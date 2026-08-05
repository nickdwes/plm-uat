<script setup lang="ts">
/**
 * Deliveries.
 *
 * A separate screen from travel requests because it answers different
 * questions: not where somebody went, but what was moved, who carried it, and
 * whether it arrived. The same request underneath — one approval chain, one
 * reconciliation — presented as the document it actually is.
 *
 * Coordinated by finance from the procurement side, so the list is ordered by
 * what is still outstanding rather than by date.
 */
import { ref, computed, onMounted } from "vue";
import { useRouter } from "vue-router";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";

interface Row {
  id: number;
  reference: string | null;
  status: string;
  destination: { id: number; name: string } | null;
  departure_date: string | null;
  currency: string;
  total_claimed: string;
  delivery_note_attachment_id: number | null;
  approvals?: { stage: string; status: string; sequence: number }[];
}

const router  = useRouter();
const rows    = ref<Row[]>([]);
const loading = ref(true);
const error   = ref<string | null>(null);
const search  = ref("");

async function load() {
  loading.value = true;
  try {
    // The API decides what this caller may see; the list only asks for the
    // delivery slice of it.
    const res = await api<{ data: Row[] }>("GET", "/api/v1/travel-requests", {
      org: currentOrgSlug.value,
      // Asked for as deliveries rather than filtered in the browser: a page
      // of travel requests would otherwise arrive and be silently discarded.
      params: { travel_type: "delivery", per_page: 100 },
    });
    rows.value = res.data;
  } catch (e) {
    error.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not load deliveries.";
  } finally {
    loading.value = false;
  }
}

const STAGE_LABELS: Record<string, string> = {
  finance: "finance", operations_executive: "the COO",
  department_head: "line manager", function_head: "function head", executive: "the CFO",
};

function statusLabel(r: Row): string {
  if (r.status !== "in_approval") return r.status.replace("_", " ");
  const pending = r.approvals?.filter((a) => a.status === "pending")
    .sort((a, b) => a.sequence - b.sequence)[0];
  return pending ? `awaiting ${STAGE_LABELS[pending.stage] ?? pending.stage}` : "in approval";
}

const statusTone = (s: string) =>
  s === "closed" ? "ok" : s === "rejected" ? "bad"
  : s === "draft" || s === "cancelled" ? "neutral" : "warn";

const filtered = computed(() => {
  const term = search.value.trim().toLowerCase();
  if (!term) return rows.value;
  return rows.value.filter((r) =>
    (r.reference ?? "").toLowerCase().includes(term) ||
    (r.destination?.name ?? "").toLowerCase().includes(term));
});

onMounted(load);
</script>

<template>
  <div class="dl-wrap">
    <header class="dl-header">
      <div>
        <h1 class="dl-title">Deliveries</h1>
        <p class="dl-sub">Consignments, who is carrying them, and whether they arrived</p>
      </div>
      <button class="btn-primary" @click="router.push({ name: 'delivery-new' })">
        + Delivery request
      </button>
    </header>

    <div v-if="error" class="alert-error">{{ error }}</div>
    <div v-if="loading" class="dl-loading">Loading…</div>

    <div v-else-if="rows.length === 0" class="dl-empty">
      <h2 class="dl-empty-title">No deliveries yet</h2>
      <p class="dl-empty-sub">
        A delivery records what is being moved, who is carrying it, and the note it is
        signed for on. It goes to finance for approval rather than to a line manager.
      </p>
      <button class="btn-primary" @click="router.push({ name: 'delivery-new' })">
        + Delivery request
      </button>
    </div>

    <template v-else>
      <input v-model="search" class="control dl-search" type="search"
             placeholder="Search by reference or destination" />

      <table class="dl-table">
        <thead>
          <tr>
            <th>Reference</th><th>Destination</th><th>Date</th>
            <th>Cost</th><th>Delivery note</th><th>Status</th><th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="filtered.length === 0">
            <td colspan="7" class="dl-none">Nothing matches that search.</td>
          </tr>
          <tr v-for="r in filtered" :key="r.id">
            <td class="dl-ref">{{ r.reference ?? "—" }}</td>
            <td>{{ r.destination?.name ?? "—" }}</td>
            <td>{{ r.departure_date ?? "—" }}</td>
            <td class="dl-amount">{{ r.currency }} {{ Number(r.total_claimed).toLocaleString() }}</td>
            <td>
              <!-- The note is what a delivery closes against, so its absence
                   is the thing worth seeing from the list. -->
              <span v-if="r.delivery_note_attachment_id" class="pill pill-ok">received</span>
              <span v-else class="pill pill-warn">outstanding</span>
            </td>
            <td><span :class="['pill', `pill-${statusTone(r.status)}`]">{{ statusLabel(r) }}</span></td>
            <td>
              <button class="link-btn" @click="router.push({ name: 'delivery', params: { id: r.id } })">
                Open
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

