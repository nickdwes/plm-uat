<script setup lang="ts">
/**
 * Tasks — the board and the list, as the designs lay them out.
 *
 * Counts across the top, then Kanban or List view over the same data. The
 * columns are the statuses themselves, so a card dragged between them is a
 * status change and nothing has to be kept in step.
 *
 * Two things the designs get right that are worth naming:
 *
 *   Overdue is a count, not a column. It cuts across To Do, In Progress and
 *   Awaiting Approval, and it is the number people come to the page for.
 *
 *   The detail is a panel over the list, not a page away from it. You answer
 *   one task and carry on down the column — which is the difference between
 *   the request inbox being read and the legacy's 261 unanswered delegations.
 */
import { ref, computed, onMounted, watch } from "vue";
import { useRouter, useRoute } from "vue-router";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import { notifySaved, notifyFailed } from "../../lib/toast";
import EmployeePicker from "../../components/EmployeePicker.vue";
import AccountPicker from "../../components/AccountPicker.vue";
import VendorPartnerPicker from "../../components/VendorPartnerPicker.vue";
import { excerpt, hasText } from "../../lib/richText";

interface Person { id: number | null; name: string | null }
interface TaskRow {
  id: number; reference: string | null; title: string; description: string | null;
  category: string; status: string; priority: string;
  deadline: string | null;
  is_done: boolean; is_overdue: boolean;
  effort_estimate_days: number | string | null;
  effort_variance: number | string | null;
  assignee: Person | null;
  assignees: Person[];
  project: { id: number; name: string } | null;
  owner: { party: string; is_ours: boolean; name: string | null; contact_name: string | null };
  source: string; is_generated: boolean;
  open_issue_count: number;
}
interface Recurrence {
  id: number; title: string; frequency: string; interval: number;
  lead_days: number; starts_on: string | null; ends_on: string | null;
  is_active: boolean; next_due: string | null;
  last_generated_for: string | null;
  assignee: Person | null;
}
interface Ask {
  id: number; kind: string; status: string; is_stale: boolean;
  request_comment: string | null;
  to: Person | null; requester: Person | null;
  task: { id: number; reference: string | null; title: string } | null;
}
interface Summary {
  total: number; todo: number; in_progress: number;
  awaiting: number; completed: number; overdue: number;
  awaiting_others: number;
}

const router = useRouter();
const route  = useRoute();

const rows    = ref<TaskRow[]>([]);
const loading = ref(true);
const saving  = ref(false);
const search  = ref("");
const page    = ref(1);
const lastPage = ref(1);

const summary = ref<Summary>({
  total: 0, todo: 0, in_progress: 0, awaiting: 0, completed: 0, overdue: 0, awaiting_others: 0,
});
const incoming = ref<Ask[]>([]);
const outgoing = ref<Ask[]>([]);

const view   = ref<"kanban" | "list">("kanban");
const status = ref("todo");
const showFilters = ref(false);
const category = ref("");
const onlyOverdue = ref(false);
const ownerParty = ref("");

/**
 * Everything outstanding that somebody outside owes.
 *
 * Its own mode rather than a status filter, because it spans To Do, In
 * Progress and Awaiting Approval — the same reason Overdue is a count and not
 * a column.
 */
const awaitingOthers = ref(false);

const org = () => currentOrgSlug.value;

function fail(e: unknown, fallback: string) {
  notifyFailed(fallback, e instanceof ApiError ? (e.firstError ?? e.message) : undefined);
}

/**
 * The board loads everything; the list loads one status at a time.
 *
 * The board needs all four columns at once, so filtering server-side there
 * would mean four round trips to draw one screen.
 */
async function load() {
  loading.value = true;
  try {
    const res = await api<{ data: TaskRow[]; meta: { last_page: number } }>("GET", "/api/v1/tasks", {
      org: org(),
      params: {
        search: search.value || undefined,
        category: category.value || undefined,
        owner_party: ownerParty.value || undefined,
        state: awaitingOthers.value ? "awaiting_others" : (onlyOverdue.value ? "overdue" : undefined),
        // Cuts across statuses, so pinning one would hide most of it.
        status: view.value === "list" && ! awaitingOthers.value ? status.value : undefined,
        page: view.value === "list" ? page.value : undefined,
        per_page: view.value === "list" ? 10 : 200,
      },
    });
    rows.value = res.data;
    lastPage.value = res.meta.last_page ?? 1;
  } catch (e) { fail(e, "Could not load tasks."); }
  finally { loading.value = false; }
}

async function loadSummary() {
  try {
    summary.value = await api<Summary>("GET", "/api/v1/tasks/summary", { org: org() });
  } catch (e) { fail(e, "Could not load the task counts."); }
}

async function loadInbox() {
  try {
    const res = await api<{ incoming: Ask[]; outgoing: Ask[] }>("GET", "/api/v1/tasks/inbox", { org: org() });
    incoming.value = res.incoming;
    outgoing.value = res.outgoing;
  } catch (e) { fail(e, "Could not load your requests."); }
}

function refresh() { load(); loadSummary(); loadInbox(); }

// Coming back from the detail panel: whatever changed there has to show here.
watch(() => route.name, (name) => { if (name === "tasks") refresh(); });

// ── Schedules ───────────────────────────────────────────────────────────────

/**
 * Work that comes back round.
 *
 * The legacy had no recurrence at all, which is why the same monthly reviews
 * and renewals appear in its 9,055 tasks typed out over and over.
 */
