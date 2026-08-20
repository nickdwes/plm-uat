<script setup lang="ts">
import { ref, reactive, computed, onMounted, watch } from "vue";
import { api } from "../lib/api";
import { currentOrgSlug } from "../stores/auth";

// ── Types ──────────────────────────────────────────────────────────────────────

interface OkrCycle {
  id: number; name: string; year: number; period: string;
  status: string; objectives_count: number;
}

interface OkrKeyResult {
  id: number; title: string; measure: string | null;
  target_value: number; achievement: number | null;
  score_percent: number; weight: number;
}

interface OkrObjective {
  id: number; title: string; description: string | null;
  weight: number; score_percent: number; key_results: OkrKeyResult[];
}

interface CycleDetail {
  cycle: OkrCycle;
  departments: { id: number; name: string }[];
  objectives: OkrObjective[];
}

// ── State ──────────────────────────────────────────────────────────────────────

const cycles        = ref<OkrCycle[]>([]);
const selected      = ref<CycleDetail | null>(null);
const loading       = ref(false);
const detailLoading = ref(false);
const error         = ref("");

// cycle CRUD
const showCycleForm = ref(false);
const savingCycle   = ref(false);
const editCycleId   = ref<number | null>(null);
const cycleFormErr  = ref("");
const cycleForm     = reactive({ name: "", year: new Date().getFullYear(), period: "annual", status: "draft" });

// objective CRUD
const showObjForm   = ref(false);
const savingObj     = ref(false);
const editObjId     = ref<number | null>(null);
const objForm       = reactive({ title: "", description: "", weight: 1 });

// key result CRUD
const showKrForm    = ref<number | null>(null); // objective id
const savingKr      = ref(false);
const krForm        = reactive({ title: "", measure: "", target_value: 100, weight: 1 });

// achievement inline edit
const achEdit       = reactive<Record<number, string>>({});
const achSaving     = reactive<Record<number, boolean>>({});

// expanded objectives
const expanded      = ref<Set<number>>(new Set());

function org(): string | null { return currentOrgSlug.value; }

// ── API calls ──────────────────────────────────────────────────────────────────

async function loadCycles(): Promise<void> {
  if (!org()) { error.value = "Select an organisation first."; return; }
  loading.value = true; error.value = "";
  try {
    const res = await api<{ data: OkrCycle[] }>("GET", "/api/v1/okr/cycles", { org: org() });
    cycles.value = res.data;
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to load OKR cycles.";
  } finally {
    loading.value = false;
  }
}

async function loadCycle(id: number): Promise<void> {
  detailLoading.value = true;
  try {
    const res = await api<CycleDetail>("GET", `/api/v1/okr/cycles/${id}`, { org: org() });
    selected.value = res;
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to load cycle detail.";
  } finally {
    detailLoading.value = false;
  }
}

async function saveCycle(): Promise<void> {
  cycleFormErr.value = "";
  savingCycle.value = true;
  try {
    if (editCycleId.value) {
      const updated = await api<OkrCycle>("PUT", `/api/v1/okr/cycles/${editCycleId.value}`, { org: org(), body: cycleForm });
      const idx = cycles.value.findIndex(c => c.id === editCycleId.value);
      if (idx !== -1) cycles.value[idx] = { ...cycles.value[idx], ...updated };
      if (selected.value?.cycle.id === editCycleId.value) selected.value.cycle = { ...selected.value.cycle, ...updated };
    } else {
      await api("POST", "/api/v1/okr/cycles", { org: org(), body: cycleForm });
      await loadCycles();
    }
    showCycleForm.value = false;
  } catch (e) {
    cycleFormErr.value = e instanceof Error ? e.message : "Failed to save cycle.";
  } finally {
    savingCycle.value = false;
  }
}

