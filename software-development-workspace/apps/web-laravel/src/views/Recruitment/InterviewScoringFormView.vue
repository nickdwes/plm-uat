<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
import { useRoute } from "vue-router";

// Public view — no auth, no api wrapper needed
const route = useRoute();
const token = route.params.token as string;

// ── State ──────────────────────────────────────────────────────────────────
const loading    = ref(true);
const error      = ref("");
const submitting = ref(false);
const submitted  = ref(false);
const isDraft    = ref(false);

interface FormData {
  interview:  Record<string, unknown> | null;
  candidate:  { name: string; resume_url: string | null } | null;
  vacancy:    { name: string } | null;
  panelist:   { name: string; role: string } | null;
  criteria:   Record<string, string>;
  existing_score: Record<string, unknown> | null;
}

const data = ref<FormData>({
  interview: null, candidate: null, vacancy: null, panelist: null,
  criteria: {}, existing_score: null,
});

const SCORE_LABELS: Record<number, string> = {
  1: "1 — Poor",
  2: "2 — Below Average",
  3: "3 — Average",
  4: "4 — Good",
  5: "5 — Excellent",
};

const SCORE_COLORS: Record<number, string> = {
  1: "#dc2626", 2: "#ea580c", 3: "#d97706", 4: "#0ea5e9", 5: "#059669",
};

// ── Scores form ────────────────────────────────────────────────────────────
const scores            = ref<Record<string, number | null>>({});
const criterionComments = ref<Record<string, string>>({});
const remarks           = ref("");
const recommendation    = ref<"hire" | "hold" | "reject" | "">("")

const technicalExercise = ref({
  conducted: false,
  score:     null as number | null,
  notes:     "",
});

const total = computed(() => {
  const vals = Object.values(scores.value).filter(v => v !== null) as number[];
  return vals.reduce((a, b) => a + b, 0);
});
const average = computed(() => {
  const vals = Object.values(scores.value).filter(v => v !== null) as number[];
  return vals.length ? Math.round((vals.reduce((a, b) => a + b, 0) / vals.length) * 10) / 10 : 0;
});
const allScored = computed(() => {
  const keys = Object.keys(data.value.criteria ?? {});
  return keys.length > 0 && keys.every(k => scores.value[k] != null && scores.value[k]! >= 1);
});

// ── API calls (direct fetch, no auth) ─────────────────────────────────────
const apiBase = import.meta.env.VITE_LARAVEL_API_URL ?? "";

async function loadForm() {
  loading.value = true; error.value = "";
  try {
    const res = await fetch(`${apiBase}/api/public/scoring/${token}`);
    if (!res.ok) {
      const j = await res.json().catch(() => ({}));
      throw new Error(j.message ?? `Error ${res.status}`);
    }
    const j = await res.json();
    data.value = j;

    // Initialise score fields
    const keys = Object.keys(j.criteria ?? {});
    keys.forEach(k => { scores.value[k] = null; criterionComments.value[k] = ""; });

    // Pre-fill from existing draft
    if (j.existing_score) {
      const ex = j.existing_score;
      keys.forEach(k => {
        if (ex[k] != null) scores.value[k] = ex[k];
        criterionComments.value[k] = ex.criterion_comments?.[k] ?? "";
      });
      remarks.value        = ex.remarks ?? "";
      recommendation.value = ex.recommendation ?? "";
      technicalExercise.value.conducted = ex.technical_exercise_conducted ?? false;
      technicalExercise.value.score     = ex.technical_exercise_score ?? null;
      technicalExercise.value.notes     = ex.technical_exercise_notes ?? "";
      if (ex.submitted) submitted.value = true;
    }
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to load form.";
  } finally {
    loading.value = false;
  }
}

