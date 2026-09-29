<script lang="ts">
  import { onMount } from 'svelte';
  import { ArrowRight, Settings2 } from 'lucide-svelte';
  import Button from '../../lib/ui/Button.svelte';
  import EmptyState from '../../lib/ui/EmptyState.svelte';
  import ErrorState from '../../lib/ui/ErrorState.svelte';
  import Skeleton from '../../lib/ui/Skeleton.svelte';
  import { api } from '../../lib/api';
  import { describeError } from '../../lib/errors';
  import { formatNumber } from '../../lib/format';
  import { t, locale, dir } from '../../lib/i18n.svelte';
  import { mapEntries } from '../../lib/import-preview';
  import { bump, can } from '../../lib/state/app.svelte';
  import { toast } from '../../lib/state/notify.svelte';
  import type { ImportCommitResult, SiteConfig } from '../../lib/types';

  let cfg = $state<SiteConfig | null>(null);
  let loading = $state(true);
  let error = $state<unknown>(null);
  let importing = $state(false);

  const groups = $derived(
    cfg
      ? [
          { key: 'redirects', entries: mapEntries(cfg.redirects) },
          { key: 'routes', entries: mapEntries(cfg.routes) },
        ].filter((g) => g.entries.length > 0)
      : [],
  );
  const empty = $derived(!!cfg && groups.length === 0);
  const rtl = $derived(dir() === 'rtl');

  async function load() {
    loading = true;
    error = null;
    try {
      cfg = (await api.get<SiteConfig>('/redirects/site-config')).data;
    } catch (e) {
      error = e;
    } finally {
      loading = false;
    }
  }
  onMount(() => void load());

  async function runImport() {
    if (importing) return;
    importing = true;
    try {
      const { data } = await api.post<ImportCommitResult>('/redirects/site-config/import');
      toast.success(t('IMPORTEXPORT.SITE_IMPORTED', { created: data.created, skipped: data.skipped }));
      bump('rules');
    } catch (e) {
      toast.error(describeError(e));
    } finally {
      importing = false;
    }
  }
</script>

<section class="card" aria-labelledby="rm-site-h">
  <div class="card-h">
    <h2 id="rm-site-h">{t('IMPORTEXPORT.SITE_TITLE')}</h2>
    <span class="spacer"></span>
    {#if groups.length && can.manage}
      <Button variant="primary" loading={importing} onclick={runImport}>{t('IMPORTEXPORT.SITE_IMPORT')}</Button>
    {/if}
  </div>
  <div class="card-b stack">
    <p class="muted intro">{t('IMPORTEXPORT.SITE_TEXT')}</p>

    {#if loading}
      <div class="stack" aria-hidden="true">
        <Skeleton h="1.5rem" w="12rem" />
        <Skeleton h="5rem" />
      </div>
    {:else if error}
      <ErrorState {error} onretry={load} compact />
    {:else if empty}
      <EmptyState icon={Settings2} title={t('IMPORTEXPORT.SITE_EMPTY_TITLE')} text={t('IMPORTEXPORT.SITE_EMPTY_TEXT')} />
    {:else}
      {#each groups as g (g.key)}
        <details class="list" open={g.entries.length <= 8}>
          <summary>
            <span class="mono">site.{g.key}</span>
            <span class="muted text-xs">{t('IMPORTEXPORT.SITE_ENTRIES', { n: g.entries.length })}</span>
          </summary>
          <p class="muted text-xs help">{t(g.key === 'redirects' ? 'IMPORTEXPORT.SITE_REDIRECTS_HELP' : 'IMPORTEXPORT.SITE_ROUTES_HELP')}</p>
          <!-- svelte-ignore a11y_no_noninteractive_tabindex -->
          <div class="table-wrap box" role="region" tabindex="0" aria-label={t('IMPORTEXPORT.SITE_TABLE', { name: `site.${g.key}`, n: g.entries.length })}>
            <table class="table">
              <caption class="sr-only">{t('IMPORTEXPORT.SITE_TABLE', { name: `site.${g.key}`, n: formatNumber(g.entries.length, locale()) })}</caption>
              <thead>
                <tr>
                  <th scope="col">{t('IMPORTEXPORT.SITE_COL_SOURCE')}</th>
                  <th scope="col" class="arrow-col"><span class="sr-only">→</span></th>
                  <th scope="col">{t('IMPORTEXPORT.SITE_COL_TARGET')}</th>
                </tr>
              </thead>
              <tbody>
                {#each g.entries as [from, to] (from)}
                  <tr>
                    <td class="mono cell">{from}</td>
                    <td class="arrow-col" class:flip={rtl} aria-hidden="true"><ArrowRight size={13} /></td>
                    <td class="mono cell">{to}</td>
                  </tr>
                {/each}
              </tbody>
            </table>
          </div>
        </details>
      {/each}
    {/if}
  </div>
</section>

<style>
  h2 {
    font-size: var(--rm-text-sm);
    font-weight: 600;
    margin: 0;
  }
  .intro {
    margin: 0;
    font-size: var(--rm-text-sm);
    max-inline-size: 46rem;
  }
  .list > summary {
    cursor: pointer;
    display: flex;
    align-items: baseline;
    gap: 0.5rem;
    font-size: var(--rm-text-sm);
    font-weight: 500;
    padding-block: 0.25rem;
  }
  .help {
    margin: 0.125rem 0 0.5rem;
  }
  .box {
    border: 1px solid var(--border);
    border-radius: var(--rm-r-md);
    max-block-size: 22rem;
    overflow: auto;
  }
  .box :global(table) {
    table-layout: fixed;
  }
  .box :global(th:first-child),
  .box :global(td:first-child) {
    inline-size: 45%;
  }
  .cell {
    font-size: var(--rm-text-xs);
    overflow-wrap: anywhere;
  }
  .box :global(.arrow-col) {
    inline-size: 2rem;
    text-align: center;
    color: var(--muted-foreground);
  }
  td.arrow-col.flip :global(svg) {
    transform: scaleX(-1);
  }
</style>
