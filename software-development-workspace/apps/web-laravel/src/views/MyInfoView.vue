<script setup lang="ts">
import { ref, reactive, computed, onMounted } from "vue";
import { api, ApiError } from "../lib/api";
import { currentOrgSlug } from "../stores/auth";
import type {
  Employee, EmergencyContact, EmployeeDependent,
  WorkLocationToday, WorkLocationType,
  LeaveBalance, LeaveBalanceResponse, LeaveRequest, Paginator,
} from "../lib/types";

const employee = ref<Employee | null>(null);
const loading  = ref(false);
const error    = ref("");

type Tab = "profile" | "personal" | "job" | "salary" | "contact" | "emergency" | "dependents";
const tab = ref<Tab>("profile");

function org(): string | null { return currentOrgSlug.value; }

function fmt(n: number | string | null | undefined): string {
  return new Intl.NumberFormat("en-KE", { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(n ?? 0));
}

function fmtDate(d: string | null | undefined): string {
  if (!d) return "—";
  try { return new Date(d).toLocaleDateString("en-KE", { year: "numeric", month: "long", day: "numeric" }); }
  catch { return d; }
}

function fmtTime(d: string | null | undefined): string {
  if (!d) return "—";
  try { return new Date(d).toLocaleTimeString("en-KE", { hour: "2-digit", minute: "2-digit" }); }
  catch { return d; }
}

function calcAge(dob: string | null | undefined): string {
  if (!dob) return "—";
  const diff = Date.now() - new Date(dob).getTime();
  const age  = Math.floor(diff / (365.25 * 24 * 60 * 60 * 1000));
  return `${age} yrs`;
}

function initials(e: any): string {
  return ((e?.first_name?.[0] ?? "") + (e?.last_name?.[0] ?? "")).toUpperCase() || "?";
}

function statusBadge(status: string): string {
  return { active: "badge-green", inactive: "badge-yellow", exited: "badge-gray" }[status] ?? "badge-gray";
}

function leaveStatusBadge(status: string): string {
  return { pending: "badge-yellow", approved: "badge-green", rejected: "badge-red", cancelled: "badge-gray" }[status] ?? "badge-gray";
}

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
    const res = await api<{ data: Employee }>("GET", "/api/v1/employees/me", { org: org() });
    employee.value = (res as any).data ?? res;
    resetContactForm();
  } catch (err) {
    error.value = err instanceof Error ? err.message : "Failed to load your profile.";
  } finally { loading.value = false; }
}

// ── Work Location Check-In ──────────────────────────────────────────────────
const WL_OPTIONS: { value: WorkLocationType; label: string }[] = [
  { value: "office",        label: "Office" },
  { value: "customer_site", label: "Customer Site" },
  { value: "home",          label: "Work From Home" },
];

const wlToday   = ref<WorkLocationToday | null>(null);
const wlLoading = ref(false);
const wlSaving  = ref(false);
const wlError   = ref("");
const wlForm = reactive({ location_type: "office" as WorkLocationType, customer_name: "", notes: "" });

function wlLabel(type: WorkLocationType): string {
  return { office: "Office", home: "Work From Home", customer_site: "Customer Site" }[type] ?? type;
}

function wlBadgeClass(type: WorkLocationType): string {
  return { office: "badge-blue", home: "badge-teal", customer_site: "badge-amber" }[type] ?? "badge-gray";
}

function wlDuration(c: { worked_minutes: number | null }): string {
  const mins = c.worked_minutes ?? 0;
  const h = Math.floor(mins / 60);
  const m = mins % 60;
  return h > 0 ? `${h}h ${m}m` : `${m}m`;
}

async function loadWorkLocation(): Promise<void> {
  wlLoading.value = true; wlError.value = "";
  try {
    const today = new Date().toISOString().slice(0, 10);
    const res = await api<{ data: WorkLocationToday[] }>("GET", "/api/v1/attendance", {
      org: org(), params: { from: today, to: today },
    });
    // One row per person per day now, so today is the first or nothing.
    wlToday.value = res.data[0] ?? null;
  } catch (err) {
    wlError.value = err instanceof Error ? err.message : "Failed to load work location.";
  } finally { wlLoading.value = false; }
}

async function checkIn(): Promise<void> {
  wlSaving.value = true; wlError.value = "";
  try {
    await api("POST", "/api/v1/attendance/clock-in", {
      org: org(),
      body: { location_type: wlForm.location_type },
    });
    wlForm.notes = "";
    await loadWorkLocation();
  } catch (err) { wlError.value = err instanceof Error ? err.message : "Check-in failed."; }
  finally { wlSaving.value = false; }
}

async function checkOut(): Promise<void> {
  wlSaving.value = true; wlError.value = "";
  try {
    await api("POST", "/api/v1/attendance/clock-out", { org: org() });
    await loadWorkLocation();
  } catch (err) { wlError.value = err instanceof Error ? err.message : "Check-out failed."; }
  finally { wlSaving.value = false; }
}

// ── Leave balance ────────────────────────────────────────────────────────────
const balances   = ref<LeaveBalance[]>([]);
const balLoading = ref(false);
const balError   = ref("");

async function loadBalance(): Promise<void> {
  balLoading.value = true; balError.value = "";
  try {
    const res = await api<LeaveBalanceResponse>("GET", "/api/v1/leave/balance", {
      org: org(), params: { year: new Date().getFullYear() },
    });
    balances.value = res.data;
  } catch (err) {
    balError.value = err instanceof ApiError && err.status === 422
      ? "No employee record linked to your account."
      : err instanceof Error ? err.message : "Failed to load leave balance.";
  } finally { balLoading.value = false; }
}

// ── Recent leave requests ───────────────────────────────────────────────────
const myLeaves     = ref<LeaveRequest[]>([]);
const leaveLoading = ref(false);

