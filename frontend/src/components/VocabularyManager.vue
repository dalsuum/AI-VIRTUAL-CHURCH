<script setup>
import { ref, computed, onMounted } from "vue";
import { api } from "../composables/useApi.js";

// Vocabulary library state.
const words   = ref([]);
const loading = ref(true);
const error   = ref("");
const message = ref("");
const status  = ref("");

const CATEGORIES = [
  "Theology", "Worship", "Pronouns", "Verbs", "Nouns",
  "Grammar", "Adjectives", "Numbers",
];

const filterCat = ref("All");
const search    = ref("");

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase();
  return words.value.filter((w) => {
    if (filterCat.value !== "All" && w.category !== filterCat.value) return false;
    if (!q) return true;
    return (
      w.zolai.toLowerCase().includes(q) ||
      (w.burmese || "").toLowerCase().includes(q) ||
      (w.hebrew || "").includes(q) ||
      w.english.toLowerCase().includes(q) ||
      (w.notes || "").toLowerCase().includes(q)
    );
  });
});

async function load() {
  loading.value = true;
  error.value = "";
  try {
    const res = await api.getVocabulary();
    words.value = res.vocabulary || [];
  } catch (e) {
    error.value = "Could not load vocabulary.";
  } finally {
    loading.value = false;
  }
}
onMounted(load);

// ── Editing ───────────────────────────────────────────────────────────────
const editing = ref(false);
const saving  = ref(false);
const form    = ref(blank());

function blank() {
  return { id: null, zolai: "", burmese: "", hebrew: "", english: "", category: "Theology", notes: "" };
}

function newWord() {
  form.value = blank();
  editing.value = true;
}

function editWord(w) {
  form.value = {
    id: w.id,
    zolai: w.zolai,
    burmese: w.burmese || "",
    hebrew: w.hebrew || "",
    english: w.english,
    category: w.category || "Theology",
    notes: w.notes || "",
  };
  editing.value = true;
}

function cancel() {
  editing.value = false;
  form.value = blank();
}

function flash(msg, ok = true) {
  message.value = msg;
  status.value = ok ? "ok" : "error";
  setTimeout(() => { message.value = ""; }, 3000);
}

async function save() {
  const f = form.value;
  if (!f.zolai.trim() || !f.english.trim()) {
    flash("Zolai and English are required.", false);
    return;
  }
  saving.value = true;
  const payload = {
    zolai: f.zolai.trim(),
    burmese: f.burmese.trim() || null,
    hebrew: f.hebrew.trim() || null,
    english: f.english.trim(),
    category: f.category || null,
    notes: f.notes.trim() || null,
  };
  try {
    if (f.id) {
      const res = await api.adminUpdateVocabulary(f.id, payload);
      const i = words.value.findIndex((w) => w.id === f.id);
      if (i !== -1) words.value[i] = res.word;
      flash("Saved.");
    } else {
      const res = await api.adminCreateVocabulary(payload);
      words.value.push(res.word);
      flash("Added.");
    }
    editing.value = false;
    form.value = blank();
  } catch (e) {
    flash(e?.message || "Save failed.", false);
  } finally {
    saving.value = false;
  }
}

async function remove(w) {
  if (!confirm(`Delete “${w.zolai}” (${w.english})?`)) return;
  try {
    await api.adminDeleteVocabulary(w.id);
    words.value = words.value.filter((x) => x.id !== w.id);
    flash("Deleted.");
  } catch (e) {
    flash(e?.message || "Delete failed.", false);
  }
}
</script>

