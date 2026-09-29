<script lang="ts">
  import { FileText, TriangleAlert, X } from 'lucide-svelte';
  import Button from '../../lib/ui/Button.svelte';
  import DropZone from './DropZone.svelte';
  import CopyButton from '../tester/CopyButton.svelte';
  import { api } from '../../lib/api';
  import { describeError } from '../../lib/errors';
  import { formatBytes, formatNumber } from '../../lib/format';
  import { t, locale } from '../../lib/i18n.svelte';
  import { MAX_IMPORT_BYTES, isGzip } from '../../lib/import-detect';
  import { bytesToBase64 } from '../../lib/import-preview';
  import { linkClick } from '../../lib/router.svelte';
  import { bump } from '../../lib/state/app.svelte';
  import { toast } from '../../lib/state/notify.svelte';
  import type { SitemapDiff } from '../../lib/types';

  const SHOW = 300;

  let name = $state('');
  let size = $state(0);
  let payload = $state<{ content: string; encoding?: 'base64' } | null>(null);
  let loadError = $state<string | null>(null);
  let running = $state(false);
  let error = $state<string | null>(null);
  let diff = $state<SitemapDiff | null>(null);

  const tiles = $derived(
    diff
      ? [
          { key: 'total', n: diff.total, tone: '' },
          { key: 'existing', n: diff.existing, tone: '' },
          { key: 'redirected', n: diff.redirected, tone: '' },
          { key: 'missing', n: diff.missing, tone: diff.missing > 0 ? 'warn' : 'ok' },
        ]
      : [],
  );
  const paths = $derived(diff?.missing_paths ?? []);
  const shown = $derived(paths.slice(0, SHOW));

  async function onFile(file: File) {
    loadError = null;
    diff = null;
    error = null;
    payload = null;
    if (file.size > MAX_IMPORT_BYTES) {
      loadError = t('IMPORTEXPORT.FILE_TOO_BIG', { size: formatBytes(file.size, locale()), max: formatBytes(MAX_IMPORT_BYTES, locale()) });
      return;
    }
    try {
      if (isGzip(file.name)) {
        payload = { content: bytesToBase64(new Uint8Array(await file.arrayBuffer())), encoding: 'base64' };
      } else {
        const text = await file.text();
        if (!text.trim()) {
          loadError = t('IMPORTEXPORT.FILE_EMPTY');
          return;
        }
        payload = { content: text };
      }
      name = file.name;
      size = file.size;
    } catch {
      loadError = t('IMPORTEXPORT.FILE_READ_FAILED');
    }
  }

  function clear() {
    payload = null;
    diff = null;
    error = null;
    name = '';
  }

  async function run() {
    if (!payload || running) return;
    running = true;
    error = null;
    try {
      const { data } = await api.post<SitemapDiff>('/redirects/import/sitemap', payload);
      diff = data;
      if ((data.suggestions_created ?? 0) > 0) bump('suggestions');
    } catch (e) {
      error = describeError(e);
      toast.error(error);
    } finally {
      running = false;
    }
  }
</script>

