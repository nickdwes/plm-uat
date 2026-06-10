<template>
  <div class="view">

    <!-- ── Header ─────────────────────────────────────────────────────────── -->
    <div class="view-header">
      <div>
        <h2>Vendor Management</h2>
        <p class="view-subtitle">Register and manage vendor partners — track onboarding status and compliance</p>
      </div>
      <button class="btn btn-primary" @click="openAdd">
        <svg viewBox="0 0 20 20" fill="currentColor" style="width:16px;height:16px;margin-right:6px;">
          <path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd"/>
        </svg>
        Register Vendor
      </button>
    </div>

    <!-- ── Metrics ─────────────────────────────────────────────────────────── -->
    <div class="metrics-grid">
      <div class="metric-card">
        <div class="metric-label">Total Vendors</div>
        <div class="metric-value">{{ metrics.total }}</div>
      </div>
      <div class="metric-card">
        <div class="metric-label">Approved</div>
        <div class="metric-value" style="color:var(--color-success)">{{ metrics.approved }}</div>
      </div>
      <div class="metric-card">
        <div class="metric-label">Pending Review</div>
        <div class="metric-value" style="color:var(--color-warning)">{{ metrics.pending_review }}</div>
      </div>
      <div class="metric-card">
        <div class="metric-label">Draft</div>
        <div class="metric-value" style="color:var(--color-text-muted)">{{ metrics.draft }}</div>
      </div>
      <div class="metric-card">
        <div class="metric-label">Suspended / Rejected</div>
        <div class="metric-value" style="color:var(--color-error)">{{ metrics.suspended + metrics.rejected }}</div>
      </div>
    </div>

    <!-- ── Toolbar ─────────────────────────────────────────────────────────── -->
    <div class="toolbar">
      <input
        v-model="search"
        type="search"
        placeholder="Search vendors…"
        style="flex:1;max-width:280px;"
        @input="onSearch"
      />
      <select v-model="filterType" @change="load(1)">
        <option value="">All types</option>
        <option value="supplier">Supplier</option>
        <option value="service_provider">Service Provider</option>
        <option value="contractor">Contractor</option>
        <option value="consultant">Consultant</option>
        <option value="distributor">Distributor</option>
        <option value="partner">Partner</option>
      </select>
      <select v-model="filterStatus" @change="load(1)">
        <option value="">All statuses</option>
        <option value="draft">Draft</option>
        <option value="pending_review">Pending Review</option>
        <option value="approved">Approved</option>
        <option value="rejected">Rejected</option>
        <option value="suspended">Suspended</option>
      </select>
    </div>

    <!-- ── Loading / Error ──────────────────────────────────────────────────── -->
    <p v-if="isLoading" class="status">Loading vendors…</p>
    <p v-else-if="error" class="error">{{ error }}</p>

    <!-- ── Empty state ───────────────────────────────────────────────────────── -->
    <div v-else-if="vendors.length === 0" class="card" style="text-align:center;padding:var(--space-10);">
      <div style="font-size:2.5rem;margin-bottom:var(--space-3);">🏢</div>
      <strong style="font-size:var(--font-size-lg)">No vendors found</strong>
      <p style="color:var(--color-text-muted);margin-top:var(--space-2)">Register your first vendor partner to get started.</p>
      <button class="btn btn-primary" style="margin-top:var(--space-5);" @click="openAdd">+ Register Vendor</button>
    </div>

    <!-- ── Vendor Table ──────────────────────────────────────────────────────── -->
    <div v-else class="card table-wrap">
      <table>
        <thead>
          <tr>
            <th>Vendor #</th>
            <th>Company Name</th>
            <th>Type</th>
            <th>Category</th>
            <th>Primary Contact</th>
            <th>Email</th>
            <th>City</th>
            <th>Status</th>
            <th style="text-align:right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="v in vendors" :key="v.id" style="cursor:pointer;" @click="router.push({ name: 'vendor-profile', params: { id: String(v.id) } })">
            <td>
              <span style="font-family:monospace;font-size:var(--font-size-sm);color:var(--color-text-muted)">
                {{ v.vendor_number ?? '—' }}
              </span>
            </td>
            <td>
              <strong>{{ v.name }}</strong>
              <div v-if="v.registration_number" style="font-size:var(--font-size-xs);color:var(--color-text-muted)">
                Reg: {{ v.registration_number }}
              </div>
            </td>
            <td>
              <span class="badge badge-gray">{{ typeLabel(v.vendor_type) }}</span>
            </td>
            <td>{{ v.category ?? '—' }}</td>
            <td>
              <div v-if="v.primary_contact_name">{{ v.primary_contact_name }}</div>
              <div v-if="v.primary_contact_designation" style="font-size:var(--font-size-xs);color:var(--color-text-muted)">
                {{ v.primary_contact_designation }}
              </div>
              <span v-else style="color:var(--color-text-muted)">—</span>
            </td>
            <td>
              <a v-if="v.contact_email" :href="'mailto:' + v.contact_email" style="color:var(--color-brand-base);" @click.stop>
                {{ v.contact_email }}
              </a>
              <span v-else style="color:var(--color-text-muted)">—</span>
            </td>
            <td>{{ v.city ?? '—' }}</td>
            <td @click.stop>
              <span class="badge" :class="statusBadge(v.onboarding_status)">
                {{ statusLabel(v.onboarding_status) }}
              </span>
            </td>
            <td style="text-align:right;white-space:nowrap;" @click.stop>
              <button class="btn btn-ghost btn-sm" title="Edit" @click="openEdit(v)">
                <svg viewBox="0 0 20 20" fill="currentColor" style="width:14px;height:14px;">
                  <path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zm-2.207 2.207L3 13.172V16h2.828l8.38-8.379-2.83-2.828z"/>
                </svg>
              </button>
              <!-- Submit for review -->
              <button
                v-if="v.onboarding_status === 'draft' || v.onboarding_status === 'rejected'"
                class="btn btn-ghost btn-sm"
                title="Submit for review"
                style="color:var(--color-warning)"
                @click="doSubmit(v)"
              >
                <svg viewBox="0 0 20 20" fill="currentColor" style="width:14px;height:14px;">
                  <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-8.707l-3-3a1 1 0 00-1.414 1.414L10.586 9H7a1 1 0 100 2h3.586l-1.293 1.293a1 1 0 101.414 1.414l3-3a1 1 0 000-1.414z" clip-rule="evenodd"/>
                </svg>
              </button>
              <!-- Approve -->
              <button
                v-if="v.onboarding_status === 'pending_review'"
                class="btn btn-ghost btn-sm"
                title="Approve"
                style="color:var(--color-success)"
                @click="doApprove(v)"
              >
                <svg viewBox="0 0 20 20" fill="currentColor" style="width:14px;height:14px;">
                  <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414L8.414 15l-5.121-5.121a1 1 0 011.414-1.414L8.414 12.172l6.879-6.879a1 1 0 011.414 0z" clip-rule="evenodd"/>
                </svg>
              </button>
              <!-- Reject -->
              <button
                v-if="v.onboarding_status === 'pending_review'"
                class="btn btn-ghost btn-sm"
                title="Reject"
                style="color:var(--color-error)"
                @click="openReject(v)"
              >
                <svg viewBox="0 0 20 20" fill="currentColor" style="width:14px;height:14px;">
                  <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                </svg>
              </button>
              <!-- Suspend -->
              <button
                v-if="v.onboarding_status === 'approved'"
                class="btn btn-ghost btn-sm"
                title="Suspend"
                style="color:var(--color-error)"
                @click="openSuspend(v)"
              >
                <svg viewBox="0 0 20 20" fill="currentColor" style="width:14px;height:14px;">
                  <path fill-rule="evenodd" d="M13.477 14.89A6 6 0 015.11 6.524L13.477 14.89zm1.414-1.414L6.524 5.11a6 6 0 018.367 8.367zM18 10a8 8 0 11-16 0 8 8 0 0116 0z" clip-rule="evenodd"/>
                </svg>
              </button>
              <!-- Reactivate -->
              <button
                v-if="v.onboarding_status === 'suspended' || v.onboarding_status === 'rejected'"
                class="btn btn-ghost btn-sm"
                title="Reactivate"
                style="color:var(--color-success)"
                @click="doReactivate(v)"
              >
                <svg viewBox="0 0 20 20" fill="currentColor" style="width:14px;height:14px;">
                  <path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd"/>
                </svg>
              </button>
              <!-- Delete -->
              <button class="btn btn-ghost btn-sm" title="Delete" style="color:var(--color-error)" @click="doDelete(v)">
                <svg viewBox="0 0 20 20" fill="currentColor" style="width:14px;height:14px;">
                  <path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zm-3 7a1 1 0 012 0v4a1 1 0 11-2 0V9zm5 0a1 1 0 012 0v4a1 1 0 11-2 0V9z" clip-rule="evenodd"/>
                </svg>
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- ── Pagination ────────────────────────────────────────────────────────── -->
    <div v-if="totalPages > 1" class="pagination">
      <button class="btn btn-ghost btn-sm" :disabled="page <= 1" @click="load(page - 1)">‹ Prev</button>
      <span style="color:var(--color-text-muted);font-size:var(--font-size-sm)">
        Page {{ page }} of {{ totalPages }}
      </span>
      <button class="btn btn-ghost btn-sm" :disabled="page >= totalPages" @click="load(page + 1)">Next ›</button>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════ -->
    <!-- DRAWER: Add / Edit Vendor Registration                                  -->
    <!-- ═══════════════════════════════════════════════════════════════════════ -->
    <div v-if="showForm" class="drawer-backdrop" @click.self="closeForm">
      <div class="drawer-panel">
        <div class="drawer-header">
          <h3>{{ editing ? 'Edit Vendor' : 'Register Vendor' }}</h3>
          <button class="btn btn-ghost btn-sm" @click="closeForm">✕</button>
        </div>
        <div class="drawer-body">

          <!-- Section: Company Information -->
          <div class="form-section-title">Company Information</div>

          <div class="form-group">
            <label>Company Name <span style="color:var(--color-error)">*</span></label>
            <input v-model="form.name" type="text" placeholder="e.g. Acme Solutions Ltd" />
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4);">
            <div class="form-group">
              <label>Registration Number</label>
              <input v-model="form.registration_number" type="text" placeholder="e.g. CPR/2020/12345" />
            </div>
            <div class="form-group">
              <label>Tax ID / KRA PIN</label>
              <input v-model="form.tax_id" type="text" placeholder="e.g. P051234567A" />
            </div>
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4);">
            <div class="form-group">
              <label>Vendor Type <span style="color:var(--color-error)">*</span></label>
              <select v-model="form.vendor_type">
                <option value="supplier">Supplier</option>
                <option value="service_provider">Service Provider</option>
                <option value="contractor">Contractor</option>
                <option value="consultant">Consultant</option>
                <option value="distributor">Distributor</option>
                <option value="partner">Partner</option>
              </select>
            </div>
            <div class="form-group">
              <label>Category / Industry</label>
              <input v-model="form.category" type="text" placeholder="e.g. IT & Technology" />
            </div>
          </div>

          <div class="form-group">
            <label>Services / Description</label>
            <textarea v-model="form.description" rows="3" placeholder="Briefly describe the services or goods this vendor provides…" />
          </div>

          <!-- Section: Primary Contact -->
          <div class="form-section-title" style="margin-top:var(--space-6);">Primary Contact</div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4);">
            <div class="form-group">
              <label>Contact Name</label>
              <input v-model="form.primary_contact_name" type="text" placeholder="Full name" />
            </div>
            <div class="form-group">
              <label>Designation / Title</label>
              <input v-model="form.primary_contact_designation" type="text" placeholder="e.g. Account Manager" />
            </div>
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4);">
            <div class="form-group">
              <label>Email Address</label>
              <input v-model="form.contact_email" type="email" placeholder="contact@vendor.com" />
            </div>
            <div class="form-group">
              <label>Phone Number</label>
              <input v-model="form.contact_phone" type="tel" placeholder="+254 7xx xxx xxx" />
            </div>
          </div>

          <div class="form-group">
            <label>Website URL</label>
            <input v-model="form.website_url" type="url" placeholder="https://www.vendor.com" />
          </div>

          <div class="form-group">
            <label>Logo URL</label>
            <input v-model="form.logo_url" type="url" placeholder="https://cdn.vendor.com/logo.png" />
          </div>

          <!-- Section: Address -->
          <div class="form-section-title" style="margin-top:var(--space-6);">Address</div>

          <div class="form-group">
            <label>Physical Address</label>
            <input v-model="form.physical_address" type="text" placeholder="Street / Building / Floor" />
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4);">
            <div class="form-group">
              <label>City / Town</label>
              <input v-model="form.city" type="text" placeholder="e.g. Nairobi" />
            </div>
            <div class="form-group">
              <label>Country</label>
              <input v-model="form.country" type="text" placeholder="Kenya" />
            </div>
          </div>

          <!-- Section: Additional Notes -->
          <div class="form-section-title" style="margin-top:var(--space-6);">Notes</div>
          <div class="form-group">
            <label>Internal Notes</label>
            <textarea v-model="form.notes" rows="3" placeholder="Any internal notes about this vendor…" />
          </div>

          <!-- Section: Partnership & Fiscal -->
          <div class="form-section-title" style="margin-top:var(--space-6);">Partnership & Fiscal</div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4);">
            <div class="form-group">
              <label>Partnership Level</label>
              <select v-model="form.partnership_level">
                <option value="">— None / Not specified —</option>
                <option value="Registered">Registered</option>
                <option value="Silver">Silver</option>
                <option value="Gold">Gold</option>
                <option value="Platinum">Platinum</option>
                <option value="Elite">Elite</option>
                <option value="Titanium">Titanium</option>
                <option value="Diamond">Diamond</option>
                <option value="Custom">Custom</option>
              </select>
            </div>
            <div class="form-group">
              <label>Fiscal Year Start Month</label>
              <select v-model.number="form.fiscal_year_start_month">
                <option :value="null">— Not specified —</option>
                <option :value="1">January</option>
                <option :value="2">February</option>
                <option :value="3">March</option>
                <option :value="4">April</option>
                <option :value="5">May</option>
                <option :value="6">June</option>
                <option :value="7">July</option>
                <option :value="8">August</option>
                <option :value="9">September</option>
                <option :value="10">October</option>
                <option :value="11">November</option>
                <option :value="12">December</option>
              </select>
            </div>
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4);">
            <div class="form-group">
              <label>Renewal Anniversary Date</label>
              <input v-model="form.renewal_anniversary_date" type="date" />
            </div>
            <div class="form-group">
              <label>Reminder (days before)</label>
              <input v-model.number="form.renewal_reminder_days" type="number" min="1" max="365" placeholder="30" />
            </div>
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4);">
            <div class="form-group">
              <label>Renewal Cost</label>
              <input v-model.number="form.renewal_cost" type="number" step="0.01" min="0" placeholder="e.g. 5000" />
            </div>
            <div class="form-group">
              <label>Currency</label>
              <input v-model="form.renewal_cost_currency" type="text" maxlength="10" placeholder="USD / KES" />
            </div>
          </div>

          <p v-if="formError" class="error" style="margin-top:var(--space-3);">{{ formError }}</p>
        </div>

        <div class="drawer-footer">
          <button class="btn btn-secondary" @click="closeForm">Cancel</button>
          <button v-if="!editing" class="btn btn-secondary" :disabled="isSaving" @click="saveAndSubmit">
            Save & Submit for Review
          </button>
          <button class="btn btn-primary" :disabled="isSaving" @click="save">
            {{ isSaving ? 'Saving…' : (editing ? 'Save Changes' : 'Save as Draft') }}
          </button>
        </div>
      </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════ -->
    <!-- MODAL: Reject / Suspend reason                                          -->
    <!-- ═══════════════════════════════════════════════════════════════════════ -->
    <div v-if="showReasonModal" class="drawer-backdrop" @click.self="closeReasonModal">
      <div class="drawer-panel" style="max-width:480px;">
        <div class="drawer-header">
          <h3>{{ reasonModalTitle }}</h3>
          <button class="btn btn-ghost btn-sm" @click="closeReasonModal">✕</button>
        </div>
        <div class="drawer-body">
          <div class="form-group">
            <label>Reason <span style="color:var(--color-error)">*</span></label>
            <textarea v-model="reasonText" rows="4" :placeholder="reasonModalTitle === 'Reject Vendor' ? 'Explain why this vendor is being rejected…' : 'Explain why this vendor is being suspended…'" />
          </div>
          <p v-if="reasonError" class="error">{{ reasonError }}</p>
        </div>
        <div class="drawer-footer">
          <button class="btn btn-secondary" @click="closeReasonModal">Cancel</button>
          <button class="btn btn-danger" :disabled="isSaving" @click="submitReason">
            {{ isSaving ? 'Processing…' : reasonModalTitle }}
          </button>
        </div>
      </div>
    </div>

  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '@/lib/api';
