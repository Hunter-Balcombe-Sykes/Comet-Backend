- [ ] **#SCHEMA-1** · P2 — `writeDesignKit()` silently discards design kit values when no `site.design_kits` row exists
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php:145-155
    - **Affects:** Professionals who update design kit settings for a site whose `design_kits` row is missing (failed backfill, trigger bypass, manual DB operation). Changes are accepted with HTTP 200 but never persisted.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the `->where('site_id', $siteId)->update($valid)` pattern with `DB::table('site.design_kits')->updateOrInsert(['site_id' => $siteId], $valid)` so the row is created if missing.
        - Add a post-update assertion that at least 1 row was affected; log a warning if not, surfacing the silent-failure case to observability.
    - **Technical:** The `trg_create_empty_design_kit` AFTER INSERT trigger on `site.sites` guarantees a `design_kits` row for every site created through normal application flow. However, triggers can be bypassed (`session_replication_role = 'replica'`, `ALTER TABLE … DISABLE TRIGGER`, raw INSERTs with `pg_restore`), and sites created before the 20260527070000 migration relied on a separate backfill step that may have missed rows. When `writeDesignKit()` runs against a site with no `design_kits` row, `lockForUpdate()->get()` acquires no lock (empty result set), and the subsequent `->update($valid)` affects 0 rows — PostgreSQL returns success for a valid UPDATE targeting zero rows. The controller returns HTTP 200 with no indication the write was a no-op.
    - **Plain English:** Imagine a filing cabinet with one folder per customer. When a new customer is added, a robot automatically creates their folder. But if the robot was turned off or missed someone, and you try to file papers for that customer, the system says "done" but the papers go in the trash because there's no folder to put them in. The fix is to check if the folder exists and create one on the spot if it doesn't.
    - **Evidence:**
        ```php
        DB::connection('pgsql')->transaction(function () use ($siteId, $valid): void {
            DB::connection('pgsql')
                ->table('site.design_kits')
                ->where('site_id', $siteId)
                ->lockForUpdate()
                ->get(); // acquire the lock before writing

            DB::connection('pgsql')
                ->table('site.design_kits')
                ->where('site_id', $siteId)
                ->update($valid);
        });
        ```
    - `[DRAFT, confidence: 0.65]`

