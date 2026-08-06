<script setup lang="ts">
/**
 * One lead.
 *
 * Laid out as the design has it: what the lead is across the top, the company
 * and owner beside it, and the substance — problem and proposed solution — in
 * the body.
 *
 * The Qualification tab is where a lead stops being a lead. The criteria it
 * asks for are the ones that make up an opportunity's score, so qualifying and
 * converting are the same act rather than two forms asking the same questions.
 */
import { ref, computed, onMounted } from "vue";
import { useRouter } from "vue-router";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import EmployeePicker from "../../components/EmployeePicker.vue";

const props = defineProps<{ id: string | number }>();

interface TeamMember { id: number; employee: { id: number | null; name: string | null }; role: string | null }
interface Lead {
  id: number; reference: string | null; name: string; source: string | null;
  status: string; priority: string; deadline: string | null; website: string | null;
  problem_statement: string | null; proposed_solution: string | null;
  account?: { id: number; name: string } | null;
  owner?: { id: number | null; name: string | null } | null;
  team?: TeamMember[];
  opportunity?: { id: number; reference: string | null; name: string } | null;
  created_at: string | null;
}

type Tab = "overview" | "company" | "qualification" | "team";

const router  = useRouter();
const lead    = ref<Lead | null>(null);
const tab     = ref<Tab>("overview");
const loading = ref(true);
const saving  = ref(false);
const error   = ref<string | null>(null);
const notice  = ref<string | null>(null);

const org  = () => currentOrgSlug.value;
const base = computed(() => `/api/v1/leads/${props.id}`);

const tabs: { key: Tab; label: string }[] = [
  { key: "overview",      label: "Overview" },
  { key: "company",       label: "Company" },
  { key: "qualification", label: "Qualification" },
  { key: "team",          label: "Research Team" },
];

function fail(e: unknown, fallback: string) {
  error.value = e instanceof ApiError ? (e.firstError ?? e.message) : fallback;
}

function flash(message: string) {
  notice.value = message;
  setTimeout(() => (notice.value = null), 3000);
}

async function load() {
  loading.value = true;
  try {
    lead.value = (await api<{ data: Lead }>("GET", base.value, { org: org() })).data;
    fillForm(lead.value);
  } catch (e) { fail(e, "Could not load this lead."); }
  finally { loading.value = false; }
}

// ── Editing ─────────────────────────────────────────────────────────────────

const editing = ref(false);
const form = ref({
  name: "", source: "", status: "new", priority: "medium",
  deadline: "", website: "", problem_statement: "", proposed_solution: "",
  owner_id: null as number | null,
});

function fillForm(l: Lead) {
  form.value = {
    name: l.name, source: l.source ?? "", status: l.status, priority: l.priority,
    deadline: l.deadline ?? "", website: l.website ?? "",
    problem_statement: l.problem_statement ?? "", proposed_solution: l.proposed_solution ?? "",
    owner_id: l.owner?.id ?? null,
  };
}

async function save() {
  saving.value = true;
  error.value = null;
  try {
    lead.value = (await api<{ data: Lead }>("PUT", base.value, {
      org: org(),
      body: {
        ...form.value,
        source: form.value.source || null,
        deadline: form.value.deadline || null,
        website: form.value.website || null,
        problem_statement: form.value.problem_statement || null,
        proposed_solution: form.value.proposed_solution || null,
        account_id: lead.value?.account?.id ?? null,
      },
    })).data;
    editing.value = false;
    flash("Saved.");
  } catch (e) { fail(e, "Could not save."); }
  finally { saving.value = false; }
}

// ── Research team ───────────────────────────────────────────────────────────

const newMember = ref({ employee_id: null as number | null, role: "" });

async function addMember() {
  if (!newMember.value.employee_id) return;
  saving.value = true;
  try {
    lead.value = (await api<{ data: Lead }>("POST", `${base.value}/team`, {
      org: org(), body: { ...newMember.value, role: newMember.value.role || null },
    })).data;
    newMember.value = { employee_id: null, role: "" };
  } catch (e) { fail(e, "Could not add them."); }
  finally { saving.value = false; }
}

