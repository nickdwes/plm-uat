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
    <p v-if="error" class="error">{{ error }}</p>

    <div class="two-col-layout">
      <section class="panel">
        <h3>Departments ({{ departments.length }})</h3>
        <ul class="entity-list">
          <li v-for="dept in departments" :key="dept.name">
            <span class="entity-name">{{ dept.department_name }}</span>
            <span v-if="dept.parent_department" class="entity-sub">↳ {{ dept.parent_department }}</span>
          </li>
          <li v-if="departments.length === 0" class="empty">No departments found.</li>
        </ul>

        <form class="inline-form" @submit.prevent="createDept">
          <h4>Add Department</h4>
          <input v-model="newDeptName" type="text" placeholder="Department name" required />
          <input v-model="newDeptParent" type="text" placeholder="Parent department (optional)" />
          <p v-if="createError" class="error">{{ createError }}</p>
          <button type="submit" class="primary small" :disabled="creating">
            {{ creating ? "Creating…" : "Add" }}
          </button>
        </form>
      </section>

      <section class="panel">
        <h3>Branches ({{ branches.length }})</h3>
        <ul class="entity-list">
          <li v-for="branch in branches" :key="branch.name">
            <span class="entity-name">{{ branch.branch }}</span>
          </li>
          <li v-if="branches.length === 0" class="empty">No branches found.</li>
        </ul>
      </section>
    </div>
  </div>
</template>
