<script setup lang="ts">
import { ref, computed, onMounted, reactive } from "vue";
import { useRouter } from "vue-router";
import { api } from "../lib/api";
import { currentOrgSlug } from "../stores/auth";

const router = useRouter();

// ── Types ─────────────────────────────────────────────────────────────────────
interface BenefitDef {
  id: number;
  name: string;
  benefit_type: string;
  eligibility_rules: string[] | null;
  amount: string | null;
  amount_limit: string | null;
  payable_period: string | null;
  paid_with_salary: boolean;
  requires_approval: boolean;
  requires_reason: boolean;
  max_days: number | null;
  registration_required: boolean;
  mode: string | null;
  location_rates: LocationRate[] | null;
  allowed_memberships: string | null;
  membership_rules: string | null;
  coverage_limit: string | null;
  dependents_required: boolean;
  notes: string | null;
  is_active: boolean;
}

interface LocationRate { location: string; country: string; daily_rate: number }

interface PlanItem {
  benefit_definition_id: number;
  amount_override: string | null;
  limit_override: string | null;
  max_days_override: number | null;
  notes: string | null;
  benefit?: BenefitDef;
}

interface BenefitPlan {
  id: number;
  name: string;
  description: string | null;
  eligibility_rules: string[] | null;
  is_active: boolean;
  items: PlanItem[];
  assignments_count: number;
}

interface Assignment {
  id: number;
  employee_id: number;
  plan_id: number;
  effective_date: string;
  end_date: string | null;
  notes: string | null;
  is_active: boolean;
  employee?: Employee;
  plan?: BenefitPlan;
  assigned_by_employee?: Employee;
}

interface Dependent {
  id: number;
  employee_id: number;
  full_name: string;
  relationship: string;
  date_of_birth: string | null;
  id_number: string | null;
  is_active: boolean;
  employee?: Employee;
}

interface Approval {
  id: number;
  employee_id: number;
  benefit_definition_id: number;
  requested_date: string;
  end_date: string | null;
  reason: string | null;
  days_requested: number;
  supervisor_id: number | null;
  status: string;
  action_date: string | null;
  action_notes: string | null;
  employee?: Employee;
  benefit?: BenefitDef;
  supervisor?: Employee;
}

interface Employee { id: number; first_name: string; last_name: string; employee_number: string }

// ── Constants ─────────────────────────────────────────────────────────────────
const BENEFIT_TYPES = [
  { value: "bonus",                label: "Bonus" },
  { value: "fuel_allowance",       label: "Fuel Allowance" },
  { value: "taxi_allowance",       label: "Taxi Allowance" },
  { value: "airtime_allowance",    label: "Airtime Allowance" },
  { value: "lunch_allowance",      label: "Lunch Allowance" },
  { value: "per_diem",             label: "Per Diem" },
  { value: "membership_allowance", label: "Membership Allowance" },
  { value: "welfare",              label: "Welfare" },
  { value: "medical_insurance",    label: "Medical Insurance" },
  { value: "work_from_home",       label: "Work From Home" },
  { value: "compensatory_days_off",label: "Compensatory Days Off" },
];

const PAYABLE_PERIODS = [
  { value: "monthly",    label: "Monthly" },
  { value: "quarterly",  label: "Quarterly" },
  { value: "annually",   label: "Annually" },
  { value: "per_trip",   label: "Per Trip" },
  { value: "per_event",  label: "Per Event" },
];

function typeLabel(val: string): string {
  return BENEFIT_TYPES.find(t => t.value === val)?.label ?? val;
}

// ── Tab state ─────────────────────────────────────────────────────────────────
const tab = ref<"benefits" | "plans" | "assignments" | "dependents" | "approvals">("benefits");

// ── Data refs ─────────────────────────────────────────────────────────────────
const definitions   = ref<BenefitDef[]>([]);
const plans         = ref<BenefitPlan[]>([]);
const assignments   = ref<Assignment[]>([]);
const dependents    = ref<Dependent[]>([]);
const approvals     = ref<Approval[]>([]);
const employees     = ref<Employee[]>([]);

const loading  = ref(false);
const saving   = ref(false);
const error    = ref("");
const success  = ref("");

function flash(msg: string) { success.value = msg; setTimeout(() => (success.value = ""), 3000); }
function org(): string | null { return currentOrgSlug.value; }

// ── Load data ─────────────────────────────────────────────────────────────────
async function loadAll() {
  if (!org()) return;
  loading.value = true;
  error.value   = "";
  try {
    const [d, p, a, dep, appr, emp] = await Promise.all([
      api<{ data: BenefitDef[] }>("GET", "/api/v1/benefits/definitions", { org: org() }),
      api<{ data: BenefitPlan[] }>("GET", "/api/v1/benefits/plans",       { org: org() }),
      api<{ data: Assignment[] }>("GET", "/api/v1/benefits/assignments",  { org: org() }),
      api<{ data: Dependent[] }>("GET", "/api/v1/benefits/dependents",   { org: org() }),
      api<{ data: Approval[] }>("GET",  "/api/v1/benefits/approvals",    { org: org() }),
      api<{ data: Employee[] }>("GET",  "/api/v1/employees",             { org: org() }),
    ]);
    definitions.value = d.data;
    plans.value       = p.data;
    assignments.value = a.data;
    dependents.value  = dep.data;
    approvals.value   = appr.data;
    employees.value   = emp.data;
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : "Failed to load data";
  } finally {
    loading.value = false;
  }
}

onMounted(loadAll);

// ─────────────────────────────────────────────────────────────────────────────
// TAB 1: BENEFIT DEFINITIONS
// ─────────────────────────────────────────────────────────────────────────────
const defModal  = ref(false);
const defEdit   = ref<BenefitDef | null>(null);

const defForm = reactive({
  name: "", benefit_type: "", is_active: true, notes: "",
  // monetary
  amount: "", amount_limit: "", payable_period: "", paid_with_salary: false,
  // limits
  max_days: "" as number | "",
  // toggles
  requires_approval: false, requires_reason: false,
  registration_required: false, dependents_required: false,
  // mode
  mode: "",
  // medical
  coverage_limit: "",
  // memberships
  allowed_memberships: "", membership_rules: "",
  // location rates (per diem)
  location_rates: [] as LocationRate[],
  // eligibility
  eligibility_rules: [] as string[],
  eligibilityInput: "",
});

// Which fields show per type
const showAmount         = computed(() => ["bonus","fuel_allowance","airtime_allowance"].includes(defForm.benefit_type));
const showAmountLimit    = computed(() => ["taxi_allowance","lunch_allowance","per_diem","membership_allowance"].includes(defForm.benefit_type));
const showPayablePeriod  = computed(() => ["bonus","fuel_allowance","airtime_allowance","lunch_allowance"].includes(defForm.benefit_type));
const showPaidWithSalary = computed(() => ["bonus","fuel_allowance","airtime_allowance"].includes(defForm.benefit_type));
const showMode           = computed(() => ["taxi_allowance","lunch_allowance"].includes(defForm.benefit_type));
const showRegistration   = computed(() => defForm.benefit_type === "taxi_allowance");
const showLocationRates  = computed(() => defForm.benefit_type === "per_diem");
const showMemberships    = computed(() => defForm.benefit_type === "membership_allowance");
const showMembershipRules= computed(() => defForm.benefit_type === "welfare");
const showCoverage       = computed(() => defForm.benefit_type === "medical_insurance");
const showDependents     = computed(() => defForm.benefit_type === "medical_insurance");
const showMaxDays        = computed(() => ["work_from_home","compensatory_days_off"].includes(defForm.benefit_type));
const showRequiresApproval = computed(() => ["work_from_home","compensatory_days_off","per_diem"].includes(defForm.benefit_type));
const showRequiresReason   = computed(() => defForm.benefit_type === "work_from_home");

