<script setup lang="ts">
import { ref, onMounted } from "vue";
import { useRouter } from "vue-router";
import { callFrappeMethod, type FrappeAuthConfig } from "../lib/frappe-client";
import { authStore, isCompanyHR } from "../stores/auth";
import type { EmployeeDetail } from "../lib/types";

const props = defineProps<{ id: string }>();
const router = useRouter();
const employee = ref<EmployeeDetail | null>(null);
const loading = ref(false);
const error = ref("");

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
    employee.value = await callFrappeMethod<EmployeeDetail>(
      authConfig(),
      "bitwyse_hrms_backend.api.get_employee",
      { employee_id: props.id },
    );
  } catch (err) {
    error.value = err instanceof Error ? err.message : "Failed to load employee.";
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<template>
  <div class="view">
    <div class="view-header">
      <button class="ghost small" @click="router.back()">← Back</button>
      <h2>Employee Detail</h2>
    </div>

    <p v-if="loading" class="status">Loading…</p>
    <p v-if="error" class="error">{{ error }}</p>

    <template v-if="employee">
      <section class="detail-card">
        <h3>{{ employee.employee_name }}</h3>
        <dl class="detail-grid">
          <dt>Employee ID</dt><dd>{{ employee.name }}</dd>
          <dt>Company</dt><dd>{{ employee.company }}</dd>
          <dt>Department</dt><dd>{{ employee.department ?? "-" }}</dd>
          <dt>Designation</dt><dd>{{ employee.designation ?? "-" }}</dd>
          <dt>Grade</dt><dd>{{ employee.grade ?? "-" }}</dd>
          <dt>Status</dt>
          <dd>
            <span :class="['badge', employee.status === 'Active' ? 'badge-green' : 'badge-grey']">
              {{ employee.status }}
            </span>
          </dd>
          <dt>Joined</dt><dd>{{ employee.date_of_joining ?? "-" }}</dd>
          <dt>Branch</dt><dd>{{ employee.branch ?? "-" }}</dd>
          <dt>Employment Type</dt><dd>{{ employee.employment_type ?? "-" }}</dd>
          <template v-if="isCompanyHR">
            <dt>Personal Email</dt><dd>{{ employee.personal_email ?? "-" }}</dd>
            <dt>Work Email</dt><dd>{{ employee.company_email ?? "-" }}</dd>
            <dt>Mobile</dt><dd>{{ employee.cell_number ?? "-" }}</dd>
            <dt>Salary Mode</dt><dd>{{ employee.salary_mode ?? "-" }}</dd>
          </template>
        </dl>
      </section>

      <!-- Onboarding checklist -->
      <section v-if="employee.onboarding_checklist?.length" class="detail-card">
        <h4>Onboarding Checklist</h4>
        <ul class="checklist">
          <li
            v-for="item in employee.onboarding_checklist"
            :key="item.activity_name"
            :class="item.status === 'Completed' ? 'done' : 'pending'"
          >
            {{ item.activity_name }}
            <span class="badge badge-grey">{{ item.status }}</span>
          </li>
        </ul>
      </section>
    </template>
  </div>
</template>
