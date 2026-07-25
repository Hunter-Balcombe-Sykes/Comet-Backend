#!/usr/bin/env bash
# Shared `cloud command:run --json` parsing — extracted from env-check.sh so
# every launch-check probe that shells out through the `cloud` CLI (env-check.sh,
# runtime-health.sh, …) uses the SAME proven repair-then-jq pipeline instead of
# reinventing (and re-breaking) it. Sourced, not executed.
#
# `cloud command:run --json` has a confirmed, live, INTERMITTENT serializer bug:
# the `output` field's newlines are sometimes emitted as a literal raw 0x0A byte
# instead of the `\n` escape JSON requires, which breaks strict JSON parsing on
# essentially any multi-line remote output. Observed real shape is
# `{"output":"…","exitCode":N}` — output FIRST, exitCode LAST — optionally
# preceded by progress objects on their own lines. Quotes inside the content ARE
# escaped correctly; unescaped control bytes are the CLI's one and only defect.
#
# Strategy:
#   1. try strict `jq` parsing (PARSE_MODE=json);
#   2. if that fails, REPAIR the payload — a state machine walks the bytes
#      tracking whether it is inside a JSON string and escapes the raw control
#      bytes that appear inside string values — then parse the repaired text
#      with `jq` (PARSE_MODE=repaired);
#   3. if the repaired text still does not parse, FAIL CLOSED. There is no
#      line-scanning fallback and there must never be one — two earlier
#      versions of this parser shipped a raw-text fallback and both produced a
#      FALSE PASS (see scripts/launch-check/README.md and
#      env-check-parser.test.sh for the exact PoCs). Content inside a JSON
#      string can mimic any textual landmark you pick; once a real parser
#      reads the structure, bytes *inside* a string value can never become a
#      sibling record, a sibling field, or a sibling line.

# JSON-string-aware repairer. Walks the payload one record (physical line) at a
# time, carrying the in-string / in-escape state ACROSS records, and escapes
# every raw control byte that occurs inside a string value:
#   - a record boundary while inside a string  → `\n`  (the CLI's actual bug)
#   - a record boundary while outside a string → a real newline (legal JSON
#     whitespace / separator between concatenated top-level values)
#   - CR, TAB and any other 0x01–0x1F byte inside a string → `\uXXXX`
# Backslash escapes (`\"`, `\\`, …) are passed through untouched, which is what
# keeps the in-string state honest.
LAUNCH_CHECK_AWK_REPAIR_JSON='
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

# launch_check_resolve_cloud_cli — sets global LAUNCH_CHECK_CLOUD to the `cloud`
# binary (PATH first, then the composer global bin), or empty if not found.
launch_check_resolve_cloud_cli() {
    LAUNCH_CHECK_CLOUD="$(command -v cloud || true)"
    if [[ -z "$LAUNCH_CHECK_CLOUD" && -x "$HOME/.composer/vendor/bin/cloud" ]]; then
        LAUNCH_CHECK_CLOUD="$HOME/.composer/vendor/bin/cloud"
    fi
}

# is_sentinel_line "$multiline_text" "SENTINEL: TEXT" — true iff some ENTIRE
# physical line of the text equals the sentinel exactly. Deliberately NOT a
# substring grep and deliberately NOT whitespace-tolerant.
is_sentinel_line() {
    local text="$1" sentinel="$2" ln
    while IFS= read -r ln; do
        [[ "$ln" == "$sentinel" ]] && return 0
    done <<< "$text"
    return 1
}

