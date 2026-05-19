# Cache Invalidation Dispatch Audit — 2026-05-20

**Branch:** feat/28-7-cloudflare-cache-purge-service-job
**Lens:** cache invalidation dispatch enumeration across all site mutation paths
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `app/Observers/Brand/BrandStoreSettingsObserver.php`
- `app/Observers/Core/CustomerObserver.php`
- `app/Observers/Core/ProfessionalIntegrationObserver.php`
- `app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalServiceCategoryController.php`
- `app/Observers/Core/ServiceObserver.php`
- `app/Services/Cache/ProfessionalCacheService.php` (referenced)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [ ] **CACHE-1** · P2 — Service category mutations do not invalidate the professional's services cache
    - **Where:** `app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalServiceCategoryController.php` — all write methods: `store`, `update`, `destroy`, `reorder`, `restore`
    - **Affects:** Dashboard `/api/me` and `GET /api/services` (hot path) served from `ProfessionalCacheService::getDashboardServices` (30-min TTL, single-flight). After a category is created, renamed, deleted, or reordered, the cached view remains stale for up to 30 minutes.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `ServiceCategoryObserver` that calls `app(ProfessionalCacheService::class)->invalidateProfessional($pro)` on `created`, `updated`, `deleted`, and `restored` events. Wire it to `ServiceCategory` in `AppServiceProvider`.
        - Alternatively, inject `ProfessionalCacheService` into the controller and call `invalidateProfessional` after each transaction commit — but the observer approach is preferred because it covers all future write paths automatically, matching the pattern already established by `ServiceObserver`.
    - **Technical:** `ProfessionalServiceController::index` delegates to `ProfessionalCacheService::getDashboardServices` on the uncached hot path (no `grouped`, no `include_archived`, no source filter). `ServiceObserver::bust()` fires on `Service` saved/deleted/restored events and calls `invalidateProfessional`, but `ServiceCategory` has no observer at all. Category renames (which change group headings), deletions (which reassign services to null), and reorders all change the grouping shape that the dashboard renders, yet none trigger a cache bust. The 30-minute TTL means a professional could see phantom categories or stale grouping for half an hour after a destructive action.
    - **Plain English:** Imagine your service menu is printed on a card that only gets reprinted every 30 minutes. If you rename a section, delete a category, or shuffle the order, the card keeps showing the old layout until the next automatic reprint. Your dashboard will look wrong until then, with no way to force a refresh.
    - **Evidence:**
        ```php
        // ProfessionalServiceCategoryController::store — no cache invalidation after commit
        $category = DB::transaction(function () use ($pro, $data) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["service-categories:{$pro->id}"]);
            // ...
            $category = ServiceCategory::query()->create([
                'professional_id' => $pro->id,
                'title' => $data['title'],
                'sort_order' => $data['sort_order'],
            ]);
            return $category->fresh();
        });
        return $this->success(['category' => $category], 201);
        ```

- [ ] **CACHE-2** · P2 — BrandStoreSettingsObserver busts the primary cache key but not the stale-while-revalidate copy
    - **Where:** `app/Observers/Brand/BrandStoreSettingsObserver.php:43-47`
    - **Affects:** The `/api/me` dashboard endpoint that reads brand store settings (commission rate, theme, payout hold days). During the SWR stale window the old value survives and is served to users who hit the endpoint between primary-key deletion and the first fresh write.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Cache::forget($key)` with `Cache::deleteMultiple([$key, $key.':stale'])` to match the pattern already used by `ProfessionalIntegrationObserver` and `CustomerObserver`.
    - **Technical:** The dashboard cache helper writes a fresh value at the primary key and keeps the last good response at `$key.':stale'` as a fallback during single-flight lock contention. When the primary key is deleted the SWR layer can still serve the stale copy to any request that arrives before the next miss triggers a recompute. `CustomerObserver::invalidateCount` and `ProfessionalIntegrationObserver::bustHydrogenBrandConfig` both delete both keys with `Cache::deleteMultiple([$key, $key.':stale'])`. `BrandStoreSettingsObserver` is the only outlier that only clears the primary.
    - **Plain English:** Think of a whiteboard with two sections: the top has the latest store settings, the bottom has a recent backup copy. When someone updates the settings, we erase the top section. But if a customer looks at the board right after the erase, before the top is rewritten, they'll read the backup copy instead of seeing blank space. We need to erase both sections at the same time so no one reads outdated information.
    - **Evidence:**
        ```php
        // BrandStoreSettingsObserver::bust — only the primary key is forgotten
        try {
            $key = CacheKeyGenerator::brandStoreSettings($professionalId);
            Cache::forget($key);
        } catch (\Throwable $e) {
            Log::warning('BrandStoreSettings cache invalidation failed', [
                'professional_id' => $professionalId,
                'message' => $e->getMessage(),
            ]);
        }
        ```

`★ Insight ─────────────────────────────────────`
**Observer coverage gap pattern:** The codebase uses observers as the authoritative bust layer (Master Pattern 15), but `ServiceCategory` has no observer — all category write paths go through the controller directly with no hook. This is a systematic gap: any future category write path added to the codebase would also miss the bust unless an observer exists.

**SWR key naming convention:** The `:stale` suffix is a project-wide convention for the stale-while-revalidate copy. `CustomerObserver` and `ProfessionalIntegrationObserver` already encode this correctly with `deleteMultiple`. Auditing for callers that only call `Cache::forget($key)` (not `deleteMultiple`) on any key that participates in a SWR cache is a useful sweep to add to future audits.

**Tier calibration note:** Both findings are correctly P2 — they cause stale dashboard data for known TTL windows but do not corrupt permanent state or expose cross-tenant data. Neither rises to P1 because the bad behavior (stale UI data) self-heals within the TTL and doesn't represent a "documented known scenario" that Shopify or Stripe would trigger adversarially.
`─────────────────────────────────────────────────`
