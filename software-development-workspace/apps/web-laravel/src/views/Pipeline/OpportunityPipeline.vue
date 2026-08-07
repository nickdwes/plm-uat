<script setup lang="ts">
/**
 * The pipeline: every stage a deal is worked through.
 *
 * A rail of stages on the left, one working panel on the right. Not a wizard —
 * the stages are not strictly sequential, and a deal is routinely bidding while
 * its specification is still being revised. So any stage can be opened, and
 * completion is recorded per stage rather than as a high-water mark.
 *
 * Money is masked unless the caller holds the commercial elevation. Masked
 * rather than hidden: the row still shows, with a dash and an explanation, so a
 * restricted view cannot be mistaken for a deal with no figures recorded.
 */
import { ref, computed, watch } from "vue";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import { notifySaved, notifyFailed } from "../../lib/toast";
import { useRouter } from "vue-router";
import EmployeePicker from "../../components/EmployeePicker.vue";

const props = defineProps<{ opportunityId: string | number }>();

interface Stage { id: number; name: string; position: number; is_current: boolean; completed_at: string | null }
interface Doc {
  id: number; stage: string; slot: string | null; document_type: string;
  label: string; original_name: string; size_bytes: number | null; uploaded_at: string | null;
}
interface Indicator { key: string; label: string; help: string; met: boolean }
interface Estimate {
  id: number; name: string;
  budget: string | null; revenue: string | null; cost: string | null; profit: string | null;
  implied_profit: string | null; documents: Doc[];
}
interface Objective {
  id: number; name: string; expected_start_date: string | null;
  expected_end_date: string | null; quarter_span: string | null;
}
interface Bid {
  id: number; tender_number: string; bid_number: string | null; tender_name: string | null;
  status: string; tender_validity_date: string | null; bid_bond_validity_date: string | null;
  bid_bond_value: string | null; bidding_cost: string | null; bidding_value: string | null;
  bidding_profit: string | null; tender_close_date: string | null; expected_award_date: string | null;
  bond_lapses_before_award: boolean; outcome_notes: string | null; documents: Doc[];
}
interface Contract {
  id: number; reference: string | null; name: string; contract_value: string | null;
  billing_cycle: string | null; owner: string | null; status: string;
  start_date: string | null; end_date: string | null;
}
interface ProjectRow { id: number; reference: string | null; name: string; priority: string; status: string; deadline: string | null }
interface Dimension { key: string; label: string; value: number | null }
interface Pipeline {
  commercials_visible: boolean;
  stages: Stage[];
  strength: {
    score: number; max: number; band: string; threshold: number; meets: boolean;
    indicators: Indicator[];
  };
  qualification: {
    dimensions: Dimension[]; score: number | null; band: string | null;
    completeness: number; percentile: number | null;
    summary: Record<string, string | null>; qualified_at: string | null;
  };
  budget: { amount: string | null; is_confirmed: boolean; confirmed_at: string | null };
  estimates: Estimate[];
  objectives: Objective[];
  bids: Bid[];
  contracts: Contract[];
  projects: ProjectRow[];
  documents: Record<string, Doc[]>;
}

const router = useRouter();
const pipeline = ref<Pipeline | null>(null);
const loading  = ref(true);
const saving   = ref(false);
const current  = ref<string>("Opportunity Processing");

const org  = () => currentOrgSlug.value;
const base = computed(() => `/api/v1/opportunities/${props.opportunityId}`);

/**
 * A 401 carrying a claims challenge is not "logged out".
 *
 * Entra Conditional Access can demand a fresh authenticator prompt before
 * commercial figures are released. Treating that as a session expiry would sign
 * people out instead of prompting them, which is exactly the retrofit this
 * avoids. See docs/entra-stepup-and-sharepoint.md.
 */
function fail(e: unknown, fallback: string) {
  if (e instanceof ApiError && e.status === 401) {
    notifyFailed(
      "Re-authentication needed",
      "Your organisation requires a fresh sign-in before commercial figures can be shown.",
    );
    return;
  }
  notifyFailed(fallback, e instanceof ApiError ? (e.firstError ?? e.message) : undefined);
}

async function load() {
  loading.value = true;
  try {
    pipeline.value = (await api<{ data: Pipeline }>("GET", `${base.value}/pipeline`, { org: org() })).data;

    // Open where the deal actually is, rather than always at the first stage.
    const here = pipeline.value.stages.find((s) => s.is_current)
      ?? pipeline.value.stages.find((s) => !s.completed_at);
    if (here) current.value = here.name;
  } catch (e) { fail(e, "Could not load the pipeline."); }
  finally { loading.value = false; }
}

/** Every stage action returns the whole pipeline, so one path refreshes it. */
async function act(method: "POST" | "PUT" | "DELETE", path: string, body?: unknown, ok?: string) {
  saving.value = true;
  try {
    pipeline.value = (await api<{ data: Pipeline }>(method, `${base.value}${path}`, { org: org(), body })).data;
    if (ok) notifySaved(ok);
    return true;
  } catch (e) { fail(e, "That did not go through."); return false; }
  finally { saving.value = false; }
}

const stage = computed(() => pipeline.value?.stages.find((s) => s.name === current.value) ?? null);
const isDone = (s: Stage) => Boolean(s.completed_at);

const completeStage = () =>
  stage.value && act("POST", `/stages/${stage.value.id}/complete`, undefined, `${stage.value.name} marked complete.`);
const reopenStage = () =>
  stage.value && act("POST", `/stages/${stage.value.id}/reopen`, undefined, `${stage.value.name} reopened.`);

// ── Processing ──────────────────────────────────────────────────────────────

const toggleIndicator = (i: Indicator) =>
  act("POST", "/indicators", { [i.key]: !i.met });

// ── Qualification ───────────────────────────────────────────────────────────

const qualifying = ref(false);
const quality = ref<Record<string, string | number | null>>({});

function openQualify() {
  const q = pipeline.value!.qualification;
  quality.value = {
    ...Object.fromEntries(q.dimensions.map((d) => [d.key, d.value])),
    ...q.summary,
  };
  qualifying.value = true;
}

async function saveQualification() {
  if (await act("POST", "/qualify", quality.value, "Assessment saved.")) qualifying.value = false;
}

const markQualified = () => act("POST", "/mark-qualified", undefined, "Marked as qualified.");

// ── Estimates ───────────────────────────────────────────────────────────────

const showEstimate = ref(false);
const estimateForm = ref({ name: "", budget: null, revenue: null, cost: null, profit: null } as Record<string, unknown>);
const openEstimate = ref<Estimate | null>(null);

async function saveEstimate() {
  if (await act("POST", "/estimates", estimateForm.value, "Estimate added.")) {
    showEstimate.value = false;
    estimateForm.value = { name: "", budget: null, revenue: null, cost: null, profit: null };
  }
}

// ── Budget ──────────────────────────────────────────────────────────────────

const budgetAmount = ref<number | null>(null);
const editingBudget = ref(false);

const confirmBudget = async () => {
  if (await act("POST", "/budget/confirm", { amount: budgetAmount.value }, "Budget confirmed.")) {
    editingBudget.value = false;
  }
};
const revokeBudget = () => act("POST", "/budget/revoke", undefined, "Confirmation revoked.");

