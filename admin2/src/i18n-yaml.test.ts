import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import YAML from 'yaml';
import { fallbackEn } from './lib/i18n-fallback';
import { strings_de } from './i18n/de.index';

const doc = YAML.parse(readFileSync(join(import.meta.dirname, '..', 'i18n', 'ui.yaml'), 'utf8'));

/** Same flattening Grav does: nested keys joined with dots. */
function flatten(node: unknown, prefix = '', out: Record<string, string> = {}): Record<string, string> {
  if (node && typeof node === 'object') for (const [k, v] of Object.entries(node)) flatten(v, prefix ? `${prefix}.${k}` : String(k), out);
  else out[prefix] = String(node);
  return out;
}

describe('i18n/ui.yaml', () => {
  it('is shaped en/de -> PLUGIN_REDIRECT_MANAGER -> UI', () => {
    expect(Object.keys(doc).sort()).toEqual(['de', 'en']);
    for (const lang of ['en', 'de']) {
      expect(Object.keys(doc[lang])).toEqual(['PLUGIN_REDIRECT_MANAGER']);
      expect(Object.keys(doc[lang].PLUGIN_REDIRECT_MANAGER)).toEqual(['UI']);
    }
  });

  it('is in sync with the sources (run `npm run i18n` after editing src/i18n)', () => {
    expect(flatten(doc.en.PLUGIN_REDIRECT_MANAGER.UI)).toEqual(fallbackEn);
    expect(flatten(doc.de.PLUGIN_REDIRECT_MANAGER.UI)).toEqual(strings_de);
  });
});
