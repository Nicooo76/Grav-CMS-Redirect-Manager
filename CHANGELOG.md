# v1.0.0
## 10/01/2026

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
    * REST API with 43 routes under `/api/v1/redirects` and an OpenAPI 3.1 description. Permissions `api.redirects.read` and `api.redirects.manage`.
    * Six MCP tools for AI clients: `redirects_list`, `redirects_create`, `redirects_test`, `redirects_top_404`, `redirects_suggest` and `redirects_import`.
    * Events `onRedirectMatched`, `onRedirectRuleSaved`, `onNotFoundLogged` and `onSuggestionCreated`. Signed webhooks for 404 spikes and dead targets. A daily or weekly report mail. Twig function `redirect_for()` and filter `redirect_target`.
    * Three scheduler jobs: hourly maintenance, link check and report mail.
    * Admin 2 page with six tabs and a dashboard widget, in light and dark theme, in English and German.
    * A Redirects panel in the Admin 2 page editor: a toolbar button with a badge for the new automatic redirects of the open page, the redirects to this page, a warning when a rule redirects the page away, old URLs with 404s and a field to add an old URL. Admin 2 cannot show a toast on the page-save screen, so this is where the editor sees what the plugin did.
    * Query strings in the 404 log are redacted for sensitive parameter names, referrers are cut to scheme, host and path, and `user/data/redirect-manager/` carries an `.htaccess` and an empty `index.html`.
    * Uninstalling keeps `user/data/redirect-manager/` and the plugin settings.
    * `export --host=<host>` in the CLI, for `cloudflare_csv`: the host for rules that have no host condition. The REST route has the same `host` parameter.
    * Multisite is tested (`tests/Integration/MultisiteTest.php`): rules, 404 log, hit counts, compiled cache and settings are per site. Grav's `problems` plugin has to be disabled per site on a multisite installation, it answers 500 there.
    * An import file with no directive the format knows (a CSV read as `.htaccess` or nginx) is a file-level error `no_directives`: the preview shows it, the API answers 422, the CLI exits with 2. It used to import nothing and exit 0.
    * Rule ids are unique within a process however many are made in one millisecond, and an import or bulk accept replaces a generated id that is already taken. Ids are 23 characters now (`r` + 11 hex time + 3 hex counter + 8 hex random); stored 18-character ids stay valid.
    * The rule list, dashboard, 404 monitor and suggestions answer in under 0.2 s with 10,000 rules and 50,000 log entries (about 1 s and up to 5 s before): parsed rules are cached by file hash, sorting runs on columns, the 404 log is read once. Numbers in `docs/PERFORMANCE.md`.
    * Optional `debug_timing` setting, or `REDIRECT_MANAGER_TIMING=1`: the header `X-Redirect-Manager-Time` reports the plugin's time per request in microseconds.
    * `bin/gpm direct-install` installs the plugin as `redirect-manager`: permissions and the MCP manifest are in `config/`, and the MCP tools are registered through `onApiMcpTools`.
