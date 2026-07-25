#!/usr/bin/env bash
# Regression test for env-check.sh's cloud-CLI-output parser. Plain executable
# bash — no `bats` dependency, run it directly:
#   scripts/launch-check/env-check-parser.test.sh
# Must pass under stock macOS /bin/bash 3.2.57 as well as modern bash.
#
# WHY THIS FILE EXISTS
# Two earlier versions of env-check.sh shipped a FALSE PASS. Both used raw-text
# line scanning over the cloud CLI's (intermittently invalid) JSON payload:
#   round 1 — grepped `"exitCode":` and the sentinels over the whole blob, so an
#             embedded `"exitCode":0` + PASS text in the remote command's OWN
#             output reported `passed` on a genuine remote exit 1;
#   round 2 — anchored exitCode to the payload tail (correct) but picked the
#             record start by "last physical line beginning with `{"output":"`",
#             so a DECOY line inside the output content faked a SECOND RECORD.
# The blind spot both times: cases tested decoys *within* a record but never a
# decoy faking a *record boundary*. Those are now mandatory — see section B.
# NEVER weaken an assertion to make a case pass: if a payload shape is ambiguous
# or unrepairable, the only correct expectation is FAIL (exit 1). A PASS must
# require positive structural evidence.
#
# TWO HARNESS BUGS THIS FILE ITSELF HAD — do not reintroduce:
#  1. Payloads passed as an ARGUMENT got brace-expanded: payload text contains
#     `{...,...}`, which bash expands inside a `$(...)`-produced argument on a
#     continued command line, silently splitting and truncating the payload so
#     the case "passed" against a mangled input. Payloads are now staged into
#     $PF by REDIRECTION and read from there.
#  2. A `builder | check` pipeline ran `check` in a SUBSHELL (bash has no
#     lastpipe here), discarding every CASE_NUM/FAILURES increment — the suite
#     would have reported success no matter what failed. Never pipe into check.
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Overridable so this historical-regression check is repeatable against an
# arbitrary revision (e.g. `ENV_CHECK=<(git show <ref>:scripts/launch-check/env-check.sh) ...`
# or a checked-out copy) without cloning the whole tree.
ENV_CHECK="${ENV_CHECK:-$DIR/env-check.sh}"
# Guard: an empty/absent script exits 0 silently, which would turn every
# expected-FAIL case into a "pass". Refuse to run against one.
if [[ ! -s "$ENV_CHECK" ]]; then
    echo "ENV-CHECK-PARSER-TEST: $ENV_CHECK is missing or empty — refusing to run"
    exit 2
fi
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

FAILURES=0
CASE_NUM=0
PF="$WORK/payload.bin"   # every case stages its payload bytes here

# ---------------------------------------------------------------------------
# Payload builders — mirror how the real `cloud command:run --json
# --fields=exitCode,output` serializes a result record: `output` FIRST,
# `exitCode` LAST, quotes inside the content correctly escaped.
# ---------------------------------------------------------------------------

# escape_json_content CONTENT — backslashes then quotes, exactly as the CLI does.
escape_json_content() {
    printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'
}

# malformed_payload CONTENT EXITCODE — the CLI's BUGGY shape: newlines inside
# the `output` string value are left as raw 0x0A bytes (invalid JSON).
malformed_payload() {
    printf '{"output":"%s\n","exitCode":%s}' "$(escape_json_content "$1")" "$2"
}

# wellformed_payload CONTENT EXITCODE — the CLI's CORRECT shape: newlines
# escaped as \n, valid JSON.
wellformed_payload() {
    local esc
    esc="$(escape_json_content "$1" | awk '{ if (NR > 1) printf "\\n"; printf "%s", $0 } END { printf "\\n" }')"
    printf '{"output":"%s","exitCode":%s}' "$esc" "$2"
}

# ---------------------------------------------------------------------------
# Harness
# ---------------------------------------------------------------------------

# make_bin BINROOT [--no-jq] — build an isolated PATH dir.
make_bin() {
    local binroot="$1"; shift
    local skip_jq=0 b p
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --no-jq) skip_jq=1 ;;
        esac
        shift
    done
    mkdir -p "$binroot"
    for b in bash sh cat sed tr awk grep tail basename dirname env printf php; do
        p="$(command -v "$b" 2>/dev/null || true)"
        [[ -n "$p" ]] && ln -sf "$p" "$binroot/$b"
    done
    if [[ $skip_jq -eq 0 ]]; then
        p="$(command -v jq || true)"
        [[ -n "$p" ]] && ln -sf "$p" "$binroot/jq"
    fi
    return 0
}

