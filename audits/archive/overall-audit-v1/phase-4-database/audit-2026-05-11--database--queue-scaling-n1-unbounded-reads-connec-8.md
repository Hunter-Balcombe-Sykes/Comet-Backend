`★ Insight ─────────────────────────────────────`
`NotificationPublisher::publish()` is already fully async — a single `insertOrIgnore` then a conditional `->onQueue('mail')` job dispatch. SCALE-7 evaporates on inspection. Similarly, the analytics tables have existed with `(professional_id, occurred_at)` composite indexes since the baseline migration — the `de9bb8b` drop-duplicate commit only removed a second identical copy, not the index itself. Both false positives required file reads to confirm; tool verification before accepting DeepSeek's claims prevented two spurious findings from reaching the final report.
`─────────────────────────────────────────────────`

# Database & Queue Scaling Audit — 2026-05-11

**Branch:** development
**Lens:** Database & queue scaling: N+1, unbounded reads, connection scoping, queue shape, vendor budgets, migration safety, backpressure
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Http/Controllers/Api/Internal/EmbeddedProductSettingsController.php
- app/Http/Controllers/Api/Internal/EmbeddedProductAnalyticsController.php
- app/Http/Controllers/Api/Internal/EmbeddedSetupController.php
- app/Http/Controllers/Api/Internal/HydrogenDeploymentController.php
- app/Http/Controllers/Api/Webhooks/ShopifyAppUninstalledWebhookController.php
- app/Http/Controllers/Api/Webhooks/StripeConnectWebhookController.php
- app/Http/Controllers/Api/Staff/StaffSite/StaffStatsController.php
- app/Services/Notifications/NotificationPublisher.php
- supabase/migrations/20260403000000_v2_baseline.sql
- supabase/migrations/20260420220000_add_analytics_ledger_occurred_at_indexes.sql
- supabase/migrations/20260506000000_create_orders_schema.sql
- supabase/migrations/20260506600000_rename_ledger_to_movements.sql
- routes/console.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 4 complete
- P2 Medium: 0 of 3 complete

---

## P1 — Fix before pilot launch

- [ ] **#SCALE-1** · P1 — EmbeddedProductSettingsController burns 3+ Shopify API calls per GET and up to N+1 per PATCH with no rate-limit governance
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedProductSettingsController.php — `show()` lines 88–141; `saveVariantEnabledStates()` lines 473–489; `saveMetafield()` lines 358–468
    - **Affects:** Shopify Admin GraphQL rate-limit bucket (points-based leaky bucket, ~1000 points/s, shared per store across all API clients). Each GET fires one Admin API call plus two Storefront API calls. Each PATCH with `disabled_variant_gids` calls `fetchVariants()` separately (even though `fetchProductMetafields()` already returned variants) then issues one mutation per changed variant. At 200 brands with staff using the embedded panel across 3–5 products in quick succession, the bucket drains and blocks automated jobs (webhook registration, catalog delta sync, order processing) for all brands on the same Shopify store.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - In `saveVariantEnabledStates()`, use the variant data already returned by `fetchProductMetafields()` instead of calling `fetchVariants()` a second time — pass the variant list as a parameter.
        - Replace the per-variant `saveVariantMetafield()` loop with a single `metafieldsSet` bulk mutation that updates all changed variants in one round-trip.
        - In `saveMetafield()`, replace the two-call pattern (query existing ID → update/create) with a single `metafieldsSet` mutation that uses owner-type + namespace + key as the idempotent key — Shopify's `metafieldsSet` upserts without needing the existing metafield ID.
        - Track `X-Shopify-Graphql-Cost` response headers and enforce a per-integration budget before issuing subsequent calls; return 429 with `Retry-After` to the embedded client when budget is exhausted.
    - **Technical:** `fetchProductMetafields()` already fetches variant data in its GraphQL query via `variants(first: 50)` — the response is parsed into `$variants` and returned. `saveVariantEnabledStates()` ignores this and calls `fetchVariants()` a second time for the same product ID, firing an identical Admin API query. Then, for each changed variant, it calls `saveVariantMetafield()` which makes yet another Admin API mutation. A 50-variant product with all variants toggled generates 1 (fetch) + 50 (set) = 51 API calls for a single PATCH. The `saveMetafield()` path compounds this: it fires a read query to get the existing metafield ID before deciding to create or update. No code path inspects the `X-Shopify-Graphql-Cost` response header, so the bucket drains silently. Shopify's Admin API points bucket is per-store, so a brand's staff session can exhaust capacity that Shopify webhooks and the order pipeline also need.
    - **Plain English:** Every time someone opens a product in the Shopify admin sidebar, we knock on Shopify's door three times in a row. When they change which product variants are enabled, we fetch the variant list again (even though we just fetched it), then send a separate update for every single variant that changed — 50 variants means 50 individual API calls. Shopify only allows so many API calls per second before slamming the door for everyone, including automated background jobs that process orders and sync catalogs. The fix is to combine reads with saves and use a single "update everything at once" API call Shopify already provides.
    - **Evidence:**
        ```php
        // show() — 3 separate API calls (1 Admin + 2 Storefront)
        $result = $this->fetchProductMetafields($integration, $productId);
        $inFavourites = $this->isInCollection($metadata, 'favourites_collection_handle', $productGid, $integration);
        $inDefault = $this->isInCollection($metadata, 'default_collection_handle', $productGid, $integration);
        ```
        ```php
        // saveVariantEnabledStates — calls fetchVariants() even though fetchProductMetafields()
        // already returned all variant data; then N individual mutation calls
        private function saveVariantEnabledStates(ProfessionalIntegration $integration, string $productGid, array $disabledGids): void
        {
            $variants = $this->fetchVariants($integration, $this->extractId($productGid));
            foreach ($variants as $variant) {
                $shouldEnable = ! in_array($variant['gid'], $disabledGids, true);
                if ($variant['enabled'] !== $shouldEnable) {
                    $this->saveVariantMetafield($integration, $variant['gid'], $shouldEnable);
                }
            }
        }
        ```

