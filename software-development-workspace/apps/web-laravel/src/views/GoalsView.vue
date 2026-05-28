<script setup lang="ts">
import { ref, computed, onMounted, watch } from "vue";
import { api } from "../lib/api";
import { currentOrgSlug } from "../stores/auth";

// ── Types ──────────────────────────────────────────────────────────────────────
interface Employee { id: number; first_name: string; last_name: string; employee_number?: string; designation?: { id: number; title: string } | null }

interface GoalKR {
  id: number; goal_id: number; title: string; description: string | null;
  use_metric: boolean; metric_format: string | null; metric_unit: string | null;
  start_value: number; target_value: number | null; current_value: number;
  completion: number; weight: number; due_date: string | null;
  status: string;
}

interface Goal {
  id: number; title: string; description: string | null;
  level: "functional_unit" | "department" | "business_unit" | "practice" | "individual" | "organizational";
  priority: "low" | "medium" | "high" | "critical";
  status: "pending" | "in_progress" | "achieved" | "not_achieved" | "on_hold";
  weight: number; due_date: string | null; completion: number;
  is_okr: boolean; in_library: boolean;
  parent_goal_id: number | null;
  parent?: { id: number; title: string } | null;
  assignees: Employee[];
  created_by?: Employee | null;
  key_results: GoalKR[];
  sub_goals?: Goal[];
}

interface Counts {
  all: number; pending: number; in_progress: number;
  achieved: number; not_achieved: number; on_hold: number;
}

// ── State ──────────────────────────────────────────────────────────────────────
const topView   = ref<"list" | "mine" | "library">("list");
const goalTab   = ref<"goals" | "okr">("goals");
const statusFilter = ref<"all" | "pending" | "in_progress" | "achieved" | "not_achieved" | "on_hold">("all");
const search    = ref("");
const goals     = ref<Goal[]>([]);
const counts    = ref<Counts>({ all: 0, pending: 0, in_progress: 0, achieved: 0, not_achieved: 0, on_hold: 0 });
const employees = ref<Employee[]>([]);
const loading   = ref(false);
const error     = ref("");
const expanded  = ref<Set<number>>(new Set());

// ── Form state ─────────────────────────────────────────────────────────────────
const showForm  = ref(false);
const editGoal  = ref<Goal | null>(null);
const saving    = ref(false);
const saveError = ref("");
const form = ref({
  title: "", description: "", level: "individual" as "functional_unit" | "department" | "business_unit" | "practice" | "individual" | "organizational",
  priority: "medium" as "low" | "medium" | "high" | "critical",
  status: "pending" as string,
  weight: 5, due_date: "", completion: 0,
  in_library: false, parent_goal_id: null as number | null,
  employee_ids: [] as number[],
});

// ── KR add state ───────────────────────────────────────────────────────────────
const krGoalId  = ref<number | null>(null);
const showKrForm = ref(false);
const krForm = ref({ title: "", use_metric: false, target_value: 100, current_value: 0, metric_format: "percentage", due_date: "", weight: 1 });

// ── Helpers ────────────────────────────────────────────────────────────────────
function org() { return currentOrgSlug.value; }
function empName(e: Employee) { return `${e.first_name} ${e.last_name}`; }

const statusLabels: Record<string, string> = {
  pending: "Pending", in_progress: "In Progress", achieved: "Achieved",
  not_achieved: "Not Achieved", on_hold: "On Hold",
};
const priorityLabels: Record<string, string> = {
  low: "Low", medium: "Medium", high: "High", critical: "Critical",
};

const filtered = computed(() => {
  const q = search.value.toLowerCase();
  return goals.value.filter(g =>
    !q || g.title.toLowerCase().includes(q) ||
    g.assignees.some(a => empName(a).toLowerCase().includes(q))
  );
});

// ── Load ───────────────────────────────────────────────────────────────────────
async function load() {
  if (!org()) return;
  loading.value = true; error.value = "";
  try {
    const [goalRes, empRes] = await Promise.all([
      api<{ data: Goal[]; counts: Counts }>("GET", "/api/v1/goals", {
        org: org(),
        params: { view: topView.value, status: statusFilter.value, okr: goalTab.value === "okr" ? "true" : "false" },
      }),
      api<{ data: Employee[] }>("GET", "/api/v1/employees", { org: org(), params: { per_page: 500 } }),
    ]);
    goals.value    = goalRes.data;
    counts.value   = goalRes.counts;
    employees.value = (empRes as any).data ?? [];
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to load.";
  } finally { loading.value = false; }
}

watch([topView, goalTab, statusFilter, currentOrgSlug], load);
onMounted(load);

// ── Progress ring SVG ──────────────────────────────────────────────────────────
function progressRing(pct: number, size = 36): { stroke: string; dashArray: string; dashOffset: string; color: string } {
  const r = (size / 2) - 4;
  const c = 2 * Math.PI * r;
  const p = Math.min(100, Math.max(0, pct));
  const color = p >= 100 ? "#22c55e" : p >= 50 ? "#f59e0b" : p > 0 ? "#3b82f6" : "#e2e8f0";
  return { stroke: color, dashArray: `${c}`, dashOffset: `${c - (p / 100) * c}`, color };
}

