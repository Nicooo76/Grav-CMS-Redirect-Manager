import { describe, expect, it } from 'vitest';
import { UndoBuffer } from './undo';

describe('undo buffer', () => {
  it('returns items within the grace period and only once', () => {
    let now = 1000;
    const buf = new UndoBuffer<string>(10_000, () => now);
    const e = buf.push(['a', 'b']);
    now += 9_999;
    expect(buf.take(e.id)?.items).toEqual(['a', 'b']);
    expect(buf.take(e.id)).toBeNull();
  });

  it('forgets entries after the ttl', () => {
    let now = 0;
    const buf = new UndoBuffer<number>(10_000, () => now);
    const e = buf.push([1]);
    now = 10_000;
    expect(buf.take(e.id)).toBeNull();
    expect(buf.size).toBe(0);
  });

  it('keeps several entries and finds the latest live one', () => {
    let now = 0;
    const buf = new UndoBuffer<number>(10_000, () => now);
    const a = buf.push([1]);
    now = 5_000;
    const b = buf.push([2]);
    expect(buf.latest()?.id).toBe(b.id);
    now = 12_000; // a expired, b alive
    expect(buf.latest()?.id).toBe(b.id);
    expect(buf.take(a.id)).toBeNull();
    expect(buf.size).toBe(1);
  });
});
