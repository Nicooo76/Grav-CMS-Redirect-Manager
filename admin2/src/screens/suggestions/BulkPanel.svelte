<script lang="ts">
  import Button from '../../lib/ui/Button.svelte';
  import { formatScore, BULK_MAX, BULK_MIN, BULK_STEP } from '../../lib/suggestions';
  import { locale, t } from '../../lib/i18n.svelte';
  import { uniqueId } from '../../lib/dom';
  import { suggestions } from './store.svelte';

  const id = uniqueId('bulk');
  const fill = $derived(`${((suggestions.threshold - BULK_MIN) / (BULK_MAX - BULK_MIN)) * 100}%`);
  const count = $derived(suggestions.atThreshold);
  const score = $derived(formatScore(suggestions.threshold, locale()));
</script>

<section class="card panel" aria-labelledby="{id}-title">
  <div class="intro">
    <h2 id="{id}-title">{t('SUGGESTIONS.BULK_TITLE')}</h2>
    <p class="text-xs muted">{t('SUGGESTIONS.BULK_HELP')}</p>
  </div>
  <div class="slider">
    <label class="row text-xs" for="{id}-range">
      <span>{t('SUGGESTIONS.BULK_SLIDER')}</span>
      <output class="num val" for="{id}-range">{score}</output>
    </label>
    <input
      id="{id}-range"
      type="range"
      min={BULK_MIN}
      max={BULK_MAX}
      step={BULK_STEP}
      value={suggestions.threshold}
      style="--fill:{fill}"
      aria-valuetext={score}
      oninput={(e) => suggestions.setThreshold(Number((e.currentTarget as HTMLInputElement).value))}
    />
  </div>
  <div class="go">
    <p class="text-xs" aria-live="polite">{t('SUGGESTIONS.BULK_COUNT', { n: count, score })}</p>
    <Button variant="primary" size="default" disabled={count === 0} loading={suggestions.bulkBusy} onclick={(e: MouseEvent) => suggestions.openPreview(e.currentTarget as HTMLElement)}>
      {t('SUGGESTIONS.BULK_REVIEW')}
    </Button>
  </div>
</section>

<style>
  .panel {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 1rem 2rem;
    padding: 0.875rem 1rem;
  }
  .intro {
    flex: 1 1 12rem;
    min-inline-size: 0;
  }
  .intro h2 {
    font-size: var(--rm-text-sm);
    font-weight: 600;
  }
  .intro p {
    margin: 0.125rem 0 0;
  }
  .slider {
    flex: 1 1 14rem;
    max-inline-size: 20rem;
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
  }
  .val {
    margin-inline-start: auto;
    font-weight: 600;
    color: var(--foreground);
  }
  .go {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    flex-wrap: wrap;
    margin-inline-start: auto;
  }
  .go p {
    margin: 0;
  }
  input[type='range'] {
    appearance: none;
    -webkit-appearance: none;
    inline-size: 100%;
    block-size: 1.25rem;
    margin: 0;
    background: transparent;
    cursor: pointer;
  }
  input[type='range']::-webkit-slider-runnable-track {
    block-size: 0.375rem;
    border-radius: 999px;
    background: linear-gradient(to right, var(--primary) var(--fill), var(--muted) var(--fill));
  }
  input[type='range']::-moz-range-track {
    block-size: 0.375rem;
    border-radius: 999px;
    background: var(--muted);
  }
  input[type='range']::-moz-range-progress {
    block-size: 0.375rem;
    border-radius: 999px;
    background: var(--primary);
  }
  input[type='range']::-webkit-slider-thumb {
    -webkit-appearance: none;
    appearance: none;
    inline-size: 1rem;
    block-size: 1rem;
    margin-block-start: -0.3125rem;
    border-radius: 50%;
    border: 2px solid var(--primary);
    background: var(--background);
    box-shadow: var(--rm-shadow-sm);
  }
  input[type='range']::-moz-range-thumb {
    inline-size: 0.75rem;
    block-size: 0.75rem;
    border-radius: 50%;
    border: 2px solid var(--primary);
    background: var(--background);
    box-shadow: var(--rm-shadow-sm);
  }
  input[type='range']:focus-visible {
    outline: none;
  }
  input[type='range']:focus-visible::-webkit-slider-thumb {
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--ring) 50%, transparent);
  }
  input[type='range']:focus-visible::-moz-range-thumb {
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--ring) 50%, transparent);
  }
</style>
