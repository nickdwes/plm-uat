<script setup lang="ts">
/**
 * PM Task Definitions.
 *
 * The house checklist "Generate PM activities" seeds a project with. Each row
 * is one standard activity, filed under a lifecycle phase; a PM opening a new
 * project generates a task from every active definition. Editable here so the
 * checklist is the organisation's own, not a constant in the code.
 */
import { ref, computed, onMounted } from "vue";
import { useRouter } from "vue-router";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import { notifySaved, notifyFailed } from "../../lib/toast";

interface Definition {
  id: number;
  phase: string | null;
  title: string;
  priority: string;
  weekly_okr: boolean;
  position: number;
  is_active: boolean;
}

const router  = useRouter();
const rows    = ref<Definition[]>([]);
const phases  = ref<string[]>([]);
const loading = ref(true);
const saving  = ref(false);

const org = () => currentOrgSlug.value;
const PRIORITIES = ["low", "medium", "high"];
const titleCase = (v: string) => v.replace(/_/g, " ").replace(/\b\w/g, (m) => m.toUpperCase());

function fail(e: unknown, fallback: string) {
  notifyFailed(fallback, e instanceof ApiError ? (e.firstError ?? e.message) : undefined);
}

async function load() {
  loading.value = true;
  try {
    const res = await api<{ data: Definition[]; phases: string[] }>(
      "GET", "/api/v1/projects/pm-definitions", { org: org() });
    rows.value = res.data;
    phases.value = res.phases;
  } catch (e) { fail(e, "Could not load the PM task definitions."); }
  finally { loading.value = false; }
}

// Grouped by phase in lifecycle order, with anything unfiled last.
const grouped = computed(() => {
  const known = phases.value.map((p) => ({ phase: p, items: rows.value.filter((r) => r.phase === p) }));
  const unfiled = rows.value.filter((r) => !r.phase || !phases.value.includes(r.phase));
  return unfiled.length ? [...known, { phase: "Unfiled", items: unfiled }] : known;
});

const activeCount = computed(() => rows.value.filter((r) => r.is_active).length);

// ── Add ──────────────────────────────────────────────────────────────────
const blankDraft = () => ({ phase: phases.value[0] ?? "", title: "", priority: "medium", weekly_okr: false });
const draft = ref(blankDraft());

async function create() {
  if (!draft.value.title.trim()) return;
  saving.value = true;
  try {
    const res = await api<{ data: Definition }>("POST", "/api/v1/projects/pm-definitions", {
      org: org(), body: { ...draft.value, phase: draft.value.phase || null },
    });
    rows.value.push(res.data);
    draft.value = blankDraft();
    notifySaved("Activity added.");
  } catch (e) { fail(e, "Could not add the activity."); }
  finally { saving.value = false; }
}

// ── Edit / toggle / delete ─────────────────────────────────────────────────
const editing = ref<Definition | null>(null);
const editForm = ref({ phase: "", title: "", priority: "medium", weekly_okr: false, is_active: true });

function openEdit(d: Definition) {
  editing.value = d;
  editForm.value = { phase: d.phase ?? "", title: d.title, priority: d.priority, weekly_okr: d.weekly_okr, is_active: d.is_active };
}

async function saveEdit() {
  const d = editing.value;
  if (!d || !editForm.value.title.trim()) return;
  saving.value = true;
  try {
    const res = await api<{ data: Definition }>("PUT", `/api/v1/projects/pm-definitions/${d.id}`, {
      org: org(), body: { ...editForm.value, phase: editForm.value.phase || null },
    });
    const i = rows.value.findIndex((r) => r.id === d.id);
    if (i !== -1) rows.value[i] = res.data;
    editing.value = null;
    notifySaved("Activity updated.");
  } catch (e) { fail(e, "Could not save the activity."); }
  finally { saving.value = false; }
}

async function toggleActive(d: Definition) {
  try {
    const res = await api<{ data: Definition }>("PUT", `/api/v1/projects/pm-definitions/${d.id}`, {
      org: org(), body: { title: d.title, phase: d.phase, is_active: !d.is_active },
    });
    const i = rows.value.findIndex((r) => r.id === d.id);
    if (i !== -1) rows.value[i] = res.data;
  } catch (e) { fail(e, "Could not update the activity."); }
}

async function remove(d: Definition) {
  if (!confirm(`Remove "${d.title}" from the checklist?`)) return;
  try {
    await api("DELETE", `/api/v1/projects/pm-definitions/${d.id}`, { org: org() });
    rows.value = rows.value.filter((r) => r.id !== d.id);
    notifySaved("Activity removed.");
  } catch (e) { fail(e, "Could not remove the activity."); }
}