const showSchedules = ref(false);
const schedules = ref<Recurrence[]>([]);

async function loadSchedules() {
  try {
    schedules.value = (await api<{ data: Recurrence[] }>("GET", "/api/v1/tasks/recurrences", {
      org: org(),
    })).data;
  } catch (e) { fail(e, "Could not load the schedules."); }
}

const blankSchedule = () => ({
  title: "", description: "", category: "internal", priority: "medium",
  frequency: "monthly", interval: 1, lead_days: 0,
  starts_on: new Date().toISOString().slice(0, 10), ends_on: "",
  assigned_to: null as number | null,
});
const schedule = ref(blankSchedule());
const addingSchedule = ref(false);

async function createSchedule() {
  if (!schedule.value.title.trim()) return;
  saving.value = true;
  try {
    await api("POST", "/api/v1/tasks/recurrences", {
      org: org(), body: { ...schedule.value, ends_on: schedule.value.ends_on || null },
    });
    schedule.value = blankSchedule();
    addingSchedule.value = false;
    notifySaved("Schedule created.");
    await loadSchedules();
  } catch (e) { fail(e, "Could not create the schedule."); }
  finally { saving.value = false; }
}

/** Safe to press twice — every task is keyed to its schedule and occurrence. */
async function runSchedule(r: Recurrence) {
  try {
    const res = await api<{ created: number }>("POST", `/api/v1/tasks/recurrences/${r.id}/run`, { org: org() });
    notifySaved(res.created ? `Raised ${res.created} task(s).` : "Nothing was due.");
    await Promise.all([loadSchedules(), load(), loadSummary()]);
  } catch (e) { fail(e, "Could not run the schedule."); }
}

async function stopSchedule(r: Recurrence) {
  try {
    await api("DELETE", `/api/v1/tasks/recurrences/${r.id}`, { org: org() });
    notifySaved("Schedule stopped.");
    await loadSchedules();
  } catch (e) { fail(e, "Could not stop the schedule."); }
}

// ── The inbox ───────────────────────────────────────────────────────────────

const showInbox = ref(false);

async function respond(a: Ask, status: "accepted" | "declined") {
  if (!a.task) return;
  try {
    await api("POST", `/api/v1/tasks/${a.task.id}/requests/${a.id}/respond`, {
      org: org(), body: { status },
    });
    notifySaved(status === "accepted" ? "Accepted." : "Declined.");
    refresh();
  } catch (e) { fail(e, "Could not answer the request."); }
}

// ── Creating ────────────────────────────────────────────────────────────────

const showNew = ref(false);
const blank = () => ({
  title: "", description: "", category: "internal", priority: "medium",
  status: "todo", deadline: "", effort_estimate_days: "" as string | number,
  assigned_to: null as number | null,
  // Ours unless somebody says otherwise — which was 7,977 of the legacy's
  // 9,055 tasks, and 974 more that meant it by leaving the field blank.
  owner_party: "internal",
  owner_account_id: null as number | null,
  owner_vendor_partner_id: null as number | null,
  owner_contact_name: "",
});
const form = ref(blank());

function openNew(withStatus = "todo") {
  form.value = { ...blank(), status: withStatus };
  showNew.value = true;
}

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
        owner_contact_name: form.value.owner_contact_name || null,
      },
    });
    showNew.value = false;
    notifySaved(`${res.data.reference ?? "Task"} created.`);
    refresh();
  } catch (e) { fail(e, "Could not create the task."); }
  finally { saving.value = false; }
}

// ── Presentation ────────────────────────────────────────────────────────────

/** The board's columns are the statuses, so nothing has to be kept in step. */
const COLUMNS = [
  { key: "todo",              label: "To Do" },
  { key: "in_progress",       label: "In Progress" },
  { key: "awaiting_approval", label: "Awaiting Approval" },
  { key: "completed",         label: "Complete" },
];

/** The legacy's twelve, which were the module map as much as a filter. */
const CATEGORIES = [
  "opportunity", "project", "support", "meeting", "training", "certificate",
  "research", "collaboration", "internal", "ticket", "account", "leave",
];
const PRIORITIES = ["low", "medium", "high"];

const countFor = (key: string) => ({
  todo: summary.value.todo,
  in_progress: summary.value.in_progress,
  awaiting_approval: summary.value.awaiting,
  completed: summary.value.completed,
}[key] ?? 0);

const columnRows = (key: string) => rows.value.filter((t) => t.status === key);

const date = (v: string | null) =>
  v ? new Date(v).toLocaleDateString(undefined, { day: "2-digit", month: "short", year: "numeric" }) : "—";

const shortDate = (v: string | null) =>
  v ? new Date(v).toLocaleDateString(undefined, { day: "2-digit", month: "short" }) : "—";

const titleCase = (v: string) => v.replace(/_/g, " ").replace(/\b\w/g, (m) => m.toUpperCase());

const initials = (name: string | null | undefined) =>
  (name ?? "?").split(/\s+/).slice(0, 2).map((w) => w[0]).join("").toUpperCase();

/** The design's avatar stack: four faces, then the overflow count. */
const stack = (people: Person[]) => people.slice(0, 4);
const overflow = (people: Person[]) => Math.max(0, people.length - 4);

const pending = computed(() => incoming.value.length);

function open(t: TaskRow | number) {
  router.push({ name: "task", params: { id: typeof t === "number" ? t : t.id } });
}

function setStatus(key: string) {
  status.value = key;
  page.value = 1;
  load();
}

