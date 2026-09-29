<script lang="ts">
  import { onMount, untrack } from 'svelte';
  import { Search, Sparkles } from 'lucide-svelte';
  import Button from '../lib/ui/Button.svelte';
  import EmptyState from '../lib/ui/EmptyState.svelte';
  import ErrorState from '../lib/ui/ErrorState.svelte';
  import Input from '../lib/ui/Input.svelte';
  import Segmented from '../lib/ui/Segmented.svelte';
  import Select from '../lib/ui/Select.svelte';
  import BulkPanel from './suggestions/BulkPanel.svelte';
  import BulkPreview from './suggestions/BulkPreview.svelte';
  import SuggestionsTable from './suggestions/SuggestionsTable.svelte';
  import { suggestions } from './suggestions/store.svelte';
  import { formatNumber } from '../lib/format';
  import { locale, t } from '../lib/i18n.svelte';
  import { linkClick, router } from '../lib/router.svelte';
  import { can, registerSearch, versions } from '../lib/state/app.svelte';
  import { MAX_ROWS, MIN_SCORE_OPTIONS, STATUSES, filterRows, formatScore, limitRows, type SuggestionStatus } from '../lib/suggestions';

  let searchEl: HTMLInputElement | null = $state(null);
  let qText = $state(suggestions.list.q);
  let debounce: ReturnType<typeof setTimeout> | undefined;
  let seenVersion = versions.suggestions;
  let first = true;

  const isOpen = $derived(suggestions.list.status === 'open');
  const filtered = $derived(suggestions.list.min > 0 || suggestions.list.q.trim() !== '');
  const matching = $derived(filterRows(suggestions.rows, suggestions.list.q));
  const limited = $derived(limitRows(matching, MAX_ROWS));
  const totalShown = $derived(suggestions.list.q.trim() ? matching.length : Math.max(suggestions.total, matching.length));
  const failed = $derived(!!suggestions.error && !suggestions.loaded);
  const empty = $derived(suggestions.loaded && suggestions.total === 0 && suggestions.rows.length === 0);
  const noMatch = $derived(suggestions.loaded && suggestions.rows.length > 0 && matching.length === 0);
  const anyReviewed = $derived(suggestions.counts.accepted + suggestions.counts.rejected > 0);

  const statusItems = $derived(
    STATUSES.map((s) => ({
      value: s,
      label: t('SUGGESTIONS.STATUS_ITEM', { label: t(`SUGGESTIONS.STATUS_${s.toUpperCase()}`), n: formatNumber(suggestions.counts[s], locale()) }),
    })),
  );
  const minOptions = $derived([
    { value: '0', label: t('SUGGESTIONS.MIN_ANY') },
    ...MIN_SCORE_OPTIONS.map((v) => ({ value: String(v), label: t('SUGGESTIONS.MIN_ITEM', { score: formatScore(v, locale()) }) })),
  ]);

  // URL -> state (mount, back/forward)
  $effect(() => {
    const r = router.route;
    if (r.name !== 'suggestions') return;
    const query = r.query;
    untrack(() => {
      suggestions.applyRoute(query, first);
      first = false;
      qText = suggestions.list.q;
    });
  });

  // other screens (404 monitor, editor, imports) changed the data
  $effect(() => {
    const v = versions.suggestions;
    if (v !== seenVersion) {
      seenVersion = v;
      suggestions.refresh();
    }
  });

  onMount(() => {
    const off = registerSearch(searchEl);
    return () => {
      off();
      clearTimeout(debounce);
    };
  });

  function onSearchInput() {
    clearTimeout(debounce);
    debounce = setTimeout(() => suggestions.setList({ q: qText }), 250);
  }
  function onSearchKey(e: KeyboardEvent) {
    if (e.key === 'Escape' && qText) {
      e.preventDefault();
      qText = '';
      clearTimeout(debounce);
      suggestions.setList({ q: '' });
    } else if (e.key === 'Enter') {
      clearTimeout(debounce);
      suggestions.setList({ q: qText });
    }
  }
</script>

