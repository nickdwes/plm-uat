<template>
  <div>
    <!-- Hero — branded with org color -->
    <section class="hero" :style="heroStyle">
      <div class="container">
        <div class="hero-inner">
          <!-- Org logo in hero -->
          <div class="hero-org" v-if="primaryOrg">
            <img v-if="primaryOrg.logo_url" :src="primaryOrg.logo_url" :alt="primaryOrg.name" class="hero-org-logo" />
            <span v-else class="hero-org-initials" :style="initialsStyle">{{ initials(primaryOrg.name) }}</span>
          </div>
          <p class="hero-tag">We're hiring</p>
          <h1 class="hero-title">Build the future<br/>with us.</h1>
          <p class="hero-sub" v-if="primaryOrg?.name">
            Join <strong>{{ primaryOrg.name }}</strong> and be part of a team making a difference.
          </p>
          <p class="hero-sub" v-else>Join a team that's making HR smarter across Africa. Browse open roles below.</p>
        </div>
      </div>
    </section>

    <!-- Search + filter -->
    <section class="search-section">
      <div class="container search-row">
        <div class="search-box">
          <svg class="s-icon" viewBox="0 0 20 20" fill="none"><circle cx="8.5" cy="8.5" r="5.5" stroke="currentColor" stroke-width="1.6"/><path d="M13.5 13.5L17 17" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
          <input v-model="search" class="s-input" placeholder="Search by title or location…" @input="debouncedLoad" />
          <button v-if="search" class="s-clear" @click="search = ''; load()">✕</button>
        </div>
      </div>
    </section>

    <!-- Content -->
    <main class="container main-content">
      <div v-if="loading" class="state-center">
        <span class="spinner"></span>
        <p style="margin-top:12px;color:var(--text-muted)">Loading opportunities…</p>
      </div>

      <div v-else-if="error" class="alert alert-error">{{ error }}</div>

      <template v-else>
        <div class="results-header">
          <span class="results-count">{{ total }} open position{{ total !== 1 ? 's' : '' }}</span>
        </div>

        <div v-if="jobs.length === 0" class="empty-state">
          <div class="empty-icon">🔍</div>
          <p>No matching roles found{{ search ? ` for "${search}"` : '' }}.</p>
          <p style="font-size:14px;margin-top:6px;">Check back soon — we're always growing!</p>
        </div>

        <div class="job-grid">
          <router-link
            v-for="job in jobs"
            :key="job.uuid"
            :to="`/jobs/${job.uuid}`"
            class="job-card"
          >
            <!-- Org branding stripe -->
            <div class="job-card-org-bar" :style="{ background: job.organization?.primary_color || 'var(--brand)' }"></div>

            <div class="job-card-body">
              <div class="job-card-top">
                <div class="job-org-row">
                  <img
                    v-if="job.organization?.logo_url"
                    :src="job.organization.logo_url"
                    :alt="job.organization?.name"
                    class="card-org-logo"
                  />
                  <span
                    v-else-if="job.organization?.name"
                    class="card-org-initials"
                    :style="{ background: job.organization?.primary_color || 'var(--brand)' }"
                  >{{ initials(job.organization.name) }}</span>
                  <span class="card-org-name">{{ job.organization?.name }}</span>
                </div>
                <div class="job-closing" v-if="job.closing_date">Closes {{ formatDate(job.closing_date) }}</div>
              </div>

              <div class="job-dept-chip" :style="deptChipStyle(job)" v-if="job.department">{{ job.department }}</div>
              <h3 class="job-title">{{ job.title }}</h3>
              <p class="job-name">{{ job.name }}</p>

              <div class="job-meta">
                <span v-if="job.location" class="meta-item">
                  <svg viewBox="0 0 16 16" fill="none" class="meta-icon"><path d="M8 1.5a4.5 4.5 0 0 1 4.5 4.5C12.5 9.5 8 14.5 8 14.5S3.5 9.5 3.5 6A4.5 4.5 0 0 1 8 1.5z" stroke="currentColor" stroke-width="1.4"/><circle cx="8" cy="6" r="1.5" stroke="currentColor" stroke-width="1.4"/></svg>
                  {{ job.location }}
                </span>
                <span class="meta-item">
                  <svg viewBox="0 0 16 16" fill="none" class="meta-icon"><rect x="2" y="2" width="12" height="12" rx="2" stroke="currentColor" stroke-width="1.4"/><path d="M5 8h6M5 5.5h3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                  {{ job.open_positions }} {{ job.open_positions === 1 ? 'position' : 'positions' }}
                </span>
              </div>

              <div class="job-card-footer" :style="{ borderColor: job.organization?.primary_color ? job.organization.primary_color + '22' : undefined }">
                <span class="apply-link" :style="{ color: job.organization?.primary_color || 'var(--brand)' }">Apply now →</span>
              </div>
            </div>
          </router-link>
        </div>

        <!-- Pagination -->
        <div v-if="lastPage > 1" class="pagination">
          <button class="btn btn-outline btn-sm" :disabled="page <= 1" @click="load(page - 1)">← Previous</button>
          <span class="page-info">Page {{ page }} of {{ lastPage }}</span>
          <button class="btn btn-outline btn-sm" :disabled="page >= lastPage" @click="load(page + 1)">Next →</button>
        </div>
      </template>
    </main>
  </div>
