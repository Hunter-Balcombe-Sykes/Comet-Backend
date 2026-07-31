# Caching Coverage Gaps Audit — 2026-07-28

**Branch:** development
**Lens:** Caching coverage gaps — hot, expensive reads with no cache at all (absence-only; existing-but-weak caches are out of scope, owned by `caching-gold-standard.md`)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- app/Services/PublicSite/SiteActionsService.php
- app/Services/PublicSite/SitepageDataResolverService.php
- app/Services/PublicSite/IndividualProfilePayloadBuilder.php
- app/Http/Controllers/Api/PublicSite/IndividualProfileController.php
- app/Http/Controllers/Api/User/SiteManagement/UserSiteActionsController.php
- app/Console/Commands/ComputeContentPopularityScores.php
- app/Services/Cache/SiteCacheService.php
- app/Services/Cache/CacheKeyGenerator.php
- app/Services/Cache/CacheLockService.php
- app/Services/Accounts/AccountCapabilities.php
- (Group B/C/D scope files reviewed against DeepSeek's "no findings" chunks — no additional gaps surfaced)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [ ] **#CCG-1** · P2 — Dashboard actions/pages picker recomputes a ~10-query pool on every load with no cache
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserSiteActionsController.php:33 (calling into) app/Services/PublicSite/SiteActionsService.php:89-242 (`pool()`)
    - **Affects:** Every professional loading their dashboard "Pages" / "Action buttons" design controls (`GET` via `UserSiteActionsController::show`). Each hit re-derives the full action pool from scratch.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap `SiteActionsService::pool()`'s result in `CacheLockService::rememberLocked()` inside `UserSiteActionsController::show()` (or push the wrap into `SiteActionsService` itself so both callers benefit), keyed via a new `CacheKeyGenerator::siteActionsPool($site->id)` entry — following the exact pattern already used for `CacheKeyGenerator::sitePopularityRanks()` and `siteBlocks()`.
        - Wire invalidation: `pool()`'s inputs span `IntegrationConnection`, `Block` (links), `Service`, `SiteMedia` (gallery), and `Menu` writes. Confirm each of these already busts through an existing observer (`SiteObserver`, `BlockObserver`, `IntegrationConnectionObserver`, `ServiceObserver` per the Redis invalidation contract in `CLAUDE.md`) and add the new key to whichever bust path(s) are missing it — do not ship the cache without confirming this, or the dashboard will show stale action pools after a connection/link edit.
        - Do NOT wrap the call inside `IndividualProfilePayloadBuilder` (app/Services/PublicSite/IndividualProfilePayloadBuilder.php:106) — that consumer already sits behind `IndividualProfileController`'s `rememberLocked` single-flight cache (`CacheKeyGenerator::publicProfile`, app/Http/Controllers/Api/PublicSite/IndividualProfileController.php:124-139), so `pool()` there only runs on cache miss. Also leave `ComputeContentPopularityScores`'s call (app/Console/Commands/ComputeContentPopularityScores.php:364) alone — it's a scheduled command, not a hot request path.
    - **Technical:** `pool()` fans out into roughly ten DB round-trips per call: `loadSections()` (1 query), `presentPageIds()` (up to 6 conditional queries — active-connections fetch, Google Business `display_settings` lookup, fetched-menu `exists()`, active-services `exists()`, live-link-block `exists()`, ready-gallery-media `exists()`), `getLinks()` (1 query), `linkBlockCreatedAt()` (1 query), and `pool()`'s own direct `IntegrationConnection::query()->where('user_id', ...)->where('is_active', true)->get(...)` (1 query — duplicates the connections fetch already done inside `presentPageIds()`). None of this is behind `Cache::` or `rememberLocked` on the `UserSiteActionsController::show()` path — confirmed by reading both files end-to-end. This clears the three-part bar: it's a named dashboard controller under `app/Http/Controllers/Api/User` (hot path), the fan-out is a real multi-query cost with several `exists()` probes (expensive), and the same professional re-triggers the identical recompute on every visit to that settings page within a TTL window (repeated). The public-payload consumer of the same method is already correctly cache-wrapped one layer up, so this finding is scoped strictly to the uncached dashboard entry point.
    - **Plain English:** Every time a professional opens the "Pages" or "Action buttons" tab in their dashboard, the system re-checks — from scratch, against the database — whether they have a shop, events, a menu, active social connections, gallery photos, and more, roughly ten separate lookups, even if nothing has changed since the last time they opened that same tab a minute ago. The public-facing version of this same page already has this shortcut in place; the dashboard's own settings view does not. The fix is to remember the answer for a short while and only redo the full check when something the professional actually edits (a new connection, a new link, a menu update) would change the outcome.
    - **Evidence:**
        ```php
        // UserSiteActionsController.php:33 — no Cache:: or rememberLocked anywhere in this action:
        $pool = $actions->pool($professional, $site);
        ```
        ```php
        // SiteActionsService.php:89-111
        public function pool(User $pro, ?Site $site, ?Collection $sections = null, ?array $booking = null): array
        {
            if ($site === null) {
                return [];
            }

            $sections ??= $this->resolver->loadSections($site);
            $booking ??= $this->resolver->getBooking($site, $sections);

            $caps = AccountCapabilities::for($pro);
            $present = array_flip($this->resolver->presentPageIds($site, $caps, $sections));
            $links = $this->resolver->getLinks($site, $booking);
            $linkCreatedAt = $this->linkBlockCreatedAt($site);

            $connectionsByPlatform = [];
            foreach (
                IntegrationConnection::query()
                    ->where('user_id', $pro->id)
                    ->where('is_active', true)
                    ->get(['id', 'user_id', 'platform', 'resource_id', 'payload', 'created_at']) as $conn
            ) {
                $connectionsByPlatform[strtolower((string) $conn->platform)][] = $conn;
            }
        ```
        ```php
        // SitepageDataResolverService.php:198-289 — presentPageIds()'s conditional query fan-out
        $connections = $this->safeQuery(
            fn () => IntegrationConnection::query()
                ->where('user_id', $userId)
                ->where('is_active', true)
                ->get(['id', 'user_id', 'platform', 'payload'])
                ->all(),
            [], 'active_integration_connections', $site,
        );
        // ...
        $gbConn = $this->safeQuery(
            fn () => IntegrationConnection::query()
                ->where('user_id', $userId)
                ->where('platform', 'google-business')
                ->where('is_active', true)
                ->first(['display_settings']),
            null, 'google_business_connection_display_settings', $site,
        );
        // ... plus fetched_menu_exists, active_services_exists, live_link_block_exists, ready_gallery_media_exists
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Dashboard actions-pool caching:** #CCG-1
    - **Why grouped:** single finding, single file pair (`UserSiteActionsController` + `SiteActionsService`), one cohesive fix.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

None.
