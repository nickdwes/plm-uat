<script setup lang="ts">
import { ref, onMounted } from "vue";
import { callFrappeMethod, type FrappeAuthConfig } from "../lib/frappe-client";
import { authStore } from "../stores/auth";
import type { DesignationRecord, GradeRecord, LeaveTypeRecord } from "../lib/types";
import CompanySelector from "../components/CompanySelector.vue";

type Tab = "designations" | "grades" | "leave-types";
const tab = ref<Tab>("designations");

const designations = ref<DesignationRecord[]>([]);
const grades = ref<GradeRecord[]>([]);
const leaveTypes = ref<LeaveTypeRecord[]>([]);
const loading = ref(false);
const error = ref("");

function authConfig(): FrappeAuthConfig {
  return {
    baseUrl: authStore.baseUrl,
    apiKey: authStore.apiKey || undefined,
    apiSecret: authStore.apiSecret || undefined,
  };
}

async function loadDesignations(): Promise<void> {
  loading.value = true;
  error.value = "";
  try {
    const result = await callFrappeMethod<{ items: DesignationRecord[] }>(
      authConfig(),
      "bitwyse_hrms_backend.api.list_designations",
      { company: authStore.company ?? undefined },
    );
    designations.value = result.items ?? [];
  } catch (err) {
    error.value = err instanceof Error ? err.message : "Failed to load designations.";
  } finally {
    loading.value = false;
  }
}

async function loadGrades(): Promise<void> {
  loading.value = true;
  error.value = "";
  try {
    const result = await callFrappeMethod<{ items: GradeRecord[] }>(
      authConfig(),
      "bitwyse_hrms_backend.api.list_grades",
      { company: authStore.company ?? undefined },
    );
    grades.value = result.items ?? [];
  } catch (err) {
    error.value = err instanceof Error ? err.message : "Failed to load grades.";
  } finally {
    loading.value = false;
  }
}

async function loadLeaveTypes(): Promise<void> {
  loading.value = true;
  error.value = "";
  try {
    const result = await callFrappeMethod<{ items: LeaveTypeRecord[] }>(
      authConfig(),
      "bitwyse_hrms_backend.api.list_leave_types",
      { company: authStore.company ?? undefined },
    );
    leaveTypes.value = result.items ?? [];
  } catch (err) {
    error.value = err instanceof Error ? err.message : "Failed to load leave types.";
  } finally {
    loading.value = false;
  }
}

function switchTab(t: Tab): void {
  tab.value = t;
  if (t === "designations") void loadDesignations();
  if (t === "grades") void loadGrades();
  if (t === "leave-types") void loadLeaveTypes();
}

onMounted(loadDesignations);
</script>

<template>
  <div class="view">
    <div class="view-header">
      <h2>Admin</h2>
      <CompanySelector @change="() => switchTab(tab)" />
    </div>

    <div class="tab-bar">
      <button class="tab-btn" :class="{ active: tab === 'designations' }" @click="switchTab('designations')">Designations</button>
      <button class="tab-btn" :class="{ active: tab === 'grades' }" @click="switchTab('grades')">Grades</button>
      <button class="tab-btn" :class="{ active: tab === 'leave-types' }" @click="switchTab('leave-types')">Leave Types</button>
    </div>

    <p v-if="loading" class="status">Loading…</p>
    <p v-if="error" class="error">{{ error }}</p>

    <!-- DESIGNATIONS -->
    <template v-if="tab === 'designations'">
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Name</th></tr>
          </thead>
          <tbody>
            <tr v-for="d in designations" :key="d.name">
              <td>{{ d.name }}</td>
            </tr>
            <tr v-if="designations.length === 0 && !loading">
              <td class="empty">No designations found.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- GRADES -->
    <template v-if="tab === 'grades'">
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Grade</th><th>Default Salary Structure</th><th>Default Base Pay (KES)</th></tr>
          </thead>
          <tbody>
            <tr v-for="g in grades" :key="g.name">
              <td>{{ g.name }}</td>
              <td>{{ g.default_salary_structure || "-" }}</td>
              <td>{{ g.default_base_pay != null ? g.default_base_pay.toLocaleString() : "-" }}</td>
            </tr>
            <tr v-if="grades.length === 0 && !loading">
              <td colspan="3" class="empty">No grades found.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- LEAVE TYPES -->
    <template v-if="tab === 'leave-types'">
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Leave Type</th><th>Max Days</th><th>Paid</th><th>Carry Forward</th><th>LWP</th></tr>
          </thead>
          <tbody>
            <tr v-for="lt in leaveTypes" :key="lt.name">
              <td>{{ lt.name }}</td>
              <td>{{ lt.max_leaves_allowed ?? "Unlimited" }}</td>
              <td>{{ lt.allow_negative ? "Yes" : "No" }}</td>
              <td>{{ lt.is_carry_forward ? "Yes" : "No" }}</td>
              <td>{{ (lt as any).is_lwp ? "Yes" : "No" }}</td>
            </tr>
            <tr v-if="leaveTypes.length === 0 && !loading">
              <td colspan="5" class="empty">No leave types found.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>
