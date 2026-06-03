- [ ] **#MIG-1** · P2 — Missing `NOT VALID` on the `skeleton_id` CHECK constraint causes a full table scan under `ACCESS EXCLUSIVE` during deploy
    - **Where:** supabase/migrations/20260527070000_skeleton_system_cleanup.sql:26
    - **Affects:** All site writes (dashboard saves, subdomain changes, publish toggles) are blocked for the duration of the scan.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Split into two steps: (a) `ALTER TABLE site.sites ADD COLUMN skeleton_id TEXT NOT NULL DEFAULT 'skeleton-1'` **without** the inline CHECK, then (b) `ALTER TABLE site.sites ADD CONSTRAINT sites_skeleton_id_check CHECK (...) NOT VALID`, then (c) `ALTER TABLE site.sites VALIDATE CONSTRAINT sites_skeleton_id_check`.
        - Ship the `VALIDATE CONSTRAINT` step as a separate migration so the lock is downgraded to `SHARE UPDATE EXCLUSIVE`.
    - **Technical:** Postgres adds the CHECK as part of the same `ALTER TABLE` that adds the column, but because the table already contains rows, the constraint is validated immediately under an `ACCESS EXCLUSIVE` lock. Even though the default value satisfies the constraint, a full sequential scan of every existing row is still performed. The two-step `NOT VALID` + `VALIDATE CONSTRAINT` pattern avoids the strong lock while still enforcing the check on all future writes.
    - **Plain English:** When this migration runs on the production database, it will lock the entire “sites” table so that no one can save their profile until the check is finished. It’s like closing the shop’s front door for a few seconds to test a new lock — customers can’t come in during the test. The fix is to install the lock in “silent test” mode first, then quietly verify old keys without shutting the door.
    - **Evidence:**
        ```sql
        ALTER TABLE site.sites
          DROP COLUMN theme_id,
          ADD COLUMN skeleton_id TEXT NOT NULL
            DEFAULT 'skeleton-1'
            CHECK (skeleton_id IN ('skeleton-1','skeleton-2','skeleton-3','skeleton-4'));
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#MIG-2** · P2 — Destructive `DROP TABLE` and `DROP COLUMN` without documented rehearsal or rollback plan
    - **Where:** supabase/migrations/20260527070000_skeleton_system_cleanup.sql:32, 36
    - **Affects:** Irreversible loss of the entire `site.themes` catalog and the `theme_id` column on `site.sites`. Any rollback would require a prior backup and manual restore.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a comment to the migration noting that it ran successfully on `development` (or whichever env) before the production push.
        - Document the rollback path (restore from backup) in the migration header.
    - **Technical:** `DROP TABLE IF EXISTS site.themes CASCADE` and the `DROP COLUMN theme_id` inside the earlier `ALTER TABLE` are irreversible – the data cannot be recovered through SQL alone. While intentional as part of the skeleton-system cleanup, the lack of an explicit rehearsal note makes it impossible to confirm that the migration was tested against an actual copy of production data before landing.
    - **Plain English:** This is like deleting an old filing cabinet and shredding a column of paperwork in the main ledger — there’s no undo button. The team should leave a sticky note confirming that a copy of the cabinet was tested first, just so there’s no panic if a rollback is ever needed.
    - **Evidence:**
        ```sql
        DROP FUNCTION IF EXISTS set_default_theme_for_site CASCADE;
        DROP TABLE IF EXISTS site.themes CASCADE;
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#MIG-3** · P3 — Missing `IF [NOT] EXISTS` guards on column additions and drops in several later design-kit migrations
    - **Where:** Multiple migrations: `20260527150000_design_kit_header_height.sql:42`, `20260527170000_design_kit_typography_uppercase.sql:42`, `20260529044737_design_kit_contrasting_colors.sql:34`, `20260529053028_design_kit_unified_space_scale.sql:56` (DROP COLUMNs without `IF EXISTS`)
    - **Affects:** Developer experience – partial re-runs of these migrations after a failure will abort with “column already exists” or “column does not exist”.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `IF NOT EXISTS` to all `ADD COLUMN` statements not covered by an outer `DO $$ ... IF EXISTS` guard.
        - Add `IF EXISTS` to all `DROP COLUMN` statements.
    - **Technical:** The migration runner (`supabase db push`) is not idempotent. If a migration file partially applies (e.g., a network blip after the first column addition), re-running the file would fail because Postgres does not recognise the columns as already present. The same applies to drops – `DROP COLUMN` without `IF EXISTS` will error if the column is already gone.
    - **Plain English:** It’s like writing an instruction “Open the window” when the window is already open – a second attempt fails noisily. Adding a small “if it’s not already there” note to each step makes the script safe to run again if anything goes wrong halfway through.
    - **Evidence:**
        ```sql
        -- 20260527150000
        ADD COLUMN sizing_header_height TEXT NULL,
        ```
        ```sql
        -- 20260529053028 (DROP without IF EXISTS)
        DROP COLUMN padding_extra_small,
        ```
    - `[DRAFT, confidence: 1.0]`

- [ ] **#MIG-4** · P2 — Destructive bulk `DROP COLUMN` in unified space scale migration without `IF EXISTS` and no documented rehearsal
    - **Where:** supabase/migrations/20260529053028_design_kit_unified_space_scale.sql:56–76
    - **Affects:** All data in the dropped `padding_*`, `spacing_*`, and tablet-tier columns is lost. While likely empty, the migration provides no confirmation that the columns were unused before dropping them.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `IF EXISTS` to each `DROP COLUMN` clause (already partially addressed by MIG-3, but these drops represent a data loss, not just a re-run safety).
        - Precede the migration with a comment confirming that a `SELECT count(*) FROM site.design_kits WHERE <column> IS NOT NULL` was run on the production database and returned zero, or that a rehearsal was performed.
    - **Technical:** `ALTER TABLE … DROP COLUMN` removes the column and its storage. Without a written guard, there is no proof that the old design-kit values were already migrated or that users had never written into those columns. This pattern is a source of accidental data loss if assumptions about column usage drift between environments.
    - **Plain English:** It’s like throwing out a drawer of customer customisations without checking if there was anything in it. Even if the drawer was empty, the person doing the throwing should leave a note saying “I checked — it was truly empty” so the next person doesn’t wonder.
    - **Evidence:**
        ```sql
        ALTER TABLE site.design_kits
          -- Drop old padding scale (base + desktop)
          DROP COLUMN padding_extra_small,
          DROP COLUMN padding_small,
          DROP COLUMN padding_general,
          DROP COLUMN padding_large,
          ...
        ```
    - `[DRAFT, confidence: 0.8]`
