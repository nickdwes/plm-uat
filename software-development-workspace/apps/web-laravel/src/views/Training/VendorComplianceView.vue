<template>
  <div class="view">

    <!-- ── Header ──────────────────────────────────────────────────────────── -->
    <div class="view-header">
      <div>
        <h2>Vendor Compliance</h2>
        <p class="view-subtitle">Track whether partner vendors have enough certified staff for each required training</p>
      </div>
      <button class="btn btn-primary" @click="openAddVendor">
        <svg viewBox="0 0 20 20" fill="currentColor" style="width:16px;height:16px;margin-right:6px;">
          <path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd"/>
        </svg>
        Add Vendor
      </button>
    </div>

    <!-- ── Summary metrics ─────────────────────────────────────────────────── -->
    <div class="metrics-grid">
      <div class="metric-card">
        <div class="metric-label">Total Vendors</div>
        <div class="metric-value">{{ summary.length }}</div>
      </div>
      <div class="metric-card">
        <div class="metric-label">Compliant</div>
        <div class="metric-value" style="color: var(--color-success)">{{ compliantCount }}</div>
      </div>
      <div class="metric-card">
        <div class="metric-label">At Risk</div>
        <div class="metric-value" style="color: var(--color-warning)">{{ atRiskCount }}</div>
      </div>
      <div class="metric-card">
        <div class="metric-label">Non-Compliant</div>
        <div class="metric-value" style="color: var(--color-error)">{{ nonCompliantCount }}</div>
      </div>
    </div>

    <!-- ── Toolbar ─────────────────────────────────────────────────────────── -->
    <div class="toolbar">
      <input
        v-model="search"
        type="search"
        placeholder="Search vendors…"
        style="flex:1; max-width:280px;"
      />
      <select v-model="filterStatus">
        <option value="">All statuses</option>
        <option value="compliant">Compliant</option>
        <option value="at_risk">At Risk</option>
        <option value="non_compliant">Non-Compliant</option>
      </select>
    </div>

    <p v-if="isLoading" class="status">Loading compliance data…</p>
    <p v-else-if="error" class="error">{{ error }}</p>

    <!-- ── Empty state ─────────────────────────────────────────────────────── -->
    <div v-else-if="filtered.length === 0 && summary.length === 0" class="card" style="text-align:center;padding:var(--space-10);">
      <div style="font-size:2.5rem;margin-bottom:var(--space-3);">🤝</div>
      <strong style="font-size:var(--font-size-lg)">No vendors yet</strong>
      <p style="color:var(--color-text-muted);margin-top:var(--space-2)">Add your first vendor partner to start tracking compliance.</p>
      <button class="btn btn-primary" style="margin-top:var(--space-5);" @click="openAddVendor">+ Add Vendor</button>
    </div>

    <!-- ── Vendor cards ────────────────────────────────────────────────────── -->
    <div v-else class="vendor-grid">
      <div
        v-for="item in filtered"
        :key="item.vendor.id"
        class="vendor-card"
        :class="'vendor-card--' + item.status"
        @click="openDetail(item)"
      >
        <!-- Card header -->
        <div class="vendor-card-head">
          <div class="vendor-card-name">{{ item.vendor.name }}</div>
          <span class="badge" :class="statusBadge(item.status)">{{ statusLabel(item.status) }}</span>
        </div>

        <!-- Progress bar -->
        <div class="vendor-progress-track" :title="`${item.certified_count} of ${item.required_count} requirements met`">
          <div
            class="vendor-progress-fill"
            :class="'vendor-progress-fill--' + item.status"
            :style="{ width: progressPct(item) + '%' }"
          ></div>
        </div>
        <div class="vendor-progress-label">
          {{ item.certified_count }} / {{ item.required_count }} requirements met
          <span v-if="item.expiring_soon > 0" class="badge badge-amber" style="margin-left:var(--space-2);">
            {{ item.expiring_soon }} expiring
          </span>
        </div>

        <!-- Gap list (show only unmet) -->
        <ul v-if="unmetGaps(item).length" class="vendor-gap-list">
          <li v-for="gap in unmetGaps(item)" :key="gap.training_item_id" class="vendor-gap-item">
            <span class="vendor-gap-title">{{ gap.title }}</span>
            <span class="vendor-gap-delta">
              {{ gap.actual_holders }}/{{ gap.required_headcount }}
              <span style="color:var(--color-error);margin-left:4px;">–{{ gap.gap }} short</span>
            </span>
          </li>
        </ul>
        <div v-else-if="item.required_count === 0" style="font-size:var(--font-size-sm);color:var(--color-text-muted);margin-top:var(--space-2);">
          No requirements defined
        </div>

        <!-- Actions -->
        <div class="vendor-card-actions">
          <button
            class="btn btn-sm btn-secondary"
            @click.stop="openRequirements(item)"
            title="Manage requirements"
          >Requirements</button>
          <button
            v-if="unmetGaps(item).length"
            class="btn btn-sm btn-primary"
            @click.stop="bulkAssignAll(item)"
            title="Assign priority training to eligible employees for all gaps"
          >Assign All Gaps</button>
          <button
            class="btn btn-sm btn-ghost"
            @click.stop="openEdit(item.vendor)"
            title="Edit vendor"
          >Edit</button>
        </div>
      </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════════════════
         Vendor detail slide-in drawer
    ════════════════════════════════════════════════════════════════════════════ -->
    <Transition name="drawer">
      <div v-if="detailItem" class="drawer-backdrop" @click.self="closeDetail">
        <div class="drawer-panel">
          <div class="drawer-header">
            <div>
              <div class="drawer-eyebrow">Compliance Detail</div>
              <div class="drawer-title">{{ detailItem.vendor.name }}</div>
            </div>
            <button class="btn btn-ghost btn-sm" @click="closeDetail">✕</button>
          </div>

          <div class="drawer-body">
            <!-- Stats -->
            <div class="drawer-section">
              <div class="drawer-section-title">Overview</div>
              <div class="detail-stats">
                <div class="detail-stat">
                  <div class="detail-stat-label">Status</div>
                  <span class="badge" :class="statusBadge(detailItem.status)">{{ statusLabel(detailItem.status) }}</span>
                </div>
                <div class="detail-stat">
                  <div class="detail-stat-label">Requirements Met</div>
                  <div class="detail-stat-value">{{ detailItem.certified_count }} / {{ detailItem.required_count }}</div>
                </div>
                <div class="detail-stat">
                  <div class="detail-stat-label">Expiring Soon</div>
                  <div class="detail-stat-value" :style="detailItem.expiring_soon > 0 ? 'color:var(--color-warning)' : ''">{{ detailItem.expiring_soon }}</div>
                </div>
                <div v-if="detailItem.vendor.contact_email" class="detail-stat">
                  <div class="detail-stat-label">Contact</div>
                  <div class="detail-stat-value">{{ detailItem.vendor.contact_email }}</div>
                </div>
              </div>
            </div>

            <!-- All gaps -->
            <div class="drawer-section">
              <div class="drawer-section-title">Certification Requirements</div>
              <div v-if="detailItem.gaps.length === 0" style="color:var(--color-text-muted);font-size:var(--font-size-sm);">
                No requirements defined for this vendor.
              </div>
              <div v-for="gap in detailItem.gaps" :key="gap.training_item_id" class="gap-row">
                <div class="gap-row-head">
                  <div>
                    <div style="font-weight:var(--font-weight-semibold);">{{ gap.title }}</div>
                    <div style="font-size:var(--font-size-xs);color:var(--color-text-muted);margin-top:2px;">{{ gap.type }}</div>
                  </div>
                  <span class="badge" :class="gap.met ? 'badge-green' : 'badge-red'">{{ gap.met ? 'Met' : 'Gap' }}</span>
                </div>
                <div class="gap-progress-track">
                  <div
                    class="gap-progress-fill"
                    :class="gap.met ? 'gap-progress-fill--met' : 'gap-progress-fill--gap'"
                    :style="{ width: Math.min(100, Math.round(gap.actual_holders / gap.required_headcount * 100)) + '%' }"
                  ></div>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:var(--font-size-sm);margin-top:var(--space-1);">
                  <span style="color:var(--color-text-muted);">{{ gap.actual_holders }} of {{ gap.required_headcount }} holders</span>
                  <span v-if="!gap.met" style="color:var(--color-error);">{{ gap.gap }} still needed</span>
                  <span v-if="gap.expiring_soon > 0" class="badge badge-amber" style="margin-left:var(--space-2);">{{ gap.expiring_soon }} expiring</span>
                </div>
                <div v-if="!gap.met" style="margin-top:var(--space-2);">
                  <button
                    class="btn btn-sm btn-primary"
                    @click="bulkAssignSingle(gap.training_item_id, gap.title)"
                  >Assign to eligible employees</button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </Transition>

    <!-- ════════════════════════════════════════════════════════════════════════
         Add / Edit vendor drawer
    ════════════════════════════════════════════════════════════════════════════ -->
    <Transition name="drawer">
      <div v-if="showVendorForm" class="drawer-backdrop" @click.self="closeVendorForm">
        <div class="drawer-panel">
          <div class="drawer-header">
            <div>
              <div class="drawer-eyebrow">{{ editingVendor ? 'Edit' : 'New' }} vendor</div>
              <div class="drawer-title">{{ editingVendor ? editingVendor.name : 'Add Vendor Partner' }}</div>
            </div>
            <button class="btn btn-ghost btn-sm" @click="closeVendorForm">✕</button>
          </div>

          <div class="drawer-body">
            <div class="drawer-section">
              <div class="drawer-section-title">Vendor Details</div>
              <div class="form-group">
                <label>Name <span class="req">*</span></label>
                <input v-model="form.name" type="text" placeholder="e.g. Cisco Systems" required />
              </div>
              <div class="form-row">
                <div class="form-group">
                  <label>Contact Email</label>
                  <input v-model="form.contact_email" type="email" placeholder="partner@vendor.com" />
                </div>
                <div class="form-group">
                  <label>Contact Phone</label>
                  <input v-model="form.contact_phone" type="text" placeholder="+254 700 000 000" />
                </div>
              </div>
              <div class="form-group">
                <label>Website</label>
                <input v-model="form.website_url" type="url" placeholder="https://vendor.com" />
              </div>
              <div class="form-group">
                <label>Notes</label>
                <textarea v-model="form.notes" rows="3" placeholder="Internal notes about this partner…"></textarea>
              </div>
              <label class="toggle-item">
                <span class="toggle-wrap">
                  <input v-model="form.is_active" type="checkbox" class="toggle-input" />
                  <span class="toggle-track"></span>
                </span>
                <span class="toggle-text">
                  <span class="toggle-label">Active</span>
                  <span class="toggle-hint">Inactive vendors are hidden from compliance views</span>
                </span>
              </label>
            </div>
          </div>

          <div class="drawer-footer">
            <p v-if="formError" class="error" style="flex:1;margin:0;">{{ formError }}</p>
            <button class="btn btn-secondary" @click="closeVendorForm">Cancel</button>
            <button class="btn btn-primary" :disabled="isSaving" @click="saveVendor">
              {{ isSaving ? 'Saving…' : editingVendor ? 'Save Changes' : 'Create Vendor' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- ════════════════════════════════════════════════════════════════════════
         Requirements manager drawer
    ════════════════════════════════════════════════════════════════════════════ -->
    <Transition name="drawer">
      <div v-if="reqVendorItem" class="drawer-backdrop" @click.self="closeRequirements">
        <div class="drawer-panel">
          <div class="drawer-header">
            <div>
              <div class="drawer-eyebrow">Requirements</div>
              <div class="drawer-title">{{ reqVendorItem.vendor.name }}</div>
            </div>
            <button class="btn btn-ghost btn-sm" @click="closeRequirements">✕</button>
          </div>

          <div class="drawer-body">
            <div class="drawer-section">
              <div class="drawer-section-title">Required Certifications</div>
              <p style="font-size:var(--font-size-sm);color:var(--color-text-muted);">
                Define which training certifications this vendor's staff must hold, and the minimum headcount.
                To add more options, link certifications to training catalog items in the Vendor Profile.
              </p>

              <!-- Existing requirements -->
              <div v-for="(req, idx) in reqList" :key="idx" class="req-row">
                <div class="form-group" style="flex:1;">
                  <label v-if="idx === 0">Certification</label>
                  <select v-model.number="req.training_item_id" :disabled="vendorCertsLoading">
                    <option :value="0">— Select certification —</option>
                    <optgroup v-if="vendorCerts.length" label="Vendor Certifications">
                      <option v-for="cert in vendorCerts" :key="cert.id" :value="cert.training_item_id">
                        {{ cert.name }}{{ cert.code ? ' (' + cert.code + ')' : '' }}
                      </option>
                    </optgroup>
                    <optgroup v-if="!vendorCerts.length && !vendorCertsLoading" label="No linked certifications">
                      <option disabled>Link certifications in Vendor Profile first</option>
                    </optgroup>
                  </select>
                </div>
                <div class="form-group" style="width:110px;">
                  <label v-if="idx === 0">Min. staff</label>
                  <input v-model.number="req.required_headcount" type="number" min="1" max="9999" />
                </div>
                <div style="display:flex;align-items:flex-end;padding-bottom:2px;">
                  <button class="btn btn-ghost btn-sm" @click="removeReq(idx)" title="Remove">✕</button>
                </div>
              </div>

              <button class="btn btn-secondary btn-sm" @click="addReq" style="margin-top:var(--space-2);">
                + Add requirement
              </button>
            </div>
          </div>

          <div class="drawer-footer">
            <p v-if="reqError" class="error" style="flex:1;margin:0;">{{ reqError }}</p>
            <button class="btn btn-secondary" @click="closeRequirements">Cancel</button>
            <button class="btn btn-primary" :disabled="isSavingReq" @click="saveRequirements">
              {{ isSavingReq ? 'Saving…' : 'Save Requirements' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>

  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { api } from '@/lib/api';
import { currentOrgSlug } from '@/stores/auth';
import type { VendorComplianceStatus, VendorGap, VendorCertification } from '@/lib/types';

function org(): string | null { return currentOrgSlug.value; }

// ── State ────────────────────────────────────────────────────────────────────
const isLoading = ref(false);
const error     = ref('');
const summary   = ref<VendorComplianceStatus[]>([]);

// Filters
const search       = ref('');
const filterStatus = ref('');

// Detail drawer
const detailItem = ref<VendorComplianceStatus | null>(null);

// Vendor form drawer — editingVendor holds the compact vendor from the summary
const showVendorForm = ref(false);
const editingVendor  = ref<VendorComplianceStatus['vendor'] | null>(null);
const isSaving  = ref(false);
const formError = ref('');
const form = ref({
  name: '',
  contact_email: '',
  contact_phone: '',
  website_url: '',
  notes: '',
  is_active: true,
});

// Requirements drawer
const reqVendorItem = ref<VendorComplianceStatus | null>(null);
const reqList = ref<{ training_item_id: number; required_headcount: number }[]>([]);
const isSavingReq = ref(false);
const reqError    = ref('');
// Linked certifications for the selected vendor (those with a training_item_id)
const vendorCerts         = ref<VendorCertification[]>([]);
const vendorCertsLoading  = ref(false);

// ── Computed ─────────────────────────────────────────────────────────────────
const compliantCount    = computed(() => summary.value.filter(s => s.status === 'compliant').length);
const atRiskCount       = computed(() => summary.value.filter(s => s.status === 'at_risk').length);
const nonCompliantCount = computed(() => summary.value.filter(s => s.status === 'non_compliant').length);

const filtered = computed(() => {
  return summary.value.filter(item => {
    const matchSearch = !search.value
      || item.vendor.name.toLowerCase().includes(search.value.toLowerCase());
    const matchStatus = !filterStatus.value || item.status === filterStatus.value;
    return matchSearch && matchStatus;
  });
});

function unmetGaps(item: VendorComplianceStatus): VendorGap[] {
  return item.gaps.filter(g => !g.met);
}

function progressPct(item: VendorComplianceStatus): number {
  if (item.required_count === 0) return 100;
  return Math.round((item.certified_count / item.required_count) * 100);
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function statusBadge(status: string): string {
  return { compliant: 'badge-green', at_risk: 'badge-amber', non_compliant: 'badge-red' }[status] ?? 'badge-gray';
}

function statusLabel(status: string): string {
  return { compliant: 'Compliant', at_risk: 'At Risk', non_compliant: 'Non-Compliant' }[status] ?? status;
}

// ── API ───────────────────────────────────────────────────────────────────────
async function loadSummary() {
  isLoading.value = true;
  error.value = '';
  try {
    const res = await api<{ data: VendorComplianceStatus[] }>('GET', '/api/v1/training/vendor-compliance/summary', { org: org() });
    summary.value = res.data ?? [];
  } catch (e: any) {
    error.value = e?.message ?? 'Failed to load compliance data.';
  } finally {
    isLoading.value = false;
  }
}

async function bulkAssignSingle(trainingItemId: number, title: string) {
  if (!confirm(`Assign priority training "${title}" to all eligible employees who don't have a record yet?`)) return;
  try {
    const res = await api<{ count: number; message: string }>('POST', '/api/v1/training/vendor-compliance/bulk-assign', {
      org: org(),
      body: { training_item_id: trainingItemId },
    });
    alert(res.message);
    await loadSummary();
  } catch (e: any) {
    alert(e?.message ?? 'Bulk assign failed.');
  }
}

async function bulkAssignAll(item: VendorComplianceStatus) {
  const gaps = unmetGaps(item);
  if (!gaps.length) return;
  if (!confirm(`Bulk-assign ${gaps.length} certification(s) for "${item.vendor.name}" to all eligible employees?`)) return;
  for (const gap of gaps) {
    await bulkAssignSingle(gap.training_item_id, gap.title);
  }
}

// ── Drawers ───────────────────────────────────────────────────────────────────
function openDetail(item: VendorComplianceStatus) { detailItem.value = item; }
function closeDetail() { detailItem.value = null; }

function openAddVendor() {
  editingVendor.value = null;
  form.value = { name: '', contact_email: '', contact_phone: '', website_url: '', notes: '', is_active: true };
  formError.value = '';
  showVendorForm.value = true;
}

function openEdit(vendor: VendorComplianceStatus['vendor']) {
  editingVendor.value = vendor;
  form.value = {
    name: vendor.name,
    contact_email: vendor.contact_email ?? '',
    contact_phone: '',
    website_url: vendor.website_url ?? '',
    notes: '',
    is_active: vendor.is_active,
  };
  formError.value = '';
  showVendorForm.value = true;
}

function closeVendorForm() { showVendorForm.value = false; editingVendor.value = null; }

async function saveVendor() {
  if (!form.value.name.trim()) { formError.value = 'Name is required.'; return; }
  isSaving.value = true;
  formError.value = '';
  try {
    if (editingVendor.value) {
      await api('PUT', `/api/v1/training/vendor-compliance/${editingVendor.value.id}`, { org: org(), body: form.value });
    } else {
      await api('POST', '/api/v1/training/vendor-compliance', { org: org(), body: form.value });
    }
    closeVendorForm();
    await loadSummary();
  } catch (e: any) {
    formError.value = e?.message ?? 'Save failed.';
  } finally {
    isSaving.value = false;
  }
}

function openRequirements(item: VendorComplianceStatus) {
  reqVendorItem.value = item;
  reqList.value = (item.gaps ?? []).map(g => ({
    training_item_id: g.training_item_id,
    required_headcount: g.required_headcount,
  }));
  if (reqList.value.length === 0) reqList.value.push({ training_item_id: 0, required_headcount: 1 });
  reqError.value = '';
  // Load vendor's certifications that are linked to training catalog items
  vendorCerts.value = [];
  vendorCertsLoading.value = true;
  api<{ data: VendorCertification[] }>('GET', `/api/v1/vendors/${item.vendor.id}/certifications`, { org: org() })
    .then(res => {
      vendorCerts.value = (res.data ?? []).filter(c => c.training_item_id !== null && c.is_active);
    })
    .catch(() => { /* non-critical */ })
    .finally(() => { vendorCertsLoading.value = false; });
}

function closeRequirements() { reqVendorItem.value = null; }

function addReq() { reqList.value.push({ training_item_id: 0, required_headcount: 1 }); }
function removeReq(idx: number) { reqList.value.splice(idx, 1); }

async function saveRequirements() {
  if (!reqVendorItem.value) return;
  const valid = reqList.value.filter(r => r.training_item_id > 0 && r.required_headcount >= 1);
  isSavingReq.value = true;
  reqError.value = '';
  try {
    await api('PUT', `/api/v1/training/vendor-compliance/${reqVendorItem.value.vendor.id}/requirements`, {
      org: org(),
      body: { requirements: valid },
    });
    closeRequirements();
    await loadSummary();
  } catch (e: any) {
    reqError.value = e?.message ?? 'Failed to save requirements.';
  } finally {
    isSavingReq.value = false;
  }
}

// ── Lifecycle ─────────────────────────────────────────────────────────────────
onMounted(loadSummary);
</script>

<style scoped>
/* ── Vendor grid ──────────────────────────────────────────────────────────── */
.vendor-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
  gap: var(--space-4);
  margin-bottom: var(--space-6);
}

.vendor-card {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-left-width: 4px;
  border-radius: var(--radius-xl);
  padding: var(--space-5);
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
  cursor: pointer;
  transition: box-shadow var(--transition-base), border-color var(--transition-base);
}

.vendor-card:hover { box-shadow: var(--shadow-md); }

.vendor-card--compliant    { border-left-color: var(--color-success); }
.vendor-card--at_risk      { border-left-color: var(--color-warning); }
.vendor-card--non_compliant { border-left-color: var(--color-error); }

.vendor-card-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--space-2);
}

.vendor-card-name {
  font-size: var(--font-size-base);
  font-weight: var(--font-weight-bold);
  color: var(--color-text);
}

/* ── Progress bar ─────────────────────────────────────────────────────────── */
.vendor-progress-track {
  height: 6px;
  background: var(--color-border);
  border-radius: var(--radius-full);
  overflow: hidden;
}

.vendor-progress-fill {
  height: 100%;
  border-radius: var(--radius-full);
  transition: width 0.4s ease;
}

.vendor-progress-fill--compliant    { background: var(--color-success); }
.vendor-progress-fill--at_risk      { background: var(--color-warning); }
.vendor-progress-fill--non_compliant { background: var(--color-error); }

.vendor-progress-label {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  display: flex;
  align-items: center;
}

/* ── Gap list ─────────────────────────────────────────────────────────────── */
.vendor-gap-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
}

.vendor-gap-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: var(--font-size-sm);
  padding: var(--space-1) 0;
  border-bottom: 1px solid var(--color-border);
}

