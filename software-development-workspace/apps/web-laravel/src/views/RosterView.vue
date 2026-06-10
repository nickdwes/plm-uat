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
  HandoverReport,
} from "../lib/types";

// ── Tab state ─────────────────────────────────────────────────────────────
type Tab = "schedule" | "reports" | "handover" | "shift-types" | "shift-groups" | "swaps" | "config";
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
const shiftForm        = ref({ name: "", shift_group_id: "" as number | "", start_time: "", end_time: "", break_start: "", break_duration_minutes: 0, is_active: true, color: "#3B82F6" });
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
    const toHHMM = (t: string) => t ? t.slice(0, 5) : "";
    shiftForm.value = { name: s.name, shift_group_id: s.shift_group?.id ?? "", start_time: toHHMM(s.start_time), end_time: toHHMM(s.end_time), break_start: toHHMM(s.break_start ?? ""), break_duration_minutes: s.break_duration_minutes, is_active: s.is_active, color: s.color ?? "#3B82F6" };
  } else {
    editingShiftId.value = null;
    shiftForm.value = { name: "", shift_group_id: "", start_time: "", end_time: "", break_start: "", break_duration_minutes: 0, is_active: true, color: "#3B82F6" };
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

const showSchedForm    = ref(false);
const schedForm        = ref({ name: "", start_date: "", end_date: "" });
const schedSaving      = ref(false);
const schedFormError   = ref("");

const assignments      = ref<RosterAssignment[]>([]);
const assignLoading    = ref(false);
const assignError      = ref("");

const employees        = ref<Employee[]>([]);
const employeesLoaded  = ref(false);

const showAssignModal  = ref(false);
const assignForm       = ref({ employee_id: "" as number | "", shift_type_id: "" as number | "", assignment_date: "", type: "shift" as "shift" | "day_off" | "leave", notes: "" });
const assignSaving     = ref(false);
const assignFormError  = ref("");

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

const assignMap = computed<Record<number, Record<string, RosterAssignment>>>(() => {
  const map: Record<number, Record<string, RosterAssignment>> = {};
  for (const a of assignments.value) {
    if (!a.employee) continue;
    if (!map[a.employee.id]) map[a.employee.id] = {};
    map[a.employee.id][a.assignment_date] = a;
  }
  return map;
});

// Distinct employees sorted alpha
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

// Group employees by their dominant shift group (for team header rows in grid)
const rosterByGroup = computed(() => {
  const empGroup = new Map<number, { id: number | null; name: string }>();
  for (const a of assignments.value) {
    if (!a.employee || empGroup.has(a.employee.id)) continue;
    const sg = (a.shift_type as any)?.shift_group;
    empGroup.set(a.employee.id, sg ? { id: sg.id, name: sg.name } : { id: null, name: "General" });
  }
  const teamMap = new Map<string, { name: string; employees: typeof rosterEmployees.value }>();
  for (const emp of rosterEmployees.value) {
    const g   = empGroup.get(emp.id) ?? { id: null, name: "General" };
    const key = g.id !== null ? `g_${g.id}` : "general";
    if (!teamMap.has(key)) teamMap.set(key, { name: g.name, employees: [] });
    teamMap.get(key)!.employees.push(emp);
  }
  return Array.from(teamMap.values()).sort((a, b) => {
    if (a.name === "General" && b.name !== "General") return 1;
    if (b.name === "General" && a.name !== "General") return -1;
    return a.name.localeCompare(b.name);
  });
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

// Shift types actually used in this schedule (for legend)
const usedShiftTypes = computed(() => {
  const map = new Map<number, { name: string; color: string }>();
  for (const a of assignments.value) {
    if (a.type === "shift" && a.shift_type && !map.has(a.shift_type.id)) {
      map.set(a.shift_type.id, { name: a.shift_type.name, color: (a.shift_type as any).color ?? "#3B82F6" });
    }
  }
  return Array.from(map.values());
});

// Cell styling helpers
function cellBg(empId: number, date: string): Record<string, string> {
  const a = assignMap.value[empId]?.[date];
  if (!a) return {};
  if (a.type === "day_off") return { background: "#FACC15", color: "#78350F" };
  if (a.type === "leave")   return { background: "#9CA3AF", color: "#1F2937" };
  const color = (a.shift_type as any)?.color ?? "#3B82F6";
  return { background: color, color: contrastColor(color) };
}

function cellLabel(empId: number, date: string): string {
  const a = assignMap.value[empId]?.[date];
  if (!a) return "";
  if (a.type === "day_off") return "OFF";
  if (a.type === "leave")   return "LEAVE";
  return a.shift_type?.name ?? "Shift";
}

function cellSubLabel(empId: number, date: string): string {
  const a = assignMap.value[empId]?.[date];
  if (!a || a.type !== "shift" || !a.shift_type) return "";
  return `${a.shift_type.start_time}–${a.shift_type.end_time}`;
}

function contrastColor(hex: string): string {
  try {
    const r = parseInt(hex.slice(1, 3), 16);
    const g = parseInt(hex.slice(3, 5), 16);
    const b = parseInt(hex.slice(5, 7), 16);
    return (0.299 * r + 0.587 * g + 0.114 * b) / 255 > 0.55 ? "#1F2937" : "#FFFFFF";
  } catch { return "#FFFFFF"; }
}

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

// ─────────────────────────────────────────────────────────────────────────────
// REPORTS
// ─────────────────────────────────────────────────────────────────────────────
type ReportSubTab = "coverage" | "my-schedule";
const reportSubTab = ref<ReportSubTab>("coverage");

// Coverage: per-date count of employees by assignment type/shift
const coverageDates = computed(() => {
  if (!activeSchedule.value) return [];
  const start = new Date(activeSchedule.value.start_date + "T00:00:00");
  const end   = new Date(activeSchedule.value.end_date   + "T00:00:00");
  const dates: string[] = [];
  for (let d = new Date(start); d <= end; d.setDate(d.getDate() + 1)) {
    dates.push(d.toISOString().slice(0, 10));
  }
  return dates;
});

// Build coverage: date → { shiftTypeId → count, off → count, leave → count }
const coverageMap = computed(() => {
  const map: Record<string, Record<string, number>> = {};
  for (const a of assignments.value) {
    const date = a.assignment_date;
    if (!map[date]) map[date] = {};
    const key = a.type === "shift" && a.shift_type ? `st_${a.shift_type.id}` : a.type;
    map[date][key] = (map[date][key] ?? 0) + 1;
  }
  return map;
});

// Unique shift types used across full schedule (for coverage table header)
const coverageShiftTypes = computed(() => {
  const map = new Map<number, { id: number; name: string; color: string }>();
  for (const a of assignments.value) {
    if (a.type === "shift" && a.shift_type && !map.has(a.shift_type.id)) {
      map.set(a.shift_type.id, { id: a.shift_type.id, name: a.shift_type.name, color: (a.shift_type as any).color ?? "#3B82F6" });
    }
  }
  return Array.from(map.values());
});

// Employee hours summary for reports
const empHoursSummary = computed(() => {
  const byEmp = new Map<number, { emp: typeof rosterEmployees.value[0]; shifts: number; offDays: number; leaveDays: number; totalMins: number }>();
  for (const a of assignments.value) {
    if (!a.employee) continue;
    const id = a.employee.id;
    if (!byEmp.has(id)) byEmp.set(id, { emp: a.employee, shifts: 0, offDays: 0, leaveDays: 0, totalMins: 0 });
    const row = byEmp.get(id)!;
    if (a.type === "shift")   { row.shifts++; row.totalMins += a.shift_type?.duration_minutes ?? 0; }
    if (a.type === "day_off") row.offDays++;
    if (a.type === "leave")   row.leaveDays++;
  }
  return Array.from(byEmp.values()).sort((a, b) => a.emp.full_name.localeCompare(b.emp.full_name));
});

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
// HANDOVER REPORTS
// ─────────────────────────────────────────────────────────────────────────────
const handovers        = ref<HandoverReport[]>([]);
const handoversLoading = ref(false);
const handoversError   = ref("");
const showHandoverForm = ref(false);
const handoverSaving   = ref(false);
const handoverError    = ref("");
const expandedHandover = ref<number | null>(null);

const handoverForm = ref({
  schedule_id:          null as number | null,
  shift_type_id:        "" as number | "",
  outgoing_employee_id: "" as number | "",
  incoming_employee_id: "" as number | "",
  report_date:          new Date().toISOString().slice(0, 10),
  shift_start:          "",
  shift_end:            "",
  tasks_completed:      "",
  ongoing_issues:       "",
  tasks_pending:        "",
  equipment_notes:      "",
  general_notes:        "",
});

async function loadHandovers() {
  if (!org()) return;
  handoversLoading.value = true; handoversError.value = "";
  try {
    const params: Record<string, any> = { per_page: 30 };
    if (activeSchedule.value) params.schedule_id = activeSchedule.value.id;
    const res = await api<{ data: HandoverReport[] }>("GET", "/api/v1/roster/handovers", { org: org(), params });
    handovers.value = res.data;
  } catch (e) { handoversError.value = e instanceof Error ? e.message : "Failed to load handover reports."; }
  finally { handoversLoading.value = false; }
}

function openHandoverForm() {
  handoverForm.value.schedule_id = activeSchedule.value?.id ?? null;
  handoverForm.value.report_date = new Date().toISOString().slice(0, 10);
  handoverError.value = "";
  showHandoverForm.value = true;
  void loadEmployees();
}

async function saveHandover() {
  handoverSaving.value = true; handoverError.value = "";
  const body = {
    ...handoverForm.value,
    shift_type_id:        handoverForm.value.shift_type_id        || null,
    outgoing_employee_id: handoverForm.value.outgoing_employee_id || null,
    incoming_employee_id: handoverForm.value.incoming_employee_id || null,
    shift_start:          handoverForm.value.shift_start || null,
    shift_end:            handoverForm.value.shift_end   || null,
  };
  try {
    const res = await api<{ data: HandoverReport }>("POST", "/api/v1/roster/handovers", { org: org(), body });
    handovers.value.unshift((res as any).data);
    showHandoverForm.value = false;
  } catch (e) { handoverError.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Failed to save."; }
  finally { handoverSaving.value = false; }
}

async function acknowledgeHandover(id: number) {
  if (!confirm("Acknowledge this handover report? This confirms you have received the shift briefing.")) return;
  try {
    const res = await api<{ data: HandoverReport }>("POST", `/api/v1/roster/handovers/${id}/acknowledge`, { org: org(), body: {} });
    const idx = handovers.value.findIndex(h => h.id === id);
    if (idx >= 0) handovers.value[idx] = (res as any).data;
  } catch (e) { alert(e instanceof Error ? e.message : "Failed to acknowledge."); }
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
  if (t === "swaps")        void loadSwaps();
  if (t === "shift-types")  void loadShiftTypes();
  if (t === "shift-groups") void loadGroups();
  if (t === "config")       void loadConfig();
  if (t === "handover")     void loadHandovers();
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
      <button :class="['tab-btn', { active: tab === 'schedule'     }]" @click="tab = 'schedule'">Schedule</button>
      <button :class="['tab-btn', { active: tab === 'reports'      }]" @click="tab = 'reports'">Reports</button>
      <button :class="['tab-btn', { active: tab === 'handover'     }]" @click="tab = 'handover'">Handover</button>
      <button :class="['tab-btn', { active: tab === 'shift-types'  }]" @click="tab = 'shift-types'">Shift Types</button>
      <button :class="['tab-btn', { active: tab === 'shift-groups' }]" @click="tab = 'shift-groups'">Shift Groups</button>
      <button :class="['tab-btn', { active: tab === 'swaps'        }]" @click="tab = 'swaps'">Swap Requests</button>
      <button :class="['tab-btn', { active: tab === 'config'       }]" @click="tab = 'config'">Configuration</button>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════════
         TAB: SCHEDULE
    ═══════════════════════════════════════════════════════════════════════ -->
    <template v-if="tab === 'schedule'">
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
          <button v-if="activeSchedule.status === 'draft'" class="btn btn-sm btn-primary" @click="publishSchedule">Publish</button>
        </div>
      </div>

      <p v-if="schedulesLoading || assignLoading" class="status">Loading…</p>
      <p v-if="schedulesError || assignError" class="error">{{ schedulesError || assignError }}</p>

      <template v-if="activeSchedule && !assignLoading">
        <!-- Week navigation -->
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

        <!-- ── Excel-style roster grid ─────────────────────────────────────── -->
        <div class="roster-grid-wrapper">
          <table class="roster-grid">
            <thead>
              <tr>
                <th class="col-emp">Employee</th>
                <th class="col-hrs">Total Hrs</th>
                <th
                  v-for="date in weekDates"
                  :key="date"
                  :class="['col-day', { 'col-today': isToday(date) }]"
                >{{ dayLabel(date) }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="rosterEmployees.length === 0">
                <td :colspan="weekDates.length + 2" class="grid-empty">
                  No assignments yet. Click <strong>+ Assign Shift</strong> to get started.
                </td>
              </tr>

              <!-- Grouped by team/shift-group -->
              <template v-for="team in rosterByGroup" :key="team.name">
                <!-- Team header row -->
                <tr v-if="rosterByGroup.length > 1" class="team-header-row">
                  <td :colspan="weekDates.length + 2" class="team-header-cell">{{ team.name }}</td>
                </tr>
                <!-- Employee rows -->
                <tr v-for="emp in team.employees" :key="emp.id" class="emp-row">
                  <td class="cell-emp">
                    <span class="emp-avatar">{{ emp.full_name[0] }}</span>
                    <span class="emp-name">{{ emp.full_name }}</span>
                  </td>
                  <td class="cell-hrs">{{ totalShiftHoursForEmployee(emp.id) }}</td>
                  <td
                    v-for="date in weekDates"
                    :key="date"
                    class="cell-slot"
                    :style="cellBg(emp.id, date)"
                    @click="() => { const a = assignMap[emp.id]?.[date]; if (!a) openAssignModal(date, emp.id); }"
                  >
                    <template v-if="assignMap[emp.id]?.[date]">
                      <div class="cell-inner">
                        <span class="cell-main-label">{{ cellLabel(emp.id, date) }}</span>
                        <span class="cell-sub-label" v-if="cellSubLabel(emp.id, date)">{{ cellSubLabel(emp.id, date) }}</span>
                        <button
                          v-if="activeSchedule.status === 'draft'"
                          class="cell-del"
                          @click.stop="removeAssignment(assignMap[emp.id][date].id)"
                          title="Remove"
                        >×</button>
                      </div>
                    </template>
                    <span v-else class="cell-empty" title="Click to assign">+</span>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>

        <!-- Shift type color legend -->
        <div v-if="usedShiftTypes.length" class="shift-legend">
          <span class="legend-label">Legend:</span>
          <div class="legend-items">
            <span v-for="st in usedShiftTypes" :key="st.name" class="legend-chip" :style="{ background: st.color, color: contrastColor(st.color) }">{{ st.name }}</span>
            <span class="legend-chip" style="background:#FACC15;color:#78350F;">OFF</span>
            <span class="legend-chip" style="background:#9CA3AF;color:#1F2937;">LEAVE</span>
          </div>
        </div>
      </template>

      <p v-if="!activeSchedule && !schedulesLoading" class="text-muted" style="margin-top:24px;">
        No schedules yet. Create one to begin rostering.
      </p>

      <!-- New Schedule Modal -->
      <div v-if="showSchedForm" class="modal-overlay" @click.self="showSchedForm = false">
        <div class="modal">
          <div class="modal-header"><h3>New Schedule</h3><button class="modal-close" @click="showSchedForm = false">×</button></div>
          <div class="modal-body">
            <p v-if="schedFormError" class="error">{{ schedFormError }}</p>
            <div class="form-group">
              <label>Schedule Name *</label>
              <input v-model="schedForm.name" class="form-input" placeholder="e.g. June 2026 — SOC" />
            </div>
            <div class="form-row">
              <div class="form-group"><label>Start Date *</label><input v-model="schedForm.start_date" type="date" class="form-input" /></div>
              <div class="form-group"><label>End Date *</label><input v-model="schedForm.end_date" type="date" class="form-input" /></div>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-outline" @click="showSchedForm = false">Cancel</button>
            <button class="btn btn-primary" :disabled="schedSaving" @click="createSchedule">{{ schedSaving ? "Saving…" : "Create Schedule" }}</button>
          </div>
        </div>
      </div>

      <!-- Assign Shift Modal -->
      <div v-if="showAssignModal" class="modal-overlay" @click.self="showAssignModal = false">
        <div class="modal">
          <div class="modal-header"><h3>Assign Shift</h3><button class="modal-close" @click="showAssignModal = false">×</button></div>
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
                <option v-for="st in shiftTypes.filter(s => s.is_active)" :key="st.id" :value="st.id">
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
            <button class="btn btn-primary" :disabled="assignSaving" @click="saveAssignment">{{ assignSaving ? "Saving…" : "Assign" }}</button>
          </div>
        </div>
      </div>
    </template>

    <!-- ══════════════════════════════════════════════════════════════════════
         TAB: REPORTS
    ═══════════════════════════════════════════════════════════════════════ -->
    <template v-if="tab === 'reports'">
      <div class="report-sub-tabs">
        <button :class="['sub-tab-btn', { active: reportSubTab === 'coverage' }]"    @click="reportSubTab = 'coverage'">Coverage Summary</button>
        <button :class="['sub-tab-btn', { active: reportSubTab === 'my-schedule' }]" @click="reportSubTab = 'my-schedule'">My Schedule</button>
      </div>

      <!-- No active schedule notice -->
      <div v-if="!activeSchedule" class="report-empty">
        <p>Select a schedule on the Schedule tab first.</p>
      </div>

      <!-- ── Coverage Summary ─────────────────────────────────────────────── -->
      <template v-if="activeSchedule && reportSubTab === 'coverage'">
        <!-- Daily coverage table -->
        <div class="report-section">
          <h3 class="report-section-title">Daily Coverage — {{ activeSchedule.name }}</h3>
          <div class="table-wrapper">
            <table class="data-table coverage-table">
              <thead>
                <tr>
                  <th>Date</th>
                  <th v-for="st in coverageShiftTypes" :key="st.id">
                    <span class="cov-type-badge" :style="{ background: st.color, color: contrastColor(st.color) }">{{ st.name }}</span>
                  </th>
                  <th><span class="cov-type-badge" style="background:#FACC15;color:#78350F;">OFF</span></th>
                  <th><span class="cov-type-badge" style="background:#9CA3AF;color:#1F2937;">LEAVE</span></th>
                  <th>Total Staff</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="date in coverageDates" :key="date" :class="{ 'row-today': isToday(date) }">
                  <td>
                    <strong>{{ new Date(date + 'T00:00:00').toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' }) }}</strong>
                  </td>
                  <td v-for="st in coverageShiftTypes" :key="st.id" class="cov-count-cell">
                    <span v-if="coverageMap[date]?.[`st_${st.id}`]" class="cov-count" :style="{ background: st.color + '22', color: st.color }">
                      {{ coverageMap[date][`st_${st.id}`] }}
                    </span>
                    <span v-else class="cov-zero">—</span>
                  </td>
                  <td class="cov-count-cell">
                    <span v-if="coverageMap[date]?.day_off" class="cov-count" style="background:#fef9c3;color:#78350F;">{{ coverageMap[date].day_off }}</span>
                    <span v-else class="cov-zero">—</span>
                  </td>
                  <td class="cov-count-cell">
                    <span v-if="coverageMap[date]?.leave" class="cov-count" style="background:#f3f4f6;color:#374151;">{{ coverageMap[date].leave }}</span>
                    <span v-else class="cov-zero">—</span>
                  </td>
                  <td class="cov-count-cell">
                    <strong>{{ Object.values(coverageMap[date] ?? {}).reduce((s, v) => s + v, 0) }}</strong>
                  </td>
                </tr>
                <tr v-if="coverageDates.length === 0">
                  <td :colspan="coverageShiftTypes.length + 4" class="empty-row">No dates in range.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Employee hours summary -->
        <div class="report-section" style="margin-top:24px;">
          <h3 class="report-section-title">Employee Hours Summary</h3>
          <div class="table-wrapper">
            <table class="data-table">
              <thead>
                <tr><th>Employee</th><th>Shift Days</th><th>Day-Off Days</th><th>Leave Days</th><th>Total Shift Hours</th></tr>
              </thead>
              <tbody>
                <tr v-for="row in empHoursSummary" :key="row.emp.id">
                  <td><strong>{{ row.emp.full_name }}</strong></td>
                  <td>{{ row.shifts }}</td>
                  <td>{{ row.offDays }}</td>
                  <td>{{ row.leaveDays }}</td>
                  <td><strong>{{ fmtDuration(row.totalMins) }}</strong></td>
                </tr>
                <tr v-if="empHoursSummary.length === 0">
                  <td colspan="5" class="empty-row">No assignments in this schedule.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </template>

      <!-- ── My Schedule ──────────────────────────────────────────────────── -->
      <template v-if="activeSchedule && reportSubTab === 'my-schedule'">
        <div class="report-section">
          <h3 class="report-section-title">My Assignments — {{ activeSchedule.name }}</h3>
          <div class="my-schedule-grid">
            <div
              v-for="a in assignments"
              :key="a.id"
              class="my-sched-card"
              :style="a.type === 'shift' && a.shift_type ? { borderLeftColor: (a.shift_type as any).color ?? '#3B82F6' } : a.type === 'day_off' ? { borderLeftColor: '#FACC15' } : { borderLeftColor: '#9CA3AF' }"
            >
              <div class="my-sched-date">{{ new Date(a.assignment_date + 'T00:00:00').toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long' }) }}</div>
              <div class="my-sched-type"
                :style="a.type === 'shift' && a.shift_type ? { color: (a.shift_type as any).color ?? '#3B82F6' } : {}"
              >
                {{ a.type === 'day_off' ? 'Day Off' : a.type === 'leave' ? 'Leave' : a.shift_type?.name ?? 'Shift' }}
              </div>
              <div v-if="a.type === 'shift' && a.shift_type" class="my-sched-time">
                {{ a.shift_type.start_time }} — {{ a.shift_type.end_time }}
                <span v-if="a.shift_type.is_overnight" class="overnight-badge">Overnight</span>
              </div>
              <div v-if="a.notes" class="my-sched-note">{{ a.notes }}</div>
            </div>
            <div v-if="assignments.length === 0" class="report-empty">No assignments found.</div>
          </div>
        </div>
      </template>
    </template>

    <!-- ══════════════════════════════════════════════════════════════════════
         TAB: HANDOVER
    ═══════════════════════════════════════════════════════════════════════ -->
    <template v-if="tab === 'handover'">
      <div class="section-toolbar">
        <div>
          <h3 style="margin:0;font-size:15px;font-weight:600;">Shift Handover Reports</h3>
          <p class="text-muted" style="font-size:12px;margin:2px 0 0;">Document ongoing issues, tasks, and briefings at shift changeover.</p>
        </div>
        <button class="btn btn-primary" @click="openHandoverForm">+ New Handover Report</button>
      </div>

      <p v-if="handoversLoading" class="status">Loading…</p>
      <p v-if="handoversError" class="error">{{ handoversError }}</p>

      <!-- Create form -->
      <div v-if="showHandoverForm" class="handover-form-card">
        <div class="handover-form-header">
          <h4>New Handover Report</h4>
          <button class="modal-close" @click="showHandoverForm = false">×</button>
        </div>
        <p v-if="handoverError" class="error">{{ handoverError }}</p>

        <div class="handover-form-grid">
          <div class="form-group">
            <label>Report Date *</label>
            <input v-model="handoverForm.report_date" type="date" class="form-input" />
          </div>
          <div class="form-group">
            <label>Shift Type</label>
            <select v-model="handoverForm.shift_type_id" class="form-input">
              <option value="">— Select shift —</option>
              <option v-for="st in shiftTypes.filter(s => s.is_active)" :key="st.id" :value="st.id">{{ st.name }}</option>
            </select>
          </div>
          <div class="form-group">
            <label>Outgoing Employee *</label>
            <select v-model="handoverForm.outgoing_employee_id" class="form-input">
              <option value="">— Select —</option>
              <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.full_name }}</option>
            </select>
          </div>
          <div class="form-group">
            <label>Incoming Employee</label>
            <select v-model="handoverForm.incoming_employee_id" class="form-input">
              <option value="">— Select —</option>
              <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.full_name }}</option>
            </select>
          </div>
          <div class="form-group">
            <label>Shift Start</label>
            <input v-model="handoverForm.shift_start" type="time" class="form-input" />
          </div>
          <div class="form-group">
            <label>Shift End</label>
            <input v-model="handoverForm.shift_end" type="time" class="form-input" />
          </div>
        </div>

        <div class="form-group">
          <label>Tasks Completed</label>
          <textarea v-model="handoverForm.tasks_completed" class="form-input" rows="3" placeholder="What was completed during this shift…"></textarea>
        </div>
        <div class="form-group">
          <label>Ongoing Issues / Incidents</label>
          <textarea v-model="handoverForm.ongoing_issues" class="form-input" rows="3" placeholder="Open issues, alerts, or incidents that need attention…"></textarea>
        </div>
        <div class="form-group">
          <label>Pending Tasks</label>
          <textarea v-model="handoverForm.tasks_pending" class="form-input" rows="2" placeholder="Tasks that still need to be completed…"></textarea>
        </div>
        <div class="handover-form-grid">
          <div class="form-group">
            <label>Equipment / Systems Notes</label>
            <textarea v-model="handoverForm.equipment_notes" class="form-input" rows="2" placeholder="Any equipment or system status notes…"></textarea>
          </div>
          <div class="form-group">
            <label>General Notes</label>
            <textarea v-model="handoverForm.general_notes" class="form-input" rows="2" placeholder="Any other information for incoming staff…"></textarea>
          </div>
        </div>

        <div class="handover-form-footer">
          <button class="btn btn-outline" @click="showHandoverForm = false">Cancel</button>
          <button class="btn btn-primary" :disabled="handoverSaving || !handoverForm.outgoing_employee_id || !handoverForm.report_date" @click="saveHandover">
            {{ handoverSaving ? "Saving…" : "Submit Handover Report" }}
          </button>
        </div>
      </div>

      <!-- Handover list -->
      <div class="handover-list">
        <div v-if="handovers.length === 0 && !handoversLoading && !showHandoverForm" class="report-empty">
          No handover reports yet.
        </div>

        <div v-for="h in handovers" :key="h.id" class="handover-card">
          <div class="handover-card-header" @click="expandedHandover = expandedHandover === h.id ? null : h.id">
            <div class="handover-meta">
              <span class="handover-date">{{ new Date(h.report_date + 'T00:00:00').toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'long', year: 'numeric' }) }}</span>
              <span v-if="h.shift_type" class="handover-shift-badge" :style="{ background: h.shift_type.color + '22', color: h.shift_type.color, border: `1px solid ${h.shift_type.color}44` }">{{ h.shift_type.name }}</span>
              <span class="handover-people">
                <strong>{{ h.outgoing_employee?.full_name ?? '—' }}</strong>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0"><polyline points="9 18 15 12 9 6"/></svg>
                <span>{{ h.incoming_employee?.full_name ?? 'No incoming assigned' }}</span>
              </span>
              <span v-if="h.shift_start && h.shift_end" class="text-muted" style="font-size:11px;">{{ h.shift_start }} – {{ h.shift_end }}</span>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
              <span v-if="h.acknowledged_at" class="badge badge-green" style="font-size:10px;">Acknowledged</span>
              <span v-else class="badge badge-yellow" style="font-size:10px;">Pending acknowledgment</span>
              <button v-if="!h.acknowledged_at" class="btn btn-sm btn-outline" @click.stop="acknowledgeHandover(h.id)">Acknowledge</button>
              <span class="expand-icon" :style="{ transform: expandedHandover === h.id ? 'rotate(90deg)' : '' }">›</span>
            </div>
          </div>

          <!-- Expanded detail -->
          <div v-if="expandedHandover === h.id" class="handover-detail">
            <div class="handover-detail-grid">
              <div v-if="h.tasks_completed" class="handover-field">
                <div class="handover-field-label">Tasks Completed</div>
                <div class="handover-field-body">{{ h.tasks_completed }}</div>
              </div>
              <div v-if="h.ongoing_issues" class="handover-field handover-field-issues">
                <div class="handover-field-label">Ongoing Issues</div>
                <div class="handover-field-body">{{ h.ongoing_issues }}</div>
              </div>
              <div v-if="h.tasks_pending" class="handover-field">
                <div class="handover-field-label">Pending Tasks</div>
                <div class="handover-field-body">{{ h.tasks_pending }}</div>
              </div>
              <div v-if="h.equipment_notes" class="handover-field">
                <div class="handover-field-label">Equipment / Systems</div>
                <div class="handover-field-body">{{ h.equipment_notes }}</div>
              </div>
              <div v-if="h.general_notes" class="handover-field" style="grid-column:1/-1">
                <div class="handover-field-label">General Notes</div>
                <div class="handover-field-body">{{ h.general_notes }}</div>
              </div>
            </div>
            <div v-if="h.acknowledged_at" class="handover-ack-notice">
              ✓ Acknowledged by {{ h.acknowledged_by?.full_name ?? 'unknown' }} at {{ new Date(h.acknowledged_at).toLocaleString('en-GB') }}
            </div>
            <div class="handover-submitted">Submitted {{ new Date(h.created_at).toLocaleString('en-GB') }}</div>
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
              <th>Color</th><th>Name</th><th>Group</th><th>Start</th><th>End</th>
              <th>Duration</th><th>Break</th><th>Overnight</th><th>Status</th><th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="shiftTypes.length === 0"><td colspan="10" class="empty-row">No shift types configured yet.</td></tr>
            <tr v-for="st in shiftTypes" :key="st.id">
              <td><span class="color-swatch" :style="{ background: st.color ?? '#3B82F6' }"></span></td>
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
        <div class="modal modal-lg">
          <div class="modal-header">
            <h3>{{ editingShiftId ? 'Edit Shift Type' : 'Add Shift Type' }}</h3>
            <button class="modal-close" @click="showShiftForm = false">×</button>
          </div>
          <div class="modal-body">
            <p v-if="shiftFormError" class="error">{{ shiftFormError }}</p>
            <div class="form-row">
              <div class="form-group" style="flex:1">
                <label>Shift Name *</label>
                <input v-model="shiftForm.name" class="form-input" placeholder="e.g. SOC Night" />
              </div>
              <div class="form-group" style="flex:0 0 auto">
                <label>Color</label>
                <div style="display:flex;align-items:center;gap:8px;">
                  <input v-model="shiftForm.color" type="color" style="width:44px;height:36px;border:1px solid #e2e8f0;border-radius:6px;padding:2px;cursor:pointer;" />
                  <code style="font-size:11px;color:#64748b;">{{ shiftForm.color }}</code>
                </div>
              </div>
            </div>
            <div class="form-group">
              <label>Shift Group</label>
              <select v-model="shiftForm.shift_group_id" class="form-input">
                <option value="">— None —</option>
                <option v-for="g in groups" :key="g.id" :value="g.id">{{ g.name }}</option>
              </select>
            </div>
            <div class="form-row">
              <div class="form-group"><label>Start Time *</label><input v-model="shiftForm.start_time" type="time" class="form-input" /></div>
              <div class="form-group"><label>End Time *</label><input v-model="shiftForm.end_time" type="time" class="form-input" /></div>
            </div>
            <div class="form-row">
              <div class="form-group"><label>Break Start</label><input v-model="shiftForm.break_start" type="time" class="form-input" /></div>
              <div class="form-group"><label>Break Duration (min)</label><input v-model.number="shiftForm.break_duration_minutes" type="number" min="0" max="480" class="form-input" /></div>
            </div>
            <div class="form-group">
              <label class="checkbox-label"><input v-model="shiftForm.is_active" type="checkbox" /> Active</label>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-outline" @click="showShiftForm = false">Cancel</button>
            <button class="btn btn-primary" :disabled="shiftSaving" @click="saveShift">{{ shiftSaving ? "Saving…" : "Save" }}</button>
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
          <thead><tr><th>Name</th><th>Description</th><th>Created</th><th></th></tr></thead>
          <tbody>
            <tr v-if="groups.length === 0"><td colspan="4" class="empty-row">No shift groups yet.</td></tr>
            <tr v-for="g in groups" :key="g.id">
              <td><strong>{{ g.name }}</strong></td>
              <td>{{ g.description ?? '—' }}</td>
              <td>{{ new Date(g.created_at).toLocaleDateString() }}</td>
              <td class="actions-cell">
                <button class="btn-icon" @click="openGroupForm(g)">✏️</button>
                <button class="btn-icon" @click="deleteGroup(g.id)">🗑️</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="showGroupForm" class="modal-overlay" @click.self="showGroupForm = false">
        <div class="modal">
          <div class="modal-header"><h3>{{ editingGroupId ? 'Edit' : 'Add' }} Shift Group</h3><button class="modal-close" @click="showGroupForm = false">×</button></div>
          <div class="modal-body">
            <p v-if="groupFormError" class="error">{{ groupFormError }}</p>
            <div class="form-group"><label>Group Name *</label><input v-model="groupForm.name" class="form-input" placeholder="e.g. Team 1, SOC Team" /></div>
            <div class="form-group"><label>Description</label><textarea v-model="groupForm.description" class="form-input" rows="2"></textarea></div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-outline" @click="showGroupForm = false">Cancel</button>
            <button class="btn btn-primary" :disabled="groupSaving" @click="saveGroup">{{ groupSaving ? "Saving…" : "Save" }}</button>
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
          <thead><tr><th>Requester</th><th>Their Shift</th><th>Co-worker</th><th>Co-worker's Shift</th><th>Status</th><th>Requested</th><th></th></tr></thead>
          <tbody>
            <tr v-if="swaps.length === 0"><td colspan="7" class="empty-row">No swap requests.</td></tr>
            <tr v-for="sw in swaps" :key="sw.id">
              <td>{{ sw.requester?.full_name ?? '—' }}</td>
              <td>
                <span v-if="sw.requester_assignment">{{ sw.requester_assignment.shift_name ?? 'Shift' }} · {{ sw.requester_assignment.assignment_date }}</span>
                <span v-else>—</span>
              </td>
              <td>{{ sw.requested_employee?.full_name ?? '—' }}</td>
              <td>
                <span v-if="sw.requested_assignment">{{ sw.requested_assignment.shift_name ?? 'Shift' }} · {{ sw.requested_assignment.assignment_date }}</span>
                <span v-else>—</span>
              </td>
              <td><span :class="['badge', swapStatusBadge(sw.status)]">{{ sw.status }}</span></td>
              <td>{{ new Date(sw.created_at).toLocaleDateString() }}</td>
              <td class="actions-cell">
                <button v-if="sw.status === 'accepted'" class="btn btn-sm btn-primary" @click="approveSwap(sw.id)">Approve</button>
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
            <button class="btn btn-primary" :disabled="configSaving" @click="saveConfig">{{ configSaving ? "Saving…" : "Save Configuration" }}</button>
          </div>
        </template>
      </div>
    </template>
  </div>
</template>

<style scoped>
/* ── Tabs ──────────────────────────────────────────────────────────────── */
.tab-bar { display:flex; gap:4px; border-bottom:2px solid var(--color-border); margin-bottom:24px; overflow-x:auto; }
.tab-btn { padding:8px 16px; border:none; background:none; cursor:pointer; font-size:13px; font-weight:500; color:var(--color-text-muted); border-bottom:2px solid transparent; margin-bottom:-2px; transition:color 0.15s,border-color 0.15s; white-space:nowrap; }
.tab-btn:hover { color:var(--color-text); }
.tab-btn.active { color:var(--color-brand-base); border-bottom-color:var(--color-brand-base); }

/* ── Sub tabs (inside Reports) ─────────────────────────────────────────── */
.report-sub-tabs { display:flex; gap:0; margin-bottom:20px; border-bottom:2px solid var(--color-border); }
.sub-tab-btn { padding:7px 18px; border:none; background:none; cursor:pointer; font-size:13px; font-weight:500; color:var(--color-text-muted); border-bottom:2px solid transparent; margin-bottom:-2px; transition:color 0.15s; }
.sub-tab-btn:hover { color:var(--color-text); }
.sub-tab-btn.active { color:var(--color-brand-base); border-bottom-color:var(--color-brand-base); }

/* ── Toolbar ───────────────────────────────────────────────────────────── */
.roster-toolbar { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:16px; }
.roster-schedule-list { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.schedule-pill { padding:4px 12px; border-radius:20px; border:1px solid var(--color-border); background:var(--color-surface); cursor:pointer; font-size:13px; color:var(--color-text-muted); transition:all 0.15s; display:flex; align-items:center; }
.schedule-pill:hover { background:var(--color-neutral-100); }
.schedule-pill.active { border-color:var(--color-brand-base); color:var(--color-brand-base); background:var(--color-brand-light); }
.section-toolbar { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:16px; flex-wrap:wrap; }

/* ── Roster week nav ───────────────────────────────────────────────────── */
.roster-week-nav { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; flex-wrap:wrap; gap:8px; }

/* ── Excel-style Roster Grid ───────────────────────────────────────────── */
.roster-grid-wrapper { overflow-x:auto; border:1px solid var(--color-border); border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,.06); }
.roster-grid { width:100%; border-collapse:collapse; background:var(--color-surface); min-width:700px; }
.roster-grid thead tr { background:#1e293b; }
.roster-grid th { padding:10px 8px; font-size:11px; font-weight:700; text-align:center; color:#f1f5f9; letter-spacing:0.04em; text-transform:uppercase; border-right:1px solid rgba(255,255,255,.08); }
.col-emp { min-width:160px; text-align:left !important; }
.col-hrs { min-width:80px; }
.col-day { min-width:110px; }
.col-today { background:#003a75 !important; }

/* Team header row */
.team-header-row { }
.team-header-cell { background:#334155; color:#94a3b8; font-size:10px; font-weight:700; letter-spacing:0.08em; text-transform:uppercase; padding:5px 12px; border-top:2px solid #475569; }

/* Employee rows */
.emp-row { transition:background 0.1s; }
.emp-row:hover { background:#f8fafc; }
.cell-emp { display:flex; align-items:center; gap:8px; padding:8px 10px; border-right:1px solid var(--color-border); border-bottom:1px solid #f1f5f9; }
.emp-avatar { width:26px; height:26px; border-radius:50%; background:var(--color-brand-subtle); color:var(--color-brand-base); display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:700; flex-shrink:0; }
.emp-name { font-size:12px; font-weight:600; color:#1e293b; white-space:nowrap; }
.cell-hrs { text-align:center; font-size:11px; color:var(--color-text-muted); font-weight:600; padding:8px; border-right:1px solid var(--color-border); border-bottom:1px solid #f1f5f9; }

/* Slot cells – colored full background */
.cell-slot { text-align:center; cursor:pointer; vertical-align:middle; height:52px; padding:0; border:1px solid rgba(0,0,0,.06); transition:opacity 0.1s; }
.cell-slot:hover { opacity:0.88; }
.cell-inner { display:flex; flex-direction:column; align-items:center; justify-content:center; height:100%; padding:4px 2px; position:relative; gap:1px; }
.cell-main-label { font-size:11px; font-weight:700; letter-spacing:0.04em; text-transform:uppercase; line-height:1.2; }
.cell-sub-label { font-size:9px; opacity:0.8; line-height:1.2; }
.cell-del { position:absolute; top:2px; right:2px; width:14px; height:14px; border-radius:50%; border:none; background:rgba(0,0,0,.25); color:#fff; font-size:10px; line-height:1; cursor:pointer; display:flex; align-items:center; justify-content:center; opacity:0; transition:opacity 0.15s; }
.cell-slot:hover .cell-del { opacity:1; }
.cell-empty { color:#d1d5db; font-size:16px; line-height:1; }
.grid-empty { text-align:center; padding:40px; color:var(--color-text-muted); }

/* Legend */
.shift-legend { display:flex; align-items:center; gap:10px; margin-top:12px; flex-wrap:wrap; }
.legend-label { font-size:11px; font-weight:600; color:var(--color-text-muted); }
.legend-items { display:flex; gap:6px; flex-wrap:wrap; }
.legend-chip { font-size:10px; font-weight:700; padding:3px 10px; border-radius:20px; letter-spacing:0.04em; }

/* ── Color swatch in table ─────────────────────────────────────────────── */
.color-swatch { display:inline-block; width:20px; height:20px; border-radius:4px; border:1px solid rgba(0,0,0,.1); vertical-align:middle; }

/* ── Reports ───────────────────────────────────────────────────────────── */
.report-section { }
.report-section-title { font-size:14px; font-weight:600; margin:0 0 12px; color:var(--color-text); }
.report-empty { text-align:center; color:var(--color-text-muted); padding:32px; font-size:13px; background:var(--color-surface); border:1px solid var(--color-border); border-radius:8px; }

.coverage-table .cov-count-cell { text-align:center; }
.cov-type-badge { font-size:10px; font-weight:700; padding:2px 8px; border-radius:20px; white-space:nowrap; }
.cov-count { font-size:13px; font-weight:700; padding:2px 10px; border-radius:20px; display:inline-block; }
.cov-zero { color:#d1d5db; font-size:12px; }
.row-today { background:var(--color-brand-light) !important; }

/* My Schedule cards */
.my-schedule-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(240px, 1fr)); gap:12px; }
.my-sched-card { background:#fff; border:1px solid var(--color-border); border-left:4px solid #3B82F6; border-radius:8px; padding:14px 16px; }
.my-sched-date { font-size:11px; font-weight:600; color:var(--color-text-muted); text-transform:uppercase; letter-spacing:0.05em; margin-bottom:4px; }
.my-sched-type { font-size:15px; font-weight:700; margin-bottom:4px; }
.my-sched-time { font-size:12px; color:#64748b; display:flex; align-items:center; gap:6px; }
.my-sched-note { font-size:11px; color:#94a3b8; margin-top:6px; font-style:italic; }
.overnight-badge { font-size:9px; font-weight:600; background:#fef3c7; color:#92400e; padding:1px 6px; border-radius:20px; }

/* ── Handover Reports ──────────────────────────────────────────────────── */
.handover-form-card { background:#fff; border:1px solid var(--color-border); border-radius:10px; padding:24px; margin-bottom:20px; }
.handover-form-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; }
.handover-form-header h4 { margin:0; font-size:15px; font-weight:600; }
.handover-form-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:4px; }
.handover-form-footer { display:flex; justify-content:flex-end; gap:8px; margin-top:16px; padding-top:16px; border-top:1px solid var(--color-border); }

.handover-list { display:flex; flex-direction:column; gap:8px; }
.handover-card { background:#fff; border:1px solid var(--color-border); border-radius:10px; overflow:hidden; }
.handover-card-header { display:flex; align-items:center; justify-content:space-between; padding:14px 18px; cursor:pointer; transition:background 0.1s; gap:12px; flex-wrap:wrap; }
.handover-card-header:hover { background:#f8fafc; }
.handover-meta { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.handover-date { font-size:13px; font-weight:700; color:#0f172a; }
.handover-shift-badge { font-size:10px; font-weight:700; padding:2px 8px; border-radius:20px; }
.handover-people { display:flex; align-items:center; gap:6px; font-size:12px; color:#475569; }
.handover-people strong { color:#0f172a; }
.expand-icon { font-size:18px; color:#94a3b8; transition:transform 0.2s; display:inline-block; }

.handover-detail { padding:16px 18px; border-top:1px solid var(--color-border); background:#fafafa; }
.handover-detail-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px; }
.handover-field { }
.handover-field-issues { background:#fff7ed; border:1px solid #fed7aa; border-radius:6px; padding:10px; }
.handover-field-label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; color:#94a3b8; margin-bottom:4px; }
.handover-field-body { font-size:12px; color:#374151; white-space:pre-wrap; line-height:1.55; }
.handover-ack-notice { background:#f0fdf4; border:1px solid #86efac; border-radius:6px; padding:8px 12px; font-size:12px; color:#15803d; font-weight:500; margin-bottom:8px; }
.handover-submitted { font-size:11px; color:#94a3b8; }

/* ── Config card ───────────────────────────────────────────────────────── */
.config-card { background:var(--color-surface); border:1px solid var(--color-border); border-radius:10px; padding:28px 32px; max-width:600px; }
.config-section-title { font-size:15px; font-weight:600; margin-bottom:20px; color:var(--color-text); }
.field-hint { font-size:12px; color:var(--color-text-muted); margin-bottom:6px; }
.config-toggle-group { display:flex; flex-direction:column; gap:20px; margin-top:24px; }
.config-toggle-row { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; padding:16px 0; border-top:1px solid var(--color-border); }
.toggle-label { font-weight:500; font-size:13px; margin-bottom:4px; }
.toggle-desc { font-size:12px; color:var(--color-text-muted); max-width:380px; }
.toggle-switch { display:flex; align-items:center; cursor:pointer; flex-shrink:0; }
.toggle-switch input { position:absolute; opacity:0; width:0; height:0; }
.toggle-track { width:40px; height:22px; background:var(--color-neutral-300); border-radius:11px; position:relative; transition:background 0.2s; }
.toggle-track.disabled { opacity:0.4; cursor:not-allowed; }
.toggle-switch input:checked + .toggle-track { background:var(--color-brand-base); }
.toggle-thumb { position:absolute; top:3px; left:3px; width:16px; height:16px; background:white; border-radius:50%; transition:transform 0.2s; box-shadow:0 1px 3px rgba(0,0,0,.2); }
.toggle-switch input:checked + .toggle-track .toggle-thumb { transform:translateX(18px); }

/* ── Shared utilities ──────────────────────────────────────────────────── */
.view-subtitle { font-size:13px; color:var(--color-text-muted); margin-top:2px; }
.text-muted { color:var(--color-text-muted); }
.status  { color:var(--color-text-muted); margin:16px 0; font-size:13px; }
.error   { color:var(--color-error); font-size:13px; margin:8px 0; }
.success { color:var(--color-success); font-size:13px; margin:8px 0; }
.empty-row { text-align:center; color:var(--color-text-muted); padding:24px; }
.actions-cell { white-space:nowrap; }
.btn-icon { background:none; border:none; cursor:pointer; padding:2px 4px; font-size:14px; }
.badge-muted { background:#F3F4F6; color:#6B7280; }
.badge-red   { background:#FEE2E2; color:var(--color-error); }
.checkbox-label { display:flex; align-items:center; gap:8px; font-size:13px; cursor:pointer; }
.checkbox-label input { width:15px; height:15px; }

/* ── Modal overlay & dialog ────────────────────────────────────────────── */
.modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.55);
  backdrop-filter: blur(2px);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
  padding: 16px;
}
.modal {
  background: #fff;
  border-radius: 12px;
  box-shadow: 0 20px 60px rgba(0,0,0,.22), 0 4px 16px rgba(0,0,0,.12);
  width: 100%;
  max-width: 480px;
  max-height: 90vh;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  animation: modal-in 0.18s cubic-bezier(.22,.68,0,1.2);
}
.modal.modal-lg { max-width: 600px; }

@keyframes modal-in {
  from { opacity: 0; transform: scale(0.96) translateY(8px); }
  to   { opacity: 1; transform: scale(1) translateY(0); }
}

.modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 20px 24px 0;
  flex-shrink: 0;
}
.modal-header h3 {
  margin: 0;
  font-size: 16px;
  font-weight: 700;
  color: #0f172a;
}
.modal-close {
  background: #f1f5f9;
  border: none;
  width: 28px;
  height: 28px;
  border-radius: 50%;
  cursor: pointer;
  font-size: 16px;
  color: #64748b;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background 0.15s, color 0.15s;
  flex-shrink: 0;
  line-height: 1;
}
.modal-close:hover { background: #e2e8f0; color: #0f172a; }

.modal-body {
  padding: 20px 24px;
  overflow-y: auto;
  flex: 1;
}
.modal-footer {
  padding: 16px 24px;
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  border-top: 1px solid #f1f5f9;
  flex-shrink: 0;
  background: #fafbfc;
}

@media (max-width: 640px) {
  .modal { max-width: 100%; border-radius: 16px 16px 0 0; align-self: flex-end; max-height: 95vh; }
  .modal-overlay { align-items: flex-end; padding: 0; }
  .handover-form-grid { grid-template-columns: 1fr; }
  .handover-detail-grid { grid-template-columns: 1fr; }
}
</style>
