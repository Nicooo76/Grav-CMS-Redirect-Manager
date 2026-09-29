<script lang="ts">
  import { TriangleAlert } from 'lucide-svelte';
  import Checkbox from '../../lib/ui/Checkbox.svelte';
  import Field from '../../lib/ui/Field.svelte';
  import Select from '../../lib/ui/Select.svelte';
  import { t } from '../../lib/i18n.svelte';
  import { MAP_FIELDS, missingFields, type ColumnRole, type DelimiterChoice } from '../../lib/import-mapping';

  interface Props {
    /** first rows of the file, header row included when there is one */
    rows: string[][];
    roles: ColumnRole[];
    hasHeader: boolean;
    delimiter: DelimiterChoice;
    hiddenColumns: number;
    onrole: (index: number, role: ColumnRole) => void;
    onheader: (v: boolean) => void;
    ondelimiter: (v: DelimiterChoice) => void;
  }
  let { rows, roles, hasHeader, delimiter, hiddenColumns, onrole, onheader, ondelimiter }: Props = $props();

  const width = $derived(roles.length);
  const header = $derived(hasHeader ? (rows[0] ?? []) : []);
  const data = $derived((hasHeader ? rows.slice(1) : rows).slice(0, 5));
  const missing = $derived(missingFields(roles));

  const roleOptions = $derived([
    ...MAP_FIELDS.map((f) => ({ value: f as string, label: t(`IMPORTEXPORT.ROLE_${f.toUpperCase()}`) })),
    { value: 'ignore', label: t('IMPORTEXPORT.ROLE_IGNORE') },
  ]);
  const delimiterOptions = $derived([
    { value: 'auto', label: t('IMPORTEXPORT.DELIM_AUTO') },
    { value: 'comma', label: t('IMPORTEXPORT.DELIM_COMMA') },
    { value: 'semicolon', label: t('IMPORTEXPORT.DELIM_SEMICOLON') },
    { value: 'tab', label: t('IMPORTEXPORT.DELIM_TAB') },
  ]);
</script>

<div class="map stack">
  <div class="map-h">
    <div>
      <h3>{t('IMPORTEXPORT.MAP_TITLE')}</h3>
      <p class="muted text-xs">{t('IMPORTEXPORT.MAP_HINT')}</p>
    </div>
    <div class="ctrl">
      <Checkbox label={t('IMPORTEXPORT.MAP_HEADER')} checked={hasHeader} onchange={(e: Event) => onheader((e.currentTarget as HTMLInputElement).checked)} />
      <Field label={t('IMPORTEXPORT.MAP_DELIMITER')} class="delim">
        {#snippet children({ id })}
          <Select {id} value={delimiter} options={delimiterOptions} onchange={(e: Event) => ondelimiter((e.currentTarget as HTMLSelectElement).value as DelimiterChoice)} />
        {/snippet}
      </Field>
    </div>
  </div>

  <div class="table-wrap sample">
    <table class="table">
      <caption class="sr-only">{t('IMPORTEXPORT.MAP_SAMPLE')}</caption>
      <thead>
        <tr>
          {#each roles as role, i (i)}
            <th scope="col">
              <Select
                size="default"
                value={role}
                options={roleOptions}
                aria-label={t('IMPORTEXPORT.MAP_COLUMN_USE', { n: i + 1 })}
                onchange={(e: Event) => onrole(i, (e.currentTarget as HTMLSelectElement).value as ColumnRole)}
              />
              {#if hasHeader}<div class="col-title mono" title={header[i] ?? ''}>{header[i] ?? ''}</div>{/if}
            </th>
          {/each}
        </tr>
      </thead>
      <tbody>
        {#each data as row, r (r)}
          <tr>
            {#each { length: width } as _, i (i)}
              <td class="mono cell" class:ignored={roles[i] === 'ignore'} title={row[i] ?? ''}>{row[i] ?? ''}</td>
            {/each}
          </tr>
        {/each}
      </tbody>
    </table>
  </div>
  {#if hiddenColumns > 0}<p class="muted text-xs">{t('IMPORTEXPORT.MAP_MORE_COLUMNS', { n: hiddenColumns })}</p>{/if}

  {#if missing.length}
    <div class="banner warn" role="status">
      <TriangleAlert size={16} />
      <div class="b-body">{t('IMPORTEXPORT.MAP_MISSING')}</div>
    </div>
  {/if}
</div>

<style>
  .map-h {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: flex-end;
    gap: 0.75rem 1.5rem;
  }
  h3 {
    font-size: var(--rm-text-sm);
    font-weight: 600;
    margin: 0;
  }
  p {
    margin: 0.125rem 0 0;
  }
  .ctrl {
    display: flex;
    align-items: flex-end;
    gap: 1rem;
    flex-wrap: wrap;
  }
  .ctrl :global(.delim) {
    min-inline-size: 9rem;
  }
  .sample {
    border: 1px solid var(--border);
    border-radius: var(--rm-r-md);
  }
  .sample th {
    min-inline-size: 9rem;
    vertical-align: top;
    padding-block: 0.5rem;
  }
  .col-title {
    margin-block-start: 0.375rem;
    font-size: var(--rm-text-2xs);
    font-weight: 400;
    color: var(--muted-foreground);
    max-inline-size: 14rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .cell {
    font-size: var(--rm-text-xs);
    max-inline-size: 14rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .cell.ignored {
    color: var(--muted-foreground);
  }
</style>
