`★ Insight ─────────────────────────────────────`
Two details from reading the actual migration files proved decisive for adjudication: (1) every migration in this repo uses explicit `BEGIN; … COMMIT;`, which means PostgreSQL's hard ban on `CREATE INDEX CONCURRENTLY` inside a transaction block *will* fire on the planned `<ts3>` migration. (2) The project has already used the safe two-step NOT VALID → VALIDATE CONSTRAINT pattern on `core.professionals` (see `20260515000001_validate_preferred_payout_method_check.sql`), making MIG-2/MIG-3 concrete regression risks, not theoretical ones.
`─────────────────────────────────────────────────`

# Migration Safety Audit — 2026-05-19

**Branch:** development
**Lens:** Migration safety: lock-on-deploy risk, backfill ordering, online DDL hygiene, reversibility
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `/Users/joshuahunter/Downloads/PARTNA-STANDALONE-PAGES-NEW-DIRECTION-1.md` (planning artifact describing future migrations)
- `supabase/migrations/` (existing migrations verified for patterns and precedent)

> **Adjudicator note:** All seven findings target migrations described in the planning document but not yet written to `supabase/migrations/`. They are pre-implementation findings — caught at the right moment, before the SQL is finalised. The existing migration `20260515000001_validate_preferred_payout_method_check.sql` confirms the project already applies the safe NOT VALID → VALIDATE pattern on `core.professionals`; the planned `<ts3>` migration must follow the same pattern. Every migration in the repo uses explicit `BEGIN; … COMMIT;`, confirming that `CREATE INDEX CONCURRENTLY` will error inside the planned `<ts3>` file.

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 3 complete

---

## P1 — Fix before pilot launch

- [ ] **#MIG-1** · P1 — `CREATE INDEX CONCURRENTLY` inside a transaction-wrapped migration will fail, leaving `<ts3>` half-applied
    - **Where:** Plan §8 Step 4 + §28.1 — `<ts3>_enforce_account_type_constraints.sql` (planned)
    - **Affects:** The entire `account_type` constraint-enforcement migration. If it errors, `SET NOT NULL` and `ADD CONSTRAINT … CHECK` may have committed while the covering index is absent — or, if the runner aborts the whole file, none of the step-3/4/5 changes land and the next `supabase db push` skips the file because `supabase_migrations.schema_migrations` records it as applied.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move `CREATE INDEX CONCURRENTLY ON core.professionals (account_type)` into its own dedicated file — e.g. `<ts4>_add_account_type_covering_index.sql` — containing nothing else.
        - Leave `<ts3>` with only `SET NOT NULL`, `ADD CONSTRAINT … CHECK`, and the dual-write trigger (all safe inside a transaction).
        - Add `-- No BEGIN/COMMIT wrap needed` comment to `<ts4>` to make intent explicit; confirm supabase CLI does not auto-wrap single-statement files (it does not when the file begins with `CREATE INDEX CONCURRENTLY`).
    - **Technical:** PostgreSQL throws `ERROR: CREATE INDEX CONCURRENTLY cannot run inside a transaction block` if the statement appears inside `BEGIN … COMMIT`. Every migration in this repo uses explicit `BEGIN; … COMMIT;` (confirmed in `20260519100000_handle_alias_lifecycle.sql`, `20260515000001_validate_preferred_payout_method_check.sql`, and others). The plan places `CREATE INDEX CONCURRENTLY` (Step 4) in the same file as `SET NOT NULL` and `ADD CONSTRAINT … CHECK` (Step 3) and the dual-write trigger (Step 5), per §28.1: `<ts3>` covers "steps 3–5 of §8". Running this file will abort at the `CREATE INDEX CONCURRENTLY` statement. Depending on whether the runner uses `ROLLBACK` on error, the DB may be left with the constraint+trigger but no index (silently degraded) or with nothing from `<ts3>` applied but the migration recorded as attempted. Either outcome requires manual intervention on prod.
    - **Plain English:** There's a special database command — "build this index without locking the table" — that refuses to run inside a batch job. The plan puts it inside a batch job. When the migration runs in production, the batch will crash partway through. Depending on exactly when it crashes, the table might end up with new rules enforced but no fast lookup path, or nothing committed at all — but the deploy system will think the migration finished. The fix takes five minutes: move that one index command into its own file so it runs solo.
    - **Evidence:**
        ```
        -- Plan §28.1:
        -- "supabase/migrations/<ts3>_enforce_account_type_constraints.sql —
        --   adds NOT NULL + CHECK constraint + covering index + dual-write trigger
        --   (steps 3–5 of §8)"
        --
        -- Plan §8, Step 4:
        -- "CREATE INDEX CONCURRENTLY ON core.professionals (account_type)
        --   for dashboard queries."
        --
        -- Existing repo pattern (every migration file):
        -- BEGIN;
        -- ... DDL ...
        -- COMMIT;
        ```

