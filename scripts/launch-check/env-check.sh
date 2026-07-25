#!/usr/bin/env bash
# Deployed-env config check — runs `launch-check:env` ON the Laravel Cloud env
# and reports whether every required var/config value is set correctly. Reads
# the env's OWN resolved config (via the app), which no external probe can see.
#
# PARSE_MODE design — READ THIS BEFORE "SIMPLIFYING" THE PARSER.
#
# `cloud command:run --json` has a confirmed, live, INTERMITTENT serializer bug:
# the `output` field's newlines are sometimes emitted as a literal raw 0x0A byte
# instead of the `\n` escape JSON requires, which breaks strict JSON parsing on
# essentially any multi-line remote output (i.e. most real output, including
# this probe's own `launch-check:env`). Observed real shape is
# `{"output":"…","exitCode":N}` — output FIRST, exitCode LAST — optionally
# preceded by progress objects on their own lines. Quotes inside the content ARE
# escaped correctly; unescaped control bytes are the CLI's one and only defect.
#
# We cannot fix the CLI, so this script:
#   1. tries strict `jq` parsing first (PARSE_MODE=json);
#   2. if that fails, REPAIRS the payload — a state machine walks the bytes
#      tracking whether it is inside a JSON string and escapes the raw control
#      bytes that appear inside string values — then parses the repaired text
#      with `jq` (PARSE_MODE=repaired);
#   3. if the repaired text still does not parse, FAILS CLOSED. There is no
#      line-scanning fallback and there must never be one again.
#
# Why no line scanning: two previous versions shipped a raw-text fallback and
# both produced a FALSE PASS. Text inside the remote command's own output can
# mimic any textual landmark you pick — an embedded `"exitCode":0`, a quoted
# PASS sentinel, even a whole decoy line starting with the literal `{"output":"`
# token that fakes a second record boundary. Heuristics over untrusted content
# keep losing. Repair-then-parse kills that entire class BY CONSTRUCTION: once a
# real JSON parser reads the structure, no bytes *inside* a string value can
# become a sibling record, a sibling field, or a sibling line.
#
# The parser itself now lives in lib/cloud-json-parse.sh, shared with every
# other probe that shells out through the `cloud` CLI (e.g. runtime-health.sh)
# — see that file for the full algorithm description.
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/cloud-json-parse.sh
source "$DIR/lib/cloud-json-parse.sh"

CLOUD_ENV="development"   # dev-api; prod is gated
TARGET="pilot"            # dev legitimately deviates → warn, not fail
while [[ $# -gt 0 ]]; do
    case "$1" in
        --env)    CLOUD_ENV="$2"; shift 2 ;;
        --target) TARGET="$2"; shift 2 ;;
        *) echo "unknown arg: $1"; exit 2 ;;
    esac
done

# production is gated (shared gate — see lib/cloud-json-parse.sh).
launch_check_prod_gate "$CLOUD_ENV" || exit 1

# Resolve the cloud CLI (PATH first, then the composer global bin — see CLAUDE.md).
# Fails closed: absent tooling means this check did not run, not that it passed.
launch_check_require_cloud_cli "$CLOUD_ENV" "php artisan launch-check:env --target=$TARGET" || exit 1
CLOUD="$LAUNCH_CHECK_CLOUD"

# --json/--fields=exitCode,output gives a machine-readable result — needed because
# the CLI's own process can exit 0 even when the remote command failed (outer
# status != remote exitCode — see reference_cloud_cli_joshuahunter memory).
RAW="$("$CLOUD" command:run "$CLOUD_ENV" --cmd="php artisan launch-check:env --target=$TARGET" --json --fields=exitCode,output --no-interaction 2>&1)"
CLI_EXIT=$?

if [[ $CLI_EXIT -ne 0 ]]; then
    echo "FAIL  cloud CLI invocation failed (exit $CLI_EXIT):"
    launch_check_dump_raw "$RAW"
    exit 1
fi

if ! launch_check_parse_cloud_result "$RAW"; then
    echo "FAIL  $LAUNCH_CHECK_PARSE_FAIL_REASON — cannot verify deployed env config"
    # Only dump the raw payload for genuine parse failures, truncated and
    # redacted — this output is copied verbatim into the run report. A
    # well-formed `{"error":true,...}` record was already extracted above.
    if [[ "$LAUNCH_CHECK_PARSE_IS_ERROR_OBJECT" != "1" ]]; then
        launch_check_dump_raw "$RAW"
    fi
    exit 1
fi

echo "$LAUNCH_CHECK_OUT"

# Shared verdict convention — see launch_check_verdict in lib/cloud-json-parse.sh.
launch_check_verdict "$LAUNCH_CHECK_OUT" "$LAUNCH_CHECK_REMOTE_EXIT" \
    "LAUNCH-CHECK-ENV" "deployed env ($CLOUD_ENV) config check" "$LAUNCH_CHECK_PARSE_MODE"
