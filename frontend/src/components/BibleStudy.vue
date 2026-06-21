<script setup>
// AI Bible Study — live multi-pastor discussion. Three phases: setup → discussion
// (streaming agent bubbles + verse cards) → summary. Streaming is delivered by
// useStudyStream over SSE; the seq-based composable handles reconnect/replay so a
// dropped connection never loses or duplicates content.
import { onMounted, onBeforeUnmount, reactive, ref, computed } from "vue";
import { api } from "../composables/useApi";
import { useStudyStream } from "../composables/useStudyStream";

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

onMounted(async () => {
  try {
    await api.ensureSession();
    config.value = await api.studyConfig();
    form.agent_count = config.value.default_agent_count ?? 4;
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
          <option v-for="l in (config?.languages || ['en'])" :key="l" :value="l">{{ l }}</option>
        </select>
      </label>
      <label>Translation
        <input v-model="form.translation" maxlength="12" />
      </label>
      <div class="styles">
        <button
          v-for="s in STYLES" :key="s" type="button"
          :class="['chip', { active: form.style === s }]"
          @click="form.style = form.style === s ? '' : s">{{ s }}</button>
      </div>
      <label>Pastors: {{ form.agent_count }}
        <input type="range" v-model.number="form.agent_count" :min="agentMin" :max="agentMax" />
      </label>
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
.study { max-width: 760px; margin: 0 auto; padding: 1rem; }
.study-head h1 { margin: 0; }
.sub { color: var(--muted, #888); }
.err { color: #c0392b; }
.card { background: var(--card, #fff); border: 1px solid var(--border, #e3e3e3); border-radius: 12px; padding: 1rem; }
.setup label { display: block; margin: 0.6rem 0; }
.setup select, .setup input, .setup textarea { width: 100%; padding: 0.5rem; box-sizing: border-box; }
.styles { display: flex; flex-wrap: wrap; gap: 0.4rem; margin: 0.5rem 0; }
.chip { border: 1px solid var(--border, #ccc); border-radius: 999px; padding: 0.25rem 0.7rem; background: transparent; cursor: pointer; }
.chip.active { background: var(--accent, #3b5bdb); color: #fff; }
.primary { background: var(--accent, #3b5bdb); color: #fff; border: 0; border-radius: 8px; padding: 0.6rem 1rem; cursor: pointer; }
.ghost { background: transparent; border: 1px solid var(--border, #ccc); border-radius: 8px; padding: 0.4rem 0.8rem; cursor: pointer; }
.status { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem; }
.status .end { margin-left: auto; }
.dot { width: 9px; height: 9px; border-radius: 50%; display: inline-block; }
.dot.on { background: #2ecc71; } .dot.off { background: #f39c12; }
.notice { background: #fff8e1; border: 1px solid #ffe082; padding: 0.4rem 0.6rem; border-radius: 8px; }
.thread { display: flex; flex-direction: column; gap: 0.8rem; }
.bubble { border-radius: 12px; padding: 0.7rem 0.9rem; border: 1px solid var(--border, #e3e3e3); }
.bubble.moderator { background: var(--card-alt, #f4f6ff); }
.bubble.pastor { background: var(--card, #fff); }
.who { font-weight: 600; margin-bottom: 0.25rem; }
.who .role { color: var(--muted, #999); font-weight: 400; font-size: 0.85em; }
.body { white-space: pre-wrap; }
.typing { color: var(--muted, #999); }
.verses { margin-top: 0.5rem; display: flex; flex-wrap: wrap; gap: 0.4rem; }
.verse-card { background: var(--accent-soft, #eef2ff); border-radius: 8px; padding: 0.2rem 0.5rem; font-size: 0.9em; }
.composer { display: flex; gap: 0.5rem; margin-top: 0.8rem; position: sticky; bottom: 0; }
.composer input { flex: 1; padding: 0.6rem; }
.summary .block { margin: 0.8rem 0; }
</style>
