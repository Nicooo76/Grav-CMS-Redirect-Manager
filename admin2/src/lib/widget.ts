/**
 * Pure logic for the dashboard widget: size layout, series, averages, links.
 * Kept out of the .svelte file so it can be unit-tested.
 */
import type { Stats } from './types';

export type WidgetLayout = 'sm' | 'md' | 'lg';

export const DEFAULT_ENDPOINT = '/redirects/stats';
/** Reload on becoming visible only if the last load is older than this. */
export const STALE_MS = 60_000;

/** Admin 2 sizes are xs|sm|md|lg|xl; we declare sm, md, lg and fold the rest. */
export function layoutFor(size: string | null | undefined): WidgetLayout {
  switch ((size ?? '').trim().toLowerCase()) {
    case 'xs':
    case 'sm':
      return 'sm';
    case 'lg':
    case 'xl':
      return 'lg';
    default:
      return 'md';
  }
}

export function endpointFor(attr: string | null | undefined): string {
  const v = (attr ?? '').trim();
  if (!v) return DEFAULT_ENDPOINT;
  return v.startsWith('/') ? v : `/${v}`;
}

/** Values of a `YYYY-MM-DD -> count` map, oldest first, at most the last `days`. */
export function seriesOf(byDay: Record<string, number> | null | undefined, days = 30): number[] {
  if (!byDay) return [];
  const keys = Object.keys(byDay).sort();
  return keys.slice(-days).map((k) => {
    const v = Number(byDay[k]);
    return Number.isFinite(v) && v > 0 ? v : 0;
  });
}

/** Average per day over the 7-day total. Null below one event per day: not meaningful. */
export function dailyAverage(total7d: number): number | null {
  if (!Number.isFinite(total7d) || total7d < 7) return null;
  return total7d / 7;
}

/** Relative change of `current` against `baseline`, as a fraction. Null when the baseline is 0. */
export function changeVs(current: number, baseline: number): number | null {
  if (!Number.isFinite(current) || !Number.isFinite(baseline) || baseline <= 0) return null;
  return (current - baseline) / baseline;
}

/** One decimal below 10, whole numbers above (for "avg 8.4/day"). */
export function roundAverage(avg: number): number {
  return avg < 10 ? Math.round(avg * 10) / 10 : Math.round(avg);
}

/** True when there is nothing to show yet: no rules and no traffic. */
export function isEmptyStats(s: Stats): boolean {
  return (
    !s.rules_total &&
    !s.not_found_today &&
    !s.not_found_7d &&
    !s.hits_today &&
    !s.hits_7d &&
    !s.open_suggestions &&
    !s.dead_targets
  );
}

export type TileId = 'notfound' | 'hits' | 'suggestions' | 'dead';

/** Tiles for a layout. `sm` keeps the 404 tile and whichever counter needs attention. */
export function tilesFor(layout: WidgetLayout, s: Stats): TileId[] {
  if (layout !== 'sm') return ['notfound', 'hits', 'suggestions', 'dead'];
  if (s.dead_targets > 0) return ['notfound', 'dead'];
  if (s.open_suggestions > 0) return ['notfound', 'suggestions'];
  return ['notfound', 'dead'];
}

export const TILE_HASH: Record<TileId, string> = {
  notfound: '/404',
  hits: '/rules',
  suggestions: '/suggestions',
  dead: '/rules?badge=dead_target',
};

export const EMPTY_HASH = '/rules/new';

/** Admin URL of a plugin sub-view. `hash` starts with `/`. */
export function adminUrl(hash: string, base: string | undefined = typeof window === 'undefined' ? undefined : window.__GRAV_ADMIN_BASE): string {
  return `${base ?? ''}/plugin/redirect-manager#${hash}`;
}

/** Path geometry for a sparkline stretched to `w` x `h` (used with preserveAspectRatio="none"). */
export function sparkPaths(values: number[], w = 100, h = 24, pad = 1.5): { line: string; area: string; flat: boolean } {
  const n = values.length;
  const max = Math.max(0, ...values);
  if (n < 2 || max === 0) return { line: '', area: '', flat: true };
  const step = (w - pad * 2) / (n - 1);
  const pts = values.map((v, i) => [pad + i * step, h - pad - (v / max) * (h - pad * 2)] as const);
  const line = pts.map(([x, y], i) => `${i ? 'L' : 'M'}${x.toFixed(1)},${y.toFixed(1)}`).join('');
  const area = `${line}L${(pad + (n - 1) * step).toFixed(1)},${h}L${pad},${h}Z`;
  return { line, area, flat: false };
}

/** Fills missing or malformed fields so a partial response cannot break the render. */
export function normalizeStats(raw: unknown): Stats {
  const o = (raw && typeof raw === 'object' ? raw : {}) as Record<string, unknown>;
  const num = (k: string) => {
    const v = Number(o[k]);
    return Number.isFinite(v) && v > 0 ? v : 0;
  };
  const map = (k: string) => (o[k] && typeof o[k] === 'object' && !Array.isArray(o[k]) ? (o[k] as Record<string, number>) : {});
  return {
    not_found_today: num('not_found_today'),
    not_found_7d: num('not_found_7d'),
    not_found_by_day: map('not_found_by_day'),
    hits_today: num('hits_today'),
    hits_7d: num('hits_7d'),
    hits_by_day: map('hits_by_day'),
    rules_total: num('rules_total'),
    rules_active: num('rules_active'),
    open_suggestions: num('open_suggestions'),
    dead_targets: num('dead_targets'),
    pending_deletes: num('pending_deletes'),
  };
}
