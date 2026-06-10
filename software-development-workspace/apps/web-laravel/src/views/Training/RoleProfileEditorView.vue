<script setup lang="ts">
import { ref, computed, onMounted, watch } from "vue";
import { useRouter } from "vue-router";
import { api } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import type { RoleCompetencyProfile, Paginator } from "../../lib/types";

const router = useRouter();

const profiles = ref<RoleCompetencyProfile[]>([]);
const total = ref(0);
const lastPage = ref(1);
const page = ref(1);
const search = ref("");
const loading = ref(false);
const error = ref("");
const perPage = 20;

const hasPrev = computed(() => page.value > 1);
const hasNext = computed(() => page.value < lastPage.value);

function org() { return currentOrgSlug.value; }

async function load(p = 1) {
  if (!org()) { error.value = "Select an organisation first."; return; }
  loading.value = true;
  error.value = "";
  page.value = p;
  try {
    const res = await api<Paginator<RoleCompetencyProfile>>("GET", "/api/v1/training/role-profiles", {
      org: org(),
      params: {
        page: p,
        per_page: perPage,
        search: search.value || undefined,
      },
    });
    profiles.value = res.data;
    total.value = res.meta.total;
    lastPage.value = res.meta.last_page;
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to load profiles.";
  } finally {
    loading.value = false;
  }
}

let debounceTimer: ReturnType<typeof setTimeout>;
function onSearchInput() {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => load(1), 350);
}

function clearSearch() {
  search.value = "";
  load(1);
}

function goToProfile(profile: RoleCompetencyProfile) {
  router.push({ name: "role-profile-detail", params: { id: profile.id } });
}

async function deleteProfile(profile: RoleCompetencyProfile, event: Event) {
  event.stopPropagation();
  if (!confirm(`Delete role profile "${profile.job_title}"?`)) return;
  
  try {
    await api("DELETE", `/api/v1/training/role-profiles/${profile.id}`, { org: org() });
    load(1);
  } catch (e) {
    alert(e instanceof Error ? e.message : "Failed to delete profile.");
  }
}

onMounted(() => load(1));
watch(currentOrgSlug, () => load(1));
</script>

<template>
  <div class="view">
    <div class="view-header">
      <div>
        <button class="back-btn" @click="router.back()">← Back</button>
        <h2>Role Competency Profiles</h2>
      </div>
      <button class="btn btn-primary">+ New Profile</button>
    </div>

    <div v-if="error" class="alert alert-danger">{{ error }}</div>

    <!-- Search -->
    <div class="search-bar">
      <div class="search-field">
        <svg class="search-icon" viewBox="0 0 20 20" fill="none">
          <circle cx="8.5" cy="8.5" r="5.5" stroke="currentColor" stroke-width="1.6"/>
          <path d="M13 13l3.5 3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
        </svg>
        <input
          v-model="search"
          type="search"
          class="search-input"
          placeholder="Search by job title…"
          @input="onSearchInput"
          @keyup.enter="load(1)"
        />
        <button v-if="search" class="clear-btn" @click="clearSearch">✕</button>
      </div>
    </div>

    <!-- Results Info -->
    <div class="results-info">
      Showing {{ profiles.length === 0 ? 0 : (page - 1) * perPage + 1 }}-{{ Math.min(page * perPage, total) }} of {{ total }}
    </div>

    <!-- Loading -->
    <div v-if="loading" class="loading">Loading profiles…</div>

    <!-- Profiles List -->
    <div v-else-if="profiles.length > 0" class="profiles-list">
      <div
        v-for="profile in profiles"
        :key="profile.id"
        class="profile-card"
        @click="goToProfile(profile)"
      >
        <div class="profile-header">
          <div>
            <h3 class="profile-title">{{ profile.job_title }}</h3>
            <p v-if="profile.department" class="profile-dept">{{ profile.department.name }}</p>
          </div>
          <button
            class="delete-btn"
            @click="deleteProfile(profile, $event)"
            title="Delete profile"
          >
            🗑
          </button>
        </div>

        <p v-if="profile.description" class="profile-description">
          {{ profile.description }}
        </p>

        <!-- Competencies Preview -->
        <div v-if="profile.competencies && profile.competencies.length > 0" class="competencies-preview">
          <div class="preview-label">{{ profile.competencies.length }} Competencies</div>
          <div class="comp-list">
            <div v-for="comp in profile.competencies.slice(0, 3)" :key="comp.id" class="comp-item">
              <span class="comp-name">{{ comp.name }}</span>
              <span class="comp-level">Level {{ comp.required_level }}</span>
            </div>
            <div v-if="profile.competencies.length > 3" class="comp-more">
              +{{ profile.competencies.length - 3 }} more
            </div>
          </div>
        </div>

        <div class="card-id">
          <code>{{ profile.uuid }}</code>
        </div>
      </div>
    </div>

    <!-- No Results -->
    <div v-else class="no-results">
      <p>No role profiles found.</p>
      <button @click="clearSearch" class="btn btn-primary" v-if="search">Clear search</button>
    </div>

    <!-- Pagination -->
    <div v-if="profiles.length > 0" class="pagination">
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
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 24px;
}

