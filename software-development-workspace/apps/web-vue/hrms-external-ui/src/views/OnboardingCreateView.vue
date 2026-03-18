<script setup lang="ts">
/**
 * OnboardingCreateView — multi-step wizard orchestrator.
 *
 * Flow:
 *   1. Load reference data (companies, departments, designations, etc.)
 *   2. Quick-start form → call create_onboarding_draft → receive onboarding_id
 *   3. Steps 1-7: v-model bound step components → auto-save via save_onboarding_step
 *   4. Final review step → submit_onboarding → redirect to list
 */
import { ref, reactive, computed, onMounted } from "vue";
import { useRouter } from "vue-router";
import { callFrappeMethod, type FrappeAuthConfig } from "../lib/frappe-client";
import { authStore } from "../stores/auth";
import type {
  CompanyRecord,
  DepartmentRecord,
  DesignationRecord,
  EmploymentTypeRecord,
  EmployeeRecord,
  OnboardingQualification,
  OnboardingNextOfKin,
  OnboardingEmergencyContact,
} from "../lib/types";

import OnboardingProgressBar from "../components/onboarding/OnboardingProgressBar.vue";
import StepPersonal from "../components/onboarding/StepPersonal.vue";
import StepEmployment from "../components/onboarding/StepEmployment.vue";
import StepStatutory from "../components/onboarding/StepStatutory.vue";
import StepQualifications from "../components/onboarding/StepQualifications.vue";
import StepNextOfKin from "../components/onboarding/StepNextOfKin.vue";
import StepDocuments from "../components/onboarding/StepDocuments.vue";
import StepReview from "../components/onboarding/StepReview.vue";

const router = useRouter();

// ---- reference data ----
const companies = ref<CompanyRecord[]>([]);
const departments = ref<DepartmentRecord[]>([]);
const designations = ref<DesignationRecord[]>([]);
const employmentTypes = ref<EmploymentTypeRecord[]>([]);
const managers = ref<EmployeeRecord[]>([]);
const refLoading = ref(true);
const refError = ref("");

// ---- quick-start form ----
const quickStart = reactive({
  company: "",
  department: "",
  designation: "",
  employment_type: "",
  date_of_hire: "",
  reporting_manager: "",
});

// ---- wizard state ----
const phase = ref<"quickstart" | "steps" | "done">("quickstart");
const onboardingId = ref("");
const currentStep = ref(1); // 1-indexed
const saving = ref(false);
const submitError = ref("");

const STEP_LABELS: Record<string, string> = {
  personal:       "Personal Details",
  employment:     "Employment Details",
  statutory:      "Statutory & Compliance",
  qualifications: "Qualifications",
  next_of_kin:    "Next of Kin",
  documents:      "Documents",
  review:         "Review & Submit",
};
const STEP_SLUGS = ["personal", "employment", "statutory", "qualifications", "next_of_kin", "documents", "review"];
const TOTAL_STEPS = STEP_SLUGS.length;

const currentSlug = computed(() => STEP_SLUGS[currentStep.value - 1] ?? "");

// ---- per-step reactive data models (matching each component's interface) ----
const personalData = reactive({
  first_name: "", middle_name: "", last_name: "",
  national_id_type: "National ID", national_id_number: "",
  date_of_birth: "", gender: "", marital_status: "",
  phone_number: "", personal_email: "", work_email: "", physical_address: "",
});
const consentCaptured = ref(false);

const employmentData = reactive({
  company: quickStart.company,
  department: quickStart.department,
  designation: quickStart.designation,
  employment_type: quickStart.employment_type,
  date_of_hire: quickStart.date_of_hire,
  reporting_manager: quickStart.reporting_manager,
  work_location: "", probation_period_months: "", group_function: "",
});

const statutoryData = reactive({
  kra_pin: "", nssf_number: "", nhif_number: "", sha_number: "",
  bank_name: "", bank_branch: "", account_number: "", account_name: "",
  tax_obligations_flag: false,
});

const qualificationsData = reactive<{ qualifications: OnboardingQualification[] }>({
  qualifications: [],
});

const nokData = reactive<{ next_of_kin: OnboardingNextOfKin[]; emergency_contacts: OnboardingEmergencyContact[] }>({
  next_of_kin: [],
  emergency_contacts: [],
});

const documentsData = reactive({
  national_id_copy: null as string | null,
  kra_pin_certificate: null as string | null,
  nssf_proof: null as string | null,
  nhif_proof: null as string | null,
  academic_certificates: null as string | null,
  employment_contract: null as string | null,
  compliance_documents: null as string | null,
});

