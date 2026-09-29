/** Locale-aware formatting. One Intl instance per locale, built lazily. */

const cache = new Map<string, unknown>();

function memo<T>(key: string, make: () => T): T {
  if (!cache.has(key)) cache.set(key, make());
  return cache.get(key) as T;
}

export function formatNumber(n: number | null | undefined, locale = 'en', opts?: Intl.NumberFormatOptions): string {
  if (n === null || n === undefined || Number.isNaN(n)) return '–';
  const key = `n|${locale}|${opts ? JSON.stringify(opts) : ''}`;
  return memo(key, () => new Intl.NumberFormat(locale, opts)).format(n);
}

/** 1.2k style for tight cells. */
export function formatCompact(n: number, locale = 'en'): string {
  return memo(`c|${locale}`, () => new Intl.NumberFormat(locale, { notation: 'compact', maximumFractionDigits: 1 })).format(n);
}

export function formatPercent(fraction: number, locale = 'en', digits = 0): string {
  return formatNumber(fraction, locale, { style: 'percent', maximumFractionDigits: digits });
}

export function toDate(value: string | number | Date | null | undefined): Date | null {
  if (value === null || value === undefined || value === '') return null;
  const d = value instanceof Date ? value : new Date(value);
  return Number.isNaN(d.getTime()) ? null : d;
}

export function formatDate(value: string | Date | null | undefined, locale = 'en'): string {
  const d = toDate(value);
  if (!d) return '–';
  return memo(`d|${locale}`, () => new Intl.DateTimeFormat(locale, { dateStyle: 'medium' })).format(d);
}

export function formatDateTime(value: string | Date | null | undefined, locale = 'en'): string {
  const d = toDate(value);
  if (!d) return '–';
  return memo(`dt|${locale}`, () => new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' })).format(d);
}

/** "12 May" style, for chart axes. */
export function formatDayMonth(value: string | Date, locale = 'en'): string {
  const d = toDate(value);
  if (!d) return '';
  return memo(`dm|${locale}`, () => new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short' })).format(d);
}

const UNITS: [Intl.RelativeTimeFormatUnit, number][] = [
  ['year', 365 * 86400],
  ['month', 30 * 86400],
  ['week', 7 * 86400],
  ['day', 86400],
  ['hour', 3600],
  ['minute', 60],
];

/** "3 days ago", "yesterday", "in 2 hours". Falls back to "now" under a minute. */
export function formatRelative(value: string | Date | null | undefined, locale = 'en', now: Date | number = Date.now()): string {
  const d = toDate(value);
  if (!d) return '–';
  const diff = (d.getTime() - (typeof now === 'number' ? now : now.getTime())) / 1000;
  const abs = Math.abs(diff);
  const rtf = memo(`r|${locale}`, () => new Intl.RelativeTimeFormat(locale, { numeric: 'auto' }));
  if (abs < 45) return rtf.format(0, 'second');
  for (const [unit, secs] of UNITS) {
    if (abs >= secs || unit === 'minute') {
      return rtf.format(Math.round(diff / secs), unit);
    }
  }
  return rtf.format(0, 'second');
}

export function formatBytes(bytes: number, locale = 'en'): string {
  if (!Number.isFinite(bytes)) return '–';
  const units = ['B', 'KB', 'MB', 'GB'];
  let v = bytes;
  let i = 0;
  while (v >= 1024 && i < units.length - 1) {
    v /= 1024;
    i++;
  }
  return `${formatNumber(v, locale, { maximumFractionDigits: i === 0 ? 0 : 1 })} ${units[i]}`;
}

/** Local YYYY-MM-DD (not UTC), the key format of the API's `daily` maps. */
export function dayKey(d: Date): string {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

/** Last `days` day keys ending today (oldest first). */
export function lastDays(days: number, today: Date = new Date()): string[] {
  const out: string[] = [];
  for (let i = days - 1; i >= 0; i--) {
    const d = new Date(today.getFullYear(), today.getMonth(), today.getDate() - i);
    out.push(dayKey(d));
  }
  return out;
}

/** Turns a sparse `daily` map into a dense series over the last `days` days. */
export function dailySeries(daily: Record<string, number> | undefined, days: number, today?: Date): number[] {
  return lastDays(days, today).map((k) => daily?.[k] ?? 0);
}

/** ISO-8601 (with offset) from the value of an `<input type=datetime-local>`, or null. */
export function localInputToIso(v: string): string | null {
  if (!v) return null;
  const d = new Date(v);
  return Number.isNaN(d.getTime()) ? null : d.toISOString();
}

/** Value for an `<input type=datetime-local>` from an ISO string. */
export function isoToLocalInput(iso: string | null | undefined): string {
  const d = toDate(iso);
  if (!d) return '';
  const p = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;
}
