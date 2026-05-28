<template>
  <div>
    <div v-if="loading" class="state-center container">
      <span class="spinner"></span>
      <p style="margin-top:12px;color:var(--text-muted)">Loading job details…</p>
    </div>

    <div v-else-if="error" class="container" style="padding:60px 24px;">
      <div class="alert alert-error">{{ error }}</div>
      <router-link to="/" class="btn btn-outline" style="margin-top:16px;">← Browse all jobs</router-link>
    </div>

    <template v-else-if="job">
      <!-- Org identity bar at the very top of the detail page -->
      <div class="org-identity-bar" :style="{ borderBottomColor: orgColor }">
        <div class="container org-identity-inner">
          <div class="org-card">
            <template v-if="job.organization?.logo_url">
              <img :src="job.organization.logo_url" :alt="job.organization?.name" class="org-logo-lg" />
            </template>
            <template v-else-if="job.organization?.name">
              <span class="org-avatar-lg" :style="{ background: orgColor, color: orgTextColor }">
                {{ initials(job.organization.name) }}
              </span>
            </template>
            <div class="org-info">
              <p class="org-hiring-label">Hiring at</p>
              <p class="org-name-text">{{ job.organization?.name ?? 'Unknown Company' }}</p>
            </div>
          </div>
          <div class="breadcrumb">
            <router-link to="/">All Jobs</router-link>
            <span>›</span>
            <span>{{ job.title }}</span>
          </div>
        </div>
      </div>

      <!-- Job hero bar -->
      <section class="job-hero" :style="heroStyle">
        <div class="container">
          <div class="job-hero-content">
            <div class="hero-left">
              <div class="dept-chip" v-if="job.department">{{ job.department }}</div>
              <h1 class="job-title">{{ job.title }}</h1>
              <p class="job-name">{{ job.name }}</p>
              <div class="job-meta">
                <span v-if="job.location" class="meta-item">
                  <svg viewBox="0 0 16 16" fill="none" class="meta-icon"><path d="M8 1.5a4.5 4.5 0 0 1 4.5 4.5C12.5 9.5 8 14.5 8 14.5S3.5 9.5 3.5 6A4.5 4.5 0 0 1 8 1.5z" stroke="currentColor" stroke-width="1.4"/><circle cx="8" cy="6" r="1.5" stroke="currentColor" stroke-width="1.4"/></svg>
                  {{ job.location }}
                </span>
                <span class="meta-item" v-if="job.open_positions">
                  <svg viewBox="0 0 16 16" fill="none" class="meta-icon"><path d="M6 8a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5zm-4 5c0-2.21 1.79-4 4-4s4 1.79 4 4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/><path d="M11 5a2 2 0 1 0 0-4 2 2 0 0 0 0 4zm3 8c0-1.66-1.12-3.07-2.67-3.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                  {{ job.open_positions }} open {{ job.open_positions === 1 ? 'position' : 'positions' }}
                </span>
                <span class="meta-item" v-if="job.closing_date">
                  <svg viewBox="0 0 16 16" fill="none" class="meta-icon"><rect x="2" y="2" width="12" height="12" rx="2" stroke="currentColor" stroke-width="1.4"/><path d="M2 6h12M6 2v2M10 2v2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                  Closes {{ formatDate(job.closing_date) }}
                </span>
              </div>
            </div>
            <div class="hero-right">
              <div class="share-card">
                <p class="share-label">Share this job</p>
                <div class="share-btns">
                  <a :href="linkedinShare" target="_blank" rel="noopener" class="share-btn linkedin">
                    <svg viewBox="0 0 24 24" fill="currentColor" class="share-icon"><path d="M20.447 20.452H17.17v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.905V9h3.144v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a1.836 1.836 0 0 1-1.838-1.833 1.836 1.836 0 1 1 1.838 1.833zm1.573 13.019H3.766V9h3.144v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>
                    LinkedIn
                  </a>
                  <a :href="emailShare" class="share-btn email">
                    <svg viewBox="0 0 24 24" fill="none" class="share-icon" stroke="currentColor" stroke-width="1.8"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    Email
                  </a>
                  <button class="share-btn copy" @click="copyLink">
                    <svg viewBox="0 0 24 24" fill="none" class="share-icon" stroke="currentColor" stroke-width="1.8"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                    {{ copied ? 'Copied!' : 'Copy link' }}
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- Content + Form -->
      <div class="container page-body">
        <!-- Job description -->
        <section class="job-desc-card" v-if="job.description">
          <h2 class="section-title">About this role</h2>
          <div class="desc-text" v-html="formattedDesc"></div>
        </section>

        <!-- Application form -->
        <section class="apply-card" id="apply-form">
          <div class="apply-card-header" :style="{ background: orgColor, color: orgTextColor }">
            <h2 class="apply-card-title">Apply for this position</h2>
            <p class="apply-card-sub">All fields marked * are required</p>
          </div>
          <div class="apply-card-body">
            <div v-if="submitError" class="alert alert-error" style="margin-bottom:16px;">{{ submitError }}</div>

            <form @submit.prevent="submitForm" novalidate>
              <div class="form-section">
                <h3 class="form-section-title">Personal Information</h3>
                <div class="form-row-2">
                  <div class="form-group">
                    <label class="form-label">First Name <span class="req">*</span></label>
                    <input v-model="form.first_name" class="form-input" :class="{ error: fe.first_name }" placeholder="e.g. Jane" />
                    <span class="field-error" v-if="fe.first_name">{{ fe.first_name }}</span>
                  </div>
                  <div class="form-group">
                    <label class="form-label">Last Name <span class="req">*</span></label>
                    <input v-model="form.last_name" class="form-input" :class="{ error: fe.last_name }" placeholder="e.g. Kamau" />
                    <span class="field-error" v-if="fe.last_name">{{ fe.last_name }}</span>
                  </div>
                </div>
                <div class="form-row-2">
                  <div class="form-group">
                    <label class="form-label">Email Address <span class="req">*</span></label>
                    <input v-model="form.email" type="email" class="form-input" :class="{ error: fe.email }" placeholder="jane@example.com" />
                    <span class="field-error" v-if="fe.email">{{ fe.email }}</span>
                  </div>
                  <div class="form-group">
                    <label class="form-label">Phone Number</label>
                    <input v-model="form.phone" type="tel" class="form-input" placeholder="+254 700 000 000" />
                  </div>
                </div>
                <div class="form-row-2">
                  <div class="form-group">
                    <label class="form-label">Current Location</label>
                    <input v-model="form.location" class="form-input" placeholder="e.g. Nairobi, Kenya" />
                  </div>
                  <div class="form-group">
                    <label class="form-label">LinkedIn Profile</label>
                    <input v-model="form.linkedin_url" type="url" class="form-input" placeholder="https://linkedin.com/in/yourname" />
                  </div>
                </div>
              </div>

              <div class="form-section">
                <h3 class="form-section-title">Documents</h3>
                <div class="form-group">
                  <label class="form-label">CV / Resume <span class="req">*</span></label>
                  <label class="file-upload-label" :class="{ 'has-file': form.cv, error: fe.cv }">
                    <span class="upload-icon">{{ form.cv ? '✅' : '📄' }}</span>
                    <span class="upload-text">{{ form.cv ? form.cv.name : 'Click to upload your CV' }}</span>
                    <span class="upload-hint">PDF, DOC or DOCX · Max 5 MB</span>
                    <input type="file" accept=".pdf,.doc,.docx" @change="onCvChange" />
                  </label>
                  <span class="field-error" v-if="fe.cv">{{ fe.cv }}</span>
                </div>
                <div class="form-group" style="margin-top:12px;">
                  <label class="form-label">Supporting Document <span style="color:var(--text-muted);font-weight:400">(optional)</span></label>
                  <label class="file-upload-label" :class="{ 'has-file': form.attachment }">
                    <span class="upload-icon">{{ form.attachment ? '📎' : '🗂️' }}</span>
                    <span class="upload-text">{{ form.attachment ? form.attachment.name : 'Portfolio, certificates, references…' }}</span>
                    <span class="upload-hint">PDF, DOC, DOCX, JPG or PNG · Max 5 MB</span>
                    <input type="file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" @change="onAttachChange" />
                  </label>
                </div>
                <div class="form-group" style="margin-top:12px;">
                  <label class="form-label">Cover Letter</label>
                  <textarea v-model="form.cover_letter" class="form-textarea" placeholder="Tell us why you're a great fit for this role…" rows="6"></textarea>
                  <p class="field-hint">{{ form.cover_letter.length }}/8000 characters</p>
                </div>
              </div>

              <div class="privacy-note">
                <svg viewBox="0 0 16 16" fill="none" class="privacy-icon"><path d="M8 1.5L2 4v4c0 3.31 2.5 6.41 6 7 3.5-.59 6-3.69 6-7V4L8 1.5z" stroke="currentColor" stroke-width="1.3"/></svg>
                Your information is processed securely and used only for recruitment purposes. We will never share it with third parties without your consent.
              </div>

              <div class="form-actions">
                <button type="submit" class="btn btn-primary" :disabled="submitting" style="min-width:200px;">
                  <span v-if="submitting" class="spinner" style="width:16px;height:16px;border-width:2px;"></span>
                  {{ submitting ? 'Submitting…' : 'Submit Application →' }}
                </button>
              </div>
            </form>
          </div>
        </section>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { getJob, applyToJob } from '../api.js'
