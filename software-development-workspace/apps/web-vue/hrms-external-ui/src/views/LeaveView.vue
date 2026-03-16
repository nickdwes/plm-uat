<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
import { callFrappeMethod, type FrappeAuthConfig } from "../lib/frappe-client";
import { authStore, isManager, isCompanyHR } from "../stores/auth";
import type { LeaveRequestRecord, LeaveBalanceSummary, PendingApprovalItem, LeaveTypeRecord } from "../lib/types";
import CompanySelector from "../components/CompanySelector.vue";

type Tab = "overview" | "apply" | "my-leaves" | "pending";
const tab = ref<Tab>("overview");

// Data
const myRequests = ref<LeaveRequestRecord[]>([]);
const balances = ref<LeaveBalanceSummary | null>(null);
const leaveTypes = ref<LeaveTypeRecord[]>([]);
const pending = ref<PendingApprovalItem[]>([]);
const loading = ref(false);
const error = ref("");

// --- Multi-entry leave application ---
interface LeaveEntry {
  id: number;
  leave_type: string;
  from_date: string;
  to_date: string;
  half_day: boolean;
  description: string;
}
let nextId = 1;
const leaveEntries = ref<LeaveEntry[]>([newEntry()]);
const submitting = ref(false);
const submitError = ref("");
const submitSuccess = ref<string[]>([]);
const supervisorEmail = ref("");
const hrManagerEmail = ref("");

function newEntry(): LeaveEntry {
  return { id: nextId++, leave_type: "", from_date: "", to_date: "", half_day: false, description: "" };
}
function addEntry(): void { leaveEntries.value.push(newEntry()); }
function removeEntry(id: number): void {
  if (leaveEntries.value.length > 1) leaveEntries.value = leaveEntries.value.filter(e => e.id !== id);
}

// Computed stats
const totalAllocated = computed(() =>
  (balances.value?.balances ?? []).reduce((sum, b) => sum + b.allocated, 0)
);
const totalTaken = computed(() =>
  (balances.value?.balances ?? []).reduce((sum, b) => sum + b.taken, 0)
);
const totalRemaining = computed(() =>
  (balances.value?.balances ?? []).reduce((sum, b) => sum + b.remaining, 0)
);
const totalCarryForward = computed(() => {
  if (!balances.value?.balances) return 0;
  const cfTypes = new Set(leaveTypes.value.filter(t => t.is_carry_forward).map(t => t.name));
  return balances.value.balances
    .filter(b => cfTypes.has(b.leave_type) && b.remaining > 0)
    .reduce((sum, b) => sum + b.remaining, 0);
});
const totalApplied = computed(() => myRequests.value.filter(r => r.status === "Open").length);
const nextScheduled = computed(() => {
  const future = myRequests.value
    .filter(r => r.status === "Approved" && r.from_date >= new Date().toISOString().slice(0, 10))
    .sort((a, b) => a.from_date.localeCompare(b.from_date));
  return future[0] ?? null;
});

function authConfig(): FrappeAuthConfig {
  return { baseUrl: authStore.baseUrl, apiKey: authStore.apiKey || undefined, apiSecret: authStore.apiSecret || undefined };
}

async function loadAll(): Promise<void> {
  loading.value = true; error.value = "";
  try {
    const [reqRes, balRes, typesRes] = await Promise.all([
      callFrappeMethod<{ items: LeaveRequestRecord[] }>(authConfig(), "bitwyse_hrms_backend.api.list_leave_requests", { company: authStore.company ?? undefined }),
      callFrappeMethod<LeaveBalanceSummary>(authConfig(), "bitwyse_hrms_backend.api.get_leave_balances", { company: authStore.company ?? undefined }),
      callFrappeMethod<{ items: LeaveTypeRecord[] }>(authConfig(), "bitwyse_hrms_backend.api.list_leave_types", {}),
    ]);
    myRequests.value = reqRes.items ?? [];
    balances.value = balRes;
    leaveTypes.value = typesRes.items ?? [];
  } catch (err) {
    error.value = err instanceof Error ? err.message : "Failed to load leave data.";
  } finally { loading.value = false; }
}

