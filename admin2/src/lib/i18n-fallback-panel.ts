/** The page-editor panel only needs the shared strings and its own (keeps its bundle small). */
import common from '../i18n/en/common';
import panel from '../i18n/en/panel';

export const fallbackPanelEn: Record<string, string> = { ...common, ...panel };
