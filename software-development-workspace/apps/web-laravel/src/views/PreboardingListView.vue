<script setup lang="ts">
import { ref, computed, onMounted, watch } from "vue";
import { useRouter } from "vue-router";
import { api } from "../lib/api";
import { currentOrgSlug } from "../stores/auth";

interface PreboardingRecord {
  id: number;
  first_name: string;
  last_name: string;
  email: string;
  phone: string | null;
  position: string | null;
  expected_start_date: string | null;
  status: string;
  welcome_sent_at: string | null;
  announcement_sent_at: string | null;
  sections: Array<{ section: string; status: string }>;
  created_at: string;
}

interface Paginator<T> {
  data: T[];
  meta: { total: number; last_page: number; current_page: number };
}

const router   = useRouter();
const records  = ref<PreboardingRecord[]>([]);
const total    = ref(0);
const lastPage = ref(1);
const page     = ref(1);
const search   = ref("");
const filterStatus = ref("");
const loading  = ref(false);
const error    = ref("");
const perPage  = 20;

const hasPrev = computed(() => page.value > 1);
const hasNext = computed(() => page.value < lastPage.value);

function org() { return currentOrgSlug.value; }

async function load(p = 1) {
  if (!org()) { error.value = "Select an organisation first."; return; }
  loading.value = true;
  error.value   = "";
  page.value    = p;
  try {
    const res = await api<Paginator<PreboardingRecord>>("GET", "/api/v1/preboarding", {
      org: org(),
      params: {
        page: p, per_page: perPage,
        search: search.value || undefined,
        status: filterStatus.value || undefined,
      },
    });
    records.value  = res.data;
    total.value    = res.meta.total;
    lastPage.value = res.meta.last_page;
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to load preboarding records.";
  } finally {
    loading.value = false;
  }
}

// Initiate preboarding form
const showCreate = ref(false);
const creating   = ref(false);
const createErr  = ref("");

const ALL_SECTIONS = [
  { key: "self_intro",     label: "Self Introduction" },
  { key: "personal",       label: "Personal Details" },
  { key: "contact",        label: "Contact Details" },
  { key: "emergency",      label: "Emergency Contacts" },
  { key: "dependents",     label: "Dependents" },
  { key: "immigration",    label: "Immigration" },
  { key: "qualifications", label: "Qualifications" },
  { key: "memberships",    label: "Memberships" },
  { key: "attachments",    label: "Forms & Attachments" },
];

const form = ref({
  first_name: "",
  last_name: "",
  email: "",
  phone: "",
  position: "",
  employee_type: "permanent",
  expected_start_date: "",
  medical_cover_eligible: false,
  link_expiry_days: 3,
  enabled_sections: ALL_SECTIONS.map(s => s.key), // all on by default
});

function resetForm() {
  form.value = {
    first_name: "", last_name: "", email: "", phone: "", position: "",
    employee_type: "permanent", expected_start_date: "",
    medical_cover_eligible: false, link_expiry_days: 3,
    enabled_sections: ALL_SECTIONS.map(s => s.key),
  };
}

// Auto-toggle dependents when medical cover eligibility changes
watch(() => form.value.medical_cover_eligible, (eligible) => {
  const idx = form.value.enabled_sections.indexOf("dependents");
  if (eligible && idx === -1) {
    form.value.enabled_sections.push("dependents");
  } else if (!eligible && idx !== -1) {
    form.value.enabled_sections.splice(idx, 1);
  }
});

async function createRecord() {
  if (!form.value.first_name || !form.value.last_name || !form.value.email) {
    createErr.value = "First name, last name and email are required."; return;
  }
  creating.value = true; createErr.value = "";
  try {
    const rec = await api<PreboardingRecord>("POST", "/api/v1/preboarding", {
      org: org()!, body: { ...form.value }
    });
    showCreate.value = false;
    resetForm();
    router.push({ name: "preboarding-detail", params: { id: rec.id } });
  } catch (e) {
    createErr.value = e instanceof Error ? e.message : "Failed to create.";
  } finally {
    creating.value = false;
  }
}

function progressPercent(record: PreboardingRecord): number {
  const active = record.sections.filter(s => s.status !== "not_applicable" && s.section !== "employment");
  if (!active.length) return 0;
  const done = active.filter(s => s.status === "submitted" || s.status === "approved").length;
  return Math.round(done / active.length * 100);
}

