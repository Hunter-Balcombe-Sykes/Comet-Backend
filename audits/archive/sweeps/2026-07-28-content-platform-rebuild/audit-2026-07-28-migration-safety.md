# Migration Safety Audit — 2026-07-28

**Branch:** development
**Lens:** Migration safety — lock-on-deploy risk, backfill ordering, online DDL hygiene, reversibility
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- supabase/migrations/20260727110000_connections_surface_key.sql
- supabase/migrations/20260728100000_retire_pinterest.sql
- supabase/migrations/20260728120000_backfill_item_tombstones.sql
- supabase/migrations/20260727100000_catalog_schema.sql
- supabase/migrations/20260727120000_routing_schema.sql
- supabase/migrations/20260727130000_ingest_schema.sql
- supabase/migrations/20260727140000_content_schema.sql
- supabase/migrations/20260727150000_sections_and_documents.sql
- supabase/migrations/20260728130000_brand_asset_refs.sql
- supabase/migrations/20260728150000_field_bindings.sql
- supabase/migrations/20260726200000_pgrst_empty_exposed_schema.sql
- supabase/migrations/CONVENTIONS.md
- scripts/guard-no-unsafe-migrations.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 4 of 4 complete
- P3 Low: 0 of 2 complete

---

## P2 — Should fix

- [x] **#MIG-1** · P2 — Three full-table `UPDATE` backfills on `site.platform_connections` lack idempotency guards
    - **Where:** supabase/migrations/20260727110000_connections_surface_key.sql:65-183
    - **Affects:** Anyone who manually re-runs the backfill portion of this migration outside the normal `supabase_migrations` tracking (e.g. a dev reset that replays statements piecemeal, or a future engineer copy-pasting the pattern). The first and third `UPDATE`s have no `WHERE` guard, so a re-run overwrites any row a human or later code path already corrected.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `WHERE "surface_key" IS NULL` to the first UPDATE and `WHERE "routing_class" IS NULL` to the third, matching the guard the second UPDATE already has.
        - Note for future authors: this specific file is not itself re-runnable end-to-end (the `ADD COLUMN "surface_key" text` statements have no `IF NOT EXISTS`, so a second application fails on the DDL before the backfill logic would even matter) — the guard is about establishing the right habit for the next backfill, not remediating live risk in this file.
    - **Technical:** Per `docs/migration-guidelines.md` §5 and the canonical exemplar (`20260608000000_backfill_subdomain_alias_lifecycle.sql`), every backfill `UPDATE` should carry a `WHERE` clause scoped to "rows still needing it" so the statement is safe to re-issue. Two of the three `UPDATE`s here recompute the column unconditionally. Because the whole file runs as a single implicit transaction (no `CONCURRENTLY` statement, so the Supabase CLI pipelines all statements into one transaction), a mid-file failure rolls back atomically — there is no half-applied-schema risk from this file specifically. The gap is precedent/hygiene for the next backfill that touches a genuinely large or actively-written table, not an active hazard here.
    - **Plain English:** Picture three people touching up the same set of forms, one after another. Two of them only fix the ones that still have a blank; the first and third just redo every form whether it needs it or not. If someone ever hand-corrects one of those forms in between, the next full pass wipes the correction out. It hasn't caused a problem yet because this exact form-run only ever happens once — but it's the wrong habit to carry into the next big cleanup job, where redoing everything unconditionally could be slow and could genuinely erase a fix someone made in the meantime.
    - **Evidence:**
        ```sql
        UPDATE "site"."platform_connections" SET "surface_key" = CASE "platform"
            WHEN 'apple-music' THEN 'apple_music.artist'
            ...
            WHEN 'online-ordering' THEN 'partna.order_link'
            ELSE NULL END;

        UPDATE "site"."platform_connections"
            SET "surface_key" = 'partna.custom_link'
            WHERE "surface_key" IS NULL;

        UPDATE "site"."platform_connections" SET "routing_class" = CASE
            WHEN "surface_key" IN (
                'x.profile','tiktok.profile', ...
            ) THEN 'social'
            ...
            ELSE 'link' END;
        ```

