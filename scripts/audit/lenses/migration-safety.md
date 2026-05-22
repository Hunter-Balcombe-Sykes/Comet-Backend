# Migration safety: lock-on-deploy risk, backfill ordering, online DDL hygiene, reversibility

Hunt **migrations that lock hot tables during deploy**, **backfills with broken ordering**, **DDL patterns that fail on prod-sized data**, and **destructive operations without a rehearsal path**. Migration *correctness* (does the schema express the right constraints) lives in `data-integrity.md` — this lens is specifically about **operational safety of the migration itself**: can we run it on the production DB without taking the app down, losing data, or deploying a half-applied schema?

Partna runs on **PostgreSQL** (Supabase-hosted, single primary, no read replicas). Migrations are raw SQL files in `supabase/migrations/` and deploy via `supabase db push` (see CLAUDE.md "push semantics"). There is no Laravel migration table to gate concurrent runs — Supabase's `supabase_migrations.schema_migrations` is the source of truth, and a half-applied file leaves prod in an inconsistent state. Recent schema work has included renames (`commission_ledger_entries` → `commission_movements`), narrowing (Phase 4 `entry_type` filter), backfills (`20260506400000_backfill_orders_payout_id.sql`), and a fresh-prod-DB role-login fix — patterns that have already bitten us.

This lens is a **sibling** to `data-integrity.md` (schema-side correctness) and `schema-rls.md` (RLS / search_path). If a finding could go in either, emit it under whichever is more specific — the adjudicator dedupes.

## Use the lens prefix `MIG` for findings

Number them `MIG-1`, `MIG-2`, … sequentially. **P0 for confirmed prod-deploy lockup risk on a hot table OR data loss. P1 for backfill ordering errors, missing CONCURRENTLY on a large table, irreversible destructive ops without a rehearsal note. P2 for missing NOT VALID + VALIDATE split, non-idempotent migrations. P3 for hygiene (missing `IF NOT EXISTS`, missing comment, ordering of unrelated statements).**

## Findings categories

### (1) Lock-on-deploy risk (hot-table DDL)

The Partna scale target (1M orders/year, peak ~3K orders/day, ~3K daily Shopify webhook deliveries) means tables in `commerce.*`, `core.professionals`, `notifications.*`, and `site.*` are write-hot during business hours. Locks on these tables stall production.

- `CREATE INDEX` without `CONCURRENTLY` on a table with >10K rows — `ACCESS EXCLUSIVE` lock blocks reads and writes for the duration.
- `ALTER TABLE ... ADD COLUMN ... NOT NULL DEFAULT <volatile>` — Postgres 11+ avoids the table rewrite only for **immutable** defaults; volatile defaults (`now()`, `gen_random_uuid()`) still rewrite the table.
- `ALTER TABLE ... ALTER COLUMN TYPE` that requires a rewrite (e.g. `TEXT` → `UUID`, widening a `NUMERIC`) — full rewrite under `ACCESS EXCLUSIVE`.
- `ALTER TABLE ... ADD CONSTRAINT ... CHECK (...)` without `NOT VALID` — validates every existing row under a strong lock.
- `ALTER TABLE ... ADD FOREIGN KEY (...)` without `NOT VALID` — scans the entire child table under `SHARE ROW EXCLUSIVE`.
- `ALTER TABLE ... SET NOT NULL` on an existing nullable column — full table scan under `ACCESS EXCLUSIVE`. The safe pattern is `ADD CHECK (col IS NOT NULL) NOT VALID` → `VALIDATE CONSTRAINT` → `ALTER COLUMN SET NOT NULL` in a follow-up migration once Postgres knows the constraint holds.
- `DROP INDEX` without `CONCURRENTLY` — brief but real `ACCESS EXCLUSIVE` lock.
- `VACUUM FULL` or `CLUSTER` in a migration — `ACCESS EXCLUSIVE` for the duration.
- `REINDEX TABLE` without `CONCURRENTLY` (Postgres 12+).

### (2) Missing `NOT VALID` / `VALIDATE CONSTRAINT` split

The two-step pattern for FKs and CHECKs on existing data:

1. `ADD CONSTRAINT ... NOT VALID` — fast metadata-only operation, new writes are checked, old rows are not.
2. `VALIDATE CONSTRAINT ...` — scans existing rows under a weaker `SHARE UPDATE EXCLUSIVE` lock that allows reads + writes.

- Migration adds a constraint without `NOT VALID` on a table that has any data — flag.
- Migration adds `NOT VALID` but the corresponding `VALIDATE CONSTRAINT` follow-up never lands — the constraint exists on paper but is not enforced for old rows.
- `VALIDATE CONSTRAINT` run inside the same migration as `NOT VALID` — wastes the two-step optimization but is otherwise safe; flag as hygiene if the table is large.

### (3) Backfill ordering & idempotency

- Backfill SQL that depends on a column added in the same migration without `COMMIT` between — Postgres DDL is transactional, but if the migration is split across files, ordering matters and a partial apply leaves prod with the column but no data.
- `UPDATE` backfills without a `WHERE` clause that limits to "rows needing backfill" — re-running the migration overwrites already-corrected rows.
- Backfills that compute the new value from a column being dropped or renamed in the same migration — irreversible if the migration fails mid-flight.
- Backfills on tables >100K rows without batching (`WHERE id IN (...) LIMIT N`) — long-running `UPDATE` holds row locks and bloats WAL.
- Backfills that read from a table that may not yet exist on a fresh prod DB — order-of-migration risk. The v2 baseline + `20260506400000_backfill_orders_payout_id.sql` is the canonical pattern; new backfills should follow it.

