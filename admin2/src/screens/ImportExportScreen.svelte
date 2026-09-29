<script lang="ts">
  import ExportView from './importexport/ExportView.svelte';
  import ImportView from './importexport/ImportView.svelte';
  import { t } from '../lib/i18n.svelte';
  import { linkClick, router } from '../lib/router.svelte';
  import { can } from '../lib/state/app.svelte';
  import EmptyState from '../lib/ui/EmptyState.svelte';
  import Button from '../lib/ui/Button.svelte';
  import { Lock } from 'lucide-svelte';

  const isExport = $derived(router.route.name === 'export');
</script>

<div class="rm-ie">
  <nav class="sub" aria-label={t('IMPORTEXPORT.NAV_LABEL')}>
    <a href="#/import" class="sub-link" aria-current={!isExport ? 'page' : undefined} onclick={linkClick}>{t('IMPORTEXPORT.NAV_IMPORT')}</a>
    <a href="#/export" class="sub-link" aria-current={isExport ? 'page' : undefined} onclick={linkClick}>{t('IMPORTEXPORT.NAV_EXPORT')}</a>
  </nav>

  {#if isExport}
    <ExportView />
  {:else if !can.manage}
    <div class="card">
      <EmptyState icon={Lock} title={t('PERM.IMPORT_TITLE')} text={t('PERM.NEEDS_MANAGE')}>
        {#snippet actions()}
          <Button variant="outline" size="default" href="#/export" onclick={linkClick}>{t('IMPORTEXPORT.NAV_EXPORT')}</Button>
        {/snippet}
      </EmptyState>
    </div>
  {:else}
    <ImportView />
  {/if}
</div>

<style>
  .rm-ie {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    container-type: inline-size;
  }
  .sub {
    display: inline-flex;
    align-self: flex-start;
    gap: 0.125rem;
    padding: 0.125rem;
    border-radius: var(--rm-r-lg);
    background: var(--muted);
  }
  .sub-link {
    padding: 0.25rem 0.875rem;
    border-radius: var(--rm-r-md);
    font-size: var(--rm-text-xs);
    font-weight: 500;
    color: color-mix(in srgb, var(--muted-foreground) 75%, var(--foreground));
    text-decoration: none;
    line-height: 1.5rem;
  }
  .sub-link:hover {
    color: var(--foreground);
    text-decoration: none;
  }
  .sub-link[aria-current='page'] {
    background: var(--background);
    color: var(--foreground);
    box-shadow: var(--rm-shadow-sm);
  }
</style>
