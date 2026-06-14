# Migration Safety Audit — 2026-06-13

**Branch:** development
**Lens:** Migration safety: lock-on-deploy risk, backfill ordering, online DDL hygiene, reversibility
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-5`
**Source files audited:**
- `supabase/migrations/20260527000000_fix_sort_order_unique_constraints.sql`
- `supabase/migrations/20260527070000_skeleton_system_cleanup.sql`
- `supabase/migrations/20260527160000_enquiry_inbox.sql`
- `supabase/migrations/20260530000000_drop_workplace_hours.sql`
- `supabase/migrations/20260530140000_rename_workplace_drop_place_id.sql`
- `supabase/migrations/20260606000000_add_woocommerce_platform.sql`
- `supabase/migrations/20260610000000_analytics_v2_clicks_sessions.sql`
- `supabase/migrations/20260610200000_integrations_v2_platforms.sql`
- `supabase/migrations/20260611000000_integrations_remove_four_platforms.sql`
- `supabase/migrations/20260612000000_add_socials_and_youtube_music_platforms.sql`
- `supabase/migrations/20260612100000_add_custom_platform_and_sync_check.sql`
- `supabase/migrations/20260612120000_account_type_partna_business.sql`
- `supabase/migrations/20260612130000_add_square_platform.sql`
- `supabase/migrations/20260612140000_site_custom_domain.sql`
- `docs/migration-guidelines.md`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 4 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **#MIG-1** · P1 — `CREATE UNIQUE INDEX` without `CONCURRENTLY` on hot table `site.sites`
    - **Where:** `supabase/migrations/20260612140000_site_custom_domain.sql:26`
    - **Affects:** All reads and writes on `site.sites` during the index build — site creation, publish/unpublish, Cloudflare subdomain resolution. Migration has not yet been applied to prod; correctable now.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `CREATE UNIQUE INDEX IF NOT EXISTS` with `CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS`. The file has no `BEGIN`/`COMMIT` wrapper so this is already valid — `CONCURRENTLY` cannot run inside a transaction.
        - While in the file, also split the `ADD CONSTRAINT sites_custom_domain_status_check` (line 33) into `NOT VALID` + a companion `VALIDATE CONSTRAINT` migration, matching the established two-step pattern (`docs/migration-guidelines.md` §CHECK constraints).
        - Add a brief comment explaining the `CONCURRENTLY` requirement, consistent with `20260527000000_fix_sort_order_unique_constraints.sql` which documents exactly this reasoning inline.
    - **Technical:** `CREATE UNIQUE INDEX` without `CONCURRENTLY` acquires `ACCESS EXCLUSIVE` on `site.sites` for the full build duration, blocking all reads and writes. `site.sites` is an explicitly hot table in this lens (every public sitepage read, every Cloudflare subdomain resolution). In this specific case, the partial predicate `WHERE custom_domain IS NOT NULL` means zero rows currently qualify — so the lock lasts only milliseconds on a pre-beta DB. However: (a) the convention requires `CONCURRENTLY` on this table regardless of current row count; (b) the migration is unapplied on prod, making now the right moment to fix it rather than accepting the technical debt; (c) any environment that rolls back and re-applies after real custom-domain data exists will experience the full lock. `CONCURRENTLY` is the correct default for any index on a hot table per `CONVENTIONS.md §1` and the pattern established in `20260610000001_analytics_v2_click_indexes.sql`.
    - **Plain English:** Adding an index to a table is like reorganising the library's card catalogue. Without the careful approach, the library locks its doors to visitors and new books for the entire time. The careful approach (adding "CONCURRENTLY") keeps the doors open — it just takes a little longer to finish the reorganisation. Right now the table is mostly empty so the lock would be over in a blink, but we should build this habit before the table fills up and the blink becomes a minute.
    - **Evidence:**
        ```sql
        -- One site per domain (case-insensitive). Partial so multiple NULLs are allowed.
        CREATE UNIQUE INDEX IF NOT EXISTS sites_custom_domain_unique
            ON site.sites (lower(custom_domain)) WHERE custom_domain IS NOT NULL;
        ```

---

## P2 — Should fix

- [ ] **#MIG-2** · P2 — Unbatched inline `UPDATE` data-scrubs on `site.sites` inside migration transactions
    - **Where:** `supabase/migrations/20260527070000_skeleton_system_cleanup.sql:62`, `supabase/migrations/20260530000000_drop_workplace_hours.sql:17`, `supabase/migrations/20260530140000_rename_workplace_drop_place_id.sql:25,34`
    - **Affects:** All DML on `site.sites` is blocked for the duration of the UPDATE scan. At pre-beta row counts these complete in sub-seconds; at production scale (thousands of sites) each becomes a multi-second lock queue on the hottest write path.
    - **Effort:** M (~2–4h) per migration to extract to a post-deploy Artisan command, or S to add `SET LOCAL statement_timeout`
    - **What to do:**
        - These three migrations are already applied to dev but unapplied on prod. Per `docs/migration-guidelines.md` ("Editing already-applied migrations"), editing them is safe on already-deployed environments (they won't re-run), so the prod deploy will execute the revised version.
        - For each `UPDATE site.sites SET … WHERE …`, extract the scrub to an idempotent post-deploy `php artisan` command dispatched after the migration, following the pattern documented in `docs/migration-guidelines.md` §Full-table-scan data scrubs, which cites `20260527070000` itself as the negative exemplar.
        - At minimum add `SET LOCAL statement_timeout = '10s';` before each UPDATE so a stuck scan aborts rather than holding the lock indefinitely.
        - The `WHERE` guards already make these idempotent (confirmed), so re-running after extraction is safe.
    - **Technical:** Each of these UPDATEs runs inside a `BEGIN`/`COMMIT` block and scans `site.sites` row-by-row, holding row-level write locks on every matched row until the transaction commits. An unbatched `UPDATE` that touches every site will, as the platform scales, produce a progressively longer window where site publishes, Cloudflare KV syncs, and domain updates all queue behind the migration transaction. `docs/migration-guidelines.md` §Full-table-scan data scrubs explicitly calls this out as an anti-pattern to avoid, citing the `settings - 'design'` scrub in `20260527070000` as the canonical negative example. The production re-baseline is the right moment to apply the correct pattern since these files haven't run on prod yet.
    - **Plain English:** These migrations reorganise data for every user profile at once, like sending a single assistant to hand-update every filing cabinet at the same moment. While that assistant is working, nobody else can touch any of those files. Pulling the job out into a separate background task means the filing keeps running while the cleanup happens quietly in the background.
    - **Evidence:**
        ```sql
        -- 20260527070000_skeleton_system_cleanup.sql:62
        UPDATE site.sites SET settings = settings - 'design' WHERE settings ? 'design';

        -- 20260530000000_drop_workplace_hours.sql:17
        UPDATE site.sites
        SET settings = jsonb_set(
            settings,
            '{google_business_profile}',
            (settings -> 'google_business_profile') - 'hours'
        )
        WHERE settings ? 'google_business_profile'
          AND (settings -> 'google_business_profile') ? 'hours';

        -- 20260530140000_rename_workplace_drop_place_id.sql:25
        UPDATE site.sites
        SET settings = jsonb_set(
                settings - 'google_business_profile',
                '{workplace}',
                COALESCE(settings -> 'workplace', settings -> 'google_business_profile')
            )
        WHERE settings ? 'google_business_profile';
        ```

- [ ] **#MIG-3** · P2 — Unbatched `UPDATE core.users` in the account-type backfill migration
    - **Where:** `supabase/migrations/20260612120000_account_type_partna_business.sql:28`
    - **Affects:** All DML on `core.users` (logins, handle updates, profile changes) is blocked for the duration of the scan. The migration is unapplied on prod.
    - **Effort:** S (~0.5–1h) to add a `SET LOCAL statement_timeout` guard; M (~2–4h) to extract to an Artisan command
    - **What to do:**
        - Add `SET LOCAL statement_timeout = '10s';` before the `UPDATE` as a circuit-breaker so a slow scan aborts rather than blocking indefinitely.
        - For a more robust fix: extract the `UPDATE core.users SET account_type = 'partna' WHERE account_type = 'individual'` to a post-deploy command. The migration then only drops/adds constraints; the data backfill runs separately and can be monitored. The `WHERE account_type = 'individual'` clause already makes it idempotent.
        - If the inline approach is kept, wrap all five statements in a single `BEGIN`/`COMMIT` block so a failure at any step rolls back the whole migration (currently each statement is its own auto-transaction, so a failure at `ADD CONSTRAINT` leaves the UPDATE committed but the constraint missing).
    - **Technical:** `core.users` is the hottest non-analytics write table (every auth event, profile update, and handle change touches it). The unbatched `UPDATE` acquires row locks on all rows it modifies and holds them until the transaction commits. The migration currently has no `BEGIN`/`COMMIT` wrapper, meaning the five DDL/DML statements each auto-commit independently — if `ADD CONSTRAINT` fails, the preceding `UPDATE` is already committed but the constraint is absent, leaving the DB in a semantically inconsistent state (all values are `'partna'` but no constraint enforces `'partna'`/`'business'`). At pre-beta user counts this is non-critical; at launch scale it becomes a deploy-outage risk. This migration is new (commit `bafd5eb4` on the `development` branch) and has not been applied to prod, so there is still time to apply the correct pattern.
    - **Plain English:** This migration updates every single user record before adding a new rule about account types. Right now with only a handful of test accounts that's instant, but the same code running against a production database with thousands of users would lock the login and profile system for the duration. Adding a safety timer means if it runs long it fails loudly instead of silently holding up traffic.
    - **Evidence:**
        ```sql
        UPDATE core.users SET account_type = 'partna' WHERE account_type = 'individual';
        ```

- [ ] **#MIG-4** · P2 — `ADD CONSTRAINT … CHECK` on `core.users` without `NOT VALID` in account-type migration
    - **Where:** `supabase/migrations/20260612120000_account_type_partna_business.sql:32–33`
    - **Affects:** `core.users` reads and writes blocked during constraint validation scan; same migration as MIG-3.
    - **Effort:** S (~0.5–1h) to split into `NOT VALID` + companion `VALIDATE CONSTRAINT` file
    - **What to do:**
        - Replace the bare `ADD CONSTRAINT … CHECK (account_type IN ('partna', 'business'))` with `ADD CONSTRAINT … CHECK (…) NOT VALID`.
        - Create a companion migration file (e.g. `20260612120001_validate_account_type_check.sql`) that runs `ALTER TABLE core.users VALIDATE CONSTRAINT users_account_type_check;`. This acquires only `SHARE UPDATE EXCLUSIVE`, allowing concurrent reads and writes during the scan.
        - This matches the established two-step pattern used in `20260528020000_alter_site_media_for_scan_states.sql` (`NOT VALID`) + `20260528020001_alter_site_media_validate.sql` (`VALIDATE CONSTRAINT`), and documented in `docs/migration-guidelines.md` §CHECK constraints on large tables.
    - **Technical:** Adding a `CHECK` constraint without `NOT VALID` causes PostgreSQL to validate every existing row in the same transaction under `ACCESS EXCLUSIVE`. Even though the preceding `UPDATE` brings all rows into compliance (so validation won't fail), PostgreSQL still scans and locks the table to confirm. The `NOT VALID` + `VALIDATE CONSTRAINT` two-step avoids the `ACCESS EXCLUSIVE` scan entirely: `NOT VALID` is a metadata-only operation, and `VALIDATE CONSTRAINT` later acquires only `SHARE UPDATE EXCLUSIVE` (allows concurrent reads and writes). `docs/migration-guidelines.md` §CHECK constraints explicitly recommends "Establish the pattern now" at current row counts. Like MIG-3, this migration is unapplied on prod and correctable.
    - **Plain English:** After updating every user record to the new account type, this migration then double-checks every record to confirm the new rule holds — while holding the door shut on anyone trying to update their profile. Splitting this into two steps means the "just post the rule" part happens instantly, and the quiet audit of existing records happens without locking anything.
    - **Evidence:**
        ```sql
        ALTER TABLE core.users
            ADD CONSTRAINT users_account_type_check CHECK (account_type IN ('partna', 'business'));
        ```

- [ ] **#MIG-5** · P2 — `DROP INDEX` without `CONCURRENTLY` before the `CONCURRENTLY` creates in the sort-order migration
    - **Where:** `supabase/migrations/20260527000000_fix_sort_order_unique_constraints.sql:11–12`
    - **Affects:** Brief `ACCESS EXCLUSIVE` lock on `site.site_media` during the index drops; gallery uploads and media reads stall for the duration of the metadata operation.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `DROP INDEX IF EXISTS site.site_images_site_sort_active_unique` and `DROP INDEX IF EXISTS site.site_images_site_sort_order_active_uq` with their `DROP INDEX CONCURRENTLY IF EXISTS` equivalents.
        - The file already has no `BEGIN`/`COMMIT` (required for `CONCURRENTLY`), so no structural change is needed.
        - This migration is already applied to dev. Per `docs/migration-guidelines.md` §Editing already-applied migrations, a content edit to add `CONCURRENTLY` is safe (no re-run on dev, affects only fresh applies including the prod re-baseline).
    - **Technical:** `DROP INDEX` without `CONCURRENTLY` acquires `ACCESS EXCLUSIVE` on the target table for the duration of the drop — which for a metadata-only operation is milliseconds. However, even a brief `ACCESS EXCLUSIVE` on a busy `site.site_media` table can cause a lock-wait pile-up if there are in-flight queries at deploy time. The file correctly uses `CONCURRENTLY` for the two subsequent `CREATE UNIQUE INDEX` statements (and its comment explicitly justifies the `CONCURRENTLY` requirement for them), making the non-`CONCURRENTLY` drops an inconsistent omission. The codebase uses `DROP INDEX CONCURRENTLY` elsewhere (e.g., `20260604000002_swap_cover_fresha_for_shopify.sql`).
    - **Plain English:** The migration removes two old index cards from the catalogue and adds two new ones. The new cards are added the careful way (one at a time, library stays open) but the old cards are ripped out the old-fashioned way (library briefly closed). Changing both removals to the same careful approach keeps things fully consistent.
    - **Evidence:**
        ```sql
        DROP INDEX IF EXISTS site.site_images_site_sort_active_unique;
        DROP INDEX IF EXISTS site.site_images_site_sort_order_active_uq;

        CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS site_images_site_sort_active_unique
            ON site.site_media (site_id, pool, sort_order)
            WHERE (deleted_at IS NULL);
        ```

---

## P3 — Nice to have

- [ ] **#MIG-6** · P3 — Missing `SET LOCAL lock_timeout` / `statement_timeout` guards on DDL migrations for hot tables
    - **Where:** `supabase/migrations/20260612120000_account_type_partna_business.sql` (touches `core.users`); `supabase/migrations/20260612140000_site_custom_domain.sql` (touches `site.sites`); `supabase/migrations/20260527070000_skeleton_system_cleanup.sql` (touches `site.sites`); and most other migrations performing DDL or data scrubs on `site.sites`, `site.blocks`, `site.design_kits`, `core.users`.
    - **Affects:** A stuck lock-wait during any of these migrations blocks the connection pool until manually killed; no automatic failsafe exists.
    - **Effort:** S (~0.5–1h) to add the two `SET LOCAL` lines to the affected files
    - **What to do:**
        - Add the following at the top of every migration file that performs `ALTER TABLE`, `CREATE INDEX`, `UPDATE`, or `DELETE` on `site.design_kits`, `site.sites`, `site.blocks`, or `core.users`:
            ```sql
            SET LOCAL lock_timeout    = '2s';
            SET LOCAL statement_timeout = '10s';
            ```
        - Per `docs/migration-guidelines.md` §Lock and statement timeouts: "`SET LOCAL` scopes the timeout to the current transaction only — it has no effect on application queries. If the DDL cannot acquire the lock within 2 s, the migration aborts with a clear error rather than silently queuing."
        - Priority targets for the next prod-push: `20260612120000_account_type_partna_business.sql` (touches `core.users` with both DDL and DML) and `20260612140000_site_custom_domain.sql` (touches `site.sites` with both `ADD COLUMN` and `CREATE INDEX`). The three site.sites scrub migrations (MIG-2) are lower priority since they're being extracted to Artisan commands.
    - **Technical:** `SET LOCAL lock_timeout = '2s'` causes the DDL statement to abort with a clear error if it cannot acquire the necessary lock within 2 seconds, instead of queuing indefinitely. Without this, a migration that gets behind a long-running application query (a slow public sitepage render, an analytics ingest holding a row lock) will queue all subsequent connections behind it, producing a cascading outage invisible to monitoring until connection exhaustion. This is a defense-in-depth measure; `docs/migration-guidelines.md` §Lock and statement timeouts mandates it for live-traffic tables.
    - **Plain English:** This is the circuit-breaker for deploy migrations. Without it, if a migration can't get access to a busy table it just waits silently — and every user request trying to use that table piles up behind it. With the two-second timeout, the migration fails loudly and immediately, alerting the deploy operator to retry when traffic is lower rather than discovering a pile-up three minutes later.
    - **Evidence:**
        ```sql
        -- docs/migration-guidelines.md §Lock and statement timeouts — mandated pattern, absent from recent migrations:
        SET LOCAL lock_timeout    = '2s';
        SET LOCAL statement_timeout = '10s';

        -- 20260612120000_account_type_partna_business.sql — no timeout guard present:
        ALTER TABLE core.users DROP CONSTRAINT IF EXISTS users_account_type_check;
        ALTER TABLE core.users DROP CONSTRAINT IF EXISTS users_account_type_individual;
        UPDATE core.users SET account_type = 'partna' WHERE account_type = 'individual';
        ALTER TABLE core.users ALTER COLUMN account_type SET DEFAULT 'partna';
        ALTER TABLE core.users
            ADD CONSTRAINT users_account_type_check CHECK (account_type IN ('partna', 'business'));
        ```
