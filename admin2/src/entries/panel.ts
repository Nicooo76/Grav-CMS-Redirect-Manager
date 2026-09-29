/**
 * Admin 2 context panel entry (page editor): a custom element that mounts the Svelte panel into its shadow root.
 * Admin 2 loads this file through a Blob import and sets `window.__GRAV_PANEL_TAG` first. It sets the attributes
 * `route`, `lang` and `type` on the element and keeps them current; the element sends `close` and `badge` events.
 */
import { mount, unmount } from 'svelte';
import Panel from '../panel/Panel.svelte';
import baseCss from '../styles/base.css?inline';
import panelCss from '../styles/panel.css?inline';
import { registerFallback, watchLocale } from '../lib/i18n.svelte';
import { fallbackPanelEn } from '../lib/i18n-fallback-panel';
import { watchTheme } from '../lib/theme';

registerFallback(fallbackPanelEn);

class RedirectManagerPanel extends HTMLElement {
  #app: ReturnType<typeof mount> | null = null;
  #cleanup: Array<() => void> = [];

  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
  }

  connectedCallback(): void {
    if (this.#app) return;
    const root = this.shadowRoot!;
    const style = document.createElement('style');
    style.textContent = baseCss + panelCss;
    const target = document.createElement('div');
    target.style.blockSize = '100%';
    root.replaceChildren(style, target);
    this.#cleanup = [watchLocale(), watchTheme(this)];
    this.#app = mount(Panel, { target, props: { host: this } });
  }

  disconnectedCallback(): void {
    if (this.#app) {
      unmount(this.#app);
      this.#app = null;
    }
    this.#cleanup.forEach((fn) => fn());
    this.#cleanup = [];
    this.shadowRoot?.replaceChildren();
  }
}

const tag = window.__GRAV_PANEL_TAG ?? 'grav-redirect-manager--panel';
if (!customElements.get(tag)) customElements.define(tag, RedirectManagerPanel);
