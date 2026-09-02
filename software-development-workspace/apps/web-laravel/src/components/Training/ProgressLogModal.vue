<script setup lang="ts">
import { ref, watch } from "vue";
import { api } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import type { EmployeeTrainingRecord } from "../../lib/types";

const props = defineProps<{
  record: EmployeeTrainingRecord;
}>();

const emit = defineEmits<{
  (e: "close"): void;
  (e: "updated", record: EmployeeTrainingRecord): void;
}>();

const progress = ref(props.record.progress_percent ?? 0);
const notes = ref("");
const saving = ref(false);
const error = ref("");

const issuesCertificate = props.record.training_item?.certificate_issued ?? false;
const score = ref<number | null>(props.record.score ?? null);
const certificateNumber = ref(props.record.certificate_number ?? "");
const certificateExpiryDate = ref(props.record.certificate_expiry_date ?? "");

// Ensure slider never goes below current value
const minProgress = props.record.progress_percent ?? 0;

watch(() => props.record.progress_percent, (val) => {
  if ((val ?? 0) > progress.value) progress.value = val ?? 0;
});

function org() { return currentOrgSlug.value; }

const progressColor = (p: number) =>
  p >= 100 ? "#22c55e" : p >= 66 ? "#3b82f6" : p >= 33 ? "#f59e0b" : "#ef4444";

async function submit() {
  if (progress.value <= minProgress) {
    error.value = `Progress must be above current value (${minProgress}%).`;
    return;
  }
  saving.value = true;
  error.value = "";
  try {
    const res = await api<{ data: EmployeeTrainingRecord }>(
      "PATCH",
      `/api/v1/training/records/${props.record.id}/progress`,
      { org: org(), body: {
        progress_percent: progress.value,
        notes: notes.value || null,
        score: score.value,
        certificate_number: certificateNumber.value || null,
        certificate_expiry_date: certificateExpiryDate.value || null,
      } }
    );
    emit("updated", res.data);
    emit("close");
  } catch (e: unknown) {
    const err = e as { message?: string };
    error.value = err.message ?? "Failed to log progress.";
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <div class="modal-overlay" @click.self="emit('close')">
    <div class="modal-panel">
      <div class="modal-header">
        <h3>Log Progress</h3>
        <button class="close-btn" @click="emit('close')">✕</button>
      </div>

      <p class="course-name">{{ record.training_item?.title }}</p>

      <div v-if="error" class="alert alert-danger">{{ error }}</div>

      <!-- Slider -->
      <div class="slider-section">
        <div class="progress-display" :style="{ color: progressColor(progress) }">
          {{ progress }}%
        </div>
        <input
          v-model.number="progress"
          type="range"
          :min="minProgress"
          max="100"
          step="1"
          class="progress-slider"
          :style="{ '--fill': progressColor(progress) }"
        />
        <div class="slider-labels">
          <span>{{ minProgress }}% (current)</span>
          <span>100%</span>
        </div>
      </div>

      <!-- Progress bar preview -->
      <div class="preview-bar">
        <div class="preview-fill" :style="{ width: progress + '%', background: progressColor(progress) }"></div>
      </div>

      <!-- Status preview -->
      <div class="status-preview">
        Status after save:
        <strong>
          {{ progress >= 100 ? "✅ Completed" : progress > 0 ? "🔵 In Progress" : "⚪ Enrolled" }}
        </strong>
      </div>

      <!-- Certification -->
      <div v-if="issuesCertificate" class="cert-section">
        <p class="cert-heading">Certification earned</p>
        <label class="notes-label">Score Achieved (%)</label>
        <input v-model.number="score" type="number" min="0" max="100" step="0.01" class="notes-input" />
        <label class="notes-label">Certificate Number</label>
        <input v-model="certificateNumber" type="text" class="notes-input" placeholder="As printed on the certificate" />
        <label class="notes-label">Certificate Expiry Date</label>
        <input v-model="certificateExpiryDate" type="date" class="notes-input" />
      </div>

      <!-- Notes -->
      <div class="notes-section">
        <label class="notes-label">Notes (optional)</label>
        <textarea
          v-model="notes"
          class="notes-input"
          rows="3"
          placeholder="What did you cover? Any blockers?"
          maxlength="1000"
        ></textarea>
        <div class="char-count">{{ notes.length }}/1000</div>
      </div>

      <div class="modal-footer">
        <button class="btn" @click="emit('close')">Cancel</button>
        <button class="btn btn-primary" :disabled="saving || progress <= minProgress" @click="submit">
          {{ saving ? "Saving…" : "Save Progress" }}
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.45);
  z-index: 500;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
}

