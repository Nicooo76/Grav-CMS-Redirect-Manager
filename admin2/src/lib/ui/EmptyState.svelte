<script lang="ts">
  import type { Snippet } from 'svelte';
  import type { IconComponent } from '../icons';
  interface Props {
    icon?: IconComponent;
    title: string;
    text?: string;
    children?: Snippet;
    actions?: Snippet;
    class?: string;
  }
  let { icon: Icon, title, text, children, actions, class: cls = '' }: Props = $props();
</script>

<div class="empty {cls}">
  {#if Icon}<span class="ico" aria-hidden="true"><Icon size={20} /></span>{/if}
  <h3>{title}</h3>
  {#if text}<p>{text}</p>{/if}
  {#if children}{@render children()}{/if}
  {#if actions}<div class="row wrap" style="margin-block-start:0.5rem;justify-content:center">{@render actions()}</div>{/if}
</div>
