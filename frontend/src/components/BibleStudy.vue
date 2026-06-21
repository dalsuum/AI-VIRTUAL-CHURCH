<script setup>
// AI Bible Study — live multi-pastor discussion. Three phases: setup → discussion
// (streaming agent bubbles + verse cards) → summary. Streaming is delivered by
// useStudyStream over SSE; the seq-based composable handles reconnect/replay so a
// dropped connection never loses or duplicates content.
import { onMounted, onBeforeUnmount, reactive, ref, computed, watch } from "vue";
import { api } from "../composables/useApi";
import { useStudyStream } from "../composables/useStudyStream";
import ThemeToggle from "./ThemeToggle.vue";

const year = new Date().getFullYear();

// Bible translations offered per language. The first entry is that language's
// DEFAULT (its own version where available); English versions are offered as a
// fallback so any reference always resolves. Codes match the backend corpus.
const TRANSLATIONS = {
  en:  [["kjv", "KJV"], ["en", "English (BSB)"]],
  my:  [["my", "Burmese (Judson 1835)"], ["kjv", "KJV (English)"]],
  td:  [["td", "Tedim (Lai Siangtho 1932)"], ["kjv", "KJV (English)"]],
  cnh: [["cnh", "Hakha Chin"], ["kjv", "KJV (English)"]],
  cfm: [["cfm", "Falam Chin"], ["kjv", "KJV (English)"]],
  lus: [["lus", "Mizo"], ["kjv", "KJV (English)"]],
  hlt: [["hlt", "Matu Chin"], ["kjv", "KJV (English)"]],
};

const phase = ref("setup");
const loading = ref(false);
const error = ref("");

const config = ref(null);
const form = reactive({
  language: "en",
  translation: "kjv",
  style: "",
  agent_count: 4,
  question: "",
});

const STYLES = ["Gentle", "Teaching", "Encouraging", "Deep Theology", "Youth", "Family", "Hope"];

const LANG_NAMES = {
  en: "English", my: "Burmese (ဗမာ)", td: "Tedim", cnh: "Hakha",
  cfm: "Falam", lus: "Mizo", hlt: "Matu",
};

const session = ref(null);
const bubbles = ref([]);            // { turn, persona_id, name, role, text, refs:[] }
const notice = ref("");
const inputOpen = ref(false);
const followUp = ref("");
const summary = ref(null);

const stream = useStudyStream();
const { connected, reconnecting } = stream;

const agentMin = computed(() => config.value?.min_agent_count ?? 2);
const agentMax = computed(() => config.value?.max_agent_count ?? 7);

// Translation options for the selected language; first is the default.
const translationOptions = computed(() => TRANSLATIONS[form.language] || TRANSLATIONS.en);

// When the language changes, snap the translation to that language's default
// (unless the current pick is still valid for the new language).
watch(() => form.language, () => {
  const valid = translationOptions.value.map(([code]) => code);
  if (!valid.includes(form.translation)) {
    form.translation = translationOptions.value[0][0];
  }
});

onMounted(async () => {
  try {
    await api.ensureSession();
    config.value = await api.studyConfig();
    form.agent_count = config.value.default_agent_count ?? 2;
  } catch (e) {
    error.value = "Bible Study is not available right now.";
  }
});

onBeforeUnmount(() => stream.close());

// User bubbles use decreasing negative turn ids so they never collide with the
// positive turn numbers the worker assigns to moderator/pastor turns.
let userTurnSeq = 0;
function pushUserBubble(text) {
  bubbles.value.push({
    turn: --userTurnSeq, persona_id: null, name: "You",
    role: "user", text, refs: [],
  });
}

function bubbleFor(env) {
  let b = bubbles.value.find((x) => x.turn === env.turn);
  if (!b) {
    b = {
      turn: env.turn,
      persona_id: env.persona_id ?? null,
      name: env.display_name || "Moderator",
      role: env.role || "pastor",
      text: "",
      refs: [],
    };
    bubbles.value.push(b);
  }
  return b;
}

