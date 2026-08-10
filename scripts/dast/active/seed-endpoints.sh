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

# --env=dast WHEN THE SCRATCH ENV EXISTS, for two reasons.
#
# CORRECTNESS: the seed must describe the app ZAP is about to scan. That app is
# served with --env=dast, so deriving its route list from the repo's own .env can
# describe a DIFFERENT application whenever a config value gates a route.
#
# AND IT IS REQUIRED ON A RUNNER: a CI checkout has no .env at all — bring-up.sh
# writes .env.dast, nothing writes .env — so a bare `artisan` boots as production
# and AppServiceProvider's AUTH-1 guard aborts on the unset SUPABASE_JWT_ISSUER
# (the same reason ci.yml pins APP_ENV for composer install). Locally there is
# always a .env, which is why this only appeared on GitHub run 31378078269.
#
# The conditional keeps the script usable standalone before a bring-up. Written
# as an if/else rather than an args array because macOS bash 3.2 errors on
# "${arr[@]}" when arr is empty under `set -u`.
#
# STDERR IS CAPTURED SEPARATELY AND SURFACED. stdout is redirected into
# routes-raw.json, so a boot failure previously wrote its message INTO THE JSON
# FILE and the lane died with no output at all — 0.2s after bring-up reported
# healthy, with nothing but "tearing down (exit was 1)" to go on. A silent death
# is the one failure mode this tool exists to eliminate; make it speak.
route_list() {
    if [[ -f "$REPO_ROOT/.env.dast" ]]; then
        php artisan route:list --env=dast --json
    else
        php artisan route:list --json
    fi
}

if ! route_list > "$OUTDIR/routes-raw.json" 2> "$OUTDIR/route-list-stderr.log"; then
    die "php artisan route:list failed — the seed cannot be built.
stderr:
$(tail -30 "$OUTDIR/route-list-stderr.log" 2>/dev/null)
stdout (captured into routes-raw.json):
$(head -c 2000 "$OUTDIR/routes-raw.json" 2>/dev/null)"
fi

php "$HERE/active/seed-endpoints.php" "$OUTDIR/routes-raw.json" "$BASE_URL" "$OUTDIR"

log "seed-endpoints: wrote $OUTDIR/seed-openapi.json and $OUTDIR/seed-urls.txt"
