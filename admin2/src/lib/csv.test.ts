import { describe, expect, it } from 'vitest';
import { csvCell, detectDelimiter, parseCsv, rulesToCsv } from './csv';

describe('csv helpers', () => {
  it('escapes cells', () => {
    expect(csvCell('plain')).toBe('plain');
    expect(csvCell('a,b')).toBe('"a,b"');
    expect(csvCell('say "hi"')).toBe('"say ""hi"""');
    expect(csvCell(null)).toBe('');
  });
  it('parses quotes, escaped quotes, CRLF and embedded newlines', () => {
    expect(parseCsv('a,b\r\n"x,1","y ""q"""\r\n"multi\nline",z\n')).toEqual([['a', 'b'], ['x,1', 'y "q"'], ['multi\nline', 'z']]);
  });
  it('detects the delimiter', () => {
    expect(detectDelimiter('a;b;c')).toBe(';');
    expect(detectDelimiter('a\tb')).toBe('\t');
    expect(detectDelimiter('abc')).toBe(',');
    expect(parseCsv('a;b\n1;2')).toEqual([['a', 'b'], ['1', '2']]);
  });
  it('writes rules as CSV', () => {
    const csv = rulesToCsv([{ source: '/a', target: '/b, c', status: 301, match_type: 'exact', enabled: true, priority: 3, group: 'G', tags: ['x', 'y'], note: '' } as never]);
    expect(csv.split('\r\n')[0]).toBe('source,target,status,match_type,enabled,priority,group,tags,note');
    expect(csv.split('\r\n')[1]).toBe('/a,"/b, c",301,exact,true,3,G,x y,');
  });
});