function openDefModal(def?: BenefitDef) {
  defEdit.value = def ?? null;
  Object.assign(defForm, {
    name: def?.name ?? "", benefit_type: def?.benefit_type ?? "", is_active: def?.is_active ?? true,
    notes: def?.notes ?? "", amount: def?.amount ?? "", amount_limit: def?.amount_limit ?? "",
    payable_period: def?.payable_period ?? "", paid_with_salary: def?.paid_with_salary ?? false,
    max_days: def?.max_days ?? "", requires_approval: def?.requires_approval ?? false,
    requires_reason: def?.requires_reason ?? false, registration_required: def?.registration_required ?? false,
    dependents_required: def?.dependents_required ?? false, mode: def?.mode ?? "",
    coverage_limit: def?.coverage_limit ?? "", allowed_memberships: def?.allowed_memberships ?? "",
    membership_rules: def?.membership_rules ?? "",
    location_rates: def?.location_rates ? JSON.parse(JSON.stringify(def.location_rates)) : [],
    eligibility_rules: def?.eligibility_rules ? [...def.eligibility_rules] : [],
    eligibilityInput: "",
  });
  defModal.value = true;
}

function addLocationRate() {
  defForm.location_rates.push({ location: "", country: "", daily_rate: 0 });
}
function removeLocationRate(i: number) { defForm.location_rates.splice(i, 1); }
function addEligibilityRule() {
  const r = defForm.eligibilityInput.trim();
  if (r) { defForm.eligibility_rules.push(r); defForm.eligibilityInput = ""; }
}
function removeEligibilityRule(i: number) { defForm.eligibility_rules.splice(i, 1); }

async function saveDef() {
  saving.value = true; error.value = "";
  try {
    const payload: Record<string, unknown> = {
      name: defForm.name, benefit_type: defForm.benefit_type, is_active: defForm.is_active,
      notes: defForm.notes || null,
      eligibility_rules: defForm.eligibility_rules.length ? defForm.eligibility_rules : null,
    };
    if (showAmount.value)          payload.amount          = defForm.amount || null;
    if (showAmountLimit.value)     payload.amount_limit    = defForm.amount_limit || null;
    if (showPayablePeriod.value)   payload.payable_period  = defForm.payable_period || null;
    if (showPaidWithSalary.value)  payload.paid_with_salary = defForm.paid_with_salary;
    if (showMaxDays.value)         payload.max_days        = defForm.max_days || null;
    if (showRequiresApproval.value)payload.requires_approval = defForm.requires_approval;
    if (showRequiresReason.value)  payload.requires_reason  = defForm.requires_reason;
    if (showMode.value)            payload.mode            = defForm.mode || null;
    if (showRegistration.value)    payload.registration_required = defForm.registration_required;
    if (showLocationRates.value)   payload.location_rates  = defForm.location_rates;
    if (showMemberships.value)     payload.allowed_memberships = defForm.allowed_memberships || null;
    if (showMembershipRules.value) payload.membership_rules = defForm.membership_rules || null;
    if (showCoverage.value)        payload.coverage_limit  = defForm.coverage_limit || null;
    if (showDependents.value)      payload.dependents_required = defForm.dependents_required;

    if (defEdit.value) {
      const updated = await api<BenefitDef>("PUT", `/api/v1/benefits/definitions/${defEdit.value.id}`, { org: org(), body: payload });
      const idx = definitions.value.findIndex(d => d.id === defEdit.value!.id);
      if (idx !== -1) definitions.value[idx] = updated;
    } else {
      const created = await api<BenefitDef>("POST", "/api/v1/benefits/definitions", { org: org(), body: payload });
      definitions.value.push(created);
    }
    defModal.value = false;
    flash("Benefit saved.");
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : "Save failed";
  } finally { saving.value = false; }
}

async function deleteDef(def: BenefitDef) {
  if (!confirm(`Delete "${def.name}"?`)) return;
  await api("DELETE", `/api/v1/benefits/definitions/${def.id}`, { org: org() });
  definitions.value = definitions.value.filter(d => d.id !== def.id);
  flash("Benefit deleted.");
}

// ─────────────────────────────────────────────────────────────────────────────
// TAB 2: BENEFIT PLANS
// ─────────────────────────────────────────────────────────────────────────────
const planModal   = ref(false);
const planEdit    = ref<BenefitPlan | null>(null);
const planExpanded= ref<number | null>(null);

const planForm = reactive({
  name: "", description: "", is_active: true,
  eligibility_rules: [] as string[],
  eligibilityInput: "",
  items: [] as Array<{
    benefit_definition_id: number;
    amount_override: string;
    limit_override: string;
    max_days_override: string;
    notes: string;
  }>,
});

function openPlanModal(plan?: BenefitPlan) {
  planEdit.value = plan ?? null;
  Object.assign(planForm, {
    name: plan?.name ?? "", description: plan?.description ?? "",
    is_active: plan?.is_active ?? true,
    eligibility_rules: plan?.eligibility_rules ? [...plan.eligibility_rules] : [],
    eligibilityInput: "",
    items: plan?.items?.map(i => ({
      benefit_definition_id: i.benefit_definition_id,
      amount_override: i.amount_override ?? "",
      limit_override: i.limit_override ?? "",
      max_days_override: i.max_days_override?.toString() ?? "",
      notes: i.notes ?? "",
    })) ?? [],
  });
  planModal.value = true;
}

function addPlanEligibility() {
  const r = planForm.eligibilityInput.trim();
  if (r) { planForm.eligibility_rules.push(r); planForm.eligibilityInput = ""; }
}
function removePlanEligibility(i: number) { planForm.eligibility_rules.splice(i, 1); }

function toggleBenefitInPlan(defId: number) {
  const idx = planForm.items.findIndex(i => i.benefit_definition_id === defId);
  if (idx === -1) {
    planForm.items.push({ benefit_definition_id: defId, amount_override: "", limit_override: "", max_days_override: "", notes: "" });
  } else {
    planForm.items.splice(idx, 1);
  }
}

function isPlanBenefitSelected(defId: number) {
  return planForm.items.some(i => i.benefit_definition_id === defId);
}

function getPlanItemOverride(defId: number) {
  return planForm.items.find(i => i.benefit_definition_id === defId);
}

