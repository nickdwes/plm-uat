<script setup lang="ts">
import { ref, onMounted, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { api } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import type { Candidate, CandidateApplication, WorkflowStage } from "../../lib/types";
import StageHistoryTimeline from "../../components/Recruitment/StageHistoryTimeline.vue";

const route  = useRoute();
const router = useRouter();

const candidate    = ref<Candidate | null>(null);
const applications = ref<CandidateApplication[]>([]);
const loading      = ref(false);
const error        = ref("");

// Tab per application card
const activeDocTabs = ref<Record<number, string>>({});
function docTab(id: number) { return activeDocTabs.value[id] ?? "stage"; }
function setDocTab(id: number, tab: string) { activeDocTabs.value[id] = tab; }

// Edit mode
const editing   = ref(false);
const saveErr   = ref("");
const savingEdit = ref(false);
const editForm  = ref({ first_name: "", middle_name: "", last_name: "", phone: "", source: "", notes: "", linkedin: "", twitter: "", facebook: "", keywords_raw: "" });

function startEdit() {
  if (!candidate.value) return;
  const c = candidate.value;
  editForm.value = {
    first_name:   c.first_name,
    middle_name:  c.middle_name ?? "",
    last_name:    c.last_name,
    phone:        c.phone ?? "",
    source:       c.source ?? "",
    notes:        c.notes ?? "",
    linkedin:     c.social_links?.linkedin ?? "",
    twitter:      c.social_links?.twitter  ?? "",
    facebook:     c.social_links?.facebook ?? "",
    keywords_raw: (c.keywords ?? []).join(", "),
  };
  saveErr.value = "";
  editing.value = true;
}

async function saveEdit() {
  if (!candidate.value) return;
  savingEdit.value = true; saveErr.value = "";
  try {
    const social_links: Record<string, string> = {};
    if (editForm.value.linkedin) social_links.linkedin = editForm.value.linkedin;
    if (editForm.value.twitter)  social_links.twitter  = editForm.value.twitter;
    if (editForm.value.facebook) social_links.facebook = editForm.value.facebook;
    const keywords = editForm.value.keywords_raw.split(",").map((k: string) => k.trim()).filter(Boolean);
    const body: Record<string, unknown> = {
      first_name:  editForm.value.first_name,
      last_name:   editForm.value.last_name,
      email:       candidate.value.email,
    };
    if (editForm.value.middle_name)       body.middle_name  = editForm.value.middle_name;
    if (editForm.value.phone)             body.phone        = editForm.value.phone;
    if (editForm.value.source)            body.source       = editForm.value.source;
    if (editForm.value.notes)             body.notes        = editForm.value.notes;
    body.keywords     = keywords;
    body.social_links = social_links;
    await api("PUT", `/api/v1/recruitment/candidates/${candidate.value.id}`, { org: currentOrgSlug.value!, body });
    editing.value = false;
    load();
  } catch (e) { saveErr.value = e instanceof Error ? e.message : "Save failed."; }
  finally { savingEdit.value = false; }
}

// Resume upload
const resumeFile    = ref<File | null>(null);
const uploadingResume = ref(false);
const resumeErr     = ref("");
function onResumeChange(e: Event) { resumeFile.value = (e.target as HTMLInputElement).files?.[0] ?? null; }
async function uploadResume() {
  if (!resumeFile.value || !candidate.value) return;
  uploadingResume.value = true; resumeErr.value = "";
  try {
    const form = new FormData(); form.append("resume", resumeFile.value);
    await api("POST", `/api/v1/recruitment/candidates/${candidate.value.id}/resume`, { org: currentOrgSlug.value!, body: form });
    resumeFile.value = null; load();
  } catch (e) { resumeErr.value = e instanceof Error ? e.message : "Upload failed."; }
  finally { uploadingResume.value = false; }
}

// Move stage
const movingApp   = ref<number | null>(null);
const stageOptions = ref<Record<number, WorkflowStage[]>>({});
const moveErr     = ref("");

async function loadStages(app: CandidateApplication) {
  if (!app.vacancy?.id || stageOptions.value[app.id]) return;
  try {
    const res = await api<{ data: { workflow: { stages: WorkflowStage[] } } }>(
      "GET", `/api/v1/recruitment/vacancies/${app.vacancy.id}`, { org: currentOrgSlug.value! }
    );
    stageOptions.value[app.id] = res.data?.workflow?.stages ?? [];
  } catch { stageOptions.value[app.id] = []; }
}

async function moveStage(app: CandidateApplication, stageId: number) {
  if (!stageId) return;
  movingApp.value = app.id; moveErr.value = "";
  try {
    await api("PATCH", `/api/v1/recruitment/applications/${app.id}/stage`, {
      org: currentOrgSlug.value!, body: { stage_id: stageId },
    });
    load();
  } catch (e) { moveErr.value = e instanceof Error ? e.message : "Stage move failed."; }
  finally { movingApp.value = null; }
}

// Load
function org() { return currentOrgSlug.value; }
const candidateId = () => route.params.id as string;

async function load() {
  if (!org()) return;
  loading.value = true; error.value = "";
  try {
    const res = await api<{ data: Candidate }>("GET", `/api/v1/recruitment/candidates/${candidateId()}`, { org: org()! });
    candidate.value    = res.data;
    applications.value = (res.data.applications ?? []) as CandidateApplication[];
  } catch (e) { error.value = e instanceof Error ? e.message : "Failed to load."; }
  finally { loading.value = false; }
}

onMounted(load);
watch(currentOrgSlug, load);

// Helpers
function initials(n: string) { return n.split(" ").map(x => x[0]).slice(0,2).join("").toUpperCase(); }
function avatarColor(id: number) { const c=["#004C97","#0ea5e9","#6366f1","#8b5cf6","#059669","#d97706"]; return c[id%c.length]; }
function stageColor(t?: string) {
  const m: Record<string,string> = { hired:"#059669", rejected:"#dc2626", offer:"#d97706", interview:"#6366f1", screening:"#0ea5e9", applied:"#6b7280", shortlisted:"#f59e0b", pre_onboarding:"#8b5cf6" };
  return m[t ?? ""] ?? "#9ca3af";
}
function statusStyle(s?: string): { bg: string; color: string } {
  const m: Record<string,{bg:string;color:string}> = { active:{bg:"#eff6ff",color:"#3b82f6"}, shortlisted:{bg:"#fffbeb",color:"#d97706"}, rejected:{bg:"#fef2f2",color:"#dc2626"}, hired:{bg:"#f0fdf4",color:"#059669"}, withdrawn:{bg:"#f1f5f9",color:"#64748b"} };
  return m[s ?? ""] ?? { bg:"#f1f5f9", color:"#64748b" };
}
function fmtDate(d?: string | null) { if (!d) return "—"; return new Date(d).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" }); }

const SOURCES = ["LinkedIn","Referral","Job Portal","Website","Internal","Agency","Walk-in","Other"];
</script>

<template>
  <div class="cd-wrap">
    <p v-if="loading" class="cd-loading"><span class="spinner"></span> Loading...</p>
    <p v-if="error" class="cd-error">{{ error }}</p>

    <template v-if="candidate && !loading">

      <!-- ── Hero header ──────────────────────────────────────────────── -->
      <div class="cd-hero">
        <div class="cd-hero-left">
          <button class="back-btn" @click="router.back()">&#8592; Candidates</button>
          <div class="cd-hero-main">
            <div class="cd-avatar" :style="{ background: avatarColor(candidate.id) }">
              {{ initials(candidate.full_name) }}
            </div>
            <div class="cd-hero-info">
              <h2 class="cd-name">{{ candidate.full_name }}</h2>
              <div class="cd-meta-row">
                <a :href="`mailto:${candidate.email}`" class="cd-email">{{ candidate.email }}</a>
                <span v-if="candidate.phone" class="cd-sep">&#183;</span>
                <span v-if="candidate.phone" class="cd-phone">{{ candidate.phone }}</span>
              </div>
              <div class="cd-meta-row" style="margin-top:4px;">
                <span v-if="candidate.source" class="source-badge">{{ candidate.source }}</span>
                <span v-if="candidate.is_archived" class="archived-badge">Archived</span>
                <template v-for="(url, platform) in candidate.social_links" :key="platform">
                  <a :href="url as string" target="_blank" class="social-link">{{ platform }}</a>
                </template>
              </div>
              <div v-if="(candidate.keywords ?? []).length" class="cd-keywords">
                <span v-for="k in candidate.keywords" :key="k" class="keyword-chip">{{ k }}</span>
              </div>
            </div>
          </div>
        </div>
        <div class="cd-hero-actions">
          <a v-if="candidate.resume_url" :href="candidate.resume_url" target="_blank" class="btn-outline">&#8595; Resume</a>
          <button class="btn-primary" @click="startEdit">&#9998; Edit</button>
        </div>
      </div>

      <!-- ── Notes strip ─────────────────────────────────────────────── -->
      <div v-if="candidate.notes" class="cd-notes-strip">
        <span class="cd-notes-label">Notes</span>
        <p class="cd-notes-text">{{ candidate.notes }}</p>
      </div>

      <!-- ── Resume upload strip ─────────────────────────────────────── -->
      <div v-if="!candidate.resume_url" class="cd-resume-strip">
        <span class="cd-notes-label">Resume</span>
        <div class="resume-upload-row">
          <input type="file" accept=".pdf,.doc,.docx" class="resume-file-input" @change="onResumeChange" />
          <button v-if="resumeFile" class="btn-outline btn-sm" :disabled="uploadingResume" @click="uploadResume">
            {{ uploadingResume ? "Uploading..." : "Upload Resume" }}
          </button>
        </div>
        <span v-if="resumeErr" class="upload-err">{{ resumeErr }}</span>
      </div>

      <!-- ── Applications ────────────────────────────────────────────── -->
      <div class="cd-section-header">
        <h3 class="cd-section-title">Applications ({{ applications.length }})</h3>
      </div>
      <p v-if="applications.length === 0" class="cd-empty">No applications on record.</p>
      <p v-if="moveErr" class="cd-error">{{ moveErr }}</p>

      <div v-for="app in applications" :key="app.id" class="cd-app-card">
        <!-- App card header -->
        <div class="cd-app-header">
          <div class="cd-app-header-left">
            <span class="cd-app-vacancy">{{ app.vacancy?.name ?? "Vacancy" }}</span>
            <div class="cd-app-meta">
              Applied {{ fmtDate(app.applied_at) }}
              <template v-if="app.screening_score != null">
                &#183; Score: <strong :style="{ color: app.screening_score >= 70 ? '#059669' : app.screening_score >= 40 ? '#d97706' : '#dc2626' }">{{ app.screening_score }}%</strong>
              </template>
            </div>
          </div>
          <div class="cd-app-header-right">
            <!-- Status badge -->
            <span class="app-status-chip" :style="{ background: statusStyle(app.status).bg, color: statusStyle(app.status).color }">
              {{ app.status }}
            </span>
            <!-- Move stage -->
            <div class="stage-move-wrap">
              <select
                class="stage-select"
                :disabled="movingApp === app.id"
                @focus="loadStages(app)"
                @change="(e) => moveStage(app, parseInt((e.target as HTMLSelectElement).value))"
              >
                <option value="">Move stage...</option>
                <option v-for="s in (stageOptions[app.id] ?? [])" :key="s.id" :value="s.id">
                  {{ s.name }}
                </option>
              </select>
              <span v-if="movingApp === app.id" class="spinner-sm"></span>
            </div>
            <!-- Add as Employee -->
            <router-link
              v-if="app.status === 'hired'"
              :to="{ name: 'preboarding-create', query: { candidate_id: candidate.id } }"
              class="btn-hire"
            >
              + Add as Employee
            </router-link>
          </div>
        </div>

        <!-- Tabs -->
        <div class="cd-app-tabs">
          <button :class="['cd-tab', { active: docTab(app.id) === 'stage' }]"    @click="setDocTab(app.id, 'stage')">Stage History</button>
          <button :class="['cd-tab', { active: docTab(app.id) === 'cover' }]"    @click="setDocTab(app.id, 'cover')">
            Cover Letter <span v-if="app.form_data?.cover_letter" class="tab-dot"></span>
          </button>
          <button :class="['cd-tab', { active: docTab(app.id) === 'docs' }]"     @click="setDocTab(app.id, 'docs')">
            Documents <span v-if="candidate.resume_url || app.form_data?.attachment_url" class="tab-dot"></span>
          </button>
        </div>

        <!-- Stage History -->
        <div v-if="docTab(app.id) === 'stage'" class="cd-tab-body">
          <div v-if="app.current_stage" class="current-stage-row">
            Current stage: <strong
              :style="{ color: stageColor(app.current_stage.stage_type) }">{{ app.current_stage.name }}</strong>
          </div>
          <StageHistoryTimeline v-if="(app.stage_history ?? []).length" :history="app.stage_history!" />
          <p v-else class="cd-empty-sub">No stage history recorded.</p>
        </div>

        <!-- Cover Letter -->
        <div v-else-if="docTab(app.id) === 'cover'" class="cd-tab-body">
          <div v-if="app.form_data?.cover_letter" class="cover-letter-box">{{ app.form_data.cover_letter }}</div>
          <p v-else class="cd-empty-sub">No cover letter submitted.</p>
        </div>

        <!-- Documents -->
        <div v-else-if="docTab(app.id) === 'docs'" class="cd-tab-body">
          <div class="doc-section-label">CV / Resume</div>
          <div v-if="candidate.resume_url" class="doc-row">
            <span class="doc-icon">&#128196;</span>
            <span class="doc-name">Resume</span>
            <a :href="candidate.resume_url" target="_blank" class="doc-dl-btn">&#8595; Download</a>
          </div>
          <p v-else class="cd-empty-sub">No CV on file.</p>
          <div class="doc-section-label" style="margin-top:12px">Supporting Documents</div>
          <div v-if="app.form_data?.attachment_url" class="doc-row">
            <span class="doc-icon">&#128206;</span>
            <span class="doc-name">{{ app.form_data?.attachment_name as string || "Attachment" }}</span>
            <a :href="(app.form_data.attachment_url as string)" target="_blank" class="doc-dl-btn">&#8595; Download</a>
          </div>
          <p v-else class="cd-empty-sub">No supporting document submitted.</p>
        </div>
      </div>

    </template>
  </div>

  <!-- Edit Candidate Modal -->
  <Teleport to="body">
    <div v-if="editing" class="modal-backdrop" @click.self="editing = false">
      <div class="modal-box">
        <div class="modal-header">
          <h3>Edit Candidate</h3>
          <button class="modal-close" @click="editing = false">&#10005;</button>
        </div>
        <div class="modal-body">
          <div v-if="saveErr" class="add-err">{{ saveErr }}</div>
          <div class="form-row-3">
            <div class="form-field"><label>First Name</label><input v-model="editForm.first_name" type="text" class="form-input" /></div>
            <div class="form-field"><label>Middle Name</label><input v-model="editForm.middle_name" type="text" class="form-input" /></div>
            <div class="form-field"><label>Last Name</label><input v-model="editForm.last_name" type="text" class="form-input" /></div>
          </div>
          <div class="form-row-2">
            <div class="form-field"><label>Phone</label><input v-model="editForm.phone" type="tel" class="form-input" /></div>
            <div class="form-field">
              <label>Source</label>
              <select v-model="editForm.source" class="form-input">
                <option value="">&#8212; Select &#8212;</option>
                <option v-for="s in SOURCES" :key="s" :value="s">{{ s }}</option>
              </select>
            </div>
          </div>
          <div class="form-section-label">Social Profiles</div>
          <div class="form-row-3">
            <div class="form-field"><label>LinkedIn</label><input v-model="editForm.linkedin" type="url" class="form-input" /></div>
            <div class="form-field"><label>X (Twitter)</label><input v-model="editForm.twitter" type="url" class="form-input" /></div>
            <div class="form-field"><label>Facebook</label><input v-model="editForm.facebook" type="url" class="form-input" /></div>
          </div>
          <div class="form-row-2">
            <div class="form-field"><label>Keywords (comma-separated)</label><input v-model="editForm.keywords_raw" type="text" class="form-input" /></div>
          </div>
          <div class="form-row-1">
            <div class="form-field"><label>Notes</label><textarea v-model="editForm.notes" class="form-input form-textarea" rows="3"></textarea></div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn-cancel" @click="editing = false">Cancel</button>
          <button class="btn-save" :disabled="savingEdit" @click="saveEdit">{{ savingEdit ? "Saving..." : "Save Changes" }}</button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.cd-wrap { max-width: 980px; margin: 0 auto; padding: 24px 16px 60px; }
.back-btn { background: none; border: none; cursor: pointer; color: var(--color-text-muted); font-size: 13px; margin-bottom: 10px; display: block; padding: 0; }
.back-btn:hover { color: var(--color-text); }
.cd-loading { display: flex; align-items: center; gap: 10px; color: var(--color-text-muted); padding: 40px 0; }
.cd-error { background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; padding: 10px 14px; border-radius: var(--radius-md); font-size: 13px; margin-bottom: 14px; }

/* Hero */
.cd-hero { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 20px; flex-wrap: wrap; }
.cd-hero-left { flex: 1; min-width: 0; }
.cd-hero-main { display: flex; align-items: flex-start; gap: 16px; }
.cd-avatar { width: 64px; height: 64px; border-radius: 50%; flex-shrink: 0; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 700; font-size: 22px; }
.cd-hero-info { flex: 1; min-width: 0; }
.cd-name { font-size: 22px; font-weight: 700; margin: 0 0 4px; color: var(--color-text); }
.cd-meta-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.cd-email { font-size: 13px; color: var(--color-brand-base, #004C97); text-decoration: none; }
.cd-email:hover { text-decoration: underline; }
.cd-phone { font-size: 13px; color: var(--color-text-muted); }
.cd-sep { color: var(--color-text-muted); }
.source-badge { padding: 2px 10px; border-radius: 12px; background: var(--color-bg); border: 1px solid var(--color-border); font-size: 11px; font-weight: 600; color: var(--color-text-muted); }
.archived-badge { padding: 2px 10px; border-radius: 12px; background: #fffbeb; border: 1px solid #fcd34d; font-size: 11px; font-weight: 600; color: #d97706; }
.social-link { font-size: 12px; color: #004C97; text-transform: capitalize; text-decoration: none; padding: 2px 8px; border-radius: 10px; border: 1px solid #bfdbfe; background: #eff6ff; }
.social-link:hover { background: #dbeafe; }
.cd-keywords { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 6px; }
.keyword-chip { padding: 2px 8px; border-radius: 10px; background: var(--color-bg); border: 1px solid var(--color-border); font-size: 11px; color: var(--color-text-muted); }
.cd-hero-actions { display: flex; gap: 8px; align-items: flex-start; flex-shrink: 0; }
.btn-outline { padding: 8px 16px; border-radius: var(--radius-md); border: 1.5px solid var(--color-border); background: var(--color-surface); color: var(--color-text); font-size: 13px; font-weight: 500; cursor: pointer; text-decoration: none; }
.btn-outline:hover { border-color: #004C97; color: #004C97; }
.btn-sm { padding: 6px 12px; font-size: 12px; }
.btn-primary { padding: 8px 16px; border-radius: var(--radius-md); background: #004C97; color: #fff; border: none; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-primary:hover { background: #003a75; }

/* Notes / resume strip */
.cd-notes-strip, .cd-resume-strip { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: 14px 18px; margin-bottom: 14px; display: flex; flex-direction: column; gap: 6px; }
.cd-notes-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--color-text-muted); }
.cd-notes-text { font-size: 13px; color: var(--color-text); white-space: pre-wrap; margin: 0; }
.resume-upload-row { display: flex; align-items: center; gap: 10px; }
.resume-file-input { font-size: 13px; color: var(--color-text); }
.upload-err { font-size: 12px; color: #dc2626; }

/* Section header */
.cd-section-header { margin-bottom: 10px; margin-top: 4px; }
.cd-section-title { font-size: 16px; font-weight: 700; color: var(--color-text); margin: 0; }
.cd-empty { color: var(--color-text-muted); font-size: 14px; padding: 12px 0; }

/* App card */
.cd-app-card { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-lg); margin-bottom: 14px; overflow: hidden; }
.cd-app-header { display: flex; align-items: flex-start; justify-content: space-between; padding: 16px 18px 12px; border-bottom: 1px solid var(--color-border); gap: 12px; flex-wrap: wrap; }
.cd-app-header-left { flex: 1; min-width: 0; }
.cd-app-vacancy { font-size: 15px; font-weight: 700; color: var(--color-text); display: block; margin-bottom: 3px; }
.cd-app-meta { font-size: 12px; color: var(--color-text-muted); }
.cd-app-header-right { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.app-status-chip { padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; text-transform: capitalize; white-space: nowrap; }
.stage-move-wrap { display: flex; align-items: center; gap: 6px; }
.stage-select { padding: 5px 10px; border-radius: var(--radius-md); border: 1.5px solid var(--color-border); background: var(--color-bg); color: var(--color-text); font-size: 12px; outline: none; cursor: pointer; }
.stage-select:focus { border-color: #004C97; }
.btn-hire { padding: 5px 12px; border-radius: var(--radius-md); background: #059669; color: #fff; border: none; font-size: 12px; font-weight: 600; cursor: pointer; text-decoration: none; white-space: nowrap; }
.btn-hire:hover { background: #047857; }

/* App tabs */
.cd-app-tabs { display: flex; border-bottom: 1px solid var(--color-border); padding: 0 18px; gap: 0; }
.cd-tab { background: none; border: none; border-bottom: 2px solid transparent; padding: 10px 14px; font-size: 13px; font-weight: 500; color: var(--color-text-muted); cursor: pointer; white-space: nowrap; position: relative; }
.cd-tab:hover { color: var(--color-text); }
.cd-tab.active { color: #004C97; border-bottom-color: #004C97; font-weight: 600; }
.tab-dot { display: inline-block; width: 6px; height: 6px; border-radius: 50%; background: #004C97; margin-left: 4px; vertical-align: middle; }
.cd-tab-body { padding: 16px 18px; }
.cd-empty-sub { font-size: 13px; color: var(--color-text-muted); }

/* Current stage */
.current-stage-row { font-size: 13px; color: var(--color-text-muted); margin-bottom: 12px; }
.current-stage-row strong { font-weight: 600; }

/* Cover letter */
.cover-letter-box { background: var(--color-bg); border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: 14px 16px; font-size: 13px; color: var(--color-text); white-space: pre-wrap; line-height: 1.6; }

/* Documents */
.doc-section-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--color-text-muted); margin-bottom: 8px; }
.doc-row { display: flex; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px solid var(--color-border); }
.doc-row:last-child { border-bottom: none; }
.doc-icon { font-size: 18px; }
.doc-name { flex: 1; font-size: 13px; font-weight: 500; color: var(--color-text); }
.doc-dl-btn { padding: 5px 12px; border-radius: var(--radius-md); background: var(--color-bg); border: 1.5px solid var(--color-border); color: var(--color-text); font-size: 12px; font-weight: 500; text-decoration: none; cursor: pointer; }
.doc-dl-btn:hover { border-color: #004C97; color: #004C97; }

/* Spinner */
.spinner { display: inline-block; width: 16px; height: 16px; border: 2px solid var(--color-border); border-top-color: #004C97; border-radius: 50%; animation: spin 0.7s linear infinite; }
.spinner-sm { display: inline-block; width: 12px; height: 12px; border: 2px solid var(--color-border); border-top-color: #004C97; border-radius: 50%; animation: spin 0.7s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

/* Modal */
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; z-index: 9999; padding: 20px; }
.modal-box { background: var(--color-surface); border-radius: var(--radius-lg); width: 100%; max-width: 620px; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 20px 60px rgba(0,0,0,0.25); }
.modal-header { display: flex; align-items: center; justify-content: space-between; padding: 18px 24px 14px; border-bottom: 1px solid var(--color-border); }
.modal-header h3 { font-size: 17px; font-weight: 700; margin: 0; }
.modal-close { background: none; border: none; cursor: pointer; font-size: 18px; color: var(--color-text-muted); padding: 0; }
.modal-close:hover { color: var(--color-text); }
.modal-body { padding: 20px 24px; overflow-y: auto; display: flex; flex-direction: column; gap: 14px; }
.modal-footer { padding: 14px 24px 18px; border-top: 1px solid var(--color-border); display: flex; justify-content: flex-end; gap: 10px; }
.add-err { background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; padding: 9px 12px; border-radius: var(--radius-md); font-size: 13px; }
.form-section-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--color-text-muted); }
.form-row-1, .form-row-2, .form-row-3 { display: grid; gap: 12px; }
.form-row-1 { grid-template-columns: 1fr; }
.form-row-2 { grid-template-columns: 1fr 1fr; }
.form-row-3 { grid-template-columns: 1fr 1fr 1fr; }
@media (max-width: 600px) { .form-row-2, .form-row-3 { grid-template-columns: 1fr; } }
.form-field { display: flex; flex-direction: column; gap: 5px; }
.form-field label { font-size: 12px; font-weight: 600; color: var(--color-text-muted); }
.form-input { border: 1.5px solid var(--color-border); border-radius: var(--radius-md); padding: 9px 12px; font-size: 13px; color: var(--color-text); background: var(--color-bg); outline: none; width: 100%; box-sizing: border-box; }
.form-input:focus { border-color: #004C97; box-shadow: 0 0 0 3px #004C9715; }
.form-textarea { resize: vertical; min-height: 72px; }
.btn-cancel { padding: 9px 18px; border-radius: var(--radius-md); border: 1.5px solid var(--color-border); background: var(--color-surface); color: var(--color-text); font-size: 13px; cursor: pointer; }
.btn-save { padding: 9px 22px; border-radius: var(--radius-md); background: #004C97; color: #fff; border: none; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-save:disabled { opacity: .6; cursor: not-allowed; }
.btn-save:not(:disabled):hover { background: #003a75; }
</style>
