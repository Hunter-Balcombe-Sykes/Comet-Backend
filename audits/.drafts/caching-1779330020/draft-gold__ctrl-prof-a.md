- [ ] **#CCH-1** · P3 — Ad-hoc cache key + unjittered TTL in Shopify embedded connection code generation
    - **Where:** app/Http/Controllers/Api/Professional/Brand/ShopifyEmbeddedConnectionController.php:38
    - **Affects:** Developers maintaining cache key consistency; cache keys not centralised could lead to silent miss if the format ever changes.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Cache::put("shopify:embed:connect:{$code}", ...)` with a key built by `CacheKeyGenerator` (e.g. `CacheKeyGenerator::shopifyEmbedConnectCode($code)`).
        - If a time‑to‑live is ever changed to an integer, route the write through `CacheLockService` or apply `JitteredTtl::withJitter()` to avoid fleet synchronisation.
    - **Technical:** The key is built with ad‑hoc string concatenation while other cache read/write pairs in the codebase use `CacheKeyGenerator` (or domain helpers) for the same logical keys. The TTL is a `Carbon` instance (`now()->addMinutes(30)`) – not a literal int, so thundering‑herd risk is low, but the deviation from the gold‑standard centralised key pattern makes future drift likely.
    - **Plain English:** Think of a filing cabinet where every drawer has a label printed from a standard template, but one drawer has a handwritten sticky note. If someone redesigns the template, that one drawer will be missed and the file won’t be found. The fix is to print the label from the same template as everything else.
    - **Evidence:**
        ```php
        Cache::put("shopify:embed:connect:{$code}", (string) $professional->id, now()->addMinutes(30));
        ```
    - `[DRAFT, confidence: 1.0]`

- [ ] **#CCH-2** · P3 — Brand‑affiliate snapshot cache key not generated from `CacheKeyGenerator`
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandAffiliateController.php:157
    - **Affects:** Future maintainers; a different writer or eviction job that uses `CacheKeyGenerator` could miss this key, leaving stale data.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `$cacheKey = "analytics:commerce:brand_affiliate_snapshot:v1:{$brandId}:{$affiliateId}"` with a call to `CacheKeyGenerator::brandAffiliateSnapshot($brandId, $affiliateId)`.
    - **Technical:** The gold standard requires that every key for a cached value originates from one helper method so that readers and writers stay in sync. Other analytics endpoints (e.g. `AffiliateCommerceAnalyticsController`, `BrandCommerceAnalyticsController`) already delegate to `CacheKeyGenerator`; this standalone concatenation is an outlier.
    - **Plain English:** All the other dashboard charts use a shared address book to look up their data. This one chart wrote the address on a scrap of paper. If the address format changes, this chart won’t know and will show old information.
    - **Evidence:**
        ```php
        $cacheKey = "analytics:commerce:brand_affiliate_snapshot:v1:{$brandId}:{$affiliateId}";
        ```
    - `[DRAFT, confidence: 1.0]`

- [ ] **#CCH-3** · P3 — Deprecated shop‑summary endpoint builds cache key without `CacheKeyGenerator`
    - **Where:** app/Http/Controllers/Api/Professional/Analytics/ProfessionalAnalyticsController.php:shopSummary() (key construction around line 680)
    - **Affects:** In‑flight callers of the deprecated shopSummary endpoint; key drift if the analytics key format is ever normalised.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `$cacheKey = 'analytics:shop:'.$professional->id.':'.$from->format('YmdH').':'.$to->format('YmdH')...` with a method on `CacheKeyGenerator` (e.g. `CacheKeyGenerator::analyticsShopSummary(...)`) that mirrors the shape used by the main `summary()` endpoint.
    - **Technical:** `summary()` already uses `CacheKeyGenerator::analyticsSummary` for the same logical data; `shopSummary()` constructs its key with ad‑hoc concatenation. Although the endpoint is deprecated, it remains live and cached – if the key composition drifts (e.g. changing the date‑format or adding a new segment), the two endpoints will read different cache entries for the same professional/window.
    - **Plain English:** Two different doors into the same room use the same key‑cutting machine. One door’s lock was cut by hand years ago and still works, but if the machine’s template changes, that lock won’t open any more. Moving it to the machine avoids future confusion.
    - **Evidence:**
        ```php
        $cacheKey = 'analytics:shop:'.$professional->id.':'.$from->format('YmdH').':'.$to->format('YmdH').':'
            .($useHourlyBuckets ? 'hour' : 'day').":v{$summaryVersion}";
        ```
    - `[DRAFT, confidence: 1.0]`