# launch_check_parse_cloud_result RAW — parses a `cloud command:run --json
# --fields=exitCode,output` payload. On success, sets LAUNCH_CHECK_PARSE_MODE
# (json|repaired), LAUNCH_CHECK_REMOTE_EXIT and LAUNCH_CHECK_OUT, and returns 0.
# On any failure — including a well-formed `{"error":true,...}` response —
# returns 1 and sets LAUNCH_CHECK_PARSE_FAIL_REASON to a human-readable cause.
# Never infers success from the absence of a failure marker.
launch_check_parse_cloud_result() {
    local raw="$1"
    LAUNCH_CHECK_PARSE_MODE=""
    LAUNCH_CHECK_REMOTE_EXIT=""
    LAUNCH_CHECK_OUT=""
    LAUNCH_CHECK_PARSE_FAIL_REASON=""
    LAUNCH_CHECK_PARSE_IS_ERROR_OBJECT=0

    local jq_bin awk_bin
    jq_bin="$(command -v jq || true)"
    awk_bin="$(command -v awk || true)"
    if [[ -z "$jq_bin" ]]; then
        LAUNCH_CHECK_PARSE_FAIL_REASON="jq not found — required to parse cloud CLI output"
        return 1
    fi
    if [[ -z "$awk_bin" ]]; then
        LAUNCH_CHECK_PARSE_FAIL_REASON="awk not found — required to repair cloud CLI output"
        return 1
    fi

    if [[ -z "$raw" ]]; then
        LAUNCH_CHECK_PARSE_FAIL_REASON="cloud CLI produced no output"
        return 1
    fi

    local payload=""
    if printf '%s' "$raw" | "$jq_bin" -e . >/dev/null 2>&1; then
        LAUNCH_CHECK_PARSE_MODE="json"
        payload="$raw"
    else
        local repaired
        repaired="$(printf '%s' "$raw" | "$awk_bin" "$LAUNCH_CHECK_AWK_REPAIR_JSON" 2>/dev/null)"
        if [[ -n "$repaired" ]] && printf '%s' "$repaired" | "$jq_bin" -e . >/dev/null 2>&1; then
            LAUNCH_CHECK_PARSE_MODE="repaired"
            payload="$repaired"
        else
            # No line-scanning fallback, by design.
            LAUNCH_CHECK_PARSE_FAIL_REASON="cloud CLI output is not valid JSON and could not be repaired"
            return 1
        fi
    fi

    # The payload is a stream of concatenated top-level JSON values (progress
    # objects, then the result record). Prefer the LAST object carrying an
    # `exitCode` key — that is the result record — and fall back to the last
    # object of any shape so `{"error":true,…}` responses are still reported.
    local record
    record="$(printf '%s' "$payload" | "$jq_bin" -s -c '
        ([.[] | select(type == "object" and has("exitCode"))] | last)
        // ([.[] | select(type == "object")] | last)
        // empty' 2>/dev/null)"
    if [[ -z "$record" ]]; then
        LAUNCH_CHECK_PARSE_FAIL_REASON="cloud CLI output contained no result object (parse mode: $LAUNCH_CHECK_PARSE_MODE)"
        return 1
    fi

    if printf '%s' "$record" | "$jq_bin" -e '.error == true' >/dev/null 2>&1; then
        local msg
        msg="$(printf '%s' "$record" | "$jq_bin" -r '.message // .errors // .' 2>/dev/null)"
        LAUNCH_CHECK_PARSE_FAIL_REASON="cloud command:run reported an error: $msg"
        # Callers should NOT also dump the raw payload for this case — the
        # extracted message above is already the narrowest useful summary,
        # and the raw record adds nothing but surface area.
        LAUNCH_CHECK_PARSE_IS_ERROR_OBJECT=1
        return 1
    fi

    # Both fields must be present AND of the right JSON type. A missing, null,
    # string-typed or otherwise non-numeric exitCode yields empty → fail closed.
    local remote_exit out
    remote_exit="$(printf '%s' "$record" | "$jq_bin" -r 'if (.exitCode | type) == "number" then (.exitCode | tostring) else empty end' 2>/dev/null)"
    out="$(printf '%s' "$record" | "$jq_bin" -r 'if (.output | type) == "string" then .output else empty end' 2>/dev/null)"

    if [[ -z "$remote_exit" ]]; then
        LAUNCH_CHECK_PARSE_FAIL_REASON="could not determine the remote command's exit code (parse mode: $LAUNCH_CHECK_PARSE_MODE)"
        return 1
    fi

    if [[ -z "$(printf '%s' "$out" | tr -d '[:space:]')" ]]; then
        LAUNCH_CHECK_PARSE_FAIL_REASON="could not locate or parse the command output (parse mode: $LAUNCH_CHECK_PARSE_MODE, exitCode=$remote_exit)"
        return 1
    fi

    LAUNCH_CHECK_REMOTE_EXIT="$remote_exit"
    LAUNCH_CHECK_OUT="$out"
    return 0
}
