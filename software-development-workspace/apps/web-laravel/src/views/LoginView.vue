<script setup lang="ts">
import { ref } from "vue";
import { useRouter, useRoute } from "vue-router";
import { api, ApiError } from "../lib/api";
import { setAuth, setPersistence } from "../stores/auth";
import type { LoginResponse } from "../lib/types";
import logoUrl from "../assets/logo.jpg";

const router = useRouter();
const route  = useRoute();

const email        = ref("");
const password     = ref("");
const remember     = ref(false);
const showPassword = ref(false);
const loading      = ref(false);
const ssoLoading   = ref(false);
const error        = ref((route.query.sso_error as string) ?? "");

const capsLock = ref(false);

const year = new Date().getFullYear();

/*
 * Caps Lock is the commonest reason a correct password is rejected, and the
 * field shows the same row of dots either way. Read off the event rather than
 * tracked as state: it is only ever true while the field has focus, and a tab
 * away leaves nothing stale behind.
 */
function trackCapsLock(event: KeyboardEvent): void {
  capsLock.value = event.getModifierState?.("CapsLock") ?? false;
}

/*
 * The previous attempt's failure stops being the truth the moment they start
 * correcting it. Leaving it up means the error the user is reading no longer
 * describes what is in the box.
 */
function clearError(): void {
  if (error.value) error.value = "";
}

