/**
 * Types for the REST contract in docs/API.md.
 *
 * Where API.md leaves a response shape open, the shape used by the UI is marked
 * `ASSUMPTION` and collected in the final report so the backend can follow it.
 */

export type MatchType = 'exact' | 'wildcard' | 'regex';
export type TargetType = 'route' | 'url' | 'page';
export type QueryMode = 'ignore' | 'pass' | 'exact' | 'params';
export type StatusCode = 200 | 301 | 302 | 307 | 308 | 410 | 451;
export type RuleState = 'active' | 'disabled' | 'expired' | 'scheduled';
export type RuleBadge =
  | 'active'
  | 'disabled'
  | 'expired'
  | 'scheduled'
  | 'chain'
  | 'loop'
  | 'conflict'
  | 'dead_target'
  | 'unused';
export type RuleOrigin = 'manual' | 'import' | 'auto' | 'suggestion';
export type ConditionKind = 'header' | 'cookie';
export type ConditionOperator = 'exists' | 'equals' | 'contains' | 'starts_with' | 'regex';
export type Severity = 'error' | 'warning' | 'info';

export interface Condition {
  kind: ConditionKind;
  name: string;
  operator: ConditionOperator;
  value: string;
  negate: boolean;
}

export interface Conditions {
  hosts: string[];
  languages: string[];
  schemes: string[];
  rules: Condition[];
}

export interface RuleStats {
  total: number;
  last_hit: string | null;
  /** YYYY-MM-DD -> hits (up to 90 days, sparse) */
  daily: Record<string, number>;
}

export interface Issue {
  code: string;
  /** form field the finding belongs to (source, target, ...) */
  field?: string;
  severity: Severity;
  message: string;
  params?: Record<string, unknown>;
}

export interface Rule {
  id: string;
  source: string;
  target: string;
  match_type: MatchType;
  status: StatusCode;
  enabled: boolean;
  priority: number;
  target_type: TargetType;
  case_sensitive: boolean;
  ignore_trailing_slash: boolean;
  query_mode: QueryMode;
  /** name -> required value, null = any value */
  query_params: Record<string, string | null>;
  query_ignore: string[];
  continue: boolean;
  only_if_not_found: boolean;
  active_from: string | null;
  expires_at: string | null;
  note: string;
  group: string;
  tags: string[];
  origin: RuleOrigin;
  conditions: Conditions;
  created_at: string | null;
  updated_at: string | null;
  // read-only
  stats?: RuleStats;
  badges?: RuleBadge[];
  issues?: Issue[];
}

/** Fields the editor may send. */
export type RuleInput = Partial<Omit<Rule, 'id' | 'stats' | 'badges' | 'issues' | 'created_at' | 'updated_at'>>;

export interface ApiMeta {
  total?: number;
  page?: number;
  per_page?: number;
  [key: string]: unknown;
}

export interface RulesMeta extends ApiMeta {
  total: number;
  page: number;
  per_page: number;
  groups?: string[];
  /** count per badge over the whole (filtered) set */
  counts?: Partial<Record<RuleBadge, number>>;
}

export type RuleSort =
  | 'priority'
  | 'source'
  | 'target'
  | 'status'
  | 'hits'
  | 'last_hit'
  | 'created_at'
  | 'updated_at';

export interface RuleQuery {
  q?: string;
  match_type?: MatchType | '';
  status?: number | '';
  state?: RuleState | '';
  badge?: RuleBadge | '';
  group?: string;
  origin?: string;
  unused_days?: number | '';
  sort?: RuleSort;
  dir?: 'asc' | 'desc';
  page?: number;
  per_page?: number;
}

export interface PreviewResult {
  /** what the rule does with the sample URL; null when it does not match */
  sample: string;
  result: null | {
    matched: boolean;
    status?: number;
    location?: string;
    captures?: Record<string, string>;
    rule_id?: string;
    reason?: string;
  };
}

/** POST /redirects/rules/validate and POST /redirects/rules?dry_run=1 */
export interface ValidateResult {
  issues: Issue[];
  preview?: PreviewResult | null;
  rule?: Rule;
}

