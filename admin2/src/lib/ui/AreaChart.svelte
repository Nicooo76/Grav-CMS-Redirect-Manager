<script lang="ts">
  import { formatCompact, formatDayMonth, formatNumber } from '../format';
  import { locale } from '../i18n.svelte';
  import { niceScale, type TrendPoint } from '../notfound-query';

  interface Props {
    points: TrendPoint[];
    /** summary for screen readers (role="img") */
    label: string;
    /** heading of the hidden data table and its two columns */
    caption: string;
    dayHeader: string;
    valueHeader: string;
    /** text of the tooltip and of the live announcement for one point */
    pointText: (p: TrendPoint) => string;
    /** hint read out when the chart gets keyboard focus */
    help?: string;
    height?: number;
  }
  let { points, label, caption, dayHeader, valueHeader, pointText, help, height = 168 }: Props = $props();

  const M = { l: 40, r: 20, t: 10, b: 24 };
  let width = $state(640);
  let active = $state<number | null>(null);
  let svgEl: SVGSVGElement | undefined = $state();

  const n = $derived(points.length);
  const innerW = $derived(Math.max(10, width - M.l - M.r));
  const innerH = $derived(height - M.t - M.b);
  const scale = $derived(niceScale(Math.max(0, ...points.map((p) => p.count))));
  const xAt = (i: number) => (n < 2 ? M.l + innerW / 2 : M.l + (i * innerW) / (n - 1));
  const yAt = (v: number) => M.t + innerH - (v / scale.max) * innerH;

  const line = $derived(points.map((p, i) => `${i ? 'L' : 'M'}${xAt(i).toFixed(1)},${yAt(p.count).toFixed(1)}`).join(''));
  const area = $derived(n < 2 ? '' : `${line}L${xAt(n - 1).toFixed(1)},${(M.t + innerH).toFixed(1)}L${xAt(0).toFixed(1)},${(M.t + innerH).toFixed(1)}Z`);

  // x labels: newest day always, then every k-th day backwards so nothing overlaps
  const labelIdx = $derived.by(() => {
    const per = Math.max(1, Math.floor(innerW / 72));
    const step = Math.max(1, Math.ceil(n / per));
    const out: number[] = [];
    for (let i = n - 1; i >= 0; i -= step) out.push(i);
    return out.reverse();
  });

  const cur = $derived(active !== null ? points[active] : null);

  function indexFromPointer(e: PointerEvent): number {
    const r = svgEl!.getBoundingClientRect();
    const x = ((e.clientX - r.left) / Math.max(1, r.width)) * width;
    if (n < 2) return 0;
    return Math.max(0, Math.min(n - 1, Math.round(((x - M.l) / innerW) * (n - 1))));
  }
  function onmove(e: PointerEvent) {
    if (n) active = indexFromPointer(e);
  }
  function onkey(e: KeyboardEvent) {
    if (!n) return;
    let next = active ?? n - 1;
    if (e.key === 'ArrowLeft') next = Math.max(0, next - 1);
    else if (e.key === 'ArrowRight') next = Math.min(n - 1, next + 1);
    else if (e.key === 'Home') next = 0;
    else if (e.key === 'End') next = n - 1;
    else if (e.key === 'Escape') {
      active = null;
      return;
    } else return;
    e.preventDefault();
    active = next;
  }
</script>

<div class="chart" bind:clientWidth={width}>
  <!-- svelte-ignore a11y_no_noninteractive_tabindex, a11y_no_noninteractive_element_interactions -->
  <div
    class="plot"
    role="group"
    aria-label={label}
    aria-describedby={help ? 'rm-chart-help' : undefined}
    tabindex="0"
    onkeydown={onkey}
    onfocus={() => (active ??= n ? n - 1 : null)}
    onblur={() => (active = null)}
    onpointerleave={(e) => {
      if (!(e.currentTarget as HTMLElement).matches(':focus')) active = null;
    }}
  >
    <svg bind:this={svgEl} {width} {height} viewBox="0 0 {width} {height}" role="img" aria-label={label} focusable="false" onpointermove={onmove} onpointerdown={onmove}>
      {#each scale.ticks as v (v)}
        <line x1={M.l} x2={M.l + innerW} y1={yAt(v)} y2={yAt(v)} stroke="var(--border)" stroke-width="1" stroke-dasharray={v === 0 ? undefined : '3 3'} />
        <text class="axis" x={M.l - 8} y={yAt(v)} text-anchor="end" dominant-baseline="middle">{formatCompact(v, locale())}</text>
      {/each}
      {#each labelIdx as i (i)}
        <text class="axis" x={xAt(i)} y={height - 6} text-anchor="middle">{formatDayMonth(points[i].day, locale())}</text>
      {/each}
      {#if n > 1}
        <path d={area} fill="var(--primary)" opacity="0.12" />
        <path d={line} fill="none" stroke="var(--primary)" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round" />
      {/if}
      {#if cur && active !== null}
        <line x1={xAt(active)} x2={xAt(active)} y1={M.t} y2={M.t + innerH} stroke="var(--muted-foreground)" stroke-width="1" stroke-dasharray="2 3" />
        <circle cx={xAt(active)} cy={yAt(cur.count)} r="4" fill="var(--card)" stroke="var(--primary)" stroke-width="2" />
      {/if}
    </svg>
    {#if cur && active !== null}
      <div class="tip" class:below={yAt(cur.count) < 40} role="presentation" style="left:clamp(3.5rem, {xAt(active)}px, calc(100% - 3.5rem));top:{yAt(cur.count)}px">{pointText(cur)}</div>
    {/if}
  </div>
  {#if help}<span id="rm-chart-help" class="sr-only">{help}</span>{/if}
  <div class="sr-only" role="status" aria-live="polite">{cur ? pointText(cur) : ''}</div>
  <table class="sr-only">
    <caption>{caption}</caption>
    <thead>
      <tr><th scope="col">{dayHeader}</th><th scope="col">{valueHeader}</th></tr>
    </thead>
    <tbody>
      {#each points as p (p.day)}
        <tr><th scope="row">{p.day}</th><td>{formatNumber(p.count, locale())}</td></tr>
      {/each}
    </tbody>
  </table>
</div>

<style>
  .chart {
    position: relative;
    inline-size: 100%;
    min-inline-size: 0;
    /* time runs left to right, also in RTL layouts */
    direction: ltr;
  }
  .plot {
    position: relative;
    border-radius: var(--rm-r-md);
    touch-action: pan-y;
  }
  svg {
    display: block;
    inline-size: 100%;
  }
  .axis {
    font-size: var(--rm-text-2xs);
    fill: var(--muted-foreground);
    font-variant-numeric: tabular-nums;
  }
  .tip.below {
    transform: translate(-50%, 10px);
  }
  .tip {
    position: absolute;
    z-index: 2;
    transform: translate(-50%, calc(-100% - 10px));
    padding: 0.25rem 0.5rem;
    border-radius: var(--rm-r-md);
    background: var(--foreground);
    color: var(--background);
    font-size: var(--rm-text-xs);
    line-height: 1rem;
    white-space: nowrap;
    pointer-events: none;
    box-shadow: var(--rm-shadow-md);
  }
</style>
