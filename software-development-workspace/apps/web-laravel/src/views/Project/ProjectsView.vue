<script setup lang="ts">
/**
 * Projects.
 *
 * Two views of one list, as the design has it. The grid is for scanning — how
 * far along, who is on it, when it is due. The list is for comparing, which a
 * grid of cards is bad at.
 *
 * Progress is null, not zero, when nothing has been planned. An unplanned
 * project shows "not planned" rather than an empty bar, because a bar at zero
 * claims work has started and stalled.
 */
import { ref, computed, onMounted } from "vue";
import { useRouter } from "vue-router";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import { notifySaved, notifyFailed } from "../../lib/toast";
import EmployeePicker from "../../components/EmployeePicker.vue";
import AccountPicker from "../../components/AccountPicker.vue";
import { excerpt, hasText } from "../../lib/richText";

interface Person { id: number | null; name: string | null }
interface Project {
  id: number; reference: string | null; name: string; description: string | null;
  status: string; priority: string; deadline: string | null;
  progress: number | null; task_count?: number;
  is_overdue: boolean; was_late: boolean | null;
  account?: { id: number; name: string; initials: string } | null;
  opportunity?: { id: number; reference: string | null; name: string } | null;
  project_lead?: Person | null;
  team?: { id: number; employee: Person }[];
}

const router  = useRouter();
const view    = ref<"grid" | "list">("grid");
const rows    = ref<Project[]>([]);
const loading = ref(true);
const saving  = ref(false);
const search  = ref("");
const state   = ref("running");
const showFilters = ref(false);

const org = () => currentOrgSlug.value;

function fail(e: unknown, fallback: string) {
  notifyFailed(fallback, e instanceof ApiError ? (e.firstError ?? e.message) : undefined);
}

async function load() {
  loading.value = true;
  try {
    rows.value = (await api<{ data: Project[] }>("GET", "/api/v1/projects", {
      org: org(),
      params: { state: state.value || undefined, search: search.value || undefined, per_page: 100 },
    })).data;
  } catch (e) { fail(e, "Could not load projects."); }
  finally { loading.value = false; }
}

// ── Creating ────────────────────────────────────────────────────────────────

const showNew = ref(false);
const blank = () => ({
  name: "", deadline: "", priority: "medium",
  project_lead_id: null as number | null, account_id: null as number | null,
});
const form = ref(blank());

async function create() {
  if (!form.value.name.trim()) return;
  saving.value = true;
  try {
    const res = await api<{ data: Project }>("POST", "/api/v1/projects", {
      org: org(), body: { ...form.value, deadline: form.value.deadline || null },
    });
    showNew.value = false;
    form.value = blank();
    notifySaved(`${res.data.name} created.`);
    router.push({ name: "project", params: { id: res.data.id } });
  } catch (e) { fail(e, "Could not create the project."); }
  finally { saving.value = false; }
}

// ── Presentation ────────────────────────────────────────────────────────────

const STATES = [
  { key: "running", label: "Ongoing" },
  { key: "overdue", label: "Overdue" },
  { key: "delivered", label: "Delivered" },
  { key: "", label: "All" },
];

const date = (v: string | null) =>
  v ? new Date(v).toLocaleDateString(undefined, { day: "2-digit", month: "short" }) : "—";

const titleCase = (v: string) => v.replace(/_/g, " ").replace(/\b\w/g, (m) => m.toUpperCase());

const initials = (name: string | null | undefined) =>
  (name ?? "?").split(/\s+/).slice(0, 2).map((w) => w[0]).join("").toUpperCase();

/** The bar's colour carries the same warning the row does. */
const progressTone = (p: Project) =>
  p.is_overdue ? "late" : (p.progress ?? 0) >= 100 ? "done" : (p.progress ?? 0) >= 50 ? "on" : "early";

const ongoing = computed(() =>
  rows.value.filter((p) => p.status !== "delivered" && p.status !== "cancelled").length);

onMounted(load);
</script>

