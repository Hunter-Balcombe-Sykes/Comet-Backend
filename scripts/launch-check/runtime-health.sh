#!/usr/bin/env bash
# Group H orchestrator — edge/sitepage probe (external) + deployed runtime
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
ALIAS=""
DOMAIN=""
while [[ $# -gt 0 ]]; do
    case "$1" in
        --handle) HANDLE="$2"; shift 2 ;;
        --env)    CLOUD_ENV="$2"; shift 2 ;;
        --target) TARGET="$2"; shift 2 ;;
        --alias)  ALIAS="$2";  shift 2 ;;
        --domain) DOMAIN="$2"; shift 2 ;;
        *) echo "unknown arg: $1"; exit 2 ;;
    esac
done

case "$TARGET" in
    pilot|launch) ;;
    *) echo "error: unknown --target '$TARGET' (must be pilot or launch)"; exit 2 ;;
esac

# production is gated — the SAME shared gate env-check.sh uses, so the two can
# never drift (see lib/cloud-json-parse.sh).
launch_check_prod_gate "$CLOUD_ENV" || exit 1

FAIL=0

echo "── Edge / sitepage ──────────────────────────"
# edge-check.sh reads $LAUNCH_CHECK_HANDLE itself, so the handle is passed via
# env rather than an argv array — avoids a bash-3.2 `set -u` unbound-variable
# abort on an empty positional array (`"${EDGE_ARGS[@]}"` with zero elements),
# which previously killed this script (and the runtime-liveness leg below it
# along with it) whenever no handle/alias/domain was given.
export LAUNCH_CHECK_HANDLE="$HANDLE"
EDGE_ARGS=()
[[ -n "$ALIAS" ]] && EDGE_ARGS+=(--alias "$ALIAS")
[[ -n "$DOMAIN" ]] && EDGE_ARGS+=(--domain "$DOMAIN")
"$DIR/edge-check.sh" "${EDGE_ARGS[@]+"${EDGE_ARGS[@]}"}" || FAIL=1

echo
echo "── Deployed runtime liveness ────────────────"
# Absent tooling is a FAIL, not a WARN. A WARN that cannot reach the exit code is
# indistinguishable from a pass: this leg previously WARNed and left FAIL=0, so a
# run with no `cloud` CLI and no handle probed NOTHING and the runner still
# printed "all automated groups passed". Same convention as env-check.sh, now
# from the same shared helper so it cannot drift again.
if ! launch_check_require_cloud_cli "$CLOUD_ENV" "php artisan launch-check:runtime --target=$TARGET"; then
    FAIL=1
else
    CLOUD="$LAUNCH_CHECK_CLOUD"
    RAW="$("$CLOUD" command:run "$CLOUD_ENV" --cmd="php artisan launch-check:runtime --target=$TARGET" --json --fields=exitCode,output --no-interaction 2>&1)"
    CLI_EXIT=$?

    if [[ $CLI_EXIT -ne 0 ]]; then
        echo "FAIL  cloud CLI invocation failed (exit $CLI_EXIT):"
        launch_check_dump_raw "$RAW"
        FAIL=1
    elif ! launch_check_parse_cloud_result "$RAW"; then
        echo "FAIL  $LAUNCH_CHECK_PARSE_FAIL_REASON — cannot verify deployed runtime health"
        # Only dump the raw payload for genuine parse failures, truncated and
        # redacted — this output is copied verbatim into the run report. A
        # well-formed `{"error":true,...}` record was already extracted above.
        if [[ "$LAUNCH_CHECK_PARSE_IS_ERROR_OBJECT" != "1" ]]; then
            launch_check_dump_raw "$RAW"
        fi
        FAIL=1
    else
        echo "$LAUNCH_CHECK_OUT"
        # Shared verdict convention — identical to env-check.sh by construction.
        launch_check_verdict "$LAUNCH_CHECK_OUT" "$LAUNCH_CHECK_REMOTE_EXIT" \
            "LAUNCH-CHECK-RUNTIME" "deployed runtime ($CLOUD_ENV) health check" \
            "$LAUNCH_CHECK_PARSE_MODE" || FAIL=1
    fi
fi

exit $FAIL
