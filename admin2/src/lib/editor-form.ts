/** Pure helpers for the rule editor form (payloads, dirty check, quick client checks). */
import { blankRule, clone } from './rule-utils';
import type { Condition, Issue, Rule, RuleInput, StatusCode } from './types';

export type RuleForm = ReturnType<typeof blankRule>;

export const STATUS_CODES: StatusCode[] = [301, 302, 307, 308, 410, 451, 200];

export function needsTarget(status: number): boolean {
  return status !== 410 && status !== 451;
}

/** Builds the initial form: stored rule, or defaults plus prefill for a new one. */
export function initialForm(rule: Rule | null, prefill: RuleInput = {}, defaultStatus = 301): RuleForm {
  if (rule) {
    const { id: _id, stats: _s, badges: _b, issues: _i, created_at: _c, updated_at: _u, ...rest } = rule;
    return { ...blankRule(), ...clone(rest) } as RuleForm;
  }
  return blankRule({ status: defaultStatus as StatusCode, ...clone(prefill) });
}

/** Trims text fields and drops empty rows so the payload is what the API should store. */
export function formToPayload(f: RuleForm): RuleInput {
  const list = (a: string[]) => [...new Set(a.map((s) => s.trim()).filter(Boolean))];
  const conds: Condition[] = f.conditions.rules
    .map((c) => ({ ...c, name: c.name.trim(), value: c.operator === 'exists' ? '' : c.value }))
    .filter((c) => c.name !== '');
  const params: Record<string, string | null> = {};
  for (const [k, v] of Object.entries(f.query_params)) {
    const name = k.trim();
    if (name) params[name] = v === null || v === '' ? null : v;
  }
  return {
    source: f.source.trim(),
    target: needsTarget(f.status) ? f.target.trim() : '',
    match_type: f.match_type,
    status: f.status,
    enabled: f.enabled,
    priority: Number.isFinite(Number(f.priority)) ? Math.trunc(Number(f.priority)) : 0,
    target_type: f.target_type,
    case_sensitive: f.case_sensitive,
    ignore_trailing_slash: f.ignore_trailing_slash,
    query_mode: f.query_mode,
    query_params: f.query_mode === 'params' ? params : {},
    query_ignore: list(f.query_ignore),
    continue: f.continue,
    only_if_not_found: f.only_if_not_found,
    active_from: f.active_from || null,
    expires_at: f.expires_at || null,
    note: f.note.trim(),
    group: f.group.trim(),
    tags: list(f.tags),
    origin: f.origin,
    conditions: {
      hosts: list(f.conditions.hosts).map((h) => h.toLowerCase()),
      languages: list(f.conditions.languages).map((l) => l.toLowerCase()),
      schemes: list(f.conditions.schemes).map((s) => s.toLowerCase()),
      rules: conds,
    },
  };
}

/** Top-level fields whose value differs from the initial payload (for PATCH). */
export function diffPayload(initial: RuleInput, current: RuleInput): RuleInput {
  const out: Record<string, unknown> = {};
  for (const key of Object.keys(current) as (keyof RuleInput)[]) {
    if (JSON.stringify(initial[key]) !== JSON.stringify(current[key])) out[key] = current[key];
  }
  return out as RuleInput;
}

export function isDirty(initial: RuleForm, current: RuleForm): boolean {
  return JSON.stringify(formToPayload(initial)) !== JSON.stringify(formToPayload(current));
}

export type FieldName = 'source' | 'target' | 'status' | 'priority' | 'active_from' | 'expires_at' | 'group' | 'conditions' | 'query_params' | 'general';

export interface FieldProblem {
  field: FieldName;
  message: string;
  severity: 'error' | 'warning';
  /** translation key when the message comes from the client */
  code?: string;
}

/** Cheap checks before hitting the API. Messages are translation keys resolved by the caller. */
export function clientChecks(f: RuleForm): { field: FieldName; code: string }[] {
  const problems: { field: FieldName; code: string }[] = [];
  const src = f.source.trim();
  if (!src) problems.push({ field: 'source', code: 'EDITOR.ERR_SOURCE_REQUIRED' });
  else if (f.match_type !== 'regex' && !src.startsWith('/') && !/^https?:\/\//i.test(src)) problems.push({ field: 'source', code: 'EDITOR.ERR_SOURCE_SLASH' });
  else if (f.match_type === 'regex') {
    try {
      new RegExp(src);
    } catch {
      problems.push({ field: 'source', code: 'EDITOR.ERR_REGEX_INVALID' });
    }
  }
  if (needsTarget(f.status) && !f.target.trim()) problems.push({ field: 'target', code: 'EDITOR.ERR_TARGET_REQUIRED' });
  if (f.active_from && f.expires_at && new Date(f.expires_at) <= new Date(f.active_from)) problems.push({ field: 'expires_at', code: 'EDITOR.ERR_PERIOD' });
  return problems;
}

/** Maps a server issue to the form field it belongs to. */
export function issueField(issue: Issue & { field?: string }): FieldName {
  if (issue.field && ['source', 'target', 'status', 'priority', 'active_from', 'expires_at', 'group', 'conditions', 'query_params'].includes(issue.field)) return issue.field as FieldName;
  const c = issue.code;
  if (/regex|source/.test(c)) return 'source';
  if (/target|unsafe|passthrough/.test(c)) return 'target';
  if (/status/.test(c)) return 'status';
  if (/period|expires|active_from/.test(c)) return 'expires_at';
  return 'general';
}

export function hasBlockingIssue(issues: Issue[]): boolean {
  return issues.some((i) => i.severity === 'error');
}

/** Has any advanced field a non-default value? Used to open the disclosure sections. */
export function advancedState(f: RuleForm) {
  return {
    query: f.query_mode !== 'ignore' || f.query_ignore.length > 0,
    conditions: f.conditions.hosts.length + f.conditions.languages.length + f.conditions.schemes.length + f.conditions.rules.length > 0,
    behaviour: f.only_if_not_found || f.continue || f.case_sensitive || !f.ignore_trailing_slash,
    schedule: !!f.active_from || !!f.expires_at,
    organise: !!f.group || f.tags.length > 0 || !!f.note,
  };
}