# assert_exit NAME EXPECTED_EXIT — runs env-check.sh with the prepared PATH.
# Globals: BINROOT, RUN_ENV_ARGS, RUN_CONFIRM_PROD. HOME is forced to a
# nonexistent path so the `$HOME/.composer/vendor/bin/cloud` fallback can never
# reach the developer's real CLI and hit a live environment.
assert_exit() {
    local name="$1" expected="$2" out actual
    out="$(PATH="$BINROOT" HOME="/nonexistent" \
        LAUNCH_CHECK_CONFIRM_PROD="${RUN_CONFIRM_PROD:-}" \
        bash "$ENV_CHECK" ${RUN_ENV_ARGS:-} 2>&1)"
    actual=$?
    if [[ "$actual" == "$expected" ]]; then
        echo "ok    $name (exit $actual)"
    else
        echo "FAIL  $name — expected exit $expected, got $actual"
        echo "      output:"
        printf '%s\n' "$out" | sed 's/^/      /'
        FAILURES=$((FAILURES + 1))
    fi
}

# check NAME EXPECTED_EXIT — stubs `cloud` to emit the bytes currently staged in
# $PF verbatim (byte-exact, raw control bytes and all) and asserts the exit code.
check() {
    local name="$1" expected="$2"
    CASE_NUM=$((CASE_NUM + 1))
    BINROOT="$WORK/bin$CASE_NUM"
    make_bin "$BINROOT"
    cp "$PF" "$WORK/payload$CASE_NUM"
    printf '#!/bin/bash\ncat %q\nexit 0\n' "$WORK/payload$CASE_NUM" > "$BINROOT/cloud"
    chmod +x "$BINROOT/cloud"
    RUN_ENV_ARGS="" RUN_CONFIRM_PROD="" assert_exit "$name" "$expected"
}

# =========================================================================
# A. Happy paths — the only shapes allowed to exit 0.
# =========================================================================

wellformed_payload '  ok    present: app.key
target=pilot  ok=11 warn=0 fail=0
LAUNCH-CHECK-ENV: PASS' 0 > "$PF"
check "well-formed JSON, exit 0, PASS sentinel" 0

malformed_payload '  ok    present: app.key

target=pilot  ok=11 warn=0 fail=0
LAUNCH-CHECK-ENV: PASS' 0 > "$PF"
check "malformed JSON (raw 0x0A), genuine PASS, exit 0" 0

# The repair must handle LEGITIMATE embedded JSON in the output content, not
# just attacks — a genuine PASS whose text quotes a sample cloud response.
malformed_payload '  ok    present: app.key
  note: a cloud response looks like {"output":"hello","exitCode":0}
target=pilot  ok=11 warn=0 fail=0
LAUNCH-CHECK-ENV: PASS' 0 > "$PF"
check 'genuine PASS whose output legitimately embeds a {"output":...} JSON sample' 0

# Real payloads prefix the result record with progress objects on their own lines.
{
    printf '{"command_id":"comm-1","status":"command.pending","message":"Queued"}\n'
    printf '{"command_id":"comm-1","status":"command.success","message":"Success"}\n'
    malformed_payload '  ok    present: app.key
LAUNCH-CHECK-ENV: PASS' 0
} > "$PF"
check "progress objects then malformed result record, genuine PASS" 0

# Raw TAB and CR inside the output content must survive repair, not break it.
printf '{"output":"  ok\tpresent: app.key\r\nLAUNCH-CHECK-ENV: PASS\n","exitCode":0}' > "$PF"
check "raw TAB and CR inside output content, genuine PASS" 0

# =========================================================================
# B. Decoy record boundaries — the exact blind spot that shipped twice.
#    Every one must FAIL: in each, the genuine exitCode is 0 and the genuine
#    content says FAIL, so an exit 0 here can only mean the decoy won.
# =========================================================================

