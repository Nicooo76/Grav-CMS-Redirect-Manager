import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushSync, mount, unmount } from 'svelte';
import TesterScreen from './TesterScreen.svelte';
import { registerFallback, watchLocale } from '../lib/i18n.svelte';
import { startRouter } from '../lib/router.svelte';
import { allEn } from '../lib/i18n-all';

registerFallback(allEn);

const P = 'PLUGIN_REDIRECT_MANAGER.UI.';
const GERMAN: Record<string, string> = {
  [P + 'TESTER.HEAD_REDIRECT']: 'Weiterleitung, {status}',
  [P + 'TESTER.RESULT']: 'Ergebnis',
  [P + 'TAB.RULES']: 'Regeln',
};

const response = {
  input: { url: '/old', method: 'GET', phase: 'any' },
  context: { path: '/old' },
  result: { rule_id: 'r1', status: 301, location: '/faq', rules: ['r1'], captures: {}, trace: [] },
  chain: [
    { url: '/old', status: 301, rule_id: 'r1', location: '/faq' },
    { url: '/faq', status: 200, rule_id: null, location: null },
  ],
  final: { url: '/faq', status: 200 },
  trace: [],
  page_exists: false,
};

function installHost() {
  const subs = new Set<(l: string) => void>();
  const h = {
    dict: {} as Record<string, string>,
    locale: 'en',
    dir: 'ltr' as const,
    t: (k: string) => h.dict[k] ?? k,
    has: (k: string) => k in h.dict,
    subscribe(fn: (l: string) => void) {
      subs.add(fn);
      return () => subs.delete(fn);
    },
    load(dict: Record<string, string>, locale: string) {
      const changed = locale !== h.locale;
      h.dict = dict;
      h.locale = locale;
      if (changed) subs.forEach((fn) => fn(locale));
    },
  };
  (window as unknown as { __GRAV_I18N: unknown }).__GRAV_I18N = h;
  return h;
}

const settle = async (ms = 0) => {
  await new Promise((r) => setTimeout(r, ms));
  flushSync();
};

let target: HTMLElement;
let app: ReturnType<typeof mount> | null = null;
let stops: Array<() => void> = [];

beforeEach(() => {
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: string) => {
      // the matched rule is not loaded: the screen shows it as deleted
      const found = String(input).endsWith('/redirects/test');
      return new Response(JSON.stringify(found ? { data: response } : { title: 'Not Found' }), { status: found ? 200 : 404, headers: { 'content-type': 'application/json' } });
    }),
  );
});

afterEach(() => {
  if (app) unmount(app);
  app = null;
  stops.forEach((fn) => fn());
  stops = [];
  target?.remove();
  vi.unstubAllGlobals();
  delete (window as unknown as { __GRAV_I18N?: unknown }).__GRAV_I18N;
  location.hash = '';
});

describe('tester screen, dictionary arrives after the result', () => {
  it('the live region announcement is German once the dictionary is there, not stuck on the English text of the first render', async () => {
    const host = installHost();
    location.hash = '#/tester?url=/old';
    target = document.createElement('div');
    document.body.append(target);
    stops = [startRouter(), watchLocale()];
    app = mount(TesterScreen, { target });
    await settle(30);

    const status = () => target.querySelector('[role=status]')?.textContent;
    expect(status()).toBe('Redirects, 301. /faq');

    host.load(GERMAN, 'de');
    await settle(10);

    expect(status()).toBe('Weiterleitung, 301. /faq');
  });
});