async function loadPending(): Promise<void> {
  loading.value = true; error.value = "";
  try {
    const res = await callFrappeMethod<{ items: PendingApprovalItem[] }>(authConfig(), "bitwyse_hrms_backend.api.list_pending_approvals", { company: authStore.company ?? undefined });
    pending.value = res.items ?? [];
  } catch (err) { error.value = err instanceof Error ? err.message : "Failed to load approvals."; }
  finally { loading.value = false; }
}

async function submitLeaves(): Promise<void> {
  const valid = leaveEntries.value.every(e => e.leave_type && e.from_date && e.to_date);
  if (!valid) { submitError.value = "Please fill all required fields for every row."; return; }
  submitting.value = true; submitError.value = ""; submitSuccess.value = [];
  const employeeId = balances.value?.employee || "";
  if (!employeeId) { submitError.value = "No employee record linked to this account. Cannot submit leave."; submitting.value = false; return; }
  try {
    const results: string[] = [];
    for (const entry of leaveEntries.value) {
      await callFrappeMethod(authConfig(), "bitwyse_hrms_backend.api.submit_leave_request", {
        employee: employeeId,
        leave_type: entry.leave_type,
        from_date: entry.from_date,
        to_date: entry.to_date,
        half_day: entry.half_day ? 1 : 0,
        description: entry.description || undefined,
        leave_approver: supervisorEmail.value || undefined,
      });
      results.push(`${entry.leave_type}: ${entry.from_date} → ${entry.to_date}`);
    }
    submitSuccess.value = results;
    leaveEntries.value = [newEntry()];
    supervisorEmail.value = ""; hrManagerEmail.value = "";
    await loadAll();
    tab.value = "my-leaves";
  } catch (err) { submitError.value = err instanceof Error ? err.message : "Submission failed."; }
  finally { submitting.value = false; }
}

async function approveRequest(name: string): Promise<void> {
  try { await callFrappeMethod(authConfig(), "bitwyse_hrms_backend.api.approve_leave_request", { request_name: name }); await loadPending(); }
  catch (err) { error.value = err instanceof Error ? err.message : "Approval failed."; }
}
async function rejectRequest(name: string): Promise<void> {
  try { await callFrappeMethod(authConfig(), "bitwyse_hrms_backend.api.reject_leave_request", { request_name: name }); await loadPending(); }
  catch (err) { error.value = err instanceof Error ? err.message : "Rejection failed."; }
}

function switchTab(t: Tab): void {
  tab.value = t; error.value = "";
  if (t === "overview" || t === "my-leaves" || t === "apply") void loadAll();
  if (t === "pending") void loadPending();
}

function statusClass(s: string): string {
  return s === "Approved" ? "badge-green" : s === "Rejected" ? "badge-red" : s === "Cancelled" ? "badge-gray" : "badge-yellow";
}

function formatDate(d: string): string {
  if (!d) return "-";
  return new Date(d).toLocaleDateString("en-KE", { day: "2-digit", month: "short", year: "numeric" });
}

function daysBetween(from: string, to: string): number {
  if (!from || !to) return 0;
  return Math.max(0, Math.round((new Date(to).getTime() - new Date(from).getTime()) / 86400000) + 1);
}

onMounted(loadAll);
</script>

