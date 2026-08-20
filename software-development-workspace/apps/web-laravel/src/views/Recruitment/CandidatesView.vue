<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount, watch } from "vue";
import { useRouter } from "vue-router";
import { api } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import type { Candidate, Vacancy, Paginator } from "../../lib/types";

const router = useRouter();

const SOURCES  = ["LinkedIn", "Referral", "Job Portal", "Website", "Internal", "Agency", "Walk-in", "Other"];
const STATUSES = [
  { value: "active",      label: "Active" },
  { value: "shortlisted", label: "Shortlisted" },
  { value: "rejected",    label: "Rejected" },
  { value: "hired",       label: "Hired" },
  { value: "withdrawn",   label: "Withdrawn" },
];

const candidates = ref<Candidate[]>([]);
const vacancies  = ref<{ id: number; name: string }[]>([]);
const total      = ref(0);
const lastPage   = ref(1);
const page       = ref(1);
const loading    = ref(false);
const error      = ref("");
const perPage    = 20;
const hasPrev    = computed(() => page.value > 1);
const hasNext    = computed(() => page.value < lastPage.value);

const filters = ref({ search: "", vacancy_id: "", source: "", status: "", date_from: "", date_to: "", archived: false });
function clearFilters() {
  filters.value = { search: "", vacancy_id: "", source: "", status: "", date_from: "", date_to: "", archived: false };
  load(1);
}
const hasActiveFilters = computed(() =>
  !!filters.value.search || !!filters.value.vacancy_id || !!filters.value.source ||
  !!filters.value.status || !!filters.value.date_from || !!filters.value.date_to || filters.value.archived
);

const selected = ref<Set<number>>(new Set());
const allOnPageSelected = computed(
  () => candidates.value.length > 0 && candidates.value.every(c => selected.value.has(c.id))
);
function toggleSelect(id: number) {
  const s = new Set(selected.value); s.has(id) ? s.delete(id) : s.add(id); selected.value = s;
}
function toggleAll() {
  if (allOnPageSelected.value) { selected.value = new Set(); }
  else { const s = new Set(selected.value); candidates.value.forEach(c => s.add(c.id)); selected.value = s; }
}

const openMenu = ref<number | null>(null);
function toggleMenu(id: number, e: Event) { e.stopPropagation(); openMenu.value = openMenu.value === id ? null : id; }
function closeMenus() { openMenu.value = null; }

const showAdd    = ref(false);
const saving     = ref(false);
const addErr     = ref("");
const resumeFile = ref<File | null>(null);
const blankForm  = () => ({ first_name: "", middle_name: "", last_name: "", email: "", phone: "",
  vacancy_id: "", applied_at: new Date().toISOString().slice(0, 10),
  source: "", linkedin: "", twitter: "", facebook: "", keywords_raw: "", notes: "" });
const newCand = ref(blankForm());

function openAdd() { addErr.value = ""; resumeFile.value = null; newCand.value = blankForm(); showAdd.value = true; }
function onResumeChange(e: Event) { resumeFile.value = (e.target as HTMLInputElement).files?.[0] ?? null; }

async function saveCandidate() {
  addErr.value = "";
  if (!newCand.value.first_name || !newCand.value.last_name || !newCand.value.email) {
    addErr.value = "First name, last name and email are required."; return;
  }
  saving.value = true;
  try {
    const social_links: Record<string, string> = {};
    if (newCand.value.linkedin) social_links.linkedin = newCand.value.linkedin;
    if (newCand.value.twitter)  social_links.twitter  = newCand.value.twitter;
    if (newCand.value.facebook) social_links.facebook = newCand.value.facebook;
    const keywords = newCand.value.keywords_raw.split(",").map((k: string) => k.trim()).filter(Boolean);
    const body: Record<string, unknown> = { first_name: newCand.value.first_name, last_name: newCand.value.last_name, email: newCand.value.email };
    if (newCand.value.middle_name)        body.middle_name  = newCand.value.middle_name;
    if (newCand.value.phone)              body.phone        = newCand.value.phone;
    if (newCand.value.source)             body.source       = newCand.value.source;
    if (newCand.value.notes)              body.notes        = newCand.value.notes;
    if (keywords.length)                  body.keywords     = keywords;
    if (Object.keys(social_links).length) body.social_links = social_links;

    const created = await api<{ data: Candidate }>("POST", "/api/v1/recruitment/candidates", { org: currentOrgSlug.value!, body });

    if (resumeFile.value) {
      const form = new FormData(); form.append("resume", resumeFile.value);
      await api("POST", `/api/v1/recruitment/candidates/${created.data.id}/resume`, { org: currentOrgSlug.value!, body: form });
    }
    if (newCand.value.vacancy_id) {
      await api("POST", `/api/v1/recruitment/vacancies/${newCand.value.vacancy_id}/applications`,
        { org: currentOrgSlug.value!, body: { candidate_id: created.data.id } });
    }
    showAdd.value = false; load(1);
  } catch (e) { addErr.value = e instanceof Error ? e.message : "Failed to save."; }
  finally { saving.value = false; }
}

