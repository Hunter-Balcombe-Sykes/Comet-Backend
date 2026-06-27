Good — verified from Glob output that two files share the `20260530000000` timestamp prefix (`_drop_workplace_hours` and `_grant_moderation_schema_to_app_backend`), confirming the compound MIGR-6 finding. `loadDesignKit()` returns `[]` for sites with no kit row (null-safe), so the deliberate backfill deferral in `20260527070000` is not a bug. All other evidence is confirmed from source files provided.

`★ Insight ─────────────────────────────────────`
- DeepSeek consistently over-tiers migration locking issues at pre-beta scale — lock duration on empty tables is measured in microseconds, making P0/P1 lock findings always P2 or lower when there are no real users yet.
- The `NOT VALID` + separate `VALIDATE` two-transaction pattern used correctly throughout this codebase (e.g. `20260526010000`, `20260527040000`) is exactly right: the ADD step holds `ACCESS EXCLUSIVE` only for catalog writes, while VALIDATE holds only `SHARE UPDATE EXCLUSIVE` (concurrent writes allowed). Migrations that skip this pattern on non-empty tables are real P2 concerns.
- `CREATE INDEX CONCURRENTLY` cannot run inside `BEGIN/COMMIT` — this is why the convention of splitting DDL + index files exists. The enquiry inbox migration's `guard:no-unsafe-migrations:disable-file` is an acknowledged bypass of that rule, which is fine once but creates a category of "environment-specific safe" migrations that become unsafe when promoted.
`─────────────────────────────────────────────────`

# Migration Safety Audit — 2026-05-31

**Branch:** development
**Lens:** Migration safety, lock-on-deploy risk, non-CONCURRENTLY index builds, backfill ordering, baseline/incremental drift, CHECK constraints rejecting valid inputs, missing hot-path indexes for 10k-row tables
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- supabase/migrations/20260526000000_baseline_standalone_user.sql
- supabase/migrations/20260526200001_accounttype_check_constraint.sql
- supabase/migrations/20260526200002_validate_accounttype_check.sql
- supabase/migrations/20260527070000_skeleton_system_cleanup.sql
- supabase/migrations/20260527160000_enquiry_inbox.sql
- supabase/migrations/20260528000000_create_moderation_schema.sql
- supabase/migrations/20260529200000_remove_csam_pipeline_tables.sql
- supabase/migrations/20260530000000_drop_workplace_hours.sql
- supabase/migrations/20260530000000_grant_moderation_schema_to_app_backend.sql
- supabase/migrations/20260530000050_grant_moderation_schema_to_app_backend.sql
- supabase/migrations/20260530000400_add_csam_quarantine_case_id_idx.sql
- supabase/migrations/20260530010000_add_visitor_confirmation_sent_at.sql
- supabase/config.toml

## Progress

- P0 Blockers: 0 of 1 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 4 complete

---

## P0 — Must fix before any real user touches the system

