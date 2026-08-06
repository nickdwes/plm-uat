<script setup lang="ts">
/**
 * The lead board.
 *
 * Two views of one thing. The board is for working — where is everything, what
 * moves next. The list is for reading — who owns what, at what priority,
 * sortable and scannable. Neither is a subset of the other, which is why both
 * are here rather than one being a filter of the other.
 *
 * Column counts come from the API rather than from the cards on screen. A
 * column that says 10 while showing 3 is worse than no count at all, and that
 * is exactly what counting the rendered cards would produce once a column is
 * bigger than the page fetches.
 */
import { ref, computed, onMounted } from "vue";
import { useRouter } from "vue-router";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import { notifySaved, notifyFailed } from "../../lib/toast";
import AccountPicker from "../../components/AccountPicker.vue";

interface Person { id: number | null; name: string | null }
interface TeamMember { id: number; employee: Person }
interface Lead {
  id: number; reference: string | null; name: string; summary: string | null;
  source: string | null; status: string; priority: string;
  account?: { id: number; name: string; initials: string } | null;
  owner?: Person | null;
  team?: TeamMember[];
  updated_at: string | null;
}
interface Column { status: string; total: number; leads: Lead[] }

const router  = useRouter();
const view    = ref<"kanban" | "list">("kanban");
const columns = ref<Column[]>([]);
const rows    = ref<Lead[]>([]);
const loading = ref(true);
const saving  = ref(false);

/** Every failure goes to a toast; nothing on this screen renders its own. */
function fail(e: unknown, fallback: string) {
  notifyFailed(fallback, e instanceof ApiError ? (e.firstError ?? e.message) : undefined);
}

const search       = ref("");
const priority     = ref("");
const showFilters  = ref(false);
const listStatus   = ref("new");

const showNew = ref(false);
const newLead = ref({
  name: "", summary: "", source: "", priority: "medium", status: "new",
  account_id: null as number | null,
});

/**
 * A lead names the customer it is for.
 *
 * Required here rather than left until conversion: a lead with no customer
 * cannot be counted towards anybody's book, cannot appear on the customer's
 * record, and the person who knows which company it came from is the one
 * raising it — not whoever picks it up three weeks later.
 */
const canCreate = computed(() =>
  Boolean(newLead.value.name.trim() && newLead.value.account_id));

const org = () => currentOrgSlug.value;

const STATUS_LABELS: Record<string, string> = {
  new: "New Leads", contacted: "Contacted", qualified: "Qualified", unqualified: "Unqualified",
};

async function loadBoard() {
  loading.value = true;
  try {
    columns.value = (await api<{ data: Column[] }>("GET", "/api/v1/leads/board", {
      org: org(),
      params: { search: search.value || undefined, priority: priority.value || undefined },
    })).data;
  } catch (e) {
    fail(e, "Could not load the board.");
  } finally { loading.value = false; }
}

async function loadList() {
  loading.value = true;
  try {
    rows.value = (await api<{ data: Lead[] }>("GET", "/api/v1/leads", {
      org: org(),
      params: { status: listStatus.value, search: search.value || undefined, per_page: 100 },
    })).data;
  } catch (e) {
    fail(e, "Could not load leads.");
  } finally { loading.value = false; }
}

const reload = () => (view.value === "kanban" ? loadBoard() : loadList());

function switchTo(v: "kanban" | "list") {
  view.value = v;
  void reload();
}

async function create(status?: string) {
  if (!canCreate.value) return;
  saving.value = true;
  try {
    const res = await api<{ data: Lead }>("POST", "/api/v1/leads", {
      org: org(),
      body: {
        ...newLead.value,
        status: status ?? newLead.value.status,
        summary: newLead.value.summary || null,
        source: newLead.value.source || null,
      },
    });
    showNew.value = false;
    newLead.value = {
      name: "", summary: "", source: "", priority: "medium", status: "new", account_id: null,
    };

    // Raised before navigating. The toast lives on the shell, so it survives
    // the route change — an inline banner here would be destroyed by it.
    notifySaved(`${res.data.name} added.`);
    router.push({ name: "lead", params: { id: res.data.id } });
  } catch (e) {
    fail(e, "Could not raise the lead.");
  } finally { saving.value = false; }
}

