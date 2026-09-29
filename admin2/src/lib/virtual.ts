/** Windowing math for the fixed-row-height rules table. */

export interface VirtualInput {
  scrollTop: number;
  viewportHeight: number;
  rowHeight: number;
  count: number;
  overscan?: number;
}

export interface VirtualWindow {
  start: number;
  end: number; // exclusive
  padTop: number;
  padBottom: number;
  total: number;
}

export function computeWindow({ scrollTop, viewportHeight, rowHeight, count, overscan = 8 }: VirtualInput): VirtualWindow {
  const total = count * rowHeight;
  if (count <= 0 || rowHeight <= 0) return { start: 0, end: 0, padTop: 0, padBottom: 0, total: 0 };
  const top = Math.max(0, Math.min(scrollTop, Math.max(0, total - viewportHeight)));
  const first = Math.floor(top / rowHeight);
  const visible = Math.ceil(viewportHeight / rowHeight) + 1;
  const start = Math.max(0, first - overscan);
  const end = Math.min(count, first + visible + overscan);
  return { start, end, padTop: start * rowHeight, padBottom: (count - end) * rowHeight, total };
}

/** Row index under a pointer (y relative to the top of the scroll content). */
export function indexAt(y: number, rowHeight: number, count: number): number {
  return Math.max(0, Math.min(count - 1, Math.floor(y / rowHeight)));
}

/** Pages larger than this render through the window; smaller ones render fully. */
export const VIRTUAL_THRESHOLD = 100;
