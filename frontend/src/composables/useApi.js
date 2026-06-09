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

  register: (payload) => request("/register", { method: "POST", body: payload }),
  login: (payload) => request("/login", { method: "POST", body: payload }),
  logout: () => request("/logout", { method: "POST" }).finally(() => { setToken(null); rememberName(null); }),
  me: () => request("/me"),

  updateMusicSource: (music_source) =>
    request("/me/music-source", { method: "PATCH", body: { music_source } }),

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
  adminTestimonies: () => request("/admin/testimonies"),
  adminApproveTestimony: (id) =>
    request(`/admin/testimonies/${id}/approve`, { method: "PATCH" }),
  adminDeleteTestimony: (id) =>
    request(`/admin/testimonies/${id}`, { method: "DELETE" }),
  adminUsers: () => request("/admin/users"),
  adminSetAdmin: (id, is_admin) =>
    request(`/admin/users/${id}/admin`, { method: "PATCH", body: { is_admin } }),
  adminDonors: () => request("/admin/donors"),
  adminExport,
};
