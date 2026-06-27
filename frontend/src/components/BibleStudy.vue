<script setup>
// AI Bible Study — live multi-pastor discussion. Three phases: setup → discussion
// (streaming agent bubbles + verse cards) → summary. Streaming is delivered by
// useStudyStream over SSE; the seq-based composable handles reconnect/replay so a
// dropped connection never loses or duplicates content.
import { onMounted, onBeforeUnmount, reactive, ref, computed, watch } from "vue";
import { api } from "../composables/useApi";
import { useStudyStream } from "../composables/useStudyStream";
import AdCarousel from "./AdCarousel.vue";

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
const restored = ref(false);        // viewing a past session loaded from history (read-only)
const recap = ref("");              // the original stored end-of-discussion summary (chat-spine)
const inputOpen = ref(false);
const followUp = ref("");
const composerInput = ref(null);

// Grow the follow-up box with its content, up to the CSS max-height.
function autoGrow() {
  const el = composerInput.value;
  if (!el) return;
  el.style.height = "auto";
  el.style.height = `${el.scrollHeight}px`;
}

// On mobile, the soft keyboard covers the focused field. Scroll it into
// view above the keyboard once the keyboard animation has settled.
function focusScroll(e) {
  const el = e.target;
  setTimeout(() => {
    el?.scrollIntoView({ block: "center", behavior: "smooth" });
  }, 300);
}
const summary = ref(null);

// Active ads for the box below the Bible Study setup form.
const ads = ref([]);
const hasStudyAd = computed(() =>
  ads.value.some((a) => a.status === "active" && (a.locations || []).includes("bible_study"))
);

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
  // Active ads for the box below the setup form — fire-and-forget so a
  // failure here never blocks the study experience.
  try {
    const res = await api.fetchActiveAds();
    ads.value = res.ads || [];
  } catch (_) { /* ignore */ }
  // Restore a past discussion opened from the history sidebar (#bible-study?session=ID):
  // load its transcript read-only, showing the summary when one was generated.
  const id = new URLSearchParams(window.location.hash.split("?")[1] || "").get("session");
  if (id) await restore(id);
});

async function restore(chatId) {
  try {
    const { session: s } = await api.historyShow(chatId);
    const studyId = s.bibleMeta?.study_session_id;
    restored.value = true;
    if (studyId) {
      // Bridged multi-pastor discussion: rich transcript lives in study_sessions.
      const full = await api.studyShow(studyId);
      session.value = { id: full.id, topic: full.topic };
      form.question = full.topic || "";
      bubbles.value = (full.messages || []).map((m) => ({
        turn: m.turn,
        persona_id: m.persona_id ?? null,
        name: m.role === "user" ? "You" : (m.role === "moderator" || m.role === "synthesis" ? "Moderator" : "Pastor"),
        role: m.role,
        text: m.content || "",
        refs: (m.scripture_refs || []).map((r) => ({ ref: r, translation: full.translation || "" })),
      }));
      if (full.summary) { summary.value = full.summary; phase.value = "summary"; return; }
    } else {
      // Chat-spine study (AI-platform /v1/chat/study): turns are on the session graph.
      session.value = { id: s.id, topic: s.title };
      form.question = s.title || "";
      recap.value = s.summary || "";   // the summary stored when this discussion ended
      bubbles.value = (s.messages || []).map((m, i) => ({
        turn: i,
        persona_id: null,
        name: m.sender === "user" ? "You" : "Pastor",
        role: m.sender === "user" ? "user" : "pastor",
        text: m.content || "",
        refs: [],
      }));
    }
    phase.value = "discussion";
  } catch {
    error.value = "Could not open that study session.";
  }
}

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
  if (composerInput.value) composerInput.value.style.height = "auto";
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

// In-app browsers (Facebook/Instagram/Line/etc.) are WebViews that block the
// programmatic `<a download>` click html2pdf uses to save — so a normal
// download silently fails. Detect them and fall back to opening the PDF in a
// new tab, where the user can save/print it manually.
function isInAppBrowser() {
  const ua = navigator.userAgent || "";
  return /FBAN|FBAV|FB_IAB|Instagram|Line\/|Messenger|Twitter|KAKAOTALK|; wv\)/i.test(ua);
}

