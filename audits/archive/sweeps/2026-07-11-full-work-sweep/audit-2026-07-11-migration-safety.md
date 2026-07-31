# Migration Safety Audit — 2026-07-12

**Branch:** HEAD
**Lens:** Migration safety: lock-on-deploy risk, backfill ordering, online DDL hygiene, reversibility
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `supabase/migrations/20260704160000_shop_brands_products.sql`
- `supabase/migrations/20260704170000_drop_menu_platform_checks.sql`
- `supabase/migrations/20260704180000_drop_users_about.sql`
- `supabase/migrations/20260704150000_prepilot_p0_schema_expand.sql`
- `supabase/migrations/20260705000000_migrate_retired_font_slugs.sql`
- `supabase/migrations/20260705120000_drop_dead_profile_features.sql`
- `supabase/migrations/20260706000000_add_city_to_site_visits.sql`
- `supabase/migrations/20260707020000_site_visits_lat_lon.sql`
- `supabase/migrations/20260707030000_rename_skeleton_ids.sql`
- `supabase/migrations/20260707120000_rename_skeleton_ids_bento_class.sql`
- `supabase/migrations/20260708000000_add_site_media_palette.sql`
- `supabase/migrations/20260708120000_sites_shop_global_settings.sql`
- `supabase/migrations/20260708124853_staff_audit_log_ip_hash_and_get_reads.sql`
- `supabase/migrations/20260709064322_migrate_retired_font_slugs_one.sql`
- `supabase/migrations/20260710120000_add_section_views_duration_ms.sql`
- `supabase/migrations/20260710160000_design_kit_theme_surface_rework.sql`
- `supabase/migrations/20260710170000_skeleton_id_one_only.sql`
- `supabase/migrations/20260710190000_semantic_text_scale_and_vocab_remap.sql`
- `supabase/migrations/20260710210000_surfaces_backend.sql`
- `supabase/migrations/20260710230000_rename_skeleton_id_to_architecture_id.sql`
- `supabase/migrations/20260711000000_staff_account_type.sql`
- `supabase/migrations/20260711000400_notifications_critical_flag.sql`
- `supabase/migrations/20260711153000_feedback_type_area_target.sql`
- `supabase/migrations/20260711160100_add_analytics_purge_indexes.sql`
- `supabase/migrations/20260711160200_site_sessions_add_composite_unique.sql`
- `supabase/migrations/20260711160300_site_sessions_promote_composite_pk.sql`
- `docs/migration-guidelines.md`
- `scripts/guard-no-unsafe-migrations.php`

## Progress

- P0 Blockers: 0 of 1 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 1 complete

---

## P0 — Must fix before any real user touches the system

