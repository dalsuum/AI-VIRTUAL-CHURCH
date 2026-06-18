<script setup>
import { ref, onMounted, nextTick } from 'vue';
import Cropper from 'cropperjs';
import 'cropperjs/dist/cropper.css';
import { api } from '../composables/useApi.js';

const props = defineProps({
  settings:  { type: Object,   default: null },
  saving:    { type: Boolean,  default: false },
  readOnly:  { type: Boolean,  default: false },
});
const emit = defineEmits(['save-setting']);

// ── State ────────────────────────────────────────────────────────────────────
const ads        = ref([]);
const analytics  = ref([]);
const busy       = ref(false);
const notice     = ref('');
const view       = ref('list'); // 'list' | 'edit' | 'analytics'
const editingAd  = ref(null);   // full ad object with slides when in edit view

const adForm = ref(defaultAdForm());
function defaultAdForm() {
  return {
    title: '', type: 'slideshow', status: 'draft',
    locations: ['between'],
    target_language: '', target_moods: [],
    currency: 'USD',
    price_per_impression: '0', price_per_click: '0',
    slide_duration: 5,
    html_content: '',
  };
}

// Slide editor state
const slideHtmlForm  = ref({ html_content: '', link_url: '', duration_seconds: '' });
const addingSlide    = ref(false);   // 'html' | 'image' | false
const imageUploadRef = ref(null);    // <input type=file>
const cropperImgRef  = ref(null);    // <img> for cropper
let   cropperInstance = null;
const cropBusy        = ref(false);

// Mood chips for targeting
const availableMoods = ref([]);
const moodInput      = ref('');

const LOCATION_OPTIONS = [
  { value: 'start',       label: 'Service Start',    hint: 'Before the first stage begins.' },
  { value: 'between',     label: 'Between Stages',   hint: 'In the content area between Previous / Next.' },
  { value: 'end',         label: 'Service End',      hint: 'After the final stage, before "End service".' },
  { value: 'special_day', label: 'Special Day pages', hint: 'In the box below the Father\'s Day music-video page. Tag an ad here to show it; untag to hide.' },
  { value: 'sticker_ads', label: 'Sticker page',      hint: 'In the box below the Live Sticker page. Tag an ad here to show it; untag to hide.' },
];

const CURRENCY_OPTIONS = ['USD','EUR','GBP','SGD','MMK','INR','KRW','JPY','AUD','CAD'];

const STATUS_OPTIONS = [
  { value: 'active', label: 'Active' },
  { value: 'paused', label: 'Paused' },
  { value: 'draft',  label: 'Draft'  },
];

// ── Load ─────────────────────────────────────────────────────────────────────
onMounted(async () => {
  await loadAds();
  try {
    const cfg = await api.adminSettings();
    availableMoods.value = cfg.moods || [];
  } catch { /* non-critical */ }
});

async function loadAds() {
  busy.value = true;
  try {
    const res = await api.adminAds();
    ads.value = res.ads || [];
  } catch (e) {
    notice.value = e?.data?.message || 'Could not load ads.';
  } finally {
    busy.value = false;
  }
}

async function loadAnalytics() {
  busy.value = true;
  try {
    const res = await api.adminAdsAnalytics();
    analytics.value = res.stats || [];
  } catch (e) {
    notice.value = e?.data?.message || 'Could not load analytics.';
  } finally {
    busy.value = false;
  }
}

// ── Ad CRUD ───────────────────────────────────────────────────────────────────
function openCreate() {
  editingAd.value = null;
  adForm.value = defaultAdForm();
  view.value = 'edit';
  notice.value = '';
}

function openEdit(ad) {
  editingAd.value = { ...ad };
  adForm.value = {
    title: ad.title,
    type: ad.type,
    status: ad.status,
    locations: [...(ad.locations || [])],
    target_language: ad.target_language || '',
    target_moods: [...(ad.target_moods || [])],
    currency: ad.currency || 'USD',
    price_per_impression: String(ad.price_per_impression ?? '0'),
    price_per_click: String(ad.price_per_click ?? '0'),
    slide_duration: ad.slide_duration || 5,
    html_content: ad.html_content || '',
  };
  view.value = 'edit';
  notice.value = '';
  nextTick(loadEditingAdSlides);
}

async function loadEditingAdSlides() {
  if (!editingAd.value?.id) return;
  try {
    const res = await api.adminAd(editingAd.value.id);
    editingAd.value = res.ad;
  } catch { /* non-critical */ }
}