<template>
  <div class="vocab-mgr">
    <!-- ── LIST ── -->
    <div v-if="!editing" class="panel">
      <div class="panel-head">
        <h2>Vocabulary <span class="count" v-if="!loading">({{ words.length }})</span></h2>
        <button class="btn primary" @click="newWord">+ New Word</button>
      </div>

      <div class="filters">
        <input v-model="search" class="inp" type="search" placeholder="Search Zolai, Burmese, Hebrew, English or notes…" />
        <div class="cat-tabs">
          <button class="tab" :class="{ active: filterCat === 'All' }" @click="filterCat = 'All'">All</button>
          <button v-for="c in CATEGORIES" :key="c" class="tab"
                  :class="{ active: filterCat === c }" @click="filterCat = c">{{ c }}</button>
        </div>
      </div>

      <p v-if="message" class="msg" :class="status">{{ message }}</p>
      <p v-if="loading" class="muted">Loading…</p>
      <p v-else-if="error" class="msg error">{{ error }}</p>
      <p v-else-if="filtered.length === 0" class="muted">No words match. Click “New Word” to add one.</p>

      <table v-else class="tbl">
        <thead>
          <tr><th>Zolai</th><th>Burmese</th><th>Hebrew</th><th>English</th><th>Category</th><th>Notes</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-for="w in filtered" :key="w.id">
            <td class="t-zolai">{{ w.zolai }}</td>
            <td>{{ w.burmese || "—" }}</td>
            <td class="t-hebrew" dir="rtl" lang="he">{{ w.hebrew || "—" }}</td>
            <td>{{ w.english }}</td>
            <td>{{ w.category || "—" }}</td>
            <td class="t-notes">{{ w.notes || "" }}</td>
            <td class="t-actions">
              <button class="btn sm" @click="editWord(w)">Edit</button>
              <button class="btn sm danger" @click="remove(w)">Delete</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- ── EDITOR ── -->
    <div v-else class="panel">
      <div class="panel-head">
        <h2>{{ form.id ? "Edit Word" : "New Word" }}</h2>
        <button class="btn" @click="cancel">← Back</button>
      </div>

      <p v-if="message" class="msg" :class="status">{{ message }}</p>

      <div class="form-grid">
        <label>Zolai (Tedim) *
          <input v-model="form.zolai" class="inp" type="text" maxlength="255" />
        </label>
        <label>Burmese
          <input v-model="form.burmese" class="inp" type="text" maxlength="255" />
        </label>
        <label>Hebrew
          <input v-model="form.hebrew" class="inp" type="text" maxlength="255" dir="rtl" lang="he" />
        </label>
        <label>English *
          <input v-model="form.english" class="inp" type="text" maxlength="255" />
        </label>
        <label>Category
          <select v-model="form.category" class="inp">
            <option v-for="c in CATEGORIES" :key="c" :value="c">{{ c }}</option>
          </select>
        </label>
        <label class="full">Notes
          <input v-model="form.notes" class="inp" type="text" maxlength="500"
                 placeholder="Optional usage note, e.g. 'NOT Pathian (Mizo word)'" />
        </label>
      </div>

      <div class="form-actions">
        <button class="btn" @click="cancel">Cancel</button>
        <button class="btn primary" :disabled="saving" @click="save">
          {{ saving ? "Saving…" : "Save" }}
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.panel { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.25rem; }
.panel-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; gap: 1rem; }
.panel-head h2 { margin: 0; font-size: 1.1rem; }
.count { color: var(--text-muted); font-weight: 400; font-size: 0.9rem; }

.btn { padding: 0.4rem 0.8rem; border: 1px solid var(--border); border-radius: var(--radius);
       background: transparent; color: var(--text); cursor: pointer; font-size: 0.85rem; }
.btn:hover { border-color: var(--primary); }
.btn.primary { background: var(--primary); border-color: var(--primary); color: var(--on-primary); }
.btn.sm { padding: 0.25rem 0.55rem; font-size: 0.78rem; }
.btn.danger:hover { border-color: #dc2626; color: #dc2626; }
.btn:disabled { opacity: 0.6; cursor: default; }

.filters { display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center; margin-bottom: 1rem; }
.inp { padding: 0.45rem 0.7rem; border: 1px solid var(--border); border-radius: var(--radius);
       background: var(--bg); color: var(--text); font-size: 0.9rem; outline: none; width: 100%; }
.inp:focus { border-color: var(--primary); }
.filters .inp { max-width: 320px; }

.cat-tabs { display: flex; flex-wrap: wrap; gap: 0.35rem; }
.tab { padding: 0.25rem 0.6rem; font-size: 0.78rem; border: 1px solid var(--border);
       border-radius: 999px; background: transparent; color: var(--text-muted); cursor: pointer; }
.tab.active { background: var(--primary); border-color: var(--primary); color: var(--on-primary); }

.tbl { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
.tbl th { text-align: left; padding: 0.5rem; font-size: 0.75rem; text-transform: uppercase;
          letter-spacing: 0.04em; color: var(--text-muted); border-bottom: 2px solid var(--border); }
.tbl td { padding: 0.5rem; border-bottom: 1px solid var(--border); vertical-align: top; }
.t-zolai { font-weight: 600; }
.t-hebrew { text-align: right; direction: rtl; font-size: 1.05rem; }
.t-notes { color: var(--text-muted); font-style: italic; font-size: 0.82rem; }
.t-actions { white-space: nowrap; text-align: right; }
.t-actions .btn { margin-left: 0.35rem; }

.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.25rem; }
.form-grid label { display: flex; flex-direction: column; gap: 0.35rem; font-size: 0.82rem; color: var(--text-muted); }
.form-grid label.full { grid-column: 1 / -1; }
.form-actions { display: flex; justify-content: flex-end; gap: 0.6rem; }

.msg { padding: 0.5rem 0.75rem; border-radius: var(--radius); font-size: 0.85rem; margin-bottom: 0.75rem; }
.msg.ok { background: color-mix(in srgb, #059669 15%, transparent); color: #059669; }
.msg.error { background: color-mix(in srgb, #dc2626 15%, transparent); color: #dc2626; }
.muted { color: var(--text-muted); font-size: 0.9rem; padding: 1rem 0; }

@media (max-width: 560px) {
  .form-grid { grid-template-columns: 1fr; }
}
</style>
