#!/usr/bin/env bash
# Builds the release ZIP that GPM (bin/gpm direct-install) installs.
#
#   scripts/build-release.sh <version>            e.g. 1.0.0
#   scripts/build-release.sh <version> --no-bundle-check
#
# Result: dist/grav-plugin-redirect-manager-<version>.zip (+ .sha256), top folder redirect-manager/.
#
# Checks, in this order (any failure stops the build, nothing in the working tree is changed):
#   1. <version> is semver, blueprints.yaml `version:` equals it, CHANGELOG.md has a section for it ("# v<version>", see scripts/changelog-section.sh)
#   2. composer validate --strict
#   3. the admin-next bundles and languages.yaml are up to date: `npm run build` of admin2 (which also compiles
#      the UI strings into ../languages.yaml) runs in a temp copy, never in place, and the sha256 of the three bundles
#      and of languages.yaml is compared with the committed files. --no-bundle-check skips this (not for releases).
#   4. staging tree: runtime files only, vendor/ from `composer install --no-dev --classmap-authoritative`
#   5. the ZIP has no forbidden paths, no root *.yaml besides blueprints/languages/redirect-manager (GPM package name),
#      vendor/ holds no third-party package, `php -l` passes on every PHP file
#
# Environment: RM_PHP_BIN (PHP used for the lint and the autoload check, default: php),
#              SOURCE_DATE_EPOCH (file times in the ZIP, default: 2026-01-01 00:00 UTC).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="${RM_PHP_BIN:-php}"
SLUG="redirect-manager"
BUNDLES="pages/redirect-manager.js widgets/redirect-manager.js panels/redirect-manager.js"

VERSION=""
CHECK_BUNDLES=1
for arg in "$@"; do
    case "$arg" in
        --no-bundle-check) CHECK_BUNDLES=0 ;;
        -h|--help) sed -n '2,20p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
        -*) echo "Unknown option: $arg" >&2; exit 2 ;;
        *) [ -z "$VERSION" ] && VERSION="$arg" || { echo "Only one version argument is allowed." >&2; exit 2; } ;;
    esac
done
[ -n "$VERSION" ] || { echo "usage: scripts/build-release.sh <version> [--no-bundle-check]" >&2; exit 2; }

