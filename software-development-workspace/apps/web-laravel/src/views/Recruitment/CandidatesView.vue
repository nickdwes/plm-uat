<script setup lang="ts">
import { ref, computed, onMounted, watch } from "vue";
import { useRouter } from "vue-router";
import { api } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import type { Candidate, Paginator } from "../../lib/types";

const router = useRouter();

const candidates = ref<Candidate[]>([]);
const total      = ref(0);
const lastPage   = ref(1);
const page       = ref(1);
const search     = ref("");
const loading    = ref(false);
const error      = ref("");
const perPage    = 20;

const hasPrev = computed(() => page.value > 1);
const hasNext = computed(() => page.value < lastPage.value);

function org() { return currentOrgSlug.value; }

async function load(p = 1) {
  if (!org()) { error.value = "Select an organisation first."; return; }
  loading.value = true;
  error.value   = "";
  page.value    = p;
  try {
    const res = await api<Paginator<Candidate>>("GET", "/api/v1/recruitment/candidates", {
      org: org(),
      params: { page: p, per_page: perPage, search: search.value || undefined },
    });
    candidates.value = res.data;
    total.value      = res.meta.total;
    lastPage.value   = res.meta.last_page;
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to load candidates.";
  } finally {
    loading.value = false;
  }
}

function stageColor(type: string | undefined) {
  const m: Record<string, string> = {
    hired: "#059669", rejected: "#dc2626", offer: "#d97706",
    interview: "#6366f1", screening: "#0ea5e9", applied: "#6b7280",
    shortlisted: "#f59e0b", pre_onboarding: "#8b5cf6",
  };
  return m[type ?? ""] ?? "#9ca3af";
}

function initials(name: string) {
  return name.split(" ").map(n => n[0]).slice(0, 2).join("").toUpperCase();
}

function avatarColor(id: number) {
  const colors = ["#004C97", "#0ea5e9", "#6366f1", "#8b5cf6", "#059669", "#d97706"];
  return colors[id % colors.length];
}

onMounted(() => load(1));
watch(currentOrgSlug, () => load(1));
</script>

