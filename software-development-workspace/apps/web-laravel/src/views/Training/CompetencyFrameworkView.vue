<script setup lang="ts">
import { ref, computed, onMounted, watch } from "vue";
import { useRouter } from "vue-router";
import { api } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import type { CompetencyItem, Paginator } from "../../lib/types";

const router = useRouter();

const competencies = ref<CompetencyItem[]>([]);
const selectedCategory = ref("");
const search = ref("");
const loading = ref(false);
const error = ref("");

const categories = computed(() => {
  const cats = new Set<string>();
  competencies.value.forEach(c => {
    if (c.category) cats.add(c.category);
  });
  return Array.from(cats).sort();
});

const filteredCompetencies = computed(() => {
  return competencies.value.filter(c => {
    const matchesCategory = !selectedCategory.value || c.category === selectedCategory.value;
    const matchesSearch = !search.value || 
      c.name.toLowerCase().includes(search.value.toLowerCase()) ||
      (c.description || "").toLowerCase().includes(search.value.toLowerCase());
    return matchesCategory && matchesSearch;
  });
});

function org() { return currentOrgSlug.value; }

async function load() {
  if (!org()) { error.value = "Select an organisation first."; return; }
  loading.value = true;
  error.value = "";
  try {
    const res = await api<Paginator<CompetencyItem>>("GET", "/api/v1/training/competencies", {
      org: org(),
      params: { per_page: 100 },
    });
    competencies.value = res.data;
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to load competencies.";
  } finally {
    loading.value = false;
  }
}

function levelColor(level: number) {
  const colors = ["#ef4444", "#f59e0b", "#eab308", "#84cc16", "#22c55e"];
  return colors[level - 1] || "#999";
}

onMounted(() => load());
watch(currentOrgSlug, () => load());
</script>

<template>
  <div class="view">
    <div class="view-header">
      <button class="back-btn" @click="router.back()">← Back</button>
      <h2>Competency Framework</h2>
    </div>

    <div v-if="error" class="alert alert-danger">{{ error }}</div>

    <!-- Search & Filter -->
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
          placeholder="Search competencies…"
        />
      </div>
      <select v-model="selectedCategory" class="filter-select">
        <option value="">All Categories</option>
        <option v-for="cat in categories" :key="cat" :value="cat">
          {{ cat }}
        </option>
      </select>
    </div>

    <!-- Results Info -->
    <div class="results-info">
      Found {{ filteredCompetencies.length }} competencies
      <span v-if="categories.length > 0"> in {{ categories.length }} categories</span>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="loading">Loading competencies…</div>

    <!-- Competencies Grid -->
    <div v-else-if="filteredCompetencies.length > 0" class="competencies-grid">
      <div v-for="comp in filteredCompetencies" :key="comp.id" class="competency-card">
        <div class="card-header">
          <h3 class="comp-name">{{ comp.name }}</h3>
          <span v-if="comp.category" class="category-badge">{{ comp.category }}</span>
        </div>

        <p v-if="comp.description" class="comp-description">{{ comp.description }}</p>

        <!-- Proficiency Levels -->
        <div v-if="comp.proficiency_levels && comp.proficiency_levels.length > 0" class="proficiency-section">
          <h4>Proficiency Levels</h4>
          <div class="levels-list">
            <div v-for="(level, idx) in comp.proficiency_levels" :key="idx" class="level-item">
              <span
                class="level-badge"
                :style="{ backgroundColor: levelColor(level.level) }"
              >
                {{ level.level }}
              </span>
              <span class="level-descriptor">{{ level.descriptor }}</span>
            </div>
          </div>
        </div>

        <div class="card-id">
          <span class="uuid-label">UUID:</span>
          <code>{{ comp.uuid }}</code>
        </div>
      </div>
    </div>

    <!-- No Results -->
    <div v-else class="no-results">
      <p>No competencies found.</p>
      <button @click="selectedCategory = ''; search = ''" class="btn btn-primary" v-if="search || selectedCategory">
        Clear filters
      </button>
    </div>
  </div>
</template>

<style scoped>
.view {
  padding: 24px;
}

.view-header {
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
  display: flex;
  gap: 12px;
  margin-bottom: 20px;
  flex-wrap: wrap;
}

.search-field {
  flex: 1;
  min-width: 250px;
  position: relative;
  display: flex;
  align-items: center;
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

.filter-select {
  padding: 10px 12px;
  border: 1px solid #ddd;
  border-radius: 6px;
  font-size: 14px;
  background: white;
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

.competencies-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
  gap: 16px;
  margin-bottom: 24px;
}

.competency-card {
  background: white;
  border: 1px solid #e0e0e0;
  border-radius: 8px;
  padding: 16px;
  transition: all 0.2s;
}

.competency-card:hover {
  border-color: #999;
  box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.card-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 12px;
}

.comp-name {
  font-size: 16px;
  font-weight: 600;
  margin: 0;
}

.category-badge {
  font-size: 11px;
  background: #e0e7ff;
  color: #3730a3;
  padding: 4px 8px;
  border-radius: 4px;
  font-weight: 500;
}

.comp-description {
  font-size: 13px;
  color: #666;
  margin: 8px 0;
  line-height: 1.4;
}

.proficiency-section {
  margin: 12px 0;
  padding: 12px;
  background: #f9f9f9;
  border-radius: 6px;
}

.proficiency-section h4 {
  font-size: 12px;
  font-weight: 600;
  color: #666;
  margin: 0 0 8px 0;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.levels-list {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.level-item {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
}

.level-badge {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: white;
  font-weight: 600;
  font-size: 12px;
  flex-shrink: 0;
}

.level-descriptor {
  color: #555;
}

.card-id {
  margin-top: 12px;
  padding-top: 12px;
  border-top: 1px solid #e0e0e0;
  font-size: 11px;
  color: #999;
  display: flex;
  align-items: center;
  gap: 6px;
}

.uuid-label {
  font-weight: 600;
}

code {
  background: #f5f5f5;
  padding: 2px 4px;
  border-radius: 2px;
  font-family: monospace;
  font-size: 10px;
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
  background: #000;
  color: white;
  border: none;
  cursor: pointer;
}
</style>
