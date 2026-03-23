<script setup lang="ts">
/**
 * OnboardingDetailView — read-only display of a single onboarding record.
 *
 * HR controls: Approve (with comments), Reject (with reason), Reopen, Mark Onboarded.
 * All other roles: read-only.
 * Sensitive fields (National ID, KRA PIN, bank account) are already masked by the API
 * for non-HR callers.
 */
import { ref, computed, onMounted } from "vue";
import { useRouter } from "vue-router";
import { callFrappeMethod, type FrappeAuthConfig } from "../lib/frappe-client";
import { authStore, isCompanyHR, isGlobalHR } from "../stores/auth";
import type { OnboardingDetail } from "../lib/types";

const props = defineProps<{ id: string }>();
const router = useRouter();

const record = ref<OnboardingDetail | null>(null);
const loading = ref(true);
const error = ref("");

// Action panel state
const actionPanel = ref<"" | "approve" | "reject">(""); 
const approveComments = ref("");
const rejectReason = ref("");
const actionSaving = ref(false);
const actionError = ref("");
const actionSuccess = ref("");

const isHR = computed(() => isCompanyHR.value || isGlobalHR.value);

function authConfig(): FrappeAuthConfig {
  return {
    baseUrl: authStore.baseUrl,
    apiKey: authStore.apiKey || undefined,
    apiSecret: authStore.apiSecret || undefined,
  };
}

async function load(): Promise<void> {
  loading.value = true;
  error.value = "";
  try {
    record.value = await callFrappeMethod<OnboardingDetail>(
      authConfig(),
      "bitwyse_hrms_backend.api.get_onboarding_record",
      { onboarding_id: props.id },
    );
  } catch (err) {
    error.value = err instanceof Error ? err.message : "Failed to load record.";
  } finally {
    loading.value = false;
  }
}

async function approve(): Promise<void> {
  if (!approveComments.value.trim()) {
    actionError.value = "Please add approval comments.";
    return;
  }
  actionSaving.value = true;
  actionError.value = "";
  actionSuccess.value = "";
  try {
    await callFrappeMethod(
      authConfig(),
      "bitwyse_hrms_backend.api.approve_onboarding",
      { onboarding_id: props.id, comments: approveComments.value },
    );
    actionSuccess.value = "Onboarding approved successfully.";
    actionPanel.value = "";
    await load();
  } catch (err) {
    actionError.value = err instanceof Error ? err.message : "Approval failed.";
  } finally {
    actionSaving.value = false;
  }
}

async function reject(): Promise<void> {
  if (!rejectReason.value.trim()) {
    actionError.value = "Rejection reason is required.";
    return;
  }
  actionSaving.value = true;
  actionError.value = "";
  actionSuccess.value = "";
  try {
    await callFrappeMethod(
      authConfig(),
      "bitwyse_hrms_backend.api.reject_onboarding",
      { onboarding_id: props.id, reason: rejectReason.value },
    );
    actionSuccess.value = "Onboarding rejected.";
    actionPanel.value = "";
    await load();
  } catch (err) {
    actionError.value = err instanceof Error ? err.message : "Rejection failed.";
  } finally {
    actionSaving.value = false;
  }
}

async function reopen(): Promise<void> {
  actionSaving.value = true;
  actionError.value = "";
  actionSuccess.value = "";
  try {
    await callFrappeMethod(
      authConfig(),
      "bitwyse_hrms_backend.api.reopen_onboarding",
      { onboarding_id: props.id },
    );
    actionSuccess.value = "Onboarding reopened to Draft.";
    await load();
  } catch (err) {
    actionError.value = err instanceof Error ? err.message : "Reopen failed.";
  } finally {
    actionSaving.value = false;
  }
}

async function markOnboarded(): Promise<void> {
  if (!confirm("Mark this employee as Onboarded and create an Employee record? This cannot be undone.")) return;
  actionSaving.value = true;
  actionError.value = "";
  actionSuccess.value = "";
  try {
    const res = await callFrappeMethod<{ employee_id: string }>(
      authConfig(),
      "bitwyse_hrms_backend.api.mark_onboarded",
      { onboarding_id: props.id },
    );
    actionSuccess.value = `Employee record created: ${res?.employee_id ?? ""}`;
    await load();
  } catch (err) {
    actionError.value = err instanceof Error ? err.message : "Mark onboarded failed.";
  } finally {
    actionSaving.value = false;
  }
}

function fmt(val: string | null | undefined): string {
  return val || "—";
}

function fmtDate(d: string | null | undefined): string {
  if (!d) return "—";
  return new Date(d).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" });
}

