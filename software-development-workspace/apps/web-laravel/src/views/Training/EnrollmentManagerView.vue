<script setup lang="ts">
import { ref, computed, onMounted, watch } from "vue";
import { api } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import type { TrainingItem, EmployeeTrainingRecord } from "../../lib/types";

interface Employee { id: number; full_name: string; employee_number: string; department?: string | null; }

function org() { return currentOrgSlug.value; }

// ── Catalog ──────────────────────────────────────────────────────────────────
const catalogItems = ref<TrainingItem[]>([]);
const selectedItem = ref<TrainingItem | null>(null);
const itemSearch = ref("");

const filteredItems = computed(() =>
  catalogItems.value.filter(i =>
    i.title.toLowerCase().includes(itemSearch.value.toLowerCase())
  )
);

async function loadCatalog() {
  if (!org()) return;
  const res = await api<{ data: TrainingItem[] }>("GET", "/api/v1/training/catalog", {
    org: org(),
    params: { per_page: 200 },
  });
  catalogItems.value = (res as unknown as { data: TrainingItem[]; meta: unknown }).data ??
    (res as unknown as { data: { data: TrainingItem[] } }).data?.data ?? [];
}

// ── Employees ────────────────────────────────────────────────────────────────
const employees = ref<Employee[]>([]);
const empSearch = ref("");
const selectedEmpIds = ref<Set<number>>(new Set());

const filteredEmployees = computed(() =>
  employees.value.filter(e =>
    e.full_name.toLowerCase().includes(empSearch.value.toLowerCase()) ||
    e.employee_number.toLowerCase().includes(empSearch.value.toLowerCase())
  )
);

function toggleEmp(id: number) {
  if (selectedEmpIds.value.has(id)) selectedEmpIds.value.delete(id);
  else selectedEmpIds.value.add(id);
  selectedEmpIds.value = new Set(selectedEmpIds.value); // trigger reactivity
}

function toggleAll() {
  if (selectedEmpIds.value.size === filteredEmployees.value.length) {
    selectedEmpIds.value = new Set();
  } else {
    selectedEmpIds.value = new Set(filteredEmployees.value.map(e => e.id));
  }
}

const allSelected = computed(() =>
  filteredEmployees.value.length > 0 &&
  selectedEmpIds.value.size === filteredEmployees.value.length
);

async function loadEmployees() {
  if (!org()) return;
  try {
    const res = await api<{ data: Employee[] }>("GET", "/api/v1/employees", {
      org: org(),
      params: { per_page: 500 },
    });
    employees.value = (res as unknown as { data: Employee[] }).data ??
      (res as unknown as { data: { data: Employee[] } }).data?.data ?? [];
  } catch {
    employees.value = [];
  }
}

// ── Existing enrollments for selected item ────────────────────────────────────
const enrollments = ref<EmployeeTrainingRecord[]>([]);
const loadingEnrollments = ref(false);

const enrolledIds = computed(() => new Set(enrollments.value.map(r => r.employee?.id).filter(Boolean) as number[]));

async function loadEnrollments(itemId: number) {
  loadingEnrollments.value = true;
  try {
    const res = await api<{ data: EmployeeTrainingRecord[] }>("GET", "/api/v1/training/records", {
      org: org(),
      params: { training_item_id: itemId, per_page: 500 },
    });
    enrollments.value = (res as unknown as { data: EmployeeTrainingRecord[] }).data ??
      (res as unknown as { data: { data: EmployeeTrainingRecord[] } }).data?.data ?? [];
  } finally {
    loadingEnrollments.value = false;
  }
}

watch(selectedItem, (item) => {
  selectedEmpIds.value = new Set();
  if (item) loadEnrollments(item.id);
  else enrollments.value = [];
});

// ── Assign ────────────────────────────────────────────────────────────────────
const dueDate = ref("");
const assigning = ref(false);
const assignResult = ref<{ enrolled: number; skipped: number } | null>(null);
const assignError = ref("");

