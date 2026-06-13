<script setup>
// The gate between intake and the service. Shows the church mark and a gentle
// countdown while the AI pipeline composes the service in the background. For a
// registered worshipper, the mood-aware "welcome back" greeting fades in as soon as
// it lands. The doors open only when the parent confirms the worship media and the
// first narrated prayer are ready.
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import { api } from "../composables/useApi";

const props = defineProps({
  // The polled service object (null until the first poll returns). We read its
  // `status` and `welcome` fields.
  service: { type: Object, default: null },
  // The worshipper's remembered display name, for the fallback greeting.
  displayName: { type: String, default: "" },
  // The chosen worship-music mode, which sets how long composition takes and so
  // how long the countdown runs: "suno" (AI-composed, ~2 min) | "youtube"/"hymn"
  // (return in seconds — YouTube lookup / a pre-rendered public-domain hymn).
  musicSource: { type: String, default: "" },
  // True once the opening experience is ready: worship music has landed when
  // expected, and server narration has produced the opening-prayer audio.
  mediaReady: { type: Boolean, default: false },
});
const emit = defineEmits(["ready"]);

// Myanmar/Tedim need more breathing room because local MMS-TTS work is staggered
// and Suno customMode renders can take longer than text generation.
const COUNTDOWN_BY_SOURCE = { suno: 150, musicgen: 480, youtube: 35, hymn: 35, hymn_sung: 35 };
const MULTILINGUAL_COUNTDOWN_BY_SOURCE = { suno: 300, musicgen: 480, youtube: 90, hymn: 90, hymn_sung: 90 };
const isMultilingualVoice = computed(() => ["my", "td"].includes(props.service?.language));
const lockedMusicSource = computed(() => props.musicSource || props.service?.music_source || "");
const countdownFrom = computed(() => {
  const table = isMultilingualVoice.value ? MULTILINGUAL_COUNTDOWN_BY_SOURCE : COUNTDOWN_BY_SOURCE;
  return table[lockedMusicSource.value] ?? (isMultilingualVoice.value ? 90 : 35);
});
const remaining = ref(countdownFrom.value);
let timer = null;
let cardTimer = null;
let opened = false;

const hasService = computed(() => props.service != null);
const welcome = computed(() => props.service?.welcome || "");
const countdownDone = computed(() => remaining.value <= 0);
const countdownCards = ref([]);
const currentCardIndex = ref(0);
const currentCardContext = ref("");
const currentCard = computed(() => countdownCards.value[currentCardIndex.value] || null);
const cardContext = computed(() => {
  const language = props.service?.language || "";
  const mood = props.service?.mood || "";
  return language + "|" + mood;
});

const FALLBACK_VERSE_CARDS = {
  en: [
    { type: "online", text: "The Lord is near to all who call on Him in truth.", source: "Psalm 145:18 (BSB)" },
    { type: "online", text: "Cast all your anxiety on Him because He cares for you.", source: "1 Peter 5:7 (BSB)" },
    { type: "online", text: "The Lord is my shepherd; I shall not want.", source: "Psalm 23:1 (BSB)" },
  ],
  my: [
    { type: "online", text: "ထာဝရဘုရားသည် မိမိကို အမှန်တကယ် ခေါ်သောသူအပေါင်းတို့နှင့် နီးတော်မူ၏။", source: "ဆာလံ 145:18" },
    { type: "online", text: "သင်တို့၏ စိုးရိမ်ပူပန်မှုအပေါင်းတို့ကို ကိုယ်တော်ပေါ်သို့ အပ်နှံကြလော့။", source: "၁ ပေတရု 5:7" },
    { type: "online", text: "ထာဝရဘုရားသည် ကျွန်ုပ်၏ ထိန်းကျောင်းသူဖြစ်တော်မူ၏။", source: "ဆာလံ 23:1" },
  ],
  td: [
    { type: "online", text: "Topa in amah taktak a samte tungah a nai hi.", source: "Late 145:18" },
    { type: "online", text: "Na lungkhamna khempeuh Topa tungah koih in; aman nang hong ngaisak hi.", source: "1 Peter 5:7" },
    { type: "online", text: "Topa pen ka zohpa hi; ka kisam lo ding hi.", source: "Late 23:1" },
  ],
};

function ensureRotatingCards(cards) {
  const language = props.service?.language || "en";
  const fallbacks = FALLBACK_VERSE_CARDS[language] || FALLBACK_VERSE_CARDS.en;
  const next = Array.isArray(cards) ? [...cards] : [];
  const seen = new Set(next.map((c) => (c.type || "") + "|" + (c.text || "") + "|" + (c.source || "")));

  for (const card of fallbacks) {
    if (next.length >= 4) break;
    const key = (card.type || "") + "|" + (card.text || "") + "|" + (card.source || "");
    if (seen.has(key)) continue;
    next.push(card);
    seen.add(key);
  }

  return next.slice(0, 16);
}

// Open only when the parent says the opening media is ready. The countdown is a
// visible minimum/expectation, not permission to start without the first voice.
const canOpen = computed(() => props.mediaReady && hasService.value);