async function saveAd() {
  busy.value = true;
  notice.value = '';
  const payload = {
    ...adForm.value,
    target_language: adForm.value.target_language || null,
    target_moods: adForm.value.target_moods,
    price_per_impression: parseFloat(adForm.value.price_per_impression) || 0,
    price_per_click: parseFloat(adForm.value.price_per_click) || 0,
    html_content: adForm.value.html_content || null,
  };
  try {
    if (editingAd.value?.id) {
      const res = await api.adminUpdateAd(editingAd.value.id, payload);
      editingAd.value = res.ad;
      notice.value = 'Ad updated.';
    } else {
      const res = await api.adminCreateAd(payload);
      editingAd.value = res.ad;
      notice.value = 'Ad created. Add slides below.';
    }
    await loadAds();
  } catch (e) {
    notice.value = e?.data?.message || 'Could not save ad.';
  } finally {
    busy.value = false;
  }
}

async function deleteAd(ad) {
  if (!confirm(`Delete ad "${ad.title}"? This cannot be undone.`)) return;
  busy.value = true;
  try {
    await api.adminDeleteAd(ad.id);
    notice.value = `Ad "${ad.title}" deleted.`;
    await loadAds();
    if (editingAd.value?.id === ad.id) { view.value = 'list'; editingAd.value = null; }
  } catch (e) {
    notice.value = e?.data?.message || 'Could not delete ad.';
  } finally {
    busy.value = false;
  }
}

// ── Slide CRUD ────────────────────────────────────────────────────────────────
function openAddSlide(type) {
  addingSlide.value = type;
  slideHtmlForm.value = { html_content: '', link_url: '', duration_seconds: '' };
  cropperDestroy();
  if (type === 'image') {
    nextTick(() => imageUploadRef.value?.click());
  }
}

async function saveHtmlSlide() {
  if (!editingAd.value?.id) return;
  busy.value = true;
  try {
    const res = await api.adminCreateSlide(editingAd.value.id, {
      type: 'html',
      html_content: slideHtmlForm.value.html_content,
      link_url: slideHtmlForm.value.link_url || null,
      duration_seconds: slideHtmlForm.value.duration_seconds ? Number(slideHtmlForm.value.duration_seconds) : null,
    });
    editingAd.value.slides = [...(editingAd.value.slides || []), res.slide];
    addingSlide.value = false;
    notice.value = 'Slide added.';
  } catch (e) {
    notice.value = e?.data?.message || 'Could not add slide.';
  } finally {
    busy.value = false;
  }
}

async function deleteSlide(slide) {
  if (!editingAd.value?.id) return;
  if (!confirm('Delete this slide?')) return;
  busy.value = true;
  try {
    await api.adminDeleteSlide(editingAd.value.id, slide.id);
    editingAd.value.slides = editingAd.value.slides.filter(s => s.id !== slide.id);
    notice.value = 'Slide deleted.';
  } catch (e) {
    notice.value = e?.data?.message || 'Could not delete slide.';
  } finally {
    busy.value = false;
  }
}

async function moveSlide(slide, direction) {
  const slides = [...(editingAd.value.slides || [])];
  const i = slides.indexOf(slide);
  if (direction === 'up' && i === 0) return;
  if (direction === 'down' && i === slides.length - 1) return;
  const swap = direction === 'up' ? i - 1 : i + 1;
  [slides[i], slides[swap]] = [slides[swap], slides[i]];
  editingAd.value.slides = slides;
  const order = slides.map(s => s.id);
  try { await api.adminReorderSlides(editingAd.value.id, order); } catch { /* optimistic */ }
}