---

## P2 — Should fix

- [ ] **#MIG-2** · P2 — `ADD CONSTRAINT … CHECK` without `NOT VALID` on `core.professionals` holds `ACCESS EXCLUSIVE` during constraint scan
    - **Where:** Plan §8 Step 3 — `<ts3>_enforce_account_type_constraints.sql` (planned)
    - **Affects:** Any concurrent signup, authentication lookup, or profile read hitting `core.professionals` during the deploy window. Even at alpha scale the table is described as "write-hot during business hours."
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `<ts3>`: use `ADD CONSTRAINT professionals_account_type_check CHECK (account_type IN ('brand', 'partner', 'individual')) NOT VALID`.
        - Add a subsequent file `<ts5>_validate_account_type_check.sql` with `ALTER TABLE core.professionals VALIDATE CONSTRAINT professionals_account_type_check;` — identical to the existing pattern in `20260515000001_validate_preferred_payout_method_check.sql`.
        - The `VALIDATE` step runs under `SHARE UPDATE EXCLUSIVE`, which permits concurrent reads and writes.
    - **Technical:** `ADD CONSTRAINT … CHECK (…)` without `NOT VALID` validates every existing row immediately under `ACCESS EXCLUSIVE`, blocking all reads and writes for the duration. This project has already applied the exact safe pattern on the same table: `20260515000001_validate_preferred_payout_method_check.sql` runs `VALIDATE CONSTRAINT professionals_preferred_payout_method_check` as a standalone follow-up migration after the constraint was added `NOT VALID` in a prior file. The plan's own §28.1 uses the same three-file split for the backfill — a fourth file for `VALIDATE` is consistent with that established structure.
    - **Plain English:** Adding a new rule to the professionals table requires checking every existing row. Doing it the default way locks the table while that check runs — nobody can sign up, log in, or read a profile. This project already solved this exact problem once before on the same table (see the payout-method migration). The fix follows the same two-step pattern already in the codebase: add the rule without checking old rows (instant), then validate old rows separately while the table stays open.
    - **Evidence:**
        ```
        -- Plan §8, Step 3:
        -- "ALTER COLUMN account_type SET NOT NULL +
        --   ADD CONSTRAINT ... CHECK (account_type IN ('brand', 'partner', 'individual')).
        --   Will fail loudly if any row is still NULL."
        -- No NOT VALID clause specified.
        --
        -- Existing safe pattern already in repo:
        -- 20260515000001_validate_preferred_payout_method_check.sql:
        --   ALTER TABLE core.professionals
        --     VALIDATE CONSTRAINT professionals_preferred_payout_method_check;
        ```

