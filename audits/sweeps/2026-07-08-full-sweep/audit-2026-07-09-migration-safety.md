# Migration Safety Audit — 2026-07-09

**Branch:** development
**Lens:** Migration safety: lock-on-deploy risk, backfill ordering, online DDL hygiene, reversibility
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- supabase/migrations/20260527070000_skeleton_system_cleanup.sql
- supabase/migrations/20260701180000_strip_block_settings_keys_and_views.sql
- supabase/migrations/20260701200000_strip_site_settings_jsonb_keys.sql
- supabase/migrations/20260603000002_validate_skeleton_id_check.sql
- supabase/migrations/20260603000003_design_kit_timestamps.sql
- supabase/migrations/20260527150000_design_kit_header_height.sql
- supabase/migrations/20260529053028_design_kit_unified_space_scale.sql
- supabase/migrations/20260701000000_design_kit_var_system_columns.sql
- supabase/migrations/20260606000000_add_woocommerce_platform.sql, 20260612100000, 20260612130000, 20260616000000, 20260617000000, 20260617120000, 20260617140000
- supabase/migrations/20260622120000_allow_events_custom_platform.sql
- supabase/migrations/20260613120000_site_custom_domain_primary.sql, 20260708120000_sites_shop_global_settings.sql
- supabase/migrations/20260624010000_schema_hardening_constraints.sql
- supabase/migrations/20260526000000_baseline_standalone_user.sql
- supabase/migrations/CONVENTIONS.md, docs/migration-guidelines.md
- scripts/guard-no-unsafe-migrations.php

## Progress

- P0 Blockers: 0 of 1 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 4 complete

---

## P0 — Must fix before any real user touches the system