<template>
  <div class="pj-wrap">
    <header class="pj-header">
      <div>
        <h1 class="pj-title">Projects</h1>
        <p class="pj-sub">
          You have {{ ongoing }} ongoing project{{ ongoing === 1 ? "" : "s" }}.
          Stay focused and complete them on time
        </p>
      </div>
      <button class="btn-primary" @click="form = blank(); showNew = true">
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <path d="M10 4v12M4 10h12" />
        </svg>
        Create Project
      </button>
    </header>

    <nav class="pj-views" role="tablist">
      <button role="tab" :aria-selected="view === 'grid'"
              :class="['pj-view', { active: view === 'grid' }]" @click="view = 'grid'">
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6">
          <rect x="3" y="3" width="6" height="6" rx="1" /><rect x="11" y="3" width="6" height="6" rx="1" />
          <rect x="3" y="11" width="6" height="6" rx="1" /><rect x="11" y="11" width="6" height="6" rx="1" />
        </svg>
        Grid
      </button>
      <button role="tab" :aria-selected="view === 'list'"
              :class="['pj-view', { active: view === 'list' }]" @click="view = 'list'">
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round">
          <path d="M4 6h12M4 10h12M4 14h8" />
        </svg>
        List view
      </button>
    </nav>

    <div class="pj-toolbar">
      <div class="pj-search-wrap">
        <svg class="pj-search-icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7">
          <circle cx="9" cy="9" r="6" /><path d="M13.5 13.5L17 17" stroke-linecap="round" />
        </svg>
        <input v-model="search" class="control pj-search" type="search" placeholder="Search" @keyup.enter="load" />
      </div>
      <button class="btn-ghost" :class="{ 'btn-on': state !== 'running' }" @click="showFilters = !showFilters">
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round">
          <path d="M3 5h14M6 10h8M8.5 15h3" />
        </svg>
        Filter
      </button>
    </div>

    <div v-if="showFilters" class="pj-chips">
      <button v-for="s in STATES" :key="s.key"
              :class="['pj-chip', { active: state === s.key }]"
              @click="state = s.key; load()">{{ s.label }}</button>
    </div>

    <div v-if="loading" class="pj-loading">Loading…</div>

    <div v-else-if="rows.length === 0" class="pj-empty">
      <h2 class="pj-empty-title">No projects here</h2>
      <p class="pj-empty-sub">A project usually starts from a won deal, at its kickoff stage.</p>
      <button class="btn-primary" @click="form = blank(); showNew = true">Create Project</button>
    </div>

    <!-- ── Grid ──────────────────────────────────────────────────────── -->
    <div v-else-if="view === 'grid'" class="pj-grid">
      <article v-for="p in rows" :key="p.id" class="pj-card"
               @click="router.push({ name: 'project', params: { id: p.id } })">
        <span :class="['pj-priority', `prio-${p.priority}`]">
          <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round">
            <path d="M5 3v14M5 4h9l-2 3 2 3H5" />
          </svg>
          {{ titleCase(p.priority) }} priority
        </span>

        <h3 class="pj-card-title">{{ p.name }}</h3>
        <!-- A card is an excerpt, not a document. The description is HTML from the
             old system's editor, much of it pasted from Word — rendered here it
             showed its own tags, and one 3KB description stretched a card past a
             thousand pixels and broke the grid row it sat in. -->
        <p v-if="hasText(p.description)" class="pj-card-sub">{{ excerpt(p.description) }}</p>
        <p v-else-if="p.account" class="pj-card-sub">For {{ p.account.name }}</p>

        <!-- Null progress is said, not drawn as an empty bar: a bar at zero
             claims work has started and stalled. -->
        <div v-if="p.progress === null" class="pj-unplanned">Not planned yet</div>
        <div v-else class="pj-progress">
          <span class="pj-bar"><span :class="['pj-bar-fill', `tone-${progressTone(p)}`]"
                                     :style="{ width: `${p.progress}%` }"></span></span>
          <span class="pj-progress-value">{{ p.progress }}%</span>
        </div>

        <footer class="pj-card-foot">
          <span v-if="p.team?.length" class="pj-stack">
            <span v-for="m in p.team.slice(0, 4)" :key="m.id" class="pj-avatar"
                  :title="m.employee.name ?? ''">{{ initials(m.employee.name) }}</span>
            <span v-if="p.team.length > 4" class="pj-avatar more">+{{ p.team.length - 4 }}</span>
          </span>
          <span v-else class="pj-muted">Nobody assigned</span>

          <span class="pj-card-meta">
            <span v-if="p.task_count !== undefined" class="pj-meta-item">☰ {{ p.task_count }}</span>
            <span :class="['pj-meta-item', { late: p.is_overdue }]">📅 {{ date(p.deadline) }}</span>
          </span>
        </footer>
      </article>
    </div>

    <!-- ── List ──────────────────────────────────────────────────────── -->
    <div v-else class="pj-panel">
      <table class="pj-table">
        <thead>
          <tr><th>Project</th><th>Progress</th><th>Assignee</th><th>Due Date</th><th>Priority</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <tr v-for="p in rows" :key="p.id">
            <td class="pj-name">{{ p.name }}</td>
            <td>
              <span v-if="p.progress === null" class="pj-muted">Not planned</span>
              <span v-else class="pj-ring">
                <svg viewBox="0 0 36 36" aria-hidden="true">
                  <circle class="pj-ring-track" cx="18" cy="18" r="15" />
                  <circle :class="['pj-ring-fill', `tone-${progressTone(p)}`]" cx="18" cy="18" r="15"
                          :stroke-dasharray="2 * Math.PI * 15"
                          :stroke-dashoffset="2 * Math.PI * 15 * (1 - p.progress / 100)" />
                </svg>
                <span class="pj-ring-value">{{ p.progress }}%</span>
              </span>
            </td>
            <td>
              <span v-if="!p.team?.length" class="pj-muted">—</span>
              <span v-else class="pj-stack">
                <span v-for="m in p.team.slice(0, 4)" :key="m.id" class="pj-avatar"
                      :title="m.employee.name ?? ''">{{ initials(m.employee.name) }}</span>
                <span v-if="p.team.length > 4" class="pj-avatar more">+{{ p.team.length - 4 }}</span>
              </span>
            </td>
            <td :class="['pj-nowrap', { 'pj-late': p.is_overdue }]">{{ date(p.deadline) }}</td>
            <td>
              <span :class="['pj-priority', `prio-${p.priority}`]">
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round">
                  <path d="M5 3v14M5 4h9l-2 3 2 3H5" />
                </svg>
                {{ titleCase(p.priority) }} priority
              </span>
            </td>
            <td><button class="link-btn" @click="router.push({ name: 'project', params: { id: p.id } })">View</button></td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- New project -->
    <div v-if="showNew" class="pj-sheet-backdrop" @click.self="showNew = false">
      <aside class="pj-sheet" role="dialog" aria-modal="true" aria-label="New project">
        <div class="pj-sheet-head">
          <h3 class="pj-sheet-title">New Project</h3>
          <button class="pj-close" aria-label="Close" @click="showNew = false">×</button>
        </div>

        <label class="pj-field"><span>Project Name</span>
          <input v-model="form.name" class="control" placeholder="Employee Portal Revamp" /></label>

        <label class="pj-field"><span>Project Deadline</span>
          <input v-model="form.deadline" class="control" type="date" /></label>

        <label class="pj-field"><span>Project Priority</span>
          <select v-model="form.priority" class="control">
            <option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option>
          </select></label>

        <div class="pj-field"><span>Project Lead</span>
          <EmployeePicker v-model="form.project_lead_id" placeholder="Who is running it?" /></div>

        <div class="pj-field"><span>Customer</span>
          <AccountPicker v-model="form.account_id" placeholder="Which customer, if any?" />
          <span class="pj-hint">
            Optional here. A project created from a deal's kickoff already knows.
          </span></div>

        <button class="btn-primary btn-block" :disabled="saving || !form.name.trim()" @click="create">
          {{ saving ? "Creating…" : "Create Project" }}
        </button>
      </aside>
    </div>
  </div>
