<script lang="ts">
  import { onMount, untrack } from 'svelte';
  import { Plus, Search, SlidersHorizontal, Columns3, Upload, ArrowRightLeft } from 'lucide-svelte';
  import Button from '../lib/ui/Button.svelte';
  import Input from '../lib/ui/Input.svelte';
  import Pagination from '../lib/ui/Pagination.svelte';
  import ErrorState from '../lib/ui/ErrorState.svelte';
  import EmptyState from '../lib/ui/EmptyState.svelte';
  import RulesTable from './rules/RulesTable.svelte';
  import FilterPanel from './rules/FilterPanel.svelte';
  import BulkBar from './rules/BulkBar.svelte';
  import ColumnChooser from './rules/ColumnChooser.svelte';
  import QuickFilters from './rules/QuickFilters.svelte';
  import { t } from '../lib/i18n.svelte';
  import { router, navigate } from '../lib/router.svelte';
  import { rules } from '../lib/state/rules.svelte';
  import { openEditor, registerSearch, versions } from '../lib/state/app.svelte';
  import { activeFilterCount, hasAnyFilter } from '../lib/rules-query';

  let searchEl: HTMLInputElement | null = $state(null);
  let qText = $state(rules.list.q);
  let filtersOpen = $state(false);
  let debounce: ReturnType<typeof setTimeout> | undefined;
  let seenVersion = versions.rules;

  const filterCount = $derived(activeFilterCount(rules.list));
  const filtered = $derived(hasAnyFilter(rules.list));
  const empty = $derived(rules.loaded && !rules.loading && rules.rows.length === 0 && rules.total === 0);

  // URL -> state (mount, back/forward). Only while the list route is showing.
  $effect(() => {
    const r = router.route;
    untrack(() => {
      if (r.name === 'rules') {
        rules.applyRoute(r.query);
        qText = rules.list.q;
        if (activeFilterCount(rules.list) > 0) filtersOpen = true;
      } else if (!rules.loaded) {
        rules.applyRoute({});
      }
    });
  });

  // other screens (editor, imports, undo) changed rules
  $effect(() => {
    const v = versions.rules;
    if (v !== seenVersion) {
      seenVersion = v;
      untrack(() => rules.load({ silent: true }));
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
    debounce = setTimeout(() => rules.setList({ q: qText }), 250);
  }
  function onSearchKey(e: KeyboardEvent) {
    if (e.key === 'Escape' && qText) {
      e.preventDefault();
      qText = '';
      clearTimeout(debounce);
      rules.setList({ q: '' });
    } else if (e.key === 'Enter') {
      clearTimeout(debounce);
      rules.setList({ q: qText });
    }
  }
</script>

<div class="rm-rules">
  <div class="toolbar">
    <div class="search">
      <Input
        bind:el={searchEl}
        bind:value={qText}
        type="text"
        role="searchbox"
        muted
        clearable
        aria-label={t('RULES.SEARCH_LABEL')}
        placeholder={t('RULES.SEARCH_PLACEHOLDER')}
        aria-keyshortcuts="/"
        oninput={onSearchInput}
        onkeydown={onSearchKey}
      >
        {#snippet lead()}<Search size={15} />{/snippet}
      </Input>
    </div>
    <Button variant="outline" aria-expanded={filtersOpen} aria-controls="rm-filters" onclick={() => (filtersOpen = !filtersOpen)}>
      <SlidersHorizontal size={15} />
      {t('RULES.FILTERS')}
      {#if filterCount}<span class="badge count" aria-label={t('RULES.FILTERS_ACTIVE', { n: filterCount })}>{filterCount}</span>{/if}
    </Button>
    <ColumnChooser />
    <span class="spacer"></span>
    <Button variant={empty && !filtered ? 'outline' : 'primary'} onclick={() => openEditor({})} aria-keyshortcuts="n">
      <Plus size={16} />
      {t('RULES.NEW')}
    </Button>
  </div>

  {#if filtersOpen}
    <FilterPanel id="rm-filters" />
  {/if}

  <QuickFilters />

  <div class="card list-card" data-loaded={rules.loaded}>
    {#if rules.selectedCount > 0}<BulkBar />{/if}

    {#if rules.error && !rules.loaded}
      <ErrorState error={rules.error} onretry={() => rules.load()} />
    {:else if empty && !filtered}
      <EmptyState icon={ArrowRightLeft} title={t('RULES.EMPTY_TITLE')} text={t('RULES.EMPTY_TEXT')}>
        <div class="example" aria-label={t('RULES.EXAMPLE_LABEL')}>
          <span class="mono">/old-page</span>
          <span class="arrow" aria-hidden="true">→</span>
          <span class="mono">/new-page</span>
          <span class="badge muted">301</span>
        </div>
        {#snippet actions()}
          <Button variant="primary" size="default" onclick={() => openEditor({})}><Plus size={16} />{t('RULES.EMPTY_CTA')}</Button>
          <Button variant="outline" size="default" href="#/import"><Upload size={15} />{t('RULES.EMPTY_IMPORT')}</Button>
        {/snippet}
      </EmptyState>
    {:else if empty && filtered}
      <EmptyState icon={Search} title={t('RULES.NO_MATCH_TITLE')} text={t('RULES.NO_MATCH_TEXT')}>
        {#snippet actions()}
          <Button
            variant="outline"
            size="default"
            onclick={() => {
              qText = '';
              rules.resetFilters();
            }}>{t('RULES.CLEAR_FILTERS')}</Button
          >
        {/snippet}
      </EmptyState>
    {:else}
      <RulesTable />
      {#if rules.error}
        <div class="banner bad" role="alert" style="margin:0.75rem">
          <div class="b-body">{t('RULES.REFRESH_FAILED')}</div>
          <Button size="sm" onclick={() => rules.load()}>{t('COMMON.RETRY')}</Button>
        </div>
      {/if}
      <Pagination
        total={rules.total}
        page={rules.list.page}
        perPage={rules.list.per_page}
        loading={rules.loading}
        onpage={(p) => rules.setList({ page: p })}
        onperpage={(n) => rules.setList({ per_page: n })}
      />
    {/if}
  </div>

  <div class="sr-only" role="status" aria-live="polite">{rules.announcement}</div>
</div>

<style>
  .rm-rules {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    container-type: inline-size;
  }
  .toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
  }
  .search {
    flex: 1 1 16rem;
    max-inline-size: 26rem;
  }
  .list-card {
    overflow: hidden;
  }
  .example {
    display: inline-flex;
    align-items: center;
    gap: 0.625rem;
    margin-block-start: 0.5rem;
    padding: 0.5rem 0.875rem;
    border: 1px dashed var(--border);
    border-radius: var(--rm-r-md);
    background: color-mix(in srgb, var(--muted) 40%, transparent);
    color: var(--foreground);
  }
  .arrow {
    color: var(--muted-foreground);
  }
</style>
