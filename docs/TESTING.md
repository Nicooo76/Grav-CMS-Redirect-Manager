# Testing

Three layers, each answers a different question.

| Suite | Needs | Answers |
|---|---|---|
| Unit (`tests/Unit`, ~4300 tests, ~25 s) | `vendor/` only | Does each class do what it says, without Grav? |
| Integration (`tests/Integration`, ~410 tests, ~7 min) | a Grav test site in `.grav/`, `curl`, `php -S` | Does the plugin work inside real Grav 2 over real HTTP and CLI? |
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

Groups (`#[Group]`) cut across both suites: `--group benchmark` runs the matcher and analysis benchmarks (`tests/Unit/Matching/MatcherBenchmarkTest.php`, `tests/Unit/Analysis/AnalysisPerformanceTest.php`; they are part of the unit suite too, `--exclude-group benchmark` skips them), `--group performance` the suggester timing test, `--group release` the release-package checks (`scripts/build-release.sh`, `scripts/test-release.sh`, see `docs/RELEASING.md`; only present when those tests exist). Others: `api`, `app`, `auto`, `cli`, `grav`, `import`, `jobs`, `notfound`, `scheduler`, `stats`, `storage`, `integration`.

Unit tests run on PHP 8.3, 8.4 and 8.5: `/opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit --testsuite unit`. PHPUnit is configured `failOnWarning`, `failOnRisky`, `failOnDeprecation`.

UI suite (once: `scripts/setup-test-site.sh`, as above):

```bash
cd tests/ui
npm ci && npx playwright install chromium
npx playwright test                        # builds a fresh site in a temp dir, seeds it, runs every spec
npx playwright test --grep-invert @visual  # without the screenshot comparison
```

Every run builds its own copy of the test site, starts `php -S` on a port from 8400 to 8499, creates an admin and a read-only user with generated passwords, seeds rules, hits, 404 entries and suggestions, and restores that state before every test. The base site is never modified. Screenshot baselines are per platform (`tests/ui/specs/__screenshots__/<platform>/`); the committed ones are darwin. Without baselines for the platform the `@visual` tests are skipped, so CI (Linux) runs them only after the Linux baselines were generated with the manually started `tests` workflow ("Run workflow", tick the baselines box) and committed. Details, environment variables (`RM_SITE_DIR`, `RM_PHP_BIN`, `RM_PORT`, `RM_VISUAL`, ...) and how to update baselines: `tests/ui/README.md`.

### Environment variables

| Variable | Used by | Meaning |
|---|---|---|
| `RM_PHP_BIN` | integration | PHP binary for the test site's `php -S`, `bin/plugin` and `bin/grav` children. Default: the PHP that runs PHPUnit. Also read by `scripts/setup-test-site.sh`. |
| `RM_GRAV_BASE` | integration | Base site directory. Default `.grav/<RM_GRAV_VERSION>`. |
| `RM_GRAV_VERSION` | integration | Grav version under `.grav/`. Default `2.2.2`. |
| `RM_PORT_RANGE` | integration | `min-max`, ports the test servers pick from (default `8100-8999`). Set different ranges when several runs share a machine. |
| `RM_COVERAGE` | integration | A directory: turns on line coverage of the test site's PHP children (see "Integration coverage"). Relative paths are relative to the repository. |
| `RM_COVERAGE_DIR` | internal | Set by the test code from `RM_COVERAGE` for the children. Do not set it yourself. |

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

State: 2026-09-29, PHP 8.5 with pcov, Grav 2.2.2 test site. `classes/` has 11,691 statement lines.

| Directory | Unit (strict) | Unit + integration |
|---|---|---|
| Analysis | 98.69 % | 98.78 % |
| Api | 100.00 % | 100.00 % |
| App | 96.54 % | 98.21 % |
| App/Exception | 100.00 % | 100.00 % |
| Auto | 97.57 % | 97.81 % |
| Check | 97.79 % | 97.79 % |
| Cli | 96.90 % | 96.90 % |
| Domain | 100.00 % | 100.00 % |
| Grav | 78.94 % | 97.42 % |
| ImportExport | 97.80 % | 98.11 % |
| ImportExport/Adapter | 98.21 % | 98.27 % |
| ImportExport/Support | 98.60 % | 98.60 % |
| Jobs | 97.65 % | 97.65 % |
| Matching | 98.78 % | 99.39 % |
| NotFound | 98.09 % | 98.09 % |
| Notify | 97.22 % | 97.57 % |
| Security | 99.28 % | 99.28 % |
| Stats | 100.00 % | 100.00 % |
| Storage | 95.58 % | 95.87 % |
| Suggest | 98.57 % | 98.57 % |
| Util | 98.25 % | 98.25 % |
| **classes/** | **96.76 %** (11,312) | **98.26 %** (11,487) |

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
