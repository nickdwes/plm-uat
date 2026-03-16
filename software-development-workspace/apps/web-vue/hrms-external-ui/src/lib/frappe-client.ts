/**
 * Frappe API client for the HRMS external UI.
 *
 * Features (Phase A):
 *  - API key + secret token auth with session cookie fallback.
 *  - Versioned endpoint convention via ``api_version`` header.
 *  - Request correlation IDs sent as ``X-Request-ID`` headers.
 *  - Structured error unwrapping from the HRMS API envelope.
 *  - Automatic retry on transient network failures (up to 2 retries).
 */

import type { ApiEnvelope } from "./types";

export interface FrappeAuthConfig {
  baseUrl: string;
  apiKey?: string;
  apiSecret?: string;
}

/** Error thrown when the HRMS API returns a structured error envelope. */
export class HrmsApiError extends Error {
  constructor(
    public readonly code: string,
    message: string,
    public readonly requestId?: string,
  ) {
    super(message);
    this.name = "HrmsApiError";
  }
}

// ---------------------------------------------------------------------------
// Internals
// ---------------------------------------------------------------------------

const API_VERSION = "1";
const MAX_RETRIES = 2;
const RETRY_DELAY_MS = 400;

function normalizeBaseUrl(baseUrl: string): string {
  return baseUrl.trim().replace(/\/$/, "");
}

function generateRequestId(): string {
  return crypto.randomUUID ? crypto.randomUUID() : Math.random().toString(36).slice(2);
}

function isRetryableError(status: number): boolean {
  // Retry on gateway/server errors but not on auth or client errors.
  return status === 502 || status === 503 || status === 504;
}

async function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function asUserFacingError(error: unknown): Error {
  if (error instanceof HrmsApiError || error instanceof Error) {
    return error;
  }
  return new Error("Unexpected error while calling the HRMS API.");
}

// ---------------------------------------------------------------------------
// Core request function
// ---------------------------------------------------------------------------

/**
 * Call a Frappe whitelisted method and return the typed data payload.
 *
 * Wraps the raw Frappe ``/api/method/`` endpoint, unwraps the HRMS API
 * envelope, and retries on transient failures.
 *
 * @param auth  Auth configuration (base URL, optional API key/secret).
 * @param method  Dotted Frappe method path, e.g. ``bitwyse_hrms_backend.api.health``.
 * @param params  Key-value POST body parameters.
 * @returns Resolved ``data`` field from the HRMS response envelope.
 * @throws {HrmsApiError} When the server returns a structured API error.
 * @throws {Error} On network failure or unexpected response shape.
 */
export async function callFrappeMethod<T>(
  auth: FrappeAuthConfig,
  method: string,
  params: Record<string, string | number | boolean | null | undefined> = {},
): Promise<T> {
  const baseUrl = normalizeBaseUrl(auth.baseUrl);
  if (!baseUrl) {
    throw new Error("VITE_FRAPPE_BASE_URL is required before API calls can run.");
  }

  const url = `${baseUrl}/api/method/${method}`;
  const requestId = generateRequestId();

  const body = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null) {
      body.append(key, String(value));
    }
  });

  const headers: HeadersInit = {
    Accept: "application/json",
    "X-API-Version": API_VERSION,
    "X-Request-ID": requestId,
    // Tells Frappe to skip the CSRF token check (required for cross-origin POST requests).
    "X-Frappe-CSRF-Token": "fetch",
  };

  if (auth.apiKey && auth.apiSecret) {
    headers.Authorization = `token ${auth.apiKey}:${auth.apiSecret}`;
  }

  let lastError: Error = new Error("Request did not execute.");

  for (let attempt = 0; attempt <= MAX_RETRIES; attempt++) {
    if (attempt > 0) {
      await sleep(RETRY_DELAY_MS * attempt);
    }

    try {
      const response = await fetch(url, {
        method: "POST",
        headers,
        body,
        credentials: "include",
      });

      if (isRetryableError(response.status) && attempt < MAX_RETRIES) {
        lastError = new Error(`HTTP ${response.status}: ${response.statusText}`);
        continue;
      }

      const payload = (await response.json()) as
        | ApiEnvelope<T>           // HRMS structured envelope
        | { message?: T; exc?: string }; // legacy Frappe envelope fallback

      // Handle HRMS structured envelope.
      if ("api_version" in payload) {
        const envelope = payload as ApiEnvelope<T>;
        if (envelope.error) {
          throw new HrmsApiError(
            envelope.error.code,
            envelope.error.message,
            envelope.request_id,
          );
        }
        if (envelope.data === undefined || envelope.data === null) {
          throw new Error("HRMS API response contained no data payload.");
        }
        return envelope.data;
      }

      // Fallback: legacy Frappe envelope (used by health endpoint).
      const legacyPayload = payload as { message?: unknown; exc?: string };
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
      }
      if (legacyPayload.exc) {
        throw new Error("Frappe returned an exception for this request.");
      }
      if (legacyPayload.message === undefined) {
        throw new Error("Frappe response did not include a message payload.");
      }
      // Frappe wraps all whitelisted method return values in a `message` key.
      // If that message itself is an HRMS structured envelope, unwrap it.
      const msg = legacyPayload.message;
      if (msg && typeof msg === "object" && "api_version" in msg && "data" in msg) {
        const inner = msg as ApiEnvelope<T>;
        if (inner.error) {
          throw new HrmsApiError(inner.error.code, inner.error.message, inner.request_id);
        }
        if (inner.data === undefined || inner.data === null) {
          throw new Error("HRMS API response contained no data payload.");
        }
        return inner.data;
      }
      return msg as T;
    } catch (error) {
      if (error instanceof HrmsApiError) {
        throw error; // Structured errors are not retried.
      }
      lastError = asUserFacingError(error);
      if (attempt >= MAX_RETRIES) {
        throw lastError;
      }
    }
  }

  throw lastError;
}
