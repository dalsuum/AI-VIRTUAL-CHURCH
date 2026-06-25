<script setup>
// AI Core / Bible Study admin. Renders inside AdminConsole as one tab. Server-only
// fields (system prompts, tradition tags, provider keys) are never returned by the
// API — this UI treats prompts/keys as write-only and shows only "set/unset" flags.
import { onMounted, ref } from "vue";
import { api } from "../composables/useApi";

const sub = ref("personas");
const personas = ref([]);
const prompts = ref([]);
const providers = ref([]);
const manifest = ref(null);
const tiers = ref(null);
const sessions = ref(null);
const usage = ref([]);
const audit = ref(null);
const msg = ref("");
const err = ref("");

async function load() {
  err.value = "";
  try {
    if (sub.value === "personas") personas.value = await api.studyAdminPersonas();
    else if (sub.value === "prompts") prompts.value = await api.studyAdminPrompts();
    else if (sub.value === "providers") providers.value = await api.studyAdminProviders();
    else if (sub.value === "manifest") manifest.value = await api.studyAdminManifest();
    else if (sub.value === "tiers") tiers.value = (await api.studyAdminTiers()).caps;
    else if (sub.value === "sessions") sessions.value = await api.studyAdminSessions();
    else if (sub.value === "usage") usage.value = await api.studyAdminUsage();
    else if (sub.value === "audit") audit.value = await api.studyAdminAudit();
  } catch (e) {
    err.value = e?.message || "Failed to load.";
  }
}

function go(s) { sub.value = s; load(); }
onMounted(load);

async function togglePersona(p) {
  await api.studyAdminUpdatePersona(p.id, { enabled: !p.enabled });
  load();
}

const newProvider = ref({ name: "", type: "openrouter", base_url: "", model: "", api_key: "", key_ref: "" });
async function addProvider() {
  err.value = ""; msg.value = "";
  try {
    await api.studyAdminCreateProvider(newProvider.value);
    msg.value = "Provider added.";
    newProvider.value = { name: "", type: "openrouter", base_url: "", model: "", api_key: "", key_ref: "" };
    load();
  } catch (e) { err.value = e?.message || "Failed."; }
}

async function saveTiers() {
  err.value = ""; msg.value = "";
  try {
    const res = await api.studyAdminUpdateTiers(tiers.value);
    tiers.value = res.caps;
    msg.value = "Tier limits saved.";
  } catch (e) { err.value = e?.message || "Failed."; }
}

async function activate() {
  err.value = ""; msg.value = "";
  try {
    const res = await api.studyAdminUpdateManifest({ enabled: true });
    msg.value = `Manifest status: ${res.status}`;
    load();
  } catch (e) {
    err.value = (e?.body?.errors || [e?.message]).join(" · ");
  }
}
</script>

