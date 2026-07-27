<script setup lang="ts">
import { ref, onMounted, watch } from "vue";
import { api } from "../lib/api";
import { currentOrgSlug } from "../stores/auth";

interface Employee { id: number; first_name: string; last_name: string; employee_number: string }
interface Review   {
  id: number; employee: Employee; reviewer: Employee | null;
  review_cycle: string; review_date: string; rating: number;
  goals: string | null; comments: string | null; outcomes: string | null;
}
interface Training {
  id: number; employee: Employee; course_name: string;
  provider: string | null; completion_date: string; certification_no: string | null;
}
interface Promotion {
  id: number; employee: Employee; effective_date: string;
  from_designation: { title: string } | null; to_designation: { title: string } | null;
  from_job_grade:   { name: string }  | null; to_job_grade:   { name: string } | null;
  notes: string | null;
}

// ── OKR types ─────────────────────────────────────────────────────────────────
interface OkrDept      { id: number; name: string }
interface OkrDeptScore { department_id: number; department_name: string; score: number; weight: number }
interface OkrKR {
  id: number; title: string; measure: string | null;
  target_value: number; achievement: number | null;
  score_percent: number; weight: number; sort_order: number;
  dept_scores: Record<number, OkrDeptScore>;
}
interface OkrObjective {
  id: number; title: string; description: string | null;
  weight: number; sort_order: number; score: number;
  key_results: OkrKR[];
}
interface OkrCycle {
  id: number; name: string; year: number; period: string; status: string;
  objectives_count?: number;
}
interface OkrScorecard {
  cycle: OkrCycle;
  departments: OkrDept[];
  objectives: OkrObjective[];
}

// ── State ─────────────────────────────────────────────────────────────────────
const tab        = ref<"reviews" | "training" | "promotions" | "okr">("reviews");
const reviews    = ref<Review[]>([]);
const trainings  = ref<Training[]>([]);
const promotions = ref<Promotion[]>([]);
const employees  = ref<Employee[]>([]);
const loading    = ref(false);
const error      = ref("");
const saving     = ref(false);
const saveError  = ref("");
const showForm   = ref(false);

// Review form
const revForm = ref({ employee_id: "", review_cycle: "", review_date: "", rating: 3, goals: "", comments: "", outcomes: "" });
// Training form
const traForm = ref({ employee_id: "", course_name: "", provider: "", completion_date: "", certification_no: "", notes: "" });

// ── OKR state ─────────────────────────────────────────────────────────────────
const okrTab        = ref<"scorecard" | "cycles">("scorecard");
const cycles        = ref<OkrCycle[]>([]);
const scorecard     = ref<OkrScorecard | null>(null);
const selectedCycle = ref<number | null>(null);
const okrLoading    = ref(false);
const okrError      = ref("");
const okrSaving     = ref(false);

// New cycle form
const showCycleForm = ref(false);
const cycleForm     = ref({ name: "", year: new Date().getFullYear(), period: "annual", status: "active" });

// Inline editing
const editingAch  = ref<number | null>(null);  // KR id being edited (achievement)
const editingDept = ref<{ krId: number; deptId: number } | null>(null);
const inlineVal   = ref<number>(0);

function org(): string | null { return currentOrgSlug.value; }
const empName = (e: Employee) => `${e.first_name} ${e.last_name}`;

// ── Performance load ──────────────────────────────────────────────────────────
async function load(): Promise<void> {
  if (!org()) { error.value = "Select an organisation first."; return; }
  loading.value = true; error.value = "";
  try {
    const [rv, tr, pr, emps] = await Promise.all([
      api<{ data: Review[] }>("GET", "/api/v1/performance/reviews",   { org: org() }),
      api<{ data: Training[] }>("GET", "/api/v1/performance/training", { org: org() }),
      api<{ data: Promotion[] }>("GET", "/api/v1/performance/promotions", { org: org() }),
      api<{ data: Employee[] }>("GET", "/api/v1/employees", { org: org(), params: { per_page: 200 } }),
    ]);
    reviews.value    = rv.data;
    trainings.value  = tr.data;
    promotions.value = pr.data;
    employees.value  = (emps as any).data as Employee[];
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to load.";
  } finally { loading.value = false; }
}

