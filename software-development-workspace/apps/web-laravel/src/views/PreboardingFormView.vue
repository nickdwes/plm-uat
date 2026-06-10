<script setup lang="ts">
/**
 * Public pre-hire form — no auth required.
 * Accessed via a one-time secure link: /#/pre-onboarding/form/:token
 */
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { api } from '@/lib/api';

// ── Props ──────────────────────────────────────────────────────────────────
const props = defineProps<{ token: string }>();

// ── Types ──────────────────────────────────────────────────────────────────
interface SectionStatus {
  section: string;
  status: 'pending' | 'submitted' | 'approved';
  submitted_at: string | null;
  data: Record<string, unknown>;
}

interface PublicRecord {
  first_name: string;
  last_name: string;
  position: string | null;
  employee_type: string | null;
  expected_start_date: string | null;
  medical_cover_eligible: boolean;
  enabled_sections: string[];
  token_expires_at: string | null;
  status: string;
  sections: SectionStatus[];
  organization: {
    name: string;
    logo_url: string | null;
    primary_color: string | null;
    country: string | null;
  } | null;
}

interface UploadedDoc {
  name: string;
  category: string;
  size: string;
  uploading?: boolean;
  error?: string;
}

// ── Section metadata ───────────────────────────────────────────────────────
const SECTION_META: Record<string, { label: string; icon: string; desc: string }> = {
  self_intro:      { label: 'Welcome Message',         icon: '👋', desc: 'A short introduction about yourself.' },
  personal:        { label: 'Personal Details',         icon: '🪪', desc: 'Your ID, date of birth, gender, and personal details.' },
  contact:         { label: 'Contact Information',      icon: '📬', desc: 'Your address, phone numbers, and email.' },
  emergency:       { label: 'Emergency Contact',        icon: '🆘', desc: 'Someone we can reach in case of an emergency.' },
  dependents:      { label: 'Dependents',               icon: '👨‍👩‍👧', desc: 'Family members covered under your benefits.' },
  immigration:     { label: 'Immigration Details',      icon: '🛂', desc: 'Passport, work permit, and visa information.' },
  qualifications:  { label: 'Qualifications & Awards',  icon: '🎓', desc: 'Education, certifications, and professional achievements.' },
  memberships:     { label: 'Professional Memberships', icon: '🏅', desc: 'Any professional body memberships.' },
  attachments:     { label: 'Documents',                icon: '📎', desc: 'Upload required documents.' },
};

// ── Core state ─────────────────────────────────────────────────────────────
const loading  = ref(true);
const error    = ref<{ code: number; message: string } | null>(null);
const record   = ref<PublicRecord | null>(null);
const step     = ref(0);
const saving   = ref(false);
const saved    = ref<Record<string, boolean>>({});
const saveErr  = ref<string | null>(null);

// Auto-save state
const autoSaveStatus = ref<'idle' | 'saving' | 'saved' | 'error'>('idle');
let autoSaveTimer: ReturnType<typeof setInterval> | null = null;

// Per-section form data & uploads
const sectionData    = ref<Record<string, Record<string, unknown>>>({});
const sectionUploads = ref<Record<string, UploadedDoc[]>>({});

// ── File validation constants ─────────────────────────────────────────────
const ACCEPTED_EXT   = /\.(pdf|jpg|jpeg|png)$/i;
const SAFE_FILENAME  = /^[a-zA-Z0-9 _\-.]+$/;
const MAX_SIZE_GEN   = 15 * 1024 * 1024;   // 15 MB general
const MAX_SIZE_DOCS  = 2  * 1024 * 1024;   // 2 MB dependents/qualifications

function validateFile(file: File, maxSize: number): string | null {
  if (!ACCEPTED_EXT.test(file.name))
    return 'Only PDF, JPG, and PNG files are accepted.';
  const basename = file.name.replace(/\.[^.]+$/, '');
  if (!SAFE_FILENAME.test(basename))
    return 'File name may only contain letters, numbers, spaces, hyphens, and underscores.';
  if (file.size > maxSize)
    return `File too large. Maximum ${Math.round(maxSize / (1024 * 1024))} MB allowed.`;
  return null;
}

function formatSize(bytes: number): string {
  return bytes > 1024 * 1024
    ? (bytes / (1024 * 1024)).toFixed(1) + ' MB'
    : Math.round(bytes / 1024) + ' KB';
}

// ── Inline upload helper ───────────────────────────────────────────────────
async function uploadSectionDoc(
  section: string,
  category: string,
  file: File,
  maxSize = MAX_SIZE_GEN,
): Promise<boolean> {
  const err = validateFile(file, maxSize);
  if (err) { saveErr.value = err; return false; }

  if (!sectionUploads.value[section]) sectionUploads.value[section] = [];
  const idx = sectionUploads.value[section].push({
    name: file.name, category, size: formatSize(file.size), uploading: true,
  }) - 1;

  try {
    const fd = new FormData();
    fd.append('file', file);
    fd.append('category', category);
    await api('POST', `/api/public/preboarding/form/${props.token}/attachments`, { formData: fd });
    sectionUploads.value[section][idx].uploading = false;
    return true;
  } catch (e: unknown) {
    const msg = (e as { message?: string }).message ?? 'Upload failed';
    sectionUploads.value[section][idx].uploading = false;
    sectionUploads.value[section][idx].error = msg;
    return false;
  }
}

function removeDoc(section: string, idx: number) {
  sectionUploads.value[section]?.splice(idx, 1);
}

function handleInlineUpload(
  e: Event,
  section: string,
  category: string,
  maxSize = MAX_SIZE_GEN,
) {
  const file = (e.target as HTMLInputElement).files?.[0];
  if (!file) return;
  (e.target as HTMLInputElement).value = '';
  void uploadSectionDoc(section, category, file, maxSize);
}

// ── ID type / validation ──────────────────────────────────────────────────
const idType  = ref<'national_id' | 'passport'>('national_id');
const idError = ref('');

const ID_PATTERNS: Record<string, { regex: RegExp; hint: string }> = {
  national_id: { regex: /^\d{7,8}$/,             hint: '7–8 digit number, e.g. 12345678' },
  passport:    { regex: /^[A-Z]{1,2}\d{6,7}$/,   hint: 'e.g. A1234567 or AB123456' },
};

function validateId(value: string): string {
  if (!value) return '';
  return ID_PATTERNS[idType.value].regex.test(value)
    ? '' : `Invalid format — ${ID_PATTERNS[idType.value].hint}.`;
}

function onIdInput(value: string) {
  patchField('personal', 'id_type',   idType.value);
  patchField('personal', 'id_number', value);
  idError.value = validateId(value);
}

// ── KRA PIN validation ─────────────────────────────────────────────────────
const KRA_PIN_REGEX = /^[A-Z]\d{9}[A-Z]$/;
const kraPinError   = ref('');

function onKraPinInput(value: string) {
  const upper = value.toUpperCase();
  patchField('personal', 'kra_pin', upper);
  kraPinError.value = upper && !KRA_PIN_REGEX.test(upper)
    ? 'Format: A123456789Z (letter + 9 digits + letter)'
    : '';
}

// ── NSSF / NHIF validation ─────────────────────────────────────────────────
const nssfError = ref('');
const nhifError = ref('');

function onNssfInput(value: string) {
  patchField('personal', 'nssf_no', value);
  nssfError.value = value && !/^\d{7,12}$/.test(value) ? 'NSSF number must be 7–12 digits.' : '';
}

function onNhifInput(value: string) {
  patchField('personal', 'nhif_no', value);
  nhifError.value = value && !/^\d{7,12}$/.test(value) ? 'NHIF/SHA number must be 7–12 digits.' : '';
}

// ── Immigration computed ───────────────────────────────────────────────────
const isForeignResident = computed((): boolean => {
  const orgCountry = (record.value?.organization?.country ?? '').toUpperCase();
  const resCountry = (getField('contact', 'country_of_residence') as string ?? '').toUpperCase();
  if (!resCountry) return false;
  if (!orgCountry)  return true;
  return resCountry !== orgCountry;
});

function onResidenceCountryChange(value: string) {
  patchField('contact', 'country_of_residence', value);
}

// ── Dependents ────────────────────────────────────────────────────────────
function getDependents(): Array<Record<string, string>> {
  const v = sectionData.value['dependents']?.['list'];
  return Array.isArray(v) ? (v as Array<Record<string, string>>) : [];
}
function addDependent() {
  const list = getDependents();
  list.push({ name: '', relationship: '', dob: '' });
  patchField('dependents', 'list', list);
}
function removeDependent(i: number) {
  const list = getDependents();
  list.splice(i, 1);
  patchField('dependents', 'list', list);
}

// Per-dependent document helpers — category: dep_<index>_<type>
function depDocs(i: number, cat: string): UploadedDoc[] {
  return sectionUploads.value['dependents']?.filter(d => d.category === `dep_${i}_${cat}`) ?? [];
}
function removeDepDoc(i: number, cat: string, docIdx: number) {
  const all = sectionUploads.value['dependents'];
  if (!all) return;
  const target = all.filter(d => d.category === `dep_${i}_${cat}`)[docIdx];
  const absIdx = all.indexOf(target);
  if (absIdx >= 0) all.splice(absIdx, 1);
}

