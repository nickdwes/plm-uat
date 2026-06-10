<script setup lang="ts">
import { ref, onMounted } from "vue";
import { useRouter, useRoute } from "vue-router";
import { api } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import type { TrainingItem } from "../../lib/types";

const router = useRouter();
const route = useRoute();

const item = ref<TrainingItem | null>(null);
const loading = ref(false);
const error = ref("");
const activeTab = ref("overview");

function org() { return currentOrgSlug.value; }

async function load() {
  if (!org()) { error.value = "Select an organisation first."; return; }
  loading.value = true;
  error.value = "";
  try {
    const res = await api<{ data: TrainingItem }>("GET", `/api/v1/training/catalog/${route.params.id}`, {
      org: org(),
    });
    item.value = res.data;
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to load item.";
  } finally {
    loading.value = false;
  }
}

function formatCurrency(amount: number, currency: string) {
  return `${currency} ${amount.toFixed(2)}`;
}

async function deleteItem() {
  if (!item.value) return;
  if (!confirm(`Delete "${item.value.title}"? This cannot be undone.`)) return;
  try {
    await api("DELETE", `/api/v1/training/catalog/${item.value.id}`, { org: org() });
    router.replace({ name: "training-catalog" });
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to delete item.";
  }
}

onMounted(() => load());
</script>

<template>
  <div class="view">
    <div class="view-header">
      <button class="back-btn" @click="router.back()">← Back to Catalog</button>
      <div v-if="item" class="header-actions">
        <button class="btn btn-edit" @click="router.push({ name: 'training-catalog', query: { edit: item.id } })">✎ Edit</button>
        <button class="btn btn-delete" @click="deleteItem">🗑 Delete</button>
      </div>
    </div>

    <div v-if="error" class="alert alert-danger">{{ error }}</div>

    <div v-if="loading" class="loading">Loading item…</div>

    <div v-else-if="item" class="detail-container">
      <div class="detail-header">
        <div>
          <h1 class="title">{{ item.title }}</h1>
          <div class="meta-badges">
            <span class="badge" :class="'badge-' + item.type">{{ item.type }}</span>
            <span class="badge badge-delivery">{{ item.delivery }}</span>
            <span v-if="item.is_featured" class="badge badge-featured">⭐ Featured</span>
            <span :class="['badge', item.is_active ? 'badge-active' : 'badge-inactive']">
              {{ item.is_active ? "Active" : "Inactive" }}
            </span>
          </div>
        </div>
      </div>

      <!-- Tabs -->
      <div class="tabs">
        <button
          class="tab-button"
          :class="{ active: activeTab === 'overview' }"
          @click="activeTab = 'overview'"
        >
          Overview
        </button>
        <button
          class="tab-button"
          :class="{ active: activeTab === 'metadata' }"
          @click="activeTab = 'metadata'"
        >
          Metadata
        </button>
      </div>

      <!-- Overview Tab -->
      <div v-if="activeTab === 'overview'" class="tab-content">
        <div class="section">
          <h3>Description</h3>
          <p>{{ item.description || "(No description provided)" }}</p>
        </div>

        <div class="section">
          <h3>Key Information</h3>
          <div class="info-grid">
            <div class="info-item">
              <label>Duration</label>
              <div>{{ item.duration_hours }}h {{ item.duration_minutes }}m</div>
            </div>
            <div class="info-item">
              <label>Provider</label>
              <div>
                <a v-if="item.provider_url" :href="item.provider_url" target="_blank" class="link">
                  {{ item.provider_name }}
                </a>
                <span v-else>{{ item.provider_name }}</span>
              </div>
            </div>
            <div class="info-item">
              <label>Cost</label>
              <div>{{ formatCurrency(item.estimated_cost, item.cost_currency) }}</div>
            </div>
            <div class="info-item">
              <label>Type</label>
              <div class="capitalize">{{ item.type }}</div>
            </div>
            <div class="info-item">
              <label>Delivery Mode</label>
              <div class="capitalize">{{ item.delivery }}</div>
            </div>
            <div class="info-item">
              <label>Long Course</label>
              <div>{{ item.is_long_course ? "Yes" : "No" }}</div>
            </div>
          </div>
        </div>

        <div v-if="item.competencies && item.competencies.length > 0" class="section">
          <h3>Related Competencies</h3>
          <div class="competencies-list">
            <div v-for="comp in item.competencies" :key="comp.id" class="competency-item">
              <div class="comp-name">{{ comp.name }}</div>
              <div class="comp-category" v-if="comp.category">{{ comp.category }}</div>
            </div>
          </div>
        </div>

        <div class="actions">
          <router-link :to="{ name: 'my-training' }" class="btn btn-primary">
            Enroll Now
          </router-link>
        </div>
      </div>

      <!-- Metadata Tab -->
      <div v-if="activeTab === 'metadata'" class="tab-content">
        <div class="section">
          <h3>System Information</h3>
          <div class="info-grid">
            <div class="info-item">
              <label>UUID</label>
              <code class="code">{{ item.uuid }}</code>
            </div>
            <div class="info-item">
              <label>Item ID</label>
              <code class="code">{{ item.id }}</code>
            </div>
            <div class="info-item">
              <label>Created</label>
              <div>{{ new Date(item.created_at).toLocaleDateString() }}</div>
            </div>
            <div class="info-item">
              <label>Max Enrollments</label>
              <div>{{ item.max_enrollments || "Unlimited" }}</div>
            </div>
            <div class="info-item">
              <label>Status</label>
              <div>{{ item.is_active ? "Published" : "Draft" }}</div>
            </div>
          </div>
        </div>

        <div v-if="item.tags && item.tags.length > 0" class="section">
          <h3>Tags</h3>
          <div class="tags-list">
            <span v-for="tag in item.tags" :key="tag" class="tag">{{ tag }}</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.view {
  padding: 24px;
}