const bulkSaving = ref(false);
async function doBulkAction(action: string) {
  if (selected.value.size === 0) return;
  const labels: Record<string,string> = { shortlist:"Shortlist", reject:"Reject", archive:"Archive", delete:"Delete" };
  const msg = action === "delete"
    ? `Permanently delete ${selected.value.size} candidate(s)? Cannot be undone.`
    : `${labels[action] ?? action} ${selected.value.size} candidate(s)?`;
  if (!window.confirm(msg)) return;
  bulkSaving.value = true;
  try {
    if (action === "archive" || action === "delete") {
      await api("POST", "/api/v1/recruitment/candidates/bulk-action",
        { org: currentOrgSlug.value!, body: { action, candidate_ids: Array.from(selected.value) } });
    } else {
      const appIds: number[] = [];
      for (const c of candidates.value) {
        if (selected.value.has(c.id) && c.applications?.[0]) appIds.push(c.applications[0].id);
      }
      if (appIds.length) {
        await api("POST", "/api/v1/recruitment/applications/bulk-action",
          { org: currentOrgSlug.value!, body: { action, application_ids: appIds } });
      }
    }
    selected.value = new Set(); load(page.value);
  } catch (e) { error.value = e instanceof Error ? e.message : "Bulk action failed."; }
  finally { bulkSaving.value = false; }
}

async function archiveOne(id: number) {
  closeMenus(); if (!window.confirm("Archive this candidate?")) return;
  try { await api("POST", "/api/v1/recruitment/candidates/bulk-action", { org: currentOrgSlug.value!, body: { action: "archive", candidate_ids: [id] } }); load(page.value); }
  catch (e) { error.value = e instanceof Error ? e.message : "Archive failed."; }
}
async function unarchiveOne(id: number) {
  closeMenus();
  try { await api("POST", "/api/v1/recruitment/candidates/bulk-action", { org: currentOrgSlug.value!, body: { action: "unarchive", candidate_ids: [id] } }); load(page.value); }
  catch (e) { error.value = e instanceof Error ? e.message : "Failed."; }
}
async function deleteOne(id: number) {
  closeMenus(); if (!window.confirm("Delete this candidate? This cannot be undone.")) return;
  try { await api("DELETE", `/api/v1/recruitment/candidates/${id}`, { org: currentOrgSlug.value! }); load(page.value); }
  catch (e) { error.value = e instanceof Error ? e.message : "Delete failed."; }
}

async function exportCsv() {
  try {
    const params: Record<string, string | number> = { per_page: 9999 };
    if (filters.value.search)     params.search     = filters.value.search;
    if (filters.value.vacancy_id) params.vacancy_id = filters.value.vacancy_id;
    if (filters.value.source)     params.source     = filters.value.source;
    if (filters.value.status)     params.status     = filters.value.status;
    if (filters.value.date_from)  params.date_from  = filters.value.date_from;
    if (filters.value.date_to)    params.date_to    = filters.value.date_to;
    if (filters.value.archived)   params.archived   = "1";
    const res = await api<Paginator<Candidate>>("GET", "/api/v1/recruitment/candidates", { org: currentOrgSlug.value!, params });
    const esc = (v: unknown) => `"${String(v ?? "").replace(/"/g, '""')}"`;
    const rows = [
      ["ID","Full Name","Email","Phone","Source","Keywords","Vacancy","Stage","Status","Applied","Score"],
      ...res.data.map(c => {
        const app = c.applications?.[0];
        return [c.id, c.full_name, c.email, c.phone ?? "", c.source ?? "",
          (c.keywords ?? []).join("; "), app?.vacancy?.name ?? "", app?.current_stage?.name ?? "",
          app?.status ?? "", app?.applied_at ? new Date(app.applied_at).toLocaleDateString("en-GB") : "", app?.screening_score ?? ""];
      }),
    ];
    const csv  = rows.map(r => r.map(esc).join(",")).join("\n");
    const blob = new Blob([csv], { type: "text/csv;charset=utf-8;" });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement("a");
    a.href = url; a.download = `candidates_${new Date().toISOString().slice(0,10)}.csv`; a.click(); URL.revokeObjectURL(url);
  } catch { error.value = "Export failed."; }
}