async function deleteCycle(id: number): Promise<void> {
  if (!confirm("Delete this OKR cycle? This will remove all objectives and key results.")) return;
  try {
    await api("DELETE", `/api/v1/okr/cycles/${id}`, { org: org() });
    cycles.value = cycles.value.filter(c => c.id !== id);
    if (selected.value?.cycle.id === id) selected.value = null;
  } catch { /* ignore */ }
}

async function saveObj(): Promise<void> {
  if (!selected.value) return;
  savingObj.value = true;
  try {
    if (editObjId.value) {
      await api("PUT", `/api/v1/okr/objectives/${editObjId.value}`, { org: org(), body: objForm });
    } else {
      await api("POST", `/api/v1/okr/cycles/${selected.value.cycle.id}/objectives`, { org: org(), body: objForm });
    }
    showObjForm.value = false;
    await loadCycle(selected.value.cycle.id);
  } catch { /* ignore */ } finally {
    savingObj.value = false;
  }
}

async function deleteObj(objId: number): Promise<void> {
  if (!confirm("Delete this objective and all its key results?")) return;
  try {
    await api("DELETE", `/api/v1/okr/objectives/${objId}`, { org: org() });
    if (selected.value) await loadCycle(selected.value.cycle.id);
  } catch { /* ignore */ }
}

async function saveKr(objectiveId: number): Promise<void> {
  savingKr.value = true;
  try {
    await api("POST", `/api/v1/okr/objectives/${objectiveId}/key-results`, { org: org(), body: krForm });
    showKrForm.value = null;
    if (selected.value) await loadCycle(selected.value.cycle.id);
  } catch { /* ignore */ } finally {
    savingKr.value = false;
  }
}

async function deleteKr(krId: number): Promise<void> {
  if (!confirm("Delete this key result?")) return;
  try {
    await api("DELETE", `/api/v1/okr/key-results/${krId}`, { org: org() });
    if (selected.value) await loadCycle(selected.value.cycle.id);
  } catch { /* ignore */ }
}

async function saveAchievement(kr: OkrKeyResult): Promise<void> {
  const val = parseFloat(achEdit[kr.id]);
  if (isNaN(val)) return;
  achSaving[kr.id] = true;
  try {
    const res = await api<{ score_percent: number }>("PUT", `/api/v1/okr/key-results/${kr.id}/achievement`, { org: org(), body: { achievement: val } });
    kr.achievement   = val;
    kr.score_percent = res.score_percent;
    if (selected.value) await loadCycle(selected.value.cycle.id);
  } catch { /* ignore */ } finally {
    achSaving[kr.id] = false;
  }
}

// ── Helpers ────────────────────────────────────────────────────────────────────

function openAddCycle(): void {
  Object.assign(cycleForm, { name: "", year: new Date().getFullYear(), period: "annual", status: "draft" });
  editCycleId.value  = null;
  cycleFormErr.value = "";
  showCycleForm.value = true;
}

function openEditCycle(c: OkrCycle): void {
  Object.assign(cycleForm, { name: c.name, year: c.year, period: c.period, status: c.status });
  editCycleId.value  = c.id;
  cycleFormErr.value = "";
  showCycleForm.value = true;
}

function openAddObj(): void {
  Object.assign(objForm, { title: "", description: "", weight: 1 });
  editObjId.value = null;
  showObjForm.value = true;
}

function openEditObj(o: OkrObjective): void {
  Object.assign(objForm, { title: o.title, description: o.description ?? "", weight: o.weight });
  editObjId.value  = o.id;
  showObjForm.value = true;
}

function openAddKr(objectiveId: number): void {
  Object.assign(krForm, { title: "", measure: "", target_value: 100, weight: 1 });
  showKrForm.value = objectiveId;
}

function toggleExpand(id: number): void {
  if (expanded.value.has(id)) expanded.value.delete(id);
  else expanded.value.add(id);
}

function statusBadge(s: string): string {
  return { draft: "badge-gray", active: "badge-green", closed: "badge-yellow" }[s] ?? "badge-gray";
}

function periodLabel(p: string): string {
  return { annual: "Annual", q1: "Q1", q2: "Q2", q3: "Q3", q4: "Q4" }[p] ?? p;
}

