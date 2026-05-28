<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
import { callFrappeMethod, type FrappeAuthConfig } from "../lib/frappe-client";
import { authStore } from "../stores/auth";
import type {
  DepartmentRecord,
  BranchRecord,
  ComplianceDocumentRecord,
  ComplianceDocumentListData,
} from "../lib/types";
import CompanySelector from "../components/CompanySelector.vue";

function authConfig(): FrappeAuthConfig {
  return {
    baseUrl: authStore.baseUrl,
    apiKey: authStore.apiKey || undefined,
    apiSecret: authStore.apiSecret || undefined,
  };
}

const departments = ref<DepartmentRecord[]>([]);
const branches = ref<BranchRecord[]>([]);
const loading = ref(false);
const error = ref("");
const newDeptName = ref("");
const newDeptParent = ref("");
const creating = ref(false);
const createError = ref("");

type OrgTab = "structure" | "compliance";
const activeTab = ref<OrgTab>("structure");

const complianceDocs = ref<ComplianceDocumentRecord[]>([]);
const complianceLoading = ref(false);
const complianceError = ref("");

const infoDoc = ref<ComplianceDocumentRecord | null>(null);
const showInfoPopup = ref(false);
const editingGuide = ref(false);
const guideEditText = ref("");
const savingGuide = ref(false);
const guideError = ref("");

const uploadingKey = ref<string | null>(null);
const uploadFileInput = ref<HTMLInputElement | null>(null);
const uploadTargetKey = ref<string | null>(null);

const editingDoc = ref<ComplianceDocumentRecord | null>(null);
const showDateDrawer = ref(false);
const dateForm = ref({ issue_date: "", expiry_date: "", anniversary_date: "", notes: "" });
const savingDate = ref(false);
const dateError = ref("");

const editingReminderDoc = ref<ComplianceDocumentRecord | null>(null);
const showReminderDrawer = ref(false);
const reminderForm = ref({ reminder_days_before: 30, reminder_recipients: "", reminder_active: true });
const savingReminder = ref(false);
const reminderError = ref("");

const complianceSegments = computed<{ segment: string; docs: ComplianceDocumentRecord[] }[]>(() => {
  const map = new Map<string, ComplianceDocumentRecord[]>();
  for (const doc of complianceDocs.value) {
    const seg = doc.segment || "Other";
    if (!map.has(seg)) map.set(seg, []);
    map.get(seg)!.push(doc);
  }
  return Array.from(map.entries()).map(([segment, docs]) => ({ segment, docs }));
});

const complianceSummary = computed(() => {
  const total = complianceDocs.value.length;
  const uploaded = complianceDocs.value.filter((d) => d.file_url).length;
  const expiringSoon = complianceDocs.value.filter((d) => d.status === "Expiring Soon").length;
  const expired = complianceDocs.value.filter((d) => d.status === "Expired").length;
  return { total, uploaded, expiringSoon, expired };
});

async function load(): Promise<void> {
  loading.value = true;
  error.value = "";
  try {
    const [deptsResult, branchesResult] = await Promise.all([
      callFrappeMethod<{ items: DepartmentRecord[] }>(
        authConfig(),
        "bitwyse_hrms_backend.api.list_departments",
        { company: authStore.company ?? undefined },
      ),
      callFrappeMethod<{ items: BranchRecord[] }>(
        authConfig(),
        "bitwyse_hrms_backend.api.list_branches",
        { company: authStore.company ?? undefined },
      ),
    ]);
    departments.value = deptsResult.items ?? [];
    branches.value = branchesResult.items ?? [];
  } catch (err) {
    error.value = err instanceof Error ? err.message : "Failed to load org data.";
  } finally {
    loading.value = false;
  }
}

async function createDept(): Promise<void> {
  if (!newDeptName.value.trim()) return;
  creating.value = true;
  createError.value = "";
  try {
    await callFrappeMethod(
      authConfig(),
      "bitwyse_hrms_backend.api.create_department",
      {
        company: authStore.company ?? "",
        department_name: newDeptName.value.trim(),
        parent_department: newDeptParent.value.trim() || undefined,
      },
    );
    newDeptName.value = "";
    newDeptParent.value = "";
    await load();
  } catch (err) {
    createError.value = err instanceof Error ? err.message : "Failed to create department.";
  } finally {
    creating.value = false;
  }
}

