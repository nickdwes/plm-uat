<script setup lang="ts">
import { ref, computed, reactive, watch, onMounted } from 'vue';
import { api } from '../../lib/api';
import { currentOrgSlug } from '../../stores/auth';
import type { Interview, InterviewScore } from '../../lib/types';

const props = defineProps<{ id: string }>();

// ── Criteria (matching the public form labels) ────────────────────────────────
const CRITERIA: Record<string, string> = {
  personal_presentation:   'General Appearance, Presentation & Communication',
  training_certifications: 'Academic (Training) Qualification',
  role_knowledge:          'Role Skills, Knowledge & Experience',
  problem_solving:         'Creative/Analytical/Problem Solving Skills',
  teamskills_leadership:   'Interpersonal Skills / Teamwork',
  attitude_adaptability:   'Attitude & Adaptability',
  personal_values:         'Personal/Professional Values',
  interests_goals:         'Interests & Goals',
};

const SCORE_LABELS: Record<number, string> = {
  1: '1 — Poor', 2: '2 — Below Average', 3: '3 — Average', 4: '4 — Good', 5: '5 — Excellent',
};
const SCORE_COLORS: Record<number, string> = {
  1: '#dc2626', 2: '#ea580c', 3: '#d97706', 4: '#0ea5e9', 5: '#059669',
};

// ── State ─────────────────────────────────────────────────────────────────────
const interview      = ref<Interview | null>(null);
const loading        = ref(true);
const loadError      = ref('');
const saving         = ref(false);
const submitting     = ref(false);
const saveError      = ref('');
const saveSuccess    = ref('');
const selectedPanelistId = ref<number | null>(null);

const scores            = ref<Record<string, number | null>>({});
const criterionComments = ref<Record<string, string>>({});
const remarks           = ref('');
const recommendation    = ref<'hire' | 'hold' | 'reject' | ''>('');
const technicalExercise = reactive({ conducted: false, score: null as number | null, notes: '' });

// ── Computed ──────────────────────────────────────────────────────────────────
const total = computed(() => {
  const vals = Object.values(scores.value).filter(v => v !== null) as number[];
  return vals.reduce((a, b) => a + b, 0);
});
const average = computed(() => {
  const vals = Object.values(scores.value).filter(v => v !== null) as number[];
  return vals.length ? Math.round((vals.reduce((a, b) => a + b, 0) / vals.length) * 10) / 10 : 0;
});
const allScored = computed(() => {
  const keys = Object.keys(CRITERIA);
  return keys.every(k => scores.value[k] != null && scores.value[k]! >= 1);
});

// ── Helpers ───────────────────────────────────────────────────────────────────
function initScoreFields() {
  const clean: Record<string, number | null> = {};
  const cleanComments: Record<string, string> = {};
  for (const k of Object.keys(CRITERIA)) {
    clean[k] = null;
    cleanComments[k] = '';
  }
  scores.value = clean;
  criterionComments.value = cleanComments;
  remarks.value = '';
  recommendation.value = '';
  technicalExercise.conducted = false;
  technicalExercise.score = null;
  technicalExercise.notes = '';
}

function formatDate(d: string) {
  return new Date(d).toLocaleString('en-GB', {
    weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  });
}

function statusClass(s: string) {
  const map: Record<string, string> = {
    scheduled: 'sf-badge-blue', confirmed: 'sf-badge-green',
    completed: 'sf-badge-indigo', cancelled: 'sf-badge-red', rescheduled: 'sf-badge-yellow',
  };
  return map[s] ?? 'sf-badge-gray';
}

function recClass(r: string | null) {
  if (!r) return '';
  return { hire: 'sf-rec-hire', hold: 'sf-rec-hold', reject: 'sf-rec-reject' }[r] ?? '';
}

