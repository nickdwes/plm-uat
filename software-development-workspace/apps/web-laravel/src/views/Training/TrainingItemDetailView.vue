<script setup lang="ts">
/**
 * Every property the catalog form now captures — provider/vendor, budget,
 * certification detail, skills, who's enrolled — laid out as tabs rather
 * than the single Overview/Metadata split this page shipped with before the
 * catalog form grew. Each tab reads straight off the same TrainingItem
 * response plus two lightweight per-item queries (records, budget requests)
 * — nothing here is a separate report, just this one course's own facts.
 */
import { ref, computed, onMounted } from "vue";
import { useRouter, useRoute } from "vue-router";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import type { TrainingItem, EmployeeTrainingRecord, TrainingBudgetRequestSummary, Paginator } from "../../lib/types";

const router = useRouter();
const route = useRoute();

function org(): string | null { return currentOrgSlug.value; }

const item = ref<TrainingItem | null>(null);
const loading = ref(false);
const error = ref("");
const activeTab = ref<"overview" | "provider" | "certification" | "skills" | "budget" | "enrollments">("overview");

const records = ref<EmployeeTrainingRecord[]>([]);
const recordsLoading = ref(false);
const budgetRequests = ref<TrainingBudgetRequestSummary[]>([]);
const budgetRequestsLoading = ref(false);

async function load(): Promise<void> {
  if (!org()) { error.value = "Select an organisation first."; return; }
  loading.value = true; error.value = "";
  try {
    const res = await api<{ data: TrainingItem }>("GET", `/api/v1/training/catalog/${route.params.id}`, { org: org() });
    item.value = res.data;
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to load item.";
  } finally { loading.value = false; }
}

async function loadRecords(): Promise<void> {
  if (!org() || !item.value) return;
  recordsLoading.value = true;
  try {
    const res = await api<Paginator<EmployeeTrainingRecord>>("GET", "/api/v1/training/records", {
      org: org(), params: { training_item_id: item.value.id, per_page: 100 },
    });
    records.value = res.data;
  } catch { records.value = []; }
  finally { recordsLoading.value = false; }
}

async function loadBudgetRequests(): Promise<void> {
  if (!org() || !item.value) return;
  budgetRequestsLoading.value = true;
  try {
    const res = await api<{ data: TrainingBudgetRequestSummary[] }>("GET", "/api/v1/training/budget-requests", {
      org: org(), params: { training_item_id: item.value.id },
    });
    budgetRequests.value = res.data;
  } catch { budgetRequests.value = []; }
  finally { budgetRequestsLoading.value = false; }
}

async function init(): Promise<void> {
  await load();
  if (item.value) {
    void loadRecords();
    void loadBudgetRequests();
  }
}

