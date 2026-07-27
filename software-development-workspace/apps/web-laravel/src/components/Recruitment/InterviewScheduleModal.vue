<template>
  <Teleport to="body">
    <div class="ism-overlay" @click.self="$emit('close')">
      <div class="ism-panel">

        <!-- Header -->
        <div class="ism-header">
          <h2 class="ism-title">{{ savedInterview ? 'Interview Scheduled' : 'Schedule Interview' }}</h2>
          <button class="ism-close" @click="$emit('close')">&times;</button>
        </div>

        <!-- ── POST-SAVE: actions panel ───────────────────────────── -->
        <template v-if="savedInterview">
          <div class="ism-body">

            <!-- Candidate + Vacancy strip (post-save, static) -->
            <div class="ism-context-strip">
              <div class="ism-context-cell">
                <span class="ism-context-label">Candidate</span>
                <span class="ism-context-value">{{ selectedCandidate?.name ?? '—' }}</span>
                <span class="ism-context-sub">{{ selectedCandidate?.email }}</span>
              </div>
              <div class="ism-context-divider"></div>
              <div class="ism-context-cell">
                <span class="ism-context-label">Position</span>
                <span class="ism-context-value">{{ props.vacancyInfo?.name ?? '—' }}</span>
                <span class="ism-context-sub">{{ props.vacancyInfo?.job_title }}</span>
              </div>
            </div>

            <!-- Teams join URL banner -->
            <div v-if="savedInterview.teams_join_url" class="ism-teams-banner">
              <span class="ism-teams-icon">&#128249;</span>
              <div class="ism-teams-info">
                <p class="ism-teams-label">Teams Meeting Ready</p>
                <a :href="savedInterview.teams_join_url" target="_blank" class="ism-teams-url">
                  {{ savedInterview.teams_join_url }}
                </a>
              </div>
            </div>

            <!-- Interview summary -->
            <div class="ism-summary">
              <div class="ism-summary-row">
                <span class="ism-summary-item"><b>Title:</b> {{ savedInterview.title }}</span>
                <span class="ism-summary-item"><b>Type:</b> {{ savedInterview.interview_type }}</span>
              </div>
              <div class="ism-summary-row">
                <span class="ism-summary-item"><b>Date:</b> {{ fmtDate(savedInterview.scheduled_at) }}</span>
                <span class="ism-summary-item"><b>Duration:</b> {{ savedInterview.duration_minutes }} min</span>
              </div>
              <div v-if="savedInterview.location" class="ism-summary-row">
                <span class="ism-summary-item"><b>Location:</b> {{ savedInterview.location }}</span>
              </div>
            </div>

            <!-- Teams generation (if not yet generated) -->
            <div v-if="!savedInterview.teams_join_url" class="ism-action-card">
              <p class="ism-action-card-title">&#128249; Generate Teams Meeting</p>
              <p class="ism-action-card-desc">Creates a Teams calendar event and join link for all panelists.</p>
              <button class="ism-btn ism-btn-indigo" :disabled="actionLoading === 'teams'" @click="generateTeams">
                {{ actionLoading === 'teams' ? 'Generating…' : 'Create Teams Meeting' }}
              </button>
            </div>

            <!-- Candidate + Panelist invite cards -->
            <div class="ism-action-grid">
              <div class="ism-action-card">
                <p class="ism-action-card-title">&#128101; Candidate Schedule</p>
                <p class="ism-action-card-desc">Send date/time/location/Teams link to the candidate. No scoring form included.</p>
                <p v-if="savedInterview.candidate_invite_sent" class="ism-sent-ok">&#10003; Invite sent {{ fmtDate(savedInterview.candidate_invite_sent_at) }}</p>
                <button
                  class="ism-btn"
                  :class="savedInterview.candidate_invite_sent ? 'ism-btn-outline' : 'ism-btn-blue'"
                  :disabled="actionLoading === 'candidate'"
                  @click="sendCandidateInvite"
                >
                  {{ actionLoading === 'candidate' ? 'Sending…' : (savedInterview.candidate_invite_sent ? 'Resend Schedule' : 'Send Candidate Schedule') }}
                </button>
              </div>

              <div class="ism-action-card">
                <p class="ism-action-card-title">&#128203; Panelist Invites</p>
                <p class="ism-action-card-desc">Send schedule + candidate CV + personal scoring form link to each panelist.</p>
                <p class="ism-sent-count">{{ savedInterview.panelists?.filter(p => p.invite_sent).length ?? 0 }} / {{ savedInterview.panelists?.length ?? 0 }} invites sent</p>
                <button class="ism-btn ism-btn-green" :disabled="actionLoading === 'panelists'" @click="sendPanelistInvites">
                  {{ actionLoading === 'panelists' ? 'Sending…' : 'Send Panelist Invites & Scoring Links' }}
                </button>
              </div>
            </div>

            <!-- Panelist status table with scoring links -->
            <div v-if="savedInterview.panelists?.length" class="ism-panelist-table">
              <div class="ism-panelist-header">Panelist Status &amp; Scoring Links</div>
              <div v-for="p in savedInterview.panelists" :key="p.id" class="ism-panelist-row">
                <div class="ism-panelist-info">
                  <span class="ism-panelist-name">{{ p.name }}</span>
                  <span class="ism-panelist-role">{{ p.role }}</span>
                </div>
                <div class="ism-panelist-status">
                  <span v-if="p.invite_sent" class="ism-status-ok">&#10003; Invited</span>
                  <span v-else class="ism-status-pending">Not invited</span>
                  <span v-if="p.score_submitted" class="ism-score-badge">&#9733; Scored</span>
                  <template v-if="p.scoring_url">
                    <a :href="p.scoring_url" target="_blank" class="ism-form-link">Open Form &#8599;</a>
                    <button class="ism-copy-btn" :title="'Copy scoring link'" @click="copyLink(p.scoring_url, p.id)">
                      {{ copied === p.id ? '&#10003; Copied' : '&#128203; Copy Link' }}
                    </button>
                  </template>
                  <span v-else class="ism-status-pending">No link yet — send invite first</span>
                </div>
              </div>
            </div>

            <!-- Action error with actionable guidance for Azure permission errors -->
            <div v-if="actionError" class="ism-error-block">
              <div class="ism-error-title">{{ teamsPermissionError ? '🔐 Microsoft Graph — Access Denied' : '⚠️ Action Failed' }}</div>
              <div class="ism-error-body">{{ teamsPermissionError ? teamsPermissionError : actionError }}</div>
              <div v-if="teamsPermissionError" class="ism-error-steps">
                <p class="ism-error-steps-title">To fix this, an Azure AD admin must:</p>
                <ol>
                  <li>Go to <strong>Azure Portal → App registrations → [your app] → API permissions</strong></li>
                  <li>Add <strong>Calendars.ReadWrite</strong> as an <em>Application</em> permission (not Delegated)</li>
                  <li>Click <strong>Grant admin consent</strong></li>
                  <li>Ensure <strong>HR_CALENDAR_MAILBOX</strong> is set to a valid mailbox in the tenant</li>
                </ol>
              </div>
            </div>
            <p v-if="actionSuccess" class="ism-success">{{ actionSuccess }}</p>
          </div>

          <div class="ism-footer">
            <button class="ism-btn ism-btn-outline" @click="$emit('close')">Done</button>
          </div>
        </template>

        <!-- ── CREATE FORM ─────────────────────────────────────────── -->
        <template v-else>
          <div class="ism-body">

            <!-- Candidate selector + vacancy strip -->
            <div class="ism-context-strip">
              <div class="ism-context-cell ism-context-cell--select">
                <span class="ism-context-label">Candidate</span>
                <template v-if="props.applications && props.applications.length > 1">
                  <select
                    v-model="selectedAppId"
                    class="ism-candidate-select"
                  >
                    <option
                      v-for="app in props.applications"
                      :key="app.id"
                      :value="app.id"
                    >
                      {{ app.candidate?.full_name ?? `${app.candidate?.first_name} ${app.candidate?.last_name}`.trim() }}
                      &nbsp;·&nbsp;{{ app.candidate?.email }}
                    </option>
                  </select>
                  <span class="ism-context-sub">{{ selectedCandidate?.email }}</span>
                </template>
                <template v-else>
                  <span class="ism-context-value">{{ selectedCandidate?.name ?? '—' }}</span>
                  <span class="ism-context-sub">{{ selectedCandidate?.email }}</span>
                </template>
              </div>
              <div class="ism-context-divider"></div>
              <div class="ism-context-cell">
                <span class="ism-context-label">Position</span>
                <span class="ism-context-value">{{ props.vacancyInfo?.name ?? '—' }}</span>
                <span class="ism-context-sub">{{ props.vacancyInfo?.job_title }}</span>
              </div>
            </div>

            <div class="ism-field-grid">
              <div class="ism-field">
                <label class="ism-label">Interview Title</label>
                <input v-model="form.title" type="text" class="ism-input" placeholder="e.g. Technical Round 1" />
              </div>
              <div class="ism-field">
                <label class="ism-label">Type</label>
                <select v-model="form.interview_type" class="ism-input">
                  <option value="phone">Phone</option>
                  <option value="video">Video</option>
                  <option value="onsite">Onsite</option>
                  <option value="panel">Panel</option>
                  <option value="technical">Technical</option>
                  <option value="hr">HR</option>
                </select>
              </div>
              <div class="ism-field">
                <label class="ism-label">Date &amp; Time</label>
                <input v-model="form.scheduled_at" type="datetime-local" class="ism-input" />
              </div>
              <div class="ism-field">
                <label class="ism-label">Duration (minutes)</label>
                <input v-model.number="form.duration_minutes" type="number" min="15" max="480" step="15" class="ism-input" />
              </div>
            </div>

            <div class="ism-field">
              <label class="ism-label">Location / Meeting Link</label>
              <input v-model="form.location" type="text" class="ism-input" placeholder="Room 3A — or leave blank to use Teams link" />
            </div>

            <label class="ism-teams-check">
              <input type="checkbox" v-model="form.createTeams" />
              <span>&#128249; Automatically create a Teams meeting after saving</span>
            </label>

            <div class="ism-field">
              <label class="ism-label">Agenda</label>
              <textarea v-model="form.agenda" rows="3" class="ism-input" placeholder="Outline the topics to be covered…"></textarea>
            </div>

            <div class="ism-field">
              <label class="ism-label">Interview Panel</label>
              <input
                v-model="searchQuery"
                type="text"
                class="ism-input"
                placeholder="Search by name, or type an email and press Enter…"
                @input="searchEmployees"
                @keydown="handleSearchKey"
              />

              <!-- Search results dropdown -->
              <div v-if="searchResults.length" class="ism-search-results">
                <button
                  v-for="emp in searchResults"
                  :key="emp.id"
                  type="button"
                  class="ism-search-item"
                  @click="addPanelist(emp)"
                >
                  <span class="ism-search-name">{{ emp.name }}</span>
                  <span class="ism-search-sub">{{ emp.designation ?? emp.work_email }}</span>
                </button>
              </div>

              <!-- No results — offer add by email -->
              <div v-else-if="searchQuery.trim() && !searchResults.length" class="ism-no-results">
                <template v-if="isValidEmail(searchQuery.trim())">
                  <span class="ism-no-results-msg">Not found as employee.</span>
                  <button type="button" class="ism-add-email-btn" @click="addExternalEmail">
                    + Add <strong>{{ searchQuery.trim() }}</strong> as external panelist
                  </button>
                </template>
                <span v-else class="ism-no-results-msg">No employees found. Type a full email to add externally.</span>
              </div>

              <!-- Added panelists list -->
              <div v-if="form.panelists.length" class="ism-panelist-list">
                <div v-for="(p, idx) in form.panelists" :key="p.is_external ? p.work_email! : p.employee_id" class="ism-panelist-entry">
                  <div class="ism-panelist-entry-info">
                    <span class="ism-panelist-name">
                      {{ p.name }}
                      <span v-if="p.is_external" class="ism-external-tag">external</span>
                    </span>
                    <span class="ism-panelist-role-sub">{{ p.work_email }}</span>
                  </div>
                  <select v-model="p.role" class="ism-input ism-role-select">
                    <option value="lead">Lead</option>
                    <option value="panelist">Panelist</option>
                    <option value="observer">Observer</option>
                  </select>
                  <button type="button" class="ism-remove" @click="removePanelist(idx)">&times;</button>
                </div>
              </div>
              <p v-if="!form.panelists.length" class="ism-hint">No panelists added yet. Search above or type an email to add.</p>
            </div>

            <p v-if="error" class="ism-error">{{ error }}</p>
          </div>

          <div class="ism-footer">
            <button class="ism-btn ism-btn-outline" @click="$emit('close')">Cancel</button>
            <button class="ism-btn ism-btn-primary" :disabled="saving" @click="submit">
              {{ saving ? 'Scheduling…' : 'Schedule Interview' }}
            </button>
          </div>
        </template>

      </div>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { ref, reactive, computed } from 'vue';