async function removeMember(m: TeamMember) {
  try {
    lead.value = (await api<{ data: Lead }>("DELETE", `${base.value}/team/${m.id}`, { org: org() })).data;
  } catch (e) { fail(e, "Could not remove them."); }
}

// ── Qualification and conversion ────────────────────────────────────────────

/**
 * The criteria that make up a deal's score.
 *
 * Weights are shown because a checklist without them hides which answers
 * actually matter — "do we know how they buy" is worth twice an OEM
 * relationship, and somebody filling this in should be able to see that.
 */
const CRITERIA: { key: string; label: string; weight: number }[] = [
  { key: "knows_procurement",   label: "We know how they buy", weight: 10 },
  { key: "compliance_pressure", label: "Under compliance pressure", weight: 15 },
  { key: "prior_deal_closed",   label: "We have closed a deal with them before", weight: 10 },
  { key: "deal_registered",     label: "Deal registered with the OEM", weight: 5 },
  { key: "oem_pressure",        label: "OEM is pushing it", weight: 5 },
  { key: "alignment_with_customer",       label: "Aligned with the customer", weight: 0 },
  { key: "alignment_with_oem_financials", label: "Aligned with OEM financials", weight: 0 },
  { key: "alignment_with_oem_solution",   label: "Aligned with the OEM solution", weight: 0 },
  { key: "alignment_with_oem_support",    label: "Aligned with OEM support", weight: 0 },
  { key: "budget_confirmed_by_customer",  label: "Budget confirmed by the customer", weight: 0 },
];

/**
 * The conversion form.
 *
 * The typed fields and the checklist are kept apart because they are bound
 * differently — a single loosely-typed bag would let a boolean reach a text
 * input, which the compiler is right to object to.
 */
const convert = ref({
  name: "",
  amount: null as number | null,
  expected_close_date: "",
  account_weight: "category_1",
  opportunity_type: "new_business",
  gap_analysis: "",
});

const criteria = ref<Record<string, boolean>>(
  Object.fromEntries(CRITERIA.map((c) => [c.key, false])),
);

/** Shown live, so the effect of an answer is visible while answering. */
const projectedScore = computed(() => {
  let score = 0;
  const weights: Record<string, number> = { category_1: 15, category_2: 10, category_3: 5 };
  score += weights[convert.value.account_weight] ?? 0;

  if (convert.value.expected_close_date) {
    const close = new Date(convert.value.expected_close_date);
    const now   = new Date();
    const out = (Math.ceil((close.getMonth() + 1) / 3) - Math.ceil((now.getMonth() + 1) / 3))
      + (close.getFullYear() - now.getFullYear()) * 4;
    score += ({ 0: 15, 1: 10, 2: 5 } as Record<number, number>)[out] ?? 0;
  }

  if (criteria.value.knows_procurement)   score += 10;
  if (Number(convert.value.amount) > 0)   score += 5;
  if (criteria.value.deal_registered)     score += 5;
  if (criteria.value.compliance_pressure) score += 15;
  if (criteria.value.oem_pressure)        score += 5;
  if (criteria.value.prior_deal_closed)   score += 10;

  return score;
});

const scoreBand = computed(() =>
  projectedScore.value >= 65 ? "strong"
  : projectedScore.value >= 50 ? "promising"
  : projectedScore.value >= 30 ? "developing" : "weak");

async function convertLead() {
  saving.value = true;
  error.value = null;
  try {
    const res = await api<{ data: { id: number } }>("POST", `${base.value}/convert`, {
      org: org(),
      body: {
        ...convert.value,
        ...criteria.value,
        name: convert.value.name || lead.value?.name,
        account_id: lead.value?.account?.id,
        expected_close_date: convert.value.expected_close_date || null,
        gap_analysis: convert.value.gap_analysis || null,
      },
    });
    router.push({ name: "opportunity", params: { id: res.data.id } });
  } catch (e) { fail(e, "Could not convert this lead."); }
  finally { saving.value = false; }
}

