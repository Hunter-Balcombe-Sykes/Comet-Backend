#!/usr/bin/env bash
# Phase 8: scanner self-tests — the acceptance gate for the whole tool.
# The worst failure mode is a silently-broken scanner reporting "all
# clear" forever, so this proves BOTH halves for both lanes: (a) a
# deliberately-vulnerable fixture is flagged in scanner output, AND
# (b) run.sh's gating actually fails the build — a scanner that
# finds-but-doesn't-fail is as broken as one that doesn't find. Then
# proves clean + baseline-suppression: a clean target is green, and
# baselining the canary's key suppresses it on re-run (also green).
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DAST_DIR="$HERE"
# shellcheck source=../lib/common.sh
source "$HERE/lib/common.sh"

FAILURES=0
pass() { log "PASS: $1"; }
fail() { log "FAIL: $1"; FAILURES=$((FAILURES + 1)); }
assert_eq() { [[ "$2" == "$3" ]] && pass "$1" || fail "$1 (expected [$3], got [$2])"; }
assert_ne() { [[ "$2" != "$3" ]] && pass "$1" || fail "$1 (expected != [$3], got [$2])"; }

# =============================================================================
# EDGE canary: a local server exposing /.env; env-git-not-exposed.yaml must
# fire, and the same run must diff-baseline non-zero.
# =============================================================================
log "=== edge canary ==="
EDGE_PORT=18799
( cd "$HERE/tests/canary/edge-fixture" && exec python3 -m http.server "$EDGE_PORT" >/tmp/dast-selftest-edge.log 2>&1 ) &
EDGE_SERVER_PID=$!
sleep 2

CANARY_OUTDIR="$(mktemp -d)"
"$HERE/edge/nuclei-edge.sh" "$CANARY_OUTDIR" "http://host.docker.internal:${EDGE_PORT}" >/dev/null 2>&1
FOUND="$(jq -r 'select(."template-id"=="env-git-not-exposed")' "$CANARY_OUTDIR/nuclei.jsonl" 2>/dev/null | wc -l | tr -d ' ')"
assert_ne "edge canary: env-git-not-exposed alert present in nuclei.jsonl" "$FOUND" "0"

"$HERE/lib/diff-baseline.sh" "$CANARY_OUTDIR" high >/dev/null 2>&1
assert_ne "edge canary: diff-baseline exits non-zero (build fails)" "$?" "0"

# --- Clean target proof. Deliberately the REAL dev host, not a bare
# python fixture — caught the hard way 2026-07-26: a bare http.server has
# no Laravel-shaped routes at all, so cache-control-correct.yaml's check
# against api/public/unsubscribe/* (expects the no-store header) sees a
# generic 404 with no headers and flags it "high" — a false positive
# about "this isn't even my app", not a real cache-control regression.
# EDGE_TARGET's actual contract is "the real deployed app", which the
# real dev host verifiably satisfies (re-confirmed clean throughout this
# build — Phase 5, and twice more in Phase 7's key-stability check).
CLEAN_OUTDIR="$(mktemp -d)"
"$HERE/edge/nuclei-edge.sh" "$CLEAN_OUTDIR" "https://dev-api.partna.au" >/dev/null 2>&1
"$HERE/lib/diff-baseline.sh" "$CLEAN_OUTDIR" high >/dev/null 2>&1
assert_eq "edge clean target: diff-baseline exits 0" "$?" "0"

# --- Baseline-suppression proof. Backs up + restores the REAL committed
# baseline around this — --update-baseline must never leave test data in
# the triaged baseline this script didn't create.
NUCLEI_BASELINE="$HERE/baseline/nuclei-baseline.txt"
NUCLEI_BASELINE_BACKUP="$(mktemp)"
cp "$NUCLEI_BASELINE" "$NUCLEI_BASELINE_BACKUP"
"$HERE/lib/diff-baseline.sh" "$CANARY_OUTDIR" high --update-baseline >/dev/null 2>&1
"$HERE/lib/diff-baseline.sh" "$CANARY_OUTDIR" high >/dev/null 2>&1
SUPPRESSED_EXIT=$?
cp "$NUCLEI_BASELINE_BACKUP" "$NUCLEI_BASELINE"
rm -f "$NUCLEI_BASELINE_BACKUP"
assert_eq "edge baseline-suppression: re-run against baselined canary exits 0" "$SUPPRESSED_EXIT" "0"

kill "$EDGE_SERVER_PID" 2>/dev/null
wait "$EDGE_SERVER_PID" 2>/dev/null
rm -rf "$CANARY_OUTDIR" "$CLEAN_OUTDIR"

