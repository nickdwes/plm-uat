<script setup lang="ts">
/** Visual progress bar showing wizard step completion. */
defineProps<{
  currentStep: number; // 1-based
  totalSteps: number;
  stepLabels: Record<string, string>;
}>();

const STEP_KEYS = ["personal", "employment", "statutory", "qualifications", "next_of_kin", "documents", "review"];
</script>

<template>
  <div class="ob-progress">
    <div class="ob-progress-track">
      <template v-for="(key, idx) in STEP_KEYS" :key="key">
        <div
          :class="[
            'ob-progress-step',
            currentStep > idx + 1  ? 'done'
            : currentStep === idx + 1 ? 'active'
            : 'pending',
          ]"
        >
          <div class="ob-step-circle">
            <svg v-if="currentStep > idx + 1" viewBox="0 0 16 16" fill="currentColor">
              <path d="M13.3 4.3l-6 6-3-3L3 8.6l4.3 4.3 7.3-7.3z"/>
            </svg>
            <span v-else>{{ idx + 1 }}</span>
          </div>
          <span class="ob-step-label">{{ stepLabels[key] ?? key }}</span>
        </div>
        <div v-if="idx < STEP_KEYS.length - 1" :class="['ob-step-connector', currentStep > idx + 1 ? 'done' : '']" />
      </template>
    </div>
    <div class="ob-progress-bar-outer">
      <div
        class="ob-progress-bar-inner"
        :style="{ width: `${Math.min(100, ((currentStep - 1) / (totalSteps - 1)) * 100)}%` }"
      />
    </div>
  </div>
</template>

<style scoped>
.ob-progress { margin-bottom: 2rem; }
.ob-progress-track {
  display: flex;
  align-items: flex-start;
  gap: 0;
  overflow-x: auto;
  padding-bottom: 0.5rem;
}
.ob-progress-step {
  display: flex;
  flex-direction: column;
  align-items: center;
  min-width: 80px;
  flex: 1;
  position: relative;
}
.ob-step-circle {
  width: 32px; height: 32px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 0.8rem; font-weight: 600;
  border: 2px solid var(--border);
  background: #fff;
  color: var(--text-muted);
  transition: background 0.2s, border-color 0.2s, color 0.2s;
  z-index: 1;
}
.ob-step-circle svg { width: 14px; height: 14px; }
.ob-progress-step.done .ob-step-circle { background: var(--primary); border-color: var(--primary); color: #fff; }
.ob-progress-step.active .ob-step-circle { border-color: var(--primary); color: var(--primary); background: #fff; }
.ob-step-label {
  font-size: 0.7rem;
  text-align: center;
  margin-top: 4px;
  color: var(--text-muted);
  max-width: 72px;
  line-height: 1.2;
}
.ob-progress-step.active .ob-step-label,
.ob-progress-step.done .ob-step-label { color: var(--primary); font-weight: 600; }
.ob-step-connector {
  flex: 1;
  height: 2px;
  background: var(--border);
  margin-top: 15px;
  transition: background 0.2s;
}
.ob-step-connector.done { background: var(--primary); }
.ob-progress-bar-outer {
  height: 4px;
  background: var(--border);
  border-radius: 2px;
  margin-top: 0.5rem;
}
.ob-progress-bar-inner {
  height: 100%;
  background: var(--primary);
  border-radius: 2px;
  transition: width 0.3s ease;
}
</style>
