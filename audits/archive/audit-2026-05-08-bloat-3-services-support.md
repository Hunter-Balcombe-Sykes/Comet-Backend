`★ Insight ─────────────────────────────────────`
Before adjudicating: three DeepSeek findings have verbatim evidence confirmed in source. One has a naming discrepancy (`formatMoneyFromCents` vs `formatMoney` in the third instance) that affects the technical analysis. Two additional findings (missed by DeepSeek) surface from cross-file review: `diskName()` duplication with a silent observability gap, and `ensureTxt`/`upsertTxt` left behind after the custom-domain removal commits.
`─────────────────────────────────────────────────`

# Unused Methods, Dead Code, and Unreferenced Bloat in Supporting Services Audit — 2026-05-08

**Branch:** development
**Lens:** Unused methods, dead code, and unreferenced bloat in supporting services
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Services/Stripe/CommissionPayoutService.php
- app/Services/Stripe/CommissionVoidService.php
- app/Services/Stripe/StripeConnectService.php
- app/Services/Stripe/StripeBillingService.php
- app/Services/Cache/SiteCacheService.php
- app/Services/Cache/ProfessionalCacheService.php
- app/Services/Cache/CacheKeyGenerator.php
- app/Services/Cache/CacheLockService.php
- app/Services/Cache/AnalyticsCacheService.php
- app/Services/Media/BrandDesignMediaService.php
- app/Services/Media/ImageVariantService.php
- app/Services/Media/VideoVariantService.php
- app/Services/Media/PlaceholderLimitExceededException.php
- app/Services/Media/UnprocessableImageException.php
- app/Services/Cloudflare/CloudflareDnsService.php
- app/Services/Cloudflare/CloudflareKvService.php
- app/Services/Notifications/CommerceNotificationService.php
- app/Services/Notifications/NotificationPublisher.php
- app/Services/Analytics/AffiliateProjectionsService.php
- app/Services/Analytics/Concerns/ResolvesTimezone.php
- app/Services/Square/SquareApiClient.php
- app/Services/Square/SquareApiException.php
- app/Services/Square/SquareServiceSyncService.php
- app/Services/Square/SquareTokenService.php
- app/Services/Fresha/FreshaApiClient.php
- app/Services/Fresha/FreshaApiException.php
- app/Services/Fresha/FreshaServiceSyncService.php
- app/Services/Fresha/FreshaTokenService.php
- app/Services/Streaming/KickApiClient.php
- app/Services/Streaming/LiveStatusInjector.php
- app/Services/Streaming/LiveStatusPoller.php
- app/Services/Streaming/StreamingTokenManager.php
- app/Services/Streaming/TwitchApiClient.php
- app/Services/Site/SocialLinkNormalizer.php
- app/Services/Customers/ContactCaptureService.php
- app/Services/Auth/SupabaseAdminService.php
- app/Services/Billing/Entitlements.php
- app/Services/Public/PublicSiteResolver.php
- app/Services/Hydrogen/HydrogenDeploymentService.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 6 of 6 complete ✅

---

## P3 — Nice to have

- [x] **#DEAD-1** · P3 — `ensureTxt` and `upsertTxt` may be dead after custom-domain removal
    - **Where:** app/Services/Cloudflare/CloudflareDnsService.php (`ensureTxt` and `upsertTxt` methods)
    - **Affects:** Cloudflare DNS service surface area — both methods add or patch TXT records for what their docblocks describe as "Shopify domain verification challenges," a flow eliminated by recent commits
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Grep controllers, jobs, observers, and commands for calls to `ensureTxt` and `upsertTxt`
        - If no callers remain after the custom-domain cleanup (commits `902cc42`, `2a752fa`), delete both methods
        - While here, verify `upsertCname` callers too — its docblock describes a use case that may also be gone
    - **Technical:** Both methods explicitly name "Shopify domain verification challenges" and "Shopify verification tokens that rotate on each domain-connect attempt" as their purpose. Commit `902cc42` ("feat(cleanup): drop custom-domain capability entirely (Task 4)") and `2a752fa` ("feat: Cloudflare routing + custom-domain removal") together removed the custom-domain feature. If no callers remain, `CloudflareDnsService` carries two live Cloudflare API methods — each capable of creating DNS records in the production zone — that no code path invokes. Caller verification is required before deletion since the codebase was not provided in full.
    - **Plain English:** These two methods were built to help verify ownership of custom domains during Shopify setup — a feature that was then removed from the product. If nothing still calls them, they're loaded guns sitting on the shelf: capable of writing DNS records to the live Cloudflare zone, but pointed at nothing. The fix is to confirm no code still fires them and then remove them.
    - **Evidence:**
        ```php
        /**
         * Ensure a TXT record exists.
         * Used for Shopify domain verification challenges.
         * Returns the Cloudflare record ID, or null on error / dev mode.
         */
        public function ensureTxt(string $name, string $content): ?string

        /**
         * Create or update a TXT record. Unlike ensureTxt (which skips if it exists),
         * this patches the content if the record exists with a different value — needed
         * for Shopify verification tokens that rotate on each domain-connect attempt.
         */
        public function upsertTxt(string $name, string $content): ?string
        ```