async function doSubmit(draft: boolean) {
  if (!draft && !allScored.value) {
    error.value = "Please score all criteria before submitting."; return;
  }
  submitting.value = true; error.value = ""; isDraft.value = draft;
  try {
    const body: Record<string, unknown> = {
      ...scores.value,
      criterion_comments:           criterionComments.value,
      technical_exercise_conducted: technicalExercise.value.conducted,
      technical_exercise_score:     technicalExercise.value.conducted ? technicalExercise.value.score : undefined,
      technical_exercise_notes:     technicalExercise.value.conducted ? (technicalExercise.value.notes || undefined) : undefined,
      remarks:        remarks.value || undefined,
      recommendation: recommendation.value || undefined,
      draft,
    };

    const res = await fetch(`${apiBase}/api/public/scoring/${token}`, {
      method:  "POST",
      headers: { "Content-Type": "application/json", "Accept": "application/json" },
      body:    JSON.stringify(body),
    });

    const j = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(j.message ?? `Error ${res.status}`);

    if (!draft) submitted.value = true;
    else        error.value = "";   // draft saved silently
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Submission failed.";
  } finally {
    submitting.value = false;
  }
}

function fmtDate(iso?: unknown): string {
  if (!iso || typeof iso !== "string") return "—";
  return new Date(iso).toLocaleString("en-GB", { weekday: "long", day: "numeric", month: "long", year: "numeric", hour: "2-digit", minute: "2-digit" });
}

onMounted(loadForm);
</script>