// ── Formatting ──────────────────────────────────────────────────────────────

const date = (v: string | null) =>
  v ? new Date(v).toLocaleDateString(undefined, { day: "2-digit", month: "short", year: "numeric" }) : "—";

const titleCase = (v: string | null) =>
  v ? v.replace(/_/g, " ").replace(/\b\w/g, (m) => m.toUpperCase()) : "—";

const statusTone = (s: string) =>
  s === "converted" || s === "qualified" ? "ok" : s === "lost" ? "danger" : s === "cold" ? "neutral" : "warn";

onMounted(load);
</script>

<template>
  <div class="ld-wrap">
    <nav class="ld-crumbs">
      <button class="ld-crumb" @click="router.push({ name: 'accounts' })">Accounts</button>
      <span class="ld-sep">›</span>
      <button v-if="lead?.account" class="ld-crumb"
              @click="router.push({ name: 'account', params: { id: lead.account.id } })">
        {{ lead.account.name }}
      </button>
      <span v-if="lead?.account" class="ld-sep">›</span>
      <span class="ld-current">{{ lead?.reference ?? "Lead" }}</span>
    </nav>

    <div v-if="loading" class="ld-loading">Loading…</div>

    <template v-else-if="lead">
      <header class="ld-head">
        <div>
          <h1 class="ld-title">{{ lead.name }}</h1>
          <p class="ld-byline">
            Raised {{ date(lead.created_at) }}
            <template v-if="lead.owner?.name"> · owned by {{ lead.owner.name }}</template>
          </p>
        </div>
        <button class="btn-ghost" @click="editing = !editing">{{ editing ? "Cancel" : "Edit" }}</button>
      </header>

      <div v-if="error" class="alert-error">{{ error }}</div>
      <div v-if="notice" class="alert-ok">{{ notice }}</div>

      <div v-if="lead.opportunity" class="ld-banner">
        This lead became
        <button class="link-btn" @click="router.push({ name: 'opportunity', params: { id: lead.opportunity.id } })">
          {{ lead.opportunity.reference }} — {{ lead.opportunity.name }}
        </button>
      </div>

      <div class="ld-facts-row">
        <dl class="ld-facts">
          <div><dt>Deadline</dt><dd>{{ date(lead.deadline) }}</dd></div>
          <div><dt>Status</dt><dd><span :class="['pill', `pill-${statusTone(lead.status)}`]">{{ titleCase(lead.status) }}</span></dd></div>
          <div><dt>Priority</dt><dd :class="lead.priority === 'high' ? 'ld-high' : ''">{{ titleCase(lead.priority) }}</dd></div>
          <div><dt>Source</dt><dd>{{ lead.source ?? "—" }}</dd></div>
        </dl>
        <dl class="ld-facts">
          <div><dt>Company</dt>
            <dd>
              <button v-if="lead.account" class="link-btn"
                      @click="router.push({ name: 'account', params: { id: lead.account.id } })">
                {{ lead.account.name }}
              </button>
              <span v-else class="ld-muted">Not yet on file</span>
            </dd></div>
          <div><dt>Website</dt>
            <dd><a v-if="lead.website" :href="lead.website" class="link-btn" target="_blank" rel="noopener">{{ lead.website }}</a>
              <span v-else>—</span></dd></div>
          <div><dt>Lead Owner</dt><dd>{{ lead.owner?.name ?? "—" }}</dd></div>
          <div><dt>Research Team</dt><dd>{{ lead.team?.length ?? 0 }} assigned</dd></div>
        </dl>
      </div>

      <nav class="ld-tabs" role="tablist">
        <button v-for="t in tabs" :key="t.key" role="tab" :aria-selected="tab === t.key"
                :class="['ld-tab', { active: tab === t.key }]" @click="tab = t.key">{{ t.label }}</button>
      </nav>

      <!-- ── Overview ────────────────────────────────────────────────── -->
      <section v-if="tab === 'overview'" class="ld-panel">
        <template v-if="!editing">
          <div class="ld-card">
            <h3 class="ld-card-label">Problem Statement</h3>
            <p v-if="lead.problem_statement" class="ld-prose">{{ lead.problem_statement }}</p>
            <p v-else class="ld-muted">Not written up yet.</p>
          </div>

          <div class="ld-card">
            <h3 class="ld-card-label">Proposed Solution Scope</h3>
            <p v-if="lead.proposed_solution" class="ld-prose">{{ lead.proposed_solution }}</p>
            <p v-else class="ld-muted">Not written up yet.</p>
          </div>
        </template>

        <div v-else class="ld-card">
          <div class="ld-grid">
            <label class="ld-field ld-span"><span>Lead name</span>
              <input v-model="form.name" class="control" /></label>

            <label class="ld-field"><span>Status</span>
              <select v-model="form.status" class="control">
                <option value="new">New</option>
                <option value="contacted">Contacted</option>
                <option value="qualified">Qualified</option>
                <option value="cold">Cold</option>
                <option value="lost">Lost</option>
              </select></label>

            <label class="ld-field"><span>Priority</span>
              <select v-model="form.priority" class="control">
                <option value="low">Low</option>
                <option value="medium">Medium</option>
                <option value="high">High</option>
              </select></label>

            <label class="ld-field"><span>Source</span>
              <input v-model="form.source" class="control" placeholder="Referral, website, LinkedIn" /></label>

            <label class="ld-field"><span>Deadline</span>
              <input v-model="form.deadline" class="control" type="date" /></label>

            <label class="ld-field ld-span"><span>Website</span>
              <input v-model="form.website" class="control" placeholder="https://www.kcbgroup.com" /></label>

            <div class="ld-field ld-span"><span>Lead owner</span>
              <EmployeePicker v-model="form.owner_id" placeholder="Who is working this?" /></div>

            <label class="ld-field ld-span"><span>Problem statement</span>
              <textarea v-model="form.problem_statement" class="control" rows="8"></textarea></label>

            <label class="ld-field ld-span"><span>Proposed solution scope</span>
              <textarea v-model="form.proposed_solution" class="control" rows="6"></textarea></label>
          </div>

          <div class="ld-actions">
            <button class="btn-primary" :disabled="saving" @click="save">
              {{ saving ? "Saving…" : "Save changes" }}
            </button>
          </div>
        </div>
      </section>

      <!-- ── Company ─────────────────────────────────────────────────── -->
      <section v-else-if="tab === 'company'" class="ld-panel">
        <div v-if="lead.account" class="ld-card">
          <h3 class="ld-card-label">Company</h3>
          <p class="ld-strong">{{ lead.account.name }}</p>
          <p class="ld-muted">
            Contacts, contracts and everything else about them live on the customer record.
          </p>
          <button class="btn-ghost" @click="router.push({ name: 'account', params: { id: lead.account.id } })">
            Open customer
          </button>
        </div>
        <div v-else class="ld-empty">
          <h3 class="ld-empty-title">No customer on file</h3>
          <p class="ld-empty-sub">
            This lead is not attached to a customer yet. One is needed before it can
            become a deal — revenue has to be against somebody.
          </p>
        </div>
      </section>

      <!-- ── Qualification ───────────────────────────────────────────── -->
      <section v-else-if="tab === 'qualification'" class="ld-panel">
        <div v-if="lead.opportunity" class="ld-card">
          <h3 class="ld-card-label">Already qualified</h3>
          <p class="ld-muted">
            This lead became {{ lead.opportunity.reference }}. Its qualification is kept
            on the opportunity, where the score is read from.
          </p>
          <button class="btn-ghost" @click="router.push({ name: 'opportunity', params: { id: lead.opportunity.id } })">
            Open the opportunity
          </button>
        </div>

        <template v-else>
          <div class="ld-card">
            <div class="ld-score-head">
              <div>
                <h3 class="ld-card-label">Qualification</h3>
                <p class="ld-muted">
                  These answers are the deal's score. Converting the lead carries them
                  onto the opportunity rather than asking again.
                </p>
              </div>
              <div :class="['ld-score', `score-${scoreBand}`]">
                <span class="ld-score-value">{{ projectedScore }}</span>
                <span class="ld-score-max">/ 80</span>
                <span class="ld-score-band">{{ scoreBand }}</span>
              </div>
            </div>

            <div class="ld-grid">
              <label class="ld-field"><span>Deal name</span>
                <input v-model="convert.name" class="control" :placeholder="lead.name" /></label>

              <label class="ld-field"><span>Value</span>
                <input v-model.number="convert.amount" class="control" type="number" min="0" step="1000" /></label>

              <label class="ld-field"><span>Account weight</span>
                <select v-model="convert.account_weight" class="control">
                  <option value="category_1">Category 1 (15)</option>
                  <option value="category_2">Category 2 (10)</option>
                  <option value="category_3">Category 3 (5)</option>
                </select></label>

              <label class="ld-field"><span>Expected close</span>
                <input v-model="convert.expected_close_date" class="control" type="date" /></label>

              <label class="ld-field"><span>Type</span>
                <select v-model="convert.opportunity_type" class="control">
                  <option value="new_business">New business</option>
                  <option value="renewal">Renewal</option>
                  <option value="upgrade">Upgrade</option>
                </select></label>
            </div>

            <ul class="ld-criteria">
              <li v-for="c in CRITERIA" :key="c.key">
                <label class="ld-check">
                  <input v-model="criteria[c.key]" type="checkbox" />
                  <span>{{ c.label }}</span>
                  <span v-if="c.weight" class="ld-weight">+{{ c.weight }}</span>
                </label>
              </li>
            </ul>

            <label class="ld-field"><span>Gap analysis</span>
              <textarea v-model="convert.gap_analysis" class="control" rows="3"
                        placeholder="What is missing before this can be won?"></textarea></label>

            <div class="ld-actions">
              <button class="btn-primary" :disabled="saving || !lead.account" @click="convertLead">
                {{ saving ? "Converting…" : "Convert to opportunity" }}
              </button>
            </div>
            <p v-if="!lead.account" class="ld-hint">
              Attach a customer first — a deal has to be with somebody.
            </p>
          </div>
        </template>
      </section>

      <!-- ── Research team ───────────────────────────────────────────── -->
      <section v-else class="ld-panel">
        <div class="ld-add-row">
          <div class="ld-add-picker">
            <EmployeePicker v-model="newMember.employee_id" placeholder="Add somebody…" />
          </div>
          <input v-model="newMember.role" class="control ld-add-role" placeholder="What they are looking at (optional)" />
          <button class="btn-primary" :disabled="saving || !newMember.employee_id" @click="addMember">Add</button>
        </div>

        <p v-if="!lead.team?.length" class="ld-muted">Nobody researching this yet.</p>

        <div v-else class="ld-card ld-table-card">
          <table class="ld-table">
            <thead><tr><th>Person</th><th>Looking at</th><th></th></tr></thead>
            <tbody>
              <tr v-for="m in lead.team" :key="m.id">
                <td class="ld-strong-cell">{{ m.employee.name ?? "—" }}</td>
                <td>{{ m.role ?? "—" }}</td>
                <td><button class="link-btn link-danger" @click="removeMember(m)">Remove</button></td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </div>