// ── Commit ──────────────────────────────────────────────────────────────────

const showObjective = ref(false);
const objectiveForm = ref({ id: null as number | null, name: "", expected_start_date: "", expected_end_date: "" });

function editObjective(o: Objective) {
  objectiveForm.value = {
    id: o.id, name: o.name,
    expected_start_date: o.expected_start_date ?? "", expected_end_date: o.expected_end_date ?? "",
  };
  showObjective.value = true;
}

async function saveObjective() {
  const { id, ...body } = objectiveForm.value;
  const done = id
    ? await act("PUT", `/objectives/${id}`, body, "Objective updated.")
    : await act("POST", "/objectives", body, "Objective committed.");
  if (done) {
    showObjective.value = false;
    objectiveForm.value = { id: null, name: "", expected_start_date: "", expected_end_date: "" };
  }
}

// ── Bids ────────────────────────────────────────────────────────────────────

const showBid = ref(false);
const openBid = ref<Bid | null>(null);
const blankBid = () => ({
  id: null as number | null, tender_number: "", bid_number: "", tender_name: "",
  tender_validity_date: "", bid_bond_validity_date: "", bid_bond_value: null as number | null,
  bidding_cost: null as number | null, bidding_value: null as number | null,
  tender_close_date: "", expected_award_date: "", status: "bidding", outcome_notes: "",
});
const bidForm = ref(blankBid());

function editBid(b: Bid) {
  bidForm.value = {
    id: b.id, tender_number: b.tender_number, bid_number: b.bid_number ?? "",
    tender_name: b.tender_name ?? "",
    tender_validity_date: b.tender_validity_date ?? "",
    bid_bond_validity_date: b.bid_bond_validity_date ?? "",
    bid_bond_value: b.bid_bond_value === null ? null : Number(b.bid_bond_value),
    bidding_cost: b.bidding_cost === null ? null : Number(b.bidding_cost),
    bidding_value: b.bidding_value === null ? null : Number(b.bidding_value),
    tender_close_date: b.tender_close_date ?? "",
    expected_award_date: b.expected_award_date ?? "",
    status: b.status, outcome_notes: b.outcome_notes ?? "",
  };
  openBid.value = null;
  showBid.value = true;
}

async function saveBid() {
  const { id, ...raw } = bidForm.value;
  // Empty strings are not dates; the API would reject them and the message
  // would blame the wrong field.
  const body = Object.fromEntries(Object.entries(raw).map(([k, v]) => [k, v === "" ? null : v]));

  const done = id
    ? await act("PUT", `/bids/${id}`, body, "Bid updated.")
    : await act("POST", "/bids", body, "Bid recorded.");
  if (done) { showBid.value = false; bidForm.value = blankBid(); }
}

// ── Contracting and kickoff ─────────────────────────────────────────────────

const showContract = ref(false);
const contractForm = ref({ name: "", start_date: "", end_date: "", billing_cycle: "", contract_value: null, owner: "" } as Record<string, unknown>);

async function saveContract() {
  if (await act("POST", "/contracts", contractForm.value, "Contract added.")) showContract.value = false;
}

const showProject = ref(false);
const projectForm = ref({ name: "", deadline: "", priority: "medium", description: "", project_lead_id: null as number | null });

async function saveProject() {
  const body = { ...projectForm.value, deadline: projectForm.value.deadline || null };
  if (await act("POST", "/projects", body, "Project created.")) showProject.value = false;
}

// ── Documents ───────────────────────────────────────────────────────────────

const uploading = ref(false);

/**
 * One upload path for every stage, mirroring the API.
 *
 * `stage` is what the panel is showing, so a document filed from the Site
 * Survey panel cannot land under Specification by accident.
 */
async function upload(event: Event, stageKey: string, extra: Record<string, string> = {}) {
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0];
  if (!file) return;

  uploading.value = true;
  try {
    const body = new FormData();
    body.append("file", file);
    body.append("stage", stageKey);
    Object.entries(extra).forEach(([k, v]) => body.append(k, v));

    pipeline.value = (await api<{ data: Pipeline }>("POST", `${base.value}/documents`, { org: org(), body })).data;
    notifySaved("Document attached.");
  } catch (e) { fail(e, "Could not attach the document."); }
  finally { uploading.value = false; input.value = ""; }
}

const removeDoc = (d: Doc) => act("DELETE", `/documents/${d.id}`, undefined, "Document removed.");

function downloadUrl(d: Doc): string {
  const root = (import.meta.env.VITE_LARAVEL_API_URL ?? "").replace(/\/$/, "");
  return `${root}${base.value}/documents/${d.id}/download?org=${org()}`;
}

const docsFor = (key: string) => pipeline.value?.documents?.[key] ?? [];

// ── Presentation ────────────────────────────────────────────────────────────

/**
 * Money, or a dash and a reason.
 *
 * Never rendered as 0 or blank when it is withheld — a masked figure and an
 * unrecorded one must not look the same.
 */
function money(v: string | number | null): string {
  if (!pipeline.value?.commercials_visible) return "•••";
  if (v === null || v === "") return "—";
  return `KES ${Number(v).toLocaleString(undefined, { maximumFractionDigits: 0 })}`;
}

const date = (v: string | null) =>
  v ? new Date(v).toLocaleDateString(undefined, { day: "2-digit", month: "short", year: "numeric" }) : "—";

const titleCase = (v: string | null) =>
  v ? v.replace(/_/g, " ").replace(/\b\w/g, (m) => m.toUpperCase()) : "—";

const kb = (b: number | null) => (b === null ? "" : `${Math.round(b / 1024)} KB`);

const STAGE_BLURB: Record<string, string> = {
  "Opportunity Processing": "Ten strength indicators, ten points each. Score 65 or more to commit.",
  "Scope/Site Survey": "What was found on site, and the scope it implies.",
  "Estimate": "What each phase of the work is expected to cost and earn.",
  "Opportunity Qualification": "Mark the opportunity as qualified once all criteria are met.",
  "Specification": "The technical specification the bid will be built against.",
  "Awaiting Budget Confirmation": "Once budget is confirmed the opportunity moves to commit stage.",
  "Opportunity Commit": "Once the budget is approved, the opportunity transitions into the commitment phase for the quarterly objectives.",
  "Awaiting BID Release": "Waiting for the tender to be released.",
  "Bidding": "The tender, the bond, and what we bid.",
  "Negotiations": "Where the terms were argued and what was conceded.",
  "Contracting": "Begin the process of adding contract details by initiating a project focused on the secured agreement.",
  "Project Kick-Off": "Start working on the secured contract by creating a project.",
};

/** The gauge arc for the fit score — a three-quarter sweep. */
const GAUGE = { r: 62, c: 2 * Math.PI * 62 * 0.75 };
const gaugeOffset = computed(() => GAUGE.c * (1 - (pipeline.value?.qualification.score ?? 0) / 100));

const BAND_LABELS: Record<string, string> = {
  high_fit: "High Fit", moderate_fit: "Moderate Fit", low_fit: "Low Fit", poor_fit: "Poor Fit",
};

watch(() => props.opportunityId, load, { immediate: true });
</script>

