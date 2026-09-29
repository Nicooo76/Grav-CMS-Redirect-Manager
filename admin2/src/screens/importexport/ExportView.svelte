<script lang="ts">
  import { onMount } from 'svelte';
  import { Download, TriangleAlert } from 'lucide-svelte';
  import Button from '../../lib/ui/Button.svelte';
  import Checkbox from '../../lib/ui/Checkbox.svelte';
  import Field from '../../lib/ui/Field.svelte';
  import Select from '../../lib/ui/Select.svelte';
  import SiteConfigPanel from './SiteConfigPanel.svelte';
  import { formats, formatDesc, formatLabel, loadFormats } from './formats.svelte';
  import { api } from '../../lib/api';
  import { downloadBlob } from '../../lib/download';
  import { describeError } from '../../lib/errors';
  import { t } from '../../lib/i18n.svelte';
  import { skippedLines, sortExportFormats } from '../../lib/import-preview';
  import { toast } from '../../lib/state/notify.svelte';
  import type { ExportResult, GroupsResult } from '../../lib/types';

  let onlyEnabled = $state(false);
  let group = $state('');
  let status = $state('');
  let groups = $state<string[]>([]);
  let busyId = $state<string | null>(null);
  let error = $state<string | null>(null);
  let last = $state<{ label: string; skipped: unknown[] } | null>(null);

  const list = $derived(sortExportFormats(formats.list));
  const groupOptions = $derived([{ value: '', label: t('IMPORTEXPORT.EXPORT_GROUP_ALL') }, ...groups.map((g) => ({ value: g, label: g }))]);
  const statusOptions = $derived([
    { value: '', label: t('IMPORTEXPORT.EXPORT_STATUS_ALL') },
    ...[301, 302, 307, 308, 410, 451, 200].map((c) => ({ value: String(c), label: `${c} ${t(`STATUS.${c}`)}` })),
  ]);
  const skipped = $derived(last ? skippedLines(last.skipped) : { lines: [], more: 0 });

  onMount(() => {
    void loadFormats();
    api
      .get<GroupsResult>('/redirects/groups')
      .then(({ data }) => (groups = (data?.groups ?? []).map((g) => g.name).filter(Boolean)))
      .catch(() => {
        /* the group filter just stays empty */
      });
  });

  async function download(id: string, label: string) {
    if (busyId) return;
    busyId = id;
    error = null;
    last = null;
    try {
      const { data } = await api.get<ExportResult>('/redirects/export', {
        format: id,
        only_enabled: onlyEnabled || undefined,
        group: group || undefined,
        status: status || undefined,
      });
      const skippedItems = Array.isArray(data.skipped) ? data.skipped : [];
      if (!data.content || !data.content.trim()) {
        toast.info(t('IMPORTEXPORT.EXPORT_EMPTY'));
        return;
      }
      downloadBlob(data.content, data.filename || `redirects.${id}`, data.mime || 'text/plain');
      toast.success(t('IMPORTEXPORT.EXPORT_DONE', { filename: data.filename || `redirects.${id}` }));
      if (skippedItems.length) last = { label, skipped: skippedItems };
    } catch (e) {
      error = describeError(e);
      toast.error(error);
    } finally {
      busyId = null;
    }
  }
</script>

<div class="rm-export">
  <section class="card" aria-labelledby="rm-export-h">
    <div class="card-h">
      <h2 id="rm-export-h">{t('IMPORTEXPORT.EXPORT_TITLE')}</h2>
    </div>
    <div class="card-b stack">
      <p class="muted intro">{t('IMPORTEXPORT.EXPORT_TEXT')}</p>

      <div class="filters" role="group" aria-label={t('IMPORTEXPORT.EXPORT_FILTERS')}>
        <Field label={t('IMPORTEXPORT.EXPORT_GROUP')} class="f-select">
          {#snippet children({ id })}
            <Select {id} bind:value={group} options={groupOptions} />
          {/snippet}
        </Field>
        <Field label={t('IMPORTEXPORT.EXPORT_STATUS')} class="f-select">
          {#snippet children({ id })}
            <Select {id} bind:value={status} options={statusOptions} />
          {/snippet}
        </Field>
        <Checkbox bind:checked={onlyEnabled} label={t('IMPORTEXPORT.EXPORT_ONLY_ENABLED')} />
      </div>

      {#if error}
        <div class="banner bad" role="alert">
          <TriangleAlert size={16} />
          <div class="b-body"><div class="b-title">{t('IMPORTEXPORT.EXPORT_FAILED')}</div>{error}</div>
        </div>
      {/if}

      <div aria-live="polite">
        {#if last && last.skipped.length}
          <div class="banner warn">
            <TriangleAlert size={16} />
            <div class="b-body">
              <div class="b-title">{t('IMPORTEXPORT.EXPORT_SKIPPED_TITLE', { n: last.skipped.length, label: last.label })}</div>
              <ul class="skipped">
                {#each skipped.lines as line, i (i)}<li>{line}</li>{/each}
              </ul>
              {#if skipped.more > 0}<p class="more">{t('IMPORTEXPORT.EXPORT_SKIPPED_MORE', { n: skipped.more })}</p>{/if}
            </div>
          </div>
        {/if}
      </div>

      <ul class="formats">
        {#each list as f (f.id)}
          {@const label = formatLabel(f)}
          {@const desc = formatDesc(f)}
          <li class="fmt">
            <div class="fmt-body">
              <div class="fmt-name">{label}</div>
              {#if desc}<div class="muted text-xs">{desc}</div>{/if}
            </div>
            <Button loading={busyId === f.id} disabled={busyId !== null && busyId !== f.id} onclick={() => download(f.id, label)}>
              <Download size={14} />{t('IMPORTEXPORT.DOWNLOAD', { label })}
            </Button>
          </li>
        {/each}
      </ul>
    </div>
  </section>

  <SiteConfigPanel />
</div>

<style>
  .rm-export {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    max-inline-size: 64rem;
  }
  h2 {
    font-size: var(--rm-text-sm);
    font-weight: 600;
    margin: 0;
  }
  .intro {
    margin: 0;
    font-size: var(--rm-text-sm);
  }
  .filters {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 0.75rem 1rem;
  }
  .filters :global(.f-select) {
    min-inline-size: 11rem;
  }
  .filters :global(.check) {
    block-size: 2rem;
  }
  .formats {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(18rem, 1fr));
    gap: 0.5rem;
  }
  .fmt {
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 0.75rem;
    border: 1px solid var(--border);
    border-radius: var(--rm-r-md);
    background: var(--card);
  }
  .fmt-name {
    font-size: var(--rm-text-sm);
    font-weight: 600;
    margin-block-end: 0.125rem;
  }
  .fmt :global(.btn) {
    align-self: flex-start;
  }
  .skipped {
    margin: 0.375rem 0 0;
    padding-inline-start: 1.125rem;
  }
  .skipped li {
    overflow-wrap: anywhere;
    font-family: var(--rm-mono);
    font-size: var(--rm-text-2xs);
  }
  .more {
    margin: 0.25rem 0 0;
  }
</style>
