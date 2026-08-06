import { ref } from "vue";

/**
 * Transient notifications.
 *
 * Replaces the inline banners each screen was rendering for itself. Those had
 * two problems beyond the duplication: they pushed the page down when they
 * appeared, so a save moved the thing you had just been looking at, and a
 * screen that was scrolled anywhere but the top showed its confirmation
 * off-screen — the save looked like it had done nothing.
 *
 * Errors do not auto-dismiss. A confirmation you miss costs nothing; a failure
 * you miss means carrying on believing the work was saved.
 */
export type ToastTone = "success" | "error" | "info";

export interface Toast {
  id: number;
  tone: ToastTone;
  message: string;
  /** Optional detail — a validation message, usually. */
  detail?: string;
}

export const toasts = ref<Toast[]>([]);

let nextId = 1;

/** How long each tone stays up. Errors stay until dismissed. */
const LIFETIME: Record<ToastTone, number | null> = {
  success: 3500,
  info: 4500,
  error: null,
};

export function dismissToast(id: number): void {
  toasts.value = toasts.value.filter((t) => t.id !== id);
}

export function toast(tone: ToastTone, message: string, detail?: string): number {
  const id = nextId++;

  toasts.value = [...toasts.value, { id, tone, message, detail }];

  // Capped so a loop that fails on every row cannot bury the screen.
  if (toasts.value.length > 4) {
    toasts.value = toasts.value.slice(-4);
  }

  const life = LIFETIME[tone];
  if (life !== null) {
    setTimeout(() => dismissToast(id), life);
  }

  return id;
}

export const notifySaved   = (message = "Saved.") => toast("success", message);
export const notifyFailed  = (message: string, detail?: string) => toast("error", message, detail);
export const notifyInfo    = (message: string, detail?: string) => toast("info", message, detail);
