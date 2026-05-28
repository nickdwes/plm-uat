<script setup lang="ts">
import { ref, onMounted } from "vue";
import { useRouter } from "vue-router";
import { api } from "../lib/api";
import { currentOrgSlug } from "../stores/auth";

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
  welcome_sent_at: string | null; announcement_sent_at: string | null; notes: string | null;
  employee_id: number | null;
  sections: Section[]; references: Reference[]; attachments: Attachment[];
}

// ─── State ───────────────────────────────────────────────────────────────────
const record  = ref<PreboardingRecord | null>(null);
const loading = ref(false);
const error   = ref("");
const saving  = ref(false);

const activeTab = ref<string>("welcome");

const SECTION_TABS = [
  { key: "self_intro",      label: "Self Introduction" },
  { key: "personal",        label: "Personal Details" },
  { key: "contact",         label: "Contact Details" },
  { key: "emergency",       label: "Emergency Contacts" },
  { key: "dependents",      label: "Dependents" },
  { key: "immigration",     label: "Immigration" },
  { key: "qualifications",  label: "Qualifications" },
  { key: "memberships",     label: "Memberships" },
  { key: "attachments",     label: "Forms & Attachments" },
];

const SECTION_FIELDS: Record<string, Array<{ key: string; label: string; type?: string; options?: string[] }>> = {
  self_intro: [
    { key: "summary",    label: "About Me (background & skills)",     type: "textarea" },
    { key: "motivation", label: "Why I joined",                        type: "textarea" },
    { key: "interests",  label: "Hobbies & Interests",                 type: "textarea" },
    { key: "fun_fact",   label: "Fun Fact about me",                   type: "text" },
  ],
  personal: [
    { key: "nationality",   label: "Nationality",     type: "text" },
    { key: "date_of_birth", label: "Date of Birth",   type: "date" },
    { key: "gender",        label: "Gender",          type: "select", options: ["Male", "Female", "Non-binary", "Prefer not to say"] },
    { key: "national_id",   label: "National ID / Passport No.", type: "text" },
    { key: "tax_id",        label: "KRA PIN",         type: "text" },
    { key: "marital_status",label: "Marital Status",  type: "select", options: ["Single","Married","Divorced","Widowed"] },
  ],
  contact: [
    { key: "personal_email", label: "Personal Email",    type: "email" },
    { key: "phone",          label: "Phone Number",      type: "text" },
    { key: "address",        label: "Home Address",      type: "text" },
    { key: "city",           label: "City",              type: "text" },
    { key: "country",        label: "Country",           type: "text" },
    { key: "postal_code",    label: "Postal Code",       type: "text" },
  ],
  emergency: [
    { key: "name",         label: "Contact Name",        type: "text" },
    { key: "relationship", label: "Relationship",        type: "text" },
    { key: "phone",        label: "Phone",               type: "text" },
    { key: "email",        label: "Email",               type: "email" },
    { key: "address",      label: "Address",             type: "text" },
  ],
  dependents: [
    { key: "full_name",      label: "Full Name",         type: "text" },
    { key: "relationship",   label: "Relationship",      type: "text" },
    { key: "date_of_birth",  label: "Date of Birth",     type: "date" },
    { key: "national_id",    label: "ID / Passport No.", type: "text" },
  ],
  immigration: [
    { key: "work_permit_number", label: "Work Permit No.",     type: "text" },
    { key: "work_permit_expiry", label: "Permit Expiry Date",  type: "date" },
    { key: "visa_type",          label: "Visa Type",           type: "text" },
    { key: "visa_expiry",        label: "Visa Expiry",         type: "date" },
    { key: "passport_number",    label: "Passport Number",     type: "text" },
    { key: "passport_expiry",    label: "Passport Expiry",     type: "date" },
  ],
  qualifications: [
    { key: "institution",    label: "Institution / University", type: "text" },
    { key: "qualification",  label: "Qualification / Degree",   type: "text" },
    { key: "field_of_study", label: "Field of Study",           type: "text" },
    { key: "year_completed", label: "Year Completed",           type: "text" },
    { key: "certifications", label: "Professional Certifications", type: "textarea" },
  ],
  memberships: [
    { key: "association_name", label: "Professional Body / Association", type: "text" },
    { key: "membership_id",    label: "Membership ID / Number",          type: "text" },
    { key: "valid_until",      label: "Valid Until",                     type: "date" },
    { key: "notes",            label: "Notes",                           type: "textarea" },
  ],
  attachments: [
    { key: "notes", label: "Notes / Instructions for candidate", type: "textarea" },
  ],
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

onMounted(load);

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
  const active   = record.value.sections.filter(s => s.status !== "not_applicable");
  if (!active.length) return 0;
  const approved = active.filter(s => s.status === "approved").length;
  return Math.round(approved / active.length * 100);
}

