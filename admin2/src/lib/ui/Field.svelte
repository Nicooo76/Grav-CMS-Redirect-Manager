<script lang="ts">
  import type { Snippet } from 'svelte';
  import { uniqueId } from '../dom';
  import { t } from '../i18n.svelte';

  interface Props {
    label: string;
    hint?: string;
    error?: string | null;
    optional?: boolean;
    id?: string;
    labelExtra?: Snippet;
    children: Snippet<[{ id: string; describedBy: string | undefined; invalid: boolean }]>;
    class?: string;
  }
  let { label, hint, error = null, optional = false, id = uniqueId('f'), labelExtra, children, class: cls = '' }: Props = $props();
  const describedBy = $derived([error ? `${id}-err` : '', hint ? `${id}-hint` : ''].filter(Boolean).join(' ') || undefined);
</script>

<div class="field {cls}">
  <div class="lbl">
    <label for={id}>{label}</label>
    {#if optional}<span class="opt">{t('COMMON.OPTIONAL')}</span>{/if}
    {#if labelExtra}{@render labelExtra()}{/if}
  </div>
  {@render children({ id, describedBy, invalid: !!error })}
  {#if error}<div class="err" id="{id}-err" role="alert">{error}</div>{/if}
  {#if hint}<div class="hint" id="{id}-hint">{hint}</div>{/if}
</div>
