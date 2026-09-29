<script lang="ts">
  import type { Snippet } from 'svelte';
  import { Loader2 } from 'lucide-svelte';

  interface Props {
    variant?: 'primary' | 'outline' | 'secondary' | 'ghost' | 'link' | 'danger' | 'destructive';
    size?: 'default' | 'sm' | 'icon' | 'icon-xs';
    loading?: boolean;
    href?: string;
    class?: string;
    children?: Snippet;
    [key: string]: unknown;
  }
  let { variant = 'outline', size = 'sm', loading = false, href, class: cls = '', children, ...rest }: Props = $props();
  const classes = $derived(
    ['btn', variant, size === 'sm' ? 'sm' : size === 'icon' ? 'icon' : size === 'icon-xs' ? 'icon xs' : '', cls].filter(Boolean).join(' '),
  );
</script>

{#if href}
  <a class={classes} {href} {...rest}>{#if children}{@render children()}{/if}</a>
{:else}
  <button type="button" class={classes} aria-busy={loading || undefined} {...rest} disabled={(rest.disabled as boolean) || loading}>
    {#if loading}<Loader2 size={14} class="spin" />{/if}
    {#if children}{@render children()}{/if}
  </button>
{/if}
