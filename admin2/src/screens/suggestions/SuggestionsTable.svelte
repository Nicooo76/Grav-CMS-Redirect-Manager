<script lang="ts">
  import { tick } from 'svelte';
  import SuggestionRow from './SuggestionRow.svelte';
  import Skeleton from '../../lib/ui/Skeleton.svelte';
  import { deepActive } from '../../lib/dom';
  import { t } from '../../lib/i18n.svelte';
  import type { StoredSuggestion } from '../../lib/types';
  import { suggestions } from './store.svelte';

  interface Props {
    rows: StoredSuggestion[];
    actions: boolean;
  }
  let { rows, actions }: Props = $props();

  let wrap: HTMLElement | undefined = $state();

  /** After accept/reject the row is gone: keep keyboard focus on the next row's Accept button. */
  async function leave(run: () => Promise<void>, index: number) {
    const p = run();
    await tick();
    const active = wrap ? deepActive(wrap.getRootNode() as ShadowRoot | Document) : null;
    if (wrap && !(active && wrap.contains(active))) {
      const buttons = wrap.querySelectorAll<HTMLElement>('tbody tr .accept');
      buttons[Math.min(index, buttons.length - 1)]?.focus();
    }
    await p;
  }
</script>

<div bind:this={wrap} class="table-wrap sg-scroll">
  <table class="table sg" aria-label={t('SUGGESTIONS.TABLE_LABEL')} aria-busy={suggestions.loading}>
    <thead>
      <tr>
        <th scope="col" class="c-score">{t('SUGGESTIONS.COL_SCORE')}</th>
        <th scope="col" class="c-reason">{t('SUGGESTIONS.COL_REASON')}</th>
        <th scope="col" class="c-path">{t('SUGGESTIONS.COL_PATH')}</th>
        <th scope="col" class="c-target">{t('SUGGESTIONS.COL_TARGET')}</th>
        <th scope="col" class="c-hits num">{t('SUGGESTIONS.COL_HITS')}</th>
        {#if actions}<th scope="col" class="c-act"><span class="sr-only">{t('SUGGESTIONS.COL_ACTIONS')}</span></th>{/if}
      </tr>
    </thead>
    <tbody class:busy={suggestions.loading && suggestions.loaded}>
      {#if !suggestions.loaded}
        {#each Array(6) as _, i (i)}
          <tr class="sk">
            <td><Skeleton w="5rem" /></td>
            <td class="c-reason"><Skeleton w="8rem" /></td>
            <td><Skeleton w="{55 + ((i * 13) % 35)}%" /></td>
            <td><Skeleton w="{45 + ((i * 17) % 40)}%" /></td>
            <td class="c-hits"><Skeleton w="2rem" /></td>
            {#if actions}<td class="c-act"><Skeleton w="8rem" h="1.75rem" /></td>{/if}
          </tr>
        {/each}
      {:else}
        {#each rows as row, i (row.id)}
          <SuggestionRow
            {row}
            index={i}
            {actions}
            onaccept={(r, idx) => leave(() => suggestions.accept(r), idx)}
            onreject={(r, idx) => leave(() => suggestions.reject(r), idx)}
          />
        {/each}
      {/if}
    </tbody>
  </table>
</div>

<style>
  table.sg {
    table-layout: fixed;
    min-inline-size: 40rem;
  }
  table.sg :global(td) {
    overflow: hidden;
  }
  table.sg :global(td.c-act) {
    overflow: visible;
  }
  table.sg :global(tbody tr) {
    block-size: 3.5rem;
  }
  tbody.busy {
    opacity: 0.6;
    transition: opacity 0.15s;
  }
  table.sg :global(th.c-score) {
    inline-size: 7rem;
  }
  table.sg :global(th.c-reason) {
    inline-size: 14rem;
  }
  table.sg :global(th.c-hits) {
    inline-size: 5rem;
  }
  table.sg :global(th.c-act) {
    inline-size: 15rem;
    text-align: end;
  }
  @container (max-width: 56rem) {
    table.sg :global(.hint) {
      display: none;
    }
    table.sg :global(th.c-reason) {
      inline-size: 9rem;
    }
    table.sg :global(th.c-act) {
      inline-size: 11rem;
    }
    table.sg :global(.rej-txt) {
      display: none;
    }
  }
  @container (max-width: 44rem) {
    table.sg :global(.c-hits) {
      display: none;
    }
  }
</style>
