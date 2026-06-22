<script setup>
// ChatGPT-style unified history rail. Lists every interaction (Bible Study, Worship,
// Service, Pastor Chat, …) for the signed-in user, grouped by date, searchable, with
// pin/favorite/rename/archive/delete/export/share actions. Clicking an item opens a
// read-only transcript; "Continue" resumes a Pastor Chat. Owner-scoping is enforced
// server-side — this component only ever sees the caller's own sessions.
import { ref, onMounted, computed } from "vue";
import { api } from "../composables/useApi";

const props = defineProps({ authed: { type: Boolean, default: false } });

const TYPE_ICON = {
  bible_study: "📖", prayer: "🙏", music: "🎵",
  service: "⛪", pastor: "💬", devotion: "📚", general: "🗒️",
};

const collapsed = ref(localStorage.getItem("history.collapsed") === "1");
const loading = ref(false);
const pinned = ref([]);
const groups = ref({});            // { "Today": [...], ... }
const nextCursor = ref(null);
const query = ref("");
const searching = ref(false);
const searchResults = ref(null);   // null = not searching
const detail = ref(null);          // open transcript
const flash = ref("");

const GROUP_ORDER = ["Today", "Yesterday", "Previous 7 Days", "Previous 30 Days", "Older"];
const orderedGroups = computed(() =>
  GROUP_ORDER.filter((g) => groups.value[g]?.length).map((g) => [g, groups.value[g]])
);

function icon(t) { return TYPE_ICON[t] || "🗒️"; }

async function load(reset = true) {
  if (!props.authed) return;
  loading.value = true;
  try {
    const params = reset ? "" : `?cursor=${encodeURIComponent(nextCursor.value)}`;
    const res = await api.history(params);
    if (reset) {
      pinned.value = res.pinned || [];
      groups.value = res.groups || {};
    } else {
      // Merge a paginated page into the existing buckets.
      for (const [g, items] of Object.entries(res.groups || {})) {
        groups.value[g] = [...(groups.value[g] || []), ...items];
      }
    }
    nextCursor.value = res.next_cursor || null;
  } catch (e) {
    flash.value = "Could not load history.";
  } finally {
    loading.value = false;
  }
}

async function runSearch() {
  const q = query.value.trim();
  if (!q) { searchResults.value = null; return; }
  searching.value = true;
  try {
    const res = await api.historySearch({ q });
    searchResults.value = res.results || [];
  } finally {
    searching.value = false;
  }
}

function clearSearch() { query.value = ""; searchResults.value = null; }

async function openItem(item) {
  try {
    const res = await api.historyShow(item.id);
    detail.value = res.session;
  } catch { flash.value = "Could not open session."; }
}

function resume(item) {
  // Pastor chats resume interactively; others open their module page.
  const routes = {
    pastor: `#pastor?session=${item.id}`,
    bible_study: "#bible-study",
    music: "#worship",
    service: "#account",
  };
  window.location.hash = routes[item.type || item.session_type] || "#account";
  detail.value = null;
}

async function rename(item) {
  const title = prompt("Rename session", item.title);
  if (title == null) return;
  await api.historyUpdate(item.id, { title });
  item.title = title;
  flash.value = "Renamed.";
}

async function togglePin(item) {
  await api.historyUpdate(item.id, { pinned: !item.pinned });
  await load(true);
}

async function toggleFavorite(item) {
  item.favorite = !item.favorite;
  await api.historyUpdate(item.id, { favorite: item.favorite });
}

async function archive(item) {
  await api.historyUpdate(item.id, { archived: true });
  await load(true);
  flash.value = "Archived.";
}

async function remove(item) {
  if (!confirm("Delete this session? You can restore it later.")) return;
  await api.historyDelete(item.id);
  detail.value = null;
  await load(true);
  flash.value = "Deleted.";
}

async function share(item) {
  const res = await api.historyShare(item.id, {});
  await navigator.clipboard?.writeText(res.url).catch(() => {});
  flash.value = "Share link copied: " + res.url;
}

function exportItem(item, format) {
  window.open(api.historyExportUrl(item.id, format), "_blank");
}

async function saveToJournal(item) {
  try {
    await api.journalGenerate(item.id);
    flash.value = "Writing your journal entry… find it under 📊 My Journey.";
  } catch { flash.value = "Could not start journal entry."; }
}

