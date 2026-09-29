// English strings for this area. Keys are relative to PLUGIN_REDIRECT_MANAGER.UI.
const en: Record<string, string> = {
  'SETTINGS.FORM_LABEL': 'Plugin settings',
  'SETTINGS.NO_FORM_TITLE': 'The settings form is not available in this view',
  'SETTINGS.NO_FORM_TEXT': 'This version of Admin cannot embed the settings form. The settings live in the plugin configuration file:',
  'SETTINGS.OPEN_CONFIG': 'Open the plugin configuration page',

  'SETTINGS.PRIVACY_TITLE': 'Data and privacy',
  'SETTINGS.PRIVACY_INTRO': 'What the plugin stores about your rules and your visitors, and how to limit it.',
  'SETTINGS.P_RULES_T': 'Rules',
  'SETTINGS.P_RULES_D': 'Saved on your server in',
  'SETTINGS.P_LOG_T': '404 log',
  'SETTINGS.P_LOG_D': 'Path, query string (secrets removed), referer without its query, user agent and its class, language, host and the visitor’s IP address with the last part set to zero. Kept for 30 days by default (log.retention_days) and capped at 50 MB (log.max_size_mb).',
  'SETTINGS.P_HITS_T': 'Hit counts',
  'SETTINGS.P_HITS_D': 'One counter per rule and day, kept for 90 days. No visitor data.',
  'SETTINGS.P_SUGGEST_T': 'Suggestions and checks',
  'SETTINGS.P_SUGGEST_D': 'Suggested targets for 404 paths and the result of the last live check per rule.',
  'SETTINGS.P_NEVER_T': 'Never stored',
  'SETTINGS.P_NEVER_D': 'Full IP addresses, cookies (the plugin sets none) and anything that leaves your server. Webhook and email digest send data only if you configure them.',
  'SETTINGS.P_OFF_T': 'Turn logging off',
  'SETTINGS.P_OFF_D': 'Switch off logging in the Logging tab, or set the IP address handling to “Do Not Store” to keep no address at all.',
  'SETTINGS.P_OFF_LINK': 'Show the Logging tab',
  'SETTINGS.P_FILES_SUMMARY': 'Where the files are',
  'SETTINGS.P_FILES_TEXT': 'Everything sits in user/data/redirect-manager/: rules.yaml, stats.json, 404/ (one log file per day), suggestions.json and target-checks.json. If you use Git Sync, add hits/ and 404/ to .gitignore: logs are not content.',
};
export default en;
