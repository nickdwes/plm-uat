<script setup lang="ts">
import { ref, computed, onMounted, watch } from "vue";
import { useRouter } from "vue-router";
import { api } from "../lib/api";
import { currentOrgSlug } from "../stores/auth";
import type { Paginator, Employee } from "../lib/types";

const router = useRouter();

const employees   = ref<Employee[]>([]);
const total       = ref(0);
const currentPage = ref(1);
const lastPage    = ref(1);
const perPage     = 20;
const search      = ref("");
const loading     = ref(false);
const error       = ref("");

const hasPrev = computed(() => currentPage.value > 1);
const hasNext = computed(() => currentPage.value < lastPage.value);

function org(): string | null { return currentOrgSlug.value; }

async function load(page = 1): Promise<void> {
  if (!org()) { error.value = "Select an organisation first."; return; }
  loading.value = true;
  error.value   = "";
  currentPage.value = page;
  try {
    const res = await api<Paginator<Employee>>("GET", "/api/v1/employees", {
      org: org(),
      params: {
        page,
        per_page: perPage,
        search: search.value.trim() || undefined,
      },
    });
    employees.value = res.data;
    total.value     = res.meta.total;
    lastPage.value  = res.meta.last_page;
  } catch (err) {
    error.value = err instanceof Error ? err.message : "Failed to load employees.";
  } finally {
    loading.value = false;
  }
}

function statusBadge(status: string): string {
  if (status === "active") return "badge-green";
  if (status === "inactive") return "badge-yellow";
  return "badge-gray";
}

onMounted(() => load(1));
watch(currentOrgSlug, () => load(1));
</script>

<template>
  <div class="view">
    <div class="view-header">
      <h2>Employees</h2>
      <div class="header-actions">
        <router-link :to="{ name: 'onboard' }" class="btn btn-primary btn-sm">+ Onboard Employee</router-link>
        <router-link :to="{ name: 'dashboard' }" class="btn btn-secondary btn-sm">← Dashboard</router-link>
      </div>
    </div>

    <div class="toolbar">
      <input
        v-model="search"
        type="search"
        class="input"
        placeholder="Search by name or ID…"
        @keyup.enter="load(1)"
        style="max-width:280px"
      />
      <button class="btn btn-primary btn-sm" :disabled="loading" @click="load(1)">Search</button>
    </div>

    <p v-if="loading" class="status">Loading…</p>
    <p v-if="error" class="error">{{ error }}</p>

    <div v-if="!loading" class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Department</th>
            <th>Designation</th>
            <th>Joined</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="emp in employees"
            :key="emp.id"
            @click="router.push({ name: 'employee-detail', params: { id: emp.id } })"
          >
            <td style="font-weight:600;font-size:12px;color:var(--text-muted)">{{ emp.employee_number }}</td>
            <td style="font-weight:600">
              {{ emp.full_name }}
              <!--
                The directory now lists people placed here from another company
                in the group. Without saying so, the only clue that somebody is
                on a different payroll is a department name that looks
                unfamiliar.
              -->
              <span v-if="emp.is_visiting" class="visiting" :title="`Employed by ${emp.home_organization}`">
                {{ emp.home_organization }}
              </span>
            </td>
            <!-- A visitor's department and title are the ones their placement
                 gives them here; their own belong to a tenant this organisation
                 cannot see. -->
            <td>{{ emp.host_department ?? emp.department?.name ?? "—" }}</td>
            <td>{{ emp.host_title ?? emp.designation?.name ?? "—" }}</td>
            <td>{{ emp.date_of_joining ?? "—" }}</td>
            <td><span :class="['badge', statusBadge(emp.status)]">{{ emp.status }}</span></td>
          </tr>
          <tr v-if="employees.length === 0">
            <td colspan="6" class="empty">No employees found.</td>
          </tr>
        </tbody>
      </table>

      <div class="pagination">
        <span>{{ total }} total</span>
        <button class="btn btn-secondary btn-sm" :disabled="!hasPrev" @click="load(currentPage - 1)">← Prev</button>
        <span>{{ currentPage }} / {{ lastPage }}</span>
        <button class="btn btn-secondary btn-sm" :disabled="!hasNext" @click="load(currentPage + 1)">Next →</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
/*
 * A visiting colleague's employer, beside their name.
 *
 * Deliberately quiet — it is a fact about somebody's contract, not a warning,
 * and on a directory where several people are seconded a loud badge on each
 * would be the first thing the eye lands on instead of the names.
 */
.visiting {
  display: inline-block;
  margin-left: 8px;
  padding: 1px 7px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 500;
  color: var(--color-text-light);
  background: var(--color-surface-sunken);
  border: 1px solid var(--color-border);
  vertical-align: middle;
}
</style>
