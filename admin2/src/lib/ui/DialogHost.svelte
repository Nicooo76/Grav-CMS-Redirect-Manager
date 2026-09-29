<script lang="ts">
  import { dialogState } from '../state/notify.svelte';
  import { t } from '../i18n.svelte';
  import { focusables, deepActive } from '../dom';
  import Input from './Input.svelte';
  import Select from './Select.svelte';

  const req = $derived(dialogState.current);
  let box: HTMLElement | undefined = $state();
  let values = $state<Record<string, any>>({});
  let opener: HTMLElement | null = null;

  $effect(() => {
    if (req) {
      opener = deepActive();
      if (req.kind === 'form') {
        const v: Record<string, any> = {};
        for (const f of req.opts.fields) v[f.name] = f.value ?? (f.type === 'toggle' ? false : '');
        values = v;
      }
      requestAnimationFrame(() => {
        if (!box) return;
        (box.querySelector<HTMLElement>('input, select, textarea') ?? box.querySelector<HTMLElement>('button[data-primary]'))?.focus();
      });
    }
  });

  function done(ok: boolean) {
    const r = dialogState.current;
    if (!r) return;
    dialogState.current = null;
    if (r.kind === 'confirm') r.resolve(ok);
    else r.resolve(ok ? { ...values } : null);
    opener?.focus?.();
  }
  function onkeydown(e: KeyboardEvent) {
    if (e.key === 'Escape') {
      e.stopPropagation();
      done(false);
    } else if (e.key === 'Tab' && box) {
      const els = focusables(box);
      const first = els[0];
      const last = els[els.length - 1];
      const a = deepActive(box.getRootNode() as ShadowRoot);
      if (e.shiftKey && a === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && a === last) {
        e.preventDefault();
        first.focus();
      }
    }
  }
</script>

{#if req}
  <div class="dlg-backdrop">
    <div bind:this={box} class="dlg" role="alertdialog" aria-modal="true" aria-label={req.opts.title ?? ''} tabindex="-1" {onkeydown}>
      {#if req.opts.title}<h3>{req.opts.title}</h3>{/if}
      {#if req.kind === 'confirm'}
        <p>{req.opts.message}</p>
      {:else}
        {#if req.opts.description}<p>{req.opts.description}</p>{/if}
        <form
          class="stack"
          style="margin-block-start:1rem"
          onsubmit={(e) => {
            e.preventDefault();
            done(true);
          }}
        >
          {#each req.opts.fields as f (f.name)}
            <label class="field">
              <span class="lbl">{f.label ?? f.name}</span>
              {#if f.type === 'select'}
                <Select bind:value={values[f.name]} options={f.options ?? []} />
              {:else if f.type === 'toggle'}
                <input type="checkbox" class="checkbox" bind:checked={values[f.name]} />
              {:else if f.type === 'textarea'}
                <textarea class="textarea" bind:value={values[f.name]} placeholder={f.placeholder}></textarea>
              {:else}
                <Input bind:value={values[f.name]} type={f.type === 'number' ? 'number' : 'text'} placeholder={f.placeholder} required={f.required} />
              {/if}
              {#if f.help}<span class="hint">{f.help}</span>{/if}
            </label>
          {/each}
        </form>
      {/if}
      <div class="dlg-actions">
        <button type="button" class="btn outline" onclick={() => done(false)}>{req.opts.cancelLabel ?? t('COMMON.CANCEL')}</button>
        <button
          type="button"
          data-primary
          class="btn {req.kind === 'confirm' && req.opts.variant === 'destructive' ? 'destructive' : 'primary'}"
          onclick={() => done(true)}
        >
          {req.kind === 'confirm' ? (req.opts.confirmLabel ?? t('COMMON.CONFIRM')) : ((req.opts as any).submitLabel ?? t('COMMON.SAVE'))}
        </button>
      </div>
    </div>
  </div>
{/if}