- [ ] **#SCALE-2** · P1 — HydrogenDeploymentController::targets() N+1 queries ProfessionalIntegration inside a Collection::map() loop
    - **Where:** app/Http/Controllers/Api/Internal/HydrogenDeploymentController.php:44–57
    - **Affects:** CI deployment pipeline — the GitHub Actions workflow_dispatch trigger calls this endpoint. At 200 brands, 200 separate `professional_integrations` queries fire inside `$settings->map()`, adding ~200ms latency and consuming 200 connection pool slots under concurrent deploys.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Pre-fetch all Shopify shop domains in one query keyed by `professional_id`, then use the map as a lookup: `ProfessionalIntegration::query()->whereIn('professional_id', $settings->pluck('professional_id'))->where('provider', ...)->pluck('shopify_shop_domain', 'professional_id')`.
        - Replace the per-row `->value()` call inside `map()` with a lookup into that pre-fetched collection.
    - **Technical:** `$query->get()` returns a collection of `BrandStoreSettings` rows. Each iteration of `$settings->map()` executes `ProfessionalIntegration::query()->where('professional_id', $row->professional_id)->where('provider', ...)->value('shopify_shop_domain')`. This is a classic N+1: 1 query to load `BrandStoreSettings` rows becomes N+1 queries total. At 200 brands, 1 query becomes 201. The `professional_integrations` table has a composite index on `(provider, professional_id)` so each lookup is fast in isolation — but 200 sequential round-trips to Postgres consume connection pool slots and add latency that can cause deployment timeouts in the CI runner.
    - **Plain English:** When the deployment pipeline asks "which brands need a new storefront build?", it gets the brand list from the database and then individually calls back to ask "what's this brand's Shopify domain?" for every single brand — 200 brands means 200 separate database calls. It's like printing a guest list then calling each guest individually to ask their address instead of looking everyone up in one batch. The fix is to look up all the Shopify domains in a single database query upfront.
    - **Evidence:**
        ```php
        $settings = $query->get(['professional_id', 'oxygen_deployment_token', 'oxygen_storefront_id']);

        $targets = $settings->map(function (BrandStoreSettings $row) {
            $integration = ProfessionalIntegration::query()
                ->where('professional_id', $row->professional_id)
                ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
                ->value('shopify_shop_domain');

            return [
                'shop_domain' => $integration,
                'oxygen_deployment_token' => $row->oxygen_deployment_token,
                'oxygen_storefront_id' => $row->oxygen_storefront_id,
            ];
        })->values();
        ```

