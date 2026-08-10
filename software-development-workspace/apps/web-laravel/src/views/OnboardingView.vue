<script setup lang="ts">
import { ref, onMounted, computed, reactive, watch } from "vue";
import { useRouter, useRoute } from "vue-router";
import { api } from "../lib/api";
import { currentOrgSlug, authStore } from "../stores/auth";

// ─── Types ───────────────────────────────────────────────────────────────────
interface Department  { id: number; name: string }
interface Designation { id: number; title: string }
interface JobGrade    { id: number; name: string; level: number }
interface Employee    { id: number; first_name: string; last_name: string; employee_number: string }

interface NextOfKin {
  name: string; relationship: string; phone: string; email: string; is_primary: boolean;
}
interface Secondment {
  org_id: number; org_name: string; role: string; scope: string;
  /** Why they are there — seconded, shared service, advisory. */
  engagement_type: string;
  start_date: string; end_date: string; allocation_pct: number; is_billable: boolean; notes: string;
}
interface AssetItem   { name: string; serial: string; assigned_date: string }
interface CheckItem   { label: string; done: boolean; notes: string }
interface PendingUpload { file: File; category: string; label: string }

// ─── Router ──────────────────────────────────────────────────────────────────
const router = useRouter();
const route  = useRoute();

const editId = computed(() => route.params.id ? Number(route.params.id) : null);
const isEdit = computed(() => editId.value !== null);

// ─── UI State ────────────────────────────────────────────────────────────────
const currentStep = ref(1);
const totalSteps  = 5;
const saving      = ref(false);
const loading     = ref(false);
const error       = ref("");
const stepErrors  = ref<string[]>([]);

const scopeModal  = ref(false);
const scopeTarget = ref<Secondment | null>(null);

// ─── Meta data ───────────────────────────────────────────────────────────────
const departments  = ref<Department[]>([]);
const designations = ref<Designation[]>([]);
const jobGrades    = ref<JobGrade[]>([]);
const managers     = ref<Employee[]>([]);

// ─── Form Data ───────────────────────────────────────────────────────────────
const bio = reactive({
  first_name: "", last_name: "", middle_name: "",
  national_id: "", tax_id: "",
  date_of_birth: "", gender: "", marital_status: "",
  phone: "", personal_email: "", work_email: "",
  address: "", city: "", county: "", country: "KE",
});

/**
 * The identifiers shown only when asked for.
 *
 * Not a control — anybody who can open this form can reveal them. It stops the
 * number being read over a shoulder, which is the exposure this screen actually
 * has.
 */
const revealed = reactive<Record<string, boolean>>({ national_id: false, tax_id: false });
function toggleReveal(field: string): void { revealed[field] = !revealed[field]; }

const employment = reactive({
  employment_type: "full_time", date_hired: "",
  probation_end_date: "", contract_end_date: "",
  designation_id: "" as string | number,
  department_id:  "" as string | number,
  job_grade_id:   "" as string | number,
  reports_to:     "" as string | number,
  benefit_plan: "", basic_salary: "", status: "active",
  primary_org_id: (authStore.currentOrg?.id ?? null) as number | null,
  secondments: [] as Secondment[],
});

/**
 * The organisations somebody can be seconded to — everywhere except where they
 * already sit. The server refuses a secondment to their own organisation, so
 * offering it here only produces a form that cannot be submitted.
 */
const allOrgs = computed(() =>
  authStore.organizations.filter(o => o.id !== employment.primary_org_id));

/**
 * How much of their week is already promised elsewhere.
 *
 * Their home organisation is not counted: the percentage means "how much goes
 * elsewhere", and the remainder stays where they normally sit. The server
 * enforces the same cap — this only saves somebody filling in a panel that will
 * be refused.
 */
function allocatedExcluding(s: Secondment | null): number {
  return employment.secondments
    .filter(x => x !== s)
    .reduce((n, x) => n + (Number(x.allocation_pct) || 0), 0);
}

const allocationLeft = computed(() => 100 - allocatedExcluding(scopeTarget.value));

// ── Contract duration picker ─────────────────────────────────────────────────
const contractDuration    = ref("");
const contractOtherMonths = ref<number | "">("");

const needsEndDate = computed(() =>
  ["contract", "attachment", "intern"].includes(employment.employment_type)
);

watch(
  [() => employment.employment_type, () => employment.date_hired, contractDuration, contractOtherMonths],
  ([type]) => {
    if (!["contract", "attachment", "intern"].includes(type as string)) {
      employment.contract_end_date = "";
      contractDuration.value = "";
      contractOtherMonths.value = "";
      return;
    }
    const months =
      contractDuration.value === "other"
        ? (typeof contractOtherMonths.value === "number" ? contractOtherMonths.value : 0)
        : contractDuration.value ? parseInt(contractDuration.value) : 0;
    if (!months || !employment.date_hired) return;
    const d = new Date(employment.date_hired);
    d.setMonth(d.getMonth() + months);
    employment.contract_end_date = d.toISOString().slice(0, 10);
  }
);

const benefits = reactive({
  nssf_number: "", nhif_number: "",
  medical_plan: "", pension_provider: "",
  life_insurance: false, pension: false, medical: false, group_life: false, wiba: false,
  benefits_effective_date: "",
});

const nokList = ref<NextOfKin[]>([
  { name: "", relationship: "", phone: "", email: "", is_primary: true },
]);
const compliance = reactive({
  nssf_registered: false, nhif_registered: false, kra_registered: false,
  has_work_permit: false, work_permit_number: "", work_permit_expiry: "",
});

const inductions = ref<CheckItem[]>([
  { label: "Organisation orientation",        done: false, notes: "" },
  { label: "HR policies & handbook briefing", done: false, notes: "" },
  { label: "Health & safety induction",       done: false, notes: "" },
  { label: "IT systems & security training",  done: false, notes: "" },
  { label: "Department-specific induction",   done: false, notes: "" },
  { label: "Meet the team introduction",      done: false, notes: "" },
]);

const documents = ref<CheckItem[]>([
  { label: "Offer letter signed",        done: false, notes: "" },
  { label: "Employment contract signed", done: false, notes: "" },
  { label: "National ID / Passport copy",done: false, notes: "" },
  { label: "KRA PIN certificate",        done: false, notes: "" },
  { label: "Academic certificates",      done: false, notes: "" },
  { label: "Passport photo",             done: false, notes: "" },
  { label: "NHIF card copy",             done: false, notes: "" },
  { label: "NSSF card copy",             done: false, notes: "" },
  { label: "Bank account details",       done: false, notes: "" },
]);

const assets = ref<AssetItem[]>([]);

// ─── Document file upload queue (Step 5) ─────────────────────────────────────
const pendingUploads = ref<PendingUpload[]>([]);
const uploadedDocs   = ref<{ id: number; category: string; original_name: string; created_at: string }[]>([]);

const UPLOAD_CATEGORIES = [
  { value: "national_id",  label: "National ID / Passport" },
  { value: "contract",     label: "Employment Contract" },
  { value: "certificate",  label: "Academic Certificate" },
  { value: "kra_pin",      label: "KRA PIN Certificate" },
  { value: "nhif",         label: "NHIF Card" },
  { value: "nssf",         label: "NSSF Card" },
  { value: "photo",        label: "Passport Photo" },
  { value: "bank_details", label: "Bank Account Details" },
  { value: "other",        label: "Other" },
];

const newUploadCategory = ref("other");
const newUploadFile     = ref<File | null>(null);
const uploadError       = ref("");

