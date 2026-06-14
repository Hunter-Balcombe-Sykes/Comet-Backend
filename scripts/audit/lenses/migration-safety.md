# Migration safety: lock-on-deploy risk, backfill ordering, online DDL hygiene, reversibility

Hunt **migrations that lock hot tables during deploy**, **backfills with broken ordering**, **DDL patterns that fail on prod-sized data**, and **destructive operations without a rehearsal path**. Migration *correctness* (does the schema express the right constraints) lives in `data-integrity.md` — this lens is specifically about **operational safety of the migration itself**: can we run it on the production DB without taking the app down, losing data, or deploying a half-applied schema?

Partna runs on **PostgreSQL** (Supabase-hosted, single primary, no read replicas). Migrations are raw SQL files in `supabase/migrations/` and deploy via `supabase db push` (see CLAUDE.md "push semantics"). There is no Laravel migration table — Supabase's `supabase_migrations.schema_migrations` is the source of truth; a half-applied file leaves prod in an inconsistent state.

**Repo reality (2026-06-11):** The 147 historical migrations have been archived in `supabase/migrations-archive/`. The codebase now has a single consolidated baseline (`20260526000000_baseline_standalone_user.sql`) plus ~70 post-baseline migrations. Schemas: `public`, `core`, `site`, `notifications`, `analytics`, `audit`, `moderation`. NO `brand`, `commerce`, `billing`, or `retail` schemas.

**Fresh-prod caveat:** the v2 baseline creates `app_backend` as `NOLOGIN` (fail-closed by design). After pushing migrations to a new Supabase project, `ALTER ROLE app_backend WITH LOGIN PASSWORD '...'` must be run in the SQL editor before the app can connect — `supabase db push` does not set LOGIN or the password. Document this in migration comments where relevant.

**Prod-is-behind caveat:** as of 2026-06-11, the production DB (`edplucmvkcnokyygxqsb`) is still on the pre-standalone schema (latest applied migration `20260512145025`). ALL ~70 post-baseline migrations in the repo are unapplied on prod. The baseline push to prod is a **gated re-baseline event**, not a single incremental migration — treat it as such. Any finding about prod deploy risk is elevated one tier until that re-baseline lands.

This lens is a **sibling** to `data-integrity.md` (schema-side correctness) and `schema-rls.md` (RLS / `search_path`). If a finding could go in either, emit it under whichever is more specific — the adjudicator dedupes.

## Use the lens prefix `MIG` for findings

Number them `MIG-1`, `MIG-2`, … sequentially. **P0 for confirmed prod-deploy lockup risk on a hot table OR data loss. P1 for backfill ordering errors, missing CONCURRENTLY on a large table, irreversible destructive ops without a rehearsal note. P2 for missing NOT VALID + VALIDATE split, non-idempotent migrations. P3 for hygiene (missing `IF NOT EXISTS`, missing comment, ordering of unrelated statements).**

## Findings categories

### (1) Lock-on-deploy risk (hot-table DDL)

Hot tables — writes flowing continuously during business hours: `site.sites`, `site.blocks`, `site.site_media`, `site.design_kits`, `core.users`, `analytics.link_clicks`, `analytics.site_visits`, `analytics.site_sessions`, `notifications.*` tables. Locks on these tables stall production.

- `CREATE INDEX` without `CONCURRENTLY` on a hot table — `ACCESS EXCLUSIVE` blocks reads and writes for the duration. The established pattern splits index creation into a companion migration file (exemplar: `20260610000001_analytics_v2_click_indexes.sql` runs `CREATE INDEX CONCURRENTLY` outside a transaction per `CONVENTIONS.md §1`).
- `ALTER TABLE ... ADD COLUMN ... NOT NULL DEFAULT <volatile>` — Postgres 11+ avoids a table rewrite only for **immutable** defaults; volatile defaults (`now()`, `gen_random_uuid()`) still rewrite the table.
- `ALTER TABLE ... ALTER COLUMN TYPE` that requires a rewrite (e.g. `TEXT` → `UUID`, numeric widening) — full rewrite under `ACCESS EXCLUSIVE`.
- `ALTER TABLE ... ADD CONSTRAINT ... CHECK (...)` without `NOT VALID` — validates every existing row under a strong lock. The canonical two-step pattern is documented in `docs/migration-guidelines.md` and illustrated by `20260527070000_skeleton_system_cleanup.sql` (adds `skeleton_id CHECK`) + `20260603000002_validate_skeleton_id_check.sql` (validates separately).
- `ALTER TABLE ... ADD FOREIGN KEY (...)` without `NOT VALID` — scans the entire child table under `SHARE ROW EXCLUSIVE`.
- `ALTER TABLE ... SET NOT NULL` on an existing nullable column — full table scan under `ACCESS EXCLUSIVE`. Safe pattern: `ADD CHECK (col IS NOT NULL) NOT VALID` → `VALIDATE CONSTRAINT` → `SET NOT NULL`.
- `DROP INDEX` without `CONCURRENTLY` — brief but real `ACCESS EXCLUSIVE` lock.
- `VACUUM FULL` or `CLUSTER` in a migration — `ACCESS EXCLUSIVE` for the duration.
- `REINDEX TABLE` without `CONCURRENTLY` (Postgres 12+).

