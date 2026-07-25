#!/usr/bin/env bash
# Runtime smoke probe — verifies the RUNNING env, which no static audit can.
# Usage: smoke.sh [--base-url https://dev-api.partna.au] [--rate-limit]
set -uo pipefail

BASE="https://dev-api.partna.au"
RATE_LIMIT_TEST=false
while [[ $# -gt 0 ]]; do
    case "$1" in
        --base-url) BASE="$2"; shift 2 ;;
        --rate-limit) RATE_LIMIT_TEST=true; shift ;;
        *) echo "unknown arg: $1" >&2; exit 2 ;;
    esac
done

FAILS=0
pass() { echo "PASS  $1"; }
fail() { echo "FAIL  $1"; FAILS=$((FAILS + 1)); }
warn() { echo "WARN  $1"; }

status() { curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$1"; }

# --- 1. Sensitive files must not be HTTP-reachable ---
for path in .env composer.json .git/config storage/logs/laravel.log; do
    code=$(status "$BASE/$path")
    [[ "$code" == "404" || "$code" == "403" ]] \
        && pass "$path not reachable ($code)" \
        || fail "$path returned $code — must be 404/403"
done

# --- 2. Debug leakage: bogus route must return clean JSON, no stack trace ---
body=$(curl -s --max-time 15 -H 'Accept: application/json' "$BASE/api/definitely-not-a-route-xyz")
if echo "$body" | grep -qE 'Stack trace|vendor/laravel|Illuminate\\\\'; then
    fail "bogus route leaks stack trace (APP_DEBUG on?)"
else
    pass "bogus route returns clean error body"
fi

# --- 3. Dev tooling must be gated ---
for path in telescope horizon; do
    code=$(status "$BASE/$path")
    [[ "$code" == "200" ]] && fail "/$path publicly reachable (200)" || pass "/$path gated ($code)"
done

# --- 4. Health endpoint answers ---
code=$(status "$BASE/api/health")
[[ "$code" == "200" ]] && pass "health endpoint 200" || fail "health endpoint returned $code"

# --- 5. Security headers on an API response (WARN — may be Cloudflare's job) ---
headers=$(curl -s -D - -o /dev/null --max-time 15 "$BASE/api/health")
for h in "x-content-type-options" "strict-transport-security" "x-frame-options"; do
    echo "$headers" | grep -qi "^$h:" && pass "header $h present" || warn "header $h missing (set via middleware or Cloudflare)"
done

# --- 6. 404-not-403 enumeration standard, live ---
code=$(status "$BASE/api/public/documents/00000000-0000-4000-8000-000000000000/download")
[[ "$code" == "404" ]] && pass "missing public resource → 404 (anti-enumeration)" \
    || fail "missing public resource returned $code — must be 404, never 403"

# --- 7. Rate limiter actually fires (opt-in: hammers the env) ---
if $RATE_LIMIT_TEST; then
    got429=false
    for _ in $(seq 1 90); do
        [[ "$(status "$BASE/api/ping")" == "429" ]] && { got429=true; break; }
    done
    $got429 && pass "throttle fired (429 within 90 hits on /api/ping)" \
        || fail "no 429 after 90 hits — is the limiter live?"
fi

echo
if [[ $FAILS -gt 0 ]]; then echo "SMOKE: $FAILS failure(s)"; exit 1; fi
echo "SMOKE: all checks passed"