import { applyOrgTheme, contrastColor } from '../utils/theme.js'

defineProps({ org: Object })
const emit = defineEmits(['org-loaded'])

const route  = useRoute()
const router = useRouter()
const uuid   = route.params.uuid

const job      = ref(null)
const loading  = ref(true)
const error    = ref('')
const copied   = ref(false)
const submitting  = ref(false)
const submitError = ref('')

const form = ref({
  first_name: '', last_name: '', email: '', phone: '',
  location: '', linkedin_url: '', cover_letter: '',
  cv: null, attachment: null,
})
const fe = ref({})

// ── Org theming ──────────────────────────────────────────────────────────
const orgColor = computed(() => job.value?.organization?.primary_color || '#004C97')
const orgTextColor = computed(() => contrastColor(orgColor.value))

const heroStyle = computed(() => {
  const c = orgColor.value
  return { background: `linear-gradient(135deg, ${c} 0%, ${lighten(c, 0.12)} 50%, ${lighten(c, 0.28)} 100%)` }
})

function lighten(hex, amount) {
  const n = parseInt(hex.replace('#', ''), 16)
  const r = Math.min(255, Math.round(((n >> 16) & 255) + (255 - ((n >> 16) & 255)) * amount))
  const g = Math.min(255, Math.round(((n >> 8) & 255) + (255 - ((n >> 8) & 255)) * amount))
  const b = Math.min(255, Math.round((n & 255) + (255 - (n & 255)) * amount))
  return `#${[r,g,b].map(v => v.toString(16).padStart(2,'0')).join('')}`
}

