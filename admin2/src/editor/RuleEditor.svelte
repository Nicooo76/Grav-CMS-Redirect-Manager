<script lang="ts">
  import { untrack, tick } from 'svelte';
  import { Trash2, FlaskConical, Lightbulb } from 'lucide-svelte';
  import Slideover from '../lib/ui/Slideover.svelte';
  import Button from '../lib/ui/Button.svelte';
  import Field from '../lib/ui/Field.svelte';
  import Select from '../lib/ui/Select.svelte';
  import Segmented from '../lib/ui/Segmented.svelte';
  import Switch from '../lib/ui/Switch.svelte';
  import Disclosure from '../lib/ui/Disclosure.svelte';
  import Combobox from '../lib/ui/Combobox.svelte';
  import ChipsInput from '../lib/ui/ChipsInput.svelte';
  import InfoPopover from '../lib/ui/InfoPopover.svelte';
  import Skeleton from '../lib/ui/Skeleton.svelte';
  import ErrorState from '../lib/ui/ErrorState.svelte';
  import RuleBadge from '../lib/ui/RuleBadge.svelte';
  import RegexHelp from './RegexHelp.svelte';
  import TargetField from './TargetField.svelte';
  import PreviewPanel from './PreviewPanel.svelte';
  import IssueList from './IssueList.svelte';
  import QueryEditor from './QueryEditor.svelte';
  import ConditionsEditor from './ConditionsEditor.svelte';
  import { api, ApiError, isAbort } from '../lib/api';
  import { describeError } from '../lib/errors';
  import { validationText } from '../lib/issue-text';
  import { t, locale } from '../lib/i18n.svelte';
  import { formatDateTime, formatNumber, formatRelative, isoToLocalInput, localInputToIso } from '../lib/format';
  import { blankRule } from '../lib/rule-utils';
  import { sampleFromSource } from '../lib/sample';
  import { suggestMatchType } from '../lib/match-hint';
  import { navigate } from '../lib/router.svelte';
  import { getLastRulesQuery, rules } from '../lib/state/rules.svelte';
  import { bump, can, closeEditor, defaultStatus, editor } from '../lib/state/app.svelte';
  import { dialogs, toast } from '../lib/state/notify.svelte';
  import {
    STATUS_CODES,
    advancedState,
    clientChecks,
    diffPayload,
    formToPayload,
    hasBlockingIssue,
    initialForm,
    isDirty,
    issueField,
    needsTarget,
    type FieldName,
    type RuleForm,
  } from '../lib/editor-form';
  import type { GroupsResult, Issue, MatchType, PreviewResult, Rule, RuleInput } from '../lib/types';

  const req = $derived(editor.request);

  let form = $state<RuleForm>(blankRule());
  let initial: RuleForm = blankRule();
  let ruleId = $state<string | null>(null);
  let stored = $state<Rule | null>(null);
  let etag: string | null = null;
  let loading = $state(false);
  let loadError = $state<unknown>(null);
  let saving = $state(false);
  let saveError = $state<string | null>(null);
  let serverErrors = $state<Partial<Record<FieldName, string>>>({});
  let touched = $state(false);
  let issues = $state<Issue[]>([]);
  let sample = $state('');
  let sampleTouched = $state(false);
  /** `answered`: the API sent a preview for the sample; its `result` is null when no rule matches the sample at all. */
  let pv = $state<{ loading: boolean; result: PreviewResult['result'] | null; answered: boolean; unavailable: boolean }>({ loading: false, result: null, answered: false, unavailable: false });
  let groupNames = $state<string[]>([]);
  let tagNames = $state<string[]>([]);
  let open = $state({ query: false, conditions: false, behaviour: false, schedule: false, organise: false });
  let bodyEl: HTMLElement | undefined = $state();

  const isNew = $derived(ruleId === null);
  const dirty = $derived(!loading && isDirty(initial, form));
  const needTarget = $derived(needsTarget(form.status));
  const readOnly = $derived(!can.manage);
  const matchSuggestion = $derived(suggestMatchType(form.source, form.match_type as MatchType));

  /* ---------- open / load ---------- */
  let lastReq: unknown = null;
  $effect(() => {
    const r = editor.request;
    if (editor.open && r && r !== lastReq) {
      lastReq = r;
      untrack(() => start(r));
    }
  });

  async function start(r: NonNullable<typeof req>) {
    ruleId = r.id ?? null;
    stored = null;
    etag = null;
    loadError = null;
    saveError = null;
    serverErrors = {};
    touched = false;
    issues = [];
    pv = { loading: false, result: null, answered: false, unavailable: false };
    sampleTouched = false;
    if (r.id) {
      loading = true;
      form = blankRule();
      initial = blankRule();
      try {
        const res = await api.get<Rule>(`/redirects/rules/${encodeURIComponent(r.id)}`);
        if (editor.request !== r) return;
        etag = res.headers.get('ETag');
        stored = res.data;
        form = initialForm(res.data);
        initial = initialForm(res.data);
        if (res.data.issues) issues = res.data.issues;
        open = advancedState(form);
      } catch (e) {
        if (editor.request !== r) return;
        loadError = e;
      } finally {
        if (editor.request === r) loading = false;
      }
    } else {
      loading = false;
      form = initialForm(null, r.prefill ?? {}, defaultStatus());
      initial = initialForm(null, r.prefill ?? {}, defaultStatus());
      // a duplicated or prefilled rule counts as unsaved only once the user changed something
      open = advancedState(form);
      if (r.prefill && Object.keys(r.prefill).length > 0 && r.candidates?.length && !form.target) {
        // leave target empty: the user picks from the suggestions
      }
    }
    loadTaxonomy();
  }

  async function loadTaxonomy() {
    if (groupNames.length || tagNames.length) return;
    try {
      const { data } = await api.get<GroupsResult>('/redirects/groups');
      groupNames = (data.groups ?? []).map((g) => g.name).filter(Boolean);
      tagNames = (data.tags ?? []).map((g) => g.name).filter(Boolean);
    } catch {
      groupNames = rules.groups.filter(Boolean);
    }
  }

  /* ---------- sample + live validation ---------- */
  $effect(() => {
    const src = form.source;
    const mt = form.match_type;
    if (!sampleTouched) untrack(() => (sample = sampleFromSource(src, mt)));
  });

  let vTimer: ReturnType<typeof setTimeout> | undefined;
  let vCtl: AbortController | null = null;
  $effect(() => {
    if (!editor.open || loading || loadError) return;
    const payload = formToPayload(form);
    const smp = sample;
    const id = ruleId;
    untrack(() => scheduleValidate(payload, smp, id));
  });

  function scheduleValidate(payload: RuleInput, smp: string, id: string | null) {
    clearTimeout(vTimer);
    if (!payload.source) {
      vCtl?.abort();
      issues = [];
      pv = { loading: false, result: null, answered: false, unavailable: false };
      return;
    }
    pv.loading = true;
    vTimer = setTimeout(() => runValidate(payload, smp, id), 250);
  }

  async function runValidate(payload: RuleInput, smp: string, id: string | null) {
    vCtl?.abort();
    const ctl = new AbortController();
    vCtl = ctl;
    try {
      const { data } = await api.post<{ issues: Issue[]; preview?: PreviewResult | null }>('/redirects/rules/validate', { ...payload, ...(id ? { id } : {}), ...(smp ? { sample: smp } : {}) }, { signal: ctl.signal });
      issues = data.issues ?? [];
      pv = { loading: false, result: data.preview?.result ?? null, answered: !!data.preview, unavailable: false };
    } catch (e) {
      if (isAbort(e)) return;
      if (e instanceof ApiError && e.status === 422 && e.errors.length) {
        issues = e.errors.map((x) => ({ code: x.code, severity: x.severity, message: x.message, field: x.field, params: x.params }) as Issue);
        pv = { loading: false, result: null, answered: false, unavailable: false };
      } else {
        pv = { loading: false, result: null, answered: false, unavailable: true };
      }
    }
  }

  /* ---------- field errors ---------- */
  const clientProblems = $derived(touched ? clientChecks(form) : []);
  const fieldError = $derived.by(() => {
    const out: Partial<Record<FieldName, string>> = { ...serverErrors };
    for (const p of clientProblems) if (!out[p.field]) out[p.field] = t(p.code);
    return out;
  });
  const invalidFields = $derived.by(() => {
    const set = new Set<FieldName>(Object.keys(fieldError) as FieldName[]);
    for (const i of issues) if (i.severity === 'error') set.add(issueField(i));
    return set;
  });
  const blocked = $derived(hasBlockingIssue(issues));

  /* ---------- actions ---------- */
  async function requestClose() {
    if (saving) return;
    if (dirty) {
      const ok = await dialogs.confirm({
        title: t('EDITOR.DISCARD_TITLE'),
        message: t('EDITOR.DISCARD_TEXT'),
        confirmLabel: t('EDITOR.DISCARD'),
        cancelLabel: t('EDITOR.KEEP_EDITING'),
        variant: 'destructive',
      });
      if (!ok) return;
    }
    closeEditor();
  }

  function focusField(name: FieldName) {
    const map: Partial<Record<FieldName, string>> = { source: '#rm-source', target: '#rm-target', status: '#rm-status', expires_at: '#rm-expires' };
    const el = bodyEl?.querySelector<HTMLElement>(map[name] ?? '[aria-invalid=true]');
    el?.focus();
  }

  async function save() {
    if (saving || loading || readOnly) return;
    touched = true;
    saveError = null;
    const problems = clientChecks(form);
    if (problems.length) {
      await tick();
      focusField(problems[0].field);
      return;
    }
    if (blocked) {
      saveError = t('EDITOR.BLOCKED');
      return;
    }
    saving = true;
    serverErrors = {};
    try {
      const payload = formToPayload(form);
      let saved: Rule;
      if (ruleId) {
        const body = diffPayload(formToPayload(initial), payload);
        if (Object.keys(body).length === 0) {
          closeEditor();
          return;
        }
        const res = await api.patch<Rule>(`/redirects/rules/${encodeURIComponent(ruleId)}`, body, { headers: etag ? { 'If-Match': etag } : {} });
        saved = res.data;
      } else {
        const res = await api.post<Rule>('/redirects/rules', payload);
        saved = res.data;
      }
      toast.success(t(ruleId ? 'EDITOR.SAVED' : 'EDITOR.CREATED'));
      const warn = saved?.issues?.find((i) => i.severity === 'warning');
      if (warn) toast.warning(validationText(warn));
      bump('rules');
      const cb = req?.onsaved;
      closeEditor();
      cb?.(saved);
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) {
        const next: Partial<Record<FieldName, string>> = {};
        const general: string[] = [];
        for (const er of e.errors) {
          if (er.severity === 'warning') continue;
          const f = issueField({ code: er.code, field: er.field } as unknown as Issue);
          const text = validationText(er);
          if (f === 'general') general.push(text);
          else if (!next[f]) next[f] = text;
        }
        serverErrors = next;
        saveError = general[0] ?? (Object.keys(next).length ? t('EDITOR.FIX_FIELDS') : e.detail || t('COMMON.ERROR'));
        await tick();
        const first = Object.keys(next)[0] as FieldName | undefined;
        if (first) focusField(first);
      } else if (e instanceof ApiError && (e.status === 409 || e.status === 412)) {
        saveError = t('EDITOR.CHANGED_ELSEWHERE');
      } else {
        saveError = describeError(e);
      }
    } finally {
      saving = false;
    }
  }

  async function remove() {
    if (!ruleId) return;
    const id = ruleId;
    closeEditor();
    await rules.remove([id]);
    bump('rules');
  }

  /** One-click fix of the self_redirect that differs only in letter case. */
  function fixCase() {
    form.case_sensitive = true;
    open.behaviour = true;
  }

  function openOther(id: string) {
    if (dirty) {
      void dialogs
        .confirm({ title: t('EDITOR.DISCARD_TITLE'), message: t('EDITOR.DISCARD_TEXT'), confirmLabel: t('EDITOR.DISCARD'), cancelLabel: t('EDITOR.KEEP_EDITING'), variant: 'destructive' })
        .then((ok) => {
          if (ok) navigate({ name: 'rule-edit', id });
        });
    } else navigate({ name: 'rule-edit', id });
  }

  function onclosed() {
    // reset so a stale form never flashes on the next open
    lastReq = null;
    if (editor.request?.origin === 'route') navigate({ name: 'rules', query: getLastRulesQuery() }, { replace: true });
  }

  function onkey(e: KeyboardEvent) {
    if (readOnly) return;
    if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') {
      e.preventDefault();
      void save();
    }
  }

  const matchItems = $derived([
    { value: 'exact', label: t('MATCH.EXACT'), title: t('MATCH_HELP.EXACT') },
    { value: 'wildcard', label: t('MATCH.WILDCARD'), title: t('MATCH_HELP.WILDCARD') },
    { value: 'regex', label: t('MATCH.REGEX'), title: t('MATCH_HELP.REGEX') },
  ]);
  const statusOptions = $derived(STATUS_CODES.map((c) => ({ value: String(c), label: `${c} · ${t(`STATUS.${c}`)}` })));
  const sourceHint = $derived(t(`EDITOR.SOURCE_HINT_${form.match_type.toUpperCase()}`));
  const candidates = $derived(req?.candidates ?? []);
  const title = $derived(readOnly && !isNew ? t('EDITOR.TITLE_VIEW') : isNew ? t('EDITOR.TITLE_NEW') : t('EDITOR.TITLE_EDIT'));

  const sectionSummary = $derived({
    query: form.query_mode === 'ignore' && !form.query_ignore.length ? '' : t(`EDITOR.QM_${form.query_mode.toUpperCase()}`),
    conditions: [form.conditions.hosts.length, form.conditions.languages.length, form.conditions.schemes.length, form.conditions.rules.length].reduce((a, b) => a + b, 0),
    schedule: form.active_from || form.expires_at ? [form.active_from ? formatDateTime(form.active_from, locale()) : '', form.expires_at ? '→ ' + formatDateTime(form.expires_at, locale()) : ''].filter(Boolean).join(' ') : '',
    organise: [form.group, form.tags.length ? t('EDITOR.TAGS_COUNT', { n: form.tags.length }) : ''].filter(Boolean).join(' · '),
  });