import { currentOrgSlug } from '@/stores/auth';
import type { Vendor, VendorMetrics, VendorType, VendorOnboardingStatus } from '@/lib/types';

const router = useRouter();

function org(): string | null { return currentOrgSlug.value; }

// ── State ────────────────────────────────────────────────────────────────────

const vendors     = ref<Vendor[]>([]);
const metrics     = ref<VendorMetrics>({ total: 0, draft: 0, pending_review: 0, approved: 0, rejected: 0, suspended: 0 });
const isLoading   = ref(false);
const error       = ref<string | null>(null);
const page        = ref(1);
const totalPages  = ref(1);
const search      = ref('');
const filterType  = ref('');
const filterStatus = ref('');

// Search debounce
let searchTimer: ReturnType<typeof setTimeout> | null = null;
function onSearch() {
  if (searchTimer) clearTimeout(searchTimer);
  searchTimer = setTimeout(() => load(1), 350);
}

// ── API ──────────────────────────────────────────────────────────────────────

async function load(p = 1) {
  isLoading.value = true;
  error.value = null;
  try {
    const params: Record<string, string | number> = { per_page: 25, page: p };
    if (search.value)       params.search           = search.value;
    if (filterType.value)   params.vendor_type       = filterType.value;
    if (filterStatus.value) params.onboarding_status = filterStatus.value;

    const res = await api<{ data: Vendor[]; meta: { last_page: number; current_page: number } }>(
      'GET', '/api/v1/vendors', { org: org(), params }
    );
    vendors.value = res.data;
    page.value = res.meta?.current_page ?? p;
    totalPages.value = res.meta?.last_page ?? 1;
  } catch (e: any) {
    error.value = e?.message ?? 'Failed to load vendors';
  } finally {
    isLoading.value = false;
  }
}

