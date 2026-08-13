<script setup lang="ts">
/**
 * Setting the new password, from the link in the email.
 *
 * The token comes from the path and the address from the query string, both
 * put there by the backend. The address is not editable: the broker checks the
 * token against it, so letting somebody change it only produces a failure they
 * cannot explain.
 */
import { computed, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { api, ApiError } from "../lib/api";
import { clearAuth } from "../stores/auth";
import logoUrl from "../assets/logo.jpg";

const route  = useRoute();
const router = useRouter();

const token = String(route.params.token ?? "");
const email = String(route.query.email ?? "");

const password  = ref("");
const confirm   = ref("");
const showPw    = ref(false);
const loading   = ref(false);
const error     = ref("");
const done      = ref(false);

/** Mirrors Laravel's Password::defaults() — eight characters, that is all. */
const tooShort = computed(() => password.value.length > 0 && password.value.length < 8);
const mismatch = computed(() => confirm.value.length > 0 && confirm.value !== password.value);

async function submit(): Promise<void> {
  if (password.value.length < 8) {
    error.value = "Use at least eight characters.";
    return;
  }
  if (password.value !== confirm.value) {
    error.value = "The two passwords do not match.";
    return;
  }

  loading.value = true;
  error.value   = "";
  try {
    await api<{ status: string }>("POST", "/api/reset-password", {
      body: {
        token,
        email,
        password: password.value,
        password_confirmation: confirm.value,
      },
    });

    /*
     * The reset revoked every token server-side, including this browser's if
     * it had one. Clearing here stops the app carrying a dead token into the
     * login screen and bouncing on the first request.
     */
    clearAuth();
    done.value = true;
    setTimeout(() => void router.push({ name: "login" }), 2500);
  } catch (err) {
    if (err instanceof ApiError) {
      if (err.status === 429) {
        error.value = "Too many attempts. Wait a minute and try again.";
      } else if (err.status === 403) {
        error.value = "That account cannot sign in with a password. Ask your HR administrator.";
      } else {
        // The broker's own message covers expired, already used, and forged.
        error.value = err.firstError
          ?? "That link is no longer valid. Request a new one.";
      }
    } else {
      error.value = "Connection failed. Check your network and try again.";
    }
  } finally {
    loading.value = false;
  }
}
</script>

<template>
  <main class="auth-shell">
    <section class="auth-card">
      <img :src="logoUrl" alt="Konvergenz" class="auth-logo" />

      <!--
        A link opened without a token is a link that got mangled in transit —
        by an email client rewriting it, or by being copied without the tail.
        Saying so beats a form that fails on submit.
      -->
      <template v-if="!token || !email">
        <h1>That link is incomplete</h1>
        <p class="lead">
          Some email clients cut long links in half. Copy the whole address from
          the message, or request a new one.
        </p>
        <router-link class="back" :to="{ name: 'forgot-password' }">
          Request a new link
        </router-link>
      </template>

      <template v-else-if="!done">
        <h1>Set a new password</h1>
        <p class="lead">
          For <strong>{{ email }}</strong>. Anywhere you are already signed in
          will be signed out.
        </p>

        <form class="auth-form" novalidate @submit.prevent="submit">
          <div class="field">
            <label for="rp-password">New password</label>
            <div class="pw-wrap">
              <input
                id="rp-password"
                v-model="password"
                class="control"
                :type="showPw ? 'text' : 'password'"
                autocomplete="new-password"
              />
              <button type="button" class="pw-toggle" @click="showPw = !showPw">
                {{ showPw ? "Hide" : "Show" }}
              </button>
            </div>
            <p class="hint" :class="{ bad: tooShort }">At least eight characters.</p>
          </div>

          <div class="field">
            <label for="rp-confirm">Confirm new password</label>
            <input
              id="rp-confirm"
              v-model="confirm"
              class="control"
              :type="showPw ? 'text' : 'password'"
              autocomplete="new-password"
            />
            <p v-if="mismatch" class="hint bad">These do not match.</p>
          </div>

          <p v-if="error" class="error" role="alert">{{ error }}</p>

          <button type="submit" class="btn-submit" :disabled="loading">
            {{ loading ? "Saving…" : "Change password" }}
          </button>
        </form>

        <router-link class="back" :to="{ name: 'login' }">Back to sign in</router-link>
      </template>

      <div v-else class="sent" role="status">
        <h1>Password changed</h1>
        <p class="lead">
          Sign in with your new password. Taking you to the sign-in screen…
        </p>
        <router-link class="back" :to="{ name: 'login' }">Go there now</router-link>
      </div>
    </section>
  </main>
</template>

<style scoped>
/* Matches ForgotPasswordView — see docs/design-system.md for the tokens. */
@reference "../theme.css";

.auth-shell {
  @apply flex items-center justify-center;
  min-height: 100vh;
  min-height: 100dvh;
  padding: clamp(1.25rem, 4vw, 2.5rem);
  background: var(--color-surface-sunken);
}

.auth-card {
  @apply flex w-full flex-col;
  max-width: 27rem;
  gap: var(--space-2);
  padding: clamp(1.75rem, 5vw, 2.5rem);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  background: var(--color-surface);
}

.auth-logo {
  @apply h-auto object-contain;
  width: clamp(120px, 11vw, 150px);
  margin-bottom: var(--space-4);
}

.auth-card h1 {
  @apply font-semibold;
  font-size: clamp(1.375rem, 1.9vw, 1.5rem);
  line-height: 1.3;
  color: var(--color-text);
  margin: 0;
}

.lead {
  font-size: var(--text-sm-size);
  color: var(--color-text-light);
  margin: var(--space-2) 0 0;
}

.auth-form {
  @apply flex flex-col;
  gap: var(--space-4);
  margin-top: clamp(1.25rem, 3.5vh, 2rem);
}

.field { @apply flex flex-col; gap: var(--space-2); }

.field label {
  @apply font-semibold;
  font-size: var(--text-xs-size);
  color: var(--color-text);
}

.pw-wrap { @apply relative flex items-center; }

.pw-toggle {
  @apply absolute cursor-pointer border-none bg-transparent font-medium;
  right: var(--space-3);
  font-size: var(--text-xs-size);
  color: var(--color-brand-base);
}

.control {
  @apply w-full;
  font-family: inherit;
  font-size: var(--text-sm-size);
  padding: 0.625rem var(--space-3);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-md);
  background: var(--color-surface);
  color: var(--color-text);
  transition: border-color var(--transition-fast), box-shadow var(--transition-fast);
}

