- [ ] **MIG-1** · P0 — CREATE UNIQUE INDEX without CONCURRENTLY on hot table `site.sites`
    - **Where:** supabase/migrations/20260612140000_site_custom_domain.sql (the `CREATE UNIQUE INDEX` statement)
    - **Affects:** All writes and reads on `site.sites` during the index build; site creation, domain verification, and public sitepage resolution stall.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `CREATE UNIQUE INDEX IF NOT EXISTS` with `CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS` and ensure it is not wrapped in a transaction (the file has no `BEGIN`/`COMMIT`, so it’s already outside).
        - Add a comment noting that `CONCURRENTLY` is required because `site.sites` receives live traffic.
    - **Technical:** Creating a unique index on a populated table without `CONCURRENTLY` acquires an `ACCESS EXCLUSIVE` lock, blocking all reads and writes for the full duration of the index build. `site.sites` is a high-traffic table (every public sitepage read, every Cloudflare subdomain resolution, every dashboard write). On a production database with thousands of sites the lock would last seconds to minutes, enough to trigger a visible outage. The canonical fix is `CREATE UNIQUE INDEX CONCURRENTLY`, which builds the index with only a brief metadata lock followed by a slower scan under weaker locks that allow concurrent writes (per `CONVENTIONS.md §1`).
    - **Plain English:** Imagine a library where every visitor and every book checkout stops the moment a librarian starts reorganising the entire card catalogue. That’s what this index creation does to the site lookup system. The fix is to do the reorganisation after hours, one card at a time, so the library keeps running.
    - **Evidence:**
        ```sql
        CREATE UNIQUE INDEX IF NOT EXISTS sites_custom_domain_unique
            ON site.sites (lower(custom_domain)) WHERE custom_domain IS NOT NULL;
        ```
    - `[DRAFT, confidence: 1.0]`

- [ ] **MIG-2** · P0 — Unbatched `UPDATE` on `site.sites` in multiple migrations holds row locks and bloats WAL
    - **Where:** 20260527070000_skeleton_system_cleanup.sql (line `UPDATE site.sites SET settings = settings - 'design' ...`), 20260530000000_drop_workplace_hours.sql (`UPDATE site.sites SET settings = jsonb_set(...) ...`), 20260530140000_rename_workplace_drop_place_id.sql (two similar UPDATEs)
    - **Affects:** All writes to `site.sites` during the update; site creation, domain changes, and publish/unpublish are blocked until the statement commits. Long-running `UPDATE` also inflates WAL and can delay replicas.
    - **Effort:** M (~2–4h) to split each into a chunked job or add `LIMIT`/batching.
    - **What to do:**
        - Replace ad-hoc `UPDATE site.sites SET … WHERE …` with batched processing (e.g. `UPDATE … WHERE id IN (SELECT id FROM site.sites WHERE … LIMIT 1000)` in a loop, or extract to a Laravel chunked job).
        - At minimum, add explicit `SET LOCAL lock_timeout = '2s';` and `statement_timeout = '10s';` so a stuck update fails fast instead of blocking the table indefinitely.
    - **Technical:** Unbatched `UPDATE` on a hot table like `site.sites` scans every row matching the `WHERE` clause and holds row-level locks on all touched rows until the transaction commits. As the number of sites grows, this becomes a multi-second or multi-minute exclusive block. The established pattern for data scrubs on large tables is to split the update into chunks (e.g. 1000 rows per transaction) to minimise lock duration. The canonical migration for this is `docs/migration-guidelines.md` §Full-table-scan data scrubs, which recommends extracting such scrubs to post-deploy jobs.
    - **Plain English:** Running a full reorganisation of every filing cabinet drawer at once means nobody can put anything new in or take anything out until the job finishes. Doing it drawer by drawer keeps the office running.
    - **Evidence:**
        ```sql
        -- From 20260527070000:
        UPDATE site.sites SET settings = settings - 'design' WHERE settings ? 'design';

        -- From 20260530000000:
        UPDATE site.sites
        SET settings = jsonb_set(
            settings,
            '{google_business_profile}',
            (settings -> 'google_business_profile') - 'hours'
        )
        WHERE settings ? 'google_business_profile'
          AND (settings -> 'google_business_profile') ? 'hours';
        ```
    - `[DRAFT, confidence: 0.9]` (elevated to P0 due to prod-is-behind re-baseline risk)