async function loadMetrics() {
  try {
    const res = await api<{ data: VendorMetrics }>('GET', '/api/v1/vendors/metrics', { org: org() });
    metrics.value = res.data;
  } catch { /* silent */ }
}

onMounted(() => {
  load();
  loadMetrics();
});

// ── Form (Add / Edit) ────────────────────────────────────────────────────────

const showForm  = ref(false);
const editing   = ref<Vendor | null>(null);
const isSaving  = ref(false);
const formError = ref<string | null>(null);

const form = reactive({
  name: '',
  registration_number: '',
  tax_id: '',
  vendor_type: 'supplier' as VendorType,
  category: '',
  description: '',
  primary_contact_name: '',
  primary_contact_designation: '',
  contact_email: '',
  contact_phone: '',
  website_url: '',
  logo_url: '',
  physical_address: '',
  city: '',
  country: 'Kenya',
  notes: '',
  // Partnership & fiscal
  partnership_level: '',
  fiscal_year_start_month: null as number | null,
  renewal_anniversary_date: '',
  renewal_cost: null as number | null,
  renewal_cost_currency: 'USD',
  renewal_reminder_days: 30 as number,
});

function resetForm() {
  form.name = '';
  form.registration_number = '';
  form.tax_id = '';
  form.vendor_type = 'supplier';
  form.category = '';
  form.description = '';
  form.primary_contact_name = '';
  form.primary_contact_designation = '';
  form.contact_email = '';
  form.contact_phone = '';
  form.website_url = '';
  form.logo_url = '';
  form.physical_address = '';
  form.city = '';
  form.country = 'Kenya';
  form.notes = '';
  form.partnership_level = '';
  form.fiscal_year_start_month = null;
  form.renewal_anniversary_date = '';
  form.renewal_cost = null;
  form.renewal_cost_currency = 'USD';
  form.renewal_reminder_days = 30;
}