function scoreColour(pct: number): string {
  if (pct >= 80) return "#10B981";
  if (pct >= 50) return "#F59E0B";
  return "#EF4444";
}

const totalScore = computed(() => {
  const objs = selected.value?.objectives ?? [];
  if (!objs.length) return 0;
  const w = objs.reduce((s, o) => s + o.weight, 0) || 1;
  return Math.round(objs.reduce((s, o) => s + (o.score_percent * o.weight), 0) / w);
});

onMounted(loadCycles);
watch(currentOrgSlug, () => { cycles.value = []; selected.value = null; loadCycles(); });
</script>

<template>
  <div class="okr-root view">

    <!-- ── Page header ──────────────────────────────────────────────────────── -->
    <div class="okr-page-hdr">
      <div>
        <h2 class="okr-page-title">OKR Cycles</h2>
        <p class="okr-page-sub">Org-level Objectives & Key Results — by cycle</p>
      </div>
      <button class="btn btn-primary btn-sm" @click="openAddCycle">+ New Cycle</button>
    </div>

    <p v-if="loading" class="status">Loading cycles…</p>
    <p v-if="error"   class="error">{{ error }}</p>

    <!-- ── Main layout ──────────────────────────────────────────────────────── -->
    <div class="okr-layout">

      <!-- Cycle list (left) -->
      <div class="okr-cycle-list">
        <div
          v-for="c in cycles"
          :key="c.id"
          :class="['okr-cycle-card', { 'okr-cycle-selected': selected?.cycle.id === c.id }]"
          @click="loadCycle(c.id)"
        >
          <div class="okr-cycle-card-hdr">
            <span :class="['badge', statusBadge(c.status)]">{{ c.status }}</span>
            <div class="okr-cycle-card-actions" @click.stop>
              <button class="act-btn" title="Edit" @click="openEditCycle(c)">
                <svg viewBox="0 0 20 20" fill="currentColor"><path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zm-2.207 2.207L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"/></svg>
              </button>
              <button class="act-btn act-del" title="Delete" @click="deleteCycle(c.id)">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zm-1 8a1 1 0 012 0v3a1 1 0 11-2 0V10z" clip-rule="evenodd"/></svg>
              </button>
            </div>
          </div>
          <p class="okr-cycle-name">{{ c.name }}</p>
          <p class="okr-cycle-meta">{{ c.year }} · {{ periodLabel(c.period) }} · {{ c.objectives_count }} objectives</p>
        </div>
        <p v-if="!loading && !cycles.length" class="empty">No OKR cycles yet. <button class="link-btn" @click="openAddCycle">Create one →</button></p>
      </div>

      <!-- Cycle detail (right) -->
      <div class="okr-detail">
        <template v-if="selected">
          <div class="okr-detail-hdr">
            <div>
              <h3 class="okr-detail-title">{{ selected.cycle.name }}</h3>
              <p class="okr-detail-meta">{{ selected.cycle.year }} · {{ periodLabel(selected.cycle.period) }} · <span :class="['badge', statusBadge(selected.cycle.status)]">{{ selected.cycle.status }}</span></p>
            </div>
            <div class="okr-score-ring">
              <svg viewBox="0 0 60 60">
                <circle cx="30" cy="30" r="24" fill="none" stroke="var(--color-neutral-200)" stroke-width="7"/>
                <circle cx="30" cy="30" r="24" fill="none"
                  :stroke="scoreColour(totalScore)" stroke-width="7"
                  :stroke-dasharray="`${2 * Math.PI * 24}`"
                  :stroke-dashoffset="`${2 * Math.PI * 24 * (1 - totalScore / 100)}`"
                  transform="rotate(-90 30 30)" stroke-linecap="round"/>
              </svg>
              <div class="okr-score-label">
                <p class="okr-score-num">{{ totalScore }}%</p>
                <p class="okr-score-sub">Overall</p>
              </div>
            </div>
          </div>

          <p v-if="detailLoading" class="status">Loading…</p>

          <!-- Objectives -->
          <div class="okr-obj-hdr">
            <h4>Objectives</h4>
            <button class="btn btn-outline btn-sm" @click="openAddObj">+ Add Objective</button>
          </div>

          <!-- Add/Edit objective form -->
          <div v-if="showObjForm" class="okr-inline-form">
            <div class="okr-form-row">
              <input v-model="objForm.title" class="input" placeholder="Objective title *" style="flex:1"/>
              <input v-model.number="objForm.weight" class="input okr-weight-input" type="number" min="0" max="100" step="0.1" placeholder="Weight"/>
            </div>
            <textarea v-model="objForm.description" class="input" rows="2" placeholder="Description (optional)"></textarea>
            <div class="okr-form-actions">
              <button class="btn btn-primary btn-sm" :disabled="savingObj || !objForm.title" @click="saveObj">{{ savingObj ? 'Saving…' : editObjId ? 'Update' : 'Add Objective' }}</button>
              <button class="btn btn-secondary btn-sm" @click="showObjForm = false; editObjId = null">Cancel</button>
            </div>
          </div>

          <p v-if="!detailLoading && !selected.objectives?.length" class="empty" style="margin:16px 0">No objectives yet.</p>

          <div class="okr-obj-list">
            <div v-for="obj in selected.objectives" :key="obj.id" class="okr-obj">

              <!-- Objective row -->
              <div class="okr-obj-row" @click="toggleExpand(obj.id)">
                <svg :class="['okr-chevron', { open: expanded.has(obj.id) }]" viewBox="0 0 20 20" fill="currentColor">
                  <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
                </svg>
                <div class="okr-obj-title-wrap">
                  <p class="okr-obj-title">{{ obj.title }}</p>
                  <p v-if="obj.description" class="okr-obj-desc">{{ obj.description }}</p>
                </div>
                <div class="okr-obj-score-wrap">
                  <div class="okr-mini-bar-wrap">
                    <div class="okr-mini-bar" :style="{ width: obj.score_percent + '%', background: scoreColour(obj.score_percent) }"></div>
                  </div>
                  <span class="okr-obj-pct" :style="{ color: scoreColour(obj.score_percent) }">{{ Math.round(obj.score_percent) }}%</span>
                </div>
                <div class="okr-obj-actions" @click.stop>
                  <button class="act-btn" title="Edit" @click="openEditObj(obj); showObjForm = true">
                    <svg viewBox="0 0 20 20" fill="currentColor"><path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zm-2.207 2.207L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"/></svg>
                  </button>
                  <button class="act-btn act-del" title="Delete" @click="deleteObj(obj.id)">
                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zm-1 8a1 1 0 012 0v3a1 1 0 11-2 0V10z" clip-rule="evenodd"/></svg>
                  </button>
                </div>
              </div>

              <!-- Key results (expanded) -->
              <div v-show="expanded.has(obj.id)" class="okr-kr-section">
                <table class="okr-kr-table">
                  <thead>
                    <tr>
                      <th>Key Result</th>
                      <th>Measure</th>
                      <th>Target</th>
                      <th>Achievement</th>
                      <th>Score</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="kr in obj.key_results" :key="kr.id">
                      <td class="okr-kr-title">{{ kr.title }}</td>
                      <td class="okr-kr-muted">{{ kr.measure ?? '—' }}</td>
                      <td class="okr-kr-muted">{{ kr.target_value }}</td>
                      <td class="okr-kr-ach">
                        <input
                          v-model="achEdit[kr.id]"
                          class="input okr-ach-input"
                          type="number"
                          :placeholder="kr.achievement != null ? String(kr.achievement) : '—'"
                          @keyup.enter="saveAchievement(kr)"
                        />
                        <button class="btn btn-primary btn-sm" :disabled="achSaving[kr.id]" @click="saveAchievement(kr)">
                          {{ achSaving[kr.id] ? '…' : '✓' }}
                        </button>
                      </td>
                      <td>
                        <span class="okr-kr-score" :style="{ color: scoreColour(kr.score_percent) }">{{ Math.round(kr.score_percent) }}%</span>
                      </td>
                      <td>
                        <button class="act-btn act-del" title="Delete KR" @click="deleteKr(kr.id)">
                          <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zm-1 8a1 1 0 012 0v3a1 1 0 11-2 0V10z" clip-rule="evenodd"/></svg>
                        </button>
                      </td>
                    </tr>
                    <tr v-if="!obj.key_results?.length">
                      <td colspan="6" class="okr-kr-empty">No key results yet.</td>
                    </tr>
                  </tbody>
                </table>

                <!-- Add KR inline form -->
                <div v-if="showKrForm === obj.id" class="okr-inline-form okr-kr-form">
                  <div class="okr-form-row">
                    <input v-model="krForm.title" class="input" placeholder="Key result title *" style="flex:1"/>
                    <input v-model="krForm.measure" class="input" placeholder="Measure (e.g. %, count)" style="width:140px"/>
                    <input v-model.number="krForm.target_value" class="input" type="number" min="0" placeholder="Target" style="width:90px"/>
                  </div>
                  <div class="okr-form-actions">
                    <button class="btn btn-primary btn-sm" :disabled="savingKr || !krForm.title" @click="saveKr(obj.id)">{{ savingKr ? 'Saving…' : 'Add KR' }}</button>
                    <button class="btn btn-secondary btn-sm" @click="showKrForm = null">Cancel</button>
                  </div>
                </div>
                <button v-else class="link-btn okr-add-kr-btn" @click="openAddKr(obj.id)">+ Add Key Result</button>
              </div>
            </div>
          </div>
        </template>

        <div v-else-if="!loading" class="okr-empty-state">
          <p>Select an OKR cycle to view its objectives and key results.</p>
        </div>
      </div>
    </div>

    <!-- ── Create / Edit Cycle modal ────────────────────────────────────────── -->
    <div v-if="showCycleForm" class="okr-modal-backdrop" @click.self="showCycleForm = false">
      <div class="okr-modal">
        <h3 class="okr-modal-title">{{ editCycleId ? 'Edit Cycle' : 'New OKR Cycle' }}</h3>

        <label class="okr-form-label">Cycle Name <span class="req">*</span></label>
        <input v-model="cycleForm.name" class="input" placeholder="e.g. FY 2026 Annual OKRs"/>

        <div class="okr-form-row">
          <div style="flex:1">
            <label class="okr-form-label">Year</label>
            <input v-model.number="cycleForm.year" class="input" type="number" min="2020" max="2100"/>
          </div>
          <div style="flex:1">
            <label class="okr-form-label">Period</label>
            <select v-model="cycleForm.period" class="input">
              <option value="annual">Annual</option>
              <option value="q1">Q1</option>
              <option value="q2">Q2</option>
              <option value="q3">Q3</option>
              <option value="q4">Q4</option>
            </select>
          </div>
          <div style="flex:1">
            <label class="okr-form-label">Status</label>
            <select v-model="cycleForm.status" class="input">
              <option value="draft">Draft</option>
              <option value="active">Active</option>
              <option value="closed">Closed</option>
            </select>
          </div>
        </div>

        <p v-if="cycleFormErr" class="error">{{ cycleFormErr }}</p>

        <div class="okr-form-actions">
          <button class="btn btn-primary" :disabled="savingCycle || !cycleForm.name" @click="saveCycle">
            {{ savingCycle ? 'Saving…' : editCycleId ? 'Update Cycle' : 'Create Cycle' }}
          </button>
          <button class="btn btn-secondary" @click="showCycleForm = false">Cancel</button>
        </div>
      </div>
    </div>

  </div>
