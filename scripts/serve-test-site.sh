#!/usr/bin/env bash
# Starts PHP's built-in server on a Grav site in the background.
#
#   scripts/serve-test-site.sh [site-dir] [port]     start (default: .grav/2.2.2, port 8100)
#   scripts/serve-test-site.sh --stop [site-dir]     stop
#
# The pid is written to <site-dir>/.server.pid, output to <site-dir>/logs/server.log.
# RM_PHP_BIN selects the PHP binary (default: php), e.g. /opt/homebrew/opt/php@8.3/bin/php.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="${RM_PHP_BIN:-php}"

if [ "${1:-}" = "--stop" ]; then
    SITE="${2:-$ROOT/.grav/2.2.2}"
    if [ -f "$SITE/.server.pid" ]; then
        kill "$(cat "$SITE/.server.pid")" 2>/dev/null || true
        rm -f "$SITE/.server.pid"
        echo "Stopped."
    fi
    exit 0
fi

SITE="${1:-$ROOT/.grav/2.2.2}"
PORT="${2:-8100}"
SITE="$(cd "$SITE" 2>/dev/null && pwd)" || { echo "No such site directory: $1" >&2; exit 1; }

[ -f "$SITE/index.php" ] || { echo "No Grav site at $SITE, run scripts/setup-test-site.sh first." >&2; exit 1; }
mkdir -p "$SITE/logs"

cd "$SITE"
nohup "$PHP_BIN" -S "127.0.0.1:$PORT" system/router.php > "$SITE/logs/server.log" 2>&1 &
echo $! > "$SITE/.server.pid"

for _ in $(seq 1 50); do
    if curl -s -o /dev/null "http://127.0.0.1:$PORT/"; then
        echo "Serving $SITE on http://127.0.0.1:$PORT (pid $(cat "$SITE/.server.pid"))"
        exit 0
    fi
    sleep 0.2
done
echo "Server did not come up, see $SITE/logs/server.log" >&2
exit 1