<template>
  <div class="view">
    <!-- Header -->
    <div class="page-header">
      <div class="page-header-left">
        <div>
          <button class="back-btn" @click="router.back()">← Back</button>
          <h2 class="page-title">Candidates</h2>
        </div>
        <span class="total-badge">{{ total }} total</span>
      </div>
    </div>

    <!-- Search bar -->
    <div class="search-section">
      <div class="search-box">
        <svg class="search-icon" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
          <circle cx="8.5" cy="8.5" r="5.5" stroke="currentColor" stroke-width="1.6"/>
          <path d="M13.5 13.5L17 17" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
        </svg>
        <input
          v-model="search"
          class="search-input"
          placeholder="Search by name or email…"
          @keyup.enter="load(1)"
        />
        <button v-if="search" class="clear-btn" @click="search = ''; load(1)">✕</button>
      </div>
      <button class="btn-search" @click="load(1)">Search</button>
    </div>

    <!-- States -->
    <div v-if="loading" class="state-msg">
      <div class="spinner"></div>
      <span>Loading candidates…</span>
    </div>
    <p v-if="error" class="error-msg">{{ error }}</p>

    <!-- Empty -->
    <div v-if="!loading && candidates.length === 0 && !error" class="empty-state">
      <div class="empty-icon">👤</div>
      <p>No candidates found{{ search ? ` for "${search}"` : "" }}.</p>
    </div>

    <!-- Candidate cards -->
    <div v-if="!loading && candidates.length > 0" class="candidates-grid">
      <div v-for="c in candidates" :key="c.id" class="candidate-card">
        <!-- Card top: avatar + name + contact -->
        <div class="card-top">
          <div class="avatar" :style="{ background: avatarColor(c.id) }">
            {{ initials(c.full_name) }}
          </div>
          <div class="candidate-info">
            <div class="candidate-name">{{ c.full_name }}</div>
            <a :href="`mailto:${c.email}`" class="candidate-email">{{ c.email }}</a>
            <div v-if="c.phone" class="candidate-phone">{{ c.phone }}</div>
          </div>
          <div class="source-badge" v-if="c.source">{{ c.source }}</div>
        </div>

        <!-- Applications -->
        <div class="applications-section" v-if="c.applications && c.applications.length > 0">
          <div class="section-label">Applications</div>
          <div
            v-for="app in c.applications"
            :key="app.id"
            class="application-row"
          >
            <div class="app-vacancy">
              <svg viewBox="0 0 16 16" fill="none" class="app-icon"><rect x="2" y="3" width="12" height="10" rx="1.5" stroke="currentColor" stroke-width="1.3"/><path d="M5 3V2.5C5 1.67 5.67 1 6.5 1h3c.83 0 1.5.67 1.5 1.5V3" stroke="currentColor" stroke-width="1.3"/><path d="M2 7h12" stroke="currentColor" stroke-width="1.3"/></svg>
              {{ app.vacancy?.name ?? "Unknown Vacancy" }}
            </div>
            <div class="app-right">
              <span
                class="stage-chip"
                :style="{ background: stageColor(app.current_stage?.stage_type) + '1a', color: stageColor(app.current_stage?.stage_type), borderColor: stageColor(app.current_stage?.stage_type) + '60' }"
              >
                {{ app.current_stage?.name ?? "No Stage" }}
              </span>
              <router-link
                :to="{ name: 'candidate-detail', params: { id: c.id } }"
                class="view-app-link"
                title="View application & attachments"
              >
                View →
              </router-link>
            </div>
          </div>
        </div>

        <!-- No applications -->
        <div v-else class="no-applications">No active applications</div>

        <!-- Footer: keywords + profile link -->
        <div class="card-footer">
          <div class="keywords">
            <span v-for="k in (c.keywords ?? []).slice(0, 4)" :key="k" class="keyword-chip">{{ k }}</span>
          </div>
          <router-link :to="{ name: 'candidate-detail', params: { id: c.id } }" class="profile-link">
            Full Profile &amp; CV
          </router-link>
        </div>
      </div>
    </div>

    <!-- Pagination -->
    <div v-if="lastPage > 1" class="pagination">
      <button :disabled="!hasPrev" class="page-btn" @click="load(page - 1)">← Prev</button>
      <span class="page-info">Page {{ page }} of {{ lastPage }}</span>
      <button :disabled="!hasNext" class="page-btn" @click="load(page + 1)">Next →</button>
    </div>
  </div>
</template>

<style scoped>
.back-btn { background: none; border: none; cursor: pointer; color: var(--color-text-muted); font-size: var(--font-size-sm); margin-bottom: 4px; display: block; }
.back-btn:hover { color: var(--color-text); }
/* Header */
.page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: var(--space-5); }
.page-header-left { display: flex; align-items: center; gap: var(--space-3); }
.page-title { font-size: 22px; font-weight: 700; color: var(--color-text); margin: 0; }
.total-badge { padding: 3px 12px; border-radius: 20px; background: #004C9715; color: #004C97; font-size: 12px; font-weight: 600; border: 1px solid #004C9730; }

/* Search */
.search-section { display: flex; align-items: center; gap: var(--space-3); margin-bottom: var(--space-5); }
.search-box {
  flex: 1; max-width: 480px;
  display: flex; align-items: center; gap: var(--space-2);
  padding: 0 14px;
  border: 1.5px solid var(--color-border);
  border-radius: var(--radius-lg);
  background: var(--color-surface);
  transition: border-color 0.15s, box-shadow 0.15s;
}
.search-box:focus-within {
  border-color: #004C97;
  box-shadow: 0 0 0 3px #004C9718;
}
.search-icon { width: 16px; height: 16px; color: var(--color-text-muted); flex-shrink: 0; }
.search-input { flex: 1; border: none; outline: none; background: transparent; padding: 10px 0; font-size: 14px; color: var(--color-text); }
.search-input::placeholder { color: var(--color-text-muted); }
.clear-btn { background: none; border: none; cursor: pointer; color: var(--color-text-muted); font-size: 13px; padding: 0 2px; line-height: 1; }
.clear-btn:hover { color: var(--color-text); }
.btn-search {
  padding: 10px 22px; border-radius: var(--radius-lg);
  background: #004C97; color: #fff; border: none; cursor: pointer;
  font-size: 14px; font-weight: 600; transition: background 0.15s;
}
.btn-search:hover { background: #003a75; }

/* State */
.state-msg { display: flex; align-items: center; gap: var(--space-2); color: var(--color-text-muted); padding: var(--space-5) 0; }
.spinner { width: 18px; height: 18px; border: 2px solid var(--color-border); border-top-color: #004C97; border-radius: 50%; animation: spin 0.7s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.error-msg { color: #dc2626; background: #fef2f2; border: 1px solid #fecaca; border-radius: var(--radius-md); padding: var(--space-3) var(--space-4); margin-bottom: var(--space-4); }
.empty-state { text-align: center; padding: 60px 20px; color: var(--color-text-muted); }
.empty-icon { font-size: 40px; margin-bottom: var(--space-2); }
.empty-state p { font-size: 15px; }

/* Grid */
.candidates-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(380px, 1fr)); gap: var(--space-4); }

/* Card */
.candidate-card {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  padding: var(--space-4);
  display: flex; flex-direction: column; gap: var(--space-3);
  transition: box-shadow 0.15s, border-color 0.15s;
}
.candidate-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.07); border-color: #004C9740; }