function openNewIn(status: string) {
  newLead.value = {
    name: "", summary: "", source: "", priority: "medium", status, account_id: null,
  };
  showNew.value = true;
}

// ── Dragging ────────────────────────────────────────────────────────────────

const dragging = ref<Lead | null>(null);
const overColumn = ref<string | null>(null);

/**
 * Moved through its own endpoint rather than a full update.
 *
 * A drag is one gesture, and sending the whole lead back to express it is how
 * a drag ends up blanking a description somebody wrote an hour ago.
 */
async function drop(status: string) {
  const lead = dragging.value;
  dragging.value = null;
  overColumn.value = null;

  if (!lead || lead.status === status) return;

  // Moved on screen first: a card that hangs where it was until the network
  // answers reads as a failed drag.
  const from = columns.value.find((c) => c.status === lead.status);
  const to   = columns.value.find((c) => c.status === status);
  if (from && to) {
    from.leads = from.leads.filter((l) => l.id !== lead.id);
    from.total -= 1;
    to.leads = [{ ...lead, status }, ...to.leads];
    to.total += 1;
  }

  try {
    await api("POST", `/api/v1/leads/${lead.id}/status`, { org: org(), body: { status } });
  } catch (e) {
    fail(e, "Could not move that lead.");
    void loadBoard();   // put the board back to what the server believes
  }
}

// ── Presentation ────────────────────────────────────────────────────────────

const priorityTone = (p: string) =>
  p === "high" ? "high" : p === "low" ? "low" : "medium";

const statusTone = (s: string) =>
  s === "qualified" ? "ok" : s === "unqualified" ? "danger" : s === "contacted" ? "info" : "warn";

/** "2 hours ago" reads as activity; a timestamp does not. */
function ago(iso: string | null): string {
  if (!iso) return "—";
  const secs = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
  const steps: [number, Intl.RelativeTimeFormatUnit][] =
    [[60, "second"], [3600, "minute"], [86400, "hour"], [604800, "day"], [2629800, "week"], [31557600, "month"]];
  const fmt = new Intl.RelativeTimeFormat(undefined, { numeric: "auto" });

  let prev = 1;
  for (const [limit, unit] of steps) {
    if (secs < limit) return fmt.format(-Math.floor(secs / prev), unit);
    prev = limit;
  }
  return fmt.format(-Math.floor(secs / 31557600), "year");
}

const initials = (name: string | null | undefined) =>
  (name ?? "?").split(/\s+/).slice(0, 2).map((w) => w[0]).join("").toUpperCase();

const listCount = computed(() =>
  columns.value.find((c) => c.status === listStatus.value)?.total ?? rows.value.length);

onMounted(() => { void loadBoard(); });
</script>

