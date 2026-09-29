/**
 * Automatic redirects: deleted pages waiting for a decision (delete policy "ask") and the rules the plugin
 * created on its own that nobody has looked at yet. Both feed the sidebar badge of Admin 2.
 */
import { api, isAbort } from '../api';
import { describeError } from '../errors';
import { t } from '../i18n.svelte';
import { bump, can } from './app.svelte';
import { toast } from './notify.svelte';
import type { BadgeInfo, PendingAction, PendingDelete, PendingResolveResult, Rule } from '../types';

export const SIDEBAR_ID = 'redirect-manager';
/** How many unseen automatic rules the notice lists at most. */
export const UNSEEN_LIST_MAX = 8;

/** Tells Admin 2 to redraw the sidebar badge without another request. Admin 2 hides the pill only for a missing value, so 0 shows "0". */
export function pushSidebarBadge(count: number): void {
  if (typeof window === 'undefined') return;
  window.dispatchEvent(new CustomEvent('grav:sidebar:badge', { detail: { id: SIDEBAR_ID, count } }));
}

class AutoStore {
  pending = $state<PendingDelete[]>([]);
  /** unseen automatic rules (count from the server) */
  unseen = $state(0);
  /** the newest of them, for the notice */
  unseenRules = $state<Rule[]>([]);
  loaded = $state(false);
  error = $state<unknown>(null);
  /** ids of decisions being sent */
  busy = $state<Record<string, boolean>>({});
  seenBusy = $state(false);
  announcement = $state('');

  #abort: AbortController | null = null;

  get badgeCount(): number {
    return this.pending.length + this.unseen;
  }

  async load(): Promise<void> {
    this.#abort?.abort();
    const ctl = new AbortController();
    this.#abort = ctl;
    try {
      const [pending, badge] = await Promise.all([
        api.get<PendingDelete[]>('/redirects/pending', undefined, ctl.signal),
        api.get<BadgeInfo>('/redirects/badge', undefined, ctl.signal),
      ]);
      const unseen = badge.data?.unseen ?? 0;
      let rules: Rule[] = [];
      if (unseen > 0) {
        const res = await api.get<Rule[]>('/redirects/rules', { origin: 'auto', sort: 'created_at', dir: 'desc', per_page: Math.min(unseen, UNSEEN_LIST_MAX) }, ctl.signal);
        rules = Array.isArray(res.data) ? res.data : [];
      }
      this.pending = Array.isArray(pending.data) ? pending.data : [];
      this.unseen = unseen;
      this.unseenRules = rules;
      this.error = null;
      this.loaded = true;
    } catch (e) {
      if (isAbort(e)) return;
      // the panel is an extra: without the auto routes (older backend) the Rules tab works as before
      this.error = e;
      this.loaded = true;
    } finally {
      if (this.#abort === ctl) this.#abort = null;
    }
  }

  #syncBadge(): void {
    pushSidebarBadge(this.badgeCount);
  }

  async resolve(entry: PendingDelete, action: PendingAction, target?: string): Promise<boolean> {
    if (this.busy[entry.id]) return false;
    this.busy[entry.id] = true;
    try {
      const { data } = await api.post<PendingResolveResult>(`/redirects/pending/${encodeURIComponent(entry.id)}/resolve`, action === 'redirect' ? { action, target } : { action });
      this.pending = this.pending.filter((p) => p.id !== entry.id);
      const n = (data?.created?.length ?? 0) + (data?.updated?.length ?? 0);
      const msg = action === 'dismiss' ? t('AUTO.RESOLVED_DISMISS', { title: entry.title || entry.route }) : t('AUTO.RESOLVED', { title: entry.title || entry.route, n });
      toast.success(msg);
      this.announcement = msg;
      this.#syncBadge();
      if (action !== 'dismiss') bump('rules');
      void this.load().then(() => this.#syncBadge());
      return true;
    } catch (e) {
      const status = (e as { status?: number })?.status;
      if (status === 404) {
        // resolved somewhere else in the meantime
        this.pending = this.pending.filter((p) => p.id !== entry.id);
        toast.info(t('AUTO.GONE_ALREADY'));
        this.#syncBadge();
      } else {
        toast.error(describeError(e));
      }
      return false;
    } finally {
      delete this.busy[entry.id];
    }
  }

  async markSeen(): Promise<void> {
    // The seen state is shared across users; read-only users leave it for someone who can act on it.
    if (this.seenBusy || this.unseen === 0 || !can.manage) return;
    this.seenBusy = true;
    try {
      await api.post('/redirects/badge/seen');
      this.unseen = 0;
      this.unseenRules = [];
      this.announcement = t('AUTO.MARKED_SEEN');
      this.#syncBadge();
    } catch (e) {
      toast.error(describeError(e));
    } finally {
      this.seenBusy = false;
    }
  }
}

export const auto = new AutoStore();
