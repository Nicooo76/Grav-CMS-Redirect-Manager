<script lang="ts">
  import { Plus, X } from 'lucide-svelte';
  import Input from '../../lib/ui/Input.svelte';
  import { uniqueId } from '../../lib/dom';
  import { t } from '../../lib/i18n.svelte';

  export interface Row {
    id: string;
    key: string;
    value: string;
  }

  interface Props {
    rows: Row[];
    /** group name, e.g. "Headers" */
    label: string;
    keyLabel: string;
    valueLabel: string;
    keyPlaceholder?: string;
    addLabel: string;
  }
  let { rows = $bindable(), label, keyLabel, valueLabel, keyPlaceholder = '', addLabel }: Props = $props();

  function add() {
    rows.push({ id: uniqueId('kv'), key: '', value: '' });
  }
  function remove(id: string) {
    const i = rows.findIndex((r) => r.id === id);
    if (i !== -1) rows.splice(i, 1);
  }
</script>

<div class="kv" role="group" aria-label={label}>
  <div class="lbl">{label}</div>
  {#each rows as row, i (row.id)}
    <div class="kv-row">
      <Input bind:value={row.key} mono placeholder={keyPlaceholder} aria-label="{keyLabel} {i + 1}" autocomplete="off" spellcheck="false" />
      <Input bind:value={row.value} mono aria-label="{valueLabel} {i + 1}" autocomplete="off" spellcheck="false" />
      <button type="button" class="btn ghost icon" aria-label={t('TESTER.ROW_REMOVE', { label: `${label} ${i + 1}` })} onclick={() => remove(row.id)}>
        <X size={14} />
      </button>
    </div>
  {/each}
  <div><button type="button" class="btn ghost sm" onclick={add}><Plus size={14} />{addLabel}</button></div>
</div>

<style>
  .kv {
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
    align-items: flex-start;
  }
  .lbl {
    font-size: var(--rm-text-xs);
    font-weight: 500;
  }
  .kv-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1.4fr) auto;
    gap: 0.375rem;
    align-items: center;
    inline-size: 100%;
  }
</style>