async function login(): Promise<void> {
  if (!email.value.trim() || !password.value) {
    error.value = "Enter your email and password to continue.";
    return;
  }
  loading.value = true;
  error.value   = "";
  try {
    const res = await api<LoginResponse>("POST", "/api/login", {
      body: { email: email.value.trim(), password: password.value },
    });
    // Decide where the session lives before writing it.
    setPersistence(remember.value);
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

    <!-- ── Left: credentials ───────────────────────────────────────────── -->
    <section class="login-pane">
      <img :src="logoUrl" alt="Konvergenz" class="login-logo" />

      <div class="login-body">
        <!--
          Below 1024px the brand panel is hidden, and without this the page
          collapsed to a bare form on a flat surface with the logo adrift in
          the corner. The band carries the identity across that breakpoint.
        -->
        <p class="brand-band" aria-hidden="true">
          Effortlessly manage your people and operations
        </p>

        <div class="login-card">
        <h1>Welcome Back</h1>
        <p class="lead">Enter your credentials to proceed to the system</p>

        <!--
          Above the fields, not below the submit button. A sign-in failure is
          the reason the page is still on screen; it is read first, and it is
          the same region whether the attempt was SSO or a password.
        -->
        <p v-if="error" class="error" role="alert" aria-live="assertive">
          <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M18 10A8 8 0 112 10a8 8 0 0116 0zm-8-4a1 1 0 011 1v3a1 1 0 11-2 0V7a1 1 0 011-1zm0 8a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" />
          </svg>
          <span>{{ error }}</span>
        </p>

        <button
          class="btn-microsoft"
          type="button"
          :disabled="ssoLoading || loading"
          @click="signInWithMicrosoft"
        >
          <svg class="ms-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 21 21" aria-hidden="true">
            <rect x="1"  y="1"  width="9" height="9" fill="#f25022" />
            <rect x="11" y="1"  width="9" height="9" fill="#7fba00" />
            <rect x="1"  y="11" width="9" height="9" fill="#00a4ef" />
            <rect x="11" y="11" width="9" height="9" fill="#ffb900" />
          </svg>
          {{ ssoLoading ? "Redirecting…" : "Sign in with Microsoft" }}
        </button>

        <div class="login-divider"><span>or</span></div>

        <form class="login-form" @submit.prevent="login">
          <div class="field">
            <label for="login-email">Email</label>
            <input
              id="login-email"
              v-model="email"
              type="email"
              class="control"
              placeholder="Email Address"
              autocomplete="email"
              autofocus
              :aria-invalid="error ? 'true' : undefined"
              required
              @input="clearError"
            />
          </div>

          <div class="field">
            <label for="login-password">Password</label>
            <div class="control-wrap">
              <input
                id="login-password"
                v-model="password"
                :type="showPassword ? 'text' : 'password'"
                class="control has-affix"
                placeholder="Password"
                autocomplete="current-password"
                :aria-invalid="error ? 'true' : undefined"
                required
                @input="clearError"
                @keyup="trackCapsLock"
                @keydown="trackCapsLock"
                @blur="capsLock = false"
              />
              <button
                type="button"
                class="affix"
                :aria-label="showPassword ? 'Hide password' : 'Show password'"
                :aria-pressed="showPassword"
                @click="showPassword = !showPassword"
              >
                <svg v-if="!showPassword" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                  <path d="M10 12a2 2 0 100-4 2 2 0 000 4z" />
                  <path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd" />
                </svg>
                <svg v-else viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                  <path fill-rule="evenodd" d="M3.707 2.293a1 1 0 00-1.414 1.414l14 14a1 1 0 001.414-1.414l-1.473-1.473A10.014 10.014 0 0019.542 10C18.268 5.943 14.478 3 10 3a9.958 9.958 0 00-4.512 1.074l-1.78-1.781zm4.261 4.26l1.514 1.515a2.003 2.003 0 012.45 2.45l1.514 1.514a4 4 0 00-5.478-5.478z" clip-rule="evenodd" />
                  <path d="M12.454 16.697L9.75 13.992a4 4 0 01-3.742-3.741L2.335 6.578A9.98 9.98 0 00.458 10c1.274 4.057 5.065 7 9.542 7 .847 0 1.669-.105 2.454-.303z" />
                </svg>
              </button>
            </div>
            <p v-if="capsLock" class="caps" role="status">Caps Lock is on.</p>
          </div>

          <div class="row-between">
            <label class="remember">
              <input v-model="remember" type="checkbox" />
              <span>Remember me</span>
            </label>
            <router-link class="link" :to="{ name: 'forgot-password' }">
              Forgot Your Password?
            </router-link>
          </div>

          <button type="submit" class="btn-submit" :disabled="loading || ssoLoading">
            {{ loading ? "Signing in…" : "Log In" }}
          </button>
        </form>
        </div>
      </div>

      <footer class="login-foot">
        <span>Copyright © {{ year }} Konvergenz Networking Solutions</span>
        <a href="https://konvergenz.co.ke/privacy" target="_blank" rel="noopener noreferrer">Privacy Policy</a>
      </footer>
    </section>

    <!-- ── Right: brand panel ──────────────────────────────────────────── -->
    <aside class="login-brand" aria-hidden="true">
      <div class="brand-copy">
        <h2>Effortlessly manage your people and operations</h2>
        <p>Sign in to access your HR workspace and stay on top of your team.</p>
      </div>

      <!-- Illustrative preview of the dashboard. Static by design. -->
      <div class="preview">
        <div class="preview-head">
          <span class="preview-title">Pending Approvals</span>
          <span class="preview-pill">4 new</span>
        </div>

        <div class="preview-cards">
          <article class="pcard">
            <span class="flag flag-amber">Leave</span>
            <h3>Annual leave request</h3>
            <p>Awaiting line manager sign-off</p>
            <div class="pmeta"><div class="pbar"><i style="width:62%"></i></div><span>62%</span></div>
          </article>
          <article class="pcard">
            <span class="flag flag-teal">Onboarding</span>
            <h3>New hire checklist</h3>
            <p>Documents and induction pending</p>
            <div class="pmeta"><div class="pbar"><i style="width:38%"></i></div><span>38%</span></div>
          </article>
        </div>

        <div class="preview-tabs">
          <span class="tab is-active">To Do</span>
          <span class="tab">In progress</span>
          <span class="tab">Completed</span>
        </div>

        <table class="preview-table">
          <thead>
            <tr><th>Task</th><th>Due</th><th>Owner</th></tr>
          </thead>
          <tbody>
            <tr><td>Confirm probation review</td><td>21 Feb</td><td><span class="who">AM</span></td></tr>
            <tr><td>Approve timesheet batch</td><td>22 Feb</td><td><span class="who">JK</span></td></tr>
            <tr><td>Upload signed contract</td><td>24 Feb</td><td><span class="who">RW</span></td></tr>
          </tbody>
        </table>
      </div>
    </aside>
  </main>
</template>

<style scoped>
/*
 * Split sign-in: credentials left, brand panel right.
 * Built on the Konvergenz tokens — see docs/design-system.md. No literal
 * colours except the Microsoft logo squares, which are brand assets of
 * Microsoft and must not be re-tinted.
 */
@reference "../theme.css";

.login-shell {
  @apply grid;
  /* dvh tracks mobile browser chrome as it collapses; vh is the fallback. */
  min-height: 100vh;
  min-height: 100dvh;
  grid-template-columns: minmax(0, 5fr) minmax(0, 6fr);
  background: var(--color-surface-sunken);
}

/* ── Left pane ─────────────────────────────────────────────────────────── */
.login-pane {
  @apply flex flex-col justify-between;
  /* Padding tracks the viewport so the form never crowds the edge on a
     narrow laptop, nor drifts far from it on a wide display. */
  padding: clamp(1.25rem, 3vh, 2rem) clamp(1.25rem, 4vw, 3.5rem);
  gap: clamp(1rem, 4vh, 2.5rem);
}

.login-logo {
  @apply h-auto object-contain;
  width: clamp(120px, 11vw, 150px);
}

.login-body {
  @apply mx-auto flex w-full flex-col justify-center;
  max-width: 27rem;
  flex: 1;
}

/*
 * The form used to sit directly on the page background — same colour, no
 * edge — so at any width where the brand panel was absent it read as an
 * unstyled document rather than a sign-in. The card gives it an edge to be
 * inside of.
 */
.login-card {
  @apply w-full;
  padding: clamp(1.5rem, 3.5vh, 2.25rem) clamp(1.25rem, 2.5vw, 2rem);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-2xl);
  background: var(--color-surface-raised);
  box-shadow: var(--shadow-md);
}

/* Hidden wherever the brand panel itself is visible — see the 1024px rule. */
.brand-band {
  @apply hidden font-semibold;
  font-size: var(--text-sm-size);
  line-height: 1.4;
  text-wrap: balance;
  margin: 0 0 var(--space-4);
  padding: var(--space-4) var(--space-5);
  border-radius: var(--radius-2xl);
  background: linear-gradient(150deg, var(--color-brand-hover), var(--color-brand-base) 70%);
  color: var(--color-on-brand);
}

.login-body h1 {
  @apply font-semibold;
  font-size: clamp(1.375rem, 1.9vw, 1.5rem);
  line-height: 1.3;
  color: var(--color-text);
  margin: 0;
}

.lead {
  font-size: var(--text-sm-size);
  color: var(--color-text-light);
  margin: var(--space-2) 0 clamp(1.25rem, 3.5vh, 2rem);
}

/* ── Microsoft SSO ─────────────────────────────────────────────────────── */
.btn-microsoft {
  @apply flex w-full cursor-pointer items-center justify-center gap-3 font-medium;
  font-size: var(--text-sm-size);
  padding: 0.625rem var(--space-4);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-md);
  background: var(--color-surface);
  color: var(--color-text);
  transition: background var(--transition-fast), border-color var(--transition-fast);
}

