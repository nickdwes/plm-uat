<script setup lang="ts">
/**
 * The honest 403.
 *
 * Reachable only by deep link once the nav is permission-gated — anybody who
 * lands here followed a bookmark or a shared URL into a part of the
 * application their role does not open. Saying so beats silently bouncing to
 * the dashboard, which reads as a bug and is impossible to support over the
 * phone ("it just goes back").
 */
import { useRouter } from "vue-router";
import { authStore } from "../stores/auth";

const router = useRouter();
</script>

<template>
  <div class="fb">
    <div class="fb-card">
      <p class="fb-code">403</p>
      <h1 class="fb-title">This area is not part of your role</h1>
      <p class="fb-body">
        The link you followed points at a part of the application your current
        role does not open. If you think it should, ask whoever manages roles —
        they can grant it from Role Assignments.
      </p>
      <p v-if="authStore.organizations.length > 1" class="fb-hint">
        You belong to more than one organisation — your rights differ between
        them, so switching (top left) may open this.
      </p>
      <div class="fb-actions">
        <button class="fb-btn" @click="router.back()">Go back</button>
        <button class="fb-btn primary" @click="router.push({ name: 'dashboard' })">Dashboard</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
@reference "../theme.css";

.fb { @apply flex items-center justify-center; min-height: 60vh; padding: var(--space-6); }
.fb-card { @apply flex flex-col items-center; max-width: 34rem; text-align: center; gap: var(--space-3); }
.fb-code { font-size: var(--display-sm-size); font-weight: 700; color: var(--color-text-light); margin: 0; }
.fb-title { font-size: var(--text-xl-size); font-weight: 600; color: var(--color-text); margin: 0; }
.fb-body, .fb-hint { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: 0; line-height: 1.6; }
.fb-actions { @apply flex; gap: var(--space-2); margin-top: var(--space-3); }
.fb-btn {
  @apply cursor-pointer; padding: 8px var(--space-4); border-radius: var(--radius-md);
  border: 1px solid var(--color-border-strong); background: var(--color-surface);
  font-family: inherit; font-size: var(--text-sm-size); color: var(--color-text);
}
.fb-btn.primary { @apply border-none; background: var(--color-brand-base); color: var(--color-on-brand); }
</style>
