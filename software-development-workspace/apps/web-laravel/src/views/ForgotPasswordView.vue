<script setup lang="ts">
/**
 * "I have forgotten my password."
 *
 * The reply is the same whether or not the address is on file, because the
 * backend deliberately will not say — otherwise this page answers "does this
 * person work here?" for anyone who asks. So the confirmation is worded to
 * carry that: it tells you what to do if nothing arrives, rather than implying
 * something is definitely on its way.
 */
import { ref } from "vue";
import { api, ApiError } from "../lib/api";
import logoUrl from "../assets/logo.jpg";

const email   = ref("");
const loading = ref(false);
const error   = ref("");
const sent    = ref(false);

async function submit(): Promise<void> {
  if (!email.value.trim()) {
    error.value = "Enter the email address you sign in with.";
    return;
  }
  loading.value = true;
  error.value   = "";
  try {
    await api<{ status: string }>("POST", "/api/forgot-password", {
      body: { email: email.value.trim() },
    });
    sent.value = true;
  } catch (err) {
    if (err instanceof ApiError) {
      // 429 is the throttle. Saying so is better than a generic failure,
      // which reads as "the system is broken" and produces a support ticket.
      error.value = err.status === 429
        ? "Too many attempts. Wait a minute and try again."
        : err.firstError ?? err.message;
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

      <template v-if="!sent">
        <h1>Reset your password</h1>
        <p class="lead">
          Give us the address you sign in with and we will send a link to set a
          new password.
        </p>

        <form class="auth-form" novalidate @submit.prevent="submit">
          <div class="field">
            <label for="fp-email">Email address</label>
            <input
              id="fp-email"
              v-model="email"
              class="control"
              type="email"
              autocomplete="username"
              placeholder="you@konvergenz.co.ke"
            />
          </div>

          <p v-if="error" class="error" role="alert">{{ error }}</p>

          <button type="submit" class="btn-submit" :disabled="loading">
            {{ loading ? "Sending…" : "Send reset link" }}
          </button>
        </form>
      </template>

      <!--
        Shown for a real address and an unknown one alike. The wording has to
        be true in both cases, which is why it says "if" rather than "we have".
      -->
      <div v-else class="sent" role="status">
        <h1>Check your email</h1>
        <p class="lead">
          If <strong>{{ email.trim() }}</strong> belongs to an account, a reset
          link is on its way. It expires in an hour.
        </p>
        <p class="lead">
          Nothing after a few minutes? Check the address for a typo, look in
          spam, or ask your HR administrator — accounts that sign in with
          Microsoft do not have a password to reset.
        </p>
      </div>

      <router-link class="back" :to="{ name: 'login' }">Back to sign in</router-link>
    </section>
  </main>
</template>

<style scoped>
/*
 * A single centred card. Built on the Konvergenz tokens — see
 * docs/design-system.md.
 */
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

.control::placeholder { color: var(--color-text-light); }

.control:focus {
  @apply outline-none;
  border-color: var(--color-brand-base);
  box-shadow: 0 0 0 3px var(--color-brand-subtle);
}

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
