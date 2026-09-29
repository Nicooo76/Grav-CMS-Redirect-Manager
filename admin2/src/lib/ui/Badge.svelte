<script lang="ts">
  import type { Snippet } from 'svelte';
  interface Props {
    variant?: 'default' | 'ok' | 'warn' | 'bad' | 'info' | 'muted' | 'outline' | 'count';
    animate?: boolean;
    class?: string;
    title?: string;
    children?: Snippet;
  }
  let { variant = 'default', animate = false, class: cls = '', title, children }: Props = $props();
</script>

<span class="badge {variant === 'default' ? '' : variant} {animate ? 'enter' : ''} {cls}" {title}>{#if children}{@render children()}{/if}</span>
