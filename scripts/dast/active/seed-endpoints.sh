#!/usr/bin/env bash
# Active lane: route seeding. Pulls `php artisan route:list --json` and
# transforms it (active/seed-endpoints.php — JSON manipulation is far more
# natural in PHP than bash+jq for this) into a ZAP-importable seed: a
# minimal OpenAPI 3 doc (default) + a flat URL list (documented fallback).
# Deviation from the plan's file list (active/seed-endpoints.sh only): the
# transformation logic lives in a companion seed-endpoints.php rather than
# inline jq, mirroring Phase 3's mint-jwt.php.
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DAST_DIR="$HERE"
REPO_ROOT="$(cd "$HERE/../.." && pwd)"
# shellcheck source=../lib/common.sh
source "$HERE/lib/common.sh"

OUTDIR="$(dast_abspath "${1:?usage: seed-endpoints.sh OUTDIR [BASE_URL]}")"
BASE_URL="${2:-${ZAP_TARGET_LOCAL:-http://127.0.0.1:8100}}"

cd "$REPO_ROOT"
php artisan route:list --json > "$OUTDIR/routes-raw.json"
php "$HERE/active/seed-endpoints.php" "$OUTDIR/routes-raw.json" "$BASE_URL" "$OUTDIR"

log "seed-endpoints: wrote $OUTDIR/seed-openapi.json and $OUTDIR/seed-urls.txt"
