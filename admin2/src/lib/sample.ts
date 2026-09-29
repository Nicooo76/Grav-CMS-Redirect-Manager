/** Example URLs derived from a rule source, for live preview and the tester link. */
import type { MatchType } from './types';

/** Generates a path that a simple regular expression source would match (best effort). */
export function sampleFromRegex(source: string): string {
  let s = source.trim().replace(/^\^/, '').replace(/\$$/, '');
  // named groups (?P<name>...) / (?<name>...) -> plain groups
  s = s.replace(/\(\?P?<[A-Za-z_][A-Za-z0-9_]*>/g, '(');
  // non-capturing groups
  s = s.replace(/\(\?:/g, '(');
  let out = '';
  let i = 0;
  const digitsFor = (q: string) => {
    const m = /^\{(\d+)(?:,(\d*))?\}$/.exec(q);
    return m ? Math.max(1, Number(m[1])) : 1;
  };
  while (i < s.length) {
    const c = s[i];
    if (c === '\\') {
      const n = s[i + 1] ?? '';
      i += 2;
      const quant = readQuant(s, i);
      i += quant.length;
      const count = digitsFor(quant);
      if (n === 'd') out += '2024'.slice(0, Math.min(4, Math.max(count, 1))).padEnd(count, '0');
      else if (n === 'w') out += 'example'.slice(0, quant ? Math.max(count, 4) : 7);
      else if (n === 's') out += ' ';
      else out += n; // escaped literal like \. or \/
      continue;
    }
    if (c === '(' || c === ')' || c === '|') {
      if (c === '|') {
        // pick the first alternative: skip to the closing paren
        let depth = 0;
        while (i < s.length && !(s[i] === ')' && depth === 0)) {
          if (s[i] === '(') depth++;
          else if (s[i] === ')') depth--;
          i++;
        }
        continue;
      }
      i++;
      continue;
    }
    if (c === '[') {
      const end = s.indexOf(']', i + 1);
      if (end === -1) break;
      const set = s.slice(i + 1, end);
      i = end + 1;
      const quant = readQuant(s, i);
      i += quant.length;
      const n = quant === '+' || quant === '*' || quant === '?' ? 1 : digitsFor(quant);
      const ch = set.startsWith('^') ? 'x' : /^0-9|^\\d/.test(set) ? '1' : /^A-Z/.test(set) ? 'A' : set[0] === '\\' ? 'x' : set[0] || 'x';
      out += ch.repeat(quant === '+' || quant === '*' ? 7 : n).replace(/^(x{7})$/, 'example');
      continue;
    }
    if (c === '.') {
      i++;
      const quant = readQuant(s, i);
      i += quant.length;
      out += quant === '' ? 'x' : 'example';
      continue;
    }
    // literal followed by an optional quantifier
    i++;
    const quant = readQuant(s, i);
    i += quant.length;
    if (quant === '?' || quant === '*') continue;
    out += c;
  }
  out = out.replace(/\/{2,}/g, '/');
  return out.startsWith('/') ? out : `/${out}`;
}

function readQuant(s: string, i: number): string {
  const c = s[i];
  if (c === '+' || c === '*' || c === '?') return c;
  if (c === '{') {
    const end = s.indexOf('}', i);
    if (end !== -1 && /^\{\d+(,\d*)?\}$/.test(s.slice(i, end + 1))) return s.slice(i, end + 1);
  }
  return '';
}

export function sampleFromSource(source: string, matchType: MatchType, origin = ''): string {
  const src = source.trim();
  if (!src) return '';
  let path: string;
  // a * in an exact source is meant as a wildcard: the example URL fills it instead of copying the pattern
  if (matchType === 'wildcard' || (matchType === 'exact' && src.includes('*'))) path = src.replace(/\*+/g, 'example');
  else if (matchType === 'regex') path = sampleFromRegex(src);
  else path = src;
  if (!path.startsWith('/') && !/^[a-z]+:\/\//i.test(path)) path = `/${path}`;
  return origin ? origin.replace(/\/$/, '') + path : path;
}
