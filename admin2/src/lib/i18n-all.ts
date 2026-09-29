/**
 * Every English UI string, one module per area (src/i18n/en/*.ts). Tooling only: `npm run i18n`, the tests and
 * the dev harness read this. The bundle itself ships just the core set of i18n-fallback.ts.
 */
import common from '../i18n/en/common';
import rules from '../i18n/en/rules';
import editor from '../i18n/en/editor';
import notfound from '../i18n/en/notfound';
import suggestions from '../i18n/en/suggestions';
import tester from '../i18n/en/tester';
import importexport from '../i18n/en/importexport';
import settings from '../i18n/en/settings';
import auto from '../i18n/en/auto';
import widget from '../i18n/en/widget';

export const allEn: Record<string, string> = {
  ...common,
  ...rules,
  ...editor,
  ...notfound,
  ...suggestions,
  ...tester,
  ...importexport,
  ...settings,
  ...widget,
  ...auto,
};
