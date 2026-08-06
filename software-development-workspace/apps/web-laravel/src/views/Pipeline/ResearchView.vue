<script setup lang="ts">
/**
 * Research — work done to find out whether the thing can be built.
 *
 * A list rather than a board. Research does not move through stages; it is
 * asked, worked and answered, and the useful question is which of it is still
 * outstanding and against what.
 *
 * The scope chips are the point of the row. Concept, architecture and
 * integration are answered by different people, so seeing at a glance which is
 * being asked is what decides who picks it up.
 */
import { ref, computed, onMounted } from "vue";
import { useRouter } from "vue-router";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import { notifySaved, notifyFailed } from "../../lib/toast";
import EmployeePicker from "../../components/EmployeePicker.vue";
import AccountPicker from "../../components/AccountPicker.vue";

interface Named { id: number; name: string }
interface Research {
  id: number; reference: string | null; name: string; status: string; priority: string;
  deadline: string | null;
  scope: { concept: boolean | null; architecture: boolean | null; integration: boolean | null };
  account?: (Named & { initials: string }) | null;
  opportunity?: { id: number; reference: string | null; name: string } | null;
  research_lead?: { id: number | null; name: string | null } | null;
  team?: { id: number; employee: { id: number | null; name: string | null } }[];
}

const router  = useRouter();
const rows    = ref<Research[]>([]);
const loading = ref(true);
const saving  = ref(false);
const search  = ref("");
const status  = ref("");

const org = () => currentOrgSlug.value;

function fail(e: unknown, fallback: string) {
  notifyFailed(fallback, e instanceof ApiError ? (e.firstError ?? e.message) : undefined);
}

async function load() {
  loading.value = true;
  try {
    rows.value = (await api<{ data: Research[] }>("GET", "/api/v1/research", {
      org: org(),
      params: { search: search.value || undefined, status: status.value || undefined, per_page: 100 },
    })).data;
  } catch (e) { fail(e, "Could not load research."); }
  finally { loading.value = false; }
}

// ── Creating ────────────────────────────────────────────────────────────────

const showNew = ref(false);
const blank = () => ({
  name: "", deadline: "", priority: "medium",
  research_lead_id: null as number | null, account_id: null as number | null,
  covers_concept: false, covers_architecture: false, covers_integration: false,
});
const form = ref(blank());

async function create() {
  if (!form.value.name.trim()) return;
  saving.value = true;
  try {
    const res = await api<{ data: Research }>("POST", "/api/v1/research", {
      org: org(),
      body: { ...form.value, deadline: form.value.deadline || null },
    });
    showNew.value = false;
    form.value = blank();
    notifySaved(`${res.data.name} raised.`);
    router.push({ name: "research-detail", params: { id: res.data.id } });
  } catch (e) { fail(e, "Could not raise the research."); }
  finally { saving.value = false; }
}

// ── Presentation ────────────────────────────────────────────────────────────

const STATUSES = [
  { key: "", label: "All" },
  { key: "new", label: "New" },
  { key: "in_progress", label: "In progress" },
  { key: "complete", label: "Complete" },
  { key: "abandoned", label: "Abandoned" },
];

const statusTone = (s: string) =>
  s === "complete" ? "ok" : s === "abandoned" ? "neutral" : s === "in_progress" ? "info" : "warn";

const date = (v: string | null) =>
  v ? new Date(v).toLocaleDateString(undefined, { day: "2-digit", month: "short", year: "numeric" }) : "—";

const titleCase = (v: string) => v.replace(/_/g, " ").replace(/\b\w/g, (m) => m.toUpperCase());

const initials = (name: string | null | undefined) =>
  (name ?? "?").split(/\s+/).slice(0, 2).map((w) => w[0]).join("").toUpperCase();

/** Only the scopes actually asked for — an unanswered flag is not a "no". */
const scopesOf = (r: Research) =>
  [
    { key: "concept", label: "Concept", on: r.scope.concept },
    { key: "architecture", label: "Architecture", on: r.scope.architecture },
    { key: "integration", label: "Integration", on: r.scope.integration },
  ].filter((s) => s.on);

const outstanding = computed(() => rows.value.filter((r) => r.status !== "complete" && r.status !== "abandoned").length);

onMounted(load);
</script>