<template>
  <div class="sf-page">

    <!-- Header bar -->
    <div class="sf-topbar">
      <div class="sf-brand">Interview Scoring Form</div>
      <div class="sf-scale-note">Scale: <strong>1</strong> (Poor) &mdash; <strong>5</strong> (Excellent)</div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="sf-center">
      <div class="sf-spinner"></div>
      <p>Loading scoring form&hellip;</p>
    </div>

    <!-- Error -->
    <div v-else-if="error && !data.interview" class="sf-center">
      <div class="sf-error-icon">&#9888;</div>
      <h2>{{ error }}</h2>
      <p class="sf-muted">This link may have expired or already been used.</p>
    </div>

    <!-- Submitted -->
    <div v-else-if="submitted" class="sf-center sf-success-state">
      <div class="sf-success-icon">&#10003;</div>
      <h2>Scores Submitted</h2>
      <p class="sf-muted">Your scoring form has been submitted. Thank you!</p>
      <div class="sf-result-summary">
        <div class="sf-result-item">
          <span class="sf-result-label">Total</span>
          <span class="sf-result-value">{{ total.toFixed(1) }} / {{ Object.keys(data.criteria).length * 5 }}</span>
        </div>
        <div class="sf-result-item">
          <span class="sf-result-label">Average</span>
          <span class="sf-result-value" :style="{ color: average >= 4 ? '#059669' : average >= 3 ? '#d97706' : '#dc2626' }">
            {{ average.toFixed(1) }} / 5
          </span>
        </div>
      </div>
    </div>

    <!-- Main form -->
    <template v-else-if="data.interview">
      <div class="sf-container">

        <!-- Interview info card -->
        <div class="sf-info-card">
          <div class="sf-info-header">
            <div>
              <h1 class="sf-interview-title">{{ data.interview.title as string }}</h1>
              <div class="sf-interview-meta">
                <span class="sf-badge sf-badge-blue">{{ (data.interview.interview_type as string ?? "").replace("_"," ") }}</span>
                <span class="sf-dot">&#183;</span>
                <span>{{ fmtDate(data.interview.scheduled_at) }}</span>
                <span class="sf-dot">&#183;</span>
                <span>{{ data.interview.duration_minutes as number }} min</span>
              </div>
            </div>
            <a v-if="data.interview.teams_join_url" :href="(data.interview.teams_join_url as string)" target="_blank" class="sf-teams-btn">
              &#128249; Join Teams Meeting
            </a>
          </div>

          <div class="sf-info-grid">
            <div class="sf-info-cell">
              <div class="sf-info-label">Candidate</div>
              <div class="sf-info-value">{{ data.candidate?.name }}</div>
              <a v-if="data.candidate?.resume_url" :href="data.candidate.resume_url" target="_blank" class="sf-cv-link">
                &#8595; Download CV / Resume
              </a>
            </div>
            <div class="sf-info-cell">
              <div class="sf-info-label">Position</div>
              <div class="sf-info-value">{{ data.vacancy?.name }}</div>
            </div>
            <div class="sf-info-cell">
              <div class="sf-info-label">Your Role</div>
              <div class="sf-info-value sf-capitalize">{{ data.panelist?.role }}</div>
            </div>
            <div v-if="data.interview.location" class="sf-info-cell">
              <div class="sf-info-label">Location</div>
              <div class="sf-info-value">{{ data.interview.location as string }}</div>
            </div>
          </div>

          <div v-if="data.interview.agenda" class="sf-agenda">
            <div class="sf-info-label">Agenda</div>
            <p class="sf-agenda-text">{{ data.interview.agenda as string }}</p>
          </div>
        </div>

        <!-- Scoring table -->
        <div class="sf-scoring-card">
          <div class="sf-scoring-header">
            <h2 class="sf-scoring-title">Candidate Evaluation</h2>
            <p class="sf-scoring-sub">Score each area from 1 (Poor) to 5 (Excellent). All areas must be scored before submitting.</p>
          </div>

          <div v-if="error" class="sf-error-msg">{{ error }}</div>

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
              <template v-for="(label, key) in data.criteria" :key="key">
                <!-- Score row -->
                <tr :class="{ 'sf-row-scored': scores[key] != null }">
                  <td class="sf-td-area">{{ label }}</td>
                  <td v-for="n in [1,2,3,4,5]" :key="n" class="sf-td-radio">
                    <label class="sf-radio-label" :class="{ 'sf-radio-selected': scores[key] === n }" :style="scores[key] === n ? { background: SCORE_COLORS[n] + '22', borderColor: SCORE_COLORS[n] } : {}">
                      <input
                        type="radio"
                        :name="key"
                        :value="n"
                        v-model="scores[key]"
                        class="sf-radio-input"
                        :disabled="submitted"
                      />
                    </label>
                  </td>
                  <td class="sf-td-given">
                    <span v-if="scores[key] != null" class="sf-given-score" :style="{ color: SCORE_COLORS[scores[key] as number], fontWeight: 700 }">
                      {{ scores[key] }}
                    </span>
                    <span v-else class="sf-given-empty">—</span>
                  </td>
                </tr>
                <!-- Comment row -->
                <tr class="sf-row-comment" :class="{ 'sf-row-comment-scored': scores[key] != null }">
                  <td colspan="7" class="sf-td-comment">
                    <textarea
                      v-model="criterionComments[key]"
                      class="sf-criterion-textarea"
                      placeholder="Add a comment to support your score (optional)…"
                      rows="2"
                      :disabled="submitted"
                    ></textarea>
                  </td>
                </tr>
              </template>
            </tbody>
            <tfoot>
              <tr class="sf-tfoot-total">
                <td class="sf-td-area"><strong>TOTAL</strong></td>
                <td colspan="5" class="sf-td-total-spacer"></td>
                <td class="sf-td-given"><strong class="sf-total-val">{{ total.toFixed(1) }}</strong></td>
              </tr>
              <tr class="sf-tfoot-avg">
                <td class="sf-td-area"><strong>Average Score</strong></td>
                <td colspan="5" class="sf-td-total-spacer"></td>
                <td class="sf-td-given">
                  <strong class="sf-avg-val"
                    :style="{ color: average >= 4 ? '#059669' : average >= 3 ? '#d97706' : '#dc2626' }">
                    {{ average.toFixed(1) }}
                  </strong>
                </td>
              </tr>
            </tfoot>
          </table>

          <!-- Scale legend -->
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
              <input type="checkbox" v-model="technicalExercise.conducted" :disabled="submitted" class="sf-hidden-radio" />
              <span class="sf-toggle-track"><span class="sf-toggle-thumb"></span></span>
              {{ technicalExercise.conducted ? "Exercise was conducted" : "No technical exercise" }}
            </label>
          </div>

          <template v-if="technicalExercise.conducted">
            <div class="sf-tech-body">
              <div class="sf-field sf-field-inline">
                <label class="sf-label">Score <span class="sf-label-hint">(0 – 100)</span></label>
                <div class="sf-score-input-wrap">
                  <input
                    type="number"
                    v-model.number="technicalExercise.score"
                    min="0" max="100" step="0.5"
                    class="sf-score-input"
                    placeholder="e.g. 75"
                    :disabled="submitted"
                  />
                  <span class="sf-score-input-suffix">/ 100</span>
                  <span
                    v-if="technicalExercise.score != null"
                    class="sf-score-badge"
                    :style="{
                      background: technicalExercise.score >= 70 ? '#f0fdf4' : technicalExercise.score >= 50 ? '#fffbeb' : '#fef2f2',
                      color:      technicalExercise.score >= 70 ? '#059669' : technicalExercise.score >= 50 ? '#d97706' : '#dc2626',
                      borderColor: technicalExercise.score >= 70 ? '#bbf7d0' : technicalExercise.score >= 50 ? '#fde68a' : '#fecaca',
                    }"
                  >{{ technicalExercise.score >= 70 ? 'Pass' : technicalExercise.score >= 50 ? 'Borderline' : 'Fail' }}</span>
                </div>
              </div>
              <div class="sf-field">
                <label class="sf-label">Exercise Notes</label>
                <textarea
                  v-model="technicalExercise.notes"
                  class="sf-textarea"
                  rows="3"
                  placeholder="Describe the exercise, key observations, and how the candidate performed…"
                  :disabled="submitted"
                ></textarea>
              </div>
            </div>
          </template>
        </div>

        <!-- Remarks + Recommendation -->
        <div class="sf-remarks-card">
          <div class="sf-field">
            <label class="sf-label">Remarks / Overall Assessment</label>
            <textarea
              v-model="remarks"
              class="sf-textarea"
              rows="5"
              placeholder="Summarise the candidate's strengths, areas for development, and overall impression..."
              :disabled="submitted"
            ></textarea>
          </div>

          <div class="sf-field">
            <label class="sf-label">Recommendation</label>
            <div class="sf-rec-group">
              <label class="sf-rec-btn" :class="{ 'sf-rec-hire': recommendation === 'hire' }">
                <input type="radio" v-model="recommendation" value="hire" :disabled="submitted" class="sf-hidden-radio" />
                &#9989; Hire
              </label>
              <label class="sf-rec-btn" :class="{ 'sf-rec-hold': recommendation === 'hold' }">
                <input type="radio" v-model="recommendation" value="hold" :disabled="submitted" class="sf-hidden-radio" />
                &#9203; Hold
              </label>
              <label class="sf-rec-btn" :class="{ 'sf-rec-reject': recommendation === 'reject' }">
                <input type="radio" v-model="recommendation" value="reject" :disabled="submitted" class="sf-hidden-radio" />
                &#10060; Reject
              </label>
            </div>
          </div>
        </div>

        <!-- Actions -->
        <div class="sf-actions" v-if="!submitted">
          <button class="sf-btn-draft" :disabled="submitting" @click="doSubmit(true)">
            {{ submitting && isDraft ? "Saving..." : "Save Draft" }}
          </button>
          <button
            class="sf-btn-submit"
            :disabled="submitting || !allScored"
            :title="!allScored ? 'Score all areas to submit' : ''"
            @click="doSubmit(false)"
          >
            {{ submitting && !isDraft ? "Submitting..." : "Submit Scores" }}
          </button>
        </div>

        <div v-if="submitted" class="sf-already-submitted">
          &#10003; Your scores have been submitted. Thank you, {{ data.panelist?.name }}!
        </div>

      </div>
    </template>
  </div>
