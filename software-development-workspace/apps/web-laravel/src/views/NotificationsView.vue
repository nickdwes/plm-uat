<template>
  <div class="view view--stacked">
    <div class="view-header">
      <div>
        <h2>Notifications</h2>
        <p class="view-subtitle">{{ unreadCount }} unread notification{{ unreadCount !== 1 ? 's' : '' }}</p>
      </div>
      <button v-if="unreadCount > 0" class="btn btn-secondary btn-sm" @click="markAllAsRead">
        Mark All as Read
      </button>
    </div>

    <p v-if="isLoading" class="status">Loading…</p>

    <!-- Notifications List -->
    <div class="card" style="padding: 0; overflow: hidden;">

      <!-- Empty State -->
      <div v-if="!isLoading && notifications.length === 0" class="status" style="text-align: center; padding: var(--space-8);">
        <div style="font-size: 2rem; margin-bottom: var(--space-3);">🔔</div>
        <strong>No notifications yet</strong>
        <p style="margin-top: 4px; color: var(--color-text-muted);">You're all caught up!</p>
      </div>

      <!-- Notification Items -->
      <div v-else>
        <div
          v-for="notification in notifications"
          :key="notification.id"
          style="padding: var(--space-5); border-bottom: 1px solid var(--color-border); display: flex; align-items: flex-start; gap: var(--space-4); cursor: pointer; transition: background var(--transition-fast);"
          :style="!notification.read_at ? 'border-left: 3px solid var(--color-brand-base); background: var(--color-brand-light);' : ''"
          @click="toggleRead(notification)"
        >
          <div style="flex: 1;">
            <div style="display: flex; align-items: center; gap: var(--space-2); margin-bottom: 4px;">
              <span style="font-weight: var(--font-weight-semibold);">{{ notification.title }}</span>
              <span v-if="!notification.read_at" style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: var(--color-brand-base); flex-shrink: 0;"></span>
            </div>
            <p style="color: var(--color-text-muted); font-size: var(--font-size-sm); margin-bottom: var(--space-2);">{{ notification.message }}</p>
            <div style="display: flex; align-items: center; gap: var(--space-3);">
              <span style="font-size: var(--font-size-xs); color: var(--color-text-light);">{{ formatDate(notification.created_at) }}</span>
              <span class="badge badge-gray">{{ notification.type }}</span>
            </div>
          </div>
          <button
            class="btn btn-ghost btn-sm"
            style="flex-shrink: 0; color: var(--color-text-muted);"
            title="Delete notification"
            @click.stop="deleteNotification(notification.id)"
          >✕</button>
        </div>
      </div>

      <!-- Pagination -->
      <div v-if="pagination.last_page > 1" class="pagination">
        <button class="btn btn-secondary btn-sm" :disabled="pagination.current_page <= 1" @click="loadPage(pagination.current_page - 1)">← Prev</button>
        <span>Page {{ pagination.current_page }} of {{ pagination.last_page }}</span>
        <button class="btn btn-secondary btn-sm" :disabled="pagination.current_page >= pagination.last_page" @click="loadPage(pagination.current_page + 1)">Next →</button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { api } from '@/lib/api';
import { currentOrgSlug } from '@/stores/auth';
import type { Notification } from '@/lib/types';

function org(): string | null { return currentOrgSlug.value; }

const notifications = ref<Notification[]>([]);
const unreadCount = ref(0);
const pagination = ref({
  total: 0,
  per_page: 20,
  current_page: 1,
  last_page: 1,
});

const isLoading = ref(false);

function formatDate(date: string): string {
  return new Date(date).toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

async function loadNotifications(page = 1) {
  if (!org()) { console.error('No organization selected'); return; }
  isLoading.value = true;
  try {
    const response = await api<any>('GET', '/api/v1/notifications', {
      org: org(),
      params: { page, per_page: 20 }
    });
    
    notifications.value = response.data;
    pagination.value = response.pagination || {
      total: response.data.length,
      per_page: 20,
      current_page: page,
      last_page: 1,
    };

    // Get unread count
    const countResponse = await api<any>('GET', '/api/v1/notifications/count', {
      org: org(),
    });
    unreadCount.value = countResponse.unread_count || 0;
  } catch (error) {
    console.error('Failed to load notifications:', error);
  } finally {
    isLoading.value = false;
  }
}

async function toggleRead(notification: Notification) {
  try {
    if (notification.read_at) {
      // Cannot unread, just do nothing
      return;
    }
    await api('PATCH', `/api/v1/notifications/${notification.id}/read`, {
      org: org(),
    });
    await loadNotifications(pagination.value.current_page);
  } catch (error) {
    console.error('Failed to mark notification as read:', error);
  }
}

async function markAllAsRead() {
  try {
    await api('PATCH', '/api/v1/notifications/mark-all-read', {
      org: org(),
    });
    await loadNotifications(pagination.value.current_page);
  } catch (error) {
    console.error('Failed to mark all as read:', error);
  }
}

async function deleteNotification(notificationId: string) {
  try {
    await api('DELETE', `/api/v1/notifications/${notificationId}`, {
      org: org(),
    });
    await loadNotifications(pagination.value.current_page);
  } catch (error) {
    console.error('Failed to delete notification:', error);
  }
}

function loadPage(page: number) {
  if (page >= 1 && page <= pagination.value.last_page) {
    loadNotifications(page);
  }
}

onMounted(() => {
  loadNotifications();
});
</script>