</template>

<style scoped>
@reference "../../theme.css";

.ld-wrap { padding: var(--space-6); max-width: 72rem; }

.ld-crumbs { @apply flex flex-wrap items-center; gap: var(--space-2); font-size: var(--text-sm-size); margin-bottom: var(--space-3); }
.ld-crumb { @apply cursor-pointer border-none bg-transparent p-0; font-family: inherit; font-size: inherit; color: var(--color-text-muted); }
.ld-crumb:hover { color: var(--color-text); }
.ld-sep { color: var(--color-text-light); }
.ld-current { color: var(--color-text); }

.ld-loading { padding: var(--space-6); color: var(--color-text-muted); }

.ld-head { @apply flex items-start justify-between; gap: var(--space-4); margin-bottom: var(--space-4); }
.ld-title { font-size: var(--text-xl-size); font-weight: 600; color: var(--color-text); margin: 0; }
.ld-byline { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: var(--space-1) 0 0; }

.ld-banner {
  padding: var(--space-3) var(--space-4); border-radius: var(--radius-md);
  background: var(--color-success-subtle); color: var(--color-success-text);
  font-size: var(--text-sm-size); margin-bottom: var(--space-4);
}

.ld-facts-row { @apply grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-4) var(--space-8); margin-bottom: var(--space-5); }
@media (max-width: 48rem) { .ld-facts-row { grid-template-columns: 1fr; } }
.ld-facts { margin: 0; }
.ld-facts > div { @apply flex items-baseline; gap: var(--space-3); padding: var(--space-1) 0; }
.ld-facts dt { font-size: var(--text-sm-size); color: var(--color-text-muted); min-width: 7.5rem; }
.ld-facts dd { font-size: var(--text-sm-size); color: var(--color-text); margin: 0; }
.ld-high { color: var(--color-danger-text); font-weight: 500; }