- [ ] **#MIG-1** · P0 — One transaction holds `ACCESS EXCLUSIVE` on `site.blocks`/`site.public_site_payload` across a DELETE, two view rebuilds, a CHECK swap+validate, and seven column/table drops
    - **Where:** `supabase/migrations/20260705120000_drop_dead_profile_features.sql:22-360`
    - **Affects:** Every public sitepage read during deploy — `site.public_site_payload` and `site.all_site_data` are the views every sitepage GET resolves through; `site.blocks` backs both.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Split into two files: (1) `DELETE FROM site.blocks` + view rebuild + CHECK swap/validate, (2) the `site.sites`/`core.users` `DROP COLUMN`s + child-table drops. This shortens how long the view-drop's lock is held before COMMIT.
        - Add `SET LOCAL lock_timeout = '2s'; SET LOCAL statement_timeout = '10s';` to both resulting files per `docs/migration-guidelines.md` §Lock and statement timeouts.
        - Per `docs/migration-guidelines.md` §Editing already-applied migrations, the Supabase CLI tracks applied versions by timestamp, not content hash — editing this file's SQL is safe even if it's already applied on dev (it no-ops there) and only changes behavior on prod's still-pending fresh apply (prod is on the pre-standalone schema per the "prod-is-behind" caveat), so this split can land now without a new migration file.
    - **Technical:** Category 1 + 6. Postgres holds every lock acquired in a transaction until COMMIT, regardless of which statement acquired it. `DROP VIEW IF EXISTS site.public_site_payload;` (line 35) takes `ACCESS EXCLUSIVE` on the view every sitepage read queries, and `ALTER TABLE site.blocks DROP CONSTRAINT blocks_group_type_check;` (line 331) takes `ACCESS EXCLUSIVE` on `site.blocks` itself — both locks stay held through the `VALIDATE CONSTRAINT` scan, the five `site.sites` column drops, the `core.users` column drop, and the two `DROP TABLE`s that follow, all the way to `COMMIT` (line 360). Any concurrent sitepage render blocks for the full duration of that chain, not just the individual DDL statement's own runtime. The file already has a well-written rollback block (lines 362-408) and correct `NOT VALID`/`VALIDATE` split for the CHECK — the fix here is purely about shortening the lock window by splitting the transaction, not about correctness.
    - **Plain English:** Imagine a shop owner rearranging the front window display — but instead of doing it in a few quick minutes, they pull the curtain shut, then also go rearrange three storage rooms in the back, all before opening the curtain again. Every customer standing outside is stuck waiting the whole time, not just for the window part. Splitting the job into "quick curtain change" and "backroom reorganizing later" means customers only wait for the short part.
    - **Evidence:**
        ```sql
        DELETE FROM site.blocks
        WHERE block_type IN ('bio', 'credentials', 'experience', 'countdown', 'sitepage_analytics');

        DROP VIEW IF EXISTS site.public_site_payload;
        DROP VIEW IF EXISTS site.all_site_data;
        ```
        ```sql
        ALTER TABLE site.blocks DROP CONSTRAINT blocks_group_type_check;

        ALTER TABLE site.blocks ADD CONSTRAINT blocks_group_type_check
            CHECK (
                (block_group = 'links' AND block_type = 'link')
                OR (block_group = 'sections' AND block_type IN (
                    'gallery', 'services', 'booking', 'contacts_collection',
                    'barbershop_info', 'documents', 'newsletter',
                    'contact', 'public_contact', 'workplace'
                ))
            ) NOT VALID;

        ALTER TABLE site.blocks VALIDATE CONSTRAINT blocks_group_type_check;
        ```

## P1 — Fix before pilot launch

- [ ] **#MIG-2** · P1 — Non-atomic numeric cast in a design-kit vocabulary backfill can abort mid-file, leaving prior DDL committed
    - **Where:** `supabase/migrations/20260710190000_semantic_text_scale_and_vocab_remap.sql:143-152`
    - **Affects:** `site.design_kits` / `site.design_kit_contributions` reads during a future apply — a cast failure here leaves the earlier 7 `DROP COLUMN`s and prior `UPDATE`s in this same file committed while this final scrub fails, since the file has no `BEGIN`/`COMMIT` wrapper.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Tighten the `WHERE` guard to reject values that survive `regexp_replace` but still can't cast (e.g. multiple decimal points): add `AND regexp_replace(value, '[^0-9.]', '', 'g') ~ '^[0-9]+(\.[0-9]+)?$'` alongside the existing `is not null` check.
        - Per `docs/migration-guidelines.md` §Editing already-applied migrations, this edit is safe to make now (no-ops on envs where the file already ran; only changes the still-pending fresh-apply behavior).
    - **Technical:** Category 3 + 9. The final `UPDATE` extracts a numeric value via `regexp_replace(value, '[^0-9.]', '', 'g')::numeric`. The existing `WHERE ... is not null` guard correctly excludes values with no digits at all (e.g. `var(--x)`), but a malformed value that still contains digits/dots in a non-numeric shape (e.g. `1.2.3rem`) passes the `WHERE` clause and then throws `invalid input syntax for type numeric` inside the `CASE`, aborting the statement. Because this file (unlike its sibling `20260705120000_drop_dead_profile_features.sql`) has no explicit transaction wrapper, the 7 `text_*` column drops and earlier vocabulary `UPDATE`s above it in the same file are already committed by the time this statement would fail — a half-applied migration.
    - **Plain English:** This migration relabels items using handwritten tags, and the very last step tries to read the trickiest handwriting after already throwing out the old shelves. If one tag is garbled in a way the simple check doesn't catch, the relabeling crashes — but the shelves are already gone. Checking the handwriting more carefully before starting avoids a half-finished cleanup.
    - **Evidence:**
        ```sql
        update site.design_kit_contributions
           set value = case
               when nullif(regexp_replace(value, '[^0-9.]', '', 'g'), '')::numeric < 0.125 then '0'
               when nullif(regexp_replace(value, '[^0-9.]', '', 'g'), '')::numeric < 0.55  then '0.25rem'
               when nullif(regexp_replace(value, '[^0-9.]', '', 'g'), '')::numeric <= 1.175 then '0.85rem'
               else '1.5rem'
             end
         where target_var = 'border_radius'
           and value not in ('0', '0.25rem', '0.85rem', '1.5rem')
           and nullif(regexp_replace(value, '[^0-9.]', '', 'g'), '') is not null;
        ```