.view-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 24px;
}

.header-actions {
  display: flex;
  gap: 8px;
}

.btn {
  padding: 7px 14px;
  border-radius: 6px;
  font-size: 13px;
  cursor: pointer;
  border: 1px solid;
  transition: opacity 0.15s;
}

.btn:hover { opacity: 0.8; }

.btn-edit {
  background: #f0f4ff;
  color: #3366cc;
  border-color: #c8d8f8;
}

.btn-delete {
  background: #fff3f3;
  color: #c0392b;
  border-color: #f5c6c6;
}

.back-btn {
  background: none;
  border: none;
  font-size: 14px;
  color: #666;
  cursor: pointer;
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

.detail-container {
  max-width: 1000px;
}

.detail-header {
  margin-bottom: 32px;
}

.title {
  font-size: 32px;
  font-weight: 700;
  margin: 0 0 12px 0;
}

.meta-badges {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.badge {
  font-size: 12px;
  padding: 6px 10px;
  border-radius: 4px;
  font-weight: 500;
}

.badge-course { background: #dbeafe; color: #0284c7; }
.badge-workshop { background: #c7d2fe; color: #4f46e5; }
.badge-certification { background: #d1d5db; color: #374151; }
.badge-exam_voucher { background: #fccf8f; color: #92400e; }
.badge-delivery { background: #e0e7ff; color: #3730a3; }
.badge-featured { background: #fff4e6; color: #d97706; }
.badge-active { background: #dcfce7; color: #15803d; }
.badge-inactive { background: #f3f4f6; color: #6b7280; }

.tabs {
  display: flex;
  gap: 0;
  border-bottom: 1px solid #e0e0e0;
  margin-bottom: 24px;
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
}

.tab-button.active {
  color: #000;
  border-bottom-color: #000;
}

.tab-content {
  animation: fadeIn 0.2s;
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

.section {
  margin-bottom: 32px;
}

.section h3 {
  font-size: 16px;
  font-weight: 600;
  margin: 0 0 16px 0;
  color: #000;
}

.section p {
  line-height: 1.6;
  color: #555;
}

.info-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 20px;
}

.info-item {
  display: flex;
  flex-direction: column;
}

.info-item label {
  font-size: 12px;
  font-weight: 600;
  color: #666;
  text-transform: uppercase;
  margin-bottom: 6px;
  letter-spacing: 0.5px;
}

.info-item div {
  font-size: 14px;
  color: #333;
}

.code {
  background: #f5f5f5;
  padding: 2px 6px;
  border-radius: 3px;
  font-family: monospace;
  font-size: 12px;
  color: #666;
}

.link {
  color: #0066cc;
  text-decoration: none;
}

.link:hover {
  text-decoration: underline;
}

.capitalize {
  text-transform: capitalize;
}

.competencies-list {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 12px;
}

.competency-item {
  background: #f9f9f9;
  border: 1px solid #e0e0e0;
  border-radius: 6px;
  padding: 12px;
}

.comp-name {
  font-weight: 500;
  font-size: 14px;
  margin-bottom: 4px;
}

.comp-category {
  font-size: 12px;
  color: #999;
}

.tags-list {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.tag {
  background: #f0f0f0;
  padding: 6px 10px;
  border-radius: 4px;
  font-size: 13px;
}

.actions {
  margin-top: 32px;
  padding-top: 24px;
  border-top: 1px solid #e0e0e0;
}

.btn {
  padding: 12px 24px;
  border-radius: 6px;
  font-size: 14px;
  font-weight: 600;
  text-decoration: none;
  display: inline-block;
  border: none;
  cursor: pointer;
}

.btn-primary {
  background: #000;
  color: white;
}

.btn-primary:hover {
  background: #333;
}
</style>
