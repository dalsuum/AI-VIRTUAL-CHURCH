// Thin API client. Stores the Sanctum token in memory + localStorage and exposes
// the endpoints Phase 1 needs. Swap BASE_URL via Vite env (VITE_API_URL).

const BASE_URL = import.meta.env.VITE_API_URL || "http://localhost:8000/api";

let token = localStorage.getItem("token") || null;

function setToken(t) {
  token = t;
  if (t) localStorage.setItem("token", t);
  else localStorage.removeItem("token");
}

// The worshipper's display name, remembered locally so returning visitors can be
// greeted with "Welcome back" without a round-trip.
function rememberName(name) {
  if (name) localStorage.setItem("display_name", name);
  else localStorage.removeItem("display_name");
}
function rememberedName() {
  return localStorage.getItem("display_name");
}

async function request(path, { method = "GET", body } = {}) {
  const res = await fetch(`${BASE_URL}${path}`, {
    method,
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
  });

  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw Object.assign(new Error(data.message || "Request failed"), { status: res.status, data });
  return data;
}

// Lazily provision an anonymous worshipper the first time we need auth, so the
// walk-up intake flow never hits a login wall. A registered/guest token in
// localStorage short-circuits this. Concurrent callers share one in-flight request.
let guestInflight = null;
async function ensureSession(opts = {}) {
  if (token) return;
  // Accept either a bare music_source string (legacy callers) or an options object
  // carrying the worshipper's optional name/email.
  const body = typeof opts === "string" ? { music_source: opts } : opts;
  if (!guestInflight) {
    guestInflight = request("/guest", { method: "POST", body })
      .then(({ token: t, user }) => {
        setToken(t);
        if (user?.name) rememberName(user.name);
      })
      .finally(() => { guestInflight = null; });
  }
  return guestInflight;
}