function setView(v: "kanban" | "list") {
  view.value = v;
  page.value = 1;
  load();
}

function go(p: number) {
  if (p < 1 || p > lastPage.value) return;
  page.value = p;
  load();
}

/** The page window around the current one, so 40 pages do not render 40 chips. */
const pages = computed(() => {
  const out: number[] = [];
  const from = Math.max(1, Math.min(page.value - 2, lastPage.value - 4));
  for (let p = from; p <= Math.min(lastPage.value, from + 4); p++) out.push(p);
  return out;
});

onMounted(refresh);
</script>

<template>
  <div class="tk-wrap">
    <header class="tk-header">
      <div>
        <h1 class="tk-title">Tasks</h1>
        <p class="tk-sub">
          You have {{ summary.total - summary.completed - summary.awaiting_others }} ongoing tasks.
          <template v-if="summary.awaiting_others">
            {{ summary.awaiting_others }} more are waiting on somebody outside.
          </template>
          Stay focused and complete them on time
        </p>
      </div>
      <div class="tk-actions">
        <!-- Counted, because being asked and never seeing it is what went
             wrong before: 261 of the legacy's 354 delegations went unanswered. -->
        <button :class="['btn-ghost', { 'has-pending': pending }]" @click="showInbox = true">
          <svg viewBox="0 0 20 20" fill="none" stroke="currentColor"
               stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 5.5h14v9H3z" /><path d="M3 6l7 5 7-5" />
          </svg>
          Requests
          <span v-if="pending" class="tk-badge">{{ pending }}</span>
        </button>
        <button class="btn-ghost" @click="showSchedules = true; loadSchedules()">
          <svg viewBox="0 0 20 20" fill="none" stroke="currentColor"
               stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 6a6 6 0 0110-4.5M16 14a6 6 0 01-10 4.5" /><path d="M4 2v4h4M16 18v-4h-4" />
          </svg>
          Schedules
        </button>
        <button class="btn-primary" @click="openNew()">
          <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <path d="M10 4v12M4 10h12" />
          </svg>
          Add Task
        </button>
      </div>
    </header>

    <!-- ── The counts ───────────────────────────────────────────────────── -->
    <div class="tk-stats">
      <div class="tk-stat"><span class="tk-stat-label">Total Tasks</span><span class="tk-stat-value">{{ summary.total }}</span></div>
      <div class="tk-stat"><span class="tk-stat-label">Todo Tasks</span><span class="tk-stat-value">{{ summary.todo }}</span></div>
      <div class="tk-stat"><span class="tk-stat-label">Task In Progress</span><span class="tk-stat-value">{{ summary.in_progress }}</span></div>
      <div class="tk-stat"><span class="tk-stat-label">Completed Tasks</span><span class="tk-stat-value">{{ summary.completed }}</span></div>
      <!-- Cuts across three of the four columns, which is why it is a count
           here rather than a column on the board. -->
      <div class="tk-stat">
        <span class="tk-stat-label">Overdue Tasks</span>
        <span :class="['tk-stat-value', { late: summary.overdue }]">{{ summary.overdue }}</span>
      </div>
      <!--
        Owed by a client or a supplier, and counted apart from the rest.

        Somebody chasing eleven things a client owes is not overloaded, and a
        total that mixes the two says they are. Clicking it is how you get the
        list to chase from.
      -->
      <button class="tk-stat tk-stat-btn"
              :class="{ active: awaitingOthers }"
              @click="awaitingOthers = !awaitingOthers; onlyOverdue = false; setView('list')">
        <span class="tk-stat-label">Waiting on Others</span>
        <span class="tk-stat-value">{{ summary.awaiting_others }}</span>
      </button>
    </div>

    <!-- ── View switch ──────────────────────────────────────────────────── -->
    <div class="tk-tabs">
      <button :class="['tk-tab', { active: view === 'kanban' }]" @click="setView('kanban')">
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5">
          <rect x="3" y="4" width="4" height="12" rx="1" /><rect x="8.5" y="4" width="4" height="8" rx="1" />
          <rect x="14" y="4" width="3" height="10" rx="1" />
        </svg>
        Kanban
      </button>
      <button :class="['tk-tab', { active: view === 'list' }]" @click="setView('list')">
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
          <path d="M7 5h10M7 10h10M7 15h10M3 5h1M3 10h1M3 15h1" />
        </svg>
        List view
      </button>
    </div>

    <!-- Status chips belong to the list; on the board the columns are the
         same thing and two of them would disagree. -->
    <div v-if="awaitingOthers" class="tk-mode">
      Showing everything outstanding that a customer or vendor owes.
      <button class="link-btn" @click="awaitingOthers = false; load()">Show all tasks</button>
    </div>

    <div v-if="view === 'list' && !awaitingOthers" class="tk-chips">
      <button v-for="c in COLUMNS" :key="c.key"
              :class="['tk-chip', { active: status === c.key }]"
              @click="setStatus(c.key)">
        {{ c.label }}
        <span class="tk-chip-count">{{ countFor(c.key) }}</span>
      </button>
    </div>

    <div class="tk-toolbar">
      <input v-model="search" class="control tk-search" type="search" placeholder="Search"
             @keyup.enter="page = 1; load()" />
      <button :class="['btn-ghost', { active: showFilters }]" @click="showFilters = !showFilters">
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
          <path d="M3 6h14M6 10h8M8 14h4" />
        </svg>
        Filter
      </button>
    </div>

    <div v-if="showFilters" class="tk-filters">
      <label class="tk-field"><span>Kind of work</span>
        <select v-model="category" class="control" @change="page = 1; load()">
          <option value="">Every kind</option>
          <option v-for="c in CATEGORIES" :key="c" :value="c">{{ titleCase(c) }}</option>
        </select></label>
      <label class="tk-field"><span>Who owes it</span>
        <select v-model="ownerParty" class="control" @change="page = 1; load()">
          <option value="">Anyone</option>
          <option value="internal">Us</option>
          <option value="customer">A customer</option>
          <option value="vendor">A vendor or OEM</option>
        </select></label>
      <label class="tk-check">
        <input v-model="onlyOverdue" type="checkbox" @change="page = 1; load()" />
        <span>Only what is past its deadline</span>
      </label>
    </div>

    <div v-if="loading" class="tk-loading">Loading…</div>

    <!-- ── Kanban ───────────────────────────────────────────────────────── -->
    <div v-else-if="view === 'kanban'" class="tk-board">
      <section v-for="c in COLUMNS" :key="c.key" class="tk-col">
        <header class="tk-col-head">
          <span class="tk-col-title">{{ c.label }}</span>
          <span class="tk-col-count">{{ countFor(c.key) }}</span>
          <button class="tk-col-add" :aria-label="`Add to ${c.label}`" @click="openNew(c.key)">+</button>
        </header>

        <p v-if="!columnRows(c.key).length" class="tk-col-empty">Nothing here.</p>

        <article v-for="t in columnRows(c.key)" :key="t.id" class="tk-card" @click="open(t)">
          <p :class="['tk-flag', t.priority]">
            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
              <path d="M5 3v14" /><path d="M5 4h9l-2 3 2 3H5z" />
            </svg>
            {{ titleCase(t.priority) }} priority
          </p>

          <h3 class="tk-card-title">
            {{ t.title }}
            <!-- Raised by the system. Deleting it by hand only means the
                 schedule raises it again. -->
            <span v-if="t.is_generated" class="tk-auto" :title="`Raised automatically (${t.source})`">auto</span>
          </h3>
          <p v-if="hasText(t.description)" class="tk-card-body">{{ excerpt(t.description) }}</p>

          <!-- An open issue is not the same thing as the column the card is
               in: a task can be In Progress and still be stuck. -->
          <!-- Owed by somebody outside, so nobody mistakes it for our work. -->
          <p v-if="!t.owner.is_ours" class="tk-card-owner">
            Owed by {{ t.owner.name ?? "somebody outside" }}
            <template v-if="t.owner.contact_name"> — {{ t.owner.contact_name }}</template>
          </p>

          <p v-if="t.open_issue_count" class="tk-card-issues">
            {{ t.open_issue_count }} open issue{{ t.open_issue_count === 1 ? "" : "s" }}
          </p>

          <footer class="tk-card-foot">
            <span class="tk-stack">
              <span v-for="p in stack(t.assignees)" :key="p.id ?? p.name ?? ''" class="tk-avatar"
                    :title="p.name ?? ''">{{ initials(p.name) }}</span>
              <span v-if="overflow(t.assignees)" class="tk-avatar more">+{{ overflow(t.assignees) }}</span>
              <span v-if="!t.assignees.length" class="tk-unowned">Unassigned</span>
            </span>
            <span :class="['tk-due', { late: t.is_overdue }]">
              <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5">
                <rect x="3" y="4.5" width="14" height="12" rx="2" /><path d="M3 8h14M7 3v3M13 3v3" />
              </svg>
              {{ shortDate(t.deadline) }}
            </span>
          </footer>
        </article>
      </section>
    </div>

    <!-- ── List ─────────────────────────────────────────────────────────── -->
    <div v-else>
      <div v-if="!rows.length" class="tk-empty">
        <h2 class="tk-empty-title">Nothing here</h2>
        <p class="tk-empty-sub">No task matches this filter. Create one, or widen the view.</p>
        <button class="btn-primary" @click="openNew(status)">Add Task</button>
      </div>

      <div v-else class="tk-table-card">
        <table class="tk-table">
          <thead>
            <tr>
              <th>Task</th><th>Owed by</th><th>Assignee</th><th>Due Date</th><th>Priority</th><th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="t in rows" :key="t.id" :class="{ overdue: t.is_overdue }">
              <td class="tk-name">
                {{ t.title }}
                <span v-if="t.is_generated" class="tk-auto" :title="`Raised automatically (${t.source})`">auto</span>
              </td>
              <td>
                <span v-if="t.owner.is_ours" class="tk-muted">Us</span>
                <span v-else class="tk-external">{{ t.owner.name ?? "Outside" }}</span>
              </td>
              <td>
                <span class="tk-stack">
                  <span v-for="p in stack(t.assignees)" :key="p.id ?? p.name ?? ''" class="tk-avatar"
                        :title="p.name ?? ''">{{ initials(p.name) }}</span>
                  <span v-if="overflow(t.assignees)" class="tk-avatar more">+{{ overflow(t.assignees) }}</span>
                  <span v-if="!t.assignees.length" class="tk-unowned">Unassigned</span>
                </span>
              </td>
              <td class="tk-nowrap">{{ date(t.deadline) }}</td>
              <td>
                <span :class="['tk-flag', t.priority]">
                  <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
                    <path d="M5 3v14" /><path d="M5 4h9l-2 3 2 3H5z" />
                  </svg>
                  {{ titleCase(t.priority) }} priority
                </span>
              </td>
              <td><button class="link-btn" @click="open(t)">View</button></td>
            </tr>
          </tbody>
        </table>

        <nav v-if="lastPage > 1" class="tk-pager">
          <button class="link-btn" :disabled="page === 1" @click="go(page - 1)">← Previous</button>
          <button v-for="p in pages" :key="p" :class="['tk-page', { active: p === page }]" @click="go(p)">
            {{ p }}
          </button>
          <button class="link-btn" :disabled="page === lastPage" @click="go(page + 1)">Next →</button>
        </nav>
      </div>
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
            <button class="link-btn tk-ask-title" @click="showInbox = false; open(a.task!.id)">
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
              <span v-if="a.is_stale" class="pill pill-bad">No answer in a week</span>
            </div>
            <button class="link-btn tk-ask-title" @click="showInbox = false; open(a.task!.id)">
              {{ a.task?.title }}
            </button>
            <p class="tk-ask-meta">asked of {{ a.to?.name ?? "someone" }}</p>
          </div>
        </section>
      </aside>
    </div>

    <!-- ── Schedules ────────────────────────────────────────────────────── -->
    <div v-if="showSchedules" class="tk-sheet-backdrop" @click.self="showSchedules = false">
      <aside class="tk-sheet" role="dialog" aria-modal="true" aria-label="Recurring schedules">
        <div class="tk-sheet-head">
          <h3 class="tk-sheet-title">Recurring work</h3>
          <button class="tk-close" aria-label="Close" @click="showSchedules = false">×</button>
        </div>

        <p class="tk-hint">
          A schedule raises its task when it comes due, and nothing else.
          Running it twice makes nothing — every task it raises is tied to its
          own occurrence.
        </p>

        <p v-if="!schedules.length" class="tk-muted tk-quiet">No schedules yet.</p>

        <div v-for="r in schedules" :key="r.id" :class="['tk-sched', { off: !r.is_active }]">
          <div class="tk-sched-head">
            <strong>{{ r.title }}</strong>
            <span v-if="!r.is_active" class="tk-tag">stopped</span>
          </div>
          <p class="tk-sched-meta">
            Every {{ r.interval > 1 ? r.interval + " " : "" }}{{ r.frequency.replace(/ly$/, "") }}{{ r.interval > 1 ? "s" : "" }}
            <template v-if="r.assignee?.name"> · {{ r.assignee.name }}</template>
          </p>
          <p class="tk-sched-meta">
            <template v-if="r.next_due">Next due {{ date(r.next_due) }}</template>
            <template v-else>Nothing further due</template>
            <template v-if="r.last_generated_for"> · last raised {{ date(r.last_generated_for) }}</template>
          </p>
          <div class="tk-sched-actions">
            <button class="btn-primary btn-sm" :disabled="!r.is_active" @click="runSchedule(r)">Run now</button>
            <button v-if="r.is_active" class="btn-ghost btn-sm" @click="stopSchedule(r)">Stop</button>
          </div>
        </div>

        <button v-if="!addingSchedule" class="btn-ghost" @click="addingSchedule = true">+ New schedule</button>

        <div v-else class="tk-sched-form">
          <label class="tk-field"><span>What repeats</span>
            <input v-model="schedule.title" class="control" placeholder="Monthly firewall rule review" /></label>

          <div class="tk-row">
            <label class="tk-field"><span>How often</span>
              <select v-model="schedule.frequency" class="control">
                <option value="daily">Daily</option><option value="weekly">Weekly</option>
                <option value="monthly">Monthly</option><option value="quarterly">Quarterly</option>
                <option value="yearly">Yearly</option>
              </select></label>
            <label class="tk-field"><span>Every</span>
              <input v-model.number="schedule.interval" class="control" type="number" min="1" max="52" /></label>
          </div>

          <label class="tk-field"><span>Days to complete it</span>
            <input v-model.number="schedule.lead_days" class="control" type="number" min="0" max="365" />
            <span class="tk-hint">
              Counted from each occurrence, so the deadline moves with it.
            </span>
          </label>

          <div class="tk-row">
            <label class="tk-field"><span>Starts</span>
              <input v-model="schedule.starts_on" class="control" type="date" /></label>
            <label class="tk-field"><span>Ends (optional)</span>
              <input v-model="schedule.ends_on" class="control" type="date" /></label>
          </div>

          <label class="tk-field"><span>Kind of work</span>
            <select v-model="schedule.category" class="control">
              <option v-for="c in CATEGORIES" :key="c" :value="c">{{ titleCase(c) }}</option>
            </select></label>

          <div class="tk-field"><span>Assignee</span>
            <EmployeePicker v-model="schedule.assigned_to" placeholder="Who does it each time?" /></div>

          <div class="tk-sched-actions">
            <button class="btn-ghost btn-sm" @click="addingSchedule = false">Cancel</button>
            <button class="btn-primary btn-sm" :disabled="saving || !schedule.title.trim()" @click="createSchedule">
              Create schedule
            </button>
          </div>
        </div>
      </aside>
    </div>

    <!-- ── Add Task ─────────────────────────────────────────────────────── -->
    <div v-if="showNew" class="tk-modal-backdrop" @click.self="showNew = false">
      <div class="tk-modal" role="dialog" aria-modal="true" aria-label="Add task">
        <div class="tk-modal-head">
          <h3 class="tk-modal-title">Add Task</h3>
          <button class="tk-close" aria-label="Close" @click="showNew = false">×</button>
        </div>

        <label class="tk-field"><span>Task Name</span>
          <input v-model="form.title" class="control" placeholder="Task Name" /></label>

        <label class="tk-field"><span>Task Description</span>
          <textarea v-model="form.description" class="control" rows="3"
                    placeholder="What has to happen, and what done looks like."></textarea></label>

        <label class="tk-field"><span>Task Deadline</span>
          <input v-model="form.deadline" class="control" type="date" /></label>

        <div class="tk-row">
          <label class="tk-field"><span>Task Priority</span>
            <select v-model="form.priority" class="control">
              <option v-for="p in PRIORITIES" :key="p" :value="p">{{ titleCase(p) }} priority</option>
            </select></label>

          <label class="tk-field"><span>Status</span>
            <select v-model="form.status" class="control">
              <option v-for="c in COLUMNS" :key="c.key" :value="c.key">{{ c.label }}</option>
            </select></label>
        </div>

        <label class="tk-field"><span>Kind of work</span>
          <select v-model="form.category" class="control">
            <option v-for="c in CATEGORIES" :key="c" :value="c">{{ titleCase(c) }}</option>
          </select>
          <span class="tk-hint">
            Most tasks belong to no project — the kind is how they are found again.
          </span>
        </label>

        <label class="tk-field"><span>Estimated effort (days)</span>
          <input v-model="form.effort_estimate_days" class="control" type="number" step="0.5" min="0" />
          <span class="tk-hint">Optional. This is what the actual time gets measured against later.</span>
        </label>

        <div class="tk-field"><span>Assignee</span>
          <EmployeePicker v-model="form.assigned_to" placeholder="Assignee" />
          <span class="tk-hint">Who here is accountable for it landing.</span>
        </div>

        <!--
          Who actually does the work, which is not always us.

          104 of the legacy's tasks were owed by a client or a supplier and 97
          of those were tracked to completion. Recording it is what turns
          "waiting on the client" from a comment into something countable.
        -->
        <label class="tk-field"><span>Who owes the work</span>
          <select v-model="form.owner_party" class="control">
            <option value="internal">Us</option>
            <option value="customer">A customer</option>
            <option value="vendor">A vendor or OEM</option>
          </select></label>

        <div v-if="form.owner_party === 'customer'" class="tk-field"><span>Which customer</span>
          <AccountPicker v-model="form.owner_account_id" placeholder="Which customer owes this?" /></div>

        <div v-if="form.owner_party === 'vendor'" class="tk-field"><span>Which vendor</span>
          <VendorPartnerPicker v-model="form.owner_vendor_partner_id" placeholder="Which vendor owes this?" /></div>

        <label v-if="form.owner_party !== 'internal'" class="tk-field"><span>Who there</span>
          <input v-model="form.owner_contact_name" class="control" placeholder="Milton Ope" />
          <span class="tk-hint">
            Typed, not picked — their engineer is rarely on our books.
          </span>
        </label>

        <button class="btn-primary btn-block" :disabled="saving || !form.title.trim()" @click="create">
          {{ saving ? "Adding…" : "Add Task" }}
        </button>
      </div>
    </div>

    <!-- The detail sits over the list, so you answer one task and carry on
         down the column rather than navigating away and back. -->
    <router-view @changed="refresh" />
  </div>
