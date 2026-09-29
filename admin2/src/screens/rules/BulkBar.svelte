<script lang="ts">
  import { onMount } from 'svelte';
  import { Power, PowerOff, Tag, FolderInput, Download, Trash2, X, ListOrdered } from 'lucide-svelte';
  import Button from '../../lib/ui/Button.svelte';
  import Menu, { type MenuItem } from '../../lib/ui/Menu.svelte';
  import { describeError } from '../../lib/errors';
  import { t } from '../../lib/i18n.svelte';
  import { requestExport, saveExport } from '../../lib/export';
  import { sortExportFormats } from '../../lib/import-preview';
  import { rules } from '../../lib/state/rules.svelte';
  import { can } from '../../lib/state/app.svelte';
  import { dialogs, toast } from '../../lib/state/notify.svelte';
  import { formats, formatLabel, loadFormats } from '../importexport/formats.svelte';

  const n = $derived(rules.selectedCount);
  let exporting = $state(false);

  onMount(() => void loadFormats());

  const statusItems = $derived<MenuItem[]>(
    [301, 302, 307, 308, 410, 451, 200].map((code) => ({ label: `${code} · ${t(`STATUS.${code}`)}`, onselect: () => rules.bulk('set_status', code) })),
  );
  const moreItems = $derived<MenuItem[]>([
    { label: t('RULES.BULK_SET_GROUP'), icon: FolderInput, onselect: setGroup },
    { label: t('RULES.BULK_ADD_TAG'), icon: Tag, onselect: () => tagDialog('add_tag') },
    { label: t('RULES.BULK_REMOVE_TAG'), icon: Tag, onselect: () => tagDialog('remove_tag') },
  ]);
  const exportItems = $derived<MenuItem[]>(sortExportFormats(formats.list).map((f) => ({ label: formatLabel(f), icon: Download, disabled: exporting, onselect: () => exportSelected(f.id) })));

  async function setGroup() {
    const res = await dialogs.form({
      title: t('RULES.BULK_SET_GROUP'),
      description: t('RULES.BULK_SET_GROUP_HELP', { n }),
      fields: [{ name: 'group', type: 'text', label: t('RULES.FILTER_GROUP'), placeholder: rules.groups.filter(Boolean).slice(0, 3).join(', ') }],
      submitLabel: t('COMMON.APPLY'),
    });
    if (res) await rules.bulk('set_group', String(res.group ?? '').trim());
  }
  async function tagDialog(action: 'add_tag' | 'remove_tag') {
    const res = await dialogs.form({
      title: action === 'add_tag' ? t('RULES.BULK_ADD_TAG') : t('RULES.BULK_REMOVE_TAG'),
      fields: [{ name: 'tag', type: 'text', label: t('RULES.TAG'), required: true }],
      submitLabel: t('COMMON.APPLY'),
    });
    const tag = String(res?.tag ?? '').trim();
    if (tag) await rules.bulk(action, tag);
  }
  /** The server builds the file (any format), limited to the selected rules. */
  async function exportSelected(format: string) {
    if (exporting) return;
    exporting = true;
    try {
      const data = await requestExport({ format, ids: rules.selectedIds });
      if (!saveExport(data, format)) toast.info(t('IMPORTEXPORT.EXPORT_EMPTY'));
      else toast.success(t('IMPORTEXPORT.EXPORT_DONE', { filename: data.filename || `redirects.${format}` }));
      const skipped = Array.isArray(data.skipped) ? data.skipped.length : 0;
      if (skipped > 0) toast.warning(t('RULES.BULK_EXPORT_SKIPPED', { n: skipped }));
    } catch (e) {
      toast.error(describeError(e));
    } finally {
      exporting = false;
    }
  }
</script>

<div class="bulk" role="toolbar" aria-label={t('RULES.BULK_LABEL')}>
  <span class="count" aria-live="polite">{t('RULES.SELECTED', { n })}</span>
  {#if can.manage}
    <Button onclick={() => rules.bulk('enable')}><Power size={14} />{t('RULES.BULK_ENABLE')}</Button>
    <Button onclick={() => rules.bulk('disable')}><PowerOff size={14} />{t('RULES.BULK_DISABLE')}</Button>
    <Menu items={statusItems} label={t('RULES.BULK_SET_STATUS')} buttonClass="btn outline sm">
      {#snippet trigger()}<ListOrdered size={14} />{t('RULES.BULK_SET_STATUS')}{/snippet}
    </Menu>
    <Menu items={moreItems} label={t('RULES.BULK_MORE')} buttonClass="btn outline sm">
      {#snippet trigger()}{t('RULES.BULK_MORE')}{/snippet}
    </Menu>
  {/if}
  <Menu items={exportItems} label={t('RULES.BULK_EXPORT')} buttonClass="btn outline sm">
    {#snippet trigger()}<Download size={14} />{t('RULES.BULK_EXPORT')}{/snippet}
  </Menu>
  {#if can.manage}
    <Button variant="danger" onclick={() => rules.remove(rules.selectedIds)}><Trash2 size={14} />{t('COMMON.DELETE')}</Button>
  {/if}
  <span class="spacer"></span>
  <Button variant="ghost" onclick={() => rules.clearSelection()} aria-label={t('RULES.CLEAR_SELECTION')}><X size={14} />{t('RULES.CLEAR_SELECTION')}</Button>
</div>

<style>
  .bulk {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 0.75rem;
    border-block-end: 1px solid var(--border);
    background: color-mix(in srgb, var(--primary) 7%, var(--card));
  }
  .count {
    font-weight: 600;
    font-size: var(--rm-text-xs);
    margin-inline-end: 0.25rem;
  }
</style>