async function exportPdf() {
  const el = document.getElementById("study-summary-print");
  if (!el) return;
  const { default: html2pdf } = await import("html2pdf.js");
  el.classList.add("pdf-mode");   // force dark-on-white for a legible PDF
  const filename = `bible-study-${session.value?.id || "summary"}.pdf`;
  const worker = html2pdf().set({
    margin: 12,
    filename,
    html2canvas: { scale: 2, backgroundColor: "#ffffff" },
    jsPDF: { unit: "mm", format: "a4" },
  }).from(el);
  try {
    if (isInAppBrowser()) {
      // Open the rendered PDF in a new tab instead of triggering a download.
      const blob = await worker.outputPdf("blob");
      const url = URL.createObjectURL(blob);
      const win = window.open(url, "_blank");
      if (!win) {
        flash("Tap ••• and choose “Open in browser” to save the PDF.");
      }
      setTimeout(() => URL.revokeObjectURL(url), 60000);
    } else {
      await worker.save();
    }
  } catch {
    flash("Could not export the PDF — try opening this page in your browser.");
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
  restored.value = false;
  if (window.location.hash.startsWith("#bible-study?")) window.location.hash = "#bible-study";
  phase.value = "setup";
}

function goHome() { window.location.hash = ""; }
</script>

<template>
  <div class="study-page">
    <!-- Global site header/footer are provided by the app layout (App.vue);
         this view only renders its own content. -->
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
        <textarea v-model="form.question" rows="3" placeholder="e.g. What does John 3:16 mean for me?" @focus="focusScroll"></textarea>
      </label>
      <button class="primary" :disabled="loading" @click="start">
        {{ loading ? "Starting…" : "Begin Discussion →" }}
      </button>
    </section>

    <!-- Sponsored box below the setup form (admin-controlled via Ads → Bible Study page) -->
    <div v-if="phase === 'setup' && hasStudyAd" class="study-ads">
      <AdCarousel
        :ads="ads"
        location="bible_study"
        :language="form.language" />
    </div>

    <!-- DISCUSSION -->
    <section v-else-if="phase === 'discussion'" class="discussion">
      <div class="status">
        <template v-if="restored">
          <span class="dot off"></span>
          <span>Past discussion</span>
          <button class="ghost end" @click="newDiscussion">New Study</button>
        </template>
        <template v-else>
          <span :class="['dot', connected ? 'on' : 'off']"></span>
          <span v-if="reconnecting">Reconnecting…</span>
          <span v-else-if="connected">Live</span>
          <span v-else>Connecting…</span>
          <button class="ghost end" @click="end">End Discussion</button>
        </template>
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

      <div v-if="restored && recap" class="recap">
        <h3>📝 Summary</h3>
        <p>{{ recap }}</p>
      </div>

      <div v-if="inputOpen" class="composer">
        <textarea
          ref="composerInput"
          v-model="followUp"
          placeholder="Ask a follow-up…"
          rows="1"
          @keydown.enter.exact.prevent="send"
          @input="autoGrow"
          @focus="focusScroll"
        ></textarea>
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
  </div>
</template>

<style scoped>
.study-page { min-height: 100vh; display: flex; flex-direction: column; background: var(--bg); }

.study { max-width: 760px; margin: 0 auto; padding: 1rem; padding-bottom: 40vh; color: var(--text); width: 100%; box-sizing: border-box; flex: 1; }
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
  scroll-margin-bottom: 40vh; /* keep field above the mobile keyboard on focus */
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

.recap { margin-top: 1rem; padding: 0.9rem 1rem; background: var(--surface-2); border: 1px solid var(--border); border-radius: var(--radius-md, 12px); }
.recap h3 { margin: 0 0 0.5rem; font-size: 1rem; }
.recap p { margin: 0; line-height: 1.55; white-space: pre-wrap; }
.composer { display: flex; align-items: flex-end; gap: 0.5rem; margin-top: 0.9rem; position: sticky; bottom: 0; background: var(--bg); padding-top: 0.5rem; }
.composer textarea { flex: 1; padding: 0.7rem 0.85rem; background: var(--surface-2); color: var(--text); border: 1px solid var(--border); border-radius: var(--radius-md, 12px); font: inherit; line-height: 1.45; resize: none; min-height: 2.75rem; max-height: 11rem; overflow-y: auto; }
.composer textarea:focus { outline: none; border-color: var(--accent, #3b82f6); }
.composer textarea::placeholder { color: var(--text-faint); }
.composer .primary { align-self: stretch; white-space: nowrap; }

.summary h2, .summary h3 { color: var(--text); }
.summary .block { margin: 0.9rem 0; }
.summary li, .summary p { color: var(--text); }
.summary .topic { color: var(--text-muted); font-style: italic; margin-top: 0; }
.actions { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 1rem; }
.action-msg { color: var(--success); margin-top: 0.8rem; }
/* Forced light palette during PDF capture so text is legible on white. */
.print-area.pdf-mode { background: #fff; padding: 8px; }
.print-area.pdf-mode :is(h2, h3, li, p, span) { color: #111 !important; }

.study-ads { margin-top: 1.5rem; }
</style>
