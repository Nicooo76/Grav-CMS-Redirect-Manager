<script lang="ts">
  import { CircleX, Info, TriangleAlert } from 'lucide-svelte';
  import Segmented from '../../lib/ui/Segmented.svelte';
  import PreviewTable from './PreviewTable.svelte';
  import { t, locale } from '../../lib/i18n.svelte';
  import { formatNumber } from '../../lib/format';
  import { filterCounts, visibleRows, ROW_CAP, type RowFilter } from '../../lib/import-preview';
  import type { ImportPreview } from '../../lib/types';

  interface Props {
    preview: ImportPreview;
    filter: RowFilter;
  }
  let { preview, filter = $bindable() }: Props = $props();

  const c = $derived(preview.counts);
  const fc = $derived(filterCounts(preview.rows));
  const view = $derived(visibleRows(preview.rows, filter));
  const notFound = $derived(preview.not_found_paths.length || c.not_found || 0);

  const filterItems = $derived(
    (['all', 'errors', 'warnings', 'duplicates', 'valid'] as const)
      .filter((f) => f === 'all' || f === 'valid' || fc[f] > 0)
      .map((f) => ({ value: f, label: `${t(`IMPORTEXPORT.FILTER_${f.toUpperCase()}`)} · ${formatNumber(fc[f], locale())}` })),
  );
  // a filter that lost all its rows (new preview) falls back to All
  $effect(() => {
    if (filter !== 'all' && fc[filter] === 0) filter = 'all';
  });

  const tiles = $derived([
    { key: 'total', n: c.total, tone: '' },
    { key: 'valid', n: c.valid, tone: c.valid > 0 ? 'ok' : '' },
    { key: 'errors', n: c.errors, tone: c.errors > 0 ? 'bad' : '' },
    { key: 'duplicates', n: c.duplicates, tone: c.duplicates > 0 ? 'info' : '' },
    { key: 'warnings', n: c.warnings, tone: c.warnings > 0 ? 'warn' : '' },
  ]);
</script>

<div class="stack panel">
  <dl class="tiles">
    {#each tiles as tile (tile.key)}
      <div class="tile {tile.tone}">
        <dt>{t(`IMPORTEXPORT.TILE_${tile.key.toUpperCase()}`)}</dt>
        <dd class="num">{formatNumber(tile.n, locale())}</dd>
      </div>
    {/each}
  </dl>

  {#each preview.errors as e, i (i)}
    <div class="banner bad" role="alert">
      <CircleX size={16} />
      <div class="b-body">{e.message}</div>
    </div>
  {/each}
  {#each preview.warnings as w, i (i)}
    <div class="banner warn">
      <TriangleAlert size={16} />
      <div class="b-body">{w.message}</div>
    </div>
  {/each}
  {#if notFound > 0}
    <div class="banner">
      <Info size={16} />
      <div class="b-body">
        {t('IMPORTEXPORT.NOT_FOUND_NOTE', { n: notFound })}
        <a href="#/404">{t('IMPORTEXPORT.LINK_404')}</a>
      </div>
    </div>
  {/if}

  {#if preview.rows.length > 0}
    <div class="filter">
      <Segmented items={filterItems} bind:value={filter} label={t('IMPORTEXPORT.FILTER_LABEL')} size="sm" />
    </div>
    <div class="tbl-card">
      {#if view.rows.length}
        <PreviewTable rows={view.rows} />
      {:else}
        <p class="muted none">{t('IMPORTEXPORT.ROWS_NONE')}</p>
      {/if}
    </div>
    {#if view.total > ROW_CAP}
      <p class="muted text-xs cap">{t('IMPORTEXPORT.ROWS_CAPPED', { shown: formatNumber(ROW_CAP, locale()), total: formatNumber(view.total, locale()) })}</p>
    {/if}
  {/if}
</div>

<style>
  .panel {
    gap: 0.75rem;
  }
  .tiles {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(7.5rem, 1fr));
    gap: 0.5rem;
    margin: 0;
  }
  .tile {
    border: 1px solid var(--border);
    border-radius: var(--rm-r-md);
    padding: 0.5rem 0.75rem;
    background: var(--card);
  }
  .tile dt {
    font-size: var(--rm-text-xs);
    color: var(--muted-foreground);
  }
  .tile dd {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 600;
    line-height: 1.75rem;
    font-variant-numeric: tabular-nums;
  }
  .tile.ok dd {
    color: var(--rm-ok-fg);
  }
  .tile.bad {
    border-color: color-mix(in srgb, var(--rm-bad-fg) 35%, var(--border));
    background: color-mix(in srgb, var(--rm-bad-fg) 6%, var(--card));
  }
  .tile.bad dt {
    color: var(--foreground);
  }
  .tile.bad dd {
    color: var(--rm-bad-fg);
  }
  .tile.warn dd {
    color: var(--rm-warn-fg);
  }
  .tile.info dd {
    color: var(--rm-info-fg);
  }
  .b-body a {
    color: var(--rm-info-fg);
    text-decoration: underline;
  }
  .filter {
    display: flex;
  }
  .tbl-card {
    border: 1px solid var(--border);
    border-radius: var(--rm-r-md);
    overflow: hidden;
  }
  .none {
    margin: 0;
    padding: 1.5rem;
    text-align: center;
    font-size: var(--rm-text-sm);
  }
  .cap {
    margin: 0;
  }
</style>