.btn-microsoft:hover:not(:disabled) {
  background: var(--color-surface-sunken);
  border-color: var(--color-text-light);
}

.btn-microsoft:disabled { @apply cursor-not-allowed opacity-60; }

.ms-icon { @apply size-5 shrink-0; }

.login-divider {
  @apply flex items-center gap-3;
  margin: clamp(0.875rem, 2.5vh, 1.25rem) 0;
  font-size: var(--text-xs-size);
  color: var(--color-text-light);
}

.login-divider::before,
.login-divider::after {
  @apply h-px flex-1;
  content: '';
  background: var(--color-border);
}

/* ── Form ──────────────────────────────────────────────────────────────── */
.login-form { @apply flex flex-col; gap: clamp(0.75rem, 2vh, 1rem); }

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

.control-wrap { @apply relative; }
.has-affix { padding-right: var(--space-10); }

.affix {
  @apply absolute top-1/2 flex -translate-y-1/2 cursor-pointer items-center border-none bg-transparent p-1;
  right: var(--space-2);
  color: var(--color-text-light);
  border-radius: var(--radius-sm);
}

.affix:hover { color: var(--color-text-muted); }
.affix svg { @apply size-5; }

.affix:focus-visible,
.link:focus-visible,
.remember input:focus-visible {
  @apply outline-2 outline-offset-2;
  outline-color: var(--color-brand-base);
}

.row-between { @apply flex items-center justify-between gap-3; }

.remember {
  @apply flex cursor-pointer items-center gap-2;
  font-size: var(--text-xs-size);
  color: var(--color-text-muted);
}

.remember input {
  @apply size-4 cursor-pointer;
  accent-color: var(--color-brand-base);
}

.link {
  @apply cursor-pointer border-none bg-transparent p-0 font-medium underline-offset-2;
  font-family: inherit;
  font-size: var(--text-xs-size);
  color: var(--color-brand-base);
}

.link:hover { @apply underline; color: var(--color-brand-hover); }

.hint {
  font-size: var(--text-xs-size);
  color: var(--color-text-muted);
  background: var(--color-surface-sunken);
  border-left: 3px solid var(--color-info);
  border-radius: 0 var(--radius-sm) var(--radius-sm) 0;
  padding: var(--space-3);
  margin: 0;
}

