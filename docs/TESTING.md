# Testing

Three layers, each answers a different question.

| Suite | Needs | Answers |
|---|---|---|
| Unit (`tests/Unit`, 4,450 tests, ~25 s) | `vendor/` only | Does each class do what it says, without Grav? |
| Integration (`tests/Integration`, 549 tests, ~6.5 min) | a Grav test site in `.grav/`, `curl`, `php -S` | Does the plugin work inside real Grav 2 over real HTTP and CLI? |
| UI (`tests/ui`, Playwright) | Node, Chromium, the Grav test site in `.grav/` | Does the Admin 2 screen work in a real browser against a real Grav 2 (rules, 404 monitor, suggestions, import/export, tester, read-only user, pending decisions, widget, screenshots, accessibility)? See `tests/ui/README.md`. |

## Running

```bash
composer install
composer test                 # php-cs-fixer (dry run), PHPStan level 8, unit suite
composer unit                 # vendor/bin/phpunit --testsuite unit
composer coverage             # unit suite with pcov, fails below 90 % (see below)
```

Integration suite (once: `scripts/setup-test-site.sh` downloads Grav into `.grav/2.2.2`, links the plugin in and creates an API test user):

```bash
scripts/setup-test-site.sh
RM_PORT_RANGE=8300-8399 vendor/bin/phpunit --testsuite integration
composer test:all             # composer test + integration
```

Groups (`#[Group]`) cut across both suites. `vendor/bin/phpunit --group <name>` runs one, `--exclude-group <name>` leaves one out, and both combine with `--testsuite`:

| Group | What it holds | Run |
|---|---|---|
| `benchmark` | the speed guards: `U:Matching/MatcherBenchmarkTest` (10,000 rules, matcher alone), `U:Analysis/AnalysisPerformanceTest` (analysis of 10,000 rules under 2 s), `I:RequestBenchmarkTest` (plugin time per frontend request, median under 1 ms) and `I:ApiPerformanceTest` (admin API at 10,000 rules and 50,000 log entries: page of 50 rules under 200 ms, other endpoints under 300 ms; `RM_PERF_FACTOR` scales these two budgets, CI uses 3 because shared runners are slower; also imports 10,000 rules 20 times). Numbers go to STDERR, see `docs/PERFORMANCE.md`. | `vendor/bin/phpunit --group benchmark`; unit part only: add `--testsuite unit`; integration part only: `RM_PORT_RANGE=8500-8599 vendor/bin/phpunit --testsuite integration --group benchmark`. `--exclude-group benchmark` skips them. |
| `performance` | the suggester timing test | `vendor/bin/phpunit --group performance` |
| `release` | the release-package checks (`scripts/build-release.sh`, `scripts/test-release.sh`, see `docs/RELEASING.md`); skipped without `RM_RELEASE_ZIP` | `scripts/test-release.sh` |
| `integration` | most classes of `tests/Integration` (API, matching, multisite, proxy headers, page cache, Grav settings) | `RM_PORT_RANGE=8500-8599 vendor/bin/phpunit --testsuite integration --group integration` |
| `cli` | `I:CliTest`, `I:CliImportExportTest` and the CLI unit tests | `vendor/bin/phpunit --group cli` |
| `api`, `app`, `auto`, `analysis`, `domain`, `grav`, `import`, `jobs`, `notfound`, `roundtrip`, `scheduler`, `stats`, `storage`, `suggest`, `util`, `i18n` | one per source directory of `classes/` (and a few cross-cutting themes) | `vendor/bin/phpunit --group notfound` |

Unit tests run on PHP 8.3, 8.4 and 8.5: `/opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit --testsuite unit`, and the same with `vendor/bin/phpstan analyse --memory-limit=1G`. `composer test` (php-cs-fixer dry run, PHPStan level 8, unit suite) uses whichever `php` is first on the PATH, so put another binary first to cover another version. The integration suite takes its PHP from `RM_PHP_BIN` (default: the PHP running PHPUnit): `RM_PHP_BIN=/opt/homebrew/opt/php@8.4/bin/php RM_PORT_RANGE=8500-8599 vendor/bin/phpunit --testsuite integration` tests PHP 8.4 against the same site. PHPUnit is configured `failOnWarning`, `failOnRisky`, `failOnDeprecation`.

