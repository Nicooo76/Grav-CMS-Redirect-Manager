<script lang="ts">
  import { X } from 'lucide-svelte';
  import { fallbackToasts, dismissToast } from '../state/notify.svelte';
  import { t } from '../i18n.svelte';
</script>

<div class="toast-region" role="region" aria-label={t('COMMON.NOTIFICATIONS')}>
  {#each fallbackToasts as tt (tt.id)}
    <div class="toast {tt.kind}" role={tt.kind === 'error' ? 'alert' : 'status'}>
      <span class="t-msg">{tt.message}</span>
      {#if tt.action}
        <button
          type="button"
          class="btn outline sm"
          onclick={() => {
            tt.action?.onClick();
            dismissToast(tt.id);
          }}>{tt.action.label}</button
        >
      {/if}
      <button type="button" class="btn ghost icon xs" aria-label={t('COMMON.DISMISS')} onclick={() => dismissToast(tt.id)}><X size={14} /></button>
    </div>
  {/each}
</div>
