<script setup>
/**
 * Admin catalog manager for the AI Worship Radio: CRUD over worship_tracks plus
 * the playlist-size settings. Backed by the `music.manage`-gated admin API.
 * Tag fields (themes / moods / scriptures) are entered as comma-separated text
 * and serialized to arrays on save. Streaming URLs must be http(s) — the server
 * enforces this too.
 */
import { ref, onMounted } from "vue";
import { api } from "../composables/useApi";

const LANGUAGES = [
  { code: "en", label: "English" },
  { code: "my", label: "Burmese" },
  { code: "td", label: "Zolai" },
  { code: "fr", label: "French" },
  { code: "de", label: "German" },
  { code: "es", label: "Spanish" },
  { code: "ja", label: "Japanese" },
  { code: "zh-CN", label: "Chinese (Simplified)" },
  { code: "ko", label: "Korean" },
  { code: "hi", label: "Hindi" },
  { code: "ta", label: "Tamil" },
  { code: "th", label: "Thai" },
];

const tracks = ref([]);
const loading = ref(false);
const error = ref("");
const ok = ref("");
const filterLang = ref("");
const search = ref("");

const blank = () => ({
  id: null, title: "", artist: "", language: "en", genre: "",
  themes: "", moods: "", scriptures: "", duration: "",
  youtube_url: "", spotify_url: "", apple_music_url: "", cover_image: "",
  lyrics_available: false, copyright_status: "curated", popularity: 0, active: true,
});
const form = ref(blank());
const editing = ref(false);

const settings = ref({ min_playlist: 5, max_playlist: 10, mood_dictionary: "" });

// YouTube search (content-filtered, reuses the sermon blocklist).
const yt = ref({ open: false, query: "", loading: false, results: [], error: "" });

onMounted(() => { load(); loadSettings(); });

async function load() {
  loading.value = true; error.value = "";
  try {
    const params = [];
    if (filterLang.value) params.push(`language=${filterLang.value}`);
    if (search.value.trim()) params.push(`search=${encodeURIComponent(search.value.trim())}`);
    const res = await api.worshipTracks(params.length ? `?${params.join("&")}` : "");
    tracks.value = res.tracks || [];
  } catch { error.value = "Could not load tracks."; }
  finally { loading.value = false; }
}

async function loadSettings() {
  try {
    const s = await api.musicSettings();
    settings.value = {
      min_playlist: s.min_playlist ?? 5,
      max_playlist: s.max_playlist ?? 10,
      mood_dictionary: s.mood_dictionary ?? "",
    };
  } catch { /* non-fatal */ }
}

function toTags(s) {
  return String(s || "").split(",").map((t) => t.trim()).filter(Boolean);
}
function fromTags(arr) {
  return Array.isArray(arr) ? arr.join(", ") : "";
}

function startNew() { form.value = blank(); editing.value = true; ok.value = ""; error.value = ""; }
function startEdit(t) {
  form.value = {
    ...t,
    themes: fromTags(t.themes), moods: fromTags(t.moods), scriptures: fromTags(t.scriptures),
    duration: t.duration ?? "",
  };
  editing.value = true; ok.value = ""; error.value = "";
}
function cancel() { editing.value = false; form.value = blank(); }

async function save() {
  error.value = ""; ok.value = "";
  if (!form.value.title.trim()) { error.value = "Title is required."; return; }
  const payload = {
    title: form.value.title.trim(),
    artist: form.value.artist?.trim() || null,
    language: form.value.language,
    genre: form.value.genre?.trim() || null,
    themes: toTags(form.value.themes),
    moods: toTags(form.value.moods),
    scriptures: toTags(form.value.scriptures),
    duration: form.value.duration === "" ? null : Number(form.value.duration),
    youtube_url: form.value.youtube_url?.trim() || null,
    spotify_url: form.value.spotify_url?.trim() || null,
    apple_music_url: form.value.apple_music_url?.trim() || null,
    cover_image: form.value.cover_image?.trim() || null,
    lyrics_available: !!form.value.lyrics_available,
    copyright_status: form.value.copyright_status?.trim() || "curated",
    popularity: Number(form.value.popularity) || 0,
    active: !!form.value.active,
  };
  try {
    if (form.value.id) await api.worshipTrackUpdate(form.value.id, payload);
    else await api.worshipTrackCreate(payload);
    ok.value = "Saved.";
    editing.value = false; form.value = blank();
    await load();
  } catch (e) {
    error.value = e?.message || "Save failed (check the URLs are http/https).";
  }
}

