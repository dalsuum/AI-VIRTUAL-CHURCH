<script setup>
import { ref, computed, onMounted } from "vue";
import { api } from "../composables/useApi.js";

// Whether this admin may write (special_sundays.manage). Read-only viewers still
// get the full monitor; the controls are hidden/disabled for them.
const props = defineProps({
  canManage: { type: Boolean, default: false },
});

// ── State ──────────────────────────────────────────────────────────────────
const loading     = ref(false);
const busy        = ref(false);
const notice      = ref("");
const error       = ref("");
const current     = ref(null);
const observances = ref([]);
const calendar    = ref([]);
const audit       = ref([]);
const ruleTypes   = ref(["nth_weekday", "easter_offset", "fixed"]);

const view    = ref("list");      // 'list' | 'edit' | 'content'
const editing = ref(null);        // the row id being edited, or null for a new one
const form    = ref(blankForm());

// Content management (manual sermons/songs + per-language modes)
const contentRow  = ref(null);    // the observance whose content we're managing
const sermonForm  = ref(null);    // editing/creating a sermon, or null
const songForm    = ref(null);    // editing/creating a song, or null

const LANGS = ["en", "my", "td"];
const LANG_LABEL = { en: "English", my: "မြန်မာ", td: "Zolai" };
const SONG_TYPES = [
  { value: "youtube", label: "YouTube link", hint: "Video id or URL" },
  { value: "hymn",    label: "Hymn library", hint: "Song id from your library" },
  { value: "audio",   label: "Hosted audio", hint: "Direct .mp3 URL" },
  { value: "suno",    label: "Suno prompt",  hint: "Composition prompt" },
];

const WEEKDAYS = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];

function blankForm() {
  return {
    key: "",
    rule_type: "nth_weekday",
    rule: { month: 5, weekday: 0, nth: 2, offset: 0, day: 1 },
    titles: { en: "", my: "", td: "" },
    briefs: { en: "", my: "", td: "" },
    sermon_tags: "",
    music_moods: "",
    region: "",
    priority: 50,
    active: true,
  };
}

async function load() {
  loading.value = true;
  error.value = "";
  try {
    const r = await api.adminSpecialSundays();
    current.value     = r.current;
    observances.value = r.observances || [];
    calendar.value    = r.calendar || [];
    audit.value       = r.audit || [];
    ruleTypes.value   = r.rule_types || ruleTypes.value;
  } catch (e) {
    error.value = e?.message || "Failed to load special Sundays.";
  } finally {
    loading.value = false;
  }
}
onMounted(load);

function flash(msg) {
  notice.value = msg;
  setTimeout(() => { if (notice.value === msg) notice.value = ""; }, 3500);
}

// ── Calendar grouping (upcoming first) ──────────────────────────────────────
const upcoming = computed(() => calendar.value.filter((c) => !c.is_past));
const past     = computed(() => calendar.value.filter((c) => c.is_past));

function fmtDate(d) {
  try {
    return new Date(d + "T00:00:00").toLocaleDateString(undefined, {
      weekday: "short", year: "numeric", month: "short", day: "numeric",
    });
  } catch { return d; }
}

function ruleSummary(o) {
  const r = o.rule || {};
  if (o.rule_type === "nth_weekday") {
    const nth = r.nth < 0 ? "last" : ["1st", "2nd", "3rd", "4th", "5th"][r.nth - 1] || `${r.nth}th`;
    const month = new Date(2000, (r.month || 1) - 1, 1).toLocaleDateString(undefined, { month: "long" });
    return `${nth} ${WEEKDAYS[r.weekday] || "?"} of ${month}`;
  }
  if (o.rule_type === "easter_offset") {
    const n = Number(r.offset || 0);
    return n === 0 ? "Easter Sunday" : `Easter ${n > 0 ? "+" : "−"}${Math.abs(n)} days`;
  }
  if (o.rule_type === "fixed") {
    const month = new Date(2000, (r.month || 1) - 1, 1).toLocaleDateString(undefined, { month: "long" });
    return `${month} ${r.day} (→ nearest Sunday)`;
  }
  return o.rule_type;
}

// ── Controls ────────────────────────────────────────────────────────────────
function startCreate() {
  editing.value = null;
  form.value = blankForm();
  view.value = "edit";
}