<template>
  <div class="lv-wrap">
    <header class="lv-header">
      <div>
        <h1 class="lv-title">Leads</h1>
        <p class="lv-sub">Manage your leads effectively to maximize your sales potential.</p>
      </div>
      <button class="btn-primary" @click="openNewIn('new')">
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <path d="M10 4v12M4 10h12" />
        </svg>
        New Lead
      </button>
    </header>

    <nav class="lv-views" role="tablist">
      <button role="tab" :aria-selected="view === 'kanban'"
              :class="['lv-view', { active: view === 'kanban' }]" @click="switchTo('kanban')">
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6">
          <rect x="3" y="4" width="4" height="12" rx="1" /><rect x="8.5" y="4" width="4" height="8" rx="1" />
          <rect x="14" y="4" width="3" height="10" rx="1" />
        </svg>
        Kanban
      </button>
      <button role="tab" :aria-selected="view === 'list'"
              :class="['lv-view', { active: view === 'list' }]" @click="switchTo('list')">
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round">
          <path d="M4 6h12M4 10h12M4 14h8" />
        </svg>
        List view
      </button>
    </nav>

    <!-- Status chips, list view only: the board already shows every column. -->
    <div v-if="view === 'list'" class="lv-chips">
      <button v-for="(label, key) in STATUS_LABELS" :key="key"
              :class="['lv-chip', { active: listStatus === key }]"
              @click="listStatus = key; loadList()">
        {{ label }}
        <span v-if="listStatus === key" class="lv-chip-count">{{ listCount }}</span>
      </button>
    </div>

    <div class="lv-toolbar">
      <div class="lv-search-wrap">
        <svg class="lv-search-icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7">
          <circle cx="9" cy="9" r="6" /><path d="M13.5 13.5L17 17" stroke-linecap="round" />
        </svg>
        <input v-model="search" class="control lv-search" type="search" placeholder="Search"
               @keyup.enter="reload" />
      </div>
      <button class="btn-ghost" :class="{ 'btn-on': priority }" @click="showFilters = !showFilters">
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round">
          <path d="M3 5h14M6 10h8M8.5 15h3" />
        </svg>
        Filter
      </button>
    </div>

    <div v-if="showFilters" class="lv-filters">
      <label class="lv-field"><span>Priority</span>
        <select v-model="priority" class="control" @change="reload">
          <option value="">All</option>
          <option value="high">High</option>
          <option value="medium">Medium</option>
          <option value="low">Low</option>
        </select>
      </label>
    </div>

    <div v-if="loading" class="lv-loading">Loading…</div>

    <!-- ── Kanban ────────────────────────────────────────────────────── -->
    <div v-else-if="view === 'kanban'" class="lv-board">
      <section v-for="col in columns" :key="col.status"
               :class="['lv-col', { over: overColumn === col.status }]"
               @dragover.prevent="overColumn = col.status"
               @dragleave="overColumn = null"
               @drop.prevent="drop(col.status)">
        <header class="lv-col-head">
          <span :class="['lv-col-name', `tone-${statusTone(col.status)}`]">
            {{ STATUS_LABELS[col.status] }}
          </span>
          <span class="lv-col-count">{{ col.total }}</span>
          <button class="lv-col-add" :aria-label="`New lead in ${STATUS_LABELS[col.status]}`"
                  @click="openNewIn(col.status)">+</button>
        </header>

        <p v-if="col.leads.length === 0" class="lv-col-empty">Nothing here.</p>

        <article v-for="l in col.leads" :key="l.id" class="lv-card" draggable="true"
                 @dragstart="dragging = l"
                 @dragend="dragging = null; overColumn = null"
                 @click="router.push({ name: 'lead', params: { id: l.id } })">
          <span :class="['lv-priority', `prio-${priorityTone(l.priority)}`]">
            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round">
              <path d="M5 3v14M5 4h9l-2 3 2 3H5" />
            </svg>
            {{ l.priority }} priority
          </span>

          <h3 class="lv-card-title">{{ l.name }}</h3>
          <p v-if="l.summary" class="lv-card-sub">{{ l.summary }}</p>

          <footer class="lv-card-foot">
            <span class="lv-card-when">Updated {{ ago(l.updated_at) }}</span>
            <span v-if="l.owner?.name" class="lv-owner">
              <span class="lv-avatar">{{ initials(l.owner.name) }}</span>{{ l.owner.name }}
            </span>
          </footer>
        </article>

        <!-- The board caps a column so it cannot stall; said out loud rather
             than silently truncating. -->
        <p v-if="col.total > col.leads.length" class="lv-col-more">
          Showing {{ col.leads.length }} of {{ col.total }} — narrow the search to see the rest.
        </p>
      </section>
    </div>

    <!-- ── List ──────────────────────────────────────────────────────── -->
    <div v-else class="lv-card-panel">
      <table class="lv-table">
        <thead>
          <tr><th>Project</th><th>Lead Owner</th><th>Assignee</th><th>Priority</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <tr v-if="rows.length === 0">
            <td colspan="5" class="lv-none">No leads in this column.</td>
          </tr>
          <tr v-for="l in rows" :key="l.id">
            <td class="lv-name">{{ l.name }}</td>
            <td>
              <span v-if="l.owner?.name" class="lv-owner">
                <span class="lv-avatar">{{ initials(l.owner.name) }}</span>{{ l.owner.name }}
              </span>
              <span v-else class="lv-muted">—</span>
            </td>
            <td>
              <span v-if="!l.team?.length" class="lv-muted">—</span>
              <span v-else class="lv-stack">
                <span v-for="m in l.team.slice(0, 4)" :key="m.id" class="lv-chip-avatar"
                      :title="m.employee.name ?? ''">{{ initials(m.employee.name) }}</span>
                <span v-if="l.team.length > 4" class="lv-chip-avatar more">+{{ l.team.length - 4 }}</span>
              </span>
            </td>
            <td>
              <span :class="['lv-priority', `prio-${priorityTone(l.priority)}`]">
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round">
                  <path d="M5 3v14M5 4h9l-2 3 2 3H5" />
                </svg>
                {{ l.priority }} priority
              </span>
            </td>
            <td><button class="link-btn" @click="router.push({ name: 'lead', params: { id: l.id } })">View</button></td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- New lead.
         A slide-over rather than a centred modal: it is the same act as New
         Estimate, New Bid and New Research, and those are panels. A dialog
         that blackens the board also hides the column you were adding to. -->
    <div v-if="showNew" class="lv-sheet-backdrop" @click.self="showNew = false">
      <aside class="lv-sheet" role="dialog" aria-modal="true" aria-label="New lead">
        <div class="lv-sheet-head">
          <h3 class="lv-sheet-title">New Lead</h3>
          <button class="lv-close" aria-label="Close" @click="showNew = false">×</button>
        </div>

        <div class="lv-field">
          <span>Customer <em class="lv-req">required</em></span>
          <AccountPicker v-model="newLead.account_id" placeholder="Which customer is this for?" />
          <span class="lv-hint">
            Not on file yet? Add them under Customers first — a lead has to belong
            to somebody.
          </span>
        </div>

        <label class="lv-field"><span>Lead name</span>
          <input v-model="newLead.name" class="control" placeholder="Integrate AI lead scoring module" /></label>

        <label class="lv-field"><span>One-line summary</span>
          <input v-model="newLead.summary" class="control" placeholder="Confirm data integrity with legacy lead database" />
          <span class="lv-hint">This is the line the board card shows under the title.</span></label>

        <label class="lv-field"><span>Source</span>
          <input v-model="newLead.source" class="control" placeholder="Website, referral, LinkedIn" /></label>

        <label class="lv-field"><span>Priority</span>
          <select v-model="newLead.priority" class="control">
            <option value="low">Low</option>
            <option value="medium">Medium</option>
            <option value="high">High</option>
          </select></label>

        <label class="lv-field"><span>Column</span>
          <select v-model="newLead.status" class="control">
            <option v-for="(label, key) in STATUS_LABELS" :key="key" :value="key">{{ label }}</option>
          </select></label>

        <button class="btn-primary btn-block" :disabled="saving || !canCreate" @click="create()">
          {{ saving ? "Adding…" : "Add Lead" }}
        </button>
      </aside>
    </div>
  </div>
