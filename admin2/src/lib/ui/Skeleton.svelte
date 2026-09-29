<script lang="ts">
  interface Props {
    w?: string;
    h?: string;
    class?: string;
    radius?: string;
  }
  let { w = '100%', h = '0.875rem', class: cls = '', radius }: Props = $props();
</script>

<span class="skeleton {cls}" style="inline-size:{w};block-size:{h};{radius ? `border-radius:${radius}` : ''}" aria-hidden="true"></span>
