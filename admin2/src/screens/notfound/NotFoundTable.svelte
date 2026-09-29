<script lang="ts">
  import { tick } from 'svelte';
  import { ArrowDown, ArrowUp } from 'lucide-svelte';
  import NotFoundRow from './NotFoundRow.svelte';
  import Skeleton from '../../lib/ui/Skeleton.svelte';
  import { deepActive } from '../../lib/dom';
  import { t } from '../../lib/i18n.svelte';
  import { toggleSort, type NotFoundSort } from '../../lib/notfound-query';
  import { notFound } from './store.svelte';

  const showSuggestion = $derived(notFound.rows.some((r) => !r.has_rule && r.best_suggestion));

  function ariaSort(key: NotFoundSort): 'ascending' | 'descending' | 'none' {
    if (notFound.list.sort !== key) return 'none';
    return notFound.list.dir === 'asc' ? 'ascending' : 'descending';
  }

  /* keep keyboard focus in the list when the focused row leaves it */
  let wrap: HTMLElement | undefined = $state();
  let lastIndex = 0;
  let lastCount = 0;
  function onfocusin(e: FocusEvent) {
    const tr = (e.target as HTMLElement).closest?.('tr[data-path]');
    if (tr && wrap) lastIndex = Array.from(wrap.querySelectorAll('tr[data-path]')).indexOf(tr);
  }
  $effect(() => {
    const count = notFound.rows.length;
    const shrank = count < lastCount;
    lastCount = count;
    if (!shrank) return;
    void tick().then(() => {
      const active = deepActive(wrap?.getRootNode() as ShadowRoot | Document);
      if (!wrap || (active && wrap.contains(active))) return;
      if (active && active !== document.body && active.isConnected) return;
      const rows = wrap.querySelectorAll<HTMLElement>('tr[data-path]');
      const next = rows[Math.min(lastIndex, rows.length - 1)];
      next?.querySelector<HTMLElement>('.acts a, .acts button')?.focus();
    });
  });
</script>

<div bind:this={wrap} class="table-wrap nf-scroll" onfocusin={onfocusin}>
  <table class="table nf" aria-label={t('NOTFOUND.TABLE_LABEL')} aria-busy={notFound.loading}>
    <thead>
      <tr>
        <th scope="col" class="c-path" aria-sort={ariaSort('path')}>{@render sortBtn('path', t('NOTFOUND.COL_PATH'))}</th>
        <th scope="col" class="c-hits num" aria-sort={ariaSort('hits')}>{@render sortBtn('hits', t('NOTFOUND.COL_HITS'))}</th>
        <th scope="col" class="c-first" aria-sort={ariaSort('first')}>{@render sortBtn('first', t('NOTFOUND.COL_FIRST'))}</th>
        <th scope="col" class="c-last" aria-sort={ariaSort('last')}>{@render sortBtn('last', t('NOTFOUND.COL_LAST'))}</th>
        <th scope="col" class="c-ref">{t('NOTFOUND.COL_REFERERS')}</th>
        <th scope="col" class="c-trend">{t('NOTFOUND.COL_TREND')}</th>
        {#if showSuggestion || !notFound.loaded}<th scope="col" class="c-sugg">{t('NOTFOUND.COL_SUGGESTION')}</th>{/if}
        <th scope="col" class="c-act"><span class="sr-only">{t('NOTFOUND.COL_ACTIONS')}</span></th>
      </tr>
    </thead>
    <tbody class:busy={notFound.loading && notFound.loaded}>
      {#if !notFound.loaded}
        {#each Array(8) as _, i (i)}
          <tr class="sk">
            <td><Skeleton w="{50 + ((i * 13) % 35)}%" /></td>
            <td class="num"><Skeleton w="2.5rem" /></td>
            <td class="c-first"><Skeleton w="4rem" /></td>
            <td class="c-last"><Skeleton w="4rem" /></td>
            <td class="c-ref"><Skeleton w="7rem" /></td>
            <td class="c-trend"><Skeleton w="3.5rem" h="1.125rem" /></td>
            <td class="c-sugg"><Skeleton w="8rem" h="1.25rem" /></td>
            <td class="c-act"><Skeleton w="7rem" h="1.75rem" /></td>
          </tr>
        {/each}
      {:else}
        {#each notFound.rows as row (row.path)}
          <NotFoundRow {row} {showSuggestion} />
        {/each}
      {/if}
    </tbody>
  </table>
</div>

{#snippet sortBtn(key: NotFoundSort, label: string)}
  <button type="button" class="sort-btn" data-active={notFound.list.sort === key} onclick={() => notFound.setList(toggleSort(notFound.list, key))}>
    {label}
    {#if notFound.list.sort === key}
      {#if notFound.list.dir === 'asc'}<ArrowUp size={12} />{:else}<ArrowDown size={12} />{/if}
    {/if}
  </button>
{/snippet}

<style>
  table.nf {
    table-layout: fixed;
    min-inline-size: 36rem;
  }
  table.nf :global(td) {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  table.nf :global(tbody tr:not(.detail):not(.sk)) {
    block-size: var(--rm-row-h);
  }
  tbody.busy {
    opacity: 0.6;
    transition: opacity 0.15s;
  }
  table.nf :global(th.c-hits) {
    inline-size: 4.75rem;
  }
  table.nf :global(th.c-first),
  table.nf :global(th.c-last) {
    inline-size: 7rem;
  }
  table.nf :global(th.c-ref) {
    inline-size: 10rem;
  }
  table.nf :global(th.c-trend) {
    inline-size: 5rem;
  }
  table.nf :global(th.c-sugg) {
    inline-size: 11.5rem;
  }
  table.nf :global(th.c-act) {
    inline-size: 11.5rem;
    text-align: end;
  }
  table.nf :global(td.c-act) {
    text-align: end;
    overflow: visible;
  }
  /* tablet: collapse secondary columns */
  @container (max-width: 56rem) {
    table.nf :global(.c-first),
    table.nf :global(.c-ref),
    table.nf :global(.c-trend) {
      display: none;
    }
    table.nf :global(th.c-last) {
      inline-size: 9rem;
    }
  }
  @container (max-width: 40rem) {
    table.nf :global(.c-sugg) {
      display: none;
    }
  }
</style>