function onFileSelected(e: Event): void {
  const input = e.target as HTMLInputElement;
  newUploadFile.value = input.files?.[0] ?? null;
  uploadError.value   = "";
}

function queueUpload(): void {
  uploadError.value = "";
  if (!newUploadFile.value) { uploadError.value = "Please select a file."; return; }
  const f = newUploadFile.value;
  if (f.size > 10 * 1024 * 1024) { uploadError.value = "File must be under 10 MB."; return; }
  const allowed = ["application/pdf","image/jpeg","image/png","image/webp",
    "application/msword","application/vnd.openxmlformats-officedocument.wordprocessingml.document"];
  if (!allowed.includes(f.type)) { uploadError.value = "Allowed types: PDF, JPG, PNG, WEBP, DOC, DOCX."; return; }
  pendingUploads.value.push({ file: f, category: newUploadCategory.value, label: f.name });
  newUploadFile.value = null;
  (document.getElementById("doc-file-input") as HTMLInputElement | null)!.value = "";
}

function removePendingUpload(i: number): void { pendingUploads.value.splice(i, 1); }

function org(): string | null { return currentOrgSlug.value; }

// ─── Navigation ───────────────────────────────────────────────────────────────
const stepLabels = ["Bio Data", "Employment", "Benefits", "Next of Kin", "Onboarding Steps"];

function validateStep(step: number): string[] {
  const errs: string[] = [];
  if (step === 1) {
    if (!bio.first_name.trim())     errs.push("First name is required.");
    if (!bio.last_name.trim())      errs.push("Last name is required.");
    if (!bio.phone.trim())          errs.push("Phone number is required.");
    if (!bio.personal_email.trim()) errs.push("Email address is required.");
  }
  if (step === 2) {
    if (!employment.employment_type) errs.push("Employment type is required.");
    if (!employment.date_hired)      errs.push("Start date is required.");
    if (!employment.primary_org_id)  errs.push("A primary organisation must be selected.");

    if (employment.secondments.some(s => !s.org_id)) {
      errs.push("Every secondment needs an organisation.");
    }

    // Somebody at 60% and 60% is a promise the week cannot keep. The server
    // refuses it too; catching it here saves losing the whole form to a 422.
    const total = allocatedExcluding(null);
    if (total > 100) {
      errs.push(`Secondments add up to ${total}% of their week. Reduce them to 100% or less.`);
    }
  }
  if (step === 4) {
    for (const nok of nokList.value) {
      if (!nok.name.trim())         errs.push("Next of kin name is required.");
      if (!nok.relationship.trim()) errs.push("Next of kin relationship is required.");
    }
  }
  return errs;
}

function goNext(): void {
  stepErrors.value = validateStep(currentStep.value);
  if (stepErrors.value.length) return;
  if (currentStep.value < totalSteps) currentStep.value++;
}
function goBack(): void {
  stepErrors.value = [];
  if (currentStep.value > 1) currentStep.value--;
}
function goToStep(n: number): void {
  if (n < currentStep.value) { currentStep.value = n; stepErrors.value = []; }
}

// ─── NOK helpers ─────────────────────────────────────────────────────────────
function addNok(): void {
  nokList.value.push({ name: "", relationship: "", phone: "", email: "", is_primary: false });
}
function removeNok(i: number): void { nokList.value.splice(i, 1); }
function setPrimaryNok(i: number): void {
  nokList.value.forEach((n, j) => { n.is_primary = j === i; });
}

// ─── Asset helpers ────────────────────────────────────────────────────────────
function addAsset(): void { assets.value.push({ name: "", serial: "", assigned_date: "" }); }
function removeAsset(i: number): void { assets.value.splice(i, 1); }

// ─── Secondment helpers ───────────────────────────────────────────────────────
function addSecondment(): void {
  const s: Secondment = {
    org_id: 0, org_name: "", role: "", scope: "operational",
    engagement_type: "secondment",
    // Half a week, because a secondment at 100% is a transfer. It also leaves
    // room for a second one without immediately breaching the cap.
    start_date: "", end_date: "", allocation_pct: 50, is_billable: false, notes: "",
  };
  employment.secondments.push(s);
  openScope(s);
}
function removeSecondment(i: number): void { employment.secondments.splice(i, 1); }
function openScope(s: Secondment): void { scopeTarget.value = s; scopeModal.value = true; }
function closeScope(): void { scopeModal.value = false; scopeTarget.value = null; }
function onSecondmentOrgChange(s: Secondment, orgId: number): void {
  s.org_id   = orgId;
  s.org_name = allOrgs.value.find(o => o.id === orgId)?.name ?? "";
}

// ─── Data loaders ─────────────────────────────────────────────────────────────
async function loadMeta(): Promise<void> {
  if (!org()) return;
  loading.value = true;
  try {
    const [d, des, g, emps] = await Promise.all([
      api<{ data: Department[] }>("GET",  "/api/v1/org/departments",  { org: org() }),
      api<{ data: Designation[] }>("GET", "/api/v1/org/designations", { org: org() }),
      api<{ data: JobGrade[] }>("GET",    "/api/v1/org/job-grades",   { org: org() }),
      api<any>("GET",                      "/api/v1/employees",        { org: org(), params: { per_page: 200 } }),
    ]);
    departments.value  = d.data;
    designations.value = des.data;
    jobGrades.value    = g.data;
    managers.value     = emps?.data ?? [];
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to load form data.";
  } finally {
    loading.value = false;
  }
}

async function loadEmployee(): Promise<void> {
  if (!editId.value) return;
  try {
    const emp = await api<any>("GET", `/api/v1/employees/${editId.value}`, { org: org() });
    const e   = emp.data ?? emp;
    Object.assign(bio, {
      first_name: e.first_name ?? "", last_name: e.last_name ?? "",
      middle_name: e.middle_name ?? "", national_id: e.national_id ?? "",
      tax_id: e.tax_id ?? "", date_of_birth: e.date_of_birth?.slice(0,10) ?? "",
      gender: e.gender ?? "", marital_status: e.marital_status ?? "",
      phone: e.phone ?? "",
      personal_email: e.personal_email ?? "", work_email: e.work_email ?? "",
      address: e.address ?? "", city: e.city ?? "",
      county: e.county ?? "", country: e.country ?? "KE",
    });
    Object.assign(employment, {
      employment_type: e.employment_type ?? "full_time",
      date_hired: e.date_hired?.slice(0,10) ?? "",
      probation_end_date: e.probation_end_date?.slice(0,10) ?? "",
      contract_end_date: e.contract_end_date?.slice(0,10) ?? "",
      designation_id: e.designation_id ?? "", department_id: e.department_id ?? "",
      job_grade_id: e.job_grade_id ?? "", reports_to: e.reports_to ?? "",
      status: e.status ?? "active",
    });

    /*
     * Everything the form sends has to come back, or saving one field wipes
     * the rest. The benefit flags in particular are sent unconditionally, so
     * an unloaded `false` is indistinguishable from somebody genuinely having
     * no medical cover.
     */
    Object.assign(benefits, {
      nssf_number: e.nssf_number ?? "", nhif_number: e.nhif_number ?? "",
      medical_plan: e.medical_plan ?? "", pension_provider: e.pension_provider ?? "",
      benefits_effective_date: e.benefits_effective_date?.slice(0,10) ?? "",
      medical: !!e.benefit_medical, pension: !!e.benefit_pension,
      life_insurance: !!e.benefit_life_insurance, group_life: !!e.benefit_group_life,
      wiba: !!e.benefit_wiba,
    });

    Object.assign(compliance, {
      nssf_registered: !!e.nssf_registered, nhif_registered: !!e.nhif_registered,
      kra_registered: !!e.kra_registered, has_work_permit: !!e.has_work_permit,
      work_permit_number: e.work_permit_number ?? "",
      work_permit_expiry: e.work_permit_expiry?.slice(0,10) ?? "",
    });

    employment.secondments = (e.secondments ?? [])
      .filter((s: any) => s.is_active !== false)
      .map((s: any) => ({
        org_id: s.organization_id, org_name: s.organization_name ?? "",
        role: s.role ?? "", scope: s.access_scope ?? "operational",
        engagement_type: s.engagement_type ?? "secondment",
        start_date: s.start_date?.slice(0,10) ?? "", end_date: s.end_date?.slice(0,10) ?? "",
        allocation_pct: s.allocation_pct ?? 50, is_billable: !!s.is_billable,
        notes: s.notes ?? "",
      }));
  } catch {
    error.value = "Failed to load employee data.";
  }
}

