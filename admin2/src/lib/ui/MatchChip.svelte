<script lang="ts">
  import type { MatchType } from '../types';
  import { t } from '../i18n.svelte';
  interface Props {
    type: MatchType;
  }
  let { type }: Props = $props();
</script>

<span class="chip" title={t(`MATCH_HELP.${type.toUpperCase()}`)}>{t(`MATCH.${type.toUpperCase()}`)}</span>