import { api } from '../../lib/api';
import { currentOrgSlug } from '../../stores/auth';
import type { Interview, Application, EmployeeSearchResult } from '../../lib/types';

const props = defineProps<{
  initialApplicationId: number;
  vacancyId: number;
  existingInterview?: Interview | null;
  applications?: Application[];
  vacancyInfo?: { id: number; name: string; job_title: string } | null;
}>();

const emit = defineEmits<{
  (e: 'close'): void;
  (e: 'saved', interview: Interview): void;
}>();

interface PanelistEntry {
  id: number;          // 0 for external
  employee_id: number; // 0 for external
  name: string;
  work_email: string | null;
  designation: string | null;
  role: 'lead' | 'panelist' | 'observer';
  is_external: boolean;
}

const form = reactive({
  title: '',
  interview_type: 'panel' as string,
  scheduled_at: '',
  duration_minutes: 60,
  location: '',
  agenda: '',
  createTeams: true,
  panelists: [] as PanelistEntry[],
});

// ── Candidate selector ───────────────────────────────────────────────────────
const selectedAppId = ref<number>(props.initialApplicationId);

const selectedCandidate = computed(() => {
  const app = props.applications?.find(a => a.id === selectedAppId.value);
  if (!app?.candidate) return null;
  const c = app.candidate;
  return {
    id:    c.id,
    name:  c.full_name ?? `${c.first_name} ${c.last_name}`.trim(),
    email: c.email,
  };
});

