- [ ] **LIFE-1** · P0 — `ShopifyIntegrationController::connect()` creates integration row in `queued` state with no automated reconcile when all job dispatches fail
    - **Where:** app/Http/Controllers/Api/Professional/Brand/ShopifyIntegrationController.php (connect method)
    - **Affects:** Brands connecting Shopify — the integration row is created with `webhook_registration_state = 'queued'`, but if all five jobs fail to dispatch (e.g. Redis queue down), the brand sees `connected: true` with no path to recovery except manual `retrySetup`. At 200 brands, one queue outage during a wave of connects leaves dozens of brands silently broken.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a daily reconcile job (`ReconcileStuckShopifyInstallsJob`) that finds integrations stuck in `queued` or `failed` for > 2h and re-dispatches the missing jobs.
        - Guard the `connect` response: if `webhook_registration_queued` is false after all five dispatches, return a 202 Accepted with a clear `retry_setup_url` rather than 200 with `connected: true`.
    - **Technical:** Pattern match to `daily reconcile job` (`0de1f2f`). Any state that depends on a vendor webhook or async job must have a reconcile job that catches missed deliveries. Here the state machine is `queued → registered/partial/failed` but the only recovery path is manual — a human must notice and click "retry setup." Supabase RLS prevents cross-brand reads, so a single reconcile job iterating `webhook_registration_state IN ('queued','failed') AND updated_at < now() - interval '2 hours'` is safe and cheap.
    - **Plain English:** Imagine a restaurant where the host seats you, marks your table as "waiting for waiter," but the pager system is down so no waiter ever comes. You sit there thinking everything's fine while your order never gets taken. The fix is a manager who walks the floor every hour checking for tables that have been waiting too long. Right now there's no manager — a brand whose Shopify connection jobs failed to queue will sit in limbo until they manually click "retry."
    - **Evidence:**
        ```php
        $webhookRegistrationQueued = true;
        $jobs = [
            RegisterShopifyWebhooksJob::class,
            CreateStorefrontAccessTokenJob::class,
            CreateShopifyMetafieldsJob::class,
            CreateShopifySalesChannelJob::class,
            SyncShopifyBrandDesignJob::class,
        ];

        foreach ($jobs as $jobClass) {
            try {
                $jobClass::dispatch((string) $integration->id);
            } catch (\Throwable $e) {
                $webhookRegistrationQueued = false;
                Log::warning('Failed to dispatch Shopify install job', [...]);
            }
        }

        return $this->success([
            'connected' => true,
            ...
            'webhook_registration_queued' => $webhookRegistrationQueued,
        ]);
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **LIFE-2** · P1 — `BrandAffiliateInviteController::claim()` performs three sequential state mutations outside a transaction with no compensating action on partial failure
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandAffiliateInviteController.php (claim method)
    - **Affects:** Affiliates claiming invites — if `claimInvite` succeeds but `transition` throws `InvalidAccountTypeTransition`, the invite is consumed (status=accepted) but the account type stays unchanged. The affiliate gets a 422, can't retry the token (it's claimed), and is stuck in a broken state.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap claim + transition + site sync in a `DB::transaction()` so partial failure rolls back.
        - If the invite service's `claimInvite` uses a separate connection or external call, add a compensating `releaseInvite` call in the `catch (InvalidAccountTypeTransition)` block so the token is freed for retry.
        - Log distinctly when the transition fails after a successful claim — currently the 422 surface hides the "claimed but broken" state from Nightwatch.
    - **Technical:** Pattern match to `#STRIPE-2` distinct-log and `dcdb3b4` in-flight cancellation. The `claim` method is a mini-aggregate: claim the invite, transition account type, sync site settings, invalidate caches. If step 2 fails, rows from step 1 persist outside a transaction. Two concurrent claim attempts on the same token could also both pass `findByToken` before either's `claimInvite` mutates the row — the invite service's own locking should be verified but isn't visible in this file.
    - **Plain English:** Like a hotel check-in where the front desk marks your room as occupied, but then the key-card machine breaks. You're told "sorry, come back later" but your room is already marked taken — you can't check in again and the room sits empty. The fix is to either do all three steps as one atomic operation, or if the key-card machine breaks, un-mark the room so you can try again.
    - **Evidence:**
        ```php
        try {
            $claimedInvite = $inviteService->claimInvite($invite, $professional);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        // §28.12: flip account_type to partner ...
        try {
            $transitionService->transition(
                $professional->fresh() ?? $professional,
                AccountType::Partner
            );
        } catch (InvalidAccountTypeTransition $e) {
            return $this->error($e->getMessage(), 422);
        }

        $site = Site::query()->where('professional_id', $professional->id)->first();
        if ($site) {
            $this->syncSiteBrandPartnerSettings($site, $brandPartnerLinks, (string) $professional->id);
            app(ProfessionalCacheService::class)->invalidateProfessional($professional);
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **LIFE-3** · P1 — `BrandStoreSettingsController::update()` commits local DB state before Shopify metafield sync — creates drift on vendor failure
    - **Where:** app/Http/Controllers/Api/Professional/Store/BrandStoreSettingsController.php (update method)
    - **Affects:** Brands updating store settings (commission rate, accent color, theme variant, product image ratio, custom photos). Local state persists even when Shopify metafield writes fail — Hydrogen reads from Shopify metafields, so the brand sees stale values on their storefront while the dashboard shows the new values.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Reorder: `updateOrCreate` + site settings write should happen AFTER the Shopify metafield sync succeeds, or within the same logical unit with a compensating rollback.
        - Alternatively, queue the Shopify sync and return 202 Accepted — the dashboard can poll `/shopify/status` for completion.
        - Add a `last_settings_sync_error` column or metadata field to surface drift to support and to self-healing reconcile.
    - **Technical:** Pattern match to `dcdb3b4` in-flight aggregate handling. The method performs: (1) `updateOrCreate` on `brand_store_settings`, (2) Oxygen deployment dispatch (async), (3) `$site->save()` for design settings, (4) Shopify metafield `setShopMetafields` call. Steps 1–3 are committed to Postgres before step 4 runs. If step 4 returns `userErrors`, the method returns 422 — but steps 1–3 are already durable. At 200 brands, a Shopify API partial outage during a configuration push wave creates silent drift between `brand_store_settings` and the Shopify metafields Hydrogen actually reads.
    - **Plain English:** Imagine updating your profile on a job board — you change your name and hit save. The site says "saved!" and shows your new name on your dashboard, but the employer-facing site still shows your old name because the sync to the public database failed silently. You think it worked, but nobody else sees the change. The fix is to either not claim "saved" until the sync completes, or to queue the sync and show a "updating…" status until it confirms.
    - **Evidence:**
        ```php
        // 1. Local DB write
        $settings = BrandStoreSettings::updateOrCreate(
            ['professional_id' => $pro->id],
            $dbFields
        );

        // 2. Oxygen deployment (async)
        if ($hasOxygenToken) {
            $settings->oxygen_deployment_token = ...;
            $settings->save();
            $this->deployment->dispatchDeployment($pro->id);
        }

        // 3. Write visual settings to site.settings.design
        $site->settings = $settings;
        $site->save();

        // 4. Shopify metafield sync — only when a Shopify-backed field is being updated.
        if ($needsShopifySync) {
            // ... metafield writes ...
            if (! $result['success']) {
                return $this->error($msg, 422); // local state already committed!
            }
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **LIFE-4** · P1 — `dispatchImageJob` swallows `Throwable` — creates stuck `processing_state = pending` rows with no recovery path
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandGalleryController.php (dispatchImageJob method) and app/Http/Controllers/Api/Professional/Store/AffiliateProductPhotoController.php (dispatchImageJob method)
    - **Affects:** Brand gallery and affiliate product photo uploads. If the queue connection fails during dispatch, the `SiteMedia` row is created with `processing_state = pending`, the original file is stored on disk, but no processing job runs. The image stays in "pending" forever — no reconcile job, no stale-state alert, no retry.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Log distinctly: the catch block should emit at `Log::error` with `processing_dispatched: false` so Nightwatch can distinguish "job dispatched" from "dispatch failed."
        - Propagate the failure to the caller: `upload()` should check whether the dispatch succeeded and, if not, either delete the `SiteMedia` row or return a 202 with a clear retry path.
        - Add a daily reconcile job (`ProcessStuckPendingImagesJob`) that finds `SiteMedia` rows with `processing_state = pending` and `created_at < now() - interval '15 minutes'` and re-dispatches or fails them.
    - **Technical:** Pattern match to `#STRIPE-2` distinct-log and `0de1f2f` daily reconcile. The function has two outcomes — dispatched vs. failed — but neither the log nor the return value distinguishes them. The caller (`upload()`) creates the row, stores the file, updates the path, calls `dispatchImageJob`, and returns success — regardless of whether the dispatch worked. At 200 brands uploading images, a queue blip creates orphaned rows that never transition to `ready`.
    - **Plain English:** Like putting a letter in a mailbox that has a broken pickup mechanism. You drop it in, the box confirms it's inside, but the mail truck never comes to collect it. The letter sits there forever. The fix is to either check that the truck actually arrived, or have someone check the box every hour for stuck letters.
    - **Evidence:**
        ```php
        private function dispatchImageJob(string $imageId, string $originalPath, string $basePath): void
        {
            $processInline = in_array(app()->environment(), ['local', 'testing'], true)
                || config('queue.default', 'sync') === 'sync';

            try {
                if ($processInline) {
                    ProcessImageVariantsJob::dispatchSync(...);
                } else {
                    ProcessImageVariantsJob::dispatch(...);
                }
            } catch (Throwable $e) {
                Log::error('Brand gallery: image processing dispatch failed', [
                    'image_id' => $imageId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **LIFE-5** · P1 — `ShareCheckoutLinkController::store()` calls Shopify `cartCreate` without an idempotency key — retries create duplicate carts
    - **Where:** app/Http/Controllers/Api/Professional/Store/ShareCheckoutLinkController.php (store method)
    - **Affects:** All shared checkout link requests (~40K daily notifications at scale). Client retries or network replays create duplicate Shopify carts, each with a different `checkoutUrl` — the affiliate's follower gets a dead or wrong link, and the brand's Shopify admin accumulates orphaned carts.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Derive an idempotency key from a deterministic hash of `affiliate_id + product_gids + quantities + timestamp rounded to 30s` and pass it as `idempotencyKey` in the `cartCreate` input per Shopify's Storefront API spec.
        - If Shopify's Storefront API doesn't support idempotency keys on `cartCreate`, add a local dedup: cache `hash(request) → checkoutUrl` for 60s so identical requests within the window return the same cart.
    - **Technical:** Category (1) idempotency on the write path. The `cartCreate` mutation is submitted without any deduplication mechanism. The Shopify Storefront API's `cartCreate` does accept an `idempotencyKey` field in the `CartInput`. At the scale target of ~40K daily notifications driving checkout link traffic, even a 0.1% retry rate produces 40 duplicate carts/day. The canonical `lockForUpdate + UNIQUE` pattern doesn't apply directly (this is an external API), but the `idempotency-key derivation` principle does — the request must be deterministically replayable.
    - **Plain English:** Like sending a text message that says "order me a pizza" — if your phone glitches and sends it twice, the pizzeria makes two pizzas even though you only wanted one. The fix is to add a unique order number to the message so the pizzeria knows "ah, I already made this one" if they see the same number twice.
    - **Evidence:**
        ```php
        $response = Http::timeout(20)
            ->acceptJson()
            ->withHeaders([
                'X-Shopify-Storefront-Access-Token' => $storefrontToken,
            ])
            ->post($url, [
                'query' => self::CART_CREATE_MUTATION,
                'variables' => [
                    'input' => ['lines' => $lines],
                ],
            ]);
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **LIFE-6** · P1 — `BrandGalleryController::upload()` and `AffiliateProductPhotoController::upload()` create `SiteMedia` rows without an idempotency key — client retries create duplicate rows
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandGalleryController.php (upload method) and app/Http/Controllers/Api/Professional/Store/AffiliateProductPhotoController.php (upload method)
    - **Affects:** Image uploads — a network retry after a timeout on the file upload creates two `SiteMedia` rows for the same file. The advisory lock serializes concurrent uploads from the same site, but a retry after the first request completes successfully produces a duplicate row.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a `UNIQUE` constraint on `(site_id, pool, COALESCE(product_gid, ''), original_size_bytes, original_mime)` or hash the file content and store a `content_sha256` column with a `UNIQUE(site_id, pool, content_sha256)` constraint.
        - At minimum, add a `UNIQUE(site_id, pool, path)` constraint so two rows can't claim the same storage path — the second `create` would fail with a typed `UniqueConstraintViolationException` catch.
    - **Technical:** Category (1) idempotency. The advisory lock (`pg_advisory_xact_lock`) prevents concurrent uploads within the same transaction, but a client retry after the first request's transaction commits will acquire the lock again, check the count again, and create a second row. The canonical `lockForUpdate + UNIQUE` pattern requires a `UNIQUE` constraint backing the idempotency key — here there is none. At 200 brands uploading gallery images, network hiccups during upload create orphaned duplicate rows.
    - **Plain English:** Like a coat check that uses a turnstile to make sure only one person enters at a time, but has no ticket system. If you go through, come back out, and go through again, you get two hangers for the same coat. The fix is to add ticket numbers that the coat check can use to say "I already have this one."
    - **Evidence:**
        ```php
        $media = DB::transaction(function () use ($site, $maxItems, $request, $file) {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(hashtext(?))', ["site-images:{$site->id}"]);
            }
            // ...
            return SiteMedia::create([
                'site_id' => $site->id,
                'pool' => self::POOL,
                'path' => '',
                // ... no idempotency key field
            ]);
        });
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **LIFE-7** · P1 — `ProfessionalAnalyticsController::summary()` swallows all `QueryException` instances, not just "table doesn't exist"
    - **Where:** app/Http/Controllers/Api/Professional/Analytics/ProfessionalAnalyticsController.php (summary method)
    - **Affects:** Dashboard analytics. Multiple query blocks catch `QueryException` with no SQLSTATE check and return empty collections. A syntax error, constraint violation, or schema mismatch in any analytics query would be silently converted to empty data, masking real bugs from Nightwatch until user reports surface them.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Narrow each catch to `catch (QueryException $e)` with a guard: `if (($e->errorInfo[0] ?? null) === '42P01') { return collect(); } throw $e;` — same pattern already used in `AffiliateCommerceAnalyticsController::buildPayoutSummary` for 42703.
        - For the link_clicks and cart_events tables specifically, pre-flight with `Schema::hasTable()` once and skip the query entirely rather than catching on every request.
    - **Technical:** Category (10) observability. The `AffiliateCommerceAnalyticsController` correctly narrows its `QueryException` catch to SQLSTATE 42703 (undefined column). Here, five separate `try { DB::table('analytics.link_clicks')... } catch (QueryException) { return collect(); }` blocks in `summary()` and `shopSummary()` catch ALL QueryExceptions. A mistyped column or broken migration that reaches production would produce zero Nightwatch signal — every dashboard would silently show empty charts instead of surfacing errors.
    - **Plain English:** Like a fire alarm that treats every type of sensor reading — smoke, heat, a dead battery, a spider inside — as "everything's fine, don't alert anyone." A real fire (a broken query) gets the same silent treatment as a known non-issue (a table that hasn't been created yet). The fix is to check WHAT went wrong before deciding to ignore it.
    - **Evidence:**
        ```php
        try {
            $clicksAgg = DB::table('analytics.link_clicks')
                ->where('professional_id', $professional->id)
                ->whereBetween('occurred_at', [$from, $to])
                ->selectRaw('COUNT(*) as total_clicks')
                // ...
                ->first();
        } catch (QueryException) {
            $clicksAgg = (object) ['total_clicks' => 0, ...];
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **LIFE-8** · P2 — `AffiliateProductController::store()` catches `QueryException` + checks `$e->getCode() === '23505'` instead of using typed `UniqueConstraintViolationException`
    - **Where:** app/Http/Controllers/Api/Professional/Store/AffiliateProductController.php (store method)
    - **Affects:** Affiliate product selection creation. The numeric-code check is fragile across Postgres versions and constraint renames.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `catch (QueryException $e) { if ($e->getCode() === '23505')` with `catch (UniqueConstraintViolationException $e)` per canonical `#STRIPE-3` / `35c6f31`.
    - **Technical:** Category (1) idempotency catch hygiene. Laravel 10+ provides `UniqueConstraintViolationException` as a typed subclass of `QueryException`. Catching by numeric SQLSTATE (`23505`) works but is less readable, relies on the developer knowing the code, and can't be statically analyzed. The canonical pattern is the typed catch — it's version-stable across Postgres releases and constraint renames.
    - **Plain English:** Like identifying someone by their exact height in millimeters instead of using their name. It works if you measure perfectly, but if the measuring tape changes (a new Postgres version uses a different internal code), you'll miss them. Using the typed exception is like calling them by name — stable and clear.
    - **Evidence:**
        ```php
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                return $this->error('This product is already selected.', 409);
            }
            throw $e;
        }
        ```
    - `[DRAFT, confidence: 1.0]`

- [ ] **LIFE-9** · P2 — `ShopifyIntegrationController::resolveShop()` makes synchronous outbound HTTP call with 6s timeout inside a controller — vendor latency propagates to user-facing p99
    - **Where:** app/Http/Controllers/Api/Professional/Brand/ShopifyIntegrationController.php (resolveShop / discoverShopifyHandle methods)
    - **Affects:** Brand onboarding — the "resolve shop domain" step makes a live HTTP request to the prospective Shopify storefront. A slow-responding storefront adds up to 6s to the API response.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Reduce timeout to 3s (the HTML scrape is a best-effort UX convenience, not a correctness requirement).
        - Or: queue the discovery as a job, return a `resolve_job_id`, and have the frontend poll. This also lets the job run with a longer timeout without blocking the user.
    - **Technical:** Category (6) vendor-integration hygiene. The `discoverShopifyHandle` method fetches the storefront homepage with `Http::timeout(6)->connectTimeout(4)`. This is a synchronous call in the request lifecycle — any latency from Shopify's infrastructure or the merchant's storefront directly inflates the p99 of this endpoint. At 200 brands all resolving their domains during onboarding, a Shopify partial outage cascades into 6s timeouts across all concurrent brand setups. The SSRF guards (DNS pinning, redirect disabling) are correct — the concern is purely latency.
    - **Plain English:** Like a receptionist who, when you ask "what's the address for Acme Corp?", drives to Acme Corp's office to read the sign on their door before answering you. You wait in the lobby for up to 6 seconds while they make the round trip. The fix is to either make that trip faster (shorter timeout) or have a separate runner do it and text you the answer when they're back.
    - **Evidence:**
        ```php
        $response = Http::timeout(6)
            ->connectTimeout(4)
            ->withOptions([...])
            ->get($url);
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **LIFE-10** · P2 — `BrandCatalogController::updateMetafields()` can make up to 3 sequential Shopify GraphQL calls in a single request — compounds vendor latency
    - **Where:** app/Http/Controllers/Api/Professional/Store/BrandCatalogController.php (updateMetafields method)
    - **Affects:** Brands managing product catalog — updating metafields on a product with commission_override deletion + new metafield set + variant cascade can make 3 sequential GraphQL calls, each adding 500ms–2s of Shopify latency.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Batch all metafield operations into a single GraphQL call using Shopify's bulk mutation syntax where possible.
        - Move the `clearVariantDisablesForProduct` cascade to an async job — it's best-effort cleanup, not correctness-critical (the brand can manually flip variants).
    - **Technical:** Category (6) vendor-integration hygiene. The method calls `deleteProductMetafield` (for commission_override null), then `setProductMetafields` (for the remaining metafields), then `clearVariantDisablesForProduct` (on activation cascade). Each is a separate HTTP round-trip to Shopify's GraphQL API. At 200 brands actively managing their catalogs, sequential calls compound: if each takes 1s, the endpoint takes 3s to respond. The canonical `vendor API version pinning` pattern doesn't directly apply here, but the principle of minimizing synchronous vendor calls does.
    - **Plain English:** Like placing three separate orders at a restaurant — "I'll have the soup… [waiter leaves, comes back] …now the salad… [waiter leaves, comes back] …now the dessert." Instead of the waiter taking all three at once, the kitchen gets them one at a time and you wait for each. The fix is to hand the waiter the full order in one trip.
    - **Evidence:**
        ```php
        if ($validated['commission_override'] === null) {
            $this->catalogService->deleteProductMetafield($integration, $productGid, 'commission_override');
        } else {
            $metafieldsToSet[] = [...];
        }
        // ... later:
        if (! empty($metafieldsToSet)) {
            $result = $this->catalogService->setProductMetafields($integration, $productGid, $metafieldsToSet);
        }
        // ... later:
        if ($activatingProduct) {
            $this->catalogService->clearVariantDisablesForProduct($integration, $productGid);
        }
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **LIFE-11** · P2 — `ShareCheckoutLinkController::store()` catches `\Throwable` and returns generic 502 — verbatim Shopify error is lost to the caller
    - **Where:** app/Http/Controllers/Api/Professional/Store/ShareCheckoutLinkController.php (store method)
    - **Affects:** Affiliates creating checkout links. When Shopify returns an error (invalid variant, storefront token expired, rate limit), the affiliate sees "Unable to create checkout. Please try again." with no diagnostic information. At 200 brands, support cannot debug checkout-link failures without digging through logs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Store the verbatim Shopify error on a `ShareCheckoutLinkAttempt` model or in a `checkout_error_log` table with the affiliate's professional_id and timestamp.
        - Return a distinct error code per failure class (invalid_variant vs. storefront_unavailable vs. rate_limited) so the frontend can show actionable messaging.
    - **Technical:** Category (6) vendor error hygiene — pattern match to `verbatim vendor error capture` (`bf6e46d`). The catch block logs the full Shopify response to Nightwatch (good) but the user-facing response is generic. At scale, a support ticket "checkout link not working" requires a log dive. Storing the verbatim error on a record attached to the affiliate lets support self-serve.
    - **Plain English:** Like a package delivery service that only tells you "delivery failed" — not whether the address was wrong, the recipient wasn't home, or the truck broke down. You have to call customer support and hope they can look up the scanner logs. The fix is to write down the actual reason on the delivery slip so anyone can see what happened.
    - **Evidence:**
        ```php
        } catch (\Throwable $e) {
            Log::error('Share checkout-link: cart creation exception.', [
                'professional_id' => $pro->id,
                'error' => $e->getMessage(),
            ]);
            return $this->error('Unable to create checkout. Please try again.', 502);
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **LIFE-12** · P2 — Multiple controllers use inline `$request->validate([...])` instead of Form Request classes
    - **Where:** Multiple controllers — `BrandAffiliateController`, `BrandAffiliateInviteController`, `BrandGalleryController`, `AffiliateProductPhotoController`, `AffiliateProductController`, `ShareCheckoutLinkController`
    - **Affects:** Validation consistency and testability. Inline validation rules can't be reused, tested in isolation, or enforced consistently across API versions.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Extract inline validation arrays into dedicated Form Request classes per canonical `a11feb2` pattern.
        - Prioritize controllers that have validation logic beyond simple field lists (e.g., conditional rules, `Rule::in` with config keys).
    - **Technical:** Category (7) authorization & validation hygiene. The canonical `Policy + Form Request` pattern requires Form Request classes for all non-trivial validation. Many controllers in the audit scope have inline `$request->validate([...])` calls. These work correctly but break the single-responsibility pattern: controllers should orchestrate, Form Requests should validate. At 200 brands, validation changes across API versions become risky when rules are scattered across controllers.
    - **Plain English:** Like having every waiter at a restaurant memorize the list of allergies instead of having it printed on a card. When the menu changes, you have to retrain every waiter individually instead of updating one card. The fix is to put the rules in one place (Form Request classes) so they're consistent and easy to update.
    - **Evidence:**
        ```php
        // BrandAffiliateController::disconnect()
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        // BrandAffiliateInviteController::store()
        $data = $request->validate([
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            // ...
        ]);

        // AffiliateProductController::store()
        $validated = $request->validate([
            'shopify_product_gid' => ['required', 'string', 'max:100', 'regex:/^gid:\/\/shopify\/Product\/\d+$/'],
            // ...
        ]);
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **LIFE-13** · P3 — `ProfessionalLinkBlockController::authorizeCustomLinks()` uses inline `abort_unless` with config check instead of a Policy ability
    - **Where:** app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalLinkBlockController.php (authorizeCustomLinks method)
    - **Affects:** Custom link creation for non-brand accounts. The config-based gate is inline and can't be tested through Policy tests.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `createCustomLink` ability to `BlockPolicy` that checks the same config, and call `$this->authorizeForUser($pro, 'createCustomLink', $site)` instead of `abort_unless`.
    - **Technical:** Category (7) authorization hygiene — pattern match to `Policy over inline role-scoping` (`#STRIPE-1`). The method checks `config("partna.account_type_defaults.{$type}.custom_links_allowed")` inline. Moving this to a Policy ability centralizes authorization logic, makes it testable, and follows the canonical `authorizeForUser` pattern the rest of the codebase uses for resource-level gates.
    - **Plain English:** Like having one security guard at the front door who checks IDs against the guest list, and another guard at the elevator who has the guest list memorized instead of using the same printed copy. If the list changes, you have to update both guards separately. The fix is to give both guards the same printed list (the Policy class).
    - **Evidence:**
        ```php
        private function authorizeCustomLinks(Professional $pro): void
        {
            $type = $pro->account_type?->value ?? mb_strtolower(trim((string) ($pro->professional_type ?? '')));
            abort_unless(
                (bool) config("partna.account_type_defaults.{$type}.custom_links_allowed", false),
                403,
                'Custom links are not available on your account type.'
            );
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **LIFE-14** · P3 — Several error logs missing `brand_professional_id` / `request_id` context — Nightwatch correlation breaks
    - **Where:** BrandGalleryController (upload), AffiliateProductPhotoController (upload), dispatchImageJob (both controllers)
    - **Affects:** Production debugging at scale. When Nightwatch receives an error without professional context, it can't correlate the error to the affected tenant or the originating request — debugging requires manual log correlation across multiple sources.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `professional_id` (or `brand_professional_id`) to every `Log::error` / `Log::warning` call in controllers and jobs.
        - Add `request_id` from the request lifecycle (available via `request()->header('X-Request-Id')` or equivalent) when called in a controller context.
    - **Technical:** Category (10) observability — pattern match to `Log-with-context` canonical. At the scale target of 200 brands × 50 affiliates generating ~40K daily notifications, log volume makes manual correlation impossible. Nightwatch's auto-grouping requires consistent context keys. The gallery upload error logs have `media_id` and `error` but no `professional_id` — a support ticket "my gallery image is stuck processing" requires a join from media_id → site_id → professional_id before the error is findable.
    - **Plain English:** Like a delivery company's tracking system that logs "package #8472 — problem at sorting facility" without saying which city the sorting facility is in. When a customer calls, you have to cross-reference three different systems just to figure out whose package has a problem. The fix is to always include the customer's ID on every log entry so you can search by customer directly.
    - **Evidence:**
        ```php
        Log::error('Brand gallery: failed to store original', [
            'media_id' => $media->id,
            'error' => $e->getMessage(),
        ]);
        // Missing: professional_id, request_id

        Log::error('Product photo: image processing dispatch failed', [
            'image_id' => $imageId,
            'error' => $e->getMessage(),
        ]);
        // Missing: professional_id, request_id
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **LIFE-15** · P3 — `BrandAffiliateInviteController` has 6 methods with inline `if (! $professional->isBrand())` checks that duplicate what `brand.only` middleware should handle
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandAffiliateInviteController.php (index, store, availability, bulk, importCsv, destroy)
    - **Affects:** Consistency of auth gating across the API surface. Middleware + inline checks create defense-in-depth but also make the auth surface harder to audit — a future developer adding a 7th method might forget the inline check, trusting the middleware alone.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Verify `brand.only` middleware is applied on the routes for these methods, remove the inline checks, and add a test that 403s non-brand requests.
        - If the routes DON'T have `brand.only` middleware, add it rather than relying on inline checks.
    - **Technical:** Category (7) authorization hygiene. The canonical pattern is middleware-only for role gating. Other brand controllers (`BrandAffiliateController`, `BrandPayoutsController`) rely on `brand.only` middleware and don't duplicate the check inline. This controller has both — the inline check is either redundant (if middleware is present) or compensating for missing middleware. Either way, inconsistent with the rest of the brand controller surface.
    - **Plain English:** Like having both a bouncer at the door AND a security guard inside who checks your ID again at every room. It's not wrong, but it's confusing — if someone adds a new room (method) and forgets to post a second guard, they'll assume the bouncer at the door was enough. Better to trust the door and test that it works.
    - **Evidence:**
        ```php
        public function index(Request $request): JsonResponse
        {
            $professional = $this->currentProfessional($request);

            if (! $professional->isBrand()) {
                return $this->error('Only brand accounts can view affiliate invites.', 403);
            }
            // ...
        }
        ```
    - `[DRAFT, confidence: 0.8]`