async function loadMyLeaves(): Promise<void> {
  leaveLoading.value = true;
  try {
    const res = await api<Paginator<LeaveRequest>>("GET", "/api/v1/leave", { org: org(), params: { page: 1, per_page: 5 } });
    myLeaves.value = res.data;
  } catch { /* silent */ }
  finally { leaveLoading.value = false; }
}

// ── Contact details (editable) ──────────────────────────────────────────────
const contactForm = reactive({
  phone: "", personal_email: "", residential_address: "", city: "", country: "", linkedin_profile: "",
});
const contactSaving  = ref(false);
const contactError   = ref("");
const contactSuccess = ref("");

function resetContactForm(): void {
  const e = employee.value as any;
  if (!e) return;
  Object.assign(contactForm, {
    phone:               e.phone ?? "",
    personal_email:      e.personal_email ?? "",
    residential_address: e.residential_address ?? e.address ?? "",
    city:                e.city ?? "",
    country:             e.country ?? "",
    linkedin_profile:    e.linkedin_profile ?? "",
  });
}

async function saveContact(): Promise<void> {
  contactSaving.value = true; contactError.value = ""; contactSuccess.value = "";
  try {
    const res = await api<{ data: Employee }>("PUT", "/api/v1/employees/me/contact", { org: org(), body: { ...contactForm } });
    employee.value = (res as any).data ?? res;
    resetContactForm();
    contactSuccess.value = "Saved.";
    setTimeout(() => { contactSuccess.value = ""; }, 3000);
  } catch (err) { contactError.value = err instanceof Error ? err.message : "Failed to save."; }
  finally { contactSaving.value = false; }
}

// ── Emergency contacts ───────────────────────────────────────────────────────
const showECForm = ref(false);
const ecSaving   = ref(false);
const ecError    = ref("");
const editingEC  = ref<EmergencyContact | null>(null);
const ecForm = reactive({ name: "", relationship: "", phone: "", email: "", is_primary: false });

function openAddEC(): void {
  editingEC.value = null;
  Object.assign(ecForm, { name: "", relationship: "", phone: "", email: "", is_primary: false });
  ecError.value = "";
  showECForm.value = true;
}

function openEditEC(c: EmergencyContact): void {
  editingEC.value = c;
  Object.assign(ecForm, { name: c.name, relationship: c.relationship, phone: c.phone, email: c.email ?? "", is_primary: c.is_primary });
  ecError.value = "";
  showECForm.value = true;
}

async function saveEC(): Promise<void> {
  if (!employee.value) return;
  ecSaving.value = true; ecError.value = "";
  try {
    if (editingEC.value) {
      await api("PUT", `/api/v1/employees/${employee.value.id}/emergency-contacts/${editingEC.value.id}`, { org: org(), body: { ...ecForm } });
    } else {
      await api("POST", `/api/v1/employees/${employee.value.id}/emergency-contacts`, { org: org(), body: { ...ecForm } });
    }
    showECForm.value = false;
    await load();
  } catch (e) { ecError.value = e instanceof Error ? e.message : "Failed to save."; }
  finally { ecSaving.value = false; }
}

async function deleteEC(id: number): Promise<void> {
  if (!employee.value) return;
  if (!confirm("Remove this emergency contact?")) return;
  await api("DELETE", `/api/v1/employees/${employee.value.id}/emergency-contacts/${id}`, { org: org() });
  await load();
}

// ── Dependents ───────────────────────────────────────────────────────────────
const dependents   = ref<EmployeeDependent[]>([]);
const depLoading   = ref(false);
const depError     = ref("");
const showDepForm  = ref(false);
const depSaving    = ref(false);
const depFormError = ref("");
const editingDepId = ref<number | null>(null);

const RELATIONSHIPS = ["Spouse", "Child", "Parent", "Sibling", "Guardian", "Other"];
const GENDERS       = ["Male", "Female", "Other"];

const emptyDepForm = () => ({
  first_name: "", last_name: "", middle_name: "", relationship: "",
  date_of_birth: "", gender: "", national_id: "", passport_number: "",
  school_institution: "", disability: "", notes: "",
});
const depForm = reactive(emptyDepForm());

async function loadDependents(): Promise<void> {
  if (!employee.value) return;
  depLoading.value = true; depError.value = "";
  try {
    const res = await api<any>("GET", `/api/v1/employees/${employee.value.id}/dependents`, { org: org() });
    dependents.value = ((res as any).data ?? res) as EmployeeDependent[];
  } catch (e) {
    depError.value = e instanceof Error ? e.message : "Failed to load dependents.";
  } finally { depLoading.value = false; }
}

function openAddDep(): void {
  editingDepId.value = null;
  Object.assign(depForm, emptyDepForm());
  depFormError.value = "";
  showDepForm.value = true;
}

function openEditDep(d: EmployeeDependent): void {
  editingDepId.value = d.id;
  Object.assign(depForm, {
    first_name:         d.first_name,
    last_name:          d.last_name,
    middle_name:        d.middle_name ?? "",
    relationship:       d.relationship,
    date_of_birth:      d.date_of_birth ?? "",
    gender:             d.gender ?? "",
    national_id:        d.national_id ?? "",
    passport_number:    d.passport_number ?? "",
    school_institution: d.school_institution ?? "",
    disability:         d.disability ?? "",
    notes:              d.notes ?? "",
  });
  depFormError.value = "";
  showDepForm.value = true;
}

async function saveDep(): Promise<void> {
  if (!employee.value) return;
  if (!depForm.first_name || !depForm.last_name || !depForm.relationship) {
    depFormError.value = "First name, last name and relationship are required.";
    return;
  }
  depSaving.value = true; depFormError.value = "";
  try {
    const body = { ...depForm } as any;
    if (!body.date_of_birth) delete body.date_of_birth;
    if (editingDepId.value) {
      await api("PUT", `/api/v1/employees/${employee.value.id}/dependents/${editingDepId.value}`, { org: org(), body });
    } else {
      await api("POST", `/api/v1/employees/${employee.value.id}/dependents`, { org: org(), body });
    }
    showDepForm.value = false;
    await loadDependents();
  } catch (e) { depFormError.value = e instanceof Error ? e.message : "Save failed."; }
  finally { depSaving.value = false; }
}

