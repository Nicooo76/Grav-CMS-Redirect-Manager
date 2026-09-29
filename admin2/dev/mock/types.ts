import type {
  PageHit,
  PendingDelete,
  Rule,
  SiteConfig,
  StoredSuggestion,
  SuggestionCandidate,
  UaClass,
} from '../../src/lib/types';

export interface MockOptions {
  /** number of generated rules (default 300, up to 10000) */
  rules?: number;
  /** simulated latency range in ms (default [60, 220]) */
  latency?: [number, number];
  seed?: number;
  /** 0..1 probability of a random HTTP 500 (default 0) */
  failRate?: number;
}
export type ResolvedOptions = Required<MockOptions>;

/** One grouped 404 path; `daily` covers the last 90 days, the rest is scaled to the requested window. */
export interface NotFoundGroup {
  path: string;
  daily: Record<string, number>;
  referers: Record<string, number>;
  ua: Record<UaClass, number>;
  languages: string[];
  hosts: string[];
  resolved: boolean;
  suggestion: SuggestionCandidate | null;
}

export interface CheckResult {
  rule_id: string;
  url: string;
  status: number;
  ok: boolean;
  error: string | null;
  final_url: string | null;
  redirects: number;
  duration_ms: number;
  checked_at: string;
}

export interface MockState {
  opts: ResolvedOptions;
  rules: Rule[];
  notFound: NotFoundGroup[];
  suggestions: StoredSuggestion[];
  pages: PageHit[];
  pending: PendingDelete[];
  checks: { last_run: string | null; results: CheckResult[] };
  /** rule id -> status of the last live check (0 = timeout); drives the `dead_target` badge */
  dead: Map<string, number>;
  ignorePatterns: string[];
  siteConfig: SiteConfig;
  /** distinct non-empty group names, refreshed after writes */
  groups: string[];
  /** per-rule revision counter, source of the ETag */
  rev: Map<string, number>;
  seq: number;
  lastCheckRun: number;
}