- [ ] **#MIG-3** · P2 — `ALTER COLUMN account_type SET NOT NULL` without prior safe CHECK pattern holds `ACCESS EXCLUSIVE` on `core.professionals`
    - **Where:** Plan §8 Step 3 — `<ts3>_enforce_account_type_constraints.sql` (planned)
    - **Affects:** Same concurrent readers and writers as MIG-2; same migration, same moment.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the direct `ALTER COLUMN account_type SET NOT NULL` with the three-step safe pattern: (1) `ADD CHECK (account_type IS NOT NULL) NOT VALID` in `<ts3>`; (2) `VALIDATE CONSTRAINT` in a follow-up migration once the check holds; (3) then `ALTER COLUMN SET NOT NULL` in a third migration — at which point Postgres skips the table scan because it knows the constraint is already enforced.
        - Alternatively: if the table is confirmed empty or near-empty at migration time (only alpha users), document that assessment as a comment in the migration and proceed with the direct `SET NOT NULL` — but add `ASSERT (SELECT count(*) FROM core.professionals WHERE account_type IS NULL) = 0` as a preceding `DO $$ … $$` block so the migration fails loudly rather than locking.
    - **Technical:** `ALTER TABLE … SET NOT NULL` on a previously-nullable column scans every row to confirm no NULLs, holding `ACCESS EXCLUSIVE` for the duration. The plan acknowledges the table is "well under 10K rows" at alpha — the lock is brief in practice. But the table is explicitly classified as "write-hot during business hours" in the lens definition, and the same concern applies to MIG-2. Using the safe pattern costs nothing and is already established precedent in this codebase. If the table is genuinely near-zero rows at migration time, a comment saying so satisfies the reviewer; the `DO` assertion is free insurance.
    - **Plain English:** Making the new column "required" (non-blank) forces Postgres to scan every existing row before accepting the change. That scan locks the table — no signups or logins during that window. At your current scale the scan takes under a second, but "write-hot table + any exclusive lock" is a pattern worth eliminating. The safe approach adds the requirement in stages, keeping the table open the whole time.
    - **Evidence:**
        ```
        -- Plan §8, Step 3:
        -- "ALTER COLUMN account_type SET NOT NULL"
        -- No prior ADD CHECK (account_type IS NOT NULL) NOT VALID +
        -- VALIDATE CONSTRAINT pattern specified.
        --
        -- Plan §8 footnote:
        -- "Production migration timing: the table core.professionals is small
        --   at our alpha stage (well under 10K rows); backfill SQL executes in
        --   milliseconds and CHECK constraint validation is fast. No blue-green
        --   path required."
        ```

