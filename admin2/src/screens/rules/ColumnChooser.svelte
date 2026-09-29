<script lang="ts">
  import { Columns3 } from 'lucide-svelte';
  import { anchored, clickOutside } from '../../lib/actions';
  import { uniqueId } from '../../lib/dom';
  import { t, dir } from '../../lib/i18n.svelte';
  import { rules } from '../../lib/state/rules.svelte';
  import { COLUMN_ORDER } from '../../lib/columns';

  let open = $state(false);
  let btn: HTMLButtonElement | undefined = $state();
  const id = uniqueId('cols');
</script>

<button
  bind:this={btn}
  type="button"
  class="btn outline sm"
  aria-haspopup="dialog"
  aria-expanded={open}
  aria-controls={open ? id : undefined}
  onclick={() => (open = !open)}
>
  <Columns3 size={15} />
  {t('RULES.COLUMNS')}
</button>

{#if open}
  <div
    {id}
    class="popover rich"
    role="dialog"
    tabindex="-1"
    aria-label={t('RULES.COLUMNS_TITLE')}
    style="min-inline-size:13rem"
    use:anchored={{ anchor: () => btn, rtl: dir() === 'rtl' }}
    use:clickOutside={{ fn: () => (open = false), ignore: () => [btn] }}
    onkeydown={(e) => {
      if (e.key === 'Escape') {
        e.stopPropagation();
        open = false;
        btn?.focus();
      }
    }}
  >
    <div class="stack" style="gap:0.5rem">
      <div class="muted text-xs">{t('RULES.COLUMNS_HINT')}</div>
      {#each COLUMN_ORDER as col (col)}
        <label class="check">
          <input type="checkbox" class="checkbox" checked={rules.columns[col]} onchange={(e) => rules.setColumn(col, (e.currentTarget as HTMLInputElement).checked)} />
          {t(`RULES.COL_${col.toUpperCase()}`)}
        </label>
      {/each}
    </div>
  </div>
{/if}
