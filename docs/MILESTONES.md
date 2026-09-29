# Milestones

The brief asked for six milestones (core, 404 monitor, Admin UI, import/export/CLI, API/MCP, polish) and, after each one, green tests and a short summary. The summaries were given in chat and are not in the repository. This file rebuilds them from the git history, the commit messages and the documents in `docs/`.

## How the history maps to the milestones

All 14 commits are from 2026-09-29, between 14:24 and 19:10 (commit times as recorded). The commits do not follow the milestone order one to one: the import/export commit comes before the Grav wiring and the UI, and several features arrived in the commit of a neighbouring milestone. Each section below therefore lists the commits that carry the milestone. Only two commit messages name a milestone: `a6bbead` ("Milestone 1 (core) complete") and `430fe9d` ("Milestones 2 (404 monitor), 4 (import/export/CLI) and 5 (API/MCP) complete").

| Commit | Time | What it is |
|---|---|---|
| `8b2cc2a`, `f559cb2` | 14:24 | Research notes, architecture contract, domain model |
| `157f984` | 14:47 | Matching core, suggestions, target checker, notifications |
| `1f87dd6` | 14:49 | 404 log (JSONL, SQLite), hit statistics |
| `1bbdeb6` | 15:07 | Importers and exporters |
| `9d85341` | 15:08 | Chain, loop and conflict analysis, rule validation |
| `a6bbead` | 15:13 | Wiring into Grav 2, integration test harness |
| `f74551b` | 15:36 | Admin 2 interface |
| `430fe9d` | 16:24 | REST API, CLI, MCP, automatic redirects, scheduler jobs |
| `a38d847` | 17:55 | Polish |
| `ad6b5ad` | 17:57 | Permission fix for the badge |
| `1526d23` | 18:43 | README, CHANGELOG, import guide, Playwright suite |
| `275530f` | 18:58 | Fixes for what Playwright found |
| `cc5b749` | 19:10 | Feature checklist from the independent audit |

## Test counts

The counts were not recorded when the commits were made. They were measured afterwards: each commit was exported with `git archive`, the current `vendor/` was copied in, and the unit suite was run once on PHP 8.5.11 (`phpunit --testsuite unit`). Numbers are PHPUnit tests, data-provider rows included. "Integration" is the number of tests PHPUnit lists for `tests/Integration`. Those tests were not run for this table, because each run needs a Grav test site and about 7 minutes. "Vitest" and "Playwright" are static counts of `it(`/`test(` declarations in the sources at that commit; Playwright loops multiply them (189 executed at `275530f`, per the audit in `FEATURE-CHECKLIST.md`).

| Commit | Unit tests | Result | Integration listed | Vitest | Playwright |
|---|---:|---|---:|---:|---:|
| `157f984` | 1,163 | green | 0 | 0 | 0 |
| `1f87dd6` | 1,642 | green | 0 | 0 | 0 |
| `1bbdeb6` | 2,488 | green | 0 | 0 | 0 |
| `9d85341` | 2,683 | green | 0 | 0 | 0 |
| `a6bbead` | 2,758 | green | 93 | 0 | 0 |
| `f74551b` | 2,758 | green | 93 | 203 | 0 |
| `430fe9d` | 3,541 | green on 2 of 3 runs | 391 | 203 | 0 |
| `a38d847` | 4,302 | green | 413 | 222 | 0 |
| `ad6b5ad` | 4,302 | 1 error | 413 | 222 | 0 |
| `1526d23` | 4,302 | 1 error | 413 | 222 | 162 |
| `275530f` | 4,304 | green | 414 | 224 | 168 |

Two things in the table need a comment.

- The one error at `ad6b5ad` and `1526d23` is `Api/AutoRedirectControllerTest::testPermissions`. `ad6b5ad` made clearing the shared badge require `manage`; the unit test still expected a reader to get 200. It was found by the audit (NF-1 in `FEATURE-CHECKLIST.md`) and fixed in `275530f`. So `composer test` was red for the last three feature commits.
- A run of `430fe9d` failed once with one failure, and a first run of `1526d23` showed one failure on top of the error. Neither repeated in the second and third run. Both runs overlapped with other work on the machine. Timing tests in the benchmark group are the likely cause; that was not confirmed.

