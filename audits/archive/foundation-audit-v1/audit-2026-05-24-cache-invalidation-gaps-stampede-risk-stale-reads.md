`★ Insight ─────────────────────────────────────`
**Finding CACHE-1 turns on a scenario that can't happen today.** The only "theme change" path in the app (`ProfessionalThemeController::select`) writes `theme_id` on the **site** row, which flows through `UpdateSiteAction → site->save() → SiteObserver::saved → invalidateSite()` — fully covered. There is no endpoint or service that mutates a `Theme` model's config/colors/fonts. `CacheKeyGenerator::theme()` is a dead method. The finding's premise doesn't exist yet, so it's dropped per precision-over-recall rules.
`─────────────────────────────────────────────────`

# Cache Invalidation & Layering Audit — 2026-05-24

**Branch:** development
**Lens:** cache invalidation gaps, stampede risk, stale reads, KV/Redis/HTTP cache layering correctness
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Services/Cache/SiteCacheService.php
- app/Services/Cache/CacheLockService.php
- app/Services/Cache/CacheKeyGenerator.php
- app/Services/Cache/ProfessionalCacheService.php
- app/Observers/Core/BlockObserver.php
- app/Observers/Core/SiteObserver.php
- app/Observers/Core/ServiceCategoryObserver.php
- app/Observers/Core/ServiceObserver.php
- app/Observers/Core/CustomerObserver.php
- app/Observers/Core/SiteMediaObserver.php
- app/Observers/Professional/ProfessionalObserver.php
- app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalThemeController.php
- app/Services/Site/UpdateSiteAction.php
- app/Services/PublicSite/IndividualProfilePayloadBuilder.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 2 complete

---

## P2 — Should fix

