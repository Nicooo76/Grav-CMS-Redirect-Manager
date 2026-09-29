<script lang="ts">
  import AreaChart from '../../lib/ui/AreaChart.svelte';
  import Segmented from '../../lib/ui/Segmented.svelte';
  import Skeleton from '../../lib/ui/Skeleton.svelte';
  import { formatDate, formatNumber } from '../../lib/format';
  import { locale, t } from '../../lib/i18n.svelte';
  import { trendSummary, type RangeDays, type TrendPoint } from '../../lib/notfound-query';
  import { notFound } from './store.svelte';

  const range = $derived([
    { value: 7, label: t('NOTFOUND.DAYS_7') },
    { value: 30, label: t('NOTFOUND.DAYS_30') },
    { value: 90, label: t('NOTFOUND.DAYS_90') },
  ]);

  const summary = $derived(trendSummary(notFound.trend));
  const label = $derived(
    t('NOTFOUND.CHART_LABEL', { days: notFound.list.days, hits: formatNumber(summary.total, locale()) }) +
      ' ' +
      (summary.peak
        ? t('NOTFOUND.CHART_PEAK', { hits: formatNumber(summary.peak.count, locale()), day: formatDate(summary.peak.day, locale()) })
        : t('NOTFOUND.CHART_NO_HITS')),
  );
  const pointText = (p: TrendPoint) => t('NOTFOUND.CHART_POINT', { day: formatDate(p.day, locale()), hits: p.count });
</script>

<section class="card" aria-labelledby="rm-nf-trend">
  <div class="card-h">
    <h2 id="rm-nf-trend">{t('NOTFOUND.CHART_TITLE')}</h2>
    <span class="spacer"></span>
    <Segmented
      size="sm"
      items={range}
      value={notFound.list.days}
      label={t('NOTFOUND.RANGE_LABEL')}
      onchange={(v: RangeDays) => notFound.setList({ days: v })}
    />
  </div>
  <div class="card-b body">
    <div class="plot" aria-busy={notFound.trendLoading}>
      {#if !notFound.trendLoaded && notFound.trendError}
        <div class="fail" role="alert">
          <span class="muted text-xs">{t('NOTFOUND.TREND_ERROR')}</span>
          <button type="button" class="btn outline sm" onclick={() => notFound.loadTrend()}>{t('COMMON.RETRY')}</button>
        </div>
      {:else if !notFound.trendLoaded}
        <Skeleton w="100%" h="10.5rem" radius="var(--rm-r-md)" />
      {:else}
        <AreaChart
          points={notFound.trend}
          {label}
          caption={t('NOTFOUND.CHART_CAPTION')}
          dayHeader={t('NOTFOUND.CHART_COL_DAY')}
          valueHeader={t('NOTFOUND.CHART_COL_HITS')}
          {pointText}
          help={t('NOTFOUND.CHART_HELP')}
        />
      {/if}
    </div>
    <dl class="stats">
      <div>
        <dt class="text-xs muted">{t('NOTFOUND.STAT_HITS')}</dt>
        <dd class="num">{notFound.loaded ? formatNumber(notFound.totals.hits, locale()) : '–'}</dd>
      </div>
      <div>
        <dt class="text-xs muted">{t('NOTFOUND.STAT_PATHS')}</dt>
        <dd class="num">{notFound.loaded ? formatNumber(notFound.totals.paths, locale()) : '–'}</dd>
      </div>
    </dl>
  </div>
</section>

<style>
  .body {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 1.5rem;
    align-items: center;
  }
  .plot {
    min-inline-size: 0;
    min-block-size: 10.5rem;
  }
  .fail {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.75rem;
    block-size: 10.5rem;
  }
  .stats {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    min-inline-size: 6rem;
    padding-inline-start: 1.5rem;
    border-inline-start: 1px solid var(--border);
    margin: 0;
  }
  dd {
    margin: 0;
    font-size: 1.5rem;
    line-height: 2rem;
    font-weight: 600;
    color: var(--foreground);
  }
  @container (max-width: 40rem) {
    .body {
      grid-template-columns: minmax(0, 1fr);
      gap: 1rem;
    }
    .stats {
      flex-direction: row;
      gap: 2rem;
      padding: 0.75rem 0 0;
      border-inline-start: 0;
      border-block-start: 1px solid var(--border);
    }
  }
</style>
