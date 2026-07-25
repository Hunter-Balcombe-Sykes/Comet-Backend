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

# --json/--fields=exitCode,output gives a reliable machine-readable result: the
# CLI's own process can exit 0 even when the remote command failed (outer status
# != remote exitCode — see reference_cloud_cli_joshuahunter memory), so parse the
# JSON payload rather than trusting $? alone.
RAW="$("$CLOUD" command:run "$CLOUD_ENV" --cmd="php artisan launch-check:env --target=$TARGET" --json --fields=exitCode,output --no-interaction 2>&1)"
CLI_EXIT=$?

if [[ $CLI_EXIT -ne 0 ]]; then
    echo "FAIL  cloud CLI invocation failed (exit $CLI_EXIT):"
    echo "$RAW"
    exit 1
fi

if ! echo "$RAW" | jq -e . >/dev/null 2>&1; then
    echo "FAIL  cloud CLI returned non-JSON output — cannot verify deployed env config:"
    echo "$RAW"
    exit 1
fi

if echo "$RAW" | jq -e '.error == true' >/dev/null 2>&1; then
    echo "FAIL  cloud command:run reported an error:"
    echo "$RAW" | jq -r '.message // .errors // .'
    exit 1
fi

REMOTE_EXIT="$(echo "$RAW" | jq -r '.exitCode // empty')"
OUT="$(echo "$RAW" | jq -r '.output // empty')"

if [[ -z "$OUT" ]]; then
    echo "FAIL  could not parse command output from cloud CLI response — cannot verify deployed env config"
    echo "$RAW"
    exit 1
fi

echo "$OUT"

if [[ -z "$REMOTE_EXIT" ]] || [[ "$REMOTE_EXIT" != "0" ]] || ! echo "$OUT" | grep -q "LAUNCH-CHECK-ENV: PASS"; then
    if echo "$OUT" | grep -q "LAUNCH-CHECK-ENV: FAIL"; then
        echo "FAIL  deployed env ($CLOUD_ENV) config check failed"
    else
        echo "FAIL  deployed env ($CLOUD_ENV) config check did not report a clean PASS sentinel (exitCode=${REMOTE_EXIT:-none}) — treating as failure"
    fi
    exit 1
fi

echo "ok    deployed env ($CLOUD_ENV) config check passed"
