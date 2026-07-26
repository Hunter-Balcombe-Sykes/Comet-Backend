#!/usr/bin/env bash
# Edge lane: weekly OWASP ZAP *passive* baseline scan (zap-baseline.py —
# spider + passive rules only, no active attacks) against EDGE_TARGET.
# Reuses the zaproxy/zap-stable image already pulled for the active lane —
# no new tool. A "ZAP baseline scan" (this) is a scan MODE, distinct from
# the baseline/ triaged-findings folder — see README. Safe against a live
# host behind Cloudflare; same WAF-throttling caution as the other edge
# tools applies (ZAP's spider isn't rate-limited by DAST_EDGE_RATE_LIMIT
# the way Nuclei/wcvs are — -m caps spider time instead).
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DAST_DIR="$HERE"
# shellcheck source=../lib/common.sh
source "$HERE/lib/common.sh"

OUTDIR="$(dast_abspath "${1:?usage: zap-baseline.sh OUTDIR [TARGET]}")"
TARGET="${2:-${EDGE_TARGET:-}}"
[[ -n "$TARGET" ]] || die "no target: pass one or set EDGE_TARGET"

require_docker_image zaproxy/zap-stable

ZAP_OUT="$OUTDIR/zap-passive"
mkdir -p "$ZAP_OUT"
chmod 777 "$ZAP_OUT"   # container runs as its own non-root user; needs write access to the bind mount

log "zap-baseline: target=$TARGET"

# zap-baseline.py exits 1 on WARN / 2 on FAIL (verified 2026-07-26 — not the
# uniform 0/nonzero contract the rest of this tool uses) — capture but don't
# let it decide the build. The unified diff-baseline.sh (Phase 7) owns
# gating from the JSON output, so every scanner in this tool fails the same
# way regardless of its own native exit-code convention.
set +e
docker run --rm -v "$ZAP_OUT:/zap/wrk/:rw" zaproxy/zap-stable \
    zap-baseline.py \
    -t "$TARGET" \
    -J zap-baseline.json -r zap-baseline.html \
    -m 5 -T 60
ZAP_EXIT=$?
set -e
log "zap-baseline: zap-baseline.py exited $ZAP_EXIT (informational only — diff-baseline.sh decides pass/fail)"

[[ -f "$ZAP_OUT/zap-baseline.json" ]] || die "zap-baseline.py produced no JSON report in $ZAP_OUT"
cp "$ZAP_OUT/zap-baseline.json" "$OUTDIR/zap-baseline-passive.json"

log "zap-baseline: wrote $OUTDIR/zap-baseline-passive.json"
