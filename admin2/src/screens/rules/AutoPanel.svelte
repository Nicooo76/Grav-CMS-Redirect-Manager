<script lang="ts">
  /**
   * Top of the Rules tab: deleted pages that wait for a decision, and the automatic rules nobody has seen yet.
   * Both parts stay quiet: they appear only when there is something to decide or to look at.
   */
  import { onMount } from 'svelte';
  import { FileX2, Sparkles, CornerLeftUp, Ban, ArrowRight, Check } from 'lucide-svelte';
  import Button from '../../lib/ui/Button.svelte';
  import PagePicker from '../../lib/ui/PagePicker.svelte';
  import { t, locale } from '../../lib/i18n.svelte';
  import { formatRelative } from '../../lib/format';
  import { hrefFor, linkClick } from '../../lib/router.svelte';
  import { auto, UNSEEN_LIST_MAX } from '../../lib/state/auto.svelte';
  import { can } from '../../lib/state/app.svelte';
  import type { PendingDelete } from '../../lib/types';

  /** the notice counts as viewed after it was on screen this long (ms) */
  const SEEN_AFTER = 4000;

  let picking = $state<string | null>(null);
  let target = $state('');
  let shownAt = 0;

  onMount(() => {
    void auto.load();
    return () => {
      // leaving the tab after the notice was visible for a while counts as having looked at it
      if (shownAt && Date.now() - shownAt >= SEEN_AFTER) void auto.markSeen();
    };
  });

  // start the clock when the unseen notice appears
  $effect(() => {
    if (auto.unseen > 0 && auto.unseenRules.length > 0) shownAt ||= Date.now();
    else shownAt = 0;
  });

  const validTarget = $derived(/^\/(?!\/)\S*$/.test(target.trim()) || /^https?:\/\/\S+$/i.test(target.trim()));

  function startRedirect(p: PendingDelete) {
    picking = picking === p.id ? null : p.id;
    target = p.suggested_parent ?? '';
  }
  async function confirmRedirect(p: PendingDelete) {
    if (!validTarget) return;
    if (await auto.resolve(p, 'redirect', target.trim())) picking = null;
  }
  function parentLabel(p: PendingDelete): string {
    return p.suggested_parent ?? t('AUTO.PARENT_HOME');
  }
  const moreUnseen = $derived(Math.max(0, auto.unseen - auto.unseenRules.length));
</script>

