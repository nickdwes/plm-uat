<script setup lang="ts">
import { ref, computed, onMounted, watch } from "vue";
import { useRoute } from "vue-router";
import { api } from "../lib/api";
import { currentOrgSlug } from "../stores/auth";

// ── Types ─────────────────────────────────────────────────────────────────────
interface OrgProfile {
  id: number; name: string; slug: string; legal_name: string | null;
  industry: string | null; country: string; currency: string;
  primary_email: string | null; primary_phone: string | null;
  address: string | null; is_active: boolean; logo_url?: string | null;
  primary_color?: string | null; headcount?: number;
}
interface ComplianceDoc {
  id: number; document_key: string; segment: string; document_label: string;
  is_perpetual: boolean; file_url: string | null; file_name: string | null;
  issue_date: string | null; expiry_date: string | null; anniversary_date: string | null;
  notes: string | null; owner_name: string | null; owner_email: string | null;
  status_override: string | null; renewal_guide: string | null;
  reminder_days_before: number; reminder_recipients: string | null;
  reminder_active: boolean; reminder_thresholds: number[];
  last_reminder_sent_at: string | null; audit_trail: AuditEntry[];
  status: string; days_until_expiry: number | null;
}
interface AuditEntry { at: string; by: string; action: string; note: string }
interface Summary {
  total: number; not_uploaded: number; valid: number; expiring: number;
  expired: number; under_review: number; pending_renewal: number;
}

// ── State ─────────────────────────────────────────────────────────────────────
const route   = useRoute();
const orgSlug = computed(() => route.params.slug as string);

const org          = ref<OrgProfile | null>(null);
const docs         = ref<ComplianceDoc[]>([]);
const summary      = ref<Summary | null>(null);
const loading      = ref(false);
const docLoading   = ref(false);
const error        = ref("");
const activeTab    = ref("Core Registration");

// Edit / upload state
const editing      = ref<ComplianceDoc | null>(null);
const editForm     = ref({ issue_date: "", expiry_date: "", anniversary_date: "",
  notes: "", owner_name: "", owner_email: "", status_override: "" });
const saving       = ref(false);
const saveErr      = ref("");
const saveOk       = ref("");

// Guide modal
const guideDoc     = ref<ComplianceDoc | null>(null);
const guideEdit    = ref(false);
const guideText    = ref("");
const guideSaving  = ref(false);

// Reminder modal
const reminderDoc  = ref<ComplianceDoc | null>(null);
const reminderForm = ref({ reminder_active: false, reminder_recipients: "", reminder_thresholds: "" });
const reminderSaving = ref(false);

// Audit trail modal
const auditDoc     = ref<ComplianceDoc | null>(null);

// File upload
const uploadingKey = ref<string | null>(null);
const fileInput    = ref<HTMLInputElement | null>(null);

// Toast
const toast = ref<{ msg: string; type: "ok" | "err" } | null>(null);
let toastTimer: ReturnType<typeof setTimeout> | null = null;
function showToast(msg: string, type: "ok" | "err" = "ok") {
  if (toastTimer) clearTimeout(toastTimer);
  toast.value = { msg, type };
  toastTimer = setTimeout(() => { toast.value = null; }, 3500);
}

// ── Computed ──────────────────────────────────────────────────────────────────
const segments = computed(() => {
  const seen = new Set<string>();
  const out: string[] = [];
  for (const d of docs.value) {
    if (!seen.has(d.segment)) { seen.add(d.segment); out.push(d.segment); }
  }
  return out;
});

const activeSegmentDocs = computed(() =>
  docs.value.filter(d => d.segment === activeTab.value)
);

const orgHeader = computed(() => orgSlug.value || currentOrgSlug.value || "");

// ── Data loading ──────────────────────────────────────────────────────────────
async function loadOrg() {
  if (!orgSlug.value) return;
  try {
    const res = await api<{ data: OrgProfile }>("GET", `/api/v1/organizations/${orgSlug.value}`, { org: orgHeader.value });
    org.value = res.data;
  } catch (e: any) {
    // try listing and finding by slug
    try {
      const r2 = await api<{ data: OrgProfile[] }>("GET", "/api/v1/organizations");
      org.value = r2.data.find(o => o.slug === orgSlug.value) ?? null;
    } catch { error.value = "Could not load organisation."; }
  }
}