- [ ] **#MIGR-1** · P0 — Index creation targets a table dropped by a prior migration — `supabase db push` halts with a fatal error
    - **Where:** supabase/migrations/20260530000400_add_csam_quarantine_case_id_idx.sql:1–2 vs supabase/migrations/20260529200000_remove_csam_pipeline_tables.sql:6
    - **Affects:** Every deploy that runs the full migration pipeline on a fresh or reset database. The `CREATE INDEX` raises PostgreSQL error `42P01` (undefined relation) and halts the push. Nothing after this file in the sequence is applied; the database is left in a partially-migrated state until the file is removed.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Delete `supabase/migrations/20260530000400_add_csam_quarantine_case_id_idx.sql` entirely. The target table `moderation.csam_quarantine` was removed in `20260529200000_remove_csam_pipeline_tables.sql`; the index has no object to live on.
        - If CSAM quarantine is reintroduced later (per memory note `project_csam_pipeline_deferred.md`), create the index in the same migration file that recreates the table, or in a proper CONCURRENTLY sibling file immediately after.
        - Confirm the file is absent from both the dev and production migration history before the next `supabase db push` to production.
    - **Technical:** Supabase applies migrations in lexicographic filename order. `20260529200000` (May 29) drops `moderation.csam_quarantine` with `DROP TABLE IF EXISTS`. `20260530000400` (May 30) then attempts `CREATE INDEX CONCURRENTLY IF NOT EXISTS csam_quarantine_case_id_idx ON moderation.csam_quarantine (case_id)`. The `IF NOT EXISTS` guard is a no-op on a missing table — it only suppresses "index already exists", not "relation does not exist". PostgreSQL raises `ERROR 42P01: relation "moderation.csam_quarantine" does not exist` before any row scan begins. On the development Supabase project, this migration was likely never applied cleanly to a fresh DB (the dev stack connects as postgres superuser and may have been in a partial state). It will fail on any fresh `supabase db reset` or on the first production push.
    - **Plain English:** Someone deleted a room from the floor plan, then a separate work order was filed to install shelving in that same room. The shelf installer shows up on moving day, can't find the room, and the entire move grinds to a halt — nothing else can be unpacked until the work order is cancelled.
    - **Evidence:**
        ```sql
        -- 20260529200000_remove_csam_pipeline_tables.sql:6
        DROP TABLE IF EXISTS moderation.csam_quarantine;
        ```
        ```sql
        -- 20260530000400_add_csam_quarantine_case_id_idx.sql:1-2
        CREATE INDEX CONCURRENTLY IF NOT EXISTS csam_quarantine_case_id_idx
            ON moderation.csam_quarantine (case_id);
        ```

---

## P2 — Should fix

- [ ] **#MIGR-2** · P2 — `CREATE INDEX` inside `BEGIN/COMMIT` in the enquiry inbox migration blocks writes for the full index build duration in any environment that already has rows
    - **Where:** supabase/migrations/20260527160000_enquiry_inbox.sql:1–4, 76–84
    - **Affects:** Any environment where `site.enquiries` or `site.customers` has rows at migration time — staging with a production data snapshot, or a future production re-deploy from scratch after real users have submitted enquiries. `CREATE INDEX` without `CONCURRENTLY` acquires `ACCESS EXCLUSIVE` for the full build duration, blocking all concurrent reads and writes on those tables.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Move the three `CREATE INDEX` statements (lines 76–84) out of the `BEGIN/COMMIT` block into a sibling file named `20260527160001_enquiry_inbox_indexes.sql` with no transaction wrapper.
        - Add `CONCURRENTLY` to each index in the sibling file.
        - Leave the FK constraints, column additions, backfill UPDATE, and enum creation inside the original `BEGIN/COMMIT` — those operations legitimately require a transaction.
        - Update the file header comment to reference the sibling file instead of the current inline justification.
    - **Technical:** The migration disables the unsafe-migrations lint guard with the rationale that `site.enquiries` was empty at migration time (pre-beta). This is currently correct, but the guard bypass is a file-level flag that survives in git history and will apply unchanged when this migration runs against any database that is not empty — e.g., a staging environment restored from a production backup after pilot launch, or a `supabase db reset` after real enquiries exist. `CREATE INDEX` (without CONCURRENTLY) acquires `ACCESS EXCLUSIVE` for the duration of the index build, which serialises all reads and writes. `CONCURRENTLY` is incompatible with explicit transactions (`BEGIN/COMMIT`), which is why the convention (`CONVENTIONS.md §1`) is to split them into a sibling file. The FK constraints and the DML backfill (`UPDATE site.enquiries SET status = 'read' WHERE read_at IS NOT NULL AND status = 'new'`) must remain inside a transaction for atomicity; only the pure `CREATE INDEX` statements need to move.
    - **Plain English:** The migration file installs three new locks on a heavy door while keeping the door bolted shut during installation. If nobody is home (empty table), the job is over in a blink. But if the store has 50,000 enquiries — say, after importing a production snapshot into staging — every customer trying to open that door while the installer is working has to wait outside. Splitting the lock installation into a separate, non-blocking job lets normal traffic continue uninterrupted.
    - **Evidence:**
        ```sql
        -- 20260527160000_enquiry_inbox.sql:1-4
        -- guard:no-unsafe-migrations:disable-file
        -- Rationale: indexes and FKs run inside BEGIN…COMMIT (required for idempotent
        -- column+FK+index steps). CONCURRENTLY is incompatible with explicit transactions.
        -- All affected tables were empty at migration time (pre-beta). Safe to skip lint.
        ```
        ```sql
        -- 20260527160000_enquiry_inbox.sql:76-84
        CREATE INDEX IF NOT EXISTS idx_enquiries_user_status_created
            ON site.enquiries (user_id, status, created_at DESC)
            WHERE deleted_at IS NULL;
        ```

