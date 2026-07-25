#!/usr/bin/env bash
# Launch-check runner — "is the RUNNING system right" (counterpart to
# scripts/audit/ which answers "is the code right").
# Usage: launch-check.sh [--only schema,smoke,supabase,supply,security,drills,env,runtime] [--base-url URL] [--rate-limit] [--runtime-target pilot|launch]
#
# NOTE: "env" (group G, deployed-env config check) and "runtime" (group H,
# deployed runtime health) are opt-in only — both need the `cloud` CLI and a
# deployed Laravel Cloud env, neither of which a plain local run can assume.
# They are deliberately NOT in the default ONLY list; run them via
# `--only env,runtime` (or include either explicitly alongside other groups).
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$DIR/../.." && pwd)"
KNOWN_GROUPS="schema smoke supabase supply security drills env runtime"
ONLY="schema,smoke,supabase,supply,security,drills"
BASE_URL="https://dev-api.partna.au"
RUNTIME_TARGET=""   # empty ⇒ runtime-health.sh's own default (pilot); pass --runtime-target to override
SMOKE_ARGS=()
while [[ $# -gt 0 ]]; do
    case "$1" in
        --only) ONLY="$2"; shift 2 ;;
        --base-url) SMOKE_ARGS+=(--base-url "$2"); BASE_URL="$2"; shift 2 ;;
        --rate-limit) SMOKE_ARGS+=(--rate-limit); shift ;;
        --runtime-target) RUNTIME_TARGET="$2"; shift 2 ;;
        *) echo "unknown arg: $1" >&2; exit 2 ;;
    esac
done

# Validate --only BEFORE dispatching anything — an unrecognised or empty group
# list must be a hard, loud error, never a silent zero-groups "pass".
if [[ -z "$ONLY" ]]; then
    echo "error: --only must not be empty. Valid groups: $KNOWN_GROUPS" >&2
    exit 2
fi
IFS=',' read -ra ONLY_GROUPS <<< "$ONLY"
for g in "${ONLY_GROUPS[@]}"; do
    known=false
    for k in $KNOWN_GROUPS; do
        [[ "$g" == "$k" ]] && { known=true; break; }
    done
    if ! $known; then
        echo "error: unknown --only group '$g'. Valid groups: $KNOWN_GROUPS" >&2
        exit 2
    fi
done

# Same rule as --only: an invalid --runtime-target must hard-error immediately,
# never silently fall through to runtime-health.sh's own (correct, but later
# and non-fatal-to-the-whole-run) validation.
if [[ -n "$RUNTIME_TARGET" && "$RUNTIME_TARGET" != "pilot" && "$RUNTIME_TARGET" != "launch" ]]; then
    echo "error: unknown --runtime-target '$RUNTIME_TARGET' (must be pilot or launch)" >&2
    exit 2
fi

OUT_DIR="$ROOT/audits/launch-check/$(date +%F)"
mkdir -p "$OUT_DIR"
REPORT="$OUT_DIR/REPORT.md"
OVERALL=0

# run_group NAME CODE [ARG...] — CODE is a FIXED, hand-written bash -c script
# (never built from a variable) that reads its dynamic inputs ONLY as
# positional parameters ($1, $2, ...) supplied via ARG. This is deliberately
# NOT `eval "$built_string"`: eval re-parses whatever bytes end up in the
# string, so anything reaching it — a --runtime-target value, a handle, a
# path — becomes shell syntax, not data, the moment it contains a quote,
# `;`, `$(...)`, backtick or newline (see the launch-check:runtime target
# injection this replaced). `bash -c CODE bash "$@"` never re-parses ARG:
# each one lands in $1.. as an inert string no matter what bytes it holds.
run_group() { # $1 name, $2 bash -c code (fixed, references $1..), $3.. args
    local name="$1" code="$2"
    shift 2
    echo "════════ $name ════════"
    local output status
    output=$(bash -c "$code" bash "$@" 2>&1); status=$?
    echo "$output"
    [[ $status -ne 0 ]] && OVERALL=1
    {
        echo "## $name — $([[ $status -eq 0 ]] && echo PASS || echo '**FAIL**')"
        echo
        echo '```'
        echo "$output"
        echo '```'
        echo
    } >> "$REPORT"
}

{
    echo "# Launch-Check Report — $(date +%F)"
    echo
    echo "Groups: $ONLY · Target: $BASE_URL"
    echo
} > "$REPORT"

[[ ",$ONLY," == *",schema,"* ]] && run_group "A · Schema-drift gate" \
    'cd "$1" && php artisan test --filter=SchemaDriftGuardTest --compact' "$ROOT"
[[ ",$ONLY," == *",smoke,"* ]] && run_group "B · Runtime smoke probe" \
    '"$1" "${@:2}"' "$DIR/smoke.sh" "${SMOKE_ARGS[@]+"${SMOKE_ARGS[@]}"}"
[[ ",$ONLY," == *",supabase,"* ]] && run_group "C · Supabase config" \
    '"$1"' "$DIR/supabase-check.sh"
[[ ",$ONLY," == *",supply,"* ]] && run_group "D · Supply chain" \
    'cd "$1" && composer audit --no-interaction && cd cloudflare-worker && npm audit --audit-level=high' "$ROOT"
[[ ",$ONLY," == *",security,"* ]] && run_group "E · Security audit (Vigil)" \
    'cd "$1" && APP_DEBUG=false php artisan vigil:audit --fail-on=critical' "$ROOT"
[[ ",$ONLY," == *",drills,"* ]] && run_group "F · Drill-log freshness" \
    '"$1"' "$DIR/drill-freshness.sh"
[[ ",$ONLY," == *",env,"* ]] && run_group "G · Deployed env config" \
    '"$1"' "$DIR/env-check.sh"
# runtime-health.sh already reads $LAUNCH_CHECK_HANDLE itself (inherited from
# this script's own environment — no --handle is passed here at all). The
# target, if given, travels as a genuine argv element ($2 inside the bash -c
# code below), never spliced into a string that gets re-parsed — see run_group.
RUNTIME_ARGS=()
[[ -n "$RUNTIME_TARGET" ]] && RUNTIME_ARGS=(--target "$RUNTIME_TARGET")
[[ ",$ONLY," == *",runtime,"* ]] && run_group "H · Deployed runtime health" \
    '"$1" "${@:2}"' "$DIR/runtime-health.sh" "${RUNTIME_ARGS[@]+"${RUNTIME_ARGS[@]}"}"

{
    echo "## I · Manual residue (no script can verify these)"
    echo
    cat "$DIR/MANUAL-CHECKLIST.md"
} >> "$REPORT"

echo
echo "Report: $REPORT"
[[ $OVERALL -eq 0 ]] && echo "LAUNCH-CHECK: all automated groups passed" || echo "LAUNCH-CHECK: FAILURES — see report"
exit $OVERALL
