/**
 * Reactive auth store for the Laravel HRMS SPA.
 *
 * Persistence is chosen at sign-in via "Remember me":
 *   unchecked → sessionStorage, cleared when the tab closes (the default)
 *   checked   → localStorage, survives until sign-out
 * Only ever one of the two holds a session; switching clears the other.
 */
import { computed, reactive, readonly } from "vue";
import { setPermissions, clearPermissions, fetchPermissions } from "./permissions";
import type { AuthUser, Organization } from "../lib/types";

const STORAGE_KEY = "hrms_laravel_auth";

interface AuthState {
  user: AuthUser | null;
  organizations: Organization[];
  /** Currently active organization (sent as X-Organization header). */
  currentOrg: Organization | null;
  /** Sanctum Bearer token — sent as Authorization header on every request. */
  token: string | null;
}

/** The store a persisted session was found in, or will be written to. */
let backing: Storage = sessionStorage;

function load(): Partial<AuthState> {
  try {
    // localStorage first: a "remembered" session should win over a stale
    // tab-scoped one left behind by an earlier sign-in.
    const fromLocal = localStorage.getItem(STORAGE_KEY);
    if (fromLocal) {
      backing = localStorage;
      return JSON.parse(fromLocal) as Partial<AuthState>;
    }
    const raw = sessionStorage.getItem(STORAGE_KEY);
    return raw ? (JSON.parse(raw) as Partial<AuthState>) : {};
  } catch {
    return {};
  }
}

const stored = load();

const state = reactive<AuthState>({
  user: stored.user ?? null,
  organizations: stored.organizations ?? [],
  currentOrg: stored.currentOrg ?? null,
  token: (stored as AuthState).token ?? null,
});

function persist(): void {
  try {
    backing.setItem(STORAGE_KEY, JSON.stringify(state));
  } catch {
    /* ignore */
  }
}

/**
 * Choose where the session is kept. Call before setAuth() on sign-in.
 * Clears the other store so a session can never exist in both.
 */
export function setPersistence(remember: boolean): void {
  backing = remember ? localStorage : sessionStorage;
  try {
    (remember ? sessionStorage : localStorage).removeItem(STORAGE_KEY);
  } catch {
    /* ignore */
  }
}

// ---------------------------------------------------------------------------
// Computed helpers
// ---------------------------------------------------------------------------

export const isAuthenticated = computed(() => state.user !== null);

export const currentOrgSlug = computed(() => state.currentOrg?.slug ?? null);

export const hasMultipleOrgs = computed(() => state.organizations.length > 1);

// ---------------------------------------------------------------------------
// Mutations
// ---------------------------------------------------------------------------

export function getToken(): string | null {
  return state.token;
}

export function setAuth(
  user: AuthUser,
  organizations: Organization[],
  token: string,
  permissions?: string[],
): void {
  state.user = user;
  state.organizations = organizations;
  state.currentOrg = organizations[0] ?? null;

  // Login already computed the caller's rights for this org; seed the
  // permissions store so first paint asks nothing. A restored session (no
  // list here) fetches on the first guarded navigation instead.
  if (permissions) {
    setPermissions(permissions);
  } else {
    clearPermissions();
  }
  state.token = token;
  persist();
}

export function setOrg(org: Organization): void {
  state.currentOrg = org;
  persist();

  // Rights differ per organisation; the old list describes the old one.
  void fetchPermissions(true);
}

/**
 * Patch branding fields (logo_url, primary_color) on a specific org in the list
 * and refresh currentOrg if it matches.
 */
export function updateOrgBranding(
  orgId: number,
  patch: Partial<Pick<Organization, 'logo_url' | 'primary_color'>>,
): void {
  state.organizations = state.organizations.map((o) =>
    o.id === orgId ? { ...o, ...patch } : o,
  );
  if (state.currentOrg?.id === orgId) {
    state.currentOrg = { ...state.currentOrg, ...patch };
  }
  persist();
}

export function clearAuth(): void {
  state.user = null;
  state.organizations = [];
  clearPermissions();
  state.currentOrg = null;
  state.token = null;
  // Clear both: sign-out must not leave a remembered session behind.
  try { sessionStorage.removeItem(STORAGE_KEY); } catch { /* ignore */ }
  try { localStorage.removeItem(STORAGE_KEY); } catch { /* ignore */ }
  backing = sessionStorage;
}

/** Read-only view of the store for use in components. */
export const authStore = readonly(state);
