# Test↔Prod Schema Parity Audit — 2026-08-18

**Branch:** HEAD
**Lens:** Test↔prod schema parity — application writes that pass SQLite CI but violate Postgres constraints
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- supabase/migrations/20260819001000_link_observations_allow_commerce_probe.sql
- supabase/migrations/20260819001100_item_media_role_video.sql
- tests/Pest.php
- app/Jobs/Platforms/GoogleBusinessEnrichJob.php
- app/Jobs/Platforms/ScanPreviousWebsiteContentJob.php
- app/Observers/Core/IntegrationConnectionObserver.php
- app/Http/Controllers/Api/Platforms/DisplaySettingsController.php
- app/Http/Controllers/Api/Platforms/FreshaController.php
- app/Http/Controllers/Api/Platforms/GenericPlatformController.php
- app/Http/Controllers/Api/Platforms/RefreshController.php
- app/Http/Controllers/Api/Routing/RoutingController.php
- app/Ingest/Projection/InstagramMediaProjector.php
- app/Ingest/Projection/ProjectionWriter.php
- app/Services/Shop/ShopContentWriter.php
- app/Services/PublicSite/IndividualProfilePayloadBuilder.php
- (plus cross-reference reads: supabase/migrations/20260726000000_baseline_pilot.sql, supabase/migrations/20260819000100/000110/000120_content_storefronts_*.sql, supabase/migrations/20260813100000/100001_create_content_storefronts*.sql, tests/Postgres/ShopStorefrontUpsertConflictTest.php, app/Jobs/Platforms/MenuFetchJob.php)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 1 complete

---

## P3 — Nice to have

- [ ] **#PARITY-1** · P3 — `content.storefronts.user_id` is nullable in the SQLite test stand-in but `NOT NULL` in production; the gap is currently harmless but uncovered
    - **Where:** tests/Pest.php:3209-3237 (`setupContentTables()`)
    - **Affects:** Any *future* write path into `content.storefronts` that forgets to set `user_id` — CI would stay green while Postgres 500s. No currently-shipping writer has this bug.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Leave the SQLite stand-in as-is (it already documents *why* it can't mirror the constraint — SQLite can't express the partial unique index `storefronts_user_provider_ref_uq` that makes `user_id` meaningful, and the real invariant is already pinned in `tests/Postgres/ShopStorefrontUpsertConflictTest.php`).
        - No code change needed today — this is a test-coverage note, not a bug fix. Absorb opportunistically only if `content.storefronts` is touched for other reasons: add a one-line comment pointer from `setupContentTables()` to the PG-lane test so a future editor doesn't miss that the real guarantee lives elsewhere.
    - **Technical:** `supabase/migrations/20260819000100_content_storefronts_user_id.sql` promotes `content.storefronts.user_id` to `NOT NULL` in production (step 6, `ALTER COLUMN user_id SET NOT NULL`), confirmed also by the identical end-state note in `tests/Postgres/ShopStorefrontUpsertConflictTest.php`'s own faithful DDL. `tests/Pest.php`'s SQLite stand-in leaves the column `TEXT NULL` (deliberately, per its own comment: SQLite can't express the partial unique index this column exists to support, so tightening the SQLite copy would only break fixtures over a rule this engine cannot enforce). I verified both real production write paths — `ShopContentWriter::upsertStore()` (app/Services/Shop/ShopContentWriter.php:96, comment cites the same migration) and `MenuFetchJob::syncOrderPlatforms()` (app/Jobs/Platforms/MenuFetchJob.php:650, comment explicitly names 20260819000100 and the insert-fails-if-omitted risk) — and both always set `user_id` on every insert/upsert row. There is no reachable write path today that omits it; the SQLite gap is real but currently masks nothing that's actually broken. This is a documented, deliberate drift with an existing Postgres-lane test backstop, not an oversight — kept at P3 as a "the seed still can't catch a *new* regression" note rather than dropped, per the lens's category-1 guidance to name seed gaps even when no current writer trips them.
    - **Plain English:** The real database requires every "store" record to be stamped with its owner; the practice database used for tests doesn't enforce that rule because the practice database can't express a more advanced rule that depends on it. The two places that actually create these records both remember to stamp the owner correctly, and there's already a separate, more faithful practice run (the "Postgres lane") that checks this specific behaviour. So nothing is broken today — this is just a reminder that if a third place ever writes one of these records without a code review catching the omission, the ordinary fast tests wouldn't catch it either.
    - **Evidence:**
        ```php
        // tests/Pest.php, setupContentTables()
        -- Re-home Task 11 (20260819000100): the owner is denormalised here so
        -- store identity (user_id, provider, external_ref) is enforceable in
        -- one table. NULLABLE in this stand-in where production is NOT NULL —
        -- SQLite cannot express the partial unique index that makes it
        -- meaningful either, so the real constraint is pinned in the PG lane
        -- (tests/Postgres/ShopStorefrontUpsertConflictTest.php). Tightening it
        -- here would only fail fixtures over a rule this engine cannot enforce.
        user_id TEXT NULL
        ```
        ```sql
        -- supabase/migrations/20260819000100_content_storefronts_user_id.sql, Step 6
        ALTER TABLE content.storefronts ALTER COLUMN user_id SET NOT NULL;
        ```

## Suggested Bundled Sessions

None — a single low-effort, no-current-impact item; not worth a dedicated session. Fold it into any future session that already has `content.storefronts` open (per the repo's opportunistic-fix policy), rather than scheduling it.

## Standalone — do NOT bundle

None.
