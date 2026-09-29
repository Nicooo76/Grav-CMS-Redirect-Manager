<script lang="ts">
  import { onMount } from 'svelte';
  import { ArrowDown, ArrowUp } from 'lucide-svelte';
  import RuleRow from './RuleRow.svelte';
  import Checkbox from '../../lib/ui/Checkbox.svelte';
  import Skeleton from '../../lib/ui/Skeleton.svelte';
  import { t } from '../../lib/i18n.svelte';
  import { rules } from '../../lib/state/rules.svelte';
  import { canReorder, toggleSort } from '../../lib/rules-query';
  import { VIRTUAL_THRESHOLD, computeWindow, indexAt } from '../../lib/virtual';
  import type { RuleSort } from '../../lib/types';

  const cols = $derived(rules.columns);
  const count = $derived(rules.rows.length);
  const virtual = $derived(count > VIRTUAL_THRESHOLD);

  let scroller: HTMLElement | undefined = $state();
  let tbody: HTMLElement | undefined = $state();
  let scrollTop = $state(0);
  let viewportH = $state(640);
  let rowH = $state(44);

  const win = $derived(
    virtual
      ? computeWindow({ scrollTop, viewportHeight: viewportH, rowHeight: rowH, count })
      : { start: 0, end: count, padTop: 0, padBottom: 0, total: 0 },
  );
  const visible = $derived(rules.rows.slice(win.start, win.end));
  const colCount = $derived(3 + (cols.status ? 1 : 0) + (cols.target ? 1 : 0) + (cols.code ? 1 : 0) + (cols.group ? 1 : 0) + (cols.hits ? 1 : 0) + (cols.last_hit ? 1 : 0) + (cols.origin ? 1 : 0) + (cols.priority ? 1 : 0) + 1);

  const allSelected = $derived(count > 0 && rules.selectedCount >= count && rules.rows.every((r) => rules.selected[r.id]));
  const someSelected = $derived(rules.selectedCount > 0 && !allSelected);

  onMount(() => {
    const px = parseFloat(getComputedStyle(document.documentElement).fontSize) || 16;
    rowH = 2.75 * px;
    const ro = new ResizeObserver(() => {
      if (scroller) viewportH = scroller.clientHeight;
    });
    if (scroller) ro.observe(scroller);
    return () => ro.disconnect();
  });

  // reset the scroll position when the data set changes (page, sort, filter)
  let lastKey = '';
  $effect(() => {
    const key = `${rules.list.page}|${rules.list.sort}|${rules.list.dir}|${rules.list.q}|${rules.list.per_page}`;
    if (key !== lastKey) {
      lastKey = key;
      if (scroller) scroller.scrollTop = 0;
      scrollTop = 0;
    }
  });

  let raf = 0;
  function onScroll() {
    if (!scroller || raf) return;
    raf = requestAnimationFrame(() => {
      raf = 0;
      if (scroller) scrollTop = scroller.scrollTop;
    });
  }

  /* ---------- selection (shift-click selects a range) ---------- */
  let anchor = -1;
  function onSelect(e: Event, index: number) {
    const input = e.currentTarget as HTMLInputElement;
    const on = input.checked;
    const shift = (e as MouseEvent).shiftKey && anchor !== -1;
    if (shift) rules.toggleRange(anchor, index, on);
    else rules.toggle(rules.rows[index].id, on);
    anchor = index;
  }

  function ariaSort(key: RuleSort): 'ascending' | 'descending' | 'none' {
    if (rules.list.sort !== key) return 'none';
    return rules.list.dir === 'asc' ? 'ascending' : 'descending';
  }

  /* ---------- drag and drop (pointer) ---------- */
  type Drag = { index: number; over: number; active: boolean; startY: number };
  let drag = $state<Drag | null>(null);
  let lastY = 0;
  let scrollRaf = 0;

  function ondragstart(e: PointerEvent, index: number) {
    if (!canReorder(rules.list) || e.button !== 0 || rules.reordering) return;
    e.preventDefault();
    (e.currentTarget as HTMLElement).setPointerCapture(e.pointerId);
    drag = { index, over: index, active: false, startY: e.clientY };
    lastY = e.clientY;
    window.addEventListener('pointermove', onmove);
    window.addEventListener('pointerup', onup);
    window.addEventListener('pointercancel', oncancel);
  }
  function overIndex(clientY: number): number {
    if (!tbody) return drag?.index ?? 0;
    const top = tbody.getBoundingClientRect().top;
    // every row has the same height, so the pointer position maps straight to an index
    return indexAt(clientY - top, rowH, count) - (virtual ? 0 : 0);
  }
  function onmove(e: PointerEvent) {
    if (!drag) return;
    lastY = e.clientY;
    if (!drag.active && Math.abs(e.clientY - drag.startY) < 4) return;
    drag.active = true;
    drag.over = overIndex(e.clientY);
    if (!scrollRaf) scrollRaf = requestAnimationFrame(autoscroll);
  }
  function autoscroll() {
    scrollRaf = 0;
    if (!drag?.active) return;
    const el = virtual ? scroller : null;
    const target = el ?? (document.scrollingElement as HTMLElement | null);
    const rect = el ? el.getBoundingClientRect() : { top: 0, bottom: window.innerHeight };
    const edge = 48;
    let v = 0;
    if (lastY < rect.top + edge) v = -Math.ceil((rect.top + edge - lastY) / 6);
    else if (lastY > rect.bottom - edge) v = Math.ceil((lastY - (rect.bottom - edge)) / 6);
    if (v && target) {
      target.scrollBy({ top: Math.max(-24, Math.min(24, v)) });
      drag.over = overIndex(lastY);
    }
    scrollRaf = requestAnimationFrame(autoscroll);
  }
  function endDrag() {
    window.removeEventListener('pointermove', onmove);
    window.removeEventListener('pointerup', onup);
    window.removeEventListener('pointercancel', oncancel);
    cancelAnimationFrame(scrollRaf);
    scrollRaf = 0;
  }
  function onup() {
    const d = drag;
    endDrag();
    drag = null;
    if (d?.active && d.over !== d.index) void rules.reorder(d.index, d.over);
  }
  function oncancel() {
    endDrag();
    drag = null;
  }

  function edge(index: number): 'before' | 'after' | null {
    if (!drag?.active || drag.over !== index || drag.over === drag.index) return null;
    return drag.over < drag.index ? 'before' : 'after';
  }