const stars = (n: number) => "★".repeat(n) + "☆".repeat(5 - n);

async function saveReview(): Promise<void> {
  saving.value = true; saveError.value = "";
  try {
    await api("POST", "/api/v1/performance/reviews", { org: org(), body: { ...revForm.value, employee_id: Number(revForm.value.employee_id), rating: Number(revForm.value.rating) } });
    revForm.value = { employee_id: "", review_cycle: "", review_date: "", rating: 3, goals: "", comments: "", outcomes: "" };
    showForm.value = false;
    await load();
  } catch (e) { saveError.value = e instanceof Error ? e.message : "Failed."; }
  finally { saving.value = false; }
}

async function deleteReview(id: number): Promise<void> {
  if (!confirm("Delete this review?")) return;
  await api("DELETE", `/api/v1/performance/reviews/${id}`, { org: org() });
  await load();
}

async function saveTraining(): Promise<void> {
  saving.value = true; saveError.value = "";
  try {
    await api("POST", "/api/v1/performance/training", { org: org(), body: { ...traForm.value, employee_id: Number(traForm.value.employee_id) } });
    traForm.value = { employee_id: "", course_name: "", provider: "", completion_date: "", certification_no: "", notes: "" };
    showForm.value = false;
    await load();
  } catch (e) { saveError.value = e instanceof Error ? e.message : "Failed."; }
  finally { saving.value = false; }
}

async function deleteTraining(id: number): Promise<void> {
  if (!confirm("Delete this record?")) return;
  await api("DELETE", `/api/v1/performance/training/${id}`, { org: org() });
  await load();
}

// ── OKR functions ─────────────────────────────────────────────────────────────
async function loadCycles(): Promise<void> {
  okrLoading.value = true; okrError.value = "";
  try {
    const res = await api<{ data: OkrCycle[] }>("GET", "/api/v1/okr/cycles", { org: org() });
    cycles.value = res.data;
    if (cycles.value.length && !selectedCycle.value) {
      // Auto-select first active cycle
      const active = cycles.value.find(c => c.status === "active") ?? cycles.value[0];
      selectedCycle.value = active.id;
      await loadScorecard(active.id);
    }
  } catch (e) { okrError.value = e instanceof Error ? e.message : "Failed to load cycles."; }
  finally { okrLoading.value = false; }
}

async function loadScorecard(cycleId: number): Promise<void> {
  okrLoading.value = true;
  try {
    scorecard.value = await api<OkrScorecard>("GET", `/api/v1/okr/cycles/${cycleId}`, { org: org() });
  } catch (e) { okrError.value = e instanceof Error ? e.message : "Failed to load scorecard."; }
  finally { okrLoading.value = false; }
}

async function saveCycle(): Promise<void> {
  okrSaving.value = true;
  try {
    await api("POST", "/api/v1/okr/cycles", { org: org(), body: { ...cycleForm.value } });
    showCycleForm.value = false;
    cycleForm.value = { name: "", year: new Date().getFullYear(), period: "annual", status: "active" };
    await loadCycles();
  } catch (e) { okrError.value = e instanceof Error ? e.message : "Failed to create cycle."; }
  finally { okrSaving.value = false; }
}

async function selectCycle(id: number): Promise<void> {
  selectedCycle.value = id;
  await loadScorecard(id);
}

// ── Inline achievement editing ────────────────────────────────────────────────
function startEditAch(kr: OkrKR): void {
  editingAch.value  = kr.id;
  editingDept.value = null;
  inlineVal.value   = kr.achievement ?? 0;
}

async function saveAch(kr: OkrKR): Promise<void> {
  if (editingAch.value !== kr.id) return;
  editingAch.value = null;
  try {
    const res = await api<{ score_percent: number }>("PUT", `/api/v1/okr/key-results/${kr.id}/achievement`, {
      org: org(), body: { achievement: inlineVal.value },
    });
    kr.achievement   = inlineVal.value;
    kr.score_percent = res.score_percent;
    // Update objective score
    recomputeObjScore(kr);
  } catch (e) { okrError.value = "Failed to save achievement."; }
}