// ─── Submit ───────────────────────────────────────────────────────────────────

/**
 * The draft this wizard has already created, if it has.
 *
 * Saving a draft twice must update the same person rather than leave a trail of
 * half-filled records behind, so the first save records the id and every save
 * after it is an update.
 */
const draftId = ref<number | null>(null);

/** Whichever record we are writing to — the one being edited, or our draft. */
const targetId = computed(() => editId.value ?? draftId.value);

function buildPayload(status: string): Record<string, unknown> {
  const payload: Record<string, unknown> = {
    // Bio
    first_name: bio.first_name, last_name: bio.last_name,
    middle_name: bio.middle_name || undefined,
    national_id: bio.national_id || undefined, tax_id: bio.tax_id || undefined,
    date_of_birth: bio.date_of_birth || undefined, gender: bio.gender || undefined,
    marital_status: bio.marital_status || undefined,
    phone: bio.phone || undefined, personal_email: bio.personal_email || undefined,
    work_email: bio.work_email || undefined,
    address: bio.address || undefined, city: bio.city || undefined,
    county: bio.county || undefined, country: bio.country || undefined,
    // Employment
    employment_type: employment.employment_type, date_hired: employment.date_hired,
    probation_end_date: employment.probation_end_date || undefined,
    contract_end_date: employment.contract_end_date || undefined,
    department_id: employment.department_id || undefined,
    designation_id: employment.designation_id || undefined,
    job_grade_id: employment.job_grade_id || undefined,
    reports_to: employment.reports_to || undefined,
    status,
    // Benefits
    nssf_number: benefits.nssf_number || undefined,
    nhif_number: benefits.nhif_number || undefined,
    medical_plan: benefits.medical_plan || undefined,
    pension_provider: benefits.pension_provider || undefined,
    benefit_medical: benefits.medical,
    benefit_life_insurance: benefits.life_insurance,
    benefit_pension: benefits.pension,
    benefit_wiba: benefits.wiba,
    benefit_group_life: benefits.group_life,
    benefits_effective_date: benefits.benefits_effective_date || undefined,
    // Compliance
    nssf_registered: compliance.nssf_registered,
    nhif_registered: compliance.nhif_registered,
    kra_registered: compliance.kra_registered,
    has_work_permit: compliance.has_work_permit,
    work_permit_number: compliance.has_work_permit ? (compliance.work_permit_number || undefined) : undefined,
    work_permit_expiry: compliance.has_work_permit ? (compliance.work_permit_expiry || undefined) : undefined,
    // Secondments
    secondments: employment.secondments.length > 0 ? employment.secondments.map(s => ({
      org_id: s.org_id,
      role: s.role || undefined,
      scope: s.scope,
      engagement_type: s.engagement_type || undefined,
      allocation_pct: s.allocation_pct,
      is_billable: s.is_billable,
      start_date: s.start_date || undefined,
      end_date: s.end_date || undefined,
      notes: s.notes || undefined,
    })) : undefined,
  };

  return payload;
}

/**
 * Save what has been filled in so far and leave.
 *
 * A record halfway through this form is not somebody who works here, so it is
 * saved as a draft: off the employee list, out of headcount, invisible to
 * payroll. Only Complete Onboarding makes the person real.
 *
 * Nothing beyond the employee record is written — next of kin, inductions and
 * documents all need a finished record to hang off, and a draft may not have
 * reached those steps at all.
 */
async function saveDraft(): Promise<void> {
  error.value = "";
  stepErrors.value = [];

  // Enough to identify who this is meant to become.
  if (!bio.first_name.trim() || !bio.last_name.trim()) {
    stepErrors.value = ["A first and last name are needed before a draft can be saved."];
    return;
  }

  saving.value = true;
  try {
    const payload = buildPayload(isEdit.value ? employment.status : "draft");

    if (targetId.value) {
      await api("PUT", `/api/v1/employees/${targetId.value}`, { org: org(), body: payload });
    } else {
      const created = await api<any>("POST", "/api/v1/employees", { org: org(), body: payload });
      draftId.value = created?.data?.id ?? created?.id ?? null;
    }

    void router.push({ name: "employees" });
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to save the draft.";
  } finally {
    saving.value = false;
  }
}

async function submit(): Promise<void> {
  error.value = "";

  // The last step commits everything, so the earlier ones have to hold.
  const failed = [1, 2, 4].flatMap(s => validateStep(s));
  if (failed.length) { stepErrors.value = failed; return; }

  saving.value = true;
  // Completing onboarding is what makes the person real.
  const payload = buildPayload(employment.status === "draft" ? "active" : employment.status);

  try {
    let employeeId: number;

    // Editing, or finishing a draft this wizard already saved.
    if (targetId.value) {
      await api("PUT", `/api/v1/employees/${targetId.value}`, { org: org(), body: payload });
      employeeId = targetId.value;
    } else {
      const created = await api<any>("POST", "/api/v1/employees", { org: org(), body: payload });
      employeeId = created?.data?.id ?? created?.id;
    }

    // Next of kin, as emergency contacts.
    for (const nok of nokList.value.filter(n => n.name.trim())) {
      await api("POST", `/api/v1/employees/${employeeId}/emergency-contacts`, {
        org: org(),
        body: {
          name: nok.name, relationship: nok.relationship,
          phone: nok.phone || undefined, email: nok.email || undefined,
          is_primary: nok.is_primary,
        },
      });
    }

    // Step 5: sync inductions, document checklist, assets
    await api("PUT", `/api/v1/employees/${employeeId}/onboarding`, {
      org: org(),
      body: {
        inductions: inductions.value.map(i => ({ label: i.label, done: i.done, notes: i.notes })),
        document_checklist: documents.value.map(d => ({ label: d.label, done: d.done, notes: d.notes })),
        assets: assets.value.filter(a => a.name.trim()).map(a => ({
          name: a.name, serial: a.serial, assigned_date: a.assigned_date || undefined,
        })),
      },
    });

    // Upload any pending document files (queued in pendingUploads)
    for (const upload of pendingUploads.value) {
      const form = new FormData();
      form.append("file", upload.file);
      form.append("category", upload.category);
      await api("POST", `/api/v1/employees/${employeeId}/documents`, { org: org(), formData: form });
    }
    pendingUploads.value = [];

    void router.push({ name: "employees" });
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to save employee.";
  } finally {
    saving.value = false;
  }
}

