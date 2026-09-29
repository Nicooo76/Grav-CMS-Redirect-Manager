/**
 * State and actions of the rules list. Every mutation is optimistic and rolls
 * back with an error toast when the API refuses it.
 */
import { api, isAbort } from '../api';
import { describeError } from '../errors';
import { t } from '../i18n.svelte';
import { navigate } from '../router.svelte';
import { UndoBuffer } from '../undo';
import { loadColumns, saveColumns, type ColumnId } from '../columns';
import {
  DEFAULT_LIST,
  fromHashQuery,
  listKey,
  moveItem,
  toApiQuery,
  toHashQuery,
  type RulesListState,
} from '../rules-query';
import type { BulkResult, GroupsResult, Rule, RulesMeta } from '../types';
import { bump } from './app.svelte';
import { toast } from './notify.svelte';

const undo = new UndoBuffer<Rule>(10_000);

/** Last list query written to the URL, so closing the editor returns to the same view. */
let lastQuery: Record<string, string> = {};
export const getLastRulesQuery = (): Record<string, string> => lastQuery;

class RulesStore {
  list = $state<RulesListState>({ ...DEFAULT_LIST });
  rows = $state<Rule[]>([]);
  meta = $state<RulesMeta | null>(null);
  loading = $state(false);
  loaded = $state(false);
  error = $state<unknown>(null);
  selected = $state<Record<string, true>>({});
  columns = $state<Record<ColumnId, boolean>>(loadColumns());
  groups = $state<string[]>([]);
  reordering = $state(false);
  /** text for the aria-live region */
  announcement = $state('');
  /** enables badge entrance animations after the first paint */
  animate = $state(false);

  #abort: AbortController | null = null;
  #refreshTimer: ReturnType<typeof setTimeout> | undefined;

  get selectedIds(): string[] {
    return Object.keys(this.selected);
  }
  get selectedCount(): number {
    return this.selectedIds.length;
  }
  get selectedRules(): Rule[] {
    return this.rows.filter((r) => this.selected[r.id]);
  }
  get total(): number {
    return this.meta?.total ?? this.rows.length;
  }

  /* ---------- loading ---------- */

  async load(opts: { silent?: boolean } = {}): Promise<void> {
    this.#abort?.abort();
    const ctl = new AbortController();
    this.#abort = ctl;
    if (!opts.silent) this.loading = true;
    try {
      const { data, meta } = await api.get<Rule[]>('/redirects/rules', toApiQuery(this.list), ctl.signal);
      const m = meta as RulesMeta;
      // paged past the end (after deleting the last row of a page): step back
      if (data.length === 0 && (m.total ?? 0) > 0 && this.list.page > 1) {
        this.list.page = Math.max(1, Math.ceil(m.total / Math.max(1, m.per_page || this.list.per_page)));
        this.#syncUrl();
        return this.load(opts);
      }
      this.rows = data;
      this.meta = m;
      if (Array.isArray(m.groups)) this.groups = m.groups.map((g: any) => (typeof g === 'string' ? g : g.name));
      this.error = null;
      const ids = new Set(data.map((r) => r.id));
      for (const id of Object.keys(this.selected)) if (!ids.has(id)) delete this.selected[id];
      this.loaded = true;
      if (!this.animate) queueMicrotask(() => (this.animate = true));
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

  /** Refreshes counts and total without replacing the rows (after own mutations). */
  refreshCounts(): void {
    clearTimeout(this.#refreshTimer);
    this.#refreshTimer = setTimeout(async () => {
      try {
        const { meta } = await api.get<Rule[]>('/redirects/rules', { ...toApiQuery(this.list), page: 1, per_page: 1 });
        if (this.meta) this.meta = { ...this.meta, total: (meta as RulesMeta).total, counts: (meta as RulesMeta).counts };
      } catch {
        /* counts are decoration */
      }
    }, 500);
  }

  #syncUrl(): void {
    lastQuery = toHashQuery(this.list);
    navigate({ name: 'rules', query: lastQuery }, { replace: true });
  }

