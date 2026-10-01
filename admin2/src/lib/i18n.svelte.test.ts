import { afterEach, describe, expect, it } from 'vitest';
import { flushSync, mount, unmount } from 'svelte';
import TraceTable from '../screens/tester/TraceTable.svelte';
import { registerFallback, watchLocale } from './i18n.svelte';
import { fallbackEn } from './i18n-fallback';
import type { TraceStep } from './types';

registerFallback(fallbackEn);

const P = 'PLUGIN_REDIRECT_MANAGER.UI.';
const GERMAN = { [P + 'COMMON.YES']: 'Ja', [P + 'COMMON.NO']: 'Nein', [P + 'TESTER.COL_RULE']: 'Regel', [P + 'TAB.RULES']: 'Regeln' };

const step = (over: Partial<TraceStep> = {}): TraceStep => ({
  rule_id: 'r1',
  source: '/a',
  match_type: 'exact',
  priority: 100,
  matched: true,
  reason: 'matched',
  path: '/a',
  ...over,
});

/** Admin 2's host: strings by full key, subscribers are told about a language change only. */
function installHost(locale = 'en') {
  const subs = new Set<(l: string) => void>();
  const h = {
    dict: {} as Record<string, string>,
    locale,
    dir: 'ltr' as const,
    t: (k: string) => h.dict[k] ?? k,
    has: (k: string) => k in h.dict,
    subscribe(fn: (l: string) => void) {
      subs.add(fn);
      return () => subs.delete(fn);
    },
    /** the dictionary arrives; the host tells subscribers only when the language changes */
    load(dict: Record<string, string>, newLocale: string) {
      const changed = newLocale !== h.locale;
      h.dict = dict;
      h.locale = newLocale;
      if (changed) subs.forEach((fn) => fn(newLocale));
    },
  };
  (window as unknown as { __GRAV_I18N: unknown }).__GRAV_I18N = h;
  return h;
}

let target: HTMLElement;
let app: ReturnType<typeof mount> | null = null;
let stopWatching: (() => void) | null = null;

function open(): void {
  target = document.createElement('div');
  document.body.append(target);
  stopWatching = watchLocale();
  app = mount(TraceTable, { target, props: { trace: [step(), step({ matched: false, reason: 'no_match', source: '/b' })], winner: 'r1' } });
  flushSync();
}

const cells = () => [...target.querySelectorAll('td span.yes, td span.no')].map((e) => e.textContent?.trim());

afterEach(() => {
  if (app) unmount(app);
  app = null;
  stopWatching?.();
  stopWatching = null;
  target?.remove();
  delete (window as unknown as { __GRAV_I18N?: unknown }).__GRAV_I18N;
});

// real time: the probe runs every 200 ms, and a fake clock would hold back Svelte's own flush as well
const settle = (ms: number) => new Promise<void>((r) => setTimeout(r, ms));

describe('texts follow the dictionary that arrives after the first render', () => {
  it('shows the English fallback first, then German when the host announces the language change', () => {
    const host = installHost('en');
    open();
    expect(cells()).toEqual(['Yes', 'No']);

    host.load(GERMAN, 'de');
    flushSync();

    expect(cells()).toEqual(['Ja', 'Nein']);
    expect(target.querySelector('th')?.nextElementSibling?.textContent?.trim()).toBe('Regel');
  });

  it('turns German as well when the dictionary arrives for the language that is already set (the host stays silent)', async () => {
    const host = installHost('de');
    open();
    expect(cells()).toEqual(['Yes', 'No']);

    host.load(GERMAN, 'de');
    await settle(400);
    flushSync();

    expect(cells()).toEqual(['Ja', 'Nein']);
  });

  it('keeps German texts that were already there', async () => {
    installHost('de').load(GERMAN, 'de');
    open();
    await settle(300);
    expect(cells()).toEqual(['Ja', 'Nein']);
  });
});
