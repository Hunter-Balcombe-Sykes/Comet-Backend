# Supabase Migration Conventions

These rules apply to every `.sql` file added under `supabase/migrations/` after 2026-05-14.
Pre-convention migrations are grandfathered (they ran safely on empty tables before launch).

A CI lint (`guard:no-unsafe-migrations`, also a pre-push hook) enforces ten of these rules
automatically; the rest are convention. Each check carries its own grandfathering cutoff — see the
constants at the top of `scripts/guard-no-unsafe-migrations.php`.

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
timestamped after `20260721000000`. Eleven pre-convention files *were* grandfathered (nine bundling
several `CONCURRENTLY` statements, two pairing a single `CONCURRENTLY` with other DDL — equally
fatal to a pipelined from-zero apply); all of them moved to `supabase/migrations-archive/` in the
2026-07-26 baseline collapse, so no file in `supabase/migrations/` bundles `CONCURRENTLY` today.

**Dropping an index on a hot table** follows the same rule: use `DROP INDEX CONCURRENTLY IF EXISTS`
in its own one-statement file, never inside a `BEGIN`/`COMMIT`. A bare `DROP INDEX` takes
`ACCESS EXCLUSIVE` on the index's table for the catalog write, blocking every writer until it
completes — the same downtime class as a non-concurrent `CREATE INDEX`. Enforced by
`scripts/guard-no-unsafe-migrations.php` **Check 7** for files timestamped after `20260722000000`
(hot tables: `site.design_kits`, `site.sites`, `site.blocks`, `core.users` — deliberately *not* the
`content.*` group §8 adds to Check 5: Check 7 infers the table from the index *name*, and tokens like
`items`/`offers`/`item_tags` collide far too readily to be worth the false positives); pre-convention
files are grandfathered, and any file that must deviate carries the
`-- guard:no-unsafe-migrations:disable-file` marker with a written justification.