async function assign() {
  if (!selectedItem.value) { assignError.value = "Select a training item first."; return; }
  if (selectedEmpIds.value.size === 0) { assignError.value = "Select at least one employee."; return; }

  assigning.value = true;
  assignError.value = "";
  assignResult.value = null;

  try {
    const res = await api<{ enrolled: number; skipped: number }>("POST", "/api/v1/training/records/bulk-assign", {
      org: org(),
      body: {
        training_item_id: selectedItem.value.id,
        employee_ids: Array.from(selectedEmpIds.value),
        due_date: dueDate.value || null,
      },
    });
    assignResult.value = res as { enrolled: number; skipped: number };
    selectedEmpIds.value = new Set();
    dueDate.value = "";
    loadEnrollments(selectedItem.value!.id);
  } catch (e: unknown) {
    const err = e as { message?: string };
    assignError.value = err.message ?? "Assignment failed.";
  } finally {
    assigning.value = false;
  }
}

// ── Withdraw (HR) ────────────────────────────────────────────────────────────
async function withdrawRecord(record: EmployeeTrainingRecord) {
  const name = record.employee?.name ?? "this employee";
  if (!confirm(`Withdraw ${name} from "${selectedItem.value?.title}"?`)) return;
  try {
    await api("PATCH", `/api/v1/training/records/${record.id}/withdraw`, { org: org() });
    loadEnrollments(selectedItem.value!.id);
  } catch (e) {
    assignError.value = e instanceof Error ? e.message : "Failed to withdraw.";
  }
}

onMounted(async () => {
  await Promise.all([loadCatalog(), loadEmployees()]);
});

watch(currentOrgSlug, () => {
  selectedItem.value = null;
  enrollments.value = [];
  Promise.all([loadCatalog(), loadEmployees()]);
});
</script>

<template>
  <div class="view">
    <div class="view-header">
      <div>
        <h2>Training Enrollment Manager</h2>
        <p class="subtitle">Assign mandatory training to employees</p>
      </div>
    </div>

    <div class="manager-layout">

      <!-- LEFT: Training item selector -->
      <div class="panel">
        <div class="panel-header">
          <h3>1. Select Training Item</h3>
        </div>
        <input
          v-model="itemSearch"
          type="search"
          class="search-input"
          placeholder="Search training…"
        />
        <div class="item-list">
          <div
            v-for="item in filteredItems"
            :key="item.id"
            :class="['item-row', { selected: selectedItem?.id === item.id }]"
            @click="selectedItem = item"
          >
            <div class="item-row-main">
              <span class="item-name">{{ item.title }}</span>
              <span class="badge" :class="'badge-' + item.type">{{ item.type }}</span>
            </div>
            <span class="item-provider">{{ item.provider_name }}</span>
          </div>
          <div v-if="filteredItems.length === 0" class="empty-small">No items found.</div>
        </div>
      </div>

      <!-- MIDDLE: Employee selector -->
      <div class="panel">
        <div class="panel-header">
          <h3>2. Select Employees</h3>
          <button class="link-btn" @click="toggleAll">
            {{ allSelected ? "Deselect All" : "Select All" }}
          </button>
        </div>
        <input
          v-model="empSearch"
          type="search"
          class="search-input"
          placeholder="Search employees…"
        />
        <div class="emp-list">
          <label
            v-for="emp in filteredEmployees"
            :key="emp.id"
            :class="['emp-row', { enrolled: enrolledIds.has(emp.id) }]"
          >
            <input
              type="checkbox"
              :checked="selectedEmpIds.has(emp.id)"
              :disabled="enrolledIds.has(emp.id)"
              @change="toggleEmp(emp.id)"
            />
            <div class="emp-info">
              <span class="emp-name">{{ emp.full_name }}</span>
              <span class="emp-num">{{ emp.employee_number }}</span>
            </div>
            <span v-if="enrolledIds.has(emp.id)" class="already-badge">enrolled</span>
          </label>
          <div v-if="filteredEmployees.length === 0" class="empty-small">No employees found.</div>
        </div>
        <div class="selection-summary">
          {{ selectedEmpIds.size }} selected · {{ enrolledIds.size }} already enrolled
        </div>
      </div>

      <!-- RIGHT: Assign panel -->
      <div class="panel">
        <div class="panel-header">
          <h3>3. Assign</h3>
        </div>

        <!-- Selected training item preview -->
        <div v-if="selectedItem" class="assign-preview">
          <div class="preview-title">{{ selectedItem.title }}</div>
          <div class="preview-meta">
            {{ selectedItem.type }} · {{ selectedItem.delivery }} ·
            {{ selectedItem.duration_hours }}h {{ selectedItem.duration_minutes }}m
          </div>
        </div>
        <div v-else class="placeholder-box">← Select a training item</div>

        <!-- Due date -->
        <div class="form-group">
          <label class="form-label">Due Date <span class="hint">(optional)</span></label>
          <input v-model="dueDate" type="date" class="form-input" />
        </div>

        <!-- Assign button -->
        <div v-if="assignError" class="alert alert-danger">{{ assignError }}</div>
        <div v-if="assignResult" class="alert alert-success">
          ✅ Enrolled {{ assignResult.enrolled }} employees.
          <span v-if="assignResult.skipped > 0">{{ assignResult.skipped }} already enrolled (skipped).</span>
        </div>

        <button
          class="btn btn-primary btn-full"
          :disabled="assigning || !selectedItem || selectedEmpIds.size === 0"
          @click="assign"
        >
          {{ assigning ? "Assigning…" : `Assign to ${selectedEmpIds.size} Employee(s)` }}
        </button>

        <!-- Enrollment list for selected item -->
        <div v-if="selectedItem" class="enrollment-section">
          <h4>Current Enrollments</h4>
          <div v-if="loadingEnrollments" class="empty-small">Loading…</div>
          <div v-else-if="enrollments.length === 0" class="empty-small">No enrollments yet.</div>
          <div v-else class="enrollment-list">
            <div v-for="r in enrollments" :key="r.id" class="enrollment-row">
              <div class="enrollment-left">
                <span class="enrollment-name">{{ r.employee?.name }}</span>
                <span :class="['badge', statusClass(r.status ?? '')]">{{ r.status }}</span>
                <span v-if="r.assignment_type === 'mandatory'" class="mandatory-badge">mandatory</span>
              </div>
              <div class="enrollment-right">
                <span class="progress-pct">{{ r.progress_percent ?? 0 }}%</span>
                <button
                  v-if="r.status !== 'completed' && r.status !== 'withdrawn'"
                  class="withdraw-btn"
                  @click="withdrawRecord(r)"
                  title="Withdraw"
                >✕</button>
              </div>
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>
</template>

