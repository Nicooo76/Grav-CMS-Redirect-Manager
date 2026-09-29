/**
 * Badge and issue analyser. Full recompute is O(n) using a source map; a single candidate rule
 * (validate / dry run) is analysed against the same index without touching state.
 */
import type { Issue, Rule, RuleBadge } from '../../src/lib/types';
import { DAY } from './util';
import { REDIRECT_STATUS } from './rules';
import { normalizePath, stateOf } from './match';

export interface RuleIndex {
  /** literal exact sources (no query) -> rules */
  bySource: Map<string, Rule[]>;
  byConflict: Map<string, Rule[]>;
}

const key = (path: string, ci = true): string => {
  const p = normalizePath(path.split('?')[0]!);
  const v = p.length > 1 ? p.replace(/\/+$/, '') : p;
  return ci ? v.toLowerCase() : v;
};
/** Two rules conflict when this key is equal (exact sources keep their query). */
const conflictKey = (r: Rule): string =>
  `${r.match_type}|${r.match_type === 'exact' ? key(r.source) + (r.source.includes('?') ? '?' + r.source.split('?')[1] : '') : r.source.toLowerCase()}`;
const literal = (t: string) => !/[$]\d|\$\{|\{[A-Za-z_]/.test(t);
const isRedirect = (r: Rule) => REDIRECT_STATUS.includes(r.status);

export function buildIndex(rules: readonly Rule[], now = Date.now()): RuleIndex {
  const bySource = new Map<string, Rule[]>();
  const byConflict = new Map<string, Rule[]>();
  for (const r of rules) {
    if (stateOf(r, now) !== 'active') continue;
    if (r.match_type === 'exact' && !r.source.includes('?')) {
      const k = key(r.source);
      (bySource.get(k) ?? bySource.set(k, []).get(k)!).push(r);
    }
    const ck = conflictKey(r);
    (byConflict.get(ck) ?? byConflict.set(ck, []).get(ck)!).push(r);
  }
  return { bySource, byConflict };
}

const inter = (a: string[], b: string[]) => !a.length || !b.length || a.some((x) => b.includes(x));
function overlap(a: Rule, b: Rule): boolean {
  const x = a.conditions;
  const y = b.conditions;
  return (
    inter(x.hosts, y.hosts) && inter(x.languages, y.languages) && inter(x.schemes, y.schemes) &&
    (!x.rules.length || !y.rules.length || JSON.stringify(x.rules) === JSON.stringify(y.rules))
  );
}

/** Follows the redirect chain of one rule through literal exact rules. */
export function follow(start: Rule, idx: RuleIndex): { chain: string[]; loop: boolean } {
  const chain = [start.source, start.target];
  if (start.target_type === 'url' || !literal(start.target)) return { chain, loop: false };
  const seen = new Set([key(start.source)]);
  let cur = start.target;
  for (let i = 0; i < 12; i++) {
    const k = key(cur);
    if (seen.has(k)) return { chain, loop: true };
    seen.add(k);
    const next = idx.bySource.get(k)?.find((r) => r.id !== start.id && isRedirect(r));
    if (!next || !literal(next.target)) break;
    chain.push(next.target);
    cur = next.target;
    if (next.target_type === 'url') break;
  }
  return { chain, loop: false };
}

export interface AnalysisCtx {
  now: number;
  /** rule id -> HTTP status (0 = timeout) reported by the last live check */
  dead: ReadonlyMap<string, number>;
}

export function analyseRule(rule: Rule, idx: RuleIndex, ctx: AnalysisCtx): { badges: RuleBadge[]; issues: Issue[] } {
  const state = stateOf(rule, ctx.now);
  const badges: RuleBadge[] = [state];
  const issues: Issue[] = [];

  if (state === 'active' && isRedirect(rule)) {
    const { chain, loop } = follow(rule, idx);
    if (loop) {
      badges.push('loop');
      issues.push({ code: 'loop', severity: 'error', field: 'target', message: `Redirect loop: ${chain.join(' -> ')}.`, params: { chain, rule_ids: [rule.id], hops: chain.length - 1, cycle: [rule.id] } });
    } else if (chain.length > 2) {
      badges.push('chain');
      const shortcut = chain[chain.length - 1]!;
      issues.push({ code: 'chain', severity: 'warning', field: 'target', message: `Redirect chain of ${chain.length - 1} hops: ${chain.join(' -> ')}. Point this rule at ${shortcut} directly.`, params: { chain, rule_ids: [], hops: chain.length - 1, shortcut, shortcut_status: null } });
    }
  }

  if (state === 'active') {
    const group = idx.byConflict.get(conflictKey(rule));
    const other = group?.filter((o) => o.id !== rule.id && overlap(rule, o)).sort((a, b) => b.priority - a.priority)[0];
    if (other) {
      badges.push('conflict');
      issues.push({ code: 'conflict', severity: 'warning', field: 'source', message: `Other rules match the same requests with a different target: ${other.id}. Only the highest-ranked one applies.`, params: { rule_ids: [other.id] } });
    }
    const dead = ctx.dead.get(rule.id);
    if (dead !== undefined) {
      badges.push('dead_target');
      issues.push({ code: 'dead_target', severity: 'warning', field: 'target', message: dead ? `The target answers with ${dead} (last live check).` : 'The target does not answer (timeout in the last live check).', params: { status: dead } });
    }
  }

  if (state === 'active') {
    const last = rule.stats?.last_hit ? Date.parse(rule.stats.last_hit) : null;
    const created = rule.created_at ? Date.parse(rule.created_at) : 0;
    if (last === null ? created < ctx.now - 14 * DAY : last < ctx.now - 90 * DAY) badges.push('unused');
  }
  return { badges, issues };
}

/** Recomputes badges + issues of every rule in place. */
export function analyseAll(rules: readonly Rule[], ctx: AnalysisCtx): void {
  const idx = buildIndex(rules, ctx.now);
  for (const r of rules) {
    const a = analyseRule(r, idx, ctx);
    r.badges = a.badges;
    r.issues = a.issues;
  }
}