function toggleCollapse() {
  collapsed.value = !collapsed.value;
  localStorage.setItem("history.collapsed", collapsed.value ? "1" : "0");
}

function newPastor() { window.location.hash = "#pastor"; }
function openJourney() { window.location.hash = "#journey"; }

onMounted(() => load(true));
defineExpose({ reload: () => load(true) });
</script>

<template>
  <aside v-if="authed" class="history-rail" :class="{ collapsed }">
    <button class="hr-collapse" @click="toggleCollapse" :title="collapsed ? 'Expand' : 'Collapse'">
      {{ collapsed ? "»" : "«" }}
    </button>

    <div v-if="!collapsed" class="hr-body">
      <div class="hr-head">
        <strong>📜 My Journey</strong>
        <button class="hr-mini" @click="openJourney" title="Spiritual Journey">📊</button>
      </div>
      <button class="hr-new" @click="newPastor">＋ New Pastor Chat</button>

      <div class="hr-search">
        <input v-model="query" @keyup.enter="runSearch" placeholder="Search everything…" />
        <button v-if="searchResults" class="hr-mini" @click="clearSearch">✕</button>
        <button v-else class="hr-mini" @click="runSearch">🔎</button>
      </div>

      <p v-if="flash" class="hr-flash" @click="flash = ''">{{ flash }}</p>

      <!-- Search results -->
      <div v-if="searchResults" class="hr-section">
        <h4>Results ({{ searchResults.length }})</h4>
        <p v-if="searching" class="hr-dim">Searching…</p>
        <p v-else-if="!searchResults.length" class="hr-dim">Nothing found.</p>
        <button v-for="it in searchResults" :key="it.id" class="hr-item" @click="openItem(it)">
          <span class="hr-ic">{{ icon(it.type) }}</span><span class="hr-tt">{{ it.title }}</span>
        </button>
      </div>

      <!-- Normal grouped list -->
      <template v-else>
        <div v-if="pinned.length" class="hr-section">
          <h4>📌 Pinned</h4>
          <button v-for="it in pinned" :key="it.id" class="hr-item" @click="openItem(it)">
            <span class="hr-ic">{{ icon(it.type) }}</span><span class="hr-tt">{{ it.title }}</span>
          </button>
        </div>

        <div v-for="[label, items] in orderedGroups" :key="label" class="hr-section">
          <h4>{{ label }}</h4>
          <button v-for="it in items" :key="it.id" class="hr-item" @click="openItem(it)">
            <span class="hr-ic">{{ icon(it.type) }}</span>
            <span class="hr-tt">{{ it.title }}</span>
            <span v-if="it.favorite" class="hr-star">★</span>
          </button>
        </div>

        <button v-if="nextCursor" class="hr-more" @click="load(false)" :disabled="loading">
          {{ loading ? "Loading…" : "Load more" }}
        </button>
        <p v-else-if="!loading && !pinned.length && !orderedGroups.length" class="hr-dim">
          No sessions yet. Start a Bible Study, Worship, or Pastor Chat.
        </p>
      </template>
    </div>

    <!-- Read-only transcript overlay -->
    <div v-if="detail" class="hr-overlay" @click.self="detail = null">
      <div class="hr-panel">
        <header>
          <span class="hr-ic">{{ icon(detail.session_type) }}</span>
          <strong>{{ detail.title }}</strong>
          <button class="hr-mini" @click="detail = null">✕</button>
        </header>
        <div class="hr-actions">
          <button @click="resume(detail)">▶ Continue</button>
          <button @click="rename(detail)">Rename</button>
          <button @click="togglePin(detail)">{{ detail.pinned ? "Unpin" : "Pin" }}</button>
          <button @click="toggleFavorite(detail)">{{ detail.favorite ? "Unstar" : "Star" }}</button>
          <button @click="saveToJournal(detail)">📔 Journal</button>
          <button @click="share(detail)">Share</button>
          <button @click="exportItem(detail, 'md')">MD</button>
          <button @click="exportItem(detail, 'pdf')">PDF</button>
          <button @click="exportItem(detail, 'docx')">DOCX</button>
          <button @click="exportItem(detail, 'json')">JSON</button>
          <button @click="archive(detail)">Archive</button>
          <button class="danger" @click="remove(detail)">Delete</button>
        </div>
        <p v-if="detail.summary" class="hr-summary">{{ detail.summary }}</p>
        <div class="hr-tags" v-if="detail.tags?.length">
          <span v-for="t in detail.tags" :key="t.tag || t" class="hr-tag">#{{ t.tag || t }}</span>
        </div>
        <div class="hr-transcript">
          <p v-for="(m, i) in (detail.messages || [])" :key="i" :class="['hr-msg', m.sender]">
            <b>{{ m.sender === 'user' ? 'You' : m.sender }}:</b> {{ m.content }}
          </p>
          <p v-if="!(detail.messages || []).length" class="hr-dim">
            This session has no chat transcript.
          </p>
        </div>
      </div>
    </div>
  </aside>