async function loadDocs() {
  docLoading.value = true;
  try {
    const res = await api<{ data: ComplianceDoc[]; summary: Summary }>(
      "GET", "/api/v1/org/compliance", { org: orgSlug.value }
    );
    docs.value    = res.data;
    summary.value = res.summary;
  } catch (e: any) {
    error.value = e.message;
  } finally {
    docLoading.value = false;
  }
}

onMounted(async () => {
  loading.value = true;
  await loadOrg();
  await loadDocs();
  loading.value = false;
});

watch(orgSlug, async () => {
  error.value = ""; org.value = null; docs.value = []; summary.value = null;
  loading.value = true;
  await loadOrg(); await loadDocs();
  loading.value = false;
});

// ── Status helpers ────────────────────────────────────────────────────────────
function statusClass(status: string): string {
  if (status === "Valid")               return "badge-valid";
  if (status === "Not Uploaded")        return "badge-missing";
  if (status === "Expired")             return "badge-expired";
  if (status === "Under Review")        return "badge-review";
  if (status === "Pending Renewal")     return "badge-pending";
  if (status.startsWith("Expiring"))    return "badge-expiring";
  return "badge-missing";
}

function statusIcon(status: string): string {
  if (status === "Valid")            return "✓";
  if (status === "Not Uploaded")     return "—";
  if (status === "Expired")          return "✗";
  if (status === "Under Review")     return "⟳";
  if (status === "Pending Renewal")  return "⏳";
  if (status.startsWith("Expiring")) return "⚠";
  return "?";
}

function fmtDate(d: string | null): string {
  if (!d) return "—";
  return new Date(d).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" });
}

function fmtAuditAction(a: string): string {
  const map: Record<string,string> = {
    updated: "Details updated", guide_updated: "Renewal guide updated",
    file_uploaded: "File uploaded", file_deleted: "File removed",
    reminder_sent: "Reminder sent",
  };
  return map[a] ?? a;
}

// ── Edit ──────────────────────────────────────────────────────────────────────
function openEdit(doc: ComplianceDoc) {
  editing.value  = doc;
  editForm.value = {
    issue_date: doc.issue_date ?? "", expiry_date: doc.expiry_date ?? "",
    anniversary_date: doc.anniversary_date ?? "", notes: doc.notes ?? "",
    owner_name: doc.owner_name ?? "", owner_email: doc.owner_email ?? "",
    status_override: doc.status_override ?? "",
  };
  saveErr.value = ""; saveOk.value = "";
}

async function saveEdit() {
  if (!editing.value) return;
  saving.value = true; saveErr.value = "";
  try {
    const res = await api<ComplianceDoc>(
      "PUT", `/api/v1/org/compliance/${editing.value.document_key}`,
      { org: orgSlug.value, body: editForm.value }
    );
    patchDoc(res);
    showToast("Details saved successfully.");
    editing.value  = null;
  } catch (e: any) { showToast(e instanceof Error ? e.message : "Failed to save.", "err"); }
  finally { saving.value = false; }
}

// ── Guide ─────────────────────────────────────────────────────────────────────
function openGuide(doc: ComplianceDoc, editable = false) {
  guideDoc.value  = doc;
  guideText.value = doc.renewal_guide ?? "";
  guideEdit.value = editable;
}

async function saveGuide() {
  if (!guideDoc.value) return;
  guideSaving.value = true;
  try {
    const res = await api<ComplianceDoc>(
      "PUT", `/api/v1/org/compliance/${guideDoc.value.document_key}/guide`,
      { org: orgSlug.value, body: { renewal_guide: guideText.value } }
    );
    patchDoc(res);
    showToast("Renewal guide saved.");
    guideEdit.value = false;
  } catch (e: any) { showToast(e instanceof Error ? e.message : "Failed to save guide.", "err"); }
  finally { guideSaving.value = false; }
}

// ── Reminders ─────────────────────────────────────────────────────────────────
function openReminder(doc: ComplianceDoc) {
  reminderDoc.value  = doc;
  reminderForm.value = {
    reminder_active:      doc.reminder_active,
    reminder_recipients:  doc.reminder_recipients ?? "",
    reminder_thresholds:  (doc.reminder_thresholds ?? []).join(", "),
  };
}