function authConfig(): FrappeAuthConfig {
  return {
    baseUrl: authStore.baseUrl,
    apiKey: authStore.apiKey || undefined,
    apiSecret: authStore.apiSecret || undefined,
  };
}

// ---- load reference data ----
async function loadRefData(): Promise<void> {
  refLoading.value = true;
  refError.value = "";
  try {
    const [comps, deps, emps] = await Promise.all([
      callFrappeMethod<{ items: CompanyRecord[] }>(authConfig(), "bitwyse_hrms_backend.api.list_companies", {}),
      callFrappeMethod<{ items: DepartmentRecord[] }>(authConfig(), "bitwyse_hrms_backend.api.list_departments", {}),
      callFrappeMethod<{ items: EmployeeRecord[] }>(authConfig(), "bitwyse_hrms_backend.api.list_employees", { limit_page_length: 100 }),
    ]);
    companies.value = comps.items ?? [];
    departments.value = deps.items ?? [];
    managers.value = emps.items ?? [];

    // Designations and employment types via frappe.client.get_list
    const [desigs, etypes] = await Promise.all([
      callFrappeMethod<{ message: DesignationRecord[] }>(authConfig(), "frappe.client.get_list", {
        doctype: "Designation", fields: JSON.stringify(["name", "designation_name"]), limit_page_length: 200,
      }),
      callFrappeMethod<{ message: EmploymentTypeRecord[] }>(authConfig(), "frappe.client.get_list", {
        doctype: "Employment Type", fields: JSON.stringify(["name", "employee_type_name"]), limit_page_length: 50,
      }),
    ]);
    // frappe.client.get_list returns { message: [...] } directly
    designations.value = (desigs as unknown as DesignationRecord[]) ?? [];
    employmentTypes.value = (etypes as unknown as EmploymentTypeRecord[]) ?? [];

    if (authStore.company) quickStart.company = authStore.company;
  } catch (err) {
    refError.value = err instanceof Error ? err.message : "Failed to load reference data. Check backend connection.";
  } finally {
    refLoading.value = false;
  }
}

// ---- quick-start: create draft ----
async function createDraft(): Promise<void> {
  if (!quickStart.company || !quickStart.department || !quickStart.designation ||
      !quickStart.employment_type || !quickStart.date_of_hire) {
    submitError.value = "Please fill all required fields before continuing.";
    return;
  }
  saving.value = true;
  submitError.value = "";
  try {
    const res = await callFrappeMethod<{ onboarding_id: string; status: string }>(
      authConfig(),
      "bitwyse_hrms_backend.api.create_onboarding_draft",
      { ...quickStart },
    );
    onboardingId.value = res.onboarding_id;
    // Pre-fill employment step from quickstart
    employmentData.company = quickStart.company;
    employmentData.department = quickStart.department;
    employmentData.designation = quickStart.designation;
    employmentData.employment_type = quickStart.employment_type;
    employmentData.date_of_hire = quickStart.date_of_hire;
    employmentData.reporting_manager = quickStart.reporting_manager;
    currentStep.value = 1;
    phase.value = "steps";
  } catch (err) {
    submitError.value = err instanceof Error ? err.message : "Failed to create onboarding record.";
  } finally {
    saving.value = false;
  }
}

// ---- save current step and advance ----
async function saveAndNext(): Promise<void> {
  if (currentStep.value === TOTAL_STEPS) {
    await submitOnboarding();
    return;
  }
  saving.value = true;
  submitError.value = "";
  try {
    const payload = currentStepPayload();
    await callFrappeMethod(
      authConfig(),
      "bitwyse_hrms_backend.api.save_onboarding_step",
      {
        onboarding_id: onboardingId.value,
        step: currentSlug.value,
        data: JSON.stringify(payload),
        consent_captured: consentCaptured.value ? 1 : 0,
      },
    );
    currentStep.value++;
  } catch (err) {
    submitError.value = err instanceof Error ? err.message : "Failed to save step.";
  } finally {
    saving.value = false;
  }
}

function currentStepPayload(): Record<string, unknown> {
  switch (currentSlug.value) {
    case "personal":       return { ...personalData, consent_captured: consentCaptured.value };
    case "employment":     return { ...employmentData };
    case "statutory":      return { ...statutoryData };
    case "qualifications": return { qualifications: qualificationsData.qualifications };
    case "next_of_kin":    return { next_of_kin: nokData.next_of_kin, emergency_contacts: nokData.emergency_contacts };
    case "documents":      return { ...documentsData };
    default:               return {};
  }
}

