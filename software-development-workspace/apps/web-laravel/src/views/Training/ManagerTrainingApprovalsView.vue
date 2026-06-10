<template>
  <div class="view">
    <div class="view-header">
      <div>
        <h2>Training Approvals</h2>
        <p class="view-subtitle">Review and approve mandatory training assignments for your team</p>
      </div>
    </div>

    <p v-if="isLoading" class="status">Loading…</p>

    <!-- Stats -->
    <div class="metrics-grid">
      <div class="metric-card">
        <div class="metric-label">Pending</div>
        <div class="metric-value" style="color: var(--color-warning)">{{ stats?.pending ?? '—' }}</div>
      </div>
      <div class="metric-card">
        <div class="metric-label">Approved</div>
        <div class="metric-value" style="color: var(--color-success)">{{ stats?.approved ?? '—' }}</div>
      </div>
      <div class="metric-card">
        <div class="metric-label">Rejected</div>
        <div class="metric-value" style="color: var(--color-error)">{{ stats?.rejected ?? '—' }}</div>
      </div>
      <div class="metric-card">
        <div class="metric-label">Withdrawn</div>
        <div class="metric-value">{{ stats?.withdrawn ?? '—' }}</div>
      </div>
    </div>

    <!-- Pending Assignments Table -->
    <div class="card" style="padding: 0; overflow: hidden;">
      <div style="padding: var(--space-5); border-bottom: 1px solid var(--color-border); display: flex; align-items: center; justify-content: space-between;">
        <div>
          <div class="card-title" style="margin-bottom: 2px;">Pending Approvals</div>
          <p style="font-size: var(--font-size-sm); color: var(--color-text-muted);">
            {{ pendingCount }} assignment{{ pendingCount !== 1 ? 's' : '' }} awaiting your review
          </p>
        </div>
      </div>

      <!-- Empty State -->
      <div v-if="!isLoading && pendingAssignments.length === 0" class="status" style="text-align: center; padding: var(--space-8);">
        <div style="font-size: 2rem; margin-bottom: var(--space-3);">✓</div>
        <strong>No pending assignments</strong>
        <p style="margin-top: 4px;">All training assignments have been reviewed</p>
      </div>

      <!-- Assignment Table -->
      <div v-if="pendingAssignments.length > 0" class="table-wrap" style="border: none; border-radius: 0; box-shadow: none;">
        <table>
          <thead>
            <tr>
              <th>Training</th>
              <th>Employee</th>
              <th>Assigned By</th>
              <th>Due Date</th>
              <th>Type</th>
              <th>Assigned</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="assignment in pendingAssignments" :key="assignment.id">
              <td>
                <div style="font-weight: var(--font-weight-semibold);">{{ assignment.training_item?.title }}</div>
                <div style="font-size: var(--font-size-sm); color: var(--color-text-muted);">{{ assignment.training_item?.type }}</div>
              </td>
              <td>
                <div style="font-weight: var(--font-weight-semibold);">{{ assignment.employee?.first_name }} {{ assignment.employee?.last_name }}</div>
                <div style="font-size: var(--font-size-sm); color: var(--color-text-muted);">{{ assignment.employee?.email }}</div>
              </td>
              <td style="color: var(--color-text-muted);">{{ assignment.assigned_by_user?.name }}</td>
              <td>
                <span v-if="assignment.due_date">{{ formatDate(assignment.due_date) }}</span>
                <span v-else class="text-muted">—</span>
              </td>
              <td>
                <span class="badge" :class="assignment.assignment_type === 'mandatory' ? 'badge-red' : 'badge-blue'">
                  {{ assignment.assignment_type }}
                </span>
              </td>
              <td style="color: var(--color-text-muted); font-size: var(--font-size-sm);">{{ formatDate(assignment.created_at) }}</td>
              <td>
                <div style="display: flex; gap: var(--space-2);">
                  <button class="btn btn-success btn-sm" @click="openApprovalModal(assignment)">Approve</button>
                  <button class="btn btn-danger btn-sm" @click="openRejectionModal(assignment)">Reject</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="pagination && pagination.last_page > 1" class="pagination">
        <button class="btn btn-secondary btn-sm" :disabled="pagination.current_page <= 1" @click="loadPage(pagination.current_page - 1)">← Prev</button>
        <span>Page {{ pagination.current_page }} of {{ pagination.last_page }}</span>
        <button class="btn btn-secondary btn-sm" :disabled="pagination.current_page >= pagination.last_page" @click="loadPage(pagination.current_page + 1)">Next →</button>
      </div>
    </div>

    <!-- Approval Action Modal -->
    <ApprovalActionModal
      v-if="selectedAssignment"
      :assignment="selectedAssignment"
      :action="modalAction"
      @close="closeModal"
      @submit="handleActionSubmit"
    />
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { api } from '@/lib/api'
import { currentOrgSlug } from '@/stores/auth'
import ApprovalActionModal from '@/components/Training/ApprovalActionModal.vue'
import type { TrainingAssignment } from '@/lib/types'

function org(): string | null { return currentOrgSlug.value; }

const pendingAssignments = ref<TrainingAssignment[]>([])
const selectedAssignment = ref<TrainingAssignment | null>(null)
const modalAction = ref<'approve' | 'reject'>('approve')
const stats = ref<any>(null)
const pagination = ref<any>(null)
const isLoading = ref(false)

const pendingCount = computed(() => stats.value?.pending || 0)

function formatDate(date: string): string {
  if (!date) return '—'
  return new Date(date).toLocaleDateString('en-US', { 
    year: 'numeric', 
    month: 'short', 
    day: 'numeric' 
  })
}

async function loadAssignments(page = 1) {
  if (!org()) { console.error('No organization selected'); return; }
  isLoading.value = true
  try {
    const response = await api<any>('GET', `/api/v1/training/assignments/my-pending?page=${page}`, {
      org: org()
    })
    pendingAssignments.value = response.data
    pagination.value = response.pagination
    
    // Also load stats
    const statsResponse = await api<any>('GET', '/api/v1/training/assignments/stats', {
      org: org()
    })
    stats.value = statsResponse.data
  } catch (error) {
    console.error('Failed to load assignments:', error)
  } finally {
    isLoading.value = false
  }
}

function loadPage(page: number) {
  loadAssignments(page)
}

function openApprovalModal(assignment: TrainingAssignment) {
  selectedAssignment.value = assignment
  modalAction.value = 'approve'
}

function openRejectionModal(assignment: TrainingAssignment) {
  selectedAssignment.value = assignment
  modalAction.value = 'reject'
}

function closeModal() {
  selectedAssignment.value = null
}

async function handleActionSubmit(data: { action: 'approve' | 'reject'; notes?: string; reason?: string }) {
  if (!selectedAssignment.value) return

  try {
    const endpoint = data.action === 'approve' 
      ? `/api/v1/training/assignments/${selectedAssignment.value.id}/approve`
      : `/api/v1/training/assignments/${selectedAssignment.value.id}/reject`

    const body = data.action === 'approve'
      ? { notes: data.notes }
      : { reason: data.reason }

    await api('PATCH', endpoint, {
      org: org(),
      body
    })

    closeModal()
    await loadAssignments(pagination.value?.current_page || 1)
  } catch (error) {
    console.error('Failed to process assignment:', error)
  }
}

onMounted(() => {
  loadAssignments()
})
</script>
