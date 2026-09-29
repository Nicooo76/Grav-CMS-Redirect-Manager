<script lang="ts">
  interface Props {
    checked: boolean;
    onchange?: (v: boolean) => void;
    label: string;
    disabled?: boolean;
    class?: string;
  }
  let { checked = $bindable(), onchange, label, disabled = false, class: cls = '' }: Props = $props();
</script>

<button
  type="button"
  role="switch"
  class="switch {cls}"
  aria-checked={checked}
  aria-label={label}
  {disabled}
  onclick={(e) => {
    e.stopPropagation();
    checked = !checked;
    onchange?.(checked);
  }}
></button>