const STATUS_COLORS: Record<string, string> = {
  Draft: "badge-gray",
  Submitted: "badge-yellow",
  Approved: "badge-blue",
  Rejected: "badge-red",
  Onboarded: "badge-green",
};

onMounted(load);
</script>

<template>
  <div class="view">
    <div class="view-header">
      <div>
        <button class="back-btn" @click="router.push({ name: 'onboarding-list' })">← Onboarding</button>
        <h2>
          Onboarding Record
          <span v-if="record" :class="['badge', STATUS_COLORS[record.status] ?? 'badge-gray']">
            {{ record.status }}
          </span>
        </h2>
        <p v-if="record" class="view-sub">{{ record.name }}</p>
      </div>
      <!-- HR action buttons -->
      <div v-if="isHR && record" class="action-buttons">
        <button
          v-if="record.status === 'Submitted'"
          class="btn-approve"
          :disabled="actionSaving"
          @click="actionPanel = actionPanel === 'approve' ? '' : 'approve'"
        >Approve</button>
        <button
          v-if="record.status === 'Submitted'"
          class="btn-reject"
          :disabled="actionSaving"
          @click="actionPanel = actionPanel === 'reject' ? '' : 'reject'"
        >Reject</button>
        <button
          v-if="record.status === 'Rejected'"
          class="btn-reopen"
          :disabled="actionSaving"
          @click="reopen"
        >Reopen</button>
        <button
          v-if="record.status === 'Approved'"
          class="btn-onboard"
          :disabled="actionSaving"
          @click="markOnboarded"
        >Mark Onboarded</button>
      </div>
    </div>

    <!-- Action panels -->
    <div v-if="actionPanel === 'approve'" class="action-panel approve-panel">
      <h4>Approve Onboarding</h4>
      <textarea v-model="approveComments" placeholder="Approval comments (required)" rows="3" />
      <div class="panel-actions">
        <button class="btn-approve" :disabled="actionSaving" @click="approve">
          <span v-if="actionSaving" class="spinner btn-spinner" /> Confirm Approval
        </button>
        <button class="secondary" @click="actionPanel = ''">Cancel</button>
      </div>
      <p v-if="actionError" class="error-banner">{{ actionError }}</p>
    </div>

    <div v-if="actionPanel === 'reject'" class="action-panel reject-panel">
      <h4>Reject Onboarding</h4>
      <textarea v-model="rejectReason" placeholder="State the reason for rejection (required)" rows="3" />
      <div class="panel-actions">
        <button class="btn-reject" :disabled="actionSaving" @click="reject">
          <span v-if="actionSaving" class="spinner btn-spinner" /> Confirm Rejection
        </button>
        <button class="secondary" @click="actionPanel = ''">Cancel</button>
      </div>
      <p v-if="actionError" class="error-banner">{{ actionError }}</p>
    </div>

    <p v-if="actionSuccess" class="success-banner">{{ actionSuccess }}</p>
    <p v-if="!actionPanel && actionError" class="error-banner">{{ actionError }}</p>

    <div v-if="loading" class="loading-state"><span class="spinner" /> Loading record…</div>
    <p v-else-if="error" class="error-banner">{{ error }}</p>

    <template v-else-if="record">
      <!-- Section: Personal Details -->
      <div class="section-card">
        <h3 class="section-title">Personal Details</h3>
        <dl class="detail-grid">
          <dt>Full Name</dt><dd>{{ fmt([record.first_name, record.middle_name, record.last_name].filter(Boolean).join(" ")) }}</dd>
          <dt>Date of Birth</dt><dd>{{ fmtDate(record.date_of_birth) }}</dd>
          <dt>Gender</dt><dd>{{ fmt(record.gender) }}</dd>
          <dt>Marital Status</dt><dd>{{ fmt(record.marital_status) }}</dd>
          <dt>ID Type</dt><dd>{{ fmt(record.national_id_type) }}</dd>
          <dt>ID Number</dt><dd class="sensitive">{{ fmt(record.national_id_number) }}</dd>
          <dt>Phone</dt><dd>{{ fmt(record.phone_number) }}</dd>
          <dt>Personal Email</dt><dd>{{ fmt(record.personal_email) }}</dd>
          <dt>Physical Address</dt><dd>{{ fmt(record.physical_address) }}</dd>
          <dt>Consent Captured</dt>
          <dd>
            <span :class="record.consent_captured ? 'yes-badge' : 'no-badge'">
              {{ record.consent_captured ? "Yes" : "No" }}
            </span>
          </dd>
        </dl>
      </div>

      <!-- Section: Employment -->
      <div class="section-card">
        <h3 class="section-title">Employment Details</h3>
        <dl class="detail-grid">
          <dt>Company</dt><dd>{{ fmt(record.company) }}</dd>
          <dt>Department</dt><dd>{{ fmt(record.department) }}</dd>
          <dt>Designation</dt><dd>{{ fmt(record.designation) }}</dd>
          <dt>Employment Type</dt><dd>{{ fmt(record.employment_type) }}</dd>
          <dt>Date of Hire</dt><dd>{{ fmtDate(record.date_of_hire) }}</dd>
          <dt>Reporting Manager</dt><dd>{{ fmt(record.reporting_manager) }}</dd>
          <dt>Work Email</dt><dd>{{ fmt(record.work_email) }}</dd>
          <dt>Work Location</dt><dd>{{ fmt(record.work_location) }}</dd>
          <dt>Probation End</dt><dd>{{ fmt(record.probation_period_months ? record.probation_period_months + ' months' : null) }}</dd>
          <dt>Group / Function</dt><dd>{{ fmt(record.group_function) }}</dd>
        </dl>
      </div>

      <!-- Section: Statutory (sensitive — masked for non-HR) -->
      <div class="section-card">
        <h3 class="section-title">Statutory &amp; Banking</h3>
        <dl class="detail-grid">
          <dt>KRA PIN</dt><dd class="sensitive">{{ fmt(record.kra_pin) }}</dd>
          <dt>NSSF Number</dt><dd class="sensitive">{{ fmt(record.nssf_number) }}</dd>
          <dt>NHIF / SHA Number</dt><dd class="sensitive">{{ fmt(record.nhif_number || record.sha_number) }}</dd>
          <dt>Bank Name</dt><dd>{{ fmt(record.bank_name) }}</dd>
          <dt>Bank Branch</dt><dd>{{ fmt(record.bank_branch) }}</dd>
          <dt>Account Name</dt><dd>{{ fmt(record.account_name) }}</dd>
          <dt>Account Number</dt><dd class="sensitive">{{ fmt(record.account_number) }}</dd>
          <dt>Tax Obligations</dt><dd>{{ record.tax_obligations_flag ? 'Has additional obligations' : 'Standard' }}</dd>
        </dl>
      </div>

      <!-- Section: Qualifications -->
      <div v-if="record.qualifications?.length" class="section-card">
        <h3 class="section-title">Qualifications</h3>
        <table class="inner-table">
          <thead>
            <tr>
              <th>Type</th>
              <th>Institution</th>
              <th>Course / Certification</th>
              <th>Year</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(q, i) in record.qualifications" :key="i">
              <td>{{ q.qualification_type }}</td>
              <td>{{ q.institution_name }}</td>
              <td>{{ q.course_or_certification }}</td>
              <td>{{ q.year_completed }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Section: Next of Kin -->
      <div v-if="record.next_of_kin?.length" class="section-card">
        <h3 class="section-title">Next of Kin</h3>
        <table class="inner-table">
          <thead>
            <tr><th>Name</th><th>Relationship</th><th>Phone</th><th>Email</th></tr>
          </thead>
          <tbody>
            <tr v-for="(n, i) in record.next_of_kin" :key="i">
              <td>{{ n.full_name }}</td>
              <td>{{ n.relationship }}</td>
              <td>{{ n.phone_number }}</td>
              <td>{{ n.email || "—" }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Section: Emergency Contacts -->
      <div v-if="record.emergency_contacts?.length" class="section-card">
        <h3 class="section-title">Emergency Contacts</h3>
        <table class="inner-table">
          <thead>
            <tr><th>Name</th><th>Relationship</th><th>Phone</th><th>Alt Phone</th></tr>
          </thead>
          <tbody>
            <tr v-for="(e, i) in record.emergency_contacts" :key="i">
              <td>{{ e.full_name }}</td>
              <td>{{ e.relationship }}</td>
              <td>{{ e.phone_number }}</td>
              <td>{{ e.alternate_phone || "—" }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Section: Workflow notes -->
      <div v-if="record.approval_comments || record.rejection_reason" class="section-card">
        <h3 class="section-title">Review Notes</h3>
        <dl class="detail-grid">
          <template v-if="record.approval_comments">
            <dt>Approval Comments</dt><dd>{{ record.approval_comments }}</dd>
          </template>
          <template v-if="record.rejection_reason">
            <dt>Rejection Reason</dt><dd class="rejection-note">{{ record.rejection_reason }}</dd>
          </template>
        </dl>
      </div>

      <!-- Linked Employee -->
      <div v-if="record.employee" class="section-card linked-employee">
        <h3 class="section-title">System Record</h3>
        <p>Linked Employee: <strong class="mono">{{ record.employee }}</strong></p>
      </div>
    </template>
  </div>
</template>

<style scoped>
.view-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem; }
.back-btn { background: none; border: none; color: var(--primary); cursor: pointer; font-size: 0.85rem; padding: 0; margin-bottom: 0.4rem; display: block; }
.view-sub { font-size: 0.75rem; color: var(--text-muted); font-family: monospace; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 0.73rem; font-weight: 600; margin-left: 0.5rem; vertical-align: middle; }
.badge-gray { background: #edf2f7; color: #4a5568; }
.badge-yellow { background: #fefcbf; color: #744210; }
.badge-blue { background: #ebf8ff; color: #2b6cb0; }
.badge-green { background: #f0fff4; color: #276749; }
.badge-red { background: #fff5f5; color: #c53030; }

.action-buttons { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.btn-approve { background: #38a169; color: #fff; border: none; padding: 0.45rem 0.9rem; border-radius: 6px; font-size: 0.85rem; cursor: pointer; }
.btn-reject { background: #e53e3e; color: #fff; border: none; padding: 0.45rem 0.9rem; border-radius: 6px; font-size: 0.85rem; cursor: pointer; }
.btn-reopen { background: #718096; color: #fff; border: none; padding: 0.45rem 0.9rem; border-radius: 6px; font-size: 0.85rem; cursor: pointer; }
.btn-onboard { background: var(--primary); color: #fff; border: none; padding: 0.45rem 0.9rem; border-radius: 6px; font-size: 0.85rem; cursor: pointer; }
button.secondary { background: #fff; border: 1px solid var(--border); padding: 0.45rem 0.9rem; border-radius: 6px; font-size: 0.85rem; cursor: pointer; }
button:disabled { opacity: 0.45; cursor: not-allowed; }

.action-panel { background: #fff; border: 1px solid var(--border); border-radius: 8px; padding: 1.25rem; margin-bottom: 1rem; }
.approve-panel { border-color: #c6f6d5; background: #f0fff4; }
.reject-panel { border-color: #fed7d7; background: #fff5f5; }
.action-panel h4 { margin-bottom: 0.75rem; font-size: 0.95rem; }
.action-panel textarea { width: 100%; padding: 0.5rem; border: 1px solid var(--border); border-radius: 6px; font-size: 0.875rem; resize: vertical; }
.panel-actions { display: flex; gap: 0.5rem; margin-top: 0.75rem; }

.section-card { background: #fff; border: 1px solid var(--border); border-radius: 10px; padding: 1.25rem; margin-bottom: 1rem; }
.section-title { font-size: 0.85rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); border-bottom: 1px solid var(--border); padding-bottom: 0.5rem; margin-bottom: 1rem; }
.detail-grid { display: grid; grid-template-columns: 160px 1fr; gap: 0.5rem 1rem; font-size: 0.875rem; margin: 0; }
dt { color: var(--text-muted); font-weight: 500; }
dd { margin: 0; }
.sensitive { font-family: monospace; font-size: 0.82rem; }
.yes-badge { background: #f0fff4; color: #276749; padding: 1px 7px; border-radius: 10px; font-size: 0.78rem; font-weight: 600; }
.no-badge { background: #fff5f5; color: #c53030; padding: 1px 7px; border-radius: 10px; font-size: 0.78rem; font-weight: 600; }
.rejection-note { color: #c53030; }

.inner-table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
.inner-table th { background: #f7f8fa; padding: 0.5rem 0.75rem; text-align: left; font-size: 0.73rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); border-bottom: 1px solid var(--border); }
.inner-table td { padding: 0.55rem 0.75rem; border-bottom: 1px solid var(--border); }

.linked-employee { border-color: #bee3f8; background: #ebf8ff; }
.linked-employee p { font-size: 0.875rem; color: #2b6cb0; }
.mono { font-family: monospace; }

.loading-state { display: flex; align-items: center; gap: 0.5rem; padding: 2rem; color: var(--text-muted); font-size: 0.875rem; }
.spinner { width: 16px; height: 16px; border: 2px solid var(--border); border-top-color: var(--primary); border-radius: 50%; animation: spin 0.7s linear infinite; display: inline-block; }
.btn-spinner { border-color: rgba(255,255,255,0.4); border-top-color: #fff; }
@keyframes spin { to { transform: rotate(360deg); } }
.error-banner { background: #fff5f5; color: #c53030; padding: 0.75rem 1rem; border-radius: 6px; font-size: 0.875rem; margin-bottom: 1rem; }
.success-banner { background: #f0fff4; color: #276749; padding: 0.75rem 1rem; border-radius: 6px; font-size: 0.875rem; margin-bottom: 1rem; }
</style>
