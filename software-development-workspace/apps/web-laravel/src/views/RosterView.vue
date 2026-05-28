<script setup lang="ts">
import { ref, computed, onMounted, watch } from "vue";
import { api, ApiError } from "../lib/api";
import { currentOrgSlug, authStore } from "../stores/auth";
import type {
  Paginator,
  Employee,
  RosterConfig,
  ShiftGroup,
  ShiftType,
  RosterSchedule,
  RosterAssignment,
  ShiftSwapRequest,
} from "../lib/types";

// ── Tab state ─────────────────────────────────────────────────────────────
type Tab = "schedule" | "shift-types" | "shift-groups" | "swaps" | "config";
const tab = ref<Tab>("schedule");

function org() { return currentOrgSlug.value; }

// ─────────────────────────────────────────────────────────────────────────────
// GENERAL CONFIG
// ─────────────────────────────────────────────────────────────────────────────
const config          = ref<RosterConfig | null>(null);
const configLoading   = ref(false);
const configSaving    = ref(false);
const configError     = ref("");
const configSuccess   = ref("");
const configForm      = ref({ primary_detail_shown: "total_shift_hours", swap_shifts_enabled: false, manager_approval_required: true });

async function loadConfig() {
  if (!org()) return;
  configLoading.value = true; configError.value = "";
  try {
    const res = await api<{ data: RosterConfig }>("GET", "/api/v1/roster/config", { org: org() });
    config.value = res.data;
    configForm.value = {
      primary_detail_shown:      res.data.primary_detail_shown,
      swap_shifts_enabled:        res.data.swap_shifts_enabled,
      manager_approval_required:  res.data.manager_approval_required,
    };
  } catch (e) { configError.value = e instanceof Error ? e.message : "Failed to load config."; }
  finally { configLoading.value = false; }
}

async function saveConfig() {
  if (!org()) return;
  configSaving.value = true; configError.value = ""; configSuccess.value = "";
  try {
    const res = await api<{ data: RosterConfig }>("PUT", "/api/v1/roster/config", { org: org(), body: configForm.value });
    config.value = res.data;
    configSuccess.value = "Configuration saved.";
    setTimeout(() => configSuccess.value = "", 3000);
  } catch (e) { configError.value = e instanceof ApiError ? e.message : "Failed to save."; }
  finally { configSaving.value = false; }
}

// ─────────────────────────────────────────────────────────────────────────────
// SHIFT GROUPS
// ─────────────────────────────────────────────────────────────────────────────
const groups        = ref<ShiftGroup[]>([]);
const groupsLoading = ref(false);
const groupsError   = ref("");
const showGroupForm = ref(false);
const groupForm     = ref({ name: "", description: "" });
const groupSaving   = ref(false);
const groupFormError= ref("");
const editingGroupId= ref<number | null>(null);

async function loadGroups() {
  if (!org()) return;
  groupsLoading.value = true; groupsError.value = "";
  try {
    const res = await api<{ data: ShiftGroup[] }>("GET", "/api/v1/roster/shift-groups", { org: org() });
    groups.value = res.data;
  } catch (e) { groupsError.value = e instanceof Error ? e.message : "Failed to load shift groups."; }
  finally { groupsLoading.value = false; }
}

function openGroupForm(g?: ShiftGroup) {
  if (g) { editingGroupId.value = g.id; groupForm.value = { name: g.name, description: g.description ?? "" }; }
  else   { editingGroupId.value = null; groupForm.value = { name: "", description: "" }; }
  groupFormError.value = ""; showGroupForm.value = true;
}