---

## P3 — Nice to have

- [ ] **#MIGR-3** · P3 — Skeleton system cleanup migration has no transaction wrapper — partial failure leaves the schema in an unrecoverable split state
    - **Where:** supabase/migrations/20260527070000_skeleton_system_cleanup.sql:1–end (no `BEGIN;`/`COMMIT;`)
    - **Affects:** Any developer running `supabase db reset` or a fresh DB push on a future branch where this migration could fail mid-way (e.g., a PostgreSQL version constraint, a permissions issue, or a view syntax error during the recreate step).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the entire file in a single `BEGIN;` / `COMMIT;`. None of the statements in this file use `CONCURRENTLY`, so there is no technical barrier to doing so.
        - Note this for the pattern library: any migration that drops + recreates multiple dependent objects (views, triggers, functions) should be a single transaction so a failure rolls back to a clean starting point rather than leaving half the objects in their old state and half in their new state.
    - **Technical:** Without an explicit `BEGIN`, each DDL and DML statement in this file is auto-committed independently. The file contains at least 11 distinct statements: two `DROP VIEW`, one `ALTER TABLE` (drop/add column), one `DROP FUNCTION`, one `DROP TABLE`, one `UPDATE`, one `CREATE TABLE`, one `CREATE FUNCTION`, one `CREATE TRIGGER`, and two `CREATE VIEW` (plus two grant `DO` blocks). If the final `CREATE VIEW site.public_site_payload` failed — say, due to a transient type resolution issue — the preceding steps (dropped views, altered table, dropped themes table, design_kits created, trigger active) would already be durably committed. The database would be missing `site.public_site_payload`, which is the primary view used to serve public site pages, with no clean rollback path. This migration has already run on `glncumufgaqcmqhzwrxm` (dev) and will run on production against empty tables, making the current practical risk very low — but the pattern should be corrected before it is copied.
    - **Plain English:** This is a renovation that tears down walls, reroutes plumbing, and hangs new drywall — each step is permanent the moment it's done. If the electrician trips a breaker halfway through and the crew has to stop, you're left with half the old layout and half the new one, with no easy way to restore either. Wrapping the whole job in a single contract ("if anything goes wrong, undo everything") takes one line of SQL and eliminates that risk.
    - **Evidence:**
        ```sql
        -- 20260527070000_skeleton_system_cleanup.sql — no BEGIN/COMMIT wrapper
        DROP VIEW IF EXISTS site.all_site_data;
        DROP VIEW IF EXISTS site.public_site_payload;

        ALTER TABLE site.sites
          DROP COLUMN theme_id,
          ADD COLUMN skeleton_id TEXT NOT NULL
            DEFAULT 'skeleton-1'
            CHECK (skeleton_id IN ('skeleton-1','skeleton-2','skeleton-3','skeleton-4'));
        -- ... 8 more auto-committed statements follow
        ```

