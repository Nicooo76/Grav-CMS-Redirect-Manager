import { describe, expect, it } from 'vitest';
import { detectDelimiter, parseCsv } from './csv';

describe('csv helpers', () => {
  it('parses quotes, escaped quotes, CRLF and embedded newlines', () => {
    expect(parseCsv('a,b\r\n"x,1","y ""q"""\r\n"multi\nline",z\n')).toEqual([['a', 'b'], ['x,1', 'y "q"'], ['multi\nline', 'z']]);
  });
  it('detects the delimiter', () => {
    expect(detectDelimiter('a;b;c')).toBe(';');
    expect(detectDelimiter('a\tb')).toBe('\t');
    expect(detectDelimiter('abc')).toBe(',');
    expect(parseCsv('a;b\n1;2')).toEqual([['a', 'b'], ['1', '2']]);
  });
});
