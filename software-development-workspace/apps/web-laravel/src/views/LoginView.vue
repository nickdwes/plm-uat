<script setup lang="ts">
import { ref } from "vue";
import { useRouter, useRoute } from "vue-router";
import { api, ApiError } from "../lib/api";
import { setAuth } from "../stores/auth";
import type { LoginResponse } from "../lib/types";

const router = useRouter();
const route  = useRoute();

const email    = ref("");
const password = ref("");
const loading  = ref(false);
const ssoLoading = ref(false);
const error    = ref((route.query.sso_error as string) ?? "");

async function login(): Promise<void> {
  if (!email.value.trim() || !password.value) {
    error.value = "Email and password are required.";
    return;
  }
  loading.value = true;
  error.value   = "";
  try {
    const res = await api<LoginResponse>("POST", "/api/login", {
      body: { email: email.value.trim(), password: password.value },
    });
    setAuth(res.user, res.organizations, res.token);
    void router.push({ name: "dashboard" });
  } catch (err) {
    if (err instanceof ApiError) {
      if (err.status === 422) {
        error.value = err.firstError ?? err.message;
      } else if (err.status === 401) {
        error.value = "Invalid email or password.";
      } else {
        error.value = err.message;
      }
    } else {
      error.value = "Connection failed. Is the backend running?";
    }
  } finally {
    loading.value = false;
  }
}

async function signInWithMicrosoft(): Promise<void> {
  ssoLoading.value = true;
  error.value = "";
  try {
    const res = await api<{ url: string }>("GET", "/api/auth/azure/redirect");
    // Full page navigation to Microsoft login
    window.location.href = res.url;
  } catch (err) {
    ssoLoading.value = false;
    error.value = err instanceof ApiError
      ? err.message
      : "Could not initiate Microsoft sign-in. Please try again.";
  }
}
</script>

<template>
  <main class="login-shell">
    <div class="login-card">
      <!-- Logo -->
      <div class="login-logo">
        <img src="../assets/logo.jpg" alt="Konvergenz" class="login-logo-img" />
      </div>

      <h1>Welcome back</h1>
      <p class="lead">Sign in to access your workspace.</p>

      <!-- Microsoft SSO -->
      <button
        class="btn-microsoft"
        :disabled="ssoLoading || loading"
        @click="signInWithMicrosoft"
        type="button"
      >
        <svg class="ms-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 21 21">
          <rect x="1"  y="1"  width="9" height="9" fill="#f25022"/>
          <rect x="11" y="1"  width="9" height="9" fill="#7fba00"/>
          <rect x="1"  y="11" width="9" height="9" fill="#00a4ef"/>
          <rect x="11" y="11" width="9" height="9" fill="#ffb900"/>
        </svg>
        {{ ssoLoading ? "Redirecting…" : "Sign in with Microsoft" }}
      </button>

      <div class="login-divider">
        <span>or sign in with email</span>
      </div>

      <form class="login-form" @submit.prevent="login">
        <label>
          Email address
          <input
            v-model="email"
            type="email"
            class="input"
            placeholder="you@example.com"
            autocomplete="email"
            required
          />
        </label>

        <label>
          Password
          <input
            v-model="password"
            type="password"
            class="input"
            placeholder="••••••••"
            autocomplete="current-password"
            required
          />
        </label>

        <p v-if="error" class="error">{{ error }}</p>

        <button type="submit" class="btn btn-primary" :disabled="loading || ssoLoading" style="width:100%">
          {{ loading ? "Signing in…" : "Sign in" }}
        </button>
      </form>
    </div>
  </main>
</template>
