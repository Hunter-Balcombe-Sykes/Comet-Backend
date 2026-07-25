#!/usr/bin/env bash
# Group G orchestrator — edge/sitepage probe (external) + deployed runtime
# liveness (inside the env, via cloud command:run). Aggregates both.
#
# The `cloud command:run --json` leg reuses lib/cloud-json-parse.sh — the same
# repair-then-jq pipeline env-check.sh uses — rather than inventing a second
# parser. See that file and scripts/launch-check/README.md for why a
# line-scanning fallback is permanently banned here: two earlier probes that
# scanned raw text for a PASS sentinel both shipped a false PASS.
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/cloud-json-parse.sh
source "$DIR/lib/cloud-json-parse.sh"

HANDLE="${LAUNCH_CHECK_HANDLE:-}"
CLOUD_ENV="development"
TARGET="pilot"
while [[ $# -gt 0 ]]; do
    case "$1" in
        --handle) HANDLE="$2"; shift 2 ;;
        --env)    CLOUD_ENV="$2"; shift 2 ;;
        --target) TARGET="$2"; shift 2 ;;
        *) echo "unknown arg: $1"; exit 2 ;;
    esac
done

# production is gated: same rule as env-check.sh — prod probing must never
# fire from a plain `--env production`, and the prod Supabase ref must stay
# out of this suite's default path (see CLAUDE.md).
if [[ "$CLOUD_ENV" == "production" && "${LAUNCH_CHECK_CONFIRM_PROD:-}" != "1" ]]; then
    echo "FAIL  refusing to run against production without LAUNCH_CHECK_CONFIRM_PROD=1 (prod probing is gated)"
    exit 1
fi

FAIL=0

echo "── Edge / sitepage ──────────────────────────"
EDGE_ARGS=(); [[ -n "$HANDLE" ]] && EDGE_ARGS=(--handle "$HANDLE")
"$DIR/edge-check.sh" "${EDGE_ARGS[@]}" || FAIL=1

echo
echo "── Deployed runtime liveness ────────────────"
launch_check_resolve_cloud_cli
CLOUD="$LAUNCH_CHECK_CLOUD"
if [[ -z "$CLOUD" ]]; then
    echo "WARN  cloud CLI not found — run manually:"
    echo "      cloud command:run $CLOUD_ENV --cmd=\"php artisan launch-check:runtime --target=$TARGET\""
else
    RAW="$("$CLOUD" command:run "$CLOUD_ENV" --cmd="php artisan launch-check:runtime --target=$TARGET" --json --fields=exitCode,output --no-interaction 2>&1)"
    CLI_EXIT=$?

    if [[ $CLI_EXIT -ne 0 ]]; then
        echo "FAIL  cloud CLI invocation failed (exit $CLI_EXIT):"
        echo "$RAW"
        FAIL=1
    elif ! launch_check_parse_cloud_result "$RAW"; then
        echo "FAIL  $LAUNCH_CHECK_PARSE_FAIL_REASON — cannot verify deployed runtime health"
        [[ -n "$RAW" ]] && echo "$RAW"
        FAIL=1
    else
        PARSE_MODE="$LAUNCH_CHECK_PARSE_MODE"
        REMOTE_EXIT="$LAUNCH_CHECK_REMOTE_EXIT"
        OUT="$LAUNCH_CHECK_OUT"
        echo "$OUT"

        HAS_PASS=false
        HAS_FAIL=false
        is_sentinel_line "$OUT" "LAUNCH-CHECK-RUNTIME: PASS" && HAS_PASS=true
        is_sentinel_line "$OUT" "LAUNCH-CHECK-RUNTIME: FAIL" && HAS_FAIL=true

        # Positive evidence required for PASS, mirroring env-check.sh: genuine
        # exit 0 AND the PASS sentinel as its own line AND no FAIL sentinel
        # also present. Absence of a FAIL marker is never treated as success.
        if [[ "$REMOTE_EXIT" == "0" && "$HAS_PASS" == true && "$HAS_FAIL" == false ]]; then
            echo "ok    deployed runtime ($CLOUD_ENV) health check passed"
        elif [[ "$HAS_FAIL" == true ]]; then
            echo "FAIL  deployed runtime ($CLOUD_ENV) health check failed"
            FAIL=1
        elif [[ "$HAS_PASS" == false ]]; then
            echo "FAIL  deployed runtime ($CLOUD_ENV) health check output contained no PASS/FAIL sentinel (exitCode=$REMOTE_EXIT, parse mode: $PARSE_MODE) — cannot verify"
            FAIL=1
        else
            echo "FAIL  deployed runtime ($CLOUD_ENV) health check did not report a clean PASS (exitCode=$REMOTE_EXIT) — treating as failure"
            FAIL=1
        fi
    fi
fi

exit $FAIL