<template>
  <div v-if="loading" class="pl-loading">Loading…</div>

  <div v-else-if="pipeline" class="pl-wrap">
    <!-- Stage rail -->
    <aside class="pl-rail">
      <button v-for="s in pipeline.stages" :key="s.id"
              :class="['pl-rail-item', { active: s.name === current, done: isDone(s) }]"
              @click="current = s.name">
        <span class="pl-rail-name">{{ s.name }}</span>
        <span v-if="isDone(s)" class="pl-rail-tick" aria-label="Complete">✓</span>
      </button>
    </aside>

    <section class="pl-panel">
      <header class="pl-panel-head">
        <div>
          <h2 class="pl-panel-title">{{ current }}</h2>
          <p v-if="STAGE_BLURB[current]" class="pl-panel-blurb">{{ STAGE_BLURB[current] }}</p>
        </div>
        <button v-if="stage" class="btn-ghost" :disabled="saving"
                @click="isDone(stage) ? reopenStage() : completeStage()">
          {{ isDone(stage) ? "Reopen" : "Mark complete" }}
        </button>
      </header>

      <!-- Commercial masking, said once rather than beside every figure. -->
      <p v-if="!pipeline.commercials_visible" class="pl-masked">
        Figures on this deal are hidden. They need the commercial-data permission.
      </p>

      <!-- ── Processing ──────────────────────────────────────────────── -->
      <div v-if="current === 'Opportunity Processing'" class="pl-body">
        <div class="pl-score-head">
          <div>
            <h3 class="pl-h3">Opportunity Score</h3>
            <p class="pl-hint">
              {{ pipeline.strength.score }} of {{ pipeline.strength.max }} —
              {{ pipeline.strength.meets
                 ? "clear to commit."
                 : `${pipeline.strength.threshold - pipeline.strength.score} more needed to commit.` }}
            </p>
          </div>
          <div :class="['pl-ring', `band-${pipeline.strength.band}`]">
            <svg viewBox="0 0 44 44" aria-hidden="true">
              <circle class="pl-ring-track" cx="22" cy="22" r="18" />
              <circle class="pl-ring-fill" cx="22" cy="22" r="18"
                      :stroke-dasharray="`${2 * Math.PI * 18}`"
                      :stroke-dashoffset="2 * Math.PI * 18 * (1 - pipeline.strength.score / 100)" />
            </svg>
            <span class="pl-ring-value">{{ pipeline.strength.score }}%</span>
          </div>
        </div>

        <ul class="pl-indicators">
          <li v-for="i in pipeline.strength.indicators" :key="i.key">
            <label class="pl-indicator">
              <input type="checkbox" :checked="i.met" :disabled="saving" @change="toggleIndicator(i)" />
              <span>
                <span class="pl-indicator-label">{{ i.label }}</span>
                <span class="pl-indicator-help">{{ i.help }}</span>
              </span>
            </label>
          </li>
        </ul>
      </div>

      <!-- ── Qualification ───────────────────────────────────────────── -->
      <div v-else-if="current === 'Opportunity Qualification'" class="pl-body">
        <div class="pl-qual-head">
          <span v-if="pipeline.qualification.qualified_at" class="pill pill-ok">Qualified</span>
          <div class="pl-qual-actions">
            <button class="btn-ghost" @click="qualifying ? (qualifying = false) : openQualify()">
              {{ qualifying ? "Cancel" : "Assess" }}
            </button>
            <button v-if="!pipeline.qualification.qualified_at" class="btn-primary"
                    :disabled="saving || pipeline.qualification.score === null" @click="markQualified">
              Mark qualified
            </button>
          </div>
        </div>

        <template v-if="!qualifying">
          <p v-if="pipeline.qualification.score === null" class="pl-empty">
            Not assessed yet. Grading the five dimensions is what "all criteria are met"
            refers to — an unassessed deal has no score, rather than a score of zero.
          </p>

          <div v-else class="pl-qual-grid">
            <div class="pl-card">
              <h3 class="pl-h3">Qualification Score</h3>
              <div class="pl-gauge-row">
                <svg class="pl-gauge" viewBox="0 0 160 160" role="img"
                     :aria-label="`${pipeline.qualification.score} out of 100`">
                  <circle class="pl-gauge-track" cx="80" cy="80" :r="GAUGE.r"
                          :stroke-dasharray="`${GAUGE.c} ${GAUGE.c * 2}`" />
                  <circle :class="['pl-gauge-fill', `fit-${pipeline.qualification.band}`]"
                          cx="80" cy="80" :r="GAUGE.r"
                          :stroke-dasharray="`${GAUGE.c} ${GAUGE.c * 2}`"
                          :stroke-dashoffset="gaugeOffset" />
                  <text x="80" y="76" class="pl-gauge-label">
                    {{ BAND_LABELS[pipeline.qualification.band ?? ""] ?? "" }}
                  </text>
                  <text x="80" y="104" class="pl-gauge-value">{{ pipeline.qualification.score }}%</text>
                </svg>
                <div class="pl-gauge-note">
                  <p class="pl-hint">
                    Based on {{ pipeline.qualification.completeness }}% of the assessment.
                  </p>
                  <span v-if="pipeline.qualification.percentile !== null" class="pill pill-ok">
                    Top {{ Math.max(pipeline.qualification.percentile, 1) }}% of all deals
                  </span>
                </div>
              </div>
            </div>

            <div class="pl-card">
              <h3 class="pl-h3">Qualification Summary</h3>
              <dl class="pl-mini">
                <div><dt>Budget Availability</dt><dd>{{ titleCase(pipeline.qualification.summary.budget_availability) }}</dd></div>
                <div><dt>Stakeholder Engagement</dt><dd>{{ titleCase(pipeline.qualification.summary.stakeholder_engagement) }}</dd></div>
                <div><dt>Timeline</dt><dd>{{ pipeline.qualification.summary.timeline_estimate ?? "—" }}</dd></div>
                <div><dt>Technical Readiness</dt><dd>{{ titleCase(pipeline.qualification.summary.technical_readiness) }}</dd></div>
                <div><dt>Decision Authority Access</dt><dd>{{ titleCase(pipeline.qualification.summary.decision_authority_access) }}</dd></div>
              </dl>
            </div>

            <div class="pl-card pl-span">
              <h3 class="pl-h3">Qualification Breakdown</h3>
              <ul class="pl-bars">
                <li v-for="d in pipeline.qualification.dimensions" :key="d.key">
                  <span class="pl-bar-label">{{ d.label }}</span>
                  <span class="pl-bar-track"><span class="pl-bar-fill" :style="{ width: `${d.value ?? 0}%` }"></span></span>
                  <span class="pl-bar-value">{{ d.value === null ? "—" : `${d.value}%` }}</span>
                </li>
              </ul>
            </div>
          </div>
        </template>

        <div v-else class="pl-card">
          <div class="pl-grid">
            <label v-for="d in pipeline.qualification.dimensions" :key="d.key" class="pl-field">
              <span>{{ d.label }}</span>
              <input v-model.number="quality[d.key]" class="control" type="number" min="0" max="100" />
            </label>

            <label class="pl-field"><span>Budget availability</span>
              <select v-model="quality.budget_availability" class="control">
                <option :value="null">—</option><option value="available">Available</option>
                <option value="limited">Limited</option><option value="none">None</option>
              </select></label>
            <label class="pl-field"><span>Stakeholder engagement</span>
              <select v-model="quality.stakeholder_engagement" class="control">
                <option :value="null">—</option><option value="high">High</option>
                <option value="medium">Medium</option><option value="low">Low</option>
              </select></label>
            <label class="pl-field"><span>Timeline</span>
              <input v-model="quality.timeline_estimate" class="control" placeholder="3–4 Months" /></label>
            <label class="pl-field"><span>Technical readiness</span>
              <select v-model="quality.technical_readiness" class="control">
                <option :value="null">—</option><option value="high">High</option>
                <option value="medium">Medium</option><option value="low">Low</option>
              </select></label>
            <label class="pl-field"><span>Decision authority access</span>
              <select v-model="quality.decision_authority_access" class="control">
                <option :value="null">—</option><option value="available">Available</option>
                <option value="limited">Limited</option><option value="none">None</option>
              </select></label>
          </div>
          <div class="pl-actions">
            <button class="btn-primary" :disabled="saving" @click="saveQualification">Save assessment</button>
          </div>
        </div>
      </div>

      <!-- ── Estimate ────────────────────────────────────────────────── -->
      <div v-else-if="current === 'Estimate'" class="pl-body">
        <div class="pl-toolbar">
          <button class="btn-primary" @click="showEstimate = true">+ Add</button>
        </div>

        <p v-if="!pipeline.estimates.length" class="pl-empty">No estimates yet.</p>

        <table v-else class="pl-table">
          <thead><tr><th>Name</th><th class="pl-num">Budget</th><th class="pl-num">Revenue</th>
            <th class="pl-num">Cost</th><th class="pl-num">Profit</th><th></th></tr></thead>
          <tbody>
            <tr v-for="e in pipeline.estimates" :key="e.id">
              <td class="pl-strong">{{ e.name }}</td>
              <td class="pl-num">{{ money(e.budget) }}</td>
              <td class="pl-num">{{ money(e.revenue) }}</td>
              <td class="pl-num">{{ money(e.cost) }}</td>
              <td class="pl-num">
                {{ money(e.profit) }}
                <!-- A quoted profit is negotiated, so it is kept as quoted. The
                     arithmetic is flagged rather than applied. -->
                <span v-if="pipeline.commercials_visible && e.implied_profit !== null
                            && Number(e.implied_profit) !== Number(e.profit)"
                      class="pl-warn" :title="`Revenue less cost is ${money(e.implied_profit)}`">⚠</span>
              </td>
              <td><button class="link-btn" @click="openEstimate = e">View Details</button></td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- ── Budget confirmation ─────────────────────────────────────── -->
      <div v-else-if="current === 'Awaiting Budget Confirmation'" class="pl-body">
        <div class="pl-card">
          <div class="pl-budget-head">
            <div>
              <h3 class="pl-h3">{{ pipeline.budget.is_confirmed ? "Budget Confirmed" : "Budget not confirmed" }}</h3>
              <p class="pl-hint">Once budget is confirmed the opportunity moves to commit stage.</p>
            </div>
            <div class="pl-budget-actions">
              <button v-if="pipeline.budget.is_confirmed" class="btn-ghost btn-danger"
                      :disabled="saving" @click="revokeBudget">Revoke</button>
              <button class="btn-primary" :disabled="saving"
                      @click="editingBudget ? confirmBudget() : (editingBudget = true, budgetAmount = pipeline.budget.amount === null ? null : Number(pipeline.budget.amount))">
                Confirm
              </button>
            </div>
          </div>

          <div class="pl-budget-value">
            <input v-if="editingBudget" v-model.number="budgetAmount" class="control pl-budget-input"
                   type="number" min="0" step="1000" placeholder="Amount" />
            <template v-else>
              <span class="pl-budget-figure">{{ money(pipeline.budget.amount) }}</span>
              <span class="pl-hint">Current Budget</span>
            </template>
          </div>

          <!-- Revoking keeps the figure: withdrawn is not the same as never
               quoted, and the screen should not pretend otherwise. -->
          <p v-if="!pipeline.budget.is_confirmed && pipeline.budget.amount !== null" class="pl-hint">
            This figure was confirmed previously and the confirmation was withdrawn.
          </p>
        </div>
      </div>

      <!-- ── Commit ──────────────────────────────────────────────────── -->
      <div v-else-if="current === 'Opportunity Commit'" class="pl-body">
        <p v-if="!pipeline.strength.meets" class="pl-gate">
          This deal scores {{ pipeline.strength.score }}. It needs
          {{ pipeline.strength.threshold }} before the stage can be closed —
          {{ pipeline.strength.indicators.filter((i) => !i.met).length }} indicators are still unmet.
        </p>

        <div class="pl-toolbar">
          <button class="btn-primary" @click="objectiveForm = { id: null, name: '', expected_start_date: '', expected_end_date: '' }; showObjective = true">
            + Commit Objective
          </button>
        </div>

        <p v-if="!pipeline.objectives.length" class="pl-empty">Nothing committed yet.</p>

        <table v-else class="pl-table">
          <thead><tr><th>Objective</th><th>Expected Start Date</th><th>Expected End Date</th><th>Action</th></tr></thead>
          <tbody>
            <tr v-for="o in pipeline.objectives" :key="o.id">
              <td>
                <span class="pl-strong">{{ o.name }}</span>
                <span v-if="o.quarter_span" class="pl-sub">{{ o.quarter_span }}</span>
              </td>
              <td>{{ date(o.expected_start_date) }}</td>
              <td>{{ date(o.expected_end_date) }}</td>
              <td><button class="link-btn" @click="editObjective(o)">Edit</button></td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- ── Bidding ─────────────────────────────────────────────────── -->
      <div v-else-if="current === 'Bidding'" class="pl-body">
        <div class="pl-toolbar">
          <button class="btn-primary" @click="bidForm = blankBid(); showBid = true">+ Add Bid</button>
        </div>

        <p v-if="!pipeline.bids.length" class="pl-empty">No bid recorded.</p>

        <table v-else class="pl-table">
          <thead><tr><th>Tender Number</th><th>Bid Number</th><th class="pl-num">Bid Value</th>
            <th>Tender Close Date</th><th>Status</th><th>Action</th></tr></thead>
          <tbody>
            <tr v-for="b in pipeline.bids" :key="b.id">
              <td class="pl-strong">{{ b.tender_number }}</td>
              <td>{{ b.bid_number ?? "—" }}</td>
              <td class="pl-num">{{ money(b.bidding_value) }}</td>
              <td>
                {{ date(b.tender_close_date) }}
                <!-- A bond lapsing before the award is a bid that cannot be
                     honoured; nothing else on the screen would surface it. -->
                <span v-if="b.bond_lapses_before_award" class="pl-warn"
                      title="The bid bond lapses before the expected award date">⚠</span>
              </td>
              <td><span class="pill pill-neutral">{{ titleCase(b.status) }}</span></td>
              <td><button class="link-btn" @click="openBid = b">View Details</button></td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- ── Contracting ─────────────────────────────────────────────── -->
      <div v-else-if="current === 'Contracting'" class="pl-body">
        <div v-if="!pipeline.contracts.length" class="pl-empty-block">
          <h3 class="pl-h3">Add Contract Details</h3>
          <p class="pl-hint">{{ STAGE_BLURB['Contracting'] }}</p>
          <button class="btn-primary" @click="showContract = true">+ New Contract</button>
        </div>

        <template v-else>
          <div class="pl-toolbar"><button class="btn-primary" @click="showContract = true">+ New Contract</button></div>
          <table class="pl-table">
            <thead><tr><th>ID</th><th>Contract Name</th><th class="pl-num">Value</th><th>End Date</th><th>Status</th></tr></thead>
            <tbody>
              <tr v-for="c in pipeline.contracts" :key="c.id">
                <td class="pl-ref">{{ c.reference ?? "—" }}</td>
                <td class="pl-strong">{{ c.name }}</td>
                <td class="pl-num">{{ money(c.contract_value) }}</td>
                <td>{{ date(c.end_date) }}</td>
                <td><span class="pill pill-warn">{{ titleCase(c.status) }}</span></td>
              </tr>
            </tbody>
          </table>
        </template>
      </div>

      <!-- ── Project kickoff ─────────────────────────────────────────── -->
      <div v-else-if="current === 'Project Kick-Off'" class="pl-body">
        <div v-if="!pipeline.projects.length" class="pl-empty-block">
          <h3 class="pl-h3">Create a project</h3>
          <p class="pl-hint">{{ STAGE_BLURB['Project Kick-Off'] }}</p>
          <button class="btn-primary" @click="showProject = true">+ Create Project</button>
        </div>

        <div v-else class="pl-card">
          <div v-for="p in pipeline.projects" :key="p.id" class="pl-project">
            <div>
              <h3 class="pl-h3">{{ p.name }}</h3>
              <p class="pl-hint">
                {{ p.reference }} · deadline {{ date(p.deadline) }} ·
                <span :class="p.priority === 'high' ? 'pl-high' : ''">{{ titleCase(p.priority) }} priority</span>
              </p>
            </div>
            <button class="btn-ghost" @click="router.push({ name: 'project', params: { id: p.id } })">
              View Project
            </button>
          </div>

          <div class="pl-files">
            <label class="btn-ghost pl-upload">
              {{ uploading ? "Uploading…" : "Add attachment" }}
              <input type="file" hidden :disabled="uploading" @change="upload($event, 'kickoff')" />
            </label>
            <span v-for="d in docsFor('kickoff')" :key="d.id" class="pl-file">
              <a :href="downloadUrl(d)" class="link-btn">{{ d.label }}</a>
              <span class="pl-file-size">{{ kb(d.size_bytes) }}</span>
              <button class="link-btn link-danger" @click="removeDoc(d)">×</button>
            </span>
          </div>
        </div>
      </div>

      <!-- ── Document stages ─────────────────────────────────────────── -->
      <div v-else class="pl-body">
        <div class="pl-toolbar">
          <label class="btn-primary pl-upload">
            {{ uploading ? "Uploading…" : "+ Add" }}
            <input type="file" hidden :disabled="uploading"
                   @change="upload($event, current === 'Scope/Site Survey' ? 'site_survey'
                                          : current === 'Specification' ? 'specification'
                                          : current === 'Negotiations' ? 'negotiation' : 'general')" />
          </label>
        </div>

        <p v-if="!docsFor(current === 'Scope/Site Survey' ? 'site_survey'
                        : current === 'Specification' ? 'specification'
                        : current === 'Negotiations' ? 'negotiation' : 'general').length"
           class="pl-empty">
          Nothing filed at this stage.
        </p>

        <table v-else class="pl-table">
          <thead><tr><th>Date</th><th>Documents</th><th>Type</th><th>Action</th></tr></thead>
          <tbody>
            <tr v-for="d in docsFor(current === 'Scope/Site Survey' ? 'site_survey'
                                  : current === 'Specification' ? 'specification'
                                  : current === 'Negotiations' ? 'negotiation' : 'general')" :key="d.id">
              <td class="pl-nowrap">{{ date(d.uploaded_at) }}</td>
              <td>
                <span class="pl-doc">
                  <span class="pl-doc-ext">{{ (d.original_name.split(".").pop() ?? "").toUpperCase() }}</span>
                  <span><span class="pl-strong">{{ d.label }}</span><span class="pl-file-size">{{ kb(d.size_bytes) }}</span></span>
                </span>
              </td>
              <td>{{ titleCase(d.document_type) }} Document</td>
              <td class="pl-row-actions">
                <a :href="downloadUrl(d)" class="link-btn">View</a>
                <button class="link-btn link-danger" @click="removeDoc(d)">Delete</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- ── Sheets ────────────────────────────────────────────────────── -->

    <div v-if="showEstimate" class="pl-sheet-backdrop" @click.self="showEstimate = false">
      <aside class="pl-sheet" role="dialog" aria-label="New estimate">
        <div class="pl-sheet-head"><h3 class="pl-sheet-title">New Estimate</h3>
          <button class="pl-close" @click="showEstimate = false">×</button></div>
        <label class="pl-field"><span>Estimate Name</span>
          <input v-model="estimateForm.name" class="control" placeholder="Phase 1 - Network" /></label>
        <label class="pl-field"><span>Budget</span>
          <input v-model.number="estimateForm.budget" class="control" type="number" min="0" /></label>
        <label class="pl-field"><span>Revenue</span>
          <input v-model.number="estimateForm.revenue" class="control" type="number" min="0" /></label>
        <label class="pl-field"><span>Cost</span>
          <input v-model.number="estimateForm.cost" class="control" type="number" min="0" /></label>
        <label class="pl-field"><span>Profit</span>
          <input v-model.number="estimateForm.profit" class="control" type="number" min="0" />
          <span class="pl-hint">Kept as quoted. Revenue less cost is shown beside it if the two differ.</span></label>
        <button class="btn-primary btn-block" :disabled="saving || !estimateForm.name" @click="saveEstimate">
          Create Estimate
        </button>
      </aside>
    </div>

    <div v-if="openEstimate" class="pl-sheet-backdrop" @click.self="openEstimate = null">
      <aside class="pl-sheet" role="dialog" aria-label="Estimate">
        <div class="pl-sheet-head"><h3 class="pl-sheet-title">{{ openEstimate.name }}</h3>
          <button class="pl-close" @click="openEstimate = null">×</button></div>
        <dl class="pl-mini">
          <div><dt>Budget</dt><dd>{{ money(openEstimate.budget) }}</dd></div>
          <div><dt>Revenue</dt><dd>{{ money(openEstimate.revenue) }}</dd></div>
          <div><dt>Cost</dt><dd>{{ money(openEstimate.cost) }}</dd></div>
          <div><dt>Profit</dt><dd>{{ money(openEstimate.profit) }}</dd></div>
          <div v-if="pipeline.commercials_visible && openEstimate.implied_profit !== null">
            <dt>Revenue less cost</dt><dd>{{ money(openEstimate.implied_profit) }}</dd></div>
        </dl>
        <div class="pl-files">
          <label class="btn-ghost pl-upload">
            {{ uploading ? "Uploading…" : "Solution estimate file" }}
            <input type="file" hidden :disabled="uploading"
                   @change="upload($event, 'estimate', { estimate_id: String(openEstimate!.id), slot: 'solution_estimate' })" />
          </label>
          <span v-for="d in openEstimate.documents" :key="d.id" class="pl-file">
            <a :href="downloadUrl(d)" class="link-btn">{{ d.label }}</a>
            <span class="pl-file-size">{{ kb(d.size_bytes) }}</span>
          </span>
        </div>
        <button class="btn-primary btn-block" @click="openEstimate = null">Close</button>
      </aside>
    </div>

    <div v-if="showObjective" class="pl-sheet-backdrop" @click.self="showObjective = false">
      <aside class="pl-sheet" role="dialog" aria-label="Commit an objective">
        <div class="pl-sheet-head">
          <h3 class="pl-sheet-title">{{ objectiveForm.id ? "Edit Objective" : "Commit an Objective" }}</h3>
          <button class="pl-close" @click="showObjective = false">×</button></div>
        <label class="pl-field"><span>Objective Name</span>
          <input v-model="objectiveForm.name" class="control" placeholder="Phase 1" /></label>
        <label class="pl-field"><span>Expected Start Date</span>
          <input v-model="objectiveForm.expected_start_date" class="control" type="date" /></label>
        <label class="pl-field"><span>Expected End Date</span>
          <input v-model="objectiveForm.expected_end_date" class="control" type="date" /></label>
        <button class="btn-primary btn-block"
                :disabled="saving || !objectiveForm.name || !objectiveForm.expected_start_date || !objectiveForm.expected_end_date"
                @click="saveObjective">
          {{ objectiveForm.id ? "Save Changes" : "Commit Objective" }}
        </button>
      </aside>
    </div>

    <div v-if="showBid" class="pl-sheet-backdrop" @click.self="showBid = false">
      <aside class="pl-sheet" role="dialog" aria-label="Bid">
        <div class="pl-sheet-head"><h3 class="pl-sheet-title">{{ bidForm.id ? "Edit Bid" : "New Bid" }}</h3>
          <button class="pl-close" @click="showBid = false">×</button></div>
        <label class="pl-field"><span>Tender Number <em class="pl-req">required</em></span>
          <input v-model="bidForm.tender_number" class="control" placeholder="#TR678932HG" /></label>
        <label class="pl-field"><span>Bid Number</span>
          <input v-model="bidForm.bid_number" class="control" /></label>
        <label class="pl-field"><span>Tender Validity</span>
          <input v-model="bidForm.tender_validity_date" class="control" type="date" /></label>
        <label class="pl-field"><span>Bid Bond Validity</span>
          <input v-model="bidForm.bid_bond_validity_date" class="control" type="date" /></label>
        <div class="pl-grid">
          <label class="pl-field"><span>Bid Bond Value</span>
            <input v-model.number="bidForm.bid_bond_value" class="control" type="number" min="0" /></label>
          <label class="pl-field"><span>Bidding Cost</span>
            <input v-model.number="bidForm.bidding_cost" class="control" type="number" min="0" /></label>
        </div>
        <label class="pl-field"><span>Bidding Value</span>
          <input v-model.number="bidForm.bidding_value" class="control" type="number" min="0" /></label>
        <label class="pl-field"><span>Tender Close Date</span>
          <input v-model="bidForm.tender_close_date" class="control" type="date" /></label>
        <label class="pl-field"><span>Expected Close / Award Date</span>
          <input v-model="bidForm.expected_award_date" class="control" type="date" /></label>
        <label class="pl-field"><span>Status</span>
          <select v-model="bidForm.status" class="control">
            <option value="bidding">Bidding</option><option value="won">Won</option>
            <option value="lost">Lost</option><option value="withdrawn">Withdrawn</option>
          </select></label>
        <label class="pl-field"><span>Outcome notes</span>
          <textarea v-model="bidForm.outcome_notes" class="control" rows="3"
                    placeholder="Why it was won or lost"></textarea></label>
        <div class="pl-sheet-actions">
          <button class="btn-ghost" @click="showBid = false">Cancel</button>
          <button class="btn-primary" :disabled="saving || !bidForm.tender_number" @click="saveBid">Save Details</button>
        </div>
      </aside>
    </div>

    <div v-if="openBid" class="pl-sheet-backdrop" @click.self="openBid = null">
      <aside class="pl-sheet" role="dialog" aria-label="Bid detail">
        <div class="pl-sheet-head"><h3 class="pl-sheet-title">{{ openBid.tender_number }}</h3>
          <button class="pl-close" @click="openBid = null">×</button></div>
        <dl class="pl-mini">
          <div><dt>Bid Number</dt><dd>{{ openBid.bid_number ?? "—" }}</dd></div>
          <div><dt>Tender Validity</dt><dd>{{ date(openBid.tender_validity_date) }}</dd></div>
          <div><dt>Bond Validity</dt><dd>{{ date(openBid.bid_bond_validity_date) }}</dd></div>
          <div><dt>Bond Value</dt><dd>{{ money(openBid.bid_bond_value) }}</dd></div>
          <div><dt>Bidding Cost</dt><dd>{{ money(openBid.bidding_cost) }}</dd></div>
          <div><dt>Bidding Value</dt><dd>{{ money(openBid.bidding_value) }}</dd></div>
          <div><dt>Expected profit</dt><dd>{{ money(openBid.bidding_profit) }}</dd></div>
          <div><dt>Expected Close Date</dt><dd>{{ date(openBid.expected_award_date) }}</dd></div>
        </dl>
        <p v-if="openBid.bond_lapses_before_award" class="pl-gate">
          The bid bond lapses before the expected award date. This bid could not be
          honoured as it stands.
        </p>
        <div class="pl-files">
          <label class="btn-ghost pl-upload">
            {{ uploading ? "Uploading…" : "Bidding documents" }}
            <input type="file" hidden :disabled="uploading"
                   @change="upload($event, 'bid', { bid_id: String(openBid!.id) })" />
          </label>
          <span v-for="d in openBid.documents" :key="d.id" class="pl-file">
            <a :href="downloadUrl(d)" class="link-btn">{{ d.label }}</a>
            <span class="pl-file-size">{{ kb(d.size_bytes) }}</span>
            <button class="link-btn link-danger" @click="removeDoc(d)">×</button>
          </span>
        </div>
        <div class="pl-sheet-actions">
          <button class="btn-ghost" @click="editBid(openBid!)">Edit</button>
          <button class="btn-primary" @click="openBid = null">Close</button>
        </div>
      </aside>
    </div>

    <div v-if="showContract" class="pl-sheet-backdrop" @click.self="showContract = false">
      <aside class="pl-sheet" role="dialog" aria-label="Add contract">
        <div class="pl-sheet-head"><h3 class="pl-sheet-title">Add Contract</h3>
          <button class="pl-close" @click="showContract = false">×</button></div>
        <label class="pl-field"><span>Contract Name</span>
          <input v-model="contractForm.name" class="control" placeholder="Enterprise SaaS Agreement" /></label>
        <div class="pl-grid">
          <label class="pl-field"><span>Start Date</span>
            <input v-model="contractForm.start_date" class="control" type="date" /></label>
          <label class="pl-field"><span>Deadline</span>
            <input v-model="contractForm.end_date" class="control" type="date" /></label>
        </div>
        <label class="pl-field"><span>Billing Cycle</span>
          <select v-model="contractForm.billing_cycle" class="control">
            <option value="">—</option><option value="one_off">One off</option>
            <option value="monthly">Monthly</option><option value="quarterly">Quarterly</option>
            <option value="biannual">Biannual</option><option value="annual">Annual</option>
          </select></label>
        <label class="pl-field"><span>Contract Value</span>
          <input v-model.number="contractForm.contract_value" class="control" type="number" min="0" /></label>
        <label class="pl-field"><span>Owner</span>
          <input v-model="contractForm.owner" class="control" placeholder="Konvergenz Networking Solutions" /></label>
        <p class="pl-hint">
          Saved to the customer's contracts, so their record and this deal show the
          same agreement. Attach the signed contract and award letter once it exists.
        </p>
        <div class="pl-sheet-actions">
          <button class="btn-ghost" @click="showContract = false">Cancel</button>
          <button class="btn-primary"
                  :disabled="saving || !contractForm.name || !contractForm.start_date || !contractForm.end_date"
                  @click="saveContract">Save Contract</button>
        </div>
      </aside>
    </div>

    <div v-if="showProject" class="pl-sheet-backdrop" @click.self="showProject = false">
      <aside class="pl-sheet" role="dialog" aria-label="New project">
        <div class="pl-sheet-head"><h3 class="pl-sheet-title">New Project</h3>
          <button class="pl-close" @click="showProject = false">×</button></div>
        <label class="pl-field"><span>Project Name</span>
          <input v-model="projectForm.name" class="control" placeholder="Employee Portal Revamp" /></label>
        <label class="pl-field"><span>Project Deadline</span>
          <input v-model="projectForm.deadline" class="control" type="date" /></label>
        <label class="pl-field"><span>Project Priority</span>
          <select v-model="projectForm.priority" class="control">
            <option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option>
          </select></label>
        <div class="pl-field"><span>Project lead</span>
          <EmployeePicker v-model="projectForm.project_lead_id" placeholder="Who is running it?" /></div>
        <p class="pl-hint">
          Scope of work, SLA and bill of materials attach to the deal once the project
          exists — they are the documents it was won against, so they stay with the deal
          rather than being copied.
        </p>
        <div class="pl-sheet-actions">
          <button class="btn-ghost" @click="showProject = false">Cancel</button>
          <button class="btn-primary" :disabled="saving || !projectForm.name" @click="saveProject">Save</button>
        </div>
      </aside>
    </div>
  </div>
