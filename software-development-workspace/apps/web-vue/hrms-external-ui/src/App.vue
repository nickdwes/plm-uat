<script setup lang="ts">
import { computed } from "vue";
import { useRoute } from "vue-router";
import AppNav from "./components/AppNav.vue";
import AppHeader from "./components/AppHeader.vue";
import { isConfigured } from "./stores/auth";

const route = useRoute();
const showNav = computed(() => isConfigured.value && route.name !== "login");
</script>

<template>
  <div :class="showNav ? 'app-layout' : 'app-login-wrapper'">
    <AppNav v-if="showNav" />
    <div v-if="showNav" class="app-main">
      <AppHeader />
      <div class="app-content">
        <router-view />
      </div>
    </div>
    <router-view v-else />
  </div>
</template>