// ── Toggle expand ──────────────────────────────────────────────────────────────
function toggleExpand(id: number) {
  if (expanded.value.has(id)) expanded.value.delete(id);
  else expanded.value.add(id);
}

// ── Status inline update ───────────────────────────────────────────────────────
async function updateStatus(goal: Goal, newStatus: string) {
  try {
    await api("PUT", `/api/v1/goals/${goal.id}/status`, { org: org(), body: { status: newStatus } });
    goal.status = newStatus as Goal["status"];
    await load(); // refresh counts
  } catch { /* silent */ }
}

// ── Completion inline update ───────────────────────────────────────────────────
const editingCompletion = ref<number | null>(null);
const completionVal = ref(0);

function startEditCompletion(g: Goal) {
  editingCompletion.value = g.id;
  completionVal.value = g.completion;
}

async function saveCompletion(g: Goal) {
  if (editingCompletion.value !== g.id) return;
  editingCompletion.value = null;
  try {
    const res = await api<{ completion: number; status: string }>("PUT", `/api/v1/goals/${g.id}/completion`, {
      org: org(), body: { completion: completionVal.value },
    });
    g.completion = res.completion;
    g.status     = res.status as Goal["status"];
    await load();
  } catch { /* silent */ }
}

// ── Open create form ───────────────────────────────────────────────────────────
function openCreate() {
  editGoal.value = null;
  form.value = {
    title: "", description: "", level: "individual" as any, priority: "medium",
    status: "pending", weight: 5, due_date: "", completion: 0,
    in_library: false, parent_goal_id: null, employee_ids: [],
  };
  saveError.value = "";
  showForm.value = true;
}

function openEdit(g: Goal) {
  editGoal.value = g;
  form.value = {
    title: g.title, description: g.description ?? "",
    level: g.level, priority: g.priority,
    status: g.status, weight: g.weight,
    due_date: g.due_date ?? "", completion: g.completion,
    in_library: g.in_library,
    parent_goal_id: g.parent_goal_id,
    employee_ids: g.assignees.map(a => a.id),
  };
  saveError.value = "";
  showForm.value = true;
}

// ── Save ───────────────────────────────────────────────────────────────────────
async function saveGoal() {
  if (!form.value.title.trim()) { saveError.value = "Goal name is required."; return; }
  saving.value = true; saveError.value = "";
  try {
    const body = {
      ...form.value,
      is_okr: goalTab.value === "okr",
      due_date: form.value.due_date || null,
      parent_goal_id: form.value.parent_goal_id || null,
    };
    if (editGoal.value) {
      await api("PUT", `/api/v1/goals/${editGoal.value.id}`, { org: org(), body });
    } else {
      await api("POST", "/api/v1/goals", { org: org(), body });
    }
    showForm.value = false;
    await load();
  } catch (e) {
    saveError.value = e instanceof Error ? e.message : "Failed to save.";
  } finally { saving.value = false; }
}

async function deleteGoal(id: number) {
  if (!confirm("Delete this goal?")) return;
  await api("DELETE", `/api/v1/goals/${id}`, { org: org() });
  await load();
}

// ── KR form ────────────────────────────────────────────────────────────────────
function openAddKR(goalId: number) {
  krGoalId.value = goalId;
  krForm.value   = { title: "", use_metric: false, target_value: 100, current_value: 0, metric_format: "percentage", due_date: "", weight: 1 };
  showKrForm.value = true;
}

async function saveKR() {
  if (!krGoalId.value || !krForm.value.title.trim()) return;
  try {
    await api("POST", `/api/v1/goals/${krGoalId.value}/key-results`, { org: org(), body: krForm.value });
    showKrForm.value = false;
    await load();
  } catch (e) { saveError.value = "Failed to save key result."; }
}

async function deleteKR(krId: number) {
  if (!confirm("Remove this key result?")) return;
  await api("DELETE", `/api/v1/goals/key-results/${krId}`, { org: org() });
  await load();
}

// ── Toggle employee in form ────────────────────────────────────────────────────
function toggleEmployee(id: number) {
  const i = form.value.employee_ids.indexOf(id);
  if (i === -1) form.value.employee_ids.push(id);
  else form.value.employee_ids.splice(i, 1);
}
</script>