function formatCurrency(amount: number | string, currency: string): string {
  // Laravel's decimal:2 cast serialises to a string, not a number.
  return `${currency} ${Number(amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function formatDate(d: string | null): string {
  return d ? new Date(d).toLocaleDateString() : "—";
}

function labelize(value: string): string {
  return value.replace(/_/g, " ").replace(/\b\w/g, c => c.toUpperCase());
}

function statusBadge(status: string): string {
  return { pending_manager: "badge-amber", pending_finance: "badge-amber", approved: "badge-green", rejected: "badge-gray",
    enrolled: "badge-blue", in_progress: "badge-amber", completed: "badge-green", expired: "badge-gray", withdrawn: "badge-gray", failed: "badge-gray" }[status] ?? "badge-gray";
}

async function deleteItem(): Promise<void> {
  if (!item.value) return;
  if (!confirm(`Delete "${item.value.title}"? This cannot be undone.`)) return;
  try {
    await api("DELETE", `/api/v1/training/catalog/${item.value.id}`, { org: org() });
    router.push({ name: "training" });
  } catch (e) {
    error.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Failed to delete item.";
  }
}

const enrolledCount = computed(() => records.value.filter(r => ["enrolled", "in_progress", "completed"].includes(r.status)).length);

onMounted(init);
</script>

<template>
  <div class="tid-page">
    <div class="tid-header">
      <button type="button" class="link-btn" @click="router.push({ name: 'training' })">← Back to Catalog</button>
      <div v-if="item" class="tid-header-actions">
        <button type="button" class="btn btn-secondary" @click="router.push({ name: 'training', query: { edit: item.id } })">Edit</button>
        <button type="button" class="btn btn-danger" @click="deleteItem">Delete</button>
      </div>
    </div>

    <p v-if="error" class="tr-error">{{ error }}</p>
    <p v-else-if="loading" class="tr-loading">Loading item…</p>

    <template v-else-if="item">
      <header class="tid-title-block">
        <span v-if="item.training_code" class="catalog-card-code">{{ item.training_code }}</span>
        <h1 class="tid-title">{{ item.title }}</h1>
        <div class="tid-badges">
          <span v-if="item.is_featured" class="badge badge-amber">Featured</span>
          <span class="badge badge-blue">{{ labelize(item.type) }}</span>
          <span class="badge badge-indigo">{{ labelize(item.delivery) }}</span>
          <span v-if="item.level" class="badge badge-gray">{{ labelize(item.level) }}</span>
          <span :class="['badge', item.is_active ? 'badge-green' : 'badge-gray']">{{ item.is_active ? "Active" : "Inactive" }}</span>
        </div>
      </header>

      <nav class="tr-tabs" role="tablist">
        <button v-for="t in ['overview','provider','certification','skills','budget','enrollments']" :key="t"
                type="button" role="tab" class="tr-tab" :class="{ active: activeTab === t }"
                @click="activeTab = t as typeof activeTab">
          {{ t === 'enrollments' ? `Enrollments (${records.length})` : labelize(t) }}
        </button>
      </nav>

      <!-- Overview -->
      <section v-if="activeTab === 'overview'" class="tid-section">
        <p class="tid-field-group-label">Description</p>
        <p class="tid-desc">{{ item.description || "No description provided." }}</p>

        <p class="tid-field-group-label">Key Information</p>
        <div class="tid-info-grid">
          <div class="tid-info-item"><label>Duration</label><div>{{ item.duration_hours }}h {{ item.duration_minutes }}m{{ item.is_long_course ? " (long course)" : "" }}</div></div>
          <div class="tid-info-item"><label>Provider</label><div>
            <a v-if="item.provider_url" :href="item.provider_url" target="_blank" rel="noopener" class="tid-link">{{ item.provider_name || item.provider_url }}</a>
            <span v-else>{{ item.provider_name || "—" }}</span>
          </div></div>
          <div class="tid-info-item"><label>Estimated Cost</label><div>{{ formatCurrency(item.estimated_cost, item.cost_currency) }}</div></div>
          <div class="tid-info-item"><label>Capacity</label><div>{{ enrolledCount }} enrolled{{ item.max_enrollments ? ` / ${item.max_enrollments} max` : " (no cap)" }}</div></div>
          <div class="tid-info-item"><label>Technical</label><div>{{ item.is_technical ? "Yes" : "No" }}</div></div>
          <div class="tid-info-item"><label>Proctored Exam</label><div>{{ item.requires_proctored_exam ? "Required" : "Not required" }}</div></div>
          <div class="tid-info-item"><label>Requires Approval</label><div>{{ item.requires_approval ? "Yes" : "No" }}</div></div>
          <div class="tid-info-item"><label>Created</label><div>{{ formatDate(item.created_at) }}</div></div>
        </div>

        <div v-if="item.tags && item.tags.length" class="tid-chip-row">
          <span v-for="tag in item.tags" :key="tag" class="badge badge-gray">{{ tag }}</span>
        </div>

        <div class="tid-actions">
          <RouterLink :to="{ name: 'my-training' }" class="btn btn-primary">Enroll Now</RouterLink>
        </div>
      </section>

      <!-- Provider -->
      <section v-else-if="activeTab === 'provider'" class="tid-section">
        <p v-if="!item.training_provider_type" class="tr-empty">No training provider set for this item.</p>
        <template v-else>
          <div class="tid-info-grid">
            <div class="tid-info-item"><label>Provider Type</label><div>{{ labelize(item.training_provider_type) }}</div></div>
            <div class="tid-info-item" v-if="item.training_provider_type === 'certifying_body'">
              <label>Certifying Professional Body</label><div>{{ item.certifying_body?.name ?? "—" }}</div>
            </div>
            <div class="tid-info-item" v-else><label>Vendor</label><div>{{ item.vendor_partner?.name ?? "—" }}</div></div>
          </div>

          <template v-if="item.training_provider_type === 'vendor_oem'">
            <p class="tid-field-group-label">Products Covered</p>
            <div v-if="item.products && item.products.length" class="tid-chip-row">
              <span v-for="p in item.products" :key="p.id" class="badge badge-indigo">{{ p.name }}</span>
            </div>
            <p v-else class="tr-empty">No products linked.</p>

            <p class="tid-field-group-label">OEM Partnership Compliance</p>
            <div v-if="item.oem_compliance" class="tid-info-grid">
              <div class="tid-info-item"><label>Required Headcount</label><div>{{ item.oem_compliance.required_headcount }}</div></div>
              <div class="tid-info-item" v-if="item.oem_compliance.notes"><label>Notes</label><div>{{ item.oem_compliance.notes }}</div></div>
            </div>
            <p v-else class="tr-empty">Not required for {{ item.vendor_partner?.name ?? "the vendor's" }} partnership compliance.</p>
          </template>
        </template>
      </section>

      <!-- Certification -->
      <section v-else-if="activeTab === 'certification'" class="tid-section">
        <p v-if="!item.certificate_issued" class="tr-empty">No certificate is issued on completion of this training.</p>
        <div v-else class="tid-info-grid">
          <div class="tid-info-item"><label>Certification Name</label><div>{{ item.certification_name || "—" }}</div></div>
          <div class="tid-info-item"><label>Exam Code</label><div>{{ item.exam_code || "—" }}</div></div>
          <div class="tid-info-item"><label>Exam Duration</label><div>{{ item.exam_duration || "—" }}</div></div>
          <div class="tid-info-item"><label>Passing Score</label><div>{{ item.passing_score != null ? `${item.passing_score}%` : "—" }}</div></div>
          <div class="tid-info-item"><label>Exam Provider</label><div>{{ item.exam_provider || "—" }}</div></div>
          <div class="tid-info-item"><label>Validity</label><div>{{ item.validity_years ? `${item.validity_years} year(s)` : "—" }}</div></div>
          <div class="tid-info-item"><label>Renewal Required</label><div>{{ item.renewal_required ? "Yes" : "No" }}</div></div>
          <div class="tid-info-item"><label>CPE Credits</label><div>{{ item.cpe_credits ?? "—" }}</div></div>
        </div>
      </section>

      <!-- Skills -->
      <section v-else-if="activeTab === 'skills'" class="tid-section">
        <p v-if="!item.competencies || item.competencies.length === 0" class="tr-empty">
          No skills recorded for this training yet.
        </p>
        <div v-else class="tid-competency-grid">
          <div v-for="comp in item.competencies" :key="comp.id" class="tid-competency-card">
            <div class="tid-competency-name">{{ comp.name }}</div>
            <div v-if="comp.category" class="badge badge-indigo">{{ comp.category }}</div>
          </div>
        </div>
      </section>

      <!-- Budget -->
      <section v-else-if="activeTab === 'budget'" class="tid-section">
        <p class="tid-field-group-label">Catalog Budget</p>
        <div class="tid-info-grid">
          <div class="tid-info-item"><label>Budgeted</label><div>{{ item.is_budgeted ? "Yes" : "No" }}</div></div>
          <div class="tid-info-item" v-if="item.is_budgeted"><label>Budget Amount</label><div>{{ formatCurrency(item.budget_amount ?? 0, item.cost_currency) }}</div></div>
          <div class="tid-info-item"><label>Estimated Cost</label><div>{{ formatCurrency(item.estimated_cost, item.cost_currency) }}</div></div>
        </div>

        <p class="tid-field-group-label">Budget Requests Referencing This Training</p>
        <p v-if="budgetRequestsLoading" class="tr-loading">Loading…</p>
        <p v-else-if="budgetRequests.length === 0" class="tr-empty">No budget requests reference this training yet.</p>
        <div v-else class="tr-budget-table-wrap">
          <table class="tr-budget-table">
            <thead><tr><th>Mode</th><th>Subject</th><th>Total</th><th>Status</th><th>Submitted</th></tr></thead>
            <tbody>
              <tr v-for="req in budgetRequests" :key="req.id">
                <td>{{ labelize(req.mode) }}</td>
                <td>{{ req.mode === 'by_training' ? (req.training_item?.title ?? '—') : (req.employee?.name ?? '—') }}</td>
                <td>{{ req.currency }} {{ Number(req.total_amount).toLocaleString() }}</td>
                <td><span class="badge" :class="statusBadge(req.status)">{{ labelize(req.status) }}</span></td>
                <td>{{ formatDate(req.created_at) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Enrollments -->
      <section v-else-if="activeTab === 'enrollments'" class="tid-section">
        <p v-if="recordsLoading" class="tr-loading">Loading…</p>
        <p v-else-if="records.length === 0" class="tr-empty">Nobody is enrolled in this training yet.</p>
        <div v-else class="tr-budget-table-wrap">
          <table class="tr-budget-table">
            <thead><tr><th>Employee</th><th>Status</th><th>Progress</th><th>Started</th><th>Completed</th><th>Score</th><th>Certificate #</th><th>Expiry</th></tr></thead>
            <tbody>
              <tr v-for="r in records" :key="r.id">
                <td>{{ r.employee?.name ?? "—" }}</td>
                <td><span class="badge" :class="statusBadge(r.status)">{{ labelize(r.status) }}</span></td>
                <td>{{ r.progress_percent }}%</td>
                <td>{{ formatDate(r.started_at) }}</td>
                <td>{{ formatDate(r.completed_at) }}</td>
                <td>{{ r.score ?? "—" }}</td>
                <td>{{ r.certificate_number ?? "—" }}</td>
                <td>{{ formatDate(r.certificate_expiry_date) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </div>
</template>

<style scoped>
.tid-page {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.tid-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.tid-header-actions {
  display: flex;
  gap: var(--space-3);
}

.tid-title-block {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.tid-title {
  font-size: var(--font-size-xl);
  font-weight: var(--font-weight-bold);
  color: var(--color-text);
}

.tid-badges {
  display: flex;
  gap: var(--space-2);
  flex-wrap: wrap;
}

.tid-section {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  padding: var(--space-5);
}

.tid-field-group-label {
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-bold);
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--color-text-muted);
  margin: var(--space-4) 0 var(--space-2);
}

.tid-field-group-label:first-child { margin-top: 0; }

.tid-desc {
  font-size: var(--font-size-sm);
  color: var(--color-text);
  line-height: var(--line-height-base);
}

.tid-info-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: var(--space-4);
}

.tid-info-item label {
  display: block;
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.03em;
  margin-bottom: var(--space-1);
}

.tid-info-item div {
  font-size: var(--font-size-sm);
  color: var(--color-text);
}

.tid-link {
  color: var(--color-brand-base);
  text-decoration: none;
}

.tid-link:hover { text-decoration: underline; }

.tid-chip-row {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  margin-top: var(--space-2);
}

.tid-actions {
  margin-top: var(--space-5);
  padding-top: var(--space-4);
  border-top: 1px solid var(--color-border);
}

.tid-competency-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: var(--space-3);
}

.tid-competency-card {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: var(--space-3);
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  align-items: flex-start;
}

.tid-competency-name {
  font-weight: var(--font-weight-medium);
  color: var(--color-text);
  font-size: var(--font-size-sm);
}

/* ── Shared with other Training views — scoped styles don't cross
   component boundaries, so these are redefined here. ── */
.link-btn {
  background: none;
  border: none;
  padding: 0;
  font-size: var(--font-size-xs);
  color: var(--color-brand-base);
  cursor: pointer;
}

.tr-error {
  color: var(--color-error);
  font-size: var(--font-size-sm);
}

.tr-loading, .tr-empty {
  color: var(--color-text-muted);
  font-size: var(--font-size-sm);
  padding: var(--space-4) 0;
  text-align: center;
}

.tr-tabs {
  display: flex;
  gap: var(--space-2);
  border-bottom: 1px solid var(--color-border);
  overflow-x: auto;
}

.tr-tab {
  background: none;
  border: none;
  padding: var(--space-3) var(--space-4);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  color: var(--color-text-muted);
  cursor: pointer;
  border-bottom: 2px solid transparent;
  margin-bottom: -1px;
  flex-shrink: 0;
  white-space: nowrap;
}

.tr-tab.active {
  color: var(--color-brand-base);
  border-bottom-color: var(--color-brand-base);
}

.catalog-card-code {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  font-weight: var(--font-weight-medium);
}

.tr-budget-table-wrap {
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  overflow-x: auto;
}

.tr-budget-table {
  width: 100%;
  border-collapse: collapse;
  font-size: var(--font-size-sm);
}

.tr-budget-table th {
  text-align: left;
  padding: var(--space-2) var(--space-3);
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  border-bottom: 1px solid var(--color-border);
  background: var(--color-surface-sunken);
}

.tr-budget-table td {
  padding: var(--space-2) var(--space-3);
  border-bottom: 1px solid var(--color-border);
}

.tr-budget-table tr:last-child td {
  border-bottom: none;
}

.btn-danger {
  background: var(--color-danger-subtle);
  color: var(--color-error);
  border: 1px solid var(--color-error);
}

@media (max-width: 640px) {
  .tid-header { flex-direction: column; align-items: stretch; gap: var(--space-3); }
}
</style>