async function saveReminder() {
  if (!reminderDoc.value) return;
  reminderSaving.value = true;
  try {
    const thresholds = reminderForm.value.reminder_thresholds
      .split(",").map(s => parseInt(s.trim(), 10)).filter(n => !isNaN(n) && n > 0);
    const res = await api<ComplianceDoc>(
      "PUT", `/api/v1/org/compliance/${reminderDoc.value.document_key}/reminder`,
      { org: orgSlug.value, body: {
        reminder_active: reminderForm.value.reminder_active,
        reminder_recipients: reminderForm.value.reminder_recipients,
        reminder_thresholds: thresholds,
      }}
    );
    patchDoc(res);
    showToast("Reminder settings saved.");
    reminderDoc.value = null;
  } catch (e: any) { showToast(e instanceof Error ? e.message : "Failed to save reminders.", "err"); }
  finally { reminderSaving.value = false; }
}

// ── File upload ───────────────────────────────────────────────────────────────
function triggerUpload(key: string) {
  uploadingKey.value = key;
  fileInput.value?.click();
}

async function onFileSelected(ev: Event) {
  const file = (ev.target as HTMLInputElement).files?.[0];
  if (!file || !uploadingKey.value) return;

  const formData = new FormData();
  formData.append("file", file);

  const token = (await import("../stores/auth")).getToken();
  const res   = await fetch(
    `${(import.meta.env.VITE_LARAVEL_API_URL ?? "").replace(/\/$/,"")}/api/v1/org/compliance/${uploadingKey.value}/upload`,
    { method: "POST", headers: { Authorization: `Bearer ${token}`, "X-Organization": orgSlug.value } , body: formData }
  );
  if (res.ok) {
    const data: ComplianceDoc = await res.json();
    patchDoc(data);
    showToast("File uploaded successfully.");
  } else {
    showToast("Upload failed. Please try again.", "err");
  }
  (ev.target as HTMLInputElement).value = "";
  uploadingKey.value = null;
}

async function deleteFile(doc: ComplianceDoc) {
  if (!confirm(`Remove the uploaded file for "${doc.document_label}"?`)) return;
  try {
    const res = await api<ComplianceDoc>(
      "DELETE", `/api/v1/org/compliance/${doc.document_key}/file`,
      { org: orgSlug.value }
    );
    patchDoc(res);
    showToast("File removed.");
  } catch (e: any) { showToast(e instanceof Error ? e.message : "Failed to remove file.", "err"); }
}

// ── Utilities ─────────────────────────────────────────────────────────────────
function patchDoc(updated: ComplianceDoc) {
  const idx = docs.value.findIndex(d => d.id === updated.id);
  if (idx !== -1) docs.value[idx] = updated;
  else docs.value.push(updated);
  // Refresh summary
  loadDocs();
}
</script>

