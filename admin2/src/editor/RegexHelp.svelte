<script lang="ts">
  import InfoPopover from '../lib/ui/InfoPopover.svelte';
  import { t } from '../lib/i18n.svelte';
  import type { MatchType } from '../lib/types';

  let { matchType, captures = {} }: { matchType: MatchType; captures?: Record<string, string> } = $props();
  const entries = $derived(Object.entries(captures));
  const capName = (k: string) => (/^\d+$/.test(k) ? '$' + k : '{' + k + '}');
</script>

<InfoPopover label={t('EDITOR.REGEX_HELP_LABEL')} width="26rem">
  {#if matchType === 'regex'}
    <div class="stack" style="gap:0.5rem">
      <strong>{t('EDITOR.REGEX_TITLE')}</strong>
      <p class="muted">{t('EDITOR.REGEX_INTRO')}</p>
      <table class="ex">
        <caption class="sr-only">{t('EDITOR.REGEX_EXAMPLES')}</caption>
        <thead><tr><th>{t('EDITOR.REGEX_SOURCE')}</th><th>{t('EDITOR.REGEX_TARGET')}</th></tr></thead>
        <tbody>
          <tr><td class="mono">^/blog/(\d{'{4}'})/(.*)$</td><td class="mono">/journal/$1/$2</td></tr>
          <tr><td class="mono">^/product/(?&lt;slug&gt;[^/]+)$</td><td class="mono">/shop/{'{slug}'}</td></tr>
          <tr><td class="mono">^/(de|en)/about$</td><td class="mono">/$1/company</td></tr>
        </tbody>
      </table>
      <ul class="muted" style="margin:0;padding-inline-start:1.1rem">
        <li>{t('EDITOR.REGEX_NO_DELIMITERS')}</li>
        <li>{t('EDITOR.REGEX_PATH_ONLY')}</li>
        <li>{t('EDITOR.REGEX_ANCHORS')}</li>
      </ul>
    </div>
  {:else}
    <div class="stack" style="gap:0.5rem">
      <strong>{t('EDITOR.WILDCARD_TITLE')}</strong>
      <p class="muted">{t('EDITOR.WILDCARD_INTRO')}</p>
      <table class="ex">
        <thead><tr><th>{t('EDITOR.REGEX_SOURCE')}</th><th>{t('EDITOR.REGEX_TARGET')}</th></tr></thead>
        <tbody>
          <tr><td class="mono">/blog/*</td><td class="mono">/journal/$1</td></tr>
          <tr><td class="mono">/*/print</td><td class="mono">/$1</td></tr>
        </tbody>
      </table>
    </div>
  {/if}
  <div style="margin-block-start:0.75rem">
    <strong>{t('EDITOR.CAPTURES')}</strong>
    {#if entries.length}
      <dl class="cap">
        {#each entries as [k, v] (k)}
          <dt class="mono">{capName(k)}</dt>
          <dd class="mono">{v}</dd>
        {/each}
      </dl>
    {:else}
      <p class="muted">{t('EDITOR.CAPTURES_NONE')}</p>
    {/if}
  </div>
</InfoPopover>

<style>
  .ex {
    inline-size: 100%;
    border-collapse: collapse;
    font-size: var(--rm-text-2xs);
  }
  .ex th {
    text-align: start;
    color: var(--muted-foreground);
    font-weight: 500;
    padding: 0.125rem 0.375rem 0.25rem 0;
  }
  .ex td {
    padding: 0.125rem 0.375rem 0.125rem 0;
    border-block-start: 1px solid var(--border);
    word-break: break-all;
  }
  .cap {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 0.125rem 0.75rem;
    margin: 0.25rem 0 0;
  }
  .cap dd {
    margin: 0;
  }
</style>