<template>
  <div class="view">
    <div class="view-header">
      <h2>Leave Management</h2>
      <CompanySelector @change="loadAll" />
    </div>

    <div class="tab-bar">
      <button class="tab-btn" :class="{ active: tab === 'overview' }" @click="switchTab('overview')">Overview</button>
      <button class="tab-btn" :class="{ active: tab === 'apply' }" @click="switchTab('apply')">Apply for Leave</button>
      <button class="tab-btn" :class="{ active: tab === 'my-leaves' }" @click="switchTab('my-leaves')">My Requests</button>
      <button v-if="isManager || isCompanyHR" class="tab-btn" :class="{ active: tab === 'pending' }" @click="switchTab('pending')">
        Pending Approvals
        <span v-if="pending.length && tab !== 'pending'" class="badge badge-yellow" style="margin-left:6px">{{ pending.length }}</span>
      </button>
    </div>

    <p v-if="loading" class="status">Loading…</p>
    <p v-if="error" class="error">{{ error }}</p>

    <!-- ── OVERVIEW TAB ─────────────────────────────────────── -->
    <template v-if="tab === 'overview'">
      <!-- Stats row -->
      <div class="leave-stats-grid">
        <div class="leave-stat-card blue">
          <div class="stat-icon">📅</div>
          <div class="stat-body">
            <div class="stat-value">{{ totalAllocated }}</div>
            <div class="stat-label">Days Earned</div>
          </div>
        </div>
        <div class="leave-stat-card green">
          <div class="stat-icon">✅</div>
          <div class="stat-body">
            <div class="stat-value">{{ totalRemaining }}</div>
            <div class="stat-label">Days Available</div>
          </div>
        </div>
        <div class="leave-stat-card orange">
          <div class="stat-icon">🏖️</div>
          <div class="stat-body">
            <div class="stat-value">{{ totalTaken }}</div>
            <div class="stat-label">Days Taken</div>
          </div>
        </div>
        <div class="leave-stat-card yellow">
          <div class="stat-icon">⏳</div>
          <div class="stat-body">
            <div class="stat-value">{{ totalApplied }}</div>
            <div class="stat-label">Pending Approval</div>
          </div>
        </div>
        <div class="leave-stat-card purple">
          <div class="stat-icon">➡️</div>
          <div class="stat-body">
            <div class="stat-value">{{ totalCarryForward }}</div>
            <div class="stat-label">Carry Forward</div>
          </div>
        </div>
        <div class="leave-stat-card teal">
          <div class="stat-icon">📆</div>
          <div class="stat-body">
            <div class="stat-value">{{ nextScheduled ? formatDate(nextScheduled.from_date) : "—" }}</div>
            <div class="stat-label">Next Scheduled Leave</div>
          </div>
        </div>
      </div>

      <!-- Balance breakdown table -->
      <div class="card" style="margin-top:20px">
        <div class="card-header-row">
          <h3 class="section-title" style="margin:0">Leave Balance Breakdown</h3>
          <span class="text-muted" style="font-size:12px">As of today</span>
        </div>
        <div class="table-wrap" style="margin-top:14px">
          <table>
            <thead>
              <tr>
                <th>Leave Type</th>
                <th style="text-align:right">Allocated</th>
                <th style="text-align:right">Taken</th>
                <th style="text-align:right">Remaining</th>
                <th>Balance Bar</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="b in (balances?.balances ?? [])" :key="b.leave_type">
                <td><strong>{{ b.leave_type }}</strong></td>
                <td style="text-align:right">{{ b.allocated }}</td>
                <td style="text-align:right; color:var(--text-muted)">{{ b.taken }}</td>
                <td style="text-align:right">
                  <span :class="['badge', b.remaining > 0 ? 'badge-green' : 'badge-red']">{{ b.remaining }} days</span>
                </td>
                <td style="min-width:140px">
                  <div class="balance-bar-bg">
                    <div class="balance-bar-fill" :style="{ width: b.allocated > 0 ? Math.round((b.taken / b.allocated) * 100) + '%' : '0%' }"></div>
                  </div>
                </td>
              </tr>
              <tr v-if="!balances?.balances?.length && !loading">
                <td colspan="5" class="empty">No leave balances found. Leave allocations may not be set up.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Recent activity -->
      <div class="card" style="margin-top:16px">
        <h3 class="section-title">Recent Requests</h3>
        <div class="table-wrap">
          <table>
            <thead>
              <tr><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Status</th></tr>
            </thead>
            <tbody>
              <tr v-for="r in myRequests.slice(0,5)" :key="r.name">
                <td>{{ r.leave_type }}</td>
                <td>{{ formatDate(r.from_date) }}</td>
                <td>{{ formatDate(r.to_date) }}</td>
                <td>{{ r.total_leave_days }}</td>
                <td><span :class="['badge', statusClass(r.status)]">{{ r.status }}</span></td>
              </tr>
              <tr v-if="!myRequests.length && !loading">
                <td colspan="5" class="empty">No leave requests yet.</td>
              </tr>
            </tbody>
          </table>
        </div>
        <button v-if="myRequests.length > 5" class="btn-ghost btn" style="margin-top:12px;font-size:13px" @click="switchTab('my-leaves')">
          View all {{ myRequests.length }} requests →
        </button>
      </div>
    </template>

    <!-- ── APPLY TAB ──────────────────────────────────────────── -->
    <template v-if="tab === 'apply'">
      <div class="card">
        <h3 class="section-title">New Leave Application</h3>
        <p class="text-muted" style="font-size:13px; margin-bottom:16px">
          Add one or more leave periods below. Each row is a separate leave request submitted together.
        </p>

        <!-- Approval chain -->
        <div class="approval-chain-row">
          <div class="approval-step">
            <div class="approval-step-num">1</div>
            <div class="approval-step-info">
              <div class="approval-step-title">Supervisor Approval</div>
              <input v-model="supervisorEmail" type="email" placeholder="supervisor@company.com" style="margin-top:4px" />
            </div>
          </div>
          <div class="approval-arrow">→</div>
          <div class="approval-step">
            <div class="approval-step-num">2</div>
            <div class="approval-step-info">
              <div class="approval-step-title">HR Manager Review</div>
              <input v-model="hrManagerEmail" type="email" placeholder="hr@company.com (optional)" style="margin-top:4px" />
            </div>
          </div>
          <div class="approval-arrow">→</div>
          <div class="approval-step done">
            <div class="approval-step-num">✓</div>
            <div class="approval-step-info">
              <div class="approval-step-title">Approved</div>
              <div style="font-size:12px; color:var(--text-muted)">Leave confirmed</div>
            </div>
          </div>
        </div>

        <!-- Leave entries table -->
        <div class="table-wrap" style="margin-top:20px">
          <table class="leave-apply-table">
            <thead>
              <tr>
                <th style="width:200px">Leave Type <span style="color:#ef4444">*</span></th>
                <th>From Date <span style="color:#ef4444">*</span></th>
                <th>To Date <span style="color:#ef4444">*</span></th>
                <th style="width:60px; text-align:center">Days</th>
                <th style="width:80px; text-align:center">Half Day</th>
                <th>Reason</th>
                <th style="width:40px"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="entry in leaveEntries" :key="entry.id">
                <td>
                  <select v-model="entry.leave_type" required>
                    <option value="" disabled>Select type</option>
                    <option v-for="lt in leaveTypes" :key="lt.name" :value="lt.name">{{ lt.name }}</option>
                  </select>
                </td>
                <td><input v-model="entry.from_date" type="date" required /></td>
                <td><input v-model="entry.to_date" type="date" required /></td>
                <td style="text-align:center; font-weight:600; color:var(--blue)">
                  {{ entry.from_date && entry.to_date ? daysBetween(entry.from_date, entry.to_date) : '—' }}
                </td>
                <td style="text-align:center">
                  <input v-model="entry.half_day" type="checkbox" style="width:auto; cursor:pointer" />
                </td>
                <td><input v-model="entry.description" type="text" placeholder="Optional reason" /></td>
                <td>
                  <button
                    type="button"
                    class="remove-row-btn"
                    :disabled="leaveEntries.length === 1"
                    @click="removeEntry(entry.id)"
                    title="Remove row"
                  >✕</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="apply-actions">
          <button type="button" class="btn btn-ghost" @click="addEntry">+ Add Another Period</button>
          <div class="apply-summary">
            <span class="text-muted" style="font-size:13px">
              {{ leaveEntries.length }} period(s) · 
              {{ leaveEntries.reduce((s, e) => s + (e.from_date && e.to_date ? daysBetween(e.from_date, e.to_date) : 0), 0) }} total days
            </span>
            <button type="button" class="btn btn-primary" :disabled="submitting" @click="submitLeaves">
              {{ submitting ? "Submitting…" : "Submit Application" }}
            </button>
          </div>
        </div>

        <p v-if="submitError" class="error" style="margin-top:12px">{{ submitError }}</p>
        <div v-if="submitSuccess.length" class="success-banner">
          <strong>✓ Leave application submitted!</strong>
          <ul style="margin-top:6px; padding-left:16px">
            <li v-for="s in submitSuccess" :key="s">{{ s }}</li>
          </ul>
        </div>
      </div>
    </template>

    <!-- ── MY REQUESTS TAB ────────────────────────────────────── -->
    <template v-if="tab === 'my-leaves'">
      <div class="card">
        <div class="card-header-row">
          <h3 class="section-title" style="margin:0">My Leave Requests</h3>
          <button class="btn btn-primary btn-sm" @click="switchTab('apply')">+ New Application</button>
        </div>
        <div class="table-wrap" style="margin-top:16px">
          <table>
            <thead>
              <tr>
                <th>Leave Type</th>
                <th>From</th>
                <th>To</th>
                <th style="text-align:center">Days</th>
                <th>Approver</th>
                <th>Status</th>
                <th>Notes</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in myRequests" :key="r.name">
                <td><strong>{{ r.leave_type }}</strong></td>
                <td>{{ formatDate(r.from_date) }}</td>
                <td>{{ formatDate(r.to_date) }}</td>
                <td style="text-align:center; font-weight:600">{{ r.total_leave_days }}</td>
                <td style="font-size:12px; color:var(--text-muted)">{{ r.leave_approver || '—' }}</td>
                <td>
                  <span :class="['badge', statusClass(r.status)]">{{ r.status }}</span>
                </td>
                <td style="font-size:12px; color:var(--text-muted)">{{ r.description || '—' }}</td>
              </tr>
              <tr v-if="!myRequests.length && !loading">
                <td colspan="7" class="empty">No leave requests found. <a style="color:var(--blue); cursor:pointer" @click="switchTab('apply')">Apply for leave →</a></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <!-- ── PENDING APPROVALS TAB ──────────────────────────────── -->
    <template v-if="tab === 'pending'">
      <div class="card">
        <h3 class="section-title">Pending Approvals</h3>
        <p class="text-muted" style="font-size:13px; margin-bottom:16px">
          Review and action leave requests from your team.
        </p>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Employee</th>
                <th>Leave Type</th>
                <th>From</th>
                <th>To</th>
                <th style="text-align:center">Days</th>
                <th>Notes</th>
                <th style="text-align:center">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in pending" :key="item.name">
                <td>
                  <div style="font-weight:600">{{ item.employee_name }}</div>
                  <div style="font-size:11px; color:var(--text-muted)">{{ item.employee }}</div>
                </td>
                <td>{{ item.leave_type }}</td>
                <td>{{ formatDate(item.from_date) }}</td>
                <td>{{ formatDate(item.to_date) }}</td>
                <td style="text-align:center; font-weight:600">{{ item.total_leave_days }}</td>
                <td style="font-size:12px; color:var(--text-muted)">{{ (item as any).description || '—' }}</td>
                <td style="text-align:center">
                  <div class="approval-btns">
                    <button class="btn-approve" @click="approveRequest(item.name)">✓ Approve</button>
                    <button class="btn-reject" @click="rejectRequest(item.name)">✕ Reject</button>
                  </div>
                </td>
              </tr>
              <tr v-if="!pending.length && !loading">
                <td colspan="7" class="empty">No pending approvals. All caught up! 🎉</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
