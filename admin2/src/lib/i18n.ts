/**
 * Translation core, independent of Svelte so it can be unit-tested.
 *
 * Strings live under `PLUGIN_REDIRECT_MANAGER.UI.<KEY>` in the host dictionary.
 * The host's `t()` humanises unknown keys ("Filter All"), so `has()` is always
 * checked first and the English text shipped in `i18n-fallback.ts` is used when
 * a key is missing. Values are stored raw; `{name}` placeholders and
 * `{n, plural, one{..} other{..}}` are resolved here, so no `ICU:` block is
 * needed in the language file (and a host that does format ICU still works,
 * because a string that was already formatted contains no braces any more).
 */

export const KEY_PREFIX = 'PLUGIN_REDIRECT_MANAGER.UI.';

export type Params = Record<string, string | number | boolean | null | undefined>;

interface HostI18n {
  t(key: string, params?: Record<string, unknown>): string;
  has(key: string): boolean;
  readonly locale: string;
  readonly dir: 'ltr' | 'rtl';
  subscribe(fn: (locale: string) => void): () => void;
}

export function fullKey(key: string): string {
  return key.startsWith('PLUGIN_') ? key : KEY_PREFIX + key;
}

/** Index of the brace that closes the one opened at `start`, or -1. */
function matchBrace(s: string, start: number): number {
  let depth = 0;
  for (let i = start; i < s.length; i++) {
    const c = s[i];
    if (c === '{') depth++;
    else if (c === '}' && --depth === 0) return i;
  }
  return -1;
}

function pluralCategory(n: number, locale: string): string {
  try {
    return new Intl.PluralRules(locale).select(n);
  } catch {
    return n === 1 ? 'one' : 'other';
  }
}

/** Resolves `{name}` and `{n, plural, =0{..} one{..} other{..}}` (with `#`). */
export function formatMessage(template: string, params: Params = {}, locale = 'en'): string {
  if (!template.includes('{')) return template;
  let out = '';
  let i = 0;
  while (i < template.length) {
    const c = template[i];
    if (c !== '{') {
      out += c;
      i++;
      continue;
    }
    const end = matchBrace(template, i);
    if (end === -1) {
      out += template.slice(i);
      break;
    }
    const inner = template.slice(i + 1, end);
    const comma = inner.indexOf(',');
    if (comma === -1) {
      const name = inner.trim();
      out += name in params && params[name] != null ? String(params[name]) : `{${inner}}`;
    } else {
      const name = inner.slice(0, comma).trim();
      const rest = inner.slice(comma + 1);
      const comma2 = rest.indexOf(',');
      const type = (comma2 === -1 ? rest : rest.slice(0, comma2)).trim();
      const body = comma2 === -1 ? '' : rest.slice(comma2 + 1);
      const value = params[name];
      if ((type === 'plural' || type === 'select') && value != null) {
        const options = parseOptions(body);
        let chosen: string | undefined;
        if (type === 'plural') {
          const n = Number(value);
          chosen = options[`=${n}`] ?? options[pluralCategory(n, locale)] ?? options.other;
          if (chosen !== undefined) {
            const numStr = new Intl.NumberFormat(locale).format(n);
            chosen = chosen.replace(/#/g, numStr);
          }
        } else {
          chosen = options[String(value)] ?? options.other;
        }
        out += chosen !== undefined ? formatMessage(chosen, params, locale) : '';
      } else {
        out += `{${inner}}`;
      }
    }
    i = end + 1;
  }
  return out;
}

function parseOptions(body: string): Record<string, string> {
  const opts: Record<string, string> = {};
  let i = 0;
  while (i < body.length) {
    while (i < body.length && /\s/.test(body[i])) i++;
    let key = '';
    while (i < body.length && body[i] !== '{' && !/\s/.test(body[i])) key += body[i++];
    while (i < body.length && /\s/.test(body[i])) i++;
    if (body[i] !== '{') break;
    const end = matchBrace(body, i);
    if (end === -1) break;
    opts[key] = body.slice(i + 1, end);
    i = end + 1;
  }
  return opts;
}

export interface Translator {
  t(key: string, params?: Params): string;
  /** Text from the host dictionary only (no English fallback shipped in the bundle), undefined when the host has none. Takes the full key. */
  hostText(fullKey: string, params?: Params): string | undefined;
  has(key: string): boolean;
  locale(): string;
  dir(): 'ltr' | 'rtl';
  subscribe(fn: () => void): () => void;
}

export function createTranslator(
  fallback: Record<string, string>,
  getHost: () => HostI18n | undefined = () => (typeof window === 'undefined' ? undefined : window.__GRAV_I18N),
): Translator {
  const rawHas = (key: string) => {
    const h = getHost();
    return !!h && typeof h.has === 'function' && h.has(fullKey(key));
  };
  return {
    t(key, params) {
      const h = getHost();
      const locale = h?.locale ?? 'en';
      let raw: string | undefined;
      if (h && rawHas(key)) {
        try {
          raw = h.t(fullKey(key), params as Record<string, unknown> | undefined);
        } catch {
          raw = undefined;
        }
      }
      if (raw === undefined) raw = fallback[key];
      if (raw === undefined) return key;
      return formatMessage(raw, params ?? {}, locale);
    },
    hostText(full, params) {
      const h = getHost();
      if (!h || typeof h.has !== 'function' || !h.has(full)) return undefined;
      try {
        return formatMessage(h.t(full, params as Record<string, unknown> | undefined), params ?? {}, h.locale ?? 'en');
      } catch {
        return undefined;
      }
    },
    has: (key) => key in fallback || rawHas(key),
    locale: () => getHost()?.locale ?? (typeof navigator !== 'undefined' ? navigator.language : 'en'),
    dir: () => getHost()?.dir ?? (typeof document !== 'undefined' && document.documentElement.dir === 'rtl' ? 'rtl' : 'ltr'),
    subscribe(fn) {
      const h = getHost();
      if (h && typeof h.subscribe === 'function') {
        try {
          return h.subscribe(() => fn());
        } catch {
          /* fall through */
        }
      }
      return () => {};
    },
  };
}