The collapsed baseline is `CONCURRENTLY`-free, so a from-zero apply of `supabase/migrations/` is now
a single file the CLI can handle. **Local fresh provisioning still uses `scripts/db/fresh-reset.sh`** (a `psql` simple-query loop — each statement runs as
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

**Inline `REFERENCES` counts too**: `ALTER TABLE … ADD COLUMN col uuid REFERENCES other(id)` creates
the FK **validated** — Postgres runs `RI_Initial_Check` over the whole table inside the `ADD COLUMN`'s
`ACCESS EXCLUSIVE` window, and holds `SHARE ROW EXCLUSIVE` on the *referenced* table while it does,
blocking that table's writers too. There is no `NOT VALID` escape hatch inline. Split it: `ADD COLUMN`
with no inline `REFERENCES`, then a named `ADD CONSTRAINT … NOT VALID`, then `VALIDATE CONSTRAINT` in
a separate transaction — the §4 pattern above, exactly as written. Enforced by
`scripts/guard-no-unsafe-migrations.php` **Check 10** for files timestamped after `20260828999999`;
Check 2 anchors on `ADD CONSTRAINT` and is structurally blind to this shape, which is why it needed a
check of its own (audit `#W1-MIG-1`, the same class of hole as the inline column CHECK in §2). The two
pre-existing files that use the pattern (`20260812090000_content_media_assets_site_media_id`,
`20260826120000_facet_source_item_origin`) are **grandfathered**: both landed on sub-6 MB `content.*`
tables where the validation scan cost milliseconds, both FKs are already validated on dev, and
re-splitting an applied-and-validated FK (`DROP CONSTRAINT` → re-add `NOT VALID` → `VALIDATE`) takes
strictly *more* locking than leaving it while opening an FK-absent write window on a live-harvested
table. Production has no `content` schema, so those files apply against empty tables there.

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

Every migration that runs DDL/DML against a live-traffic table opens a transaction and sets bounded
timeouts, so a blocked lock aborts fast instead of stalling the whole sequential `db push`:

```sql
BEGIN;
SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';
-- … DDL …
COMMIT;
```

**The hot list is eleven tables**, in two groups with two different cutoffs:

| Group | Tables | Cutoff |
|---|---|---|
| `site.*` / `core.*` | `site.design_kits`, `site.sites`, `site.blocks`, `core.users` | `20260711999999` |
| `content.*` (the curation store — written on every ingest run, read on every public render) | `content.items`, `content.item_media`, `content.offers`, `content.item_tags`, `content.item_variants`, `content.source_items`, `content.media_assets` | `20260828999999` |

**A backfill takes the SESSION-level form, not this one.** §5 forbids running a backfill inside a
migration transaction, and `SET LOCAL` bounds nothing outside one — it is a silent no-op there. So a
bare-statement backfill file bounds itself once at the top instead:

```sql
SET lock_timeout      = '2s';
SET statement_timeout = '10s';

UPDATE content.item_media m SET … WHERE …;
```

**Enforced** by `scripts/guard-no-unsafe-migrations.php` **Check 5**, which accepts either shape
(`BEGIN` + `SET LOCAL` for DDL; session-level `SET` for a DML-only file) and prints whichever remedy
fits the file. This is **forward-only**: the pre-convention files that lack the guards are
grandfathered, not retrofitted — they re-apply against an empty, traffic-free DB at the prod cutover,
where there is no live traffic to contend for the lock (audit `migrations-early/MIG-7`,
`migrations-recent/MIG-8`). The `content.*` group got its own later cutoff for the same reason: it was
added in 2026-08 (audit `#W1-SCALE-1`), long after those tables' migrations had already been applied
to dev. Add the guards to every *new* hot-table migration.

---

## 9. `GENERATED ... STORED` columns on a pre-existing table

`ADD COLUMN ... GENERATED ALWAYS AS (...) STORED` forces a full heap rewrite under
`ACCESS EXCLUSIVE` — the generated value has to be materialised into every existing tuple.
There is no online variant (`VIRTUAL` generated columns don't exist before PostgreSQL 18).
Put it in its own migration file so the lock window covers nothing else, and bound it:

```sql
BEGIN;
SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '60s';
ALTER TABLE commerce.orders ADD COLUMN region_label text
    GENERATED ALWAYS AS (upper(region)) STORED;
COMMIT;
```

**Enforced** by `scripts/guard-no-unsafe-migrations.php` **Check 9** for files timestamped after
`20260726000000`. Exempt when the table is created in the same file. **Canonical example**:
`20260727110004_connections_platform_generated_alias.sql` (audit Unit H / #MIG-4).

---

## 10. Every migration states a reverse path

The Supabase org is on the **Free** plan — no PITR, no managed backups. The weekly
`partna-db-backup` R2 dump is the only backup, so schema RPO is ~7 days
(`docs/deploy/routine-deploy.md` → Rollback). "Roll forward and hope" is the actual recovery
strategy, viable only if the person writing the forward repair at 02:00 can read what the
original file did.

**Safe pattern** — a `-- ROLLBACK:` header line, last in the header comment block, immediately
before the first `BEGIN;`/first executable statement, separated from the prose above by a bare
`--` line:

```sql
-- ROLLBACK: ALTER TABLE site.shop_brands DROP COLUMN IF EXISTS products_curated_at;
```

Multi-clause reverses continuation-indent under the SQL; caveats go on their own `--` line in
parentheses:

```sql
-- ROLLBACK: ALTER TABLE site.platform_connections
--             ALTER COLUMN created_at DROP NOT NULL,
--             ALTER COLUMN updated_at DROP NOT NULL;
```

**Say when there is no reverse.** A note claiming revertibility where none exists is WORSE than no
note — it invites someone to try it during an incident. Write `-- ROLLBACK: NONE.` plus one line of
why:

```sql
-- ROLLBACK: NONE. Hard DELETE of unreachable rows. No undo, no PITR (Supabase
--           Free). Only recovery is the partna-db-backup R2 dump if fresher
--           than the apply.
```

**By operation class:**

| Operation | Reverse |
|---|---|
| Additive DDL (`ADD COLUMN`, `ADD TABLE`) | Exact inverse, `IF EXISTS`-guarded |
| `CREATE INDEX CONCURRENTLY` | `DROP INDEX CONCURRENTLY` in its own one-statement file — §1 applies to the reverse too |
| `ADD CONSTRAINT ... NOT VALID` | `DROP CONSTRAINT IF EXISTS` |
| `VALIDATE CONSTRAINT` | NONE — PostgreSQL has no "un-validate"; name the sibling file whose `DROP CONSTRAINT` is the real reverse |
| `SET NOT NULL` | `DROP NOT NULL` — note that the backfill's values stay |
| `UPDATE` backfill/repair | Usually NONE — no pre-image is recorded, say so |
| `INSERT` backfill | Revertible only if the rows carry a marker this file wrote — quote the exact predicate |
| `DELETE` / `DROP TABLE` | NONE — say what is lost and the real recovery path |
| Whole-schema `CREATE SCHEMA` | The `DROP SCHEMA ... CASCADE` one-liner, **and** an inventory of what CASCADE destroys — distinguish re-derivable projections from locally accumulated state |

**Scope**: every `.sql` in `supabase/migrations/`, including the baseline. `supabase/migrations-archive/`
is explicitly **out of scope** — those files pair a `CONCURRENTLY` statement with other DDL/DML and so
cannot be applied from zero at all (§1); there is no forward apply left for a reverse to undo.

**Do not reproduce a vocabulary `<column> IN (...)` list inside a note.**
`ConstraintVocabularyLockstepTest` regex-matches the first `<column> IN (...)` in raw,
un-comment-stripped SQL. Every other consumer of these files (the guard script, this convention's
own tests) strips `--` comments first — which is also why a note containing DDL text can never trip
a lint — but that one test does not, so a note reproducing a domain list would shadow a real
constraint.

**Enforced** by a new test in `tests/Feature/Database/MigrationTransactionBoundaryTest.php`.
Historical `-- To revert:` (case-insensitive) is accepted as equivalent — nine pre-existing files
already used that spelling before this convention was written.

**Canonical examples**: `20260729140000_shop_brands_products_curated_at.sql` (one-liner),
`20260729150018_pconn_timestamps_validate_and_not_null.sql` (multi-clause),
`20260729120000_purge_orphan_ingest_rows.sql` (honest `NONE`).

---

## Summary cheat sheet

| Operation | Unsafe | Safe |
|-----------|--------|------|
| Add index | `CREATE INDEX` | `CREATE INDEX CONCURRENTLY IF NOT EXISTS` (outside transaction) |
| Add CHECK | `ADD CONSTRAINT CHECK (...)` | `ADD CONSTRAINT ... NOT VALID` → `VALIDATE CONSTRAINT` |
| Set NOT NULL | `ALTER COLUMN SET NOT NULL` | Four-step NOT VALID pattern (see §3) |
| Add FK | `ADD CONSTRAINT FOREIGN KEY` | `ADD CONSTRAINT ... NOT VALID` → `VALIDATE CONSTRAINT` |
| Backfill data | `UPDATE` inside migration transaction | Separate file or dispatched job |
| DDL on a hot table | No timeout, or `SET LOCAL` with no `BEGIN` | `BEGIN;` + `SET LOCAL lock_timeout`/`statement_timeout` + `COMMIT;` (see §8 for the eleven-table list) |
| Backfill on a hot table | `SET LOCAL` outside a transaction (silent no-op) | Session-level `SET lock_timeout`/`statement_timeout` at the top of the file (see §8) |
| Inline column CHECK | `ADD COLUMN col … CHECK (…)` | `ADD COLUMN` then `ADD CONSTRAINT … NOT VALID` → `VALIDATE` (see §2) |
| Inline column FK | `ADD COLUMN col … REFERENCES …` | `ADD COLUMN` then `ADD CONSTRAINT … NOT VALID` → `VALIDATE` (see §4) |
| Drop index (hot table) | `DROP INDEX` | `DROP INDEX CONCURRENTLY IF EXISTS` (own file, no transaction) (see §1) |
| Drop function/trigger | Unqualified `DROP FUNCTION foo` | Schema-qualified `DROP FUNCTION schema.foo()` (see §7) |
| Bundle VALIDATE with ADD | `ADD … NOT VALID; VALIDATE;` one txn | `COMMIT` between them — VALIDATE in its own txn/file (see §2) |
| Add STORED generated column | `ADD COLUMN … GENERATED … STORED` | own file + `BEGIN` + `SET LOCAL lock_timeout`/`statement_timeout` (see §9) |
| Any migration | No stated reverse path | `-- ROLLBACK:` header line — the inverse statement, or NONE + why (see §10) |