- [x] **#NOOP-1** · P3 — `hydrateTypographySettings` is dead scaffolding called on every site cache fill
    - **Where:** app/Services/Cache/SiteCacheService.php (`hydrateTypographySettings` method, called from `hydrateSiteWithBrandTypography`)
    - **Affects:** Public site payload — three method calls deep on every cache fill, which the service docblock identifies as handling 95% of traffic; performs no transformation
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Decide: implement the intended brand typography hydration, or remove the scaffolding
        - If removing: delete `hydrateTypographySettings` and collapse `hydrateSiteWithBrandTypography` (it only assigns `$site['settings']` and returns — the caller can inline that)
        - If keeping as a future hook: add a `// TODO:` comment with the planned implementation and remove the silent pass-through body
    - **Technical:** The call chain is `getPublicSitePayload` → `safeHydrateSitePayload` → `hydrateSiteWithBrandTypography` → `hydrateTypographySettings`. The bottom of that chain returns `$settings` unchanged. `hydrateSiteWithBrandTypography` is `public`, meaning it appears on the class's API surface even though it does nothing of consequence. Brand typography data is already present in `site.settings.design.typography` (as resolved by `resolveBrandPartnerEnrichmentData`), so this method appears to have been scaffolded for a complementary enrichment pass that was never written.
    - **Plain English:** Picture a car wash where the last station — labeled "Final Polish" — has a worker who takes your car, looks at it, and hands it right back without touching it. The station is on the conveyor belt, the worker shows up, but nothing happens. Either put the polish on the car or remove the station — leaving it there tricks everyone into assuming something important is happening there.
    - **Evidence:**
        ```php
        public function hydrateTypographySettings(array $settings, string $brandProfessionalId): array
        {
            return $settings;
        }
        ```

- [x] **#NOOP-2** · P3 — `flushHeldCommissions` is a self-documented no-op retained past its cleanup window
    - **Where:** app/Services/Stripe/CommissionVoidService.php (`flushHeldCommissions` method)
    - **Affects:** `StripeConnectWebhookController` and any tests that call this method — each call writes a log entry and returns 0 with no other effect
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Find all callers of `flushHeldCommissions` in `StripeConnectWebhookController` and test files
        - Remove those call sites — the Phase 4 model picks up newly-eligible approved orders automatically on the next payout cron sweep once `stripe_connect_status` flips to `active`
        - Delete the method once all callers are gone
    - **Technical:** The docblock describes the history precisely: in the legacy commission-ledger model, accruals were held in a 'pending' state until the affiliate connected Stripe; Phase 3.5 moved the source of truth to `commerce.orders` where orders are created as `'approved'` immediately. The method was preserved temporarily so callers could be updated incrementally without a `if (Phase4)` conditional. The MEMORY.md confirms Phase 4 was deployed 2026-05-06. The cleanup window has closed; the method now solely emits a log line on the webhook path on every affiliate Stripe Connect activation event.
    - **Plain English:** This is a retired assembly-line worker who still reports to the factory every day, signs in, writes a note saying "nothing to put together today," and goes home. The method exists so the code that calls it doesn't break while the rest of the Phase 4 migration settles — but that migration is complete. It's time to formally retire the position and remove the manager who keeps calling the desk.
    - **Evidence:**
        ```php
        public function flushHeldCommissions(Professional $affiliate): int
        {
            Log::info('flushHeldCommissions is a no-op in Phase 4+ — orders are approved on creation', [
                'affiliate_id' => (string) $affiliate->id,
            ]);

            return 0;
        }
        ```

