<script setup lang="ts">
/**
 * Where toasts appear.
 *
 * Mounted once on the shell rather than per screen, so a notification raised
 * during navigation survives the route change that caused it — the old inline
 * banners were destroyed with the component that raised them, which is why
 * "Lead created" was never seen: the router had already moved on.
 *
 * Bottom-right, out of the way of the header actions and the sidebar. Polite
 * live region: a confirmation should be announced without interrupting
 * whatever a screen reader was in the middle of saying.
 */
import { toasts, dismissToast } from "../lib/toast";

const ICONS: Record<string, string> = {
  success: "✓",
  error: "!",
  info: "i",
};
</script>

<template>
  <div class="toast-host" role="status" aria-live="polite">
    <TransitionGroup name="toast">
      <div v-for="t in toasts" :key="t.id" :class="['toast', `toast-${t.tone}`]">
        <span class="toast-mark" aria-hidden="true">{{ ICONS[t.tone] }}</span>
        <div class="toast-body">
          <p class="toast-message">{{ t.message }}</p>
          <p v-if="t.detail" class="toast-detail">{{ t.detail }}</p>
        </div>
        <button class="toast-close" aria-label="Dismiss" @click="dismissToast(t.id)">×</button>
      </div>
    </TransitionGroup>
  </div>
</template>

<style scoped>
@reference "../theme.css";

.toast-host {
  @apply fixed flex flex-col items-end;
  right: var(--space-5);
  bottom: var(--space-5);
  gap: var(--space-2);
  z-index: 100;
  /* The strip itself must not swallow clicks on the page behind it. */
  pointer-events: none;
}

.toast {
  @apply flex items-start;
  pointer-events: auto;
  gap: var(--space-3);
  width: min(24rem, calc(100vw - var(--space-6)));
  padding: var(--space-3) var(--space-4);
  border-radius: var(--radius-md);
  border: 1px solid var(--color-border);
  background: var(--color-surface-raised);
  box-shadow: var(--shadow-lg);
}

.toast-mark {
  @apply flex shrink-0 items-center justify-center;
  width: 1.25rem; height: 1.25rem;
  border-radius: 999px;
  font-size: 0.7rem; font-weight: 700;
  margin-top: 1px;
}
.toast-success .toast-mark { background: var(--color-success-subtle); color: var(--color-success-text); }
.toast-error   .toast-mark { background: var(--color-danger-subtle);  color: var(--color-danger-text); }
.toast-info    .toast-mark { background: var(--color-brand-subtle);   color: var(--color-brand-on-surface); }

.toast-success { border-left: 3px solid var(--color-success-text); }
.toast-error   { border-left: 3px solid var(--color-danger-text); }
.toast-info    { border-left: 3px solid var(--color-brand-base); }

.toast-body { flex: 1; min-width: 0; }
.toast-message { font-size: var(--text-sm-size); color: var(--color-text); margin: 0; }
.toast-detail  { font-size: var(--text-xs-size); color: var(--color-text-muted); margin: var(--space-1) 0 0; }

.toast-close {
  @apply shrink-0 cursor-pointer border-none bg-transparent p-0;
  font-size: 1.1rem; line-height: 1; color: var(--color-text-light);
}
.toast-close:hover { color: var(--color-text); }

.toast-enter-active, .toast-leave-active { transition: opacity 180ms ease, transform 180ms ease; }
.toast-enter-from { opacity: 0; transform: translateY(6px); }
.toast-leave-to   { opacity: 0; transform: translateX(12px); }
.toast-move       { transition: transform 180ms ease; }

@media (prefers-reduced-motion: reduce) {
  .toast-enter-active, .toast-leave-active, .toast-move { transition: none; }
}
</style>
