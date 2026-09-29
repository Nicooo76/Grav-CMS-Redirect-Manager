import { ApiError } from './api';
import { t } from './i18n.svelte';

/** A sentence a human can act on for any failed request. */
export function describeError(e: unknown): string {
  if (e instanceof ApiError) {
    if (e.isNetwork) return t('COMMON.ERROR_NETWORK');
    if (e.status === 401) return t('COMMON.ERROR_UNAUTHORIZED');
    if (e.status === 403) return t('COMMON.ERROR_FORBIDDEN');
    if (e.status === 404 && !e.detail) return t('COMMON.ERROR_NOT_FOUND');
    if (e.detail) return e.detail;
    if (e.status >= 500) return t('COMMON.ERROR_SERVER', { status: e.status });
    return e.title || t('COMMON.ERROR');
  }
  if (e instanceof Error && e.message) return e.message;
  return t('COMMON.ERROR');
}