</template>

<style scoped>
@reference "../../theme.css";

.pj-wrap { padding: var(--space-6); max-width: 86rem; }

.pj-header { @apply flex flex-wrap items-start justify-between; gap: var(--space-4); margin-bottom: var(--space-4); }
.pj-title { font-size: var(--text-xl-size); font-weight: 600; color: var(--color-text); margin: 0; }
.pj-sub   { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: var(--space-1) 0 0; }

.pj-views { @apply flex gap-1; border-bottom: 1px solid var(--color-border); margin-bottom: var(--space-4); }
.pj-view {
  @apply inline-flex cursor-pointer items-center border-none bg-transparent;
  gap: var(--space-2); padding: var(--space-2) var(--space-3);
  font-family: inherit; font-size: var(--text-sm-size); color: var(--color-text-muted);
  border-bottom: 2px solid transparent; margin-bottom: -1px;
}
.pj-view svg { width: 1rem; height: 1rem; }
.pj-view:hover { color: var(--color-text); }
.pj-view.active { color: var(--color-brand-on-surface); border-bottom-color: var(--color-brand-base); font-weight: 500; }

.pj-toolbar { @apply flex flex-wrap items-center justify-end; gap: var(--space-3); margin-bottom: var(--space-4); }
.pj-search-wrap { @apply relative; flex: 1; min-width: 12rem; max-width: 26rem; margin-left: auto; }
.pj-search-icon { @apply absolute; width: 1rem; height: 1rem; left: var(--space-3); top: 50%; transform: translateY(-50%); color: var(--color-text-light); pointer-events: none; }
.pj-search { padding-left: 2.25rem; }

