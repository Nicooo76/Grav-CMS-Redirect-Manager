/** GET /redirects/export and the browser download, shared by the export view and "Export selected". */
import { api } from './api';
import { downloadBlob } from './download';
import type { ExportResult } from './types';

export interface ExportRequest {
  format: string;
  /** export only these rules (comma list on the wire) */
  ids?: string[];
  onlyEnabled?: boolean;
  group?: string;
  status?: string;
}

export async function requestExport(r: ExportRequest): Promise<ExportResult> {
  const { data } = await api.get<ExportResult>('/redirects/export', {
    format: r.format,
    ids: r.ids?.length ? r.ids.join(',') : undefined,
    only_enabled: r.onlyEnabled || undefined,
    group: r.group || undefined,
    status: r.status || undefined,
  });
  return data;
}

/** Saves the export as a file. Returns false when the export has no content. */
export function saveExport(data: ExportResult, format: string): boolean {
  if (!data.content || !data.content.trim()) return false;
  downloadBlob(data.content, data.filename || `redirects.${format}`, data.mime || 'text/plain');
  return true;
}
