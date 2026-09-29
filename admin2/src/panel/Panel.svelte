<script lang="ts">
  import { onMount, untrack } from 'svelte';
  import { api, ApiError, isAbort } from '../lib/api';
  import { ICONS, type IconName } from './icons';
  import { describeError } from '../lib/errors';
  import { dir, locale, t } from '../lib/i18n.svelte';
  import { formatRelative } from '../lib/format';
  import { adminUrl } from '../lib/widget';
  import { validationText } from '../lib/issue-text';
  import {
    LIST_MAX,
    PANEL_ENDPOINT,
    announceBadge,
    contextOf,
    hasError,
    normalizeContext,
    oldUrlBody,
    pushSidebarBadge,
    rulesHash,
    sourceFromInput,
    unseenRules,
    type PageContext,
    type PageRule,
  } from '../lib/panel';
  import Field from '../lib/ui/Field.svelte';
  import Skeleton from '../lib/ui/Skeleton.svelte';
  import type { Issue, ValidateResult } from '../lib/types';

  let { host }: { host: HTMLElement } = $props();

  let ctx = $state(untrack(() => contextOf(host)));
  let data = $state<PageContext | null>(null);
  let canManage = $state(false);
  let error = $state<unknown>(null);
  let announcement = $state('');
  let root = $state<HTMLElement | null>(null);

  const unseen = $derived(data ? unseenRules(data) : []);
  const nf = (n: number) => new Intl.NumberFormat(locale()).format(n);
  const when = (iso: string | null) => formatRelative(iso, locale());

  let loadCtl: AbortController | null = null;

  async function load(silent = false): Promise<void> {
    if (!ctx.route) return;
    loadCtl?.abort();
    const ctl = (loadCtl = new AbortController());
    if (!silent) error = null;
    try {
      const res = await api.get<unknown>(PANEL_ENDPOINT, { route: ctx.route, lang: ctx.lang }, ctl.signal);
      if (ctl.signal.aborted) return;
      data = normalizeContext(res.data);
      canManage = (res.meta as { permissions?: { manage?: boolean } })?.permissions?.manage === true;
      error = null;
      announceBadge(host, data.unseen);
    } catch (e) {
      if (isAbort(e) || ctl.signal.aborted) return;
      // a failed refresh keeps what is on screen
      if (!silent || !data) error = e;
    }
  }

  // the host changes route and lang while the panel is open (a rename moves the editor to the new address)
  $effect(() => {
    void [ctx.route, ctx.lang];
    untrack(() => {
      data = null;
      void load();
    });
  });

  function close(): void {
    host.dispatchEvent(new CustomEvent('close'));
  }

  function go(e: MouseEvent, hash: string): void {
    const nav = window.__GRAV_NAVIGATE;
    if (!nav || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    e.preventDefault();
    close();
    nav(adminUrl(hash));
  }

  function say(text: string): void {
    // the same text twice in a row would not be announced again
    announcement = announcement === text ? `${text} ` : text;
  }

  let seenBusy = $state(false);
  async function markSeen(): Promise<void> {
    if (seenBusy || !data) return;
    seenBusy = true;
    try {
      const res = await api.post<{ cleared: number; count: number | null; sidebar: number | null }>(`${PANEL_ENDPOINT}/seen`, { route: ctx.route, lang: ctx.lang });
      const clear = (rows: PageRule[]) => rows.map((r) => ({ ...r, unseen: false }));
      data = { ...data, incoming: clear(data.incoming), created: clear(data.created), unseen: 0 };
      announceBadge(host, 0);
      pushSidebarBadge(res.data?.sidebar ?? null);
      say(t('PANEL.MARKED_SEEN'));
    } catch (e) {
      hostToast('error', describeError(e));
    } finally {
      seenBusy = false;
    }
  }

  function hostToast(kind: 'success' | 'error', message: string): void {
    try {
      window.__GRAV_TOAST?.[kind](message);
    } catch {
      /* the live region below still says it */
    }
    say(message);
  }

  /* ---------- "Add an old URL" ---------- */

  let input = $state('');
  let issues = $state<Issue[]>([]);
  let checking = $state(false);
  let submitting = $state(false);
  const source = $derived(sourceFromInput(input));

  const toIssues = (e: unknown): Issue[] =>
    e instanceof ApiError && e.errors.length
      ? e.errors.map((x) => ({ code: x.code, field: x.field, severity: x.severity, message: x.message, params: x.params }))
      : [{ code: 'error', severity: 'error', message: describeError(e) }];

  // live check while typing: the same dry run the rule editor uses
  $effect(() => {
    const s = source;
    const route = ctx.route;
    const note = t('PANEL.ADD_NOTE');
    if (!s || !route || !canManage) {
      issues = [];
      checking = false;
      return;
    }
    checking = true;
    const ctl = new AbortController();
    const timer = setTimeout(async () => {
      try {
        const res = await api.post<ValidateResult>('/redirects/rules/validate', oldUrlBody(s, route, note), { signal: ctl.signal });
        issues = res.data?.issues ?? [];
      } catch (e) {
        if (isAbort(e)) return;
        issues = toIssues(e);
      }
      checking = false;
    }, 350);
    return () => {
      clearTimeout(timer);
      ctl.abort();
    };
  });

  async function add(e: SubmitEvent): Promise<void> {
    e.preventDefault();
    if (!source || submitting || !ctx.route) return;
    submitting = true;
    try {
      await api.post('/redirects/rules', oldUrlBody(source, ctx.route, t('PANEL.ADD_NOTE')));
      hostToast('success', t('PANEL.ADDED', { source }));
      input = '';
      issues = [];
      await load(true);
    } catch (err) {
      issues = toIssues(err);
    } finally {
      submitting = false;
    }
  }

  onMount(() => {
    const mo = new MutationObserver(() => {
      const next = contextOf(host);
      if (next.route !== ctx.route || next.lang !== ctx.lang) ctx = next;
    });
    mo.observe(host, { attributes: true, attributeFilter: ['route', 'lang'] });
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && !e.defaultPrevented) close();
    };
    document.addEventListener('keydown', onKey);
    // the host does not move focus into the panel
    root?.focus({ preventScroll: true });
    return () => {
      mo.disconnect();
      document.removeEventListener('keydown', onKey);
      loadCtl?.abort();
    };
  });
