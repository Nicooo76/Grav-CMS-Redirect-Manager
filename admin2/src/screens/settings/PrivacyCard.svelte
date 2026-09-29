<script lang="ts">
  import { t } from '../../lib/i18n.svelte';

  interface Props {
    /** switches the settings form to its logging tab; absent when the form is not embedded */
    onlogging?: () => void;
  }
  let { onlogging }: Props = $props();
</script>

<section class="card" aria-labelledby="rm-privacy-h">
  <div class="card-h">
    <h2 id="rm-privacy-h">{t('SETTINGS.PRIVACY_TITLE')}</h2>
  </div>
  <div class="card-b stack">
    <p class="muted intro">{t('SETTINGS.PRIVACY_INTRO')}</p>
    <dl class="facts">
      <div>
        <dt>{t('SETTINGS.P_RULES_T')}</dt>
        <dd>{t('SETTINGS.P_RULES_D')} <code class="mono">user/data/redirect-manager/rules.yaml</code></dd>
      </div>
      <div>
        <dt>{t('SETTINGS.P_LOG_T')}</dt>
        <dd>{t('SETTINGS.P_LOG_D')}</dd>
      </div>
      <div>
        <dt>{t('SETTINGS.P_HITS_T')}</dt>
        <dd>{t('SETTINGS.P_HITS_D')}</dd>
      </div>
      <div>
        <dt>{t('SETTINGS.P_SUGGEST_T')}</dt>
        <dd>{t('SETTINGS.P_SUGGEST_D')}</dd>
      </div>
      <div>
        <dt>{t('SETTINGS.P_NEVER_T')}</dt>
        <dd>{t('SETTINGS.P_NEVER_D')}</dd>
      </div>
      <div>
        <dt>{t('SETTINGS.P_OFF_T')}</dt>
        <dd>
          {t('SETTINGS.P_OFF_D')}
          {#if onlogging}
            <button type="button" class="linklike" onclick={onlogging}>{t('SETTINGS.P_OFF_LINK')}</button>
          {/if}
        </dd>
      </div>
    </dl>
    <details class="more">
      <summary>{t('SETTINGS.P_FILES_SUMMARY')}</summary>
      <p class="muted text-xs">{t('SETTINGS.P_FILES_TEXT')}</p>
    </details>
  </div>
</section>

<style>
  h2 {
    font-size: var(--rm-text-sm);
    font-weight: 600;
    margin: 0;
  }
  .intro {
    margin: 0;
    font-size: var(--rm-text-sm);
  }
  .facts {
    margin: 0;
    display: flex;
    flex-direction: column;
  }
  .facts > div {
    display: grid;
    grid-template-columns: minmax(8rem, 12rem) minmax(0, 1fr);
    gap: 0.25rem 1.5rem;
    padding-block: 0.625rem;
    border-block-start: 1px solid color-mix(in srgb, var(--border) 70%, transparent);
    font-size: var(--rm-text-sm);
  }
  dt {
    font-weight: 500;
  }
  dd {
    margin: 0;
    color: var(--foreground);
    overflow-wrap: anywhere;
  }
  code {
    font-size: var(--rm-text-xs);
  }
  .linklike {
    padding: 0;
    border: 0;
    background: none;
    color: var(--rm-info-fg);
    font: inherit;
    text-decoration: underline;
    cursor: pointer;
    border-radius: var(--rm-r-sm);
  }
  .more > summary {
    cursor: pointer;
    font-size: var(--rm-text-xs);
    font-weight: 500;
    color: var(--muted-foreground);
  }
  .more p {
    margin: 0.5rem 0 0;
    max-inline-size: 46rem;
  }
  @container (max-width: 34rem) {
    .facts > div {
      grid-template-columns: minmax(0, 1fr);
    }
  }
</style>