<section class="card" aria-labelledby="rm-sitemap-h">
  <div class="card-h">
    <h2 id="rm-sitemap-h">{t('IMPORTEXPORT.SITEMAP_TITLE')}</h2>
  </div>
  <div class="card-b stack">
    <p class="muted intro">{t('IMPORTEXPORT.SITEMAP_TEXT')}</p>

    {#if loadError}
      <div class="banner bad" role="alert">
        <TriangleAlert size={16} />
        <div class="b-body">{loadError}</div>
      </div>
    {/if}

    {#if !payload}
      <DropZone compact accept=".xml,.gz" title={t('IMPORTEXPORT.SITEMAP_DROP')} hint={t('IMPORTEXPORT.SITEMAP_HINT')} onfile={onFile} />
    {:else}
      <div class="filebar">
        <span class="fico" aria-hidden="true"><FileText size={18} /></span>
        <div class="grow">
          <div class="fname">{name}</div>
          <div class="muted text-xs">{formatBytes(size, locale())}</div>
        </div>
        <Button variant="ghost" onclick={clear}><X size={14} />{t('IMPORTEXPORT.FILE_REMOVE')}</Button>
        <Button variant="primary" loading={running} onclick={run}>{t('IMPORTEXPORT.SITEMAP_RUN')}</Button>
      </div>
    {/if}

    {#if error}
      <div class="banner bad" role="alert">
        <TriangleAlert size={16} />
        <div class="b-body"><div class="b-title">{t('IMPORTEXPORT.SITEMAP_FAILED')}</div>{error}</div>
      </div>
    {/if}

    <div aria-live="polite">
      {#if diff}
        <div class="stack">
          <dl class="tiles">
            {#each tiles as tile (tile.key)}
              <div class="tile {tile.tone}">
                <dt>{t(`IMPORTEXPORT.SITEMAP_TILE_${tile.key.toUpperCase()}`)}</dt>
                <dd class="num">{formatNumber(tile.n, locale())}</dd>
              </div>
            {/each}
          </dl>

          {#if paths.length}
            <div class="list-h">
              <h3>{t('IMPORTEXPORT.SITEMAP_MISSING_TITLE')}</h3>
              <span class="spacer"></span>
              <CopyButton text={paths.join('\n')} label={t('IMPORTEXPORT.SITEMAP_COPY')} withText />
            </div>
            <!-- svelte-ignore a11y_no_noninteractive_tabindex -->
            <ul class="paths mono" aria-label={t('IMPORTEXPORT.SITEMAP_MISSING_TITLE')} tabindex="0">
              {#each shown as p, i (i)}<li>{p}</li>{/each}
            </ul>
            {#if paths.length > SHOW}
              <p class="muted text-xs">{t('IMPORTEXPORT.SITEMAP_SHOWN', { shown: formatNumber(SHOW, locale()), total: formatNumber(paths.length, locale()) })}</p>
            {/if}
          {:else}
            <p class="muted">{t('IMPORTEXPORT.SITEMAP_NONE_MISSING')}</p>
          {/if}

          {#if (diff.suggestions_created ?? 0) > 0}
            <div class="banner">
              <div class="b-body">
                {t('IMPORTEXPORT.SITEMAP_SUGGESTIONS', { n: diff.suggestions_created ?? 0 })}
                <a href="#/suggestions" onclick={linkClick}>{t('IMPORTEXPORT.SITEMAP_SUGGESTIONS_LINK')}</a>
              </div>
            </div>
          {/if}
        </div>
      {/if}
    </div>
  </div>
</section>

<style>
  h2,
  h3 {
    font-size: var(--rm-text-sm);
    font-weight: 600;
    margin: 0;
  }
  .intro {
    margin: 0;
    font-size: var(--rm-text-sm);
    max-inline-size: 46rem;
  }
  .filebar {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
    padding: 0.625rem 0.75rem;
    border: 1px solid var(--border);
    border-radius: var(--rm-r-md);
    background: color-mix(in srgb, var(--muted) 30%, transparent);
  }
  .fico {
    display: inline-grid;
    color: var(--muted-foreground);
  }
  .fname {
    font-size: var(--rm-text-sm);
    font-weight: 500;
    overflow-wrap: anywhere;
  }
  .tiles {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(8rem, 1fr));
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
  .tile.warn dd {
    color: var(--rm-warn-fg);
  }
  .tile.ok dd {
    color: var(--rm-ok-fg);
  }
  .b-body a {
    color: var(--rm-info-fg);
    text-decoration: underline;
  }
  .list-h {
    display: flex;
    align-items: center;
    gap: 0.5rem;
  }
  .paths {
    list-style: none;
    margin: 0;
    padding: 0.5rem 0.75rem;
    max-block-size: 16rem;
    overflow: auto;
    border: 1px solid var(--border);
    border-radius: var(--rm-r-md);
    font-size: var(--rm-text-xs);
    line-height: 1.5;
  }
  .paths li {
    overflow-wrap: anywhere;
  }
</style>
