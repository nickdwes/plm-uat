<template>
  <div class="min-h-screen bg-gray-50 py-8 px-4">
    <!-- Loading / Error -->
    <div v-if="loading" class="flex justify-center items-center h-40">
      <svg class="animate-spin h-8 w-8 text-indigo-600" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
      </svg>
    </div>
    <div v-else-if="loadError" class="text-center text-red-600">{{ loadError }}</div>

    <template v-else-if="interview">
      <div class="max-w-4xl mx-auto space-y-6">
        <!-- Header -->
        <div class="bg-white rounded-xl shadow-sm p-6">
          <div class="flex items-start justify-between">
            <div>
              <h1 class="text-2xl font-bold text-gray-900">{{ interview.title }}</h1>
              <p class="text-gray-500 mt-1">
                {{ formatDate(interview.scheduled_at) }} &middot; {{ interview.duration_minutes }} min &middot;
                <span class="capitalize">{{ interview.interview_type }}</span>
                <span v-if="interview.location"> &middot; {{ interview.location }}</span>
              </p>
            </div>
            <span :class="statusClass(interview.status)" class="text-xs px-2 py-1 rounded-full font-medium capitalize">
              {{ interview.status }}
            </span>
          </div>
        </div>

        <!-- Panelist selector (if more than one panelist) -->
        <div v-if="interview.panelists.length > 1" class="bg-white rounded-xl shadow-sm p-5">
          <label class="block text-sm font-medium text-gray-700 mb-2">You are scoring as:</label>
          <select v-model="selectedPanelistId" class="input w-full max-w-xs">
            <option :value="null" disabled>Select your name</option>
            <option v-for="p in interview.panelists" :key="p.employee_id" :value="p.employee_id">
              {{ p.name }} ({{ p.role }})
            </option>
          </select>
        </div>

        <!-- Score Form -->
        <div class="bg-white rounded-xl shadow-sm p-6">
          <h2 class="text-lg font-semibold text-gray-800 mb-4">Scoring Sheet</h2>
          <p class="text-xs text-gray-500 mb-4">Rate each criterion from 0 to 10.</p>

          <div class="overflow-x-auto">
            <table class="w-full text-sm border-collapse">
              <thead>
                <tr class="bg-gray-50">
                  <th class="text-left px-3 py-2 border text-gray-600 w-1/2">Criteria</th>
                  <th class="text-center px-3 py-2 border text-gray-600 w-20">Score (0–10)</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(label, key) in CRITERIA" :key="key" class="border-b hover:bg-gray-50">
                  <td class="px-3 py-3 border text-gray-700">{{ label }}</td>
                  <td class="px-3 py-3 border text-center">
                    <input
                      v-model.number="scores[key]"
                      type="number"
                      min="0"
                      max="10"
                      step="0.5"
                      class="w-20 text-center rounded border border-gray-300 px-2 py-1 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                      @input="recompute"
                    />
                  </td>
                </tr>
                <!-- Totals row -->
                <tr class="bg-indigo-50 font-semibold">
                  <td class="px-3 py-3 border text-indigo-700">TOTAL</td>
                  <td class="px-3 py-3 border text-center text-indigo-700">{{ computedTotal ?? '–' }}</td>
                </tr>
                <tr class="bg-indigo-50 font-semibold">
                  <td class="px-3 py-3 border text-indigo-700">Average Score</td>
                  <td class="px-3 py-3 border text-center text-indigo-700">{{ computedAvg ?? '–' }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Comments -->
          <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label class="label">Strengths</label>
              <textarea v-model="form.strengths" rows="3" class="input" placeholder="Key strengths observed…"></textarea>
            </div>
            <div>
              <label class="label">Areas for Improvement</label>
              <textarea v-model="form.weaknesses" rows="3" class="input" placeholder="Areas needing development…"></textarea>
            </div>
          </div>

          <div class="mt-4">
            <label class="label">Remarks</label>
            <textarea v-model="form.remarks" rows="3" class="input" placeholder="Overall remarks…"></textarea>
          </div>

          <div class="mt-4">
            <label class="label">Recommendation</label>
            <select v-model="form.recommendation" class="input w-48">
              <option value="">— Select —</option>
              <option value="hire">Hire</option>
              <option value="hold">Hold</option>
              <option value="reject">Reject</option>
            </select>
          </div>

          <p v-if="saveError" class="mt-3 text-sm text-red-600">{{ saveError }}</p>
          <p v-if="saveSuccess" class="mt-3 text-sm text-green-600">{{ saveSuccess }}</p>

          <div class="mt-5 flex gap-3">
            <button @click="save(false)" :disabled="saving || !selectedPanelistId" class="btn-secondary">
              {{ saving && !submitting ? 'Saving…' : 'Save Draft' }}
            </button>
            <button @click="save(true)" :disabled="saving || !selectedPanelistId" class="btn-primary">
              {{ saving && submitting ? 'Submitting…' : 'Submit Final Score' }}
            </button>
          </div>
        </div>

        <!-- Panel Scores Summary -->
        <div v-if="interview.scores && interview.scores.length" class="bg-white rounded-xl shadow-sm p-6">
          <h2 class="text-lg font-semibold text-gray-800 mb-4">Panel Scores Summary</h2>
          <div class="overflow-x-auto">
            <table class="w-full text-sm border-collapse">
              <thead>
                <tr class="bg-gray-50">
                  <th class="text-left px-3 py-2 border text-gray-600">Panelist</th>
                  <th v-for="(label, key) in CRITERIA" :key="key" class="text-center px-2 py-2 border text-gray-600 text-xs">
                    {{ shortLabel(label) }}
                  </th>
                  <th class="text-center px-3 py-2 border text-gray-600">Total</th>
                  <th class="text-center px-3 py-2 border text-gray-600">Avg</th>
                  <th class="text-center px-3 py-2 border text-gray-600">Rec.</th>
                  <th class="text-center px-3 py-2 border text-gray-600">Status</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="s in interview.scores" :key="s.id" class="border-b hover:bg-gray-50">
                  <td class="px-3 py-2 border text-gray-700 font-medium">{{ s.panelist_name }}</td>
                  <td v-for="(_, key) in CRITERIA" :key="key" class="text-center px-2 py-2 border text-gray-600">
                    {{ s[key as keyof typeof s] ?? '–' }}
                  </td>
                  <td class="text-center px-3 py-2 border font-semibold text-indigo-600">{{ s.total ?? '–' }}</td>
                  <td class="text-center px-3 py-2 border font-semibold text-indigo-600">{{ s.average_score ?? '–' }}</td>
                  <td class="text-center px-3 py-2 border capitalize">
                    <span :class="recClass(s.recommendation)">{{ s.recommendation ?? '–' }}</span>
                  </td>
                  <td class="text-center px-3 py-2 border">
                    <span v-if="s.submitted" class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full">Submitted</span>
                    <span v-else class="text-xs bg-yellow-100 text-yellow-700 px-2 py-0.5 rounded-full">Draft</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-if="interview.overall_score !== null" class="mt-3 text-sm text-gray-600">
            <strong>Overall Interview Score:</strong> {{ interview.overall_score }} / 10
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed, onMounted, watch } from 'vue';
import { api } from '../../lib/api';
import { currentOrgSlug } from '../../stores/auth';
import type { Interview, InterviewScore } from '../../lib/types';

