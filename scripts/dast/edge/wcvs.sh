#!/usr/bin/env bash
# Edge lane: wcvs cache-deception/poisoning scan against the sitepage host
# (the Cloudflare Worker + Cache API front end — the one surface where cache
# deception is reachable; the API host is covered by nuclei-edge.sh's
# cache-control-correct template instead). Non-destructive by construction:
# crafted GETs probing whether a path-confusion/extension trick gets a
# private response cached for the next visitor. Same rate-limit/WAF caution
# as nuclei-edge.sh — EDGE_SITEPAGE_TARGET sits behind Cloudflare too.
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DAST_DIR="$HERE"
# shellcheck source=../lib/common.sh
source "$HERE/lib/common.sh"

OUTDIR="$(dast_abspath "${1:?usage: wcvs.sh OUTDIR}")"
TARGET="${EDGE_SITEPAGE_TARGET:-}"
[[ -n "$TARGET" ]] || die "no target: set EDGE_SITEPAGE_TARGET (a real <handle>.partna.au)"

RATE_LIMIT="${DAST_EDGE_RATE_LIMIT:-20}"
require_wcvs_image

WCVS_OUT="$OUTDIR/wcvs"
mkdir -p "$WCVS_OUT"

log "wcvs: target=$TARGET rate-limit=$RATE_LIMIT"

# wcvs writes its own timestamp+random-suffixed filenames
# (WCVS_<date>_<rand>_Report.json / _Log.txt) — there is no fixed-name flag,
# so we normalize the report to $OUTDIR/wcvs-report.json below for Phase 7's
# diff-baseline and the README's documented artifact name.
#
# -skiptest dos: wcvs's "dos" category implements "Responsible Denial of
# Service with Web Cache Poisoning" (confirmed 2026-07-26 via upstream's
# published references) — a DoS-testing technique, which contradicts this
# lane's non-destructive-only contract (plan gotchas block: edge lane is
# "GET/HEAD + passive only"). Every other default category (deception,
# cookies, css, forwarding, smuggling, headers, parameters, fatget,
# cloaking, splitting, pollution, encoding) stays — they detect cache
# poisoning/deception without attempting to degrade the target.
docker run --rm -v "$WCVS_OUT:/out" hackmanit/web-cache-vulnerability-scanner:2.0.0 /wcvs \
    -u "$TARGET" \
    -gp /out/ -gr -gl \
    -nc -ns \
    -skiptest dos \
    -rr "$RATE_LIMIT"

REPORT="$(find "$WCVS_OUT" -maxdepth 1 -iname 'WCVS_*_Report.json' -print -quit)"
[[ -n "$REPORT" ]] || die "wcvs produced no report file in $WCVS_OUT"
cp "$REPORT" "$OUTDIR/wcvs-report.json"

log "wcvs: wrote $OUTDIR/wcvs-report.json"
