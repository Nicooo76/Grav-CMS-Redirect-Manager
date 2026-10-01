# Decisions

Assumptions and choices made while building the plugin. Each entry says what was decided, why, and what it would take to change it.

## D-001: Grav 2.2 only

Target Grav 2.2.2 (current stable on 2026-09-29), API plugin 1.0.42, Admin 2 2.1.25, PHP 8.3 to 8.5. `compatibility: grav: ['2.0']`, dependency `grav >= 2.1.5` because the API plugin needs it. No Grav 1.7 code paths.

## D-002: No hard dependency on api or admin2

The API and Admin 2 plugins are optional. Without them the `onApi*` events never fire; frontend redirects, the 404 log, Twig functions and the CLI still work. Hard dependencies would force GPM to install the whole admin stack on headless or CLI-managed sites. The versions that work together are recorded, not declared: Admin 2 itself declares `api >= 1.0.40`, and this plugin was tested with API plugin 1.0.42 and Admin 2 2.1.25 (README, Requirements). Older versions are untested.

## D-003: Permission names `api.redirects.read` and `api.redirects.manage`

The brief asks for `admin.redirects.read` and `admin.redirects.manage`. In Grav 2 the classic admin is gone; the API plugin checks permissions through `requirePermission()` and only resolves and lists permissions below `api.` (`PermissionResolver.php:111-113`). A permission named `admin.redirects.*` would work for super admins but never show up in the Admin 2 user editor. We keep the brief's verbs (`read`, `manage`) under the `api.` namespace. `admin.super` and `api.super` pass every check.

## D-004: 30x redirects are sent from `PluginsLoadedEvent`

The runtime probe (GRAV2-NOTES.md, section 2) showed that `onRequestHandlerInit`, the documented early hook, runs after the session starts, so every redirect would carry `Set-Cookie` and `Cache-Control: no-store`. `PluginsLoadedEvent` runs earlier, has config and autoloading ready, and produces clean, cacheable redirects. Cost: the plugin resolves base path and language prefix itself (`Grav/RequestContextFactory`). If a future Grav release starts sessions earlier or moves `PluginsLoadedEvent`, the fallback is `onRequestHandlerInit` with explicit header cleanup.

## D-005: 410, 451 and pass-through finish in `onPagesInitialized`

These need Twig (410/451 templates) or the page tree (pass-through). The match result from `PluginsLoadedEvent` is stored on the plugin instance and completed there. Pass-through replaces `$grav['page']` before core resolves it.

## D-006: Rules in YAML, compiled into a PHP array for OPcache

`rules.yaml` stays readable and diffable for Git Sync. Parsing YAML on every request is too slow for 10,000 rules, so the rule set is compiled into `cache://redirect-manager/rules-<hash>.php` (plain arrays; with OPcache the include costs almost nothing). The compiled file records mtime and size of `rules.yaml`; one `stat()` per request detects edits from any source.

## D-007: Hit counts are append-only logs, aggregated later

Writing `rules.yaml` or `stats.json` on every hit would cause lock contention and Git noise. Each hit appends one line to `hits/YYYY-MM-DD.log`. Aggregation (scheduler, admin API, CLI) renames the file first, so concurrent appends go to a new file, and folds it into `stats.json` with daily buckets for 90 days. A writer that still holds the renamed file must not append to it after it was read, so the aggregator seals the file (owner execute bit) under its lock before reading, and a writer starts over when its locked handle is sealed or the path no longer points at it; the path check alone lost 5 to 15 of 4,000 hits in a stress test on macOS.

## D-008: Priority semantics

Higher `priority` wins. Equal priority: exact before wildcard before regex, then older rules first, then id. Drag and drop in the admin writes new priorities for the moved block only.

## D-009: Language handling

Rule sources never contain the Grav language prefix; `conditions.languages` restricts a rule to languages. When the request carried a prefix, internal targets get the same prefix unless the target already starts with a known language prefix or uses `{lang}` explicitly. This matches how Grav stores routes (`route()` has no prefix) and keeps one rule valid for all languages.

## D-010: Admin 2 UI built with Svelte 5 + Vite into single-file custom elements

