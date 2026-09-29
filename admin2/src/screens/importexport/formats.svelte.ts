/** Import/export formats from GET /redirects/import/formats, shared by both views. Falls back to a built-in list. */
import { api } from '../../lib/api';
import { t } from '../../lib/i18n.svelte';
import { FALLBACK_FORMATS } from '../../lib/import-detect';
import type { ImportFormat } from '../../lib/types';

export const formats = $state<{ list: ImportFormat[]; loaded: boolean; loading: boolean; failed: boolean }>({
  list: FALLBACK_FORMATS,
  loaded: false,
  loading: false,
  failed: false,
});

let pending: Promise<void> | null = null;

export function loadFormats(): Promise<void> {
  if (formats.loaded) return Promise.resolve();
  if (pending) return pending;
  formats.loading = true;
  pending = api
    .get<ImportFormat[]>('/redirects/import/formats')
    .then(({ data }) => {
      if (Array.isArray(data) && data.length) {
        formats.list = data;
        formats.failed = false;
      }
      formats.loaded = true;
    })
    .catch(() => {
      formats.failed = true;
      formats.loaded = true;
    })
    .finally(() => {
      formats.loading = false;
      pending = null;
    });
  return pending;
}

/** Friendly name of a format: our translation when there is one, otherwise the API's label. */
export function formatLabel(f: Pick<ImportFormat, 'id' | 'label'>): string {
  const key = `IMPORTEXPORT.FORMAT_${f.id.toUpperCase()}`;
  const s = t(key);
  return s === key ? f.label : s;
}

/** One-line description, or '' when there is none. */
export function formatDesc(f: Pick<ImportFormat, 'id'>): string {
  const key = `IMPORTEXPORT.FORMAT_${f.id.toUpperCase()}_DESC`;
  const s = t(key);
  return s === key ? '' : s;
}

export function labelForId(id: string | null | undefined): string {
  if (!id) return '';
  const f = formats.list.find((x) => x.id === id);
  return f ? formatLabel(f) : id;
}
