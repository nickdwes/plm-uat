<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
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

const initials = computed(() => {
  if (!employee.value?.employee_name) return "?";
  return employee.value.employee_name
    .split(" ")
    .slice(0, 2)
    .map((w) => w[0])
    .join("")
    .toUpperCase();
});

function tenure(dateStr: string | null): string {
  if (!dateStr) return "—";
  const start = new Date(dateStr);
  const now = new Date();
  const months =
    (now.getFullYear() - start.getFullYear()) * 12 +
    (now.getMonth() - start.getMonth());
  if (months < 1) return "< 1 month";
  if (months < 12) return `${months} month${months > 1 ? "s" : ""}`;
  const yrs = Math.floor(months / 12);
  const mo = months % 12;
  return mo ? `${yrs}y ${mo}mo` : `${yrs} year${yrs > 1 ? "s" : ""}`;
}

function formatDate(d: string | null): string {
  if (!d) return "—";
  return new Date(d).toLocaleDateString("en-GB", {
    day: "numeric",
    month: "short",
    year: "numeric",
  });
}

const onboardingProgress = computed(() => {
  const list = employee.value?.onboarding_checklist ?? [];
  if (!list.length) return null;
  const done = list.filter((i) => i.status === "Completed").length;
  return { done, total: list.length, pct: Math.round((done / list.length) * 100) };
});

onMounted(load);
</script>

