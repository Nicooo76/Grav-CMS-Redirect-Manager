<script lang="ts">
  import { Plus, Trash2 } from 'lucide-svelte';
  import Select from '../lib/ui/Select.svelte';
  import Field from '../lib/ui/Field.svelte';
  import ChipsInput from '../lib/ui/ChipsInput.svelte';
  import Button from '../lib/ui/Button.svelte';
  import { t } from '../lib/i18n.svelte';
  import type { RuleForm } from '../lib/editor-form';
  import type { ConditionKind, ConditionOperator } from '../lib/types';

  let { form = $bindable() }: { form: RuleForm } = $props();

  const kinds = $derived((['header', 'cookie'] as ConditionKind[]).map((k) => ({ value: k, label: t(`EDITOR.COND_${k.toUpperCase()}`) })));
  const ops = $derived(
    (['exists', 'equals', 'contains', 'starts_with', 'regex'] as ConditionOperator[]).map((o) => ({ value: o, label: t(`EDITOR.OP_${o.toUpperCase()}`) })),
  );

  function toggleScheme(s: string, on: boolean) {
    const set = new Set(form.conditions.schemes);
    if (on) set.add(s);
    else set.delete(s);
    form.conditions.schemes = [...set];
  }
</script>

<div class="field-grid">
  <Field label={t('EDITOR.COND_HOSTS')} hint={t('EDITOR.COND_HOSTS_HELP')} optional>
    {#snippet children({ id, describedBy })}
      <ChipsInput {id} bind:values={form.conditions.hosts} label={t('EDITOR.COND_HOSTS')} placeholder="example.org" mono normalize={(s) => s.trim().toLowerCase()} {describedBy} />
    {/snippet}
  </Field>
  <Field label={t('EDITOR.COND_LANGUAGES')} hint={t('EDITOR.COND_LANGUAGES_HELP')} optional>
    {#snippet children({ id, describedBy })}
      <ChipsInput {id} bind:values={form.conditions.languages} label={t('EDITOR.COND_LANGUAGES')} placeholder="de, en" mono normalize={(s) => s.trim().toLowerCase()} {describedBy} />
    {/snippet}
  </Field>
</div>

<fieldset class="schemes">
  <legend class="lbl text-xs">{t('EDITOR.COND_SCHEMES')} <span class="muted" style="font-weight:400">{t('COMMON.OPTIONAL')}</span></legend>
  <div class="row" style="gap:1rem">
    {#each ['http', 'https'] as s (s)}
      <label class="check">
        <input type="checkbox" class="checkbox" checked={form.conditions.schemes.includes(s)} onchange={(e) => toggleScheme(s, (e.currentTarget as HTMLInputElement).checked)} />
        <span class="mono">{s}</span>
      </label>
    {/each}
  </div>
  <div class="hint muted text-xs">{t('EDITOR.COND_SCHEMES_HELP')}</div>
</fieldset>

<div class="stack" style="gap:0.5rem" role="group" aria-label={t('EDITOR.COND_RULES')}>
  <div class="lbl text-xs" style="font-weight:500">{t('EDITOR.COND_RULES')} <span class="muted" style="font-weight:400">{t('COMMON.OPTIONAL')}</span></div>
  {#each form.conditions.rules as c, i (i)}
    <div class="cond">
      <Select bind:value={c.kind} options={kinds} aria-label={t('EDITOR.COND_KIND')} />
      <input class="input mono" bind:value={c.name} aria-label={t('EDITOR.COND_NAME')} placeholder={c.kind === 'cookie' ? 'session' : 'Accept-Language'} />
      <Select bind:value={c.operator} options={ops} aria-label={t('EDITOR.COND_OPERATOR')} />
      {#if c.operator !== 'exists'}
        <input class="input mono" bind:value={c.value} aria-label={t('EDITOR.COND_VALUE')} placeholder={t('EDITOR.COND_VALUE')} />
      {:else}
        <span></span>
      {/if}
      <label class="check" title={t('EDITOR.COND_NEGATE_HELP')}>
        <input type="checkbox" class="checkbox" bind:checked={c.negate} />
        <span class="text-xs">{t('EDITOR.COND_NEGATE')}</span>
      </label>
      <Button variant="ghost" size="icon" aria-label={t('EDITOR.REMOVE_ROW')} onclick={() => form.conditions.rules.splice(i, 1)}><Trash2 size={14} /></Button>
    </div>
  {/each}
  <div>
    <Button onclick={() => form.conditions.rules.push({ kind: 'header', name: '', operator: 'exists', value: '', negate: false })}><Plus size={14} />{t('EDITOR.ADD_CONDITION')}</Button>
  </div>
</div>

<style>
  .schemes {
    border: 0;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
  }
  .schemes legend {
    padding: 0;
    margin-block-end: 0.375rem;
    font-weight: 500;
  }
  .cond {
    display: grid;
    grid-template-columns: 6.5rem minmax(0, 1fr) 8rem minmax(0, 1fr) auto auto;
    gap: 0.375rem;
    align-items: center;
  }
  @container (max-width: 40rem) {
    .cond {
      grid-template-columns: 1fr 1fr;
    }
  }
</style>