- [ ] **MIG-3** · P0 — Unbatched `UPDATE` on `core.users` during account_type backfill
    - **Where:** supabase/migrations/20260612120000_account_type_partna_business.sql: line `UPDATE core.users SET account_type = 'partna' WHERE account_type = 'individual';`
    - **Affects:** All user profile updates, logins, and dashboard reads — `core.users` is the central user table and a hot table.
    - **Effort:** M (~2–4h) to batch or replace with a migration-only approach using `SET LOCAL lock_timeout` and chunking.
    - **What to do:**
        - Batch the update: `UPDATE core.users SET account_type = 'partna' WHERE account_type = 'individual' AND id IN (SELECT id FROM core.users WHERE account_type = 'individual' LIMIT 1000)` run repeatedly until zero rows updated.
        - Alternatively, add `SET LOCAL lock_timeout = '2s'` and `statement_timeout = '10s'` and only run if the table row count is below a threshold; if larger, extract to a Laravel command.
    - **Technical:** `core.users` is likely to grow to thousands of rows. An unbatched `UPDATE` that touches every user row will hold row locks on all of them for the duration of the scan. Any concurrent write (handle change, login timestamp, profile update) will block. This is especially dangerous during a re-baseline where this migration runs for the first time on a production-sized user base. The canonical pattern is to chunk the update or to run it as a post-deploy job that can be monitored and retried.
    - **Plain English:** Updating every user’s account type at once is like needing a signature from every resident of an apartment building in one go — nobody can enter or leave until it’s done. It’s safer to go door-to-door in small batches.
    - **Evidence:**
        ```sql
        UPDATE core.users SET account_type = 'partna' WHERE account_type = 'individual';
        ```
    - `[DRAFT, confidence: 1.0]`

- [ ] **MIG-4** · P1 — `ADD CONSTRAINT … CHECK` on `core.users` without `NOT VALID` scans entire user table under `ACCESS EXCLUSIVE`
    - **Where:** supabase/migrations/20260612120000_account_type_partna_business.sql: line `ADD CONSTRAINT users_account_type_check CHECK (account_type IN ('partna', 'business'));` (no `NOT VALID`)
    - **Affects:** All writes and reads on `core.users` during the validation scan; signups, profile updates, and authentication-dependent operations stall.
    - **Effort:** S (~0.5–1h) to adopt the two-step pattern.
    - **What to do:**
        - Split into two migrations: first `ADD CONSTRAINT … NOT VALID` (metadata-only, no table scan), then a companion file `VALIDATE CONSTRAINT` (acquires only `SHARE UPDATE EXCLUSIVE`, allowing concurrent writes).
        - The preceding `UPDATE` already brings existing rows into compliance; validation is then fast.
    - **Technical:** Adding a `CHECK` constraint on an existing table without `NOT VALID` immediately scans every row to verify the constraint. On `core.users`, this means an `ACCESS EXCLUSIVE` lock for the duration of a full table scan. Even though the `UPDATE` above has already converted all rows, PostgreSQL must still scan and lock the table. The two-step pattern (`ADD ... NOT VALID` then `VALIDATE CONSTRAINT` in a separate transaction) is documented in `CONVENTIONS.md §2` and used elsewhere in the codebase (e.g., `20260527070000` with `skeleton_id CHECK`). Because `core.users` is a hot table, the lock risk is real.
    - **Plain English:** Adding a rule “all account types must be one of these two” normally forces a clerk to re-check every existing record before anyone can file anything new. That’s a full lockdown. Instead, you can just post the new rule (nobody can break it going forward) and then quietly audit old records later without shutting the counter.
    - **Evidence:**
        ```sql
        ALTER TABLE core.users
            ADD CONSTRAINT users_account_type_check CHECK (account_type IN ('partna', 'business'));
        ```
    - `[DRAFT, confidence: 1.0]` (elevated to P1 due to prod-being-behind base risk; normally P2)

