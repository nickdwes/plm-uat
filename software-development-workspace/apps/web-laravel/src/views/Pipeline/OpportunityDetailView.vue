<script setup lang="ts">
/**
 * One deal.
 *
 * The phase is the spine of the screen because it is the spine of the record:
 * whether a deal is open, won or lost is read from it and stored nowhere else,
 * so moving it here is the only way those facts change.
 *
 * Moving into Win or Lost is a separate permission from editing. If the API
 * refuses, it says so — the phases are not hidden, because a salesperson who
 * cannot close should still be able to see where the deal has got to.
 */
import { ref, computed, onMounted, watch } from "vue";
import { useRouter } from "vue-router";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import { notifySaved, notifyFailed } from "../../lib/toast";
import EmployeePicker from "../../components/EmployeePicker.vue";
import OpportunityPipeline from "./OpportunityPipeline.vue";
import { safeHtml } from "../../lib/richText";

const props = defineProps<{ id: string | number }>();

interface Phase { id: number; name: string; position: number; outcome: string | null }
interface Assignee { id: number; employee: { id: number | null; name: string | null }; role: string | null }
interface Opportunity {
  id: number; reference: string | null; name: string; description: string | null;
  type: string | null; practice: string | null; amount: string | number | null;
  expected_close_date: string | null;
  phase?: Phase | null; outcome: string | null; is_open: boolean; closed_at: string | null;
  owner?: { id: number | null; name: string | null } | null;
  presale_lead?: { id: number | null; name: string | null } | null;
  assignees?: Assignee[];
  account?: { id: number; name: string } | null;
  lead?: { id: number; reference: string | null; name: string } | null;
  qualification: Record<string, unknown>;
  score: number; score_max: number; score_band: string;
  commercials_visible: boolean;
  commitment: { is_committed: boolean; year: number | null; quarter: number | null; discuss_weekly: boolean };
}

type Tab = "overview" | "pipeline" | "activities" | "research" | "proposals" | "documents";

const tabs: { key: Tab; label: string }[] = [
  { key: "overview",   label: "Overview" },
  { key: "pipeline",   label: "Pipeline" },
  { key: "activities", label: "Activities" },
  { key: "research",   label: "Research" },
  { key: "proposals",  label: "Proposals" },
  { key: "documents",  label: "Documents" },
];

const tab = ref<Tab>("overview");

/**
 * Research raised against this deal.
 *
 * Fetched separately rather than folded into the deal: most visits never open
 * the tab, and research is a module of its own that happens to link here.
 */
const researches = ref<{ id: number; reference: string | null; name: string; status: string }[]>([]);

watch(tab, async (t) => {
  if (t !== "research" || researches.value.length) return;
  try {
    researches.value = (await api<{ data: typeof researches.value }>("GET", "/api/v1/research", {
      org: org(), params: { opportunity_id: props.id, per_page: 50 },
    })).data;
  } catch { /* an empty tab is better than an error about a side panel */ }
});

const router = useRouter();
const deal    = ref<Opportunity | null>(null);
const phases  = ref<Phase[]>([]);
const loading = ref(true);
const saving  = ref(false);

const org  = () => currentOrgSlug.value;
const base = computed(() => `/api/v1/opportunities/${props.id}`);

function fail(e: unknown, fallback: string) {
  // The detail is the API's own message — a validation error is far more use
  // than "could not save", and a toast has room for both.
  notifyFailed(fallback, e instanceof ApiError ? (e.firstError ?? e.message) : undefined);
}

const flash = (message: string) => notifySaved(message);

async function load() {
  loading.value = true;
  try {
    const [d, p] = await Promise.all([
      api<{ data: Opportunity }>("GET", base.value, { org: org() }),
      api<{ data: Phase[] }>("GET", "/api/v1/opportunities/phases", { org: org() }),
    ]);
    deal.value   = d.data;
    phases.value = p.data;
  } catch (e) { fail(e, "Could not load this opportunity."); }
  finally { loading.value = false; }
}

async function moveTo(phase: Phase) {
  saving.value = true;
  try {
    deal.value = (await api<{ data: Opportunity }>("POST", `${base.value}/phase`, {
      org: org(), body: { opportunity_phase_id: phase.id },
    })).data;
    flash(`Moved to ${phase.name}.`);
  } catch (e) { fail(e, "Could not move the deal."); }
  finally { saving.value = false; }
}

// ── Assignees ───────────────────────────────────────────────────────────────

const newAssignee = ref({ employee_id: null as number | null, role: "" });

