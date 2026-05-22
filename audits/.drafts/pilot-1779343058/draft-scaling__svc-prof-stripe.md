
<!-- ═══ SUB-CHUNK: s1 (app/Services/Professional) ═══ -->

- [ ] **CACHE-1** · P1 — `isStorefrontReachable()` cache lacks single-flight locking, TTL jitter, and push invalidation
    - **Where:** app/Services/Professional/Brand/BrandStatusService.php:263-281
    - **Affects:** Every admin page load hitting `/internal/embedded/provision-integration` and every brand onboarding checklist poll — all 30 brands at target scale.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Cache::get` + `Cache::put` with `CacheLockService::rememberLocked('brand_status:storefront_reachable:' . sha1($url), 60, fn() => ...)` — the lock grants single-flight so concurrent requests share one HTTP probe.
        - Add ±20% TTL jitter via the `rememberLocked` jitter parameter to prevent synchronised expiry thundering herds.
        - On the storefront-deployment webhook path, push-invalidate the exact cache key so a freshly deployed storefront flips to reachable instantly rather than waiting up to 15s.
    - **Technical:** The code comment acknowledges this HTTP probe "dominates p95 on hot endpoints" without the cache. The existing `Cache::get`/`Cache::put` pair is a classic cold-cache stampede vector — after a Redis eviction, deploy, or flush, every concurrent request races its own 5-second `Http::get()` to the brand's storefront URL. `CacheLockService::rememberLocked` wraps the probe in a Redis-backed mutex (lock key scoped to the cache key), so only one request pays the HTTP cost. The 15s negative TTL is a good intuition but without push invalidation the dashboard stays stale for up to 15s after a deployment succeeds. The canonical replacement already deployed in commerce analytics (`rememberLocked` + jitter + SWR + push-invalidate on every write) maps directly onto this use case.
    - **Plain English:** Every time a staff member loads the admin dashboard or a brand checks their onboarding progress, the system pings the brand's storefront to see if it's live. The dev team already added a short-lived "sticky note" (cache) so we don't ping over and over. But when that sticky note falls off — after a server restart, a cache clear, or just when the timer runs out — every request that arrives at the same moment sends its own ping. That's like twenty people all calling the same store to ask if they're open, at the exact same second. The fix is a "take a number" system: the first person makes the call, everyone else waits and reads the answer from the board.
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

        return $reachable;
        ```
    - `[DRAFT, confidence: 0.9]`

<!-- ═══ SUB-CHUNK: s2 (app/Services/Stripe) ═══ -->

- [ ] **#CACHE-1** · P3 — StripeTransactionFetcher delegates caching to controller but makes N sequential Stripe round-trips per page render; no in-service guard against uncached hot reads
    - **Where:** app/Services/Stripe/StripeTransactionFetcher.php:44–68 (forBrand) and :74–100 (forAffiliate)
    - **Affects:** Brand and affiliate dashboard users viewing transaction history; each page load fans out up to `limit` (default 25) sequential `paymentIntents->retrieve` or `charges->retrieve` calls to Stripe's API.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Confirm the calling controller wraps the fetcher in `CacheLockService::rememberLocked`. If it already does, downgrade this to a documentation note.
        - Add a `@see` docblock cross-reference from the fetcher to the controller so future readers don't assume the service self-caches.
    - **Technical:** The service docblock states "Cache wrapping is the controller's job." That is a valid separation, but there's no compile-time or runtime guard that ensures the controller actually implements it. If a developer adds a second call site (e.g., a webhook handler or admin endpoint) without realising caching lives elsewhere, Stripe rate limits and latency accumulate silently. The service could take a PSR-16 cache as an optional constructor dependency and self-cache when one is injected, falling back to direct fetch otherwise — that makes the contract explicit.
    - **Plain English:** Think of this like a library that charges your credit card every time you open it. The library's instructions say "your accountant will handle reimbursements," but nothing in the library actually talks to the accountant. If someone else opens the library without knowing the rule, they get charged too. A small label saying "see accountant" inside the book cover would prevent that surprise.
    - **Evidence:**
        ```php
        // app/Services/Stripe/StripeTransactionFetcher.php:27-29
        // Cache wrapping is the controller's job (CacheLockService::rememberLocked) — this service
        // stays pure for testability.
        class StripeTransactionFetcher
        {
            public function __construct(private readonly StripeClient $stripe) {}

            public function forBrand(Professional $brand, array $filters): array
            {
                $payouts = $this->scopedPayouts($brand->id, 'brand', $filters);
                $rows = [];

                foreach ($payouts as $payout) {
                    // ...
                    $pi = $this->stripe->paymentIntents->retrieve($payout->payment_intent_id, [
                        'expand' => ['latest_charge.refunds'],
                    ]);
                    // ...
                }
                // ...
            }
        ```
    - `[DRAFT, confidence: 0.5]`