</template>

<style scoped>
/* ── Layout ─────────────────────────────────────────────────────────────────── */
.okr-root { max-width: none; }

.okr-page-hdr {
  display: flex; align-items: flex-start; justify-content: space-between;
  margin-bottom: 24px; gap: 12px; flex-wrap: wrap;
}
.okr-page-title { font-size: 22px; font-weight: 700; color: var(--color-text); letter-spacing: -0.3px; }
.okr-page-sub   { font-size: 13px; color: var(--color-text-muted); margin-top: 2px; }

.okr-layout {
  display: grid;
  grid-template-columns: 280px 1fr;
  gap: 20px;
  align-items: start;
}
@media (max-width: 800px) { .okr-layout { grid-template-columns: 1fr; } }

/* ── Cycle list ─────────────────────────────────────────────────────────────── */
.okr-cycle-list { display: flex; flex-direction: column; gap: 10px; }

.okr-cycle-card {
  background: var(--color-surface); border: 1px solid var(--color-border);
  border-radius: 10px; padding: 14px 16px; cursor: pointer;
  transition: box-shadow .15s, border-color .15s;
}
.okr-cycle-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,.08); border-color: var(--color-brand-base); }
.okr-cycle-selected  { border-color: var(--color-brand-base); background: var(--color-brand-light); }