async function addAssignee() {
  if (!newAssignee.value.employee_id) return;
  saving.value = true;
  try {
    deal.value = (await api<{ data: Opportunity }>("POST", `${base.value}/assignees`, {
      org: org(), body: { ...newAssignee.value, role: newAssignee.value.role || null },
    })).data;
    newAssignee.value = { employee_id: null, role: "" };
  } catch (e) { fail(e, "Could not add them."); }
  finally { saving.value = false; }
}

async function removeAssignee(a: Assignee) {
  try {
    deal.value = (await api<{ data: Opportunity }>("DELETE", `${base.value}/assignees/${a.id}`, { org: org() })).data;
  } catch (e) { fail(e, "Could not remove them."); }
}

// ── Presentation ────────────────────────────────────────────────────────────

const CRITERIA: { key: string; label: string; weight: number }[] = [
  { key: "knows_procurement",   label: "We know how they buy", weight: 10 },
  { key: "compliance_pressure", label: "Under compliance pressure", weight: 15 },
  { key: "prior_deal_closed",   label: "Closed with them before", weight: 10 },
  { key: "deal_registered",     label: "Deal registered with the OEM", weight: 5 },
  { key: "oem_pressure",        label: "OEM is pushing it", weight: 5 },
  { key: "alignment_with_customer",       label: "Aligned with the customer", weight: 0 },
  { key: "alignment_with_oem_financials", label: "Aligned with OEM financials", weight: 0 },
  { key: "alignment_with_oem_solution",   label: "Aligned with the OEM solution", weight: 0 },
  { key: "alignment_with_oem_support",    label: "Aligned with OEM support", weight: 0 },
  { key: "budget_confirmed_by_customer",  label: "Budget confirmed by the customer", weight: 0 },
];

/** The open phases in order, with the two closing ones held apart. */
const openPhases    = computed(() => phases.value.filter((p) => p.outcome === null));
const closingPhases = computed(() => phases.value.filter((p) => p.outcome !== null));

/**
 * Masked rather than blank when the caller lacks the commercial elevation.
 * A withheld figure and an unrecorded one must not look the same.
 */
const money = (v: string | number | null) => {
  if (deal.value && !deal.value.commercials_visible) return "•••";
  return v === null || v === "" ? "—" : `KES ${Number(v).toLocaleString(undefined, { maximumFractionDigits: 0 })}`;
};

const date = (v: string | null) =>
  v ? new Date(v).toLocaleDateString(undefined, { day: "2-digit", month: "short", year: "numeric" }) : "—";

const titleCase = (v: string | null) =>
  v ? v.replace(/_/g, " ").replace(/\b\w/g, (m) => m.toUpperCase()) : "—";

onMounted(load);
</script>