### (2) Missing `NOT VALID` / `VALIDATE CONSTRAINT` split

The two-step pattern for FKs and CHECKs on existing data:

1. `ADD CONSTRAINT ... NOT VALID` — fast metadata-only operation; new writes are checked, old rows are not.
2. `VALIDATE CONSTRAINT ...` — scans existing rows under a weaker `SHARE UPDATE EXCLUSIVE` lock that allows reads + writes.

- Migration adds a constraint without `NOT VALID` on a table that has any data — flag.
- Migration adds `NOT VALID` but the corresponding `VALIDATE CONSTRAINT` follow-up never lands — the constraint exists on paper but old rows are not enforced.
- `VALIDATE CONSTRAINT` run in the same migration as `NOT VALID` — wastes the two-step optimization; flag as hygiene if the table is large.

See `docs/migration-guidelines.md` §CHECK constraints for the canonical wording and code example.

### (3) Backfill ordering & idempotency

- Backfill SQL that depends on a column added in the same migration without `COMMIT` between — if the migration is split across files, ordering matters; a partial apply leaves prod with the column but no data.
- `UPDATE` backfills without a `WHERE` clause limiting to "rows needing backfill" — re-running overwrites already-corrected rows.
- Backfills that compute the new value from a column being dropped or renamed in the same migration — irreversible if the migration fails mid-flight.
- Backfills on tables >100K rows without batching (`WHERE id IN (...) LIMIT N`) — long-running `UPDATE` holds row locks and bloats WAL. The analytics event tables (`analytics.link_clicks`, `analytics.site_visits`, `analytics.site_sessions`) are the write-heavy candidates as the platform scales.
- Backfills that read from a table that may not yet exist on a fresh prod DB — order-of-migration risk.

**Canonical exemplar for a correct data backfill:** `20260608000000_backfill_subdomain_alias_lifecycle.sql` — a pure SQL `UPDATE` with a `WHERE expires_at IS NULL` idempotency guard, accompanied by a comment explaining the bug it fixes and why the WHERE guard makes it safe to re-run. New backfills must follow this pattern.

Inline `UPDATE` scrubs in migration transactions should be extracted to a post-deploy artisan command or chunked job per `docs/migration-guidelines.md` §Full-table-scan data scrubs.

### (4) Destructive operations without rehearsal

- `DROP TABLE` / `DROP COLUMN` / `DROP CONSTRAINT` in a migration not first shipped to the `development` environment — the dev/prod env split in CLAUDE.md exists for exactly this.
- `DROP COLUMN` immediately after `RENAME COLUMN` to the new name in the same file — if rollback is needed, the old data is gone.
- `TRUNCATE` on any table — irreversible; flag and require a justifying comment.
- Column rename done as `DROP COLUMN` + `ADD COLUMN` instead of `RENAME COLUMN` — data loss.
- `ALTER COLUMN ... DROP DEFAULT` followed by `SET DEFAULT` — confirm the gap doesn't allow nulls in.
- Trigger / function `DROP` + `CREATE` without `CREATE OR REPLACE` — a window where the trigger doesn't exist; writes bypass it. Especially critical for append-only enforcement triggers (`core.reject_staff_audit_log_mutation`, `core.trg_handle_change_log_append_only`) and the `trg_create_empty_design_kit` auto-insert trigger.

### (5) Reversibility

- Migration has no documented rollback path — a comment explaining "to revert: …" is the established convention for destructive migrations; flag its absence on any `DROP`, `TRUNCATE`, or destructive `ALTER`.
- Reversible-on-paper but irreversible-in-practice: `DROP COLUMN` on a column populated by application writes, `DROP CONSTRAINT UNIQUE` after duplicate rows could have been inserted.
- `RENAME COLUMN` without updating any view that references it — the view silently breaks until the next deploy.

### (6) Multi-statement transaction hazards

- `CREATE INDEX CONCURRENTLY` cannot run inside a transaction — if the migration runner wraps the file in `BEGIN`, the statement fails. Use a separate migration file (per `CONVENTIONS.md §1`).
- Multiple `ALTER TABLE` statements on the same hot table in one migration — each acquires `ACCESS EXCLUSIVE` independently; coalesce or split.
- `ALTER TABLE` followed by `UPDATE` followed by another `ALTER TABLE` — locks accumulate; consider splitting.

