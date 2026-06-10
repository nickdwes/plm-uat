<script setup lang="ts">
import { ref, onMounted, watch } from "vue";
import { api } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import type { TrainingDashboardStats } from "../../lib/types";

const stats = ref<TrainingDashboardStats | null>(null);
const loading = ref(false);
const error = ref("");

function org() { return currentOrgSlug.value; }

async function load() {
  if (!org()) { error.value = "Select an organisation first."; return; }
  loading.value = true;
  error.value = "";
  try {
    const res = await api<TrainingDashboardStats>("GET", "/api/v1/training/dashboard", {
      org: org(),
    });
    stats.value = res;
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to load dashboard.";
  } finally {
    loading.value = false;
  }
}

onMounted(() => load());
watch(currentOrgSlug, () => load());
</script>

<template>
  <div class="view">
    <div class="view-header">
      <div>
        <h2>Training & Competency</h2>
        <p class="subtitle">Learning overview and analytics</p>
      </div>
    </div>

    <div v-if="error" class="alert alert-danger">{{ error }}</div>

    <div v-if="loading" class="loading">Loading dashboard…</div>

    <div v-else-if="stats" class="dashboard-grid">
      <!-- Active Learners KPI -->
      <div class="kpi-card">
        <div class="kpi-icon">👥</div>
        <div class="kpi-content">
          <div class="kpi-value">{{ stats.active_learners }}</div>
          <div class="kpi-label">Active Learners</div>
        </div>
      </div>

      <!-- Completions This Month KPI -->
      <div class="kpi-card">
        <div class="kpi-icon">✅</div>
        <div class="kpi-content">
          <div class="kpi-value">{{ stats.completions_this_month }}</div>
          <div class="kpi-label">Completions This Month</div>
        </div>
      </div>

      <!-- Completion Rate KPI -->
      <div class="kpi-card">
        <div class="kpi-icon">📈</div>
        <div class="kpi-content">
          <div class="kpi-value">{{ stats.completion_rate_percent }}%</div>
          <div class="kpi-label">Completion Rate</div>
        </div>
      </div>

      <!-- Overdue KPI -->
      <div class="kpi-card">
        <div class="kpi-icon">⏰</div>
        <div class="kpi-content">
          <div class="kpi-value">{{ stats.overdue }}</div>
          <div class="kpi-label">Overdue Trainings</div>
        </div>
      </div>

      <!-- Priority Active KPI -->
      <div class="kpi-card">
        <div class="kpi-icon">⭐</div>
        <div class="kpi-content">
          <div class="kpi-value">{{ stats.priority_active }}</div>
          <div class="kpi-label">Priority In Progress</div>
        </div>
      </div>

      <!-- Catalog Total KPI -->
      <div class="kpi-card">
        <div class="kpi-icon">📚</div>
        <div class="kpi-content">
          <div class="kpi-value">{{ stats.catalog_total }}</div>
          <div class="kpi-label">Catalog Items</div>
          <div class="kpi-subtext">{{ stats.catalog_active }} active</div>
        </div>
      </div>
    </div>

    <!-- Quick Actions -->
    <div class="quick-actions">
      <router-link :to="{ name: 'training-catalog' }" class="btn btn-outline">📚 Browse Catalog</router-link>
      <router-link :to="{ name: 'my-training' }" class="btn btn-outline">🎓 My Training</router-link>
      <router-link :to="{ name: 'competencies' }" class="btn btn-outline">🏅 Competencies</router-link>
      <router-link :to="{ name: 'training-enroll' }" class="btn btn-primary">➕ Assign Training</router-link>
    </div>
  </div>
</template>

<style scoped>
.view {
  padding: 24px;
}

.view-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 32px;
}

.view-header h2 {
  font-size: 28px;
  font-weight: 600;
  margin-bottom: 4px;
}

.subtitle {
  color: #666;
  font-size: 14px;
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

.loading {
  text-align: center;
  padding: 32px;
  color: #666;
}

.dashboard-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: 16px;
  margin-bottom: 32px;
}

.kpi-card {
  background: white;
  border: 1px solid #e0e0e0;
  border-radius: 8px;
  padding: 20px;
  display: flex;
  gap: 16px;
  align-items: flex-start;
}

.kpi-icon {
  font-size: 32px;
  line-height: 1;
}

.kpi-content {
  flex: 1;
}

.kpi-value {
  font-size: 28px;
  font-weight: 700;
  line-height: 1;
  margin-bottom: 4px;
}

.kpi-label {
  font-size: 13px;
  color: #666;
  font-weight: 500;
}

.kpi-subtext {
  font-size: 12px;
  color: #999;
  margin-top: 4px;
}

.quick-actions {
  display: flex;
  gap: 12px;
}

.btn {
  padding: 10px 16px;
  border-radius: 6px;
  font-size: 14px;
  font-weight: 500;
  text-decoration: none;
  display: inline-block;
  border: none;
  cursor: pointer;
  transition: all 0.2s;
}

.btn-outline {
  background: white;
  border: 1px solid #d0d0d0;
  color: #333;
}

.btn-outline:hover {
  border-color: #999;
  background: #f9f9f9;
}

.btn-primary {
  background: #3366cc;
  border-color: #3366cc;
  color: white;
  font-weight: 500;
}

.btn-primary:hover { background: #2255bb; }
</style>