</template>

<style scoped>
/* ── Page shell ──────────────────────────────────────────────────────────── */
.sf-page { min-height: 100vh; background: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }

.sf-topbar {
  background: #004C97; color: #fff;
  display: flex; align-items: center; justify-content: space-between;
  padding: 14px 24px; gap: 12px;
}
.sf-brand { font-size: 16px; font-weight: 700; letter-spacing: 0.3px; }
.sf-scale-note { font-size: 13px; opacity: 0.85; }

.sf-container { max-width: 900px; margin: 0 auto; padding: 24px 16px 60px; display: flex; flex-direction: column; gap: 20px; }

/* ── Loading / error / success center ────────────────────────────────────── */
.sf-center { display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 60vh; gap: 12px; text-align: center; padding: 40px 20px; }
.sf-spinner { width: 36px; height: 36px; border: 3px solid #e2e8f0; border-top-color: #004C97; border-radius: 50%; animation: spin 0.7s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.sf-muted { color: #64748b; font-size: 14px; }
.sf-error-icon { font-size: 48px; color: #dc2626; }
.sf-success-icon { width: 64px; height: 64px; border-radius: 50%; background: #059669; color: #fff; font-size: 32px; display: flex; align-items: center; justify-content: center; font-weight: 700; }
.sf-success-state h2 { color: #059669; }
.sf-result-summary { display: flex; gap: 32px; margin-top: 16px; }
.sf-result-item { display: flex; flex-direction: column; align-items: center; gap: 4px; }
.sf-result-label { font-size: 12px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
.sf-result-value { font-size: 28px; font-weight: 700; color: #1e293b; }

/* ── Info card ───────────────────────────────────────────────────────────── */
.sf-info-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; }
.sf-info-header { display: flex; align-items: flex-start; justify-content: space-between; padding: 20px 24px 16px; gap: 12px; flex-wrap: wrap; border-bottom: 1px solid #e2e8f0; }
.sf-interview-title { font-size: 20px; font-weight: 700; color: #0f172a; margin: 0 0 8px; }
.sf-interview-meta { display: flex; align-items: center; gap: 8px; font-size: 13px; color: #64748b; flex-wrap: wrap; }
.sf-badge { padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; text-transform: capitalize; }
.sf-badge-blue { background: #eff6ff; color: #3b82f6; }
.sf-dot { color: #cbd5e1; }
.sf-teams-btn { padding: 8px 16px; border-radius: 8px; background: #5865F2; color: #fff; text-decoration: none; font-size: 13px; font-weight: 600; white-space: nowrap; flex-shrink: 0; }
.sf-teams-btn:hover { background: #4752C4; }

.sf-info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0; padding: 16px 24px; }
.sf-info-cell { padding: 8px 12px 8px 0; }
.sf-info-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; margin-bottom: 3px; }
.sf-info-value { font-size: 14px; font-weight: 600; color: #0f172a; }
.sf-capitalize { text-transform: capitalize; }
.sf-cv-link { font-size: 12px; color: #004C97; text-decoration: none; display: inline-block; margin-top: 4px; padding: 2px 8px; border-radius: 6px; border: 1px solid #bfdbfe; background: #eff6ff; }
.sf-cv-link:hover { background: #dbeafe; }
.sf-agenda { padding: 12px 24px 16px; border-top: 1px solid #f1f5f9; }
.sf-agenda-text { font-size: 13px; color: #374151; white-space: pre-wrap; margin: 4px 0 0; }

/* ── Scoring table ───────────────────────────────────────────────────────── */
.sf-scoring-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; }
.sf-scoring-header { padding: 20px 24px 16px; border-bottom: 1px solid #e2e8f0; }
.sf-scoring-title { font-size: 17px; font-weight: 700; color: #0f172a; margin: 0 0 4px; }
.sf-scoring-sub { font-size: 13px; color: #64748b; margin: 0; }
.sf-error-msg { background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; padding: 10px 24px; font-size: 13px; }

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
  display: inline-block; width: 36px; height: 36px; border-radius: 50%;
  border: 2px solid #e2e8f0; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  transition: border-color 0.15s, background 0.15s;
}
.sf-radio-label:hover { border-color: #94a3b8; background: #f1f5f9; }
.sf-radio-selected { border-width: 2px !important; }
.sf-radio-input { display: none; }

.sf-tfoot-total td, .sf-tfoot-avg td { padding: 12px 16px; background: #f8fafc; border-top: 2px solid #e2e8f0; }
.sf-total-val { font-size: 18px; color: #0f172a; }
.sf-avg-val { font-size: 20px; font-weight: 700; }

/* ── Criterion comment rows ──────────────────────────────────────────────── */
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
.sf-criterion-textarea:disabled { opacity: 0.7; cursor: not-allowed; }

/* ── Technical exercise card ─────────────────────────────────────────────── */
.sf-tech-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; }
.sf-tech-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 18px 24px; border-bottom: 1px solid #e2e8f0; gap: 16px; flex-wrap: wrap;
}
.sf-tech-body { padding: 20px 24px; display: flex; flex-direction: column; gap: 16px; }

.sf-toggle-label {
  display: flex; align-items: center; gap: 10px; cursor: pointer;
  font-size: 13px; font-weight: 600; color: #64748b; user-select: none;
}
.sf-toggle-active { color: #059669; }
.sf-toggle-track {
  position: relative; width: 40px; height: 22px;
  background: #e2e8f0; border-radius: 11px; flex-shrink: 0;
  transition: background 0.2s;
}
.sf-toggle-active .sf-toggle-track { background: #059669; }
.sf-toggle-thumb {
  position: absolute; top: 3px; left: 3px;
  width: 16px; height: 16px; border-radius: 50%; background: #fff;
  box-shadow: 0 1px 3px rgba(0,0,0,0.2); transition: transform 0.2s;
}
.sf-toggle-active .sf-toggle-thumb { transform: translateX(18px); }

.sf-field-inline { flex-direction: row !important; align-items: center; gap: 16px !important; flex-wrap: wrap; }
.sf-field-inline .sf-label { white-space: nowrap; margin: 0; min-width: 60px; }
.sf-score-input-wrap { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.sf-score-input {
  width: 90px; padding: 8px 12px; border: 1.5px solid #e2e8f0; border-radius: 8px;
  font-size: 18px; font-weight: 700; color: #0f172a; background: #f8fafc;
  outline: none; text-align: center; -moz-appearance: textfield;
}
.sf-score-input::-webkit-inner-spin-button { -webkit-appearance: none; }
.sf-score-input:focus { border-color: #004C97; background: #fff; }
.sf-score-input:disabled { opacity: 0.7; cursor: not-allowed; }
.sf-score-input-suffix { font-size: 14px; color: #64748b; font-weight: 600; }
.sf-score-badge {
  padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 700;
  border: 1px solid;
}
.sf-label-hint { font-size: 11px; font-weight: 400; color: #94a3b8; margin-left: 4px; }

/* ── Legend ──────────────────────────────────────────────────────────────── */
.sf-legend { display: flex; flex-wrap: wrap; gap: 12px; padding: 12px 24px 16px; border-top: 1px solid #f1f5f9; }
.sf-legend-item { font-size: 12px; font-weight: 600; }

/* ── Remarks card ────────────────────────────────────────────────────────── */
.sf-remarks-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px 24px; display: flex; flex-direction: column; gap: 16px; }
.sf-field { display: flex; flex-direction: column; gap: 6px; }
.sf-label { font-size: 13px; font-weight: 600; color: #374151; }
.sf-textarea { border: 1.5px solid #e2e8f0; border-radius: 8px; padding: 10px 14px; font-size: 13px; color: #0f172a; background: #f8fafc; outline: none; resize: vertical; min-height: 100px; font-family: inherit; width: 100%; box-sizing: border-box; }
.sf-textarea:focus { border-color: #004C97; background: #fff; }
.sf-textarea:disabled { opacity: 0.7; cursor: not-allowed; }

.sf-rec-group { display: flex; gap: 12px; flex-wrap: wrap; }
.sf-rec-btn {
  padding: 10px 24px; border-radius: 8px; border: 2px solid #e2e8f0;
  cursor: pointer; font-size: 14px; font-weight: 600; color: #374151;
  transition: all 0.15s; user-select: none;
}
.sf-rec-btn:hover { border-color: #94a3b8; }
.sf-rec-hire   { border-color: #059669 !important; background: #f0fdf4; color: #059669 !important; }
.sf-rec-hold   { border-color: #d97706 !important; background: #fffbeb; color: #d97706 !important; }
.sf-rec-reject { border-color: #dc2626 !important; background: #fef2f2; color: #dc2626 !important; }
.sf-hidden-radio { display: none; }

/* ── Actions ─────────────────────────────────────────────────────────────── */
.sf-actions { display: flex; justify-content: flex-end; gap: 12px; padding: 4px 0; }
.sf-btn-draft {
  padding: 11px 24px; border-radius: 8px; border: 1.5px solid #e2e8f0;
  background: #fff; color: #374151; font-size: 14px; font-weight: 600; cursor: pointer;
}
.sf-btn-draft:hover { border-color: #94a3b8; }
.sf-btn-draft:disabled { opacity: .5; cursor: not-allowed; }
.sf-btn-submit {
  padding: 11px 28px; border-radius: 8px; border: none;
  background: #004C97; color: #fff; font-size: 14px; font-weight: 700; cursor: pointer;
  transition: background 0.15s;
}
.sf-btn-submit:hover:not(:disabled) { background: #003a75; }
.sf-btn-submit:disabled { opacity: .4; cursor: not-allowed; }

.sf-already-submitted {
  text-align: center; padding: 16px; border-radius: 8px;
  background: #f0fdf4; border: 1px solid #bbf7d0; color: #059669;
  font-size: 14px; font-weight: 600;
}
</style>
