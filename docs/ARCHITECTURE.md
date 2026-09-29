# Architecture

This file is the contract between the parts of the plugin. If code and this file disagree, fix one of them in the same change.

## Layers

```
redirect-manager.php        Plugin class: event subscriptions and one-line delegations into classes/Grav/, no logic (under 200 lines,
                            guarded by tests/Unit/PluginLayoutTest)
classes/
  Domain/                   Value objects and enums (Rule, Conditions, RequestContext, MatchResult, ...). No Grav.
  Util/                     Ids, Clock, PathNormalizer, small helpers. No Grav.
  Security/                 TargetGuard (open-redirect protection), RegexSafety. No Grav.
  Matching/                 RuleCompiler, CompiledRuleSet, Matcher, MatcherOptions, LocationEncoder. No Grav.
  Analysis/                 ChainAnalyzer, ConflictDetector, RuleValidator, ValidationIssue. No Grav.
  Storage/                  RuleRepository (YAML), CompiledRuleCache (PHP file for OPcache), AtomicFile. No Grav.
  NotFound/                 NotFoundLogger, LogStore (JSONL + SQLite), UserAgentClassifier, IpAnonymizer, IgnoreList. No Grav.
  Stats/                    HitRecorder, StatsStore (daily aggregation, 90 days). No Grav.
  Suggest/                  PageIndex, Suggester, SitemapDiff, SuggestionStore. No Grav.
  ImportExport/             Importer, Exporter, one adapter per format. No Grav (Symfony YAML only).
  Check/                    TargetChecker (HEAD requests, Symfony HttpClient interface). No Grav.
  Notify/                   WebhookNotifier (HMAC), DigestBuilder. No Grav.
  Grav/                     Everything that touches Grav: RequestContextFactory, RedirectResponder (pure, returns ResponseData),
                            ServiceFactory (pure, built from config arrays), RedirectLookup, RuleEvents, TwigExtension,
                            GravPageIndexBuilder (page tree of every supported language) + PageIndexCache, SchedulerJobs (job registration and entry points).
                            The plugin's event bodies: FrontendRedirectHandler (onPluginsLoaded, onPagesInitialized, onPageNotFound:
                            match, send, count hits, log 404s), TwigIntegration (template paths, redirect_for, sandbox policy),
                            AdminIntegration (permissions, sidebar, page definition, widget, MCP tools), RouteRegistrar (all 40 REST routes,
                            the single place they are registered; the integration tests loop over it), SchedulerJobs::onInitialized.
  Auto/                     Automatic redirects. Grav-free: PageSnapshot/PageNode, AutoRedirectPlanner (plan = rules to
                            create, update, delete, notes, pending decision), AutoRedirectApplier (runs a plan in
                            RuleRepository::transaction, fires events, updates the state), AutoState, MoveDeriver,
                            RouteChangeDetector. Touch Grav: AutoRedirectListener (API page events), PageSnapshotter.
  Jobs/                     The scheduler jobs' logic without Grav: MaintenanceJob, SuggestionRefresher, CheckTargetsJob,
                            DigestJob. SchedulerJobs wires them to the plugin's services.
  Api/                      ApiController (extends the API plugin's AbstractApiController).
  Cli/                      CliCommands (shared logic for cli/*Command.php).
cli/                        Symfony console commands for bin/plugin redirect-manager ...
admin-next/                 Built Admin 2 bundles (committed): pages/redirect-manager.js, widgets/redirect-manager.js
admin2/                     Svelte + Vite source of the Admin 2 bundles
templates/redirect-manager/ gone.html.twig, unavailable.html.twig
tests/Unit, tests/Integration, tests/ui (Playwright)
```

Everything outside `Grav/`, `Api/`, `Cli/`, `cli/` and `redirect-manager.php` must run without Grav, so unit tests need only `vendor/`. The classes in `Grav/` and `Auto/` that do touch Grav take the `Grav` container (or a closure that returns the `ServiceFactory`) in the constructor and are unit tested against the stand-ins in `tests/Unit/Support/Grav/`.

Grav includes the plugin file before it calls `autoload()`, so `redirect-manager.php` may not extend, implement or `use` anything from `classes/`; it only names those classes inside method bodies. Page-change events of the API plugin share one method (`onApiPageEvent`), which hands the event name to `AutoRedirectListener::dispatch()`.

## Data files

All under `user://data/redirect-manager/` (per site, so Grav multisite setups keep separate data; versionable with Git Sync):