</template>

<style scoped>
@reference "../../theme.css";

.tk-wrap { padding: var(--space-6); max-width: 96rem; }

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
.btn-ghost.has-pending, .btn-ghost.active { border-color: var(--color-brand-base); color: var(--color-brand-on-surface); }

/* ── Counts ─────────────────────────────────────────────────────────────── */
.tk-stats {
  @apply grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr));
  background: var(--color-surface-raised); border: 1px solid var(--color-border);
  border-radius: var(--radius-lg); margin-bottom: var(--space-5);
}
.tk-stat { @apply flex flex-col; gap: var(--space-1); padding: var(--space-4) var(--space-5); border-right: 1px solid var(--color-border); }
.tk-stat:last-child { border-right: none; }
.tk-stat-label { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.tk-stat-value { font-size: var(--text-xl-size); font-weight: 600; color: var(--color-text); }
.tk-stat-value.late { color: var(--color-danger-text); }

/* ── View switch ────────────────────────────────────────────────────────── */
.tk-tabs { @apply flex; gap: var(--space-5); border-bottom: 1px solid var(--color-border); margin-bottom: var(--space-4); }
.tk-tab {
  @apply inline-flex cursor-pointer items-center border-none bg-transparent;
  gap: var(--space-2); padding: var(--space-3) 0; margin-bottom: -1px;
  font-family: inherit; font-size: var(--text-sm-size); color: var(--color-text-muted);
  border-bottom: 2px solid transparent;
}
.tk-tab svg { width: 1rem; height: 1rem; }
.tk-tab.active { color: var(--color-brand-on-surface); border-bottom-color: var(--color-brand-base); font-weight: 500; }

.tk-chips { @apply flex flex-wrap; gap: var(--space-2); margin-bottom: var(--space-4); }
.tk-chip {
  @apply inline-flex cursor-pointer items-center; gap: var(--space-2);
  padding: 6px var(--space-4); border-radius: 999px;
  border: 1px solid var(--color-border-strong); background: var(--color-surface);
  font-family: inherit; font-size: var(--text-sm-size); color: var(--color-text-muted);
}
.tk-chip.active { background: var(--color-brand-base); color: var(--color-on-brand); border-color: var(--color-brand-base); }
.tk-chip-count {
  @apply inline-flex items-center justify-center; min-width: 1.1rem; height: 1.1rem;
  padding: 0 4px; border-radius: 999px; font-size: 0.65rem; font-weight: 600;
  background: var(--color-surface-sunken); color: var(--color-text-muted);
}
.tk-chip.active .tk-chip-count { background: rgb(255 255 255 / 0.25); color: var(--color-on-brand); }

.tk-toolbar { @apply flex flex-wrap justify-end; gap: var(--space-2); margin-bottom: var(--space-4); }
.tk-search { max-width: 22rem; }
.tk-filters {
  @apply flex flex-wrap items-end; gap: var(--space-4);
  padding: var(--space-4); margin-bottom: var(--space-4);
  border: 1px solid var(--color-border); border-radius: var(--radius-lg);
  background: var(--color-surface-raised);
}
.tk-loading { padding: var(--space-6); color: var(--color-text-muted); }

/* ── Board ──────────────────────────────────────────────────────────────── */
.tk-board { @apply flex; gap: var(--space-4); overflow-x: auto; padding-bottom: var(--space-4); }
.tk-col { @apply flex flex-col shrink-0; width: 17rem; gap: var(--space-3); }
.tk-col-head { @apply flex items-center; gap: var(--space-2); }
.tk-col-title { font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); }
.tk-col-count {
  @apply inline-flex items-center justify-center; min-width: 1.1rem; height: 1.1rem;
  padding: 0 4px; border-radius: var(--radius-sm); font-size: 0.65rem; font-weight: 600;
  background: var(--color-brand-subtle); color: var(--color-brand-on-surface);
}
.tk-col-add {
  @apply ml-auto cursor-pointer border-none bg-transparent p-0;
  font-size: 1.1rem; line-height: 1; color: var(--color-text-muted);
}
.tk-col-empty { font-size: var(--text-xs-size); color: var(--color-text-light); margin: 0; }

