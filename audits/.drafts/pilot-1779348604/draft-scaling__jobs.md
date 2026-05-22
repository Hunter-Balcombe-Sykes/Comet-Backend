- [ ] **#CACHE-1** · P1 — Synchronous Shopify metafield API call on every orders/paid webhook
    - **Where:** app/Jobs/Shopify/ProcessShopifyOrderWebhookJob.php:172-174
    - **Affects:** Every Shopify orders/paid webhook handler — adds a synchronous external API round-trip to the critical commerce path. At scale (150K orders/year), this is ~17 Shopify API calls/hour on average, spiking with traffic.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Cache commission override values locally with push-invalidation when the brand updates them (product webhook or dashboard save).
        - On cache miss, use `CacheLockService::rememberLocked` with 60s TTL + ±20% jitter + SWR so at most one worker fetches from Shopify while others serve stale data.
        - Store the last-known override values in a local table (e.g. `product_commission_overrides`) updated on brand-side metafield writes, making the webhook path a local DB read.
    - **Technical:** `fetchCommissionOverridesForProducts()` makes a Shopify GraphQL Admin API call inside `process()`, the core webhook handler for orders/paid. This couples the commission computation to Shopify's API latency and availability — a degraded Shopify API (rate-limit, 5xx) delays or fails commission writes. The canonical replacement from the analytics rebuild is a local cache fronted by `CacheLockService`, push-invalidated on the write path. Even simpler: since the brand sets these metafields through our own dashboard, persist the values locally at write time and read them from our DB in the webhook handler.
    - **Plain English:** Every time a customer places an order through an affiliate link, we pause the order processing to call Shopify and ask "what's the commission rate on each product in this order?" That's like stopping every sale at the register to phone a supplier and ask for pricing — it adds delay and breaks if the phone line is busy. The fix is to keep a local copy of the pricing that we update when the brand changes it, so the sale goes straight through.
    - **Evidence:**
        ```php
        // Lines 166-174 in process():
        // Collect GIDs for a single-call metafield override fetch.
        $productGids = $this->extractProductGids($lineItems);
        $overrideMap = ($integration && ! empty($productGids))
            ? $catalogService->fetchCommissionOverridesForProducts($integration, $productGids)
            : [];
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#CACHE-2** · P3 — Rate-limit lock via `Cache::add` with no TTL jitter and no Redis pinning guarantee
    - **Where:** app/Jobs/Shopify/ProcessShopifyShopUpdateJob.php:91-93
    - **Affects:** Brand design sync throttling — minor stampede risk if multiple shop/update webhooks for the same brand coalesce; cross-worker lock silently fails if the Cache driver isn't Redis.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add ±20% TTL jitter: `now()->addMinutes(random_int(48, 72))` instead of `now()->addHour()`.
        - Pin to Redis explicitly: `Cache::store('redis')->add(...)` so a misconfigured `CACHE_DRIVER` defaulting to `file` doesn't silently degrade the lock to single-process.
        - Apply the same pattern at any other `Cache::add` / `Cache::put` rate-limit sites by searching the codebase for `Cache::add(` usage.
    - **Technical:** `Cache::add($cacheKey, true, now()->addHour())` is an atomic `SET NX` used as a distributed rate-limit lock — it prevents `SyncShopifyBrandDesignJob` from dispatching more than once per hour per integration. The lock works correctly today (Redis-backed), but two hardening concerns apply: (1) the TTL has no jitter, so if a fleet-wide event triggers simultaneous shop/update webhooks across many brands, the per-brand locks all expire synchronously one hour later — unlikely today but a risk as brand count grows; (2) the `Cache` facade respects the default driver, which commit `4416acf4` fixed for Redis but future config drift could silently regress to `file` and break cross-worker locking. The canonical pattern from the analytics rebuild is to use Redis directly with jittered TTLs and explicit driver pins.
    - **Plain English:** When Shopify tells us a brand's store settings changed, we refresh their design (logos, colors) but want at most one refresh per hour. We do that with a shared timer — "last refresh was at 2:00pm, don't refresh again until 3:00pm." Two small issues: (1) the timer is exactly one hour with no randomness, so if something triggers refreshes for many brands at once, they all come up for refresh again at the same time an hour later; (2) the timer is stored in a shared notebook (Redis) but the code doesn't insist on that notebook — if accidentally pointed at individual scratchpads (file cache), each worker sees its own timer and multiple refreshes happen. The fix is to add a small random offset to the timer and explicitly say "use the shared notebook."
    - **Evidence:**
        ```php
        $cacheKey = "shopify:brand_design_sync:{$integration->id}";
        if (Cache::add($cacheKey, true, now()->addHour())) {
            SyncShopifyBrandDesignJob::dispatch((string) $integration->id);
        }
        ```
    - `[DRAFT, confidence: 0.7]`
