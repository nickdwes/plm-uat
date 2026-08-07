<script setup lang="ts">
/**
 * Pick an OEM or supplier.
 *
 * Mirrors AccountPicker deliberately — a task owed by Safaricom and one owed by
 * Versa are the same shape of question, and two different-looking controls for
 * it would only invite the "Versa-Fatih" mess the legacy ended up with.
 */
import { ref, onMounted, watch } from "vue";
import { api } from "../lib/api";
import { currentOrgSlug } from "../stores/auth";

const props = defineProps<{ modelValue: number | null; placeholder?: string }>();
const emit  = defineEmits<{ (e: "update:modelValue", v: number | null): void }>();

interface Partner { id: number; name: string }

const options = ref<Partner[]>([]);
const loading = ref(false);

async function load() {
  loading.value = true;
  try {
    const res = await api<{ data: Partner[] }>("GET", "/api/v1/vendors", {
      org: currentOrgSlug.value, params: { per_page: 200 },
    });
    options.value = res.data ?? [];
  } catch { options.value = []; }
  finally { loading.value = false; }
}

const selected = ref<number | null>(props.modelValue);
watch(() => props.modelValue, (v) => { selected.value = v; });
watch(selected, (v) => emit("update:modelValue", v === null ? null : Number(v)));

onMounted(load);
</script>

<template>
  <select v-model="selected" class="control">
    <option :value="null">{{ loading ? "Loading…" : (placeholder ?? "Select a vendor") }}</option>
    <option v-for="o in options" :key="o.id" :value="o.id">{{ o.name }}</option>
  </select>
</template>

<style scoped>
@reference "../theme.css";

.control {
  font-family: inherit; font-size: var(--text-sm-size);
  padding: 8px var(--space-3);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-md);
  background: var(--color-surface); color: var(--color-text); width: 100%;
}
.control:focus { @apply outline-none; border-color: var(--color-brand-base); box-shadow: 0 0 0 3px var(--color-brand-subtle); }
</style>