The Vitest count at `275530f` (224) and the unit and integration counts (4,304 and 414) match the audit.

## Milestone 1: core

Commits: `8b2cc2a`, `157f984`, `9d85341`, `a6bbead`.

Delivered:

- Research first, as the brief asked: `docs/GRAV2-NOTES.md` with the binding answers and a runtime probe on a real Grav 2.2.2 site, before any code.
- The rule model, the matcher with its compiled structure (exact hash map, wildcard trie, regex buckets), path normalisation, the open-redirect guard and the regex backtrack guard.
- Chain, loop and conflict analysis and save-time validation (`9d85341`).
- The wiring into Grav 2 (`a6bbead`): the rule repository with locking, the compiled rule cache, the request context (base path, language prefix, proxy headers), Twig helpers, the 410 and 451 templates, and the integration harness that sends real HTTP requests to a fresh Grav 2.2.2 site.

Tests: 1,163 unit tests at the first code commit, 438 of them rows of the matcher table (the brief asks for at least 150). 2,758 unit tests and 93 integration tests at `a6bbead`.

Findings and decisions:

- The documented early hook `onRequestHandlerInit` runs after the session has started, so every redirect would carry `Set-Cookie` and `Cache-Control: no-store`. 30x redirects therefore leave from `PluginsLoadedEvent` (D-004). 410, 451 and pass-through finish in `onPagesInitialized` (D-005).
- Rules stay in YAML and are compiled into a PHP array for OPcache (D-006). Hits are append-only logs folded in later (D-007). Priority and language semantics are in D-008 and D-009.

## Milestone 2: 404 monitor

Commits: `1f87dd6`, `157f984` (suggestions), `a6bbead` (logging in `onPageNotFound`), `430fe9d` (automatic redirects, pending decisions), `f74551b` (the monitor screen).

Delivered:

- The 404 log with JSONL and SQLite stores, an atomic file helper, hit statistics with daily buckets, IP anonymisation, ignore patterns, retention and a size cap.
- The suggestion engine (same slug, similarity, other language, title, taxonomy, parent) with score and reason, and bulk accept with preview. Sitemap diff.
- Automatic redirects for slug change, move, reorganize and delete through the API plugin's page events, with delete policies (ask, 410, parent, never), and a queue for pending decisions.
- The live target check with protection against private addresses, and the signed webhooks and report mail.

Tests: 1,642 unit tests at `1f87dd6` (479 more than before, for the log, the storage helpers and the statistics). 3,541 unit and 391 integration tests at `430fe9d`, most of the increase from automatic redirects.

Findings and decisions:

- A single page move fires only `onApiPageMoved`, after the folder was renamed, so the old routes cannot be read any more. They are derived (D-015). Deleting one language of a page does nothing (D-016). The planner and the applier are separate (D-014).
- Later, in the polish commit, the page index turned out to miss pages that exist only in a non-default language (D-018), and the hit recorder lost hits under heavy aggregation because its retry limit was five (D-025).

## Milestone 3: Admin UI

Commit: `f74551b`, with fixes in `a38d847`, `1526d23` and `275530f`.

Delivered:

- The interface as Svelte 5 with Vite in `admin2/` (152 source files), built into two single-file custom elements in `admin-next/pages/` and `admin-next/widgets/` and committed. It uses only Admin 2's design tokens, so theme, accent colour and dark mode follow the host. Screens: rules table, editor slide-over, 404 monitor, suggestions, tester, import / export, settings, dashboard widget.

Tests: 203 Vitest tests at that commit. The PHP counts did not change (2,758 unit). Browser tests came only with `1526d23`; before that the UI ran against a mock API (`admin2/dev/mock-api.ts`).

Findings and decisions:

