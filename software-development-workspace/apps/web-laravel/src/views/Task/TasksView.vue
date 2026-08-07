<script setup lang="ts">
/**
 * Tasks — across the whole system, not one project's.
 *
 * The legacy carried 9,055 of these and only a quarter had a project; the rest
 * were support, training, meetings and plain internal work. So this is a list
 * of work, filtered by what kind it is, rather than a project sub-screen.
 *
 * Two things sit above the list because the legacy lost them:
 *
 *   The inbox. 261 of its 354 delegations were never answered, because nothing
 *   ever told somebody they had been asked. It leads, and it carries a count.
 *
 *   Overdue. A state filter rather than a status, because "past its deadline
 *   and still open" is the question people arrive with and no single status
 *   value answers it.
 */
import { ref, computed, onMounted } from "vue";
import { useRouter } from "vue-router";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import { notifySaved, notifyFailed } from "../../lib/toast";
import EmployeePicker from "../../components/EmployeePicker.vue";

interface Person { id: number | null; name: string | null }
interface TaskRow {
  id: number; reference: string | null; title: string;
  category: string; status: string; priority: string;
  deadline: string | null; started_on: string | null;
  is_done: boolean; is_overdue: boolean; is_blocked: boolean;
  effort_estimate_days: number | string | null;
  elapsed_days: number | null;
  effort_variance: number | string | null;
  assignee: Person | null;
  project: { id: number; name: string } | null;
}
interface Ask {
  id: number; kind: string; status: string; is_stale: boolean;
  request_comment: string | null;
  to: Person | null; requester: Person | null;
  task: { id: number; reference: string | null; title: string } | null;
}

const router  = useRouter();
const rows    = ref<TaskRow[]>([]);
const loading = ref(true);
const saving  = ref(false);
const search  = ref("");

const incoming = ref<Ask[]>([]);
const outgoing = ref<Ask[]>([]);

const state    = ref("open");
const category = ref("");

const org = () => currentOrgSlug.value;

function fail(e: unknown, fallback: string) {
  notifyFailed(fallback, e instanceof ApiError ? (e.firstError ?? e.message) : undefined);
}

async function load() {
  loading.value = true;
  try {
    rows.value = (await api<{ data: TaskRow[] }>("GET", "/api/v1/tasks", {
      org: org(),
      params: {
        search: search.value || undefined,
        state: state.value || undefined,
        category: category.value || undefined,
        per_page: 100,
      },
    })).data;
  } catch (e) { fail(e, "Could not load tasks."); }
  finally { loading.value = false; }
}

async function loadInbox() {
  try {
    const res = await api<{ incoming: Ask[]; outgoing: Ask[] }>("GET", "/api/v1/tasks/inbox", { org: org() });
    incoming.value = res.incoming;
    outgoing.value = res.outgoing;
  } catch (e) { fail(e, "Could not load your requests."); }
}

// ── The inbox ───────────────────────────────────────────────────────────────

const showInbox = ref(false);

/**
 * Answering is done here rather than on the task, because the whole failure
 * being fixed is that nobody went looking. If you have to open the task to
 * accept, you are back where the legacy was.
 */
async function respond(ask: Ask, status: "accepted" | "declined") {
  if (!ask.task) return;
  try {
    await api("POST", `/api/v1/tasks/${ask.task.id}/requests/${ask.id}/respond`, {
      org: org(), body: { status },
    });
    notifySaved(status === "accepted" ? "Accepted." : "Declined.");
    await Promise.all([loadInbox(), load()]);
  } catch (e) { fail(e, "Could not answer the request."); }
}

// ── Creating ────────────────────────────────────────────────────────────────

const showNew = ref(false);
const blank = () => ({
  title: "", description: "", category: "internal", priority: "medium",
  deadline: "", effort_estimate_days: "" as string | number,
  assigned_to: null as number | null,
});
const form = ref(blank());

