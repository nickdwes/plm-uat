<script setup lang="ts">
/**
 * The pop-up that asks "prove it is you" before something genuinely sensitive
 * is shown — a code emailed to the viewer's own address, entered here, good
 * for a short window afterward so it does not have to be repeated for every
 * record looked at in one sitting.
 */
import { ref, watch, nextTick } from "vue";
import { useStepUp } from "../composables/useStepUp";

const props = defineProps<{
  open: boolean;
  purpose: string;
  org: string | null;
  /** What this is protecting, said in the dialog rather than left implicit. */
  label?: string;
}>();

const emit = defineEmits<{
  "update:open": [boolean];
  verified: [];
}>();

const step = useStepUp(props.purpose);
const code = ref("");
const codeInput = ref<HTMLInputElement | null>(null);

function close(): void {
  emit("update:open", false);
}

async function send(): Promise<void> {
  code.value = "";
  await step.requestCode(props.org);
  await nextTick();
  codeInput.value?.focus();
}

async function submit(): Promise<void> {
  if (code.value.length !== 6) return;
  if (await step.verify(props.org, code.value)) {
    emit("verified");
    close();
  } else {
    // Wrong code — cleared so a retry is not a stale digit sitting there
    // looking like it should have worked.
    code.value = "";
  }
}

// A fresh dialog each time it opens, not whatever was left over from before.
watch(() => props.open, (isOpen) => {
  if (isOpen) {
    step.reset();
    code.value = "";
    void send();
  }
});
</script>

<template>
  <div v-if="open" class="su-scrim" @click.self="close">
    <div class="su-modal" role="dialog" aria-modal="true" aria-label="Verify it's you">
      <div class="su-head">
        <h3>Verify it's you</h3>
        <button class="su-close" aria-label="Close" @click="close">×</button>
      </div>

      <p class="su-lead">
        {{ label || "This needs a fresh code before it can be shown." }}
        A 6-digit code has been sent to your own email address.
      </p>

      <p v-if="step.error.value" class="su-error">{{ step.error.value }}</p>

      <form class="su-form" @submit.prevent="submit">
        <input
          ref="codeInput"
          v-model="code"
          type="text"
          inputmode="numeric"
          autocomplete="one-time-code"
          maxlength="6"
          placeholder="000000"
          class="su-code-input"
          :disabled="step.busy.value"
        />
        <button type="submit" class="btn btn-primary" :disabled="step.busy.value || code.length !== 6">
          {{ step.busy.value ? "Checking…" : "Verify" }}
        </button>
      </form>

      <button class="su-resend" type="button" :disabled="step.busy.value" @click="send">
        {{ step.busy.value ? "Sending…" : "Send a new code" }}
      </button>

      <p class="su-note">
        Codes expire after 10 minutes and only work once. Once verified, this stays unlocked for
        15 minutes so it does not have to be repeated for every record you look at.
      </p>
    </div>
  </div>
</template>

<style scoped>
@reference "../theme.css";

.su-scrim {
  @apply fixed inset-0 flex items-center justify-center z-50;
  background: rgb(0 0 0 / 0.35);
  padding: var(--space-4);
}

.su-modal {
  @apply flex flex-col;
  width: min(24rem, 100%);
  gap: var(--space-3);
  padding: var(--space-5);
  border-radius: var(--radius-xl);
  background: var(--color-surface-raised);
  box-shadow: var(--shadow-lg);
}

.su-head { @apply flex items-start justify-between; }
.su-head h3 { font-size: var(--text-base-size); font-weight: 600; color: var(--color-text); margin: 0; }
.su-close {
  @apply cursor-pointer border-none bg-transparent;
  font-size: var(--text-xl-size); line-height: 1; color: var(--color-text-muted);
  padding: 0 var(--space-2);
}
.su-close:hover { color: var(--color-text); }

.su-lead { font-size: var(--text-sm-size); color: var(--color-text-muted); margin: 0; line-height: 1.5; }

.su-error {
  font-size: var(--text-xs-size); color: var(--color-error); margin: 0;
  background: var(--color-error-light);
  border-left: 3px solid var(--color-error);
  border-radius: 0 var(--radius-sm) var(--radius-sm) 0;
  padding: var(--space-2) var(--space-3);
}

.su-form { @apply flex gap-2; }
.su-code-input {
  @apply flex-1;
  font-family: ui-monospace, monospace;
  font-size: var(--text-xl-size);
  letter-spacing: 0.35em;
  text-align: center;
  padding: 0.5rem var(--space-3);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-md);
  background: var(--color-surface);
  color: var(--color-text);
}
.su-code-input:focus {
  @apply outline-none;
  border-color: var(--color-brand-base);
  box-shadow: 0 0 0 3px var(--color-brand-subtle);
}

.su-resend {
  @apply cursor-pointer self-start border-none bg-transparent p-0 font-medium;
  font-size: var(--text-xs-size);
  color: var(--color-brand-base);
}
.su-resend:hover:not(:disabled) { @apply underline; }
.su-resend:disabled { @apply cursor-not-allowed opacity-60; }

.su-note { font-size: var(--text-xs-size); color: var(--color-text-light); margin: 0; line-height: 1.5; }
</style>
