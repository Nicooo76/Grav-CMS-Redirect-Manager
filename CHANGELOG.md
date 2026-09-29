# v1.0.0
## 09/29/2026

1. [](#new)
    * First release, for Grav 2.1.5 and newer. Developed and tested on Grav 2.2.2 with PHP 8.3, 8.4 and 8.5.
    * Exact, wildcard and regex rules. Priorities, conditions on host, language, scheme, request headers and cookies, four query modes, start and end dates, "continue matching" and "only if no page exists".
    * Status codes 301, 302, 307, 308, 410 and 451, and pass-through (200). 30x answers leave before the session starts, so they carry no cookie, can be cached and skip Grav's trailing-slash hop. 410 and 451 pages can be overridden in a theme.
    * 404 monitor with a JSONL or SQLite log, bot filter, ignore list, IP anonymization, retention and a size cap.
    * Target suggestions for 404 paths, with a score and a reason, and bulk accept above a threshold with a preview.
    * Automatic redirects when a page is moved, renamed, reorganized or deleted. Deleted pages can ask, answer 410, redirect to the parent or do nothing.
    * URL tester with the full chain and a trace of every candidate rule. Rule analysis for chains, loops, conflicts, shadowed rules, expired and scheduled rules, dead targets and unused rules. Live link check with protection against private addresses.
    * Import from CSV, JSON, YAML, Grav `site.yaml`, Apache `.htaccess`, nginx, Netlify `_redirects`, WordPress Redirection (JSON and CSV), crawler exports and old sitemaps. Export to the same formats except crawler and sitemap, plus Cloudflare Bulk Redirects. Every export lists the rules it skipped or simplified.
    * CLI `bin/plugin redirect-manager` with 13 commands, JSON output and exit codes for CI: `add`, `rules`, `enable`, `disable`, `remove`, `test`, `stats`, `import`, `export`, `suggest`, `check-targets`, `prune` and `rebuild-cache`.
    * REST API with 40 routes under `/api/v1/redirects` and an OpenAPI 3.1 description. Permissions `api.redirects.read` and `api.redirects.manage`.
    * Six MCP tools for AI clients: `redirects_list`, `redirects_create`, `redirects_test`, `redirects_top_404`, `redirects_suggest` and `redirects_import`.
    * Events `onRedirectMatched`, `onRedirectRuleSaved`, `onNotFoundLogged` and `onSuggestionCreated`. Signed webhooks for 404 spikes and dead targets. A daily or weekly report mail. Twig function `redirect_for()` and filter `redirect_target`.
    * Three scheduler jobs: hourly maintenance, link check and report mail.
    * Admin 2 page with six tabs and a dashboard widget, in light and dark theme, in English and German.
    * Query strings in the 404 log are redacted for sensitive parameter names, referrers are cut to scheme, host and path, and `user/data/redirect-manager/` carries an `.htaccess` and an empty `index.html`.
    * Uninstalling keeps `user/data/redirect-manager/` and the plugin settings.
    * Optional `debug_timing` setting, or `REDIRECT_MANAGER_TIMING=1`: the header `X-Redirect-Manager-Time` reports the plugin's time per request in microseconds.
    * `bin/gpm direct-install` installs the plugin as `redirect-manager`: permissions and the MCP manifest are in `config/`, and the MCP tools are registered through `onApiMcpTools`.
