<script lang="ts">
  import { ChevronLeft, ChevronRight } from 'lucide-svelte';
  import { t, locale, dir } from '../i18n.svelte';
  import { formatNumber } from '../format';
  import Select from './Select.svelte';

  interface Props {
    total: number;
    page: number;
    perPage: number;
    onpage: (p: number) => void;
    onperpage?: (n: number) => void;
    sizes?: number[];
    loading?: boolean;
  }
  let { total, page, perPage, onpage, onperpage, sizes = [25, 50, 100, 250, 500], loading = false }: Props = $props();

  const pages = $derived(Math.max(1, Math.ceil(total / Math.max(1, perPage))));
  const from = $derived(total === 0 ? 0 : (page - 1) * perPage + 1);
  const to = $derived(Math.min(total, page * perPage));
  const rtl = $derived(dir() === 'rtl');
  const sizeOptions = $derived(
    [...new Set([...sizes, perPage])].sort((a, b) => a - b).map((n) => ({ value: String(n), label: formatNumber(n, locale()) })),
  );
</script>

<nav class="pager row wrap" aria-label={t('COMMON.PAGINATION')}>
  <span class="text-xs muted num" aria-live="polite">
    {t('COMMON.RANGE_OF', { from: formatNumber(from, locale()), to: formatNumber(to, locale()), total: formatNumber(total, locale()) })}
  </span>
  <span class="spacer"></span>
  {#if onperpage}
    <label class="row text-xs muted" style="gap:0.375rem">
      <span>{t('COMMON.PER_PAGE')}</span>
      <Select
        value={String(perPage)}
        options={sizeOptions}
        class="pp"
        onchange={(e: Event) => onperpage?.(Number((e.currentTarget as HTMLSelectElement).value))}
        style="min-inline-size:4.5rem"
      />
    </label>
  {/if}
  <span class="text-xs muted num">{t('COMMON.PAGE_OF', { page: formatNumber(page, locale()), pages: formatNumber(pages, locale()) })}</span>
  <button type="button" class="btn outline icon" aria-label={t('COMMON.PREVIOUS')} disabled={page <= 1 || loading} onclick={() => onpage(page - 1)}>
    {#if rtl}<ChevronRight size={16} />{:else}<ChevronLeft size={16} />{/if}
  </button>
  <button type="button" class="btn outline icon" aria-label={t('COMMON.NEXT')} disabled={page >= pages || loading} onclick={() => onpage(page + 1)}>
    {#if rtl}<ChevronLeft size={16} />{:else}<ChevronRight size={16} />{/if}
  </button>
</nav>

<style>
  .pager {
    padding: 0.625rem 0.75rem;
    border-block-start: 1px solid var(--border);
  }
  .pager :global(.pp) {
    inline-size: auto;
  }
</style>
