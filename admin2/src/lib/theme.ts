/**
 * Colours come from host CSS variables and follow the theme by themselves.
 * This only mirrors `html.dark` for things variables cannot reach (native form
 * chrome via color-scheme) and for JS that needs to know (e.g. canvas).
 */
export function isDark(): boolean {
  return document.documentElement.classList.contains('dark') || document.body?.classList.contains('dark') === true;
}

export function watchTheme(el: HTMLElement, onchange?: (dark: boolean) => void): () => void {
  const apply = () => {
    const dark = isDark();
    // Unset (not 'light') when the class is absent so a standalone harness that
    // follows the OS preference keeps working.
    el.style.colorScheme = dark ? 'dark' : '';
    el.toggleAttribute('data-dark', dark);
    onchange?.(dark);
  };
  apply();
  const mo = new MutationObserver(apply);
  mo.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
  return () => mo.disconnect();
}
