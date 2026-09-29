#!/usr/bin/env bash
# Measures the plugin's per-request cost inside a real Grav 2 site with 10,000 rules.
#
#   scripts/benchmark-request.sh [--rules N] [--requests N] [--warmup N] [--port N] [--keep]
#
# 1. sets up a fresh Grav site in build/benchmark/2.2.2 (scripts/setup-test-site.sh; the Grav zip is reused)
# 2. generates N rules (85 % exact, 10 % wildcard, 5 % regex) and imports them with `bin/plugin redirect-manager import`
# 3. serves the site with `php -S` (OPcache on for the CLI server) and sends the requests three times:
#      a) plugin enabled, REDIRECT_MANAGER_TIMING=1 (reads the X-Redirect-Manager-Time header)
#      b) plugin enabled, no timing
#      c) plugin disabled
# 4. prints median and p95 of the header value and the total response times, writes build/benchmark-request.json
#    and build/benchmark-request.md.
#
# RM_PHP_BIN selects the PHP binary (default: php). Defaults: 10000 rules, 2000 requests, 300 warm-up requests, port 8700.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="${RM_PHP_BIN:-php}"
RULES=10000
REQUESTS=2000
WARMUP=300
PORT=8700
KEEP=0

while [ $# -gt 0 ]; do
    case "$1" in
        --rules) RULES="$2"; shift 2 ;;
        --requests) REQUESTS="$2"; shift 2 ;;
        --warmup) WARMUP="$2"; shift 2 ;;
        --port) PORT="$2"; shift 2 ;;
        --keep) KEEP=1; shift ;;
        -h|--help) sed -n '2,15p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Unknown option: $1" >&2; exit 2 ;;
    esac
done

BASE="$ROOT/build/benchmark"
SITE="$BASE/2.2.2"
DATA="$BASE/data"
SERVER_PID=""

cleanup() {
    if [ -n "$SERVER_PID" ]; then kill "$SERVER_PID" 2>/dev/null || true; fi
    if [ "$KEEP" = "0" ]; then rm -rf "$SITE"; fi
}
trap cleanup EXIT

# --- 1. fresh site -------------------------------------------------------------------------------------------------
mkdir -p "$BASE"
rm -rf "$SITE" "$DATA"
for zip in "$ROOT"/.grav/grav-admin-v2.2.2.zip; do
    [ -f "$zip" ] && [ ! -f "$BASE/grav-admin-v2.2.2.zip" ] && cp "$zip" "$BASE/"
done
echo "== Setting up the site"
"$ROOT/scripts/setup-test-site.sh" "$BASE" 2.2.2 > "$BASE/setup.log" 2>&1 || { cat "$BASE/setup.log" >&2; exit 1; }

mkdir -p "$SITE/user/config/plugins" "$SITE/logs"
cat > "$SITE/user/config/system.yaml" <<'YAML'
home:
  alias: '/home'
pages:
  theme: quark2
cache:
  enabled: true
  check:
    method: file
errors:
  display: 0
  log: true
debugger:
  enabled: false
YAML
rm -f "$SITE/user/config/plugins/redirect-manager.yaml"

# --- 2. rules ------------------------------------------------------------------------------------------------------
echo "== Generating $RULES rules and $((WARMUP + REQUESTS)) requests"
"$PHP_BIN" "$ROOT/scripts/benchmark/generate.php" "$DATA" "$RULES" "$REQUESTS" "$WARMUP"
echo "== Importing the rules (bin/plugin redirect-manager import)"
START=$(date +%s)
(cd "$SITE" && "$PHP_BIN" bin/plugin redirect-manager import "$DATA/rules.csv" --skip-duplicates > "$BASE/import.log" 2>&1) || { tail -20 "$BASE/import.log" >&2; exit 1; }
echo "   imported in $(( $(date +%s) - START )) s, rules.yaml $(du -k "$SITE/user/data/redirect-manager/rules.yaml" | cut -f1) KB"

# --- 3. runs -------------------------------------------------------------------------------------------------------
run_phase() { # label, json file, timing (1|0)
    local label="$1" json="$2" timing="$3"
    rm -rf "$SITE/cache" && mkdir -p "$SITE/cache"
    (
        cd "$SITE"
        if [ "$timing" = "1" ]; then export REDIRECT_MANAGER_TIMING=1; else unset REDIRECT_MANAGER_TIMING; fi
        exec "$PHP_BIN" -d opcache.enable=1 -d opcache.enable_cli=1 -d opcache.validate_timestamps=1 -d opcache.memory_consumption=128 \
            -S "127.0.0.1:$PORT" system/router.php > "$SITE/logs/server.log" 2>&1
    ) &
    SERVER_PID=$!
    for _ in $(seq 1 100); do
        curl -s -o /dev/null "http://127.0.0.1:$PORT/" && break
        sleep 0.1
    done
    "$PHP_BIN" "$ROOT/scripts/benchmark/client.php" "http://127.0.0.1:$PORT" "$DATA/requests.tsv" "$WARMUP" "$label" "$json"
    kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
    SERVER_PID=""
}

echo "== Requests"
run_phase "plugin enabled + timing" "$BASE/enabled-timing.json" 1
run_phase "plugin enabled" "$BASE/enabled.json" 0
printf 'enabled: false\n' > "$SITE/user/config/plugins/redirect-manager.yaml"
run_phase "plugin disabled" "$BASE/disabled.json" 0
rm -f "$SITE/user/config/plugins/redirect-manager.yaml"

# --- 4. report -----------------------------------------------------------------------------------------------------
{
    echo "Machine: $(sysctl -n machdep.cpu.brand_string 2>/dev/null || grep -m1 'model name' /proc/cpuinfo | cut -d: -f2- | sed 's/^ //'), $(uname -sm)"
    echo "PHP: $("$PHP_BIN" -r 'echo PHP_VERSION;') with -d opcache.enable_cli=1 -d opcache.validate_timestamps=1 (php -S), Grav 2.2.2"
    echo "Rules: $RULES, requests: $REQUESTS after $WARMUP warm-up"
    echo
    "$PHP_BIN" "$ROOT/scripts/benchmark/report.php" "$BASE/enabled-timing.json" "$BASE/enabled.json" "$BASE/disabled.json"
} | tee "$ROOT/build/benchmark-request.md"
cp "$BASE/enabled-timing.json" "$ROOT/build/benchmark-request.json"
