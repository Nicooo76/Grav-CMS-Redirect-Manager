/**
 * English fallback strings, used when the host dictionary lacks a key.
 * One module per area (src/i18n/en/*.ts); the German counterparts live in
 * src/i18n/de/*.ts and both are compiled into i18n/ui.yaml by `npm run i18n`.
 */
import common from '../i18n/en/common';
import rules from '../i18n/en/rules';
import editor from '../i18n/en/editor';
import notfound from '../i18n/en/notfound';
import suggestions from '../i18n/en/suggestions';
import tester from '../i18n/en/tester';
import importexport from '../i18n/en/importexport';
import settings from '../i18n/en/settings';
import widget from '../i18n/en/widget';

export const fallbackEn: Record<string, string> = {
  ...common,
  ...rules,
  ...editor,
  ...notfound,
  ...suggestions,
  ...tester,
  ...importexport,
  ...settings,
  ...widget,
};
