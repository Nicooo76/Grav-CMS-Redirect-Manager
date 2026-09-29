<script lang="ts">
  import { tick } from 'svelte';
  import { GripVertical, Pencil, ExternalLink, FileText, Copy, FlaskConical, ArrowUp, ArrowDown, Link2, Trash2, Power } from 'lucide-svelte';
  import Checkbox from '../../lib/ui/Checkbox.svelte';
  import Switch from '../../lib/ui/Switch.svelte';
  import Menu, { type MenuItem } from '../../lib/ui/Menu.svelte';
  import RuleBadge from '../../lib/ui/RuleBadge.svelte';
  import MatchChip from '../../lib/ui/MatchChip.svelte';
  import Sparkline from '../../lib/ui/Sparkline.svelte';
  import { autofocus, tooltip } from '../../lib/actions';
  import { t, locale } from '../../lib/i18n.svelte';
  import { formatDateTime, formatNumber, formatRelative, dailySeries } from '../../lib/format';
  import { hrefFor, linkClick, navigate } from '../../lib/router.svelte';
  import { rules } from '../../lib/state/rules.svelte';
  import { openEditor } from '../../lib/state/app.svelte';
  import { sampleFromSource } from '../../lib/sample';
  import { toInput } from '../../lib/rule-utils';
  import { canReorder } from '../../lib/rules-query';
  import type { Rule, RuleBadge as Badge, StatusCode } from '../../lib/types';

  interface Props {
    rule: Rule;
    index: number;
    count: number;
    dragging: boolean;
    dropEdge: 'before' | 'after' | null;
    onselect: (e: MouseEvent | KeyboardEvent | Event, index: number) => void;
    ondragstart: (e: PointerEvent, index: number) => void;
  }
  let { rule, index, count, dragging, dropEdge, onselect, ondragstart }: Props = $props();

  type Editing = 'target' | 'status' | null;
  let editing = $state<Editing>(null);
  let draft = $state('');

  const cols = $derived(rules.columns);
  const STATE_BADGES: Badge[] = ['active', 'disabled', 'expired', 'scheduled'];
  const badges = $derived(rule.badges ?? []);
  const stateBadge = $derived(badges.find((b) => STATE_BADGES.includes(b)) ?? (rule.enabled ? 'active' : 'disabled'));
  const problems = $derived(badges.filter((b) => !STATE_BADGES.includes(b)));
  const inactive = $derived(stateBadge !== 'active');
  const series = $derived(dailySeries(rule.stats?.daily, 30));
  const reorderable = $derived(canReorder(rules.list));
  const total = $derived(rule.stats?.total ?? 0);
  const noTarget = $derived(rule.status === 410 || rule.status === 451);
  const STATUS_OPTIONS = [301, 302, 307, 308, 410, 451, 200].map((v) => ({ value: String(v), label: `${v} · ${t(`STATUS.${v}`)}` }));

  function startEdit(field: Editing) {
    editing = field;
    if (field === 'target') draft = rule.target;
  }
  function cancel() {
    editing = null;
  }
  function inferTargetType(target: string): Rule['target_type'] {
    if (/^[a-z][a-z0-9+.-]*:\/\//i.test(target)) return 'url';
    if (rule.target_type === 'url') return 'route';
    return rule.target_type;
  }
  function commitTarget() {
    if (editing !== 'target') return;
    const v = draft.trim();
    editing = null;
    if (!v || v === rule.target) return;
    void rules.update(rule.id, { target: v, target_type: inferTargetType(v) });
  }
  function onTargetKey(e: KeyboardEvent) {
    if (e.key === 'Enter') {
      e.preventDefault();
      commitTarget();
    } else if (e.key === 'Escape') {
      e.preventDefault();
      e.stopPropagation();
      cancel();
    }
  }
  function commitStatus(e: Event) {
    const v = Number((e.currentTarget as HTMLSelectElement).value);
    editing = null;
    if (v !== rule.status) void rules.update(rule.id, { status: v as StatusCode });
  }
  function onStatusKey(e: KeyboardEvent) {
    if (e.key === 'Escape') {
      e.preventDefault();
      e.stopPropagation();
      cancel();
    }
  }

  function handleKey(e: KeyboardEvent) {
    if (!reorderable) return;
    if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
      e.preventDefault();
      move(e.key === 'ArrowUp' ? -1 : 1);
    }
  }
  let handleEl: HTMLButtonElement | undefined = $state();
  async function move(delta: number) {
    const to = index + delta;
    if (to < 0 || to >= count) return;
    await rules.reorder(index, to);
    // the keyed row was moved in the DOM; keep keyboard focus on its handle
    await tick();
    handleEl?.focus();
  }

  const items = $derived<MenuItem[]>([
    { label: t('COMMON.EDIT'), icon: Pencil, onselect: () => navigate({ name: 'rule-edit', id: rule.id }) },
    { label: t('RULES.ACT_DUPLICATE'), icon: Copy, onselect: () => duplicate() },
    { label: t('RULES.ACT_TEST'), icon: FlaskConical, onselect: () => navigate({ name: 'tester', query: { url: sampleFromSource(rule.source, rule.match_type) } }) },
    ...(problems.includes('chain') ? [{ label: t('RULES.ACT_SHORTEN'), icon: Link2, onselect: () => rules.shortenChain(rule.id) }] : []),
    { label: rule.enabled ? t('RULES.ACT_DISABLE') : t('RULES.ACT_ENABLE'), icon: Power, onselect: () => rules.update(rule.id, { enabled: !rule.enabled }) },
    ...(reorderable
      ? [
          { separator: true, label: '' },
          { label: t('RULES.ACT_MOVE_UP'), icon: ArrowUp, disabled: index === 0, onselect: () => move(-1) },
          { label: t('RULES.ACT_MOVE_DOWN'), icon: ArrowDown, disabled: index === count - 1, onselect: () => move(1) },
        ]
      : []),
    { separator: true, label: '' },
    { label: t('COMMON.DELETE'), icon: Trash2, danger: true, onselect: () => rules.remove([rule.id]) },
  ]);

  function duplicate() {
    openEditor({ prefill: { ...toInput(rule), origin: 'manual' } });
  }
