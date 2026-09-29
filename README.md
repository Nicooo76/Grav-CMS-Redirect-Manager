# Redirect Manager for Grav 2

Redirect Manager sends visitors from old URLs to new ones and tells you which old URLs still get requests. It has exact, wildcard and regex rules, a 404 monitor that suggests targets, automatic redirects when you move, rename or delete pages, import and export for the common formats, a CLI, a REST API, MCP tools and an Admin 2 interface. Rules live in one YAML file in `user/data/`, so they can go into Git.

Version 1.0.0 runs on Grav 2.1.5 or newer and PHP 8.3 or newer. It was developed and tested on Grav 2.2.2. The API plugin and Admin 2 are optional: without them the frontend redirects, the 404 log, Twig and the CLI still work.

- [Screenshots](#screenshots)
- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [First steps](#first-steps)
- [Configuration reference](#configuration-reference)
- [Rule reference](#rule-reference)
- [Import and export](#import-and-export)
- [Migrating from Grav 1.7](#migrating-from-grav-17)
- [CLI reference](#cli-reference)
- [REST API](#rest-api)
- [MCP tools](#mcp-tools)
- [Events for developers](#events-for-developers)
- [Webhooks and report mail](#webhooks-and-report-mail)
- [Twig](#twig)
- [Privacy](#privacy)
- [Performance](#performance)
- [Security](#security)
- [Known limits](#known-limits)
- [FAQ](#faq)
- [Contributing](#contributing)
- [License](#license)
- [Deutsch](#deutsch)

## Screenshots

Admin 2 shows the plugin as one page with six tabs: Rules, 404 monitor, Suggestions, Tester, Import / Export and Settings. Every screen follows the light or dark theme of Admin 2.

| Light | Dark |
|---|---|
| <img src="https://github.com/pixagentur/grav-plugin-redirect-manager/raw/main/docs/screenshots/rules-light.png" alt="Rules tab in the light theme with pending decisions, new automatic redirects and the rule table" width="440"> | <img src="https://github.com/pixagentur/grav-plugin-redirect-manager/raw/main/docs/screenshots/rules-dark.png" alt="Rules tab in the dark theme" width="440"> |

<details>
<summary>More screens: editor, 404 monitor, suggestions, tester, import, export, settings, dashboard widget</summary>

| Light | Dark |
|---|---|
| <img src="https://github.com/pixagentur/grav-plugin-redirect-manager/raw/main/docs/screenshots/editor-light.png" alt="Rule editor with a live URL test, a conflict warning and a chain warning" width="440"> | <img src="https://github.com/pixagentur/grav-plugin-redirect-manager/raw/main/docs/screenshots/editor-dark.png" alt="Rule editor in the dark theme" width="440"> |
| <img src="https://github.com/pixagentur/grav-plugin-redirect-manager/raw/main/docs/screenshots/404-light.png" alt="404 monitor with a chart of hits per day and the most requested missing paths" width="440"> | <img src="https://github.com/pixagentur/grav-plugin-redirect-manager/raw/main/docs/screenshots/404-dark.png" alt="404 monitor in the dark theme" width="440"> |
| <img src="https://github.com/pixagentur/grav-plugin-redirect-manager/raw/main/docs/screenshots/suggestions-light.png" alt="Suggestions with scores, reasons and a bulk accept slider" width="440"> | <img src="https://github.com/pixagentur/grav-plugin-redirect-manager/raw/main/docs/screenshots/suggestions-dark.png" alt="Suggestions in the dark theme" width="440"> |
| <img src="https://github.com/pixagentur/grav-plugin-redirect-manager/raw/main/docs/screenshots/tester-light.png" alt="URL tester showing a two-hop redirect chain" width="440"> | <img src="https://github.com/pixagentur/grav-plugin-redirect-manager/raw/main/docs/screenshots/tester-dark.png" alt="URL tester in the dark theme" width="440"> |
| <img src="https://github.com/pixagentur/grav-plugin-redirect-manager/raw/main/docs/screenshots/import-light.png" alt="CSV import with column mapping and a preview" width="440"> | <img src="https://github.com/pixagentur/grav-plugin-redirect-manager/raw/main/docs/screenshots/import-dark.png" alt="Import in the dark theme" width="440"> |
| <img src="https://github.com/pixagentur/grav-plugin-redirect-manager/raw/main/docs/screenshots/export-light.png" alt="Export cards for CSV, JSON, YAML, htaccess, nginx, Cloudflare, Netlify, site.yaml and WordPress" width="440"> | <img src="https://github.com/pixagentur/grav-plugin-redirect-manager/raw/main/docs/screenshots/export-dark.png" alt="Export in the dark theme" width="440"> |
| <img src="https://github.com/pixagentur/grav-plugin-redirect-manager/raw/main/docs/screenshots/settings-light.png" alt="Settings tab with the Redirects section" width="440"> | <img src="https://github.com/pixagentur/grav-plugin-redirect-manager/raw/main/docs/screenshots/settings-dark.png" alt="Settings in the dark theme" width="440"> |
| <img src="https://github.com/pixagentur/grav-plugin-redirect-manager/raw/main/docs/screenshots/dashboard-widget-light.png" alt="Admin 2 dashboard with the Redirects overview widget" width="440"> | <img src="https://github.com/pixagentur/grav-plugin-redirect-manager/raw/main/docs/screenshots/dashboard-widget-dark.png" alt="Dashboard widget in the dark theme" width="440"> |

</details>

## Features

**Matching**

- Exact, wildcard (`*` with `$1` in the target) and regex rules with named groups.
- Priority with drag and drop in Admin 2. On equal priority exact comes before wildcard, wildcard before regex, then the older rule.
- Conditions on host, language, scheme, request headers and cookies.
- Query strings can be ignored, passed on, matched exactly or matched by listed parameters.
- Case sensitivity and trailing slash per rule, start and end dates, "continue matching", "only if no page exists".
- 30x answers are sent before the session starts. They carry no cookie, can be cached, and Grav's own trailing-slash redirect does not add a second hop.

**Statuses**

- 301, 302, 307 and 308 redirects.
- 410 Gone and 451 Unavailable For Legal Reasons with page templates a theme can override.
- Status 200 as pass-through: the target page is served under the old URL, nothing is redirected.
- A `Cache-Control` value per class: permanent for 301, 308 and 410, temporary for 302, 307 and 451.

**404 monitor**

- Logs GET and HEAD requests that end in a 404, grouped by path with hits, first and last seen, referrers and a trend.
- Filters for 7, 30 and 90 days, bots, search text and paths you marked as done.
- A built-in ignore list for scanner noise, your own ignore patterns, IP anonymization, retention and a size cap.
- JSONL files by default, SQLite as an option for the 404 log (rules always stay in YAML).

**Suggestions**

- Finds existing pages that fit a missing URL: same slug, other language, similar route, title match, taxonomy match, parent or home page.
- Every suggestion has a score from 0 to 1. Accept them one by one or in bulk above a threshold, with a preview first.

**Automatic redirects**

- Creates rules when a page gets a new slug or route, moves, is reorganized or is deleted. Children get one wildcard rule or one rule each.
- For deleted pages you choose per site: ask, 410, redirect to the parent, or nothing. Pending decisions appear in Admin 2.
- Works for changes made through Admin 2, the REST API and MCP, because those go through the API plugin.

**Tester and live check**

- The tester runs a URL through the rules without a request and shows the chain, the final status, whether a page exists and why each candidate rule matched or not.
- The live check sends HEAD requests to the targets of your rules, on a schedule or on demand, and lists the dead ones.
- Rule analysis flags chains, loops, conflicts, shadowed rules, expired and scheduled rules, dead targets and rules without hits. A chain can be shortened with one click.

**Import and export**

- Import: CSV, JSON, YAML, Grav `site.yaml`, Apache `.htaccess`, nginx, Netlify `_redirects`, WordPress Redirection (JSON and CSV), crawler exports and old sitemaps.
- Export: CSV, JSON, YAML, `site.yaml`, `.htaccess`, nginx, Netlify, WordPress (JSON and CSV) and Cloudflare Bulk Redirects. An export names every rule it had to skip or simplify.

**CLI, REST API, MCP**

- `bin/plugin redirect-manager` with 13 commands, JSON output and exit codes for CI.
- 40 REST routes with an OpenAPI 3.1 description.
- Six MCP tools for AI clients.

**Events, webhooks, digest**

- Four events for other plugins: `onRedirectMatched`, `onRedirectRuleSaved`, `onNotFoundLogged`, `onSuggestionCreated`.
- Signed JSON webhooks for 404 spikes and dead targets, and a daily or weekly report mail.

**Twig**

- `redirect_for(url)` and the `redirect_target` filter.

## Requirements

| | |
|---|---|
| Grav | 2.1.5 or newer. Tested on 2.2.2. |
| PHP | 8.3 or newer, with `json` and `mbstring`. Tested on 8.3, 8.4 and 8.5. |
| API plugin | Recommended. Needed for the REST API, MCP tools, automatic redirects and Admin 2. Tested with 1.0.42. |
| Admin 2 plugin | Recommended. The interface. Tested with 2.1.25. |
| `pdo_sqlite` | Optional. Only for `log.backend: sqlite`. |
| `intl` | Optional. Normalizes request paths to Unicode form NFC. |
| Email plugin | Optional. Only for the report mail. |
| Grav scheduler | Recommended. Runs the hourly maintenance, the link check and the report mail. Set it up as described in the Grav docs. |

## Installation

Use one of three ways.

**GPM.** `bin/gpm install redirect-manager` works once the plugin is listed in the GPM repository. It is not listed yet, so use one of the next two ways until then.

**GPM direct install.** Take the ZIP from the [GitHub releases](https://github.com/pixagentur/grav-plugin-redirect-manager/releases):

```bash
bin/gpm direct-install https://github.com/pixagentur/grav-plugin-redirect-manager/releases/download/v1.0.0/grav-plugin-redirect-manager-1.0.0.zip -y
```

A downloaded ZIP works too: `bin/gpm direct-install grav-plugin-redirect-manager-1.0.0.zip -y`. The plugin lands in `user/plugins/redirect-manager`.

**Manual.** Unzip into `user/plugins/`, then clear the cache:

```bash
unzip grav-plugin-redirect-manager-1.0.0.zip -d user/plugins/
bin/grav clearcache
```

The folder must be named `redirect-manager`. The release also has a `.sha256` file for the ZIP.

**Update.** Install the new ZIP the same way. Rules, statistics and settings stay where they are. If you use MCP, restart the MCP server afterwards, see [MCP tools](#mcp-tools).

**Uninstall.**

```bash
bin/gpm uninstall redirect-manager
```

This removes the plugin folder and keeps your data: `user/data/redirect-manager/` (rules, statistics, 404 log, suggestions) and `user/config/plugins/redirect-manager.yaml`. Reinstalling picks them up again. Delete the data folder yourself if you want it gone. While the plugin is not installed, no redirect happens and the site keeps running. GPM does not clear `cache/redirect-manager/` either, nothing reads it, and you can delete it.

## First steps

1. Open Admin 2, Redirects, Settings. Set **Default status code** to 301 if you want permanent redirects by default, see the [FAQ](#why-does-a-new-rule-use-302).
2. Rules, **New redirect**: source `/old-page`, target `/new-page`. The editor tests the URL while you type.
3. Test it in the Tester tab, or from the command line:

   ```bash
   bin/plugin redirect-manager test /old-page --expect-status=301 --expect-location=/new-page
   ```

4. Let the 404 monitor collect requests for a few days. Then open Suggestions, press **Generate suggestions** and accept the good ones.
5. Make sure the Grav scheduler runs. Hourly maintenance folds hit counts into the statistics, deletes old log entries and refreshes suggestions.

Without Admin 2 the same steps work with `bin/plugin redirect-manager add /old-page /new-page --status=301` and by editing `user/data/redirect-manager/rules.yaml`.

## Configuration reference

Settings live in Admin 2 under Redirects, Settings, or in `user/config/plugins/redirect-manager.yaml`. The defaults are in the plugin's `redirect-manager.yaml`.

**General**

| Key | Default | Description |
|---|---|---|
| `enabled` | `true` | Grav's plugin switch. |
| `debug_timing` | `false` | Adds the header `X-Redirect-Manager-Time` with the microseconds the plugin spent in the early phase. For measuring, see [Performance](#performance). Not in the settings form. The environment variable `REDIRECT_MANAGER_TIMING=1` does the same. |
| `base_url` | `''` | Public root URL of the site, for runs without a request: the scheduler, the command line and link checks started from Admin 2. Empty means `system.custom_base_url`, then the URL Grav detects, then `http://localhost`. |

**Redirects**

| Key | Default | Description |
|---|---|---|
| `redirects.enabled` | `true` | Off: the plugin stops redirecting and stops logging 404 requests. Stored rules stay. |
| `redirects.default_status` | `0` | Status for new rules made by hand, imported rules without a code and accepted suggestions. Allowed: 0, 301, 302, 307, 308. `0` uses `system.pages.redirect_default_code`, which is 302 in stock Grav. Existing rules and automatic redirects keep their own code. |
| `redirects.keep_language_prefix` | `true` | If the visitor used a prefix such as `/de`, an internal target gets the same prefix. Targets that already start with a language code, targets with `{lang}` and external targets stay as they are. |
| `redirects.cache_control_permanent` | `public, max-age=3600` | `Cache-Control` for 301, 308 and 410. Browsers keep a cached permanent redirect until `max-age` runs out, even after you change or delete the rule. |
| `redirects.cache_control_temporary` | `no-store` | `Cache-Control` for 302, 307 and 451. |
| `redirects.max_chain_depth` | `10` | Most rules applied to one request with "continue matching". The rule check also reports chains and loops longer than this. Range 1 to 50. |
| `redirects.query_ignore` | `utm_*`, `fbclid`, `gclid`, `msclkid` | Parameter names skipped when a request is compared with the query string of a rule set to "Match exactly". Case does not matter, `*` matches any run of characters. Rules with another query mode ignore this list. Each rule can add its own names. |
| `redirects.excluded_paths` | `[]` | Path prefixes the plugin never handles: no redirect and no 404 log entry. `/shop` covers `/shop` and everything below it. The API route, the Admin 2 route and `/user`, `/system`, `/vendor`, `/cache`, `/logs` are always excluded. |
| `redirects.disable_expired` | `false` | The hourly job disables rules whose "expires at" date has passed. Expired rules never match anyway, so this tidies the list. |

**Security**

| Key | Default | Description |
|---|---|---|
| `security.allowed_hosts` | `[]` | Hosts a rule may redirect to when the target is an external URL. `example.org` allows that host. `*.example.org` allows subdomains but not `example.org` itself, so list both. A rule with any other external host cannot be saved. |
| `security.allow_any_external` | `false` | Ignores the list above. Anyone who can edit rules can then send visitors to any site. |
| `security.trust_proxy_headers` | `false` | Reads host and protocol from `X-Forwarded-Host` and `X-Forwarded-Proto`, and the visitor IP for the 404 log from `X-Forwarded-For`. Turn it on only behind a proxy or CDN that sets these headers, otherwise visitors can fake them. |
| `security.regex_backtrack_limit` | `100000` | Sets `pcre.backtrack_limit` while rules are matched. A rule that needs more steps does not match that request and matching goes on with the next rule. Range 1000 to 1000000. |

**Logging**

| Key | Default | Description |
|---|---|---|
| `log.enabled` | `true` | Store every GET or HEAD request that ends in a 404. Redirect hits are counted separately and do not depend on this. |
| `log.backend` | `jsonl` | `jsonl` (one file per day) or `sqlite` (needs `pdo_sqlite`, falls back to JSONL with a warning in the Grav log). |
| `log.retention_days` | `30` | Entries older than this are deleted by the hourly job. Range 1 to 365. |
| `log.max_size_mb` | `50` | JSONL only. When the day files together exceed this size, the oldest days go first. A single day stops recording at 10 MB. Range 1 to 2048. |
| `log.log_bots` | `true` | Off: requests from crawlers, recognized by user agent, are not stored. On: stored and marked as bots. Suggestions, the webhook and the report mail leave bots out either way. |
| `log.ip_mode` | `anonymize` | `anonymize` or `none`, see [Privacy](#privacy). |
| `log.use_default_ignores` | `true` | Built-in ignore list: WordPress and phpMyAdmin probes, `*.php`, `*.asp` and similar, `.env` and `.git` files, `/favicon.ico` and more. Turn it off if your site has real `.php` URLs to migrate. |
| `log.ignore_patterns` | `[]` | Paths that are never logged. `*` matches any characters including `/`, `?` matches one character, case does not matter, the pattern must fit the whole path. A pattern with `?` is also tried against path and query, for example `/search?q=*`. |

**Automation**

| Key | Default | Description |
|---|---|---|
| `auto_redirect.enabled` | `true` | Create rules when pages move, get a new slug or route, or are deleted. |
| `auto_redirect.status` | `301` | Code for these rules. 301, 302, 307 or 308, anything else becomes 301. |
| `auto_redirect.children` | `wildcard` | `wildcard`: one rule `/old/*` to `/new/$1` for all child pages. `each`: one rule per child page. |
| `auto_redirect.on_delete` | `ask` | `ask`: the decision waits in Admin 2 and the URL answers 404 meanwhile. `gone`: 410. `parent`: redirect to the nearest existing parent. `never`: nothing. |
| `suggestions.min_score` | `0.5` | A 404 path gets a suggestion only if the best page scores at least this much. Fallbacks to a parent or the home page are exempt. Range 0 to 1. |
| `suggestions.bulk_accept_score` | `0.9` | Where the slider on the Suggestions tab starts. The API and the CLI use it when a bulk accept names no score. Range 0.5 to 1. |

**Link checker**

| Key | Default | Description |
|---|---|---|
| `checker.enabled` | `true` | Run the scheduled link check. Off: manual checks still work. |
| `checker.schedule` | `30 3 * * 0` | Cron expression, Sundays at 03:30. An invalid expression falls back to the default. |
| `checker.timeout` | `5` | Seconds per request. A target that does not answer in time counts as dead. Range 1 to 30. |
| `checker.max_per_run` | `500` | Most distinct target URLs per run. The rest is shown as rate limited, never as dead. Range 1 to 10000. |
| `checker.check_external` | `true` | Off: targets on other hosts are not requested and show as skipped. |
| `checker.manual_interval` | `300` | Minimum seconds between two checks started in Admin 2 or through the API. Minimum 30. The CLI and the scheduled run ignore it. |

**Notifications**

| Key | Default | Description |
|---|---|---|
| `notifications.webhook_url` | `''` | Receives a JSON POST for 404 spikes and newly dead targets. https only, http works for localhost. Redirects are not followed. Empty: no webhooks. |
| `notifications.webhook_secret` | `''` | Signs each request, see [Webhooks](#webhooks-and-report-mail). The environment variable `REDIRECT_MANAGER_WEBHOOK_SECRET` takes precedence and keeps the secret out of the config file. |
| `notifications.not_found_threshold` | `25` | A 404 path triggers the webhook at this many non-bot hits in 24 hours. Minimum 1. |
| `notifications.dead_targets` | `true` | Send the `dead_target` webhook. The report mail lists dead targets either way. |
| `notifications.email_digest` | `none` | `none`, `daily` or `weekly`. Needs the Email plugin with a working mailer. |
| `notifications.email_to` | `''` | Recipient of the report mail. Without an address nothing is sent. |

**Statistics and import**

| Key | Default | Description |
|---|---|---|
| `stats.keep_days` | `90` | Daily hit counts older than this are dropped. The total per rule and its last hit date stay. Range 7 to 730. |
| `stats.unused_days` | `180` | Active rules without a hit for this many days get the "Unused" badge. Range 1 to 3650. |
| `import.max_mb` | `10` | Largest import in MB, measured on the file content. Larger imports are rejected, through the API with HTTP 413. Range 1 to 100. |

## Rule reference

A rule is one entry in `user/data/redirect-manager/rules.yaml`. The same field names appear in JSON and YAML exports and in the REST API.

| Field | Values | Default | Meaning |
|---|---|---|---|
| `id` | text | generated | Unique id, for example `r0192f3c1a2b4f1e2d3`. |
| `source` | text | required | Exact: a path, optionally with `?query`. Wildcard: a path with `*`. Regex: a PCRE pattern without delimiters. |
| `target` | text | empty | Path or absolute URL. Empty for 410 and 451. |
| `match_type` | `exact`, `wildcard`, `regex` | `exact` | How `source` is read. |
| `status` | 200, 301, 302, 307, 308, 410, 451 | the default status setting | What the visitor gets. |
| `enabled` | true, false | `true` | Disabled rules never match. |
| `priority` | integer | `0` | Higher numbers are evaluated first. |
| `target_type` | `route`, `url`, `page` | `route` | `route`: a path on this site. `url`: an absolute URL, checked against the allowed hosts. `page`: a Grav page route, the target follows the page when it moves. |
| `case_sensitive` | true, false | `false` | Off: paths are compared without regard to case, and regex rules get the `i` flag. Captures keep the request's case. |
| `ignore_trailing_slash` | true, false | `true` | `/a` and `/a/` count as the same path. |
| `query_mode` | `ignore`, `pass`, `exact`, `params` | `ignore` | See below. |
| `query_params` | map of name to value or null | `{}` | For `params`: the request must have these parameters. A null value means any value. |
| `query_ignore` | list of names | `[]` | Extra names skipped in `exact` mode, on top of `redirects.query_ignore`. |
| `continue` | true, false | `false` | Keep matching after this rule. See below. |
| `only_if_not_found` | true, false | `false` | Apply only when Grav has no page at the source URL. |
| `active_from`, `expires_at` | ISO 8601 date and time, or null | null | The rule matches only inside this window. |
| `note`, `group`, `tags` | text, text, list | empty | For you. Filters and exports use them. |
| `origin` | `manual`, `import`, `auto`, `suggestion` | `manual` | Where the rule came from. |
| `conditions` | object | empty | `hosts`, `languages`, `schemes` and `rules` (header and cookie conditions). |

The API adds three read-only fields: `stats` (hits), `badges` and `issues`.

### Match types

- **Exact.** The path must be equal after normalization. `source: /old-page`.
- **Wildcard.** `*` matches any run of characters, including `/`. Each `*` is a capture: `/blog/*` with target `/news/$1`. A wildcard source must match the whole path.
- **Regex.** PCRE, matched against the normalized path without the query string. The pattern is used as written, so add `^` and `$` yourself. Named groups become `{name}`, numbered groups `$1`. Patterns run with the `u` flag, and with `i` unless the rule is case sensitive. The maximum length is 1000 characters.

Paths are compared after normalization: percent-decoded once, Unicode NFC, no dot segments, no duplicate slashes. Sources never contain the Grav language prefix, use a language condition.

### Placeholders in targets

`$1` to `$9`, `${10}` and up, `{name}` for named groups, and `{lang}` for the request language (or the default language).

- Captured text is percent-encoded when it is put into the target, so a capture cannot add `?`, `#` or `&`.
- An empty `{lang}` drops its path segment: `/{lang}/about` becomes `/about`.
- Duplicate slashes inside an internal target collapse. A leading `//` or `/\` does not, and the rule is treated as not matching.
- Unknown `{x}` stays literal. Named groups win over `{lang}`.
- A placeholder that the source does not define is a validation error.

### Query modes

| Mode | Matching | Target |
|---|---|---|
| `ignore` | The query string plays no role. | The query string is dropped. |
| `pass` | The query string plays no role. | The request's query string is appended. |
| `exact` | The request's query must equal the query in the source, after removing the ignored names. Parameter order does not matter. | Dropped. |
| `params` | The listed parameters must be present, with their value if one is given. Other parameters are ignored. | Dropped. |

### Conditions

All groups must hold, values inside a group are alternatives.

- `hosts`: request hosts, case insensitive. `*.example.com` matches subdomains only, not the apex. List `example.com` as well to cover both.
- `languages`: Grav language codes, for example `de`.
- `schemes`: `http` or `https`.
- `rules`: header or cookie conditions with a name, an operator (`exists`, `equals`, `contains`, `starts_with`, `regex`), a value and `negate`. `equals`, `contains` and `starts_with` ignore case, `regex` is case sensitive, so use `(?i)`. Header names ignore case, cookie names do not.

### Priority and order

Rules are ranked by `priority`, highest first. On equal priority: exact, then wildcard, then regex; then `created_at`, oldest first; then `id`. The first rule that passes every check wins. Admin 2 writes new priorities for the moved block only when you drag rows.

**Continue.** With `continue: true` matching goes on with the rewritten path against the rules ranked after this one. The last matching rule decides the status. The depth is capped by `redirects.max_chain_depth`.

**Only if no page exists.** Rules without this flag run first, before Grav looks for a page, so they override an existing page at the same URL. A rule with `only_if_not_found: true` runs later, after Grav found no page, so it never hides content.

**Pass-through (200).** Grav serves the target page under the requested URL. The target must be a routable page of this site. If it is not, the plugin logs a warning to `grav.log`, records no hit and lets the normal 404 happen.

**Language prefix.** If the request carried a prefix and `redirects.keep_language_prefix` is on, an internal target gets the same prefix, so one rule covers all languages.

## Import and export

Admin 2: Redirects, Import / Export. CLI: `import` and `export`. REST: `/redirects/import/*` and `/redirects/export`. The full guide with every deviation is in [docs/IMPORT-FORMATS.md](https://github.com/pixagentur/grav-plugin-redirect-manager/blob/main/docs/IMPORT-FORMATS.md).

An import shows a preview first. It validates every row like a manual rule, skips duplicates by default and refuses the whole file if one row is invalid, unless you choose to skip invalid rows.

| Format | Import | Export | What to know |
|---|---|---|---|
| CSV | yes | yes | Delimiter and header detected, English or German header names, a column mapper. Export loses conditions, the start date, "continue" and per-rule ignored parameters. |
| JSON, YAML | yes | yes | The plugin's own structure with every field. Use them for backups and for moving rules between sites. |
| Grav `site.yaml` | yes | yes | `redirects` become exact, wildcard or regex rules. `routes` become status 200 rules. Export skips rules with conditions, 308, 410, 451 and exact or parameter query modes. |
| Apache `.htaccess` | yes | yes | `Redirect`, `RedirectMatch`, `RewriteRule` with `RewriteCond`. `Redirect /a /b` becomes an exact rule where Apache matches a prefix. Rewrites without an `R` flag are skipped. |
| nginx | yes | yes | `location` with `return` or `rewrite`, `if` on host, scheme, header, cookie or query. Other directives are ignored. |
| Netlify `_redirects` | yes | yes | Placeholders and splats work. Country, Language and Role conditions are ignored. |
| WordPress Redirection | yes | yes | JSON and CSV. Match types login, role, ip, page, language, custom and the random action are skipped. |
| Crawler CSV | yes | no | Screaming Frog and Sitebulb. Creates suggestions for the 404 and 410 URLs, no rules. |
| Cloudflare Bulk Redirects | no | yes | Needs a host per source. Only 301, 302, 307, 308, no regex. |
| Old sitemap | yes | no | Lists URLs with no page and no rule and stores a suggestion for each. |

Imports are limited by `import.max_mb` and 50,000 rows. Exports return two lists: `skipped` for rules the format cannot express at all and `lossy` for rules that lost a setting. The CLI prints both to stderr.

## Migrating from Grav 1.7

A relaunch has four sources of old URLs. Use all four.

1. **`site.yaml`.** Grav 1.7 sites keep redirects in `user/config/site.yaml` under `redirects` and `routes`. Grav 2 still applies them, so nothing breaks. To manage them in one place, open Import / Export and use "Grav site configuration", or copy the file to the new site and import it with the format `grav_site`. Your `site.yaml` is not changed. Grav's `redirect_default_code` is 302 in stock Grav, so `redirects` entries without a `[301]` suffix are imported as 302, which is also what Grav does with them. Check them.
2. **Server redirects.** Import the old `.htaccess` or nginx config. The importer reads only redirect directives, warns about everything it changes and skips rewrites that are not redirects.
3. **The old sitemap.** Upload the old `sitemap.xml` in Import / Export, "Compare with an old sitemap". You get the URLs that have neither a page nor a rule, and each one becomes a suggestion. Do this on the new site before launch.
4. **Crawler exports.** Crawl the old URL list against the new site with Screaming Frog or Sitebulb and import the "Response Codes" export as `crawler_csv`. The 404 and 410 rows become suggestions.

Then:

1. Open Suggestions. Sort by score. Use bulk accept for suggestions from 0.9 up and read the preview. Handle the rest by hand, or set 410 for content that is gone for good.
2. Import your own mapping list as CSV with the columns `source, target, status`. A `group` column such as "relaunch 2026" makes the rules easy to filter, export and remove later.
3. Run every important URL through `bin/plugin redirect-manager test <url> --expect-status=301 --expect-location=<target>`. See the [CI example](#ci-example).
4. Go live. Look at the 404 monitor daily for the first weeks, generate suggestions again and accept the good ones.
5. A permanent redirect is cached for `redirects.cache_control_permanent` (one hour by default). Keep it short until you are sure about the rules.

## CLI reference

Run commands as `bin/plugin redirect-manager <command>`. Every command also has the alias `redirects:<command>`, for example `redirects:add`. The command that lists rules is named `rules`, with the alias `redirects:list`. A command called `list` would take the place of Symfony's built-in `list`, the command overview of `bin/plugin redirect-manager` ([why](https://github.com/pixagentur/grav-plugin-redirect-manager/blob/main/docs/DECISIONS.md#d-026-the-list-command-is-rules-with-the-alias-redirectslist)). `--help` prints the options of a command.

Options that come from the command line are validated. A bad value gives exit code 2 and a message on stderr, never a PHP error. With `--json` the only thing on stdout is one JSON document.

| Command | Purpose | Options |
|---|---|---|
| `add <source> <target>` | Validate and store one rule. Prints the id and warnings. | `--status` (default from settings), `--type` exact, wildcard or regex (default exact), `--group`, `--note`, `--priority`, `--dry-run`, `--json` |
| `rules` | List rules. | `--q`, `--match-type`, `--status`, `--state` active, disabled, expired or scheduled, `--badge`, `--group`, `--origin`, `--unused-days`, `--sort`, `--dir`, `--limit` (1 to 500, default 50), `--page`, `--json` |
| `enable <id...>` | Enable rules. A rule that would be invalid is skipped and reported. | none |
| `disable <id...>` | Disable rules. | none |
| `remove <id...>` | Delete rules. Unknown ids do not stop the others. | none |
| `test <url>` | Show what happens to a URL, or assert it. No request is made. | `--expect-status`, `--expect-location`, `--method` (default GET), `--language`, `--phase` early, not_found or any (default any), `--json` |
| `stats` | Dashboard numbers: 404s, redirect hits, rules, suggestions, dead targets, pending deletes. | `--json` |
| `import <file>` | Import a file. | `--format`, `--dry-run`, `--skip-duplicates` and `--no-skip-duplicates` (default skip), `--skip-invalid` (default off), `--json` |
| `export` | Write rules to stdout or a file. | `--format` (default csv), `--output`, `-o`, `--only-enabled`, `--group`, `--json` |
| `suggest` | Generate suggestions for open 404 paths and accept the confident ones. | `--days` (1 to 366, default 30), `--accept-min` (default `suggestions.bulk_accept_score`), `--no-accept`, `--dry-run`, `--json` |
| `check-targets` | Send a live request to every enabled rule's target and list the dead ones. No rate limit. | `--base-url` (default `base_url`, then the site URL), `--json` |
| `prune` | Delete or disable rules without a hit for a long time. | `--unused-days` (default 180), `--dry-run`, `--disable-only`, `--json` |
| `rebuild-cache` | Drop and rebuild the compiled rule cache. | none |

Formats for `--format`: `csv`, `json`, `yaml`, `grav_site`, `htaccess`, `nginx`, `wordpress_json`, `wordpress_csv`, `netlify`; import also `crawler_csv`, export also `cloudflare_csv`. Without `--format`, `import` detects the format.

**Exit codes**

| Code | Meaning | Used by |
|---|---|---|
| 0 | Success | all |
| 1 | Runtime error: an unexpected exception, a file that cannot be written, an unavailable service | all |
| 2 | Invalid input: a bad option value, a rule that fails validation, a missing or invalid import file, a rule skipped by `enable` or `disable` because it would be invalid | all |
| 3 | An expectation did not match, or dead targets were found | `test` with `--expect-*`, `check-targets` |
| 4 | A rule id does not exist | `enable`, `disable`, `remove` |

`import --dry-run` returns 2 when the file has errors or invalid rows. `remove` with an unknown id returns 4 after deleting the ids that exist.

### CI example

Keep your critical redirects in a script and run it against the deployed or staging site after each deploy. The commands read `user/data/redirect-manager/rules.yaml` of the site they run in and make no HTTP request, except `check-targets`.

```bash
#!/usr/bin/env bash
set -euo pipefail
cd /var/www/site

plugin="bin/plugin redirect-manager"

# The rule file itself is valid
$plugin import redirects.csv --dry-run

# The redirects that must not break
$plugin test /old-page   --expect-status=301 --expect-location=/new-page
$plugin test /blog/hello --expect-status=301 --expect-location=/news/hello
$plugin test /retired    --expect-status=410

# No rule points at a dead target (exit code 3 if one does)
$plugin check-targets --base-url=https://staging.example.org
```

Any non-zero exit code fails the job. Use `--json` when you want to parse the result.

## REST API

The plugin registers 40 routes below the API plugin's prefix, `/api/v1` by default. They use the API plugin's authentication: an API key in `X-API-Key`, a JWT in `X-API-Token` or `Authorization: Bearer`, or the Admin 2 session.

Two permissions guard them. They appear in the Admin 2 user editor under "Redirects".

- `api.redirects.read`: view rules, the 404 log, suggestions, statistics, test URLs, read exports.
- `api.redirects.manage`: create, change, delete and import rules, handle 404 entries and suggestions, run link checks, decide on deleted pages.

`admin.super` and `api.super` pass every check. Read routes that only compute (`/redirects/rules/validate`, `/redirects/test`) need read.

| Area | Routes | Needs |
|---|---|---|
| Rules | `GET/POST /redirects/rules`, `GET/PATCH/DELETE /redirects/rules/{id}`, `POST .../restore`, `.../bulk`, `.../reorder`, `.../validate`, `.../{id}/shorten-chain`, `GET /redirects/analysis`, `GET /redirects/groups` | read for GET and validate, manage for the rest |
| Tester | `POST /redirects/test` | read |
| 404 monitor | `GET /redirects/404`, `.../trend`, `.../entries`; `POST .../ignore`, `.../resolve`; `DELETE /redirects/404` | read for GET, manage for the rest |
| Suggestions | `GET /redirects/suggest`, `GET /redirects/suggestions`; `POST .../generate`, `.../bulk-accept`, `.../{id}/accept`, `.../{id}/reject` | read for GET, manage for POST |
| Import, export | `GET /redirects/import/formats`, `GET /redirects/export`, `GET /redirects/site-config`; `POST .../import/preview`, `.../import/commit`, `.../import/sitemap`, `.../site-config/import` | read for GET, manage for POST |
| Checks and stats | `GET /redirects/stats`, `GET /redirects/checks`, `GET /redirects/pages`; `POST /redirects/checks/run` | read for GET, manage for the run |
| Auto redirects | `GET /redirects/pending`, `GET /redirects/badge`; `POST /redirects/pending/{id}/resolve`, `POST /redirects/badge/seen` | read for GET, manage for POST |

A rule created through the API:

```bash
curl -s -X POST https://example.org/api/v1/redirects/rules \
  -H "X-API-Key: $GRAV_API_KEY" -H "Content-Type: application/json" \
  -d '{"source": "/old-page", "target": "/new-page", "status": 301}'
```

Add `?dry_run=1` to validate without saving. Errors follow RFC 7807. Validation errors are 422 with a list of field errors. Chains and conflicts come back as warnings in the rule's `issues`. Other codes: 401, 403, 404, 409 (a changed or already decided record, `If-Match` mismatch), 413 (import too large), 429 (link checks too close together, with `Retry-After`).

Every route with its parameters and responses is in [docs/API.md](https://github.com/pixagentur/grav-plugin-redirect-manager/blob/main/docs/API.md) and [docs/openapi.yaml](docs/openapi.yaml). The OpenAPI file is part of the release ZIP.

## MCP tools

The plugin gives AI clients six tools through [grav-mcp](https://learn.getgrav.org/2/advanced/mcp-server). The API plugin publishes them at `GET /api/v1/mcp/tools`. Tool names are the prefix `redirects` plus the tool name.

| Tool | Does | Needs |
|---|---|---|
| `redirects_list` | Lists rules with search, filters and paging, including hit statistics, badges and analysis issues. | read |
| `redirects_create` | Creates a rule. Validates it and rejects loops, unsafe targets and invalid regexes. `dry_run` validates without saving. | manage |
| `redirects_test` | Shows what the rules do with a URL: the matching rule, status, location, the whole chain, the final status and whether a page exists. | read |
| `redirects_top_404` | Reports the most requested missing URLs with hits, referrers, whether a rule covers them and the best suggestion. | read |
| `redirects_suggest` | Finds existing pages that fit a missing path, with score and reason. | read |
| `redirects_import` | Imports the text of a file in any import format. Duplicates are skipped by default. | manage |

An agent can read rules, find missing URLs, test, create and import. It cannot edit or delete a rule, accept suggestions or run link checks. Those stay in Admin 2, the CLI and the REST API. The permission of each tool is checked twice, by grav-mcp and by the route.

**Restart the MCP server after installing or updating the plugin.** grav-mcp reads the tool list once at startup. The `GET /mcp/tools` response carries an `ETag` built from the enabled plugins and the modification times of root `mcp.yaml` files. This plugin's manifest is `config/mcp.yaml` and is registered through an event, so a changed tool does not change the `ETag`. A client that sends `If-None-Match` can keep an old list until the set of enabled plugins changes. The environment variable `GRAV_MCP_PLUGIN_TOOLS` of grav-mcp (`all`, `none` or a list of plugin slugs) decides which plugins contribute tools.

The manifest is in `config/mcp.yaml`, not in the plugin root, because a second `*.yaml` file there makes `bin/gpm direct-install` install the plugin under the wrong name.

## Events for developers

Other plugins can listen to four events. The API plugin does not need to be installed. All listeners are registered before the plugin's own early handler runs, so a listener in a normal plugin works for every event.

| Event | Payload | When |
|---|---|---|
| `onRedirectMatched` | `result` (`MatchResult`: `rule`, `status`, `location`, `rules`, `captures`, `trace`), `context` (`RequestContext`: `path`, `query`, `host`, `scheme`, `language`, `headers`, `cookies`), `request` (`RequestContextResult`: `basePath`, `languagePrefix`, `rawPath`, `rawQuery`, `method`), `cancel` (false) | Before a redirect, a 410, a 451 or a pass-through is sent. A listener can replace `result` or set `cancel` to `true`, which means "no rule matched". |
| `onRedirectRuleSaved` | `rule`, `previous` (null on create), `action` (`create`, `update`, `delete`, `import`, `auto`) | After `rules.yaml` was written, from Admin 2, the REST API, the CLI, imports and automatic redirects. |
| `onNotFoundLogged` | `entry` (`NotFoundEntry`: `time`, `path`, `query`, `referer`, `userAgent`, `uaClass`, `ip`, `language`, `host`, `method`) | After a 404 was written to the log. |
| `onSuggestionCreated` | `suggestion` (array: `id`, `path`, `target`, `score`, `reason` and more) | When a stored suggestion is created or improved. |

`onRedirectMatched` runs in the earliest phase of a request, before Grav's session and page handling. Use only the data in the payload, and keep the listener fast: it runs on every redirect.

```php
<?php

namespace Grav\Plugin;

use Grav\Common\Plugin;
use RocketTheme\Toolbox\Event\Event;

class PreviewBypassPlugin extends Plugin
{
    public static function getSubscribedEvents(): array
    {
        return [
            'onRedirectMatched' => ['onRedirectMatched', 0],
            'onRedirectRuleSaved' => ['onRedirectRuleSaved', 0],
        ];
    }

    public function onRedirectMatched(Event $event): void
    {
        // Editors with ?preview=1 see the old URL, not the redirect
        if (isset($event['context']->query['preview'])) {
            $event['cancel'] = true;
        }
    }

    public function onRedirectRuleSaved(Event $event): void
    {
        $rule = $event['rule'];        // Grav\Plugin\RedirectManager\Domain\Rule
        $action = $event['action'];    // create, update, delete, import or auto
        // For example: purge a CDN path when $rule->source changes
    }
}
```

## Webhooks and report mail

Set `notifications.webhook_url` to get a JSON `POST` in two cases. Changes to rules send nothing.

- `not_found_threshold`: a 404 path reached `notifications.not_found_threshold` hits from non-bot visitors within 24 hours. The hourly job checks. Each path is reported once, then again after 7 days if it is still above the threshold.
- `dead_target`: a scheduled link check found a target newly dead. Each target is reported once, and again if it recovers and dies again.

Each run sends at most 20 webhooks. A timeout or a 5xx answer is retried once, other failures are final. The timeout is 5 seconds. The URL must use https, or http for `localhost`. Redirects are not followed. The URL must not contain credentials.

```json
{
  "event": "not_found_threshold",
  "sent_at": "2026-09-29T10:05:03+00:00",
  "site": "Example site",
  "data": {
    "path": "/produkte/zelt-alpin",
    "hits": 31,
    "window": "24h",
    "first_seen": "2026-09-29T02:14:55+00:00",
    "top_referer": "https://www.google.com/"
  }
}
```

`site` is the site title. The data of `dead_target` is `rule_id`, `source`, `target`, `status` (or null) and `error` (or null). Headers:

| Header | Value |
|---|---|
| `X-Redirect-Manager-Event` | `not_found_threshold` or `dead_target` |
| `X-Redirect-Manager-Timestamp` | Unix time in seconds |
| `X-Redirect-Manager-Signature` | `sha256=` and the hex HMAC-SHA256 of the timestamp, a dot and the raw body, keyed with the secret. Sent only if a secret is set. |

Verify the signature over the raw body, compare in constant time and reject old timestamps:

```php
<?php

$secret    = getenv('REDIRECT_MANAGER_WEBHOOK_SECRET');
$body      = file_get_contents('php://input');
$timestamp = $_SERVER['HTTP_X_REDIRECT_MANAGER_TIMESTAMP'] ?? '';
$signature = $_SERVER['HTTP_X_REDIRECT_MANAGER_SIGNATURE'] ?? '';

$expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
$fresh    = ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= 300;

if (!$fresh || !hash_equals($expected, $signature)) {
    http_response_code(401);
    exit;
}

$payload = json_decode($body, true);
// $payload['event'], $payload['data']
```

**Report mail.** `notifications.email_digest` set to `daily` or `weekly` sends a summary at 07:00, weekly on Mondays. It has the top 404 paths and new 404 paths (bots left out), redirect hits, the most used rules, open suggestions and dead targets. It goes out through Grav's Email plugin to `notifications.email_to`. The text exists in English and German and follows the site's default language. Links in the mail use `base_url`.

## Twig

Both are read-only. They record no hit, send nothing and consider only rules without "only if no page exists".

```twig
{# {status, location, rule_id} or null #}
{% set r = redirect_for('/old-page') %}
{% if r %}
    {{ r.status }} to {{ r.location }} by rule {{ r.rule_id }}
{% endif %}

{# The location, or the URL itself when no rule applies #}
<a href="{{ '/old-page'|redirect_target }}">Products</a>
```

Use `redirect_target` to fix internal links that still point at old URLs. Both are on the Twig sandbox allow-list.

**Overriding the 410 and 451 pages.** The plugin renders `redirect-manager/gone.html.twig` and `redirect-manager/unavailable.html.twig`. Put your own files at the same paths in your theme and they win:

```
user/themes/mytheme/templates/redirect-manager/gone.html.twig
user/themes/mytheme/templates/redirect-manager/unavailable.html.twig
```

The templates get `redirect_status` (410 or 451) and `redirect_path`. The default pages use the language strings `PLUGIN_REDIRECT_MANAGER.FRONTEND.GONE_TITLE`, `GONE_TEXT`, `UNAVAILABLE_TITLE`, `UNAVAILABLE_TEXT` and `HOME_LINK`, which you can override in `user/languages/`. The responses carry no session cookie.

## Privacy

Redirect Manager keeps its data on your server, in `user/data/redirect-manager/`. It sets no cookie, not even on a redirect, and sends nothing to a third party unless you set a webhook URL, a report mail recipient or external link checks.

**What is stored**

| File or folder | Content | Personal data |
|---|---|---|
| `rules.yaml` | Your rules. | Only what you type into notes. |
| `hits/`, `stats.json` | Per rule: the rule id per hit, then totals and daily counts. | None. No IP, no user agent. |
| `404/` or `404.sqlite` | One entry per logged 404 request: time, path, query string, referrer, user agent, browser or bot class, IP address as set below, language, host, method. | IP address, user agent and whatever visitors put in URLs. |
| `suggestions.json`, `target-checks.json`, `auto-state.json`, `notify-state.json`, `404-state.json` | Suggestions, the last link check results, deleted pages waiting for a decision, what was already reported by webhook, paths you marked as done. | None beyond paths. |

**What the 404 log leaves out or shortens**

- **IP address.** `log.ip_mode: anonymize` (the default) sets the last IPv4 octet to 0 (`1.2.3.4` becomes `1.2.3.0`) and keeps the first 48 bits of an IPv6 address. The full address is never stored. `none` stores no IP at all. Behind a proxy the log shows the visitor's address only with `security.trust_proxy_headers` on, otherwise it shows the proxy.
- **Referrer.** Only scheme, host and path. User info, query string and fragment are dropped.
- **Query string.** Values of parameters with sensitive names are replaced with `***`: names that are or end in `email`, `mail`, `token`, `password`, `passwd`, `pwd`, `pass`, `secret`, `key`, `apikey`, `session`, `sessionid`, `sid`, `phpsessid`, `auth`, `otp`, `sig`, `signature`, `credential`, `jwt`, `bearer` or `cookie`. Any value that looks like an email address is replaced too, whatever the name.
- **Free text.** Control characters, escape sequences and invisible characters are removed, and every field has a length limit.
- **Methods.** Only GET and HEAD.
- **Bots.** `log.log_bots: false` stores no bot request at all. The user agent is stored as sent, shortened.
- **Noise.** Scanner probes such as `/wp-login.php` or `/.env` are not logged, see `log.use_default_ignores`.

**Retention.** The hourly maintenance job deletes 404 entries older than `log.retention_days` (30 by default, up to 365) and enforces `log.max_size_mb`. It drops daily hit counts after `stats.keep_days` (90 by default). Without a running Grav scheduler none of this happens. With `log.enabled: false` no 404 is stored. Hit counting continues.

**Where the data lives.** In `user/data/redirect-manager/` of the site. The folder gets an `.htaccess` that denies web access and an empty `index.html`. nginx ignores `.htaccess`, so on nginx keep Grav's own rule that blocks `user/data/`.

**Data that leaves the server.** Only on your setting: a webhook carries a 404 path, its hit count, its most frequent referrer and a rule's source and target. The report mail carries top 404 paths, counts and rule sources and targets. The link check requests your rule targets, including external hosts, with the user agent `GravRedirectManager/1.0 (+link check)`. Turn it off with `checker.check_external: false` if targets on other hosts should not be contacted.

**Deleting data.** Uninstalling keeps the data folder. Delete `user/data/redirect-manager/` yourself to remove rules, statistics and logs. Admin 2 deletes the log entries of one path at a time (row menu in the 404 monitor). To clear the whole log, call `DELETE /redirects/404?all=1` or delete the `404/` folder or `404.sqlite`.

**Git Sync.** `rules.yaml` belongs in Git, logs do not. A suggested `.gitignore` for `user/data/redirect-manager/`:

```
hits/
404/
404.sqlite
```

The other JSON files (`stats.json`, `suggestions.json`, `target-checks.json`, `auto-state.json`, `notify-state.json`, `404-state.json`) change whenever the plugin runs. Ignore them too if you do not want those commits.

This section describes what the plugin does. Whether that is enough for your legal duties is your decision, and it is not legal advice.

## Performance

On every frontend request the plugin does one `stat()` of `rules.yaml`, one `include` of the compiled rule set and a match. The compiled set lives in `cache/redirect-manager/rules-<hash>.php`, a plain PHP array that OPcache keeps. The first request after a change to `rules.yaml` compiles it, and later requests only include it.

The numbers below are from `docs/PERFORMANCE.md`: 10,000 rules (8,500 exact, 1,000 wildcard, 500 regex), Grav 2.2.2 with the page cache on, PHP 8.4.26 with OPcache, `php -S` on an Apple M3 Max, 2,000 requests over one connection. **The machine was under heavy load from other jobs during the run, with a load average above 70. Read the numbers as an upper bound.**

Time the plugin spent per request, from the `X-Redirect-Manager-Time` header (request context, compiled rule cache, match), in microseconds:

| Request kind | Requests | Median | p95 | Max |
|---|---:|---:|---:|---:|
| all | 2000 | 316.2 | 414.4 | 1812.9 |
| exact hit | 902 | 328.9 | 419.9 | 1812.9 |
| regex hit | 185 | 332.4 | 445.6 | 852.1 |
| wildcard hit | 303 | 352.3 | 443.9 | 763.6 |
| 404, no rule | 365 | 249.6 | 341 | 1319.5 |
| real page, no rule | 245 | 235 | 299.7 | 703.5 |

Total response time as measured by curl, median / p95 in milliseconds:

| Request kind | Plugin disabled | Plugin enabled |
|---|---:|---:|
| all | 4.6 / 5.68 | 1.8 / 6.14 |
| exact hit | 4.62 / 5.8 | 1.66 / 2.21 |
| 404, no rule | 4.58 / 5.81 | 5.66 / 7.56 |
| real page, no rule | 4.51 / 5.58 | 5.02 / 6.14 |

With the plugin disabled the redirect URLs are plain 404s, so only the last two rows compare one to one. The plugin's median time per PHP version was 304.0 µs on 8.3, 316.2 µs on 8.4 and 442.2 µs on 8.5, the last one measured while the machine was busier.

What follows from it, according to `docs/PERFORMANCE.md`:

- The plugin's own work is about 0.3 ms at the median and below 0.5 ms at p95 with 10,000 rules. Exact, wildcard and regex hits cost the same within noise. Exact rules are found by one hash lookup, wildcard rules through a trie over the path segments before the first `*`, regex rules through their literal first path segment. A regex that does not start with a literal segment, such as `^/(\d+)/x`, is tried on every request, so keep those few.
- A redirect is answered in about 1.7 ms in total against about 4.6 ms for the 404 Grav renders without the plugin, because it skips session, page and theme work.
- Requests that fall through cost 0.4 to 1 ms more than without the plugin. Part of it is loading the plugin, and for 404s writing the log line and running the "only if no page exists" phase.
- Compiling 10,000 rules takes about 120 ms, once per change.
- Without OPcache the compiled file (about 3.7 MB for 10,000 rules) is parsed on every request. PHP-FPM and Apache with mod_php have OPcache on by default.

**Reproduce it.**

```bash
scripts/setup-test-site.sh            # once, downloads Grav 2.2.2
scripts/benchmark-request.sh          # 10,000 rules, 2,000 requests, about 40 seconds
```

The result goes to `build/benchmark-request.md` and `build/benchmark-request.json`. Options such as `--rules 20000` and `--port` are described in [docs/PERFORMANCE.md](https://github.com/pixagentur/grav-plugin-redirect-manager/blob/main/docs/PERFORMANCE.md). To measure your own site, set `debug_timing: true` or `REDIRECT_MANAGER_TIMING=1` and read the `X-Redirect-Manager-Time` header.

## Security

- **Open redirects.** A target is checked when the rule is saved and again for every redirect, after placeholders were filled in. That second check matters: a capture such as `/evil.com` turns the harmless template `/$1` into `//evil.com`. A location that starts with `//` or `/\`, also in percent-encoded or double-encoded form, is refused and the rule counts as not matching. External targets must be `http` or `https` URLs without user info, with a host on `security.allowed_hosts`, and a placeholder cannot sit in the host. Captured text is percent-encoded so it cannot add `?`, `#` or `&`. `security.allow_any_external` turns the allowlist off.
- **Regex denial of service.** A pattern is limited to 1000 characters. When a rule is saved the plugin runs it against probe strings under a low backtrack limit and rejects patterns that need exponential time. While matching, `pcre.backtrack_limit` is set from `security.regex_backtrack_limit` and the recursion limit is 10,000. A pattern that hits a limit does not match and never hangs the request. The same holds for regex conditions on headers and cookies.
- **Path normalization.** A request path is percent-decoded once, normalized to Unicode NFC when `intl` is available, and stripped of dot segments and duplicate slashes. A path with a NUL byte, control characters or invalid UTF-8, or longer than 2048 characters, is never redirected. `%2520` becomes `%20`, not a space.
- **SSRF in the live check.** The checker resolves each external host first and refuses private, loopback, link-local, carrier-grade NAT, multicast and reserved addresses, IPv6 and IPv4-mapped IPv6 included. It pins the first address for the request so a second DNS answer cannot change it, and checks every redirect hop again. It follows at most 5 redirects. The site's own host is exempt so a development site can be checked. Webhooks accept https only, or http for `localhost`, never follow redirects and reject URLs with credentials.
- **Untrusted files.** Sitemaps with a DOCTYPE are refused, gzip is unpacked with a size limit, XML entities are not resolved. YAML is parsed without objects, constants or custom tags. Imports are text only, are never executed and have size, row and nesting limits. CSV export protects against formula injection.
- **Log injection.** Everything a visitor sends is cleaned before it is stored, see [Privacy](#privacy).
- **Data directory.** `user/data/redirect-manager/` carries an `.htaccess` that denies all access and an empty `index.html`. An existing `.htaccess` is never overwritten. nginx ignores `.htaccess`, so keep Grav's `user/data/` rule in your nginx config.
- **Permissions.** Reads need `api.redirects.read`, writes need `api.redirects.manage`. The Admin 2 interface hides write controls without `manage`, but the server checks every request. Writes with only a session cookie go through the API plugin's same-origin check.
- **Failures.** Everything the plugin does during a request is wrapped: a failure is written to `grav.log` and Grav carries on. A broken `rules.yaml` keeps the last compiled rules in force and logs once per change.

Report a vulnerability by mail to info@pixagentur.com instead of opening a public issue.

## Known limits

Each entry says what is limited, why, and what to do instead. The reasons are recorded in [docs/DECISIONS.md](https://github.com/pixagentur/grav-plugin-redirect-manager/blob/main/docs/DECISIONS.md), and every requirement that could not be met as written is listed with its Grav or Admin 2 source in [docs/FEATURE-CHECKLIST.md](https://github.com/pixagentur/grav-plugin-redirect-manager/blob/main/docs/FEATURE-CHECKLIST.md).

**Admin 2 and the API plugin**

- **One page in Admin 2.** Admin 2 gives a plugin one page route. Rules, 404 monitor, suggestions, tester, import / export and settings are tabs inside it, reachable by a hash (`#/rules`, `#/404`). Instead: link to a tab with its hash.
- **Automatic redirects need the API plugin.** They come from the API plugin's page events. Pages changed on disk, by FTP, by a Git pull or by another tool fire no event, so no rule is created. Instead: the 404 monitor and the suggestions catch the dead URLs afterwards.
- **No message on the page-save screen.** After you rename, move or delete a page, Admin 2 shows its own "saved" toast and gives a plugin no way to add a line to it. Admin 2 reads a toast from a response only for a plugin's own page, and the API plugin's page routes do not let a plugin change their response. Instead: the sidebar badge next to "Redirects" counts the new automatic rules and the pending decisions, and the Rules tab lists them with "Mark as seen".
- **Sidebar badge shows 0.** After you clear the last item in the open page, Admin 2 shows "0" until the next reload, because it ignores a live update to nothing. After a reload the badge is empty. Instead: reload, or ignore the "0".
- **Moves that rename pages with translated slugs.** For a single move, the plugin derives the old routes because Grav reports the change only after the folder was renamed. That is exact for moves and for renames on sites without per-language slugs. For a move that renames a page with translated slugs it is exact only for the languages whose slug follows the folder. Reorganize, update and delete are exact. Instead: check the new rules after such a move, or rename in a separate step.
- **Deleting one language does nothing.** Deleting a single translation of a page that keeps other translations creates no rule, because Grav can show the default language instead and a 410 could hide a live page. Instead: the 404 monitor shows the URL if it really dies.
- **Permission names.** The permissions are `api.redirects.read` and `api.redirects.manage`, not `admin.redirects.*`. Grav 2 has no classic admin, and the API plugin lists only `api.*` permissions in the user editor. Instead: nothing, the names work like any other permission.
- **OpenAPI is a file.** The API plugin has no hook for a plugin to add to its own OpenAPI description, so `docs/openapi.yaml` ships with the plugin and the API plugin does not serve it. Instead: point your API client at that file.

**MCP**

- **Tool list after updates.** grav-mcp reads the tools at startup, and the `ETag` of the tool list does not change when this plugin's tools change. Instead: restart the MCP server after an update.
- **`redirects_top_404`, not `redirects_404_top`.** A tool name must start with a letter. Instead: use the shipped name.
- **Tools are declared, not coded.** The tools live in `config/mcp.yaml` and a small registrar. An agent can only do what the REST routes let its key do. Instead: for anything else, use the REST API.

**Command line**

- **`rules`, not `list`.** See [CLI reference](#cli-reference). Instead: `bin/plugin redirect-manager rules`, or the alias `redirects:list`.

**Storage and export**

- **SQLite is for the 404 log only.** `log.backend: sqlite` moves the 404 log. Rules stay in `rules.yaml`, and hit counts and statistics stay in files. Rules in YAML are what Git Sync can diff, merge and restore, and a database file is none of that. Instead: to keep rules in Git, ignore `404.sqlite` and commit `rules.yaml`.
- **New rules default to 302** on a stock Grav, because the plugin follows Grav's `redirect_default_code`. Instead: set `redirects.default_status` to 301.
- **The Cloudflare export needs a host.** Only the REST route takes `host`. Admin 2 and the CLI export rules with a host condition and skip the others. Instead: use the REST route with `host` for Cloudflare.
- **Export formats differ.** No format other than the plugin's own carries everything. The export names every skipped and simplified rule. Instead: read that list, or export as JSON or YAML.

**Scheduler, matching and web server**

- **Grav scheduler required for housekeeping.** Without it the log is never purged, hit counts are folded into the statistics only when the API or the CLI runs, the link check does not run and no report mail goes out. Instead: add Grav's scheduler entry to cron. `bin/plugin redirect-manager check-targets` runs the link check by hand.
- **Static files.** A request for a file that exists on disk may never reach Grav on Apache or nginx. Rules for such URLs only work when the web server hands missing files to Grav. Instead: add the redirect to the web server for those URLs.
- **Regex conditions are case sensitive.** Instead: start the pattern with `(?i)`.

**Testing**

- **Tested setup.** Development and all tests ran on Grav 2.2.2 with PHP 8.3 to 8.5, mostly on the PHP built-in server. Not tested: Grav 2.1.x, reverse proxies and CDNs, Apache and nginx in production, multisite, the real Git Sync plugin, Admin 2 in German in a browser. Subfolder installs are covered through `system.custom_base_url`. Instead: try your setup on a copy and report what breaks.

## FAQ

### Why does a new rule use 302?

The default status code of a new rule is `redirects.default_status`. Its default `0` means "use Grav's `system.pages.redirect_default_code`", and stock Grav 2.2.2 ships `redirect_default_code: 302`. To get permanent redirects by default, set **Default status code** to 301 in the plugin settings or `redirect_default_code: 301` in `user/config/system.yaml`. Rules you saved before keep their code. Automatic redirects use `auto_redirect.status`, 301 by default.

### Does it replace `site.redirects`?

No. Grav keeps applying `site.redirects` and `site.routes` from `site.yaml`, and the plugin does not change that file. The plugin answers first: it matches at the start of the request, while Grav applies `site.redirects` later and only when no page exists. If you want everything in one place, copy the entries into the plugin with the import, see [Migrating from Grav 1.7](#migrating-from-grav-17), and then remove them from `site.yaml` yourself.

### Does it work with Git Sync?

Yes. `rules.yaml` is a readable, diffable YAML file. The plugin checks its modification time and size on every request, so rules that arrive by `git pull` apply at once. Keep logs and state out of Git, see [Privacy](#privacy).

### Can I edit rules.yaml by hand?

Yes, at `user/data/redirect-manager/rules.yaml`. The next request recompiles it. If the file is broken, the plugin keeps the last compiled rules and writes one line to `grav.log` for that change.

### Does it work with static caching or a CDN?

Only for requests that reach Grav. A full-page cache or CDN that answers a URL before PHP runs never lets the plugin see it. What the plugin sends is cacheable: 301, 308 and 410 carry `Cache-Control: public, max-age=3600` by default, and 302, 307 and 451 carry `no-store`. That means a CDN or browser can keep a permanent redirect for an hour, and a browser keeps it until then even after you edit the rule. Change `redirects.cache_control_permanent` to shorten or lengthen this. Not tested with a specific CDN.

### What do I set behind a reverse proxy?

Turn on `security.trust_proxy_headers` only if the proxy sets `X-Forwarded-Host`, `X-Forwarded-Proto` and `X-Forwarded-For` itself and removes them from incoming requests. Without it, conditions on host or scheme see the proxy's values and the 404 log shows the proxy's IP. With it turned on but no such proxy, visitors can fake those headers. Reverse proxies are not part of the test setup.

### Does it work with multisite?

The data folder is `user://data/redirect-manager/`, and `user://` is the folder of the current site, so each site of a Grav multisite has its own rules, log and settings. This was not tested.

### How does it handle several languages?

Rule sources never contain the language prefix. A rule applies to all languages unless it has a language condition. When a visitor used `/de/old`, an internal target gets `/de`, unless `redirects.keep_language_prefix` is off, the target already starts with a language code or the target uses `{lang}`. Imports move a leading language code of a source into a language condition. Suggestions and the page picker see pages in every language, including pages that exist only in a non-default language.

### What happens without Admin 2?

The frontend redirects, the 404 log, Twig, the scheduler jobs and the CLI work. You edit settings in `user/config/plugins/redirect-manager.yaml` and rules with the CLI or in `rules.yaml`. Without the API plugin there is also no REST API, no MCP and no automatic redirects, because those depend on it. Admin 2 itself needs the API plugin.

### How do I test rules in CI?

Use `bin/plugin redirect-manager test <url> --expect-status=... --expect-location=...` and check the exit code. The [CI example](#ci-example) has a script. `import --dry-run` checks a rule file, and `check-targets` finds dead targets.

### Why does my rule not apply to a URL that has a page?

A rule without "only if no page exists" runs before Grav looks for a page, so it does apply. Check the Tester tab: it shows every candidate rule and why it matched or not. Common causes are a higher-priority rule that matches first, a query string in exact mode, a host or language condition, an `expires_at` in the past, and a `/de` prefix in the source. Sources must not contain the language prefix.

## Contributing

```bash
composer install
composer test        # php-cs-fixer (dry run), PHPStan level 8, unit tests
composer test:all    # the above plus the integration suite
```

The integration suite needs a Grav test site. `scripts/setup-test-site.sh` downloads Grav 2.2.2 into `.grav/` once. Then run `RM_PORT_RANGE=8300-8399 vendor/bin/phpunit --testsuite integration`. The suites, environment variables and coverage are in [docs/TESTING.md](https://github.com/pixagentur/grav-plugin-redirect-manager/blob/main/docs/TESTING.md).

The Admin 2 interface is Svelte 5 with Vite in `admin2/`. It builds to two files in `admin-next/`, which are committed:

```bash
cd admin2
npm ci
npm run dev      # dev harness with a mock API, usually on http://localhost:5199/dev/index.html
npm test         # unit tests (Vitest)
npm run build    # writes admin-next/ and the generated UI strings in ../languages.yaml
```

Commit the build output with your change. CI rebuilds it and fails on any difference. UI text lives in `admin2/src/i18n/{en,de}/`, the rest of the language file is hand-written. German uses "Sie".

The browser tests are Playwright specs in `tests/ui/`. CI runs them in the `playwright` job, see [docs/RELEASING.md](https://github.com/pixagentur/grav-plugin-redirect-manager/blob/main/docs/RELEASING.md).

Code is PHP 8.3 syntax with `declare(strict_types=1)`, PSR-12 and PHPStan level 8. [docs/ARCHITECTURE.md](https://github.com/pixagentur/grav-plugin-redirect-manager/blob/main/docs/ARCHITECTURE.md) is the contract between the parts, [docs/DECISIONS.md](https://github.com/pixagentur/grav-plugin-redirect-manager/blob/main/docs/DECISIONS.md) explains the choices, and [docs/GRAV2-NOTES.md](https://github.com/pixagentur/grav-plugin-redirect-manager/blob/main/docs/GRAV2-NOTES.md) holds what was verified about Grav 2.

**Releasing.** A release is a tag `vX.Y.Z` on a commit whose `blueprints.yaml` says `version: X.Y.Z`. `scripts/build-release.sh <version>` builds the ZIP and `scripts/test-release.sh` installs it into a clean Grav, exercises it and uninstalls it again. The full process is in [docs/RELEASING.md](https://github.com/pixagentur/grav-plugin-redirect-manager/blob/main/docs/RELEASING.md).

## License

MIT, see [LICENSE](LICENSE).

---

## Deutsch

Redirect Manager leitet Besucher von alten auf neue URLs um und zeigt, welche alten URLs weiter angefragt werden. Das Plugin kennt exakte, Platzhalter- und Regex-Regeln, einen 404-Monitor mit Zielvorschlägen, automatische Weiterleitungen beim Verschieben, Umbenennen und Löschen von Seiten, Import und Export gängiger Formate, eine Kommandozeile, eine REST-API, MCP-Werkzeuge und eine Oberfläche in Admin 2. Die Oberfläche ist auf Deutsch (Sie-Form) und Englisch verfügbar. Die Regeln liegen in einer YAML-Datei und passen damit in Git.

**Voraussetzungen.** Grav 2.1.5 oder neuer (getestet auf 2.2.2) und PHP 8.3 oder neuer. Das API-Plugin und Admin 2 sind optional, aber für Oberfläche, REST-API, MCP und automatische Weiterleitungen nötig. Ohne sie laufen die Weiterleitungen, das 404-Protokoll, Twig und die Kommandozeile weiter. Damit Wartung, Linkprüfung und Bericht laufen, muss der Grav-Scheduler aktiv sein.

**Installation.** `bin/gpm install redirect-manager` klappt, sobald das Plugin im GPM-Verzeichnis gelistet ist. Bis dahin laden Sie die ZIP-Datei von den [GitHub-Releases](https://github.com/pixagentur/grav-plugin-redirect-manager/releases) und installieren sie mit `bin/gpm direct-install grav-plugin-redirect-manager-1.0.0.zip -y`. Alternativ entpacken Sie die ZIP-Datei nach `user/plugins/` und leeren den Cache mit `bin/grav clearcache`. Der Ordner muss `redirect-manager` heißen. Die Deinstallation mit `bin/gpm uninstall redirect-manager` lässt Ihre Daten stehen: `user/data/redirect-manager/` und `user/config/plugins/redirect-manager.yaml`. Löschen Sie den Datenordner selbst, wenn er weg soll.

**Erste Schritte.**

1. Öffnen Sie in Admin 2 den Punkt Redirects, dann Settings. Stellen Sie den Standard-Statuscode auf 301, wenn neue Regeln dauerhaft weiterleiten sollen. Grav liefert 302 als Standard, deshalb bekommt eine neue Regel sonst 302.
2. Legen Sie mit **New redirect** eine Regel an, zum Beispiel `/alte-seite` nach `/neue-seite`. Der Editor testet die URL beim Tippen.
3. Prüfen Sie die Regel im Tester oder auf der Kommandozeile: `bin/plugin redirect-manager test /alte-seite --expect-status=301 --expect-location=/neue-seite`.
4. Lassen Sie den 404-Monitor einige Tage sammeln. Erzeugen Sie dann unter Suggestions Vorschläge und übernehmen Sie die guten. Vorschläge ab Score 0,9 lassen sich gesammelt übernehmen, mit Vorschau.
5. Sorgen Sie dafür, dass der Grav-Scheduler läuft. Er räumt das Protokoll auf, zählt die Treffer zusammen und prüft die Weiterleitungsziele.

**Relaunch von einer alten Seite.** Importieren Sie die alte `.htaccess` oder nginx-Konfiguration und die Weiterleitungen aus `site.yaml`. Laden Sie danach die alte `sitemap.xml` unter Import / Export hoch. Sie erhalten alle URLs, für die es weder eine Seite noch eine Regel gibt, jede davon als Vorschlag. Die Details stehen im englischen Abschnitt [Migrating from Grav 1.7](#migrating-from-grav-17) und in [docs/IMPORT-FORMATS.md](https://github.com/pixagentur/grav-plugin-redirect-manager/blob/main/docs/IMPORT-FORMATS.md).

**Datenschutz im Detail.**

- **Wo die Daten liegen.** Alles bleibt auf Ihrem Server in `user/data/redirect-manager/`. Das Plugin setzt kein Cookie, auch nicht bei einer Weiterleitung. Der Ordner enthält eine `.htaccess`, die den Zugriff aus dem Web sperrt, und eine leere `index.html`. Bei nginx wirkt die `.htaccess` nicht. Halten Sie dort die Sperre für `user/data/` aus der Grav-Konfiguration bereit.
- **Was der Treffer-Zähler speichert.** Pro Weiterleitung die Regel-ID, dann Summen und Tageswerte. Keine IP-Adresse, kein User-Agent.
- **Was das 404-Protokoll speichert.** Für jede angefragte, nicht vorhandene Seite: Zeitpunkt, Pfad, Query-String, Verweisseite, User-Agent, die Klasse Browser oder Bot, die IP-Adresse laut Einstellung, Sprache, Host und Methode. Es protokolliert nur GET- und HEAD-Anfragen.
- **IP-Adresse.** In der Voreinstellung `anonymize` setzt das Plugin das letzte Oktett einer IPv4-Adresse auf 0 (aus `1.2.3.4` wird `1.2.3.0`) und behält von einer IPv6-Adresse nur die ersten 48 Bit. Die volle Adresse wird nie gespeichert. Mit `none` speichert das Plugin gar keine IP. Hinter einem Proxy sehen Sie die Adresse des Besuchers nur, wenn `security.trust_proxy_headers` an ist. Sonst steht im Protokoll die Adresse des Proxys.
- **Verweisseite und Query-String.** Von der Verweisseite bleiben Schema, Host und Pfad. Werte von Parametern mit sensiblen Namen wie `token`, `password`, `email`, `key`, `session` oder `auth` ersetzt das Plugin durch `***`. Das gilt auch für jeden Wert, der wie eine E-Mail-Adresse aussieht.
- **Bots und Rauschen.** Mit `log.log_bots: false` speichert das Plugin Anfragen von Crawlern gar nicht. Angriffsversuche wie `/wp-login.php` oder `/.env` protokolliert es nicht, solange `log.use_default_ignores` an ist.
- **Aufbewahrung.** Der stündliche Wartungsjob löscht 404-Einträge, die älter sind als `log.retention_days` (30 Tage, bis 365 möglich), und hält die Größe unter `log.max_size_mb` (50 MB). Tageswerte der Treffer bleiben `stats.keep_days` Tage (90) erhalten. Ohne laufenden Scheduler passiert nichts davon. Mit `log.enabled: false` speichert das Plugin keine 404-Anfragen. Die Treffer zählt es weiter.
- **Was den Server verlässt.** Nur auf Ihre Einstellung hin. Ein Webhook enthält einen 404-Pfad mit Trefferzahl und häufigster Verweisseite, oder Quelle und Ziel einer Regel. Die Berichts-Mail enthält die häufigsten 404-Pfade, Zahlen und Regeln. Die Linkprüfung fragt Ihre Weiterleitungsziele per HEAD-Anfrage ab, auch auf fremden Hosts, mit dem User-Agent `GravRedirectManager/1.0 (+link check)`. Mit `checker.check_external: false` bleiben fremde Hosts unberührt.
- **Löschen.** Die Deinstallation lässt die Daten stehen. Um sie zu entfernen, löschen Sie `user/data/redirect-manager/`. Admin 2 löscht die Protokolleinträge pro Pfad (Zeilenmenü im 404-Monitor). Das ganze Protokoll leeren Sie mit `DELETE /redirects/404?all=1` oder indem Sie den Ordner `404/` oder die Datei `404.sqlite` löschen.
- **Git Sync.** Nehmen Sie `rules.yaml` in Git auf, aber nicht die Protokolle. Tragen Sie `hits/`, `404/` und `404.sqlite` in eine `.gitignore` des Datenordners ein.

Ob diese Vorkehrungen für Ihre rechtlichen Pflichten genügen, entscheiden Sie. Das ist keine Rechtsberatung.

**Bekannte Grenzen.** Automatische Weiterleitungen entstehen nur bei Änderungen über Admin 2, die REST-API oder MCP. Seiten, die Sie per FTP oder Git ändern, lösen nichts aus. Dafür sind der 404-Monitor und die Vorschläge da. Beim Speichern einer Seite zeigt Admin 2 keinen Hinweis des Plugins an, weil Admin 2 Plugins dafür keine Möglichkeit gibt. Die Zahl neben "Redirects" im Admin-Menü und die Liste auf dem Reiter Rules melden neue automatische Regeln. Die Zahl zeigt nach dem Abhaken der letzten Meldung bis zum Neuladen "0". Der Befehl zum Auflisten heißt `rules` statt `list`, weil `list` die Befehlsübersicht von `bin/plugin` ersetzen würde. SQLite gilt nur für das 404-Protokoll, die Regeln bleiben in YAML, damit Git Sync sie vergleichen kann. Die Rechte heißen `api.redirects.read` und `api.redirects.manage`. Nach einem Update starten Sie den MCP-Server neu. Alle Grenzen stehen im englischen Abschnitt [Known limits](#known-limits).

**Weiter lesen** (englisch): [Configuration reference](#configuration-reference) für jede Einstellung, [Rule reference](#rule-reference) für Regelfelder und Reihenfolge, [CLI reference](#cli-reference) mit Exit-Codes und CI-Beispiel, [REST API](#rest-api), [Security](#security), [FAQ](#faq).
