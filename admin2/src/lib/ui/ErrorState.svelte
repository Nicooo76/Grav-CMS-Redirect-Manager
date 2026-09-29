<script lang="ts">
  import { TriangleAlert } from 'lucide-svelte';
  import { t } from '../i18n.svelte';
  import { describeError } from '../errors';
  import { ApiError } from '../api';

  interface Props {
    error: unknown;
    title?: string;
    onretry?: () => void;
    compact?: boolean;
  }
  let { error, title, onretry, compact = false }: Props = $props();
  const status = $derived(error instanceof ApiError && error.status ? error.status : null);
</script>

<div class="empty" role="alert" style={compact ? 'padding:1.25rem' : ''}>
  <span class="ico" aria-hidden="true" style="color:var(--rm-warn-fg)"><TriangleAlert size={20} /></span>
  <h3>{title ?? t('COMMON.ERROR_LOAD')}</h3>
  <p>{describeError(error)}</p>
  {#if status}<p class="text-xs muted mono">HTTP {status}</p>{/if}
  {#if onretry}<button type="button" class="btn outline" style="margin-block-start:0.5rem" onclick={onretry}>{t('COMMON.RETRY')}</button>{/if}
</div>
