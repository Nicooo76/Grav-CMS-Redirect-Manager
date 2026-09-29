/**
 * Texts for the finding codes the server sends (import issues and rule validation). The dictionary lives in
 * the plugin's languages.yaml under PLUGIN_REDIRECT_MANAGER.IMPORT.ISSUE.<CODE> and .VALIDATION.<CODE>; the
 * English server message is the fallback for a code without an entry (or a host without the dictionary).
 */
import { tHost } from './i18n.svelte';
import type { Params } from './i18n';

export interface CodedIssue {
  code: string;
  message: string;
  params?: Record<string, unknown>;
}

const IMPORT_PREFIX = 'PLUGIN_REDIRECT_MANAGER.IMPORT.ISSUE.';
const VALIDATION_PREFIX = 'PLUGIN_REDIRECT_MANAGER.VALIDATION.';

/** Server params reduced to what a message template can print: scalars as they are, lists joined with an arrow or comma. */
export function textParams(params: Record<string, unknown> | undefined): Params {
  const out: Params = {};
  for (const [k, v] of Object.entries(params ?? {})) {
    if (v === null || v === undefined) continue;
    // imports stringify lists ("/a, /b, /a"): a chain reads better with arrows
    if (typeof v === 'string') out[k] = k === 'chain' ? v.replace(/, (?=\/|https?:)/g, ' → ') : v;
    else if (typeof v === 'number' || typeof v === 'boolean') out[k] = v;
    else if (Array.isArray(v)) out[k] = v.filter((x) => typeof x === 'string' || typeof x === 'number').join(k === 'chain' ? ' → ' : ', ');
  }
  return out;
}

function translate(prefix: string, issue: CodedIssue): string {
  const text = issue.code ? tHost(prefix + issue.code.toUpperCase(), textParams(issue.params)) : undefined;
  return text ?? issue.message;
}

/** Message of a rule validation finding (editor checks, save errors). */
export function validationText(issue: CodedIssue): string {
  if (issue.code === 'chain') {
    const p = issue.params ?? {};
    // "Rule X redirects here and continues" is a different sentence from the chain of the rule itself
    const code = p.incoming ? 'CHAIN_INCOMING' : 'CHAIN';
    const head = tHost(VALIDATION_PREFIX + code, textParams(p));
    if (head === undefined) return issue.message;
    const tail = typeof p.shortcut === 'string' && p.shortcut ? tHost(VALIDATION_PREFIX + 'CHAIN_SHORTCUT', textParams(p)) : undefined;
    return tail ? `${head} ${tail}` : head;
  }
  return translate(VALIDATION_PREFIX, issue);
}

/** Import findings: the importer's own codes first, then rule validation codes (rows are validated like manual rules). */
export function importIssueText(issue: CodedIssue): string {
  const own = issue.code ? tHost(IMPORT_PREFIX + issue.code.toUpperCase(), textParams(issue.params)) : undefined;
  return own ?? validationText(issue);
}
