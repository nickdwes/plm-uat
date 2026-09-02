<script setup lang="ts">
import { ref, onMounted } from "vue";
import { useRouter, useRoute } from "vue-router";
import { api } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import type { EmployeeTrainingRecord } from "../../lib/types";
import ProgressLogModal from "../../components/Training/ProgressLogModal.vue";
import EvidenceUploadField from "../../components/Training/EvidenceUploadField.vue";

const router = useRouter();
const route = useRoute();

const record = ref<EmployeeTrainingRecord | null>(null);
const loading = ref(false);
const error = ref("");
const activeTab = ref<"progress" | "evidence" | "checkins">("progress");
const showProgressModal = ref(false);

function org() { return currentOrgSlug.value; }

async function load() {
  if (!org()) { error.value = "Select an organisation first."; return; }
  loading.value = true;
  error.value = "";
  try {
    const res = await api<{ data: EmployeeTrainingRecord }>(
      "GET",
      `/api/v1/training/records/${route.params.id}`,
      { org: org() }
    );
    record.value = res.data;
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to load training record.";
  } finally {
    loading.value = false;
  }
}

function onProgressUpdated(updated: EmployeeTrainingRecord) {
  record.value = updated;
  load(); // reload for full progress_updates list
}

function statusBadgeClass(status: string) {
  return {
    enrolled:    "badge-enrolled",
    in_progress: "badge-progress",
    completed:   "badge-completed",
    withdrawn:   "badge-withdrawn",
    overdue:     "badge-overdue",
  }[status] ?? "";
}

function statusLabel(status: string) {
  return status.replace("_", " ").replace(/\b\w/g, c => c.toUpperCase());
}

const progressColor = (p: number) =>
  p >= 100 ? "#22c55e" : p >= 66 ? "#3b82f6" : p >= 33 ? "#f59e0b" : "#ef4444";

function formatDate(d: string | null) {
  return d ? new Date(d).toLocaleDateString("en-KE", { dateStyle: "medium" }) : "—";
}

function formatDateTime(d: string) {
  return new Date(d).toLocaleDateString("en-KE", { dateStyle: "medium" }) +
    " " + new Date(d).toLocaleTimeString("en-KE", { timeStyle: "short" });
}

async function withdraw() {
  if (!record.value) return;
  if (!confirm("Withdraw from this training? Your progress will be preserved but you will no longer be enrolled.")) return;
  try {
    await api("PATCH", `/api/v1/training/records/${record.value.id}/withdraw`, { org: org() });
    load();
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to withdraw.";
  }
}

onMounted(() => load());
</script>