const props = defineProps<{ id: string }>();

// ── Criteria config ───────────────────────────────────────────────────────────

const CRITERIA: Record<string, string> = {
  personal_presentation:   'Personal Presentation & Communication',
  training_certifications: 'Training & Certifications',
  role_knowledge:          'Role Knowledge, Skills & Experience',
  problem_solving:         'Creative / Analytical / Problem Solving & Resourcefulness',
  teamskills_leadership:   'Team Skills & Leadership',
  attitude_adaptability:   'Attitude & Adaptability',
  personal_values:         'Personal / Professional Values',
  interests_goals:         'Interests & Goals',
};

// ── State ─────────────────────────────────────────────────────────────────────

const interview = ref<Interview | null>(null);
const loading = ref(true);
const loadError = ref('');
const saving = ref(false);
const submitting = ref(false);
const saveError = ref('');
const saveSuccess = ref('');

const selectedPanelistId = ref<number | null>(null);

const scores = reactive<Record<string, number | null>>({
  personal_presentation:   null,
  training_certifications: null,
  role_knowledge:          null,
  problem_solving:         null,
  teamskills_leadership:   null,
  attitude_adaptability:   null,
  personal_values:         null,
  interests_goals:         null,
});

const form = reactive({
  strengths: '',
  weaknesses: '',
  remarks: '',
  recommendation: '',
});