</template>

<style scoped>
@reference "../../theme.css";

.lv-wrap { padding: var(--space-6); max-width: 88rem; }

.lv-header { @apply flex items-start justify-between; gap: var(--space-4); margin-bottom: var(--space-4); }
.lv-title { font-size: var(--text-xl-size); font-weight: 600; color: var(--color-text); margin: 0; }
.lv-sub   { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: var(--space-1) 0 0; }

.lv-views { @apply flex gap-1; border-bottom: 1px solid var(--color-border); margin-bottom: var(--space-4); }
.lv-view {
  @apply inline-flex cursor-pointer items-center border-none bg-transparent;
  gap: var(--space-2); padding: var(--space-2) var(--space-3);
  font-family: inherit; font-size: var(--text-sm-size); color: var(--color-text-muted);
  border-bottom: 2px solid transparent; margin-bottom: -1px;
}
.lv-view svg { width: 1rem; height: 1rem; }
.lv-view:hover { color: var(--color-text); }
.lv-view.active { color: var(--color-brand-on-surface); border-bottom-color: var(--color-brand-base); font-weight: 500; }

.lv-chips { @apply flex flex-wrap; gap: var(--space-2); margin-bottom: var(--space-4); }
.lv-chip {
  @apply inline-flex cursor-pointer items-center;
  gap: var(--space-2); padding: 6px var(--space-4); border-radius: 999px;
  border: 1px solid var(--color-border-strong); background: var(--color-surface);
  font-family: inherit; font-size: var(--text-sm-size); color: var(--color-text-muted);
}
.lv-chip.active { background: var(--color-brand-base); color: var(--color-on-brand); border-color: var(--color-brand-base); }
.lv-chip-count { font-size: var(--text-xs-size); opacity: 0.85; }