</template>

<style scoped>
@reference "../../theme.css";

.pl-loading { padding: var(--space-6); color: var(--color-text-muted); }

.pl-wrap { @apply flex; gap: var(--space-4); align-items: flex-start; }
@media (max-width: 56rem) { .pl-wrap { @apply flex-col; } }

/* ── Rail ──────────────────────────────────────────────────────────────── */
.pl-rail {
  @apply flex shrink-0 flex-col; width: 13rem; gap: 2px;
  padding: var(--space-2);
  background: var(--color-surface-raised);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
}
@media (max-width: 56rem) { .pl-rail { @apply w-full flex-row overflow-x-auto; } }

.pl-rail-item {
  @apply flex cursor-pointer items-center border-none bg-transparent;
  gap: var(--space-2); padding: var(--space-2) var(--space-3);
  border-radius: var(--radius-md); border-left: 2px solid transparent;
  font-family: inherit; font-size: var(--text-sm-size); color: var(--color-text-muted);
  text-align: left; white-space: nowrap;
}
.pl-rail-item:hover { background: var(--color-surface-sunken); color: var(--color-text); }
.pl-rail-item.active {
  background: var(--color-brand-subtle); color: var(--color-brand-on-surface);
  border-left-color: var(--color-brand-base); font-weight: 500;
}
.pl-rail-name { @apply flex-1; }
.pl-rail-tick { color: var(--color-success-text); font-weight: 700; }

