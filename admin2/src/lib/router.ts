/**
 * Hash routes. Admin 2 gives a plugin exactly one page route
 * (`/plugin/redirect-manager`), so sub-views live in the hash (DECISIONS D-011):
 *
 *   #/rules  #/rules/new  #/rules/<id>  #/404  #/suggestions
 *   #/tester?url=  #/import  #/export  #/settings
 */

export type RouteName =
  | 'rules'
  | 'rule-new'
  | 'rule-edit'
  | 'notfound'
  | 'suggestions'
  | 'tester'
  | 'import'
  | 'export'
  | 'settings';

export type TabId = 'rules' | 'notfound' | 'suggestions' | 'tester' | 'importexport' | 'settings';

export interface Route {
  name: RouteName;
  id?: string;
  query: Record<string, string>;
}

export const DEFAULT_ROUTE: Route = { name: 'rules', query: {} };

export function parseHash(hash: string): Route {
  let h = hash.startsWith('#') ? hash.slice(1) : hash;
  if (!h.startsWith('/')) return { ...DEFAULT_ROUTE, query: {} };
  const qi = h.indexOf('?');
  const query: Record<string, string> = {};
  if (qi !== -1) {
    new URLSearchParams(h.slice(qi + 1)).forEach((v, k) => {
      query[k] = v;
    });
    h = h.slice(0, qi);
  }
  const parts = h.split('/').filter(Boolean).map(safeDecode);
  const [a, b] = parts;
  switch (a) {
    case undefined:
    case 'rules':
      if (b === 'new') return { name: 'rule-new', query };
      if (b) return { name: 'rule-edit', id: b, query };
      return { name: 'rules', query };
    case '404':
      return { name: 'notfound', query };
    case 'suggestions':
      return { name: 'suggestions', query };
    case 'tester':
      return { name: 'tester', query };
    case 'import':
      return { name: 'import', query };
    case 'export':
      return { name: 'export', query };
    case 'settings':
      return { name: 'settings', query };
    default:
      return { name: 'rules', query: {} };
  }
}

function safeDecode(s: string): string {
  try {
    return decodeURIComponent(s);
  } catch {
    return s;
  }
}

export interface RouteTarget {
  name: RouteName;
  id?: string;
  query?: Record<string, string | number | boolean | null | undefined>;
}

export function buildHash(t: RouteTarget): string {
  let path: string;
  switch (t.name) {
    case 'rules':
      path = '/rules';
      break;
    case 'rule-new':
      path = '/rules/new';
      break;
    case 'rule-edit':
      path = `/rules/${encodeURIComponent(t.id ?? '')}`;
      break;
    case 'notfound':
      path = '/404';
      break;
    default:
      path = `/${t.name}`;
  }
  const p = new URLSearchParams();
  for (const [k, v] of Object.entries(t.query ?? {})) {
    if (v === undefined || v === null || v === '' || v === false) continue;
    p.set(k, v === true ? '1' : String(v));
  }
  const q = p.toString();
  return `#${path}${q ? `?${q}` : ''}`;
}

export function tabOf(route: Route): TabId {
  switch (route.name) {
    case 'rules':
    case 'rule-new':
    case 'rule-edit':
      return 'rules';
    case 'notfound':
      return 'notfound';
    case 'import':
    case 'export':
      return 'importexport';
    default:
      return route.name;
  }
}

export function sameRoute(a: Route, b: Route): boolean {
  return buildHash(a) === buildHash(b);
}
