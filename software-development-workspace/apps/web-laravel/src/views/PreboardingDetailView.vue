<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
import { useRouter } from "vue-router";
import { api } from "../lib/api";
import { currentOrgSlug, authStore } from "../stores/auth";

// ─── Meta types ──────────────────────────────────────────────────────────────
interface Dept        { id: number; name: string; }
interface Designation { id: number; title: string; }
interface JobGrade    { id: number; name: string; level: number; }
interface Employee    { id: number; first_name: string; last_name: string; employee_number: string; }

const props = defineProps<{ id: string }>();
const router = useRouter();

// ─── Types ───────────────────────────────────────────────────────────────────
interface Section {
  id: number; section: string; status: string;
  data: Record<string, unknown> | null;
  hr_notes: string | null; reviewed_at: string | null; submitted_at: string | null;
}
interface Reference {
  id: number; referee_name: string; referee_email: string;
  referee_company: string | null; referee_job_title: string | null;
  relationship: string | null; status: string;
  invited_at: string | null; submitted_at: string | null;
}
interface Attachment {
  id: number; category: string; original_name: string; mime_type: string | null; size_bytes: number | null; created_at: string;
}
interface PreboardingRecord {
  id: number; first_name: string; last_name: string; email: string; phone: string | null;
  position: string | null; expected_start_date: string | null; status: string;
  employee_type: string | null;
  welcome_sent_at: string | null; announcement_sent_at: string | null; notes: string | null;
  employee_id: number | null;
  token: string | null; token_expires_at: string | null; token_used_at: string | null;
  link_expiry_days: number; link_resend_count: number;
  medical_cover_eligible: boolean;
  form_config: { enabled_sections?: string[] } | null;
  sections: Section[]; references: Reference[]; attachments: Attachment[];
}

// ─── State ───────────────────────────────────────────────────────────────────
const record  = ref<PreboardingRecord | null>(null);
const loading = ref(false);
const error   = ref("");
const saving  = ref(false);

const activeTab = ref<string>("welcome");

// Meta for employment dropdowns
const departments  = ref<Dept[]>([]);
const designations = ref<Designation[]>([]);
const jobGrades    = ref<JobGrade[]>([]);
const managers     = ref<Employee[]>([]);
const allOrgs      = computed(() => authStore.organizations);

const SECTION_TABS: Array<{ key: string; label: string; hrOnly?: boolean }> = [
  { key: "self_intro",      label: "Self Introduction" },
  { key: "personal",        label: "Personal Details" },
  { key: "contact",         label: "Contact Details" },
  { key: "emergency",       label: "Emergency Contacts" },
  { key: "dependents",      label: "Dependents" },
  { key: "immigration",     label: "Immigration" },
  { key: "qualifications",  label: "Qualifications" },
  { key: "memberships",     label: "Memberships" },
  { key: "attachments",     label: "Forms & Attachments" },
  { key: "employment",      label: "Employment", hrOnly: true },
];

const SECTION_FIELDS: Record<string, Array<{ key: string; label: string; type?: string; options?: string[] }>> = {
  self_intro: [
    { key: "summary",    label: "About Me (background & skills)",     type: "textarea" },
    { key: "motivation", label: "Why I joined",                        type: "textarea" },
    { key: "interests",  label: "Hobbies & Interests",                 type: "textarea" },
    { key: "fun_fact",   label: "Fun Fact about me",                   type: "text" },
  ],
  personal: [
    { key: "first_name",     label: "First Name",              type: "text" },
    { key: "last_name",      label: "Last Name",               type: "text" },
    { key: "other_names",    label: "Other Names",             type: "text" },
    { key: "dob",            label: "Date of Birth",           type: "date" },
    { key: "gender",         label: "Gender",                  type: "select", options: ["male","female","other","prefer_not_to_say"] },
    { key: "marital_status", label: "Marital Status",          type: "select", options: ["single","married","divorced","widowed"] },
    { key: "id_type",        label: "ID Type",                 type: "select", options: ["national_id","passport"] },
    { key: "id_number",      label: "ID / Passport Number",    type: "text" },
    { key: "kra_pin",        label: "KRA PIN",                 type: "text" },
    { key: "nssf_no",        label: "NSSF Number",             type: "text" },
    { key: "nhif_no",        label: "NHIF / SHA Number",       type: "text" },
    { key: "nationality",    label: "Nationality",             type: "text" },
  ],
  contact: [
    { key: "address",              label: "Home Address",           type: "text" },
    { key: "email",                label: "Personal Email",         type: "email" },
    { key: "phone",                label: "Phone Number",           type: "text" },
    { key: "phone_alt",            label: "Alt. Phone",             type: "text" },
    { key: "city",                 label: "City",                   type: "text" },
    { key: "county",               label: "County / State",         type: "text" },
    { key: "country_of_residence", label: "Country of Residence",   type: "text" },
    { key: "postal_code",          label: "Postal Code",            type: "text" },
  ],
  emergency: [
    // Health
    { key: "blood_group",      label: "Blood Group",            type: "select", options: ["A+","A-","B+","B-","AB+","AB-","O+","O-","Unknown","Not disclosed"] },
    { key: "disability",       label: "Disability",             type: "text" },
    { key: "allergies",        label: "Allergies",              type: "textarea" },
    // Emergency contact
    { key: "name",             label: "Emergency Contact Name", type: "text" },
    { key: "relationship",     label: "Relationship",           type: "text" },
    { key: "phone",            label: "Phone",                  type: "text" },
    { key: "phone_alt",        label: "Alt. Phone",             type: "text" },
    { key: "address",          label: "Address",                type: "text" },
    { key: "preferred_contact",label: "Preferred Contact",      type: "select", options: ["phone","whatsapp","email"] },
  ],
  // dependents / qualifications / memberships are list-based; rendered separately
  dependents:     [],
  immigration: [
    { key: "passport_no",               label: "Passport Number",        type: "text" },
    { key: "passport_expiry",           label: "Passport Expiry",        type: "date" },
    { key: "passport_country_of_issue", label: "Country of Issue",       type: "text" },
    { key: "work_permit_no",            label: "Work Permit No.",        type: "text" },
    { key: "work_permit_expiry",        label: "Permit Expiry",          type: "date" },
    { key: "visa_type",                 label: "Visa Type",              type: "text" },
    { key: "visa_expiry",               label: "Visa Expiry",            type: "date" },
  ],
  qualifications: [],
  memberships:    [],
  attachments:    [
    { key: "notes", label: "Notes / Instructions for candidate", type: "textarea" },
  ],
  // ── Employment (HR-internal) — rendered by custom template, not field loop ──
  employment: [],
};

// ─── Load ─────────────────────────────────────────────────────────────────────
async function load() {
  loading.value = true; error.value = "";
  try {
    record.value = await api<PreboardingRecord>("GET", `/api/v1/preboarding/${props.id}`, { org: currentOrgSlug.value! });
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to load.";
  } finally {
    loading.value = false;
  }
}

async function loadMeta() {
  try {
    const [d, des, g, emps] = await Promise.all([
      api<{ data: Dept[] }>("GET",        "/api/v1/org/departments",  { org: currentOrgSlug.value! }),
      api<{ data: Designation[] }>("GET", "/api/v1/org/designations", { org: currentOrgSlug.value! }),
      api<{ data: JobGrade[] }>("GET",    "/api/v1/org/job-grades",   { org: currentOrgSlug.value! }),
      api<any>("GET",                      "/api/v1/employees",        { org: currentOrgSlug.value!, params: { per_page: 200 } }),
    ]);
    departments.value  = d.data  ?? [];
    designations.value = des.data ?? [];
    jobGrades.value    = g.data  ?? [];
    managers.value     = emps?.data ?? [];
  } catch { /* non-critical — dropdowns just show empty */ }
}

onMounted(() => { load(); loadMeta(); });

// ─── Helpers ──────────────────────────────────────────────────────────────────
function getSection(key: string): Section | undefined {
  return record.value?.sections.find(s => s.section === key);
}

function sectionStatus(key: string) {
  return getSection(key)?.status ?? "pending";
}

function sectionStatusColor(key: string): string {
  const m: Record<string,string> = { pending: "#6b7280", submitted: "#0ea5e9", approved: "#059669", not_applicable: "#9ca3af" };
  return m[sectionStatus(key)] ?? "#9ca3af";
}