.error {
  @apply flex items-start gap-2;
  font-size: var(--text-xs-size);
  color: var(--color-error);
  background: var(--color-error-light);
  border-left: 3px solid var(--color-error);
  border-radius: 0 var(--radius-sm) var(--radius-sm) 0;
  padding: var(--space-3);
  margin: 0 0 var(--space-4);
}

.error svg { @apply size-4 shrink-0; margin-top: 1px; }

/* A warning about the keyboard, not about what was typed — so it is muted
   rather than red, and it is a status: it appears without an attempt. */
.caps {
  @apply flex items-center gap-1.5;
  font-size: var(--text-xs-size);
  color: var(--color-warning);
  margin: var(--space-1) 0 0;
}

.control[aria-invalid='true'] { border-color: var(--color-error); }

.control[aria-invalid='true']:focus {
  border-color: var(--color-error);
  box-shadow: 0 0 0 3px var(--color-error-light);
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
  margin-top: var(--space-1);
}

.btn-submit:hover:not(:disabled) { background: var(--color-brand-hover); }
.btn-submit:disabled { @apply cursor-not-allowed opacity-60; }

.btn-submit:focus-visible {
  @apply outline-2 outline-offset-2;
  outline-color: var(--color-brand-hover);
}

.login-foot {
  @apply flex flex-wrap items-center justify-between gap-2;
  font-size: var(--text-xs-size);
  color: var(--color-text-light);
}

.login-foot a { color: var(--color-text-light); text-decoration: none; }
.login-foot a:hover { color: var(--color-brand-base); @apply underline; }

/* ── Right brand panel ─────────────────────────────────────────────────── */
.login-brand {
  @apply relative flex flex-col justify-center overflow-hidden;
  margin: var(--space-4) var(--space-4) var(--space-4) 0;
  padding: clamp(2rem, 6vh, 4rem) clamp(1.75rem, 4vw, 3rem);
  border-radius: var(--radius-3xl);
  background: linear-gradient(150deg, var(--color-brand-hover), var(--color-brand-base) 70%);
  color: var(--color-on-brand);
}

/* Soft depth — two oversized circles bled off the edges. Sized in vw so
   they stay proportional instead of dominating a narrow panel. */
.login-brand::before,
.login-brand::after {
  @apply pointer-events-none absolute rounded-full;
  content: '';
  background: rgb(255 255 255 / 0.07);
}
.login-brand::before {
  width: clamp(16rem, 26vw, 26rem);
  height: clamp(16rem, 26vw, 26rem);
  top: clamp(-9rem, -9vw, -5rem);
  right: clamp(-7rem, -7vw, -4rem);
}
.login-brand::after {
  width: clamp(12rem, 20vw, 20rem);
  height: clamp(12rem, 20vw, 20rem);
  bottom: clamp(-8rem, -8vw, -5rem);
  left: clamp(-6rem, -6vw, -3rem);
}

/* Copy and preview share one 44rem column, centred in the panel. Both need
   the same width and the same centring or their left edges won't line up. */
.brand-copy {
  @apply relative w-full;
  max-width: 44rem;
  margin-inline: auto;
}

.brand-copy h2 {
  @apply font-bold;
  /* Scales between display-xs and display-md across the panel's range. */
  font-size: clamp(1.5rem, 2.6vw, 2.25rem);
  line-height: 1.25;
  letter-spacing: var(--tracking-display);
  text-wrap: balance;
  margin: 0;
}

.brand-copy p {
  font-size: clamp(0.875rem, 1.1vw, 1rem);
  color: rgb(255 255 255 / 0.82);
  margin: var(--space-3) 0 0;
  max-width: 34rem;
}

/* ── Dashboard preview ─────────────────────────────────────────────────── */
.preview {
  @apply relative w-full;
  /* Capped so the table's columns stay grouped. Unbounded, the card follows
     the panel width and on a 1920 display the due/owner columns drift far
     from the task they belong to. */
  max-width: 44rem;
  margin-inline: auto;
  margin-top: clamp(1.5rem, 5vh, 2.5rem);
  padding: clamp(0.875rem, 1.4vw, 1rem);
  border-radius: var(--radius-2xl);
  background: var(--color-surface);
  color: var(--color-text);
  box-shadow: var(--shadow-lg);
}

.preview-head { @apply flex items-center justify-between; margin-bottom: var(--space-3); }

.preview-title {
  @apply font-semibold;
  font-size: var(--text-sm-size);
}

.preview-pill {
  @apply rounded-full font-semibold;
  font-size: 0.6875rem;
  padding: 2px var(--space-2);
  background: var(--color-brand-light);
  color: var(--color-brand-base);
}

