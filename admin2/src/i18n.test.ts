import { describe, expect, it } from 'vitest';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { allEn as fallbackEn } from './lib/i18n-all';
import { fallbackEn as shipped } from './lib/i18n-fallback';
import { strings_de } from './i18n/de.index';

const root = join(import.meta.dirname);

function walk(dir: string, out: string[] = []): string[] {
  for (const name of readdirSync(dir)) {
    const p = join(dir, name);
    if (statSync(p).isDirectory()) walk(p, out);
    else if (/\.(svelte|ts)$/.test(name) && !name.endsWith('.test.ts') && !p.includes('/i18n/')) out.push(p);
  }
  return out;
}

/** Literal keys passed to t('...'), plus template keys with a known prefix are skipped. */
function usedKeys(): Map<string, string[]> {
  const found = new Map<string, string[]>();
  for (const file of walk(root)) {
    const src = readFileSync(file, 'utf8');
    for (const m of src.matchAll(/\bt\(\s*'([A-Z][A-Z0-9_]*\.[A-Z0-9_.]+)'/g)) {
      const list = found.get(m[1]) ?? [];
      list.push(relative(root, file));
      found.set(m[1], list);
    }
  }
  return found;
}

const placeholders = (s: string) => [...s.matchAll(/\{(\w+)(?:,|\})/g)].map((m) => m[1]).sort();

describe('bundled fallback', () => {
  it('is a subset of the full catalog and covers the shell', () => {
    for (const [k, v] of Object.entries(shipped)) expect(fallbackEn[k], k).toBe(v);
    for (const k of ['TAB.RULES', 'TAB.SETTINGS', 'COMMON.ERROR_NETWORK', 'COMMON.RETRY', 'PAGE_ERROR.TITLE', 'STATUS.301']) expect(shipped[k], k).toBeTruthy();
  });
  it('stays small (the rest comes from the host dictionary)', () => {
    expect(Object.keys(shipped).length).toBeLessThan(150);
  });
});

describe('i18n catalog', () => {
  it('has an English string for every literal t() key in the source', () => {
    const missing = [...usedKeys()].filter(([k]) => !(k in fallbackEn)).map(([k, files]) => `${k}  (${[...new Set(files)].join(', ')})`);
    expect(missing).toEqual([]);
  });

  it('has a German string for every English string and no strays', () => {
    const missingDe = Object.keys(fallbackEn).filter((k) => !(k in strings_de));
    const strayDe = Object.keys(strings_de).filter((k) => !(k in fallbackEn));
    expect(missingDe).toEqual([]);
    expect(strayDe).toEqual([]);
  });

  it('uses the same placeholders in both languages', () => {
    const bad = Object.keys(fallbackEn)
      .filter((k) => k in strings_de)
      .filter((k) => JSON.stringify(placeholders(fallbackEn[k])) !== JSON.stringify(placeholders(strings_de[k])))
      .map((k) => `${k}: ${placeholders(fallbackEn[k])} vs ${placeholders(strings_de[k])}`);
    expect(bad).toEqual([]);
  });
});
