# REST API

All routes live under the API plugin prefix (`/api/v1` by default) and use its authentication (API key, JWT in `X-API-Token` or `Authorization: Bearer`, or the admin session). Reads need `api.redirects.read`, writes need `api.redirects.manage`; super admins pass both. Responses use the API plugin envelope `{data, meta?, links?}`; errors are RFC 7807 (`{status, title, detail, errors?}`), validation errors are 422 with `errors: [{field, code, message, severity}]`.

Machine-readable description: `docs/openapi.yaml`. MCP tool mapping: `mcp.yaml`.

## Rule object

```json
{
  "id": "r0192f3c1a2b4f1e2d3",
  "source": "/old-page", "target": "/new-page", "match_type": "exact", "status": 301,
  "enabled": true, "priority": 0, "target_type": "route",
  "case_sensitive": false, "ignore_trailing_slash": true,
  "query_mode": "ignore", "query_params": {}, "query_ignore": [],
  "continue": false, "only_if_not_found": false,
  "active_from": null, "expires_at": null,
  "note": "", "group": "", "tags": [], "origin": "manual",
  "conditions": {"hosts": [], "languages": [], "schemes": [], "rules": []},
  "created_at": "2026-09-29T12:00:00+00:00", "updated_at": "2026-09-29T12:00:00+00:00",
  "stats": {"total": 12, "last_hit": "2026-09-29T11:59:00+00:00", "daily": {"2026-09-29": 3}},
  "badges": ["active"],
  "issues": [{"code": "chain", "severity": "warning", "message": "...", "params": {"chain": ["/a", "/b", "/c"], "shortcut": "/c"}}]
}
```

`badges` is any of `active`, `disabled`, `expired`, `scheduled`, `chain`, `loop`, `conflict`, `dead_target`, `unused`. `stats`, `badges` and `issues` are read-only.

## Rules

| Method | Path | Permission | Purpose |
|---|---|---|---|
| GET | `/redirects/rules` | read | List. Query: `q` (search source, target, note, group, tags), `match_type`, `status` (code), `state` (`active\|disabled\|expired\|scheduled`), `badge`, `group`, `origin`, `unused_days`, `sort` (`priority\|source\|target\|status\|hits\|last_hit\|created_at\|updated_at`), `dir`, `page`, `per_page` (max 500). `meta`: `total`, `page`, `per_page`, `groups`, `counts` (per badge). |
| POST | `/redirects/rules` | manage | Create. Body: rule fields. `?dry_run=1` validates only and returns `{data: {rule, issues, preview}}`. Errors (loop, invalid regex, unsafe target) give 422; warnings (chain, conflict) are returned in `issues`. |
| GET | `/redirects/rules/{id}` | read | One rule with stats, badges, issues. ETag header. |
| PATCH | `/redirects/rules/{id}` | manage | Partial update, optional `If-Match`. Same validation as create. |
| DELETE | `/redirects/rules/{id}` | manage | Delete; returns the deleted rule so the UI can offer undo. |
| POST | `/redirects/rules/restore` | manage | Body `{rules: [...]}`: re-inserts deleted rules with their ids (undo). |
| POST | `/redirects/rules/bulk` | manage | Body `{action, ids, value?}`; action `enable\|disable\|delete\|set_status\|set_group\|add_tag\|remove_tag`. Returns `{affected, rules}` (deleted rules for undo). |
| POST | `/redirects/rules/reorder` | manage | Body `{ids: [...]}` in the desired order; assigns descending priorities to exactly these rules. |
| POST | `/redirects/rules/validate` | read | Body: rule fields. Returns `{issues, preview}` without saving (live editor). `preview` = `{sample, result}` when body has `sample` (a URL). |
| POST | `/redirects/rules/{id}/shorten-chain` | manage | Sets the target to the end of its chain (one-click fix). |
| GET | `/redirects/analysis` | read | `{chains, loops, conflicts, expired, unused}` over all rules. |
| GET | `/redirects/groups` | read | `{groups: [{name, count}], tags: [{name, count}]}` |

## Tester

| POST | `/redirects/test` | read | Body `{url, method?, headers?, cookies?, language?, phase?}`. Returns `{input, context, result: MatchResult\|null, chain: [{url, status, rule_id}], final: {url, status}, trace: [TraceStep], page_exists: bool}`. No real request is made. |

## 404 monitor

