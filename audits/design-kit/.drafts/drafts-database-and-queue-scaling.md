- [ ] **SCALE-1** · P2 — Migration adds CHECK constraint without `NOT VALID` on `site.sites.skeleton_id`
    - **Where:** supabase/migrations/20260527070000_skeleton_system_cleanup.sql (ALTER TABLE block)
    - **Affects:** Deploy safety when `site.sites` row count grows; at 200 brands this is currently instant, but the pattern risks a full-table validation scan holding an ACCESS EXCLUSIVE lock on a growing table during deploy.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add the CHECK constraint with `ADD CONSTRAINT ... CHECK (...) NOT VALID` first, then `ALTER TABLE ... VALIDATE CONSTRAINT` in a separate step.
        - Backfill any rows that might violate the constraint before the VALIDATE step (currently all rows get the DEFAULT 'skeleton-1', so this is a safety net).
    - **Technical:** Postgres validates CHECK constraints against every existing row at `ADD` time unless `NOT VALID` is specified. On a hot table under concurrent writes, that scan holds a lock that can queue other DML. At 200 rows the scan is sub-millisecond and harmless, so this is a P2 hardening item rather than a P0 deploy-blocker. The canonical replacement is the two-step `NOT VALID` → `VALIDATE` pattern, which separates the metadata change from the backfill scan.
    - **Plain English:** Adding a new quality-check rule to every existing row in one go is like stopping every worker on an assembly line to re-inspect every box at once. Instead, you first announce the rule ("from now on, boxes must pass this"), then quietly check the backlog while the line keeps moving. The fix is a two-step process: declare the rule, then validate existing rows without blocking new work.
    - **Evidence:**
        ```sql
        ALTER TABLE site.sites
          DROP COLUMN theme_id,
          ADD COLUMN skeleton_id TEXT NOT NULL
            DEFAULT 'skeleton-1'
            CHECK (skeleton_id IN ('skeleton-1','skeleton-2','skeleton-3','skeleton-4'));
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **SCALE-2** · P3 — `information_schema.columns` queried on every design kit write
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php:103-108
    - **Affects:** Every design kit update (colors, typography, spacing edits from the dashboard). ~200 brands × occasional edits = low volume today; becomes ~200 extra metadata queries/day at scale target.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the per-request `information_schema.columns` query with a cached column list (the schema changes only at deploy time — cache key busted on deploy, or use a static array compiled from a config file).
        - If dynamic discovery is kept for safety, apply a short in-process static cache (`static $columns = null`) so it queries once per request cycle, not once per method call.
    - **Technical:** Category (1) N+1 pattern where the "N" is the number of design-kit writes. Every `writeDesignKit()` call runs `DB::connection('pgsql')->table('information_schema.columns')->where(...)->pluck()->all()` to discover the current column set. Information_schema is a system catalog query — it doesn't scale with user data but still costs a round-trip and catalog lock acquisition per write. The column list for `site.design_kits` changes only at migration time (deploy), so caching it eliminates this entirely.
    - **Plain English:** Every time someone saves their design settings, the system looks up a building blueprint to confirm which rooms exist — even though the blueprint hasn't changed since the building was constructed. Instead, take a photo of the blueprint once after construction and reuse it. The fix is to cache the column list so it's fetched once per deploy, not once per save.
    - **Evidence:**
        ```php
        $columns = DB::connection('pgsql')
            ->table('information_schema.columns')
            ->where('table_schema', 'site')
            ->where('table_name', 'design_kits')
            ->pluck('column_name')
            ->all();
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **SCALE-3** · P3 — All design-kit migrations lack `lock_timeout` / `statement_timeout` guards
    - **Where:** supabase/migrations/20260527080000 through 20260530130000 (10 migration files)
    - **Affects:** Deploy safety when `site.design_kits` grows. At 200 rows today this is negligible, but the pattern across 10 migrations normalises unprotected DDL against a table that will eventually hold ~60 columns × 200 rows = trivial, yet concurrent reads from the public profile cache-warm path could queue behind an ALTER TABLE.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `SET LOCAL lock_timeout = '2s'; SET LOCAL statement_timeout = '10s';` at the top of each migration that runs DDL against tables queried by the live application (`site.design_kits`, `site.sites`, `site.site_media`, `site.blocks`).
        - For the multi-column `ALTER TABLE` in `20260529053028` (22 DROP + 10 ADD in one statement), explicitly verify it completes within the timeout on a staging clone at scale volumes.
    - **Technical:** Category (8) — migrations that alter tables read by live traffic should set explicit timeouts so a lock queue doesn't cascade into request timeouts. Postgres `ALTER TABLE` acquires an ACCESS EXCLUSIVE lock; if a long-running query holds a weaker lock on the same table, the migration waits indefinitely, and all subsequent queries queue behind it. `lock_timeout` makes the migration fail fast instead of creating a silent blockage. At the current row count these DDLs complete in microseconds, so this is P3 defence-in-depth — the tables must grow 100× before this matters.
    - **Plain English:** Imagine a librarian trying to reorganise a bookshelf while patrons are still browsing. If the librarian waits politely for every patron to finish, no problem. But if one patron sits down to read for an hour, the librarian stands frozen, and new patrons can't access any books on that shelf. Setting a timeout is like the librarian saying "I'll wait 2 seconds, and if the shelf isn't free, I'll try again later." The fix is to add a 2-second patience limit on every schema change touching live tables.
    - **Evidence:**
        ```sql
        -- No lock_timeout / statement_timeout set before any of these:
        ALTER TABLE site.design_kits ADD COLUMN color_accent TEXT NULL, ...;
        ALTER TABLE site.design_kits ADD COLUMN typography_font_family TEXT NULL, ...;
        ALTER TABLE site.design_kits ADD COLUMN color_text_muted TEXT NULL, ...;  -- 21 cols
        ALTER TABLE site.design_kits DROP COLUMN padding_extra_small, ...;  -- 22 drops + 10 adds
        -- (pattern repeated across all 10 design_kit migration files)
        ```
    - `[DRAFT, confidence: 0.80]`
