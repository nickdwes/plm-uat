<script setup lang="ts">
import { ref, computed } from "vue";
import type { Application, WorkflowStage } from "../../lib/types";
import { api } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import StageHistoryTimeline from "./StageHistoryTimeline.vue";

const props = defineProps<{
  application: Application;
  stages: WorkflowStage[];
}>();

const emit = defineEmits<{ close: []; updated: [app: Application] }>();

const moving  = ref(false);
const notes   = ref("");
const error   = ref("");
const activeTab = ref<"stage" | "documents" | "history">("stage");

const coverLetter   = computed(() => (props.application.form_data?.cover_letter as string | undefined) ?? "");
const resumeUrl     = computed(() => props.application.candidate?.resume_url ?? null);
const attachmentUrl = computed(() => (props.application.form_data?.attachment_url as string | null) ?? null);
const hasDocuments  = computed(() => !!(resumeUrl.value || coverLetter.value || attachmentUrl.value));

function resumeFileName(url: string) {
  try { return decodeURIComponent(url.split("/").pop() ?? "Resume"); } catch { return "Resume"; }
}

async function moveStage(stageId: number) {
  if (moving.value) return;
  moving.value = true;
  error.value  = "";
  try {
    const updated = await api<{ data: Application }>("PATCH", `/api/v1/recruitment/applications/${props.application.id}/stage`, {
      org: currentOrgSlug.value,
      body: { stage_id: stageId, notes: notes.value || undefined },
    });
    emit("updated", updated.data);
    notes.value = "";
  } catch (e: unknown) {
    error.value = (e as Error).message ?? "Failed to move stage.";
  } finally {
    moving.value = false;
  }
}
</script>

<template>
  <div class="modal-overlay" @click.self="emit('close')">
    <div class="modal-panel">
      <div class="modal-header">
        <div>
          <h3>{{ application.candidate?.full_name }}</h3>
          <span class="badge" :class="application.status">{{ application.status }}</span>
        </div>
        <button class="btn-icon" @click="emit('close')">✕</button>
      </div>

      <!-- Tab bar -->
      <div class="modal-tab-bar">
        <button :class="['modal-tab', { active: activeTab === 'stage' }]" @click="activeTab = 'stage'">Stage</button>
        <button :class="['modal-tab', { active: activeTab === 'documents' }]" @click="activeTab = 'documents'">
          Application
          <span v-if="hasDocuments" class="tab-dot"></span>
        </button>
        <button :class="['modal-tab', { active: activeTab === 'history' }]" @click="activeTab = 'history'">History</button>
      </div>

      <div class="modal-body">

        <!-- ── Stage tab ── -->
        <template v-if="activeTab === 'stage'">
          <div class="panel-section">
            <div class="section-title">Move Stage</div>
            <div class="stage-buttons">
              <button
                v-for="stage in stages"
                :key="stage.id"
                :class="['stage-btn', { active: stage.id === application.current_stage?.id }]"
                :disabled="moving || stage.id === application.current_stage?.id"
                @click="moveStage(stage.id)"
              >
                {{ stage.name }}
              </button>
            </div>
            <textarea v-model="notes" class="form-input" placeholder="Optional notes for this move…" rows="2" />
            <p v-if="error" class="error-msg">{{ error }}</p>
          </div>

          <div v-if="application.candidate" class="panel-section">
            <div class="section-title">Candidate</div>
            <div class="cand-info">
              <div>{{ application.candidate.email }}</div>
              <div v-if="application.candidate.phone">{{ application.candidate.phone }}</div>
              <div v-if="application.candidate.source">Source: {{ application.candidate.source }}</div>
            </div>
            <div v-if="(application.candidate.keywords ?? []).length" class="keywords">
              <span v-for="k in application.candidate.keywords" :key="k" class="keyword-chip">{{ k }}</span>
            </div>
          </div>
        </template>

        <!-- ── Application / Documents tab ── -->
        <template v-else-if="activeTab === 'documents'">

          <!-- Resume / CV -->
          <div class="panel-section">
            <div class="section-title">CV / Resume</div>
            <div v-if="resumeUrl" class="doc-card">
              <div class="doc-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="doc-svg"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
              </div>
              <div class="doc-details">
                <span class="doc-name">{{ resumeFileName(resumeUrl) }}</span>
                <span class="doc-label">Submitted CV</span>
              </div>
              <a :href="resumeUrl" target="_blank" rel="noopener" class="doc-btn">↓ Download</a>
            </div>
            <p v-else class="empty-state">No resume submitted.</p>
          </div>

          <!-- Cover Letter -->
          <div class="panel-section">
            <div class="section-title">Cover Letter</div>
            <div v-if="coverLetter" class="cover-letter-box">{{ coverLetter }}</div>
            <p v-else class="empty-state">No cover letter submitted.</p>
          </div>

          <!-- Supporting Document -->
          <div class="panel-section">
            <div class="section-title">Supporting Document</div>
            <div v-if="attachmentUrl" class="doc-card">
              <div class="doc-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="doc-svg"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
              </div>
              <div class="doc-details">
                <span class="doc-name">Supporting Document</span>
                <span class="doc-label">Portfolio / Certificate / References</span>
              </div>
              <a :href="attachmentUrl" target="_blank" rel="noopener" class="doc-btn">↓ Download</a>
            </div>
            <p v-else class="empty-state">No supporting document submitted.</p>
          </div>

        </template>

        <!-- ── History tab ── -->
        <template v-else-if="activeTab === 'history'">
          <div class="panel-section">
            <div class="section-title">Stage History</div>
            <StageHistoryTimeline :history="application.stage_history ?? []" />
          </div>
        </template>

      </div>
    </div>
  </div>
