<script lang="ts">
  import { ArrowRight, Pencil } from 'lucide-svelte';
  import Badge from '../../lib/ui/Badge.svelte';
  import Button from '../../lib/ui/Button.svelte';
  import MatchChip from '../../lib/ui/MatchChip.svelte';
  import Skeleton from '../../lib/ui/Skeleton.svelte';
  import { t, dir } from '../../lib/i18n.svelte';
  import { linkClick } from '../../lib/router.svelte';
  import { statusVariant } from '../../lib/tester';
  import type { Rule } from '../../lib/types';

  interface Props {
    ruleId: string;
    rule: Rule | null;
    loading: boolean;
    captures: Record<string, string>;
  }
  let { ruleId, rule, loading, captures }: Props = $props();
  const caps = $derived(Object.entries(captures ?? {}));
</script>

<div class="rule">
  {#if loading}
    <Skeleton w="60%" h="1rem" />
  {:else if rule}
    <div class="line">
      <span class="mono src" title={rule.source}>{rule.source}</span>
      <span class="arrow" class:flip={dir() === 'rtl'} aria-hidden="true"><ArrowRight size={14} /></span>
      <span class="mono dst" title={rule.target}>{rule.target || t('TESTER.NO_TARGET')}</span>
      <Badge variant={statusVariant(rule.status)}>{rule.status}</Badge>
      <MatchChip type={rule.match_type} />
      <span class="spacer"></span>
      <Button href="#/rules/{encodeURIComponent(rule.id)}" onclick={linkClick}><Pencil size={14} />{t('TESTER.EDIT_RULE')}</Button>
    </div>
  {:else}
    <div class="line">
      <span class="muted">{t('TESTER.RULE_GONE')}</span>
      <span class="mono text-xs">{ruleId}</span>
    </div>
  {/if}

  {#if caps.length}
    <dl class="caps" aria-label={t('TESTER.CAPTURES')}>
      {#each caps as [k, v] (k)}
        <div>
          <dt class="mono">{k}</dt>
          <dd class="mono">{v}</dd>
        </div>
      {/each}
    </dl>
  {/if}
</div>

<style>
  .rule {
    display: flex;
    flex-direction: column;
    gap: 0.625rem;
  }
  .line {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
  }
  .src,
  .dst {
    font-size: var(--rm-text-xs);
    overflow-wrap: anywhere;
  }
  .arrow {
    color: var(--muted-foreground);
    display: inline-flex;
  }
  .arrow.flip {
    transform: scaleX(-1);
  }
  .caps {
    margin: 0;
    display: flex;
    flex-wrap: wrap;
    gap: 0.375rem;
  }
  .caps > div {
    display: inline-flex;
    align-items: baseline;
    gap: 0.375rem;
    padding: 0.125rem 0.5rem;
    border: 1px solid var(--border);
    border-radius: var(--rm-r-sm);
    background: color-mix(in srgb, var(--muted) 35%, transparent);
    font-size: var(--rm-text-2xs);
  }
  .caps dt {
    color: var(--muted-foreground);
  }
  .caps dd {
    margin: 0;
    overflow-wrap: anywhere;
  }
</style>