.preview-cards { @apply grid grid-cols-2; gap: var(--space-3); }

.pcard {
  padding: var(--space-3);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
}

.flag {
  @apply inline-block rounded font-semibold uppercase;
  font-size: 0.625rem;
  letter-spacing: 0.06em;
  padding: 2px 6px;
}
.flag-amber { background: var(--color-warning-light); color: var(--color-warning); }
.flag-teal  { background: var(--color-success-light); color: var(--color-success); }

.pcard h3 {
  @apply font-semibold;
  font-size: var(--text-xs-size);
  margin: var(--space-2) 0 2px;
}

.pcard p {
  font-size: 0.6875rem;
  color: var(--color-text-light);
  margin: 0 0 var(--space-3);
}

.pmeta { @apply flex items-center gap-2; font-size: 0.625rem; color: var(--color-text-light); }

.pbar { @apply h-1 flex-1 overflow-hidden rounded-full; background: var(--color-neutral-200); }
.pbar i { @apply block h-full rounded-full; background: var(--color-brand-base); }

.preview-tabs {
  @apply flex gap-4;
  margin: var(--space-4) 0 0;
  border-bottom: 1px solid var(--color-border);
}

.tab {
  font-size: var(--text-xs-size);
  color: var(--color-text-light);
  padding-bottom: var(--space-2);
  border-bottom: 2px solid transparent;
}

.tab.is-active {
  @apply font-semibold;
  color: var(--color-brand-base);
  border-bottom-color: var(--color-brand-base);
}

.preview-table { @apply w-full; border-collapse: collapse; }

.preview-table th {
  @apply text-left font-semibold uppercase;
  font-size: 0.625rem;
  letter-spacing: 0.06em;
  color: var(--color-text-light);
  padding: var(--space-3) 0 var(--space-2);
}

.preview-table td {
  font-size: var(--text-xs-size);
  padding: var(--space-2) 0;
  border-top: 1px solid var(--color-border);
}

.preview-table td:nth-child(2) { color: var(--color-text-light); }
.preview-table td:last-child { @apply text-right; }

.who {
  @apply inline-flex size-6 items-center justify-center rounded-full font-semibold;
  font-size: 0.625rem;
  background: var(--color-brand-light);
  color: var(--color-brand-base);
}

/* ── Responsive ────────────────────────────────────────────────────────── */

/* Wide displays: give the brand panel the extra room rather than
   stretching the form, which has a fixed comfortable reading width. */
@media (min-width: 1600px) {
  .login-shell { grid-template-columns: minmax(0, 4fr) minmax(0, 6fr); }
}

/* Small laptops: even the split, so neither column is starved. */
@media (max-width: 1280px) {
  .login-shell { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); }
  .preview-cards { @apply grid-cols-1; }
  /* Two stacked cards plus a table is too tall here — one is enough to
     convey the idea. */
  .pcard:nth-child(2) { @apply hidden; }
}

/* Tablet and below: the brand panel is decorative, so it goes first. */
@media (max-width: 1024px) {
  .login-shell { grid-template-columns: 1fr; }
  .login-brand { @apply hidden; }
  .brand-band { @apply block; }
}

@media (max-width: 640px) {
  .login-pane { padding: 1rem; gap: 1.25rem; }
  .login-body { max-width: none; }
  .login-card { padding: 1.25rem 1rem; }
  .login-foot { @apply flex-col items-start; gap: 0.25rem; }
}

/* Short viewports — landscape phones, small laptops, split-screen windows.
   Height-based rather than width-based: a 1440x700 laptop needs the same
   vertical compression a phone in landscape does. */
@media (max-height: 760px) {
  .login-brand { padding-block: clamp(1.25rem, 3vh, 2rem); }
  .preview { margin-top: 1.25rem; }
  .preview-table tbody tr:last-child { @apply hidden; }
}

/* Note: min-height stays at 100dvh here. It is a floor, not a ceiling —
   taller content already overflows and scrolls — and overriding it to 0 let
   the shell end above the fold, exposing the wrapper background beneath. */
@media (max-height: 620px) {
  .login-pane { padding-block: 1rem; gap: 1rem; }
  .preview { @apply hidden; }
  .brand-band { @apply hidden; }
}

/* Coarse pointers need a larger hit area than a mouse. */
@media (pointer: coarse) {
  .affix { @apply p-2; }
  .remember input { @apply size-5; }
  .link { padding-block: 0.25rem; }
}

@media (prefers-reduced-motion: reduce) {
  .btn-microsoft, .control, .btn-submit, .affix { transition: none; }
}
</style>
