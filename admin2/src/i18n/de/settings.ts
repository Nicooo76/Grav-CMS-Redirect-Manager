// German strings for this area. Keys are relative to PLUGIN_REDIRECT_MANAGER.UI.
const de: Record<string, string> = {
  'SETTINGS.FORM_LABEL': 'Plugin-Einstellungen',
  'SETTINGS.NO_FORM_TITLE': 'Das Einstellungsformular ist in dieser Ansicht nicht verfügbar',
  'SETTINGS.NO_FORM_TEXT': 'Diese Admin-Version kann das Einstellungsformular nicht einbetten. Die Einstellungen stehen in der Konfigurationsdatei des Plugins:',
  'SETTINGS.OPEN_CONFIG': 'Konfigurationsseite des Plugins öffnen',

  'SETTINGS.PRIVACY_TITLE': 'Daten und Datenschutz',
  'SETTINGS.PRIVACY_INTRO': 'Was das Plugin über Ihre Regeln und Besucher speichert und wie sich das begrenzen lässt.',
  'SETTINGS.P_RULES_T': 'Regeln',
  'SETTINGS.P_RULES_D': 'Gespeichert auf Ihrem Server in',
  'SETTINGS.P_LOG_T': '404-Protokoll',
  'SETTINGS.P_LOG_D': 'Pfad, Query-String (sensible Werte entfernt), Referrer ohne Query, User-Agent samt Klasse, Sprache, Host und die Besucher-IP, deren letzter Teil auf null gesetzt ist. Das Plugin bewahrt sie standardmäßig 30 Tage auf (log.retention_days) und begrenzt das Protokoll auf 50 MB (log.max_size_mb).',
  'SETTINGS.P_HITS_T': 'Aufrufzähler',
  'SETTINGS.P_HITS_D': 'Ein Zähler pro Regel und Tag, 90 Tage aufbewahrt. Keine Besucherdaten.',
  'SETTINGS.P_SUGGEST_T': 'Vorschläge und Prüfungen',
  'SETTINGS.P_SUGGEST_D': 'Vorgeschlagene Ziele für 404-Pfade und das Ergebnis der letzten Live-Prüfung pro Regel.',
  'SETTINGS.P_NEVER_T': 'Nie gespeichert',
  'SETTINGS.P_NEVER_D': 'Vollständige IP-Adressen, Cookies (das Plugin setzt keine) und alles, was Ihren Server verlässt. Webhook und E-Mail-Zusammenfassung senden nur dann Daten, wenn Sie sie einrichten.',
  'SETTINGS.P_OFF_T': 'Protokollierung ausschalten',
  'SETTINGS.P_OFF_D': 'Die Protokollierung im Tab „Protokollierung“ ausschalten oder die IP-Adress-Verarbeitung auf „Nicht speichern“ stellen, damit gar keine Adresse gespeichert wird.',
  'SETTINGS.P_OFF_LINK': 'Tab „Protokollierung“ anzeigen',
  'SETTINGS.P_FILES_SUMMARY': 'Wo die Dateien liegen',
  'SETTINGS.P_FILES_TEXT': 'Alles liegt in user/data/redirect-manager/: rules.yaml, stats.json, 404/ (eine Protokolldatei pro Tag), hits/ (Aufruflisten, bis sie in stats.json einfließen), suggestions.json und target-checks.json. Bei Git Sync gehören hits/ und 404/ in die .gitignore: Protokolle sind keine Inhalte.',
};
export default de;