async function removeDep(id: number, name: string): Promise<void> {
  if (!employee.value) return;
  if (!confirm(`Remove ${name} from dependents?`)) return;
  await api("DELETE", `/api/v1/employees/${employee.value.id}/dependents/${id}`, { org: org() });
  await loadDependents();
}

onMounted(async () => {
  await load();
  loadWorkLocation();
  loadBalance();
  loadMyLeaves();
  loadDependents();
});
</script>

<template>
  <div class="ed-root">
    <h2 class="mi-page-title">My Info</h2>

    <p v-if="loading" class="status">Loading…</p>
    <p v-if="error"   class="error">{{ error }}</p>

    <div v-if="employee">

      <!-- ── PROFILE CARD ──────────────────────────────────────────────────── -->
      <div class="ed-profile-card">
        <div class="ed-photo-wrap">
          <img v-if="(employee as any).photo_url" :src="(employee as any).photo_url" class="ed-photo-img" alt="Profile photo" />
          <div v-else class="ed-avatar">{{ initials(employee) }}</div>
        </div>

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
              <span v-if="(employee as any).manager?.full_name" class="ed-manager-chip">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0">
                  <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                  <circle cx="9" cy="7" r="4"/>
                  <path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                </svg>
                {{ (employee as any).manager.full_name }}
              </span>
            </div>
            <div class="ed-profile-actions">
              <span :class="['badge', statusBadge((employee as any).status)]" style="font-size:0.82rem;padding:5px 14px;text-transform:capitalize">
                {{ (employee as any).status }}
              </span>
            </div>
          </div>

          <p v-if="(employee as any).professional_summary" class="ed-summary-preview">
            {{ (employee as any).professional_summary }}
          </p>
        </div>
      </div>

      <!-- ── TAB NAV ───────────────────────────────────────────────────────── -->
      <div class="ed-tabs">
        <button :class="['ed-tab', { 'ed-tab-active': tab === 'profile'    }]" @click="tab = 'profile'">Profile</button>
        <button :class="['ed-tab', { 'ed-tab-active': tab === 'personal'   }]" @click="tab = 'personal'">Personal Details</button>
        <button :class="['ed-tab', { 'ed-tab-active': tab === 'job'        }]" @click="tab = 'job'">Job</button>
        <button :class="['ed-tab', { 'ed-tab-active': tab === 'salary'     }]" @click="tab = 'salary'">Salary</button>
        <button :class="['ed-tab', { 'ed-tab-active': tab === 'contact'    }]" @click="tab = 'contact'">Contact Details</button>
        <button :class="['ed-tab', { 'ed-tab-active': tab === 'emergency'  }]" @click="tab = 'emergency'">Emergency Contacts</button>
        <button :class="['ed-tab', { 'ed-tab-active': tab === 'dependents' }]" @click="tab = 'dependents'">Dependents</button>
      </div>

      <!-- ── PROFILE ───────────────────────────────────────────────────────── -->
      <div v-if="tab === 'profile'" class="ed-tab-body">
        <div class="ed-two-col">

          <!-- About -->
          <div class="ed-section-card">
            <h3 class="ed-section-title">About</h3>
            <div class="ed-kv-list">
              <div class="ed-kv-row">
                <span class="ed-kv-label">Full Name</span>
                <span class="ed-kv-val">{{ (employee as any).full_name }}</span>
              </div>
              <div class="ed-kv-row">
                <span class="ed-kv-label">Employee Id</span>
                <span class="ed-kv-val ed-kv-accent">{{ (employee as any).employee_number }}</span>
              </div>
              <div class="ed-kv-row">
                <span class="ed-kv-label">Birthday</span>
                <span class="ed-kv-val">{{ fmtDate((employee as any).date_of_birth) }}</span>
              </div>
              <div class="ed-kv-row">
                <span class="ed-kv-label">Joined Date</span>
                <span class="ed-kv-val">{{ fmtDate((employee as any).date_of_joining) }}</span>
              </div>
              <div class="ed-kv-row">
                <span class="ed-kv-label">Sub Unit</span>
                <span class="ed-kv-val">{{ (employee as any).department?.name ?? "—" }}</span>
              </div>
              <div class="ed-kv-row">
                <span class="ed-kv-label">Status</span>
                <span :class="['badge', statusBadge((employee as any).status)]" style="text-transform:capitalize">{{ (employee as any).status }}</span>
              </div>
              <div class="ed-kv-row">
                <span class="ed-kv-label">Mobile</span>
                <span class="ed-kv-val">{{ (employee as any).phone ?? "—" }}</span>
              </div>
              <div class="ed-kv-row">
                <span class="ed-kv-label">Work Email</span>
                <span class="ed-kv-val">{{ (employee as any).work_email ?? (employee as any).email ?? "—" }}</span>
              </div>
            </div>
          </div>

          <!-- Quick Access -->
          <div class="ed-section-card">
            <h3 class="ed-section-title">Quick Access</h3>
            <div class="mi-quick-links">
              <router-link :to="{ name: 'leave' }" class="mi-quick-link">
                <span class="mi-quick-icon">🗓</span>
                <span>
                  <span class="mi-quick-title">Leave</span>
                  <span class="mi-quick-sub">Apply &amp; track leave requests</span>
                </span>
              </router-link>
              <router-link :to="{ name: 'performance' }" class="mi-quick-link">
                <span class="mi-quick-icon">📊</span>
                <span>
                  <span class="mi-quick-title">Performance</span>
                  <span class="mi-quick-sub">Reviews &amp; appraisals</span>
                </span>
              </router-link>
              <router-link :to="{ name: 'goals' }" class="mi-quick-link">
                <span class="mi-quick-icon">🎯</span>
                <span>
                  <span class="mi-quick-title">Goals</span>
                  <span class="mi-quick-sub">Objectives &amp; key results</span>
                </span>
              </router-link>
            </div>
          </div>
        </div>

        <!-- Work Location Check-In -->
        <div class="ed-section-card">
          <h3 class="ed-section-title">Work Location</h3>
          <p v-if="wlError" class="error">{{ wlError }}</p>

          <div v-if="wlToday?.is_clocked_in" class="wl-current">
            <div class="wl-status-row">
              <span :class="['badge', wlBadgeClass(wlToday.actual_location!)]">{{ wlLabel(wlToday.actual_location!) }}</span>
              <span v-if="wlToday.account" class="wl-customer">{{ wlToday.account.name }}</span>
            </div>
            <p class="wl-meta">
              Checked in at {{ fmtTime(wlToday.first_clock_in_at!) }}
              &nbsp;·&nbsp; {{ wlDuration(wlToday) }} so far
            </p>
            <p v-if="wlToday.notes" class="ed-prose" style="margin:6px 0 0">{{ wlToday.notes }}</p>
            <button class="btn btn-outline btn-sm" style="margin-top:10px" :disabled="wlSaving" @click="checkOut">
              {{ wlSaving ? "…" : "Check Out" }}
            </button>
          </div>

          <div v-else class="wl-form">
            <div class="wl-options">
              <label v-for="opt in WL_OPTIONS" :key="opt.value" :class="['wl-option', { 'wl-option-active': wlForm.location_type === opt.value }]">
                <input type="radio" name="wl-location" :value="opt.value" v-model="wlForm.location_type" />
                {{ opt.label }}
              </label>
            </div>
            <div v-if="wlForm.location_type === 'customer_site'" style="margin-top:10px">
              <label class="ed-form-label">Customer / Site Name *</label>
              <input v-model="wlForm.customer_name" type="text" class="input" placeholder="e.g. Acme Corp HQ" />
            </div>
            <div style="margin-top:10px">
              <label class="ed-form-label">Notes</label>
              <input v-model="wlForm.notes" type="text" class="input" placeholder="Optional notes" />
            </div>
            <button
              class="btn btn-primary btn-sm" style="margin-top:12px"
              :disabled="wlSaving || (wlForm.location_type === 'customer_site' && !wlForm.customer_name)"
              @click="checkIn"
            >
              {{ wlSaving ? "Checking in…" : "Check In" }}
            </button>
          </div>

          <div v-if="wlToday?.punches?.length" class="wl-history">
            <div class="ed-dr-header" style="margin-top:16px">Today's Activity</div>
            <div v-for="c in wlToday.punches" :key="c.id" class="wl-history-item">
              <span v-if="c.location_type" :class="['badge', wlBadgeClass(c.location_type)]">
                {{ wlLabel(c.location_type) }}
              </span>
              <!-- Typed rather than clocked. Shown, because a figure somebody
                   remembered and one the clock recorded are different evidence. -->
              <span v-if="c.is_manual" class="wl-customer">entered by hand</span>
              <span class="wl-history-time">
                {{ fmtTime(c.in) }} – {{ c.out ? fmtTime(c.out) : "now" }}
              </span>
            </div>
          </div>
        </div>

        <!-- Leave Balance -->
        <div class="ed-section-card">
          <h3 class="ed-section-title">Leave Balance</h3>
          <p v-if="balLoading" class="status">Loading…</p>
          <p v-if="balError" class="error">{{ balError }}</p>
          <div v-if="balances.length > 0" class="balance-list">
            <div v-for="b in balances" :key="b.leave_type" class="balance-item">
              <div class="balance-header">
                <span class="balance-name">{{ b.leave_type }}</span>
                <span class="balance-numbers">{{ b.available_days }} / {{ b.entitled_days + b.carried_days }} available</span>
              </div>
              <div class="balance-bar">
                <div
                  class="balance-fill"
                  :style="{ width: (b.entitled_days + b.carried_days) > 0
                    ? `${Math.max(0, (b.available_days / (b.entitled_days + b.carried_days)) * 100)}%`
                    : '0%' }"
                ></div>
              </div>
              <div class="balance-meta">
                <span>Entitled: {{ b.entitled_days }}</span>
                <span>Used: {{ b.used_days }}</span>
                <span>Pending: {{ b.pending_days }}</span>
                <span>Carried: {{ b.carried_days }}</span>
              </div>
            </div>
          </div>
          <p v-else-if="!balLoading && !balError" class="ed-empty-hint">No leave balances for this year.</p>
        </div>

        <!-- Recent Leave Requests -->
        <div class="ed-section-card">
          <div class="ed-section-hdr" style="margin-bottom:12px">
            <h3 class="ed-section-title" style="margin:0">Recent Leave Requests</h3>
            <router-link :to="{ name: 'leave' }" class="ed-link">View All →</router-link>
          </div>
          <div v-if="myLeaves.length" class="ed-kv-list">
            <div v-for="l in myLeaves" :key="l.id" class="ed-kv-row">
              <span class="ed-kv-label">
                {{ l.leave_type?.name ?? "Leave" }} · {{ fmtDate(l.from_date) }}{{ l.from_date !== l.to_date ? " – " + fmtDate(l.to_date) : "" }}
              </span>
              <span :class="['badge', leaveStatusBadge(l.status)]" style="text-transform:capitalize">{{ l.status }}</span>
            </div>
          </div>
          <p v-else-if="!leaveLoading" class="ed-empty-hint">No recent leave requests.</p>
        </div>
      </div><!-- /profile -->

      <!-- ── PERSONAL DETAILS ──────────────────────────────────────────────── -->
      <div v-if="tab === 'personal'" class="ed-tab-body">
        <div class="ed-two-col">
          <div class="ed-section-card">
            <h3 class="ed-section-title">Personal Details</h3>
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
                <span class="ed-kv-label">Age</span>
                <span class="ed-kv-val">{{ calcAge((employee as any).date_of_birth) }}</span>
              </div>
              <div class="ed-kv-row" v-if="(employee as any).blood_group !== undefined">
                <span class="ed-kv-label">Blood Group</span>
                <span class="ed-kv-val">{{ (employee as any).blood_group ?? "—" }}</span>
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
                <span class="ed-kv-label">SHA / NHIF No</span>
                <span class="ed-kv-val ed-kv-mono">{{ (employee as any).sha_number ?? (employee as any).nhif_number ?? "—" }}</span>
              </div>
            </div>
          </div>
        </div>

        <div class="ed-section-card ed-health">
          <h3 class="ed-section-title ed-health-title">Health Information</h3>
          <div class="ed-kv-list ed-health-grid">
            <div class="ed-kv-row">
              <span class="ed-kv-label">Allergies</span>
              <span class="ed-kv-val">{{ (employee as any).allergies ?? "—" }}</span>
            </div>
            <div class="ed-kv-row">
              <span class="ed-kv-label">Known Conditions</span>
              <span class="ed-kv-val">{{ (employee as any).known_conditions ?? "—" }}</span>
            </div>
            <div class="ed-kv-row">
              <span class="ed-kv-label">Disabilities</span>
              <span class="ed-kv-val">{{ (employee as any).disabilities ?? "—" }}</span>
            </div>
          </div>
        </div>

        <div class="ed-two-col">
          <div class="ed-section-card">
            <h3 class="ed-section-title">About Me</h3>
            <p v-if="(employee as any).about_me" class="ed-prose" style="margin:0">{{ (employee as any).about_me }}</p>
            <p v-else class="ed-empty-hint">—</p>
          </div>
          <div class="ed-section-card">
            <h3 class="ed-section-title">Hobbies &amp; Interests</h3>
            <p v-if="(employee as any).hobbies_interests" class="ed-prose" style="margin:0">{{ (employee as any).hobbies_interests }}</p>
            <p v-else class="ed-empty-hint">—</p>
          </div>
        </div>

        <div class="ed-section-card">
          <h3 class="ed-section-title">Professional Summary</h3>
          <p v-if="(employee as any).professional_summary" class="ed-prose">{{ (employee as any).professional_summary }}</p>
          <p v-else class="ed-empty-hint">No professional summary added.</p>
        </div>
      </div><!-- /personal -->

      <!-- ── JOB ───────────────────────────────────────────────────────────── -->
      <div v-if="tab === 'job'" class="ed-tab-body">
        <div class="ed-two-col">
          <div class="ed-section-card">
            <h3 class="ed-section-title">Job Details</h3>
            <div class="ed-kv-list">
              <div class="ed-kv-row">
                <span class="ed-kv-label">Employee No</span>
                <span class="ed-kv-val ed-kv-accent">{{ (employee as any).employee_number ?? "—" }}</span>
              </div>
              <div class="ed-kv-row">
                <span class="ed-kv-label">Employment Type</span>
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
                <span class="ed-kv-label">Job Grade</span>
                <span class="ed-kv-val">{{ (employee as any).job_grade?.name ?? "—" }}</span>
              </div>
              <div class="ed-kv-row">
                <span class="ed-kv-label">Date of Joining</span>
                <span class="ed-kv-val">{{ fmtDate((employee as any).date_of_joining ?? (employee as any).date_hired) }}</span>
              </div>
              <div class="ed-kv-row" v-if="(employee as any).probation_end_date">
                <span class="ed-kv-label">Probation / Review Date</span>
                <span class="ed-kv-val">{{ fmtDate((employee as any).probation_end_date) }}</span>
              </div>
              <div class="ed-kv-row" v-if="(employee as any).contract_end_date">
                <span class="ed-kv-label">Contract End</span>
                <span class="ed-kv-val">{{ fmtDate((employee as any).contract_end_date) }}</span>
              </div>
            </div>
          </div>

          <div class="ed-section-card">
            <h3 class="ed-section-title">Reporting Structure</h3>
            <div v-if="(employee as any).manager" class="ed-kv-list" style="margin-bottom:14px">
              <div class="ed-kv-row">
                <span class="ed-kv-label">Reports To</span>
                <span class="ed-kv-val">{{ (employee as any).manager.full_name }}</span>
              </div>
            </div>
            <template v-if="(employee as any).direct_reports?.length">
              <div class="ed-dr-header">Direct Reports ({{ (employee as any).direct_reports.length }})</div>
              <div class="ed-dr-chips">
                <div v-for="dr in (employee as any).direct_reports" :key="dr.id" class="ed-dr-chip">
                  <span class="ed-dr-initials">{{ initials(dr) }}</span>
                  <div class="ed-dr-info">
                    <span class="ed-dr-name">{{ dr.full_name }}</span>
                    <span v-if="dr.designation?.title" class="ed-dr-role">{{ dr.designation.title }}</span>
                  </div>
                </div>
              </div>
            </template>
            <p v-else-if="!(employee as any).manager" class="ed-empty-hint">No reporting structure defined.</p>
            <p v-else class="ed-empty-hint">No direct reports.</p>
          </div>
        </div>

        <div class="ed-section-card">
          <h3 class="ed-section-title">Job Description</h3>
          <p v-if="(employee as any).job_description" class="ed-prose">{{ (employee as any).job_description }}</p>
          <p v-else class="ed-empty-hint">No job description added.</p>
        </div>
      </div><!-- /job -->

      <!-- ── SALARY ────────────────────────────────────────────────────────── -->
      <div v-if="tab === 'salary'" class="ed-tab-body">
        <div class="ed-section-card">
          <h3 class="ed-section-title">Monthly Compensation</h3>
          <div class="ed-kv-list">
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
            <div class="ed-kv-row">
              <span class="ed-kv-label">Insurance Relief</span>
              <span class="ed-kv-val ed-kv-mono">KES {{ fmt((employee as any).insurance_relief) }}</span>
            </div>
          </div>
        </div>
      </div><!-- /salary -->

      <!-- ── CONTACT DETAILS ───────────────────────────────────────────────── -->
      <div v-if="tab === 'contact'" class="ed-tab-body">
        <div class="ed-section-card">
          <div class="ed-section-hdr" style="margin-bottom:12px">
            <h3 class="ed-section-title" style="margin:0">Contact Details</h3>
            <span v-if="contactSuccess" class="ed-success-badge">{{ contactSuccess }}</span>
          </div>
          <p v-if="contactError" class="error">{{ contactError }}</p>

          <div class="ed-kv-list" style="margin-bottom:16px">
            <div class="ed-kv-row">
              <span class="ed-kv-label">Work Email</span>
              <span class="ed-kv-val">{{ (employee as any).work_email ?? (employee as any).email ?? "—" }}</span>
            </div>
          </div>

          <div class="ed-comp-form-grid">
            <div>
              <label class="ed-form-label">Phone</label>
              <input v-model="contactForm.phone" type="text" class="input" placeholder="+254…" />
            </div>
            <div>
              <label class="ed-form-label">Personal Email</label>
              <input v-model="contactForm.personal_email" type="email" class="input" />
            </div>
            <div style="grid-column:1/-1">
              <label class="ed-form-label">Address</label>
              <textarea v-model="contactForm.residential_address" class="input" rows="2" style="width:100%;resize:vertical" />
            </div>
            <div>
              <label class="ed-form-label">City</label>
              <input v-model="contactForm.city" type="text" class="input" />
            </div>
            <div>
              <label class="ed-form-label">Country (ISO code)</label>
              <input v-model="contactForm.country" type="text" class="input" maxlength="2" placeholder="e.g. KE" style="text-transform:uppercase" />
            </div>
            <div style="grid-column:1/-1">
              <label class="ed-form-label">LinkedIn Profile</label>
              <input v-model="contactForm.linkedin_profile" type="url" class="input" placeholder="https://www.linkedin.com/in/…" />
            </div>
          </div>

          <button class="btn btn-primary btn-sm" style="margin-top:12px" :disabled="contactSaving" @click="saveContact">
            {{ contactSaving ? "Saving…" : "Save Changes" }}
          </button>
        </div>
      </div><!-- /contact -->

      <!-- ── EMERGENCY CONTACTS ────────────────────────────────────────────── -->
      <div v-if="tab === 'emergency'" class="ed-tab-body">
        <div class="ed-section-card ed-sensitive">
          <div class="ed-section-hdr" style="margin-bottom:12px">
            <h3 class="ed-section-title ed-sensitive-title" style="margin:0">🔒 Emergency Contacts</h3>
            <button class="btn btn-outline btn-sm" @click="openAddEC">+ Add</button>
          </div>

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
              <input type="checkbox" id="mi-ec-primary" v-model="ecForm.is_primary" />
              <label for="mi-ec-primary" style="font-size:0.82rem;cursor:pointer">Primary contact</label>
            </div>
            <div style="display:flex;gap:8px;margin-top:12px">
              <button class="btn btn-primary btn-sm" :disabled="ecSaving" @click="saveEC">{{ ecSaving ? "Saving…" : "Save" }}</button>
              <button class="btn btn-secondary btn-sm" @click="showECForm = false">Cancel</button>
            </div>
          </div>

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
      </div><!-- /emergency -->

      <!-- ── DEPENDENTS ────────────────────────────────────────────────────── -->
      <div v-if="tab === 'dependents'" class="ed-tab-body">
        <div class="ed-section-card">
          <div class="ed-section-hdr" style="margin-bottom:12px">
            <h3 class="ed-section-title" style="margin:0">Dependents</h3>
            <button class="btn btn-outline btn-sm" @click="openAddDep">+ Add</button>
          </div>

          <p v-if="depError" class="error">{{ depError }}</p>

          <div v-if="showDepForm" class="ed-sub-form">
            <p v-if="depFormError" class="error" style="margin-bottom:8px">{{ depFormError }}</p>
            <div class="ed-comp-form-grid">
              <div>
                <label class="ed-form-label">First Name *</label>
                <input v-model="depForm.first_name" type="text" class="input" />
              </div>
              <div>
                <label class="ed-form-label">Last Name *</label>
                <input v-model="depForm.last_name" type="text" class="input" />
              </div>
              <div>
                <label class="ed-form-label">Middle Name</label>
                <input v-model="depForm.middle_name" type="text" class="input" />
              </div>
              <div>
                <label class="ed-form-label">Relationship *</label>
                <select v-model="depForm.relationship" class="input">
                  <option value="">Select…</option>
                  <option v-for="r in RELATIONSHIPS" :key="r" :value="r">{{ r }}</option>
                </select>
              </div>
              <div>
                <label class="ed-form-label">Date of Birth</label>
                <input v-model="depForm.date_of_birth" type="date" class="input" />
              </div>
              <div>
                <label class="ed-form-label">Gender</label>
                <select v-model="depForm.gender" class="input">
                  <option value="">Select…</option>
                  <option v-for="g in GENDERS" :key="g" :value="g">{{ g }}</option>
                </select>
              </div>
              <div>
                <label class="ed-form-label">National ID</label>
                <input v-model="depForm.national_id" type="text" class="input" />
              </div>
              <div>
                <label class="ed-form-label">Passport Number</label>
                <input v-model="depForm.passport_number" type="text" class="input" />
              </div>
              <div>
                <label class="ed-form-label">School / Institution</label>
                <input v-model="depForm.school_institution" type="text" class="input" />
              </div>
              <div>
                <label class="ed-form-label">Disability (if any)</label>
                <input v-model="depForm.disability" type="text" class="input" />
              </div>
              <div style="grid-column:1/-1">
                <label class="ed-form-label">Notes</label>
                <textarea v-model="depForm.notes" class="input" rows="2" style="width:100%;resize:vertical" />
              </div>
            </div>
            <div style="display:flex;gap:8px;margin-top:12px">
              <button class="btn btn-primary btn-sm" :disabled="depSaving" @click="saveDep">{{ depSaving ? "Saving…" : "Save" }}</button>
              <button class="btn btn-secondary btn-sm" @click="showDepForm = false">Cancel</button>
            </div>
          </div>

          <div v-if="dependents.length" class="ed-ec-list">
            <div v-for="d in dependents" :key="d.id" class="ed-ec-card">
              <div class="ed-ec-top">
                <span class="ed-ec-name">{{ d.full_name ?? `${d.first_name} ${d.last_name}` }}</span>
                <span class="ed-ec-rel">{{ d.relationship }}</span>
                <span v-if="d.disability" class="dep-tag dep-tag-warn">Disability</span>
                <div class="ed-ec-actions">
                  <button class="act-btn" @click="openEditDep(d)">✏</button>
                  <button class="act-btn act-del" @click="removeDep(d.id, d.full_name ?? d.first_name)">✕</button>
                </div>
              </div>
              <div class="ed-ec-details">
                <span v-if="d.date_of_birth">🎂 {{ fmtDate(d.date_of_birth) }} ({{ calcAge(d.date_of_birth) }})</span>
                <span v-if="d.gender">{{ d.gender }}</span>
                <span v-if="d.school_institution">🏫 {{ d.school_institution }}</span>
              </div>
            </div>
          </div>
          <p v-else-if="!showDepForm && !depLoading" class="ed-empty-hint">No dependents recorded.</p>
        </div>
      </div><!-- /dependents -->

    </div><!-- /employee -->
  </div>