.vendor-gap-item:last-child { border-bottom: none; }

.vendor-gap-title {
  color: var(--color-text);
  font-weight: var(--font-weight-semibold);
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.vendor-gap-delta {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  white-space: nowrap;
  margin-left: var(--space-2);
}

.vendor-card-actions {
  display: flex;
  gap: var(--space-2);
  flex-wrap: wrap;
  padding-top: var(--space-1);
}

/* ── Detail drawer internals ─────────────────────────────────────────────── */
.detail-stats {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: var(--space-4);
}

.detail-stat-label {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  font-weight: var(--font-weight-semibold);
  text-transform: uppercase;
  letter-spacing: 0.06em;
  margin-bottom: 4px;
}

.detail-stat-value {
  font-size: var(--font-size-base);
  font-weight: var(--font-weight-semibold);
  color: var(--color-text);
}

.gap-row {
  padding: var(--space-3) 0;
  border-bottom: 1px solid var(--color-border);
}

.gap-row:last-child { border-bottom: none; }

.gap-row-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: var(--space-2);
}

.gap-progress-track {
  height: 6px;
  background: var(--color-border);
  border-radius: var(--radius-full);
  overflow: hidden;
}

.gap-progress-fill {
  height: 100%;
  border-radius: var(--radius-full);
  transition: width 0.4s ease;
}