function openAdd() {
  editing.value = null;
  resetForm();
  formError.value = null;
  showForm.value = true;
}

function openEdit(v: Vendor) {
  editing.value = v;
  form.name = v.name;
  form.registration_number = v.registration_number ?? '';
  form.tax_id = v.tax_id ?? '';
  form.vendor_type = v.vendor_type;
  form.category = v.category ?? '';
  form.description = v.description ?? '';
  form.primary_contact_name = v.primary_contact_name ?? '';
  form.primary_contact_designation = v.primary_contact_designation ?? '';
  form.contact_email = v.contact_email ?? '';
  form.contact_phone = v.contact_phone ?? '';
  form.website_url = v.website_url ?? '';
  form.logo_url = v.logo_url ?? '';
  form.physical_address = v.physical_address ?? '';
  form.city = v.city ?? '';
  form.country = v.country ?? 'Kenya';
  form.notes = v.notes ?? '';
  form.partnership_level = v.partnership_level ?? '';
  form.fiscal_year_start_month = v.fiscal_year_start_month ?? null;
  form.renewal_anniversary_date = v.renewal_anniversary_date ?? '';
  form.renewal_cost = v.renewal_cost ?? null;
  form.renewal_cost_currency = v.renewal_cost_currency ?? 'USD';
  form.renewal_reminder_days = v.renewal_reminder_days ?? 30;
  formError.value = null;
  showForm.value = true;
}