function docsForSection(section: string, category: string): UploadedDoc[] {
  return sectionUploads.value[section]?.filter(d => d.category === category) ?? [];
}
function anyDocForSection(section: string, categories: string[]): boolean {
  return sectionUploads.value[section]?.some(d => categories.includes(d.category)) ?? false;
}

// ── Qualifications ─────────────────────────────────────────────────────────
const QUAL_TYPES = [
  "Master's Degree", "Bachelor's Degree", "Postgraduate Diploma",
  "Diploma", "Certificate", "Professional Certification",
  "Award / Recognition", "Other",
];

function getQualifications(): Array<Record<string, string>> {
  const v = sectionData.value['qualifications']?.['list'];
  return Array.isArray(v) ? (v as Array<Record<string, string>>) : [];
}
function addQualification() {
  const list = getQualifications();
  list.push({ type: '', institution: '', qualification: '', year: '', grade: '' });
  patchField('qualifications', 'list', list);
}
function removeQualification(i: number) {
  const list = getQualifications();
  list.splice(i, 1);
  patchField('qualifications', 'list', list);
}

// ── Memberships ────────────────────────────────────────────────────────────
function getMemberships(): Array<Record<string, string>> {
  const v = sectionData.value['memberships']?.['list'];
  return Array.isArray(v) ? (v as Array<Record<string, string>>) : [];
}
function addMembership() {
  const list = getMemberships();
  list.push({ body: '', membership_no: '', valid_until: '' });
  patchField('memberships', 'list', list);
}
function removeMembership(i: number) {
  const list = getMemberships();
  list.splice(i, 1);
  patchField('memberships', 'list', list);
}

// ── General attachments tab ────────────────────────────────────────────────
const attachFile      = ref<File | null>(null);
const attachCategory  = ref('');
const attachUploading = ref(false);
const attachUploaded  = ref<{ name: string; category: string }[]>([]);
const attachErr       = ref<string | null>(null);

async function uploadAttachment() {
  if (!attachFile.value || !attachCategory.value.trim()) return;
  const err = validateFile(attachFile.value, MAX_SIZE_GEN);
  if (err) { attachErr.value = err; return; }
  attachUploading.value = true;
  attachErr.value = null;
  try {
    const fd = new FormData();
    fd.append('file', attachFile.value);
    fd.append('category', attachCategory.value.trim().toLowerCase().replace(/\s+/g, '_'));
    await api('POST', `/api/public/preboarding/form/${props.token}/attachments`, { formData: fd });
    attachUploaded.value.push({ name: attachFile.value.name, category: attachCategory.value });
    attachFile.value     = null;
    attachCategory.value = '';
    saved.value['attachments'] = true;
    const input = document.getElementById('attach-file') as HTMLInputElement | null;
    if (input) input.value = '';
  } catch (e: unknown) {
    attachErr.value = (e as { message?: string }).message ?? 'Upload failed.';
  } finally {
    attachUploading.value = false;
  }
}

function handleFileChange(e: Event) {
  attachFile.value = (e.target as HTMLInputElement).files?.[0] ?? null;
}

// ── Computed ───────────────────────────────────────────────────────────────
const enabledSections = computed(() => record.value?.enabled_sections ?? []);
const currentSection  = computed(() => enabledSections.value[step.value] ?? null);

const completedSections = computed(() => {
  if (!record.value) return new Set<string>();
  const fromServer = new Set(
    record.value.sections
      .filter(s => s.status !== 'pending')
      .map(s => s.section)
  );
  return new Set([...fromServer, ...Object.keys(saved.value).filter(k => saved.value[k])]);
});

const allDone = computed(() =>
  enabledSections.value.every(s => completedSections.value.has(s))
);

const expiryText = computed(() => {
  if (!record.value?.token_expires_at) return null;
  const diff = new Date(record.value.token_expires_at).getTime() - Date.now();
  if (diff <= 0) return 'Expired';
  const days = Math.ceil(diff / 86400000);
  return days === 1 ? 'Expires tomorrow' : `Expires in ${days} days`;
});

// ── Helpers ────────────────────────────────────────────────────────────────
function initSectionData(key: string) {
  if (!sectionData.value[key]) sectionData.value[key] = {};
}

function patchField(section: string, field: string, value: unknown) {
  if (!sectionData.value[section]) sectionData.value[section] = {};
  sectionData.value[section][field] = value;
}

function getField(section: string, field: string): unknown {
  return sectionData.value[section]?.[field] ?? '';
}

// ── Auto-save ──────────────────────────────────────────────────────────────
async function autoSave(sectionKey: string) {
  if (!sectionKey || !sectionData.value[sectionKey]) return;
  if (Object.keys(sectionData.value[sectionKey]).length === 0) return;
  autoSaveStatus.value = 'saving';
  try {
    await api('POST', `/api/public/preboarding/form/${props.token}/sections/${sectionKey}`, {
      body: { data: sectionData.value[sectionKey], draft: true },
    });
    autoSaveStatus.value = 'saved';
    setTimeout(() => {
      if (autoSaveStatus.value === 'saved') autoSaveStatus.value = 'idle';
    }, 3000);
  } catch {
    autoSaveStatus.value = 'error';
  }
}

// Auto-save when navigating away from a section
watch(step, (_newStep, oldStep) => {
  const leaving = enabledSections.value[oldStep];
  if (leaving) void autoSave(leaving);
});

// ── Initialise ─────────────────────────────────────────────────────────────
onMounted(async () => {
  try {
    const data = await api<PublicRecord>('GET', `/api/public/preboarding/form/${props.token}`);
    record.value = data;

    // Restore saved data and mark completed sections
    for (const sec of data.sections) {
      if (sec.status !== 'pending') saved.value[sec.section] = true;
      if (sec.data && Object.keys(sec.data).length > 0) {
        sectionData.value[sec.section] = { ...sec.data };
        // Restore idType from saved data
        if (sec.section === 'personal' && sec.data.id_type) {
          idType.value = sec.data.id_type as 'national_id' | 'passport';
        }
      }
    }

    // Ensure all enabled sections have an entry
    for (const key of data.enabled_sections) {
      if (!sectionData.value[key]) sectionData.value[key] = {};
    }

    // Advance to first incomplete section
    const firstPending = data.enabled_sections.findIndex(s => !saved.value[s]);
    step.value = firstPending >= 0 ? firstPending : 0;

    // Start 60-second auto-save interval
    autoSaveTimer = setInterval(() => {
      const current = enabledSections.value[step.value];
      if (current) void autoSave(current);
    }, 60000);
  } catch (e: unknown) {
    const err = e as { status?: number; message?: string };
    error.value = { code: err.status ?? 0, message: err.message ?? 'Something went wrong.' };
  } finally {
    loading.value = false;
  }
});

onUnmounted(() => {
  if (autoSaveTimer) clearInterval(autoSaveTimer);
});

// ── Submit with validation ─────────────────────────────────────────────────
async function validateAndSubmit(sectionKey: string) {
  saveErr.value = null;

  if (sectionKey === 'personal') {
    if (idError.value) { saveErr.value = 'Please fix the ID number before submitting.'; return; }
    if (kraPinError.value) { saveErr.value = 'Please fix the KRA PIN format before submitting.'; return; }
    if (nssfError.value || nhifError.value) { saveErr.value = 'Please fix NSSF/NHIF number errors.'; return; }
    // Require at least ID doc upload
    if (!anyDocForSection('personal', ['national_id','passport'])) {
      saveErr.value = 'Please upload a copy of your National ID or Passport before submitting.'; return;
    }
  }

  if (sectionKey === 'dependents') {
    const deps = getDependents();
    for (let i = 0; i < deps.length; i++) {
      if (deps[i].relationship === 'spouse' && depDocs(i, 'marriage').length === 0) {
        saveErr.value = `Please upload a Marriage Certificate or Affidavit for ${deps[i].name || `Dependent ${i + 1}`}.`; return;
      }
      if (deps[i].relationship === 'child' && depDocs(i, 'birth').length === 0) {
        saveErr.value = `Please upload a Birth Certificate or Adoption Certificate for ${deps[i].name || `Dependent ${i + 1}`}.`; return;
      }
    }
  }

  if (sectionKey === 'qualifications') {
    const quals = getQualifications();
    const uploaded = sectionUploads.value['qualifications']?.length ?? 0;
    if (quals.length > 0 && uploaded < quals.length) {
      saveErr.value = `Please upload a supporting document for each qualification (${quals.length} required, ${uploaded} uploaded).`;
      return;
    }
  }

  await submitSection(sectionKey);
}