.lv-toolbar { @apply flex flex-wrap items-center justify-end; gap: var(--space-3); margin-bottom: var(--space-4); }
.lv-search-wrap { @apply relative; flex: 1; min-width: 12rem; max-width: 26rem; margin-left: auto; }
.lv-search-icon { @apply absolute; width: 1rem; height: 1rem; left: var(--space-3); top: 50%; transform: translateY(-50%); color: var(--color-text-light); pointer-events: none; }
.lv-search { padding-left: 2.25rem; }

.lv-filters { @apply flex flex-wrap items-end; gap: var(--space-3); margin-bottom: var(--space-4); }
.lv-filters .lv-field { min-width: 10rem; }

.lv-loading { padding: var(--space-6); color: var(--color-text-muted); }

/* ── Board ─────────────────────────────────────────────────────────────── */
.lv-board { @apply flex overflow-x-auto; gap: var(--space-4); padding-bottom: var(--space-4); align-items: flex-start; }
.lv-col {
  @apply flex shrink-0 flex-col; width: 19rem; gap: var(--space-3);
  padding: var(--space-3); border-radius: var(--radius-lg);
  border: 1px dashed transparent;
}
.lv-col.over { border-color: var(--color-brand-base); background: var(--color-brand-subtle); }

.lv-col-head { @apply flex items-center; gap: var(--space-2); }
.lv-col-name { font-size: var(--text-sm-size); font-weight: 600; }
.tone-warn   { color: var(--color-warning-text); }
.tone-info   { color: var(--color-brand-on-surface); }
.tone-ok     { color: var(--color-success-text); }
.tone-danger { color: var(--color-danger-text); }
.lv-col-count { font-size: var(--text-xs-size); color: var(--color-text-light); }
.lv-col-add {
  @apply ml-auto cursor-pointer border-none bg-transparent p-0;
  font-size: var(--text-lg-size); line-height: 1; color: var(--color-text-light);
}
.lv-col-add:hover { color: var(--color-text); }
.lv-col-empty { font-size: var(--text-xs-size); color: var(--color-text-light); margin: 0; padding: var(--space-3); }
.lv-col-more  { font-size: var(--text-xs-size); color: var(--color-text-light); margin: 0; }

