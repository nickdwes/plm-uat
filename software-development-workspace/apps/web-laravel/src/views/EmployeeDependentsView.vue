<script setup lang="ts">
import { ref, reactive, onMounted } from "vue";
import { useRouter } from "vue-router";
import { api } from "../lib/api";
import { currentOrgSlug } from "../stores/auth";
import type { EmployeeDependent } from "../lib/types";

const props  = defineProps<{ id: string }>();
const router = useRouter();

const dependents  = ref<EmployeeDependent[]>([]);
const loading     = ref(false);
const error       = ref("");
const showForm    = ref(false);
const saving      = ref(false);
const formError   = ref("");
const editingId   = ref<number | null>(null);
const employeeName = ref("");

const RELATIONSHIPS = ["Spouse", "Child", "Parent", "Sibling", "Guardian", "Other"];
const GENDERS       = ["Male", "Female", "Other"];

const emptyForm = () => ({
  first_name: "", last_name: "", middle_name: "", relationship: "",
  date_of_birth: "", gender: "", national_id: "", passport_number: "",
  school_institution: "", disability: "", notes: "",
});
const form = reactive(emptyForm());

function org() { return currentOrgSlug.value; }

function fmtDate(d: string | null | undefined): string {
  if (!d) return "—";
  try { return new Date(d).toLocaleDateString("en-KE", { year: "numeric", month: "short", day: "numeric" }); }
  catch { return d; }
}

function calcAge(dob: string | null | undefined): string {
  if (!dob) return "—";
  const age = Math.floor((Date.now() - new Date(dob).getTime()) / (365.25 * 24 * 60 * 60 * 1000));
  return `${age} yrs`;
}

async function load() {
  loading.value = true; error.value = "";
  try {
    const res = await api<any>("GET", `/api/v1/employees/${props.id}`, { org: org() });
    const emp = (res as any).data ?? res;
    employeeName.value = emp.full_name ?? "";

    const dRes = await api<any>("GET", `/api/v1/employees/${props.id}/dependents`, { org: org() });
    dependents.value = ((dRes as any).data ?? dRes) as EmployeeDependent[];
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to load.";
  } finally { loading.value = false; }
}

function openAdd() {
  editingId.value = null;
  Object.assign(form, emptyForm());
  formError.value = "";
  showForm.value  = true;
}

function openEdit(d: EmployeeDependent) {
  editingId.value = d.id;
  Object.assign(form, {
    first_name:        d.first_name,
    last_name:         d.last_name,
    middle_name:       d.middle_name ?? "",
    relationship:      d.relationship,
    date_of_birth:     d.date_of_birth ?? "",
    gender:            d.gender ?? "",
    national_id:       d.national_id ?? "",
    passport_number:   d.passport_number ?? "",
    school_institution:d.school_institution ?? "",
    disability:        d.disability ?? "",
    notes:             d.notes ?? "",
  });
  formError.value = "";
  showForm.value  = true;
}

async function save() {
  if (!form.first_name || !form.last_name || !form.relationship) {
    formError.value = "First name, last name and relationship are required.";
    return;
  }
  saving.value = true; formError.value = "";
  try {
    const body = { ...form } as any;
    if (!body.date_of_birth) delete body.date_of_birth;
    if (editingId.value) {
      await api("PUT", `/api/v1/employees/${props.id}/dependents/${editingId.value}`, { org: org(), body });
    } else {
      await api("POST", `/api/v1/employees/${props.id}/dependents`, { org: org(), body });
    }
    showForm.value = false;
    await load();
  } catch (e) { formError.value = e instanceof Error ? e.message : "Save failed."; }
  finally { saving.value = false; }
}

async function remove(id: number, name: string) {
  if (!confirm(`Remove ${name} from dependents?`)) return;
  await api("DELETE", `/api/v1/employees/${props.id}/dependents/${id}`, { org: org() });
  await load();
}

onMounted(load);
</script>

