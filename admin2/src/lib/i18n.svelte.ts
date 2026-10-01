/** Reactive wrapper: components call `t(...)` and re-render on language change. */
import { createTranslator, watchDictionary, type Params } from './i18n';

/** English fallback strings; each entry registers only the areas it needs (keeps the widget small). */
const fallback: Record<string, string> = {};
export function registerFallback(strings: Record<string, string>): void {
  Object.assign(fallback, strings);
}

const translator = createTranslator(fallback);

let version = $state(0);
let unsubscribe: (() => void) | null = null;
let users = 0;

/**
 * Called by the entry elements; keeps one host subscription for all mounted apps. The host announces a language
 * change; a dictionary that arrives for the current language is found by watchDictionary().
 */
export function watchLocale(): () => void {
  if (users++ === 0) {
    const off = translator.subscribe(() => version++);
    const stop = watchDictionary(translator, () => version++);
    unsubscribe = () => {
      off();
      stop();
    };
  }
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

/** Host-only text (server-provided dictionary entries such as issue codes); undefined when missing. */
export function tHost(fullKey: string, params?: Params): string | undefined {
  void version;
  return translator.hostText(fullKey, params);
}

export function locale(): string {
  void version;
  return translator.locale();
}

export function dir(): 'ltr' | 'rtl' {
  void version;
  return translator.dir();
}
