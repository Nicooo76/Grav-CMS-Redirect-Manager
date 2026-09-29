import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import type { Locator } from '@playwright/test';
import { parse } from 'yaml';
import { repoRoot } from './env';

/**
 * Every visible or accessible text of an element and its (open) shadow roots: text nodes and the text attributes.
 * `skip` is a selector of elements that show the user's own data (the cells of a file's sample rows), not UI text.
 */
export async function shadowTexts(host: Locator, skip = ''): Promise<string[]> {
  return host.evaluate((el, skipSelector) => {
    const out: string[] = [];
    const ATTRS = ['aria-label', 'title', 'placeholder', 'alt', 'aria-description'];
    const walk = (node: Node) => {
      if (node.nodeType === Node.TEXT_NODE) {
        const parent = (node as Text).parentElement;
        if (parent && !['STYLE', 'SCRIPT', 'NOSCRIPT'].includes(parent.tagName)) {
          const text = (node.textContent ?? '').replace(/\s+/g, ' ').trim();
          if (text) out.push(text);
        }
        return;
      }
      if (node.nodeType === Node.ELEMENT_NODE) {
        const e = node as Element;
        if (['STYLE', 'SCRIPT'].includes(e.tagName)) return;
        if (skipSelector && e.matches(skipSelector)) return;
        for (const a of ATTRS) {
          const v = e.getAttribute(a);
          if (v && v.trim()) out.push(v.replace(/\s+/g, ' ').trim());
        }
        if (e.shadowRoot) e.shadowRoot.childNodes.forEach(walk);
      }
      node.childNodes.forEach(walk);
    };
    if ((el as Element).shadowRoot) (el as Element).shadowRoot!.childNodes.forEach(walk);
    else walk(el);
    return out;
  }, skip);
}

type Dict = Record<string, string>;

let cached: { en: Dict; de: Dict } | undefined;

/** The plugin's UI strings as the host loads them (languages.yaml, section UI), flat. */
export function uiDictionaries(): { en: Dict; de: Dict } {
  if (!cached) {
    const doc = parse(readFileSync(join(repoRoot, 'languages.yaml'), 'utf8')) as Record<string, any>;
    const flat = (lang: string): Dict => {
      const out: Dict = {};
      const walk = (prefix: string, v: unknown) => {
        if (v && typeof v === 'object') for (const [k, x] of Object.entries(v)) walk(prefix ? `${prefix}.${k}` : k, x);
        else if (typeof v === 'string') out[prefix] = v;
      };
      walk('', doc[lang]?.PLUGIN_REDIRECT_MANAGER?.UI ?? {});
      return out;
    };
    cached = { en: flat('en'), de: flat('de') };
  }
  return cached;
}

const escapeRe = (s: string) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/** Index of the brace closing the one at `start`. */
function closing(s: string, start: number): number {
  let depth = 0;
  for (let i = start; i < s.length; i++) {
    if (s[i] === '{') depth++;
    else if (s[i] === '}' && --depth === 0) return i;
  }
  return -1;
}

/** A message template ("{n, plural, one {# hit} other {# hits}}", "{name}") as a regular expression source. */
export function templateToRegex(template: string): string {
  let out = '';
  for (let i = 0; i < template.length; ) {
    if (template[i] !== '{') {
      out += escapeRe(template[i++]);
      continue;
    }
    const end = closing(template, i);
    if (end === -1) {
      out += escapeRe(template.slice(i));
      break;
    }
    const inner = template.slice(i + 1, end);
    if (!inner.includes(',')) out += '.+?';
    else {
      const body = inner.slice(inner.indexOf(',', inner.indexOf(',') + 1) + 1);
      const options: string[] = [];
      for (let j = 0; j < body.length; ) {
        const open = body.indexOf('{', j);
        if (open === -1) break;
        const close = closing(body, open);
        if (close === -1) break;
        options.push(templateToRegex(body.slice(open + 1, close).replace(/#/g, '{n}')));
        j = close + 1;
      }
      out += `(?:${options.join('|') || '.+?'})`;
    }
    i = end + 1;
  }
  return out;
}

/** How Admin 2's t() shows a key it does not know ("FILTER.ALL" -> "Filter All"): candidates for a leaked key. */
function humanized(key: string): string[] {
  const words = (s: string) => s.split('_').map((w) => w.charAt(0) + w.slice(1).toLowerCase()).join(' ');
  const parts = key.split('.');
  return [words(parts[parts.length - 1]), parts.map(words).join(' ')].filter((h) => /[A-Za-z]{3}/.test(h));
}

export interface Leaks {
  rawKeys: string[];
  english: string[];
  humanized: string[];
}

/**
 * What must not be on a German screen: raw translation keys (`PLUGIN_REDIRECT_MANAGER.`, `TAB.RULES`), a text that is
 * the English string of a key whose German string is different (the English fallback shipped in the bundle shows when
 * the host has no German text), and a humanized key ("Filter Match").
 */
export function findLeaks(texts: string[]): Leaks {
  const { en, de } = uiDictionaries();
  const deValues = new Set(Object.values(de));
  const enPatterns: Array<{ key: string; re: RegExp }> = [];
  const enExact = new Map<string, string>();
  for (const [key, value] of Object.entries(en)) {
    if (de[key] === value || !value.trim()) continue;
    if (value.includes('{')) enPatterns.push({ key, re: new RegExp(`^${templateToRegex(value)}$`) });
    else enExact.set(value, key);
  }
  const human = new Map<string, string>();
  for (const key of Object.keys(en)) for (const h of humanized(key)) if (!deValues.has(h) && !Object.values(en).includes(h)) human.set(h, key);

  const leaks: Leaks = { rawKeys: [], english: [], humanized: [] };
  for (const text of new Set(texts)) {
    if (/PLUGIN_REDIRECT_MANAGER\./.test(text) || /\b[A-Z][A-Z0-9]+(?:_[A-Z0-9]+)*\.[A-Z][A-Z0-9]+(?:_[A-Z0-9]+)*\b/.test(text)) leaks.rawKeys.push(text);
    else if (enExact.has(text) && !deValues.has(text)) leaks.english.push(`${text}  [${enExact.get(text)}]`);
    else {
      const p = enPatterns.find((x) => x.re.test(text) && !deValues.has(text));
      if (p) leaks.english.push(`${text}  [${p.key}]`);
      else if (human.has(text)) leaks.humanized.push(`${text}  [${human.get(text)}]`);
    }
  }
  return leaks;
}
