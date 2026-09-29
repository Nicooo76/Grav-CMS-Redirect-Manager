/**
 * State and actions of the 404 monitor. Mutations are optimistic: rows leave the
 * list at once and come back with an error toast when the API refuses.
 */
import { api, isAbort } from '../../lib/api';
import { describeError } from '../../lib/errors';
import { t } from '../../lib/i18n.svelte';
import {
  DEFAULT_NF,
  fillTrend,
  fromHashQuery,
  globMatch,
  listKey,
  toApiQuery,
  toHashQuery,
  toTrendQuery,
  type NotFoundState,
  type TrendPoint,
} from '../../lib/notfound-query';
import { navigate } from '../../lib/router.svelte';
import { bump, openEditor } from '../../lib/state/app.svelte';
import { dialogs, toast } from '../../lib/state/notify.svelte';
import type { NotFoundEntry, NotFoundMeta, NotFoundRow, SuggestionCandidate } from '../../lib/types';

export interface EntriesState {
  loading: boolean;
  error: unknown;
  rows: NotFoundEntry[];
}

class NotFoundStore {
  list = $state<NotFoundState>({ ...DEFAULT_NF });
  rows = $state<NotFoundRow[]>([]);
  meta = $state<NotFoundMeta | null>(null);
  loading = $state(false);
  loaded = $state(false);
  error = $state<unknown>(null);

  trend = $state<TrendPoint[]>([]);
  trendLoaded = $state(false);
  trendLoading = $state(false);
  trendError = $state<unknown>(null);

  expanded = $state<Record<string, true>>({});
  entries = $state<Record<string, EntriesState>>({});
  /** paths whose "create redirect" is fetching candidates */
  busy = $state<Record<string, true>>({});
  /** text for the aria-live region */
  announcement = $state('');

  #abort: AbortController | null = null;
  #trendAbort: AbortController | null = null;

  get total(): number {
    return this.meta?.total ?? this.rows.length;
  }
  get totals(): { hits: number; paths: number } {
    return { hits: this.meta?.totals?.hits ?? 0, paths: this.meta?.totals?.paths ?? this.total };
  }

  /* ---------- loading ---------- */

  async load(opts: { silent?: boolean } = {}): Promise<void> {
    this.#abort?.abort();
    const ctl = new AbortController();
    this.#abort = ctl;
    if (!opts.silent) this.loading = true;
    try {
      const { data, meta } = await api.get<NotFoundRow[]>('/redirects/404', toApiQuery(this.list), ctl.signal);
      const m = meta as NotFoundMeta;
      const total = m.total ?? data.length;
      // paged past the end (rows removed from the last page): step back
      if (data.length === 0 && total > 0 && this.list.page > 1) {
        this.list.page = Math.max(1, Math.ceil(total / Math.max(1, m.per_page || this.list.per_page)));
        this.#syncUrl();
        return this.load(opts);
      }
      this.rows = data;
      this.meta = { ...m, total };
      this.error = null;
      this.loaded = true;
      this.#announce();
    } catch (e) {
      if (isAbort(e)) return;
      this.error = e;
    } finally {
      if (this.#abort === ctl) {
        this.loading = false;
        this.#abort = null;
      }
    }
  }

  async loadTrend(): Promise<void> {
    this.#trendAbort?.abort();
    const ctl = new AbortController();
    this.#trendAbort = ctl;
    this.trendLoading = true;
    try {
      const { data } = await api.get<{ days: Record<string, number> }>('/redirects/404/trend', toTrendQuery(this.list), ctl.signal);
      this.trend = fillTrend(data?.days, this.list.days);
      this.trendError = null;
      this.trendLoaded = true;
    } catch (e) {
      if (isAbort(e)) return;
      this.trendError = e;
    } finally {
      if (this.#trendAbort === ctl) {
        this.trendLoading = false;
        this.#trendAbort = null;
      }
    }
  }

  /** Reloads list and trend without the loading state (after own mutations or changes elsewhere). */
  refresh(): void {
    void this.load({ silent: true });
    void this.loadTrend();
  }

