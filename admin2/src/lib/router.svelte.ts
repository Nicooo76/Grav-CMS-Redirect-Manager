import { buildHash, parseHash, type Route, type RouteTarget } from './router';

let current = $state<Route>(parseHash(typeof location === 'undefined' ? '' : location.hash));
let users = 0;

function sync() {
  current = parseHash(location.hash);
}

/** Reactive current route. */
export const router = {
  get route(): Route {
    return current;
  },
};

/** Called by every mounted app; one hashchange listener for all. */
export function startRouter(): () => void {
  if (users++ === 0) window.addEventListener('hashchange', sync);
  sync();
  return () => {
    if (--users === 0) window.removeEventListener('hashchange', sync);
  };
}

export function hrefFor(target: RouteTarget): string {
  return buildHash(target);
}

/** Navigate to a route. `replace` avoids a history entry (filters, pagination). */
export function navigate(target: RouteTarget, opts: { replace?: boolean } = {}): void {
  const hash = buildHash(target);
  if (hash === location.hash) return;
  if (opts.replace) {
    // replaceState does not fire hashchange, so sync by hand.
    const url = location.pathname + location.search + hash;
    history.replaceState(history.state, '', url);
    sync();
  } else {
    location.hash = hash;
  }
}

/** Click handler for `<a href="#/...">`: keeps modifier-clicks working. */
export function linkClick(e: MouseEvent): void {
  if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
  const a = (e.currentTarget as HTMLAnchorElement | null) ?? null;
  const href = a?.getAttribute('href');
  if (!href || !href.startsWith('#/')) return;
  // Stop the host's link interception: a hash-only change needs no SPA navigation.
  e.preventDefault();
  e.stopPropagation();
  location.hash = href;
}
