/**
 * The drawing rules of the plugin's sparklines, written down again for the tests (not imported from the UI source, so
 * a change of the formula in the UI shows up as a failing test instead of following silently).
 */

/** "M x,y L x,y ..." of a series in a w x h box with `pad` around it; empty for fewer than two points or all zeros. */
export function sparkLine(values: number[], w: number, h: number, pad = 1.5): string {
  const n = values.length;
  const max = Math.max(0, ...values);
  if (n < 2 || max === 0) return '';
  const step = (w - pad * 2) / (n - 1);
  return values
    .map((v, i) => [pad + i * step, h - pad - (v / max) * (h - pad * 2)] as const)
    .map(([x, y], i) => `${i ? 'L' : 'M'}${x.toFixed(1)},${y.toFixed(1)}`)
    .join('');
}

const pad2 = (n: number) => String(n).padStart(2, '0');

/** YYYY-MM-DD of the browser's (local) calendar day, like the UI's keys for the last N days */
export function dayKey(d: Date): string {
  return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`;
}

/** a dense series over the last `days` days (oldest first) from a sparse day -> count map */
export function dailySeries(daily: Record<string, number> | undefined, days: number, today = new Date()): number[] {
  const out: number[] = [];
  for (let i = days - 1; i >= 0; i--) out.push(daily?.[dayKey(new Date(today.getFullYear(), today.getMonth(), today.getDate() - i))] ?? 0);
  return out;
}

/** the widget's series: the values of the API's day map, sorted by day, the last `days` of them */
export function seriesOf(byDay: Record<string, number> | null | undefined, days = 30): number[] {
  if (!byDay) return [];
  return Object.keys(byDay)
    .sort()
    .slice(-days)
    .map((k) => (Number.isFinite(Number(byDay[k])) && Number(byDay[k]) > 0 ? Number(byDay[k]) : 0));
}
