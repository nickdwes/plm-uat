<script setup lang="ts">
/**
 * Customer typeahead.
 *
 * Selection is explicit: the model updates only when a result is chosen, so a
 * half-typed company name cannot be submitted as if it were a customer on
 * file. The same reasoning as EmployeePicker — typing an id fails silently,
 * and nothing tells you that customer 12 is not the one you meant.
 *
 * A failed lookup says so rather than rendering an empty list. An empty
 * dropdown reads as "no such customer", which is how somebody ends up
 * convinced a client was never entered.
 */
import { ref, watch, onBeforeUnmount, onMounted } from "vue";
import { api, ApiError } from "../lib/api";
import { currentOrgSlug } from "../stores/auth";

interface Match {
  id: number;
  name: string;
  reference: string | null;
  initials: string;
  type?: { id: number; name: string } | null;
}

const props = withDefaults(defineProps<{
  modelValue: number | null;
  placeholder?: string;
  disabled?: boolean;
}>(), {
  placeholder: "Search customers…",
  disabled: false,
});

const emit = defineEmits<{
  (e: "update:modelValue", value: number | null): void;
}>();

const term     = ref("");
const results  = ref<Match[]>([]);
const chosen   = ref<Match | null>(null);
const open     = ref(false);
const loading  = ref(false);
const searched = ref(false);
const blocked  = ref(false);

let timer: ReturnType<typeof setTimeout> | null = null;

async function run(q: string) {
  if (!currentOrgSlug.value) return;
  loading.value = true;
  try {
    const res = await api<{ data: Match[] }>("GET", "/api/v1/accounts", {
      org: currentOrgSlug.value,
      params: { search: q || undefined, per_page: 15 },
    });
    results.value = res.data ?? [];
    searched.value = true;
    blocked.value = false;
  } catch (e) {
    results.value = [];
    // Said out loud rather than shown as "no matches": being unable to read
    // the customer master is a permissions problem, not an empty book.
    blocked.value = e instanceof ApiError && e.status === 403;
    searched.value = true;
  } finally {
    loading.value = false;
  }
}

/**
 * Loaded once on mount as well as on typing.
 *
 * Most organisations have a few dozen customers, and making somebody type
 * before they can see any of them hides the list from the person who does not
 * yet know what is in it.
 */
onMounted(() => {
  void run("");

  if (props.modelValue) void resolveExisting(props.modelValue);
});

async function resolveExisting(id: number) {
  try {
    const res = await api<{ data: Match }>("GET", `/api/v1/accounts/${id}`, { org: currentOrgSlug.value });
    chosen.value = res.data;
    term.value = res.data.name;
  } catch { /* leave it unresolved rather than showing a wrong name */ }
}

watch(term, (q) => {
  if (timer) clearTimeout(timer);
  if (chosen.value && q !== chosen.value.name) clear();
  open.value = true;
  timer = setTimeout(() => void run(q.trim()), 250);
});

function choose(m: Match) {
  chosen.value = m;
  term.value = m.name;
  open.value = false;
  emit("update:modelValue", m.id);
}

function clear() {
  chosen.value = null;
  emit("update:modelValue", null);
}

onBeforeUnmount(() => { if (timer) clearTimeout(timer); });
</script>

<template>
  <div class="picker">
    <input
      v-model="term"
      class="control"
      type="search"
      :placeholder="placeholder"
      :disabled="disabled"
      autocomplete="off"
      role="combobox"
      :aria-expanded="open"
      aria-autocomplete="list"
      @focus="open = true"
      @keydown.escape="open = false"
    />

    <!-- Confirms the resolved customer, so a selection is never implied by
         text that merely happens to be in the box. -->
    <p v-if="chosen" class="chosen">
      Selected <strong>{{ chosen.name }}</strong>
      <span v-if="chosen.reference" class="muted">({{ chosen.reference }})</span>
      <button type="button" class="clear" @click="term = ''; clear()">change</button>
    </p>

    <ul v-if="open && (results.length || (searched && !loading))" class="results" role="listbox">
      <li v-if="loading" class="muted pad">Searching…</li>
      <li v-else-if="blocked" class="muted pad">
        You cannot read the customer list. Ask for customer access, or have somebody
        else attach this one.
      </li>
      <li v-else-if="!results.length" class="muted pad">
        No customer matches that. It may need adding to the customer master first.
      </li>
      <li
        v-for="m in results" :key="m.id"
        role="option" tabindex="0"
        @click="choose(m)" @keydown.enter="choose(m)"
      >
        <span class="row">
          <span class="avatar">{{ m.initials }}</span>
          <span class="name">{{ m.name }}</span>
        </span>
        <span class="meta">
          {{ m.reference ?? "—" }}<template v-if="m.type?.name"> · {{ m.type.name }}</template>
        </span>
      </li>
    </ul>
  </div>
</template>

<style scoped>
@reference "../theme.css";

.picker { @apply relative; }

.control {
  @apply w-full;
  font-family: inherit;
  font-size: var(--text-sm-size);
  padding: 8px var(--space-3);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-md);
  background: var(--color-surface);
  color: var(--color-text);
}
.control:focus {
  @apply outline-none;
  border-color: var(--color-brand-base);
  box-shadow: 0 0 0 3px var(--color-brand-subtle);
}

.chosen { font-size: var(--text-xs-size); color: var(--color-text-muted); margin: var(--space-1) 0 0; }
.clear {
  @apply ml-2 cursor-pointer border-none bg-transparent p-0 font-medium underline-offset-2;
  font-family: inherit; font-size: var(--text-xs-size); color: var(--color-brand-base);
}
.clear:hover { @apply underline; }

.results {
  @apply absolute z-10 max-h-64 w-full list-none overflow-y-auto;
  margin: var(--space-1) 0 0;
  padding: 0;
  background: var(--color-surface-raised);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-md);
}
.results li {
  @apply flex cursor-pointer flex-col;
  padding: var(--space-2) var(--space-3);
  border-bottom: 1px solid var(--color-border);
  gap: 2px;
}
.results li:last-child { border-bottom: none; }
.results li:hover { background: var(--color-surface-sunken); }
.results li:focus-visible { @apply outline-2 outline-offset-[-2px]; outline-color: var(--color-brand-base); }

.row { @apply flex items-center; gap: var(--space-2); }
.avatar {
  @apply inline-flex shrink-0 items-center justify-center;
  width: 1.5rem; height: 1.5rem; border-radius: var(--radius-sm);
  background: var(--color-brand-subtle); color: var(--color-brand-on-surface);
  font-size: 0.55rem; font-weight: 700;
}
.name { font-size: var(--text-sm-size); color: var(--color-text); }
.meta { font-size: var(--text-xs-size); color: var(--color-text-light); padding-left: 2rem; }
.muted { font-size: var(--text-xs-size); color: var(--color-text-light); }
.pad { padding: var(--space-3); cursor: default; }
</style>
