<script setup lang="ts">
import { ref, onMounted } from "vue";
import { callFrappeMethod, type FrappeAuthConfig } from "../lib/frappe-client";
import { authStore, isManager } from "../stores/auth";
import type { LeaveRequestRecord, LeaveBalanceSummary, PendingApprovalItem } from "../lib/types";
import CompanySelector from "../components/CompanySelector.vue";

type Tab = "my-leaves" | "pending" | "team";
const tab = ref<Tab>("my-leaves");

const myRequests = ref<LeaveRequestRecord[]>([]);
const balances = ref<LeaveBalanceSummary | null>(null);
const pending = ref<PendingApprovalItem[]>([]);
const loading = ref(false);
const error = ref("");

// New leave request form
const newLeave = ref({ leave_type: "", from_date: "", to_date: "", description: "" });
const submitting = ref(false);
const submitError = ref("");
const submitSuccess = ref(false);

function authConfig(): FrappeAuthConfig {
  return {
    baseUrl: authStore.baseUrl,
    apiKey: authStore.apiKey || undefined,
    apiSecret: authStore.apiSecret || undefined,
  };
}

async function loadMyLeaves(): Promise<void> {
  loading.value = true;
  error.value = "";
  try {
    const [reqResult, balResult] = await Promise.all([
      callFrappeMethod<{ items: LeaveRequestRecord[] }>(
        authConfig(),
        "bitwyse_hrms_backend.api.list_leave_requests",
        { company: authStore.company ?? undefined },
      ),
      callFrappeMethod<LeaveBalanceSummary>(
        authConfig(),
        "bitwyse_hrms_backend.api.get_leave_balances",
        { company: authStore.company ?? undefined },
      ),
    ]);
    myRequests.value = reqResult.items ?? [];
    balances.value = balResult;
  } catch (err) {
    error.value = err instanceof Error ? err.message : "Failed to load leave data.";
  } finally {
    loading.value = false;
  }
}

async function loadPending(): Promise<void> {
  loading.value = true;
  error.value = "";
  try {
    const result = await callFrappeMethod<{ items: PendingApprovalItem[] }>(
      authConfig(),
      "bitwyse_hrms_backend.api.list_pending_approvals",
      { company: authStore.company ?? undefined },
    );
    pending.value = result.items ?? [];
  } catch (err) {
    error.value = err instanceof Error ? err.message : "Failed to load pending approvals.";
  } finally {
    loading.value = false;
  }
}

async function submitLeave(): Promise<void> {
  submitting.value = true;
  submitError.value = "";
  submitSuccess.value = false;
  try {
    await callFrappeMethod(
      authConfig(),
      "bitwyse_hrms_backend.api.submit_leave_request",
      {
        company: authStore.company ?? undefined,
        ...newLeave.value,
      },
    );
    submitSuccess.value = true;
    newLeave.value = { leave_type: "", from_date: "", to_date: "", description: "" };
    await loadMyLeaves();
  } catch (err) {
    submitError.value = err instanceof Error ? err.message : "Failed to submit leave.";
  } finally {
    submitting.value = false;
  }
}

async function approve(name: string): Promise<void> {
  try {
    await callFrappeMethod(authConfig(), "bitwyse_hrms_backend.api.approve_leave_request", {
      leave_request_id: name,
    });
    await loadPending();
  } catch (err) {
    error.value = err instanceof Error ? err.message : "Approval failed.";
  }
}

async function reject(name: string): Promise<void> {
  try {
    await callFrappeMethod(authConfig(), "bitwyse_hrms_backend.api.reject_leave_request", {
      leave_request_id: name,
    });
    await loadPending();
  } catch (err) {
    error.value = err instanceof Error ? err.message : "Rejection failed.";
  }
}

function switchTab(t: Tab): void {
  tab.value = t;
  error.value = "";
  if (t === "my-leaves") void loadMyLeaves();
  if (t === "pending") void loadPending();
}

