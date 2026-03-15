<script setup lang="ts">
import { computed } from "vue";
import { useRoute } from "vue-router";
import AppNav from "./components/AppNav.vue";
import { isConfigured } from "./stores/auth";

const route = useRoute();
const showNav = computed(() => isConfigured.value && route.name !== "login");
</script>

<template>
  <div :class="['app-layout', { 'with-nav': showNav }]">
    <AppNav v-if="showNav" />
    <main class="app-content">
      <router-view />
    </main>
  </div>
</template>