function startEdit(o) {
  editing.value = o.id;
  form.value = {
    key: o.key,
    rule_type: o.rule_type,
    rule: { month: 1, weekday: 0, nth: 1, offset: 0, day: 1, ...(o.rule || {}) },
    titles: { en: "", my: "", td: "", ...(o.titles || {}) },
    briefs: { en: "", my: "", td: "", ...(o.briefs || {}) },
    sermon_tags: (o.sermon_tags || []).join(", "),
    music_moods: (o.music_moods || []).join(", "),
    region: o.region || "",
    priority: o.priority,
    active: o.active,
  };
  view.value = "edit";
}

function cancelEdit() {
  view.value = "list";
  editing.value = null;
  error.value = "";
}

// Only send the rule keys the selected rule_type actually uses.
function ruleForType(t, r) {
  if (t === "nth_weekday")   return { month: +r.month, weekday: +r.weekday, nth: +r.nth };
  if (t === "easter_offset") return { offset: +r.offset };
  if (t === "fixed")         return { month: +r.month, day: +r.day };
  return {};
}

function csvToList(s) {
  return String(s || "").split(",").map((x) => x.trim()).filter(Boolean);
}

async function save() {
  if (!props.canManage) return;
  busy.value = true;
  error.value = "";
  const payload = {
    key: form.value.key.trim(),
    rule_type: form.value.rule_type,
    rule: ruleForType(form.value.rule_type, form.value.rule),
    titles: { ...form.value.titles },
    briefs: { ...form.value.briefs },
    sermon_tags: csvToList(form.value.sermon_tags),
    music_moods: csvToList(form.value.music_moods),
    region: form.value.region.trim() || null,
    priority: +form.value.priority,
    active: !!form.value.active,
  };
  try {
    if (editing.value) {
      await api.adminUpdateSpecialSunday(editing.value, payload);
      flash("Observance updated.");
    } else {
      await api.adminCreateSpecialSunday(payload);
      flash("Observance added.");
    }
    await load();
    view.value = "list";
    editing.value = null;
  } catch (e) {
    error.value = e?.message || "Save failed — check the rule values and required text.";
  } finally {
    busy.value = false;
  }
}

async function toggleActive(o) {
  if (!props.canManage || busy.value) return;
  busy.value = true;
  try {
    await api.adminUpdateSpecialSunday(o.id, { active: !o.active });
    o.active = !o.active;
    flash(`${o.titles?.en || o.key} ${o.active ? "enabled" : "disabled"}.`);
    await load();
  } catch (e) {
    error.value = e?.message || "Could not change status.";
  } finally {
    busy.value = false;
  }
}

async function remove(o) {
  if (!props.canManage) return;
  if (!confirm(`Delete "${o.titles?.en || o.key}"? It will be re-created next time the seeder runs if it still exists in config.`)) return;
  busy.value = true;
  try {
    await api.adminDeleteSpecialSunday(o.id);
    flash("Observance deleted.");
    await load();
  } catch (e) {
    error.value = e?.message || "Delete failed.";
  } finally {
    busy.value = false;
  }
}

// ── Content management (manual sermons / songs + per-language modes) ─────────
function openContent(o) {
  contentRow.value = observances.value.find((x) => x.id === o.id) || o;
  sermonForm.value = null;
  songForm.value = null;
  preview.value = null;
  view.value = "content";
}
function closeContent() {
  view.value = "list";
  contentRow.value = null;
}

function modeOf(segment, lang) {
  return contentRow.value?.content_modes?.[segment]?.[lang] || "auto";
}
async function setMode(segment, lang, mode) {
  if (!props.canManage || busy.value) return;
  const o = contentRow.value;
  const modes = JSON.parse(JSON.stringify(o.content_modes || {}));
  modes[segment] = modes[segment] || {};
  modes[segment][lang] = mode;
  busy.value = true;
  try {
    await api.adminUpdateSpecialSunday(o.id, { content_modes: modes });
    o.content_modes = modes;
    flash(`${segment} (${lang}) → ${mode}.`);
  } catch (e) {
    error.value = e?.message || "Could not change mode.";
  } finally {
    busy.value = false;
  }
}

const sermonsFor = (lang) => (contentRow.value?.sermons || []).filter((s) => s.language === lang);
const songsFor   = (lang) => (contentRow.value?.songs   || []).filter((s) => s.language === lang);