function shortLabel(label: string): string {
  return label.split(/[,&/(]/)[0].trim().split(' ').slice(0, 3).join(' ');
}

// ── Auto-populate when panelist changes ───────────────────────────────────────
watch(selectedPanelistId, (id) => {
  if (!id || !interview.value) { initScoreFields(); return; }
  const ex = interview.value.scores.find((s: any) => s.panelist_id === id);
  if (ex) {
    for (const k of Object.keys(CRITERIA)) {
      scores.value[k] = (ex as any)[k] ?? null;
      criterionComments.value[k] = (ex as any).criterion_comments?.[k] ?? '';
    }
    remarks.value        = (ex as any).remarks ?? '';
    recommendation.value = (ex as any).recommendation ?? '';
    technicalExercise.conducted = (ex as any).technical_exercise_conducted ?? false;
    technicalExercise.score     = (ex as any).technical_exercise_score ?? null;
    technicalExercise.notes     = (ex as any).technical_exercise_notes ?? '';
  } else {
    initScoreFields();
  }
});

// ── Load ──────────────────────────────────────────────────────────────────────
onMounted(async () => {
  initScoreFields();
  try {
    const data = await api<Interview>('GET', `/api/v1/recruitment/interviews/${props.id}`, {
      org: currentOrgSlug.value,
    });
    interview.value = data;
    if (data.panelists.length === 1) {
      selectedPanelistId.value = data.panelists[0].employee_id;
    }
  } catch {
    loadError.value = 'Failed to load interview details.';
  } finally {
    loading.value = false;
  }
});

// ── Save ──────────────────────────────────────────────────────────────────────
async function save(submit: boolean) {
  if (!selectedPanelistId.value) return;
  if (submit && !allScored.value) {
    saveError.value = 'Please score all criteria before submitting.'; return;
  }
  saveError.value = '';
  saveSuccess.value = '';
  saving.value = true;
  submitting.value = submit;
  try {
    const payload: Record<string, any> = {
      panelist_id:       selectedPanelistId.value,
      ...scores.value,
      criterion_comments:           criterionComments.value,
      technical_exercise_conducted: technicalExercise.conducted,
      technical_exercise_score:     technicalExercise.conducted ? technicalExercise.score : undefined,
      technical_exercise_notes:     technicalExercise.conducted ? (technicalExercise.notes || undefined) : undefined,
      remarks:        remarks.value || undefined,
      recommendation: recommendation.value || undefined,
      submit,
    };
    const updated = await api<{ score: InterviewScore; interview: Interview }>(
      'POST', `/api/v1/recruitment/interviews/${props.id}/scores`,
      { org: currentOrgSlug.value, body: payload }
    );
    interview.value = updated.interview;
    saveSuccess.value = submit ? 'Score submitted successfully!' : 'Draft saved.';
  } catch (e: any) {
    saveError.value = e?.message ?? 'Failed to save score.';
  } finally {
    saving.value = false;
    submitting.value = false;
  }
}

// ── Check if this panelist already submitted ──────────────────────────────────
const currentPanelistSubmitted = computed(() => {
  if (!selectedPanelistId.value || !interview.value) return false;
  return interview.value.scores.some(
    (s: any) => s.panelist_id === selectedPanelistId.value && s.submitted
  );
});
</script>

<template>
  <div class="sf-page">

    <!-- Header bar -->
    <div class="sf-topbar">
      <div class="sf-brand">Interview Scoring Sheet</div>
      <div class="sf-scale-note">Scale: <strong>1</strong> (Poor) &mdash; <strong>5</strong> (Excellent)</div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="sf-center">
      <div class="sf-spinner"></div>
      <p>Loading&hellip;</p>
    </div>

    <!-- Error -->
    <div v-else-if="loadError" class="sf-center">
      <div class="sf-error-icon">&#9888;</div>
      <h2>{{ loadError }}</h2>
    </div>

    <template v-else-if="interview">
      <div class="sf-container">

        <!-- Interview info card -->
        <div class="sf-info-card">
          <div class="sf-info-header">
            <div>
              <h1 class="sf-interview-title">{{ interview.title }}</h1>
              <div class="sf-interview-meta">
                <span class="sf-badge" :class="statusClass(interview.status)">{{ interview.status }}</span>
                <span class="sf-dot">&#183;</span>
                <span class="sf-badge sf-badge-outline">{{ interview.interview_type }}</span>
                <span class="sf-dot">&#183;</span>
                <span>{{ formatDate(interview.scheduled_at) }}</span>
                <span class="sf-dot">&#183;</span>
                <span>{{ interview.duration_minutes }} min</span>
              </div>
            </div>
            <a v-if="interview.teams_join_url" :href="interview.teams_join_url" target="_blank" class="sf-teams-btn">
              &#128249; Join Teams Meeting
            </a>
          </div>
        </div>

        <!-- Panelist selector -->
        <div class="sf-info-card sf-panelist-card">
          <label class="sf-label">Scoring as panelist</label>
          <select v-model="selectedPanelistId" class="sf-select">
            <option :value="null" disabled>Select your name&hellip;</option>
            <option v-for="p in interview.panelists" :key="p.employee_id ?? p.id" :value="p.employee_id ?? p.id">
              {{ p.name }} &mdash; {{ p.role }}
              <template v-if="p.score_submitted"> (submitted)</template>
            </option>
          </select>
        </div>

        <template v-if="selectedPanelistId">

          <!-- Already submitted notice -->
          <div v-if="currentPanelistSubmitted" class="sf-already-submitted">
            &#10003; This panelist has already submitted their final score. You can still edit and re-save if needed.
          </div>

          <!-- Scoring table -->
          <div class="sf-scoring-card">
            <div class="sf-scoring-header">
              <h2 class="sf-scoring-title">Candidate Evaluation</h2>
              <p class="sf-scoring-sub">Score each area from 1 (Poor) to 5 (Excellent). All areas must be scored before submitting.</p>
            </div>

            <div v-if="saveError" class="sf-error-msg">{{ saveError }}</div>
            <div v-if="saveSuccess" class="sf-success-msg">{{ saveSuccess }}</div>

            <table class="sf-table">
              <thead>
                <tr>
                  <th class="sf-th-area">Scoring Area</th>
                  <th v-for="n in [1,2,3,4,5]" :key="n" class="sf-th-score">
                    <span class="sf-score-header-num" :style="{ color: SCORE_COLORS[n] }">{{ n }}</span>
                  </th>
                  <th class="sf-th-given">Given</th>
                </tr>
              </thead>
              <tbody>
                <template v-for="(label, key) in CRITERIA" :key="key">
                  <tr :class="{ 'sf-row-scored': scores[key] != null }">
                    <td class="sf-td-area">{{ label }}</td>
                    <td v-for="n in [1,2,3,4,5]" :key="n" class="sf-td-radio">
                      <label
                        class="sf-radio-label"
                        :class="{ 'sf-radio-selected': scores[key] === n }"
                        :style="scores[key] === n ? { background: SCORE_COLORS[n] + '22', borderColor: SCORE_COLORS[n] } : {}"
                      >
                        <input type="radio" :name="String(key)" :value="n" v-model="scores[key]" class="sf-radio-input" />
                      </label>
                    </td>
                    <td class="sf-td-given">
                      <span v-if="scores[key] != null" class="sf-given-score" :style="{ color: SCORE_COLORS[scores[key] as number], fontWeight: 700 }">
                        {{ scores[key] }}
                      </span>
                      <span v-else class="sf-given-empty">—</span>
                    </td>
                  </tr>
                  <tr class="sf-row-comment" :class="{ 'sf-row-comment-scored': scores[key] != null }">
                    <td colspan="7" class="sf-td-comment">
                      <textarea
                        v-model="criterionComments[key]"
                        class="sf-criterion-textarea"
                        placeholder="Add a comment to support your score (optional)…"
                        rows="2"
                      ></textarea>
                    </td>
                  </tr>
                </template>
              </tbody>
              <tfoot>
                <tr class="sf-tfoot-total">
                  <td class="sf-td-area"><strong>TOTAL</strong></td>
                  <td colspan="5" class="sf-td-total-spacer"></td>
                  <td class="sf-td-given"><strong class="sf-total-val">{{ total > 0 ? total.toFixed(1) : '—' }}</strong></td>
                </tr>
                <tr class="sf-tfoot-avg">
                  <td class="sf-td-area"><strong>Average Score</strong></td>
                  <td colspan="5" class="sf-td-total-spacer"></td>
                  <td class="sf-td-given">
                    <strong class="sf-avg-val"
                      :style="{ color: average >= 4 ? '#059669' : average >= 3 ? '#d97706' : average > 0 ? '#dc2626' : '#94a3b8' }">
                      {{ average > 0 ? average.toFixed(1) : '—' }}
                    </strong>
                  </td>
                </tr>
              </tfoot>
            </table>

            <div class="sf-legend">
              <span v-for="(label, n) in SCORE_LABELS" :key="n" class="sf-legend-item" :style="{ color: SCORE_COLORS[Number(n)] }">
                {{ label }}
              </span>
            </div>
          </div>

          <!-- Technical Exercise -->
          <div class="sf-tech-card">
            <div class="sf-tech-header">
              <h2 class="sf-scoring-title">Technical Exercise</h2>
              <label class="sf-toggle-label" :class="{ 'sf-toggle-active': technicalExercise.conducted }">
                <input type="checkbox" v-model="technicalExercise.conducted" class="sf-hidden-radio" />
                <span class="sf-toggle-track"><span class="sf-toggle-thumb"></span></span>
                {{ technicalExercise.conducted ? 'Exercise was conducted' : 'No technical exercise' }}
              </label>
            </div>
            <template v-if="technicalExercise.conducted">
              <div class="sf-tech-body">
                <div class="sf-field sf-field-inline">
                  <label class="sf-label">Score <span class="sf-label-hint">(0 – 100)</span></label>
                  <div class="sf-score-input-wrap">
                    <input type="number" v-model.number="technicalExercise.score" min="0" max="100" step="0.5"
                      class="sf-score-input" placeholder="e.g. 75" />
                    <span class="sf-score-input-suffix">/ 100</span>
                    <span v-if="technicalExercise.score != null" class="sf-score-badge"
                      :style="{
                        background:  technicalExercise.score >= 70 ? '#f0fdf4' : technicalExercise.score >= 50 ? '#fffbeb' : '#fef2f2',
                        color:       technicalExercise.score >= 70 ? '#059669' : technicalExercise.score >= 50 ? '#d97706' : '#dc2626',
                        borderColor: technicalExercise.score >= 70 ? '#bbf7d0' : technicalExercise.score >= 50 ? '#fde68a' : '#fecaca',
                      }">
                      {{ technicalExercise.score >= 70 ? 'Pass' : technicalExercise.score >= 50 ? 'Borderline' : 'Fail' }}
                    </span>
                  </div>
                </div>
                <div class="sf-field">
                  <label class="sf-label">Exercise Notes</label>
                  <textarea v-model="technicalExercise.notes" class="sf-textarea" rows="3"
                    placeholder="Describe the exercise, key observations, and how the candidate performed…"></textarea>
                </div>
              </div>
            </template>
          </div>

          <!-- Remarks + Recommendation -->
          <div class="sf-remarks-card">
            <div class="sf-field">
              <label class="sf-label">Remarks / Overall Assessment</label>
              <textarea v-model="remarks" class="sf-textarea" rows="5"
                placeholder="Summarise the candidate's strengths, areas for development, and overall impression…"></textarea>
            </div>
            <div class="sf-field">
              <label class="sf-label">Recommendation</label>
              <div class="sf-rec-group">
                <label class="sf-rec-btn" :class="{ 'sf-rec-hire': recommendation === 'hire' }">
                  <input type="radio" v-model="recommendation" value="hire" class="sf-hidden-radio" />
                  &#9989; Hire
                </label>
                <label class="sf-rec-btn" :class="{ 'sf-rec-hold': recommendation === 'hold' }">
                  <input type="radio" v-model="recommendation" value="hold" class="sf-hidden-radio" />
                  &#9203; Hold
                </label>
                <label class="sf-rec-btn" :class="{ 'sf-rec-reject': recommendation === 'reject' }">
                  <input type="radio" v-model="recommendation" value="reject" class="sf-hidden-radio" />
                  &#10060; Reject
                </label>
              </div>
            </div>
          </div>

          <!-- Actions -->
          <div class="sf-actions">
            <button class="sf-btn-draft" :disabled="saving" @click="save(false)">
              {{ saving && !submitting ? 'Saving…' : 'Save Draft' }}
            </button>
            <button class="sf-btn-submit" :disabled="saving || !allScored"
              :title="!allScored ? 'Score all areas to submit' : ''" @click="save(true)">
              {{ saving && submitting ? 'Submitting…' : 'Submit Final Score' }}
            </button>
          </div>

        </template>

        <!-- Panel Scores Summary -->
        <div v-if="interview.scores && interview.scores.length" class="sf-scoring-card">
          <div class="sf-scoring-header">
            <h2 class="sf-scoring-title">Panel Scores Summary</h2>
          </div>
          <div class="sf-summary-wrap">
            <table class="sf-table sf-summary-table">
              <thead>
                <tr>
                  <th class="sf-th-area">Panelist</th>
                  <th v-for="(label, key) in CRITERIA" :key="key" class="sf-th-summary">
                    {{ shortLabel(label) }}
                  </th>
                  <th class="sf-th-summary">Total</th>
                  <th class="sf-th-summary">Avg</th>
                  <th class="sf-th-summary">Rec.</th>
                  <th class="sf-th-summary">Status</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="s in interview.scores" :key="s.id">
                  <td class="sf-td-area">{{ s.panelist_name }}</td>
                  <td v-for="(_, key) in CRITERIA" :key="key" class="sf-td-summary">
                    <span v-if="s[key as keyof typeof s] != null"
                      :style="{ color: SCORE_COLORS[s[key as keyof typeof s] as number] ?? '#374151', fontWeight: 600 }">
                      {{ s[key as keyof typeof s] }}
                    </span>
                    <span v-else class="sf-given-empty">—</span>
                  </td>
                  <td class="sf-td-summary sf-td-total">{{ s.total ?? '—' }}</td>
                  <td class="sf-td-summary sf-td-avg"
                    :style="{ color: (s.average_score ?? 0) >= 4 ? '#059669' : (s.average_score ?? 0) >= 3 ? '#d97706' : '#dc2626' }">
                    {{ s.average_score ?? '—' }}
                  </td>
                  <td class="sf-td-summary">
                    <span class="sf-rec-tag" :class="recClass(s.recommendation)">{{ s.recommendation ?? '—' }}</span>
                  </td>
                  <td class="sf-td-summary">
                    <span v-if="s.submitted" class="sf-status-tag sf-status-submitted">Submitted</span>
                    <span v-else class="sf-status-tag sf-status-draft">Draft</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-if="interview.overall_score != null" class="sf-overall-score">
            <strong>Overall Interview Score:</strong>
            <span :style="{ color: interview.overall_score >= 4 ? '#059669' : interview.overall_score >= 3 ? '#d97706' : '#dc2626' }">
              {{ interview.overall_score }} / 5
            </span>
          </div>
        </div>

      </div>
    </template>
  </div>