- [ ] **#SCALE-3** · P1 — ShopifyAppUninstalledWebhookController executes an unbounded single-statement DELETE synchronously inside the webhook handler
    - **Where:** app/Http/Controllers/Api/Webhooks/ShopifyAppUninstalledWebhookController.php:99–101
    - **Affects:** Webhook handler latency and row-level lock scope. A brand with 10+ active affiliates each curating 200+ products accumulates ~2K rows; brands using "select all in collection" may accumulate 5K–10K rows. A single-statement `DELETE WHERE brand_professional_id = ?` acquires row-level locks on every matching row for the duration of the scan, blocking any concurrent webhook that touches the same table (e.g., product sync updating selection metadata). The webhook response is also held open while the delete runs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Dispatch a `PurgeAffiliateProductSelectionsJob` immediately after the `$integration->update()` call, and remove the inline delete. The job can delete in `->chunkById(500, fn($chunk) => AffiliateProductSelection::whereIn('id', $chunk->pluck('id'))->delete())` batches.
        - Return `$this->success(['received' => true])` without waiting for the deletion to complete — Shopify doesn't need the deletion result.
    - **Technical:** `AffiliateProductSelection::query()->where('brand_professional_id', ...)->delete()` translates to a single `DELETE FROM commerce.affiliate_product_selections WHERE brand_professional_id = ?`. PostgreSQL acquires a row-level exclusive lock on every matching row in one pass. At 10K rows this is fast (< 50ms) but still ties up a connection and blocks concurrent writers on the same table for that window. More critically, the webhook handler is synchronous — PHP-FPM holds the worker and the Postgres connection open until the delete completes. The comment in the code acknowledges Shopify's access token is already revoked before this webhook fires, which is the reason the full Shopify teardown service isn't called — that's correct reasoning, but the deletion itself still belongs in a background job.
    - **Plain English:** When a brand uninstalls the Shopify app, we try to clean up their product recommendations before telling Shopify "got it." If a brand had thousands of recommendations, the cleanup holds the database table locked while it runs. Shopify is patiently waiting for our response the whole time. Moving the cleanup to a background job means we immediately say "received" to Shopify, then quietly clean up afterward in small batches that don't hold any locks for long.
    - **Evidence:**
        ```php
        $deletedSelections = AffiliateProductSelection::query()
            ->where('brand_professional_id', $integration->professional_id)
            ->delete();
        ```

- [ ] **#SCALE-4** · P1 — StripeConnectWebhookController makes a synchronous Stripe API call inside handleCheckoutSessionCompleted
    - **Where:** app/Http/Controllers/Api/Webhooks/StripeConnectWebhookController.php:163–181
    - **Affects:** Stripe's webhook response deadline (~10s). `syncPaymentMethodFromCheckoutSession` retrieves the Checkout Session from Stripe's API to extract payment method details — a vendor I/O call that holds the webhook handler open. Stripe's retry schedule begins if the response exceeds 10s; if Stripe's API is slow (common under load), the webhook controller starts returning 5xx, causing Stripe to back off and retry, amplifying the queue of pending payment method syncs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Dispatch a `SyncPaymentMethodFromCheckoutSessionJob` passing `$professional->id` and `$session->id`. Return `response()->json(['received' => true])` immediately after the `WebhookEvent` idempotency guard.
        - Set `$tries = 3` and `$backoff = [5, 15, 30]` on the job to handle transient Stripe API failures without retry storms.
    - **Technical:** The `match ($session->mode ?? null)` block calls `$service->syncPaymentMethodFromCheckoutSession($professional, $session->id)` synchronously. This method must call Stripe's API (`Session::retrieve($sessionId)`) to obtain the payment method ID attached to the completed setup. That network call happens inside the `__invoke` lifecycle while Stripe's webhook delivery timer is running. This path is triggered on `mode === 'setup'` — i.e., when brands or affiliates set up payment methods via Stripe Checkout. It's less frequent than `payment` mode but the pattern is wrong regardless: vendor I/O should never block webhook acknowledgement. A Postgres connection is also held open while waiting on Stripe's API response.
    - **Plain English:** When a brand finishes setting up their payment method through Stripe's checkout flow, Stripe sends us a notification. Instead of saying "got it" right away, we immediately call Stripe back to fetch the payment details while Stripe is still waiting for our "got it." If Stripe's servers are busy, Stripe eventually gives up waiting for our acknowledgement and tries again later, causing the same double-call. The fix is to say "received" immediately and then fetch the details in a background task.
    - **Evidence:**
        ```php
        match ($session->mode ?? null) {
            'setup' => $service->syncPaymentMethodFromCheckoutSession(
                $professional,
                $session->id
            ),
        ```

---

## P2 — Should fix

