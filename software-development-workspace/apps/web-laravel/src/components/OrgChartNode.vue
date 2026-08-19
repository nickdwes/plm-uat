<script setup lang="ts">
/**
 * One person on the org chart, and everyone under them.
 *
 * Recursive, because the shape is. Drawn top-down with connector lines rather
 * than an indented list: an indented list is easier to build and answers a
 * different question — it tells you who is senior, where a chart tells you who
 * sits alongside whom, which is what people open one to find out.
 */
import { computed, ref } from "vue";

export interface ChartNode {
  id: number;
  name: string;
  number: string;
  title: string | null;
  department: string | null;
  email: string | null;
  photo: string | null;
  is_shared: boolean;
  reports: number;
  children: ChartNode[];
}

const props = withDefaults(defineProps<{
  node: ChartNode;
  /** Nodes at or below this depth start folded, so a large chart opens legibly. */
  collapseBelow?: number;
  depth?: number;
  highlight?: string;
}>(), { collapseBelow: 3, depth: 0, highlight: "" });

const emit = defineEmits<{ (e: "pick", node: ChartNode): void }>();

const open = ref(props.depth < props.collapseBelow);

const hasChildren = computed(() => props.node.children.length > 0);

/** Initials, for the common case of no photograph on file. */
const initials = computed(() =>
  props.node.name.split(/\s+/).filter(Boolean).slice(0, 2)
    .map((w) => w[0]?.toUpperCase() ?? "").join(""));

const matches = computed(() => {
  const q = props.highlight.trim().toLowerCase();
  if (!q) return false;
  return [props.node.name, props.node.title, props.node.department, props.node.number]
    .some((v) => (v ?? "").toLowerCase().includes(q));
});
</script>

<template>
  <li class="oc-node">
    <div class="oc-card-wrap">
      <button
        :class="['oc-card', { match: matches, shared: node.is_shared }]"
        @click="emit('pick', node)"
      >
        <span class="oc-avatar" aria-hidden="true">
          <img v-if="node.photo" :src="node.photo" :alt="''" />
          <span v-else>{{ initials }}</span>
        </span>

        <span class="oc-who">
          <span class="oc-name">{{ node.name }}</span>
          <span class="oc-title">{{ node.title ?? "No designation" }}</span>
          <span v-if="node.department" class="oc-dept">{{ node.department }}</span>
          <!--
            A seconded person appears on their home chart and works somewhere
            else part of the week. Marking it here stops the chart reading as a
            full-time headcount it is not.
          -->
          <span v-if="node.is_shared" class="oc-shared">Shared / seconded</span>
        </span>
      </button>

      <!--
        The count is on the toggle, so a folded branch says how much it is
        hiding. A plain chevron makes a collapsed chart look like a complete one.
      -->
      <button
        v-if="hasChildren"
        class="oc-toggle"
        :aria-expanded="open"
        :aria-label="open ? `Collapse ${node.name}` : `Expand ${node.name}, ${node.reports} below`"
        @click="open = !open"
      >
        {{ open ? "−" : `+${node.reports}` }}
      </button>
    </div>

    <ul v-if="hasChildren && open" class="oc-children">
      <OrgChartNode
        v-for="child in node.children"
        :key="child.id"
        :node="child"
        :depth="depth + 1"
        :collapse-below="collapseBelow"
        :highlight="highlight"
        @pick="emit('pick', $event)"
      />
    </ul>
  </li>
</template>

<style scoped>
@reference "../theme.css";

/*
 * Connector lines are drawn with pseudo-elements rather than SVG. The tree
 * reflows as branches open and close, and anything absolutely positioned
 * against measured coordinates goes wrong the moment it does.
 */
.oc-node {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 0 10px;
  list-style: none;
}

/* The stalk up to the parent's horizontal rail. */
.oc-node::before {
  content: "";
  position: absolute;
  top: -18px;
  height: 18px;
  border-left: 1px solid var(--color-border);
}
:deep(.oc-root) > .oc-node::before { display: none; }

.oc-card-wrap { position: relative; display: flex; align-items: center; }

.oc-card {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 15rem;
  padding: 10px 12px;
  text-align: left;
  cursor: pointer;
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: 10px;
  transition: border-color .12s ease, box-shadow .12s ease;
}
.oc-card:hover { border-color: var(--color-brand-base); }
.oc-card:focus-visible { outline: 2px solid var(--color-brand-base); outline-offset: 2px; }
.oc-card.match { border-color: var(--color-brand-base); box-shadow: 0 0 0 3px var(--color-brand-subtle); }
.oc-card.shared { border-style: dashed; }

.oc-avatar {
  flex: 0 0 auto;
  width: 34px; height: 34px;
  border-radius: 999px;
  display: grid; place-items: center;
  background: var(--color-brand-subtle);
  color: var(--color-brand-base);
  font-size: .78rem; font-weight: 600;
  overflow: hidden;
}
.oc-avatar img { width: 100%; height: 100%; object-fit: cover; }

.oc-who { display: flex; flex-direction: column; min-width: 0; }
.oc-name { font-size: .86rem; font-weight: 600; color: var(--color-text);
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.oc-title { font-size: .76rem; color: var(--color-text-light);
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.oc-dept { font-size: .7rem; color: var(--color-text-muted);
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.oc-shared { font-size: .66rem; color: var(--color-brand-base); margin-top: 2px; }

.oc-toggle {
  position: absolute;
  left: 50%; transform: translateX(-50%);
  bottom: -13px;
  min-width: 26px; height: 20px;
  padding: 0 6px;
  border-radius: 999px;
  border: 1px solid var(--color-border);
  background: var(--color-surface);
  color: var(--color-text-light);
  font-size: .7rem; line-height: 1;
  cursor: pointer;
  z-index: 1;
}
.oc-toggle:hover { border-color: var(--color-brand-base); color: var(--color-brand-base); }

.oc-children {
  display: flex;
  justify-content: center;
  margin: 30px 0 0;
  padding: 0;
  position: relative;
}

/* The rail joining a row of siblings. */
.oc-children::before {
  content: "";
  position: absolute;
  top: -18px;
  left: 50%;
  height: 18px;
  border-left: 1px solid var(--color-border);
}
.oc-children > .oc-node::after {
  content: "";
  position: absolute;
  top: -18px;
  width: 50%;
  border-top: 1px solid var(--color-border);
}
.oc-children > .oc-node:first-child::after { left: 50%; }
.oc-children > .oc-node:last-child::after  { right: 50%; }
/* A lone child needs the stalk and no rail at all. */
.oc-children > .oc-node:only-child::after { display: none; }
.oc-children > .oc-node:not(:first-child):not(:last-child)::after { width: 100%; left: 0; }
</style>
