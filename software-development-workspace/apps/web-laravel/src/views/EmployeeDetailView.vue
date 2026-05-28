<script setup lang="ts">
import { ref, onMounted, reactive, computed } from "vue";
import { useRouter } from "vue-router";
import { api } from "../lib/api";
import { currentOrgSlug } from "../stores/auth";
import type { Employee, EmergencyContact } from "../lib/types";

const props  = defineProps<{ id: string }>();
const router = useRouter();

const employee = ref<Employee | null>(null);
const loading  = ref(false);
const error    = ref("");

type Tab = "overview" | "career" | "profiles" | "benefits";
const tab = ref<Tab>("overview");

// Emergency contact form
const showECForm  = ref(false);
const ecSaving    = ref(false);
const ecError     = ref("");
const editingEC   = ref<EmergencyContact | null>(null);
const ecForm = reactive({ name: "", relationship: "", phone: "", email: "", is_primary: false });

// Exit form
const showExit   = ref(false);
const exitDate   = ref("");
const exitReason = ref("");
const exiting    = ref(false);
const exitError  = ref("");

// Compensation edit
const showCompEdit = ref(false);
const compSaving   = ref(false);
const compError    = ref("");
const compSuccess  = ref("");
const comp = reactive({
  basic_salary: 0, house_allowance: 0, transport_allowance: 0,
  medical_allowance: 0, other_allowances: 0, insurance_relief: 0,
});

function org(): string | null { return currentOrgSlug.value; }

// Sensitive fields are only returned by the API when the viewer is HR or self.
// We detect access by checking if any sensitive-only field key is present.
const hasSensitive = computed(() => {
  const e = employee.value as any;
  if (!e) return false;
  return e.tax_id !== undefined || e.blood_group !== undefined || Array.isArray(e.emergency_contacts);
});