async function ytSearch() {
  const q = (yt.value.query || `${form.value.title} ${form.value.artist}`).trim();
  if (!q) { yt.value.error = "Enter a search term."; return; }
  yt.value.loading = true; yt.value.error = ""; yt.value.results = [];
  try {
    const res = await api.worshipYoutubeSearch(q);
    yt.value.results = res.results || [];
    if (!yt.value.results.length) yt.value.error = "No clean results — try another search.";
  } catch (e) {
    yt.value.error = e?.message || "YouTube search unavailable (is YOUTUBE_API_KEY set?).";
  } finally {
    yt.value.loading = false;
  }
}
function ytAttach(r) {
  form.value.youtube_url = r.url;
  if (!form.value.cover_image && r.thumbnail) form.value.cover_image = r.thumbnail;
  yt.value.open = false;
}
function ytOpen() {
  yt.value.open = true; yt.value.error = ""; yt.value.results = [];
  yt.value.query = `${form.value.title} ${form.value.artist}`.trim();
}

async function remove(t) {
  if (!confirm(`Delete “${t.title}”?`)) return;
  try { await api.worshipTrackDelete(t.id); await load(); }
  catch { error.value = "Delete failed."; }
}

const importInput = ref(null);

// Download the catalog (respecting the language filter) as a portable JSON file.
async function exportJson() {
  error.value = ""; ok.value = "";
  try {
    const data = await api.worshipTracksExport(filterLang.value ? `?language=${filterLang.value}` : "");
    const blob = new Blob([JSON.stringify(data, null, 2)], { type: "application/json" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = `worship-tracks${filterLang.value ? "-" + filterLang.value : ""}.json`;
    a.click();
    URL.revokeObjectURL(url);
  } catch { error.value = "Export failed."; }
}

// Parse a chosen JSON file and bulk-import; the server validates + de-dupes.
async function importJson(ev) {
  const file = ev.target.files?.[0];
  ev.target.value = ""; // allow re-importing the same file
  if (!file) return;
  error.value = ""; ok.value = "";
  try {
    const parsed = JSON.parse(await file.text());
    const list = Array.isArray(parsed) ? parsed : parsed.tracks;
    if (!Array.isArray(list) || !list.length) {
      error.value = 'JSON must contain a non-empty "tracks" array.';
      return;
    }
    const res = await api.worshipTracksImport({ tracks: list });
    ok.value = `Imported ${res.imported}, skipped ${res.skipped} duplicate(s)` +
      (res.errors?.length ? `, ${res.errors.length} row(s) rejected.` : ".");
    if (res.errors?.length) error.value = res.errors.slice(0, 5).join(" • ");
    await load();
  } catch (e) {
    error.value = e?.message || "Import failed — check the file is valid JSON.";
  }
}

async function saveSettings() {
  error.value = ""; ok.value = "";
  try {
    await api.musicSettingsSave({
      min_playlist: Number(settings.value.min_playlist),
      max_playlist: Number(settings.value.max_playlist),
      mood_dictionary: settings.value.mood_dictionary || "",
    });
    ok.value = "Settings saved.";
  } catch (e) { error.value = e?.message || "Could not save settings."; }
}
</script>

<template>
  <div class="mcm">
    <h2>🎶 Worship Radio Catalog</h2>
    <p class="hint">
      Metadata-only catalog for mood-based recommendations. Store official streaming
      links (YouTube / Spotify / Apple) — never hosted audio. Themes/moods drive the
      matcher; comma-separate them.
    </p>

    <p v-if="error" class="msg err">{{ error }}</p>
    <p v-if="ok" class="msg ok">{{ ok }}</p>

    <!-- Playlist settings -->
    <fieldset class="settings-box">
      <legend>Playlist settings</legend>
      <label>Min songs <input type="number" min="1" max="50" v-model="settings.min_playlist" /></label>
      <label>Max songs <input type="number" min="1" max="50" v-model="settings.max_playlist" /></label>
      <label class="dict">Mood dictionary override (JSON, optional)
        <textarea v-model="settings.mood_dictionary" rows="3"
          placeholder='{"anxiety":["peace","trust"]}'></textarea>
      </label>
      <button class="btn" @click="saveSettings">Save settings</button>
    </fieldset>

    <!-- Toolbar -->
    <div class="toolbar">
      <select v-model="filterLang" @change="load">
        <option value="">All languages</option>
        <option v-for="l in LANGUAGES" :key="l.code" :value="l.code">{{ l.label }}</option>
      </select>
      <input v-model="search" placeholder="Search title/artist" @keyup.enter="load" />
      <button class="btn" @click="load">Search</button>
      <button class="btn primary" @click="startNew">+ Add track</button>
      <button class="btn" @click="importInput?.click()">📥 Import JSON</button>
      <button class="btn" @click="exportJson">📤 Export JSON</button>
      <input ref="importInput" type="file" accept="application/json,.json" hidden @change="importJson" />
    </div>

    <!-- Editor -->
    <form v-if="editing" class="editor" @submit.prevent="save">
      <div class="row">
        <label>Title* <input v-model="form.title" required maxlength="255" /></label>
        <label>Artist <input v-model="form.artist" maxlength="255" /></label>
        <label>Language
          <select v-model="form.language">
            <option v-for="l in LANGUAGES" :key="l.code" :value="l.code">{{ l.label }}</option>
          </select>
        </label>
      </div>
      <div class="row">
        <label>Genre <input v-model="form.genre" maxlength="100" /></label>
        <label>Duration (sec) <input type="number" min="0" v-model="form.duration" /></label>
        <label>Popularity <input type="number" min="0" v-model="form.popularity" /></label>
      </div>
      <div class="row">
        <label>Themes <input v-model="form.themes" placeholder="peace, trust, hope" /></label>
        <label>Moods <input v-model="form.moods" placeholder="anxiety, peace" /></label>
        <label>Scriptures <input v-model="form.scriptures" placeholder="Isaiah 26:3" /></label>
      </div>
      <div class="row">
        <label>YouTube URL
          <span class="yt-field">
            <input v-model="form.youtube_url" type="url" />
            <button class="btn" type="button" @click="ytOpen">🔎 Find</button>
          </span>
        </label>
        <label>Spotify URL <input v-model="form.spotify_url" type="url" /></label>
      </div>

      <!-- Content-filtered YouTube search (reuses the sermon blocklist). -->
      <div v-if="yt.open" class="yt-search">
        <div class="yt-bar">
          <input v-model="yt.query" placeholder="Search worship songs on YouTube"
            @keyup.enter.prevent="ytSearch" />
          <button class="btn primary" type="button" :disabled="yt.loading" @click="ytSearch">
            {{ yt.loading ? "Searching…" : "Search" }}
          </button>
          <button class="btn" type="button" @click="yt.open = false">Close</button>
        </div>
        <p v-if="yt.error" class="msg err">{{ yt.error }}</p>
        <p class="hint">Results are screened through the same content filter sermons use.</p>
        <ul class="yt-results">
          <li v-for="r in yt.results" :key="r.video_id">
            <img v-if="r.thumbnail" :src="r.thumbnail" alt="" />
            <span class="yt-meta"><strong>{{ r.title }}</strong><small>{{ r.channel }}</small></span>
            <button class="btn primary" type="button" @click="ytAttach(r)">Use</button>
          </li>
        </ul>
      </div>
      <div class="row">
        <label>Apple Music URL <input v-model="form.apple_music_url" type="url" /></label>
        <label>Cover image URL <input v-model="form.cover_image" type="url" /></label>
      </div>
      <div class="row checks">
        <label class="chk"><input type="checkbox" v-model="form.lyrics_available" /> Lyrics available</label>
        <label class="chk"><input type="checkbox" v-model="form.active" /> Active</label>
        <label>License / Copyright <input v-model="form.copyright_status" maxlength="60" placeholder="e.g. CCLI, Public Domain, YouTube Official" /></label>
      </div>
      <div class="actions">
        <button class="btn primary" type="submit">Save</button>
        <button class="btn" type="button" @click="cancel">Cancel</button>
      </div>
    </form>

    <!-- Table -->
    <table v-if="!loading" class="grid">
      <thead><tr><th>Title</th><th>Artist</th><th>Lang</th><th>Moods</th><th>Pop.</th><th>Active</th><th></th></tr></thead>
      <tbody>
        <tr v-for="t in tracks" :key="t.id">
          <td>{{ t.title }}</td>
          <td>{{ t.artist || "—" }}</td>
          <td>{{ t.language }}</td>
          <td class="tags">{{ (t.moods || []).join(", ") || "—" }}</td>
          <td>{{ t.popularity }}</td>
          <td>{{ t.active ? "✓" : "—" }}</td>
          <td class="rowact">
            <button class="link" @click="startEdit(t)">Edit</button>
            <button class="link danger" @click="remove(t)">Delete</button>
          </td>
        </tr>
        <tr v-if="!tracks.length"><td colspan="7" class="empty">No tracks yet.</td></tr>
      </tbody>
    </table>
    <p v-else class="hint">Loading…</p>
  </div>
</template>

<style scoped>
.mcm { color: var(--text); }
.hint { color: var(--text-muted); font-size: .9rem; }
.msg { padding: .5rem .8rem; border-radius: var(--radius-sm); }
.msg.err { background: var(--danger-soft); color: var(--danger); }
.msg.ok { background: var(--success-soft); color: var(--success); }

.settings-box { border: 1px solid var(--border); border-radius: var(--radius); padding: 1rem; margin: 1rem 0;
  display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-end; }
.settings-box label { display: flex; flex-direction: column; gap: .25rem; font-size: .85rem; }
.settings-box input { width: 6rem; }
.settings-box .dict { flex: 1; min-width: 240px; }
.settings-box .dict textarea { width: 100%; }

.toolbar { display: flex; gap: .5rem; margin: 1rem 0; flex-wrap: wrap; }
.toolbar input { flex: 1; min-width: 160px; }

.editor { border: 1px solid var(--primary); border-radius: var(--radius); padding: 1rem; margin-bottom: 1rem;
  display: flex; flex-direction: column; gap: .75rem; background: var(--primary-soft); }
.editor .row { display: flex; gap: .75rem; flex-wrap: wrap; }
.editor label { display: flex; flex-direction: column; gap: .25rem; flex: 1; min-width: 160px; font-size: .85rem; }
.editor .checks .chk { flex-direction: row; align-items: center; gap: .4rem; }
.actions { display: flex; gap: .5rem; }

input, select, textarea { padding: .45rem .6rem; border: 1px solid var(--border); border-radius: var(--radius-sm);
  background: var(--surface); color: var(--text); font: inherit; }
.btn { padding: .45rem .9rem; border: 1px solid var(--border); border-radius: var(--radius-sm);
  background: var(--surface); color: var(--text); cursor: pointer; }
.btn.primary { background: var(--primary); color: var(--on-primary); border-color: var(--primary); }

.yt-field { display: flex; gap: .4rem; }
.yt-field input { flex: 1; }
.yt-search { border: 1px solid var(--primary); border-radius: var(--radius); padding: .8rem; background: var(--surface); }
.yt-bar { display: flex; gap: .5rem; }
.yt-bar input { flex: 1; }
.yt-results { list-style: none; margin: .5rem 0 0; padding: 0; display: flex; flex-direction: column; gap: .4rem; }
.yt-results li { display: flex; align-items: center; gap: .6rem; padding: .4rem; border: 1px solid var(--border);
  border-radius: var(--radius-sm); }
.yt-results img { width: 80px; height: 45px; object-fit: cover; border-radius: var(--radius-sm); }
.yt-meta { flex: 1; display: flex; flex-direction: column; min-width: 0; }
.yt-meta strong { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.yt-meta small { color: var(--text-muted); }

.grid { width: 100%; border-collapse: collapse; }
.grid th, .grid td { text-align: left; padding: .5rem .6rem; border-bottom: 1px solid var(--border); font-size: .9rem; }
.grid .tags { color: var(--text-muted); }
.empty { text-align: center; color: var(--text-muted); }
.rowact { white-space: nowrap; }
.link { background: none; border: none; color: var(--primary); cursor: pointer; padding: 0 .4rem; }
.link.danger { color: var(--danger); }
</style>