<template>
  <div class="op-wrap">
    <nav class="op-crumbs">
      <button class="op-crumb" @click="router.push({ name: 'accounts' })">Accounts</button>
      <span class="op-sep">›</span>
      <button v-if="deal?.account" class="op-crumb"
              @click="router.push({ name: 'account', params: { id: deal.account.id } })">
        {{ deal.account.name }}
      </button>
      <span v-if="deal?.account" class="op-sep">›</span>
      <span class="op-current">{{ deal?.reference ?? "Opportunity" }}</span>
    </nav>

    <div v-if="loading" class="op-loading">Loading…</div>

    <template v-else-if="deal">
      <header class="op-head">
        <div>
          <h1 class="op-title">{{ deal.name }}</h1>
          <p class="op-byline">
            {{ money(deal.amount) }}
            <template v-if="deal.expected_close_date"> · expected {{ date(deal.expected_close_date) }}</template>
            <template v-if="deal.owner?.name"> · {{ deal.owner.name }}</template>
          </p>
        </div>
        <div :class="['op-score', `score-${deal.score_band}`]">
          <span class="op-score-value">{{ deal.score }}</span>
          <span class="op-score-max">/ {{ deal.score_max }}</span>
          <span class="op-score-band">{{ deal.score_band }}</span>
        </div>
      </header>

      <nav class="op-tabs" role="tablist">
        <button v-for="t in tabs" :key="t.key" role="tab" :aria-selected="tab === t.key"
                :class="['op-tab', { active: tab === t.key }]" @click="tab = t.key">{{ t.label }}</button>
      </nav>

      <!-- ── Pipeline ────────────────────────────────────────────────── -->
      <OpportunityPipeline v-if="tab === 'pipeline'" :opportunity-id="props.id" />

      <!-- ── Everything else ─────────────────────────────────────────── -->
      <template v-else-if="tab === 'overview'">
      <!-- The phase, which is where open/won/lost is read from. -->
      <div class="op-card">
        <div class="op-card-head">
          <h3 class="op-card-label">Phase</h3>
          <span :class="['pill', deal.outcome === 'won' ? 'pill-ok' : deal.outcome === 'lost' ? 'pill-danger' : 'pill-neutral']">
            {{ deal.phase?.name ?? "Not set" }}
          </span>
        </div>

        <ol class="op-track">
          <li v-for="p in openPhases" :key="p.id"
              :class="['op-step', { done: (deal.phase?.position ?? 0) > p.position, here: deal.phase?.id === p.id }]">
            <button class="op-step-btn" :disabled="saving || deal.phase?.id === p.id" @click="moveTo(p)">
              {{ p.name }}
            </button>
          </li>
        </ol>

        <div class="op-close">
          <button v-for="p in closingPhases" :key="p.id"
                  :class="['btn-ghost', p.outcome === 'won' ? 'btn-win' : 'btn-lose']"
                  :disabled="saving || deal.phase?.id === p.id"
                  @click="moveTo(p)">
            Mark {{ p.name }}
          </button>
          <span v-if="deal.closed_at" class="op-muted">Closed {{ date(deal.closed_at) }}</span>
        </div>
      </div>

      <div class="op-columns">
        <div class="op-card">
          <h3 class="op-card-label">Qualification</h3>
          <p class="op-muted">
            These are the score. Weights shown because a checklist without them hides
            which answers matter.
          </p>
          <ul class="op-criteria">
            <li v-for="c in CRITERIA" :key="c.key" :class="{ met: deal.qualification[c.key] }">
              <span class="op-mark">{{ deal.qualification[c.key] ? "✓" : "·" }}</span>
              <span>{{ c.label }}</span>
              <span v-if="c.weight" class="op-weight">+{{ c.weight }}</span>
            </li>
          </ul>
          <dl class="op-mini">
            <div><dt>Account weight</dt><dd>{{ titleCase(String(deal.qualification.account_weight ?? "")) || "—" }}</dd></div>
            <div><dt>Type</dt><dd>{{ titleCase(deal.type) }}</dd></div>
            <div><dt>Practice</dt><dd>{{ deal.practice ?? "—" }}</dd></div>
          </dl>
          <template v-if="deal.qualification.gap_analysis">
            <h4 class="op-card-label">Gap analysis</h4>
            <p class="op-prose">{{ deal.qualification.gap_analysis }}</p>
          </template>
        </div>

        <div class="op-side">
          <div class="op-card">
            <h3 class="op-card-label">Commitment</h3>
            <dl class="op-mini">
              <div><dt>Committed</dt><dd>{{ deal.commitment.is_committed ? "Yes" : "No" }}</dd></div>
              <div v-if="deal.commitment.is_committed"><dt>Quarter</dt>
                <dd>Q{{ deal.commitment.quarter }} {{ deal.commitment.year }}</dd></div>
              <div><dt>Weekly review</dt><dd>{{ deal.commitment.discuss_weekly ? "Yes" : "No" }}</dd></div>
            </dl>
          </div>

          <div v-if="deal.lead" class="op-card">
            <h3 class="op-card-label">Came from</h3>
            <button class="link-btn" @click="router.push({ name: 'lead', params: { id: deal.lead.id } })">
              {{ deal.lead.reference }} — {{ deal.lead.name }}
            </button>
          </div>

          <div class="op-card">
            <h3 class="op-card-label">Assignees</h3>
            <div class="op-add-row">
              <EmployeePicker v-model="newAssignee.employee_id" placeholder="Add somebody…" />
              <button class="btn-primary" :disabled="saving || !newAssignee.employee_id" @click="addAssignee">Add</button>
            </div>
            <p v-if="!deal.assignees?.length" class="op-muted">Nobody assigned.</p>
            <ul v-else class="op-people">
              <li v-for="a in deal.assignees" :key="a.id">
                <span>{{ a.employee.name ?? "—" }}</span>
                <button class="link-btn link-danger" @click="removeAssignee(a)">Remove</button>
              </li>
            </ul>
          </div>
        </div>
      </div>

      <div v-if="deal.description" class="op-card">
        <h3 class="op-card-label">Description</h3>
        <div class="op-prose rich" v-html="safeHtml(deal.description)"></div>
      </div>
      </template>

      <!-- ── Research ────────────────────────────────────────────────── -->
      <template v-else-if="tab === 'research'">
        <div v-if="!researches.length" class="op-card op-pending">
          <h3 class="op-card-label">Create a Research Card</h3>
          <p class="op-muted">Start doing research on the work to be done.</p>
          <button class="btn-primary" @click="router.push({ name: 'research' })">Create a Research</button>
        </div>

        <div v-else class="op-card">
          <h3 class="op-card-label">Research on this deal</h3>
          <ul class="op-people">
            <li v-for="r in researches" :key="r.id">
              <span>
                <span class="op-strong">{{ r.name }}</span>
                <span class="op-muted"> · {{ r.reference }} · {{ r.status }}</span>
              </span>
              <button class="link-btn"
                      @click="router.push({ name: 'research-detail', params: { id: r.id } })">
                View Research
              </button>
            </li>
          </ul>
          <button class="btn-ghost" @click="router.push({ name: 'research' })">Create a Research</button>
        </div>
      </template>

      <!-- Tabs whose modules are next in the build. Shown rather than hidden:
           they are part of the record's shape, and a tab that says what it is
           waiting for beats one that silently is not there. -->
      <div v-else class="op-card op-pending">
        <h3 class="op-card-label">{{ tabs.find((t) => t.key === tab)?.label }}</h3>
        <p class="op-muted">
          <template v-if="tab === 'activities'">
            The deal's own timeline is recorded and kept apart from the customer's;
            the screen for it follows Research.
          </template>
          <template v-else>
            Proposals and documents are filed through the Pipeline tab today, under
            the stage they belong to. A combined view follows.
          </template>
        </p>
      </div>
    </template>
  </div>
