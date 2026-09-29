/**
 * English fallback shipped in the page bundle: the strings around the shell (tabs, errors, badges, status names,
 * common buttons). Everything else comes from the host dictionary, which Admin 2 loads before it shows a plugin
 * page and which always carries the English texts under the active language (the API plugin merges them in).
 * Keeping all ~790 strings here cost 41 KB raw / 13 KB gzip for a case that only happens with a stale cache.
 * `has()` still decides, per key, whether the host or this set answers (src/lib/i18n.ts).
 * The complete set lives in i18n-all.ts.
 */
import common from '../i18n/en/common';

export const fallbackEn: Record<string, string> = { ...common };