- [ ] **#SCHEMA-2** · P3 — `site.design_kits` table has no `created_at` / `updated_at` columns
    - **Where:** supabase/migrations/20260527070000_skeleton_system_cleanup.sql:116-118
    - **Affects:** Internal debugging, audit trails, and any future feature that needs to know when design kit values were last changed at the DB level.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `created_at TIMESTAMPTZ NOT NULL DEFAULT now()` and `updated_at TIMESTAMPTZ NOT NULL DEFAULT now()` columns via a new migration.
        - Backfill existing rows with a sensible timestamp (e.g., the parent site's `updated_at`).
        - Create a trigger `SET updated_at = now()` on UPDATE, or document that `writeDesignKit()` must set it explicitly since it uses raw DB queries (no Eloquent timestamps).
    - **Technical:** The table is created with only `site_id UUID PRIMARY KEY REFERENCES site.sites(id) ON DELETE CASCADE`. No subsequent migration adds timestamp columns. The `writeDesignKit()` method uses raw `DB::table(…)->update()` which bypasses Eloquent's automatic timestamp management — even if columns existed, they wouldn't be set without an explicit touch or trigger. Without timestamps, there's no way to answer "when did this professional last change their accent colour?" from the database alone. The parent `site.sites.updated_at` is touched by the controller after a design-kit-only write, providing a proxy audit trail, but it's imprecise (any site change updates it) and loses fidelity if a direct DB write occurs.
    - **Plain English:** Every other table in the system has a "created at" and "last updated at" timestamp — like a time stamp on a paper form. The design settings table is missing these, so if something goes wrong, you can't tell when the settings were last changed without digging through application logs. It's a simple column addition that future you will thank present you for.
    - **Evidence:**
        ```sql
        CREATE TABLE site.design_kits (
          site_id UUID PRIMARY KEY REFERENCES site.sites(id) ON DELETE CASCADE
        );
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SCHEMA-3** · P3 — Migration `20260529044737` adds columns without `IF NOT EXISTS`, breaking idempotent re-run
    - **Where:** supabase/migrations/20260529044737_design_kit_contrasting_colors.sql:23-25
    - **Affects:** Deployment recovery — if this migration needs to be re-run in a disaster-recovery scenario (partial application, branch switch, restore), it fails with `column … already exists` instead of succeeding no-op.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `IF NOT EXISTS` to each `ADD COLUMN` clause: `ADD COLUMN IF NOT EXISTS color_contrasting_bg TEXT NULL`.
        - Audit the other migrations for the same pattern — `20260529053028` also lacks `IF EXISTS` on `DROP COLUMN` and `IF NOT EXISTS` on `ADD COLUMN`.
    - **Technical:** PostgreSQL `ALTER TABLE … ADD COLUMN` (without `IF NOT EXISTS`) throws `ERROR: column "…" of relation "design_kits" already exists` if the column is already present. This turns a re-run of an already-applied migration from a safe no-op into a deployment failure. Partna's migration strategy runs SQL files sequentially against Supabase; while re-runs are rare in normal CI/CD, disaster recovery (restoring a backup and re-applying migrations, or switching between branches with different migration states) hits this. The single `ALTER TABLE` statement is atomic, so partial application within the statement isn't a concern, but the lack of idempotency is inconsistent with later migrations that DO use `IF NOT EXISTS` (e.g., `20260530110000`).
    - **Plain English:** Most of the database change files are safe to run twice by accident — they check "does this column already exist?" before adding it. Two of the files skip that check, so if they ever need to be re-applied during an emergency restore, they'll crash instead of cleanly saying "already done, moving on." It's like a door that only opens once and then jams — adding a simple guard makes it safe to open again.
    - **Evidence:**
        ```sql
        ALTER TABLE site.design_kits
          ADD COLUMN color_contrasting_bg TEXT NULL,
          ADD COLUMN color_contrasting_text TEXT NULL,
          ADD COLUMN color_placeholder TEXT NULL;
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#SCHEMA-4** · P3 — Migration `20260529053028` drops and adds columns without `IF EXISTS` / `IF NOT EXISTS` guards
    - **Where:** supabase/migrations/20260529053028_design_kit_unified_space_scale.sql:52-80
    - **Affects:** Same as SCHEMA-3 — disaster-recovery re-run fails on already-applied column operations. Higher risk here because 24 DROP and 10 ADD operations run in a single `ALTER TABLE`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `IF EXISTS` to every `DROP COLUMN` clause (e.g., `DROP COLUMN IF EXISTS padding_extra_small`).
        - Add `IF NOT EXISTS` to every `ADD COLUMN` clause (e.g., `ADD COLUMN IF NOT EXISTS space_xs TEXT NULL`).
        - Consider splitting the migration into two statements if the combined ALTER TABLE with 34 column operations becomes unwieldy — but the idempotency guards are the priority.
    - **Technical:** Same PostgreSQL behaviour as SCHEMA-3: `DROP COLUMN` without `IF EXISTS` throws `ERROR: column "…" does not exist`, and `ADD COLUMN` without `IF NOT EXISTS` throws `ERROR: column "…" already exists`. This migration performs 24 drops and 10 adds in a single `ALTER TABLE` — the statement is atomic, but any single missing/already-present column causes the entire operation to fail. During normal forward deployments this is fine (the columns exist/don't exist as expected), but during a restore-and-replay scenario, the migration cannot distinguish "already applied" from "never applied."
    - **Plain English:** This is the same issue as SCHEMA-3, but bigger — 34 column changes in one command. If any one of them has already run, the whole thing fails. Like trying to remove 24 stickers and add 10 new ones all at once, where the instructions say "tear off sticker A" but it's already gone — the whole process stops. Adding "if it's there" and "if it's not there" checks to each step makes the whole block safe to re-run.
    - **Evidence:**
        ```sql
        ALTER TABLE site.design_kits
          -- Drop old padding scale (base + desktop)
          DROP COLUMN padding_extra_small,
          DROP COLUMN padding_small,
          …
          -- Add unified space scale (mobile base)
          ADD COLUMN space_xs TEXT NULL,
          ADD COLUMN space_s TEXT NULL,
          …;
        ```
    - `[DRAFT, confidence: 0.8]`