  #announce(): void {
    const { paths, hits } = this.totals;
    this.announcement = t('NOTFOUND.SUMMARY', { paths, hits, days: this.list.days });
  }

  #syncUrl(): void {
    navigate({ name: 'notfound', query: toHashQuery(this.list) }, { replace: true });
  }

  /** Change list state; resets to page 1 unless `page` is part of the patch. */
  setList(patch: Partial<NotFoundState>): void {
    const next: NotFoundState = { ...this.list, ...patch };
    if (!('page' in patch)) next.page = 1;
    if (listKey(next) === listKey(this.list)) return;
    const trendChanged = next.days !== this.list.days || next.bots !== this.list.bots;
    this.list = next;
    this.#syncUrl();
    void this.load();
    if (trendChanged) void this.loadTrend();
  }

  /** URL -> state (mount, back/forward). On mount (`first`) an already loaded list is refreshed, other screens may have changed data. */
  applyRoute(query: Record<string, string>, first = false): void {
    const next = fromHashQuery(query);
    const changed = listKey(next) !== listKey(this.list);
    const trendChanged = next.days !== this.list.days || next.bots !== this.list.bots;
    if (changed) this.list = next;
    if (changed || !this.loaded) {
      void this.load();
      if (trendChanged || !this.trendLoaded) void this.loadTrend();
    } else if (first) {
      this.refresh();
    }
  }

  resetFilters(): void {
    this.setList({ q: '', class: '', include_resolved: false });
  }

  /* ---------- optimistic helpers ---------- */

  #snapshot() {
    const rows = this.rows.slice();
    const meta = this.meta ? { ...this.meta, totals: this.meta.totals ? { ...this.meta.totals } : undefined } : null;
    return () => {
      this.rows = rows;
      this.meta = meta;
    };
  }

  #removeWhere(pred: (r: NotFoundRow) => boolean): { restore: () => void; removed: number } {
    const restore = this.#snapshot();
    const gone = this.rows.filter(pred);
    if (gone.length === 0) return { restore, removed: 0 };
    this.rows = this.rows.filter((r) => !pred(r));
    if (this.meta) {
      const hits = gone.reduce((a, r) => a + r.hits, 0);
      this.meta = {
        ...this.meta,
        total: Math.max(0, (this.meta.total ?? 0) - gone.length),
        totals: this.meta.totals
          ? { ...this.meta.totals, hits: Math.max(0, this.meta.totals.hits - hits), paths: Math.max(0, this.meta.totals.paths - gone.length) }
          : undefined,
      };
    }
    this.#announce();
    return { restore, removed: gone.length };
  }

  /* ---------- actions ---------- */

  async resolve(row: NotFoundRow, resolved = true): Promise<void> {
    let restore: () => void;
    if (this.list.include_resolved) {
      // stays in the list, only its state flips
      const before = this.rows;
      this.rows = this.rows.map((r) => (r.path === row.path ? { ...r, resolved } : r));
      restore = () => (this.rows = before);
    } else if (resolved) {
      restore = this.#removeWhere((r) => r.path === row.path).restore;
    } else {
      restore = () => {};
    }
    try {
      await api.post('/redirects/404/resolve', { paths: [row.path], resolved });
    } catch (e) {
      restore();
      toast.error(describeError(e));
      return;
    }
    if (resolved) {
      toast.success(t('NOTFOUND.DONE_TOAST', { path: row.path }), {
        duration: 10000,
        action: { label: t('NOTFOUND.DONE_UNDO'), onClick: () => void this.resolve(row, false) },
      });
    } else {
      toast.success(t('NOTFOUND.REOPENED', { path: row.path }));
    }
    this.refresh();
    bump('stats');
  }

  async ignore(row: NotFoundRow): Promise<void> {
    const res = await dialogs.form({
      title: t('NOTFOUND.IGNORE_TITLE'),
      description: t('NOTFOUND.IGNORE_DESC'),
      submitLabel: t('NOTFOUND.IGNORE_SUBMIT'),
      fields: [{ name: 'pattern', type: 'text', label: t('NOTFOUND.IGNORE_FIELD'), value: row.path, required: true, help: t('NOTFOUND.IGNORE_HELP') }],
    });
    const pattern = String(res?.pattern ?? '').trim();
    if (!pattern) return;
    const { restore, removed } = this.#removeWhere((r) => globMatch(pattern, r.path));
    try {
      const { data } = await api.post<{ pattern: string; patterns: string[]; purged: number }>('/redirects/404/ignore', { pattern, purge: true });
      toast.success(t('NOTFOUND.IGNORED', { pattern, n: typeof data?.purged === 'number' ? data.purged : removed }));
    } catch (e) {
      restore();
      toast.error(describeError(e));
      return;
    }
    this.refresh();
    bump('stats');
  }

  async deleteEntries(row: NotFoundRow): Promise<void> {
    const ok = await dialogs.confirm({
      title: t('NOTFOUND.DELETE_TITLE'),
      message: t('NOTFOUND.DELETE_TEXT', { path: row.path }),
      confirmLabel: t('NOTFOUND.DELETE_CONFIRM'),
      variant: 'destructive',
    });
    if (!ok) return;
    const { restore } = this.#removeWhere((r) => r.path === row.path);
    try {
      await api.delete('/redirects/404', { query: { path: row.path } });
    } catch (e) {
      restore();
      toast.error(describeError(e));
      return;
    }
    toast.success(t('NOTFOUND.DELETED', { path: row.path }));
    this.refresh();
    bump('stats');
  }

  /* ---------- raw entries ---------- */

  async toggleEntries(path: string): Promise<void> {
    if (this.expanded[path]) {
      delete this.expanded[path];
      return;
    }
    this.expanded[path] = true;
    const prev = this.entries[path];
    this.entries[path] = { loading: true, error: null, rows: prev?.rows ?? [] };
    try {
      const { data } = await api.get<NotFoundEntry[]>('/redirects/404/entries', { path });
      this.entries[path] = { loading: false, error: null, rows: Array.isArray(data) ? data : [] };
    } catch (e) {
      this.entries[path] = { loading: false, error: e, rows: prev?.rows ?? [] };
    }
  }

  async retryEntries(path: string): Promise<void> {
    delete this.expanded[path];
    await this.toggleEntries(path);
  }

  /* ---------- create redirect ---------- */

  async createRedirect(row: NotFoundRow): Promise<void> {
    if (this.busy[row.path]) return;
    this.busy[row.path] = true;
    let candidates: SuggestionCandidate[] = [];
    try {
      const { data } = await api.get<SuggestionCandidate[]>('/redirects/suggest', { path: row.path, limit: 5 });
      if (Array.isArray(data)) candidates = data;
    } catch {
      /* the editor still opens, with the stored suggestion */
    } finally {
      delete this.busy[row.path];
    }
    if (candidates.length === 0 && row.best_suggestion) candidates = [row.best_suggestion];
    const target = row.best_suggestion?.target ?? candidates[0]?.target;
    openEditor({
      prefill: { source: row.path, match_type: 'exact', ...(target ? { target } : {}) },
      candidates,
      onsaved: () => {
        toast.success(t('NOTFOUND.CREATED', { path: row.path }));
        bump('rules', 'notFound', 'suggestions');
      },
    });
  }
}

export const notFound = new NotFoundStore();