- [ ] **#MIGR-4** · P3 — Redundant `account_type` CHECK constraint added after the baseline already defines an identical one
    - **Where:** supabase/migrations/20260526200001_accounttype_check_constraint.sql:11 vs supabase/migrations/20260526000000_baseline_standalone_user.sql (`users_account_type_check`)
    - **Affects:** Schema clarity and developer comprehension. No runtime harm — PostgreSQL accepts multiple semantically identical CHECK constraints on the same column. Both constraints are evaluated on every write; at one row per user, the cost is negligible.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Delete `20260526200001_accounttype_check_constraint.sql` and `20260526200002_validate_accounttype_check.sql` from the migration history. The baseline at `20260526000000` already defines `CONSTRAINT users_account_type_check CHECK (account_type = 'individual')`, so both incremental files are no-ops on any database that has the current baseline.
        - Alternatively, add a comment to both files noting they are intentionally no-ops on the current baseline (kept for environments running a pre-consolidation baseline that lacks the constraint).
    - **Technical:** The baseline `20260526000000_baseline_standalone_user.sql` defines `CONSTRAINT users_account_type_check CHECK (account_type = 'individual')` inline on `core.users`. Migration `20260526200001` adds `CONSTRAINT users_account_type_individual CHECK (account_type = 'individual') NOT VALID` — a second, differently named constraint with the same expression. `20260526200002` validates it. PostgreSQL does not deduplicate CHECK constraints by expression; it accepts both. The incremental migration pair was authored when the constraint was not yet in the baseline, then the baseline was updated to include it, but the incremental files were never cleaned up. Both constraints exist in the live schema on dev.
    - **Plain English:** The safety rulebook was updated to require a fire extinguisher in every room. The main blueprint was revised to include it — then a separate work order arrived and installed a second identical fire extinguisher right next to the first. Both work perfectly. The second one was just unnecessary paperwork.
    - **Evidence:**
        ```sql
        -- baseline (20260526000000_baseline_standalone_user.sql)
        CONSTRAINT users_account_type_check CHECK (account_type = 'individual')
        ```
        ```sql
        -- 20260526200001_accounttype_check_constraint.sql:11
        ALTER TABLE core.users
            ADD CONSTRAINT users_account_type_individual
            CHECK (account_type = 'individual') NOT VALID;
        ```

- [ ] **#MIGR-5** · P3 — No partial index on `confirmation_sent_at IS NULL` for the idempotency guard queries in the confirmation-send jobs
    - **Where:** supabase/migrations/20260530010000_add_visitor_confirmation_sent_at.sql:7–10
    - **Affects:** `SendEnquiryConfirmationJob` and `SendSubscriptionConfirmationJob` (and any future workers that query `WHERE confirmation_sent_at IS NULL` to avoid double-sending). Without a partial index, each job tick performs a sequential scan of `site.enquiries` and `notifications.email_subscriptions` to find un-confirmed rows. At current pre-pilot scale the cost is negligible; at 500k+ rows with a 60-second job cadence it becomes a measurable recurring load.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a migration file (no `BEGIN/COMMIT`, use `CONCURRENTLY`) containing:
            ```sql
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_enquiries_confirmation_unsent
                ON site.enquiries (created_at)
                WHERE confirmation_sent_at IS NULL AND deleted_at IS NULL;

            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_email_subscriptions_confirmation_unsent
                ON notifications.email_subscriptions (created_at)
                WHERE confirmation_sent_at IS NULL;
            ```
        - The partial predicate on `IS NULL` keeps the index tiny — rows leave it the moment the column is stamped — making it effectively free to maintain.
        - Put this on the runway checklist for before the first marketing campaign that triggers bulk confirmation sends, not necessarily before pilot launch.
    - **Technical:** `confirmation_sent_at` is the idempotency sentinel introduced in `20260530010000`. The column is `NULL` on every new row and is stamped exactly once after the confirmation email is delivered. Jobs that guard on `WHERE confirmation_sent_at IS NULL` need to find a typically-small set of rows inside a potentially large table. Without an index, PostgreSQL performs a sequential scan proportional to total row count on every invocation. A partial index on `IS NULL` matches exactly the rows the job cares about and, because rows move out of it on stamp, stays orders of magnitude smaller than a full index. This is a runway item, not a pre-launch blocker.
    - **Plain English:** The job worker checks the entire filing cabinet for unstamped envelopes every minute. With a few dozen files, fine. With hundreds of thousands, the worker spends most of their time flipping through files that are already stamped. Adding a dedicated "unstamped" tray (a partial index) means the worker only checks one small pile and immediately knows what needs attention.
    - **Evidence:**
        ```sql
        -- 20260530010000_add_visitor_confirmation_sent_at.sql:7-10
        ALTER TABLE site.enquiries
            ADD COLUMN IF NOT EXISTS confirmation_sent_at timestamptz NULL;
        ALTER TABLE notifications.email_subscriptions
            ADD COLUMN IF NOT EXISTS confirmation_sent_at timestamptz NULL;
        ```