function initials(name) {
  return (name || '').split(/\s+/).slice(0, 2).map(w => w[0]).join('').toUpperCase()
}

// ── Share ────────────────────────────────────────────────────────────────
const portalUrl     = computed(() => window.location.href)
const linkedinShare = computed(() => `https://www.linkedin.com/sharing/share-offsite/?url=${encodeURIComponent(portalUrl.value)}`)
const emailShare    = computed(() => {
  if (!job.value) return '#'
  const s = encodeURIComponent(`Job Opportunity: ${job.value.title}`)
  const b = encodeURIComponent(`Hi,\n\nI thought you might be interested in this job opening:\n\n${job.value.title} — ${job.value.name}\nCompany: ${job.value.organization?.name ?? ''}\n${portalUrl.value}\n\nDeadline: ${job.value.closing_date ?? 'Open until filled'}`)
  return `mailto:?subject=${s}&body=${b}`
})

const formattedDesc = computed(() => (job.value?.description ?? '').replace(/\n/g, '<br>'))

function formatDate(d) {
  return new Date(d).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
}

async function copyLink() {
  try {
    await navigator.clipboard.writeText(portalUrl.value)
    copied.value = true
    setTimeout(() => { copied.value = false }, 2000)
  } catch { /* ignore */ }
}

// ── Form ─────────────────────────────────────────────────────────────────
function onCvChange(e) { form.value.cv = e.target.files[0] ?? null }
function onAttachChange(e) { form.value.attachment = e.target.files[0] ?? null }