### (7) Cross-schema / search_path footguns

Partna's schemas: `public`, `core`, `site`, `notifications`, `analytics`, `audit`, `moderation`.

- Raw migration SQL referencing a table without schema qualification — works in dev (because `search_path` resolves it) but fails on a fresh prod DB if the schema isn't yet on the search_path.
- `CREATE FUNCTION ... SECURITY DEFINER` without a `SET search_path = ''` clause — the function inherits the caller's search_path, opening a privilege-escalation path. The fix: `SET search_path = ''` (empty string; forces schema-qualified builtins), as applied retroactively in `20260606040000_pin_function_search_paths.sql`. Any new function must pin its search_path at creation time.
- `GRANT` / `REVOKE` on the `app_backend` role inside a `DO $$ IF NOT EXISTS ... $$` block — the baseline creates `app_backend` as `NOLOGIN`; fresh-DB pushes need this guard (already present in `20260527010000_reorganize_schemas.sql`). New grants without this guard will fail on a fresh DB.

### (8) Trigger / function correctness during migration

- A migration that disables a trigger (`ALTER TABLE DISABLE TRIGGER`) without re-enabling it before commit — writes during the migration bypass the trigger.
- `CREATE OR REPLACE FUNCTION` changing a trigger's behaviour without a separate backfill for rows that should have been processed under the new logic.
- New trigger added with `FOR EACH STATEMENT` where `FOR EACH ROW` is intended — silent semantic change.

### (9) Migration hygiene

- Files without `IF NOT EXISTS` / `IF EXISTS` guards where the operation is idempotent in spirit (e.g. `CREATE INDEX IF NOT EXISTS`) — re-running on a partially-applied DB fails.
- Migrations that hardcode UUIDs or environment-specific timestamps — reproducibility issues, drift between dev and prod.
- Migrations that reference data from a different environment (`UPDATE ... WHERE id = '<dev-uuid>'`) — runs in dev, silently no-ops in prod.
- Filename ordering: Partna uses `YYYYMMDDHHMMSS_description.sql`. Any new migration with a timestamp earlier than the latest applied migration is skipped on already-deployed envs.
- Missing top-of-file comment explaining *why* the migration exists — schema diffs are not self-documenting.
- Missing `SET LOCAL lock_timeout` / `statement_timeout` on DDL against live-traffic tables. Per `docs/migration-guidelines.md` §Lock and statement timeouts: `SET LOCAL lock_timeout = '2s'; SET LOCAL statement_timeout = '10s';` should guard every migration that touches `site.design_kits`, `site.sites`, `site.blocks`, or `core.users`.

## Per-finding requirements

For every finding:
- Cite the category number (1–9).
- Quote the verbatim SQL from the migration file.
- Name the canonical fix: `CREATE INDEX CONCURRENTLY ...`, `ADD CONSTRAINT ... NOT VALID; VALIDATE CONSTRAINT ...`, `UPDATE ... WHERE <not-yet-backfilled>`, `CREATE OR REPLACE TRIGGER`, `SET search_path = ''`, `SET LOCAL lock_timeout = '2s'`, etc.
- Estimate the lock duration / risk class: **fast metadata-only** (safe), **weak lock + table scan** (safe with planning), **`ACCESS EXCLUSIVE` on hot table** (block deploy until split).

## Out of scope — do NOT re-flag

- Schema *correctness* (does the FK exist? is the CHECK right?) — that's `data-integrity.md` (DINT prefix).
- RLS policies and `search_path` semantics for runtime queries — that's `schema-rls.md` (SCHEMA prefix).
- Migrations in `supabase/migrations-archive/` — historical, already applied on dev.
- Findings about Laravel migration files (none exist; CI rejects them).
- `app_backend` role NOLOGIN (intentional fail-closed — not a finding).

## Suggested per-domain scope groups

### Group A — All migrations (the only source of truth)
```
--scope supabase/migrations
```

### Group B — Recent / unapplied migrations
Use `git log --oneline -- supabase/migrations/ | head -30` to identify candidates, then scope to those specific files.

### Group C — Trigger / function migrations
```
--scope supabase/migrations
```
…filtered to files containing `TRIGGER` or `FUNCTION` (the scan tier will discover these in scope).

## Exhaustiveness directive

Walk every migration file in scope. For each `ALTER TABLE`, `CREATE INDEX`, `ADD CONSTRAINT`, `UPDATE`, `DELETE`, `DROP`, and `CREATE OR REPLACE TRIGGER` / `FUNCTION` statement, ask: *does this hold a strong lock on a hot table, modify data without idempotency, or change behaviour in a window that allows writes to bypass invariants?* Emit a finding for every quotable instance. **Half-applied migrations are the worst kind of prod incident — they're invisible until the next write breaks.**