# =============================================================================
# ACTIVE canary: DAST_CANARY=1 registers GET /api/__dast_canary?x=<reflected>
# (routes/api.php). Reuses bring-up.sh for a real isolated stack + served
# app (the actual pipeline this lane runs in production use), but drives a
# FOCUSED ZAP scan against just the canary URL rather than the full
# 487-route policy scan — faster, and precisely what this test needs to
# prove (the plan's own "reflected input route" fixture, flagged for real).
# =============================================================================
log "=== active canary ==="
ACTIVE_OUTDIR="$(mktemp -d)"
BRINGUP_PID=""
active_teardown() {
    [[ -n "$BRINGUP_PID" ]] && { kill "$BRINGUP_PID" 2>/dev/null; wait "$BRINGUP_PID" 2>/dev/null; }
}
trap active_teardown EXIT INT TERM

DAST_CANARY=1 "$HERE/active/bring-up.sh" "$ACTIVE_OUTDIR" >"$ACTIVE_OUTDIR/bring-up-stdout.log" 2>&1 &
BRINGUP_PID=$!
deadline=$(( $(date +%s) + 90 ))
while [[ ! -f "$ACTIVE_OUTDIR/bring-up.env" ]]; do
    if ! kill -0 "$BRINGUP_PID" 2>/dev/null; then
        fail "active canary: bring-up.sh died before becoming ready — see $ACTIVE_OUTDIR/bring-up-stdout.log"
        break
    fi
    if [[ $(date +%s) -ge $deadline ]]; then
        fail "active canary: bring-up.sh did not become ready within 90s"
        break
    fi
    sleep 1
done

if [[ -f "$ACTIVE_OUTDIR/bring-up.env" ]]; then
    # shellcheck disable=SC1091
    source "$ACTIVE_OUTDIR/bring-up.env"
    ZAP_TARGET_URL="http://host.docker.internal:${APP_PORT}"

    # Confirm the canary route itself actually exists (proves DAST_CANARY=1
    # reached the served app, not just this script's own env).
    CANARY_STATUS="$(curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1:${APP_PORT}/api/__dast_canary?x=probe")"
    assert_eq "active canary: /api/__dast_canary route is live" "$CANARY_STATUS" "200"

    ZAP_CANARY_WORK="$ACTIVE_OUTDIR/zap"
    mkdir -p "$ZAP_CANARY_WORK"
    cat > "$ZAP_CANARY_WORK/canary-plan.yaml" <<EOF
env:
  contexts:
    - name: "canary"
      urls: ["${ZAP_TARGET_URL}/api/__dast_canary"]
jobs:
  - type: spider
    parameters:
      context: "canary"
      url: "${ZAP_TARGET_URL}/api/__dast_canary?x=canary-probe"
  - type: activeScan
    parameters:
      context: "canary"
    policyDefinition:
      defaultStrength: "MEDIUM"
      defaultThreshold: "OFF"
      rules:
        - id: 40012   # Cross Site Scripting (Reflected)
          threshold: "LOW"
  - type: report
    parameters:
      template: "traditional-json"
      reportDir: "/zap/out"
      # Named zap-report (not canary-report) deliberately — diff-baseline.sh
      # looks for exactly OUTDIR-slash-zap-slash-zap-report.json (matching
      # zap-active.sh's real output). A mismatched filename here means
      # diff-baseline.sh silently sees no ZAP data at all and reports
      # clean regardless of what the scan actually found — caught by this
      # self-test's own second assertion failing on first run 2026-07-26.
      reportFile: "zap-report"
EOF
    docker run --rm \
        -v "$ZAP_CANARY_WORK:/zap/wrk/:rw" \
        -v "$ZAP_CANARY_WORK:/zap/out:rw" \
        zaproxy/zap-stable zap.sh -cmd -autorun /zap/wrk/canary-plan.yaml \
        > "$ZAP_CANARY_WORK/canary-run.log" 2>&1

    XSS_COUNT="$(jq -r '[.site[]?.alerts[]? | select(.alertRef == "40012")] | length' "$ZAP_CANARY_WORK/zap-report.json" 2>/dev/null || echo 0)"
    assert_ne "active canary: reflected XSS alert (40012) present" "$XSS_COUNT" "0"

    "$HERE/lib/diff-baseline.sh" "$ACTIVE_OUTDIR" high >/dev/null 2>&1
    assert_ne "active canary: diff-baseline exits non-zero (build fails)" "$?" "0"
else
    fail "active canary: skipped remaining assertions — bring-up never became ready"
fi

active_teardown
trap - EXIT INT TERM
rm -rf "$ACTIVE_OUTDIR"

# =============================================================================
log "=== dast-selftest.sh summary ==="
if [[ "$FAILURES" -eq 0 ]]; then
    log "ALL PASS"
    exit 0
else
    log "$FAILURES assertion(s) FAILED"
    exit 1
fi