.lv-card {
  @apply flex cursor-pointer flex-col; gap: var(--space-2);
  padding: var(--space-3) var(--space-4);
  background: var(--color-surface-raised);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
}
.lv-card:hover { border-color: var(--color-border-strong); box-shadow: var(--shadow-sm); }
.lv-card-title { font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); margin: 0; }
.lv-card-sub   { font-size: var(--text-xs-size); color: var(--color-text-muted); margin: 0; line-height: 1.5; }
.lv-card-foot  { @apply flex flex-wrap items-center justify-between; gap: var(--space-2); padding-top: var(--space-2); border-top: 1px solid var(--color-border); }
.lv-card-when  { font-size: var(--text-xs-size); color: var(--color-text-light); }

.lv-priority { @apply inline-flex items-center; gap: 4px; font-size: var(--text-xs-size); text-transform: capitalize; white-space: nowrap; }
.lv-priority svg { width: 0.75rem; height: 0.75rem; }
.prio-high   { color: var(--color-danger-text); font-weight: 500; }
.prio-medium { color: var(--color-warning-text); }
.prio-low    { color: var(--color-text-muted); }

.lv-owner { @apply inline-flex items-center; gap: var(--space-2); font-size: var(--text-xs-size); color: var(--color-text-muted); }
.lv-avatar {
  @apply inline-flex items-center justify-center;
  width: 1.25rem; height: 1.25rem; border-radius: 999px;
  background: var(--color-surface-sunken); color: var(--color-text-muted);
  font-size: 0.6rem; font-weight: 600;
}

/* ── List ──────────────────────────────────────────────────────────────── */
.lv-card-panel {
  background: var(--color-surface-raised);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  overflow-x: auto;
}
.lv-table { @apply w-full; border-collapse: collapse; font-size: var(--text-sm-size); }
.lv-table th {
  text-align: left; padding: var(--space-3) var(--space-4);
  font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-muted);
  border-bottom: 1px solid var(--color-border); white-space: nowrap;
}
.lv-table td { padding: var(--space-3) var(--space-4); border-bottom: 1px solid var(--color-border); color: var(--color-text); }
.lv-table tr:last-child td { border-bottom: none; }
.lv-name { font-weight: 500; }
.lv-none { text-align: center; color: var(--color-text-light); padding: var(--space-6); }
.lv-muted { color: var(--color-text-light); }

.lv-stack { @apply inline-flex items-center; }
.lv-chip-avatar {
  @apply inline-flex items-center justify-center;
  width: 1.5rem; height: 1.5rem; margin-right: -0.35rem; border-radius: 999px;
  background: var(--color-surface-sunken); color: var(--color-text-muted);
  border: 2px solid var(--color-surface-raised);
  font-size: 0.6rem; font-weight: 600;
}
.lv-chip-avatar.more { background: var(--color-brand-subtle); color: var(--color-brand-on-surface); }

/* ── Shared ────────────────────────────────────────────────────────────── */
.lv-field { @apply flex flex-col; gap: var(--space-1); }
.lv-field > span { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.lv-hint { font-size: var(--text-xs-size); color: var(--color-text-light); }
.lv-req { font-style: normal; color: var(--color-danger-text); }

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

.lv-sheet-backdrop { @apply fixed inset-0 flex justify-end z-50; background: rgb(0 0 0 / 0.28); }
.lv-sheet {
  @apply flex flex-col overflow-y-auto; width: min(26rem, 100%); gap: var(--space-4);
  padding: var(--space-5); background: var(--color-surface-raised);
  box-shadow: var(--shadow-lg);
  animation: sheet-in 180ms ease;
}
@keyframes sheet-in { from { transform: translateX(12px); opacity: 0; } }
@media (prefers-reduced-motion: reduce) { .lv-sheet { animation: none; } }
.lv-sheet-head { @apply flex items-center justify-between; }
.lv-sheet-title { font-size: var(--text-base-size); font-weight: 600; margin: 0; color: var(--color-text); }
.lv-close { @apply cursor-pointer border-none bg-transparent p-0; font-size: 1.5rem; line-height: 1; color: var(--color-text-muted); }

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
