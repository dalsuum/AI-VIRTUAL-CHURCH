<script setup>
// ChatGPT-style unified history rail. Lists every interaction (Bible Study, Worship,
// Service, Pastor Chat, …) for the signed-in user, grouped by date, searchable, with
// pin/favorite/rename/archive/delete/export/share actions. Clicking an item opens a
// read-only transcript; "Continue" resumes a Pastor Chat. Owner-scoping is enforced
// server-side — this component only ever sees the caller's own sessions.
import { ref, onMounted, computed } from "vue";
import { api } from "../composables/useApi";

const props = defineProps({
  authed: { type: Boolean, default: false },
  open: { type: Boolean, default: true },
});
const emit = defineEmits(["close"]);

const TYPE_ICON = {
  bible_study: "📖", prayer: "🙏", music: "🎵",
  service: "⛪", pastor: "💬", devotion: "📚", general: "🗒️",
};

const loading = ref(false);
const pinned = ref([]);
const groups = ref({});            // { "Today": [...], ... }
const nextCursor = ref(null);
const query = ref("");
const searching = ref(false);
const searchResults = ref(null);   // null = not searching
const detail = ref(null);          // open transcript
const flash = ref("");
const view = ref("active");        // "active" | "archived" | "deleted"
const selectMode = ref(false);     // multi-select for bulk delete/archive/restore
const selected = ref(new Set());   // chosen session ids

const GROUP_ORDER = ["Today", "Yesterday", "Previous 7 Days", "Previous 30 Days", "Older"];
const orderedGroups = computed(() =>
  GROUP_ORDER.filter((g) => groups.value[g]?.length).map((g) => [g, groups.value[g]])
);

function icon(t) { return TYPE_ICON[t] || "🗒️"; }

