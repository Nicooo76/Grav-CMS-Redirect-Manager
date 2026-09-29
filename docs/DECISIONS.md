# Decisions

Assumptions and choices made while building the plugin. Each entry says what was decided, why, and what it would take to change it.

## D-001: Grav 2.2 only

Target Grav 2.2.2 (current stable on 2026-09-29), API plugin 1.0.42, Admin 2 2.1.25, PHP 8.3 to 8.5. `compatibility: grav: ['2.0']`, dependency `grav >= 2.1.5` because the API plugin needs it. No Grav 1.7 code paths.

## D-002: No hard dependency on api or admin2

The API and Admin 2 plugins are optional. Without them the `onApi*` events never fire; frontend redirects, the 404 log, Twig functions and the CLI still work. Hard dependencies would force GPM to install the whole admin stack on headless or CLI-managed sites.

## D-003: Permission names `api.redirects.read` and `api.redirects.manage`

The brief asks for `admin.redirects.read` and `admin.redirects.manage`. In Grav 2 the classic admin is gone; the API plugin checks permissions through `requirePermission()` and only resolves and lists permissions below `api.` (`PermissionResolver.php:111-113`). A permission named `admin.redirects.*` would work for super admins but never show up in the Admin 2 user editor. We keep the brief's verbs (`read`, `manage`) under the `api.` namespace. `admin.super` and `api.super` pass every check.

## D-004: 30x redirects are sent from `PluginsLoadedEvent`

The runtime probe (GRAV2-NOTES.md, section 2) showed that `onRequestHandlerInit`, the documented early hook, runs after the session starts, so every redirect would carry `Set-Cookie` and `Cache-Control: no-store`. `PluginsLoadedEvent` runs earlier, has config and autoloading ready, and produces clean, cacheable redirects. Cost: the plugin resolves base path and language prefix itself (`Grav/RequestContextFactory`). If a future Grav release starts sessions earlier or moves `PluginsLoadedEvent`, the fallback is `onRequestHandlerInit` with explicit header cleanup.

## D-005: 410, 451 and pass-through finish in `onPagesInitialized`

These need Twig (410/451 templates) or the page tree (pass-through). The match result from `PluginsLoadedEvent` is stored on the plugin instance and completed there. Pass-through replaces `$grav['page']` before core resolves it.

## D-006: Rules in YAML, compiled into a PHP array for OPcache

`rules.yaml` stays readable and diffable for Git Sync. Parsing YAML on every request is too slow for 10,000 rules, so the rule set is compiled into `cache://redirect-manager/rules-<hash>.php` (plain arrays; with OPcache the include costs almost nothing). The compiled file records mtime and size of `rules.yaml`; one `stat()` per request detects edits from any source.

## D-007: Hit counts are append-only logs, aggregated later

Writing `rules.yaml` or `stats.json` on every hit would cause lock contention and Git noise. Each hit appends one line to `hits/YYYY-MM-DD.log`. Aggregation (scheduler, admin API, CLI) renames the file first, so concurrent appends go to a new file, and folds it into `stats.json` with daily buckets for 90 days.

## D-008: Priority semantics

Higher `priority` wins. Equal priority: exact before wildcard before regex, then older rules first, then id. Drag and drop in the admin writes new priorities for the moved block only.

## D-009: Language handling

Rule sources never contain the Grav language prefix; `conditions.languages` restricts a rule to languages. When the request carried a prefix, internal targets get the same prefix unless the target already starts with a known language prefix or uses `{lang}` explicitly. This matches how Grav stores routes (`route()` has no prefix) and keeps one rule valid for all languages.

## D-010: Admin 2 UI built with Svelte 5 + Vite into single-file custom elements

