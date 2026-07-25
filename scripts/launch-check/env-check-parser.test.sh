#!/usr/bin/env bash
# Regression test for env-check.sh's cloud-CLI-output parser. Plain executable
# bash — no `bats` dependency, run it directly:
#   scripts/launch-check/env-check-parser.test.sh
#
# Pins the payload-shape behaviours from the false-PASS incident (an earlier
# version of the raw-text fallback grepped the WHOLE payload for `"exitCode":`
# and the PASS/FAIL sentinel, so text anywhere in the remote command's own
# output — a stray log line, a nested subprocess dump — could forge a PASS).
# Each case here stubs `cloud` with a canned payload and asserts env-check.sh's
# exit code. Never weaken an assertion to make a case "pass" — if a payload
# shape is ambiguous or malformed, the correct expectation is FAIL (exit 1).
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_CHECK="$DIR/env-check.sh"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

FAILURES=0
CASE_NUM=0

# run_case NAME EXPECTED_EXIT CLOUD_SCRIPT_BODY
run_case() {
    local name="$1" expected="$2" body="$3"
    CASE_NUM=$((CASE_NUM + 1))
    local binroot="$WORK/bin$CASE_NUM"
    mkdir -p "$binroot"
    printf '#!/usr/bin/env bash\n%s\n' "$body" > "$binroot/cloud"
    chmod +x "$binroot/cloud"
    local jq_path
    jq_path="$(command -v jq || true)"
    [[ -n "$jq_path" ]] && ln -sf "$jq_path" "$binroot/jq"
    local php_path
    php_path="$(command -v php || true)"
    [[ -n "$php_path" ]] && ln -sf "$php_path" "$binroot/php"
    for b in bash sh cat grep sed tr tail basename dirname env printf; do
        local p
        p="$(command -v "$b" 2>/dev/null || true)"
        [[ -n "$p" ]] && ln -sf "$p" "$binroot/$b"
    done

    local out actual
    out="$(PATH="$binroot" HOME="$HOME" bash "$ENV_CHECK" 2>&1)"
    actual=$?

    if [[ "$actual" == "$expected" ]]; then
        echo "ok    $name (exit $actual)"
    else
        echo "FAIL  $name — expected exit $expected, got $actual"
        echo "      output:"
        echo "$out" | sed 's/^/      /'
        FAILURES=$((FAILURES + 1))
    fi
}

# --- 1. well-formed JSON, exit 0, PASS -> pass ---
run_case "well-formed JSON PASS" 0 '
echo "{\"output\":\"  ok    present: app.key\ntarget=pilot  ok=11 warn=0 fail=0\nLAUNCH-CHECK-ENV: PASS\n\",\"exitCode\":0}"
exit 0'

# --- 2. malformed JSON (real unescaped newline), genuine PASS, exit 0 -> pass ---
run_case "malformed JSON genuine PASS" 0 '
printf "{\"output\":\"  ok    present: app.key\n\ntarget=pilot  ok=11 warn=0 fail=0\nLAUNCH-CHECK-ENV: PASS\n\",\"exitCode\":0}"
exit 0'

# --- 3. false-PASS PoC: genuine exitCode=1 (real trailing position), fake
#        "exitCode":0 + fake PASS sentinel embedded mid-line earlier -> FAIL ---
run_case "false-PASS PoC (fake exitCode+sentinel embedded mid-line)" 1 '
printf "{\"output\":\"fatal: config missing before real check ran\naudit log tail: previous successful run was: {\\\"exitCode\\\":0,\\\"output\\\":\\\"LAUNCH-CHECK-ENV: PASS\\\"}\n\",\"exitCode\":1}"
exit 0'

# --- 4a. empty output field -> FAIL ---
run_case "empty output field" 1 '
echo "{\"output\":\"\",\"exitCode\":0}"
exit 0'

# --- 4b. whitespace-only output field -> FAIL ---
run_case "whitespace-only output field" 1 '
echo "{\"output\":\"   \n\n   \",\"exitCode\":0}"
exit 0'

# --- 5. sentinel substring inside error text (not its own line), exit 1 -> FAIL ---
run_case "sentinel substring embedded in longer line" 1 '
printf "{\"output\":\"remote log noise mentions LAUNCH-CHECK-ENV: PASS in passing but this run genuinely failed\nmore lines\n\",\"exitCode\":1}"
exit 0'

# --- 6. both PASS and FAIL sentinels present -> FAIL wins ---
run_case "both sentinels present, FAIL wins" 1 '
printf "{\"output\":\"  ok    present: app.key\nLAUNCH-CHECK-ENV: PASS\n  FAIL  missing: nightwatch.token\nLAUNCH-CHECK-ENV: FAIL\n\",\"exitCode\":1}"
exit 0'

# --- 7. no exitCode field at all -> FAIL ---
run_case "no exitCode field" 1 '
printf "{\"output\":\"  ok    present: app.key\nLAUNCH-CHECK-ENV: PASS\n\"}"
exit 0'

# --- 8. fallback + genuine trailing exitCode:0 + genuine FAIL sentinel -> FAIL ---
run_case "genuine exit 0 but genuine FAIL sentinel" 1 '
printf "{\"output\":\"  FAIL  missing: nightwatch.token\ntarget=launch  ok=10 warn=0 fail=1\nLAUNCH-CHECK-ENV: FAIL\n\",\"exitCode\":0}"
exit 0'

# --- 9. well-formed JSON, exit 0, but NO sentinel at all -> FAIL (never infer
#         success from the mere absence of a FAIL marker) ---
run_case "well-formed JSON, exit 0, no sentinel" 1 '
echo "{\"output\":\"some unrelated noise with no sentinel at all\",\"exitCode\":0}"
exit 0'

echo
if [[ $FAILURES -gt 0 ]]; then
    echo "ENV-CHECK-PARSER-TEST: $FAILURES failure(s) of $CASE_NUM"
    exit 1
fi
echo "ENV-CHECK-PARSER-TEST: all $CASE_NUM cases passed"