<template>
  <div class="view">

    <!-- Top nav -->
    <div class="emp-topbar">
      <button class="back-link" @click="router.back()">← Back to People</button>
      <span v-if="employee" :class="['badge', employee.status === 'Active' ? 'badge-green' : 'badge-gray']">
        {{ employee.status }}
      </span>
    </div>

    <!-- Loading / error -->
    <div v-if="loading" class="org-loading">
      <span class="org-loading-spinner"></span>
      <span>Loading employee…</span>
    </div>
    <p v-if="error" class="error-banner">{{ error }}</p>

    <template v-if="employee">

      <!-- Hero -->
      <div class="emp-hero">
        <div class="emp-avatar">{{ initials }}</div>
        <div class="emp-hero-body">
          <h2 class="emp-name">{{ employee.employee_name }}</h2>
          <p class="emp-subtitle">
            <span v-if="employee.designation">{{ employee.designation }}</span>
            <span v-if="employee.designation && employee.department" class="emp-sep">·</span>
            <span v-if="employee.department">{{ employee.department }}</span>
            <span class="emp-sep">·</span>
            <span>{{ employee.company }}</span>
          </p>
          <p class="emp-id">Employee ID: {{ employee.name }}</p>
        </div>
      </div>

      <!-- Metric tiles -->
      <div class="emp-metrics">
        <article class="emp-metric-card">
          <p class="emp-metric-label">Grade / Level</p>
          <p class="emp-metric-value">{{ employee.grade ?? "—" }}</p>
        </article>
        <article class="emp-metric-card">
          <p class="emp-metric-label">Tenure</p>
          <p class="emp-metric-value">{{ tenure(employee.date_of_joining) }}</p>
          <p class="emp-metric-sub">Since {{ formatDate(employee.date_of_joining) }}</p>
        </article>
        <article class="emp-metric-card">
          <p class="emp-metric-label">Employment Type</p>
          <p class="emp-metric-value">{{ employee.employment_type ?? "—" }}</p>
        </article>
        <article class="emp-metric-card">
          <p class="emp-metric-label">Branch</p>
          <p class="emp-metric-value">{{ employee.branch ?? "—" }}</p>
        </article>
      </div>

      <!-- Info panels -->
      <div class="emp-panels">

        <!-- Work Details -->
        <section class="emp-panel">
          <div class="emp-panel-header">
            <span class="emp-panel-icon emp-icon-blue">🏢</span>
            <h3 class="emp-panel-title">Work Details</h3>
          </div>
          <div class="emp-fields">
            <div class="emp-field">
              <span class="emp-field-label">Department</span>
              <span class="emp-field-value">{{ employee.department ?? "—" }}</span>
            </div>
            <div class="emp-field">
              <span class="emp-field-label">Designation</span>
              <span class="emp-field-value">{{ employee.designation ?? "—" }}</span>
            </div>
            <div class="emp-field">
              <span class="emp-field-label">Reports To</span>
              <span class="emp-field-value">{{ employee.reports_to ?? "—" }}</span>
            </div>
            <div class="emp-field">
              <span class="emp-field-label">Notice Period</span>
              <span class="emp-field-value">
                {{ employee.notice_period != null ? `${employee.notice_period} days` : "—" }}
              </span>
            </div>
            <div class="emp-field" v-if="isCompanyHR">
              <span class="emp-field-label">Salary Mode</span>
              <span class="emp-field-value">{{ employee.salary_mode ?? "—" }}</span>
            </div>
          </div>
        </section>

        <!-- Personal Details (HR only) -->
        <section class="emp-panel" v-if="isCompanyHR">
          <div class="emp-panel-header">
            <span class="emp-panel-icon emp-icon-purple">👤</span>
            <h3 class="emp-panel-title">Personal Details</h3>
          </div>
          <div class="emp-fields">
            <div class="emp-field">
              <span class="emp-field-label">Gender</span>
              <span class="emp-field-value">{{ employee.gender ?? "—" }}</span>
            </div>
            <div class="emp-field">
              <span class="emp-field-label">Date of Birth</span>
              <span class="emp-field-value">{{ formatDate(employee.date_of_birth) }}</span>
            </div>
            <div class="emp-field">
              <span class="emp-field-label">Personal Email</span>
              <span class="emp-field-value">{{ employee.personal_email ?? "—" }}</span>
            </div>
            <div class="emp-field">
              <span class="emp-field-label">Work Email</span>
              <span class="emp-field-value">{{ employee.company_email ?? "—" }}</span>
            </div>
            <div class="emp-field">
              <span class="emp-field-label">Mobile</span>
              <span class="emp-field-value">{{ employee.cell_number ?? "—" }}</span>
            </div>
          </div>
        </section>

        <!-- Onboarding Status -->
        <section class="emp-panel">
          <div class="emp-panel-header">
            <span class="emp-panel-icon emp-icon-green">✅</span>
            <h3 class="emp-panel-title">Onboarding</h3>
          </div>
          <div class="emp-fields">
            <div class="emp-field">
              <span class="emp-field-label">Status</span>
              <span class="emp-field-value">
                <span
                  v-if="employee.onboarding_status?.status"
                  :class="['badge', employee.onboarding_status.status === 'Completed' ? 'badge-green' : 'badge-yellow']"
                >{{ employee.onboarding_status.status }}</span>
                <span v-else class="text-muted">Not started</span>
              </span>
            </div>
            <template v-if="onboardingProgress">
              <div class="emp-field">
                <span class="emp-field-label">Progress</span>
                <span class="emp-field-value">{{ onboardingProgress.done }} / {{ onboardingProgress.total }} tasks</span>
              </div>
              <div class="emp-progress-bar">
                <div class="emp-progress-fill" :style="{ width: onboardingProgress.pct + '%' }"></div>
              </div>
            </template>
            <div v-else>
              <span class="text-muted">No checklist items</span>
            </div>
          </div>
        </section>

      </div>

      <!-- Onboarding checklist table -->
      <section v-if="employee.onboarding_checklist?.length" class="emp-table-section">
        <h3 class="section-title">Onboarding Checklist</h3>
        <div class="table-wrap card">
          <table>
            <thead>
              <tr>
                <th>Activity</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in employee.onboarding_checklist" :key="item.activity_name">
                <td>{{ item.activity_name }}</td>
                <td>
                  <span :class="['badge', item.status === 'Completed' ? 'badge-green' : 'badge-yellow']">
                    {{ item.status }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Documents (HR only) -->
      <section v-if="isCompanyHR && employee.documents?.length" class="emp-table-section">
        <h3 class="section-title">Documents</h3>
        <div class="table-wrap card">
          <table>
            <thead>
              <tr>
                <th>File Name</th>
                <th>Uploaded</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="doc in employee.documents" :key="doc.name">
                <td>{{ doc.file_name }}</td>
                <td>{{ formatDate(doc.creation) }}</td>
                <td>
                  <a :href="authStore.baseUrl + doc.file_url" target="_blank" class="text-blue">
                    Download
                  </a>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

    </template>
  </div>
</template>