function closeForm() { showForm.value = false; }

function buildBody(): Record<string, unknown> {
  const body: Record<string, unknown> = {
    name:                        form.name.trim(),
    registration_number:         form.registration_number.trim() || null,
    tax_id:                      form.tax_id.trim() || null,
    vendor_type:                 form.vendor_type,
    category:                    form.category.trim() || null,
    description:                 form.description.trim() || null,
    primary_contact_name:        form.primary_contact_name.trim() || null,
    primary_contact_designation: form.primary_contact_designation.trim() || null,
    contact_email:               form.contact_email.trim() || null,
    contact_phone:               form.contact_phone.trim() || null,
    website_url:                 form.website_url.trim() || null,
    logo_url:                    form.logo_url.trim() || null,
    physical_address:            form.physical_address.trim() || null,
    city:                        form.city.trim() || null,
    country:                     form.country.trim() || 'Kenya',
    notes:                       form.notes.trim() || null,
    // Partnership & fiscal
    partnership_level:           form.partnership_level.trim() || null,
    fiscal_year_start_month:     form.fiscal_year_start_month || null,
    renewal_anniversary_date:    form.renewal_anniversary_date || null,
    renewal_cost:                form.renewal_cost,
    renewal_cost_currency:       form.renewal_cost_currency.trim() || 'USD',
    renewal_reminder_days:       form.renewal_reminder_days || 30,
  };
  return body;
}

