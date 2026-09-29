// German strings for this area. Keys are relative to PLUGIN_REDIRECT_MANAGER.UI.
const de: Record<string, string> = {
  'SETTINGS.FORM_LABEL': 'Plugin-Einstellungen',
  'SETTINGS.NO_FORM_TITLE': 'Das Einstellungsformular gibt es in dieser Ansicht nicht',
  'SETTINGS.NO_FORM_TEXT': 'Diese Admin-Version kann das Einstellungsformular nicht einbetten. Die Einstellungen stehen in der Konfigurationsdatei des Plugins:',
  'SETTINGS.OPEN_CONFIG': 'Konfigurationsseite des Plugins öffnen',

  'SETTINGS.PRIVACY_TITLE': 'Daten und Datenschutz',
  'SETTINGS.PRIVACY_INTRO': 'Was das Plugin über deine Regeln und deine Besucher speichert und wie du das begrenzt.',
  'SETTINGS.P_RULES_T': 'Regeln',
  'SETTINGS.P_RULES_D': 'Gespeichert auf deinem Server in',
  'SETTINGS.P_LOG_T': '404-Protokoll',
  'SETTINGS.P_LOG_D': 'Pfad, Query-String (Geheimnisse entfernt), Referer ohne Query, User-Agent und seine Klasse, Sprache, Host und die IP-Adresse des Besuchers mit auf null gesetztem letztem Teil. Standardmäßig 30 Tage aufbewahrt (log.retention_days) und auf 50 MB begrenzt (log.max_size_mb).',
  'SETTINGS.P_HITS_T': 'Trefferzähler',
  'SETTINGS.P_HITS_D': 'Ein Zähler pro Regel und Tag, 90 Tage aufbewahrt. Keine Besucherdaten.',
  'SETTINGS.P_SUGGEST_T': 'Vorschläge und Prüfungen',
  'SETTINGS.P_SUGGEST_D': 'Vorgeschlagene Ziele für 404-Pfade und das Ergebnis der letzten Live-Prüfung pro Regel.',
  'SETTINGS.P_NEVER_T': 'Nie gespeichert',
  'SETTINGS.P_NEVER_D': 'Vollständige IP-Adressen, Cookies (das Plugin setzt keine) und alles, was deinen Server verlässt. Webhook und E-Mail-Zusammenfassung senden nur dann Daten, wenn du sie einrichtest.',
  'SETTINGS.P_OFF_T': 'Protokollierung ausschalten',
  'SETTINGS.P_OFF_D': 'Schalte die Protokollierung im Tab „Protokollierung“ aus oder stelle die IP-Adress-Verarbeitung auf „Nicht speichern“, damit gar keine Adresse gespeichert wird.',
  'SETTINGS.P_OFF_LINK': 'Tab „Protokollierung“ anzeigen',
  'SETTINGS.P_FILES_SUMMARY': 'Wo die Dateien liegen',
  'SETTINGS.P_FILES_TEXT': 'Alles liegt in user/data/redirect-manager/: rules.yaml, stats.json, 404/ (eine Protokolldatei pro Tag), suggestions.json und target-checks.json. Wenn du Git Sync nutzt, trage hits/ und 404/ in die .gitignore ein: Protokolle sind keine Inhalte.',
};
export default de;
