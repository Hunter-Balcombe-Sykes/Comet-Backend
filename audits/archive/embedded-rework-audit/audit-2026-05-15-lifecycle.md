Both confirmed. No Shopify reconcile job exists anywhere in `app/Jobs`, and the `resolveActive()` swallowed exception at line 205 has no log emit — a DeepSeek miss.

`★ Insight ─────────────────────────────────────`
The `catch (\Throwable) { return null; }` in `resolveActive()` is subtler than it looks: it's nested inside `rememberLockedNullable`, so the cache stores the `null` return and serves it for the full 10-minute TTL. A transient Shopify outage pins every product in the analytics view as "active: null (unknown)" for 10 minutes with no trace in Nightwatch.
`─────────────────────────────────────────────────`

# Lifecycle Audit — 2026-05-15

**Branch:** development
**Lens:** Lifecycle correctness: race-safety, idempotency, anchor decoupling, reconcile loops, vendor resilience, observability discipline
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Http/Controllers/Api/Internal/EmbeddedConnectController.php
- app/Http/Controllers/Api/Internal/EmbeddedSetupController.php
- app/Http/Controllers/Api/Internal/EmbeddedOrderAnalyticsController.php
- app/Http/Controllers/Api/Internal/EmbeddedProductAnalyticsController.php
- app/Http/Controllers/Api/Internal/EmbeddedProductSettingsController.php
- app/Http/Middleware/Auth/VerifyShopifySessionToken.php
- app/Http/Controllers/Api/Webhooks/ShopifyAppUninstalledWebhookController.php
- app/Http/Controllers/Concerns/HandlesShopifyWebhook.php
- app/Services/Shopify/ShopifyShopResolver.php
- app/Http/Requests/Api/Internal/Embedded/*.php
- bootstrap/app.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 2 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 2 complete

---

## P1 — Fix before pilot launch

- [ ] **#LIFE-1** · P1 — No daily reconcile job for missed Shopify `app/uninstalled` webhooks
    - **Where:** app/Http/Controllers/Api/Webhooks/ShopifyAppUninstalledWebhookController.php (entire file; no reconcile counterpart exists in app/Jobs/Shopify/)
    - **Affects:** Brand integration status correctness for all 200 brands at pilot. A missed uninstall delivery leaves the integration in a Connected-but-invalid state: `access_token` is still non-null so `BrandStatusService::determine()` doesn't flag Disconnected, but every subsequent catalog-sync, webhook-registration, and metafield-write job fails with a 401. At 200 brands, several missed deliveries per month are expected at Shopify's documented "at-least-once, occasionally zero" delivery guarantee — without a reconcile loop, recovery requires a manual Ops ticket.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create `ReconcileStuckShopifyIntegrationsJob` (mirrors `ReconcileStuckPayoutsJob` shape): query `professional_integrations` where `provider = 'shopify'` AND `access_token IS NOT NULL` AND `provider_metadata->>'disconnected_at' IS NULL`; for each, hit `https://{shop}/admin/api/{version}/shop.json` with the stored token; on 401 or domain-mismatch, null the token and write `disconnected_at` + `disconnected_reason = 'reconcile_detected_revocation'`, then sync brand status.
        - Log a distinct `shopify.reconcile.healed` event with `professional_id` and `shop_domain` on every auto-heal so drift is visible in Nightwatch.
        - Schedule daily via `routes/console.php` (e.g. `3 2 * * *`).
        - Add a "stuck for > 7 days" Nightwatch alert: integrations where `access_token IS NULL` AND `brand_status != 'disconnected'` — these are the silent drift cases the reconcile job catches but doesn't immediately resolve if status sync fails.
    - **Technical:** The Stripe lifecycle audit mandated a `ReconcileStuckTransferringPayoutsJob` (pattern: daily reconcile job) because any vendor-driven state transition must have a reconcile loop that catches missed deliveries — webhooks are at-least-once and *occasionally zero*. The identical pattern applies here: uninstall transitions brand status to Disconnected via the `app/uninstalled` webhook, but no sibling job verifies that transition actually occurred. `ReconcileStuckPayoutsJob` is the canonical model — same structure, same logging contract, same Nightwatch attribution. Glob of `app/Jobs/**/*Reconcile*.php` confirms only the Stripe job exists; no Shopify equivalent.
    - **Plain English:** When a brand removes the Partna app from their Shopify store, Shopify sends us a "goodbye" signal. If that signal gets lost — which happens occasionally with any third-party notification system — we keep treating the brand as connected even though their keys no longer work. Every automated task we run for that brand silently fails. We need a daily health check that asks Shopify "are these brands still installed?" and cleans up the ones that aren't, the same way we recently added a daily check for stuck payment transfers.
    - **Evidence:**
        ```php
        // ShopifyAppUninstalledWebhookController.php — state transition via webhook only
        $integration->update([
            'access_token' => null,
            'refresh_token' => null,
            'provider_metadata' => $metadata,
        ]);
        // ...
        PurgeAffiliateProductSelectionsJob::dispatch((string) $integration->professional_id);
        ```
        ```
        // app/Jobs/Shopify/ — confirmed via directory listing: no reconcile job exists.
        // Only ReconcileStuckPayoutsJob.php exists in app/Jobs/Stripe/.
        ```

- [ ] **#LIFE-2** · P1 — `app/uninstalled` webhook controller missing `X-Shopify-Webhook-Id` dedup
    - **Where:** app/Http/Controllers/Api/Webhooks/ShopifyAppUninstalledWebhookController.php (entire `__invoke`; contrast with app/Http/Controllers/Concerns/HandlesShopifyWebhook.php:63–84)
    - **Affects:** All 200 brands. Every other Shopify webhook controller (orders/paid, orders/updated, orders/cancelled, orders/edited, refunds/create, shop/update, theme-published) uses the `HandlesShopifyWebhook` trait which atomically claims `X-Shopify-Webhook-Id` via `Cache::add` before processing. The uninstall controller does not. Shopify documents at-least-once delivery; the dangerous scenario is a **delayed retry after reinstall**: brand uninstalls → Shopify sends webhook (first delivery) → brand reinstalls → `provisionShopifyIntegration` saves new token and clears `disconnected_at` → Shopify retries the original uninstall hours later → the retry clears the new token and sets `disconnected_at` again. The brand is stuck in Disconnected status immediately after reinstalling.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract `X-Shopify-Webhook-Id` from the request header after HMAC validation.
        - Add `Cache::add("shopify:webhook:app-uninstalled:{$webhookId}", true, config('partna.cache.ttls.webhook_idempotency'))` — return `$this->success(['received' => true])` on `false` (duplicate), mirroring the pattern in `HandlesShopifyWebhook`.
        - Long term: migrate `ShopifyAppUninstalledWebhookController` to extend `HandlesShopifyWebhook` so future improvements to the trait (DB-level dedup, retry-safe dispatch) are inherited automatically.
    - **Technical:** Category 1 (idempotency on the write path). The `HandlesShopifyWebhook` trait (lines 63–84) shows the canonical dedup pattern — atomic `Cache::add` keyed by `X-Shopify-Webhook-Id` with no pre-probe, executed only after HMAC passes. The uninstall controller pre-dates this trait and was never migrated. All six other Shopify webhook controllers use it. At 200 brands, Shopify typically retries uninstall webhooks within minutes to hours; the cache TTL dedupes same-day retries but delayed retries (cross-TTL) bypass dedup regardless — which is why LIFE-1 (the reconcile job) is the complementary fix. Canonical replacement: `lockForUpdate + UNIQUE` (here via the Cache-backed dedup gate in the shared trait).
    - **Plain English:** Shopify may send us the same "this brand uninstalled the app" message more than once — it's how their notification system works. Every other type of Shopify message we handle is already protected against duplicates. The uninstall message isn't. The worst case: a brand removes and immediately re-adds our app, we successfully reconnect them, then a delayed copy of the "uninstalled" message arrives and disconnects them again — they'd see an error immediately after reinstalling, with no obvious reason why.
    - **Evidence:**
        ```php
        // ShopifyAppUninstalledWebhookController.php — no webhook-ID extraction
        $rawBody = (string) $request->getContent();
        $signature = (string) $request->header('X-Shopify-Hmac-SHA256', '');
        $shopDomain = strtolower(trim((string) $request->header('X-Shopify-Shop-Domain', '')));
        // X-Shopify-Webhook-Id is never read; no Cache::add dedup gate follows.
        ```
        ```php
        // HandlesShopifyWebhook.php:63–84 — the pattern that should be adopted
        $webhookId = (string) $request->header('X-Shopify-Webhook-Id', '');
        // ...
        if ($webhookId !== '') {
            $cacheKey = "{$this->dedupCachePrefix()}:{$webhookId}";
            if (! Cache::add($cacheKey, true, (int) config('partna.cache.ttls.webhook_idempotency'))) {
                return $this->success(['received' => true, 'duplicate' => true]);
            }
        }
        ```

---

## P2 — Should fix

- [ ] **#LIFE-3** · P2 — `provisionShopifyIntegration` merges JSONB provider metadata without a row lock
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedSetupController.php, method `provisionShopifyIntegration` (the `$existing` query)
    - **Affects:** Brand integrations across all 200 brands. The embedded app calls this endpoint on every admin page load for token refresh. On a busy brand (multiple admin tabs open, a Remix SSR fan-out), two concurrent requests both read `$existingMetadata`, merge their respective changes, and write — the second write silently overwrites the first. Lost writes include `webhook_registration_state`, `scopes`, and collection handles; a lost `webhook_registration_state = 'registered'` reset to `'queued'` re-dispatches all six setup jobs on the next load.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the read-merge-write in a `DB::transaction` and add `->lockForUpdate()` to the `$existing` query, mirroring the pattern in `EmbeddedConnectController::connect()` which already does this correctly for the same model.
        - The existing `UniqueConstraintViolationException` catch on the outer `updateOrCreate` remains valid; the lock serialises concurrent same-brand updates so the second caller sees the first's committed state.
    - **Technical:** Category 2 (race-safety on read-modify-write). `EmbeddedConnectController::connect()` is the canonical in-repo reference — it wraps the same `ProfessionalIntegration` read-merge-write in `DB::transaction` with `->lockForUpdate()->first()` and catches `UniqueConstraintViolationException` on the outer write. `provisionShopifyIntegration` does the same JSONB merge pattern without any of these guards. The `$isNoOpRefresh` guard that skips cache-busting is computed from `$existing` before the merge, so it doesn't protect the merge itself. Canonical replacement: `lockForUpdate + UNIQUE`.
    - **Plain English:** When two browser tabs open the Shopify admin app at the same moment, both send a "here's my access token, save it" request simultaneously. Both read the current saved settings, make their own additions, and write. The last one to finish wins — so the first tab's changes vanish. In practice this resets a flag that says "webhooks are registered," which causes us to dispatch a whole batch of setup jobs unnecessarily on the next page load. A one-line fix holds a database-level "I'm writing this row" lock so requests queue up rather than racing.
    - **Evidence:**
        ```php
        // EmbeddedSetupController.php — provisionShopifyIntegration()
        $existing = ProfessionalIntegration::query()
            ->where('professional_id', $professionalId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();                              // no lockForUpdate, no transaction

        $existingMetadata = is_array($existing?->provider_metadata) ? $existing->provider_metadata : [];
        // ...
        $metadata = array_merge($existingMetadata, [
            'shop_domain' => $shopDomain,
            'shop_id' => $data['shop_id'] ?? Arr::get($existingMetadata, 'shop_id'),
            'scopes' => $scopesArray ?: Arr::get($existingMetadata, 'scopes', []),
            'connected_at' => now()->toIso8601String(),
            'webhook_registration_state' => 'queued',
        ]);
        ```
        ```php
        // EmbeddedConnectController.php — the correct pattern in the same repo
        $existing = ProfessionalIntegration::query()
            ->where('professional_id', $professionalId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->lockForUpdate()
            ->first();
        ```

- [ ] **#LIFE-4** · P2 — Synchronous Shopify Admin API round-trip on every embedded page load
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedSetupController.php, method `provisionShopifyIntegration` (call to `validateShopifyAccessToken`); method `validateShopifyAccessToken` (`Http::timeout(10)->get(...)`)
    - **Affects:** Embedded app p99 latency for all 200 brands. `validateShopifyAccessToken` makes a synchronous `GET /admin/api/{version}/shop.json` on every call to `provisionShopifyIntegration`, including the no-op token-refresh path that fires on every admin page load. The `$isNoOpRefresh` guard that skips cache-busting is computed after the validation call, so even identical token refreshes incur a 1–3 s Shopify round-trip. At 200 brands × ~10 admin page loads/day = 2,000+ Shopify API calls/day with no caching, all on request threads.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Cache the validation result with a short TTL (60–120 s), keyed by `"shopify:token-valid:{$shopDomain}:".hash('sha256', $accessToken)`. A token rotation (new token string) produces a different key, so the cache always validates fresh tokens — revocation window is at most 60 s, which is acceptable given the code already allows transient 5xx outages through with `valid: true`.
        - Use `rememberLocked` (the existing `CacheLockService`) so concurrent page-load fan-outs share one validation call rather than N parallel Shopify requests.
        - Invalidate (forget) the key inside `provisionShopifyIntegration` when the stored token actually changes — ensures a newly revoked-then-reissued token is re-validated on the next persist, not served from the stale cache.
    - **Technical:** Category 6 (vendor-integration hygiene; synchronous vendor call on request thread). The security rationale for the validation is sound (the inline comment explains cross-shop token substitution risk). The fix preserves the security contract — a brand submitting a different token always bypasses the cache since the key includes `sha256($accessToken)` — while eliminating the per-load Shopify round-trip for the common no-op-refresh case. `CacheLockService::rememberLocked` with a 60 s TTL is the in-repo canonical tool (`EmbeddedSetupController::brandProfile` already uses it for the storefront-status probe on the same hot path). Canonical pattern: `rememberLocked`.
    - **Plain English:** Every time a brand manager opens any page in the Shopify admin app, our server phones Shopify to verify the brand's API keys are still valid. That's like a security guard calling headquarters to check your badge every time you open a door inside the building — once you're in, you should be trusted for a minute. The keys don't expire in seconds; we can remember "this key checked out fine 60 seconds ago" and skip the phone call, making the app load noticeably faster for every brand admin.
    - **Evidence:**
        ```php
        // EmbeddedSetupController.php — validateShopifyAccessToken always runs
        // before $isNoOpRefresh is evaluated
        $validation = $this->validateShopifyAccessToken($shopDomain, $data['access_token']);
        if (! $validation['valid']) { ... }

        $integration = ProfessionalIntegration::updateOrCreate(...);
        // ...
        $isNoOpRefresh = ! $needsJobDispatch
            && $existing !== null
            && $existing->access_token === $data['access_token'];
        ```
        ```php
        // validateShopifyAccessToken() — synchronous HTTP call, 10 s timeout
        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $accessToken,
            'Accept' => 'application/json',
        ])->timeout(10)->get($url);
        ```

- [ ] **#LIFE-5** · P2 — Swallowed Shopify API exceptions on write and read paths in `EmbeddedProductSettingsController`
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedProductSettingsController.php:319 (`fetchVariants`), :372 (`isInCollection`)
    - **Affects:** All brands using the product-settings Shopify extension. The write-path failure (`fetchVariants` line 319) is the more serious case: `saveVariantEnabledStates` calls `fetchVariants`, which catches all `\Throwable` and returns `[]`; the caller then iterates an empty array, writes nothing to Shopify, and `update()` returns `$this->success(['message' => 'Saved.'])` — the brand sees "Saved" but the variant enabled-states were not updated. The read-path failure (`isInCollection` line 372) silently returns `false`, so `show()` reports the product as "not in collection" when the real answer is "unknown due to a Shopify error." Neither failure appears in Nightwatch.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `fetchVariants` (write-path): replace `catch (\Throwable $e) { return []; }` with a `Log::warning` call including `shop_domain`, `product_id`, `operation: 'fetchVariants'`, and `error: $e->getMessage()`, then re-throw (or throw a `\RuntimeException` wrapping it) so `saveVariantEnabledStates` → `update()` surfaces a 422 to the UI instead of a false-success.
        - In `isInCollection` (read-path): add a `Log::warning` with `shop_domain`, `collection_handle`, `product_gid`, `operation: 'isInCollection'`, and `error_class: class_basename($e)` before returning `false`. Returning `false` (not throwing) is acceptable on a read path, but silent is not.
        - Note: `fetchProductMetafields` (line 252) already has `Log::error` with correct context and is not affected.
        - Canonical pattern: `Log-with-context`.
    - **Technical:** Category 10 (observability). The Stripe audit pattern "a function with N distinct outcomes needs N distinct log strings" applies here. `fetchVariants` has two outcomes — success or Shopify-error — but only emits output on success; the error path is invisible. `isInCollection` has the same gap. `fetchProductMetafields` in the same file is the correct reference: it logs `shop_domain`, `product_id`, `status`, and `errors` before returning empty. DeepSeek's draft incorrectly cited `fetchProductMetafields` as a swallowed exception; adjudicator verified it has `Log::error` at line 252.
    - **Plain English:** If Shopify's servers have a hiccup while a brand is managing product settings, our code quietly pretends everything worked. On the save side, a brand disables a variant, sees a green "Saved" confirmation, but the change was never sent to Shopify — they'd only discover the problem if they went back to check the settings. We need to surface these errors properly: tell the brand something went wrong (so they can retry), and log a note so our team can see it in the monitoring dashboard.
    - **Evidence:**
        ```php
        // EmbeddedProductSettingsController.php:319 — fetchVariants (write-path)
        // called by saveVariantEnabledStates; caller returns void, update() returns success
        } catch (\Throwable $e) {
            return [];   // no log; write path silently succeeds with no-op
        }
        ```
        ```php
        // EmbeddedProductSettingsController.php:372 — isInCollection (read-path)
        } catch (\Throwable) {
            return false;    // no log
        }
        ```
        ```php
        // fetchProductMetafields (line 252) — the correct pattern in the same file
        } catch (\Throwable $e) {
            Log::error('Shopify Admin API exception fetching product metafields.', [
                'shop_domain' => $shopDomain,
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);
            return $empty;
        }
        ```

---

## P3 — Nice to have

- [ ] **#LIFE-6** · P3 — Stale `'2025-01'` API version fallback in `EmbeddedProductSettingsController`
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedProductSettingsController.php (`fetchProductMetafields`, `fetchVariants`, `isInCollection`, `saveMetafield`, `saveVariantMetafield`, `toggleCollection` — six occurrences of `config('services.shopify.api_version', '2025-01')`)
    - **Affects:** All brands using the product-settings extension if `services.shopify.api_version` is ever absent from the environment. The fallback `'2025-01'` is two major versions behind the current documented API version (`2026-04` per CLAUDE.md), and is inconsistent with `validateShopifyAccessToken` in the same codebase which falls back to `'2026-04'`. If an operator deploys without the config key, product-settings calls target a deprecated API version while token-validation calls target the current one.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace all six `config('services.shopify.api_version', '2025-01')` occurrences with `config('services.shopify.api_version')` (no fallback).
        - Add an assertion in `AppServiceProvider::boot()` (or an `artisan config:validate` command) that `services.shopify.api_version` is non-empty in non-test environments, so a missing env var surfaces at boot rather than at request time with a silent bad-version fallback.
        - Canonical pattern: vendor API version pinning (the `STRIPE_API_VERSION` pattern extended to Shopify).
    - **Plain English:** We have a single master setting that controls which version of Shopify's rules we follow. Six places in one file have an old version number baked in as a fallback — if the master setting ever goes missing from our server config, those calls use rules from over a year ago. A quick cleanup removes all the hardcoded fallbacks so the code fails loudly (at startup) rather than silently (at request time with deprecated API calls).
    - **Evidence:**
        ```php
        // EmbeddedProductSettingsController.php — fetchProductMetafields (and 5 other methods)
        $apiVersion = config('services.shopify.api_version', '2025-01');
        ```
        ```php
        // EmbeddedSetupController.php::validateShopifyAccessToken — inconsistent fallback
        $apiVersion = (string) config('services.shopify.api_version', '2026-04');
        ```

- [ ] **#LIFE-7** · P3 — Swallowed exception in `EmbeddedProductAnalyticsController::resolveActive()` caches `null` for full TTL
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedProductAnalyticsController.php:205
    - **Affects:** Product analytics views for all brands during Shopify API disruptions. The exception is caught inside the `rememberLockedNullable` closure, which means `null` is stored in the cache for the full 10-minute TTL. All subsequent requests for the same `(brand, product)` pair serve the cached `null` (displayed as "active: unknown") for up to 10 minutes, with no Nightwatch trace that a Shopify error occurred. Adjudicator-added finding; DeepSeek did not flag this.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `Log::warning` before `return null` with `professional_id`, `product_id`, `operation: 'resolveActive'`, and `error_class: class_basename($e)` (class name only — the exception message may contain GIDs or tokens).
        - Consider returning a sentinel that distinguishes "Shopify unreachable" from "metafield not set yet" so the UI can show a transient error state rather than treating the product as newly-onboarded.
        - Canonical pattern: `Log-with-context`.
    - **Plain English:** When checking whether a specific product is enabled in our system, we ask Shopify for the answer. If Shopify is temporarily unreachable, the code stores a "no answer" blank in our short-term memory for ten minutes, and during that time the analytics screen shows every product as if its status is unknown. We have no record of this happening, so if a brand reports missing product status we'd have nothing to look at. Adding one log line means our monitoring dashboard would flag the Shopify connection issue immediately.
    - **Evidence:**
        ```php
        // EmbeddedProductAnalyticsController.php:200–207 — inside rememberLockedNullable closure
        try {
            return $this->catalog->fetchProductActiveMetafield(
                $integration,
                "gid://shopify/Product/{$productId}",
            );
        } catch (\Throwable) {
            return null;   // cached for 10 min; no log emitted
        }
        ```
