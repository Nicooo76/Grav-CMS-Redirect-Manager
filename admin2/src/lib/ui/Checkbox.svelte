<script lang="ts">
  import type { Snippet } from 'svelte';
  interface Props {
    checked?: boolean;
    indeterminate?: boolean;
    label?: string;
    children?: Snippet;
    class?: string;
    [key: string]: unknown;
  }
  let { checked = $bindable(false), indeterminate = false, label, children, class: cls = '', ...rest }: Props = $props();
  let el: HTMLInputElement | undefined = $state();
  $effect(() => {
    if (el) el.indeterminate = indeterminate;
  });
</script>

{#if label || children}
  <label class="check {cls}">
    <input bind:this={el} type="checkbox" class="checkbox" bind:checked {...rest} />
    {#if children}{@render children()}{:else}<span>{label}</span>{/if}
  </label>
{:else}
  <input bind:this={el} type="checkbox" class="checkbox {cls}" bind:checked {...rest} />
{/if}
