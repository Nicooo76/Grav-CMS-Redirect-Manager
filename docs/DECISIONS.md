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

## D-013: MCP tools through `mcp.yaml`

grav-mcp reads tool manifests from enabled plugins. The plugin ships `mcp.yaml` with prefix `redirects`, which yields the tool names from the brief (`redirects_list`, `redirects_create`, `redirects_test`, `redirects_404_top`, `redirects_suggest`, `redirects_import`). Imports use a JSON body with the file content as a string, because MCP manifests do not support multipart.
