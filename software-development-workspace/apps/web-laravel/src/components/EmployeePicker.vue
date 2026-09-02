<script setup lang="ts">
/**
 * Employee typeahead.
 *
 * Replaces the raw numeric id fields in the asset and role dialogs. Typing an
 * id is not merely inconvenient — it fails silently. Nothing tells you that
 * employee 12 is a different person from the one you meant, and the mistake
 * only surfaces when equipment or access lands somewhere unexpected.
 *
 * Selection is explicit: the model updates only when a result is chosen, so a
 * half-typed name cannot be submitted as if it were a person.
 */
import { ref, watch, onBeforeUnmount } from "vue";
import { api } from "../lib/api";
import { currentOrgSlug } from "../stores/auth";

interface Match {
  id: number;
  full_name: string;
  employee_number: string;
  work_email: string | null;
}

const props = withDefaults(defineProps<{
  modelValue: number | null;
  placeholder?: string;
  disabled?: boolean;
  /* When set, the search only returns people in this department. */
  departmentId?: number | null;
}>(), {
  placeholder: "Search by name, number or email…",
  disabled: false,
  departmentId: null,
});

const emit = defineEmits<{
  (e: "update:modelValue", value: number | null): void;
  /*
   * The whole record, for callers that need more than the id — an invitee list
   * needs the name and the work address, and re-fetching a person you have just
   * picked to learn their email is a request nobody should have to make.
   */
  (e: "chosen", match: Match | null): void;
}>();

const term     = ref("");
const results  = ref<Match[]>([]);
const chosen   = ref<Match | null>(null);
const open     = ref(false);
const loading  = ref(false);
const searched = ref(false);

let timer: ReturnType<typeof setTimeout> | null = null;

/*
 * Set while `choose()` writes the name into the box.
 *
 * Without it the write trips the `term` watcher, which reopens the list and
 * searches again for the name just picked — leaving "No active employee matches
 * that" sitting under a successful selection whenever the full name is not
 * itself a hit. The box being filled by a choice is not somebody typing.
 */
let selecting = false;

async function run(q: string) {
  if (!currentOrgSlug.value) return;
  loading.value = true;
  try {
    const res = await api<{ data: Match[] }>("GET", "/api/v1/employees/search", {
      org: currentOrgSlug.value,
      params: props.departmentId ? { q, department_id: props.departmentId } : { q },
    });
    results.value = res.data ?? [];
    searched.value = true;
  } catch {
    // A failed lookup must not look like "no such person" — leave the list
    // untouched and let the empty state stay silent.
    results.value = [];
  } finally {
    loading.value = false;
  }
}

// Debounced so a typed name is one request, not one per keystroke.
watch(term, (q) => {
  if (selecting) { selecting = false; return; }
  if (timer) clearTimeout(timer);
  if (chosen.value && q !== chosen.value.full_name) clear();
  if (q.trim().length < 2) { results.value = []; searched.value = false; return; }
  open.value = true;
  timer = setTimeout(() => void run(q.trim()), 250);
});

function choose(m: Match) {
  selecting = true;
  chosen.value = m;
  term.value = m.full_name;
  open.value = false;
  searched.value = false;
  emit("update:modelValue", m.id);
  emit("chosen", m);
}

function clear() {
  chosen.value = null;
  emit("update:modelValue", null);
  emit("chosen", null);
}

/**
 * Opening the field lists people straight away.
 *
 * A picker that shows nothing until you have typed two characters is a text box
 * wearing a picker's clothes: it asks you to already know the name, which is
 * the thing you opened it to find out. So focus lists the first page — the
 * courtesy the department-scoped case already had, now given to every caller.
 *
 * Cached on `results`, which the `term` watcher empties whenever the box is
 * cleared, so re-focusing without typing reuses the list rather than asking
 * again.
 */
async function browse() {
  if (chosen.value) return;
  if (term.value.trim().length >= 2) { open.value = results.value.length > 0; return; }

  open.value = true;

  if (results.value.length === 0) {
    await run("");
  }
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
      @focus="browse"
      @keydown.escape="open = false"
    />

    <!-- Confirms the resolved person, so a selection is never implied by
         text that merely happens to be in the box. -->
    <p v-if="chosen" class="chosen">
      Selected <strong>{{ chosen.full_name }}</strong>
      <span class="muted">({{ chosen.employee_number }})</span>
      <button type="button" class="clear" @click="term = ''; clear()">change</button>
    </p>

    <ul v-if="open && (results.length || (searched && !loading))" class="results" role="listbox">
      <li v-if="loading" class="muted pad">Searching…</li>
      <!-- "matches that" is only true when something was typed; on an opened
           field with an empty box there is no "that" to match. -->
      <li v-else-if="!results.length" class="muted pad">
        {{ term.trim() ? "No active employee matches that." : "No active employees to pick from." }}
      </li>
      <li
        v-for="m in results" :key="m.id"
        role="option" tabindex="0"
        @click="choose(m)" @keydown.enter="choose(m)"
      >
        <span class="name">{{ m.full_name }}</span>
        <span class="meta">{{ m.employee_number }}<template v-if="m.work_email"> · {{ m.work_email }}</template></span>
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

.chosen {
  font-size: var(--text-xs-size);
  color: var(--color-text-muted);
  margin: var(--space-1) 0 0;
}

.clear {
  @apply ml-2 cursor-pointer border-none bg-transparent p-0 font-medium underline-offset-2;
  font-family: inherit;
  font-size: var(--text-xs-size);
  color: var(--color-brand-base);
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
}
.results li:last-child { border-bottom: none; }
.results li:hover { background: var(--color-surface-sunken); }
.results li:focus-visible {
  @apply outline-2 outline-offset-[-2px];
  outline-color: var(--color-brand-base);
}

.name { font-size: var(--text-sm-size); color: var(--color-text); }
.meta { font-size: var(--text-xs-size); color: var(--color-text-light); }
.muted { font-size: var(--text-xs-size); color: var(--color-text-light); }
.pad { padding: var(--space-3); cursor: default; }
</style>
