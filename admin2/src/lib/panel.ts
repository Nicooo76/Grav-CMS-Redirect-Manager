/**
 * Pure logic of the page-editor panel: which page is open, what the rules of that page look like, the old-URL form.
 * Kept out of the .svelte file so it can be unit-tested.
 */
import type { Issue, MatchType, RuleOrigin, RuleState, StatusCode, TargetType } from './types';

export const PANEL_ENDPOINT = '/redirects/page-context';
export const SIDEBAR_ID = 'redirect-manager';
/** How many rows each list shows before it points to the Rules tab. */
export const LIST_MAX = 8;

export interface PageRule {
  id: string;
  source: string;
  target: string;
  match_type: MatchType;
  status: StatusCode;
  target_type: TargetType;
  origin: RuleOrigin;
  state: RuleState;
  languages: string[];
  note: string;
  created_at: string | null;
  unseen: boolean;
  recent: boolean;
  hits: number;
  last_hit: string | null;
}

export interface PageOutgoing extends PageRule {
  location: string;
}

export interface PagePending {
  id: string;
  title: string;
  route: string;
  children_count: number;
  deleted_at: string;
}

export interface PageNotFound {
  path: string;
  hits: number;
  last_seen: string;
}

export interface PageContext {
  route: string;
  language: string | null;
  incoming: PageRule[];
  incoming_total: number;
  created: PageRule[];
  created_total: number;
  unseen: number;
  outgoing: PageOutgoing | null;
  pending: PagePending[];
  not_found: PageNotFound[];
  generated_at: string;
}

/** Which page the host has open: the attributes Admin 2 sets on the panel element, else the globals of the editor. */
export function contextOf(host: Pick<HTMLElement, 'getAttribute'>, win: Pick<Window, '__GRAV_PAGE_ROUTE' | '__GRAV_CONTENT_LANG'> = window): { route: string; lang: string } {
  const route = (host.getAttribute('route') || win.__GRAV_PAGE_ROUTE || '').trim();
  const lang = (host.getAttribute('lang') || win.__GRAV_CONTENT_LANG || '').trim();
  return { route: route && !route.startsWith('/') ? `/${route}` : route, lang };
}

const arr = <T>(v: unknown): T[] => (Array.isArray(v) ? (v as T[]) : []);
const num = (v: unknown): number => (typeof v === 'number' && Number.isFinite(v) && v > 0 ? v : 0);

function rule(raw: unknown): PageRule | null {
  if (!raw || typeof raw !== 'object') return null;
  const r = raw as Partial<PageRule>;
  if (typeof r.id !== 'string' || typeof r.source !== 'string') return null;
  return {
    id: r.id,
    source: r.source,
    target: typeof r.target === 'string' ? r.target : '',
    match_type: r.match_type ?? 'exact',
    status: r.status ?? 301,
    target_type: r.target_type ?? 'route',
    origin: r.origin ?? 'manual',
    state: r.state ?? 'active',
    languages: arr<string>(r.languages),
    note: typeof r.note === 'string' ? r.note : '',
    created_at: r.created_at ?? null,
    unseen: r.unseen === true,
    recent: r.recent === true,
    hits: num(r.hits),
    last_hit: r.last_hit ?? null,
  };
}

/** Fills missing or malformed fields so a partial answer (an older or newer backend) cannot break the render. */
export function normalizeContext(raw: unknown): PageContext {
  const o = (raw && typeof raw === 'object' ? raw : {}) as Record<string, unknown>;
  const rules = (v: unknown) => arr<unknown>(v).map(rule).filter((r): r is PageRule => r !== null);
  const outgoing = rule(o.outgoing);
  const created = rules(o.created);
  const incoming = rules(o.incoming);
  return {
    route: typeof o.route === 'string' ? o.route : '',
    language: typeof o.language === 'string' && o.language ? o.language : null,
    incoming,
    incoming_total: Math.max(num(o.incoming_total), incoming.length),
    created,
    created_total: Math.max(num(o.created_total), created.length),
    unseen: num(o.unseen) || created.filter((r) => r.unseen).length,
    outgoing: outgoing ? { ...outgoing, location: typeof (o.outgoing as { location?: unknown }).location === 'string' ? (o.outgoing as { location: string }).location : '' } : null,
    pending: arr<PagePending>(o.pending).filter((p) => p && typeof p.id === 'string'),
    not_found: arr<PageNotFound>(o.not_found).filter((p) => p && typeof p.path === 'string'),
    generated_at: typeof o.generated_at === 'string' ? o.generated_at : '',
  };
}

/** The unseen automatic rules, newest first as the server sent them. */
export function unseenRules(ctx: PageContext): PageRule[] {
  return ctx.created.filter((r) => r.unseen);
}

/**
 * What a person typed into "old URL" as a rule source: a path with a leading slash. A pasted address loses its
 * scheme, host and fragment; a query string stays (the rule can match it). Empty when nothing usable is left.
 */
export function sourceFromInput(input: string): string {
  let s = input.trim();
  if (!s) return '';
  s = s.replace(/^[a-z][a-z0-9+.-]*:\/\/[^/?#]*/i, '').replace(/^\/\/[^/?#]+/, '');
  s = s.split('#')[0]!;
  if (!s.startsWith('/')) s = `/${s}`;
  return s.replace(/\/{2,}/g, '/');
}

/** Body for POST /redirects/rules and /rules/validate: a 301 exact rule from the old URL to this page. */
export function oldUrlBody(source: string, route: string, note: string): Record<string, unknown> {
  return { source, target: route, target_type: 'page', match_type: 'exact', status: 301, enabled: true, note };
}

export function hasError(issues: Issue[]): boolean {
  return issues.some((i) => i.severity === 'error');
}

/** Tells Admin 2 to redraw the toolbar badge of the panel without another request (the host listens for `badge` on the element). */
export function announceBadge(host: EventTarget, count: number): void {
  host.dispatchEvent(new CustomEvent('badge', { detail: { count } }));
}

/** Tells Admin 2 to redraw the sidebar badge. Admin 2 hides the pill only for a missing value, so 0 shows "0". */
export function pushSidebarBadge(count: number | null): void {
  if (typeof window === 'undefined') return;
  window.dispatchEvent(new CustomEvent('grav:sidebar:badge', { detail: { id: SIDEBAR_ID, count: count ?? 0 } }));
}

/** Hash of the Rules tab filtered to this page. */
export function rulesHash(route: string): string {
  return `/rules?q=${encodeURIComponent(route)}`;
}