</script>

<!-- svelte-ignore a11y_no_noninteractive_tabindex -->
<div
  bind:this={scroller}
  class="table-wrap rules-scroll"
  class:virtual
  onscroll={onScroll}
  tabindex={virtual ? 0 : undefined}
  data-virtual={virtual || undefined}
  role={virtual ? 'region' : undefined}
  aria-label={virtual ? t('RULES.TABLE_REGION') : undefined}
>
  <table class="table rules" aria-label={t('RULES.TABLE_LABEL')} aria-rowcount={rules.total + 1} aria-busy={rules.loading}>
    <thead>
      <tr aria-rowindex="1">
        <th class="c-sel keep">
          <Checkbox
            checked={allSelected}
            indeterminate={someSelected}
            aria-label={t('RULES.SELECT_ALL')}
            onchange={(e: Event) => rules.toggleAll((e.currentTarget as HTMLInputElement).checked)}
          />
        </th>
        <th class="c-drag"><span class="sr-only">{t('RULES.COL_DRAG')}</span></th>
        {#if cols.status}<th class="c-status">{t('RULES.COL_STATUS')}</th>{/if}
        <th class="c-source" aria-sort={ariaSort('source')}>
          {@render sortBtn('source', t('RULES.COL_SOURCE'))}
        </th>
        {#if cols.target}
          <th class="c-target" aria-sort={ariaSort('target')}>{@render sortBtn('target', t('RULES.COL_TARGET'))}</th>
        {/if}
        {#if cols.code}
          <th class="c-code" aria-sort={ariaSort('status')}>{@render sortBtn('status', t('RULES.COL_CODE'))}</th>
        {/if}
        {#if cols.group}<th class="c-group">{t('RULES.COL_GROUP')}</th>{/if}
        {#if cols.hits}
          <th class="c-hits num" aria-sort={ariaSort('hits')}>{@render sortBtn('hits', t('RULES.COL_HITS'))}</th>
        {/if}
        {#if cols.last_hit}
          <th class="c-last" aria-sort={ariaSort('last_hit')}>{@render sortBtn('last_hit', t('RULES.COL_LAST_HIT'))}</th>
        {/if}
        {#if cols.origin}<th class="c-origin">{t('RULES.COL_ORIGIN')}</th>{/if}
        {#if cols.priority}
          <th class="c-priority num" aria-sort={ariaSort('priority')}>{@render sortBtn('priority', t('RULES.COL_PRIORITY'))}</th>
        {/if}
        <th class="c-act"><span class="sr-only">{t('RULES.COL_ACTIONS')}</span></th>
      </tr>
    </thead>
    <tbody bind:this={tbody} class:busy={rules.loading && rules.loaded}>
      {#if !rules.loaded}
        {#each Array(8) as _, i (i)}
          <tr class="sk">
            <td class="c-sel keep"><Skeleton w="1rem" h="1rem" /></td>
            <td class="c-drag keep"></td>
            {#if cols.status}<td><Skeleton w="7rem" h="1.25rem" /></td>{/if}
            <td><Skeleton w="{55 + ((i * 13) % 35)}%" /></td>
            {#if cols.target}<td><Skeleton w="{40 + ((i * 17) % 45)}%" /></td>{/if}
            {#if cols.code}<td><Skeleton w="2rem" /></td>{/if}
            {#if cols.group}<td><Skeleton w="4rem" /></td>{/if}
            {#if cols.hits}<td><Skeleton w="4rem" /></td>{/if}
            {#if cols.last_hit}<td><Skeleton w="4rem" /></td>{/if}
            {#if cols.origin}<td><Skeleton w="3.5rem" /></td>{/if}
            {#if cols.priority}<td><Skeleton w="2rem" /></td>{/if}
            <td class="c-act keep"></td>
          </tr>
        {/each}
      {:else}
        {#if virtual && win.padTop > 0}
          <tr aria-hidden="true" class="pad"><td colspan={colCount} style="block-size:{win.padTop}px"></td></tr>
        {/if}
        {#each visible as rule, i (rule.id)}
          <RuleRow
            {rule}
            index={win.start + i}
            {count}
            dragging={drag?.active === true && drag.index === win.start + i}
            dropEdge={edge(win.start + i)}
            onselect={onSelect}
            {ondragstart}
          />
        {/each}
        {#if virtual && win.padBottom > 0}
          <tr aria-hidden="true" class="pad"><td colspan={colCount} style="block-size:{win.padBottom}px"></td></tr>
        {/if}
      {/if}
    </tbody>
  </table>
</div>

{#snippet sortBtn(key: RuleSort, label: string)}
  <button type="button" class="sort-btn" data-active={rules.list.sort === key} onclick={() => rules.setList(toggleSort(rules.list, key))}>
    {label}
    {#if rules.list.sort === key}
      {#if rules.list.dir === 'asc'}<ArrowUp size={12} />{:else}<ArrowDown size={12} />{/if}
    {/if}
  </button>
{/snippet}

<style>
  .rules-scroll {
    position: relative;
  }
  .rules-scroll.virtual {
    max-block-size: min(72vh, 46rem);
    overflow-y: auto;
    overscroll-behavior: contain;
  }
  .rules-scroll.virtual :global(thead th) {
    position: sticky;
    inset-block-start: 0;
    z-index: 2;
    background: color-mix(in srgb, var(--muted) 45%, var(--card));
  }
  table.rules {
    table-layout: fixed;
    min-inline-size: 52rem;
  }
  table.rules :global(tbody tr:not(.pad):not(.sk)) {
    block-size: var(--rm-row-h);
  }
  table.rules :global(tbody tr.sk) {
    block-size: var(--rm-row-h);
  }
  table.rules :global(td) {
    padding-block: 0;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
  }
  table.rules :global(tr.pad td) {
    padding: 0;
    border: 0;
  }
  table.rules :global(tr.pad:hover) {
    background: none;
  }
  tbody.busy {
    opacity: 0.6;
    transition: opacity 0.15s;
  }
  /* column widths: fixed for the small ones, source and target share the rest */
  table.rules :global(.c-sel) {
    inline-size: 2.5rem;
    padding-inline-end: 0;
    text-align: center;
  }
  table.rules :global(.c-drag) {
    inline-size: 1.75rem;
    padding-inline: 0;
  }
  table.rules :global(.c-status) {
    inline-size: 13.5rem;
  }
  table.rules :global(.c-source) {
    inline-size: 34%;
  }
  table.rules :global(.c-target) {
    inline-size: 28%;
  }
  table.rules :global(.c-code) {
    inline-size: 4rem;
  }
  table.rules :global(.c-group) {
    inline-size: 6.5rem;
  }
  table.rules :global(.c-hits) {
    inline-size: 7.5rem;
  }
  table.rules :global(.c-last) {
    inline-size: 8rem;
  }
  table.rules :global(.c-origin) {
    inline-size: 5.75rem;
  }
  table.rules :global(.c-priority) {
    inline-size: 4.5rem;
  }
  table.rules :global(.c-act) {
    inline-size: 2.75rem;
    text-align: end;
  }
  /* narrower containers (sidebar open, tablet): collapse secondary columns */
  @container (max-width: 76rem) {
    table.rules :global(.c-origin),
    table.rules :global(.c-priority) {
      display: none;
    }
  }
  @container (max-width: 62rem) {
    table.rules {
      min-inline-size: 0;
    }
    table.rules :global(.c-group),
    table.rules :global(.c-last) {
      display: none;
    }
    /* status column: switch plus icon-only badges (the label stays in the DOM and the tooltip) */
    table.rules :global(.c-status) {
      inline-size: 7.5rem;
    }
    table.rules :global(.c-status .badge) {
      font-size: 0;
      padding: 0.1875rem;
    }
  }
  @container (max-width: 46rem) {
    table.rules :global(.c-hits) {
      display: none;
    }
    /* keep the state and the first problem; further problem badges live in the editor */
    table.rules :global(.c-status .cell > :nth-child(n + 4)) {
      display: none;
    }
    table.rules :global(.c-source) {
      inline-size: 46%;
    }
    table.rules :global(.c-target) {
      inline-size: 38%;
    }
  }
</style>