<template>
  <div class="view">
    <!-- Hidden file input -->
    <input ref="fileInput" type="file" style="display:none" accept=".pdf,.png,.jpg,.jpeg,.webp,.doc,.docx" @change="onFileSelected" />

    <!-- Header -->
    <div class="view-header">
      <div>
        <h2>{{ org?.name ?? "Organisation Profile" }}</h2>
        <p class="op-sub" v-if="org">{{ org.legal_name ?? org.name }} &bull; {{ org.industry ?? "—" }} &bull; {{ org.country }}</p>
      </div>
      <div style="display:flex;gap:10px;align-items:center">
        <router-link :to="{ name: 'org' }" class="btn btn-secondary btn-sm">← All Organisations</router-link>
      </div>
    </div>

    <p v-if="loading" class="status">Loading…</p>
    <p v-if="error"   class="error">{{ error }}</p>

    <!-- Org info card -->
    <div v-if="org" class="card op-info-card" :style="{ borderTop: `4px solid ${org.primary_color || '#004C97'}` }">
      <div class="op-info-inner">
        <div class="op-logo-wrap">
          <img v-if="org.logo_url" :src="org.logo_url" alt="Logo" class="op-logo" />
          <div v-else class="op-logo-initials" :style="{ background: org.primary_color || '#004C97' }">
            {{ (org.name || '').split(' ').slice(0,2).map((w:string)=>w[0]).join('').toUpperCase() }}
          </div>
        </div>
        <div class="op-info-fields">
          <div class="op-field"><span class="op-label">Email</span><span>{{ org.primary_email || '—' }}</span></div>
          <div class="op-field"><span class="op-label">Phone</span><span>{{ org.primary_phone || '—' }}</span></div>
          <div class="op-field"><span class="op-label">Currency</span><span>{{ org.currency }}</span></div>
          <div class="op-field"><span class="op-label">Headcount</span><span>{{ org.headcount ?? '—' }}</span></div>
          <div class="op-field"><span class="op-label">Status</span>
            <span :class="org.is_active ? 'badge-valid' : 'badge-expired'" class="badge">{{ org.is_active ? 'Active' : 'Inactive' }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Compliance summary dashboard -->
    <div v-if="summary" class="op-summary-row">
      <div class="op-stat op-stat-total">  <span class="op-stat-n">{{ summary.total }}</span>         <span>Total Documents</span></div>
      <div class="op-stat op-stat-valid">  <span class="op-stat-n">{{ summary.valid }}</span>         <span>Valid</span></div>
      <div class="op-stat op-stat-exp">    <span class="op-stat-n">{{ summary.expiring }}</span>      <span>Expiring Soon</span></div>
      <div class="op-stat op-stat-danger"> <span class="op-stat-n">{{ summary.expired }}</span>       <span>Expired</span></div>
      <div class="op-stat op-stat-miss">   <span class="op-stat-n">{{ summary.not_uploaded }}</span>  <span>Not Uploaded</span></div>
      <div class="op-stat op-stat-review"> <span class="op-stat-n">{{ summary.under_review }}</span>  <span>Under Review</span></div>
    </div>

    <!-- Document tabs -->
    <div v-if="!loading" class="tabs op-tabs" style="margin-bottom:20px">
      <button v-for="seg in segments" :key="seg"
        :class="['tab', { active: activeTab === seg }]"
        @click="activeTab = seg">
        {{ seg }}
      </button>
    </div>

    <!-- Document table -->
    <div v-if="!loading && activeSegmentDocs.length > 0" class="card" style="padding:0;overflow:hidden">
      <table class="op-table">
        <thead>
          <tr>
            <th style="width:30%">Document</th>
            <th>Status</th>
            <th>Issued</th>
            <th>Expires</th>
            <th>Owner</th>
            <th>File</th>
            <th style="width:120px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="doc in activeSegmentDocs" :key="doc.id" :class="'op-row-' + doc.status.toLowerCase().replace(/[^a-z]/g,'-')">
            <td class="op-doc-label">
              <span>{{ doc.document_label }}</span>
              <span v-if="doc.is_perpetual" class="op-perp-tag">No expiry</span>
            </td>
            <td>
              <span :class="['badge', statusClass(doc.status)]">
                {{ statusIcon(doc.status) }} {{ doc.status }}
              </span>
              <div v-if="doc.days_until_expiry !== null && doc.days_until_expiry > 0" class="op-days-left">
                {{ doc.days_until_expiry }} days left
              </div>
            </td>
            <td class="op-date">{{ fmtDate(doc.issue_date) }}</td>
            <td class="op-date">{{ doc.is_perpetual ? 'Perpetual' : fmtDate(doc.expiry_date) }}</td>
            <td class="op-owner">
              <span v-if="doc.owner_name">{{ doc.owner_name }}</span>
              <span v-else class="op-muted">—</span>
            </td>
            <td class="op-file-cell">
              <a v-if="doc.file_url" :href="doc.file_url" target="_blank" class="op-file-link" :title="doc.file_name ?? 'View file'">
                📄 {{ doc.file_name ? doc.file_name.substring(0,20) + (doc.file_name.length > 20 ? '…' : '') : 'View' }}
              </a>
              <span v-else class="op-muted">—</span>
            </td>
            <td class="op-actions">
              <button class="op-btn" title="Edit details" @click="openEdit(doc)">✏</button>
              <button class="op-btn" :title="doc.file_url ? 'Replace file' : 'Upload file'" @click="triggerUpload(doc.document_key)">⬆</button>
              <button v-if="doc.file_url" class="op-btn op-btn-danger" title="Remove file" @click="deleteFile(doc)">✕</button>
              <button class="op-btn op-btn-info" title="Renewal guide" @click="openGuide(doc)">ℹ</button>
              <button class="op-btn" title="Set reminders" @click="openReminder(doc)">🔔</button>
              <button class="op-btn op-btn-audit" title="Audit trail" @click="auditDoc = doc">⏱</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- ── Edit modal ──────────────────────────────────────────────────────── -->
    <Teleport to="body">
    <div v-if="editing" class="op-overlay" @click.self="editing = null">
      <div class="op-modal">
        <div class="op-modal-header">
          <h3 class="op-modal-title">✏ Edit — {{ editing.document_label }}</h3>
          <button class="op-modal-x" @click="editing = null" title="Close">✕</button>
        </div>
        <div class="op-form-grid">
          <label>Issue Date<input type="date" v-model="editForm.issue_date" /></label>
          <label>Expiry Date<input type="date" v-model="editForm.expiry_date" :disabled="editing.is_perpetual" /></label>
          <label>Anniversary / Review Date<input type="date" v-model="editForm.anniversary_date" /></label>
          <label>Owner Name<input type="text" v-model="editForm.owner_name" placeholder="e.g. Jane Mwangi" /></label>
          <label>Owner Email<input type="email" v-model="editForm.owner_email" placeholder="jane@example.com" /></label>
          <label>Status Override
            <select v-model="editForm.status_override">
              <option value="">— Auto-calculated —</option>
              <option>Under Review</option>
              <option>Pending Renewal</option>
            </select>
          </label>
        </div>
        <label class="op-full-label">Notes / Audit Note
          <textarea v-model="editForm.notes" rows="3" placeholder="Internal notes…"></textarea>
        </label>
        <div class="op-modal-footer">
          <button class="btn btn-ghost btn-sm" @click="editing = null">Cancel</button>
          <button class="btn btn-primary btn-sm" :disabled="saving" @click="saveEdit">{{ saving ? "Saving…" : "Save" }}</button>
        </div>
      </div>
    </div>
    </Teleport>

    <!-- ── Guide modal ─────────────────────────────────────────────────────── -->
    <Teleport to="body">
    <div v-if="guideDoc" class="op-overlay" @click.self="guideDoc = null; guideEdit = false">
      <div class="op-modal op-modal-wide">
        <div class="op-modal-header">
          <h3 class="op-modal-title">📋 Renewal Guide — {{ guideDoc.document_label }}</h3>
          <div style="display:flex;gap:8px;align-items:center">
            <button v-if="!guideEdit" class="btn btn-secondary btn-sm" @click="guideEdit = true">Edit Guide</button>
            <button class="op-modal-x" @click="guideDoc = null; guideEdit = false" title="Close">✕</button>
          </div>
        </div>
        <div v-if="!guideEdit" class="op-guide-body">
          <pre class="op-guide-pre">{{ guideDoc.renewal_guide || 'No guide available.' }}</pre>
        </div>
        <div v-else>
          <textarea v-model="guideText" rows="12" class="op-guide-editor" placeholder="Enter renewal instructions, links, contacts…"></textarea>
        </div>
        <div class="op-modal-footer">
          <button class="btn btn-ghost btn-sm" @click="guideDoc = null; guideEdit = false">Close</button>
          <button v-if="guideEdit" class="btn btn-primary btn-sm" :disabled="guideSaving" @click="saveGuide">
            {{ guideSaving ? "Saving…" : "Save Guide" }}
          </button>
        </div>
      </div>
    </div>
    </Teleport>

    <!-- ── Reminder modal ──────────────────────────────────────────────────── -->
    <Teleport to="body">
    <div v-if="reminderDoc" class="op-overlay" @click.self="reminderDoc = null">
      <div class="op-modal">
        <div class="op-modal-header">
          <h3 class="op-modal-title">🔔 Reminders — {{ reminderDoc.document_label }}</h3>
          <button class="op-modal-x" @click="reminderDoc = null" title="Close">✕</button>
        </div>
        <label class="op-toggle-row">
          <input type="checkbox" v-model="reminderForm.reminder_active" />
          <span>Enable email reminders</span>
        </label>
        <label class="op-full-label">
          Recipient emails (comma-separated)
          <input type="text" v-model="reminderForm.reminder_recipients"
            placeholder="hr@company.com, cfo@company.com" />
        </label>
        <label class="op-full-label">
          Alert thresholds — days before expiry (comma-separated)
          <input type="text" v-model="reminderForm.reminder_thresholds"
            placeholder="180, 90, 30, 23, 16, 9, 6, 5, 4, 3, 2, 1" />
          <small class="op-hint">Default: 180, 90, 30 (monthly), 23, 16, 9 (weekly), 6–1 (daily)</small>
        </label>
        <p v-if="reminderDoc.last_reminder_sent_at" class="op-hint">
          Last reminder sent: {{ fmtDate(reminderDoc.last_reminder_sent_at) }}
        </p>
        <div class="op-modal-footer">
          <button class="btn btn-ghost btn-sm" @click="reminderDoc = null">Cancel</button>
          <button class="btn btn-primary btn-sm" :disabled="reminderSaving" @click="saveReminder">
            {{ reminderSaving ? "Saving…" : "Save Reminders" }}
          </button>
        </div>
      </div>
    </div>
    </Teleport>

    <!-- ── Audit trail modal ───────────────────────────────────────────────── -->
    <Teleport to="body">
    <div v-if="auditDoc" class="op-overlay" @click.self="auditDoc = null">
      <div class="op-modal op-modal-wide">
        <div class="op-modal-header">
          <h3 class="op-modal-title">⏱ Audit Trail — {{ auditDoc.document_label }}</h3>
          <button class="op-modal-x" @click="auditDoc = null" title="Close">✕</button>
        </div>
        <div v-if="auditDoc.audit_trail?.length" class="op-audit-list">
          <div v-for="(entry, i) in [...auditDoc.audit_trail].reverse()" :key="i" class="op-audit-entry">
            <span class="op-audit-action">{{ fmtAuditAction(entry.action) }}</span>
            <span class="op-audit-by">by {{ entry.by }}</span>
            <span class="op-audit-time">{{ new Date(entry.at).toLocaleString('en-GB') }}</span>
            <p v-if="entry.note" class="op-audit-note">{{ entry.note }}</p>
          </div>
        </div>
        <p v-else class="op-muted" style="padding:16px">No audit entries yet.</p>
        <div class="op-modal-footer">
          <button class="btn btn-ghost btn-sm" @click="auditDoc = null">Close</button>
        </div>
      </div>
    </div>
    </Teleport>

    <!-- ── Toast notification ─────────────────────────────────────────────── -->
    <Teleport to="body">
      <transition name="op-toast-fade">
        <div v-if="toast" class="op-toast" :class="toast.type === 'ok' ? 'op-toast-ok' : 'op-toast-err'">
          <span class="op-toast-icon">{{ toast.type === 'ok' ? '✓' : '✗' }}</span>
          {{ toast.msg }}
        </div>
      </transition>
    </Teleport>
  </div>
</template>

<style scoped>
/* ── Info card ─────────────────────────────────────────────────────────────── */
.op-sub { color: var(--text-muted); font-size: 13px; margin-top: 2px; }
.op-info-card { margin-bottom: 20px; }
.op-info-inner { display: flex; gap: 20px; align-items: flex-start; }
.op-logo-wrap { flex-shrink: 0; }
.op-logo { height: 60px; width: auto; max-width: 140px; object-fit: contain; border-radius: 8px; border: 1px solid var(--border); }
.op-logo-initials { width: 60px; height: 60px; border-radius: 12px; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 800; font-size: 22px; }
.op-info-fields { display: flex; flex-wrap: wrap; gap: 12px 28px; }
.op-field { display: flex; flex-direction: column; gap: 1px; }
.op-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; color: var(--color-text-muted); }