- [ ] **#SCALE-5** · P2 — EmbeddedSetupController::setupDomain and provisionDomainTxt make synchronous Cloudflare API calls in the request path
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedSetupController.php — `setupDomain()` lines 337–352; `provisionDomainTxt()` lines 371–385
    - **Affects:** Embedded wizard domain setup UX and PHP-FPM worker capacity. Each call blocks the request on Cloudflare's DNS API (typically 200–500ms, up to several seconds during incidents). Cloudflare's API rate limit is ~1200 requests/5min zone-wide; a bulk onboarding of 20+ brands in a short window could hit this, causing wizard failures for all concurrent onboarders.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `setupDomain()`: `ProvisionBrandDnsJob` already exists at `app/Jobs/Cloudflare/ProvisionBrandDnsJob.php` and handles exactly the CNAME upsert idempotently. Dispatch `ProvisionBrandDnsJob::dispatch($professionalId)` instead of calling `$dns->upsertCname()` inline. The `oxygen_storefront_id` DB write can stay synchronous since it's a local write only.
        - `provisionDomainTxt()`: Create a `ProvisionBrandDnsTxtJob` that wraps `$dns->upsertTxt($recordName, $txtValue)`, dispatched immediately after the DB write. The wizard should poll `/embedded/domain-status` (which reads from the DB, not Cloudflare) for completion rather than waiting for the synchronous response.
        - Add per-brand debounce (e.g., `Cache::add("dns:provision:{$professionalId}", true, 30)`) to prevent double-dispatch from wizard re-clicks.
    - **Technical:** `new CloudflareDnsService; $dns->upsertCname(...)` and `new CloudflareDnsService; $dns->upsertTxt(...)` are synchronous HTTP calls to Cloudflare's API issued inside `EmbeddedSetupController` action methods. The controller holds the PHP-FPM worker and an implicit Postgres connection for the duration of the Cloudflare round-trip. `ProvisionBrandDnsJob` (which already has `$tries = 3`) was built for exactly this purpose but is not used on the `setupDomain` path — `EmbeddedSetupController` bypasses it and calls the service directly. The wizard currently makes the brand wait on a Cloudflare response to proceed, which creates a poor UX and a worker-consumption risk under concurrent onboarding.
    - **Plain English:** When a brand clicks "Set up my domain" in the wizard, we call Cloudflare to create their DNS record and make the brand sit and wait for Cloudflare to respond before we show them "done." If Cloudflare is slow or many brands are onboarding at once, the wizard hangs. We actually already have a background worker job (`ProvisionBrandDnsJob`) that does this work properly — we just aren't using it in the wizard. The fix is to queue the Cloudflare call and let the wizard poll for completion instead of waiting.
    - **Evidence:**
        ```php
        $dns = new CloudflareDnsService;
        $dns->upsertCname($subdomain, 'shops.myshopify.com', false);
        ```
        ```php
        $dns = new CloudflareDnsService;
        $dns->upsertTxt($recordName, (string) $data['txt_value']);
        ```

- [ ] **#SCALE-6** · P2 — EmbeddedProductAnalyticsController::resolveActive fetches the entire brand catalog from Shopify to resolve a single product's boolean flag on every cache miss
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedProductAnalyticsController.php:161–180
    - **Affects:** Embedded product analytics panel on cache miss. `fetchBrandCatalog()` fetches all products with `sidest.*` metafields via paginated Admin API GraphQL calls. On a cold cache (deploy, Redis flush, first load), multiple concurrent requests from different brands all trigger full catalog fetches simultaneously, draining the Shopify Admin API rate-limit budget for every affected brand.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Store the `sidest.active` flag on the local `commerce.order_items` or a dedicated `brand_product_states` table, populated by the catalog sync job. The `resolveActive()` call becomes a single local DB read: `DB::table('...')->where('shopify_product_id', $productId)->where('brand_professional_id', $professionalId)->value('active')`.
        - Alternatively, cache the active flag independently under a product-scoped key (`embedded:product-active:{professionalId}:{productId}` with a 10m TTL), populated during catalog sync rather than on-demand.
        - As an immediate lower-effort fix: extract `resolveActive()` into its own cache miss path with `Cache::remember("embedded:product-active:{$professionalId}:{$productId}", 300, fn() => ...)` so the full catalog fetch is isolated and doesn't block the analytics render.
    - **Technical:** `$this->catalog->fetchBrandCatalog($professional)` issues one or more Shopify Admin API GraphQL calls, paginating through all products with `sidest.*` metafields. The result is then scanned linearly (`foreach ($catalog as $product)`) to find one product's `active` metafield value. This entire fetch occurs synchronously inside `build()`, which is wrapped by a 5-minute `Cache::memo()->remember()`. The 5-minute cache is effective under steady-state, but on cache miss all concurrent callers block on the same Shopify API round-trip before any of them can prime the cache. At 200 brands with daily embedded-app usage, a rolling deploy or Redis clear produces a thundering herd — all brands simultaneously miss cache and flood the Shopify API with full-catalog fetches.
    - **Plain English:** To answer the simple yes/no question "is this product currently active?", we download the brand's entire product catalog from Shopify — potentially hundreds of products — every 5 minutes on cache miss. It's like checking whether one book is in stock by downloading the entire bookstore's inventory. Under normal use the caching keeps this affordable, but whenever the cache is cleared (after a deploy, for example), every brand's analytics panel simultaneously triggers a full catalog download. Storing the active flag locally avoids the Shopify round-trip entirely.
    - **Evidence:**
        ```php
        private function resolveActive(string $professionalId, string $productId): ?bool
        {
            try {
                $professional = Professional::findOrFail($professionalId);
                $catalog = $this->catalog->fetchBrandCatalog($professional);
            } catch (\Throwable) {
                return null;
            }

            if (! is_array($catalog)) {
                return null;
            }

            $needle = "gid://shopify/Product/{$productId}";

            foreach ($catalog as $product) {
                if (($product['gid'] ?? null) === $needle) {
                    $value = $product['metafields']['active'] ?? null;

                    return is_bool($value) ? $value : null;
                }
            }

            return null;
        }
        ```