- [x] **#MIG-2** · P2 — `ADD CONSTRAINT ... CHECK` and two `SET NOT NULL` statements on `site.platform_connections` skip the `NOT VALID` split
    - **Where:** supabase/migrations/20260727110000_connections_surface_key.sql:185-189
    - **Affects:** `site.platform_connections` — a real, populated table (created in the `20260726000000` baseline, not a brand-new one), though not one of the project's four formally-designated hot tables (`site.design_kits`, `site.sites`, `site.blocks`, `core.users` — `scripts/guard-no-unsafe-migrations.php`'s `HOT_TABLES` const). Currently zero rows on production (`core.users = 0`, per `CLAUDE.md`), so today's lock exposure is nil; the pattern matters for the next table this shape gets copied onto once prod carries real customers.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Use `ADD CONSTRAINT platform_connections_routing_class_check CHECK (...) NOT VALID`, then `VALIDATE CONSTRAINT` in a separate transaction/file, per `CONVENTIONS.md` §2.
        - For the two `SET NOT NULL`s, use the four-step pattern in `CONVENTIONS.md` §3 (`ADD CHECK ... NOT VALID` → backfill → `VALIDATE` → `SET NOT NULL`).
    - **Technical:** Both patterns are exactly what `scripts/guard-no-unsafe-migrations.php` Check 3 and Check 4 test for generically (not gated on the `HOT_TABLES` list — those checks apply to any populated table) — this file would have failed CI on both checks were it not for the file-level `-- guard:no-unsafe-migrations:disable-file` marker. The lock class is `ACCESS EXCLUSIVE` with a full-table scan; on a table with "hundreds of rows" (per the migration's own comment) that's milliseconds, and with prod currently empty it's a no-op — so this is a hygiene/precedent finding, not an active lockup risk today.
    - **Plain English:** There's a fast way and a slow way to add a new rule to an existing table. The slow way locks the whole table while it checks every existing row against the rule; the fast way checks new entries immediately but verifies old entries in the background without blocking anyone. This migration used the slow way three times. Right now that's harmless — the table is small and there are no real customers using it yet — but it's the kind of shortcut that turns into a real outage once the table is busy, so it's worth fixing the pattern now while it's cheap.
    - **Evidence:**
        ```sql
        ALTER TABLE "site"."platform_connections" ALTER COLUMN "surface_key" SET NOT NULL;
        ALTER TABLE "site"."platform_connections" ALTER COLUMN "routing_class" SET NOT NULL;
        ALTER TABLE "site"."platform_connections"
            ADD CONSTRAINT "platform_connections_routing_class_check"
            CHECK ("routing_class" IN ('social', 'content', 'events', 'shop', 'booking', 'reservations', 'ordering', 'link', 'ignore'));
        ```

- [x] **#MIG-3** · P2 — Six sequential `CREATE`/`DROP INDEX` statements on `site.platform_connections` without `CONCURRENTLY`
    <!-- ALSO closed a hole the finding did not name: scripts/guard-no-unsafe-migrations.php's Checks 1, 5 and 7 used [\w.]+/preg_quote patterns that cannot cross a double quote, and every identifier in this codebase's DDL is quoted — so those checks matched NOTHING and the guard had been passing CI while inspecting nothing. Repaired (quote-normalisation + a new Check 9 for GENERATED..STORED + a justification requirement on disable-file markers), with before/after proof on the original unsafe content. The justification check itself failed its first review — it scanned raw SQL, so an incidental 'reason' in a string literal satisfied it — and was re-fixed to anchor on comment prose only, then re-reviewed against 7 adversarial inputs. -->
    - **Where:** supabase/migrations/20260727110000_connections_surface_key.sql:192-207
    - **Affects:** Same table as MIG-2 — real but non-hot, zero rows in prod today.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Split each `CREATE UNIQUE INDEX` / `CREATE INDEX` and each `DROP INDEX` into its own single-statement companion file using `CONCURRENTLY`, per `CONVENTIONS.md` §1 (exemplar: `20260610000001_analytics_v2_click_indexes.sql`).
    - **Technical:** `scripts/guard-no-unsafe-migrations.php` Check 1 (`CREATE INDEX` without `CONCURRENTLY`) applies to any table with pre-existing data, independent of the `HOT_TABLES` allowlist — the exemption is only for indexes on tables/columns created in the *same* file, which doesn't apply here since the indexed columns (`user_id`, `resource_id`, etc.) predate this migration. Each of the four `CREATE (UNIQUE) INDEX` and two `DROP INDEX` statements takes `ACCESS EXCLUSIVE` for its duration. At "hundreds of rows" and zero prod data today this is sub-second; flagged for the precedent it sets, matching MIG-2's root cause and file.
    - **Plain English:** Same idea as the previous item — six operations in a row that each briefly lock the whole table while it's touched up, rather than the "keep working while we fix it in the background" version. Trivial today because the table is essentially empty in production; the fix is about not carrying the habit forward.
    - **Evidence:**
        ```sql
        DROP INDEX "site"."idx_platform_connections_unique_active";
        DROP INDEX "site"."idx_platform_connections_canonical";
        DROP INDEX "site"."idx_platform_connections_user_platform_sort";
        CREATE UNIQUE INDEX "idx_platform_connections_unique_active"
            ON "site"."platform_connections" ("user_id", "surface_key", "resource_id")
            WHERE ("deleted_at" IS NULL);
        CREATE UNIQUE INDEX "idx_platform_connections_canonical"
            ON "site"."platform_connections" ("user_id", "surface_key", "canonical_key")
            WHERE (("canonical_key" IS NOT NULL) AND ("deleted_at" IS NULL));
        CREATE INDEX "idx_platform_connections_user_surface_sort"
            ON "site"."platform_connections" ("user_id", "surface_key", "sort_order")
            WHERE ("deleted_at" IS NULL);
        CREATE UNIQUE INDEX "idx_platform_connections_primary_per_class"
            ON "site"."platform_connections" ("user_id", "routing_class")
            WHERE ("is_primary" AND "deleted_at" IS NULL);
        ```

- [x] **#MIG-4** · P2 — `DROP COLUMN` + `ADD COLUMN ... GENERATED ... STORED` rewrites `site.platform_connections` under a pattern the guard script doesn't check for at all
    - **Where:** supabase/migrations/20260727110000_connections_surface_key.sql:211-229
    - **Affects:** Same table again — real but non-hot, zero rows in prod today.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - If a future migration needs a `STORED` generated column on a table that might carry real data, split it into its own file and check row count / expected lock duration first, or consider `VIRTUAL`-style computation in the read path instead.
        - Consider adding a Check 9 to `scripts/guard-no-unsafe-migrations.php` for `ADD COLUMN ... GENERATED ... STORED` on a populated table — none of the current eight checks catch this pattern, which is a real gap in the guard, independent of this specific file's low current risk.
    - **Technical:** `GENERATED ALWAYS AS (...) STORED` forces a full table rewrite under `ACCESS EXCLUSIVE` because the value must be materialized — this is a different mechanism than a volatile `DEFAULT` and isn't covered by any of the guard's eight existing checks (confirmed by reading `scripts/guard-no-unsafe-migrations.php` in full: Checks 1–8 cover indexes, FK/CHECK `NOT VALID`, `SET NOT NULL`, hot-table timeouts, `CONCURRENTLY` bundling, and `VALIDATE` bundling — none match `GENERATED ... STORED`). At "hundreds of rows" dev-scale and zero rows in prod today, the rewrite is instant; this is flagged as a genuine guard-coverage gap worth closing before the pattern is reused on a bigger table.
    - **Plain English:** Adding a "pre-computed" column that Postgres has to physically write into every row means rebuilding the whole table while nobody else can read or write it — like re-stamping a field into every folder in a filing cabinet before anyone can open the drawer again. Right now the cabinet is nearly empty, so this takes no time at all. The real finding is that our own automated safety checker doesn't know to look for this pattern — worth teaching it before this trick gets used on a bigger table.
    - **Evidence:**
        ```sql
        ALTER TABLE "site"."platform_connections" DROP COLUMN "platform";
        ALTER TABLE "site"."platform_connections" ADD COLUMN "platform" text
            GENERATED ALWAYS AS (CASE "surface_key"
                WHEN 'apple_music.artist' THEN 'apple-music'
                WHEN 'apple_podcasts.show' THEN 'apple-podcast'
                ...
                ELSE split_part("surface_key", '.', 1)
            END) STORED NOT NULL;
        ```

## P3 — Nice to have

- [ ] **#MIG-5** · P3 — Seven new-schema migrations create tables without `SET LOCAL lock_timeout`/`statement_timeout`
    - **Where:** supabase/migrations/20260727100000_catalog_schema.sql, 20260727120000_routing_schema.sql, 20260727130000_ingest_schema.sql, 20260727140000_content_schema.sql, 20260727150000_sections_and_documents.sql, 20260728130000_brand_asset_refs.sql, 20260728150000_field_bindings.sql
    - **Affects:** Deploy pipeline robustness only — every table these files create is brand new (verified by reading all seven in full: no file runs `ALTER TABLE`/`UPDATE` against `site.design_kits`, `site.sites`, `site.blocks`, or `core.users`, the only tables `HOT_TABLES` names and the only ones Check 5 of `scripts/guard-no-unsafe-migrations.php` gates on). No lock contention is possible on a table that doesn't exist yet.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Optionally wrap each file's DDL in `BEGIN; SET LOCAL lock_timeout = '2s'; SET LOCAL statement_timeout = '10s'; ... COMMIT;` for defense-in-depth against a hung deploy pipeline, per `docs/migration-guidelines.md` §8.
    - **Technical:** None of these files touch a `HOT_TABLES` member directly, so Check 5 doesn't and shouldn't fire on them — this is not a CI gap, just optional insurance. Cost is two lines per file; benefit is a fast, clear failure instead of an indefinite hang if a future migration reuses one of these files as a template against a table that does carry contention.
    - **Plain English:** These files create brand-new, empty tables, so there's nothing for them to collide with — the safety timer genuinely isn't needed today. Adding it anyway costs nothing and means that if someone copies one of these files as a starting point for a future change on a busier table, the timer comes along for free.
    - **Evidence:**
        ```sql
        -- (beginning of 20260727100000_catalog_schema.sql — no SET LOCAL statements present)
        -- Catalog schema — the compiled platform catalog's DB projection (plan §1).
        CREATE SCHEMA IF NOT EXISTS "catalog";
        ```

- [ ] **#MIG-6** · P3 — `retire_pinterest.sql` updates `site.platform_connections` without a lock/statement timeout
    - **Where:** supabase/migrations/20260728100000_retire_pinterest.sql:20-25
    - **Affects:** Deploy pipeline robustness — `site.platform_connections` is not in `HOT_TABLES`, and the `UPDATE` is scoped to `WHERE surface_key = 'pinterest.profile' AND deleted_at IS NULL`, touching a handful of rows at most.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Optionally add `SET LOCAL lock_timeout = '2s'; SET LOCAL statement_timeout = '10s';` before the `UPDATE`s for a clean failure instead of an indefinite wait if a concurrent write happens to hold a conflicting lock on one of the matched rows.
    - **Technical:** Same reasoning as MIG-5: this isn't a `HOT_TABLES` member, so the guard's Check 5 correctly doesn't fire, and the targeted `WHERE` clause keeps the affected row count tiny regardless. This is cheap insurance, not a fix for an active gap.
    - **Plain English:** This cleanup only touches the handful of connections belonging to the one retired platform, so it's inherently low-risk. Adding a short timeout just means that in the rare case someone is updating their Pinterest connection at the exact moment this runs, the deploy fails fast and cleanly instead of waiting around.
    - **Evidence:**
        ```sql
        UPDATE "site"."platform_connections"
           SET "deleted_at" = now(),
               "is_active" = false,
               "updated_at" = now()
         WHERE "surface_key" = 'pinterest.profile'
           AND "deleted_at" IS NULL;
        ```

## Suggested Bundled Sessions

None — every finding here edits or proposes editing a `supabase/migrations/` file (schema/migration change), which the standalone rule below always excludes from bundling.

## Standalone — do NOT bundle

- **#MIG-1 — Backfill idempotency guards** · DB migration/schema change.
- **#MIG-2 — CHECK/SET NOT NULL without NOT VALID split** · DB migration/schema change.
- **#MIG-3 — Index operations without CONCURRENTLY** · DB migration/schema change.
- **#MIG-4 — STORED generated column rewrite + guard coverage gap** · DB migration/schema change; also touches the shared CI guard script (`scripts/guard-no-unsafe-migrations.php`), which every future migration relies on.
- **#MIG-5 — Missing timeout guards on new-schema migrations** · DB migration/schema change.
- **#MIG-6 — Missing timeout guard on retire_pinterest.sql** · DB migration/schema change.