async function loadCompliance(): Promise<void> {
  complianceLoading.value = true;
  complianceError.value = "";
  try {
    const result = await callFrappeMethod<ComplianceDocumentListData>(
      authConfig(),
      "bitwyse_hrms_backend.api.list_compliance_documents",
      { company: authStore.company ?? undefined },
    );
    complianceDocs.value = result.items ?? [];
  } catch (err) {
    complianceError.value = err instanceof Error ? err.message : "Failed to load compliance documents.";
  } finally {
    complianceLoading.value = false;
  }
}

function switchTab(tab: OrgTab): void {
  activeTab.value = tab;
  if (tab === "compliance" && complianceDocs.value.length === 0) {
    loadCompliance();
  }
}

function openInfo(doc: ComplianceDocumentRecord): void {
  infoDoc.value = doc;
  editingGuide.value = false;
  guideEditText.value = doc.renewal_guide ?? "";
  guideError.value = "";
  showInfoPopup.value = true;
}

function closeInfo(): void {
  showInfoPopup.value = false;
  infoDoc.value = null;
  editingGuide.value = false;
}

function startEditGuide(): void {
  guideEditText.value = infoDoc.value?.renewal_guide ?? "";
  editingGuide.value = true;
}

async function saveGuide(): Promise<void> {
  if (!infoDoc.value) return;
  savingGuide.value = true;
  guideError.value = "";
  try {
    await callFrappeMethod(
      authConfig(),
      "bitwyse_hrms_backend.api.save_compliance_guide",
      {
        company: authStore.company ?? "",
        document_key: infoDoc.value.document_key,
        renewal_guide: guideEditText.value,
      },
    );
    infoDoc.value.renewal_guide = guideEditText.value;
    const idx = complianceDocs.value.findIndex((d) => d.document_key === infoDoc.value!.document_key);
    if (idx !== -1) complianceDocs.value[idx].renewal_guide = guideEditText.value;
    editingGuide.value = false;
  } catch (err) {
    guideError.value = err instanceof Error ? err.message : "Failed to save guide.";
  } finally {
    savingGuide.value = false;
  }
}

function triggerUpload(doc: ComplianceDocumentRecord): void {
  uploadTargetKey.value = doc.document_key;
  uploadFileInput.value?.click();
}

async function onFileSelected(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0];
  if (!file || !uploadTargetKey.value) return;

  uploadingKey.value = uploadTargetKey.value;

  try {
    const formData = new FormData();
    formData.append("file", file);
    formData.append("is_private", "1");
    formData.append("folder", "Home/Compliance");

    const uploadUrl = `${authStore.baseUrl}/api/method/upload_file`;
    const headers: Record<string, string> = {};
    if (authStore.apiKey && authStore.apiSecret) {
      headers["Authorization"] = `token ${authStore.apiKey}:${authStore.apiSecret}`;
    }

    const uploadResponse = await fetch(uploadUrl, { method: "POST", headers, body: formData });
    if (!uploadResponse.ok) throw new Error(`Upload failed: ${uploadResponse.statusText}`);

    const uploadData = await uploadResponse.json();
    const fileUrl: string = uploadData.message?.file_url ?? uploadData.file_url ?? "";
    if (!fileUrl) throw new Error("Upload did not return a file URL.");

    await callFrappeMethod(
      authConfig(),
      "bitwyse_hrms_backend.api.save_compliance_document",
      {
        company: authStore.company ?? "",
        document_key: uploadTargetKey.value,
        file_url: fileUrl,
        file_name: file.name,
      },
    );

    await loadCompliance();
  } catch (err) {
    alert(err instanceof Error ? err.message : "Upload failed.");
  } finally {
    uploadingKey.value = null;
    uploadTargetKey.value = null;
    input.value = "";
  }
}

async function removeFile(doc: ComplianceDocumentRecord): Promise<void> {
  if (!confirm(`Remove the uploaded file for "${doc.document_label}"?`)) return;
  try {
    await callFrappeMethod(
      authConfig(),
      "bitwyse_hrms_backend.api.delete_compliance_file",
      { company: authStore.company ?? "", document_key: doc.document_key },
    );
    await loadCompliance();
  } catch (err) {
    alert(err instanceof Error ? err.message : "Failed to remove file.");
  }
}