async function savePlan() {
  saving.value = true; error.value = "";
  try {
    const payload = {
      name: planForm.name, description: planForm.description || null, is_active: planForm.is_active,
      eligibility_rules: planForm.eligibility_rules.length ? planForm.eligibility_rules : null,
      items: planForm.items.map(i => ({
        benefit_definition_id: i.benefit_definition_id,
        amount_override: i.amount_override || null,
        limit_override: i.limit_override || null,
        max_days_override: i.max_days_override ? parseInt(i.max_days_override) : null,
        notes: i.notes || null,
      })),
    };
    if (planEdit.value) {
      const updated = await api<BenefitPlan>("PUT", `/api/v1/benefits/plans/${planEdit.value.id}`, { org: org(), body: payload });
      const idx = plans.value.findIndex(p => p.id === planEdit.value!.id);
      if (idx !== -1) plans.value[idx] = updated;
    } else {
      const created = await api<BenefitPlan>("POST", "/api/v1/benefits/plans", { org: org(), body: payload });
      plans.value.push(created);
    }
    planModal.value = false;
    flash("Plan saved.");
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : "Save failed";
  } finally { saving.value = false; }
}

async function deletePlan(plan: BenefitPlan) {
  if (!confirm(`Delete plan "${plan.name}"?`)) return;
  await api("DELETE", `/api/v1/benefits/plans/${plan.id}`, { org: org() });
  plans.value = plans.value.filter(p => p.id !== plan.id);
  flash("Plan deleted.");
}

// ─────────────────────────────────────────────────────────────────────────────
// TAB 3: ASSIGNMENTS
// ─────────────────────────────────────────────────────────────────────────────
const assignModal = ref(false);
const assignForm  = reactive({ employee_id: "", plan_id: "", effective_date: "", end_date: "", notes: "" });

function openAssignModal() {
  Object.assign(assignForm, { employee_id: "", plan_id: "", effective_date: "", end_date: "", notes: "" });
  assignModal.value = true;
}

async function saveAssignment() {
  saving.value = true; error.value = "";
  try {
    const created = await api<Assignment>("POST", "/api/v1/benefits/assignments", {
      org: org(),
      body: {
        employee_id: Number(assignForm.employee_id), plan_id: Number(assignForm.plan_id),
        effective_date: assignForm.effective_date, end_date: assignForm.end_date || null,
        notes: assignForm.notes || null,
      },
    });
    assignments.value.unshift(created);
    assignModal.value = false;
    flash("Plan assigned.");
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : "Save failed";
  } finally { saving.value = false; }
}

async function revokeAssignment(a: Assignment) {
  if (!confirm("Revoke this plan assignment?")) return;
  await api("DELETE", `/api/v1/benefits/assignments/${a.id}`, { org: org() });
  assignments.value = assignments.value.filter(x => x.id !== a.id);
  flash("Assignment revoked.");
}

function empName(e?: Employee) { return e ? `${e.first_name} ${e.last_name}` : "—"; }

// ─────────────────────────────────────────────────────────────────────────────
// TAB 4: DEPENDENTS (Medical Insurance)
// ─────────────────────────────────────────────────────────────────────────────
const depModal  = ref(false);
const depForm   = reactive({ employee_id: "", full_name: "", relationship: "spouse", date_of_birth: "", id_number: "" });
const depFilter = ref("");

const filteredDependents = computed(() => {
  const q = depFilter.value.toLowerCase();
  return dependents.value.filter(d =>
    !q || d.full_name.toLowerCase().includes(q) || empName(d.employee).toLowerCase().includes(q)
  );
});

function openDepModal() {
  Object.assign(depForm, { employee_id: "", full_name: "", relationship: "spouse", date_of_birth: "", id_number: "" });
  depModal.value = true;
}

async function saveDependent() {
  saving.value = true; error.value = "";
  try {
    const created = await api<Dependent>("POST", "/api/v1/benefits/dependents", {
      org: org(),
      body: {
        employee_id: Number(depForm.employee_id), full_name: depForm.full_name,
        relationship: depForm.relationship, date_of_birth: depForm.date_of_birth || null,
        id_number: depForm.id_number || null,
      },
    });
    dependents.value.push(created);
    depModal.value = false;
    flash("Dependent registered.");
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : "Save failed";
  } finally { saving.value = false; }
}

async function removeDependent(d: Dependent) {
  if (!confirm(`Remove dependent "${d.full_name}"?`)) return;
  await api("DELETE", `/api/v1/benefits/dependents/${d.id}`, { org: org() });
  dependents.value = dependents.value.filter(x => x.id !== d.id);
  flash("Dependent removed.");
}

// ─────────────────────────────────────────────────────────────────────────────
// TAB 5: APPROVALS (WFH / Compensatory Days)
// ─────────────────────────────────────────────────────────────────────────────
const approvalModal = ref(false);
const approvalFilter= ref<"all" | "pending" | "approved" | "rejected">("pending");
const approvalForm  = reactive({
  employee_id: "", benefit_definition_id: "", requested_date: "", end_date: "",
  reason: "", days_requested: 1, supervisor_id: "",
});
const actionNotes = ref("");

const filteredApprovals = computed(() => {
  const list = approvals.value;
  if (approvalFilter.value === "all") return list;
  return list.filter(a => a.status === approvalFilter.value);
});

// Only WFH and comp days for approval workflow
const approvableDefs = computed(() =>
  definitions.value.filter(d => ["work_from_home", "compensatory_days_off"].includes(d.benefit_type))
);

function openApprovalModal() {
  Object.assign(approvalForm, { employee_id: "", benefit_definition_id: "", requested_date: "", end_date: "", reason: "", days_requested: 1, supervisor_id: "" });
  approvalModal.value = true;
}

async function saveApproval() {
  saving.value = true; error.value = "";
  try {
    const created = await api<Approval>("POST", "/api/v1/benefits/approvals", {
      org: org(),
      body: {
        employee_id: Number(approvalForm.employee_id),
        benefit_definition_id: Number(approvalForm.benefit_definition_id),
        requested_date: approvalForm.requested_date,
        end_date: approvalForm.end_date || null,
        reason: approvalForm.reason || null,
        days_requested: Number(approvalForm.days_requested),
        supervisor_id: approvalForm.supervisor_id ? Number(approvalForm.supervisor_id) : null,
      },
    });
    approvals.value.unshift(created);
    approvalModal.value = false;
    flash("Approval request submitted.");
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : "Save failed";
  } finally { saving.value = false; }
}

async function actOnApproval(a: Approval, status: "approved" | "rejected") {
  saving.value = true; error.value = "";
  try {
    const updated = await api<Approval>("PATCH", `/api/v1/benefits/approvals/${a.id}/action`, {
      org: org(),
      body: { status, action_notes: actionNotes.value || null },
    });
    const idx = approvals.value.findIndex(x => x.id === a.id);
    if (idx !== -1) approvals.value[idx] = { ...approvals.value[idx], ...updated };
    actionNotes.value = "";
    flash(`Request ${status}.`);
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : "Action failed";
  } finally { saving.value = false; }
}

function statusBadge(s: string) {
  return s === "approved" ? "badge-success" : s === "rejected" ? "badge-error" : "badge-warning";
}
</script>

