# Architecture

This file is the contract between the parts of the plugin. If code and this file disagree, fix one of them in the same change.

## Layers

```
redirect-manager.php        Plugin class: event wiring only, no logic
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
  Grav/                     Everything that touches Grav: RequestContextFactory, RedirectResponder,
                            GravPageIndexBuilder, AutoRedirectListener, TwigExtension, ServiceFactory, Scheduler jobs.
  Api/                      ApiController (extends the API plugin's AbstractApiController).
  Cli/                      CliCommands (shared logic for cli/*Command.php).
cli/                        Symfony console commands for bin/plugin redirect-manager ...
admin-next/                 Built Admin 2 bundles (committed): pages/redirect-manager.js, widgets/redirect-manager.js
admin2/                     Svelte + Vite source of the Admin 2 bundles
templates/redirect-manager/ gone.html.twig, unavailable.html.twig
tests/Unit, tests/Integration, tests/ui (Playwright)
```

Everything outside `Grav/`, `Api/`, `Cli/`, `cli/` and `redirect-manager.php` must run without Grav, so unit tests need only `vendor/`.

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
| `auto-state.json` | JSON | AutoRedirectListener | Routes captured in "before" API events |

Suggested `.gitignore` for Git Sync users: `hits/`, `404/`, `404.sqlite` (logs are not content).

The compiled rule set lives in `cache://redirect-manager/rules-<hash>.php` (plain `return [...]` array, OPcache friendly). It stores the `rules.yaml` mtime and size; one `stat()` per request detects changes, including those pulled in by Git Sync. Clearing the Grav cache only forces a rebuild.

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

1. `Grav\Events\PluginsLoadedEvent` (skips the API route and the admin route): `RequestContextFactory` builds the context from the PSR request (strips base path and language prefix itself), loads compiled rules, `Matcher::match(ctx, MatchPhase::Early)`.
   - 30x: `$grav->close()` with our own PSR-7 response. No session exists yet, so the redirect carries no cookie and gets the configured `Cache-Control`. This also runs before Grav's trailing-slash redirect, so there is no double hop.
   - 410 / 451 / pass-through: remember the result and finish it in `onPagesInitialized`, where Twig and pages exist (see GRAV2-NOTES.md, section 2).
2. `onPageNotFound` (priority 10, above the error plugin): log the 404 unless ignored, then `Matcher::match(ctx, MatchPhase::NotFound)` for "only if not found" rules.
3. Hits are appended with `HitRecorder` (one line per hit); aggregation happens in the scheduler, the admin API and the CLI.

## Conventions for all code

- PHP 8.3 syntax only (no property hooks, no asymmetric visibility). `declare(strict_types=1)`, PSR-12, PHPStan level 8 clean.
- Enums for closed sets, readonly value objects, no static mutable state.
- Services get their collaborators through the constructor. Time comes from `Util\Clock`.
- File writes: `Storage\AtomicFile` (temp + rename) for documents, `fopen('a')` + `flock(LOCK_EX)` for logs.
- User-visible strings are translation keys under `PLUGIN_REDIRECT_MANAGER.*` (en + de in `languages/`).
- Tests: PHPUnit 11 attributes (`#[CoversClass]`, `#[DataProvider]`, `#[Group]`), one test class per service, temp dirs under `sys_get_temp_dir()` removed in `tearDown()`.
