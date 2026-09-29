// English strings for this area. Keys are relative to PLUGIN_REDIRECT_MANAGER.UI.
const en: Record<string, string> = {
  'WIDGET.TITLE': 'Redirects',
  'WIDGET.SUMMARY': '{total, plural, one {# rule} other {# rules}}, {active} active',
  'WIDGET.NOT_FOUND_LABEL': '404s, last 7 days',
  'WIDGET.NOT_FOUND_ARIA': '{n, plural, one {# not found error} other {# not found errors}} in the last 7 days',
  'WIDGET.HITS_LABEL': 'Redirect hits, last 7 days',
  'WIDGET.HITS_ARIA': '{n, plural, one {# redirect hit} other {# redirect hits}} in the last 7 days',
  'WIDGET.SUGGESTIONS_LABEL': 'Open suggestions',
  'WIDGET.SUGGESTIONS_ARIA': '{n, plural, =0 {No open suggestions} one {# open suggestion} other {# open suggestions}}',
  'WIDGET.DEAD_LABEL': 'Dead targets',
  'WIDGET.DEAD_ARIA': '{n, plural, =0 {No dead targets} one {# dead target} other {# dead targets}}',
  'WIDGET.TODAY': '{n, plural, =0 {None today} other {# today}}',
  'WIDGET.AVG': 'avg {n}/day',
  'WIDGET.EMPTY_TITLE': 'No redirects yet',
  'WIDGET.EMPTY_TEXT': 'Create a redirect and this card shows 404s, hits and open suggestions.',
  'WIDGET.EMPTY_CTA': 'Create the first redirect',
  'WIDGET.PENDING': '{n, plural, one {# deleted page waits for a decision} other {# deleted pages wait for a decision}}',
};
export default en;
