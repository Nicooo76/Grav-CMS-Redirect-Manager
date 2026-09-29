/**
 * Dev-only mock backend for the Redirect Manager Admin 2 UI.
 * Replaces `window.fetch` for `<server><prefix>/redirects/...` and implements docs/API.md with
 * stateful in-memory data. Everything else goes to the real fetch.
 */
import type {
  ImportCommitResult,
  ImportIssue,
  ImportOptions,
  ImportPreview,
  ImportRow,
  Issue,
  NotFoundEntry,
  NotFoundRow,
  Rule,
  RuleBadge,
  RuleInput,
  SitemapDiff,
  Stats,
  StoredSuggestion,
  UaClass,
} from '../src/lib/types';
import type { CheckResult, MockOptions, MockState, NotFoundGroup, ResolvedOptions } from './mock/types';
import { analyseAll, analyseRule, buildIndex, follow, type RuleIndex } from './mock/analysis';
import { buildChecks, buildState, uaSamples } from './mock/fixtures';
import { FORMATS, detectFormat, exportRules, parseImport, type ParsedRow } from './mock/importExport';
import { buildRulePreview, evaluate, pageKey, runTest, sortRules, splitLanguage, normalizePath, type TestBody } from './mock/match';
import { emptyRuleStats, makeRule, validateFields, type FieldIssue } from './mock/rules';
import { suggest } from './mock/suggest';
import { ApiError, DAY, dayKey, globTest, hash, isoAtom, mulberry32, pick, rint, weighted, type FieldError } from './mock/util';

export type { MockOptions, MockState } from './mock/types';
export { buildRulePreview, matchRule } from './mock/match';

declare global {
  interface Window {
    __GRAV_API_SERVER_URL?: string;
    __GRAV_API_PREFIX?: string;
  }
}

interface Req {
  method: string;
  path: string;
  params: string[];
  query: URLSearchParams;
  body: Record<string, any>;
  headers: Headers;
}
interface Res {
  status?: number;
  data: unknown;
  meta?: Record<string, unknown>;
  headers?: Record<string, string>;
}
type Handler = (r: Req) => Res;

const RULE_FIELDS = ['source', 'target', 'match_type', 'status', 'enabled', 'priority', 'target_type', 'case_sensitive', 'ignore_trailing_slash', 'query_mode', 'query_params', 'query_ignore', 'continue', 'only_if_not_found', 'active_from', 'expires_at', 'note', 'group', 'tags', 'origin', 'conditions'] as const;
const fieldsOf = (o: Record<string, any>): RuleInput => Object.fromEntries(RULE_FIELDS.filter((k) => o[k] !== undefined).map((k) => [k, o[k]]));
const num = (v: string | null, d: number) => (v !== null && v !== '' && Number.isFinite(Number(v)) ? Number(v) : d);
const flag = (v: string | null) => v === '1' || v === 'true';