.tk-card {
  @apply flex cursor-pointer flex-col; gap: var(--space-2);
  padding: var(--space-4); border: 1px solid var(--color-border);
  border-radius: var(--radius-lg); background: var(--color-surface-raised);
}
.tk-card:hover { border-color: var(--color-border-strong); }
.tk-card-title { font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); margin: 0; }
.tk-card-body {
  font-size: var(--text-xs-size); color: var(--color-text-muted); margin: 0;
  display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}
.tk-card-issues { font-size: var(--text-xs-size); color: var(--color-danger-text); margin: 0; }
.tk-card-owner { font-size: var(--text-xs-size); color: var(--color-warning-text); margin: 0; }
.tk-auto {
  margin-left: var(--space-1); padding: 0 var(--space-2); border-radius: var(--radius-sm);
  background: var(--color-brand-subtle); color: var(--color-brand-on-surface);
  font-size: 0.6rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em;
}

.tk-sched {
  @apply flex flex-col; gap: var(--space-1);
  padding: var(--space-3); border: 1px solid var(--color-border);
  border-radius: var(--radius-md); background: var(--color-surface);
}
.tk-sched.off { opacity: 0.6; }
.tk-sched-head { @apply flex flex-wrap items-center; gap: var(--space-2); font-size: var(--text-sm-size); }
.tk-sched-meta { font-size: var(--text-xs-size); color: var(--color-text-muted); margin: 0; }
.tk-sched-actions { @apply flex justify-end; gap: var(--space-2); margin-top: var(--space-2); }
.tk-sched-form { @apply flex flex-col; gap: var(--space-3); padding: var(--space-3); border: 1px dashed var(--color-border-strong); border-radius: var(--radius-md); }
.tk-external {
  padding: 1px var(--space-2); border-radius: var(--radius-sm);
  background: var(--color-warning-subtle); color: var(--color-warning-text);
  font-size: var(--text-xs-size); white-space: nowrap;
}
.tk-stat-btn { @apply cursor-pointer border-none text-left; background: transparent; font-family: inherit; border-right: 1px solid var(--color-border); }
.tk-stat-btn.active { background: var(--color-warning-subtle); }
.tk-stat-btn.active .tk-stat-value { color: var(--color-warning-text); }
.tk-mode {
  @apply flex flex-wrap items-center; gap: var(--space-2);
  padding: var(--space-3) var(--space-4); margin-bottom: var(--space-4);
  border-radius: var(--radius-md); background: var(--color-warning-subtle);
  color: var(--color-warning-text); font-size: var(--text-sm-size);
}
.tk-card-foot { @apply flex items-center justify-between; gap: var(--space-2); margin-top: var(--space-1); }