async function save() {
  if (!form.name.trim()) { formError.value = 'Company name is required.'; return; }
  isSaving.value = true;
  formError.value = null;
  try {
    if (editing.value) {
      const updated = await api<{ data: Vendor }>('PUT', `/api/v1/vendors/${editing.value.id}`, { org: org(), body: buildBody() });
      const idx = vendors.value.findIndex(v => v.id === editing.value!.id);
      if (idx !== -1) vendors.value[idx] = updated.data;
    } else {
      const created = await api<{ data: Vendor }>('POST', '/api/v1/vendors', { org: org(), body: buildBody() });
      vendors.value.unshift(created.data);
      metrics.value.total++;
      metrics.value.draft++;
    }
    closeForm();
    loadMetrics();
  } catch (e: any) {
    formError.value = e?.message ?? 'Failed to save vendor.';
  } finally {
    isSaving.value = false;
  }
}

async function saveAndSubmit() {
  if (!form.name.trim()) { formError.value = 'Company name is required.'; return; }
  isSaving.value = true;
  formError.value = null;
  try {
    const created = await api<{ data: Vendor }>('POST', '/api/v1/vendors', { org: org(), body: buildBody() });
    // Then submit for review
    const res = await api<{ data: Vendor }>('POST', `/api/v1/vendors/${created.data.id}/submit`, { org: org() });
    vendors.value.unshift(res.data);
    closeForm();
    loadMetrics();
  } catch (e: any) {
    formError.value = e?.message ?? 'Failed to save vendor.';
  } finally {
    isSaving.value = false;
  }
}

