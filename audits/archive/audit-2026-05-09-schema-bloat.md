`★ Insight ─────────────────────────────────────`
- DeepSeek's "no Eloquent model = unused" heuristic produced one false positive: `analytics.booking_events` is actively used via raw `DB::table('analytics.booking_events')` in `BookingAnalyticsController` (2 sites) and `PublicBookingController` (3 sites). The model-less search pattern misses raw-query consumers — keep the table.
- The Phase-4 cleanup migration (`20260506500000_drop_legacy_aggregates.sql`) dropped 8 `*_metrics_*` aggregates but left `analytics.professional_customer_daily` behind. It has the same shape (orphaned aggregate, no readers in PHP) and should have been in that DROP statement.
- The `headshot_*` cluster has a sibling `icon_*` cluster on the same table and a parallel `banner_*` cluster on `site.sites` — all three were scaffolded for an old "buckets + paths" media model that got replaced by `site.site_media` (pool=design). One migration cleans up all three.
`─────────────────────────────────────────────────`

# Schema Bloat Audit — 2026-05-09

**Branch:** development
**Lens:** Unused columns, dead tables, orphaned indexes, and unreferenced schema bloat
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated manually after Sonnet adjudication timed out at 200k context
**Source files audited:**
- supabase/migrations/20260403000000_v2_baseline.sql
- supabase/migrations/20260420200000_add_rls_to_remaining_tables.sql
- supabase/migrations/20260506500000_drop_legacy_aggregates.sql
- supabase/migrations/20260507000000_drop_booking_aggregates.sql
- All other migrations under supabase/migrations/
- app/Models/ (all subdirectories) — used to confirm absence of fillable/cast/accessor coverage

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 5 of 5 complete ✅
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [x] **#SCHEMA-1** · P2 — Duplicate index on `analytics.site_visits (professional_id, occurred_at)`
    - **Where:** supabase/migrations/20260403000000_v2_baseline.sql:1143-1144
    - **Affects:** Write throughput on `site_visits` — every INSERT pays double B-tree maintenance for two functionally identical indexes. Storage doubled for the index pair.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Pick one of the two duplicate indexes to keep (`analytics_site_visits_professional_occurred_idx` is the more namespaced name; prefer it for consistency with surrounding indexes).
        - Add a new migration: `DROP INDEX IF EXISTS analytics.site_visits_professional_time_idx;`
        - Leave `site_visits_pro_date_range_idx` alone — that one has `DESC` ordering plus an `INCLUDE (country_code, device_type)` covering clause and is functionally distinct.
        - Verify no app code references the dropped name explicitly (greps clean — neither name is referenced from PHP).
    - **Technical:** Two indexes were created with identical column lists and ordering: `analytics_site_visits_professional_occurred_idx ON (professional_id, occurred_at)` and `site_visits_professional_time_idx ON (professional_id, occurred_at)`. Postgres maintains both on every write but the planner can only use one at a time, so the second is pure overhead. Dropping it costs zero query plans (the surviving index is identical) and saves an index's worth of storage plus per-INSERT/UPDATE write amplification.
    - **Plain English:** Two identical filing folders in the same cabinet. Every time a new page comes in, you have to file it twice — but you only ever look in one folder. Removing the duplicate makes filing faster and frees up space.
    - **Evidence:**
        ```sql
        CREATE INDEX analytics_site_visits_professional_occurred_idx ON analytics.site_visits (professional_id, occurred_at);
        CREATE INDEX site_visits_professional_time_idx ON analytics.site_visits (professional_id, occurred_at);
        CREATE INDEX site_visits_pro_date_range_idx ON analytics.site_visits (professional_id, occurred_at DESC) INCLUDE (country_code, device_type);
        ```

- [x] **#SCHEMA-2** · P2 — Duplicate index on `analytics.link_clicks (professional_id, occurred_at)`
    - **Where:** supabase/migrations/20260403000000_v2_baseline.sql:1173, 1176
    - **Affects:** Write throughput on `link_clicks` (the highest-volume analytics table). Same shape as #SCHEMA-1.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Drop `link_clicks_professional_time_idx`, keep `analytics_link_clicks_professional_occurred_idx`.
        - Add to the same migration as #SCHEMA-1.
        - Leave `link_clicks_pro_date_range_idx` alone — DESC ordering + `INCLUDE (link_block_id)` covering clause makes it functionally distinct.
    - **Technical:** Two indexes with identical `(professional_id, occurred_at)` definitions. Same redundancy as site_visits — drop one. Note that `link_clicks` is touched on every public link click event, so the write-side savings are larger here than on `site_visits`.
    - **Plain English:** Same as SCHEMA-1 but on the link-clicks table.
    - **Evidence:**
        ```sql
        CREATE INDEX analytics_link_clicks_professional_occurred_idx ON analytics.link_clicks (professional_id, occurred_at);
        CREATE INDEX link_clicks_pro_date_range_idx ON analytics.link_clicks (professional_id, occurred_at DESC) INCLUDE (link_block_id);
        CREATE INDEX link_clicks_professional_time_idx ON analytics.link_clicks (professional_id, occurred_at);
        ```

