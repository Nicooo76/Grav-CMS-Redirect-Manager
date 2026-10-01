import { describe, expect, it } from 'vitest';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import YAML from 'yaml';
import { allEn as fallbackEn } from './lib/i18n-all';
import { strings_de } from './i18n/de.index';

const pluginRoot = join(import.meta.dirname, '..', '..');
const doc = YAML.parse(readFileSync(join(pluginRoot, 'languages.yaml'), 'utf8'));
const P = 'PLUGIN_REDIRECT_MANAGER';

/** Same flattening Grav does: nested keys joined with dots. */
function flatten(node: unknown, prefix = '', out: Record<string, string> = {}): Record<string, string> {
  if (node && typeof node === 'object') for (const [k, v] of Object.entries(node)) flatten(v, prefix ? `${prefix}.${k}` : String(k), out);
  else out[prefix] = String(node);
  return out;
}

function phpFiles(dir: string, out: string[] = []): string[] {
  for (const name of readdirSync(dir)) {
    const p = join(dir, name);
    if (statSync(p).isDirectory()) phpFiles(p, out);
    else if (name.endsWith('.php')) out.push(p);
  }
  return out;
}

describe('languages.yaml', () => {
  it('has an en and a de block for the plugin', () => {
    expect(Object.keys(doc).sort()).toEqual(['de', 'en']);
    for (const lang of ['en', 'de']) expect(Object.keys(doc[lang])).toEqual([P]);
  });

  it('has the same keys in en and de', () => {
    const en = Object.keys(flatten(doc.en[P])).sort();
    const de = Object.keys(flatten(doc.de[P])).sort();
    expect(de).toEqual(en);
  });

  it('carries every UI string of the sources, in en and de (run `npm run i18n` after editing src/i18n)', () => {
    expect(flatten(doc.en[P].UI)).toEqual(fallbackEn);
    expect(flatten(doc.de[P].UI)).toEqual(strings_de);
  });

  it('keeps UI as the last section, after the hand-written ones', () => {
    for (const lang of ['en', 'de']) {
      const keys = Object.keys(doc[lang][P]);
      expect(keys[keys.length - 1]).toBe('UI');
      for (const section of ['TITLE', 'WIDGET', 'PERMISSIONS', 'CONFIG', 'FRONTEND', 'IMPORT', 'VALIDATION']) expect(keys, section).toContain(section);
    }
  });

  it('has no empty texts', () => {
    for (const lang of ['en', 'de']) for (const [k, v] of Object.entries(flatten(doc[lang][P]))) expect(v.trim(), `${lang} ${k}`).not.toBe('');
  });

  it('translates every code the importer can report', () => {
    const codes = new Set<string>();
    for (const file of phpFiles(join(pluginRoot, 'classes', 'ImportExport'))) {
      for (const m of readFileSync(file, 'utf8').matchAll(/new ImportIssue\(\s*'([a-z_]+)'/g)) codes.add(m[1]!);
    }
    expect(codes.size).toBeGreaterThan(40);
    for (const lang of ['en', 'de']) {
      const table = doc[lang][P].IMPORT.ISSUE as Record<string, string>;
      for (const code of codes) expect(table[code.toUpperCase()], `${lang} IMPORT.ISSUE.${code.toUpperCase()}`).toBeTruthy();
    }
  });

  it('translates every validation code the rule checks can report', () => {
    const codes = new Set<string>();
    const read = (file: string) => readFileSync(join(pluginRoot, 'classes', file), 'utf8');
    for (const file of ['Analysis/RuleValidator.php', 'Analysis/IssueFactory.php', 'Analysis/ChainAnalyzer.php']) {
      for (const m of read(file).matchAll(/ValidationIssue::(?:error|warning|info)\(\s*'([a-z_]+)'/g)) codes.add(m[1]!);
    }
    for (const file of ['Security/TargetGuard.php', 'Security/RegexSafety.php']) {
      for (const m of read(file).matchAll(/const ERR_[A-Z_]+ = '([a-z_]+)'/g)) codes.add(m[1]!);
    }
    expect(codes.size).toBeGreaterThan(20);
    for (const lang of ['en', 'de']) {
      const table = doc[lang][P].VALIDATION as Record<string, string>;
      for (const code of codes) expect(table[code.toUpperCase()], `${lang} VALIDATION.${code.toUpperCase()}`).toBeTruthy();
    }
  });
});

/**
 * Grav parses its YAML with the libyaml extension when the server has it (YamlFormatter::decode(), "native"), and
 * libyaml follows YAML 1.1: a bare `YES:` key is the key 1, a bare `NO:` the key 0, a bare value `Off` is false. The
 * Symfony parser Grav falls back to does not do that, so a site without libyaml never shows it: on a server with
 * libyaml the German tester page showed COMMON.YES in English, and the blueprint's "Off" option had no label. Every file the plugin ships must read the same under both versions of the language.
 */
describe('shipped YAML files', () => {
  const files = ['languages.yaml', 'blueprints.yaml', 'redirect-manager.yaml', ...readdirSync(join(pluginRoot, 'config')).filter((f) => f.endsWith('.yaml')).map((f) => join('config', f))];

  /** every difference between two parses, as "path: value 1.2 vs value 1.1" */
  function differences(a: unknown, b: unknown, path = ''): string[] {
    if (a && b && typeof a === 'object' && typeof b === 'object') {
      const keys = new Set([...Object.keys(a), ...Object.keys(b)]);
      return [...keys].flatMap((k) => {
        const here = path ? `${path}.${k}` : k;
        if (!(k in b)) return [`${here}: only in YAML 1.2`];
        if (!(k in a)) return [`${here}: only in YAML 1.1`];
        return differences((a as Record<string, unknown>)[k], (b as Record<string, unknown>)[k], here);
      });
    }
    return a === b ? [] : [`${path}: ${JSON.stringify(a)} in YAML 1.2, ${JSON.stringify(b)} in YAML 1.1`];
  }

  it.each(files)('%s reads the same under YAML 1.1 (libyaml) and 1.2 (Symfony)', (file) => {
    const source = readFileSync(join(pluginRoot, file), 'utf8');
    expect(differences(YAML.parse(source, { version: '1.2' }), YAML.parse(source, { version: '1.1' }))).toEqual([]);
  });

  it('the differences are found when a key or value is a YAML 1.1 word', () => {
    const source = 'UI:\n  COMMON:\n    NO: Nein\n    YES: Ja\n  OPTION: Off\n  FINE: "Off"\n';
    expect(differences(YAML.parse(source, { version: '1.2' }), YAML.parse(source, { version: '1.1' }))).toEqual([
      'UI.COMMON.NO: only in YAML 1.2',
      'UI.COMMON.YES: only in YAML 1.2',
      'UI.COMMON.false: only in YAML 1.1',
      'UI.COMMON.true: only in YAML 1.1',
      'UI.OPTION: "Off" in YAML 1.2, false in YAML 1.1',
    ]);
  });
});