## P2 — Should fix

- [ ] **#MIG-3** · P2 — Table creation, JSONB CTE backfill, and a live-table `UPDATE` coalesced into one transaction with no lock/statement timeout
    - **Where:** `supabase/migrations/20260704160000_shop_brands_products.sql:7-105`
    - **Affects:** `site.platform_connections` writes (shop connect/disconnect) during this migration's apply window.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `SET LOCAL lock_timeout = '2s'; SET LOCAL statement_timeout = '10s';` at the top of the transaction.
        - No structural split is required — the closing `UPDATE` already carries a correct idempotency guard (`(payload->>'storage') IS DISTINCT FROM 'relational'`), matching the canonical backfill pattern.
    - **Technical:** Category 6 + 9. The transaction creates `site.shop_brands`/`site.shop_products`, backfills them via a `jsonb_each`/`jsonb_array_elements` CTE read against `site.platform_connections` filtered to `platform = 'shop'`, then closes with `UPDATE site.platform_connections SET payload = '{"storage":"relational"}'::jsonb WHERE platform = 'shop' AND ...`. The `UPDATE` takes `ROW EXCLUSIVE` on `site.platform_connections`, held until COMMIT — lengthened by the preceding CTE work and index builds in the same transaction. This is bounded (only `platform = 'shop'` rows, a narrow subset) and already idempotent, so it's hardening rather than a demonstrated hot-table lockup — `site.platform_connections` isn't on the platform's explicit hot-table list, and shop connect/disconnect is infrequent relative to sitepage reads.
    - **Plain English:** This migration opens a new filing cabinet, moves specific papers into it, and puts a sticky note on the old folder saying "moved" — all in one locked session. It only affects shop-connected profiles, and it's already careful not to redo work if run twice, but there's no timer on the lock, so if something else is mid-write to that table when this runs, it could wait indefinitely instead of failing fast.
    - **Evidence:**
        ```sql
        UPDATE site.platform_connections
        SET payload = '{"storage":"relational"}'::jsonb
        WHERE platform = 'shop'
          AND deleted_at IS NULL
          AND (payload->>'storage') IS DISTINCT FROM 'relational';
        ```

- [ ] **#MIG-4** · P2 — No CI check enforces `SET LOCAL lock_timeout`/`statement_timeout` on DDL against live-traffic tables
    - **Where:** `scripts/guard-no-unsafe-migrations.php` (existing guard, no timeout check); representative gap example at `supabase/migrations/20260711000000_staff_account_type.sql:13-18`
    - **Affects:** Any future migration touching `site.design_kits`, `site.sites`, `site.blocks`, or `core.users` — a stuck lock-wait on deploy queues instead of failing fast with a clear error.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a 5th check to `scripts/guard-no-unsafe-migrations.php` (alongside the existing 4 lock-pattern checks) that requires `SET LOCAL lock_timeout` when a migration's `ALTER TABLE`/`UPDATE` targets `site.design_kits`, `site.sites`, or `site.blocks` — the three tables `docs/migration-guidelines.md` §Lock and statement timeouts already names. Extending it to `core.users`, `analytics.*`, and `notifications.*` is a reasonable follow-on but is a doc-scope decision, not a bug fix.
        - Backfill `SET LOCAL lock_timeout = '2s'; SET LOCAL statement_timeout = '10s';` into the migrations the new check would flag.
    - **Technical:** Category 9. `docs/migration-guidelines.md` documents the `SET LOCAL lock_timeout`/`statement_timeout` pattern, but `scripts/guard-no-unsafe-migrations.php` ("Master Pattern 20") only enforces `CREATE INDEX ... CONCURRENTLY`, FK/CHECK `NOT VALID`, and the four-step `SET NOT NULL` pattern — it has no check for the timeout directive, so the convention is unenforced and inconsistently applied (only 3 files in the whole `supabase/migrations/` tree use it: `20260703000000_add_platform_connection_conditional_validators.sql`, `20260701220000_promote_gb_apify_status_placeid.sql`, `20260624010000_schema_hardening_constraints.sql`). Note the original scan's file list overstated this: several of the files it cited are provably low-risk on inspection — `20260705000000_migrate_retired_font_slugs.sql` and `20260709064322_migrate_retired_font_slugs_one.sql` explicitly reason "both tables are tiny, no lock-contention concern"; `20260708120000_sites_shop_global_settings.sql` and `20260711000400_notifications_critical_flag.sql` use constant (immutable) `DEFAULT` values, which Postgres 11+ applies metadata-only with no table rewrite; `20260707030000_rename_skeleton_ids.sql`, `20260707120000_rename_skeleton_ids_bento_class.sql`, and `20260711000000_staff_account_type.sql` already correctly split `VALIDATE CONSTRAINT` out under the lighter lock. `20260711153000_feedback_type_area_target.sql` was dropped from this finding entirely — `core.feedback` isn't a hot table, and the file's own header explains it's a "low-traffic internal tool" exempted by the guard's own same-file-column exemption. The real, actionable gap is the missing CI enforcement, not that every listed file is independently dangerous.
    - **Plain English:** A stuck lock-wait during deploy is like a worker standing at a locked filing cabinet forever instead of giving up after a couple of seconds. Most of these migrations are small, careful jobs that are unlikely to hit this problem — but there's currently no automatic check making sure every future migration sets that "give up and retry" timer on the tables people are actively using. Adding one automatic check now means nobody has to remember it by hand later.
    - **Evidence:**
        ```sql
        ALTER TABLE core.users DROP CONSTRAINT IF EXISTS users_account_type_check;

        ALTER TABLE core.users
            ADD CONSTRAINT users_account_type_check CHECK (account_type IN ('partna', 'business', 'staff')) NOT VALID;

        ALTER TABLE core.users VALIDATE CONSTRAINT users_account_type_check;
        ```