// Sermons
function newSermon(lang) {
  sermonForm.value = { id: null, language: lang, title: "", body: "", mood: "", region: "", priority: 50, active: true };
}
function editSermon(s) { sermonForm.value = { ...s, mood: s.mood || "", region: s.region || "" }; }
async function saveSermon() {
  const f = sermonForm.value, o = contentRow.value;
  const payload = { language: f.language, title: f.title, body: f.body, mood: f.mood || null, region: f.region || null, priority: +f.priority, active: !!f.active };
  busy.value = true; error.value = "";
  try {
    if (f.id) await api.adminUpdateSpecialSermon(f.id, payload);
    else      await api.adminCreateSpecialSermon(o.id, payload);
    flash("Sermon saved."); sermonForm.value = null; await reloadKeepingContent(o.id);
  } catch (e) { error.value = e?.message || "Save failed."; } finally { busy.value = false; }
}
async function deleteSermon(s) {
  if (!confirm(`Delete sermon "${s.title}"?`)) return;
  busy.value = true;
  try { await api.adminDeleteSpecialSermon(s.id); flash("Sermon deleted."); await reloadKeepingContent(contentRow.value.id); }
  catch (e) { error.value = e?.message || "Delete failed."; } finally { busy.value = false; }
}

// Songs
function newSong(lang) {
  songForm.value = { id: null, language: lang, title: "", source_type: "youtube", source_ref: "", lyrics: "", mood: "", region: "", priority: 50, active: true };
}
function editSong(s) { songForm.value = { ...s, lyrics: s.lyrics || "", mood: s.mood || "", region: s.region || "" }; }
async function saveSong() {
  const f = songForm.value, o = contentRow.value;
  const payload = { language: f.language, title: f.title, source_type: f.source_type, source_ref: f.source_ref, lyrics: f.lyrics || null, mood: f.mood || null, region: f.region || null, priority: +f.priority, active: !!f.active };
  busy.value = true; error.value = "";
  try {
    if (f.id) await api.adminUpdateSpecialSong(f.id, payload);
    else      await api.adminCreateSpecialSong(o.id, payload);
    flash("Song saved."); songForm.value = null; await reloadKeepingContent(o.id);
  } catch (e) { error.value = e?.message || "Save failed — check the source value."; } finally { busy.value = false; }
}
async function deleteSong(s) {
  if (!confirm(`Delete song "${s.title}"?`)) return;
  busy.value = true;
  try { await api.adminDeleteSpecialSong(s.id); flash("Song deleted."); await reloadKeepingContent(contentRow.value.id); }
  catch (e) { error.value = e?.message || "Delete failed."; } finally { busy.value = false; }
}

// Preview — resolve what would actually play for a language + mood.
const previewLang = ref("en");
const previewMood = ref("");
const preview     = ref(null);
const previewBusy = ref(false);
async function runPreview() {
  if (!contentRow.value) return;
  previewBusy.value = true;
  preview.value = null;
  try {
    preview.value = await api.adminPreviewSpecialSunday(contentRow.value.id, previewLang.value, previewMood.value.trim());
  } catch (e) {
    error.value = e?.message || "Preview failed.";
  } finally {
    previewBusy.value = false;
  }
}

// Reload the catalog but stay on the content panel for the same observance.
async function reloadKeepingContent(id) {
  await load();
  contentRow.value = observances.value.find((x) => x.id === id) || null;
  if (!contentRow.value) view.value = "list";
}
</script>

