<script lang="ts">
  import { onMount, untrack } from 'svelte';
  import { CircleCheck, FileText, TriangleAlert, X } from 'lucide-svelte';
  import Button from '../../lib/ui/Button.svelte';
  import Checkbox from '../../lib/ui/Checkbox.svelte';
  import Field from '../../lib/ui/Field.svelte';
  import Input from '../../lib/ui/Input.svelte';
  import Select from '../../lib/ui/Select.svelte';
  import Skeleton from '../../lib/ui/Skeleton.svelte';
  import CsvMapping from './CsvMapping.svelte';
  import DropZone from './DropZone.svelte';
  import PreviewPanel from './PreviewPanel.svelte';
  import SitemapDiff from './SitemapDiff.svelte';
  import { formats, formatLabel, labelForId, loadFormats } from './formats.svelte';
  import { ApiError, api, isAbort } from '../../lib/api';
  import { describeError } from '../../lib/errors';
  import { formatBytes } from '../../lib/format';
  import { t, locale } from '../../lib/i18n.svelte';
  import { ACCEPT, MAX_IMPORT_BYTES, detectFormat, isAcceptedFile, pickFormat, usesColumnMapping } from '../../lib/import-detect';
  import {
    assignRole,
    buildCsvOptions,
    columnCount,
    guessHasHeader,
    guessMapping,
    delimiterChar,
    missingFields,
    type ColumnRole,
    type DelimiterChoice,
  } from '../../lib/import-mapping';
  import { buildImportBody, importCount, type RowFilter } from '../../lib/import-preview';
  import { parseCsv } from '../../lib/csv';
  import { linkClick } from '../../lib/router.svelte';
  import { bump } from '../../lib/state/app.svelte';
  import { toast } from '../../lib/state/notify.svelte';
  import type { ImportCommitResult, ImportPreview } from '../../lib/types';

  const MAP_COLS = 12;

  /* ---------- input ---------- */
  let content = $state('');
  let fileName = $state('');
  let fileSize = $state(0);
  let lineCount = $state(0);
  let source = $state<'file' | 'paste' | null>(null);
  let contentVersion = $state(0);
  let loadError = $state<string | null>(null);
  let pasteText = $state('');

  /* ---------- format ---------- */
  let override = $state('');
  const clientDetected = $derived(content ? pickFormat(detectFormat(fileName, content), formats.list) : null);

  /* ---------- csv mapping ---------- */
  let sampleRows = $state<string[][]>([]);
  let hiddenColumns = $state(0);
  let hasHeader = $state(false);
  let delimiter = $state<DelimiterChoice>('auto');
  let roles = $state<ColumnRole[]>([]);
  let touched = $state(false);
  let mapKey = '';

  /* ---------- options ---------- */
  let defaultGroup = $state('');
  let defaultStatus = $state('');
  let skipDuplicates = $state(true);
  let skipInvalid = $state(true);

  /* ---------- preview / commit ---------- */
  let preview = $state<ImportPreview | null>(null);
  let previewing = $state(false);
  let previewError = $state<unknown>(null);
  let filter = $state<RowFilter>('all');
  let committing = $state(false);
  let commitError = $state<string | null>(null);
  let result = $state<ImportCommitResult | null>(null);
  let announcement = $state('');
  let ctl: AbortController | null = null;

  const detectedId = $derived(preview?.format ?? clientDetected);
  const effectiveFormat = $derived(override || detectedId || '');
  const csvActive = $derived(usesColumnMapping(effectiveFormat));
  const sendCsv = $derived(usesColumnMapping(override || clientDetected) || touched);
  const mappingMissing = $derived(sendCsv && roles.length > 0 && missingFields(roles).length > 0);
  const csvOptions = $derived(sendCsv && roles.length > 0 ? buildCsvOptions(roles, delimiter, hasHeader) : null);
  const csvKey = $derived(JSON.stringify(csvOptions));

  const importable = $derived(preview ? importCount(preview, skipDuplicates, skipInvalid) : 0);
  const blockedByErrors = $derived(!!preview && !skipInvalid && preview.counts.errors > 0);
  const commitReason = $derived(
    !preview || previewing
      ? ''
      : blockedByErrors
        ? t('IMPORTEXPORT.COMMIT_INVALID')
        : importable === 0
          ? t('IMPORTEXPORT.COMMIT_NONE')
          : '',
  );
  const canCommit = $derived(!!preview && !previewing && !committing && !commitReason && !mappingMissing);

  const formatOptions = $derived([
    {
      value: '',
      label: detectedId ? t('IMPORTEXPORT.FORMAT_AUTO_DETECTED', { format: labelForId(detectedId) }) : t('IMPORTEXPORT.FORMAT_AUTO'),
    },
    ...formats.list.filter((f) => f.import).map((f) => ({ value: f.id, label: formatLabel(f) })),
  ]);
  const statusOptions = $derived([
    { value: '', label: t('IMPORTEXPORT.OPT_STATUS_AUTO') },
    ...[301, 302, 307, 308].map((c) => ({ value: String(c), label: `${c} ${t(`STATUS.${c}`)}` })),
  ]);

  onMount(() => {
    void loadFormats();
    return () => ctl?.abort();
  });

  /* ---------- reading the file ---------- */

  function setContent(text: string, name: string, size: number, from: 'file' | 'paste') {
    ctl?.abort();
    content = text;
    fileName = name;
    fileSize = size;
    source = from;
    lineCount = text.split('\n').length;
    override = '';
    preview = null;
    previewError = null;
    result = null;
    commitError = null;
    filter = 'all';
    touched = false;
    roles = [];
    mapKey = '';
    contentVersion++;
  }

  async function onFile(file: File) {
    loadError = null;
    if (file.size > MAX_IMPORT_BYTES) {
      loadError = t('IMPORTEXPORT.FILE_TOO_BIG', { size: formatBytes(file.size, locale()), max: formatBytes(MAX_IMPORT_BYTES, locale()) });
      return;
    }
    if (!isAcceptedFile(file.name)) {
      loadError = t('IMPORTEXPORT.FILE_TYPE', { types: ACCEPT.replace(/,/g, ', ') });
      return;
    }
    try {
      const text = await file.text();
      if (!text.trim()) {
        loadError = t('IMPORTEXPORT.FILE_EMPTY');
        return;
      }
      setContent(text, file.name, file.size, 'file');
    } catch {
      loadError = t('IMPORTEXPORT.FILE_READ_FAILED');
    }
  }

  function usePasted() {
    loadError = null;
    if (!pasteText.trim()) {
      loadError = t('IMPORTEXPORT.FILE_EMPTY');
      return;
    }
    if (new Blob([pasteText]).size > MAX_IMPORT_BYTES) {
      loadError = t('IMPORTEXPORT.FILE_TOO_BIG', { size: formatBytes(new Blob([pasteText]).size, locale()), max: formatBytes(MAX_IMPORT_BYTES, locale()) });
      return;
    }
    setContent(pasteText, '', new Blob([pasteText]).size, 'paste');
  }

  function clearAll() {
    ctl?.abort();
    content = '';
    fileName = '';
    fileSize = 0;
    source = null;
    override = '';
    preview = null;
    previewing = false;
    previewError = null;
    result = null;
    commitError = null;
    roles = [];
    touched = false;
    mapKey = '';
    pasteText = '';
    loadError = null;
    contentVersion++;
  }

  /* ---------- csv mapping ---------- */

  function parseSample(): { rows: string[][]; hidden: number } {
    const rows = parseCsv(content.slice(0, 100_000), delimiterChar(delimiter)).slice(0, 6);
    const width = columnCount(rows);
    return { rows: rows.map((r) => r.slice(0, MAP_COLS)), hidden: Math.max(0, width - MAP_COLS) };
  }

  // (Re)build the sample and the guess when the file or the delimiter changes.
  $effect(() => {
    if (!csvActive || !content) return;
    const key = `${contentVersion}|${delimiter}`;
    if (key === mapKey) return;
    mapKey = key;
    untrack(() => {
      const s = parseSample();
      sampleRows = s.rows;
      hiddenColumns = s.hidden;
      hasHeader = guessHasHeader(s.rows);
      roles = guessMapping(s.rows, hasHeader);
      touched = false;
    });
  });

  function onRole(i: number, role: ColumnRole) {
    roles = assignRole(roles, i, role);
    touched = true;
  }
  function onHeader(v: boolean) {
    hasHeader = v;
    roles = guessMapping(sampleRows, v);
    touched = true;
  }

  /* ---------- preview ---------- */

  function body(): Record<string, unknown> {
    return buildImportBody({ content, filename: fileName, format: override, csv: csvOptions, defaultGroup, defaultStatus });
  }

  // Re-run the preview when anything that changes the result changes (debounced).
  $effect(() => {
    void contentVersion;
    void override;
    void csvKey;
    void defaultGroup;
    void defaultStatus;
    if (!content || result) return;
    if (mappingMissing) {
      untrack(() => {
        ctl?.abort();
        preview = null;
        previewing = false;
      });
      return;
    }
    // a CSV file whose mapping is not built yet waits for it
    if (untrack(() => csvActive && sendCsv && roles.length === 0)) return;
    const timer = setTimeout(() => void runPreview(), 300);
    return () => clearTimeout(timer);
  });

  async function runPreview(): Promise<void> {
    ctl?.abort();
    const mine = new AbortController();
    ctl = mine;
    previewing = true;
    previewError = null;
    try {
      const { data } = await api.post<ImportPreview>('/redirects/import/preview', body(), { signal: mine.signal });
      if (ctl !== mine) return;
      preview = data;
      announcement = t('IMPORTEXPORT.PREVIEW_READY', { total: data.counts.total, errors: data.counts.errors });
    } catch (e) {
      if (isAbort(e) || ctl !== mine) return;
      preview = null;
      previewError = e;
    } finally {
      if (ctl === mine) previewing = false;
    }
  }

  /* ---------- commit ---------- */

  async function commit(): Promise<void> {
    if (!canCommit || !preview) return;
    committing = true;
    commitError = null;
    const hadNotFound = preview.not_found_paths.length > 0 || preview.counts.not_found > 0;
    try {
      const { data } = await api.post<ImportCommitResult>('/redirects/import/commit', {
        ...body(),
        skip_duplicates: skipDuplicates,
        skip_invalid: skipInvalid,
      });
      result = data;
      toast.success(t('IMPORTEXPORT.DONE_TITLE', { n: data.created }));
      if (hadNotFound) bump('rules', 'notFound', 'suggestions');
      else bump('rules');
    } catch (e) {
      const msg = e instanceof ApiError && e.detail ? e.detail : describeError(e);
      commitError = msg;
      toast.error(msg);
    } finally {
      committing = false;
    }
  }

  function another() {
    clearAll();
  }
