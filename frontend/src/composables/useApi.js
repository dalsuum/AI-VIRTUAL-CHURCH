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
// ── Guest device identity ────────────────────────────────────────────────────
// Two stable, non-sensitive signals so the server can enforce the "one free use
// per service" guest quota across cookie clears (see GuestUsageService):
//   • a coarse browser fingerprint (UA + language + screen + timezone), sent as a
//     header — survives clearing site data;
//   • a long-lived first-party `guest_id` cookie (a random UUID), readable by the
//     server. Neither identifies the person; both are hashed server-side.
function guestFingerprint() {
  try {
    const parts = [
      navigator.userAgent,
      navigator.language,
      `${screen.width}x${screen.height}x${screen.colorDepth}`,
      Intl.DateTimeFormat().resolvedOptions().timeZone || "",
      navigator.hardwareConcurrency || "",
    ].join("|");
    // Small, fast non-crypto hash (djb2) → hex. The server salts+SHA-256s it anyway.
    let h = 5381;
    for (let i = 0; i < parts.length; i++) h = ((h << 5) + h + parts.charCodeAt(i)) >>> 0;
    return h.toString(16);
  } catch {
    return "unknown";
  }
}

function ensureGuestCookie() {
  if (/(^|;\s*)guest_id=/.test(document.cookie)) return;
  const uuid =
    (crypto.randomUUID && crypto.randomUUID()) ||
    `${Date.now()}-${Math.random().toString(16).slice(2)}`;
  // 1-year first-party cookie; SameSite=Lax so it rides along with same-site XHR.
  document.cookie = `guest_id=${uuid}; path=/; max-age=${60 * 60 * 24 * 365}; SameSite=Lax`;
}
ensureGuestCookie();

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

