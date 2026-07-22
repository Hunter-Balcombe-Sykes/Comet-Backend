# Supabase Migration Conventions

These rules apply to every `.sql` file added under `supabase/migrations/` after 2026-05-14.
Pre-convention migrations are grandfathered (they ran safely on empty tables before launch).

A CI lint (`guard:no-unsafe-migrations`) enforces the three most dangerous violations automatically.

---

## 1. Index creation — always `CONCURRENTLY`, always outside a transaction

**Safe pattern**

```sql
-- File: 20260601000001_add_foo_bar_idx.sql  (note the +1 suffix — index file is separate)
-- No BEGIN/COMMIT — CONCURRENTLY cannot run inside a transaction.
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_foo_bar
    ON commerce.foo (bar)
    WHERE deleted_at IS NULL;
```

**Two-file convention**: when a migration needs both schema changes (inside a `BEGIN`/`COMMIT` block)
and new indexes, split into two files with the same timestamp prefix + sequential suffixes:

```
20260601000000_add_foo_columns.sql      ← DDL inside BEGIN/COMMIT
20260601000001_add_foo_indexes.sql      ← CONCURRENTLY outside any transaction
```

**A `CONCURRENTLY` statement must be ALONE in its file** — a file that contains a
`CREATE`/`DROP`/`REINDEX … CONCURRENTLY` statement must contain **only that one statement**: no
second `CONCURRENTLY`, no other DDL/DML, and no `BEGIN`/`COMMIT`. The Supabase CLI applier
(`supabase db reset` **and** `supabase db push`) sends a file's statements to Postgres as a single
libpq **pipeline** (an implicit transaction) whenever the file has more than one statement — of any
kind — and `CONCURRENTLY` cannot run inside a pipeline/transaction (`SQLSTATE 25001`). So a from-zero
`db reset`/`db push` aborts on any file that pairs a `CONCURRENTLY` statement with anything else.
Split multi-index changes into consecutive one-statement files sharing the timestamp prefix with
sequential suffixes (`…000001`, `…000002`, …); put any accompanying non-index DDL in its own
`BEGIN`/`COMMIT` file. Enforced by `scripts/guard-no-unsafe-migrations.php` **Check 6** for files
timestamped after `20260721000000`; nine pre-convention files are grandfathered.

**Dropping an index on a hot table** follows the same rule: use `DROP INDEX CONCURRENTLY IF EXISTS`
in its own one-statement file, never inside a `BEGIN`/`COMMIT`. A bare `DROP INDEX` takes
`ACCESS EXCLUSIVE` on the index's table for the catalog write, blocking every writer until it
completes — the same downtime class as a non-concurrent `CREATE INDEX`. Enforced by
`scripts/guard-no-unsafe-migrations.php` **Check 7** for files timestamped after `20260722000000`
(hot tables: `site.design_kits`, `site.sites`, `site.blocks`, `core.users`); pre-convention files
are grandfathered, and any file that must deviate carries the
`-- guard:no-unsafe-migrations:disable-file` marker with a written justification.

Because those grandfathered bundles cannot be applied from zero by the CLI, **local fresh
provisioning uses `scripts/db/fresh-reset.sh`** (a `psql` simple-query loop — each statement runs as
its own top-level command, so `CONCURRENTLY` succeeds). The fresh-**prod** cutover uses the psql
procedure documented in `CLAUDE.md` → "Push to Supabase / Fresh prod DB". `supabase db reset`/`db
push` are **not** usable from an empty database here.

**Why**: `CREATE INDEX` (non-concurrent) acquires a `SHARE` lock on the target table for the entire
build duration, blocking all `INSERT`/`UPDATE`/`DELETE`. On a table with millions of rows, this is
minutes of write downtime. `CONCURRENTLY` builds the index in multiple passes under weaker locks,
so traffic flows throughout.

**Canonical example**: `20260424120000_add_live_check_index.sql`

---

## 2. CHECK constraints on populated tables

**Safe pattern**

```sql
-- Step A — add NOT VALID (lock-light; skips existing row validation)
BEGIN;
ALTER TABLE commerce.orders
    ADD CONSTRAINT orders_status_check CHECK (status IN ('pending','active','fulfilled')) NOT VALID;
COMMIT;

-- Step B — validate in a separate transaction (SHARE UPDATE EXCLUSIVE — doesn't block writes)
BEGIN;
ALTER TABLE commerce.orders VALIDATE CONSTRAINT orders_status_check;
COMMIT;
```

Split into two migration files if the validation window matters for rollout sequencing.

