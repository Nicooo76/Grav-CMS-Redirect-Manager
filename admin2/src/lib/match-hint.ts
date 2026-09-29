/** Spots sources that were typed for another match type, so the editor can offer the switch. */
import type { MatchType } from './types';

/** True when the text uses regular expression syntax that means nothing in a plain path or a wildcard. */
export function looksLikeRegex(source: string): boolean {
  const s = source.trim();
  if (!s) return false;
  return (
    s.startsWith('^') ||
    (s.endsWith('$') && !s.endsWith('\\$')) ||
    /\(.*\)/.test(s) ||
    /\[[^\]]+\]/.test(s) ||
    /\\[dDwWsSbB.\/\\()[\]{}+*?|^$-]/.test(s) ||
    /\.[*+]/.test(s) ||
    /\{\d+(,\d*)?\}/.test(s) ||
    s.includes('|')
  );
}

/**
 * The match type that fits the source better than the selected one, or null when the selection is fine.
 * Never switches anything by itself: the editor shows a hint with a button.
 */
export function suggestMatchType(source: string, selected: MatchType): MatchType | null {
  const s = source.trim();
  if (!s) return null;
  const regex = looksLikeRegex(s);
  if (selected === 'exact') {
    if (regex) return 'regex';
    return s.includes('*') ? 'wildcard' : null;
  }
  if (selected === 'wildcard') return regex ? 'regex' : null;
  // regex: a plain path with * is almost certainly meant as a wildcard ("/blog/*" is not a useful regex)
  if (!regex && s.includes('*') && !/[?^$]/.test(s)) return 'wildcard';
  return null;
}