## P3 — Nice to have

- [ ] **#MIG-5** · P3 — Missing rollback-path comment on destructive `site.design_kits` column drops
    - **Where:** `supabase/migrations/20260710160000_design_kit_theme_surface_rework.sql:22-24`
    - **Affects:** Documentation only — the drops themselves are fast, metadata-only operations on data the file itself calls test-only.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a short rollback comment noting the drop is unrecoverable-in-data (structure-only restore), matching the convention used in `20260705120000_drop_dead_profile_features.sql` and `20260704180000_drop_users_about.sql`.
    - **Technical:** Category 5. `color_bg`, `effect_style`, and `motion_entrance` are dropped from `site.design_kits` with no rollback comment. The original scan claimed sibling migration `20260710190000_semantic_text_scale_and_vocab_remap.sql` "includes a full rollback script" by contrast — that's inaccurate; that file (see #MIG-2) also has no rollback block, so the rollback-comment convention is inconsistently applied across this batch of test-data-only design-kit migrations generally, not uniquely absent here. Given the file's own header states "Test users only — destructive drops are sanctioned" and the drop is metadata-only (no lock/rewrite risk), this is pure documentation hygiene rather than an operational risk — the "no rehearsal path" framing doesn't apply cleanly here since, structurally, every current post-baseline migration is dev-only until the gated prod re-baseline (per CLAUDE.md's "prod-is-behind" caveat), so rehearsal-on-dev is already the de facto process.
    - **Plain English:** This migration throws away three old drawers from a filing cabinet that the team says nobody uses anymore — probably true, and low-risk either way. It's just missing a short note saying "if we're wrong, here's what you'd have to manually restore," the same note other similar cleanups in this codebase already include.
    - **Evidence:**
        ```sql
        alter table site.design_kits drop column if exists color_bg;
        alter table site.design_kits drop column if exists effect_style;
        alter table site.design_kits drop column if exists motion_entrance;
        ```

## Suggested Bundled Sessions

None.

## Standalone — do NOT bundle

- **#MIG-1 — One transaction holds `ACCESS EXCLUSIVE` on `site.blocks`/views across a DELETE + drops:** P0, and edits a `supabase/migrations/` DDL file whose behavior applies to prod's still-pending schema catch-up.
- **#MIG-2 — Non-atomic numeric cast in a design-kit vocabulary backfill:** edits a `supabase/migrations/` file affecting eventual prod schema state; data-scrub correctness change.
- **#MIG-3 — CTE backfill + UPDATE coalesced in one transaction:** edits a `supabase/migrations/` DDL/DML file.
- **#MIG-4 — Missing CI enforcement for lock/statement timeouts:** touches both a CI guard script (`scripts/guard-no-unsafe-migrations.php`) and multiple `supabase/migrations/` files.
- **#MIG-5 — Missing rollback-path comment:** edits a `supabase/migrations/` file, even though low-risk.
