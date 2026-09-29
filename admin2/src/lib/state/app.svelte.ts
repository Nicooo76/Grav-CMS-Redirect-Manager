/** Cross-screen state: editor requests, data versions, stats, shortcuts. */
import { api, isAbort } from '../api';
import type { Rule, RuleInput, Stats, SuggestionCandidate } from '../types';

/* ---------- rule editor requests ---------- */

export interface EditorRequest {
  /** edit an existing rule */
  id?: string;
  /** values for a new rule (e.g. source from a 404 path) */
  prefill?: RuleInput;
  /** suggestion candidates the user can pick a target from (404 monitor) */
  candidates?: SuggestionCandidate[];
  /** called with the saved rule */
  onsaved?: (rule: Rule) => void;
  /** where the request came from, used to decide whether to touch the URL */
  origin?: 'route' | 'screen';
}

export const editor = $state<{ open: boolean; request: EditorRequest | null }>({ open: false, request: null });

export function openEditor(request: EditorRequest = {}): void {
  editor.request = { origin: 'screen', ...request };
  editor.open = true;
}

export function closeEditor(): void {
  editor.open = false;
}

/* ---------- data versions: lists reload when these change ---------- */

export const versions = $state({ rules: 0, notFound: 0, suggestions: 0, stats: 0 });

export type DataArea = keyof typeof versions;

/** Tell other screens that data changed. Rules changes also refresh stats and suggestion counts. */
export function bump(...areas: DataArea[]): void {
  for (const a of areas) versions[a]++;
  if (!areas.includes('stats')) versions.stats++;
}

/* ---------- stats (tab badge, widgets) ---------- */

export const statsState = $state<{ data: Stats | null; error: boolean; loading: boolean }>({ data: null, error: false, loading: false });
let statsAbort: AbortController | null = null;

export async function loadStats(): Promise<void> {
  statsAbort?.abort();
  const ctl = new AbortController();
  statsAbort = ctl;
  statsState.loading = true;
  try {
    const { data } = await api.get<Stats>('/redirects/stats', undefined, ctl.signal);
    statsState.data = data;
    statsState.error = false;
  } catch (e) {
    if (isAbort(e)) return;
    statsState.error = true;
  } finally {
    if (statsAbort === ctl) statsState.loading = false;
  }
}

/* ---------- search shortcut ---------- */

let searchEl: HTMLInputElement | null = null;
export function registerSearch(el: HTMLInputElement | null): () => void {
  searchEl = el;
  return () => {
    if (searchEl === el) searchEl = null;
  };
}
export function focusSearch(): boolean {
  if (!searchEl) return false;
  searchEl.focus();
  searchEl.select();
  return true;
}
