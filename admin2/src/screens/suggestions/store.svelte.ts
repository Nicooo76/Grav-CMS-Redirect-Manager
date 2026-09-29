/**
 * State and actions of the suggestions review. Accept and reject are optimistic:
 * the row leaves the list at once and returns with an error toast when the API refuses.
 */
import { api, isAbort } from '../../lib/api';
import { describeError } from '../../lib/errors';
import { locale, t } from '../../lib/i18n.svelte';
import { navigate } from '../../lib/router.svelte';
import { bump } from '../../lib/state/app.svelte';
import { toast } from '../../lib/state/notify.svelte';
import {
  DEFAULT_BULK_SCORE,
  DEFAULT_SUGGESTIONS,
  STATUSES,
  acceptBody,
  clampThreshold,
  countAtOrAbove,
  fromHashQuery,
  formatScore,
  stateKey,
  toApiQuery,
  toHashQuery,
  withEdit,
  type SuggestionStatus,
  type SuggestionsState,
} from '../../lib/suggestions';
import type { BulkAcceptPreview, Rule, StoredSuggestion, SuggestionsMeta } from '../../lib/types';

/** POST /redirects/suggestions/generate */
interface GenerateResult {
  paths: number;
  suggested: number;
  created: number;
  improved: number;
  no_suggestion: number;
  skipped_with_rule: number;
}

class SuggestionsStore {
  list = $state<SuggestionsState>({ ...DEFAULT_SUGGESTIONS });
  rows = $state<StoredSuggestion[]>([]);
  /** rows the server reports for the current query */
  total = $state(0);
  counts = $state<Record<SuggestionStatus, number>>({ open: 0, accepted: 0, rejected: 0 });
  loading = $state(false);
  loaded = $state(false);
  error = $state<unknown>(null);
  /** target edits made in the table, id -> target */
  edits = $state<Record<string, string>>({});
  generating = $state(false);
  threshold = $state(DEFAULT_BULK_SCORE);
  /** the user moved the slider: the server's default no longer replaces it */
  #thresholdTouched = false;
  bulkBusy = $state(false);
  preview = $state<BulkAcceptPreview | null>(null);
  previewBusy = $state(false);
  /** element that opened the preview, gets focus back when it closes */
  previewOpener: HTMLElement | null = null;
  announcement = $state('');

  #abort: AbortController | null = null;

  get atThreshold(): number {
    return countAtOrAbove(this.rows, this.threshold);
  }

  /* ---------- loading ---------- */

