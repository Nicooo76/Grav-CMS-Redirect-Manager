<script lang="ts">
  import { Power, PowerOff, Tag, FolderInput, Download, Trash2, X, ListOrdered } from 'lucide-svelte';
  import Button from '../../lib/ui/Button.svelte';
  import Menu, { type MenuItem } from '../../lib/ui/Menu.svelte';
  import { t } from '../../lib/i18n.svelte';
  import { rules } from '../../lib/state/rules.svelte';
  import { dialogs } from '../../lib/state/notify.svelte';
  import { downloadBlob } from '../../lib/download';
  import { rulesToCsv } from '../../lib/csv';

  const n = $derived(rules.selectedCount);

  const statusItems = $derived<MenuItem[]>(
    [301, 302, 307, 308, 410, 451, 200].map((code) => ({ label: `${code} · ${t(`STATUS.${code}`)}`, onselect: () => rules.bulk('set_status', code) })),
  );
  const moreItems = $derived<MenuItem[]>([
    { label: t('RULES.BULK_SET_GROUP'), icon: FolderInput, onselect: setGroup },
    { label: t('RULES.BULK_ADD_TAG'), icon: Tag, onselect: () => tagDialog('add_tag') },
    { label: t('RULES.BULK_REMOVE_TAG'), icon: Tag, onselect: () => tagDialog('remove_tag') },
    { separator: true, label: '' },
    { label: t('RULES.BULK_EXPORT_JSON'), icon: Download, onselect: () => exportSelected('json') },
    { label: t('RULES.BULK_EXPORT_CSV'), icon: Download, onselect: () => exportSelected('csv') },
  ]);

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
  function exportSelected(kind: 'json' | 'csv') {
    const selected = rules.selectedRules.map(({ stats: _s, badges: _b, issues: _i, ...r }) => r);
    if (kind === 'json') downloadBlob(JSON.stringify({ rules: selected }, null, 2), 'redirects-selected.json', 'application/json');
    else downloadBlob(rulesToCsv(selected as never), 'redirects-selected.csv', 'text/csv');
  }
</script>

<div class="bulk" role="toolbar" aria-label={t('RULES.BULK_LABEL')}>
  <span class="count" aria-live="polite">{t('RULES.SELECTED', { n })}</span>
  <Button onclick={() => rules.bulk('enable')}><Power size={14} />{t('RULES.BULK_ENABLE')}</Button>
  <Button onclick={() => rules.bulk('disable')}><PowerOff size={14} />{t('RULES.BULK_DISABLE')}</Button>
  <Menu items={statusItems} label={t('RULES.BULK_SET_STATUS')} buttonClass="btn outline sm">
    {#snippet trigger()}<ListOrdered size={14} />{t('RULES.BULK_SET_STATUS')}{/snippet}
  </Menu>
  <Menu items={moreItems} label={t('RULES.BULK_MORE')} buttonClass="btn outline sm">
    {#snippet trigger()}{t('RULES.BULK_MORE')}{/snippet}
  </Menu>
  <Button variant="danger" onclick={() => rules.remove(rules.selectedIds)}><Trash2 size={14} />{t('COMMON.DELETE')}</Button>
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