Admin 2 loads plugin UI as self-contained JavaScript files that define a custom element (Blob `import()`, no relative imports). Team Grav writes these by hand. We write the UI in Svelte 5 (Admin 2's own framework) and let Vite produce one ES file per entry (`admin-next/pages/redirect-manager.js`, `admin-next/widgets/redirect-manager.js`, `admin-next/panels/redirect-manager.js`) with CSS inlined into the shadow root. The built files are committed; end users need no build step. Styling uses only Admin 2's CSS custom properties, so theme, accent colour and dark mode follow the host.

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

## D-026: The list command is `rules`, with the alias `redirects:list`

The brief asks for a CLI command `list`. `bin/plugin redirect-manager` runs on Symfony Console (7.4.19 in `vendor/`), and Symfony has a built-in command named `list`. It is the default command and prints the overview of all commands of the plugin. A plugin command with the same name would take its place:

- Registered with `Application::add()`, it replaces the built-in one, so a bare `bin/plugin redirect-manager` would run our command instead of showing the overview.
- Registered through a command loader, which is how Grav loads plugin commands (`PluginCommandLoader.php`, `PluginApplication::init()`), an explicit `bin/plugin redirect-manager list` runs ours, and the overview is reachable only without a command name.

Both were tried on a stand-alone Symfony `Application` with the same loader arrangement, not inside `bin/plugin` itself. Either outcome makes `list` behave unlike the `list` of every other Grav plugin, so the command is `rules`. `redirects:list` is an alias, and so is `redirects:<name>` for every other command, which keeps names unique if several plugins ever share one console. Earlier text in README and in the help of `cli/RulesCommand.php` said Symfony "reserves" the name. That was wrong: Symfony accepts it. The reason is the lost overview.

Change it: rename the command in `RulesCommand::configure()` and drop the alias. Scripts that call `rules` would break.

## D-027: MCP tools are a manifest and a registrar, there is no `McpTools` class

The brief lists a class `McpTools`. MCP tools of a Grav 2 plugin are data, not code. The API plugin's `McpToolCollector::add()` takes a plain array per tool (`name`, `title`, `description`, `method`, `path`, `permission`, `input`, ...) and rejects entries that break its rules (`grav-plugin-api/classes/Api/Mcp/McpToolCollector.php`, `add()` from line 125, allowed methods in `METHODS`). A tool has no handler. grav-mcp calls the REST route named by `method` and `path` with the caller's own API key, so permissions, validation and errors stay in the controllers. A PHP class per tool would add a second implementation of routes that already exist, or would only build the same arrays. So the six tools are declared once in `config/mcp.yaml`, and `Grav/McpManifest` hands them to the collector in `onApiMcpTools` (`redirect-manager.php`). The manifest is a file that tests read: `U:Grav/McpManifestTest` checks the six names and `I:ApiSystemTest::testEveryMcpToolPointsAtAWorkingRoute` calls each route. Why the file sits in `config/` and not in the plugin root, and what that costs, is in D-024. Tool naming is in D-013.

Change it: a `McpTools` class that returns the manifest array would work and be trivial. It would not make tools executable in PHP, because the API plugin offers no such hook.

## D-028: The frontend bundles live in `admin-next/`, not `assets/`

The brief names `assets/` as the place for the frontend bundle. Admin 2 does not look there. The API plugin serves plugin UI from fixed paths: the page from `admin-next/pages/<slug>.js` (`GpmController::customPageScript()`, line 1753, and the existence check at line 1738), the dashboard widget from `admin-next/widgets/<slug>.js` (line 1770), and field, panel, modal and report scripts from sibling folders. A bundle in `assets/` would never be loaded. So `admin-next/pages/redirect-manager.js`, `admin-next/widgets/redirect-manager.js` and `admin-next/panels/redirect-manager.js` (D-030) are the committed build output (D-010), and the source is in `admin2/`. `assets/` exists in a checkout as an empty, untracked folder. `scripts/build-release.sh` copies it only when it holds files. `U:PluginLayoutTest` and step 5 of the release build check the layout.

Change it: only if Admin 2 changes its convention.

## D-029: SQLite covers the 404 log only, rules stay in YAML

The brief says "Optional SQLite-Backend mit identischem Interface" in the sentence after the 404 log, and it specifies rule storage separately: YAML, "versionierbar mit Git Sync". We read the SQLite option as an option for the 404 log. `NotFound/LogStore` is the interface, `JsonlLogStore` (default) and `SqliteLogStore` implement it, both run the same 27 contract tests (`U:NotFound/LogStoreContract`), and `log.backend: sqlite` falls back to JSONL with a warning when `pdo_sqlite` is missing (`Grav/ServiceFactory.php`). Not covered by SQLite: rules, hit logs and statistics. Rules in a binary database file cannot be diffed, merged or edited by hand, which are the reasons for YAML (D-006), and the compiled cache keys on the mtime and size of `rules.yaml`. Hit logs and statistics are append-only files whose rename protocol (D-007, D-025) has no SQLite counterpart. The log is the part that grows large, is never versioned and is queried by grouping, so it is where SQLite pays.

Change it: a `RuleStore` interface with a SQLite implementation that passes the same tests as `RuleRepositoryTest`, plus an answer to Git Sync (export to YAML on change). We did not build it because the brief's other requirements point the other way.

## D-030: After a page change the editor sees a toolbar badge, a panel and a sidebar badge, not a toast

The brief wants a notification when an automatic redirect is created. What exists: a Redirects button with a badge in the page editor's toolbar and the panel behind it (below); the plugin's sidebar item, which shows a badge with the unseen automatic rules and pending delete decisions (D-023); and the Rules tab, which lists them in a "new automatic redirects" panel with "Mark as seen". What Admin 2 does not offer is a plugin message on the page-save screen. Read in the sources under `grav-plugin-api` and `grav-admin-next` (commits in `docs/GRAV2-NOTES.md`):

- The page editor shows fixed texts. `src/routes/pages/edit/[...route]/+page.svelte` calls `toast.success(i18n.t('ADMIN_NEXT.PAGES.SAVED'))` after a save (line 1524) and `PAGE_SAVED_AND_MOVED` after a move (lines 1450 and 1510). It never looks for a toast in the response body.
- A server-provided toast is honoured in one place only. `extractToastHint()` (`src/lib/utils/toast-hint.ts`) is called from `src/routes/plugin/[slug]/+page.svelte` (lines 153 and 162), for the actions and save endpoints of the plugin's own page. The response of `PATCH /pages/{route}` is not part of that.
- The API plugin builds that response from its page serializer after firing `onApiPageUpdated` (`PagesController::update()`, lines 960 to 968; `move()` likewise, lines 1127 to 1148). The event carries the page, and nothing in the response is taken from listeners. The only `toast` field on the server side is the fifth argument of `ErrorResponse::create()` (`ErrorResponse.php:56-64`), for errors.
- The host fires no window event when a page save ends. `grav:editor:save` is a keyboard command that asks the editor to save.

So a toast on the page-save response is not possible without a change in the API plugin or in Admin 2. The README says so under "Known limits". The requirement "the editor is told" is met by the context panel instead.

**The context panel.** A plugin registers a panel in `onApiContextPanels` (format in the class comment of `ContextPanelController.php`) and ships `admin-next/panels/<slug>.js`. Read in `ContextPanelTriggers.svelte`, `ContextPanelHost.svelte` and the page editor (`pages/edit/[...route]/+page.svelte:1826`), then observed in the real Admin 2 (`P:page-panel.spec.ts`):

- The page editor toolbar gets a button with the panel's Lucide icon and its label as tooltip. Its badge comes from the panel's `badgeEndpoint`, called with `route`, `lang` and `type` (`/redirects/page-context/badge`, `{count}`, `null` for none). The host asks again when the route or language changes and on `pages:update`, `config:update`, `plugins:update` and `themes:update` invalidations. A rename moves the editor to the new route, so the badge is asked for the new page; the spec sees the "1" appear after the save without a reload.
- Clicking the button loads `GET /gpm/plugins/<slug>/panel-script` (the file `admin-next/panels/<slug>.js`) through a Blob import with `window.__GRAV_PANEL_TAG` set, mounts `<grav-<slug>--panel>` in a fixed slide-in with a backdrop, and sets the attributes `route`, `lang` and `type` on it (and keeps them current). The globals `__GRAV_PAGE_ROUTE` and `__GRAV_CONTENT_LANG` carry the same values; the panel reads the attributes first and falls back to the globals. The element may send `close`, `badge` (`detail.count`) and `resize` events. The host provides no header, focus handling or Escape key, so the panel draws its own header, moves focus in and closes on Escape.
- The registration takes `authorize` as a string or an array. The plugin uses the string `api.redirects.read`, like the widget (KI-1 in `docs/FEATURE-CHECKLIST.md`). The label is shown verbatim, so the server translates it, in the site's language and not in the Admin 2 language of the user (the same holds for the widget title). Inside the panel every text follows the user's Admin 2 language. The icon is a Lucide name (`route`), not the Font Awesome name of the sidebar item.
- The panel bundle is a third Vite entry (`admin2/src/entries/panel.ts`, built with `--mode panel`, budget 90 KB raw in `scripts/check-bundles.mjs`). It draws its five icons inline instead of loading the icon library, which is what keeps it under the budget.

What the panel shows for the open page (`GET /redirects/page-context`): the redirects that lead to it; the automatic ones created just now, with "Mark as seen" for this page only (`POST /redirects/page-context/seen`); a warning when a rule redirects the page away; deleted pages below it that wait for a decision; 404s on its old URLs; and a field that adds an old URL as a 301 exact rule with target type page. The panel and the badges meet the requirement. A toast is still impossible.

Sidebar badge, read in the source: Admin 2 refetches sidebar badge counts on content invalidations with the action create, delete, move, copy or list (`AppShell.svelte`, `COUNT_CHANGING_ACTIONS`), and a page update response carries the `pages:list` tag (`PagesController.php:968`). The sidebar badge should therefore refresh after a save without a reload. The toolbar badge of the panel and the sidebar count staying in step were observed in `P:page-panel.spec.ts` and `P:admin2-page-editor.spec.ts`.