{#if auto.pending.length > 0}
  <section class="card auto" aria-labelledby="rm-pending-h" data-testid="pending-panel">
    <div class="card-h">
      <span class="ico" aria-hidden="true"><FileX2 size={16} /></span>
      <div class="grow">
        <h2 id="rm-pending-h">{t('AUTO.PENDING_TITLE', { n: auto.pending.length })}</h2>
        <p class="text-xs muted">{t('AUTO.PENDING_TEXT')}</p>
      </div>
    </div>
    <ul class="items">
      {#each auto.pending as p (p.id)}
        {@const busy = !!auto.busy[p.id]}
        <li class="item">
          <div class="info">
            <div class="row wrap" style="gap:0.5rem">
              {#if p.title}<span class="ttl">{p.title}</span>{/if}
              <span class="mono muted route">{p.route}</span>
              {#each p.languages as l (l)}<span class="badge muted">{l}</span>{/each}
            </div>
            <div class="text-xs muted row wrap" style="gap:0.25rem 0.75rem">
              <span>{t('AUTO.DELETED_AT', { when: formatRelative(p.deleted_at, locale()) })}</span>
              {#if p.children_count > 0}
                <details class="kids">
                  <summary>{t('AUTO.CHILDREN', { n: p.children_count })}</summary>
                  <ul class="mono">
                    {#each p.children as c (c)}<li>{c}</li>{/each}
                    {#if p.children_count > p.children.length}<li class="muted">{t('AUTO.UNSEEN_MORE', { n: p.children_count - p.children.length })}</li>{/if}
                  </ul>
                </details>
              {/if}
            </div>
          </div>
          <div class="actions" role="group" aria-label={t('AUTO.ACTIONS', { title: p.title || p.route })}>
            <Button disabled={busy || !can.manage} title={t('AUTO.ACT_GONE_HELP')} onclick={() => auto.resolve(p, 'gone')}><Ban size={14} />{t('AUTO.ACT_GONE')}</Button>
            <Button disabled={busy || !can.manage} title={t('AUTO.ACT_PARENT_HELP', { parent: parentLabel(p) })} onclick={() => auto.resolve(p, 'parent')}><CornerLeftUp size={14} />{t('AUTO.ACT_PARENT')}</Button>
            <Button disabled={busy || !can.manage} aria-expanded={picking === p.id} title={t('AUTO.ACT_REDIRECT_HELP')} onclick={() => startRedirect(p)}><ArrowRight size={14} />{t('AUTO.ACT_REDIRECT')}</Button>
            <Button variant="ghost" disabled={busy || !can.manage} title={t('AUTO.ACT_DISMISS_HELP')} onclick={() => auto.resolve(p, 'dismiss')}>{t('AUTO.ACT_DISMISS')}</Button>
          </div>
          {#if picking === p.id}
            <form
              class="picker"
              onsubmit={(e) => {
                e.preventDefault();
                void confirmRedirect(p);
              }}
            >
              <label class="text-xs muted" for="rm-pick-{p.id}">{t('AUTO.REDIRECT_TARGET')}</label>
              <div class="row" style="gap:0.5rem;align-items:flex-start">
                <div class="grow"><PagePicker id="rm-pick-{p.id}" bind:value={target} describedBy="rm-pick-h-{p.id}" invalid={target.trim() !== '' && !validTarget} /></div>
                <Button type="submit" variant="primary" disabled={busy || !validTarget} loading={busy}>{t('AUTO.REDIRECT_CONFIRM')}</Button>
                <Button variant="ghost" onclick={() => (picking = null)}>{t('COMMON.CANCEL')}</Button>
              </div>
              <p id="rm-pick-h-{p.id}" class="text-xs muted">{t('AUTO.REDIRECT_TARGET_HINT')}</p>
            </form>
          {/if}
        </li>
      {/each}
    </ul>
  </section>
{/if}

{#if auto.unseen > 0 && auto.unseenRules.length > 0}
  <section class="card auto" aria-labelledby="rm-unseen-h" data-testid="unseen-panel">
    <div class="card-h">
      <span class="ico" aria-hidden="true"><Sparkles size={16} /></span>
      <div class="grow">
        <h2 id="rm-unseen-h">{t('AUTO.UNSEEN_TITLE', { n: auto.unseen })}</h2>
        <p class="text-xs muted">{t('AUTO.UNSEEN_TEXT')}</p>
      </div>
      <Button loading={auto.seenBusy} onclick={() => auto.markSeen()}><Check size={14} />{t('AUTO.MARK_SEEN')}</Button>
    </div>
    <ul class="items">
      {#each auto.unseenRules.slice(0, UNSEEN_LIST_MAX) as r (r.id)}
        <li class="item rule">
          <a
            class="mono truncate"
            href={hrefFor({ name: 'rule-edit', id: r.id })}
            onclick={(e) => {
              linkClick(e);
              // opening one of them means the editor has looked at the new rules
              void auto.markSeen();
            }}
            title={r.source}>{r.source}</a
          >
          <span class="muted" aria-hidden="true">→</span>
          <span class="mono truncate target">{r.status === 410 || r.status === 451 ? t(`STATUS.${r.status}`) : r.target}</span>
          <span class="badge muted">{r.status}</span>
          <span class="text-xs muted nowrap when">{formatRelative(r.created_at, locale())}</span>
        </li>
      {/each}
      {#if moreUnseen > 0}<li class="item text-xs muted">{t('AUTO.UNSEEN_MORE', { n: moreUnseen })}</li>{/if}
    </ul>
  </section>
{/if}

<div class="sr-only" role="status" aria-live="polite">{auto.announcement}</div>

<style>
  .auto {
    border-inline-start: 3px solid color-mix(in srgb, var(--rm-info-fg) 55%, transparent);
    overflow: hidden;
  }
  .ico {
    display: grid;
    place-items: center;
    inline-size: 1.75rem;
    block-size: 1.75rem;
    border-radius: var(--rm-r-md);
    background: color-mix(in srgb, var(--rm-info-fg) 12%, transparent);
    color: var(--rm-info-fg);
    flex-shrink: 0;
  }
  .card-h {
    padding-block-end: 0.5rem !important;
  }
  .items {
    list-style: none;
    margin: 0;
    padding: 0;
  }
  .item {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem 1rem;
    padding: 0.625rem 1rem;
    border-block-start: 1px solid var(--border);
  }
  .info {
    flex: 1 1 18rem;
    min-inline-size: 0;
    display: flex;
    flex-direction: column;
    gap: 0.125rem;
  }
  .ttl {
    font-weight: 600;
  }
  .route {
    font-size: var(--rm-text-xs);
  }
  .actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.375rem;
  }
  .picker {
    flex-basis: 100%;
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    padding-block-start: 0.25rem;
  }
  .picker p {
    margin: 0;
  }
  .kids summary {
    cursor: pointer;
  }
  .kids ul {
    margin: 0.25rem 0 0;
    padding-inline-start: 1rem;
    max-block-size: 8rem;
    overflow: auto;
  }
  .rule {
    flex-wrap: nowrap;
  }
  .rule a {
    flex: 0 1 22rem;
    color: var(--foreground);
  }
  .rule .when {
    min-inline-size: 6.5rem;
    text-align: end;
  }
  .rule .target {
    flex: 1 1 0;
    min-inline-size: 0;
  }
</style>