function sectionStatusLabel(status: string): string {
  const m: Record<string,string> = { pending: "Pending", submitted: "Submitted", approved: "Approved", not_applicable: "N/A" };
  return m[status] ?? status;
}

function progressPercent(): number {
  if (!record.value) return 0;
  // Exclude HR-internal and not-applicable sections from the denominator
  const active = record.value.sections.filter(
    s => s.status !== "not_applicable" && s.section !== "employment"
  );
  if (!active.length) return 0;
  // Count both submitted (candidate filled) and approved (HR reviewed) as complete
  const done = active.filter(s => s.status === "submitted" || s.status === "approved").length;
  return Math.round(done / active.length * 100);
}

// ─── Section editing ─────────────────────────────────────────────────────────
const editingSec    = ref<string | null>(null);
const secForm       = ref<Record<string, string>>({});
const secErr        = ref("");
interface EmpSecondment {
  org_id: string; org_name: string; role: string; scope: string;
  start_date: string; end_date: string; allocation_pct: number; is_billable: boolean; notes: string;
}
const empSecondments = ref<EmpSecondment[]>([]);
const empScopeModal   = ref(false);
const empScopeTarget  = ref<EmpSecondment | null>(null);

function addEmpSecondment() {
  empSecondments.value.push({ org_id: "", org_name: "", role: "", scope: "full", start_date: "", end_date: "", allocation_pct: 100, is_billable: false, notes: "" });
}
function removeEmpSecondment(i: number) {
  empSecondments.value.splice(i, 1);
}
function onEmpSecondmentOrg(i: number, orgId: string) {
  empSecondments.value[i].org_id   = orgId;
  empSecondments.value[i].org_name = allOrgs.value.find(o => String(o.id) === orgId)?.name ?? "";
}
function openEmpScope(s: EmpSecondment) { empScopeTarget.value = s; empScopeModal.value = true; }
function closeEmpScope()                { empScopeModal.value = false; empScopeTarget.value = null; }

function startEditSection(key: string) {
  const sec = getSection(key);
  const existing = (sec?.data as Record<string,string>) ?? {};
  if (key === 'employment') {
    // Pre-populate all employment fields from saved data
    secForm.value = {
      employment_type:   existing['employment_type']   ?? 'full_time',
      date_hired:        existing['date_hired']         ?? '',
      designation_id:    existing['designation_id']     ?? '',
      designation_name:  existing['designation_name']   ?? '',
      department_id:     existing['department_id']      ?? '',
      department_name:   existing['department_name']    ?? '',
      reports_to:        existing['reports_to']         ?? '',
      manager_name:      existing['manager_name']       ?? '',
      job_grade_id:      existing['job_grade_id']       ?? '',
      job_grade_name:    existing['job_grade_name']     ?? '',
      benefit_plan:      existing['benefit_plan']       ?? '',
      basic_salary:      existing['basic_salary']       ?? '',
      status:            existing['status']             ?? 'active',
      probation_end_date:existing['probation_end_date'] ?? '',
      contract_end_date: existing['contract_end_date']  ?? '',
      primary_org_id:    existing['primary_org_id']     ?? '',
      primary_org_name:  existing['primary_org_name']   ?? '',
      hr_notes:          existing['hr_notes']           ?? '',
    };
    // Restore secondments
    const saved = (sec?.data as any)?.secondments;
    empSecondments.value = Array.isArray(saved)
      ? saved.map((s: any) => ({
          org_id:       String(s.org_id ?? ''),
          org_name:     s.org_name ?? '',
          role:         s.role ?? '',
          scope:        s.scope ?? 'full',
          start_date:   s.start_date ?? '',
          end_date:     s.end_date ?? '',
          allocation_pct: s.allocation_pct ?? 100,
          is_billable:  s.is_billable ?? false,
          notes:        s.notes ?? '',
        }))
      : [];
  } else {
    const fields = SECTION_FIELDS[key] ?? [];
    secForm.value = Object.fromEntries(fields.map((f: { key: string }) => [f.key, existing[f.key] ?? ""]));
  }
  editingSec.value = key;
  secErr.value = "";
}

async function saveSection(key: string) {
  saving.value = true; secErr.value = "";
  try {
    const data: Record<string, unknown> = { ...secForm.value };
    if (key === 'employment') {
      data['secondments'] = empSecondments.value;
    }
    await api("PUT", `/api/v1/preboarding/${props.id}/sections/${key}`, {
      org: currentOrgSlug.value!, body: { data }
    });
    editingSec.value = null;
    await load();
  } catch (e) { secErr.value = e instanceof Error ? e.message : "Failed to save."; }
  finally { saving.value = false; }
}

async function approveSection(key: string, hrNotes?: string) {
  saving.value = true;
  try {
    await api("PATCH", `/api/v1/preboarding/${props.id}/sections/${key}/approve`, {
      org: currentOrgSlug.value!, body: { hr_notes: hrNotes ?? null }
    });
    await load();
  } finally { saving.value = false; }
}

async function markNotApplicable(key: string) {
  if (!confirm(`Mark "${SECTION_TABS.find(t => t.key === key)?.label}" as Not Applicable?`)) return;
  saving.value = true;
  try {
    await api("PATCH", `/api/v1/preboarding/${props.id}/sections/${key}/not-applicable`, { org: currentOrgSlug.value! });
    await load();
  } finally { saving.value = false; }
}

// ─── Welcome / Announcement ──────────────────────────────────────────────────
async function sendWelcome() {
  if (!confirm("Mark welcome email as sent to the candidate?")) return;
  saving.value = true;
  try {
    await api("PATCH", `/api/v1/preboarding/${props.id}/welcome`, { org: currentOrgSlug.value! });
    await load();
  } finally { saving.value = false; }
}

async function sendAnnouncement() {
  if (!confirm("Mark the joining announcement email as sent to the team?")) return;
  saving.value = true;
  try {
    await api("PATCH", `/api/v1/preboarding/${props.id}/announcement`, { org: currentOrgSlug.value! });
    await load();
  } finally { saving.value = false; }
}

// ─── Link management ─────────────────────────────────────────────────────────
const resendingLink  = ref(false);
const sendingEmail   = ref(false);
const emailSentMsg   = ref("");
const linkCopied     = ref(false);

function formAccessUrl(token: string): string {
  const base = (import.meta as any).env.VITE_APP_URL ?? window.location.origin;
  return `${base}/#/pre-onboarding/form/${token}`;
}

function isLinkExpired(rec: PreboardingRecord): boolean {
  if (!rec.token_expires_at) return false;
  return new Date(rec.token_expires_at) < new Date();
}

function linkExpiryLabel(rec: PreboardingRecord): string {
  if (!rec.token_expires_at) return "No expiry set";
  const d = new Date(rec.token_expires_at);
  if (d < new Date()) return `Expired on ${d.toLocaleDateString()}`;
  const diffMs = d.getTime() - Date.now();
  const diffDays = Math.ceil(diffMs / 86400000);
  if (diffDays === 0) return "Expires today";
  if (diffDays === 1) return "Expires tomorrow";
  return `Expires in ${diffDays} days (${d.toLocaleDateString()})`;
}

async function copyLink(token: string) {
  await navigator.clipboard.writeText(formAccessUrl(token));
  linkCopied.value = true;
  setTimeout(() => { linkCopied.value = false; }, 2000);
}

async function resendLink() {
  if (!confirm("Regenerate the form link? The previous link will become invalid.")) return;
  resendingLink.value = true;
  emailSentMsg.value = "";
  try {
    await api("POST", `/api/v1/preboarding/${props.id}/resend-link`, { org: currentOrgSlug.value! });
    await load();
  } finally { resendingLink.value = false; }
}

async function sendLinkEmail() {
  if (!record.value?.email) return;
  if (!confirm(`Send the form link to ${record.value.email}?`)) return;
  sendingEmail.value = true;
  emailSentMsg.value = "";
  try {
    const res = await api<{ message: string }>("POST", `/api/v1/preboarding/${props.id}/send-link-email`, { org: currentOrgSlug.value! });
    emailSentMsg.value = res.message ?? `Email sent to ${record.value.email}`;
  } catch (e) {
    emailSentMsg.value = "Failed to send email. " + (e instanceof Error ? e.message : "");
  } finally { sendingEmail.value = false; }
}

// ─── References ───────────────────────────────────────────────────────────────
const showAddRef = ref(false);
const refForm    = ref({ referee_name: "", referee_email: "", referee_company: "", referee_job_title: "", relationship: "" });
const refErr     = ref("");