<template>
  <div class="dep-root">
    <nav class="dep-breadcrumb">
      <span class="dep-bc-link" @click="router.push({ name: 'employees' })">Employees</span>
      <span>/</span>
      <span class="dep-bc-link" @click="router.push({ name: 'employee-detail', params: { id: props.id } })">{{ employeeName || `#${props.id}` }}</span>
      <span>/</span>
      <span class="dep-bc-current">Dependents</span>
    </nav>

    <div class="dep-header">
      <div>
        <h2 class="dep-title">Dependents</h2>
        <p class="dep-sub">Family members registered as dependents for {{ employeeName }}.</p>
      </div>
      <button class="btn btn-primary" @click="openAdd">+ Add Dependent</button>
    </div>

    <p v-if="loading" class="status">Loading…</p>
    <p v-if="error"   class="error">{{ error }}</p>

    <!-- Form modal -->
    <div v-if="showForm" class="dep-modal-overlay" @click.self="showForm = false">
      <div class="dep-modal">
        <div class="dep-modal-header">
          <h3>{{ editingId ? "Edit Dependent" : "Add Dependent" }}</h3>
          <button class="dep-close-btn" @click="showForm = false">✕</button>
        </div>
        <p v-if="formError" class="error" style="margin-bottom:12px">{{ formError }}</p>
        <div class="dep-form-grid">
          <div>
            <label class="dep-form-label">First Name *</label>
            <input v-model="form.first_name" type="text" class="input" />
          </div>
          <div>
            <label class="dep-form-label">Last Name *</label>
            <input v-model="form.last_name" type="text" class="input" />
          </div>
          <div>
            <label class="dep-form-label">Middle Name</label>
            <input v-model="form.middle_name" type="text" class="input" />
          </div>
          <div>
            <label class="dep-form-label">Relationship *</label>
            <select v-model="form.relationship" class="input">
              <option value="">Select…</option>
              <option v-for="r in RELATIONSHIPS" :key="r" :value="r">{{ r }}</option>
            </select>
          </div>
          <div>
            <label class="dep-form-label">Date of Birth</label>
            <input v-model="form.date_of_birth" type="date" class="input" />
          </div>
          <div>
            <label class="dep-form-label">Gender</label>
            <select v-model="form.gender" class="input">
              <option value="">Select…</option>
              <option v-for="g in GENDERS" :key="g" :value="g">{{ g }}</option>
            </select>
          </div>
          <div>
            <label class="dep-form-label">National ID</label>
            <input v-model="form.national_id" type="text" class="input" />
          </div>
          <div>
            <label class="dep-form-label">Passport Number</label>
            <input v-model="form.passport_number" type="text" class="input" />
          </div>
          <div>
            <label class="dep-form-label">School / Institution</label>
            <input v-model="form.school_institution" type="text" class="input" />
          </div>
          <div>
            <label class="dep-form-label">Disability (if any)</label>
            <input v-model="form.disability" type="text" class="input" />
          </div>
          <div style="grid-column:1/-1">
            <label class="dep-form-label">Notes</label>
            <textarea v-model="form.notes" class="input" rows="2" style="resize:vertical" />
          </div>
        </div>
        <div class="dep-modal-footer">
          <button class="btn btn-primary" :disabled="saving" @click="save">{{ saving ? "Saving…" : "Save" }}</button>
          <button class="btn btn-secondary" @click="showForm = false">Cancel</button>
        </div>
      </div>
    </div>

    <!-- Table -->
    <div v-if="!loading && dependents.length" class="dep-table-wrap">
      <table class="dep-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Relationship</th>
            <th>Date of Birth</th>
            <th>Age</th>
            <th>Gender</th>
            <th>School / Institution</th>
            <th>National ID</th>
            <th style="width:90px"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="d in dependents" :key="d.id">
            <td class="dep-name-cell">
              {{ d.full_name ?? `${d.first_name} ${d.last_name}` }}
              <span v-if="d.disability" class="dep-tag dep-tag-warn">Disability</span>
            </td>
            <td>{{ d.relationship }}</td>
            <td>{{ fmtDate(d.date_of_birth) }}</td>
            <td>{{ calcAge(d.date_of_birth) }}</td>
            <td>{{ d.gender ?? "—" }}</td>
            <td>{{ d.school_institution ?? "—" }}</td>
            <td class="dep-mono">{{ d.national_id ?? "—" }}</td>
            <td class="dep-actions-cell">
              <button class="act-btn" @click="openEdit(d)">✏</button>
              <button class="act-btn act-del" @click="remove(d.id, d.full_name ?? d.first_name)">✕</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-else-if="!loading && !error" class="dep-empty">
      <p>No dependents recorded for this employee.</p>
      <button class="btn btn-outline" @click="openAdd">+ Add First Dependent</button>
    </div>
  </div>
