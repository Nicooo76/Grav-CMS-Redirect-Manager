<script lang="ts">
  import { Ban, Bot, Check, ChevronDown, ChevronLeft, ChevronRight, Eye, EyeOff, Plus, Trash2, Undo2 } from 'lucide-svelte';
  import Badge from '../../lib/ui/Badge.svelte';
  import Button from '../../lib/ui/Button.svelte';
  import Menu, { type MenuItem } from '../../lib/ui/Menu.svelte';
  import Sparkline from '../../lib/ui/Sparkline.svelte';
  import Entries from './Entries.svelte';
  import { uniqueId } from '../../lib/dom';
  import { dailySeries, formatDateTime, formatNumber, formatPercent, formatRelative } from '../../lib/format';
  import { dir, locale, t } from '../../lib/i18n.svelte';
  import { botShare, isBotDominated, summarizeReferers } from '../../lib/notfound-query';
  import { hrefFor, linkClick } from '../../lib/router.svelte';
  import { formatScore } from '../../lib/suggestions';
  import type { NotFoundRow } from '../../lib/types';
  import { can } from '../../lib/state/app.svelte';
  import { notFound } from './store.svelte';

  interface Props {
    row: NotFoundRow;
    showSuggestion: boolean;
  }
  let { row, showSuggestion }: Props = $props();

  const detailId = uniqueId('nf-detail');
  const open = $derived(!!notFound.expanded[row.path]);
  const busy = $derived(!!notFound.busy[row.path]);
  const rtl = $derived(dir() === 'rtl');
  const refs = $derived(summarizeReferers(row.top_referers));
  const series = $derived(dailySeries(row.daily, Math.min(notFound.list.days, 30)));
  const sugg = $derived(row.has_rule ? null : row.best_suggestion);
  const refTitle = $derived(
    (row.top_referers ?? [])
      .map((r) => t('NOTFOUND.REFERER_ITEM', { referer: r.referer.trim() || t('NOTFOUND.DIRECT'), hits: formatNumber(r.hits, locale()) }))
      .join('\n'),
  );

  const items = $derived<MenuItem[]>([
    {
      label: open ? t('NOTFOUND.ACT_ENTRIES_HIDE') : t('NOTFOUND.ACT_ENTRIES'),
      icon: open ? EyeOff : Eye,
      onselect: () => notFound.toggleEntries(row.path),
    },
    ...(can.manage
      ? [
          row.resolved
            ? { label: t('NOTFOUND.ACT_REOPEN'), icon: Undo2, onselect: () => notFound.resolve(row, false) }
            : { label: t('NOTFOUND.ACT_DONE'), icon: Check, onselect: () => notFound.resolve(row, true) },
          { label: t('NOTFOUND.ACT_IGNORE'), icon: Ban, onselect: () => notFound.ignore(row) },
          { separator: true, label: '' },
          { label: t('NOTFOUND.ACT_DELETE'), icon: Trash2, danger: true, onselect: () => notFound.deleteEntries(row) },
        ]
      : []),
  ]);
</script>