.gap-progress-fill--met { background: var(--color-success); }
.gap-progress-fill--gap { background: var(--color-error); }

/* ── Requirements drawer ─────────────────────────────────────────────────── */
.req-row {
  display: flex;
  gap: var(--space-2);
  align-items: flex-start;
  padding: var(--space-2) 0;
  border-bottom: 1px solid var(--color-border);
}

.req-row:last-of-type { border-bottom: none; }

/* ── Drawer layout ───────────────────────────────────────────────────────── */
.drawer-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.4);
  z-index: 400;
  display: flex;
  justify-content: flex-end;
}

.drawer-panel {
  background: var(--color-surface);
  width: 480px;
  max-width: 100vw;
  height: 100%;
  display: flex;
  flex-direction: column;
  box-shadow: -4px 0 32px rgba(0, 0, 0, 0.14);
}

.drawer-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  padding: var(--space-6) var(--space-6) var(--space-5);
  border-bottom: 1px solid var(--color-border);
  flex-shrink: 0;
}

.drawer-eyebrow {
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-semibold);
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--color-brand-base);
  margin-bottom: 4px;
}

.drawer-title {
  font-size: var(--font-size-lg);
  font-weight: var(--font-weight-bold);
  color: var(--color-text);
  margin: 0;
}

.drawer-body {
  flex: 1;
  overflow-y: auto;
  padding: var(--space-2) 0;
}