/* ── Panel ─────────────────────────────────────────────────────────────── */
.pl-panel {
  @apply flex flex-1 flex-col; gap: var(--space-4); min-width: 0;
  padding: var(--space-5);
  background: var(--color-surface-raised);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
}
.pl-panel-head { @apply flex items-start justify-between; gap: var(--space-4); }
.pl-panel-title { font-size: var(--text-lg-size); font-weight: 600; color: var(--color-text); margin: 0; }
.pl-panel-blurb { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: var(--space-1) 0 0; max-width: 60ch; }
.pl-body { @apply flex flex-col; gap: var(--space-4); }
.pl-toolbar { @apply flex justify-end; }

.pl-masked {
  padding: var(--space-2) var(--space-3); border-radius: var(--radius-md);
  background: var(--color-surface-sunken); color: var(--color-text-muted);
  font-size: var(--text-xs-size); margin: 0;
}
.pl-gate {
  padding: var(--space-3) var(--space-4); border-radius: var(--radius-md);
  background: var(--color-warning-subtle); color: var(--color-warning-text);
  font-size: var(--text-sm-size); margin: 0;
}

.pl-h3 { font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); margin: 0; }
.pl-hint { font-size: var(--text-xs-size); color: var(--color-text-light); margin: var(--space-1) 0 0; }
.pl-empty { font-size: var(--text-sm-size); color: var(--color-text-light); margin: 0; padding: var(--space-4) 0; }
.pl-empty-block { @apply flex flex-col items-center; gap: var(--space-2); padding: var(--space-8) var(--space-4); text-align: center; }
.pl-strong { font-weight: 500; color: var(--color-text); }
.pl-sub { @apply block; font-size: var(--text-xs-size); color: var(--color-text-light); }
.pl-ref { font-variant-numeric: tabular-nums; color: var(--color-text-muted); }
.pl-nowrap { white-space: nowrap; }
.pl-high { color: var(--color-danger-text); }
.pl-warn { color: var(--color-warning-text); margin-left: 4px; cursor: help; }