</template>

<style scoped>
@reference "../../theme.css";

.op-wrap { padding: var(--space-6); max-width: 72rem; }

.op-crumbs { @apply flex flex-wrap items-center; gap: var(--space-2); font-size: var(--text-sm-size); margin-bottom: var(--space-3); }
.op-crumb { @apply cursor-pointer border-none bg-transparent p-0; font-family: inherit; font-size: inherit; color: var(--color-text-muted); }
.op-crumb:hover { color: var(--color-text); }
.op-sep { color: var(--color-text-light); }
.op-current { color: var(--color-text); }

.op-loading { padding: var(--space-6); color: var(--color-text-muted); }

.op-head { @apply flex items-start justify-between; gap: var(--space-4); margin-bottom: var(--space-4); }
.op-title { font-size: var(--text-xl-size); font-weight: 600; color: var(--color-text); margin: 0; }
.op-byline { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: var(--space-1) 0 0; }

.op-score { @apply flex shrink-0 items-baseline; gap: var(--space-1); }
.op-score-value { font-size: var(--text-2xl-size); font-weight: 700; }
.op-score-max   { font-size: var(--text-sm-size); color: var(--color-text-light); }
.op-score-band  { font-size: var(--text-xs-size); text-transform: uppercase; letter-spacing: 0.04em; margin-left: var(--space-2); }
.score-strong     { color: var(--color-success-text); }
.score-promising  { color: var(--color-brand-on-surface); }
.score-developing { color: var(--color-warning-text); }
.score-weak       { color: var(--color-text-light); }

.op-tabs { @apply flex flex-wrap gap-1 overflow-x-auto; border-bottom: 1px solid var(--color-border); margin-bottom: var(--space-4); }
.op-tab {
  @apply cursor-pointer border-none bg-transparent;
  padding: var(--space-2) var(--space-3);
  font-family: inherit; font-size: var(--text-sm-size); color: var(--color-text-muted);
  border-bottom: 2px solid transparent; margin-bottom: -1px; white-space: nowrap;
}
.op-tab:hover  { color: var(--color-text); }
.op-tab.active { color: var(--color-brand-on-surface); border-bottom-color: var(--color-brand-base); font-weight: 500; }
.op-pending { align-items: flex-start; }
.op-strong { font-weight: 500; color: var(--color-text); }

.op-columns { @apply grid; grid-template-columns: 3fr 2fr; gap: var(--space-4); margin-top: var(--space-4); }
@media (max-width: 56rem) { .op-columns { grid-template-columns: 1fr; } }
.op-side { @apply flex flex-col; gap: var(--space-4); }

.op-card {
  @apply flex flex-col; gap: var(--space-3);
  padding: var(--space-5);
  background: var(--color-surface-raised);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
}
.op-card-head { @apply flex items-center justify-between; gap: var(--space-3); }
.op-card-label {
  font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-muted);
  text-transform: uppercase; letter-spacing: 0.04em; margin: 0;
}
.op-prose { font-size: var(--text-sm-size); color: var(--color-text); margin: 0; white-space: pre-wrap; line-height: 1.6; }
.op-muted { font-size: var(--text-sm-size); color: var(--color-text-light); margin: 0; }

