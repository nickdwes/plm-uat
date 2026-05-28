/**
 * Reactive auth store for the Laravel HRMS SPA.
 * Persists to sessionStorage — cleared when the tab is closed.
 */
import { computed, reactive, readonly } from "vue";
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

function load(): Partial<AuthState> {
  try {
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
    sessionStorage.setItem(STORAGE_KEY, JSON.stringify(state));
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

export function setAuth(user: AuthUser, organizations: Organization[], token: string): void {
  state.user = user;
  state.organizations = organizations;
  state.currentOrg = organizations[0] ?? null;
  state.token = token;
  persist();
}

export function setOrg(org: Organization): void {
  state.currentOrg = org;
  persist();
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
  state.currentOrg = null;
  state.token = null;
  sessionStorage.removeItem(STORAGE_KEY);
}

/** Read-only view of the store for use in components. */
export const authStore = readonly(state);