</template>


<script setup>
import { ref, computed, onMounted } from 'vue'
import { getJobs } from '../api.js'
import { contrastColor } from '../utils/theme.js'

const emit = defineEmits(['org-loaded'])
defineProps({ org: Object })

const jobs     = ref([])
const total    = ref(0)
const lastPage = ref(1)
const page     = ref(1)
const search   = ref('')
const loading  = ref(false)
const error    = ref('')
let debounceTimer = null

const primaryOrg = computed(() => jobs.value[0]?.organization ?? null)

const heroStyle = computed(() => {
  const color = primaryOrg.value?.primary_color || '#004C97'
  return { background: `linear-gradient(135deg, ${color} 0%, ${lighten(color, 0.15)} 60%, ${lighten(color, 0.30)} 100%)` }
})

const initialsStyle = computed(() => ({ background: 'rgba(255,255,255,0.2)', color: '#fff' }))

function deptChipStyle(job) {
  const color = job.organization?.primary_color || '#004C97'
  return { background: color + '18', color }
}

function initials(name) {
  return (name || '').split(/\s+/).slice(0, 2).map(w => w[0]).join('').toUpperCase()
}

function lighten(hex, amount) {
  const n = parseInt(hex.replace('#', ''), 16)
  const r = Math.min(255, Math.round(((n >> 16) & 255) + (255 - ((n >> 16) & 255)) * amount))
  const g = Math.min(255, Math.round(((n >> 8) & 255) + (255 - ((n >> 8) & 255)) * amount))
  const b = Math.min(255, Math.round((n & 255) + (255 - (n & 255)) * amount))
  return `#${[r,g,b].map(v => v.toString(16).padStart(2,'0')).join('')}`
}

