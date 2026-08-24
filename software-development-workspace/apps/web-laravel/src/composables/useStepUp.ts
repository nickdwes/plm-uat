import { ref } from "vue";
import { api, ApiError } from "../lib/api";

/**
 * Step-up verification — proving it is really you, right now, not just that
 * your session cookie is still valid.
 *
 * One instance per purpose. "Elevated" is a fact about the signed-in session
 * for that purpose, not about any one page or record — once granted it holds
 * for every payroll view for its window, the same way the server checks it.
 */
export function useStepUp(purpose: string) {
  const busy           = ref(false);
  const codeSent       = ref(false);
  const error          = ref("");
  const elevatedUntil  = ref<string | null>(null);

  async function checkStatus(orgSlug: string | null): Promise<boolean> {
    try {
      const res = await api<{ elevated: boolean; elevated_until: string | null }>(
        "GET", "/api/v1/security/step-up/status", { org: orgSlug, params: { purpose } });
      elevatedUntil.value = res.elevated_until;
      return res.elevated;
    } catch {
      return false;
    }
  }

  async function requestCode(orgSlug: string | null): Promise<boolean> {
    busy.value = true; error.value = "";
    try {
      await api("POST", "/api/v1/security/step-up/request", { org: orgSlug, body: { purpose } });
      codeSent.value = true;
      return true;
    } catch (e) {
      error.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not send a code.";
      return false;
    } finally { busy.value = false; }
  }

  async function verify(orgSlug: string | null, code: string): Promise<boolean> {
    busy.value = true; error.value = "";
    try {
      const res = await api<{ elevated_until: string }>(
        "POST", "/api/v1/security/step-up/verify", { org: orgSlug, body: { purpose, code } });
      elevatedUntil.value = res.elevated_until;
      return true;
    } catch (e) {
      error.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not verify that code.";
      return false;
    } finally { busy.value = false; }
  }

  function reset(): void {
    codeSent.value = false;
    error.value = "";
  }

  return { busy, codeSent, error, elevatedUntil, checkStatus, requestCode, verify, reset };
}

/**
 * Whether a caught error is a specific endpoint refusing for lack of a fresh
 * step-up, rather than any other kind of failure. Free function rather than
 * part of the composable above, since the call site catching the error is
 * usually not the same one that owns the composable instance.
 */
export function isStepUpError(e: unknown): boolean {
  return e instanceof ApiError && e.status === 401 && e.code === "step_up_required";
}
