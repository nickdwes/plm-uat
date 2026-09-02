/**
 * The caller's rights, as the server computed them.
 *
 * A flat Set of concrete permission strings — wildcards already expanded,
 * exclusions and denies already applied, by the same engine that will answer
 * the API call. The client performs a Set lookup and nothing else: no matcher,
 * no role names, no second implementation of the rules to drift.
 *
 * This is UX, not enforcement. Everything hidden here is refused again
 * server-side; a stale list means a nav item that 403s (and triggers a
 * refetch), never an open door.
 */
import { reactive, computed } from "vue";
import { api } from "../lib/api";
import { currentOrgSlug } from "./auth";

const state = reactive({
  permissions: new Set<string>(),
  loaded: false,
  loading: null as Promise<void> | null,
});

/** Seed from the login response, so first paint needs no second request. */
export function setPermissions(permissions: string[]): void {
  state.permissions = new Set(permissions);
  state.loaded = true;
}

export function clearPermissions(): void {
  state.permissions = new Set();
  state.loaded = false;
  state.loading = null;
}

/**
 * Fetch (or re-fetch) for the organisation currently in context.
 *
 * Deduplicated: the router guard and a view mounting at the same time share
 * one request. Callers that need freshness (org switch, a 403) pass force.
 */
export function fetchPermissions(force = false): Promise<void> {
  if (state.loaded && !force) return Promise.resolve();
  if (state.loading && !force) return state.loading;

  state.loading = api<{ data: { permissions: string[] } }>(
    "GET", "/api/v1/authorization/my-permissions", { org: currentOrgSlug.value },
  )
    .then((res) => {
      state.permissions = new Set(res.data.permissions);
      state.loaded = true;
    })
    .catch(() => {
      /*
       * A failed fetch leaves whatever was known standing rather than
       * blanking the UI — the server still refuses anything it should.
       */
    })
    .finally(() => {
      state.loading = null;
    });

  return state.loading;
}

export const permissionsLoaded = computed(() => state.loaded);

/** May the caller do this? A Set lookup — the server did all the thinking. */
export function can(permission: string): boolean {
  return state.permissions.has(permission);
}

export function canAny(...permissions: string[]): boolean {
  return permissions.some((p) => state.permissions.has(p));
}