async function load(p = 1) {
  loading.value = true
  error.value   = ''
  page.value    = p
  try {
    const res  = await getJobs({ search: search.value || undefined, page: p })
    jobs.value  = res.data
    total.value = res.meta.total
    lastPage.value = res.meta.last_page
    emit('org-loaded', res.data[0]?.organization ?? null)
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

function debouncedLoad() {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => load(1), 400)
}

function formatDate(d) {
  return new Date(d).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
}

onMounted(() => load())
</script>

<style scoped>
.hero { color: #fff; padding: 80px 0 60px; transition: background .4s; }
.hero-inner { display: flex; flex-direction: column; gap: 0; }
.hero-org { margin-bottom: 20px; }
.hero-org-logo { height: 52px; width: auto; max-width: 200px; object-fit: contain; background: rgba(255,255,255,0.95); border-radius: 10px; padding: 8px 14px; }
.hero-org-initials { display: inline-flex; align-items: center; justify-content: center; width: 52px; height: 52px; border-radius: 12px; font-size: 20px; font-weight: 800; color: #fff; }
.hero-tag { font-size: 13px; font-weight: 600; letter-spacing: 1px; text-transform: uppercase; opacity: .75; margin-bottom: 16px; }
.hero-title { font-size: clamp(36px, 5vw, 56px); font-weight: 800; line-height: 1.1; letter-spacing: -1px; margin-bottom: 18px; }
.hero-sub { font-size: 18px; opacity: .85; max-width: 520px; line-height: 1.6; }
.hero-sub strong { opacity: 1; font-weight: 700; }
.search-section { background: var(--surface); border-bottom: 1px solid var(--border); padding: 20px 0; position: sticky; top: 64px; z-index: 90; }
.search-row { display: flex; align-items: center; gap: 12px; }
.search-box { display: flex; align-items: center; gap: 10px; flex: 1; max-width: 560px; padding: 0 16px; border: 1.5px solid var(--border); border-radius: 10px; background: var(--surface); transition: border-color .15s, box-shadow .15s; }
.search-box:focus-within { border-color: var(--brand); box-shadow: 0 0 0 3px rgba(var(--brand-rgb), .1); }
.s-icon { width: 17px; height: 17px; flex-shrink: 0; color: var(--text-muted); }
.s-input { flex: 1; border: none; outline: none; padding: 12px 0; font-size: 15px; background: transparent; color: var(--text); }
.s-input::placeholder { color: var(--text-muted); }
.s-clear { background: none; border: none; color: var(--text-muted); font-size: 14px; cursor: pointer; padding: 0 4px; }
.main-content { padding: 40px 24px; }
.results-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
.results-count { font-size: 15px; font-weight: 600; color: var(--text-muted); }
.state-center { text-align: center; padding: 80px 0; }
.empty-state { text-align: center; padding: 80px 20px; color: var(--text-muted); }
.empty-icon { font-size: 40px; margin-bottom: 12px; }
.job-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 20px; }
.job-card { display: flex; flex-direction: column; background: var(--surface); border: 1.5px solid var(--border); border-radius: var(--radius); overflow: hidden; transition: box-shadow .15s, border-color .15s, transform .15s; }
.job-card:hover { box-shadow: var(--shadow-lg); transform: translateY(-2px); }
.job-card-org-bar { height: 4px; flex-shrink: 0; }
.job-card-body { display: flex; flex-direction: column; gap: 10px; padding: 20px; flex: 1; }
.job-card-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
.job-org-row { display: flex; align-items: center; gap: 7px; min-width: 0; }
.card-org-logo { height: 22px; width: auto; max-width: 100px; object-fit: contain; flex-shrink: 0; }
.card-org-initials { display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 5px; font-size: 10px; font-weight: 800; color: #fff; flex-shrink: 0; }
.card-org-name { font-size: 12px; font-weight: 600; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.job-closing { font-size: 12px; color: var(--text-muted); white-space: nowrap; flex-shrink: 0; }
.job-dept-chip { width: fit-content; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
.job-title { font-size: 18px; font-weight: 700; color: var(--text); line-height: 1.3; }
.job-name { font-size: 14px; color: var(--text-muted); }
.job-meta { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 4px; }
.meta-item { display: flex; align-items: center; gap: 5px; font-size: 13px; color: var(--text-muted); }
.meta-icon { width: 14px; height: 14px; }
.job-card-footer { margin-top: auto; padding-top: 14px; border-top: 1px solid var(--border); }
.apply-link { font-size: 14px; font-weight: 600; }
.pagination { display: flex; align-items: center; justify-content: center; gap: 16px; margin-top: 48px; }
.page-info { font-size: 14px; color: var(--text-muted); }
</style>