async function create() {
  if (!form.value.title.trim()) return;
  saving.value = true;
  try {
    const res = await api<{ data: TaskRow }>("POST", "/api/v1/tasks", {
      org: org(),
      body: {
        ...form.value,
        deadline: form.value.deadline || null,
        effort_estimate_days: form.value.effort_estimate_days === "" ? null : form.value.effort_estimate_days,
      },
    });
    showNew.value = false;
    form.value = blank();
    notifySaved(`${res.data.reference ?? "Task"} raised.`);
    router.push({ name: "task", params: { id: res.data.id } });
  } catch (e) { fail(e, "Could not raise the task."); }
  finally { saving.value = false; }
}

// ── Presentation ────────────────────────────────────────────────────────────

const STATES = [
  { key: "open",    label: "Open" },
  { key: "overdue", label: "Overdue" },
  { key: "mine",    label: "Mine" },
  { key: "",        label: "All" },
];

/** The legacy's twelve, which were the module map as much as a filter. */
const CATEGORIES = [
  "opportunity", "project", "support", "meeting", "training", "certificate",
  "research", "collaboration", "internal", "ticket", "account", "leave",
];

const PRIORITIES = ["low", "medium", "high"];

const statusTone = (s: string) =>
  s === "completed" ? "ok" : s === "blocked" ? "bad" : s === "in_progress" ? "info"
    : s === "awaiting_approval" ? "warn" : "neutral";

const date = (v: string | null) =>
  v ? new Date(v).toLocaleDateString(undefined, { day: "2-digit", month: "short", year: "numeric" }) : "—";

const titleCase = (v: string) => v.replace(/_/g, " ").replace(/\b\w/g, (m) => m.toUpperCase());

const initials = (name: string | null | undefined) =>
  (name ?? "?").split(/\s+/).slice(0, 2).map((w) => w[0]).join("").toUpperCase();

/**
 * Over or under the estimate, in days.
 *
 * Shown only where there is an estimate to compare against — no estimate is
 * not a variance of zero, and rendering it as one would say the work came in
 * exactly on plan when nobody ever said what the plan was.
 */
function variance(t: TaskRow): { text: string; tone: string } | null {
  if (t.effort_variance === null || t.effort_variance === undefined) return null;
  const v = Number(t.effort_variance);
  if (v === 0) return { text: "On estimate", tone: "ok" };
  return v > 0
    ? { text: `${v.toFixed(1)}d over`, tone: "bad" }
    : { text: `${Math.abs(v).toFixed(1)}d under`, tone: "ok" };
}

const pending = computed(() => incoming.value.length);
const overdue = computed(() => rows.value.filter((r) => r.is_overdue).length);

onMounted(() => { load(); loadInbox(); });
</script>

