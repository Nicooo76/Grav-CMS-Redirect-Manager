<script lang="ts">
  import { onMount, untrack } from 'svelte';
  import { Check } from 'lucide-svelte';
  import { api, isAbort } from '../lib/api';
  import { t, locale } from '../lib/i18n.svelte';
  import { formatNumber } from '../lib/format';
  import ErrorState from '../lib/ui/ErrorState.svelte';
  import type { Stats } from '../lib/types';
  import {
    EMPTY_HASH,
    STALE_MS,
    TILE_HASH,
    adminUrl,
    dailyAverage,
    endpointFor,
    isEmptyStats,
    layoutFor,
    normalizeStats,
    roundAverage,
    seriesOf,
    sparkPaths,
    tilesFor,
    type TileId,
  } from '../lib/widget';

  let { host }: { host: HTMLElement } = $props();

  let size = $state(untrack(() => host.getAttribute('size')));
  let stats = $state<Stats | null>(null);
  let error = $state<unknown>(null);
  let lastLoad = 0;
  let ctl: AbortController | null = null;

  const layout = $derived(layoutFor(size));
  const nf = (n: number) => formatNumber(n, locale());

  async function load(silent = false): Promise<void> {
    ctl?.abort();
    const c = (ctl = new AbortController());
    if (!silent || !stats) error = null;
    try {
      const res = await api.get<unknown>(endpointFor(host.getAttribute('data-endpoint')), undefined, c.signal);
      if (c.signal.aborted) return;
      stats = normalizeStats(res.data);
      error = null;
      lastLoad = Date.now();
    } catch (e) {
      if (isAbort(e) || c.signal.aborted) return;
      // a failed background refresh keeps the numbers that are already on screen
      if (!silent || !stats) error = e;
    }
  }

  function retry(): void {
    stats = null;
    void load();
  }

  onMount(() => {
    void load();
    const mo = new MutationObserver((records) => {
      for (const r of records) {
        if (r.attributeName === 'size') size = host.getAttribute('size');
        else if (r.attributeName === 'data-endpoint') retry();
      }
    });
    mo.observe(host, { attributes: true, attributeFilter: ['size', 'data-endpoint'] });
    const onVisible = () => {
      if (document.visibilityState === 'visible' && Date.now() - lastLoad > STALE_MS) void load(true);
    };
    document.addEventListener('visibilitychange', onVisible);
    return () => {
      mo.disconnect();
      document.removeEventListener('visibilitychange', onVisible);
      ctl?.abort();
    };
  });

  function go(e: MouseEvent, hash: string): void {
    const nav = window.__GRAV_NAVIGATE;
    if (!nav || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    e.preventDefault();
    nav(adminUrl(hash));
  }

  interface Tile {
    id: TileId;
    hash: string;
    value: number;
    none: boolean;
    bad: boolean;
    label: string;
    aria: string;
    today: number | null;
    avg: number | null;
    series: number[];
    color: string;
  }

  function tile(id: TileId, s: Stats): Tile {
    const hash = TILE_HASH[id];
    switch (id) {
      case 'notfound':
        return {
          id, hash, value: s.not_found_7d, none: false, bad: false,
          label: t('WIDGET.NOT_FOUND_LABEL'), aria: `${t('WIDGET.NOT_FOUND_ARIA', { n: s.not_found_7d })}`,
          today: s.not_found_today, avg: dailyAverage(s.not_found_7d), series: seriesOf(s.not_found_by_day), color: 'var(--muted-foreground)',
        };
      case 'hits':
        return {
          id, hash, value: s.hits_7d, none: false, bad: false,
          label: t('WIDGET.HITS_LABEL'), aria: t('WIDGET.HITS_ARIA', { n: s.hits_7d }),
          today: s.hits_today, avg: dailyAverage(s.hits_7d), series: seriesOf(s.hits_by_day), color: 'var(--primary)',
        };
      case 'suggestions':
        return {
          id, hash, value: s.open_suggestions, none: s.open_suggestions === 0, bad: false,
          label: t('WIDGET.SUGGESTIONS_LABEL'), aria: t('WIDGET.SUGGESTIONS_ARIA', { n: s.open_suggestions }),
          today: null, avg: null, series: [], color: '',
        };
      case 'dead':
        return {
          id, hash, value: s.dead_targets, none: s.dead_targets === 0, bad: s.dead_targets > 0,
          label: t('WIDGET.DEAD_LABEL'), aria: t('WIDGET.DEAD_ARIA', { n: s.dead_targets }),
          today: null, avg: null, series: [], color: '',
        };
    }
  }

  const tiles = $derived(stats ? tilesFor(layout, stats).map((id) => tile(id, stats!)) : []);
  const skeletonCount = $derived(layout === 'sm' ? 2 : 4);
  const empty = $derived(stats !== null && isEmptyStats(stats));
  const withSpark = $derived(layout !== 'sm');
</script>

{#snippet spark(values: number[], color: string)}
  {@const p = sparkPaths(values, 100, 24)}
  <svg class="spark" viewBox="0 0 100 24" preserveAspectRatio="none" aria-hidden="true" focusable="false">
    {#if p.flat}
      <line x1="1.5" y1="22.5" x2="98.5" y2="22.5" stroke="var(--border)" stroke-width="1" stroke-dasharray="2 3" vector-effect="non-scaling-stroke" />
    {:else}
      <path d={p.area} fill={color} opacity="0.12" />
      <path d={p.line} fill="none" stroke={color} stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
    {/if}
  </svg>
{/snippet}

<section class="card wg {layout}" aria-label={t('WIDGET.TITLE')} aria-busy={stats === null && error === null}>
  {#if layout === 'sm'}
    <h2 class="sr-only">{t('WIDGET.TITLE')}</h2>
  {:else}
    <div class="card-h">
      <h2>{t('WIDGET.TITLE')}</h2>
      {#if layout === 'lg' && stats && !empty}
        <span class="grow"></span>
        <a class="sum" href={adminUrl('/rules')} onclick={(e) => go(e, '/rules')}>{t('WIDGET.SUMMARY', { total: stats.rules_total, active: stats.rules_active })}</a>
      {/if}
    </div>
  {/if}

  {#if error}
    <div class="body"><ErrorState {error} compact onretry={retry} /></div>
  {:else if stats === null}
    <div class="body tiles" aria-hidden="true">
      {#each Array(skeletonCount) as _, i (i)}
        <div class="tile">
          <span class="top"><span class="val"><span class="skeleton sk-val"></span></span></span>
          <span class="lbl"><span class="skeleton sk-lbl"></span></span>
          {#if withSpark && i < 2}<span class="spark-wrap"><span class="skeleton sk-spark"></span></span>{/if}
        </div>
      {/each}
    </div>
  {:else if empty}
    <div class="body">
      <div class="empty">
        <h3>{t('WIDGET.EMPTY_TITLE')}</h3>
        <p>{t('WIDGET.EMPTY_TEXT')}</p>
        <a class="btn outline sm" href={adminUrl(EMPTY_HASH)} onclick={(e) => go(e, EMPTY_HASH)}>{t('WIDGET.EMPTY_CTA')}</a>
      </div>
    </div>
  {:else}
    <div class="body tiles">
      {#each tiles as tl (tl.id)}
        <a class="tile" class:bad={tl.bad} href={adminUrl(tl.hash)} aria-label={tl.aria} onclick={(e) => go(e, tl.hash)}>
          <span class="top">
            <span class="val" class:none={tl.none}>
              {#if tl.none}
                <Check size={18} aria-hidden="true" class="ok-ico" /><span class="none-txt">{t('COMMON.NONE')}</span>
              {:else}
                {nf(tl.value)}
              {/if}
            </span>
            {#if tl.today !== null && layout !== 'sm'}
              <span class="sub">{t('WIDGET.TODAY', { n: tl.today })}{#if layout === 'lg' && tl.avg !== null}<span class="avg">{' · '}{t('WIDGET.AVG', { n: nf(roundAverage(tl.avg)) })}</span>{/if}</span>
            {/if}
          </span>
          <span class="lbl">{tl.label}</span>
          {#if withSpark && tl.series.length}
            <span class="spark-wrap">{@render spark(tl.series, tl.color)}</span>
          {/if}
        </a>
      {/each}
    </div>
  {/if}
</section>

<style>
  .wg {
    block-size: 100%;
    display: flex;
    flex-direction: column;
    box-sizing: border-box;
    min-inline-size: 0;
  }
  .wg.sm {
    overflow: hidden;
  }
  .card-h {
    flex: none;
  }
  .sum {
    font-size: var(--rm-text-xs);
    color: var(--muted-foreground);
    text-decoration: none;
    border-radius: var(--rm-r-sm);
  }
  .sum:hover {
    color: var(--foreground);
    text-decoration: underline;
  }
  .body {
    flex: 1;
    min-block-size: 0;
    padding: 0 1rem 1rem;
    display: flex;
    flex-direction: column;
  }
  .sm .body {
    padding: 0;
  }
  .sm .body:has(.empty),
  .sm .body:has(:global([role='alert'])) {
    padding: 0.5rem;
  }
  .body > :global(.empty) {
    flex: 1;
    justify-content: center;
    padding: 1.25rem 1rem;
  }

  .tiles {
    display: grid;
    gap: 0.75rem;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    align-content: stretch;
  }
  .lg .tiles {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
  .sm .tiles {
    gap: 0;
  }
  @container (max-inline-size: 56rem) {
    .lg .tiles {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }
  @container (max-inline-size: 30rem) {
    .avg {
      display: none;
    }
  }

  .tile {
    display: flex;
    flex-direction: column;
    gap: 0.125rem;
    min-inline-size: 0;
    padding: 0.75rem;
    border: 1px solid var(--border);
    border-radius: var(--rm-r-lg);
    color: var(--foreground);
    text-decoration: none;
    background: transparent;
  }
  a.tile:hover {
    border-color: color-mix(in srgb, var(--primary) 30%, var(--border));
    box-shadow: var(--rm-shadow-sm);
  }
  .sm .tile {
    border: 0;
    border-radius: 0;
    padding: 1rem;
  }
  .sm .tile {
    justify-content: center;
  }
  .sm .tile + .tile {
    border-inline-start: 1px solid var(--border);
  }
  .sm a.tile:hover {
    background: color-mix(in srgb, var(--muted) 50%, transparent);
    box-shadow: none;
  }
  .sm a.tile:focus-visible {
    outline-offset: -2px;
  }

  .top {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 0 0.5rem;
    min-block-size: 2.25rem;
  }
  .val {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    font-size: 1.875rem;
    line-height: 2.25rem;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    letter-spacing: -0.01em;
  }
  .tile.bad .val {
    color: var(--rm-bad-fg);
  }
  .val.none {
    font-size: var(--rm-text-base);
    font-weight: 500;
    letter-spacing: 0;
  }
  .val.none :global(.ok-ico) {
    color: var(--rm-ok-fg);
  }
  .sub {
    margin-inline-start: auto;
    white-space: nowrap;
    font-size: 0.75rem;
    line-height: 1rem;
    color: var(--muted-foreground);
    text-align: end;
    font-variant-numeric: tabular-nums;
  }
  .lbl {
    font-size: 0.75rem;
    line-height: 1rem;
    color: var(--muted-foreground);
  }
  .spark-wrap {
    display: block;
    margin-block-start: auto;
    padding-block-start: 0.5rem;
    line-height: 0;
  }
  .spark {
    display: block;
    inline-size: 100%;
    block-size: 1.75rem;
    overflow: visible;
  }
  .lg .spark {
    block-size: 2.25rem;
  }

  .sk-val {
    inline-size: 3.5rem;
    block-size: 1.75rem;
    margin-block: 0.25rem;
  }
  .sk-lbl {
    inline-size: 70%;
    block-size: 0.75rem;
    margin-block: 0.125rem;
  }
  .sk-spark {
    inline-size: 100%;
    block-size: 1.75rem;
  }
  .lg .sk-spark {
    block-size: 2.25rem;
  }
</style>