UI suite (once: `scripts/setup-test-site.sh`, as above):

```bash
cd tests/ui
npm ci && npx playwright install chromium
npx playwright test                        # builds a fresh site in a temp dir, seeds it, runs every spec
npx playwright test --grep-invert @visual  # without the screenshot comparison
```

Every run builds its own copy of the test site, starts `php -S` on a port from 8400 to 8499 (`RM_PORT_RANGE`), creates an admin and a read-only user with generated passwords, seeds rules, hits, 404 entries and suggestions, and restores that state before every test. The base site is never modified. Screenshot baselines are per platform (`tests/ui/specs/__screenshots__/<platform>/`); the committed ones are darwin. Without baselines for the platform the `@visual` tests are skipped, so CI (Linux) runs them only after the Linux baselines were generated with the manually started `tests` workflow ("Run workflow", tick the baselines box) and committed. Details, environment variables (`RM_SITE_DIR`, `RM_PHP_BIN`, `RM_PORT`, `RM_VISUAL`, ...) and how to update baselines: `tests/ui/README.md`.

### CI (`.github/workflows/tests.yml`)

- **Runner.** Every job runs on `ubuntu-24.04`, not `ubuntu-latest`: GitHub moves the label to Ubuntu 26 on 2026-10-19, and the PHP builds, Chromium libraries and fonts the tests were written against are those of 24.04. Move the pin on purpose, together with a green run.
- **JIT.** `PHP_INI` sets `opcache.jit=disable` and `opcache.jit_buffer_size=0`. `opcache.jit=off` is not enough: it still initialises the JIT, and with pcov loaded (the coverage job) PHP then prints `JIT is incompatible with third party extensions that override zend_execute_ex()` on stderr of every process it starts. The concurrency tests start children and treat any stderr output of a worker as an error, so the children are started through `Tests\Unit\Support\ChildPhp::command()` (the JIT switched off on the command line), and `ChildPhp::stderr()` drops exactly that one line before the test looks at the rest. Reproduce it with a php.ini that has `opcache.enable_cli=1`, `opcache.jit=off`, `opcache.jit_buffer_size=64M` and `pcov.enabled=1` (`PHPRC=<dir with that php.ini> vendor/bin/phpunit --testsuite unit`): `-d` options on the command line do not reach the children, a php.ini does.
- **libyaml.** The Playwright job loads the `yaml` extension. Grav parses `languages.yaml` with it when it is there, as YAML 1.1, where a bare `YES:` or `NO:` key is a boolean and a bare `Off` a `false`. A site without the extension (Homebrew PHP here) never shows that. `admin2/scripts/gen-i18n.mjs` quotes such keys, `admin2/src/i18n-yaml.test.ts` reads every shipped YAML file under both versions of the language, and `german.spec.ts` compares the dictionary the server serves with the file. To run the UI suite the way CI does, build the extension once (`pecl download yaml`, `phpize`, `./configure --with-yaml=$(brew --prefix libyaml)`, `make`) and point `RM_PHP_BIN` at a script that runs `php -d extension=<path>/yaml.so "$@"`.
- **Code style.** `composer cs` runs on PHP 8.3 only. PHP CS Fixer warns when it runs on a PHP newer than the minimum of `composer.json`, and its rules do not depend on the PHP version. The same warning appears when you run `composer cs` or `composer test` with PHP 8.4 or 8.5 first on the PATH; it is not an error.
- **Exports.** The WordPress Redirection JSON carries the time of the export, to the second, in `plugin.date`. Tests that compare two exports normalise it (`CliImportExportTest::withoutExportTime()`); every other format is byte-identical between runs.

### Environment variables

| Variable | Used by | Meaning |
|---|---|---|
| `RM_PHP_BIN` | integration | PHP binary for the test site's `php -S`, `bin/plugin` and `bin/grav` children. Default: the PHP that runs PHPUnit. Also read by `scripts/setup-test-site.sh`. |
| `RM_GRAV_BASE` | integration | Base site directory. Default `.grav/<RM_GRAV_VERSION>`. |
| `RM_GRAV_VERSION` | integration | Grav version under `.grav/`. Default `2.2.2`. |
| `RM_PORT_RANGE` | integration | `min-max`, ports the test servers pick from (default `8100-8999`). Set different ranges when several runs share a machine. |
| `RM_COVERAGE` | integration | A directory: turns on line coverage of the test site's PHP children (see "Integration coverage"). Relative paths are relative to the repository. |
| `RM_COVERAGE_DIR` | internal | Set by the test code from `RM_COVERAGE` for the children. Do not set it yourself. |

