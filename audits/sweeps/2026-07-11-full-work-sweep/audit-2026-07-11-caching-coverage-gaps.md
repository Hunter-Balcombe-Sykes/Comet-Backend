# Caching Coverage Gaps Audit — 2026-07-11

**Branch:** HEAD
**Lens:** Caching coverage gaps — hot, expensive reads with no cache at all (public sitepage resolution, handle/profile resolution, account-capability lookups, dashboard controllers, synchronous vendor reads)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `app/Http/Controllers/Api/PublicSite/AnalyticsController.php`
- `app/Http/Controllers/Api/PublicSite/BootstrapController.php`
- `app/Http/Controllers/Api/PublicSite/PublicEarlyAccessController.php`
- `app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php`
- `app/Http/Controllers/Api/PublicSite/PublicMenuController.php`
- `app/Http/Middleware/AddPublicCacheHeaders.php`
- `app/Http/Middleware/Auth/{EnsurePartnaAdmin,EnsurePartnaStaff,RequireAal2,VerifySupabaseJwt}.php`
- `app/Http/Middleware/Context/{EnforcePendingDeletionReadOnly,LoadCurrentUser}.php`
- `app/Http/Middleware/{IdempotencyKey,VerifyBotToken}.php`
- `app/Http/Middleware/Logging/{LogLeadRateLimits,RecordStaffAuditEntry}.php`
- `app/Http/Middleware/Moderation/PerTargetReportThrottle.php`
- `app/Services/Accounts/{AccountCapabilities,AccountCapabilitySet}.php`
- `app/Services/Cache/{CacheKeyGenerator,SiteCacheService,UserCacheService}.php`
- `app/Services/FeatureAvailability/{FeatureAvailability,UserFeatureAvailability}.php`
- `app/Services/PublicSite/{IndividualProfilePayloadBuilder,SiteActionsService,SitepageDataResolverService}.php`
- `app/Services/Site/{ContentSelectionService,UpdateSiteAction}.php`
- `app/Services/Platforms/**/*.php` (all connect/fetch/highlights strategies, scrapers, registry, payloads)
- `app/Services/Analytics/ContentPopularityReader.php` (adjudicator addition)
- `app/Http/Controllers/Api/PublicSite/IndividualProfileController.php` (adjudicator addition)
- `routes/api.php`, `routes/api/publicSite.php`, `bootstrap/app.php` (adjudicator addition — route/middleware verification)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 0 complete

---

No findings survived adjudication. Summary of verification performed beyond DeepSeek's three chunks:

- **Public profile payload (`IndividualProfileController::show`)** — confirmed the canonical reference implementation: `handle.resolve` cache (30s, `rememberLocked`) → `public.profile:{handle}:{updated_at_ts}` cache (60s, `rememberLocked`, SWR via `SiteCacheService`/`CacheLockService`) wraps the *entire* `IndividualProfilePayloadBuilder::build()` call, which is where the expensive fan-out (`SitepageDataResolverService::presentPageIds`, `getGallery`, `getLinks`, `ContentSelectionService::resolve`, `ContentPopularityReader::forSite`, design-kit read, etc.) actually lives. None of that fan-out is a coverage gap — it's already inside the cache boundary.
- **Auth path (`LoadCurrentUser` → `UserCacheService::getByAuthId`)** — confirmed two-level cache (30min immutable id-map + 60s SWR hydrated-model cache via `CacheLockService::rememberLockedNullable`), matching the doctrine's canonical reference implementation.
- **`PublicMenuController` / `PublicIntegrationController`** — confirmed both routes (`/public/profiles/{handle}/menu`, `/integrations`, `/platforms`) fall under `AddPublicCacheHeaders::CACHEABLE_PATH_PREFIXES` (`api/public/profiles`), which is appended to the global `api` middleware group in `bootstrap/app.php`. Every response gets `Cache-Control: public, max-age=900, s-maxage=900` — the CDN is genuinely the cache layer here, matching the explicit design comment in `PublicIntegrationController`. `ContentPopularityReader::forSite()` (called directly by both controllers, outside any backend cache) is a single indexed `WHERE site_id = ?` returning a small per-site row set — not an aggregate/join/JSONB-scan, so it doesn't clear the "expensive" bar even setting the CDN aside.
- **`AccountCapabilities::for()`** — per-request `WeakMap` memo; the underlying computation reads already-hydrated `User` attributes with no DB query except `staffRole()` for staff accounts (rare, single indexed lookup) — not a coverage gap under the lens (memoization scope matches the value's actual invalidation lifetime; a Redis-level cache would add invalidation surface for a value cheaper than the lookup that would invalidate it).
- **`FeatureAvailability::for()`** — already implements the category-5 canonical fix pattern exactly (`Cache::remember` behind a version token bumped on write).
- **`MenuSource`, `AppleSearch`** — confirmed per-instance memoization / `Cache::get`+`Cache::put` wrapper respectively; both are single indexed reads or already cached vendor calls.

No read in scope is simultaneously hot, expensive, and repeated with zero cache of any kind. This is a clean result, not an unscanned one.

## Suggested Bundled Sessions

None.

## Standalone — do NOT bundle

None.
