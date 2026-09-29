import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushSync, mount, unmount } from 'svelte';
import Panel from './Panel.svelte';
import { registerFallback } from '../lib/i18n.svelte';
import { fallbackPanelEn } from '../lib/i18n-fallback-panel';

registerFallback(fallbackPanelEn);

const rule = (over: Record<string, unknown> = {}) => ({
  id: 'r1',
  source: '/blog',
  target: '/news',
  match_type: 'exact',
  status: 301,
  target_type: 'page',
  origin: 'auto',
  state: 'active',
  languages: [],
  note: '',
  created_at: '2026-09-29T09:30:00+00:00',
  unseen: true,
  recent: true,
  hits: 0,
  last_hit: null,
  ...over,
});

const context = (over: Record<string, unknown> = {}) => ({
  route: '/news',
  language: null,
  incoming: [rule()],
  incoming_total: 1,
  created: [rule()],
  created_total: 1,
  unseen: 1,
  outgoing: null,
  pending: [],
  not_found: [],
  generated_at: '2026-09-29T10:00:00+00:00',
  ...over,
});

interface Call {
  method: string;
  url: URL;
  body: unknown;
}

let calls: Call[];
let answers: (call: Call) => { status?: number; body: unknown } | undefined;

function installFetch(): void {
  calls = [];
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: string, init: RequestInit = {}) => {
      const call: Call = { method: init.method ?? 'GET', url: new URL(input, 'http://x'), body: init.body ? JSON.parse(String(init.body)) : undefined };
      calls.push(call);
      const a = answers(call) ?? { body: { data: {} } };
      return new Response(JSON.stringify(a.body), { status: a.status ?? 200, headers: { 'content-type': 'application/json' } });
    }),
  );
}

const settle = async (ms = 0) => {
  await new Promise((r) => setTimeout(r, ms));
  flushSync();
};

let host: HTMLElement;
let app: ReturnType<typeof mount> | null = null;
let badges: number[];
let closed: number;

function open(attrs: Record<string, string> = { route: '/news', lang: 'en', type: 'pages' }) {
  host = document.createElement('div');
  for (const [k, v] of Object.entries(attrs)) host.setAttribute(k, v);
  badges = [];
  closed = 0;
  host.addEventListener('badge', (e) => badges.push((e as CustomEvent).detail.count));
  host.addEventListener('close', () => closed++);
  document.body.append(host);
  app = mount(Panel, { target: host, props: { host } });
}

beforeEach(() => {
  installFetch();
  answers = (c) => {
    if (c.url.pathname.endsWith('/redirects/page-context')) return { body: { data: context(), meta: { permissions: { read: true, manage: true } } } };
    if (c.url.pathname.endsWith('/redirects/page-context/seen')) return { body: { data: { cleared: 1, count: null, sidebar: null } } };
    if (c.url.pathname.endsWith('/redirects/rules/validate')) return { body: { data: { issues: [] } } };
    if (c.url.pathname.endsWith('/redirects/rules')) return { status: 201, body: { data: { id: 'new' } } };
    return undefined;
  };
});

afterEach(() => {
  if (app) unmount(app);
  app = null;
  host?.remove();
  vi.unstubAllGlobals();
  vi.useRealTimers();
});