async function addReference() {
  if (!refForm.value.referee_name || !refForm.value.referee_email) {
    refErr.value = "Name and email are required."; return;
  }
  saving.value = true; refErr.value = "";
  try {
    await api("POST", `/api/v1/preboarding/${props.id}/references`, {
      org: currentOrgSlug.value!, body: { ...refForm.value }
    });
    showAddRef.value = false;
    refForm.value = { referee_name: "", referee_email: "", referee_company: "", referee_job_title: "", relationship: "" };
    await load();
  } catch (e) { refErr.value = e instanceof Error ? e.message : "Failed."; }
  finally { saving.value = false; }
}

async function approveRef(refId: number) {
  saving.value = true;
  try {
    await api("PATCH", `/api/v1/preboarding/${props.id}/references/${refId}/approve`, { org: currentOrgSlug.value! });
    await load();
  } finally { saving.value = false; }
}

async function inviteRef(refId: number) {
  saving.value = true;
  try {
    await api("PATCH", `/api/v1/preboarding/${props.id}/references/${refId}/invite`, { org: currentOrgSlug.value! });
    await load();
  } finally { saving.value = false; }
}

async function deleteRef(refId: number) {
  if (!confirm("Remove this reference?")) return;
  saving.value = true;
  try {
    await api("DELETE", `/api/v1/preboarding/${props.id}/references/${refId}`, { org: currentOrgSlug.value! });
    await load();
  } finally { saving.value = false; }
}

// ─── Attachments ──────────────────────────────────────────────────────────────
const attFile     = ref<File | null>(null);
const attCategory = ref("contract");
const attErr      = ref("");

async function uploadAttachment() {
  if (!attFile.value) { attErr.value = "Select a file."; return; }
  saving.value = true; attErr.value = "";
  try {
    const fd = new FormData();
    fd.append("file", attFile.value);
    fd.append("category", attCategory.value);
    await api("POST", `/api/v1/preboarding/${props.id}/attachments`, {
      org: currentOrgSlug.value!, formData: fd
    });
    attFile.value = null;
    await load();
  } catch (e) { attErr.value = e instanceof Error ? e.message : "Upload failed."; }
  finally { saving.value = false; }
}

function onAttFile(e: Event) {
  const f = (e.target as HTMLInputElement).files?.[0];
  attFile.value = f ?? null;
}

function downloadUrl(attId: number): string {
  const base = (import.meta as any).env.VITE_API_BASE ?? "";
  return `${base}/api/v1/preboarding/${props.id}/attachments/${attId}/download?org=${currentOrgSlug.value}`;
}

async function deleteAttachment(attId: number) {
  if (!confirm("Delete this attachment?")) return;
  saving.value = true;
  try {
    await api("DELETE", `/api/v1/preboarding/${props.id}/attachments/${attId}`, { org: currentOrgSlug.value! });
    await load();
  } finally { saving.value = false; }
}

function formatBytes(b: number | null): string {
  if (!b) return "";
  if (b < 1024) return b + " B";
  if (b < 1024 * 1024) return (b / 1024).toFixed(1) + " KB";
  return (b / 1024 / 1024).toFixed(1) + " MB";
}

// ─── Convert to Employee ──────────────────────────────────────────────────────
function goConvert() {
  router.push({ name: "onboard" });
}
</script>

