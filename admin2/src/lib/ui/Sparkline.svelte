<script lang="ts">
  interface Props {
    values: number[];
    width?: number;
    height?: number;
    label?: string;
    fill?: boolean;
    /** css colour, defaults to the host primary */
    color?: string;
    class?: string;
  }
  let { values, width = 64, height = 20, label, fill = true, color = 'var(--primary)', class: cls = '' }: Props = $props();

  const pad = 1.5;
  const geo = $derived.by(() => {
    const n = values.length;
    const max = Math.max(1, ...values);
    if (n < 2) return { line: '', area: '' };
    const step = (width - pad * 2) / (n - 1);
    const pts = values.map((v, i) => [pad + i * step, height - pad - (v / max) * (height - pad * 2)] as const);
    const line = pts.map(([x, y], i) => `${i ? 'L' : 'M'}${x.toFixed(1)},${y.toFixed(1)}`).join('');
    const area = `${line}L${(pad + (n - 1) * step).toFixed(1)},${height}L${pad},${height}Z`;
    return { line, area };
  });
  const total = $derived(values.reduce((a, b) => a + b, 0));
</script>

<svg class="spark {cls}" {width} {height} viewBox="0 0 {width} {height}" role={label ? 'img' : undefined} aria-label={label} aria-hidden={label ? undefined : 'true'} focusable="false">
  {#if total === 0}
    <line x1={pad} y1={height - pad} x2={width - pad} y2={height - pad} stroke="var(--border)" stroke-width="1" stroke-dasharray="2 3" />
  {:else}
    {#if fill}<path d={geo.area} fill={color} opacity="0.12" />{/if}
    <path d={geo.line} fill="none" stroke={color} stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
  {/if}
</svg>
