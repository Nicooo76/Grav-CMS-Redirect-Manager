/**
 * Thin fetch wrapper for the plugin's REST routes (docs/API.md).
 *
 * The token is read from `window.__GRAV_API_TOKEN` on every call because the
 * host rotates it about once an hour; caching it would break long sessions.
 */
import type { ApiMeta } from './types';

export interface FieldError {
  field: string;
  code: string;
  message: string;
  severity: 'error' | 'warning';
  params?: Record<string, unknown>;
}

export class ApiError extends Error {
  readonly status: number;
  readonly title: string;
  readonly detail: string;
  readonly errors: FieldError[];
  readonly retryAfter: number | null;
  readonly aborted: boolean;

  constructor(init: {
    status: number;
    title?: string;
    detail?: string;
    errors?: FieldError[];
    retryAfter?: number | null;
    aborted?: boolean;
  }) {
    const detail = init.detail ?? '';
    const title = init.title ?? '';
    super(detail || title || `HTTP ${init.status}`);
    this.name = 'ApiError';
    this.status = init.status;
    this.title = title;
    this.detail = detail;
    this.errors = init.errors ?? [];
    this.retryAfter = init.retryAfter ?? null;
    this.aborted = init.aborted ?? false;
  }

  /** field name -> first message, for inline form errors */
  get fieldMessages(): Record<string, string> {
    const out: Record<string, string> = {};
    for (const e of this.errors) if (!(e.field in out)) out[e.field] = e.message;
    return out;
  }

  get isNetwork(): boolean {
    return this.status === 0;
  }
}

export interface ApiResponse<T> {
  data: T;
  meta: ApiMeta;
  headers: Headers;
}

export type Query = Record<string, string | number | boolean | null | undefined>;

export interface RequestOptions {
  query?: Query;
  body?: unknown;
  signal?: AbortSignal;
  headers?: Record<string, string>;
}

export function baseUrl(): string {
  return (window.__GRAV_API_SERVER_URL ?? '') + (window.__GRAV_API_PREFIX ?? '/api/v1');
}

export function buildQuery(query?: Query): string {
  if (!query) return '';
  const p = new URLSearchParams();
  for (const [k, v] of Object.entries(query)) {
    if (v === undefined || v === null || v === '') continue;
    p.set(k, typeof v === 'boolean' ? (v ? '1' : '0') : String(v));
  }
  const s = p.toString();
  return s ? `?${s}` : '';
}

export function buildHeaders(json: boolean, extra?: Record<string, string>): Record<string, string> {
  const h: Record<string, string> = { Accept: 'application/json' };
  const token = window.__GRAV_API_TOKEN;
  if (token) h['X-API-Token'] = token;
  const env = window.__GRAV_ENVIRONMENT;
  if (env) h['X-Grav-Environment'] = env;
  if (json) h['Content-Type'] = 'application/json';
  return { ...h, ...extra };
}

async function parseError(res: Response): Promise<ApiError> {
  let body: any = null;
  try {
    body = await res.json();
  } catch {
    /* not JSON */
  }
  const retry = Number(res.headers.get('Retry-After'));
  const raw: any[] = Array.isArray(body?.errors) ? body.errors : [];
  const errors: FieldError[] = raw
    .filter((e) => e && (e.message || e.detail))
    .map((e) => ({
      field: String(e.field ?? ''),
      code: String(e.code ?? ''),
      message: String(e.message ?? e.detail ?? ''),
      severity: e.severity === 'warning' ? 'warning' : 'error',
      ...(e.params && typeof e.params === 'object' ? { params: e.params } : {}),
    }));
  return new ApiError({
    status: res.status,
    title: typeof body?.title === 'string' ? body.title : res.statusText,
    detail: typeof body?.detail === 'string' ? body.detail : (errors[0]?.message ?? ''),
    errors,
    retryAfter: Number.isFinite(retry) && retry > 0 ? retry : null,
  });
}

export async function request<T = unknown>(
  method: string,
  path: string,
  opts: RequestOptions = {},
): Promise<ApiResponse<T>> {
  const hasBody = opts.body !== undefined;
  const url = baseUrl() + path + buildQuery(opts.query);
  let res: Response;
  try {
    res = await fetch(url, {
      method,
      headers: buildHeaders(hasBody, opts.headers),
      body: hasBody ? JSON.stringify(opts.body) : undefined,
      signal: opts.signal,
    });
  } catch (e) {
    if ((e as Error)?.name === 'AbortError') throw new ApiError({ status: 0, aborted: true, title: 'Aborted' });
    throw new ApiError({ status: 0, title: 'Network error', detail: (e as Error)?.message ?? '' });
  }
  if (!res.ok) throw await parseError(res);
  if (res.status === 204) return { data: undefined as T, meta: {}, headers: res.headers };
  let json: any = null;
  try {
    json = await res.json();
  } catch {
    throw new ApiError({ status: res.status, title: 'Invalid response' });
  }
  const hasEnvelope = json && typeof json === 'object' && 'data' in json;
  return {
    data: (hasEnvelope ? json.data : json) as T,
    meta: (hasEnvelope ? json.meta : undefined) ?? {},
    headers: res.headers,
  };
}

export const api = {
  get: <T>(path: string, query?: Query, signal?: AbortSignal) => request<T>('GET', path, { query, signal }),
  post: <T>(path: string, body?: unknown, opts: Omit<RequestOptions, 'body'> = {}) =>
    request<T>('POST', path, { ...opts, body: body ?? {} }),
  patch: <T>(path: string, body: unknown, opts: Omit<RequestOptions, 'body'> = {}) =>
    request<T>('PATCH', path, { ...opts, body }),
  delete: <T>(path: string, opts: RequestOptions = {}) => request<T>('DELETE', path, opts),
};

/** True for errors that should silently be ignored (superseded request). */
export function isAbort(e: unknown): boolean {
  return e instanceof ApiError && e.aborted;
}

/** Message for a toast or inline banner. */
export function errorMessage(e: unknown, fallback = ''): string {
  if (e instanceof ApiError) return e.detail || e.title || fallback;
  if (e instanceof Error) return e.message || fallback;
  return fallback;
}