<template>
  <div class="view">
    <!-- Header -->
    <div class="view-header">
      <button class="back-btn" @click="router.back()">← Back to My Training</button>
      <div v-if="record" class="header-actions">
        <button
          v-if="record.status !== 'completed' && record.status !== 'withdrawn'"
          class="btn btn-primary"
          @click="showProgressModal = true"
        >
          📈 Log Progress
        </button>
        <button
          v-if="record.status !== 'withdrawn' && record.status !== 'completed'"
          class="btn btn-danger"
          @click="withdraw"
        >
          Withdraw
        </button>
      </div>
    </div>

    <div v-if="error" class="alert alert-danger">{{ error }}</div>

    <div v-if="loading" class="loading">Loading record…</div>

    <div v-else-if="record" class="record-container">

      <!-- Summary card -->
      <div class="summary-card">
        <div class="summary-left">
          <h2 class="item-title">{{ record.training_item?.title }}</h2>
          <div class="summary-meta">
            <span :class="['badge', statusBadgeClass(record.status ?? '')]">
              {{ statusLabel(record.status ?? '') }}
            </span>
            <span class="assignment-type">
              {{ record.assignment_type === 'mandatory' ? '🔴 Mandatory' : '🟢 Self-Assigned' }}
            </span>
          </div>
          <div class="summary-info">
            <span v-if="record.started_at">Started: {{ formatDate(record.started_at) }}</span>
            <span v-if="record.due_date" :class="{ overdue: record.status !== 'completed' && record.due_date < new Date().toISOString() }">
              Due: {{ formatDate(record.due_date) }}
            </span>
            <span v-if="record.completed_at">Completed: {{ formatDate(record.completed_at) }}</span>
          </div>
          <div v-if="record.score != null || record.certificate_number" class="summary-info">
            <span v-if="record.score != null">Score: {{ record.score }}%</span>
            <span v-if="record.certificate_number">Certificate #: {{ record.certificate_number }}</span>
            <span v-if="record.certificate_expiry_date" :class="{ overdue: record.certificate_expiry_date < new Date().toISOString() }">
              Expires: {{ formatDate(record.certificate_expiry_date) }}
            </span>
          </div>
        </div>

        <!-- Progress circle -->
        <div class="progress-circle-wrap">
          <svg class="progress-ring" viewBox="0 0 80 80">
            <circle cx="40" cy="40" r="34" fill="none" stroke="#f3f4f6" stroke-width="8"/>
            <circle
              cx="40" cy="40" r="34" fill="none"
              :stroke="progressColor(record.progress_percent ?? 0)"
              stroke-width="8"
              stroke-linecap="round"
              stroke-dasharray="213.6"
              :stroke-dashoffset="213.6 - (213.6 * (record.progress_percent ?? 0) / 100)"
              transform="rotate(-90 40 40)"
            />
          </svg>
          <div class="circle-label" :style="{ color: progressColor(record.progress_percent ?? 0) }">
            {{ record.progress_percent ?? 0 }}%
          </div>
        </div>
      </div>

      <!-- Competencies (if linked) -->
      <div v-if="record.training_item?.competencies?.length" class="competencies-row">
        <span class="comp-label">Competencies:</span>
        <span v-for="c in record.training_item.competencies" :key="c.id" class="comp-chip">
          {{ c.name }}
        </span>
      </div>

      <!-- Tabs -->
      <div class="tabs">
        <button :class="['tab', { active: activeTab === 'progress' }]" @click="activeTab = 'progress'">
          Progress Log ({{ record.progress_updates?.length ?? 0 }})
        </button>
        <button :class="['tab', { active: activeTab === 'evidence' }]" @click="activeTab = 'evidence'">
          Evidence Files ({{ record.evidence_files?.length ?? 0 }})
        </button>
        <button :class="['tab', { active: activeTab === 'checkins' }]" @click="activeTab = 'checkins'">
          Daily Check-ins ({{ record.daily_check_ins?.length ?? 0 }})
        </button>
      </div>

      <!-- Progress Log tab -->
      <div v-if="activeTab === 'progress'" class="tab-content">
        <div v-if="record.progress_updates && record.progress_updates.length > 0" class="timeline">
          <div v-for="update in [...(record.progress_updates ?? [])].reverse()" :key="update.id" class="timeline-item">
            <div class="timeline-dot" :style="{ background: progressColor(update.progress_percent) }"></div>
            <div class="timeline-body">
              <div class="timeline-header">
                <strong>{{ update.progress_percent }}% progress</strong>
                <span class="timeline-date">{{ formatDateTime(update.recorded_at) }}</span>
              </div>
              <p v-if="update.notes" class="timeline-notes">{{ update.notes }}</p>
            </div>
          </div>
        </div>
        <div v-else class="empty-state">
          <p>No progress logged yet.</p>
          <button
            v-if="record.status !== 'completed' && record.status !== 'withdrawn'"
            class="btn btn-primary"
            @click="showProgressModal = true"
          >
            Log First Progress
          </button>
        </div>
      </div>

      <!-- Evidence Files tab -->
      <div v-if="activeTab === 'evidence'" class="tab-content">
        <template v-if="record.status !== 'withdrawn'">
          <p class="evidence-group-label">Certificate</p>
          <EvidenceUploadField
            :record-id="record.id"
            :existing-files="record.evidence_files ?? []"
            category="certificate"
            @uploaded="load"
          />
          <p class="evidence-group-label">Transcript</p>
          <EvidenceUploadField
            :record-id="record.id"
            :existing-files="record.evidence_files ?? []"
            category="transcript"
            @uploaded="load"
          />
        </template>
        <div v-else class="empty-state"><p>Cannot upload evidence for a withdrawn enrollment.</p></div>
      </div>

      <!-- Daily Check-ins tab -->
      <div v-if="activeTab === 'checkins'" class="tab-content">
        <div v-if="record.daily_check_ins && record.daily_check_ins.length > 0" class="checkin-list">
          <div v-for="ci in [...(record.daily_check_ins ?? [])].reverse()" :key="ci.id" class="checkin-item">
            <div class="checkin-date">{{ formatDate(ci.check_in_date) }}</div>
            <div class="checkin-body">
              <span v-if="ci.hours_spent != null" class="hours-badge">⏱ {{ ci.hours_spent }}h</span>
              <span v-if="ci.notes" class="checkin-notes">{{ ci.notes }}</span>
            </div>
          </div>
        </div>
        <div v-else class="empty-state"><p>No daily check-ins recorded yet.</p></div>
      </div>

    </div>

    <!-- Progress Log Modal -->
    <ProgressLogModal
      v-if="showProgressModal && record"
      :record="record"
      @close="showProgressModal = false"
      @updated="onProgressUpdated"
    />
  </div>
</template>

<style scoped>
.view { padding: 24px; }

.view-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 24px;
}