<template>
  <div class="view">
    <div class="pb-detail-header">
      <button class="back-btn" @click="router.push({ name: 'preboarding' })">← Back</button>
      <div v-if="record">
        <h2 class="page-title">{{ record.first_name }} {{ record.last_name }}</h2>
        <div class="pb-subtitle">{{ record.position || "—" }} · {{ record.email }}</div>
      </div>
    </div>

    <div v-if="error" class="alert-error">{{ error }}</div>
    <div v-if="loading" class="loading-row">Loading…</div>

    <template v-else-if="record">
      <!-- Progress bar + status row -->
      <div class="pb-progress-header">
        <div class="pb-progress-bar-wrap">
          <div class="pb-progress-bar">
            <div class="pb-progress-fill" :style="{ width: progressPercent() + '%' }"></div>
          </div>
          <span class="pb-pct-label">{{ progressPercent() }}% complete</span>
        </div>
        <div class="pb-status-chips">
          <span class="status-chip" :class="'status-' + record.status">{{ record.status.replace('_',' ') }}</span>
          <span v-if="record.employee_type" :class="['pb-etype-badge', 'etype-' + record.employee_type]">
            {{ record.employee_type }}
          </span>
          <span v-if="record.welcome_sent_at" class="status-chip status-sent">✓ Welcome sent</span>
          <span v-if="record.announcement_sent_at" class="status-chip status-sent">✓ Announcement sent</span>
          <span v-if="record.expected_start_date" class="status-chip status-date">
            Start: {{ new Date(record.expected_start_date).toLocaleDateString() }}
          </span>
        </div>
      </div>

      <!-- Tab bar -->
      <div class="pb-tabs">
        <button
          :class="['pb-tab', activeTab === 'welcome' && 'active']"
          @click="activeTab = 'welcome'"
        >Welcome</button>
        <button
          v-for="tab in SECTION_TABS"
          :key="tab.key"
          :class="['pb-tab', activeTab === tab.key && 'active', tab.hrOnly && 'pb-tab-hr']"
          @click="activeTab = tab.key"
        >
          {{ tab.label }}
          <span v-if="tab.hrOnly" class="tab-hr-badge">HR</span>
          <span v-else class="tab-dot" :style="{ background: sectionStatusColor(tab.key) }"></span>
        </button>
        <button
          :class="['pb-tab', activeTab === 'references' && 'active']"
          @click="activeTab = 'references'"
        >References</button>
        <button
          :class="['pb-tab', activeTab === 'files' && 'active']"
          @click="activeTab = 'files'"
        >Files</button>
      </div>

      <!-- ── Welcome tab ── -->
      <div v-if="activeTab === 'welcome'" class="pb-panel">
        <div class="pb-panel-title">Welcome New Hire to the Team</div>
        <p class="pb-panel-desc">
          Send a welcome email to the candidate to officially kick off the preboarding process.
          The candidate will be notified and can begin filling in their information.
        </p>
        <div class="pb-info-row">
          <div class="pb-info-item">
            <span class="pb-info-label">Name</span>
            <span>{{ record.first_name }} {{ record.last_name }}</span>
          </div>
          <div class="pb-info-item">
            <span class="pb-info-label">Email</span>
            <span>{{ record.email }}</span>
          </div>
          <div class="pb-info-item">
            <span class="pb-info-label">Position</span>
            <span>{{ record.position || "—" }}</span>
          </div>
          <div class="pb-info-item">
            <span class="pb-info-label">Employee Type</span>
            <span :class="['pb-etype-badge', 'etype-' + (record.employee_type ?? 'permanent')]">
              {{ record.employee_type ?? "—" }}
            </span>
          </div>
          <div class="pb-info-item">
            <span class="pb-info-label">Expected Start</span>
            <span>{{ record.expected_start_date ? new Date(record.expected_start_date).toLocaleDateString() : "—" }}</span>
          </div>
          <div class="pb-info-item">
            <span class="pb-info-label">Medical Cover</span>
            <span :style="{ color: record.medical_cover_eligible ? '#059669' : '#6b7280' }">
              {{ record.medical_cover_eligible ? "✓ Eligible" : "Not eligible" }}
            </span>
          </div>
        </div>

        <!-- Secure link panel -->
        <div class="pb-link-panel" :class="{ 'link-expired': record.token_expires_at && isLinkExpired(record) }">
          <div class="pb-link-panel-header">
            <div>
              <div class="pb-link-title">Pre-Hire Form Link</div>
              <div class="pb-link-meta">
                <span :class="['pb-link-status', record.token_used_at ? 'link-used' : (isLinkExpired(record) ? 'link-exp' : 'link-active')]">
                  {{ record.token_used_at ? "Accessed" : (isLinkExpired(record) ? "Expired" : "Active") }}
                </span>
                <span class="pb-link-expiry">{{ linkExpiryLabel(record) }}</span>
                <span v-if="record.link_resend_count" class="pb-link-resent">
                  · Resent {{ record.link_resend_count }}×
                </span>
              </div>
            </div>
            <div class="pb-link-actions">
              <button
                class="btn-ghost"
                :disabled="!record.token || resendingLink"
                @click="copyLink(record.token!)"
              >{{ linkCopied ? "✓ Copied!" : "Copy Link" }}</button>
              <button
                class="btn-secondary"
                :disabled="resendingLink"
                @click="resendLink"
              >{{ resendingLink ? "Regenerating…" : "Regenerate Link" }}</button>
              <button
                class="btn-primary"
                :disabled="sendingEmail || !record.token"
                @click="sendLinkEmail"
              >{{ sendingEmail ? "Sending…" : "✉ Send to " + record.email }}</button>
            </div>
          </div>
          <div v-if="record.token" class="pb-link-url">{{ formAccessUrl(record.token) }}</div>
          <p v-if="emailSentMsg" class="pb-email-sent-msg" :class="{ err: emailSentMsg.startsWith('Failed') }">{{ emailSentMsg }}</p>
          <p class="pb-link-note">
            Share this link with the candidate via email. The link expires in <strong>{{ record.link_expiry_days }} day{{ record.link_expiry_days === 1 ? '' : 's' }}</strong> and becomes invalid after the form is first opened.
            Use "Regenerate Link" to issue a new link if the original was lost or expired.
          </p>
        </div>

        <div class="pb-action-row" style="margin-top:20px">
          <button
            v-if="!record.welcome_sent_at"
            class="btn-primary"
            :disabled="saving"
            @click="sendWelcome"
          >Send Welcome Email</button>
          <span v-else class="status-chip status-sent">
            ✓ Welcome sent on {{ new Date(record.welcome_sent_at).toLocaleDateString() }}
          </span>
        </div>

        <div v-if="record.welcome_sent_at" class="pb-action-row" style="margin-top:16px">
          <div class="pb-panel-title" style="margin-bottom:6px">New Hire Joining Announcement</div>
          <p class="pb-panel-desc" style="margin-bottom:12px">
            Once the candidate has completed their self-introduction, send an announcement email to the team.
          </p>
          <button
            v-if="!record.announcement_sent_at"
            class="btn-secondary"
            :disabled="saving"
            @click="sendAnnouncement"
          >Send Joining Announcement</button>
          <span v-else class="status-chip status-sent">
            ✓ Announcement sent on {{ new Date(record.announcement_sent_at).toLocaleDateString() }}
          </span>
        </div>

        <!-- Convert button once completed -->
        <div v-if="record.status === 'completed' && !record.employee_id" class="pb-convert-box">
          <div class="pb-convert-title">All sections complete — ready to add as employee</div>
          <button class="btn-success" @click="goConvert">Add as Employee →</button>
        </div>
        <div v-if="record.employee_id" class="pb-convert-box pb-convert-done">
          ✓ Converted to Employee #{{ record.employee_id }}
        </div>
      </div>

      <!-- ── Section tabs ── -->
      <div v-for="tab in SECTION_TABS" :key="tab.key">
        <div v-if="activeTab === tab.key" class="pb-panel">
          <div class="pb-panel-header">
            <div>
              <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
                <div class="pb-panel-title">{{ tab.label }}</div>
                <span v-if="tab.hrOnly" class="pb-hr-only-badge">HR Internal</span>
              </div>
              <span
                v-if="!tab.hrOnly"
                class="section-status-badge"
                :style="{ background: sectionStatusColor(tab.key) + '22', color: sectionStatusColor(tab.key) }"
              >{{ sectionStatusLabel(sectionStatus(tab.key)) }}</span>
            </div>
            <div class="pb-panel-actions">
              <button
                v-if="!tab.hrOnly && sectionStatus(tab.key) !== 'not_applicable' && sectionStatus(tab.key) !== 'approved'"
                class="btn-ghost"
                @click="markNotApplicable(tab.key)"
              >Mark N/A</button>
              <button
                v-if="tab.hrOnly || ['pending','submitted'].includes(sectionStatus(tab.key))"
                class="btn-secondary"
                @click="startEditSection(tab.key)"
              >{{ tab.hrOnly ? (getSection(tab.key)?.data ? 'Edit' : 'Fill In') : (sectionStatus(tab.key) === 'pending' ? 'Fill In' : 'Edit') }}</button>
              <button
                v-if="!tab.hrOnly && sectionStatus(tab.key) === 'submitted'"
                class="btn-primary"
                :disabled="saving"
                @click="approveSection(tab.key)"
              >Approve</button>
            </div>
          </div>

          <!-- HR Internal notice -->
          <div v-if="tab.hrOnly" class="pb-hr-notice">
            This section is filled in by HR and is not visible to the candidate.
          </div>

          <!-- ── List sections: dependents ── -->
          <template v-if="tab.key === 'dependents'">
            <div v-if="getSection('dependents')?.data" class="pb-list-section">
              <div v-if="((getSection('dependents')?.data as any)?.list ?? []).length === 0" class="pb-empty-section">No dependents recorded.</div>
              <table v-else class="pb-list-table">
                <thead>
                  <tr><th>Name</th><th>Relationship</th><th>Date of Birth</th></tr>
                </thead>
                <tbody>
                  <tr v-for="(dep, i) in ((getSection('dependents')?.data as any)?.list ?? [])" :key="i">
                    <td>{{ dep.name || '—' }}</td>
                    <td style="text-transform:capitalize">{{ dep.relationship || '—' }}</td>
                    <td>{{ dep.dob || '—' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div v-else class="pb-empty-section">This section has not been filled in yet.</div>
          </template>

          <!-- ── List sections: qualifications ── -->
          <template v-else-if="tab.key === 'qualifications'">
            <div v-if="getSection('qualifications')?.data">
              <div v-if="((getSection('qualifications')?.data as any)?.list ?? []).length === 0" class="pb-empty-section">No qualifications recorded.</div>
              <div v-else class="pb-qual-list">
                <div v-for="(q, i) in ((getSection('qualifications')?.data as any)?.list ?? [])" :key="i" class="pb-qual-card">
                  <div class="pb-qual-type">{{ q.type || 'Qualification' }}</div>
                  <div class="pb-qual-name">{{ q.qualification || '—' }}</div>
                  <div class="pb-qual-meta">
                    <span v-if="q.institution">{{ q.institution }}</span>
                    <span v-if="q.year"> · {{ q.year }}</span>
                    <span v-if="q.grade"> · {{ q.grade }}</span>
                  </div>
                </div>
              </div>
            </div>
            <div v-else class="pb-empty-section">This section has not been filled in yet.</div>
          </template>

          <!-- ── List sections: memberships ── -->
          <template v-else-if="tab.key === 'memberships'">
            <div v-if="getSection('memberships')?.data">
              <div v-if="((getSection('memberships')?.data as any)?.list ?? []).length === 0" class="pb-empty-section">No memberships recorded.</div>
              <table v-else class="pb-list-table">
                <thead>
                  <tr><th>Professional Body</th><th>Membership No.</th><th>Valid Until</th></tr>
                </thead>
                <tbody>
                  <tr v-for="(m, i) in ((getSection('memberships')?.data as any)?.list ?? [])" :key="i">
                    <td>{{ m.body || '—' }}</td>
                    <td>{{ m.membership_no || '—' }}</td>
                    <td>{{ m.valid_until || '—' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div v-else class="pb-empty-section">This section has not been filled in yet.</div>
          </template>

          <!-- ── Standard key-value sections ── -->
          <template v-else>
            <!-- Employment: custom display -->
            <template v-if="tab.key === 'employment'">
              <div v-if="getSection('employment')?.data" class="pb-data-grid">
                <div class="pb-data-item"><span class="pb-data-label">Employment Type</span><span class="pb-data-value" style="text-transform:capitalize">{{ (getSection('employment')?.data as any)?.employment_type?.replace('_',' ') || '—' }}</span></div>
                <div class="pb-data-item"><span class="pb-data-label">Start Date</span><span class="pb-data-value">{{ (getSection('employment')?.data as any)?.date_hired || '—' }}</span></div>
                <div class="pb-data-item"><span class="pb-data-label">Job Role</span><span class="pb-data-value">{{ (getSection('employment')?.data as any)?.designation_name || '—' }}</span></div>
                <div class="pb-data-item"><span class="pb-data-label">Department</span><span class="pb-data-value">{{ (getSection('employment')?.data as any)?.department_name || '—' }}</span></div>
                <div class="pb-data-item"><span class="pb-data-label">Reporting Manager</span><span class="pb-data-value">{{ (getSection('employment')?.data as any)?.manager_name || '—' }}</span></div>
                <div class="pb-data-item"><span class="pb-data-label">Cadre / Job Grade</span><span class="pb-data-value">{{ (getSection('employment')?.data as any)?.job_grade_name || '—' }}</span></div>
                <div class="pb-data-item"><span class="pb-data-label">Benefit Plan</span><span class="pb-data-value">{{ (getSection('employment')?.data as any)?.benefit_plan || '—' }}</span></div>
                <div class="pb-data-item"><span class="pb-data-label">Salary / Rate (KES)</span><span class="pb-data-value">{{ (getSection('employment')?.data as any)?.basic_salary || '—' }}</span></div>
                <div class="pb-data-item"><span class="pb-data-label">Status</span><span class="pb-data-value" style="text-transform:capitalize">{{ (getSection('employment')?.data as any)?.status || '—' }}</span></div>
                <div class="pb-data-item"><span class="pb-data-label">Probation End Date</span><span class="pb-data-value">{{ (getSection('employment')?.data as any)?.probation_end_date || '—' }}</span></div>
                <div class="pb-data-item"><span class="pb-data-label">Contract End Date</span><span class="pb-data-value">{{ (getSection('employment')?.data as any)?.contract_end_date || '—' }}</span></div>
                <div class="pb-data-item"><span class="pb-data-label">Primary Organisation</span><span class="pb-data-value">{{ (getSection('employment')?.data as any)?.primary_org_name || '—' }}</span></div>
                <div class="pb-data-item pb-data-item-full" v-if="((getSection('employment')?.data as any)?.secondments ?? []).length"><span class="pb-data-label">Secondments</span><span class="pb-data-value">{{ ((getSection('employment')?.data as any)?.secondments ?? []).map((s:any) => s.org_name + (s.role ? ' — ' + s.role : '')).join(', ') }}</span></div>
                <div class="pb-data-item pb-data-item-full" v-if="(getSection('employment')?.data as any)?.hr_notes"><span class="pb-data-label">HR Notes</span><span class="pb-data-value" style="white-space:pre-line">{{ (getSection('employment')?.data as any)?.hr_notes }}</span></div>
              </div>
              <div v-else class="pb-empty-section">No employment details recorded yet.</div>
            </template>

            <!-- All other standard sections -->
            <template v-else>
              <div v-if="getSection(tab.key)?.data && sectionStatus(tab.key) !== 'pending'" class="pb-data-grid">
                <div v-for="field in (SECTION_FIELDS[tab.key] ?? [])" :key="field.key" class="pb-data-item">
                  <span class="pb-data-label">{{ field.label }}</span>
                  <span v-if="field.type === 'textarea'" class="pb-data-value" style="white-space:pre-line">{{ (getSection(tab.key)?.data as any)?.[field.key] || '—' }}</span>
                  <span v-else class="pb-data-value">{{ (getSection(tab.key)?.data as any)?.[field.key] || '—' }}</span>
                </div>
              </div>
              <div v-else-if="sectionStatus(tab.key) === 'pending'" class="pb-empty-section">
                This section has not been filled in yet.
              </div>
              <div v-if="sectionStatus(tab.key) === 'not_applicable'" class="pb-na-banner">
                This section has been marked as Not Applicable.
              </div>
            </template>
          </template>

          <!-- HR Notes (candidate sections) -->
          <div v-if="!tab.hrOnly && getSection(tab.key)?.hr_notes" class="pb-hr-notes">
            <span class="pb-data-label">HR Notes:</span> {{ getSection(tab.key)?.hr_notes }}
          </div>

          <!-- Inline edit form: Employment uses custom form -->
          <div v-if="editingSec === tab.key" class="pb-edit-form">
            <div v-if="secErr" class="alert-error">{{ secErr }}</div>

            <!-- ── Employment custom form ── -->
            <template v-if="tab.key === 'employment'">
              <div class="pb-form-grid">
                <div class="pb-field">
                  <label>Employment Type *</label>
                  <select v-model="secForm['employment_type']" class="pb-input pb-select">
                    <option value="">Select…</option>
                    <option value="full_time">Full Time</option>
                    <option value="part_time">Part Time</option>
                    <option value="contract">Contract</option>
                    <option value="attachment">Attachment</option>
                    <option value="intern">Internship</option>
                    <option value="casual">Casual</option>
                  </select>
                </div>
                <div class="pb-field">
                  <label>Start Date *</label>
                  <input v-model="secForm['date_hired']" type="date" class="pb-input" />
                </div>
                <div class="pb-field">
                  <label>Job Role</label>
                  <select v-model="secForm['designation_id']" class="pb-input pb-select" @change="secForm['designation_name'] = designations.find(d => String(d.id) === String(secForm['designation_id']))?.title ?? ''">
                    <option value="">Select role…</option>
                    <option v-for="d in designations" :key="d.id" :value="String(d.id)">{{ d.title }}</option>
                  </select>
                </div>
                <div class="pb-field">
                  <label>Department / Unit</label>
                  <select v-model="secForm['department_id']" class="pb-input pb-select" @change="secForm['department_name'] = departments.find(d => String(d.id) === String(secForm['department_id']))?.name ?? ''">
                    <option value="">Select unit…</option>
                    <option v-for="d in departments" :key="d.id" :value="String(d.id)">{{ d.name }}</option>
                  </select>
                </div>
                <div class="pb-field">
                  <label>Reporting Manager</label>
                  <select v-model="secForm['reports_to']" class="pb-input pb-select" @change="secForm['manager_name'] = (() => { const m = managers.find(m => String(m.id) === String(secForm['reports_to'])); return m ? m.first_name + ' ' + m.last_name : ''; })()">
                    <option value="">Select manager…</option>
                    <option v-for="m in managers" :key="m.id" :value="String(m.id)">{{ m.first_name }} {{ m.last_name }} ({{ m.employee_number }})</option>
                  </select>
                </div>
                <div class="pb-field">
                  <label>Cadre / Job Grade</label>
                  <select v-model="secForm['job_grade_id']" class="pb-input pb-select" @change="secForm['job_grade_name'] = jobGrades.find(g => String(g.id) === String(secForm['job_grade_id']))?.name ?? ''">
                    <option value="">Select cadre…</option>
                    <option v-for="g in jobGrades" :key="g.id" :value="String(g.id)">{{ g.name }} (Level {{ g.level }})</option>
                  </select>
                </div>
                <div class="pb-field">
                  <label>Benefit Plan</label>
                  <input v-model="secForm['benefit_plan']" type="text" class="pb-input" placeholder="e.g. Standard Medical Plan A" />
                </div>
                <div class="pb-field">
                  <label>Salary / Rate (KES) <span class="pb-admin-badge">HR Admin only</span></label>
                  <input v-model="secForm['basic_salary']" type="number" min="0" class="pb-input" placeholder="e.g. 85000" />
                </div>
                <div class="pb-field">
                  <label>Status</label>
                  <select v-model="secForm['status']" class="pb-input pb-select">
                    <option value="active">Active</option>
                    <option value="probation">Probation</option>
                    <option value="inactive">Inactive</option>
                  </select>
                </div>
                <div class="pb-field">
                  <label>Probation End Date</label>
                  <input v-model="secForm['probation_end_date']" type="date" class="pb-input" />
                </div>
                <div v-if="['contract','attachment','intern'].includes(secForm['employment_type'])" class="pb-field">
                  <label>Contract End Date</label>
                  <input v-model="secForm['contract_end_date']" type="date" class="pb-input" />
                </div>
                <div class="pb-field pb-field-full" v-if="allOrgs.length">
                  <label>Primary Organisation</label>
                  <div class="pb-org-toggle">
                    <label v-for="o in allOrgs" :key="o.id" class="pb-org-pill" :class="{ selected: secForm['primary_org_id'] === String(o.id) }" @click="secForm['primary_org_id'] = String(o.id); secForm['primary_org_name'] = o.name">
                      <span class="pb-org-radio" :class="{ active: secForm['primary_org_id'] === String(o.id) }">◉</span>
                      {{ o.name }}
                    </label>
                  </div>
                </div>

                <!-- Secondments -->
                <div class="pb-field pb-field-full">
                  <div class="pb-secondment-header">
                    <label>Secondary Assignments <span class="pb-hint">(Secondments)</span></label>
                    <button type="button" class="btn-secondary" style="font-size:12px;padding:4px 12px" @click="addEmpSecondment">+ Add Secondment</button>
                  </div>
                  <div v-if="empSecondments.length === 0" class="pb-empty-section" style="padding:8px 0">
                    No secondments added. Employee is assigned to primary organisation only.
                  </div>
                  <div v-for="(s, si) in empSecondments" :key="si" class="pb-secondment-row">
                    <div class="pb-secondment-grid">
                      <div class="pb-field">
                        <label>Organisation</label>
                        <select class="pb-input pb-select" :value="s.org_id" @change="onEmpSecondmentOrg(si, ($event.target as HTMLSelectElement).value)">
                          <option value="">Select organisation…</option>
                          <option v-for="o in allOrgs.filter(o => String(o.id) !== secForm['primary_org_id'])" :key="o.id" :value="String(o.id)">{{ o.name }}</option>
                        </select>
                      </div>
                      <div class="pb-field">
                        <label>Role in Organisation</label>
                        <input v-model="s.role" type="text" class="pb-input" placeholder="e.g. Project Lead" />
                      </div>
                      <div class="pb-field" style="max-width:120px">
                        <label>Allocation %</label>
                        <input v-model.number="s.allocation_pct" type="number" min="0" max="100" class="pb-input" />
                      </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;margin-top:8px;flex-wrap:wrap">
                      <button type="button" class="btn-secondary" style="font-size:12px;padding:3px 10px" @click="openEmpScope(s)">⚙ Edit Scope</button>
                      <button type="button" class="btn-danger-ghost" style="font-size:12px" @click="removeEmpSecondment(si)">✕ Remove</button>
                      <span v-if="s.scope"       class="pb-scope-badge pb-scope-blue">{{ s.scope }}</span>
                      <span v-if="s.is_billable" class="pb-scope-badge pb-scope-teal">Billable</span>
                      <span v-if="s.start_date"  class="pb-scope-badge pb-scope-gray">From {{ s.start_date }}</span>
                      <span v-if="s.end_date"    class="pb-scope-badge pb-scope-gray">To {{ s.end_date }}</span>
                    </div>
                  </div>
                </div>

                <div class="pb-field pb-field-full">
                  <label>HR Notes</label>
                  <textarea v-model="secForm['hr_notes']" class="pb-input pb-textarea" rows="3" placeholder="Internal notes…"></textarea>
                </div>
              </div>
            </template>

            <!-- Standard field-loop edit form for all other sections -->
            <template v-else>
              <div class="pb-form-grid">
                <div
                  v-for="field in (SECTION_FIELDS[tab.key] ?? [])"
                  :key="field.key"
                  :class="['pb-field', field.type === 'textarea' ? 'pb-field-full' : '']"
                >
                  <label>{{ field.label }}</label>
                  <textarea v-if="field.type === 'textarea'" v-model="secForm[field.key]" class="pb-input pb-textarea" rows="3"></textarea>
                  <select v-else-if="field.type === 'select'" v-model="secForm[field.key]" class="pb-input pb-select">
                    <option value="">— Select —</option>
                    <option v-for="opt in field.options" :key="opt" :value="opt">{{ opt }}</option>
                  </select>
                  <input v-else v-model="secForm[field.key]" :type="field.type || 'text'" class="pb-input" />
                </div>
              </div>
            </template>

            <div class="pb-edit-actions">
              <button class="btn-secondary" @click="editingSec = null">Cancel</button>
              <button class="btn-primary" :disabled="saving" @click="saveSection(tab.key)">
                {{ saving ? "Saving…" : "Save Section" }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- ── References tab ── -->
      <div v-if="activeTab === 'references'" class="pb-panel">
        <div class="pb-panel-header">
          <div class="pb-panel-title">Professional References</div>
          <button class="btn-primary" @click="showAddRef = true">+ Add Reference</button>
        </div>

        <div v-if="!record.references.length" class="pb-empty-section">No references added yet.</div>

        <div class="pb-ref-list">
          <div v-for="ref in record.references" :key="ref.id" class="pb-ref-card">
            <div class="pb-ref-top">
              <div>
                <div class="pb-ref-name">{{ ref.referee_name }}</div>
                <div class="pb-ref-meta">{{ ref.referee_job_title }} @ {{ ref.referee_company }}</div>
                <div class="pb-ref-email">{{ ref.referee_email }}</div>
                <div v-if="ref.relationship" class="pb-ref-rel">Relationship: {{ ref.relationship }}</div>
              </div>
              <div class="pb-ref-actions">
                <span
                  class="ref-status"
                  :class="'ref-' + ref.status"
                >{{ ref.status }}</span>
                <button v-if="ref.status === 'pending'" class="btn-ghost" :disabled="saving" @click="inviteRef(ref.id)">Invite</button>
                <button v-if="ref.status === 'submitted'" class="btn-secondary" :disabled="saving" @click="approveRef(ref.id)">Approve</button>
                <button class="btn-danger-ghost" :disabled="saving" @click="deleteRef(ref.id)">✕</button>
              </div>
            </div>
          </div>
        </div>

        <!-- Add ref form -->
        <div v-if="showAddRef" class="pb-edit-form">
          <div class="pb-panel-title" style="margin-bottom:12px">Add Reference</div>
          <div v-if="refErr" class="alert-error">{{ refErr }}</div>
          <div class="pb-form-grid">
            <div class="pb-field">
              <label>Referee Name *</label>
              <input v-model="refForm.referee_name" class="pb-input" />
            </div>
            <div class="pb-field">
              <label>Referee Email *</label>
              <input v-model="refForm.referee_email" type="email" class="pb-input" />
            </div>
            <div class="pb-field">
              <label>Company</label>
              <input v-model="refForm.referee_company" class="pb-input" />
            </div>
            <div class="pb-field">
              <label>Job Title</label>
              <input v-model="refForm.referee_job_title" class="pb-input" />
            </div>
            <div class="pb-field pb-field-full">
              <label>Relationship (e.g. Supervisor, Colleague)</label>
              <input v-model="refForm.relationship" class="pb-input" />
            </div>
          </div>
          <div class="pb-edit-actions">
            <button class="btn-secondary" @click="showAddRef = false">Cancel</button>
            <button class="btn-primary" :disabled="saving" @click="addReference">Add Reference</button>
          </div>
        </div>
      </div>

      <!-- ── Files tab ── -->
      <div v-if="activeTab === 'files'" class="pb-panel">
        <div class="pb-panel-header">
          <div class="pb-panel-title">Forms &amp; Attachments</div>
        </div>

        <!-- Upload row -->
        <div class="pb-upload-row">
          <select v-model="attCategory" class="pb-upload-select pb-input">
            <option value="contract">Signed Contract</option>
            <option value="id_document">ID / Passport</option>
            <option value="certificate">Certificate</option>
            <option value="tax_form">Tax Form</option>
            <option value="policy_ack">Policy Acknowledgement</option>
            <option value="other">Other</option>
          </select>
          <input type="file" class="pb-file-input" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx" @change="onAttFile" />
          <button class="btn-primary" :disabled="saving || !attFile" @click="uploadAttachment">Upload</button>
        </div>
        <div v-if="attErr" class="alert-error">{{ attErr }}</div>

        <div v-if="!record.attachments.length" class="pb-empty-section">No files uploaded yet.</div>

        <div class="pb-att-list">
          <div v-for="att in record.attachments" :key="att.id" class="pb-att-item">
            <div class="pb-att-icon">📄</div>
            <div class="pb-att-meta">
              <div class="pb-att-name">{{ att.original_name }}</div>
              <div class="pb-att-sub">{{ att.category }} · {{ formatBytes(att.size_bytes) }}</div>
            </div>
            <div class="pb-att-actions">
              <a :href="downloadUrl(att.id)" download class="btn-ghost">↓ Download</a>
              <button class="btn-danger-ghost" :disabled="saving" @click="deleteAttachment(att.id)">✕</button>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- ── Secondment Scope Modal ─────────────────────────────────── -->
    <Teleport to="body">
      <div v-if="empScopeModal && empScopeTarget" class="pb-modal-overlay" @click.self="closeEmpScope">
        <div class="pb-modal">
          <div class="pb-modal-header">
            <h3>Scope of Engagement</h3>
            <button class="pb-modal-close" type="button" @click="closeEmpScope">✕</button>
          </div>
          <div class="pb-modal-body">
            <p class="pb-modal-sub">
              Define the terms of this secondment to
              <strong>{{ empScopeTarget.org_name || "the selected organisation" }}</strong>.
            </p>
            <div class="pb-field">
              <label>Engagement Type</label>
              <select v-model="empScopeTarget.scope" class="pb-input pb-select">
                <option value="full">Full Secondment — works entirely in secondary org</option>
                <option value="partial">Partial Secondment — split time between orgs</option>
                <option value="project">Project Based — specific project engagement</option>
                <option value="advisory">Advisory / Consulting — advisory role only</option>
                <option value="coverage">Coverage — temporary coverage / acting role</option>
              </select>
            </div>
            <div class="pb-grid-2">
              <div class="pb-field">
                <label>Start Date</label>
                <input v-model="empScopeTarget.start_date" type="date" class="pb-input" />
              </div>
              <div class="pb-field">
                <label>End Date <span class="pb-hint">(blank = indefinite)</span></label>
                <input v-model="empScopeTarget.end_date" type="date" class="pb-input" />
              </div>
            </div>
            <div class="pb-grid-2">
              <div class="pb-field">
                <label>Time Allocation (%)</label>
                <input v-model.number="empScopeTarget.allocation_pct" type="number" min="0" max="100" class="pb-input" />
              </div>
              <div class="pb-field">
                <label>Billable to Secondary Org?</label>
                <select v-model="empScopeTarget.is_billable" class="pb-input pb-select">
                  <option :value="false">No — internal arrangement</option>
                  <option :value="true">Yes — cost billed to secondary org</option>
                </select>
              </div>
            </div>
            <div class="pb-field">
              <label>Notes / Purpose</label>
              <textarea v-model="empScopeTarget.notes" class="pb-input pb-textarea" rows="3"
                placeholder="Describe the purpose, deliverables or any special terms…"></textarea>
            </div>
          </div>
          <div class="pb-modal-footer">
            <button class="btn-secondary" type="button" @click="closeEmpScope">Cancel</button>
            <button class="btn-primary"   type="button" @click="closeEmpScope">Save Scope</button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.pb-detail-header { display: flex; align-items: flex-start; gap: 16px; margin-bottom: 20px; }
.back-btn  { background: none; border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: 6px 14px; cursor: pointer; font-size: 13px; margin-bottom: 4px; }
.page-title { margin: 0; font-size: 22px; font-weight: 700; }
.pb-subtitle { font-size: 14px; color: var(--color-text-muted); }

.pb-progress-header { display: flex; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 20px; }
.pb-progress-bar-wrap { display: flex; align-items: center; gap: 10px; flex: 1; min-width: 200px; }
.pb-progress-bar { flex: 1; height: 8px; background: var(--color-neutral-200); border-radius: 4px; overflow: hidden; }
.pb-progress-fill { height: 100%; background: var(--color-brand-base); border-radius: 4px; transition: width .4s; }
.pb-pct-label { font-size: 13px; color: var(--color-text-muted); white-space: nowrap; }
.pb-status-chips { display: flex; flex-wrap: wrap; gap: 8px; }

.status-chip  { font-size: 12px; font-weight: 600; padding: 3px 10px; border-radius: 20px; }
.status-pending    { background: #f3f4f6; color: #6b7280; }
.status-in_progress{ background: #e0f2fe; color: #0369a1; }
.status-completed  { background: #d1fae5; color: #065f46; }
.status-converted  { background: #ede9fe; color: #6d28d9; }
.status-sent       { background: #d1fae5; color: #065f46; }
.status-date       { background: #fef9c3; color: #92400e; }

/* Tabs */
.pb-tabs { display: flex; flex-wrap: wrap; gap: 4px; border-bottom: 2px solid var(--color-border); margin-bottom: 20px; padding-bottom: 0; }
.pb-tab  {
  padding: 8px 14px; border: none; background: none; cursor: pointer;
  font-size: 13px; font-weight: 500; color: var(--color-text-muted);
  border-bottom: 2px solid transparent; margin-bottom: -2px; transition: all .15s;
  display: flex; align-items: center; gap: 6px;
}
.pb-tab.active { color: var(--color-brand-base); border-bottom-color: var(--color-brand-base); }
.pb-tab:hover:not(.active) { color: var(--color-text); }
.tab-dot { width: 7px; height: 7px; border-radius: 50%; display: inline-block; }

/* Panel */
.pb-panel { background: #fff; border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: 24px; }
.pb-panel-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; }
.pb-panel-title  { font-size: 16px; font-weight: 700; margin-bottom: 2px; }
.pb-panel-desc   { font-size: 14px; color: var(--color-text-muted); margin-bottom: 20px; line-height: 1.6; }
.pb-panel-actions { display: flex; gap: 8px; flex-wrap: wrap; }

.section-status-badge { font-size: 11px; font-weight: 600; padding: 2px 10px; border-radius: 20px; }

.pb-info-row { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px,1fr)); gap: 14px; margin-bottom: 20px; }
.pb-info-item { display: flex; flex-direction: column; gap: 2px; }
.pb-info-label, .pb-data-label { font-size: 11px; font-weight: 600; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: .5px; }

.pb-action-row { display: flex; align-items: center; gap: 12px; }

.pb-convert-box { margin-top: 24px; padding: 18px 20px; background: #f0fdf4; border: 1px solid #86efac; border-radius: var(--radius-lg); display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.pb-convert-title { font-weight: 600; color: #15803d; }
.pb-convert-done { background: #ede9fe; border-color: #c4b5fd; }
.pb-convert-done .pb-convert-title { color: #6d28d9; }

/* Employee type badge */
.pb-etype-badge { font-size: 11px; font-weight: 700; padding: 2px 10px; border-radius: 20px; text-transform: capitalize; }
.etype-permanent { background: #e0f2fe; color: #0369a1; }
.etype-contract  { background: #fef9c3; color: #92400e; }
.etype-intern    { background: #f3e8ff; color: #7c3aed; }
.etype-probation { background: #fff7ed; color: #c2410c; }

/* Form link panel */
.pb-link-panel {
  margin-top: 20px; padding: 16px 20px;
  background: #f8fafc; border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
}
.pb-link-panel.link-expired { border-color: #fca5a5; background: #fef2f2; }
.pb-link-panel-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; margin-bottom: 10px; }
.pb-link-title { font-weight: 600; font-size: 14px; margin-bottom: 4px; }
.pb-link-meta  { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.pb-link-status { font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 20px; }
.link-active { background: #d1fae5; color: #065f46; }
.link-used   { background: #e0f2fe; color: #0369a1; }
.link-exp    { background: #fee2e2; color: #b91c1c; }
.pb-link-expiry { font-size: 12px; color: var(--color-text-muted); }
.pb-link-resent { font-size: 12px; color: var(--color-text-muted); }
.pb-link-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.pb-link-url {
  font-size: 12px; font-family: monospace; background: #fff;
  border: 1px solid var(--color-border); border-radius: var(--radius-md);
  padding: 6px 10px; word-break: break-all; margin-bottom: 8px; color: var(--color-text);
}
.pb-link-note { font-size: 12px; color: var(--color-text-muted); margin: 0; line-height: 1.5; }
.pb-email-sent-msg {
  font-size: 12px; font-weight: 600; margin: 8px 0 0;
  color: #059669;
}
.pb-email-sent-msg.err { color: #dc2626; }

/* ── Employment form extras ───────────────────────────────────────────────── */
.pb-admin-badge {
  font-size: 10px; font-weight: 700; background: #dbeafe; color: #1d4ed8;
  padding: 1px 7px; border-radius: 20px; margin-left: 6px; vertical-align: middle;
}
.pb-data-item-full { grid-column: 1 / -1; }
.pb-org-toggle { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 6px; }
.pb-org-pill {
  display: flex; align-items: center; gap: 6px; cursor: pointer;
  border: 1px solid var(--color-border); border-radius: 20px;
  padding: 5px 14px; font-size: 13px; transition: all .15s;
}
.pb-org-pill.selected { border-color: var(--color-brand-base); background: #ede9fe; font-weight: 600; }
.pb-org-radio { font-size: 12px; color: var(--color-text-muted); }
.pb-org-pill.selected .pb-org-radio { color: var(--color-brand-base); }

.pb-secondment-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
.pb-secondment-row { border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: 14px; margin-bottom: 10px; background: var(--color-neutral-50, #fafafa); }
.pb-secondment-grid { display: grid; grid-template-columns: 1fr 1fr auto; gap: 12px; }
.pb-hint { font-size: 12px; color: var(--color-text-muted); font-weight: 400; margin-left: 4px; }
@media (max-width: 600px) { .pb-secondment-grid { grid-template-columns: 1fr; } }

.pb-scope-badge { font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 20px; }
.pb-scope-blue  { background: #dbeafe; color: #1d4ed8; }
.pb-scope-teal  { background: #ccfbf1; color: #0f766e; }
.pb-scope-gray  { background: #f1f5f9; color: #475569; }

/* Scope Modal */
.pb-modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 16px; }
.pb-modal { background: var(--color-surface, #fff); border-radius: var(--radius-lg, 12px); width: 100%; max-width: 520px; box-shadow: 0 20px 60px rgba(0,0,0,.25); display: flex; flex-direction: column; max-height: 90vh; overflow: hidden; }
.pb-modal-header { display: flex; justify-content: space-between; align-items: center; padding: 18px 20px 12px; border-bottom: 1px solid var(--color-border); }
.pb-modal-header h3 { margin: 0; font-size: 16px; font-weight: 700; }
.pb-modal-close { background: none; border: none; cursor: pointer; font-size: 18px; color: var(--color-text-muted); padding: 2px 6px; border-radius: 4px; }
.pb-modal-close:hover { background: var(--color-neutral-100, #f1f5f9); }
.pb-modal-body { padding: 18px 20px; overflow-y: auto; display: flex; flex-direction: column; gap: 16px; }
.pb-modal-sub { margin: 0 0 4px; font-size: 13px; color: var(--color-text-muted); }
.pb-modal-footer { display: flex; justify-content: flex-end; gap: 10px; padding: 14px 20px; border-top: 1px solid var(--color-border); }
.pb-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
@media (max-width: 480px) { .pb-grid-2 { grid-template-columns: 1fr; } }

.pb-data-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px,1fr)); gap: 14px; margin-bottom: 14px; }
.pb-data-item { display: flex; flex-direction: column; gap: 3px; }
.pb-data-value { font-size: 14px; }

.pb-empty-section { color: var(--color-text-muted); font-size: 14px; font-style: italic; padding: 12px 0; }
.pb-na-banner { background: #f9fafb; border: 1px dashed var(--color-border); border-radius: var(--radius-md); padding: 10px 14px; color: var(--color-text-muted); font-size: 13px; margin-top: 10px; }
.pb-hr-notes { margin-top: 12px; font-size: 13px; background: #fffbeb; border: 1px solid #fde68a; border-radius: var(--radius-md); padding: 8px 12px; }

/* Form */
.pb-edit-form { margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--color-border); }
.pb-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 16px; }
.pb-field { display: flex; flex-direction: column; gap: 5px; }
.pb-field-full { grid-column: span 2; }
.pb-field label { font-size: 13px; font-weight: 500; }
.pb-input { padding: 8px 12px; border: 1px solid var(--color-border); border-radius: var(--radius-md); font-size: 14px; }
.pb-textarea { resize: vertical; font-family: inherit; }
.pb-select { background: #fff; }
.pb-input:focus { outline: none; border-color: var(--color-brand-base); }
.pb-edit-actions { display: flex; justify-content: flex-end; gap: 10px; }

/* References */
.pb-ref-list { display: flex; flex-direction: column; gap: 12px; margin-bottom: 16px; }
.pb-ref-card { border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: 14px 16px; }
.pb-ref-top  { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; }
.pb-ref-name { font-weight: 600; font-size: 15px; }
.pb-ref-meta, .pb-ref-email, .pb-ref-rel { font-size: 13px; color: var(--color-text-muted); }
.pb-ref-actions { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
.ref-status { font-size: 11px; font-weight: 700; padding: 2px 10px; border-radius: 20px; text-transform: uppercase; }
.ref-pending  { background: #f3f4f6; color: #6b7280; }
.ref-invited  { background: #e0f2fe; color: #0369a1; }
.ref-submitted{ background: #fef9c3; color: #92400e; }
.ref-approved { background: #d1fae5; color: #065f46; }

/* Files */
.pb-upload-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 8px; }
.pb-upload-select { flex: 0 0 180px; }
.pb-file-input { flex: 1; font-size: 13px; }
.pb-att-list   { display: flex; flex-direction: column; gap: 8px; margin-top: 12px; }
.pb-att-item   { display: flex; align-items: center; gap: 12px; padding: 10px 14px; border: 1px solid var(--color-border); border-radius: var(--radius-md); background: #fafafa; }
.pb-att-icon   { font-size: 22px; flex-shrink: 0; }
.pb-att-meta   { flex: 1; min-width: 0; }
.pb-att-name   { font-size: 14px; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pb-att-sub    { font-size: 12px; color: var(--color-text-muted); }
.pb-att-actions { display: flex; gap: 6px; }

/* Buttons */
.btn-primary   { padding: 8px 16px; background: var(--color-brand-base); color: #fff; border: none; border-radius: var(--radius-md); font-size: 13px; font-weight: 500; cursor: pointer; }
.btn-primary:disabled { opacity: .6; cursor: default; }
.btn-secondary { padding: 8px 16px; background: #fff; color: var(--color-text); border: 1px solid var(--color-border); border-radius: var(--radius-md); font-size: 13px; cursor: pointer; }
.btn-ghost     { padding: 6px 12px; background: none; color: var(--color-brand-base); border: 1px solid var(--color-brand-base); border-radius: var(--radius-md); font-size: 12px; cursor: pointer; text-decoration: none; }
.btn-danger-ghost { padding: 6px 10px; background: none; color: #dc2626; border: none; cursor: pointer; font-size: 14px; }
.btn-success   { padding: 9px 18px; background: #059669; color: #fff; border: none; border-radius: var(--radius-md); font-size: 14px; font-weight: 600; cursor: pointer; }

.alert-error   { background: #fef2f2; border: 1px solid #fca5a5; color: #dc2626; border-radius: var(--radius-md); padding: 10px 14px; margin-bottom: 14px; font-size: 13px; }
.loading-row   { text-align: center; padding: 40px; color: var(--color-text-muted); }

@media (max-width: 600px) {
  .pb-form-grid { grid-template-columns: 1fr; }
  .pb-field-full { grid-column: span 1; }
  .pb-tabs { gap: 2px; }
  .pb-tab  { font-size: 12px; padding: 7px 10px; }
}

/* ── HR-only tab + badge ─────────────────────────────────────────────────── */
.pb-tab-hr { border-left: 2px solid #ddd6fe; margin-left: 6px; }
.pb-tab-hr.active { color: #7c3aed; border-bottom-color: #7c3aed; }
.tab-hr-badge {
  font-size: 10px; font-weight: 700; letter-spacing: .04em;
  background: #ede9fe; color: #6d28d9;
  padding: 1px 6px; border-radius: 20px;
}
.pb-hr-only-badge {
  font-size: 11px; font-weight: 700; padding: 2px 10px; border-radius: 20px;
  background: #ede9fe; color: #6d28d9;
}
.pb-hr-notice {
  font-size: 12px; color: #7c3aed;
  background: #f5f3ff; border: 1px solid #ddd6fe;
  border-radius: var(--radius-md); padding: 8px 12px;
  margin-bottom: 16px;
}

/* ── List sections ───────────────────────────────────────────────────────── */
.pb-list-table {
  width: 100%; border-collapse: collapse; font-size: 13px;
}
.pb-list-table th {
  text-align: left; font-size: 11px; font-weight: 700; color: var(--color-text-muted);
  text-transform: uppercase; letter-spacing: .05em;
  padding: 8px 10px; border-bottom: 2px solid var(--color-border);
}
.pb-list-table td {
  padding: 10px; border-bottom: 1px solid var(--color-border); font-size: 14px;
}
.pb-list-table tr:last-child td { border-bottom: none; }

.pb-qual-list { display: flex; flex-direction: column; gap: 10px; }
.pb-qual-card {
  border: 1px solid var(--color-border); border-radius: var(--radius-md);
  padding: 12px 16px; background: #fafafa;
}
.pb-qual-type { font-size: 11px; font-weight: 700; color: #6366f1; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 2px; }
.pb-qual-name { font-size: 14px; font-weight: 600; margin-bottom: 4px; }
.pb-qual-meta { font-size: 13px; color: var(--color-text-muted); }
</style>
