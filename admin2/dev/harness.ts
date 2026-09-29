/**
 * Dev harness: mimics the Admin 2 host so the plugin can be developed and
 * screenshotted without a Grav site. Sets the window globals the bundles read,
 * fakes toast + dialogs, provides theme/language switches and the mock API.
 *
 * URL flags: ?theme=dark|light  ?lang=en|de|ar  ?rows=300|10000  ?latency=0
 *            ?fail=0.2  ?readonly=1  ?nohost=1 (no toast/dialog globals)  ?i18n=off  ?chrome=0
 *            ?rotate=1 (rotate the API token every 5 s to prove it is re-read)
 */
import './host.css';
import YAML from 'yaml';
import languagesYaml from '../../languages.yaml?raw';
import { installMockApi } from './mock-api';

const BUNDLE_ROOT = import.meta.env.DEV ? (await import('./root')).REPO_ROOT : '';
const params = new URLSearchParams(location.search);
const w = window as any;
const isWidgetPage = document.body.dataset.harness === 'widget';

/* ---------- globals ---------- */
w.__GRAV_API_SERVER_URL = '';
w.__GRAV_API_PREFIX = '/api/v1';
w.__GRAV_API_TOKEN = 'dev-token-1';
w.__GRAV_ENVIRONMENT = 'default';
w.__GRAV_ADMIN_BASE = '/admin';
if (params.get('rotate') === '1') {
  let n = 1;
  setInterval(() => (w.__GRAV_API_TOKEN = `dev-token-${++n}`), 5000);
}
w.__GRAV_NAVIGATE = (url: string) => {
  console.info('[harness] __GRAV_NAVIGATE', url);
  fakeToast('info', `Navigate: ${url}`);
  const hash = url.split('#')[1];
  if (hash !== undefined && !isWidgetPage) location.hash = '#' + hash;
};

