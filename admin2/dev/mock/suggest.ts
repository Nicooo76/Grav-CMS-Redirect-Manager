/** Tiny stand-in for classes/Suggest/Suggester: scores existing pages against a missing path. */
import type { PageHit, SuggestionCandidate, SuggestionReason } from '../../src/lib/types';

const lev = (a: string, b: string): number => {
  const row = Array.from({ length: b.length + 1 }, (_, i) => i);
  for (let i = 1; i <= a.length; i++) {
    let prev = row[0]!;
    row[0] = i;
    for (let j = 1; j <= b.length; j++) {
      const tmp = row[j]!;
      row[j] = Math.min(row[j]! + 1, row[j - 1]! + 1, prev + (a[i - 1] === b[j - 1] ? 0 : 1));
      prev = tmp;
    }
  }
  return row[b.length]!;
};
const slugOf = (p: string) => (p.split('?')[0]!.replace(/\/+$/, '').split('/').pop() ?? '').replace(/\.[a-z0-9]{2,4}$/i, '').toLowerCase();
const tokens = (s: string) => s.toLowerCase().split(/[^a-z0-9äöüß]+/).filter((t) => t.length > 2 && !/^\d+$/.test(t));

export function suggest(pages: readonly PageHit[], path: string, limit = 5, language?: string): SuggestionCandidate[] {
  const q = slugOf(path);
  const qTokens = tokens(path);
  const best = new Map<string, SuggestionCandidate>();
  const offer = (page: PageHit, score: number, reason: SuggestionReason, details: Record<string, unknown> = {}) => {
    const cur = best.get(page.route);
    if (!cur || cur.score < score) best.set(page.route, { target: page.route, score: Math.round(score * 100) / 100, reason, page_title: page.title, details });
  };
  const wantEn = language === 'en' || path.startsWith('/en/');
  for (const p of pages) {
    const s = slugOf(p.route);
    if (!s || p.route === '/') continue;
    if (q && s === q) offer(p, 0.95, p.route.startsWith('/en/') !== wantEn ? 'other_language' : 'same_slug', { slug: s });
    const sim = q ? 1 - lev(q, s) / Math.max(q.length, s.length) : 0;
    if (sim >= 0.7) offer(p, 0.5 + sim * 0.4, 'similar_route', { similarity: Math.round(sim * 100) / 100 });
    const hay = new Set([...tokens(p.route), ...tokens(p.title)]);
    const overlap = qTokens.filter((t) => hay.has(t)).length / Math.max(1, qTokens.length);
    if (overlap >= 0.5) offer(p, 0.45 + overlap * 0.35, 'title_match', { tokens: qTokens });
    if (/^\/(category|tag|kategorie|schlagwort)\//.test(path) && (p.route === '/blog' || p.route === '/journal')) offer(p, 0.55, 'taxonomy_match');
  }
  const parts = path.split('?')[0]!.split('/').filter(Boolean);
  for (let i = parts.length - 1; i > 0; i--) {
    const parent = pages.find((p) => p.route === '/' + parts.slice(0, i).join('/'));
    if (parent) {
      offer(parent, 0.4, 'parent_fallback');
      break;
    }
  }
  const home = pages.find((p) => p.route === '/');
  if (home) offer(home, 0.3, 'home_fallback');
  return [...best.values()].sort((a, b) => b.score - a.score).slice(0, limit);
}
