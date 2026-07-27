<script setup lang="ts">
import type { FormFieldSchema } from "../../lib/types";

const props = defineProps<{
  fields: FormFieldSchema[];
  modelValue: Record<string, unknown>;
  readonly?: boolean;
}>();

const emit = defineEmits<{
  "update:modelValue": [v: Record<string, unknown>];
}>();

function update(key: string, value: unknown) {
  emit("update:modelValue", { ...props.modelValue, [key]: value });
}
</script>

<template>
  <div class="dynamic-form">
    <div v-for="field in fields" :key="field.key" class="form-group">
      <label class="form-label">
        {{ field.label }}
        <span v-if="field.required" class="required-star">*</span>
      </label>

      <textarea
        v-if="field.type === 'textarea'"
        :value="String(modelValue[field.key] ?? '')"
        :placeholder="field.placeholder ?? ''"
        :required="field.required"
        :readonly="readonly"
        class="form-input"
        rows="3"
        @input="update(field.key, ($event.target as HTMLTextAreaElement).value)"
      />

      <select
        v-else-if="field.type === 'select'"
        :value="String(modelValue[field.key] ?? '')"
        :required="field.required"
        :disabled="readonly"
        class="form-input"
        @change="update(field.key, ($event.target as HTMLSelectElement).value)"
      >
        <option value="">Select…</option>
        <option v-for="opt in field.options ?? []" :key="opt" :value="opt">{{ opt }}</option>
      </select>

      <input
        v-else
        :type="field.type"
        :value="String(modelValue[field.key] ?? '')"
        :placeholder="field.placeholder ?? ''"
        :required="field.required"
        :readonly="readonly"
        class="form-input"
        @input="update(field.key, ($event.target as HTMLInputElement).value)"
      />
    </div>
  </div>
</template>

<style scoped>
.dynamic-form { display: flex; flex-direction: column; gap: var(--space-4); }
.form-group { display: flex; flex-direction: column; gap: var(--space-1); }
.form-label { font-size: var(--font-size-sm); font-weight: var(--font-weight-semibold); color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.05em; }
.required-star { color: var(--color-red); margin-left: 2px; }
.form-input { padding: 8px 12px; border: 1px solid var(--color-border); border-radius: var(--radius-md); font-size: var(--font-size-base); width: 100%; background: var(--color-surface); }
.form-input:focus { outline: none; border-color: var(--color-brand-base); }
</style>
