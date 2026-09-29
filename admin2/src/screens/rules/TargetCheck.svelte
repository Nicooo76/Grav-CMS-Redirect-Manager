<script lang="ts">
  /** Last live check of the redirect targets, and a button to run it now (GET/POST /redirects/checks). */
  import { onMount } from 'svelte';
  import { ShieldCheck } from 'lucide-svelte';
  import Button from '../../lib/ui/Button.svelte';
  import { api, ApiError } from '../../lib/api';
  import { describeError } from '../../lib/errors';
  import { formatRelative } from '../../lib/format';
  import { locale, t } from '../../lib/i18n.svelte';
  import { bump, can } from '../../lib/state/app.svelte';
  import { toast } from '../../lib/state/notify.svelte';
  import type { CheckRun } from '../../lib/types';

  let last = $state<string | null>(null);
  let dead = $state(0);
  let loaded = $state(false);
  let running = $state(false);

  const countDead = (r: CheckRun) => (r.results ?? []).filter((x) => !x.ok).length;

  onMount(() => {
    api
      .get<CheckRun>('/redirects/checks')
      .then(({ data }) => {
        last = data.last_run ?? null;
        dead = countDead(data);
      })
      .catch(() => {
        /* an extra line: without the checks route the rules list still works */
      })
      .finally(() => (loaded = true));
  });

  async function run() {
    if (running) return;
    running = true;
    try {
      const { data } = await api.post<CheckRun & { checked?: number; dead?: number }>('/redirects/checks/run');
      last = data.last_run ?? new Date().toISOString();
      dead = data.dead ?? countDead(data);
      toast.success(t('CHECK.DONE', { checked: data.checked ?? data.results?.length ?? 0, dead }));
      bump('rules');
    } catch (e) {
      if (e instanceof ApiError && e.status === 429) toast.info(t('CHECK.TOO_SOON', { n: e.retryAfter ?? 30 }));
      else toast.error(describeError(e));
    } finally {
      running = false;
    }
  }
</script>

{#if loaded && (last || can.manage)}
  <div class="row wrap text-xs muted check" role="status">
    <ShieldCheck size={13} aria-hidden="true" />
    <span>{last ? t('CHECK.LAST', { when: formatRelative(last, locale()), dead }) : t('CHECK.NEVER')}</span>
    {#if can.manage}
      <Button variant="ghost" loading={running} onclick={run}>{t('CHECK.RUN')}</Button>
    {/if}
  </div>
{/if}

<style>
  .check {
    gap: 0.375rem;
    align-items: center;
  }
</style>