async function request(path, { method = "GET", body } = {}, _retried = false) {
  const mutating = method !== "GET" && method !== "HEAD";
  if (mutating) await ensureCsrf();

  const res = await fetch(`${BASE_URL}${path}`, {
    method,
    credentials: "include",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
      // Guest-quota fingerprint (ignored by the server for registered users).
      "X-Guest-Fingerprint": guestFingerprint(),
      ...(mutating ? { "X-XSRF-TOKEN": getCsrfToken() } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
  });

  // A 419 means the XSRF-TOKEN cookie we sent no longer matches the server
  // session (e.g. the session rotated while a tab was left open, leaving a
  // stale token behind). ensureCsrf() won't refresh it because a cookie still
  // exists, so force a fresh token and retry the mutating request once.
  if (res.status === 419 && mutating && !_retried) {
    await refreshCsrf();
    return request(path, { method, body }, true);
  }

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

// Generic authenticated download — returns a Blob for the caller to save.
async function adminDownload(path, accept) {
  await ensureCsrf();
  const res = await fetch(`${BASE_URL}${path}`, {
    credentials: "include",
    headers: { Accept: accept },
  });
  if (!res.ok) throw Object.assign(new Error("Download failed"), { status: res.status });
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
  // Registration no longer auto-logs-in: it creates a pending account and emails an
  // activation link. No session is established here — the user signs in after activating.
  register: (payload) =>
    request("/register", { method: "POST", body: payload }),
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
  // Public auth-state probe — returns { authenticated, user } with HTTP 200 even
  // when logged out (no console 401), unlike /me which sits behind auth:sanctum.
  session: () => request("/auth/session"),

  // ── AI Bible Study (worshipper) ──────────────────────────────────────────
  studyConfig: () => request("/v1/study/config"),
  studyCreateSession: (payload) =>
    request("/v1/study/sessions", { method: "POST", body: payload }),
  studyShow: (id) => request(`/v1/study/sessions/${id}`),
  studyPostMessage: (id, content) =>
    request(`/v1/study/sessions/${id}/messages`, { method: "POST", body: { content } }),
  studyListEvents: (id, afterSeq = 0) =>
    request(`/v1/study/sessions/${id}/events?after_seq=${afterSeq}`),
  studyEnd: (id) => request(`/v1/study/sessions/${id}/end`, { method: "POST" }),
  studyEmail: (id, email) =>
    request(`/v1/study/sessions/${id}/email`, { method: "POST", body: email ? { email } : {} }),
  // Narrate a discussion reply (TTS). Returns { url }; synthesized once, then cached.
  studyNarrate: (lang, text) =>
    request("/v1/study/narrate", { method: "POST", body: { lang, text } }),

  // ── AI Worship Radio (worshipper) ────────────────────────────────────────
  musicMoods: () => request("/music/moods"),
  musicRecommend: (payload) =>
    request("/music/recommend", { method: "POST", body: payload }),

  // ── AI Worship Radio (admin / Music tab) ─────────────────────────────────
  worshipTracks: (params = "") => request(`/admin/worship-tracks${params}`),
  worshipYoutubeSearch: (q) =>
    request(`/admin/worship-tracks/youtube-search?q=${encodeURIComponent(q)}`),
  worshipTrackCreate: (payload) =>
    request("/admin/worship-tracks", { method: "POST", body: payload }),
  worshipTrackUpdate: (id, payload) =>
    request(`/admin/worship-tracks/${id}`, { method: "PATCH", body: payload }),
  worshipTrackDelete: (id) =>
    request(`/admin/worship-tracks/${id}`, { method: "DELETE" }),
  worshipTracksExport: (params = "") =>
    request(`/admin/worship-tracks/export${params}`),
  worshipTracksImport: (payload) =>
    request("/admin/worship-tracks/import", { method: "POST", body: payload }),
  musicSettings: () => request("/admin/music-settings"),
  musicSettingsSave: (payload) =>
    request("/admin/music-settings", { method: "PATCH", body: payload }),

  // ── AI Bible Study (admin / AI Core console) ─────────────────────────────
  studyAdminPersonas: () => request("/v1/admin/study/personas"),
  studyAdminCreatePersona: (payload) =>
    request("/v1/admin/study/personas", { method: "POST", body: payload }),
  studyAdminUpdatePersona: (id, payload) =>
    request(`/v1/admin/study/personas/${id}`, { method: "PATCH", body: payload }),
  studyAdminDeletePersona: (id) =>
    request(`/v1/admin/study/personas/${id}`, { method: "DELETE" }),
  studyAdminPrompts: () => request("/v1/admin/study/prompts"),
  studyAdminUpdatePrompt: (id, payload) =>
    request(`/v1/admin/study/prompts/${id}`, { method: "PATCH", body: payload }),
  studyAdminProviders: () => request("/v1/admin/study/providers"),
  studyAdminCreateProvider: (payload) =>
    request("/v1/admin/study/providers", { method: "POST", body: payload }),
  studyAdminUpdateProvider: (id, payload) =>
    request(`/v1/admin/study/providers/${id}`, { method: "PATCH", body: payload }),
  studyAdminDeleteProvider: (id) =>
    request(`/v1/admin/study/providers/${id}`, { method: "DELETE" }),
  studyAdminManifest: () => request("/v1/admin/study/manifest"),
  studyAdminUpdateManifest: (payload) =>
    request("/v1/admin/study/manifest", { method: "PATCH", body: payload }),
  studyAdminTiers: () => request("/v1/admin/study/tiers"),
  studyAdminUpdateTiers: (payload) =>
    request("/v1/admin/study/tiers", { method: "PATCH", body: payload }),
  studyAdminSessions: () => request("/v1/admin/study/sessions"),
  studyAdminUsage: () => request("/v1/admin/study/usage"),
  studyAdminAudit: () => request("/v1/admin/study/audit"),

  // Account self-service
  updateName: (name) =>
    request("/me/name", { method: "PATCH", body: { name } }),
  changePassword: (current_password, new_password) =>
    request("/me/change-password", { method: "POST", body: { current_password, new_password } }),
  forgotPassword: (email) =>
    request("/forgot-password", { method: "POST", body: { email } }),
  resetPassword: (token, new_password) =>
    request("/reset-password", { method: "POST", body: { token, new_password } }),

  // Subscription + token wallet (account page).
  subscriptionStatus: () => request("/subscription"),
  subscriptionCheckout: () => request("/subscription/checkout", { method: "POST" }),
  subscriptionCancel: () => request("/subscription/cancel", { method: "POST" }),
  tokenBalance: () => request("/tokens/balance"),
  tokenHistory: () => request("/tokens/history"),

  // ── Unified Conversation & Spiritual History ─────────────────────────────
  history: (params = "") => request(`/history${params}`),
  historyShow: (id) => request(`/history/${id}`),
  historySearch: (payload) =>
    request("/history/search", { method: "POST", body: payload }),
  historyUpdate: (id, payload) =>
    request(`/history/${id}`, { method: "PATCH", body: payload }),
  historyDelete: (id) => request(`/history/${id}`, { method: "DELETE" }),
  historyRestore: (id) => request(`/history/${id}/restore`, { method: "POST" }),
  historyBulk: (action, ids) =>
    request("/history/bulk", { method: "POST", body: { action, ids } }),
  historyShare: (id, payload = {}) =>
    request(`/history/${id}/share`, { method: "POST", body: payload }),
  historyRevokeShare: (id) => request(`/history/${id}/share`, { method: "DELETE" }),
  historyStats: () => request("/history/stats"),
  historyTimeline: (year) => request(`/history/timeline${year ? `?year=${year}` : ""}`),
  historyExportUrl: (id, format) => `${BASE_URL}/history/${id}/export?format=${format}`,
  historyExportAllUrl: (format) => `${BASE_URL}/history/export-all?format=${format}`,
  sharedView: (token, password) =>
    request(`/shared/${token}${password ? `?password=${encodeURIComponent(password)}` : ""}`),

  // ── Spiritual Journal ────────────────────────────────────────────────────
  journalGenerate: (sessionId) =>
    request(`/history/${sessionId}/journal`, { method: "POST" }),
  journalList: (params = "") => request(`/journal${params}`),
  journalShow: (id) => request(`/journal/${id}`),
  journalDelete: (id) => request(`/journal/${id}`, { method: "DELETE" }),

  // ── AI Pastor Chat ───────────────────────────────────────────────────────
  pastorStart: (payload) =>
    request("/pastor/sessions", { method: "POST", body: payload }),
  pastorMessages: (id) => request(`/pastor/sessions/${id}/messages`),
  pastorPostMessage: (id, message) =>
    request(`/pastor/sessions/${id}/messages`, { method: "POST", body: { message } }),

  // Spiritual-profile preferences (account page).
  updateProfile: (payload) =>
    request("/me/profile", { method: "PATCH", body: payload }),

  // Public app configuration (intake/preparing options). Optional context narrows
  // countdown cards by service mood/language once a session poll is available.
  getConfig: (context = {}) => {
    const params = new URLSearchParams();
    if (context.mood) params.set("mood", context.mood);
    if (context.language) params.set("language", context.language);
    const qs = params.toString();
    return request(`/config${qs ? `?${qs}` : ""}`);
  },

  // Active special Sunday (if any) for the highlight card, localized to the
  // service language. Returns { active: false } outside any observance window.
  getCurrentSpecialSunday: (language = "en") =>
    request(`/special-sunday/current?language=${encodeURIComponent(language)}`),

  // Worship song library (public read for the front song panel).
  getSongs: (params = {}) => {
    const qs = new URLSearchParams();
    if (params.language) qs.set("language", params.language);
    if (params.search) qs.set("search", params.search);
    const q = qs.toString();
    return request(`/songs${q ? `?${q}` : ""}`);
  },

  // Zolai ↔ Burmese ↔ English vocabulary reference (public read for #vocabulary).
  getVocabulary: () => request("/vocabulary"),
  // Learner view: AI-generated entry for one concept in one language. Body carries
  // {status: 'ready'|'generating', entry}; poll while 'generating'.
  learnVocab: (id, lang) => request(`/vocabulary/${id}/learn?lang=${encodeURIComponent(lang)}`),
  // AI "Explain": cached teaching explanation; body {status, explanation?}. Poll while generating.
  explainVocab: (id, lang) => request(`/vocabulary/${id}/explain?lang=${encodeURIComponent(lang)}`, { method: "POST" }),
  // Per-user favorites + viewed history (auth required).
  favoriteVocab: (id) => request(`/vocabulary/${id}/favorite`, { method: "POST" }),
  unfavoriteVocab: (id) => request(`/vocabulary/${id}/favorite`, { method: "DELETE" }),
  myVocabulary: (kind) => request(`/me/vocabulary?kind=${encodeURIComponent(kind)}`),
  // Admin CRUD (vocabulary.manage).
  adminCreateVocabulary: (payload) =>
    request("/admin/vocabulary", { method: "POST", body: payload }),
  adminUpdateVocabulary: (id, payload) =>
    request(`/admin/vocabulary/${id}`, { method: "PATCH", body: payload }),
  adminDeleteVocabulary: (id) =>
    request(`/admin/vocabulary/${id}`, { method: "DELETE" }),

  // Online Bible reader (public, read-only). lang = en | my | td.
  bibleConfig: () => request("/bible/config"),
  bibleBooks: (lang = "en") =>
    request(`/bible/books?lang=${encodeURIComponent(lang)}`),
  bibleChapter: (lang, book, chapter) =>
    request(`/bible/chapter?lang=${encodeURIComponent(lang)}&book=${book}&chapter=${chapter}`),
  // Chapter narration (TTS). Returns { url }. Synthesized once, then cached.
  bibleAudio: (lang, book, chapter) =>
    request(`/bible/audio?lang=${encodeURIComponent(lang)}&book=${book}&chapter=${chapter}`),
  // AI background-music loop for a chapter + reader-local hour (0-23). Returns
  // { url, theme, tod, generating }. url is null while it's still generating.
  bibleBgMusic: (lang, book, chapter, hour) =>
    request(`/bible/bg-music?lang=${encodeURIComponent(lang)}&book=${book}&chapter=${chapter}&hour=${hour}`),
  // Static mode: which uploaded track best fits this chapter's mood + the
  // reader's time of day (falls back to the fixed track server-side).
  bibleBgMusicMatch: (lang, book, chapter, hour) =>
    request(`/bible/bg-music/match?lang=${encodeURIComponent(lang)}&book=${book}&chapter=${chapter}&hour=${hour}`),

  updateGuestEmail: (email) =>
    request("/me/email", { method: "PATCH", body: { email } }),

  updateMusicSource: (music_source) =>
    request("/me/music-source", { method: "PATCH", body: { music_source } }),

  // Email-link resume: server exchanges the URL token for a service-scoped session
  // cookie; it does not sign the browser into the owner's account.
  resumeSession: (sessionToken) =>
    fetch(`${BASE_URL}/service/${sessionToken}/resume`, {
      credentials: "include",
      headers: { Accept: "application/json" },
    }).then(async (r) => {
      const data = await r.json();
      if (!r.ok) throw new Error(data.message || "Resume failed");
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
  adminFreezeStatus: () => request("/admin/freeze/status"),
  adminKnowledgeHealth: () => request("/admin/knowledge/health"),
  // Multipart upload — cannot use the JSON request() helper.
  adminKnowledgeUpload: async (formData) => {
    await ensureCsrf();
    const res = await fetch(`${BASE_URL}/admin/knowledge/upload`, {
      method: "POST",
      credentials: "include",
      headers: { Accept: "application/json", "X-XSRF-TOKEN": getCsrfToken() },
      body: formData,
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw Object.assign(new Error(data.message || "Upload failed"), { status: res.status, data });
    return data;
  },
  adminKnowledgeJobs: () => request("/admin/knowledge/jobs"),
  adminKnowledgeCancelJob: (id) => request(`/admin/knowledge/jobs/${id}/cancel`, { method: "POST" }),
  adminKnowledgeRetryJob: (id) => request(`/admin/knowledge/jobs/${id}/retry`, { method: "POST" }),
  adminKnowledgeDeleteJob: (id) => request(`/admin/knowledge/jobs/${id}`, { method: "DELETE" }),
  adminKnowledgeInspect: (query, language, corpora) =>
    request("/admin/knowledge/inspect", {
      method: "POST",
      body: JSON.stringify({ query, language: language || "en", corpora: corpora || null }),
    }),
  adminKnowledgeLibrary: () => request("/admin/knowledge/library"),
  adminKnowledgeLibraryToggle: (corpus) => request(`/admin/knowledge/library/${corpus}/toggle`, { method: "POST" }),
  adminKnowledgeLibraryReindex: (corpus) => request(`/admin/knowledge/library/${corpus}/reindex`, { method: "POST" }),
  adminKnowledgeLibraryDestroy: (corpus) => request(`/admin/knowledge/library/${corpus}`, { method: "DELETE" }),
  adminServices: () => request("/admin/services"),
  adminServiceResumeLink: (id) =>
    request(`/admin/services/${id}/resume-link`, { method: "POST" }),
  adminRetryService: (id) => request(`/admin/services/${id}/retry`, { method: "POST" }),
  adminDeleteService: (id) => request(`/admin/services/${id}`, { method: "DELETE" }),
  adminBulkDeleteServices: (service_ids) =>
    request("/admin/services/bulk-delete", { method: "POST", body: { service_ids } }),
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
  adminBulkDeleteUsers: (user_ids) =>
    request("/admin/users/bulk-delete", { method: "POST", body: { user_ids } }),
  adminCreateUser: (payload) =>
    request("/admin/users", { method: "POST", body: payload }),
  adminAssignRole: (id, role) =>
    request(`/admin/users/${id}/role`, { method: "PATCH", body: { role } }),
  adminGrantTokens: (id, amount) =>
    request(`/admin/users/${id}/tokens`, { method: "POST", body: { amount } }),
  adminForcePasswordReset: (id) =>
    request(`/admin/users/${id}/force-reset`, { method: "POST" }),
  adminDonors: () => request("/admin/donors"),
  adminPrayerRequests: () => request("/admin/prayer-requests"),
  adminSettings: () => request("/admin/settings"),
  adminUpdateSettings: (payload) =>
    request("/admin/settings", { method: "PATCH", body: payload }),
  // Bible AI background-music matrix: status (cached/total) + queue generation.
  adminBibleBgMusicStatus: () => request("/admin/bible/bg-music/status"),
  adminBibleBgMusicPregenerate: () =>
    request("/admin/bible/bg-music/pregenerate", { method: "POST" }),
  // Background-music library: list tracks, upload, delete, and pick the active one.
  adminBibleBgMusicLibrary: () => request("/admin/bible/bg-music/library"),
  // Upload a local mp3/ogg into the library. Multipart — can't use the JSON helper.
  adminBibleBgMusicUpload: async (file, theme, tod) => {
    await ensureCsrf();
    const fd = new FormData();
    fd.append("file", file, file.name);
    if (theme) fd.append("theme", theme);
    if (tod) fd.append("tod", tod);
    const res = await fetch(`${BASE_URL}/admin/bible/bg-music/upload`, {
      method: "POST",
      credentials: "include",
      headers: { Accept: "application/json", "X-XSRF-TOKEN": getCsrfToken() },
      body: fd,
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw Object.assign(new Error(data.message || "Upload failed"), { status: res.status, data });
    return data;
  },
  adminBibleBgMusicDelete: (id) =>
    request(`/admin/bible/bg-music/library/${encodeURIComponent(id)}`, { method: "DELETE" }),
  adminBibleBgMusicTags: (id, theme, tod) =>
    request(`/admin/bible/bg-music/library/${encodeURIComponent(id)}`, { method: "PATCH", body: { theme, tod } }),
  adminBibleBgMusicSelect: (src, key) =>
    request("/admin/bible/bg-music/select", { method: "POST", body: { src, key } }),

  // Content filter — categorized YouTube blocklist (CRUD + import/export).
  cfList: () => request("/admin/content-filter"),
  cfReplace: (categories) =>
    request("/admin/content-filter", { method: "PUT", body: { categories } }),
  cfAddCategory: (payload) =>
    request("/admin/content-filter/categories", { method: "POST", body: payload }),
  cfUpdateCategory: (id, payload) =>
    request(`/admin/content-filter/categories/${encodeURIComponent(id)}`, { method: "PATCH", body: payload }),
  cfDeleteCategory: (id) =>
    request(`/admin/content-filter/categories/${encodeURIComponent(id)}`, { method: "DELETE" }),
  cfAddKeyword: (id, keyword) =>
    request(`/admin/content-filter/categories/${encodeURIComponent(id)}/keywords`, { method: "POST", body: { keyword } }),
  cfUpdateKeyword: (id, from, to) =>
    request(`/admin/content-filter/categories/${encodeURIComponent(id)}/keywords`, { method: "PATCH", body: { from, to } }),
  cfDeleteKeyword: (id, keyword) =>
    request(`/admin/content-filter/categories/${encodeURIComponent(id)}/keywords`, { method: "DELETE", body: { keyword } }),
  cfExportJson: () => adminDownload("/admin/content-filter/export.json", "application/json"),
  cfExportCsv: () => adminDownload("/admin/content-filter/export.csv", "text/csv"),
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
  // Special Sundays — monitor + catalog management (special_sundays.view/manage).
  adminSpecialSundays: () => request("/admin/special-sundays"),
  adminCreateSpecialSunday: (payload) =>
    request("/admin/special-sundays", { method: "POST", body: payload }),
  adminUpdateSpecialSunday: (id, payload) =>
    request(`/admin/special-sundays/${id}`, { method: "PATCH", body: payload }),
  adminDeleteSpecialSunday: (id) =>
    request(`/admin/special-sundays/${id}`, { method: "DELETE" }),
  adminPreviewSpecialSunday: (id, language = "en", mood = "") => {
    const qs = new URLSearchParams({ language });
    if (mood) qs.set("mood", mood);
    return request(`/admin/special-sundays/${id}/preview?${qs.toString()}`);
  },
  // Curated sermon/song libraries attached to a special Sunday (manual mode).
  adminCreateSpecialSermon: (dayId, payload) =>
    request(`/admin/special-sundays/${dayId}/sermons`, { method: "POST", body: payload }),
  adminUpdateSpecialSermon: (id, payload) =>
    request(`/admin/special-sermons/${id}`, { method: "PATCH", body: payload }),
  adminDeleteSpecialSermon: (id) =>
    request(`/admin/special-sermons/${id}`, { method: "DELETE" }),
  adminCreateSpecialSong: (dayId, payload) =>
    request(`/admin/special-sundays/${dayId}/songs`, { method: "POST", body: payload }),
  adminUpdateSpecialSong: (id, payload) =>
    request(`/admin/special-songs/${id}`, { method: "PATCH", body: payload }),
  adminDeleteSpecialSong: (id) =>
    request(`/admin/special-songs/${id}`, { method: "DELETE" }),
  // Song library CRUD (admin Lyrics tab; requires lyrics.manage).
  adminGetSong: (id) => request(`/admin/songs/${id}`),
  adminCreateSong: (payload) =>
    request("/admin/songs", { method: "POST", body: payload }),
  adminUpdateSong: (id, payload) =>
    request(`/admin/songs/${id}`, { method: "PATCH", body: payload }),
  adminDeleteSong: (id) =>
    request(`/admin/songs/${id}`, { method: "DELETE" }),
  // Bulk song import (CSV/JSON) — multipart, so it can't use the JSON helper.
  adminImportSongs: async (file) => {
    await ensureCsrf();
    const fd = new FormData();
    fd.append("file", file, file.name);
    const res = await fetch(`${BASE_URL}/admin/songs/import`, {
      method: "POST",
      credentials: "include",
      headers: { Accept: "application/json", "X-XSRF-TOKEN": getCsrfToken() },
      body: fd,
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw Object.assign(new Error(data.message || "Import failed"), { status: res.status, data });
    return data;
  },
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

  // ---- Father's Day (Special Day) MV — removable feature -------------------
  // Public page config (enabled flag, effects, copy).
  fdPublicConfig: () => request("/fathers-day/config"),
  // Public render: upload photo(s) + chosen effect. Multipart, so raw fetch.
  fdRender: async (files, effect, songId, opts = {}) => {
    await ensureCsrf();      // CSRF cookie/token for the stateful SPA POST
    const fd = new FormData();
    files.forEach((f) => fd.append("photos[]", f, f.name));
    if (effect) fd.append("effect", effect);
    if (songId) fd.append("song_id", songId);
    if (opts.full) fd.append("full", "1");
    if (opts.clipStart != null) fd.append("clip_start", String(opts.clipStart));
    if (opts.clipEnd != null) fd.append("clip_end", String(opts.clipEnd));
    const res = await fetch(`${BASE_URL}/fathers-day/render`, {
      method: "POST",
      credentials: "include",
      headers: { Accept: "application/json", "X-XSRF-TOKEN": getCsrfToken() },
      body: fd,
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw Object.assign(new Error(data.message || "Render failed"), { status: res.status, data });
    return data;
  },
  fdJobStatus: (id) => request(`/fathers-day/job/${id}`),
  fdDownloadUrl: (id) => `${BASE_URL}/fathers-day/download/${id}`,
  // Public song stream for the visitor's clip picker.
  fdPublicSongBlob: async (songId) => {
    const res = await fetch(`${BASE_URL}/fathers-day/song/${songId}/audio`, {
      credentials: "include",
      headers: { Accept: "audio/*" },
    });
    if (!res.ok) throw Object.assign(new Error("Could not load song"), { status: res.status });
    return res.blob();
  },

  // --- Live Sticker maker (SELF-CONTAINED & REMOVABLE) ------------------
  stickerConfig: () => request("/stickers/config"),
  // Step 1: upload one photo, get a token + auto face-crop box. Multipart.
  stickerDetect: async (file) => {
    await ensureCsrf();
    const fd = new FormData();
    fd.append("photo", file, file.name);
    const res = await fetch(`${BASE_URL}/stickers/detect`, {
      method: "POST",
      credentials: "include",
      headers: { Accept: "application/json", "X-XSRF-TOKEN": getCsrfToken() },
      body: fd,
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw Object.assign(new Error(data.message || "Could not read image"), { status: res.status, data });
    return data;
  },
  // Step 2: queue the 5-sticker composite with the adjusted crop + text.
  stickerRender: (payload) => request("/stickers/render", { method: "POST", body: payload }),
  stickerJobStatus: (id) => request(`/stickers/job/${id}`),
  // Admin enable/disable + page copy.
  stickerAdminShow: () => request("/admin/stickers"),
  stickerAdminSave: (payload) => request("/admin/stickers", { method: "POST", body: payload }),
  stickerResetUsage: () => request("/admin/stickers/reset-usage", { method: "POST" }),

  // Admin: fetch a library song as a blob for the tap-to-sync player (cookie auth).
  fdSongBlob: async (songId) => {
    const res = await fetch(`${BASE_URL}/admin/fathers-day/songs/${songId}/audio`, {
      credentials: "include",
      headers: { Accept: "audio/*" },
    });
    if (!res.ok) throw Object.assign(new Error("Could not load song"), { status: res.status });
    return res.blob();
  },

  // Admin global settings + song library.
  fdAdminShow: () => request("/admin/fathers-day"),
  fdAdminSave: (payload) => request("/admin/fathers-day", { method: "POST", body: payload }),
  fdResetUsage: () => request("/admin/fathers-day/reset-usage", { method: "POST" }),
  fdUpdateSong: (songId, payload) => request(`/admin/fathers-day/songs/${songId}`, { method: "PATCH", body: payload }),
  fdDeleteSong: (songId) => request(`/admin/fathers-day/songs/${songId}`, { method: "DELETE" }),
  fdAddSong: async (file, title) => {
    await ensureCsrf();
    const fd = new FormData();
    fd.append("song", file, file.name);
    if (title) fd.append("title", title);
    const res = await fetch(`${BASE_URL}/admin/fathers-day/songs`, {
      method: "POST",
      credentials: "include",
      headers: { Accept: "application/json", "X-XSRF-TOKEN": getCsrfToken() },
      body: fd,
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw Object.assign(new Error(data.message || "Upload failed"), { status: res.status, data });
    return data;
  },
  fdUploadBrandTag: async (file) => {
    await ensureCsrf();
    const fd = new FormData();
    fd.append("tag", file, file.name);
    const res = await fetch(`${BASE_URL}/admin/fathers-day/brand-tag`, {
      method: "POST",
      credentials: "include",
      headers: { Accept: "application/json", "X-XSRF-TOKEN": getCsrfToken() },
      body: fd,
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw Object.assign(new Error(data.message || "Upload failed"), { status: res.status, data });
    return data;
  },
  fdDeleteBrandTag: () => request("/admin/fathers-day/brand-tag", { method: "DELETE" }),
};