<div class="rm-sg">
  <div class="toolbar">
    <Segmented
      items={statusItems}
      value={suggestions.list.status}
      label={t('SUGGESTIONS.STATUS_LABEL')}
      onchange={(v: SuggestionStatus) => suggestions.setList({ status: v })}
    />
    <div class="search">
      <Input
        bind:el={searchEl}
        bind:value={qText}
        type="text"
        role="searchbox"
        muted
        clearable
        aria-label={t('SUGGESTIONS.SEARCH_LABEL')}
        placeholder={t('SUGGESTIONS.SEARCH_PLACEHOLDER')}
        aria-keyshortcuts="/"
        oninput={onSearchInput}
        onkeydown={onSearchKey}
      >
        {#snippet lead()}<Search size={15} />{/snippet}
      </Input>
    </div>
    <Select
      value={String(suggestions.list.min)}
      options={minOptions}
      aria-label={t('SUGGESTIONS.MIN_LABEL')}
      onchange={(e: Event) => suggestions.setList({ min: Number((e.currentTarget as HTMLSelectElement).value) })}
    />
    {#if can.manage}
      <Button class="push" variant="outline" loading={suggestions.generating} onclick={() => suggestions.generate()}>
        <Sparkles size={14} />{t('SUGGESTIONS.GENERATE')}
      </Button>
    {/if}
  </div>

  {#if isOpen && can.manage && suggestions.loaded && suggestions.rows.length > 0}
    <BulkPanel />
  {/if}

  <div class="card list-card">
    {#if failed}
      <ErrorState error={suggestions.error} onretry={() => suggestions.load()} />
    {:else if empty && !filtered && isOpen}
      {#if anyReviewed}
        <EmptyState icon={Sparkles} title={t('SUGGESTIONS.EMPTY_DONE_TITLE')} text={t('SUGGESTIONS.EMPTY_DONE_TEXT')}>
          {#snippet actions()}
            {#if can.manage}<Button variant="primary" size="default" loading={suggestions.generating} onclick={() => suggestions.generate()}>{t('SUGGESTIONS.GENERATE')}</Button>{/if}
            <Button variant="outline" size="default" href="#/404" onclick={linkClick}>{t('SUGGESTIONS.EMPTY_LINK')}</Button>
          {/snippet}
        </EmptyState>
      {:else}
        <EmptyState icon={Sparkles} title={t('SUGGESTIONS.EMPTY_TITLE')} text={t('SUGGESTIONS.EMPTY_TEXT')}>
          {#snippet actions()}
            {#if can.manage}<Button variant="primary" size="default" loading={suggestions.generating} onclick={() => suggestions.generate()}>{t('SUGGESTIONS.GENERATE')}</Button>{/if}
            <Button variant="outline" size="default" href="#/404" onclick={linkClick}>{t('SUGGESTIONS.EMPTY_LINK')}</Button>
          {/snippet}
        </EmptyState>
      {/if}
    {:else if empty && !filtered}
      <EmptyState
        icon={Sparkles}
        title={suggestions.list.status === 'accepted' ? t('SUGGESTIONS.EMPTY_ACCEPTED_TITLE') : t('SUGGESTIONS.EMPTY_REJECTED_TITLE')}
        text={suggestions.list.status === 'accepted' ? t('SUGGESTIONS.EMPTY_ACCEPTED_TEXT') : t('SUGGESTIONS.EMPTY_REJECTED_TEXT')}
      >
        {#snippet actions()}
          <Button variant="outline" size="default" onclick={() => suggestions.setList({ status: 'open' })}>{t('SUGGESTIONS.SHOW_OPEN')}</Button>
        {/snippet}
      </EmptyState>
    {:else if (empty && filtered) || noMatch}
      <EmptyState icon={Search} title={t('SUGGESTIONS.NO_MATCH_TITLE')} text={t('SUGGESTIONS.NO_MATCH_TEXT')}>
        {#snippet actions()}
          <Button
            variant="outline"
            size="default"
            onclick={() => {
              qText = '';
              suggestions.resetFilters();
            }}>{t('SUGGESTIONS.CLEAR_FILTERS')}</Button
          >
        {/snippet}
      </EmptyState>
    {:else}
      <SuggestionsTable rows={limited.rows} actions={isOpen && can.manage} />
      {#if suggestions.error}
        <div class="banner bad" role="alert" style="margin:0.75rem">
          <div class="b-body">{t('SUGGESTIONS.REFRESH_FAILED')}</div>
          <Button size="sm" onclick={() => suggestions.refresh()}>{t('COMMON.RETRY')}</Button>
        </div>
      {/if}
      {#if limited.hidden > 0}
        <p class="cap text-xs muted">
          {t('SUGGESTIONS.SHOWING_CAP', { shown: formatNumber(limited.rows.length, locale()), total: formatNumber(totalShown, locale()) })}
        </p>
      {/if}
    {/if}
  </div>

  <div class="sr-only" role="status" aria-live="polite">{suggestions.announcement}</div>
</div>

{#if suggestions.preview}
  <BulkPreview
    rows={suggestions.preview.rows}
    returnTo={suggestions.previewOpener}
    busy={suggestions.previewBusy}
    onconfirm={() => suggestions.commitPreview()}
    oncancel={() => suggestions.closePreview()}
  />
{/if}

<style>
  .rm-sg {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    container-type: inline-size;
  }
  .toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem 0.75rem;
  }
  .search {
    flex: 1 1 12rem;
    max-inline-size: 18rem;
  }
  .toolbar :global(.select-wrap) {
    inline-size: auto;
    min-inline-size: 9rem;
  }
  .toolbar :global(.push) {
    margin-inline-start: auto;
  }
  .list-card {
    overflow: hidden;
  }
  .cap {
    margin: 0;
    padding: 0.625rem 0.75rem;
    border-block-start: 1px solid var(--border);
  }
</style>
