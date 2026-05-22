After walking every file in the provided scope, I find no reads that meet all three CCG criteria simultaneously. Here is the summary of why each candidate domain was cleared:

## Resolution & capability paths (Group A)

- **`ProfessionalCacheService`** — all lookups (`getIdByAuthId`, `getIdByHandle`, `getPayloadById`, `getByAuthId`, `getActiveServices`, `getDashboardServices`, `getBrandStoreSettings`, `getBrandPartnerStatus`, `getCustomerCount`) use `CacheLockService::rememberLocked` or `rememberLockedNullable`. No uncached hot reads.
- **`SiteCacheService::getPublicSitePayload`** — single-flight locked with Redis primary + `:stale` SWR. The warm-cache hydration queries (`resolveImageVariantUrlsInSite`, `enrichSiteWithBrandPartnerRadius`, `applyBrandImageFallbacks`) re-query `MediaVariant`, `Site`, `Professional`, `BrandPartnerLink`, and `BrandDesignMediaService` on every request. This is a cache-quality defect (payload not self-contained → `CCH` lens), not a cache-absence defect — the payload *is* cached.
- **`FeatureFlagService`** — registry, pro overrides, and brand overrides all cached via `CacheLockService::rememberLocked` with per-request memoisation as a fast path. No uncached reads.
- **`VerifySupabaseJwt`** — JWKS fetch cached via `CacheLockService::rememberLocked('supabase:jwks', ...)`, with APCu per-kid PEM caching inside `resolveSigningKey`. The auth-server fallback path is disabled by default (`jwks_fail_closed=true`); the fallback is an intentional source-of-truth call, not a candidate for caching.
- **Middleware**: `LoadCurrentProfessional` delegates to the cached `ProfessionalCacheService`. `VerifyShopifySessionToken`'s JTI counter is Redis-backed. `BrandFundingGate`/`RequirePlan`/`FeatureGate` delegate to services whose internals are not in the provided file list (cannot assess).

## Dashboard read paths (Group B)

- No dashboard controllers are present in the provided files. The services that back them (`ProfessionalCacheService`, `SiteCacheService`, `AnalyticsCacheService`) are all cache-infrastructure or invalidation classes — the read paths they expose to controllers are already wrapped.

## Synchronous vendor reads (Group C)

- No vendor service files (`app/Services/Shopify`, `app/Services/Stripe`, `app/Services/Square`, `app/Services/Cloudflare`) are present in the provided files. Vendor calls observed in the provided scope are either:
  - Inside observer write-paths (e.g. `SiteCacheService` bust methods resolving shop domains to clear Hydrogen cache keys) — write-side, not hot read paths.
  - Inside `VerifyTurnstileCaptcha` — each token is unique per request, making caching incorrect.

## Observers & commands

- All observer files are write-side cache invalidation. Console commands are one-shot/admin paths. Neither qualifies as a hot read path.

**Result: 0 CCG findings in the provided file set.** The architecture shows deliberate, consistent cache coverage on every hot read path visible in these files. The sibling lens `caching-gold-standard.md` (`CCH`) owns the quality concerns around the public-site payload re-hydration pattern; those are not cache-absence defects.
