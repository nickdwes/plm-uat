<script setup lang="ts">
/**
 * Travel requests — the traveller's own list, and an approver's queue.
 *
 * One list serves both, because the API already decides what a caller may see:
 * your own requests, plus anything waiting on you, plus your team's if you
 * hold the permission. A separate "approvals" screen would duplicate that
 * logic in the client and drift from it.
 */
import { ref, computed, onMounted } from "vue";
import { useRouter } from "vue-router";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";

interface Approval { stage: string; status: string; sequence: number }
interface TravelRequestRow {
  id: number;
  reference: string | null;
  status: string;
  destination: { id: number; name: string } | null;
  departure_date: string | null;
  return_date: string | null;
  currency: string;
  total_claimed: string;
  submitted_at: string | null;
  created_at: string | null;
  approvals?: Approval[];
}

const STAGE_LABELS: Record<string, string> = {
  department_head: "line manager",
  function_head:   "function head",
  finance:         "finance",
  executive:       "the CFO",
};

const router  = useRouter();
const rows    = ref<TravelRequestRow[]>([]);
const loading = ref(true);
const error   = ref<string | null>(null);
const search  = ref("");
const statusFilter = ref("");

async function load() {
  loading.value = true;
  try {
    const res = await api<{ data: TravelRequestRow[] }>("GET", "/api/v1/travel-requests", {
      org: currentOrgSlug.value,
      params: { status: statusFilter.value || undefined, per_page: 50 },
    });
    rows.value = res.data;
  } catch (e) {
    error.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not load travel requests.";
  } finally {
    loading.value = false;
  }
}

/**
 * "Awaiting finance" rather than "in approval".
 *
 * A four-stage chain shown as one status tells the traveller nothing about
 * where their request has reached, which is what makes people chase approvers
 * by email. The stage is derived from the pending approval rather than stored,
 * so it cannot disagree with the chain itself.
 */
function statusLabel(row: TravelRequestRow): string {
  if (row.status !== "in_approval") return row.status.replace("_", " ");

  const pending = row.approvals?.filter((a) => a.status === "pending")
    .sort((a, b) => a.sequence - b.sequence)[0];

  return pending ? `awaiting ${STAGE_LABELS[pending.stage] ?? pending.stage}` : "in approval";
}

function statusTone(status: string): string {
  if (status === "approved" || status === "disbursed") return "ok";
  if (status === "rejected") return "bad";
  if (status === "draft" || status === "cancelled") return "neutral";
  return "warn";
}

const filtered = computed(() => {
  const term = search.value.trim().toLowerCase();
  if (!term) return rows.value;
  return rows.value.filter((r) =>
    (r.reference ?? "").toLowerCase().includes(term) ||
    (r.destination?.name ?? "").toLowerCase().includes(term));
});

const dateRange = (r: TravelRequestRow) =>
  r.departure_date && r.return_date ? `${r.departure_date} – ${r.return_date}` : "—";

onMounted(load);
</script>

<template>
  <div class="tr-wrap">
    <header class="tr-header">
      <div>
        <h1 class="tr-title">Travel Requests</h1>
        <p class="tr-sub">Manage your travel requests and expenses</p>
      </div>
      <button v-if="rows.length" class="btn-primary" @click="router.push({ name: 'travel-request-new' })">
        + Travel Request
      </button>
    </header>

    <div v-if="error" class="alert-error">{{ error }}</div>
    <div v-if="loading" class="tr-loading">Loading…</div>

    <!-- Empty state carries the action, so a first-time user has one thing to do. -->
    <div v-else-if="rows.length === 0" class="tr-empty">
      <h2 class="tr-empty-title">No travel requests yet</h2>
      <p class="tr-empty-sub">You currently don't have any travel requests.</p>
      <button class="btn-primary" @click="router.push({ name: 'travel-request-new' })">
        + Travel request
      </button>
    </div>

    <template v-else>
      <div class="tr-toolbar">
        <input v-model="search" class="control tr-search" type="search"
               placeholder="Search by reference or destination" />
        <select v-model="statusFilter" class="control tr-filter" @change="load">
          <option value="">All statuses</option>
          <option value="draft">Draft</option>
          <option value="in_approval">In approval</option>
          <option value="approved">Approved</option>
          <option value="rejected">Rejected</option>
        </select>
      </div>

      <table class="tr-table">
        <thead>
          <tr>
            <th>Request ID</th><th>Destination</th><th>Travel dates</th>
            <th>Estimated cost</th><th>Submitted</th><th>Status</th><th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="filtered.length === 0">
            <td colspan="7" class="tr-none">Nothing matches that search.</td>
          </tr>
          <tr v-for="r in filtered" :key="r.id">
            <td class="tr-ref">{{ r.reference ?? "—" }}</td>
            <td>{{ r.destination?.name ?? "—" }}</td>
            <td>{{ dateRange(r) }}</td>
            <td class="tr-amount">{{ r.currency }} {{ Number(r.total_claimed).toLocaleString() }}</td>
            <td>{{ r.submitted_at ? r.submitted_at.slice(0, 10) : "—" }}</td>
            <td><span :class="['pill', `pill-${statusTone(r.status)}`]">{{ statusLabel(r) }}</span></td>
            <td>
              <button class="link-btn" @click="router.push({ name: 'travel-request', params: { id: r.id } })">
                View
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </template>
  </div>
