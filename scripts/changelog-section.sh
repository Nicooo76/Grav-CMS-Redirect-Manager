#!/usr/bin/env bash
# Prints the CHANGELOG.md section of one version (without its heading).
#
#   scripts/changelog-section.sh <version> [changelog-file]
#
# A section starts at a "## <version>" or "## [<version>]" heading (anything after the version, such as
# " - 2026-09-29", is allowed) and ends before the next "## " heading. Exit code 1 when there is no such section
# or it is empty.
set -euo pipefail

VERSION="${1:-}"
FILE="${2:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/CHANGELOG.md}"
[ -n "$VERSION" ] || { echo "usage: $0 <version> [changelog-file]" >&2; exit 2; }
[ -f "$FILE" ] || { echo "No such file: $FILE" >&2; exit 1; }

BODY="$(awk -v v="$VERSION" '
    /^## / {
        if (active) { exit }
        title = substr($0, 4)
        sub(/^\[/, "", title)
        if (index(title, v) == 1) {
            rest = substr(title, length(v) + 1)
            c = substr(rest, 1, 1)
            if (rest == "" || c == " " || c == "\t" || c == "-" || c == "]") { active = 1; next }
        }
    }
    active { print }
' "$FILE")"

# Trim leading and trailing blank lines.
BODY="$(printf '%s\n' "$BODY" | sed -e '/./,$!d' | sed -e :a -e '/^\n*$/{$d;N;ba' -e '}')"
if [ -z "$(printf '%s' "$BODY" | tr -d '[:space:]')" ]; then
    echo "No CHANGELOG section for $VERSION in $FILE" >&2
    exit 1
fi
printf '%s\n' "$BODY"
