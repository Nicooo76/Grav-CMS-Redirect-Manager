<script lang="ts">
  import type { Snippet } from 'svelte';
  import { ChevronDown } from 'lucide-svelte';

  interface Props {
    title: string;
    summary?: string;
    open?: boolean;
    children: Snippet;
    class?: string;
  }
  let { title, summary, open = $bindable(false), children, class: cls = '' }: Props = $props();
</script>

<details class="disc {cls}" bind:open>
  <summary>
    <span class="chev" aria-hidden="true"><ChevronDown size={14} /></span>
    <span class="t">{title}</span>
    {#if summary}<span class="s muted text-xs truncate">{summary}</span>{/if}
  </summary>
  <div class="disc-body">{@render children()}</div>
</details>

<style>
  .disc {
    border-block-start: 1px solid var(--border);
  }
  summary {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 0.125rem;
    cursor: pointer;
    list-style: none;
    border-radius: var(--rm-r-md);
    font-size: var(--rm-text-sm);
    font-weight: 600;
  }
  summary::-webkit-details-marker {
    display: none;
  }
  summary:hover .t {
    color: var(--primary);
  }
  .chev {
    display: flex;
    color: var(--muted-foreground);
    transition: rotate 0.15s;
    rotate: -90deg;
  }
  :global([dir='rtl']) .chev {
    rotate: 90deg;
  }
  details[open] > summary .chev {
    rotate: 0deg;
  }
  .s {
    font-weight: 400;
    margin-inline-start: auto;
    max-inline-size: 60%;
  }
  .disc-body {
    padding: 0.25rem 0.125rem 1.25rem;
    display: flex;
    flex-direction: column;
    gap: 0.875rem;
  }
</style>
