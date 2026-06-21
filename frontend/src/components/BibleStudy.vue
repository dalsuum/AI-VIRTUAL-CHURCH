<script setup>
// AI Bible Study — live multi-pastor discussion. Three phases: setup → discussion
// (streaming agent bubbles + verse cards) → summary. Streaming is delivered by
// useStudyStream over SSE; the seq-based composable handles reconnect/replay so a
// dropped connection never loses or duplicates content.
import { onMounted, onBeforeUnmount, reactive, ref, computed, watch } from "vue";
import { api } from "../composables/useApi";
import { useStudyStream } from "../composables/useStudyStream";

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
  return role === "moderator" || role === "synthesis" ? "moderator" : "pastor";
}
</script>

<template>
  <div class="study">
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
      <h2>Your Study Summary</h2>
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
    </section>
  </div>
</template>

<style scoped>
.study { max-width: 760px; margin: 0 auto; padding: 1rem; color: var(--text); }
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
</style>
