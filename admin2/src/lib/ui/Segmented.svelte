<script lang="ts">
  interface Item {
    value: string | number;
    label: string;
    title?: string;
  }
  interface Props {
    items: Item[];
    value: string | number;
    onchange?: (v: any) => void;
    label: string;
    size?: 'default' | 'sm';
  }
  let { items, value = $bindable(), onchange, label, size = 'default' }: Props = $props();

  function move(e: KeyboardEvent, i: number) {
    let n = i;
    if (e.key === 'ArrowRight' || e.key === 'ArrowDown') n = (i + 1) % items.length;
    else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') n = (i - 1 + items.length) % items.length;
    else return;
    e.preventDefault();
    value = items[n].value;
    onchange?.(value);
    ((e.currentTarget as HTMLElement).parentElement?.children[n] as HTMLElement)?.focus();
  }
</script>

<div class="segmented {size === 'sm' ? 'sm' : ''}" role="radiogroup" aria-label={label}>
  {#each items as it, i (it.value)}
    <button
      type="button"
      role="radio"
      aria-checked={value === it.value}
      tabindex={value === it.value ? 0 : -1}
      title={it.title}
      onclick={() => {
        value = it.value;
        onchange?.(value);
      }}
      onkeydown={(e) => move(e, i)}>{it.label}</button
    >
  {/each}
</div>
