# Scaling Antipatterns Audit — 2026-07-10

**Branch:** audit-fix/analytics-master-2026-07-10
**Lens:** Scaling antipatterns: write amplification, rebuild-on-write, weak caching
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Http/Resources/Content/ContentLibraryUploadResource.php
- app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php
- app/Http/Resources/Platforms/ShopBrandResource.php
- app/Http/Resources/PublicSite/IndividualProfileResource.php
- app/Http/Resources/SiteResource.php
- app/Http/Resources/Staff/StaffSiteResource.php
- app/Http/Resources/UserDashboardResource.php
- app/Http/Resources/UserPublicResource.php
- app/Http/Resources/UserStaffResource.php
- app/Http/Resources/WorkplaceResource.php
- app/Jobs/Analytics/RecordAnalyticsEventJob.php
- app/Notifications/Moderation/AccountBannedNotification.php
- app/Notifications/Moderation/AccountSuspendedNotification.php
- app/Notifications/Moderation/ReportOutcomeNotification.php
- app/Observers/Core/IntegrationConnectionObserver.php
- app/Observers/User/UserObserver.php
- app/Services/Analytics/AnalyticsCacheService.php
- app/Services/Analytics/AnalyticsEvent.php
- app/Services/Analytics/AnalyticsQueryService.php
- app/Services/Analytics/ContentFreshness.php
- app/Services/Analytics/ContentPopularityReader.php
- app/Services/Analytics/Writers/PostgresEventWriter.php
- app/Services/Cache/SiteCacheService.php
- app/Services/Cache/UserCacheService.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 0 complete

---

## P1 — Fix before pilot launch

- [ ] **CACHE-1** · P1 — Section-dwell UPDATE has no supporting index for its preferred lookup column, and runs one SELECT+UPDATE pair per event with no batching
    - **Where:** app/Services/Analytics/Writers/PostgresEventWriter.php:340-382; supabase/migrations/20260526000000_baseline_standalone_user.sql:1241-1243
    - **Affects:** The analytics ingest pipeline (`analytics` queue) for any sitepage receiving concurrent traffic on the same section — a viral post drives many visitors dwelling on the same `(site_id, section_key)` within the same 24h window.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a partial composite index supporting the dwell lookup's preferred (`visitor_id`) branch: `CREATE INDEX CONCURRENTLY section_views_dwell_visitor_idx ON analytics.section_views (site_id, section_key, visitor_id, occurred_at DESC) WHERE visitor_id IS NOT NULL;` — as its own non-transactional migration file, mirroring the split-file pattern already used for `section_views_block_id_idx` (`20260624010100_section_views_block_id_idx.sql`, CONCURRENTLY needs no `BEGIN`/`COMMIT`).
        - Add the `session_id` fallback equivalent (`WHERE session_id IS NOT NULL`) for the branch used when `visitorId` is absent.
        - Batching `writeMany`'s dwell loop into a bulk-load + CTE update is optional follow-up work, not a blocker — no caller currently invokes `writeMany` with more than one event (`write()` always calls `writeMany([$event])`), so there's no batch to collapse today; revisit if/when the "future BufferedIngestor" mentioned in the class docblock actually lands.
    - **Technical:** `applySectionDwell` prefers `visitor_id` over `session_id` when both are present (lines 350-352: `$e->visitorId !== null ? ['visitor_id', ...] : ['session_id', ...]`), and issues `WHERE site_id = ? AND section_key = ? AND visitor_id = ? AND occurred_at >= ? ORDER BY occurred_at DESC LIMIT 1`. `analytics.section_views` has `(site_id, section_key, occurred_at DESC)` and `(session_id, section_key)` indexes (baseline migration, lines 1241-1243) but nothing covering `visitor_id` — the column the writer prefers. Under the existing `site_section_occurred_idx`, Postgres range-scans every row for that site+section in the last 24h and filters `visitor_id` in-index/heap; cost grows with that section's view volume, which is exactly the traffic pattern a viral sitepage produces (thousands of concurrent visitors dwelling on the same hot section). This is a same-day-shipped gap: the `duration_ms` column and dwell-annotation logic landed via commit `2b387257` / migration `20260710120000_add_section_views_duration_ms.sql`, and no index migration accompanied it. Separately, the per-event SELECT+UPDATE pair has no batching — but I confirmed via `RecordAnalyticsEventJob` and both `QueuedIngestor`/`SyncIngestor` that `writeMany` is never actually called with more than one event today, so the batching gap is architecturally real but not yet load-bearing; the missing index is the concrete, present-day risk.
    - **Plain English:** When someone reads a section of your page for a while, the system needs to find that page-view's paper record in a filing cabinet before it can add "read for X seconds" to it — but the filing cabinet isn't indexed by the visitor's name, only roughly by page and time. Normally that's fine because there aren't many records to flip through. But if one page suddenly goes viral and thousands of people are reading the same section at once, that filing cabinet's "page and time" drawer fills up with thousands of slips, and now every single lookup has to flip through all of them by hand instead of jumping straight to the right one. Adding a proper index is like adding a visitor-name tab to that drawer — a small, cheap fix that keeps lookups fast exactly when traffic spikes.
    - **Evidence:**
        ```php
        // Prefers visitor_id (not indexed) over session_id (indexed) for the dwell lookup:
        [$idColumn, $idValue] = $e->visitorId !== null
            ? ['visitor_id', $e->visitorId]
            : ['session_id', $e->sessionId];
        ```
        ```php
        $targetId = DB::connection('pgsql')->table('analytics.section_views')
            ->where('site_id', $e->siteId)
            ->where('section_key', $e->sectionKey)
            ->where($idColumn, $idValue)
            ->where('occurred_at', '>=', Carbon::parse($e->occurredAt)->subDay()->toISOString())
            ->orderByDesc('occurred_at')
            ->limit(1)
            ->value('id');
        ```
        ```sql
        CREATE INDEX section_views_professional_occurred_idx ON analytics.section_views (professional_id, occurred_at DESC);
        CREATE INDEX section_views_site_section_occurred_idx ON analytics.section_views (site_id, section_key, occurred_at DESC);
        CREATE INDEX section_views_session_section_idx ON analytics.section_views (session_id, section_key);
        ```

## Suggested Bundled Sessions

None.

## Standalone — do NOT bundle

- **CACHE-1 — Missing supporting index for section-dwell lookup** · reason: DB migration/schema change (new index on `analytics.section_views`).