| File | Format | Written by | Notes |
|---|---|---|---|
| `rules.yaml` | YAML `{version: 1, rules: [...]}` | RuleRepository | Atomic write (temp file + rename) under `rules.lock` |
| `stats.json` | JSON | StatsStore | Aggregated hits per rule: total, last hit, daily counts (90 days) |
| `hits/YYYY-MM-DD.log` | one rule id per line | HitRecorder | Append-only, folded into stats.json and deleted by aggregation |
| `404/YYYY-MM-DD.jsonl` | JSON Lines | JsonlLogStore | Append-only with `LOCK_EX`, retention + size cap |
| `404.sqlite` | SQLite | SqliteLogStore | Only when `log.backend: sqlite` and pdo_sqlite exists |
| `404-state.json` | JSON | NotFound state | Paths marked done, with time |
| `suggestions.json` | JSON | SuggestionStore | Open / accepted / rejected suggestions |
| `target-checks.json` | JSON | TargetChecker | Last live-check result per rule |
| `auto-state.json` | JSON | AutoState | Deleted pages waiting for a decision (with the captured subtree), ids of unseen auto rules (sidebar badge), the last decisions |
| `notify-state.json` | JSON | ThresholdTracker | 404 paths and dead targets already reported by webhook (cooldown, relapse) |

Suggested `.gitignore` for Git Sync users: `hits/`, `404/`, `404.sqlite` (logs are not content).

The directory also carries an `.htaccess` (`Require all denied`, Apache 2.2 fallback `Deny from all`) and an empty `index.html`, written by `Storage/DataDirProtection` the first time a service that writes there is used (`ServiceFactory::protectDataDir()`). Existing files are never overwritten, so a changed `.htaccess` stays. Grav's own `.htaccess` and nginx samples already deny `user/data/` except media files; this is defence in depth (nginx ignores `.htaccess`). The read-only matching path does not touch the directory.

The compiled rule set lives in `cache://redirect-manager/rules-<hash>.php` (plain `return [...]` array, OPcache friendly). It stores mtime, size, inode and ctime of `rules.yaml`; one `stat()` per request detects changes, including those pulled in by Git Sync (a file replaced by `rename()` gets a new inode and ctime; a file modified within the last two seconds is also compared by content hash). Multisite: the data and cache directories follow Grav's `user://` and `cache://` streams, so every site of a `setup.php` multisite installation has its own rules, logs and compiled cache. Clearing the Grav cache only forces a rebuild.

## Rule model

See `Domain/Rule.php`. Serialized keys are snake_case. Important semantics:

- `priority`: higher first. Equal priority: exact, then wildcard, then regex; then `created_at` ascending; then `id`.
- `source`:
  - exact: a path, optionally with `?query` (used by query modes exact/params).
  - wildcard: a path where `*` matches any run of characters including `/`. Each `*` is a capture: `$1`, `$2`, ...
  - regex: a PCRE pattern without delimiters, matched against the normalized path (no query). Named groups become `{name}`, numbered groups `$1`.