function attachHandlers() {
  stream.open(session.value.id, session.value.stream_token, {
    "agent.started": (e) => bubbleFor(e),
    "token.delta": (e) => {
      const b = bubbleFor(e);
      b.text += e.text || "";
    },
    "verse.card": (e) => {
      const b = bubbleFor(e);
      if (!b.refs.some((r) => r.ref === e.ref)) b.refs.push({ ref: e.ref, translation: e.translation });
    },
    "safety.blocked": () => {
      notice.value = "A response was filtered for safety.";
    },
    "round.complete": () => {
      inputOpen.value = true;
    },
    "state.changed": (e) => {
      if (e.state === "summarized") loadSummary();
    },
  });
}

async function start() {
  error.value = "";
  if (form.question.trim().length < 3) {
    error.value = "Please enter a question.";
    return;
  }
  loading.value = true;
  try {
    const res = await api.studyCreateSession({
      language: form.language,
      translation: form.translation,
      style: form.style || null,
      agent_count: form.agent_count,
      question: form.question.trim(),
    });
    session.value = { id: res.session.id, stream_token: res.stream_token };
    bubbles.value = [];
    inputOpen.value = false;
    phase.value = "discussion";
    pushUserBubble(form.question.trim());   // show the worshipper's question first
    attachHandlers();
  } catch (e) {
    error.value = e?.message || "Could not start the discussion.";
  } finally {
    loading.value = false;
  }
}

async function send() {
  const text = followUp.value.trim();
  if (text.length < 2) return;
  inputOpen.value = false;
  followUp.value = "";
  pushUserBubble(text);                      // echo the follow-up into the thread
  try {
    await api.studyPostMessage(session.value.id, text);
  } catch (e) {
    error.value = e?.message || "Could not send your message.";
    inputOpen.value = true;
  }
}

async function end() {
  try {
    await api.studyEnd(session.value.id);
    notice.value = "Preparing your study summary…";
    // Fallback poll in case the summarized event is missed.
    setTimeout(loadSummary, 4000);
  } catch (e) {
    error.value = e?.message || "Could not end the discussion.";
  }
}

async function loadSummary() {
  try {
    const full = await api.studyShow(session.value.id);
    if (full.summary) {
      summary.value = full.summary;
      phase.value = "summary";
      stream.close();
    }
  } catch {
    /* keep waiting */
  }
}

function roleClass(role) {
  if (role === "user") return "user";
  return role === "moderator" || role === "synthesis" ? "moderator" : "pastor";
}

// ── Summary actions: copy / share / email / PDF / navigation ────────────────
function summaryText() {
  const s = summary.value || {};
  const L = [];
  L.push("AI Bible Study — Summary");
  if (session.value?.topic || form.question) L.push(`Topic: ${session.value?.topic || form.question}`);
  const sect = (title, items) => {
    const arr = (items || []).filter(Boolean);
    if (arr.length) { L.push("", title); arr.forEach((i) => L.push("- " + i)); }
  };
  sect("Key Verses", s.key_verses);
  sect("Main Lessons", s.lessons);
  if (s.prayer) { L.push("", "Prayer", s.prayer); }
  sect("Action Points", s.action_points);
  sect("Reflection Questions", s.reflection_questions);
  sect("Study Plan", s.study_plan);
  return L.join("\n");
}

const actionMsg = ref("");
function flash(m) { actionMsg.value = m; setTimeout(() => (actionMsg.value = ""), 2500); }

async function copySummary() {
  try { await navigator.clipboard.writeText(summaryText()); flash("Copied to clipboard."); }
  catch { flash("Could not copy."); }
}

async function shareSummary() {
  const text = summaryText();
  if (navigator.share) {
    try { await navigator.share({ title: "AI Bible Study", text }); return; } catch { /* cancelled */ }
  }
  try { await navigator.clipboard.writeText(text); flash("Copied — paste it anywhere to share."); }
  catch { flash("Sharing not supported on this device."); }
}

async function emailSummary() {
  let email = "";
  try {
    const res = await api.studyEmail(session.value.id);
    if (res?.ok) { flash("Summary emailed."); return; }
  } catch (e) {
    if (e?.status === 422) {
      email = window.prompt("Send the summary to which email address?") || "";
      if (!email) return;
      try { await api.studyEmail(session.value.id, email); flash("Summary emailed."); }
      catch { flash("Could not send the email."); }
    } else { flash("Could not send the email."); }
  }
}

