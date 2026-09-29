<script lang="ts">
  import { onMount } from 'svelte';
  import { ArrowRight, ArrowLeft } from 'lucide-svelte';
  import Button from '../../lib/ui/Button.svelte';
  import { deepActive, focusables, uniqueId } from '../../lib/dom';
  import { PREVIEW_ROWS, formatScore } from '../../lib/suggestions';
  import { dir, locale, t } from '../../lib/i18n.svelte';
  import type { StoredSuggestion } from '../../lib/types';

  interface Props {
    rows: StoredSuggestion[];
    /** gets focus back on close; falls back to the element focused when the dialog opened */
    returnTo?: HTMLElement | null;
    busy: boolean;
    onconfirm: () => void;
    oncancel: () => void;
  }
  let { rows, returnTo = null, busy, onconfirm, oncancel }: Props = $props();

  const uid = uniqueId('bp');
  let box: HTMLElement | undefined = $state();
  let opener: HTMLElement | null = null;
  const shown = $derived(rows.slice(0, PREVIEW_ROWS));
  const more = $derived(Math.max(0, rows.length - PREVIEW_ROWS));
  const rtl = $derived(dir() === 'rtl');

  onMount(() => {
    opener = deepActive();
    box?.querySelector<HTMLElement>('button[data-primary]')?.focus();
    return () => {
      const back = returnTo?.isConnected ? returnTo : opener;
      if (back?.isConnected) back.focus();
    };
  });

  function onkeydown(e: KeyboardEvent) {
    if (e.key === 'Escape') {
      e.preventDefault();
      e.stopPropagation();
      if (!busy) oncancel();
    } else if (e.key === 'Tab' && box) {
      const els = focusables(box);
      if (els.length === 0) return;
      const first = els[0]!;
      const last = els[els.length - 1]!;
      const a = deepActive(box.getRootNode() as ShadowRoot);
      if (e.shiftKey && (a === first || a === box)) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && a === last) {
        e.preventDefault();
        first.focus();
      }
    }
  }
</script>

<div class="backdrop dlg-backdrop">
  <div
    bind:this={box}
    class="dlg preview"
    role="dialog"
    aria-modal="true"
    aria-labelledby="{uid}-title"
    aria-describedby="{uid}-text"
    tabindex="-1"
    {onkeydown}
  >
    <h3 id="{uid}-title">{t('SUGGESTIONS.BULK_PREVIEW_TITLE', { n: rows.length })}</h3>
    <p id="{uid}-text">{t('SUGGESTIONS.BULK_PREVIEW_TEXT')}</p>
    <!-- svelte-ignore a11y_no_noninteractive_tabindex -->
    <div class="scroll" role="region" aria-label={t('SUGGESTIONS.BULK_LIST_LABEL')} tabindex="0">
      <table class="table">
        <thead>
          <tr>
            <th scope="col">{t('SUGGESTIONS.COL_PATH')}</th>
            <th scope="col">{t('SUGGESTIONS.COL_TARGET')}</th>
            <th scope="col" class="num">{t('SUGGESTIONS.COL_SCORE')}</th>
          </tr>
        </thead>
        <tbody>
          {#each shown as r (r.id)}
            <tr>
              <td class="mono cut" title={r.path}>{r.path}</td>
              <td class="mono cut" title={r.target}>
                <span class="arrow" aria-hidden="true">{#if rtl}<ArrowLeft size={12} />{:else}<ArrowRight size={12} />{/if}</span>{r.target}
              </td>
              <td class="num">{formatScore(r.score, locale())}</td>
            </tr>
          {/each}
        </tbody>
      </table>
      {#if more > 0}<p class="more text-xs muted">{t('SUGGESTIONS.BULK_MORE', { n: more })}</p>{/if}
    </div>
    <div class="dlg-actions">
      <Button size="default" disabled={busy} onclick={oncancel}>{t('COMMON.CANCEL')}</Button>
      <Button variant="primary" size="default" loading={busy} data-primary onclick={onconfirm}>{t('SUGGESTIONS.BULK_CONFIRM', { n: rows.length })}</Button>
    </div>
  </div>
</div>

<style>
  .backdrop {
    z-index: 50;
  }
  .preview {
    max-inline-size: 46rem;
    display: flex;
    flex-direction: column;
    max-block-size: min(40rem, calc(100dvh - 2rem));
  }
  .scroll {
    flex: 1 1 auto;
    min-block-size: 6rem;
    margin-block-start: 1rem;
    overflow: auto;
    border: 1px solid var(--border);
    border-radius: var(--rm-r-md);
  }
  .scroll :global(th) {
    position: sticky;
    inset-block-start: 0;
    z-index: 1;
    background: color-mix(in srgb, var(--muted) 45%, var(--card));
  }
  .scroll :global(td) {
    padding-block: 0.375rem;
    font-size: var(--rm-text-xs);
  }
  .cut {
    max-inline-size: 16rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .arrow {
    display: inline-block;
    margin-inline-end: 0.375rem;
    color: var(--muted-foreground);
    vertical-align: -1px;
  }
  .more {
    margin: 0;
    padding: 0.5rem 0.75rem;
    border-block-start: 1px solid var(--border);
  }
  .scroll :global(tbody tr:hover) {
    background: transparent;
  }
</style>
