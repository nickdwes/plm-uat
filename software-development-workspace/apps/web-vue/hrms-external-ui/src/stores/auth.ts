/**
 * Reactive auth + company-context store.
 *
 * Stored in sessionStorage so credentials survive a hard-refresh within the
 * same tab but are cleared when the tab is closed.
 */
import { computed, reactive, readonly } from "vue";

export const ROLE_GLOBAL_HR = "HRMS Global HR";
export const ROLE_COMPANY_HR = "HRMS Company HR";
export const ROLE_MANAGER = "HRMS Manager";
export const ROLE_EMPLOYEE = "HRMS Employee";

const STORAGE_KEY = "hrms_auth";

export interface AuthState {
  baseUrl: string;
  apiKey: string;
  apiSecret: string;
  /** Currently-selected company (null = all accessible companies for Global HR) */
  company: string | null;
  /** Frappe role of the authenticated user */
  role: string | null;
  /** Frappe username */
  username: string | null;
  /** List of companies this user has access to */
  companies: string[];
  /** Laravel REST API base URL (e.g. https://api.hrms.example.com) */
  laravelBaseUrl: string;
  /** Sanctum personal access token for Laravel REST API */
  laravelToken: string;
}

function loadFromStorage(): Partial<AuthState> {
  try {
    const raw = sessionStorage.getItem(STORAGE_KEY);
    return raw ? (JSON.parse(raw) as Partial<AuthState>) : {};
  } catch {
    return {};
  }
}

const stored = loadFromStorage();

const state = reactive<AuthState>({
  baseUrl: stored.baseUrl ?? import.meta.env.VITE_FRAPPE_BASE_URL ?? "",
  apiKey: stored.apiKey ?? "",
  apiSecret: stored.apiSecret ?? "",
  company: stored.company ?? null,
  role: stored.role ?? null,
  username: stored.username ?? null,
  companies: stored.companies ?? [],
  laravelBaseUrl: stored.laravelBaseUrl ?? import.meta.env.VITE_LARAVEL_BASE_URL ?? "",
  laravelToken: stored.laravelToken ?? "",
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

export const isConfigured = computed(() => state.baseUrl.trim().length > 0);

export const isAuthenticated = computed(() => isConfigured.value && state.role !== null);

export const isGlobalHR = computed(() => state.role === ROLE_GLOBAL_HR);

export const isCompanyHR = computed(
  () => state.role === ROLE_GLOBAL_HR || state.role === ROLE_COMPANY_HR,
);

export const isManager = computed(
  () => isCompanyHR.value || state.role === ROLE_MANAGER,
);

// ---------------------------------------------------------------------------
// Mutations
// ---------------------------------------------------------------------------

export function setConnection(baseUrl: string, apiKey: string, apiSecret: string): void {
  state.baseUrl = baseUrl;
  state.apiKey = apiKey;
  state.apiSecret = apiSecret;
  persist();
}

export function setSession(
  role: string,
  username: string,
  companies: string[],
  company: string | null,
): void {
  state.role = role;
  state.username = username;
  state.companies = companies;
  state.company = company ?? companies[0] ?? null;
  persist();
}

export function setCompany(company: string | null): void {
  state.company = company;
  persist();
}

export function setLaravelConnection(laravelBaseUrl: string, laravelToken: string): void {
  state.laravelBaseUrl = laravelBaseUrl;
  state.laravelToken = laravelToken;
  persist();
}

export function clearSession(): void {
  state.role = null;
  state.username = null;
  state.companies = [];
  state.company = null;
  sessionStorage.removeItem(STORAGE_KEY);
}

export const authStore = readonly(state);
