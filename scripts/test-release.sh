#!/usr/bin/env bash
# Installs the release ZIP into a clean Grav 2.2.2, exercises it and uninstalls it again.
#
#   scripts/test-release.sh [zip]            default: dist/grav-plugin-redirect-manager-<blueprints version>.zip,
#                                            built with scripts/build-release.sh when missing
#   scripts/test-release.sh --manual [zip]   install by unzipping into user/plugins instead of bin/gpm direct-install
#
# The work is done by tests/Integration/ReleaseTest.php (phpunit group "release"), which unpacks a fresh copy of
# grav-admin into a temp directory (nothing of .grav/2.2.2 is reused), installs the ZIP, requests pages, calls the
# REST API, runs every CLI command and scheduler job, uninstalls the plugin and checks that the site still works,
# that the data directory user/data/redirect-manager is kept, and that the logs stay free of warnings, errors and
# deprecations after every step. The temp directory is removed at the end.
#
# The grav-admin ZIP is taken from .grav/grav-admin-v<version>.zip, or downloaded there (same URL as
# scripts/setup-test-site.sh).
#
# Environment: RM_PHP_BIN (PHP for the server, bin/gpm, bin/plugin, bin/grav and phpunit; default: php),
#              RM_PORT_RANGE (default 8400-8499), RM_GRAV_VERSION (default 2.2.2), RM_GRAV_ZIP,
#              RM_RELEASE_INSTALL (gpm or manual, what --manual sets).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="${RM_PHP_BIN:-php}"
GRAV_VERSION="${RM_GRAV_VERSION:-2.2.2}"
INSTALL="${RM_RELEASE_INSTALL:-gpm}"
ZIP=""

for arg in "$@"; do
    case "$arg" in
        --manual) INSTALL="manual" ;;
        -h|--help) sed -n '2,19p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
        -*) echo "Unknown option: $arg" >&2; exit 2 ;;
        *) ZIP="$arg" ;;
    esac
done

cd "$ROOT"
command -v "$PHP_BIN" >/dev/null 2>&1 || [ -x "$PHP_BIN" ] || { echo "PHP binary not found: $PHP_BIN" >&2; exit 1; }
export RM_PHP_BIN="$(command -v "$PHP_BIN" || echo "$PHP_BIN")"
export RM_PORT_RANGE="${RM_PORT_RANGE:-8400-8499}"
export RM_RELEASE_INSTALL="$INSTALL"

if [ -z "$ZIP" ]; then
    VERSION="$(sed -n 's/^version:[[:space:]]*//p' blueprints.yaml | head -n 1 | tr -d "\"' \t")"
    ZIP="dist/grav-plugin-redirect-manager-$VERSION.zip"
    if [ ! -f "$ZIP" ]; then
        echo "== $ZIP is missing, building it"
        scripts/build-release.sh "$VERSION"
    fi
fi
[ -f "$ZIP" ] || { echo "No such ZIP: $ZIP" >&2; exit 1; }
export RM_RELEASE_ZIP="$(cd "$(dirname "$ZIP")" && pwd)/$(basename "$ZIP")"

GRAV_ZIP="${RM_GRAV_ZIP:-$ROOT/.grav/grav-admin-v$GRAV_VERSION.zip}"
if [ ! -f "$GRAV_ZIP" ]; then
    mkdir -p "$(dirname "$GRAV_ZIP")"
    URL="https://github.com/getgrav/grav/releases/download/$GRAV_VERSION/grav-admin-v$GRAV_VERSION.zip"
    echo "== Downloading $URL"
    curl -fL --retry 3 --silent --show-error -o "$GRAV_ZIP.part" "$URL"
    mv "$GRAV_ZIP.part" "$GRAV_ZIP"
fi
export RM_GRAV_ZIP="$GRAV_ZIP"

if [ ! -x vendor/bin/phpunit ]; then
    echo "== composer install"
    composer install --no-interaction --quiet
fi

echo "== Release test"
echo "PHP:     $("$RM_PHP_BIN" -r 'echo PHP_VERSION;') ($RM_PHP_BIN)"
echo "ZIP:     $RM_RELEASE_ZIP"
echo "Grav:    $GRAV_VERSION ($GRAV_ZIP)"
echo "Install: $INSTALL"
exec "$RM_PHP_BIN" vendor/bin/phpunit --testsuite integration --group release --testdox
