`★ Insight ─────────────────────────────────────`
- The Cloudflare Worker routes all `<handle>.partna.au` traffic through its own Cache API (24h primary + 7d stale shadow, push-purged). The platform payload path is thus a Service Binding call to the Astro SSR worker — not a direct API hit — which means edge-level caching of subrequests is controlled by the Astro worker code (in the separate `partna-pages` repo), not by this codebase. Backend Redis caching on top of that would add a second staleness layer against a push-invalidate architecture.
- `AccountCapabilities::for()` uses a `WeakMap` for request-scoped memoization: pure attribute calculation against an already-loaded Eloquent model, no DB roundtrip. Zero cost to cache, zero need for Redis.
`─────────────────────────────────────────────────`

# Caching Coverage Gaps — 2026-06-06

**Branch:** development
**Lens:** Caching coverage gaps: hot, expensive reads with no cache at all
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Http/Controllers/Api/Platforms/AppleController.php
- app/Http/Controllers/Api/Platforms/EventbriteController.php
- app/Http/Controllers/Api/Platforms/FacebookController.php
- app/Http/Controllers/Api/Platforms/FreshaController.php
- app/Http/Controllers/Api/Platforms/InstagramController.php
- app/Http/Controllers/Api/Platforms/ShopifyController.php
- app/Http/Controllers/Api/Platforms/TiktokController.php
- app/Http/Controllers/Api/Platforms/YoutubeController.php
- app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php
- app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php
- app/Http/Controllers/Api/PublicSite/PublicConfigController.php
- app/Http/Middleware/AddPublicCacheHeaders.php
- app/Models/Core/Site/IntegrationConnection.php
- app/Observers/Core/IntegrationConnectionObserver.php
- app/Services/Accounts/AccountCapabilities.php
- app/Services/Cache/CacheLockService.php
- app/Services/Cache/CacheKeyGenerator.php
- app/Services/Cache/SiteCacheService.php
- app/Services/Cache/UserCacheService.php
- app/Services/Cloudflare/CloudflareKvService.php
- app/Services/Cloudflare/CloudflarePurgeService.php
- app/Services/Platforms/AppleSearch.php
- app/Services/Platforms/EventbriteScraper.php
- app/Services/Platforms/InstagramScraper.php
- app/Services/Platforms/PlatformRefresher.php
- app/Services/Platforms/PlatformScraper.php
- app/Services/Platforms/ShopifyScraper.php
- app/Services/Platforms/YoutubeScraper.php
- app/Services/Platforms/YoutubeThumbnailResolver.php
- app/Services/SmartLinks/SafeUrlFetcher.php
- app/Console/Commands/RefreshIntegrationConnectionsCommand.php
- app/Console/Commands/EnforcePlatformLinkCapCommand.php
- app/Jobs/Cache/AggregateCacheMetricsJob.php
- app/Jobs/Cache/WarmPublicSiteCacheJob.php
- app/Jobs/Cloudflare/CloudflareCachePurgeJob.php
- app/Jobs/Cloudflare/RetireSubdomainFromKvJob.php
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php
- app/Listeners/RecordCacheMetrics.php
- cloudflare-worker/src/index.js
- routes/api/integrations.php
- supabase/migrations/20260602150238_create_platform_connections.sql

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 0 complete

---

*No caching coverage gaps were identified in this scope after applying the three-part bar.*

**Adjudication notes (why the draft conclusion holds):**

**Public platforms endpoint (`PublicIntegrationController::show`)** — This is the only read in scope that sits on the hot public-site path (unauthenticated, per-request). The controller explicitly documents the correct design: no Redis backend cache because the Cloudflare edge cache (24 h primary + 7 d stale-shadow, push-purged by `IntegrationConnectionObserver` → `CloudflareCachePurgeJob` → `purgeHandle()`) is the caching layer. The DB query itself uses a covered partial index (`idx_platform_connections_user_platform_sort` on `user_id, platform, sort_order WHERE deleted_at IS NULL AND is_active`), so the raw query cost is an indexed FK scan — not an expensive multi-table join or aggregate. A Redis layer on top would add staleness against an already-correct invalidation strategy.

**Capability lookups (`AccountCapabilities::for`)** — Pure attribute computation from an already-loaded `User.status` string. No DB query, no vendor call. Memoized per-request via `WeakMap`. Cost is O(1) in-process; Redis caching would be pure overhead.

**Dashboard platform vendor reads** (`/api/platforms/*/recent`, `/api/platforms/fresha/team`, Apple/YouTube/Shopify highlight endpoints) — All sit behind `user.api` middleware (authenticated) and represent individual user configuration actions: opening the highlight picker, viewing the team member list, browsing products. These are not on the hot multi-caller public-site rendering path and are not concurrently shared across callers — each is scoped to a single authenticated user performing a one-time setup action. Two out of three bar criteria fail (not multi-caller / not hot in the defined sense).

**Static vendor reads** (TikTok, Facebook `connect`) — Pure link normalisation; no vendor API call at all.

**Reference data** (`PublicConfigController::socialPlatforms`, `::integrations`) — HTTP `Cache-Control: public, max-age=3600` delegates caching to the CDN/browser layer, appropriate for deploy-stable config that the frontend caches at app load.

**Already cached reads** — `YoutubeThumbnailResolver` caches per-video maxres verdicts for 30 days. `SiteCacheService` covers the public-site payload with single-flight + jitter + SWR. `UserCacheService` covers the auth path with a 60 s hydrated-model cache. `ShopifyController::setProducts` uses a 10-minute catalog warm from `brandProducts`. None of these are absent-cache gaps.
