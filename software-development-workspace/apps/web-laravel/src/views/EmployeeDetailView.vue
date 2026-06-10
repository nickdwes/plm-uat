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

// Photo upload
const photoInput    = ref<HTMLInputElement | null>(null);
const photoUploading = ref(false);

// Profile text editing (summary / about / hobbies)
const editingProfile = ref(false);
const profileSaving  = ref(false);
const profileDraft   = reactive({ professional_summary: "", about_me: "", hobbies_interests: "" });

// Job description editing
const editingJD = ref(false);
const jdSaving  = ref(false);
const jdDraft   = ref("");

// Qualifications
const showQualForm = ref(false);
const qualSaving   = ref(false);
const qualError    = ref("");
const qualForm = reactive({
  title: "", institution: "", qualification_type: "certificate",
  field_of_study: "", year_obtained: "" as string | number,
  certificate_number: "", notes: "",
});

// Secondments
const showSecondmentForm = ref(false);
const secondmentSaving   = ref(false);
const secondmentError    = ref("");
const secondmentForm = reactive({
  organization_name: "", role: "", start_date: "", end_date: "", notes: "",
});

function org(): string | null { return currentOrgSlug.value; }

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

// ── Photo upload ───────────────────────────────────────────────────────────
function triggerPhotoUpload() { photoInput.value?.click(); }

async function onPhotoSelected(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0];
  if (!file) return;
  photoUploading.value = true;
  try {
    const fd = new FormData();
    fd.append("photo", file);
    const res = await api<{ data: { photo_url: string } }>("POST", `/api/v1/employees/${props.id}/photo`, { org: org(), formData: fd });
    if (employee.value) (employee.value as any).photo_url = (res as any).data?.photo_url ?? (res as any).photo_url;
  } catch (err) { error.value = err instanceof Error ? err.message : "Photo upload failed."; }
  finally { photoUploading.value = false; if (photoInput.value) photoInput.value.value = ""; }
}

// ── Profile text ───────────────────────────────────────────────────────────
function openEditProfile() {
  const e = employee.value as any;
  Object.assign(profileDraft, {
    professional_summary: e.professional_summary ?? "",
    about_me: e.about_me ?? "",
    hobbies_interests: e.hobbies_interests ?? "",
  });
  editingProfile.value = true;
}

async function saveProfile() {
  profileSaving.value = true;
  try {
    await api("PUT", `/api/v1/employees/${props.id}`, { org: org(), body: { ...profileDraft } });
    if (employee.value) {
      (employee.value as any).professional_summary = profileDraft.professional_summary;
      (employee.value as any).about_me             = profileDraft.about_me;
      (employee.value as any).hobbies_interests    = profileDraft.hobbies_interests;
    }
    editingProfile.value = false;
  } catch (err) { error.value = err instanceof Error ? err.message : "Save failed."; }
  finally { profileSaving.value = false; }
}

// ── Job description ────────────────────────────────────────────────────────
function openEditJD() {
  jdDraft.value = (employee.value as any).job_description ?? "";
  editingJD.value = true;
}

async function saveJD() {
  jdSaving.value = true;
  try {
    await api("PUT", `/api/v1/employees/${props.id}`, { org: org(), body: { job_description: jdDraft.value } });
    if (employee.value) (employee.value as any).job_description = jdDraft.value;
    editingJD.value = false;
  } catch (err) { error.value = err instanceof Error ? err.message : "Save failed."; }
  finally { jdSaving.value = false; }
}

// ── Qualifications ─────────────────────────────────────────────────────────
async function addQualification() {
  qualSaving.value = true; qualError.value = "";
  try {
    const res = await api<{ data: any }>("POST", `/api/v1/employees/${props.id}/qualifications`, {
      org: org(), body: { ...qualForm, year_obtained: qualForm.year_obtained || null },
    });
    const e = employee.value as any;
    e.qualifications = [((res as any).data ?? res), ...(e.qualifications ?? [])];
    showQualForm.value = false;
    Object.assign(qualForm, { title: "", institution: "", qualification_type: "certificate", field_of_study: "", year_obtained: "", certificate_number: "", notes: "" });
  } catch (err) { qualError.value = err instanceof Error ? err.message : "Failed to save."; }
  finally { qualSaving.value = false; }
}

async function deleteQualification(qualId: number) {
  if (!confirm("Remove this qualification?")) return;
  await api("DELETE", `/api/v1/employees/${props.id}/qualifications/${qualId}`, { org: org() });
  const e = employee.value as any;
  e.qualifications = e.qualifications?.filter((q: any) => q.id !== qualId) ?? [];
}