<template>
  <div class="ss">
    <header class="ss-head">
      <div>
        <h2>Special Sundays</h2>
        <p class="ss-sub">
          Observances auto-bias the sermon &amp; worship during their
          Fri&nbsp;00:00&nbsp;→&nbsp;Sun&nbsp;23:59 window and show a highlight card on
          the intake screen. Everything below is seeded automatically; edit, disable,
          or add your own as needed.
        </p>
      </div>
      <button v-if="canManage && view === 'list'" class="btn primary" @click="startCreate">+ Add observance</button>
    </header>

    <p v-if="notice" class="ss-notice ok">{{ notice }}</p>
    <p v-if="error" class="ss-notice err">{{ error }}</p>
    <p v-if="loading" class="ss-muted">Loading…</p>

    <!-- ───────────────────────── MONITOR + LIST ───────────────────────── -->
    <template v-if="view === 'list' && !loading">
      <!-- Active-now banner -->
      <div class="ss-current" :class="{ none: !current }">
        <template v-if="current">
          <strong>● Active now:</strong> {{ current.title }}
          <span class="ss-muted">(Sunday {{ fmtDate(current.date) }}) — biasing live services</span>
        </template>
        <template v-else>
          <strong>○ No observance active right now.</strong>
          <span class="ss-muted">Selection runs normally until the next window opens.</span>
        </template>
      </div>

      <!-- Upcoming calendar -->
      <section class="ss-section">
        <h3>Calendar — upcoming</h3>
        <table class="ss-grid">
          <thead><tr><th>Date</th><th>Observance</th><th>Priority</th></tr></thead>
          <tbody>
            <tr v-for="c in upcoming" :key="c.key + c.date">
              <td>{{ fmtDate(c.date) }}</td>
              <td>{{ c.title }}</td>
              <td>{{ c.priority }}</td>
            </tr>
            <tr v-if="!upcoming.length"><td colspan="3" class="ss-muted">Nothing scheduled in range.</td></tr>
          </tbody>
        </table>
      </section>

      <!-- Catalog + controls -->
      <section class="ss-section">
        <h3>Catalog</h3>
        <table class="ss-grid">
          <thead>
            <tr>
              <th>Title (EN)</th><th>Rule</th><th>Next</th>
              <th>Tags / Moods</th><th>Prio</th><th>Status</th>
              <th v-if="canManage"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="o in observances" :key="o.id" :class="{ off: !o.active }">
              <td>
                <strong>{{ o.titles?.en || o.key }}</strong>
                <div class="ss-key">{{ o.key }}</div>
              </td>
              <td>{{ ruleSummary(o) }}</td>
              <td>{{ o.next_dates?.[0] ? fmtDate(o.next_dates[0]) : "—" }}</td>
              <td class="ss-tags">
                <span class="ss-chip sermon" v-for="t in o.sermon_tags" :key="'s'+t">{{ t }}</span>
                <span class="ss-chip mood" v-for="m in o.music_moods" :key="'m'+m">{{ m }}</span>
              </td>
              <td>{{ o.priority }}</td>
              <td>
                <button
                  class="ss-toggle"
                  :class="{ on: o.active }"
                  :disabled="!canManage || busy"
                  @click="toggleActive(o)"
                >{{ o.active ? "On" : "Off" }}</button>
              </td>
              <td v-if="canManage" class="ss-actions">
                <button class="btn" @click="openContent(o)">Content</button>
                <button class="btn" @click="startEdit(o)">Edit</button>
                <button class="btn danger" @click="remove(o)">Delete</button>
              </td>
            </tr>
          </tbody>
        </table>
      </section>

      <!-- Bias audit -->
      <section class="ss-section">
        <h3>Bias audit — recent services in a window</h3>
        <table class="ss-grid">
          <thead><tr><th>When</th><th>Service</th><th>Lang</th><th>Mood</th><th>Observance</th></tr></thead>
          <tbody>
            <tr v-for="a in audit" :key="a.session_id + a.created_at">
              <td>{{ a.created_at }}</td>
              <td>#{{ a.session_id }} <span class="ss-muted">{{ a.status }}</span></td>
              <td>{{ a.language }}</td>
              <td>{{ a.mood || "—" }}</td>
              <td>{{ a.title }}</td>
            </tr>
            <tr v-if="!audit.length"><td colspan="5" class="ss-muted">No services generated inside a window yet.</td></tr>
          </tbody>
        </table>
      </section>
    </template>

    <!-- ───────────────────────── EDIT / CREATE ───────────────────────── -->
    <form v-else-if="view === 'edit'" class="ss-form" @submit.prevent="save">
      <h3>{{ editing ? "Edit observance" : "New observance" }}</h3>

      <label>Key <small>(lowercase, a–z 0–9 _ ; stable identifier)</small></label>
      <input v-model="form.key" :disabled="!!editing" placeholder="e.g. harvest_sunday" required />

      <label>Date rule</label>
      <select v-model="form.rule_type">
        <option v-for="t in ruleTypes" :key="t" :value="t">{{ t }}</option>
      </select>

      <!-- Rule fields per type -->
      <div v-if="form.rule_type === 'nth_weekday'" class="ss-rule-row">
        <span>The
          <select v-model.number="form.rule.nth">
            <option :value="1">1st</option><option :value="2">2nd</option>
            <option :value="3">3rd</option><option :value="4">4th</option>
            <option :value="5">5th</option><option :value="-1">last</option>
          </select>
          <select v-model.number="form.rule.weekday">
            <option v-for="(d, i) in WEEKDAYS" :key="d" :value="i">{{ d }}</option>
          </select>
          of
          <select v-model.number="form.rule.month">
            <option v-for="m in 12" :key="m" :value="m">{{ new Date(2000, m-1, 1).toLocaleDateString(undefined,{month:'long'}) }}</option>
          </select>
        </span>
      </div>
      <div v-else-if="form.rule_type === 'easter_offset'" class="ss-rule-row">
        <span>Easter Sunday +
          <input type="number" v-model.number="form.rule.offset" style="width:6rem" /> days
          <small>(Palm = −7, Pentecost = +49)</small>
        </span>
      </div>
      <div v-else-if="form.rule_type === 'fixed'" class="ss-rule-row">
        <span>
          <select v-model.number="form.rule.month">
            <option v-for="m in 12" :key="m" :value="m">{{ new Date(2000, m-1, 1).toLocaleDateString(undefined,{month:'long'}) }}</option>
          </select>
          <input type="number" min="1" max="31" v-model.number="form.rule.day" style="width:5rem" />
          <small>→ snapped to the nearest Sunday</small>
        </span>
      </div>

      <fieldset class="ss-fieldset">
        <legend>Title</legend>
        <label>English <small>(required)</small></label>
        <input v-model="form.titles.en" required />
        <label>Myanmar (မြန်မာ) <small>— Unicode only</small></label>
        <input v-model="form.titles.my" class="my-text" />
        <label>Zolai / Tedim</label>
        <input v-model="form.titles.td" class="my-text" />
      </fieldset>

      <fieldset class="ss-fieldset">
        <legend>Short brief</legend>
        <label>English <small>(required)</small></label>
        <textarea v-model="form.briefs.en" rows="2" required></textarea>
        <label>Myanmar (မြန်မာ)</label>
        <textarea v-model="form.briefs.my" rows="2" class="my-text"></textarea>
        <label>Zolai / Tedim</label>
        <textarea v-model="form.briefs.td" rows="2" class="my-text"></textarea>
      </fieldset>

      <label>Sermon tags <small>(comma-separated — bias the sermon theme)</small></label>
      <input v-model="form.sermon_tags" placeholder="harvest, provision, gratitude" />

      <label>Music moods <small>(comma-separated — bias worship selection)</small></label>
      <input v-model="form.music_moods" placeholder="grateful, joyful, praise" />

      <div class="ss-rule-row">
        <span>
          <label>Priority <small>(higher wins on overlap)</small></label>
          <input type="number" v-model.number="form.priority" style="width:6rem" />
        </span>
        <span>
          <label>Region <small>(optional)</small></label>
          <input v-model="form.region" placeholder="global" style="width:9rem" />
        </span>
        <span class="ss-active">
          <label><input type="checkbox" v-model="form.active" /> Active</label>
        </span>
      </div>

      <div class="ss-form-actions">
        <button type="submit" class="btn primary" :disabled="busy">{{ busy ? "Saving…" : "Save" }}</button>
        <button type="button" class="btn" @click="cancelEdit">Cancel</button>
      </div>
    </form>

    <!-- ──────────────────── MANAGE CONTENT (manual mode) ──────────────────── -->
    <div v-else-if="view === 'content' && contentRow" class="ss-content">
      <div class="ss-content-head">
        <h3>Content — {{ contentRow.titles?.en || contentRow.key }}</h3>
        <button class="btn" @click="closeContent">← Back</button>
      </div>
      <p class="ss-muted">
        By default the AI sermon &amp; mood-selected worship run normally. Flip a
        language to <strong>Manual</strong> to serve your curated sermon/song below
        instead — if none is active, it safely falls back to the AI.
      </p>

      <!-- Mode matrix -->
      <table class="ss-grid ss-modes">
        <thead><tr><th>Language</th><th>Sermon</th><th>Worship song</th></tr></thead>
        <tbody>
          <tr v-for="l in LANGS" :key="l">
            <td><strong>{{ LANG_LABEL[l] }}</strong></td>
            <td v-for="seg in ['sermon','music']" :key="seg">
              <button class="ss-seg" :class="{ on: modeOf(seg, l) === 'auto' }"   :disabled="busy" @click="setMode(seg, l, 'auto')">Auto (AI)</button>
              <button class="ss-seg" :class="{ on: modeOf(seg, l) === 'manual' }" :disabled="busy" @click="setMode(seg, l, 'manual')">Manual</button>
            </td>
          </tr>
        </tbody>
      </table>

      <!-- Preview: what would actually play -->
      <div class="ss-preview">
        <div class="ss-preview-bar">
          <strong>Preview what plays</strong>
          <label>Language
            <select v-model="previewLang">
              <option v-for="l in LANGS" :key="l" :value="l">{{ LANG_LABEL[l] }}</option>
            </select>
          </label>
          <label>Mood
            <input v-model="previewMood" placeholder="e.g. grateful" @keyup.enter="runPreview" />
          </label>
          <button class="btn primary" :disabled="previewBusy" @click="runPreview">{{ previewBusy ? "…" : "Preview" }}</button>
        </div>

        <div v-if="preview" class="ss-preview-out" :class="{ 'my-text': preview.language !== 'en' }">
          <div class="ss-preview-seg">
            <span class="ss-preview-label">Sermon</span>
            <template v-if="preview.sermon.mode === 'manual'">
              <span class="ss-chip sermon">manual</span>
              <strong>{{ preview.sermon.title }}</strong>
              <p class="ss-preview-body">{{ preview.sermon.body }}</p>
            </template>
            <template v-else>
              <span class="ss-chip">auto (AI)</span>
              <span v-if="preview.sermon.fallback" class="ss-muted">— manual set but no active entry, falling back</span>
              <span class="ss-muted">biased by: {{ (preview.sermon_tags || []).join(", ") || "—" }}</span>
            </template>
          </div>
          <div class="ss-preview-seg">
            <span class="ss-preview-label">Worship</span>
            <template v-if="preview.music.mode === 'manual'">
              <span class="ss-chip mood">manual</span>
              <strong>{{ preview.music.title }}</strong>
              <span class="ss-muted">{{ preview.music.source_type }}: {{ preview.music.source_ref }}</span>
            </template>
            <template v-else>
              <span class="ss-chip">auto (mood-selected)</span>
              <span v-if="preview.music.fallback" class="ss-muted">— manual set but no active entry, falling back</span>
              <span class="ss-muted">biased by: {{ (preview.music_moods || []).join(", ") || "—" }}</span>
            </template>
          </div>
        </div>
      </div>

      <!-- Per-language sermon + song libraries -->
      <div v-for="l in LANGS" :key="'lib'+l" class="ss-lang-block" :class="{ 'my-text': l !== 'en' }">
        <h4>{{ LANG_LABEL[l] }}</h4>

        <div class="ss-lib">
          <div class="ss-lib-head">
            <span>Sermons <small class="ss-muted">({{ modeOf('sermon', l) }})</small></span>
            <button class="btn" @click="newSermon(l)">+ Add sermon</button>
          </div>
          <ul class="ss-lib-list">
            <li v-for="s in sermonsFor(l)" :key="s.id" :class="{ off: !s.active }">
              <span><strong>{{ s.title }}</strong> <small class="ss-muted">prio {{ s.priority }}{{ s.mood ? ' · '+s.mood : '' }}</small></span>
              <span class="ss-lib-act">
                <button class="btn" @click="editSermon(s)">Edit</button>
                <button class="btn danger" @click="deleteSermon(s)">Del</button>
              </span>
            </li>
            <li v-if="!sermonsFor(l).length" class="ss-muted">No curated sermon.</li>
          </ul>

          <div class="ss-lib-head">
            <span>Songs <small class="ss-muted">({{ modeOf('music', l) }})</small></span>
            <button class="btn" @click="newSong(l)">+ Add song</button>
          </div>
          <ul class="ss-lib-list">
            <li v-for="s in songsFor(l)" :key="s.id" :class="{ off: !s.active }">
              <span><strong>{{ s.title }}</strong> <small class="ss-muted">{{ s.source_type }} · prio {{ s.priority }}{{ s.mood ? ' · '+s.mood : '' }}</small></span>
              <span class="ss-lib-act">
                <button class="btn" @click="editSong(s)">Edit</button>
                <button class="btn danger" @click="deleteSong(s)">Del</button>
              </span>
            </li>
            <li v-if="!songsFor(l).length" class="ss-muted">No curated song.</li>
          </ul>
        </div>
      </div>

      <!-- Sermon editor modal -->
      <div v-if="sermonForm" class="ss-modal" @click.self="sermonForm = null">
        <form class="ss-modal-box" @submit.prevent="saveSermon">
          <h4>{{ sermonForm.id ? "Edit" : "New" }} sermon — {{ LANG_LABEL[sermonForm.language] }}</h4>
          <label>Title</label>
          <input v-model="sermonForm.title" :class="{ 'my-text': sermonForm.language !== 'en' }" required />
          <label>Body <small>(spoken verbatim)</small></label>
          <textarea v-model="sermonForm.body" rows="8" :class="{ 'my-text': sermonForm.language !== 'en' }" required></textarea>
          <div class="ss-rule-row">
            <span><label>Mood <small>(optional tag)</small></label><input v-model="sermonForm.mood" style="width:9rem" /></span>
            <span><label>Priority</label><input type="number" v-model.number="sermonForm.priority" style="width:6rem" /></span>
            <span class="ss-active"><label><input type="checkbox" v-model="sermonForm.active" /> Active</label></span>
          </div>
          <div class="ss-form-actions">
            <button type="submit" class="btn primary" :disabled="busy">{{ busy ? "Saving…" : "Save" }}</button>
            <button type="button" class="btn" @click="sermonForm = null">Cancel</button>
          </div>
        </form>
      </div>

      <!-- Song editor modal -->
      <div v-if="songForm" class="ss-modal" @click.self="songForm = null">
        <form class="ss-modal-box" @submit.prevent="saveSong">
          <h4>{{ songForm.id ? "Edit" : "New" }} song — {{ LANG_LABEL[songForm.language] }}</h4>
          <label>Title</label>
          <input v-model="songForm.title" :class="{ 'my-text': songForm.language !== 'en' }" required />
          <label>Source type</label>
          <select v-model="songForm.source_type">
            <option v-for="t in SONG_TYPES" :key="t.value" :value="t.value">{{ t.label }}</option>
          </select>
          <label>Source <small>{{ (SONG_TYPES.find(t => t.value === songForm.source_type) || {}).hint }}</small></label>
          <input v-model="songForm.source_ref" required />
          <label>Lyrics <small>(optional, on-screen)</small></label>
          <textarea v-model="songForm.lyrics" rows="4" :class="{ 'my-text': songForm.language !== 'en' }"></textarea>
          <div class="ss-rule-row">
            <span><label>Mood</label><input v-model="songForm.mood" style="width:9rem" /></span>
            <span><label>Priority</label><input type="number" v-model.number="songForm.priority" style="width:6rem" /></span>
            <span class="ss-active"><label><input type="checkbox" v-model="songForm.active" /> Active</label></span>
          </div>
          <div class="ss-form-actions">
            <button type="submit" class="btn primary" :disabled="busy">{{ busy ? "Saving…" : "Save" }}</button>
            <button type="button" class="btn" @click="songForm = null">Cancel</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<style scoped>
