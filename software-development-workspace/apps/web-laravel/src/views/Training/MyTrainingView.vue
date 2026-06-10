<script setup lang="ts">
import { ref, computed, onMounted, watch } from "vue";
import { useRouter } from "vue-router";
import { api } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import type { EmployeeTrainingRecord, Paginator } from "../../lib/types";

const router = useRouter();

const records = ref<EmployeeTrainingRecord[]>([]);
const total = ref(0);
const lastPage = ref(1);
const page = ref(1);
const activeTab = ref("overview");
const loading = ref(false);
const error = ref("");
const perPage = 20;

const hasPrev = computed(() => page.value > 1);
const hasNext = computed(() => page.value < lastPage.value);

const tabs = [
  { id: "overview", label: "Overview" },
  { id: "active", label: "Active" },
  { id: "completed", label: "Completed" },
  { id: "priority", label: "Priority" },
];

function org() { return currentOrgSlug.value; }

async function load(p = 1) {
  if (!org()) { error.value = "Select an organisation first."; return; }
  loading.value = true;
  error.value = "";
  page.value = p;
  try {
    const filters: Record<string, string> = {};
    if (activeTab.value !== "overview") {
      if (activeTab.value === "active") {
        filters.status = "in_progress,enrolled";
      } else if (activeTab.value === "completed") {
        filters.status = "completed";
      } else if (activeTab.value === "priority") {
        filters.is_priority = "true";
      }
    }

    const res = await api<Paginator<EmployeeTrainingRecord>>("GET", "/api/v1/training/records/my", {
      org: org(),
      params: { page: p, per_page: perPage, ...filters },
    });
    records.value = res.data;
    total.value = res.meta.total;
    lastPage.value = res.meta.last_page;
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to load training records.";
  } finally {
    loading.value = false;
  }
}

function setTab(tab: string) {
  activeTab.value = tab;
  load(1);
}

function statusBadgeClass(status: string) {
  return "badge-" + status.replace(/_/g, "-");
}

function statusLabel(status: string) {
  const labels: Record<string, string> = {
    enrolled: "Enrolled",
    in_progress: "In Progress",
    completed: "Completed",
    expired: "Expired",
    withdrawn: "Withdrawn",
    failed: "Failed",
  };
  return labels[status] || status;
}

function progressColor(percent: number) {
  if (percent < 33) return "#ef4444";
  if (percent < 66) return "#f59e0b";
  return "#10b981";
}

onMounted(() => load(1));
watch(currentOrgSlug, () => load(1));
</script>

