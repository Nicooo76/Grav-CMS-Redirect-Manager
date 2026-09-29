# Redirect Manager for Grav 2

Redirects for Grav 2.2: exact, wildcard and regex rules, a 404 monitor with target suggestions, automatic redirects when pages move or are deleted, import and export for common formats, a CLI, a REST API, MCP tools and an Admin 2 interface.

Requires Grav 2.1.5 or newer and PHP 8.3 or newer. The API plugin and Admin 2 are optional: without them the frontend redirects, the 404 log and the CLI still work.

## Install

Download `grav-plugin-redirect-manager-<version>.zip` from the GitHub releases page, unzip it into `user/plugins/` and clear the cache:

```bash
unzip grav-plugin-redirect-manager-<version>.zip -d user/plugins/
bin/grav clearcache
```

`bin/gpm direct-install grav-plugin-redirect-manager-<version>.zip -y` works as well and installs into `user/plugins/redirect-manager`.

Then open the plugin settings in Admin 2, or edit `user/config/plugins/redirect-manager.yaml`. The defaults are in the plugin's `redirect-manager.yaml`.

## Uninstall

```bash
bin/gpm uninstall redirect-manager
```

This removes the plugin folder and keeps your data. Rules, hit statistics, the 404 log and suggestions stay in `user/data/redirect-manager/`, and your settings stay in `user/config/plugins/redirect-manager.yaml`. Reinstalling picks them up again. Delete the data directory yourself if you want it gone. While the plugin is not installed, no redirect happens and the site keeps running.

## Use

- **Admin 2**: Redirects page with rules, editor, 404 monitor, suggestions, URL tester, import/export and settings, plus a dashboard widget.
- **CLI**: `bin/plugin redirect-manager <command>` with `add`, `rules`, `enable`, `disable`, `remove`, `test`, `stats`, `import`, `export`, `suggest`, `check-targets`, `prune` and `rebuild-cache`. `--help` on a command lists its options.
- **Scheduler**: three jobs (maintenance, target check, e-mail digest). List them with `bin/grav scheduler --jobs`.
- **REST API**: under `/api/v1/redirects`, see [docs/API.md](docs/API.md) and [docs/openapi.yaml](docs/openapi.yaml).
- **Permissions**: `api.redirects.read` and `api.redirects.manage`.

## Documentation

- [docs/API.md](docs/API.md): REST API
- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md): request pipeline, data files, rule model
- [docs/DECISIONS.md](docs/DECISIONS.md): assumptions and choices
- [docs/RELEASING.md](docs/RELEASING.md): building and testing the release ZIP

## Development / releasing

Tests, CI and the release process are in [docs/RELEASING.md](docs/RELEASING.md). Short version: `composer test:all` runs code style, static analysis, unit and integration tests; `scripts/build-release.sh <version>` builds the ZIP; `scripts/test-release.sh` installs it into a clean Grav 2.2.2, exercises it and uninstalls it again.

## License

MIT, see [LICENSE](LICENSE).