/* Priority flag, coloured as the designs have it. */
.tk-flag { @apply inline-flex items-center; gap: 4px; font-size: var(--text-xs-size); margin: 0; white-space: nowrap; }
.tk-flag svg { width: 0.85rem; height: 0.85rem; }
.tk-flag.high   { color: var(--color-danger-text); }
.tk-flag.medium { color: var(--color-warning-text); }
.tk-flag.low    { color: var(--color-text-muted); }

.tk-stack { @apply inline-flex items-center; }
.tk-avatar {
  @apply inline-flex shrink-0 items-center justify-center;
  width: 1.5rem; height: 1.5rem; border-radius: 999px; margin-right: -0.4rem;
  border: 2px solid var(--color-surface-raised);
  background: var(--color-surface-sunken); color: var(--color-text-muted);
  font-size: 0.55rem; font-weight: 600;
}
.tk-avatar.more { background: var(--color-brand-subtle); color: var(--color-brand-on-surface); }
.tk-unowned { font-size: var(--text-xs-size); color: var(--color-warning-text); }

.tk-due { @apply inline-flex items-center; gap: 4px; font-size: var(--text-xs-size); color: var(--color-text-muted); white-space: nowrap; }
.tk-due svg { width: 0.85rem; height: 0.85rem; }
.tk-due.late { color: var(--color-danger-text); font-weight: 500; }