function statusColor(status: string): string {
  const m: Record<string,string> = {
    pending: "#6b7280", in_progress: "#0ea5e9",
    completed: "#059669", converted: "#8b5cf6",
  };
  return m[status] ?? "#9ca3af";
}

function statusLabel(status: string): string {
  const m: Record<string,string> = {
    pending: "Pending", in_progress: "In Progress",
    completed: "Completed", converted: "Converted",
  };
  return m[status] ?? status;
}

function initials(r: PreboardingRecord) {
  return (r.first_name[0] + r.last_name[0]).toUpperCase();
}

onMounted(() => load(1));
watch(currentOrgSlug, () => load(1));
watch([search, filterStatus], () => load(1));
</script>

<template>
  <div class="view">
    <div class="pb-header">
      <div class="pb-header-left">
        <h2 class="page-title">Pre-Onboarding</h2>
        <span class="total-badge">{{ total }} total</span>
      </div>
      <button class="btn-primary" @click="showCreate = true">+ Initiate Preboarding</button>
    </div>

    <!-- Filters -->
    <div class="pb-filters">
      <input v-model="search" class="pb-search" placeholder="Search by name, email, position…" />
      <select v-model="filterStatus" class="pb-filter-select">
        <option value="">All Statuses</option>
        <option value="pending">Pending</option>
        <option value="in_progress">In Progress</option>
        <option value="completed">Completed</option>
        <option value="converted">Converted</option>
      </select>
    </div>

    <div v-if="error" class="alert-error">{{ error }}</div>
    <div v-if="loading" class="loading-row">Loading…</div>

    <!-- Cards grid -->
    <div v-else-if="records.length" class="pb-grid">
      <div
        v-for="rec in records"
        :key="rec.id"
        class="pb-card"
        @click="router.push({ name: 'preboarding-detail', params: { id: rec.id } })"
      >
        <div class="pb-card-top">
          <div class="pb-avatar">{{ initials(rec) }}</div>
          <div class="pb-card-meta">
            <div class="pb-name">{{ rec.first_name }} {{ rec.last_name }}</div>
            <div class="pb-pos">{{ rec.position || "—" }}</div>
            <div class="pb-email">{{ rec.email }}</div>
          </div>
          <span class="pb-status-badge" :style="{ background: statusColor(rec.status) + '22', color: statusColor(rec.status) }">
            {{ statusLabel(rec.status) }}
          </span>
        </div>

        <div class="pb-progress-row">
          <div class="pb-progress-bar">
            <div class="pb-progress-fill" :style="{ width: progressPercent(rec) + '%' }"></div>
          </div>
          <span class="pb-progress-pct">{{ progressPercent(rec) }}%</span>
        </div>

        <div class="pb-card-footer">
          <span v-if="rec.expected_start_date" class="pb-footer-item">
            🗓 Start: {{ new Date(rec.expected_start_date).toLocaleDateString() }}
          </span>
          <span v-if="rec.welcome_sent_at" class="pb-footer-item pb-sent">✓ Welcome sent</span>
          <span v-else class="pb-footer-item pb-pending">⏳ Welcome pending</span>
        </div>
      </div>
    </div>

    <div v-else class="empty-state">
      <p>No preboarding records found.</p>
      <button class="btn-primary" @click="showCreate = true">Initiate First Preboarding</button>
    </div>

    <!-- Pagination -->
    <div v-if="lastPage > 1" class="pagination">
      <button :disabled="!hasPrev" @click="load(page - 1)">← Prev</button>
      <span>Page {{ page }} of {{ lastPage }}</span>
      <button :disabled="!hasNext" @click="load(page + 1)">Next →</button>
    </div>

    <!-- Create modal -->
    <div v-if="showCreate" class="pb-modal-overlay" @click.self="showCreate = false">
      <div class="pb-modal">
        <div class="pb-modal-header">
          <h3>Initiate Pre-Onboarding</h3>
          <button class="pb-modal-close" @click="showCreate = false">✕</button>
        </div>
        <div class="pb-modal-body">
          <div v-if="createErr" class="alert-error">{{ createErr }}</div>

          <!-- Basic info -->
          <div class="pb-modal-section-label">Candidate Details</div>
          <div class="pb-form-grid">
            <div class="pb-field">
              <label>First Name *</label>
              <input v-model="form.first_name" class="pb-input" placeholder="Jane" />
            </div>
            <div class="pb-field">
              <label>Last Name *</label>
              <input v-model="form.last_name" class="pb-input" placeholder="Doe" />
            </div>
            <div class="pb-field pb-field-full">
              <label>Email *</label>
              <input v-model="form.email" type="email" class="pb-input" placeholder="jane@example.com" />
            </div>
            <div class="pb-field">
              <label>Phone</label>
              <input v-model="form.phone" class="pb-input" placeholder="+254 700 000000" />
            </div>
            <div class="pb-field">
              <label>Position / Role</label>
              <input v-model="form.position" class="pb-input" placeholder="Software Engineer" />
            </div>
            <div class="pb-field">
              <label>Employee Type</label>
              <select v-model="form.employee_type" class="pb-input">
                <option value="permanent">Permanent</option>
                <option value="contract">Contract</option>
                <option value="intern">Intern</option>
                <option value="probation">Probation</option>
              </select>
            </div>
            <div class="pb-field">
              <label>Expected Start Date</label>
              <input v-model="form.expected_start_date" type="date" class="pb-input" />
            </div>
          </div>

          <!-- Form settings -->
          <div class="pb-modal-section-label" style="margin-top:18px">Form Settings</div>
          <div class="pb-form-grid">
            <div class="pb-field">
              <label>Link Valid For (days)</label>
              <input v-model.number="form.link_expiry_days" type="number" min="1" max="30" class="pb-input" />
              <span class="pb-field-hint">Pre-hire must complete within this period.</span>
            </div>
            <div class="pb-field pb-field-checkbox-group">
              <label>Medical Cover Eligible</label>
              <label class="pb-checkbox-label">
                <input type="checkbox" v-model="form.medical_cover_eligible" class="pb-checkbox" />
                <span>Eligible for medical cover (enables Dependents section)</span>
              </label>
            </div>
          </div>

          <!-- Sections to enable -->
          <div class="pb-modal-section-label" style="margin-top:18px">Form Sections to Collect</div>
          <div class="pb-sections-grid">
            <label
              v-for="sec in ALL_SECTIONS"
              :key="sec.key"
              class="pb-section-toggle"
              :class="{ disabled: sec.key === 'dependents' && !form.medical_cover_eligible }"
            >
              <input
                type="checkbox"
                :value="sec.key"
                v-model="form.enabled_sections"
                :disabled="sec.key === 'dependents' && !form.medical_cover_eligible"
                class="pb-checkbox"
              />
              <span>{{ sec.label }}</span>
            </label>
          </div>
          <p class="pb-hint-text">
            The pre-hire will only see and complete the selected sections. Dependents section requires medical cover eligibility.
          </p>
        </div>
        <div class="pb-modal-footer">
          <button class="btn-secondary" @click="showCreate = false; resetForm()">Cancel</button>
          <button class="btn-primary" :disabled="creating" @click="createRecord">
            {{ creating ? "Creating…" : "Initiate Preboarding" }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.pb-header        { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.pb-header-left   { display: flex; align-items: center; gap: 12px; }
.page-title       { margin: 0; font-size: 22px; font-weight: 700; }
.total-badge      { background: var(--color-neutral-100); color: var(--color-text-muted); font-size: 12px; padding: 3px 10px; border-radius: 20px; }

.pb-filters       { display: flex; gap: 10px; margin-bottom: 20px; }
.pb-search        { flex: 1; padding: 9px 14px; border: 1px solid var(--color-border); border-radius: var(--radius-md); font-size: 14px; }
.pb-filter-select { padding: 9px 12px; border: 1px solid var(--color-border); border-radius: var(--radius-md); font-size: 14px; background: #fff; }

.pb-grid          { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px; }

.pb-card {
  background: #fff; border: 1px solid var(--color-border); border-radius: var(--radius-lg);
  padding: 18px; cursor: pointer; transition: box-shadow .15s, border-color .15s;
}
.pb-card:hover    { box-shadow: 0 4px 16px rgba(0,0,0,.1); border-color: var(--color-brand-base); }

.pb-card-top      { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 14px; }
.pb-avatar        {
  width: 42px; height: 42px; border-radius: 50%; background: var(--color-brand-base);
  color: #fff; font-weight: 700; font-size: 15px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.pb-card-meta     { flex: 1; min-width: 0; }
.pb-name          { font-weight: 600; font-size: 15px; }
.pb-pos           { font-size: 13px; color: var(--color-text-muted); }
.pb-email         { font-size: 12px; color: var(--color-text-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.pb-status-badge  { font-size: 11px; font-weight: 600; padding: 3px 10px; border-radius: 20px; white-space: nowrap; flex-shrink: 0; }

.pb-progress-row  { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
.pb-progress-bar  { flex: 1; height: 6px; background: var(--color-neutral-200); border-radius: 3px; overflow: hidden; }
.pb-progress-fill { height: 100%; background: var(--color-brand-base); border-radius: 3px; transition: width .3s; }
.pb-progress-pct  { font-size: 12px; color: var(--color-text-muted); min-width: 30px; text-align: right; }

.pb-card-footer   { display: flex; flex-wrap: wrap; gap: 8px; }
.pb-footer-item   { font-size: 12px; color: var(--color-text-muted); }
.pb-sent          { color: #059669; }
.pb-pending       { color: #d97706; }

.loading-row      { text-align: center; padding: 40px; color: var(--color-text-muted); }
.empty-state      { text-align: center; padding: 60px 20px; color: var(--color-text-muted); }
.empty-state button { margin-top: 12px; }

.pagination       { display: flex; align-items: center; justify-content: center; gap: 16px; margin-top: 24px; }
.pagination button { padding: 7px 16px; border: 1px solid var(--color-border); border-radius: var(--radius-md); background: #fff; cursor: pointer; }
.pagination button:disabled { opacity: .4; cursor: default; }

.alert-error      { background: #fef2f2; border: 1px solid #fca5a5; color: #dc2626; border-radius: var(--radius-md); padding: 10px 14px; margin-bottom: 14px; font-size: 13px; }

.pb-modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.45); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.pb-modal         { background: #fff; border-radius: var(--radius-lg); width: min(540px,95vw); max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,.25); }
.pb-modal-header  { display: flex; justify-content: space-between; align-items: center; padding: 20px 24px 16px; border-bottom: 1px solid var(--color-border); }
.pb-modal-header h3 { margin: 0; font-size: 17px; }
.pb-modal-close   { background: none; border: none; font-size: 18px; cursor: pointer; color: var(--color-text-muted); padding: 4px; }
.pb-modal-body    { padding: 20px 24px; }
.pb-modal-footer  { display: flex; justify-content: flex-end; gap: 10px; padding: 16px 24px; border-top: 1px solid var(--color-border); }

.pb-form-grid     { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.pb-field         { display: flex; flex-direction: column; gap: 5px; }
.pb-field-full    { grid-column: span 2; }
.pb-field label   { font-size: 13px; font-weight: 500; color: var(--color-text); }
.pb-input         { padding: 8px 12px; border: 1px solid var(--color-border); border-radius: var(--radius-md); font-size: 14px; }
.pb-input:focus   { outline: none; border-color: var(--color-brand-base); }

.btn-primary   { padding: 9px 18px; background: var(--color-brand-base); color: #fff; border: none; border-radius: var(--radius-md); font-size: 14px; font-weight: 500; cursor: pointer; }
.btn-primary:disabled { opacity: .6; cursor: default; }
.btn-secondary { padding: 9px 18px; background: #fff; color: var(--color-text); border: 1px solid var(--color-border); border-radius: var(--radius-md); font-size: 14px; cursor: pointer; }

.pb-modal-section-label {
  font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px;
  color: var(--color-text-muted); margin-bottom: 10px;
}
.pb-field-hint { font-size: 11px; color: var(--color-text-muted); margin-top: 2px; }
.pb-field-checkbox-group { justify-content: flex-start; padding-top: 6px; }
.pb-checkbox-label { display: flex; align-items: center; gap: 8px; font-size: 13px; cursor: pointer; margin-top: 4px; }
.pb-checkbox { width: 15px; height: 15px; accent-color: var(--color-brand-base); flex-shrink: 0; }
.pb-sections-grid {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
  gap: 8px; margin-bottom: 8px;
}
.pb-section-toggle {
  display: flex; align-items: center; gap: 8px; font-size: 13px; cursor: pointer;
  padding: 6px 10px; border: 1px solid var(--color-border); border-radius: var(--radius-md);
  transition: border-color .15s, background .15s;
}
.pb-section-toggle:has(input:checked) { border-color: var(--color-brand-base); background: #fff0f1; }
.pb-section-toggle.disabled { opacity: .45; cursor: not-allowed; }
.pb-hint-text { font-size: 12px; color: var(--color-text-muted); line-height: 1.5; margin: 0; }

@media (max-width: 540px) {
  .pb-form-grid { grid-template-columns: 1fr; }
  .pb-field-full { grid-column: span 1; }
}
</style>