const searchQuery    = ref('');
const searchResults  = ref<EmployeeSearchResult[]>([]);
const saving         = ref(false);
const error          = ref('');
const savedInterview = ref<Interview | null>(props.existingInterview ?? null);
const actionLoading  = ref<'teams' | 'candidate' | 'panelists' | null>(null);
const actionError    = ref('');
const actionSuccess  = ref('');

const teamsPermissionError = computed(() => {
  if (!actionError.value) return null;
  const msg = actionError.value.toLowerCase();
  if (msg.includes('access denied') || msg.includes('access is denied') || msg.includes('calendars.readwrite') || msg.includes('admin consent')) {
    return actionError.value;
  }
  return null;
});
const copied         = ref<number | null>(null);

function copyLink(url: string, panelistId: number) {
  navigator.clipboard.writeText(url).then(() => {
    copied.value = panelistId;
    setTimeout(() => { if (copied.value === panelistId) copied.value = null; }, 2000);
  });
}

let searchTimer: ReturnType<typeof setTimeout> | null = null;

function fmtDate(iso?: string | null): string {
  if (!iso) return '—';
  return new Date(iso).toLocaleString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

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
      const existing = new Set(form.panelists.map(p => p.work_email?.toLowerCase()));
      searchResults.value = (res.data ?? []).filter(e => !existing.has(e.work_email?.toLowerCase()));
    } catch { searchResults.value = []; }
  }, 300);
}

