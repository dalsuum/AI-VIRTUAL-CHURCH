<script setup>
// Spiritual Journey dashboard — stat cards + a chronological timeline of every
// interaction. All figures come from the owner-scoped /history/stats + /history/timeline.
import { ref, onMounted, computed } from "vue";
import { api } from "../composables/useApi";

const stats = ref(null);
const timeline = ref(null);
const journal = ref([]);
const year = ref(new Date().getFullYear());
const error = ref("");

const TYPE_ICON = {
  bible_study: "📖", prayer: "🙏", music: "🎵", service: "⛪", pastor: "💬", devotion: "📚", general: "🗒️",
};

const topics = computed(() => Object.entries(stats.value?.top_topics || {}));
const months = computed(() => Object.entries(timeline.value?.months || {}));

async function load() {
  try {
    await api.ensureSession().catch(() => {});
    stats.value = await api.historyStats();
    timeline.value = await api.historyTimeline(year.value);
    await loadJournal();
  } catch (e) { error.value = "Could not load your journey."; }
}

async function loadJournal() {
  try { journal.value = (await api.journalList()).entries || []; } catch { /* ignore */ }
}

async function deleteEntry(entry) {
  if (!confirm("Delete this journal entry?")) return;
  await api.journalDelete(entry.id);
  journal.value = journal.value.filter((e) => e.id !== entry.id);
}

async function changeYear(delta) {
  year.value += delta;
  timeline.value = await api.historyTimeline(year.value);
}

function exportAll(format) { window.open(api.historyExportAllUrl(format), "_blank"); }

onMounted(load);
</script>

<template>
  <section class="journey">
    <header class="journey-head">
      <h2>📊 My Spiritual Journey</h2>
      <div class="journey-exports">
        <span>Export journal:</span>
        <button @click="exportAll('pdf')">PDF</button>
        <button @click="exportAll('docx')">DOCX</button>
        <button @click="exportAll('md')">Markdown</button>
        <button @click="exportAll('json')">JSON</button>
      </div>
    </header>

    <p v-if="error" class="journey-err">{{ error }}</p>

    <div v-if="stats" class="cards">
      <div class="card"><b>{{ stats.counts.total }}</b><span>Total sessions</span></div>
      <div class="card"><b>{{ stats.counts.bible_study }}</b><span>📖 Bible studies</span></div>
      <div class="card"><b>{{ stats.counts.pastor }}</b><span>💬 Pastor chats</span></div>
      <div class="card"><b>{{ stats.counts.music }}</b><span>🎵 Worship</span></div>
      <div class="card"><b>{{ stats.counts.service }}</b><span>⛪ Services</span></div>
      <div class="card"><b>{{ stats.counts.prayer }}</b><span>🙏 Prayers</span></div>
      <div class="card highlight"><b>🔥 {{ stats.streak_days }}</b><span>Day streak</span></div>
      <div class="card" v-if="stats.favorite_book"><b>{{ stats.favorite_book }}</b><span>Favorite book</span></div>
    </div>

    <div v-if="topics.length" class="topics">
      <h3>Most discussed topics</h3>
      <span v-for="[tag, count] in topics" :key="tag" class="topic">#{{ tag }} · {{ count }}</span>
    </div>

    <div v-if="journal.length" class="journal">
      <h3>📔 My Journal</h3>
      <article v-for="e in journal" :key="e.id" class="entry" :class="e.status">
        <header>
          <b>{{ e.title || 'Journal entry' }}</b>
          <span v-if="e.scripture_ref" class="ref">{{ e.scripture_ref }}</span>
          <button class="del" @click="deleteEntry(e)" title="Delete">✕</button>
        </header>
        <p v-if="e.status === 'pending'" class="pending">Writing your reflection… <button @click="loadJournal">refresh</button></p>
        <p v-else-if="e.status === 'failed'" class="pending">Could not generate this entry.</p>
        <template v-else>
          <p v-if="e.insight"><b>Insight.</b> {{ e.insight }}</p>
          <p v-if="e.prayer"><b>Prayer.</b> {{ e.prayer }}</p>
          <p v-if="e.reflection"><b>Reflection.</b> {{ e.reflection }}</p>
        </template>
      </article>
    </div>

    <div class="timeline">
      <div class="timeline-head">
        <h3>Timeline</h3>
        <div class="yearnav">
          <button @click="changeYear(-1)">‹</button>
          <b>{{ year }}</b>
          <button @click="changeYear(1)">›</button>
        </div>
      </div>
      <p v-if="!months.length" class="journey-dim">Nothing recorded in {{ year }} yet.</p>
      <div v-for="[month, items] in months" :key="month" class="month">
        <h4>{{ month }}</h4>
        <ul>
          <li v-for="it in items" :key="it.id">
            <span>{{ TYPE_ICON[it.type] || "🗒️" }}</span> {{ it.title }}
            <em v-if="it.mood">· {{ it.mood }}</em>
          </li>
        </ul>
      </div>
    </div>
  </section>
</template>

<style scoped>
.journey { max-width: 880px; margin: 0 auto; padding: 18px; }
.journey-head { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: space-between; }
.journey-exports { display: flex; gap: 6px; align-items: center; font-size: 13px; opacity: .85; }
.journey-exports button { padding: 3px 9px; border-radius: 7px; border: 1px solid var(--border,#ddd); background: none; cursor: pointer; }
.journey-err { color: #b91c1c; }
.cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 10px; margin: 16px 0; }
.card { border: 1px solid var(--border); border-radius: 12px; padding: 14px; text-align: center; background: var(--surface); }
.card b { display: block; font-size: 24px; }
.card span { font-size: 12px; opacity: .7; }
.card.highlight { background: var(--danger-soft); border-color: var(--danger); }
.topics { margin: 14px 0; }
.topic { display: inline-block; background: var(--surface-3); border-radius: 999px; padding: 2px 10px; margin: 3px; font-size: 12px; }
.timeline-head { display: flex; align-items: center; justify-content: space-between; }
.yearnav { display: flex; gap: 8px; align-items: center; }
.yearnav button { border: 1px solid var(--border,#ddd); background: none; border-radius: 6px; width: 26px; height: 26px; cursor: pointer; }
.month h4 { margin: 12px 0 4px; opacity: .8; }
.month ul { list-style: none; padding: 0; margin: 0; }
.month li { padding: 5px 8px; border-left: 2px solid var(--primary); margin: 3px 0; }
.month li em { opacity: .55; font-style: normal; }
.journey-dim { opacity: .55; }
.journal { margin: 18px 0; }
.entry { border: 1px solid var(--border); border-radius: 12px; padding: 12px 14px; margin: 8px 0; background: var(--surface); }
.entry header { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
.entry header b { flex: 1; }
.entry .ref { font-size: 12px; background: var(--surface-3); border-radius: 999px; padding: 1px 8px; }
.entry .del { border: none; background: none; cursor: pointer; opacity: .5; }
.entry p { margin: 4px 0; line-height: 1.45; }
.entry .pending { opacity: .7; font-style: italic; }
.entry .pending button { border: none; background: none; color: var(--primary); cursor: pointer; text-decoration: underline; }
</style>