// ── Inline actions ───────────────────────────────────────────────────────────

async function doSubmit(v: Vendor) {
  try {
    const res = await api<{ data: Vendor }>('POST', `/api/v1/vendors/${v.id}/submit`, { org: org() });
    replaceInList(res.data);
    loadMetrics();
  } catch (e: any) { alert(e?.message ?? 'Failed to submit.'); }
}

async function doApprove(v: Vendor) {
  try {
    const res = await api<{ data: Vendor }>('POST', `/api/v1/vendors/${v.id}/approve`, { org: org() });
    replaceInList(res.data);
    loadMetrics();
  } catch (e: any) { alert(e?.message ?? 'Failed to approve.'); }
}

async function doReactivate(v: Vendor) {
  try {
    const res = await api<{ data: Vendor }>('POST', `/api/v1/vendors/${v.id}/reactivate`, { org: org() });
    replaceInList(res.data);
    loadMetrics();
  } catch (e: any) { alert(e?.message ?? 'Failed to reactivate.'); }
}

async function doDelete(v: Vendor) {
  if (!confirm(`Delete vendor "${v.name}"? This action cannot be undone.`)) return;
  try {
    await api('DELETE', `/api/v1/vendors/${v.id}`, { org: org() });
    vendors.value = vendors.value.filter(x => x.id !== v.id);
    loadMetrics();
  } catch (e: any) { alert(e?.message ?? 'Failed to delete.'); }
}

function replaceInList(updated: Vendor) {
  const idx = vendors.value.findIndex(x => x.id === updated.id);
  if (idx !== -1) vendors.value[idx] = updated;
}

// ── Reason modal (reject / suspend) ─────────────────────────────────────────

const showReasonModal   = ref(false);
const reasonModalTitle  = ref('');
const reasonText        = ref('');
const reasonError       = ref<string | null>(null);
const reasonTarget      = ref<Vendor | null>(null);
const reasonAction      = ref<'reject' | 'suspend' | null>(null);

function openReject(v: Vendor) {
  reasonTarget.value     = v;
  reasonAction.value     = 'reject';
  reasonModalTitle.value = 'Reject Vendor';
  reasonText.value       = '';
  reasonError.value      = null;
  showReasonModal.value  = true;
}

function openSuspend(v: Vendor) {
  reasonTarget.value     = v;
  reasonAction.value     = 'suspend';
  reasonModalTitle.value = 'Suspend Vendor';
  reasonText.value       = '';
  reasonError.value      = null;
  showReasonModal.value  = true;
}