function org() { return currentOrgSlug.value; }
async function load(p = 1) {
  if (!org()) { error.value = "Select an organisation first."; return; }
  loading.value = true; error.value = ""; page.value = p;
  const params: Record<string, string | number> = { page: p, per_page: perPage };
  if (filters.value.search)     params.search     = filters.value.search;
  if (filters.value.vacancy_id) params.vacancy_id = filters.value.vacancy_id;
  if (filters.value.source)     params.source     = filters.value.source;
  if (filters.value.status)     params.status     = filters.value.status;
  if (filters.value.date_from)  params.date_from  = filters.value.date_from;
  if (filters.value.date_to)    params.date_to    = filters.value.date_to;
  if (filters.value.archived)   params.archived   = "1";
  try {
    const res = await api<Paginator<Candidate>>("GET", "/api/v1/recruitment/candidates", { org: org()!, params });
    candidates.value = res.data; total.value = res.meta.total; lastPage.value = res.meta.last_page;
  } catch (e) { error.value = e instanceof Error ? e.message : "Failed to load."; }
  finally { loading.value = false; }
}
async function loadVacancies() {
  if (!org()) return;
  try {
    const res = await api<Paginator<Vacancy>>("GET", "/api/v1/recruitment/vacancies", { org: org()!, params: { per_page: 200, status: "open" } });
    vacancies.value = res.data.map((v: Vacancy) => ({ id: v.id, name: v.name }));
  } catch { /* silent */ }
}

function stageColor(t?: string) {
  const m: Record<string, string> = { hired:"#059669", rejected:"#dc2626", offer:"#d97706", interview:"#6366f1", screening:"#0ea5e9", applied:"#6b7280", shortlisted:"#f59e0b", pre_onboarding:"#8b5cf6" };
  return m[t ?? ""] ?? "#9ca3af";
}
function statusStyle(s?: string): { bg: string; color: string } {
  const m: Record<string, { bg: string; color: string }> = { active:{bg:"#eff6ff",color:"#3b82f6"}, shortlisted:{bg:"#fffbeb",color:"#d97706"}, rejected:{bg:"#fef2f2",color:"#dc2626"}, hired:{bg:"#f0fdf4",color:"#059669"}, withdrawn:{bg:"#f1f5f9",color:"#64748b"} };
  return m[s ?? ""] ?? { bg:"#f1f5f9", color:"#64748b" };
}
function initials(n: string) { return n.split(" ").map(x => x[0]).slice(0,2).join("").toUpperCase(); }
function avatarColor(id: number) { const c=["#004C97","#0ea5e9","#6366f1","#8b5cf6","#059669","#d97706"]; return c[id%c.length]; }
function fmtDate(d?: string | null) { if (!d) return "—"; return new Date(d).toLocaleDateString("en-GB",{day:"numeric",month:"short",year:"numeric"}); }

onMounted(() => { load(1); loadVacancies(); });
watch(currentOrgSlug, () => { load(1); loadVacancies(); });
document.addEventListener("click", closeMenus);
onBeforeUnmount(() => document.removeEventListener("click", closeMenus));
</script>