<template>
  <div class="rs-wrap">
    <header class="rs-header">
      <div>
        <h1 class="rs-title">Research</h1>
        <p class="rs-sub">
          What has to be found out before a deal can be promised.
          <template v-if="!loading && rows.length"> {{ outstanding }} still open.</template>
        </p>
      </div>
      <button class="btn-primary" @click="form = blank(); showNew = true">
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <path d="M10 4v12M4 10h12" />
        </svg>
        Create a Research
      </button>
    </header>

    <div class="rs-chips">
      <button v-for="s in STATUSES" :key="s.key"
              :class="['rs-chip', { active: status === s.key }]"
              @click="status = s.key; load()">{{ s.label }}</button>
    </div>

    <div class="rs-toolbar">
      <input v-model="search" class="control rs-search" type="search" placeholder="Search research"
             @keyup.enter="load" />
    </div>

    <div v-if="loading" class="rs-loading">Loading…</div>

    <div v-else-if="rows.length === 0" class="rs-empty">
      <h2 class="rs-empty-title">Create a Research Card</h2>
      <p class="rs-empty-sub">Start doing research on the work to be done</p>
      <button class="btn-primary" @click="form = blank(); showNew = true">Create a Research</button>
    </div>

    <div v-else class="rs-card">
      <table class="rs-table">
        <thead>
          <tr><th>ID</th><th>Research</th><th>Scope</th><th>Company</th>
            <th>Linked to</th><th>Research Lead</th><th>Deadline</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-for="r in rows" :key="r.id">
            <td class="rs-ref">{{ r.reference ?? "—" }}</td>
            <td class="rs-name">{{ r.name }}</td>
            <td>
              <span v-if="!scopesOf(r).length" class="rs-muted">—</span>
              <span v-else class="rs-scopes">
                <span v-for="s in scopesOf(r)" :key="s.key" class="rs-scope">{{ s.label }}</span>
              </span>
            </td>
            <td>
              <button v-if="r.account" class="link-btn"
                      @click="router.push({ name: 'account', params: { id: r.account.id } })">
                {{ r.account.name }}
              </button>
              <span v-else class="rs-muted">—</span>
            </td>
            <td>
              <button v-if="r.opportunity" class="link-btn"
                      @click="router.push({ name: 'opportunity', params: { id: r.opportunity.id } })">
                {{ r.opportunity.reference }}
              </button>
              <span v-else class="rs-muted">—</span>
            </td>
            <td>
              <span v-if="r.research_lead?.name" class="rs-person">
                <span class="rs-avatar">{{ initials(r.research_lead.name) }}</span>{{ r.research_lead.name }}
              </span>
              <span v-else class="rs-muted">—</span>
            </td>
            <td class="rs-nowrap">{{ date(r.deadline) }}</td>
            <td><span :class="['pill', `pill-${statusTone(r.status)}`]">{{ titleCase(r.status) }}</span></td>
            <td>
              <button class="link-btn" @click="router.push({ name: 'research-detail', params: { id: r.id } })">
                View Research
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- New research -->
    <div v-if="showNew" class="rs-sheet-backdrop" @click.self="showNew = false">
      <aside class="rs-sheet" role="dialog" aria-modal="true" aria-label="New research">
        <div class="rs-sheet-head">
          <h3 class="rs-sheet-title">New Research</h3>
          <button class="rs-close" aria-label="Close" @click="showNew = false">×</button>
        </div>

        <label class="rs-field"><span>Research Name</span>
          <input v-model="form.name" class="control" placeholder="Employee Portal Revamp" /></label>

        <label class="rs-field"><span>Research Deadline</span>
          <input v-model="form.deadline" class="control" type="date" /></label>

        <label class="rs-field"><span>Research Priority</span>
          <select v-model="form.priority" class="control">
            <option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option>
          </select></label>

        <div class="rs-field"><span>Research Lead</span>
          <EmployeePicker v-model="form.research_lead_id" placeholder="Who is running it?" /></div>

        <div class="rs-field">
          <span>Company</span>
          <AccountPicker v-model="form.account_id" placeholder="Which customer, if any?" />
          <span class="rs-hint">
            Optional. Research often starts before there is a customer record or a
            deal to attach it to — which is most of the point of doing it.
          </span>
        </div>

        <fieldset class="rs-scope-set">
          <legend>What is being asked</legend>
          <label class="rs-check"><input v-model="form.covers_concept" type="checkbox" />
            <span><strong>Concept</strong> — can this be done at all?</span></label>
          <label class="rs-check"><input v-model="form.covers_architecture" type="checkbox" />
            <span><strong>Architecture</strong> — how would it be built?</span></label>
          <label class="rs-check"><input v-model="form.covers_integration" type="checkbox" />
            <span><strong>Integration</strong> — will it talk to what they run?</span></label>
        </fieldset>

        <button class="btn-primary btn-block" :disabled="saving || !form.name.trim()" @click="create">
          {{ saving ? "Creating…" : "Create Research" }}
        </button>
      </aside>
    </div>
  </div>
</template>

<style scoped>
@reference "../../theme.css";

.rs-wrap { padding: var(--space-6); max-width: 86rem; }

.rs-header { @apply flex flex-wrap items-start justify-between; gap: var(--space-4); margin-bottom: var(--space-4); }
.rs-title { font-size: var(--text-xl-size); font-weight: 600; color: var(--color-text); margin: 0; }
.rs-sub   { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: var(--space-1) 0 0; }

