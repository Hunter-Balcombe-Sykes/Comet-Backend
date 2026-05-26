- [ ] **#CFG-1** · P2 — Hardcoded storefront reachability cache TTL and HTTP timeouts in BrandStatusService
    - **Where:** app/Services/Professional/Brand/BrandStatusService.php:279-280 and 291-294
    - **Affects:** Brand status determination (storefront_live → ready_for_affiliates). Slow or transient Shopify stores may cause false negatives, stalling brand onboarding.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move the 60s / 15s cache TTLs into `config/sidest.php` under a `brand_status` key (e.g., `brand_status.storefront_reachable_cache_ttl_success`, `...failure`).
        - Move the 5s timeout and 3s connect_timeout into the same config block (`brand_status.storefront_check_timeout`, `...connect_timeout`).
        - Update the `Cache::put` and `Http::withOptions` calls to use `config('sidest.brand_status.…')`.
    - **Technical:** The `isStorefrontReachable` method hardcodes `Cache::put($cacheKey, $reachable, $reachable ? 60 : 15)` and passes `'timeout' => 5, 'connect_timeout' => 3` to `Http::withOptions`. These numbers control how long a failing storefront keeps the brand in `shopify_configured` and how long we wait for a response. In production, transient network issues or a slow Shopify proxy may need more lenient defaults; without config, tuning requires a code deployment. Moving them into `config/sidest.php` lets ops adjust them via env without touching code.
    - **Plain English:** When Partna checks if a brand’s storefront is live, it remembers a “no” answer for 15 seconds before rechecking. If the storefront is slow to respond, we give up after 3 seconds. Currently those numbers are written directly in the code, so if we ever need to be more patient (e.g., during a Shopify outage), we’d have to push new code. Putting them in a config file lets us tweak them with an environment variable change and a restart — much faster and safer.
    - **Evidence:**
        ```php
        Cache::put($cacheKey, $reachable, $reachable ? 60 : 15);
        ```
        ```php
        try {
            $response = Http::withOptions([
                'allow_redirects' => false,
                'timeout' => 5,
                'connect_timeout' => 3,
            ])->get($url);
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#CFG-2** · P3 — Hardcoded pending payout sweep limit (500) in CommissionPayoutService
    - **Where:** app/Services/Stripe/CommissionPayoutService.php:197
    - **Affects:** Daily cron sweep that re-queues existing pending/processing payouts. With many stuck payouts, only 500 are processed per run.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract the `500` limit into a config key like `partna.store.payout_pending_sweep_limit`.
        - Replace the `->limit(500)` with `->limit(config('partna.store.payout_pending_sweep_limit', 500))`.
    - **Technical:** The `processEligiblePayouts` method fetches existing pending/processing payouts with `->limit(500)->get()`. If the number of in‑flight payouts grows beyond 500, the cron only touches the first 500 each run. While each payout is dispatched as a job, the sweep itself is a single DB query; the limit caps the number of jobs created per invocation. Moving the limit to config allows scaling without a deploy.
    - **Plain English:** Every night Partna picks up any payouts that haven’t finished and retries them. Right now it only looks at the first 500 — if more than 500 are stuck, the rest have to wait until the next night. This number is baked into the code, so if we ever need to handle more at once, we’d have to change code and redeploy. Making it a config value lets us adjust it on the fly.
    - **Evidence:**
        ```php
        $existingPending = CommissionPayout::query()
            ->whereIn('status', ['pending', 'processing'])
            ...
            ->limit(500)
            ->get();
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CFG-3** · P3 — Hardcoded void chunk size (500) in CommissionVoidService
    - **Where:** app/Services/Stripe/CommissionVoidService.php:67
    - **Affects:** Nightly void of expired commissions; each chunk processes at most 500 orders.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `->chunkById(500, ...)` with a configurable value from `partna.store.commission_void_chunk_size`.
    - **Technical:** `processVoidableCommissions` uses `Order::query()->...->chunkById(500, ...)`. Hardcoding 500 means every cron tick processes at most 500 orders per 30‑day window. If a tenant has 5,000 voidable orders, the cron will run 10 times before completing them. Making the chunk size configurable allows operators to increase throughput without a deploy.
    - **Plain English:** Partna’s nightly cleanup of expired commissions processes the work in batches of 500 orders at a time. If a large brand has thousands of commissions that need voiding, it takes many nights to finish. That batch size is fixed in the code, so we can’t speed it up without pushing a code change. Making it a config setting would let us increase the batch size when needed.
    - **Evidence:**
        ```php
        Order::query()
            ->where('status', 'approved')
            ...
            ->chunkById(500, function ($orders) use (&$stats) {
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CFG-4** · P3 — Hardcoded bulk invite row limit (500) in BrandAffiliateInviteService
    - **Where:** app/Services/Professional/Brand/BrandAffiliateInviteService.php:23
    - **Affects:** Brands that want to upload more than 500 affiliate invites in one CSV.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move the `BULK_MAX_ROWS = 500` constant into `config/sidest.php` (e.g., `invites.bulk_max_rows`).
        - Replace the constant usage with `config('sidest.invites.bulk_max_rows', 500)`.
    - **Technical:** The service defines `private const BULK_MAX_ROWS = 500` and enforces it in `processBulkInvites`. The limit is arbitrary and may need to be adjusted per‑brand or platform‑wide. Hardcoding it means any change requires a code deployment and a PR. Storing the limit in config allows ops to raise it for a specific tenant or during a migration without touching code.
    - **Plain English:** When a brand uploads a CSV of affiliate invitations, they can include at most 500 rows. If a large brand wants to upload more, they’re stuck — the limit is written directly in the code. Putting that number in a config file means we can raise it for specific partners without changing the code or deploying.
    - **Evidence:**
        ```php
        private const BULK_MAX_ROWS = 500;
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#CFG-5** · P3 — Hardcoded Stripe Connect status cache TTL (60 seconds)
    - **Where:** app/Services/Stripe/StripeConnectService.php:17
    - **Affects:** How quickly the platform picks up Stripe account status changes (onboarding / capability activation).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `private const STATUS_CACHE_TTL = 60` with a config value like `services.stripe.connect_status_cache_ttl`.
        - Use `config('services.stripe.connect_status_cache_ttl', 60)` where the constant is referenced.
    - **Technical:** The `syncAccountStatus` method wraps the Stripe v2 account retrieve in a 60‑second cache. The TTL is hardcoded in a class constant, so operators cannot shorten it to resolve status propagation issues without a deploy. Moving it to `config/services.php` lets ops reduce the TTL via env to respond to incidents quickly.
    - **Plain English:** After a brand finishes their Stripe onboarding, Partna remembers the result for 60 seconds before checking again. That 60‑second timer is baked into the code, so if we ever need to speed it up (e.g., to fix a stuck “onboarding” status), we’d have to push new code. Making it a config value would let us change it with an environment variable.
    - **Evidence:**
        ```php
        private const STATUS_CACHE_TTL = 60;
        ```
    - `[DRAFT, confidence: 0.8]`
