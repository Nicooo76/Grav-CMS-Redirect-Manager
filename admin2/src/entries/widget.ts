import { mount, unmount } from 'svelte';
import Widget from '../widget/Widget.svelte';
import baseCss from '../styles/base.css?inline';
import { registerFallback, watchLocale } from '../lib/i18n.svelte';
import { fallbackWidgetEn } from '../lib/i18n-fallback-widget';
import { watchTheme } from '../lib/theme';

registerFallback(fallbackWidgetEn);

class RedirectManagerWidget extends HTMLElement {
  #app: ReturnType<typeof mount> | null = null;
  #cleanup: Array<() => void> = [];

  static get observedAttributes(): string[] {
    return ['size'];
  }

  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
  }

  connectedCallback(): void {
    if (this.#app) return;
    const root = this.shadowRoot!;
    const style = document.createElement('style');
    // the host gives the widget container a height; pass it down to the card
    style.textContent = `${baseCss}\n:host{block-size:100%}\n.rm-root{block-size:100%;container-type:inline-size}`;
    const target = document.createElement('div');
    target.className = 'rm-root';
    root.replaceChildren(style, target);
    this.#cleanup = [watchLocale(), watchTheme(this)];
    this.#app = mount(Widget, { target, props: { host: this } });
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

const tag = window.__GRAV_WIDGET_TAG ?? 'grav-redirect-manager--widget';
if (!customElements.get(tag)) customElements.define(tag, RedirectManagerWidget);
