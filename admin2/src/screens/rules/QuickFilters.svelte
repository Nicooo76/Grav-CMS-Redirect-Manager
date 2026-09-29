<script lang="ts">
  import { t, locale } from '../../lib/i18n.svelte';
  import { formatNumber } from '../../lib/format';
  import { rules } from '../../lib/state/rules.svelte';
  import type { RuleBadge } from '../../lib/types';

  const ORDER: RuleBadge[] = ['chain', 'loop', 'conflict', 'dead_target', 'unused', 'expired', 'scheduled', 'disabled'];
  const VARIANT: Record<string, string> = { chain: 'warn', loop: 'bad', conflict: 'warn', dead_target: 'bad', unused: '', expired: 'muted', scheduled: 'info', disabled: 'muted' };

  // Zero-value rule: only badges that exist in the data are offered.
  const items = $derived(ORDER.map((b) => ({ badge: b, n: rules.meta?.counts?.[b] ?? 0 })).filter((i) => i.n > 0 || rules.list.badge === i.badge));
</script>

{#if items.length}
  <div class="row wrap qf" role="group" aria-label={t('RULES.QUICK_FILTERS')}>
    <span class="text-xs muted">{t('RULES.NEEDS_ATTENTION')}</span>
    {#each items as it (it.badge)}
      <button
        type="button"
        class="badge {VARIANT[it.badge]} qf-btn"
        aria-pressed={rules.list.badge === it.badge}
        onclick={() => rules.setList({ badge: rules.list.badge === it.badge ? '' : it.badge })}
      >
        {t(`BADGE.${it.badge.toUpperCase()}`)}
        <span class="num">{formatNumber(it.n, locale())}</span>
      </button>
    {/each}
  </div>
{/if}

<style>
  .qf {
    gap: 0.375rem;
  }
  .qf-btn {
    cursor: pointer;
    border: 1px solid transparent;
    padding-block: 0.1875rem;
  }
  .qf-btn:hover {
    border-color: color-mix(in srgb, currentColor 35%, transparent);
  }
  .qf-btn[aria-pressed='true'] {
    border-color: currentColor;
    box-shadow: inset 0 0 0 1px currentColor;
  }
  .qf-btn.outline {
    border-color: var(--border);
  }
</style>
