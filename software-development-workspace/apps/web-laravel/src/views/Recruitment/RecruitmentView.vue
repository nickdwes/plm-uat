<script setup lang="ts">
import { ref, onMounted, watch } from "vue";
import { useRouter } from "vue-router";
import { api } from "../../lib/api";
import { currentOrgSlug, authStore } from "../../stores/auth";

const router = useRouter();
import type { RecruitmentStats } from "../../lib/types";

const stats   = ref<RecruitmentStats | null>(null);
const loading = ref(false);
const error   = ref("");

function org() { return currentOrgSlug.value; }

async function load() {
  if (!org()) { error.value = "Select an organisation first."; return; }
  loading.value = true;
  error.value   = "";
  try {
    const res = await api<{ data: RecruitmentStats }>("GET", "/api/v1/recruitment/stats", { org: org() });
    stats.value = res.data;
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to load stats.";
  } finally {
    loading.value = false;
  }
}

onMounted(load);
watch(currentOrgSlug, load);
</script>

<template>
  <div class="view">
    <div class="view-header">
      <div>
        <button class="back-btn" @click="router.back()">← Back</button>
        <h2>Recruitment</h2>
      </div>
      <span v-if="authStore.currentOrg" class="badge badge-blue">{{ authStore.currentOrg.name }}</span>
    </div>

    <p v-if="loading" class="status">Loading…</p>
    <p v-if="error" class="error">{{ error }}</p>

    <template v-if="stats">
      <div class="metrics-grid" style="margin-bottom:24px">
        <article class="metric-card">
          <p class="metric-label">Open Vacancies</p>
          <p class="metric-value" style="color:var(--green)">{{ stats.open }}</p>
          <p class="metric-sub">Currently active</p>
        </article>
        <article class="metric-card">
          <p class="metric-label">Draft Vacancies</p>
          <p class="metric-value">{{ stats.draft }}</p>
          <p class="metric-sub">Not yet published</p>
        </article>
        <article class="metric-card">
          <p class="metric-label">Applications</p>
          <p class="metric-value">{{ stats.applications }}</p>
          <p class="metric-sub">Total received</p>
        </article>
        <article class="metric-card">
          <p class="metric-label">Shortlisted</p>
          <p class="metric-value" style="color:var(--yellow)">{{ stats.shortlisted }}</p>
          <p class="metric-sub">Awaiting offer</p>
        </article>
        <article class="metric-card">
          <p class="metric-label">Hired</p>
          <p class="metric-value" style="color:var(--green)">{{ stats.hired }}</p>
          <p class="metric-sub">This cycle</p>
        </article>
      </div>
    </template>

    <p class="section-title">Recruitment Modules</p>
    <div class="actions-grid" style="margin-bottom:28px">
      <router-link :to="{ name: 'vacancies' }" class="action-card">
        <span class="action-icon">📋</span>
        <span>Vacancies</span>
      </router-link>
      <router-link :to="{ name: 'candidates' }" class="action-card">
        <span class="action-icon">👤</span>
        <span>Candidates</span>
      </router-link>
      <router-link :to="{ name: 'rec-templates' }" class="action-card">
        <span class="action-icon">📄</span>
        <span>Templates</span>
      </router-link>
      <router-link :to="{ name: 'rec-workflows' }" class="action-card">
        <span class="action-icon">⚙️</span>
        <span>Workflows</span>
      </router-link>
    </div>
  </div>
</template>