.pj-chips { @apply flex flex-wrap; gap: var(--space-2); margin-bottom: var(--space-4); }
.pj-chip {
  @apply cursor-pointer; padding: 6px var(--space-4); border-radius: 999px;
  border: 1px solid var(--color-border-strong); background: var(--color-surface);
  font-family: inherit; font-size: var(--text-sm-size); color: var(--color-text-muted);
}
.pj-chip.active { background: var(--color-brand-base); color: var(--color-on-brand); border-color: var(--color-brand-base); }

.pj-loading { padding: var(--space-6); color: var(--color-text-muted); }
.pj-empty { @apply flex flex-col items-center; padding: var(--space-8) var(--space-6); text-align: center; gap: var(--space-2); }
.pj-empty-title { font-size: var(--text-lg-size); font-weight: 600; color: var(--color-text); margin: 0; }
.pj-empty-sub { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: 0 0 var(--space-3); }

/* ── Grid ──────────────────────────────────────────────────────────────── */
.pj-grid { @apply grid; grid-template-columns: repeat(auto-fill, minmax(19rem, 1fr)); gap: var(--space-4); }
.pj-card {
  @apply flex cursor-pointer flex-col; gap: var(--space-2);
  padding: var(--space-4);
  background: var(--color-surface-raised);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
}
.pj-card:hover { border-color: var(--color-border-strong); box-shadow: var(--shadow-sm); }
.pj-card-title { font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); margin: 0; }
.pj-card-sub {
  font-size: var(--text-xs-size); color: var(--color-text-muted); margin: 0; line-height: 1.5;
  /* Three lines, whatever arrives. Without this one imported description
     decides how tall every card in its row is. */
  display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 3;
  overflow: hidden; overflow-wrap: anywhere;
}
.pj-card-foot { @apply flex flex-wrap items-center justify-between; gap: var(--space-2); padding-top: var(--space-2); border-top: 1px solid var(--color-border); }
.pj-card-meta { @apply inline-flex items-center; gap: var(--space-3); }
.pj-meta-item { font-size: var(--text-xs-size); color: var(--color-text-light); white-space: nowrap; }
.pj-meta-item.late { color: var(--color-danger-text); }

.pj-progress { @apply flex items-center; gap: var(--space-3); }
.pj-bar { @apply block; flex: 1; height: 5px; border-radius: 999px; background: var(--color-surface-sunken); overflow: hidden; }
.pj-bar-fill { @apply block; height: 100%; border-radius: 999px; }
.pj-progress-value { font-size: var(--text-xs-size); color: var(--color-text-muted); font-variant-numeric: tabular-nums; }
.tone-early { background: var(--color-brand-base); stroke: var(--color-brand-base); }
.tone-on    { background: var(--color-warning-text); stroke: var(--color-warning-text); }
.tone-done  { background: var(--color-success-text); stroke: var(--color-success-text); }
.tone-late  { background: var(--color-danger-text); stroke: var(--color-danger-text); }
.pj-unplanned { font-size: var(--text-xs-size); color: var(--color-text-light); font-style: italic; }

.pj-priority { @apply inline-flex items-center; gap: 4px; font-size: var(--text-xs-size); white-space: nowrap; }
.pj-priority svg { width: 0.75rem; height: 0.75rem; }
.prio-high   { color: var(--color-danger-text); font-weight: 500; }
.prio-medium { color: var(--color-warning-text); }
.prio-low    { color: var(--color-text-muted); }