// ── Secondments ────────────────────────────────────────────────────────────
async function addSecondment() {
  secondmentSaving.value = true; secondmentError.value = "";
  try {
    const res = await api<{ data: any }>("POST", `/api/v1/employees/${props.id}/secondments`, {
      org: org(), body: { ...secondmentForm },
    });
    const e = employee.value as any;
    e.secondments = [((res as any).data ?? res), ...(e.secondments ?? [])];
    showSecondmentForm.value = false;
    Object.assign(secondmentForm, { organization_name: "", role: "", start_date: "", end_date: "", notes: "" });
  } catch (err) { secondmentError.value = err instanceof Error ? err.message : "Failed to save."; }
  finally { secondmentSaving.value = false; }
}

async function deleteSecondment(secId: number) {
  if (!confirm("Remove this secondment record?")) return;
  await api("DELETE", `/api/v1/employees/${props.id}/secondments/${secId}`, { org: org() });
  const e = employee.value as any;
  e.secondments = e.secondments?.filter((s: any) => s.id !== secId) ?? [];
}

function statusBadge(status: string): string {
  return { active: "badge-green", inactive: "badge-yellow", exited: "badge-gray" }[status] ?? "badge-gray";
}

function qualTypeBadgeColor(type: string): string {
  return { degree: "#3b82f6", diploma: "#8b5cf6", certificate: "#10b981", professional: "#f59e0b" }[type] ?? "#64748b";
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

        <!-- Photo / avatar -->
        <div class="ed-photo-wrap">
          <img v-if="(employee as any).photo_url" :src="(employee as any).photo_url" class="ed-photo-img" alt="Profile photo" />
          <div v-else class="ed-avatar">{{ initials(employee) }}</div>
          <button v-if="hasSensitive" class="ed-photo-btn" :disabled="photoUploading" @click="triggerPhotoUpload" title="Change profile photo">
            <svg v-if="!photoUploading" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
              <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
              <circle cx="12" cy="13" r="4"/>
            </svg>
            <span v-else style="font-size:10px">…</span>
          </button>
          <input ref="photoInput" type="file" accept="image/jpeg,image/png,image/webp" style="display:none" @change="onPhotoSelected" />
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
              <router-link :to="{ name: 'employee-edit', params: { id: (employee as any).id } }" class="btn btn-outline btn-sm">✏ Edit</router-link>
            </div>
          </div>

          <!-- Professional summary preview (one line) -->
          <p v-if="(employee as any).professional_summary" class="ed-summary-preview">
            {{ (employee as any).professional_summary }}
          </p>
        </div>
      </div>

      <!-- ── TAB NAV ───────────────────────────────────────────────────────── -->
      <div class="ed-tabs">
        <button :class="['ed-tab', { 'ed-tab-active': tab === 'overview'  }]" @click="tab = 'overview'">Overview</button>
        <button :class="['ed-tab', { 'ed-tab-active': tab === 'career'    }]" @click="tab = 'career'">Career</button>
        <button :class="['ed-tab', { 'ed-tab-active': tab === 'profiles'  }]" @click="tab = 'profiles'">Profile</button>
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

        <!-- ── Sensitive sections ── -->
        <template v-if="hasSensitive">

          <div class="ed-two-col">
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

          <div class="ed-section-card ed-sensitive">
            <div class="ed-section-hdr" style="margin-bottom:14px">
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
                <input type="checkbox" id="ec-primary" v-model="ecForm.is_primary" />
                <label for="ec-primary" style="font-size:0.82rem;cursor:pointer">Primary contact</label>
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

        </template>
      </div><!-- /overview -->

      <!-- ── CAREER ────────────────────────────────────────────────────────── -->
      <div v-if="tab === 'career'" class="ed-tab-body">

        <!-- Career Details -->
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

        <!-- Reporting Structure -->
        <div class="ed-section-card">
          <h3 class="ed-section-title">Reporting Structure</h3>

          <div v-if="(employee as any).manager" class="ed-kv-list" style="margin-bottom:14px">
            <div class="ed-kv-row">
              <span class="ed-kv-label">Reports To</span>
              <router-link
                :to="{ name: 'employee-detail', params: { id: (employee as any).manager.id } }"
                class="ed-link ed-kv-val"
              >{{ (employee as any).manager.full_name }}</router-link>
            </div>
          </div>

          <template v-if="(employee as any).direct_reports?.length">
            <div class="ed-dr-header">Direct Reports ({{ (employee as any).direct_reports.length }})</div>
            <div class="ed-dr-chips">
              <router-link
                v-for="dr in (employee as any).direct_reports"
                :key="dr.id"
                :to="{ name: 'employee-detail', params: { id: dr.id } }"
                class="ed-dr-chip"
              >
                <span class="ed-dr-initials">{{ initials(dr) }}</span>
                <div class="ed-dr-info">
                  <span class="ed-dr-name">{{ dr.full_name }}</span>
                  <span v-if="dr.designation?.title" class="ed-dr-role">{{ dr.designation.title }}</span>
                </div>
              </router-link>
            </div>
          </template>
          <p v-else-if="!(employee as any).manager" class="ed-empty-hint">No reporting structure defined.</p>
          <p v-else class="ed-empty-hint">No direct reports.</p>
        </div>

        <!-- Job Description -->
        <div class="ed-section-card">
          <div class="ed-section-hdr" style="margin-bottom:12px">
            <h3 class="ed-section-title" style="margin:0">Job Description</h3>
            <button v-if="hasSensitive && !editingJD" class="btn btn-outline btn-sm" @click="openEditJD">✏ Edit</button>
            <button v-if="editingJD" class="btn btn-secondary btn-sm" @click="editingJD = false">Cancel</button>
          </div>
          <div v-if="editingJD">
            <textarea v-model="jdDraft" class="input" rows="7" style="width:100%;resize:vertical" placeholder="Describe this employee's responsibilities, expectations, and key deliverables…" />
            <div style="margin-top:8px;display:flex;gap:8px">
              <button class="btn btn-primary btn-sm" :disabled="jdSaving" @click="saveJD">{{ jdSaving ? "Saving…" : "Save" }}</button>
            </div>
          </div>
          <p v-else-if="(employee as any).job_description" class="ed-prose">{{ (employee as any).job_description }}</p>
          <p v-else class="ed-empty-hint">No job description added.</p>
        </div>

        <!-- Qualifications & Certifications -->
        <div class="ed-section-card">
          <div class="ed-section-hdr" style="margin-bottom:12px">
            <h3 class="ed-section-title" style="margin:0">Qualifications &amp; Certifications</h3>
            <button v-if="hasSensitive" class="btn btn-outline btn-sm" @click="showQualForm = !showQualForm">
              {{ showQualForm ? "✕ Cancel" : "+ Add" }}
            </button>
          </div>

          <div v-if="showQualForm" class="ed-sub-form">
            <p v-if="qualError" class="error" style="margin-bottom:8px">{{ qualError }}</p>
            <div class="ed-comp-form-grid">
              <div>
                <label class="ed-form-label">Title / Qualification Name *</label>
                <input v-model="qualForm.title" type="text" class="input" placeholder="e.g. BSc Information Technology" />
              </div>
              <div>
                <label class="ed-form-label">Institution / Awarding Body</label>
                <input v-model="qualForm.institution" type="text" class="input" placeholder="University, college, or body" />
              </div>
              <div>
                <label class="ed-form-label">Type</label>
                <select v-model="qualForm.qualification_type" class="input">
                  <option value="degree">Degree</option>
                  <option value="diploma">Diploma</option>
                  <option value="certificate">Certificate</option>
                  <option value="professional">Professional Certification</option>
                </select>
              </div>
              <div>
                <label class="ed-form-label">Year Obtained</label>
                <input v-model="qualForm.year_obtained" type="number" class="input" min="1950" :max="new Date().getFullYear()" placeholder="e.g. 2020" />
              </div>
              <div>
                <label class="ed-form-label">Field of Study</label>
                <input v-model="qualForm.field_of_study" type="text" class="input" placeholder="e.g. Computer Science" />
              </div>
              <div>
                <label class="ed-form-label">Certificate / Reg. No.</label>
                <input v-model="qualForm.certificate_number" type="text" class="input" placeholder="Optional" />
              </div>
            </div>
            <div style="margin-top:8px;display:flex;gap:8px">
              <button class="btn btn-primary btn-sm" :disabled="qualSaving || !qualForm.title" @click="addQualification">
                {{ qualSaving ? "Saving…" : "Save Qualification" }}
              </button>
            </div>
          </div>

          <div v-if="(employee as any).qualifications?.length" class="ed-qual-list">
            <div v-for="q in (employee as any).qualifications" :key="q.id" class="ed-qual-card">
              <div class="ed-qual-top">
                <div style="flex:1;min-width:0">
                  <span class="ed-qual-title">{{ q.title }}</span>
                  <span class="ed-qual-type" :style="{ background: qualTypeBadgeColor(q.qualification_type) + '18', color: qualTypeBadgeColor(q.qualification_type) }">
                    {{ q.qualification_type }}
                  </span>
                </div>
                <button v-if="hasSensitive" class="act-btn act-del" @click="deleteQualification(q.id)">✕</button>
              </div>
              <div class="ed-qual-meta">
                <span v-if="q.institution">{{ q.institution }}</span>
                <span v-if="q.field_of_study"> · {{ q.field_of_study }}</span>
                <span v-if="q.year_obtained" class="ed-qual-year">{{ q.year_obtained }}</span>
              </div>
              <div v-if="q.certificate_number" class="ed-qual-cert">Cert / Reg #: {{ q.certificate_number }}</div>
            </div>
          </div>
          <p v-else-if="!showQualForm" class="ed-empty-hint">No qualifications or certifications recorded.</p>
        </div>

        <!-- Secondments / Placements -->
        <div class="ed-section-card">
          <div class="ed-section-hdr" style="margin-bottom:12px">
            <h3 class="ed-section-title" style="margin:0">Secondments &amp; External Placements</h3>
            <button v-if="hasSensitive" class="btn btn-outline btn-sm" @click="showSecondmentForm = !showSecondmentForm">
              {{ showSecondmentForm ? "✕ Cancel" : "+ Add" }}
            </button>
          </div>

          <div v-if="showSecondmentForm" class="ed-sub-form">
            <p v-if="secondmentError" class="error" style="margin-bottom:8px">{{ secondmentError }}</p>
            <div class="ed-comp-form-grid">
              <div>
                <label class="ed-form-label">Organization *</label>
                <input v-model="secondmentForm.organization_name" type="text" class="input" placeholder="Organization name" />
              </div>
              <div>
                <label class="ed-form-label">Role / Title</label>
                <input v-model="secondmentForm.role" type="text" class="input" placeholder="Job title during secondment" />
              </div>
              <div>
                <label class="ed-form-label">Start Date *</label>
                <input v-model="secondmentForm.start_date" type="date" class="input" />
              </div>
              <div>
                <label class="ed-form-label">End Date</label>
                <input v-model="secondmentForm.end_date" type="date" class="input" />
              </div>
            </div>
            <div style="margin-top:8px">
              <label class="ed-form-label">Notes</label>
              <input v-model="secondmentForm.notes" type="text" class="input" placeholder="Optional notes" />
            </div>
            <div style="margin-top:8px;display:flex;gap:8px">
              <button class="btn btn-primary btn-sm" :disabled="secondmentSaving || !secondmentForm.organization_name || !secondmentForm.start_date" @click="addSecondment">
                {{ secondmentSaving ? "Saving…" : "Save Secondment" }}
              </button>
            </div>
          </div>

          <div v-if="(employee as any).secondments?.length" class="ed-secondment-list">
            <div v-for="s in (employee as any).secondments" :key="s.id" class="ed-secondment-card">
              <div class="ed-secondment-top">
                <div style="flex:1;min-width:0">
                  <span class="ed-secondment-org">{{ s.organization_name }}</span>
                  <span v-if="s.role" class="ed-secondment-role">{{ s.role }}</span>
                </div>
                <button v-if="hasSensitive" class="act-btn act-del" @click="deleteSecondment(s.id)">✕</button>
              </div>
              <div class="ed-secondment-dates">
                {{ fmtDate(s.start_date) }} → {{ s.end_date ? fmtDate(s.end_date) : "Present" }}
              </div>
              <p v-if="s.notes" class="ed-secondment-notes">{{ s.notes }}</p>
            </div>
          </div>
          <p v-else-if="!showSecondmentForm" class="ed-empty-hint">No secondments or external placements recorded.</p>
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

        <!-- Professional Summary (full-width, always visible) -->
        <div class="ed-section-card">
          <div class="ed-section-hdr" style="margin-bottom:12px">
            <h3 class="ed-section-title" style="margin:0">Professional Summary</h3>
            <button v-if="!editingProfile" class="btn btn-outline btn-sm" @click="openEditProfile">✏ Edit</button>
            <button v-if="editingProfile" class="btn btn-secondary btn-sm" @click="editingProfile = false">Cancel</button>
          </div>

          <!-- Edit mode -->
          <div v-if="editingProfile">
            <div class="form-group">
              <label class="ed-form-label">Professional Summary</label>
              <textarea v-model="profileDraft.professional_summary" class="input" rows="4" style="width:100%;resize:vertical" placeholder="Brief professional background and career highlights…" />
            </div>
            <div class="form-group">
              <label class="ed-form-label">About Me</label>
              <textarea v-model="profileDraft.about_me" class="input" rows="3" style="width:100%;resize:vertical" placeholder="Personal statement or introduction…" />
            </div>
            <div class="form-group">
              <label class="ed-form-label">Hobbies &amp; Interests</label>
              <textarea v-model="profileDraft.hobbies_interests" class="input" rows="2" style="width:100%;resize:vertical" placeholder="Reading, running, photography…" />
            </div>
            <button class="btn btn-primary btn-sm" :disabled="profileSaving" @click="saveProfile">{{ profileSaving ? "Saving…" : "Save Profile" }}</button>
          </div>

          <!-- Read mode -->
          <template v-else>
            <p v-if="(employee as any).professional_summary" class="ed-prose">{{ (employee as any).professional_summary }}</p>
            <p v-else class="ed-empty-hint">No professional summary added. Click Edit to add one.</p>
          </template>
        </div>

        <!-- About Me + Hobbies (read-only, two-col) -->
        <div v-if="!editingProfile" class="ed-two-col">
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
.ed-photo-btn { position: absolute; bottom: 0; right: 0; width: 26px; height: 26px; border-radius: 50%; background: #fff; border: 1.5px solid #e2e8f0; display: flex; align-items: center; justify-content: center; cursor: pointer; color: #475569; box-shadow: 0 1px 4px rgba(0,0,0,.12); transition: background 0.15s; }
.ed-photo-btn:hover { background: #f1f5f9; }
.ed-photo-btn:disabled { opacity: 0.6; cursor: default; }

/* Tabs */
.ed-tabs { display: flex; border-bottom: 2px solid #e2e8f0; margin: 24px 0 0; overflow-x: auto; }
.ed-tab { background: none; border: none; border-bottom: 2px solid transparent; margin-bottom: -2px; padding: 10px 20px; font-size: 0.88rem; font-weight: 500; color: #64748b; cursor: pointer; white-space: nowrap; transition: color 0.15s, border-color 0.15s; text-decoration: none; }
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

/* Qualifications */
.ed-qual-list { display: flex; flex-direction: column; gap: 10px; }
.ed-qual-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 16px; }
.ed-qual-top { display: flex; align-items: flex-start; gap: 8px; margin-bottom: 4px; }
.ed-qual-title { font-size: 0.9rem; font-weight: 600; color: #0f172a; }
.ed-qual-type { font-size: 0.68rem; font-weight: 600; padding: 2px 8px; border-radius: 20px; text-transform: capitalize; margin-left: 6px; white-space: nowrap; }
.ed-qual-meta { font-size: 0.8rem; color: #64748b; display: flex; flex-wrap: wrap; gap: 0; }
.ed-qual-year { margin-left: auto; font-size: 0.82rem; font-weight: 600; color: #374151; }
.ed-qual-cert { font-size: 0.76rem; color: #94a3b8; margin-top: 4px; font-family: ui-monospace, monospace; }

/* Secondments */
.ed-secondment-list { display: flex; flex-direction: column; gap: 10px; }
.ed-secondment-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 16px; }
.ed-secondment-top { display: flex; align-items: flex-start; gap: 8px; margin-bottom: 4px; }
.ed-secondment-org { font-size: 0.9rem; font-weight: 600; color: #0f172a; }
.ed-secondment-role { font-size: 0.78rem; color: #64748b; background: #f1f5f9; padding: 2px 8px; border-radius: 20px; margin-left: 6px; }
.ed-secondment-dates { font-size: 0.8rem; color: #64748b; margin-bottom: 4px; }
.ed-secondment-notes { font-size: 0.8rem; color: #94a3b8; margin: 4px 0 0; }

/* Empty hint */
.ed-empty-hint { font-size: 0.82rem; color: #94a3b8; margin: 0; }

/* KV wrap */
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

/* form-group spacing */
.form-group { margin-bottom: 12px; }

/* Act buttons */
.act-btn { background: none; border: 1px solid #e2e8f0; border-radius: 5px; padding: 2px 7px; font-size: 0.78rem; cursor: pointer; color: #64748b; }
.act-btn:hover { background: #f1f5f9; }
.act-del { color: #dc2626; border-color: #fca5a5; }
.act-del:hover { background: #fff7f7; }

@media (max-width: 640px) {
  .ed-comp-form-grid { grid-template-columns: 1fr; }
  .ed-profile-card { flex-direction: column; }
  .ed-ec-grid { grid-template-columns: 1fr; }
}
</style>