<template>
  <div class="tk-wrap">
    <header class="tk-header">
      <div>
        <h1 class="tk-title">Tasks</h1>
        <p class="tk-sub">
          Everything anyone owes, whatever it is attached to.
          <template v-if="!loading && overdue"> {{ overdue }} past its deadline.</template>
        </p>
      </div>
      <div class="tk-actions">
        <!-- Ahead of the list, and counted, because being asked and never
             seeing it is what went wrong before. -->
        <button :class="['btn-ghost', { 'has-pending': pending }]" @click="showInbox = true">
          <svg viewBox="0 0 20 20" fill="none" stroke="currentColor"
               stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 5.5h14v9H3z" /><path d="M3 6l7 5 7-5" />
          </svg>
          Requests
          <span v-if="pending" class="tk-badge">{{ pending }}</span>
        </button>
        <button class="btn-primary" @click="form = blank(); showNew = true">
          <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <path d="M10 4v12M4 10h12" />
          </svg>
          New Task
        </button>
      </div>
    </header>

    <div class="tk-chips">
      <button v-for="s in STATES" :key="s.key"
              :class="['tk-chip', { active: state === s.key }]"
              @click="state = s.key; load()">{{ s.label }}</button>
    </div>

    <div class="tk-toolbar">
      <select v-model="category" class="control tk-cat" @change="load">
        <option value="">Every kind of work</option>
        <option v-for="c in CATEGORIES" :key="c" :value="c">{{ titleCase(c) }}</option>
      </select>
      <input v-model="search" class="control tk-search" type="search" placeholder="Search tasks"
             @keyup.enter="load" />
    </div>

    <div v-if="loading" class="tk-loading">Loading…</div>

    <div v-else-if="rows.length === 0" class="tk-empty">
      <h2 class="tk-empty-title">Nothing here</h2>
      <p class="tk-empty-sub">No task matches this filter. Raise one, or widen the view.</p>
      <button class="btn-primary" @click="form = blank(); showNew = true">New Task</button>
    </div>

    <div v-else class="tk-card">
      <table class="tk-table">
        <thead>
          <tr><th>ID</th><th>Task</th><th>Kind</th><th>Owner</th><th>Attached to</th>
            <th>Deadline</th><th>Effort</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-for="t in rows" :key="t.id" :class="{ overdue: t.is_overdue }">
            <td class="tk-ref">{{ t.reference ?? "—" }}</td>
            <td class="tk-name">{{ t.title }}</td>
            <td><span class="tk-tag">{{ titleCase(t.category) }}</span></td>
            <td>
              <span v-if="t.assignee?.name" class="tk-person">
                <span class="tk-avatar">{{ initials(t.assignee.name) }}</span>{{ t.assignee.name }}
              </span>
              <!-- Unassigned is worth seeing, not hiding behind a dash. -->
              <span v-else class="tk-unowned">Unassigned</span>
            </td>
            <td>
              <button v-if="t.project" class="link-btn"
                      @click="router.push({ name: 'project', params: { id: t.project.id } })">
                {{ t.project.name }}
              </button>
              <span v-else class="tk-muted">—</span>
            </td>
            <td class="tk-nowrap">
              {{ date(t.deadline) }}
              <span v-if="t.is_overdue" class="tk-late">late</span>
            </td>
            <td class="tk-nowrap">
              <span v-if="variance(t)" :class="['pill', `pill-${variance(t)!.tone}`]">
                {{ variance(t)!.text }}
              </span>
              <span v-else-if="t.effort_estimate_days" class="tk-muted">
                {{ Number(t.effort_estimate_days).toFixed(1) }}d planned
              </span>
              <span v-else class="tk-muted">—</span>
            </td>
            <td><span :class="['pill', `pill-${statusTone(t.status)}`]">{{ titleCase(t.status) }}</span></td>
            <td>
              <button class="link-btn" @click="router.push({ name: 'task', params: { id: t.id } })">
                Open
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- ── Requests ─────────────────────────────────────────────────────── -->
    <div v-if="showInbox" class="tk-sheet-backdrop" @click.self="showInbox = false">
      <aside class="tk-sheet" role="dialog" aria-modal="true" aria-label="Requests">
        <div class="tk-sheet-head">
          <h3 class="tk-sheet-title">Requests</h3>
          <button class="tk-close" aria-label="Close" @click="showInbox = false">×</button>
        </div>

        <section>
          <h4 class="tk-sheet-sub">Asked of you</h4>
          <p v-if="!incoming.length" class="tk-muted tk-quiet">Nothing waiting on you.</p>
          <div v-for="a in incoming" :key="a.id" class="tk-ask">
            <div class="tk-ask-head">
              <span class="tk-tag">{{ titleCase(a.kind) }}</span>
              <span v-if="a.is_stale" class="pill pill-warn">Waiting over a week</span>
            </div>
            <button class="link-btn tk-ask-title"
                    @click="showInbox = false; router.push({ name: 'task', params: { id: a.task!.id } })">
              {{ a.task?.title }}
            </button>
            <p class="tk-ask-meta">from {{ a.requester?.name ?? "someone" }}</p>
            <p v-if="a.request_comment" class="tk-ask-note">“{{ a.request_comment }}”</p>
            <div class="tk-ask-actions">
              <button class="btn-primary btn-sm" @click="respond(a, 'accepted')">Accept</button>
              <button class="btn-ghost btn-sm" @click="respond(a, 'declined')">Decline</button>
            </div>
          </div>
        </section>

        <section>
          <!-- Your own unanswered asks, so silence can be chased rather than
               mistaken for agreement. -->
          <h4 class="tk-sheet-sub">Waiting on others</h4>
          <p v-if="!outgoing.length" class="tk-muted tk-quiet">You have asked nobody.</p>
          <div v-for="a in outgoing" :key="a.id" class="tk-ask">
            <div class="tk-ask-head">
              <span class="tk-tag">{{ titleCase(a.kind) }}</span>
              <span v-if="a.is_stale" class="pill pill-warn">No answer in a week</span>
            </div>
            <button class="link-btn tk-ask-title"
                    @click="showInbox = false; router.push({ name: 'task', params: { id: a.task!.id } })">
              {{ a.task?.title }}
            </button>
            <p class="tk-ask-meta">asked of {{ a.to?.name ?? "someone" }}</p>
          </div>
        </section>
      </aside>
    </div>

    <!-- ── New task ─────────────────────────────────────────────────────── -->
    <div v-if="showNew" class="tk-sheet-backdrop" @click.self="showNew = false">
      <aside class="tk-sheet" role="dialog" aria-modal="true" aria-label="New task">
        <div class="tk-sheet-head">
          <h3 class="tk-sheet-title">New Task</h3>
          <button class="tk-close" aria-label="Close" @click="showNew = false">×</button>
        </div>

        <label class="tk-field"><span>Task</span>
          <input v-model="form.title" class="control" placeholder="Renew the Fortinet support contract" /></label>

        <label class="tk-field"><span>Detail</span>
          <textarea v-model="form.description" class="control" rows="3"
                    placeholder="What has to happen, and what done looks like."></textarea></label>

        <label class="tk-field"><span>Kind of work</span>
          <select v-model="form.category" class="control">
            <option v-for="c in CATEGORIES" :key="c" :value="c">{{ titleCase(c) }}</option>
          </select>
          <span class="tk-hint">
            Most tasks belong to nobody's project — the kind is how they are found again.
          </span>
        </label>

        <div class="tk-row">
          <label class="tk-field"><span>Priority</span>
            <select v-model="form.priority" class="control">
              <option v-for="p in PRIORITIES" :key="p" :value="p">{{ titleCase(p) }}</option>
            </select></label>

          <label class="tk-field"><span>Deadline</span>
            <input v-model="form.deadline" class="control" type="date" /></label>
        </div>

        <label class="tk-field"><span>Estimated effort (days)</span>
          <input v-model="form.effort_estimate_days" class="control" type="number" step="0.5" min="0"
                 placeholder="3" />
          <span class="tk-hint">
            Optional, but this is what actual time gets measured against later.
          </span>
        </label>

        <div class="tk-field"><span>Owner</span>
          <EmployeePicker v-model="form.assigned_to" placeholder="Who is doing it?" />
          <span class="tk-hint">
            Leave it blank to raise the task unowned — you can ask somebody to
            take it once it exists.
          </span>
        </div>

        <button class="btn-primary btn-block" :disabled="saving || !form.title.trim()" @click="create">
          {{ saving ? "Creating…" : "Create Task" }}
        </button>
      </aside>
    </div>
  </div>