**Why**: `ADD CONSTRAINT CHECK` without `NOT VALID` acquires `ACCESS EXCLUSIVE` and performs a
full-table scan while holding it. `NOT VALID` drops the lock immediately after the catalog write;
`VALIDATE CONSTRAINT` acquires only `SHARE UPDATE EXCLUSIVE`, which allows concurrent reads
and writes.

**Inline column CHECK counts too**: `ALTER TABLE … ADD COLUMN col … CHECK (…)` validates existing
rows under `ACCESS EXCLUSIVE` exactly like a bare `ADD CONSTRAINT CHECK`. Split it — `ADD COLUMN`
(no inline `CHECK`) first, then a named `ADD CONSTRAINT … NOT VALID` → `VALIDATE CONSTRAINT` in a
separate transaction. Guard Check 3 matches only `ADD CONSTRAINT … CHECK`, so an inline column CHECK
is a convention, not a lint (audit `migrations-early/MIG-3`).

**Enforced**: `scripts/guard-no-unsafe-migrations.php` **Check 8** fails any file timestamped after
`20260722000000` that runs `VALIDATE CONSTRAINT` in the same transaction as its `ADD CONSTRAINT …
NOT VALID` (no `COMMIT` between them) — the bundling holds the heavier catalog-write lock through the
whole validation scan, defeating the split. Put `VALIDATE CONSTRAINT` in its own transaction or file;
pre-convention files are grandfathered. The same rule applies to FK constraints (§4).

---

## 3. `SET NOT NULL` on populated tables — four-step pattern

Direct `ALTER COLUMN SET NOT NULL` acquires `ACCESS EXCLUSIVE` and validates every row under the
lock. Use this four-step sequence instead:

```sql
-- Step 1: Add NOT VALID check (no row scan, lock released immediately)
BEGIN;
ALTER TABLE commerce.orders
    ADD CONSTRAINT chk_orders_col_not_null CHECK (col IS NOT NULL) NOT VALID;
COMMIT;

-- Step 2: Backfill any NULLs — in a separate one-shot job or migration OUTSIDE a transaction
UPDATE commerce.orders SET col = <default> WHERE col IS NULL;

-- Step 3: Validate (acquires SHARE UPDATE EXCLUSIVE — allows concurrent writes)
BEGIN;
ALTER TABLE commerce.orders VALIDATE CONSTRAINT chk_orders_col_not_null;
COMMIT;

-- Step 4: Promote to NOT NULL (metadata-only once Postgres has a validated check; near-instant)
BEGIN;
ALTER TABLE commerce.orders ALTER COLUMN col SET NOT NULL;
ALTER TABLE commerce.orders DROP CONSTRAINT chk_orders_col_not_null;
COMMIT;
```

Step 4 is near-instant because Postgres skips the row scan when a validated `NOT NULL` check
already exists. Never combine Steps 1–4 into a single transaction.

---

## 4. Foreign key constraints — always `NOT VALID` first

```sql
-- Step A — add FK without validation
BEGIN;
ALTER TABLE commerce.order_items
    ADD CONSTRAINT fk_order_items_orders
    FOREIGN KEY (order_id) REFERENCES commerce.orders(id) ON DELETE CASCADE NOT VALID;
COMMIT;

-- Step B — validate separately
BEGIN;
ALTER TABLE commerce.order_items VALIDATE CONSTRAINT fk_order_items_orders;
COMMIT;
```

**Why**: `ADD CONSTRAINT FOREIGN KEY` without `NOT VALID` takes `ACCESS EXCLUSIVE` and validates
every row. With `NOT VALID`, only new rows are checked at write time; `VALIDATE CONSTRAINT` back-fills
the existing rows under `SHARE UPDATE EXCLUSIVE`.

---

## 5. Never backfill data inside a migration transaction

If new rows need default values, dispatch a one-shot queued job after the migration runs, or run
the `UPDATE` in a separate file outside any `BEGIN`/`COMMIT` block.

```sql
-- BAD — migration holds ACCESS EXCLUSIVE while updating millions of rows:
BEGIN;
ALTER TABLE commerce.orders ADD COLUMN region text;
UPDATE commerce.orders SET region = 'AU';  -- full table scan under lock
COMMIT;

-- GOOD — DDL is fast; backfill runs outside the lock window:
-- File 1: 20260601000000_add_region_to_orders.sql
BEGIN;
ALTER TABLE commerce.orders ADD COLUMN region text;
COMMIT;

-- File 2: 20260601000001_backfill_orders_region.sql  (or a dispatched job)
UPDATE commerce.orders SET region = 'AU' WHERE region IS NULL;
```