function isValidEmail(v: string) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
}

function addExternalEmail() {
  const email = searchQuery.value.trim().toLowerCase();
  if (!isValidEmail(email)) return;
  if (form.panelists.some(p => p.work_email?.toLowerCase() === email)) {
    searchQuery.value = ''; return;
  }
  form.panelists.push({
    id: 0,
    employee_id: 0,
    name: email,
    work_email: email,
    designation: null,
    role: 'panelist',
    is_external: true,
  });
  searchQuery.value = '';
  searchResults.value = [];
}

function handleSearchKey(e: KeyboardEvent) {
  if (e.key === 'Enter' || e.key === 'Tab' || e.key === ',') {
    e.preventDefault();
    if (isValidEmail(searchQuery.value.trim())) addExternalEmail();
  }
}

function addPanelist(emp: EmployeeSearchResult) {
  form.panelists.push({ ...emp, employee_id: emp.id, role: 'panelist', is_external: false });
  searchQuery.value = ''; searchResults.value = [];
}

function removePanelist(idx: number) { form.panelists.splice(idx, 1); }

async function submit() {
  error.value = '';
  if (!form.title.trim())     { error.value = 'Title is required.'; return; }
  if (!form.scheduled_at)     { error.value = 'Date & time is required.'; return; }
  if (!form.panelists.length) { error.value = 'At least one panelist is required.'; return; }

  saving.value = true;
  try {
    const empPanelists  = form.panelists.filter(p => !p.is_external);
    const extPanelists  = form.panelists.filter(p => p.is_external);
    const payload = {
      title:            form.title.trim(),
      interview_type:   form.interview_type,
      scheduled_at:     form.scheduled_at,
      duration_minutes: form.duration_minutes,
      location:         form.location || null,
      agenda:           form.agenda || null,
      panelist_ids:     empPanelists.map(p => p.id),
      panelist_roles:   empPanelists.map(p => p.role),
      external_panelists: extPanelists.map(p => ({
        email: p.work_email!,
        name:  p.name !== p.work_email ? p.name : null,
        role:  p.role,
      })),
    };
    const interview = await api<Interview>('POST', `/api/v1/recruitment/applications/${selectedAppId.value}/interviews`, {
      org: currentOrgSlug.value,
      body: payload,
    });
    savedInterview.value = interview;
    emit('saved', interview);
    if (form.createTeams) await generateTeams();
  } catch (e: unknown) {
    error.value = (e as Error)?.message ?? 'Failed to schedule interview.';
  } finally {
    saving.value = false;
  }
}

