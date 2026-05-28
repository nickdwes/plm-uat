/**
 * Minimal REST client for the Laravel backend (/api/v1/...).
 * Uses Sanctum Bearer token auth stored in authStore.laravelToken.
 */
import { authStore } from "../stores/auth";

export class LaravelApiError extends Error {
  constructor(
    public readonly status: number,
    message: string,
  ) {
    super(message);
    this.name = "LaravelApiError";
  }
}

function buildUrl(path: string): string {
  const base = (authStore.laravelBaseUrl || "").replace(/\/$/, "");
  const cleanPath = path.startsWith("/") ? path : `/${path}`;
  return `${base}${cleanPath}`;
}

function authHeaders(): HeadersInit {
  const headers: Record<string, string> = {
    Accept: "application/json",
  };
  if (authStore.laravelToken) {
    headers["Authorization"] = `Bearer ${authStore.laravelToken}`;
  }
  return headers;
}

export async function laravelGet<T>(path: string): Promise<T> {
  const resp = await fetch(buildUrl(path), {
    method: "GET",
    headers: authHeaders(),
  });

  if (!resp.ok) {
    const text = await resp.text().catch(() => resp.statusText);
    throw new LaravelApiError(resp.status, `HTTP ${resp.status}: ${text}`);
  }

  return resp.json() as Promise<T>;
}

export async function laravelPost<T>(path: string, body: unknown): Promise<T> {
  const resp = await fetch(buildUrl(path), {
    method: "POST",
    headers: { ...authHeaders(), "Content-Type": "application/json" },
    body: JSON.stringify(body),
  });

  if (!resp.ok) {
    const text = await resp.text().catch(() => resp.statusText);
    throw new LaravelApiError(resp.status, `HTTP ${resp.status}: ${text}`);
  }

  return resp.json() as Promise<T>;
}

export async function laravelPut<T>(path: string, body: unknown): Promise<T> {
  const resp = await fetch(buildUrl(path), {
    method: "PUT",
    headers: { ...authHeaders(), "Content-Type": "application/json" },
    body: JSON.stringify(body),
  });

  if (!resp.ok) {
    const text = await resp.text().catch(() => resp.statusText);
    throw new LaravelApiError(resp.status, `HTTP ${resp.status}: ${text}`);
  }

  return resp.json() as Promise<T>;
}

export async function laravelDelete<T = void>(path: string): Promise<T | void> {
  const resp = await fetch(buildUrl(path), {
    method: "DELETE",
    headers: authHeaders(),
  });

  if (!resp.ok) {
    const text = await resp.text().catch(() => resp.statusText);
    throw new LaravelApiError(resp.status, `HTTP ${resp.status}: ${text}`);
  }

  if (resp.status === 204) return;
  return resp.json() as Promise<T>;
}

export async function laravelUpload<T>(path: string, formData: FormData): Promise<T> {
  // Do NOT set Content-Type header — browser will set multipart/form-data with boundary.
  const headers: Record<string, string> = { Accept: "application/json" };
  if (authStore.laravelToken) {
    headers["Authorization"] = `Bearer ${authStore.laravelToken}`;
  }

  const resp = await fetch(buildUrl(path), {
    method: "POST",
    headers,
    body: formData,
  });

  if (!resp.ok) {
    const text = await resp.text().catch(() => resp.statusText);
    throw new LaravelApiError(resp.status, `HTTP ${resp.status}: ${text}`);
  }

  return resp.json() as Promise<T>;
}
