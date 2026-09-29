#!/usr/bin/env bash
# Sets up a Grav test site for the integration suite.
#
#   scripts/setup-test-site.sh [base-dir] [grav-version] [--multilang]
#
# Downloads grav-admin-v<version>.zip from the GitHub releases into <base-dir>/<version> (default
# .grav/2.2.2, gitignored) unless it exists, links this plugin into user/plugins, and creates an API
# test user. The generated password goes to <base-dir>/credentials.env (mode 600) and is never printed.
# RM_PHP_BIN selects the PHP binary (default: php).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="${RM_PHP_BIN:-php}"
BASE_DIR="$ROOT/.grav"
VERSION="2.2.2"
MULTILANG=0
ARGS=()

for arg in "$@"; do
    case "$arg" in
        --multilang) MULTILANG=1 ;;
        -h|--help) sed -n '2,10p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) ARGS+=("$arg") ;;
    esac
done
[ "${#ARGS[@]}" -ge 1 ] && BASE_DIR="${ARGS[0]}"
[ "${#ARGS[@]}" -ge 2 ] && VERSION="${ARGS[1]}"

SITE="$BASE_DIR/$VERSION"
ZIP="$BASE_DIR/grav-admin-v$VERSION.zip"
URL="https://github.com/getgrav/grav/releases/download/$VERSION/grav-admin-v$VERSION.zip"
CREDENTIALS="$BASE_DIR/credentials.env"

mkdir -p "$BASE_DIR"

if [ ! -f "$SITE/index.php" ]; then
    if [ ! -f "$ZIP" ]; then
        echo "Downloading $URL"
        curl -fL --retry 3 --silent --show-error -o "$ZIP.part" "$URL"
        mv "$ZIP.part" "$ZIP"
    fi
    TMP="$(mktemp -d "$BASE_DIR/unpack.XXXXXX")"
    unzip -q "$ZIP" -d "$TMP"
    rm -rf "$SITE"
    mv "$TMP/grav-admin" "$SITE"
    rm -rf "$TMP"
    echo "Unpacked Grav $VERSION to $SITE"
fi

# The plugin needs its own vendor directory (autoloader).
if [ ! -f "$ROOT/vendor/autoload.php" ]; then
    (cd "$ROOT" && composer install --no-interaction --quiet)
fi

mkdir -p "$SITE/user/plugins"
ln -sfn "$ROOT" "$SITE/user/plugins/redirect-manager"

if [ "$MULTILANG" = "1" ]; then
    mkdir -p "$SITE/user/config"
    cat > "$SITE/user/config/system.yaml" <<'YAML'
languages:
  supported: [en, de]
  default_lang: en
  include_default_lang: true
YAML
fi

# API test user (password generated once, kept in credentials.env).
if [ ! -f "$SITE/user/accounts/rmtest.yaml" ]; then
    PASSWORD="$(openssl rand -hex 12)Aa1"
    (
        umask 077
        printf 'RM_API_USER=rmtest\nRM_API_PASSWORD=%s\n' "$PASSWORD" > "$CREDENTIALS"
    )
    if ! (cd "$SITE" && "$PHP_BIN" bin/plugin login new-user -n \
        -u rmtest -p "$PASSWORD" -e rmtest@example.invalid -P a --admin-type=api \
        -N "RM Test" -t "Test" -s enabled > "$BASE_DIR/new-user.log" 2>&1); then
        echo "Creating the API test user failed, see $BASE_DIR/new-user.log" >&2
        exit 1
    fi
    echo "Created API test user rmtest (credentials in $CREDENTIALS)"
fi

echo "Test site ready: $SITE"