.pl-card {
  @apply flex flex-col; gap: var(--space-3);
  padding: var(--space-4);
  border: 1px solid var(--color-border); border-radius: var(--radius-md);
}

/* ── Processing ────────────────────────────────────────────────────────── */
.pl-score-head { @apply flex items-center justify-between; gap: var(--space-4); }
.pl-ring { @apply relative flex shrink-0 items-center justify-center; width: 3.5rem; height: 3.5rem; }
.pl-ring svg { width: 100%; height: 100%; transform: rotate(-90deg); }
.pl-ring-track { fill: none; stroke: var(--color-surface-sunken); stroke-width: 4; }
.pl-ring-fill  { fill: none; stroke-width: 4; stroke-linecap: round; transition: stroke-dashoffset 300ms ease; }
.band-strong .pl-ring-fill     { stroke: var(--color-success-text); }
.band-developing .pl-ring-fill { stroke: var(--color-warning-text); }
.band-weak .pl-ring-fill       { stroke: var(--color-danger-text); }
.pl-ring-value { @apply absolute; font-size: var(--text-xs-size); font-weight: 700; color: var(--color-text); }

.pl-indicators { @apply grid list-none; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-3); margin: 0; padding: 0; }
@media (max-width: 48rem) { .pl-indicators { grid-template-columns: 1fr; } }
.pl-indicator { @apply flex cursor-pointer items-start; gap: var(--space-2); }
.pl-indicator-label { @apply block; font-size: var(--text-sm-size); color: var(--color-text); }
.pl-indicator-help  { @apply block; font-size: var(--text-xs-size); color: var(--color-text-light); line-height: 1.45; }

