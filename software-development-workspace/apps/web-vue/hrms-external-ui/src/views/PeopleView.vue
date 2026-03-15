<script setup lang="ts">
import { ref, onMounted, computed } from "vue";
import { useRouter } from "vue-router";
import { callFrappeMethod, type FrappeAuthConfig } from "../lib/frappe-client";
import { authStore } from "../stores/auth";
import type { EmployeeRecord, PaginationMeta } from "../lib/types";
import CompanySelector from "../components/CompanySelector.vue";

const router = useRouter();
const employees = ref<EmployeeRecord[]>([]);
const meta = ref<PaginationMeta | null>(null);
const search = ref("");
const limitStart = ref(0);
const pageLength = 20;
const loading = ref(false);
const error = ref("");

const hasPrev = computed(() => limitStart.value > 0);
const hasNext = computed(() => {
  if (!meta.value) return false;
  return limitStart.value + pageLength < meta.value.total;
});

function authConfig(): FrappeAuthConfig {
  return {
    baseUrl: authStore.baseUrl,
    apiKey: authStore.apiKey || undefined,
    apiSecret: authStore.apiSecret || undefined,
  };
}

type EmployeeListData = { items: EmployeeRecord[] };

async function load(reset = false): Promise<void> {
  if (reset) limitStart.value = 0;
  loading.value = true;
  error.value = "";
  try {
    const result = await callFrappeMethod<EmployeeListData>(
      authConfig(),
      "bitwyse_hrms_backend.api.list_employees",
      {
        company: authStore.company ?? undefined,
        search: search.value.trim() || undefined,
        limit_start: limitStart.value,
        limit_page_length: pageLength,
      },
    );
    employees.value = result.items ?? [];
    // meta is in the envelope — frappe-client exposes it via a secondary path;
    // we read from the last raw envelope stored in the module-level variable
    meta.value = null; // updated when frappe-client exposes meta
  } catch (err) {
    error.value = err instanceof Error ? err.message : "Failed to load employees.";
  } finally {
    loading.value = false;
  }
}

function viewEmployee(id: string): void {
  void router.push({ name: "people-detail", params: { id } });
}

onMounted(() => load(true));
</script>

<template>
  <div class="view">
    <div class="view-header">
      <h2>People</h2>
      <CompanySelector @change="load(true)" />
    </div>

    <div class="toolbar">
      <input
        v-model="search"
        type="search"
        placeholder="Search by name, ID…"
        @keyup.enter="load(true)"
      />
      <button class="primary small" :disabled="loading" @click="load(true)">
        {{ loading ? "…" : "Search" }}
      </button>
    </div>

    <p v-if="error" class="error">{{ error }}</p>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Company</th>
            <th>Department</th>
            <th>Designation</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="emp in employees"
            :key="emp.name"
            class="clickable"
            @click="viewEmployee(emp.name)"
          >
            <td>{{ emp.name }}</td>
            <td>{{ emp.employee_name }}</td>
            <td>{{ emp.company }}</td>
            <td>{{ emp.department ?? "-" }}</td>
            <td>{{ emp.designation ?? "-" }}</td>
            <td>
              <span :class="['badge', emp.status === 'Active' ? 'badge-green' : 'badge-grey']">
                {{ emp.status }}
              </span>
            </td>
          </tr>
          <tr v-if="employees.length === 0 && !loading">
            <td colspan="6" class="empty">No employees found.</td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="pagination">
      <button class="ghost small" :disabled="!hasPrev || loading" @click="limitStart -= pageLength; load()">
        ← Previous
      </button>
      <span class="page-info">Showing {{ limitStart + 1 }}–{{ limitStart + employees.length }}</span>
      <button class="ghost small" :disabled="!hasNext || loading" @click="limitStart += pageLength; load()">
        Next →
      </button>
    </div>
  </div>
</template>