/* ---------- theme ---------- */
function setTheme(mode: 'light' | 'dark' | 'system') {
  const dark = mode === 'dark' || (mode === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
  document.documentElement.classList.toggle('dark', dark);
  try {
    localStorage.setItem('rm-dev-theme', mode);
  } catch {}
}
let savedTheme: string | null = null;
try {
  savedTheme = localStorage.getItem('rm-dev-theme');
} catch {}
setTheme((params.get('theme') as any) ?? (savedTheme as any) ?? 'light');

/* ---------- i18n bridge ---------- */
const subs = new Set<(l: string) => void>();
let lang = params.get('lang') ?? 'en';
/** The plugin's real language file, flattened the way Grav does it, so the harness sees what a host would serve. */
function flatten(node: unknown, prefix: string, out: Record<string, string> = {}): Record<string, string> {
  if (node && typeof node === 'object') for (const [k, v] of Object.entries(node)) flatten(v, `${prefix}.${k}`, out);
  else out[prefix] = String(node);
  return out;
}
const parsed = YAML.parse(languagesYaml) as Record<string, { PLUGIN_REDIRECT_MANAGER: unknown }>;
const dicts: Record<string, Record<string, string>> = {
  en: flatten(parsed.en.PLUGIN_REDIRECT_MANAGER, 'PLUGIN_REDIRECT_MANAGER'),
  de: flatten(parsed.de.PLUGIN_REDIRECT_MANAGER, 'PLUGIN_REDIRECT_MANAGER'),
};
const i18nOff = params.get('i18n') === 'off';
w.__GRAV_I18N = {
  get locale() {
    return lang;
  },
  get dir() {
    return lang === 'ar' || lang === 'he' ? 'rtl' : 'ltr';
  },
  has: (key: string) => !i18nOff && !!dicts[lang]?.[key],
  // like the real host: unknown keys are humanised, params are NOT applied to raw values
  t: (key: string) => dicts[lang]?.[key] || key.split('.').pop()!.replace(/_/g, ' ').toLowerCase(),
  subscribe(fn: (l: string) => void) {
    subs.add(fn);
    return () => subs.delete(fn);
  },
};
function setLang(l: string) {
  lang = l;
  document.documentElement.lang = l;
  document.documentElement.dir = w.__GRAV_I18N.dir;
  subs.forEach((fn) => fn(l));
}
document.documentElement.lang = lang;
document.documentElement.dir = w.__GRAV_I18N.dir;

/* ---------- fake toast (sonner look-alike, supports `action`) ---------- */
let toastRegion: HTMLElement;
function fakeToast(kind: string, message: string, opts: any = {}) {
  toastRegion ??= (() => {
    const el = document.createElement('div');
    el.className = 'fake-toasts';
    el.setAttribute('role', 'region');
    el.setAttribute('aria-label', 'Notifications');
    el.setAttribute('aria-live', 'polite');
    document.body.appendChild(el);
    return el;
  })();
  const t = document.createElement('div');
  t.className = `fake-toast ${kind}`;
  const msg = document.createElement('span');
  msg.textContent = message;
  t.appendChild(msg);
  const remove = () => t.remove();
  if (opts.action) {
    const b = document.createElement('button');
    b.className = 'act';
    b.textContent = opts.action.label;
    b.onclick = () => {
      opts.action.onClick?.();
      remove();
    };
    t.appendChild(b);
  }
  const x = document.createElement('button');
  x.className = 'x';
  x.setAttribute('aria-label', 'Close');
  x.textContent = '×';
  x.onclick = remove;
  t.appendChild(x);
  toastRegion.appendChild(t);
  setTimeout(remove, opts.duration ?? 4000);
}
if (params.get('nohost') !== '1') {
  w.__GRAV_TOAST = {
    success: (m: string, o?: any) => fakeToast('success', m, o),
    error: (m: string, o?: any) => fakeToast('error', m, o),
    info: (m: string, o?: any) => fakeToast('info', m, o),
    warning: (m: string, o?: any) => fakeToast('warning', m, o),
  };

  /* ---------- fake dialogs ---------- */
  const modal = (build: (close: (v: any) => void) => HTMLElement) =>
    new Promise<any>((resolve) => {
      const bg = document.createElement('div');
      bg.className = 'fake-dlg-bg';
      const prev = document.activeElement as HTMLElement | null;
      const close = (v: any) => {
        bg.remove();
        prev?.focus?.();
        resolve(v);
      };
      bg.appendChild(build(close));
      bg.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') close(null);
      });
      document.body.appendChild(bg);
      (bg.querySelector('input, select, textarea, button.primary') as HTMLElement | null)?.focus();
    });
  const btn = (label: string, cls: string, onclick: () => void) => {
    const b = document.createElement('button');
    b.className = `hbtn ${cls}`;
    b.textContent = label;
    b.onclick = onclick;
    return b;
  };
  w.__GRAV_DIALOGS = {
    confirm: (o: any) =>
      modal((close) => {
        const d = document.createElement('div');
        d.className = 'fake-dlg';
        d.setAttribute('role', 'alertdialog');
        d.innerHTML = `<h3></h3><p></p><div class="acts"></div>`;
        d.querySelector('h3')!.textContent = o.title ?? 'Are you sure?';
        d.querySelector('p')!.textContent = o.message;
        const acts = d.querySelector('.acts')!;
        acts.append(btn(o.cancelLabel ?? 'Cancel', '', () => close(false)), btn(o.confirmLabel ?? 'Confirm', 'primary', () => close(true)));
        return d;
      }).then((v) => v === true),
    form: (o: any) =>
      modal((close) => {
        const d = document.createElement('div');
        d.className = 'fake-dlg';
        d.setAttribute('role', 'dialog');
        const h = document.createElement('h3');
        h.textContent = o.title ?? '';
        d.appendChild(h);
        if (o.description) {
          const p = document.createElement('p');
          p.textContent = o.description;
          d.appendChild(p);
        }
        const inputs: Record<string, HTMLInputElement> = {};
        for (const f of o.fields) {
          const l = document.createElement('label');
          l.textContent = f.label ?? f.name;
          const i = document.createElement('input');
          i.type = f.type === 'number' ? 'number' : 'text';
          i.value = f.value ?? '';
          i.placeholder = f.placeholder ?? '';
          inputs[f.name] = i;
          l.appendChild(i);
          d.appendChild(l);
        }
        const acts = document.createElement('div');
        acts.className = 'acts';
        acts.append(
          btn(o.cancelLabel ?? 'Cancel', '', () => close(null)),
          btn(o.submitLabel ?? 'Save', 'primary', () => close(Object.fromEntries(Object.entries(inputs).map(([k, v]) => [k, v.value])))),
        );
        d.appendChild(acts);
        return d;
      }),
  };
}