// Admin CSV export. Exports need the Bearer token, so we can't use a plain <a
// href> download — fetch the file with auth and hand back a Blob for the caller
// to save.
async function adminExport(type) {
  const res = await fetch(`${BASE_URL}/admin/export/${type}`, {
    headers: {
      Accept: "text/csv",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  });
  if (!res.ok) throw Object.assign(new Error("Export failed"), { status: res.status });
  return res.blob();
}

export const api = {
  setToken,
  hasToken: () => !!token,
  ensureSession,
  rememberedName,

  // End the current worshipper's session entirely — drop the auth token and the
  // remembered name. On a shared/walk-up device this lets the next visitor start
  // fresh: the intake form shows the name field again, and ensureSession will
  // provision a brand-new guest (carrying their new name) on the next service.
  clearSession: () => { setToken(null); rememberName(null); },

  // Register/login return { user, token }. Persist the token (and remembered name)
  // so subsequent authed calls — the admin dashboard especially — use this account
  // rather than any stale guest token left in localStorage.
  register: (payload) =>
    request("/register", { method: "POST", body: payload }).then((res) => {
      if (res?.token) setToken(res.token);
      if (res?.user?.name) rememberName(res.user.name);
      return res;
    }),
  login: (payload) =>
    request("/login", { method: "POST", body: payload }).then((res) => {
      if (res?.token) setToken(res.token);
      if (res?.user?.name) rememberName(res.user.name);
      return res;
    }),
  logout: () => request("/logout", { method: "POST" }).finally(() => { setToken(null); rememberName(null); }),
  me: () => request("/me"),

  // Account self-service
  updateName: (name) =>
    request("/me/name", { method: "PATCH", body: { name } }),
  changePassword: (current_password, new_password) =>
    request("/me/change-password", { method: "POST", body: { current_password, new_password } }),
  forgotPassword: (email) =>
    request("/forgot-password", { method: "POST", body: { email } }),
  resetPassword: (token, new_password) =>
    request("/reset-password", { method: "POST", body: { token, new_password } }),

  // Public app configuration (intake/preparing options). Optional context narrows
  // countdown cards by service mood/language once a session poll is available.
  getConfig: (context = {}) => {
    const params = new URLSearchParams();
    if (context.mood) params.set("mood", context.mood);
    if (context.language) params.set("language", context.language);
    const qs = params.toString();
    return request(`/config${qs ? `?${qs}` : ""}`);
  },

  updateGuestEmail: (email) =>
    request("/me/email", { method: "PATCH", body: { email } }),

  updateMusicSource: (music_source) =>
    request("/me/music-source", { method: "PATCH", body: { music_source } }),

  changePassword: (current_password, new_password) =>
    request("/me/change-password", { method: "POST", body: { current_password, new_password } }),

  resumeSession: (sessionToken) =>
    fetch(`${BASE_URL}/service/${sessionToken}/resume`, {
      headers: { Accept: "application/json" },
    })
      .then(async (r) => {
        const data = await r.json();
        if (!r.ok) throw new Error(data.message || "Resume failed");
        if (data.auth_token) setToken(data.auth_token);
        return data;
      }),

  getMyServices: () => request("/me/services"),

  startService: () => request("/service/start", { method: "POST" }),
  submitIntake: (sessionToken, payload) =>
    request(`/service/${sessionToken}/intake`, { method: "POST", body: payload }),
  getService: (sessionToken) => request(`/service/${sessionToken}`),

  // Offering: open a PaymentIntent. `amount` is in minor units (cents).
  createOffering: (sessionToken, { amount, allocation }) =>
    request(`/service/${sessionToken}/offering`, {
      method: "POST",
      body: { amount, allocation },
    }),

  // Testimonies: read the approved wall, or share your own (held for moderation).
  listTestimonies: () => request("/testimonies"),
  submitTestimony: (content) =>
    request("/testimonies", { method: "POST", body: { content } }),

  // Admin console (requires an is_admin account).
  adminDashboard: () => request("/admin/dashboard"),
  adminServices: () => request("/admin/services"),
  adminRetryService: (id) => request(`/admin/services/${id}/retry`, { method: "POST" }),
  adminDeleteService: (id) => request(`/admin/services/${id}`, { method: "DELETE" }),
  adminTestimonies: () => request("/admin/testimonies"),
  adminApproveTestimony: (id) =>
    request(`/admin/testimonies/${id}/approve`, { method: "PATCH" }),
  adminDeleteTestimony: (id) =>
    request(`/admin/testimonies/${id}`, { method: "DELETE" }),
  adminUsers: () => request("/admin/users"),
  adminSetAdmin: (id, is_admin) =>
    request(`/admin/users/${id}/admin`, { method: "PATCH", body: { is_admin } }),
  adminBlockUser: (id, is_blocked) =>
    request(`/admin/users/${id}/block`, { method: "PATCH", body: { is_blocked } }),
  adminDeleteUser: (id) =>
    request(`/admin/users/${id}`, { method: "DELETE" }),
  adminCreateUser: (payload) =>
    request("/admin/users", { method: "POST", body: payload }),
  adminAssignRole: (id, role) =>
    request(`/admin/users/${id}/role`, { method: "PATCH", body: { role } }),
  adminForcePasswordReset: (id) =>
    request(`/admin/users/${id}/force-reset`, { method: "POST" }),
  adminDonors: () => request("/admin/donors"),
  adminPrayerRequests: () => request("/admin/prayer-requests"),
  adminSettings: () => request("/admin/settings"),
  adminUpdateSettings: (payload) =>
    request("/admin/settings", { method: "PATCH", body: payload }),
  adminMusicTracks: (params = {}) => {
    const qs = new URLSearchParams();
    if (params.mood) qs.set("mood", params.mood);
    if (params.language) qs.set("language", params.language);
    if (params.search) qs.set("search", params.search);
    if (params.limit) qs.set("limit", String(params.limit));
    const q = qs.toString();
    return request(`/admin/music-tracks${q ? `?${q}` : ""}`);
  },
  adminCreateMusicTrack: (payload) =>
    request("/admin/music-tracks", { method: "POST", body: payload }),
  adminUpdateMusicTrack: (id, payload) =>
    request(`/admin/music-tracks/${id}`, { method: "PATCH", body: payload }),
  adminDeleteMusicTrack: (id) =>
    request(`/admin/music-tracks/${id}`, { method: "DELETE" }),
  adminGetPermissions: () => request("/admin/permissions"),
  adminUpdatePermissions: (permissions) =>
    request("/admin/permissions", { method: "PATCH", body: { permissions } }),
  adminExport,

  // Voice Studio — available to all authenticated users (each user's data is isolated).
  voiceScript:   (lang) => request(`/voice-studio/script/${lang}`),
  voiceProgress: (lang) => request(`/voice-studio/progress/${lang}`),
  voiceDelete:   (lang, id) => request(`/voice-studio/recording/${lang}/${id}`, { method: "DELETE" }),

  // Multipart upload — cannot use the JSON request() helper.
  voiceStore: async (formData) => {
    const res = await fetch(`${BASE_URL}/voice-studio/recording`, {
      method: "POST",
      headers: {
        Accept: "application/json",
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
        // No Content-Type — browser sets multipart boundary automatically.
      },
      body: formData,
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw Object.assign(new Error(data.message || "Upload failed"), { status: res.status, data });
    return data;
  },

  // System monitor — update check, package installs, service restarts.
  adminUpdateStatus: () => request("/admin/updates/status"),
  adminUpdateCheck: () => request("/admin/updates/check", { method: "POST" }),
  adminGitPull: () => request("/admin/updates/git-pull", { method: "POST" }),
  adminInstallPackage: (package_name) =>
    request("/admin/updates/install", { method: "POST", body: { package: package_name } }),
  adminRestartService: (service) =>
    request("/admin/updates/restart-service", { method: "POST", body: { service } }),

  // Voicebox TTS monitor — health, voice profiles, generation queue.
  adminVoiceboxHealth:   () => request("/admin/voicebox/health"),
  adminVoiceboxProfiles: () => request("/admin/voicebox/profiles"),
  adminVoiceboxQueue:    () => request("/admin/voicebox/queue"),

  // Blob download — needs auth header, returns Blob.
  voiceExport: async (lang) => {
    const res = await fetch(`${BASE_URL}/voice-studio/export/${lang}`, {
      headers: { ...(token ? { Authorization: `Bearer ${token}` } : {}) },
    });
    if (!res.ok) throw Object.assign(new Error("Export failed"), { status: res.status });
    return res.blob();
  },
};