function fmt(n: number | string | null | undefined): string {
  return new Intl.NumberFormat("en-KE", { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(n ?? 0));
}

function fmtDate(d: string | null | undefined): string {
  if (!d) return "—";
  try { return new Date(d).toLocaleDateString("en-KE", { year: "numeric", month: "long", day: "numeric" }); }
  catch { return d; }
}

function calcAge(dob: string | null | undefined): string {
  if (!dob) return "—";
  const diff = Date.now() - new Date(dob).getTime();
  const age  = Math.floor(diff / (365.25 * 24 * 60 * 60 * 1000));
  return `${age} yrs`;
}

function initials(e: any): string {
  return ((e.first_name?.[0] ?? "") + (e.last_name?.[0] ?? "")).toUpperCase() || "?";
}

const grossPay = computed(() =>
  comp.basic_salary + comp.house_allowance + comp.transport_allowance +
  comp.medical_allowance + comp.other_allowances
);

const empGross = computed(() => {
  const e = employee.value as any;
  if (!e) return 0;
  return Number(e.basic_salary ?? 0) + Number(e.house_allowance ?? 0) +
         Number(e.transport_allowance ?? 0) + Number(e.medical_allowance ?? 0) +
         Number(e.other_allowances ?? 0);
});

async function load(): Promise<void> {
  loading.value = true; error.value = "";
  try {
    const res = await api<{ data: Employee }>("GET", `/api/v1/employees/${props.id}`, { org: org() });
    employee.value = (res as any).data ?? res;
    const e = employee.value as any;
    comp.basic_salary        = Number(e.basic_salary ?? 0);
    comp.house_allowance     = Number(e.house_allowance ?? 0);
    comp.transport_allowance = Number(e.transport_allowance ?? 0);
    comp.medical_allowance   = Number(e.medical_allowance ?? 0);
    comp.other_allowances    = Number(e.other_allowances ?? 0);
    comp.insurance_relief    = Number(e.insurance_relief ?? 0);
  } catch (err) {
    error.value = err instanceof Error ? err.message : "Failed to load employee.";
  } finally { loading.value = false; }
}

async function saveComp(): Promise<void> {
  compSaving.value = true; compError.value = ""; compSuccess.value = "";
  try {
    const res = await api<{ data: Employee }>("PUT", `/api/v1/employees/${props.id}`, {
      org: org(), body: { ...comp },
    });
    const updated = (res as any).data ?? res;
    employee.value = updated;
    const e = updated as any;
    comp.basic_salary        = Number(e.basic_salary ?? 0);
    comp.house_allowance     = Number(e.house_allowance ?? 0);
    comp.transport_allowance = Number(e.transport_allowance ?? 0);
    comp.medical_allowance   = Number(e.medical_allowance ?? 0);
    comp.other_allowances    = Number(e.other_allowances ?? 0);
    comp.insurance_relief    = Number(e.insurance_relief ?? 0);
    compSuccess.value = "Saved.";
    showCompEdit.value = false;
    setTimeout(() => { compSuccess.value = ""; }, 3000);
  } catch (err) { compError.value = err instanceof Error ? err.message : "Failed."; }
  finally { compSaving.value = false; }
}

async function exitEmployee(): Promise<void> {
  if (!exitDate.value) { exitError.value = "Exit date is required."; return; }
  exiting.value = true; exitError.value = "";
  try {
    await api("POST", `/api/v1/employees/${props.id}/exit`, {
      org: org(),
      body: { date_exited: exitDate.value, exit_reason: exitReason.value || null },
    });
    await load();
    showExit.value = false;
  } catch (err) {
    exitError.value = err instanceof Error ? err.message : "Failed to process exit.";
  } finally { exiting.value = false; }
}

// ── Emergency contacts ─────────────────────────────────────────────────────
function openAddEC() {
  editingEC.value = null;
  Object.assign(ecForm, { name: "", relationship: "", phone: "", email: "", is_primary: false });
  ecError.value = "";
  showECForm.value = true;
}

function openEditEC(c: EmergencyContact) {
  editingEC.value = c;
  Object.assign(ecForm, { name: c.name, relationship: c.relationship, phone: c.phone, email: c.email ?? "", is_primary: c.is_primary });
  ecError.value = "";
  showECForm.value = true;
}

async function saveEC() {
  ecSaving.value = true; ecError.value = "";
  try {
    if (editingEC.value) {
      await api("PUT", `/api/v1/employees/${props.id}/emergency-contacts/${editingEC.value.id}`, { org: org(), body: { ...ecForm } });
    } else {
      await api("POST", `/api/v1/employees/${props.id}/emergency-contacts`, { org: org(), body: { ...ecForm } });
    }
    showECForm.value = false;
    await load();
  } catch (e) { ecError.value = e instanceof Error ? e.message : "Failed to save."; }
  finally { ecSaving.value = false; }
}

async function deleteEC(id: number) {
  if (!confirm("Remove this emergency contact?")) return;
  await api("DELETE", `/api/v1/employees/${props.id}/emergency-contacts/${id}`, { org: org() });
  await load();
}

function statusBadge(status: string): string {
  return { active: "badge-green", inactive: "badge-yellow", exited: "badge-gray" }[status] ?? "badge-gray";
}

onMounted(load);
</script>

<template>
  <div class="ed-root">

    <!-- Breadcrumb -->
    <nav class="ed-breadcrumb">
      <span class="ed-bc-link" @click="router.push({ name: 'employees' })">Employees</span>
      <span class="ed-bc-sep">/</span>
      <span class="ed-bc-current">{{ employee ? (employee as any).full_name : "…" }}</span>
    </nav>

    <p v-if="loading" class="status">Loading…</p>
    <p v-if="error"   class="error">{{ error }}</p>

    <div v-if="employee">

      <!-- ── PROFILE CARD ──────────────────────────────────────────────────── -->
      <div class="ed-profile-card">
        <div class="ed-avatar">{{ initials(employee) }}</div>
        <div class="ed-profile-main">
          <div class="ed-profile-top">
            <div>
              <h2 class="ed-name">{{ (employee as any).full_name }}</h2>
              <p class="ed-sub">
                {{ (employee as any).designation?.title ?? (employee as any).designation?.name ?? "No designation" }}
                &nbsp;·&nbsp;
                {{ (employee as any).department?.name ?? "No department" }}
              </p>
              <span class="ed-emp-id-badge">{{ (employee as any).employee_number }}</span>
            </div>
            <div class="ed-profile-actions">
              <span :class="['badge', statusBadge((employee as any).status)]" style="font-size:0.82rem;padding:5px 14px;text-transform:capitalize">
                {{ (employee as any).status }}
              </span>
              <router-link :to="{ name: 'employee-edit', params: { id: (employee as any).id } }" class="btn btn-outline btn-sm">✏ Edit</router-link>
            </div>
          </div>
        </div>
      </div>

      <!-- ── TAB NAV ───────────────────────────────────────────────────────── -->
      <div class="ed-tabs">
        <button :class="['ed-tab', { 'ed-tab-active': tab === 'overview'  }]" @click="tab = 'overview'">Overview</button>
        <button :class="['ed-tab', { 'ed-tab-active': tab === 'career'    }]" @click="tab = 'career'">Career</button>
        <button :class="['ed-tab', { 'ed-tab-active': tab === 'profiles'  }]" @click="tab = 'profiles'">Profiles</button>
        <button :class="['ed-tab', { 'ed-tab-active': tab === 'benefits'  }]" @click="tab = 'benefits'">Benefits</button>
        <router-link :to="{ name: 'employee-dependents', params: { id: (employee as any).id } }" class="ed-tab" active-class="ed-tab-active">Dependents</router-link>
        <router-link :to="{ name: 'employee-vehicles',   params: { id: (employee as any).id } }" class="ed-tab" active-class="ed-tab-active">Vehicles</router-link>
      </div>

      <!-- ── OVERVIEW ──────────────────────────────────────────────────────── -->
      <div v-if="tab === 'overview'" class="ed-tab-body">
        <div class="ed-two-col">

          <!-- CONTACT -->
          <div class="ed-section-card">
            <h3 class="ed-section-title">Contact</h3>
            <div class="ed-kv-list">
              <div class="ed-kv-row" v-if="(employee as any).phone !== undefined">
                <span class="ed-kv-label">Phone</span>
                <span class="ed-kv-val">{{ (employee as any).phone ?? "—" }}</span>
              </div>
              <div class="ed-kv-row">
                <span class="ed-kv-label">Work Email</span>
                <span class="ed-kv-val">{{ (employee as any).work_email ?? (employee as any).email ?? "—" }}</span>
              </div>
              <div class="ed-kv-row" v-if="(employee as any).personal_email !== undefined">
                <span class="ed-kv-label">Personal Email</span>
                <span class="ed-kv-val">{{ (employee as any).personal_email ?? "—" }}</span>
              </div>
              <div class="ed-kv-row" v-if="(employee as any).linkedin_profile !== undefined">
                <span class="ed-kv-label">LinkedIn</span>
                <span class="ed-kv-val">
                  <a v-if="(employee as any).linkedin_profile" :href="(employee as any).linkedin_profile" target="_blank" rel="noopener" class="ed-link">View Profile</a>
                  <span v-else>—</span>
                </span>
              </div>
              <div class="ed-kv-row" v-if="(employee as any).residential_address !== undefined || (employee as any).address !== undefined">
                <span class="ed-kv-label">Address</span>
                <span class="ed-kv-val ed-kv-wrap">{{ (employee as any).residential_address ?? (employee as any).address ?? "—" }}</span>
              </div>
              <div class="ed-kv-row" v-if="(employee as any).city">
                <span class="ed-kv-label">City</span>
                <span class="ed-kv-val">{{ (employee as any).city }}</span>
              </div>
            </div>
          </div>

          <!-- EMPLOYMENT -->
          <div class="ed-section-card">
            <h3 class="ed-section-title">Employment</h3>
            <div class="ed-kv-list">
              <div class="ed-kv-row">
                <span class="ed-kv-label">Employee No</span>
                <span class="ed-kv-val ed-kv-accent">{{ (employee as any).employee_number ?? "—" }}</span>
              </div>
              <div class="ed-kv-row">
                <span class="ed-kv-label">Type</span>
                <span class="ed-kv-val" style="text-transform:capitalize">{{ (employee as any).employment_type?.replace(/_/g, " ") ?? "—" }}</span>
              </div>
              <div class="ed-kv-row">
                <span class="ed-kv-label">Department</span>
                <span class="ed-kv-val">{{ (employee as any).department?.name ?? "—" }}</span>
              </div>
              <div class="ed-kv-row">
                <span class="ed-kv-label">Designation</span>
                <span class="ed-kv-val">{{ (employee as any).designation?.title ?? (employee as any).designation?.name ?? "—" }}</span>
              </div>
              <div class="ed-kv-row">
                <span class="ed-kv-label">Start Date</span>
                <span class="ed-kv-val">{{ fmtDate((employee as any).date_of_joining ?? (employee as any).date_hired) }}</span>
              </div>
              <div class="ed-kv-row" v-if="(employee as any).manager?.full_name">
                <span class="ed-kv-label">Reports To</span>
                <span class="ed-kv-val">{{ (employee as any).manager.full_name }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- ── Sensitive sections (only visible to HR or the employee themselves) ── -->
        <template v-if="hasSensitive">

          <!-- Personal Details + Statutory IDs -->
          <div class="ed-two-col">

            <!-- PERSONAL -->
            <div class="ed-section-card ed-sensitive">
              <h3 class="ed-section-title ed-sensitive-title">🔒 Personal Details</h3>
              <div class="ed-kv-list">
                <div class="ed-kv-row" v-if="(employee as any).gender !== undefined">
                  <span class="ed-kv-label">Gender</span>
                  <span class="ed-kv-val" style="text-transform:capitalize">{{ (employee as any).gender ?? "—" }}</span>
                </div>
                <div class="ed-kv-row" v-if="(employee as any).date_of_birth !== undefined">
                  <span class="ed-kv-label">Date of Birth</span>
                  <span class="ed-kv-val">{{ fmtDate((employee as any).date_of_birth) }}</span>
                </div>
                <div class="ed-kv-row" v-if="(employee as any).date_of_birth !== undefined">
                  <span class="ed-kv-label">Age</span>
                  <span class="ed-kv-val">{{ calcAge((employee as any).date_of_birth) }}</span>
                </div>
              </div>
            </div>

            <!-- STATUTORY IDs -->
            <div class="ed-section-card ed-sensitive">
              <h3 class="ed-section-title ed-sensitive-title">🔒 Statutory IDs</h3>
              <div class="ed-kv-list">
                <div class="ed-kv-row">
                  <span class="ed-kv-label">National ID</span>
                  <span class="ed-kv-val ed-kv-mono">{{ (employee as any).national_id ?? "—" }}</span>
                </div>
                <div class="ed-kv-row">
                  <span class="ed-kv-label">Passport No</span>
                  <span class="ed-kv-val ed-kv-mono">{{ (employee as any).passport_number ?? "—" }}</span>
                </div>
                <div class="ed-kv-row">
                  <span class="ed-kv-label">KRA PIN</span>
                  <span class="ed-kv-val ed-kv-mono">{{ (employee as any).tax_id ?? "—" }}</span>
                </div>
                <div class="ed-kv-row">
                  <span class="ed-kv-label">NSSF Number</span>
                  <span class="ed-kv-val ed-kv-mono">{{ (employee as any).nssf_number ?? "—" }}</span>
                </div>
                <div class="ed-kv-row">
                  <span class="ed-kv-label">SHA Number</span>
                  <span class="ed-kv-val ed-kv-mono">{{ (employee as any).nhif_number ?? "—" }}</span>
                </div>
              </div>
            </div>
          </div>

          <!-- HEALTH INFORMATION -->
          <div class="ed-section-card ed-sensitive ed-health">
            <h3 class="ed-section-title ed-health-title">🔒 Health Information</h3>
            <div class="ed-health-grid">
              <div class="ed-kv-list">
                <div class="ed-kv-row">
                  <span class="ed-kv-label">Blood Group</span>
                  <span class="ed-kv-val">{{ (employee as any).blood_group ?? "—" }}</span>
                </div>
                <div class="ed-kv-row">
                  <span class="ed-kv-label">Allergies</span>
                  <span class="ed-kv-val ed-kv-wrap">{{ (employee as any).allergies ?? "None declared" }}</span>
                </div>
              </div>
              <div class="ed-kv-list">
                <div class="ed-kv-row">
                  <span class="ed-kv-label">Known Conditions</span>
                  <span class="ed-kv-val ed-kv-wrap">{{ (employee as any).known_conditions ?? "None declared" }}</span>
                </div>
                <div class="ed-kv-row">
                  <span class="ed-kv-label">Disabilities</span>
                  <span class="ed-kv-val ed-kv-wrap">{{ (employee as any).disabilities ?? "None declared" }}</span>
                </div>
              </div>
            </div>
          </div>

          <!-- EMERGENCY CONTACTS -->
          <div class="ed-section-card ed-sensitive">
            <div class="ed-section-hdr" style="margin-bottom:14px">
              <h3 class="ed-section-title ed-sensitive-title" style="margin:0">🔒 Emergency Contacts</h3>
              <button class="btn btn-outline btn-sm" @click="openAddEC">+ Add</button>
            </div>

            <!-- Add/edit form -->
            <div v-if="showECForm" class="ed-ec-form">
              <p v-if="ecError" class="error" style="margin-bottom:8px">{{ ecError }}</p>
              <div class="ed-comp-form-grid ed-ec-grid">
                <div>
                  <label class="ed-form-label">Name *</label>
                  <input v-model="ecForm.name" type="text" class="input" placeholder="Full name" />
                </div>
                <div>
                  <label class="ed-form-label">Relationship *</label>
                  <input v-model="ecForm.relationship" type="text" class="input" placeholder="e.g. Spouse, Parent" />
                </div>
                <div>
                  <label class="ed-form-label">Phone *</label>
                  <input v-model="ecForm.phone" type="text" class="input" placeholder="+254…" />
                </div>
                <div>
                  <label class="ed-form-label">Email</label>
                  <input v-model="ecForm.email" type="email" class="input" />
                </div>
              </div>
              <div style="display:flex;align-items:center;gap:8px;margin-top:8px">
                <input type="checkbox" id="ec-primary" v-model="ecForm.is_primary" />
                <label for="ec-primary" style="font-size:0.82rem;cursor:pointer">Primary contact</label>
              </div>
              <div style="display:flex;gap:8px;margin-top:12px">
                <button class="btn btn-primary btn-sm" :disabled="ecSaving" @click="saveEC">{{ ecSaving ? "Saving…" : "Save" }}</button>
                <button class="btn btn-secondary btn-sm" @click="showECForm = false">Cancel</button>
              </div>
            </div>

            <!-- Contact cards -->
            <div v-if="(employee as any).emergency_contacts?.length" class="ed-ec-list">
              <div v-for="c in (employee as any).emergency_contacts" :key="c.id" class="ed-ec-card">
                <div class="ed-ec-top">
                  <span class="ed-ec-name">{{ c.name }}</span>
                  <span v-if="c.is_primary" class="ed-ec-primary-badge">Primary</span>
                  <span class="ed-ec-rel">{{ c.relationship }}</span>
                  <div class="ed-ec-actions">
                    <button class="act-btn" @click="openEditEC(c)">✏</button>
                    <button class="act-btn act-del" @click="deleteEC(c.id)">✕</button>
                  </div>
                </div>
                <div class="ed-ec-details">
                  <span>📞 {{ c.phone }}</span>
                  <span v-if="c.email">✉ {{ c.email }}</span>
                </div>
              </div>
            </div>
            <p v-else-if="!showECForm" class="ed-empty-hint">No emergency contacts added yet.</p>
          </div>

        </template><!-- /hasSensitive -->

      </div><!-- /overview -->

      <!-- ── CAREER ────────────────────────────────────────────────────────── -->
      <div v-if="tab === 'career'" class="ed-tab-body">
        <div class="ed-section-card">
          <h3 class="ed-section-title">Career Details</h3>
          <div class="ed-kv-list">
            <div class="ed-kv-row">
              <span class="ed-kv-label">Job Grade</span>
              <span class="ed-kv-val">{{ (employee as any).job_grade?.name ?? "—" }}</span>
            </div>
            <div class="ed-kv-row">
              <span class="ed-kv-label">Employment Type</span>
              <span class="ed-kv-val" style="text-transform:capitalize">{{ (employee as any).employment_type?.replace(/_/g, " ") ?? "—" }}</span>
            </div>
            <div class="ed-kv-row">
              <span class="ed-kv-label">Date of Joining</span>
              <span class="ed-kv-val">{{ fmtDate((employee as any).date_of_joining ?? (employee as any).date_hired) }}</span>
            </div>
            <div class="ed-kv-row">
              <span class="ed-kv-label">Probation End</span>
              <span class="ed-kv-val">{{ fmtDate((employee as any).probation_end_date) }}</span>
            </div>
            <div class="ed-kv-row" v-if="(employee as any).contract_end_date">
              <span class="ed-kv-label">Contract End</span>
              <span class="ed-kv-val">{{ fmtDate((employee as any).contract_end_date) }}</span>
            </div>
          </div>
        </div>

        <!-- Exit info (if exited) -->
        <div v-if="(employee as any).date_exited" class="ed-section-card ed-exit-card">
          <h3 class="ed-section-title" style="color:#dc2626">Exit Information</h3>
          <div class="ed-kv-list">
            <div class="ed-kv-row">
              <span class="ed-kv-label">Exit Date</span>
              <span class="ed-kv-val">{{ fmtDate((employee as any).date_exited) }}</span>
            </div>
            <div class="ed-kv-row" v-if="(employee as any).exit_reason">
              <span class="ed-kv-label">Reason</span>
              <span class="ed-kv-val">{{ (employee as any).exit_reason }}</span>
            </div>
          </div>
        </div>

        <!-- Process exit -->
        <div v-if="(employee as any).status === 'active'" class="ed-section-card">
          <div class="ed-section-hdr">
            <h3 class="ed-section-title" style="margin:0">Process Exit</h3>
            <button class="btn btn-danger btn-sm" @click="showExit = !showExit">
              {{ showExit ? "Cancel" : "Process Exit" }}
            </button>
          </div>
          <div v-if="showExit" style="margin-top:16px">
            <p class="error" v-if="exitError">{{ exitError }}</p>
            <div class="form-group">
              <label>Exit Date <span style="color:#dc2626">*</span></label>
              <input v-model="exitDate" type="date" class="input" :max="new Date().toISOString().substring(0,10)" />
            </div>
            <div class="form-group">
              <label>Exit Reason</label>
              <input v-model="exitReason" type="text" class="input" placeholder="Resignation, contract end, etc." />
            </div>
            <div style="display:flex;gap:8px">
              <button class="btn btn-danger" :disabled="exiting" @click="exitEmployee">
                {{ exiting ? "Processing…" : "Confirm Exit" }}
              </button>
              <button class="btn btn-secondary" @click="showExit = false">Cancel</button>
            </div>
          </div>
        </div>
      </div>

      <!-- ── PROFILES ──────────────────────────────────────────────────────── -->
      <div v-if="tab === 'profiles'" class="ed-tab-body">
        <div class="ed-two-col">

          <!-- PERSONAL -->
          <div class="ed-section-card">
            <h3 class="ed-section-title">Personal</h3>
            <div class="ed-kv-list">
              <div class="ed-kv-row">
                <span class="ed-kv-label">Gender</span>
                <span class="ed-kv-val" style="text-transform:capitalize">{{ (employee as any).gender ?? "—" }}</span>
              </div>
              <div class="ed-kv-row">
                <span class="ed-kv-label">Date of Birth</span>
                <span class="ed-kv-val">{{ fmtDate((employee as any).date_of_birth) }}</span>
              </div>
              <div class="ed-kv-row">
                <span class="ed-kv-label">City</span>
                <span class="ed-kv-val">{{ (employee as any).city ?? "—" }}</span>
              </div>
              <div class="ed-kv-row">
                <span class="ed-kv-label">Country</span>
                <span class="ed-kv-val">{{ (employee as any).country ?? "—" }}</span>
              </div>
            </div>
          </div>

          <!-- IDENTITY DOCUMENTS -->
          <div class="ed-section-card ed-sensitive">
            <h3 class="ed-section-title ed-sensitive-title">🔒 Identity Documents — Sensitive</h3>
            <div class="ed-kv-list">
              <div class="ed-kv-row">
                <span class="ed-kv-label">National ID</span>
                <span class="ed-kv-val ed-kv-mono">{{ (employee as any).national_id ?? "—" }}</span>
              </div>
              <div class="ed-kv-row">
                <span class="ed-kv-label">Passport No</span>
                <span class="ed-kv-val ed-kv-mono">{{ (employee as any).passport_number ?? "—" }}</span>
              </div>
              <div class="ed-kv-row">
                <span class="ed-kv-label">KRA PIN</span>
                <span class="ed-kv-val ed-kv-mono">{{ (employee as any).tax_id ?? "—" }}</span>
              </div>
              <div class="ed-kv-row">
                <span class="ed-kv-label">NSSF Number</span>
                <span class="ed-kv-val ed-kv-mono">{{ (employee as any).nssf_number ?? "—" }}</span>
              </div>
              <div class="ed-kv-row">
                <span class="ed-kv-label">NHIF / SHIF No</span>
                <span class="ed-kv-val ed-kv-mono">{{ (employee as any).nhif_number ?? "—" }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ── BENEFITS ──────────────────────────────────────────────────────── -->
      <div v-if="tab === 'benefits'" class="ed-tab-body">

        <!-- COMPENSATION -->
        <div class="ed-section-card">
          <div class="ed-section-hdr" style="margin-bottom:16px">
            <h3 class="ed-section-title" style="margin:0">Monthly Compensation</h3>
            <div style="display:flex;gap:8px;align-items:center">
              <span v-if="compSuccess" class="ed-success-badge">{{ compSuccess }}</span>
              <button class="btn btn-outline btn-sm" @click="showCompEdit = !showCompEdit">
                {{ showCompEdit ? "✕ Cancel" : "✏ Edit" }}
              </button>
            </div>
          </div>
          <p v-if="compError" class="error">{{ compError }}</p>

          <!-- Read -->
          <div v-if="!showCompEdit" class="ed-kv-list">
            <div class="ed-kv-row">
              <span class="ed-kv-label">Basic Salary</span>
              <span class="ed-kv-val ed-kv-mono">KES {{ fmt((employee as any).basic_salary) }}</span>
            </div>
            <div class="ed-kv-row">
              <span class="ed-kv-label">House Allowance</span>
              <span class="ed-kv-val ed-kv-mono">KES {{ fmt((employee as any).house_allowance) }}</span>
            </div>
            <div class="ed-kv-row">
              <span class="ed-kv-label">Transport Allowance</span>
              <span class="ed-kv-val ed-kv-mono">KES {{ fmt((employee as any).transport_allowance) }}</span>
            </div>
            <div class="ed-kv-row">
              <span class="ed-kv-label">Medical Allowance</span>
              <span class="ed-kv-val ed-kv-mono">KES {{ fmt((employee as any).medical_allowance) }}</span>
            </div>
            <div class="ed-kv-row">
              <span class="ed-kv-label">Other Allowances</span>
              <span class="ed-kv-val ed-kv-mono">KES {{ fmt((employee as any).other_allowances) }}</span>
            </div>
            <div class="ed-kv-row ed-kv-total">
              <span class="ed-kv-label">Gross Pay</span>
              <span class="ed-kv-val ed-kv-mono">KES {{ fmt(empGross) }}</span>
            </div>
          </div>

          <!-- Edit -->
          <div v-else>
            <div class="ed-comp-form-grid">
              <div>
                <label class="ed-form-label">Basic Salary (KES)</label>
                <input v-model.number="comp.basic_salary" type="number" min="0" class="input" />
              </div>
              <div>
                <label class="ed-form-label">House Allowance</label>
                <input v-model.number="comp.house_allowance" type="number" min="0" class="input" />
              </div>
              <div>
                <label class="ed-form-label">Transport Allowance</label>
                <input v-model.number="comp.transport_allowance" type="number" min="0" class="input" />
              </div>
              <div>
                <label class="ed-form-label">Medical Allowance</label>
                <input v-model.number="comp.medical_allowance" type="number" min="0" class="input" />
              </div>
              <div>
                <label class="ed-form-label">Other Allowances</label>
                <input v-model.number="comp.other_allowances" type="number" min="0" class="input" />
              </div>
              <div>
                <label class="ed-form-label">Insurance Relief <small style="color:#94a3b8">(max KES 5,000/mo)</small></label>
                <input v-model.number="comp.insurance_relief" type="number" min="0" class="input" />
              </div>
            </div>
            <div class="ed-comp-form-footer">
              <div class="ed-comp-preview">Gross Preview: <strong>KES {{ fmt(grossPay) }}</strong></div>
              <button class="btn btn-primary btn-sm" :disabled="compSaving" @click="saveComp">
                {{ compSaving ? "Saving…" : "Save Compensation" }}
              </button>
            </div>
          </div>
        </div>

        <!-- STATUTORY BENEFITS -->
        <div class="ed-section-card" style="margin-top:16px">
          <h3 class="ed-section-title">Statutory Contributions (Estimated Monthly)</h3>
          <div class="ed-kv-list">
            <div class="ed-kv-row">
              <span class="ed-kv-label">SHIF (2.75% of Gross)</span>
              <span class="ed-kv-val ed-kv-mono">KES {{ fmt(empGross * 0.0275) }}</span>
            </div>
            <div class="ed-kv-row">
              <span class="ed-kv-label">NSSF Tier I (6% up to KES 7,000)</span>
              <span class="ed-kv-val ed-kv-mono">KES {{ fmt(Math.min(empGross, 7000) * 0.06) }}</span>
            </div>
            <div class="ed-kv-row">
              <span class="ed-kv-label">Housing Levy (1.5% of Gross)</span>
              <span class="ed-kv-val ed-kv-mono">KES {{ fmt(empGross * 0.015) }}</span>
            </div>
            <div class="ed-kv-row">
              <span class="ed-kv-label">Insurance Relief</span>
              <span class="ed-kv-val ed-kv-mono">KES {{ fmt((employee as any).insurance_relief) }}</span>
            </div>
            <div class="ed-kv-row">
              <span class="ed-kv-label">NITA Levy</span>
              <span class="ed-kv-val ed-kv-mono">KES 50.00</span>
            </div>
          </div>
        </div>
      </div>

    </div><!-- /employee -->
  </div>
</template>

<style scoped>
/* Root */
.ed-root { padding: 24px; max-width: 1100px; }

/* Breadcrumb */
.ed-breadcrumb { display: flex; align-items: center; gap: 6px; font-size: 0.82rem; color: #94a3b8; margin-bottom: 20px; }
.ed-bc-link { cursor: pointer; color: var(--color-brand-base, #004C97); font-weight: 500; }
.ed-bc-link:hover { text-decoration: underline; }
.ed-bc-sep { color: #cbd5e1; }
.ed-bc-current { color: #475569; font-weight: 500; }

/* Profile card */
.ed-profile-card { background: #fff; border: 1px solid var(--color-border, #e2e8f0); border-radius: 12px; padding: 24px; display: flex; gap: 20px; align-items: flex-start; }
.ed-avatar { width: 64px; height: 64px; border-radius: 50%; background: #3b9c74; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; font-weight: 700; flex-shrink: 0; }
.ed-profile-main { flex: 1; }
.ed-profile-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.ed-name { font-size: 1.25rem; font-weight: 700; color: #0f172a; margin: 0 0 4px; }
.ed-sub { font-size: 0.85rem; color: #64748b; margin: 0 0 10px; }
.ed-emp-id-badge { display: inline-block; background: #f0fdf4; border: 1px solid #86efac; color: #166534; font-size: 0.72rem; font-weight: 600; padding: 2px 10px; border-radius: 20px; font-family: ui-monospace, monospace; }
.ed-profile-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }

/* Tabs */
.ed-tabs { display: flex; border-bottom: 2px solid #e2e8f0; margin: 24px 0 0; overflow-x: auto; }
.ed-tab { background: none; border: none; border-bottom: 2px solid transparent; margin-bottom: -2px; padding: 10px 20px; font-size: 0.88rem; font-weight: 500; color: #64748b; cursor: pointer; white-space: nowrap; transition: color 0.15s, border-color 0.15s; }
.ed-tab:hover { color: #1e293b; }
.ed-tab-active { color: var(--color-brand-base, #004C97); border-bottom-color: var(--color-brand-base, #004C97); font-weight: 600; }

/* Tab body */
.ed-tab-body { margin-top: 20px; display: flex; flex-direction: column; gap: 16px; }

/* Two column */
.ed-two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
@media (max-width: 768px) { .ed-two-col { grid-template-columns: 1fr; } }

/* Section card */
.ed-section-card { background: #fff; border: 1px solid var(--color-border, #e2e8f0); border-radius: 10px; padding: 20px; }
.ed-section-title { font-size: 0.72rem; font-weight: 700; letter-spacing: 0.07em; color: #94a3b8; margin: 0 0 14px; text-transform: uppercase; }
.ed-section-hdr { display: flex; align-items: center; justify-content: space-between; }

/* Sensitive */
.ed-sensitive { border-color: #fed7aa; background: #fffbeb; }
.ed-sensitive-title { color: #d97706; }

/* Health */
.ed-health { border-color: #bfdbfe; background: #eff6ff; }
.ed-health-title { color: #1d4ed8; }
.ed-health-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 24px; }
@media (max-width: 640px) { .ed-health-grid { grid-template-columns: 1fr; } }

/* Emergency contacts */
.ed-ec-form { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
.ed-ec-grid { grid-template-columns: 1fr 1fr; }
.ed-ec-list { display: flex; flex-direction: column; gap: 10px; }
.ed-ec-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 16px; }
.ed-ec-top { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 6px; }
.ed-ec-name { font-weight: 600; font-size: 0.9rem; color: #1e293b; }
.ed-ec-rel { font-size: 0.78rem; color: #64748b; background: #f1f5f9; padding: 2px 8px; border-radius: 20px; }
.ed-ec-primary-badge { font-size: 0.72rem; font-weight: 600; background: #dbeafe; color: #1d4ed8; padding: 2px 8px; border-radius: 20px; }
.ed-ec-actions { margin-left: auto; display: flex; gap: 4px; }
.ed-ec-details { font-size: 0.82rem; color: #64748b; display: flex; gap: 16px; flex-wrap: wrap; }
.ed-empty-hint { font-size: 0.82rem; color: #94a3b8; margin: 0; }
.act-btn { background: none; border: 1px solid #e2e8f0; border-radius: 5px; padding: 2px 7px; font-size: 0.78rem; cursor: pointer; color: #64748b; }
.act-btn:hover { background: #f1f5f9; }
.act-del { color: #dc2626; border-color: #fca5a5; }
.act-del:hover { background: #fff7f7; }

/* Kv wrap */
.ed-kv-wrap { white-space: pre-wrap; text-align: right; max-width: 280px; }
.ed-link { color: var(--color-brand-base, #004C97); text-decoration: none; font-size: 0.85rem; }
.ed-link:hover { text-decoration: underline; }

/* Exit card */
.ed-exit-card { border-color: #fca5a5; background: #fff7f7; }

/* KV list */
.ed-kv-list { display: flex; flex-direction: column; }
.ed-kv-row { display: flex; justify-content: space-between; align-items: baseline; padding: 9px 0; border-bottom: 1px solid #f8fafc; gap: 16px; }
.ed-kv-row:last-child { border-bottom: none; }
.ed-kv-label { font-size: 0.82rem; color: #64748b; flex-shrink: 0; }
.ed-kv-val { font-size: 0.85rem; color: #0f172a; font-weight: 500; text-align: right; }
.ed-kv-accent { color: var(--color-brand-base, #004C97); font-weight: 600; }
.ed-kv-mono { font-family: 'SF Mono', ui-monospace, monospace; font-size: 0.82rem; }
.ed-kv-total { border-top: 2px solid #e2e8f0; border-bottom: none; margin-top: 4px; padding-top: 12px; }
.ed-kv-total .ed-kv-label { font-weight: 700; color: #374151; font-size: 0.85rem; }
.ed-kv-total .ed-kv-val { font-weight: 700; font-size: 0.9rem; color: var(--color-brand-base, #004C97); }

/* Success badge */
.ed-success-badge { background: #f0fdf4; border: 1px solid #86efac; color: #15803d; font-size: 0.78rem; padding: 3px 10px; border-radius: 20px; }

/* Compensation form */
.ed-comp-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px; }
.ed-form-label { display: block; font-size: 0.8rem; font-weight: 600; margin-bottom: 4px; color: #374151; }
.ed-comp-form-footer { display: flex; align-items: center; justify-content: space-between; padding-top: 12px; border-top: 1px solid #f1f5f9; gap: 12px; flex-wrap: wrap; }
.ed-comp-preview { font-size: 0.85rem; color: #64748b; }
.ed-comp-preview strong { color: var(--color-brand-base, #004C97); }

/* Button outline */
.btn-outline { background: #fff; border: 1px solid var(--color-border, #e2e8f0); color: #374151; }
.btn-outline:hover { background: #f8fafc; border-color: #94a3b8; }

@media (max-width: 640px) {
  .ed-comp-form-grid { grid-template-columns: 1fr; }
  .ed-profile-card { flex-direction: column; }
}
</style>
