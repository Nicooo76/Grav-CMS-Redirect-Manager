<script lang="ts">
  import type { Snippet } from 'svelte';
  import { HelpCircle } from 'lucide-svelte';
  import { anchored, clickOutside } from '../actions';
  import { uniqueId } from '../dom';
  import { dir } from '../i18n.svelte';

  interface Props {
    label: string;
    children: Snippet;
    width?: string;
  }
  let { label, children, width = '20rem' }: Props = $props();
  let open = $state(false);
  let btn: HTMLButtonElement | undefined = $state();
  const id = uniqueId('info');
</script>

<button
  bind:this={btn}
  type="button"
  class="btn ghost icon xs"
  aria-label={label}
  aria-expanded={open}
  aria-controls={open ? id : undefined}
  onclick={() => (open = !open)}
  onkeydown={(e) => {
    if (e.key === 'Escape' && open) {
      e.stopPropagation();
      open = false;
    }
  }}
>
  <HelpCircle size={14} />
</button>
{#if open}
  <div
    {id}
    class="popover rich"
    role="dialog"
    tabindex="-1"
    aria-label={label}
    style="inline-size:min({width}, calc(100vw - 1rem))"
    use:anchored={{ anchor: () => btn, rtl: dir() === 'rtl' }}
    use:clickOutside={{ fn: () => (open = false), ignore: () => [btn] }}
    onkeydown={(e) => {
      if (e.key === 'Escape') {
        e.stopPropagation();
        open = false;
        btn?.focus();
      }
    }}
  >
    {@render children()}
  </div>
{/if}
