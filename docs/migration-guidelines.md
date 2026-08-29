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

DDL against tables served by live traffic should be guarded by timeouts so a stuck lock-wait doesn't cascade into a deploy outage. This is CI-enforced: `guard:no-unsafe-migrations` Check 5 fails any migration that runs `ALTER TABLE` or `UPDATE` against one of these tables without a lock timeout — see `scripts/guard-no-unsafe-migrations.php`.

**The list is eleven tables, in two groups with two different cutoffs** (the `content.*` group was added in 2026-08, long after its own migrations had already been applied to dev — hence the later boundary):

| Group | Tables | Constant | Cutoff |
|---|---|---|---|
| `site.*` / `core.*` | `site.design_kits`, `site.sites`, `site.blocks`, `core.users` | `HOT_TABLES` | `TIMEOUT_GUARD_CUTOFF` = `20260711999999` |
| `content.*` — the curation store, written on every ingest run and read on every public profile render | `content.items`, `content.item_media`, `content.offers`, `content.item_tags`, `content.item_variants`, `content.source_items`, `content.media_assets` | `CONTENT_HOT_TABLES` | `CONTENT_TIMEOUT_GUARD_CUTOFF` = `20260828999999` |

**DDL — add inside the transaction:**
```sql
BEGIN;
SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';
-- … DDL …
COMMIT;
```

`SET LOCAL` scopes the timeout to the current transaction only — it has no effect on application queries. It also has no effect at all *outside* a transaction: outside `BEGIN`/`COMMIT` it is a silent no-op, which is why Check 5 requires the explicit `BEGIN;` alongside it.

**A backfill takes the session-level form instead.** `CONVENTIONS.md` §5 forbids running a backfill inside a migration transaction, so a bare-statement backfill file cannot use `SET LOCAL` at all. Bound it once at the top of the file:

```sql
SET lock_timeout      = '2s';
SET statement_timeout = '10s';

UPDATE content.item_media m SET … WHERE …;
```

Check 5 accepts either shape and prints whichever remedy fits the file it flagged.

If the DDL cannot acquire the lock within 2 s, the migration aborts with a clear error rather than silently queuing. Fix the cause (outstanding long-running query) and re-run; don't raise the timeout as the first response.

## Inline `ADD COLUMN … REFERENCES` (#W1-MIG-1)

An FK written inline on an `ADD COLUMN` is created **validated**: Postgres scans the whole table under the `ADD COLUMN`'s `ACCESS EXCLUSIVE` lock, and holds `SHARE ROW EXCLUSIVE` on the *referenced* table throughout, blocking that table's writers too. There is no `NOT VALID` escape hatch inline.

**Avoid:**
```sql
ALTER TABLE content.item_media
    ADD COLUMN source_item_id uuid REFERENCES content.source_items (id) ON DELETE CASCADE;
```

**Prefer — the §4 split:**
```sql
ALTER TABLE content.item_media ADD COLUMN source_item_id uuid;

ALTER TABLE content.item_media
    ADD CONSTRAINT item_media_source_item_id_fkey
    FOREIGN KEY (source_item_id) REFERENCES content.source_items (id) ON DELETE CASCADE
    NOT VALID;

-- separate transaction / file:
ALTER TABLE content.item_media VALIDATE CONSTRAINT item_media_source_item_id_fkey;
```

CI-enforced by Check 10 (`INLINE_FK_GUARD_CUTOFF` = `20260828999999`); exempt when the table is created in the same file. Check 2 anchors on `ADD CONSTRAINT` and cannot see this shape — that blindness is the whole reason Check 10 exists.

## Editing already-applied migrations

The Supabase CLI tracks applied migrations by their **version timestamp**, not by a content hash (verified against CLI 2.101.0 — `migration list` shows aligned Local/Remote rows even after a file's SQL is edited). Consequences:

- Editing the SQL of a migration already recorded in an environment's history does **not** block `supabase db push` and does **not** re-run that file there. `db push` applies only versions absent from the remote history; an edited-but-already-applied file is skipped. The edit takes effect **only on a fresh apply** (first prod deploy, `db reset`, disaster recovery).
- So back-filling idempotency guards (`IF [NOT] EXISTS`, `DROP TRIGGER IF EXISTS`) into old files is safe for existing environments (no-op on push) and only changes fresh-apply behaviour — exactly their purpose. No `migration repair` is needed for content edits.

**The real hazard is schema divergence, not a push error.** Because the edit never runs on already-applied environments, a *semantic* change (not just a guard) leaves those environments out of sync with what the file now describes — and a fresh apply will produce a different schema. For any change that alters resulting schema, add a **new** migration instead of editing an applied one, so every environment converges.

`supabase migration repair` is for **version**-level divergence — a file deleted after it was applied, or a migration applied directly to the DB with no file — which surfaces as Remote-only rows in `supabase migration list`. It is not for content edits.

> The 2026-06-03 `IF [NOT] EXISTS` back-fills (`20260529044737`, `20260529053028`, `20260527080000`–`20260527140000`) needed no repair: `migration list` showed them aligned, `db push --dry-run` queued nothing for them. They only harden fresh applies / re-runs.