.view-header div {
  flex: 1;
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

.search-bar {
  margin-bottom: 20px;
}

.search-field {
  position: relative;
  display: flex;
  align-items: center;
  max-width: 400px;
}

.search-icon {
  width: 18px;
  height: 18px;
  position: absolute;
  left: 12px;
  color: #999;
}

.search-input {
  width: 100%;
  padding: 10px 12px 10px 36px;
  border: 1px solid #ddd;
  border-radius: 6px;
  font-size: 14px;
}

.search-input:focus {
  outline: none;
  border-color: #666;
  box-shadow: 0 0 0 2px rgba(0,0,0,0.05);
}

.clear-btn {
  position: absolute;
  right: 10px;
  background: none;
  border: none;
  font-size: 16px;
  color: #999;
  cursor: pointer;
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

.profiles-list {
  display: grid;
  gap: 12px;
  margin-bottom: 24px;
}

.profile-card {
  background: white;
  border: 1px solid #e0e0e0;
  border-radius: 8px;
  padding: 16px;
  cursor: pointer;
  transition: all 0.2s;
}

.profile-card:hover {
  border-color: #999;
  box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.profile-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 12px;
}

.profile-title {
  font-size: 16px;
  font-weight: 600;
  margin: 0 0 4px 0;
}

.profile-dept {
  font-size: 13px;
  color: #999;
  margin: 0;
}

.delete-btn {
  background: none;
  border: none;
  font-size: 18px;
  cursor: pointer;
  opacity: 0.5;
  transition: opacity 0.2s;
}

.delete-btn:hover {
  opacity: 1;
}

.profile-description {
  font-size: 13px;
  color: #666;
  margin: 8px 0;
  line-height: 1.4;
}

.competencies-preview {
  margin: 12px 0;
  padding: 12px;
  background: #f9f9f9;
  border-radius: 6px;
}

.preview-label {
  font-size: 12px;
  font-weight: 600;
  color: #666;
  margin-bottom: 8px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.comp-list {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.comp-item {
  display: flex;
  justify-content: space-between;
  font-size: 13px;
}

.comp-name {
  color: #333;
  font-weight: 500;
}

.comp-level {
  color: #999;
  font-size: 12px;
}

.comp-more {
  font-size: 12px;
  color: #999;
  font-style: italic;
}

.card-id {
  margin-top: 12px;
  padding-top: 12px;
  border-top: 1px solid #e0e0e0;
  font-size: 11px;
  color: #999;
}

code {
  background: #f5f5f5;
  padding: 2px 4px;
  border-radius: 2px;
  font-family: monospace;
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
