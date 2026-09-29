<script lang="ts" module>
  export interface ComboItem {
    value: string;
    label: string;
    description?: string;
    data?: unknown;
  }
</script>

<script lang="ts">
  import type { Snippet } from 'svelte';
  import { ChevronDown, Loader2 } from 'lucide-svelte';
  import { anchored, clickOutside } from '../actions';
  import { uniqueId } from '../dom';
  import { t, dir } from '../i18n.svelte';

  interface Props {
    value?: string;
    items: ComboItem[];
    /** typed text becomes the value (e.g. group names) */
    allowCustom?: boolean;
    /** filter items locally (default true unless onsearch is given) */
    localFilter?: boolean;
    /** called (debounced by the parent) with the typed text */
    onsearch?: (q: string) => void;
    onselect?: (item: ComboItem | null, text: string) => void;
    loading?: boolean;
    placeholder?: string;
    id?: string;
    mono?: boolean;
    invalid?: boolean;
    describedBy?: string;
    emptyText?: string;
    option?: Snippet<[ComboItem]>;
    size?: 'default' | 'lg';
    [key: string]: unknown;
  }
  let {
    value = $bindable(''),
    items,
    allowCustom = false,
    localFilter,
    onsearch,
    onselect,
    loading = false,
    placeholder = '',
    id: idProp,
    mono = false,
    invalid = false,
    describedBy,
    emptyText,
    option,
    size = 'default',
    ...rest
  }: Props = $props();

  let open = $state(false);
  let active = $state(0);
  let input: HTMLInputElement | undefined = $state();
  let text = $state(value);
  // svelte-ignore state_referenced_locally
  const id = idProp ?? uniqueId('cb');
  const listId = `${id}-list`;

  // keep the field in sync when the parent changes value
  $effect(() => {
    if (value !== text && !open) text = value;
  });

  const filtered = $derived.by(() => {
    const doFilter = localFilter ?? !onsearch;
    if (!doFilter || !text) return items;
    const q = text.toLowerCase();
    const list = items.filter((i) => i.label.toLowerCase().includes(q) || i.value.toLowerCase().includes(q));
    return list.length ? list : [];
  });

  $effect(() => {
    void filtered.length;
    active = Math.min(active, Math.max(0, filtered.length - 1));
  });

  function pick(i: ComboItem) {
    value = i.value;
    text = i.value;
    open = false;
    onselect?.(i, i.value);
  }
  function oninput() {
    open = true;
    active = 0;
    if (allowCustom) value = text;
    onsearch?.(text);
  }
  function onkeydown(e: KeyboardEvent) {
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      if (!open) open = true;
      else active = (active + 1) % Math.max(1, filtered.length);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      if (!open) open = true;
      else active = (active - 1 + filtered.length) % Math.max(1, filtered.length);
    } else if (e.key === 'Enter') {
      if (open && filtered[active]) {
        e.preventDefault();
        pick(filtered[active]);
      }
    } else if (e.key === 'Escape') {
      if (open) {
        e.preventDefault();
        e.stopPropagation();
        open = false;
      }
    } else if (e.key === 'Tab') {
      open = false;
    }
  }
  function onblur() {
    // committing typed text happens through allowCustom's oninput; nothing to do
    if (!allowCustom && text !== value) text = value;
  }
</script>

<span class="input-wrap" style="display:block">
  <input
    bind:this={input}
    {id}
    type="text"
    role="combobox"
    class="input {size === 'lg' ? 'lg' : ''} {mono ? 'mono' : ''} has-trail"
    autocomplete="off"
    aria-autocomplete="list"
    aria-expanded={open}
    aria-controls={open ? listId : undefined}
    aria-activedescendant={open && filtered[active] ? `${id}-opt-${active}` : undefined}
    aria-invalid={invalid || undefined}
    aria-describedby={describedBy}
    {placeholder}
    bind:value={text}
    onfocus={() => {
      if (onsearch || items.length) open = true;
    }}
    onclick={() => (open = true)}
    {oninput}
    {onkeydown}
    {onblur}
    {...rest}
  />
  <span class="trail" style="pointer-events:none;color:var(--muted-foreground);inset-inline-end:0.625rem;display:flex">
    {#if loading}<Loader2 size={14} class="spin" />{:else}<ChevronDown size={14} />{/if}
  </span>
</span>

{#if open}
  <div
    id={listId}
    class="popover"
    role="listbox"
    aria-label={placeholder || t('COMMON.SUGGESTIONS')}
    style="padding:0.25rem;max-block-size:16rem;overflow-y:auto"
    use:anchored={{ anchor: () => input, match: true, rtl: dir() === 'rtl' }}
    use:clickOutside={{ fn: () => (open = false), ignore: () => [input] }}
  >
    {#each filtered as it, i (it.value + i)}
      <!-- svelte-ignore a11y_click_events_have_key_events -->
      <div
        id="{id}-opt-{i}"
        role="option"
        tabindex="-1"
        aria-selected={i === active}
        class="menu-item"
        style="white-space:normal;{i === active ? 'background:color-mix(in srgb, var(--accent) 70%, transparent)' : ''}"
        onpointerdown={(e) => e.preventDefault()}
        onclick={() => pick(it)}
        onmousemove={() => (active = i)}
      >
        {#if option}{@render option(it)}{:else}
          <span class="grow">
            <span class={mono ? 'mono' : ''}>{it.label}</span>
            {#if it.description}<span class="muted text-xs" style="display:block">{it.description}</span>{/if}
          </span>
        {/if}
      </div>
    {:else}
      <div class="muted text-xs" style="padding:0.5rem 0.625rem" role="status">
        {#if loading}{t('COMMON.LOADING')}{:else if allowCustom && text}{t('COMMON.USE_TYPED', { value: text })}{:else}{emptyText ?? t('COMMON.NO_RESULTS')}{/if}
      </div>
    {/each}
  </div>
{/if}