/* Card top */
.card-top { display: flex; align-items: flex-start; gap: var(--space-3); }
.avatar {
  width: 44px; height: 44px; border-radius: 50%; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  color: #fff; font-weight: 700; font-size: 15px; letter-spacing: 0.5px;
}
.candidate-info { flex: 1; min-width: 0; }
.candidate-name { font-weight: 600; font-size: 15px; color: var(--color-text); margin-bottom: 2px; }
.candidate-email { font-size: 13px; color: #004C97; text-decoration: none; display: block; }
.candidate-email:hover { text-decoration: underline; }
.candidate-phone { font-size: 12px; color: var(--color-text-muted); margin-top: 1px; }
.source-badge { padding: 3px 10px; border-radius: 20px; background: var(--color-bg); border: 1px solid var(--color-border); font-size: 11px; color: var(--color-text-muted); white-space: nowrap; flex-shrink: 0; }

/* Applications */
.applications-section { display: flex; flex-direction: column; gap: 6px; }
.section-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: var(--color-text-muted); margin-bottom: 2px; }
.application-row {
  display: flex; align-items: center; justify-content: space-between; gap: var(--space-2);
  padding: 8px 12px; border-radius: var(--radius-md);
  background: var(--color-bg); border: 1px solid var(--color-border);
}
.app-vacancy { display: flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 500; color: var(--color-text); min-width: 0; flex: 1; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
.app-icon { width: 14px; height: 14px; flex-shrink: 0; color: var(--color-text-muted); }
.app-right { display: flex; align-items: center; gap: var(--space-2); flex-shrink: 0; }
.stage-chip { padding: 3px 10px; border-radius: 20px; border: 1px solid; font-size: 11px; font-weight: 600; white-space: nowrap; }
.view-app-link { font-size: 12px; font-weight: 600; color: #004C97; text-decoration: none; white-space: nowrap; }
.view-app-link:hover { text-decoration: underline; }
.no-applications { font-size: 13px; color: var(--color-text-muted); padding: 8px 0; font-style: italic; }

/* Footer */
.card-footer { display: flex; align-items: center; justify-content: space-between; gap: var(--space-2); padding-top: var(--space-2); border-top: 1px solid var(--color-border); }
.keywords { display: flex; flex-wrap: wrap; gap: 4px; }
.keyword-chip { padding: 2px 8px; border-radius: 8px; background: var(--color-bg); border: 1px solid var(--color-border); font-size: 11px; color: var(--color-text-muted); }
.profile-link { font-size: 12px; font-weight: 600; color: #004C97; text-decoration: none; white-space: nowrap; flex-shrink: 0; }
.profile-link:hover { text-decoration: underline; }

/* Pagination */
.pagination { display: flex; align-items: center; gap: var(--space-3); justify-content: center; margin-top: var(--space-6); }
.page-btn { padding: 8px 18px; border: 1px solid var(--color-border); border-radius: var(--radius-md); background: var(--color-surface); font-size: 13px; cursor: pointer; transition: border-color 0.15s; }
.page-btn:hover:not(:disabled) { border-color: #004C97; color: #004C97; }
.page-btn:disabled { opacity: 0.4; cursor: not-allowed; }
.page-info { font-size: 13px; color: var(--color-text-muted); }
</style>