function tick() {
  if (remaining.value > 0) remaining.value -= 1;
  if (canOpen.value && !opened) {
    opened = true;
    clearInterval(timer);
    timer = null;
    if (cardTimer) {
      clearInterval(cardTimer);
      cardTimer = null;
    }
    emit("ready");
  }
}

async function loadCountdownCards() {
  try {
    const language = props.service?.language || undefined;
    const mood = props.service?.mood || undefined;
    const cfg = await api.getConfig({ language, mood });
    const cards = Array.isArray(cfg.countdown_cards) ? cfg.countdown_cards : [];
    countdownCards.value = ensureRotatingCards(cards
      .filter((c) => c && typeof c.text === "string" && c.text.trim())
      .map((c) => ({
        type: ["testimony", "online"].includes(c.type) ? c.type : "banner",
        text: c.text.trim(),
        source: typeof c.source === "string" ? c.source.trim() : "",
      }))
      .slice(0, 16));
    currentCardIndex.value = 0;
    currentCardContext.value = cardContext.value;
  } catch {
    countdownCards.value = ensureRotatingCards([]);
  }
}

watch(countdownFrom, (next) => {
  if (!opened && remaining.value < next) remaining.value = next;
});

watch(cardContext, (next) => {
  if (next !== currentCardContext.value) loadCountdownCards();
});

onMounted(() => {
  remaining.value = countdownFrom.value;
  loadCountdownCards();
  timer = setInterval(tick, 1000);
  cardTimer = setInterval(() => {
    if (countdownCards.value.length > 1) {
      currentCardIndex.value = (currentCardIndex.value + 1) % countdownCards.value.length;
    }
  }, 9000);
});
onUnmounted(() => {
  if (timer) clearInterval(timer);
  if (cardTimer) clearInterval(cardTimer);
});
</script>

<template>
  <section class="preparing">
    <div class="mark" aria-hidden="true">✝</div>
    <h1 class="title">AI Virtual Church</h1>

    <!-- Personalized greeting for registered worshippers, the moment it arrives. -->
    <transition name="fade">
      <p v-if="welcome" class="welcome">{{ welcome }}</p>
    </transition>

    <div class="count" :class="{ holding: countdownDone && !hasService }">
      <template v-if="!countdownDone">
        <span class="num">{{ remaining }}</span>
        <span class="lead">Your service begins in…</span>
      </template>
      <template v-else-if="!hasService || !mediaReady">
        <span class="spinner" aria-hidden="true"></span>
        <span class="lead">Preparing worship music and first narration…</span>
      </template>
      <template v-else>
        <span class="lead">Opening the doors…</span>
      </template>
    </div>

    <transition name="fade" mode="out-in">
      <div v-if="currentCard" :key="currentCardIndex" class="wait-card">
        <span class="card-label">{{ currentCard.type === "testimony" ? "Testimony" : currentCard.type === "online" ? "Bible verse" : "While you wait" }}</span>
        <p>{{ currentCard.text }}</p>
        <small v-if="currentCard.source">{{ currentCard.source }}</small>
      </div>
    </transition>

    <p class="hint">Take a breath. Your worship will begin soon.</p>
  </section>
</template>

<style scoped>
.preparing {
  min-height: 70vh;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
  gap: 1.1rem;
  padding: 2rem 1rem;
}
.mark {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 72px;
  height: 72px;
  border-radius: 20px;
  background: var(--primary);
  color: var(--on-primary);
  font-size: 2rem;
  box-shadow: var(--shadow);
  animation: pulse 2.4s ease-in-out infinite;
}
.title { font-size: 1.5rem; margin: 0; letter-spacing: -0.02em; }
.welcome {
  max-width: 34rem;
  color: var(--text);
  line-height: 1.7;
  font-size: 1.05rem;
  margin: 0.25rem 0;
}
.count { display: flex; flex-direction: column; align-items: center; gap: 0.35rem; margin-top: 0.5rem; }
.num { font-size: 3.5rem; font-weight: 700; line-height: 1; color: var(--primary); letter-spacing: -0.03em; }
.lead { color: var(--text-muted); font-size: 0.95rem; }
.hint { color: var(--text-faint); font-size: 0.85rem; max-width: 28rem; margin: 0.5rem 0 0; }
.wait-card {
  width: min(100%, 34rem);
  border: 1px solid var(--border);
  background: var(--surface-2);
  border-radius: var(--radius-sm);
  padding: 0.95rem 1rem;
  text-align: left;
  box-shadow: var(--shadow-sm);
}
.wait-card p { margin: 0.35rem 0 0; color: var(--text); line-height: 1.6; font-size: 0.95rem; }
.wait-card small { display: block; margin-top: 0.5rem; color: var(--text-faint); font-size: 0.78rem; }
.card-label { color: var(--primary); font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; }

.spinner {
  width: 1.6rem; height: 1.6rem; border-radius: 50%;
  border: 3px solid var(--surface-3);
  border-top-color: var(--primary);
  animation: spin 0.9s linear infinite;
}

@keyframes pulse { 0%, 100% { transform: scale(1); opacity: 1; } 50% { transform: scale(1.06); opacity: 0.85; } }
@keyframes spin { to { transform: rotate(360deg); } }

.fade-enter-active { transition: opacity 0.6s ease; }
.fade-enter-from { opacity: 0; }
</style>