- [ ] **#CACHE-2** · P3 — StripeBalanceService makes live Stripe API calls on every invocation; no caching layer inside the service
    - **Where:** app/Services/Stripe/StripeBalanceService.php:41–56 (forAffiliate), :69–92 (upcomingFor), :101–124 (payoutScheduleFor)
    - **Affects:** Affiliate dashboard pages showing available balance, pending payouts, and payout schedule. Three Stripe round-trips per dashboard load if the controller doesn't cache.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Verify the controller wraps each call in `CacheLockService::rememberLocked`. If it does, close this finding.
        - Add a design-constraint comment linking to the controller so the caching contract is traceable from the service.
    - **Technical:** Same delegation pattern as StripeTransactionFetcher. The service is intentionally pure, but three separate methods (`forAffiliate`, `upcomingFor`, `scheduleFor`) each hit Stripe independently. If a single dashboard widget calls all three without upstream caching, that's three sequential Stripe HTTP calls per render. The risk is lower than the transaction fetcher because balance data changes less frequently and the calls are lighter (simple retrieves without expands), but the pattern is identical.
    - **Plain English:** Same pattern as the transaction history library — three more credit cards that get swiped every time someone checks their balance. The note says "your accountant pays these too," but the cards don't know that.
    - **Evidence:**
        ```php
        // app/Services/Stripe/StripeBalanceService.php:18-19
        // Cache wrapping is the controller's responsibility (CacheLockService::rememberLocked)
        // — this service stays pure for testability.

        // :41-56 — live Stripe call, no cache
        $balance = $this->stripe->balance->retrieve([], [
            'stripe_account' => $affiliate->stripe_connect_account_id,
        ]);
        ```
    - `[DRAFT, confidence: 0.5]`

- [ ] **#CACHE-3** · P3 — buildClawbackPlan hydrates full Eloquent models to sum a single JSONB field where a selectRaw aggregate would suffice
    - **Where:** app/Services/Stripe/CommissionPayoutRefundService.php:197–201
    - **Affects:** Shopify refund webhook handler latency. Each refund event loads every prior clawback row for the payout+order pair into memory.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `->get()->sum(fn ($c) => ...)` with a `selectRaw('COALESCE(SUM((metadata->>\'refund_share_cents\')::int), 0)')` query so PostgreSQL does the aggregation.
    - **Technical:** The current code hydrates a full `CommissionClawback` Eloquent model for every prior clawback row on this payout+order pair, then sums a nested JSONB field in PHP. At pre-beta scale (0–2 clawbacks per order) this is negligible. At the scaling target (30 brands × ~50 affiliates × ~100 orders/year), the per-order clawback count stays small because clawbacks are rare — but the pattern is still a Category 5 micro-optimisation: hydrating full Eloquent models for an aggregate query. The canonical pattern is a `selectRaw` aggregate, which the codebase already uses elsewhere (e.g., `CommissionVoidService::pendingSummaryForAffiliateBrand`).
    - **Plain English:** Imagine tallying how much change is in a jar by taking out every coin, writing down its value on a separate piece of paper, then adding the papers — instead of just counting the coins in place. It works fine for a jar with two coins. It's just a habit worth breaking before the jars get bigger.
    - **Evidence:**
        ```php
        // app/Services/Stripe/CommissionPayoutRefundService.php:197-201
        $priorRefundCovered = (int) CommissionClawback::query()
            ->where('payout_id', $payout->id)
            ->where('order_id', $order->id)
            ->get()
            ->sum(fn ($c) => (int) ($c->metadata['refund_share_cents'] ?? 0));
        ```
    - `[DRAFT, confidence: 0.7]`

- [ ] **#CACHE-4** · P3 — bustPayoutCaches directly forgets affiliatePayoutState but relies solely on version-bump for brand-side caches; asymmetric invalidation
    - **Where:** app/Services/Stripe/CommissionPayoutRefundService.php:148–155
    - **Affects:** Brand dashboard stale-read window after a refund-driven payout mutation. The brand's `affiliatePayoutState` equivalent (if one exists) is invalidated only via the version-token bump, not a direct forget.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Audit `CacheKeyGenerator` for a brand-side payout-state key. If one exists, add `Cache::forget` + `Cache::forget(':stale')` for it in `bustPayoutCaches`.
        - If none exists, document the asymmetry so future brand-side cache additions follow the direct-forget pattern.
    - **Technical:** The method bumps the analytics version token for both brand and affiliate (which invalidates version-token-keyed caches on next read), but only does a direct `Cache::forget` on the affiliate's `affiliatePayoutState` key (with its SWR `:stale` twin). If a brand-side payout-state cache key exists, it stays warm until the version token is re-read, creating a window where the brand dashboard could show stale post-refund payout totals. The version bump handles it eventually, but the direct-forget is the immediate invalidation the affiliate side gets.
    - **Plain English:** When a refund happens, the affiliate's dashboard updates instantly (the cache is cleared directly). The brand's dashboard updates on a timer (it waits for the next version check). Both get the right answer eventually, but the brand might see old numbers for a few extra seconds. Giving both sides the same instant-update treatment keeps the experience symmetric.
    - **Evidence:**
        ```php
        // app/Services/Stripe/CommissionPayoutRefundService.php:148-155
        private function bustPayoutCaches(Order $order): void
        {
            $this->analyticsCache->bumpAnalyticsVersion($order->affiliate_professional_id);
            $this->analyticsCache->bumpAnalyticsVersion($order->brand_professional_id);

            $stateKey = CacheKeyGenerator::affiliatePayoutState($order->affiliate_professional_id);
            Cache::forget($stateKey);
            Cache::forget($stateKey.':stale');
        }
        ```
    - `[DRAFT, confidence: 0.4]`