<tr class:disabled={!!row.resolved} data-path={row.path}>
  <td class="c-path">
    <div class="cell">
      <button
        type="button"
        class="exp"
        aria-expanded={open}
        aria-controls={open ? detailId : undefined}
        aria-label={open ? t('NOTFOUND.TOGGLE_ENTRIES_HIDE', { path: row.path }) : t('NOTFOUND.TOGGLE_ENTRIES', { path: row.path })}
        onclick={() => notFound.toggleEntries(row.path)}
      >
        {#if open}<ChevronDown size={14} />{:else if rtl}<ChevronLeft size={14} />{:else}<ChevronRight size={14} />{/if}
      </button>
      <span class="mono truncate path" title={row.path}>{row.path}</span>
      {#if isBotDominated(row)}
        <Badge variant="muted" title={t('NOTFOUND.BOTS_BADGE_TITLE', { share: formatPercent(botShare(row), locale()) })}>
          <Bot size={11} />{t('NOTFOUND.BOTS_BADGE')}
        </Badge>
      {/if}
      {#if row.has_rule}<Badge variant="muted">{t('NOTFOUND.HAS_RULE')}</Badge>{/if}
      {#if row.resolved}<Badge variant="outline">{t('NOTFOUND.DONE')}</Badge>{/if}
    </div>
  </td>
  <td class="c-hits num">{formatNumber(row.hits, locale())}</td>
  <td class="c-first">
    <span title={formatDateTime(row.first_seen, locale())}>{formatRelative(row.first_seen, locale())}</span>
  </td>
  <td class="c-last">
    <span title={formatDateTime(row.last_seen, locale())}>{formatRelative(row.last_seen, locale())}</span>
  </td>
  <td class="c-ref">
    {#if refs}
      <div class="cell" title={refTitle}>
        {#if refs.direct}<span class="muted truncate">{t('NOTFOUND.DIRECT')}</span>{:else}<span class="truncate">{refs.first}</span>{/if}
        {#if refs.more > 0}<span class="chip">+{refs.more}</span>{/if}
      </div>
    {:else}<span class="muted">–</span>{/if}
  </td>
  <td class="c-trend"><Sparkline values={series} width={56} height={18} /></td>
  {#if showSuggestion}
    <td class="c-sugg">
      {#if sugg}
        <button
          type="button"
          class="sugg"
          disabled={busy || !can.manage}
          title={t('NOTFOUND.SUGGESTION_TITLE', { target: sugg.target, score: formatScore(sugg.score, locale()) })}
          aria-label={t('NOTFOUND.SUGGESTION_TITLE', { target: sugg.target, score: formatScore(sugg.score, locale()) })}
          onclick={() => notFound.createRedirect(row)}
        >
          <Badge variant="outline"><span class="mono truncate">{sugg.target}</span><span class="num pct">{formatScore(sugg.score, locale())}</span></Badge>
        </button>
      {:else}<span class="muted">–</span>{/if}
    </td>
  {/if}
  <td class="c-act keep">
    <div class="acts">
      {#if row.has_rule}
        <Button href={hrefFor({ name: 'rules', query: { q: row.path } })} onclick={linkClick} aria-label={t('NOTFOUND.EDIT_RULE_FOR', { path: row.path })}>{t('NOTFOUND.EDIT_RULE')}</Button>
      {:else if can.manage}
        <Button loading={busy} aria-label={t('NOTFOUND.CREATE_FOR', { path: row.path })} onclick={() => notFound.createRedirect(row)}>
          <Plus size={14} />{t('NOTFOUND.CREATE')}
        </Button>
      {/if}
      <Menu {items} label={t('NOTFOUND.ROW_ACTIONS', { path: row.path })} />
    </div>
  </td>
</tr>
{#if open}
  <tr class="detail">
    <td colspan="8"><Entries path={row.path} id={detailId} /></td>
  </tr>
{/if}

<style>
  tr.detail:hover {
    background: transparent;
  }
  tr.detail > td {
    padding: 0;
    background: color-mix(in srgb, var(--muted) 25%, transparent);
  }
  .cell {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    min-inline-size: 0;
  }
  .exp {
    display: grid;
    place-items: center;
    flex: none;
    inline-size: 1.5rem;
    block-size: 1.5rem;
    margin-inline-start: -0.375rem;
    border-radius: var(--rm-r-sm);
    color: var(--muted-foreground);
  }
  .exp:hover {
    background: var(--accent);
    color: var(--foreground);
  }
  .path {
    font-weight: 500;
    min-inline-size: 3rem;
  }
  .cell :global(.badge) {
    flex: none;
  }
  .sugg {
    display: block;
    max-inline-size: 100%;
    border-radius: var(--rm-r-sm);
    text-align: start;
  }
  .sugg:disabled {
    opacity: 0.6;
  }
  .sugg :global(.badge) {
    max-inline-size: 100%;
    gap: 0.375rem;
    cursor: pointer;
  }
  .sugg:hover :global(.badge) {
    background: var(--accent);
  }
  .pct {
    color: var(--muted-foreground);
  }
  .acts {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 0.25rem;
  }
</style>