- [ ] **CACHE-1** · P2 — SWR fast path serves stale payload without backward-compat healing
    - **Where:** app/Services/Cache/SiteCacheService.php — SWR path (~line 118 return) vs primary-hit path (~line 68–82)
    - **Affects:** The first visitor to a site after the primary key expires, when the `:stale` copy holds a pre-V2-strip payload shape (missing `services`, `legal`, or unsplit `links`/`sections`). That visitor gets a broken public profile response. Given the V2 strip landed on 2026-05-22, any site whose cache was populated before the strip and never busted is in this window right now.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In the SWR fast-path `return` (the block that returns `$stale` when the fill lock isn't acquired), run the same backward-compat guard as the primary path: check `array_key_exists('services', $stale)`, call `ensureBlockCollections()`, add `legal` key when absent.
        - If the stale value is an array but fails the guard (old shape), forget both the primary and stale keys and fall through to `buildPayloadFromDb()` — same behaviour as the primary-path healing already does.
        - If the stale value is the `MISS_SENTINEL`, return `null` (already handled).
    - **Technical:** The primary-hit path at ~line 68 guards `array_key_exists('services', $cached)`, calls `ensureBlockCollections()`, backfills `legal`, and re-writes both keys before returning. If that guard fails it forgets both keys and falls through to a cold rebuild. The SWR fast path (~line 118) does none of this — it returns `$stale` unconditionally (modulo sentinel check). If both the primary and stale copies hold a pre-V2 payload (cache written before the strip, never busted), every request that hits the SWR path serves the broken shape until the stale TTL expires — up to 2.5 hours. The V2 strip merged 2026-05-22 (commit `8e97b901`) makes this an active risk for any site whose cache predates the deploy.
    - **Plain English:** The cache has two layers — a "fresh" copy and a backup "in case the fresh one expires" copy. The fresh copy gets a health check when it's read: if it's an old format, the system rebuilds it from the database. But the backup copy skips that health check entirely. If both copies are from before the recent platform update (which changed what fields a page response must include), every visitor served by the backup copy sees a broken page — missing the services list, missing footer links — for up to 2.5 hours. The fix is to give the backup the same health check as the fresh copy.
    - **Evidence:**
        ```php
        // Primary-hit path — heals old payload shape before returning
        if (is_array($cached)) {
            if (array_key_exists('services', $cached)) {
                $cached = $this->ensureBlockCollections($cached);
                if (! array_key_exists('legal', $cached)) {
                    $cached['legal'] = null;
                }
                // ...
                return $cached;
            }
            Cache::forget($key);
            Cache::forget($staleKey);
        }

        // SWR fast path — returns $stale with NO healing guard
        return $stale === self::MISS_SENTINEL ? null : (is_array($stale) ? $stale : null);
        ```

---

## P3 — Nice to have

- [ ] **CACHE-2** · P3 — BlockObserver double-busts site cache on every block mutation
    - **Where:** app/Observers/Core/BlockObserver.php:69–93 (`onBlockMutated`)
    - **Affects:** Every block create/update/delete fires two full `deleteMultiple` sweeps of the same Redis key set. No correctness impact; doubles unnecessary DEL commands under any block-churn workload.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the direct `$this->siteCache->invalidateSite($block->site)` call from `onBlockMutated`. Rely solely on `$block->site->touch()` to trigger `SiteObserver::saved → invalidateSite()`.
        - Add a comment in `onBlockMutated` explaining that the `touch()` chain is intentional and handles both Redis invalidation and Cloudflare purge dispatch — to prevent a future maintainer from "restoring" the direct call.
        - Verify `SiteObserver::saved` covers all intended keys (it does — `invalidateSite` + `CloudflareCachePurgeJob` + `WarmPublicSiteCacheJob`).
    - **Technical:** `BlockObserver::onBlockMutated` calls `$this->siteCache->invalidateSite($block->site)` first, then immediately calls `$block->site->touch()`. The `touch()` fires an Eloquent `saved` event caught by `SiteObserver::saved`, which calls `invalidateSite()` a second time and additionally dispatches `CloudflareCachePurgeJob` and `WarmPublicSiteCacheJob`. The first `invalidateSite` call is redundant — the `touch()` path does the same work and more. The two calls are idempotent so there is no correctness issue, just wasted Redis DEL commands.
    - **Plain English:** Every time you add or edit a link on your page, the cleanup routine runs twice in a row — once directly, and once when the "site changed" alarm fires. The second run is the one that also refreshes Cloudflare and pre-warms the cache. Removing the first run saves a small amount of server work with no downside.
    - **Evidence:**
        ```php
        // BlockObserver.php — two paths to same destination
        private function onBlockMutated(Block $block, string $action): void
        {
            // ...
            try {
                $this->siteCache->invalidateSite($block->site);  // ← first bust
            } catch (\Throwable $e) { /* ... */ }

            try {
                $block->site->touch();  // ← triggers SiteObserver::saved → invalidateSite AGAIN
            } catch (\Throwable $e) { /* ... */ }
        }
        ```
        ```php
        // SiteObserver::saved — also calls invalidateSite on the same site
        public function saved(Site $site): void
        {
            try {
                $this->siteCache->invalidateSite($site);  // ← second bust of same key set
            } catch (\Throwable $e) { /* ... */ }
            // + CloudflareCachePurgeJob, WarmPublicSiteCacheJob dispatches
        }
        ```

- [ ] **CACHE-3** · P3 — ServiceCategoryObserver nukes the full professional cache when only two services keys need busting
    - **Where:** app/Observers/Core/ServiceCategoryObserver.php:51–66 (`bust`)
    - **Affects:** Every service-category rename, reorder, or delete invalidates 13+ cache keys — including the hydrated User model, three payload variants, two ID-map entries, and the public site payload — when only `professionalDashboardServices` and `professionalServices` (plus their `:stale` copies) are actually stale. The over-invalidation forces extra Postgres round-trips on the next authenticated request for the affected professional.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the `$this->professionalCache->invalidateProfessional($pro)` call with targeted `Cache::forget()` calls for `CacheKeyGenerator::professionalDashboardServices($proId)`, its `:stale` copy, `CacheKeyGenerator::professionalServices($proId)`, and its `:stale` copy.
        - If category names are surfaced in the public site payload (via the `services` section of the cached public payload), also call `app(SiteCacheService::class)->invalidateSite($pro->site)`.
        - The `User` model load can be replaced by reading `$category->professional_id` directly — no Eloquent model needed for targeted key busting.
    - **Technical:** `ServiceCategoryObserver::bust()` loads the full `User` model and passes it to `ProfessionalCacheService::invalidateProfessional()`. That method clears every key in the professional namespace: three payload variants, two ID-map entries, the hydrated model (both primary and stale), both services keys, both dashboard keys, the customer count key, and transitively the site public payload. A category rename only affects the services display — specifically the `professionalDashboardServices` key and the `professionalServices` key. Over-invalidating the hydrated model means the next 60s of authenticated requests each pay a Postgres round-trip for `User + site` instead of hitting Redis.
    - **Plain English:** When you rename a service category (e.g. "Haircuts" → "Styling"), the system throws out every cached fact about your account — your profile, your page, your customer count, everything — when really only the services list needed refreshing. It's like resetting your whole router because one app needed an update. The fix is to only clear the shelf that actually changed.
    - **Evidence:**
        ```php
        // ServiceCategoryObserver.php — full invalidateProfessional for a category-only change
        private function bust(ServiceCategory $category): void
        {
            // ...
            try {
                $this->professionalCache->invalidateProfessional($pro);
                // busts: 3 payload keys, 2 id-map keys, model + stale,
                //        services + stale, dashboard + stale, customerCount + stale, site cache
            } catch (\Throwable $e) { /* ... */ }
        }
        ```
        ```php
        // ProfessionalCacheService::invalidateProfessional — 13+ keys, only 4 relevant here
        $keys = [
            CacheKeyGenerator::professionalPayloadById($professional->id),
            CacheKeyGenerator::professionalPayloadByHandle($handleLc),
            CacheKeyGenerator::professionalPayloadByAuthId($professional->auth_user_id),
            CacheKeyGenerator::professionalIdByHandle($handleLc),
            CacheKeyGenerator::professionalIdByAuthId($professional->auth_user_id),
            $modelKey,
            $modelKey.':stale',
            CacheKeyGenerator::professionalServices($professional->id),
            CacheKeyGenerator::professionalServices($professional->id).':stale',
            CacheKeyGenerator::professionalDashboardServices($professional->id),
            CacheKeyGenerator::professionalDashboardServices($professional->id).':stale',
            CacheKeyGenerator::customerCount($professional->id),
            CacheKeyGenerator::customerCount($professional->id).':stale',
        ];
        ```