async function saveGroup() {
  if (!org()) return;
  groupSaving.value = true; groupFormError.value = "";
  try {
    if (editingGroupId.value) {
      await api("PUT", `/api/v1/roster/shift-groups/${editingGroupId.value}`, { org: org(), body: groupForm.value });
    } else {
      await api("POST", "/api/v1/roster/shift-groups", { org: org(), body: groupForm.value });
    }
    showGroupForm.value = false;
    await loadGroups();
  } catch (e) { groupFormError.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Failed to save."; }
  finally { groupSaving.value = false; }
}

async function deleteGroup(id: number) {
  if (!confirm("Delete this shift group?")) return;
  await api("DELETE", `/api/v1/roster/shift-groups/${id}`, { org: org() });
  await loadGroups();
}

// ─────────────────────────────────────────────────────────────────────────────
// SHIFT TYPES
// ─────────────────────────────────────────────────────────────────────────────
const shiftTypes       = ref<ShiftType[]>([]);
const shiftTypesLoading= ref(false);
const shiftTypesError  = ref("");
const showShiftForm    = ref(false);
const editingShiftId   = ref<number | null>(null);
const shiftForm        = ref({ name: "", shift_group_id: "" as number | "", start_time: "", end_time: "", break_start: "", break_duration_minutes: 0, is_active: true });
const shiftSaving      = ref(false);
const shiftFormError   = ref("");
const shiftSearch      = ref("");

async function loadShiftTypes() {
  if (!org()) return;
  shiftTypesLoading.value = true; shiftTypesError.value = "";
  try {
    const res = await api<{ data: ShiftType[] }>("GET", "/api/v1/roster/shift-types", { org: org(), params: { search: shiftSearch.value || undefined } });
    shiftTypes.value = res.data;
  } catch (e) { shiftTypesError.value = e instanceof Error ? e.message : "Failed to load shift types."; }
  finally { shiftTypesLoading.value = false; }
}

function openShiftForm(s?: ShiftType) {
  if (s) {
    editingShiftId.value = s.id;
    shiftForm.value = { name: s.name, shift_group_id: s.shift_group?.id ?? "", start_time: s.start_time, end_time: s.end_time, break_start: s.break_start ?? "", break_duration_minutes: s.break_duration_minutes, is_active: s.is_active };
  } else {
    editingShiftId.value = null;
    shiftForm.value = { name: "", shift_group_id: "", start_time: "", end_time: "", break_start: "", break_duration_minutes: 0, is_active: true };
  }
  shiftFormError.value = ""; showShiftForm.value = true;
}

async function saveShift() {
  if (!org()) return;
  shiftSaving.value = true; shiftFormError.value = "";
  const body = { ...shiftForm.value, shift_group_id: shiftForm.value.shift_group_id || null, break_start: shiftForm.value.break_start || null };
  try {
    if (editingShiftId.value) {
      await api("PUT", `/api/v1/roster/shift-types/${editingShiftId.value}`, { org: org(), body });
    } else {
      await api("POST", "/api/v1/roster/shift-types", { org: org(), body });
    }
    showShiftForm.value = false;
    await loadShiftTypes();
  } catch (e) { shiftFormError.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Failed to save."; }
  finally { shiftSaving.value = false; }
}

async function deleteShift(id: number) {
  if (!confirm("Delete this shift type?")) return;
  await api("DELETE", `/api/v1/roster/shift-types/${id}`, { org: org() });
  await loadShiftTypes();
}

function fmtDuration(minutes: number): string {
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  return m ? `${h}h ${m}m` : `${h}h`;
}

// ─────────────────────────────────────────────────────────────────────────────
// SCHEDULES & ROSTER GRID
// ─────────────────────────────────────────────────────────────────────────────
const schedules        = ref<RosterSchedule[]>([]);
const schedulesLoading = ref(false);
const schedulesError   = ref("");
const activeSchedule   = ref<RosterSchedule | null>(null);

// New schedule form
const showSchedForm    = ref(false);
const schedForm        = ref({ name: "", start_date: "", end_date: "" });
const schedSaving      = ref(false);
const schedFormError   = ref("");

// Assignment grid
const assignments      = ref<RosterAssignment[]>([]);
const assignLoading    = ref(false);
const assignError      = ref("");

// Employees list (for assignment)
const employees        = ref<Employee[]>([]);
const employeesLoaded  = ref(false);

// Assignment modal
const showAssignModal  = ref(false);
const assignForm       = ref({ employee_id: "" as number | "", shift_type_id: "" as number | "", assignment_date: "", type: "shift" as "shift" | "day_off" | "leave", notes: "" });
const assignSaving     = ref(false);
const assignFormError  = ref("");

// Week navigation
const weekOffset = ref(0);

const weekDates = computed<string[]>(() => {
  if (!activeSchedule.value) return [];
  const start = new Date(activeSchedule.value.start_date + "T00:00:00");
  const end   = new Date(activeSchedule.value.end_date   + "T00:00:00");
  const weekStart = new Date(start);
  weekStart.setDate(weekStart.getDate() + weekOffset.value * 7);

  const dates: string[] = [];
  for (let i = 0; i < 7; i++) {
    const d = new Date(weekStart);
    d.setDate(d.getDate() + i);
    if (d <= end) dates.push(d.toISOString().slice(0, 10));
  }
  return dates;
});

const canPrevWeek = computed(() => weekOffset.value > 0);
const canNextWeek = computed(() => {
  if (!activeSchedule.value || weekDates.value.length === 0) return false;
  const last = new Date(weekDates.value[weekDates.value.length - 1] + "T00:00:00");
  const end  = new Date(activeSchedule.value.end_date + "T00:00:00");
  return last < end;
});

// Build a lookup: employeeId → date → assignment
const assignMap = computed<Record<number, Record<string, RosterAssignment>>>(() => {
  const map: Record<number, Record<string, RosterAssignment>> = {};
  for (const a of assignments.value) {
    if (!a.employee) continue;
    if (!map[a.employee.id]) map[a.employee.id] = {};
    map[a.employee.id][a.assignment_date] = a;
  }
  return map;
});

// Distinct employees that have at least one assignment in this schedule
const rosterEmployees = computed<{ id: number; full_name: string; employee_number: string }[]>(() => {
  const seen = new Set<number>();
  const list: { id: number; full_name: string; employee_number: string }[] = [];
  for (const a of assignments.value) {
    if (!a.employee || seen.has(a.employee.id)) continue;
    seen.add(a.employee.id);
    list.push(a.employee);
  }
  return list.sort((a, b) => a.full_name.localeCompare(b.full_name));
});

const totalShiftHoursForEmployee = computed(() => (empId: number): string => {
  let total = 0;
  for (const a of assignments.value) {
    if (a.employee?.id === empId && a.type === "shift" && a.shift_type) {
      total += a.shift_type.duration_minutes;
    }
  }
  return fmtDuration(total);
});

async function loadSchedules() {
  if (!org()) return;
  schedulesLoading.value = true; schedulesError.value = "";
  try {
    const res = await api<Paginator<RosterSchedule>>("GET", "/api/v1/roster", { org: org(), params: { per_page: 50 } });
    schedules.value = res.data;
    if (!activeSchedule.value && res.data.length) selectSchedule(res.data[0]);
  } catch (e) { schedulesError.value = e instanceof Error ? e.message : "Failed to load schedules."; }
  finally { schedulesLoading.value = false; }
}

async function selectSchedule(s: RosterSchedule) {
  activeSchedule.value = s;
  weekOffset.value = 0;
  await loadAssignments();
}

async function loadAssignments() {
  if (!activeSchedule.value || !org()) return;
  assignLoading.value = true; assignError.value = "";
  try {
    const res = await api<{ data: RosterAssignment[] }>("GET", `/api/v1/roster/${activeSchedule.value.id}/assignments`, { org: org() });
    assignments.value = res.data;
  } catch (e) { assignError.value = e instanceof Error ? e.message : "Failed to load assignments."; }
  finally { assignLoading.value = false; }
}

async function loadEmployees() {
  if (employeesLoaded.value || !org()) return;
  try {
    const res = await api<{ data: Employee[] }>("GET", "/api/v1/employees", { org: org(), params: { per_page: 200, status: "active" } });
    employees.value = res.data;
    employeesLoaded.value = true;
  } catch { /* non-critical */ }
}

function openAssignModal(date: string, empId?: number) {
  assignForm.value = { employee_id: empId ?? "", shift_type_id: "", assignment_date: date, type: "shift", notes: "" };
  assignFormError.value = ""; showAssignModal.value = true;
  void loadEmployees();
}

async function saveAssignment() {
  if (!activeSchedule.value || !org()) return;
  assignSaving.value = true; assignFormError.value = "";
  const body = { ...assignForm.value, shift_type_id: assignForm.value.shift_type_id || null };
  try {
    await api("POST", `/api/v1/roster/${activeSchedule.value.id}/assignments`, { org: org(), body });
    showAssignModal.value = false;
    await loadAssignments();
  } catch (e) { assignFormError.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Failed to save."; }
  finally { assignSaving.value = false; }
}

async function removeAssignment(assignmentId: number) {
  if (!activeSchedule.value || !confirm("Remove this shift assignment?")) return;
  await api("DELETE", `/api/v1/roster/${activeSchedule.value.id}/assignments/${assignmentId}`, { org: org() });
  await loadAssignments();
}

async function createSchedule() {
  if (!org()) return;
  schedSaving.value = true; schedFormError.value = "";
  try {
    await api("POST", "/api/v1/roster", { org: org(), body: schedForm.value });
    showSchedForm.value = false; schedForm.value = { name: "", start_date: "", end_date: "" };
    await loadSchedules();
  } catch (e) { schedFormError.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Failed to create."; }
  finally { schedSaving.value = false; }
}

async function publishSchedule() {
  if (!activeSchedule.value || !confirm("Publish this schedule? It cannot be edited after publishing.")) return;
  try {
    await api("POST", `/api/v1/roster/${activeSchedule.value.id}/publish`, { org: org() });
    await loadSchedules();
  } catch (e) { alert(e instanceof Error ? e.message : "Failed to publish."); }
}

function dayLabel(date: string): string {
  const d = new Date(date + "T00:00:00");
  return d.toLocaleDateString("en-GB", { weekday: "short", day: "numeric", month: "short" });
}

function isToday(date: string): boolean {
  return date === new Date().toISOString().slice(0, 10);
}

function assignTypeColor(type: string): string {
  if (type === "day_off") return "#E5E7EB";
  if (type === "leave")   return "#FEF3C7";
  return "#DBEAFE";
}
function assignTypeText(a: RosterAssignment): string {
  if (a.type === "day_off") return "Off";
  if (a.type === "leave")   return "Leave";
  return a.shift_type ? `${a.shift_type.name} (${a.shift_type.start_time}–${a.shift_type.end_time})` : "Shift";
}

// ─────────────────────────────────────────────────────────────────────────────
// SWAP REQUESTS
// ─────────────────────────────────────────────────────────────────────────────
const swaps        = ref<ShiftSwapRequest[]>([]);
const swapsLoading = ref(false);
const swapsError   = ref("");
const swapFilter   = ref<string>("");

async function loadSwaps() {
  if (!org()) return;
  swapsLoading.value = true; swapsError.value = "";
  try {
    const res = await api<Paginator<ShiftSwapRequest>>("GET", "/api/v1/roster/swaps", { org: org(), params: { per_page: 50, status: swapFilter.value || undefined } });
    swaps.value = res.data;
  } catch (e) { swapsError.value = e instanceof Error ? e.message : "Failed to load swaps."; }
  finally { swapsLoading.value = false; }
}

async function approveSwap(id: number) {
  if (!confirm("Approve this shift swap? The shifts will be exchanged immediately.")) return;
  try {
    await api("POST", `/api/v1/roster/swaps/${id}/approve`, { org: org(), body: {} });
    await loadSwaps();
  } catch (e) { alert(e instanceof Error ? e.message : "Failed to approve."); }
}

function swapStatusBadge(s: string): string {
  const map: Record<string, string> = { pending: "badge-yellow", accepted: "badge-blue", approved: "badge-green", rejected: "badge-red", cancelled: "badge-muted" };
  return map[s] ?? "badge-muted";
}

// ─────────────────────────────────────────────────────────────────────────────
// Lifecycle
// ─────────────────────────────────────────────────────────────────────────────
onMounted(() => {
  void loadConfig();
  void loadShiftTypes();
  void loadGroups();
  void loadSchedules();
});

watch(currentOrgSlug, () => {
  employeesLoaded.value = false;
  void loadConfig();
  void loadShiftTypes();
  void loadGroups();
  void loadSchedules();
});

watch(tab, (t) => {
  if (t === "swaps")       void loadSwaps();
  if (t === "shift-types") void loadShiftTypes();
  if (t === "shift-groups")void loadGroups();
  if (t === "config")      void loadConfig();
});

watch(swapFilter, () => { if (tab.value === "swaps") void loadSwaps(); });
</script>

<template>
  <div class="view">
    <!-- ── Header ──────────────────────────────────────────────────────────── -->
    <div class="view-header">
      <div>
        <h2>Roster</h2>
        <p class="view-subtitle">Schedule and manage staff duty rosters</p>
      </div>
      <span v-if="authStore.currentOrg" class="badge badge-blue">{{ authStore.currentOrg.name }}</span>
    </div>

    <!-- ── Tabs ───────────────────────────────────────────────────────────── -->
    <div class="tab-bar">
      <button :class="['tab-btn', { active: tab === 'schedule' }]"    @click="tab = 'schedule'">Schedule</button>
      <button :class="['tab-btn', { active: tab === 'shift-types' }]" @click="tab = 'shift-types'">Shift Types</button>
      <button :class="['tab-btn', { active: tab === 'shift-groups' }]"@click="tab = 'shift-groups'">Shift Groups</button>
      <button :class="['tab-btn', { active: tab === 'swaps' }]"       @click="tab = 'swaps'">Swap Requests</button>
      <button :class="['tab-btn', { active: tab === 'config' }]"       @click="tab = 'config'">Configuration</button>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════════
         TAB: SCHEDULE
    ═══════════════════════════════════════════════════════════════════════ -->
    <template v-if="tab === 'schedule'">

      <!-- Schedule selector bar -->
      <div class="roster-toolbar">
        <div class="roster-schedule-list">
          <button
            v-for="s in schedules"
            :key="s.id"
            :class="['schedule-pill', { active: activeSchedule?.id === s.id }]"
            @click="selectSchedule(s)"
          >
            {{ s.name }}
            <span :class="['badge', s.status === 'published' ? 'badge-green' : 'badge-muted']" style="margin-left:6px;font-size:10px;">{{ s.status }}</span>
          </button>
          <button class="btn btn-sm btn-outline" @click="showSchedForm = true">+ New Schedule</button>
        </div>

        <div v-if="activeSchedule" class="roster-actions">
          <button
            v-if="activeSchedule.status === 'draft'"
            class="btn btn-sm btn-primary"
            @click="publishSchedule"
          >Publish</button>
        </div>
      </div>

      <p v-if="schedulesLoading || assignLoading" class="status">Loading…</p>
      <p v-if="schedulesError || assignError" class="error">{{ schedulesError || assignError }}</p>

      <!-- Grid -->
      <template v-if="activeSchedule && !assignLoading">
        <div class="roster-week-nav">
          <div>
            <strong>{{ activeSchedule.name }}</strong>
            <span class="text-muted" style="margin-left:8px;font-size:12px;">{{ activeSchedule.start_date }} → {{ activeSchedule.end_date }}</span>
          </div>
          <div style="display:flex;gap:8px;align-items:center;">
            <button class="btn btn-sm btn-outline" :disabled="!canPrevWeek" @click="weekOffset--">‹ Prev</button>
            <span class="text-muted" style="font-size:12px;">Week {{ weekOffset + 1 }}</span>
            <button class="btn btn-sm btn-outline" :disabled="!canNextWeek" @click="weekOffset++">Next ›</button>
            <button class="btn btn-sm btn-primary" @click="openAssignModal(weekDates[0] ?? '')">+ Assign Shift</button>
          </div>
        </div>

        <div class="roster-grid-wrapper">
          <table class="roster-grid">
            <thead>
              <tr>
                <th class="roster-emp-col">Employee</th>
                <th class="roster-emp-col">Total Hours</th>
                <th
                  v-for="date in weekDates"
                  :key="date"
                  :class="['roster-day-col', { 'roster-today': isToday(date) }]"
                >{{ dayLabel(date) }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="rosterEmployees.length === 0">
                <td :colspan="weekDates.length + 2" style="text-align:center;padding:32px;color:var(--color-text-muted);">
                  No assignments yet. Click <strong>+ Assign Shift</strong> to get started.
                </td>
              </tr>
              <tr v-for="emp in rosterEmployees" :key="emp.id">
                <td class="roster-emp-cell">
                  <span class="emp-avatar">{{ emp.full_name[0] }}</span>
                  <span>{{ emp.full_name }}</span>
                </td>
                <td class="roster-hours-cell">{{ totalShiftHoursForEmployee(emp.id) }}</td>
                <td
                  v-for="date in weekDates"
                  :key="date"
                  class="roster-slot"
                  @click="() => { const a = assignMap[emp.id]?.[date]; if (!a) openAssignModal(date, emp.id); }"
                >
                  <template v-if="assignMap[emp.id]?.[date] as RosterAssignment | undefined">
                    <div
                      class="shift-chip"
                      :style="{ background: assignTypeColor(assignMap[emp.id][date].type) }"
                    >
                      <span class="shift-chip-label">{{ assignTypeText(assignMap[emp.id][date]) }}</span>
                      <button
                        class="shift-chip-del"
                        title="Remove"
                        @click.stop="removeAssignment(assignMap[emp.id][date].id)"
                      >×</button>
                    </div>
                  </template>
                  <template v-else>
                    <span class="roster-slot-empty" title="Click to assign">+</span>
                  </template>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Employees not yet on roster -->
        <div v-if="rosterEmployees.length === 0" style="margin-top:16px;">
          <p class="text-muted" style="font-size:13px;">You can also add employees via Bulk Assign in a future step.</p>
        </div>
      </template>

      <p v-if="!activeSchedule && !schedulesLoading" class="text-muted" style="margin-top:24px;">
        No schedules yet. Create one to begin rostering.
      </p>

      <!-- New Schedule Modal -->
      <div v-if="showSchedForm" class="modal-overlay" @click.self="showSchedForm = false">
        <div class="modal">
          <div class="modal-header">
            <h3>New Schedule</h3>
            <button class="modal-close" @click="showSchedForm = false">×</button>
          </div>
          <div class="modal-body">
            <p v-if="schedFormError" class="error">{{ schedFormError }}</p>
            <div class="form-group">
              <label>Schedule Name *</label>
              <input v-model="schedForm.name" class="form-input" placeholder="e.g. Week 18 — ICU" />
            </div>
            <div class="form-row">
              <div class="form-group">
                <label>Start Date *</label>
                <input v-model="schedForm.start_date" type="date" class="form-input" />
              </div>
              <div class="form-group">
                <label>End Date *</label>
                <input v-model="schedForm.end_date" type="date" class="form-input" />
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-outline" @click="showSchedForm = false">Cancel</button>
            <button class="btn btn-primary" :disabled="schedSaving" @click="createSchedule">
              {{ schedSaving ? "Saving…" : "Create Schedule" }}
            </button>
          </div>
        </div>
      </div>

      <!-- Assign Shift Modal -->
      <div v-if="showAssignModal" class="modal-overlay" @click.self="showAssignModal = false">
        <div class="modal">
          <div class="modal-header">
            <h3>Assign Shift</h3>
            <button class="modal-close" @click="showAssignModal = false">×</button>
          </div>
          <div class="modal-body">
            <p v-if="assignFormError" class="error">{{ assignFormError }}</p>
            <div class="form-group">
              <label>Employee *</label>
              <select v-model="assignForm.employee_id" class="form-input">
                <option value="">Select employee…</option>
                <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.full_name }} ({{ e.employee_number }})</option>
              </select>
            </div>
            <div class="form-group">
              <label>Date *</label>
              <input v-model="assignForm.assignment_date" type="date" class="form-input" />
            </div>
            <div class="form-group">
              <label>Type *</label>
              <select v-model="assignForm.type" class="form-input">
                <option value="shift">Shift</option>
                <option value="day_off">Day Off</option>
                <option value="leave">Leave</option>
              </select>
            </div>
            <div v-if="assignForm.type === 'shift'" class="form-group">
              <label>Shift Type</label>
              <select v-model="assignForm.shift_type_id" class="form-input">
                <option value="">— No specific shift type —</option>
                <option v-for="st in shiftTypes" :key="st.id" :value="st.id">
                  {{ st.name }} ({{ st.start_time }}–{{ st.end_time }})
                </option>
              </select>
            </div>
            <div class="form-group">
              <label>Notes</label>
              <textarea v-model="assignForm.notes" class="form-input" rows="2" placeholder="Optional note…"></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-outline" @click="showAssignModal = false">Cancel</button>
            <button class="btn btn-primary" :disabled="assignSaving" @click="saveAssignment">
              {{ assignSaving ? "Saving…" : "Assign" }}
            </button>
          </div>
        </div>
      </div>
    </template>

    <!-- ══════════════════════════════════════════════════════════════════════
         TAB: SHIFT TYPES
    ═══════════════════════════════════════════════════════════════════════ -->
    <template v-if="tab === 'shift-types'">
      <div class="section-toolbar">
        <div style="display:flex;gap:8px;">
          <input v-model="shiftSearch" class="form-input" placeholder="Search shift types…" style="width:220px;" @keyup.enter="loadShiftTypes" />
          <button class="btn btn-sm btn-outline" @click="loadShiftTypes">Search</button>
        </div>
        <button class="btn btn-primary" @click="openShiftForm()">+ Add Shift Type</button>
      </div>

      <p v-if="shiftTypesLoading" class="status">Loading…</p>
      <p v-if="shiftTypesError" class="error">{{ shiftTypesError }}</p>

      <div class="table-wrapper" v-if="!shiftTypesLoading">
        <table class="data-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Group</th>
              <th>Start</th>
              <th>End</th>
              <th>Duration</th>
              <th>Break</th>
              <th>Overnight</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="shiftTypes.length === 0">
              <td colspan="9" class="empty-row">No shift types configured yet.</td>
            </tr>
            <tr v-for="st in shiftTypes" :key="st.id">
              <td><strong>{{ st.name }}</strong></td>
              <td>{{ st.shift_group?.name ?? '—' }}</td>
              <td>{{ st.start_time }}</td>
              <td>{{ st.end_time }}</td>
              <td>{{ fmtDuration(st.duration_minutes) }}</td>
              <td>{{ st.break_start ? `${st.break_start} (${st.break_duration_minutes}m)` : '—' }}</td>
              <td><span :class="['badge', st.is_overnight ? 'badge-yellow' : 'badge-muted']">{{ st.is_overnight ? 'Yes' : 'No' }}</span></td>
              <td><span :class="['badge', st.is_active ? 'badge-green' : 'badge-muted']">{{ st.is_active ? 'Active' : 'Inactive' }}</span></td>
              <td class="actions-cell">
                <button class="btn-icon" title="Edit" @click="openShiftForm(st)">✏️</button>
                <button class="btn-icon" title="Delete" @click="deleteShift(st.id)">🗑️</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Shift Type Form Modal -->
      <div v-if="showShiftForm" class="modal-overlay" @click.self="showShiftForm = false">
        <div class="modal">
          <div class="modal-header">
            <h3>{{ editingShiftId ? 'Edit Shift Type' : 'Add Shift Type' }}</h3>
            <button class="modal-close" @click="showShiftForm = false">×</button>
          </div>
          <div class="modal-body">
            <p v-if="shiftFormError" class="error">{{ shiftFormError }}</p>
            <div class="form-group">
              <label>Shift Name *</label>
              <input v-model="shiftForm.name" class="form-input" placeholder="e.g. Morning Shift" />
            </div>
            <div class="form-group">
              <label>Shift Group</label>
              <select v-model="shiftForm.shift_group_id" class="form-input">
                <option value="">— None —</option>
                <option v-for="g in groups" :key="g.id" :value="g.id">{{ g.name }}</option>
              </select>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label>Start Time *</label>
                <input v-model="shiftForm.start_time" type="time" class="form-input" />
              </div>
              <div class="form-group">
                <label>End Time *</label>
                <input v-model="shiftForm.end_time" type="time" class="form-input" />
              </div>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label>Break Start</label>
                <input v-model="shiftForm.break_start" type="time" class="form-input" />
              </div>
              <div class="form-group">
                <label>Break Duration (minutes)</label>
                <input v-model.number="shiftForm.break_duration_minutes" type="number" min="0" max="480" class="form-input" />
              </div>
            </div>
            <div class="form-group">
              <label class="checkbox-label">
                <input v-model="shiftForm.is_active" type="checkbox" />
                Active
              </label>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-outline" @click="showShiftForm = false">Cancel</button>
            <button class="btn btn-primary" :disabled="shiftSaving" @click="saveShift">
              {{ shiftSaving ? "Saving…" : "Save" }}
            </button>
          </div>
        </div>
      </div>
    </template>

    <!-- ══════════════════════════════════════════════════════════════════════
         TAB: SHIFT GROUPS
    ═══════════════════════════════════════════════════════════════════════ -->
    <template v-if="tab === 'shift-groups'">
      <div class="section-toolbar">
        <span></span>
        <button class="btn btn-primary" @click="openGroupForm()">+ Add Shift Group</button>
      </div>

      <p v-if="groupsLoading" class="status">Loading…</p>
      <p v-if="groupsError" class="error">{{ groupsError }}</p>

      <div class="table-wrapper" v-if="!groupsLoading">
        <table class="data-table">
          <thead>
            <tr><th>Name</th><th>Description</th><th>Created</th><th></th></tr>
          </thead>
          <tbody>
            <tr v-if="groups.length === 0">
              <td colspan="4" class="empty-row">No shift groups yet.</td>
            </tr>
            <tr v-for="g in groups" :key="g.id">
              <td><strong>{{ g.name }}</strong></td>
              <td>{{ g.description ?? '—' }}</td>
              <td>{{ new Date(g.created_at).toLocaleDateString() }}</td>
              <td class="actions-cell">
                <button class="btn-icon" title="Edit" @click="openGroupForm(g)">✏️</button>
                <button class="btn-icon" title="Delete" @click="deleteGroup(g.id)">🗑️</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Shift Group Modal -->
      <div v-if="showGroupForm" class="modal-overlay" @click.self="showGroupForm = false">
        <div class="modal">
          <div class="modal-header">
            <h3>{{ editingGroupId ? 'Edit Shift Group' : 'Add Shift Group' }}</h3>
            <button class="modal-close" @click="showGroupForm = false">×</button>
          </div>
          <div class="modal-body">
            <p v-if="groupFormError" class="error">{{ groupFormError }}</p>
            <div class="form-group">
              <label>Group Name *</label>
              <input v-model="groupForm.name" class="form-input" placeholder="e.g. Clinical, Operations" />
            </div>
            <div class="form-group">
              <label>Description</label>
              <textarea v-model="groupForm.description" class="form-input" rows="2"></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-outline" @click="showGroupForm = false">Cancel</button>
            <button class="btn btn-primary" :disabled="groupSaving" @click="saveGroup">
              {{ groupSaving ? "Saving…" : "Save" }}
            </button>
          </div>
        </div>
      </div>
    </template>

    <!-- ══════════════════════════════════════════════════════════════════════
         TAB: SWAP REQUESTS
    ═══════════════════════════════════════════════════════════════════════ -->
    <template v-if="tab === 'swaps'">
      <div class="section-toolbar">
        <select v-model="swapFilter" class="form-input" style="width:160px;">
          <option value="">All Statuses</option>
          <option value="pending">Pending</option>
          <option value="accepted">Accepted</option>
          <option value="approved">Approved</option>
          <option value="rejected">Rejected</option>
          <option value="cancelled">Cancelled</option>
        </select>
      </div>

      <p v-if="swapsLoading" class="status">Loading…</p>
      <p v-if="swapsError" class="error">{{ swapsError }}</p>

      <div class="table-wrapper" v-if="!swapsLoading">
        <table class="data-table">
          <thead>
            <tr>
              <th>Requester</th>
              <th>Their Shift</th>
              <th>Co-worker</th>
              <th>Co-worker's Shift</th>
              <th>Status</th>
              <th>Requested</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="swaps.length === 0">
              <td colspan="7" class="empty-row">No swap requests.</td>
            </tr>
            <tr v-for="sw in swaps" :key="sw.id">
              <td>{{ sw.requester?.full_name ?? '—' }}</td>
              <td>
                <span v-if="sw.requester_assignment">
                  {{ sw.requester_assignment.shift_name ?? 'Shift' }} · {{ sw.requester_assignment.assignment_date }}
                </span>
                <span v-else>—</span>
              </td>
              <td>{{ sw.requested_employee?.full_name ?? '—' }}</td>
              <td>
                <span v-if="sw.requested_assignment">
                  {{ sw.requested_assignment.shift_name ?? 'Shift' }} · {{ sw.requested_assignment.assignment_date }}
                </span>
                <span v-else>—</span>
              </td>
              <td><span :class="['badge', swapStatusBadge(sw.status)]">{{ sw.status }}</span></td>
              <td>{{ new Date(sw.created_at).toLocaleDateString() }}</td>
              <td class="actions-cell">
                <button
                  v-if="sw.status === 'accepted'"
                  class="btn btn-sm btn-primary"
                  @click="approveSwap(sw.id)"
                >Approve</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- ══════════════════════════════════════════════════════════════════════
         TAB: CONFIGURATION
    ═══════════════════════════════════════════════════════════════════════ -->
    <template v-if="tab === 'config'">
      <div class="config-card">
        <h3 class="config-section-title">General Configuration</h3>

        <p v-if="configLoading" class="status">Loading…</p>
        <p v-if="configError"   class="error">{{ configError }}</p>
        <p v-if="configSuccess" class="success">{{ configSuccess }}</p>

        <template v-if="!configLoading">
          <div class="form-group" style="max-width:400px;">
            <label>Primary Detail Shown with Employee Name</label>
            <p class="field-hint">Controls which hours figure appears next to each employee on the roster grid.</p>
            <select v-model="configForm.primary_detail_shown" class="form-input">
              <option value="total_shift_hours">Total Shift Hours</option>
              <option value="allocated_hours">Allocated Hours (includes leave)</option>
            </select>
          </div>

          <div class="config-toggle-group">
            <div class="config-toggle-row">
              <div>
                <p class="toggle-label">Swap Shifts</p>
                <p class="toggle-desc">Allow employees to initiate shift swap requests with co-workers.</p>
              </div>
              <label class="toggle-switch">
                <input type="checkbox" v-model="configForm.swap_shifts_enabled" />
                <span class="toggle-track"><span class="toggle-thumb"></span></span>
              </label>
            </div>

            <div class="config-toggle-row">
              <div>
                <p class="toggle-label">Manager Approval for Shift Swaps</p>
                <p class="toggle-desc">Require a manager to approve all accepted swap requests before they take effect.</p>
              </div>
              <label class="toggle-switch">
                <input type="checkbox" v-model="configForm.manager_approval_required" :disabled="!configForm.swap_shifts_enabled" />
                <span class="toggle-track" :class="{ disabled: !configForm.swap_shifts_enabled }"><span class="toggle-thumb"></span></span>
              </label>
            </div>
          </div>

          <div style="margin-top:24px;">
            <button class="btn btn-primary" :disabled="configSaving" @click="saveConfig">
              {{ configSaving ? "Saving…" : "Save Configuration" }}
            </button>
          </div>
        </template>
      </div>
    </template>
  </div>
</template>

<style scoped>
/* ── Tabs ──────────────────────────────────────────────────────────────── */
.tab-bar {
  display: flex;
  gap: 4px;
  border-bottom: 2px solid var(--color-border);
  margin-bottom: 24px;
}
.tab-btn {
  padding: 8px 16px;
  border: none;
  background: none;
  cursor: pointer;
  font-size: 13px;
  font-weight: 500;
  color: var(--color-text-muted);
  border-bottom: 2px solid transparent;
  margin-bottom: -2px;
  transition: color 0.15s, border-color 0.15s;
}
.tab-btn:hover { color: var(--color-text); }
.tab-btn.active { color: var(--color-brand-base); border-bottom-color: var(--color-brand-base); }

/* ── Toolbar ───────────────────────────────────────────────────────────── */
.roster-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 16px;
}
.roster-schedule-list {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.schedule-pill {
  padding: 4px 12px;
  border-radius: 20px;
  border: 1px solid var(--color-border);
  background: var(--color-surface);
  cursor: pointer;
  font-size: 13px;
  color: var(--color-text-muted);
  transition: all 0.15s;
  display: flex;
  align-items: center;
}
.schedule-pill:hover { background: var(--color-neutral-100); }
.schedule-pill.active { border-color: var(--color-brand-base); color: var(--color-brand-base); background: var(--color-brand-light); }

.section-toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  margin-bottom: 16px;
}

/* ── Roster week nav ───────────────────────────────────────────────────── */
.roster-week-nav {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
}

/* ── Roster Grid ───────────────────────────────────────────────────────── */
.roster-grid-wrapper {
  overflow-x: auto;
  border: 1px solid var(--color-border);
  border-radius: 8px;
}
.roster-grid {
  width: 100%;
  border-collapse: collapse;
  background: var(--color-surface);
  min-width: 800px;
}
.roster-grid thead tr {
  background: var(--color-neutral-100);
}
.roster-grid th, .roster-grid td {
  padding: 8px 10px;
  border: 1px solid var(--color-border);
  font-size: 12px;
}
.roster-grid th { font-weight: 600; text-align: center; }
.roster-emp-col { min-width: 160px; text-align: left !important; }
.roster-hours-cell { text-align: center; font-size: 12px; color: var(--color-text-muted); }
.roster-day-col { min-width: 130px; }
.roster-today { background: var(--color-brand-light) !important; }
.roster-emp-cell {
  display: flex;
  align-items: center;
  gap: 8px;
  font-weight: 500;
}
.emp-avatar {
  width: 26px;
  height: 26px;
  border-radius: 50%;
  background: var(--color-brand-subtle);
  color: var(--color-brand-base);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 11px;
  font-weight: 700;
  flex-shrink: 0;
}
.roster-slot {
  text-align: center;
  cursor: pointer;
  vertical-align: middle;
  min-height: 40px;
}
.roster-slot:hover { background: var(--color-neutral-50); }
.roster-slot-empty {
  color: var(--color-neutral-300);
  font-size: 18px;
  line-height: 1;
}
.shift-chip {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 4px;
  border-radius: 4px;
  padding: 3px 6px;
  font-size: 11px;
  font-weight: 500;
}
.shift-chip-label { flex: 1; text-align: left; }
.shift-chip-del {
  background: none;
  border: none;
  cursor: pointer;
  color: var(--color-text-muted);
  font-size: 14px;
  line-height: 1;
  padding: 0 2px;
}
.shift-chip-del:hover { color: var(--color-error); }

/* ── Config card ───────────────────────────────────────────────────────── */
.config-card {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: 10px;
  padding: 28px 32px;
  max-width: 600px;
}
.config-section-title {
  font-size: 15px;
  font-weight: 600;
  margin-bottom: 20px;
  color: var(--color-text);
}
.field-hint {
  font-size: 12px;
  color: var(--color-text-muted);
  margin-bottom: 6px;
}
.config-toggle-group {
  display: flex;
  flex-direction: column;
  gap: 20px;
  margin-top: 24px;
}
.config-toggle-row {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  padding: 16px 0;
  border-top: 1px solid var(--color-border);
}
.toggle-label {
  font-weight: 500;
  font-size: 13px;
  margin-bottom: 4px;
}
.toggle-desc {
  font-size: 12px;
  color: var(--color-text-muted);
  max-width: 380px;
}

/* Toggle switch */
.toggle-switch { display: flex; align-items: center; cursor: pointer; flex-shrink: 0; }
.toggle-switch input { position: absolute; opacity: 0; width: 0; height: 0; }
.toggle-track {
  width: 40px; height: 22px;
  background: var(--color-neutral-300);
  border-radius: 11px;
  position: relative;
  transition: background 0.2s;
}
.toggle-track.disabled { opacity: 0.4; cursor: not-allowed; }
.toggle-switch input:checked + .toggle-track { background: var(--color-brand-base); }
.toggle-thumb {
  position: absolute;
  top: 3px; left: 3px;
  width: 16px; height: 16px;
  background: white;
  border-radius: 50%;
  transition: transform 0.2s;
  box-shadow: 0 1px 3px rgba(0,0,0,.2);
}
.toggle-switch input:checked + .toggle-track .toggle-thumb { transform: translateX(18px); }

/* ── Shared utilities ──────────────────────────────────────────────────── */
.view-subtitle { font-size: 13px; color: var(--color-text-muted); margin-top: 2px; }
.text-muted { color: var(--color-text-muted); }
.status  { color: var(--color-text-muted); margin: 16px 0; font-size: 13px; }
.error   { color: var(--color-error); font-size: 13px; margin: 8px 0; }
.success { color: var(--color-success); font-size: 13px; margin: 8px 0; }
.empty-row { text-align: center; color: var(--color-text-muted); padding: 24px; }
.actions-cell { white-space: nowrap; }
.btn-icon { background: none; border: none; cursor: pointer; padding: 2px 4px; font-size: 14px; }

/* badge extras */
.badge-muted { background: #F3F4F6; color: #6B7280; }
.badge-red   { background: #FEE2E2; color: var(--color-error); }

.checkbox-label { display: flex; align-items: center; gap: 8px; font-size: 13px; cursor: pointer; }
.checkbox-label input { width: 15px; height: 15px; }
</style>
