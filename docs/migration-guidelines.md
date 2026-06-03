# Migration Guidelines

Patterns and anti-patterns for Supabase migration files in `supabase/migrations/`.

## Full-table-scan data scrubs (#SCHEMA-2)

Inline `UPDATE` statements that scan every row in a migration should be extracted into a post-deploy artisan command or background job. An inline scrub holds an `ACCESS EXCLUSIVE` lock on the table for the duration of the scan; as row counts grow that can queue DML for seconds during deploy.

**Avoid:**
```sql
UPDATE site.sites SET settings = settings - 'design' WHERE settings ? 'design';
```

**Prefer:** a post-deploy command (`php artisan migrate:clean-stale-design`) or a chunked job dispatched after the migration transaction commits, so the lock is released before application traffic resumes.

The migration's own comment on the `design_kits` backfill documents the correct pattern: "backfilled separately (see Phase 2 step 2.4 in the plan — not in the migration so the backfill window stays predictable)." Apply the same rule to any scrub.

## CHECK constraints on large tables (#SCALE-1)

Adding a `CHECK` constraint with `ADD COLUMN ... CHECK(...)` validates every existing row inside the migration transaction, holding an `ACCESS EXCLUSIVE` lock for the scan duration.

**Avoid (for large tables):**
```sql
ALTER TABLE site.sites
  ADD COLUMN skeleton_id TEXT NOT NULL
    DEFAULT 'skeleton-1'
    CHECK (skeleton_id IN ('skeleton-1','skeleton-2','skeleton-3','skeleton-4'));
```

**Prefer — NOT VALID + VALIDATE split:**
```sql
-- Step 1: add the column and constraint but skip the validation scan.
ALTER TABLE site.sites
  ADD COLUMN skeleton_id TEXT NOT NULL DEFAULT 'skeleton-1';

ALTER TABLE site.sites
  ADD CONSTRAINT sites_skeleton_id_check
    CHECK (skeleton_id IN ('skeleton-1','skeleton-2','skeleton-3','skeleton-4'))
    NOT VALID;

-- Step 2: validate in a separate statement (or separate migration).
-- Postgres acquires only SHARE UPDATE EXCLUSIVE for this — non-blocking.
ALTER TABLE site.sites
  VALIDATE CONSTRAINT sites_skeleton_id_check;
```

At current row counts (≤ 200) the validation scan is instant; the split matters at scale. Establish the pattern now.

## Lock and statement timeouts (#SCALE-3)

DDL against tables served by live traffic (`site.design_kits`, `site.sites`, `site.blocks`) should be guarded by timeouts so a stuck lock-wait doesn't cascade into a deploy outage.

**Add at the top of every migration that runs DDL on a live-traffic table:**
```sql
SET LOCAL lock_timeout    = '2s';
SET LOCAL statement_timeout = '10s';
```

`SET LOCAL` scopes the timeout to the current transaction only — it has no effect on application queries.

If the DDL cannot acquire the lock within 2 s, the migration aborts with a clear error rather than silently queuing. Fix the cause (outstanding long-running query) and re-run; don't raise the timeout as the first response.

## Editing already-applied migrations

The Supabase CLI tracks applied migrations by their **version timestamp**, not by a content hash (verified against CLI 2.101.0 — `migration list` shows aligned Local/Remote rows even after a file's SQL is edited). Consequences:

- Editing the SQL of a migration already recorded in an environment's history does **not** block `supabase db push` and does **not** re-run that file there. `db push` applies only versions absent from the remote history; an edited-but-already-applied file is skipped. The edit takes effect **only on a fresh apply** (first prod deploy, `db reset`, disaster recovery).
- So back-filling idempotency guards (`IF [NOT] EXISTS`, `DROP TRIGGER IF EXISTS`) into old files is safe for existing environments (no-op on push) and only changes fresh-apply behaviour — exactly their purpose. No `migration repair` is needed for content edits.

**The real hazard is schema divergence, not a push error.** Because the edit never runs on already-applied environments, a *semantic* change (not just a guard) leaves those environments out of sync with what the file now describes — and a fresh apply will produce a different schema. For any change that alters resulting schema, add a **new** migration instead of editing an applied one, so every environment converges.

`supabase migration repair` is for **version**-level divergence — a file deleted after it was applied, or a migration applied directly to the DB with no file — which surfaces as Remote-only rows in `supabase migration list`. It is not for content edits.

> The 2026-06-03 `IF [NOT] EXISTS` back-fills (`20260529044737`, `20260529053028`, `20260527080000`–`20260527140000`) needed no repair: `migration list` showed them aligned, `db push --dry-run` queued nothing for them. They only harden fresh applies / re-runs.