</script>

<Slideover open={editor.open} {title} onrequestclose={requestClose} {onclosed}>
  {#snippet headerExtra()}
    {#if stored?.badges?.length}
      <span class="row" style="gap:0.25rem">{#each stored.badges.filter((b) => b !== 'active') as b (b)}<RuleBadge kind={b} compact />{/each}</span>
    {/if}
  {/snippet}

  <div bind:this={bodyEl} class="ed" onkeydown={onkey} role="presentation">
    {#if loading}
      <div class="stack" aria-busy="true" aria-label={t('COMMON.LOADING')}>
        <Skeleton h="2rem" />
        <Skeleton h="5rem" />
        <Skeleton h="2rem" />
        <Skeleton h="2rem" w="60%" />
      </div>
    {:else if loadError}
      <ErrorState error={loadError} title={t('EDITOR.LOAD_FAILED')} onretry={() => req && start(req)} />
    {:else}
      {#if saveError}
        <div class="banner bad" role="alert"><div class="b-body">{saveError}</div></div>
      {/if}

      {#if stored}
        <div class="meta row wrap text-xs muted">
          {#if stored.created_at}<span title={formatDateTime(stored.created_at, locale())}>{t('EDITOR.CREATED_AT', { when: formatRelative(stored.created_at, locale()) })}</span>{/if}
          {#if stored.stats}
            <span>·</span>
            <span>{t('COMMON.HITS', { n: stored.stats.total })}{#if stored.stats.last_hit}, {t('EDITOR.LAST_HIT', { when: formatRelative(stored.stats.last_hit, locale()) })}{/if}</span>
          {/if}
          <span class="spacer"></span>
          <a href="#/tester?url={encodeURIComponent(sample || form.source)}" class="row" style="gap:0.25rem" onclick={() => closeEditor()}><FlaskConical size={12} />{t('RULES.ACT_TEST')}</a>
        </div>
      {/if}

      {#if readOnly}
        <div class="banner" role="note"><div class="b-body">{t('PERM.READ_ONLY')}</div></div>
      {/if}

      <fieldset class="ro" disabled={readOnly}>
      <section class="stack" style="gap:1rem" aria-label={t('EDITOR.SECTION_RULE')}>
        <Field label={t('EDITOR.SOURCE')} hint={sourceHint} error={fieldError.source ?? null} id="rm-source">
          {#snippet labelExtra()}
            <span class="spacer"></span>
            <Segmented size="sm" label={t('EDITOR.MATCH_TYPE')} items={matchItems} bind:value={form.match_type} />
          {/snippet}
          {#snippet children({ id, describedBy })}
            <input
              {id}
              data-autofocus
              class="input mono"
              bind:value={form.source}
              placeholder={form.match_type === 'regex' ? '^/blog/(\\d{4})/(.*)$' : form.match_type === 'wildcard' ? '/blog/*' : '/old-page'}
              aria-invalid={invalidFields.has('source') || undefined}
              aria-describedby={describedBy}
              spellcheck="false"
              autocomplete="off"
              autocapitalize="off"
            />
          {/snippet}
        </Field>

        {#if matchSuggestion && !readOnly}
          <div class="banner" role="status" data-testid="match-hint">
            <Lightbulb size={16} aria-hidden="true" />
            <div class="b-body">{t(`EDITOR.HINT_${form.match_type.toUpperCase()}_TO_${matchSuggestion.toUpperCase()}`)}</div>
            <Button onclick={() => (form.match_type = matchSuggestion)}>{t(`EDITOR.HINT_SWITCH_${matchSuggestion.toUpperCase()}`)}</Button>
          </div>
        {/if}

        <PreviewPanel
          bind:sample
          matchType={form.match_type as MatchType}
          hasSource={!!form.source.trim()}
          loading={pv.loading}
          result={pv.result}
          answered={pv.answered}
          unavailable={pv.unavailable}
          ontouch={() => (sampleTouched = true)}
        />

        <IssueList {issues} onshorten={(target) => { form.target = target; form.target_type = 'route'; }} onopen={openOther} onfixcase={fixCase} />

        {#if needTarget}
          <TargetField bind:form invalid={invalidFields.has('target')} error={fieldError.target ?? null} {candidates} />
        {:else}
          <div class="banner"><div class="b-body">{t('EDITOR.NO_TARGET_NEEDED', { code: form.status })}</div></div>
        {/if}

        <div class="field-grid">
          <Field label={t('EDITOR.STATUS')} hint={t(`STATUS_HELP.${form.status}`)} error={fieldError.status ?? null} id="rm-status">
            {#snippet labelExtra()}
              <InfoPopover label={t('EDITOR.STATUS_HELP_ALL')} width="24rem">
                <dl class="codes">
                  {#each STATUS_CODES as c (c)}
                    <dt><span class="badge {c === 410 || c === 451 ? 'bad' : c === 200 ? 'muted' : 'info'}">{c}</span> {t(`STATUS.${c}`)}</dt>
                    <dd class="muted">{t(`STATUS_HELP.${c}`)}</dd>
                  {/each}
                </dl>
              </InfoPopover>
            {/snippet}
            {#snippet children({ id, describedBy })}
              <Select
                {id}
                value={String(form.status)}
                options={statusOptions}
                aria-describedby={describedBy}
                onchange={(e: Event) => (form.status = Number((e.currentTarget as HTMLSelectElement).value) as never)}
              />
            {/snippet}
          </Field>
          <Field label={t('EDITOR.PRIORITY')} hint={t('EDITOR.PRIORITY_HELP')} id="rm-priority">
            {#snippet children({ id, describedBy })}
              <input {id} class="input num" type="number" step="1" bind:value={form.priority} aria-describedby={describedBy} />
            {/snippet}
          </Field>
        </div>

        {#if isNew && !readOnly && (form.status === 302 || form.status === 307)}
          <div class="row wrap text-xs status-note" data-testid="status-note">
            <span class="muted">{form.status === defaultStatus() ? t('EDITOR.STATUS_DEFAULT_NOTE', { code: form.status }) : t('EDITOR.STATUS_TEMP_NOTE', { code: form.status })}</span>
            <Button variant="outline" onclick={() => (form.status = 301)}>{t('EDITOR.USE_301')}</Button>
          </div>
        {/if}

        <div class="row enabled">
          <Switch bind:checked={form.enabled} label={t('EDITOR.ENABLED')} />
          <span class="grow">
            <span style="font-weight:500">{t('EDITOR.ENABLED')}</span>
            <span class="muted text-xs" style="display:block">{form.enabled ? t('EDITOR.ENABLED_ON') : t('EDITOR.ENABLED_OFF')}</span>
          </span>
        </div>
      </section>

      <div class="adv">
        <Disclosure title={t('EDITOR.SEC_QUERY')} summary={sectionSummary.query} bind:open={open.query}>
          <QueryEditor bind:form />
        </Disclosure>
        <Disclosure title={t('EDITOR.SEC_CONDITIONS')} summary={sectionSummary.conditions ? t('EDITOR.CONDITIONS_COUNT', { n: sectionSummary.conditions }) : ''} bind:open={open.conditions}>
          <ConditionsEditor bind:form />
        </Disclosure>
        <Disclosure title={t('EDITOR.SEC_BEHAVIOUR')} bind:open={open.behaviour}>
          <label class="check opt"><input type="checkbox" class="checkbox" bind:checked={form.only_if_not_found} /><span><span>{t('EDITOR.ONLY_NOT_FOUND')}</span><span class="muted text-xs" style="display:block">{t('EDITOR.ONLY_NOT_FOUND_HELP')}</span></span></label>
          <label class="check opt"><input type="checkbox" class="checkbox" bind:checked={form.continue} /><span><span>{t('EDITOR.CONTINUE')}</span><span class="muted text-xs" style="display:block">{t('EDITOR.CONTINUE_HELP')}</span></span></label>
          <label class="check opt"><input type="checkbox" class="checkbox" bind:checked={form.case_sensitive} /><span><span>{t('EDITOR.CASE_SENSITIVE')}</span><span class="muted text-xs" style="display:block">{t('EDITOR.CASE_SENSITIVE_HELP')}</span></span></label>
          <label class="check opt"><input type="checkbox" class="checkbox" bind:checked={form.ignore_trailing_slash} /><span><span>{t('EDITOR.TRAILING_SLASH')}</span><span class="muted text-xs" style="display:block">{t('EDITOR.TRAILING_SLASH_HELP')}</span></span></label>
        </Disclosure>
        <Disclosure title={t('EDITOR.SEC_SCHEDULE')} summary={sectionSummary.schedule} bind:open={open.schedule}>
          <div class="field-grid">
            <Field label={t('EDITOR.ACTIVE_FROM')} hint={t('EDITOR.ACTIVE_FROM_HELP')} optional>
              {#snippet children({ id, describedBy })}
                <input {id} class="input" type="datetime-local" value={isoToLocalInput(form.active_from)} aria-describedby={describedBy} onchange={(e) => (form.active_from = localInputToIso((e.currentTarget as HTMLInputElement).value))} />
              {/snippet}
            </Field>
            <Field label={t('EDITOR.EXPIRES_AT')} hint={t('EDITOR.EXPIRES_AT_HELP')} optional error={fieldError.expires_at ?? null} id="rm-expires">
              {#snippet children({ id, describedBy, invalid })}
                <input {id} class="input" type="datetime-local" value={isoToLocalInput(form.expires_at)} aria-invalid={invalid || undefined} aria-describedby={describedBy} onchange={(e) => (form.expires_at = localInputToIso((e.currentTarget as HTMLInputElement).value))} />
              {/snippet}
            </Field>
          </div>
        </Disclosure>
        <Disclosure title={t('EDITOR.SEC_ORGANISE')} summary={sectionSummary.organise} bind:open={open.organise}>
          <div class="field-grid">
            <Field label={t('EDITOR.GROUP')} hint={t('EDITOR.GROUP_HELP')} optional>
              {#snippet children({ id, describedBy })}
                <Combobox {id} bind:value={form.group} items={groupNames.map((g) => ({ value: g, label: g }))} allowCustom {describedBy} placeholder={t('EDITOR.GROUP_PLACEHOLDER')} />
              {/snippet}
            </Field>
            <Field label={t('EDITOR.TAGS')} hint={t('EDITOR.TAGS_HELP')} optional>
              {#snippet children({ id, describedBy })}
                <ChipsInput {id} bind:values={form.tags} label={t('EDITOR.TAGS')} suggestions={tagNames} {describedBy} />
              {/snippet}
            </Field>
          </div>
          <Field label={t('EDITOR.NOTE')} optional>
            {#snippet children({ id })}
              <textarea {id} class="textarea" rows="3" bind:value={form.note} placeholder={t('EDITOR.NOTE_PLACEHOLDER')}></textarea>
            {/snippet}
          </Field>
        </Disclosure>
      </div>
      </fieldset>
    {/if}
  </div>

  {#snippet footer()}
    {#if !isNew && !readOnly}
      <Button variant="danger" onclick={remove} disabled={saving || loading}><Trash2 size={14} />{t('COMMON.DELETE')}</Button>
    {/if}
    <span class="spacer"></span>
    {#if readOnly}
      <Button variant="outline" onclick={requestClose}>{t('COMMON.CLOSE')}</Button>
    {:else}
      <span class="muted text-2xs hide-sm"><kbd>Ctrl</kbd> <kbd>Enter</kbd></span>
      <Button variant="outline" onclick={requestClose} disabled={saving}>{t('COMMON.CANCEL')}</Button>
      <Button variant="primary" size="default" onclick={save} loading={saving} disabled={loading || !!loadError || blocked} title={blocked ? t('EDITOR.BLOCKED') : undefined}>
        {isNew ? t('EDITOR.CREATE') : t('EDITOR.SAVE')}
      </Button>
    {/if}
  {/snippet}
</Slideover>

<style>
  .ed {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    container-type: inline-size;
  }
  .meta {
    margin-block-start: -0.25rem;
  }
  .ro {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    margin: 0;
    padding: 0;
    border: 0;
    min-inline-size: 0;
  }
  .status-note {
    gap: 0.5rem;
    align-items: center;
    margin-block-start: -0.25rem;
  }
  .enabled {
    padding: 0.625rem 0.75rem;
    border: 1px solid var(--border);
    border-radius: var(--rm-r-md);
  }
  .adv {
    margin-block-start: 0.5rem;
  }
  .adv :global(.disc:last-child) {
    border-block-end: 1px solid var(--border);
  }
  .opt {
    align-items: flex-start;
  }
  .opt :global(.checkbox) {
    margin-block-start: 0.125rem;
  }
  .codes {
    display: grid;
    gap: 0.125rem;
    margin: 0;
  }
  .codes dt {
    font-weight: 600;
    margin-block-start: 0.5rem;
  }
  .codes dt:first-child {
    margin-block-start: 0;
  }
  .codes dd {
    margin: 0;
  }
  @media (max-width: 600px) {
    .hide-sm {
      display: none;
    }
  }
</style>
