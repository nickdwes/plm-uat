<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { api, ApiError } from '@/lib/api';
import { setAuth } from '@/stores/auth';
import type { LoginResponse } from '@/lib/types';

const router = useRouter();
const route  = useRoute();
const status = ref<'loading' | 'error'>('loading');
const errMsg = ref('');

onMounted(async () => {
  // If Azure redirected with an error (e.g. user cancelled), go back to login
  const ssoError = route.query.sso_error as string | undefined;
  if (ssoError) {
    void router.replace({ name: 'login', query: { sso_error: ssoError } });
    return;
  }

  const code = route.query.code as string | undefined;
  if (!code) {
    void router.replace({ name: 'login', query: { sso_error: 'Missing authentication code.' } });
    return;
  }

  try {
    const res = await api<LoginResponse>('POST', '/api/auth/azure/exchange', {
      body: { code },
    });
    setAuth(res.user, res.organizations, res.token);
    void router.replace({ name: 'hr-dashboard' });
  } catch (err) {
    status.value = 'error';
    errMsg.value = err instanceof ApiError
      ? err.message
      : 'Sign-in failed. Please try again.';
  }
});
</script>

<template>
  <main class="sso-shell">
    <div class="sso-card">
      <template v-if="status === 'loading'">
        <div class="sso-spinner"></div>
        <p class="sso-msg">Completing sign-in…</p>
      </template>
      <template v-else>
        <div class="sso-error-icon">⚠️</div>
        <p class="sso-err">{{ errMsg }}</p>
        <a href="#/login" class="sso-back">← Back to login</a>
      </template>
    </div>
  </main>
</template>

<style scoped>
.sso-shell {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f1f5f9;
}
.sso-card {
  background: #fff;
  border-radius: 16px;
  padding: 48px 40px;
  text-align: center;
  box-shadow: 0 4px 24px rgba(0,0,0,.08);
  min-width: 300px;
}
.sso-spinner {
  width: 40px; height: 40px;
  border: 3px solid #e2e8f0;
  border-top-color: #6366f1;
  border-radius: 50%;
  animation: spin 0.7s linear infinite;
  margin: 0 auto 20px;
}
@keyframes spin { to { transform: rotate(360deg); } }
.sso-msg { font-size: 15px; color: #64748b; }
.sso-error-icon { font-size: 40px; margin-bottom: 12px; }
.sso-err { font-size: 14px; color: #dc2626; margin-bottom: 20px; line-height: 1.5; }
.sso-back { font-size: 13px; color: #4f46e5; text-decoration: none; }
.sso-back:hover { text-decoration: underline; }
</style>
