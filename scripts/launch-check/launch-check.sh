#!/usr/bin/env bash
# Launch-check runner — "is the RUNNING system right" (counterpart to
# scripts/audit/ which answers "is the code right").
# Usage: launch-check.sh [--only schema,smoke,supabase,supply,security,drills,env] [--base-url URL] [--rate-limit]
#
# NOTE: "env" (group G, deployed-env config check) is opt-in only — it needs the
# `cloud` CLI and a deployed Laravel Cloud env, neither of which a plain local
# run can assume. It is deliberately NOT in the default ONLY list; run it via
# `--only env` (or include it explicitly alongside other groups).
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$DIR/../.." && pwd)"
KNOWN_GROUPS="schema smoke supabase supply security drills env"
ONLY="schema,smoke,supabase,supply,security,drills"
BASE_URL="https://dev-api.partna.au"
SMOKE_ARGS=()
while [[ $# -gt 0 ]]; do
    case "$1" in
        --only) ONLY="$2"; shift 2 ;;
        --base-url) SMOKE_ARGS+=(--base-url "$2"); BASE_URL="$2"; shift 2 ;;
        --rate-limit) SMOKE_ARGS+=(--rate-limit); shift ;;
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

OUT_DIR="$ROOT/audits/launch-check/$(date +%F)"
mkdir -p "$OUT_DIR"
REPORT="$OUT_DIR/REPORT.md"
OVERALL=0

run_group() { # $1 name, $2 command string
    echo "════════ $1 ════════"
    local output status
    output=$(eval "$2" 2>&1); status=$?
    echo "$output"
    [[ $status -ne 0 ]] && OVERALL=1
    {
        echo "## $1 — $([[ $status -eq 0 ]] && echo PASS || echo '**FAIL**')"
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
    "cd '$ROOT' && php artisan test --filter=SchemaDriftGuardTest --compact"
[[ ",$ONLY," == *",smoke,"* ]] && run_group "B · Runtime smoke probe" \
    "'$DIR/smoke.sh' ${SMOKE_ARGS[*]:-}"
[[ ",$ONLY," == *",supabase,"* ]] && run_group "C · Supabase config" \
    "'$DIR/supabase-check.sh'"
[[ ",$ONLY," == *",supply,"* ]] && run_group "D · Supply chain" \
    "cd '$ROOT' && composer audit --no-interaction && cd cloudflare-worker && npm audit --audit-level=high"
[[ ",$ONLY," == *",security,"* ]] && run_group "E · Security audit (Vigil)" \
    "cd '$ROOT' && APP_DEBUG=false php artisan vigil:audit --fail-on=critical"
[[ ",$ONLY," == *",drills,"* ]] && run_group "F · Drill-log freshness" \
    "'$DIR/drill-freshness.sh'"
[[ ",$ONLY," == *",env,"* ]] && run_group "G · Deployed env config" \
    "'$DIR/env-check.sh'"

{
    echo "## H · Manual residue (no script can verify these)"
    echo
    cat "$DIR/MANUAL-CHECKLIST.md"
} >> "$REPORT"

echo
echo "Report: $REPORT"
[[ $OVERALL -eq 0 ]] && echo "LAUNCH-CHECK: all automated groups passed" || echo "LAUNCH-CHECK: FAILURES — see report"
exit $OVERALL