</template>

<style scoped>
@reference "../../theme.css";

.tr-wrap { padding: var(--space-6); max-width: 78rem; }

.tr-header { @apply flex items-start justify-between; gap: var(--space-4); margin-bottom: var(--space-5); }
.tr-title { font-size: var(--text-2xl-size); font-weight: 600; color: var(--color-text); margin: 0; }
.tr-sub   { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: var(--space-1) 0 0; }

.tr-loading { padding: var(--space-6); color: var(--color-text-muted); }

.tr-empty { @apply flex flex-col items-center; padding: var(--space-8) var(--space-6); text-align: center; gap: var(--space-2); }
.tr-empty-title { font-size: var(--text-lg-size); font-weight: 600; color: var(--color-text); margin: 0; }
.tr-empty-sub   { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: 0 0 var(--space-3); }

.tr-toolbar { @apply flex flex-wrap; gap: var(--space-3); margin-bottom: var(--space-4); }
.tr-search { max-width: 22rem; flex: 1; }
.tr-filter { max-width: 12rem; }

.control {
  font-family: inherit; font-size: var(--text-sm-size);
  padding: 8px var(--space-3);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-md);
  background: var(--color-surface);
  color: var(--color-text);
  width: 100%;
}
.control:focus {
  @apply outline-none;
  border-color: var(--color-brand-base);
  box-shadow: 0 0 0 3px var(--color-brand-subtle);
}

.tr-table { @apply w-full; border-collapse: collapse; font-size: var(--text-sm-size); }
.tr-table th {
  text-align: left; padding: var(--space-2) var(--space-3);
  font-size: var(--text-xs-size); font-weight: 600;
  color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.04em;
  border-bottom: 1px solid var(--color-border);
}
.tr-table td { padding: var(--space-3); border-bottom: 1px solid var(--color-border); color: var(--color-text); }
.tr-ref { font-variant-numeric: tabular-nums; font-weight: 500; }
.tr-amount { font-variant-numeric: tabular-nums; }
.tr-none { text-align: center; color: var(--color-text-light); padding: var(--space-6); }

.pill {
  display: inline-block; width: fit-content;
  padding: 2px var(--space-2);
  border-radius: 999px;
  font-size: var(--text-xs-size);
  text-transform: capitalize;
  border: 1px solid transparent;
}
/* Feedback ramps rather than the brand, so status never changes meaning when
   an organisation changes its colour. */
.pill-ok      { background: var(--color-success-subtle); color: var(--color-success-text); }
.pill-warn    { background: var(--color-warning-subtle); color: var(--color-warning-text); }
.pill-bad     { background: var(--color-danger-subtle);  color: var(--color-danger-text); }
.pill-neutral { background: var(--color-surface-sunken); color: var(--color-text-muted); }

.link-btn {
  @apply cursor-pointer border-none bg-transparent p-0;
  font-family: inherit; font-size: var(--text-sm-size);
  color: var(--color-brand-on-surface);
}
.link-btn:hover { @apply underline; }

.btn-primary {
  @apply cursor-pointer border-none;
  padding: 8px var(--space-4);
  border-radius: var(--radius-md);
  font-family: inherit; font-size: var(--text-sm-size); font-weight: 500;
  background: var(--color-brand-base);
  color: var(--color-on-brand);
}
.btn-primary:disabled { @apply cursor-not-allowed opacity-50; }

.alert-error {
  padding: var(--space-3) var(--space-4);
  border-radius: var(--radius-md);
  font-size: var(--text-sm-size);
  background: var(--color-danger-subtle);
  color: var(--color-danger-text);
  margin-bottom: var(--space-4);
}
</style>
