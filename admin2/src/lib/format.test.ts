import { describe, expect, it } from 'vitest';
import { dailySeries, dayKey, formatBytes, formatCompact, formatNumber, formatPercent, formatRelative, isoToLocalInput, lastDays, localInputToIso } from './format';

describe('formatters', () => {
  it('formats numbers per locale and shows a dash for missing values', () => {
    expect(formatNumber(1234567, 'en')).toBe('1,234,567');
    expect(formatNumber(1234567, 'de')).toBe('1.234.567');
    expect(formatNumber(null)).toBe('–');
    expect(formatNumber(Number.NaN)).toBe('–');
    expect(formatCompact(1500, 'en')).toBe('1.5K');
    expect(formatPercent(0.9, 'en')).toBe('90%');
  });

  it('formats bytes', () => {
    expect(formatBytes(512)).toBe('512 B');
    expect(formatBytes(1536)).toBe('1.5 KB');
    expect(formatBytes(5 * 1024 * 1024)).toBe('5 MB');
  });

  it('formats relative time with the nearest sensible unit', () => {
    const now = new Date('2026-09-29T12:00:00Z');
    expect(formatRelative('2026-09-29T11:59:50Z', 'en', now)).toBe('now');
    expect(formatRelative('2026-09-29T09:00:00Z', 'en', now)).toBe('3 hours ago');
    expect(formatRelative('2026-09-28T12:00:00Z', 'en', now)).toBe('yesterday');
    expect(formatRelative('2026-09-15T12:00:00Z', 'en', now)).toBe('2 weeks ago');
    expect(formatRelative('2026-09-29T14:00:00Z', 'de', now)).toBe('in 2 Stunden');
    expect(formatRelative(null)).toBe('–');
    expect(formatRelative('not a date')).toBe('–');
  });

  it('builds dense day series from sparse maps', () => {
    const today = new Date(2026, 8, 29);
    expect(lastDays(3, today)).toEqual(['2026-09-27', '2026-09-28', '2026-09-29']);
    expect(dailySeries({ '2026-09-29': 4, '2026-09-27': 1, '2026-01-01': 9 }, 3, today)).toEqual([1, 0, 4]);
    expect(dailySeries(undefined, 2, today)).toEqual([0, 0]);
    expect(dayKey(new Date(2026, 0, 5))).toBe('2026-01-05');
  });

  it('converts datetime-local values', () => {
    expect(localInputToIso('')).toBeNull();
    expect(localInputToIso('garbage')).toBeNull();
    const iso = localInputToIso('2026-09-29T10:30');
    expect(iso).toMatch(/^2026-09-29T/);
    expect(isoToLocalInput(iso)).toBe('2026-09-29T10:30');
    expect(isoToLocalInput(null)).toBe('');
  });
});