</template>

<style scoped>
@reference "../../theme.css";

.tk-wrap { padding: var(--space-6); max-width: 90rem; }

.tk-header { @apply flex flex-wrap items-start justify-between; gap: var(--space-4); margin-bottom: var(--space-4); }
.tk-title { font-size: var(--text-xl-size); font-weight: 600; color: var(--color-text); margin: 0; }
.tk-sub   { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: var(--space-1) 0 0; }
.tk-actions { @apply flex flex-wrap items-center; gap: var(--space-2); }

.tk-badge {
  @apply inline-flex items-center justify-center;
  min-width: 1.25rem; height: 1.25rem; padding: 0 5px; border-radius: 999px;
  background: var(--color-brand-base); color: var(--color-on-brand);
  font-size: var(--text-xs-size); font-weight: 600;
}
.btn-ghost.has-pending { border-color: var(--color-brand-base); color: var(--color-brand-on-surface); }

.tk-chips { @apply flex flex-wrap; gap: var(--space-2); margin-bottom: var(--space-4); }
.tk-chip {
  @apply cursor-pointer; padding: 6px var(--space-4); border-radius: 999px;
  border: 1px solid var(--color-border-strong); background: var(--color-surface);
  font-family: inherit; font-size: var(--text-sm-size); color: var(--color-text-muted);
}
.tk-chip.active { background: var(--color-brand-base); color: var(--color-on-brand); border-color: var(--color-brand-base); }