.back-btn { background: none; border: none; font-size: 14px; color: #666; cursor: pointer; }

.header-actions { display: flex; gap: 8px; }

.btn {
  padding: 8px 16px;
  border-radius: 6px;
  border: 1px solid #ddd;
  background: white;
  font-size: 13px;
  cursor: pointer;
  font-weight: 500;
}

.btn-primary { background: #3366cc; color: white; border-color: #3366cc; }
.btn-primary:hover { background: #2255bb; }
.btn-danger { background: #fff3f3; color: #c0392b; border-color: #f5c6c6; }
.btn-danger:hover { background: #ffe0e0; }

.alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; }
.alert-danger { background: #fee; color: #c33; border: 1px solid #f99; }

.loading { color: #888; padding: 24px; }

.summary-card {
  background: white;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  padding: 24px;
  display: flex;
  align-items: flex-start;
  gap: 24px;
  margin-bottom: 16px;
  box-shadow: 0 1px 4px rgba(0,0,0,0.04);
}

.summary-left { flex: 1; }

.item-title { font-size: 22px; font-weight: 600; margin: 0 0 10px; }

.summary-meta { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }

.badge {
  padding: 3px 10px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 500;
}

.badge-enrolled    { background: #f3f4f6; color: #374151; }
.badge-progress    { background: #dbeafe; color: #1d4ed8; }
.badge-completed   { background: #dcfce7; color: #15803d; }
.badge-withdrawn   { background: #fee2e2; color: #b91c1c; }
.badge-overdue     { background: #fef3c7; color: #92400e; }

.assignment-type { font-size: 12px; color: #555; }

.summary-info { display: flex; gap: 16px; font-size: 13px; color: #666; flex-wrap: wrap; }

.overdue { color: #c0392b; font-weight: 500; }

/* Progress ring */
.progress-circle-wrap {
  position: relative;
  width: 80px;
  height: 80px;
  flex-shrink: 0;
}

.progress-ring { width: 80px; height: 80px; }

.circle-label {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  font-weight: 700;
}

.competencies-row {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 16px;
  padding: 10px 16px;
  background: #f9fafb;
  border-radius: 8px;
  border: 1px solid #e5e7eb;
}

.comp-label { font-size: 12px; color: #666; font-weight: 500; }

.evidence-group-label {
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #667085;
  margin: 20px 0 8px;
}
.evidence-group-label:first-child { margin-top: 0; }

.comp-chip {
  background: #e0e7ff;
  color: #3730a3;
  border-radius: 20px;
  padding: 2px 10px;
  font-size: 12px;
}

/* Tabs */
.tabs {
  display: flex;
  border-bottom: 2px solid #e5e7eb;
  margin-bottom: 20px;
  gap: 0;
}

.tab {
  padding: 10px 20px;
  border: none;
  background: none;
  font-size: 14px;
  color: #666;
  cursor: pointer;
  border-bottom: 2px solid transparent;
  margin-bottom: -2px;
  transition: color 0.15s;
}

.tab.active { color: #3366cc; border-bottom-color: #3366cc; font-weight: 500; }
.tab:hover:not(.active) { color: #333; }

/* Timeline */
.timeline { display: flex; flex-direction: column; gap: 0; }

.timeline-item {
  display: flex;
  gap: 16px;
  padding-bottom: 20px;
  position: relative;
}

.timeline-item::before {
  content: "";
  position: absolute;
  left: 7px;
  top: 20px;
  bottom: 0;
  width: 2px;
  background: #e5e7eb;
}

.timeline-item:last-child::before { display: none; }

.timeline-dot {
  width: 16px;
  height: 16px;
  border-radius: 50%;
  flex-shrink: 0;
  margin-top: 3px;
  border: 2px solid white;
  box-shadow: 0 0 0 2px currentColor;
}

.timeline-body { flex: 1; }

.timeline-header {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  margin-bottom: 4px;
}

.timeline-header strong { font-size: 14px; color: #111; }

.timeline-date { font-size: 12px; color: #aaa; }

.timeline-notes { font-size: 13px; color: #555; margin: 0; }

/* Check-ins */
.checkin-list { display: flex; flex-direction: column; gap: 10px; }

.checkin-item {
  display: flex;
  gap: 16px;
  align-items: baseline;
  padding: 10px 14px;
  background: #fafafa;
  border-radius: 8px;
  border: 1px solid #f0f0f0;
}

.checkin-date { font-size: 13px; font-weight: 500; color: #374151; min-width: 120px; }

.checkin-body { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }

.hours-badge {
  background: #f0f4ff;
  color: #3366cc;
  border-radius: 20px;
  padding: 2px 10px;
  font-size: 12px;
}

.checkin-notes { font-size: 13px; color: #555; }

.empty-state {
  text-align: center;
  padding: 32px;
  color: #999;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
}
</style>
