<script lang="ts">
  import { Plus, Trash2 } from 'lucide-svelte';
  import Select from '../lib/ui/Select.svelte';
  import Field from '../lib/ui/Field.svelte';
  import ChipsInput from '../lib/ui/ChipsInput.svelte';
  import Button from '../lib/ui/Button.svelte';
  import { t } from '../lib/i18n.svelte';
  import type { RuleForm } from '../lib/editor-form';

  let { form = $bindable() }: { form: RuleForm } = $props();

  type Row = { k: string; v: string };
  const toRows = (p: Record<string, string | null>): Row[] => Object.entries(p).map(([k, v]) => ({ k, v: v ?? '' }));
  let rows = $state<Row[]>(toRows(form.query_params));
  let lastJson = JSON.stringify(form.query_params);

  // external replacement of the form (load, reset): re-read the rows
  $effect(() => {
    const json = JSON.stringify(form.query_params);
    if (json !== lastJson) {
      lastJson = json;
      rows = toRows(form.query_params);
    }
  });
  function commit() {
    const p: Record<string, string | null> = {};
    for (const r of rows) if (r.k.trim()) p[r.k.trim()] = r.v === '' ? null : r.v;
    lastJson = JSON.stringify(p);
    form.query_params = p;
  }

  const modes = $derived(
    (['ignore', 'pass', 'exact', 'params'] as const).map((m) => ({ value: m, label: t(`EDITOR.QM_${m.toUpperCase()}`) })),
  );
</script>

<Field label={t('EDITOR.QUERY_MODE')} hint={t(`EDITOR.QM_${form.query_mode.toUpperCase()}_HELP`)}>
  {#snippet children({ id, describedBy })}
    <Select {id} bind:value={form.query_mode} options={modes} aria-describedby={describedBy} />
  {/snippet}
</Field>

{#if form.query_mode === 'params'}
  <div class="stack" style="gap:0.5rem" role="group" aria-label={t('EDITOR.QUERY_PARAMS')}>
    <div class="lbl text-xs" style="font-weight:500">{t('EDITOR.QUERY_PARAMS')}</div>
    {#each rows as row, i (i)}
      <div class="row">
        <input class="input mono" aria-label={t('EDITOR.PARAM_NAME')} placeholder={t('EDITOR.PARAM_NAME')} bind:value={row.k} oninput={commit} />
        <input class="input mono" aria-label={t('EDITOR.PARAM_VALUE')} placeholder={t('EDITOR.PARAM_ANY')} bind:value={row.v} oninput={commit} />
        <Button
          variant="ghost"
          size="icon"
          aria-label={t('EDITOR.REMOVE_ROW')}
          onclick={() => {
            rows.splice(i, 1);
            commit();
          }}><Trash2 size={14} /></Button
        >
      </div>
    {/each}
    <div>
      <Button
        onclick={() => {
          rows.push({ k: '', v: '' });
        }}><Plus size={14} />{t('EDITOR.ADD_PARAM')}</Button
      >
    </div>
  </div>
{/if}

<Field label={t('EDITOR.QUERY_IGNORE')} hint={t('EDITOR.QUERY_IGNORE_HELP')} optional>
  {#snippet children({ id, describedBy })}
    <ChipsInput {id} bind:values={form.query_ignore} label={t('EDITOR.QUERY_IGNORE')} placeholder="utm_*, ref" mono {describedBy} />
  {/snippet}
</Field>