/* ---------- fake <grav-blueprint-form> (?blueprint=1): light DOM, styled by page CSS like Admin 2's ---------- */
if (params.get('blueprint') === '1' && !customElements.get('grav-blueprint-form')) {
  customElements.define(
    'grav-blueprint-form',
    class extends HTMLElement {
      static observedAttributes = ['tab'];
      connectedCallback() {
        this.innerHTML = `<div style="border:1px solid var(--border);border-radius:0.5rem;padding:1rem"><strong>Blueprint form for ${this.getAttribute('plugin')}</strong><p style="color:var(--muted-foreground);font-size:0.875rem">Rendered in the light DOM and slotted into the plugin page.</p><label>Default status <input value="301" style="border:1px solid var(--input);border-radius:0.375rem;padding:0.25rem 0.5rem;background:transparent;color:inherit"></label></div>`;
      }
    },
  );
}

/* ---------- mock API ---------- */
const rows = Number(params.get('rows') ?? 300);
const latency = params.get('latency');
const mock = installMockApi({
  rules: rows,
  latency: latency !== null ? [Number(latency), Number(latency)] : undefined,
  failRate: Number(params.get('fail') ?? 0),
  readOnly: params.get('readonly') === '1',
});
w.__RM_MOCK = mock;

/* ---------- dev bar ---------- */
const bar = document.getElementById('devbar');
if (bar && params.get('chrome') !== '0') {
  bar.innerHTML = `
    <strong>Dev harness</strong>
    <label>Theme <select id="dv-theme"><option>light</option><option>dark</option><option>system</option></select></label>
    <label>Language <select id="dv-lang"><option>en</option><option>de</option><option value="ar">ar (RTL)</option></select></label>
    <label>Rules <select id="dv-rows"><option>300</option><option>2000</option><option>10000</option></select></label>
    <label>Fail rate <select id="dv-fail"><option value="0">0</option><option value="0.2">20%</option><option value="1">100%</option></select></label>
    <button id="dv-reset">Reset data</button>`;
  const q = (id: string) => document.getElementById(id) as HTMLSelectElement;
  q('dv-theme').value = document.documentElement.classList.contains('dark') ? 'dark' : 'light';
  q('dv-theme').onchange = () => setTheme(q('dv-theme').value as any);
  q('dv-lang').value = lang;
  q('dv-lang').onchange = () => setLang(q('dv-lang').value);
  q('dv-rows').value = String(rows);
  q('dv-rows').onchange = () => {
    params.set('rows', q('dv-rows').value);
    location.search = params.toString();
  };
  q('dv-fail').value = String(params.get('fail') ?? 0);
  q('dv-fail').onchange = () => {
    params.set('fail', q('dv-fail').value);
    location.search = params.toString();
  };
  (document.getElementById('dv-reset') as HTMLButtonElement).onclick = () => mock.reset();
} else if (bar) {
  bar.remove();
}

/* ---------- mount ---------- */
/** ?bundle=1 loads the committed, minified file the way Admin 2 does (fetch + Blob import). */
async function loadBundle(kind: 'pages' | 'widgets', tag: string, globalName: string) {
  const res = await fetch(`/@fs${BUNDLE_ROOT}/admin-next/${kind}/redirect-manager.js`);
  const code = await res.text();
  const blob = new Blob([`window.${globalName} = ${JSON.stringify(tag)};\n${code}`], { type: 'application/javascript' });
  await import(/* @vite-ignore */ URL.createObjectURL(blob));
  await customElements.whenDefined(tag);
}

if (isWidgetPage) {
  const tag = 'grav-widget-redirect-manager-overview';
  w.__GRAV_WIDGET_TAG = tag;
  if (params.get('bundle') === '1') await loadBundle('widgets', 'grav-widget-redirect-manager-overview', '__GRAV_WIDGET_TAG');
  else await import('../src/entries/widget');
  for (const size of ['sm', 'md', 'lg']) {
    const slot = document.querySelector(`[data-size="${size}"]`);
    if (!slot) continue;
    const el = document.createElement(tag);
    el.setAttribute('size', size);
    el.setAttribute('data-endpoint', '/redirects/stats');
    el.setAttribute('plugin', 'redirect-manager');
    el.setAttribute('widget-id', 'redirect-manager.overview');
    slot.appendChild(el);
  }
} else {
  w.__GRAV_PAGE_TAG = 'grav-redirect-manager--page';
  if (params.get('bundle') === '1') await loadBundle('pages', w.__GRAV_PAGE_TAG, '__GRAV_PAGE_TAG');
  else await import('../src/entries/page');
  const el = document.createElement(w.__GRAV_PAGE_TAG);
  el.addEventListener('page-state', (e: Event) => console.debug('[harness] page-state', (e as CustomEvent).detail));
  document.getElementById('mount')!.appendChild(el);
}