- [ ] **#MIG-1** · P0 — Documented anti-pattern (full-table JSONB scrub co-transacted with DDL on hot tables) repeated in 3 migrations, including 2 *after* the team wrote a guide against it
    - **Where:** supabase/migrations/20260527070000_skeleton_system_cleanup.sql:39-62; supabase/migrations/20260701180000_strip_block_settings_keys_and_views.sql:9-16; supabase/migrations/20260701200000_strip_site_settings_jsonb_keys.sql:11-12, 325-338
    - **Affects:** Every write to `site.sites` and `site.blocks` — site create, publish, settings edits, block reorder, dashboard saves — for the duration of these migrations' scans. Both tables are on the lens's explicit hot-table list, and neither has a GIN index on `settings` (confirmed against the baseline), so every `settings ? 'key'` / `WHERE settings IS NOT NULL` predicate is a sequential scan.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Rewrite the three migrations' data scrubs per `docs/migration-guidelines.md` §Full-table-scan data scrubs: move the `UPDATE ... settings = settings - '<key>'` statements out of the `BEGIN/COMMIT` block that also carries the `ALTER TABLE`/`CREATE OR REPLACE VIEW` statements. Since all three files are already applied on `development` (confirmed by later migrations that depend on their output — `site.design_kits`, `platform`/`category` columns), editing them in place is safe per `docs/migration-guidelines.md` §Editing already-applied migrations: `db push` skips already-recorded versions, so the edit only changes fresh-apply behaviour — exactly the prod re-baseline scenario this needs to be safe for.
        - Split each into two files (`<ts>_..._ddl.sql` + `<ts+1>_..._backfill.sql`) so the DDL commits and releases `ACCESS EXCLUSIVE` before the scan/rewrite starts, per CONVENTIONS.md §5.
        - For `20260701200000`, tighten `WHERE settings IS NOT NULL` to `WHERE settings ?| ARRAY['hero_title','hero_subtitle', ...]` (the JSONB `?|` "any key exists" operator) so an already-scrubbed row is a no-op instead of a guaranteed rewrite on every row.
        - The `skeleton_id` inline `CHECK` in `20260527070000` (validates under the same lock) is a **known, already-accepted trade-off** — `20260603000002_validate_skeleton_id_check.sql` explicitly documents the team's decision not to rewrite that migration, judging the row-scan "brief pre-pilot." Re-confirm that judgment still holds once a re-baseline date is set; do not silently re-litigate it as a fresh finding.
    - **Technical:** `docs/migration-guidelines.md` §Full-table-scan data scrubs (#SCHEMA-2) uses `UPDATE site.sites SET settings = settings - 'design' WHERE settings ? 'design';` — verbatim, from `20260527070000` — as its own canonical "Avoid" example, with the fix already spelled out (extract to a post-deploy command/job). Despite that documentation existing, the identical shape reappears twice more: `20260701180000` runs `UPDATE site.blocks SET settings = (settings - 'live_check_enabled' - 'category' - 'platform') WHERE ...` inside the same transaction as a `CREATE OR REPLACE VIEW` and a non-concurrent `DROP INDEX`, and `20260701200000` runs a 10-key strip on `site.sites` guarded only by `WHERE settings IS NOT NULL` — weaker selectivity than the original, meaning on a populated table it rewrites nearly every row. All three keep the table's `ACCESS EXCLUSIVE` lock (acquired by the co-transacted `ALTER TABLE`/`DROP INDEX`) held for the full scan+rewrite duration, since Postgres doesn't release a transaction's locks until `COMMIT`. Per the lens's "prod-is-behind" caveat, the gated re-baseline is exactly the scenario where these three run back-to-back against real schema state — this is a confirmed hot-table lockup risk, not a hypothetical one.
    - **Plain English:** Three separate updates to the site do the same risky thing: they redesign a shelf (change the table structure) and restock every single item on it (rewrite every row) at the same time, with the shop's front door locked the whole time. The team already wrote an internal guide saying "don't do this, do the restocking after the shop reopens" — but two more updates did it anyway after the guide was written. Nobody's shopping right now (pre-beta), which is why nothing broke yet, but this needs fixing before the real database goes live, or every site edit will queue up behind these updates during deploy.
    - **Evidence:**
        ```sql
        -- 20260527070000_skeleton_system_cleanup.sql
        ALTER TABLE site.sites
          DROP COLUMN theme_id,
          ADD COLUMN skeleton_id TEXT NOT NULL
            DEFAULT 'skeleton-1'
            CHECK (skeleton_id IN ('skeleton-1','skeleton-2','skeleton-3','skeleton-4'));
        -- ...
        UPDATE site.sites SET settings = settings - 'design' WHERE settings ? 'design';
        ```
        ```sql
        -- 20260701180000_strip_block_settings_keys_and_views.sql
        UPDATE site.blocks
        SET settings = (settings - 'live_check_enabled' - 'category' - 'platform')
        WHERE block_group = 'links'
          AND (settings ? 'live_check_enabled' OR settings ? 'category' OR settings ? 'platform');

        -- 2. Drop the dead expression index (replaced by idx_blocks_live_check_enabled_active).
        DROP INDEX IF EXISTS site.idx_blocks_live_check_enabled;
        ```
        ```sql
        -- 20260701200000_strip_site_settings_jsonb_keys.sql
        UPDATE site.sites SET settings = settings
            - 'hero_title'
            - 'hero_subtitle'
            - 'primary_button_text'
            - 'primary_button_url'
            - 'bio_text'
            - 'show_branding'
            - 'charlie_enabled'
            - 'services_auto_sync_enabled'
            - 'booking_mode'
            - 'manual_booking_url'
        WHERE settings IS NOT NULL;
        ```
        ```md
        <!-- docs/migration-guidelines.md — the team's own "Avoid" example is this exact SQL -->
        **Avoid:**
        \`\`\`sql
        UPDATE site.sites SET settings = settings - 'design' WHERE settings ? 'design';
        \`\`\`
        ```

## P2 — Should fix

- [ ] **#MIG-2** · P2 — `site.design_kits` timestamp backfill has no idempotency guard
    - **Where:** supabase/migrations/20260603000003_design_kit_timestamps.sql:28-32
    - **Affects:** `site.design_kits` rows — on any replay of this migration file (fresh-DB reset, or a manual Supabase MCP `apply_migration` re-run outside `schema_migrations` tracking, a pattern this repo has hit before per the documented migration-drift history), every kit's `created_at`/`updated_at` is reset to its parent site's timestamps, silently overwriting any real `updated_at` written by the `set_timestamp_design_kits` trigger since.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a guard matching the canonical exemplar (`20260608000000_backfill_subdomain_alias_lifecycle.sql`): `WHERE dk.created_at = dk.updated_at` (only rows that still show the migration-time double-stamp, i.e. never independently updated) before backfilling from `s.created_at`/`s.updated_at`.
        - No `BEGIN/COMMIT` change needed — the surrounding `ADD COLUMN IF NOT EXISTS` + trigger creation in the same file are both cheap/idempotent already; only the `UPDATE` itself needs the guard.
    - **Technical:** The `UPDATE site.design_kits dk SET created_at = s.created_at, updated_at = s.updated_at FROM site.sites s WHERE s.id = dk.site_id` has no `WHERE` clause limiting it to not-yet-backfilled rows, unlike `20260608000000`'s `WHERE expires_at IS NULL` idempotency pattern that this lens holds up as canonical. Under normal `supabase db push` semantics this file runs once and is tracked, so the risk is real but not routine — it requires a fresh-DB replay or an out-of-band re-apply, both of which this repo's own docs (`Supabase Migration Drift` history) confirm have happened in practice.
    - **Plain English:** This step stamps a "date created" label on every design kit by copying it from the matching site — but it doesn't check whether a kit already has a correct, independent stamp before overwriting it. If this step ever runs twice, kits that were properly time-stamped by real usage get silently reset. A simple "only stamp the ones that still look untouched" check fixes it.
    - **Evidence:**
        ```sql
        UPDATE site.design_kits dk
        SET created_at = s.created_at,
            updated_at = s.updated_at
        FROM site.sites s
        WHERE s.id = dk.site_id;
        ```

- [ ] **#MIG-3** · P2 — `guard-no-unsafe-migrations` doesn't detect inline `ADD COLUMN ... CHECK(...)`, only separate `ADD CONSTRAINT ... CHECK`
    - **Where:** scripts/guard-no-unsafe-migrations.php:123-139
    - **Affects:** Any future migration that adds a column with an inline `CHECK` clause on a populated hot table — the CI lint that's supposed to catch exactly this class of lock risk silently passes it, as it did for `20260527070000`'s `skeleton_id` column.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extend Check 3's regex to also match `ADD\s+COLUMN\s+\S+.*?\bCHECK\s*\(` (inline column-definition CHECK), not just `ADD\s+CONSTRAINT\s+\S+\s+CHECK\s*\(`.
        - Add a fixture test migration with an inline `ADD COLUMN ... CHECK(...)` (no `NOT VALID`) to the guard's test suite to lock in the fix and prevent regression.
    - **Technical:** Check 3 in `guard-no-unsafe-migrations.php` matches the pattern `ADD\s+CONSTRAINT\s+\S+\s+CHECK\s*\(...\)` — a *named, separate* constraint statement. It does not match Postgres's inline column-definition syntax (`ADD COLUMN col TYPE ... CHECK (...)`), which is exactly the form `20260527070000_skeleton_system_cleanup.sql` uses for `skeleton_id`. This explains why that migration wasn't flagged and never needed a `guard:no-unsafe-migrations:disable-file` marker (confirmed absent from the file) — the lint has a structural blind spot for the inline form, not that the migration was deliberately exempted. `docs/migration-guidelines.md` and `20260603000002` both independently identify this exact statement as unsafe, so the lint's coverage gap is real, not theoretical.
    - **Plain English:** There's an automatic checker that's supposed to catch exactly the kind of risky database change described in MIG-1 before it ships — but it only recognizes one of the two ways engineers can write that risky change in SQL, and missed the other. It's like a metal detector that only beeps for knives held one way. Widening what it looks for closes the gap.
    - **Evidence:**
        ```php
        // ── Check 3: ADD CONSTRAINT CHECK without NOT VALID ───────────────────────
        // Check constraints on populated tables need NOT VALID to avoid ACCESS EXCLUSIVE.
        if (preg_match_all(
            '/ADD\s+CONSTRAINT\s+\S+\s+CHECK\s*\(.*?(?=,\s*ADD\s+CONSTRAINT\b|;|\z)/is',
            $content,
            $checkMatches
        )) {
        ```

## P3 — Nice to have

- [ ] **#MIG-4** · P3 — Two design-kit column-drop migrations predate the `-- ROLLBACK:` comment convention
    - **Where:** supabase/migrations/20260529053028_design_kit_unified_space_scale.sql:24-71; supabase/migrations/20260701000000_design_kit_var_system_columns.sql:40-67
    - **Affects:** 28 columns dropped in the first file, 23 in the second — all confirmed all-NULL/unused at drop time, so no data loss occurred. Only the documentation is missing.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `-- ROLLBACK:` block to both files listing `ADD COLUMN IF NOT EXISTS <name> TEXT NULL` for each dropped column, matching the convention established from `20260701140000_menu_platform_links.sql` onward (confirmed present in 26 later migration files).
    - **Technical:** The `-- ROLLBACK:` comment convention is real and consistently applied to every destructive migration from `20260701140000` (14:00) onward, but these two files predate that convention (05-29 and 07-01 00:00, both before the 07-01 14:00 cutoff) — so their absence isn't a deviation from an established pattern at the time they were written, just a gap relative to where the convention landed later. `DROP COLUMN` is metadata-only in modern Postgres (no rewrite), so there's no lock-safety issue here — purely a documentation backfill.
    - **Plain English:** Same idea as leaving a note behind after clearing out old filing-cabinet drawers — nothing was lost (the drawers were already empty), but writing down what used to be there costs nothing and helps whoever looks at this later.
    - **Evidence:**
        ```sql
        -- 20260529053028_design_kit_unified_space_scale.sql
        ALTER TABLE site.design_kits
          -- Drop old padding scale (base + desktop)
          DROP COLUMN IF EXISTS padding_extra_small,
          DROP COLUMN IF EXISTS padding_small,
        ```
        ```sql
        -- 20260701000000_design_kit_var_system_columns.sql
        ALTER TABLE site.design_kits
          DROP COLUMN IF EXISTS typography_font_size,
          DROP COLUMN IF EXISTS typography_font_weight,
        ```

- [ ] **#MIG-5** · P3 — Seven `platform_connections` CHECK rebuilds skip `NOT VALID` (compensated by an explicit guard exemption + justification)
    - **Where:** supabase/migrations/20260606000000_add_woocommerce_platform.sql:15-23, 20260612100000_add_custom_platform_and_sync_check.sql, 20260612130000_add_square_platform.sql, 20260616000000_allow_pending_refresh_status.sql, 20260617000000_add_opentable_platform.sql, 20260617120000_add_category_platforms.sql, 20260617140000_add_resdiary_nowbookit_platforms.sql
    - **Affects:** `site.platform_connections` — a `DROP CONSTRAINT` + inline `ADD CONSTRAINT ... CHECK` rebuild on each, which validates every existing row under lock.
    - **Effort:** M (~2–4h) if ever revisited; no action required today
    - **What to do:**
        - No fix required now — all seven already carry `-- guard:no-unsafe-migrations:disable-file` with an explicit justification ("table is empty in this pre-beta app... rewriting its SQL... would diverge from the applied version"), confirmed present in every cited file.
        - When `site.platform_connections` is no longer near-empty, do not copy this shortcut for a new platform addition — use the `NOT VALID` + `VALIDATE` split demonstrated in the sibling migration `20260622120000_allow_events_custom_platform.sql` for the same constraint on the same table.
    - **Technical:** These seven migrations legitimately bypass `CONVENTIONS.md` §2 (`NOT VALID` + `VALIDATE` split), but each does so through the project's own CI-enforced opt-out mechanism (`guard:no-unsafe-migrations:disable-file`), not silently. `20260622120000` demonstrates the correct two-step pattern for the identical constraint, so the safe alternative is already established and in use going forward — this is a documentation/consistency observation, not an active risk, given the compensating control already in place.
    - **Plain English:** Seven of these changes use a shortcut, but each one already comes with an explicit sign-off note explaining why the shortcut is safe today (the table is basically empty). One more recent change did it the fully safe way instead. Nothing to fix now — just don't copy the shortcut once the table has real data in it.
    - **Evidence:**
        ```sql
        -- guard:no-unsafe-migrations:disable-file
        -- Exempt: site.platform_connections is empty in this pre-beta app, so rebuilding
        -- the CHECK takes a harmless lock (no rows to validate). This migration is
        -- intentionally retained after the WooCommerce revert (PR #201) and is already
        -- applied to the DBs — rewriting its SQL to the NOT VALID + VALIDATE pattern
        -- would diverge from the applied version.
        ALTER TABLE site.platform_connections
            DROP CONSTRAINT IF EXISTS platform_connections_platform_check;
        ```

- [ ] **#MIG-6** · P3 — Two `site.sites` column-add migrations skip the documented `lock_timeout` guard
    - **Where:** supabase/migrations/20260613120000_site_custom_domain_primary.sql:9-10; supabase/migrations/20260708120000_sites_shop_global_settings.sql:27-29
    - **Affects:** `site.sites` — both migrations run bare `ADD COLUMN ... NOT NULL DEFAULT <immutable literal>`, which is metadata-only and fast under normal conditions, so the guard is prophylactic rather than fixing an active bug.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `SET LOCAL lock_timeout = '2s'; SET LOCAL statement_timeout = '10s';` at the top of both files, per `docs/migration-guidelines.md` §Lock and statement timeouts, which explicitly names `site.sites` (along with `site.design_kits` and `site.blocks`) as requiring this guard on every DDL statement.
        - `20260624010000_schema_hardening_constraints.sql` and `20260701220000_allow_events_custom_platform.sql` already demonstrate the correct pattern to copy.
    - **Technical:** Both files add columns with immutable-literal defaults (`false`, `'checkout'`, `true`) — PostgreSQL 11+ makes these metadata-only, so there's no rewrite risk today. The gap is purely against the project's own written convention, which unconditionally requires the timeout guard on `site.sites`/`site.blocks`/`core.users`/`site.design_kits` DDL regardless of whether the specific statement is expected to be fast, as belt-and-suspenders against a future Postgres/Supabase behavior change. (DeepSeek's draft also flagged `site.workplaces` and `site.shop_brands` migrations here — those tables are not on the documented hot-table list, so they're dropped from this finding as out of scope.)
    - **Plain English:** The project has a rule that says "always put a safety timer on changes to the site table," so that if something unexpectedly gets stuck, it fails fast and loud instead of quietly blocking the whole app. These two changes forgot the timer. The change itself is safe today, but the timer is cheap insurance for later.
    - **Evidence:**
        ```sql
        -- 20260613120000_site_custom_domain_primary.sql (no lock_timeout / statement_timeout)
        ALTER TABLE site.sites
            ADD COLUMN IF NOT EXISTS custom_domain_primary boolean NOT NULL DEFAULT false;
        ```
        ```sql
        -- 20260708120000_sites_shop_global_settings.sql (no lock_timeout / statement_timeout)
        ALTER TABLE site.sites
            ADD COLUMN IF NOT EXISTS shop_link_mode  text    NOT NULL DEFAULT 'checkout',
            ADD COLUMN IF NOT EXISTS shop_auto_latest boolean NOT NULL DEFAULT true;
        ```

- [ ] **#MIG-7** · P3 — Three bare `ADD COLUMN` statements break the `IF NOT EXISTS` convention mid-chain
    - **Where:** supabase/migrations/20260527150000_design_kit_header_height.sql:21-24
    - **Affects:** A fresh-DB reset replay or an out-of-band re-apply of this file fails with `42701: duplicate_column`, aborting mid-migration and blocking every subsequent file in the sequence (this file sits in the middle of a ~15-file `design_kits` migration chain).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change all three `ADD COLUMN` to `ADD COLUMN IF NOT EXISTS`, matching every sibling `design_kits` migration in the chain (`20260527080000` through `20260527140000`, `20260603000004`, etc., all confirmed to use `IF NOT EXISTS`).
        - Per `docs/migration-guidelines.md` §Editing already-applied migrations, this is a safe no-op edit on environments where the file already ran (Supabase tracks by version timestamp, not content hash) — it only changes fresh-apply behaviour, which is exactly the goal.
    - **Technical:** This is the one hygiene gap in this audit with a genuine functional failure mode (not just a documentation gap): every other `design_kits` `ADD COLUMN` migration in the surrounding timestamp range uses `IF NOT EXISTS`; this file alone doesn't, making it the single point of failure for any full sequential replay of the migration chain (fresh prod re-baseline, CI fresh-DB job, or disaster recovery restore).
    - **Plain English:** Almost every step in this multi-step process is written to be safely repeatable — "add this if it's not already there." This one step forgets that phrasing, so if the whole sequence is ever replayed from scratch and this step runs a second time, it crashes and blocks everything after it. Matching the wording used everywhere else fixes it.
    - **Evidence:**
        ```sql
        ALTER TABLE site.design_kits
          ADD COLUMN sizing_header_height TEXT NULL,
          ADD COLUMN sizing_tablet_header_height TEXT NULL,
          ADD COLUMN sizing_desktop_header_height TEXT NULL;
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Guard-script hardening:** MIG-3
    - **Why grouped:** single-item bundle — a PHP lint-script edit (not a SQL migration or schema change), so it doesn't require the DB-migration standalone gate.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).

## Standalone — do NOT bundle

- **MIG-1 — Repeated hot-table lock anti-pattern across 3 migrations** · P0, and every constituent change is a SQL migration edit — mandatory individual sign-off.
- **MIG-2 — Design-kit timestamp backfill idempotency** · edits a `supabase/migrations/` SQL file (schema-adjacent data change) — standalone per DB-migration rule.
- **MIG-4 — Rollback comments on 2 column-drop migrations** · edits `supabase/migrations/` SQL files — standalone per DB-migration rule, despite low risk.
- **MIG-5 — CHECK rebuild pattern note (no action needed)** · touches `supabase/migrations/` files if ever revisited — standalone per DB-migration rule.
- **MIG-6 — `lock_timeout` guard on 2 `site.sites` migrations** · edits `supabase/migrations/` SQL files — standalone per DB-migration rule.
- **MIG-7 — `IF NOT EXISTS` fix on `design_kit_header_height`** · edits a `supabase/migrations/` SQL file — standalone per DB-migration rule.