function validate() {
  const errs = {}
  if (!form.value.first_name.trim()) errs.first_name = 'First name is required.'
  if (!form.value.last_name.trim())  errs.last_name  = 'Last name is required.'
  if (!form.value.email.trim())      errs.email      = 'Email is required.'
  else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.value.email)) errs.email = 'Enter a valid email address.'
  if (!form.value.cv)                errs.cv         = 'Please upload your CV.'
  fe.value = errs
  return Object.keys(errs).length === 0
}

async function submitForm() {
  submitError.value = ''
  if (!validate()) {
    document.querySelector('.field-error')?.scrollIntoView({ behavior: 'smooth', block: 'center' })
    return
  }
  submitting.value = true
  try {
    const fd = new FormData()
    fd.append('first_name',   form.value.first_name.trim())
    fd.append('last_name',    form.value.last_name.trim())
    fd.append('email',        form.value.email.trim())
    if (form.value.phone)        fd.append('phone',        form.value.phone)
    if (form.value.location)     fd.append('location',     form.value.location)
    if (form.value.linkedin_url) fd.append('linkedin_url', form.value.linkedin_url)
    if (form.value.cover_letter) fd.append('cover_letter', form.value.cover_letter)
    fd.append('cv', form.value.cv)
    if (form.value.attachment)   fd.append('attachment', form.value.attachment)

    const res = await applyToJob(uuid, fd)
    router.push({ name: 'success', params: { uuid }, query: { ref: res.reference, title: job.value?.title ?? '' } })
  } catch (e) {
    submitError.value = e.message
    window.scrollTo({ top: 0, behavior: 'smooth' })
  } finally {
    submitting.value = false
  }
}

