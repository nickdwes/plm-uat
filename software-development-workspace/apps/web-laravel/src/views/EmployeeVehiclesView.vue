<script setup lang="ts">
import { ref, reactive, onMounted } from "vue";
import { useRouter } from "vue-router";
import { api } from "../lib/api";
import { currentOrgSlug } from "../stores/auth";
import type { EmployeeVehicle } from "../lib/types";

const props  = defineProps<{ id: string }>();
const router = useRouter();

const vehicles     = ref<EmployeeVehicle[]>([]);
const loading      = ref(false);
const error        = ref("");
const showForm     = ref(false);
const saving       = ref(false);
const formError    = ref("");
const editingId    = ref<number | null>(null);
const employeeName = ref("");

const CURRENT_YEAR = new Date().getFullYear();
const MIN_YEAR     = 1900;

const emptyForm = () => ({
  registration_number: "", make: "", model: "", color: "", year: "" as string | number,
  parking_slot: "", notes: "",
});
const form = reactive(emptyForm());

function org() { return currentOrgSlug.value; }

async function load() {
  loading.value = true; error.value = "";
  try {
    const empRes = await api<any>("GET", `/api/v1/employees/${props.id}`, { org: org() });
    employeeName.value = ((empRes as any).data ?? empRes).full_name ?? "";

    const vRes = await api<any>("GET", `/api/v1/employees/${props.id}/vehicles`, { org: org() });
    vehicles.value = ((vRes as any).data ?? vRes) as EmployeeVehicle[];
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

function openEdit(v: EmployeeVehicle) {
  editingId.value = v.id;
  Object.assign(form, {
    registration_number: v.registration_number,
    make:        v.make ?? "",
    model:       v.model ?? "",
    color:       v.color ?? "",
    year:        v.year ?? "",
    parking_slot:v.parking_slot ?? "",
    notes:       v.notes ?? "",
  });
  formError.value = "";
  showForm.value  = true;
}

async function save() {
  if (!form.registration_number) {
    formError.value = "Registration number is required.";
    return;
  }
  const yr = Number(form.year);
  if (form.year && (isNaN(yr) || yr < MIN_YEAR || yr > CURRENT_YEAR + 1)) {
    formError.value = `Year must be between ${MIN_YEAR} and ${CURRENT_YEAR + 1}.`;
    return;
  }
  saving.value = true; formError.value = "";
  try {
    const body: any = { ...form };
    body.year = form.year ? Number(form.year) : null;
    if (!body.year) delete body.year;
    if (editingId.value) {
      await api("PUT", `/api/v1/employees/${props.id}/vehicles/${editingId.value}`, { org: org(), body });
    } else {
      await api("POST", `/api/v1/employees/${props.id}/vehicles`, { org: org(), body });
    }
    showForm.value = false;
    await load();
  } catch (e) { formError.value = e instanceof Error ? e.message : "Save failed."; }
  finally { saving.value = false; }
}

async function remove(id: number, reg: string) {
  if (!confirm(`Remove vehicle ${reg}?`)) return;
  await api("DELETE", `/api/v1/employees/${props.id}/vehicles/${id}`, { org: org() });
  await load();
}

onMounted(load);
</script>

<template>
  <div class="veh-root">
    <nav class="veh-breadcrumb">
      <span class="veh-bc-link" @click="router.push({ name: 'employees' })">Employees</span>
      <span>/</span>
      <span class="veh-bc-link" @click="router.push({ name: 'employee-detail', params: { id: props.id } })">{{ employeeName || `#${props.id}` }}</span>
      <span>/</span>
      <span class="veh-bc-current">Vehicles</span>
    </nav>

    <div class="veh-header">
      <div>
        <h2 class="veh-title">Vehicles</h2>
        <p class="veh-sub">Registered vehicles for {{ employeeName }} (for parking access).</p>
      </div>
      <button class="btn btn-primary" @click="openAdd">+ Add Vehicle</button>
    </div>

    <p v-if="loading" class="status">Loading…</p>
    <p v-if="error"   class="error">{{ error }}</p>

    <!-- Form modal -->
    <div v-if="showForm" class="veh-modal-overlay" @click.self="showForm = false">
      <div class="veh-modal">
        <div class="veh-modal-header">
          <h3>{{ editingId ? "Edit Vehicle" : "Add Vehicle" }}</h3>
          <button class="veh-close-btn" @click="showForm = false">✕</button>
        </div>
        <p v-if="formError" class="error" style="margin-bottom:12px">{{ formError }}</p>
        <div class="veh-form-grid">
          <div>
            <label class="veh-form-label">Registration Number *</label>
            <input v-model="form.registration_number" type="text" class="input" placeholder="e.g. KBZ 123A" style="text-transform:uppercase" />
          </div>
          <div>
            <label class="veh-form-label">Make</label>
            <input v-model="form.make" type="text" class="input" placeholder="e.g. Toyota" />
          </div>
          <div>
            <label class="veh-form-label">Model</label>
            <input v-model="form.model" type="text" class="input" placeholder="e.g. Prado" />
          </div>
          <div>
            <label class="veh-form-label">Color</label>
            <input v-model="form.color" type="text" class="input" />
          </div>
          <div>
            <label class="veh-form-label">Year</label>
            <input v-model="form.year" type="number" :min="MIN_YEAR" :max="CURRENT_YEAR + 1" class="input" />
          </div>
          <div>
            <label class="veh-form-label">Parking Slot</label>
            <input v-model="form.parking_slot" type="text" class="input" placeholder="e.g. B-12" />
          </div>
          <div style="grid-column:1/-1">
            <label class="veh-form-label">Notes</label>
            <textarea v-model="form.notes" class="input" rows="2" style="resize:vertical" />
          </div>
        </div>
        <div class="veh-modal-footer">
          <button class="btn btn-primary" :disabled="saving" @click="save">{{ saving ? "Saving…" : "Save" }}</button>
          <button class="btn btn-secondary" @click="showForm = false">Cancel</button>
        </div>
      </div>
    </div>

    <!-- Table -->
    <div v-if="!loading && vehicles.length" class="veh-table-wrap">
      <table class="veh-table">
        <thead>
          <tr>
            <th>Registration</th>
            <th>Make</th>
            <th>Model</th>
            <th>Color</th>
            <th>Year</th>
            <th>Parking Slot</th>
            <th>Notes</th>
            <th style="width:90px"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="v in vehicles" :key="v.id">
            <td class="veh-reg">{{ v.registration_number }}</td>
            <td>{{ v.make ?? "—" }}</td>
            <td>{{ v.model ?? "—" }}</td>
            <td>{{ v.color ?? "—" }}</td>
            <td>{{ v.year ?? "—" }}</td>
            <td>
              <span v-if="v.parking_slot" class="veh-slot-badge">{{ v.parking_slot }}</span>
              <span v-else>—</span>
            </td>
            <td class="veh-notes">{{ v.notes ?? "—" }}</td>
            <td class="veh-actions">
              <button class="act-btn" @click="openEdit(v)">✏</button>
              <button class="act-btn act-del" @click="remove(v.id, v.registration_number)">✕</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-else-if="!loading && !error" class="veh-empty">
      <p>No vehicles registered for this employee.</p>
      <button class="btn btn-outline" @click="openAdd">+ Register a Vehicle</button>
    </div>
  </div>
</template>

<style scoped>
.veh-root { padding: 24px; max-width: 1100px; }
.veh-breadcrumb { display: flex; align-items: center; gap: 6px; font-size: 0.82rem; color: #94a3b8; margin-bottom: 20px; }
.veh-bc-link { cursor: pointer; color: var(--color-brand-base); font-weight: 500; }
.veh-bc-link:hover { text-decoration: underline; }
.veh-bc-current { color: #475569; font-weight: 500; }

.veh-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 24px; gap: 16px; }
.veh-title { font-size: 1.25rem; font-weight: 700; color: #0f172a; margin: 0 0 4px; }
.veh-sub { font-size: 0.82rem; color: #64748b; margin: 0; }

/* Modal */
.veh-modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.35); display: flex; align-items: center; justify-content: center; z-index: 200; padding: 16px; }
.veh-modal { background: #fff; border-radius: 12px; width: 100%; max-width: 640px; max-height: 90vh; overflow-y: auto; padding: 24px; }
.veh-modal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
.veh-modal-header h3 { font-size: 1rem; font-weight: 700; margin: 0; }
.veh-close-btn { background: none; border: none; font-size: 1.1rem; cursor: pointer; color: #94a3b8; }
.veh-modal-footer { display: flex; gap: 8px; margin-top: 20px; border-top: 1px solid #f1f5f9; padding-top: 16px; }
.veh-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.veh-form-label { display: block; font-size: 0.8rem; font-weight: 600; margin-bottom: 4px; color: #374151; }

/* Table */
.veh-table-wrap { overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 10px; }
.veh-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.veh-table th { background: #f8fafc; color: #64748b; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; padding: 10px 14px; text-align: left; border-bottom: 1px solid #e2e8f0; }
.veh-table td { padding: 12px 14px; border-bottom: 1px solid #f8fafc; vertical-align: middle; color: #0f172a; }
.veh-table tr:last-child td { border-bottom: none; }
.veh-table tr:hover td { background: #f8fafc; }
.veh-reg { font-family: ui-monospace, monospace; font-weight: 700; font-size: 0.88rem; color: var(--color-brand-base); text-transform: uppercase; }
.veh-slot-badge { background: #f0fdf4; border: 1px solid #86efac; color: #166534; font-size: 0.78rem; font-weight: 600; padding: 2px 10px; border-radius: 20px; font-family: ui-monospace, monospace; }
.veh-notes { color: #64748b; font-size: 0.82rem; max-width: 180px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.veh-actions { display: flex; gap: 4px; }
.act-btn { background: none; border: 1px solid #e2e8f0; border-radius: 5px; padding: 3px 8px; font-size: 0.78rem; cursor: pointer; color: #64748b; }
.act-btn:hover { background: #f1f5f9; }
.act-del { color: #dc2626; border-color: #fca5a5; }
.act-del:hover { background: #fff7f7; }

.veh-empty { text-align: center; padding: 60px 24px; color: #64748b; border: 2px dashed #e2e8f0; border-radius: 10px; }
.veh-empty p { margin: 0 0 16px; }

@media (max-width: 600px) { .veh-form-grid { grid-template-columns: 1fr; } }
</style>
