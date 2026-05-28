<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl max-h-[92vh] flex flex-col">
      <!-- Header -->
      <div class="flex items-center justify-between px-6 py-4 border-b">
        <h2 class="text-lg font-semibold text-gray-800">Schedule Interview</h2>
        <button @click="$emit('close')" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
      </div>

      <!-- Body -->
      <div class="overflow-y-auto flex-1 px-6 py-5 space-y-5">
        <!-- Title + Type -->
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="label">Interview Title</label>
            <input v-model="form.title" type="text" class="input" placeholder="e.g. Technical Round 1" />
          </div>
          <div>
            <label class="label">Type</label>
            <select v-model="form.interview_type" class="input">
              <option value="phone">Phone</option>
              <option value="video">Video</option>
              <option value="onsite">Onsite</option>
              <option value="panel">Panel</option>
              <option value="technical">Technical</option>
              <option value="hr">HR</option>
            </select>
          </div>
        </div>

        <!-- Date/Time + Duration -->
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="label">Date &amp; Time</label>
            <input v-model="form.scheduled_at" type="datetime-local" class="input" />
          </div>
          <div>
            <label class="label">Duration (minutes)</label>
            <input v-model.number="form.duration_minutes" type="number" min="15" max="480" step="15" class="input" />
          </div>
        </div>

        <!-- Location -->
        <div>
          <label class="label">Location / Meeting Link</label>
          <input v-model="form.location" type="text" class="input" placeholder="Room 3A or https://meet.google.com/..." />
        </div>

        <!-- Agenda -->
        <div>
          <label class="label">Agenda</label>
          <textarea v-model="form.agenda" rows="3" class="input" placeholder="Outline the topics to be covered…"></textarea>
        </div>

        <!-- Panelists -->
        <div>
          <label class="label">Interview Panel</label>
          <div class="flex gap-2 mb-2">
            <input
              v-model="searchQuery"
              type="text"
              class="input flex-1"
              placeholder="Search employees by name or email…"
              @input="searchEmployees"
            />
          </div>
          <!-- Search results dropdown -->
          <div v-if="searchResults.length" class="border rounded-lg divide-y mb-3 max-h-40 overflow-y-auto">
            <button
              v-for="emp in searchResults"
              :key="emp.id"
              type="button"
              @click="addPanelist(emp)"
              class="w-full text-left px-3 py-2 hover:bg-gray-50 flex items-center gap-3"
            >
              <div>
                <p class="text-sm font-medium text-gray-800">{{ emp.name }}</p>
                <p class="text-xs text-gray-500">{{ emp.designation ?? emp.work_email }}</p>
              </div>
            </button>
          </div>

          <!-- Selected panelists -->
          <div v-if="form.panelists.length" class="border rounded-lg divide-y">
            <div
              v-for="(p, idx) in form.panelists"
              :key="p.employee_id"
              class="flex items-center gap-3 px-3 py-2"
            >
              <div class="flex-1">
                <p class="text-sm font-medium text-gray-800">{{ p.name }}</p>
                <p class="text-xs text-gray-500">{{ p.work_email }}</p>
              </div>
              <select v-model="p.role" class="input text-sm py-1 w-32">
                <option value="lead">Lead</option>
                <option value="panelist">Panelist</option>
                <option value="observer">Observer</option>
              </select>
              <button @click="removePanelist(idx)" type="button" class="text-red-400 hover:text-red-600 text-lg leading-none">&times;</button>
            </div>
          </div>
          <p v-if="!form.panelists.length" class="text-xs text-gray-400 mt-1">No panelists added yet. Search above to add.</p>
        </div>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
      </div>

      <!-- Footer -->
      <div class="px-6 py-4 border-t flex justify-end gap-3">
        <button @click="$emit('close')" class="btn-secondary">Cancel</button>
        <button @click="submit" :disabled="saving" class="btn-primary">
          {{ saving ? 'Scheduling…' : 'Schedule Interview' }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive } from 'vue';
import { api } from '../../lib/api';
  import { currentOrgSlug } from '../../stores/auth';
import type { Interview, EmployeeSearchResult } from '../../lib/types';

const props = defineProps<{
  applicationId: number;
  vacancyId: number;
}>();

const emit = defineEmits<{
  (e: 'close'): void;
  (e: 'saved', interview: Interview): void;
}>();

// ── State ─────────────────────────────────────────────────────────────────────

interface PanelistEntry {
  id: number;
  employee_id: number;
  name: string;
  work_email: string | null;
  designation: string | null;
  role: 'lead' | 'panelist' | 'observer';
}

const form = reactive({
  title: '',
  interview_type: 'panel' as string,
  scheduled_at: '',
  duration_minutes: 60,
  location: '',
  agenda: '',
  panelists: [] as PanelistEntry[],
});

const searchQuery = ref('');
const searchResults = ref<EmployeeSearchResult[]>([]);
const saving = ref(false);
const error = ref('');

let searchTimer: ReturnType<typeof setTimeout> | null = null;

// ── Methods ───────────────────────────────────────────────────────────────────

function searchEmployees() {
  if (searchTimer) clearTimeout(searchTimer);
  const q = searchQuery.value.trim();
  if (!q) { searchResults.value = []; return; }

  searchTimer = setTimeout(async () => {
    try {
      const res = await api<{ data: EmployeeSearchResult[] }>('GET', '/api/v1/recruitment/employees-search', {
        org: currentOrgSlug.value,
        params: { q },
      });
      const existing = new Set(form.panelists.map(p => p.id));
      searchResults.value = (res.data ?? []).filter(e => !existing.has(e.id));
    } catch {
      searchResults.value = [];
    }
  }, 300);
}

function addPanelist(emp: EmployeeSearchResult) {
  form.panelists.push({ ...emp, employee_id: emp.id, role: 'panelist' });
  searchQuery.value = '';
  searchResults.value = [];
}

function removePanelist(idx: number) {
  form.panelists.splice(idx, 1);
}

async function submit() {
  error.value = '';
  if (!form.title.trim()) { error.value = 'Title is required.'; return; }
  if (!form.scheduled_at) { error.value = 'Date & time is required.'; return; }
  if (!form.panelists.length) { error.value = 'At least one panelist is required.'; return; }

  saving.value = true;
  try {
    const payload = {
      title: form.title.trim(),
      interview_type: form.interview_type,
      scheduled_at: form.scheduled_at,
      duration_minutes: form.duration_minutes,
      location: form.location || null,
      agenda: form.agenda || null,
      panelist_ids: form.panelists.map(p => p.id),
      panelist_roles: form.panelists.map(p => p.role),
    };
    const interview = await api<Interview>('POST', `/api/v1/recruitment/applications/${props.applicationId}/interviews`, {
      org: currentOrgSlug.value,
      body: payload,
    });
    emit('saved', interview);
  } catch (e: any) {
    error.value = e?.message ?? 'Failed to schedule interview.';
  } finally {
    saving.value = false;
  }
}
</script>

<style scoped>
.label { @apply block text-sm font-medium text-gray-700 mb-1; }
.input { @apply block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500; }
.btn-primary { @apply px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700 disabled:opacity-50; }
.btn-secondary { @apply px-4 py-2 rounded-lg border border-gray-300 text-gray-700 text-sm font-medium hover:bg-gray-50; }
</style>