</script>

<div class="rm-import">
  {#if result}
    <div class="card done" role="status">
      <div class="card-b stack">
        <div class="row">
          <span class="ok-ico" aria-hidden="true"><CircleCheck size={20} /></span>
          <div>
            <h2>{result.created > 0 ? t('IMPORTEXPORT.DONE_TITLE', { n: result.created }) : t('IMPORTEXPORT.DONE_NONE')}</h2>
            {#if result.skipped > 0}<p class="muted">{t('IMPORTEXPORT.DONE_SKIPPED', { n: result.skipped })}</p>{/if}
          </div>
        </div>
        <div class="row wrap">
          <Button variant="primary" size="default" href="#/rules?origin=import" onclick={linkClick}>{t('IMPORTEXPORT.VIEW_RULES')}</Button>
          <Button size="default" onclick={another}>{t('IMPORTEXPORT.IMPORT_ANOTHER')}</Button>
        </div>
      </div>
    </div>
  {:else}
    <section class="card" aria-labelledby="rm-file-h">
      <div class="card-h">
        <h2 id="rm-file-h">{t('IMPORTEXPORT.FILE_TITLE')}</h2>
      </div>
      <div class="card-b stack">
        {#if loadError}
          <div class="banner bad" role="alert">
            <TriangleAlert size={16} />
            <div class="b-body">{loadError}</div>
          </div>
        {/if}

        {#if !content}
          <DropZone accept={ACCEPT} title={t('IMPORTEXPORT.DROP_TITLE')} hint={t('IMPORTEXPORT.DROP_HINT')} onfile={onFile} />
          <details class="paste">
            <summary>{t('IMPORTEXPORT.PASTE_SUMMARY')}</summary>
            <div class="stack paste-body">
              <Field label={t('IMPORTEXPORT.PASTE_LABEL')} hint={t('IMPORTEXPORT.PASTE_HINT')}>
                {#snippet children({ id, describedBy })}
                  <textarea {id} class="textarea mono" rows="6" spellcheck="false" bind:value={pasteText} aria-describedby={describedBy}></textarea>
                {/snippet}
              </Field>
              <div><Button onclick={usePasted}>{t('IMPORTEXPORT.PASTE_USE')}</Button></div>
            </div>
          </details>
        {:else}
          <div class="filebar">
            <span class="fico" aria-hidden="true"><FileText size={18} /></span>
            <div class="grow">
              <div class="fname">{source === 'paste' ? t('IMPORTEXPORT.PASTED') : fileName}</div>
              <div class="muted text-xs">
                {formatBytes(fileSize, locale())} · {t('IMPORTEXPORT.LINES', { n: lineCount })}
              </div>
            </div>
            <Button variant="ghost" onclick={clearAll}><X size={14} />{t('IMPORTEXPORT.FILE_REMOVE')}</Button>
          </div>

          <div class="fmt">
            <Field label={t('IMPORTEXPORT.FORMAT_LABEL')} hint={detectedId ? t('IMPORTEXPORT.FORMAT_DETECTED_HINT') : t('IMPORTEXPORT.FORMAT_UNKNOWN_HINT')}>
              {#snippet children({ id, describedBy })}
                <Select {id} bind:value={override} options={formatOptions} aria-describedby={describedBy} />
              {/snippet}
            </Field>
          </div>

          {#if csvActive && roles.length > 0}
            <CsvMapping
              rows={sampleRows}
              {roles}
              {hasHeader}
              {delimiter}
              {hiddenColumns}
              onrole={onRole}
              onheader={onHeader}
              ondelimiter={(v) => (delimiter = v)}
            />
          {/if}
        {/if}
      </div>
    </section>

    {#if content}
      <section class="card" aria-labelledby="rm-prev-h" aria-busy={previewing}>
        <div class="card-h">
          <h2 id="rm-prev-h">{t('IMPORTEXPORT.PREVIEW_TITLE')}</h2>
          <span class="spacer"></span>
          {#if commitReason}<span id="rm-commit-reason" class="muted text-xs reason">{commitReason}</span>{/if}
          <Button
            variant="primary"
            size="default"
            loading={committing}
            disabled={!canCommit}
            aria-describedby={commitReason ? 'rm-commit-reason' : undefined}
            onclick={commit}
          >
            {committing ? t('IMPORTEXPORT.COMMITTING') : t('IMPORTEXPORT.COMMIT', { n: importable })}
          </Button>
        </div>
        <div class="card-b stack">
          <div class="field-grid opts">
            <Field label={t('IMPORTEXPORT.OPT_GROUP')} hint={t('IMPORTEXPORT.OPT_GROUP_HINT')} optional>
              {#snippet children({ id, describedBy })}
                <Input {id} bind:value={defaultGroup} autocomplete="off" aria-describedby={describedBy} />
              {/snippet}
            </Field>
            <Field label={t('IMPORTEXPORT.OPT_STATUS')} hint={t('IMPORTEXPORT.OPT_STATUS_HINT')}>
              {#snippet children({ id, describedBy })}
                <Select {id} bind:value={defaultStatus} options={statusOptions} aria-describedby={describedBy} />
              {/snippet}
            </Field>
          </div>
          <div class="checks">
            <div>
              <Checkbox bind:checked={skipDuplicates} label={t('IMPORTEXPORT.OPT_SKIP_DUP')} />
              <p class="muted text-xs">{t('IMPORTEXPORT.OPT_SKIP_DUP_HINT')}</p>
            </div>
            <div>
              <Checkbox bind:checked={skipInvalid} label={t('IMPORTEXPORT.OPT_SKIP_INVALID')} />
              <p class="muted text-xs">{t('IMPORTEXPORT.OPT_SKIP_INVALID_HINT')}</p>
            </div>
          </div>

          {#if commitError}
            <div class="banner bad" role="alert">
              <TriangleAlert size={16} />
              <div class="b-body"><div class="b-title">{t('IMPORTEXPORT.COMMIT_FAILED')}</div>{commitError}</div>
            </div>
          {/if}

          {#if previewError}
            <div class="banner bad" role="alert">
              <TriangleAlert size={16} />
              <div class="b-body"><div class="b-title">{t('IMPORTEXPORT.PREVIEW_FAILED')}</div>{describeError(previewError)}</div>
              <Button onclick={() => runPreview()}>{t('COMMON.RETRY')}</Button>
            </div>
          {:else if mappingMissing}
            <p class="muted text-sm">{t('IMPORTEXPORT.MAP_MISSING')}</p>
          {:else if preview}
            <div class:stale={previewing}>
              <PreviewPanel {preview} bind:filter />
            </div>
          {:else}
            <div class="stack" aria-hidden="true">
              <Skeleton h="3.25rem" />
              <Skeleton h="8rem" />
            </div>
          {/if}
        </div>
      </section>
    {/if}
  {/if}

  <div class="sr-only" role="status" aria-live="polite">{announcement}</div>

  <SitemapDiff />
</div>

<style>
  .rm-import {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    max-inline-size: 64rem;
  }
  .card-h h2,
  .done h2 {
    font-size: var(--rm-text-sm);
    font-weight: 600;
    margin: 0;
  }
  .done h2 {
    font-size: var(--rm-text-base);
  }
  .done p {
    margin: 0.125rem 0 0;
    font-size: var(--rm-text-sm);
  }
  .ok-ico {
    display: inline-grid;
    place-items: center;
    inline-size: 2.25rem;
    block-size: 2.25rem;
    border-radius: 999px;
    color: var(--rm-ok-fg);
    background: color-mix(in srgb, var(--rm-ok-fg) 14%, transparent);
  }
  .paste > summary {
    cursor: pointer;
    font-size: var(--rm-text-xs);
    font-weight: 500;
    color: var(--muted-foreground);
    padding-block: 0.125rem;
  }
  .paste > summary:hover {
    color: var(--foreground);
  }
  .paste-body {
    padding-block-start: 0.75rem;
  }
  .filebar {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.625rem 0.75rem;
    border: 1px solid var(--border);
    border-radius: var(--rm-r-md);
    background: color-mix(in srgb, var(--muted) 30%, transparent);
  }
  .fico {
    display: inline-grid;
    place-items: center;
    color: var(--muted-foreground);
  }
  .fname {
    font-size: var(--rm-text-sm);
    font-weight: 500;
    overflow-wrap: anywhere;
  }
  .fmt {
    max-inline-size: 28rem;
  }
  .opts {
    grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
  }
  .checks {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem 2rem;
  }
  .checks p {
    margin: 0.125rem 0 0;
    padding-inline-start: 1.5rem;
    max-inline-size: 22rem;
  }
  .reason {
    text-align: end;
  }
  .stale {
    opacity: 0.6;
  }
</style>