onMounted(load);
</script>

<template>
  <div class="pm-wrap">
    <header class="pm-head">
      <div>
        <button class="pm-back" @click="router.push({ name: 'projects-dashboard' })">← Projects Dashboard</button>
        <h1 class="pm-title">PM Task Definitions</h1>
        <p class="pm-sub">
          The standard activities “Generate PM activities” adds to a project.
          {{ activeCount }} active, filed by lifecycle phase.
        </p>
      </div>
    </header>

    <!-- Add a new activity -->
    <form class="pm-add" @submit.prevent="create">
      <label class="pm-field pm-field-phase"><span>Phase</span>
        <select v-model="draft.phase" class="control">
          <option value="">No phase</option>
          <option v-for="p in phases" :key="p" :value="p">{{ p }}</option>
        </select></label>
      <label class="pm-field pm-field-title"><span>Activity</span>
        <input v-model="draft.title" class="control" placeholder="e.g. Confirm scope & charter sign-off" /></label>
      <label class="pm-field"><span>Priority</span>
        <select v-model="draft.priority" class="control">
          <option v-for="p in PRIORITIES" :key="p" :value="p">{{ titleCase(p) }}</option>
        </select></label>
      <label class="pm-check">
        <input v-model="draft.weekly_okr" type="checkbox" /><span>Weekly OKR</span>
      </label>
      <button class="btn-primary" :disabled="saving || !draft.title.trim()">+ Add</button>
    </form>

    <div v-if="loading" class="pm-loading">Loading…</div>

    <template v-else>
      <section v-for="group in grouped" :key="group.phase" class="pm-group">
        <h2 class="pm-group-title">{{ group.phase }} <span class="pm-count">{{ group.items.length }}</span></h2>
        <p v-if="!group.items.length" class="pm-empty">Nothing here yet.</p>

        <ul v-else class="pm-list">
          <li v-for="d in group.items" :key="d.id" :class="['pm-item', { off: !d.is_active }]">
            <div class="pm-item-main">
              <span class="pm-item-title">{{ d.title }}</span>
              <span :class="['pm-prio', `prio-${d.priority}`]">{{ titleCase(d.priority) }}</span>
              <span v-if="d.weekly_okr" class="pm-okr">Weekly OKR</span>
            </div>
            <div class="pm-item-actions">
              <label class="pm-switch" :title="d.is_active ? 'Active — will generate' : 'Inactive — skipped'">
                <input type="checkbox" :checked="d.is_active" @change="toggleActive(d)" />
                <span>{{ d.is_active ? "Active" : "Off" }}</span>
              </label>
              <button class="link-btn" @click="openEdit(d)">Edit</button>
              <button class="link-btn pm-del" @click="remove(d)">Delete</button>
            </div>
          </li>
        </ul>
      </section>
    </template>

    <!-- Edit -->
    <div v-if="editing" class="pm-modal-backdrop" @click.self="editing = null">
      <div class="pm-modal" role="dialog" aria-modal="true" aria-label="Edit activity">
        <div class="pm-modal-head">
          <h3 class="pm-modal-title">Edit activity</h3>
          <button class="pm-close" aria-label="Close" @click="editing = null">×</button>
        </div>
        <label class="pm-field"><span>Activity</span>
          <input v-model="editForm.title" class="control" /></label>
        <label class="pm-field"><span>Phase</span>
          <select v-model="editForm.phase" class="control">
            <option value="">No phase</option>
            <option v-for="p in phases" :key="p" :value="p">{{ p }}</option>
          </select></label>
        <label class="pm-field"><span>Priority</span>
          <select v-model="editForm.priority" class="control">
            <option v-for="p in PRIORITIES" :key="p" :value="p">{{ titleCase(p) }}</option>
          </select></label>
        <label class="pm-check"><input v-model="editForm.weekly_okr" type="checkbox" /><span>Weekly OKR</span></label>
        <label class="pm-check"><input v-model="editForm.is_active" type="checkbox" /><span>Active (included when generating)</span></label>
        <button class="btn-primary btn-block" :disabled="saving || !editForm.title.trim()" @click="saveEdit">Save</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
@reference "../../theme.css";

.pm-wrap { padding: var(--space-6); width: 100%; max-width: 72rem; }
@media (max-width: 40rem) { .pm-wrap { padding: var(--space-4); } }