- Paths are compared after `PathNormalizer::normalize()` (decoded, NFC, no dot segments, no duplicate slashes). Sources never include the Grav language prefix; use `conditions.languages`.
- `case_sensitive: false` (default) compares lowercase, captures keep the request's case.
- `ignore_trailing_slash: true` (default) treats `/a` and `/a/` as the same path.
- Target placeholders: `$1`..`$9`, `${10}`, `{name}`, `{lang}` (request language or the default language).
- `query_mode`: `ignore` (drop query), `pass` (append request query to target), `exact` (query must equal the source's query, after removing ignored params), `params` (listed params must be present). Global ignore patterns (default `utm_*`, `fbclid`, `gclid`, `msclkid`) plus per-rule `query_ignore`.
- `continue: true`: after this rule matches, matching continues with the rewritten path against the rules ranked after it. The last matching rule decides the status. Depth is capped (`max_chain_depth`, default 10).
- Placeholder details (`Matching/Matcher`): captured text is percent-encoded when substituted (path context keeps `/`; inside the target's query everything reserved is encoded), so a capture cannot add `?`, `#` or `&`. An empty `{lang}` drops its path segment (`/{lang}/about` becomes `/about`). Duplicate slashes inside an internal target path collapse, but a leading `//` or `/\` does not: `TargetGuard::isSafeLocation()` rejects it and the rule is treated as not matching (trace reason `unsafe_target`). Named groups win over `{lang}`. Unknown `{x}` stays literal.
- Host lists (`conditions.hosts`, external target allowlist): `*.example.com` matches subdomains only, not the apex; list `example.com` as well to cover both. Header/cookie `regex` conditions are case-sensitive (use `(?i)`); `equals`, `contains`, `starts_with` ignore case; header names ignore case, cookie names do not.
- `only_if_not_found: true`: the rule is evaluated only after Grav found no page (`onPageNotFound`), so existing pages are never overridden.
- `status: 200` is pass-through: Grav serves the target page under the requested URL.
- `target_type: page`: `target` is a page route. Auto-redirect updates it when the page moves.

## Request flow (frontend)

Everything below is wrapped in try/catch: a failure is logged to `grav.log` and Grav carries on. A broken `rules.yaml` keeps the last compiled rules in force (or an empty set) and logs once per change.

1. `Grav\Events\PluginsLoadedEvent` (priority 10000, not in CLI): `ServiceFactory::enabled()`, then `RequestContextFactory` builds the context from the PSR request (strips base path and language prefix itself; invalid paths mean "never redirect"). Excluded paths (API route, Admin 2 route, `redirects.excluded_paths`, `/user/ /system/ /vendor/ /cache/ /logs/`) end the flow for the request. Then `CompiledRuleCache::load()` (one `stat()` plus one include when warm) and `Matcher::match(ctx, MatchPhase::Early)`.
   - The event `onRedirectMatched` fires (payload `result`, `context`, `request`, `cancel`); listeners may replace `result` or cancel.
   - 30x: hits are appended (one line per applied rule), `RedirectResponder` builds the response (Location with base path and language prefix, `Cache-Control` permanent or temporary, `X-Redirect-By`, empty body) and `$grav->close()` sends it. No session exists yet, so no cookie and cacheable. This also runs before Grav's trailing-slash redirect, so there is no double hop.
   - 410 / 451 / pass-through: the result is kept on the `FrontendRedirectHandler` and finished in step 2.
   - With `debug_timing: true` (or the environment variable `REDIRECT_MANAGER_TIMING=1`) the handler adds `X-Redirect-Manager-Time: <microseconds>` for the time from handler entry to the end of the match (services, request context, compiled rule cache, match) on every request the plugin looked at. Off by default: the only cost then is one `getenv()` and one config read. Numbers: docs/PERFORMANCE.md.
2. `onPagesInitialized` (priority 10): 410/451 render `redirect-manager/gone.html.twig` / `unavailable.html.twig` (a theme overrides them with its own `templates/redirect-manager/...`; plugin path is added last in `onTwigTemplatePaths`; the templates get `redirect_status` and `redirect_path` plus Grav's own Twig variables, documented at the top of each template) and close with that status. The session has started by now, so `Set-Cookie`, `Expires`, `Pragma` are removed before closing. Pass-through: `unset($grav['page']); $grav['page'] = $pages->find(target)`; a target that is not a routable page logs a warning, records no hit and falls through to the normal 404.
3. `onPageNotFound` (priority 10, above the error plugin): `NotFoundLogger::log()` (skipped for ignored paths, non-GET/HEAD, logging off, bots when `log_bots: false`), event `onNotFoundLogged` (payload `entry`), then `Matcher::match(ctx, MatchPhase::NotFound)` for "only if not found" rules with the same `onRedirectMatched` event. 30x and 410/451 are sent like in step 1/2 with the session headers stripped; pass-through sets `$event->page` and stops propagation so the error plugin does not replace it. Nothing matched: no `stopPropagation`.
4. Hits are appended with `HitRecorder` (one line per hit); aggregation happens in the scheduler, the admin API and the CLI. Retention purges of the 404 log are not run on requests either.
5. Twig (`onTwigInitialized`): function `redirect_for(url)` returns `{status, location, rule_id}` or null, filter `redirect_target` returns the location or its input. Both are read-only (no hit, nothing sent), consider only rules without "only if not found", and are on the sandbox allow-list (`onBuildTwigSandboxPolicy`).

Events for other plugins are fired through `Grav\RuleEvents` (`saved()`, `matched()`, `notFoundLogged()`); the API controller and the CLI call `saved($rule, $previous, $action)` after they wrote `rules.yaml`.

## Automatic redirects (API page events)

`AutoRedirectListener` handles the API plugin's events (`onApiBeforePageUpdate`, `onApiPageUpdated`, `onApiPageMoved`, `onApiBeforePageDelete`, `onApiPageDeleted`, `onApiBeforePagesReorganize`, `onApiPagesReorganized`, listed in `AutoRedirectListener::EVENTS`; batch variants carry `method: batch`). The plugin class subscribes to them and forwards each to `AutoRedirectListener::dispatch()`.

- Update: the before event captures a `PageSnapshot` only when the request body changes `header.slug` or `header.routes` (`RouteChangeDetector`), so autosaves cost nothing. The after event reloads the page tree from disk (`Pages::reset()`) and compares.
- Move (`POST /pages/{route}/move`): the API has no before event, and the folder is renamed before the event fires. The old routes are derived from the old parent (still in place) and the slug per language (`MoveDeriver`).
- Reorganize: exact. The before event hands over the page objects, the after event the final folders.
- Delete: the before event snapshots the subtree (with the ancestors for the "parent" policy), the after event applies the policy. Deleting one language of a page that keeps other translations does nothing.
- `AutoRedirectPlanner` is pure. `AutoRedirectApplier` plans inside `RuleRepository::transaction()` and, after the write, invalidates the compiled cache, fires `onRedirectRuleSaved` (`auto` for created and re-targeted rules, `delete` for removed ones), records unseen ids and logs one line to grav.log.
- Every handler catches `Throwable`: a failure is logged and never reaches the API request.

## Scheduler

`onSchedulerInitialized` registers three jobs (static `Class::method` callables in `Grav/SchedulerJobs`): `redirect-manager-maintenance` (hourly), `redirect-manager-check-targets` (`checker.schedule`, when `checker.enabled`) and `redirect-manager-digest` (07:00 daily or Monday, when `notifications.email_digest` is not `none`). Callable jobs run inside the scheduler process, so Grav and the plugin are loaded. Run one now: `bin/grav scheduler --run=<job id>`; list them: `bin/grav scheduler --jobs`. Jobs have no request to read the site URL from: set `base_url` in the plugin config (else `system.custom_base_url`, else `http://localhost`).

## Plugin root and `config/`

The root holds exactly three yaml files: `redirect-manager.yaml` (defaults), `blueprints.yaml` and `languages.yaml`. GPM names a direct-installed package after the first other `*.yaml` it finds in the ZIP root, so `permissions.yaml` and the MCP manifest live in `config/`. `onRegisterPermissions` reads `config/permissions.yaml`; `onApiMcpTools` hands `config/mcp.yaml` to the API plugin's tool collector (`Grav\McpManifest`), which yields the same tools as a root `mcp.yaml` would. Why: docs/DECISIONS.md D-024. `scripts/build-release.sh` and `tests/Unit/PluginLayoutTest.php` guard the layout.

## Integration tests

`scripts/setup-test-site.sh` downloads Grav into `.grav/<version>` (gitignored) and links the plugin in. `tests/Integration` copies `user/` of that site per test class into a temp directory (system, vendor, bin and the plugins are symlinks), starts `php -S` on a free port (binary from `RM_PHP_BIN`), writes rules and config per scenario and checks real HTTP answers with curl. The Grav cache is off in the copy, so config and page edits apply at once; the plugin's compiled rule cache is not affected (`PageCacheTest` switches Grav's cache on). `TestSite::createMultisite()` builds a `setup.php` installation with two sites for `MultisiteTest`. `Support/RegisteredRoutes` lists the REST routes from `RouteRegistrar` and the permission each one has in `docs/openapi.yaml`; `ApiSecurityTest` runs its 401, 403 and same-origin checks over that list. A test class that needs its own port range respects the caller's `RM_PORT_RANGE`. PHPStan scans `.grav/2.2.2/system/src` and `.grav/2.2.2/vendor` for the Grav, Nyholm and Twig classes.

## Conventions for all code

- PHP 8.3 syntax only (no property hooks, no asymmetric visibility). `declare(strict_types=1)`, PSR-12, PHPStan level 8 clean.
- Enums for closed sets, readonly value objects, no static mutable state.
- Services get their collaborators through the constructor. Time comes from `Util\Clock`.
- File writes: `Storage\AtomicFile` (temp + rename) for documents, `fopen('a')` + `flock(LOCK_EX)` for logs.
- User-visible strings are translation keys under `PLUGIN_REDIRECT_MANAGER.*` (en + de in `languages/`).
- Tests: PHPUnit 11 attributes (`#[CoversClass]`, `#[DataProvider]`, `#[Group]`), one test class per service, temp dirs under `sys_get_temp_dir()` removed in `tearDown()`.