async function submitSection(sectionKey: string) {
  saving.value  = true;
  saveErr.value = null;
  try {
    await api('POST', `/api/public/preboarding/form/${props.token}/sections/${sectionKey}`, {
      body: { data: sectionData.value[sectionKey] ?? {}, draft: false },
    });
    saved.value[sectionKey] = true;
    const next = enabledSections.value.findIndex(
      (s, i) => i > step.value && !completedSections.value.has(s)
    );
    if (next >= 0) step.value = next;
  } catch (e: unknown) {
    saveErr.value = (e as { message?: string }).message ?? 'Failed to save. Please try again.';
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <!-- ── Loading ── -->
  <div v-if="loading" class="pf-loading">
    <div class="pf-spinner"></div>
    <p>Loading your form…</p>
  </div>

  <!-- ── Error states ── -->
  <div v-else-if="error" class="pf-error-page">
    <div class="pf-error-card">
      <div class="pf-error-icon">
        {{ error.code === 410 ? '⏰' : error.code === 403 ? '✅' : '❌' }}
      </div>
      <h2 class="pf-error-title">
        {{ error.code === 410 ? 'Link Expired' : error.code === 403 ? 'Already Submitted' : 'Form Not Found' }}
      </h2>
      <p class="pf-error-msg">{{ error.message }}</p>
    </div>
  </div>

  <!-- ── Main form ── -->
  <div v-else-if="record" class="pf-page">

    <!-- Header -->
    <header class="pf-header">
      <div class="pf-header-inner">
        <div class="pf-logo">
          <template v-if="record?.organization?.logo_url">
            <img :src="record.organization.logo_url" :alt="record.organization.name" class="pf-logo-img" />
          </template>
          <template v-else-if="record?.organization?.name">
            {{ record.organization.name }}
          </template>
          <template v-else>HR Portal</template>
        </div>
        <div class="pf-header-right">
          <span v-if="autoSaveStatus === 'saving'" class="pf-autosave saving">⟳ Saving…</span>
          <span v-else-if="autoSaveStatus === 'saved'" class="pf-autosave saved">✓ Draft saved</span>
          <span v-else-if="autoSaveStatus === 'error'" class="pf-autosave err">⚠ Save failed</span>
          <span v-if="expiryText" class="pf-expiry-badge" :class="{ expired: expiryText === 'Expired' }">
            {{ expiryText }}
          </span>
        </div>
      </div>
    </header>

    <!-- Welcome banner -->
    <div class="pf-welcome">
      <div class="pf-welcome-inner">
        <h1 class="pf-welcome-title">Welcome, {{ record.first_name }}! 🎉</h1>
        <p class="pf-welcome-sub">
          We're thrilled to have you joining us
          <template v-if="record.position"> as <strong>{{ record.position }}</strong></template>
          <template v-if="record.expected_start_date">
            on <strong>{{ new Date(record.expected_start_date).toLocaleDateString('en-GB', { day:'numeric', month:'long', year:'numeric' }) }}</strong>
          </template>.
          Please complete the sections below so we can get everything ready for your first day.
        </p>
        <p class="pf-welcome-note">
          Your progress is saved after each section — you can fill them in any order.
        </p>
      </div>
    </div>

    <div class="pf-body">

      <!-- Sidebar progress -->
      <aside class="pf-sidebar">
        <p class="pf-sidebar-title">Your Form</p>
        <ul class="pf-steps">
          <li
            v-for="(skey, idx) in enabledSections"
            :key="skey"
            class="pf-step"
            :class="{
              'step-active':    idx === step,
              'step-done':      completedSections.has(skey),
              'step-pending':   !completedSections.has(skey) && idx !== step,
            }"
            @click="step = idx"
          >
            <span class="pf-step-icon">
              {{ completedSections.has(skey) ? '✓' : SECTION_META[skey]?.icon ?? '📄' }}
            </span>
            <span class="pf-step-label">{{ SECTION_META[skey]?.label ?? skey }}</span>
          </li>
        </ul>
        <div class="pf-progress-bar">
          <div
            class="pf-progress-fill"
            :style="{ width: (completedSections.size / enabledSections.length * 100) + '%' }"
          ></div>
        </div>
        <p class="pf-progress-text">
          {{ completedSections.size }} / {{ enabledSections.length }} completed
        </p>
      </aside>

      <!-- Section content -->
      <main class="pf-content">

        <!-- All done state -->
        <div v-if="allDone" class="pf-all-done">
          <div class="pf-done-icon">🎊</div>
          <h2 class="pf-done-title">All sections complete!</h2>
          <p class="pf-done-sub">
            Thank you, {{ record.first_name }}. HR has received your information and will be in touch soon.
          </p>
        </div>

        <template v-else-if="currentSection">
          <div class="pf-section-header">
            <span class="pf-section-icon">{{ SECTION_META[currentSection]?.icon ?? '📄' }}</span>
            <div>
              <h2 class="pf-section-title">{{ SECTION_META[currentSection]?.label ?? currentSection }}</h2>
              <p class="pf-section-desc">{{ SECTION_META[currentSection]?.desc }}</p>
            </div>
            <span v-if="completedSections.has(currentSection)" class="pf-section-done-badge">Submitted ✓</span>
          </div>

          <!-- ── SELF INTRO ── -->
          <template v-if="currentSection === 'self_intro'">
            {{ initSectionData('self_intro') }}
            <div class="pf-field">
              <label class="pf-label">About Me (background &amp; skills)</label>
              <textarea
                class="pf-textarea"
                rows="4"
                placeholder="Your background, experience, and key skills…"
                :value="String(getField('self_intro','summary'))"
                @input="patchField('self_intro','summary',($event.target as HTMLTextAreaElement).value)"
              ></textarea>
            </div>
            <div class="pf-field">
              <label class="pf-label">Why I joined</label>
              <textarea
                class="pf-textarea"
                rows="3"
                placeholder="What attracted you to this role / organisation…"
                :value="String(getField('self_intro','motivation'))"
                @input="patchField('self_intro','motivation',($event.target as HTMLTextAreaElement).value)"
              ></textarea>
            </div>
            <div class="pf-field">
              <label class="pf-label">Hobbies &amp; Interests</label>
              <textarea
                class="pf-textarea"
                rows="3"
                placeholder="What do you enjoy outside of work…"
                :value="String(getField('self_intro','interests'))"
                @input="patchField('self_intro','interests',($event.target as HTMLTextAreaElement).value)"
              ></textarea>
            </div>
            <div class="pf-field">
              <label class="pf-label">Fun Fact about me</label>
              <input
                class="pf-input"
                type="text"
                placeholder="Something surprising or fun about you…"
                :value="String(getField('self_intro','fun_fact'))"
                @input="patchField('self_intro','fun_fact',($event.target as HTMLInputElement).value)"
              />
            </div>
          </template>

          <!-- ── PERSONAL ── -->
          <template v-else-if="currentSection === 'personal'">
            {{ initSectionData('personal') }}
            <div class="pf-grid-2">
              <div class="pf-field">
                <label class="pf-label">First Name <span class="pf-required">*</span></label>
                <input class="pf-input" type="text" :value="String(getField('personal','first_name'))" @input="patchField('personal','first_name',($event.target as HTMLInputElement).value)" placeholder="First name" />
              </div>
              <div class="pf-field">
                <label class="pf-label">Last Name <span class="pf-required">*</span></label>
                <input class="pf-input" type="text" :value="String(getField('personal','last_name'))" @input="patchField('personal','last_name',($event.target as HTMLInputElement).value)" placeholder="Last name" />
              </div>
              <div class="pf-field">
                <label class="pf-label">Other Names</label>
                <input class="pf-input" type="text" :value="String(getField('personal','other_names'))" @input="patchField('personal','other_names',($event.target as HTMLInputElement).value)" placeholder="Middle name / other names" />
              </div>
              <div class="pf-field">
                <label class="pf-label">Date of Birth <span class="pf-required">*</span></label>
                <input class="pf-input" type="date" :value="String(getField('personal','dob'))" @input="patchField('personal','dob',($event.target as HTMLInputElement).value)" />
              </div>
              <div class="pf-field">
                <label class="pf-label">Gender</label>
                <select class="pf-select" :value="String(getField('personal','gender'))" @change="patchField('personal','gender',($event.target as HTMLSelectElement).value)">
                  <option value="">Select…</option>
                  <option value="male">Male</option>
                  <option value="female">Female</option>
                  <option value="other">Other</option>
                  <option value="prefer_not_to_say">Prefer not to say</option>
                </select>
              </div>
              <div class="pf-field">
                <label class="pf-label">Marital Status</label>
                <select class="pf-select" :value="String(getField('personal','marital_status'))" @change="patchField('personal','marital_status',($event.target as HTMLSelectElement).value)">
                  <option value="">Select…</option>
                  <option value="single">Single</option>
                  <option value="married">Married</option>
                  <option value="divorced">Divorced</option>
                  <option value="widowed">Widowed</option>
                </select>
              </div>

              <!-- Government ID with type toggle -->
              <div class="pf-field pf-full">
                <label class="pf-label">Government ID <span class="pf-required">*</span></label>
                <div class="pf-id-toggle">
                  <button type="button" :class="['pf-id-tab', idType === 'national_id' && 'active']" @click="idType = 'national_id'; idError = ''">National ID</button>
                  <button type="button" :class="['pf-id-tab', idType === 'passport' && 'active']"    @click="idType = 'passport';    idError = ''">Passport</button>
                </div>
                <input class="pf-input" :class="{ 'pf-input-error': idError }" type="text"
                  :value="String(getField('personal','id_number'))"
                  @input="onIdInput(($event.target as HTMLInputElement).value)"
                  :placeholder="idType === 'national_id' ? 'e.g. 12345678' : 'e.g. A1234567'" />
                <span v-if="idError" class="pf-field-error">{{ idError }}</span>
                <span v-else class="pf-field-hint">{{ ID_PATTERNS[idType].hint }}</span>
              </div>

              <!-- KRA PIN -->
              <div class="pf-field">
                <label class="pf-label">KRA PIN <span class="pf-required">*</span></label>
                <input class="pf-input" :class="{ 'pf-input-error': kraPinError }" type="text"
                  :value="String(getField('personal','kra_pin'))"
                  @input="onKraPinInput(($event.target as HTMLInputElement).value)"
                  placeholder="e.g. A012345678P" maxlength="11" />
                <span v-if="kraPinError" class="pf-field-error">{{ kraPinError }}</span>
                <span v-else class="pf-field-hint">Format: A123456789Z</span>
              </div>

              <!-- NSSF -->
              <div class="pf-field">
                <label class="pf-label">NSSF Number</label>
                <input class="pf-input" :class="{ 'pf-input-error': nssfError }" type="text"
                  :value="String(getField('personal','nssf_no'))"
                  @input="onNssfInput(($event.target as HTMLInputElement).value)"
                  placeholder="NSSF number" />
                <span v-if="nssfError" class="pf-field-error">{{ nssfError }}</span>
              </div>

              <!-- NHIF / SHA -->
              <div class="pf-field">
                <label class="pf-label">NHIF / SHA Number</label>
                <input class="pf-input" :class="{ 'pf-input-error': nhifError }" type="text"
                  :value="String(getField('personal','nhif_no'))"
                  @input="onNhifInput(($event.target as HTMLInputElement).value)"
                  placeholder="NHIF / SHA number" />
                <span v-if="nhifError" class="pf-field-error">{{ nhifError }}</span>
              </div>

              <div class="pf-field">
                <label class="pf-label">Nationality</label>
                <input class="pf-input" type="text" :value="String(getField('personal','nationality'))" @input="patchField('personal','nationality',($event.target as HTMLInputElement).value)" placeholder="e.g. Kenyan" />
              </div>
            </div>

            <!-- Required document uploads -->
            <div class="pf-doc-upload-section">
              <p class="pf-subsection-label" style="margin-bottom:14px">Required Documents</p>

              <!-- ID / Passport upload -->
              <div class="pf-doc-row">
                <div class="pf-doc-row-label">
                  <span class="pf-doc-icon">🪪</span>
                  <div>
                    <strong>{{ idType === 'national_id' ? 'National ID' : 'Passport' }} Copy</strong>
                    <span class="pf-required"> *</span>
                    <p class="pf-field-hint">PDF, JPG or PNG · max 2 MB</p>
                  </div>
                </div>
                <div class="pf-doc-row-uploads">
                  <div v-for="(doc, di) in docsForSection('personal', idType)" :key="di" class="pf-doc-chip" :class="{ uploading: doc.uploading, err: doc.error }">
                    <span>{{ doc.uploading ? '⏳' : doc.error ? '⚠️' : '✓' }}</span>
                    <span class="pf-doc-chip-name">{{ doc.name }}</span>
                    <button type="button" class="pf-doc-remove" @click="removeDoc('personal', di)">✕</button>
                  </div>
                  <label v-if="!docsForSection('personal', idType).length" class="pf-doc-upload-label">
                    <input type="file" accept=".pdf,.jpg,.jpeg,.png" class="pf-hidden-file" @change="handleInlineUpload($event, 'personal', idType, MAX_SIZE_DOCS)" />
                    + Upload
                  </label>
                </div>
              </div>

              <!-- KRA PIN certificate upload -->
              <div class="pf-doc-row">
                <div class="pf-doc-row-label">
                  <span class="pf-doc-icon">📋</span>
                  <div>
                    <strong>KRA PIN Certificate</strong>
                    <p class="pf-field-hint">PDF, JPG or PNG · max 2 MB</p>
                  </div>
                </div>
                <div class="pf-doc-row-uploads">
                  <div v-for="(doc, di) in docsForSection('personal', 'kra_pin')" :key="di" class="pf-doc-chip" :class="{ uploading: doc.uploading, err: doc.error }">
                    <span>{{ doc.uploading ? '⏳' : doc.error ? '⚠️' : '✓' }}</span>
                    <span class="pf-doc-chip-name">{{ doc.name }}</span>
                    <button type="button" class="pf-doc-remove" @click="removeDoc('personal', di)">✕</button>
                  </div>
                  <label class="pf-doc-upload-label">
                    <input type="file" accept=".pdf,.jpg,.jpeg,.png" class="pf-hidden-file" @change="handleInlineUpload($event, 'personal', 'kra_pin', MAX_SIZE_DOCS)" />
                    + Upload
                  </label>
                </div>
              </div>
            </div>
          </template>

          <!-- ── CONTACT ── -->
          <template v-else-if="currentSection === 'contact'">
            {{ initSectionData('contact') }}
            <div class="pf-grid-2">
              <div class="pf-field pf-full">
                <label class="pf-label">Physical / Postal Address</label>
                <textarea class="pf-textarea" rows="2" :value="String(getField('contact','address'))" @input="patchField('contact','address',($event.target as HTMLTextAreaElement).value)" placeholder="Street, city, county"></textarea>
              </div>
              <div class="pf-field">
                <label class="pf-label">Personal Email</label>
                <input class="pf-input" type="email" :value="String(getField('contact','personal_email'))" @input="patchField('contact','personal_email',($event.target as HTMLInputElement).value)" placeholder="personal@email.com" />
              </div>
              <div class="pf-field">
                <label class="pf-label">Phone (Primary)</label>
                <input class="pf-input" type="tel" :value="String(getField('contact','phone_primary'))" @input="patchField('contact','phone_primary',($event.target as HTMLInputElement).value)" placeholder="+254 7xx xxx xxx" />
              </div>
              <div class="pf-field">
                <label class="pf-label">Phone (Secondary)</label>
                <input class="pf-input" type="tel" :value="String(getField('contact','phone_secondary'))" @input="patchField('contact','phone_secondary',($event.target as HTMLInputElement).value)" placeholder="Optional" />
              </div>
              <div class="pf-field">
                <label class="pf-label">City / Town</label>
                <input class="pf-input" type="text" :value="String(getField('contact','city'))" @input="patchField('contact','city',($event.target as HTMLInputElement).value)" placeholder="Nairobi" />
              </div>
              <div class="pf-field">
                <label class="pf-label">County / Region</label>
                <input class="pf-input" type="text" :value="String(getField('contact','county'))" @input="patchField('contact','county',($event.target as HTMLInputElement).value)" placeholder="e.g. Nairobi County" />
              </div>
              <div class="pf-field pf-full">
                <label class="pf-label">Country of Residence <span class="pf-required">*</span></label>
                <select
                  class="pf-select"
                  :value="String(getField('contact','country_of_residence'))"
                  @change="onResidenceCountryChange(($event.target as HTMLSelectElement).value)"
                >
                  <option value="">Select country…</option>
                  <option value="KE">Kenya</option>
                  <option value="UG">Uganda</option>
                  <option value="TZ">Tanzania</option>
                  <option value="RW">Rwanda</option>
                  <option value="ET">Ethiopia</option>
                  <option value="NG">Nigeria</option>
                  <option value="ZA">South Africa</option>
                  <option value="GH">Ghana</option>
                  <option value="GB">United Kingdom</option>
                  <option value="US">United States</option>
                  <option value="CA">Canada</option>
                  <option value="AU">Australia</option>
                  <option value="IN">India</option>
                  <option value="CN">China</option>
                  <option value="DE">Germany</option>
                  <option value="FR">France</option>
                  <option value="AE">United Arab Emirates</option>
                  <option value="OTHER">Other</option>
                </select>
                <span class="pf-field-hint">This determines whether immigration details are required.</span>
              </div>
            </div>
          </template>

          <!-- ── EMERGENCY ── -->
          <template v-else-if="currentSection === 'emergency'">
            {{ initSectionData('emergency') }}

            <!-- Your health info -->
            <p class="pf-subsection-label">Your Health Information</p>
            <div class="pf-grid-2">
              <div class="pf-field">
                <label class="pf-label">Blood Group</label>
                <select class="pf-select" :value="String(getField('emergency','blood_group'))" @change="patchField('emergency','blood_group',($event.target as HTMLSelectElement).value)">
                  <option value="">Select…</option>
                  <option v-for="bg in ['A+','A-','B+','B-','AB+','AB-','O+','O-']" :key="bg" :value="bg">{{ bg }}</option>
                </select>
              </div>
              <div class="pf-field">
                <label class="pf-label">Any Disability?</label>
                <select class="pf-select" :value="String(getField('emergency','disability'))" @change="patchField('emergency','disability',($event.target as HTMLSelectElement).value)">
                  <option value="">Select…</option>
                  <option value="none">None</option>
                  <option value="physical">Physical</option>
                  <option value="visual">Visual</option>
                  <option value="hearing">Hearing</option>
                  <option value="cognitive">Cognitive / Learning</option>
                  <option value="mental_health">Mental Health</option>
                  <option value="other">Other</option>
                  <option value="prefer_not_to_say">Prefer not to say</option>
                </select>
              </div>
              <div class="pf-field pf-full">
                <label class="pf-label">Known Allergies</label>
                <textarea
                  class="pf-textarea"
                  rows="2"
                  :value="String(getField('emergency','allergies'))"
                  @input="patchField('emergency','allergies',($event.target as HTMLTextAreaElement).value)"
                  placeholder="List any known allergies (food, medication, environmental) or write 'None'"
                ></textarea>
              </div>
            </div>

            <!-- Emergency contact -->
            <p class="pf-subsection-label" style="margin-top:20px">Emergency Contact</p>
            <div class="pf-grid-2">
              <div class="pf-field">
                <label class="pf-label">Contact Full Name</label>
                <input class="pf-input" type="text" :value="String(getField('emergency','name'))" @input="patchField('emergency','name',($event.target as HTMLInputElement).value)" placeholder="Full name" />
              </div>
              <div class="pf-field">
                <label class="pf-label">Relationship</label>
                <select class="pf-select" :value="String(getField('emergency','relationship'))" @change="patchField('emergency','relationship',($event.target as HTMLSelectElement).value)">
                  <option value="">Select…</option>
                  <option value="spouse">Spouse</option>
                  <option value="parent">Parent</option>
                  <option value="sibling">Sibling</option>
                  <option value="child">Child</option>
                  <option value="friend">Friend</option>
                  <option value="other">Other</option>
                </select>
              </div>
              <div class="pf-field">
                <label class="pf-label">Phone (Primary)</label>
                <input class="pf-input" type="tel" :value="String(getField('emergency','phone_primary'))" @input="patchField('emergency','phone_primary',($event.target as HTMLInputElement).value)" placeholder="+254 7xx xxx xxx" />
              </div>
              <div class="pf-field">
                <label class="pf-label">Phone (Secondary)</label>
                <input class="pf-input" type="tel" :value="String(getField('emergency','phone_secondary'))" @input="patchField('emergency','phone_secondary',($event.target as HTMLInputElement).value)" placeholder="Optional" />
              </div>
              <div class="pf-field pf-full">
                <label class="pf-label">Address</label>
                <input class="pf-input" type="text" :value="String(getField('emergency','address'))" @input="patchField('emergency','address',($event.target as HTMLInputElement).value)" placeholder="Contact's address (optional)" />
              </div>
              <div class="pf-field pf-full">
                <label class="pf-label">Preferred Contact Method</label>
                <div class="pf-radio-group">
                  <label v-for="opt in ['Phone Call','SMS','WhatsApp','Email']" :key="opt" class="pf-radio-label">
                    <input
                      type="radio"
                      :value="opt"
                      :checked="getField('emergency','preferred_contact') === opt"
                      @change="patchField('emergency','preferred_contact', opt)"
                    /> {{ opt }}
                  </label>
                </div>
              </div>
            </div>
          </template>

          <!-- ── DEPENDENTS ── -->
          <template v-else-if="currentSection === 'dependents'">
            {{ initSectionData('dependents') }}
            <p class="pf-helper-text">Add any dependents (spouse, children) who will be covered under company benefits.</p>

            <div v-for="(dep, i) in getDependents()" :key="i" class="pf-list-card">
              <div class="pf-list-card-header">
                <strong>Dependent {{ i + 1 }}</strong>
                <button class="pf-remove-btn" @click="removeDependent(i)" type="button">✕ Remove</button>
              </div>
              <div class="pf-grid-3">
                <div class="pf-field">
                  <label class="pf-label">Full Name</label>
                  <input class="pf-input" type="text" v-model="dep.name" placeholder="Full name" />
                </div>
                <div class="pf-field">
                  <label class="pf-label">Relationship</label>
                  <select class="pf-select" v-model="dep.relationship">
                    <option value="">Select…</option>
                    <option value="spouse">Spouse</option>
                    <option value="child">Child</option>
                    <option value="parent">Parent</option>
                    <option value="sibling">Sibling</option>
                    <option value="other">Other</option>
                  </select>
                </div>
                <div class="pf-field">
                  <label class="pf-label">Date of Birth</label>
                  <input class="pf-input" type="date" v-model="dep.dob" />
                </div>
              </div>

              <!-- ── Evidence upload (spouse) ── -->
              <template v-if="dep.relationship === 'spouse'">
                <div class="pf-dep-evidence">
                  <div class="pf-dep-evidence-header">
                    <span class="pf-dep-evidence-icon">💍</span>
                    <div>
                      <p class="pf-dep-evidence-title">
                        Marriage Certificate <em>or</em> Affidavit
                        <span class="pf-required"> *</span>
                      </p>
                      <p class="pf-dep-evidence-hint">PDF, JPG or PNG · max 2 MB per file</p>
                    </div>
                  </div>
                  <div class="pf-dep-evidence-uploads">
                    <div
                      v-for="(doc, di) in depDocs(i, 'marriage')"
                      :key="di"
                      class="pf-doc-chip"
                      :class="{ uploading: doc.uploading, err: doc.error }"
                    >
                      <span>{{ doc.uploading ? '⏳' : doc.error ? '⚠️' : '✓' }}</span>
                      <span class="pf-doc-chip-name" :title="doc.name">{{ doc.name }}</span>
                      <button type="button" class="pf-doc-remove" @click="removeDepDoc(i, 'marriage', di)">✕</button>
                    </div>
                    <label class="pf-doc-upload-label">
                      <input
                        type="file"
                        accept=".pdf,.jpg,.jpeg,.png"
                        class="pf-hidden-file"
                        @change="handleInlineUpload($event, 'dependents', `dep_${i}_marriage`, MAX_SIZE_DOCS)"
                      />
                      <svg viewBox="0 0 16 16" fill="currentColor" width="12" height="12"><path d="M8 1a.5.5 0 0 1 .5.5v5h5a.5.5 0 0 1 0 1h-5v5a.5.5 0 0 1-1 0v-5h-5a.5.5 0 0 1 0-1h5v-5A.5.5 0 0 1 8 1z"/></svg>
                      Upload
                    </label>
                  </div>
                </div>
              </template>

              <!-- ── Evidence upload (child) ── -->
              <template v-else-if="dep.relationship === 'child'">
                <div class="pf-dep-evidence">
                  <div class="pf-dep-evidence-header">
                    <span class="pf-dep-evidence-icon">👶</span>
                    <div>
                      <p class="pf-dep-evidence-title">
                        Birth Certificate <em>or</em> Adoption Certificate
                        <span class="pf-required"> *</span>
                      </p>
                      <p class="pf-dep-evidence-hint">PDF, JPG or PNG · max 2 MB per file</p>
                    </div>
                  </div>
                  <div class="pf-dep-evidence-uploads">
                    <div
                      v-for="(doc, di) in depDocs(i, 'birth')"
                      :key="di"
                      class="pf-doc-chip"
                      :class="{ uploading: doc.uploading, err: doc.error }"
                    >
                      <span>{{ doc.uploading ? '⏳' : doc.error ? '⚠️' : '✓' }}</span>
                      <span class="pf-doc-chip-name" :title="doc.name">{{ doc.name }}</span>
                      <button type="button" class="pf-doc-remove" @click="removeDepDoc(i, 'birth', di)">✕</button>
                    </div>
                    <label class="pf-doc-upload-label">
                      <input
                        type="file"
                        accept=".pdf,.jpg,.jpeg,.png"
                        class="pf-hidden-file"
                        @change="handleInlineUpload($event, 'dependents', `dep_${i}_birth`, MAX_SIZE_DOCS)"
                      />
                      <svg viewBox="0 0 16 16" fill="currentColor" width="12" height="12"><path d="M8 1a.5.5 0 0 1 .5.5v5h5a.5.5 0 0 1 0 1h-5v5a.5.5 0 0 1-1 0v-5h-5a.5.5 0 0 1 0-1h5v-5A.5.5 0 0 1 8 1z"/></svg>
                      Upload
                    </label>
                  </div>
                </div>
              </template>

            </div>

            <button class="pf-add-btn" @click="addDependent" type="button">+ Add Dependent</button>
          </template>

          <!-- ── IMMIGRATION ── -->
          <template v-else-if="currentSection === 'immigration'">
            {{ initSectionData('immigration') }}

            <!-- Not a foreign resident: skip option -->
            <template v-if="!isForeignResident && getField('contact','country_of_residence')">
              <div class="pf-imm-skip-banner">
                <span class="pf-imm-skip-icon">✅</span>
                <div>
                  <p class="pf-imm-skip-title">No immigration details required</p>
                  <p class="pf-imm-skip-sub">Your country of residence matches the company’s location. You can skip this section.</p>
                </div>
                <button class="pf-btn-secondary" type="button" @click="submitSection('immigration')" :disabled="saving">
                  {{ saving ? 'Saving…' : 'Skip this section →' }}
                </button>
              </div>
            </template>

            <!-- Country not yet filled in: prompt -->
            <template v-else-if="!getField('contact','country_of_residence')">
              <div class="pf-imm-skip-banner pf-imm-warn">
                <span class="pf-imm-skip-icon">ℹ️</span>
                <div>
                  <p class="pf-imm-skip-title">Country of residence not set</p>
                  <p class="pf-imm-skip-sub">Please complete the Contact Information section first so we can determine whether immigration details are required.</p>
                </div>
              </div>
            </template>

            <!-- Foreign resident: all fields required -->
            <template v-else>
              <div class="pf-imm-required-banner">
                ⚠️ Immigration details are <strong>required</strong> because your country of residence differs from the company’s country.
              </div>
              <div class="pf-grid-2">
                <div class="pf-field">
                  <label class="pf-label">Passport Number <span class="pf-required">*</span></label>
                  <input class="pf-input" type="text"
                    :value="String(getField('immigration','passport_no'))"
                    @input="patchField('immigration','passport_no',($event.target as HTMLInputElement).value)"
                    placeholder="e.g. A1234567" required />
                </div>
                <div class="pf-field">
                  <label class="pf-label">Passport Expiry <span class="pf-required">*</span></label>
                  <input class="pf-input" type="date"
                    :value="String(getField('immigration','passport_expiry'))"
                    @input="patchField('immigration','passport_expiry',($event.target as HTMLInputElement).value)"
                    required />
                </div>
                <div class="pf-field">
                  <label class="pf-label">Country of Issue <span class="pf-required">*</span></label>
                  <input class="pf-input" type="text"
                    :value="String(getField('immigration','passport_country'))"
                    @input="patchField('immigration','passport_country',($event.target as HTMLInputElement).value)"
                    placeholder="Country that issued the passport" required />
                </div>
                <div class="pf-field">
                  <label class="pf-label">Work Permit Number <span class="pf-required">*</span></label>
                  <input class="pf-input" type="text"
                    :value="String(getField('immigration','work_permit_no'))"
                    @input="patchField('immigration','work_permit_no',($event.target as HTMLInputElement).value)"
                    placeholder="Work permit / pass number" required />
                </div>
                <div class="pf-field">
                  <label class="pf-label">Work Permit Expiry <span class="pf-required">*</span></label>
                  <input class="pf-input" type="date"
                    :value="String(getField('immigration','work_permit_expiry'))"
                    @input="patchField('immigration','work_permit_expiry',($event.target as HTMLInputElement).value)"
                    required />
                </div>
                <div class="pf-field">
                  <label class="pf-label">Visa Type</label>
                  <input class="pf-input" type="text"
                    :value="String(getField('immigration','visa_type'))"
                    @input="patchField('immigration','visa_type',($event.target as HTMLInputElement).value)"
                    placeholder="e.g. Work Visa (optional)" />
                </div>
                <div class="pf-field">
                  <label class="pf-label">Visa Expiry</label>
                  <input class="pf-input" type="date"
                    :value="String(getField('immigration','visa_expiry'))"
                    @input="patchField('immigration','visa_expiry',($event.target as HTMLInputElement).value)" />
                </div>
              </div>
            </template>
          </template>

          <!-- ── QUALIFICATIONS ── -->
          <template v-else-if="currentSection === 'qualifications'">
            {{ initSectionData('qualifications') }}
            <p class="pf-helper-text">List your educational qualifications, professional certifications, and awards. Upload a supporting document for each entry.</p>
            <div v-for="(q, i) in getQualifications()" :key="i" class="pf-list-card">
              <div class="pf-list-card-header">
                <strong>Entry {{ i + 1 }}</strong>
                <button class="pf-remove-btn" @click="removeQualification(i)" type="button">✕ Remove</button>
              </div>
              <div class="pf-grid-2">
                <div class="pf-field pf-full">
                  <label class="pf-label">Type <span class="pf-required">*</span></label>
                  <select class="pf-select" v-model="q.type">
                    <option value="">Select type…</option>
                    <option v-for="t in QUAL_TYPES" :key="t" :value="t">{{ t }}</option>
                  </select>
                </div>
                <div class="pf-field">
                  <label class="pf-label">Institution / Issuing Body</label>
                  <input class="pf-input" type="text" v-model="q.institution" placeholder="University / College / Organisation" />
                </div>
                <div class="pf-field">
                  <label class="pf-label">Qualification / Award Name</label>
                  <input class="pf-input" type="text" v-model="q.qualification" placeholder="e.g. Bachelor of Science in IT" />
                </div>
                <div class="pf-field">
                  <label class="pf-label">Year Obtained</label>
                  <input class="pf-input" type="number" v-model="q.year" placeholder="2020" min="1970" :max="new Date().getFullYear()" />
                </div>
                <div class="pf-field">
                  <label class="pf-label">Grade / Class</label>
                  <input class="pf-input" type="text" v-model="q.grade" placeholder="e.g. First Class, B+" />
                </div>
              </div>

              <!-- Per-qualification document upload -->
              <div style="margin-top:14px">
                <label class="pf-label" style="margin-bottom:6px">Supporting Document <span class="pf-required">*</span></label>
                <p class="pf-field-hint" style="margin-bottom:8px">Certificate, transcript, or award letter · PDF, JPG or PNG · max 2 MB</p>
                <div v-if="docsForSection('qualifications', `qual_doc_${i}`).length" style="display:flex;flex-direction:column;gap:6px">
                  <div v-for="(doc, di) in docsForSection('qualifications', `qual_doc_${i}`)" :key="di"
                       class="pf-doc-chip" :class="{ uploading: doc.uploading, err: doc.error }">
                    <span>{{ doc.uploading ? '⏳' : doc.error ? '⚠️' : '✓' }}</span>
                    <span class="pf-doc-chip-name">{{ doc.name }}</span>
                    <button type="button" class="pf-doc-remove" @click="removeDoc('qualifications', (sectionUploads['qualifications']||[]).indexOf(doc))">✕</button>
                  </div>
                </div>
                <label v-else class="pf-doc-upload-label">
                  <input type="file" accept=".pdf,.jpg,.jpeg,.png" class="pf-hidden-file"
                    @change="handleInlineUpload($event, 'qualifications', `qual_doc_${i}`, MAX_SIZE_DOCS)" />
                  + Upload Document
                </label>
              </div>
            </div>
            <button class="pf-add-btn" @click="addQualification" type="button">+ Add Qualification / Award</button>
          </template>

          <!-- ── MEMBERSHIPS ── -->
          <template v-else-if="currentSection === 'memberships'">
            {{ initSectionData('memberships') }}
            <p class="pf-helper-text">List any professional body memberships.</p>
            <div v-for="(m, i) in getMemberships()" :key="i" class="pf-list-card">
              <div class="pf-list-card-header">
                <strong>Membership {{ i + 1 }}</strong>
                <button class="pf-remove-btn" @click="removeMembership(i)" type="button">✕ Remove</button>
              </div>
              <div class="pf-grid-3">
                <div class="pf-field">
                  <label class="pf-label">Professional Body</label>
                  <input class="pf-input" type="text" v-model="m.body" placeholder="e.g. ICPAK, LSK, EBK" />
                </div>
                <div class="pf-field">
                  <label class="pf-label">Membership Number</label>
                  <input class="pf-input" type="text" v-model="m.membership_no" placeholder="Member no." />
                </div>
                <div class="pf-field">
                  <label class="pf-label">Valid Until</label>
                  <input class="pf-input" type="date" v-model="m.valid_until" />
                </div>
              </div>
            </div>
            <button class="pf-add-btn" @click="addMembership" type="button">+ Add Membership</button>
          </template>

          <!-- ── ATTACHMENTS ── -->
          <template v-else-if="currentSection === 'attachments'">
            <p class="pf-helper-text">Upload required documents (ID, certificates, etc.). Each file must be PDF, JPG, PNG, or Word format, max 10 MB.</p>
            <div v-if="attachUploaded.length" class="pf-upload-list">
              <div v-for="(f, i) in attachUploaded" :key="i" class="pf-upload-item">
                <span class="pf-upload-icon">📎</span>
                <div>
                  <div class="pf-upload-name">{{ f.name }}</div>
                  <div class="pf-upload-cat">{{ f.category }}</div>
                </div>
                <span class="pf-upload-ok">✓</span>
              </div>
            </div>
            <div class="pf-upload-form">
              <div class="pf-field">
                <label class="pf-label">Document Category</label>
                <select class="pf-select" v-model="attachCategory">
                  <option value="">Select category…</option>
                  <option value="national_id">National ID</option>
                  <option value="passport">Passport</option>
                  <option value="academic_certificate">Academic Certificate</option>
                  <option value="professional_certificate">Professional Certificate</option>
                  <option value="kra_pin">KRA PIN Certificate</option>
                  <option value="nssf">NSSF Card</option>
                  <option value="nhif">NHIF / SHA Card</option>
                  <option value="work_permit">Work Permit</option>
                  <option value="photo">Passport Photo</option>
                  <option value="other">Other</option>
                </select>
              </div>
              <div class="pf-field">
                <label class="pf-label">File</label>
                <input id="attach-file" class="pf-input" type="file"
                  accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx"
                  @change="handleFileChange" />
              </div>
              <p v-if="attachErr" class="pf-save-error">{{ attachErr }}</p>
              <button
                class="pf-upload-btn"
                :disabled="!attachFile || !attachCategory || attachUploading"
                @click="uploadAttachment"
                type="button"
              >
                {{ attachUploading ? 'Uploading…' : 'Upload Document' }}
              </button>
            </div>
            <p class="pf-helper-text" style="margin-top: 12px;">You can upload multiple documents. Mark this section as done when finished.</p>
          </template>

          <!-- ── Save/Next bar ── -->
          <div
            v-if="!(currentSection === 'immigration' && !isForeignResident && getField('contact','country_of_residence'))"
            class="pf-save-bar"
          >
            <p v-if="saveErr" class="pf-save-error">{{ saveErr }}</p>
            <div class="pf-save-actions">
              <button v-if="step > 0" class="pf-btn-secondary" @click="step--" type="button">← Previous</button>
              <button class="pf-btn-primary" :disabled="saving" @click="validateAndSubmit(currentSection!)" type="button">
                {{ saving ? 'Saving…' : completedSections.has(currentSection!) ? 'Update Section ✓' : 'Save & Continue →' }}
              </button>
            </div>
          </div>
        </template>

      </main>
    </div>

    <!-- Footer -->
    <footer class="pf-footer">
      <p>Secure form — your data is encrypted in transit. &nbsp;|&nbsp; Need help? Contact your HR team.</p>
    </footer>
  </div>
</template>

<style scoped>
/* ── Reset / base ─────────────────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

.pf-page {
  min-height: 100vh;
  background: #f1f5f9;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
  color: #1e293b;
  display: flex;
  flex-direction: column;
}

/* ── Loading ─────────────────────────────────────────────────────────────── */
.pf-loading {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 16px;
  color: #64748b;
  font-size: 15px;
  background: #f1f5f9;
}
.pf-spinner {
  width: 36px; height: 36px;
  border: 3px solid #e2e8f0;
  border-top-color: #6366f1;
  border-radius: 50%;
  animation: spin 0.7s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Error page ───────────────────────────────────────────────────────────── */
.pf-error-page {
  min-height: 100vh;
  background: #f1f5f9;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
}
.pf-error-card {
  background: #fff;
  border-radius: 16px;
  padding: 48px 40px;
  max-width: 480px;
  text-align: center;
  box-shadow: 0 4px 24px rgba(0,0,0,.08);
}
.pf-error-icon { font-size: 56px; margin-bottom: 20px; }
.pf-error-title { font-size: 22px; font-weight: 700; margin-bottom: 12px; }
.pf-error-msg { font-size: 15px; color: #64748b; line-height: 1.6; }

/* ── Header ───────────────────────────────────────────────────────────────── */
.pf-header {
  background: #fff;
  border-bottom: 1px solid #e2e8f0;
  position: sticky; top: 0; z-index: 10;
}
.pf-header-inner {
  max-width: 1100px;
  margin: 0 auto;
  padding: 0 24px;
  height: 56px;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.pf-logo {
  font-weight: 700;
  font-size: 18px;
  color: #4f46e5;
  letter-spacing: -0.3px;
  display: flex;
  align-items: center;
}
.pf-logo-img {
  height: 36px;
  width: auto;
  object-fit: contain;
}
.pf-expiry-badge {
  font-size: 12px;
  font-weight: 600;
  background: #d1fae5;
  color: #065f46;
  padding: 3px 10px;
  border-radius: 20px;
}
.pf-expiry-badge.expired {
  background: #fee2e2;
  color: #b91c1c;
}

/* ── Welcome banner ───────────────────────────────────────────────────────── */
.pf-welcome {
  background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
  padding: 36px 0;
  color: #fff;
}
.pf-welcome-inner {
  max-width: 1100px;
  margin: 0 auto;
  padding: 0 24px;
}
.pf-welcome-title {
  font-size: 28px;
  font-weight: 800;
  margin-bottom: 10px;
}
.pf-welcome-sub {
  font-size: 15px;
  opacity: 0.92;
  line-height: 1.6;
  margin-bottom: 8px;
}
.pf-welcome-note {
  font-size: 13px;
  opacity: 0.75;
}

/* ── Body layout ──────────────────────────────────────────────────────────── */
.pf-body {
  flex: 1;
  max-width: 1100px;
  width: 100%;
  margin: 32px auto;
  padding: 0 24px;
  display: grid;
  grid-template-columns: 240px 1fr;
  gap: 24px;
  align-items: start;
}
@media (max-width: 760px) {
  .pf-body { grid-template-columns: 1fr; }
  .pf-sidebar { order: 2; }
  .pf-content { order: 1; }
}

/* ── Sidebar ──────────────────────────────────────────────────────────────── */
.pf-sidebar {
  background: #fff;
  border-radius: 12px;
  padding: 20px;
  box-shadow: 0 1px 6px rgba(0,0,0,.06);
  position: sticky;
  top: 72px;
}
.pf-sidebar-title {
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: #94a3b8;
  margin-bottom: 12px;
}
.pf-steps {
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin-bottom: 16px;
}
.pf-step {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 10px;
  border-radius: 8px;
  cursor: pointer;
  font-size: 13px;
  transition: background 0.15s;
}
.pf-step:hover { background: #f8fafc; }
.pf-step.step-active {
  background: #ede9fe;
  color: #5b21b6;
  font-weight: 600;
}
.pf-step.step-done { color: #16a34a; }
.pf-step.step-pending { color: #94a3b8; }
.pf-step-icon { font-size: 16px; flex-shrink: 0; }
.pf-step-label { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pf-progress-bar {
  height: 4px;
  background: #e2e8f0;
  border-radius: 2px;
  overflow: hidden;
  margin-bottom: 6px;
}
.pf-progress-fill {
  height: 100%;
  background: #6366f1;
  border-radius: 2px;
  transition: width 0.4s ease;
}
.pf-progress-text {
  font-size: 12px;
  color: #64748b;
  text-align: right;
}

/* ── Content panel ────────────────────────────────────────────────────────── */
.pf-content {
  background: #fff;
  border-radius: 12px;
  padding: 28px 32px;
  box-shadow: 0 1px 6px rgba(0,0,0,.06);
}
.pf-section-header {
  display: flex;
  align-items: flex-start;
  gap: 14px;
  margin-bottom: 24px;
  padding-bottom: 20px;
  border-bottom: 1px solid #f1f5f9;
}
.pf-section-icon { font-size: 32px; flex-shrink: 0; margin-top: 2px; }
.pf-section-title { font-size: 20px; font-weight: 700; margin-bottom: 4px; }
.pf-section-desc { font-size: 14px; color: #64748b; line-height: 1.5; }
.pf-section-done-badge {
  margin-left: auto;
  flex-shrink: 0;
  font-size: 12px;
  font-weight: 600;
  background: #d1fae5;
  color: #065f46;
  padding: 4px 10px;
  border-radius: 20px;
  white-space: nowrap;
}

/* ── Form fields ──────────────────────────────────────────────────────────── */
.pf-grid-2 {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
}
.pf-grid-3 {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap: 16px;
}
@media (max-width: 600px) {
  .pf-grid-2, .pf-grid-3 { grid-template-columns: 1fr; }
}
.pf-field { display: flex; flex-direction: column; gap: 5px; }
.pf-full { grid-column: 1 / -1; }
.pf-label { font-size: 13px; font-weight: 600; color: #374151; }
.pf-input, .pf-select, .pf-textarea {
  border: 1px solid #d1d5db;
  border-radius: 8px;
  padding: 9px 12px;
  font-size: 14px;
  font-family: inherit;
  color: #1e293b;
  background: #fff;
  transition: border-color 0.15s, box-shadow 0.15s;
  width: 100%;
}
.pf-input:focus, .pf-select:focus, .pf-textarea:focus {
  outline: none;
  border-color: #6366f1;
  box-shadow: 0 0 0 3px rgba(99,102,241,.12);
}
.pf-textarea { resize: vertical; }

/* ── Helper text ──────────────────────────────────────────────────────────── */
.pf-helper-text {
  font-size: 14px;
  color: #64748b;
  margin-bottom: 16px;
  line-height: 1.5;
}

/* ── List cards (dependents, quals, memberships) ─────────────────────────── */
.pf-list-card {
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 16px;
  margin-bottom: 12px;
  background: #f8fafc;
}
.pf-list-card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
  font-size: 13px;
  color: #374151;
}
.pf-remove-btn {
  background: none;
  border: none;
  font-size: 12px;
  color: #ef4444;
  cursor: pointer;
  padding: 4px 8px;
  border-radius: 6px;
}
.pf-remove-btn:hover { background: #fee2e2; }
.pf-add-btn {
  background: none;
  border: 1px dashed #6366f1;
  color: #4f46e5;
  font-size: 13px;
  font-weight: 600;
  padding: 8px 16px;
  border-radius: 8px;
  cursor: pointer;
  margin-top: 4px;
  transition: background 0.15s;
}
.pf-add-btn:hover { background: #ede9fe; }

/* ── Attachments ──────────────────────────────────────────────────────────── */
.pf-upload-list { margin-bottom: 20px; display: flex; flex-direction: column; gap: 8px; }
.pf-upload-item {
  display: flex;
  align-items: center;
  gap: 12px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 8px;
  padding: 10px 14px;
}
.pf-upload-icon { font-size: 20px; }
.pf-upload-name { font-size: 13px; font-weight: 600; }
.pf-upload-cat  { font-size: 12px; color: #64748b; }
.pf-upload-ok   { margin-left: auto; color: #16a34a; font-weight: 700; }
.pf-upload-form {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 18px;
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.pf-upload-btn {
  background: #4f46e5;
  color: #fff;
  border: none;
  border-radius: 8px;
  padding: 10px 20px;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  align-self: flex-start;
  transition: background 0.15s;
}
.pf-upload-btn:disabled { background: #a5b4fc; cursor: not-allowed; }
.pf-upload-btn:not(:disabled):hover { background: #4338ca; }

/* ── Save bar ─────────────────────────────────────────────────────────────── */
.pf-save-bar {
  margin-top: 28px;
  padding-top: 20px;
  border-top: 1px solid #f1f5f9;
}
.pf-save-error {
  font-size: 13px;
  color: #dc2626;
  background: #fef2f2;
  border: 1px solid #fecaca;
  border-radius: 6px;
  padding: 8px 12px;
  margin-bottom: 12px;
}
.pf-save-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
}
.pf-btn-primary {
  background: #4f46e5;
  color: #fff;
  border: none;
  border-radius: 8px;
  padding: 10px 24px;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.15s;
}
.pf-btn-primary:disabled { background: #a5b4fc; cursor: not-allowed; }
.pf-btn-primary:not(:disabled):hover { background: #4338ca; }
.pf-btn-secondary {
  background: #f1f5f9;
  color: #374151;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  padding: 10px 20px;
  font-size: 14px;
  font-weight: 500;
  cursor: pointer;
  transition: background 0.15s;
}
.pf-btn-secondary:hover { background: #e2e8f0; }

/* ── All done ─────────────────────────────────────────────────────────────── */
.pf-all-done {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  padding: 40px 20px;
}
.pf-done-icon  { font-size: 64px; margin-bottom: 20px; }
.pf-done-title { font-size: 24px; font-weight: 800; color: #16a34a; margin-bottom: 12px; }
.pf-done-sub   { font-size: 15px; color: #64748b; max-width: 440px; line-height: 1.6; }

/* ── Footer ───────────────────────────────────────────────────────────────── */
.pf-footer {
  border-top: 1px solid #e2e8f0;
  padding: 16px 24px;
  text-align: center;
  font-size: 12px;
  color: #94a3b8;
  background: #fff;
}

/* ── Required asterisk ────────────────────────────────────────────────────── */
.pf-required { color: #ef4444; margin-left: 2px; }

/* ── Immigration skip/required banners ───────────────────────────────────── */
.pf-imm-skip-banner {
  display: flex;
  align-items: flex-start;
  gap: 14px;
  background: #f0fdf4;
  border: 1px solid #86efac;
  border-radius: 12px;
  padding: 18px 20px;
  margin-bottom: 24px;
}
.pf-imm-skip-banner.pf-imm-warn {
  background: #fffbeb;
  border-color: #fcd34d;
}
.pf-imm-skip-icon { font-size: 24px; flex-shrink: 0; margin-top: 2px; }
.pf-imm-skip-title { font-weight: 700; font-size: 14px; color: #14532d; margin-bottom: 4px; }
.pf-imm-skip-banner.pf-imm-warn .pf-imm-skip-title { color: #78350f; }
.pf-imm-skip-sub  { font-size: 13px; color: #166534; line-height: 1.5; }
.pf-imm-skip-banner.pf-imm-warn .pf-imm-skip-sub { color: #92400e; }
.pf-imm-skip-banner .pf-btn-secondary { margin-left: auto; flex-shrink: 0; white-space: nowrap; }
.pf-imm-required-banner {
  background: #fef9c3;
  border: 1px solid #fde047;
  border-radius: 8px;
  padding: 12px 16px;
  font-size: 13px;
  color: #713f12;
  margin-bottom: 20px;
  line-height: 1.5;
}

/* ── ID type toggle ───────────────────────────────────────────────────────── */
.pf-id-toggle {
  display: flex;
  gap: 0;
  margin-bottom: 8px;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  overflow: hidden;
  width: fit-content;
}
.pf-id-tab {
  background: #fff;
  border: none;
  padding: 7px 18px;
  font-size: 13px;
  font-weight: 500;
  color: #64748b;
  cursor: pointer;
  transition: background 0.12s, color 0.12s;
}
.pf-id-tab:first-child { border-right: 1px solid #d1d5db; }
.pf-id-tab.active {
  background: #4f46e5;
  color: #fff;
  font-weight: 600;
}
.pf-input-error { border-color: #ef4444 !important; }
.pf-field-error { font-size: 12px; color: #dc2626; margin-top: 3px; }
.pf-field-hint  { font-size: 12px; color: #94a3b8; margin-top: 3px; }

/* ── Subsection label ─────────────────────────────────────────────────────── */
.pf-subsection-label {
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: #94a3b8;
  margin-bottom: 12px;
  padding-bottom: 6px;
  border-bottom: 1px solid #f1f5f9;
  grid-column: 1 / -1;
}

/* ── Radio group ──────────────────────────────────────────────────────────── */
.pf-radio-group {
  display: flex;
  flex-wrap: wrap;
  gap: 16px;
  margin-top: 4px;
}
.pf-radio-label {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 14px;
  color: #374151;
  cursor: pointer;
}
/* ── Logo image ───────────────────────────────────────────────────────────── */
.pf-logo-img { height: 40px; max-width: 160px; object-fit: contain; }

/* ── Autosave indicator ───────────────────────────────────────────────────── */
.pf-autosave {
  font-size: 12px;
  font-weight: 600;
  padding: 3px 10px;
  border-radius: 20px;
}
.pf-autosave.saving { background: #eff6ff; color: #1d4ed8; }
.pf-autosave.saved  { background: #f0fdf4; color: #15803d; }
.pf-autosave.err    { background: #fef2f2; color: #b91c1c; }

/* ── Inline doc upload widgets ────────────────────────────────────────────── */
/* ── Per-dependent evidence upload strip ─────────────────────────────────── */
.pf-dep-evidence {
  margin-top: 14px;
  padding: 12px 14px;
  background: #f0f4ff;
  border: 1px solid #c7d2fe;
  border-radius: 8px;
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
}

.pf-dep-evidence-header {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  flex: 1;
  min-width: 0;
}

.pf-dep-evidence-icon {
  font-size: 20px;
  flex-shrink: 0;
  margin-top: 1px;
}

.pf-dep-evidence-title {
  font-size: 13px;
  font-weight: 600;
  color: #3730a3;
  margin: 0 0 2px;
  line-height: 1.4;
}
.pf-dep-evidence-title em { font-style: italic; font-weight: 400; }

.pf-dep-evidence-hint {
  font-size: 11px;
  color: #6366f1;
  margin: 0;
}

.pf-dep-evidence-uploads {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 6px;
  flex-shrink: 0;
}

.pf-doc-upload-section {
  margin-top: 28px;
  padding-top: 20px;
  border-top: 1px solid #f1f5f9;
}
.pf-doc-required-box {
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 14px 16px;
  margin-bottom: 12px;
  background: #fafafa;
}
.pf-doc-row {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
}
.pf-doc-row-label {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  flex: 1;
  min-width: 0;
}
.pf-doc-icon { font-size: 22px; flex-shrink: 0; margin-top: 1px; }
.pf-doc-row-uploads {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 6px;
  flex-shrink: 0;
}
.pf-doc-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 6px;
  padding: 5px 10px;
  font-size: 12px;
  max-width: 260px;
}
.pf-doc-chip.uploading { background: #eff6ff; border-color: #bfdbfe; }
.pf-doc-chip.err       { background: #fef2f2; border-color: #fecaca; }
.pf-doc-chip-name {
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
  max-width: 180px;
}
.pf-doc-remove {
  background: none;
  border: none;
  cursor: pointer;
  color: #ef4444;
  font-size: 11px;
  padding: 0 2px;
  line-height: 1;
  flex-shrink: 0;
}
.pf-doc-upload-label {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  cursor: pointer;
  color: #4f46e5;
  font-size: 13px;
  font-weight: 600;
  border: 1px dashed #a5b4fc;
  border-radius: 6px;
  padding: 5px 12px;
  transition: background 0.12s;
  white-space: nowrap;
}
.pf-doc-upload-label:hover { background: #ede9fe; }
.pf-hidden-file {
  position: absolute;
  opacity: 0;
  width: 0;
  height: 0;
  pointer-events: none;
}
</style>
