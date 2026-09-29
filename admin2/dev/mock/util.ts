/** Small shared helpers: seeded RNG, dates, hashing, HTTP error type. */
import type { Severity } from '../../src/lib/types';

export type Rng = () => number;

export function mulberry32(seed: number): Rng {
  let a = seed | 0;
  return () => {
    a = (a + 0x6d2b79f5) | 0;
    let t = Math.imul(a ^ (a >>> 15), 1 | a);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

export const rint = (r: Rng, lo: number, hi: number): number => lo + Math.floor(r() * (hi - lo + 1));
export const pick = <T>(r: Rng, arr: readonly T[]): T => arr[Math.floor(r() * arr.length)] as T;
export const chance = (r: Rng, p: number): boolean => r() < p;
export function weighted<T>(r: Rng, items: readonly (readonly [T, number])[]): T {
  const total = items.reduce((s, i) => s + i[1], 0);
  let x = r() * total;
  for (const [v, w] of items) {
    x -= w;
    if (x < 0) return v;
  }
  return items[items.length - 1]![0];
}
export function shuffle<T>(r: Rng, arr: T[]): T[] {
  for (let i = arr.length - 1; i > 0; i--) {
    const j = Math.floor(r() * (i + 1));
    [arr[i], arr[j]] = [arr[j] as T, arr[i] as T];
  }
  return arr;
}

export const DAY = 86_400_000;
export const dayKey = (d: Date | number): string => new Date(d).toISOString().slice(0, 10);
export const isoAtom = (d: Date | number): string => new Date(d).toISOString().replace(/\.\d{3}Z$/, '+00:00');
/** FNV-1a, stable across runs. */
export function hash(s: string): number {
  let h = 0x811c9dc5;
  for (let i = 0; i < s.length; i++) {
    h ^= s.charCodeAt(i);
    h = Math.imul(h, 0x01000193);
  }
  return h >>> 0;
}
export const clone = <T>(v: T): T => JSON.parse(JSON.stringify(v)) as T;
export const escapeRe = (s: string): string => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
/** `*` glob to case-insensitive test. */
export const globTest = (pattern: string, value: string): boolean =>
  new RegExp('^' + pattern.split('*').map(escapeRe).join('.*') + '$', 'i').test(value);

export interface FieldError {
  field: string;
  code: string;
  message: string;
  severity: Severity;
}

/** Thrown by handlers, rendered as RFC 7807 problem+json. */
export class ApiError extends Error {
  constructor(
    public status: number,
    public title: string,
    detail: string,
    public errors?: FieldError[],
    public headers: Record<string, string> = {},
  ) {
    super(detail);
  }
}
