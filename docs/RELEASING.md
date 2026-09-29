# Releasing

A release is a git tag `vX.Y.Z` on a commit whose `blueprints.yaml` says `version: X.Y.Z`. GitHub Actions builds the ZIP, tests it against a clean Grav 2.2.2 and attaches it to a GitHub release.

## Cut a release

1. Make sure `main` is green (`tests` workflow: PHP 8.3/8.4/8.5, Admin 2 bundles, Playwright, release ZIP).
2. Bump `version:` in `blueprints.yaml`.
3. Add a section to `CHANGELOG.md` in Grav's format: a `# vX.Y.Z` heading, a `## MM/DD/YYYY` line below it, then items like `1. [](#new)` with `    * ...` lines. Its text becomes the release notes, without the date line. The older `## X.Y.Z - YYYY-MM-DD` heading also works. The build fails without a section for the version.
4. If `admin2/` changed, run `npm run build` in `admin2/` and commit `admin-next/` and `languages.yaml` (the build also compiles the UI strings into it). The build and CI compare the committed files with a fresh build and fail on any difference.
5. Build and test locally (see below).
6. Commit, then tag and push:

   ```bash
   git tag vX.Y.Z
   git push origin main vX.Y.Z
   ```

The `release` workflow then:

1. checks that the tag (`v1.2.3` gives `1.2.3`) equals the version in `blueprints.yaml`,
2. cuts the release notes out of `CHANGELOG.md` (`scripts/changelog-section.sh`),
3. runs `scripts/build-release.sh`,
4. runs `scripts/test-release.sh` against the ZIP,
5. creates the GitHub release with `grav-plugin-redirect-manager-X.Y.Z.zip` and its `.sha256`. A version with a hyphen (`1.0.0-rc.1`) is marked as a pre-release.

## What CI runs on every push and pull request

| Job | Content |
|---|---|
| `php` (8.3, 8.4, 8.5) | `composer validate --strict`, `composer cs`, `scripts/setup-test-site.sh`, `composer stan`, unit tests (`composer coverage` with pcov on 8.3: fails below 90 %), integration suite with `RM_PHP_BIN` set. The `.grav` test site is cached by Grav version and setup script hash. |
| `ui` | Node 22, `npm ci`, `npm test`, `npm run build` in `admin2/`, then `git diff --exit-code admin-next languages.yaml` (also fails on new untracked files in `admin-next/`). |
| `playwright` | Runs only when `tests/ui/playwright.config.ts` exists. Sets up and serves the test site on port 8100, runs `npx playwright test` in `tests/ui`, uploads the report on failure. The specs get `RM_BASE_URL`, `RM_SITE_DIR` and `RM_CREDENTIALS_FILE` from the workflow. |
| `release-check` | Builds the ZIP for the version in `blueprints.yaml` and runs `scripts/test-release.sh`. |

## Build the ZIP

```bash
scripts/build-release.sh 1.0.0
```

Result: `dist/grav-plugin-redirect-manager-1.0.0.zip` and `.sha256` (`dist/` is gitignored). The script stops at the first problem and never changes the working tree:

1. The version is semver, equals `blueprints.yaml`, and `CHANGELOG.md` has its section.
2. `composer validate --strict`.
3. The `admin-next` bundles and `languages.yaml` are up to date. `npm run build` of `admin2/` runs in a temp copy (`npm ci` there when `admin2/node_modules` is missing) and the sha256 of `admin-next/pages/redirect-manager.js`, `admin-next/widgets/redirect-manager.js`, `admin-next/panels/redirect-manager.js` and `languages.yaml` is compared with the committed files. `--no-bundle-check` skips this for local experiments; never publish such a ZIP.
4. A staging directory gets the runtime files, and `vendor/` from `composer install --no-dev --classmap-authoritative` run in a scratch copy, so the dev `vendor/` of the repository stays untouched.
5. `php -l` on every PHP file (with `RM_PHP_BIN`, default `php`; use 8.3), the staged autoloader must resolve the plugin classes.
6. The ZIP is checked: top folder `redirect-manager/` only, first entry is the folder itself, none of `tests/`, `admin2/`, `node_modules/`, `.grav/`, `scripts/`, `build/`, `composer.json`/`composer.lock`, dotfiles, and no `docs/` other than `docs/openapi.yaml`. No root `*.yaml` other than `blueprints.yaml`, `languages.yaml` and `redirect-manager.yaml` (see below); `config/permissions.yaml` and `config/mcp.yaml` must be there. `vendor/` may hold only `autoload.php` and `composer/`, with no package in `installed.json`.

Content: `redirect-manager.php`, `redirect-manager.yaml`, `blueprints.yaml` and `languages.yaml` from the repository root, `config/` (`permissions.yaml`, `mcp.yaml`), `classes/`, `cli/`, `templates/`, `admin-next/`, `vendor/`, `README.md`, `CHANGELOG.md`, `LICENSE`, `docs/openapi.yaml` (`languages/` and `assets/` too, once they hold files). File times are fixed (`SOURCE_DATE_EPOCH`, default 2026-01-01 UTC) and entries sorted, so the same tree gives the same ZIP.

