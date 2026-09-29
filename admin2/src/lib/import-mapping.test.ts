import { describe, expect, it } from 'vitest';
import { assignRole, buildColumns, buildCsvOptions, columnCount, delimiterChar, guessHasHeader, guessMapping, missingFields } from './import-mapping';

describe('guessHasHeader', () => {
  it('recognises known titles', () => {
    expect(guessHasHeader([['source', 'target', 'status'], ['/a', '/b', '301']])).toBe(true);
    expect(guessHasHeader([['Old URL', 'New URL'], ['/a', '/b']])).toBe(true);
    expect(guessHasHeader([['Quelle', 'Ziel'], ['/a', '/b']])).toBe(true);
  });
  it('is false when the first row holds data', () => {
    expect(guessHasHeader([['/a', '/b', '301'], ['/c', '/d', '302']])).toBe(false);
    expect(guessHasHeader([['https://e.org/a', 'https://e.org/b']])).toBe(false);
  });
  it('unknown titles count as a header when the next row is data', () => {
    expect(guessHasHeader([['Spalte A', 'Spalte B'], ['/a', '/b']])).toBe(true);
    expect(guessHasHeader([['x', 'y'], ['p', 'q']])).toBe(false);
  });
  it('is false for no rows', () => {
    expect(guessHasHeader([])).toBe(false);
  });
});

describe('guessMapping', () => {
  it('maps by header names, English and German', () => {
    const roles = guessMapping([['source', 'target', 'status', 'match_type', 'group', 'notes'], ['/a', '/b', '301', 'exact', 'g', 'n']], true);
    expect(roles).toEqual(['source', 'target', 'status', 'match_type', 'group', 'note']);
    expect(guessMapping([['Quelle', 'Ziel', 'Gruppe', 'Notiz'], ['/a', '/b', 'x', 'y']], true)).toEqual(['source', 'target', 'group', 'note']);
  });
  it('gives each field to one column only', () => {
    const roles = guessMapping([['url', 'old', 'target'], ['/a', '/b', '/c']], true);
    expect(roles.filter((r) => r === 'source')).toHaveLength(1);
    expect(roles[2]).toBe('target');
  });
  it('maps by values when there is no header', () => {
    const rows = [['/a', '/b', '301'], ['/c', 'https://e.org/d', '302'], ['/e', '/f', '410']];
    expect(guessMapping(rows, false)).toEqual(['source', 'target', 'status']);
  });
  it('finds the status column in the middle', () => {
    const rows = [['/a', '301', '/b'], ['/c', '302', '/d']];
    expect(guessMapping(rows, false)).toEqual(['source', 'status', 'target']);
  });
  it('recognises match types by value', () => {
    const rows = [['/a', '/b', 'exact'], ['/c/*', '/d', 'wildcard']];
    expect(guessMapping(rows, false)).toEqual(['source', 'target', 'match_type']);
  });
  it('falls back to the first two columns when nothing is recognisable', () => {
    expect(guessMapping([['a', 'b', 'c'], ['d', 'e', 'f']], false)).toEqual(['source', 'target', 'ignore']);
  });
  it('a header row is not used for value guesses', () => {
    const roles = guessMapping([['from', 'to'], ['/a', '/b']], true);
    expect(roles).toEqual(['source', 'target']);
  });
  it('handles ragged rows and empty input', () => {
    expect(guessMapping([], false)).toEqual([]);
    expect(guessMapping([['/a'], ['/b', '/c']], false)).toHaveLength(2);
    expect(columnCount([['a'], ['b', 'c', 'd']])).toBe(3);
  });
  it('a single column becomes the source', () => {
    expect(guessMapping([['/a'], ['/b']], false)).toEqual(['source']);
  });
});

describe('assignRole', () => {
  it('moves a field from its previous column', () => {
    expect(assignRole(['source', 'target', 'ignore'], 2, 'source')).toEqual(['ignore', 'target', 'source']);
  });
  it('ignore never clears other columns', () => {
    expect(assignRole(['source', 'ignore', 'ignore'], 1, 'ignore')).toEqual(['source', 'ignore', 'ignore']);
  });
  it('does not mutate its input', () => {
    const r = ['source', 'target'] as const;
    assignRole(r, 1, 'source');
    expect(r).toEqual(['source', 'target']);
  });
});

describe('buildColumns / options', () => {
  it('lists field -> index and drops ignored columns', () => {
    expect(buildColumns(['target', 'ignore', 'source', 'status'])).toEqual({ target: 0, source: 2, status: 3 });
  });
  it('reports missing required fields', () => {
    expect(missingFields(['source', 'ignore'])).toEqual(['target']);
    expect(missingFields(['ignore'])).toEqual(['source', 'target']);
    expect(missingFields(['source', 'target'])).toEqual([]);
  });
  it('maps the delimiter choices', () => {
    expect(delimiterChar('auto')).toBeUndefined();
    expect(delimiterChar('comma')).toBe(',');
    expect(delimiterChar('semicolon')).toBe(';');
    expect(delimiterChar('tab')).toBe('\t');
  });
  it('builds the CSV options', () => {
    expect(buildCsvOptions(['source', 'target'], 'auto', true)).toEqual({ columns: { source: 0, target: 1 }, has_header: true });
    expect(buildCsvOptions(['source', 'target'], 'tab', false)).toEqual({ columns: { source: 0, target: 1 }, delimiter: '\t', has_header: false });
    expect(buildCsvOptions([], 'auto', false)).toEqual({ has_header: false });
  });
});