- [ ] **#SCALE-7** · P2 — StaffStatsController SUM on commerce.commission_movements status='pending' has no partial index
    - **Where:** app/Http/Controllers/Api/Staff/StaffSite/StaffStatsController.php:76–78
    - **Affects:** Staff ops dashboard aggregate query cost on 60-second cache miss. At 1M orders/year generating 2–10 commission movement rows each, the `commission_movements` table grows to 2M–10M rows. Existing indexes cover `(brand_professional_id, occurred_at)` and `(affiliate_professional_id, occurred_at)` — neither helps a `WHERE status = 'pending'` scan. Without a partial index, PostgreSQL full-scans all pending rows to compute `SUM(amount_cents)`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a partial index: `CREATE INDEX CONCURRENTLY idx_cm_pending_amount ON commerce.commission_movements (amount_cents) WHERE status = 'pending';`
        - Add a corresponding partial index for the active subscriptions count: `CREATE INDEX CONCURRENTLY idx_subscriptions_active_count ON billing.subscriptions (id) WHERE ended_at IS NULL;`
        - Both `CONCURRENTLY` to avoid a table lock on hot tables.
    - **Technical:** `DB::table('commerce.commission_movements')->where('status', 'pending')->sum('amount_cents')` generates `SELECT SUM(amount_cents) FROM commerce.commission_movements WHERE status = 'pending'`. The migration log confirms the table was renamed from `commission_ledger_entries` to `commission_movements` (migration `20260506600000`) and the rename preserved all existing indexes. The preserved indexes are `idx_cle_brand_occurred_at (brand_professional_id, occurred_at)`, `idx_cle_affiliate_occurred_at (affiliate_professional_id, occurred_at)`, and `idx_cm_order_id (order_id) WHERE order_id IS NOT NULL` — none of which covers a status filter. A `(amount_cents) WHERE status = 'pending'` partial index reduces the SUM to an index-only scan over the pending subset. The 60s `CacheLockService` TTL limits the query to once per minute at most, but the per-miss cost scales linearly with table size.
    - **Plain English:** The staff dashboard's "pending commissions" total asks the database to add up every pending commission across the platform. Without a special shortcut (a partial index), the database reads through every single pending commission row to compute the sum — potentially millions of rows as the platform scales. A partial index is like pre-computing a running total on a sticky note every time a new pending commission is added, so the staff dashboard can just read the note instead of recounting. The query runs at most once per minute (it's cached), but that one-per-minute cost grows with every new commission.
    - **Evidence:**
        ```php
        $pendingCommissionCents = DB::table('commerce.commission_movements')
            ->where('status', 'pending')
            ->sum('amount_cents');
        ```

`★ Insight ─────────────────────────────────────`
The `ProvisionBrandDnsJob` (with `$tries = 3`) already exists and handles exactly what `setupDomain()` does inline — this is a case where a background job was built for a use-case but the one controller that triggers the same operation was never updated to use it. Pattern: always grep for existing job classes before proposing "create a new job" in a fix. The `saveVariantEnabledStates()` redundant `fetchVariants()` call is another common antipattern: data was already fetched earlier in the call chain, a helper method fetches it again because it's easier than threading the result through as a parameter.
`─────────────────────────────────────────────────`