</script>

<tr
  class:disabled={inactive}
  class:dragging
  class:drop-before={dropEdge === 'before'}
  class:drop-after={dropEdge === 'after'}
  class:selected={!!rules.selected[rule.id]}
  aria-rowindex={index + 2}
  data-id={rule.id}
>
  <td class="c-sel keep">
    <Checkbox
      checked={!!rules.selected[rule.id]}
      aria-label={t('RULES.SELECT_ROW', { source: rule.source })}
      onclick={(e: MouseEvent) => onselect(e, index)}
      onchange={() => {}}
    />
  </td>
  <td class="c-drag keep">
    <button
      bind:this={handleEl}
      type="button"
      class="drag"
      aria-label={reorderable ? t('RULES.DRAG_HANDLE', { source: rule.source }) : t('RULES.DRAG_DISABLED')}
      aria-disabled={!reorderable}
      title={reorderable ? undefined : t('RULES.DRAG_DISABLED')}
      onpointerdown={(e) => reorderable && ondragstart(e, index)}
      onkeydown={handleKey}
    >
      <GripVertical size={14} />
    </button>
  </td>
  {#if cols.status}
    <td class="c-status keep">
      <div class="cell">
        <Switch checked={rule.enabled} label={t('RULES.TOGGLE_ENABLED', { source: rule.source })} onchange={(v) => rules.update(rule.id, { enabled: v })} />
        {#key stateBadge}<RuleBadge kind={stateBadge} animate={rules.animate} />{/key}
        {#each problems.slice(0, 1) as p (p)}<RuleBadge kind={p} animate={rules.animate} />{/each}
        {#each problems.slice(1) as p (p)}<RuleBadge kind={p} animate={rules.animate} compact />{/each}
      </div>
    </td>
  {/if}
  <td class="c-source">
    <div class="cell">
      <a class="src mono truncate" href={hrefFor({ name: 'rule-edit', id: rule.id })} onclick={linkClick} title={rule.source}>{rule.source}</a>
      <MatchChip type={rule.match_type} />
    </div>
  </td>
  {#if cols.target}
    <td class="c-target">
      {#if editing === 'target'}
        <input
          class="input inline mono"
          aria-label={t('RULES.EDIT_TARGET', { source: rule.source })}
          bind:value={draft}
          use:autofocus={{ select: true }}
          onkeydown={onTargetKey}
          onblur={commitTarget}
        />
      {:else if noTarget}
        <span class="muted">—</span>
      {:else}
        <button type="button" class="cell-edit" onclick={() => startEdit('target')} title={t('RULES.EDIT_TARGET_HINT')} aria-label={t('RULES.EDIT_TARGET_BTN', { target: rule.target })}>
          {#if rule.target_type === 'url'}<ExternalLink size={12} class="ti" />{:else if rule.target_type === 'page'}<FileText size={12} class="ti" />{/if}
          <span class="mono truncate">{rule.target}</span>
          <Pencil size={12} class="pen" />
        </button>
      {/if}
    </td>
  {/if}
  {#if cols.code}
    <td class="c-code">
      {#if editing === 'status'}
        <span class="select-wrap" style="min-inline-size:8rem">
          <select
            class="select-el"
            style="block-size:1.75rem;padding-inline-start:0.5rem"
            aria-label={t('RULES.EDIT_STATUS', { source: rule.source })}
            value={String(rule.status)}
            onchange={commitStatus}
            onkeydown={onStatusKey}
            onblur={cancel}
            use:autofocus
          >
            {#each STATUS_OPTIONS as o (o.value)}<option value={o.value}>{o.label}</option>{/each}
          </select>
        </span>
      {:else}
        <button type="button" class="cell-edit code" onclick={() => startEdit('status')} use:tooltip={t(`STATUS.${rule.status}`)} aria-label={t('RULES.EDIT_STATUS_BTN', { status: rule.status, label: t(`STATUS.${rule.status}`) })}>
          <span class="num">{rule.status}</span>
        </button>
      {/if}
    </td>
  {/if}
  {#if cols.group}
    <td class="c-group"><span class="truncate" title={rule.group}>{#if rule.group}{rule.group}{:else}<span class="muted">—</span>{/if}</span></td>
  {/if}
  {#if cols.hits}
    <td class="c-hits num">
      <div class="cell end">
        <Sparkline values={series} width={56} height={18} color={total === 0 ? 'var(--muted-foreground)' : 'var(--primary)'} />
        <span class={total === 0 ? 'muted' : ''}>{formatNumber(total, locale())}</span>
      </div>
    </td>
  {/if}
  {#if cols.last_hit}
    <td class="c-last">
      {#if rule.stats?.last_hit}
        <span class="truncate" title={formatDateTime(rule.stats.last_hit, locale())}>{formatRelative(rule.stats.last_hit, locale())}</span>
      {:else}<span class="muted">{t('COMMON.NEVER')}</span>{/if}
    </td>
  {/if}
  {#if cols.origin}
    <td class="c-origin muted">{t(`ORIGIN.${rule.origin.toUpperCase()}`)}</td>
  {/if}
  {#if cols.priority}
    <td class="c-priority num">{rule.priority}</td>
  {/if}
  <td class="c-act keep">
    <Menu {items} label={t('RULES.ROW_ACTIONS', { source: rule.source })} />
  </td>
</tr>

<style>
  tr.selected {
    background: color-mix(in srgb, var(--primary) 6%, transparent);
  }
  tr.dragging {
    opacity: 0.45;
  }
  tr.drop-before > td {
    box-shadow: inset 0 2px 0 var(--primary);
  }
  tr.drop-after > td {
    box-shadow: inset 0 -2px 0 var(--primary);
  }
  .cell {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    min-inline-size: 0;
  }
  .cell.end {
    justify-content: flex-end;
  }
  tr.disabled .src {
    color: var(--rm-muted-fg);
    font-weight: 400;
  }
  .src {
    color: var(--foreground);
    text-decoration: none;
    font-weight: 500;
    border-radius: var(--rm-r-sm);
  }
  .src:hover {
    color: var(--primary);
    text-decoration: underline;
  }
  .drag {
    display: grid;
    place-items: center;
    inline-size: 1.5rem;
    block-size: 1.75rem;
    border-radius: var(--rm-r-sm);
    color: color-mix(in srgb, var(--muted-foreground) 88%, transparent);
    cursor: grab;
    touch-action: none;
  }
  .drag:hover,
  .drag:focus-visible {
    color: var(--foreground);
    background: var(--accent);
  }
  .drag[aria-disabled='true'] {
    cursor: not-allowed;
    opacity: 0.4;
  }
  .cell-edit {
    display: flex;
    align-items: center;
    gap: 0.375rem;
    inline-size: 100%;
    min-inline-size: 0;
    padding: 0.25rem 0.375rem;
    margin-inline: -0.375rem;
    border-radius: var(--rm-r-sm);
    text-align: start;
    color: var(--foreground);
  }
  .cell-edit:hover {
    background: color-mix(in srgb, var(--accent) 80%, transparent);
  }
  .cell-edit :global(.pen) {
    margin-inline-start: auto;
    flex-shrink: 0;
    opacity: 0;
    color: var(--muted-foreground);
  }
  .cell-edit:hover :global(.pen),
  .cell-edit:focus-visible :global(.pen) {
    opacity: 1;
  }
  .cell-edit :global(.ti) {
    flex-shrink: 0;
    color: var(--muted-foreground);
  }
  .cell-edit.code {
    inline-size: auto;
    font-variant-numeric: tabular-nums;
  }
</style>
