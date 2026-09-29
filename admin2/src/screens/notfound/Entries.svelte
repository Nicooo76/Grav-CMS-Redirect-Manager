<script lang="ts">
  import Skeleton from '../../lib/ui/Skeleton.svelte';
  import ErrorState from '../../lib/ui/ErrorState.svelte';
  import { formatDateTime } from '../../lib/format';
  import { locale, t } from '../../lib/i18n.svelte';
  import { refererLabel } from '../../lib/notfound-query';
  import { notFound } from './store.svelte';

  interface Props {
    path: string;
    id: string;
  }
  let { path, id }: Props = $props();

  const state = $derived(notFound.entries[path]);
  const first = $derived(!state || (state.loading && state.rows.length === 0));
  const classLabel = (c: string | undefined) => (c ? t(`NOTFOUND.CLASS_${c.toUpperCase()}`) : '–');
</script>

<div {id} class="entries" aria-busy={state?.loading}>
  {#if state?.error && state.rows.length === 0}
    <ErrorState compact error={state.error} title={t('NOTFOUND.ENTRIES_ERROR')} onretry={() => notFound.retryEntries(path)} />
  {:else if first}
    <div class="sk" aria-hidden="true">
      {#each Array(4) as _, i (i)}<Skeleton w="{70 + ((i * 11) % 25)}%" />{/each}
    </div>
  {:else if state && state.rows.length === 0}
    <p class="muted text-xs empty-line">{t('NOTFOUND.ENTRIES_EMPTY')}</p>
  {:else if state}
    <!-- svelte-ignore a11y_no_noninteractive_tabindex -->
    <div class="scroll" role="region" tabindex="0" aria-label={t('NOTFOUND.ENTRIES_TITLE', { path })}>
      <table class="table mini">
        <caption class="sr-only">{t('NOTFOUND.ENTRIES_TITLE', { path })}</caption>
        <thead>
          <tr>
            <th scope="col">{t('NOTFOUND.ENTRY_TIME')}</th>
            <th scope="col">{t('NOTFOUND.ENTRY_REFERER')}</th>
            <th scope="col">{t('NOTFOUND.ENTRY_CLASS')}</th>
            <th scope="col">{t('NOTFOUND.ENTRY_LANGUAGE')}</th>
            <th scope="col">{t('NOTFOUND.ENTRY_HOST')}</th>
          </tr>
        </thead>
        <tbody>
          {#each state.rows as e, i (e.time + i)}
            <tr>
              <td class="nowrap">{formatDateTime(e.time, locale())}</td>
              <td class="ref">
                {#if refererLabel(e.referer ?? '')}<span class="mono truncate" title={e.referer}>{refererLabel(e.referer ?? '')}</span>{:else}<span class="muted">{t('NOTFOUND.DIRECT')}</span>{/if}
              </td>
              <td><span title={e.ua}>{classLabel(e.ua_class)}</span></td>
              <td>{e.language || '–'}</td>
              <td class="mono">{e.host || '–'}</td>
            </tr>
          {/each}
        </tbody>
      </table>
    </div>
  {/if}
</div>

<style>
  .entries {
    padding: 0.75rem 1rem 1rem;
  }
  .scroll {
    max-block-size: 16rem;
    overflow: auto;
    border: 1px solid var(--border);
    border-radius: var(--rm-r-md);
    background: var(--card);
  }
  .mini {
    font-size: var(--rm-text-xs);
  }
  .mini :global(th) {
    position: sticky;
    inset-block-start: 0;
    background: color-mix(in srgb, var(--muted) 45%, var(--card));
  }
  .mini :global(td),
  .mini :global(th) {
    padding-block: 0.375rem;
  }
  .mini tbody tr:hover {
    background: transparent;
  }
  .ref {
    max-inline-size: 18rem;
  }
  .ref .truncate {
    display: block;
  }
  .sk {
    display: flex;
    flex-direction: column;
    gap: 0.625rem;
  }
  .empty-line {
    margin: 0;
  }
</style>