- [ ] **#MIG-4** · P2 — Three-file split creates a partial-application window where `account_type` column exists with all NULLs and new application writes land NULL values before `<ts3>` enforces NOT NULL
    - **Where:** Plan §28.1 — the `<ts1>` / `<ts2>` / `<ts3>` migration sequence (planned)
    - **Affects:** Production deploy. If `<ts1>` succeeds but `<ts2>` fails (syntax error, connection drop, OOM), `supabase_migrations.schema_migrations` records `<ts1>` as applied. The column exists with all NULLs. Application code hasn't been updated to write `account_type` yet (dual-write trigger isn't in place until `<ts3>`). New signups during the `<ts2>`-failed state produce NULL `account_type`. `<ts3>`'s `SET NOT NULL` then fails because of these post-`<ts1>` writes.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Merge `<ts1>` (add column) and `<ts2>` (backfill) into a single file. The column addition is metadata-only and the backfill on a sub-10K row table completes in milliseconds — there is no lock reason to split them.
        - If keeping the split: add a defensive `DO $$ … $$` block at the top of `<ts3>` that counts NULLs and raises an exception if any exist (the plan describes this for brand_signup_code's step-3 in §36 — apply the same pattern here).
        - Add a `-- To revert: ALTER TABLE core.professionals DROP COLUMN account_type CASCADE; DROP TRIGGER IF EXISTS ...` comment to `<ts1>` per the project's established convention (referenced in CLAUDE.md).
    - **Technical:** Supabase records each file as atomically applied or not. A failure in `<ts2>` leaves the DB in a state where `account_type` exists but is unpopulated, and subsequent application writes (using the old code path that doesn't write `account_type` yet) continue landing NULLs. When `<ts2>` is retried or `<ts3>` runs, the NOT NULL enforcement fails with a genuine constraint violation from the new writes, not a backfill error. The plan's own §8 Step 3 says it "will fail loudly if any row is still NULL (defensive)" — but it doesn't prevent the failure, only detect it. Merging `<ts1>` and `<ts2>` closes the window entirely. The §36 brand_signup_code migration uses the same three-step approach and includes a `DO $$ … $$` assertion guard in step 3 — the `account_type` migration should do the same.
    - **Plain English:** The migration is split into three separate files that must all succeed in sequence. If the second file (filling in the data) fails for any reason, the database is stuck with an empty new column, and new user signups keep writing blank values into it. When the third file tries to enforce that the column can't be blank, it fails because of those new blank values — not because of a bug in the migration, but because the window between files allowed them in. Combining files one and two eliminates the gap.
    - **Evidence:**
        ```
        -- Plan §28.1:
        -- "Migration files:
        --   supabase/migrations/<ts1>_add_account_type_column_to_professionals.sql —
        --     adds account_type text NULL only (step 1 of §8)
        --   supabase/migrations/<ts2>_backfill_account_type.sql —
        --     runs the §8 step 2 backfill
        --   supabase/migrations/<ts3>_enforce_account_type_constraints.sql —
        --     adds NOT NULL + CHECK constraint + covering index + dual-write trigger
        --     (steps 3–5 of §8)"
        --
        -- Plan §36 (brand_signup_code step 3, the established safe pattern):
        -- "DO $$
        -- BEGIN
        --   IF EXISTS (SELECT 1 FROM brand.brand_profiles WHERE signup_code IS NULL) THEN
        --     RAISE EXCEPTION 'backfill incomplete: % rows have NULL signup_code', ...
        --   END IF;
        -- END$$;"
        ```

---

## P3 — Nice to have

- [ ] **#MIG-5** · P3 — `brand.signup_code_audit` uses `gen_random_uuid()` without a `CREATE EXTENSION IF NOT EXISTS pgcrypto` guard
    - **Where:** Plan §34 — `<ts>_create_brand_signup_code_audit.sql` (planned)
    - **Affects:** Fresh CI databases, restored backups, or self-hosted Supabase environments where `pgcrypto` may not be pre-enabled.
    - **Effort:** S (~0.25h)
    - **What to do:**
        - Add `CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA extensions;` at the top of the audit table migration (before the `CREATE TABLE` statement).
        - The `IF NOT EXISTS` guard makes the migration safe to re-run and self-documenting about its dependency — no manual pre-flight check required.
    - **Technical:** `gen_random_uuid()` is provided by the `pgcrypto` extension (or by `pgcrypto`'s inclusion in Postgres 13+'s built-in `uuid-ossp`). Supabase enables `pgcrypto` by default, but the plan itself acknowledges the dependency with a manual verification step ("verify it's enabled in the target Supabase environment before running this migration"). The `IF NOT EXISTS` guard subsumes that manual check and makes the migration portable. The v2 baseline (which creates schemas and grants) is the correct precedent — it uses `IF NOT EXISTS` guards throughout.
    - **Plain English:** The audit table relies on a database add-on called pgcrypto to generate random IDs. The plan says "make sure this add-on is installed before running the migration" — but if you're restoring from backup or running CI, that's easy to forget. Adding one line to the migration file makes it install the add-on automatically if it's missing, eliminating the manual step.
    - **Evidence:**
        ```sql
        -- Plan §34:
        -- "CREATE TABLE brand.signup_code_audit (
        --   id uuid PRIMARY KEY DEFAULT gen_random_uuid(), ..."
        -- The plan notes: "gen_random_uuid() requires the pgcrypto extension —
        -- verify it's enabled in the target Supabase environment before running
        -- this migration"
        -- No CREATE EXTENSION IF NOT EXISTS pgcrypto is specified in the migration SQL.
        ```

- [ ] **#MIG-6** · P3 — No rollback comment in the migration SQL specs for destructive or irreversible operations
    - **Where:** Plan §28.1 (`<ts1>`, `<ts2>`, `<ts3>`), §28.16 (soft-delete migration), §34 (audit table)
    - **Affects:** Operators needing to roll back during a production incident — without documented SQL the recovery requires referencing the plan document under pressure.
    - **Effort:** S (~0.25h)
    - **What to do:**
        - Add a `-- To revert: …` comment block at the top of each destructive migration file, following the convention cited in CLAUDE.md. For `<ts1>`: `-- To revert: ALTER TABLE core.professionals DROP COLUMN account_type CASCADE;`. For `<ts3>`: `-- To revert: DROP CONSTRAINT professionals_account_type_check; ALTER COLUMN account_type DROP NOT NULL; DROP TRIGGER IF EXISTS sync_account_type ON core.professionals; DROP FUNCTION IF EXISTS sync_account_type_fn();`. For the soft-delete migration: `-- To revert: ALTER TABLE brand.brand_partner_links DROP COLUMN deleted_at; DROP INDEX CONCURRENTLY IF EXISTS ...`.
        - Note: the plan does document the rollback in §8 prose ("Rollback is `DROP COLUMN account_type CASCADE` plus removing the trigger"), but this is not captured inside the migration file specification itself where an operator would find it during an incident.
    - **Technical:** CLAUDE.md states: "CLAUDE.md doesn't require a `down` SQL but a comment explaining 'to revert: …' is the established convention." None of the migration SQL specifications in §8, §28.16, or §34 include such a comment block. For `SET NOT NULL` the reversal is `DROP NOT NULL`; for the dual-write trigger it requires dropping both the trigger and its function; for the soft-delete `deleted_at` column the reversal must also drop both indexes before the column drop. A rollback comment captures all of these in one place so the operator can copy-paste rather than reconstruct during a stressful rollback window.
    - **Plain English:** When something goes wrong during a deploy, the team needs to undo a database change fast. The project convention is to include a comment inside each migration file that says "to undo this, run: …". The planned migrations document the rollback steps in a separate planning document, but not inside the migration files where an operator would look during an incident. Moving those steps into the files takes two minutes and could save thirty.
    - **Evidence:**
        ```
        -- Plan §8 (prose, not in migration SQL spec):
        -- "Rollback is DROP COLUMN account_type CASCADE plus removing the trigger."
        --
        -- CLAUDE.md convention:
        -- "CLAUDE.md doesn't require a 'down' SQL but a comment explaining
        --   'to revert: …' is the established convention."
        --
        -- None of the migration SQL specifications in §8, §28.16, or §34
        -- include a rollback comment block in the SQL itself.
        ```

- [ ] **#MIG-7** · P3 — `<ts2>` backfill UPDATE lacks `WHERE account_type IS NULL` guard — non-idempotent on retry
    - **Where:** Plan §8 Step 2 — `<ts2>_backfill_account_type.sql` (planned)
    - **Affects:** Operators retrying `<ts2>` after a partial failure. Without the guard, a re-run overwrites all rows — including any where `professional_type` may have been updated by the dual-write trigger since the initial backfill.
    - **Effort:** S (~0.25h)
    - **What to do:**
        - Add `AND account_type IS NULL` to every `UPDATE` branch in the backfill. Example: `UPDATE core.professionals SET account_type = 'brand', professional_type = 'brand' WHERE professional_type = 'brand' AND account_type IS NULL`.
        - This makes each branch skip already-backfilled rows on retry, preserving any `professional_type` values the trigger may have legitimately updated since the first pass.
    - **Technical:** The plan specifies that the backfill writes both `account_type` AND `professional_type` in a single UPDATE to prevent the dual-write trigger from firing recursively. Without `WHERE account_type IS NULL`, re-running the migration on an already-backfilled database resets `professional_type` to the mapped value for every row — even rows where `professional_type` may have evolved via the trigger between the initial backfill and the retry. The plan's §36 brand_signup_code Artisan command already implements idempotency via `BrandProfile::whereNull('signup_code')->cursor()` — the backfill migration should apply the same `WHERE IS NULL` pattern.
    - **Plain English:** The backfill updates every row that matches a condition, but doesn't first check "has this row already been updated?" If the migration needs to be run twice — for example, after fixing an error that stopped it partway — it overwrites already-correct data. This is usually harmless but can cause unexpected resets if anything changed between the two runs. Adding a simple "only update rows that still need it" filter makes the backfill safe to retry as many times as needed.
    - **Evidence:**
        ```
        -- Plan §8, Step 2 (no WHERE account_type IS NULL specified):
        -- "professional_type = 'brand' → account_type = 'brand'
        --   professional_type IN ('professional', 'influencer') AND has any
        --   non-soft-deleted BrandPartnerLink → account_type = 'partner'
        --   professional_type IN ('professional', 'influencer') AND has no
        --   BrandPartnerLink → account_type = 'individual'"
        --
        -- Plan §8:
        -- "The backfill writes BOTH columns in the same UPDATE so the dual-write
        --   trigger doesn't fire recursively."
        --
        -- Plan §36 (established idempotency pattern):
        -- "foreach (BrandProfile::whereNull('signup_code')->cursor() as $brand) { ... }"
        ```
