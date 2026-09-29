<script lang="ts">
  import { onMount } from 'svelte';
  import { TriangleAlert } from 'lucide-svelte';
  import Tabs from './lib/ui/Tabs.svelte';
  import ToastHost from './lib/ui/ToastHost.svelte';
  import DialogHost from './lib/ui/DialogHost.svelte';
  import RuleEditor from './editor/RuleEditor.svelte';
  import RulesScreen from './screens/RulesScreen.svelte';
  import NotFoundScreen from './screens/NotFoundScreen.svelte';
  import SuggestionsScreen from './screens/SuggestionsScreen.svelte';
  import TesterScreen from './screens/TesterScreen.svelte';
  import ImportExportScreen from './screens/ImportExportScreen.svelte';
  import SettingsScreen from './screens/SettingsScreen.svelte';
  import { t, dir } from './lib/i18n.svelte';
  import { router, navigate, hrefFor } from './lib/router.svelte';
  import { tabOf, type TabId } from './lib/router';
  import { editor, openEditor, closeEditor, loadStats, statsState, versions, focusSearch } from './lib/state/app.svelte';
  import { isTypingTarget } from './lib/dom';
  import { rules } from './lib/state/rules.svelte';

  interface Props {
    /** the custom element hosting this app (for page-state / page-action events) */
    host: HTMLElement;
  }
  let { host }: Props = $props();

  const route = $derived(router.route);
  const tab = $derived(tabOf(route));
  const tabs = $derived([
    { id: 'rules', label: t('TAB.RULES'), href: hrefFor({ name: 'rules' }) },
    { id: 'notfound', label: t('TAB.NOTFOUND'), href: hrefFor({ name: 'notfound' }) },
    {
      id: 'suggestions',
      label: t('TAB.SUGGESTIONS'),
      href: hrefFor({ name: 'suggestions' }),
      badge: statsState.data?.open_suggestions ?? null,
      badgeLabel: t('TAB.SUGGESTIONS_COUNT', { n: statsState.data?.open_suggestions ?? 0 }),
    },
    { id: 'tester', label: t('TAB.TESTER'), href: hrefFor({ name: 'tester' }) },
    { id: 'importexport', label: t('TAB.IMPORTEXPORT'), href: hrefFor({ name: 'import' }) },
    { id: 'settings', label: t('TAB.SETTINGS'), href: hrefFor({ name: 'settings' }) },
  ]);

  function selectTab(id: string) {
    const map: Record<string, string> = { importexport: 'import' };
    // keep the sub-view when the tab is already the active one
    if (id === tab) return;
    navigate({ name: (map[id] ?? id) as never });
  }

  /* editor <-> route sync: #/rules/new and #/rules/<id> open the slide-over */
  $effect(() => {
    if (route.name === 'rule-new') {
      const q = route.query;
      openEditor({
        origin: 'route',
        prefill: { ...(q.source ? { source: q.source } : {}), ...(q.target ? { target: q.target } : {}) },
      });
    } else if (route.name === 'rule-edit') {
      openEditor({ origin: 'route', id: route.id });
    }
  });
  // closing an editor opened from the URL returns to the list
  $effect(() => {
    if (!editor.open && editor.request?.origin === 'route' && (route.name === 'rule-new' || route.name === 'rule-edit')) {
      navigate({ name: 'rules' }, { replace: true });
    }
  });

  let mainEl: HTMLElement | undefined = $state();

  onMount(() => {
    loadStats();

    const onKey = (e: KeyboardEvent) => {
      if (e.defaultPrevented || e.altKey) return;
      const path = e.composedPath();
      const inside = path.includes(host);
      const active = document.activeElement;
      // only act when focus is on this page (or nowhere), never inside other host dialogs
      if (!inside && !(active === document.body || active === null)) return;
      if (isTypingTarget(e.target)) return;
      // Ctrl/Cmd+Z brings back the rules deleted last (keyboard route to the Undo toast action)
      if ((e.metaKey || e.ctrlKey) && !e.shiftKey && e.key.toLowerCase() === 'z') {
        if (rules.canUndo) {
          e.preventDefault();
          void rules.undo();
        }
        return;
      }
      if (e.metaKey || e.ctrlKey) return;
      if (editor.open) return;
      if (e.key === 'n') {
        e.preventDefault();
        openEditor({});
      } else if (e.key === '/') {
        if (focusSearch()) e.preventDefault();
      }
    };
    document.addEventListener('keydown', onKey);

    const onAction = (e: Event) => {
      const id = (e as CustomEvent).detail?.id;
      if (id === 'new' || id === 'create' || id === 'add') openEditor({});
      else if (id === 'import') navigate({ name: 'import' });
      else if (id === 'export') navigate({ name: 'export' });
      else if (id === 'test' || id === 'tester') navigate({ name: 'tester' });
      else if (id === 'settings') navigate({ name: 'settings' });
    };
    host.addEventListener('page-action', onAction);
    host.dispatchEvent(new CustomEvent('page-state', { detail: { dirty: false, valid: true, busy: false } }));

    return () => {
      document.removeEventListener('keydown', onKey);
      host.removeEventListener('page-action', onAction);
    };
  });

  // Any screen that changes rules, suggestions or 404 state bumps the stats version:
  // refresh the counters (tab badge) shortly after, coalescing bursts of changes.
  let statsTimer: ReturnType<typeof setTimeout> | undefined;
  let statsSeen = versions.stats;
  $effect(() => {
    const v = versions.stats;
    if (v === statsSeen) return;
    statsSeen = v;
    clearTimeout(statsTimer);
    statsTimer = setTimeout(() => loadStats(), 400);
    return () => clearTimeout(statsTimer);
  });
</script>

<div class="rm-root" dir={dir()}>
  <div data-rm-main>
    <Tabs {tabs} active={tab} label={t('APP.NAV')} onselect={selectTab} />
    <div class="rm-view">
      <svelte:boundary>
        {#if tab === 'rules'}
          <RulesScreen />
        {:else if tab === 'notfound'}
          <NotFoundScreen />
        {:else if tab === 'suggestions'}
          <SuggestionsScreen />
        {:else if tab === 'tester'}
          <TesterScreen />
        {:else if tab === 'importexport'}
          <ImportExportScreen />
        {:else if tab === 'settings'}
          <SettingsScreen />
        {/if}
        {#snippet failed(error, reset)}
          <div class="empty" role="alert">
            <span class="ico" style="color:var(--rm-warn-fg)"><TriangleAlert size={20} /></span>
            <h3>{t('PAGE_ERROR.TITLE')}</h3>
            <p>{t('PAGE_ERROR.TEXT')}</p>
            <p class="text-xs muted mono">{(error as Error)?.message}</p>
            <button type="button" class="btn outline" onclick={reset}>{t('COMMON.RETRY')}</button>
          </div>
        {/snippet}
      </svelte:boundary>
    </div>
  </div>

  <RuleEditor />
  <ToastHost />
  <DialogHost />
</div>

<style>
  .rm-root {
    min-inline-size: 0;
  }
  .rm-view {
    padding-block-start: 1rem;
  }
</style>
