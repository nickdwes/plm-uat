<script setup lang="ts">
import type { StageHistory } from "../../lib/types";

defineProps<{ history: StageHistory[] }>();

function formatDate(dt: string): string {
  return new Date(dt).toLocaleString("en-KE", { dateStyle: "medium", timeStyle: "short" });
}
</script>

<template>
  <div class="timeline">
    <div v-if="!history.length" class="timeline-empty">No stage history yet.</div>
    <div v-for="h in history" :key="h.id" class="timeline-item">
      <div class="timeline-dot" :class="h.to_stage.stage_type" />
      <div class="timeline-body">
        <div class="timeline-title">
          <span v-if="h.from_stage">{{ h.from_stage.name }} → </span>
          <strong>{{ h.to_stage.name }}</strong>
        </div>
        <div class="timeline-meta">
          {{ h.moved_by.name }} · {{ formatDate(h.moved_at) }}
        </div>
        <div v-if="h.notes" class="timeline-notes">{{ h.notes }}</div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.timeline { display: flex; flex-direction: column; gap: 0; }
.timeline-empty { color: var(--color-text-muted); font-size: var(--font-size-sm); padding: var(--space-3); }
.timeline-item { display: flex; gap: var(--space-3); padding: var(--space-3) 0; border-left: 2px solid var(--color-border); margin-left: 8px; padding-left: var(--space-4); position: relative; }
.timeline-dot { width: 12px; height: 12px; border-radius: 50%; position: absolute; left: -7px; top: var(--space-4); flex-shrink: 0; }
.timeline-dot.hired    { background: #10b981; }
.timeline-dot.rejected { background: #ef4444; }
.timeline-dot.offer    { background: #f59e0b; }
.timeline-dot.interview{ background: #6366f1; }
.timeline-dot.screening{ background: #3b82f6; }
.timeline-dot.application{ background: var(--color-text-muted); }
.timeline-dot.decision { background: #8b5cf6; }
.timeline-body { display: flex; flex-direction: column; gap: 2px; }
.timeline-title { font-size: var(--font-size-base); color: var(--color-text); }
.timeline-meta { font-size: var(--font-size-sm); color: var(--color-text-muted); }
.timeline-notes { font-size: var(--font-size-sm); color: var(--color-text-light); font-style: italic; margin-top: 2px; }
</style>
