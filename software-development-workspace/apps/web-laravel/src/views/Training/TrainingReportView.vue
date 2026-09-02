<script setup lang="ts">
/**
 * Everything the Training module has learned about itself in one place —
 * catalog, enrolment, budget and certification expiry all come from the
 * same App\Services\Training\TrainingReport that the export workbook uses,
 * so the numbers on screen and in the download can never disagree.
 */
import { ref, computed, onMounted } from "vue";
import { useRouter } from "vue-router";
import { api } from "../../lib/api";
import { currentOrgSlug, getToken } from "../../stores/auth";
import type { TrainingReportSummary } from "../../lib/types";

const router = useRouter();
function org(): string | null { return currentOrgSlug.value; }

const report = ref<TrainingReportSummary | null>(null);
const loading = ref(false);
const error = ref("");
const exporting = ref(false);
const exportError = ref("");

async function load(): Promise<void> {
  if (!org()) { error.value = "Select an organisation first."; return; }
  loading.value = true; error.value = "";
  try {
    const res = await api<{ data: TrainingReportSummary }>("GET", "/api/v1/reports/training/summary", { org: org() });
    report.value = res.data;
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Could not load the training report.";
  } finally { loading.value = false; }
}

function labelize(value: string): string {
  return value.replace(/_/g, " ").replace(/\b\w/g, c => c.toUpperCase());
}

function maxCount(rows: { count: number }[]): number {
  return Math.max(1, ...rows.map(r => r.count));
}

async function exportXlsx(): Promise<void> {
  exporting.value = true; exportError.value = "";
  try {
    const base = (import.meta.env.VITE_LARAVEL_API_URL ?? "").replace(/\/$/, "");
    const headers: Record<string, string> = {};
    const token = getToken();
    if (token) headers["Authorization"] = `Bearer ${token}`;
    const slug = org();
    if (slug) headers["X-Organization"] = slug;

    const res = await fetch(`${base}/api/v1/reports/training/export`, { method: "GET", headers });
    if (!res.ok) throw new Error(`Export failed (${res.status}).`);

    const stamp = new Date().toISOString().slice(0, 10);
    const url = URL.createObjectURL(await res.blob());
    const link = document.createElement("a");
    link.href = url;
    link.download = `training-report-${stamp}.xlsx`;
    link.click();
    URL.revokeObjectURL(url);
  } catch (e) {
    exportError.value = e instanceof Error ? e.message : "Export failed.";
  } finally { exporting.value = false; }
}

const budgetVariance = computed(() => (row: { total_budgeted_amount: number; total_estimated_cost: number }) =>
  row.total_budgeted_amount - row.total_estimated_cost);

onMounted(load);
</script>

