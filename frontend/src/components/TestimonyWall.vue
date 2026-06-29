<script setup>
// The testimony segment: read what others have shared, and add your own. Submitted
// testimonies are held for moderation, so the wall only ever shows approved ones.
import { ref, onMounted } from "vue";
import { useI18n } from "vue-i18n";
import { api } from "../composables/useApi";

const { t } = useI18n();
const testimonies = ref([]);
const content = ref("");
const stage = ref("idle"); // "idle" | "sending" | "thanks"
const error = ref(null);

async function load() {
  try {
    const { testimonies: list } = await api.listTestimonies();
    testimonies.value = list || [];
  } catch (e) {
    // A quiet wall is fine; never block the service on this.
  }
}

async function share() {
  if (content.value.trim().length < 10) {
    error.value = t("testimony.errMore");
    return;
  }
  error.value = null;
  stage.value = "sending";
  try {
    await api.submitTestimony(content.value.trim());
    content.value = "";
    stage.value = "thanks";
  } catch (e) {
    error.value = e?.data?.message || t("testimony.errSend");
    stage.value = "idle";
  }
}

onMounted(load);
</script>

<template>
  <section class="testimony">
    <h2>{{ t("testimony.title") }}</h2>

    <ul v-if="testimonies.length" class="wall">
      <li v-for="item in testimonies" :key="item.id">{{ item.content }}</li>
    </ul>
    <p v-else class="sub">{{ t("testimony.beFirst") }}</p>

    <template v-if="stage !== 'thanks'">
      <label class="share-label" for="testimony-input">{{ t("testimony.shareLabel") }}</label>
      <textarea
        id="testimony-input"
        v-model="content"
        rows="3"
        :placeholder="t('testimony.placeholder')"
        :disabled="stage === 'sending'"
      ></textarea>
      <p v-if="error" class="error">{{ error }}</p>
      <button class="primary" type="button" :disabled="stage === 'sending'" @click="share">
        {{ stage === "sending" ? t("testimony.sending") : t("testimony.share") }}
      </button>
    </template>

    <p v-else class="thanks">
      {{ t("testimony.thanks") }}
    </p>
  </section>
</template>

<style scoped>
.testimony { margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid var(--border); }
.testimony h2 { font-size: 1.15rem; margin: 0 0 0.5rem; letter-spacing: -0.01em; }
.sub { color: var(--text-muted); margin: 0 0 1rem; }
.wall { list-style: none; padding: 0; margin: 0 0 1.25rem; }
.wall li { background: var(--surface-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 0.75rem 0.95rem; margin-bottom: 0.5rem; line-height: 1.55; color: var(--text); }
.share-label { display: block; font-size: 0.9rem; color: var(--text-muted); margin-bottom: 0.35rem; }
textarea { width: 100%; box-sizing: border-box; padding: 0.65rem 0.75rem; border: 1px solid var(--border); border-radius: var(--radius-sm); font: inherit; resize: vertical; background: var(--surface); color: var(--text); }
textarea::placeholder { color: var(--text-faint); }
textarea:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-soft); }
.error { color: var(--danger); font-size: 0.9rem; margin: 0.4rem 0 0; }
.primary { margin-top: 0.6rem; width: 100%; padding: 0.75rem; border: 0; border-radius: var(--radius-sm); background: var(--primary); color: var(--on-primary); font-size: 1rem; font-weight: 600; cursor: pointer; transition: background 0.12s ease; }
.primary:hover:not(:disabled) { background: var(--primary-hover); }
.primary:disabled { opacity: 0.6; cursor: default; }
.thanks { color: var(--success); }
</style>
