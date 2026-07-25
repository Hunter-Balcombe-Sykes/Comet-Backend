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

AWK="$(command -v awk || true)"
if [[ -z "$AWK" ]]; then
    echo "FAIL  awk not found — required to repair cloud CLI output"
    exit 1   # fail closed — without the repairer a malformed payload is unverifiable
fi

# JSON-string-aware repairer. Walks the payload one record (physical line) at a
# time, carrying the in-string / in-escape state ACROSS records, and escapes
# every raw control byte that occurs inside a string value:
#   - a record boundary while inside a string  → `\n`  (the CLI's actual bug)
#   - a record boundary while outside a string → a real newline (legal JSON
#     whitespace / separator between concatenated top-level values)
#   - CR, TAB and any other 0x01–0x1F byte inside a string → `\uXXXX`
# Backslash escapes (`\"`, `\\`, …) are passed through untouched, which is what
# keeps the in-string state honest: the CLI escapes quotes correctly, so a `"`
# in the content can never be mistaken for a string terminator.
AWK_REPAIR_JSON='
BEGIN {
    instr = 0; esc = 0
    for (i = 1; i < 32; i++) ctl[sprintf("%c", i)] = sprintf("\\u%04x", i)
}
{
    if (NR > 1) { if (instr) printf "\\n"; else printf "\n" }
    n = length($0)
    for (i = 1; i <= n; i++) {
        c = substr($0, i, 1)
        if (instr) {
            if (esc)          { printf "%s", c; esc = 0; continue }
            if (c == "\\")    { printf "\\"; esc = 1; continue }
            if (c == "\"")    { instr = 0; printf "\""; continue }
            if (c in ctl)     { printf "%s", ctl[c]; continue }
            printf "%s", c
        } else {
            if (c == "\"") { instr = 1 }
            printf "%s", c
        }
    }
}
'

# is_sentinel_line "$multiline_text" "LAUNCH-CHECK-ENV: PASS" — true iff some
# ENTIRE physical line of the text equals the sentinel exactly. Deliberately
# NOT a substring grep and deliberately NOT whitespace-tolerant: a line like
# `audit tail: ...LAUNCH-CHECK-ENV: PASS...` or `  LAUNCH-CHECK-ENV: PASS  `
# must NOT count, only a line that IS the sentinel and nothing else, which is
# exactly how the command emits it (`$this->line('LAUNCH-CHECK-ENV: ...')`).
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

# ---- Step 1/2: obtain a payload `jq` can parse, or fail closed. ----
PAYLOAD=""
PARSE_MODE=""

if printf '%s' "$RAW" | "$JQ" -e . >/dev/null 2>&1; then
    PARSE_MODE="json"
    PAYLOAD="$RAW"
else
    REPAIRED="$(printf '%s' "$RAW" | "$AWK" "$AWK_REPAIR_JSON" 2>/dev/null)"
    if [[ -n "$REPAIRED" ]] && printf '%s' "$REPAIRED" | "$JQ" -e . >/dev/null 2>&1; then
        PARSE_MODE="repaired"
        PAYLOAD="$REPAIRED"
    else
        # No line-scanning fallback, by design. Losing the ability to pass on a
        # payload we cannot structurally understand is the correct trade.
        echo "FAIL  cloud CLI output is not valid JSON and could not be repaired — cannot verify deployed env config"
        echo "$RAW"
        exit 1
    fi
fi

# ---- Step 3: structural extraction. ----
# The payload is a stream of concatenated top-level JSON values (progress
# objects, then the result record). Prefer the LAST object carrying an
# `exitCode` key — that is the result record — and fall back to the last object
# of any shape so the CLI's own `{"error":true,…}` responses are still reported.
# `jq -s` reads the STRUCTURE, so a `{"output":"…` sequence living INSIDE a
# string value is content, not a record, and can never be selected here.
RECORD="$(printf '%s' "$PAYLOAD" | "$JQ" -s -c '
    ([.[] | select(type == "object" and has("exitCode"))] | last)
    // ([.[] | select(type == "object")] | last)
    // empty' 2>/dev/null)"
if [[ -z "$RECORD" ]]; then
    echo "FAIL  cloud CLI output contained no result object (parse mode: $PARSE_MODE) — cannot verify deployed env config"
    echo "$RAW"
    exit 1
fi

if printf '%s' "$RECORD" | "$JQ" -e '.error == true' >/dev/null 2>&1; then
    echo "FAIL  cloud command:run reported an error:"
    printf '%s' "$RECORD" | "$JQ" -r '.message // .errors // .'
    exit 1
fi

# Both fields must be present AND of the right JSON type. A missing, null,
# string-typed or otherwise non-numeric exitCode yields empty → fail closed.
REMOTE_EXIT="$(printf '%s' "$RECORD" | "$JQ" -r 'if (.exitCode | type) == "number" then (.exitCode | tostring) else empty end' 2>/dev/null)"
OUT="$(printf '%s' "$RECORD" | "$JQ" -r 'if (.output | type) == "string" then .output else empty end' 2>/dev/null)"

if [[ -z "$REMOTE_EXIT" ]]; then
    echo "FAIL  could not determine the remote command's exit code (parse mode: $PARSE_MODE) — cannot verify deployed env config"
    echo "$RAW"
    exit 1
fi

if [[ -z "$(printf '%s' "$OUT" | tr -d '[:space:]')" ]]; then
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
