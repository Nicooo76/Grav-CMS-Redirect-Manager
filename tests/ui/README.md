# UI tests (Playwright)

End-to-end tests of the Redirect Manager pages in Admin 2: real browser, real Grav 2, real API plugin, real Admin 2 login. Nothing is mocked.

## Run

```bash
scripts/setup-test-site.sh                 # once, from the repository root: downloads Grav into .grav/2.2.2 and links the plugin in
cd tests/ui
npm ci && npx playwright install chromium
npx playwright test                        # everything
npx playwright test rules-crud             # one spec
npx playwright test --grep-invert @visual  # without the screenshot comparison
npx playwright test --ui                   # Playwright's UI mode
```

Needs PHP 8.3 or newer with the extensions Grav needs (`RM_PHP_BIN` selects another binary), Node 22 and the Grav test site.

## What a run does

`global-setup.ts` builds a **fresh Grav site** in a temp directory for every run: `system/`, `vendor/`, `bin/` and the plugins are symlinked to the base site (`.grav/2.2.2`, made by `scripts/setup-test-site.sh`), `user/config`, `user/pages`, `user/accounts` and the theme are copied. Then it

1. starts `php -S` on a free port from 8400 to 8499 (`RM_PORT_RANGE` changes the range) (four PHP workers, `PHP_CLI_SERVER_WORKERS=4`),
2. creates two accounts with generated passwords: `rmadmin` (super admin) and `rmreader` (`api.access` + `api.redirects.read`). The passwords exist only in a `0600` JSON file in the temp directory (or in `RM_CREDENTIALS_FILE`),
3. seeds the data: the rules come from `fixtures/seed-rules.json` through `bin/plugin redirect-manager import`, plus one loop rule (hand-written into `rules.yaml`, the importer refuses loops) and one conflicting rule (API). Hits and 404 entries are real HTTP requests with fixed paths and user agents (`support/seed.ts`). `bin/grav scheduler --run=redirect-manager-maintenance` folds the hits into the statistics, `bin/plugin redirect-manager suggest --no-accept` generates suggestions,
4. logs in through the Admin 2 login form once per account and saves the browser state (`auth-admin.json`, `auth-readonly.json`),
5. copies the seeded data files aside. Before **every test** the `seeded` fixture (`support/test.ts`) restores rules, 404 log, suggestions, statistics, pages and the plugin configuration from that copy. Tests are independent and can run in any order.

`global-teardown.ts` stops the server and removes the temp directory. The API plugin's rate limit (120 requests per minute) is switched off in the test site, otherwise a run trips it.

The tests run with one worker: they change the state of one shared site.

## Environment variables

| Variable | Default | Meaning |
|---|---|---|
| `RM_SITE_DIR` | `<repo>/.grav/2.2.2` | Base Grav site that is copied for the run. It is never modified. |
| `RM_GRAV_VERSION` | `2.2.2` | Version folder under `.grav/`. |
| `RM_PHP_BIN` | `php` | PHP binary for the server and the CLI calls. |
| `RM_PORT` | random in the range | Fixed port for the test server. |
| `RM_PORT_RANGE` | `8400-8499` | Range the random port is taken from, e.g. `8600-8699`. |
| `RM_PHP_WORKERS` | `4` | `PHP_CLI_SERVER_WORKERS` of the test server. |
| `RM_CREDENTIALS_FILE` | temp directory | Where the generated passwords are written (mode 0600, removed at the end). |
| `RM_KEEP_SITE` | unset | `1`: keep the temp site after the run (the server is stopped), for looking at logs and data. |
| `RM_OUTPUT_DIR` | `test-results` | Playwright's output directory. Give parallel runs different ones. |
| `RM_VISUAL` | auto | `1` forces the screenshot tests, `0` skips them, unset runs them when baselines for the platform exist. |

`RM_BASE_URL` (used by an earlier draft of the CI job that started the site itself) is not needed: the setup starts its own server.

## Specs