export interface BulkResult {
  affected: number;
  /** the deleted rules (action delete), so the UI can offer undo */
  rules?: Rule[];
}

export interface GroupsResult {
  groups: { name: string; count: number }[];
  tags: { name: string; count: number }[];
}

export interface PageHit {
  route: string;
  title: string;
  language: string;
  /** ASSUMPTION: list of language codes of existing translations */
  translations: string[];
}

/* Tester */

export interface TraceStep {
  rule_id: string;
  source: string;
  match_type: MatchType;
  priority: number;
  matched: boolean;
  reason: string;
  path: string;
}

export interface MatchResult {
  rule_id: string;
  status: number;
  location: string;
  rules: string[];
  captures: Record<string, string>;
  trace: TraceStep[];
}

/** Where the tester's walk ended. Flags are present only when they apply. */
export interface FinalInfo {
  url: string;
  status: number;
  /** the target is on another site and was not requested (status is reported as 200) */
  external?: boolean;
  loop?: boolean;
  /** the walk stopped at the depth limit */
  truncated?: boolean;
  /** the path is excluded from redirects (excluded_paths, API and admin routes) */
  excluded?: boolean;
}

export interface TestResponse {
  input: { url: string; [k: string]: unknown };
  context: Record<string, unknown>;
  result: MatchResult | null;
  /** one entry per rule hop; a last entry with rule_id null is the page the visitor ends up on (200) or a 404 */
  chain: { url: string; status: number; rule_id: string | null; location?: string | null }[];
  final: FinalInfo;
  trace: TraceStep[];
  page_exists: boolean;
}

/* 404 monitor */

export type UaClass = 'browser' | 'bot' | 'monitoring' | 'unknown';

export interface SuggestionCandidate {
  target: string;
  score: number;
  reason: SuggestionReason;
  page_title: string;
  details?: Record<string, unknown>;
}

export type SuggestionReason =
  | 'same_slug'
  | 'other_language'
  | 'similar_route'
  | 'title_match'
  | 'taxonomy_match'
  | 'parent_fallback'
  | 'home_fallback';

export interface NotFoundRow {
  path: string;
  hits: number;
  first_seen: string;
  last_seen: string;
  top_referers: { referer: string; hits: number }[];
  daily: Record<string, number>;
  ua: Partial<Record<UaClass, number>>;
  languages: string[];
  hosts: string[];
  has_rule: boolean;
  /** ASSUMPTION: best_suggestion is the top SuggestionCandidate or null */
  best_suggestion: SuggestionCandidate | null;
  /** ASSUMPTION: true when marked done via /redirects/404/resolve (only with include_resolved=1) */
  resolved?: boolean;
}

export interface NotFoundMeta extends ApiMeta {
  totals?: { hits: number; paths: number; by_day: Record<string, number> };
}

export interface NotFoundQuery {
  days?: 7 | 30 | 90;
  bots?: 0 | 1;
  class?: UaClass | '';
  q?: string;
  sort?: 'hits' | 'last' | 'first' | 'path';
  dir?: 'asc' | 'desc';
  page?: number;
  per_page?: number;
  include_resolved?: 0 | 1;
}

export interface NotFoundEntry {
  time: string;
  path: string;
  query?: string;
  referer?: string;
  ua?: string;
  ua_class?: UaClass;
  language?: string;
  host?: string;
  ip?: string;
}

/* Suggestions */

export interface StoredSuggestion {
  id: string;
  path: string;
  target: string;
  score: number;
  reason: SuggestionReason;
  page_title: string;
  hits: number;
  status: 'open' | 'accepted' | 'rejected';
  source?: string;
  created_at?: string;
}

export interface BulkAcceptPreview {
  min_score: number;
  count: number;
  rows: StoredSuggestion[];
}

/* Import / export */

export interface ImportFormat {
  id: string;
  label: string;
  import: boolean;
  export: boolean;
  extension?: string;
}