</template>

<style scoped>
/* Root */
/* No ceiling, matching the .view convention (style.css) and the fix on the
   same layout in EmployeeDetailView.vue — see that file for the reasoning. */
.ed-root { padding: 24px; width: 100%; }

.mi-page-title { font-size: 1.25rem; font-weight: 700; color: #0f172a; margin: 0 0 16px; }

/* Profile card */
.ed-profile-card { background: #fff; border: 1px solid var(--color-border); border-radius: 12px; padding: 24px; display: flex; gap: 20px; align-items: flex-start; }
.ed-profile-main { flex: 1; min-width: 0; }
.ed-profile-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.ed-name { font-size: 1.25rem; font-weight: 700; color: #0f172a; margin: 0 0 4px; }
.ed-sub { font-size: 0.85rem; color: #64748b; margin: 0 0 8px; }
.ed-emp-id-badge { display: inline-block; background: #f0fdf4; border: 1px solid #86efac; color: #166534; font-size: 0.72rem; font-weight: 600; padding: 2px 10px; border-radius: 20px; font-family: ui-monospace, monospace; }
.ed-manager-chip { display: inline-flex; align-items: center; gap: 4px; background: #f0f6ff; border: 1px solid #bfdbfe; color: #1d4ed8; font-size: 0.72rem; font-weight: 500; padding: 2px 10px; border-radius: 20px; margin-left: 6px; }
.ed-profile-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.ed-summary-preview { font-size: 0.84rem; color: #475569; margin: 10px 0 0; line-height: 1.55; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }

/* Photo wrap */
.ed-photo-wrap { position: relative; flex-shrink: 0; width: 80px; height: 80px; }
.ed-photo-img { width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 3px solid #e2e8f0; }
.ed-avatar { width: 80px; height: 80px; border-radius: 50%; background: #3b9c74; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1.7rem; font-weight: 700; }

/* Tabs */
.ed-tabs { display: flex; border-bottom: 2px solid #e2e8f0; margin: 24px 0 0; overflow-x: auto; }
.ed-tab { background: none; border: none; border-bottom: 2px solid transparent; margin-bottom: -2px; padding: 10px 20px; font-size: 0.88rem; font-weight: 500; color: #64748b; cursor: pointer; white-space: nowrap; transition: color 0.15s, border-color 0.15s; text-decoration: none; }
.ed-tab:hover { color: #1e293b; }
.ed-tab-active { color: var(--color-brand-base); border-bottom-color: var(--color-brand-base); font-weight: 600; }

/* Tab body */
.ed-tab-body { margin-top: 20px; display: flex; flex-direction: column; gap: 16px; }

/* Two column */
.ed-two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
@media (max-width: 768px) { .ed-two-col { grid-template-columns: 1fr; } }

/* Section card */
.ed-section-card { background: #fff; border: 1px solid var(--color-border); border-radius: 10px; padding: 20px; }
.ed-section-title { font-size: 0.72rem; font-weight: 700; letter-spacing: 0.07em; color: #94a3b8; margin: 0 0 14px; text-transform: uppercase; }
.ed-section-hdr { display: flex; align-items: center; justify-content: space-between; }

/* Sub form */
.ed-sub-form { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin-bottom: 16px; }

/* Sensitive */
.ed-sensitive { border-color: #fed7aa; background: #fffbeb; }
.ed-sensitive-title { color: #d97706; }

/* Health */
.ed-health { border-color: #bfdbfe; background: #eff6ff; }
.ed-health-title { color: #1d4ed8; }
.ed-health-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 24px; }
@media (max-width: 640px) { .ed-health-grid { grid-template-columns: 1fr; } }

/* Emergency contacts / dependents */
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

.dep-tag { display: inline-block; font-size: 0.68rem; font-weight: 600; padding: 2px 7px; border-radius: 20px; }
.dep-tag-warn { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }

/* Prose */
.ed-prose { white-space: pre-wrap; font-size: 0.88rem; color: #374151; line-height: 1.65; margin: 0; }

/* Reporting structure */
.ed-dr-header { font-size: 0.75rem; font-weight: 600; color: #64748b; letter-spacing: 0.05em; text-transform: uppercase; margin-bottom: 10px; }
.ed-dr-chips { display: flex; flex-wrap: wrap; gap: 8px; }
.ed-dr-chip { display: flex; align-items: center; gap: 8px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 12px; text-decoration: none; color: inherit; transition: border-color 0.15s, background 0.15s; min-width: 160px; }
.ed-dr-chip:hover { border-color: #94a3b8; background: #f1f5f9; }
.ed-dr-initials { width: 34px; height: 34px; border-radius: 50%; background: #004C97; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 0.82rem; font-weight: 700; flex-shrink: 0; }
.ed-dr-info { display: flex; flex-direction: column; min-width: 0; }
.ed-dr-name { font-size: 0.85rem; font-weight: 600; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ed-dr-role { font-size: 0.75rem; color: #64748b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* Empty hint / link */
.ed-empty-hint { font-size: 0.82rem; color: #94a3b8; margin: 0; }
.ed-link { color: var(--color-brand-base); text-decoration: none; font-size: 0.85rem; }
.ed-link:hover { text-decoration: underline; }

/* KV list */
.ed-kv-list { display: flex; flex-direction: column; }
.ed-kv-row { display: flex; justify-content: space-between; align-items: baseline; padding: 9px 0; border-bottom: 1px solid #f8fafc; gap: 16px; }
.ed-kv-row:last-child { border-bottom: none; }
.ed-kv-label { font-size: 0.82rem; color: #64748b; flex-shrink: 0; }
.ed-kv-val { font-size: 0.85rem; color: #0f172a; font-weight: 500; text-align: right; }
.ed-kv-accent { color: var(--color-brand-base); font-weight: 600; }
.ed-kv-mono { font-family: 'SF Mono', ui-monospace, monospace; font-size: 0.82rem; }
.ed-kv-total { border-top: 2px solid #e2e8f0; border-bottom: none; margin-top: 4px; padding-top: 12px; }
.ed-kv-total .ed-kv-label { font-weight: 700; color: #374151; font-size: 0.85rem; }
.ed-kv-total .ed-kv-val { font-weight: 700; font-size: 0.9rem; color: var(--color-brand-base); }

/* Success badge */
.ed-success-badge { background: #f0fdf4; border: 1px solid #86efac; color: #15803d; font-size: 0.78rem; padding: 3px 10px; border-radius: 20px; }

/* Compensation / form grid */
.ed-comp-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px; }
.ed-form-label { display: block; font-size: 0.8rem; font-weight: 600; margin-bottom: 4px; color: #374151; }

/* Act buttons */
.act-btn { background: none; border: 1px solid #e2e8f0; border-radius: 5px; padding: 2px 7px; font-size: 0.78rem; cursor: pointer; color: #64748b; }
.act-btn:hover { background: #f1f5f9; }
.act-del { color: #dc2626; border-color: #fca5a5; }
.act-del:hover { background: #fff7f7; }

/* Quick Access */
.mi-quick-links { display: flex; flex-direction: column; gap: 10px; }
.mi-quick-link { display: flex; align-items: center; gap: 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; text-decoration: none; color: inherit; transition: border-color 0.15s, background 0.15s; }
.mi-quick-link:hover { border-color: #94a3b8; background: #f1f5f9; }
.mi-quick-icon { font-size: 1.3rem; flex-shrink: 0; }
.mi-quick-title { display: block; font-size: 0.85rem; font-weight: 600; color: #0f172a; }
.mi-quick-sub { display: block; font-size: 0.75rem; color: #64748b; }

/* Work Location */
.wl-options { display: flex; gap: 8px; flex-wrap: wrap; }
.wl-option { display: flex; align-items: center; gap: 6px; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 14px; font-size: 0.85rem; cursor: pointer; transition: border-color 0.15s, background 0.15s; }
.wl-option:hover { border-color: #94a3b8; }
.wl-option-active { border-color: var(--color-brand-base); background: #f0f6ff; color: var(--color-brand-base); font-weight: 600; }
.wl-option input { margin: 0; }
.wl-current { display: flex; flex-direction: column; gap: 4px; }
.wl-status-row { display: flex; align-items: center; gap: 8px; }
.wl-customer { font-size: 0.85rem; color: #374151; font-weight: 500; }
.wl-meta { font-size: 0.8rem; color: #64748b; margin: 4px 0 0; }
.wl-history { display: flex; flex-direction: column; gap: 8px; }
.wl-history-item { display: flex; align-items: center; gap: 10px; font-size: 0.82rem; color: #475569; padding: 6px 0; border-bottom: 1px solid #f8fafc; }
.wl-history-item:last-child { border-bottom: none; }
.wl-history-time { margin-left: auto; color: #94a3b8; }

/* Leave balance (from LeaveView) */
.balance-list { display: flex; flex-direction: column; gap: 20px; }
.balance-item { display: flex; flex-direction: column; gap: 6px; }
.balance-header { display: flex; align-items: center; justify-content: space-between; }
.balance-name { font-size: var(--font-size-sm); font-weight: var(--font-weight-bold); color: var(--color-text); }
.balance-numbers { font-size: var(--font-size-sm); font-weight: var(--font-weight-semibold); color: var(--color-brand-base); }
.balance-bar { height: 8px; background: var(--color-neutral-200); border-radius: var(--radius-full); overflow: hidden; }
.balance-fill { height: 100%; background: var(--color-brand-base); border-radius: var(--radius-full); transition: width 0.4s; }
.balance-meta { display: flex; gap: 16px; font-size: 12px; color: var(--color-text-muted); }

@media (max-width: 640px) {
  .ed-comp-form-grid { grid-template-columns: 1fr; }
  .ed-profile-card { flex-direction: column; }
  .ed-ec-grid { grid-template-columns: 1fr; }
}
</style>