## Test the ZIP

```bash
scripts/test-release.sh                     # builds the ZIP if dist/ has none for the current version
scripts/test-release.sh dist/grav-plugin-redirect-manager-1.0.0.zip
scripts/test-release.sh --manual            # install by unzipping into user/plugins
RM_PHP_BIN=/opt/homebrew/opt/php@8.4/bin/php scripts/test-release.sh
```

The script downloads `grav-admin-v2.2.2.zip` to `.grav/` when it is missing and runs the phpunit group `release` (`tests/Integration/ReleaseTest.php`). The test skips itself unless `RM_RELEASE_ZIP` is set, so a plain `composer integration` never runs it. It unpacks a fresh Grav into a temp directory (nothing of `.grav/2.2.2`, which has the plugin symlinked) and starts `php -S` on a port from `RM_PORT_RANGE` (default 8400-8499). The steps, in order:

1. Clean site: pages load, an API user is created. Warnings that a clean Grav already logs are recorded as the baseline.
2. Install with `bin/gpm direct-install <zip> -y`. Asserts the folder is `user/plugins/redirect-manager` and holds exactly the files of the ZIP.
3. Frontend: home and other pages return 200, `/admin` does not fail, a missing page is a 404 and lands in the 404 log.
4. REST API: unauthenticated call is 401; reads of rules, stats, 404s, analysis, groups, suggestions, import formats and export; the six MCP tools of `GET /mcp/tools` without warnings; a rule created through the API redirects on the frontend.
5. Every `cli/*Command.php` runs once (`bin/plugin redirect-manager <command>`). A command without a check in the test fails the test, so new commands must be added there.
6. Scheduler: `bin/grav scheduler --jobs` lists the jobs, each `redirect-manager-*` job runs with `--run=<id>`, and the maintenance job folds the recorded hit into the rule statistics.
7. Uninstall with `bin/gpm uninstall redirect-manager -y`: the plugin folder is gone, `user/data/redirect-manager/rules.yaml` is still there, pages still load, the rule redirect and the API routes are gone (404, no 500), the scheduler lists no plugin job.
8. Reinstall: the kept rule redirects again.

After every step the test reads what `logs/grav.log` and the PHP server log got since the last step. A new warning, notice, error or deprecation line, a PHP error marker or a 5xx response fails the step, and the message names it. Grav INFO lines (job results) are fine. The temp directory is deleted at the end.

## Installing the ZIP: what GPM really does

`bin/gpm direct-install <zip> -y` accepts a local path as well as a URL. GPM takes the folder name from the first entry of the ZIP (which is why the build keeps the `redirect-manager/` directory entry first) and the **package name from the first `*.yaml` file in the ZIP root**, apart from `blueprints.yaml` and `languages.yaml` (`GPM::getPackageName()`). GPM 2.2.2 therefore installs a ZIP with `mcp.yaml` or `permissions.yaml` in the root as `user/plugins/mcp` or `.../permissions`. This ZIP keeps only `blueprints.yaml`, `languages.yaml` and `redirect-manager.yaml` there; permissions and the MCP manifest are in `config/` (docs/DECISIONS.md D-024), and `scripts/build-release.sh` fails when any other root yaml appears. Never add a yaml file to the plugin root. Both install variants (`bin/gpm direct-install` and `--manual`) are required checks in CI.

Install without that problem: unzip into `user/plugins/` and clear the cache.

```bash
unzip grav-plugin-redirect-manager-1.0.0.zip -d user/plugins/
bin/grav clearcache
```

## Uninstall keeps the data

`bin/gpm uninstall redirect-manager` removes `user/plugins/redirect-manager` and clears the Grav cache. It leaves alone:

- `user/data/redirect-manager/`: rules, statistics, the 404 log, suggestions and state files,
- `user/config/plugins/redirect-manager.yaml`, if the settings were ever saved,
- `cache/redirect-manager/`, the compiled rule cache. GPM clears only `cache/grav` and `cache/compiled`. Nothing reads the leftover files; delete the folder if you like.

Reinstalling the plugin picks all of it up again. Whoever wants the data gone deletes the directory. Dependencies: `blueprints.yaml` names only `grav` and `php`, which GPM skips, so uninstalling never pulls other plugins out, and it is refused only when another installed package declares a dependency on `redirect-manager` (none does). Without the plugin, redirects stop and the site keeps running: the release test checks that pages, the admin route and the scheduler still work.

## Files

| File | Purpose |
|---|---|
| `scripts/build-release.sh` | Builds and checks the ZIP |
| `scripts/test-release.sh` | Driver of the release test |
| `scripts/changelog-section.sh` | Prints the CHANGELOG section of a version (release notes) |
| `tests/Integration/ReleaseTest.php`, `tests/Integration/Support/ReleaseSite.php`, `ProcessRunner.php` | The release test |
| `.github/workflows/tests.yml`, `release.yml` | CI and release |
