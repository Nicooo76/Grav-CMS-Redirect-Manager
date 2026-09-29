/** Small DOM helpers that respect shadow roots. */

export const FOCUSABLE =
  'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"]), summary';

/** Element that has focus, looking through shadow roots. */
export function deepActive(root: Document | ShadowRoot = document): HTMLElement | null {
  let el = root.activeElement as HTMLElement | null;
  while (el && el.shadowRoot && el.shadowRoot.activeElement) el = el.shadowRoot.activeElement as HTMLElement;
  return el;
}

export function focusables(container: HTMLElement): HTMLElement[] {
  return Array.from(container.querySelectorAll<HTMLElement>(FOCUSABLE)).filter(
    (el) => !el.hasAttribute('inert') && el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden',
  );
}

/** True while the user is typing in a text field (shortcuts must not fire). */
export function isTypingTarget(target: EventTarget | null): boolean {
  // events reaching `document` are retargeted to the shadow host; ask for the real focus owner
  let el = (deepActive() ?? target) as HTMLElement | null;
  if (!el || !el.tagName) return false;
  const tag = el.tagName;
  if (tag === 'TEXTAREA' || tag === 'SELECT') return true;
  if (tag === 'INPUT') {
    const type = (el as HTMLInputElement).type;
    return !['checkbox', 'radio', 'button', 'submit', 'reset', 'file', 'range', 'color'].includes(type);
  }
  return el.isContentEditable;
}

export interface Placement {
  top: number;
  left: number;
  width?: number;
  maxHeight: number;
  flipped: boolean;
}

/** Fixed-position coordinates for a popup under (or above) an anchor. */
export function placeBelow(anchor: DOMRect, popW: number, popH: number, opts: { match?: boolean; gap?: number; rtl?: boolean } = {}): Placement {
  const gap = opts.gap ?? 4;
  const vw = window.innerWidth;
  const vh = window.innerHeight;
  const width = opts.match ? anchor.width : popW;
  let left = opts.rtl ? anchor.right - width : anchor.left;
  left = Math.max(8, Math.min(left, vw - width - 8));
  const spaceBelow = vh - anchor.bottom - gap - 8;
  const spaceAbove = anchor.top - gap - 8;
  const flipped = popH > spaceBelow && spaceAbove > spaceBelow;
  const maxHeight = Math.max(120, flipped ? spaceAbove : spaceBelow);
  const h = Math.min(popH, maxHeight);
  const top = flipped ? anchor.top - gap - h : anchor.bottom + gap;
  return { top, left, width: opts.match ? width : undefined, maxHeight, flipped };
}

export function clamp(n: number, min: number, max: number): number {
  return Math.min(max, Math.max(min, n));
}

let uid = 0;
export function uniqueId(prefix = 'rm'): string {
  return `${prefix}-${++uid}`;
}
