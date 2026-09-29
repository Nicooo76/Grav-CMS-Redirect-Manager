/** Column chooser state for the rules table, persisted per browser. */

export type ColumnId = 'status' | 'target' | 'code' | 'group' | 'hits' | 'last_hit' | 'origin' | 'priority';

export const COLUMN_ORDER: ColumnId[] = ['status', 'target', 'code', 'group', 'hits', 'last_hit', 'origin', 'priority'];
export const DEFAULT_COLUMNS: Record<ColumnId, boolean> = {
  status: true,
  target: true,
  code: true,
  group: true,
  hits: true,
  last_hit: true,
  origin: true,
  priority: false,
};

const KEY = 'rm.rules.columns.v1';

export function loadColumns(storage: Pick<Storage, 'getItem'> | null = safeStorage()): Record<ColumnId, boolean> {
  const out = { ...DEFAULT_COLUMNS };
  try {
    const raw = storage?.getItem(KEY);
    if (!raw) return out;
    const parsed = JSON.parse(raw);
    for (const id of COLUMN_ORDER) if (typeof parsed?.[id] === 'boolean') out[id] = parsed[id];
  } catch {
    /* corrupted or blocked: defaults */
  }
  return out;
}

export function saveColumns(cols: Record<ColumnId, boolean>, storage: Pick<Storage, 'setItem'> | null = safeStorage()): void {
  try {
    storage?.setItem(KEY, JSON.stringify(cols));
  } catch {
    /* private mode, quota: ignore */
  }
}

function safeStorage(): Storage | null {
  try {
    return typeof localStorage === 'undefined' ? null : localStorage;
  } catch {
    return null;
  }
}