.tk-toolbar { @apply flex flex-wrap justify-end; gap: var(--space-2); margin-bottom: var(--space-4); }
.tk-search { max-width: 22rem; }
.tk-cat { max-width: 14rem; }
.tk-loading { padding: var(--space-6); color: var(--color-text-muted); }

.tk-empty { @apply flex flex-col items-center; padding: var(--space-8) var(--space-6); text-align: center; gap: var(--space-2); }
.tk-empty-title { font-size: var(--text-lg-size); font-weight: 600; color: var(--color-text); margin: 0; }
.tk-empty-sub { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: 0 0 var(--space-3); }

.tk-card {
  background: var(--color-surface-raised);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  overflow-x: auto;
}
.tk-table { @apply w-full; border-collapse: collapse; font-size: var(--text-sm-size); }
.tk-table th {
  text-align: left; padding: var(--space-3) var(--space-4);
  font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-muted);
  border-bottom: 1px solid var(--color-border); white-space: nowrap;
}
.tk-table td { padding: var(--space-3) var(--space-4); border-bottom: 1px solid var(--color-border); color: var(--color-text); }
.tk-table tr:last-child td { border-bottom: none; }
.tk-name { font-weight: 500; }
.tk-ref { font-variant-numeric: tabular-nums; color: var(--color-text-muted); }
.tk-nowrap { white-space: nowrap; }
.tk-muted { color: var(--color-text-light); }
.tk-quiet { font-size: var(--text-sm-size); margin: 0; }

/* Late work reads as late without having to compare two dates by eye. */
.tk-table tr.overdue td { background: color-mix(in oklab, var(--color-danger-subtle) 45%, transparent); }
.tk-late {
  margin-left: var(--space-2); padding: 1px var(--space-2); border-radius: var(--radius-sm);
  background: var(--color-danger-subtle); color: var(--color-danger-text);
  font-size: var(--text-xs-size);
}

.tk-tag {
  padding: 1px var(--space-2); border-radius: var(--radius-sm);
  background: var(--color-surface-sunken); color: var(--color-text-muted);
  font-size: var(--text-xs-size); white-space: nowrap;
}
.tk-unowned { font-size: var(--text-xs-size); color: var(--color-warning-text); }

.tk-person { @apply inline-flex items-center; gap: var(--space-2); }
.tk-avatar {
  @apply inline-flex shrink-0 items-center justify-center;
  width: 1.5rem; height: 1.5rem; border-radius: 999px;
  background: var(--color-surface-sunken); color: var(--color-text-muted);
  font-size: 0.6rem; font-weight: 600;
}