async function generateTeams() {
  if (!savedInterview.value) return;
  actionLoading.value = 'teams'; actionError.value = ''; actionSuccess.value = '';
  try {
    const res = await api<{ interview: Interview; teams_join_url: string }>('POST',
      `/api/v1/recruitment/interviews/${savedInterview.value.id}/create-teams-event`,
      { org: currentOrgSlug.value, body: {} }
    );
    if (res.interview) savedInterview.value = res.interview;
    if (res.teams_join_url && savedInterview.value) (savedInterview.value as Interview).teams_join_url = res.teams_join_url;
    actionSuccess.value = 'Teams meeting created.';
  } catch (e: unknown) {
    actionError.value = (e as Error)?.message ?? 'Teams meeting creation failed.';
  } finally { actionLoading.value = null; }
}

async function sendCandidateInvite() {
  if (!savedInterview.value) return;
  actionLoading.value = 'candidate'; actionError.value = ''; actionSuccess.value = '';
  try {
    const res = await api<{ interview: Interview; message: string }>('POST',
      `/api/v1/recruitment/interviews/${savedInterview.value.id}/send-candidate-invite`,
      { org: currentOrgSlug.value, body: {} }
    );
    if (res.interview) savedInterview.value = res.interview;
    actionSuccess.value = res.message ?? 'Candidate invite sent.';
  } catch (e: unknown) {
    actionError.value = 'Candidate invite: ' + ((e as Error)?.message ?? 'Failed.');
  } finally { actionLoading.value = null; }
}

async function sendPanelistInvites() {
  if (!savedInterview.value) return;
  actionLoading.value = 'panelists'; actionError.value = ''; actionSuccess.value = '';
  try {
    const res = await api<{ interview: Interview; message: string; errors: string[] }>('POST',
      `/api/v1/recruitment/interviews/${savedInterview.value.id}/send-invites`,
      { org: currentOrgSlug.value, body: {} }
    );
    if (res.interview) savedInterview.value = res.interview;
    actionSuccess.value = res.message ?? 'Panelist invites sent.';
    if (res.errors?.length) actionError.value = res.errors.join('; ');
  } catch (e: unknown) {
    actionError.value = 'Panelist invites: ' + ((e as Error)?.message ?? 'Failed.');
  } finally { actionLoading.value = null; }
}
</script>