.okr-cycle-card-hdr { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; }
.okr-cycle-card-actions { display: flex; gap: 4px; opacity: 0; transition: opacity .15s; }
.okr-cycle-card:hover .okr-cycle-card-actions { opacity: 1; }

.okr-cycle-name { font-size: 14px; font-weight: 600; color: var(--color-text); margin-bottom: 2px; }
.okr-cycle-meta { font-size: 12px; color: var(--color-text-muted); text-transform: capitalize; }

/* ── Cycle detail ───────────────────────────────────────────────────────────── */
.okr-detail {
  background: var(--color-surface); border: 1px solid var(--color-border);
  border-radius: 12px; padding: 24px; min-height: 300px;
}

.okr-detail-hdr {
  display: flex; justify-content: space-between; align-items: flex-start;
  margin-bottom: 20px; gap: 16px;
}
.okr-detail-title { font-size: 18px; font-weight: 700; color: var(--color-text); }
.okr-detail-meta  { font-size: 13px; color: var(--color-text-muted); margin-top: 4px; display: flex; align-items: center; gap: 8px; }

.okr-score-ring { position: relative; width: 60px; height: 60px; flex-shrink: 0; }
.okr-score-ring svg { width: 100%; height: 100%; }
.okr-score-label {
  position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
  text-align: center; pointer-events: none;
}
.okr-score-num { font-size: 12px; font-weight: 700; color: var(--color-text); line-height: 1; }
.okr-score-sub { font-size: 9px; color: var(--color-text-muted); margin-top: 1px; }