function goBack(): void {
  if (currentStep.value > 1) currentStep.value--;
}

async function submitOnboarding(): Promise<void> {
  saving.value = true;
  submitError.value = "";
  try {
    await callFrappeMethod(
      authConfig(),
      "bitwyse_hrms_backend.api.submit_onboarding",
      { onboarding_id: onboardingId.value },
    );
    phase.value = "done";
  } catch (err) {
    submitError.value = err instanceof Error ? err.message : "Submission failed. Please check required fields.";
  } finally {
    saving.value = false;
  }
}

onMounted(loadRefData);
</script>

<template>
  <div class="view">
    <div class="view-header">
      <div>
        <button class="back-btn" @click="router.push({ name: 'onboarding-list' })">← Onboarding</button>
        <h2>New Employee Onboarding</h2>
      </div>
    </div>

    <div v-if="refLoading" class="loading-state"><span class="spinner" />  Preparing form…</div>
    <p v-else-if="refError" class="error-banner">{{ refError }}</p>

    <!-- Quick-start phase -->
    <div v-else-if="phase === 'quickstart'" class="card qs-card">
      <h3>Start Onboarding Record</h3>
      <p class="help-text">Fill in the employment details to create a draft, then guide the employee through each section.</p>

      <div class="form-grid">
        <div class="field">
          <label>Company <span class="req">*</span></label>
          <select v-model="quickStart.company" required>
            <option value="">Select company…</option>
            <option v-for="c in companies" :key="c.name" :value="c.name">{{ c.company_name || c.name }}</option>
          </select>
        </div>
        <div class="field">
          <label>Department <span class="req">*</span></label>
          <select v-model="quickStart.department" required>
            <option value="">Select department…</option>
            <option v-for="d in departments" :key="d.name" :value="d.name">{{ d.department_name || d.name }}</option>
          </select>
        </div>
        <div class="field">
          <label>Designation <span class="req">*</span></label>
          <select v-model="quickStart.designation" required>
            <option value="">Select designation…</option>
            <option v-for="d in designations" :key="d.name" :value="d.name">{{ d.designation_name || d.name }}</option>
          </select>
        </div>
        <div class="field">
          <label>Employment Type <span class="req">*</span></label>
          <select v-model="quickStart.employment_type" required>
            <option value="">Select type…</option>
            <option v-for="e in employmentTypes" :key="e.name" :value="e.name">{{ e.employee_type_name || e.name }}</option>
          </select>
        </div>
        <div class="field">
          <label>Date of Hire <span class="req">*</span></label>
          <input v-model="quickStart.date_of_hire" type="date" required />
        </div>
        <div class="field">
          <label>Reporting Manager</label>
          <select v-model="quickStart.reporting_manager">
            <option value="">None</option>
            <option v-for="m in managers" :key="m.name" :value="m.name">{{ m.employee_name || m.name }}</option>
          </select>
        </div>
      </div>

      <p v-if="submitError" class="error-banner">{{ submitError }}</p>
      <div class="form-actions">
        <button class="primary" :disabled="saving" @click="createDraft">
          <span v-if="saving" class="spinner btn-spinner" />
          {{ saving ? "Creating…" : "Create Onboarding Record →" }}
        </button>
      </div>
    </div>

    <!-- Steps phase -->
    <div v-else-if="phase === 'steps'">
      <OnboardingProgressBar
        :current-step="currentStep"
        :total-steps="TOTAL_STEPS"
        :step-labels="STEP_LABELS"
      />

      <p v-if="submitError" class="error-banner">{{ submitError }}</p>

      <div class="card step-card">
        <StepPersonal
          v-if="currentStep === 1"
          v-model="personalData"
          :consent-captured="consentCaptured"
          @update:consent-captured="consentCaptured = $event"
        />
        <StepEmployment
          v-else-if="currentStep === 2"
          v-model="employmentData"
          :companies="companies"
          :departments="departments"
          :designations="designations"
          :employment-types="employmentTypes"
          :managers="managers"
        />
        <StepStatutory
          v-else-if="currentStep === 3"
          v-model="statutoryData"
        />
        <StepQualifications
          v-else-if="currentStep === 4"
          v-model="qualificationsData"
        />
        <StepNextOfKin
          v-else-if="currentStep === 5"
          v-model="nokData"
        />
        <StepDocuments
          v-else-if="currentStep === 6"
          v-model="documentsData"
        />
        <StepReview
          v-else-if="currentStep === 7"
          :personal="personalData"
          :employment="employmentData"
          :statutory="statutoryData"
          :qualifications="qualificationsData.qualifications"
          :next-of-kin="nokData.next_of_kin"
          :emergency-contacts="nokData.emergency_contacts"
          :documents="documentsData"
          :consent-captured="consentCaptured"
        />

        <!-- Navigation buttons — always in the orchestrator -->
        <div class="step-nav">
          <button v-if="currentStep > 1" class="secondary" :disabled="saving" @click="goBack">← Back</button>
          <span class="step-counter">Step {{ currentStep }} of {{ TOTAL_STEPS }}</span>
          <button class="primary" :disabled="saving" @click="saveAndNext">
            <span v-if="saving" class="spinner btn-spinner" />
            {{ currentStep === TOTAL_STEPS ? (saving ? 'Submitting…' : 'Submit for Approval') : (saving ? 'Saving…' : 'Next →') }}
          </button>
        </div>
      </div>

      <p class="draft-note">Draft saved. Record ID: <span class="mono">{{ onboardingId }}</span></p>
    </div>

    <!-- Completion screen -->
    <div v-else-if="phase === 'done'" class="card done-card">
      <svg class="done-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="10"/>
        <path d="M9 12l2 2 4-4"/>
      </svg>
      <h3>Onboarding Submitted!</h3>
      <p>Record <span class="mono">{{ onboardingId }}</span> has been submitted for HR review.</p>
      <div class="done-actions">
        <button class="primary" @click="router.push({ name: 'onboarding-detail', params: { id: onboardingId } })">View Record</button>
        <button class="secondary" @click="router.push({ name: 'onboarding-list' })">Back to List</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.view-header { margin-bottom: 1.5rem; }