async function exportPdf() {
  const el = document.getElementById("study-summary-print");
  if (!el) return;
  const { default: html2pdf } = await import("html2pdf.js");
  el.classList.add("pdf-mode");   // force dark-on-white for a legible PDF
  try {
    await html2pdf()
      .set({
        margin: 12,
        filename: `bible-study-${session.value?.id || "summary"}.pdf`,
        html2canvas: { scale: 2, backgroundColor: "#ffffff" },
        jsPDF: { unit: "mm", format: "a4" },
      })
      .from(el)
      .save();
  } finally {
    el.classList.remove("pdf-mode");
  }
}

function newDiscussion() {
  stream.close();
  summary.value = null;
  bubbles.value = [];
  notice.value = "";
  session.value = null;
  phase.value = "setup";
}

function goHome() { window.location.hash = ""; }
</script>

<template>
  <div class="study-page">
    <!-- Shared site header (matches the rest of the app) -->
    <header class="topbar">
      <a class="brand" href="#">
        <span class="brand-mark" aria-hidden="true">✝</span>
        <span class="brand-name">AI Virtual Church</span>
      </a>
      <div class="topbar-right">
        <nav class="topbar-nav">
          <a href="#bible" class="nav-link">📖 Bible</a>
          <a href="#bible-study" class="nav-link active">💬 Bible Study</a>
        </nav>
        <ThemeToggle />
      </div>
    </header>

    <main class="study">
    <header class="study-head">
      <h1>AI Bible Study</h1>
      <p class="sub" v-if="phase === 'setup'">Sit with experienced pastors. Ask anything. Study together.</p>
    </header>

    <p v-if="error" class="err">{{ error }}</p>

    <!-- SETUP -->
    <section v-if="phase === 'setup'" class="setup card">
      <label>Language
        <select v-model="form.language">
          <option v-for="l in (config?.languages || ['en'])" :key="l" :value="l">{{ LANG_NAMES[l] || l }}</option>
        </select>
      </label>
      <label>Translation
        <select v-model="form.translation">
          <option v-for="[code, name] in translationOptions" :key="code" :value="code">{{ name }}</option>
        </select>
      </label>
      <div class="styles">
        <button
          v-for="s in STYLES" :key="s" type="button"
          :class="['chip', { active: form.style === s }]"
          @click="form.style = form.style === s ? '' : s">{{ s }}</button>
      </div>
      <label>Pastors: {{ form.agent_count }}
        <input type="range" v-model.number="form.agent_count" :min="agentMin" :max="agentMax" :disabled="agentMin >= agentMax" />
      </label>
      <p class="tier-note">
        Your plan allows up to <strong>{{ agentMax }}</strong> pastor{{ agentMax === 1 ? '' : 's' }}<span v-if="config?.tier"> ({{ config.tier }})</span>.
        <span v-if="config?.tier === 'guest'">Register for more.</span>
      </p>
      <label>Your question
        <textarea v-model="form.question" rows="3" placeholder="e.g. What does John 3:16 mean for me?"></textarea>
      </label>
      <button class="primary" :disabled="loading" @click="start">
        {{ loading ? "Starting…" : "Begin Discussion →" }}
      </button>
    </section>

    <!-- DISCUSSION -->
    <section v-else-if="phase === 'discussion'" class="discussion">
      <div class="status">
        <span :class="['dot', connected ? 'on' : 'off']"></span>
        <span v-if="reconnecting">Reconnecting…</span>
        <span v-else-if="connected">Live</span>
        <span v-else>Connecting…</span>
        <button class="ghost end" @click="end">End Discussion</button>
      </div>

      <p v-if="notice" class="notice">{{ notice }}</p>

      <div class="thread">
        <article v-for="b in bubbles" :key="b.turn" :class="['bubble', roleClass(b.role)]">
          <div class="who">{{ b.name }}<span class="role">· {{ b.role }}</span></div>
          <div class="body">{{ b.text }}<span v-if="!b.text" class="typing">…</span></div>
          <div v-if="b.refs.length" class="verses">
            <span v-for="r in b.refs" :key="r.ref" class="verse-card">📖 {{ r.ref }} <em>{{ r.translation }}</em></span>
          </div>
        </article>
      </div>

      <div v-if="inputOpen" class="composer">
        <input v-model="followUp" placeholder="Ask a follow-up…" @keyup.enter="send" />
        <button class="primary" @click="send">Send →</button>
      </div>
    </section>

    <!-- SUMMARY -->
    <section v-else-if="phase === 'summary'" class="summary card">
      <div id="study-summary-print" class="print-area">
        <h2>Your Study Summary</h2>
        <p v-if="session?.topic || form.question" class="topic">{{ session?.topic || form.question }}</p>
        <div v-if="summary.key_verses?.length" class="block">
          <h3>📖 Key Verses</h3>
          <ul><li v-for="(v, i) in summary.key_verses" :key="i">{{ v }}</li></ul>
        </div>
        <div v-if="summary.lessons?.length" class="block">
          <h3>💡 Main Lessons</h3>
          <ul><li v-for="(v, i) in summary.lessons" :key="i">{{ v }}</li></ul>
        </div>
        <div v-if="summary.prayer" class="block">
          <h3>🙏 Prayer</h3><p>{{ summary.prayer }}</p>
        </div>
        <div v-if="summary.action_points?.length" class="block">
          <h3>✅ Action Points</h3>
          <ol><li v-for="(v, i) in summary.action_points" :key="i">{{ v }}</li></ol>
        </div>
        <div v-if="summary.reflection_questions?.length" class="block">
          <h3>❓ Reflection</h3>
          <ul><li v-for="(v, i) in summary.reflection_questions" :key="i">{{ v }}</li></ul>
        </div>
        <div v-if="summary.study_plan?.length" class="block">
          <h3>🗓 Study Plan</h3>
          <ol><li v-for="(v, i) in summary.study_plan" :key="i">{{ v }}</li></ol>
        </div>
      </div>

      <p v-if="actionMsg" class="action-msg">{{ actionMsg }}</p>
      <div class="actions">
        <button class="primary" @click="exportPdf">⬇ Export PDF</button>
        <button class="ghost" @click="copySummary">📋 Copy</button>
        <button class="ghost" @click="shareSummary">🔗 Share</button>
        <button class="ghost" @click="emailSummary">✉ Email me</button>
      </div>
      <div class="actions">
        <button class="primary" @click="newDiscussion">➕ New Discussion</button>
        <button class="ghost" @click="goHome">🏠 Church Home</button>
      </div>
    </section>
    </main>

    <footer class="site-footer">
      <span>✝ AI Virtual Church</span>
      <span class="sep">·</span>
      <span>AI Bible Study</span>
      <span class="sep">·</span>
      <span>© {{ year }}</span>
    </footer>
  </div>
