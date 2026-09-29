<script lang="ts">
  import { onMount, untrack } from 'svelte';
  import { Search, TriangleAlert } from 'lucide-svelte';
  import Button from '../lib/ui/Button.svelte';
  import EmptyState from '../lib/ui/EmptyState.svelte';
  import ErrorState from '../lib/ui/ErrorState.svelte';
  import Input from '../lib/ui/Input.svelte';
  import Pagination from '../lib/ui/Pagination.svelte';
  import Select from '../lib/ui/Select.svelte';
  import Switch from '../lib/ui/Switch.svelte';
  import NotFoundTable from './notfound/NotFoundTable.svelte';
  import TrendCard from './notfound/TrendCard.svelte';
  import { notFound } from './notfound/store.svelte';
  import { t } from '../lib/i18n.svelte';
  import { hasAnyFilter, NF_PAGE_SIZES, UA_CLASSES } from '../lib/notfound-query';
  import { linkClick, router } from '../lib/router.svelte';
  import { registerSearch, versions } from '../lib/state/app.svelte';
  import type { UaClass } from '../lib/types';

  let searchEl: HTMLInputElement | null = $state(null);
  let qText = $state(notFound.list.q);
  let debounce: ReturnType<typeof setTimeout> | undefined;
  let seenVersion = versions.notFound;
  let first = true;

  const filtered = $derived(hasAnyFilter(notFound.list));
  const nothingLogged = $derived(notFound.loaded && notFound.rows.length === 0 && notFound.total === 0 && !filtered);
  const noMatch = $derived(notFound.loaded && notFound.rows.length === 0 && notFound.total === 0 && filtered);
  const failed = $derived(!!notFound.error && !notFound.loaded);

  const classOptions = $derived([
    { value: '', label: t('NOTFOUND.CLASS_ALL') },
    ...UA_CLASSES.map((c) => ({ value: c, label: t(`NOTFOUND.CLASS_${c.toUpperCase()}`) })),
  ]);

  // URL -> state (mount, back/forward)
  $effect(() => {
    const r = router.route;
    if (r.name !== 'notfound') return;
    const query = r.query;
    untrack(() => {
      notFound.applyRoute(query, first);
      first = false;
      qText = notFound.list.q;
    });
  });

  // other screens (editor, suggestions, imports) changed the data
  $effect(() => {
    const v = versions.notFound;
    if (v !== seenVersion) {
      seenVersion = v;
      notFound.refresh();
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
    debounce = setTimeout(() => notFound.setList({ q: qText }), 250);
  }
  function onSearchKey(e: KeyboardEvent) {
    if (e.key === 'Escape' && qText) {
      e.preventDefault();
      qText = '';
      clearTimeout(debounce);
      notFound.setList({ q: '' });
    } else if (e.key === 'Enter') {
      clearTimeout(debounce);
      notFound.setList({ q: qText });
    }
  }
</script>

<div class="rm-nf">
  {#if !nothingLogged && !failed}
    <TrendCard />
  {/if}

  <div class="toolbar">
    <div class="search">
      <Input
        bind:el={searchEl}
        bind:value={qText}
        type="text"
        role="searchbox"
        muted
        clearable
        aria-label={t('NOTFOUND.SEARCH_LABEL')}
        placeholder={t('NOTFOUND.SEARCH_PLACEHOLDER')}
        aria-keyshortcuts="/"
        oninput={onSearchInput}
        onkeydown={onSearchKey}
      >
        {#snippet lead()}<Search size={15} />{/snippet}
      </Input>
    </div>
    <Select
      value={notFound.list.class}
      options={classOptions}
      aria-label={t('NOTFOUND.FILTER_CLASS')}
      onchange={(e: Event) => notFound.setList({ class: (e.currentTarget as HTMLSelectElement).value as UaClass | '' })}
    />
    <span class="sw text-xs">
      <Switch checked={notFound.list.bots} label={t('NOTFOUND.BOTS_LABEL')} onchange={(v) => notFound.setList({ bots: v })} />
      <span>{t('NOTFOUND.BOTS_LABEL')}</span>
    </span>
    <span class="sw text-xs">
      <Switch checked={notFound.list.include_resolved} label={t('NOTFOUND.RESOLVED_LABEL')} onchange={(v) => notFound.setList({ include_resolved: v })} />
      <span>{t('NOTFOUND.RESOLVED_LABEL')}</span>
    </span>
    <p class="summary text-xs muted num" role="status" aria-live="polite">{notFound.loaded ? notFound.announcement : ''}</p>
  </div>

  <div class="card list-card">
    {#if failed}
      <ErrorState error={notFound.error} onretry={() => notFound.refresh()} />
    {:else if nothingLogged}
      <EmptyState icon={TriangleAlert} title={t('NOTFOUND.EMPTY_TITLE')} text={t('NOTFOUND.EMPTY_TEXT')}>
        {#if !notFound.list.bots}<p class="text-xs">{t('NOTFOUND.EMPTY_BOTS_HINT')}</p>{/if}
        {#snippet actions()}
          {#if notFound.list.days < 90}
            <Button variant="primary" size="default" onclick={() => notFound.setList({ days: 90 })}>{t('NOTFOUND.EMPTY_LONGER')}</Button>
          {/if}
          <Button variant="outline" size="default" href="#/settings" onclick={linkClick}>{t('NOTFOUND.EMPTY_SETTINGS')}</Button>
        {/snippet}
      </EmptyState>
    {:else if noMatch}
      <EmptyState icon={Search} title={t('NOTFOUND.NO_MATCH_TITLE')} text={t('NOTFOUND.NO_MATCH_TEXT')}>
        {#snippet actions()}
          <Button
            variant="outline"
            size="default"
            onclick={() => {
              qText = '';
              notFound.resetFilters();
            }}>{t('NOTFOUND.CLEAR_FILTERS')}</Button
          >
        {/snippet}
      </EmptyState>
    {:else}
      <NotFoundTable />
      {#if notFound.error}
        <div class="banner bad" role="alert" style="margin:0.75rem">
          <div class="b-body">{t('NOTFOUND.REFRESH_FAILED')}</div>
          <Button size="sm" onclick={() => notFound.refresh()}>{t('COMMON.RETRY')}</Button>
        </div>
      {/if}
      {#if notFound.loaded}
        <Pagination
          total={notFound.total}
          page={notFound.list.page}
          perPage={notFound.list.per_page}
          sizes={NF_PAGE_SIZES}
          loading={notFound.loading}
          onpage={(p) => notFound.setList({ page: p })}
          onperpage={(n) => notFound.setList({ per_page: n })}
        />
      {/if}
    {/if}
  </div>
</div>

<style>
  .rm-nf {
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
    flex: 1 1 14rem;
    max-inline-size: 22rem;
  }
  .toolbar :global(.select-wrap) {
    inline-size: auto;
    min-inline-size: 9rem;
  }
  .sw {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    color: var(--foreground);
  }
  .summary {
    margin: 0;
    margin-inline-start: auto;
  }
  .list-card {
    overflow: hidden;
  }
</style>