</template>

<style scoped>
.history-rail { width: 280px; flex: 0 0 280px; border-right: 1px solid var(--border, #e3e3e3);
  background: var(--panel, #fafafa); height: 100vh; position: sticky; top: 0; overflow-y: auto; }
.history-rail.collapsed { width: 40px; flex-basis: 40px; }
.hr-collapse { position: sticky; top: 6px; left: 6px; margin: 6px; background: none; border: 1px solid var(--border,#ddd);
  border-radius: 6px; cursor: pointer; width: 28px; height: 28px; }
.hr-body { padding: 6px 10px 40px; }
.hr-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
.hr-new { width: 100%; padding: 8px; border-radius: 8px; border: 1px solid var(--border,#ddd);
  background: var(--accent,#4f46e5); color: #fff; cursor: pointer; margin-bottom: 8px; }
.hr-search { display: flex; gap: 4px; margin-bottom: 8px; }
.hr-search input { flex: 1; padding: 6px 8px; border-radius: 8px; border: 1px solid var(--border,#ddd); }
.hr-mini { background: none; border: 1px solid var(--border,#ddd); border-radius: 6px; cursor: pointer; padding: 2px 7px; }
.hr-section { margin-bottom: 12px; }
.hr-section h4 { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; opacity: .6; margin: 8px 0 4px; }
.hr-item { display: flex; align-items: center; gap: 6px; width: 100%; text-align: left; padding: 7px 8px;
  border: none; background: none; border-radius: 8px; cursor: pointer; font-size: 13px; }
.hr-item:hover { background: var(--hover, #eee); }
.hr-ic { flex: 0 0 auto; }
.hr-tt { flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.hr-star { color: #eab308; }
.hr-more { width: 100%; padding: 6px; margin-top: 4px; border: 1px dashed var(--border,#ccc);
  border-radius: 8px; background: none; cursor: pointer; }
.hr-dim { opacity: .55; font-size: 12px; padding: 4px 8px; }
.hr-flash { font-size: 12px; background: #fef9c3; border-radius: 6px; padding: 5px 8px; cursor: pointer; }
.hr-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.4); display: flex; justify-content: center;
  align-items: center; z-index: 1000; }
.hr-panel { background: var(--panel,#fff); width: min(680px, 92vw); max-height: 86vh; overflow-y: auto;
  border-radius: 14px; padding: 16px; }
.hr-panel header { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
.hr-panel header strong { flex: 1; }
.hr-actions { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; }
.hr-actions button { padding: 4px 9px; border-radius: 7px; border: 1px solid var(--border,#ddd);
  background: none; cursor: pointer; font-size: 12px; }
.hr-actions .danger { color: #b91c1c; border-color: #fca5a5; }
.hr-summary { font-style: italic; opacity: .8; border-left: 3px solid var(--accent,#4f46e5); padding-left: 8px; }
.hr-tags { display: flex; flex-wrap: wrap; gap: 5px; margin: 6px 0; }
.hr-tag { font-size: 11px; background: var(--hover,#eee); border-radius: 999px; padding: 1px 8px; }
.hr-transcript { margin-top: 10px; }
.hr-msg { margin: 6px 0; line-height: 1.4; }
.hr-msg.user { text-align: right; }
@media (max-width: 760px) {
  .history-rail { position: fixed; bottom: 0; top: auto; width: 100%; height: 46vh; z-index: 900;
    border-top: 1px solid var(--border,#ddd); }
  .history-rail.collapsed { height: 40px; width: 100%; flex-basis: 40px; }
}
</style>