// ── Computed ──────────────────────────────────────────────────────────────────

const computedTotal = computed(() => {
  const vals = Object.values(scores).filter(v => v !== null && v !== undefined) as number[];
  if (!vals.length) return null;
  return vals.reduce((a, b) => a + b, 0);
});

const computedAvg = computed(() => {
  const vals = Object.values(scores).filter(v => v !== null && v !== undefined) as number[];
  if (!vals.length) return null;
  return (vals.reduce((a, b) => a + b, 0) / vals.length).toFixed(2);
});

// ── Helpers ───────────────────────────────────────────────────────────────────

function recompute() { /* triggers computed */ }

function shortLabel(label: string): string {
  return label.split(/[,&/]/)[0].trim().split(' ').slice(0, 2).join(' ');
}

function formatDate(d: string) {
  return new Date(d).toLocaleString(undefined, {
    weekday: 'short', year: 'numeric', month: 'short', day: 'numeric',
    hour: '2-digit', minute: '2-digit',
  });
}

function statusClass(s: string) {
  const map: Record<string, string> = {
    scheduled: 'bg-blue-100 text-blue-700',
    confirmed: 'bg-green-100 text-green-700',
    completed: 'bg-indigo-100 text-indigo-700',
    cancelled: 'bg-red-100 text-red-700',
    rescheduled: 'bg-yellow-100 text-yellow-700',
  };
  return map[s] ?? 'bg-gray-100 text-gray-700';
}

function recClass(r: string | null) {
  if (!r) return '';
  const map: Record<string, string> = {
    hire: 'text-green-700 font-semibold',
    hold: 'text-yellow-700 font-semibold',
    reject: 'text-red-700 font-semibold',
  };
  return map[r] ?? '';
}

// ── Auto-populate scores when panelist changes ────────────────────────────────

watch(selectedPanelistId, (id) => {
  if (!id || !interview.value) return;
  const existing = interview.value.scores.find(s => s.panelist_id === id);
  if (existing) {
    for (const key of Object.keys(CRITERIA)) {
      scores[key] = (existing as any)[key] ?? null;
    }
    form.strengths      = existing.strengths ?? '';
    form.weaknesses     = existing.weaknesses ?? '';
    form.remarks        = existing.remarks ?? '';
    form.recommendation = existing.recommendation ?? '';
  } else {
    for (const key of Object.keys(CRITERIA)) scores[key] = null;
    form.strengths = form.weaknesses = form.remarks = form.recommendation = '';
  }
});

// ── Load ──────────────────────────────────────────────────────────────────────

onMounted(async () => {
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
  saveError.value = '';
  saveSuccess.value = '';
  saving.value = true;
  submitting.value = submit;
  try {
    const payload: Record<string, any> = {
      panelist_id: selectedPanelistId.value,
      ...scores,
      strengths:      form.strengths || null,
      weaknesses:     form.weaknesses || null,
      remarks:        form.remarks || null,
      recommendation: form.recommendation || null,
      submit,
    };
    const updated = await api<InterviewScore>('POST', `/api/v1/recruitment/interviews/${props.id}/scores`, {
      org: currentOrgSlug.value,
      body: payload,
    });
    // Refresh local scores list
    if (interview.value) {
      const idx = interview.value.scores.findIndex(s => s.panelist_id === updated.panelist_id);
      if (idx >= 0) interview.value.scores[idx] = updated;
      else interview.value.scores.push(updated);
    }
    saveSuccess.value = submit ? 'Score submitted successfully!' : 'Draft saved.';
  } catch (e: any) {
    saveError.value = e?.message ?? 'Failed to save score.';
  } finally {
    saving.value = false;
    submitting.value = false;
  }
}
</script>

<style scoped>
.label { @apply block text-sm font-medium text-gray-700 mb-1; }
.input { @apply block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500; }
.btn-primary { @apply px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700 disabled:opacity-50; }
.btn-secondary { @apply px-4 py-2 rounded-lg border border-gray-300 text-gray-700 text-sm font-medium hover:bg-gray-50 disabled:opacity-50; }
</style>