  /** Change list state (filters, sort, page); resets to page 1 unless `page` is part of the patch. */
  setList(patch: Partial<RulesListState>): void {
    const next: RulesListState = { ...this.list, ...patch };
    if (!('page' in patch)) next.page = 1;
    if (listKey(next) === listKey(this.list)) return;
    this.list = next;
    this.#syncUrl();
    this.load();
  }

  /** URL -> state (initial mount, back/forward). Returns true when the state changed. */
  applyRoute(query: Record<string, string>): boolean {
    const next = fromHashQuery(query);
    if (listKey(next) === listKey(this.list) && this.loaded) return false;
    const changed = listKey(next) !== listKey(this.list);
    this.list = next;
    lastQuery = toHashQuery(next);
    if (changed || !this.loaded) this.load();
    return changed;
  }

  resetFilters(): void {
    this.setList({ q: '', match_type: '', state: '', status: '', group: '', origin: '', unused_days: '', badge: '' });
  }

  /* ---------- selection ---------- */

  toggle(id: string, on?: boolean): void {
    const next = on ?? !this.selected[id];
    if (next) this.selected[id] = true;
    else delete this.selected[id];
  }
  toggleRange(fromIndex: number, toIndex: number, on: boolean): void {
    const [a, b] = fromIndex < toIndex ? [fromIndex, toIndex] : [toIndex, fromIndex];
    for (let i = a; i <= b; i++) this.toggle(this.rows[i].id, on);
  }
  toggleAll(on: boolean): void {
    if (on) for (const r of this.rows) this.selected[r.id] = true;
    else this.clearSelection();
  }
  clearSelection(): void {
    for (const id of Object.keys(this.selected)) delete this.selected[id];
  }

  /* ---------- columns ---------- */

  setColumn(id: ColumnId, on: boolean): void {
    this.columns[id] = on;
    saveColumns({ ...this.columns });
  }

  /* ---------- mutations ---------- */

