# Import and export formats

What each format reads, what it changes on the way in, and what it cannot carry on the way out. The short version is in the [README](../README.md#import-and-export).

Where to run an import:

- Admin 2: Redirects, tab Import / Export. The file is parsed first and shown as a preview with errors and warnings per row. Nothing is saved until you press the import button.
- CLI: `bin/plugin redirect-manager import <file> --dry-run`, then again without `--dry-run`.
- REST: `POST /redirects/import/preview`, then `POST /redirects/import/commit`. MCP: `redirects_import` (commit only).

## Formats

| Id | Format | Import | Export | How it is detected |
|---|---|---|---|---|
| `csv` | CSV | yes | yes | `.csv` or `.tsv`, unless the columns match one of the CSV variants below |
| `json` | JSON | yes | yes | `.json` |
| `yaml` | YAML | yes | yes | `.yaml` or `.yml` with a `rules` key or a bare list |
| `grav_site` | Grav `site.yaml` | yes | yes | YAML with `redirects` or `routes` and no `rules` |
| `htaccess` | Apache `.htaccess` | yes | yes | file name `.htaccess` or `*.htaccess`, or `Redirect`, `RewriteRule` lines in the content |
| `nginx` | nginx config | yes | yes | file name with `nginx`, `location`, `rewrite` or `return` lines in the content |
| `netlify` | Netlify `_redirects` | yes | yes | file named `_redirects`, or lines like `/from /to 301` |
| `wordpress_json` | WordPress Redirection, JSON | yes | yes | JSON object with a `redirects` list and no `rules` |
| `wordpress_csv` | WordPress Redirection, CSV | yes | yes | CSV with the columns `source`, `target`, `regex`, `code` |
| `crawler_csv` | Crawler export | yes | no | CSV with an address or URL column, a status code column and no `target` column |
| `cloudflare_csv` | Cloudflare Bulk Redirects | no | yes | export only |

Files with no useful name are sniffed by content. When detection is wrong, pick the format yourself: in Admin 2 from the format list, on the command line with `--format`, in the API with `format`. A CSV that has `source_url` and `target_url` columns is taken for a Cloudflare file and refused, because Cloudflare is export only. Choose `csv` explicitly to import it.

## What every import does

- **Validation.** Each row goes through the same checks as a rule you type in: loops, unsafe targets, invalid or catastrophic regular expressions, hosts that are not on the allowlist. Above 300 rows only the per-rule checks run. The chain and conflict analysis is skipped for the preview and appears afterwards in the rule list.
- **Invalid rows.** By default one invalid row rejects the whole import. To import the valid rows and leave out the others, use `--skip-invalid` on the command line, `skip_invalid` in the API or the checkbox "Skip invalid rows" in Admin 2.
- **Duplicates.** A row is a duplicate when an existing rule or an earlier row has the same match type, the same source, the same query parameters and the same conditions. Sources are compared without regard to case unless the rule is case sensitive, and without a trailing slash when the rule ignores it. Duplicates are skipped by default (`--no-skip-duplicates` imports them).
- **Sources.** A `#fragment` is removed. An absolute source URL on the site's own host becomes a path. An absolute source URL on another host becomes a host condition, with a warning. A query string in the source switches the rule to exact query matching. A leading language prefix that matches a supported language (`/de/alt`) is cut off and stored as a language condition, because sources never contain the prefix.
- **Status.** A row without a status gets the default status code from the settings, which is Grav's `redirect_default_code` unless you set one. `303` is imported as `302` with a warning. Any other code that is not 200, 301, 302, 307, 308, 410 or 451 is an error.
- **Bookkeeping.** Imported rules have the origin `import`. Admin 2 and the API accept a default group and a default status for the file.
- **Limits.** The size limit is `import.max_mb` (10 MB), measured on the text. A file can hold 50,000 rows. Text in another encoding is converted to UTF-8 with a warning. A binary file is refused. YAML is parsed without objects, constants or custom tags and with a nesting limit; JSON allows a depth of 32.

## CSV

- Any of `,` `;` tab `|` as delimiter, detected from the file. A header row is optional and detected. Header names are case insensitive and may be English or German.
- Columns and their accepted header names:

  | Field | Header names |
  |---|---|
  | source | `source`, `from`, `old`, `url`, `quelle`, `alt`, `old_url`, `oldurl`, `source_url`, `alte_url`, `request`, `request_url` |
  | target | `target`, `to`, `new`, `destination`, `ziel`, `neu`, `target_url`, `new_url`, `newurl`, `redirect_to`, `redirect`, `neue_url`, `ziel_url` |
  | status | `status`, `code`, `status_code`, `statuscode`, `http_code`, `http_status`, `type` |
  | match type | `match_type`, `match`, `matchtype`, `mode` |
  | regex flag | `regex`, `is_regex`, `regexp` (a true value makes the rule a regex rule when no match type is given) |
  | group | `group`, `gruppe`, `category`, `kategorie` |
  | note | `note`, `notiz`, `notes`, `comment`, `kommentar`, `description` |
  | enabled | `enabled`, `active`, `aktiv` |
  | priority | `priority`, `prioritaet`, `priorität` |
  | others | `query_mode`, `case_sensitive`, `ignore_trailing_slash`, `only_if_not_found`, `expires_at` (or `expires`, `ablaufdatum`), `tags` (separated by `\|`) |

- Without a header the columns are read in this order: source, target, status, match type, group, note. Admin 2 shows a column mapper; in the API the option `columns` maps fields to header names or zero-based column numbers, `delimiter` and `has_header` override the detection.
- Export columns: `source, target, status, match_type, enabled, priority, group, note, query_mode, case_sensitive, ignore_trailing_slash, only_if_not_found, expires_at, tags`.
- Cells that start with `=`, `+`, `-`, `@`, a tab or a carriage return get a leading apostrophe on export, so spreadsheets do not run them as formulas. The import removes that apostrophe again.
- Not exported: conditions, the start date, "continue", per-rule ignored query parameters, and the link to a page (a page target becomes its route).

## JSON and YAML

The plugin's own structure, `{"version": 1, "rules": [...]}` or a bare list of rules, with the field names of the [rule reference](../README.md#rule-reference). Exports carry every field, so JSON and YAML are the formats for backups and for moving rules between sites.

## Grav site.yaml

Reads the `redirects` and `routes` sections. Admin 2 also has a panel "Grav site configuration" on the Import tab that copies the site's own `site.redirects` and `site.routes` in one click (`POST /redirects/site-config/import`). It skips duplicates and invalid rows, puts the rules in the group `site.yaml` and does not change `site.yaml`.

- `redirects` are regular expressions. Grav puts `^` in front of every pattern and replaces only the part that matched. The importer turns a pattern into an exact rule when it is a plain path, into a wildcard rule when it is a simple `(.*)` shape, and into a regex rule otherwise. A pattern without `$` at the end gets the warning `grav_prefix_semantics`: Grav matches it as a prefix, the imported rule matches the whole path.
- The target may end in `[301]` (301 to 307). Without it the status is `system.pages.redirect_default_code`, 302 in stock Grav.
- `(?i)` at the start makes the rule case insensitive, otherwise it is case sensitive like Grav's.
- `routes` are aliases. Grav shows the target page under the alias URL and does not redirect, so they become status 200 rules (pass-through) with the warning `alias_pass_through`. A 301 is usually what you want.
- Export writes patterns that end in `$`. Skipped: rules with conditions, exact or parameter query modes, the statuses 308, 410 and 451, pass-through rules that are not exact, regex rules that do not start with `^` or `/`. When two rules produce the same pattern, the first one wins and the second is reported as skipped.

## Apache .htaccess

Reads `Redirect`, `RedirectPermanent`, `RedirectTemp`, `RedirectMatch` and `RewriteRule` with `RewriteCond`. `<IfModule>`, `<IfDefine>`, `<IfVersion>` and `<VirtualHost>` are read through, every other block is skipped with a warning. Continuation lines with a trailing backslash work. A file in which no line is a redirect or rewrite directive or a block (a CSV, plain text, a file with only `Header` and `ErrorDocument` lines) is a file-level error `no_directives`: the preview shows it, the API answers 422 and the CLI exits with 2. A file with only comments, or only `RewriteEngine On`, imports nothing and gets the warning `no_rows`.

- `Redirect /a /b` becomes an exact rule. Apache also matches everything below `/a`; the importer does not.
- A `RewriteRule` without the `QSA` flag drops the query string, as the rule says. Apache itself keeps it unless the target ends in `?`.
- Supported conditions: `%{HTTP_HOST}` and `%{SERVER_NAME}` (host), `%{HTTPS}`, `%{SERVER_PORT}`, `%{REQUEST_SCHEME}` (scheme), `%{QUERY_STRING}` (query), `%{HTTP_USER_AGENT}`, `%{HTTP_REFERER}` and `%{HTTP:name}` (header conditions). `%{REQUEST_FILENAME} !-f` becomes "only if no page exists". OR-combinations of host conditions work. Other conditions are ignored with a warning and the rule is imported without them.
- Skipped with a warning: internal rewrites (no `R` flag), rules that answer 403 (`F`), proxy rules (`P`), negated patterns, and substitutions that use server variables or condition captures. Flags the plugin does not know are ignored with a warning.
- A pattern without `^` is imported as written and gets a warning.
- Export skips rules with a language condition, rules with a cookie condition and pass-through rules. It does not carry the start and expiry dates, "continue", page targets or per-rule ignored query parameters.

## nginx

Reads `location` blocks with `return` or `rewrite`, `if` on host, scheme, header, cookie or query, and server-level domain redirects. Every other directive is ignored. A block that is not closed is an error. A config with none of `server`, `http`, `location`, `if`, `return`, `rewrite` or the directives it reports (`try_files`, `proxy_pass`, `map` and similar) is a file-level error `no_directives`, as for `.htaccess`.

- `return` drops the query string. `rewrite` keeps it unless the target ends in `?`. Imported rules follow that: a `$is_args$args` in a `return` target means the query is passed on.
- Skipped with a warning: internal rewrites (`last`, `break`), rules inside an unsupported `if`, a server-wide redirect with no host condition (it would redirect every request), targets with nginx variables the plugin does not know, named or empty locations.
- Export skips rules with a language condition, pass-through rules, "only if no page exists" rules, rules that need more than one condition and rules that test more than one query parameter.
- Export does not carry the start and expiry dates, "continue", page targets or per-rule ignored query parameters.

## Netlify _redirects

`from [query=value ...] to [status[!]]`, one per line. `:name` placeholders become named regex groups, a trailing `*` (or `:splat`) becomes a wildcard. Default status when none is given is 301. A `!` after the status is ignored.

- Country, Language and Role conditions are not supported. They are ignored with a warning.
- Query parameters in the source become parameter matching. A target that uses the value of a query parameter is imported with a warning, the value is not carried over.
- Errors: a placeholder in the target that the source does not define, and status 200 with an absolute URL (Netlify proxying).
- Export skips 410 and 451 (Netlify needs a target), conditions other than host, wildcard hosts, regex rules that cannot be written as placeholders, and wildcards other than one trailing `*` with `$1` at the end of the target. Case sensitivity, the trailing-slash setting, "pass query on", dates, "continue", "only if no page exists" and page targets are not carried. Exact query matching is written as parameter matching, which Netlify treats more loosely.

## WordPress Redirection

The JSON export (Tools, Import/Export) and the CSV export of the WordPress Redirection plugin.

- JSON: URL redirects, plain and regex, with the case, trailing-slash and query flags, group names, the enabled flag, 410 error rules and pass-through. The match types server (host), agent, referrer, header and cookie become conditions. Other match types (login, role, ip, page, language, custom) and the random action are skipped with a warning. Rules are read in Redirection's own order. The note comes from the title.
- CSV: columns `source, target, regex, code, type, hits, title, status`, read by header name, without a header in that order.
- Export writes the same two formats. JSON export skips rules with a language or scheme condition, rules that need parameter matching, status 451 and rules with more than one condition. It does not carry dates, "continue", "only if no page exists", page targets or per-rule ignored query parameters. CSV export skips rules with conditions and does not carry the query flags.

## Crawler exports

Screaming Frog "Response Codes" exports and Sitebulb URL lists. A crawler lists broken URLs, not redirects, so the import creates no rules. It reads the address column (`address`, `url`, `destination`, `broken_url`, `target_url`) and the status column (`status_code`, `http_status_code`, `response_code` and similar, or the words "Not Found" and "Gone"), keeps rows with 404 or 410, turns them into site paths (other hosts are dropped) and stores a suggestion for each one. Review them in the Suggestions tab. The header may sit in the first five lines.

## Old sitemaps

Not a rule format, but the best start for a relaunch. Upload the old `sitemap.xml` (or `.gz`). The plugin lists the URLs that have neither a page in any language nor a matching rule and stores a suggestion for each one. Admin 2: Import / Export, "Compare with an old sitemap" (files up to 5 MB). REST: `POST /redirects/import/sitemap`.

- A sitemap index is refused. Upload the nested sitemaps one by one.
- Sitemaps with a DOCTYPE are refused, gzip is unpacked with a size limit, entities are not resolved.
- URLs are reduced to paths. Query strings are dropped, so a sitemap of `?id=1` URLs collapses.

## Export

`GET /redirects/export?format=...`, `bin/plugin redirect-manager export --format=...`, or the export cards in Admin 2. Filters: only enabled rules, one group, status codes, rule ids. The REST route takes `host`, the command line `--host`.

- Rules come out in the order the matcher uses: priority, then exact before wildcard before regex, then age.
- Formats without an enabled flag skip disabled rules and say so. Only CSV, JSON, YAML and both WordPress formats keep disabled rules.
- A rule that uses `{lang}` in its target is skipped in every format except CSV, JSON and YAML. No other format has a language placeholder.
- The result has two lists. `skipped` names the rules the format cannot express at all, `lossy` names the rules that were exported but lost a setting. The command line prints both to stderr, or with `--json` returns them.
- Cloudflare Bulk Redirects needs a host in every source. It takes the host conditions of the rule. For rules without one, pass `host=example.org` to the REST route or `--host=example.org` to the CLI. Admin 2 has no field for it, so rules without a host condition are skipped there, and in the CLI or REST route when no host is given, with the reason `export_host_required`. Cloudflare takes 301, 302, 307 and 308 only, no regular expressions, no conditions other than host, and wildcards only as a trailing `/*`.
