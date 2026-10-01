<script lang="ts">
  import { onMount, untrack } from 'svelte';
  import { ChevronRight, FlaskConical, Play, TriangleAlert } from 'lucide-svelte';
  import Button from '../lib/ui/Button.svelte';
  import EmptyState from '../lib/ui/EmptyState.svelte';
  import Field from '../lib/ui/Field.svelte';
  import Input from '../lib/ui/Input.svelte';
  import Select from '../lib/ui/Select.svelte';
  import Skeleton from '../lib/ui/Skeleton.svelte';
  import KeyValueRows, { type Row } from './tester/KeyValueRows.svelte';
  import ResultCard from './tester/ResultCard.svelte';
  import { ApiError, api, isAbort } from '../lib/api';
  import { describeError } from '../lib/errors';
  import { t, dir } from '../lib/i18n.svelte';
  import { linkClick, navigate, router } from '../lib/router.svelte';
  import { registerSearch } from '../lib/state/app.svelte';
  import { buildTestBody, normalizeTestUrl, summarize } from '../lib/tester';
  import type { Rule, TestResponse } from '../lib/types';

  let inputEl: HTMLInputElement | null = $state(null);
  let url = $state('');
  let language = $state('');
  let method = $state('GET');
  let userAgent = $state('');
  let headers = $state<Row[]>([]);
  let cookies = $state<Row[]>([]);
  let advancedOpen = $state(false);

  let loading = $state(false);
  let error = $state<unknown>(null);
  let fieldError = $state<string | null>(null);
  let resp = $state<TestResponse | null>(null);
  let testedUrl = $state('');
  let rule = $state<Rule | null>(null);
  let ruleLoading = $state(false);
  // What the live region says: kept as key and numbers, not as text, so it follows the language when the dictionary
  // arrives after the result (a deep link tests on mount, before the host has loaded the German texts).
  let announced = $state<{ key: string; status: number | null; url: string } | null>(null);
  const announcement = $derived(announced ? `${t(announced.key, { status: announced.status ?? '' })}. ${announced.url}` : '');

  let lastRun = '';
  let ctl: AbortController | null = null;

  const outcome = $derived(resp ? summarize(resp) : null);
  const advancedCount = $derived(
    (language.trim() ? 1 : 0) +
      (method !== 'GET' ? 1 : 0) +
      (userAgent.trim() ? 1 : 0) +
      headers.filter((h) => h.key.trim()).length +
      cookies.filter((c) => c.key.trim()).length,
  );

  const methodOptions = [
    { value: 'GET', label: 'GET' },
    { value: 'HEAD', label: 'HEAD' },
  ];

  onMount(() => {
    const off = registerSearch(inputEl);
    return () => {
      off();
      ctl?.abort();
    };
  });

  // Deep link: #/tester?url=/foo fills the field and runs once. Our own hash update sets lastRun first.
  $effect(() => {
    const r = router.route;
    if (r.name !== 'tester') return;
    const q = r.query.url ?? '';
    untrack(() => {
      if (q && q !== lastRun) {
        url = q;
        void run();
      }
    });
  });

  async function run(): Promise<void> {
    const normalized = normalizeTestUrl(url);
    if (!normalized) {
      fieldError = t('TESTER.URL_REQUIRED');
      inputEl?.focus();
      return;
    }
    ctl?.abort();
    const mine = new AbortController();
    ctl = mine;
    lastRun = normalized;
    loading = true;
    error = null;
    fieldError = null;
    testedUrl = normalized;
    navigate({ name: 'tester', query: { url: normalized } }, { replace: true });
    try {
      const { data } = await api.post<TestResponse>('/redirects/test', buildTestBody(normalized, { method, language, userAgent, headers, cookies }), {
        signal: mine.signal,
      });
      if (ctl !== mine) return;
      resp = data;
      const o = summarize(data);
      announced = { key: o.headlineKey, status: o.status, url: o.finalUrl };
      void loadRule(data.result?.rule_id ?? null, mine);
    } catch (e) {
      if (isAbort(e) || ctl !== mine) return;
      if (e instanceof ApiError && e.status === 422 && e.fieldMessages.url) {
        fieldError = e.fieldMessages.url;
        inputEl?.focus();
      } else {
        error = e;
      }
    } finally {
      if (ctl === mine) loading = false;
    }
  }

  async function loadRule(id: string | null, mine: AbortController): Promise<void> {
    rule = null;
    if (!id) {
      ruleLoading = false;
      return;
    }
    ruleLoading = true;
    try {
      const { data } = await api.get<Rule>(`/redirects/rules/${encodeURIComponent(id)}`, undefined, mine.signal);
      if (ctl === mine) rule = data;
    } catch {
      /* a deleted rule is shown as such */
    } finally {
      if (ctl === mine) ruleLoading = false;
    }
  }

  function onSubmit(e: SubmitEvent) {
    e.preventDefault();
    void run();
  }
</script>