// ── Image Upload + Cropper ────────────────────────────────────────────────────
function onFileSelected(e) {
  const file = e.target.files?.[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = (ev) => {
    nextTick(() => {
      if (!cropperImgRef.value) return;
      cropperImgRef.value.src = ev.target.result;
      cropperDestroy();
      cropperInstance = new Cropper(cropperImgRef.value, {
        aspectRatio: NaN, // free crop
        viewMode: 1,
        autoCropArea: 1,
      });
    });
  };
  reader.readAsDataURL(file);
  e.target.value = '';
}

function cropperDestroy() {
  if (cropperInstance) { cropperInstance.destroy(); cropperInstance = null; }
}

async function uploadCropped() {
  if (!cropperInstance || !editingAd.value?.id) return;
  cropBusy.value = true;
  try {
    // First create the slide record
    const created = await api.adminCreateSlide(editingAd.value.id, {
      type: 'image',
      link_url: slideHtmlForm.value.link_url || null,
      duration_seconds: slideHtmlForm.value.duration_seconds ? Number(slideHtmlForm.value.duration_seconds) : null,
    });
    const slide = created.slide;

    // Then upload the cropped image blob
    const canvas = cropperInstance.getCroppedCanvas({ maxWidth: 1200, maxHeight: 800 });
    const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/webp', 0.88));
    const res = await api.adminUploadSlideImage(editingAd.value.id, slide.id, blob, 'slide.webp');

    const finalSlide = { ...res.slide, image_url: res.url };
    editingAd.value.slides = [...(editingAd.value.slides || []), finalSlide];
    addingSlide.value = false;
    cropperDestroy();
    notice.value = 'Image slide added.';
  } catch (e) {
    notice.value = e?.data?.message || 'Upload failed.';
  } finally {
    cropBusy.value = false;
  }
}

// ── Targeting helpers ─────────────────────────────────────────────────────────
function toggleLocation(loc) {
  const arr = adForm.value.locations;
  const i = arr.indexOf(loc);
  if (i >= 0) { if (arr.length > 1) arr.splice(i, 1); }
  else arr.push(loc);
}

function toggleMood(mood) {
  const arr = adForm.value.target_moods;
  const i = arr.indexOf(mood);
  if (i >= 0) arr.splice(i, 1);
  else arr.push(mood);
}

function addCustomMood() {
  const m = moodInput.value.trim();
  if (!m) return;
  if (!adForm.value.target_moods.includes(m)) adForm.value.target_moods.push(m);
  moodInput.value = '';
}

// ── Formatting ────────────────────────────────────────────────────────────────
function fmtRevenue(row) {
  return `${Number(row.revenue).toFixed(2)} ${row.currency}`;
}
function statusClass(s) {
  return { active: 'badge-active', paused: 'badge-paused', draft: 'badge-draft' }[s] || '';
}
const locationLabel = (v) => LOCATION_OPTIONS.find(o => o.value === v)?.label || v;

// ── Analytics tab ─────────────────────────────────────────────────────────────
async function showAnalytics() {
  view.value = 'analytics';
  notice.value = '';
  await loadAnalytics();
}

function backToList() {
  view.value = 'list';
  notice.value = '';
  editingAd.value = null;
  addingSlide.value = false;
  cropperDestroy();
  loadAds();
}
</script>

<template>
  <div class="ads-mgr">
    <!-- Top bar -->
    <div class="ads-head">
      <div class="ads-breadcrumb">
        <span class="crumb" :class="{ active: view === 'list' }" @click="backToList">Ads</span>
        <template v-if="view === 'edit'">
          <span class="sep">›</span>
          <span class="crumb active">{{ editingAd?.id ? editingAd.title : 'New Ad' }}</span>
        </template>
        <template v-if="view === 'analytics'">
          <span class="sep">›</span>
          <span class="crumb active">Analytics</span>
        </template>
      </div>
      <div class="ads-actions">
        <button class="chip" @click="showAnalytics">Analytics</button>
        <button class="chip primary-chip" @click="openCreate">+ New Ad</button>
      </div>
    </div>

    <p v-if="notice" class="ads-notice">{{ notice }}</p>

    <!-- ── Quick HTML / Google Ads slot ── -->
    <div v-if="settings" class="slot-panel">
      <div class="slot-header">
        <div class="slot-title-row">
          <strong>Quick Ad Slot</strong>
          <span class="slot-hint">Paste a Google Ads embed code or any custom HTML — shown in the service player between stages.</span>
        </div>
        <div class="slot-toggle">
          <button
            type="button"
            class="toggle-btn"
            :class="{ active: settings.ad_slot_enabled === true }"
            :disabled="saving || readOnly"
            @click="emit('save-setting', 'ad_slot_enabled', true, 'Ad slot enabled.')"
          >Enabled</button>
          <button
            type="button"
            class="toggle-btn"
            :class="{ active: settings.ad_slot_enabled !== true }"
            :disabled="saving || readOnly"
            @click="emit('save-setting', 'ad_slot_enabled', false, 'Ad slot disabled.')"
          >Disabled</button>
        </div>
      </div>
      <textarea
        v-model="settings.ad_slot_html"
        class="slot-textarea"
        rows="4"
        placeholder="Paste Google Ads embed code or custom HTML here…"
        :disabled="saving || readOnly"
      ></textarea>
      <button
        class="chip primary-chip"
        :disabled="saving || readOnly"
        @click="emit('save-setting', 'ad_slot_html', settings.ad_slot_html, 'Ad slot code saved.')"
      >Save ad code</button>
    </div>

    <!-- ── List view ── -->
    <template v-if="view === 'list'">
      <div v-if="busy" class="ads-loading">Loading…</div>
      <div v-else-if="!ads.length" class="ads-empty">No ads yet. Click <strong>+ New Ad</strong> to create one.</div>
      <div v-else class="ads-list">
        <div v-for="ad in ads" :key="ad.id" class="ad-card">
          <div class="ad-card-head">
            <span :class="['ad-status', statusClass(ad.status)]">{{ ad.status }}</span>
            <strong class="ad-title">{{ ad.title }}</strong>
            <span class="ad-type-badge">{{ ad.type }}</span>
          </div>
          <div class="ad-card-meta">
            <span v-for="loc in (ad.locations || [])" :key="loc" class="loc-chip">{{ locationLabel(loc) }}</span>
            <span v-if="ad.target_language" class="loc-chip lang">{{ ad.target_language.toUpperCase() }}</span>
            <span class="ad-stat">{{ ad.slides_count || 0 }} slide{{ ad.slides_count !== 1 ? 's' : '' }}</span>
            <span class="ad-stat">{{ ad.total_impressions || 0 }} imp.</span>
            <span class="ad-stat">{{ ad.total_clicks || 0 }} clicks</span>
            <span class="ad-stat revenue">{{ Number(ad.total_revenue || 0).toFixed(2) }} {{ ad.currency }}</span>
          </div>
          <div class="ad-card-actions">
            <button class="link" @click="openEdit(ad)">Edit</button>
            <button class="link danger" @click="deleteAd(ad)">Delete</button>
          </div>
        </div>
      </div>
    </template>

    <!-- ── Edit / create view ── -->
    <template v-else-if="view === 'edit'">
      <div class="edit-layout">
        <!-- Left: ad form -->
        <section class="edit-form-col">
          <h3 class="edit-section-title">Ad details</h3>

          <div class="field">
            <label>Title</label>
            <input v-model="adForm.title" type="text" maxlength="150" placeholder="Ad campaign name" />
          </div>

          <div class="field">
            <label>Status</label>
            <div class="choice-row compact">
              <button v-for="s in STATUS_OPTIONS" :key="s.value" type="button"
                class="choice sm" :class="{ active: adForm.status === s.value }"
                @click="adForm.status = s.value">{{ s.label }}</button>
            </div>
          </div>

          <div class="field">
            <label>Type</label>
            <div class="choice-row compact">
              <button type="button" class="choice sm" :class="{ active: adForm.type === 'slideshow' }" @click="adForm.type = 'slideshow'">
                Image / Slides
              </button>
              <button type="button" class="choice sm" :class="{ active: adForm.type === 'html' }" @click="adForm.type = 'html'">
                Custom HTML
              </button>
            </div>
          </div>

          <!-- Custom HTML content (only for html type) -->
          <div v-if="adForm.type === 'html'" class="field">
            <label>HTML Content (embed code / banner)</label>
            <textarea v-model="adForm.html_content" rows="6" class="code-textarea"
              placeholder="Paste Google Ads embed code or custom HTML…"></textarea>
          </div>

          <div class="field">
            <label>Show Locations <span class="field-hint">(select all that apply)</span></label>
            <div class="choice-row compact">
              <button v-for="loc in LOCATION_OPTIONS" :key="loc.value" type="button"
                class="choice sm" :class="{ active: adForm.locations.includes(loc.value) }"
                @click="toggleLocation(loc.value)">
                {{ loc.label }}
              </button>
            </div>
          </div>

          <div class="field">
            <label>Default slide duration <span class="field-hint">(seconds)</span></label>
            <div class="stepper">
              <button type="button" @click="adForm.slide_duration = Math.max(1, adForm.slide_duration - 1)">−</button>
              <span class="stepper-val">{{ adForm.slide_duration }}s</span>
              <button type="button" @click="adForm.slide_duration = Math.min(60, adForm.slide_duration + 1)">+</button>
            </div>
          </div>

          <h3 class="edit-section-title" style="margin-top:1.5rem">Billing</h3>

          <div class="field-row">
            <div class="field">
              <label>Currency</label>
              <select v-model="adForm.currency">
                <option v-for="c in CURRENCY_OPTIONS" :key="c" :value="c">{{ c }}</option>
              </select>
            </div>
            <div class="field">
              <label>Per impression</label>
              <input v-model="adForm.price_per_impression" type="number" min="0" step="0.0001" />
            </div>
            <div class="field">
              <label>Per click</label>
              <input v-model="adForm.price_per_click" type="number" min="0" step="0.0001" />
            </div>
          </div>

          <h3 class="edit-section-title" style="margin-top:1.5rem">Audience Targeting</h3>
          <p class="field-hint" style="margin-bottom:.75rem">Leave blank / empty to show to everyone.</p>

          <div class="field">
            <label>Language</label>
            <div class="choice-row compact">
              <button type="button" class="choice sm" :class="{ active: adForm.target_language === '' }" @click="adForm.target_language = ''">All</button>
              <button type="button" class="choice sm" :class="{ active: adForm.target_language === 'en' }" @click="adForm.target_language = 'en'">English</button>
              <button type="button" class="choice sm" :class="{ active: adForm.target_language === 'my' }" @click="adForm.target_language = 'my'">Myanmar</button>
              <button type="button" class="choice sm" :class="{ active: adForm.target_language === 'td' }" @click="adForm.target_language = 'td'">Zolai</button>
            </div>
          </div>

          <div class="field">
            <label>Moods <span class="field-hint">(empty = all moods)</span></label>
            <div class="mood-chips">
              <span v-for="mood in availableMoods" :key="mood"
                class="mood-chip" :class="{ selected: adForm.target_moods.includes(mood) }"
                @click="toggleMood(mood)">{{ mood }}</span>
            </div>
            <div class="mood-add-row">
              <input v-model="moodInput" placeholder="Add custom mood…" @keyup.enter="addCustomMood" />
              <button type="button" class="chip" @click="addCustomMood">Add</button>
            </div>
            <div v-if="adForm.target_moods.length" class="selected-moods">
              Selected:
              <span v-for="m in adForm.target_moods" :key="m" class="tag-mood">{{ m }}
                <button type="button" @click="toggleMood(m)">×</button>
              </span>
            </div>
          </div>

          <div class="edit-save-row">
            <button class="primary" :disabled="busy || !adForm.title.trim()" @click="saveAd">
              {{ busy ? 'Saving…' : (editingAd?.id ? 'Update Ad' : 'Create Ad') }}
            </button>
            <button class="chip" @click="backToList">Cancel</button>
            <button v-if="editingAd?.id" class="chip danger" @click="deleteAd(editingAd)">Delete Ad</button>
          </div>
        </section>

        <!-- Right: slides (only after ad exists and type = slideshow) -->
        <section v-if="editingAd?.id && adForm.type === 'slideshow'" class="slides-col">
          <h3 class="edit-section-title">Slides</h3>

          <!-- Slide list -->
          <div class="slide-list">
            <div v-for="slide in (editingAd.slides || [])" :key="slide.id" class="slide-item">
              <div class="slide-preview">
                <img v-if="slide.image_url || slide.image_path" :src="slide.image_url || `/storage/${slide.image_path}`" class="slide-thumb" />
                <div v-else class="slide-html-badge">HTML</div>
              </div>
              <div class="slide-meta">
                <span v-if="slide.link_url" class="slide-link">{{ slide.link_url }}</span>
                <span class="slide-dur">{{ slide.duration_seconds ? slide.duration_seconds + 's' : 'Default' }}</span>
              </div>
              <div class="slide-controls">
                <button class="icon-btn" title="Move up" @click="moveSlide(slide, 'up')">↑</button>
                <button class="icon-btn" title="Move down" @click="moveSlide(slide, 'down')">↓</button>
                <button class="icon-btn danger" title="Delete" @click="deleteSlide(slide)">×</button>
              </div>
            </div>
            <div v-if="!(editingAd.slides?.length)" class="slides-empty">No slides yet.</div>
          </div>

          <!-- Add slide buttons -->
          <div v-if="!addingSlide" class="add-slide-btns">
            <button class="chip primary-chip" @click="openAddSlide('image')">+ Image slide</button>
            <button class="chip" @click="openAddSlide('html')">+ HTML slide</button>
          </div>

          <!-- Hidden file input -->
          <input ref="imageUploadRef" type="file" accept=".png,.jpg,.jpeg,.webp" style="display:none" @change="onFileSelected" />

          <!-- Image cropper panel -->
          <div v-if="addingSlide === 'image'" class="crop-panel">
            <p class="field-hint" style="margin-bottom:.5rem">Crop your image, then click Upload.</p>
            <div class="crop-wrap">
              <img ref="cropperImgRef" style="max-width:100%;display:block" />
            </div>
            <div class="field" style="margin-top:.75rem">
              <label>Link URL (optional)</label>
              <input v-model="slideHtmlForm.link_url" type="url" placeholder="https://…" />
            </div>
            <div class="field">
              <label>Slide duration (seconds, blank = use ad default)</label>
              <div class="stepper">
                <button type="button" @click="slideHtmlForm.duration_seconds = Math.max(1, (Number(slideHtmlForm.duration_seconds)||5) - 1)">−</button>
                <span class="stepper-val">{{ slideHtmlForm.duration_seconds || '—' }}</span>
                <button type="button" @click="slideHtmlForm.duration_seconds = (Number(slideHtmlForm.duration_seconds)||4) + 1">+</button>
              </div>
            </div>
            <div class="crop-actions">
              <button class="primary" :disabled="cropBusy || !cropperInstance" @click="uploadCropped">
                {{ cropBusy ? 'Uploading…' : 'Upload cropped image' }}
              </button>
              <button class="chip" @click="addingSlide = false; cropperDestroy()">Cancel</button>
              <button class="chip" @click="imageUploadRef?.click()">Choose different file</button>
            </div>
          </div>

          <!-- HTML slide form -->
          <div v-if="addingSlide === 'html'" class="html-slide-panel">
            <div class="field">
              <label>HTML content</label>
              <textarea v-model="slideHtmlForm.html_content" rows="5" class="code-textarea" placeholder="HTML for this slide…"></textarea>
            </div>
            <div class="field">
              <label>Link URL (optional)</label>
              <input v-model="slideHtmlForm.link_url" type="url" placeholder="https://…" />
            </div>
            <div class="field">
              <label>Slide duration (seconds)</label>
              <div class="stepper">
                <button type="button" @click="slideHtmlForm.duration_seconds = Math.max(1, (Number(slideHtmlForm.duration_seconds)||5) - 1)">−</button>
                <span class="stepper-val">{{ slideHtmlForm.duration_seconds || '—' }}</span>
                <button type="button" @click="slideHtmlForm.duration_seconds = (Number(slideHtmlForm.duration_seconds)||4) + 1">+</button>
              </div>
            </div>
            <div class="html-slide-actions">
              <button class="primary" :disabled="busy || !slideHtmlForm.html_content.trim()" @click="saveHtmlSlide">Add slide</button>
              <button class="chip" @click="addingSlide = false">Cancel</button>
            </div>
          </div>
        </section>
      </div>
    </template>

    <!-- ── Analytics view ── -->
    <template v-else-if="view === 'analytics'">
      <div v-if="busy" class="ads-loading">Loading analytics…</div>
      <div v-else-if="!analytics.length" class="ads-empty">No impressions recorded yet.</div>
      <table v-else class="analytics-table">
        <thead>
          <tr>
            <th>Ad</th><th>Impressions</th><th>Clicks</th><th>CTR</th>
            <th>Total view time</th><th>Revenue</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in analytics" :key="row.ad_id">
            <td><strong>{{ row.title }}</strong></td>
            <td>{{ row.impressions }}</td>
            <td>{{ row.clicks }}</td>
            <td>{{ row.impressions > 0 ? ((row.clicks / row.impressions) * 100).toFixed(1) + '%' : '—' }}</td>
            <td>{{ Math.round((row.total_duration_ms || 0) / 1000) }}s</td>
            <td class="revenue-cell">{{ fmtRevenue(row) }}</td>
          </tr>
        </tbody>
      </table>
    </template>
  </div>
</template>

<style scoped>
/* Quick slot panel */
.slot-panel {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 1rem 1.2rem;
  margin-bottom: 1.25rem;
  display: flex;
  flex-direction: column;
  gap: .65rem;
}
.slot-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
.slot-title-row { display: flex; flex-direction: column; gap: .2rem; }
.slot-title-row strong { font-size: .95rem; }
.slot-hint { font-size: .78rem; color: var(--text-muted); }
.slot-toggle { display: flex; gap: .35rem; flex-shrink: 0; }
.toggle-btn {
  padding: .3rem .75rem;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  background: var(--surface);
  cursor: pointer;
  font-size: .82rem;
  font-weight: 500;
}
.toggle-btn.active { background: var(--primary); color: var(--on-primary); border-color: var(--primary); }
.toggle-btn:disabled { opacity: .5; cursor: default; }
.slot-textarea {
  width: 100%;
  box-sizing: border-box;
  padding: .5rem .7rem;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  background: var(--surface);
  color: var(--text);
  font-family: monospace;
  font-size: .8rem;
  resize: vertical;
}

.ads-mgr { padding: 0.25rem 0; }
.ads-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.1rem; flex-wrap: wrap; gap: .5rem; }
.ads-breadcrumb { display: flex; align-items: center; gap: .4rem; font-size: .95rem; }
.crumb { color: var(--text-muted); cursor: pointer; }
.crumb.active { color: var(--text); font-weight: 600; cursor: default; }
.sep { color: var(--text-faint); }
.ads-actions { display: flex; gap: .5rem; }
.ads-notice { background: var(--surface-2, var(--surface)); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: .55rem .9rem; margin-bottom: 1rem; font-size: .9rem; }
.ads-loading, .ads-empty { text-align: center; color: var(--text-muted); padding: 2.5rem 1rem; font-size: .95rem; }

