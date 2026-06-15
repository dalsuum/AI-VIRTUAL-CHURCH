// Thin API client. Uses Sanctum SPA session-cookie auth: HttpOnly session cookie
// set by the server + XSRF-TOKEN header for CSRF protection. No bearer tokens are
// stored or sent from the browser. Swap BASE_URL via Vite env (VITE_API_URL).

const BASE_URL = import.meta.env.VITE_API_URL || "http://localhost:8000/api";
// Sanctum csrf-cookie lives at the app root, not under /api.
const APP_URL = BASE_URL.replace(/\/api$/, "");

// Non-sensitive flag: a session has been established this browser tab. Lets
// ensureSession() skip the guest-provision call for returning visitors without
// storing any credential in JS memory.
let sessionEstablished = !!sessionStorage.getItem("session_established");

function markSession() {
  sessionEstablished = true;
  sessionStorage.setItem("session_established", "1");
}
function dropSession() {
  sessionEstablished = false;
  sessionStorage.removeItem("session_established");
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

// Read the XSRF-TOKEN cookie that Sanctum sets after /sanctum/csrf-cookie.
function getCsrfToken() {
  const match = document.cookie
    .split(";")
    .find((c) => c.trim().startsWith("XSRF-TOKEN="));
  return match ? decodeURIComponent(match.split("=").slice(1).join("=")) : null;
}

// Fetch the CSRF cookie once per tab; concurrent callers share one in-flight request.
let csrfInflight = null;
async function ensureCsrf() {
  if (getCsrfToken()) return;
  if (!csrfInflight) {
    csrfInflight = fetch(`${APP_URL}/sanctum/csrf-cookie`, {
      credentials: "include",
    }).finally(() => {
      csrfInflight = null;
    });
  }
  return csrfInflight;
}

// Force a fresh CSRF cookie from the server, discarding any cached token.
// Call this whenever the server session has been cleared (e.g. after a 401
// retry) so the next mutating request carries a token the server will accept.
async function refreshCsrf() {
  csrfInflight = null;
  await fetch(`${APP_URL}/sanctum/csrf-cookie`, { credentials: "include" });
}

async function request(path, { method = "GET", body } = {}) {
  const mutating = method !== "GET" && method !== "HEAD";
  if (mutating) await ensureCsrf();

  const res = await fetch(`${BASE_URL}${path}`, {
    method,
    credentials: "include",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
      ...(mutating ? { "X-XSRF-TOKEN": getCsrfToken() } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
  });

  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw Object.assign(new Error(data.message || "Request failed"), { status: res.status, data });
  return data;
}

// Lazily provision an anonymous worshipper the first time we need auth, so the
// walk-up intake flow never hits a login wall. An existing session short-circuits
// this. Concurrent callers share one in-flight request.
let guestInflight = null;
async function ensureSession(opts = {}) {
  if (sessionEstablished) return;
  // Accept either a bare music_source string (legacy callers) or an options object
  // carrying the worshipper's optional name/email.
  const body = typeof opts === "string" ? { music_source: opts } : opts;
  if (!guestInflight) {
    guestInflight = request("/guest", { method: "POST", body })
      .then(async ({ user }) => {
        markSession();
        if (user?.name) rememberName(user.name);
        // session()->regenerate() rotates the CSRF _token; re-fetch the csrf
        // cookie so document.cookie reflects the post-regeneration token before
        // the caller makes any further mutating requests.
        await fetch(`${APP_URL}/sanctum/csrf-cookie`, { credentials: "include" });
      })
      .finally(() => {
        guestInflight = null;
      });
  }
  return guestInflight;
}

// Admin CSV export — fetch with session cookie and hand back a Blob for the
// caller to save.
async function adminExport(type) {
  await ensureCsrf();
  const res = await fetch(`${BASE_URL}/admin/export/${type}`, {
    credentials: "include",
    headers: { Accept: "text/csv" },
  });
  if (!res.ok) throw Object.assign(new Error("Export failed"), { status: res.status });
  return res.blob();
}

export const api = {
  // hasToken kept for component compatibility; now checks the session flag.
  hasToken: () => sessionEstablished,
  ensureSession,
  rememberedName,

  // End the current worshipper's session — drop the session flag and the remembered
  // name. On a shared/walk-up device this lets the next visitor start fresh:
  // the intake form shows the name field again, and ensureSession will provision a
  // brand-new guest (carrying their new name) on the next service.
  clearSession: () => { dropSession(); rememberName(null); },

  refreshCsrf,

  // Auth flows establish a server-side session (HttpOnly cookie set in response).
  // The frontend stores only the display name for greeting purposes.
  register: (payload) =>
    request("/register", { method: "POST", body: payload }).then((res) => {
      markSession();
      if (res?.user?.name) rememberName(res.user.name);
      return res;
    }),
  login: (payload) =>
    request("/login", { method: "POST", body: payload }).then((res) => {
      markSession();
      if (res?.user?.name) rememberName(res.user.name);
      return res;
    }),
  logout: () =>
    request("/logout", { method: "POST" }).finally(() => {
      dropSession();
      rememberName(null);
    }),
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

  // Email-link resume: server establishes the session via the URL token and sets
  // the HttpOnly cookie; frontend marks session established and reads metadata.
  resumeSession: (sessionToken) =>
    fetch(`${BASE_URL}/service/${sessionToken}/resume`, {
      credentials: "include",
      headers: { Accept: "application/json" },
    }).then(async (r) => {
      const data = await r.json();
      if (!r.ok) throw new Error(data.message || "Resume failed");
      markSession();
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
  adminVoiceTrainingStatus: () => request("/admin/voice-training/status"),
  adminVoiceTrainingStart: (payload = {}) =>
    request("/admin/voice-training/start", { method: "POST", body: payload }),
  adminExport,
  adminGrammarReview: (params = {}) => {
    const qs = new URLSearchParams();
    if (params.lang)   qs.set('lang',   params.lang);
    if (params.type)   qs.set('type',   params.type);
    if (params.status) qs.set('status', params.status);
    if (params.page)   qs.set('page',   String(params.page));
    const q = qs.toString();
    return request(`/admin/grammar-review${q ? `?${q}` : ''}`);
  },
  adminGrammarReviewSave: (payload) =>
    request('/admin/grammar-review', { method: 'POST', body: payload }),

  // Ads management
  adminAds: () => request('/admin/ads'),
  adminAd: (id) => request(`/admin/ads/${id}`),
  adminCreateAd: (payload) => request('/admin/ads', { method: 'POST', body: payload }),
  adminUpdateAd: (id, payload) => request(`/admin/ads/${id}`, { method: 'PATCH', body: payload }),
  adminDeleteAd: (id) => request(`/admin/ads/${id}`, { method: 'DELETE' }),
  adminCreateSlide: (adId, payload) => request(`/admin/ads/${adId}/slides`, { method: 'POST', body: payload }),
  adminUpdateSlide: (adId, slideId, payload) => request(`/admin/ads/${adId}/slides/${slideId}`, { method: 'PATCH', body: payload }),
  adminDeleteSlide: (adId, slideId) => request(`/admin/ads/${adId}/slides/${slideId}`, { method: 'DELETE' }),
  adminReorderSlides: (adId, order) => request(`/admin/ads/${adId}/reorder`, { method: 'POST', body: { order } }),
  adminAdsAnalytics: () => request('/admin/ads-analytics'),
  // Image upload uses FormData — can't use the JSON helper
  adminUploadSlideImage: async (adId, slideId, blob, filename) => {
    await ensureCsrf();
    const fd = new FormData();
    fd.append('image', blob, filename);
    const res = await fetch(`${BASE_URL}/admin/ads/${adId}/slides/${slideId}/image`, {
      method: 'POST',
      credentials: 'include',
      headers: { 'X-XSRF-TOKEN': getCsrfToken(), Accept: 'application/json' },
      body: fd,
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw Object.assign(new Error(data.message || 'Upload failed'), { status: res.status, data });
    return data;
  },
  // Public ad fetching + tracking (no auth needed)
  fetchActiveAds: (language, mood) => {
    const p = new URLSearchParams();
    if (language) p.set('language', language);
    if (mood) p.set('mood', mood);
    const q = p.toString();
    return request(`/ads/active${q ? `?${q}` : ''}`);
  },
  trackAdImpression: (payload) => request('/ads/track', { method: 'POST', body: payload }),

  // Voice Studio — available to all authenticated users (each user's data is isolated).
  voiceStatus:   () => request("/voice-studio/status"),
  voiceScript:   (lang) => request(`/voice-studio/script/${lang}`),
  voiceProgress: (lang) => request(`/voice-studio/progress/${lang}`),
  voiceDelete:   (lang, id) => request(`/voice-studio/recording/${lang}/${id}`, { method: "DELETE" }),

  // Multipart upload — cannot use the JSON request() helper.
  voiceStore: async (formData) => {
    await ensureCsrf();
    const res = await fetch(`${BASE_URL}/voice-studio/recording`, {
      method: "POST",
      credentials: "include",
      headers: {
        Accept: "application/json",
        "X-XSRF-TOKEN": getCsrfToken(),
        // No Content-Type — browser sets multipart boundary automatically.
      },
      body: formData,
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw Object.assign(new Error(data.message || "Upload failed"), { status: res.status, data });
    return data;
  },

  voiceTranscribe: async (formData) => {
    await ensureCsrf();
    const res = await fetch(`${BASE_URL}/voice-studio/transcribe`, {
      method: "POST",
      credentials: "include",
      headers: {
        Accept: "application/json",
        "X-XSRF-TOKEN": getCsrfToken(),
      },
      body: formData,
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw Object.assign(new Error(data.error || data.message || "Transcription failed"), { status: res.status, data });
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

  // Blob download — returns Blob.
  voiceExport: async (lang) => {
    const res = await fetch(`${BASE_URL}/voice-studio/export/${lang}`, {
      credentials: "include",
      headers: { Accept: "application/octet-stream" },
    });
    if (!res.ok) throw Object.assign(new Error("Export failed"), { status: res.status });
    return res.blob();
  },
};
