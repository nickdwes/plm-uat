<script setup lang="ts">
import { ref, computed, onMounted, watch, reactive } from "vue";
import { useRouter, useRoute } from "vue-router";
import { api } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import type { TrainingItem, Paginator } from "../../lib/types";

const router = useRouter();
const route = useRoute();

const items = ref<TrainingItem[]>([]);
const total = ref(0);
const lastPage = ref(1);
const page = ref(1);
const type = ref("");
const delivery = ref("");
const search = ref("");
const loading = ref(false);
const error = ref("");
const perPage = 20;

const hasPrev = computed(() => page.value > 1);
const hasNext = computed(() => page.value < lastPage.value);

// ── Create / Edit modal ──────────────────────────────────────────────────────
const showModal = ref(false);
const editingId = ref<number | null>(null);
const saving = ref(false);
const formError = ref("");

const blankForm = () => ({
  title: "",
  description: "",
  type: "course",
  delivery: "e_learning",
  duration_hours: 1,
  duration_minutes: 0,
  provider_name: "",
  provider_url: "",
  cost_currency: "USD",
  estimated_cost: 0,
  is_active: true,
  is_featured: false,
  requires_approval: false,
  is_budgeted: false,
  budget_amount: null as number | null,
  max_enrollments: null as number | null,
  tags: [] as string[],
});

const form = reactive(blankForm());

function openCreate() {
  Object.assign(form, blankForm());
  editingId.value = null;
  formError.value = "";
  showModal.value = true;
}

function openEdit(item: TrainingItem, e: Event) {
  e.stopPropagation();
  Object.assign(form, {
    title: item.title,
    description: item.description ?? "",
    type: item.type,
    delivery: item.delivery,
    duration_hours: item.duration_hours,
    duration_minutes: item.duration_minutes,
    provider_name: item.provider_name ?? "",
    provider_url: item.provider_url ?? "",
    cost_currency: item.cost_currency,
    estimated_cost: item.estimated_cost,
    is_active: item.is_active,
    is_featured: item.is_featured,
    requires_approval: item.requires_approval,
    is_budgeted: item.is_budgeted,
    budget_amount: item.budget_amount ?? null,
    max_enrollments: item.max_enrollments ?? null,
    tags: item.tags ?? [],
  });
  editingId.value = item.id;
  formError.value = "";
  showModal.value = true;
}

async function saveItem() {
  if (!form.title.trim()) { formError.value = "Title is required."; return; }
  saving.value = true;
  formError.value = "";
  try {
    const payload = { ...form };
    if (editingId.value) {
      await api("PUT", `/api/v1/training/catalog/${editingId.value}`, { org: org(), body: payload });
    } else {
      await api("POST", "/api/v1/training/catalog", { org: org(), body: payload });
    }
    showModal.value = false;
    load(editingId.value ? page.value : 1);
  } catch (e: unknown) {
    const err = e as { message?: string; errors?: Record<string, string[]> };
    if (err.errors) {
      formError.value = Object.values(err.errors).flat().join(" ");
    } else {
      formError.value = err.message ?? "Failed to save training item.";
    }
  } finally {
    saving.value = false;
  }
}

async function deleteItem(item: TrainingItem, e: Event) {
  e.stopPropagation();
  if (!confirm(`Delete "${item.title}"? This cannot be undone.`)) return;
  try {
    await api("DELETE", `/api/v1/training/catalog/${item.id}`, { org: org() });
    load(page.value);
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to delete item.";
  }
}

const typeOptions = [
  { label: "All Types", value: "" },
  { label: "Course", value: "course" },
  { label: "Workshop", value: "workshop" },
  { label: "Certification", value: "certification" },
  { label: "Exam", value: "exam_voucher" },
];

const deliveryOptions = [
  { label: "All Delivery", value: "" },
  { label: "E-Learning", value: "e_learning" },
  { label: "In-Person", value: "in_person" },
  { label: "Virtual", value: "virtual" },
  { label: "Blended", value: "blended" },
];

function org() { return currentOrgSlug.value; }