export function installMockApi(options: MockOptions = {}): { reset(): void; state: MockState; uninstall(): void } {
  const opts: ResolvedOptions = { rules: options.rules ?? 300, latency: options.latency ?? [60, 220], seed: options.seed ?? 1, failRate: options.failRate ?? 0 };
  const g = globalThis as unknown as Window & typeof globalThis;
  const realFetch = g.fetch.bind(g);
  const state: MockState = buildState(opts);
  let netRng = mulberry32(opts.seed ^ 0x9e3779b9);

  /* ---- caches over the rule list ---- */
  let sorted: Rule[] | null = null;
  let index: RuleIndex | null = null;
  let existing: Set<string> | null = null;
  let ruleKeys: Set<string> | null = null;
  let deadTargets: Set<string> | null = null;
  const hay = new WeakMap<Rule, string>();
  const getSorted = () => (sorted ??= sortRules(state.rules));
  const getIndex = () => (index ??= buildIndex(state.rules));
  const getExisting = () => (existing ??= new Set(state.pages.map((p) => pageKey(p.route))));
  const getRuleKeys = () => (ruleKeys ??= new Set(state.rules.filter((r) => r.enabled && r.match_type === 'exact').map((r) => pageKey(r.source))));
  /** Fixture pages, plus the dynamic content areas of the mock site, minus targets the last live check found dead. */
  const DYNAMIC = /^\/(journal|shop|user\/(images|downloads)|kampagne|produkte)\//;
  const pageExists = (key: string): boolean => {
    if (getExisting().has(key)) return true;
    if (!DYNAMIC.test(key)) return false;
    deadTargets ??= new Set([...state.dead.keys()].map((id) => state.rules.find((r) => r.id === id)).filter((r): r is Rule => !!r).map((r) => pageKey(r.target.split('?')[0]!)));
    return !deadTargets.has(key);
  };
  const hayOf = (r: Rule) => {
    let h = hay.get(r);
    if (h === undefined) {
      h = [r.source, r.target, r.note, r.group, ...r.tags].join('\n').toLowerCase();
      hay.set(r, h);
    }
    return h;
  };
  const byId = (id: string): Rule => {
    const r = state.rules.find((x) => x.id === id);
    if (!r) throw new ApiError(404, 'Not Found', `Regel ${id} existiert nicht.`);
    return r;
  };
  const recompute = () => {
    analyseAll(state.rules, { now: Date.now(), dead: state.dead });
    state.groups = [...new Set(state.rules.map((r) => r.group).filter(Boolean))].sort();
    sorted = index = existing = ruleKeys = deadTargets = null;
  };
  const newId = () => 'r' + Date.now().toString(16).padStart(12, '0') + (state.seq++).toString(16).padStart(6, '0');
  const bump = (r: Rule) => {
    state.rev.set(r.id, (state.rev.get(r.id) ?? 0) + 1);
    hay.delete(r);
  };
  const etag = (r: Rule) => `"${r.id}-${state.rev.get(r.id) ?? 0}"`;
  const toErrors = (issues: FieldIssue[]): FieldError[] =>
    issues.filter((i) => i.severity === 'error').map((i) => ({ field: i.field ?? '', code: i.code, message: i.message, severity: i.severity }));
  const unprocessable = (issues: FieldIssue[]) => new ApiError(422, 'Unprocessable Entity', issues.find((i) => i.severity === 'error')?.message ?? 'Ungültige Daten.', toErrors(issues));

  /** Field errors plus analysis (loop / chain / conflict) for a rule that is not stored yet. */
  function candidateIssues(rule: Rule): FieldIssue[] {
    const out: FieldIssue[] = validateFields(rule);
    if (out.some((i) => i.code === 'invalid_regex' || i.code === 'invalid_pattern')) return out;
    for (const i of analyseRule(rule, getIndex(), { now: Date.now(), dead: new Map() }).issues) out.push({ ...i, field: i.code === 'conflict' ? 'source' : 'target' });
    return out;
  }
  function insert(input: RuleInput, origin?: Rule['origin']): Rule {
    const rule = makeRule({ ...input, origin: origin ?? input.origin }, newId(), isoAtom(Date.now()));
    const issues = candidateIssues(rule);
    if (issues.some((i) => i.severity === 'error')) throw unprocessable(issues);
    state.rules.push(rule);
    state.rev.set(rule.id, 1);
    return rule;
  }

  /* ---- routing table ---- */
  const routes: [string, RegExp, Handler][] = [];
  const on = (method: string, path: string, h: Handler) => routes.push([method, new RegExp('^' + path.replace(/:id/g, '([^/]+)') + '/?$'), h]);

  /* ---- rules ---- */
  on('GET', '/redirects/rules', ({ query: q }) => {
    const term = (q.get('q') ?? '').trim().toLowerCase();
    const mt = q.get('match_type');
    const status = num(q.get('status'), 0);
    const st = q.get('state');
    const badge = q.get('badge') as RuleBadge | null;
    const group = q.get('group');
    const origin = q.get('origin');
    const unused = q.has('unused_days') && q.get('unused_days') !== '' ? num(q.get('unused_days'), 0) : null;
    const cutoff = Date.now() - (unused ?? 0) * DAY;
    const base = state.rules.filter(
      (r) =>
        (!term || hayOf(r).includes(term)) &&
        (!mt || r.match_type === mt) &&
        (!status || r.status === status) &&
        (!st || r.badges?.[0] === st) &&
        (!group || r.group === group) &&
        (!origin || r.origin === origin) &&
        (unused === null || !r.stats?.last_hit || Date.parse(r.stats.last_hit) < cutoff),
    );
    const counts: Partial<Record<RuleBadge, number>> = {};
    for (const r of base) for (const b of r.badges ?? []) counts[b] = (counts[b] ?? 0) + 1;
    const list = badge ? base.filter((r) => r.badges?.includes(badge)) : base;
    const sort = q.get('sort') ?? 'priority';
    const dir = (q.get('dir') ?? (['source', 'target', 'status'].includes(sort) ? 'asc' : 'desc')) === 'asc' ? 1 : -1;
    const key: Record<string, (r: Rule) => string | number> = {
      priority: (r) => r.priority, source: (r) => r.source.toLowerCase(), target: (r) => r.target.toLowerCase(), status: (r) => r.status,
      hits: (r) => r.stats?.total ?? 0, last_hit: (r) => r.stats?.last_hit ?? '', created_at: (r) => r.created_at ?? '', updated_at: (r) => r.updated_at ?? '',
    };
    const k = key[sort] ?? key.priority!;
    list.sort((a, b) => (k(a) < k(b) ? -dir : k(a) > k(b) ? dir : 0) || b.priority - a.priority);
    const per = Math.min(10000, Math.max(1, num(q.get('per_page'), 25)));
    const page = Math.max(1, num(q.get('page'), 1));
    return { data: list.slice((page - 1) * per, page * per), meta: { total: list.length, page, per_page: per, groups: state.groups, counts } };
  });

  on('POST', '/redirects/rules', ({ body, query }) => {
    if (flag(query.get('dry_run'))) {
      const rule = makeRule(fieldsOf(body), '', isoAtom(Date.now()));
      const issues = candidateIssues(rule);
      const a = analyseRule(rule, getIndex(), { now: Date.now(), dead: new Map() });
      return { data: { rule: { ...rule, badges: a.badges, issues: a.issues }, issues, preview: typeof body.sample === 'string' ? buildRulePreview(rule, body.sample) : null } };
    }
    const rule = insert(fieldsOf(body));
    recompute();
    return { status: 201, data: rule, headers: { ETag: etag(rule) } };
  });

  on('POST', '/redirects/rules/restore', ({ body }) => {
    if (!Array.isArray(body.rules)) throw new ApiError(422, 'Unprocessable Entity', 'rules muss eine Liste sein.', [{ field: 'rules', code: 'invalid', message: 'rules muss eine Liste sein.', severity: 'error' }]);
    const restored: Rule[] = [];
    for (const src of body.rules as Rule[]) {
      if (!src?.id || state.rules.some((r) => r.id === src.id)) continue;
      const r = { ...makeRule(src, src.id, src.created_at ?? isoAtom(Date.now())), updated_at: src.updated_at, stats: src.stats ?? emptyRuleStats() };
      state.rules.push(r);
      state.rev.set(r.id, 1);
      restored.push(r);
    }
    recompute();
    return { data: { affected: restored.length, rules: restored } };
  });

  on('POST', '/redirects/rules/bulk', ({ body }) => {
    const ids = new Set<string>(Array.isArray(body.ids) ? body.ids : []);
    const action = String(body.action);
    if (!['enable', 'disable', 'delete', 'set_status', 'set_group', 'add_tag', 'remove_tag'].includes(action)) throw new ApiError(422, 'Unprocessable Entity', `Unbekannte Aktion "${action}".`, [{ field: 'action', code: 'invalid_action', message: 'Unbekannte Aktion.', severity: 'error' }]);
    if (action === 'set_status' && ![200, 301, 302, 307, 308, 410, 451].includes(Number(body.value))) throw new ApiError(422, 'Unprocessable Entity', 'Ungültiger Status.', [{ field: 'value', code: 'invalid_status', message: 'Ungültiger Status.', severity: 'error' }]);
    const hit = state.rules.filter((r) => ids.has(r.id));
    const now = isoAtom(Date.now());
    if (action === 'delete') {
      state.rules = state.rules.filter((r) => !ids.has(r.id));
      hit.forEach((r) => state.rev.delete(r.id));
    } else {
      for (const r of hit) {
        if (action === 'enable') r.enabled = true;
        else if (action === 'disable') r.enabled = false;
        else if (action === 'set_status') r.status = Number(body.value) as Rule['status'];
        else if (action === 'set_group') r.group = String(body.value ?? '');
        else if (action === 'add_tag') r.tags = [...new Set([...r.tags, String(body.value)])];
        else r.tags = r.tags.filter((t) => t !== String(body.value));
        r.updated_at = now;
        bump(r);
      }
    }
    recompute();
    return { data: action === 'delete' ? { affected: hit.length, rules: hit } : { affected: hit.length } };
  });

  on('POST', '/redirects/rules/reorder', ({ body }) => {
    const rules = (Array.isArray(body.ids) ? (body.ids as string[]) : []).map(byId);
    const vals = rules.map((r) => r.priority).sort((a, b) => b - a);
    for (let i = 1; i < vals.length; i++) if (vals[i]! >= vals[i - 1]!) vals[i] = vals[i - 1]! - 1;
    rules.forEach((r, i) => { r.priority = vals[i]!; bump(r); });
    recompute();
    return { data: { affected: rules.length } };
  });

  on('POST', '/redirects/rules/validate', ({ body }) => {
    const rule = makeRule(fieldsOf(body), typeof body.id === 'string' ? body.id : '', isoAtom(Date.now()));
    const issues = candidateIssues(rule);
    return { data: { issues, preview: typeof body.sample === 'string' && body.sample ? buildRulePreview(rule, body.sample, body.language) : null } };
  });

  on('GET', '/redirects/rules/:id', ({ params }) => {
    const r = byId(params[0]!);
    return { data: r, headers: { ETag: etag(r) } };
  });

  on('PATCH', '/redirects/rules/:id', ({ params, body, headers }) => {
    const r = byId(params[0]!);
    const inm = headers.get('If-Match');
    if (inm && inm !== '*' && inm !== etag(r)) throw new ApiError(412, 'Precondition Failed', 'Die Regel wurde inzwischen geändert.');
    const patch = fieldsOf(body);
    if (typeof patch.target === 'string' && patch.target_type === undefined && /^https?:\/\//i.test(patch.target) !== (r.target_type === 'url')) patch.target_type = /^https?:\/\//i.test(patch.target) ? 'url' : 'route';
    const merged = makeRule({ ...r, ...patch }, r.id, r.created_at ?? isoAtom(Date.now()));
    const issues = candidateIssues(merged);
    if (issues.some((i) => i.severity === 'error')) throw unprocessable(issues);
    Object.assign(r, merged, { stats: r.stats, created_at: r.created_at, updated_at: isoAtom(Date.now()) });
    bump(r);
    recompute();
    return { data: r, headers: { ETag: etag(r) } };
  });

  on('DELETE', '/redirects/rules/:id', ({ params }) => {
    const r = byId(params[0]!);
    state.rules = state.rules.filter((x) => x !== r);
    state.rev.delete(r.id);
    recompute();
    return { data: r };
  });

  on('POST', '/redirects/rules/:id/shorten-chain', ({ params }) => {
    const r = byId(params[0]!);
    const { chain, loop } = follow(r, getIndex());
    if (loop || chain.length < 3) throw new ApiError(422, 'Unprocessable Entity', 'Diese Regel hat keine Kette, die sich verkürzen lässt.');
    r.target = chain[chain.length - 1]!;
    r.updated_at = isoAtom(Date.now());
    bump(r);
    recompute();
    return { data: r, headers: { ETag: etag(r) } };
  });

  on('GET', '/redirects/analysis', () => {
    const pickBy = (b: RuleBadge) => state.rules.filter((r) => r.badges?.includes(b));
    const issueOf = (r: Rule, code: string): Issue | undefined => r.issues?.find((i) => i.code === code);
    return {
      data: {
        chains: pickBy('chain').map((r) => ({ rule_id: r.id, source: r.source, target: r.target, ...issueOf(r, 'chain')?.params })),
        loops: pickBy('loop').map((r) => ({ rule_id: r.id, source: r.source, ...issueOf(r, 'loop')?.params })),
        conflicts: pickBy('conflict').map((r) => ({ rule_id: r.id, source: r.source, ...issueOf(r, 'conflict')?.params })),
        expired: pickBy('expired').map((r) => ({ rule_id: r.id, source: r.source, expires_at: r.expires_at })),
        unused: pickBy('unused').map((r) => ({ rule_id: r.id, source: r.source, last_hit: r.stats?.last_hit ?? null })),
      },
    };
  });

  on('GET', '/redirects/groups', () => {
    const count = (vals: Iterable<string>) => {
      const m = new Map<string, number>();
      for (const v of vals) if (v) m.set(v, (m.get(v) ?? 0) + 1);
      return [...m].map(([name, count]) => ({ name, count })).sort((a, b) => b.count - a.count || a.name.localeCompare(b.name));
    };
    return { data: { groups: count(state.rules.map((r) => r.group)), tags: count(state.rules.flatMap((r) => r.tags)) } };
  });

  /* ---- tester ---- */
  on('POST', '/redirects/test', ({ body }) => {
    if (typeof body.url !== 'string' || !body.url.trim()) throw new ApiError(422, 'Unprocessable Entity', 'url ist erforderlich.', [{ field: 'url', code: 'required', message: 'url ist erforderlich.', severity: 'error' }]);
    return { data: runTest(getSorted(), pageExists, body as TestBody, Date.now()) };
  });

  /* ---- 404 monitor ---- */
  const hitsOf = (n: NotFoundGroup) => Object.values(n.daily).reduce((a, b) => a + b, 0);
  const timeOf = (path: string, day: string) => isoAtom(Date.parse(day) + (hash(path + day) % 86400) * 1000);

  function windowOf(q: URLSearchParams): [string, string] {
    const from = q.get('from');
    if (from) return [from.slice(0, 10), (q.get('to') ?? dayKey(Date.now())).slice(0, 10)];
    return [dayKey(Date.now() - (num(q.get('days'), 30) - 1) * DAY), dayKey(Date.now())];
  }
  /** Rows for the window; `bots=0` and `class` scale hits by the share of the allowed UA classes. */
  function notFoundRows(q: URLSearchParams): (NotFoundRow & { _f: number })[] {
    const [lo, hi] = windowOf(q);
    const bots = q.get('bots') !== '0';
    const cls = (q.get('class') ?? '') as UaClass | '';
    const term = (q.get('q') ?? '').toLowerCase();
    const lang = q.get('language');
    const host = q.get('host');
    const withResolved = flag(q.get('include_resolved'));
    const allowed = (c: UaClass) => (cls ? c === cls : bots || (c !== 'bot' && c !== 'monitoring'));
    const keys = getRuleKeys();
    const rows: (NotFoundRow & { _f: number })[] = [];
    for (const n of state.notFound) {
      if ((n.resolved && !withResolved) || (term && !n.path.toLowerCase().includes(term)) || (lang && !n.languages.includes(lang)) || (host && !n.hosts.includes(host))) continue;
      const uaTotal = Object.values(n.ua).reduce((a, b) => a + b, 0) || 1;
      const f = (['browser', 'bot', 'monitoring', 'unknown'] as const).reduce((s, c) => s + (allowed(c) ? n.ua[c] : 0), 0) / uaTotal;
      const daily: Record<string, number> = {};
      let hits = 0;
      for (const [d, v] of Object.entries(n.daily)) {
        const s = Math.round(v * f);
        if (d >= lo && d <= hi && s > 0) { daily[d] = s; hits += s; }
      }
      if (!hits) continue;
      const share = hits / (hitsOf(n) || 1);
      const days = Object.keys(daily).sort();
      const ua: Partial<Record<UaClass, number>> = {};
      for (const c of ['browser', 'bot', 'monitoring', 'unknown'] as const) if (allowed(c)) ua[c] = Math.round(n.ua[c] * share);
      rows.push({
        path: n.path, hits, first_seen: timeOf(n.path, days[0]!), last_seen: timeOf(n.path, days[days.length - 1]!),
        top_referers: Object.entries(n.referers).map(([referer, v]) => ({ referer, hits: Math.max(1, Math.round(v * share * f)) })).sort((a, b) => b.hits - a.hits).slice(0, 5),
        daily, ua, languages: n.languages, hosts: n.hosts, has_rule: keys.has(pageKey(n.path)), best_suggestion: n.suggestion, resolved: n.resolved, _f: f,
      });
    }
    return rows;
  }
  const strip = ({ _f, ...row }: NotFoundRow & { _f: number }): NotFoundRow => row;

  on('GET', '/redirects/404', ({ query: q }) => {
    const rows = notFoundRows(q);
    const by_day: Record<string, number> = {};
    let hits = 0;
    for (const r of rows) for (const [d, v] of Object.entries(r.daily)) { by_day[d] = (by_day[d] ?? 0) + v; hits += v; }
    const sort = q.get('sort') ?? 'hits';
    const dir = (q.get('dir') ?? (sort === 'path' ? 'asc' : 'desc')) === 'asc' ? 1 : -1;
    const k = (r: NotFoundRow): string | number => (sort === 'path' ? r.path : sort === 'last' ? r.last_seen : sort === 'first' ? r.first_seen : r.hits);
    rows.sort((a, b) => (k(a) < k(b) ? -dir : k(a) > k(b) ? dir : 0) || a.path.localeCompare(b.path));
    const per = Math.min(500, Math.max(1, num(q.get('per_page'), 25)));
    const page = Math.max(1, num(q.get('page'), 1));
    return { data: rows.slice((page - 1) * per, page * per).map(strip), meta: { total: rows.length, page, per_page: per, totals: { hits, paths: rows.length, by_day } } };
  });

  on('GET', '/redirects/404/trend', ({ query: q }) => {
    const days = num(q.get('days'), 30);
    const bots = q.get('bots') !== '0';
    const out: Record<string, number> = {};
    for (let i = days - 1; i >= 0; i--) out[dayKey(Date.now() - i * DAY)] = 0;
    for (const n of state.notFound) {
      if (n.resolved) continue;
      const total = Object.values(n.ua).reduce((a, b) => a + b, 0) || 1;
      const f = bots ? 1 : (n.ua.browser + n.ua.unknown) / total;
      for (const [d, v] of Object.entries(n.daily)) if (d in out) out[d]! += Math.round(v * f);
    }
    return { data: { days: out } };
  });

  on('GET', '/redirects/404/entries', ({ query }) => {
    const n = state.notFound.find((x) => x.path === query.get('path'));
    if (!n) throw new ApiError(404, 'Not Found', 'Pfad nicht im 404-Log.');
    const rng = mulberry32(hash(n.path) ^ opts.seed);
    const out: NotFoundEntry[] = [];
    const refs = Object.entries(n.referers);
    const classes = Object.entries(n.ua) as [UaClass, number][];
    for (const [d, c] of Object.entries(n.daily).sort((a, b) => b[0].localeCompare(a[0]))) {
      for (let i = 0; i < c && out.length < 100; i++) {
        const ua_class = weighted(rng, classes);
        out.push({
          time: isoAtom(Date.parse(d) + rint(rng, 0, 86399) * 1000), path: n.path, query: rng() < 0.15 ? 'utm_source=newsletter' : '',
          referer: ua_class === 'browser' && refs.length ? weighted(rng, refs) : '', ua: pick(rng, uaSamples[ua_class]), ua_class,
          language: pick(rng, n.languages), host: pick(rng, n.hosts), ip: `${rint(rng, 11, 223)}.${rint(rng, 0, 255)}.${rint(rng, 0, 255)}.0`,
        });
      }
    }
    return { data: out.sort((a, b) => b.time.localeCompare(a.time)).slice(0, 100) };
  });

  on('POST', '/redirects/404/ignore', ({ body }) => {
    const pattern = String(body.pattern ?? '').trim();
    if (!pattern) throw new ApiError(422, 'Unprocessable Entity', 'pattern ist erforderlich.', [{ field: 'pattern', code: 'required', message: 'pattern ist erforderlich.', severity: 'error' }]);
    if (!state.ignorePatterns.includes(pattern)) state.ignorePatterns.push(pattern);
    const before = state.notFound.length;
    if (body.purge) state.notFound = state.notFound.filter((n) => !globTest(pattern, n.path));
    return { data: { pattern, removed: before - state.notFound.length, ignore_patterns: state.ignorePatterns } };
  });

  on('POST', '/redirects/404/resolve', ({ body }) => {
    const paths = new Set<string>(Array.isArray(body.paths) ? body.paths : []);
    let n = 0;
    for (const g2 of state.notFound) if (paths.has(g2.path)) { g2.resolved = body.resolved !== false; n++; }
    return { data: { updated: n } };
  });

  on('DELETE', '/redirects/404', ({ query, body }) => {
    const before = state.notFound.length;
    if (body.all === true) state.notFound = [];
    else if (query.get('path')) state.notFound = state.notFound.filter((n) => n.path !== query.get('path'));
    else throw new ApiError(422, 'Unprocessable Entity', 'path oder {all: true} erforderlich.');
    return { data: { deleted: before - state.notFound.length } };
  });

  /* ---- suggestions ---- */
  const suggestionById = (id: string): StoredSuggestion => {
    const s = state.suggestions.find((x) => x.id === id);
    if (!s) throw new ApiError(404, 'Not Found', `Vorschlag ${id} existiert nicht.`);
    return s;
  };
  const acceptSuggestion = (s: StoredSuggestion, over: { target?: string; status?: number } = {}): Rule => {
    const rule = insert({ source: s.path, target: over.target ?? s.target, status: (over.status ?? 301) as Rule['status'], origin: 'suggestion', });
    s.status = 'accepted';
    if (over.target) s.target = over.target;
    return rule;
  };

  on('GET', '/redirects/suggest', ({ query: q }) => {
    const path = q.get('path');
    if (!path) throw new ApiError(422, 'Unprocessable Entity', 'path ist erforderlich.', [{ field: 'path', code: 'required', message: 'path ist erforderlich.', severity: 'error' }]);
    return { data: suggest(state.pages, path, Math.min(20, num(q.get('limit'), 5)), q.get('language') ?? undefined) };
  });

  on('GET', '/redirects/suggestions', ({ query: q }) => {
    const st = q.get('status');
    const min = num(q.get('min_score'), 0);
    const src = q.get('source');
    const list = state.suggestions.filter((s) => (!st || s.status === st) && s.score >= min && (!src || s.source === src)).sort((a, b) => b.score - a.score || b.hits - a.hits);
    return { data: list, meta: { total: list.length } };
  });

  on('POST', '/redirects/suggestions/generate', () => {
    let created = 0;
    let updated = 0;
    const keys = getRuleKeys();
    for (const n of state.notFound) {
      if (n.resolved || keys.has(pageKey(n.path))) continue;
      const best = suggest(state.pages, n.path, 1)[0];
      if (!best) continue;
      const cur = state.suggestions.find((s) => s.path === n.path);
      if (!cur) {
        state.suggestions.push({ id: 'sg' + newId().slice(-8), path: n.path, target: best.target, score: best.score, reason: best.reason, page_title: best.page_title, hits: hitsOf(n), status: 'open', source: 'auto', created_at: isoAtom(Date.now()) });
        created++;
      } else if (cur.status === 'open' && (best.score > cur.score || cur.hits !== hitsOf(n))) {
        Object.assign(cur, { target: best.target, score: Math.max(cur.score, best.score), reason: best.reason, page_title: best.page_title, hits: hitsOf(n) });
        updated++;
      }
    }
    return { data: { created, updated, total_open: state.suggestions.filter((s) => s.status === 'open').length } };
  });

  on('POST', '/redirects/suggestions/bulk-accept', ({ body }) => {
    const min = Number(body.min_score ?? 0.8);
    const ids = Array.isArray(body.ids) ? new Set<string>(body.ids) : null;
    const rows = state.suggestions.filter((s) => s.status === 'open' && s.score >= min && (!ids || ids.has(s.id)));
    if (body.dry_run) return { data: { min_score: min, count: rows.length, rows } };
    const rules = rows.map((s) => acceptSuggestion(s));
    recompute();
    return { data: { created: rules.length, rules } };
  });

  on('POST', '/redirects/suggestions/:id/accept', ({ params, body }) => {
    const s = suggestionById(params[0]!);
    const rule = acceptSuggestion(s, { target: typeof body.target === 'string' ? body.target : undefined, status: body.status });
    recompute();
    return { data: { suggestion: s, rule } };
  });

  on('POST', '/redirects/suggestions/:id/reject', ({ params }) => {
    const s = suggestionById(params[0]!);
    s.status = 'rejected';
    return { data: { suggestion: s } };
  });

  /* ---- import / export ---- */
  const dupKey = (r: Pick<Rule, 'match_type' | 'source'>) => `${r.match_type}|${r.match_type === 'exact' ? pageKey(r.source.split('?')[0]!) + (r.source.includes('?') ? '?' + r.source.split('?')[1] : '') : r.source.toLowerCase()}`;
  const importIssue = (i: FieldIssue): ImportIssue => ({
    code: ({ target_empty: 'missing_target', source_empty: 'missing_source' } as Record<string, string>)[i.code] ?? i.code,
    message: i.message, ...(i.params ? { params: i.params } : {}),
  });

  interface Analysed { format: string | null; parsed: ReturnType<typeof parseImport> | null; rows: (ImportRow & { _draft: ParsedRow })[]; unknown: boolean }
  function analyseImport(body: Record<string, any>): Analysed {
    const content = String(body.content ?? '');
    const format = (body.format as string) || detectFormat(content, body.filename) || null;
    if (!content.trim() || !format || !FORMATS.some((f) => f.id === format)) return { format, parsed: null, rows: [], unknown: true };
    const o: ImportOptions = body.options ?? {};
    const parsed = parseImport(content, format, o);
    const existingByKey = new Map<string, string>();
    for (const r of state.rules) existingByKey.set(dupKey(r), r.id);
    const idx = getIndex();
    const inFile = new Set<string>();
    const fileSources = new Set(parsed.rows.map((p) => pageKey(p.input.source)));
    const rows = parsed.rows.map((p) => {
      const rule = makeRule({ ...p.input, origin: 'import', group: p.input.group ?? o.default_group ?? '' }, `tmp_${p.line}`, isoAtom(Date.now()));
      const issues = validateFields(rule);
      const warnings: ImportIssue[] = issues.filter((i) => i.severity !== 'error').map(importIssue);
      const errors: ImportIssue[] = [...p.issues, ...issues.filter((i) => i.severity === 'error').map(importIssue)];
      const tk = pageKey(rule.target.split('?')[0]!);
      if (rule.source && rule.match_type === 'exact' && rule.target && rule.target_type !== 'url') {
        if (tk === pageKey(rule.source)) errors.push({ code: 'loop', message: 'Quelle und Ziel sind identisch.' });
        else if (idx.bySource.has(tk) || fileSources.has(tk)) warnings.push({ code: 'chain', message: `Das Ziel ${rule.target} ist selbst Quelle einer Weiterleitung (Kette).`, params: { target: rule.target } });
      }
      if (rule.match_type === 'exact' && rule.source.length > 1 && rule.source.endsWith('/')) warnings.push({ code: 'trailing_slash', message: 'Die Quelle endet auf /, gilt aber auch ohne Slash.' });
      const key = dupKey(rule);
      const row: ImportRow & { _draft: ParsedRow } = { line: p.line, raw: p.raw, rule: errors.length && !rule.source ? null : rule, errors, warnings, duplicate_of: existingByKey.get(key) ?? null, duplicate_in_file: inFile.has(key), _draft: p };
      inFile.add(key);
      return row;
    });
    return { format, parsed, rows, unknown: false };
  }

  on('GET', '/redirects/import/formats', () => ({ data: FORMATS }));

  on('POST', '/redirects/import/preview', ({ body }) => {
    const a = analyseImport(body);
    const errors: ImportIssue[] = a.parsed ? [...a.parsed.errors] : [];
    const warnings: ImportIssue[] = [];
    if (a.unknown) errors.push({ code: 'unknown_format', message: !String(body.content ?? '').trim() ? 'Die Datei ist leer.' : 'Das Format wurde nicht erkannt. Bitte ein Format wählen.' });
    if (a.rows.length > 2000) warnings.push({ code: 'rows_truncated', message: `Nur die ersten 2000 von ${a.rows.length} Zeilen werden angezeigt.` });
    const preview: ImportPreview = {
      format: a.format,
      counts: {
        total: a.rows.length,
        valid: a.rows.filter((r) => !r.errors.length).length,
        errors: a.rows.filter((r) => r.errors.length).length,
        duplicates: a.rows.filter((r) => r.duplicate_of || r.duplicate_in_file).length,
        warnings: a.rows.filter((r) => r.warnings.length).length,
        skipped: a.parsed?.skipped ?? 0,
        not_found: a.parsed?.notFound.length ?? 0,
      },
      errors, warnings,
      rows: a.rows.slice(0, 2000).map(({ _draft, ...r }) => r),
      not_found_paths: a.parsed?.notFound.slice(0, 500) ?? [],
    };
    return { data: preview };
  });

  on('POST', '/redirects/import/commit', ({ body }) => {
    const a = analyseImport(body);
    if (a.unknown) throw new ApiError(422, 'Unprocessable Entity', 'Das Format wurde nicht erkannt.', [{ field: 'format', code: 'unknown_format', message: 'Das Format wurde nicht erkannt.', severity: 'error' }]);
    const skipDup = body.skip_duplicates !== false;
    const skipInvalid = body.skip_invalid !== false;
    const lines = Array.isArray(body.lines) ? new Set<number>(body.lines) : null;
    const created: Rule[] = [];
    let skipped = 0;
    for (const row of a.rows) {
      if (lines && !lines.has(row.line)) { skipped++; continue; }
      if (row.errors.length) {
        if (!skipInvalid) throw new ApiError(422, 'Unprocessable Entity', `Zeile ${row.line}: ${row.errors[0]!.message}`);
        skipped++; continue;
      }
      if ((row.duplicate_of || row.duplicate_in_file) && skipDup) { skipped++; continue; }
      const r = row.rule!;
      const rule = { ...r, id: newId() };
      state.rules.push(rule);
      state.rev.set(rule.id, 1);
      created.push(rule);
    }
    for (const path of a.parsed?.notFound ?? []) {
      if (state.notFound.some((n) => n.path === path)) continue;
      const cand = suggest(state.pages, path, 1)[0] ?? null;
      state.notFound.push({ path, daily: { [dayKey(Date.now())]: 1 }, referers: { '': 1 }, ua: { browser: 0, bot: 1, monitoring: 0, unknown: 0 }, languages: ['de'], hosts: ['example.test'], resolved: false, suggestion: cand });
      if (cand && !state.suggestions.some((s) => s.path === path)) state.suggestions.push({ id: 'sg' + newId().slice(-8), path, target: cand.target, score: cand.score, reason: cand.reason, page_title: cand.page_title, hits: 1, status: 'open', source: 'import', created_at: isoAtom(Date.now()) });
    }
    recompute();
    const result: ImportCommitResult = { created: created.length, skipped, rules: created };
    return { data: result };
  });

  on('GET', '/redirects/export', ({ query: q }) => {
    const format = q.get('format') ?? 'csv';
    if (!FORMATS.some((f) => f.id === format && f.export)) throw new ApiError(422, 'Unprocessable Entity', `Export im Format "${format}" wird nicht unterstützt.`, [{ field: 'format', code: 'invalid_format', message: 'Format nicht unterstützt.', severity: 'error' }]);
    const host = q.get('host');
    const st = num(q.get('status'), 0);
    const rules = getSorted().filter((r) => (!flag(q.get('only_enabled')) || r.enabled) && (!q.get('group') || r.group === q.get('group')) && (!st || r.status === st) && (!host || !r.conditions.hosts.length || r.conditions.hosts.includes(host)));
    return { data: exportRules(rules, format, host ?? 'example.test') };
  });

  on('GET', '/redirects/site-config', () => ({ data: state.siteConfig }));

  on('POST', '/redirects/site-config/import', () => {
    const have = new Set(state.rules.map((r) => dupKey(r)));
    const created: Rule[] = [];
    let skipped = 0;
    const add = (map: Record<string, string>, status: number) => {
      for (const [source, target] of Object.entries(map)) {
        const match_type = /^\^|\(/.test(source) ? 'regex' : 'exact';
        if (have.has(dupKey({ match_type, source }))) { skipped++; continue; }
        created.push(insert({ source, target, match_type, status: status as Rule['status'], group: 'Legacy', note: status === 200 ? 'Aus site.routes' : 'Aus site.redirects' }, 'import'));
      }
    };
    add(state.siteConfig.redirects, 301);
    add(state.siteConfig.routes, 200);
    recompute();
    return { data: { created: created.length, skipped, rules: created } };
  });

  /* ---- stats, checks, pages, pending ---- */
  on('GET', '/redirects/stats', () => {
    const today = dayKey(Date.now());
    const nf: Record<string, number> = {};
    const hb: Record<string, number> = {};
    for (let i = 29; i >= 0; i--) nf[dayKey(Date.now() - i * DAY)] = hb[dayKey(Date.now() - i * DAY)] = 0;
    for (const n of state.notFound) for (const [d, v] of Object.entries(n.daily)) if (d in nf) nf[d]! += v;
    for (const r of state.rules) for (const [d, v] of Object.entries(r.stats?.daily ?? {})) if (d in hb) hb[d]! += v;
    const last7 = (m: Record<string, number>) => Object.entries(m).filter(([d]) => d > dayKey(Date.now() - 7 * DAY)).reduce((s, [, v]) => s + v, 0);
    const stats: Stats = {
      not_found_today: nf[today] ?? 0, not_found_7d: last7(nf), not_found_by_day: nf, hits_today: hb[today] ?? 0, hits_7d: last7(hb), hits_by_day: hb,
      rules_total: state.rules.length, rules_active: state.rules.filter((r) => r.badges?.[0] === 'active').length,
      open_suggestions: state.suggestions.filter((s) => s.status === 'open').length,
      dead_targets: state.rules.filter((r) => r.badges?.includes('dead_target')).length, pending_deletes: state.pending.length,
    };
    return { data: stats };
  });

  on('GET', '/redirects/checks', () => ({ data: state.checks }));

  on('POST', '/redirects/checks/run', ({ body }) => {
    const wait = Math.ceil((state.lastCheckRun + 20_000 - Date.now()) / 1000);
    if (wait > 0) throw new ApiError(429, 'Too Many Requests', `Der Live-Check läuft nur alle 20 Sekunden. Bitte in ${wait} s erneut versuchen.`, undefined, { 'Retry-After': String(wait) });
    state.lastCheckRun = Date.now();
    const ids: string[] | null = Array.isArray(body.ids) && body.ids.length ? body.ids : null;
    const fresh: CheckResult[] = buildChecks(netRng, state.rules, state.dead, ids, Date.now());
    state.checks = { last_run: isoAtom(Date.now()), results: ids ? [...state.checks.results.filter((r) => !ids.includes(r.rule_id)), ...fresh] : fresh };
    return { data: state.checks };
  });

  on('GET', '/redirects/pages', ({ query: q }) => {
    const term = (q.get('q') ?? '').toLowerCase();
    const lang = q.get('language');
    const list = state.pages.filter((p) => (!lang || p.language === lang) && (!term || p.route.toLowerCase().includes(term) || p.title.toLowerCase().includes(term)));
    return { data: list.slice(0, Math.min(100, num(q.get('limit'), 20))) };
  });

  on('GET', '/redirects/pending', () => ({ data: state.pending }));

  on('POST', '/redirects/pending/:id/resolve', ({ params, body }) => {
    const p = state.pending.find((x) => x.id === params[0]);
    if (!p) throw new ApiError(404, 'Not Found', `Eintrag ${params[0]} existiert nicht.`);
    const action = String(body.action);
    let rule: Rule | null = null;
    if (action === 'gone') rule = insert({ source: p.route, target: '', status: 410, note: 'Seite gelöscht' });
    else if (action === 'parent') rule = insert({ source: p.route, target: p.route.replace(/\/[^/]*$/, '') || '/', status: 301, target_type: 'page', note: 'Seite gelöscht' });
    else if (action === 'redirect') {
      if (!body.target) throw new ApiError(422, 'Unprocessable Entity', 'target ist erforderlich.', [{ field: 'target', code: 'target_empty', message: 'target ist erforderlich.', severity: 'error' }]);
      rule = insert({ source: p.route, target: String(body.target), status: 301, note: 'Seite gelöscht' });
    } else if (action !== 'dismiss') throw new ApiError(422, 'Unprocessable Entity', 'Unbekannte Aktion.', [{ field: 'action', code: 'invalid_action', message: 'Unbekannte Aktion.', severity: 'error' }]);
    state.pending = state.pending.filter((x) => x !== p);
    if (rule) recompute();
    return { data: { id: p.id, action, rule } };
  });

  /* ---- sitemap (async: base64 + gzip) ---- */
  const asyncRoutes: [RegExp, (r: Req) => Promise<Res>][] = [
    [/^\/redirects\/import\/sitemap\/?$/, async ({ body }) => {
      let xml = String(body.content ?? '');
      if (body.encoding === 'base64') {
        try {
          const bin = Uint8Array.from(atob(xml.replace(/\s/g, '')), (c) => c.charCodeAt(0));
          xml = bin[0] === 0x1f && bin[1] === 0x8b ? await new Response(new Blob([bin]).stream().pipeThrough(new DecompressionStream('gzip'))).text() : new TextDecoder().decode(bin);
        } catch {
          throw new ApiError(422, 'Unprocessable Entity', 'Die Sitemap konnte nicht dekodiert werden.', [{ field: 'content', code: 'invalid_encoding', message: 'Ungültiges Base64/Gzip.', severity: 'error' }]);
        }
      }
      const locs = [...xml.matchAll(/<loc>\s*(?:<!\[CDATA\[)?\s*([^<\]\s]+)\s*(?:\]\]>)?\s*<\/loc>/gi)].map((m) => m[1]!.replace(/&amp;/g, '&'));
      if (!locs.length) throw new ApiError(422, 'Unprocessable Entity', 'Keine <loc>-Einträge gefunden.', [{ field: 'content', code: 'no_urls', message: 'Keine URLs in der Sitemap.', severity: 'error' }]);
      const sortedRules = getSorted();
      const diff: SitemapDiff = { total: locs.length, existing: 0, redirected: 0, missing: 0, missing_paths: [], suggestions_created: 0 };
      for (const loc of locs) {
        let u: URL;
        try { u = new URL(loc, 'https://example.test'); } catch { continue; }
        const { lang, path } = splitLanguage(u.pathname);
        if (getExisting().has(pageKey(u.pathname))) { diff.existing++; continue; }
        const ctx = { host: 'example.test', scheme: 'https', path: normalizePath(path), query: u.search.slice(1), language: lang ?? 'de', defaultLanguage: 'de', headers: {}, cookies: {}, now: Date.now(), phase: 'early' as const, pageExists: false };
        if (evaluate(sortedRules, ctx, 0).result) { diff.redirected++; continue; }
        diff.missing++;
        diff.missing_paths.push(u.pathname + u.search);
        const cand = suggest(state.pages, u.pathname, 1)[0];
        if (cand && !state.suggestions.some((s) => s.path === u.pathname)) {
          state.suggestions.push({ id: 'sg' + newId().slice(-8), path: u.pathname, target: cand.target, score: cand.score, reason: cand.reason, page_title: cand.page_title, hits: 0, status: 'open', source: 'sitemap', created_at: isoAtom(Date.now()) });
          diff.suggestions_created!++;
        }
      }
      diff.missing_paths = diff.missing_paths.slice(0, 500);
      return { data: diff };
    }],
  ];

  /* ---- fetch interceptor ---- */
  const sleep = (ms: number, signal?: AbortSignal | null) =>
    new Promise<void>((resolve, reject) => {
      const abort = () => reject(new DOMException('The operation was aborted.', 'AbortError'));
      if (signal?.aborted) return abort();
      const t = setTimeout(() => { signal?.removeEventListener('abort', onAbort); resolve(); }, ms);
      const onAbort = () => { clearTimeout(t); abort(); };
      signal?.addEventListener('abort', onAbort, { once: true });
    });

  const problem = (status: number, title: string, detail: string, errors?: FieldError[], extra: Record<string, string> = {}) =>
    new Response(JSON.stringify({ type: 'about:blank', status, title, detail, ...(errors ? { errors } : {}) }), {
      status, headers: { 'Content-Type': 'application/problem+json', ...extra },
    });

  const mockFetch = async (input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const req = typeof input === 'string' || input instanceof URL ? null : input;
    const href = typeof input === 'string' ? input : input instanceof URL ? input.href : input.url;
    const here = typeof location !== 'undefined' ? location.href : 'http://localhost/';
    const url = new URL(href, here);
    const base = new URL((g.__GRAV_API_SERVER_URL ?? '') + (g.__GRAV_API_PREFIX ?? '/api/v1'), here);
    const basePath = base.pathname.replace(/\/$/, '');
    const rest = url.pathname.slice(basePath.length);
    if (url.origin !== base.origin || !url.pathname.startsWith(basePath + '/') || !rest.startsWith('/redirects')) return realFetch(input, init);

    const signal = init?.signal ?? req?.signal;
    const [lo, hi] = opts.latency;
    await sleep(lo + netRng() * Math.max(0, hi - lo), signal);

    const headers = new Headers(req?.headers);
    new Headers(init?.headers).forEach((v, k) => headers.set(k, v));
    if (!headers.get('X-API-Token')) return problem(401, 'Unauthorized', 'Der Header X-API-Token fehlt.');
    const accept = headers.get('Accept');
    if (accept && !/json|\*\/\*/.test(accept)) return problem(406, 'Not Acceptable', 'Nur application/json wird angeboten.');
    if (opts.failRate > 0 && netRng() < opts.failRate) return problem(500, 'Internal Server Error', 'Simulierter Serverfehler (failRate).');

    const method = (init?.method ?? req?.method ?? 'GET').toUpperCase();
    let body: Record<string, any> = {};
    const raw = typeof init?.body === 'string' ? init.body : req && method !== 'GET' && method !== 'HEAD' ? await req.clone().text() : '';
    if (raw) {
      try {
        const parsed = JSON.parse(raw);
        body = parsed && typeof parsed === 'object' ? parsed : {};
      } catch {
        return problem(400, 'Bad Request', 'Der Body ist kein gültiges JSON.');
      }
    }
    const r: Req = { method, path: rest, params: [], query: url.searchParams, body, headers };
    try {
      let res: Res | null = null;
      for (const [re, h] of asyncRoutes) if (method === 'POST' && re.test(rest)) { res = await h(r); break; }
      if (!res) {
        let pathMatched = false;
        for (const [m, re, h] of routes) {
          const match = re.exec(rest);
          if (!match) continue;
          pathMatched = true;
          if (m !== method) continue;
          res = h({ ...r, params: match.slice(1).map(decodeURIComponent) });
          break;
        }
        if (!res) throw pathMatched ? new ApiError(405, 'Method Not Allowed', `${method} ist für ${rest} nicht erlaubt.`) : new ApiError(404, 'Not Found', `Unbekannte Route ${rest}.`);
      }
      return new Response(JSON.stringify({ data: res.data, ...(res.meta ? { meta: res.meta } : {}) }), {
        status: res.status ?? 200, headers: { 'Content-Type': 'application/json', ...res.headers },
      });
    } catch (e) {
      if (e instanceof ApiError) return problem(e.status, e.title, e.message, e.errors, e.headers);
      console.error('[mock-api]', e);
      return problem(500, 'Internal Server Error', (e as Error).message);
    }
  };
  g.fetch = mockFetch as typeof fetch;

  return {
    state,
    reset() {
      Object.assign(state, buildState(opts));
      sorted = index = existing = ruleKeys = deadTargets = null;
      netRng = mulberry32(opts.seed ^ 0x9e3779b9);
    },
    uninstall() {
      if (g.fetch === (mockFetch as typeof fetch)) g.fetch = realFetch as typeof fetch;
    },
  };
}