/* ── Qualification ─────────────────────────────────────────────────────── */
.pl-qual-head { @apply flex items-center justify-between; gap: var(--space-3); }
.pl-qual-actions { @apply flex; gap: var(--space-2); }
.pl-qual-grid { @apply grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-4); }
@media (max-width: 56rem) { .pl-qual-grid { grid-template-columns: 1fr; } }
.pl-span { grid-column: 1 / -1; }

.pl-gauge-row { @apply flex flex-wrap items-center; gap: var(--space-4); }
.pl-gauge { width: 9rem; height: 9rem; transform: rotate(135deg); flex-shrink: 0; }
.pl-gauge-track { fill: none; stroke: var(--color-surface-sunken); stroke-width: 16; stroke-linecap: round; }
.pl-gauge-fill  { fill: none; stroke-width: 16; stroke-linecap: round; transition: stroke-dashoffset 400ms ease; }
.fit-high_fit     { stroke: var(--color-success-text); }
.fit-moderate_fit { stroke: var(--color-brand-base); }
.fit-low_fit      { stroke: var(--color-warning-text); }
.fit-poor_fit     { stroke: var(--color-danger-text); }
.pl-gauge-label, .pl-gauge-value {
  text-anchor: middle; transform: rotate(-135deg); transform-origin: 80px 80px; fill: var(--color-text);
}
.pl-gauge-label { font-size: 11px; fill: var(--color-text-muted); }
.pl-gauge-value { font-size: 26px; font-weight: 700; }
.pl-gauge-note { @apply flex flex-col items-start; gap: var(--space-2); }