/* ── List ──────────────────────────────────────────────────────────────── */
.pj-panel {
  background: var(--color-surface-raised);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  overflow-x: auto;
}
.pj-table { @apply w-full; border-collapse: collapse; font-size: var(--text-sm-size); }
.pj-table th {
  text-align: left; padding: var(--space-3) var(--space-4);
  font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-muted);
  background: var(--color-surface-sunken);
  border-bottom: 1px solid var(--color-border); white-space: nowrap;
}
.pj-table td { padding: var(--space-3) var(--space-4); border-bottom: 1px solid var(--color-border); color: var(--color-text); }
.pj-table tr:last-child td { border-bottom: none; }
.pj-name { font-weight: 500; }
.pj-nowrap { white-space: nowrap; }
.pj-late { color: var(--color-danger-text); }
.pj-muted { color: var(--color-text-light); font-size: var(--text-xs-size); }

.pj-ring { @apply relative inline-flex items-center justify-center; width: 2.5rem; height: 2.5rem; }
.pj-ring svg { width: 100%; height: 100%; transform: rotate(-90deg); }
.pj-ring-track { fill: none; stroke: var(--color-surface-sunken); stroke-width: 3; }
.pj-ring-fill  { fill: none; stroke-width: 3; stroke-linecap: round; }
.pj-ring-value { @apply absolute; font-size: 0.55rem; font-weight: 600; color: var(--color-text); }

.pj-stack { @apply inline-flex items-center; }
.pj-avatar {
  @apply inline-flex items-center justify-center;
  width: 1.5rem; height: 1.5rem; margin-right: -0.35rem; border-radius: 999px;
  background: var(--color-surface-sunken); color: var(--color-text-muted);
  border: 2px solid var(--color-surface-raised);
  font-size: 0.6rem; font-weight: 600;
}
.pj-avatar.more { background: var(--color-brand-subtle); color: var(--color-brand-on-surface); }

/* ── Shared ────────────────────────────────────────────────────────────── */
.pj-field { @apply flex flex-col; gap: var(--space-1); }
.pj-field > span { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.pj-hint { font-size: var(--text-xs-size); color: var(--color-text-light); }

.control {
  font-family: inherit; font-size: var(--text-sm-size);
  padding: 8px var(--space-3);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-md);
  background: var(--color-surface); color: var(--color-text); width: 100%;
}
.control:focus { @apply outline-none; border-color: var(--color-brand-base); box-shadow: 0 0 0 3px var(--color-brand-subtle); }

.link-btn { @apply cursor-pointer border-none bg-transparent p-0; font-family: inherit; font-size: var(--text-sm-size); color: var(--color-brand-on-surface); }
.link-btn:hover { @apply underline; }

.pj-sheet-backdrop { @apply fixed inset-0 flex justify-end z-50; background: rgb(0 0 0 / 0.28); }
.pj-sheet {
  @apply flex flex-col overflow-y-auto; width: min(26rem, 100%); gap: var(--space-4);
  padding: var(--space-5); background: var(--color-surface-raised); box-shadow: var(--shadow-lg);
  animation: sheet-in 180ms ease;
}
@keyframes sheet-in { from { transform: translateX(12px); opacity: 0; } }
@media (prefers-reduced-motion: reduce) { .pj-sheet { animation: none; } }
.pj-sheet-head { @apply flex items-center justify-between; }
.pj-sheet-title { font-size: var(--text-base-size); font-weight: 600; margin: 0; color: var(--color-text); }
.pj-close { @apply cursor-pointer border-none bg-transparent p-0; font-size: 1.5rem; line-height: 1; color: var(--color-text-muted); }

.btn-primary, .btn-ghost {
  @apply inline-flex cursor-pointer items-center justify-center;
  gap: var(--space-2); padding: 8px var(--space-4); border-radius: var(--radius-md);
  font-family: inherit; font-size: var(--text-sm-size); font-weight: 500; white-space: nowrap;
}
.btn-primary { @apply border-none; background: var(--color-brand-base); color: var(--color-on-brand); }
.btn-ghost   { background: var(--color-surface); color: var(--color-text); border: 1px solid var(--color-border-strong); }
.btn-on      { border-color: var(--color-brand-base); color: var(--color-brand-on-surface); }
.btn-block   { width: 100%; }
.btn-primary svg, .btn-ghost svg { width: 1rem; height: 1rem; }
.btn-primary:disabled { @apply cursor-not-allowed opacity-50; }
</style>