.drawer-section {
  padding: var(--space-5) var(--space-6);
  border-bottom: 1px solid var(--color-border);
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.drawer-section-title {
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-bold);
  text-transform: uppercase;
  letter-spacing: 0.07em;
  color: var(--color-text-muted);
}

.drawer-footer {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-4) var(--space-6);
  border-top: 1px solid var(--color-border);
  flex-shrink: 0;
  background: var(--color-surface);
}

.form-row {
  display: flex;
  gap: var(--space-3);
}

.form-row .form-group {
  flex: 1;
  min-width: 0;
}

.req { color: var(--color-error); }

/* Toggle switch */
.toggle-item {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  cursor: pointer;
}

.toggle-wrap { position: relative; flex-shrink: 0; }

.toggle-input { position: absolute; opacity: 0; width: 0; height: 0; }

.toggle-track {
  display: block;
  width: 40px;
  height: 22px;
  background: var(--color-neutral-200);
  border-radius: var(--radius-full);
  transition: background var(--transition-fast);
  position: relative;
}

.toggle-track::after {
  content: '';
  position: absolute;
  top: 3px;
  left: 3px;
  width: 16px;
  height: 16px;
  border-radius: 50%;
  background: #fff;
  box-shadow: 0 1px 3px rgba(0,0,0,0.2);
  transition: transform var(--transition-fast);
}

.toggle-input:checked + .toggle-track { background: var(--color-brand-base); }
.toggle-input:checked + .toggle-track::after { transform: translateX(18px); }

.toggle-text { display: flex; flex-direction: column; gap: 2px; }
.toggle-label { font-size: var(--font-size-base); font-weight: var(--font-weight-semibold); color: var(--color-text); }
.toggle-hint  { font-size: var(--font-size-sm); color: var(--color-text-muted); }

/* Drawer slide-in transition */
.drawer-enter-active,
.drawer-leave-active { transition: opacity 0.2s ease; }
.drawer-enter-active .drawer-panel,
.drawer-leave-active .drawer-panel { transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1); }
.drawer-enter-from, .drawer-leave-to { opacity: 0; }
.drawer-enter-from .drawer-panel, .drawer-leave-to .drawer-panel { transform: translateX(100%); }
</style>
