/** The dashboard widget only needs the shared strings and its own (keeps its bundle small). */
import common from '../i18n/en/common';
import widget from '../i18n/en/widget';

export const fallbackWidgetEn: Record<string, string> = { ...common, ...widget };