- [ ] **MIG-5** · P1 — Foreign key constraints added to `site.enquiries` without `NOT VALID`
    - **Where:** supabase/migrations/20260527160000_enquiry_inbox.sql: the `DO $$ … ALTER TABLE … ADD CONSTRAINT … FOREIGN KEY … $$` blocks for `enquiries_customer_fk` and `enquiries_notification_fk` (no `NOT VALID`, and the `guard:no-unsafe-migrations:disable-file` exemption acknowledges the missing two‑step pattern)
    - **Affects:** `site.enquiries` writes and reads during the constraint creation if the table has grown beyond a few thousand rows; the dashboard enquiry inbox and public enquiry submission both touch this table.
    - **Effort:** S (~0.5–1h) to add `NOT VALID` and follow with a companion `VALIDATE` migration.
    - **What to do:**
        - Replace each `ADD CONSTRAINT … FOREIGN KEY …` with `ADD CONSTRAINT … FOREIGN KEY … NOT VALID`.
        - Create a new companion migration file (or add to the existing sibling index file) that runs `ALTER TABLE site.enquiries VALIDATE CONSTRAINT …` outside the main transaction.
    - **Technical:** Adding a foreign key without `NOT VALID` triggers a scan of the entire child table under `SHARE ROW EXCLUSIVE` to verify referential integrity. While this lock is weaker than `ACCESS EXCLUSIVE`, it still blocks concurrent writes. `site.enquiries` may already contain rows on the production database. The two‑step pattern is required by `CONVENTIONS.md §2` for any constraint that will be validated on populated tables. The exemption comment (“tables were empty at migration time”) is no longer true for the production environment; the finding is elevated due to the prod‑behind status.
    - **Plain English:** When you connect two filing cabinets with a rule that every entry must reference a valid person, the system normally locks the whole cabinet while checking every entry. Doing the check off‑hours keeps filing open.
    - **Evidence:**
        ```sql
        DO $$ BEGIN
            ALTER TABLE site.enquiries
                ADD CONSTRAINT enquiries_customer_fk
                    FOREIGN KEY (customer_id) REFERENCES site.customers(id) ON DELETE SET NULL;
        EXCEPTION
            WHEN duplicate_object THEN NULL;
        END $$;
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **MIG-6** · P2 — `platform_connections` CHECK constraints rebuilt without `NOT VALID`
    - **Where:** supabase/migrations/20260606000000_add_woocommerce_platform.sql, 20260612100000_add_custom_platform_and_sync_check.sql, 20260612130000_add_square_platform.sql — each uses `DROP CONSTRAINT` + `ADD CONSTRAINT … CHECK …` without `NOT VALID`
    - **Affects:** `site.platform_connections` writes (e.g., connecting a new platform) blocked during the full‑table validation scan.
    - **Effort:** S (~0.5–1h) to apply the `NOT VALID` + `VALIDATE` pattern.
    - **What to do:**
        - For each migration, split into two steps: `ADD CONSTRAINT … NOT VALID` in one transaction, then `VALIDATE CONSTRAINT` in a separate transaction (or a companion file).
    - **Technical:** Although `site.platform_connections` is small (dozens to low hundreds of rows pre‑beta), any `ADD CONSTRAINT … CHECK` without `NOT VALID` scans the entire table under `ACCESS EXCLUSIVE`. The pattern is already used successfully in later platform‑renaming migrations (e.g., `20260610200000` and subsequent ones that use `NOT VALID` + `VALIDATE`). These earlier files did not, and they will be applied to a production DB that may have grown, so the lock risk is non‑zero.
    - **Plain English:** Even a small filing cabinet gets completely frozen while a new rule is being verified. It’s better to post the rule first (stop new mistakes) and then slowly check the old entries.
    - **Evidence:**
        ```sql
        -- From 20260606000000:
        ALTER TABLE site.platform_connections
            ADD CONSTRAINT platform_connections_platform_check
            CHECK (platform IN (
                'shopify', 'woocommerce', 'eventbrite', ...
            ));
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **MIG-7** · P2 — `DROP INDEX` without `CONCURRENTLY` on `site.site_media` (hot table)
    - **Where:** supabase/migrations/20260527000000_fix_sort_order_unique_constraints.sql: `DROP INDEX IF EXISTS site.site_images_site_sort_active_unique; DROP INDEX IF EXISTS site.site_images_site_sort_order_active_uq;`
    - **Affects:** Brief write blockage on `site.site_media` during the drop; gallery uploads, media processing, and public sitepage reads that depend on this index could stall for the (short) duration of the metadata lock.
    - **Effort:** S (~0.5–1h) to convert the drops to `DROP INDEX CONCURRENTLY`.
    - **What to do:**
        - Replace `DROP INDEX` with `DROP INDEX CONCURRENTLY` and ensure the statement runs outside a transaction (the file already has no `BEGIN`/`COMMIT`).
    - **Technical:** Dropping an index normally acquires an `ACCESS EXCLUSIVE` lock on the table. Although the operation itself is fast (metadata‑only), on a busy table like `site.site_media` the queue of waiting writes can cause a momentary spike in lock contention. The codebase already uses `DROP INDEX CONCURRENTLY` elsewhere (e.g., `20260604000002`), so the pattern is established.
    - **Plain English:** Removing an index is like taking down a road sign – it’s quick, but while the sign is being removed, no cars can pass. Doing it with a temporary detour sign means traffic keeps flowing.
    - **Evidence:**
        ```sql
        DROP INDEX IF EXISTS site.site_images_site_sort_active_unique;
        DROP INDEX IF EXISTS site.site_images_site_sort_order_active_uq;
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **MIG-8** · P3 — Missing `SET LOCAL lock_timeout` / `statement_timeout` on migrations that touch hot tables
    - **Where:** Many migrations that perform DDL or data updates on `site.sites`, `core.users`, `site.blocks`, `site.site_media`; examples include the unbatched `UPDATE` migrations listed above, the `skeleton_system_cleanup.sql`, `account_type_partna_business.sql`, and `enquiry_inbox.sql`.
    - **Affects:** If a heavy lock is acquired and held for longer than expected, there is no automatic cancellation; connections block indefinitely, leading to a cascading application outage.
    - **Effort:** S (~0.5–1h) to add `SET LOCAL lock_timeout = '2s'; SET LOCAL statement_timeout = '10s';` at the top of each migration that touches a live‑traffic table.
    - **What to do:**
        - Add `SET LOCAL lock_timeout = '2s';` and `SET LOCAL statement_timeout = '10s';` to every migration file that performs `ALTER TABLE`, `CREATE INDEX`, `UPDATE`, or `DELETE` on `site.design_kits`, `site.sites`, `site.blocks`, `core.users` (as required by `docs/migration-guidelines.md` §Lock and statement timeouts).
    - **Technical:** In a pre‑beta environment, migrations run unattended during deploy. If a ddl acquires an `ACCESS EXCLUSIVE` lock and then gets blocked by an open transaction, the lock can sit for minutes. Setting a short `lock_timeout` causes the migration to fail fast instead of stalling the connection pool. This is a defense‑in‑depth measure already recommended in the project’s own documentation but missing from most of the later migrations.
    - **Plain English:** A safety valve on every heavy operation: if it takes longer than two seconds to get the exclusive lock, it aborts and alerts, preventing a cascading pile‑up. It’s like a circuit breaker that trips before the whole house catches fire.
    - **Evidence:** (not a single line — the absence is visible in the reviewed migrations; none of the unbatched update files or the `account_type` migration include a `SET LOCAL lock_timeout` guard.)
    - `[DRAFT, confidence: 0.9]`