/* ── List ───────────────────────────────────────────────────────────────── */
.tk-empty { @apply flex flex-col items-center; padding: var(--space-8) var(--space-6); text-align: center; gap: var(--space-2); }
.tk-empty-title { font-size: var(--text-lg-size); font-weight: 600; color: var(--color-text); margin: 0; }
.tk-empty-sub { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: 0 0 var(--space-3); }

.tk-table-card {
  background: var(--color-surface-raised); border: 1px solid var(--color-border);
  border-radius: var(--radius-lg); overflow-x: auto;
}
.tk-table { @apply w-full; border-collapse: collapse; font-size: var(--text-sm-size); }
.tk-table th {
  text-align: left; padding: var(--space-3) var(--space-4);
  font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-muted);
  background: var(--color-surface-sunken);
  border-bottom: 1px solid var(--color-border); white-space: nowrap;
}
.tk-table td { padding: var(--space-3) var(--space-4); border-bottom: 1px solid var(--color-border); color: var(--color-text); }
.tk-table tr:last-child td { border-bottom: none; }
.tk-name { font-weight: 500; }
.tk-nowrap { white-space: nowrap; }
.tk-muted { color: var(--color-text-light); }
.tk-quiet { font-size: var(--text-sm-size); margin: 0; }
/* Late work reads as late without comparing two dates by eye. */
.tk-table tr.overdue td { background: color-mix(in oklab, var(--color-danger-subtle) 40%, transparent); }

