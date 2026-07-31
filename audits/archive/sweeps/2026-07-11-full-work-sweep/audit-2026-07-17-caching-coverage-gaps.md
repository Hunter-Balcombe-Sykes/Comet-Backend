# Caching Coverage Gaps Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Caching coverage gaps — hot, expensive reads with no cache at all (absence-only; the inverse of the gold-standard-adherence lens)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php
- app/Http/Controllers/Api/PublicSite/PublicMenuController.php
- app/Services/Accounts/AccountCapabilities.php
- app/Services/Accounts/AccountCapabilitySet.php
- app/Services/Accounts/LifestyleConnectionCleanup.php
- app/Services/PublicSite/IndividualProfilePayloadBuilder.php
- app/Services/PublicSite/SiteActionsService.php
- app/Services/PublicSite/SitepageDataResolverService.php
- app/Services/Analytics/ContentPopularityReader.php (pulled in for cross-check — shared by both findings)
- app/Http/Controllers/Api/PublicSite/IndividualProfileController.php, app/Http/Middleware/Context/LoadCurrentUser.php, routes/api.php, app/Http/Controllers/Api/ApiController.php (pulled in to confirm the existing cache boundary before flagging gaps)
- app/Services/Platforms/* (24 files — no findings; reads are job/connect-time, not hot-path)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 1 complete

---

## P2 — Should fix

- [ ] **#CCG-2** · P2 — Popularity-rank read is cached on the profile payload path but hits Postgres uncached on two sibling public endpoints
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php:105, app/Http/Controllers/Api/PublicSite/PublicMenuController.php:70-73, app/Services/Analytics/ContentPopularityReader.php:33-46
    - **Affects:** Every unauthenticated viewer of a professional's `/platforms` (shop-product ranks) and `/menu` (menu-item/category ranks) subpages — a Postgres round-trip on every single request, with no TTL or memoisation, for a value that only changes every ~15 minutes.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a `CacheKeyGenerator::sitePopularityRanks(string $siteId)` key.
        - Wrap both call sites' `$this->popularity->forSite(...)` in `CacheLockService::rememberLocked($key, $ttl, fn () => $this->popularity->forSite($siteId))`, TTL matched to (or slightly under) the `analytics:compute-popularity` cadence — no push-invalidate needed since the compute job already tolerates the same staleness window the 60s public-profile cache accepts elsewhere.
        - No behavioural change: `forSite()` itself is untouched, only its two uncached call sites gain the wrapper.
    - **Technical:** `ContentPopularityReader::forSite()`'s own class docblock states the read is "behind the 60s public-profile cache" — true only for its call site inside `IndividualProfilePayloadBuilder::build()` (itself wrapped in `CacheLockService::rememberLocked`, confirmed in `IndividualProfileController.php:101`). `PublicIntegrationController::show()` and `PublicMenuController::show()` call the exact same reader directly, with no `Cache::`/`rememberLocked` anywhere in either controller or in `forSite()` — every unauthenticated request re-issues the `analytics.content_popularity_scores` query. This is the identical read shape the codebase already treats as cache-worthy in one location; the other two public per-visitor endpoints were never wired into that pattern. At the platform's stated scale target (a single profile going viral), this is a fan-out of concurrent, uncached, identical reads against one Postgres primary for data that a 15-minute-cadence batch job is the only writer of.
    - **Plain English:** Imagine a shop that repaints its "today's bestsellers" sign once every 15 minutes, but two of its three doors have an employee re-checking the stockroom in person every single time a customer walks through — even though the sign was already just painted and everyone can see it. The data barely changes, but the code re-fetches it from the database on every page view for two specific pages (the menu page and the platforms/shop page), when it's already treated as a cacheable value on the main profile page. If a professional's page suddenly goes viral, that's a lot of unnecessary simultaneous database hits for information that hasn't changed in minutes.
    - **Evidence:**
        ```php
        // app/Services/Analytics/ContentPopularityReader.php — class docblock's cache assumption:
         * Payload builders call forSite() once per build and annotate their content
         * arrays / pageOrder from the returned maps. One indexed read per build
         * (site_id, content_type, rank), behind the 60s public-profile cache.
        ```
        ```php
        // app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php:98-106 — direct, uncached call:
        $shopLinkMode = null;
        $productRanks = [];
        if ($connections->has('shop')) {
            $site = Site::query()->where('user_id', $userId)->first(['id', 'shop_link_mode']);
            $shopLinkMode = $site?->shop_link_mode;
            // shop-product ranks annotate each product with a nullable
            // popularityRank on the public wire (inert until ONE consumes it).
            $productRanks = $this->popularity->forSite($site?->id)['shop_product'] ?? [];
        }
        ```
        ```php
        // app/Http/Controllers/Api/PublicSite/PublicMenuController.php:70-73 — direct, uncached call:
        $siteId = Site::query()->where('user_id', $userId)->value('id');
        $ranks = $this->popularity->forSite($siteId);
        $categoryRanks = $ranks['menu_category'] ?? [];
        $itemRanks = $ranks['menu_item'] ?? [];
        ```

## P3 — Nice to have

- [ ] **#CCG-1** · P3 — `presentPageIds()`'s 7-query fan-out runs twice inside one `build()` call
    - **Where:** app/Services/PublicSite/SitepageDataResolverService.php:174-309 (`presentPageIds`), app/Services/PublicSite/IndividualProfilePayloadBuilder.php:97-109 (`build`), app/Services/PublicSite/SiteActionsService.php:94-95 (`pool`)
    - **Affects:** Every public sitepage payload cache miss (behind the 60s `rememberLocked` wrapper) — doubles the presence-probe query fan-out on each miss, though single-flight locking means this cost isn't multiplied across concurrent viewers of the same handle.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Compute `presentPageIds()` once in `IndividualProfilePayloadBuilder::build()` and thread the resulting `list<string>` into both the page-order branch and into `SiteActionsService::pool()` as an optional pre-resolved parameter (mirroring how `$sections`/`$booking`/`$ranks` are already injectable into `pool()`).
        - This is request-scoped de-duplication only — no Redis/`rememberLocked` needed, since the whole `build()` output is already behind the public-profile cache.
    - **Technical:** `IndividualProfilePayloadBuilder::build()` resolves `$pageOrder` via either `buildPageOrder()` (which internally calls `presentPageIds()`) or, in manual-order mode, calls `presentPageIds()` directly — then unconditionally calls `$this->actions->pool($pro, $site, $sections, $booking, $ranks)`, whose first two lines call `AccountCapabilities::for($pro)` (cheap, WeakMap-memoized) and `$this->resolver->presentPageIds($site, $caps, $sections)` again. With identical `$site`/`$caps`/`$sections` inputs, this is a second full run of the ~7-query presence-probe fan-out (`IntegrationConnection` pluck, conditional `ShopProduct` exists, GB `display_settings` first, `Menu` exists, `Service` exists, links `Block` exists, gallery `SiteMedia` exists) inside the same `build()` invocation, every time. Impact is bounded to one cache-miss execution per 60s window per site (not per concurrent viewer), since `IndividualProfileController` wraps the whole build in `CacheLockService::rememberLocked` (confirmed single-flight) — this keeps the finding at P3 rather than P2.
    - **Plain English:** Picture a receptionist who, for one visitor, walks through a checklist of "does this person have a menu? gallery photos? services? links?" — then, thirty seconds later for the exact same visitor, walks through the identical checklist again from scratch before handing over the final folder. Nothing changed between the two passes. It only wastes effort once per cache refresh (not once per website visitor), so it's low-priority polish rather than an urgent fix.
    - **Evidence:**
        ```php
        // app/Services/PublicSite/IndividualProfilePayloadBuilder.php:97-109
        $pageOrder = $ordering['smart_page_order']
            ? $this->resolver->buildPageOrder($site, $caps, $sections, $ranks['page'] ?? [])
            : $this->actions->applyManualPageOrder(
                $this->resolver->presentPageIds($site, $caps, $sections),
                $ordering['manual_page_order'],
            );

        $rankedActions = $this->actions->resolveRankedActions(
            $this->actions->pool($pro, $site, $sections, $booking, $ranks),
            $this->popularity->rankedActionsForSite($site?->id),
            $ordering['smart_actions'],
            $ordering['manual_actions'],
        );
        ```
        ```php
        // app/Services/PublicSite/SiteActionsService.php:94-95 — pool() always recomputes:
        $caps = AccountCapabilities::for($pro);
        $present = $this->resolver->presentPageIds($site, $caps, $sections);
        ```
        ```php
        // app/Services/PublicSite/SitepageDataResolverService.php:194-202 — one of the ~7 probes fanned out per call:
                $platforms = $this->safeQuery(
                    fn () => IntegrationConnection::query()
                        ->where('user_id', $userId)
                        ->where('is_active', true)
                        ->distinct()
                        ->pluck('platform')
                        ->all(),
                    [],
                );
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Public sitepage read-path caching hygiene:** #CCG-1, #CCG-2
    - **Why grouped:** Both are request-scoped/cache-coverage fixes on the same public-profile read family (`IndividualProfilePayloadBuilder` and its sibling public controllers); neither touches auth, money, or schema, and both are small enough to plan+implement together.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).

## Standalone — do NOT bundle

None.
