import { describe, expect, it } from 'vitest';
import { computeWindow, indexAt } from './virtual';

describe('virtual window', () => {
  const base = { rowHeight: 44, viewportHeight: 600, count: 10_000, overscan: 8 };

  it('renders only the visible rows plus overscan at the top', () => {
    const w = computeWindow({ ...base, scrollTop: 0 });
    expect(w.start).toBe(0);
    expect(w.end).toBe(Math.ceil(600 / 44) + 1 + 8);
    expect(w.padTop).toBe(0);
    expect(w.padBottom).toBe((10_000 - w.end) * 44);
    expect(w.total).toBe(440_000);
  });

  it('keeps the padding sum equal to the total height', () => {
    for (const scrollTop of [0, 1, 999, 123_456, 439_400]) {
      const w = computeWindow({ ...base, scrollTop });
      expect(w.padTop + (w.end - w.start) * 44 + w.padBottom).toBe(w.total);
      expect(w.end - w.start).toBeLessThan(40);
    }
  });

  it('clamps scroll positions past the end', () => {
    const w = computeWindow({ ...base, scrollTop: 10_000_000 });
    expect(w.end).toBe(10_000);
    expect(w.padBottom).toBe(0);
  });

  it('handles empty and tiny lists', () => {
    expect(computeWindow({ ...base, count: 0, scrollTop: 0 })).toEqual({ start: 0, end: 0, padTop: 0, padBottom: 0, total: 0 });
    const w = computeWindow({ ...base, count: 3, scrollTop: 0 });
    expect(w.start).toBe(0);
    expect(w.end).toBe(3);
  });

  it('maps a pointer offset to a row index', () => {
    expect(indexAt(0, 44, 100)).toBe(0);
    expect(indexAt(87, 44, 100)).toBe(1);
    expect(indexAt(-50, 44, 100)).toBe(0);
    expect(indexAt(99999, 44, 100)).toBe(99);
  });
});