.tk-pager { @apply flex flex-wrap items-center justify-center; gap: var(--space-2); padding: var(--space-4); }
.tk-page {
  @apply inline-flex cursor-pointer items-center justify-center;
  min-width: 2rem; height: 2rem; border-radius: var(--radius-md);
  border: 1px solid transparent; background: transparent;
  font-family: inherit; font-size: var(--text-sm-size); color: var(--color-text-muted);
}
.tk-page.active { border-color: var(--color-brand-base); color: var(--color-brand-on-surface); }

.tk-tag {
  padding: 1px var(--space-2); border-radius: var(--radius-sm);
  background: var(--color-surface-sunken); color: var(--color-text-muted);
  font-size: var(--text-xs-size); white-space: nowrap;
}

.tk-field { @apply flex flex-col; gap: var(--space-1); }
.tk-field > span { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.tk-hint { font-size: var(--text-xs-size); color: var(--color-text-light); }
.tk-row { @apply grid; grid-template-columns: 1fr 1fr; gap: var(--space-3); }
.tk-check { @apply flex cursor-pointer items-center; gap: var(--space-2); font-size: var(--text-sm-size); color: var(--color-text); }

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
.pill-warn { background: var(--color-warning-subtle); color: var(--color-warning-text); border-color: color-mix(in oklab, var(--color-warning-text) 30%, transparent); }
.pill-bad  { background: var(--color-danger-subtle);  color: var(--color-danger-text);  border-color: color-mix(in oklab, var(--color-danger-text) 30%, transparent); }

.link-btn { @apply cursor-pointer border-none bg-transparent p-0; font-family: inherit; font-size: var(--text-sm-size); color: var(--color-brand-on-surface); text-align: left; }
.link-btn:hover { @apply underline; }
.link-btn:disabled { @apply cursor-not-allowed no-underline opacity-40; }

.tk-sheet-backdrop { @apply fixed inset-0 flex justify-end z-50; background: rgb(0 0 0 / 0.28); }
.tk-sheet {
  @apply flex flex-col overflow-y-auto; width: min(28rem, 100%); gap: var(--space-4);
  padding: var(--space-5); background: var(--color-surface-raised); box-shadow: var(--shadow-lg);
  animation: sheet-in 180ms ease;
}
@keyframes sheet-in { from { transform: translateX(12px); opacity: 0; } }
@media (prefers-reduced-motion: reduce) { .tk-sheet, .tk-modal { animation: none; } }
.tk-sheet-head { @apply flex items-center justify-between; }
.tk-sheet-title { font-size: var(--text-base-size); font-weight: 600; margin: 0; color: var(--color-text); }
.tk-sheet-sub { font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-muted); margin: 0; text-transform: uppercase; letter-spacing: 0.04em; }
.tk-close { @apply cursor-pointer border-none bg-transparent p-0; font-size: 1.5rem; line-height: 1; color: var(--color-text-muted); }

.tk-modal-backdrop { @apply fixed inset-0 flex items-center justify-center z-50; background: rgb(0 0 0 / 0.32); padding: var(--space-4); }
.tk-modal {
  @apply flex flex-col overflow-y-auto; width: min(26rem, 100%); max-height: 90vh; gap: var(--space-4);
  padding: var(--space-5); background: var(--color-surface-raised);
  border-radius: var(--radius-lg); box-shadow: var(--shadow-lg);
  animation: modal-in 150ms ease;
}
@keyframes modal-in { from { transform: scale(0.98); opacity: 0; } }
.tk-modal-head { @apply flex items-center justify-between; }
.tk-modal-title { font-size: var(--text-lg-size); font-weight: 600; margin: 0; color: var(--color-text); }

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
