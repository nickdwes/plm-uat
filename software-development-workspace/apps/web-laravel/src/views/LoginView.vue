<script setup lang="ts">
import { ref } from "vue";
import { useRouter } from "vue-router";
import { api, ApiError } from "../lib/api";
import { setAuth } from "../stores/auth";
import type { LoginResponse } from "../lib/types";

const router = useRouter();

const email    = ref("");
const password = ref("");
const loading  = ref(false);
const error    = ref("");

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

        <button type="submit" class="btn btn-primary" :disabled="loading" style="width:100%">
          {{ loading ? "Signing in…" : "Sign in" }}
        </button>
      </form>
    </div>
  </main>
</template>
