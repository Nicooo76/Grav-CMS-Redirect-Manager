<script lang="ts">
  import { Upload } from 'lucide-svelte';
  import { t } from '../../lib/i18n.svelte';

  interface Props {
    accept: string;
    /** short line under the title */
    hint: string;
    title: string;
    disabled?: boolean;
    /** one row instead of a tall box */
    compact?: boolean;
    onfile: (file: File) => void;
  }
  let { accept, hint, title, disabled = false, compact = false, onfile }: Props = $props();

  let over = $state(false);
  let depth = 0;

  function pick(e: Event) {
    const input = e.currentTarget as HTMLInputElement;
    const f = input.files?.[0];
    if (f) onfile(f);
    input.value = ''; // allows choosing the same file again
  }
  function onDrop(e: DragEvent) {
    e.preventDefault();
    depth = 0;
    over = false;
    if (disabled) return;
    const f = e.dataTransfer?.files?.[0];
    if (f) onfile(f);
  }
  function onOver(e: DragEvent) {
    if (!e.dataTransfer?.types?.includes('Files')) return;
    e.preventDefault();
    if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy';
  }
</script>

<label
  class="drop"
  class:over
  class:disabled
  class:compact
  ondragenter={(e) => {
    if (!e.dataTransfer?.types?.includes('Files')) return;
    depth++;
    over = true;
  }}
  ondragover={onOver}
  ondragleave={() => {
    depth = Math.max(0, depth - 1);
    if (depth === 0) over = false;
  }}
  ondrop={onDrop}
>
  <input class="sr-only" type="file" {accept} {disabled} onchange={pick} />
  <span class="ico" aria-hidden="true"><Upload size={20} /></span>
  <span class="text">
    <span class="title">{title}</span>
    <span class="hint">{hint}</span>
  </span>
  <span class="btn outline sm choose" aria-hidden="true">{t('IMPORTEXPORT.CHOOSE_FILE')}</span>
</label>

<style>
  .drop {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.375rem;
    padding: 2rem 1rem;
    border: 1px dashed var(--border);
    border-radius: var(--rm-r-lg);
    background: color-mix(in srgb, var(--muted) 30%, transparent);
    text-align: center;
    cursor: pointer;
  }
  .drop:hover {
    background: color-mix(in srgb, var(--muted) 50%, transparent);
  }
  .drop:focus-within {
    outline: 2px solid var(--ring);
    outline-offset: 2px;
  }
  .drop.over {
    border-color: var(--primary);
    border-style: solid;
    background: color-mix(in srgb, var(--primary) 8%, transparent);
  }
  .drop.disabled {
    opacity: 0.6;
    cursor: not-allowed;
  }
  .text {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    align-items: center;
  }
  .drop.compact {
    flex-direction: row;
    text-align: start;
    padding: 0.875rem 1rem;
    gap: 0.75rem;
  }
  .compact .text {
    align-items: flex-start;
    flex: 1 1 auto;
  }
  .compact .choose {
    margin-block-start: 0;
  }
  .ico {
    display: inline-grid;
    flex: none;
    place-items: center;
    inline-size: 2.5rem;
    block-size: 2.5rem;
    border-radius: var(--rm-r-lg);
    background: var(--muted);
    color: var(--muted-foreground);
  }
  .title {
    font-size: var(--rm-text-sm);
    font-weight: 600;
  }
  .hint {
    font-size: var(--rm-text-xs);
    color: var(--muted-foreground);
    max-inline-size: 34rem;
  }
  .choose {
    margin-block-start: 0.5rem;
    pointer-events: none;
  }
</style>
