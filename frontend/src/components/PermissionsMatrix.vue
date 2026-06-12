<template>
  <div class="perm-wrap">
    <div class="perm-header">
      <h2>Staff Permissions</h2>
      <p class="perm-desc">
        Control which actions each staff role can perform in the console.
        Admin always has full access and cannot be restricted here.
      </p>
    </div>

    <div class="perm-table-wrap">
      <table class="perm-table">
        <thead>
          <tr>
            <th class="feat-col">Feature</th>
            <th class="action-col">Action</th>
            <th v-for="role in roles" :key="role" class="role-col">
              {{ roleLabel(role) }}
            </th>
          </tr>
        </thead>
        <tbody>
          <template v-for="group in FEATURE_GROUPS" :key="group.label">
            <tr v-for="(perm, pi) in group.perms" :key="perm.key">
              <td v-if="pi === 0" :rowspan="group.perms.length" class="feat-cell">
                {{ group.label }}
              </td>
              <td class="action-cell">{{ perm.label }}</td>
              <td v-for="role in roles" :key="role" class="check-cell">
                <label class="cb-wrap">
                  <input
                    type="checkbox"
                    :checked="has(role, perm.key)"
                    @change="toggle(role, perm.key)"
                    class="cb"
                  />
                  <span class="cb-mark"></span>
                </label>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>

    <div class="perm-actions">
      <button class="save-btn" :disabled="saving" @click="save">
        {{ saving ? "Saving…" : "Save Permissions" }}
      </button>
      <span v-if="saveMsg" class="save-msg" :class="saveOk ? 'ok' : 'err'">{{ saveMsg }}</span>
    </div>
  </div>
</template>

<script setup>
import { ref, watch } from "vue";
import { api } from "../composables/useApi";

const props = defineProps({
  initialData: { type: Object, default: null },
});

const FEATURE_GROUPS = [
  {
    label: "Dashboard",
    perms: [{ key: "dashboard.view", label: "View" }],
  },
  {
    label: "Services",
    perms: [
      { key: "services.view",   label: "View" },
      { key: "services.retry",  label: "Retry" },
      { key: "services.delete", label: "Delete" },
    ],
  },
  {
    label: "Testimonies",
    perms: [
      { key: "testimonies.view",    label: "View" },
      { key: "testimonies.approve", label: "Approve" },
      { key: "testimonies.delete",  label: "Delete" },
    ],
  },
  {
    label: "Prayer Requests",
    perms: [{ key: "prayer_requests.view", label: "View" }],
  },
  {
    label: "Donors",
    perms: [{ key: "donors.view", label: "View" }],
  },
  {
    label: "Voice Studio",
    perms: [{ key: "voice_studio.view", label: "View" }],
  },
];

const ROLE_LABELS = { moderator: "Moderator", presenter: "Presenter" };
const roleLabel = (r) => ROLE_LABELS[r] || r;

const roles = ref(["moderator", "presenter"]);
const local = ref({ moderator: [], presenter: [] });
const saving = ref(false);
const saveMsg = ref("");
const saveOk  = ref(true);

function initFromData(data) {
  if (!data?.permissions) return;
  local.value = {
    moderator: [...(data.permissions.moderator ?? [])],
    presenter: [...(data.permissions.presenter ?? [])],
  };
  if (data.roles) roles.value = data.roles;
}

watch(() => props.initialData, initFromData, { immediate: true });

function has(role, perm) {
  return local.value[role]?.includes(perm) ?? false;
}

function toggle(role, perm) {
  if (!local.value[role]) local.value[role] = [];
  const idx = local.value[role].indexOf(perm);
  if (idx === -1) {
    local.value[role].push(perm);
  } else {
    local.value[role].splice(idx, 1);
  }
}

async function save() {
  saving.value = true;
  saveMsg.value = "";
  try {
    await api.adminUpdatePermissions({ ...local.value });
    saveMsg.value = "Permissions saved.";
    saveOk.value  = true;
  } catch (e) {
    saveMsg.value = e?.data?.message || "Could not save.";
    saveOk.value  = false;
  } finally {
    saving.value = false;
    setTimeout(() => { saveMsg.value = ""; }, 3000);
  }
}
</script>

<style scoped>
.perm-wrap { max-width: 700px; }

.perm-header { margin-bottom: 1.25rem; }
.perm-header h2 { font-size: 1.05rem; margin: 0 0 .3rem; }
.perm-desc { color: var(--text-muted); font-size: .875rem; line-height: 1.5; margin: 0; }

.perm-table-wrap {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--radius); overflow-x: auto; box-shadow: var(--shadow-sm);
}

.perm-table { width: 100%; border-collapse: collapse; }
.perm-table th, .perm-table td {
  padding: .6rem .85rem; border-bottom: 1px solid var(--border);
  font-size: .875rem; text-align: left;
}
.perm-table th {
  font-size: .75rem; font-weight: 600; text-transform: uppercase;
  letter-spacing: .04em; color: var(--text-muted); background: var(--surface-2, var(--surface));
}
.perm-table tr:last-child td { border-bottom: 0; }

.feat-col   { width: 140px; }
.action-col { width: 120px; }
.role-col   { width: 110px; text-align: center; }

.feat-cell {
  font-weight: 600; font-size: .82rem; color: var(--text);
  vertical-align: top; padding-top: .75rem; border-right: 1px solid var(--border);
}
.action-cell { color: var(--text-muted); font-size: .85rem; }
.check-cell  { text-align: center; }

.cb-wrap {
  display: inline-flex; align-items: center; justify-content: center;
  cursor: pointer; position: relative; width: 20px; height: 20px;
}
.cb { position: absolute; opacity: 0; width: 100%; height: 100%; cursor: pointer; margin: 0; }
.cb-mark {
  width: 16px; height: 16px; border: 1.5px solid var(--border-strong, #aaa);
  border-radius: 4px; background: var(--surface); transition: all .12s;
  display: flex; align-items: center; justify-content: center;
}
.cb:checked + .cb-mark {
  background: var(--primary, #2563eb); border-color: var(--primary, #2563eb);
}
.cb:checked + .cb-mark::after {
  content: "✓"; color: #fff; font-size: .7rem; font-weight: 700; line-height: 1;
}
.cb:focus + .cb-mark { box-shadow: 0 0 0 3px var(--primary-soft, rgba(37,99,235,.15)); }

.perm-actions { display: flex; align-items: center; gap: 1rem; margin-top: 1.25rem; }
.save-btn {
  padding: .55rem 1.4rem; border-radius: var(--radius-sm, 6px); border: none;
  background: var(--primary, #2563eb); color: var(--on-primary, #fff);
  font-weight: 600; font-size: .9rem; cursor: pointer;
}
.save-btn:hover:not(:disabled) { background: var(--primary-hover, #1d4ed8); }
.save-btn:disabled { opacity: .6; cursor: default; }

.save-msg { font-size: .875rem; }
.save-msg.ok  { color: var(--success, #16a34a); }
.save-msg.err { color: var(--danger, #dc2626); }
</style>
