<script setup lang="ts">
import { ref, computed, onMounted, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { api } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import type { Vacancy, Application, WorkflowStage } from "../../lib/types";
import CandidatePipelineBoard from "../../components/Recruitment/CandidatePipelineBoard.vue";
import ApplicationModal from "../../components/Recruitment/ApplicationModal.vue";
import InterviewScheduleModal from "../../components/Recruitment/InterviewScheduleModal.vue";
import type { Interview } from "../../lib/types";

const route  = useRoute();
const router = useRouter();

const vacancy      = ref<Vacancy | null>(null);
const applications = ref<Application[]>([]);
const stages       = ref<WorkflowStage[]>([]);
const loading      = ref(false);
const boardLoading = ref(false);
const saving       = ref(false);
const statusSaving = ref(false);
const error        = ref("");
const saveError    = ref("");
const activeTab    = ref<"pipeline" | "info" | "interviews">("pipeline");
const selectedApp  = ref<Application | null>(null);
const copied       = ref(false);
const showEdit     = ref(false);

// Edit form state
const editForm = ref({
  name:           "",
  job_title:      "",
  location:       "",
  open_positions: 1,
  closing_date:   "",
  description:    "",
});

function openEdit() {
  if (!vacancy.value) return;
  editForm.value = {
    name:           vacancy.value.name,
    job_title:      vacancy.value.job_title,
    location:       vacancy.value.location ?? "",
    open_positions: vacancy.value.open_positions,
    closing_date:   vacancy.value.closing_date ?? "",
    description:    vacancy.value.description ?? "",
  };
  saveError.value = "";
  showEdit.value  = true;
}

async function saveEdit() {
  if (!vacancy.value) return;
  saving.value    = true;
  saveError.value = "";
  try {
    const res = await api<{ data: Vacancy }>("PUT", `/api/v1/recruitment/vacancies/${vacancyId()}`, {
      org:  org(),
      body: {
        // pass through fields the backend requires
        workflow_id:    vacancy.value.workflow?.id,
        name:           editForm.value.name,
        job_title:      editForm.value.job_title,
        location:       editForm.value.location || null,
        open_positions: editForm.value.open_positions,
        closing_date:   editForm.value.closing_date || null,
        description:    editForm.value.description || null,
        status:         vacancy.value.status,
      },
    });
    vacancy.value  = res.data;
    stages.value   = res.data.workflow?.stages ?? [];
    showEdit.value = false;
  } catch (e) {
    saveError.value = e instanceof Error ? e.message : "Failed to save.";
  } finally {
    saving.value = false;
  }
}

const STATUS_TRANSITIONS: Record<string, { label: string; value: string; cls: string }[]> = {
  draft:     [{ label: "Publish (Open)", value: "open",      cls: "status-open"      }, { label: "Cancel",          value: "cancelled", cls: "status-cancelled" }],
  open:      [{ label: "Close",          value: "closed",    cls: "status-closed"    }, { label: "Cancel",          value: "cancelled", cls: "status-cancelled" }],
  closed:    [{ label: "Re-open",        value: "open",      cls: "status-open"      }],
  cancelled: [{ label: "Re-open as Draft",value: "draft",    cls: "status-draft"     }],
};
const showStatusMenu = ref(false);

async function changeStatus(newStatus: string) {
  if (!vacancy.value) return;
  showStatusMenu.value = false;
  statusSaving.value   = true;
  try {
    const res = await api<{ data: Vacancy }>("PUT", `/api/v1/recruitment/vacancies/${vacancyId()}`, {
      org:  org(),
      body: {
        workflow_id:    vacancy.value.workflow?.id,
        name:           vacancy.value.name,
        job_title:      vacancy.value.job_title,
        location:       vacancy.value.location,
        open_positions: vacancy.value.open_positions,
        closing_date:   vacancy.value.closing_date,
        description:    vacancy.value.description,
        status:         newStatus,
      },
    });
    vacancy.value = res.data;
    stages.value  = res.data.workflow?.stages ?? [];
    // re-shorten if needed
    if (newStatus === "open") shortenUrl(portalJobUrl.value);
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to change status.";
  } finally {
    statusSaving.value = false;
  }
}

const PORTAL_BASE   = import.meta.env.VITE_PORTAL_URL ?? "https://vacancy.bitwyse.co.ke";
const BITLY_TOKEN   = import.meta.env.VITE_BITLY_TOKEN ?? "";

const portalJobUrl  = computed(() => vacancy.value ? `${PORTAL_BASE}/jobs/${vacancy.value.uuid}` : "");
const shortUrl      = ref("");
const shorteningUrl = ref(false);

const displayUrl    = computed(() => shortUrl.value || portalJobUrl.value);

const linkedinShare = computed(() => `https://www.linkedin.com/sharing/share-offsite/?url=${encodeURIComponent(displayUrl.value)}`);
const emailShare    = computed(() => {
  if (!vacancy.value) return "#";
  const s = encodeURIComponent(`Job Opening: ${vacancy.value.job_title}`);
  const b = encodeURIComponent(`Hi,\n\nWe have an exciting opportunity for ${vacancy.value.job_title}.\n\nApply here: ${displayUrl.value}\n\nDeadline: ${vacancy.value.closing_date ?? "Open until filled"}`);
  return `mailto:?subject=${s}&body=${b}`;
});

async function shortenUrl(url: string) {
  if (!BITLY_TOKEN || !url) return;
  shorteningUrl.value = true;
  try {
    const res = await fetch("https://api-ssl.bitly.com/v4/shorten", {
      method: "POST",
      headers: {
        "Authorization": `Bearer ${BITLY_TOKEN}`,
        "Content-Type": "application/json",
      },
      body: JSON.stringify({ long_url: url }),
    });
    if (res.ok) {
      const data = await res.json();
      shortUrl.value = data.link ?? "";
    }
  } catch { /* fall back to full URL */ }
  finally { shorteningUrl.value = false; }
}

async function copyPortalUrl() {
  try {
    await navigator.clipboard.writeText(displayUrl.value);
    copied.value = true;
    setTimeout(() => { copied.value = false; }, 2000);
  } catch { /* ignore */ }
}

function org() { return currentOrgSlug.value; }
const vacancyId = () => route.params.id as string;

async function loadVacancy() {
  if (!org()) return;
  loading.value = true;
  error.value   = "";
  try {
    const res = await api<{ data: Vacancy }>("GET", `/api/v1/recruitment/vacancies/${vacancyId()}`, { org: org() });
    vacancy.value = res.data;
    stages.value  = res.data.workflow?.stages ?? [];
    await loadApplications();
    // shorten the portal URL once vacancy UUID is known
    shortenUrl(portalJobUrl.value);
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to load vacancy.";
  } finally {
    loading.value = false;
  }
}

async function loadApplications() {
  if (!org()) return;
  boardLoading.value = true;
  try {
    const res = await api<{ data: Application[] }>("GET", `/api/v1/recruitment/vacancies/${vacancyId()}/applications`, { org: org() });
    applications.value = res.data;
  } finally {
    boardLoading.value = false;
  }
}

async function onStageChanged(appId: number, stageId: number) {
  boardLoading.value = true;
  try {
    const res = await api<{ data: Application }>("PATCH", `/api/v1/recruitment/applications/${appId}/stage`, {
      org: org(),
      body: { stage_id: stageId },
    });
    const idx = applications.value.findIndex((a) => a.id === appId);
    if (idx !== -1) applications.value[idx] = res.data;
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to move stage.";
  } finally {
    boardLoading.value = false;
  }
}

function onAppUpdated(updated: Application) {
  const idx = applications.value.findIndex((a) => a.id === updated.id);
  if (idx !== -1) applications.value[idx] = updated;
  selectedApp.value = updated;
}

// ── Interviews ───────────────────────────────────────────────────────────────
const interviews        = ref<Interview[]>([]);
const interviewsLoading = ref(false);
const showScheduleModal = ref(false);
const scheduleAppId     = ref<number | null>(null);

async function loadInterviews() {
  if (!vacancy.value || !applications.value.length) { interviews.value = []; return; }
  interviewsLoading.value = true;
  try {
    const all = await Promise.all(
      applications.value.map(app =>
        api<{ data: Interview[] }>("GET", `/api/v1/recruitment/applications/${app.id}/interviews`, { org: org() })
          .then(r => r.data)
          .catch(() => [] as Interview[])
      )
    );
    interviews.value = all.flat().sort((a, b) => new Date(b.scheduled_at).getTime() - new Date(a.scheduled_at).getTime());
  } finally {
    interviewsLoading.value = false;
  }
}

function openScheduleModal(appId?: number) {
  scheduleAppId.value = appId ?? (applications.value[0]?.id ?? null);
  showScheduleModal.value = true;
}

function onInterviewSaved(interview: Interview) {
  interviews.value.unshift(interview);
  showScheduleModal.value = false;
}

async function sendInvites(interviewId: number) {
  try {
    await api("POST", `/api/v1/recruitment/interviews/${interviewId}/send-invites`, { org: org(), body: {} });
    alert("Invites sent successfully.");
    await loadInterviews();
  } catch (e: any) {
    alert("Failed to send invites: " + (e?.message ?? e));
  }
}

function interviewTypeLabel(t: string): string {
  const map: Record<string, string> = { phone: "Phone", video: "Video", onsite: "Onsite", panel: "Panel", technical: "Technical", hr: "HR" };
  return map[t] ?? t;
}

function interviewStatusClass(s: string): string {
  const map: Record<string, string> = {
    scheduled: "bg-blue-100 text-blue-700",
    confirmed: "bg-green-100 text-green-700",
    completed: "bg-indigo-100 text-indigo-700",
    cancelled: "bg-red-100 text-red-700",
    rescheduled: "bg-yellow-100 text-yellow-700",
  };
  return map[s] ?? "bg-gray-100 text-gray-700";
}

function fmtDateTime(d: string) {
  return new Date(d).toLocaleString(undefined, { dateStyle: "medium", timeStyle: "short" });
}

const showSchedule = computed(() => showScheduleModal.value && scheduleAppId.value !== null);
const safeScheduleAppId = computed(() => scheduleAppId.value ?? 0);

watch(activeTab, (tab) => { if (tab === "interviews" && !interviews.value.length) loadInterviews(); });

onMounted(loadVacancy);
watch(currentOrgSlug, loadVacancy);
</script>

<template>
  <div class="view">
    <div class="view-header">
      <div>
        <button class="back-btn" @click="router.back()">← Back</button>
        <h2>{{ vacancy?.name ?? "Vacancy" }}</h2>
      </div>
      <div v-if="vacancy" class="header-actions">
        <!-- Status badge + dropdown -->
        <div class="status-menu-wrap">
          <button
            class="status-badge-btn"
            :class="`status-${vacancy.status}`"
            :disabled="statusSaving"
            @click="showStatusMenu = !showStatusMenu"
          >
            {{ statusSaving ? 'Saving…' : vacancy.status }}
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="chevron-ico"><polyline points="6 9 12 15 18 9"/></svg>
          </button>
          <div v-if="showStatusMenu" class="status-dropdown">
            <button
              v-for="t in STATUS_TRANSITIONS[vacancy.status] ?? []"
              :key="t.value"
              class="status-option"
              :class="t.cls"
              @click="changeStatus(t.value)"
            >{{ t.label }}</button>
            <p v-if="!(STATUS_TRANSITIONS[vacancy.status]?.length)" class="status-no-transition">No transitions available</p>
          </div>
        </div>
        <!-- Edit button -->
        <button class="btn btn-sm" @click="openEdit">✏ Edit</button>
      </div>
    </div>

    <p v-if="loading" class="status">Loading…</p>
    <p v-if="error" class="error">{{ error }}</p>

    <template v-if="vacancy && !loading">
      <div class="tab-bar">
        <button :class="['tab-btn', { active: activeTab === 'pipeline' }]" @click="activeTab = 'pipeline'">Pipeline</button>
        <button :class="['tab-btn', { active: activeTab === 'interviews' }]" @click="activeTab = 'interviews'">Interviews</button>
        <button :class="['tab-btn', { active: activeTab === 'info' }]" @click="activeTab = 'info'">Details</button>
      </div>

      <div v-if="activeTab === 'pipeline'" class="tab-panel">
        <CandidatePipelineBoard
          :stages="stages"
          :applications="applications"
          :loading="boardLoading"
          @stage-changed="onStageChanged"
          @open-app="(app) => (selectedApp = app)"
        />
      </div>

      <!-- ── Interviews tab ─────────────────────────────────────────────── -->
      <div v-if="activeTab === 'interviews'" class="tab-panel">
        <div class="interviews-header">
          <h3 class="section-title">Scheduled Interviews</h3>
          <button class="btn btn-primary" @click="openScheduleModal()">+ Schedule Interview</button>
        </div>

        <p v-if="interviewsLoading" class="status">Loading interviews…</p>

        <div v-else-if="!interviews.length" class="empty-interviews">
          <p>No interviews scheduled yet.</p>
          <button class="btn btn-primary mt-3" @click="openScheduleModal()">Schedule First Interview</button>
        </div>

        <div v-else class="interviews-list">
          <div v-for="iv in interviews" :key="iv.id" class="interview-card">
            <div class="interview-card-header">
              <div>
                <h4 class="interview-title">{{ iv.title }}</h4>
                <p class="interview-meta">{{ fmtDateTime(iv.scheduled_at) }} · {{ iv.duration_minutes }} min · {{ interviewTypeLabel(iv.interview_type) }}<span v-if="iv.location"> · {{ iv.location }}</span></p>
              </div>
              <span class="interview-status-badge" :class="interviewStatusClass(iv.status)">{{ iv.status }}</span>
            </div>

            <!-- Panelists -->
            <div class="interview-panelists">
              <span class="panelists-label">Panel:</span>
              <span v-for="p in iv.panelists" :key="p.id" class="panelist-chip">
                {{ p.name }} <em>({{ p.role }})</em>
                <span v-if="p.invite_sent" class="invite-sent-dot" title="Invite sent">✓</span>
              </span>
              <span v-if="!iv.panelists.length" class="text-gray-400 text-xs">No panelists</span>
            </div>

            <!-- Score summary -->
            <div v-if="iv.overall_score !== null" class="overall-score">
              Overall Score: <strong>{{ iv.overall_score }} / 10</strong>
            </div>

            <!-- Actions -->
            <div class="interview-actions">
              <button class="btn btn-sm" @click="sendInvites(iv.id)">📧 Send Invites</button>
              <router-link :to="`/recruitment/interviews/${iv.id}/scorecard`" class="btn btn-sm btn-primary">📋 Scorecard</router-link>
            </div>
          </div>
        </div>
      </div>

      <div v-if="activeTab === 'info'" class="tab-panel">
        <div class="detail-grid">
          <div class="detail-card">
            <h4>Vacancy Info</h4>
            <dl class="detail-list">
              <dt>Job Title</dt><dd>{{ vacancy.job_title }}</dd>
              <dt>Location</dt><dd>{{ vacancy.location ?? "—" }}</dd>
              <dt>Open Positions</dt><dd>{{ vacancy.open_positions }}</dd>
              <dt>Closing Date</dt><dd>{{ vacancy.closing_date ? new Date(vacancy.closing_date).toLocaleDateString() : "—" }}</dd>
            </dl>
          </div>

          <!-- Public job portal sharing card -->
          <div class="detail-card share-card">
            <h4>Share & Promote</h4>
            <p class="share-desc">Post this vacancy on external channels. Applicants go directly into the recruitment pipeline.</p>
            <div class="portal-url-box">
              <span class="portal-url-label">Public Application Link</span>
              <code class="portal-url">{{ shorteningUrl ? 'Shortening…' : displayUrl }}</code>
              <button class="copy-btn" @click="copyPortalUrl">{{ copied ? '✓ Copied' : 'Copy' }}</button>
            </div>
            <div class="share-actions">
              <a :href="linkedinShare" target="_blank" rel="noopener" class="share-btn linkedin-btn">
                <svg viewBox="0 0 24 24" fill="currentColor" class="share-svg"><path d="M20.447 20.452H17.17v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.905V9h3.144v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a1.836 1.836 0 0 1-1.838-1.833 1.836 1.836 0 1 1 1.838 1.833zm1.573 13.019H3.766V9h3.144v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>
                Post on LinkedIn
              </a>
              <a :href="emailShare" class="share-btn email-btn">
                <svg viewBox="0 0 24 24" fill="none" class="share-svg" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                Share via Email
              </a>
              <a :href="portalJobUrl" target="_blank" class="share-btn preview-btn">
                <svg viewBox="0 0 24 24" fill="none" class="share-svg" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                Preview Portal
              </a>
            </div>
          </div>

          <div v-if="vacancy.description" class="detail-card">
            <h4>Description</h4>
            <p class="description-text">{{ vacancy.description }}</p>
          </div>
        </div>
      </div>
    </template>

    <!-- Interview schedule modal -->
    <InterviewScheduleModal
      v-if="showSchedule"
      :application-id="safeScheduleAppId"
      :vacancy-id="Number(vacancyId())"
      @close="showScheduleModal = false"
      @saved="onInterviewSaved"
    />

    <ApplicationModal
      v-if="selectedApp"
      :application="selectedApp"
      :stages="stages"
      @close="selectedApp = null"
      @updated="onAppUpdated"
    />

    <!-- Edit modal -->
    <div v-if="showEdit" class="modal-backdrop" @click.self="showEdit = false">
      <div class="modal-box">
        <div class="modal-header">
          <h3>Edit Vacancy</h3>
          <button class="modal-close" @click="showEdit = false">✕</button>
        </div>
        <div class="modal-body">
          <p v-if="saveError" class="error">{{ saveError }}</p>
          <div class="form-grid">
            <label class="field">
              <span>Vacancy Name <em>*</em></span>
              <input v-model="editForm.name" type="text" class="input" />
            </label>
            <label class="field">
              <span>Job Title <em>*</em></span>
              <input v-model="editForm.job_title" type="text" class="input" />
            </label>
            <label class="field">
              <span>Location</span>
              <input v-model="editForm.location" type="text" class="input" />
            </label>
            <label class="field">
              <span>Open Positions</span>
              <input v-model.number="editForm.open_positions" type="number" min="1" class="input" />
            </label>
            <label class="field">
              <span>Closing Date</span>
              <input v-model="editForm.closing_date" type="date" class="input" />
            </label>
          </div>
          <label class="field" style="margin-top: var(--space-3)">
            <span>Description</span>
            <textarea v-model="editForm.description" rows="5" class="input textarea" />
          </label>
        </div>
        <div class="modal-footer">
          <button class="btn" @click="showEdit = false">Cancel</button>
          <button class="btn btn-primary" :disabled="saving" @click="saveEdit">{{ saving ? 'Saving…' : 'Save Changes' }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* Interviews tab */
.interviews-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: var(--space-4); }
.section-title { font-size: var(--font-size-lg); font-weight: 600; margin: 0; }
.empty-interviews { text-align: center; color: var(--color-text-muted); padding: var(--space-8) 0; }
.interviews-list { display: flex; flex-direction: column; gap: var(--space-4); }
.interview-card { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: var(--space-4) var(--space-5); }
.interview-card-header { display: flex; align-items: flex-start; justify-content: space-between; gap: var(--space-3); margin-bottom: var(--space-2); }
.interview-title { font-weight: 600; font-size: var(--font-size-base); margin: 0 0 4px; }
.interview-meta { font-size: 13px; color: var(--color-text-muted); margin: 0; }
.interview-status-badge { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; padding: 3px 10px; border-radius: 20px; white-space: nowrap; }
.interview-panelists { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; font-size: 13px; margin-bottom: var(--space-2); }
.panelists-label { font-weight: 600; color: var(--color-text-muted); }
.panelist-chip { background: #f0f4ff; color: #3730a3; padding: 2px 10px; border-radius: 20px; font-size: 12px; }
.panelist-chip em { font-style: normal; opacity: .7; }
.invite-sent-dot { color: #16a34a; font-weight: 700; margin-left: 3px; }
.overall-score { font-size: 13px; color: var(--color-text-muted); margin-bottom: var(--space-2); }
.interview-actions { display: flex; gap: var(--space-2); flex-wrap: wrap; margin-top: var(--space-3); }
.mt-3 { margin-top: var(--space-3); }

.back-btn { background: none; border: none; cursor: pointer; color: var(--color-text-muted); font-size: var(--font-size-sm); margin-bottom: 4px; display: block; }

/* Header actions */
.header-actions { display: flex; align-items: center; gap: var(--space-2); }
.btn-sm { padding: 5px 12px; font-size: var(--font-size-sm); }

/* Status badge/button */
.status-menu-wrap { position: relative; }
.status-badge-btn { display: inline-flex; align-items: center; gap: 5px; padding: 5px 10px; border-radius: 20px; border: none; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; cursor: pointer; }
.status-badge-btn:disabled { opacity: .7; cursor: default; }
.chevron-ico { width: 12px; height: 12px; }
.status-draft     { background: #f5f5f5; color: #555; }
.status-open      { background: #e6f4ea; color: #1a7f37; }
.status-closed    { background: #fdecea; color: #c0392b; }
.status-cancelled { background: #fff3cd; color: #856404; }

.status-dropdown { position: absolute; top: calc(100% + 6px); right: 0; background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-md); box-shadow: 0 4px 16px rgba(0,0,0,.12); min-width: 170px; z-index: 50; overflow: hidden; }
.status-option { display: block; width: 100%; padding: 9px 14px; text-align: left; background: none; border: none; cursor: pointer; font-size: var(--font-size-sm); font-weight: 500; }
.status-option:hover { background: var(--color-bg); }
.status-no-transition { padding: 9px 14px; font-size: var(--font-size-sm); color: var(--color-text-muted); margin: 0; }

.tab-bar { display: flex; gap: var(--space-2); border-bottom: 1px solid var(--color-border); margin-bottom: var(--space-5); }
.tab-btn { padding: 8px 16px; background: none; border: none; border-bottom: 2px solid transparent; cursor: pointer; font-size: var(--font-size-sm); color: var(--color-text-muted); }
.tab-btn.active { border-bottom-color: var(--color-brand-base, #004C97); color: var(--color-brand-base, #004C97); font-weight: 600; }
.tab-panel { padding: var(--space-2) 0; }
.detail-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: var(--space-5); }
.detail-card { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: var(--space-5); }
.detail-card h4 { font-weight: var(--font-weight-semibold); margin-bottom: var(--space-3); }
.detail-list { display: grid; grid-template-columns: auto 1fr; gap: 6px 16px; font-size: var(--font-size-sm); }
.detail-list dt { color: var(--color-text-muted); }
.detail-list dd { font-weight: 500; }
.description-text { font-size: var(--font-size-sm); color: var(--color-text-muted); white-space: pre-wrap; }

/* Share card */
.share-card { display: flex; flex-direction: column; gap: var(--space-3); }
.share-desc { font-size: 13px; color: var(--color-text-muted); line-height: 1.5; }
.portal-url-box { display: flex; align-items: center; gap: 8px; background: var(--color-bg); border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: 10px 12px; flex-wrap: wrap; }
.portal-url-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .4px; color: var(--color-text-muted); flex-basis: 100%; }
.portal-url { font-size: 12px; font-family: monospace; color: #004C97; flex: 1; word-break: break-all; }
.copy-btn { padding: 4px 12px; border-radius: var(--radius-sm); border: 1px solid #004C97; background: transparent; color: #004C97; font-size: 12px; font-weight: 600; cursor: pointer; white-space: nowrap; }
.copy-btn:hover { background: #004C9715; }
.share-actions { display: flex; flex-direction: column; gap: 8px; }
.share-btn { display: flex; align-items: center; gap: 8px; padding: 9px 14px; border-radius: var(--radius-md); font-size: 13px; font-weight: 600; text-decoration: none; border: none; cursor: pointer; transition: opacity .15s; }
.share-svg { width: 15px; height: 15px; flex-shrink: 0; }
.linkedin-btn { background: #0a66c2; color: #fff; }
.email-btn { background: var(--color-bg); color: var(--color-text); border: 1px solid var(--color-border); }
.preview-btn { background: #004C97; color: #fff; }
.share-btn:hover { opacity: .85; }

/* Edit modal */
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 100; display: flex; align-items: center; justify-content: center; padding: var(--space-4); }
.modal-box { background: var(--color-surface); border-radius: var(--radius-lg); width: 100%; max-width: 560px; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 8px 40px rgba(0,0,0,.2); }
.modal-header { display: flex; align-items: center; justify-content: space-between; padding: var(--space-4) var(--space-5); border-bottom: 1px solid var(--color-border); }
.modal-header h3 { font-weight: 600; margin: 0; }
.modal-close { background: none; border: none; font-size: 18px; cursor: pointer; color: var(--color-text-muted); line-height: 1; }
.modal-body { padding: var(--space-5); overflow-y: auto; }
.modal-footer { padding: var(--space-4) var(--space-5); border-top: 1px solid var(--color-border); display: flex; justify-content: flex-end; gap: var(--space-2); }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-3); }
.field { display: flex; flex-direction: column; gap: 5px; font-size: var(--font-size-sm); }
.field span { font-weight: 500; color: var(--color-text-muted); }
.field em { color: #c0392b; font-style: normal; }
.input { padding: 8px 10px; border: 1px solid var(--color-border); border-radius: var(--radius-md); font-size: var(--font-size-sm); background: var(--color-bg); color: var(--color-text); outline: none; width: 100%; box-sizing: border-box; }
.input:focus { border-color: #004C97; }
.textarea { resize: vertical; min-height: 90px; }
</style>
