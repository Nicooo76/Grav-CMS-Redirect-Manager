<script lang="ts">
  import { Check, Copy } from 'lucide-svelte';
  import { t } from '../../lib/i18n.svelte';
  import { toast } from '../../lib/state/notify.svelte';
  import { copyText } from './clipboard';

  interface Props {
    text: string;
    label: string;
    /** show the label next to the icon */
    withText?: boolean;
  }
  let { text, label, withText = false }: Props = $props();
  let done = $state(false);
  let timer: ReturnType<typeof setTimeout> | undefined;

  async function copy() {
    const ok = await copyText(text);
    if (ok) {
      done = true;
      toast.success(t('COMMON.COPIED'));
      clearTimeout(timer);
      timer = setTimeout(() => (done = false), 1500);
    } else {
      toast.error(t('TESTER.COPY_FAILED'));
    }
  }
</script>

<button type="button" class="btn {withText ? 'ghost sm' : 'ghost icon xs'}" aria-label={withText ? undefined : label} onclick={copy}>
  {#if done}<Check size={14} />{:else}<Copy size={14} />{/if}
  {#if withText}{label}{/if}
</button>
