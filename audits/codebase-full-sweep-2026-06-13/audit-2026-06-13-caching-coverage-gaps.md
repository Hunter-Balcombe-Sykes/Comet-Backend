# Caching Coverage Gaps Audit — 2026-06-13

**Branch:** development
**Lens:** Caching coverage gaps: hot, expensive reads with no cache at all
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-5`
**Source files audited:**
- `app/Services/PublicSite/PublicSiteResolver.php`
- `app/Services/PublicSite/SitepageDataResolverService.php`
- `app/Services/PublicSite/IndividualProfilePayloadBuilder.php`
- `app/Services/Accounts/AccountCapabilities.php`
- `app/Services/Accounts/AccountCapabilitySet.php`
- `app/Services/Cache/SiteCacheService.php`
- `app/Services/Cache/CacheLockService.php`
- `app/Services/Cache/UserCacheService.php`
- `app/Services/Cache/CacheKeyGenerator.php`
- `app/Services/Analytics/AnalyticsCacheService.php`
- `app/Services/Analytics/AnalyticsQueryService.php`
- `app/Services/Streaming/LiveStatusInjector.php`
- `app/Services/Streaming/LiveStatusPoller.php`
- `app/Services/Platforms/PlatformRefresher.php`
- `app/Http/Middleware/Context/LoadCurrentUser.php`
- `app/Http/Middleware/AddPublicCacheHeaders.php`
- `app/Http/Controllers/Api/PublicSite/IndividualProfileController.php`
- `app/Http/Controllers/Api/PublicSite/PublicSiteController.php`
- `app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php`
- `app/Http/Controllers/Api/PublicSite/AnalyticsController.php`
- `app/Http/Controllers/Api/User/Analytics/UserAnalyticsController.php`
- `app/Http/Controllers/Api/User/Notifications/NotificationController.php`
- `app/Http/Controllers/Api/User/Account/UserSelfController.php`
- `app/Services/Notifications/NotificationListingService.php`
- `cloudflare-worker/src/index.js`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 0 complete

---

No CCG findings. Every read on the hot paths in scope passes through an existing cache layer or is cheap enough that caching would add invalidation surface for no benefit.

**Canonical coverage map verified:**

| Hot path | Cache layer | Evidence |
|---|---|---|
| Public profile payload (`GET /api/public/profiles/{handle}`) | Two-layer: 30 s handle.resolve (single-flight) + full payload keyed on `updated_at_ts` with SWR | `IndividualProfileController::show()` ll. 70–116 |
| Public site payload (`GET /api/public/site-by-slug`) | SWR + single-flight via `SiteCacheService::getPublicSitePayload()` | `SiteCacheService.php` ll. 134–267 |
| Auth user resolution (`LoadCurrentUser` middleware) | `UserCacheService::getByAuthId()` → `rememberLockedNullable` with 60 s TTL + SWR | `UserCacheService.php` ll. 147–165 |
| Dashboard entry point (`GET /me`) | `UserCacheService` (services, customer count) + `SiteCacheService::getSiteLinkBlocks()` | `UserSelfController::show()` ll. 56–59 |
| Analytics summary dashboard | `AnalyticsCacheService::summary()` → `rememberLocked`, version-token invalidation | `AnalyticsCacheService.php` ll. 82–86 |
| Notification bell poll | `NotificationListingService::index()` → `rememberLocked`, 15 s TTL, bust on mark-read/dismiss | `NotificationListingService.php` ll. 29–37 |
| `AccountCapabilities::for()` | PHP `WeakMap` per-request memo | `AccountCapabilities.php` ll. 17–30 |
| Streaming live status (`LiveStatusInjector`) | Reads Redis keys written by `LiveStatusPoller` (scheduled job); no DB on any request path | `LiveStatusInjector.php` ll. 70–71 |
| Platform vendor reads (`PlatformRefresher`) | All vendor scrapes run inside scheduled cron jobs, not on any request path | `PlatformRefresher.php` ll. 45–107 |

**Reads that were evaluated and intentionally not cached:**

- **`AnalyticsQueryService::liveVisitors()`** (`COUNT` on `analytics.site_sessions`, polled every ~15 s from the analytics dashboard): fails the multi-caller bar — the value is per-user and changes every few seconds; caching it per `user_id` would save at most one DB round-trip per 15 s window per user with zero cross-request sharing. At pre-beta scale this is not a material load.

- **`ResolvesSiteFromRequest::resolveSiteFromData()`** on analytics event endpoints: the hot path sends `site_id`, resolved via PK lookup (`whereKey`). The lens explicitly excludes single indexed PK lookups as "not expensive."

- **`PublicIntegrationController::show()`**: handle → `user_id` is an indexed single-value lookup; the integrations query (`idx_platform_connections_user_platform_sort`) is index-scanned. The controller comment records the deliberate decision to rely on the 24 h Astro edge-page cache (Cloudflare Worker `caches.default.put` on every SSR hit) rather than a backend Redis layer; the backend endpoint is only called on Astro cache misses, making it infrequent in practice.
