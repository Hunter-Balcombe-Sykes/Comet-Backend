# Migration Safety Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Migration safety: lock-on-deploy risk, backfill ordering, online DDL hygiene, reversibility
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- supabase/migrations/20260711170000_users_email_unique_case_insensitive.sql
- supabase/migrations/20260712000000_retire_staff_account_type.sql
- supabase/migrations/20260713120000_reconcile_instagram_gallery_unification.sql
- supabase/migrations/20260714200000_architecture_one_to_staple.sql
- supabase/migrations/20260714210000_drop_effect_surface.sql
- supabase/migrations/20260714220000_add_aesthetic_axes.sql
- supabase/migrations/20260714230000_drop_glass_satellites.sql
- supabase/migrations/20260715090000_menu_item_currency_and_dining_modes.sql

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 2 complete

---

## P2 — Should fix

- [ ] **MIG-1** · P2 — Instagram/gallery unification backfill rewrites every matching row on re-run instead of skipping already-corrected ones
    - **Where:** supabase/migrations/20260713120000_reconcile_instagram_gallery_unification.sql:23-34
    - **Affects:** `site.sites` rows for every user with an active Instagram connection — a re-run (partial-apply retry, or a future fresh-apply replay against a partially-seeded DB) touches every one of those rows again even when already correct.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a WHERE guard so only rows that actually need the value changed get touched, e.g. compute the target value once and filter `WHERE s.content_instagram_auto_enabled IS DISTINCT FROM <target>`.
        - Match the standard already used two statements later in the same file: `WHERE platform = 'instagram' AND display_settings ? 'gallery'` naturally no-ops on a re-run; the `site.sites` UPDATE should hit the same bar.
    - **Technical:** The `CASE` expression makes the final state idempotent (the `ELSE` branch reassigns the row's current value), but Postgres still writes a new tuple version and takes a row lock for every matching row on every execution — there is no free "no-op" for an UPDATE that happens to set the same value. The canonical exemplar for this exact pattern, `20260608000000_backfill_subdomain_alias_lifecycle.sql`, uses `WHERE expires_at IS NULL` specifically so "a re-run is a no-op" per its own comment. At `site.sites`' current pre-beta row count (~10, per the sibling `20260714200000` migration's own census) this is negligible today, but it's the kind of backfill idempotency gap category (3) exists to catch before the table is analytics-scale.
    - **Plain English:** Picture repainting every wall in a room, including the ones that are already the right color — the end result looks fine, but you've wasted paint and time on walls that didn't need it. If this script gets accidentally run twice, it repeats that unnecessary work on every Instagram-connected site. Adding a quick "skip if already correct" check makes it safe and cheap to re-run.
    - **Evidence:**
        ```sql
        UPDATE site.sites s
        SET content_instagram_auto_enabled = CASE
                -- Never set (NULL): adopt the legacy card intent -- ON unless every
                -- active connection explicitly hid the gallery.
                WHEN s.content_instagram_auto_enabled IS NULL THEN ig.any_on
                -- Curated ON but the card was explicitly hidden everywhere: a deliberate
                -- hide must survive the unification.
                WHEN s.content_instagram_auto_enabled = true AND ig.any_on = false THEN false
                ELSE s.content_instagram_auto_enabled
            END
        FROM ig
        WHERE s.user_id = ig.user_id;
        ```

- [ ] **MIG-2** · P2 — Six migrations touching hot tables omit the `SET LOCAL lock_timeout` / `statement_timeout` guard, right before the prod gated re-baseline replays all of them for the first time
    - **Where:** supabase/migrations/20260712000000_retire_staff_account_type.sql (touches `core.users`), 20260713120000_reconcile_instagram_gallery_unification.sql (`site.sites`), 20260714200000_architecture_one_to_staple.sql (`site.sites`), 20260714210000_drop_effect_surface.sql (`site.design_kits`), 20260714220000_add_aesthetic_axes.sql (`site.design_kits`), 20260714230000_drop_glass_satellites.sql (`site.design_kits`)
    - **Affects:** Deploy-pipeline safety for the next `supabase db push` — none of these six have run against production yet (prod-is-behind: latest applied prod migration is `20260512145025`), so this is the first opportunity for any of them to hang against real traffic.
    - **Effort:** S (~0.5–1h for all six)
    - **What to do:**
        - Add `SET LOCAL lock_timeout = '2s'; SET LOCAL statement_timeout = '10s';` at the top of each of the six files, per `docs/migration-guidelines.md` §Lock and statement timeouts.
        - Note per `docs/migration-guidelines.md` §Editing already-applied migrations: this edit is a no-op on the `development` environment (already applied there, tracked by version timestamp) — it only takes effect on a fresh apply, which is exactly the upcoming prod gated re-baseline this finding is protecting.
    - **Technical:** Verified via grep: repo-wide, only 3 of 60+ migration files that touch `site.sites` / `site.blocks` / `site.design_kits` / `core.users` include `SET LOCAL lock_timeout`, so this is a long-standing, systemic gap rather than a regression unique to these six files — but it specifically matters now because the "prod-is-behind" caveat means all six will execute against production for the first time as part of one large gated re-baseline event, where a stuck lock (e.g. a long-running analytics query holding a conflicting lock on `core.users`) would otherwise wait indefinitely rather than failing fast with a clear, retryable error. `20260711170000_users_email_unique_case_insensitive.sql` and `20260715090000_menu_item_currency_and_dining_modes.sql` are correctly excluded — the former uses `CONCURRENTLY` outside a transaction (self-limiting), the latter only touches `site.menu_items`/`site.menus`, which aren't on the hot-table list.
    - **Plain English:** Think of a delivery truck that pulls up to a loading dock with no timer — if the dock is busy, it just waits there forever, blocking every truck behind it. A 2-second "give up and retry" rule means a migration either gets in quickly or fails fast with a clear message, instead of silently hanging the whole deploy. This costs nothing to add and specifically protects the big one-time deploy where all of prod's pending migrations run together for the first time.
    - **Evidence:**
        ```sql
        -- Confirmed via grep: none of the 6 files above contain this text.
        -- Canonical form (docs/migration-guidelines.md §Lock and statement timeouts):
        SET LOCAL lock_timeout    = '2s';
        SET LOCAL statement_timeout = '10s';
        ```

- [ ] **MIG-3** · P2 — `NOT VALID` + `VALIDATE CONSTRAINT` run in the same implicit transaction on `core.users`, defeating the two-step lock-weakening pattern
    - **Where:** supabase/migrations/20260712000000_retire_staff_account_type.sql:17-25
    - **Affects:** `core.users` — the primary user table read/written on every authenticated request (login, registration, profile resolution). This is the hottest table in the schema.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the `UPDATE` + `DROP CONSTRAINT` + `ADD CONSTRAINT ... NOT VALID` in an explicit `BEGIN; ... COMMIT;` block, then run `VALIDATE CONSTRAINT` in its own separate explicit `BEGIN; ... COMMIT;` block — either later in the same file or in a companion file, mirroring `CONVENTIONS.md` §2's example exactly (which wraps *each* step in its own `BEGIN`/`COMMIT` pair even when both live in one file).
        - Apply the same explicit-transaction-boundary fix template to any future `account_type` CHECK migration — this file is the reusable pattern other engineers will copy.
    - **Technical:** Postgres holds every lock acquired within a transaction until `COMMIT`, not until the individual statement finishes. This file has no `BEGIN`/`COMMIT` at all, so (per this repo's own convention of needing explicit transaction boundaries even within a single file — see `CONVENTIONS.md` §2's example, which wraps Step A and Step B in *separate* `BEGIN`/`COMMIT` pairs specifically to get separate lock windows) the `DROP CONSTRAINT`, `ADD CONSTRAINT ... NOT VALID`, and `VALIDATE CONSTRAINT` all run as one continuous transaction. That means the `ACCESS EXCLUSIVE` lock taken for the `DROP`/`ADD` catalog writes is still held for the entire duration of `VALIDATE CONSTRAINT`'s row scan — the whole point of splitting `NOT VALID` from `VALIDATE` (deferring the scan to a weaker `SHARE UPDATE EXCLUSIVE` lock) is lost. The file's own comment claims "Same DROP → ADD NOT VALID → VALIDATE dance (CONVENTIONS §2)," but doesn't realize the transaction-boundary half of that convention. At today's row count this is low-impact (the leading `UPDATE` only touches 3 rows), which is why this sits at P2 rather than P1 — but `core.users` is the one table where getting this pattern right matters most going forward.
    - **Plain English:** Imagine a road crew that needs to do two things on a busy street: put up a new sign, then inspect every car that passes. If they keep the road closed the whole time for both tasks, closing it once didn't actually save any time over just doing both tasks under one closure — even though the plan was "quick sign now, inspect cars later without closing the road again." The fix is making sure the road actually reopens between the two tasks, which today just requires writing the "reopen" step explicitly instead of assuming it happens automatically.
    - **Evidence:**
        ```sql
        ALTER TABLE core.users DROP CONSTRAINT IF EXISTS users_account_type_check;

        ALTER TABLE core.users
            ADD CONSTRAINT users_account_type_check CHECK (account_type IN ('partna', 'business')) NOT VALID;

        ALTER TABLE core.users VALIDATE CONSTRAINT users_account_type_check;
        ```

## P3 — Nice to have

- [ ] **MIG-4** · P3 — `ADD CONSTRAINT CHECK` without `NOT VALID` on `site.sites`, already compensated by a documented guard exemption
    - **Where:** supabase/migrations/20260714200000_architecture_one_to_staple.sql:15-34
    - **Affects:** `site.sites` — informational/tracking only; the file's own reasoning already accounts for the lock.
    - **Effort:** S (no code change required now)
    - **What to do:**
        - No fix required today. The file already carries `-- guard:no-unsafe-migrations:disable-file` with a specific, checkable justification: `site.sites` has 10 rows total in dev (a complete census, matching the `site.workplaces` precedent scale), and the preceding `UPDATE site.sites SET architecture_id = 'staple' WHERE architecture_id IS DISTINCT FROM 'staple'` normalizes every row first, so the later `VALIDATE`-equivalent scan can never fail.
        - Re-open only if `site.sites` grows materially before this constraint is touched again — at that point use the `NOT VALID` → `VALIDATE CONSTRAINT` split (`CONVENTIONS.md` §2) instead of the exemption.
    - **Technical:** `scripts/guard-no-unsafe-migrations.php` (Master Pattern 20) exists precisely to catch `ADD CONSTRAINT ... CHECK` without `NOT VALID`; this file opts out via the documented `disable-file` escape hatch with an explicit, checkable justification, following the exact same precedent as `20260612100000_add_custom_platform_and_sync_check.sql` and `20260629120000_drop_platform_connections_check.sql` (both cited in this file's own comment). A prior adjudicated audit (`audits/sweeps/2026-07-08-full-sweep/audit-2026-07-09-migration-safety.md`, finding MIG-5) reviewed the identical pattern on `site.platform_connections` and rated it P3 "compensated by an explicit guard exemption + justification... no fix required now" — this is the same root cause on a different table and should carry the same tier for consistency. The file's `DROP CONSTRAINT` → `UPDATE` → `ALTER COLUMN SET DEFAULT` → `ADD CONSTRAINT` sequence also technically fits category (6)'s "multi-statement hazard," but at 10 rows the cumulative lock time is immaterial and covered by the same exemption reasoning.
    - **Plain English:** This is like putting up a "new items must follow this rule" sign and auditing existing stock later, except here the shop owner checked the shelf first, confirmed there's only a handful of items, and already tidied them to match the new rule before applying it — so the quick inspection that follows genuinely can't turn up a problem. Nothing to fix now; just keep an eye on it if the shop's inventory ever grows before this rule changes again.
    - **Evidence:**
        ```sql
        -- guard:no-unsafe-migrations:disable-file

        ALTER TABLE site.sites DROP CONSTRAINT IF EXISTS sites_architecture_id_check;

        UPDATE site.sites
        SET architecture_id = 'staple'
        WHERE architecture_id IS DISTINCT FROM 'staple';

        ALTER TABLE site.sites ALTER COLUMN architecture_id SET DEFAULT 'staple';

        ALTER TABLE site.sites
            ADD CONSTRAINT sites_architecture_id_check CHECK (architecture_id = 'staple');
        ```

- [ ] **MIG-5** · P3 — `DROP COLUMN` migrations on `site.design_kits` carry no "to revert:" note (repo-wide convention gap, not a regression)
    - **Where:** supabase/migrations/20260714210000_drop_effect_surface.sql:13; supabase/migrations/20260714230000_drop_glass_satellites.sql:14-17
    - **Affects:** `site.design_kits` — if a rollback were ever needed mid-incident, there's no comment documenting the `ADD COLUMN` needed to restore storage (data itself is unrecoverable regardless).
    - **Effort:** S (~0.5h, going forward only)
    - **What to do:**
        - Adopt a one-line "to revert:" comment on future `DROP COLUMN` migrations for design-kit vars, e.g. `-- to revert: ALTER TABLE site.design_kits ADD COLUMN effect_surface TEXT NULL; (restores storage only — values are unrecoverable)`.
        - Don't retroactively edit these two files or the ~13 earlier `site.design_kits` column drops (`20260603000001_drop_orphan_design_kit_typography_cols.sql`, `20260603000004_drop_design_kit_sizing_tablet_header_height.sql`, `20260528090000_drop_design_kit_row_height.sql`, `20260528030000_drop_design_kit_bg_image.sql`, and others) — none of them carry this comment either, so singling out just these two would be inconsistent, and editing an already-applied migration's SQL is a no-op on `development` per `docs/migration-guidelines.md`.
    - **Technical:** Confirmed via grep that `app/Services/Design/Presets/PresetTargetableColumns.php`, `PlatformMixFactor.php`, and `app/Http/Requests/Concerns/DesignKitValidationRules.php` reference `effect_surface` / `effect_scrim_blur` / `effect_glass_blur` / `motion_glass_shine_duration` only inside comments documenting the 2026-07-10/07-15 retirement — no live code path reads or writes any of these columns, so there is no cross-file invariant at risk and no functional data-loss exposure today. `DROP COLUMN IF EXISTS` is a catalog-only operation in Postgres (no table rewrite), so the lock-duration risk is negligible regardless. The gap is purely documentation, and it is the established (if imperfect) norm for this table's entire column-churn history, not something unique to these two files — hence P3, not P1.
    - **Plain English:** This is like clearing out a filing cabinet drawer that's confirmed empty and unused, but not leaving a sticky note on the cabinet saying what used to be there. Nobody needs that data back — the app already stopped reading it days before these files ran — but it's a cheap habit to build for next time, in case a future column drop isn't quite as clean-cut as this one.
    - **Evidence:**
        ```sql
        ALTER TABLE site.design_kits DROP COLUMN IF EXISTS effect_surface;
        ```
        ```sql
        ALTER TABLE site.design_kits
            DROP COLUMN IF EXISTS effect_scrim_blur,
            DROP COLUMN IF EXISTS effect_glass_blur,
            DROP COLUMN IF EXISTS motion_glass_shine_duration;
        ```

## Suggested Bundled Sessions

None. Every finding above edits a `supabase/migrations/*.sql` file — per the fix-flow's own rule, any item touching a DB migration/schema change always runs standalone with its own plan + sign-off, never bundled with another finding.

## Standalone — do NOT bundle

- **MIG-1 — Instagram unification backfill idempotency guard** · DB migration/schema change.
- **MIG-2 — Missing lock/statement timeouts on 6 hot-table migrations** · DB migration/schema change; touches `core.users` among others.
- **MIG-3 — NOT VALID/VALIDATE transaction split on `core.users`** · DB migration/schema change touching the platform's hottest table (`core.users`), directly affects auth/session resolution.
- **MIG-4 — `site.sites` CHECK exemption (informational)** · DB migration/schema change; no action required, but any future edit to this file's constraint logic is standalone by rule.
- **MIG-5 — Rollback-comment convention for `site.design_kits` drops** · DB migration/schema change.