- [x] **#SCHEMA-3** · P2 — `analytics.professional_customer_daily` orphaned by the Phase-4 cleanup
    - **Where:** supabase/migrations/20260403000000_v2_baseline.sql:1353-1365 (table + index), supabase/migrations/20260420200000_add_rls_to_remaining_tables.sql:538-551 (RLS policies)
    - **Affects:** Storage and maintenance for an aggregate table that no production code reads or writes. The RLS policy attached to it adds per-row evaluation overhead for any future query that does hit the table.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Confirm the frontend doesn't read this table directly via PostgREST (the RLS policies suggest it might have been intended for direct frontend access at some point — worth a 30-second check).
        - If unused, add to the same drop migration with the indexes: `DROP TABLE analytics.professional_customer_daily;` (RLS policies and the index drop with it).
        - Optional: in the migration message, reference `20260506500000_drop_legacy_aggregates.sql` so the lineage is clear.
    - **Technical:** The Phase-4 cleanup migration (`20260506500000_drop_legacy_aggregates.sql`) explicitly dropped 8 legacy aggregate tables (`brand_metrics_daily`, `brand_metrics_hourly`, `brand_affiliate_daily`, `brand_commission_daily`, `professional_metrics_daily`, `professional_metrics_hourly`, `site_metrics_daily`, `site_metrics_hourly`) — all `*_metrics_*` aggregates. `professional_customer_daily` matches the same orphaned-aggregate pattern but was scoped to "customer counts" rather than "metrics" and slipped through the cleanup. No Eloquent model exists for it, no `DB::table('analytics.professional_customer_daily')` calls exist anywhere in `app/`, and the new analytics architecture (commerce.brand_affiliate_rollup + live queries on raw event tables) doesn't need it.
    - **Plain English:** When the team did a big cleanup of old reporting tables, they got eight of them but missed this one. It's the same kind of dead weight — data that gets stored but never looked at.
    - **Evidence:**
        ```sql
        -- Defined in v2 baseline:
        CREATE TABLE IF NOT EXISTS analytics.professional_customer_daily (
            day date NOT NULL,
            affiliate_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
            timezone text NOT NULL,
            customers_count integer NOT NULL DEFAULT 0,
            new_customers_count integer NOT NULL DEFAULT 0,
            returning_customers_count integer NOT NULL DEFAULT 0,
            updated_at timestamptz NOT NULL DEFAULT now(),
            PRIMARY KEY (day, affiliate_professional_id, timezone)
        );

        -- RLS policies added later (20260420200000):
        ALTER TABLE analytics.professional_customer_daily ENABLE ROW LEVEL SECURITY;
        CREATE POLICY professional_customer_daily_pro_select ON analytics.professional_customer_daily FOR SELECT TO authenticated ...;
        ```
        Grep result: zero `professional_customer_daily` references in `app/`.