<template>
  <div class="cv-wrap">

    <!-- Header -->
    <div class="cv-header">
      <div class="cv-header-left">
        <button class="back-btn" @click="router.back()">&#8592; Recruitment</button>
        <div>
          <h2 class="cv-title">Candidates</h2>
          <p class="cv-sub">
            {{ total }} total
            <span v-if="selected.size > 0"> &middot; <strong>{{ selected.size }} selected</strong></span>
            <span v-if="filters.archived" class="archived-badge"> &mdash; Archived</span>
          </p>
        </div>
      </div>
      <div class="cv-header-actions">
        <button class="btn-export" @click="exportCsv">&#8595; Export CSV</button>
        <button class="btn-add" @click="openAdd">+ Add Candidate</button>
      </div>
    </div>

    <!-- Filter toolbar -->
    <div class="cv-toolbar">
      <div class="cv-search-wrap">
        <svg class="search-ico" viewBox="0 0 20 20" fill="none"><circle cx="8.5" cy="8.5" r="5.5" stroke="currentColor" stroke-width="1.6"/><path d="M13.5 13.5L17 17" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
        <input v-model="filters.search" class="cv-search-input" placeholder="Search name or email..." @keyup.enter="load(1)" />
        <button v-if="filters.search" class="cv-clear-btn" @click="filters.search = ''; load(1)">&#10005;</button>
      </div>
      <select v-model="filters.vacancy_id" class="cv-select" @change="load(1)">
        <option value="">All Vacancies</option>
        <option v-for="v in vacancies" :key="v.id" :value="String(v.id)">{{ v.name }}</option>
      </select>
      <select v-model="filters.source" class="cv-select" @change="load(1)">
        <option value="">All Sources</option>
        <option v-for="s in SOURCES" :key="s" :value="s">{{ s }}</option>
      </select>
      <select v-model="filters.status" class="cv-select" @change="load(1)">
        <option value="">All Statuses</option>
        <option v-for="s in STATUSES" :key="s.value" :value="s.value">{{ s.label }}</option>
      </select>
      <div class="cv-date-range">
        <input v-model="filters.date_from" type="date" class="cv-date-input" @change="load(1)" title="Applied from" />
        <span class="cv-date-sep">&#8594;</span>
        <input v-model="filters.date_to" type="date" class="cv-date-input" @change="load(1)" title="Applied to" />
      </div>
      <label class="cv-archived-toggle">
        <input type="checkbox" v-model="filters.archived" @change="load(1)" />
        <span>Archived</span>
      </label>
      <button class="btn-search" @click="load(1)">Search</button>
      <button v-if="hasActiveFilters" class="btn-clear-filters" @click="clearFilters">&#10005; Clear</button>
    </div>

    <!-- Bulk action bar -->
    <transition name="bulk-slide">
      <div v-if="selected.size > 0" class="cv-bulk-bar">
        <span class="bulk-count">{{ selected.size }} candidate{{ selected.size > 1 ? 's' : '' }} selected</span>
        <div class="bulk-actions">
          <button class="bulk-btn bulk-shortlist" :disabled="bulkSaving" @click="doBulkAction('shortlist')">Shortlist</button>
          <button class="bulk-btn bulk-reject"    :disabled="bulkSaving" @click="doBulkAction('reject')">Reject</button>
          <button class="bulk-btn bulk-archive"   :disabled="bulkSaving" @click="doBulkAction('archive')">Archive</button>
          <button class="bulk-btn bulk-delete"    :disabled="bulkSaving" @click="doBulkAction('delete')">Delete</button>
          <button class="bulk-btn bulk-clear" @click="selected = new Set()">Clear</button>
        </div>
      </div>
    </transition>

    <!-- States -->
    <div v-if="error" class="cv-error">{{ error }}</div>
    <div v-if="loading" class="cv-loading"><div class="spinner"></div> Loading candidates...</div>
    <div v-if="!loading && candidates.length === 0 && !error" class="cv-empty">
      <div class="empty-icon">&#128100;</div>
      <p>No candidates found{{ hasActiveFilters ? " matching your filters" : "" }}.</p>
      <button v-if="hasActiveFilters" class="btn-clear-filters" style="margin-top:10px" @click="clearFilters">Clear filters</button>
    </div>

    <!-- Table -->
    <div v-if="!loading && candidates.length > 0" class="cv-table-wrap">
      <table class="cv-table">
        <thead>
          <tr>
            <th class="col-check"><input type="checkbox" :checked="allOnPageSelected" @change="toggleAll" /></th>
            <th>Candidate</th>
            <th>Vacancy</th>
            <th>Stage</th>
            <th>Status</th>
            <th>Source</th>
            <th>Applied</th>
            <th class="col-score">Score</th>
            <th class="col-actions"></th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="c in candidates" :key="c.id"
            :class="{ 'row-selected': selected.has(c.id), 'row-archived': c.is_archived }"
            @click="toggleSelect(c.id)"
          >
            <td class="col-check" @click.stop>
              <input type="checkbox" :checked="selected.has(c.id)" @change="toggleSelect(c.id)" />
            </td>
            <td class="col-cand" @click.stop="router.push({ name: 'candidate-detail', params: { id: c.id } })">
              <div class="cand-row">
                <div class="cand-avatar" :style="{ background: avatarColor(c.id) }">{{ initials(c.full_name) }}</div>
                <div class="cand-info">
                  <span class="cand-name">{{ c.full_name }}</span>
                  <span class="cand-email">{{ c.email }}</span>
                </div>
              </div>
            </td>
            <td class="col-vacancy">
              <span v-if="c.applications?.[0]?.vacancy" class="vacancy-text">{{ c.applications![0].vacancy!.name }}</span>
              <span v-else class="text-muted">&#8212;</span>
              <span v-if="(c.applications?.length ?? 0) > 1" class="more-apps">+{{ (c.applications?.length ?? 0) - 1 }}</span>
            </td>
            <td>
              <span v-if="c.applications?.[0]?.current_stage" class="stage-chip"
                :style="{ background: stageColor(c.applications![0].current_stage!.stage_type) + '1a', color: stageColor(c.applications![0].current_stage!.stage_type), borderColor: stageColor(c.applications![0].current_stage!.stage_type) + '55' }">
                {{ c.applications![0].current_stage!.name }}
              </span>
              <span v-else class="text-muted">&#8212;</span>
            </td>
            <td>
              <span v-if="c.applications?.[0]?.status" class="status-chip"
                :style="{ background: statusStyle(c.applications![0].status).bg, color: statusStyle(c.applications![0].status).color }">
                {{ c.applications![0].status }}
              </span>
              <span v-else class="text-muted">&#8212;</span>
            </td>
            <td><span v-if="c.source" class="source-chip">{{ c.source }}</span><span v-else class="text-muted">&#8212;</span></td>
            <td class="col-date">{{ fmtDate(c.applications?.[0]?.applied_at) }}</td>
            <td class="col-score">
              <span v-if="c.applications?.[0]?.screening_score != null" class="score-pill"
                :style="{ color: c.applications![0].screening_score! >= 70 ? '#059669' : c.applications![0].screening_score! >= 40 ? '#d97706' : '#dc2626' }">
                {{ c.applications![0].screening_score }}%
              </span>
              <span v-else class="text-muted">&#8212;</span>
            </td>
            <td class="col-actions" @click.stop>
              <div class="menu-wrap">
                <button class="menu-btn" @click="toggleMenu(c.id, $event)">&#8943;</button>
                <div v-if="openMenu === c.id" class="menu-dropdown">
                  <router-link :to="{ name: 'candidate-detail', params: { id: c.id } }" class="menu-item">&#128100; View Profile</router-link>
                  <a v-if="c.resume_url" :href="c.resume_url" target="_blank" class="menu-item">&#128196; Download Resume</a>
                  <button v-if="!c.is_archived" class="menu-item" @click="archiveOne(c.id)">&#128230; Archive</button>
                  <button v-else class="menu-item" @click="unarchiveOne(c.id)">&#9851; Unarchive</button>
                  <button class="menu-item menu-item-danger" @click="deleteOne(c.id)">&#128465; Delete</button>
                </div>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div v-if="lastPage > 1" class="cv-pagination">
      <button :disabled="!hasPrev" class="page-btn" @click="load(page - 1)">&#8592; Prev</button>
      <span class="page-info">Page {{ page }} of {{ lastPage }}</span>
      <button :disabled="!hasNext" class="page-btn" @click="load(page + 1)">Next &#8594;</button>
    </div>

  </div>

  <!-- Add Candidate Modal -->
  <Teleport to="body">
    <div v-if="showAdd" class="modal-backdrop" @click.self="showAdd = false">
      <div class="modal-box">
        <div class="modal-header">
          <h3>Add Candidate</h3>
          <button class="modal-close" @click="showAdd = false">&#10005;</button>
        </div>
        <div class="modal-body">
          <div v-if="addErr" class="add-err">{{ addErr }}</div>
          <div class="form-row-3">
            <div class="form-field"><label>First Name <span class="req">*</span></label><input v-model="newCand.first_name" type="text" class="form-input" placeholder="First name" /></div>
            <div class="form-field"><label>Middle Name</label><input v-model="newCand.middle_name" type="text" class="form-input" placeholder="Middle name" /></div>
            <div class="form-field"><label>Last Name <span class="req">*</span></label><input v-model="newCand.last_name" type="text" class="form-input" placeholder="Last name" /></div>
          </div>
          <div class="form-row-2">
            <div class="form-field"><label>Email <span class="req">*</span></label><input v-model="newCand.email" type="email" class="form-input" placeholder="email@example.com" /></div>
            <div class="form-field"><label>Contact Number</label><input v-model="newCand.phone" type="tel" class="form-input" placeholder="+254 712 345 678" /></div>
          </div>
          <div class="form-row-2">
            <div class="form-field">
              <label>Vacancy</label>
              <select v-model="newCand.vacancy_id" class="form-input">
                <option value="">&#8212; Select vacancy &#8212;</option>
                <option v-for="v in vacancies" :key="v.id" :value="String(v.id)">{{ v.name }}</option>
              </select>
            </div>
            <div class="form-field"><label>Date of Application</label><input v-model="newCand.applied_at" type="date" class="form-input" /></div>
          </div>
          <div class="form-row-1">
            <div class="form-field">
              <label>Candidate Source</label>
              <select v-model="newCand.source" class="form-input">
                <option value="">&#8212; Select source &#8212;</option>
                <option v-for="s in SOURCES" :key="s" :value="s">{{ s }}</option>
              </select>
            </div>
          </div>
          <div class="form-section-label">Social Profiles</div>
          <div class="form-row-3">
            <div class="form-field"><label>LinkedIn</label><input v-model="newCand.linkedin" type="url" class="form-input" placeholder="https://linkedin.com/in/..." /></div>
            <div class="form-field"><label>X (Twitter)</label><input v-model="newCand.twitter" type="url" class="form-input" placeholder="https://x.com/..." /></div>
            <div class="form-field"><label>Facebook</label><input v-model="newCand.facebook" type="url" class="form-input" placeholder="https://facebook.com/..." /></div>
          </div>
          <div class="form-row-2">
            <div class="form-field"><label>Keywords</label><input v-model="newCand.keywords_raw" type="text" class="form-input" placeholder="react, python (comma-separated)" /></div>
            <div class="form-field"><label>Resume (PDF/DOC, max 5MB)</label><input type="file" class="form-input" accept=".pdf,.doc,.docx" @change="onResumeChange" /></div>
          </div>
          <div class="form-row-1">
            <div class="form-field"><label>Notes</label><textarea v-model="newCand.notes" class="form-input form-textarea" rows="3" placeholder="Additional notes..."></textarea></div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn-cancel" @click="showAdd = false">Cancel</button>
          <button class="btn-save" :disabled="saving" @click="saveCandidate">{{ saving ? "Saving..." : "Save Candidate" }}</button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.cv-wrap { max-width: none; padding: 24px clamp(1rem, 2.5vw, 3rem) 60px; }
