<!--
  Live Sticker — admin manager (SELF-CONTAINED & REMOVABLE).
  Enable/disable the public #stickers page + set fallback page copy. The sticker
  theme (title, captions, AI art) automatically follows the CURRENT Special
  Sunday when one is active; this copy is the fallback shown otherwise.
  Remove with the rest of the feature.
-->
<script setup>
import { ref, onMounted } from "vue";
import { api } from "../composables/useApi";

const cfg = ref(null);
const loading = ref(true);
const saving = ref(false);
const msg = ref("");
const err = ref("");

onMounted(load);

async function load() {
  loading.value = true;
  try {
    cfg.value = await api.stickerAdminShow();
  } catch (e) {
    err.value = e.message || "Failed to load configuration.";
  } finally {
    loading.value = false;
  }
}

async function save() {
  saving.value = true; msg.value = ""; err.value = "";
  try {
    await api.stickerAdminSave({
      enabled: !!cfg.value.enabled,
      title: cfg.value.title || "",
      subtitle: cfg.value.subtitle || "",
    });
    msg.value = "Settings saved.";
  } catch (e) {
    err.value = e.message || "Save failed.";
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <section class="fdm">
    <h2>Live Sticker</h2>
    <p class="hint">
      Visitors upload a photo at <code>#stickers</code> and get an AI watercolor
      die-cut sticker. The theme (title, caption suggestions and art) follows the
      <strong>current Special Sunday</strong> automatically — the copy below is
      the fallback shown when no observance is active.
    </p>

    <p v-if="loading">Loading…</p>
    <p v-if="err" class="bad small">{{ err }}</p>
    <p v-if="msg" class="ok small">{{ msg }}</p>

    <div v-if="!loading && cfg" class="fdm-grid">
      <label class="toggle">
        <input type="checkbox" v-model="cfg.enabled" />
        <span><strong>Enabled</strong> — show the page to visitors</span>
      </label>
      <div class="field">
        <label>Page title (fallback)</label>
        <input type="text" v-model="cfg.title" maxlength="120" placeholder="Make a Live Sticker" />
      </div>
      <div class="field">
        <label>Subtitle</label>
        <input type="text" v-model="cfg.subtitle" maxlength="200"
          placeholder="Upload a photo — we'll turn it into a fun watercolor sticker." />
      </div>
      <div class="actions">
        <button class="btn primary" :disabled="saving" @click="save">{{ saving ? "Saving…" : "Save settings" }}</button>
      </div>
    </div>
  </section>
</template>

<style scoped>
.fdm { max-width: 680px; }
.hint { color: var(--muted, #888); font-size: .9rem; }
.fdm-grid { display: flex; flex-direction: column; gap: 1rem; margin-top: 1.25rem; }
.toggle { display: flex; align-items: center; gap: .6rem; cursor: pointer; }
.field { display: flex; flex-direction: column; gap: .35rem; }
.field label { font-size: .8rem; font-weight: 700; }
.field input { padding: .65rem; border: 1px solid var(--border, #444); border-radius: 8px; background: transparent; color: inherit; }
.actions { margin-top: .5rem; }
.btn { padding: .6rem 1.4rem; border: none; border-radius: 8px; cursor: pointer; }
.btn.primary { background: var(--primary, #3b82f6); color: #fff; font-weight: 700; }
.btn:disabled { opacity: .6; cursor: wait; }
.bad { color: var(--danger, #ef4444); }
.ok { color: var(--success, #22c55e); }
.small { font-size: .85rem; }
</style>
