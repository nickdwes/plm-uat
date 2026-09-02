<script setup lang="ts">
/**
 * Every deal in play.
 *
 * A flat list rather than a board: an opportunity moves through fourteen
 * phases, and fourteen columns is a spreadsheet turned sideways. The phase is
 * a column here instead, and the state filter answers the question people
 * actually arrive with — what is still open, and what did we win.
 */
import { ref, computed, onMounted } from "vue";
import { useRouter } from "vue-router";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import { notifyFailed } from "../../lib/toast";

interface Person { id: number | null; name: string | null }
interface Opportunity {
  id: number; reference: string | null; name: string; amount: string | number | null;
  expected_close_date: string | null;
  phase?: { id: number; name: string; outcome: string | null } | null;
  outcome: string | null; is_open: boolean;
  score: number; score_max: number; score_band: string;
  owner?: Person | null;
  account?: { id: number; name: string } | null;
  assignees?: { id: number; employee: Person }[];
}

const router  = useRouter();

/**
 * Open the record. The whole row, because a "View" button in the last column
 * sits past the right edge of most screens on a table this wide.
 */
function openRow(id: number, event: MouseEvent) {
  const el = event.target as HTMLElement | null;
  if (el?.closest("button, a, input, select, label")) return;

  router.push({ name: "opportunity", params: { id } });
}
const rows    = ref<Opportunity[]>([]);
const loading = ref(true);
const search  = ref("");
const state   = ref("open");

const org = () => currentOrgSlug.value;

async function load() {
  loading.value = true;
  try {
    rows.value = (await api<{ data: Opportunity[] }>("GET", "/api/v1/opportunities", {
      org: org(),
      params: { state: state.value || undefined, search: search.value || undefined, per_page: 100 },
    })).data;
  } catch (e) {
    notifyFailed("Could not load opportunities.", e instanceof ApiError ? (e.firstError ?? e.message) : undefined);
  } finally { loading.value = false; }
}

const money = (v: string | number | null) =>
  v === null || v === "" ? "—" : `KES ${Number(v).toLocaleString(undefined, { maximumFractionDigits: 0 })}`;

const date = (v: string | null) =>
  v ? new Date(v).toLocaleDateString(undefined, { day: "2-digit", month: "short", year: "numeric" }) : "—";

const initials = (name: string | null | undefined) =>
  (name ?? "?").split(/\s+/).slice(0, 2).map((w) => w[0]).join("").toUpperCase();

/** What is in play, kept apart from what has been won. */
const totals = computed(() => ({
  count: rows.value.length,
  value: rows.value.reduce((sum, o) => sum + Number(o.amount ?? 0), 0),
}));

const STATES = [
  { key: "open", label: "In play" },
  { key: "won",  label: "Won" },
  { key: "lost", label: "Lost" },
  { key: "",     label: "All" },
];

onMounted(load);
</script>

<template>
  <div class="ov-wrap">
    <header class="ov-header">
      <div>
        <h1 class="ov-title">Opportunities</h1>
        <p class="ov-sub">Deals in progress, and what they are worth.</p>
      </div>
      <div class="ov-total">
        <span class="ov-total-value">{{ money(totals.value) }}</span>
        <span class="ov-total-label">across {{ totals.count }} deal{{ totals.count === 1 ? "" : "s" }}</span>
      </div>
    </header>

    <div class="ov-chips">
      <button v-for="s in STATES" :key="s.key"
              :class="['ov-chip', { active: state === s.key }]"
              @click="state = s.key; load()">{{ s.label }}</button>
    </div>

    <div class="ov-toolbar">
      <input v-model="search" class="control ov-search" type="search" placeholder="Search deals"
             @keyup.enter="load" />
    </div>

    <div v-if="loading" class="ov-loading">Loading…</div>

    <div v-else class="ov-card">
      <table class="ov-table">
        <thead>
          <tr><th>ID</th><th>Opportunity</th><th>Customer</th><th class="ov-num">Amount</th>
            <th>Owner</th><th>Assignees</th><th>Phase</th><th>Close</th><th>Score</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-if="rows.length === 0">
            <td colspan="10" class="ov-none">
              Nothing here. A deal starts life as a lead and is converted from it.
            </td>
          </tr>
          <tr v-for="o in rows" :key="o.id" class="op-clickable" @click="openRow(o.id, $event)">
            <td class="ov-ref">{{ o.reference ?? "—" }}</td>
            <td class="ov-name">{{ o.name }}</td>
            <td>
              <button v-if="o.account" class="link-btn"
                      @click="router.push({ name: 'account', params: { id: o.account.id } })">
                {{ o.account.name }}
              </button>
              <span v-else class="ov-muted">—</span>
            </td>
            <td class="ov-num">{{ money(o.amount) }}</td>
            <td>{{ o.owner?.name ?? "—" }}</td>
            <td>
              <span v-if="!o.assignees?.length" class="ov-muted">—</span>
              <span v-else class="ov-stack">
                <span v-for="a in o.assignees.slice(0, 3)" :key="a.id" class="ov-avatar"
                      :title="a.employee.name ?? ''">{{ initials(a.employee.name) }}</span>
                <span v-if="o.assignees.length > 3" class="ov-avatar more">+{{ o.assignees.length - 3 }}</span>
              </span>
            </td>
            <td>
              <span :class="['pill', o.outcome === 'won' ? 'pill-ok' : o.outcome === 'lost' ? 'pill-danger' : 'pill-neutral']">
                {{ o.phase?.name ?? "—" }}
              </span>
            </td>
            <td class="ov-nowrap">{{ date(o.expected_close_date) }}</td>
            <td class="ov-num" :title="`${o.score} of ${o.score_max} — ${o.score_band}`">
              <span :class="['ov-score', `score-${o.score_band}`]">{{ o.score }}</span>
            </td>
            <td>
              <button class="link-btn" @click="router.push({ name: 'opportunity', params: { id: o.id } })">View</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<style scoped>