.cv-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 20px; gap: 12px; flex-wrap: wrap; }
.cv-header-left { display: flex; align-items: flex-start; gap: 12px; }
.back-btn { background: none; border: none; cursor: pointer; color: var(--color-text-muted); font-size: 13px; margin-bottom: 4px; display: block; padding: 0; }
.back-btn:hover { color: var(--color-text); }
.cv-title { font-size: 22px; font-weight: 700; color: var(--color-text); margin: 0 0 2px; }
.cv-sub { font-size: 13px; color: var(--color-text-muted); margin: 0; }
.archived-badge { color: #d97706; font-weight: 600; }
.cv-header-actions { display: flex; gap: 10px; align-items: center; flex-shrink: 0; }
.btn-export { padding: 8px 16px; border-radius: var(--radius-lg); border: 1.5px solid var(--color-border); background: var(--color-surface); color: var(--color-text); font-size: 13px; font-weight: 500; cursor: pointer; }
.btn-export:hover { border-color: #004C97; color: #004C97; }
.btn-add { padding: 8px 18px; border-radius: var(--radius-lg); background: #004C97; color: #fff; border: none; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-add:hover { background: #003a75; }
.cv-toolbar { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; flex-wrap: wrap; padding: 14px 16px; background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-lg); }
.cv-search-wrap { flex: 1; min-width: 200px; display: flex; align-items: center; gap: 8px; background: var(--color-bg); border: 1.5px solid var(--color-border); border-radius: var(--radius-md); padding: 0 12px; }
.cv-search-wrap:focus-within { border-color: #004C97; }
.search-ico { width: 15px; height: 15px; color: var(--color-text-muted); flex-shrink: 0; }
.cv-search-input { flex: 1; border: none; outline: none; background: transparent; padding: 8px 0; font-size: 13px; color: var(--color-text); }
.cv-search-input::placeholder { color: var(--color-text-muted); }
.cv-clear-btn { background: none; border: none; cursor: pointer; color: var(--color-text-muted); font-size: 12px; padding: 0; }
.cv-select { padding: 8px 12px; border-radius: var(--radius-md); border: 1.5px solid var(--color-border); background: var(--color-bg); color: var(--color-text); font-size: 13px; cursor: pointer; outline: none; min-width: 130px; }
.cv-select:focus { border-color: #004C97; }
.cv-date-range { display: flex; align-items: center; gap: 4px; }
.cv-date-input { padding: 8px 10px; border-radius: var(--radius-md); border: 1.5px solid var(--color-border); background: var(--color-bg); color: var(--color-text); font-size: 12px; outline: none; width: 130px; }
.cv-date-input:focus { border-color: #004C97; }
.cv-date-sep { font-size: 12px; color: var(--color-text-muted); }
.cv-archived-toggle { display: flex; align-items: center; gap: 6px; cursor: pointer; font-size: 13px; color: var(--color-text-muted); white-space: nowrap; user-select: none; }
.cv-archived-toggle input { cursor: pointer; }
.btn-search { padding: 8px 18px; border-radius: var(--radius-md); background: #004C97; color: #fff; border: none; cursor: pointer; font-size: 13px; font-weight: 600; white-space: nowrap; }
.btn-search:hover { background: #003a75; }
.btn-clear-filters { padding: 7px 14px; border-radius: var(--radius-md); border: 1.5px solid #fecaca; background: #fef2f2; color: #dc2626; font-size: 12px; font-weight: 500; cursor: pointer; white-space: nowrap; }
.btn-clear-filters:hover { background: #fee2e2; }
.cv-bulk-bar { display: flex; align-items: center; gap: 12px; padding: 10px 16px; background: #eff6ff; border: 1.5px solid #bfdbfe; border-radius: var(--radius-lg); margin-bottom: 12px; flex-wrap: wrap; }
.bulk-count { font-size: 13px; font-weight: 600; color: #1d4ed8; white-space: nowrap; }
.bulk-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.bulk-btn { padding: 6px 14px; border-radius: var(--radius-md); border: 1.5px solid; font-size: 12px; font-weight: 600; cursor: pointer; }
.bulk-btn:disabled { opacity: .5; cursor: not-allowed; }
.bulk-shortlist { background: #fffbeb; border-color: #fcd34d; color: #d97706; }
.bulk-reject    { background: #fef2f2; border-color: #fca5a5; color: #dc2626; }
.bulk-archive   { background: #f1f5f9; border-color: #cbd5e1; color: #475569; }
.bulk-delete    { background: #fef2f2; border-color: #fca5a5; color: #dc2626; }
.bulk-clear     { background: transparent; border-color: #cbd5e1; color: var(--color-text-muted); }
.bulk-slide-enter-active, .bulk-slide-leave-active { transition: all 0.2s; }
.bulk-slide-enter-from, .bulk-slide-leave-to { opacity: 0; transform: translateY(-8px); }
.cv-error { background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; padding: 10px 14px; border-radius: var(--radius-md); font-size: 13px; margin-bottom: 14px; }
.cv-loading { display: flex; align-items: center; gap: 10px; color: var(--color-text-muted); padding: 40px 0; font-size: 14px; }
.spinner { width: 18px; height: 18px; border: 2px solid var(--color-border); border-top-color: #004C97; border-radius: 50%; animation: spin 0.7s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.cv-empty { text-align: center; padding: 60px 20px; color: var(--color-text-muted); }
.empty-icon { font-size: 40px; margin-bottom: 10px; }
.cv-empty p { font-size: 15px; }
.cv-table-wrap { border: 1px solid var(--color-border); border-radius: var(--radius-lg); overflow: hidden; overflow-x: auto; }
.cv-table { width: 100%; border-collapse: collapse; }
.cv-table thead { background: var(--color-bg); }
.cv-table th { padding: 12px 14px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--color-text-muted); text-align: left; border-bottom: 1.5px solid var(--color-border); white-space: nowrap; }
.cv-table td { padding: 12px 14px; font-size: 13px; color: var(--color-text); border-bottom: 1px solid var(--color-border); vertical-align: middle; }
.cv-table tbody tr { background: var(--color-surface); transition: background 0.1s; cursor: pointer; }
.cv-table tbody tr:last-child td { border-bottom: none; }
.cv-table tbody tr:hover { background: var(--color-bg); }
.cv-table tbody tr.row-selected { background: #eff6ff; }
.cv-table tbody tr.row-archived { opacity: 0.65; }
.col-check { width: 40px; text-align: center; padding: 12px 8px; cursor: default; }
.col-cand { cursor: pointer; }
.col-score { text-align: center; width: 70px; }
.col-actions { width: 50px; text-align: center; padding: 8px; }
.col-date { white-space: nowrap; color: var(--color-text-muted); font-size: 12px; }
.col-vacancy { max-width: 180px; }
.cand-row { display: flex; align-items: center; gap: 10px; }
.cand-avatar { width: 36px; height: 36px; border-radius: 50%; flex-shrink: 0; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 700; font-size: 13px; }
.cand-info { display: flex; flex-direction: column; min-width: 0; }
.cand-name { font-weight: 600; color: var(--color-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cand-name:hover { color: #004C97; text-decoration: underline; }
.cand-email { font-size: 12px; color: var(--color-text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.stage-chip { display: inline-block; padding: 2px 9px; border-radius: 12px; font-size: 11px; font-weight: 600; border: 1px solid; white-space: nowrap; }
.status-chip { display: inline-block; padding: 2px 9px; border-radius: 12px; font-size: 11px; font-weight: 600; text-transform: capitalize; }
.source-chip { display: inline-block; padding: 2px 9px; border-radius: 12px; background: var(--color-bg); border: 1px solid var(--color-border); font-size: 11px; color: var(--color-text-muted); }
.score-pill { font-size: 12px; font-weight: 700; }
.vacancy-text { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block; max-width: 160px; font-size: 12px; }
.more-apps { font-size: 11px; color: var(--color-text-muted); margin-left: 4px; }
.text-muted { color: var(--color-text-muted); font-size: 12px; }
.menu-wrap { position: relative; display: flex; justify-content: center; }
.menu-btn { background: none; border: 1px solid transparent; border-radius: var(--radius-md); width: 28px; height: 28px; cursor: pointer; font-size: 16px; color: var(--color-text-muted); display: flex; align-items: center; justify-content: center; }
.menu-btn:hover { border-color: var(--color-border); background: var(--color-bg); color: var(--color-text); }
.menu-dropdown { position: absolute; right: 0; top: 32px; z-index: 100; background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-lg); box-shadow: 0 8px 24px rgba(0,0,0,0.12); min-width: 160px; padding: 4px 0; }
.menu-item { display: flex; align-items: center; gap: 8px; padding: 9px 14px; font-size: 13px; color: var(--color-text); cursor: pointer; background: none; border: none; width: 100%; text-align: left; text-decoration: none; }
.menu-item:hover { background: var(--color-bg); }
.menu-item-danger { color: #dc2626; }
.menu-item-danger:hover { background: #fef2f2; }
.cv-pagination { display: flex; align-items: center; gap: 12px; justify-content: center; margin-top: 20px; }
.page-btn { padding: 8px 18px; border-radius: var(--radius-md); border: 1.5px solid var(--color-border); background: var(--color-surface); color: var(--color-text); font-size: 13px; font-weight: 500; cursor: pointer; }
.page-btn:disabled { opacity: .4; cursor: not-allowed; }
.page-btn:not(:disabled):hover { border-color: #004C97; color: #004C97; }
.page-info { font-size: 13px; color: var(--color-text-muted); }
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; z-index: 9999; padding: 20px; }
.modal-box { background: var(--color-surface); border-radius: var(--radius-lg); width: 100%; max-width: 680px; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 20px 60px rgba(0,0,0,0.25); }
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
.req { color: #dc2626; }
.form-input { border: 1.5px solid var(--color-border); border-radius: var(--radius-md); padding: 9px 12px; font-size: 13px; color: var(--color-text); background: var(--color-bg); outline: none; width: 100%; box-sizing: border-box; }
.form-input:focus { border-color: #004C97; box-shadow: 0 0 0 3px #004C9715; }
.form-textarea { resize: vertical; min-height: 72px; }
.btn-cancel { padding: 9px 18px; border-radius: var(--radius-md); border: 1.5px solid var(--color-border); background: var(--color-surface); color: var(--color-text); font-size: 13px; cursor: pointer; }
.btn-save { padding: 9px 22px; border-radius: var(--radius-md); background: #004C97; color: #fff; border: none; font-size: 13px; font-weight: 600; cursor: pointer; }
.btn-save:disabled { opacity: .6; cursor: not-allowed; }
.btn-save:not(:disabled):hover { background: #003a75; }
</style>