async function load(reset = true) {
  if (!props.authed) return;
  loading.value = true;
  try {
    const parts = [];
    if (view.value === "archived") parts.push("archived=true");
    if (view.value === "deleted") parts.push("trashed=true");
    if (!reset && nextCursor.value) parts.push(`cursor=${encodeURIComponent(nextCursor.value)}`);
    const res = await api.history(parts.length ? `?${parts.join("&")}` : "");
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
  // Deleted sessions have no readable transcript (the show endpoint excludes
  // trashed rows); offer to restore instead of opening.
  if (view.value === "deleted") return restoreDeleted(item);
  try {
    const res = await api.historyShow(item.id);
    detail.value = res.session;
  } catch { flash.value = "Could not open session."; }
}

function resume(item) {
  // Pastor chats resume interactively; others open their module page.
  const moodQ = item.mood ? `?mood=${encodeURIComponent(item.mood)}&language=${encodeURIComponent(item.language || "en")}` : "";
  // Bible Study carries the chat-session id; the page resolves bridged (multi-pastor)
  // vs chat-spine transcripts itself, so both kinds restore.
  const routes = {
    pastor: `#pastor?session=${item.id}`,
    bible_study: `#bible-study?session=${item.id}`,
    music: `#worship${moodQ}`,
    service: "#account",
  };
  window.location.hash = routes[item.type || item.session_type] || "#account";
  detail.value = null;
  closeOnPhone();
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
  detail.value = null;
  await load(true);
  flash.value = "Archived.";
}

async function restore(item) {
  await api.historyUpdate(item.id, { archived: false });
  detail.value = null;
  await load(true);
  flash.value = "Restored.";
}

async function restoreDeleted(item) {
  if (!confirm(`Restore "${item.title}"?`)) return;
  await api.historyRestore(item.id);
  await load(true);
  flash.value = "Restored.";
}

function setView(v) {
  view.value = view.value === v ? "active" : v;
  selectMode.value = false;
  selected.value = new Set();
  clearSearch();
  load(true);
}

// ── Multi-select / bulk actions ─────────────────────────────────────────────
const visibleItems = computed(() => {
  if (searchResults.value) return searchResults.value;
  const groupItems = orderedGroups.value.flatMap(([, items]) => items);
  return view.value === "active" ? [...pinned.value, ...groupItems] : groupItems;
});

function toggleSelectMode() {
  selectMode.value = !selectMode.value;
  selected.value = new Set();
}
function toggleSelect(it) {
  const s = new Set(selected.value);
  s.has(it.id) ? s.delete(it.id) : s.add(it.id);
  selected.value = s;
}
function selectAll() {
  const ids = visibleItems.value.map((i) => i.id);
  selected.value = selected.value.size === ids.length ? new Set() : new Set(ids);
}

async function bulkAction(action) {
  const ids = [...selected.value];
  if (!ids.length) return;
  const verb = { delete: "Delete", archive: "Archive", unarchive: "Restore", untrash: "Restore" }[action];
  if (action === "delete" && !confirm(`Delete ${ids.length} session(s)? You can restore them later.`)) return;
  try {
    await api.historyBulk(action, ids);
    flash.value = `${verb}d ${ids.length} session(s).`;
  } catch { flash.value = "Bulk action failed."; }
  selectMode.value = false;
  selected.value = new Set();
  await load(true);
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

// On phones the rail is an overlay drawer; close it after navigating so the
// destination view isn't left hidden behind the backdrop.
function closeOnPhone() {
  if (window.matchMedia("(max-width: 760px)").matches) emit("close");
}

function newPastor() { window.location.hash = "#pastor"; closeOnPhone(); }
function openJourney() { window.location.hash = "#journey"; closeOnPhone(); }

onMounted(() => load(true));
defineExpose({ reload: () => load(true) });
</script>

<template>
  <div v-if="authed && open" class="hr-backdrop" @click="emit('close')"></div>
  <aside v-if="authed" class="history-rail" :class="{ open, closed: !open }">
    <div class="hr-body">
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
      <p v-if="view === 'deleted'" class="hr-dim">Tap a session to restore it.</p>

      <div class="hr-views">
        <button class="hr-toggle" :class="{ on: view === 'archived' }" @click="setView('archived')">
          {{ view === 'archived' ? "← Active" : "🗄 Archived" }}
        </button>
        <button class="hr-toggle" :class="{ on: view === 'deleted' }" @click="setView('deleted')">
          {{ view === 'deleted' ? "← Active" : "🗑 Deleted" }}
        </button>
        <button v-if="!searchResults" class="hr-toggle" :class="{ on: selectMode }" @click="toggleSelectMode">
          {{ selectMode ? "Cancel" : "☑ Select" }}
        </button>
      </div>

      <!-- Bulk action bar (multi-select) -->
      <div v-if="selectMode" class="hr-bulk">
        <button class="hr-mini" @click="selectAll">
          {{ selected.size === visibleItems.length && visibleItems.length ? "Clear" : "All" }}
        </button>
        <span class="hr-bulk-n">{{ selected.size }} selected</span>
        <template v-if="selected.size">
          <button v-if="view === 'active'" class="hr-bulk-btn" @click="bulkAction('archive')">🗄 Archive</button>
          <button v-if="view === 'archived'" class="hr-bulk-btn" @click="bulkAction('unarchive')">♻ Restore</button>
          <button v-if="view === 'deleted'" class="hr-bulk-btn" @click="bulkAction('untrash')">♻ Restore</button>
          <button v-if="view !== 'deleted'" class="hr-bulk-btn danger" @click="bulkAction('delete')">🗑 Delete</button>
        </template>
      </div>

      <!-- Search results -->
      <div v-if="searchResults" class="hr-section">
        <h4>Results ({{ searchResults.length }})</h4>
        <p v-if="searching" class="hr-dim">Searching…</p>
        <p v-else-if="!searchResults.length" class="hr-dim">Nothing found.</p>
        <button v-for="it in searchResults" :key="it.id" class="hr-item" @click="openItem(it)">
          <span class="hr-tt">{{ it.title }}</span>
        </button>
      </div>

      <!-- Normal grouped list -->
      <template v-else>
        <div v-if="pinned.length && view === 'active'" class="hr-section">
          <h4>📌 Pinned</h4>
          <button v-for="it in pinned" :key="it.id" class="hr-item" :class="{ sel: selected.has(it.id) }"
                  @click="selectMode ? toggleSelect(it) : openItem(it)">
            <input v-if="selectMode" type="checkbox" class="hr-cb" :checked="selected.has(it.id)" @click.stop="toggleSelect(it)" />
            <span class="hr-tt">{{ it.title }}</span>
          </button>
        </div>

        <div v-for="[label, items] in orderedGroups" :key="label" class="hr-section">
          <h4>{{ label }}</h4>
          <button v-for="it in items" :key="it.id" class="hr-item" :class="{ sel: selected.has(it.id) }"
                  @click="selectMode ? toggleSelect(it) : openItem(it)">
            <input v-if="selectMode" type="checkbox" class="hr-cb" :checked="selected.has(it.id)" @click.stop="toggleSelect(it)" />
            <span class="hr-tt">{{ it.title }}</span>
            <span v-if="it.favorite" class="hr-star">★</span>
          </button>
        </div>

        <button v-if="nextCursor" class="hr-more" @click="load(false)" :disabled="loading">
          {{ loading ? "Loading…" : "Load more" }}
        </button>
        <p v-else-if="!loading && !pinned.length && !orderedGroups.length" class="hr-dim">
          {{ view === 'archived' ? "No archived sessions."
             : view === 'deleted' ? "No deleted sessions."
             : "No sessions yet. Start a Bible Study, Worship, or Pastor Chat." }}
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
          <button v-if="detail.archived" @click="restore(detail)">♻ Restore</button>
          <button v-else @click="archive(detail)">Archive</button>
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
/* Desktop: the rail is part of the flex row and PUSHES content. Closing it
   collapses its width to zero (content reclaims the space). */
.history-rail { width: 280px; flex: 0 0 280px; border-right: 1px solid var(--border);
  background: var(--surface); color: var(--text); height: 100vh; position: sticky; top: 0; overflow-y: auto;
  transition: flex-basis .2s ease, width .2s ease; }
.history-rail.closed { width: 0; flex-basis: 0; overflow: hidden; border-right: none; }
/* Backdrop only exists for the phone overlay drawer; hidden on desktop. */
.hr-backdrop { display: none; }
.hr-body { padding: 6px 10px 40px; }
.hr-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
.hr-new { width: 100%; padding: 8px; border-radius: 8px; border: 1px solid var(--primary);
  background: var(--primary); color: var(--on-primary); cursor: pointer; margin-bottom: 8px; }
.hr-search { display: flex; gap: 4px; margin-bottom: 8px; }
.hr-search input { flex: 1; padding: 6px 8px; border-radius: 8px; border: 1px solid var(--border);
  background: var(--surface-2); color: var(--text); }
.hr-search input::placeholder { color: var(--text-faint); }
.hr-mini { background: none; border: 1px solid var(--border); border-radius: 6px; cursor: pointer; padding: 2px 7px; color: var(--text); }
.hr-views { display: flex; gap: 4px; margin-bottom: 8px; }
.hr-toggle { flex: 1; padding: 5px; border: 1px solid var(--border);
  border-radius: 8px; background: none; cursor: pointer; color: var(--text); font-size: 12px; }
.hr-toggle:hover, .hr-toggle.on { background: var(--surface-3); }
.hr-section { margin-bottom: 12px; }
.hr-section h4 { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; opacity: .6; margin: 8px 0 4px; }
.hr-item { display: flex; align-items: center; gap: 6px; width: 100%; text-align: left; padding: 7px 8px;
  border: none; background: none; border-radius: 8px; cursor: pointer; font-size: 13px; color: var(--text); }
.hr-item:hover { background: var(--surface-3); }
.hr-item.sel { background: var(--primary-soft); }
.hr-cb { flex: 0 0 auto; margin: 0; cursor: pointer; }
.hr-bulk { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-bottom: 8px;
  padding: 6px 8px; background: var(--surface-2); border: 1px solid var(--border); border-radius: 8px; }
.hr-bulk-n { font-size: 12px; color: var(--text-muted); margin-right: auto; }
.hr-bulk-btn { padding: 4px 8px; border: 1px solid var(--border); border-radius: 6px;
  background: var(--surface); cursor: pointer; font-size: 12px; color: var(--text); }
.hr-bulk-btn.danger { color: #dc2626; border-color: #dc2626; }
.hr-ic { flex: 0 0 auto; }
.hr-tt { flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.hr-star { color: #eab308; }
.hr-more { width: 100%; padding: 6px; margin-top: 4px; border: 1px dashed var(--border);
  border-radius: 8px; background: none; cursor: pointer; color: var(--text); }
.hr-dim { opacity: .55; font-size: 12px; padding: 4px 8px; }
.hr-flash { font-size: 12px; background: #fef9c3; border-radius: 6px; padding: 5px 8px; cursor: pointer; }
.hr-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.4); display: flex; justify-content: center;
  align-items: center; z-index: 1000; }
.hr-panel { background: var(--surface); color: var(--text); width: min(680px, 92vw); max-height: 86vh; overflow-y: auto;
  border-radius: 14px; padding: 16px; }
.hr-panel header { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
.hr-panel header strong { flex: 1; }
.hr-actions { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; }
.hr-actions button { padding: 4px 9px; border-radius: 7px; border: 1px solid var(--border);
  background: none; cursor: pointer; font-size: 12px; color: var(--text); }
.hr-actions .danger { color: var(--danger); border-color: var(--danger); }
.hr-summary { font-style: italic; opacity: .8; border-left: 3px solid var(--primary); padding-left: 8px; }
.hr-tags { display: flex; flex-wrap: wrap; gap: 5px; margin: 6px 0; }
.hr-tag { font-size: 11px; background: var(--surface-3); border-radius: 999px; padding: 1px 8px; }
.hr-transcript { margin-top: 10px; }
.hr-msg { margin: 6px 0; line-height: 1.4; }
.hr-msg.user { text-align: right; }
@media (max-width: 760px) {
  /* Phone: off-canvas drawer that slides in from the left OVER the content,
     with a dimming backdrop. */
  .history-rail { position: fixed; top: 0; left: 0; bottom: auto; height: 100vh; width: 280px;
    flex-basis: 280px; z-index: 950; border-right: 1px solid var(--border);
    transform: translateX(-100%); transition: transform .25s ease; }
  .history-rail.open { transform: translateX(0); }
  .history-rail.closed { transform: translateX(-100%); width: 280px; flex-basis: 280px; }
  .hr-backdrop { display: block; position: fixed; inset: 0; z-index: 940;
    background: rgba(0,0,0,.45); }
}
</style>
