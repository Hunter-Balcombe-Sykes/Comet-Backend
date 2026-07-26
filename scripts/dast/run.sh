#!/usr/bin/env bash
# DAST security scanning entrypoint. Two lanes:
#   active — ZAP fuzzing an isolated, runner-owned local Supabase stack.
#   edge   — Nuclei + wcvs + weekly ZAP passive baseline against EDGE_TARGET, non-destructive.
# See README.md. Neither lane runs in ci.yml — see docs/superpowers/plans/2026-07-17-dast-security-implementation.md.
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DAST_DIR="$HERE"
# shellcheck source=lib/common.sh
source "$HERE/lib/common.sh"

usage() {
    cat <<'EOF'
Usage: run.sh --only active|edge [--target URL] [--fail-on SEVERITY] [--update-baseline]

  --only active|edge   Required. Which lane to run.
  --target URL         Override the lane's default target.
  --fail-on SEVERITY   Severity floor for a non-zero exit (default: DAST_FAIL_ON env, else "high").
  --update-baseline    Append this run's findings to the triaged baseline (human-run only).
EOF
}

ONLY="" TARGET="" FAIL_ON="${DAST_FAIL_ON:-high}" UPDATE_BASELINE=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        --only)            ONLY="$2"; shift 2 ;;
        --target)          TARGET="$2"; shift 2 ;;
        --fail-on)         FAIL_ON="$2"; shift 2 ;;
        --update-baseline) UPDATE_BASELINE=1; shift ;;
        -h|--help)         usage; exit 0 ;;
        *) die "unknown arg: $1 (see --help)" ;;
    esac
done
[[ "$ONLY" =~ ^(active|edge)$ ]] || { usage; die "--only active|edge required"; }

OUTDIR="$(dast_outdir)"
log "lane=$ONLY target=${TARGET:-<lane default>} fail-on=$FAIL_ON outdir=$OUTDIR"

case "$ONLY" in
    active)
        "$HERE/active/zap-active.sh"  "$OUTDIR" "$TARGET"
        ;;
    edge)
        "$HERE/edge/nuclei-edge.sh"   "$OUTDIR" "$TARGET"
        "$HERE/edge/wcvs.sh"          "$OUTDIR"
        "$HERE/edge/zap-baseline.sh"  "$OUTDIR" "$TARGET"   # weekly OWASP ZAP passive baseline (Phase 6b)
        ;;
esac

# --- Baseline diff decides the exit code; run.sh itself never inspects
# scanner output directly (that's diff-baseline.sh's one job — every
# scanner fails the build the same way regardless of its own native exit
# convention, matching the wcvs/zap-baseline scripts' own design note).
DIFF_ARGS=("$OUTDIR" "$FAIL_ON")
[[ "$UPDATE_BASELINE" -eq 1 ]] && DIFF_ARGS+=(--update-baseline)
set +e
"$HERE/lib/diff-baseline.sh" "${DIFF_ARGS[@]}"
DIFF_EXIT=$?
set -e

# --- REPORT.md — merged human view (Scope · per-lane finding counts ·
# NEW-vs-baselined table), matching launch-check's audits/<suite>/<date>/
# REPORT.md convention. audits/dast/.gitignore keeps only this file
# committable; everything else under $OUTDIR is a raw, git-ignored artifact.
{
    echo "# DAST run — $(date +%F) — lane: $ONLY"
    echo
    echo "**Target:** ${TARGET:-<lane default>}  "
    echo "**Fail-on:** $FAIL_ON  "
    echo "**Exit:** $DIFF_EXIT"
    echo
    echo "## New findings"
    echo
    if [[ -s "$OUTDIR/new-findings.txt" ]]; then
        echo '| Scanner | Key | Severity |'
        echo '|---|---|---|'
        while IFS=$'\t' read -r scanner key sev; do
            echo "| $scanner | $key | $sev |"
        done < "$OUTDIR/new-findings.txt"
    else
        echo "None — clean run against the current baseline."
    fi
} > "$OUTDIR/REPORT.md"
log "run.sh: wrote $OUTDIR/REPORT.md"

exit "$DIFF_EXIT"
