<script setup>
import { ref, computed } from "vue";
import vocab from "../data/zolai_vocabulary.json";

const search = ref("");
const activeCategory = ref("All");

const categories = computed(() => {
  const cats = [...new Set(vocab.map((w) => w.category))];
  return ["All", ...cats];
});

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase();
  return vocab.filter((w) => {
    const catOk = activeCategory.value === "All" || w.category === activeCategory.value;
    if (!catOk) return false;
    if (!q) return true;
    return (
      w.zolai.toLowerCase().includes(q) ||
      (w.burmese || "").toLowerCase().includes(q) ||
      w.english.toLowerCase().includes(q) ||
      w.notes.toLowerCase().includes(q)
    );
  });
});

const categoryColors = {
  Theology:   "var(--primary)",
  Worship:    "#8b5cf6",
  Pronouns:   "#059669",
  Verbs:      "#d97706",
  Nouns:      "#0284c7",
  Grammar:    "#dc2626",
  Adjectives: "#7c3aed",
  Numbers:    "#0891b2",
};

function catColor(cat) {
  return categoryColors[cat] || "var(--text-muted)";
}
</script>

<template>
  <div class="vocab-page">
    <header class="vocab-header">
      <a href="#" class="back-link" aria-label="Back to worship">
        <span class="back-icon">&#8592;</span><span class="back-text">&nbsp;Back to worship</span>
      </a>
      <div class="vocab-title-block">
        <h1 class="vocab-title">Vocabulary</h1>
        <p class="vocab-sub">
          Zolai (Tedim Chin) ↔ Burmese ↔ English reference — {{ vocab.length }} words &amp; phrases.
        </p>
      </div>
    </header>

    <div class="controls">
      <input
        v-model="search"
        class="search-input"
        type="search"
        placeholder="Search Zolai, Burmese or English…"
        aria-label="Search vocabulary"
      />
      <div class="cat-filters" role="group" aria-label="Filter by category">
        <button
          v-for="cat in categories"
          :key="cat"
          class="cat-btn"
          :class="{ active: activeCategory === cat }"
          :style="activeCategory === cat && cat !== 'All' ? { borderColor: catColor(cat), color: catColor(cat) } : {}"
          @click="activeCategory = cat"
        >
          {{ cat }}
        </button>
      </div>
    </div>

    <p v-if="filtered.length === 0" class="empty">No matches found.</p>

    <div v-else class="table-wrap">
      <table class="vocab-table">
        <thead>
          <tr>
            <th>Zolai (Tedim)</th>
            <th>Burmese</th>
            <th>English</th>
            <th>Category</th>
            <th>Notes</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(word, i) in filtered" :key="i">
            <td class="zolai-word">{{ word.zolai }}</td>
            <td class="burmese-word">{{ word.burmese }}</td>
            <td>{{ word.english }}</td>
            <td>
              <span class="cat-badge" :style="{ color: catColor(word.category), borderColor: catColor(word.category) }">
                {{ word.category }}
              </span>
            </td>
            <td class="notes-cell">{{ word.notes }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <footer class="vocab-footer">
      <p>
        Reference: <em>Paunam Khenna Leh Kampau Luanzia</em> (Sia Cin Sian Pau) &nbsp;·&nbsp;
        Lai Siangtho 1932 &nbsp;·&nbsp; Tedim Hymnal
      </p>
    </footer>
  </div>
</template>

<style scoped>
.vocab-page {
  min-height: 100vh;
  padding: 0 0 4rem;
  background: var(--bg);
  color: var(--text);
}

.vocab-header {
  padding: 1.25rem 1.5rem 0.5rem;
  border-bottom: 1px solid var(--border);
  background: var(--surface);
}

.back-link {
  display: inline-block;
  font-size: 0.83rem;
  color: var(--text-muted);
  text-decoration: none;
  margin-bottom: 0.6rem;
  transition: color 0.15s;
}
.back-link:hover { color: var(--primary); }

.vocab-title-block { margin-bottom: 0.75rem; }
.vocab-title {
  font-size: 1.45rem;
  font-weight: 700;
  letter-spacing: -0.02em;
  margin: 0 0 0.3rem;
}
.vocab-sub {
  font-size: 0.83rem;
  color: var(--text-muted);
  margin: 0;
  line-height: 1.5;
}
.vocab-sub code {
  font-size: 0.78rem;
  background: var(--bg);
  border: 1px solid var(--border);
  border-radius: 4px;
  padding: 0 4px;
}

.controls {
  padding: 1rem 1.5rem;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  position: sticky;
  top: 0;
  z-index: 5;
  background: color-mix(in srgb, var(--bg) 90%, transparent);
  backdrop-filter: blur(8px);
  border-bottom: 1px solid var(--border);
}

.search-input {
  width: 100%;
  max-width: 380px;
  padding: 0.5rem 0.85rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--surface);
  color: var(--text);
  font-size: 0.9rem;
  outline: none;
  transition: border-color 0.15s;
}
.search-input:focus { border-color: var(--primary); }

.cat-filters {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}
.cat-btn {
  padding: 0.3rem 0.75rem;
  font-size: 0.78rem;
  border: 1px solid var(--border);
  border-radius: 999px;
  background: transparent;
  color: var(--text-muted);
  cursor: pointer;
  transition: border-color 0.15s, color 0.15s, background 0.15s;
}
.cat-btn:hover { border-color: var(--primary); color: var(--primary); }
.cat-btn.active {
  background: var(--primary);
  border-color: var(--primary);
  color: var(--on-primary);
  font-weight: 600;
}

.empty {
  text-align: center;
  color: var(--text-muted);
  padding: 3rem 1.5rem;
  font-size: 0.9rem;
}

.table-wrap {
  overflow-x: auto;
  padding: 1rem 1.5rem 0;
}

.vocab-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.875rem;
}
.vocab-table thead tr {
  border-bottom: 2px solid var(--border);
}
.vocab-table th {
  text-align: left;
  padding: 0.55rem 0.75rem;
  font-weight: 600;
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--text-muted);
  white-space: nowrap;
}
.vocab-table td {
  padding: 0.55rem 0.75rem;
  border-bottom: 1px solid var(--border);
  vertical-align: top;
}
.vocab-table tbody tr:hover td {
  background: color-mix(in srgb, var(--primary) 5%, transparent);
}

.zolai-word {
  font-weight: 600;
  font-size: 0.95rem;
  letter-spacing: 0.01em;
  color: var(--text);
}

.burmese-word {
  font-size: 0.95rem;
  color: var(--text);
  white-space: nowrap;
}

.cat-badge {
  display: inline-block;
  font-size: 0.72rem;
  font-weight: 600;
  padding: 0.15rem 0.55rem;
  border-radius: 999px;
  border: 1px solid;
  white-space: nowrap;
}

.notes-cell {
  color: var(--text-muted);
  font-size: 0.82rem;
  font-style: italic;
}

.vocab-footer {
  padding: 2rem 1.5rem 0;
  text-align: center;
  font-size: 0.78rem;
  color: var(--text-muted);
}

@media (max-width: 500px) {
  .vocab-header, .controls, .table-wrap { padding-left: 1rem; padding-right: 1rem; }
  .vocab-title { font-size: 1.2rem; }
  .vocab-table { font-size: 0.8rem; }
  .back-text { display: none; }
  .back-icon { font-size: 1.1rem; }
}
</style>
