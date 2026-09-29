<script lang="ts">
  import { importIssueText } from '../../lib/issue-text';
  import { ArrowRight, CircleX, Copy, TriangleAlert } from 'lucide-svelte';
  import Badge from '../../lib/ui/Badge.svelte';
  import MatchChip from '../../lib/ui/MatchChip.svelte';
  import { t, locale, dir } from '../../lib/i18n.svelte';
  import { formatNumber } from '../../lib/format';
  import { linkClick } from '../../lib/router.svelte';
  import { isDuplicate } from '../../lib/import-preview';
  import { statusVariant } from '../../lib/tester';
  import type { ImportRow } from '../../lib/types';

  interface Props {
    rows: ImportRow[];
  }
  let { rows }: Props = $props();

  const rtl = $derived(dir() === 'rtl');
  const state = (r: ImportRow): 'error' | 'warn' | 'dup' | 'ok' => (r.errors.length ? 'error' : r.warnings.length ? 'warn' : isDuplicate(r) ? 'dup' : 'ok');
</script>

<div class="table-wrap">
  <table class="table pv">
    <caption class="sr-only">{t('IMPORTEXPORT.TABLE_CAPTION')}</caption>
    <thead>
      <tr>
        <th scope="col" class="num">{t('IMPORTEXPORT.COL_LINE')}</th>
        <th scope="col">{t('IMPORTEXPORT.COL_RULE')}</th>
        <th scope="col">{t('IMPORTEXPORT.COL_STATUS')}</th>
        <th scope="col">{t('IMPORTEXPORT.COL_MATCH')}</th>
        <th scope="col">{t('IMPORTEXPORT.COL_NOTES')}</th>
      </tr>
    </thead>
    <tbody>
      {#each rows as r, ri (ri)}
        {@const s = state(r)}
        <tr class="row-{s}">
          <td class="num muted">{formatNumber(r.line, locale())}</td>
          <td class="rule">
            {#if r.rule}
              <div class="pair">
                <span class="mono">{r.rule.source || '∅'}</span>
                <span class="arrow" class:flip={rtl} aria-hidden="true"><ArrowRight size={13} /></span>
                <span class="mono">{r.rule.target || '∅'}</span>
              </div>
            {:else}
              <span class="mono raw" title={r.raw}>{r.raw}</span>
            {/if}
          </td>
          <td>{#if r.rule}<Badge variant={statusVariant(r.rule.status)}>{r.rule.status}</Badge>{/if}</td>
          <td>{#if r.rule}<MatchChip type={r.rule.match_type} />{/if}</td>
          <td class="notes">
            {#each r.errors as e, i (i)}
              <div class="note err"><CircleX size={14} aria-hidden="true" /><span><span class="sr-only">{t('IMPORTEXPORT.ISSUE_ERROR')}: </span>{importIssueText(e)}</span></div>
            {/each}
            {#each r.warnings as w, i (i)}
              <div class="note warn"><TriangleAlert size={14} aria-hidden="true" /><span><span class="sr-only">{t('IMPORTEXPORT.ISSUE_WARNING')}: </span>{importIssueText(w)}</span></div>
            {/each}
            {#if r.duplicate_of}
              <div class="note dup">
                <Copy size={14} aria-hidden="true" />
                <span>
                  {t('IMPORTEXPORT.DUP_OF')} <a class="mono" href="#/rules/{encodeURIComponent(r.duplicate_of)}" onclick={linkClick}>{r.duplicate_of}</a>
                </span>
              </div>
            {:else if r.duplicate_in_file}
              <div class="note dup"><Copy size={14} aria-hidden="true" /><span>{t('IMPORTEXPORT.DUP_IN_FILE')}</span></div>
            {/if}
          </td>
        </tr>
      {/each}
    </tbody>
  </table>
</div>

<style>
  .pv td {
    vertical-align: top;
  }
  .pair {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 0.25rem 0.5rem;
    font-size: var(--rm-text-xs);
  }
  .pair .mono {
    overflow-wrap: anywhere;
  }
  .arrow {
    color: var(--muted-foreground);
    display: inline-flex;
    align-self: center;
  }
  .arrow.flip {
    transform: scaleX(-1);
  }
  .raw {
    font-size: var(--rm-text-xs);
    overflow-wrap: anywhere;
  }
  .rule {
    min-inline-size: 14rem;
  }
  .notes {
    min-inline-size: 12rem;
    font-size: var(--rm-text-xs);
  }
  .note {
    display: flex;
    gap: 0.375rem;
    align-items: flex-start;
    line-height: 1.125rem;
  }
  .note :global(svg) {
    flex: none;
    margin-block-start: 2px;
  }
  .note.err {
    color: var(--rm-bad-fg);
  }
  .note.warn {
    color: var(--rm-warn-fg);
  }
  .note.dup {
    color: var(--rm-info-fg);
  }
  .note a {
    color: inherit;
    text-decoration: underline;
  }
  tr.row-error > td {
    background: color-mix(in srgb, var(--rm-bad-fg) 8%, transparent);
  }
  tr.row-warn > td {
    background: color-mix(in srgb, var(--rm-warn-fg) 8%, transparent);
  }
  tr.row-dup > td {
    background: color-mix(in srgb, var(--rm-info-fg) 6%, transparent);
  }
  tr.row-error > td:first-child,
  tr.row-warn > td:first-child,
  tr.row-dup > td:first-child {
    position: relative;
  }
  tr.row-error > td:first-child::before,
  tr.row-warn > td:first-child::before,
  tr.row-dup > td:first-child::before {
    content: '';
    position: absolute;
    inset-block: 0;
    inset-inline-start: 0;
    inline-size: 3px;
  }
  tr.row-error > td:first-child::before {
    background: var(--rm-bad-fg);
  }
  tr.row-warn > td:first-child::before {
    background: var(--rm-warn-fg);
  }
  tr.row-dup > td:first-child::before {
    background: var(--rm-info-fg);
  }
</style>
