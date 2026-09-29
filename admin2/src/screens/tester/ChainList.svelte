<script lang="ts">
  import { linkClick } from '../../lib/router.svelte';
  import Badge from '../../lib/ui/Badge.svelte';
  import { t } from '../../lib/i18n.svelte';
  import type { ChainStep } from '../../lib/tester';

  interface Props {
    steps: ChainStep[];
  }
  let { steps }: Props = $props();
</script>

<ol class="chain" aria-label={t('TESTER.CHAIN_LABEL')}>
  {#each steps as s (s.n)}
    <li class="step" class:terminal={s.terminal} class:loops={s.loops}>
      <span class="n" aria-hidden="true">{s.n}</span>
      <div class="body">
        <div class="url mono" title={s.url}>{s.url}</div>
        <div class="meta">
          {#if s.status !== null}
            <Badge variant={s.variant}>{s.status}</Badge>
          {:else if s.loops}
            <Badge variant="bad">{t('TESTER.STEP_LOOP')}</Badge>
          {/if}
          {#if s.ruleId}
            <a class="rule-link mono" href="#/rules/{encodeURIComponent(s.ruleId)}" onclick={linkClick} title={t('TESTER.OPEN_RULE')}>{s.ruleId}</a>
          {/if}
          {#if s.terminal}<span class="muted text-xs">{s.loops ? t('TESTER.STEP_LOOP_HINT') : t('TESTER.STEP_FINAL')}</span>{/if}
        </div>
      </div>
    </li>
  {/each}
</ol>

<style>
  .chain {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
  }
  .step {
    position: relative;
    display: flex;
    gap: 0.75rem;
    padding-block-end: 0.875rem;
  }
  .step:last-child {
    padding-block-end: 0;
  }
  /* the connector between two steps */
  .step:not(:last-child)::before {
    content: '';
    position: absolute;
    inset-inline-start: 0.6875rem;
    inset-block: 1.5rem 0.125rem;
    inline-size: 1px;
    background: var(--border);
  }
  .n {
    flex: none;
    inline-size: 1.375rem;
    block-size: 1.375rem;
    display: inline-grid;
    place-items: center;
    border-radius: 999px;
    border: 1px solid var(--border);
    background: var(--card);
    font-size: var(--rm-text-2xs);
    font-weight: 600;
    color: var(--muted-foreground);
    font-variant-numeric: tabular-nums;
  }
  .terminal .n {
    border-color: var(--primary);
    color: var(--primary);
  }
  .loops .n {
    border-color: var(--rm-bad-fg);
    color: var(--rm-bad-fg);
  }
  .body {
    min-inline-size: 0;
    flex: 1 1 auto;
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
  }
  .url {
    font-size: var(--rm-text-xs);
    overflow-wrap: anywhere;
    line-height: 1.375rem;
  }
  .meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
  }
  .rule-link {
    color: var(--rm-info-fg);
    font-size: var(--rm-text-2xs);
    max-inline-size: 16rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
</style>
