<script lang="ts">
  import { TriangleAlert, CircleAlert, Info, ArrowRight } from 'lucide-svelte';
  import Button from '../lib/ui/Button.svelte';
  import { t } from '../lib/i18n.svelte';
  import type { Issue } from '../lib/types';

  interface Props {
    issues: Issue[];
    onshorten?: (target: string) => void;
    onopen?: (id: string) => void;
  }
  let { issues, onshorten, onopen }: Props = $props();

  const iconFor = (s: string) => (s === 'error' ? CircleAlert : s === 'warning' ? TriangleAlert : Info);
  const cls = (s: string) => (s === 'error' ? 'bad' : s === 'warning' ? 'warn' : '');
  const chainOf = (i: Issue): string[] => (Array.isArray(i.params?.chain) ? (i.params!.chain as string[]) : []);
</script>

{#if issues.length}
  <ul class="stack" style="gap:0.5rem;list-style:none;padding:0;margin:0" aria-label={t('EDITOR.CHECKS')}>
    {#each issues as issue, i (issue.code + i)}
      {@const Icon = iconFor(issue.severity)}
      <li class="banner {cls(issue.severity)}" role={issue.severity === 'error' ? 'alert' : undefined}>
        <Icon size={15} />
        <div class="b-body">
          <div>{issue.message}</div>
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
          {#if issue.code === 'conflict' && typeof issue.params?.other_id === 'string' && onopen}
            <div style="margin-block-start:0.375rem">
              <Button size="sm" onclick={() => onopen?.(issue.params!.other_id as string)}>
                {t('EDITOR.OPEN_OTHER', { source: String(issue.params?.other_source ?? issue.params!.other_id) })}
              </Button>
            </div>
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