@reference "../../theme.css";

.ov-wrap { padding: var(--space-6); max-width: 88rem; }

.ov-header { @apply flex flex-wrap items-start justify-between; gap: var(--space-4); margin-bottom: var(--space-4); }
.ov-title { font-size: var(--text-xl-size); font-weight: 600; color: var(--color-text); margin: 0; }
.ov-sub   { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: var(--space-1) 0 0; }
.ov-total { @apply flex flex-col items-end; }
.ov-total-value { font-size: var(--text-lg-size); font-weight: 700; color: var(--color-text); }
.ov-total-label { font-size: var(--text-xs-size); color: var(--color-text-muted); }

.ov-chips { @apply flex flex-wrap; gap: var(--space-2); margin-bottom: var(--space-4); }
.ov-chip {
  @apply cursor-pointer; padding: 6px var(--space-4); border-radius: 999px;
  border: 1px solid var(--color-border-strong); background: var(--color-surface);
  font-family: inherit; font-size: var(--text-sm-size); color: var(--color-text-muted);
}
.ov-chip.active { background: var(--color-brand-base); color: var(--color-on-brand); border-color: var(--color-brand-base); }

.ov-toolbar { @apply flex justify-end; margin-bottom: var(--space-4); }
.ov-search { max-width: 22rem; }

.control {
  font-family: inherit; font-size: var(--text-sm-size);
  padding: 8px var(--space-3);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-md);
  background: var(--color-surface); color: var(--color-text); width: 100%;
}
.control:focus { @apply outline-none; border-color: var(--color-brand-base); box-shadow: 0 0 0 3px var(--color-brand-subtle); }

.ov-loading { padding: var(--space-6); color: var(--color-text-muted); }

.ov-card {
  background: var(--color-surface-raised);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  overflow-x: auto;
}
.ov-table { @apply w-full; border-collapse: collapse; font-size: var(--text-sm-size); }
.ov-table th {
  text-align: left; padding: var(--space-3) var(--space-4);
  font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-muted);
  border-bottom: 1px solid var(--color-border); white-space: nowrap;
}
.ov-table td { padding: var(--space-3) var(--space-4); border-bottom: 1px solid var(--color-border); color: var(--color-text); }
.ov-table tr:last-child td { border-bottom: none; }
.ov-name { font-weight: 500; }
.ov-ref  { font-variant-numeric: tabular-nums; color: var(--color-text-muted); }
.ov-num  { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.ov-nowrap { white-space: nowrap; }
.ov-none { text-align: center; color: var(--color-text-light); padding: var(--space-6); }
.ov-muted { color: var(--color-text-light); }

.ov-stack { @apply inline-flex items-center; }
.ov-avatar {
  @apply inline-flex items-center justify-center;
  width: 1.5rem; height: 1.5rem; margin-right: -0.35rem; border-radius: 999px;
  background: var(--color-surface-sunken); color: var(--color-text-muted);
  border: 2px solid var(--color-surface-raised);
  font-size: 0.6rem; font-weight: 600;
}
.ov-avatar.more { background: var(--color-brand-subtle); color: var(--color-brand-on-surface); }

.ov-score { font-weight: 600; }
.score-strong     { color: var(--color-success-text); }
.score-promising  { color: var(--color-brand-on-surface); }
.score-developing { color: var(--color-warning-text); }
.score-weak       { color: var(--color-text-light); }

.pill { display: inline-block; padding: 2px var(--space-3); border-radius: 999px; font-size: var(--text-xs-size); border: 1px solid transparent; white-space: nowrap; }
.pill-ok      { background: var(--color-success-subtle); color: var(--color-success-text); border-color: color-mix(in oklab, var(--color-success-text) 30%, transparent); }
.pill-danger  { background: var(--color-danger-subtle);  color: var(--color-danger-text);  border-color: color-mix(in oklab, var(--color-danger-text) 30%, transparent); }
.pill-neutral { background: var(--color-surface-sunken); color: var(--color-text-muted); border-color: var(--color-border); }

.link-btn { @apply cursor-pointer border-none bg-transparent p-0; font-family: inherit; font-size: var(--text-sm-size); color: var(--color-brand-on-surface); text-align: left; }
.link-btn:hover { @apply underline; }

.op-clickable { @apply cursor-pointer; transition: background-color 120ms ease; }
.op-clickable:hover { background: var(--color-neutral-100); }
</style>