/* The sales process as a track — where it is, and what comes next. */
.op-track { @apply flex flex-wrap list-none; gap: var(--space-2); margin: 0; padding: 0; }
.op-step-btn {
  @apply cursor-pointer;
  padding: 4px var(--space-3); border-radius: 999px;
  border: 1px solid var(--color-border-strong);
  background: var(--color-surface); color: var(--color-text-muted);
  font-family: inherit; font-size: var(--text-xs-size); white-space: nowrap;
}
.op-step-btn:hover:not(:disabled) { border-color: var(--color-brand-base); color: var(--color-brand-on-surface); }
.op-step-btn:disabled { @apply cursor-default; }
.op-step.done .op-step-btn { background: var(--color-surface-sunken); color: var(--color-text-light); }
.op-step.here .op-step-btn {
  background: var(--color-brand-base); color: var(--color-on-brand);
  border-color: var(--color-brand-base); font-weight: 600;
}

.op-close { @apply flex flex-wrap items-center; gap: var(--space-3); padding-top: var(--space-2); border-top: 1px solid var(--color-border); }
.btn-win  { color: var(--color-success-text); border-color: color-mix(in oklab, var(--color-success-text) 40%, transparent); }
.btn-lose { color: var(--color-danger-text);  border-color: color-mix(in oklab, var(--color-danger-text) 40%, transparent); }

.op-criteria { @apply list-none; margin: 0; padding: 0; }
.op-criteria li { @apply flex items-center; gap: var(--space-2); padding: var(--space-1) 0; font-size: var(--text-sm-size); color: var(--color-text-light); }
.op-criteria li.met { color: var(--color-text); }
.op-mark { @apply inline-flex w-4 shrink-0 justify-center; color: var(--color-text-light); }
.op-criteria li.met .op-mark { color: var(--color-success-text); }
.op-weight { font-size: var(--text-xs-size); color: var(--color-text-light); margin-left: auto; }

.op-mini { margin: 0; }
.op-mini > div { @apply flex items-baseline justify-between; gap: var(--space-3); padding: var(--space-1) 0; }
.op-mini dt { font-size: var(--text-sm-size); color: var(--color-text-muted); }
.op-mini dd { font-size: var(--text-sm-size); color: var(--color-text); margin: 0; }

.op-add-row { @apply flex items-start; gap: var(--space-2); }
.op-add-row > div:first-child { flex: 1; }

.op-people { @apply list-none; margin: 0; padding: 0; }
.op-people li { @apply flex items-center justify-between; gap: var(--space-3); padding: var(--space-2) 0; border-bottom: 1px solid var(--color-border); font-size: var(--text-sm-size); }
.op-people li:last-child { border-bottom: none; }

.pill { display: inline-block; padding: 2px var(--space-3); border-radius: 999px; font-size: var(--text-xs-size); border: 1px solid transparent; }
.pill-ok      { background: var(--color-success-subtle); color: var(--color-success-text); border-color: color-mix(in oklab, var(--color-success-text) 30%, transparent); }
.pill-danger  { background: var(--color-danger-subtle);  color: var(--color-danger-text);  border-color: color-mix(in oklab, var(--color-danger-text) 30%, transparent); }
.pill-neutral { background: var(--color-surface-sunken); color: var(--color-text-muted); border-color: var(--color-border); }

.link-btn { @apply cursor-pointer border-none bg-transparent p-0; font-family: inherit; font-size: var(--text-sm-size); color: var(--color-brand-on-surface); text-align: left; }
.link-btn:hover { @apply underline; }
.link-danger { color: var(--color-danger-text); }

.btn-primary, .btn-ghost {
  @apply inline-flex cursor-pointer items-center justify-center;
  gap: var(--space-2); padding: 8px var(--space-4); border-radius: var(--radius-md);
  font-family: inherit; font-size: var(--text-sm-size); font-weight: 500; white-space: nowrap;
}
.btn-primary { @apply border-none; background: var(--color-brand-base); color: var(--color-on-brand); }
.btn-ghost   { background: var(--color-surface); color: var(--color-text); border: 1px solid var(--color-border-strong); }
.btn-primary:disabled, .btn-ghost:disabled { @apply cursor-not-allowed opacity-50; }

</style>