describe('Panel', () => {
  it('asks for the page of the host attributes and tells the toolbar the unseen count', async () => {
    open();
    await settle(10);

    const get = calls.find((c) => c.url.pathname.endsWith('/redirects/page-context'));
    expect(get?.url.searchParams.get('route')).toBe('/news');
    expect(get?.url.searchParams.get('lang')).toBe('en');
    expect(badges).toEqual([1]);
    expect(host.textContent).toContain('Created automatically just now');
    expect(host.textContent).toContain('/blog');
    expect(host.textContent).toContain('Mark as seen');
  });

  it('"Mark as seen" posts the page, clears the notice and sets the toolbar badge to 0', async () => {
    open();
    await settle(10);
    const button = [...host.querySelectorAll('button')].find((b) => b.textContent?.trim() === 'Mark as seen')!;
    button.click();
    await settle(10);

    const post = calls.find((c) => c.method === 'POST' && c.url.pathname.endsWith('/seen'));
    expect(post?.body).toEqual({ route: '/news', lang: 'en' });
    expect(host.textContent).not.toContain('Created automatically just now');
    expect(badges).toEqual([1, 0]);
    expect(host.querySelector('[role=status]')?.textContent).toBe('Marked as seen.');
  });

  it('a reader gets no "Mark as seen" and no form, only the read-only note', async () => {
    answers = () => ({ body: { data: context(), meta: { permissions: { read: true, manage: false } } } });
    open();
    await settle(10);

    expect(host.textContent).not.toContain('Mark as seen');
    expect(host.querySelector('input')).toBeNull();
    expect(host.textContent).toContain('You can view redirects but not add them.');
  });

  it('reloads when the host changes the route (a rename moves the editor)', async () => {
    open();
    await settle(10);
    host.setAttribute('route', '/news-2');
    await settle(20);

    const routes = calls.filter((c) => c.url.pathname.endsWith('/redirects/page-context')).map((c) => c.url.searchParams.get('route'));
    expect(routes).toEqual(['/news', '/news-2']);
  });

  it('falls back to the editor globals without attributes', async () => {
    window.__GRAV_PAGE_ROUTE = '/from-global';
    window.__GRAV_CONTENT_LANG = 'de';
    try {
      open({});
      await settle(10);
      const get = calls.find((c) => c.url.pathname.endsWith('/redirects/page-context'));
      expect(get?.url.searchParams.get('route')).toBe('/from-global');
      expect(get?.url.searchParams.get('lang')).toBe('de');
    } finally {
      delete window.__GRAV_PAGE_ROUTE;
      delete window.__GRAV_CONTENT_LANG;
    }
  });

  it('shows the warning when a rule redirects the page away', async () => {
    answers = () => ({ body: { data: context({ outgoing: { ...rule({ id: 'away', source: '/news', target: '/shop' }), location: '/shop', status: 302 } }), meta: { permissions: { read: true, manage: true } } } });
    open();
    await settle(10);

    expect(host.textContent).toContain('This page is redirected away');
    expect(host.textContent).toContain('/news sends visitors to /shop (302)');
  });

  it('closes on Escape and on the close button', async () => {
    open();
    await settle(10);
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    (host.querySelector('button[aria-label]') as HTMLButtonElement).click();
    expect(closed).toBe(2);
  });

  it('checks the old URL while typing (debounced), then creates a 301 exact rule to the page', async () => {
    open();
    await settle(10);
    const input = host.querySelector('input') as HTMLInputElement;
    input.value = 'https://example.com/old-url';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await settle(500);

    const check = calls.find((c) => c.url.pathname.endsWith('/rules/validate'));
    expect(check?.body).toMatchObject({ source: '/old-url', target: '/news', target_type: 'page', match_type: 'exact', status: 301 });

    (host.querySelector('form') as HTMLFormElement).dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await settle(30);

    const create = calls.find((c) => c.method === 'POST' && c.url.pathname.endsWith('/redirects/rules'));
    expect(create?.body).toEqual({ source: '/old-url', target: '/news', target_type: 'page', match_type: 'exact', status: 301, enabled: true, note: 'Added in the page editor' });
    expect(input.value).toBe('');
  });

  it('shows the errors of the check and keeps the button disabled', async () => {
    answers = (c) =>
      c.url.pathname.endsWith('/rules/validate')
        ? { body: { data: { issues: [{ code: 'self_redirect', severity: 'error', message: 'The source and the target are the same.' }] } } }
        : { body: { data: context(), meta: { permissions: { read: true, manage: true } } } };
    open();
    await settle(10);
    const input = host.querySelector('input') as HTMLInputElement;
    input.value = '/news';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await settle(500);

    expect(host.querySelector('[role=alert]')?.textContent).toContain('same');
    expect((host.querySelector('button[type=submit]') as HTMLButtonElement).disabled).toBe(true);
  });

  it('shows an error state with a retry when the request fails', async () => {
    answers = () => ({ status: 403, body: { status: 403, title: 'Forbidden', detail: 'No.' } });
    open();
    await settle(10);

    expect(host.querySelector('[role=alert]')?.textContent).toContain('Could not load data');
    expect(host.textContent).toContain('Try again');
    expect(badges).toEqual([]);
  });
});