.pl-bars { @apply list-none; margin: 0; padding: 0; }
.pl-bars li { @apply flex items-center; gap: var(--space-3); padding: var(--space-1) 0; }
.pl-bar-label { font-size: var(--text-sm-size); color: var(--color-text-muted); width: 9rem; flex-shrink: 0; }
.pl-bar-track { @apply block; flex: 1; height: 8px; border-radius: 999px; background: var(--color-surface-sunken); overflow: hidden; }
.pl-bar-fill  { @apply block; height: 100%; border-radius: 999px; background: var(--color-brand-base); }
.pl-bar-value { font-size: var(--text-xs-size); color: var(--color-brand-on-surface); width: 3rem; text-align: right; font-variant-numeric: tabular-nums; }

/* ── Budget ────────────────────────────────────────────────────────────── */
.pl-budget-head { @apply flex items-start justify-between; gap: var(--space-4); }
.pl-budget-actions { @apply flex; gap: var(--space-2); }
.pl-budget-value { @apply flex items-baseline; gap: var(--space-3); }
.pl-budget-figure { font-size: var(--text-2xl-size); font-weight: 700; color: var(--color-brand-on-surface); }
.pl-budget-input { max-width: 14rem; }

/* ── Tables ────────────────────────────────────────────────────────────── */
.pl-table { @apply w-full; border-collapse: collapse; font-size: var(--text-sm-size); }
.pl-table th {
  text-align: left; padding: var(--space-3); background: var(--color-surface-sunken);
  font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-muted); white-space: nowrap;
}
.pl-table td { padding: var(--space-3); border-bottom: 1px solid var(--color-border); color: var(--color-text); }
.pl-table tr:last-child td { border-bottom: none; }
.pl-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.pl-row-actions { @apply flex; gap: var(--space-3); }

.pl-doc { @apply inline-flex items-center; gap: var(--space-3); }
.pl-doc-ext {
  @apply inline-flex shrink-0 items-center justify-center;
  width: 2rem; height: 2.25rem; border-radius: var(--radius-sm);
  background: var(--color-danger-subtle); color: var(--color-danger-text);
  font-size: 0.55rem; font-weight: 700;
}
.pl-file-size { @apply block; font-size: var(--text-xs-size); color: var(--color-text-light); }

.pl-files { @apply flex flex-wrap items-center; gap: var(--space-3); padding-top: var(--space-2); border-top: 1px solid var(--color-border); }
.pl-file { @apply inline-flex items-center; gap: var(--space-2); }
.pl-upload { @apply cursor-pointer; }
.pl-project { @apply flex items-start justify-between; gap: var(--space-3); }

/* ── Forms ─────────────────────────────────────────────────────────────── */
.pl-grid { @apply grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-3); }
.pl-field { @apply flex flex-col; gap: var(--space-1); }
.pl-field > span { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.pl-req { font-style: normal; color: var(--color-danger-text); }
.pl-actions { @apply flex justify-end; }

.control {
  font-family: inherit; font-size: var(--text-sm-size);
  padding: 8px var(--space-3);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-md);
  background: var(--color-surface); color: var(--color-text); width: 100%;
}
.control:focus { @apply outline-none; border-color: var(--color-brand-base); box-shadow: 0 0 0 3px var(--color-brand-subtle); }

.pl-mini { margin: 0; }
.pl-mini > div { @apply flex items-baseline justify-between; gap: var(--space-3); padding: var(--space-2) 0; border-bottom: 1px solid var(--color-border); }
.pl-mini > div:last-child { border-bottom: none; }
.pl-mini dt { font-size: var(--text-sm-size); color: var(--color-text-muted); }
.pl-mini dd { font-size: var(--text-sm-size); color: var(--color-text); margin: 0; }

/* ── Sheets ────────────────────────────────────────────────────────────── */
.pl-sheet-backdrop { @apply fixed inset-0 flex justify-end z-50; background: rgb(0 0 0 / 0.28); }
.pl-sheet {
  @apply flex flex-col overflow-y-auto; width: min(26rem, 100%); gap: var(--space-3);
  padding: var(--space-5); background: var(--color-surface-raised); box-shadow: var(--shadow-lg);
  animation: sheet-in 180ms ease;
}
@keyframes sheet-in { from { transform: translateX(12px); opacity: 0; } }
@media (prefers-reduced-motion: reduce) { .pl-sheet { animation: none; } }
.pl-sheet-head { @apply flex items-center justify-between; }
.pl-sheet-title { font-size: var(--text-base-size); font-weight: 600; margin: 0; color: var(--color-text); }
.pl-sheet-actions { @apply flex justify-end; gap: var(--space-2); margin-top: auto; padding-top: var(--space-3); }
.pl-close { @apply cursor-pointer border-none bg-transparent p-0; font-size: 1.5rem; line-height: 1; color: var(--color-text-muted); }

.pill { display: inline-block; padding: 2px var(--space-3); border-radius: 999px; font-size: var(--text-xs-size); border: 1px solid transparent; white-space: nowrap; }
.pill-ok      { background: var(--color-success-subtle); color: var(--color-success-text); border-color: color-mix(in oklab, var(--color-success-text) 30%, transparent); }
.pill-warn    { background: var(--color-warning-subtle); color: var(--color-warning-text); border-color: color-mix(in oklab, var(--color-warning-text) 30%, transparent); }
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
.btn-danger  { color: var(--color-danger-text); border-color: color-mix(in oklab, var(--color-danger-text) 40%, transparent); }
.btn-block   { width: 100%; }
.btn-primary:disabled, .btn-ghost:disabled { @apply cursor-not-allowed opacity-50; }
</style>
