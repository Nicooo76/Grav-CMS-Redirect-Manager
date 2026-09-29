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