.pm-head { margin-bottom: var(--space-5); }
.pm-back { @apply cursor-pointer border-none bg-transparent p-0; font-family: inherit; font-size: var(--text-sm-size); color: var(--color-brand-on-surface); margin-bottom: var(--space-2); }
.pm-back:hover { @apply underline; }
.pm-title { font-size: var(--text-xl-size); font-weight: 600; color: var(--color-text); margin: 0; }
.pm-sub   { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: var(--space-1) 0 0; }

.pm-add {
  @apply flex flex-wrap items-end; gap: var(--space-3);
  padding: var(--space-4); margin-bottom: var(--space-5);
  background: var(--color-surface-raised); border: 1px solid var(--color-border); border-radius: var(--radius-lg);
}
.pm-field { @apply flex flex-col; gap: var(--space-1); }
.pm-field > span { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.pm-field-title { flex: 1; min-width: 14rem; }
.pm-field-phase { min-width: 10rem; }
.pm-check { @apply inline-flex items-center; gap: var(--space-2); font-size: var(--text-sm-size); color: var(--color-text); white-space: nowrap; }

.pm-loading { padding: var(--space-6); color: var(--color-text-muted); }

.pm-group { margin-bottom: var(--space-5); }
.pm-group-title { @apply flex items-center; gap: var(--space-2); font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); margin: 0 0 var(--space-2); text-transform: uppercase; letter-spacing: .04em; }
.pm-count { font-size: var(--text-xs-size); font-weight: 500; color: var(--color-text-muted); background: var(--color-surface-sunken); border-radius: 999px; padding: 1px 8px; }
.pm-empty { font-size: var(--text-sm-size); color: var(--color-text-light); font-style: italic; margin: 0 0 var(--space-3); }

.pm-list { @apply flex flex-col; gap: var(--space-2); list-style: none; padding: 0; margin: 0; }
.pm-item {
  @apply flex flex-wrap items-center justify-between; gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
  background: var(--color-surface-raised); border: 1px solid var(--color-border); border-radius: var(--radius-md);
}
.pm-item.off { opacity: 0.55; }
.pm-item-main { @apply flex flex-wrap items-center; gap: var(--space-2); min-width: 0; }
.pm-item-title { font-size: var(--text-sm-size); font-weight: 500; color: var(--color-text); }
.pm-prio { font-size: var(--text-xs-size); padding: 1px 8px; border-radius: 999px; background: var(--color-surface-sunken); }
.prio-high   { color: var(--color-danger-text); }
.prio-medium { color: var(--color-warning-text); }
.prio-low    { color: var(--color-text-muted); }
.pm-okr { font-size: var(--text-xs-size); color: var(--color-brand-on-surface); background: var(--color-brand-subtle); border-radius: 999px; padding: 1px 8px; }

.pm-item-actions { @apply flex items-center; gap: var(--space-3); }
.pm-switch { @apply inline-flex items-center cursor-pointer; gap: 6px; font-size: var(--text-xs-size); color: var(--color-text-muted); }
.pm-del { color: var(--color-danger-text); }

.link-btn { @apply cursor-pointer border-none bg-transparent p-0; font-family: inherit; font-size: var(--text-sm-size); color: var(--color-brand-on-surface); }
.link-btn:hover { @apply underline; }

.control {
  font-family: inherit; font-size: var(--text-sm-size);
  padding: 8px var(--space-3); border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-md); background: var(--color-surface); color: var(--color-text); width: 100%;
}
.control:focus { @apply outline-none; border-color: var(--color-brand-base); box-shadow: 0 0 0 3px var(--color-brand-subtle); }

.btn-primary {
  @apply inline-flex cursor-pointer items-center justify-center border-none;
  gap: var(--space-2); padding: 8px var(--space-4); border-radius: var(--radius-md);
  font-family: inherit; font-size: var(--text-sm-size); font-weight: 500; white-space: nowrap;
  background: var(--color-brand-base); color: var(--color-on-brand);
}
.btn-primary:disabled { @apply cursor-not-allowed opacity-50; }
.btn-block { width: 100%; }

.pm-modal-backdrop { @apply fixed inset-0 flex items-center justify-center z-50; background: rgb(0 0 0 / 0.3); padding: var(--space-4); }
.pm-modal { @apply flex flex-col w-full; max-width: 28rem; gap: var(--space-3); padding: var(--space-5); background: var(--color-surface-raised); border-radius: var(--radius-lg); box-shadow: var(--shadow-lg); }
.pm-modal-head { @apply flex items-center justify-between; }
.pm-modal-title { font-size: var(--text-base-size); font-weight: 600; margin: 0; color: var(--color-text); }
.pm-close { @apply cursor-pointer border-none bg-transparent p-0; font-size: 1.5rem; line-height: 1; color: var(--color-text-muted); }
</style>