- [x] **#LEGACY-1** · P3 — `createSetupIntent` is a live Stripe API call on a documented legacy path
    - **Where:** app/Services/Stripe/StripeConnectService.php:177-198
    - **Affects:** Brand payment method setup — the method creates a live `SetupIntent` object at Stripe on every call; as long as it coexists with `createPaymentMethodSetupCheckoutSession`, any caller using it bypasses hosted Checkout and requires client-side `client_secret` handling
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Search controllers, tests, and the frontend repo for calls to `createSetupIntent`
        - If callers exist, migrate them to `createPaymentMethodSetupCheckoutSession`; the hosted Checkout path also surfaces SCA challenges automatically, which the legacy path requires the frontend to handle manually
        - If no callers remain, delete `createSetupIntent` and its docblock
    - **Technical:** The docblock says "Legacy SetupIntent path (kept for compatibility)." The modern replacement `createPaymentMethodSetupCheckoutSession` uses Stripe Checkout `mode: 'setup'`, which stores the payment method automatically on success and is resilient to SCA. The legacy path still makes a live `stripe->setupIntents->create()` API call, consuming Stripe's idempotency key budget (`pi_` prefix), and returns a `client_secret` that a frontend must consume with `stripe.js`. Recent commits (`2a752fa`, `05031c0`, `a3f935e`) are Cloudflare/URL/auth-cache changes that do not touch this code, so no recent work validates keeping it.
    - **Plain English:** The store installed a new self-service checkout kiosk (the modern Checkout flow) but left the old staffed register running. New customers should use the kiosk, but if some are still walking to the register, they get a different (more fragile) experience. Every Stripe API change to SetupIntents has to be tested against both paths. The fix is to find out if anyone still uses the old register — if not, unplug it; if they do, move them to the kiosk.
    - **Evidence:**
        ```php
        /**
         * Legacy SetupIntent path (kept for compatibility).
         */
        public function createSetupIntent(Professional $brand): array
        {
            $customerId = $brand->stripe_customer_id;

            if (! $customerId) {
                $customerId = $this->createCustomer($brand);
            }

            $setupIntent = $this->stripe->setupIntents->create([
                'customer' => $customerId,
                'payment_method_types' => ['card', 'au_becs_debit'],
                'metadata' => [
                    'sidest_professional_id' => $brand->id,
                ],
            ]);

            return [
                'client_secret' => $setupIntent->client_secret,
                'setup_intent_id' => $setupIntent->id,
            ];
        }
        ```

- [x] **#DISK-1** · P3 — `diskName()` duplicated between `ImageVariantService` and `VideoVariantService`, with a silent observability divergence
    - **Where:** app/Services/Media/ImageVariantService.php (`diskName` method) and app/Services/Media/VideoVariantService.php (`diskName` method)
    - **Affects:** All R2/media-disk operations — if disk-resolution logic is updated in one service but not the other, image and video artifacts silently route to different storage buckets; `VideoVariantService` is missing the `Log::warning` that `ImageVariantService` emits when it falls through to `filesystems.default`
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extract `diskName()` into a shared `MediaDiskResolver` (trait or static helper) in `app/Services/Media/`
        - Replace both `private function diskName()` and `public function resolvedDiskName()` in both services with delegation to the shared resolver
        - Carry the `Log::warning` call from `ImageVariantService` into the shared implementation so video fallbacks are observable in Nightwatch — `VideoVariantService` currently falls back to `filesystems.default` silently
    - **Technical:** Both implementations are structurally identical: read `config('partna.media_disk')`, probe `$_ENV`/`$_SERVER` superglobals to bypass Laravel Cloud's config-cache boot-timing issue, then optionally fall back to `config('filesystems.default')` when the configured disk is still the literal string `'media'` and the default is an S3-backed disk. The only behavioral difference is that `ImageVariantService` emits `Log::warning('PARTNA_MEDIA_DISK not set ...')` in the fallback branch; `VideoVariantService` returns silently. Because every `Storage::disk()` call in both services routes through `diskName()`, a divergent update would deposit images and videos on different buckets — a failure mode with no immediate error, only missing assets discovered later.
    - **Plain English:** Two warehouses use the same delivery-routing logic written twice in two different instruction manuals. The image warehouse's manual says "if you can't find the usual loading dock, go to the backup dock and announce it on the radio." The video warehouse's manual says the same thing — except it skips the radio announcement. If both manuals need updating tomorrow (say, the backup dock moves), both need changes. And if the video warehouse starts using the wrong dock, nobody hears about it until shipments go missing. One shared manual, with the radio call, fixes both problems.
    - **Evidence:**
        ```php
        // ImageVariantService.php — includes Log::warning in the S3 fallback branch
        private function diskName(): string
        {
            $configured = (string) config('partna.media_disk', 'media');
            $explicit = $_ENV['PARTNA_MEDIA_DISK'] ?? $_SERVER['PARTNA_MEDIA_DISK']
                ?? $_ENV['SIDEST_MEDIA_DISK'] ?? $_SERVER['SIDEST_MEDIA_DISK'] ?? null;
            if (is_string($explicit) && trim($explicit) !== '') {
                return $configured;
            }
            if ($configured === 'media') {
                ...
                if (...(($defaultConfig['driver'] ?? null) === 's3')) {
                    Log::warning('PARTNA_MEDIA_DISK not set (legacy fallback: SIDEST_MEDIA_DISK); using filesystems.default disk for media operations.', [...]);
                    return $default;
                }
            }
            return $configured;
        }

        // VideoVariantService.php — identical resolution logic, Log::warning absent
        private function diskName(): string
        {
            $configured = (string) config('partna.media_disk', 'media');
            $explicit = $_ENV['PARTNA_MEDIA_DISK'] ?? $_SERVER['PARTNA_MEDIA_DISK']
                ?? $_ENV['SIDEST_MEDIA_DISK'] ?? $_SERVER['SIDEST_MEDIA_DISK'] ?? null;
            if (is_string($explicit) && trim($explicit) !== '') {
                return $configured;
            }
            if ($configured === 'media') {
                ...
                if (...(($defaultConfig['driver'] ?? null) === 's3')) {
                    return $default;  // ← silent fallback, no log
                }
            }
            return $configured;
        }
        ```

