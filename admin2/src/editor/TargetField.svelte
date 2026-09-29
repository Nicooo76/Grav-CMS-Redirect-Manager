<script lang="ts">
  import { Lightbulb } from 'lucide-svelte';
  import Segmented from '../lib/ui/Segmented.svelte';
  import Combobox, { type ComboItem } from '../lib/ui/Combobox.svelte';
  import Field from '../lib/ui/Field.svelte';
  import { api, isAbort } from '../lib/api';
  import { t, locale } from '../lib/i18n.svelte';
  import { formatPercent } from '../lib/format';
  import type { RuleForm } from '../lib/editor-form';
  import type { PageHit, SuggestionCandidate, TargetType } from '../lib/types';

  interface Props {
    form: RuleForm;
    invalid?: boolean;
    error?: string | null;
    candidates?: SuggestionCandidate[];
  }
  let { form = $bindable(), invalid = false, error = null, candidates = [] }: Props = $props();

  let pageItems = $state<ComboItem[]>([]);
  let pageLoading = $state(false);
  let timer: ReturnType<typeof setTimeout> | undefined;
  let ctl: AbortController | null = null;

  async function searchPages(q: string) {
    clearTimeout(timer);
    timer = setTimeout(async () => {
      ctl?.abort();
      ctl = new AbortController();
      pageLoading = true;
      try {
        const { data } = await api.get<PageHit[]>('/redirects/pages', { q, limit: 8 }, ctl.signal);
        pageItems = (Array.isArray(data) ? data : []).map((p) => ({
          value: p.route,
          label: p.route,
          description: [p.title, (p.translations?.length ? p.translations : [p.language]).filter(Boolean).join(', ')].filter(Boolean).join(' · '),
          data: p,
        }));
      } catch (e) {
        if (!isAbort(e)) pageItems = [];
      } finally {
        pageLoading = false;
      }
    }, 200);
  }

  function setType(v: TargetType) {
    form.target_type = v;
    if (v === 'page') searchPages(form.target);
  }

  function pickCandidate(c: SuggestionCandidate) {
    form.target = c.target;
    form.target_type = /^https?:\/\//i.test(c.target) ? 'url' : 'page';
  }

  const types = $derived([
    { value: 'route', label: t('EDITOR.TT_ROUTE') },
    { value: 'url', label: t('EDITOR.TT_URL') },
    { value: 'page', label: t('EDITOR.TT_PAGE') },
  ]);
  const placeholder = $derived(form.target_type === 'url' ? 'https://example.org/page' : '/new-page');
  const hint = $derived(
    form.match_type !== 'exact' && form.target_type !== 'page' ? t('EDITOR.TARGET_PLACEHOLDERS') : t(`EDITOR.TT_${form.target_type.toUpperCase()}_HELP`),
  );
</script>

<Field label={t('EDITOR.TARGET')} {hint} {error} id="rm-target">
  {#snippet labelExtra()}
    <span class="spacer"></span>
    <Segmented size="sm" label={t('EDITOR.TARGET_TYPE')} items={types} value={form.target_type} onchange={setType} />
  {/snippet}
  {#snippet children({ id, describedBy })}
    {#if form.target_type === 'page'}
      <Combobox {id} bind:value={form.target} items={pageItems} allowCustom mono {describedBy} invalid={invalid} loading={pageLoading} onsearch={searchPages} placeholder={t('EDITOR.PAGE_SEARCH')} emptyText={t('EDITOR.PAGE_NONE')}>
        {#snippet option(it)}
          <span class="grow">
            <span class="mono">{it.label}</span>
            {#if it.description}<span class="muted text-xs" style="display:block">{it.description}</span>{/if}
          </span>
        {/snippet}
      </Combobox>
    {:else}
      <input {id} class="input mono" bind:value={form.target} {placeholder} aria-invalid={invalid || undefined} aria-describedby={describedBy} spellcheck="false" autocomplete="off" />
    {/if}
  {/snippet}
</Field>

{#if candidates.length}
  <div class="cands" role="group" aria-label={t('EDITOR.CANDIDATES')}>
    <div class="row text-xs muted"><Lightbulb size={13} />{t('EDITOR.CANDIDATES')}</div>
    <ul class="stack" style="gap:0.25rem;list-style:none;padding:0;margin:0">
      {#each candidates as c (c.target)}
        <li>
          <button type="button" class="cand" aria-pressed={form.target === c.target} onclick={() => pickCandidate(c)}>
            <span class="grow" style="min-inline-size:0">
              <span class="mono truncate" style="display:block">{c.target}</span>
              {#if c.page_title}<span class="muted text-xs truncate" style="display:block">{c.page_title}</span>{/if}
            </span>
            <span class="badge {c.score >= 0.85 ? 'ok' : c.score >= 0.6 ? 'info' : 'muted'} num">{formatPercent(c.score, locale())}</span>
          </button>
        </li>
      {/each}
    </ul>
  </div>
{/if}

<style>
  .cands {
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
    padding: 0.625rem;
    border: 1px dashed var(--border);
    border-radius: var(--rm-r-md);
    background: color-mix(in srgb, var(--muted) 30%, transparent);
  }
  .cand {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    inline-size: 100%;
    padding: 0.375rem 0.5rem;
    border: 1px solid var(--border);
    border-radius: var(--rm-r-md);
    background: var(--background);
    text-align: start;
  }
  .cand:hover {
    border-color: color-mix(in srgb, var(--primary) 50%, var(--border));
  }
  .cand[aria-pressed='true'] {
    border-color: var(--primary);
    box-shadow: 0 0 0 1px var(--primary);
  }
</style>