.dl-wrap { padding: var(--space-6); max-width: 78rem; }

.dl-header { @apply flex items-start justify-between; gap: var(--space-4); margin-bottom: var(--space-5); }
.dl-title { font-size: var(--text-2xl-size); font-weight: 600; color: var(--color-text); margin: 0; }
.dl-sub   { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: var(--space-1) 0 0; }

.dl-loading { padding: var(--space-6); color: var(--color-text-muted); }

.dl-empty { @apply flex flex-col items-center; padding: var(--space-8) var(--space-6); text-align: center; gap: var(--space-2); }
.dl-empty-title { font-size: var(--text-lg-size); font-weight: 600; color: var(--color-text); margin: 0; }
.dl-empty-sub   { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: 0 0 var(--space-3); max-width: 46ch; }

.dl-search { max-width: 22rem; margin-bottom: var(--space-4); }

.control {
  font-family: inherit; font-size: var(--text-sm-size);
  padding: 8px var(--space-3);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-md);
  background: var(--color-surface); color: var(--color-text); width: 100%;
}
.control:focus { @apply outline-none; border-color: var(--color-brand-base); box-shadow: 0 0 0 3px var(--color-brand-subtle); }

.dl-table { @apply w-full; border-collapse: collapse; font-size: var(--text-sm-size); }
.dl-table th {
  text-align: left; padding: var(--space-2) var(--space-3);
  font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-muted);
  text-transform: uppercase; letter-spacing: 0.04em;
  border-bottom: 1px solid var(--color-border);
}
.dl-table td { padding: var(--space-3); border-bottom: 1px solid var(--color-border); color: var(--color-text); }
.dl-ref { font-variant-numeric: tabular-nums; font-weight: 500; }
.dl-amount { font-variant-numeric: tabular-nums; }
.dl-none { text-align: center; color: var(--color-text-light); padding: var(--space-6); }

.pill { display: inline-block; width: fit-content; padding: 2px var(--space-2); border-radius: 999px; font-size: var(--text-xs-size); text-transform: capitalize; }
.pill-ok      { background: var(--color-success-subtle); color: var(--color-success-text); }
.pill-warn    { background: var(--color-warning-subtle); color: var(--color-warning-text); }
.pill-bad     { background: var(--color-danger-subtle);  color: var(--color-danger-text); }
.pill-neutral { background: var(--color-surface-sunken); color: var(--color-text-muted); }

.link-btn { @apply cursor-pointer border-none bg-transparent p-0; font-family: inherit; font-size: var(--text-sm-size); color: var(--color-brand-on-surface); }
.link-btn:hover { @apply underline; }

.btn-primary {
  @apply cursor-pointer border-none;
  padding: 8px var(--space-4); border-radius: var(--radius-md);
  font-family: inherit; font-size: var(--text-sm-size); font-weight: 500;
  background: var(--color-brand-base); color: var(--color-on-brand);
}

.alert-error {
  padding: var(--space-3) var(--space-4); border-radius: var(--radius-md);
  font-size: var(--text-sm-size);
  background: var(--color-danger-subtle); color: var(--color-danger-text);
  margin-bottom: var(--space-4);
}
</style>