- [x] **#SCHEMA-4** · P2 — Dead `headshot_*` and `icon_*` columns on `core.professionals`
    - **Where:** supabase/migrations/20260403000000_v2_baseline.sql:212-235 (column definitions + CHECK constraints)
    - **Affects:** Schema clarity and storage. Four `text` columns with default values, two CHECK constraints that fire on every INSERT/UPDATE, and a database view (`core.public_site_payload` or similar — see `p.headshot_bucket AS professional_headshot_bucket` reference) that materializes them. New developers reading the Professional model see these in the schema and wonder where the read paths are.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Confirm zero references to `icon_bucket`, `icon_path`, `headshot_bucket`, `headshot_path` outside the v2 baseline migration. (Greps clean — the only PHP references are absent; only schema-level refs remain in the v2 baseline + one view that aliases `headshot_bucket`.)
        - Audit the view `core.public_site_payload` (or wherever the `professional_headshot_bucket` alias lives at line 1724 of the baseline) and update it to drop the column reference, OR drop the view if it's also unused.
        - Migration: `ALTER TABLE core.professionals DROP CONSTRAINT IF EXISTS professionals_headshot_bucket_when_path; DROP CONSTRAINT IF EXISTS professionals_icon_bucket_when_path; DROP COLUMN IF EXISTS headshot_bucket, DROP COLUMN IF EXISTS headshot_path, DROP COLUMN IF EXISTS icon_bucket, DROP COLUMN IF EXISTS icon_path;`
        - Consider rolling this together with #SCHEMA-5 (banner cluster) into one "media-bucket-cleanup" migration since the rationale is identical.
    - **Technical:** The v2 baseline carries the `(*_bucket, *_path)` pair pattern that came from an earlier "named bucket per asset type" media architecture. The current product uses `site.site_media` (with a `pool` column gating `design` vs other media types) and the `BrandDesignMediaService` writer (per the user's `feedback_design_token_storage` memory). Professional model `$fillable` is `[professional_type, ...account fields...]` — none of `icon_*` or `headshot_*` appear. Zero accessor or cast references. The CHECK constraints add nontrivial INSERT-time cost: `headshot_path IS NULL OR headshot_bucket IS NOT NULL` evaluates on every row write, even when the columns are perpetually NULL. Three places to clean up: (a) column drops on `core.professionals`, (b) constraint drops, (c) the view alias at line 1724 that selects `p.headshot_bucket AS professional_headshot_bucket`.
    - **Plain English:** This is the user's example, plus three more dead columns alongside it. Old profile-photo and icon slots from a prior version of the product. Today the photos live in the `site_media` table. Drop the four old columns, the two integrity constraints they had, and update the one database view that still mentions one of them.
    - **Evidence:**
        ```sql
        -- supabase/migrations/20260403000000_v2_baseline.sql:212-217
        icon_bucket text DEFAULT 'public-assets',
        icon_path text,
        headshot_bucket text DEFAULT 'public-assets',
        headshot_path text,

        -- :230-232
        CONSTRAINT professionals_headshot_bucket_when_path CHECK ((headshot_path IS NULL) OR (headshot_bucket IS NOT NULL)),
        CONSTRAINT professionals_icon_bucket_when_path CHECK ((icon_path IS NULL) OR (icon_bucket IS NOT NULL)),

        -- :1724 (view aliases the dead column):
        p.headshot_bucket AS professional_headshot_bucket,
        ```
        Grep `icon_bucket\|icon_path\|headshot_bucket\|headshot_path` across `app/**/*.php`: zero matches.

- [x] **#SCHEMA-5** · P2 — Dead `banner_bucket` and `banner_path` columns on `site.sites`
    - **Where:** supabase/migrations/20260403000000_v2_baseline.sql (column definitions + CHECK constraint)
    - **Affects:** Schema clarity. Same shape as #SCHEMA-4 — two columns plus a CHECK constraint that fire on every site INSERT/UPDATE.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Confirm zero references (greps clean).
        - Roll into the same migration as #SCHEMA-4.
        - `ALTER TABLE site.sites DROP CONSTRAINT IF EXISTS sites_banner_bucket_when_path; DROP COLUMN IF EXISTS banner_bucket, DROP COLUMN IF EXISTS banner_path;`
    - **Technical:** Site model `$fillable` is `[subdomain, theme_id, is_published, settings]` — no banner fields. Brand banner imagery now flows through `site.site_media` (pool=design) per `feedback_design_token_storage`. Same bucket/path scaffolding as the headshot/icon cluster, dropped for the same reason.
    - **Plain English:** Old banner-image slots that got replaced by the unified media system. Empty frames with a check-the-frame-is-valid rule that runs every time a site row is touched. Safe to remove.
    - **Evidence:**
        ```sql
        banner_bucket text DEFAULT 'public-assets',
        banner_path text,
        CONSTRAINT sites_banner_bucket_when_path CHECK ((banner_path IS NULL) OR (banner_bucket IS NOT NULL))
        ```
        Grep `banner_bucket\|banner_path` across `app/**/*.php`: zero matches.

---

## Suggested Bundled Sessions

- **Bundle: `schema-bloat-cleanup`** — #SCHEMA-1, #SCHEMA-2, #SCHEMA-3, #SCHEMA-4, #SCHEMA-5. All five are `DROP` operations on Postgres, all have evidence verified by direct grep, and the rollback story is identical (revert the migration). Together they fit a single migration file (`drop_legacy_schema_bloat.sql`) and one short PR. The two media-cluster items (#SCHEMA-4, #SCHEMA-5) share the same rationale ("replaced by site.site_media pool=design") so the migration message can cover both succinctly. Total schema-side blast radius: 0 PHP changes, 1 view edit (the `professional_headshot_bucket` alias on line 1724 of the baseline), 5 column drops, 3 constraint drops, 2 index drops, 1 table drop.

## Rejected by adjudication

- **DeepSeek #SCHEMA-3 ("`booking_events` has no model and looks unused"):** false positive. The table is read 5 times via raw `DB::table('analytics.booking_events')` from `BookingAnalyticsController` and `PublicBookingController`. The "no Eloquent model = unused" heuristic doesn't catch raw-query consumers. Keep the table.
