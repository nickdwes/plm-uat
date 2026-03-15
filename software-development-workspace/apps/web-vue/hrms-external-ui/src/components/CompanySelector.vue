<script setup lang="ts">
import { authStore, isGlobalHR, setCompany } from "../stores/auth";
</script>

<template>
  <div v-if="authStore.companies.length > 1 || isGlobalHR" class="company-selector">
    <label for="company-select">Company</label>
    <select
      id="company-select"
      :value="authStore.company ?? ''"
      @change="setCompany(($event.target as HTMLSelectElement).value || null)"
    >
      <option v-if="isGlobalHR" value="">All companies</option>
      <option v-for="c in authStore.companies" :key="c" :value="c">{{ c }}</option>
    </select>
  </div>
</template>