async function load(p = 1) {
  if (!org()) { error.value = "Select an organisation first."; return; }
  loading.value = true;
  error.value = "";
  page.value = p;
  try {
    const res = await api<Paginator<TrainingItem>>("GET", "/api/v1/training/catalog", {
      org: org(),
      params: {
        page: p,
        per_page: perPage,
        type: type.value || undefined,
        delivery: delivery.value || undefined,
        search: search.value || undefined,
      },
    });
    items.value = res.data;
    total.value = res.meta.total;
    lastPage.value = res.meta.last_page;
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Failed to load catalog.";
  } finally {
    loading.value = false;
  }
}

let debounceTimer: ReturnType<typeof setTimeout>;
function onSearchInput() {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => load(1), 350);
}

function clearSearch() {
  search.value = "";
  load(1);
}

function setType(val: string) {
  type.value = val;
  load(1);
}

function setDelivery(val: string) {
  delivery.value = val;
  load(1);
}

function goToItem(item: TrainingItem) {
  router.push({ name: "training-item-detail", params: { id: item.id } });
}

function typeBadge(type: string): string {
  const map: Record<string, string> = {
    course: 'badge-blue',
    workshop: 'badge-indigo',
    certification: 'badge-teal',
    exam_voucher: 'badge-amber',
    subscription: 'badge-blue',
    on_job: 'badge-green',
    mentoring: 'badge-teal',
    conference: 'badge-indigo',
  };
  return map[type] ?? 'badge-gray';
}

onMounted(async () => {
  await load(1);
  // Handle ?edit=<id> from detail view "Edit" button
  if (route.query.edit) {
    const target = items.value.find(i => String(i.id) === String(route.query.edit));
    if (target) {
      openEdit(target, new MouseEvent("click"));
    }
    router.replace({ name: "training-catalog" });
  }
});
watch(currentOrgSlug, () => load(1));
</script>