.back-btn { background: none; border: none; color: var(--primary); cursor: pointer; font-size: 0.85rem; padding: 0; margin-bottom: 0.4rem; display: block; }
.card { background: #fff; border: 1px solid var(--border); border-radius: 10px; padding: 1.5rem; }
.qs-card { max-width: 700px; }
.qs-card h3 { margin-bottom: 0.25rem; }
.help-text { font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1.25rem; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
@media (max-width: 560px) { .form-grid { grid-template-columns: 1fr; } }
.field { display: flex; flex-direction: column; gap: 0.3rem; }
.field label { font-size: 0.8rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.04em; }
.field input, .field select { padding: 0.45rem 0.6rem; border: 1px solid var(--border); border-radius: 6px; font-size: 0.875rem; background: #fff; }
.req { color: #e53e3e; }
.step-card { margin-top: 1rem; }
.draft-note { font-size: 0.78rem; color: var(--text-muted); text-align: center; margin-top: 0.75rem; }
.mono { font-family: monospace; }
.done-card { text-align: center; max-width: 500px; margin: 2rem auto; padding: 2.5rem 2rem; }
.done-icon { width: 56px; height: 56px; color: #38a169; margin: 0 auto 1rem; }
.done-card h3 { font-size: 1.3rem; margin-bottom: 0.5rem; }
.done-card p { color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1.5rem; }
.done-actions { display: flex; gap: 0.75rem; justify-content: center; flex-wrap: wrap; }
.form-actions { margin-top: 1.5rem; text-align: right; }
button.primary { background: var(--primary); color: #fff; border: none; padding: 0.5rem 1.25rem; border-radius: 6px; font-size: 0.875rem; cursor: pointer; display: inline-flex; align-items: center; gap: 0.4rem; }
button.secondary { background: #fff; border: 1px solid var(--border); color: var(--text); padding: 0.5rem 1.25rem; border-radius: 6px; font-size: 0.875rem; cursor: pointer; }
button:disabled { opacity: 0.45; cursor: not-allowed; }
.error-banner { background: #fff5f5; color: #c53030; padding: 0.75rem 1rem; border-radius: 6px; font-size: 0.875rem; margin: 1rem 0; }
.loading-state { display: flex; align-items: center; gap: 0.5rem; padding: 2rem; color: var(--text-muted); font-size: 0.875rem; }
.spinner { width: 16px; height: 16px; border: 2px solid var(--border); border-top-color: var(--primary); border-radius: 50%; animation: spin 0.7s linear infinite; display: inline-block; }
.step-nav { display: flex; justify-content: space-between; align-items: center; margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--border); flex-wrap: wrap; gap: 0.5rem; }
.step-counter { font-size: 0.8rem; color: var(--text-muted); }
</style>