## Integration test classes and helpers

Every class copies the test site's `user/` folder into a temp directory, starts its own `php -S` on a port from `RM_PORT_RANGE` and resets the site before each test (`IntegrationTestCase`, `Support/TestSite`). A class that needs other PHP settings overrides `siteIni()`: the benchmarks switch OPcache on for the CLI server.

| Class | What it proves |
|---|---|
| `RedirectMatchingTest`, `StatusResponsesTest`, `MultilanguageTest`, `SubfolderTest`, `EventsTest`, `ResilienceTest`, `NotFoundLoggingTest`, `PageIndexTest` | matching, status codes, languages, a base URL in a subfolder, events, broken files, the 404 log and the page index, all over real HTTP |
| `Api*Test` (`ApiRulesTest`, `ApiNotFoundTest`, `ApiSuggestionsTest`, `ApiImportExportTest`, `ApiTesterAndAnalysisTest`, `ApiSystemTest`, `ApiSmokeTest`, `ApiSecurityTest`) | the REST API with real tokens: envelope, permissions, validation, ETag, import and export. Base class `ApiTestCase`: a super admin (`$api`), a read-only user (`$reader`) and a user without plugin permissions (`$basic`) |
| `ApiSecurityTest` | 401, 403 and the same-origin (CSRF) rule for every route. The route list comes from the code: `Support/RegisteredRoutes` reads `RouteRegistrar` and the permission each route has in `docs/openapi.yaml`, so a new route is checked without anyone editing the test. `U:OpenApiTest` ties the same list to the OpenAPI file |
| `CliTest`, `CliImportExportTest` | `bin/plugin redirect-manager` through `Support/CliRunner`: every command and exit code; import and export for every format (clean files, real-world files, wrong formats, `--host` for Cloudflare, round trips) |
| `ProxyHeadersTest` | `X-Forwarded-Host`, `-Proto` and `-For` with `security.trust_proxy_headers` on and off, and the `HTTPS` server variable. `php -S` has no TLS, so the site starts with `Support/https-prepend.php`, which sets `HTTPS` for requests that carry `X-Test-Https` |
| `PageCacheTest` | Grav's page cache and `pages.expires` on: a redirect still comes first, carries the plugin's `Cache-Control`, and a rule edit applies at once |
| `GravRedirectSettingsTest` | `redirect_default_route`, `redirect_default_code` and `redirect_trailing_slash` stay Grav's; `system.yaml` is never touched |
| `MultisiteTest` | two sites behind one Grav: rules, 404 log, hit counts, compiled cache and settings are per site, and a rule of site A does not apply on site B. `TestSite::createMultisite(['site-a', 'site-b'])` writes a `setup.php` that maps the first label of the Host header (`site-a.test`) to `user/sites/<name>/`, `cache/<name>/` and `tmp/<name>/`; `forSite('site-a')` returns a `TestSite` for one of them (Host header and paths included); the test's own `setup.php` reads `RM_SITE` to pick the site for command line runs. Grav's `problems` plugin looks for `user/accounts`, `data`, `pages`, `config`, `plugins` and `themes` under the web root, finds none on a multisite installation and answers every request with a 500 page, so `TestSite::writeSystemConfig()` disables it per site, as Grav's multisite documentation advises |
| `AutoRedirect/*` | page changes through the API plugin's events, the delete policies, permissions, the scheduler jobs and the digest mail; `WithoutApiPluginTest` runs the plugin without the API plugin |
| `ApiPerformanceTest` | see the `benchmark` group above |
| `ReleaseTest` | the release ZIP installed by `bin/gpm direct-install` and by hand into a fresh Grav (`Support/ReleaseSite`); needs `RM_RELEASE_ZIP`, otherwise skipped |

