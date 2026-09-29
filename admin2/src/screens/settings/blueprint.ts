/**
 * Svelte action that shows Admin 2's `<grav-blueprint-form>` (the plugin's real settings form).
 *
 * The form is styled by the host's Tailwind classes, which do not reach into a shadow root. So it
 * lives in the LIGHT DOM of our page element (a child with slot="rm-settings") and is projected
 * into our layout through a <slot> placed in `node`.
 */

export const BLUEPRINT_TAG = 'grav-blueprint-form';

export function blueprintAvailable(): boolean {
  return typeof customElements !== 'undefined' && !!customElements.get(BLUEPRINT_TAG);
}

export interface BlueprintOptions {
  plugin: string;
  /** tab to open, with or without the `_tab` suffix; '' leaves the form's own choice */
  tab?: string;
}

export function blueprintForm(node: HTMLElement, opts: BlueprintOptions) {
  const root = node.getRootNode();
  const pageEl = root instanceof ShadowRoot ? (root.host as HTMLElement) : node;
  const slot = document.createElement('slot');
  slot.name = 'rm-settings';
  node.appendChild(slot);
  const el = document.createElement(BLUEPRINT_TAG);
  el.setAttribute('plugin', opts.plugin);
  el.setAttribute('slot', 'rm-settings');
  if (opts.tab) el.setAttribute('tab', opts.tab);
  pageEl.appendChild(el);
  return {
    update(next: BlueprintOptions) {
      if (next.plugin !== el.getAttribute('plugin')) el.setAttribute('plugin', next.plugin);
      if (next.tab) el.setAttribute('tab', next.tab);
      else el.removeAttribute('tab');
    },
    destroy() {
      el.remove();
      slot.remove();
    },
  };
}

/** Admin URL of the plugin's own configuration page in the host, or null when the host did not tell us its base. */
export function hostConfigUrl(slug: string): string | null {
  const base = typeof window !== 'undefined' ? window.__GRAV_ADMIN_BASE : undefined;
  return base ? `${base.replace(/\/$/, '')}/plugins/${slug}` : null;
}
