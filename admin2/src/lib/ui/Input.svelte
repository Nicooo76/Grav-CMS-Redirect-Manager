<script lang="ts">
  import type { Snippet } from 'svelte';
  import { X } from 'lucide-svelte';
  import { t } from '../i18n.svelte';

  interface Props {
    value?: string | number;
    type?: string;
    size?: 'default' | 'lg' | 'inline';
    mono?: boolean;
    lead?: Snippet;
    clearable?: boolean;
    muted?: boolean;
    class?: string;
    el?: HTMLInputElement | null;
    [key: string]: unknown;
  }
  let {
    value = $bindable(''),
    type = 'text',
    size = 'default',
    mono = false,
    lead,
    clearable = false,
    muted = false,
    class: cls = '',
    el = $bindable(null),
    ...rest
  }: Props = $props();
  const showClear = $derived(clearable && String(value ?? '') !== '');
</script>

<span class="input-wrap {muted ? 'muted-fill' : ''}">
  {#if lead}<span class="lead" aria-hidden="true">{@render lead()}</span>{/if}
  <input
    bind:this={el}
    bind:value
    {type}
    class="input {size === 'lg' ? 'lg' : ''} {size === 'inline' ? 'inline' : ''} {mono ? 'mono' : ''} {lead ? 'has-lead' : ''} {showClear ? 'has-trail' : ''} {cls}"
    {...rest}
  />
  {#if showClear}
    <button
      type="button"
      class="btn ghost icon xs trail"
      aria-label={t('COMMON.CLEAR')}
      onclick={() => {
        value = '';
        el?.focus();
        el?.dispatchEvent(new Event('input', { bubbles: true }));
      }}><X size={14} /></button
    >
  {/if}
</span>
