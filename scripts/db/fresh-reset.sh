#!/usr/bin/env bash
#
# Local fresh-DB provisioning that survives the CONCURRENTLY-in-pipeline CLI limitation.
#
# WHY THIS EXISTS
#   `supabase db reset` and `supabase db push` send each migration file's statements
#   to Postgres as one libpq pipeline (implicit transaction). `CREATE/DROP INDEX
#   CONCURRENTLY` cannot run inside a pipeline (SQLSTATE 25001), so any migration file
#   with more than one CONCURRENTLY statement aborts a from-zero apply. Many of this
#   repo's index migrations bundle several CONCURRENTLY statements (CONVENTIONS.md §1),
#   so `supabase db reset` cannot provision a database from scratch here.
#
#   psql's simple-query protocol runs each statement as its own top-level command — no
#   pipeline — so this loop applies every migration (CONCURRENTLY and all) cleanly.
#
# WHAT IT DOES
#   1. Provisions a clean Supabase base (auth/storage schemas, roles, extensions) by
#      running `supabase db reset --no-seed` with the migrations temporarily moved aside,
#      so zero app migrations run through the broken pipeline applier.
#   2. Applies every migration in supabase/migrations/*.sql, in filename order, via a
#      `psql` loop, and records each in supabase_migrations.schema_migrations so the
#      Supabase migration tooling sees them as applied.
#
# SAFETY
#   LOCAL ONLY. It talks to the local Supabase docker container (supabase_db_<project_id>)
#   over docker exec; it has no way to reach a remote host. For the fresh-PROD cutover
#   procedure, see CLAUDE.md → "Fresh prod DB".
#
# USAGE
#   supabase start          # if the local stack isn't running
#   scripts/db/fresh-reset.sh
#
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT"
MIG_DIR="supabase/migrations"

[ -d "$MIG_DIR" ] || { echo "error: $MIG_DIR not found (run from the backend repo)."; exit 1; }

# Derive the local container name from the project id in config.toml.
PROJECT_ID="$(grep -E '^project_id' supabase/config.toml | head -1 | sed -E 's/^project_id[[:space:]]*=[[:space:]]*"?([^"]+)"?.*/\1/')"
CONTAINER="supabase_db_${PROJECT_ID}"

# Safety + preconditions: the target must be the running LOCAL supabase db container.
if ! docker ps --format '{{.Names}}' | grep -qx "$CONTAINER"; then
    echo "error: local supabase db container '$CONTAINER' is not running."
    echo "       start the local stack first:  supabase start"
    exit 1
fi

psql_local() { docker exec -i "$CONTAINER" psql -U postgres -d postgres -v ON_ERROR_STOP=1 "$@"; }

echo "==> Target: local container $CONTAINER"

# Surface duplicate 14-digit version prefixes loudly. schema_migrations keys on the
# version, so two files sharing a prefix can record only one history row (the INSERT
# below uses ON CONFLICT (version) DO NOTHING) — both files' SQL still runs, but the
# colliding file's bookkeeping row is missing. The real fix is renaming one file to a
# unique timestamp; do not mask this silently.
dupes="$(printf '%s\n' "$MIG_DIR"/*.sql | xargs -n1 basename | grep -oE '^[0-9]{14}' | sort | uniq -d || true)"
if [ -n "$dupes" ]; then
    echo "!!  WARNING: duplicate migration version prefix(es) detected. Only one history row per"
    echo "!!           version can be recorded, so a colliding file's schema_migrations row will be"
    echo "!!           missing (its SQL still applies). Rename one file to a unique timestamp to fix."
    printf '%s\n' "$dupes" | sed 's/^/!!             /'
fi

# NOTE: if the psql loop below halts on a migration SQL error (e.g. "column ... does not
# exist"), that migration has a from-zero bug — typically it references schema the
# consolidated baseline no longer has. Fix that migration; it is not a fault of this script.

# ── 1. Clean base, zero app migrations ────────────────────────────────────────
# Move the real migrations aside so `db reset` provisions only the Supabase base.
# A trap restores them on any exit so an interrupted run never strands files.
TMP="$(mktemp -d)"
restore() {
    if [ -d "$TMP" ]; then
        # move any stashed files back (‑n: never clobber a restored original)
        for m in "$TMP"/*.sql; do
            [ -e "$m" ] || continue
            mv -n "$m" "$MIG_DIR"/
        done
        rmdir "$TMP" 2>/dev/null || true
    fi
}
trap restore EXIT INT TERM

shopt -s nullglob
mig_files=("$MIG_DIR"/*.sql)
shopt -u nullglob
[ "${#mig_files[@]}" -gt 0 ] || { echo "error: no migration files in $MIG_DIR."; exit 1; }

echo "==> Provisioning clean base (supabase db reset --no-seed, no app migrations)…"
mv "${mig_files[@]}" "$TMP"/
supabase db reset --no-seed >/dev/null
# Restore migrations before applying them.
for m in "$TMP"/*.sql; do mv "$m" "$MIG_DIR"/; done
trap - EXIT INT TERM
rmdir "$TMP" 2>/dev/null || true

# ── 2. Apply every migration via psql (simple protocol → CONCURRENTLY ok) ──────
# A zero-migration `db reset` does not create the migration-history table (Supabase
# creates it lazily on first apply), so create it here with the stock DDL before we
# record anything into it.
psql_local -c "CREATE SCHEMA IF NOT EXISTS supabase_migrations;
CREATE TABLE IF NOT EXISTS supabase_migrations.schema_migrations (
    version text NOT NULL PRIMARY KEY,
    statements text[],
    name text
);" >/dev/null

echo "==> Applying migrations via psql loop…"
count=0
while IFS= read -r f; do
    base="$(basename "$f")"
    version="$(printf '%s' "$base" | grep -oE '^[0-9]{14}' || true)"
    [ -n "$version" ] || { echo "    skip (no timestamp): $base"; continue; }
    name="$(printf '%s' "$base" | sed -E 's/^[0-9]{14}_(.*)\.sql$/\1/')"
    # Apply the file, then record it as applied — both in one psql invocation.
    { cat "$f"; printf '\nINSERT INTO supabase_migrations.schema_migrations(version,name) VALUES (%s,%s) ON CONFLICT (version) DO NOTHING;\n' "'$version'" "'$name'"; } \
        | psql_local >/dev/null
    count=$((count + 1))
done < <(printf '%s\n' "$MIG_DIR"/*.sql | sort)

echo "==> Done. Applied $count migrations to local database ($CONTAINER)."
echo "    Recorded in supabase_migrations.schema_migrations."
