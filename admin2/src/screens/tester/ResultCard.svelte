<script lang="ts">
  import { Info, Plus, TriangleAlert } from 'lucide-svelte';
  import Badge from '../../lib/ui/Badge.svelte';
  import Button from '../../lib/ui/Button.svelte';
  import ChainList from './ChainList.svelte';
  import CopyButton from './CopyButton.svelte';
  import RuleMatch from './RuleMatch.svelte';
  import TraceTable from './TraceTable.svelte';
  import { t } from '../../lib/i18n.svelte';
  import { openEditor } from '../../lib/state/app.svelte';
  import { pathOfUrl, type Outcome } from '../../lib/tester';
  import type { Rule, TestResponse } from '../../lib/types';

  interface Props {
    resp: TestResponse;
    outcome: Outcome;
    rule: Rule | null;
    ruleLoading: boolean;
    testedUrl: string;
  }
  let { resp, outcome, rule, ruleLoading, testedUrl }: Props = $props();

  const headline = $derived(t(outcome.headlineKey, { status: outcome.status ?? '' }));
  const showFinal = $derived(outcome.kind === 'redirect');
  const showChain = $derived(resp.chain.length > 0);
  const trace = $derived(resp.trace ?? []);
  const winner = $derived(resp.result?.rule_id ?? null);
</script>

<section class="card result" aria-labelledby="rm-result-h">
  <div class="card-h">
    <h2 id="rm-result-h" class="sr-only">{t('TESTER.RESULT')}</h2>
    <Badge variant={outcome.variant}>{outcome.status ?? (outcome.kind === 'loop' ? t('TESTER.STEP_LOOP') : '!')}</Badge>
    <span class="headline">{headline}</span>
  </div>
  <div class="card-b stack">
    {#if showFinal}
      <div class="final">
        <span class="lbl">{t('TESTER.FINAL_URL')}</span>
        <code class="mono url">{outcome.finalUrl}</code>
        <CopyButton text={outcome.finalUrl} label={t('TESTER.COPY_URL')} />
      </div>
    {/if}

    {#if outcome.kind === 'found'}
      <p class="muted note">{t('TESTER.NOTE_PAGE_EXISTS')}</p>
    {:else if outcome.kind === 'notfound'}
      <p class="muted note">{t('TESTER.NOTE_NOT_FOUND')}</p>
      <div>
        <Button onclick={() => openEditor({ prefill: { source: pathOfUrl(testedUrl) } })}><Plus size={14} />{t('TESTER.CREATE_RULE')}</Button>
      </div>
    {:else if outcome.kind === 'passthrough'}
      <p class="muted note">{t('TESTER.NOTE_PASSTHROUGH')}</p>
    {:else if outcome.kind === 'gone'}
      <p class="muted note">{t('TESTER.NOTE_GONE')}</p>
    {:else if outcome.kind === 'legal'}
      <p class="muted note">{t('TESTER.NOTE_LEGAL')}</p>
    {:else if outcome.kind === 'excluded'}
      <p class="muted note">{t('TESTER.NOTE_EXCLUDED')}</p>
    {/if}
    {#if outcome.external}
      <p class="muted note">{t('TESTER.NOTE_EXTERNAL')}</p>
    {/if}

    {#if outcome.loop}
      <div class="banner bad">
        <TriangleAlert size={16} />
        <div class="b-body">{t('TESTER.NOTE_LOOP')}</div>
      </div>
    {:else if outcome.depth}
      <div class="banner warn">
        <TriangleAlert size={16} />
        <div class="b-body">{t('TESTER.NOTE_DEPTH', { n: resp.chain.length })}</div>
      </div>
    {:else if outcome.hops > 1}
      <div class="banner">
        <Info size={16} />
        <div class="b-body">{t('TESTER.NOTE_CHAIN', { n: outcome.hops })}</div>
      </div>
    {/if}
    {#if outcome.destinationMissing}
      <div class="banner warn">
        <TriangleAlert size={16} />
        <div class="b-body">{t('TESTER.NOTE_DEST_MISSING', { status: resp.final.status })}</div>
      </div>
    {/if}

    {#if showChain}
      <section class="sec" aria-labelledby="rm-chain-h">
        <h3 id="rm-chain-h">{t('TESTER.CHAIN')}</h3>
        <ChainList steps={outcome.steps} />
      </section>
    {/if}

    {#if winner}
      <section class="sec" aria-labelledby="rm-rule-h">
        <h3 id="rm-rule-h">{t('TESTER.MATCHED_RULE')}</h3>
        <RuleMatch ruleId={winner} {rule} loading={ruleLoading} captures={resp.result?.captures ?? {}} />
      </section>
    {/if}
  </div>

  {#if trace.length}
    <section class="trace-sec" aria-labelledby="rm-trace-h">
      <div class="trace-h">
        <h3 id="rm-trace-h">{t('TESTER.TRACE')}</h3>
        <p class="muted text-xs">{t('TESTER.TRACE_HINT')}</p>
      </div>
      <TraceTable {trace} {winner} />
    </section>
  {/if}
</section>

<style>
  .card-h {
    border-block-end: 1px solid var(--border);
  }
  .headline {
    font-size: var(--rm-text-base);
    font-weight: 600;
  }
  .card > .card-h + .card-b {
    padding-block-start: 1rem;
  }
  .final {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
  }
  .final .lbl {
    font-size: var(--rm-text-xs);
    color: var(--muted-foreground);
  }
  .url {
    font-size: var(--rm-text-sm);
    overflow-wrap: anywhere;
    min-inline-size: 0;
  }
  .note {
    margin: 0;
    font-size: var(--rm-text-sm);
  }
  .sec h3,
  .trace-h h3 {
    font-size: var(--rm-text-xs);
    font-weight: 600;
    margin: 0 0 0.5rem;
  }
  .trace-sec {
    border-block-start: 1px solid var(--border);
  }
  .trace-h {
    padding: 0.875rem 1rem 0.5rem;
  }
  .trace-h h3 {
    margin: 0;
  }
  .trace-h p {
    margin: 0.125rem 0 0;
  }
</style>