<template>
  <div class="view">
    <div class="view-header">
      <div>
        <button class="btn btn-ghost btn-sm" style="margin-bottom: var(--space-2); padding-left: 0;" @click="router.back()">← Back</button>
        <h2>Training Catalog</h2>
        <p class="view-subtitle">{{ total }} item{{ total !== 1 ? 's' : '' }} available</p>
      </div>
      <button class="btn btn-primary" @click="openCreate">
        <svg viewBox="0 0 20 20" fill="currentColor" style="width:16px;height:16px;">
          <path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd" />
        </svg>
        Add Training Item
      </button>
    </div>

    <div v-if="error" class="error">{{ error }}</div>

    <!-- Search & Filters -->
    <div class="toolbar" style="margin-bottom: var(--space-5);">
      <div style="flex: 1; min-width: 240px; position: relative; display: flex; align-items: center;">
        <svg style="width:16px;height:16px;position:absolute;left:10px;color:var(--color-text-muted);" viewBox="0 0 20 20" fill="none">
          <circle cx="8.5" cy="8.5" r="5.5" stroke="currentColor" stroke-width="1.6"/>
          <path d="M13 13l3.5 3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
        </svg>
        <input
          v-model="search"
          type="search"
          style="padding-left: 34px;"
          placeholder="Search by title or provider…"
          @input="onSearchInput"
          @keyup.enter="load(1)"
        />
        <button v-if="search" class="btn btn-ghost btn-sm" style="position:absolute;right:4px;padding:4px 6px;" @click="clearSearch">✕</button>
      </div>
      <select v-model="type" @change="setType(type)">
        <option v-for="opt in typeOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
      </select>
      <select v-model="delivery" @change="setDelivery(delivery)">
        <option v-for="opt in deliveryOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
      </select>
    </div>

    <!-- Results info -->
    <p v-if="!loading" style="font-size: var(--font-size-sm); color: var(--color-text-muted); margin-bottom: var(--space-4);">
      Showing {{ items.length === 0 ? 0 : (page - 1) * perPage + 1 }}–{{ Math.min(page * perPage, total) }} of {{ total }} items
    </p>

    <p v-if="loading" class="status">Loading training items…</p>

    <!-- Items Grid -->
    <div v-else-if="items.length > 0" class="catalog-grid">
      <div v-for="item in items" :key="item.id" class="catalog-card" @click="goToItem(item)">

        <div class="catalog-card-header">
          <div style="flex:1; min-width:0;">
            <div class="catalog-card-title">{{ item.title }}</div>
          </div>
          <span v-if="item.is_featured" class="badge badge-amber" style="flex-shrink:0;">⭐ Featured</span>
        </div>

        <div style="display:flex; gap: var(--space-2); flex-wrap:wrap; margin-bottom: var(--space-3);">
          <span class="badge" :class="typeBadge(item.type)">{{ item.type.replace('_', ' ') }}</span>
          <span class="badge badge-indigo">{{ item.delivery.replace('_', ' ') }}</span>
          <span v-if="!item.is_active" class="badge badge-gray">Inactive</span>
        </div>

        <p class="catalog-card-desc">{{ item.description || 'No description provided.' }}</p>

        <div class="catalog-card-footer">
          <span style="font-size: var(--font-size-xs); color: var(--color-text-muted);">
            ⏱ {{ item.duration_hours }}h {{ item.duration_minutes }}m
          </span>
          <span style="font-size: var(--font-size-xs); color: var(--color-text-muted);">{{ item.provider_name }}</span>
        </div>

        <div class="catalog-card-actions" @click.stop>
          <button class="btn btn-secondary btn-sm" style="flex:1;" @click="openEdit(item, $event)">✎ Edit</button>
          <button class="btn btn-sm" style="flex:1; color:var(--color-error); border-color:var(--color-error); background:var(--color-error-light);" @click="deleteItem(item, $event)">🗑 Delete</button>
        </div>
      </div>
    </div>

    <!-- Empty State -->
    <div v-else class="card" style="text-align:center; padding: var(--space-8);">
      <div style="font-size:2rem; margin-bottom: var(--space-3);">📚</div>
      <strong>No training items found</strong>
      <p style="color: var(--color-text-muted); margin-top: var(--space-2);">
        {{ search ? 'Try a different search term.' : 'Add your first training item to get started.' }}
      </p>
      <div style="margin-top: var(--space-4); display:flex; gap: var(--space-3); justify-content:center;">
        <button v-if="search" class="btn btn-secondary" @click="clearSearch">Clear search</button>
        <button class="btn btn-primary" @click="openCreate">+ Add Training Item</button>
      </div>
    </div>

    <!-- Pagination -->
    <div v-if="items.length > 0 && lastPage > 1" class="pagination">
      <button class="btn btn-secondary btn-sm" :disabled="!hasPrev" @click="load(page - 1)">← Previous</button>
      <span>Page {{ page }} of {{ lastPage }}</span>
      <button class="btn btn-secondary btn-sm" :disabled="!hasNext" @click="load(page + 1)">Next →</button>
    </div>
  </div>

  <!-- Create / Edit Modal -->
  <Transition name="modal">
    <div v-if="showModal" class="modal-backdrop" @click.self="showModal = false">
      <div class="modal-dialog" role="dialog" aria-modal="true">

        <!-- Modal Header -->
        <div class="modal-header">
          <div class="modal-header-left">
            <div class="modal-icon">
              <svg viewBox="0 0 20 20" fill="currentColor" width="18" height="18">
                <path d="M9 4.804A7.968 7.968 0 005.5 4c-1.255 0-2.443.29-3.5.804v10A7.969 7.969 0 015.5 14c1.396 0 2.7.378 3.8 1.037A7.977 7.977 0 0113 14.5c1.255 0 2.443.29 3.5.804V5.804A7.968 7.968 0 0013 5a7.968 7.968 0 00-4 1.08V4.804z"/>
              </svg>
            </div>
            <div>
              <p class="modal-eyebrow">{{ editingId ? 'Edit item' : 'New item' }}</p>
              <h3 class="modal-title">{{ editingId ? 'Edit Training Item' : 'Add Training Item' }}</h3>
            </div>
          </div>
          <button class="modal-close" @click="showModal = false" title="Close">
            <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16">
              <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
            </svg>
          </button>
        </div>

        <!-- Error banner -->
        <div v-if="formError" class="modal-error">
          <svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14" style="flex-shrink:0;margin-top:1px;">
            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
          </svg>
          {{ formError }}
        </div>

        <!-- Scrollable body -->
        <form @submit.prevent="saveItem" class="modal-body">

          <!-- Title -->
          <div class="form-group">
            <label class="field-label">Title <span class="req">*</span></label>
            <input v-model="form.title" type="text" class="field-input" placeholder="e.g. Advanced Python Programming" required />
          </div>

          <!-- Description -->
          <div class="form-group">
            <label class="field-label">Description</label>
            <textarea v-model="form.description" rows="2" class="field-input field-textarea" placeholder="What will participants learn?"></textarea>
          </div>

          <!-- Type + Delivery row -->
          <div class="field-row">
            <div class="form-group">
              <label class="field-label">Type <span class="req">*</span></label>
              <div class="select-wrap">
                <select v-model="form.type" class="field-input">
                  <option value="course">Course</option>
                  <option value="workshop">Workshop</option>
                  <option value="certification">Certification</option>
                  <option value="exam_voucher">Exam Voucher</option>
                  <option value="subscription">Subscription</option>
                  <option value="on_job">On-the-Job</option>
                  <option value="mentoring">Mentoring</option>
                  <option value="conference">Conference</option>
                </select>
                <svg class="select-arrow" viewBox="0 0 20 20" fill="currentColor" width="14" height="14">
                  <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
                </svg>
              </div>
            </div>
            <div class="form-group">
              <label class="field-label">Delivery Mode <span class="req">*</span></label>
              <div class="select-wrap">
                <select v-model="form.delivery" class="field-input">
                  <option value="e_learning">E-Learning</option>
                  <option value="in_person">In-Person</option>
                  <option value="virtual">Virtual</option>
                  <option value="blended">Blended</option>
                  <option value="self_study">Self-Study</option>
                </select>
                <svg class="select-arrow" viewBox="0 0 20 20" fill="currentColor" width="14" height="14">
                  <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
                </svg>
              </div>
            </div>
          </div>

          <div class="modal-divider"></div>

          <!-- Duration & Capacity -->
          <p class="section-label">Duration &amp; Capacity</p>
          <div class="field-row field-row-3">
            <div class="form-group">
              <label class="field-label">Hours</label>
              <input v-model.number="form.duration_hours" type="number" min="0" class="field-input field-input-center" placeholder="0" />
            </div>
            <div class="form-group">
              <label class="field-label">Minutes</label>
              <input v-model.number="form.duration_minutes" type="number" min="0" max="59" class="field-input field-input-center" placeholder="0" />
            </div>
            <div class="form-group">
              <label class="field-label">Max Enrollments</label>
              <input v-model.number="form.max_enrollments" type="number" min="1" class="field-input field-input-center" placeholder="Unlimited" />
            </div>
          </div>

          <div class="modal-divider"></div>

          <!-- Provider & Cost -->
          <p class="section-label">Provider &amp; Cost</p>
          <div class="field-row">
            <div class="form-group">
              <label class="field-label">Provider Name</label>
              <input v-model="form.provider_name" type="text" class="field-input" placeholder="e.g. Udemy, Coursera" />
            </div>
            <div class="form-group">
              <label class="field-label">Provider URL</label>
              <input v-model="form.provider_url" type="url" class="field-input" placeholder="https://…" />
            </div>
          </div>
          <div class="field-row">
            <div class="form-group field-narrow">
              <label class="field-label">Currency</label>
              <input v-model="form.cost_currency" type="text" class="field-input field-input-center" placeholder="USD" maxlength="3" style="text-transform:uppercase;" />
            </div>
            <div class="form-group">
              <label class="field-label">Estimated Cost</label>
              <input v-model.number="form.estimated_cost" type="number" min="0" step="0.01" class="field-input" placeholder="0.00" />
            </div>
          </div>

          <div class="modal-divider"></div>

          <!-- Visibility toggles -->
          <p class="section-label">Visibility</p>
          <div class="toggle-row">
            <label class="toggle-card" :class="{ 'toggle-card-on': form.is_active }">
              <div class="toggle-card-body">
                <div class="toggle-card-icon toggle-icon-active">
                  <svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                  </svg>
                </div>
                <div>
                  <div class="toggle-card-label">Active</div>
                  <div class="toggle-card-hint">Visible to employees in their catalog</div>
                </div>
              </div>
              <span class="toggle-wrap">
                <input v-model="form.is_active" type="checkbox" class="toggle-input" />
                <span class="toggle-track"></span>
              </span>
            </label>

            <label class="toggle-card" :class="{ 'toggle-card-on': form.is_featured }">
              <div class="toggle-card-body">
                <div class="toggle-card-icon toggle-icon-featured">
                  <svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14">
                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                  </svg>
                </div>
                <div>
                  <div class="toggle-card-label">Featured</div>
                  <div class="toggle-card-hint">Highlighted with a ⭐ badge in the catalog</div>
                </div>
              </div>
              <span class="toggle-wrap">
                <input v-model="form.is_featured" type="checkbox" class="toggle-input" />
                <span class="toggle-track"></span>
              </span>
            </label>
          </div>

          <div class="modal-divider"></div>

          <!-- Approval & Budget -->
          <p class="section-label">Approval &amp; Budget</p>
          <div class="toggle-row">
            <label class="toggle-card" :class="{ 'toggle-card-on': form.requires_approval }">
              <div class="toggle-card-body">
                <div class="toggle-card-icon toggle-icon-approval">
                  <svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                  </svg>
                </div>
                <div>
                  <div class="toggle-card-label">Requires Approval</div>
                  <div class="toggle-card-hint">Manager or HR must approve enrollment requests</div>
                </div>
              </div>
              <span class="toggle-wrap">
                <input v-model="form.requires_approval" type="checkbox" class="toggle-input" />
                <span class="toggle-track"></span>
              </span>
            </label>

            <label class="toggle-card" :class="{ 'toggle-card-on': form.is_budgeted }">
              <div class="toggle-card-body">
                <div class="toggle-card-icon toggle-icon-budget">
                  <svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14">
                    <path d="M8.433 7.418c.155-.103.346-.196.567-.267v1.698a2.305 2.305 0 01-.567-.267C8.07 8.34 8 8.114 8 8c0-.114.07-.34.433-.582zM11 12.849v-1.698c.22.071.412.164.567.267.364.243.433.468.433.582 0 .114-.07.34-.433.582a2.305 2.305 0 01-.567.267z"/><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-13a1 1 0 10-2 0v.092a4.535 4.535 0 00-1.676.662C6.602 6.234 6 7.009 6 8c0 .99.602 1.765 1.324 2.246.48.32 1.054.545 1.676.662v1.941c-.391-.127-.68-.317-.843-.504a1 1 0 10-1.51 1.31c.562.649 1.413 1.076 2.353 1.253V15a1 1 0 102 0v-.092a4.535 4.535 0 001.676-.662C13.398 13.766 14 12.991 14 12c0-.99-.602-1.765-1.324-2.246A4.535 4.535 0 0011 9.092V7.151c.391.127.68.317.843.504a1 1 0 101.511-1.31c-.563-.649-1.413-1.076-2.354-1.253V5z" clip-rule="evenodd"/>
                  </svg>
                </div>
                <div>
                  <div class="toggle-card-label">Budgeted</div>
                  <div class="toggle-card-hint">This training has an allocated budget</div>
                </div>
              </div>
              <span class="toggle-wrap">
                <input v-model="form.is_budgeted" type="checkbox" class="toggle-input" />
                <span class="toggle-track"></span>
              </span>
            </label>
          </div>

          <!-- Budget amount — revealed when is_budgeted is on -->
          <Transition name="expand">
            <div v-if="form.is_budgeted" class="budget-amount-row">
              <div class="form-group field-narrow">
                <label class="field-label">Currency</label>
                <input v-model="form.cost_currency" type="text" class="field-input field-input-center" placeholder="USD" maxlength="3" style="text-transform:uppercase;" readonly />
              </div>
              <div class="form-group">
                <label class="field-label">Budget Amount</label>
                <input v-model.number="form.budget_amount" type="number" min="0" step="0.01" class="field-input" placeholder="e.g. 50000" />
              </div>
            </div>
          </Transition>

        </form>

        <!-- Footer -->
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" @click="showModal = false">Cancel</button>
          <button class="btn btn-primary" :disabled="saving" @click="saveItem">
            <svg v-if="saving" class="spin-icon" viewBox="0 0 20 20" fill="currentColor" width="14" height="14">
              <path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd"/>
            </svg>
            {{ saving ? 'Saving…' : (editingId ? 'Save Changes' : 'Create Item') }}
          </button>
        </div>

      </div>
    </div>
  </Transition>