.ld-tabs { @apply flex flex-wrap gap-1 overflow-x-auto; border-bottom: 1px solid var(--color-border); }
.ld-tab {
  @apply cursor-pointer border-none bg-transparent;
  padding: var(--space-2) var(--space-3);
  font-family: inherit; font-size: var(--text-sm-size); color: var(--color-text-muted);
  border-bottom: 2px solid transparent; margin-bottom: -1px; white-space: nowrap;
}
.ld-tab:hover  { color: var(--color-text); }
.ld-tab.active { color: var(--color-brand-on-surface); border-bottom-color: var(--color-brand-base); font-weight: 500; }

.ld-panel { @apply flex flex-col; padding: var(--space-5) 0; gap: var(--space-4); }

.ld-card {
  @apply flex flex-col; gap: var(--space-3);
  padding: var(--space-5);
  background: var(--color-surface-raised);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
}
.ld-table-card { padding: 0; overflow-x: auto; }
.ld-card-label {
  font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-muted);
  text-transform: uppercase; letter-spacing: 0.04em; margin: 0;
}
.ld-prose { font-size: var(--text-sm-size); color: var(--color-text); margin: 0; white-space: pre-wrap; line-height: 1.6; }
.ld-strong { font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); margin: 0; }
.ld-muted { font-size: var(--text-sm-size); color: var(--color-text-light); margin: 0; }
.ld-hint { font-size: var(--text-xs-size); color: var(--color-text-light); margin: 0; }

