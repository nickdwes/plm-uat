<script setup lang="ts">
import { authStore } from "../stores/auth";
</script>

<template>
  <header class="app-header">
    <span></span>
    <div class="header-right">
      <div class="header-user">
        <div class="header-avatar">{{ (authStore.username || 'U')[0].toUpperCase() }}</div>
        <div class="header-username">{{ authStore.username || 'User' }}</div>
      </div>
    </div>
  </header>
</template>

<style scoped>
.app-header {
  height: 56px;
  background: #fff;
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  justify-content: flex-end;
  padding: 0 28px;
  flex-shrink: 0;
}
.header-right { display: flex; align-items: center; gap: 12px; }
.header-user { display: flex; align-items: center; gap: 10px; }
.header-avatar {
  width: 34px; height: 34px;
  background: var(--blue);
  color: white;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-weight: 700; font-size: 13px;
}
.header-username { font-size: 13px; font-weight: 500; color: var(--text); }
</style>