- One page route for the plugin, sub-views by hash (D-011). The bundle path is `admin-next/`, not `assets/` (D-028).
- The first UI was built against the API as designed. The polish commit says "UI follows the real API": response shapes, permission-aware controls, pending decisions on the Rules tab.
- Playwright then found seven defects (`275530f`): the widget answered 500 for users who are not super admins, focus did not return after the editor closed, the editor preview could stay on "Checking...", case-only suggestions failed with 422, the "Ignore" toast counted entries and not paths, a chain warning had a contrast of 4.25 instead of 4.5, and one help text was wrong.

## Milestone 4: import, export, CLI

Commits: `1bbdeb6` (formats), `430fe9d` (CLI).

Delivered:

- Importers and exporters for CSV, JSON, YAML, Grav `site.yaml`, `.htaccess`, nginx, Netlify, WordPress Redirection (JSON and CSV), crawler exports and Cloudflare, with duplicate detection and row errors. Unit tests grew from 1,642 to 2,488 with this commit (846 more).
- 13 CLI commands with JSON output and exit codes for CI (`430fe9d`).

Tests: `I:CliTest` has 18 tests at HEAD. The round trip per format is in the unit suite.

Findings and decisions:

- The list command is `rules`, with the alias `redirects:list` (D-026). The first explanation in the README, that Symfony reserves `list`, was wrong and is corrected there.
- `docs/IMPORT-FORMATS.md` (added in `1526d23`) records every place where a format cannot carry a rule.

## Milestone 5: API and MCP

Commit: `430fe9d`, corrected in `a38d847`.

Delivered:

- 40 REST routes at release behind the API plugin, with the permissions `api.redirects.read` and `api.redirects.manage`, an OpenAPI description in `docs/openapi.yaml`, six MCP tools, and three scheduler jobs (maintenance, link check, report mail).

Tests: 391 integration tests listed at this commit against 93 before, 413 after the polish commit. The route security test walks every route for 401 and 403.

Findings and decisions:

- The permission names are `api.redirects.*` and not `admin.redirects.*` (D-003), and OpenAPI is a shipped file (D-012). The MCP tool for the top 404 paths is `redirects_top_404` (D-013). The tools are a manifest and not a class (D-027).
- The MCP manifest and `permissions.yaml` sat in the plugin root at first. `bin/gpm direct-install` then installed the plugin as `mcp`. It showed when the release ZIP was installed with `bin/gpm`; `a38d847` moved both files to `config/` and registered the tools through `onApiMcpTools` (D-024).

## Milestone 6: polish

Commits: `a38d847`, `ad6b5ad`, `1526d23`, `275530f`, `cc5b749`.

Delivered:

- `a38d847`: `languages.yaml` with every UI, validation and import text in English and German, the page index for pages that exist only in other languages, the deny-all `.htaccess` in the data directory, the request benchmark (median 0.3 ms with 10,000 rules), the coverage gate, GitHub Actions for tests and releases, and the release ZIP build and install test.
- `ad6b5ad`: clearing the shared badge needs `manage`.
- `1526d23`: README, CHANGELOG, the import format guide and the Playwright suite (29 spec files against a fresh Grav 2.2.2 with real Admin 2, in light and dark, axe on every screen).
- `275530f`: the seven fixes named under milestone 3.
- `cc5b749`: `docs/FEATURE-CHECKLIST.md`, the point-by-point audit against the brief, with the gaps that remained. It is the source for the closing pass that produced D-026 to D-030 and this file.

Tests: 4,302 unit and 413 integration tests after the polish commit, 4,304 and 414 at `275530f`, plus 224 Vitest and 189 Playwright tests. Coverage of `classes/` was 96.76 % in the audit (gate: 90 %).

Findings and decisions:

- `HitRecorder` gave up after five attempts when an aggregation renamed its file under it, and `StatsStoreTest` lost hits in about four of five runs at 16 parallel test runs. The limits are now 1,000 attempts (D-025).
- The plugin can time itself with `X-Redirect-Manager-Time`, because a request benchmark needs a measurement inside a real request (D-020).
- The audit found what remained: the CI could not run yet because no repository existed, some conditions were only unit-tested, and several deviations from the brief were not in `DECISIONS.md`. The last group is closed by D-026 to D-030.