.rs-chips { @apply flex flex-wrap; gap: var(--space-2); margin-bottom: var(--space-4); }
.rs-chip {
  @apply cursor-pointer; padding: 6px var(--space-4); border-radius: 999px;
  border: 1px solid var(--color-border-strong); background: var(--color-surface);
  font-family: inherit; font-size: var(--text-sm-size); color: var(--color-text-muted);
}
.rs-chip.active { background: var(--color-brand-base); color: var(--color-on-brand); border-color: var(--color-brand-base); }

.rs-toolbar { @apply flex justify-end; margin-bottom: var(--space-4); }
.rs-search { max-width: 22rem; }
.rs-loading { padding: var(--space-6); color: var(--color-text-muted); }

.rs-empty { @apply flex flex-col items-center; padding: var(--space-8) var(--space-6); text-align: center; gap: var(--space-2); }
.rs-empty-title { font-size: var(--text-lg-size); font-weight: 600; color: var(--color-text); margin: 0; }
.rs-empty-sub { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: 0 0 var(--space-3); }

.rs-card {
  background: var(--color-surface-raised);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  overflow-x: auto;
}
.rs-table { @apply w-full; border-collapse: collapse; font-size: var(--text-sm-size); }
.rs-table th {
  text-align: left; padding: var(--space-3) var(--space-4);
  font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-muted);
  border-bottom: 1px solid var(--color-border); white-space: nowrap;
}
.rs-table td { padding: var(--space-3) var(--space-4); border-bottom: 1px solid var(--color-border); color: var(--color-text); }
.rs-table tr:last-child td { border-bottom: none; }
.rs-name { font-weight: 500; }
.rs-ref { font-variant-numeric: tabular-nums; color: var(--color-text-muted); }
.rs-nowrap { white-space: nowrap; }
.rs-muted { color: var(--color-text-light); }

.rs-scopes { @apply inline-flex flex-wrap; gap: 4px; }
.rs-scope {
  padding: 1px var(--space-2); border-radius: var(--radius-sm);
  background: var(--color-surface-sunken); color: var(--color-text-muted);
  font-size: var(--text-xs-size); white-space: nowrap;
}

.rs-person { @apply inline-flex items-center; gap: var(--space-2); }
.rs-avatar {
  @apply inline-flex shrink-0 items-center justify-center;
  width: 1.5rem; height: 1.5rem; border-radius: 999px;
  background: var(--color-surface-sunken); color: var(--color-text-muted);
  font-size: 0.6rem; font-weight: 600;
}

.rs-field { @apply flex flex-col; gap: var(--space-1); }
.rs-field > span { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.rs-hint { font-size: var(--text-xs-size); color: var(--color-text-light); }

.rs-scope-set { @apply flex flex-col; gap: var(--space-2); border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: var(--space-3); }
.rs-scope-set legend { font-size: var(--text-xs-size); color: var(--color-text-muted); padding: 0 var(--space-1); }
.rs-check { @apply flex cursor-pointer items-start; gap: var(--space-2); font-size: var(--text-sm-size); color: var(--color-text); }

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
.pill-info    { background: var(--color-brand-subtle);   color: var(--color-brand-on-surface); border-color: color-mix(in oklab, var(--color-brand-base) 30%, transparent); }
.pill-neutral { background: var(--color-surface-sunken); color: var(--color-text-muted); border-color: var(--color-border); }

.link-btn { @apply cursor-pointer border-none bg-transparent p-0; font-family: inherit; font-size: var(--text-sm-size); color: var(--color-brand-on-surface); text-align: left; }
.link-btn:hover { @apply underline; }

.rs-sheet-backdrop { @apply fixed inset-0 flex justify-end z-50; background: rgb(0 0 0 / 0.28); }
.rs-sheet {
  @apply flex flex-col overflow-y-auto; width: min(26rem, 100%); gap: var(--space-4);
  padding: var(--space-5); background: var(--color-surface-raised); box-shadow: var(--shadow-lg);
  animation: sheet-in 180ms ease;
}
@keyframes sheet-in { from { transform: translateX(12px); opacity: 0; } }
@media (prefers-reduced-motion: reduce) { .rs-sheet { animation: none; } }
.rs-sheet-head { @apply flex items-center justify-between; }
.rs-sheet-title { font-size: var(--text-base-size); font-weight: 600; margin: 0; color: var(--color-text); }
.rs-close { @apply cursor-pointer border-none bg-transparent p-0; font-size: 1.5rem; line-height: 1; color: var(--color-text-muted); }

.btn-primary {
  @apply inline-flex cursor-pointer items-center justify-center border-none;
  gap: var(--space-2); padding: 8px var(--space-4); border-radius: var(--radius-md);
  font-family: inherit; font-size: var(--text-sm-size); font-weight: 500; white-space: nowrap;
  background: var(--color-brand-base); color: var(--color-on-brand);
}
.btn-primary svg { width: 1rem; height: 1rem; }
.btn-block { width: 100%; }
.btn-primary:disabled { @apply cursor-not-allowed opacity-50; }
</style>