step() { printf '\n== %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
sha256_of() {
    if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | awk '{print $1}'; else shasum -a 256 "$1" | awk '{print $1}'; fi
}
file_size() { wc -c < "$1" | tr -d ' '; }

WORK="$(mktemp -d "${TMPDIR:-/tmp}/rm-build.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT

# ---------------------------------------------------------------- 1. version
step "Version $VERSION"
SEMVER='^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?$'
printf '%s' "$VERSION" | grep -Eq "$SEMVER" || fail "'$VERSION' is not a semantic version (1.2.3, 1.2.3-rc.1)."

BP_VERSION="$(sed -n 's/^version:[[:space:]]*//p' "$ROOT/blueprints.yaml" | head -n 1 | sed -e 's/[[:space:]]*#.*$//' -e "s/^['\"]//" -e "s/['\"][[:space:]]*$//" | tr -d '[:space:]')"
[ -n "$BP_VERSION" ] || fail "no 'version:' in blueprints.yaml."
[ "$BP_VERSION" = "$VERSION" ] || fail "blueprints.yaml says version $BP_VERSION, not $VERSION. Bump it first (docs/RELEASING.md)."
echo "blueprints.yaml version $BP_VERSION"

[ -f "$ROOT/CHANGELOG.md" ] || fail "CHANGELOG.md is missing."
"$ROOT/scripts/changelog-section.sh" "$VERSION" "$ROOT/CHANGELOG.md" > /dev/null || fail "CHANGELOG.md has no section for $VERSION with content."
echo "CHANGELOG.md has a section for $VERSION"

for required in redirect-manager.php redirect-manager.yaml blueprints.yaml README.md CHANGELOG.md LICENSE docs/openapi.yaml composer.json composer.lock; do
    [ -f "$ROOT/$required" ] || fail "required file missing: $required"
done

# ---------------------------------------------------------------- 2. composer
step "composer validate --strict"
(cd "$ROOT" && composer validate --strict --no-interaction) || fail "composer validate --strict failed."

# ---------------------------------------------------------------- 3. bundles
if [ "$CHECK_BUNDLES" = "1" ]; then
    step "admin-next bundles and languages.yaml are up to date"
    command -v node >/dev/null 2>&1 || fail "node is needed to rebuild admin2 (or pass --no-bundle-check)."
    B="$WORK/bundles"
    mkdir -p "$B/admin2" "$B/admin-next/pages" "$B/admin-next/widgets" "$B/admin-next/panels"
    # vite writes to ../admin-next and the i18n step to ../languages.yaml, both relative to admin2/, so a copy next
    # to a scratch admin-next and a scratch languages.yaml keeps the working tree untouched.
    cp "$ROOT/languages.yaml" "$B/languages.yaml"
    rsync -a --exclude node_modules --exclude screenshots --exclude .DS_Store "$ROOT/admin2/" "$B/admin2/"
    if [ -d "$ROOT/admin2/node_modules" ]; then
        ln -s "$ROOT/admin2/node_modules" "$B/admin2/node_modules"
    else
        echo "admin2/node_modules is missing, running npm ci in the temp copy"
        (cd "$B/admin2" && npm ci --no-audit --no-fund --loglevel=error)
    fi
    (cd "$B/admin2" && npm run build --silent) > "$WORK/vite.log" 2>&1 || { cat "$WORK/vite.log" >&2; fail "npm run build failed in admin2."; }
    stale=0
    for bundle in $BUNDLES; do
        [ -f "$B/admin-next/$bundle" ] || fail "the admin2 build did not produce admin-next/$bundle."
        [ -f "$ROOT/admin-next/$bundle" ] || fail "admin-next/$bundle is missing in the repository."
        have="$(sha256_of "$ROOT/admin-next/$bundle")"
        want="$(sha256_of "$B/admin-next/$bundle")"
        if [ "$have" = "$want" ]; then
            echo "ok    admin-next/$bundle ($have)"
        else
            echo "STALE admin-next/$bundle" >&2
            echo "      committed: $have" >&2
            echo "      rebuilt:   $want" >&2
            stale=1
        fi
    done
    have="$(sha256_of "$ROOT/languages.yaml")"
    want="$(sha256_of "$B/languages.yaml")"
    if [ "$have" = "$want" ]; then
        echo "ok    languages.yaml ($have)"
    else
        echo "STALE languages.yaml (the UI strings in admin2/src/i18n are not compiled into it)" >&2
        echo "      committed: $have" >&2
        echo "      rebuilt:   $want" >&2
        stale=1
    fi
    [ "$stale" = "0" ] || fail "generated files are stale. Run 'npm run build' in admin2/, review and commit admin-next/ and languages.yaml, then build again."
else
    step "admin-next bundle check skipped (--no-bundle-check)"
    echo "WARNING: do not publish a ZIP built this way." >&2
fi

# ---------------------------------------------------------------- 4. staging
step "Staging"
STAGE="$WORK/stage"
PKG="$STAGE/$SLUG"
mkdir -p "$PKG"
RSYNC=(rsync -a --exclude .DS_Store --exclude '*.orig' --exclude '*.rej' --exclude '*~')

cp "$ROOT"/*.yaml "$PKG/"
cp "$ROOT/redirect-manager.php" "$ROOT/README.md" "$ROOT/CHANGELOG.md" "$ROOT/LICENSE" "$PKG/"
for dir in classes cli config templates admin-next languages assets; do
    if [ -d "$ROOT/$dir" ] && [ -n "$(find "$ROOT/$dir" -type f ! -name .DS_Store -print -quit)" ]; then
        "${RSYNC[@]}" "$ROOT/$dir/" "$PKG/$dir/"
    fi
done
mkdir -p "$PKG/docs"
cp "$ROOT/docs/openapi.yaml" "$PKG/docs/openapi.yaml"

# vendor/: only this plugin's autoloader. composer.json + composer.lock go to a scratch copy of the staging
# tree so the repo's dev vendor/ stays untouched; classes/ must be there for --classmap-authoritative.
COMPOSER_DIR="$WORK/composer"
mkdir -p "$COMPOSER_DIR"
cp "$ROOT/composer.json" "$ROOT/composer.lock" "$COMPOSER_DIR/"
"${RSYNC[@]}" "$PKG/classes/" "$COMPOSER_DIR/classes/"
(cd "$COMPOSER_DIR" && composer install --no-dev --classmap-authoritative --no-interaction --no-scripts --no-progress --quiet) \
    || fail "composer install --no-dev failed."
"${RSYNC[@]}" "$COMPOSER_DIR/vendor/" "$PKG/vendor/"

# Normalize modes and times so the same tree gives the same ZIP.
find "$PKG" -type d -exec chmod 755 {} +
find "$PKG" -type f -exec chmod 644 {} +
EPOCH="${SOURCE_DATE_EPOCH:-1767225600}"
if TS="$(date -u -r "$EPOCH" +%Y%m%d%H%M.%S 2>/dev/null)"; then :; else TS="$(date -u -d "@$EPOCH" +%Y%m%d%H%M.%S)"; fi
find "$PKG" -exec env TZ=UTC touch -t "$TS" {} +

# php -l on every PHP file, and the classmap must resolve.
step "php -l ($("$PHP_BIN" -r 'echo PHP_VERSION;'))"
lint_failed=0
lint_count=0
while IFS= read -r -d '' php_file; do
    lint_count=$((lint_count + 1))
    if ! out="$("$PHP_BIN" -l "$php_file" 2>&1)"; then
        printf '%s\n' "$out" >&2
        lint_failed=1
    fi
done < <(find "$PKG" -name '*.php' -print0 | sort -z)
[ "$lint_failed" = "0" ] || fail "php -l reported errors."
echo "$lint_count PHP files, no syntax errors"
"$PHP_BIN" -r '
    require $argv[1] . "/vendor/autoload.php";
    foreach (["Domain\\Rule", "Storage\\RuleRepository"] as $class) {
        if (!class_exists("Grav\\Plugin\\RedirectManager\\" . $class)) { fwrite(STDERR, "autoload cannot find $class\n"); exit(1); }
    }
' "$PKG" || fail "the staged autoloader does not resolve the plugin classes."
echo "vendor/autoload.php resolves the plugin classes"

# ---------------------------------------------------------------- 5. zip
step "ZIP"
DIST="$ROOT/dist"
ZIP="$DIST/grav-plugin-$SLUG-$VERSION.zip"
mkdir -p "$DIST"
rm -f "$ZIP" "$ZIP.sha256"
# Directory entries stay in: Grav's Installer::unZip() takes the package folder name from the FIRST entry of the
# ZIP, so it must be the "redirect-manager/" directory entry (a file entry there gives "Not a valid Grav package").
(cd "$STAGE" && find "$SLUG" | LC_ALL=C sort | TZ=UTC zip -X -q "$ZIP" -@)
[ -f "$ZIP" ] || fail "zip produced no file."

ENTRIES="$WORK/entries.txt"
unzip -Z1 "$ZIP" | LC_ALL=C sort > "$ENTRIES"
[ "$(unzip -Z1 "$ZIP" | head -n 1)" = "$SLUG/" ] || fail "the first ZIP entry must be '$SLUG/' (GPM reads the folder name from it)."

bad=0
# Every entry except docs/openapi.yaml (the only allowed file below docs/) is matched against the patterns.
CHECKED="$WORK/checked.txt"
grep -Ev "^$SLUG/docs/(openapi\.yaml)?$" "$ENTRIES" > "$CHECKED" || true
forbid() { # <description> <extended regex on the entry name>
    if grep -Eq "$2" "$CHECKED"; then
        echo "forbidden ($1):" >&2
        grep -E "$2" "$CHECKED" | head -n 5 | sed 's/^/  /' >&2
        bad=1
    fi
}
if grep -Ev "^$SLUG/" "$ENTRIES" | grep -q .; then echo "entries outside $SLUG/:" >&2; grep -Ev "^$SLUG/" "$ENTRIES" | head -n 5 >&2; bad=1; fi
forbid "tests"            "^$SLUG/tests/"
forbid "admin2 source"    "^$SLUG/admin2/"
forbid "node_modules"     "(^|/)node_modules/"
forbid ".grav test site"  "^$SLUG/\.grav/"
forbid "scripts"          "^$SLUG/scripts/"
forbid "build output"     "^$SLUG/(build|dist)/"
forbid "docs other than openapi.yaml" "^$SLUG/docs/"
grep -q "^$SLUG/docs/openapi.yaml$" "$ENTRIES" || { echo "docs/openapi.yaml is missing from the ZIP." >&2; bad=1; }
forbid "dev files"        "(^|/)(\.git[^/]*|\.github|\.php-cs-fixer[^/]*|phpstan[^/]*|phpunit[^/]*|composer\.(json|lock)|package(-lock)?\.json|\.DS_Store)(/|$)"
# vendor/: autoload.php and composer/ only, and no package inside installed.json.
if grep -E "^$SLUG/vendor/" "$ENTRIES" | grep -Ev "^$SLUG/vendor/(autoload\.php|composer/.*)?$" | grep -q .; then
    echo "third-party files in vendor/:" >&2
    grep -E "^$SLUG/vendor/" "$ENTRIES" | grep -Ev "^$SLUG/vendor/(autoload\.php|composer/.*)?$" | head -n 5 | sed 's/^/  /' >&2
    bad=1
fi
grep -q "^$SLUG/vendor/autoload.php$" "$ENTRIES" || { echo "vendor/autoload.php is missing from the ZIP." >&2; bad=1; }
packages="$(unzip -p "$ZIP" "$SLUG/vendor/composer/installed.json" | jq '.packages | length')"
[ "$packages" = "0" ] || { echo "vendor/composer/installed.json lists $packages package(s)." >&2; bad=1; }
# GPM::getPackageName() names a direct-installed package after the first *.yaml in the ZIP root, ignoring only
# blueprints.yaml and languages.yaml. permissions.yaml and mcp.yaml sort before redirect-manager.yaml and would
# turn the plugin into "mcp" or "permissions" (docs/DECISIONS.md D-024): they live in config/.
root_yaml="$(grep -E "^$SLUG/[^/]+\.ya?ml$" "$ENTRIES" | grep -Ev "^$SLUG/(blueprints|languages|redirect-manager)\.yaml$" || true)"
if [ -n "$root_yaml" ]; then
    echo "forbidden root yaml file(s) in the ZIP (GPM would name the package after them; only blueprints.yaml, languages.yaml and redirect-manager.yaml may sit in the root, move the rest to config/):" >&2
    printf '%s\n' "$root_yaml" | sed 's/^/  /' >&2
    bad=1
fi
for must in redirect-manager.php redirect-manager.yaml blueprints.yaml languages.yaml config/permissions.yaml config/mcp.yaml README.md CHANGELOG.md LICENSE admin-next/pages/redirect-manager.js admin-next/widgets/redirect-manager.js admin-next/panels/redirect-manager.js; do
    grep -q "^$SLUG/$must$" "$ENTRIES" || { echo "missing in the ZIP: $must" >&2; bad=1; }
done
[ "$bad" = "0" ] || fail "the ZIP content check failed."
echo "content check passed"

SHA="$(sha256_of "$ZIP")"
printf '%s  %s\n' "$SHA" "$(basename "$ZIP")" > "$ZIP.sha256"
COUNT="$(unzip -Z1 "$ZIP" | grep -vc '/$')"

step "Done"
echo "file:   ${ZIP#"$ROOT"/}"
echo "files:  $COUNT (plus directory entries)"
echo "size:   $(file_size "$ZIP") bytes"
echo "sha256: $SHA"
echo "top level:"
unzip -Z1 "$ZIP" | awk -F/ '{ print ($3 == "" ? $2 : $2 "/") }' | LC_ALL=C sort -u | sed 's/^/  /'