  async load(opts: { silent?: boolean } = {}): Promise<void> {
    this.#abort?.abort();
    const ctl = new AbortController();
    this.#abort = ctl;
    if (!opts.silent) this.loading = true;
    const status = this.list.status;
    try {
      const { data, meta } = await api.get<StoredSuggestion[]>('/redirects/suggestions', toApiQuery(this.list), ctl.signal);
      this.rows = Array.isArray(data) ? data : [];
      this.total = meta.total ?? this.rows.length;
      const m = meta as SuggestionsMeta;
      // the slider starts at suggestions.bulk_accept_score until the user moves it
      if (!this.#thresholdTouched && typeof m.bulk_accept_score === 'number') this.threshold = clampThreshold(m.bulk_accept_score);
      const counts = m.counts;
      if (counts) this.counts = { open: counts.open ?? 0, accepted: counts.accepted ?? 0, rejected: counts.rejected ?? 0 };
      this.error = null;
      this.loaded = true;
      const ids = new Set(this.rows.map((r) => r.id));
      for (const id of Object.keys(this.edits)) if (!ids.has(id)) delete this.edits[id];
      this.announcement = t('SUGGESTIONS.ANNOUNCE', { label: t(`SUGGESTIONS.STATUS_${status.toUpperCase()}`), n: this.total });
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

  refresh(): void {
    void this.load({ silent: true });
  }

  #syncUrl(): void {
    navigate({ name: 'suggestions', query: toHashQuery(this.list) }, { replace: true });
  }

  setList(patch: Partial<SuggestionsState>): void {
    const next: SuggestionsState = { ...this.list, ...patch };
    if (stateKey(next) === stateKey(this.list)) return;
    const reload = next.status !== this.list.status || next.min !== this.list.min;
    this.list = next;
    this.#syncUrl();
    if (reload) void this.load();
  }

  /** URL -> state (mount, back/forward). On mount (`first`) an already loaded list is refreshed. */
  applyRoute(query: Record<string, string>, first = false): void {
    const next = fromHashQuery(query);
    const changed = stateKey(next) !== stateKey(this.list);
    const reload = next.status !== this.list.status || next.min !== this.list.min;
    if (changed) this.list = next;
    if (reload || !this.loaded) void this.load();
    else if (first) this.refresh();
  }

  resetFilters(): void {
    this.setList({ min: 0, q: '' });
  }

  /* ---------- edits ---------- */

  edit(row: StoredSuggestion, value: string): void {
    this.edits = withEdit(this.edits, row, value);
  }

  /* ---------- optimistic removal ---------- */

  #remove(id: string, to: SuggestionStatus): () => void {
    const rows = this.rows;
    const counts = { ...this.counts };
    const total = this.total;
    this.rows = rows.filter((r) => r.id !== id);
    this.total = Math.max(0, total - 1);
    this.counts = { ...counts, [this.list.status]: Math.max(0, counts[this.list.status] - 1), [to]: counts[to] + 1 };
    return () => {
      this.rows = rows;
      this.total = total;
      this.counts = counts;
    };
  }

  async accept(row: StoredSuggestion): Promise<void> {
    const target = this.edits[row.id] ?? row.target;
    const body = acceptBody(row, this.edits);
    const restore = this.#remove(row.id, 'accepted');
    try {
      const { data } = await api.post<{ suggestion?: StoredSuggestion; rule?: Rule }>(`/redirects/suggestions/${encodeURIComponent(row.id)}/accept`, body);
      delete this.edits[row.id];
      const rule = data?.rule;
      const message = t('SUGGESTIONS.ACCEPTED', { path: row.path, target });
      if (rule?.id) {
        toast.success(message, {
          duration: 10000,
          action: { label: t('SUGGESTIONS.OPEN_RULE'), onClick: () => navigate({ name: 'rule-edit', id: rule.id }) },
        });
      } else {
        toast.success(message);
      }
    } catch (e) {
      restore();
      toast.error(describeError(e));
      return;
    }
    bump('rules', 'suggestions', 'notFound');
  }

  async reject(row: StoredSuggestion): Promise<void> {
    const restore = this.#remove(row.id, 'rejected');
    try {
      await api.post(`/redirects/suggestions/${encodeURIComponent(row.id)}/reject`);
    } catch (e) {
      restore();
      toast.error(describeError(e));
      return;
    }
    toast.success(t('SUGGESTIONS.REJECTED', { path: row.path }));
    bump('suggestions');
  }

  /* ---------- generate ---------- */

  async generate(): Promise<void> {
    if (this.generating) return;
    this.generating = true;
    try {
      const { data } = await api.post<GenerateResult>('/redirects/suggestions/generate');
      const msg = t('SUGGESTIONS.GENERATED', { created: data?.created ?? 0, improved: data?.improved ?? 0, none: data?.no_suggestion ?? 0 });
      toast.success(msg);
      this.announcement = msg;
    } catch (e) {
      toast.error(describeError(e));
      return;
    } finally {
      this.generating = false;
    }
    bump('suggestions');
  }

  /* ---------- bulk accept ---------- */

  setThreshold(v: number): void {
    this.#thresholdTouched = true;
    this.threshold = clampThreshold(v);
  }

  /** Dry run: fetches what would be created and opens the preview. */
  async openPreview(opener: HTMLElement | null = null): Promise<void> {
    if (this.bulkBusy) return;
    this.previewOpener = opener;
    this.bulkBusy = true;
    try {
      const { data } = await api.post<BulkAcceptPreview>('/redirects/suggestions/bulk-accept', { min_score: this.threshold, dry_run: true });
      const rows = Array.isArray(data?.rows) ? data.rows : [];
      if (rows.length === 0) {
        toast.info(t('SUGGESTIONS.BULK_NONE', { score: formatScore(this.threshold, locale()) }));
        return;
      }
      this.preview = { min_score: data.min_score ?? this.threshold, count: data.count ?? rows.length, rows };
    } catch (e) {
      toast.error(describeError(e));
    } finally {
      this.bulkBusy = false;
    }
  }

  closePreview(): void {
    if (!this.previewBusy) this.preview = null;
  }

  async commitPreview(): Promise<void> {
    const p = this.preview;
    if (!p || this.previewBusy) return;
    this.previewBusy = true;
    try {
      const { data } = await api.post<{ count: number; rules?: Rule[]; skipped?: { id: string }[] }>('/redirects/suggestions/bulk-accept', {
        min_score: p.min_score,
        dry_run: false,
        ids: p.rows.map((r) => r.id),
      });
      const skipped = data?.skipped?.length ?? 0;
      toast.success(t('SUGGESTIONS.BULK_DONE', { n: data?.count ?? p.rows.length }));
      if (skipped > 0) toast.warning(t('SUGGESTIONS.BULK_SKIPPED', { n: skipped }));
      this.preview = null;
    } catch (e) {
      toast.error(describeError(e));
      return;
    } finally {
      this.previewBusy = false;
    }
    bump('rules', 'suggestions', 'notFound');
  }
}

export const suggestions = new SuggestionsStore();