// ── Inline dept score editing ─────────────────────────────────────────────────
function startEditDept(kr: OkrKR, deptId: number): void {
  editingDept.value = { krId: kr.id, deptId };
  editingAch.value  = null;
  inlineVal.value   = kr.dept_scores[deptId]?.score ?? 0;
}

async function saveDeptScore(kr: OkrKR, deptId: number, deptName: string): Promise<void> {
  if (!editingDept.value || editingDept.value.krId !== kr.id || editingDept.value.deptId !== deptId) return;
  editingDept.value = null;
  try {
    const res = await api<{ score_percent: number }>("POST", `/api/v1/okr/key-results/${kr.id}/dept-scores`, {
      org: org(), body: { scores: [{ department_id: deptId, score: inlineVal.value, weight: 1 }] },
    });
    if (!kr.dept_scores[deptId]) {
      kr.dept_scores[deptId] = { department_id: deptId, department_name: deptName, score: inlineVal.value, weight: 1 };
    } else {
      kr.dept_scores[deptId].score = inlineVal.value;
    }
    kr.score_percent = res.score_percent;
    recomputeObjScore(kr);
  } catch (e) { okrError.value = "Failed to save dept score."; }
}

function recomputeObjScore(kr: OkrKR): void {
  if (!scorecard.value) return;
  for (const obj of scorecard.value.objectives) {
    const match = obj.key_results.find(k => k.id === kr.id);
    if (match) {
      const totalW = obj.key_results.reduce((a, k) => a + k.weight, 0);
      obj.score    = totalW > 0
        ? parseFloat((obj.key_results.reduce((a, k) => a + k.score_percent * k.weight, 0) / totalW).toFixed(2))
        : 0;
      break;
    }
  }
}

function scoreBand(s: number): string {
  if (s >= 80) return "score-green";
  if (s >= 60) return "score-amber";
  return "score-red";
}

function deptAvgScore(kr: OkrKR): number {
  const vals = Object.values(kr.dept_scores);
  if (!vals.length) return kr.score_percent;
  return parseFloat((vals.reduce((a, d) => a + d.score, 0) / vals.length).toFixed(2));
}

function deptWeightedScore(kr: OkrKR): number {
  const vals = Object.values(kr.dept_scores);
  if (!vals.length) return kr.score_percent;
  const totalW = vals.reduce((a, d) => a + d.weight, 0);
  return totalW > 0
    ? parseFloat((vals.reduce((a, d) => a + d.score * d.weight, 0) / totalW).toFixed(2))
    : 0;
}

onMounted(load);
watch(currentOrgSlug, () => { load(); if (tab.value === "okr") loadCycles(); });
watch(tab, (v) => {
  showForm.value = false; saveError.value = "";
  if (v === "okr") loadCycles();
});
</script>