onMounted(async () => {
  await loadMeta();
  if (isEdit.value) {
    await loadEmployee();
    // Load Step 5 onboarding state
    try {
      const ob = await api<any>("GET", `/api/v1/employees/${editId.value}/onboarding`, { org: org() });
      if (ob.inductions?.length)         inductions.value        = ob.inductions;
      if (ob.document_checklist?.length) documents.value         = ob.document_checklist;
      if (ob.assets?.length)             assets.value            = ob.assets.map((a: any) => ({
        name: a.name, serial: a.serial_number ?? "", assigned_date: a.assigned_date ?? "",
      }));
    } catch { /* non-fatal — checklists are just empty */ }
    // Load already-uploaded documents
    try {
      const docs = await api<any>("GET", `/api/v1/employees/${editId.value}/documents`, { org: org() });
      uploadedDocs.value = docs?.data ?? [];
    } catch { /* non-fatal */ }
  }
});
</script>

<template>
  <div class="view ob-view">
    <!-- Page header -->
    <div class="view-header">
      <div>
        <nav class="ob-breadcrumb">HR / Employees / {{ isEdit ? "Edit Employee" : "Onboard New Employee" }}</nav>
        <h2>{{ isEdit ? "Edit Employee" : "Onboard New Employee" }}</h2>
        <p class="ob-subtitle">All data is handled in compliance with the Kenya Data Protection Act 2019.</p>
      </div>

      <!--
        Available at every step rather than only at the end, because the reason
        to stop halfway is that something is missing — a KRA PIN, a signed
        contract — and the form should not have to be retyped when it arrives.
      -->
      <div class="ob-header-actions">
        <button class="btn btn-ghost" type="button" :disabled="saving" @click="saveDraft">
          {{ saving ? "Saving…" : "Save as Draft" }}
        </button>
      </div>
    </div>

    <p v-if="!isEdit" class="ob-draft-note">
      Saved drafts stay off the employee list and out of headcount until
      onboarding is completed.
    </p>

    <p v-if="loading" class="status">Loading…</p>
    <p v-if="error"   class="error">{{ error }}</p>

    <!-- Step indicator -->
    <div class="ob-steps">
      <div
        v-for="(label, idx) in stepLabels" :key="idx"
        class="ob-step"
        :class="{ active: currentStep === idx + 1, done: currentStep > idx + 1 }"
        @click="goToStep(idx + 1)"
      >
        <div class="ob-step-circle">
          <span v-if="currentStep > idx + 1">✓</span>
          <span v-else>{{ idx + 1 }}</span>
        </div>
        <span class="ob-step-label">{{ label }}</span>
        <div v-if="idx < stepLabels.length - 1" class="ob-step-line" />
      </div>
    </div>

    <div v-if="stepErrors.length" class="ob-step-errors">
      <p v-for="e in stepErrors" :key="e">{{ e }}</p>
    </div>

    <!-- ═══════════════════  STEP 1 — Bio Data  ═══════════════════ -->
    <div v-show="currentStep === 1" class="ob-card">
      <div class="ob-card-header"><span class="ob-card-num">1</span> Personal Details</div>

      <div class="ob-field-full">
        <input v-model="bio.first_name"  type="text" class="input ob-name-input" placeholder="First name *" />
        <input v-model="bio.middle_name" type="text" class="input ob-name-input" placeholder="Middle name (optional)" />
        <input v-model="bio.last_name"   type="text" class="input ob-name-input" placeholder="Last name *" />
      </div>

      <div class="ob-grid-2">
        <!--
          Masked until asked for. This form is filled in at a desk with people
          walking past, and a KRA PIN on screen is a KRA PIN in the room. It is
          not a control — anyone who can open the form can reveal it — but it
          stops the number being read by somebody who merely walked behind the
          chair.
        -->
        <div class="form-field">
          <label class="ob-label">National ID / Passport <span class="ob-admin-badge">HR Admin only</span></label>
          <div class="ob-masked">
            <input
              v-model="bio.national_id"
              :type="revealed.national_id ? 'text' : 'password'"
              class="input" placeholder="12345678"
            />
            <button type="button" class="ob-reveal" @click="toggleReveal('national_id')">
              {{ revealed.national_id ? "Hide" : "Show" }}
            </button>
          </div>
        </div>
        <div class="form-field">
          <label class="ob-label">KRA PIN <span class="ob-admin-badge">HR Admin only</span></label>
          <div class="ob-masked">
            <input
              v-model="bio.tax_id"
              :type="revealed.tax_id ? 'text' : 'password'"
              class="input" placeholder="A000000000Z"
            />
            <button type="button" class="ob-reveal" @click="toggleReveal('tax_id')">
              {{ revealed.tax_id ? "Hide" : "Show" }}
            </button>
          </div>
        </div>
      </div>

      <div class="ob-grid-2">
        <div class="form-field">
          <label class="ob-label">Date of Birth</label>
          <input v-model="bio.date_of_birth" class="input" type="date" />
        </div>
        <div class="form-field">
          <label class="ob-label">Phone Number <span class="ob-req">*</span></label>
          <input v-model="bio.phone" type="tel" class="input" placeholder="+254712345678 or 0712345678" />
        </div>
      </div>

      <div class="ob-grid-2">
        <div class="form-field">
          <label class="ob-label">Gender</label>
          <select v-model="bio.gender" class="input">
            <option value="">— Select —</option>
            <option value="male">Male</option>
            <option value="female">Female</option>
            <option value="other">Other / Prefer not to say</option>
          </select>
        </div>
        <div class="form-field">
          <label class="ob-label">Marital Status</label>
          <select v-model="bio.marital_status" class="input">
            <option value="">— Select —</option>
            <option value="single">Single</option>
            <option value="married">Married</option>
            <option value="divorced">Divorced</option>
            <option value="widowed">Widowed</option>
          </select>
        </div>
      </div>

      <div class="form-field">
        <label class="ob-label">Email Address <span class="ob-req">*</span></label>
        <input v-model="bio.personal_email" class="input" type="email" placeholder="jane@example.com" />
      </div>

      <div class="form-field">
        <label class="ob-label">Work Email <span class="ob-hint">(ERP portal login)</span></label>
        <input v-model="bio.work_email" class="input" type="email" placeholder="firstname.lastname@company.co.ke" />
        <p class="ob-hint-text">Used for signing in to the ERP system. Leave blank if not yet assigned.</p>
      </div>

      <div class="form-field">
        <label class="ob-label">Residential Address</label>
        <textarea v-model="bio.address" class="input ob-textarea" rows="2" placeholder="Street / Estate, Area" />
      </div>

      <div class="ob-grid-2">
        <div class="form-field">
          <label class="ob-label">City</label>
          <input v-model="bio.city" type="text" class="input" placeholder="Nairobi" />
        </div>
        <!-- Beside the country, which on its own is too coarse for anything a
             Kenyan employer files. -->
        <div class="form-field">
          <label class="ob-label">County</label>
          <input v-model="bio.county" type="text" class="input" placeholder="Nairobi" />
        </div>
        <div class="form-field">
          <label class="ob-label">Country</label>
          <input v-model="bio.country" type="text" class="input" placeholder="KE" maxlength="3" />
        </div>
      </div>

      <div class="ob-nav">
        <router-link :to="{ name: 'employees' }" class="btn btn-ghost">Cancel</router-link>
        <button class="btn btn-primary" type="button" @click="goNext">Next →</button>
      </div>
    </div>

    <!-- ═══════════  STEP 2 — Employment Details  ═══════════ -->
    <div v-show="currentStep === 2" class="ob-card">
      <div class="ob-card-header"><span class="ob-card-num">2</span> Employment Details</div>

      <div class="ob-grid-2">
        <div class="form-field">
          <label class="ob-label">Employment Type <span class="ob-req">*</span></label>
          <select v-model="employment.employment_type" class="input">
            <option value="">Select…</option>
            <option value="full_time">Full Time</option>
            <option value="part_time">Part Time</option>
            <option value="contract">Contract</option>
            <option value="attachment">Attachment</option>
            <option value="intern">Internship</option>
            <option value="casual">Casual</option>
          </select>
        </div>
        <div class="form-field">
          <label class="ob-label">Start Date <span class="ob-req">*</span></label>
          <input v-model="employment.date_hired" class="input" type="date" />
        </div>
      </div>

      <div class="ob-grid-2">
        <div class="form-field">
          <label class="ob-label">Job Role</label>
          <select v-model="employment.designation_id" class="input">
            <option value="">Select role…</option>
            <option v-for="d in designations" :key="d.id" :value="d.id">{{ d.title }}</option>
          </select>
        </div>
        <div class="form-field">
          <label class="ob-label">Department / Unit</label>
          <select v-model="employment.department_id" class="input">
            <option value="">Select unit…</option>
            <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
          </select>
        </div>
      </div>

      <div class="ob-grid-2">
        <div class="form-field">
          <label class="ob-label">Reporting Manager</label>
          <select v-model="employment.reports_to" class="input">
            <option value="">Select manager…</option>
            <option v-for="m in managers" :key="m.id" :value="m.id">
              {{ m.first_name }} {{ m.last_name }} ({{ m.employee_number }})
            </option>
          </select>
        </div>
        <div class="form-field">
          <label class="ob-label">Cadre / Job Grade</label>
          <select v-model="employment.job_grade_id" class="input">
            <option value="">Select cadre…</option>
            <option v-for="g in jobGrades" :key="g.id" :value="g.id">{{ g.name }} (Level {{ g.level }})</option>
          </select>
        </div>
      </div>

      <div class="ob-grid-2">
        <div class="form-field">
          <label class="ob-label">Benefit Plan</label>
          <input v-model="employment.benefit_plan" type="text" class="input" placeholder="e.g. Standard Medical Plan A" />
        </div>
        <div class="form-field">
          <label class="ob-label">Salary / Rate (KES) <span class="ob-admin-badge">HR Admin only</span></label>
          <input v-model="employment.basic_salary" class="input" type="number" min="0" placeholder="e.g. 85000" />
        </div>
      </div>

      <div class="ob-grid-2">
        <div class="form-field">
          <label class="ob-label">Status</label>
          <select v-model="employment.status" class="input">
            <option value="active">Active</option>
            <option value="probation">Probation</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>
        <div class="form-field">
          <label class="ob-label">Probation End Date</label>
          <input v-model="employment.probation_end_date" class="input" type="date" />
        </div>
      </div>

      <!-- Duration / End Date — Contract, Attachment, Internship only -->
      <div v-if="needsEndDate" class="ob-duration-section">
        <label class="ob-label">Duration</label>
        <div class="duration-chips">
          <button
            v-for="opt in [{label:'2 months',val:'2'},{label:'3 months',val:'3'},{label:'6 months',val:'6'},{label:'9 months',val:'9'},{label:'12 months',val:'12'},{label:'Other',val:'other'}]"
            :key="opt.val" type="button"
            class="duration-chip" :class="{ active: contractDuration === opt.val }"
            @click="contractDuration = opt.val">
            {{ opt.label }}
          </button>
        </div>
        <div v-if="contractDuration === 'other'" class="other-months-row">
          <input v-model.number="contractOtherMonths" type="number" min="1" max="120" class="input"
            placeholder="Number of months" style="max-width:180px" />
          <span class="ob-hint" style="margin-left:8px">months from start date</span>
        </div>
        <div class="form-field" style="margin-top:14px">
          <label class="ob-label">Contract End Date
            <span v-if="contractDuration" class="ob-hint"> — auto-calculated · editable</span>
          </label>
          <input v-model="employment.contract_end_date" class="input" type="date" style="max-width:220px" />
        </div>
      </div>

      <!-- Primary Organisation -->
      <div class="ob-section">
        <div class="ob-section-title">
          Primary Organisation <span class="ob-hint">(toggle to set home organisation)</span>
        </div>
        <div class="ob-org-toggle">
          <label
            v-for="o in allOrgs" :key="o.id"
            class="ob-org-pill"
            :class="{ selected: employment.primary_org_id === o.id }"
          >
            <input type="radio" :value="o.id" v-model="employment.primary_org_id" style="display:none" />
            <span class="ob-org-radio" />
            {{ o.name }}
          </label>
        </div>
      </div>

      <!-- Secondments -->
      <div class="ob-section">
        <div class="ob-section-title" style="display:flex;justify-content:space-between;align-items:center">
          <span>Secondary Assignments <span class="ob-hint">(Secondments)</span></span>
          <button type="button" class="btn btn-secondary btn-sm" @click="addSecondment">+ Add Secondment</button>
        </div>

        <div v-if="employment.secondments.length === 0" class="ob-empty-note">
          No secondments added. Employee is assigned to primary organisation only.
        </div>

        <div v-for="(s, i) in employment.secondments" :key="i" class="ob-secondment-row">
          <div class="ob-secondment-meta">
            <div class="form-field" style="flex:2">
              <label class="ob-label">Organisation</label>
              <select
                class="input"
                :value="s.org_id || ''"
                @change="onSecondmentOrgChange(s, Number(($event.target as HTMLSelectElement).value))"
              >
                <option value="">Select organisation…</option>
                <option
                  v-for="o in allOrgs.filter(o => o.id !== employment.primary_org_id)"
                  :key="o.id" :value="o.id"
                >{{ o.name }}</option>
              </select>
            </div>
            <div class="form-field" style="flex:2">
              <label class="ob-label">Role in Organisation</label>
              <input v-model="s.role" type="text" class="input" placeholder="e.g. Project Lead" />
            </div>
            <div class="form-field" style="flex:1">
              <label class="ob-label">Allocation %</label>
              <input v-model.number="s.allocation_pct" class="input" type="number" min="0" max="100" />
            </div>
          </div>
          <div class="ob-secondment-actions">
            <button type="button" class="btn btn-secondary btn-sm" @click="openScope(s)">⚙ Edit Scope</button>
            <button type="button" class="btn btn-ghost btn-sm ob-danger-btn" @click="removeSecondment(i)">Remove</button>
          </div>
          <div v-if="s.scope || s.is_billable || s.start_date" class="ob-secondment-tags">
            <span v-if="s.scope"       class="badge badge-blue">{{ s.scope }}</span>
            <span v-if="s.is_billable" class="badge badge-teal">Billable</span>
            <span v-if="s.start_date"  class="badge badge-gray">From {{ s.start_date }}</span>
            <span v-if="s.end_date"    class="badge badge-gray">To {{ s.end_date }}</span>
          </div>
        </div>
      </div>

      <div class="ob-nav">
        <button class="btn btn-ghost" type="button" @click="goBack">← Back</button>
        <button class="btn btn-primary" type="button" @click="goNext">Next →</button>
      </div>
    </div>

    <!-- ═══════════════════  STEP 3 — Benefits  ═══════════════════ -->
    <div v-show="currentStep === 3" class="ob-card">
      <div class="ob-card-header"><span class="ob-card-num">3</span> Benefits</div>

      <div class="ob-grid-2">
        <div class="form-field">
          <label class="ob-label">NSSF Number</label>
          <input v-model="benefits.nssf_number" type="text" class="input" placeholder="NSSF membership number" />
        </div>
        <div class="form-field">
          <label class="ob-label">NHIF Number</label>
          <input v-model="benefits.nhif_number" type="text" class="input" placeholder="NHIF membership number" />
        </div>
      </div>

      <div class="ob-grid-2">
        <div class="form-field">
          <label class="ob-label">Medical Plan</label>
          <input v-model="benefits.medical_plan" type="text" class="input" placeholder="e.g. Jubilee Health – Family" />
        </div>
        <div class="form-field">
          <label class="ob-label">Pension Provider</label>
          <input v-model="benefits.pension_provider" type="text" class="input" placeholder="e.g. Sanlam Kenya" />
        </div>
      </div>

      <div class="ob-section">
        <div class="ob-section-title">Benefit Entitlements</div>
        <div class="ob-checklist-grid">
          <label class="ob-check-row"><input type="checkbox" v-model="benefits.medical"        class="ob-checkbox" /> <span>Medical Cover</span></label>
          <label class="ob-check-row"><input type="checkbox" v-model="benefits.life_insurance" class="ob-checkbox" /> <span>Group Life Insurance</span></label>
          <label class="ob-check-row"><input type="checkbox" v-model="benefits.pension"        class="ob-checkbox" /> <span>Pension / Provident Fund</span></label>
          <label class="ob-check-row"><input type="checkbox" v-model="benefits.wiba"           class="ob-checkbox" /> <span>WIBA (Work Injury Benefits)</span></label>
          <label class="ob-check-row"><input type="checkbox" v-model="benefits.group_life"     class="ob-checkbox" /> <span>Group Personal Accident</span></label>
        </div>
      </div>

      <div class="form-field">
        <label class="ob-label">Benefits Effective Date</label>
        <input v-model="benefits.benefits_effective_date" class="input" type="date" style="max-width:220px" />
      </div>

      <div class="ob-nav">
        <button class="btn btn-ghost" type="button" @click="goBack">← Back</button>
        <button class="btn btn-primary" type="button" @click="goNext">Next →</button>
      </div>
    </div>

    <!-- ═══════════  STEP 4 — Next of Kin & Compliance  ═══════════ -->
    <div v-show="currentStep === 4" class="ob-card">
      <div class="ob-card-header"><span class="ob-card-num">4</span> Next of Kin &amp; Compliance</div>

      <div class="ob-section">
        <div class="ob-section-title" style="display:flex;justify-content:space-between;align-items:center">
          <span>Next of Kin</span>
          <button type="button" class="btn btn-secondary btn-sm" @click="addNok">+ Add Contact</button>
        </div>

        <div v-for="(nok, i) in nokList" :key="i" class="ob-nok-row">
          <div class="ob-nok-header">
            <span class="ob-nok-num">{{ i + 1 }}. {{ nok.name || "New Contact" }}</span>
            <div style="display:flex;gap:8px;align-items:center">
              <label class="ob-primary-label">
                <input type="radio" name="nok-primary" :checked="nok.is_primary" @change="setPrimaryNok(i)" />
                Primary
              </label>
              <button v-if="nokList.length > 1" type="button" class="btn btn-ghost btn-sm ob-danger-btn" @click="removeNok(i)">Remove</button>
            </div>
          </div>
          <div class="ob-grid-2">
            <div class="form-field">
              <label class="ob-label">Full Name <span class="ob-req">*</span></label>
              <input v-model="nok.name" type="text" class="input" placeholder="e.g. Jane Kamau" />
            </div>
            <div class="form-field">
              <label class="ob-label">Relationship <span class="ob-req">*</span></label>
              <select v-model="nok.relationship" class="input">
                <option value="">— Select —</option>
                <option value="spouse">Spouse</option>
                <option value="parent">Parent</option>
                <option value="sibling">Sibling</option>
                <option value="child">Child</option>
                <option value="guardian">Guardian</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div class="form-field">
              <label class="ob-label">Phone</label>
              <input v-model="nok.phone" type="tel" class="input" placeholder="+254 700 000 000" />
            </div>
            <div class="form-field">
              <label class="ob-label">Email</label>
              <input v-model="nok.email" class="input" type="email" placeholder="nok@email.com" />
            </div>
          </div>
        </div>
      </div>

      <div class="ob-section">
        <div class="ob-section-title">Statutory Compliance</div>
        <div class="ob-grid-2">
          <div class="form-field">
            <label class="ob-label">NSSF Registration</label>
            <select v-model="compliance.nssf_registered" class="input">
              <option :value="false">Not Registered</option>
              <option :value="true">Registered</option>
            </select>
          </div>
          <div class="form-field">
            <label class="ob-label">NHIF Registration</label>
            <select v-model="compliance.nhif_registered" class="input">
              <option :value="false">Not Registered</option>
              <option :value="true">Registered</option>
            </select>
          </div>
          <div class="form-field">
            <label class="ob-label">KRA PIN Status</label>
            <select v-model="compliance.kra_registered" class="input">
              <option :value="false">Not Registered</option>
              <option :value="true">Registered</option>
            </select>
          </div>
          <div class="form-field">
            <label class="ob-label">Work Permit Required</label>
            <select v-model="compliance.has_work_permit" class="input">
              <option :value="false">No</option>
              <option :value="true">Yes</option>
            </select>
          </div>
        </div>
        <div v-if="compliance.has_work_permit" class="ob-grid-2">
          <div class="form-field">
            <label class="ob-label">Work Permit Number</label>
            <input v-model="compliance.work_permit_number" type="text" class="input" placeholder="WP/2026/XXXXX" />
          </div>
          <div class="form-field">
            <label class="ob-label">Work Permit Expiry</label>
            <input v-model="compliance.work_permit_expiry" class="input" type="date" />
          </div>
        </div>
      </div>

      <div class="ob-nav">
        <button class="btn btn-ghost" type="button" @click="goBack">← Back</button>
        <button class="btn btn-primary" type="button" @click="goNext">Next →</button>
      </div>
    </div>

    <!-- ═══════════  STEP 5 — Onboarding Next Steps  ═══════════ -->
    <div v-show="currentStep === 5" class="ob-card">
      <div class="ob-card-header"><span class="ob-card-num">5</span> Onboarding Steps</div>

      <div class="ob-section">
        <div class="ob-section-title">HR Inductions</div>
        <div class="ob-checklist">
          <div v-for="(item, i) in inductions" :key="i" class="ob-checklist-item">
            <label class="ob-check-row">
              <input type="checkbox" v-model="item.done" class="ob-checkbox" />
              <span :class="{ 'ob-done': item.done }">{{ item.label }}</span>
            </label>
            <input v-if="item.done" v-model="item.notes" type="text" class="input ob-notes-input" placeholder="Notes / date completed…" />
          </div>
        </div>
      </div>

      <div class="ob-section">
        <div class="ob-section-title">Documents Required</div>
        <div class="ob-checklist">
          <div v-for="(doc, i) in documents" :key="i" class="ob-checklist-item">
            <label class="ob-check-row">
              <input type="checkbox" v-model="doc.done" class="ob-checkbox" />
              <span :class="{ 'ob-done': doc.done }">{{ doc.label }}</span>
            </label>
            <input v-if="doc.done" v-model="doc.notes" type="text" class="input ob-notes-input" placeholder="Notes / date received…" />
          </div>
        </div>
      </div>

      <div class="ob-section">
        <div class="ob-section-title" style="display:flex;justify-content:space-between;align-items:center">
          <span>Assets Assigned</span>
          <button type="button" class="btn btn-secondary btn-sm" @click="addAsset">+ Add Asset</button>
        </div>
        <div v-if="assets.length === 0" class="ob-empty-note">No assets assigned yet.</div>
        <div v-for="(a, i) in assets" :key="i" class="ob-asset-row">
          <div class="form-field" style="flex:2">
            <label class="ob-label">Asset Name</label>
            <input v-model="a.name" type="text" class="input" placeholder="e.g. Laptop Dell XPS 15" />
          </div>
          <div class="form-field" style="flex:1">
            <label class="ob-label">Serial / Tag #</label>
            <input v-model="a.serial" type="text" class="input" placeholder="SN-XXXX" />
          </div>
          <div class="form-field" style="flex:1">
            <label class="ob-label">Assigned Date</label>
            <input v-model="a.assigned_date" class="input" type="date" />
          </div>
          <button type="button" class="btn btn-ghost btn-sm ob-asset-del ob-danger-btn" @click="removeAsset(i)">✕</button>
        </div>
      </div>

      <!-- Document File Upload -->
      <div class="ob-section">
        <div class="ob-section-title">Upload Documents <span class="ob-hint">(stored securely — private access only)</span></div>

        <!-- Already-uploaded docs (edit mode) -->
        <div v-if="uploadedDocs.length > 0" class="ob-uploaded-list">
          <div v-for="doc in uploadedDocs" :key="doc.id" class="ob-uploaded-item">
            <span class="badge badge-blue">{{ doc.category }}</span>
            <span class="ob-uploaded-name">{{ doc.original_name }}</span>
          </div>
        </div>

        <!-- Queue new uploads -->
        <div class="ob-upload-row">
          <select v-model="newUploadCategory" class="input ob-upload-select">
            <option v-for="c in UPLOAD_CATEGORIES" :key="c.value" :value="c.value">{{ c.label }}</option>
          </select>
          <input id="doc-file-input" type="file"
            accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx"
            class="ob-file-input"
            @change="onFileSelected" />
          <button type="button" class="btn btn-secondary btn-sm" @click="queueUpload">+ Queue</button>
        </div>
        <p v-if="uploadError" class="ob-upload-error">{{ uploadError }}</p>
        <p class="ob-hint-text">PDF, JPG, PNG, WEBP, DOC, DOCX · max 10 MB · files are uploaded on final submit</p>

        <div v-if="pendingUploads.length > 0" class="ob-pending-list">
          <div v-for="(u, i) in pendingUploads" :key="i" class="ob-pending-item">
            <span class="badge badge-teal">{{ u.category }}</span>
            <span class="ob-uploaded-name">{{ u.label }}</span>
            <button type="button" class="btn btn-ghost btn-sm ob-danger-btn" @click="removePendingUpload(i)">✕</button>
          </div>
        </div>
      </div>

      <div class="ob-nav">
        <button class="btn btn-ghost" type="button" @click="goBack">← Back</button>
        <button class="btn btn-primary" type="button" :disabled="saving" @click="submit">
          {{ saving ? "Saving…" : isEdit ? "Save Changes" : "Complete Onboarding" }}
        </button>
      </div>
    </div>

    <!-- ══════════════  SCOPE OF ENGAGEMENT MODAL  ══════════════ -->
    <Teleport to="body">
      <div v-if="scopeModal && scopeTarget" class="ob-modal-overlay" @click.self="closeScope">
        <div class="ob-modal">
          <div class="ob-modal-header">
            <h3>Scope of Engagement</h3>
            <button class="ob-modal-close" type="button" @click="closeScope">✕</button>
          </div>
          <div class="ob-modal-body">
            <p class="ob-modal-sub">
              Define the terms of this secondment to
              <strong>{{ scopeTarget.org_name || "the selected organisation" }}</strong>.
            </p>

            <!--
              Bound to engagement_type, not scope. These are reasons somebody is
              in a second organisation; `access_scope` is what they may do once
              there (operational, read only, admin). Writing one into the other
              meant a "full secondment" also silently described their
              permissions.
            -->
            <div class="form-field">
              <label class="ob-label">Engagement Type</label>
              <select v-model="scopeTarget.engagement_type" class="input">
                <option value="secondment">Full Secondment — works entirely in secondary org</option>
                <option value="partial">Partial Secondment — split time between orgs</option>
                <option value="shared_service">Shared Service — serves both orgs</option>
                <option value="project">Project Based — specific project engagement</option>
                <option value="advisory">Advisory / Consulting — advisory role only</option>
                <option value="coverage">Coverage — temporary coverage / acting role</option>
              </select>
            </div>

            <div class="ob-grid-2">
              <div class="form-field">
                <label class="ob-label">Start Date</label>
                <input v-model="scopeTarget.start_date" class="input" type="date" />
              </div>
              <div class="form-field">
                <label class="ob-label">End Date <span class="ob-hint">(blank = indefinite)</span></label>
                <input v-model="scopeTarget.end_date" class="input" type="date" />
              </div>
            </div>

            <div class="ob-grid-2">
              <div class="form-field">
                <label class="ob-label">Time Allocation (%)</label>
                <!-- Nought would be a secondment that is not happening, so the
                     floor is one — the same rule the server applies. -->
                <input
                  v-model.number="scopeTarget.allocation_pct"
                  class="input" type="number" min="1" :max="allocationLeft"
                />
                <p class="ob-hint-text">
                  e.g. 50 = half time in this org.
                  {{ allocationLeft }}% of their week is still unassigned.
                </p>
              </div>
              <div class="form-field">
                <label class="ob-label">Billable to Secondary Org?</label>
                <select v-model="scopeTarget.is_billable" class="input">
                  <option :value="false">No — internal arrangement</option>
                  <option :value="true">Yes — cost billed to secondary org</option>
                </select>
              </div>
            </div>

            <div class="form-field">
              <label class="ob-label">Notes / Purpose</label>
              <textarea v-model="scopeTarget.notes" class="input ob-textarea" rows="3"
                placeholder="Describe the purpose, deliverables or any special terms…" />
            </div>
          </div>
          <div class="ob-modal-footer">
            <button class="btn btn-ghost"    type="button" @click="closeScope">Cancel</button>
            <button class="btn btn-primary"  type="button" @click="closeScope">Save Scope</button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.ob-breadcrumb { font-size: 12px; color: var(--color-text-muted); margin-bottom: 2px; }