@import url("https://fonts.googleapis.com/css2?family=Padauk:wght@400;700&family=Noto+Sans+Myanmar:wght@400;600&display=swap");
.ss { max-width: 980px; }
.ss-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; }
.ss-sub { color: var(--text-muted); max-width: 60ch; line-height: 1.5; margin: 0.25rem 0 1rem; }
.ss-muted { color: var(--text-muted); }
.ss-notice { padding: 0.5rem 0.75rem; border-radius: var(--radius-sm); margin: 0.5rem 0; }
.ss-notice.ok  { background: color-mix(in srgb, green 12%, var(--surface)); }
.ss-notice.err { background: color-mix(in srgb, red 12%, var(--surface)); }
.ss-current { padding: 0.7rem 1rem; border-radius: var(--radius-sm); border: 1px solid var(--primary);
  background: color-mix(in srgb, var(--primary) 8%, var(--surface)); margin-bottom: 1.25rem; }
.ss-current.none { border-color: var(--border); background: var(--surface); }
.ss-section { margin-bottom: 1.75rem; }
.ss-section h3 { margin: 0 0 0.5rem; font-size: 1rem; }
.ss-grid { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
.ss-grid th, .ss-grid td { text-align: left; padding: 0.45rem 0.6rem; border-bottom: 1px solid var(--border); vertical-align: top; }
.ss-grid tr.off { opacity: 0.5; }
.ss-key { color: var(--text-muted); font-size: 0.78rem; font-family: monospace; }
.ss-tags { max-width: 22rem; }
.ss-chip { display: inline-block; padding: 0.05rem 0.4rem; margin: 0.1rem; border-radius: 999px; font-size: 0.72rem; }
.ss-chip.sermon { background: color-mix(in srgb, var(--primary) 16%, transparent); }
.ss-chip.mood   { background: color-mix(in srgb, orange 18%, transparent); }
.ss-toggle { padding: 0.2rem 0.7rem; border-radius: 999px; border: 1px solid var(--border); cursor: pointer; background: var(--surface); }
.ss-toggle.on { background: var(--primary); color: var(--on-primary); border-color: var(--primary); }
.ss-toggle:disabled { cursor: default; }
.ss-actions { white-space: nowrap; }
.btn { padding: 0.3rem 0.7rem; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface); cursor: pointer; font: inherit; margin-right: 0.3rem; }
.btn.primary { background: var(--primary); color: var(--on-primary); border-color: var(--primary); }
.btn.danger { color: #c0392b; }
.ss-form { max-width: 640px; }
.ss-form label { display: block; margin: 0.75rem 0 0.2rem; font-weight: 600; font-size: 0.9rem; }
.ss-form label small, .ss-rule-row small { font-weight: 400; color: var(--text-muted); }
.ss-form input, .ss-form select, .ss-form textarea { width: 100%; padding: 0.4rem 0.5rem; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface); color: var(--text); font: inherit; }
.ss-rule-row { display: flex; flex-wrap: wrap; gap: 1.25rem; align-items: flex-end; margin: 0.75rem 0; }
.ss-rule-row input, .ss-rule-row select { width: auto; }
.ss-fieldset { border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 0.5rem 0.9rem 0.9rem; margin: 1rem 0; }
.ss-fieldset legend { padding: 0 0.4rem; font-weight: 600; }
.my-text { font-family: "Padauk", "Noto Sans Myanmar", "Myanmar Text", sans-serif; }
.ss-active label { display: inline-flex; align-items: center; gap: 0.4rem; }
.ss-active input { width: auto; }
.ss-form-actions { margin-top: 1.25rem; display: flex; gap: 0.5rem; }