Admin 2 loads plugin UI as self-contained JavaScript files that define a custom element (Blob `import()`, no relative imports). Team Grav writes these by hand. We write the UI in Svelte 5 (Admin 2's own framework) and let Vite produce one ES file per entry (`admin-next/pages/redirect-manager.js`, `admin-next/widgets/redirect-manager.js`) with CSS inlined into the shadow root. The built files are committed; end users need no build step. Styling uses only Admin 2's CSS custom properties, so theme, accent colour and dark mode follow the host.

## D-011: One admin route, hash sub-views

Admin 2 gives a plugin exactly one page route (`/plugin/<slug>`). Rules, 404 monitor, suggestions, tester, import/export and settings are tabs inside that page, addressed by a hash (`#/rules`, `#/404`, ...), so deep links and the back button work.

## D-012: OpenAPI as a shipped file

The API plugin's `openapi.yaml` covers core routes only and has no extension hook. The plugin ships `docs/openapi.yaml` for its own routes.

## D-013: MCP tools through a tool manifest

grav-mcp reads the tools of enabled plugins from `GET /api/v1/mcp/tools`. The plugin declares them in a manifest (`config/mcp.yaml`, see D-024 for why it is not in the plugin root) with prefix `redirects`, which yields `redirects_list`, `redirects_create`, `redirects_test`, `redirects_top_404` (not `404_top`: a tool name must start with a letter), `redirects_suggest`, `redirects_import`. Imports use a JSON body with the file content as a string, because MCP manifests do not support multipart.

## D-014: Automatic redirects are planned by a pure planner, applied in one transaction

`Auto/AutoRedirectPlanner` turns two page snapshots and the existing rules into a plan; `AutoRedirectApplier` runs it inside `RuleRepository::transaction()`. Rules made by the feature have `origin: auto` and `target_type: page` (exact) or `route` (wildcard `/old/* -> /new/$1`, which needs `$1`). What the planner does beyond "old -> new":

- **Language:** one rule per group of languages with the same old and new route; the language condition is set only when the group does not cover every language of the page.
- **Moving back:** an auto rule from the new route to the old one is removed and nothing is created. Any auto rule whose source is the new route (a live page now) is removed.
- **Chains:** auto rules and rules with `target_type: page` that point at the old route (or below it) get the new target, so `A -> B` and `B -> C` end as `A -> C` and `B -> C`. Rules with a plain route target are never touched.
- **Manual rules win:** an enabled manual rule with the same source blocks the auto rule (noted in grav.log); a manual rule back from the new route is a loop and blocks it too. Routes another page serves are never used as a source (reorganize swaps).
- **Delete:** `gone` = 410 for the route and a wildcard (or one rule per descendant with children mode `each`) for descendants; `parent` = 301 to the nearest ancestor that exists (the home page for a top-level page); `ask` = a pending decision in `auto-state.json`; `never` = nothing. Auto rules that pointed at the deleted page become 410 or follow the parent.

Change it: the plan format (`AutoPlan`) is the seam; the applier never decides.

## D-015: Old routes of a single move are derived, not captured

`POST /pages/{route}/move` fires only `onApiPageMoved` after the folder was renamed and the page tree re-initialised, so the page's old per-language routes cannot be read any more (`translatedLanguages()` reads the files). `MoveDeriver` rebuilds them from the old parent (still in place), the slug per language (unchanged by a move) and, for a move that renames the folder, the old slug. Exact for moves and for renames on sites without per-language slugs; a move that renames a page with translated slugs is exact only for the languages whose slug follows the folder. Reorganize, update and delete have before events and are exact. Changing it would need a before event for move in the API plugin.

## D-016: Deleting one language of a page does nothing

`DELETE /pages/{route}?lang=de` on a page that keeps other translations removes only one file. Grav serves the default-language content in a missing language unless configured otherwise, so a 410 or a redirect could hide a live page. The feature leaves it alone; the 404 monitor covers the case where the URL really dies. Deleting the last translation is a normal delete.

## D-017: Scheduler jobs are static callables, one per concern

Callable jobs run inside the scheduler process, where Grav and the plugin are loaded. Three jobs instead of one so that schedules and enabled states (`user/config/scheduler.yaml`) are independent: hourly maintenance, the link check on `checker.schedule`, and the digest. A step of the maintenance job that fails is recorded and the next still runs. The digest goes through `$grav['Email']`; without the email plugin (or with engine `none`) it logs and does nothing. Jobs cannot see the request URL, so live checks and mail links use the `base_url` setting.

## D-018: The page index walks the page tree once per supported language

Grav holds one language's page tree, and a folder that has no file in any language of the active fallback chain (`default.de.md` and nothing else, active language `en`) is only an empty placeholder in it. Reading `translatedLanguages()` per page cannot find such pages. `Grav/GravPageIndexBuilder` therefore sets each supported language active (`Language::setActive()` plus `resetFallbackPageExtensions()`, because Grav caches the fallback extension list under a language-independent key), calls `Pages::reset()`, takes the pages whose own file is in that language (`default.md` without extension counts as the default language), and restores the original language and tree at the end (the original language goes last, so no extra reset is needed; an unset language is written back by reflection because `setActive()` cannot unset). Cost: one tree build per language when Grav's page cache is off, one cache read per language when it is on; the result is cached under a stamp of the page files that does not depend on the language (Grav's `getSimplePagesHash()` with the page cache on, else a scan of `*.md` and `*.yaml`). Only API, CLI and scheduler runs build the index, never a frontend request.

## D-019: The data directory protects itself

`user/data/redirect-manager/` gets an `.htaccess` (`Require all denied`, `Deny from all` for Apache 2.2) and an empty `index.html` when the plugin first writes there. Existing files are left alone. Grav's own `.htaccess` (and its nginx and Caddy samples in `webserver-configs/`) already deny `user/data/` except media files; rules, IP addresses and 404 logs should not depend on one layer. nginx and IIS ignore `.htaccess`, so on those servers Grav's server config remains the only protection.

## D-020: The plugin can time itself, and nothing else does

Proof that 10,000 rules cost well under a millisecond per request needs a measurement inside a real request. With `debug_timing` or `REDIRECT_MANAGER_TIMING=1` the early phase reports `X-Redirect-Manager-Time` in microseconds. It is off by default, is not part of the settings form, and not documented for site owners beyond docs/PERFORMANCE.md. `scripts/benchmark-request.sh` and the `benchmark` group of the integration suite use it.

## D-021: One language file, generated in part

`languages.yaml` holds every text of the plugin: settings (`CONFIG`), permissions, front-end pages, the widget label, the issue codes of imports (`IMPORT.ISSUE.<CODE>`) and of rule validation (`VALIDATION.<CODE>`), and the Admin 2 texts under `UI`. The `UI` section is generated: `npm run i18n` (in `admin2/`) compiles `admin2/src/i18n/{en,de}/*.ts` into it and leaves every other section and comment alone. Codes without an entry fall back to the English message the server sends. The page bundle ships only the shell strings as English fallback (`src/lib/i18n-fallback.ts`); Admin 2 loads the dictionary before it shows a plugin page and the API plugin merges the English texts under any language, so the fallback matters only for a stale dictionary cache. Keeping all 788 strings in the bundle cost 41 KB raw and 13 KB gzip. German uses "Sie", like Admin 2's own translations. `tests/Unit/LanguagesTest.php` and `admin2/src/i18n-yaml.test.ts` keep the file complete.

## D-022: The UI learns what the user may do from `GET /redirects/stats`

`meta.permissions` `{read, manage}` uses the same check as the controllers (`BaseController::may()` calls `requirePermission()`), so key scope caps, super admins and `api.access` count the same way. The UI hides or disables write controls when `manage` is false and says why. The server still refuses every write with 403; the flags are convenience, not protection. `meta.default_status` tells the editor which status a new rule starts with.

## D-023: The sidebar badge is `null` at zero

Admin 2 draws the pill of a sidebar item whenever the value is not null or undefined, so `count: 0` shows a "0". `GET /redirects/badge` and `POST /redirects/badge/seen` answer `count: null` when nothing is unseen and nothing is pending. The live update through `grav:sidebar:badge` ignores null in Admin 2, so the UI sends 0 when the last item is cleared: the pill then reads "0" until the next reload. That is Admin 2 behaviour; hiding it live would need a change there.

## D-024: Only three yaml files in the plugin root; permissions and MCP manifest live in `config/`

`bin/gpm direct-install` names the package after the first `*.yaml` in the ZIP root, alphabetically, ignoring only `blueprints.yaml` and `languages.yaml` (`GPM::getPackageName()`, Grav 2.2.2). With `mcp.yaml` and `permissions.yaml` in the root, both sorting before `redirect-manager.yaml`, GPM installed the plugin as `user/plugins/mcp`. Renaming was no option (`redirect-manager.yaml` is the plugin's config and must keep its name), so the root holds exactly `blueprints.yaml`, `languages.yaml` and `redirect-manager.yaml`:

- `permissions.yaml` moved to `config/permissions.yaml`; `onRegisterPermissions` reads `plugin://redirect-manager/config/permissions.yaml`.
- The MCP manifest moved to `config/mcp.yaml`. The API plugin only looks for `plugins://<slug>/mcp.yaml`, so `onApiMcpTools` parses the file and calls `registerPlugin()` and `add()` on the event's `McpToolCollector` (`classes/Grav/McpManifest.php`). That is the collector's public runtime route and it runs the same validation, prefix rule (`{prefix}_{name}`), duplicate check and warnings as a root manifest; permission filtering in `McpController` works on the collected tools and does not care where they came from. The tool names did not change.

Cost: the ETag of `GET /mcp/tools` is built from the enabled-plugin set and the mtimes of root `mcp.yaml` files only. Tools added through `onApiMcpTools` do not move it, so after a plugin update that changes a tool, a client that sends `If-None-Match` keeps its cached list until the set of enabled plugins changes (grav-mcp reads the list at startup anyway). `scripts/build-release.sh` fails when the ZIP has any other root `*.yaml`; `tests/Unit/PluginLayoutTest.php` fails in the repository. The `bin/gpm` step of `scripts/test-release.sh` is a required check in CI.

## D-025: Hit and 404 writers retry until they hold the current file, and never give up early

Both append-only logs (`Stats/HitRecorder`, `NotFound/JsonlLogStore`) are rewritten by rename while writers run: `StatsStore::aggregate()` renames the day file, `purge()`, `deletePath()` and rotation replace or delete it. A writer opens the file, locks it, checks that the path still points at the file it locked, and starts over otherwise; the aggregator locks the renamed file before it reads it. That protocol is lossless, and the code did not change. What lost hits was around it: `HitRecorder` allowed 5 attempts and then threw ("kept changing while writing"), and it threw "Cannot open hit log" when a concurrent writer had just created the directory. `StatsStoreTest::testRecordingWhileAggregatingLosesAndDuplicatesNothing` ran the aggregator in a loop without a pause, so a loaded machine (writers descheduled between open and lock) hit the limit in about 4 of 5 runs at 16 parallel test runs; the failing worker dropped its remaining hits (the 3,983 of 4,000). Both limits are now 1,000 attempts, meant to stop a broken file system and not to be reached, and a failed open creates the directory and retries once before it counts as an error. `HitRecorder` has an optional `afterOpen` hook (tests only) that runs an aggregation in the window between open and lock; `HitRecorderAggregationRaceTest` uses it. Not changed: `flock()` results are still not checked, because a file system without lock support (some network mounts) would otherwise lose every hit.
