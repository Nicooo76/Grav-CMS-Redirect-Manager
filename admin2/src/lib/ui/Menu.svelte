<script lang="ts">
  import type { Snippet } from 'svelte';
  import type { IconComponent } from '../icons';
  import { MoreHorizontal } from 'lucide-svelte';
  import { anchored, clickOutside } from '../actions';
  import { uniqueId } from '../dom';
  import { dir } from '../i18n.svelte';

  export interface MenuItem {
    label: string;
    icon?: IconComponent;
    onselect?: () => void;
    danger?: boolean;
    disabled?: boolean;
    separator?: boolean;
  }
  interface Props {
    items: MenuItem[];
    label: string;
    icon?: IconComponent;
    trigger?: Snippet;
    buttonClass?: string;
  }
  let { items, label, icon: Icon = MoreHorizontal, trigger, buttonClass = 'btn ghost icon' }: Props = $props();

  let open = $state(false);
  let btn: HTMLButtonElement | undefined = $state();
  let pop: HTMLElement | undefined = $state();
  const id = uniqueId('menu');

  const actionable = $derived(items.filter((i) => !i.separator));

  function itemEls(): HTMLElement[] {
    return pop ? Array.from(pop.querySelectorAll<HTMLElement>('[role=menuitem]:not([aria-disabled=true])')) : [];
  }
  async function openMenu(focusLast = false) {
    open = true;
    await Promise.resolve();
    requestAnimationFrame(() => {
      const els = itemEls();
      (focusLast ? els[els.length - 1] : els[0])?.focus();
    });
  }
  function close(restore = true) {
    open = false;
    if (restore) btn?.focus();
  }
  function onBtnKey(e: KeyboardEvent) {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      openMenu(e.key === 'ArrowUp');
    }
  }
  function onPopKey(e: KeyboardEvent) {
    const els = itemEls();
    const i = els.indexOf(e.target as HTMLElement);
    if (e.key === 'Escape') {
      e.preventDefault();
      e.stopPropagation();
      close();
    } else if (e.key === 'ArrowDown') {
      e.preventDefault();
      els[(i + 1) % els.length]?.focus();
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      els[(i - 1 + els.length) % els.length]?.focus();
    } else if (e.key === 'Home') {
      e.preventDefault();
      els[0]?.focus();
    } else if (e.key === 'End') {
      e.preventDefault();
      els[els.length - 1]?.focus();
    } else if (e.key === 'Tab') {
      close(false);
    }
  }
  function select(it: MenuItem) {
    if (it.disabled) return;
    close();
    it.onselect?.();
  }
</script>

<button
  bind:this={btn}
  type="button"
  class={buttonClass}
  aria-haspopup="menu"
  aria-expanded={open}
  aria-controls={open ? id : undefined}
  aria-label={label}
  onclick={(e) => {
    e.stopPropagation();
    open ? close(false) : openMenu();
  }}
  onkeydown={onBtnKey}
>
  {#if trigger}{@render trigger()}{:else}<Icon size={16} />{/if}
</button>

{#if open}
  <div
    {id}
    bind:this={pop}
    class="popover"
    role="menu"
    aria-label={label}
    tabindex="-1"
    use:anchored={{ anchor: () => btn, rtl: dir() === 'rtl' }}
    use:clickOutside={{ fn: () => close(false), ignore: () => [btn] }}
    onkeydown={onPopKey}
  >
    {#each items as it, i (i)}
      {#if it.separator}
        <div class="menu-sep" role="separator"></div>
      {:else}
        <button
          type="button"
          role="menuitem"
          class="menu-item {it.danger ? 'danger' : ''}"
          aria-disabled={it.disabled || undefined}
          onclick={(e) => {
            e.stopPropagation();
            select(it);
          }}
        >
          {#if it.icon}<it.icon size={14} />{/if}
          {it.label}
        </button>
      {/if}
    {/each}
  </div>
{/if}