- [x] **#DUP-1** · P3 — `formatMoney` triplicated across financial and notification services, with a behavioral divergence in the third copy
    - **Where:** app/Services/Stripe/CommissionPayoutService.php (~464–474), app/Services/Stripe/CommissionVoidService.php (~520–530), app/Services/Notifications/CommerceNotificationService.php (~176–186, named `formatMoneyFromCents`)
    - **Affects:** All currency-formatted strings in payout notifications, void warnings, and booking notifications — any new currency (e.g. NZD) must be added in three places; the third copy has a defensive AUD default and currency normalization the other two lack
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract into a shared static helper, e.g. `app/Support/Money.php::format(int $cents, string $currencyCode): string`
        - The `CommerceNotificationService` version adds `strtoupper(trim(...))` and a fallback to `'AUD'` on an empty currency code — adopt these defenses in the shared implementation so all callers get safe behavior
        - Replace all three private implementations with calls to the shared helper
    - **Technical:** `CommissionPayoutService::formatMoney` and `CommissionVoidService::formatMoney` are byte-for-byte identical: a `match` on `strtoupper($currencyCode)` maps USD/GBP/EUR/AUD to symbol prefixes and falls through to `"{$code} "` for unknowns, then `number_format($cents / 100, 2, '.', ',')`. `CommerceNotificationService::formatMoneyFromCents` follows the same structure but calls `strtoupper(trim($currencyCode))` first and substitutes `'AUD'` if the result is empty. This means commission payout and void notification strings are crash-safe on an empty currency code (the match falls through to `" "` prefix), but payout-processing log messages in `CommissionPayoutService` and `CommissionVoidService` are not. The divergence is invisible until an order lands with a blank `currency_code`.
    - **Plain English:** Three departments in the same company each built their own calculator to convert cents into dollar amounts with currency symbols. Two calculators are identical twins. The third is slightly smarter — it handles edge cases the others don't, like a blank currency field. When the company starts accepting New Zealand dollars, someone has to update all three calculators. If they miss one, customer-facing receipts from that department show a different format. One calculator in the break room, with all the smarts from the third one baked in, fixes this.
    - **Evidence:**
        ```php
        // CommissionPayoutService.php — byte-for-byte matches CommissionVoidService.php
        private function formatMoney(int $cents, string $currencyCode): string
        {
            $prefix = match (strtoupper($currencyCode)) {
                'USD' => '$',
                'GBP' => '£',
                'EUR' => '€',
                'AUD' => 'A$',
                default => strtoupper($currencyCode).' ',
            };

            return $prefix.number_format($cents / 100, 2, '.', ',');
        }

        // CommerceNotificationService.php — same match block, adds normalization + AUD default
        private function formatMoneyFromCents(int $cents, string $currencyCode): string
        {
            $currencyCode = strtoupper(trim($currencyCode));
            if ($currencyCode === '') {
                $currencyCode = 'AUD';
            }

            $prefix = match ($currencyCode) {
                'USD' => '$',
                'GBP' => '£',
                'EUR' => '€',
                'AUD' => 'A$',
                default => $currencyCode.' ',
            };

            return $prefix.number_format($cents / 100, 2, '.', ',');
        }
        ```

## Suggested Bundled Sessions

### Bundle A — Caller Audit + Dead Code Removal
**NOOP-2, LEGACY-1, DEAD-1** — all three require a grep for callers before any deletion. Safe to tackle in a single session: grep the full codebase, list callers for each method, remove call sites, then remove the methods. Total wall time ≈ 1–2h.

### Bundle B — DRY Extraction
**DUP-1, DISK-1** — both extract a private helper into a shared utility class. `DUP-1` (Money formatter) takes ~30 min; `DISK-1` (MediaDiskResolver) takes ~1–2h. Running them together amortizes the "create new file + write tests" overhead. New `app/Support/Money.php` and `app/Services/Media/MediaDiskResolver.php` are the expected outputs.

### Standalone — do NOT bundle
**NOOP-1** (hydrateTypographySettings) — requires a product decision before acting: implement brand typography hydration or officially retire the hook. Remove without implementing only after confirming the feature is not on the near-term roadmap.
