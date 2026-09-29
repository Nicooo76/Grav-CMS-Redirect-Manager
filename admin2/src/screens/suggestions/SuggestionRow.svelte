<script lang="ts">
  import { Check, Pencil, X } from 'lucide-svelte';
  import Badge from '../../lib/ui/Badge.svelte';
  import Button from '../../lib/ui/Button.svelte';
  import { autofocus } from '../../lib/actions';
  import { formatNumber } from '../../lib/format';
  import { locale, t } from '../../lib/i18n.svelte';
  import { hrefFor, linkClick } from '../../lib/router.svelte';
  import { effectiveTarget, formatScore, isEdited, reasonKey, scoreBucket, scorePercent } from '../../lib/suggestions';
  import type { StoredSuggestion } from '../../lib/types';
  import { suggestions } from './store.svelte';

  interface Props {
    row: StoredSuggestion;
    index: number;
    actions: boolean;
    onaccept: (row: StoredSuggestion, index: number) => void;
    onreject: (row: StoredSuggestion, index: number) => void;
  }
  let { row, index, actions, onaccept, onreject }: Props = $props();

  let editing = $state(false);
  let draft = $state('');

  const target = $derived(effectiveTarget(row, suggestions.edits));
  const edited = $derived(isEdited(row, suggestions.edits));
  const pct = $derived(scorePercent(row.score));

  function start() {
    draft = target;
    editing = true;
  }
  function commit() {
    if (!editing) return;
    editing = false;
    suggestions.edit(row, draft);
  }
  function onkey(e: KeyboardEvent) {
    if (e.key === 'Enter') {
      e.preventDefault();
      commit();
    } else if (e.key === 'Escape') {
      e.preventDefault();
      e.stopPropagation();
      editing = false;
    }
  }
</script>

<tr data-index={index}>
  <td class="c-score">
    <div class="score">
      <div class="progress" data-bucket={scoreBucket(row.score)} aria-hidden="true"><i style="inline-size:{pct}%"></i></div>
      <span class="num">{formatScore(row.score, locale())}</span>
    </div>
  </td>
  <td class="c-reason">
    <div class="reason">
      <span class="lbl">{t(reasonKey(row.reason))}</span>
      <span class="hint text-xs muted">{t(`${reasonKey(row.reason)}_HINT`)}</span>
    </div>
  </td>
  <td class="c-path">
    <a class="mono truncate" href={hrefFor({ name: 'notfound', query: { q: row.path } })} onclick={linkClick} title={row.path}>{row.path}</a>
  </td>
  <td class="c-target">
    {#if editing}
      <input
        class="input inline mono"
        aria-label={t('SUGGESTIONS.EDIT_TARGET', { path: row.path })}
        bind:value={draft}
        use:autofocus={{ select: true }}
        onkeydown={onkey}
        onblur={commit}
      />
    {:else if actions}
      <button type="button" class="cell-edit" title={t('SUGGESTIONS.EDIT_TARGET_HINT')} aria-label={t('SUGGESTIONS.EDIT_TARGET_BTN', { target })} onclick={start}>
        <span class="mono truncate">{target}</span>
        {#if edited}<Badge variant="info" title={t('SUGGESTIONS.EDITED_TITLE', { target: row.target })}>{t('SUGGESTIONS.EDITED')}</Badge>{/if}
        <Pencil size={12} class="pen" />
      </button>
    {:else}
      <span class="mono truncate block" title={target}>{target}</span>
    {/if}
    {#if row.page_title && !edited}<div class="title text-xs muted truncate" title={row.page_title}>{row.page_title}</div>{/if}
  </td>
  <td class="c-hits num">{formatNumber(row.hits, locale())}</td>
  {#if actions}
    <td class="c-act keep">
      <div class="acts">
        <Button class="accept" aria-label={t('SUGGESTIONS.ACCEPT_FOR', { path: row.path })} onclick={() => onaccept(row, index)}>
          <Check size={14} />{t('SUGGESTIONS.ACCEPT')}
        </Button>
        <Button variant="ghost" aria-label={t('SUGGESTIONS.REJECT_FOR', { path: row.path })} onclick={() => onreject(row, index)}>
          <X size={14} /><span class="rej-txt">{t('SUGGESTIONS.REJECT')}</span>
        </Button>
      </div>
    </td>
  {/if}
</tr>

<style>
  td {
    vertical-align: middle;
  }
  .score {
    display: flex;
    align-items: center;
    gap: 0.5rem;
  }
  .score .progress {
    inline-size: 2.75rem;
    flex: none;
  }
  .progress[data-bucket='high'] > i {
    background: var(--rm-ok);
  }
  .progress[data-bucket='low'] > i {
    background: var(--rm-warn);
  }
  .reason {
    display: flex;
    flex-direction: column;
    gap: 0.0625rem;
    min-inline-size: 0;
  }
  .reason .lbl {
    font-weight: 500;
  }
  .reason .hint {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }
  .c-path a {
    display: block;
    color: var(--foreground);
    text-decoration: none;
    border-radius: var(--rm-r-sm);
  }
  .c-path a:hover {
    color: var(--primary);
    text-decoration: underline;
  }
  .block {
    display: block;
  }
  .title {
    margin-block-start: 0.0625rem;
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
    flex: none;
    color: var(--muted-foreground);
  }
  .cell-edit :global(.badge) {
    flex: none;
  }
  .c-hits {
    padding-inline-end: 1.5rem;
  }
  .acts {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 0.25rem;
  }
</style>
