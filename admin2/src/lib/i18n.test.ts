import { afterEach, describe, expect, it, vi } from 'vitest';
import { createTranslator, formatMessage, watchDictionary } from './i18n';

const host = (dict: Record<string, string>, locale = 'en') => ({
  t: (key: string) => dict[key] ?? key.split('.').pop()!.toLowerCase(),
  has: (key: string) => key in dict,
  locale,
  dir: 'ltr' as const,
  subscribe: () => () => {},
});

describe('formatMessage', () => {
  it('substitutes placeholders and leaves unknown ones', () => {
    expect(formatMessage('Hello {name}', { name: 'Ada' })).toBe('Hello Ada');
    expect(formatMessage('Hello {who}', {})).toBe('Hello {who}');
    expect(formatMessage('No braces')).toBe('No braces');
    expect(formatMessage('Broken {', {})).toBe('Broken {');
  });
  it('resolves plurals with # and =0', () => {
    const m = '{n, plural, =0 {No rules} one {# rule} other {# rules}}';
    expect(formatMessage(m, { n: 0 })).toBe('No rules');
    expect(formatMessage(m, { n: 1 })).toBe('1 rule');
    expect(formatMessage(m, { n: 1234 }, 'en')).toBe('1,234 rules');
  });
  it('uses the locale plural rules', () => {
    const m = '{n, plural, one {# Regel} other {# Regeln}}';
    expect(formatMessage(m, { n: 1 }, 'de')).toBe('1 Regel');
    expect(formatMessage(m, { n: 2 }, 'de')).toBe('2 Regeln');
  });
  it('supports select and nested placeholders', () => {
    expect(formatMessage('{k, select, a {Alpha {who}} other {Other}}', { k: 'a', who: 'X' })).toBe('Alpha X');
    expect(formatMessage('{k, select, a {Alpha} other {Other}}', { k: 'z' })).toBe('Other');
  });
});

describe('translator', () => {
  const fallback = { 'A.B': 'English {n, plural, one {# thing} other {# things}}', 'C.D': 'Only fallback' };

  it('uses the English fallback when the host has no dictionary', () => {
    const t = createTranslator(fallback, () => undefined);
    expect(t.t('A.B', { n: 3 })).toBe('English 3 things');
    expect(t.locale()).toBeTruthy();
  });

  it('checks has() before t() so the host never humanises our keys', () => {
    const t = createTranslator(fallback, () => host({}));
    expect(t.t('C.D')).toBe('Only fallback');
  });

  it('prefers the host string and formats raw values itself', () => {
    const h = host({ 'PLUGIN_REDIRECT_MANAGER.UI.A.B': 'Deutsch {n, plural, one {# Ding} other {# Dinge}}' }, 'de');
    const t = createTranslator(fallback, () => h);
    expect(t.t('A.B', { n: 2 })).toBe('Deutsch 2 Dinge');
  });

  it('returns the key when nothing knows it', () => {
    const t = createTranslator({}, () => undefined);
    expect(t.t('X.Y')).toBe('X.Y');
  });

  it('survives a host that throws', () => {
    const h = { ...host({ 'PLUGIN_REDIRECT_MANAGER.UI.C.D': 'x' }), t: () => { throw new Error('boom'); } };
    const t = createTranslator(fallback, () => h);
    expect(t.t('C.D')).toBe('Only fallback');
  });

  it('forwards subscriptions', () => {
    let called = 0;
    const h = { ...host({}), subscribe: (fn: (l: string) => void) => { fn('de'); return () => { called = -1; }; } };
    const t = createTranslator({}, () => h);
    const off = t.subscribe(() => called++);
    expect(called).toBe(1);
    off();
    expect(called).toBe(-1);
  });
});

describe('watchDictionary', () => {
  /** A host like Admin 2's: the dictionary can be replaced without a notification (same language). */
  function lateHost() {
    const state = { locale: 'de', dict: {} as Record<string, string> };
    return { state, host: { t: (k: string) => state.dict[k] ?? k, has: (k: string) => k in state.dict, get locale() { return state.locale; }, dir: 'ltr' as const, subscribe: () => () => {} } };
  }

  afterEach(() => vi.useRealTimers());

  it('calls back once when the dictionary arrives for the language that is already set', () => {
    vi.useFakeTimers();
    const { state, host } = lateHost();
    const t = createTranslator({ 'COMMON.YES': 'Yes' }, () => host);
    const changed = vi.fn();
    watchDictionary(t, changed, { intervalMs: 100, maxMs: 5000 });

    vi.advanceTimersByTime(500);
    expect(changed).not.toHaveBeenCalled();
    expect(t.t('COMMON.YES')).toBe('Yes');

    state.dict = { 'PLUGIN_REDIRECT_MANAGER.UI.COMMON.YES': 'Ja' };
    vi.advanceTimersByTime(100);
    expect(changed).toHaveBeenCalledTimes(1);
    expect(t.t('COMMON.YES')).toBe('Ja');

    vi.advanceTimersByTime(1000);
    expect(changed).toHaveBeenCalledTimes(1);
  });

  it('notices a language change and a replaced dictionary', () => {
    vi.useFakeTimers();
    const { state, host } = lateHost();
    state.dict = { 'PLUGIN_REDIRECT_MANAGER.UI.COMMON.YES': 'Ja' };
    const t = createTranslator({}, () => host);
    const changed = vi.fn();
    watchDictionary(t, changed, { intervalMs: 100 });

    state.locale = 'fr';
    vi.advanceTimersByTime(100);
    state.dict = { 'PLUGIN_REDIRECT_MANAGER.UI.COMMON.YES': 'Oui' };
    vi.advanceTimersByTime(100);
    expect(changed).toHaveBeenCalledTimes(2);
  });

  it('stops after the time limit and when told to', () => {
    vi.useFakeTimers();
    const { state, host } = lateHost();
    const t = createTranslator({}, () => host);
    const changed = vi.fn();
    watchDictionary(t, changed, { intervalMs: 100, maxMs: 1000 });
    vi.advanceTimersByTime(1500);
    state.dict = { 'PLUGIN_REDIRECT_MANAGER.UI.COMMON.YES': 'Ja' };
    vi.advanceTimersByTime(1000);
    expect(changed).not.toHaveBeenCalled();

    const stop = watchDictionary(t, changed, { intervalMs: 100 });
    stop();
    state.dict = { 'PLUGIN_REDIRECT_MANAGER.UI.COMMON.YES': 'Nein' };
    vi.advanceTimersByTime(1000);
    expect(changed).not.toHaveBeenCalled();
    expect(vi.getTimerCount()).toBe(0);
  });

  it('survives having no host at all', () => {
    vi.useFakeTimers();
    const t = createTranslator({}, () => undefined);
    const changed = vi.fn();
    const stop = watchDictionary(t, changed, { intervalMs: 100 });
    vi.advanceTimersByTime(1000);
    expect(changed).not.toHaveBeenCalled();
    stop();
  });
});