</template>

<style scoped>
.catalog-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: var(--space-4);
  margin-bottom: var(--space-6);
}

.catalog-card {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  padding: var(--space-5);
  cursor: pointer;
  transition: box-shadow var(--transition-base), border-color var(--transition-base);
  box-shadow: var(--shadow-sm);
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
}

.catalog-card:hover {
  border-color: var(--color-brand-base);
  box-shadow: var(--shadow-md);
}

.catalog-card-header {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
}

.catalog-card-title {
  font-size: var(--font-size-md);
  font-weight: var(--font-weight-bold);
  color: var(--color-text);
  line-height: var(--line-height-tight);
}

.catalog-card-desc {
  font-size: var(--font-size-sm);
  color: var(--color-text-muted);
  line-height: var(--line-height-base);
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  flex: 1;
}

.catalog-card-footer {
  display: flex;
  justify-content: space-between;
  padding-top: var(--space-3);
  border-top: 1px solid var(--color-border);
}

.catalog-card-actions {
  display: flex;
  gap: var(--space-2);
  padding-top: var(--space-2);
}

/* ── Modal popup ──────────────────────────────────────────────────────────── */
.modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.5);
  backdrop-filter: blur(2px);
  z-index: 400;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: var(--space-4);
}

.modal-dialog {
  background: var(--color-surface);
  border-radius: 16px;
  width: 100%;
  max-width: 560px;
  max-height: 90vh;
  display: flex;
  flex-direction: column;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2), 0 4px 16px rgba(0, 0, 0, 0.1);
  overflow: hidden;
}

