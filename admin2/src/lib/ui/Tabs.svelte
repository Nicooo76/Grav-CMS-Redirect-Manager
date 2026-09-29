<script lang="ts">
  interface Tab {
    id: string;
    label: string;
    href: string;
    badge?: number | null;
    badgeLabel?: string;
  }
  interface Props {
    tabs: Tab[];
    active: string;
    label: string;
    onselect?: (id: string) => void;
  }
  let { tabs, active, label, onselect }: Props = $props();
</script>

<nav class="tabs" aria-label={label}>
  {#each tabs as tab (tab.id)}
    <a
      class="tab"
      href={tab.href}
      aria-current={tab.id === active ? 'page' : undefined}
      onclick={(e) => {
        if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        e.preventDefault();
        e.stopPropagation();
        onselect?.(tab.id);
      }}
    >
      {tab.label}
      {#if tab.badge}<span class="badge count"><span aria-hidden="true">{tab.badge > 999 ? '999+' : tab.badge}</span><span class="sr-only">{tab.badgeLabel}</span></span>{/if}
    </a>
  {/each}
</nav>
