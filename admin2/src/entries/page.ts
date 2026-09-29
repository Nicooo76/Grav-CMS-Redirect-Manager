/**
 * Admin 2 page entry: a custom element that mounts the Svelte app into its
 * shadow root. Admin 2 loads this file through a Blob import and sets
 * `window.__GRAV_PAGE_TAG` first, so the tag name is never hardcoded.
 */
import { mount, unmount } from 'svelte';
import App from '../App.svelte';
import baseCss from '../styles/base.css?inline';
import pageCss from '../styles/page.css?inline';
import { startRouter } from '../lib/router.svelte';
import { registerFallback, watchLocale } from '../lib/i18n.svelte';
import { fallbackEn } from '../lib/i18n-fallback';
import { watchTheme } from '../lib/theme';

registerFallback(fallbackEn);

class RedirectManagerPage extends HTMLElement {
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
    style.textContent = baseCss + pageCss;
    const target = document.createElement('div');
    root.replaceChildren(style, target);
    this.#cleanup = [startRouter(), watchLocale(), watchTheme(this)];
    this.#app = mount(App, { target, props: { host: this } });
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

const tag = window.__GRAV_PAGE_TAG ?? 'grav-redirect-manager--page';
if (!customElements.get(tag)) customElements.define(tag, RedirectManagerPage);
