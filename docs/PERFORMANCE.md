# Performance

The plugin's job on every frontend request is one `stat()` of `rules.yaml`, one `include` of the compiled rule set and a match. This page shows what that costs inside a real Grav 2.2.2 request with 10,000 rules, and how to repeat the measurement.

## Method

`scripts/benchmark-request.sh` builds a fresh Grav site in `build/benchmark/` (Grav's page cache on, no debugger), generates 10,000 rules (8,500 exact, 1,000 wildcard, 500 regex) and imports them with `bin/plugin redirect-manager import`. It serves the site with `php -S` and OPcache enabled for the CLI server (`-d opcache.enable_cli=1 -d opcache.validate_timestamps=1`), sends 300 warm-up requests, then 2,000 measured requests over one keep-alive connection:

- 45 % exact hits, 15 % wildcard hits, 10 % regex hits (301 and 302),
- 12 % real pages that no rule touches (`/`, `/typography`),
- 18 % 404s, some of them shaped like the rules (`/old/section-N/nothing`) so they get past the exact-match lookup.

The plugin measures itself: with `REDIRECT_MANAGER_TIMING=1` (or `debug_timing: true` in `redirect-manager.yaml`) the early phase writes `X-Redirect-Manager-Time` in microseconds. The clock starts when the `PluginsLoadedEvent` handler is entered and stops after the match, so it covers building the services, the request context, loading the compiled rule cache (`stat()` plus `include` plus hydration) and the match. It does not cover building or sending the response, hit recording or the 404 log. Without the variable and the setting the handler does one `getenv()` and one config read.

The same 2,000 requests then run twice more, with the plugin enabled without timing and with the plugin disabled, to compare total response times (curl `time_total`).

## Result

Machine: Apple M3 Max (16 cores, 48 GB), macOS (Darwin arm64). PHP 8.4.26, Grav 2.2.2, `php -S` single process. The machine was under heavy load from other jobs during the run (load average above 70), so read the numbers as an upper bound.

Plugin time per request (header X-Redirect-Manager-Time: request context, compiled rule cache, match), microseconds:

| Request kind | Requests | Median | p95 | Max | Status codes |
|---|---:|---:|---:|---:|---|
| all | 2000 | 316.2 | 414.4 | 1812.9 | 200 x245, 301 x1263, 302 x127, 404 x365 |
| hit-exact | 902 | 328.9 | 419.9 | 1812.9 | 301 x775, 302 x127 |
| hit-regex | 185 | 332.4 | 445.6 | 852.1 | 301 x185 |
| hit-wildcard | 303 | 352.3 | 443.9 | 763.6 | 301 x303 |
| miss-404 | 365 | 249.6 | 341 | 1319.5 | 404 x365 |
| miss-page | 245 | 235 | 299.7 | 703.5 | 200 x245 |

Total response time per request as measured by curl (ms), same requests, three server runs:

| Request kind | Plugin disabled (median / p95) | Plugin enabled (median / p95) | Enabled + timing header (median / p95) |
|---|---:|---:|---:|
| all | 4.6 / 5.68 | 1.8 / 6.14 | 1.94 / 6.74 |
| hit-exact | 4.62 / 5.8 | 1.66 / 2.21 | 1.78 / 2.49 |
| hit-regex | 4.68 / 5.56 | 1.65 / 2.14 | 1.8 / 2.73 |
| hit-wildcard | 4.62 / 5.57 | 1.68 / 2.18 | 1.8 / 2.34 |
| miss-404 | 4.58 / 5.81 | 5.66 / 7.56 | 6.21 / 8.61 |
| miss-page | 4.51 / 5.58 | 5.02 / 6.14 | 5.6 / 7.07 |

With the plugin disabled the redirect URLs are plain 404s, so only the miss rows are comparable one to one.

Plugin time per request, median and p95 in microseconds, per PHP version (same run, all request kinds):

| PHP | Median | p95 |
|---|---:|---:|
| 8.3 | 304.0 | 427.4 |
| 8.4 | 316.2 | 414.4 |
| 8.5 | 442.2 | 585.1 |

The 8.5 run happened while the machine was busier than during the 8.3 and 8.4 runs.

What it says:

- The plugin's own work per request is about 0.3 ms at the median and stays below 0.5 ms at p95, with 10,000 rules. Exact, wildcard and regex hits cost the same within noise: the compiled set finds exact rules in one hash lookup, wildcard rules through a trie over the path segments before the first `*`, and regex rules through their literal first path segment (`^/archive-12/...`). The generated regexes all start with a literal segment; a regex that does not (`^/(\\d+)/x`) goes into a list that is tried for every request, so keep those few.
- A redirect is answered in about 1.7 ms in total, against about 4.6 ms for the 404 Grav renders without the plugin. The redirect skips Grav's session, page and theme work.
- Requests that fall through cost 0.4 to 1 ms more than without the plugin (real pages about 0.4 ms, 404s about 1.1 ms). Part of that is loading the plugin (autoloader, events) and, for 404s, writing the 404 log line and running the "only if not found" phase; the rule match itself is about 0.25 ms of it.
- The first request after a change to `rules.yaml` compiles the rule set (about 120 ms for 10,000 rules, see `build/benchmark.json` from the unit benchmark) and writes `cache/redirect-manager/rules-*.php`; all later requests only include it.

Without OPcache the compiled file (about 3.7 MB for 10,000 rules) is parsed on every request; the CLI server needs `opcache.enable_cli=1` for realistic numbers, and PHP-FPM and Apache with mod_php have OPcache on by default.

## Repeat it

```
scripts/setup-test-site.sh                 # once, downloads Grav 2.2.2 (the benchmark reuses the zip)
scripts/benchmark-request.sh               # 10,000 rules, 2,000 requests, about 40 seconds
RM_PHP_BIN=/opt/homebrew/opt/php@8.3/bin/php scripts/benchmark-request.sh --rules 20000 --port 8711
```

The result is written to `build/benchmark-request.md` and `build/benchmark-request.json`. The integration suite has a shorter version that fails when the median exceeds 1,000 µs:

```
RM_PORT_RANGE=8500-8599 vendor/bin/phpunit --testsuite integration --group benchmark
```

That test (10,000 rules, 400 requests) reported a median of 270 to 390 µs and a p95 of 380 to 510 µs on PHP 8.3 to 8.5 on the same machine. The unit benchmark (`tests/Unit/Matching/MatcherBenchmarkTest.php`) measures the matcher alone, without Grav: about 10 µs per match.

## Admin API at realistic sizes

The frontend path above is one `include` and a match. The admin screens read more: the rule list, the dashboard, the 404 monitor and the suggestions. `tests/Integration/ApiPerformanceTest.php` (group `benchmark`) measures them over HTTP against a real Grav 2.2.2 with OPcache for the CLI server: 10,000 rules (85 % exact, 10 % wildcard, 5 % regex), statistics for a third of the rules (45 days of daily buckets), 50,000 404 entries over 30 days on 8,000 paths, 5,000 suggestions. Each figure is the wall-clock time of a whole request, including Grav's boot and the token check, after two warm-up requests. That is an upper bound of the server time. The test fails when the fastest of five requests exceeds 200 ms for a page of 50 rules or 300 ms for any other endpoint. It reports the median next to it.

```
RM_PORT_RANGE=8500-8599 vendor/bin/phpunit --testsuite integration --filter ApiPerformanceTest
```

| Request | Before | After |
|---|---:|---:|
| `GET /redirects/rules` (page of 50, default sort) | about 0.93 s | 0.15 s |
| `GET /redirects/rules` sorted by source, searched, filtered | about 0.83 to 0.97 s | 0.12 s |
| `GET /redirects/stats` | 0.31 s | 0.18 s |
| `GET /redirects/404` (30 days, 50 rows) | 5.0 s | 0.17 s |
| `GET /redirects/404` (30 days, bots included, 100 rows) | 9.5 s | 0.15 s |
| `GET /redirects/404?q=page-12` | 4.8 s | 0.12 s |
| `GET /redirects/404/trend` | 0.20 s | 0.08 s |
| `GET /redirects/suggestions` | 0.52 s | 0.15 s |
| `POST /redirects/import/commit`, 10,000 CSV rows | 0.8 s | 0.8 s (median of 20 runs, maximum 1.0 s) |

The "before" column was measured in one PHP process without HTTP (`RedirectService` called directly, PHP 8.5, no OPcache, same data), so it leaves out about 30 ms of Grav boot that the "after" column includes. The user-visible rule list took about 1.1 s. Apple M3 Max, other jobs running.

What was slow, and what changed:

- **Parsing `rules.yaml` took 0.75 s of the 0.93 s.** Symfony YAML needs about 75 microseconds per rule, and every API request parsed the file again. The parsed rows now live in `cache://redirect-manager/parsed-rules-<hash>.php`, keyed by the SHA-1 of the file content, so the cache cannot be stale (a hand edit changes the hash and the file is parsed once more). Every save writes the rows through, so the request after an edit finds the cache warm. The compiled rule set for the frontend reads the same cache when it rebuilds. Reading 10,000 rules from the cache costs about 25 ms for the rows plus 50 ms for the `Rule` objects. The dashboard only needs counts and reads the rows without objects.
- **Sorting used `usort()` with a callback, 130,000 calls at 10,000 rules.** It now sorts plain columns with `array_multisort()`. The order is the same for every sort and direction (a test compares all of them with the old comparator on random rules).
- **The 404 log was read twice as objects, and once more per row.** `NotFoundService::groups()` asked `RuleService::all()` for every one of the 50 rows whether any rule exists (50 times the 0.1 s hydration: about 4.5 s of the 5 s). It asks once, and only counts the stored rows. The JSONL store now decodes each line once into an array, never builds a `NotFoundEntry` for lines it only counts, remembers the position of every accepted line and reads the entries of the 50 paths on the page back by position instead of a second pass. Suggestions use the aggregates only and skip the details.
- **`stats.json` was decoded three times per rule list** (aggregation, statistics, unused rules). The decoded result is now kept while the file is unchanged.
- The analysis of all rules (chains, loops, conflicts, about 0.5 s at 10,000 rules) was already cached per file revision and is hit: a rule list after the first one takes 0.1 s of it. The first request after a change to `rules.yaml` still computes it, so the first list after an edit takes about 0.6 s. The tests warm it first.

