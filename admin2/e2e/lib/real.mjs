/**
 * Shared helpers for the scripts that drive the real Admin 2 of the dev site (http://127.0.0.1:8088).
 * Credentials come from _testsite/.test-credentials (or .test-credentials-readonly) and are never printed.
 */
import { chromium } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

export const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
export const site = join(root, '..', '..', '_testsite');

export function credentials(readonly = false) {
  const file = join(site, readonly ? '.test-credentials-readonly' : '.test-credentials');
  const c = Object.fromEntries(readFileSync(file, 'utf8').split('\n').filter((l) => l.includes('=')).map((l) => [l.slice(0, l.indexOf('=')).trim(), l.slice(l.indexOf('=') + 1).trim()]));
  return readonly ? { user: c.GRAV_READONLY_USER, pass: c.GRAV_READONLY_PASS, url: 'http://127.0.0.1:8088' } : { user: c.GRAV_ADMIN_USER, pass: c.GRAV_ADMIN_PASS, url: c.GRAV_URL || 'http://127.0.0.1:8088' };
}

/** Opens a context, logs in and returns { ctx, page, base, pageEl, applyTheme, errors }. */
export async function openAdmin(browser, { theme = 'light', readonly = false, viewport = { width: 1440, height: 900 }, locale = 'en-US' } = {}) {
  const cred = credentials(readonly);
  const ctx = await browser.newContext({ viewport, colorScheme: theme, locale, reducedMotion: 'reduce' });
  const page = await ctx.newPage();
  page.setDefaultTimeout(7000);
  const errors = [];
  const failed = [];
  page.on('console', (m) => {
    if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errors.push(m.text().slice(0, 300));
  });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  page.on('response', (r) => {
    if (r.status() >= 400 && r.url().includes('/redirects')) failed.push(`${r.status()} ${r.request().method()} ${new URL(r.url()).pathname.replace(/^.*\/api\/v1/, '')}`);
  });
  await page.goto(`${cred.url}/admin`);
  await page.waitForSelector('input', { timeout: 20000 });
  await page.locator('input[type=text], input[name=username], input[autocomplete=username]').first().fill(cred.user);
  await page.locator('input[type=password]').first().fill(cred.pass);
  await page.locator('button[type=submit]').first().click();
  await page.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 20000 }).catch(() => {});
  await page.waitForTimeout(2000);
  const pageEl = () => page.locator('grav-redirect-manager--page');
  const applyTheme = () => page.evaluate((t) => document.documentElement.classList.toggle('dark', t === 'dark'), theme);
  return { ctx, page, base: cred.url, pageEl, applyTheme, errors, failed };
}

export async function gotoPlugin(h, hash = '#/rules') {
  await h.page.goto(`${h.base}/admin/plugin/redirect-manager${hash}`);
  await h.page.waitForSelector('grav-redirect-manager--page', { timeout: 15000 });
  await h.page.waitForFunction(() => !document.querySelector('grav-redirect-manager--page')?.shadowRoot?.querySelector('[aria-busy="true"]'), null, { timeout: 15000 }).catch(() => {});
  await h.applyTheme();
  await h.page.waitForTimeout(700);
}

export const launch = () => chromium.launch();

/** Small REST client for checks and cleanup (JWT via the API plugin's token route). */
export async function apiClient({ readonly = false } = {}) {
  const cred = credentials(readonly);
  const base = `${cred.url}/api/v1`;
  const r = await fetch(base + '/auth/token', { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ username: cred.user, password: cred.pass }) });
  const j = await r.json();
  const token = j.data?.access_token ?? j.access_token;
  const call = async (method, path, body) => {
    const res = await fetch(base + path, { method, headers: { 'X-API-Token': token, 'content-type': 'application/json', accept: 'application/json' }, body: body === undefined ? undefined : JSON.stringify(body) });
    let json = null;
    try {
      json = await res.json();
    } catch {
      /* no body */
    }
    return { status: res.status, data: json?.data, meta: json?.meta, json };
  };
  call.token = token;
  call.base = base;
  return call;
}

/** Collects pass/fail lines like the other e2e scripts. */
export function reporter({ onFail } = {}) {
  const report = [];
  const say = (name, ok, extra = '') => {
    report.push({ name, ok, extra });
    console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${extra ? '  ' + String(extra).slice(0, 220) : ''}`);
    return ok;
  };
  /** runs one step; an exception counts as a failure and the run goes on */
  const step = async (name, fn) => {
    try {
      const r = await fn();
      if (r === false) say(name, false);
      else if (r !== undefined && r !== true) say(name, true, r);
      else say(name, true);
    } catch (e) {
      say(name, false, String(e.message).split('\n')[0]);
      try {
        await onFail?.(name, e);
      } catch {
        /* a failing screenshot must not hide the failure */
      }
    }
  };
  return { report, say, step, failed: () => report.filter((r) => !r.ok) };
}

/** Fails a step with a message (use in the false branch of a returned check). */
export function bad(message) {
  throw new Error(message);
}
