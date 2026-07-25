#!/usr/bin/env bash
# Deployed-env config check — runs `launch-check:env` ON the Laravel Cloud env
# and reports whether every required var/config value is set correctly. Reads
# the env's OWN resolved config (via the app), which no external probe can see.
set -uo pipefail

CLOUD_ENV="development"   # dev-api; prod is gated
TARGET="pilot"            # dev legitimately deviates → warn, not fail
while [[ $# -gt 0 ]]; do
    case "$1" in
        --env)    CLOUD_ENV="$2"; shift 2 ;;
        --target) TARGET="$2"; shift 2 ;;
        *) echo "unknown arg: $1"; exit 2 ;;
    esac
done

# production is gated: requires an explicit opt-in env var so it can never fire
# by accident from a plain `--env production` (prod Supabase ref must stay out of
# this suite's default path — see CLAUDE.md).
if [[ "$CLOUD_ENV" == "production" && "${LAUNCH_CHECK_CONFIRM_PROD:-}" != "1" ]]; then
    echo "FAIL  refusing to run against production without LAUNCH_CHECK_CONFIRM_PROD=1 (prod probing is gated)"
    exit 1
fi

# Resolve the cloud CLI (PATH first, then the composer global bin — see CLAUDE.md).
CLOUD="$(command -v cloud || true)"
[[ -z "$CLOUD" && -x "$HOME/.composer/vendor/bin/cloud" ]] && CLOUD="$HOME/.composer/vendor/bin/cloud"
if [[ -z "$CLOUD" ]]; then
    echo "FAIL  cloud CLI not found — run manually:"
    echo "      cloud command:run $CLOUD_ENV --cmd=\"php artisan launch-check:env --target=$TARGET\""
    exit 1   # fail closed — absent tooling means this check did not run, not that it passed
fi

JQ="$(command -v jq || true)"
if [[ -z "$JQ" ]]; then
    echo "FAIL  jq not found — required to parse cloud CLI output"
    exit 1   # fail closed — same rule as the missing-cloud-CLI case above
fi

# --json/--fields=exitCode,output gives a machine-readable result — needed because
# the CLI's own process can exit 0 even when the remote command failed (outer
# status != remote exitCode — see reference_cloud_cli_joshuahunter memory).
#
# KNOWN CLI BUG (confirmed live, reproducible, intermittent): the `output` field
# is meant to be a JSON string with embedded newlines escaped as `\n`, but the
# CLI's serializer sometimes emits a literal unescaped 0x0A byte instead —
# breaking `jq -e .` on ANY multi-line remote output (which is most real output,
# including this probe's own launch-check:env). Not fixable upstream by us. So:
# try strict JSON parsing first (handles short/single-line responses, including
# the CLI's own `{"error":true,...}` payloads, cleanly); if that fails, fall back
# to matching the exit code and PASS/FAIL sentinel directly against the raw text
# — a plain substring/regex match works regardless of a broken escape elsewhere
# in the payload, since neither pattern itself spans the break.
RAW="$("$CLOUD" command:run "$CLOUD_ENV" --cmd="php artisan launch-check:env --target=$TARGET" --json --fields=exitCode,output --no-interaction 2>&1)"
CLI_EXIT=$?

if [[ $CLI_EXIT -ne 0 ]]; then
    echo "FAIL  cloud CLI invocation failed (exit $CLI_EXIT):"
    echo "$RAW"
    exit 1
fi

if [[ -z "$RAW" ]]; then
    echo "FAIL  cloud CLI produced no output — cannot verify deployed env config"
    exit 1
fi

REMOTE_EXIT=""
OUT="$RAW"
PARSE_MODE="raw-fallback"

if echo "$RAW" | "$JQ" -e . >/dev/null 2>&1; then
    PARSE_MODE="json"
    if echo "$RAW" | "$JQ" -e '.error == true' >/dev/null 2>&1; then
        echo "FAIL  cloud command:run reported an error:"
        echo "$RAW" | "$JQ" -r '.message // .errors // .'
        exit 1
    fi
    REMOTE_EXIT="$(echo "$RAW" | "$JQ" -r '.exitCode // empty')"
    OUT="$(echo "$RAW" | "$JQ" -r '.output // empty')"
    if [[ -z "$OUT" ]]; then
        echo "FAIL  could not parse command output from cloud CLI response — cannot verify deployed env config"
        echo "$RAW"
        exit 1
    fi
else
    # Malformed JSON (the known bug above). Pull the exit code straight out of
    # the raw text: `"exitCode":<digits>` is a plain literal that survives an
    # unescaped-newline break elsewhere in the payload, so a substring/regex
    # match is reliable here even though a full parse is not. Use the LAST
    # match — a multi-line output could coincidentally contain the substring
    # earlier, but the CLI always places the real field at the end.
    REMOTE_EXIT="$(printf '%s' "$RAW" | grep -oE '"exitCode":[0-9]+' | tail -1 | grep -oE '[0-9]+$' || true)"
fi

echo "$OUT"

if [[ -z "$REMOTE_EXIT" ]]; then
    echo "FAIL  could not determine the remote command's exit code (parse mode: $PARSE_MODE) — cannot verify deployed env config"
    exit 1
fi

HAS_PASS=false
HAS_FAIL=false
echo "$RAW" | grep -q "LAUNCH-CHECK-ENV: PASS" && HAS_PASS=true
echo "$RAW" | grep -q "LAUNCH-CHECK-ENV: FAIL" && HAS_FAIL=true

# Positive evidence required for PASS: exit 0 AND the PASS sentinel present AND
# no FAIL sentinel also present anywhere in the payload. Never infer success
# from the mere absence of a FAIL marker — a truncated/garbled/no-sentinel
# payload must fail closed, not pass by default.
if [[ "$REMOTE_EXIT" == "0" && "$HAS_PASS" == true && "$HAS_FAIL" == false ]]; then
    echo "ok    deployed env ($CLOUD_ENV) config check passed"
    exit 0
fi

if [[ "$HAS_FAIL" == true ]]; then
    echo "FAIL  deployed env ($CLOUD_ENV) config check failed"
elif [[ "$HAS_PASS" == false ]]; then
    echo "FAIL  deployed env ($CLOUD_ENV) config check output contained no PASS/FAIL sentinel (exitCode=$REMOTE_EXIT, parse mode: $PARSE_MODE) — cannot verify"
else
    echo "FAIL  deployed env ($CLOUD_ENV) config check did not report a clean PASS (exitCode=$REMOTE_EXIT) — treating as failure"
fi
exit 1
