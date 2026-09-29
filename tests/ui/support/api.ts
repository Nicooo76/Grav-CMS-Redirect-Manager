import { credentials, runState } from './env';

export interface ApiResult<T = any> {
  status: number;
  data: T;
  meta: any;
  json: any;
  headers: Headers;
}

export type ApiCall = (method: string, path: string, body?: unknown) => Promise<ApiResult>;

/** A small REST client of the site's API (JWT from the token route). Used for state checks and for arranging data. */
export async function apiFor(who: 'admin' | 'readonly' = 'admin'): Promise<ApiCall> {
  return apiClient(runState().baseUrl, credentials()[who]);
}

export async function apiClient(base: string, cred: { username: string; password: string }): Promise<ApiCall> {
  const who = cred.username;
  const apiBase = `${base}/api/v1`;
  const r = await fetch(apiBase + '/auth/token', {
    method: 'POST',
    headers: { 'content-type': 'application/json' },
    body: JSON.stringify({ username: cred.username, password: cred.password }),
  });
  const j: any = await r.json();
  const token: string | undefined = j.data?.access_token ?? j.access_token;
  if (!token) throw new Error(`API login of the ${who} user failed with HTTP ${r.status}`);
  const call: ApiCall = async (method, path, body) => {
    const res = await fetch(apiBase + path, {
      method,
      headers: { 'X-API-Token': token, 'content-type': 'application/json', accept: 'application/json' },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    let json: any = null;
    try {
      json = await res.json();
    } catch {
      /* no body */
    }
    return { status: res.status, data: json?.data, meta: json?.meta, json, headers: res.headers };
  };
  return call;
}