<template>
  <div class="rpt-page">
    <header class="rpt-header">
      <div>
        <button type="button" class="link-btn" @click="router.push({ name: 'training' })">← Back to Training</button>
        <h2>Training Report</h2>
        <p class="rpt-lead">A comprehensive view of the catalog, enrolment, budget and certifications.</p>
      </div>
      <div class="tr-header-actions">
        <p v-if="exportError" class="tr-error" style="margin:0;">{{ exportError }}</p>
        <button type="button" class="btn btn-primary" :disabled="exporting || !report" @click="exportXlsx">
          {{ exporting ? "Building…" : "Export to Excel" }}
        </button>
      </div>
    </header>

    <p v-if="error" class="tr-error">{{ error }}</p>
    <p v-else-if="loading" class="tr-loading">Loading the report…</p>

    <template v-else-if="report">
      <!-- Overview -->
      <section class="rpt-section">
        <h3 class="rpt-section-title">Overview</h3>
        <div class="tr-stats">
          <div class="tr-stat-card">
            <span class="tr-stat-label">Active Learners</span>
            <span class="tr-stat-value">{{ report.overview.active_learners }}</span>
          </div>
          <div class="tr-stat-card">
            <span class="tr-stat-label">Completions This Month</span>
            <span class="tr-stat-value">{{ report.overview.completions_this_month }}</span>
          </div>
          <div class="tr-stat-card">
            <span class="tr-stat-label">Overdue Trainings</span>
            <span class="tr-stat-value" :class="{ 'tr-stat-warn': report.overview.overdue > 0 }">{{ report.overview.overdue }}</span>
          </div>
          <div class="tr-stat-card">
            <span class="tr-stat-label">Completion Rate</span>
            <span class="tr-stat-value">{{ report.overview.completion_rate_percent }}%</span>
          </div>
          <div class="tr-stat-card">
            <span class="tr-stat-label">Catalog Items</span>
            <span class="tr-stat-value">{{ report.overview.catalog_active }} / {{ report.overview.catalog_total }}</span>
          </div>
          <div class="tr-stat-card">
            <span class="tr-stat-label">Competencies Tracked</span>
            <span class="tr-stat-value">{{ report.competencies_count }}</span>
          </div>
        </div>
      </section>

      <!-- Catalog breakdown -->
      <section class="rpt-section">
        <h3 class="rpt-section-title">Catalog Breakdown</h3>
        <div class="rpt-bar-grid">
          <div class="rpt-bar-block">
            <p class="rpt-bar-heading">By Type</p>
            <p v-if="report.catalog_by_type.length === 0" class="tr-empty">No catalog items yet.</p>
            <div v-for="b in report.catalog_by_type" :key="b.label" class="rpt-bar-row">
              <span class="rpt-bar-label">{{ labelize(b.label) }}</span>
              <div class="rpt-bar-track"><div class="rpt-bar-fill" :style="{ width: (b.count / maxCount(report.catalog_by_type) * 100) + '%' }"></div></div>
              <span class="rpt-bar-count">{{ b.count }}</span>
            </div>
          </div>
          <div class="rpt-bar-block">
            <p class="rpt-bar-heading">By Delivery</p>
            <p v-if="report.catalog_by_delivery.length === 0" class="tr-empty">No catalog items yet.</p>
            <div v-for="b in report.catalog_by_delivery" :key="b.label" class="rpt-bar-row">
              <span class="rpt-bar-label">{{ labelize(b.label) }}</span>
              <div class="rpt-bar-track"><div class="rpt-bar-fill" :style="{ width: (b.count / maxCount(report.catalog_by_delivery) * 100) + '%' }"></div></div>
              <span class="rpt-bar-count">{{ b.count }}</span>
            </div>
          </div>
          <div class="rpt-bar-block">
            <p class="rpt-bar-heading">By Provider Type</p>
            <p v-if="report.catalog_by_provider_type.length === 0" class="tr-empty">No catalog items yet.</p>
            <div v-for="b in report.catalog_by_provider_type" :key="b.label" class="rpt-bar-row">
              <span class="rpt-bar-label">{{ labelize(b.label) }}</span>
              <div class="rpt-bar-track"><div class="rpt-bar-fill" :style="{ width: (b.count / maxCount(report.catalog_by_provider_type) * 100) + '%' }"></div></div>
              <span class="rpt-bar-count">{{ b.count }}</span>
            </div>
          </div>
        </div>

        <p v-if="report.top_vendors.length" class="rpt-bar-heading" style="margin-top:var(--space-4);">Top Vendors</p>
        <div v-if="report.top_vendors.length" class="rpt-bar-block">
          <div v-for="v in report.top_vendors" :key="v.vendor" class="rpt-bar-row">
            <span class="rpt-bar-label">{{ v.vendor }}</span>
            <div class="rpt-bar-track"><div class="rpt-bar-fill" :style="{ width: (v.count / maxCount(report.top_vendors) * 100) + '%' }"></div></div>
            <span class="rpt-bar-count">{{ v.count }}</span>
          </div>
        </div>
      </section>

      <!-- Enrollment -->
      <section class="rpt-section">
        <h3 class="rpt-section-title">Enrollment by Status</h3>
        <p v-if="report.enrollment_by_status.length === 0" class="tr-empty">No enrollments recorded yet.</p>
        <div v-else class="rpt-bar-block">
          <div v-for="b in report.enrollment_by_status" :key="b.label" class="rpt-bar-row">
            <span class="rpt-bar-label">{{ labelize(b.label) }}</span>
            <div class="rpt-bar-track"><div class="rpt-bar-fill" :style="{ width: (b.count / maxCount(report.enrollment_by_status) * 100) + '%' }"></div></div>
            <span class="rpt-bar-count">{{ b.count }}</span>
          </div>
        </div>
      </section>

      <!-- Budget -->
      <section class="rpt-section">
        <h3 class="rpt-section-title">Budget</h3>
        <p v-if="report.budget.length === 0" class="tr-empty">No active catalog items to budget for yet.</p>
        <div v-else class="tr-stats" style="margin-bottom:var(--space-4);">
          <template v-for="row in report.budget" :key="row.currency">
            <div class="tr-stat-card">
              <span class="tr-stat-label">Budgeted ({{ row.currency }})</span>
              <span class="tr-stat-value">{{ row.currency }} {{ Number(row.total_budgeted_amount).toLocaleString() }}</span>
            </div>
            <div class="tr-stat-card">
              <span class="tr-stat-label">Estimated Cost ({{ row.currency }})</span>
              <span class="tr-stat-value">{{ row.currency }} {{ Number(row.total_estimated_cost).toLocaleString() }}</span>
            </div>
            <div class="tr-stat-card">
              <span class="tr-stat-label">Budgeted − Estimated ({{ row.currency }})</span>
              <span class="tr-stat-value" :class="{ 'tr-stat-warn': budgetVariance(row) < 0 }">
                {{ row.currency }} {{ budgetVariance(row).toLocaleString() }}
              </span>
            </div>
          </template>
        </div>

        <p class="rpt-bar-heading">Budget Requests by Status</p>
        <p v-if="report.budget_requests_by_status.length === 0" class="tr-empty">No budget requests submitted yet.</p>
        <div v-else class="tr-budget-table-wrap">
          <table class="tr-budget-table">
            <thead><tr><th>Status</th><th>Currency</th><th>Count</th><th>Total</th></tr></thead>
            <tbody>
              <tr v-for="row in report.budget_requests_by_status" :key="row.status + row.currency">
                <td>{{ labelize(row.status) }}</td>
                <td>{{ row.currency }}</td>
                <td>{{ row.count }}</td>
                <td>{{ row.currency }} {{ Number(row.total).toLocaleString() }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Certifications -->
      <section class="rpt-section">
        <h3 class="rpt-section-title">Certifications</h3>
        <div class="tr-stats">
          <div class="tr-stat-card">
            <span class="tr-stat-label">Certificate-Issuing Items</span>
            <span class="tr-stat-value">{{ report.certifications.certificate_issuing_items }}</span>
          </div>
          <div class="tr-stat-card">
            <span class="tr-stat-label">Certificates Held</span>
            <span class="tr-stat-value">{{ report.certifications.certificates_held }}</span>
          </div>
          <div class="tr-stat-card">
            <span class="tr-stat-label">Expiring Within 90 Days</span>
            <span class="tr-stat-value" :class="{ 'tr-stat-warn': report.certifications.expiring_within_90_days > 0 }">
              {{ report.certifications.expiring_within_90_days }}
            </span>
          </div>
          <div class="tr-stat-card">
            <span class="tr-stat-label">Expired</span>
            <span class="tr-stat-value" :class="{ 'tr-stat-warn': report.certifications.expired > 0 }">
              {{ report.certifications.expired }}
            </span>
          </div>
        </div>
      </section>
    </template>
  </div>
</template>

<style scoped>
.rpt-page {
  display: flex;
  flex-direction: column;
  gap: var(--space-5);
}

.rpt-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--space-4);
  flex-wrap: wrap;
}

