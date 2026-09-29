<script lang="ts">
  import { CircleCheck, CirclePause, Clock, CalendarClock, Link2, RefreshCcw, Unlink, GitCompareArrows, MousePointerClick } from 'lucide-svelte';
  import type { RuleBadge } from '../types';
  import { t } from '../i18n.svelte';
  import { tooltip } from '../actions';

  interface Props {
    kind: RuleBadge;
    animate?: boolean;
    /** icon only, label as accessible name (dense cells) */
    compact?: boolean;
  }
  let { kind, animate = false, compact = false }: Props = $props();

  const META: Record<RuleBadge, { variant: string; icon: any }> = {
    active: { variant: 'ok', icon: CircleCheck },
    disabled: { variant: 'muted', icon: CirclePause },
    expired: { variant: 'muted', icon: Clock },
    scheduled: { variant: 'info', icon: CalendarClock },
    chain: { variant: 'warn', icon: Link2 },
    loop: { variant: 'bad', icon: RefreshCcw },
    dead_target: { variant: 'bad', icon: Unlink },
    conflict: { variant: 'warn', icon: GitCompareArrows },
    unused: { variant: 'outline', icon: MousePointerClick },
  };
  const meta = $derived(META[kind] ?? { variant: 'muted', icon: CircleCheck });
  const label = $derived(t(`BADGE.${kind.toUpperCase()}`));
  const help = $derived(t(`BADGE_HELP.${kind.toUpperCase()}`));
</script>

<span class="badge {meta.variant} {animate ? 'enter' : ''}" use:tooltip={help} title={undefined}>
  <meta.icon size={11} aria-hidden="true" />
  {#if compact}<span class="sr-only">{label}</span>{:else}{label}{/if}
</span>