</template>

<style scoped>
.dep-root { padding: 24px; max-width: 1100px; }
.dep-breadcrumb { display: flex; align-items: center; gap: 6px; font-size: 0.82rem; color: #94a3b8; margin-bottom: 20px; }
.dep-bc-link { cursor: pointer; color: var(--color-brand-base); font-weight: 500; }
.dep-bc-link:hover { text-decoration: underline; }
.dep-bc-current { color: #475569; font-weight: 500; }

.dep-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 24px; gap: 16px; }
.dep-title { font-size: 1.25rem; font-weight: 700; color: #0f172a; margin: 0 0 4px; }
.dep-sub { font-size: 0.82rem; color: #64748b; margin: 0; }

/* Modal */
.dep-modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.35); display: flex; align-items: center; justify-content: center; z-index: 200; padding: 16px; }
.dep-modal { background: #fff; border-radius: 12px; width: 100%; max-width: 720px; max-height: 90vh; overflow-y: auto; padding: 24px; }
.dep-modal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
.dep-modal-header h3 { font-size: 1rem; font-weight: 700; margin: 0; }
.dep-close-btn { background: none; border: none; font-size: 1.1rem; cursor: pointer; color: #94a3b8; }
.dep-modal-footer { display: flex; gap: 8px; margin-top: 20px; border-top: 1px solid #f1f5f9; padding-top: 16px; }
.dep-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.dep-form-label { display: block; font-size: 0.8rem; font-weight: 600; margin-bottom: 4px; color: #374151; }

/* Table */
.dep-table-wrap { overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 10px; }
.dep-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.dep-table th { background: #f8fafc; color: #64748b; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; padding: 10px 14px; text-align: left; border-bottom: 1px solid #e2e8f0; }
.dep-table td { padding: 12px 14px; border-bottom: 1px solid #f8fafc; vertical-align: middle; color: #0f172a; }
.dep-table tr:last-child td { border-bottom: none; }
.dep-table tr:hover td { background: #f8fafc; }
.dep-name-cell { font-weight: 600; }
.dep-mono { font-family: ui-monospace, monospace; font-size: 0.82rem; }
.dep-tag { display: inline-block; font-size: 0.68rem; font-weight: 600; padding: 2px 7px; border-radius: 20px; margin-left: 6px; }
.dep-tag-warn { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
.dep-actions-cell { display: flex; gap: 4px; }
.act-btn { background: none; border: 1px solid #e2e8f0; border-radius: 5px; padding: 3px 8px; font-size: 0.78rem; cursor: pointer; color: #64748b; }
.act-btn:hover { background: #f1f5f9; }
.act-del { color: #dc2626; border-color: #fca5a5; }
.act-del:hover { background: #fff7f7; }

.dep-empty { text-align: center; padding: 60px 24px; color: #64748b; border: 2px dashed #e2e8f0; border-radius: 10px; }
.dep-empty p { margin: 0 0 16px; }

@media (max-width: 600px) { .dep-form-grid { grid-template-columns: 1fr; } }
</style>
