<script lang="ts">
  import { onMount, tick } from 'svelte';
  import { Info } from 'lucide-svelte';
  import PrivacyCard from './settings/PrivacyCard.svelte';
  import { blueprintAvailable, blueprintForm, hostConfigUrl, BLUEPRINT_TAG } from './settings/blueprint';
  import { t } from '../lib/i18n.svelte';

  const PLUGIN = 'redirect-manager';
  const CONFIG_PATH = 'user/config/plugins/redirect-manager.yaml';

  let available = $state(blueprintAvailable());
  let tab = $state('');
  let formBox: HTMLElement | undefined = $state();
  const configUrl = hostConfigUrl(PLUGIN);

  onMount(() => {
    // Admin may register the element a moment after this page loaded
    if (!available && typeof customElements !== 'undefined') {
      void customElements.whenDefined(BLUEPRINT_TAG).then(() => (available = true));
    }
  });

  async function showLogging() {
    // set to '' first so that choosing the same tab again still counts as a change
    tab = '';
    await tick();
    tab = 'log_tab';
    formBox?.scrollIntoView({ block: 'start' });
  }

  function openHostConfig(e: MouseEvent) {
    if (!configUrl || !window.__GRAV_NAVIGATE) return;
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    e.preventDefault();
    window.__GRAV_NAVIGATE(configUrl);
  }
</script>

<div class="rm-settings">
  {#if available}
    <div bind:this={formBox} class="form-box" aria-label={t('SETTINGS.FORM_LABEL')} role="region">
      <div class="host" use:blueprintForm={{ plugin: PLUGIN, tab }}></div>
    </div>
  {:else}
    <div class="banner" role="note">
      <Info size={16} />
      <div class="b-body">
        <div class="b-title">{t('SETTINGS.NO_FORM_TITLE')}</div>
        <p>{t('SETTINGS.NO_FORM_TEXT')}</p>
        <p><code class="mono">{CONFIG_PATH}</code></p>
        {#if configUrl}
          <p><a href={configUrl} onclick={openHostConfig}>{t('SETTINGS.OPEN_CONFIG')}</a></p>
        {/if}
      </div>
    </div>
  {/if}

  <PrivacyCard onlogging={available ? showLogging : undefined} />
</div>

<style>
  .rm-settings {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    container-type: inline-size;
    max-inline-size: 64rem;
  }
  .banner p {
    margin: 0.25rem 0 0;
  }
  .banner a {
    color: var(--rm-info-fg);
    text-decoration: underline;
  }
  .banner code {
    font-size: var(--rm-text-xs);
  }
</style>