.ld-empty { @apply flex flex-col items-center; padding: var(--space-8) var(--space-6); text-align: center; gap: var(--space-2); }
.ld-empty-title { font-size: var(--text-base-size); font-weight: 600; color: var(--color-text); margin: 0; }
.ld-empty-sub { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: 0; max-width: 52ch; }

.ld-grid { @apply grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-4); }
.ld-span { grid-column: 1 / -1; }
@media (max-width: 40rem) { .ld-grid { grid-template-columns: 1fr; } }

.ld-field { @apply flex flex-col; gap: var(--space-1); }
.ld-field > span { font-size: var(--text-xs-size); color: var(--color-text-muted); }

.control {
  font-family: inherit; font-size: var(--text-sm-size);
  padding: 8px var(--space-3);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-md);
  background: var(--color-surface); color: var(--color-text); width: 100%;
}
.control:focus { @apply outline-none; border-color: var(--color-brand-base); box-shadow: 0 0 0 3px var(--color-brand-subtle); }

.ld-actions { @apply flex justify-end; }

.ld-score-head { @apply flex items-start justify-between; gap: var(--space-4); }
.ld-score { @apply flex shrink-0 items-baseline; gap: var(--space-1); }
.ld-score-value { font-size: var(--text-2xl-size); font-weight: 700; }
.ld-score-max   { font-size: var(--text-sm-size); color: var(--color-text-light); }
.ld-score-band  { font-size: var(--text-xs-size); text-transform: uppercase; letter-spacing: 0.04em; margin-left: var(--space-2); }
.score-strong     { color: var(--color-success-text); }
.score-promising  { color: var(--color-brand-on-surface); }
.score-developing { color: var(--color-warning-text); }
.score-weak       { color: var(--color-text-light); }