</template>

<style scoped>
/* ── Page shell ────────────────────────────────────────────────────────────── */
.sf-page { min-height: 100vh; background: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }

.sf-topbar {
  background: #004C97; color: #fff;
  display: flex; align-items: center; justify-content: space-between;
  padding: 14px 24px; gap: 12px;
}
.sf-brand { font-size: 16px; font-weight: 700; letter-spacing: 0.3px; }
.sf-scale-note { font-size: 13px; opacity: 0.85; }

.sf-container { max-width: 960px; margin: 0 auto; padding: 24px 16px 60px; display: flex; flex-direction: column; gap: 20px; }

/* ── Loading / error ───────────────────────────────────────────────────────── */
.sf-center { display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 60vh; gap: 12px; text-align: center; padding: 40px 20px; }
.sf-spinner { width: 36px; height: 36px; border: 3px solid #e2e8f0; border-top-color: #004C97; border-radius: 50%; animation: spin 0.7s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.sf-error-icon { font-size: 48px; color: #dc2626; }

/* ── Cards ─────────────────────────────────────────────────────────────────── */
.sf-info-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; }
.sf-scoring-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; }
.sf-tech-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; }
.sf-remarks-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px 24px; display: flex; flex-direction: column; gap: 16px; }
.sf-panelist-card { padding: 20px 24px; display: flex; flex-direction: column; gap: 8px; }