</script>

{#snippet icon(name: IconName, size = 15, cls = '')}
  <svg class={cls || undefined} width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    {#if name === 'circle-alert' || name === 'circle-check'}<circle cx="12" cy="12" r="10" />{/if}
    {#each ICONS[name] as d (d)}<path {d} />{/each}
  </svg>
{/snippet}

{#snippet ruleLine(r: PageRule)}
  <span class="mono">{r.source}</span>
  {@render icon('arrow-right', 12, 'flip')}
  <span class="mono">{r.target}</span>
  <span class="chip" title={t(`STATUS.${r.status}`)}>{r.status}</span>
{/snippet}

<section class="pn" bind:this={root} tabindex="-1" dir={dir()} aria-labelledby="pn-title" data-testid="rm-panel">
  <header class="pn-h">
    <div class="grow">
      <h2 id="pn-title">{t('PANEL.TITLE')}</h2>
      {#if ctx.route}
        <p class="pn-route"><span class="mono" data-testid="rm-panel-route">{ctx.route}</span>{#if ctx.lang}<span class="chip">{t('PANEL.LANGUAGE', { lang: ctx.lang })}</span>{/if}</p>
      {/if}
    </div>
    <button type="button" class="btn ghost icon" aria-label={t('COMMON.CLOSE')} onclick={close}>{@render icon('x', 16)}</button>
  </header>

  <div class="pn-b" aria-busy={data === null && error === null}>
    {#if error}
      <div class="empty" role="alert">
        <h3>{t('COMMON.ERROR_LOAD')}</h3>
        <p>{describeError(error)}</p>
        <button type="button" class="btn outline" onclick={() => void load()}>{t('COMMON.RETRY')}</button>
      </div>
    {:else if data === null}
      <div class="pn-skel" aria-hidden="true">
        <Skeleton h="1rem" w="60%" />
        <Skeleton h="2.5rem" />
        <Skeleton h="2.5rem" />
        <Skeleton h="1rem" w="40%" />
      </div>
    {:else}
      {#if data.outgoing}
        {@const o = data.outgoing}
        <div class="banner warn" data-testid="rm-panel-outgoing">
          {@render icon('triangle-alert')}
          <div class="b-body">
            <div class="b-title">{t('PANEL.OUTGOING_TITLE')}</div>
            <div>
              {#if o.location}
                {t('PANEL.OUTGOING_TEXT', { source: o.source, target: o.location, status: o.status })}
              {:else}
                {t('PANEL.OUTGOING_GONE', { source: o.source, status: o.status })}
              {/if}
            </div>
            <div class="actions"><a class="btn outline sm" href={adminUrl(`/rules/${encodeURIComponent(o.id)}`)} onclick={(e) => go(e, `/rules/${encodeURIComponent(o.id)}`)}>{t('PANEL.OUTGOING_OPEN')}</a></div>
          </div>
        </div>
      {/if}

      {#if unseen.length}
        <div class="banner ok" data-testid="rm-panel-unseen">
          {@render icon('circle-check')}
          <div class="b-body">
            <h3 class="b-title">{t('PANEL.UNSEEN_TITLE')}</h3>
            <div>{t('PANEL.UNSEEN_TEXT', { n: data.unseen || unseen.length })}</div>
            <ul aria-label={t('PANEL.UNSEEN_LIST')}>
              {#each unseen.slice(0, LIST_MAX) as r (r.id)}
                <li>{@render ruleLine(r)}</li>
              {/each}
            </ul>
            {#if (data.unseen || unseen.length) > LIST_MAX}
              <div class="text-xs muted">{t('PANEL.UNSEEN_MORE', { n: (data.unseen || unseen.length) - LIST_MAX })}</div>
            {/if}
            {#if canManage}
              <div class="actions"><button type="button" class="btn outline sm" aria-busy={seenBusy || undefined} disabled={seenBusy} onclick={markSeen}>{t('PANEL.MARK_SEEN')}</button></div>
            {/if}
          </div>
        </div>
      {/if}

      {#if data.pending.length}
        <div class="banner" data-testid="rm-panel-pending">
          {@render icon('triangle-alert')}
          <div class="b-body">
            <div>{t('PANEL.PENDING', { n: data.pending.length })}</div>
            <div class="actions"><a class="btn outline sm" href={adminUrl('/rules')} onclick={(e) => go(e, '/rules')}>{t('PANEL.PENDING_LINK')}</a></div>
          </div>
        </div>
      {/if}

      <section aria-labelledby="pn-in" data-testid="rm-panel-incoming">
        <h3 id="pn-in">{t('PANEL.INCOMING_TITLE')}</h3>
        {#if data.incoming.length}
          <ul class="pn-list">
            {#each data.incoming.slice(0, LIST_MAX) as r (r.id)}
              <li>
                <div class="pn-src">
                  <a class="mono" href={adminUrl(`/rules/${encodeURIComponent(r.id)}`)} onclick={(e) => go(e, `/rules/${encodeURIComponent(r.id)}`)}>{r.source}</a>
                  <span class="chip" title={t(`STATUS.${r.status}`)}>{r.status}</span>
                  {#if r.origin === 'auto'}<span class="badge info">{t('ORIGIN.AUTO')}</span>{/if}
                  {#if r.match_type === 'wildcard'}<span class="badge muted">{t('PANEL.SUBPAGES')}</span>{/if}
                  {#if r.state !== 'active'}<span class="badge muted">{t(`BADGE.${r.state.toUpperCase()}`)}</span>{/if}
                </div>
                <div class="pn-meta">
                  {#if r.hits > 0}
                    <span class="num">{t('COMMON.HITS', { n: r.hits })}</span>
                    {#if r.last_hit}<span class="sep">{t('PANEL.LAST_HIT', { when: when(r.last_hit) })}</span>{/if}
                  {:else}
                    <span>{t('PANEL.NO_HITS')}</span>
                  {/if}
                </div>
              </li>
            {/each}
          </ul>
          {#if data.incoming_total > LIST_MAX}
            <p style="margin-block-start:0.5rem"><a href={adminUrl(rulesHash(ctx.route))} onclick={(e) => go(e, rulesHash(ctx.route))}>{t('PANEL.INCOMING_MORE', { n: nf(data.incoming_total) })}</a></p>
          {/if}
        {:else}
          <p class="muted text-xs">{t('PANEL.INCOMING_EMPTY')}</p>
        {/if}
      </section>

      {#if data.not_found.length}
        <section aria-labelledby="pn-nf" data-testid="rm-panel-notfound">
          <h3 id="pn-nf">{t('PANEL.NOT_FOUND_TITLE')}</h3>
          <p class="lead">{t('PANEL.NOT_FOUND_TEXT')}</p>
          <ul class="pn-list">
            {#each data.not_found as n (n.path)}
              <li>
                <span class="mono">{n.path}</span>
                <div class="pn-meta"><span class="num">{t('COMMON.HITS', { n: n.hits })}</span><span class="sep">{t('PANEL.LAST_HIT', { when: when(n.last_seen) })}</span></div>
              </li>
            {/each}
          </ul>
        </section>
      {/if}

      <section aria-labelledby="pn-add" data-testid="rm-panel-add">
        <h3 id="pn-add">{t('PANEL.ADD_TITLE')}</h3>
        {#if canManage}
          <form onsubmit={add} novalidate>
            <Field label={t('PANEL.ADD_LABEL')} hint={t('PANEL.ADD_HELP')}>
              {#snippet children({ id, describedBy, invalid })}
                <div class="form-row">
                  <span class="input-wrap"><input {id} class="input mono" type="text" bind:value={input} placeholder={t('PANEL.ADD_PLACEHOLDER')} autocomplete="off" autocapitalize="off" spellcheck="false" aria-describedby={describedBy} aria-invalid={invalid || hasError(issues) || undefined} /></span>
                  <button type="submit" class="btn primary" aria-busy={submitting || undefined} disabled={!source || submitting || hasError(issues)}>{t('PANEL.ADD_SUBMIT')}</button>
                </div>
              {/snippet}
            </Field>
            <div class="status">{#if checking}{t('PANEL.ADD_CHECKING')}{/if}</div>
            {#if issues.length}
              <ul class="stack" style="gap:0.5rem;list-style:none;padding:0;margin:0" aria-label={t('PANEL.CHECKS')}>
                {#each issues as issue, i (issue.code + i)}
                  <li class="banner {issue.severity === 'error' ? 'bad' : issue.severity === 'warning' ? 'warn' : ''}">
                    {@render icon(issue.severity === 'error' ? 'circle-alert' : 'triangle-alert')}
                    <div class="b-body" role={issue.severity === 'error' ? 'alert' : undefined}>{validationText(issue)}</div>
                  </li>
                {/each}
              </ul>
            {/if}
          </form>
        {:else}
          <p class="muted text-xs">{t('PANEL.READ_ONLY')}</p>
        {/if}
      </section>
    {/if}
  </div>

  <footer class="pn-f">
    <a class="btn outline" href={adminUrl(ctx.route ? rulesHash(ctx.route) : '/rules')} onclick={(e) => go(e, ctx.route ? rulesHash(ctx.route) : '/rules')}>{t('PANEL.OPEN_MANAGER')}</a>
  </footer>

  <div class="sr-only" role="status" aria-live="polite">{announcement}</div>
</section>
