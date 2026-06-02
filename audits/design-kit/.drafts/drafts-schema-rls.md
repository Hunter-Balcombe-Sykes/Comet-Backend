- [ ] **SCHEMA-1** · P1 — StaffUpdateSiteRequest design_kit validation rules heavily out of sync with site.design_kits schema
    - **Where:** app/Http/Requests/Api/Staff/UserSite/StaffUpdateSiteRequest.php (design_kit section, ~28 stale rules + ~13 missing rules)
    - **Affects:** Staff dashboard operators attempting to update professional design kits — writes silently succeed but data is dropped because `writeDesignKit()` filters against actual DB columns; clients get false-positive 200 responses.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Remove all validation rules for columns dropped by migration `20260529053028`: `spacing_*`, `padding_*`, `padding_tablet_*`, `spacing_tablet_*`, `spacing_desktop_*`, `padding_desktop_*`, `sizing_tablet_*`, `typography_tablet_*`.
        - Add validation rules for replacement columns from the same migration: `space_xs`, `space_s`, `space_regular`, `space_medium`, `space_large` and their `space_desktop_*` counterparts, plus `color_placeholder`, `color_contrasting_bg`, `color_contrasting_text`.
        - Add a smoke test that diffs the design_kit validation keys against `information_schema.columns` on `site.design_kits` so this class can't drift again.
    - **Technical:** Migration `20260529053028_design_kit_unified_space_scale.sql` dropped 24 columns (the entire padding + spacing scale, all tablet-tier columns for padding/spacing/sizing/typography) and added 10 new `space_*` + `space_desktop_*` columns. `StaffUpdateSiteRequest` was never updated — it still validates the dropped columns and omits the replacements. The controller's `writeDesignKit()` method queries `information_schema.columns` to filter incoming keys against real columns, so writes from staff clients silently drop all spacing/padding/tablet values without error. This is a schema-contract-drift under Category 3 (constraint coverage — validation rules fail to match the schema they're meant to guard).
    - **Plain English:** The staff admin panel was given a checklist of fields to edit, but someone rearranged the actual storage shelves without updating the checklist. Staff operators can check boxes that no longer correspond to real storage slots — the system silently ignores those changes. Meanwhile, the new storage slots don't appear on the checklist at all, so staff can't edit them. The operator thinks they've made changes, but nothing actually gets saved.
    - **Evidence:**
        ```sql
        -- Migration 20260529053028_design_kit_unified_space_scale.sql — drops these:
        DROP COLUMN padding_extra_small,
        DROP COLUMN padding_small,
        DROP COLUMN padding_general,
        DROP COLUMN padding_large,
        DROP COLUMN padding_desktop_extra_small,
        DROP COLUMN padding_desktop_small,
        DROP COLUMN padding_desktop_general,
        DROP COLUMN padding_desktop_large,
        DROP COLUMN spacing_extra_small,
        DROP COLUMN spacing_small,
        DROP COLUMN spacing_general,
        DROP COLUMN spacing_large,
        DROP COLUMN spacing_desktop_extra_small,
        DROP COLUMN spacing_desktop_small,
        DROP COLUMN spacing_desktop_general,
        DROP COLUMN spacing_desktop_large,
        DROP COLUMN padding_tablet_extra_small,
        DROP COLUMN padding_tablet_small,
        DROP COLUMN padding_tablet_general,
        DROP COLUMN padding_tablet_large,
        DROP COLUMN spacing_tablet_extra_small,
        DROP COLUMN spacing_tablet_small,
        DROP COLUMN spacing_tablet_general,
        DROP COLUMN spacing_tablet_large,
        DROP COLUMN sizing_tablet_button_height,
        DROP COLUMN sizing_tablet_input_height,
        DROP COLUMN typography_tablet_font_size,
        DROP COLUMN typography_tablet_title_font_size;
        -- And adds these:
        ADD COLUMN space_xs TEXT NULL,
        ADD COLUMN space_s TEXT NULL,
        ADD COLUMN space_regular TEXT NULL,
        ADD COLUMN space_medium TEXT NULL,
        ADD COLUMN space_large TEXT NULL,
        ADD COLUMN space_desktop_xs TEXT NULL,
        ADD COLUMN space_desktop_s TEXT NULL,
        ADD COLUMN space_desktop_regular TEXT NULL,
        ADD COLUMN space_desktop_medium TEXT NULL,
        ADD COLUMN space_desktop_large TEXT NULL;
        ```
        ```php
        // StaffUpdateSiteRequest.php — still validates dropped columns:
        'design_kit.spacing_extra_small' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.spacing_small' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.spacing_general' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.spacing_large' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.padding_extra_small' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.padding_small' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.padding_general' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.padding_large' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.padding_tablet_extra_small' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.padding_tablet_small' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.padding_tablet_general' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.padding_tablet_large' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.padding_desktop_extra_small' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.padding_desktop_small' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.padding_desktop_general' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.padding_desktop_large' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.spacing_tablet_extra_small' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.spacing_tablet_small' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.spacing_tablet_general' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.spacing_tablet_large' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.spacing_desktop_extra_small' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.spacing_desktop_small' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.spacing_desktop_general' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.spacing_desktop_large' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.sizing_tablet_button_height' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.sizing_tablet_input_height' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.sizing_tablet_header_height' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.typography_tablet_font_size' => ['sometimes', 'nullable', 'string', 'max:32'],
        // And missing the replacement space_* columns entirely.
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **SCHEMA-2** · P2 — Migration 20260527070000 performs inline data-mutation UPDATE on site.sites during deployment
    - **Where:** supabase/migrations/20260527070000_skeleton_system_cleanup.sql:68-70
    - **Affects:** Deploy pipeline — the `UPDATE` scans every row where `settings ? 'design'` is true and modifies the JSONB column. On a large `site.sites` table this blocks the migration completion and holds a row-lock window.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract the `UPDATE site.sites SET settings = settings - 'design'` into a separate backfill job dispatched post-deploy, matching the pattern already documented for the design_kits backfill in the same migration's comment ("existing sites are backfilled separately … so the backfill window stays predictable").
        - Add a `SET lock_timeout = '2s'` guard on the migration's DDL statements as defense-in-depth for future hot-table changes.
    - **Technical:** Category 6 (migration safety under load). The migration performs a full-table-scan `UPDATE` inline — every matching row is locked, modified, and written before the migration transaction commits. For a table with tens of thousands of sites, this blocks the deploy pipeline. The canonical replacement is a post-deploy job that processes rows in batches, or at minimum wrapping the statement in a timeout guard. The same migration's own comments acknowledge this pattern for the design_kits backfill but don't apply it to the settings scrub.
    - **Plain English:** During a software update, the system pauses to manually erase a sticky note from every filing cabinet that has one. If you have 10 cabinets, that's fast. If you have 50,000, the whole update is stuck waiting for the janitor to finish. The fix is to do the cleanup as a background chore after the update finishes, not as a gate that blocks the update itself.
    - **Evidence:**
        ```sql
        -- 5. Strip the legacy `settings.design.*` JSONB sub-key from every site row.
        --    The new design vars live in their own table (site.design_kits) instead
        --    of inside settings JSONB. This is a one-shot scrub; no future code
        --    writes back to settings.design.
        UPDATE site.sites SET settings = settings - 'design' WHERE settings ? 'design';
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **SCHEMA-3** · P2 — `site.design_kits` table has no row-level security enabled
    - **Where:** supabase/migrations/20260527070000_skeleton_system_cleanup.sql:94-97 (CREATE TABLE site.design_kits)
    - **Affects:** All per-site design configuration data — any database role with `site` schema access can read/write every professional's design kit directly, bypassing the application's authorization layer.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `ALTER TABLE site.design_kits ENABLE ROW LEVEL SECURITY;` and `ALTER TABLE site.design_kits FORCE ROW LEVEL SECURITY;` in a new migration.
        - Create an RLS policy using `current_setting('app.actor_id')` to constrain reads/writes to the owning professional's rows (the canonical Partna RLS pattern).
    - **Technical:** Category 1 (row-level security). `site.design_kits` stores per-tenant design configuration (colors, typography, spacing) keyed by `site_id`. The table is created without `ENABLE ROW LEVEL SECURITY`, meaning the shared `app_backend` role (which the Laravel application connects as) can read and write every row without restriction. The application enforces ownership through the `writeDesignKit()` method which writes by `site_id`, but there is no database-level enforcement — a bug in the application layer, a raw SQL query, or a compromised connection would expose all design data. The canonical replacement is an RLS policy keyed on `current_setting('app.actor_id')` matching the `site.sites.user_id` → `core.users.id` chain.
    - **Plain English:** Imagine an apartment building where every unit's front door has a different key, but the building's maintenance closet — which holds paint swatches and décor choices for every unit — has no lock at all. Any maintenance worker who gets into the building can repaint anyone's apartment. The application is supposed to check IDs before letting someone into the closet, but if that check ever fails (or is bypassed), there's no second lock on the door itself.
    - **Evidence:**
        ```sql
        CREATE TABLE site.design_kits (
          site_id UUID PRIMARY KEY REFERENCES site.sites(id) ON DELETE CASCADE
        );
        -- No ALTER TABLE site.design_kits ENABLE ROW LEVEL SECURITY follows.
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **SCHEMA-4** · P2 — `create_empty_design_kit` trigger has no ON CONFLICT guard, fails on duplicate site_id
    - **Where:** supabase/migrations/20260527070000_skeleton_system_cleanup.sql:107-112 (trigger function + trigger definition)
    - **Affects:** Site creation — if a `design_kits` row already exists for a `site_id` (manual backfill, race condition, or re-insert), the `INSERT INTO site.design_kits` in the trigger raises a PK violation and rolls back the entire site INSERT.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `ON CONFLICT (site_id) DO NOTHING` to the trigger's INSERT statement.
        - Alternatively, switch to an `INSERT ... ON CONFLICT DO NOTHING` if the row may already exist from the Phase 2 backfill.
    - **Technical:** Category 5 (trigger correctness). The `AFTER INSERT` trigger unconditionally inserts a row into `site.design_kits` keyed by `NEW.id`. The migration's own comments note that existing sites are backfilled separately ("Phase 2 step 2.4 … not in the migration"). If the backfill creates a row and a site is later re-inserted (or if a race between the trigger and a concurrent backfill occurs), the trigger's bare `INSERT` hits a duplicate-key violation on the PK `site_id`, aborting both the trigger and the parent INSERT into `site.sites`. The canonical replacement is `ON CONFLICT (site_id) DO NOTHING` — idempotent, safe under concurrency, no downside.
    - **Plain English:** Every time a new apartment is built, a worker automatically hangs an empty paint-swatch clipboard on the wall. But if someone already hung a clipboard there (maybe they came by earlier to prep), the worker panics, rips the clipboard off the wall, and refuses to finish building the apartment. The fix is simple: if a clipboard is already there, just walk away — don't tear down the whole building over it.
    - **Evidence:**
        ```sql
        CREATE OR REPLACE FUNCTION site.create_empty_design_kit()
        RETURNS TRIGGER AS $$
        BEGIN
          INSERT INTO site.design_kits (site_id) VALUES (NEW.id);
          RETURN NEW;
        END;
        $$ LANGUAGE plpgsql;

        CREATE TRIGGER trg_create_empty_design_kit
          AFTER INSERT ON site.sites
          FOR EACH ROW EXECUTE FUNCTION site.create_empty_design_kit();
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **SCHEMA-5** · P2 — Orphaned `sizing_tablet_header_height` column survives tablet-tier purge
    - **Where:** supabase/migrations/20260529053028_design_kit_unified_space_scale.sql (DROP list omits this column); supabase/migrations/20260527150000_design_kit_header_height.sql (added the column)
    - **Affects:** Storage — the column exists in the DB and is validated by `StaffUpdateSiteRequest` but is neither read by `IndividualProfilePayloadBuilder` (no `prefix => 'sizingTablet'` mapping), nor validated by `UpdateSiteRequest`. The migration that purports to drop the tablet tier leaves this column behind.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `DROP COLUMN IF EXISTS sizing_tablet_header_height` in a new cleanup migration, or explicitly decide to keep it and add the `sizingTablet` → `sizingTablet` prefix mapping to `groupKitColumns()` so it surfaces in the API.
        - Audit whether any other `*_tablet_*` columns were similarly missed by the 20260529053028 DROP list.
    - **Technical:** Category 6 (migration safety) + Category 3 (constraint/schema consistency). Migration `20260529053028` declares "Drops all `*_tablet_*` columns — the kit now ships a single desktop breakpoint at 550px (no tablet tier)" but only drops `sizing_tablet_button_height`, `sizing_tablet_input_height`, `typography_tablet_font_size`, and `typography_tablet_title_font_size`. The `sizing_tablet_header_height` column (added by the later migration `20260527150000`) was not included in the DROP list. The application code treats it as dropped — `UpdateSiteRequest` has no validation rule for it, and `groupKitColumns()` has no `sizingTablet` prefix map. The result is a zombie column: stored, validated by the stale staff request, but invisible in API responses.
    - **Plain English:** The operations team announced "we're removing all the medium-sized storage lockers — everything will use small or large from now on." They cleared out most of them, but missed one locker in the back corner. The main inventory system doesn't list it, so customers never see what's inside. But the old staff clipboard still has a checkbox for it, so workers can put things in a locker that nobody knows exists.
    - **Evidence:**
        ```sql
        -- Migration 20260527150000 — adds the column:
        ALTER TABLE site.design_kits
          ADD COLUMN sizing_tablet_header_height TEXT NULL;

        -- Migration 20260529053028 — claims to drop all tablet columns but omits it:
        -- Drops `sizing_tablet_button_height` and `sizing_tablet_input_height` only.
        DROP COLUMN sizing_tablet_button_height,
        DROP COLUMN sizing_tablet_input_height,
        -- No mention of sizing_tablet_header_height.
        ```
        ```php
        // StaffUpdateSiteRequest — still validates the orphaned column:
        'design_kit.sizing_tablet_header_height' => ['sometimes', 'nullable', 'string', 'max:16'],
        ```
    - `[DRAFT, confidence: 0.75]`
