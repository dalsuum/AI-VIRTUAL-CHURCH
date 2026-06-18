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

const view    = ref("list");      // 'list' | 'edit'
const editing = ref(null);        // the row id being edited, or null for a new one
const form    = ref(blankForm());

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
</style>
