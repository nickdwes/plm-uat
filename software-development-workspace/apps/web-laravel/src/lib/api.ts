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
  ) {
    super(message);
    this.name = "ApiError";
  }

  /** Returns the first individual validation error message, if any. */
  get firstError(): string | undefined {
    if (!this.errors) return undefined;
    const first = Object.keys(this.errors)[0];
    return first ? this.errors[first][0] : undefined;
  }
}

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
    const d = data as { message?: string; errors?: Record<string, string[]> };
    throw new ApiError(res.status, d.message ?? res.statusText, d.errors);
  }

  return data as T;
}
