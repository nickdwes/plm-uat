<script setup lang="ts">
import { authStore, setOrg, hasMultipleOrgs } from "../stores/auth";
import type { Organization } from "../lib/types";

const emit = defineEmits<{ change: [] }>();

function onChange(e: Event): void {
  const id = Number((e.target as HTMLSelectElement).value);
  const org = authStore.organizations.find((o) => o.id === id);
  if (org) {
    setOrg(org as Organization);
    emit("change");
  }
}
</script>

<template>
  <div v-if="hasMultipleOrgs || authStore.organizations.length === 1" class="org-selector">
    <label for="org-select">Org</label>
    <select id="org-select" class="input" :value="authStore.currentOrg?.id ?? ''" @change="onChange">
      <option v-for="org in authStore.organizations" :key="org.id" :value="org.id">
        {{ org.name }}
      </option>
    </select>
  </div>
</template>
