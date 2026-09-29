import type { Rule, RuleInput, Conditions } from './types';

/** Deep copy that also works on Svelte state proxies (structuredClone does not). */
export function clone<T>(v: T): T {
  return v === undefined ? v : (JSON.parse(JSON.stringify(v)) as T);
}

export const EMPTY_CONDITIONS: Conditions = { hosts: [], languages: [], schemes: [], rules: [] };

/** New rule defaults, shared by the editor and the duplicate action. */
export function blankRule(defaults: Partial<RuleInput> = {}): Required<Omit<RuleInput, never>> {
  return {
    source: '',
    target: '',
    match_type: 'exact',
    status: 301,
    enabled: true,
    priority: 0,
    target_type: 'route',
    case_sensitive: false,
    ignore_trailing_slash: true,
    query_mode: 'ignore',
    query_params: {},
    query_ignore: [],
    continue: false,
    only_if_not_found: false,
    active_from: null,
    expires_at: null,
    note: '',
    group: '',
    tags: [],
    origin: 'manual',
    conditions: clone(EMPTY_CONDITIONS),
    ...defaults,
  } as Required<RuleInput>;
}

/** Editable fields of a stored rule (drops id, stats, badges, issues, timestamps). */
export function toInput(rule: Rule): RuleInput {
  const { id: _id, stats: _s, badges: _b, issues: _i, created_at: _c, updated_at: _u, ...rest } = rule;
  return clone(rest) as RuleInput;
}

/**
 * True when two paths are not identical but equal ignoring case (/shop/Zelte and /shop/zelte). A case-insensitive
 * rule with such a target matches its own target (a loop the API refuses with self_redirect), so a rule built from
 * a suggestion has to be case-sensitive. Same test as PathNormalizer::differsOnlyInCase() in the plugin.
 */
export function differsOnlyInCase(a: string, b: string): boolean {
  return a !== b && a.toLowerCase() === b.toLowerCase();
}

/** Target that is a path of this site (not a full URL): the only kind that can point back at the source. */
export const isSitePath = (target: string): boolean => !/^[a-z][a-z0-9+.-]*:\/\//i.test(target);