</template>

<style scoped>
.study-page { min-height: 100vh; display: flex; flex-direction: column; background: var(--bg); }

.topbar {
  display: flex; align-items: center; justify-content: space-between;
  gap: 0.75rem; padding: 0.8rem 1.1rem;
  background: var(--surface); border-bottom: 1px solid var(--border);
}
.topbar-right { display: flex; align-items: center; gap: 0.75rem; }
.topbar-nav { display: flex; align-items: center; gap: 0.4rem; }
.brand { display: inline-flex; align-items: center; gap: 0.55rem; text-decoration: none; color: var(--text); font-weight: 600; }
.brand-mark { font-size: 1.15rem; }
.nav-link { text-decoration: none; color: var(--text-muted); padding: 0.35rem 0.6rem; border-radius: var(--radius-sm); font-size: 0.92rem; }
.nav-link:hover { background: var(--surface-2); color: var(--text); }
.nav-link.active { background: var(--primary-soft); color: var(--text); }

.site-footer {
  margin-top: auto; display: flex; flex-wrap: wrap; justify-content: center; gap: 0.5rem;
  padding: 1.1rem; color: var(--text-muted); font-size: 0.85rem;
  border-top: 1px solid var(--border); background: var(--surface);
}
.site-footer .sep { opacity: 0.5; }

.study { max-width: 760px; margin: 0 auto; padding: 1rem; color: var(--text); width: 100%; box-sizing: border-box; flex: 1; }
@media (max-width: 600px) { .brand-name { display: none; } }
.study-head h1 { margin: 0; color: var(--text); }
.sub { color: var(--text-muted); }
.err { color: var(--danger); }

