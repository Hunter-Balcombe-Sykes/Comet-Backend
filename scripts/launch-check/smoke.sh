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
status_fast() { curl -s -o /dev/null -w '%{http_code}' --max-time 5 "$1"; }
is_unreachable() { [[ -z "$1" || "$1" == "000" ]]; }

# --- 1. Sensitive files must not be HTTP-reachable ---
for path in .env composer.json .git/config storage/logs/laravel.log; do
    code=$(status "$BASE/$path")
    [[ "$code" == "404" || "$code" == "403" ]] \
        && pass "$path not reachable ($code)" \
        || fail "$path returned $code — must be 404/403"
done

# --- 2. Debug leakage: bogus route must return clean JSON, no stack trace ---
resp=$(curl -s --max-time 15 -H 'Accept: application/json' -w $'\n%{http_code}' "$BASE/api/definitely-not-a-route-xyz")
curl_exit=$?
resp_code="${resp##*$'\n'}"
body="${resp%$'\n'*}"
if [[ $curl_exit -ne 0 ]] || is_unreachable "$resp_code"; then
    fail "bogus route unreachable (curl exit $curl_exit, status ${resp_code:-none}) — cannot verify clean error body"
elif echo "$body" | grep -qE 'Stack trace|vendor/laravel|Illuminate\\\\'; then
    fail "bogus route leaks stack trace (APP_DEBUG on?)"
else
    pass "bogus route returns clean error body"
fi

# --- 3. Dev tooling must be gated ---
for path in telescope horizon; do
    code=$(status "$BASE/$path")
    if is_unreachable "$code"; then
        fail "/$path unreachable (status ${code:-none}) — cannot verify gating"
    elif [[ "$code" == "200" ]]; then
        fail "/$path publicly reachable (200)"
    else
        pass "/$path gated ($code)"
    fi
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
    aborted=false
    consecutive_unreachable=0
    for _ in $(seq 1 90); do
        code=$(status_fast "$BASE/api/ping")
        if is_unreachable "$code"; then
            consecutive_unreachable=$((consecutive_unreachable + 1))
            if [[ $consecutive_unreachable -ge 3 ]]; then
                fail "target unreachable during rate-limit probe (status ${code:-none}, $consecutive_unreachable consecutive) — aborting"
                aborted=true
                break
            fi
            continue
        fi
        consecutive_unreachable=0
        [[ "$code" == "429" ]] && { got429=true; break; }
    done
    if ! $aborted; then
        $got429 && pass "throttle fired (429 within 90 hits on /api/ping)" \
            || fail "no 429 after 90 hits — is the limiter live?"
    fi
fi

echo
if [[ $FAILS -gt 0 ]]; then echo "SMOKE: $FAILS failure(s)"; exit 1; fi
echo "SMOKE: all checks passed"