/* ── Summary row ───────────────────────────────────────────────────────────── */
.op-summary-row { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 24px; }
.op-stat { flex: 1 1 120px; padding: 14px 18px; border-radius: 10px; display: flex; flex-direction: column; gap: 4px; border: 1px solid var(--color-border); background: var(--color-surface); }
.op-stat-n { font-size: 28px; font-weight: 800; line-height: 1; }
.op-stat span:last-child { font-size: 13px; color: var(--color-text-muted); }
.op-stat-total  .op-stat-n { color: var(--color-text); }
.op-stat-valid  .op-stat-n { color: #16a34a; }
.op-stat-exp    .op-stat-n { color: #d97706; }
.op-stat-danger .op-stat-n { color: #dc2626; }
.op-stat-miss   .op-stat-n { color: var(--text-muted); }
.op-stat-review .op-stat-n { color: #7c3aed; }

/* ── Tabs ──────────────────────────────────────────────────────────────────── */
.op-tabs { flex-wrap: wrap; gap: 4px; }

/* ── Document table ────────────────────────────────────────────────────────── */
.op-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.op-table th { padding: 10px 14px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: var(--color-text-muted); background: var(--color-bg); border-bottom: 1px solid var(--color-border); }
.op-table td { padding: 10px 14px; border-bottom: 1px solid var(--color-border); vertical-align: middle; }
.op-table tr:last-child td { border-bottom: none; }
.op-table tr:hover td { background: color-mix(in srgb, var(--color-brand-base) 4%, transparent); }
.op-doc-label { display: flex; flex-direction: column; gap: 3px; }
.op-perp-tag { font-size: 11px; color: var(--color-text-muted); border: 1px solid var(--color-border); border-radius: 10px; padding: 1px 6px; width: fit-content; }
.op-date { white-space: nowrap; color: var(--color-text-muted); font-size: 12px; }
.op-owner { font-size: 12px; color: var(--color-text-muted); }
.op-muted { color: var(--color-text-muted); }
.op-file-link { font-size: 12px; color: var(--color-brand-base); text-decoration: none; }
.op-file-link:hover { text-decoration: underline; }
.op-days-left { font-size: 11px; color: var(--color-text-muted); margin-top: 2px; }

/* Row urgency tints */
.op-row-expired td { background: rgba(220,38,38,.05); }
.op-row-expiring-this-week- td { background: rgba(217,119,6,.08); }

/* ── Badges ────────────────────────────────────────────────────────────────── */
.badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; white-space: nowrap; }
.badge-valid     { background: #dcfce7; color: #15803d; }
.badge-missing   { background: #f3f4f6; color: #6b7280; }
.badge-expired   { background: #fee2e2; color: #b91c1c; }
.badge-expiring  { background: #fef3c7; color: #92400e; }
.badge-review    { background: #ede9fe; color: #6d28d9; }
.badge-pending   { background: #e0f2fe; color: #075985; }

/* ── Action buttons ────────────────────────────────────────────────────────── */
.op-actions { white-space: nowrap; display: flex; gap: 4px; }
.op-btn { background: var(--color-bg); border: 1px solid var(--color-border); border-radius: 6px; width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; font-size: 14px; transition: background .15s, border-color .15s; }
.op-btn:hover { background: var(--color-neutral-200); border-color: var(--color-neutral-300); }
.op-btn-danger:hover { background: #fee2e2; border-color: #fca5a5; }
.op-btn-info { color: var(--color-brand-base); }
.op-btn-audit { color: #7c3aed; }

/* ── Modals ────────────────────────────────────────────────────────────────── */
.op-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.55); z-index: 9000; display: flex; align-items: center; justify-content: center; padding: 20px; backdrop-filter: blur(2px); }
.op-modal { background: #ffffff; border-radius: 14px; padding: 28px; width: 100%; max-width: 520px; max-height: 90vh; overflow-y: auto; box-shadow: 0 24px 80px rgba(0,0,0,.25), 0 0 0 1px rgba(0,0,0,.06); display: flex; flex-direction: column; gap: 16px; border: 1px solid var(--color-border); }
.op-modal-wide { max-width: 700px; }
.op-modal-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding-bottom: 14px; border-bottom: 3px solid var(--color-brand-base); }
.op-modal-title { font-size: 17px; font-weight: 700; margin: 0; color: var(--color-text); }
.op-modal-x { background: none; border: none; font-size: 18px; cursor: pointer; color: var(--color-text-muted); padding: 2px 6px; line-height: 1; border-radius: 6px; flex-shrink: 0; transition: background .15s, color .15s; }
.op-modal-x:hover { background: var(--color-error-light); color: var(--color-error); }
.op-modal-footer { display: flex; justify-content: flex-end; gap: 10px; margin-top: 4px; padding-top: 14px; border-top: 1px solid var(--color-border); }
.op-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.op-form-grid label { display: flex; flex-direction: column; gap: 4px; font-size: 13px; font-weight: 600; color: var(--color-text-muted); }
.op-form-grid input, .op-form-grid select { padding: 8px 10px; border: 1px solid var(--color-border); border-radius: 8px; font-size: 14px; background: var(--color-bg); color: var(--color-text); }
.op-form-grid input:focus, .op-form-grid select:focus { outline: 2px solid var(--color-brand-base); outline-offset: -1px; }
.op-full-label { display: flex; flex-direction: column; gap: 6px; font-size: 13px; font-weight: 600; color: var(--color-text-muted); }
.op-full-label input, .op-full-label textarea { padding: 8px 10px; border: 1px solid var(--color-border); border-radius: 8px; font-size: 14px; background: var(--color-bg); color: var(--color-text); resize: vertical; font-family: inherit; }
.op-full-label input:focus, .op-full-label textarea:focus { outline: 2px solid var(--color-brand-base); outline-offset: -1px; }
.op-toggle-row { display: flex; align-items: center; gap: 10px; font-size: 14px; cursor: pointer; color: var(--color-text); }
.op-toggle-row input[type=checkbox] { width: 16px; height: 16px; cursor: pointer; accent-color: var(--color-brand-base); }
.op-hint { font-size: 12px; color: var(--color-text-muted); }
.op-guide-body { background: var(--color-bg); border: 1px solid var(--color-border); border-radius: 8px; padding: 16px; }
.op-guide-pre { white-space: pre-wrap; font-family: inherit; font-size: 13px; line-height: 1.7; margin: 0; color: var(--color-text); }
.op-guide-editor { width: 100%; padding: 12px; border: 1px solid var(--color-border); border-radius: 8px; font-size: 13px; font-family: inherit; background: var(--color-bg); color: var(--color-text); line-height: 1.6; box-sizing: border-box; }
.op-guide-editor:focus { outline: 2px solid var(--color-brand-base); outline-offset: -1px; }
.op-audit-list { display: flex; flex-direction: column; gap: 12px; max-height: 380px; overflow-y: auto; }
.op-audit-entry { padding: 10px 12px; border-radius: 8px; background: var(--color-bg); border: 1px solid var(--color-border); }
.op-audit-action { font-weight: 700; font-size: 13px; color: var(--color-text); }
.op-audit-by { font-size: 12px; color: var(--color-text-muted); margin-left: 8px; }
.op-audit-time { font-size: 11px; color: var(--color-text-muted); float: right; }
.op-audit-note { font-size: 12px; color: var(--color-text-muted); margin: 4px 0 0; }

/* ── Toast ─────────────────────────────────────────────────────────────────── */
.op-toast { position: fixed; bottom: 28px; right: 28px; z-index: 9999; display: flex; align-items: center; gap: 10px; padding: 13px 22px; border-radius: 10px; font-size: 14px; font-weight: 600; box-shadow: 0 8px 32px rgba(0,0,0,.22); pointer-events: none; min-width: 220px; }
.op-toast-ok  { background: var(--color-brand-base); color: #fff; }
.op-toast-err { background: var(--color-error); color: #fff; }
.op-toast-icon { font-size: 16px; font-weight: 800; }
.op-toast-fade-enter-active { animation: toast-slide-in .25s ease; }
.op-toast-fade-leave-active  { animation: toast-slide-in .2s ease reverse; }
@keyframes toast-slide-in { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }

@media (max-width: 700px) {
  .op-form-grid { grid-template-columns: 1fr; }
  .op-summary-row { gap: 8px; }
  .op-stat { flex: 1 1 100px; padding: 10px 12px; }
  .op-stat-n { font-size: 22px; }
  .op-table th:nth-child(3), .op-table td:nth-child(3),
  .op-table th:nth-child(4), .op-table td:nth-child(4),
  .op-table th:nth-child(5), .op-table td:nth-child(5) { display: none; }
}
</style>
