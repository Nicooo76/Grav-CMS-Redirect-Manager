<script lang="ts">
  import { Check, Minus } from 'lucide-svelte';
  import MatchChip from '../../lib/ui/MatchChip.svelte';
  import { t, locale } from '../../lib/i18n.svelte';
  import { formatNumber } from '../../lib/format';
  import { linkClick } from '../../lib/router.svelte';
  import { matchedIndex, reasonCode, truncateTrace } from '../../lib/tester';
  import type { TraceStep } from '../../lib/types';

  interface Props {
    trace: TraceStep[];
    /** the rule that produced the result, to highlight its row */
    winner: string | null;
  }
  let { trace, winner }: Props = $props();

  let showAll = $state(false);
  const cut = $derived(truncateTrace(trace, showAll));
  const hit = $derived(matchedIndex(trace, winner));

  function reasonText(reason: string): string {
    const code = reasonCode(reason);
    return code ? t(`TESTER.REASON_${code.toUpperCase()}`) : reason;
  }
</script>

<div class="table-wrap">
  <table class="table trace">
    <caption class="sr-only">{t('TESTER.TRACE_CAPTION', { n: trace.length })}</caption>
    <thead>
      <tr>
        <th scope="col" class="num">#</th>
        <th scope="col">{t('TESTER.COL_RULE')}</th>
        <th scope="col" class="num">{t('TESTER.COL_PRIORITY')}</th>
        <th scope="col">{t('TESTER.COL_MATCHED')}</th>
        <th scope="col">{t('TESTER.COL_REASON')}</th>
        <th scope="col">{t('TESTER.COL_PATH')}</th>
      </tr>
    </thead>
    <tbody>
      {#each cut.rows as row, i (i)}
        <tr class:hit={i === hit}>
          <td class="num muted">{formatNumber(i + 1, locale())}</td>
          <td>
            <div class="rule-cell">
              <a class="mono src" href="#/rules/{encodeURIComponent(row.rule_id)}" onclick={linkClick} title={row.source}>{row.source}</a>
              <MatchChip type={row.match_type} />
            </div>
          </td>
          <td class="num">{formatNumber(row.priority, locale())}</td>
          <td>
            {#if row.matched}
              <span class="yes"><Check size={14} aria-hidden="true" />{t('COMMON.YES')}</span>
            {:else}
              <span class="no"><Minus size={14} aria-hidden="true" />{t('COMMON.NO')}</span>
            {/if}
          </td>
          <td class="reason">{reasonText(row.reason)}</td>
          <td><span class="mono text-xs path">{row.path}</span></td>
        </tr>
      {/each}
    </tbody>
  </table>
</div>

{#if trace.length > 20}
  <div class="more">
    <button type="button" class="btn ghost sm" aria-expanded={showAll} onclick={() => (showAll = !showAll)}>
      {showAll ? t('TESTER.TRACE_FEWER') : t('TESTER.TRACE_ALL', { n: trace.length })}
    </button>
  </div>
{/if}

<style>
  .trace td {
    vertical-align: top;
  }
  .rule-cell {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.375rem;
  }
  .src {
    font-size: var(--rm-text-xs);
    overflow-wrap: anywhere;
    color: var(--foreground);
    text-decoration: none;
  }
  .src:hover {
    color: var(--primary);
    text-decoration: underline;
  }
  .path {
    overflow-wrap: anywhere;
  }
  .reason {
    min-inline-size: 12rem;
    font-size: var(--rm-text-xs);
  }
  .yes,
  .no {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    font-size: var(--rm-text-xs);
    white-space: nowrap;
  }
  .yes {
    color: var(--rm-ok-fg);
    font-weight: 600;
  }
  .no {
    color: var(--muted-foreground);
  }
  tr.hit > td {
    background: color-mix(in srgb, var(--rm-ok-fg) 9%, transparent);
  }
  tr.hit > td:first-child {
    position: relative;
  }
  tr.hit > td:first-child::before {
    content: '';
    position: absolute;
    inset-block: 0;
    inset-inline-start: 0;
    inline-size: 3px;
    background: var(--rm-ok-fg);
  }
  .more {
    padding: 0.5rem 0.75rem;
    border-block-start: 1px solid color-mix(in srgb, var(--border) 60%, transparent);
  }
</style>