| Method | Path | Permission | Purpose |
|---|---|---|---|
| GET | `/redirects/404` | read | Grouped paths. Query: `days` (7/30/90) or `from`/`to`, `bots` (0/1), `class`, `q`, `language`, `host`, `sort` (`hits\|last\|first\|path`), `dir`, `page`, `per_page`, `include_resolved`. Rows: `{path, hits, first_seen, last_seen, top_referers, daily, ua, languages, hosts, has_rule, best_suggestion}`. `meta.totals`: `{hits, paths, by_day}`. |
| GET | `/redirects/404/trend` | read | `{days: {YYYY-MM-DD: n}}` for 7/30/90 days, `bots` filter. |
| GET | `/redirects/404/entries` | read | Raw entries for one `path` (last 100). |
| POST | `/redirects/404/ignore` | manage | Body `{pattern}`: appends to `log.ignore_patterns` and removes matching entries if `purge: true`. |
| POST | `/redirects/404/resolve` | manage | Body `{paths, resolved: true\|false}`. |
| DELETE | `/redirects/404` | manage | Query `path` (one path) or body `{all: true}`. |

## Suggestions

| Method | Path | Permission | Purpose |
|---|---|---|---|
| GET | `/redirects/suggest` | read | Query `path`, `language`, `limit`: live suggestions for one path. |
| GET | `/redirects/suggestions` | read | Stored suggestions. Query `status` (`open\|accepted\|rejected`), `min_score`, `source`. |
| POST | `/redirects/suggestions/generate` | manage | Computes suggestions for all open 404 paths. Returns counts. |
| POST | `/redirects/suggestions/{id}/accept` | manage | Body optional `{target, status}`. Creates a rule (origin `suggestion`). |
| POST | `/redirects/suggestions/{id}/reject` | manage | |
| POST | `/redirects/suggestions/bulk-accept` | manage | Body `{min_score, ids?, dry_run}`. Dry run returns the preview rows. |

## Import and export

| Method | Path | Permission | Purpose |
|---|---|---|---|
| GET | `/redirects/import/formats` | read | Supported formats with import/export flags. |
| POST | `/redirects/import/preview` | manage | Body `{content, filename?, format?, options?}` (content as text, max `upload_max_mb`). Returns `ImportPreview` (rows with line, rule, errors, warnings, duplicate flags; counts). |
| POST | `/redirects/import/commit` | manage | Same body plus `{skip_duplicates, skip_invalid, lines?}`. Returns `{created, skipped, rules}`. Crawler exports create 404 candidates and suggestions instead of rules. |
| POST | `/redirects/import/sitemap` | manage | Body `{content}` (old sitemap XML, optionally base64 gzip via `encoding: base64`). Returns the diff and creates suggestions for missing paths. |
| GET | `/redirects/export` | read | Query `format`, `only_enabled`, `group`, `status`, `host`. Returns `{filename, mime, content, skipped}`. |
| GET | `/redirects/site-config` | read | Grav's own `site.redirects`, `site.routes` and `system.pages.redirect_*` settings, read-only. |
| POST | `/redirects/site-config/import` | manage | Imports `site.redirects`/`site.routes` into plugin rules (does not change `site.yaml`). |

## Checks, stats, pages

| Method | Path | Permission | Purpose |
|---|---|---|---|
| GET | `/redirects/stats` | read | Dashboard numbers: `not_found_today`, `not_found_7d`, `not_found_by_day` (30 days), `hits_today`, `hits_7d`, `hits_by_day`, `rules_total`, `rules_active`, `open_suggestions`, `dead_targets`, `pending_deletes`. |
| GET | `/redirects/checks` | read | Last live-check results `{last_run, results}`. |
| POST | `/redirects/checks/run` | manage | Runs the live check now (rate limited: 429 with `Retry-After`). Body optional `{ids}`. |
| GET | `/redirects/pages` | read | Page search for the target picker. Query `q`, `language`, `limit`. Rows `{route, title, language, translations}`. |
| GET | `/redirects/pending` | read | Deleted pages waiting for a decision (delete policy `ask`). |
| POST | `/redirects/pending/{id}/resolve` | manage | Body `{action: gone\|parent\|redirect\|dismiss, target?}`. |

## Events for other plugins

| Event | Payload | When |
|---|---|---|
| `onRedirectMatched` | `result` (MatchResult), `context` (RequestContext), `request` (RequestContextResult: base path, language prefix, method), `cancel` (false) | Before the response is sent (redirect, 410, 451, pass-through). Listeners may replace `result` or set `cancel: true`. |
| `onRedirectRuleSaved` | `rule`, `previous` (null on create), `action` (`create\|update\|delete\|import\|auto`) | After rules.yaml was written. |
| `onNotFoundLogged` | `entry` (NotFoundEntry) | After a 404 was written to the log. |
| `onSuggestionCreated` | `suggestion` (record array) | When a stored suggestion is created or improved. |
