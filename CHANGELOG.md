# Changelog

## 1.0.0 - 2026-09-29

First release, for Grav 2.2.

- Exact, wildcard and regex rules with priorities, conditions (host, language, scheme), query handling, scheduling and expiry.
- Status codes 301, 302, 307, 308, 410, 451 and pass-through (200).
- 404 monitor with JSONL or SQLite log, bot filter, IP anonymization and retention.
- Target suggestions for 404 paths, bulk accept with a score threshold.
- Automatic redirects when a page is moved, renamed or deleted.
- Chain, loop, conflict and dead-target detection; live target checker.
- Import and export: CSV, JSON, YAML, .htaccess, nginx, WordPress, Cloudflare, Netlify, crawler CSV, old sitemaps.
- CLI (`bin/plugin redirect-manager`), scheduler jobs, REST API with OpenAPI description, MCP tools.
- Admin 2 page and dashboard widget.
- Page index over every supported language: suggestions, the page picker and the tester also see pages that exist only in a non-default language.
- `user/data/redirect-manager/` protects itself with an `.htaccess` and an empty `index.html` (an existing `.htaccess` is never overwritten).
- Optional `debug_timing` setting (or `REDIRECT_MANAGER_TIMING=1`): the header `X-Redirect-Manager-Time` reports the plugin's time per request in microseconds.
- Webhook and e-mail digest notifications.
- `bin/gpm direct-install` installs the plugin as `redirect-manager`: permissions and the MCP manifest moved to `config/`, the MCP tools are registered through `onApiMcpTools` (same tool names).