function openDateDrawer(doc: ComplianceDocumentRecord): void {
  editingDoc.value = doc;
  dateForm.value = {
    issue_date: doc.issue_date ?? "",
    expiry_date: doc.expiry_date ?? "",
    anniversary_date: doc.anniversary_date ?? "",
    notes: doc.notes ?? "",
  };
  dateError.value = "";
  showDateDrawer.value = true;
}

function closeDateDrawer(): void {
  showDateDrawer.value = false;
  editingDoc.value = null;
}

async function saveDates(): Promise<void> {
  if (!editingDoc.value) return;
  savingDate.value = true;
  dateError.value = "";
  try {
    await callFrappeMethod(
      authConfig(),
      "bitwyse_hrms_backend.api.save_compliance_document",
      {
        company: authStore.company ?? "",
        document_key: editingDoc.value.document_key,
        issue_date: dateForm.value.issue_date || null,
        expiry_date: dateForm.value.expiry_date || null,
        anniversary_date: dateForm.value.anniversary_date || null,
        notes: dateForm.value.notes,
      },
    );
    closeDateDrawer();
    await loadCompliance();
  } catch (err) {
    dateError.value = err instanceof Error ? err.message : "Failed to save dates.";
  } finally {
    savingDate.value = false;
  }
}

function openReminderDrawer(doc: ComplianceDocumentRecord): void {
  editingReminderDoc.value = doc;
  reminderForm.value = {
    reminder_days_before: doc.reminder_days_before ?? 30,
    reminder_recipients: doc.reminder_recipients ?? "",
    reminder_active: doc.reminder_active === 1,
  };
  reminderError.value = "";
  showReminderDrawer.value = true;
}

function closeReminderDrawer(): void {
  showReminderDrawer.value = false;
  editingReminderDoc.value = null;
}

async function saveReminder(): Promise<void> {
  if (!editingReminderDoc.value) return;
  savingReminder.value = true;
  reminderError.value = "";
  try {
    await callFrappeMethod(
      authConfig(),
      "bitwyse_hrms_backend.api.save_compliance_reminder",
      {
        company: authStore.company ?? "",
        document_key: editingReminderDoc.value.document_key,
        reminder_days_before: reminderForm.value.reminder_days_before,
        reminder_recipients: reminderForm.value.reminder_recipients,
        reminder_active: reminderForm.value.reminder_active ? 1 : 0,
      },
    );
    closeReminderDrawer();
    await loadCompliance();
  } catch (err) {
    reminderError.value = err instanceof Error ? err.message : "Failed to save reminder settings.";
  } finally {
    savingReminder.value = false;
  }
}

onMounted(load);
</script>