/* ── Info header ───────────────────────────────────────────────────────────── */
.sf-info-header { display: flex; align-items: flex-start; justify-content: space-between; padding: 20px 24px 20px; gap: 12px; flex-wrap: wrap; }
.sf-interview-title { font-size: 20px; font-weight: 700; color: #0f172a; margin: 0 0 8px; }
.sf-interview-meta { display: flex; align-items: center; gap: 8px; font-size: 13px; color: #64748b; flex-wrap: wrap; }
.sf-badge { padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; text-transform: capitalize; }
.sf-badge-blue    { background: #eff6ff; color: #3b82f6; }
.sf-badge-green   { background: #f0fdf4; color: #059669; }
.sf-badge-indigo  { background: #eef2ff; color: #6366f1; }
.sf-badge-red     { background: #fef2f2; color: #dc2626; }
.sf-badge-yellow  { background: #fffbeb; color: #d97706; }
.sf-badge-gray    { background: #f1f5f9; color: #64748b; }
.sf-badge-outline { background: transparent; border: 1px solid #e2e8f0; color: #64748b; }
.sf-dot { color: #cbd5e1; }
.sf-teams-btn { padding: 8px 16px; border-radius: 8px; background: #5865F2; color: #fff; text-decoration: none; font-size: 13px; font-weight: 600; white-space: nowrap; flex-shrink: 0; }
.sf-teams-btn:hover { background: #4752C4; }

/* ── Panelist selector ─────────────────────────────────────────────────────── */
.sf-select { border: 1.5px solid #e2e8f0; border-radius: 8px; padding: 9px 12px; font-size: 14px; color: #0f172a; background: #f8fafc; outline: none; max-width: 360px; width: 100%; }
.sf-select:focus { border-color: #004C97; background: #fff; }

/* ── Scoring header ────────────────────────────────────────────────────────── */
.sf-scoring-header { padding: 20px 24px 16px; border-bottom: 1px solid #e2e8f0; }
.sf-scoring-title { font-size: 17px; font-weight: 700; color: #0f172a; margin: 0 0 4px; }
.sf-scoring-sub { font-size: 13px; color: #64748b; margin: 0; }
.sf-error-msg { background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; padding: 10px 24px; font-size: 13px; }
.sf-success-msg { background: #f0fdf4; border: 1px solid #bbf7d0; color: #059669; padding: 10px 24px; font-size: 13px; }

/* ── Scoring table ─────────────────────────────────────────────────────────── */
.sf-table { width: 100%; border-collapse: collapse; }
.sf-th-area { text-align: left; padding: 12px 16px; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; color: #64748b; background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
.sf-th-score { text-align: center; padding: 12px 8px; width: 60px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
.sf-th-given { text-align: center; padding: 12px 16px; width: 70px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; color: #64748b; }
.sf-score-header-num { font-size: 16px; font-weight: 700; }

.sf-table tbody tr { border-bottom: 1px solid #f1f5f9; transition: background 0.1s; }
.sf-table tbody tr:hover { background: #f8fafc; }
.sf-row-scored { background: #f0fdf4 !important; }
.sf-td-area { padding: 14px 16px; font-size: 13px; font-weight: 500; color: #1e293b; }
.sf-td-radio { padding: 10px 8px; text-align: center; }
.sf-td-given { padding: 10px 16px; text-align: center; font-size: 14px; }
.sf-given-score { font-size: 18px; }
.sf-given-empty { color: #cbd5e1; font-size: 16px; }

.sf-radio-label {
  display: inline-flex; width: 36px; height: 36px; border-radius: 50%;
  border: 2px solid #e2e8f0; cursor: pointer;
  align-items: center; justify-content: center;
  transition: border-color 0.15s, background 0.15s;
}
.sf-radio-label:hover { border-color: #94a3b8; background: #f1f5f9; }
.sf-radio-selected { border-width: 2px !important; }
.sf-radio-input { display: none; }

.sf-tfoot-total td, .sf-tfoot-avg td { padding: 12px 16px; background: #f8fafc; border-top: 2px solid #e2e8f0; }
.sf-total-val { font-size: 18px; color: #0f172a; }
.sf-avg-val { font-size: 20px; font-weight: 700; }

/* ── Criterion comment rows ────────────────────────────────────────────────── */
.sf-row-comment td { padding: 0 16px 10px; border-bottom: 1px solid #f1f5f9; background: #fff; }
.sf-row-comment-scored td { background: #f0fdf4 !important; }
.sf-criterion-textarea {
  width: 100%; box-sizing: border-box;
  border: 1.5px solid #e2e8f0; border-radius: 6px;
  padding: 7px 10px; font-size: 12px; color: #374151;
  background: #f8fafc; outline: none; resize: vertical;
  min-height: 50px; font-family: inherit; line-height: 1.5;
}
.sf-criterion-textarea:focus { border-color: #004C97; background: #fff; }

/* ── Technical exercise card ───────────────────────────────────────────────── */
.sf-tech-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 18px 24px; border-bottom: 1px solid #e2e8f0; gap: 16px; flex-wrap: wrap;
}
.sf-tech-body { padding: 20px 24px; display: flex; flex-direction: column; gap: 16px; }

.sf-toggle-label { display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 13px; font-weight: 600; color: #64748b; user-select: none; }
.sf-toggle-active { color: #059669; }
.sf-toggle-track { position: relative; width: 40px; height: 22px; background: #e2e8f0; border-radius: 11px; flex-shrink: 0; transition: background 0.2s; }
.sf-toggle-active .sf-toggle-track { background: #059669; }
.sf-toggle-thumb { position: absolute; top: 3px; left: 3px; width: 16px; height: 16px; border-radius: 50%; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.2); transition: transform 0.2s; }
.sf-toggle-active .sf-toggle-thumb { transform: translateX(18px); }

.sf-field { display: flex; flex-direction: column; gap: 6px; }
.sf-field-inline { flex-direction: row !important; align-items: center; gap: 16px !important; flex-wrap: wrap; }
.sf-field-inline .sf-label { white-space: nowrap; margin: 0; min-width: 60px; }
.sf-label { font-size: 13px; font-weight: 600; color: #374151; }
.sf-label-hint { font-size: 11px; font-weight: 400; color: #94a3b8; margin-left: 4px; }
.sf-score-input-wrap { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.sf-score-input { width: 90px; padding: 8px 12px; border: 1.5px solid #e2e8f0; border-radius: 8px; font-size: 18px; font-weight: 700; color: #0f172a; background: #f8fafc; outline: none; text-align: center; -moz-appearance: textfield; }
.sf-score-input::-webkit-inner-spin-button { -webkit-appearance: none; }
.sf-score-input:focus { border-color: #004C97; background: #fff; }
.sf-score-input-suffix { font-size: 14px; color: #64748b; font-weight: 600; }
.sf-score-badge { padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 700; border: 1px solid; }
.sf-textarea { border: 1.5px solid #e2e8f0; border-radius: 8px; padding: 10px 14px; font-size: 13px; color: #0f172a; background: #f8fafc; outline: none; resize: vertical; min-height: 100px; font-family: inherit; width: 100%; box-sizing: border-box; }
.sf-textarea:focus { border-color: #004C97; background: #fff; }

/* ── Legend ────────────────────────────────────────────────────────────────── */
.sf-legend { display: flex; flex-wrap: wrap; gap: 12px; padding: 12px 24px 16px; border-top: 1px solid #f1f5f9; }
.sf-legend-item { font-size: 12px; font-weight: 600; }

/* ── Recommendation ────────────────────────────────────────────────────────── */
.sf-rec-group { display: flex; gap: 12px; flex-wrap: wrap; }
.sf-rec-btn { padding: 10px 24px; border-radius: 8px; border: 2px solid #e2e8f0; cursor: pointer; font-size: 14px; font-weight: 600; color: #374151; transition: all 0.15s; user-select: none; }
.sf-rec-btn:hover { border-color: #94a3b8; }
.sf-rec-hire   { border-color: #059669 !important; background: #f0fdf4; color: #059669 !important; }
.sf-rec-hold   { border-color: #d97706 !important; background: #fffbeb; color: #d97706 !important; }
.sf-rec-reject { border-color: #dc2626 !important; background: #fef2f2; color: #dc2626 !important; }
.sf-hidden-radio { display: none; }

/* ── Actions ───────────────────────────────────────────────────────────────── */
.sf-actions { display: flex; justify-content: flex-end; gap: 12px; padding: 4px 0; }
.sf-btn-draft { padding: 11px 24px; border-radius: 8px; border: 1.5px solid #e2e8f0; background: #fff; color: #374151; font-size: 14px; font-weight: 600; cursor: pointer; }
.sf-btn-draft:hover { border-color: #94a3b8; }
.sf-btn-draft:disabled { opacity: .5; cursor: not-allowed; }
.sf-btn-submit { padding: 11px 28px; border-radius: 8px; border: none; background: #004C97; color: #fff; font-size: 14px; font-weight: 700; cursor: pointer; transition: background 0.15s; }
.sf-btn-submit:hover:not(:disabled) { background: #003a75; }
.sf-btn-submit:disabled { opacity: .4; cursor: not-allowed; }

/* ── Panel Scores Summary ──────────────────────────────────────────────────── */
.sf-summary-wrap { overflow-x: auto; }
.sf-summary-table .sf-th-summary { text-align: center; padding: 10px 8px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; color: #64748b; background: #f8fafc; border-bottom: 1px solid #e2e8f0; white-space: nowrap; }
.sf-td-summary { text-align: center; padding: 10px 8px; font-size: 13px; color: #374151; }
.sf-td-total { font-weight: 700; font-size: 14px; color: #0f172a; }
.sf-td-avg { font-weight: 700; font-size: 14px; }
.sf-rec-tag { font-size: 12px; font-weight: 600; text-transform: capitalize; }
.sf-status-tag { font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 10px; }
.sf-status-submitted { background: #f0fdf4; color: #059669; }
.sf-status-draft { background: #fffbeb; color: #d97706; }
.sf-overall-score { padding: 12px 24px; font-size: 14px; color: #374151; border-top: 1px solid #f1f5f9; }
.sf-overall-score span { font-size: 18px; font-weight: 700; margin-left: 8px; }

/* ── Already submitted notice ──────────────────────────────────────────────── */
.sf-already-submitted { text-align: center; padding: 14px 20px; border-radius: 8px; background: #f0fdf4; border: 1px solid #bbf7d0; color: #059669; font-size: 14px; font-weight: 600; }
</style>