.okr-obj-hdr { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
.okr-obj-hdr h4 { font-size: 14px; font-weight: 600; color: var(--color-text); }

/* ── Objective row ──────────────────────────────────────────────────────────── */
.okr-obj-list { display: flex; flex-direction: column; gap: 8px; }

.okr-obj {
  border: 1px solid var(--color-border); border-radius: 8px; overflow: hidden;
}

.okr-obj-row {
  display: flex; align-items: center; gap: 10px; padding: 12px 14px;
  cursor: pointer; background: var(--color-surface);
  transition: background .12s;
}
.okr-obj-row:hover { background: var(--color-neutral-100); }

.okr-chevron {
  width: 14px; height: 14px; flex-shrink: 0; opacity: 0.5;
  transition: transform .2s;
}
.okr-chevron.open { transform: rotate(180deg); }

.okr-obj-title-wrap { flex: 1; min-width: 0; }
.okr-obj-title { font-size: 14px; font-weight: 600; color: var(--color-text); }
.okr-obj-desc  { font-size: 12px; color: var(--color-text-muted); margin-top: 2px; }

.okr-obj-score-wrap { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.okr-mini-bar-wrap  { width: 80px; height: 6px; background: var(--color-neutral-200); border-radius: 3px; overflow: hidden; }
.okr-mini-bar       { height: 100%; border-radius: 3px; transition: width .4s; }
.okr-obj-pct        { font-size: 12px; font-weight: 700; width: 36px; text-align: right; }

.okr-obj-actions { display: flex; gap: 4px; flex-shrink: 0; opacity: 0; transition: opacity .15s; }
.okr-obj-row:hover .okr-obj-actions { opacity: 1; }

/* ── Key results table ──────────────────────────────────────────────────────── */
.okr-kr-section {
  background: var(--color-neutral-100);
  border-top: 1px solid var(--color-border);
  padding: 12px 14px;
}

.okr-kr-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.okr-kr-table th {
  text-align: left; font-size: 11px; font-weight: 600; text-transform: uppercase;
  letter-spacing: .04em; color: var(--color-text-muted); padding: 0 8px 8px 0;
}
.okr-kr-table td { padding: 6px 8px 6px 0; vertical-align: middle; }
.okr-kr-table tr:not(:last-child) td { border-bottom: 1px solid var(--color-border); }

.okr-kr-title { font-weight: 500; color: var(--color-text); }
.okr-kr-muted { color: var(--color-text-muted); }
.okr-kr-score { font-weight: 700; font-size: 13px; }
.okr-kr-empty { color: var(--color-text-muted); font-style: italic; padding: 8px 0; text-align: center; }

.okr-kr-ach   { display: flex; align-items: center; gap: 6px; }
.okr-ach-input { width: 80px; padding: 4px 8px; font-size: 12px; }

.okr-add-kr-btn {
  margin-top: 10px; font-size: 12px; color: var(--color-brand-base);
  background: none; border: none; cursor: pointer; padding: 0;
}
.okr-add-kr-btn:hover { text-decoration: underline; }

/* ── Inline forms ───────────────────────────────────────────────────────────── */
.okr-inline-form {
  background: var(--color-surface); border: 1px solid var(--color-border);
  border-radius: 8px; padding: 14px; margin-bottom: 12px;
  display: flex; flex-direction: column; gap: 10px;
}
.okr-kr-form { margin-top: 10px; }

.okr-form-row    { display: flex; gap: 10px; align-items: flex-start; }
.okr-form-actions { display: flex; gap: 8px; }
.okr-form-label  { font-size: 12px; font-weight: 600; color: var(--color-text-muted); display: block; margin-bottom: 4px; }
.okr-weight-input { width: 90px; }
.req { color: var(--color-error); margin-left: 2px; }

/* ── Empty / modal ──────────────────────────────────────────────────────────── */
.okr-empty-state {
  display: flex; align-items: center; justify-content: center;
  min-height: 200px; color: var(--color-text-muted); font-size: 14px;
}

.link-btn {
  background: none; border: none; cursor: pointer;
  color: var(--color-brand-base); font-size: inherit;
}
.link-btn:hover { text-decoration: underline; }

.okr-modal-backdrop {
  position: fixed; inset: 0; background: rgba(0,0,0,.45);
  display: flex; align-items: center; justify-content: center; z-index: 300;
}
.okr-modal {
  background: var(--color-surface); border-radius: 14px; padding: 28px;
  width: 560px; max-width: 95vw; display: flex; flex-direction: column; gap: 14px;
  box-shadow: 0 20px 60px rgba(0,0,0,.25);
}
.okr-modal-title { font-size: 18px; font-weight: 700; color: var(--color-text); }

/* ── Action buttons ─────────────────────────────────────────────────────────── */
.act-btn {
  background: none; border: none; cursor: pointer; padding: 4px;
  border-radius: 4px; color: var(--color-text-muted); display: flex;
  transition: color .15s, background .15s;
}
.act-btn svg { width: 14px; height: 14px; }
.act-btn:hover { color: var(--color-brand-base); background: var(--color-brand-light); }
.act-del:hover { color: var(--color-error); background: var(--color-error-light); }
</style>