.ob-subtitle   { font-size: 13px; color: var(--color-text-muted); margin-top: 2px; }

.ob-header-actions { display: flex; gap: 8px; align-items: center; }
.ob-draft-note { font-size: 12px; color: var(--color-text-light); margin: 4px 0 0; }

/* Masked identifiers */
.ob-masked { display: flex; align-items: center; gap: 8px; }
.ob-masked .input { flex: 1; min-width: 0; }
.ob-reveal {
  flex-shrink: 0; border: none; background: none; padding: 0; cursor: pointer;
  font-family: inherit; font-size: 12px; color: var(--color-brand-on-surface);
}
.ob-reveal:hover { text-decoration: underline; }

/* Step indicator */
.ob-steps { display: flex; align-items: flex-start; padding: 24px 0; }
.ob-step  { display: flex; flex-direction: column; align-items: center; flex: 1; position: relative; cursor: default; }
.ob-step.done { cursor: pointer; }
.ob-step-circle {
  width: 32px; height: 32px; border-radius: 50%;
  background: var(--color-neutral-200); color: var(--color-text-muted);
  display: flex; align-items: center; justify-content: center;
  font-size: 13px; font-weight: 600; border: 2px solid var(--color-neutral-300);
  position: relative; z-index: 1; transition: all .2s;
}
.ob-step.active .ob-step-circle { background: var(--color-brand-base); color: #fff; border-color: var(--color-brand-base); }
.ob-step.done   .ob-step-circle { background: #0d9488; color: #fff; border-color: #0d9488; }
.ob-step-label  { font-size: 11px; margin-top: 6px; color: var(--color-text-muted); text-align: center; white-space: nowrap; }
.ob-step.active .ob-step-label  { color: var(--color-brand-base); font-weight: 600; }
.ob-step.done   .ob-step-label  { color: #0d9488; }
.ob-step-line {
  position: absolute; top: 15px; left: calc(50% + 18px);
  width: calc(100% - 36px); height: 2px; background: var(--color-neutral-300);
}
.ob-step.done .ob-step-line { background: #0d9488; }

/* Errors */
.ob-step-errors { background: var(--color-error-light); border: 1px solid rgba(211,47,47,.25); border-radius: var(--radius-lg); padding: 10px 16px; margin-bottom: 12px; }
.ob-step-errors p { margin: 2px 0; font-size: 13px; color: var(--color-error); }

/* Card */
.ob-card { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: 28px 32px; margin-bottom: 24px; }
.ob-card-header { display: flex; align-items: center; gap: 10px; font-size: 16px; font-weight: 600; margin-bottom: 24px; }
.ob-card-num { width: 28px; height: 28px; border-radius: 50%; background: var(--color-brand-base); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; flex-shrink: 0; }

/* Grids */
.ob-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-4); margin-bottom: var(--space-4); }
.ob-field-full { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: var(--space-3); margin-bottom: var(--space-4); }
.ob-name-input { min-width: 0; }

/* Labels / hints */
.ob-label     { font-size: var(--font-size-sm); font-weight: var(--font-weight-semibold); color: var(--color-text); display: block; margin-bottom: 5px; }
.ob-req       { color: var(--color-error); margin-left: 2px; }
.ob-hint      { font-size: 11px; color: var(--color-text-muted); font-weight: var(--font-weight-regular); margin-left: 4px; }
.ob-hint-text { font-size: var(--font-size-sm); color: var(--color-text-muted); margin-top: 4px; line-height: var(--line-height-relaxed); }
.ob-admin-badge { font-size: 10px; background: var(--color-brand-light); color: var(--color-brand-base); padding: 1px 7px; border-radius: 10px; font-weight: var(--font-weight-medium); margin-left: 5px; vertical-align: middle; }
.ob-textarea  { resize: vertical; font-family: var(--font-family); }

/* Sections */
.ob-section       { margin-top: 24px; margin-bottom: 8px; }
.ob-section-title { font-size: 13px; font-weight: 600; border-bottom: 1px solid var(--color-neutral-200); padding-bottom: 8px; margin-bottom: 16px; }
.ob-empty-note    { font-size: 13px; color: var(--color-text-muted); padding: 12px 0; }

/* Primary org toggle */
.ob-org-toggle { display: flex; flex-wrap: wrap; gap: 10px; }
.ob-org-pill   { display: flex; align-items: center; gap: 8px; padding: 8px 16px; border: 2px solid var(--color-neutral-300); border-radius: 20px; cursor: pointer; font-size: 13px; transition: all .15s; user-select: none; }
.ob-org-pill.selected { border-color: var(--color-brand-base); background: var(--color-brand-light); color: var(--color-brand-base); font-weight: 600; }
.ob-org-radio { width: 14px; height: 14px; border-radius: 50%; border: 2px solid currentColor; display: inline-block; flex-shrink: 0; }

/* Secondment */
.ob-secondment-row     { border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: 16px; margin-bottom: 12px; background: var(--color-neutral-50); }
.ob-secondment-meta    { display: flex; gap: 12px; align-items: flex-end; }
.ob-secondment-actions { display: flex; gap: 8px; margin-top: 10px; }
.ob-secondment-tags    { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; }

/* Checklists */
.ob-checklist      { display: flex; flex-direction: column; gap: 10px; }
.ob-checklist-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.ob-checklist-item { display: flex; flex-direction: column; gap: 6px; }
.ob-check-row      { display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 13px; }
.ob-checkbox       { width: 16px; height: 16px; accent-color: var(--color-brand-base); flex-shrink: 0; }
.ob-done           { text-decoration: line-through; color: var(--color-text-muted); }
.ob-notes-input    { font-size: 12px; padding: 4px 8px; }
.ob-primary-label  { display: flex; align-items: center; gap: 4px; font-size: 12px; cursor: pointer; }

/* Assets / NOK */
.ob-asset-row { display: flex; gap: 12px; align-items: flex-end; margin-bottom: 12px; }
.ob-asset-del { align-self: flex-end; margin-bottom: 2px; }
.ob-nok-row   { border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: 16px; margin-bottom: 12px; background: var(--color-neutral-50); }
.ob-nok-header{ display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; }
.ob-nok-num   { font-size: 13px; font-weight: 600; }
.ob-danger-btn { color: var(--color-error) !important; }

/* Navigation */
.ob-nav { display: flex; justify-content: space-between; align-items: center; margin-top: 28px; }

/* Modal */
.ob-modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.45); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.ob-modal         { background: #fff; border-radius: var(--radius-lg); width: min(600px,95vw); max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,.25); }
.ob-modal-header  { display: flex; justify-content: space-between; align-items: center; padding: 20px 24px 16px; border-bottom: 1px solid var(--color-neutral-200); }
.ob-modal-header h3 { margin: 0; font-size: 16px; }
.ob-modal-close   { background: none; border: none; font-size: 18px; cursor: pointer; color: var(--color-text-muted); line-height:1; padding: 4px; }
.ob-modal-sub     { font-size: 13px; color: var(--color-text-muted); margin-bottom: 18px; }
.ob-modal-body    { padding: 20px 24px; }
.ob-modal-footer  { display: flex; justify-content: flex-end; gap: 10px; padding: 16px 24px; border-top: 1px solid var(--color-neutral-200); }

/* Duration picker */
.ob-duration-section { margin-bottom: var(--space-4); }
.duration-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 6px; margin-bottom: 12px; }
.duration-chip {
  padding: 6px 16px; border-radius: 20px; border: 2px solid var(--color-neutral-300);
  background: #fff; font-size: 13px; cursor: pointer; transition: all .15s; color: var(--color-text);
}
.duration-chip:hover  { border-color: var(--color-brand-base); color: var(--color-brand-base); }
.duration-chip.active { border-color: var(--color-brand-base); background: var(--color-brand-light); color: var(--color-brand-base); font-weight: 600; }
.other-months-row { display: flex; align-items: center; margin-bottom: 4px; }

@media (max-width: 680px) {
  .ob-grid-2 { grid-template-columns: 1fr; }
  .ob-field-full { flex-direction: column; }
  .ob-step-label { display: none; }
  .ob-secondment-meta { flex-direction: column; }
  .ob-asset-row { flex-direction: column; }
  .ob-card { padding: 18px 16px; }
  .ob-upload-row { flex-direction: column; align-items: stretch; }
}

/* Document upload */
.ob-upload-row    { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 6px; }
.ob-upload-select { flex: 1; min-width: 160px; }
.ob-file-input    { flex: 2; font-size: 13px; min-width: 0; }
.ob-upload-error  { font-size: 13px; color: var(--color-error); margin: 4px 0; }
.ob-pending-list, .ob-uploaded-list {
  display: flex; flex-direction: column; gap: 6px; margin-top: 10px;
}
.ob-pending-item, .ob-uploaded-item {
  display: flex; align-items: center; gap: 10px;
  background: var(--color-neutral-50); border: 1px solid var(--color-border);
  border-radius: var(--radius-md); padding: 6px 12px;
}
.ob-uploaded-name { font-size: 13px; flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
</style>
