/**
 * Toasts and dialogs: the host's `__GRAV_TOAST` / `__GRAV_DIALOGS` when present,
 * otherwise an in-page fallback rendered by ToastHost / DialogHost (used by the
 * dev harness with `?nohost=1`, and by hosts that lack the globals).
 */
import { deepActive } from '../dom';

export type ToastKind = 'success' | 'error' | 'info' | 'warning';

export interface ToastOptions {
  duration?: number;
  action?: { label: string; onClick: () => void };
}

export interface FallbackToast {
  id: number;
  kind: ToastKind;
  message: string;
  action?: ToastOptions['action'];
}

export const fallbackToasts = $state<FallbackToast[]>([]);
let seq = 0;

function push(kind: ToastKind, message: string, opts: ToastOptions = {}): void {
  const host = window.__GRAV_TOAST;
  if (host && typeof host[kind] === 'function') {
    try {
      host[kind](message, {
        duration: opts.duration,
        ...(opts.action ? { action: { label: opts.action.label, onClick: opts.action.onClick } } : {}),
      });
      return;
    } catch {
      /* fall through to the in-page toast */
    }
  }
  const id = ++seq;
  fallbackToasts.push({ id, kind, message, action: opts.action });
  setTimeout(() => dismissToast(id), opts.duration ?? (kind === 'error' ? 8000 : 5000));
}

export function dismissToast(id: number): void {
  const i = fallbackToasts.findIndex((t) => t.id === id);
  if (i !== -1) fallbackToasts.splice(i, 1);
}

export const toast = {
  success: (m: string, o?: ToastOptions) => push('success', m, o),
  error: (m: string, o?: ToastOptions) => push('error', m, o),
  info: (m: string, o?: ToastOptions) => push('info', m, o),
  warning: (m: string, o?: ToastOptions) => push('warning', m, o),
};

/* ---------- dialogs ---------- */

export interface ConfirmOptions {
  title?: string;
  message: string;
  confirmLabel?: string;
  cancelLabel?: string;
  variant?: 'destructive' | 'default';
}

export interface FormField {
  name: string;
  type?: 'text' | 'textarea' | 'select' | 'toggle' | 'number';
  label?: string;
  placeholder?: string;
  help?: string;
  required?: boolean;
  value?: string | number | boolean;
  options?: { value: string; label: string }[];
}

export interface FormOptions {
  title?: string;
  description?: string;
  fields: FormField[];
  submitLabel?: string;
  cancelLabel?: string;
  size?: 'sm' | 'md' | 'lg' | 'xl';
}

export type DialogRequest =
  | { kind: 'confirm'; opts: ConfirmOptions; resolve: (v: boolean) => void }
  | { kind: 'form'; opts: FormOptions; resolve: (v: Record<string, unknown> | null) => void };

export const dialogState = $state<{ current: DialogRequest | null }>({ current: null });

/**
 * The host restores focus to `document.activeElement`, which for anything inside
 * our shadow root is the shadow host itself (not focusable). Remember the real
 * element and put focus back ourselves once the dialog is gone.
 */
async function withFocusRestore<T>(run: () => Promise<T>): Promise<T> {
  const before = deepActive();
  try {
    return await run();
  } finally {
    if (before && before.isConnected && before !== document.body) requestAnimationFrame(() => before.focus({ preventScroll: true }));
  }
}

export const dialogs = {
  async confirm(opts: ConfirmOptions): Promise<boolean> {
    const host = window.__GRAV_DIALOGS;
    if (host && typeof host.confirm === 'function') {
      return withFocusRestore(async () => {
        try {
          return !!(await host.confirm(opts));
        } catch {
          return false; // a dialog that could not be shown is not a yes
        }
      });
    }
    return new Promise<boolean>((resolve) => {
      dialogState.current = { kind: 'confirm', opts, resolve };
    });
  },
  async form(opts: FormOptions): Promise<Record<string, unknown> | null> {
    const host = window.__GRAV_DIALOGS;
    if (host && typeof host.form === 'function') {
      return withFocusRestore(async () => {
        try {
          return (await host.form(opts)) ?? null;
        } catch {
          return null;
        }
      });
    }
    return new Promise((resolve) => {
      dialogState.current = { kind: 'form', opts, resolve };
    });
  },
};