<template>
  <div class="goals-page">

    <!-- ── Page Header ────────────────────────────────────────────────────── -->
    <div class="goals-header">
      <h2 class="goals-title">Goals</h2>
      <div class="goals-topnav">
        <button :class="['topnav-btn', { active: topView === 'list' }]"  @click="topView = 'list'">Goal List</button>
        <button :class="['topnav-btn', { active: topView === 'mine' }]"  @click="topView = 'mine'">My Goals</button>
        <button :class="['topnav-btn', { active: topView === 'library' }]" @click="topView = 'library'">Goal Library</button>
      </div>
      <router-link :to="{ name: 'hr-dashboard' }" class="btn btn-secondary btn-sm">← Dashboard</router-link>
    </div>

    <p v-if="error" class="error">{{ error }}</p>

    <!-- ── Main layout: sidebar + content ────────────────────────────────── -->
    <div class="goals-layout">

      <!-- Status sidebar -->
      <aside class="status-sidebar">
        <div
          v-for="item in [
            { key: 'all',          label: 'All',          count: counts.all },
            { key: 'pending',      label: 'Pending',      count: counts.pending },
            { key: 'in_progress',  label: 'In Progress',  count: counts.in_progress },
            { key: 'achieved',     label: 'Achieved',     count: counts.achieved },
            { key: 'not_achieved', label: 'Not Achieved', count: counts.not_achieved },
            { key: 'on_hold',      label: 'On Hold',      count: counts.on_hold },
          ]"
          :key="item.key"
          :class="['status-item', { active: statusFilter === item.key }]"
          @click="statusFilter = (item.key as any)"
        >
          <span class="status-count" :class="`status-count-${item.key}`">{{ item.count }}</span>
          <span class="status-label">{{ item.label }}</span>
        </div>
      </aside>

      <!-- Content area -->
      <div class="goals-content">

        <!-- Goals / OKRs tabs + toolbar -->
        <div class="content-toolbar">
          <div class="content-tabs">
            <button :class="['ctab', { active: goalTab === 'goals' }]" @click="goalTab = 'goals'">
              <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
              </svg>
              Goals
            </button>
            <button :class="['ctab', { active: goalTab === 'okr' }]" @click="goalTab = 'okr'">
              <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16">
                <path d="M2 10a8 8 0 018-8v8h8a8 8 0 11-16 0z"/>
                <path d="M12 2.252A8.014 8.014 0 0117.748 8H12V2.252z"/>
              </svg>
              OKRs
            </button>
          </div>
          <div class="toolbar-right">
            <input v-model="search" type="text" class="search-input" :placeholder="`Search ${goalTab === 'okr' ? 'objectives' : 'goals'}…`" />
            <button class="btn-export btn-export-sm">PDF</button>
            <button class="btn-export btn-export-sm">CSV</button>
            <button class="btn btn-primary btn-sm" @click="openCreate">
              + {{ goalTab === 'okr' ? 'Create Objective' : 'Create Goal' }}
            </button>
          </div>
        </div>

        <!-- Result count -->
        <p class="result-count">
          <span v-if="loading">Loading…</span>
          <span v-else>({{ filtered.length }}) {{ goalTab === 'okr' ? 'Objectives' : 'Goals' }} Found</span>
        </p>

        <!-- Table -->
        <div class="goals-table-wrap">
          <table class="goals-table">
            <thead>
              <tr>
                <th style="width:32px"><input type="checkbox" /></th>
                <th class="col-name">{{ goalTab === 'okr' ? 'Objective Name' : 'Goal Name' }}</th>
                <th class="col-num">Weight</th>
                <th class="col-num">Level</th>
                <th class="col-owner">Owner</th>
                <th class="col-date">Due Date</th>
                <th class="col-status">Status</th>
                <th class="col-priority">Priority</th>
                <th style="width:60px"></th>
              </tr>
            </thead>
            <tbody>
              <template v-for="goal in filtered" :key="goal.id">
                <!-- Main goal row -->
                <tr class="goal-row" :class="`status-row-${goal.status}`">
                  <td><input type="checkbox" /></td>
                  <td class="col-name">
                    <div class="goal-name-cell">
                      <!-- Expand toggle (if has sub-goals or KRs) -->
                      <button
                        v-if="(goal.sub_goals?.length ?? 0) > 0 || (goal.key_results?.length ?? 0) > 0"
                        class="expand-btn"
                        @click="toggleExpand(goal.id)"
                      >{{ expanded.has(goal.id) ? '▼' : '▶' }}</button>
                      <span v-else style="width:20px;display:inline-block"></span>

                      <!-- Progress ring -->
                      <div class="ring-wrap" @click="startEditCompletion(goal)">
                        <svg :width="36" :height="36" viewBox="0 0 36 36">
                          <circle cx="18" cy="18" r="14" fill="none" stroke="#e2e8f0" stroke-width="4"/>
                          <circle
                            cx="18" cy="18" r="14" fill="none"
                            :stroke="progressRing(goal.completion).color"
                            stroke-width="4"
                            stroke-linecap="round"
                            :stroke-dasharray="progressRing(goal.completion).dashArray"
                            :stroke-dashoffset="progressRing(goal.completion).dashOffset"
                            transform="rotate(-90 18 18)"
                          />
                          <text x="18" y="22" text-anchor="middle" font-size="8" fill="#374151" font-weight="600">
                            <tspan v-if="editingCompletion === goal.id">…</tspan>
                            <tspan v-else>{{ Math.round(goal.completion) }}%</tspan>
                          </text>
                        </svg>
                        <input
                          v-if="editingCompletion === goal.id"
                          v-model.number="completionVal"
                          type="number" min="0" max="100"
                          class="completion-input"
                          @blur="saveCompletion(goal)"
                          @keyup.enter="saveCompletion(goal)"
                          @keyup.esc="editingCompletion = null"
                          @click.stop
                        />
                      </div>

                      <!-- Title -->
                      <span class="goal-title" @click="openEdit(goal)">{{ goal.title }}</span>
                    </div>
                  </td>
                  <td class="col-num">{{ goal.weight }}</td>
                  <td class="col-num">
                    <span class="level-icon" :title="goal.level">
                      <!-- Individual -->
                      <svg v-if="goal.level === 'individual'" viewBox="0 0 20 20" fill="currentColor" width="18" height="18">
                        <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"/>
                      </svg>
                      <!-- Organizational -->
                      <svg v-else-if="goal.level === 'organizational'" viewBox="0 0 20 20" fill="currentColor" width="18" height="18">
                        <path d="M13 6a3 3 0 11-6 0 3 3 0 016 0zM18 8a2 2 0 11-4 0 2 2 0 014 0zM14 15a4 4 0 00-8 0v1h8v-1zM6 8a2 2 0 11-4 0 2 2 0 014 0zM16 18v-1a5.972 5.972 0 00-.75-2.906A3.005 3.005 0 0119 15v1h-3zM4.75 12.094A5.973 5.973 0 004 15v1H1v-1a3 3 0 013.75-2.906z"/>
                      </svg>
                      <!-- Department -->
                      <svg v-else-if="goal.level === 'department'" viewBox="0 0 20 20" fill="currentColor" width="18" height="18">
                        <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h8a2 2 0 012 2v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4zm3 1h2v2H7V5zm0 4h2v2H7V9zm0 4h2v2H7v-2zm4-8h2v2h-2V5zm0 4h2v2h-2V9zm0 4h2v2h-2v-2z" clip-rule="evenodd"/>
                      </svg>
                      <!-- Business Unit / Functional Unit / Practice -->
                      <svg v-else viewBox="0 0 20 20" fill="currentColor" width="18" height="18">
                        <path d="M2 4a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H3a1 1 0 01-1-1V4zM3 9h14v6a1 1 0 01-1 1H4a1 1 0 01-1-1V9z"/>
                      </svg>
                    </span>
                  </td>
                  <td class="col-owner">
                    <span v-for="(a, i) in goal.assignees.slice(0, 2)" :key="a.id">
                      <span class="owner-chip" :title="empName(a)">{{ empName(a) }}</span>
                      <span v-if="i === 0 && goal.assignees.length > 1">, </span>
                    </span>
                    <span v-if="goal.assignees.length > 2" class="owner-more">+{{ goal.assignees.length - 2 }}</span>
                    <span v-if="goal.assignees.length === 0" class="text-muted">—</span>
                  </td>
                  <td class="col-date">{{ goal.due_date ?? "—" }}</td>
                  <td class="col-status">
                    <select
                      :value="goal.status"
                      class="status-select"
                      :class="`status-sel-${goal.status}`"
                      @change="updateStatus(goal, ($event.target as HTMLSelectElement).value)"
                    >
                      <option value="pending">Pending</option>
                      <option value="in_progress">In Progress</option>
                      <option value="achieved">Achieved</option>
                      <option value="not_achieved">Not Achieved</option>
                      <option value="on_hold">On Hold</option>
                    </select>
                  </td>
                  <td class="col-priority">
                    <span :class="['priority-badge', `priority-${goal.priority}`]">
                      {{ priorityLabels[goal.priority] }}
                    </span>
                  </td>
                  <td>
                    <div class="row-actions">
                      <button class="act-btn" title="Add Key Result" @click="openAddKR(goal.id)">＋KR</button>
                      <button class="act-btn act-del" title="Delete" @click="deleteGoal(goal.id)">✕</button>
                    </div>
                  </td>
                </tr>

                <!-- Key Results (expanded) -->
                <template v-if="expanded.has(goal.id)">
                  <tr v-for="kr in goal.key_results" :key="`kr-${kr.id}`" class="kr-row">
                    <td></td>
                    <td class="col-name">
                      <div class="kr-name-cell">
                        <span style="width:20px;display:inline-block"></span>
                        <div class="ring-wrap" style="transform:scale(0.8)">
                          <svg :width="36" :height="36" viewBox="0 0 36 36">
                            <circle cx="18" cy="18" r="14" fill="none" stroke="#e2e8f0" stroke-width="4"/>
                            <circle cx="18" cy="18" r="14" fill="none"
                              :stroke="progressRing(kr.completion).color"
                              stroke-width="4" stroke-linecap="round"
                              :stroke-dasharray="progressRing(kr.completion).dashArray"
                              :stroke-dashoffset="progressRing(kr.completion).dashOffset"
                              transform="rotate(-90 18 18)"
                            />
                            <text x="18" y="22" text-anchor="middle" font-size="8" fill="#374151" font-weight="600">{{ Math.round(kr.completion) }}%</text>
                          </svg>
                        </div>
                        <span class="kr-title">{{ kr.title }}</span>
                        <span v-if="kr.use_metric" class="kr-metric-badge">{{ kr.current_value }} / {{ kr.target_value }} {{ kr.metric_unit }}</span>
                      </div>
                    </td>
                    <td class="col-num">{{ kr.weight }}</td>
                    <td></td>
                    <td></td>
                    <td class="col-date">{{ kr.due_date ?? "—" }}</td>
                    <td class="col-status">
                      <span :class="['status-pill', `status-pill-${kr.status}`]">{{ statusLabels[kr.status] }}</span>
                    </td>
                    <td></td>
                    <td>
                      <button class="act-btn act-del" @click="deleteKR(kr.id)">✕</button>
                    </td>
                  </tr>
                </template>
              </template>

              <tr v-if="!loading && filtered.length === 0">
                <td colspan="9" style="text-align:center;padding:40px;color:#94a3b8">
                  No {{ goalTab === 'okr' ? 'objectives' : 'goals' }} found.
                  <button class="btn btn-primary btn-sm" style="margin-left:12px" @click="openCreate">
                    + Create {{ goalTab === 'okr' ? 'Objective' : 'Goal' }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ══ Create / Edit Goal Form (modal) ═══════════════════════════════════ -->
    <div v-if="showForm" class="modal-backdrop" @click.self="showForm = false">
      <div class="modal-panel">
        <div class="modal-header">
          <h3>{{ editGoal ? 'Edit' : 'Add' }} {{ goalTab === 'okr' ? 'Objective' : 'Goal' }}</h3>
          <button class="modal-close" @click="showForm = false">✕</button>
        </div>

        <div class="modal-body">
          <p v-if="saveError" class="error" style="margin-bottom:12px">{{ saveError }}</p>

          <div class="form-two-col">
            <!-- Left column -->
            <div class="form-left">
              <!-- Goal Name -->
              <div class="form-field">
                <label>{{ goalTab === 'okr' ? 'Objective' : 'Goal' }} Name <span class="req">*</span></label>
                <input v-model="form.title" type="text" class="input" placeholder="Type for hints…" />
              </div>

              <!-- Description -->
              <div class="form-field">
                <label>Description</label>
                <textarea v-model="form.description" class="input" rows="4" placeholder="Describe this goal…"></textarea>
              </div>

              <!-- Parent Goal -->
              <div class="form-field">
                <label>Parent Goal</label>
                <select v-model="form.parent_goal_id" class="input">
                  <option :value="null">None</option>
                  <option v-for="g in goals.filter(g => !editGoal || g.id !== editGoal.id)" :key="g.id" :value="g.id">{{ g.title }}</option>
                </select>
              </div>

              <!-- Due Date -->
              <div class="form-field">
                <label>Due Date <span class="req">*</span></label>
                <input v-model="form.due_date" type="date" class="input" />
              </div>

              <!-- Assigned To -->
              <div class="form-field">
                <label>Assigned To <span class="req">*</span></label>
                <div class="assignee-list">
                  <label v-for="e in employees.slice(0, 50)" :key="e.id" class="assignee-row">
                    <input type="checkbox" :checked="form.employee_ids.includes(e.id)" @change="toggleEmployee(e.id)" />
                    <span>{{ empName(e) }}</span>
                    <span class="emp-num" v-if="e.employee_number">{{ e.employee_number }}</span>
                    <span class="emp-role" v-if="e.designation?.title">{{ e.designation.title }}</span>
                  </label>
                  <p v-if="employees.length === 0" style="color:#94a3b8;font-size:0.8rem">Loading employees…</p>
                </div>
              </div>
            </div>

            <!-- Right column -->
            <div class="form-right">
              <!-- Completion ring -->
              <div class="completion-display">
                <svg :width="80" :height="80" viewBox="0 0 80 80">
                  <circle cx="40" cy="40" r="32" fill="none" stroke="#e2e8f0" stroke-width="8"/>
                  <circle cx="40" cy="40" r="32" fill="none"
                    :stroke="progressRing(form.completion, 80).color"
                    stroke-width="8" stroke-linecap="round"
                    :stroke-dasharray="progressRing(form.completion, 80).dashArray"
                    :stroke-dashoffset="progressRing(form.completion, 80).dashOffset"
                    transform="rotate(-90 40 40)"
                  />
                  <text x="40" y="46" text-anchor="middle" font-size="16" fill="#374151" font-weight="700">{{ Math.round(form.completion) }}%</text>
                </svg>
                <div class="completion-controls">
                  <button class="comp-btn" @click="form.completion = Math.max(0, form.completion - 5)">−</button>
                  <input v-model.number="form.completion" type="number" min="0" max="100" class="comp-input" />
                  <button class="comp-btn" @click="form.completion = Math.min(100, form.completion + 5)">+</button>
                </div>
                <span style="font-size:0.75rem;color:#64748b">Completion</span>
              </div>

              <!-- Level -->
              <div class="form-field">
                <label>Level</label>
                <select v-model="form.level" class="input">
                  <option value="functional_unit">Functional Unit</option>
                  <option value="department">Department</option>
                  <option value="business_unit">Business Unit</option>
                  <option value="practice">Practice</option>
                  <option value="individual">Individual</option>
                  <option value="organizational">Organizational</option>
                </select>
              </div>

              <!-- Priority -->
              <div class="form-field">
                <label>Priority</label>
                <select v-model="form.priority" class="input">
                  <option value="low">Low</option>
                  <option value="medium">Medium</option>
                  <option value="high">High</option>
                  <option value="critical">Critical</option>
                </select>
              </div>

              <!-- Status -->
              <div class="form-field">
                <label>Status</label>
                <select v-model="form.status" class="input">
                  <option value="pending">Pending</option>
                  <option value="in_progress">In Progress</option>
                  <option value="achieved">Achieved</option>
                  <option value="not_achieved">Not Achieved</option>
                  <option value="on_hold">On Hold</option>
                </select>
              </div>

              <!-- Weight -->
              <div class="form-field">
                <label>Weight <span class="req">*</span></label>
                <div style="display:flex;gap:8px;align-items:center">
                  <button class="comp-btn" @click="form.weight = Math.max(1, form.weight - 1)">−</button>
                  <input v-model.number="form.weight" type="number" min="1" max="100" class="input" style="width:70px;text-align:center" />
                  <button class="comp-btn" @click="form.weight = Math.min(100, form.weight + 1)">+</button>
                </div>
              </div>

              <!-- Library -->
              <div class="form-field" style="flex-direction:row;align-items:center;gap:8px">
                <input v-model="form.in_library" type="checkbox" id="inlib" />
                <label for="inlib" style="margin:0;cursor:pointer">Add to Goal Library</label>
              </div>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button class="btn btn-secondary" @click="showForm = false">Cancel</button>
          <button class="btn btn-primary" :disabled="saving" @click="saveGoal">
            {{ saving ? "Saving…" : "Save" }}
          </button>
        </div>
      </div>
    </div>

    <!-- ══ Add Key Result modal ══════════════════════════════════════════════ -->
    <div v-if="showKrForm" class="modal-backdrop" @click.self="showKrForm = false">
      <div class="modal-panel" style="max-width:520px">
        <div class="modal-header">
          <h3>Add Key Result</h3>
          <button class="modal-close" @click="showKrForm = false">✕</button>
        </div>
        <div class="modal-body">
          <div class="form-field">
            <label>Key Result Title <span class="req">*</span></label>
            <input v-model="krForm.title" type="text" class="input" />
          </div>
          <div style="display:flex;gap:12px">
            <div class="form-field" style="flex:1">
              <label>Due Date</label>
              <input v-model="krForm.due_date" type="date" class="input" />
            </div>
            <div class="form-field" style="flex:1">
              <label>Weight</label>
              <input v-model.number="krForm.weight" type="number" min="0" class="input" />
            </div>
          </div>
          <div class="form-field" style="flex-direction:row;align-items:center;gap:8px">
            <input v-model="krForm.use_metric" type="checkbox" id="useMetric" />
            <label for="useMetric" style="margin:0;cursor:pointer">Use numeric metric</label>
          </div>
          <template v-if="krForm.use_metric">
            <div style="display:flex;gap:12px">
              <div class="form-field" style="flex:1">
                <label>Target</label>
                <input v-model.number="krForm.target_value" type="number" class="input" />
              </div>
              <div class="form-field" style="flex:1">
                <label>Current</label>
                <input v-model.number="krForm.current_value" type="number" class="input" />
              </div>
              <div class="form-field" style="flex:1">
                <label>Format</label>
                <select v-model="krForm.metric_format" class="input">
                  <option value="number">Number</option>
                  <option value="percentage">Percentage</option>
                  <option value="currency">Currency</option>
                </select>
              </div>
            </div>
          </template>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" @click="showKrForm = false">Cancel</button>
          <button class="btn btn-primary" @click="saveKR">Save</button>
        </div>
      </div>
    </div>

  </div>
</template>

<style scoped>
/* ── Page shell ──────────────────────────────────────────────────────────── */
.goals-page { display:flex; flex-direction:column; gap:0; min-height:100%; }

.goals-header {
  display:flex; align-items:center; gap:16px; padding:20px 28px 0;
  flex-wrap:wrap;
}
.goals-title { font-size:1.4rem; font-weight:700; color:#1e293b; margin:0; flex:1; }

.goals-topnav { display:flex; gap:2px; background:#f1f5f9; border-radius:8px; padding:3px; }
.topnav-btn {
  background:none; border:none; padding:6px 18px; border-radius:6px;
  font-size:0.85rem; font-weight:500; color:#64748b; cursor:pointer; transition:all 0.15s;
}
.topnav-btn.active { background:#fff; color:#0f172a; font-weight:700; box-shadow:0 1px 3px rgba(0,0,0,.12); }

/* ── Layout ──────────────────────────────────────────────────────────────── */
.goals-layout { display:flex; gap:0; flex:1; padding:20px 28px; }

/* ── Status sidebar ──────────────────────────────────────────────────────── */
.status-sidebar {
  width:180px; flex-shrink:0; background:#fff; border:1px solid #e2e8f0;
  border-radius:10px; height:fit-content; overflow:hidden; margin-right:20px;
}
.status-item {
  display:flex; align-items:center; gap:10px; padding:10px 16px;
  cursor:pointer; border-bottom:1px solid #f1f5f9; transition:background 0.1s;
}
.status-item:last-child { border-bottom:none; }
.status-item:hover { background:#f8fafc; }
.status-item.active { background:#eff6ff; }
.status-count {
  min-width:24px; height:24px; border-radius:12px;
  display:flex; align-items:center; justify-content:center;
  font-size:0.75rem; font-weight:700; background:#e2e8f0; color:#374151;
}
.status-count-in_progress { background:#dbeafe; color:#1d4ed8; }
.status-count-achieved     { background:#dcfce7; color:#15803d; }
.status-count-not_achieved { background:#fee2e2; color:#dc2626; }
.status-count-on_hold      { background:#fef9c3; color:#a16207; }
.status-count-pending      { background:#f1f5f9; color:#374151; }
.status-label { font-size:0.82rem; color:#374151; }
.status-item.active .status-label { font-weight:600; color:#1d4ed8; }

/* ── Content ─────────────────────────────────────────────────────────────── */
.goals-content { flex:1; min-width:0; display:flex; flex-direction:column; gap:12px; }

.content-toolbar {
  display:flex; align-items:center; justify-content:space-between;
  flex-wrap:wrap; gap:12px;
}
.content-tabs { display:flex; gap:0; border-bottom:2px solid #e2e8f0; }
.ctab {
  display:flex; align-items:center; gap:6px;
  background:none; border:none; border-bottom:3px solid transparent;
  margin-bottom:-2px; padding:8px 20px;
  font-size:0.87rem; font-weight:500; color:#64748b; cursor:pointer; transition:all 0.15s;
}
.ctab.active { color:#ea580c; border-bottom-color:#ea580c; font-weight:700; }

.toolbar-right { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.search-input {
  border:1px solid #e2e8f0; border-radius:6px; padding:6px 12px;
  font-size:0.85rem; width:200px; outline:none;
}
.search-input:focus { border-color:#3b82f6; }
.btn-export {
  background:#fff; border:1px solid #e2e8f0; border-radius:6px;
  padding:5px 12px; font-size:0.78rem; font-weight:600; color:#64748b; cursor:pointer;
}
.btn-export:hover { background:#f8fafc; }

.result-count { font-size:0.82rem; color:#64748b; margin:0; }

/* ── Table ───────────────────────────────────────────────────────────────── */
.goals-table-wrap { overflow-x:auto; background:#fff; border:1px solid #e2e8f0; border-radius:10px; }
.goals-table { width:100%; border-collapse:collapse; font-size:0.83rem; }
.goals-table thead th {
  background:#f8fafc; padding:10px 12px; text-align:left;
  font-size:0.72rem; font-weight:700; letter-spacing:.04em; text-transform:uppercase;
  color:#64748b; border-bottom:2px solid #e2e8f0; white-space:nowrap;
}
.goal-row td { padding:10px 12px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
.goal-row:hover td { background:#fafafa; }
.kr-row td { padding:7px 12px; border-bottom:1px dashed #f1f5f9; background:#fafbfd; vertical-align:middle; }

.col-name   { min-width:280px; }
.col-num    { width:70px; text-align:center; }
.col-owner  { min-width:140px; }
.col-date   { width:110px; white-space:nowrap; }
.col-status { width:130px; }
.col-priority { width:90px; }

.goal-name-cell { display:flex; align-items:center; gap:8px; }
.kr-name-cell   { display:flex; align-items:center; gap:6px; padding-left:28px; }

.expand-btn {
  background:none; border:none; cursor:pointer; color:#94a3b8;
  font-size:0.7rem; padding:2px 4px; border-radius:3px;
}
.expand-btn:hover { background:#f1f5f9; }

/* Progress ring */
.ring-wrap { position:relative; cursor:pointer; flex-shrink:0; }
.completion-input {
  position:absolute; top:50%; left:50%; transform:translate(-50%,-50%);
  width:40px; height:20px; text-align:center; font-size:0.7rem;
  border:1px solid #3b82f6; border-radius:3px; background:#fff; z-index:2;
}

.goal-title {
  font-weight:600; color:#1e293b; cursor:pointer; flex:1;
  overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:340px;
}
.goal-title:hover { color:#2563eb; text-decoration:underline; }
.kr-title { font-size:0.82rem; color:#374151; flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:300px; }
.kr-metric-badge { font-size:0.72rem; background:#eff6ff; color:#1d4ed8; padding:1px 6px; border-radius:10px; white-space:nowrap; }

.level-icon { color:#64748b; display:flex; align-items:center; justify-content:center; }
.owner-chip { font-size:0.78rem; color:#374151; }
.owner-more { font-size:0.75rem; color:#94a3b8; margin-left:4px; }

/* Status select */
.status-select {
  border:none; background:none; font-size:0.8rem; font-weight:500; cursor:pointer;
  padding:3px 6px; border-radius:6px; outline:none; width:100%;
}
.status-sel-pending      { background:#f1f5f9; color:#475569; }
.status-sel-in_progress  { background:#dbeafe; color:#1d4ed8; }
.status-sel-achieved     { background:#dcfce7; color:#15803d; }
.status-sel-not_achieved { background:#fee2e2; color:#dc2626; }
.status-sel-on_hold      { background:#fef9c3; color:#a16207; }

/* Status pill (KRs) */
.status-pill { font-size:0.72rem; font-weight:600; padding:2px 8px; border-radius:10px; }
.status-pill-pending      { background:#f1f5f9; color:#475569; }
.status-pill-in_progress  { background:#dbeafe; color:#1d4ed8; }
.status-pill-achieved     { background:#dcfce7; color:#15803d; }
.status-pill-not_achieved { background:#fee2e2; color:#dc2626; }
.status-pill-on_hold      { background:#fef9c3; color:#a16207; }

/* Priority badges */
.priority-badge { font-size:0.72rem; font-weight:700; padding:3px 10px; border-radius:4px; white-space:nowrap; }
.priority-low      { background:#f0fdf4; color:#15803d; }
.priority-medium   { background:#fef9c3; color:#a16207; }
.priority-high     { background:#fff7ed; color:#c2410c; }
.priority-critical { background:#fef2f2; color:#dc2626; }

.row-actions { display:flex; gap:4px; justify-content:flex-end; opacity:0; transition:opacity 0.15s; }
.goal-row:hover .row-actions { opacity:1; }
.act-btn {
  background:#f1f5f9; border:none; border-radius:4px; padding:3px 7px;
  font-size:0.72rem; cursor:pointer; color:#64748b; white-space:nowrap;
}
.act-btn:hover { background:#e2e8f0; }
.act-del:hover { background:#fee2e2; color:#dc2626; }

/* ── Modal ───────────────────────────────────────────────────────────────── */
.modal-backdrop {
  position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:1000;
  display:flex; align-items:flex-start; justify-content:center; overflow-y:auto; padding:40px 20px;
}
.modal-panel {
  background:#fff; border-radius:14px; box-shadow:0 20px 60px rgba(0,0,0,.25);
  width:100%; max-width:820px; display:flex; flex-direction:column;
}
.modal-header {
  display:flex; align-items:center; justify-content:space-between;
  padding:20px 24px; border-bottom:1px solid #e2e8f0;
}
.modal-header h3 { font-size:1.05rem; font-weight:700; color:#1e293b; margin:0; }
.modal-close {
  background:none; border:none; font-size:1.2rem; cursor:pointer;
  color:#94a3b8; width:32px; height:32px; border-radius:6px;
}
.modal-close:hover { background:#f1f5f9; color:#1e293b; }
.modal-body { padding:24px; overflow-y:auto; max-height:70vh; }
.modal-footer {
  padding:16px 24px; border-top:1px solid #e2e8f0;
  display:flex; justify-content:flex-end; gap:10px;
}

.form-two-col { display:grid; grid-template-columns:1fr 240px; gap:24px; }
.form-left  { display:flex; flex-direction:column; gap:14px; }
.form-right { display:flex; flex-direction:column; gap:14px; align-items:stretch; }

.form-field { display:flex; flex-direction:column; gap:5px; }
.form-field label { font-size:0.82rem; font-weight:600; color:#374151; }
.req { color:#ef4444; }

.completion-display {
  display:flex; flex-direction:column; align-items:center; gap:8px;
  padding:16px; background:#f8fafc; border-radius:10px; border:1px solid #e2e8f0;
}
.completion-controls { display:flex; align-items:center; gap:6px; }
.comp-btn {
  width:28px; height:28px; border:1px solid #e2e8f0; border-radius:6px;
  background:#fff; cursor:pointer; font-size:1rem; color:#374151;
  display:flex; align-items:center; justify-content:center;
}
.comp-btn:hover { background:#f1f5f9; }
.comp-input { width:60px; text-align:center; border:1px solid #e2e8f0; border-radius:6px; padding:4px; font-size:0.85rem; }

.assignee-list {
  max-height:180px; overflow-y:auto; border:1px solid #e2e8f0; border-radius:6px;
  padding:6px 8px; display:flex; flex-direction:column; gap:4px; background:#fafafa;
}
.assignee-row {
  display:flex; align-items:center; gap:8px; padding:3px 4px; border-radius:4px;
  cursor:pointer; font-size:0.82rem; color:#374151;
}
.assignee-row:hover { background:#f1f5f9; }
.emp-num { font-size:0.72rem; color:#94a3b8; margin-left:auto; }
.emp-role { font-size:0.72rem; color:#6366f1; margin-left:6px; font-style:italic; }

@media (max-width: 768px) {
  .goals-layout   { flex-direction:column; padding:12px; }
  .status-sidebar { width:100%; margin-right:0; margin-bottom:12px; display:flex; flex-wrap:wrap; }
  .status-item    { flex:1; min-width:100px; }
  .form-two-col   { grid-template-columns:1fr; }
  .goals-header   { padding:16px; }
}
</style>