/* Content management */
.ss-content-head { display: flex; justify-content: space-between; align-items: center; }
.ss-modes { max-width: 560px; margin: 0.75rem 0 1.5rem; }
.ss-seg { padding: 0.2rem 0.6rem; border: 1px solid var(--border); background: var(--surface); cursor: pointer; font: inherit; }
.ss-seg:first-of-type { border-radius: var(--radius-sm) 0 0 var(--radius-sm); }
.ss-seg:last-of-type  { border-radius: 0 var(--radius-sm) var(--radius-sm) 0; border-left: none; }
.ss-seg.on { background: var(--primary); color: var(--on-primary); border-color: var(--primary); }
.ss-preview { border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 0.75rem 1rem; margin: 0.5rem 0 1rem; }
.ss-preview-bar { display: flex; flex-wrap: wrap; gap: 0.9rem; align-items: center; }
.ss-preview-bar label { display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.88rem; }
.ss-preview-bar select, .ss-preview-bar input { padding: 0.3rem 0.45rem; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface); color: var(--text); font: inherit; }
.ss-preview-out { margin-top: 0.85rem; display: grid; gap: 0.7rem; }
.ss-preview-seg { display: flex; flex-wrap: wrap; gap: 0.4rem 0.6rem; align-items: baseline; }
.ss-preview-label { display: inline-block; min-width: 4.5rem; font-weight: 700; }
.ss-preview-body { flex-basis: 100%; margin: 0.25rem 0 0; padding: 0.5rem 0.7rem; background: var(--surface); border-radius: var(--radius-sm); max-height: 9rem; overflow: auto; white-space: pre-wrap; line-height: 1.6; }
.ss-lang-block { border-top: 1px solid var(--border); padding-top: 0.75rem; margin-top: 1rem; }
.ss-lang-block h4 { margin: 0 0 0.5rem; }
.ss-lib-head { display: flex; justify-content: space-between; align-items: center; margin: 0.6rem 0 0.3rem; font-weight: 600; font-size: 0.9rem; }
.ss-lib-list { list-style: none; padding: 0; margin: 0; }
.ss-lib-list li { display: flex; justify-content: space-between; align-items: center; padding: 0.35rem 0.5rem; border-bottom: 1px solid var(--border); font-size: 0.9rem; }
.ss-lib-list li.off { opacity: 0.5; }
.ss-lib-act { white-space: nowrap; }
.my-text, .my-text input, .my-text textarea { font-family: "Padauk", "Noto Sans Myanmar", "Myanmar Text", sans-serif; }
.ss-modal { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: flex; align-items: flex-start; justify-content: center; padding: 4vh 1rem; z-index: 50; overflow-y: auto; }
.ss-modal-box { background: var(--surface); border-radius: var(--radius-sm); padding: 1.25rem 1.5rem; width: 100%; max-width: 560px; border: 1px solid var(--border); }
.ss-modal-box label { display: block; margin: 0.7rem 0 0.2rem; font-weight: 600; font-size: 0.9rem; }
.ss-modal-box input, .ss-modal-box select, .ss-modal-box textarea { width: 100%; padding: 0.4rem 0.5rem; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--bg, var(--surface)); color: var(--text); font: inherit; }
.ss-modal-box .ss-rule-row input { width: auto; }
</style>
