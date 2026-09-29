<script lang="ts">
  import { ArrowRight, Loader2 } from 'lucide-svelte';
  import Badge from '../lib/ui/Badge.svelte';
  import RegexHelp from './RegexHelp.svelte';
  import { t, dir } from '../lib/i18n.svelte';
  import type { MatchType, PreviewResult } from '../lib/types';

  interface Props {
    sample: string;
    matchType: MatchType;
    hasSource: boolean;
    loading: boolean;
    result: PreviewResult['result'] | null;
    /** the API answered for this sample; with `result` null that means no rule matches it */
    answered?: boolean;
    unavailable?: boolean;
    ontouch: () => void;
  }
  let { sample = $bindable(), matchType, hasSource, loading, result, answered = false, unavailable = false, ontouch }: Props = $props();
  const status = $derived(result?.status);
  /** Reason codes of the API that have a text; anything else is not shown raw. */
  const REASONS = ['other_rule'];
  const reason = $derived(result?.reason && REASONS.includes(result.reason) ? t(`EDITOR.PREVIEW_REASON_${result.reason.toUpperCase()}`) : '');
  const statusVariant = $derived(status === 410 || status === 451 ? 'bad' : status === 200 ? 'muted' : 'info');
</script>

<section class="preview" aria-labelledby="pv-title">
  <div class="row">
    <label id="pv-title" class="lbl text-xs" for="rm-sample" style="font-weight:500">{t('EDITOR.PREVIEW_TITLE')}</label>
    <span class="spacer"></span>
    {#if loading}<Loader2 size={13} class="spin muted" aria-hidden="true" />{/if}
    {#if matchType !== 'exact'}<RegexHelp {matchType} captures={result?.captures ?? {}} />{/if}
  </div>
  <input
    id="rm-sample"
    class="input inline mono"
    bind:value={sample}
    spellcheck="false"
    autocomplete="off"
    placeholder="/blog/2024/example"
    aria-describedby="pv-result"
    oninput={ontouch}
  />
  <div id="pv-result" class="result" aria-live="polite" aria-atomic="true">
    {#if !hasSource}
      <span class="muted">{t('EDITOR.PREVIEW_EMPTY')}</span>
    {:else if unavailable}
      <span class="muted">{t('EDITOR.PREVIEW_UNAVAILABLE')}</span>
    {:else if !result && answered}
      <Badge variant="muted">{t('EDITOR.PREVIEW_NO_MATCH')}</Badge>
      <span class="muted">{t('EDITOR.PREVIEW_NO_RULE')}</span>
    {:else if !result}
      <span class="muted">{loading ? t('EDITOR.PREVIEW_WAIT') : t('EDITOR.PREVIEW_IDLE')}</span>
    {:else if result.matched}
      <span class="mono path">{sample}</span>
      <span class="arr"><span class="sr-only">{t('EDITOR.PREVIEW_ARROW')}</span>{#if dir() === 'rtl'}<ArrowRight size={13} style="transform:scaleX(-1)" aria-hidden="true" />{:else}<ArrowRight size={13} aria-hidden="true" />{/if}</span>
      <span class="mono path strong">{result.location || '—'}</span>
      <Badge variant={statusVariant}>{status}</Badge>
    {:else}
      <Badge variant="muted">{t('EDITOR.PREVIEW_NO_MATCH')}</Badge>
      {#if reason}<span class="muted">{reason}</span>{/if}
    {/if}
  </div>
</section>

<style>
  .preview {
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
    padding: 0.75rem;
    border: 1px solid var(--border);
    border-radius: var(--rm-r-md);
    background: color-mix(in srgb, var(--muted) 35%, transparent);
  }
  .result {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.375rem 0.5rem;
    min-block-size: 1.5rem;
    font-size: var(--rm-text-xs);
  }
  .path {
    word-break: break-all;
  }
  .strong {
    font-weight: 600;
  }
  .arr {
    display: inline-flex;
    color: var(--muted-foreground);
  }
</style>
