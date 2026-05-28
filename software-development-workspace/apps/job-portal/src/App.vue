<template>
  <div>
    <nav class="portal-nav">
      <div class="container inner">
        <!-- Org logo / name -->
        <router-link to="/" class="nav-brand">
          <template v-if="org.logo_url">
            <img :src="org.logo_url" :alt="org.name" class="org-logo" />
          </template>
          <template v-else-if="org.name">
            <span class="org-initials" :style="{ background: org.primary_color, color: orgTextColor }">
              {{ initials(org.name) }}
            </span>
            <span class="org-name">{{ org.name }}</span>
          </template>
          <template v-else>
            <span class="portal-wordmark">
              Bitwyse<span class="logo-dot">.</span>Careers
            </span>
          </template>
          <span class="careers-badge">Careers</span>
        </router-link>

        <router-link to="/" class="nav-link">← All Jobs</router-link>
      </div>
    </nav>

    <router-view :org="org" @org-loaded="onOrgLoaded" />

    <footer class="portal-footer">
      <div class="container footer-inner">
        <span v-if="org.name">{{ org.name }}</span>
        <span v-else>Bitwyse</span>
        <span class="footer-sep">·</span>
        <span>© {{ year }} All rights reserved</span>
      </div>
    </footer>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { applyOrgTheme, contrastColor } from './utils/theme.js'

const year = new Date().getFullYear()

const org = ref({ name: null, logo_url: null, primary_color: '#004C97' })

function onOrgLoaded(orgData) {
  org.value = orgData || { name: null, logo_url: null, primary_color: '#004C97' }
  applyOrgTheme(org.value)
}

const orgTextColor = computed(() => contrastColor(org.value.primary_color || '#004C97'))

function initials(name) {
  return name
    .split(/\s+/)
    .slice(0, 2)
    .map(w => w[0])
    .join('')
    .toUpperCase()
}
</script>

<style scoped>
.portal-footer {
  border-top: 1px solid var(--border);
  padding: 24px 0;
  margin-top: 80px;
  font-size: 13px;
  color: var(--text-muted);
  text-align: center;
}
.footer-inner {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}
.footer-sep { opacity: .4; }
</style>

