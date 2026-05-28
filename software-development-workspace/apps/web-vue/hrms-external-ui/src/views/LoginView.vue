<script setup lang="ts">
import { ref } from "vue";
import { useRouter } from "vue-router";
import { callFrappeMethod, type FrappeAuthConfig } from "../lib/frappe-client";
import { authStore, setConnection, setSession, setLaravelConnection } from "../stores/auth";

const router = useRouter();

const baseUrl = ref(authStore.baseUrl);
const apiKey = ref(authStore.apiKey);
const apiSecret = ref(authStore.apiSecret);
const laravelBaseUrl = ref(authStore.laravelBaseUrl);
const laravelToken = ref(authStore.laravelToken);
const company = ref("");
const role = ref("HRMS Company HR");
const loading = ref(false);
const error = ref("");

const ROLES = [
  "HRMS Global HR",
  "HRMS Company HR",
  "HRMS Manager",
  "HRMS Employee",
];

function authConfig(): FrappeAuthConfig {
  return {
    baseUrl: baseUrl.value,
    apiKey: apiKey.value.trim() || undefined,
    apiSecret: apiSecret.value.trim() || undefined,
  };
}

async function connect(): Promise<void> {
  if (!baseUrl.value.trim()) {
    error.value = "Enter the Frappe backend URL.";
    return;
  }

  loading.value = true;
  error.value = "";

  try {
    // Step 1: Probe connectivity using the guest-accessible health endpoint.
    // Do NOT send credentials here — Frappe rejects invalid tokens with 401
    // even on guest endpoints, causing a misleading error on first connect.
    await callFrappeMethod<{ status: string }>(
      { baseUrl: baseUrl.value },
      "bitwyse_hrms_backend.api.health",
      {},
    );

    // Step 2: Validate credentials and fetch company list.
    // Let auth errors propagate so the user sees a clear message.
    setConnection(baseUrl.value, apiKey.value, apiSecret.value);

    let companies: string[] = [];
    const result = await callFrappeMethod<{ items: { name: string }[] }>(
      authConfig(),
      "bitwyse_hrms_backend.api.list_companies",
      {},
    );
    companies = result.items.map((c) => c.name);
    if (company.value && !companies.includes(company.value)) {
      companies.push(company.value);
    }

    setSession(role.value, apiKey.value || "guest", companies, (company.value || companies[0]) ?? null);
    setLaravelConnection(laravelBaseUrl.value.trim(), laravelToken.value.trim());

    void router.push({ name: "dashboard" });
  } catch (err) {
    if (err instanceof Error && err.message.startsWith("HTTP 401")) {
      error.value = "Invalid API key or secret. Please check your credentials.";
    } else {
      error.value = err instanceof Error ? err.message : "Connection failed.";
    }
  } finally {
    loading.value = false;
  }
}
</script>

<template>
  <main class="login-shell">
    <div class="login-card">
      <h1>Bitwyse HRMS</h1>
      <p class="lead">Connect to your Frappe backend to get started.</p>

      <form class="login-form" @submit.prevent="connect">
        <label>
          Backend URL
          <span class="field-hint">Leave blank when running via the Vite dev server — it proxies <code>/api</code> to <code>localhost:8080</code> automatically. Fill in for a remote backend (e.g. <code>https://hrms.example.com</code>).</span>
          <input
            v-model="baseUrl"
            type="text"
            placeholder="Leave blank for local dev (proxied) — or enter remote URL"
          />
        </label>

        <label>
          API Key
          <input v-model="apiKey" type="text" placeholder="Leave blank for session auth" />
        </label>

        <label>
          API Secret
          <input v-model="apiSecret" type="password" placeholder="Leave blank for session auth" />
        </label>

        <label>
          Laravel API URL
          <span class="field-hint">Base URL of the Laravel backend (e.g. <code>https://api.hrms.example.com</code>). Required for the Compliance module.</span>
          <input
            v-model="laravelBaseUrl"
            type="text"
            placeholder="https://api.hrms.example.com"
          />
        </label>

        <label>
          Laravel API Token
          <span class="field-hint">Your Sanctum personal access token from the Laravel backend.</span>
          <input v-model="laravelToken" type="password" placeholder="Sanctum Bearer token" />
        </label>

        <label>
          Company (optional — leave blank for default)
          <input v-model="company" type="text" placeholder="e.g. Bitwyse Ltd" />
        </label>

        <label>
          Your HRMS Role
          <select v-model="role">
            <option v-for="r in ROLES" :key="r" :value="r">{{ r }}</option>
          </select>
        </label>

        <p v-if="error" class="error">{{ error }}</p>

        <button type="submit" class="primary" :disabled="loading">
          {{ loading ? "Connecting…" : "Connect" }}
        </button>
      </form>
    </div>
  </main>
</template>