# The reviewer's literal payload, byte-exact: raw 0x0A newlines, UNESCAPED inner
# quotes, and a decoy line starting with the bare `{"output":"` token. Unescaped
# quotes desync any JSON string tracker, so this is unrepairable → fail closed.
cat > "$PF" <<'PAYLOAD'
{"output":"target=pilot ok=9 warn=0 fail=2
LAUNCH-CHECK-ENV: FAIL
{"output":"LAUNCH-CHECK-ENV: PASS
","exitCode":0}
PAYLOAD
check "reviewer's exact decoy payload (round-2 false-PASS PoC)" 1

cat > "$PF" <<'PAYLOAD'
{"output":"{"output":"LAUNCH-CHECK-ENV: PASS
  FAIL  missing: nightwatch.token
LAUNCH-CHECK-ENV: FAIL
","exitCode":0}
PAYLOAD
check "decoy record boundary at START of output content" 1

cat > "$PF" <<'PAYLOAD'
{"output":"  FAIL  missing: nightwatch.token
{"output":"LAUNCH-CHECK-ENV: PASS
target=pilot ok=9 warn=0 fail=2
LAUNCH-CHECK-ENV: FAIL
","exitCode":0}
PAYLOAD
check "decoy record boundary in the MIDDLE of output content" 1

cat > "$PF" <<'PAYLOAD'
{"output":"  FAIL  missing: nightwatch.token
LAUNCH-CHECK-ENV: FAIL
{"output":"LAUNCH-CHECK-ENV: PASS
","exitCode":0}
PAYLOAD
check "decoy record boundary at END of output content" 1

cat > "$PF" <<'PAYLOAD'
{"output":"{"output":"LAUNCH-CHECK-ENV: PASS
  FAIL  missing: nightwatch.token
{"output":"LAUNCH-CHECK-ENV: PASS
LAUNCH-CHECK-ENV: FAIL
{"output":"LAUNCH-CHECK-ENV: PASS
","exitCode":0}
PAYLOAD
check "MULTIPLE decoy record boundaries in one payload" 1

# Decoy that fakes a whole record INCLUDING a trailing `,"exitCode":0}` suffix.
cat > "$PF" <<'PAYLOAD'
{"output":"  FAIL  missing: nightwatch.token
LAUNCH-CHECK-ENV: FAIL
{"output":"LAUNCH-CHECK-ENV: PASS
","exitCode":0}
","exitCode":1}
PAYLOAD
check "decoy fakes a whole record INCLUDING a trailing exitCode suffix" 1

# The repairable variant: the real CLI escapes quotes, so a decoy it could
# actually emit DOES parse after repair — and structural parsing then correctly
# reads the whole thing as ONE output string containing a genuine FAIL sentinel.
malformed_payload '  FAIL  missing: nightwatch.token
{"output":"LAUNCH-CHECK-ENV: PASS","exitCode":0}
LAUNCH-CHECK-ENV: FAIL' 0 > "$PF"
check "repairable decoy (CLI-escaped) + genuine FAIL sentinel, genuine exit 0" 1

wellformed_payload '{"output":"LAUNCH-CHECK-ENV: PASS","exitCode":0}
LAUNCH-CHECK-ENV: FAIL' 0 > "$PF"
check "well-formed decoy record inside output string, genuine FAIL" 1

# A CLI-escaped decoy whose genuine verdict is a MISSING sentinel (not a FAIL
# line): the decoy's PASS text must not be promoted to a verdict.
malformed_payload '  FAIL  missing: nightwatch.token
{"output":"LAUNCH-CHECK-ENV: PASS","exitCode":0}' 0 > "$PF"
check "repairable decoy PASS text, genuine output has NO sentinel" 1

# Round-1 PoC: fake exitCode + fake sentinel embedded mid-line, genuine exit 1.
malformed_payload 'fatal: config missing before real check ran
audit log tail: previous successful run was: {"exitCode":0,"output":"LAUNCH-CHECK-ENV: PASS"}' 1 > "$PF"
check "round-1 false-PASS PoC (embedded exitCode + sentinel)" 1

# Round-1 PoC in its ORIGINAL field order — exitCode FIRST (malformed, so the
# raw fallback is what runs), so the embedded fake `"exitCode":0` is the LAST
# textual occurrence in the blob. This is exactly what made round 1's
# `grep -oE '"exitCode":[0-9]+' | tail -1` + whole-blob sentinel grep report a
# false PASS on a genuine remote exit 1.
cat > "$PF" <<'PAYLOAD'
{"exitCode":1,"output":"fatal: config missing before real check ran
audit log tail: previous successful run was: {"exitCode":0,"output":"LAUNCH-CHECK-ENV: PASS"}
"}
PAYLOAD
check "round-1 false-PASS PoC, original exitCode-first byte shape" 1

# =========================================================================
# C. Sentinel semantics — FAIL wins, exact whole-line match only.
# =========================================================================

malformed_payload '  ok    present: app.key
LAUNCH-CHECK-ENV: FAIL
target=pilot  ok=11 warn=0 fail=0
LAUNCH-CHECK-ENV: PASS' 0 > "$PF"
check "genuine PASS sentinel but output also contains a FAIL sentinel line" 1

malformed_payload '  ok    present: app.key
LAUNCH-CHECK-ENV: PASS
  FAIL  missing: nightwatch.token
LAUNCH-CHECK-ENV: FAIL' 1 > "$PF"
check "both sentinels present, FAIL wins" 1

malformed_payload '  FAIL  missing: nightwatch.token
target=launch  ok=10 warn=0 fail=1
LAUNCH-CHECK-ENV: FAIL' 0 > "$PF"
check "genuine exit 0 but genuine FAIL sentinel" 1

# Whitespace-padded sentinel is NOT the sentinel: the command emits it via
# $this->line() with no padding, so padding means something else produced it.
# Fail closed rather than invent trimming rules.
malformed_payload '  ok    present: app.key
    LAUNCH-CHECK-ENV: PASS   ' 0 > "$PF"
check "PASS sentinel with leading/trailing whitespace (not an exact line)" 1

malformed_payload 'remote log noise mentions LAUNCH-CHECK-ENV: PASS in passing but this run genuinely failed
more lines' 1 > "$PF"
check "PASS sentinel as a substring of a longer line" 1

wellformed_payload 'some unrelated noise with no sentinel at all' 0 > "$PF"
check "well-formed JSON, exit 0, no sentinel at all" 1

# =========================================================================
# D. exitCode edge cases — anything that is not a genuine numeric 0 fails.
# =========================================================================

malformed_payload '  ok    present: app.key
LAUNCH-CHECK-ENV: PASS' -1 > "$PF"
check "negative exitCode with PASS sentinel" 1

malformed_payload '  ok    present: app.key
LAUNCH-CHECK-ENV: PASS' 99999999999999999999 > "$PF"
check "huge exitCode with PASS sentinel" 1

printf '{"output":"  ok    present: app.key\nLAUNCH-CHECK-ENV: PASS\n"}' > "$PF"
check "absent exitCode field with PASS sentinel" 1

wellformed_payload '  ok    present: app.key
LAUNCH-CHECK-ENV: PASS' '"0"' > "$PF"
check "string-typed exitCode with PASS sentinel" 1

wellformed_payload '  ok    present: app.key
LAUNCH-CHECK-ENV: PASS' 'null' > "$PF"
check "null exitCode with PASS sentinel" 1

printf '{"output":"  ok    present: app.key\nLAUNCH-CHECK-ENV: PASS\n","exitCod' > "$PF"
check "truncated payload mid-record with PASS sentinel" 1

malformed_payload '  ok    present: app.key
LAUNCH-CHECK-ENV: PASS' 1 > "$PF"
check "remote exit 1 with PASS sentinel (exit code is necessary, not optional)" 1

# =========================================================================
# E. Empty / unusable output.
# =========================================================================

printf '{"output":"","exitCode":0}' > "$PF"
check "empty output field" 1

printf '{"output":"   \\n\\n   ","exitCode":0}' > "$PF"
check "whitespace-only output field" 1

: > "$PF"
check "cloud CLI produced no output at all" 1

printf 'Error: could not reach environment development' > "$PF"
check "non-JSON plain-text cloud output" 1

printf '{"error":true,"message":"environment not found"}' > "$PF"
check "cloud CLI error object" 1

# =========================================================================
# F. Missing tooling / gated environments — must fail closed, never skip.
# =========================================================================

CASE_NUM=$((CASE_NUM + 1))
BINROOT="$WORK/bin$CASE_NUM"; make_bin "$BINROOT"   # deliberately no `cloud` stub
RUN_ENV_ARGS="" RUN_CONFIRM_PROD="" assert_exit "cloud CLI absent" 1

CASE_NUM=$((CASE_NUM + 1))
BINROOT="$WORK/bin$CASE_NUM"; make_bin "$BINROOT" --no-jq
printf '#!/bin/bash\necho "{\\"output\\":\\"LAUNCH-CHECK-ENV: PASS\\",\\"exitCode\\":0}"\nexit 0\n' > "$BINROOT/cloud"
chmod +x "$BINROOT/cloud"
RUN_ENV_ARGS="" RUN_CONFIRM_PROD="" assert_exit "jq absent" 1

CASE_NUM=$((CASE_NUM + 1))
BINROOT="$WORK/bin$CASE_NUM"; make_bin "$BINROOT"
printf '#!/bin/bash\necho "{\\"output\\":\\"LAUNCH-CHECK-ENV: PASS\\",\\"exitCode\\":0}"\nexit 0\n' > "$BINROOT/cloud"
chmod +x "$BINROOT/cloud"
RUN_ENV_ARGS="--env production" RUN_CONFIRM_PROD="" \
    assert_exit "production without LAUNCH_CHECK_CONFIRM_PROD=1" 1

CASE_NUM=$((CASE_NUM + 1))
BINROOT="$WORK/bin$CASE_NUM"; make_bin "$BINROOT"
printf '#!/bin/bash\nexit 7\n' > "$BINROOT/cloud"
chmod +x "$BINROOT/cloud"
RUN_ENV_ARGS="" RUN_CONFIRM_PROD="" assert_exit "cloud CLI invocation itself fails (non-zero CLI exit)" 1

echo
if [[ $FAILURES -gt 0 ]]; then
    echo "ENV-CHECK-PARSER-TEST: $FAILURES failure(s) of $CASE_NUM"
    exit 1
fi
echo "ENV-CHECK-PARSER-TEST: all $CASE_NUM cases passed"