<template>
  <div class="view">
    <div class="view-header">
      <h2>Performance</h2>
      <router-link :to="{ name: 'hr-dashboard' }" class="btn btn-secondary btn-sm">← Dashboard</router-link>
    </div>

    <p v-if="loading" class="status">Loading…</p>
    <p v-if="error"   class="error">{{ error }}</p>
    <p v-if="saveError" class="error">{{ saveError }}</p>

    <!-- Tab bar -->
    <div class="tabs" style="margin-bottom:20px">
      <button :class="['tab', { active: tab === 'reviews' }]"    @click="tab = 'reviews'">Reviews</button>
      <button :class="['tab', { active: tab === 'training' }]"   @click="tab = 'training'">Training</button>
      <button :class="['tab', { active: tab === 'promotions' }]" @click="tab = 'promotions'">Promotions</button>
      <button :class="['tab', { active: tab === 'okr' }]"        @click="tab = 'okr'">📊 OKRs</button>
    </div>

    <!-- ── REVIEWS ──────────────────────────────────────────────────────── -->
    <template v-if="tab === 'reviews'">
      <div class="view-header" style="margin-bottom:16px">
        <span></span>
        <button class="btn btn-primary btn-sm" @click="showForm = !showForm">+ Add Review</button>
      </div>
      <div v-if="showForm" class="card" style="margin-bottom:16px">
        <h3 class="card-title">New Performance Review</h3>
        <div class="form-grid">
          <div class="form-field">
            <label>Employee</label>
            <select v-model="revForm.employee_id" class="input">
              <option value="">Select employee…</option>
              <option v-for="e in employees" :key="e.id" :value="e.id">{{ empName(e) }} ({{ e.employee_number }})</option>
            </select>
          </div>
          <div class="form-field"><label>Review Cycle</label><input v-model="revForm.review_cycle" type="text" placeholder="e.g. Q1 2026" class="input" /></div>
          <div class="form-field"><label>Review Date</label><input v-model="revForm.review_date" type="date" class="input" /></div>
          <div class="form-field"><label>Rating (1–5)</label><input v-model.number="revForm.rating" type="number" min="1" max="5" class="input" style="max-width:80px" /></div>
          <div class="form-field" style="grid-column:span 2"><label>Goals</label><textarea v-model="revForm.goals" rows="2" class="input"></textarea></div>
          <div class="form-field" style="grid-column:span 2"><label>Comments</label><textarea v-model="revForm.comments" rows="2" class="input"></textarea></div>
          <div class="form-field" style="grid-column:span 2"><label>Outcomes</label><textarea v-model="revForm.outcomes" rows="2" class="input"></textarea></div>
        </div>
        <div style="margin-top:12px">
          <button class="btn btn-primary btn-sm" :disabled="saving" @click="saveReview">Save Review</button>
          <button class="btn btn-secondary btn-sm" style="margin-left:8px" @click="showForm = false">Cancel</button>
        </div>
      </div>
      <div class="card">
        <table>
          <thead><tr><th>Employee</th><th>Cycle</th><th>Date</th><th>Rating</th><th>Comments</th><th></th></tr></thead>
          <tbody>
            <tr v-for="r in reviews" :key="r.id">
              <td>{{ empName(r.employee) }}<br><small class="text-muted">{{ r.employee.employee_number }}</small></td>
              <td>{{ r.review_cycle }}</td><td>{{ r.review_date }}</td>
              <td style="color:#f59e0b;font-size:1.1rem">{{ stars(r.rating) }}</td>
              <td style="max-width:200px;white-space:pre-wrap">{{ r.comments ?? "—" }}</td>
              <td style="text-align:right"><button class="btn btn-sm" style="color:var(--red)" @click="deleteReview(r.id)">Delete</button></td>
            </tr>
            <tr v-if="!reviews.length"><td colspan="6" class="status">No reviews yet.</td></tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- ── TRAINING ─────────────────────────────────────────────────────── -->
    <template v-if="tab === 'training'">
      <div class="view-header" style="margin-bottom:16px">
        <span></span>
        <button class="btn btn-primary btn-sm" @click="showForm = !showForm">+ Add Training</button>
      </div>
      <div v-if="showForm" class="card" style="margin-bottom:16px">
        <h3 class="card-title">New Training Record</h3>
        <div class="form-grid">
          <div class="form-field">
            <label>Employee</label>
            <select v-model="traForm.employee_id" class="input">
              <option value="">Select employee…</option>
              <option v-for="e in employees" :key="e.id" :value="e.id">{{ empName(e) }}</option>
            </select>
          </div>
          <div class="form-field"><label>Course Name</label><input v-model="traForm.course_name" type="text" class="input" /></div>
          <div class="form-field"><label>Provider</label><input v-model="traForm.provider" type="text" class="input" /></div>
          <div class="form-field"><label>Completion Date</label><input v-model="traForm.completion_date" type="date" class="input" /></div>
          <div class="form-field"><label>Certificate No.</label><input v-model="traForm.certification_no" type="text" class="input" /></div>
        </div>
        <div style="margin-top:12px">
          <button class="btn btn-primary btn-sm" :disabled="saving" @click="saveTraining">Save</button>
          <button class="btn btn-secondary btn-sm" style="margin-left:8px" @click="showForm = false">Cancel</button>
        </div>
      </div>
      <div class="card">
        <table>
          <thead><tr><th>Employee</th><th>Course</th><th>Provider</th><th>Completed</th><th>Cert No.</th><th></th></tr></thead>
          <tbody>
            <tr v-for="t in trainings" :key="t.id">
              <td>{{ empName(t.employee) }}</td><td>{{ t.course_name }}</td>
              <td>{{ t.provider ?? "—" }}</td><td>{{ t.completion_date }}</td><td>{{ t.certification_no ?? "—" }}</td>
              <td style="text-align:right"><button class="btn btn-sm" style="color:var(--red)" @click="deleteTraining(t.id)">Delete</button></td>
            </tr>
            <tr v-if="!trainings.length"><td colspan="6" class="status">No training records yet.</td></tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- ── PROMOTIONS ───────────────────────────────────────────────────── -->
    <template v-if="tab === 'promotions'">
      <div class="card">
        <table>
          <thead><tr><th>Employee</th><th>From</th><th>To</th><th>Effective Date</th><th>Notes</th></tr></thead>
          <tbody>
            <tr v-for="p in promotions" :key="p.id">
              <td>{{ empName(p.employee) }}<br><small class="text-muted">{{ p.employee.employee_number }}</small></td>
              <td>{{ p.from_designation?.title ?? "—" }}<br><small v-if="p.from_job_grade" class="text-muted">{{ p.from_job_grade.name }}</small></td>
              <td>{{ p.to_designation?.title ?? "—" }}<br><small v-if="p.to_job_grade" class="text-muted">{{ p.to_job_grade.name }}</small></td>
              <td>{{ p.effective_date }}</td><td>{{ p.notes ?? "—" }}</td>
            </tr>
            <tr v-if="!promotions.length"><td colspan="5" class="status">No promotions recorded yet.</td></tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- ── OKRs ─────────────────────────────────────────────────────────── -->
    <template v-if="tab === 'okr'">
      <p v-if="okrError" class="error">{{ okrError }}</p>

      <!-- OKR Sub-tabs -->
      <div class="okr-subtabs">
        <button :class="['okr-stab', { 'okr-stab-active': okrTab === 'scorecard' }]" @click="okrTab = 'scorecard'">Scorecard</button>
        <button :class="['okr-stab', { 'okr-stab-active': okrTab === 'cycles'    }]" @click="okrTab = 'cycles'">Cycles</button>
      </div>

      <!-- ── CYCLES ─────────────────────────────────────────────────────── -->
      <div v-if="okrTab === 'cycles'" class="okr-section">
        <div class="okr-toolbar">
          <h3 class="okr-section-title">OKR Cycles</h3>
          <button class="btn btn-primary btn-sm" @click="showCycleForm = !showCycleForm">+ New Cycle</button>
        </div>

        <div v-if="showCycleForm" class="card" style="margin-bottom:16px">
          <h4 class="card-title">New OKR Cycle</h4>
          <div class="form-grid">
            <div class="form-field">
              <label>Cycle Name</label>
              <input v-model="cycleForm.name" type="text" class="input" placeholder="e.g. Jan–Dec 2026" />
            </div>
            <div class="form-field">
              <label>Year</label>
              <input v-model.number="cycleForm.year" type="number" class="input" min="2020" max="2100" />
            </div>
            <div class="form-field">
              <label>Period</label>
              <select v-model="cycleForm.period" class="input">
                <option value="annual">Annual</option>
                <option value="q1">Q1</option>
                <option value="q2">Q2</option>
                <option value="q3">Q3</option>
                <option value="q4">Q4</option>
              </select>
            </div>
            <div class="form-field">
              <label>Status</label>
              <select v-model="cycleForm.status" class="input">
                <option value="draft">Draft</option>
                <option value="active">Active</option>
                <option value="closed">Closed</option>
              </select>
            </div>
          </div>
          <div style="margin-top:12px;display:flex;gap:8px">
            <button class="btn btn-primary btn-sm" :disabled="okrSaving" @click="saveCycle">{{ okrSaving ? "Saving…" : "Save Cycle" }}</button>
            <button class="btn btn-secondary btn-sm" @click="showCycleForm = false">Cancel</button>
          </div>
        </div>

        <div class="card">
          <table>
            <thead><tr><th>Name</th><th>Year</th><th>Period</th><th>Status</th><th>Objectives</th><th></th></tr></thead>
            <tbody>
              <tr v-for="c in cycles" :key="c.id">
                <td style="font-weight:600">{{ c.name }}</td>
                <td>{{ c.year }}</td>
                <td style="text-transform:uppercase">{{ c.period }}</td>
                <td>
                  <span :class="['badge', c.status === 'active' ? 'badge-green' : c.status === 'closed' ? 'badge-gray' : 'badge-yellow']">
                    {{ c.status }}
                  </span>
                </td>
                <td>{{ c.objectives_count ?? "—" }}</td>
                <td style="text-align:right">
                  <button class="btn btn-sm btn-primary" @click="okrTab = 'scorecard'; selectCycle(c.id)">View Scorecard</button>
                </td>
              </tr>
              <tr v-if="!cycles.length"><td colspan="6" class="status">No cycles yet. Create one above.</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ── SCORECARD ─────────────────────────────────────────────────── -->
      <div v-if="okrTab === 'scorecard'" class="okr-section">
        <!-- Cycle selector -->
        <div class="okr-toolbar">
          <h3 class="okr-section-title">OKR Scorecard</h3>
          <div style="display:flex;gap:8px;align-items:center">
            <select v-model="selectedCycle" class="input" style="min-width:200px" @change="selectedCycle && loadScorecard(selectedCycle)">
              <option :value="null" disabled>Select cycle…</option>
              <option v-for="c in cycles" :key="c.id" :value="c.id">{{ c.name }} ({{ c.year }})</option>
            </select>
          </div>
        </div>

        <p v-if="okrLoading" class="status">Loading scorecard…</p>

        <!-- No cycles -->
        <div v-if="!okrLoading && !cycles.length" class="card" style="text-align:center;padding:40px">
          <p style="color:#94a3b8;margin-bottom:16px">No OKR cycles found. Create one first.</p>
          <button class="btn btn-primary" @click="okrTab = 'cycles'; showCycleForm = true">Create First Cycle</button>
        </div>

        <div v-if="scorecard && !okrLoading">

          <!-- Legend -->
          <div class="okr-legend">
            <span class="score-green okr-legend-pill">≥ 80% On Track</span>
            <span class="score-amber okr-legend-pill">60–79% At Risk</span>
            <span class="score-red   okr-legend-pill">&lt; 60% Off Track</span>
          </div>

          <!-- Objective summary bars -->
          <div class="okr-summary-grid">
            <div v-for="obj in scorecard.objectives" :key="obj.id" class="okr-summary-card">
              <div class="okr-summary-header">
                <span class="okr-summary-title">{{ obj.title }}</span>
                <span :class="['okr-score-badge', scoreBand(obj.score)]">{{ obj.score.toFixed(1) }}%</span>
              </div>
              <div class="okr-progress-bar">
                <div :class="['okr-progress-fill', scoreBand(obj.score)]" :style="{ width: Math.min(100, obj.score) + '%' }"></div>
              </div>
              <div class="okr-summary-meta">Weight: {{ obj.weight }}% · {{ obj.key_results.length }} KRs</div>
            </div>
          </div>

          <!-- Matrix table -->
          <div class="okr-matrix-wrap">
            <table class="okr-matrix">
              <thead>
                <tr>
                  <th class="okr-col-obj">OBJECTIVES / KEY RESULTS</th>
                  <th class="okr-col-w">KR Weight</th>
                  <th class="okr-col-m">Measure</th>
                  <th class="okr-col-a">Achievement</th>
                  <th class="okr-col-s">Score %</th>
                  <th v-for="dept in scorecard.departments" :key="dept.id" class="okr-col-dept">{{ dept.name }}</th>
                  <th class="okr-col-avg">Avg Dept</th>
                  <th class="okr-col-wt">Wtd Score</th>
                </tr>
              </thead>
              <tbody>
                <template v-for="obj in scorecard.objectives" :key="obj.id">
                  <!-- Objective header row -->
                  <tr class="okr-obj-row">
                    <td colspan="2">
                      <span class="okr-obj-title">{{ obj.title }}</span>
                      <span class="okr-obj-weight">Obj Weight: {{ obj.weight }}%</span>
                    </td>
                    <td></td>
                    <td></td>
                    <td :class="['okr-score-cell', scoreBand(obj.score)]">{{ obj.score.toFixed(1) }}%</td>
                    <td v-for="dept in scorecard.departments" :key="dept.id"></td>
                    <td></td>
                    <td></td>
                  </tr>

                  <!-- KR rows -->
                  <tr v-for="kr in obj.key_results" :key="kr.id" class="okr-kr-row">
                    <!-- KR title -->
                    <td class="okr-kr-title-cell">{{ kr.title }}</td>

                    <!-- KR weight -->
                    <td class="okr-num-cell">{{ kr.weight }}%</td>

                    <!-- Measure -->
                    <td class="okr-measure-cell">{{ kr.measure ?? "—" }}</td>

                    <!-- Achievement (inline editable) -->
                    <td class="okr-num-cell okr-editable" @click="startEditAch(kr)">
                      <template v-if="editingAch === kr.id">
                        <input
                          v-model.number="inlineVal"
                          type="number"
                          min="0"
                          class="okr-inline-input"
                          @blur="saveAch(kr)"
                          @keyup.enter="saveAch(kr)"
                          @keyup.esc="editingAch = null"
                          v-focus
                        />
                      </template>
                      <template v-else>
                        {{ kr.achievement != null ? kr.achievement : "—" }}
                        <span class="okr-edit-hint">✎</span>
                      </template>
                    </td>

                    <!-- Score % -->
                    <td :class="['okr-score-cell', scoreBand(kr.score_percent)]">
                      {{ kr.score_percent.toFixed(1) }}%
                    </td>

                    <!-- Dept score cells (inline editable) -->
                    <td
                      v-for="dept in scorecard.departments"
                      :key="dept.id"
                      class="okr-num-cell okr-editable"
                      @click="startEditDept(kr, dept.id)"
                    >
                      <template v-if="editingDept?.krId === kr.id && editingDept?.deptId === dept.id">
                        <input
                          v-model.number="inlineVal"
                          type="number"
                          min="0"
                          max="100"
                          class="okr-inline-input"
                          @blur="saveDeptScore(kr, dept.id, dept.name)"
                          @keyup.enter="saveDeptScore(kr, dept.id, dept.name)"
                          @keyup.esc="editingDept = null"
                          v-focus
                        />
                      </template>
                      <template v-else>
                        <span :class="kr.dept_scores[dept.id] ? scoreBand(kr.dept_scores[dept.id].score) : ''">
                          {{ kr.dept_scores[dept.id] ? kr.dept_scores[dept.id].score.toFixed(1) + "%" : "—" }}
                        </span>
                        <span class="okr-edit-hint">✎</span>
                      </template>
                    </td>

                    <!-- Avg dept score -->
                    <td :class="['okr-score-cell', Object.keys(kr.dept_scores).length ? scoreBand(deptAvgScore(kr)) : '']">
                      {{ Object.keys(kr.dept_scores).length ? deptAvgScore(kr).toFixed(1) + "%" : "—" }}
                    </td>

                    <!-- Weighted dept score -->
                    <td :class="['okr-score-cell', Object.keys(kr.dept_scores).length ? scoreBand(deptWeightedScore(kr)) : '']">
                      {{ Object.keys(kr.dept_scores).length ? deptWeightedScore(kr).toFixed(1) + "%" : "—" }}
                    </td>
                  </tr>
                </template>

                <tr v-if="!scorecard.objectives.length">
                  <td :colspan="5 + (scorecard.departments.length || 0) + 2" class="status">
                    No objectives found. Add objectives via the API or import from Excel.
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
/* ── OKR layout ──────────────────────────────────────────────────────────── */
.okr-subtabs { display:flex; gap:4px; margin-bottom:20px; border-bottom:2px solid #e2e8f0; }
.okr-stab { background:none; border:none; border-bottom:2px solid transparent; margin-bottom:-2px; padding:8px 18px; font-size:0.88rem; font-weight:500; color:#64748b; cursor:pointer; transition:all 0.15s; }
.okr-stab:hover { color:#1e293b; }
.okr-stab-active { color:var(--color-brand-base); border-bottom-color:var(--color-brand-base); font-weight:700; }

.okr-section { display:flex; flex-direction:column; gap:16px; }
.okr-toolbar { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; }
.okr-section-title { font-size:1rem; font-weight:700; color:#1e293b; margin:0; }

/* ── Legend ──────────────────────────────────────────────────────────────── */
.okr-legend { display:flex; gap:8px; flex-wrap:wrap; }
.okr-legend-pill { font-size:0.72rem; font-weight:600; padding:3px 10px; border-radius:20px; }

/* ── Score bands ─────────────────────────────────────────────────────────── */
.score-green { background:#f0fdf4; color:#166534; border:1px solid #86efac; }
.score-amber { background:#fffbeb; color:#92400e; border:1px solid #fcd34d; }
.score-red   { background:#fff7f7; color:#991b1b; border:1px solid #fca5a5; }

/* ── Summary cards ───────────────────────────────────────────────────────── */
.okr-summary-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:12px; }
.okr-summary-card { background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:14px 16px; }
.okr-summary-header { display:flex; justify-content:space-between; align-items:flex-start; gap:8px; margin-bottom:10px; }
.okr-summary-title { font-size:0.82rem; font-weight:600; color:#1e293b; flex:1; line-height:1.3; }
.okr-score-badge { font-size:0.78rem; font-weight:700; padding:3px 8px; border-radius:12px; flex-shrink:0; }
.okr-progress-bar { height:6px; background:#f1f5f9; border-radius:3px; overflow:hidden; margin-bottom:6px; }
.okr-progress-fill { height:100%; border-radius:3px; transition:width 0.4s; }
.okr-progress-fill.score-green { background:#22c55e; border:none; }
.okr-progress-fill.score-amber { background:#f59e0b; border:none; }
.okr-progress-fill.score-red   { background:#ef4444; border:none; }
.okr-summary-meta { font-size:0.72rem; color:#94a3b8; }

/* ── Matrix table ────────────────────────────────────────────────────────── */
.okr-matrix-wrap { overflow-x:auto; border:1px solid #e2e8f0; border-radius:10px; background:#fff; }
.okr-matrix { width:100%; border-collapse:collapse; font-size:0.82rem; }
.okr-matrix thead th { background:#f8fafc; color:#64748b; font-size:0.7rem; font-weight:700; letter-spacing:0.05em; text-transform:uppercase; padding:10px 10px; border-bottom:2px solid #e2e8f0; white-space:nowrap; }
.okr-col-obj  { min-width:220px; text-align:left; }
.okr-col-w    { width:80px; text-align:center; }
.okr-col-m    { min-width:120px; }
.okr-col-a    { width:100px; text-align:center; }
.okr-col-s    { width:80px; text-align:center; }
.okr-col-dept { min-width:90px; text-align:center; }
.okr-col-avg  { width:80px; text-align:center; }
.okr-col-wt   { width:90px; text-align:center; }

/* Objective row */
.okr-obj-row td { background:#f8fafc; padding:10px 10px; border-top:2px solid #e2e8f0; }
.okr-obj-title  { font-weight:700; font-size:0.85rem; color:#1e293b; margin-right:10px; }
.okr-obj-weight { font-size:0.72rem; color:#94a3b8; }

/* KR row */
.okr-kr-row td  { padding:8px 10px; border-bottom:1px solid #f8fafc; vertical-align:middle; }
.okr-kr-row:hover td { background:#fafafa; }
.okr-kr-title-cell { color:#374151; padding-left:24px !important; }
.okr-num-cell   { text-align:center; font-family:ui-monospace,monospace; font-size:0.8rem; }
.okr-measure-cell { color:#64748b; font-size:0.8rem; }
.okr-score-cell { text-align:center; font-weight:700; font-size:0.8rem; padding:4px 8px; }

/* Inline editing */
.okr-editable   { cursor:pointer; position:relative; }
.okr-editable:hover .okr-edit-hint { opacity:1; }
.okr-edit-hint  { font-size:0.65rem; color:#94a3b8; margin-left:4px; opacity:0; transition:opacity 0.15s; }
.okr-inline-input { width:70px; border:1px solid var(--color-brand-base); border-radius:4px; padding:2px 6px; font-size:0.8rem; text-align:center; font-family:ui-monospace,monospace; outline:none; }

@media (max-width:768px) {
  .okr-summary-grid { grid-template-columns:1fr; }
}
</style>
