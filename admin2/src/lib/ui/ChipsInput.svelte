<script lang="ts">
  import { X } from 'lucide-svelte';
  import { uniqueId } from '../dom';
  import { t } from '../i18n.svelte';

  interface Props {
    values: string[];
    id?: string;
    placeholder?: string;
    suggestions?: string[];
    label: string;
    mono?: boolean;
    /** transform typed text before it becomes a chip */
    normalize?: (s: string) => string;
    onchange?: (v: string[]) => void;
    describedBy?: string;
  }
  let { values = $bindable([]), id: idProp, placeholder = '', suggestions = [], label, mono = false, normalize = (s) => s.trim(), onchange, describedBy }: Props = $props();

  let text = $state('');
  let input: HTMLInputElement | undefined = $state();
  // svelte-ignore state_referenced_locally
  const id = idProp ?? uniqueId('chips');
  const listId = `${id}-list`;

  function add(raw: string) {
    const parts = raw.split(/[,\n]/).map(normalize).filter(Boolean);
    if (!parts.length) return;
    const next = [...values];
    for (const p of parts) if (!next.includes(p)) next.push(p);
    values = next;
    onchange?.(next);
    text = '';
  }
  function remove(i: number) {
    values = values.filter((_, j) => j !== i);
    onchange?.(values);
    input?.focus();
  }
  function onkeydown(e: KeyboardEvent) {
    if (e.key === 'Enter' || e.key === ',') {
      if (text.trim()) {
        e.preventDefault();
        add(text);
      } else if (e.key === ',') e.preventDefault();
    } else if (e.key === 'Backspace' && !text && values.length) {
      remove(values.length - 1);
    }
  }
</script>

<!-- svelte-ignore a11y_no_noninteractive_element_interactions, a11y_click_events_have_key_events -->
<div class="chips input" role="group" aria-label={label} onclick={() => input?.focus()}>
  {#each values as v, i (v)}
    <span class="chip-item {mono ? 'mono' : ''}">
      {v}
      <button type="button" class="rm" aria-label={t('COMMON.REMOVE_ITEM', { item: v })} onclick={(e) => { e.stopPropagation(); remove(i); }}><X size={11} /></button>
    </span>
  {/each}
  <input
    bind:this={input}
    {id}
    class="bare {mono ? 'mono' : ''}"
    bind:value={text}
    {placeholder}
    list={suggestions.length ? listId : undefined}
    aria-describedby={describedBy}
    {onkeydown}
    onblur={() => add(text)}
    autocomplete="off"
  />
  {#if suggestions.length}
    <datalist id={listId}>{#each suggestions.filter((s) => !values.includes(s)) as s (s)}<option value={s}></option>{/each}</datalist>
  {/if}
</div>

<style>
  .chips {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.25rem;
    block-size: auto;
    min-block-size: 2rem;
    padding: 0.1875rem 0.375rem;
    cursor: text;
  }
  .chips:focus-within {
    border-color: var(--ring);
    box-shadow: 0 0 0 1px var(--ring);
  }
  .chip-item {
    display: inline-flex;
    align-items: center;
    gap: 0.125rem;
    padding: 0 0.125rem 0 0.5rem;
    border-radius: var(--rm-r-sm);
    background: var(--secondary);
    font-size: var(--rm-text-xs);
    line-height: 1.25rem;
  }
  .rm {
    display: grid;
    place-items: center;
    inline-size: 1.125rem;
    block-size: 1.125rem;
    border-radius: var(--rm-r-sm);
    color: var(--muted-foreground);
  }
  .rm:hover {
    color: var(--foreground);
    background: var(--accent);
  }
  .bare {
    flex: 1 1 6rem;
    min-inline-size: 5rem;
    border: 0;
    background: transparent;
    padding: 0 0.25rem;
    block-size: 1.5rem;
    font-size: var(--rm-text-sm);
  }
  .bare:focus-visible {
    box-shadow: none;
    outline: none;
  }
</style>