export interface ImportIssue {
  code: string;
  message: string;
  params?: Record<string, unknown>;
}

export interface ImportRow {
  line: number;
  raw: string;
  rule: Rule | null;
  errors: ImportIssue[];
  warnings: ImportIssue[];
  duplicate_of: string | null;
  duplicate_in_file: boolean;
}

export interface ImportPreview {
  format: string | null;
  counts: {
    total: number;
    valid: number;
    errors: number;
    duplicates: number;
    warnings: number;
    skipped: number;
    not_found: number;
  };
  errors: ImportIssue[];
  warnings: ImportIssue[];
  rows: ImportRow[];
  not_found_paths: string[];
}

export interface ImportOptions {
  /** CSV: which column holds which field. ASSUMPTION: passed as options.columns */
  columns?: Record<string, number | null>;
  delimiter?: string;
  has_header?: boolean;
  default_group?: string;
  default_status?: number;
}

export interface ImportCommitResult {
  created: number;
  skipped: number;
  rules: Rule[];
}

export interface SitemapDiff {
  total: number;
  existing: number;
  redirected: number;
  missing: number;
  missing_paths: string[];
  /** ASSUMPTION: number of suggestions created for the missing paths */
  suggestions_created?: number;
}

export interface ExportResult {
  filename: string;
  mime: string;
  content: string;
  /** rules the format cannot express at all: {rule_id, code, reason} */
  skipped: unknown[];
  /** rules exported with a loss of detail */
  lossy?: unknown[];
  exported?: number;
}

export interface SiteConfig {
  redirects: Record<string, string>;
  routes: Record<string, string>;
  /** system.pages.redirect_default_route etc. */
  settings?: Record<string, unknown>;
}

/* Live check of the redirect targets */

export interface CheckResult {
  rule_id: string;
  source?: string;
  target?: string;
  url: string;
  status: number;
  ok: boolean;
  error: string | null;
  final_url: string | null;
  redirects: number;
  duration_ms: number;
  checked_at: string;
}

/** GET /redirects/checks; POST /redirects/checks/run adds `checked` and `dead` */
export interface CheckRun {
  last_run: string | null;
  results: CheckResult[];
}

/* Stats */

export interface Stats {
  not_found_today: number;
  not_found_7d: number;
  /** YYYY-MM-DD -> count, 30 days */
  not_found_by_day: Record<string, number>;
  hits_today: number;
  hits_7d: number;
  hits_by_day: Record<string, number>;
  rules_total: number;
  rules_active: number;
  open_suggestions: number;
  dead_targets: number;
  pending_deletes: number;
}

/** GET /redirects/pending: a deleted page waiting for a decision (delete policy "ask"). */
export interface PendingDelete {
  id: string;
  title: string;
  route: string;
  /** route per language; "*" is the route of a page without language variants */
  routes: Record<string, string>;
  languages: string[];
  /** routes of the child pages, at most 50 */
  children: string[];
  children_count: number;
  deleted_at: string;
  /** nearest ancestor route that still exists, null when there is none */
  suggested_parent: string | null;
}

export type PendingAction = 'gone' | 'parent' | 'redirect' | 'dismiss';

export interface PendingResolveResult {
  id: string;
  action: PendingAction;
  created: Rule[];
  updated: Rule[];
  deleted: string[];
  notes: { kind: string; source: string; message: string }[];
}

/** GET /redirects/badge. `count` is null when there is nothing to show. */
export interface BadgeInfo {
  count: number | null;
  unseen: number;
  pending: number;
}

export interface Permissions {
  read: boolean;
  manage: boolean;
}

/** meta of GET /redirects/stats */
export interface StatsMeta extends ApiMeta {
  permissions?: Permissions;
  /** status code a new rule gets when none is sent */
  default_status?: number;
}

export interface SuggestionsMeta extends ApiMeta {
  counts?: { open: number; accepted: number; rejected: number };
  /** score the bulk accept slider starts at (suggestions.bulk_accept_score) */
  bulk_accept_score?: number;
}