- [ ] **#MIGR-6** · P3 — Two distinct timestamp-collision issues in the `20260530000000` migration block: a content-duplicate file and a shared timestamp prefix
    - **Where:** supabase/migrations/20260530000000_grant_moderation_schema_to_app_backend.sql and supabase/migrations/20260530000050_grant_moderation_schema_to_app_backend.sql (identical content); supabase/migrations/20260530000000_drop_workplace_hours.sql and supabase/migrations/20260530000000_grant_moderation_schema_to_app_backend.sql (same timestamp prefix, different content)
    - **Affects:** Developers reading migration history; tooling that does `supabase db diff` or archives migrations by timestamp; anyone auditing the schema trail. At runtime both issues are harmless: Supabase tracks migrations by full filename (not just the timestamp prefix), so all three files run independently; the GRANT statements are idempotent, so the duplicate content file is a no-op on the second execution.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Delete `20260530000050_grant_moderation_schema_to_app_backend.sql` — it is byte-for-byte identical to `20260530000000_grant_moderation_schema_to_app_backend.sql`.
        - Rename `20260530000000_grant_moderation_schema_to_app_backend.sql` to `20260530000001_grant_moderation_schema_to_app_backend.sql` to eliminate the shared timestamp prefix with `20260530000000_drop_workplace_hours.sql`. Both names currently map to the same version key prefix (`20260530000000`), which can confuse schema diff tools and is a latent source of ordering ambiguity.
        - Add a lint step (e.g., a CI script) that fails if two migration files share the same 14-digit timestamp prefix.
    - **Technical:** Supabase records each migration by the full filename (minus `.sql` extension) in `supabase_migrations.schema_migrations`. This means `20260530000000_drop_workplace_hours` and `20260530000000_grant_moderation_schema_to_app_backend` are stored as distinct versions and both run, ordered lexicographically (`d` < `g`). No data corruption occurs. The content-duplicate (`_000000` vs `_000050`) is similarly a no-op because all GRANT and ALTER DEFAULT PRIVILEGES statements are idempotent. The practical harm is code-review confusion, broken assumptions in `supabase db diff`, and difficulty reconstructing the intended deploy sequence from the git log — both issues are classic rebase/hotfix artifacts that ended up on the same branch without cleanup.
    - **Plain English:** Two versions of the same memo were printed and filed — one dated 9:00 AM and one dated 9:00:50 AM, word for word identical. And separately, two completely different memos were both dated 9:00 AM. The filing system handles it fine, but anyone flipping through the cabinet later will be confused about which version was intentional and whether both 9:00 memos were supposed to run in a specific order.
    - **Evidence:**
        ```sql
        -- 20260530000000_grant_moderation_schema_to_app_backend.sql (first line of comment block)
        -- F1 (P0): the moderation schema (created in 20260528000000) and its tables
        -- (20260528*, 20260529*) were never granted to app_backend.
        ```
        ```sql
        -- 20260530000050_grant_moderation_schema_to_app_backend.sql (identical first line)
        -- F1 (P0): the moderation schema (created in 20260528000000) and its tables
        -- (20260528*, 20260529*) were never granted to app_backend.
        ```
