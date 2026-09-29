<script lang="ts">
  /** Combobox over the site's pages (GET /redirects/pages), used wherever a redirect target is picked. */
  import Combobox, { type ComboItem } from './Combobox.svelte';
  import { api, isAbort } from '../api';
  import { t } from '../i18n.svelte';
  import type { PageHit } from '../types';

  interface Props {
    value?: string;
    id?: string;
    invalid?: boolean;
    describedBy?: string;
    placeholder?: string;
    onkeydown?: (e: KeyboardEvent) => void;
  }
  let { value = $bindable(''), id, invalid = false, describedBy, placeholder, onkeydown }: Props = $props();

  let items = $state<ComboItem[]>([]);
  let loading = $state(false);
  let timer: ReturnType<typeof setTimeout> | undefined;
  let ctl: AbortController | null = null;

  export function search(q: string): void {
    clearTimeout(timer);
    timer = setTimeout(async () => {
      ctl?.abort();
      ctl = new AbortController();
      loading = true;
      try {
        const { data } = await api.get<PageHit[]>('/redirects/pages', { q, limit: 8 }, ctl.signal);
        items = (Array.isArray(data) ? data : []).map((p) => ({
          value: p.route,
          label: p.route,
          description: [p.title, (p.translations?.length ? p.translations : [p.language]).filter(Boolean).join(', ')].filter(Boolean).join(' · '),
          data: p,
        }));
      } catch (e) {
        if (!isAbort(e)) items = [];
      } finally {
        loading = false;
      }
    }, 200);
  }
</script>

<Combobox {id} bind:value {items} allowCustom mono {describedBy} {invalid} {loading} onsearch={search} {onkeydown} placeholder={placeholder ?? t('EDITOR.PAGE_SEARCH')} emptyText={t('EDITOR.PAGE_NONE')}>
  {#snippet option(it)}
    <span class="grow">
      <span class="mono">{it.label}</span>
      {#if it.description}<span class="muted text-xs" style="display:block">{it.description}</span>{/if}
    </span>
  {/snippet}
</Combobox>
