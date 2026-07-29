#!/usr/bin/env bash
#
# Apply the whole supabase/migrations/ set, in filename order, to a DISPOSABLE
# Postgres — the CI service container for the applied-schema lane
# (phpunit.schema.xml), or a local throwaway container.
#
# WHY psql -f PER FILE, NOT ONE TRANSACTION
#   psql's simple-query protocol runs each statement as its own top-level
#   command. The Supabase CLI instead sends a file's statements as one libpq
#   PIPELINE, and CREATE/DROP INDEX CONCURRENTLY cannot run in a pipeline
#   (SQLSTATE 25001) — the same limitation scripts/db/fresh-reset.sh exists to
#   work around. Deliberately NOT --single-transaction, for the same reason and
#   because that is how the documented fresh-prod cutover applies them
#   (CLAUDE.md → "Fresh prod cutover"), so this exercises the real procedure.
#
# WHAT IT PROVES BEYOND THE TESTS
#   A from-zero apply of every migration is itself the thing CLAUDE.md warns can
#   silently break. Running this in CI means a migration that cannot be applied
#   to an empty database fails a build the day it lands, rather than on the next
#   fresh-environment cutover.
#
# SAFETY
#   Refuses any database not named partna_test unless PG_LANE_DISPOSABLE=1.
#   It runs DDL for the entire production schema; pointing it at something real
#   would be unrecoverable.
#
# USAGE
#   PGHOST=127.0.0.1 PGPORT=5432 PGUSER=postgres PGPASSWORD=postgres \
#   PGDATABASE=partna_test scripts/db/apply-migrations.sh
#
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT"

MIG_DIR="supabase/migrations"
SHIM="scripts/db/ci-supabase-shim.sql"

[ -d "$MIG_DIR" ] || { echo "error: $MIG_DIR not found (run from the backend repo)."; exit 1; }
[ -f "$SHIM" ] || { echo "error: $SHIM not found."; exit 1; }

DB="${PGDATABASE:-}"
if [ "$DB" != "partna_test" ] && [ "${PG_LANE_DISPOSABLE:-}" != "1" ]; then
    echo "error: refusing to apply the full schema to '${DB:-<unset>}'."
    echo "       This runs DDL for every table in the product. Target a throwaway"
    echo "       database named partna_test, or set PG_LANE_DISPOSABLE=1."
    exit 1
fi

run() { psql -v ON_ERROR_STOP=1 --quiet --no-psqlrc -f "$1"; }

echo "==> Supabase shim (roles, auth.users, auth.uid) on ${DB}"
run "$SHIM"

echo "==> Applying $(ls "$MIG_DIR"/*.sql | wc -l | tr -d ' ') migrations"
for f in $(ls "$MIG_DIR"/*.sql | sort); do
    printf '    %s\n' "$(basename "$f")"
    run "$f"
done

echo "==> Applied cleanly."