<template>
  <div class="view">
    <div class="view-header">
      <div>
        <button class="back-btn" @click="router.back()">← Back</button>
        <h2>My Training</h2>
      </div>
      <router-link :to="{ name: 'training-catalog' }" class="btn btn-outline">Browse Catalog</router-link>
    </div>

    <div v-if="error" class="alert alert-danger">{{ error }}</div>

    <!-- Tabs -->
    <div class="tabs">
      <button
        v-for="tab in tabs"
        :key="tab.id"
        class="tab-button"
        :class="{ active: activeTab === tab.id }"
        @click="setTab(tab.id)"
      >
        {{ tab.label }}
      </button>
    </div>

    <!-- Results Info -->
    <div class="results-info">
      Showing {{ records.length === 0 ? 0 : (page - 1) * perPage + 1 }}-{{ Math.min(page * perPage, total) }} of {{ total }}
    </div>

    <!-- Loading -->
    <div v-if="loading" class="loading">Loading training records…</div>

    <!-- Records List -->
    <div v-else-if="records.length > 0" class="records-list">
      <div
        v-for="record in records"
        :key="record.id"
        class="record-card"
        @click="router.push({ name: 'training-record-detail', params: { id: record.id } })"
      >
        <div class="record-header">
          <div class="record-title">
            <h3>{{ record.training_item?.title || "(Unknown)" }}</h3>
            <div class="record-meta">
              <span class="badge" :class="statusBadgeClass(record.status)">
                {{ statusLabel(record.status) }}
              </span>
              <span v-if="record.is_priority" class="badge badge-priority">⭐ Priority</span>
            </div>
          </div>
          <div class="duration-info">
            <div v-if="record.training_item?.duration_hours" class="duration">
              {{ record.training_item.duration_hours }}h
            </div>
          </div>
        </div>

        <!-- Progress Bar -->
        <div class="progress-section">
          <div class="progress-label">
            <span>Progress</span>
            <span class="progress-percent">{{ record.progress_percent }}%</span>
          </div>
          <div class="progress-bar">
            <div
              class="progress-fill"
              :style="{
                width: record.progress_percent + '%',
                backgroundColor: progressColor(record.progress_percent),
              }"
            />
          </div>
        </div>

        <!-- Metadata -->
        <div class="record-footer">
          <div class="footer-item">
            <span class="label">Type</span>
            <span>{{ record.training_item?.type || "-" }}</span>
          </div>
          <div class="footer-item">
            <span class="label">Started</span>
            <span>{{ record.started_at ? new Date(record.started_at).toLocaleDateString() : "Not started" }}</span>
          </div>
          <div class="footer-item">
            <span class="label">Due</span>
            <span>{{ record.due_date ? new Date(record.due_date).toLocaleDateString() : "No due date" }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- No Results -->
    <div v-else class="no-results">
      <p>No training records found for this view.</p>
      <router-link :to="{ name: 'training-catalog' }" class="btn btn-primary">
        Browse Catalog
      </router-link>
    </div>

    <!-- Pagination -->
    <div v-if="records.length > 0" class="pagination">
      <button :disabled="!hasPrev" @click="load(page - 1)" class="btn">← Previous</button>
      <span class="page-info">Page {{ page }} of {{ lastPage }}</span>
      <button :disabled="!hasNext" @click="load(page + 1)" class="btn">Next →</button>
    </div>
  </div>
</template>

<style scoped>
.view {
  padding: 24px;
}

.view-header {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  margin-bottom: 24px;
}

.back-btn {
  background: none;
  border: none;
  font-size: 14px;
  color: #666;
  cursor: pointer;
  margin-bottom: 8px;
  display: block;
}

.btn-outline {
  padding: 8px 16px;
  border-radius: 6px;
  border: 1px solid #3366cc;
  color: #3366cc;
  background: white;
  font-size: 13px;
  cursor: pointer;
  text-decoration: none;
  font-weight: 500;
}

.btn-outline:hover { background: #f0f4ff; }

.view-header h2 {
  font-size: 28px;
  font-weight: 600;
  margin: 0;
}

.alert {
  padding: 12px 16px;
  border-radius: 6px;
  margin-bottom: 16px;
}

.alert-danger {
  background-color: #fee;
  color: #c33;
  border: 1px solid #f99;
}

.tabs {
  display: flex;
  gap: 0;
  border-bottom: 1px solid #e0e0e0;
  margin-bottom: 20px;
  overflow-x: auto;
}

.tab-button {
  background: none;
  border: none;
  padding: 12px 16px;
  font-size: 14px;
  font-weight: 500;
  color: #666;
  cursor: pointer;
  border-bottom: 2px solid transparent;
  margin-bottom: -1px;
  white-space: nowrap;
}

.tab-button.active {
  color: #000;
  border-bottom-color: #000;
}

.results-info {
  font-size: 13px;
  color: #666;
  margin-bottom: 16px;
}

.loading {
  text-align: center;
  padding: 32px;
  color: #666;
}

.records-list {
  display: grid;
  gap: 12px;
  margin-bottom: 24px;
}

.record-card {
  background: white;
  border: 1px solid #e0e0e0;
  border-radius: 8px;
  padding: 16px;
  cursor: pointer;
  transition: all 0.2s;
}

.record-card:hover {
  border-color: #999;
  box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.record-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 16px;
}

.record-title {
  flex: 1;
}

.record-title h3 {
  font-size: 16px;
  font-weight: 600;
  margin: 0 0 8px 0;
}

.record-meta {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.badge {
  font-size: 11px;
  padding: 4px 8px;
  border-radius: 4px;
  font-weight: 500;
}

.badge-enrolled { background: #dbeafe; color: #0284c7; }
.badge-in-progress { background: #fef3c7; color: #92400e; }
.badge-completed { background: #dcfce7; color: #15803d; }
.badge-expired { background: #e5e7eb; color: #374151; }
.badge-withdrawn { background: #fecdd3; color: #991b1b; }
.badge-failed { background: #fee2e2; color: #b91c1c; }
.badge-priority { background: #fff4e6; color: #d97706; }

.duration-info {
  text-align: right;
}

.duration {
  font-size: 13px;
  font-weight: 600;
  color: #666;
}

.progress-section {
  margin-bottom: 16px;
}

.progress-label {
  display: flex;
  justify-content: space-between;
  font-size: 12px;
  color: #666;
  margin-bottom: 6px;
}

.progress-percent {
  font-weight: 600;
  color: #000;
}

.progress-bar {
  height: 8px;
  background: #e0e0e0;
  border-radius: 4px;
  overflow: hidden;
}

.progress-fill {
  height: 100%;
  transition: width 0.3s;
}

.record-footer {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 12px;
  padding-top: 12px;
  border-top: 1px solid #f0f0f0;
  font-size: 12px;
}

.footer-item {
  display: flex;
  flex-direction: column;
}

.label {
  color: #999;
  font-weight: 500;
  text-transform: uppercase;
  font-size: 10px;
  letter-spacing: 0.5px;
  margin-bottom: 4px;
}

.footer-item span:last-child {
  color: #333;
  font-size: 13px;
}

.no-results {
  text-align: center;
  padding: 48px 24px;
  color: #999;
}

.btn {
  padding: 10px 16px;
  border-radius: 6px;
  font-size: 14px;
  font-weight: 500;
  text-decoration: none;
  display: inline-block;
  background: white;
  border: 1px solid #ddd;
  cursor: pointer;
}

.btn-primary {
  background: #000;
  color: white;
  border: none;
}

.pagination {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 12px;
  padding-top: 16px;
  border-top: 1px solid #e0e0e0;
}

.btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.page-info {
  font-size: 13px;
  color: #666;
}
</style>
