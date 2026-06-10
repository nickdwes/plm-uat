<template>
  <div style="position: fixed; inset: 0; z-index: 50; display: flex; align-items: center; justify-content: center; background: rgba(0,0,0,0.4);">
    <div style="background: var(--color-surface); border-radius: var(--radius-xl); box-shadow: var(--shadow-lg); max-width: 480px; width: 100%; margin: var(--space-4);">
      <!-- Header -->
      <div style="padding: var(--space-5) var(--space-6); border-bottom: 1px solid var(--color-border);">
        <h3 style="font-size: var(--font-size-lg); font-weight: var(--font-weight-bold);">
          {{ action === 'approve' ? 'Approve Assignment' : 'Reject Assignment' }}
        </h3>
        <p style="font-size: var(--font-size-sm); color: var(--color-text-muted); margin-top: 4px;">
          {{ assignment.employee?.first_name }} {{ assignment.employee?.last_name }} — {{ assignment.training_item?.title }}
        </p>
      </div>

      <!-- Body -->
      <div style="padding: var(--space-5) var(--space-6);">
        <!-- Approve Form -->
        <div v-if="action === 'approve'">
          <p style="font-size: var(--font-size-sm); color: var(--color-text-muted); margin-bottom: var(--space-4);">
            Approving this assignment will immediately enroll {{ assignment.employee?.first_name }} in the training.
          </p>
          <div class="form-group">
            <label>Approval Notes <span style="color: var(--color-text-light);">(optional)</span></label>
            <textarea v-model="approvalNotes" placeholder="Add any notes about this approval…" rows="4" />
          </div>
        </div>

        <!-- Reject Form -->
        <div v-else>
          <p style="font-size: var(--font-size-sm); color: var(--color-text-muted); margin-bottom: var(--space-4);">
            The employee will be notified about the rejection and the reason provided.
          </p>
          <div class="form-group">
            <label>Rejection Reason <span style="color: var(--color-error);">*</span></label>
            <textarea v-model="rejectionReason" placeholder="Please explain why this assignment is being rejected…" rows="4" :style="rejectionError ? 'border-color: var(--color-error);' : ''" />
            <span v-if="rejectionError" class="field-hint" style="color: var(--color-error);">{{ rejectionError }}</span>
          </div>
        </div>
      </div>

      <!-- Footer -->
      <div style="padding: var(--space-4) var(--space-6); border-top: 1px solid var(--color-border); display: flex; gap: var(--space-3); justify-content: flex-end;">
        <button class="btn btn-secondary" @click="$emit('close')">Cancel</button>
        <button
          :class="action === 'approve' ? 'btn btn-success' : 'btn btn-danger'"
          :disabled="isSubmitting || (action === 'reject' && !rejectionReason.trim())"
          @click="handleSubmit"
        >
          {{ isSubmitting ? 'Processing…' : action === 'approve' ? 'Approve' : 'Reject' }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import type { TrainingAssignment } from '@/lib/types'

interface Props {
  assignment: TrainingAssignment
  action: 'approve' | 'reject'
}

interface Emits {
  (e: 'close'): void
  (e: 'submit', data: { action: 'approve' | 'reject'; notes?: string; reason?: string }): void
}

const props = defineProps<Props>()
const emit = defineEmits<Emits>()

const approvalNotes = ref('')
const rejectionReason = ref('')
const rejectionError = ref('')
const isSubmitting = ref(false)

async function handleSubmit() {
  if (props.action === 'reject' && !rejectionReason.value.trim()) {
    rejectionError.value = 'Rejection reason is required'
    return
  }

  rejectionError.value = ''
  isSubmitting.value = true

  try {
    emit('submit', {
      action: props.action,
      notes: props.action === 'approve' ? approvalNotes.value : undefined,
      reason: props.action === 'reject' ? rejectionReason.value : undefined
    })
  } finally {
    isSubmitting.value = false
  }
}
</script>
