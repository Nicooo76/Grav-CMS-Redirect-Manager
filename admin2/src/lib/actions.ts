/** Svelte actions. */
import { placeBelow } from './dom';

/** Calls `fn` when a pointer goes down or focus lands outside `node` (and outside `ignore`). */
export function clickOutside(node: HTMLElement, opts: { fn: () => void; ignore?: () => (HTMLElement | null | undefined)[] }) {
  let current = opts;
  const onDown = (e: Event) => {
    const path = e.composedPath();
    if (path.includes(node)) return;
    const ignore = current.ignore?.() ?? [];
    if (ignore.some((el) => el && path.includes(el))) return;
    current.fn();
  };
  // listen on the document so clicks in the host page count too
  document.addEventListener('pointerdown', onDown, true);
  return {
    update(next: typeof opts) {
      current = next;
    },
    destroy() {
      document.removeEventListener('pointerdown', onDown, true);
    },
  };
}

/** Positions a `position: fixed` popup below (or above) an anchor and keeps it there. */
export function anchored(node: HTMLElement, opts: { anchor: () => HTMLElement | null | undefined; match?: boolean; rtl?: boolean }) {
  let current = opts;
  const place = () => {
    const a = current.anchor();
    if (!a) return;
    const r = a.getBoundingClientRect();
    node.style.maxHeight = '';
    const p = placeBelow(r, node.offsetWidth, node.scrollHeight, { match: current.match, rtl: current.rtl });
    node.style.top = `${p.top}px`;
    node.style.left = `${p.left}px`;
    if (p.width) node.style.width = `${p.width}px`;
    node.style.maxHeight = `${p.maxHeight}px`;
  };
  place();
  const raf = requestAnimationFrame(place);
  window.addEventListener('resize', place);
  window.addEventListener('scroll', place, true);
  const ro = new ResizeObserver(place);
  ro.observe(node);
  return {
    update(next: typeof opts) {
      current = next;
      place();
    },
    destroy() {
      cancelAnimationFrame(raf);
      window.removeEventListener('resize', place);
      window.removeEventListener('scroll', place, true);
      ro.disconnect();
    },
  };
}

/** Lightweight tooltip: shown on hover and keyboard focus, hidden on Esc/blur. */
export function tooltip(node: HTMLElement, text: string | null | undefined) {
  let value = text;
  let tip: HTMLElement | null = null;
  let timer: ReturnType<typeof setTimeout> | undefined;
  const show = () => {
    if (!value || tip) return;
    tip = document.createElement('div');
    tip.className = 'tooltip';
    tip.setAttribute('role', 'tooltip');
    tip.textContent = value;
    const rn = node.getRootNode();
    (rn instanceof ShadowRoot ? rn : document.body).appendChild(tip);
    const r = node.getBoundingClientRect();
    const tw = tip.offsetWidth;
    const th = tip.offsetHeight;
    let left = r.left + r.width / 2 - tw / 2;
    left = Math.max(8, Math.min(left, window.innerWidth - tw - 8));
    let top = r.top - th - 6;
    if (top < 8) top = r.bottom + 6;
    tip.style.left = `${left}px`;
    tip.style.top = `${top}px`;
  };
  const enter = () => {
    clearTimeout(timer);
    timer = setTimeout(show, 350);
  };
  const hide = () => {
    clearTimeout(timer);
    tip?.remove();
    tip = null;
  };
  const key = (e: KeyboardEvent) => {
    if (e.key === 'Escape') hide();
  };
  node.addEventListener('mouseenter', enter);
  node.addEventListener('mouseleave', hide);
  node.addEventListener('focus', show);
  node.addEventListener('blur', hide);
  node.addEventListener('keydown', key);
  node.addEventListener('click', hide);
  return {
    update(next: string | null | undefined) {
      value = next;
      if (tip && value) tip.textContent = value;
      if (!value) hide();
    },
    destroy() {
      hide();
      node.removeEventListener('mouseenter', enter);
      node.removeEventListener('mouseleave', hide);
      node.removeEventListener('focus', show);
      node.removeEventListener('blur', hide);
      node.removeEventListener('keydown', key);
      node.removeEventListener('click', hide);
    },
  };
}

/** Focus the node on mount. */
export function autofocus(node: HTMLElement, opts: { select?: boolean } | boolean = true) {
  if (opts === false) return;
  queueMicrotask(() => {
    node.focus({ preventScroll: true });
    if (typeof opts === 'object' && opts.select && node instanceof HTMLInputElement) node.select();
  });
}