---

## 6. Migration testing requirements for hot tables

Any migration that touches one of the hot `commerce.*` tables **must** be tested against a staging
database snapshot with at least 100,000 rows in the target table before deploying to production.
This surfaces lock-contention issues that only appear at scale.

**Hot tables requiring pre-prod load testing:**

| Table | Why it matters |
|-------|---------------|
| `commerce.orders` | Core transaction table; every payout sweep reads it |
| `commerce.order_events` | Append-only audit log; Shopify webhooks write constantly |
| `commerce.commission_movements` | Payout ledger; read on every affiliate dashboard load |
| `commerce.commission_payouts` | Payout batch table; sweep updates in bulk |
| `commerce.brand_affiliate_rollup` | Trigger-maintained rollup; high write frequency |

**How to test**: restore a prod snapshot to the dev Supabase project, run `supabase db push --dry-run`
to confirm the migration plan, then `supabase db push`. Monitor Supabase lock metrics during the run
and check that no row-level lock waits exceed 100ms.

---

## 7. Schema-qualify `DROP FUNCTION` / `DROP TRIGGER`

Always name the schema when dropping a function or trigger:

```sql
-- BAD — resolves against search_path; if the function lives in `core` and core isn't
-- on the path, `IF EXISTS` makes this a silent no-op, orphaning any trigger bound to it:
DROP FUNCTION IF EXISTS set_default_theme_for_site CASCADE;

-- GOOD — names the schema explicitly, so it always resolves:
DROP FUNCTION IF EXISTS core.set_default_theme_for_site() CASCADE;
```

An unqualified `DROP FUNCTION IF EXISTS` that fails to resolve leaves a dependent trigger live and
bound to a function whose body may reference columns the same migration then drops — the next write
to the table 500s until the follow-up migration lands. Drop the trigger (schema-qualified) *before*
dropping the column its function reads, in the same transaction. Convention only — the guard doesn't
parse object resolution (audit `migrations-early/MIG-4`).

---

## 8. Lock and statement timeouts on hot-table DDL

Every migration that runs DDL/DML against a live-traffic table (`site.design_kits`, `site.sites`,
`site.blocks`, `core.users`) opens a transaction and sets bounded timeouts, so a blocked lock aborts
fast instead of stalling the whole sequential `db push`:

```sql
BEGIN;
SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';
-- … DDL …
COMMIT;
```

**Enforced** by `scripts/guard-no-unsafe-migrations.php` **Check 5** for files timestamped after
`20260711999999`. This is **forward-only**: the pre-convention files that lack the guards are
grandfathered, not retrofitted — they re-apply against an empty, traffic-free DB at the prod cutover,
where there is no live traffic to contend for the lock (audit `migrations-early/MIG-7`,
`migrations-recent/MIG-8`). Add the guards to every *new* hot-table migration.

---

## Summary cheat sheet

| Operation | Unsafe | Safe |
|-----------|--------|------|
| Add index | `CREATE INDEX` | `CREATE INDEX CONCURRENTLY IF NOT EXISTS` (outside transaction) |
| Add CHECK | `ADD CONSTRAINT CHECK (...)` | `ADD CONSTRAINT ... NOT VALID` → `VALIDATE CONSTRAINT` |
| Set NOT NULL | `ALTER COLUMN SET NOT NULL` | Four-step NOT VALID pattern (see §3) |
| Add FK | `ADD CONSTRAINT FOREIGN KEY` | `ADD CONSTRAINT ... NOT VALID` → `VALIDATE CONSTRAINT` |
| Backfill data | `UPDATE` inside migration transaction | Separate file or dispatched job |
| DDL/DML on a hot table | No timeout, or `SET LOCAL` with no `BEGIN` | `BEGIN;` + `SET LOCAL lock_timeout`/`statement_timeout` + `COMMIT;` |
| Inline column CHECK | `ADD COLUMN col … CHECK (…)` | `ADD COLUMN` then `ADD CONSTRAINT … NOT VALID` → `VALIDATE` (see §2) |
| Drop index (hot table) | `DROP INDEX` | `DROP INDEX CONCURRENTLY IF EXISTS` (own file, no transaction) (see §1) |
| Drop function/trigger | Unqualified `DROP FUNCTION foo` | Schema-qualified `DROP FUNCTION schema.foo()` (see §7) |
| Bundle VALIDATE with ADD | `ADD … NOT VALID; VALIDATE;` one txn | `COMMIT` between them — VALIDATE in its own txn/file (see §2) |
