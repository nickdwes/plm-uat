<script setup lang="ts">
import { ref, onMounted } from "vue";
import { callFrappeMethod, type FrappeAuthConfig } from "../lib/frappe-client";
import { authStore } from "../stores/auth";
import type { DepartmentRecord, BranchRecord } from "../lib/types";
import CompanySelector from "../components/CompanySelector.vue";

const departments = ref<DepartmentRecord[]>([]);
const branches = ref<BranchRecord[]>([]);
const loading = ref(false);
const error = ref("");

// New department form
const newDeptName = ref("");
const newDeptParent = ref("");
const creating = ref(false);
const createError = ref("");

function authConfig(): FrappeAuthConfig {
  return {
    baseUrl: authStore.baseUrl,
    apiKey: authStore.apiKey || undefined,
    apiSecret: authStore.apiSecret || undefined,
  };
}

async function load(): Promise<void> {
  loading.value = true;
  error.value = "";
  try {
    const [deptsResult, branchesResult] = await Promise.all([
      callFrappeMethod<{ items: DepartmentRecord[] }>(
        authConfig(),
        "bitwyse_hrms_backend.api.list_departments",
        { company: authStore.company ?? undefined },
      ),
      callFrappeMethod<{ items: BranchRecord[] }>(
        authConfig(),
        "bitwyse_hrms_backend.api.list_branches",
        { company: authStore.company ?? undefined },
      ),
    ]);
    departments.value = deptsResult.items ?? [];
    branches.value = branchesResult.items ?? [];
  } catch (err) {
    error.value = err instanceof Error ? err.message : "Failed to load org data.";
  } finally {
    loading.value = false;
  }
}

async function createDept(): Promise<void> {
  if (!newDeptName.value.trim()) return;
  creating.value = true;
  createError.value = "";
  try {
    await callFrappeMethod(
      authConfig(),
      "bitwyse_hrms_backend.api.create_department",
      {
        company: authStore.company ?? "",
        department_name: newDeptName.value.trim(),
        parent_department: newDeptParent.value.trim() || undefined,
      },
    );
    newDeptName.value = "";
    newDeptParent.value = "";
    await load();
  } catch (err) {
    createError.value = err instanceof Error ? err.message : "Failed to create department.";
  } finally {
    creating.value = false;
  }
}

onMounted(load);
</script>

<template>
  <div class="view">
    <div class="view-header">
      <h2>Organisation</h2>
      <CompanySelector @change="load" />
    </div>

    <p v-if="loading" class="status">Loading…</p>
    <p v-if="error" class="error-banner">{{ error }}</p>

    <div class="org-grid">
      <!-- Departments panel -->
      <section class="org-panel">
        <div class="org-panel-header">
          <div class="org-panel-title">
            <span class="org-panel-icon">🏢</span>
            <h3>Departments</h3>
          </div>
          <span class="org-count-badge">{{ departments.length }}</span>
        </div>

        <ul class="org-list" v-if="departments.length > 0">
          <li v-for="dept in departments" :key="dept.name" class="org-list-item">
            <div class="org-item-dot"></div>
            <div class="org-item-body">
              <span class="org-item-name">{{ dept.department_name }}</span>
              <span v-if="dept.parent_department" class="org-item-parent">↳ {{ dept.parent_department }}</span>
            </div>
          </li>
        </ul>
        <div v-else class="org-empty">No departments found.</div>

        <div class="org-add-section">
          <h4 class="org-add-title">Add Department</h4>
          <form class="org-add-form" @submit.prevent="createDept">
            <input v-model="newDeptName" type="text" placeholder="Department name" required />
            <input v-model="newDeptParent" type="text" placeholder="Parent department (optional)" />
            <p v-if="createError" class="error">{{ createError }}</p>
            <button type="submit" class="primary small" :disabled="creating">
              {{ creating ? "Creating…" : "Add" }}
            </button>
          </form>
        </div>
      </section>

      <!-- Branches panel -->
      <section class="org-panel">
        <div class="org-panel-header">
          <div class="org-panel-title">
            <span class="org-panel-icon">📍</span>
            <h3>Branches</h3>
          </div>
          <span class="org-count-badge">{{ branches.length }}</span>
        </div>

        <ul class="org-list" v-if="branches.length > 0">
          <li v-for="branch in branches" :key="branch.name" class="org-list-item">
            <div class="org-item-dot"></div>
            <div class="org-item-body">
              <span class="org-item-name">{{ branch.branch }}</span>
            </div>
          </li>
        </ul>
        <div v-else class="org-empty">
          <div class="org-empty-icon">📭</div>
          <p>No branches found.</p>
          <p class="org-empty-hint">Branches will appear here once added.</p>
        </div>
      </section>
    </div>
  </div>
</template>