onMounted(() => loadMyLeaves());
</script>

<template>
  <div class="view">
    <div class="view-header">
      <h2>Leave</h2>
      <CompanySelector @change="loadMyLeaves" />
    </div>

    <div class="tab-bar">
      <button :class="{ active: tab === 'my-leaves' }" @click="switchTab('my-leaves')">My Leave</button>
      <button v-if="isManager" :class="{ active: tab === 'pending' }" @click="switchTab('pending')">
        Pending Approvals
        <span v-if="pending.length" class="badge badge-orange">{{ pending.length }}</span>
      </button>
    </div>

    <p v-if="loading" class="status">Loading…</p>
    <p v-if="error" class="error">{{ error }}</p>

    <!-- MY LEAVE TAB -->
    <template v-if="tab === 'my-leaves'">
      <!-- Leave balances -->
      <section v-if="balances?.balances?.length" class="panel">
        <h3>Balances as of today</h3>
        <div class="metric-grid">
          <article v-for="b in balances.balances" :key="b.leave_type" class="metric-card small">
            <p class="metric-label">{{ b.leave_type }}</p>
            <p class="metric-value">{{ b.remaining }}<span class="metric-unit"> days</span></p>
            <p class="metric-sub">{{ b.taken }} taken / {{ b.allocated }} allocated</p>
          </article>
        </div>
      </section>

      <!-- Submit new leave -->
      <section class="panel">
        <h3>Apply for Leave</h3>
        <form class="leave-form" @submit.prevent="submitLeave">
          <div class="grid two-col">
            <label>
              Leave Type
              <input v-model="newLeave.leave_type" type="text" placeholder="Annual Leave" required />
            </label>
            <label>
              From Date
              <input v-model="newLeave.from_date" type="date" required />
            </label>
            <label>
              To Date
              <input v-model="newLeave.to_date" type="date" required />
            </label>
            <label>
              Reason
              <input v-model="newLeave.description" type="text" placeholder="Optional" />
            </label>
          </div>
          <p v-if="submitError" class="error">{{ submitError }}</p>
          <p v-if="submitSuccess" class="success">Leave application submitted!</p>
          <button type="submit" class="primary small" :disabled="submitting">
            {{ submitting ? "Submitting…" : "Submit" }}
          </button>
        </form>
      </section>

      <!-- My requests list -->
      <section class="panel">
        <h3>My Requests</h3>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Type</th><th>From</th><th>To</th><th>Days</th><th>Status</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="req in myRequests" :key="req.name">
                <td>{{ req.leave_type }}</td>
                <td>{{ req.from_date }}</td>
                <td>{{ req.to_date }}</td>
                <td>{{ req.total_leave_days }}</td>
                <td>
                  <span :class="['badge', req.status === 'Approved' ? 'badge-green' : req.status === 'Rejected' ? 'badge-red' : 'badge-grey']">
                    {{ req.status }}
                  </span>
                </td>
              </tr>
              <tr v-if="myRequests.length === 0 && !loading">
                <td colspan="5" class="empty">No leave requests found.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>

    <!-- PENDING APPROVALS TAB -->
    <template v-if="tab === 'pending'">
      <section class="panel">
        <h3>Pending Approvals ({{ pending.length }})</h3>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Employee</th><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in pending" :key="item.name">
                <td>{{ item.employee_name }}</td>
                <td>{{ item.leave_type }}</td>
                <td>{{ item.from_date }}</td>
                <td>{{ item.to_date }}</td>
                <td>{{ item.total_leave_days }}</td>
                <td class="actions-cell">
                  <button class="primary small" @click="approve(item.name)">Approve</button>
                  <button class="danger small" @click="reject(item.name)">Reject</button>
                </td>
              </tr>
              <tr v-if="pending.length === 0 && !loading">
                <td colspan="6" class="empty">No pending approvals.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </div>
</template>
