<script lang="ts">
  import { untrack } from 'svelte';
  import { TriangleAlert, CircleAlert, Info, ArrowRight } from 'lucide-svelte';
  import Button from '../lib/ui/Button.svelte';
  import { api } from '../lib/api';
  import { t } from '../lib/i18n.svelte';
  import { validationText } from '../lib/issue-text';
  import type { Issue, Rule } from '../lib/types';

  interface Props {
    issues: Issue[];
    onshorten?: (target: string) => void;
    onopen?: (id: string) => void;
  }
  let { issues, onshorten, onopen }: Props = $props();

  const iconFor = (s: string) => (s === 'error' ? CircleAlert : s === 'warning' ? TriangleAlert : Info);
  const cls = (s: string) => (s === 'error' ? 'bad' : s === 'warning' ? 'warn' : '');
  /** Rules another finding points at: conflicts and duplicates list rule_ids, a shadowed rule names its winner. */
  const RELATED = new Set(['conflict', 'duplicate', 'shadowed']);
  function relatedIds(i: Issue): string[] {
    if (!RELATED.has(i.code)) return [];
    const p = i.params ?? {};
    const ids = Array.isArray(p.rule_ids) ? p.rule_ids : typeof p.rule_id === 'string' ? [p.rule_id] : [];
    return ids.filter((x): x is string => typeof x === 'string').slice(0, 3);
  }
  /** id -> source of the other rule, fetched once so the button can name it */
  const sources = $state<Record<string, string>>({});
  $effect(() => {
    const wanted = issues.flatMap(relatedIds);
    untrack(() => {
      for (const id of wanted) {
        if (id in sources) continue;
        sources[id] = '';
        api
          .get<Rule>(`/redirects/rules/${encodeURIComponent(id)}`)
          .then(({ data }) => (sources[id] = data.source))
          .catch(() => {});
      }
    });
  });
  const chainOf = (i: Issue): string[] => (Array.isArray(i.params?.chain) ? (i.params!.chain as string[]) : []);
</script>

{#if issues.length}
  <ul class="stack" style="gap:0.5rem;list-style:none;padding:0;margin:0" aria-label={t('EDITOR.CHECKS')}>
    {#each issues as issue, i (issue.code + i)}
      {@const Icon = iconFor(issue.severity)}
      <li class="banner {cls(issue.severity)}" role={issue.severity === 'error' ? 'alert' : undefined}>
        <Icon size={15} />
        <div class="b-body">
          <div>{validationText(issue)}</div>
          {#if issue.code === 'chain' && chainOf(issue).length > 1}
            <div class="chain mono text-2xs">
              {#each chainOf(issue) as hop, j (j)}
                {#if j > 0}<ArrowRight size={11} class="flip-rtl" />{/if}<span>{hop}</span>
              {/each}
            </div>
          {/if}
          {#if issue.code === 'chain' && typeof issue.params?.shortcut === 'string' && onshorten}
            <div style="margin-block-start:0.375rem">
              <Button size="sm" onclick={() => onshorten?.(issue.params!.shortcut as string)}>{t('EDITOR.SHORTEN', { target: issue.params!.shortcut as string })}</Button>
            </div>
          {/if}
          {#if onopen}
            {#each relatedIds(issue) as other (other)}
              <div style="margin-block-start:0.375rem">
                <Button size="sm" onclick={() => onopen?.(other)}>{t('EDITOR.OPEN_OTHER', { source: sources[other] || other })}</Button>
              </div>
            {/each}
          {/if}
        </div>
      </li>
    {/each}
  </ul>
{/if}

<style>
  .chain {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.25rem;
    margin-block-start: 0.25rem;
    color: var(--muted-foreground);
  }
  :global([dir='rtl']) .chain :global(.flip-rtl) {
    transform: scaleX(-1);
  }
</style>
