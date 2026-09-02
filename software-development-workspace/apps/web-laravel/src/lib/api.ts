/**
 * Laravel Sanctum API client — Bearer token auth.
 *
 * Flow:
 *   1. POST /login  → receives { token, user, organizations }
 *   2. Token stored in auth store (sessionStorage).
 *   3. Every subsequent request sends: Authorization: Bearer <token>
 *   No CSRF cookies, no credentials:include, no domain config required.
 */

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    message: string,
    public readonly errors?: Record<string, string[]>,
    /**
     * A machine-checkable reason distinct from the human message — right now
     * only ever "step_up_required", set on a 401 that means "prove it is you
     * again", not "your session is dead". Callers that care check this rather
     * than pattern-matching the message text.
     */
    public readonly code?: string,
    /**
     * The whole error body, for the handful of refusals that carry data the
     * caller must act on rather than merely display — the vendor duplicate
     * guard hands back the candidate records it found. Kept separate from
     * `errors`, which is Laravel's field→messages map and nothing else.
     */
    public readonly payload?: Record<string, unknown>,
  ) {
    super(message);
    this.name = "ApiError";
  }

  /**
   * The single way to ask "was this a permission refusal?".
   *
   * Views used to match `status === 403` in four different phrasings; this
   * names the question once. The global handler has already refetched the
   * caller's permission list by the time anybody reads this, so the nav and
   * tabs are reconciling themselves — a catcher usually only needs to decide
   * between a quiet degrade (a narrower scope, a "not shown to you" state)
   * and surfacing the refusal.
   */
  get forbidden(): boolean {
    return this.status === 403;
  }

  /** Returns the first individual validation error message, if any. */
  get firstError(): string | undefined {
    if (!this.errors) return undefined;
    const first = Object.keys(this.errors)[0];
    return first ? this.errors[first][0] : undefined;
  }
}

import { clearAuth } from "../stores/auth";
import { getToken } from "../stores/auth";

// ---------------------------------------------------------------------------
// Internals
// ---------------------------------------------------------------------------

const BASE = (import.meta.env.VITE_LARAVEL_API_URL ?? "").replace(/\/$/, "");

// ---------------------------------------------------------------------------
// Public API
// ---------------------------------------------------------------------------

type Method = "GET" | "POST" | "PUT" | "PATCH" | "DELETE";

export interface RequestOptions {
  body?: unknown;
  /** Multipart form data (for file uploads). Mutually exclusive with body. */
  formData?: FormData;
  /** Organization slug — sent as X-Organization header for org-scoped routes. */
  org?: string | null;
  /** Extra query params appended to the URL. */
  params?: Record<string, string | number | boolean | undefined | null>;
}

/**
 * Make an authenticated request to the Laravel API.
 * Throws `ApiError` on non-2xx responses.
 */
export async function api<T>(
  method: Method,
  path: string,
  options: RequestOptions = {},
): Promise<T> {
  const headers: Record<string, string> = {
    Accept: "application/json",
  };

  const token = getToken();
  if (token) {
    headers["Authorization"] = `Bearer ${token}`;
  }

  if (options.body !== undefined) {
    headers["Content-Type"] = "application/json";
  }
  // Note: do NOT set Content-Type for FormData — browser sets it with boundary automatically

  if (options.org) {
    headers["X-Organization"] = options.org;
  }

  // Build query string
  let url = `${BASE}${path}`;
  if (options.params) {
    const qs = Object.entries(options.params)
      .filter(([, v]) => v !== undefined && v !== null)
      .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`)
      .join("&");
    if (qs) url += `?${qs}`;
  }

  const res = await fetch(url, {
    method,
    headers,
    body: options.formData ?? (options.body !== undefined ? JSON.stringify(options.body) : undefined),
  });

  if (res.status === 204) return undefined as T;

  const data: unknown = await res.json().catch(() => ({ message: res.statusText }));

  if (!res.ok) {
    const d = data as { message?: string; errors?: Record<string, string[]>; code?: string };

    /*
     * A dead session should end the session, not decorate the page — but a
     * 401 asking for a fresh step-up code is not a dead session, and treating
     * it as one would sign somebody out the moment they opened a page that
     * needs one. The two have to stay distinguishable here, in the one place
     * every request passes through, rather than in each caller.
     */
    if (res.status === 403) {
      // The server refused something the UI offered: the client's permission
      // copy is stale (a role changed mid-session). Reconcile it so the nav
      // and tabs stop advertising the door. Deferred import — api.ts is
      // imported by the store, and a top-level cycle would evaluate half a
      // module.
      void import("../stores/permissions").then((m) => m.fetchPermissions(true));
    }

    if (res.status === 401 && d.code !== "step_up_required") {
      clearAuth();

      if (!window.location.hash.startsWith("#/login")) {
        window.location.hash = "#/login";
      }
    }

    throw new ApiError(res.status, d.message ?? res.statusText, d.errors, d.code, d);
  }

  return data as T;
}