</template>

<style scoped>
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 200; display: flex; justify-content: flex-end; }
.modal-panel { width: 480px; max-width: 100%; background: var(--color-surface); display: flex; flex-direction: column; box-shadow: -4px 0 20px rgba(0,0,0,0.15); overflow-y: auto; }
.modal-header { display: flex; justify-content: space-between; align-items: flex-start; padding: var(--space-5) var(--space-6); border-bottom: 1px solid var(--color-border); gap: var(--space-3); }
.modal-header h3 { font-size: var(--font-size-lg); font-weight: var(--font-weight-semibold); margin: 0; }

/* Tab bar */
.modal-tab-bar { display: flex; border-bottom: 1px solid var(--color-border); padding: 0 var(--space-6); }
.modal-tab { position: relative; padding: 10px 14px; background: none; border: none; border-bottom: 2px solid transparent; cursor: pointer; font-size: var(--font-size-sm); color: var(--color-text-muted); margin-bottom: -1px; display: flex; align-items: center; gap: 6px; }
.modal-tab.active { border-bottom-color: var(--color-brand-base, #004C97); color: var(--color-brand-base, #004C97); font-weight: 600; }
.tab-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--color-brand-base, #004C97); display: inline-block; }

.modal-body { padding: var(--space-5) var(--space-6); display: flex; flex-direction: column; gap: var(--space-6); }
.panel-section { display: flex; flex-direction: column; gap: var(--space-3); }
.section-title { font-size: var(--font-size-xs); font-weight: var(--font-weight-semibold); text-transform: uppercase; letter-spacing: 0.08em; color: var(--color-text-muted); }

/* Stage tab */
.stage-buttons { display: flex; flex-wrap: wrap; gap: var(--space-2); }
.stage-btn { padding: 5px 12px; border-radius: 20px; border: 1px solid var(--color-border); background: var(--color-bg); font-size: var(--font-size-sm); cursor: pointer; transition: background 0.15s; }
.stage-btn:hover:not(:disabled) { background: var(--color-brand-light, rgba(0,76,151,0.1)); border-color: var(--color-brand-base, #004C97); }
.stage-btn.active { background: var(--color-brand-base, #004C97); color: #fff; border-color: var(--color-brand-base, #004C97); }
.stage-btn:disabled { opacity: 0.55; cursor: not-allowed; }
.form-input { padding: 8px 12px; border: 1px solid var(--color-border); border-radius: var(--radius-md); font-size: var(--font-size-base); width: 100%; resize: vertical; }
.cand-info { display: flex; flex-direction: column; gap: 4px; font-size: var(--font-size-sm); color: var(--color-text-muted); }

/* Document cards */
.doc-card { display: flex; align-items: center; gap: var(--space-3); padding: var(--space-3) var(--space-4); border: 1px solid var(--color-border); border-radius: var(--radius-md); background: var(--color-bg); }
.doc-icon { flex-shrink: 0; width: 36px; height: 36px; background: rgba(0,76,151,0.08); border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center; color: var(--color-brand-base, #004C97); }
.doc-svg { width: 18px; height: 18px; }
.doc-details { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.doc-name { font-size: var(--font-size-sm); font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.doc-label { font-size: 11px; color: var(--color-text-muted); }
.doc-btn { flex-shrink: 0; padding: 5px 12px; border-radius: var(--radius-sm); background: var(--color-brand-base, #004C97); color: #fff; font-size: var(--font-size-xs); font-weight: 600; text-decoration: none; white-space: nowrap; }
.doc-btn:hover { opacity: 0.85; }

/* Cover letter */
.cover-letter-box { padding: var(--space-4); background: var(--color-bg); border: 1px solid var(--color-border); border-radius: var(--radius-md); font-size: var(--font-size-sm); line-height: 1.7; color: var(--color-text); white-space: pre-wrap; max-height: 320px; overflow-y: auto; }

.empty-state { font-size: var(--font-size-sm); color: var(--color-text-muted); font-style: italic; }

.badge { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; text-transform: capitalize; background: rgba(0,76,151,0.1); color: var(--color-brand-base, #004C97); }
.badge.hired    { background: rgba(16,185,129,0.1); color: #059669; }
.badge.rejected { background: rgba(239,68,68,0.1); color: #dc2626; }
.badge.shortlisted { background: rgba(245,158,11,0.1); color: #d97706; }
.btn-icon { background: none; border: none; cursor: pointer; font-size: 18px; color: var(--color-text-muted); }
.error-msg { color: #dc2626; font-size: var(--font-size-sm); }
.keywords { display: flex; flex-wrap: wrap; gap: var(--space-1); }
.keyword-chip { padding: 2px 8px; border-radius: 10px; background: var(--color-bg); border: 1px solid var(--color-border); font-size: 11px; color: var(--color-text-muted); }
.link { color: var(--color-brand-base, #004C97); font-size: var(--font-size-sm); }
</style>
