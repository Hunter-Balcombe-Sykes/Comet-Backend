#!/usr/bin/env bash
# Deployed-env config check — runs `launch-check:env` ON the Laravel Cloud env
# and reports whether every required var/config value is set correctly. Reads
# the env's OWN resolved config (via the app), which no external probe can see.
#
# PARSE_MODE design (see the two long comments below before "simplifying" this):
# `cloud command:run --json` has a confirmed, live, INTERMITTENT serializer bug
# — the `output` field's newlines are sometimes emitted as a literal raw 0x0A
# byte instead of the `\n` escape JSON requires, which breaks strict JSON
# parsing on essentially any multi-line remote output (i.e. most real output,
# including this probe's own launch-check:env). We can't fix the CLI, so this
# script tries strict `jq` parsing first (PARSE_MODE=json) and only falls back
# to raw-text matching (PARSE_MODE=raw-fallback) when `jq` rejects the payload.
# The fallback is NOT a plain substring grep over the whole blob — an earlier
# version was and that let attacker/log-controlled text anywhere in the
# command's own output (a stray audit-log line, a nested subprocess dump, an
# embedded example JSON snippet) forge a PASS. It now (a) takes the exit code
# ONLY from bytes anchored to the true end of the payload — which the `cloud`
# CLI itself appends AFTER the command's own output content, so nothing in
# that content can ever be positioned later — and (b) requires the PASS/FAIL
# sentinel to appear as an entire physical line on its own (exact match), not
# a substring anywhere, so quoting the sentinel inside a longer line of
# unrelated text cannot satisfy it.
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

# is_sentinel_line "$multiline_text" "LAUNCH-CHECK-ENV: PASS" — true iff some
# ENTIRE physical line of the text equals the sentinel exactly. Deliberately
# NOT a substring grep: a line like `audit tail: ...LAUNCH-CHECK-ENV: PASS...`
# must NOT count, only a line that IS the sentinel and nothing else, which is
# how the command actually emits it (`$this->line('LAUNCH-CHECK-ENV: ...')`).
is_sentinel_line() {
    local text="$1" sentinel="$2" ln
    while IFS= read -r ln; do
        [[ "$ln" == "$sentinel" ]] && return 0
    done <<< "$text"
    return 1
}

# --json/--fields=exitCode,output gives a machine-readable result — needed because
# the CLI's own process can exit 0 even when the remote command failed (outer
# status != remote exitCode — see reference_cloud_cli_joshuahunter memory).
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
OUT=""
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
else
    # Malformed JSON (the known bug above). Recover the two facts we need
    # WITHOUT trusting a naive "search the whole blob" match, since the blob
    # now contains attacker/log-controlled bytes we cannot fully parse:
    #
    # 1. exitCode: the `cloud` CLI always appends `,"exitCode":<digits>}` as
    #    the literal final bytes of the whole payload — it serializes this
    #    AFTER the command's own output content, so nothing inside that
    #    content (however it's formatted) can ever land after it. Anchor the
    #    match to the true end of $RAW via bash's own `$` (end-of-string),
    #    not grep's per-line `$` — $RAW may contain real embedded newlines,
    #    and grep's `$` would match end-of-EACH-line, not end of the buffer.
    RAW_LEN=${#RAW}
    TAIL_LEN=4096
    # bash's `${var: -N}` returns EMPTY (not the whole string) when N >= the
    # string's length, instead of clamping to offset 0 — so cap it explicitly.
    [[ $TAIL_LEN -gt $RAW_LEN ]] && TAIL_LEN=$RAW_LEN
    TAIL="${RAW: -$TAIL_LEN}"
    if [[ "$TAIL" =~ ,\"exitCode\":([0-9]+)\}[[:space:]]*$ ]]; then
        REMOTE_EXIT="${BASH_REMATCH[1]}"
        MATCHED_SUFFIX="${BASH_REMATCH[0]}"
        # 2. output: strip that exact trailing suffix, then find the LAST
        #    physical line that STARTS WITH the literal `{"output":"` token
        #    — the genuine record always begins its own line with this exact
        #    prefix (immediately after a real newline, or at buffer start).
        #    A forged occurrence embedded mid-line (e.g. "...tail: {"output":"...")
        #    does not start a line and is skipped, so it cannot be mistaken
        #    for the real record boundary.
        PREFIX="${RAW%"$MATCHED_SUFFIX"}"
        FOUND_LINE=0
        LINE_NO=0
        while IFS= read -r ln; do
            LINE_NO=$((LINE_NO + 1))
            case "$ln" in
                '{"output":"'*) FOUND_LINE=$LINE_NO ;;
            esac
        done <<< "$PREFIX"
        if [[ $FOUND_LINE -gt 0 ]]; then
            REGION="$(printf '%s\n' "$PREFIX" | tail -n "+$FOUND_LINE")"
            # Strip the literal `{"output":"` from the first line of the region,
            # and the closing `"` of the JSON string value from the very end
            # (the stripped MATCHED_SUFFIX started at the comma AFTER that quote).
            OUT="$(printf '%s\n' "$REGION" | sed '1s/^{"output":"//')"
            OUT="${OUT%\"}"
        fi
    fi
fi

if [[ -z "$REMOTE_EXIT" ]]; then
    echo "FAIL  could not determine the remote command's exit code (parse mode: $PARSE_MODE) — cannot verify deployed env config"
    echo "$RAW"
    exit 1
fi

if [[ -z "$(echo "$OUT" | tr -d '[:space:]')" ]]; then
    echo "FAIL  could not locate or parse the command output (parse mode: $PARSE_MODE, exitCode=$REMOTE_EXIT) — cannot verify deployed env config"
    echo "$RAW"
    exit 1
fi

echo "$OUT"

HAS_PASS=false
HAS_FAIL=false
is_sentinel_line "$OUT" "LAUNCH-CHECK-ENV: PASS" && HAS_PASS=true
is_sentinel_line "$OUT" "LAUNCH-CHECK-ENV: FAIL" && HAS_FAIL=true

# Positive evidence required for PASS: genuine exit 0 AND the PASS sentinel
# present as its own line AND no FAIL sentinel also present. Never infer
# success from the mere absence of a FAIL marker — a truncated/garbled/
# no-sentinel/spoofed payload must fail closed, not pass by default.
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