// ─── Section editing ─────────────────────────────────────────────────────────
const editingSec = ref<string | null>(null);
const secForm    = ref<Record<string, string>>({});
const secErr     = ref("");

function startEditSection(key: string) {
  const sec = getSection(key);
  const existing = (sec?.data as Record<string,string>) ?? {};
  const fields = SECTION_FIELDS[key] ?? [];
  secForm.value = Object.fromEntries(fields.map((f: { key: string }) => [f.key, existing[f.key] ?? ""]));
  editingSec.value = key;
  secErr.value = "";
}

async function saveSection(key: string) {
  saving.value = true; secErr.value = "";
  try {
    await api("PUT", `/api/v1/preboarding/${props.id}/sections/${key}`, {
      org: currentOrgSlug.value!, body: { data: secForm.value }
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
          :class="['pb-tab', activeTab === tab.key && 'active']"
          @click="activeTab = tab.key"
        >
          {{ tab.label }}
          <span
            class="tab-dot"
            :style="{ background: sectionStatusColor(tab.key) }"
          ></span>
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
            <span class="pb-info-label">Expected Start</span>
            <span>{{ record.expected_start_date ? new Date(record.expected_start_date).toLocaleDateString() : "—" }}</span>
          </div>
        </div>

        <div class="pb-action-row">
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
              <div class="pb-panel-title">{{ tab.label }}</div>
              <span
                class="section-status-badge"
                :style="{ background: sectionStatusColor(tab.key) + '22', color: sectionStatusColor(tab.key) }"
              >{{ sectionStatusLabel(sectionStatus(tab.key)) }}</span>
            </div>
            <div class="pb-panel-actions">
              <button
                v-if="sectionStatus(tab.key) !== 'not_applicable' && sectionStatus(tab.key) !== 'approved'"
                class="btn-ghost"
                @click="markNotApplicable(tab.key)"
              >Mark N/A</button>
              <button
                v-if="['pending','submitted'].includes(sectionStatus(tab.key))"
                class="btn-secondary"
                @click="startEditSection(tab.key)"
              >{{ sectionStatus(tab.key) === 'pending' ? 'Fill In' : 'Edit' }}</button>
              <button
                v-if="sectionStatus(tab.key) === 'submitted'"
                class="btn-primary"
                :disabled="saving"
                @click="approveSection(tab.key)"
              >Approve</button>
            </div>
          </div>

          <!-- Display filled data -->
          <div v-if="getSection(tab.key)?.data && sectionStatus(tab.key) !== 'pending'" class="pb-data-grid">
            <div v-for="field in (SECTION_FIELDS[tab.key] ?? [])" :key="field.key" class="pb-data-item">
              <span class="pb-data-label">{{ field.label }}</span>
              <span class="pb-data-value">{{ (getSection(tab.key)?.data as any)?.[field.key] || '—' }}</span>
            </div>
          </div>
          <div v-else-if="sectionStatus(tab.key) === 'pending'" class="pb-empty-section">
            This section has not been filled in yet.
          </div>
          <div v-if="sectionStatus(tab.key) === 'not_applicable'" class="pb-na-banner">
            This section has been marked as Not Applicable.
          </div>

          <!-- HR Notes -->
          <div v-if="getSection(tab.key)?.hr_notes" class="pb-hr-notes">
            <span class="pb-data-label">HR Notes:</span> {{ getSection(tab.key)?.hr_notes }}
          </div>

          <!-- Inline edit form -->
          <div v-if="editingSec === tab.key" class="pb-edit-form">
            <div v-if="secErr" class="alert-error">{{ secErr }}</div>
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
</style>