onMounted(async () => {
  try {
    const res = await getJob(uuid)
    job.value = res.data
    emit('org-loaded', res.data.organization ?? null)
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
})
</script>

<style scoped>
.state-center { text-align: center; padding: 80px 0; }

/* Org identity bar */
.org-identity-bar {
  background: #fff;
  border-bottom: 3px solid #004C97;
  padding: 14px 0;
}
.org-identity-inner {
  display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;
}
.org-card { display: flex; align-items: center; gap: 14px; }
.org-logo-lg {
  height: 44px; width: auto; max-width: 180px; object-fit: contain;
  border-radius: 8px; padding: 6px 10px; background: #f8fafc; border: 1px solid #e2e8f0;
}
.org-avatar-lg {
  display: inline-flex; align-items: center; justify-content: center;
  width: 44px; height: 44px; border-radius: 10px;
  font-size: 16px; font-weight: 800; flex-shrink: 0;
}
.org-info { display: flex; flex-direction: column; gap: 2px; }
.org-hiring-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; color: #94a3b8; }
.org-name-text { font-size: 16px; font-weight: 700; color: #0f172a; }
.breadcrumb { display: flex; align-items: center; gap: 8px; font-size: 13px; color: #94a3b8; }
.breadcrumb a { color: #64748b; }
.breadcrumb a:hover { text-decoration: underline; }

/* Job hero */
.job-hero { color: #fff; padding: 48px 0 40px; transition: background .4s; }
.job-hero-content { display: flex; align-items: flex-start; justify-content: space-between; gap: 32px; flex-wrap: wrap; }
.hero-left { flex: 1; min-width: 280px; }
.dept-chip { display: inline-block; padding: 4px 12px; border-radius: 20px; background: rgba(255,255,255,.2); font-size: 12px; font-weight: 600; margin-bottom: 12px; color: #fff; }
.job-title { font-size: clamp(24px, 4vw, 38px); font-weight: 800; letter-spacing: -.5px; margin-bottom: 6px; }
.job-name { font-size: 16px; opacity: .8; margin-bottom: 16px; }
.job-meta { display: flex; flex-wrap: wrap; gap: 16px; }
.meta-item { display: flex; align-items: center; gap: 6px; font-size: 14px; opacity: .9; }
.meta-icon { width: 15px; height: 15px; }

/* Share card */
.hero-right { flex-shrink: 0; }
.share-card { background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.25); border-radius: 12px; padding: 20px 22px; min-width: 260px; }
.share-label { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .6px; opacity: .7; margin-bottom: 12px; }
.share-btns { display: flex; flex-direction: column; gap: 8px; }
.share-btn { display: flex; align-items: center; gap: 10px; padding: 9px 14px; border-radius: 8px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; text-decoration: none; transition: background .15s; }
.share-icon { width: 16px; height: 16px; flex-shrink: 0; }
.linkedin { background: #0a66c2; color: #fff; }
.linkedin:hover { background: #004182; }
.email { background: rgba(255,255,255,.2); color: #fff; border: 1px solid rgba(255,255,255,.3); }
.email:hover { background: rgba(255,255,255,.3); }
.copy { background: rgba(255,255,255,.2); color: #fff; border: 1px solid rgba(255,255,255,.3); }
.copy:hover { background: rgba(255,255,255,.3); }

/* Page body */
.page-body { display: grid; grid-template-columns: 1fr 1fr; gap: 28px; padding: 36px 24px; align-items: start; }
@media (max-width: 860px) { .page-body { grid-template-columns: 1fr; } }

/* Job desc card */
.job-desc-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 28px; }
.section-title { font-size: 20px; font-weight: 700; margin-bottom: 8px; }
.desc-text { font-size: 15px; line-height: 1.75; color: var(--text-muted); }

/* Apply card */
.apply-card { background: var(--surface); border: 1.5px solid var(--border); border-radius: var(--radius); overflow: hidden; box-shadow: var(--shadow); }
.apply-card-header { padding: 22px 28px; }
.apply-card-title { font-size: 20px; font-weight: 700; margin-bottom: 4px; }
.apply-card-sub { font-size: 14px; opacity: .8; }
.apply-card-body { padding: 24px 28px; }

/* Form */
.form-section { border-top: 1px solid var(--border); padding-top: 20px; margin-top: 20px; display: flex; flex-direction: column; gap: 14px; }
.form-section:first-child { border-top: none; padding-top: 0; margin-top: 0; }
.form-section-title { font-size: 15px; font-weight: 700; color: var(--text); margin-bottom: 4px; }
.form-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
@media (max-width: 600px) { .form-row-2 { grid-template-columns: 1fr; } }
.field-error { font-size: 12px; color: #ef4444; }
.field-hint { font-size: 12px; color: var(--text-muted); text-align: right; margin-top: 4px; }
.file-upload-label.error { border-color: #ef4444; }
.privacy-note { display: flex; align-items: flex-start; gap: 10px; padding: 14px 16px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; font-size: 13px; color: #166534; line-height: 1.5; margin: 8px 0; }
.privacy-icon { width: 16px; height: 16px; flex-shrink: 0; margin-top: 1px; }
.form-actions { display: flex; justify-content: flex-end; margin-top: 20px; }
</style>
