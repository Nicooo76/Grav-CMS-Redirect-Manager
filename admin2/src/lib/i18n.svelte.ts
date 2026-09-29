/** Reactive wrapper: components call `t(...)` and re-render on language change. */
import { createTranslator, type Params } from './i18n';

/** English fallback strings; each entry registers only the areas it needs (keeps the widget small). */
const fallback: Record<string, string> = {};
export function registerFallback(strings: Record<string, string>): void {
  Object.assign(fallback, strings);
}

const translator = createTranslator(fallback);

let version = $state(0);
let unsubscribe: (() => void) | null = null;
let users = 0;

/** Called by the entry elements; keeps one host subscription for all mounted apps. */
export function watchLocale(): () => void {
  if (users++ === 0) unsubscribe = translator.subscribe(() => version++);
  return () => {
    if (--users === 0) {
      unsubscribe?.();
      unsubscribe = null;
    }
  };
}

/** Bump manually, e.g. once after the host dictionary arrived. */
export function refreshLocale(): void {
  version++;
}

export function t(key: string, params?: Params): string {
  void version;
  return translator.t(key, params);
}

export function locale(): string {
  void version;
  return translator.locale();
}

export function dir(): 'ltr' | 'rtl' {
  void version;
  return translator.dir();
}
