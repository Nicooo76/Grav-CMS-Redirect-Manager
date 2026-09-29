import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { importIssueText, textParams, validationText } from './issue-text';

const DICT: Record<string, string> = {
  'PLUGIN_REDIRECT_MANAGER.IMPORT.ISSUE.TOO_MANY_ROWS': 'Mehr Zeilen als die Grenze von {max}.',
  'PLUGIN_REDIRECT_MANAGER.VALIDATION.LOOP': 'Schleife: {chain}.',
  'PLUGIN_REDIRECT_MANAGER.VALIDATION.SELF_REDIRECT': 'Ziel ist die eigene Quelle: {chain}.',
  'PLUGIN_REDIRECT_MANAGER.VALIDATION.SELF_REDIRECT_CASE': 'Nur die Schreibung unterscheidet sich ({chain}).',
  'PLUGIN_REDIRECT_MANAGER.VALIDATION.CHAIN': 'Kette mit {hops} Schritten: {chain}.',
  'PLUGIN_REDIRECT_MANAGER.VALIDATION.CHAIN_SHORTCUT': 'Direkt auf {shortcut} zeigen.',
  'PLUGIN_REDIRECT_MANAGER.VALIDATION.CHAIN_INCOMING': 'Eine andere Regel läuft hier weiter: {chain}.',
};

beforeEach(() => {
  (window as unknown as { __GRAV_I18N: unknown }).__GRAV_I18N = {
    locale: 'de',
    dir: 'ltr',
    has: (k: string) => k in DICT,
    t: (k: string) => DICT[k] ?? k,
    subscribe: () => () => {},
  };
});
afterEach(() => {
  delete (window as unknown as { __GRAV_I18N?: unknown }).__GRAV_I18N;
});

describe('textParams', () => {
  it('keeps scalars, joins lists and turns a stringified chain into arrows', () => {
    expect(textParams({ max: 5, name: 'x', ok: true, none: null })).toEqual({ max: 5, name: 'x', ok: true });
    expect(textParams({ chain: ['/a', '/b'] })).toEqual({ chain: '/a → /b' });
    expect(textParams({ rule_ids: ['r1', 'r2'] })).toEqual({ rule_ids: 'r1, r2' });
    expect(textParams({ chain: '/a, /b, /a' })).toEqual({ chain: '/a → /b → /a' });
    expect(textParams({ o: { a: 1 } })).toEqual({});
  });
});

describe('importIssueText', () => {
  it('translates a known code with its params', () => {
    expect(importIssueText({ code: 'too_many_rows', message: 'The file has more rows than allowed.', params: { max: 500 } })).toBe('Mehr Zeilen als die Grenze von 500.');
  });
  it('falls back to the rule validation code, then to the server message', () => {
    expect(importIssueText({ code: 'loop', message: 'Redirect loop', params: { chain: '/a, /b, /a' } })).toBe('Schleife: /a → /b → /a.');
    expect(importIssueText({ code: 'something_new', message: 'English text' })).toBe('English text');
  });
});

describe('validationText', () => {
  it('uses the server message when the host has no entry', () => {
    expect(validationText({ code: 'target_scheme', message: 'The target uses a scheme that is not allowed.' })).toBe('The target uses a scheme that is not allowed.');
  });
  it('builds the chain sentence with the shortcut', () => {
    expect(validationText({ code: 'chain', message: 'en', params: { chain: ['/a', '/b', '/c'], hops: 2, shortcut: '/c' } })).toBe('Kette mit 2 Schritten: /a → /b → /c. Direkt auf /c zeigen.');
    expect(validationText({ code: 'chain', message: 'en', params: { chain: ['/a', '/b'], hops: 1, incoming: true } })).toBe('Eine andere Regel läuft hier weiter: /a → /b.');
  });
  it('translates by code and leaves codeless issues alone', () => {
    expect(validationText({ code: 'loop', message: 'en', params: { chain: ['/x', '/y', '/x'] } })).toBe('Schleife: /x → /y → /x.');
    expect(validationText({ code: '', message: 'plain' })).toBe('plain');
  });
});

describe('validationText: self redirect', () => {
  it('uses the letter-case wording only when the server says the paths differ only in case', () => {
    const base = { code: 'self_redirect', message: 'The target resolves to the rule\'s own source.' };
    expect(validationText({ ...base, params: { chain: ['/A', '/a'], case_only: true } })).toBe('Nur die Schreibung unterscheidet sich (/A → /a).');
    expect(validationText({ ...base, params: { chain: ['/a', '/a'] } })).toBe('Ziel ist die eigene Quelle: /a → /a.');
  });
});
