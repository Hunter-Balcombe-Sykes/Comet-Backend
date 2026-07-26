#!/usr/bin/env bash
# Edge lane: Nuclei curated tag-pinned scan + 5 custom assert templates
# (edge/templates/*.yaml — their one home; see README). Non-destructive
# (template matches are GET/HEAD only), rate-limited against EDGE_TARGET
# — which also serves the live host behind Cloudflare. An unthrottled
# sweep trips the WAF; challenge pages then read as "clean" (false
# negatives). Sanity-check DAST_EDGE_RATE_LIMIT before raising it.
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DAST_DIR="$HERE"
# shellcheck source=../lib/common.sh
source "$HERE/lib/common.sh"

OUTDIR="${1:?usage: nuclei-edge.sh OUTDIR [TARGET]}"
TARGET="${2:-${EDGE_TARGET:-}}"
[[ -n "$TARGET" ]] || die "no target: pass one or set EDGE_TARGET"

RATE_LIMIT="${DAST_EDGE_RATE_LIMIT:-20}"
require_docker_image projectdiscovery/nuclei

mkdir -p "$OUTDIR"

# Optional, opt-in fixture for the alias-301-canonical template (transient
# data — see that template's description). Unset ⇒ template no-ops.
ALIAS_HOST="${EDGE_ALIAS_HOST:-}"

log "nuclei: target=$TARGET rate-limit=$RATE_LIMIT alias_host=${ALIAS_HOST:-<none>}"

# Two `-t` (templates) flags — NOT `-t` + `-it` (include-templates) — union
# Projectdiscovery's full curated set with our custom dir in one pass, both
# filtered by the same -tags/-severity floor below. Verified empirically
# 2026-07-26 against nuclei v3.11.0: `-it` silently drops everything in
# /templates when combined with -t + -tags/-severity (0 of 5 custom
# templates loaded); repeating `-t` for both dirs loads all of them (30
# templates loaded vs 26 for the official set alone). Do not "simplify"
# this back to -it without re-verifying against the installed nuclei version.
docker run --rm \
    -v dast-nuclei-templates:/root/nuclei-templates \
    -v "$HERE/edge/templates:/templates:ro" \
    -v "$OUTDIR:/out" \
    projectdiscovery/nuclei \
    -u "$TARGET" \
    -t /root/nuclei-templates \
    -t /templates/ \
    -tags exposures,misconfiguration,http,ssl,cache \
    -severity low,medium,high,critical \
    -rate-limit "$RATE_LIMIT" \
    -var "alias_host=${ALIAS_HOST}" \
    -jsonl -o /out/nuclei.jsonl \
    -stats

log "nuclei: wrote $OUTDIR/nuclei.jsonl"