  #replace(id: string, rule: Rule): void {
    const i = this.rows.findIndex((r) => r.id === id);
    if (i !== -1) this.rows[i] = rule;
  }

  #afterMutation(): void {
    bump('stats');
    this.refreshCounts();
  }

  /** Optimistic PATCH of one rule. Resolves true on success. */
  async update(id: string, patch: Partial<Rule>): Promise<boolean> {
    const i = this.rows.findIndex((r) => r.id === id);
    if (i === -1) return false;
    const prev = this.rows[i];
    const guess: Rule = { ...prev, ...patch };
    if ('enabled' in patch) {
      const rest = (prev.badges ?? []).filter((b) => b !== 'active' && b !== 'disabled');
      guess.badges = patch.enabled ? ['active', ...rest] : ['disabled', ...rest];
    }
    this.rows[i] = guess;
    try {
      const { data } = await api.patch<Rule>(`/redirects/rules/${encodeURIComponent(id)}`, patch);
      this.#replace(id, data);
      this.#afterMutation();
      return true;
    } catch (e) {
      this.#replace(id, prev);
      toast.error(describeError(e));
      return false;
    }
  }

  async remove(ids: string[]): Promise<void> {
    if (!ids.length) return;
    const snapshot = this.rows;
    const prevTotal = this.meta?.total;
    const gone = snapshot.filter((r) => ids.includes(r.id));
    this.rows = snapshot.filter((r) => !ids.includes(r.id));
    if (this.meta && prevTotal !== undefined) this.meta = { ...this.meta, total: Math.max(0, prevTotal - ids.length) };
    for (const id of ids) delete this.selected[id];
    try {
      let deleted: Rule[];
      if (ids.length === 1) {
        const { data } = await api.delete<Rule>(`/redirects/rules/${encodeURIComponent(ids[0])}`);
        deleted = data && (data as Rule).id ? [data] : gone;
      } else {
        const { data } = await api.post<BulkResult>('/redirects/rules/bulk', { action: 'delete', ids });
        deleted = data?.rules?.length ? data.rules : gone;
      }
      const entry = undo.push(deleted);
      toast.success(t('RULES.DELETED', { n: deleted.length }), {
        duration: 10_000,
        action: { label: t('COMMON.UNDO'), onClick: () => void this.undo(entry.id) },
      });
      this.announcement = t('RULES.DELETED', { n: deleted.length });
      this.#afterMutation();
      if (this.rows.length === 0) this.load({ silent: true });
    } catch (e) {
      this.rows = snapshot;
      if (this.meta && prevTotal !== undefined) this.meta = { ...this.meta, total: prevTotal };
      toast.error(describeError(e));
    }
  }

  async undo(entryId?: number): Promise<void> {
    const entry = entryId === undefined ? undo.latest() : undo.take(entryId);
    if (!entry) return;
    if (entryId === undefined) undo.take(entry.id);
    try {
      await api.post('/redirects/rules/restore', { rules: entry.items });
      toast.success(t('RULES.RESTORED', { n: entry.items.length }));
      this.announcement = t('RULES.RESTORED', { n: entry.items.length });
      bump('rules');
    } catch (e) {
      toast.error(describeError(e));
    }
  }

  get canUndo(): boolean {
    return undo.size > 0;
  }

  async bulk(action: 'enable' | 'disable' | 'set_status' | 'set_group' | 'add_tag' | 'remove_tag', value?: string | number): Promise<void> {
    const ids = this.selectedIds;
    if (!ids.length) return;
    const snapshot = this.rows.map((r) => r);
    // optimistic local effect for the visible rows
    this.rows = this.rows.map((r) => {
      if (!this.selected[r.id]) return r;
      const c: Rule = { ...r };
      if (action === 'enable' || action === 'disable') {
        c.enabled = action === 'enable';
        const rest = (r.badges ?? []).filter((b) => b !== 'active' && b !== 'disabled');
        c.badges = [action === 'enable' ? 'active' : 'disabled', ...rest];
      } else if (action === 'set_status') c.status = Number(value) as Rule['status'];
      else if (action === 'set_group') c.group = String(value ?? '');
      else if (action === 'add_tag' && value) c.tags = [...new Set([...r.tags, String(value)])];
      else if (action === 'remove_tag') c.tags = r.tags.filter((tag) => tag !== value);
      return c;
    });
    try {
      const { data } = await api.post<BulkResult>('/redirects/rules/bulk', { action, ids, ...(value !== undefined ? { value } : {}) });
      toast.success(t('RULES.BULK_DONE', { n: data?.affected ?? ids.length }));
      this.announcement = t('RULES.BULK_DONE', { n: data?.affected ?? ids.length });
      this.#afterMutation();
      this.load({ silent: true });
      if (action === 'set_group') this.#refreshGroups();
    } catch (e) {
      this.rows = snapshot;
      toast.error(describeError(e));
    }
  }

  async #refreshGroups(): Promise<void> {
    try {
      const { data } = await api.get<GroupsResult>('/redirects/groups');
      this.groups = data.groups.map((g) => g.name);
    } catch {
      /* optional */
    }
  }

  /** Move the row at `from` to `to` inside the current page and persist the order. */
  async reorder(from: number, to: number): Promise<void> {
    if (this.reordering || from === to) return;
    const prev = this.rows;
    const next = moveItem(prev, from, to);
    if (next.every((r, i) => r === prev[i])) return;
    this.rows = next;
    this.reordering = true;
    const moved = next[Math.max(0, Math.min(next.length - 1, to))];
    try {
      await api.post('/redirects/rules/reorder', { ids: next.map((r) => r.id) });
      this.announcement = t('RULES.MOVED_TO', { position: Math.max(0, Math.min(next.length - 1, to)) + 1 + (this.list.page - 1) * this.list.per_page });
      void moved;
      bump('stats');
      this.load({ silent: true });
    } catch (e) {
      this.rows = prev;
      toast.error(describeError(e));
    } finally {
      this.reordering = false;
    }
  }

  async shortenChain(id: string): Promise<void> {
    try {
      const { data } = await api.post<Rule>(`/redirects/rules/${encodeURIComponent(id)}/shorten-chain`);
      if (data && (data as Rule).id) this.#replace(id, data);
      toast.success(t('RULES.CHAIN_SHORTENED'));
      this.#afterMutation();
      this.load({ silent: true });
    } catch (e) {
      toast.error(describeError(e));
    }
  }
}

export const rules = new RulesStore();