<template>
  <div class="page-wrap">
    <div class="page-header">
      <div>
        <button class="btn btn-ghost btn-sm" @click="router.push('/org')" style="display:flex;align-items:center;gap:4px;font-size:13px;margin-bottom:8px">
          <svg viewBox="0 0 20 20" fill="currentColor" style="width:14px;height:14px"><path fill-rule="evenodd" d="M9.707 16.707a1 1 0 01-1.414 0l-6-6a1 1 0 010-1.414l6-6a1 1 0 011.414 1.414L5.414 9H17a1 1 0 110 2H5.414l4.293 4.293a1 1 0 010 1.414z" clip-rule="evenodd"/></svg>
          Organisation
        </button>
        <h1 class="page-title">Benefit Plans</h1>
        <p class="page-sub">Define benefits, build plans, assign to employees and manage approvals.</p>
      </div>
    </div>

    <div v-if="error" class="alert alert-error">{{ error }}</div>
    <div v-if="success" class="alert alert-success">{{ success }}</div>

    <!-- Sub-tabs -->
    <div class="tabs" style="margin-bottom:20px">
      <button :class="['tab', { active: tab === 'benefits' }]"    @click="tab = 'benefits'">Benefits</button>
      <button :class="['tab', { active: tab === 'plans' }]"       @click="tab = 'plans'">Plans</button>
      <button :class="['tab', { active: tab === 'assignments' }]"  @click="tab = 'assignments'">Assignments</button>
      <button :class="['tab', { active: tab === 'dependents' }]"  @click="tab = 'dependents'">Dependents</button>
      <button :class="['tab', { active: tab === 'approvals' }]"   @click="tab = 'approvals'">Approvals</button>
    </div>

    <div v-if="loading" class="bp-loading">Loading…</div>

    <!-- ════════════════════════════════════════════════════════════
         TAB 1 — BENEFIT DEFINITIONS
    ════════════════════════════════════════════════════════════ -->
    <template v-if="tab === 'benefits'">
      <div class="card">
        <div class="bp-card-header">
          <h2 class="card-title">Benefit Definitions</h2>
          <button class="btn btn-primary btn-sm" @click="openDefModal()">+ Add Benefit</button>
        </div>

        <div v-if="!definitions.length" class="bp-empty">No benefits configured yet.</div>
        <div v-else class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th>Name</th><th>Type</th><th>Amount / Limit</th>
                <th>Mode</th><th>Approval</th><th>Status</th><th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="def in definitions" :key="def.id">
                <td class="fw-600">{{ def.name }}</td>
                <td><span class="bp-type-chip">{{ typeLabel(def.benefit_type) }}</span></td>
                <td>
                  <span v-if="def.amount">KES {{ Number(def.amount).toLocaleString() }}</span>
                  <span v-else-if="def.amount_limit">Limit: KES {{ Number(def.amount_limit).toLocaleString() }}</span>
                  <span v-else-if="def.coverage_limit">Cover: KES {{ Number(def.coverage_limit).toLocaleString() }}</span>
                  <span v-else-if="def.max_days">{{ def.max_days }} days</span>
                  <span v-else class="text-muted">—</span>
                </td>
                <td>{{ def.mode ? def.mode.replace("_", " ") : "—" }}</td>
                <td>
                  <span v-if="def.requires_approval" class="badge badge-warning">Required</span>
                  <span v-else class="text-muted">—</span>
                </td>
                <td>
                  <span :class="['badge', def.is_active ? 'badge-success' : 'badge-neutral']">
                    {{ def.is_active ? "Active" : "Inactive" }}
                  </span>
                </td>
                <td>
                  <div class="row-actions">
                    <button class="btn btn-ghost btn-sm" @click="openDefModal(def)">Edit</button>
                    <button class="btn btn-ghost btn-sm danger" @click="deleteDef(def)">Delete</button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <!-- ════════════════════════════════════════════════════════════
         TAB 2 — BENEFIT PLANS
    ════════════════════════════════════════════════════════════ -->
    <template v-if="tab === 'plans'">
      <div class="card">
        <div class="bp-card-header">
          <h2 class="card-title">Benefit Plans</h2>
          <button class="btn btn-primary btn-sm" @click="openPlanModal()">+ Create Plan</button>
        </div>

        <div v-if="!plans.length" class="bp-empty">No plans created yet.</div>
        <div v-else class="bp-plan-list">
          <div v-for="plan in plans" :key="plan.id" class="bp-plan-card">
            <div class="bp-plan-header" @click="planExpanded = planExpanded === plan.id ? null : plan.id">
              <div>
                <span class="bp-plan-name">{{ plan.name }}</span>
                <span class="text-muted" style="font-size:12px;margin-left:10px">{{ plan.items.length }} benefit(s) · {{ plan.assignments_count }} assignment(s)</span>
              </div>
              <div class="row-actions">
                <span :class="['badge', plan.is_active ? 'badge-success' : 'badge-neutral']">
                  {{ plan.is_active ? "Active" : "Inactive" }}
                </span>
                <button class="btn btn-ghost btn-sm" @click.stop="openPlanModal(plan)">Edit</button>
                <button class="btn btn-ghost btn-sm danger" @click.stop="deletePlan(plan)">Delete</button>
                <span class="bp-chevron">{{ planExpanded === plan.id ? "▲" : "▼" }}</span>
              </div>
            </div>
            <div v-if="planExpanded === plan.id" class="bp-plan-body">
              <p v-if="plan.description" class="bp-plan-desc">{{ plan.description }}</p>
              <div v-if="plan.items.length" class="table-wrap">
                <table class="table">
                  <thead><tr><th>Benefit</th><th>Type</th><th>Amount Override</th><th>Limit Override</th><th>Days Override</th></tr></thead>
                  <tbody>
                    <tr v-for="item in plan.items" :key="item.benefit_definition_id">
                      <td>{{ item.benefit?.name ?? item.benefit_definition_id }}</td>
                      <td><span class="bp-type-chip">{{ typeLabel(item.benefit?.benefit_type ?? "") }}</span></td>
                      <td>{{ item.amount_override ? `KES ${Number(item.amount_override).toLocaleString()}` : "—" }}</td>
                      <td>{{ item.limit_override ? `KES ${Number(item.limit_override).toLocaleString()}` : "—" }}</td>
                      <td>{{ item.max_days_override ?? "—" }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <p v-else class="text-muted" style="font-size:13px">No benefits in this plan.</p>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- ════════════════════════════════════════════════════════════
         TAB 3 — ASSIGNMENTS
    ════════════════════════════════════════════════════════════ -->
    <template v-if="tab === 'assignments'">
      <div class="card">
        <div class="bp-card-header">
          <h2 class="card-title">Plan Assignments</h2>
          <button class="btn btn-primary btn-sm" @click="openAssignModal()">+ Assign Plan</button>
        </div>

        <div v-if="!assignments.length" class="bp-empty">No plans assigned yet.</div>
        <div v-else class="table-wrap">
          <table class="table">
            <thead>
              <tr><th>Employee</th><th>Plan</th><th>Effective</th><th>End Date</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
              <tr v-for="a in assignments" :key="a.id">
                <td>{{ empName(a.employee) }}</td>
                <td class="fw-600">{{ a.plan?.name ?? "—" }}</td>
                <td>{{ a.effective_date }}</td>
                <td>{{ a.end_date ?? "Ongoing" }}</td>
                <td>
                  <span :class="['badge', a.is_active ? 'badge-success' : 'badge-neutral']">
                    {{ a.is_active ? "Active" : "Inactive" }}
                  </span>
                </td>
                <td>
                  <button class="btn btn-ghost btn-sm danger" @click="revokeAssignment(a)">Revoke</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <!-- ════════════════════════════════════════════════════════════
         TAB 4 — DEPENDENTS (Medical Insurance)
    ════════════════════════════════════════════════════════════ -->
    <template v-if="tab === 'dependents'">
      <div class="card">
        <div class="bp-card-header">
          <h2 class="card-title">Medical Insurance Dependents</h2>
          <button class="btn btn-primary btn-sm" @click="openDepModal()">+ Register Dependent</button>
        </div>
        <div class="bp-filter-row">
          <input v-model="depFilter" class="input" placeholder="Search by name or employee…" style="max-width:280px" />
        </div>

        <div v-if="!filteredDependents.length" class="bp-empty">No dependents registered.</div>
        <div v-else class="table-wrap">
          <table class="table">
            <thead>
              <tr><th>Employee</th><th>Dependent</th><th>Relationship</th><th>DOB</th><th>ID Number</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
              <tr v-for="d in filteredDependents" :key="d.id">
                <td>{{ empName(d.employee) }}</td>
                <td class="fw-600">{{ d.full_name }}</td>
                <td class="bp-rel-chip">{{ d.relationship }}</td>
                <td>{{ d.date_of_birth ?? "—" }}</td>
                <td>{{ d.id_number ?? "—" }}</td>
                <td>
                  <span :class="['badge', d.is_active ? 'badge-success' : 'badge-neutral']">
                    {{ d.is_active ? "Active" : "Inactive" }}
                  </span>
                </td>
                <td>
                  <button class="btn btn-ghost btn-sm danger" @click="removeDependent(d)">Remove</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <!-- ════════════════════════════════════════════════════════════
         TAB 5 — APPROVALS (WFH / Compensatory Days)
    ════════════════════════════════════════════════════════════ -->
    <template v-if="tab === 'approvals'">
      <div class="card">
        <div class="bp-card-header">
          <h2 class="card-title">Approvals</h2>
          <button class="btn btn-primary btn-sm" @click="openApprovalModal()">+ New Request</button>
        </div>
        <div class="bp-filter-row">
          <button v-for="f in ['all','pending','approved','rejected']" :key="f"
            :class="['btn-filter', { active: approvalFilter === f }]"
            @click="approvalFilter = f as typeof approvalFilter">
            {{ f.charAt(0).toUpperCase() + f.slice(1) }}
          </button>
        </div>

        <div v-if="!filteredApprovals.length" class="bp-empty">No requests found.</div>
        <div v-else class="table-wrap">
          <table class="table">
            <thead>
              <tr><th>Employee</th><th>Type</th><th>Date</th><th>Days</th><th>Reason</th><th>Supervisor</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
              <tr v-for="a in filteredApprovals" :key="a.id">
                <td>{{ empName(a.employee) }}</td>
                <td><span class="bp-type-chip">{{ typeLabel(a.benefit?.benefit_type ?? "") }}</span></td>
                <td>{{ a.requested_date }}<span v-if="a.end_date"> – {{ a.end_date }}</span></td>
                <td>{{ a.days_requested }}</td>
                <td class="text-muted" style="max-width:160px;white-space:normal">{{ a.reason ?? "—" }}</td>
                <td>{{ empName(a.supervisor) }}</td>
                <td><span :class="['badge', statusBadge(a.status)]">{{ a.status }}</span></td>
                <td>
                  <div v-if="a.status === 'pending'" class="row-actions">
                    <button class="btn btn-ghost btn-sm success" @click="actOnApproval(a, 'approved')">Approve</button>
                    <button class="btn btn-ghost btn-sm danger"  @click="actOnApproval(a, 'rejected')">Reject</button>
                  </div>
                  <span v-else class="text-muted" style="font-size:12px">{{ a.action_date ?? "" }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <!-- ════════════════════════════════════════════════════════════
         MODAL — Benefit Definition
    ════════════════════════════════════════════════════════════ -->
    <div v-if="defModal" class="bp-overlay" @click.self="defModal = false">
      <div class="bp-modal">
        <div class="bp-modal-head">
          <h3>{{ defEdit ? "Edit Benefit" : "Add Benefit" }}</h3>
          <button class="bp-close" @click="defModal = false">×</button>
        </div>
        <div class="bp-modal-body">
          <div class="form-grid-2">
            <div class="form-field">
              <label class="bp-label">Benefit Type <span class="req">*</span></label>
              <select v-model="defForm.benefit_type" class="input">
                <option value="">Select type…</option>
                <option v-for="t in BENEFIT_TYPES" :key="t.value" :value="t.value">{{ t.label }}</option>
              </select>
            </div>
            <div class="form-field">
              <label class="bp-label">Name <span class="req">*</span></label>
              <input v-model="defForm.name" class="input" placeholder="e.g. Annual Bonus" />
            </div>
          </div>

          <!-- Amount / Limit -->
          <div v-if="showAmount || showAmountLimit" class="form-grid-2">
            <div v-if="showAmount" class="form-field">
              <label class="bp-label">Amount (KES)</label>
              <input v-model="defForm.amount" type="number" min="0" class="input" placeholder="0.00" />
            </div>
            <div v-if="showAmountLimit" class="form-field">
              <label class="bp-label">Amount Limit (KES)</label>
              <input v-model="defForm.amount_limit" type="number" min="0" class="input" placeholder="0.00" />
            </div>
          </div>

          <!-- Payable Period + Paid with salary -->
          <div v-if="showPayablePeriod || showPaidWithSalary" class="form-grid-2">
            <div v-if="showPayablePeriod" class="form-field">
              <label class="bp-label">Payable Period</label>
              <select v-model="defForm.payable_period" class="input">
                <option value="">Select…</option>
                <option v-for="p in PAYABLE_PERIODS" :key="p.value" :value="p.value">{{ p.label }}</option>
              </select>
            </div>
            <div v-if="showPaidWithSalary" class="form-field bp-toggle-field">
              <label class="bp-label">Paid with Salary</label>
              <label class="bp-toggle">
                <input type="checkbox" v-model="defForm.paid_with_salary" />
                <span class="bp-slider" />
              </label>
            </div>
          </div>

          <!-- Max Days -->
          <div v-if="showMaxDays" class="form-field">
            <label class="bp-label">Maximum Allowed Days</label>
            <input v-model.number="defForm.max_days" type="number" min="0" class="input" style="max-width:160px" />
          </div>

          <!-- Approval / Reason -->
          <div v-if="showRequiresApproval || showRequiresReason" class="form-grid-2">
            <div v-if="showRequiresApproval" class="form-field bp-toggle-field">
              <label class="bp-label">Requires Supervisor Approval</label>
              <label class="bp-toggle">
                <input type="checkbox" v-model="defForm.requires_approval" />
                <span class="bp-slider" />
              </label>
            </div>
            <div v-if="showRequiresReason" class="form-field bp-toggle-field">
              <label class="bp-label">Reason Required</label>
              <label class="bp-toggle">
                <input type="checkbox" v-model="defForm.requires_reason" />
                <span class="bp-slider" />
              </label>
            </div>
          </div>

          <!-- Mode (Taxi / Lunch) -->
          <div v-if="showMode || showRegistration" class="form-grid-2">
            <div v-if="showMode" class="form-field">
              <label class="bp-label">Mode</label>
              <select v-model="defForm.mode" class="input">
                <option value="">Select…</option>
                <option value="company_issued">Company Issued</option>
                <option value="reimbursement">Reimbursement</option>
                <option value="issued_amount">Issued Amount</option>
              </select>
            </div>
            <div v-if="showRegistration" class="form-field bp-toggle-field">
              <label class="bp-label">Vehicle Registration Required</label>
              <label class="bp-toggle">
                <input type="checkbox" v-model="defForm.registration_required" />
                <span class="bp-slider" />
              </label>
            </div>
          </div>

          <!-- Coverage + Dependents (Medical) -->
          <div v-if="showCoverage || showDependents" class="form-grid-2">
            <div v-if="showCoverage" class="form-field">
              <label class="bp-label">Coverage Limit (KES)</label>
              <input v-model="defForm.coverage_limit" type="number" min="0" class="input" />
            </div>
            <div v-if="showDependents" class="form-field bp-toggle-field">
              <label class="bp-label">Dependents Required</label>
              <label class="bp-toggle">
                <input type="checkbox" v-model="defForm.dependents_required" />
                <span class="bp-slider" />
              </label>
            </div>
          </div>

          <!-- Allowed Memberships -->
          <div v-if="showMemberships" class="form-field">
            <label class="bp-label">Approved Membership Agreements</label>
            <textarea v-model="defForm.allowed_memberships" class="input" rows="2" placeholder="List approved memberships…" />
          </div>

          <!-- Welfare Membership Rules -->
          <div v-if="showMembershipRules" class="form-field">
            <label class="bp-label">Membership Rules</label>
            <textarea v-model="defForm.membership_rules" class="input" rows="2" placeholder="Membership eligibility rules…" />
          </div>

          <!-- Per Diem Location Rates -->
          <div v-if="showLocationRates" class="form-field">
            <label class="bp-label">Location Rates</label>
            <div v-for="(rate, i) in defForm.location_rates" :key="i" class="bp-rate-row">
              <input v-model="rate.location" class="input" placeholder="Location / City" style="flex:2" />
              <input v-model="rate.country" class="input" placeholder="Country" style="flex:1" />
              <input v-model.number="rate.daily_rate" type="number" min="0" class="input" placeholder="Daily Rate (KES)" style="flex:1" />
              <button class="btn btn-ghost btn-sm danger" @click="removeLocationRate(i)">×</button>
            </div>
            <button class="btn btn-secondary btn-sm" style="margin-top:6px" @click="addLocationRate">+ Add Location</button>
          </div>

          <!-- Eligibility Rules -->
          <div class="form-field">
            <label class="bp-label">Eligibility Rules</label>
            <div class="bp-tag-input">
              <input v-model="defForm.eligibilityInput" class="input" placeholder="Add rule and press Enter" @keyup.enter="addEligibilityRule" />
              <button class="btn btn-secondary btn-sm" @click="addEligibilityRule">Add</button>
            </div>
            <div class="bp-tags">
              <span v-for="(r, i) in defForm.eligibility_rules" :key="i" class="bp-tag">
                {{ r }} <button @click="removeEligibilityRule(i)">×</button>
              </span>
            </div>
          </div>

          <!-- Notes -->
          <div class="form-field">
            <label class="bp-label">Notes</label>
            <textarea v-model="defForm.notes" class="input" rows="2" />
          </div>

          <!-- Active toggle -->
          <div class="form-field bp-toggle-field">
            <label class="bp-label">Active</label>
            <label class="bp-toggle">
              <input type="checkbox" v-model="defForm.is_active" />
              <span class="bp-slider" />
            </label>
          </div>
        </div>
        <div class="bp-modal-foot">
          <button class="btn btn-ghost" @click="defModal = false">Cancel</button>
          <button class="btn btn-primary" :disabled="saving" @click="saveDef">
            {{ saving ? "Saving…" : "Save Benefit" }}
          </button>
        </div>
      </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════
         MODAL — Plan Builder
    ════════════════════════════════════════════════════════════ -->
    <div v-if="planModal" class="bp-overlay" @click.self="planModal = false">
      <div class="bp-modal bp-modal-wide">
        <div class="bp-modal-head">
          <h3>{{ planEdit ? "Edit Plan" : "Create Benefit Plan" }}</h3>
          <button class="bp-close" @click="planModal = false">×</button>
        </div>
        <div class="bp-modal-body">
          <div class="form-grid-2">
            <div class="form-field">
              <label class="bp-label">Plan Name <span class="req">*</span></label>
              <input v-model="planForm.name" class="input" placeholder="e.g. Senior Staff Package" />
            </div>
            <div class="form-field bp-toggle-field">
              <label class="bp-label">Active</label>
              <label class="bp-toggle">
                <input type="checkbox" v-model="planForm.is_active" />
                <span class="bp-slider" />
              </label>
            </div>
          </div>
          <div class="form-field">
            <label class="bp-label">Description</label>
            <textarea v-model="planForm.description" class="input" rows="2" />
          </div>

          <!-- Eligibility rules for plan -->
          <div class="form-field">
            <label class="bp-label">Plan Eligibility Rules</label>
            <div class="bp-tag-input">
              <input v-model="planForm.eligibilityInput" class="input" placeholder="Add rule and press Enter" @keyup.enter="addPlanEligibility" />
              <button class="btn btn-secondary btn-sm" @click="addPlanEligibility">Add</button>
            </div>
            <div class="bp-tags">
              <span v-for="(r, i) in planForm.eligibility_rules" :key="i" class="bp-tag">
                {{ r }} <button @click="removePlanEligibility(i)">×</button>
              </span>
            </div>
          </div>

          <!-- Benefit selection -->
          <div class="form-field">
            <label class="bp-label">Select Benefits</label>
            <p class="bp-hint">Click to add or remove. Expand to set per-plan overrides.</p>
            <div v-if="!definitions.length" class="text-muted" style="font-size:13px">No benefits defined yet.</div>
            <div v-else>
              <div v-for="def in definitions" :key="def.id" class="bp-benefit-row">
                <label class="bp-benefit-check">
                  <input type="checkbox" :checked="isPlanBenefitSelected(def.id)" @change="toggleBenefitInPlan(def.id)" />
                  <span class="bp-benefit-info">
                    <span class="fw-600">{{ def.name }}</span>
                    <span class="bp-type-chip" style="margin-left:8px">{{ typeLabel(def.benefit_type) }}</span>
                    <span v-if="def.amount" class="text-muted" style="font-size:12px;margin-left:8px">KES {{ Number(def.amount).toLocaleString() }}</span>
                  </span>
                </label>
                <!-- Overrides row when selected -->
                <div v-if="isPlanBenefitSelected(def.id)" class="bp-override-row">
                  <input v-model="getPlanItemOverride(def.id)!.amount_override" type="number" min="0" class="input" placeholder="Amount override" />
                  <input v-model="getPlanItemOverride(def.id)!.limit_override" type="number" min="0" class="input" placeholder="Limit override" />
                  <input v-model="getPlanItemOverride(def.id)!.max_days_override" type="number" min="0" class="input" placeholder="Max days override" />
                  <input v-model="getPlanItemOverride(def.id)!.notes" class="input" placeholder="Notes" />
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="bp-modal-foot">
          <button class="btn btn-ghost" @click="planModal = false">Cancel</button>
          <button class="btn btn-primary" :disabled="saving" @click="savePlan">
            {{ saving ? "Saving…" : "Save Plan" }}
          </button>
        </div>
      </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════
         MODAL — Assign Plan
    ════════════════════════════════════════════════════════════ -->
    <div v-if="assignModal" class="bp-overlay" @click.self="assignModal = false">
      <div class="bp-modal">
        <div class="bp-modal-head">
          <h3>Assign Benefit Plan</h3>
          <button class="bp-close" @click="assignModal = false">×</button>
        </div>
        <div class="bp-modal-body">
          <div class="form-field">
            <label class="bp-label">Employee <span class="req">*</span></label>
            <select v-model="assignForm.employee_id" class="input">
              <option value="">Select employee…</option>
              <option v-for="e in employees" :key="e.id" :value="e.id">
                {{ e.first_name }} {{ e.last_name }} ({{ e.employee_number }})
              </option>
            </select>
          </div>
          <div class="form-field">
            <label class="bp-label">Benefit Plan <span class="req">*</span></label>
            <select v-model="assignForm.plan_id" class="input">
              <option value="">Select plan…</option>
              <option v-for="p in plans.filter(x => x.is_active)" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
          </div>
          <div class="form-grid-2">
            <div class="form-field">
              <label class="bp-label">Effective Date <span class="req">*</span></label>
              <input v-model="assignForm.effective_date" type="date" class="input" />
            </div>
            <div class="form-field">
              <label class="bp-label">End Date <span class="bp-hint-inline">(blank = ongoing)</span></label>
              <input v-model="assignForm.end_date" type="date" class="input" />
            </div>
          </div>
          <div class="form-field">
            <label class="bp-label">Notes</label>
            <textarea v-model="assignForm.notes" class="input" rows="2" />
          </div>
        </div>
        <div class="bp-modal-foot">
          <button class="btn btn-ghost" @click="assignModal = false">Cancel</button>
          <button class="btn btn-primary" :disabled="saving" @click="saveAssignment">
            {{ saving ? "Saving…" : "Assign Plan" }}
          </button>
        </div>
      </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════
         MODAL — Register Dependent
    ════════════════════════════════════════════════════════════ -->
    <div v-if="depModal" class="bp-overlay" @click.self="depModal = false">
      <div class="bp-modal">
        <div class="bp-modal-head">
          <h3>Register Dependent</h3>
          <button class="bp-close" @click="depModal = false">×</button>
        </div>
        <div class="bp-modal-body">
          <p class="bp-hint">Required for Medical Insurance coverage. Documents should be submitted to HR.</p>
          <div class="form-field">
            <label class="bp-label">Employee <span class="req">*</span></label>
            <select v-model="depForm.employee_id" class="input">
              <option value="">Select employee…</option>
              <option v-for="e in employees" :key="e.id" :value="e.id">
                {{ e.first_name }} {{ e.last_name }} ({{ e.employee_number }})
              </option>
            </select>
          </div>
          <div class="form-grid-2">
            <div class="form-field">
              <label class="bp-label">Full Name <span class="req">*</span></label>
              <input v-model="depForm.full_name" class="input" placeholder="Dependent's full name" />
            </div>
            <div class="form-field">
              <label class="bp-label">Relationship <span class="req">*</span></label>
              <select v-model="depForm.relationship" class="input">
                <option value="spouse">Spouse</option>
                <option value="child">Child</option>
                <option value="parent">Parent</option>
                <option value="other">Other</option>
              </select>
            </div>
          </div>
          <div class="form-grid-2">
            <div class="form-field">
              <label class="bp-label">Date of Birth</label>
              <input v-model="depForm.date_of_birth" type="date" class="input" />
            </div>
            <div class="form-field">
              <label class="bp-label">ID / Passport Number</label>
              <input v-model="depForm.id_number" class="input" placeholder="National ID or Passport" />
            </div>
          </div>
          <p class="bp-hint" style="margin-top:8px">
            Supporting documents (birth certificate, marriage certificate, etc.) should be submitted physically to HR for filing.
          </p>
        </div>
        <div class="bp-modal-foot">
          <button class="btn btn-ghost" @click="depModal = false">Cancel</button>
          <button class="btn btn-primary" :disabled="saving" @click="saveDependent">
            {{ saving ? "Saving…" : "Register Dependent" }}
          </button>
        </div>
      </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════
         MODAL — Approval Request
    ════════════════════════════════════════════════════════════ -->
    <div v-if="approvalModal" class="bp-overlay" @click.self="approvalModal = false">
      <div class="bp-modal">
        <div class="bp-modal-head">
          <h3>Submit Approval Request</h3>
          <button class="bp-close" @click="approvalModal = false">×</button>
        </div>
        <div class="bp-modal-body">
          <div class="form-field">
            <label class="bp-label">Employee <span class="req">*</span></label>
            <select v-model="approvalForm.employee_id" class="input">
              <option value="">Select employee…</option>
              <option v-for="e in employees" :key="e.id" :value="e.id">
                {{ e.first_name }} {{ e.last_name }} ({{ e.employee_number }})
              </option>
            </select>
          </div>
          <div class="form-field">
            <label class="bp-label">Benefit Type <span class="req">*</span></label>
            <select v-model="approvalForm.benefit_definition_id" class="input">
              <option value="">Select…</option>
              <option v-for="d in approvableDefs" :key="d.id" :value="d.id">{{ d.name }}</option>
            </select>
          </div>
          <div class="form-grid-2">
            <div class="form-field">
              <label class="bp-label">Start Date <span class="req">*</span></label>
              <input v-model="approvalForm.requested_date" type="date" class="input" />
            </div>
            <div class="form-field">
              <label class="bp-label">End Date</label>
              <input v-model="approvalForm.end_date" type="date" class="input" />
            </div>
          </div>
          <div class="form-grid-2">
            <div class="form-field">
              <label class="bp-label">Days Requested <span class="req">*</span></label>
              <input v-model.number="approvalForm.days_requested" type="number" min="1" class="input" />
            </div>
            <div class="form-field">
              <label class="bp-label">Supervisor</label>
              <select v-model="approvalForm.supervisor_id" class="input">
                <option value="">Select supervisor…</option>
                <option v-for="e in employees" :key="e.id" :value="e.id">
                  {{ e.first_name }} {{ e.last_name }}
                </option>
              </select>
            </div>
          </div>
          <div class="form-field">
            <label class="bp-label">Reason</label>
            <textarea v-model="approvalForm.reason" class="input" rows="2" placeholder="Provide reason for the request…" />
          </div>
        </div>
        <div class="bp-modal-foot">
          <button class="btn btn-ghost" @click="approvalModal = false">Cancel</button>
          <button class="btn btn-primary" :disabled="saving" @click="saveApproval">
            {{ saving ? "Saving…" : "Submit Request" }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.page-wrap  { padding: 24px clamp(1rem, 2.5vw, 3rem); max-width: none; }
.page-title { font-size: 22px; font-weight: 700; margin-bottom: 2px; }
.page-sub   { font-size: 13px; color: var(--color-text-muted); margin-bottom: 20px; }
.page-header{ display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px; }

.alert        { padding: 10px 16px; border-radius: var(--radius-md); font-size: 13px; margin-bottom: 12px; }
.alert-error  { background: var(--color-error-light); color: var(--color-error); border: 1px solid rgba(211,47,47,.2); }
.alert-success{ background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }

.card        { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: 24px; margin-bottom: 20px; }
.card-title  { font-size: 15px; font-weight: 600; }

.bp-card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; }
.bp-loading { text-align: center; padding: 40px; color: var(--color-text-muted); }
.bp-empty   { text-align: center; padding: 40px; color: var(--color-text-muted); font-size: 13px; }

.bp-filter-row { display: flex; gap: 8px; margin-bottom: 16px; }

.btn-filter        { padding: 5px 14px; border-radius: 16px; border: 1px solid var(--color-neutral-300); background: #fff; font-size: 12px; cursor: pointer; transition: all .15s; }
.btn-filter.active { background: var(--color-brand-base); color: #fff; border-color: var(--color-brand-base); }

/* Type chip */
.bp-type-chip { display: inline-block; font-size: 11px; padding: 2px 8px; border-radius: 10px; background: var(--color-brand-light); color: var(--color-brand-base); font-weight: 500; }
.bp-rel-chip  { text-transform: capitalize; font-size: 13px; }

/* Plan list */
.bp-plan-list   { display: flex; flex-direction: column; gap: 10px; }
.bp-plan-card   { border: 1px solid var(--color-border); border-radius: var(--radius-md); overflow: hidden; }
.bp-plan-header { display: flex; justify-content: space-between; align-items: center; padding: 14px 18px; cursor: pointer; background: var(--color-neutral-50); }
.bp-plan-name   { font-weight: 600; font-size: 14px; }
.bp-plan-body   { padding: 16px 18px; border-top: 1px solid var(--color-border); }
.bp-plan-desc   { font-size: 13px; color: var(--color-text-muted); margin-bottom: 12px; }
.bp-chevron     { font-size: 11px; color: var(--color-text-muted); margin-left: 8px; }

/* Plan builder benefit rows */
.bp-benefit-row  { border: 1px solid var(--color-border); border-radius: var(--radius-sm); padding: 10px 14px; margin-bottom: 8px; }
.bp-benefit-check{ display: flex; align-items: center; gap: 10px; cursor: pointer; }
.bp-benefit-info { display: flex; align-items: center; flex-wrap: wrap; }
.bp-override-row { display: grid; grid-template-columns: 1fr 1fr 1fr 1.5fr; gap: 8px; margin-top: 10px; }

/* Location rates */
.bp-rate-row { display: flex; gap: 8px; align-items: center; margin-bottom: 8px; }

/* Eligibility tags */
.bp-tag-input { display: flex; gap: 8px; margin-bottom: 8px; }
.bp-tags      { display: flex; flex-wrap: wrap; gap: 6px; }
.bp-tag       { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; background: var(--color-neutral-100); border-radius: 12px; font-size: 12px; }
.bp-tag button{ background: none; border: none; cursor: pointer; color: var(--color-text-muted); padding: 0; font-size: 14px; line-height: 1; }

/* Toggle switch */
.bp-toggle-field { display: flex; align-items: center; gap: 12px; }
.bp-toggle  { position: relative; display: inline-block; width: 40px; height: 22px; }
.bp-toggle input { display: none; }
.bp-slider  { position: absolute; inset: 0; background: var(--color-neutral-300); border-radius: 11px; cursor: pointer; transition: .2s; }
.bp-toggle input:checked + .bp-slider { background: var(--color-brand-base); }
.bp-slider::after { content: ""; position: absolute; width: 16px; height: 16px; left: 3px; top: 3px; border-radius: 50%; background: #fff; transition: .2s; }
.bp-toggle input:checked + .bp-slider::after { transform: translateX(18px); }

/* Modal */
.bp-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.45); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.bp-modal   { background: #fff; border-radius: var(--radius-lg); width: min(540px,95vw); max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,.25); }
.bp-modal-wide { width: min(760px,95vw); }
.bp-modal-head { display: flex; justify-content: space-between; align-items: center; padding: 20px 24px 16px; border-bottom: 1px solid var(--color-neutral-200); }
.bp-modal-head h3 { margin: 0; font-size: 16px; font-weight: 600; }
.bp-modal-body { padding: 20px 24px; }
.bp-modal-foot { display: flex; justify-content: flex-end; gap: 10px; padding: 16px 24px; border-top: 1px solid var(--color-neutral-200); }
.bp-close  { background: none; border: none; font-size: 20px; cursor: pointer; color: var(--color-text-muted); line-height: 1; }

.bp-label      { font-size: 13px; font-weight: 600; display: block; margin-bottom: 5px; }
.bp-hint       { font-size: 12px; color: var(--color-text-muted); margin-bottom: 8px; }
.bp-hint-inline{ font-size: 11px; color: var(--color-text-muted); font-weight: 400; margin-left: 4px; }

/* Table */
.table-wrap { overflow-x: auto; }
.table { width: 100%; border-collapse: collapse; font-size: 13px; }
.table th { text-align: left; padding: 8px 12px; border-bottom: 2px solid var(--color-neutral-200); white-space: nowrap; font-size: 12px; color: var(--color-text-muted); }
.table td { padding: 10px 12px; border-bottom: 1px solid var(--color-neutral-100); vertical-align: middle; }
.table tr:last-child td { border-bottom: none; }

.row-actions { display: flex; gap: 6px; align-items: center; }

.badge         { display: inline-block; font-size: 11px; padding: 2px 8px; border-radius: 10px; font-weight: 500; }
.badge-success { background: #dcfce7; color: #15803d; }
.badge-warning { background: #fef9c3; color: #854d0e; }
.badge-error   { background: #fee2e2; color: #b91c1c; }
.badge-neutral { background: var(--color-neutral-100); color: var(--color-text-muted); }

.form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
.form-field  { margin-bottom: 14px; }
.form-field:last-child { margin-bottom: 0; }

.fw-600    { font-weight: 600; }
.text-muted{ color: var(--color-text-muted); }
.req       { color: var(--color-error); }

.btn        { padding: 8px 16px; border-radius: var(--radius-md); border: none; cursor: pointer; font-size: 13px; font-weight: 500; transition: opacity .15s; }
.btn:disabled{ opacity: .5; cursor: not-allowed; }
.btn-primary { background: var(--color-brand-base); color: #fff; }
.btn-secondary { background: var(--color-neutral-100); color: var(--color-text); border: 1px solid var(--color-neutral-300); }
.btn-ghost  { background: transparent; color: var(--color-text); border: 1px solid var(--color-neutral-300); }
.btn-ghost.danger  { color: var(--color-error); border-color: transparent; }
.btn-ghost.success { color: #15803d; border-color: transparent; }
.btn-sm     { padding: 5px 12px; font-size: 12px; }

.input { width: 100%; padding: 8px 12px; border: 1px solid var(--color-neutral-300); border-radius: var(--radius-md); font-size: 13px; background: #fff; box-sizing: border-box; }
.input:focus { outline: none; border-color: var(--color-brand-base); }

@media (max-width: 680px) {
  .form-grid-2 { grid-template-columns: 1fr; }
  .bp-override-row { grid-template-columns: 1fr 1fr; }
}
</style>
