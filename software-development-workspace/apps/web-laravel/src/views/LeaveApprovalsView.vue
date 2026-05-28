<script setup lang="ts">
import { ref, onMounted, watch } from "vue";
import { api } from "../lib/api";
import { currentOrgSlug } from "../stores/auth";
import type { LeaveRequest } from "../lib/types";

const pending  = ref<LeaveRequest[]>([]);
const history  = ref<LeaveRequest[]>([]);
const tab      = ref<"pending" | "all">("pending");
const loading  = ref(false);
const error    = ref("");
const actioning= ref<number | null>(null);
const actionError = ref("");

function org(): string | null { return currentOrgSlug.value; }

async function load(): Promise<void> {
  if (!org()) { error.value = "Select an organisation first."; return; }
  loading.value = true; error.value = "";
  try {
    const [p, a] = await Promise.all([
      api<{ data: LeaveRequest[] }>("GET", "/api/v1/leave", { org: org(), params: { status: "pending", per_page: 100 } }),
      api<{ data: LeaveRequest[] }>("GET", "/api/v1/leave", { org: org(), params: { per_page: 100 } }),
    ]);
    pending.value = (p as any).data ?? [];
    history.value = (a as any).data ?? [];
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to load.";
  } finally {
    loading.value = false;
  }
}

async function approve(id: number): Promise<void> {
  actioning.value = id; actionError.value = "";
  try {
    await api("POST", `/api/v1/leave/${id}/approve`, { org: org() });
    await load();
  } catch (e) { actionError.value = e instanceof Error ? e.message : "Failed."; }
  finally { actioning.value = null; }
}

async function reject(id: number): Promise<void> {
  const reason = prompt("Rejection reason (optional):");
  if (reason === null) return;  // cancelled
  actioning.value = id; actionError.value = "";
  try {
    await api("POST", `/api/v1/leave/${id}/reject`, { org: org(), body: { reason } });
    await load();
  } catch (e) { actionError.value = e instanceof Error ? e.message : "Failed."; }
  finally { actioning.value = null; }
}

function statusBadge(status: string): string {
  if (status === "approved") return "badge-green";
  if (status === "pending")  return "badge-yellow";
  if (status === "rejected") return "badge-red";
  return "badge-gray";
}

onMounted(load);
watch(currentOrgSlug, load);
</script>

<template>
  <div class="view">
    <div class="view-header">
      <h2>Leave Approvals</h2>
      <router-link :to="{ name: 'hr-dashboard' }" class="btn btn-secondary btn-sm">← Dashboard</router-link>
    </div>

    <p v-if="loading" class="status">Loading…</p>
    <p v-if="error"   class="error">{{ error }}</p>
    <p v-if="actionError" class="error">{{ actionError }}</p>

    <!-- Tab bar -->
    <div class="tabs" style="margin-bottom:20px">
      <button :class="['tab', { active: tab === 'pending' }]" @click="tab = 'pending'">
        Pending
        <span v-if="pending.length" class="badge badge-yellow" style="margin-left:6px">{{ pending.length }}</span>
      </button>
      <button :class="['tab', { active: tab === 'all' }]" @click="tab = 'all'">All Applications</button>
    </div>

    <!-- Pending -->
    <template v-if="tab === 'pending'">
      <div v-if="!pending.length && !loading" class="card status">No pending leave requests.</div>
      <div v-for="r in pending" :key="r.id" class="card" style="margin-bottom:12px">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px">
          <div>
            <p style="font-weight:600;margin-bottom:4px">{{ r.employee?.first_name }} {{ r.employee?.last_name }}
              <span class="text-muted" style="font-weight:400;font-size:0.85em"> · {{ r.employee?.employee_number }}</span>
            </p>
            <p class="text-muted" style="margin-bottom:4px">{{ r.leave_type?.name }} &nbsp;·&nbsp; {{ r.from_date }} → {{ r.to_date }} &nbsp;·&nbsp; <strong>{{ r.days_requested }} days</strong></p>
            <p v-if="r.reason" class="text-muted" style="font-size:0.9em">{{ r.reason }}</p>
          </div>
          <div style="display:flex;gap:8px;flex-shrink:0">
            <button class="btn btn-primary btn-sm" :disabled="actioning === r.id" @click="approve(r.id)">Approve</button>
            <button class="btn btn-secondary btn-sm" :disabled="actioning === r.id" @click="reject(r.id)">Reject</button>
          </div>
        </div>
      </div>
    </template>

    <!-- All -->
    <template v-if="tab === 'all'">
      <div class="card">
        <table>
          <thead>
            <tr>
              <th>Employee</th>
              <th>Leave Type</th>
              <th>From</th>
              <th>To</th>
              <th style="text-align:center">Days</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in history" :key="r.id">
              <td>{{ r.employee?.first_name }} {{ r.employee?.last_name }}</td>
              <td>{{ r.leave_type?.name }}</td>
              <td>{{ r.from_date }}</td>
              <td>{{ r.to_date }}</td>
              <td style="text-align:center">{{ r.days_requested }}</td>
              <td><span :class="['badge', statusBadge(r.status)]">{{ r.status }}</span></td>
              <td style="text-align:right">
                <template v-if="r.status === 'pending'">
                  <button class="btn btn-sm" style="color:var(--green)" :disabled="actioning === r.id" @click="approve(r.id)">Approve</button>
                  &nbsp;
                  <button class="btn btn-sm" style="color:var(--red)"   :disabled="actioning === r.id" @click="reject(r.id)">Reject</button>
                </template>
              </td>
            </tr>
            <tr v-if="!history.length"><td colspan="7" class="status">No leave applications.</td></tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>
