import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError, api, buildHeaders, buildQuery, errorMessage, isAbort, request } from './api';

const json = (body: unknown, init: ResponseInit = {}) =>
  new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' }, ...init });

describe('api client', () => {
  let fetchMock: ReturnType<typeof vi.fn>;
  beforeEach(() => {
    fetchMock = vi.fn();
    vi.stubGlobal('fetch', fetchMock);
    window.__GRAV_API_SERVER_URL = 'https://example.test';
    window.__GRAV_API_PREFIX = '/api/v1';
    window.__GRAV_API_TOKEN = 'token-a';
    window.__GRAV_ENVIRONMENT = 'default';
  });
  afterEach(() => vi.unstubAllGlobals());

  it('builds the URL from server, prefix, path and query and drops empty values', async () => {
    fetchMock.mockResolvedValue(json({ data: [] }));
    await api.get('/redirects/rules', { q: 'a b', page: 2, empty: '', none: undefined, flag: true });
    const [url] = fetchMock.mock.calls[0];
    expect(url).toBe('https://example.test/api/v1/redirects/rules?q=a+b&page=2&flag=1');
  });

  it('re-reads the token from the window on every request', async () => {
    fetchMock.mockImplementation(async () => json({ data: 1 }));
    await api.get('/x');
    window.__GRAV_API_TOKEN = 'token-b';
    await api.get('/x');
    expect(fetchMock.mock.calls[0][1].headers['X-API-Token']).toBe('token-a');
    expect(fetchMock.mock.calls[1][1].headers['X-API-Token']).toBe('token-b');
  });

  it('sends Accept, environment and JSON content type only with a body', () => {
    expect(buildHeaders(false)).toMatchObject({ Accept: 'application/json', 'X-Grav-Environment': 'default' });
    expect(buildHeaders(false)['Content-Type']).toBeUndefined();
    expect(buildHeaders(true)['Content-Type']).toBe('application/json');
    window.__GRAV_API_TOKEN = null;
    expect(buildHeaders(false)['X-API-Token']).toBeUndefined();
  });

  it('unwraps {data, meta} and tolerates bare payloads', async () => {
    fetchMock.mockResolvedValueOnce(json({ data: [1, 2], meta: { total: 2 } }));
    const res = await request<number[]>('GET', '/a');
    expect(res.data).toEqual([1, 2]);
    expect(res.meta.total).toBe(2);
    fetchMock.mockResolvedValueOnce(json({ foo: 1 }));
    expect((await request<any>('GET', '/b')).data).toEqual({ foo: 1 });
  });

  it('serialises the body of writes', async () => {
    fetchMock.mockResolvedValue(json({ data: {} }));
    await api.post('/rules', { source: '/a' });
    expect(fetchMock.mock.calls[0][1]).toMatchObject({ method: 'POST', body: '{"source":"/a"}' });
  });

  it('parses RFC 7807 errors with field errors', async () => {
    fetchMock.mockResolvedValue(
      json(
        { status: 422, title: 'Validation failed', detail: 'Nope', errors: [{ field: 'target', code: 'unsafe_target', message: 'Host not allowed', severity: 'error' }, { field: 'target', code: 'x', message: 'second' }] },
        { status: 422 },
      ),
    );
    const err = await api.get('/x').catch((e) => e);
    expect(err).toBeInstanceOf(ApiError);
    expect(err.status).toBe(422);
    expect(err.detail).toBe('Nope');
    expect(err.errors).toHaveLength(2);
    expect(err.fieldMessages).toEqual({ target: 'Host not allowed' });
  });

  it('reads Retry-After and falls back to the status text', async () => {
    fetchMock.mockResolvedValue(new Response('busy', { status: 429, statusText: 'Too Many Requests', headers: { 'Retry-After': '30' } }));
    const err = await api.get('/x').catch((e) => e);
    expect(err.retryAfter).toBe(30);
    expect(errorMessage(err)).toBe('Too Many Requests');
  });

  it('turns network failures and aborts into typed errors', async () => {
    fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'));
    const net = await api.get('/x').catch((e) => e);
    expect(net.isNetwork).toBe(true);
    const abort = Object.assign(new Error('x'), { name: 'AbortError' });
    fetchMock.mockRejectedValueOnce(abort);
    const a = await api.get('/x').catch((e) => e);
    expect(isAbort(a)).toBe(true);
  });

  it('builds query strings', () => {
    expect(buildQuery()).toBe('');
    expect(buildQuery({ a: 1, b: false })).toBe('?a=1&b=0');
  });
});
