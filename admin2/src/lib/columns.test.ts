import { describe, expect, it } from 'vitest';
import { DEFAULT_COLUMNS, loadColumns, saveColumns } from './columns';

const store = (initial?: string) => {
  const data: Record<string, string> = initial ? { 'rm.rules.columns.v1': initial } : {};
  return { getItem: (k: string) => data[k] ?? null, setItem: (k: string, v: string) => void (data[k] = v), data };
};

describe('column chooser persistence', () => {
  it('starts with the defaults', () => {
    expect(loadColumns(store())).toEqual(DEFAULT_COLUMNS);
  });
  it('round-trips a choice', () => {
    const s = store();
    saveColumns({ ...DEFAULT_COLUMNS, origin: false, priority: true }, s);
    expect(loadColumns(s)).toMatchObject({ origin: false, priority: true, hits: true });
  });
  it('ignores corrupted or foreign data', () => {
    expect(loadColumns(store('{nope'))).toEqual(DEFAULT_COLUMNS);
    expect(loadColumns(store('{"origin":"yes","bogus":false}'))).toEqual(DEFAULT_COLUMNS);
  });
  it('never throws when storage is blocked', () => {
    const blocked = { getItem: () => { throw new Error('denied'); }, setItem: () => { throw new Error('denied'); } };
    expect(loadColumns(blocked)).toEqual(DEFAULT_COLUMNS);
    expect(() => saveColumns(DEFAULT_COLUMNS, blocked)).not.toThrow();
    expect(loadColumns(null)).toEqual(DEFAULT_COLUMNS);
  });
});
