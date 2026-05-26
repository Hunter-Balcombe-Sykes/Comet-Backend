- [ ] **#CACHE-1** · P1 — `analyticsSummary` cache key lacks version token embedding — survives commerce-write invalidation
    - **Where:** app/Services/Cache/CacheKeyGenerator.php:analyticsSummary() line ~L145
    - **Affects:** Professional dashboard analytics summary views; stale commerce data (revenue, commissions, order counts) served for up to 24h after any commerce webhook write.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Embed `analyticsSummaryVersion` token in `analyticsSummary` key, matching the pattern already used by `affiliateCommerceAnalytics` and `brandCommerceAnalytics`.
        - Or add explicit `Cache::forget` per date-range variant inside `AnalyticsCacheService::invalidateAnalytics()` (less scalable — requires key enumeration).
    - **Technical:** `CacheKeyGenerator::analyticsSummary()` returns a static key `analytics:summary:q3:{pro}:{start}:{end}` with a hardcoded `q3` schema marker. Unlike `affiliateCommerceAnalytics` and `brandCommerceAnalytics` (which read `analyticsSummaryVersion` from Redis and embed `$version` into the key), the summary key never changes on a version bump. `AnalyticsCacheService::invalidateAnalytics()` calls `bumpAnalyticsVersion()` which atomically busts every version-embedded key — but `analyticsSummary` keys are invisible to that mechanism. Result: after a Shopify order webhook fires, the professional's dashboard analytics summary continues serving the pre-order numbers until the key's natural TTL expires (up to 24h per `CacheLockService` stale extension).
    - **Plain English:** Imagine a restaurant's daily sales whiteboard. When a new order comes in, the manager updates the board. But this particular whiteboard has one section that's locked in a glass case — the manager only updates it once a day regardless of how many orders come in. The `analyticsSummary` cache is that glass case. The fix is to link it to the same "update the board" trigger that everything else uses.
    - **Evidence:**
        ```php
        // CacheKeyGenerator.php — static q3 marker, no dynamic version token
        public static function analyticsSummary(string $professionalId, string $startDate, string $endDate): string
        {
            // q3: commerce fields now read from commerce.orders instead of commission_movements (Phase 3)
            return "analytics:summary:q3:{$professionalId}:{$startDate}:{$endDate}";
        }
        
        // Compare with brandCommerceAnalytics — embeds dynamic version token
        public static function brandCommerceAnalytics(string $professionalId, string $from, string $to): string
        {
            $version = \Illuminate\Support\Facades\Cache::get(self::analyticsSummaryVersion($professionalId), 0);
            return "analytics:commerce:brand:v7:{$professionalId}:{$version}:{$from}:{$to}";
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CACHE-2** · P2 — `bookingAnalytics` cache key lacks version token embedding — no atomic invalidation path
    - **Where:** app/Services/Cache/CacheKeyGenerator.php:bookingAnalytics() line ~L160
    - **Affects:** Professional booking analytics dashboard views; stale booking aggregates (counts, revenue) served after booking webhook writes until TTL expiry.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Embed `analyticsSummaryVersion` token in the `bookingAnalytics` key, matching the version-embedding pattern on `affiliateCommerceAnalytics`.
        - Verify that the booking write path (observer / rebuild job) also bumps the version token or has its own invalidation — if not, add a `bumpAnalyticsVersion` call on booking writes.
    - **Technical:** The `bookingAnalytics` key returns `analytics:booking:{pro}:{from}:{to}:{groupBy}` with no dynamic version component. If `BookingAnalyticsAggregateService` or a `RebuildBooking*AggregatesJob` exists (suggested by the key shape and the scope notes), any version-token bump from `AnalyticsCacheService` is a no-op for these keys. They would require per-key `Cache::forget` enumeration across all active date-range/groupBy combinations on every booking write — the exact antipattern the commerce rebuild eliminated. Without seeing the booking job code, the conservative fix is to embed the version token now so invalidation is atomic regardless of what the rebuild jobs do.
    - **Plain English:** Same glass-case whiteboard problem, but for booking data. When someone books an appointment, the booking analytics chart should refresh. It doesn't — it waits for its timer to run out. The fix is the same: connect it to the "update now" button that everything else uses.
    - **Evidence:**
        ```php
        // CacheKeyGenerator.php — no version token in booking analytics key
        public static function bookingAnalytics(string $professionalId, string $from, string $to, string $groupBy): string
        {
            return "analytics:booking:{$professionalId}:{$from}:{$to}:{$groupBy}";
        }
        ```
    - `[DRAFT, confidence: 0.65]`

- [ ] **#CACHE-3** · P2 — `bookingMilestoneTotals` key defined but never invalidated in any provided cache service
    - **Where:** app/Services/Cache/CacheKeyGenerator.php:bookingMilestoneTotals() line ~L210
    - **Affects:** Milestone notification path — lifetime booking counts may serve stale after new bookings, causing missed or delayed milestone notifications ("You've reached 100 bookings!").
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `CacheKeyGenerator::bookingMilestoneTotals($professionalId)` (with `:stale`) to the key list in `ProfessionalCacheService::invalidateProfessional()` if booking writes update the professional timestamp.
        - Or add explicit bust in the booking write path (observer / job) — and document it so future readers know where invalidation lives.
    - **Technical:** `bookingMilestoneTotals` generates the key `pro:{id}:bookings:milestone-totals`. It lives in the `pro:` namespace but is absent from `ProfessionalCacheService::invalidateProfessional()`, which enumerates `professionalPayloadById`, `professionalServices`, `customerCount`, `brandPartnerStatus`, etc. but does not include this key. `AnalyticsCacheService::invalidateAnalytics()` also doesn't touch it. If there is a booking observer that separately busts this key, it's not visible in the audit scope. The key's purpose is to prevent re-scanning `analytics.booking_events` during a booking burst — but if the key is never invalidated, the first cached value persists until TTL expiry regardless of new bookings.
    - **Plain English:** There's a sticky note on the fridge tracking "lifetime bookings." Every time a new booking comes in, someone should cross out the old number and write the new one. But right now, nobody is crossing it out — the note stays frozen until it falls off the fridge on its own. The milestone celebration ("100 bookings!") might not fire on time because the note still says "99."
    - **Evidence:**
        ```php
        // CacheKeyGenerator.php — key defined, semantically in pro: namespace
        public static function bookingMilestoneTotals(string $professionalId): string
        {
            return "pro:{$professionalId}:bookings:milestone-totals";
        }
        ```
        ```php
        // ProfessionalCacheService.php — invalidateProfessional enumerates pro:* keys
        // but bookingMilestoneTotals is absent from the list:
        $keys = [
            CacheKeyGenerator::professionalPayloadById($professional->id),
            CacheKeyGenerator::professionalPayloadByHandle($handleLc),
            CacheKeyGenerator::professionalPayloadByAuthId($professional->auth_user_id),
            // ... professionalServices, customerCount, brandPartnerStatus ...
            // bookingMilestoneTotals NOT present
        ];
        ```
    - `[DRAFT, confidence: 0.70]`

- [ ] **#CACHE-4** · P3 — `FeatureFlagService::jitteredTtl()` double-jitters with `CacheLockService::writeWithJitter()`
    - **Where:** app/Services/FeatureFlags/FeatureFlagService.php:jitteredTtl() → app/Services/Cache/CacheLockService.php:writeWithJitter()
    - **Affects:** Feature flag cache TTL precision — effective jitter range is wider than intended (~192–432s instead of ~240–360s for a 300s base TTL). No user-visible impact; purely a hygiene inconsistency.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the `random_int` jitter from `FeatureFlagService::jitteredTtl()` and let `CacheLockService::writeWithJitter()` be the single jitter layer.
        - Or document the double-jitter as intentional (wider spread for feature flags) and rename the method to avoid confusion.
    - **Technical:** `FeatureFlagService::jitteredTtl()` applies ±60s additive jitter via `random_int(-60, 60)` then passes the already-jittered int to `CacheLockService::rememberLocked()`, which internally calls `writeWithJitter()` → `JitteredTtl::applyJitter()` (multiplicative ±20%). For a base TTL of 300s, the first pass yields 240–360s; the second multiplies that by 0.8–1.2, giving an effective range of ~192–432s. This is harmless — feature flag TTLs are not SLO-critical — but it means the `±60s` jitter annotation in the code is misleading. The actual spread is ~±30% instead of the documented ±20%.
    - **Plain English:** The kitchen has two separate timers for the same dish. One timer adds a bit of randomness (±60 seconds), then the second timer adds more randomness on top (±20%). The dish is never burned, but nobody can say exactly when it'll be done either. It's fine, just a bit sloppy.
    - **Evidence:**
        ```php
        // FeatureFlagService.php — applies additive jitter
        private function jitteredTtl(?Carbon $nearestExpiry = null): Carbon|int
        {
            $base = self::BASE_TTL_SECONDS + random_int(-self::TTL_JITTER_SECONDS, self::TTL_JITTER_SECONDS);
            // ...
            return $base;
        }
        
        // Then passes to CacheLockService which applies multiplicative jitter internally:
        // CacheLockService::writeWithJitter → JitteredTtl::applyJitter (mt_rand-based ±20%)
        ```
    - `[DRAFT, confidence: 0.80]`