<div class="rm-tester">
  <form class="card" onsubmit={onSubmit} novalidate>
    <div class="card-b stack">
      <Field label={t('TESTER.URL_LABEL')} hint={t('TESTER.URL_HINT')} error={fieldError}>
        {#snippet children({ id, describedBy, invalid })}
          <div class="line">
            <div class="grow">
              <Input
                {id}
                bind:el={inputEl}
                bind:value={url}
                size="lg"
                mono
                type="text"
                inputmode="url"
                autocomplete="off"
                autocapitalize="off"
                spellcheck="false"
                placeholder="/old-page"
                aria-describedby={describedBy}
                aria-invalid={invalid || undefined}
                aria-keyshortcuts="/"
              />
            </div>
            <Button type="submit" variant="primary" size="default" {loading}>
              {#if !loading}<Play size={15} />{/if}{t('TESTER.RUN')}
            </Button>
          </div>
        {/snippet}
      </Field>

      <details class="adv" bind:open={advancedOpen}>
        <summary>
          <span class="chev" class:open={advancedOpen} class:flip={dir() === 'rtl'} aria-hidden="true"><ChevronRight size={14} /></span>
          {t('TESTER.ADVANCED')}
          {#if advancedCount && !advancedOpen}<span class="badge count" aria-label={t('TESTER.ADVANCED_ACTIVE', { n: advancedCount })}>{advancedCount}</span>{/if}
        </summary>
        <div class="adv-body stack">
          <div class="field-grid">
            <Field label={t('TESTER.LANGUAGE')} hint={t('TESTER.LANGUAGE_HINT')} optional>
              {#snippet children({ id })}
                <Input {id} bind:value={language} placeholder="de" autocomplete="off" spellcheck="false" maxlength={12} />
              {/snippet}
            </Field>
            <Field label={t('TESTER.METHOD')} optional>
              {#snippet children({ id })}
                <Select {id} bind:value={method} options={methodOptions} />
              {/snippet}
            </Field>
          </div>
          <Field label={t('TESTER.USER_AGENT')} optional>
            {#snippet children({ id })}
              <Input {id} bind:value={userAgent} mono placeholder="Mozilla/5.0 …" autocomplete="off" spellcheck="false" />
            {/snippet}
          </Field>
          <KeyValueRows
            bind:rows={headers}
            label={t('TESTER.HEADERS')}
            keyLabel={t('TESTER.HEADER_NAME')}
            valueLabel={t('TESTER.HEADER_VALUE')}
            keyPlaceholder="Referer"
            addLabel={t('TESTER.ADD_HEADER')}
          />
          <KeyValueRows
            bind:rows={cookies}
            label={t('TESTER.COOKIES')}
            keyLabel={t('TESTER.COOKIE_NAME')}
            valueLabel={t('TESTER.COOKIE_VALUE')}
            keyPlaceholder="lang"
            addLabel={t('TESTER.ADD_COOKIE')}
          />
        </div>
      </details>
    </div>
  </form>

  <div class="sr-only" role="status" aria-live="polite">{announcement}</div>

  {#if loading && !resp}
    <div class="card" aria-busy="true">
      <div class="card-b stack" aria-hidden="true">
        <Skeleton w="14rem" h="1.5rem" />
        <Skeleton w="60%" />
        <Skeleton h="5rem" />
      </div>
    </div>
  {:else if error}
    <div class="banner bad" role="alert">
      <TriangleAlert size={16} />
      <div class="b-body">
        <div class="b-title">{t('TESTER.ERROR')}</div>
        {describeError(error)}
      </div>
      <Button onclick={() => run()}>{t('COMMON.RETRY')}</Button>
    </div>
  {/if}

  {#if resp && outcome && !(loading && !resp) && !error}
    <div class="result-wrap" class:stale={loading} aria-busy={loading}>
      <ResultCard {resp} {outcome} {rule} {ruleLoading} {testedUrl} />
    </div>
  {:else if !resp && !loading && !error}
    <div class="card">
      <EmptyState icon={FlaskConical} title={t('TESTER.EMPTY_TITLE')} text={t('TESTER.EMPTY_TEXT')}>
        <p class="ex-label text-xs muted">{t('TESTER.EXAMPLES')}</p>
        <div class="row wrap" style="justify-content:center">
          <a class="btn outline sm" href="#/tester?url=%2Fold-page" onclick={linkClick}><span class="mono">/old-page</span></a>
          <a class="btn outline sm" href="#/tester?url=https%3A%2F%2Fexample.org%2Fold-page%3Futm_source%3Dnews" onclick={linkClick}
            ><span class="mono">https://example.org/old-page?utm_source=news</span></a
          >
        </div>
      </EmptyState>
    </div>
  {/if}
</div>

<style>
  .rm-tester {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    container-type: inline-size;
    max-inline-size: 64rem;
  }
  .line {
    display: flex;
    gap: 0.5rem;
    align-items: center;
  }
  .adv > summary {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    cursor: pointer;
    font-size: var(--rm-text-xs);
    font-weight: 500;
    color: var(--muted-foreground);
    list-style: none;
    padding-block: 0.125rem;
    border-radius: var(--rm-r-sm);
  }
  .adv > summary::-webkit-details-marker {
    display: none;
  }
  .adv > summary:hover {
    color: var(--foreground);
  }
  .chev {
    display: inline-flex;
  }
  .chev.flip {
    transform: scaleX(-1);
  }
  .chev.open {
    transform: rotate(90deg);
  }
  .adv-body {
    padding-block-start: 0.75rem;
  }
  .ex-label {
    margin: 0.5rem 0 0.375rem;
  }
  .result-wrap.stale {
    opacity: 0.6;
  }
</style>
