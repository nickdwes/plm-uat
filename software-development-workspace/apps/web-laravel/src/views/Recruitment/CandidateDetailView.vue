<script setup lang="ts">
import { ref, onMounted, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { api } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import type { Candidate, CandidateApplication } from "../../lib/types";
import StageHistoryTimeline from "../../components/Recruitment/StageHistoryTimeline.vue";

const route  = useRoute();
const router = useRouter();

const candidate    = ref<Candidate | null>(null);
const applications = ref<CandidateApplication[]>([]);
const loading      = ref(false);
const error        = ref("");

// per-card active tab: 'stage' | 'docs' | 'cover'
const activeDocTabs = ref<Record<number, string>>({});
function docTab(id: number) { return activeDocTabs.value[id] ?? "stage"; }
function setDocTab(id: number, tab: string) { activeDocTabs.value[id] = tab; }

function org() { return currentOrgSlug.value; }
const candidateId = () => route.params.id as string;

async function load() {
  if (!org()) return;
  loading.value = true;
  error.value   = "";
  try {
    const cRes = await api<{ data: Candidate }>("GET", `/api/v1/recruitment/candidates/${candidateId()}`, { org: org() });
    candidate.value = cRes.data;
    applications.value = (cRes.data.applications ?? []) as CandidateApplication[];
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to load candidate.";
  }
  loading.value = false;
}

onMounted(load);
watch(currentOrgSlug, load);
</script>

<template>
  <div class="view">
    <div class="view-header">
      <div>
        <button class="back-btn" @click="router.back()">← Back</button>
        <h2>{{ candidate?.full_name ?? "Candidate" }}</h2>
      </div>
      <a v-if="candidate?.resume_url" :href="candidate.resume_url" target="_blank" class="btn btn-sm">↓ Resume</a>
    </div>

    <p v-if="loading" class="status">Loading…</p>
    <p v-if="error" class="error">{{ error }}</p>

    <template v-if="candidate && !loading">
      <div class="detail-grid">
        <!-- Profile card -->
        <div class="detail-card">
          <h4>Profile</h4>
          <dl class="detail-list">
            <dt>Email</dt><dd>{{ candidate.email }}</dd>
            <dt>Phone</dt><dd>{{ candidate.phone ?? "—" }}</dd>
            <dt>Source</dt><dd>{{ candidate.source ?? "—" }}</dd>
          </dl>
          <div v-if="(candidate.keywords ?? []).length" class="keywords-row">
            <span v-for="k in candidate.keywords" :key="k" class="keyword-chip">{{ k }}</span>
          </div>
          <div v-if="candidate.social_links && Object.keys(candidate.social_links).length" class="social-links">
            <a v-for="(url, platform) in candidate.social_links" :key="platform" :href="url as string" target="_blank" class="link">{{ platform }}</a>
          </div>
        </div>

        <!-- Notes -->
        <div v-if="candidate.notes" class="detail-card">
          <h4>Notes</h4>
          <p class="notes-text">{{ candidate.notes }}</p>
        </div>
      </div>

      <!-- Application history -->
      <div class="section-header">Applications ({{ applications.length }})</div>
      <div v-if="applications.length === 0" class="empty-msg">No applications on record.</div>

      <div v-for="app in applications" :key="app.id" class="app-card">
        <div class="app-card-header">
          <span class="app-vacancy">{{ app.vacancy?.name ?? "Vacancy" }}</span>
          <span class="badge" :class="app.status === 'hired' ? 'badge-green' : app.status === 'rejected' ? 'badge-red' : 'badge-yellow'">{{ app.status }}</span>
        </div>
        <div class="app-card-meta">
          Applied {{ app.applied_at ? new Date(app.applied_at).toLocaleDateString() : "—" }}
          <span v-if="app.screening_score != null"> · Score: {{ app.screening_score }}%</span>
        </div>

        <!-- Tabs -->
        <div class="doc-tabs">
          <button
            class="doc-tab"
            :class="{ active: docTab(app.id) === 'stage' }"
            @click="setDocTab(app.id, 'stage')"
          >Stage Info</button>
          <button
            class="doc-tab"
            :class="{ active: docTab(app.id) === 'cover' }"
            @click="setDocTab(app.id, 'cover')"
          >
            Cover Letter
            <span v-if="app.form_data?.cover_letter" class="tab-dot" />
          </button>
          <button
            class="doc-tab"
            :class="{ active: docTab(app.id) === 'docs' }"
            @click="setDocTab(app.id, 'docs')"
          >
            Documents
            <span v-if="candidate.resume_url || app.form_data?.attachment_url" class="tab-dot" />
          </button>
        </div>

        <!-- Stage Info tab -->
        <div v-if="docTab(app.id) === 'stage'">
          <div v-if="app.current_stage" class="current-stage-row">
            Current stage: <strong>{{ app.current_stage.name }}</strong>
          </div>
          <StageHistoryTimeline v-if="(app.stage_history ?? []).length" :history="app.stage_history!" />
          <p v-else class="empty-sub">No stage history recorded.</p>
        </div>

        <!-- Cover Letter tab -->
        <div v-else-if="docTab(app.id) === 'cover'">
          <div v-if="app.form_data?.cover_letter" class="cover-letter-box">{{ app.form_data.cover_letter }}</div>
          <p v-else class="empty-sub">No cover letter submitted.</p>
        </div>

        <!-- Documents tab -->
        <div v-else-if="docTab(app.id) === 'docs'" class="docs-tab-content">
          <!-- CV / Resume -->
          <div class="doc-section-label">CV / Resume</div>
          <div v-if="candidate.resume_url" class="doc-card">
            <span class="doc-icon">📄</span>
            <span class="doc-name">Resume</span>
            <a :href="candidate.resume_url" target="_blank" class="doc-download-btn">↓ Download</a>
          </div>
          <p v-else class="empty-sub">No CV on file.</p>

          <!-- Supporting Document -->
          <div class="doc-section-label" style="margin-top: var(--space-3)">Supporting Document</div>
          <div v-if="app.form_data?.attachment_url" class="doc-card">
            <span class="doc-icon">📎</span>
            <span class="doc-name">{{ app.form_data?.attachment_name as string || "Attachment" }}</span>
            <a :href="(app.form_data.attachment_url as string)" target="_blank" class="doc-download-btn">↓ Download</a>
          </div>
          <p v-else class="empty-sub">No supporting document submitted.</p>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.back-btn { background: none; border: none; cursor: pointer; color: var(--color-text-muted); font-size: var(--font-size-sm); margin-bottom: 4px; display: block; }
.detail-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: var(--space-5); margin-bottom: var(--space-6); }
.detail-card { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: var(--space-5); }
.detail-card h4 { font-weight: 600; margin-bottom: var(--space-3); }
.detail-list { display: grid; grid-template-columns: auto 1fr; gap: 6px 16px; font-size: var(--font-size-sm); margin-bottom: var(--space-3); }
.detail-list dt { color: var(--color-text-muted); }
.detail-list dd { font-weight: 500; }
.keywords-row { display: flex; flex-wrap: wrap; gap: var(--space-1); margin-bottom: var(--space-2); }
.keyword-chip { padding: 2px 8px; border-radius: 10px; background: var(--color-bg); border: 1px solid var(--color-border); font-size: 11px; }
.social-links { display: flex; flex-wrap: wrap; gap: var(--space-2); }
.social-links .link { font-size: var(--font-size-sm); color: var(--color-brand-base, #004C97); text-transform: capitalize; }
.notes-text { font-size: var(--font-size-sm); color: var(--color-text-muted); white-space: pre-wrap; }
.section-header { font-weight: 600; font-size: var(--font-size-sm); text-transform: uppercase; letter-spacing: 0.06em; color: var(--color-text-muted); margin-bottom: var(--space-3); }
.empty-msg { color: var(--color-text-muted); font-size: var(--font-size-sm); padding: var(--space-4) 0; }
.app-card { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: var(--space-4); margin-bottom: var(--space-3); }
.app-card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; }
.app-vacancy { font-weight: 600; font-size: var(--font-size-sm); }
.app-card-meta { font-size: 12px; color: var(--color-text-muted); margin-bottom: var(--space-3); }
.current-stage-row { font-size: var(--font-size-sm); margin-bottom: var(--space-2); }

/* Tabs */
.doc-tabs { display: flex; gap: 2px; border-bottom: 1px solid var(--color-border); margin-bottom: var(--space-3); }
.doc-tab { background: none; border: none; border-bottom: 2px solid transparent; padding: 6px 14px; font-size: var(--font-size-sm); cursor: pointer; color: var(--color-text-muted); display: flex; align-items: center; gap: 5px; position: relative; }
.doc-tab.active { color: var(--color-brand-base, #004C97); border-bottom-color: var(--color-brand-base, #004C97); font-weight: 600; }
.tab-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--color-brand-base, #004C97); display: inline-block; }

/* Cover letter */
.cover-letter-box { background: var(--color-bg); border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: var(--space-3); font-size: var(--font-size-sm); white-space: pre-wrap; max-height: 220px; overflow-y: auto; line-height: 1.6; color: var(--color-text); }

/* Documents */
.docs-tab-content { padding: var(--space-1) 0; }
.doc-section-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: var(--color-text-muted); margin-bottom: var(--space-2); }
.doc-card { display: flex; align-items: center; gap: var(--space-2); background: var(--color-bg); border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: var(--space-2) var(--space-3); }
.doc-icon { font-size: 18px; }
.doc-name { flex: 1; font-size: var(--font-size-sm); font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.doc-download-btn { padding: 4px 10px; border-radius: var(--radius-md); background: var(--color-brand-base, #004C97); color: #fff; font-size: 12px; text-decoration: none; white-space: nowrap; }
.doc-download-btn:hover { opacity: 0.85; }
.empty-sub { font-size: var(--font-size-sm); color: var(--color-text-muted); padding: var(--space-2) 0; }
</style>