.ld-criteria { @apply grid list-none; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-2); margin: 0; padding: 0; }
@media (max-width: 44rem) { .ld-criteria { grid-template-columns: 1fr; } }
.ld-check { @apply flex cursor-pointer items-center; gap: var(--space-2); font-size: var(--text-sm-size); color: var(--color-text); }
.ld-weight { font-size: var(--text-xs-size); color: var(--color-text-light); margin-left: auto; }

.ld-add-row { @apply flex flex-wrap items-start; gap: var(--space-3); }
.ld-add-picker { flex: 1; min-width: 16rem; }
.ld-add-role { max-width: 20rem; }

.ld-table { @apply w-full; border-collapse: collapse; font-size: var(--text-sm-size); }
.ld-table th {
  text-align: left; padding: var(--space-3) var(--space-4);
  font-size: var(--text-xs-size); font-weight: 600; color: var(--color-text-muted);
  border-bottom: 1px solid var(--color-border);
}
.ld-table td { padding: var(--space-3) var(--space-4); border-bottom: 1px solid var(--color-border); color: var(--color-text); }
.ld-table tr:last-child td { border-bottom: none; }
.ld-strong-cell { font-weight: 500; }

.pill { display: inline-block; padding: 2px var(--space-3); border-radius: 999px; font-size: var(--text-xs-size); border: 1px solid transparent; }
.pill-ok      { background: var(--color-success-subtle); color: var(--color-success-text); border-color: color-mix(in oklab, var(--color-success-text) 30%, transparent); }
.pill-warn    { background: var(--color-warning-subtle); color: var(--color-warning-text); border-color: color-mix(in oklab, var(--color-warning-text) 30%, transparent); }
.pill-danger  { background: var(--color-danger-subtle);  color: var(--color-danger-text);  border-color: color-mix(in oklab, var(--color-danger-text) 30%, transparent); }
.pill-neutral { background: var(--color-surface-sunken); color: var(--color-text-muted); border-color: var(--color-border); }

.link-btn { @apply cursor-pointer border-none bg-transparent p-0; font-family: inherit; font-size: var(--text-sm-size); color: var(--color-brand-on-surface); }
.link-btn:hover { @apply underline; }
.link-danger { color: var(--color-danger-text); }

.btn-primary, .btn-ghost {
  @apply inline-flex cursor-pointer items-center justify-center;
  gap: var(--space-2); padding: 8px var(--space-4); border-radius: var(--radius-md);
  font-family: inherit; font-size: var(--text-sm-size); font-weight: 500; white-space: nowrap;
  align-self: flex-start;
}
.btn-primary { @apply border-none; background: var(--color-brand-base); color: var(--color-on-brand); }
.btn-ghost   { background: var(--color-surface); color: var(--color-text); border: 1px solid var(--color-border-strong); }
.btn-primary:disabled { @apply cursor-not-allowed opacity-50; }

.alert-error, .alert-ok {
  padding: var(--space-3) var(--space-4); border-radius: var(--radius-md);
  font-size: var(--text-sm-size); margin-bottom: var(--space-4);
}
.alert-error { background: var(--color-danger-subtle);  color: var(--color-danger-text); }
.alert-ok    { background: var(--color-success-subtle); color: var(--color-success-text); }
</style>