.control:focus {
  @apply outline-none;
  border-color: var(--color-brand-base);
  box-shadow: 0 0 0 3px var(--color-brand-subtle);
}

.hint {
  font-size: var(--text-xs-size);
  color: var(--color-text-light);
  margin: 0;
}

.hint.bad { color: var(--color-error); }

.error {
  font-size: var(--text-xs-size);
  color: var(--color-error);
  background: var(--color-error-light);
  border-radius: var(--radius-sm);
  padding: var(--space-3);
  margin: 0;
}

.btn-submit {
  @apply w-full cursor-pointer border-none font-semibold;
  font-family: inherit;
  font-size: var(--text-sm-size);
  padding: 0.6875rem var(--space-4);
  border-radius: var(--radius-md);
  background: var(--color-brand-base);
  color: var(--color-on-brand);
  transition: background var(--transition-fast);
}

.btn-submit:hover:not(:disabled) { background: var(--color-brand-hover); }
.btn-submit:disabled { @apply cursor-not-allowed opacity-60; }

.btn-submit:focus-visible {
  @apply outline-2 outline-offset-2;
  outline-color: var(--color-brand-hover);
}

.sent { @apply flex flex-col; gap: var(--space-2); }

.back {
  @apply font-medium no-underline;
  font-size: var(--text-sm-size);
  color: var(--color-brand-base);
  margin-top: clamp(1.25rem, 3.5vh, 2rem);
}

.back:hover { text-decoration: underline; }
</style>