.rpt-header h2 {
  font-size: var(--font-size-xl);
  font-weight: var(--font-weight-bold);
  color: var(--color-text);
  margin-top: var(--space-2);
}

.rpt-lead {
  font-size: var(--font-size-sm);
  color: var(--color-text-muted);
  margin-top: var(--space-1);
}

.rpt-section {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  padding: var(--space-5);
}

.rpt-section-title {
  font-size: var(--font-size-md);
  font-weight: var(--font-weight-bold);
  color: var(--color-text);
  margin-bottom: var(--space-4);
}

.rpt-bar-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: var(--space-5);
}

.rpt-bar-heading {
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-bold);
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--color-text-muted);
  margin-bottom: var(--space-2);
}

.rpt-bar-row {
  display: grid;
  grid-template-columns: 120px 1fr 40px;
  align-items: center;
  gap: var(--space-2);
  margin-bottom: var(--space-2);
  font-size: var(--font-size-sm);
}

.rpt-bar-label {
  color: var(--color-text-muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.rpt-bar-track {
  height: 10px;
  background: var(--color-border);
  border-radius: var(--radius-full, 999px);
  overflow: hidden;
}

.rpt-bar-fill {
  height: 100%;
  background: var(--color-brand-base);
  border-radius: var(--radius-full, 999px);
}

.rpt-bar-count {
  text-align: right;
  font-weight: var(--font-weight-medium);
  color: var(--color-text);
}

@media (max-width: 640px) {
  .rpt-bar-row { grid-template-columns: 90px 1fr 30px; }
}

/* ── Shared with TrainingDashboardView's visual language ────────────────
   Vue's scoped styles don't cross component boundaries, so these are
   redefined here rather than inherited — same tokens, same class names. */
.tr-header-actions {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  flex-wrap: wrap;
}

.tr-error {
  color: var(--color-error);
  font-size: var(--font-size-sm);
  margin-bottom: var(--space-3);
}

.tr-loading, .tr-empty {
  color: var(--color-text-muted);
  font-size: var(--font-size-sm);
  padding: var(--space-4) 0;
  text-align: center;
}

.tr-stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: var(--space-4);
}

.tr-stat-card {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  padding: var(--space-4) var(--space-5);
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  box-shadow: var(--shadow-sm);
}

.tr-stat-label {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  font-weight: var(--font-weight-medium);
}

.tr-stat-value {
  font-size: var(--font-size-xl);
  font-weight: var(--font-weight-bold);
  color: var(--color-text);
}

.tr-stat-warn {
  color: var(--color-error);
}

.link-btn {
  background: none;
  border: none;
  padding: 0;
  font-size: var(--font-size-xs);
  color: var(--color-brand-base);
  cursor: pointer;
  margin-bottom: var(--space-2);
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
</style>
