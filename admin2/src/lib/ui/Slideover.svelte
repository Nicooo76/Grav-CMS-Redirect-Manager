<script lang="ts">
  import type { Snippet } from 'svelte';
  import { X } from 'lucide-svelte';
  import { deepActive, focusables, uniqueId } from '../dom';
  import { t } from '../i18n.svelte';

  interface Props {
    open: boolean;
    title: string;
    /** user asked to close (Esc, backdrop, X); the parent decides (e.g. unsaved-changes confirm) */
    onrequestclose: () => void;
    onclosed?: () => void;
    wide?: boolean;
    headerExtra?: Snippet;
    children: Snippet;
    footer?: Snippet;
    /** selector of the element (in the same shadow root) made inert while open */
    inertSelector?: string;
  }
  let { open, title, onrequestclose, onclosed, wide = false, headerExtra, children, footer, inertSelector = '[data-rm-main]' }: Props = $props();

  let mounted = $state(false);
  let closing = $state(false);
  let panel: HTMLElement | undefined = $state();
  /** Real focus owner when the panel opened (deep: `document.activeElement` is only the shadow host). */
  let opener: HTMLElement | null = null;
  /** The element made inert while the panel is open. */
  let background: HTMLElement | null = null;
  const titleId = uniqueId('so-title');
  let closeTimer: ReturnType<typeof setTimeout> | undefined;

  const reduced = () => typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches;

  $effect(() => {
    if (open) {
      clearTimeout(closeTimer);
      if (!mounted) {
        opener = deepActive();
        mounted = true;
        closing = false;
      }
    } else if (mounted && !closing) {
      closing = true;
      closeTimer = setTimeout(finish, reduced() ? 0 : 200);
    }
  });

  function finish() {
    mounted = false;
    closing = false;
    // The effect below clears `inert` only after this function, and focus() does nothing on an inert element:
    // release the page behind the panel first.
    if (background) background.inert = false;
    const back = opener;
    opener = null;
    if (back && back.isConnected) {
      back.focus({ preventScroll: true });
      // A host dialog (confirm, form) that was open a moment ago restores its own idea of the focus in the next
      // frame; take it back when that left the focus nowhere.
      requestAnimationFrame(() => {
        const now = deepActive();
        if (back.isConnected && now !== back && (!now || now === document.body || !!now.shadowRoot)) back.focus({ preventScroll: true });
      });
    }
    onclosed?.();
  }

  // inert background + initial focus
  $effect(() => {
    if (!mounted || !panel) return;
    const root = panel.getRootNode() as ShadowRoot | Document;
    const bg = inertSelector ? (root.querySelector(inertSelector) as HTMLElement | null) : null;
    background = bg;
    if (bg) bg.inert = true;
    const first = panel.querySelector<HTMLElement>('[data-autofocus]') ?? focusables(panel.querySelector('.so-body') as HTMLElement)[0] ?? panel;
    requestAnimationFrame(() => first.focus({ preventScroll: true }));
    return () => {
      if (bg) bg.inert = false;
      if (background === bg) background = null;
    };
  });

  function onkeydown(e: KeyboardEvent) {
    if (e.key === 'Escape' && !e.defaultPrevented) {
      e.stopPropagation();
      onrequestclose();
      return;
    }
    if (e.key !== 'Tab' || !panel) return;
    const els = focusables(panel);
    if (els.length === 0) {
      e.preventDefault();
      return;
    }
    const first = els[0];
    const last = els[els.length - 1];
    const active = deepActive(panel.getRootNode() as ShadowRoot);
    if (e.shiftKey && (active === first || !panel.contains(active))) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && active === last) {
      e.preventDefault();
      first.focus();
    }
  }
</script>

{#if mounted}
  <!-- svelte-ignore a11y_click_events_have_key_events, a11y_no_static_element_interactions -->
  <div class="so-backdrop {closing ? 'closing' : ''}" onclick={onrequestclose}></div>
  <div
    bind:this={panel}
    class="so-panel {wide ? 'wide' : ''} {closing ? 'closing' : ''}"
    role="dialog"
    aria-modal="true"
    aria-labelledby={titleId}
    tabindex="-1"
    {onkeydown}
  >
    <div class="so-head">
      <h2 id={titleId} class="grow truncate">{title}</h2>
      {#if headerExtra}{@render headerExtra()}{/if}
      <button type="button" class="btn ghost icon" aria-label={t('COMMON.CLOSE')} onclick={onrequestclose}><X size={16} /></button>
    </div>
    <div class="so-body">{@render children()}</div>
    {#if footer}<div class="so-foot">{@render footer()}</div>{/if}
  </div>
{/if}
