<script lang="ts">
  import Select from '../../lib/ui/Select.svelte';
  import Field from '../../lib/ui/Field.svelte';
  import Button from '../../lib/ui/Button.svelte';
  import { t } from '../../lib/i18n.svelte';
  import { rules } from '../../lib/state/rules.svelte';
  import { UNUSED_OPTIONS, activeFilterCount } from '../../lib/rules-query';

  let { id }: { id: string } = $props();

  const all = $derived({ value: '', label: t('COMMON.ALL') });
  const matchOptions = $derived([all, ...(['exact', 'wildcard', 'regex'] as const).map((v) => ({ value: v, label: t(`MATCH.${v.toUpperCase()}`) }))]);
  const stateOptions = $derived([all, ...(['active', 'disabled', 'expired', 'scheduled'] as const).map((v) => ({ value: v, label: t(`BADGE.${v.toUpperCase()}`) }))]);
  const statusOptions = $derived([all, ...[301, 302, 307, 308, 410, 451, 200].map((v) => ({ value: String(v), label: `${v} · ${t(`STATUS.${v}`)}` }))]);
  const groupOptions = $derived([all, ...rules.groups.filter(Boolean).map((g) => ({ value: g, label: g }))]);
  const originOptions = $derived([all, ...(['manual', 'import', 'auto', 'suggestion'] as const).map((v) => ({ value: v, label: t(`ORIGIN.${v.toUpperCase()}`) }))]);
  const unusedOptions = $derived([all, ...UNUSED_OPTIONS.map((d) => ({ value: String(d), label: t('RULES.NO_HITS_SINCE_DAYS', { n: d }) }))]);
  const badgeOptions = $derived([
    all,
    ...(['chain', 'loop', 'conflict', 'dead_target', 'unused', 'expired', 'scheduled', 'disabled'] as const).map((v) => ({ value: v, label: t(`BADGE.${v.toUpperCase()}`) })),
  ]);
</script>

<div {id} class="card filters" role="group" aria-label={t('RULES.FILTERS')}>
  <div class="field-grid">
    <Field label={t('RULES.FILTER_MATCH')}>
      {#snippet children({ id })}
        <Select {id} value={rules.list.match_type} options={matchOptions} onchange={(e: Event) => rules.setList({ match_type: (e.currentTarget as HTMLSelectElement).value as any })} />
      {/snippet}
    </Field>
    <Field label={t('RULES.FILTER_STATE')}>
      {#snippet children({ id })}
        <Select {id} value={rules.list.state} options={stateOptions} onchange={(e: Event) => rules.setList({ state: (e.currentTarget as HTMLSelectElement).value as any })} />
      {/snippet}
    </Field>
    <Field label={t('RULES.FILTER_STATUS')}>
      {#snippet children({ id })}
        <Select
          {id}
          value={String(rules.list.status)}
          options={statusOptions}
          onchange={(e: Event) => {
            const v = (e.currentTarget as HTMLSelectElement).value;
            rules.setList({ status: v === '' ? '' : Number(v) });
          }}
        />
      {/snippet}
    </Field>
    <Field label={t('RULES.FILTER_GROUP')}>
      {#snippet children({ id })}
        <Select {id} value={rules.list.group} options={groupOptions} onchange={(e: Event) => rules.setList({ group: (e.currentTarget as HTMLSelectElement).value })} />
      {/snippet}
    </Field>
    <Field label={t('RULES.FILTER_ORIGIN')}>
      {#snippet children({ id })}
        <Select {id} value={rules.list.origin} options={originOptions} onchange={(e: Event) => rules.setList({ origin: (e.currentTarget as HTMLSelectElement).value })} />
      {/snippet}
    </Field>
    <Field label={t('RULES.FILTER_UNUSED')}>
      {#snippet children({ id })}
        <Select
          {id}
          value={String(rules.list.unused_days)}
          options={unusedOptions}
          onchange={(e: Event) => {
            const v = (e.currentTarget as HTMLSelectElement).value;
            rules.setList({ unused_days: v === '' ? '' : Number(v) });
          }}
        />
      {/snippet}
    </Field>
    <Field label={t('RULES.FILTER_BADGE')}>
      {#snippet children({ id })}
        <Select {id} value={rules.list.badge} options={badgeOptions} onchange={(e: Event) => rules.setList({ badge: (e.currentTarget as HTMLSelectElement).value as any })} />
      {/snippet}
    </Field>
  </div>
  {#if activeFilterCount(rules.list) > 0}
    <div class="row" style="margin-block-start:0.75rem">
      <Button variant="ghost" onclick={() => rules.resetFilters()}>{t('RULES.CLEAR_FILTERS')}</Button>
    </div>
  {/if}
</div>

<style>
  .filters {
    padding: 1rem;
  }
</style>