/* Ad cards */
.ads-list { display: flex; flex-direction: column; gap: .75rem; }
.ad-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1rem 1.2rem; }
.ad-card-head { display: flex; align-items: center; gap: .7rem; margin-bottom: .5rem; }
.ad-title { flex: 1; font-size: 1rem; }
.ad-type-badge { font-size: .75rem; color: var(--text-muted); border: 1px solid var(--border); padding: .1rem .4rem; border-radius: 999px; }
.ad-status { font-size: .7rem; font-weight: 700; text-transform: uppercase; padding: .15rem .5rem; border-radius: 999px; }
.badge-active { background: #d1fae5; color: #065f46; }
.badge-paused { background: #fef3c7; color: #92400e; }
.badge-draft  { background: var(--surface-2, #f3f4f6); color: var(--text-muted); }
.ad-card-meta { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; margin-bottom: .6rem; }
.loc-chip { font-size: .75rem; padding: .1rem .45rem; border-radius: 999px; border: 1px solid var(--border); background: var(--surface-2, var(--surface)); color: var(--text-muted); }
.loc-chip.lang { border-color: var(--primary); color: var(--primary); }
.ad-stat { font-size: .8rem; color: var(--text-muted); }
.revenue { color: #059669; font-weight: 600; }
.ad-card-actions { display: flex; gap: .75rem; }

/* Edit layout */
.edit-layout { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; align-items: start; }
@media (max-width: 900px) { .edit-layout { grid-template-columns: 1fr; } }
.edit-form-col, .slides-col { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.25rem 1.4rem; }
.edit-section-title { font-size: 1rem; font-weight: 700; margin: 0 0 .9rem; }

.field { margin-bottom: .9rem; }
.field label { display: block; font-size: .82rem; color: var(--text-muted); margin-bottom: .3rem; font-weight: 500; }
.field input, .field select, .field textarea { width: 100%; box-sizing: border-box; padding: .5rem .65rem; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface); color: var(--text); font-size: .9rem; }
.field-hint { font-size: .78rem; color: var(--text-muted); }
.field-row { display: flex; gap: .75rem; }
.field-row .field { flex: 1; }
.code-textarea { font-family: monospace; font-size: .8rem; }

.choice-row.compact { flex-wrap: wrap; gap: .45rem; margin-bottom: 0; }
.choice.sm { padding: .35rem .75rem; min-width: 0; flex: none; font-size: .85rem; }

.stepper { display: flex; align-items: center; gap: .5rem; }
.stepper button { width: 2rem; height: 2rem; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface); cursor: pointer; font-size: 1.1rem; display: flex; align-items: center; justify-content: center; }
.stepper-val { min-width: 2.5rem; text-align: center; font-weight: 600; }

.mood-chips { display: flex; flex-wrap: wrap; gap: .35rem; margin-bottom: .5rem; }
.mood-chip { font-size: .8rem; padding: .2rem .55rem; border: 1px solid var(--border); border-radius: 999px; cursor: pointer; }
.mood-chip.selected { background: var(--primary); color: var(--on-primary); border-color: var(--primary); }
.mood-add-row { display: flex; gap: .4rem; margin-bottom: .4rem; }
.mood-add-row input { flex: 1; padding: .4rem .6rem; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface); color: var(--text); font-size: .85rem; }
.selected-moods { font-size: .82rem; color: var(--text-muted); }
.tag-mood { background: var(--primary-soft, #e0e7ff); color: var(--primary); border-radius: 999px; padding: .1rem .4rem; margin: 0 .2rem; }
.tag-mood button { background: none; border: none; cursor: pointer; color: inherit; padding: 0 .1rem; font-size: .85rem; }

.edit-save-row { display: flex; gap: .6rem; flex-wrap: wrap; margin-top: 1rem; }
.edit-save-row .danger { color: #dc2626; }

/* Slides */
.slide-list { display: flex; flex-direction: column; gap: .6rem; margin-bottom: .9rem; }
.slides-empty { font-size: .88rem; color: var(--text-muted); text-align: center; padding: 1rem 0; }
.slide-item { display: flex; align-items: center; gap: .75rem; background: var(--surface-2, #f9fafb); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: .5rem .75rem; }
.slide-preview { width: 64px; height: 40px; overflow: hidden; border-radius: 4px; background: var(--border); flex-shrink: 0; }
.slide-thumb { width: 100%; height: 100%; object-fit: cover; }
.slide-html-badge { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-size: .7rem; font-weight: 700; color: var(--text-muted); }
.slide-meta { flex: 1; font-size: .78rem; color: var(--text-muted); display: flex; flex-direction: column; gap: .15rem; overflow: hidden; }
.slide-link { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.slide-dur { font-weight: 600; }
.slide-controls { display: flex; gap: .25rem; }
.icon-btn { width: 1.7rem; height: 1.7rem; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface); cursor: pointer; font-size: .9rem; display: flex; align-items: center; justify-content: center; }
.icon-btn.danger { color: #dc2626; border-color: #fca5a5; }

.add-slide-btns { display: flex; gap: .5rem; margin-top: .25rem; }

.crop-panel, .html-slide-panel { margin-top: 1rem; padding: .9rem; background: var(--surface-2, #f9fafb); border: 1px solid var(--border); border-radius: var(--radius-sm); }
.crop-wrap { width: 100%; max-height: 280px; overflow: hidden; border-radius: var(--radius-sm); background: #000; }
.crop-actions, .html-slide-actions { display: flex; gap: .5rem; margin-top: .75rem; flex-wrap: wrap; }

/* Analytics */
.analytics-table { width: 100%; border-collapse: collapse; font-size: .88rem; }
.analytics-table th { text-align: left; padding: .5rem .75rem; border-bottom: 2px solid var(--border); font-size: .8rem; color: var(--text-muted); font-weight: 600; }
.analytics-table td { padding: .5rem .75rem; border-bottom: 1px solid var(--border); }
.revenue-cell { color: #059669; font-weight: 700; }

/* Reuse existing class names from AdminConsole for consistent look */
.primary { padding: .6rem 1.1rem; background: var(--primary); color: var(--on-primary); border: none; border-radius: var(--radius-sm); font-weight: 600; cursor: pointer; }
.primary:disabled { opacity: .5; cursor: default; }
.chip { padding: .45rem .85rem; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface); cursor: pointer; font-size: .85rem; }
.chip:disabled { opacity: .5; cursor: default; }
.primary-chip { background: var(--primary); color: var(--on-primary); border-color: var(--primary); }
.link { background: none; border: none; color: var(--primary); cursor: pointer; font-size: .85rem; padding: 0; }
.link.danger { color: #dc2626; }
.danger { color: #dc2626; }
.choice { display: flex; flex-direction: column; gap: .2rem; padding: .55rem .85rem; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface); cursor: pointer; text-align: left; }
.choice.active { border-color: var(--primary); background: var(--primary-soft, #e0e7ff); }
.choice-row { display: flex; gap: .5rem; flex-wrap: wrap; margin-bottom: .75rem; }
</style>
