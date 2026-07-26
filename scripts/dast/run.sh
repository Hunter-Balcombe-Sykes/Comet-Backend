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
# Phase 7 wires diff-baseline + REPORT.md merge + exit code here.