.modal-panel {
  background: #fff;
  border-radius: 12px;
  width: 100%;
  max-width: 480px;
  padding: 28px;
  box-shadow: 0 20px 60px rgba(0,0,0,0.2);
}

.modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
}

.modal-header h3 { font-size: 18px; font-weight: 600; margin: 0; }

.close-btn {
  background: none; border: none; font-size: 18px; cursor: pointer; color: #888;
}

.close-btn:hover { color: #333; }

.course-name {
  font-size: 13px;
  color: #666;
  margin: 0 0 20px;
  font-style: italic;
}

.alert {
  padding: 10px 14px;
  border-radius: 6px;
  margin-bottom: 14px;
  font-size: 13px;
}

.alert-danger { background: #fee; color: #c33; border: 1px solid #f99; }

.slider-section { margin-bottom: 12px; }

.progress-display {
  font-size: 48px;
  font-weight: 700;
  text-align: center;
  margin-bottom: 8px;
  line-height: 1;
  transition: color 0.2s;
}

.progress-slider {
  width: 100%;
  height: 6px;
  -webkit-appearance: none;
  appearance: none;
  border-radius: 3px;
  background: #e5e7eb;
  outline: none;
  cursor: pointer;
}

.progress-slider::-webkit-slider-thumb {
  -webkit-appearance: none;
  width: 20px;
  height: 20px;
  border-radius: 50%;
  background: var(--fill, #3b82f6);
  cursor: pointer;
  border: 2px solid white;
  box-shadow: 0 1px 4px rgba(0,0,0,0.2);
}

.slider-labels {
  display: flex;
  justify-content: space-between;
  font-size: 11px;
  color: #999;
  margin-top: 4px;
}

.preview-bar {
  height: 8px;
  background: #f3f4f6;
  border-radius: 4px;
  overflow: hidden;
  margin-bottom: 10px;
}

.preview-fill {
  height: 100%;
  border-radius: 4px;
  transition: width 0.15s, background 0.2s;
}

.status-preview {
  font-size: 13px;
  color: #555;
  margin-bottom: 16px;
  text-align: center;
}

.cert-section {
  margin-bottom: 16px;
  padding: 12px 14px;
  background: #f8fafc;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
}

.cert-heading {
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #667085;
  margin: 0 0 10px;
}

.cert-section .notes-label { margin-top: 8px; }
.cert-section .notes-label:first-of-type { margin-top: 0; }

.notes-section { margin-bottom: 20px; }

.notes-label {
  display: block;
  font-size: 13px;
  font-weight: 500;
  color: #444;
  margin-bottom: 5px;
}

.notes-input {
  width: 100%;
  border: 1px solid #ddd;
  border-radius: 6px;
  padding: 8px 10px;
  font-size: 14px;
  resize: vertical;
  box-sizing: border-box;
  outline: none;
}

.notes-input:focus { border-color: #3366cc; }

.char-count { font-size: 11px; color: #aaa; text-align: right; margin-top: 2px; }

.modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
}

.btn {
  padding: 8px 16px;
  border-radius: 6px;
  border: 1px solid #ddd;
  background: #fff;
  font-size: 14px;
  cursor: pointer;
}

.btn-primary {
  background: #3366cc;
  color: white;
  border-color: #3366cc;
  font-weight: 500;
}

.btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-primary:not(:disabled):hover { background: #2255bb; }
</style>