`specs/*.spec.ts`, one file per area: rules-crud, bulk, filters, reorder, editor-validation, monitor-404, suggestions, import, tester, keyboard, readonly, pending, widget, visual, a11y, and the audit follow-ups:

| Spec | What it drives |
|---|---|
| `page-panel` | the Redirects context panel of Admin 2's page editor: toolbar button and badge, the lists, "Mark as seen", the warning for a page that is redirected away, adding an old URL (checked while typing, 301 on the public site), "Open in Redirect Manager", axe in light and dark. `RM_SHOTS=1 npx playwright test page-panel -g screenshot` rewrites `docs/screenshots/page-panel-*.png` |
| `admin2-page-editor` | Admin 2's own page editor (folder name, Delete Page) and what the plugin does with it: unseen panel, sidebar badge, 301 on the public site, pending panel |
| `german` | the admin user's language is German: every tab, the editor, the pending panel and the widget; no raw keys, English fallbacks or humanized keys anywhere in the shadow DOM |
| `tablet` | 768 x 1024 and 1024 x 768: collapsed columns, full-width editor, no horizontal page scroll, every tab, axe |
| `large-list` | 10,000 rules (CLI import): virtual window, scrolling to the end, frame times, search, filter and sort speed |
| `rollback` | `page.route` holds the API answer and then fails it (500, 409): inline edit, switch, bulk, reorder, delete, suggestions, 404 monitor |
| `import-advanced` | a real `drop` on the drop zone, manual CSV column mapping, Grav's `site.redirects` and `site.routes` panel |
| `editor-page-target` | target type "Page" with the search picker, the rule following a renamed page, the status code help |
| `badges-check` | row badges, "Check now" against the site itself, the dead target badge, the widget after a check (tile, sparklines) |
| `views` | 404 row sparkline, column chooser, "Export selected", empty states, skeleton loaders, slide-over motion |
| `host-tokens` | computed styles of the plugin equal the host's CSS variables (light, dark, accent and font change), Lucide icons |

Helpers are in `support/`:

- `test.ts` extends Playwright's `test` with the fixtures `app` (the page: `goto`, `root`, `rows`, `row(source)`, `editor`, `toast()`, `setTheme()`), `api` (REST client with the JWT of the current user), `site` (`reset()`, `setConfig()`, `cli()`, `get()`), `asUser` (`'admin'` or `'readonly'`) and the automatic `seeded`.
- `app.ts`: the plugin page is a custom element (`grav-redirect-manager--page`) with an open shadow root; Playwright's locators pierce it. Toasts and confirm/form dialogs belong to Admin 2 and sit outside the shadow root.
- `i18n-scan.ts`: every text of the shadow DOM, and the leak check for the German tests (raw keys, English strings, humanized keys). `spark.ts`: the sparkline formula, written down again for the tests.
- `seed.ts`, `site.ts`: site builder and seed data. The numbers in the specs (32 rules, 7 suggestions, 45 404 hits, ...) come from there.

Rules for new tests: no `waitForTimeout`, arrange state through the API, verify state through the API as well as the UI, never depend on relative times ("now", "2 minutes ago"). What the `seeded` fixture does not restore has to be put back by the test itself, in `afterEach` or `finally`: the admin account's preferences (language, accent, font, colour mode: `DELETE /admin-next/preferences/user`), `user/config/site.yaml` (and `cache/compiled` after writing it, Grav's compiled configuration is keyed by file times with second resolution).

## Screenshot tests (`@visual`)

`visual.spec.ts` compares the main screens in light and dark with `toHaveScreenshot` (`maxDiffPixelRatio` 0.005, relative times and the 404 trend chart masked). Baselines are per platform in `specs/__screenshots__/<platform>/visual.spec.ts/`, because font rendering differs between macOS and Linux. The committed baselines are the **darwin** ones.