<template>
  <div class="bsa">
    <nav class="subtabs">
      <button v-for="t in ['personas','prompts','providers','manifest','tiers','sessions','usage','audit']"
              :key="t" :class="{ active: sub === t }" @click="go(t)">{{ t }}</button>
    </nav>

    <p v-if="msg" class="ok">{{ msg }}</p>
    <p v-if="err" class="err">{{ err }}</p>

    <div v-if="sub === 'personas'">
      <table><thead><tr><th>Lang</th><th>Name</th><th>Weight</th><th>Moderator</th><th>Prompt</th><th>Lens</th><th>Enabled</th></tr></thead>
        <tbody>
          <tr v-for="p in personas" :key="p.id">
            <td>{{ p.language }}</td><td>{{ p.display_name }}</td><td>{{ p.weight }}</td>
            <td>{{ p.is_moderator ? "✓" : "" }}</td>
            <td>{{ p.has_system_prompt ? "set" : "—" }}</td>
            <td>{{ p.has_tradition_tag ? "set" : "—" }}</td>
            <td><button class="chip" @click="togglePersona(p)">{{ p.enabled ? "on" : "off" }}</button></td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-else-if="sub === 'prompts'">
      <table><thead><tr><th>Lang</th><th>Role</th><th>Temp</th><th>Max tokens</th><th>Enabled</th></tr></thead>
        <tbody><tr v-for="t in prompts" :key="t.id">
          <td>{{ t.language }}</td><td>{{ t.role }}</td><td>{{ t.temperature }}</td><td>{{ t.max_tokens }}</td><td>{{ t.enabled ? "✓" : "" }}</td>
        </tr></tbody>
      </table>
      <p class="hint">Prompt bodies are server-only and edited via API (write-only).</p>
    </div>

    <div v-else-if="sub === 'providers'">
      <table><thead><tr><th>Name</th><th>Type</th><th>Model</th><th>Key</th><th>Enabled</th></tr></thead>
        <tbody><tr v-for="p in providers" :key="p.id">
          <td>{{ p.name }}</td><td>{{ p.type }}</td><td>{{ p.model }}</td>
          <td>{{ p.key_set ? "•••• set" : "unset" }}</td><td>{{ p.enabled ? "✓" : "" }}</td>
        </tr></tbody>
      </table>
      <fieldset class="add">
        <legend>Add provider</legend>
        <input v-model="newProvider.name" placeholder="name" />
        <select v-model="newProvider.type">
          <option>openrouter</option><option>ollama</option><option>runpod</option>
          <option>lmstudio</option><option>openai_compatible</option>
        </select>
        <input v-model="newProvider.base_url" placeholder="base_url" />
        <input v-model="newProvider.model" placeholder="model" />
        <input v-model="newProvider.api_key" type="password" placeholder="api key (write-only)" />
        <input v-model="newProvider.key_ref" placeholder="or env var name (key_ref)" />
        <button class="primary" @click="addProvider">Add</button>
      </fieldset>
    </div>

    <div v-else-if="sub === 'manifest' && manifest">
      <p>Status: <strong>{{ manifest.status }}</strong> · Enabled: {{ manifest.enabled ? "yes" : "no" }}</p>
      <p>Languages: {{ (manifest.languages || []).join(", ") }}</p>
      <p>Agents: {{ manifest.default_agent_count }} (min {{ manifest.min_agent_count }}, max {{ manifest.max_agent_count }})</p>
      <p>Memory: {{ manifest.memory_strategy }}</p>
      <button class="primary" @click="activate">Validate &amp; activate</button>
    </div>

    <div v-else-if="sub === 'tiers' && tiers">
      <p class="hint">Max pastors a worshipper may convene, by plan (2–7). Enforced server-side.</p>
      <div class="tier-grid">
        <label v-for="k in ['guest','member','premium']" :key="k">
          {{ k }}
          <input type="number" min="2" max="7" v-model.number="tiers[k]" />
        </label>
      </div>
      <button class="primary" @click="saveTiers">Save tier limits</button>
    </div>

    <div v-else-if="sub === 'sessions' && sessions">
      <table><thead><tr><th>ID</th><th>User</th><th>Lang</th><th>State</th><th>Agents</th></tr></thead>
        <tbody><tr v-for="s in sessions.data" :key="s.id">
          <td>{{ s.id }}</td><td>{{ s.user_id }}</td><td>{{ s.language }}</td><td>{{ s.state }}</td><td>{{ s.agent_count }}</td>
        </tr></tbody>
      </table>
    </div>

    <div v-else-if="sub === 'usage'">
      <table><thead><tr><th>Day</th><th>Turns</th><th>Prompt</th><th>Completion</th></tr></thead>
        <tbody><tr v-for="u in usage" :key="u.day">
          <td>{{ u.day }}</td><td>{{ u.turns }}</td><td>{{ u.prompt_tokens }}</td><td>{{ u.completion_tokens }}</td>
        </tr></tbody>
      </table>
    </div>

    <div v-else-if="sub === 'audit' && audit">
      <table><thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Entity</th></tr></thead>
        <tbody><tr v-for="a in audit.data" :key="a.id">
          <td>{{ a.created_at }}</td><td>{{ a.actor?.name || a.actor_user_id }}</td><td>{{ a.action }}</td><td>{{ a.entity_type }}#{{ a.entity_id }}</td>
        </tr></tbody>
      </table>
    </div>
  </div>
</template>

<style scoped>
.bsa { color: var(--text); }
.bsa table { width: 100%; border-collapse: collapse; }
.bsa th, .bsa td { text-align: left; padding: 0.4rem 0.5rem; border-bottom: 1px solid var(--border); font-size: 0.92em; color: var(--text); }
.bsa th { color: var(--text-muted); font-weight: 600; }
.subtabs { display: flex; flex-wrap: wrap; gap: 0.3rem; margin-bottom: 0.8rem; }
.subtabs button { border: 1px solid var(--border-strong); background: var(--surface-2); color: var(--text); border-radius: var(--radius-sm); padding: 0.3rem 0.7rem; cursor: pointer; font: inherit; text-transform: capitalize; }
.subtabs button.active { background: var(--primary); color: var(--on-primary); border-color: var(--primary); }
.chip { border: 1px solid var(--border-strong); border-radius: 999px; padding: 0.15rem 0.7rem; cursor: pointer; background: var(--surface-2); color: var(--text); font: inherit; }
.primary { background: var(--primary); color: var(--on-primary); border: 0; border-radius: var(--radius-sm); padding: 0.45rem 0.9rem; cursor: pointer; font: inherit; font-weight: 600; }
.primary:hover { background: var(--primary-hover); }
.add { margin-top: 1rem; display: flex; flex-wrap: wrap; gap: 0.4rem; border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 0.8rem; }
.add legend { color: var(--text-muted); padding: 0 0.4rem; }
.add input, .add select { padding: 0.45rem; background: var(--surface-2); color: var(--text); border: 1px solid var(--border); border-radius: var(--radius-sm); font: inherit; }
.ok { color: var(--success); } .err { color: var(--danger); }
.hint { color: var(--text-muted); font-size: 0.9em; }
.tier-grid { display: flex; gap: 1rem; margin: 0.6rem 0; }
.tier-grid label { display: flex; flex-direction: column; gap: 0.2rem; text-transform: capitalize; color: var(--text); }
.tier-grid input { width: 5rem; padding: 0.4rem; background: var(--surface-2); color: var(--text); border: 1px solid var(--border); border-radius: var(--radius-sm); }
</style>
