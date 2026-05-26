- [ ] **#CCH-1** · P1 — Plain `Cache::get` + `Cache::put` on a hot dashboard HTTP-check path with no single-flight lock
    - **Where:** app/Services/Professional/Brand/BrandStatusService.php:248-266
    - **Affects:** Every admin page load that evaluates brand status — `BrandOnboardingReadinessService::getChecklist()` → `syncBrandStatus()` → `BrandStatusService::sync()` → `determine()` → `isStorefrontReachable()`. Concurrent page loads (multiple staff tabs, brand dashboard open in two windows) all fire HTTP requests to the brand's storefront on cache miss, stampeding the origin.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Inject `CacheLockService` and replace the `Cache::get` + fallback + `Cache::put` pattern with `$this->cacheLock->rememberLocked($cacheKey, $reachable ? 60 : 15, fn() => $this->performHttpCheck($url))`.
        - Extract the HTTP call into a private method so the closure passed to `rememberLocked` is clean and testable.
    - **Technical:** The current code does `Cache::get($key)` → if null, executes an HTTP GET with 5s timeout → `Cache::put($key, $result, $ttl)`. There is no atomicity guard between the get and the put. When the cache is cold (deploy boundary, scheduled flush, or natural expiry), every concurrent caller that reaches this line before the first one finishes its HTTP call sees a miss and fires its own HTTP request. With the cache on a 15–60s TTL and called from `determine()` which runs on every `BrandOnboardingReadinessService::getChecklist()` invocation (the brand onboarding checklist endpoint), this creates a stampede of outbound HTTP calls at cold start. `CacheLockService::rememberLocked` would serialise them through a Redis lock — one caller performs the HTTP check, the rest block briefly and read the cached result.
    - **Plain English:** Imagine a dozen staff members all refreshing the same dashboard page at the same moment after a deploy. Each one's request sees the cache is empty and independently calls out to the brand's storefront to see if it's alive — all at once. The fix routes all those requests through a single gatekeeper, so only one person checks the storefront and everyone else gets the answer a split second later from the cache.
    - **Evidence:**
        ```php
        $cacheKey = 'brand_status:storefront_reachable:'.sha1($url);

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return (bool) $cached;
        }

        try {
            $response = Http::withOptions([
                'allow_redirects' => false,
                'timeout' => 5,
                'connect_timeout' => 3,
            ])->get($url);

            $reachable = $response->successful();
        } catch (\Throwable) {
            $reachable = false;
        }

        Cache::put($cacheKey, $reachable, $reachable ? 60 : 15);
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#CCH-2** · P2 — `isStorefrontReachable` cache write has unjittered TTL and no stale-while-revalidate companion
    - **Where:** app/Services/Professional/Brand/BrandStatusService.php:265
    - **Affects:** The storefront-reachability cache for every brand. Synchronised TTL expiry causes thundering-herd HTTP checks when the cache expires naturally — every process that ticks at the same wall-clock moment (e.g. all Horizon workers hitting the dashboard endpoint at the same second after deploy) will miss together and stampede even after the single-flight lock is added. Without SWR, callers that arrive during the recomputation window wait on the lock-holder's HTTP call rather than receiving a last-good stale answer.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Route the write through `CacheLockService::rememberLocked` (which auto-applies jitter via `JitteredTtl` and manages the `:stale` companion key automatically).
        - If a bespoke write is retained, call `JitteredTtl::withJitter($ttl)` at the write site and write a `$key:stale` companion with 10× TTL.
    - **Technical:** `Cache::put($cacheKey, $reachable, $reachable ? 60 : 15)` writes a hardcoded integer TTL. When all cache entries share the same TTL, they all expire simultaneously — every process sees a miss on the same second and recomputes. `JitteredTtl::withJitter()` spreads expiry by ±20%, smoothing the miss distribution across the fleet. Additionally, `rememberLocked` maintains a `$key:stale` companion at 10× TTL that is returned immediately when the primary expires, while the lock-holder refreshes in the background — callers never wait on a cold read.
    - **Plain English:** The storefront-check cache has a fixed countdown — it expires at exactly the same moment for every server. When it does, all of them rush to check the storefront at once. Adding a random wiggle to the countdown staggers those expirations across the fleet, and keeping a "day-old but good-enough" copy means nobody has to wait for the fresh check to finish.
    - **Evidence:**
        ```php
        Cache::put($cacheKey, $reachable, $reachable ? 60 : 15);
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#CCH-3** · P2 — `isStorefrontReachable` cache key built via ad-hoc string concatenation, not via `CacheKeyGenerator`
    - **Where:** app/Services/Professional/Brand/BrandStatusService.php:248
    - **Affects:** Any future code path that needs to invalidate this key programmatically (e.g. a webhook that fires when a storefront goes live, or a staff admin "refresh status" button). Without a centralised key helper, the invalidation call site must duplicate the concatenation logic — drift between the reader and writer produces a silent cache-miss that looks like the key was never written.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a static key helper (e.g. `CacheKeyGenerator::brandStorefrontReachable(string $subdomain): string`) and call it from both the read path and any future invalidation path.
    - **Technical:** The key `'brand_status:storefront_reachable:'.sha1($url)` is assembled inline. The gold standard requires every key to originate from a single helper method so that readers and writers call the same source of truth. Currently there is no writer that invalidates this key programmatically, but if one is added later (e.g. a Shopify `app/uninstalled` webhook that resets the status), the author would need to replicate the `sha1($url)` algorithm exactly. `CacheKeyGenerator` removes that risk.
    - **Plain English:** The label on the storage box is written by hand in one place. If someone later needs to clear that box from a different part of the system, they have to write the label exactly the same way — same spelling, same abbreviations. A shared label-maker function guarantees both sides always match.
    - **Evidence:**
        ```php
        $cacheKey = 'brand_status:storefront_reachable:'.sha1($url);
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CCH-4** · P2 — `failPayout` mutates payout state without bumping analytics cache version (missing push-invalidate)
    - **Where:** Write site: app/Services/Stripe/CommissionPayoutService.php:554-570 (`failPayout` method). Read site: cached analytics reads keyed off `analyticsSummaryVersion` (via `AnalyticsCacheService`).
    - **Affects:** Dashboard analytics views (commerce overview, affiliate projections) after a payout fails — they show stale payout counts and gross/net totals until the analytics cache TTL expires naturally, because the version token was never incremented. Staff and brand users see a payout as still "in flight" when it has already failed.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `$this->analyticsCache->bumpAnalyticsVersion($payout->brand_professional_id)` and `$this->analyticsCache->bumpAnalyticsVersion($payout->affiliate_professional_id)` inside `failPayout`, matching the pattern already used by `markCompleted`.
    - **Technical:** `markCompleted` (line ~480) correctly calls `$this->analyticsCache->bumpAnalyticsVersion(...)` for both the brand and affiliate so cached analytics reads pick up the completed payout on the next request. `failPayout` — which transitions the payout to `failed`, releases orders, and deletes payout items — does not bump the version token. Any cached dashboard view keyed off `analyticsSummaryVersion` will serve stale data showing the payout as still pending/processing until the underlying cache TTL expires. The version-token pattern (category 5 of the gold standard) requires every terminal state transition to increment the token.
    - **Plain English:** When a payout succeeds, the system pings the dashboard caches so they refresh immediately. When a payout fails — the brand's card was declined, the affiliate's Stripe account wasn't ready — the dashboard cache doesn't get that ping. Staff and brands keep seeing the payout as "in progress" until the cache naturally expires minutes later, which looks like the system is stuck.
    - **Evidence:**
        ```php
        // failPayout — no cache invalidation (compare with markCompleted below)
        private function failPayout(CommissionPayout $payout, string $code, string $reason): void
        {
            CommissionPayoutItem::where('payout_id', $payout->id)->delete();
            Order::where('payout_id', $payout->id)->update(['payout_id' => null]);

            $payout->forceFill([
                'status' => 'failed',
                'failure_code' => $code,
                'failure_reason' => $reason,
                'processed_at' => now(),
            ])->save();
            // ... log warning ...
        }

        // markCompleted — correctly bumps analytics version
        private function markCompleted(CommissionPayout $payout, Professional $brand, Professional $affiliate): void
        {
            $payout->forceFill([...])->save();
            $this->analyticsCache->bumpAnalyticsVersion($brand->id);
            $this->analyticsCache->bumpAnalyticsVersion($affiliate->id);
            // ...
        }
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#CCH-5** · P2 — `cancelExpiredPayout` mutates payout and orders without bumping analytics cache version (missing push-invalidate)
    - **Where:** Write site: app/Services/Stripe/CommissionVoidService.php:275-330 (`cancelExpiredPayout` method). Read site: cached analytics reads via `AnalyticsCacheService` version-token pattern.
    - **Affects:** Dashboard analytics after the nightly `VoidExpiredPayoutsJob` runs — expired payouts are cancelled and linked orders are voided, but cached analytics views (commerce overview, affiliate projections) remain stale until TTL expiry. Affiliates whose payouts expired due to grace-period timeout see out-of-date dashboard numbers.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - After the payout cancellation and order voiding succeed, call `app(AnalyticsCacheService::class)->bumpAnalyticsVersion($payout->brand_professional_id)` and `->bumpAnalyticsVersion($payout->affiliate_professional_id)`.
    - **Technical:** `cancelExpiredPayout` transitions a payout to `cancelled` and voids all linked orders inside a transaction. The `voidOrder` method sets `status='voided'` which triggers the `rollup_apply_delta` trigger — so the DB-side rollup is correct. However, any cached read of analytics (e.g. the affiliate's dashboard commerce overview) keyed off the version-token pattern will not see the cancelled payout or voided orders until the token is bumped. The method already has an `$affiliate` loaded via `lockForUpdate()` and the payout carries `brand_professional_id`, so both IDs are available for the bump call.
    - **Plain English:** Every night, the system cleans up expired payouts from affiliates who never connected their bank account. The database is updated correctly, but the dashboard cache doesn't get notified. Those affiliates see stale numbers until the cache refreshes on its own schedule — so their dashboard might still show "pending" payouts that were already cancelled hours ago.
    - **Evidence:**
        ```php
        // cancelExpiredPayout — no analytics version bump after successful cancellation
        $updated = CommissionPayout::query()
            ->where('id', $payout->id)
            ->where('status', 'pending')
            ->update([
                'status' => 'cancelled',
                'failure_code' => 'grace_period_expired',
                // ...
            ]);

        if ($updated === 0) { /* ... */ return; }

        $voidedOrders = $this->voidOrdersLinkedToPayout($payout->id, 'payout_grace_expired');
        $this->clearOrderStampsForVoidedPayout($payout->id);

        $stats['cancelled_count']++;
        // ...
        // No analyticsCache->bumpAnalyticsVersion(...) call anywhere in this method
        ```
    - `[DRAFT, confidence: 0.85]`