.card {
  background: var(--surface);
  color: var(--text);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 1.25rem;
  box-shadow: var(--shadow-sm);
}

.setup label { display: block; margin: 0.8rem 0; color: var(--text); font-weight: 500; }
.setup select, .setup input, .setup textarea {
  width: 100%; margin-top: 0.3rem; padding: 0.55rem 0.65rem; box-sizing: border-box;
  background: var(--surface-2); color: var(--text);
  border: 1px solid var(--border); border-radius: var(--radius-sm);
  font: inherit;
}
.setup input::placeholder, .setup textarea::placeholder { color: var(--text-faint); }

.tier-note { color: var(--text-muted); font-size: 0.85em; margin: 0.2rem 0 0.6rem; }
.styles { display: flex; flex-wrap: wrap; gap: 0.4rem; margin: 0.6rem 0; }
.chip {
  border: 1px solid var(--border-strong); border-radius: 999px;
  padding: 0.3rem 0.85rem; background: var(--surface-2); color: var(--text);
  cursor: pointer; font: inherit;
}
.chip:hover { border-color: var(--primary); }
.chip.active { background: var(--primary); color: var(--on-primary); border-color: var(--primary); }

.primary {
  background: var(--primary); color: var(--on-primary); border: 0;
  border-radius: var(--radius-sm); padding: 0.6rem 1.1rem; cursor: pointer; font: inherit; font-weight: 600;
}
.primary:hover { background: var(--primary-hover); }
.primary:disabled { opacity: 0.6; cursor: default; }
.ghost {
  background: transparent; color: var(--text); border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm); padding: 0.4rem 0.85rem; cursor: pointer; font: inherit;
}

.status { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.6rem; color: var(--text-muted); }
.status .end { margin-left: auto; }
.dot { width: 9px; height: 9px; border-radius: 50%; display: inline-block; }
.dot.on { background: var(--success); } .dot.off { background: #f59e0b; }

.notice { background: var(--primary-soft); color: var(--text); border: 1px solid var(--border); padding: 0.45rem 0.7rem; border-radius: var(--radius-sm); }

.thread { display: flex; flex-direction: column; gap: 0.8rem; }
.bubble { border-radius: var(--radius); padding: 0.8rem 1rem; border: 1px solid var(--border); background: var(--surface); color: var(--text); }
.bubble.moderator { background: var(--primary-soft); border-color: var(--border-strong); }
.bubble.pastor { background: var(--surface-2); }
.bubble.user { align-self: flex-end; max-width: 88%; background: var(--primary); color: var(--on-primary); border-color: var(--primary); }
.bubble.user .who { color: var(--on-primary); }
.bubble.user .body { color: var(--on-primary); }
.who { font-weight: 600; margin-bottom: 0.3rem; color: var(--text); }
.who .role { color: var(--text-muted); font-weight: 400; font-size: 0.85em; }
.body { white-space: pre-wrap; color: var(--text); }
.typing { color: var(--text-faint); }
.verses { margin-top: 0.55rem; display: flex; flex-wrap: wrap; gap: 0.4rem; }
.verse-card { background: var(--primary-soft); color: var(--text); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 0.2rem 0.55rem; font-size: 0.9em; }
.verse-card em { color: var(--text-muted); }

.composer { display: flex; gap: 0.5rem; margin-top: 0.9rem; position: sticky; bottom: 0; background: var(--bg); padding-top: 0.5rem; }
.composer input { flex: 1; padding: 0.6rem; background: var(--surface-2); color: var(--text); border: 1px solid var(--border); border-radius: var(--radius-sm); font: inherit; }

.summary h2, .summary h3 { color: var(--text); }
.summary .block { margin: 0.9rem 0; }
.summary li, .summary p { color: var(--text); }
.summary .topic { color: var(--text-muted); font-style: italic; margin-top: 0; }
.actions { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 1rem; }
.action-msg { color: var(--success); margin-top: 0.8rem; }
/* Forced light palette during PDF capture so text is legible on white. */
.print-area.pdf-mode { background: #fff; padding: 8px; }
.print-area.pdf-mode :is(h2, h3, li, p, span) { color: #111 !important; }
</style>