.tk-field { @apply flex flex-col; gap: var(--space-1); }
.tk-field > span { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.tk-hint { font-size: var(--text-xs-size); color: var(--color-text-light); }
.tk-row { @apply grid; grid-template-columns: 1fr 1fr; gap: var(--space-3); }

.tk-ask {
  @apply flex flex-col; gap: var(--space-1);
  padding: var(--space-3); margin-top: var(--space-2);
  border: 1px solid var(--color-border); border-radius: var(--radius-md);
  background: var(--color-surface);
}
.tk-ask-head { @apply flex flex-wrap items-center; gap: var(--space-2); }
.tk-ask-title { font-weight: 500; }
.tk-ask-meta { font-size: var(--text-xs-size); color: var(--color-text-muted); margin: 0; }
.tk-ask-note { font-size: var(--text-sm-size); color: var(--color-text); margin: var(--space-1) 0 0; }
.tk-ask-actions { @apply flex; gap: var(--space-2); margin-top: var(--space-2); }

.control {
  font-family: inherit; font-size: var(--text-sm-size);
  padding: 8px var(--space-3);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-md);
  background: var(--color-surface); color: var(--color-text); width: 100%;
}
.control:focus { @apply outline-none; border-color: var(--color-brand-base); box-shadow: 0 0 0 3px var(--color-brand-subtle); }

.pill { display: inline-block; padding: 2px var(--space-3); border-radius: 999px; font-size: var(--text-xs-size); border: 1px solid transparent; white-space: nowrap; }
.pill-ok      { background: var(--color-success-subtle); color: var(--color-success-text); border-color: color-mix(in oklab, var(--color-success-text) 30%, transparent); }
.pill-warn    { background: var(--color-warning-subtle); color: var(--color-warning-text); border-color: color-mix(in oklab, var(--color-warning-text) 30%, transparent); }
.pill-bad     { background: var(--color-danger-subtle);  color: var(--color-danger-text);  border-color: color-mix(in oklab, var(--color-danger-text) 30%, transparent); }
.pill-info    { background: var(--color-brand-subtle);   color: var(--color-brand-on-surface); border-color: color-mix(in oklab, var(--color-brand-base) 30%, transparent); }
.pill-neutral { background: var(--color-surface-sunken); color: var(--color-text-muted); border-color: var(--color-border); }

.link-btn { @apply cursor-pointer border-none bg-transparent p-0; font-family: inherit; font-size: var(--text-sm-size); color: var(--color-brand-on-surface); text-align: left; }
.link-btn:hover { @apply underline; }

.tk-sheet-backdrop { @apply fixed inset-0 flex justify-end z-50; background: rgb(0 0 0 / 0.28); }
.tk-sheet {
  @apply flex flex-col overflow-y-auto; width: min(28rem, 100%); gap: var(--space-4);
  padding: var(--space-5); background: var(--color-surface-raised); box-shadow: var(--shadow-lg);
  animation: sheet-in 180ms ease;
}
@keyframes sheet-in { from { transform: translateX(12px); opacity: 0; } }
@media (prefers-reduced-motion: reduce) { .tk-sheet { animation: none; } }
.tk-sheet-head { @apply flex items-center justify-between; }
.tk-sheet-title { font-size: var(--text-base-size); font-weight: 600; margin: 0; color: var(--color-text); }
.tk-sheet-sub { font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-muted); margin: 0; text-transform: uppercase; letter-spacing: 0.04em; }
.tk-close { @apply cursor-pointer border-none bg-transparent p-0; font-size: 1.5rem; line-height: 1; color: var(--color-text-muted); }

.btn-primary {
  @apply inline-flex cursor-pointer items-center justify-center border-none;
  gap: var(--space-2); padding: 8px var(--space-4); border-radius: var(--radius-md);
  font-family: inherit; font-size: var(--text-sm-size); font-weight: 500; white-space: nowrap;
  background: var(--color-brand-base); color: var(--color-on-brand);
}
.btn-primary svg { width: 1rem; height: 1rem; }
.btn-block { width: 100%; }
.btn-primary:disabled { @apply cursor-not-allowed opacity-50; }

.btn-ghost {
  @apply inline-flex cursor-pointer items-center justify-center;
  gap: var(--space-2); padding: 8px var(--space-4); border-radius: var(--radius-md);
  border: 1px solid var(--color-border-strong); background: var(--color-surface);
  font-family: inherit; font-size: var(--text-sm-size); color: var(--color-text);
}
.btn-ghost svg { width: 1rem; height: 1rem; }
.btn-sm { padding: 5px var(--space-3); font-size: var(--text-xs-size); }
</style>