function closeReasonModal() { showReasonModal.value = false; }

async function submitReason() {
  if (!reasonText.value.trim()) { reasonError.value = 'Reason is required.'; return; }
  if (!reasonTarget.value || !reasonAction.value) return;
  isSaving.value = true;
  reasonError.value = null;
  try {
    const endpoint = `/api/v1/vendors/${reasonTarget.value.id}/${reasonAction.value}`;
    const res = await api<{ data: Vendor }>(
      'POST', endpoint, { org: org(), body: { reason: reasonText.value.trim() } }
    );
    replaceInList(res.data);
    closeReasonModal();
    loadMetrics();
  } catch (e: any) {
    reasonError.value = e?.message ?? 'Failed to process action.';
  } finally {
    isSaving.value = false;
  }
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function typeLabel(t: VendorType | string): string {
  const map: Record<string, string> = {
    supplier: 'Supplier',
    service_provider: 'Service Provider',
    contractor: 'Contractor',
    consultant: 'Consultant',
    distributor: 'Distributor',
    partner: 'Partner',
  };
  return map[t] ?? t;
}

function statusLabel(s: VendorOnboardingStatus | string): string {
  const map: Record<string, string> = {
    draft: 'Draft',
    pending_review: 'Pending Review',
    approved: 'Approved',
    rejected: 'Rejected',
    suspended: 'Suspended',
  };
  return map[s] ?? s;
}

function statusBadge(s: VendorOnboardingStatus | string): string {
  const map: Record<string, string> = {
    draft: 'badge-gray',
    pending_review: 'badge-amber',
    approved: 'badge-green',
    rejected: 'badge-red',
    suspended: 'badge-red',
  };
  return map[s] ?? 'badge-gray';
}
</script>

<style scoped>
.form-section-title {
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-semibold);
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--color-text-muted);
  border-bottom: 1px solid var(--color-border);
  padding-bottom: var(--space-2);
  margin-bottom: var(--space-4);
}

/* ── Detail sections ─────────────────────────────────────────────────────────  */
.detail-section {
  margin-bottom: var(--space-6);
}
.detail-section-label {
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-semibold);
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--color-text-muted);
  border-bottom: 1px solid var(--color-border);
  padding-bottom: var(--space-2);
  margin-bottom: var(--space-4);
}
.detail-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: var(--space-3);
}
.detail-item {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.detail-item--full {
  grid-column: 1 / -1;
}
.detail-key {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.detail-val {
  font-size: var(--font-size-sm);
  color: var(--color-text);
}

/* ── Drawer ──────────────────────────────────────────────────────────────────  */
.drawer-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.4);
  z-index: 100;
  display: flex;
  justify-content: flex-end;
}
.drawer-panel {
  background: var(--color-surface);
  width: 100%;
  max-width: 560px;
  height: 100%;
  display: flex;
  flex-direction: column;
  box-shadow: var(--shadow-xl, -4px 0 24px rgba(0,0,0,0.18));
  animation: slideIn 0.25s ease;
}
@keyframes slideIn {
  from { transform: translateX(100%); }
  to   { transform: translateX(0); }
}
.drawer-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  padding: var(--space-5) var(--space-6);
  border-bottom: 1px solid var(--color-border);
  flex-shrink: 0;
}
.drawer-header h3 {
  margin: 0;
  font-size: var(--font-size-lg);
  font-weight: var(--font-weight-semibold);
}
.drawer-body {
  flex: 1;
  overflow-y: auto;
  padding: var(--space-6);
}
.drawer-footer {
  padding: var(--space-4) var(--space-6);
  border-top: 1px solid var(--color-border);
  display: flex;
  justify-content: flex-end;
  gap: var(--space-3);
  flex-shrink: 0;
}

/* ── Font size helpers ───────────────────────────────────────────────────────  */
.font-size-xs { font-size: var(--font-size-xs); }
</style>