### (4) Destructive operations without rehearsal

- `DROP TABLE` / `DROP COLUMN` / `DROP CONSTRAINT` in a migration that hasn't shipped to `development` first — the env table in CLAUDE.md exists precisely so dev catches this; flag any migration whose accompanying PR description doesn't mention a dev run.
- `DROP COLUMN` immediately after `RENAME COLUMN` to the new name in the same file — if rollback is needed, the old data is gone.
- `TRUNCATE` on any table — irreversible; flag and require a comment justifying it.
- Column rename done as `DROP COLUMN` + `ADD COLUMN` instead of `RENAME COLUMN` — data loss.
- `ALTER COLUMN ... DROP DEFAULT` followed by `SET DEFAULT` — confirm the gap doesn't let nulls in.
- Trigger / function `DROP` + `CREATE` without `CREATE OR REPLACE` — a window where the trigger doesn't exist, during which writes bypass it. Especially critical for `brand_affiliate_rollup` maintenance triggers.

### (5) Reversibility

- Migration has no documented rollback path (CLAUDE.md doesn't require a `down` SQL but a comment explaining "to revert: …" is the established convention) — flag absent comment on destructive migrations.
- Reversible-on-paper but irreversible-in-practice operations: `DROP COLUMN` of a column populated by application writes, `DROP CONSTRAINT UNIQUE` after duplicate rows could have been inserted.
- `RENAME COLUMN` without `CREATE OR REPLACE VIEW` updates for any view that references it — view silently breaks until the next deploy.

### (6) Multi-statement transaction hazards

- Long-running DDL statements (`CREATE INDEX CONCURRENTLY`, `VALIDATE CONSTRAINT`) inside an implicit transaction with other DDL — `CREATE INDEX CONCURRENTLY` cannot run inside a transaction at all; if the migration runner wraps the file in `BEGIN`, the statement fails.
- Multiple `ALTER TABLE` statements on the same hot table in one migration — each acquires `ACCESS EXCLUSIVE` independently; coalesce or split.
- `ALTER TABLE` followed by `UPDATE` followed by another `ALTER TABLE` — the locks accumulate; consider splitting into separate deploys.

### (7) Cross-schema / search_path footguns

Partna's `search_path` covers `public`, `core`, `site`, `brand`, `commerce`, `notifications`, `analytics`, `billing`.

- Raw migration SQL referencing a table without schema qualification — works in dev (because `search_path` resolves it) but fails on a fresh prod DB if the schema isn't yet on the search_path.
- `CREATE FUNCTION ... SECURITY DEFINER` without a `SET search_path = ...` clause — the function inherits the caller's search_path, opening a privilege-escalation path (the `commerce.brand_affiliate_rollup` triggers should set their own search_path).
- `GRANT` / `REVOKE` on a role (`app_backend`) that doesn't exist on a fresh prod DB — CLAUDE.md flags this; the role is created `NOLOGIN` in the v2 baseline.

### (8) Trigger / function correctness during migration

- A migration that disables a trigger (`ALTER TABLE DISABLE TRIGGER`) without re-enabling it before commit — writes during the migration silently bypass the trigger.
- `CREATE OR REPLACE FUNCTION` that changes a trigger's behaviour without a separate migration to backfill rows that should have been processed under the new logic.
- New trigger added with `FOR EACH STATEMENT` where `FOR EACH ROW` is intended (or vice versa) — silent semantic change.

### (9) Migration hygiene

- Files without an `IF NOT EXISTS` / `IF EXISTS` guard where the operation is idempotent in spirit (e.g. `CREATE INDEX IF NOT EXISTS`) — re-running the migration on a partially-applied DB fails.
- Migrations that hardcode UUIDs or timestamps — reproducibility issues, drift between dev and prod.
- Migrations that reference data from a different environment (`UPDATE ... WHERE id = '<dev-uuid>'`) — runs in dev, silently no-ops in prod.
- Filename ordering: Partna uses `YYYYMMDDHHMMSS_description.sql`. Flag any new migration with a timestamp earlier than the latest applied migration in `supabase/migrations/` — it will be skipped on already-deployed envs.
- Missing top-of-file comment explaining *why* the migration exists — schema diffs are not self-documenting.

## Per-finding requirements

For every finding:
- Cite the category number (1–9).
- Quote the verbatim SQL from the migration file.
- Name the canonical fix: `CREATE INDEX CONCURRENTLY ...`, `ADD CONSTRAINT ... NOT VALID; VALIDATE CONSTRAINT ...`, `UPDATE ... WHERE <not-yet-backfilled>`, `CREATE OR REPLACE TRIGGER`, `SET search_path = commerce, public`, etc.
- Estimate the lock duration / risk class: **fast metadata-only** (safe), **weak lock + table scan** (safe with planning), **`ACCESS EXCLUSIVE` on hot table** (block deploy until split).

## Out of scope — do NOT re-flag

- Schema *correctness* (does the FK exist? is the CHECK right?) — that's `data-integrity.md` (DINT prefix).
- RLS policies and `search_path` semantics for runtime queries — that's `schema-rls.md` (SCHEMA prefix).
- Migrations in `supabase/migrations/` that are already deployed to prod and have not been amended — historic.
- Findings about Laravel migration files (none exist; CI rejects them).

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