- Without baselines for the current platform the visual tests are **skipped** (Linux CI today).
- Update or create baselines: `RM_VISUAL=1 npx playwright test --grep @visual --update-snapshots`, look at the diff, commit.
- Linux baselines: run the `tests` workflow by hand (Actions, "Run workflow", tick the baselines box). It uploads `visual-baselines-linux`; unpack it into `tests/ui/specs/__screenshots__/linux/` and commit. From then on CI compares them. **No Linux baselines are committed yet.**
- Linux baselines on a machine with Docker (not run yet: the Docker Desktop engine of the machine that wrote this was down and could not be started from the session, so treat the recipe as unverified). The image must match `@playwright/test` (1.63.0), PHP comes from apt, the base site is a copy of `.grav/2.2.2` whose `user/plugins/redirect-manager` is a real directory, not the symlink (the link target does not exist in the container):

  ```bash
  docker run --rm --ipc=host -v "$PWD":/work -v /path/to/base-site-copy:/site-base:ro \
    -e RM_SITE_DIR=/site-base -e RM_VISUAL=1 -e RM_OUTPUT_DIR=/tmp/pw-out -w /work/tests/ui \
    mcr.microsoft.com/playwright:v1.63.0-noble bash -c '
      apt-get update -qq && apt-get install -y -qq php8.3-cli php8.3-curl php8.3-gd php8.3-mbstring php8.3-xml php8.3-zip php8.3-intl php8.3-sqlite3 &&
      npx playwright test --grep @visual --update-snapshots && npx playwright test --grep @visual'
  ```

  Look at the 14 PNGs in `specs/__screenshots__/linux/visual.spec.ts/` before committing, and run the CI workflow once to see that the runner's fonts give the same pixels (fallback glyphs such as arrows and quotes can differ between the image and `ubuntu-24.04`).
- Baselines change whenever the UI changes on purpose. Regenerate them in the same commit as the UI change.

## Accessibility (`a11y.spec.ts`)

axe-core over every screen in both themes (rules with filter panel and selection, editor for a new and an existing rule, 404 monitor, suggestions, tester, import, export, settings, dashboard widget), WCAG 2.0 to 2.2 A/AA plus best practices. `serious` and `critical` violations fail. The scan covers the plugin's own element only. On the settings screen Admin 2's blueprint renderer (`grav-blueprint-form`) is excluded: its list fields and one select have no accessible names, which the plugin cannot fix.

The chain-warning contrast finding (4.25 instead of 4.5) is fixed (`.chain` uses `--rm-muted-fg`), the scan has no filter any more.

## Product findings the suite made (all fixed, kept as a record)

- Focus was not returned to the trigger after the editor closed. Fixed in `Slideover.svelte` (the list behind was still `inert` when the focus call ran; `keyboard.spec.ts`).
- The editor preview showed "Checking..." for good when the API answered `preview.result: null`. It shows "No match" with the reason now (`editor-validation.spec.ts`).
- 404 monitor "Create redirect" for case-only suggestions (`/shop/Zelte`) failed with 422 `self_redirect`. The editor prefill sets `case_sensitive`, the error says "differs only in letter case" and offers a one-click fix (`monitor-404.spec.ts`, `editor-validation.spec.ts`).
- The dashboard widget was broken for non-super users: `authorize` was an array, the API plugin's dashboard code needs a string (`widget.spec.ts`, `ApiSystemTest::testDashboardWidgetsFollowThePermission`).
- The toast after Ignore counted log entries, not paths. `POST /redirects/404/ignore` answers `purged_paths` besides `purged` now, the toast uses the paths (`monitor-404.spec.ts`).
- Wrong UI text `IMPORTEXPORT.SITE_TEXT` (Grav's `site.redirects` do not run before the plugin; they run only when no plugin rule matched and no routable page exists).
- Open, upstream: Admin 2's own confirm and form dialogs have no `role="dialog"` (`docs/GRAV2-NOTES.md`, Appendix B); `hostDialog()` finds them by heading.

## Debugging

`npx playwright show-trace test-results/<test>/trace.zip` (traces are kept on failure), `npx playwright test --ui`, `RM_KEEP_SITE=1` and then `logs/grav.log` and `logs/server.log` in the kept site. A failed run prints the paths.