/* Stats grid */
.leave-stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 14px;
  margin-bottom: 4px;
}
.leave-stat-card {
  background: #fff;
  border-radius: 12px;
  padding: 18px 20px;
  display: flex;
  align-items: center;
  gap: 14px;
  border: 1px solid var(--border);
  box-shadow: var(--shadow);
}
.stat-icon { font-size: 28px; line-height: 1; }
.stat-value { font-size: 26px; font-weight: 700; line-height: 1.1; }
.stat-label { font-size: 12px; color: var(--text-muted); margin-top: 3px; }
.leave-stat-card.blue .stat-value { color: var(--blue); }
.leave-stat-card.green .stat-value { color: #16a34a; }
.leave-stat-card.orange .stat-value { color: #ea580c; }
.leave-stat-card.yellow .stat-value { color: #d97706; }
.leave-stat-card.purple .stat-value { color: #7c3aed; }
.leave-stat-card.teal .stat-value { color: #0d9488; font-size: 14px; font-weight: 600; padding-top: 4px; }

/* Balance bar */
.balance-bar-bg { background: #f3f4f6; border-radius: 99px; height: 8px; width: 100%; overflow: hidden; }
.balance-bar-fill { background: var(--blue); height: 100%; border-radius: 99px; transition: width 0.3s; }

/* Card header row */
.card-header-row { display: flex; align-items: center; justify-content: space-between; }

/* Approval chain */
.approval-chain-row {
  display: flex;
  align-items: center;
  gap: 12px;
  background: #f8faff;
  border: 1px solid #dbeafe;
  border-radius: 10px;
  padding: 16px 20px;
  flex-wrap: wrap;
}
.approval-arrow { color: var(--text-muted); font-size: 18px; }
.approval-step { display: flex; align-items: flex-start; gap: 10px; flex: 1; min-width: 160px; }
.approval-step-num {
  width: 28px; height: 28px; border-radius: 50%;
  background: var(--blue); color: white;
  display: flex; align-items: center; justify-content: center;
  font-size: 13px; font-weight: 700; flex-shrink: 0;
}
.approval-step.done .approval-step-num { background: #16a34a; }
.approval-step-title { font-size: 13px; font-weight: 600; }
.approval-step-info input { width: 100%; max-width: 200px; font-size: 12px; padding: 5px 8px; }

/* Leave apply table */
.leave-apply-table td { padding: 8px 10px; }
.leave-apply-table td select,
.leave-apply-table td input[type="text"],
.leave-apply-table td input[type="date"] { padding: 7px 10px; font-size: 13px; }

.remove-row-btn {
  background: none; border: none; cursor: pointer;
  color: #ef4444; font-size: 16px; padding: 2px 6px;
  border-radius: 4px; line-height: 1;
}
.remove-row-btn:hover { background: #fee2e2; }
.remove-row-btn:disabled { color: #d1d5db; cursor: not-allowed; }

/* Apply footer */
.apply-actions {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 14px;
  flex-wrap: wrap;
  gap: 10px;
}
.apply-summary { display: flex; align-items: center; gap: 14px; }

/* Success banner */
.success-banner {
  background: #f0fdf4; border: 1px solid #86efac;
  border-radius: 8px; padding: 14px 16px; margin-top: 12px;
  color: #15803d; font-size: 14px;
}

/* Approval buttons */
.approval-btns { display: flex; gap: 6px; justify-content: center; }
.btn-approve {
  background: #dcfce7; color: #16a34a; border: 1px solid #86efac;
  border-radius: 6px; padding: 5px 12px; font-size: 12px; font-weight: 600; cursor: pointer;
  transition: background 0.15s;
}
.btn-approve:hover { background: #bbf7d0; }
.btn-reject {
  background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5;
  border-radius: 6px; padding: 5px 12px; font-size: 12px; font-weight: 600; cursor: pointer;
  transition: background 0.15s;
}
.btn-reject:hover { background: #fecaca; }
</style>
