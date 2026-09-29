<script lang="ts">
  import { ChevronDown } from 'lucide-svelte';

  export interface Option {
    value: string;
    label: string;
    disabled?: boolean;
  }
  interface Props {
    value?: string;
    options: Option[];
    size?: 'default' | 'lg';
    class?: string;
    [key: string]: unknown;
  }
  let { value = $bindable(''), options, size = 'default', class: cls = '', ...rest }: Props = $props();
</script>

<span class="select-wrap {cls}">
  <select bind:value class="select-el {size === 'lg' ? 'lg' : ''}" {...rest}>
    {#each options as o (o.value)}
      <option value={o.value} disabled={o.disabled}>{o.label}</option>
    {/each}
  </select>
  <span class="chev" aria-hidden="true"><ChevronDown size={14} /></span>
</span>