<script lang="ts">
function statusClass(status: string) {
  return {
    enrolled:    "badge-enrolled",
    in_progress: "badge-progress",
    completed:   "badge-completed",
    withdrawn:   "badge-withdrawn",
  }[status] ?? "";
}
</script>

<style scoped>
.view { padding: 24px; }

.view-header { margin-bottom: 20px; }
.view-header h2 { font-size: 26px; font-weight: 600; margin: 0; }
.subtitle { font-size: 14px; color: #888; margin: 4px 0 0; }

.manager-layout {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap: 16px;
  align-items: start;
}

@media (max-width: 900px) {
  .manager-layout { grid-template-columns: 1fr; }
}

.panel {
  background: white;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  padding: 16px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  max-height: calc(100vh - 180px);
  overflow: hidden;
}

.panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.panel-header h3 { font-size: 14px; font-weight: 600; color: #374151; margin: 0; }

.link-btn {
  background: none; border: none; font-size: 12px; color: #3366cc; cursor: pointer; text-decoration: underline;
}

.search-input {
  width: 100%;
  padding: 7px 10px;
  border: 1px solid #ddd;
  border-radius: 6px;
  font-size: 13px;
  outline: none;
  box-sizing: border-box;
}

.search-input:focus { border-color: #3366cc; }

/* Item list */
.item-list {
  flex: 1;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.item-row {
  padding: 8px 10px;
  border-radius: 6px;
  cursor: pointer;
  border: 1px solid transparent;
  transition: background 0.1s;
}

.item-row:hover { background: #f9fafb; }
.item-row.selected { background: #f0f4ff; border-color: #c8d8f8; }

.item-row-main {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 6px;
}

.item-name { font-size: 13px; font-weight: 500; color: #111; flex: 1; }
.item-provider { font-size: 11px; color: #aaa; }

/* Employee list */
.emp-list {
  flex: 1;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.emp-row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 7px 10px;
  border-radius: 6px;
  cursor: pointer;
  transition: background 0.1s;
}

.emp-row:hover { background: #f9fafb; }
.emp-row.enrolled { opacity: 0.55; }

.emp-info { flex: 1; min-width: 0; }
.emp-name { font-size: 13px; font-weight: 500; display: block; }
.emp-num { font-size: 11px; color: #aaa; }

.already-badge {
  background: #f0fdf4;
  color: #15803d;
  border: 1px solid #bbf7d0;
  border-radius: 20px;
  padding: 1px 7px;
  font-size: 10px;
  flex-shrink: 0;
}

.selection-summary { font-size: 12px; color: #888; text-align: right; }

/* Assign panel */
.assign-preview {
  background: #f0f4ff;
  border: 1px solid #c8d8f8;
  border-radius: 8px;
  padding: 10px 12px;
}

.preview-title { font-size: 14px; font-weight: 600; color: #1e40af; }
.preview-meta { font-size: 12px; color: #6b7280; margin-top: 2px; }

.placeholder-box {
  background: #f9fafb;
  border: 1px dashed #d1d5db;
  border-radius: 8px;
  padding: 16px;
  font-size: 13px;
  color: #bbb;
  text-align: center;
}

.form-group { display: flex; flex-direction: column; gap: 4px; }
.form-label { font-size: 12px; font-weight: 500; color: #374151; }
.hint { font-weight: 400; color: #aaa; }

.form-input {
  padding: 7px 10px;
  border: 1px solid #ddd;
  border-radius: 6px;
  font-size: 13px;
  outline: none;
}

.form-input:focus { border-color: #3366cc; }

.alert { padding: 10px 12px; border-radius: 6px; font-size: 13px; }
.alert-danger { background: #fee; color: #c33; border: 1px solid #f99; }
.alert-success { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }

.btn {
  padding: 8px 16px;
  border-radius: 6px;
  border: 1px solid #ddd;
  font-size: 13px;
  cursor: pointer;
  font-weight: 500;
}

.btn-primary { background: #3366cc; color: white; border-color: #3366cc; }
.btn-primary:not(:disabled):hover { background: #2255bb; }
.btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-full { width: 100%; }

/* Enrollment list */
.enrollment-section { border-top: 1px solid #f0f0f0; padding-top: 12px; }
.enrollment-section h4 { font-size: 13px; font-weight: 600; color: #374151; margin: 0 0 8px; }

.enrollment-list {
  max-height: 200px;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.enrollment-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 5px 8px;
  border-radius: 6px;
  background: #fafafa;
  border: 1px solid #f0f0f0;
}

.enrollment-left { display: flex; align-items: center; gap: 6px; flex: 1; min-width: 0; }
.enrollment-name { font-size: 12px; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.enrollment-right { display: flex; align-items: center; gap: 8px; }
.progress-pct { font-size: 12px; color: #666; font-weight: 500; }

.mandatory-badge {
  background: #fff7ed;
  color: #c2410c;
  border: 1px solid #fed7aa;
  border-radius: 20px;
  padding: 1px 7px;
  font-size: 10px;
}

.withdraw-btn {
  background: none;
  border: none;
  color: #bbb;
  cursor: pointer;
  font-size: 14px;
  padding: 0 4px;
  line-height: 1;
}

.withdraw-btn:hover { color: #c0392b; }

.badge {
  padding: 2px 8px;
  border-radius: 20px;
  font-size: 10px;
  font-weight: 500;
}

.badge-enrolled    { background: #f3f4f6; color: #374151; }
.badge-progress    { background: #dbeafe; color: #1d4ed8; }
.badge-completed   { background: #dcfce7; color: #15803d; }
.badge-withdrawn   { background: #fee2e2; color: #b91c1c; }
.badge-course      { background: #e0e7ff; color: #3730a3; }
.badge-workshop    { background: #fef3c7; color: #92400e; }
.badge-certification { background: #dcfce7; color: #15803d; }
.badge-exam_voucher { background: #fce7f3; color: #9d174d; }
.badge-subscription { background: #f3f4f6; color: #374151; }

.empty-small { font-size: 12px; color: #bbb; text-align: center; padding: 12px; }
</style>