Other helpers in `Support/`: `ApiClient` and `ApiResponse` (JSON API calls with a bearer token), `HttpResponse` (one frontend answer), `AccountFactory` (limited API users through the Login plugin's CLI), `SessionLogin` (front-end login cookie), `CaptureServer` (records webhook calls), `StaticServer` (a second server for the live link check, because the main one is single threaded) and `ProcessRunner`.

## Grav stand-ins in unit tests

Grav is not in `vendor/`, yet `Grav/`, `Auto/AutoRedirectListener`, `Auto/PageSnapshotter` and `Api/` extend or call Grav and API plugin classes. The unit suite therefore ships minimal stand-ins in `tests/Unit/Support/Grav/*.php`:

- `Common.php`: `Grav` (ArrayAccess container that records `fireEvent()` and `close()`), `Config` (dotted `get`/`set`), `UniformResourceLocator`, `Language`, `Pages`, `PageInterface` (only the methods the plugin reads), RocketTheme `Event`.
- `Psr.php`: the PSR-7 response/request interfaces (subsets) and a Nyholm `Response`.
- `ApiPlugin.php`: `AbstractApiController`, `ApiResponse` and the API exceptions, copied from the API plugin's constructor signatures and status codes. `AbstractApiController::$granted` is the permission allow-list of the test.
- `Plugins.php`, `Scheduler.php`, `Email.php`, `Twig.php`: `Plugins::getPlugin()`, `RedirectManagerPlugin`, the scheduler and its job recorder, the email service, Twig extension bases.
- `tests/Unit/Support/FakePage.php`, `FakeServerRequest.php`: configurable fakes on top of them.

Rules: every stub file declares a class only when it does not exist yet (`class_exists($name, false)`), so a real Grav on the include path always wins. Tests that need them extend `Tests\Unit\Support\GravTestCase` (or a test case that does), which loads `GravStubs.php`. Nothing under `tests/` is seen by PHPStan (`phpstan.neon.dist` analyses `classes`, `cli`, `redirect-manager.php` against the real Grav in `.grav/2.2.2`), but php-cs-fixer checks it. Stubs implement what the plugin calls and no more; whatever real Grav does beyond that is the integration suite's job.

What the stand-ins do not prove: Grav's actual event order, page routing, `Pages::find()` semantics, the API plugin's permission resolver and JSON envelope, the scheduler. Those live in `tests/Integration`.

## Coverage

### Strict attribution

PHPUnit credits a line only to the classes named in `#[CoversClass]` of the test that ran it. A value object such as `Rule` that every matcher test uses shows 0 % until a test covers it on purpose. That is intended: the numbers below mean "this class has a test aimed at it", not "some test happened to run through it". Do not paper over gaps with `#[CoversNothing]`, `#[UsesClass]` or `@codeCoverageIgnore`. There is no `@codeCoverageIgnore` in the code today; the handful of lines that stay uncovered are defensive branches that cannot be reached from a test (for example `GravBootstrap`'s `catch` around `AutoState::pendingCount()`, `PathNormalizer` after `mb_check_encoding`).

### Unit coverage

```bash
composer coverage
# = php -d pcov.enabled=1 vendor/bin/phpunit --testsuite unit --coverage-clover build/clover.xml
#   php scripts/coverage-report.php --min=90 build/clover.xml
```

`scripts/coverage-report.php [--min=N] [--files] clover.xml [more.xml ...] [dump-dir ...]` prints line coverage of `classes/` overall and per directory (`--files`: per file) from the Clover file. It needs pcov (`php -m | grep pcov`; PHP 8.5 from Homebrew has it here, 8.3 and 8.4 do not). Exit code 1 below `--min`.

### Integration coverage (`RM_COVERAGE`)

pcov cannot instrument the `php -S` child that serves the test site, so the child measures itself:

1. With `RM_COVERAGE=<dir>` set, `TestSite::phpCommand()` adds `-d auto_prepend_file=tests/Support/coverage-prepend.php -d pcov.enabled=1 -d pcov.directory=classes` to every PHP child that runs plugin code: the site server, `bin/plugin redirect-manager` (`CliRunner`) and `bin/grav scheduler` (scheduler tests). This only happens when `RM_PHP_BIN` (default: the running PHP) has the pcov extension; otherwise the run continues unmeasured and prints a note.
2. The prepend file starts pcov before Grav boots and registers a shutdown function that runs after Grav's own (`$grav->close()` and `exit` still reach it). It writes the executed lines of `classes/` to `<dir>/<pid>.json` as `{"<file>": [line, ...]}` and merges every request of the same process into that file. It prints nothing and swallows every error, so a response is never affected.
3. `coverage-report.php` takes the dump directories as extra arguments: a line is covered when the Clover file or any dump says so. The denominator stays the Clover file's statement lines, so a dump cannot inflate the total.

```bash
php -d pcov.enabled=1 vendor/bin/phpunit --testsuite unit --coverage-clover build/clover.xml
RM_PORT_RANGE=8300-8399 RM_COVERAGE=build/coverage-integration php vendor/bin/phpunit --testsuite integration
php scripts/coverage-report.php --files build/clover.xml build/coverage-integration
```

Run both against the same source: the dumps are line numbers, so an edit to `classes/` between the two runs skews the merged column for that file. Delete the dump directory before a new run (dumps are merged, not replaced). The dumps are not attributed to test classes, so the merged column is "executed at all", the unit column is "executed by a test aimed at it".

### Numbers

State: 2026-09-29 (after the final backend fixes), PHP 8.5 with pcov, Grav 2.2.2 test site. `classes/` has 12,420 statement lines. The merged column comes from an integration run with `RM_COVERAGE` and `--testsuite integration` in full; the two `ApiPerformanceTest` tests fail in such a run because pcov in the server slows every request past the budgets, so use `--exclude-group benchmark` when you only want the coverage.

| Directory | Unit (strict) | Unit + integration |
|---|---|---|
| Analysis | 98.61 % | 98.80 % |
| Api | 100.00 % | 100.00 % |
| App | 96.49 % | 98.04 % |
| App/Exception | 100.00 % | 100.00 % |
| Auto | 97.58 % | 97.82 % |
| Check | 97.79 % | 97.79 % |
| Cli | 97.17 % | 98.20 % |
| Domain | 100.00 % | 100.00 % |
| Grav | 82.82 % | 96.94 % |
| ImportExport | 97.80 % | 98.43 % |
| ImportExport/Adapter | 98.24 % | 98.30 % |
| ImportExport/Support | 98.60 % | 98.60 % |
| Jobs | 97.65 % | 97.65 % |
| Matching | 98.78 % | 99.39 % |
| NotFound | 98.30 % | 98.30 % |
| Notify | 97.22 % | 97.57 % |
| Security | 99.28 % | 99.28 % |
| Stats | 100.00 % | 100.00 % |
| Storage | 95.97 % | 96.22 % |
| Suggest | 98.57 % | 98.57 % |
| Util | 96.15 % | 96.15 % |
| **classes/** | **96.71 %** (12,011) | **98.23 %** (12,200) |

The unit number for `Grav/` is low because `GravPageIndexBuilder` (about 140 lines) walks the real page tree and has no unit test; the integration suite (`tests/Integration/PageIndexTest.php`) covers it. Without it the unit coverage of `classes/` is above 97 %.

### What is unit tested against stand-ins, what is left to integration

| Class | Unit | Integration |
|---|---|---|
| `Auto/AutoRedirectListener`, `Auto/PageSnapshotter` | 100 %, fake pages and a real `ServiceFactory` over a temp dir | real page events of the API plugin |
| `Grav/GravBootstrap`, `GravConfigWriter`, `PageIndexCache`, `RuleEvents`, `SchedulerJobs`, `TwigExtension`, `ResponseData` | >= 97 % against `Config`/`Locator`/`Scheduler` stand-ins and temp dirs | `RuleEventsTest`, `SchedulerJobsTest`, `DigestMailTest` |
| `Api/*` (7 controllers) | 100 %: stub `AbstractApiController` and `ApiResponse`, the real `RedirectService` behind the controller | `Api*Test`: auth, JSON envelope, permissions over HTTP |
| `Grav/GravPageIndexBuilder` | none | `PageIndexTest` |
| `redirect-manager.php` (plugin class) | none, not part of `classes/` | everything in `tests/Integration` |

## Conventions

PHPUnit 11 attributes (`#[CoversClass]`, `#[DataProvider]`, `#[Group]`), one test class per service, `declare(strict_types=1)`, temp dirs under `sys_get_temp_dir()` removed in `tearDown()`, time from `Util\FixedClock`. Integration tests extend `IntegrationTestCase` and copy the test site per class; see "Integration tests" in `docs/ARCHITECTURE.md`.