<template>
  <div class="view">
    <div class="view-header">
      <h2>Organisation</h2>
      <div class="org-tab-switcher">
        <button class="org-tab-btn" :class="{ active: activeTab === 'structure' }" @click="switchTab('structure')">
          📋 Structure
        </button>
        <button class="org-tab-btn" :class="{ active: activeTab === 'compliance' }" @click="switchTab('compliance')">
          🛡️ Compliance
        </button>
      </div>
      <CompanySelector @change="load" />
    </div>

    <!-- STRUCTURE TAB -->
    <template v-if="activeTab === 'structure'">
      <div v-if="loading" class="org-loading">
        <span class="org-loading-spinner"></span>
        <span>Loading…</span>
      </div>
      <p v-if="error" class="error-banner">{{ error }}</p>

      <div class="org-grid">
        <section class="org-panel">
          <div class="org-panel-header">
            <div class="org-panel-title">
              <span class="org-panel-icon">🏢</span>
              <h3>Departments</h3>
            </div>
            <span class="org-count-badge">{{ departments.length }}</span>
          </div>
          <ul class="org-list" v-if="departments.length > 0">
            <li v-for="dept in departments" :key="dept.name" class="org-list-item">
              <div class="org-item-dot"></div>
              <div class="org-item-body">
                <span class="org-item-name">{{ dept.department_name }}</span>
                <span v-if="dept.parent_department && dept.parent_department !== 'All Departments'" class="org-item-parent">
                  ↳ {{ dept.parent_department }}
                </span>
              </div>
            </li>
          </ul>
          <div v-else class="org-empty">
            <div class="org-empty-icon">🏢</div>
            <p>No departments found.</p>
            <p class="org-empty-hint">Departments will appear here once added.</p>
          </div>
          <div class="org-add-section">
            <h4 class="org-add-title">Add Department</h4>
            <form class="org-add-form" @submit.prevent="createDept">
              <input v-model="newDeptName" type="text" placeholder="Department name" required />
              <input v-model="newDeptParent" type="text" placeholder="Parent department (optional)" />
              <p v-if="createError" class="error">{{ createError }}</p>
              <button type="submit" class="primary small" :disabled="creating">
                {{ creating ? "Creating…" : "Add" }}
              </button>
            </form>
          </div>
        </section>

        <section class="org-panel">
          <div class="org-panel-header">
            <div class="org-panel-title">
              <span class="org-panel-icon">📍</span>
              <h3>Branches</h3>
            </div>
            <span class="org-count-badge">{{ branches.length }}</span>
          </div>
          <ul class="org-list" v-if="branches.length > 0">
            <li v-for="branch in branches" :key="branch.name" class="org-list-item">
              <div class="org-item-dot"></div>
              <div class="org-item-body">
                <span class="org-item-name">{{ branch.branch }}</span>
              </div>
            </li>
          </ul>
          <div v-else class="org-empty">
            <div class="org-empty-icon">📭</div>
            <p>No branches found.</p>
            <p class="org-empty-hint">Branches will appear here once added.</p>
          </div>
        </section>
      </div>
    </template>

    <!-- COMPLIANCE TAB -->
    <template v-else>
      <input
        ref="uploadFileInput"
        type="file"
        accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
        style="display: none"
        @change="onFileSelected"
      />

      <div v-if="complianceLoading" class="org-loading">
        <span class="org-loading-spinner"></span>
        <span>Loading compliance documents…</span>
      </div>
      <p v-if="complianceError" class="error-banner">{{ complianceError }}</p>

      <div v-if="!complianceLoading && complianceDocs.length > 0" class="comp-summary">
        <div class="comp-stat-card">
          <span class="comp-stat-num">{{ complianceSummary.total }}</span>
          <span class="comp-stat-label">Total Documents</span>
        </div>
        <div class="comp-stat-card comp-stat-ok">
          <span class="comp-stat-num">{{ complianceSummary.uploaded }}</span>
          <span class="comp-stat-label">Uploaded</span>
        </div>
        <div class="comp-stat-card comp-stat-warn">
          <span class="comp-stat-num">{{ complianceSummary.expiringSoon }}</span>
          <span class="comp-stat-label">Expiring Soon</span>
        </div>
        <div class="comp-stat-card comp-stat-danger">
          <span class="comp-stat-num">{{ complianceSummary.expired }}</span>
          <span class="comp-stat-label">Expired</span>
        </div>
      </div>

      <div v-for="seg in complianceSegments" :key="seg.segment" class="comp-segment">
        <div class="comp-segment-header">
          <span class="comp-segment-title">{{ seg.segment }}</span>
          <span class="comp-segment-count">{{ seg.docs.length }} document{{ seg.docs.length !== 1 ? 's' : '' }}</span>
        </div>

        <div class="comp-doc-grid">
          <div
            v-for="doc in seg.docs"
            :key="doc.document_key"
            class="comp-doc-card"
            :class="`comp-doc-card--${doc.status.toLowerCase().replace(' ', '-')}`"
          >
            <div class="comp-doc-card-header">
              <div class="comp-doc-title-row">
                <span class="comp-doc-label">{{ doc.document_label }}</span>
                <button class="comp-info-btn" title="View renewal guide" @click="openInfo(doc)">ⓘ</button>
              </div>
              <span class="comp-status-badge" :class="`comp-status--${doc.status.toLowerCase().replace(' ', '-')}`">
                {{ doc.status }}
              </span>
            </div>

            <div class="comp-doc-dates">
              <div class="comp-date-row" v-if="doc.issue_date">
                <span class="comp-date-label">Issued</span>
                <span class="comp-date-val">{{ doc.issue_date }}</span>
              </div>
              <div class="comp-date-row" v-if="!doc.is_perpetual && doc.expiry_date">
                <span class="comp-date-label">Expires</span>
                <span class="comp-date-val" :class="{ 'comp-date-urgent': doc.status === 'Expired' || doc.status === 'Expiring Soon' }">
                  {{ doc.expiry_date }}
                </span>
              </div>
              <div class="comp-date-row" v-if="doc.is_perpetual">
                <span class="comp-date-label">Validity</span>
                <span class="comp-date-val">Perpetual</span>
              </div>
              <div class="comp-date-row" v-if="doc.anniversary_date">
                <span class="comp-date-label">Review</span>
                <span class="comp-date-val">{{ doc.anniversary_date }}</span>
              </div>
            </div>

            <div v-if="doc.file_url" class="comp-file-row">
              <a :href="doc.file_url" target="_blank" class="comp-file-link" rel="noopener noreferrer">
                📄 {{ doc.file_name || 'View Document' }}
              </a>
              <button class="comp-remove-btn" title="Remove file" @click="removeFile(doc)">✕</button>
            </div>

            <div class="comp-doc-actions">
              <button
                class="comp-action-btn comp-action-upload"
                :disabled="uploadingKey === doc.document_key"
                @click="triggerUpload(doc)"
              >
                {{ uploadingKey === doc.document_key ? 'Uploading…' : doc.file_url ? '↑ Replace' : '↑ Upload' }}
              </button>
              <button class="comp-action-btn comp-action-dates" @click="openDateDrawer(doc)">📅 Dates</button>
              <button class="comp-action-btn comp-action-reminder" title="Reminder settings" @click="openReminderDrawer(doc)">🔔</button>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- INFO POPUP -->
    <Teleport to="body">
      <div v-if="showInfoPopup" class="comp-overlay" @click.self="closeInfo">
        <div class="comp-popup" role="dialog" aria-modal="true">
          <div class="comp-popup-header">
            <div>
              <h3 class="comp-popup-title">{{ infoDoc?.document_label }}</h3>
              <span class="comp-popup-segment">{{ infoDoc?.segment }}</span>
            </div>
            <button class="comp-popup-close" @click="closeInfo">✕</button>
          </div>
          <div class="comp-popup-body">
            <template v-if="!editingGuide">
              <p v-if="infoDoc?.renewal_guide" class="comp-guide-text" style="white-space: pre-wrap">{{ infoDoc.renewal_guide }}</p>
              <p v-else class="comp-guide-empty">No renewal guide added yet.</p>
              <button class="comp-guide-edit-btn" @click="startEditGuide">✏️ Edit Guide</button>
            </template>
            <template v-else>
              <label class="comp-guide-label">Renewal Procedure Guide</label>
              <textarea v-model="guideEditText" class="comp-guide-textarea" rows="12" placeholder="Enter step-by-step renewal procedure…"></textarea>
              <p v-if="guideError" class="error">{{ guideError }}</p>
              <div class="comp-guide-actions">
                <button class="primary small" :disabled="savingGuide" @click="saveGuide">{{ savingGuide ? 'Saving…' : 'Save Guide' }}</button>
                <button class="secondary small" @click="editingGuide = false">Cancel</button>
              </div>
            </template>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- DATE DRAWER -->
    <Teleport to="body">
      <div v-if="showDateDrawer" class="comp-overlay" @click.self="closeDateDrawer">
        <div class="comp-drawer" role="dialog" aria-modal="true">
          <div class="comp-drawer-header">
            <h3>Document Dates</h3>
            <button class="comp-popup-close" @click="closeDateDrawer">✕</button>
          </div>
          <div class="comp-drawer-body">
            <p class="comp-drawer-doc-name">{{ editingDoc?.document_label }}</p>
            <label class="comp-field-label">Issue Date</label>
            <input type="date" v-model="dateForm.issue_date" class="comp-field-input" />
            <label class="comp-field-label">Expiry Date <small v-if="editingDoc?.is_perpetual">(not applicable — perpetual)</small></label>
            <input type="date" v-model="dateForm.expiry_date" class="comp-field-input" :disabled="editingDoc?.is_perpetual === 1" />
            <label class="comp-field-label">Anniversary / Review Date</label>
            <input type="date" v-model="dateForm.anniversary_date" class="comp-field-input" />
            <label class="comp-field-label">Notes</label>
            <textarea v-model="dateForm.notes" class="comp-field-input" rows="3" placeholder="Additional notes…"></textarea>
            <p v-if="dateError" class="error">{{ dateError }}</p>
            <div class="comp-drawer-actions">
              <button class="primary small" :disabled="savingDate" @click="saveDates">{{ savingDate ? 'Saving…' : 'Save' }}</button>
              <button class="secondary small" @click="closeDateDrawer">Cancel</button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- REMINDER DRAWER -->
    <Teleport to="body">
      <div v-if="showReminderDrawer" class="comp-overlay" @click.self="closeReminderDrawer">
        <div class="comp-drawer" role="dialog" aria-modal="true">
          <div class="comp-drawer-header">
            <h3>🔔 Expiry Reminders</h3>
            <button class="comp-popup-close" @click="closeReminderDrawer">✕</button>
          </div>
          <div class="comp-drawer-body">
            <p class="comp-drawer-doc-name">{{ editingReminderDoc?.document_label }}</p>
            <label class="comp-field-label">
              <input type="checkbox" v-model="reminderForm.reminder_active" />
              Enable email reminders
            </label>
            <label class="comp-field-label">Remind (days before expiry)</label>
            <input type="number" v-model.number="reminderForm.reminder_days_before" class="comp-field-input" min="1" max="365" />
            <label class="comp-field-label">Recipient Emails</label>
            <textarea v-model="reminderForm.reminder_recipients" class="comp-field-input" rows="3" placeholder="hr@company.com, ceo@company.com"></textarea>
            <small class="comp-field-hint">Comma-separated. Reminder emails are sent automatically each day.</small>
            <p v-if="reminderError" class="error">{{ reminderError }}</p>
            <div class="comp-drawer-actions">
              <button class="primary small" :disabled="savingReminder" @click="saveReminder">{{ savingReminder ? 'Saving…' : 'Save' }}</button>
              <button class="secondary small" @click="closeReminderDrawer">Cancel</button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.org-tab-switcher { display: flex; gap: 4px; background: var(--surface-2, #f1f5f9); border-radius: 8px; padding: 4px; }
.org-tab-btn { padding: 6px 16px; border: none; border-radius: 6px; background: transparent; color: var(--text-2, #64748b); font-size: 0.85rem; font-weight: 500; cursor: pointer; transition: background 0.15s, color 0.15s; }
.org-tab-btn.active { background: #fff; color: var(--primary, #3b82f6); box-shadow: 0 1px 4px rgba(0,0,0,0.08); }

.comp-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 12px; margin-bottom: 24px; }
.comp-stat-card { background: var(--surface-1, #fff); border: 1px solid var(--border, #e2e8f0); border-radius: 10px; padding: 14px 16px; display: flex; flex-direction: column; align-items: center; gap: 4px; }
.comp-stat-num { font-size: 2rem; font-weight: 700; line-height: 1; color: var(--text-1, #1e293b); }
.comp-stat-label { font-size: 0.75rem; color: var(--text-2, #64748b); text-align: center; }
.comp-stat-ok .comp-stat-num { color: #16a34a; }
.comp-stat-warn .comp-stat-num { color: #d97706; }
.comp-stat-danger .comp-stat-num { color: #dc2626; }

.comp-segment { margin-bottom: 28px; }
.comp-segment-header { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 2px solid var(--primary-light, #dbeafe); }
.comp-segment-title { font-size: 0.95rem; font-weight: 700; color: var(--primary, #3b82f6); text-transform: uppercase; letter-spacing: 0.04em; }
.comp-segment-count { font-size: 0.78rem; color: var(--text-2, #64748b); background: var(--surface-2, #f1f5f9); padding: 2px 8px; border-radius: 20px; }

.comp-doc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px; }
.comp-doc-card { background: var(--surface-1, #fff); border: 1px solid var(--border, #e2e8f0); border-left: 3px solid #94a3b8; border-radius: 10px; padding: 14px; display: flex; flex-direction: column; gap: 10px; transition: box-shadow 0.15s; }
.comp-doc-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.07); }
.comp-doc-card--expiring-soon { border-left-color: #f59e0b; }
.comp-doc-card--expired { border-left-color: #ef4444; }
.comp-doc-card--valid { border-left-color: #22c55e; }
.comp-doc-card--not-uploaded { border-left-color: #94a3b8; }

.comp-doc-card-header { display: flex; flex-direction: column; gap: 6px; }
.comp-doc-title-row { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
.comp-doc-label { font-size: 0.875rem; font-weight: 600; color: var(--text-1, #1e293b); line-height: 1.3; flex: 1; }
.comp-info-btn { background: none; border: none; font-size: 1rem; cursor: pointer; color: var(--text-2, #64748b); padding: 0 2px; flex-shrink: 0; line-height: 1; }
.comp-info-btn:hover { color: var(--primary, #3b82f6); }

.comp-status-badge { display: inline-block; padding: 2px 10px; border-radius: 20px; font-size: 0.72rem; font-weight: 600; letter-spacing: 0.03em; width: fit-content; }
.comp-status--not-uploaded { background: #f1f5f9; color: #64748b; }
.comp-status--valid { background: #dcfce7; color: #15803d; }
.comp-status--expiring-soon { background: #fef3c7; color: #b45309; }
.comp-status--expired { background: #fee2e2; color: #b91c1c; }

.comp-doc-dates { display: flex; flex-direction: column; gap: 3px; }
.comp-date-row { display: flex; gap: 8px; font-size: 0.78rem; }
.comp-date-label { color: var(--text-2, #64748b); width: 52px; flex-shrink: 0; }
.comp-date-val { color: var(--text-1, #1e293b); }
.comp-date-urgent { color: #ef4444; font-weight: 600; }

.comp-file-row { display: flex; align-items: center; gap: 8px; }
.comp-file-link { font-size: 0.78rem; color: var(--primary, #3b82f6); text-decoration: none; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; }
.comp-file-link:hover { text-decoration: underline; }
.comp-remove-btn { background: none; border: none; color: #94a3b8; cursor: pointer; font-size: 0.75rem; padding: 2px 4px; border-radius: 4px; }
.comp-remove-btn:hover { background: #fee2e2; color: #ef4444; }

.comp-doc-actions { display: flex; gap: 6px; flex-wrap: wrap; margin-top: auto; }
.comp-action-btn { flex: 1; padding: 5px 8px; border: 1px solid var(--border, #e2e8f0); border-radius: 6px; font-size: 0.78rem; font-weight: 500; cursor: pointer; background: var(--surface-2, #f8fafc); color: var(--text-1, #1e293b); transition: background 0.12s; white-space: nowrap; }
.comp-action-btn:hover:not(:disabled) { background: var(--surface-3, #e2e8f0); }
.comp-action-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.comp-action-upload { border-color: #3b82f6; color: #3b82f6; }
.comp-action-upload:hover:not(:disabled) { background: #eff6ff; }
.comp-action-reminder { flex: 0 0 auto; }

.comp-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 200; display: flex; align-items: center; justify-content: center; padding: 16px; }
.comp-popup { background: #fff; border-radius: 12px; width: 100%; max-width: 560px; max-height: 80vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.2); display: flex; flex-direction: column; }
.comp-popup-header { display: flex; align-items: flex-start; justify-content: space-between; padding: 18px 20px 12px; border-bottom: 1px solid var(--border, #e2e8f0); gap: 12px; }
.comp-popup-title { font-size: 1rem; font-weight: 700; color: var(--text-1, #1e293b); margin: 0 0 4px; }
.comp-popup-segment { font-size: 0.75rem; color: var(--primary, #3b82f6); background: #eff6ff; padding: 2px 8px; border-radius: 20px; }
.comp-popup-close { background: none; border: none; font-size: 1.1rem; cursor: pointer; color: var(--text-2, #64748b); padding: 0; flex-shrink: 0; }
.comp-popup-close:hover { color: #ef4444; }
.comp-popup-body { padding: 18px 20px; flex: 1; overflow-y: auto; }
.comp-guide-text { font-size: 0.85rem; line-height: 1.6; color: var(--text-1, #1e293b); }
.comp-guide-empty { color: var(--text-2, #64748b); font-style: italic; font-size: 0.85rem; }
.comp-guide-edit-btn { background: none; border: none; color: var(--primary, #3b82f6); font-size: 0.82rem; cursor: pointer; padding: 0; margin-top: 12px; display: block; }
.comp-guide-label { display: block; font-size: 0.82rem; font-weight: 600; color: var(--text-2, #64748b); margin-bottom: 6px; }
.comp-guide-textarea { width: 100%; border: 1px solid var(--border, #e2e8f0); border-radius: 6px; padding: 10px; font-size: 0.83rem; line-height: 1.5; resize: vertical; font-family: inherit; box-sizing: border-box; }
.comp-guide-actions { display: flex; gap: 8px; margin-top: 12px; }

.comp-drawer { background: #fff; border-radius: 12px; width: 100%; max-width: 420px; max-height: 85vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.2); display: flex; flex-direction: column; }
.comp-drawer-header { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px 12px; border-bottom: 1px solid var(--border, #e2e8f0); }
.comp-drawer-header h3 { margin: 0; font-size: 0.95rem; }
.comp-drawer-body { padding: 16px 20px; display: flex; flex-direction: column; gap: 10px; }
.comp-drawer-doc-name { font-weight: 600; font-size: 0.875rem; color: var(--text-1, #1e293b); margin: 0 0 4px; padding: 8px 12px; background: var(--surface-2, #f1f5f9); border-radius: 6px; }
.comp-field-label { font-size: 0.8rem; font-weight: 600; color: var(--text-2, #64748b); display: flex; align-items: center; gap: 6px; }
.comp-field-input { width: 100%; border: 1px solid var(--border, #e2e8f0); border-radius: 6px; padding: 8px 10px; font-size: 0.85rem; font-family: inherit; box-sizing: border-box; }
.comp-field-input:disabled { background: var(--surface-2, #f1f5f9); color: var(--text-2, #64748b); }
.comp-field-hint { color: var(--text-2, #64748b); font-size: 0.75rem; line-height: 1.4; margin-top: -6px; }
.comp-drawer-actions { display: flex; gap: 8px; margin-top: 6px; }

.error { color: #ef4444; font-size: 0.82rem; margin: 0; }
.error-banner { color: #b91c1c; background: #fee2e2; border-radius: 8px; padding: 10px 14px; font-size: 0.85rem; margin-bottom: 16px; }
.org-loading { display: flex; align-items: center; gap: 10px; padding: 24px 0; color: var(--text-2, #64748b); }
.org-loading-spinner { width: 18px; height: 18px; border: 2px solid var(--border, #e2e8f0); border-top-color: var(--primary, #3b82f6); border-radius: 50%; animation: spin 0.7s linear infinite; display: inline-block; }
@keyframes spin { to { transform: rotate(360deg); } }

.org-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; }
.org-panel { background: var(--surface-1, #fff); border: 1px solid var(--border, #e2e8f0); border-radius: 12px; padding: 18px; display: flex; flex-direction: column; gap: 14px; }
.org-panel-header { display: flex; align-items: center; justify-content: space-between; }
.org-panel-title { display: flex; align-items: center; gap: 8px; }
.org-panel-icon { font-size: 1.2rem; }
.org-panel-title h3 { margin: 0; font-size: 0.95rem; }
.org-count-badge { background: var(--primary-light, #dbeafe); color: var(--primary, #3b82f6); border-radius: 20px; padding: 2px 10px; font-size: 0.78rem; font-weight: 600; }
.org-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
.org-list-item { display: flex; align-items: flex-start; gap: 10px; padding: 8px 10px; background: var(--surface-2, #f8fafc); border-radius: 8px; }
.org-item-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--primary, #3b82f6); margin-top: 5px; flex-shrink: 0; }
.org-item-body { display: flex; flex-direction: column; gap: 2px; }
.org-item-name { font-size: 0.875rem; font-weight: 500; color: var(--text-1, #1e293b); }
.org-item-parent { font-size: 0.78rem; color: var(--text-2, #64748b); }
.org-empty { text-align: center; padding: 24px 0; color: var(--text-2, #64748b); }
.org-empty-icon { font-size: 2rem; margin-bottom: 8px; }
.org-empty-hint { font-size: 0.78rem; margin-top: 4px; }
.org-add-section { border-top: 1px solid var(--border, #e2e8f0); padding-top: 14px; }
.org-add-title { font-size: 0.82rem; font-weight: 600; color: var(--text-2, #64748b); margin: 0 0 10px; text-transform: uppercase; letter-spacing: 0.04em; }
.org-add-form { display: flex; flex-direction: column; gap: 8px; }
.org-add-form input { border: 1px solid var(--border, #e2e8f0); border-radius: 6px; padding: 8px 10px; font-size: 0.85rem; }
</style>