<style scoped>
/* ── Candidate / Vacancy context strip ──────────────────────────────────── */
.ism-context-strip {
  display: flex;
  align-items: stretch;
  background: var(--color-brand-base);
  border-radius: 10px;
  overflow: hidden;
  padding: 14px 20px;
  gap: 20px;
}
.ism-context-cell { display: flex; flex-direction: column; gap: 2px; flex: 1; min-width: 0; }
.ism-context-cell--select { justify-content: center; }
.ism-context-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: rgba(255,255,255,0.65); }
.ism-context-value { font-size: 14px; font-weight: 700; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ism-context-sub   { font-size: 12px; color: rgba(255,255,255,0.7); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ism-context-divider { width: 1px; background: rgba(255,255,255,0.2); flex-shrink: 0; margin: 0 4px; }

/* Candidate select inside the blue strip */
.ism-candidate-select {
  background: rgba(255,255,255,0.15);
  border: 1px solid rgba(255,255,255,0.35);
  border-radius: 6px;
  color: #fff;
  font-size: 14px;
  font-weight: 700;
  padding: 5px 28px 5px 10px;
  outline: none;
  cursor: pointer;
  width: 100%;
  appearance: none;
  -webkit-appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23ffffff' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 10px center;
}
.ism-candidate-select:focus { border-color: rgba(255,255,255,0.7); background-color: rgba(255,255,255,0.2); }
.ism-candidate-select option { background: #004C97; color: #fff; font-weight: 500; }

/* ── Copy scoring link button ────────────────────────────────────────────── */
.ism-copy-btn {
  background: none;
  border: 1px solid var(--color-border);
  border-radius: 6px;
  padding: 2px 8px;
  font-size: 11px;
  font-weight: 600;
  color: var(--color-text-muted);
  cursor: pointer;
}
.ism-copy-btn:hover { background: var(--color-bg); color: var(--color-text); }

/* ── Overlay + panel ─────────────────────────────────────────────────────── */
.ism-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.5);
  z-index: 9999;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
}
.ism-panel {
  background: var(--color-surface);
  border-radius: 12px;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
  width: 100%;
  max-width: 680px;
  max-height: 92vh;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

/* ── Header ──────────────────────────────────────────────────────────────── */
.ism-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 18px 24px;
  border-bottom: 1px solid var(--color-border);
  flex-shrink: 0;
}
.ism-title { font-size: 17px; font-weight: 700; color: var(--color-text); margin: 0; }
.ism-close {
  background: none; border: none; cursor: pointer;
  font-size: 22px; line-height: 1; color: var(--color-text-muted);
  padding: 0 4px; border-radius: 4px;
}
.ism-close:hover { color: var(--color-text); background: var(--color-bg); }

/* ── Body / footer ───────────────────────────────────────────────────────── */
.ism-body {
  flex: 1;
  overflow-y: auto;
  padding: 20px 24px;
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.ism-footer {
  padding: 14px 24px;
  border-top: 1px solid var(--color-border);
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  flex-shrink: 0;
}

/* ── Form fields ─────────────────────────────────────────────────────────── */
.ism-field-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.ism-field { display: flex; flex-direction: column; gap: 5px; }
.ism-label { font-size: 12px; font-weight: 600; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.04em; }
.ism-input {
  padding: 9px 12px;
  border: 1px solid var(--color-border);
  border-radius: 8px;
  font-size: 13px;
  color: var(--color-text);
  background: var(--color-bg);
  width: 100%;
  box-sizing: border-box;
  outline: none;
  font-family: inherit;
  resize: vertical;
}
.ism-input:focus { border-color: var(--color-brand-base); box-shadow: 0 0 0 2px rgba(0,76,151,0.12); }
.ism-role-select { width: 120px; padding: 6px 10px; flex-shrink: 0; }

.ism-teams-check {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  background: rgba(0,76,151,0.04);
  border: 1px solid rgba(0,76,151,0.15);
  border-radius: 8px;
  font-size: 13px;
  font-weight: 600;
  color: var(--color-brand-base);
  cursor: pointer;
  user-select: none;
}
.ism-teams-check input { width: 15px; height: 15px; accent-color: var(--color-brand-base); flex-shrink: 0; }

/* ── Employee search dropdown ────────────────────────────────────────────── */
.ism-search-results {
  margin-top: 4px;
  border: 1px solid var(--color-border);
  border-radius: 8px;
  max-height: 160px;
  overflow-y: auto;
}
.ism-search-item {
  width: 100%;
  text-align: left;
  padding: 9px 14px;
  background: none;
  border: none;
  border-bottom: 1px solid var(--color-border);
  cursor: pointer;
  display: flex;
  flex-direction: column;
  gap: 1px;
}
.ism-search-item:last-child { border-bottom: none; }
.ism-search-item:hover { background: var(--color-bg); }
.ism-search-name { font-size: 13px; font-weight: 500; color: var(--color-text); }
.ism-search-sub { font-size: 11px; color: var(--color-text-muted); }

/* No results / add-by-email */
.ism-no-results {
  margin-top: 6px;
  padding: 10px 14px;
  border: 1px dashed var(--color-border);
  border-radius: 8px;
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.ism-no-results-msg { font-size: 12px; color: var(--color-text-muted); }
.ism-add-email-btn {
  background: none;
  border: 1px solid var(--color-brand-base);
  color: var(--color-brand-base);
  border-radius: 6px;
  padding: 4px 12px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
}
.ism-add-email-btn:hover { background: rgba(0,76,151,0.06); }

/* External tag badge */
.ism-external-tag {
  display: inline-block;
  background: #fef3c7;
  color: #92400e;
  border: 1px solid #fde68a;
  border-radius: 4px;
  font-size: 10px;
  font-weight: 700;
  padding: 0 5px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  vertical-align: middle;
  margin-left: 5px;
}

.ism-panelist-list {
  margin-top: 6px;
  border: 1px solid var(--color-border);
  border-radius: 8px;
  overflow: hidden;
}
.ism-panelist-entry {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 12px;
  border-bottom: 1px solid var(--color-border);
}
.ism-panelist-entry:last-child { border-bottom: none; }
.ism-panelist-entry-info { flex: 1; min-width: 0; }
.ism-panelist-role-sub { display: block; font-size: 11px; color: var(--color-text-muted); }
.ism-remove { background: none; border: none; cursor: pointer; font-size: 18px; color: #f87171; line-height: 1; padding: 0 4px; }
.ism-remove:hover { color: #dc2626; }
.ism-hint { font-size: 12px; color: var(--color-text-muted); margin-top: 4px; }

/* ── Post-save: Teams banner ─────────────────────────────────────────────── */
.ism-teams-banner {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 14px 16px;
  background: #eef2ff;
  border: 1px solid #c7d2fe;
  border-radius: 10px;
}
.ism-teams-icon { font-size: 22px; flex-shrink: 0; margin-top: 1px; }
.ism-teams-info { flex: 1; min-width: 0; }
.ism-teams-label { font-size: 13px; font-weight: 700; color: #3730a3; margin: 0 0 4px; }
.ism-teams-url { font-size: 12px; color: #4f46e5; text-decoration: none; word-break: break-all; display: block; }
.ism-teams-url:hover { text-decoration: underline; }

/* ── Interview summary ───────────────────────────────────────────────────── */
.ism-summary {
  padding: 12px 14px;
  background: var(--color-bg);
  border-radius: 8px;
  border: 1px solid var(--color-border);
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.ism-summary-row { display: flex; gap: 20px; flex-wrap: wrap; }
.ism-summary-item { font-size: 13px; color: var(--color-text); }

/* ── Action cards ────────────────────────────────────────────────────────── */
.ism-action-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.ism-action-card {
  border: 1px solid var(--color-border);
  border-radius: 10px;
  padding: 14px 16px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.ism-action-card-title { font-size: 13px; font-weight: 700; color: var(--color-text); margin: 0; }
.ism-action-card-desc { font-size: 12px; color: var(--color-text-muted); margin: 0; flex: 1; }
.ism-sent-ok   { font-size: 12px; color: #059669; font-weight: 600; }
.ism-sent-count { font-size: 12px; color: var(--color-text-muted); }

/* ── Panelist status table ───────────────────────────────────────────────── */
.ism-panelist-table {
  border: 1px solid var(--color-border);
  border-radius: 10px;
  overflow: hidden;
}
.ism-panelist-header {
  padding: 8px 14px;
  background: var(--color-bg);
  border-bottom: 1px solid var(--color-border);
  font-size: 12px;
  font-weight: 600;
  color: var(--color-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
.ism-panelist-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 9px 14px;
  border-bottom: 1px solid var(--color-border);
  gap: 10px;
}
.ism-panelist-row:last-child { border-bottom: none; }
.ism-panelist-info { display: flex; flex-direction: column; gap: 1px; }
.ism-panelist-name { font-size: 13px; font-weight: 500; color: var(--color-text); }
.ism-panelist-role { font-size: 11px; color: var(--color-text-muted); text-transform: capitalize; }
.ism-panelist-status { display: flex; align-items: center; gap: 6px; font-size: 12px; flex-wrap: wrap; justify-content: flex-end; }
.ism-status-ok      { color: #059669; font-weight: 600; }
.ism-status-pending { color: #9ca3af; }
.ism-score-badge    { background: #f0fdf4; color: #059669; border: 1px solid #bbf7d0; border-radius: 12px; padding: 1px 8px; font-size: 11px; font-weight: 600; }
.ism-form-link      { color: var(--color-brand-base); border: 1px solid rgba(0,76,151,0.25); border-radius: 6px; padding: 2px 8px; font-size: 11px; font-weight: 600; text-decoration: none; }
.ism-form-link:hover { background: rgba(0,76,151,0.06); }

/* ── Messages ────────────────────────────────────────────────────────────── */
.ism-error   { font-size: 13px; color: #dc2626; background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; padding: 8px 12px; }
.ism-success { font-size: 13px; color: #059669; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 8px 12px; }

.ism-error-block { background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 14px 16px; font-size: 13px; }
.ism-error-title { font-weight: 700; color: #991b1b; margin-bottom: 6px; font-size: 13px; }
.ism-error-body  { color: #b91c1c; line-height: 1.5; }
.ism-error-steps { margin-top: 12px; padding-top: 12px; border-top: 1px solid #fecaca; }
.ism-error-steps-title { font-weight: 600; color: #7f1d1d; font-size: 12px; margin-bottom: 6px; }
.ism-error-steps ol { padding-left: 18px; color: #991b1b; font-size: 12px; line-height: 1.8; }
.ism-error-steps strong { color: #7f1d1d; }
.ism-error-steps em { font-style: italic; color: #b91c1c; }

/* ── Buttons ─────────────────────────────────────────────────────────────── */
.ism-btn {
  padding: 9px 18px;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 600;
  border: none;
  cursor: pointer;
  transition: opacity 0.15s;
  white-space: nowrap;
}
.ism-btn:disabled { opacity: 0.45; cursor: not-allowed; }
.ism-btn-primary { background: var(--color-brand-base); color: #fff; }
.ism-btn-primary:hover:not(:disabled) { opacity: 0.88; }
.ism-btn-indigo  { background: #4f46e5; color: #fff; }
.ism-btn-indigo:hover:not(:disabled) { opacity: 0.88; }
.ism-btn-blue    { background: #2563eb; color: #fff; }
.ism-btn-blue:hover:not(:disabled) { opacity: 0.88; }
.ism-btn-green   { background: #059669; color: #fff; }
.ism-btn-green:hover:not(:disabled) { opacity: 0.88; }
.ism-btn-outline { background: transparent; color: var(--color-text); border: 1px solid var(--color-border); }
.ism-btn-outline:hover:not(:disabled) { background: var(--color-bg); }

@media (max-width: 560px) {
  .ism-field-grid  { grid-template-columns: 1fr; }
  .ism-action-grid { grid-template-columns: 1fr; }
}
</style>