/* Header */
.modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 20px 24px 18px;
  border-bottom: 1px solid var(--color-border);
  flex-shrink: 0;
}

.modal-header-left {
  display: flex;
  align-items: center;
  gap: 14px;
}

.modal-icon {
  width: 40px;
  height: 40px;
  border-radius: 10px;
  background: #eff6ff;
  color: var(--color-brand-base);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.modal-eyebrow {
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.07em;
  color: var(--color-brand-base);
  margin: 0 0 2px;
}

.modal-title {
  font-size: 17px;
  font-weight: 700;
  color: var(--color-text);
  margin: 0;
  line-height: 1.2;
}

.modal-close {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  border: 1px solid var(--color-border);
  background: transparent;
  color: var(--color-text-muted);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: background 0.15s, color 0.15s;
  flex-shrink: 0;
}
.modal-close:hover { background: var(--color-border); color: var(--color-text); }

/* Error banner */
.modal-error {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  margin: 0 24px;
  padding: 10px 14px;
  background: var(--color-error-light);
  color: var(--color-error);
  border-radius: 8px;
  font-size: 13px;
  font-weight: 500;
  margin-top: 16px;
}

/* Scrollable body */
.modal-body {
  flex: 1;
  overflow-y: auto;
  padding: 20px 24px;
  display: flex;
  flex-direction: column;
  gap: 16px;
}

/* Fields */
.field-label {
  display: block;
  font-size: 12px;
  font-weight: 600;
  color: var(--color-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.05em;
  margin-bottom: 6px;
}

.field-input {
  display: block;
  width: 100%;
  box-sizing: border-box;
  padding: 9px 12px;
  border: 1.5px solid var(--color-border);
  border-radius: 8px;
  font-size: 14px;
  color: var(--color-text);
  background: var(--color-surface);
  outline: none;
  transition: border-color 0.15s, box-shadow 0.15s;
  font-family: inherit;
}
.field-input:focus {
  border-color: var(--color-brand-base);
  box-shadow: 0 0 0 3px rgba(0, 76, 151, 0.1);
}
.field-textarea { resize: vertical; min-height: 72px; }
.field-input-center { text-align: center; }

/* Custom select */
.select-wrap { position: relative; }
.select-wrap .field-input {
  appearance: none;
  -webkit-appearance: none;
  padding-right: 32px;
  cursor: pointer;
}
.select-arrow {
  position: absolute;
  right: 10px;
  top: 50%;
  transform: translateY(-50%);
  pointer-events: none;
  color: var(--color-text-muted);
}

/* Field rows */
.field-row {
  display: flex;
  gap: 12px;
}
.field-row .form-group { flex: 1; min-width: 0; }
.field-row-3 .form-group { flex: 1; min-width: 0; }
.field-narrow { flex: 0 0 90px !important; }

/* Dividers */
.modal-divider {
  border: none;
  border-top: 1px solid var(--color-border);
  margin: 4px 0 0;
}

/* Section labels */
.section-label {
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.07em;
  color: var(--color-text-muted);
  margin: 0 0 -4px;
}

/* Toggle cards */
.toggle-row {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.toggle-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 14px;
  border: 1.5px solid var(--color-border);
  border-radius: 10px;
  cursor: pointer;
  transition: border-color 0.15s, background 0.15s;
  background: var(--color-surface);
}
.toggle-card:hover { border-color: #94a3b8; }
.toggle-card-on { border-color: var(--color-brand-base); background: #f0f7ff; }

.toggle-card-body {
  display: flex;
  align-items: center;
  gap: 12px;
}

.toggle-card-icon {
  width: 30px;
  height: 30px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.toggle-icon-active   { background: #dcfce7; color: #16a34a; }
.toggle-icon-featured { background: #fef9c3; color: #ca8a04; }
.toggle-icon-approval { background: #e0f2fe; color: #0284c7; }
.toggle-icon-budget   { background: #ede9fe; color: #7c3aed; }

.toggle-card-label {
  font-size: 14px;
  font-weight: 600;
  color: var(--color-text);
  line-height: 1.3;
}
.toggle-card-hint {
  font-size: 12px;
  color: var(--color-text-muted);
  margin-top: 1px;
}

/* Toggle switch */
.toggle-wrap { position: relative; flex-shrink: 0; }
.toggle-input { position: absolute; opacity: 0; width: 0; height: 0; }
.toggle-track {
  display: block;
  width: 40px;
  height: 22px;
  background: var(--color-neutral-200);
  border-radius: var(--radius-full);
  transition: background var(--transition-fast);
  position: relative;
}
.toggle-track::after {
  content: '';
  position: absolute;
  top: 3px; left: 3px;
  width: 16px; height: 16px;
  border-radius: 50%;
  background: #fff;
  box-shadow: 0 1px 3px rgba(0,0,0,0.2);
  transition: transform var(--transition-fast);
}
.toggle-input:checked + .toggle-track { background: var(--color-brand-base); }
.toggle-input:checked + .toggle-track::after { transform: translateX(18px); }

/* Footer */
.modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  padding: 16px 24px;
  border-top: 1px solid var(--color-border);
  flex-shrink: 0;
  background: var(--color-surface);
}

.req { color: var(--color-error); }

/* Spinner icon */
.spin-icon {
  animation: spin 0.7s linear infinite;
  margin-right: 4px;
  vertical-align: middle;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* Budget amount reveal row */
.budget-amount-row {
  display: flex;
  gap: 12px;
  padding: 14px 16px;
  background: #f5f3ff;
  border: 1.5px solid #ddd6fe;
  border-radius: 10px;
  margin-top: -4px;
}
.budget-amount-row .form-group { flex: 1; min-width: 0; }
.budget-amount-row .field-narrow { flex: 0 0 90px !important; }

/* Expand/collapse transition for budget row */
.expand-enter-active,
.expand-leave-active {
  transition: opacity 0.2s ease, transform 0.2s ease;
}
.expand-enter-from,
.expand-leave-to {
  opacity: 0;
  transform: translateY(-6px);
}

/* Modal scale+fade transition */
.modal-enter-active,
.modal-leave-active {
  transition: opacity 0.18s ease;
}
.modal-enter-active .modal-dialog,
.modal-leave-active .modal-dialog {
  transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.18s ease;
}
.modal-enter-from,
.modal-leave-to { opacity: 0; }
.modal-enter-from .modal-dialog { transform: scale(0.94) translateY(8px); opacity: 0; }
.modal-leave-to .modal-dialog  { transform: scale(0.96) translateY(4px); opacity: 0; }
</style>
