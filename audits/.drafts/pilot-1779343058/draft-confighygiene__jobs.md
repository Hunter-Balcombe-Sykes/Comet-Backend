- [ ] **#CFG-1** · P2 — Inconsistent Shopify API version fallback defaults across jobs
    - **Where:** Multiple files — `ReconcileStuckShopifyIntegrationsJob.php:176` vs 9 other Shopify jobs
    - **Affects:** Shopify API integration for all brands when `services.shopify.api_version` is missing from config. Different jobs would use different API versions, producing mismatched GraphQL schema expectations.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Audit all `config('services.shopify.api_version', …)` calls and align the fallback to a single value — the canonical default is `'2026-04'` (the version the reconciler already targets).
        - Add `SHOPIFY_API_VERSION=2026-04` to `.env.example` so the fallback never activates in configured environments.
    - **Technical:** Category 4. `ReconcileStuckShopifyIntegrationsJob` defaults to `'2026-04'` while `CreateShopifyCollectionsJob`, `CreateShopifyMetafieldsJob`, `CreateShopifySalesChannelJob`, `CreateStorefrontAccessTokenJob`, `CreateShopifyAffiliateDiscountJob`, `RegisterShopifyWebhooksJob`, `SetShopifySetupCompleteJob`, `SyncShopifyBrandDesignJob`, and `ProcessShopifyShopUpdateJob` all default to `'2025-01'`. If the config key is absent, the reconciler queries a different API surface than the install-chain jobs — meaning a brand re-installing during a config outage would have metafield definitions created on `2025-01` while the reconciler validates tokens against `2026-04` schema, potentially flagging healthy integrations as broken due to response shape differences.
    - **Plain English:** Think of it like a restaurant where the lunch menu and the dinner menu have the same item names but different prices. Nine chefs are cooking from the lunch menu, but the health inspector is checking against the dinner menu. Most of the time the manager provides the right menu (the config key), but if that page goes missing, chaos ensues — some dishes look wrong to the inspector even though they're perfectly fine.
    - **Evidence:**
        ```php
        // ReconcileStuckShopifyIntegrationsJob.php:176
        $apiVersion = (string) config('services.shopify.api_version', '2026-04');
        ```
        ```php
        // CreateShopifyCollectionsJob.php (and 8 other jobs)
        $apiVersion = trim((string) config('services.shopify.api_version', '2025-01'));
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CFG-2** · P3 — Shopify Hydrogen CNAME target `shops.myshopify.com` hardcoded without config indirection
    - **Where:** `app/Jobs/Cloudflare/ProvisionBrandDnsJob.php:52`
    - **Affects:** Brand DNS provisioning during OAuth install. If Shopify ever changes the Oxygen/Hydrogen hosting domain, every brand install breaks until a code deploy.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the hardcoded `'shops.myshopify.com'` string with `config('services.shopify.hydrogen_cname', 'shops.myshopify.com')`.
        - Add `SHOPIFY_HYDROGEN_CNAME=shops.myshopify.com` to `.env.example` so ops can override it without a code change.
    - **Technical:** Category 4. `upsertCname($subdomain, 'shops.myshopify.com', false)` bakes a vendor-specific DNS target into the job body. Unlike API version strings (which have scattered fallback defaults elsewhere), this value has no config path at all — it cannot be changed without editing and deploying the job file. Every other Shopify vendor constant (API version, domain validation patterns) already routes through `config('services.shopify.*')`; this is the lone hardcoded exception in the DNS provisioning path.
    - **Plain English:** This is like having a shipping address printed directly onto the packaging machine instead of on a configurable label. If the warehouse moves, you have to rebuild the machine rather than just printing a new label. Shopify's hosting domain is stable, but if it ever changes — or if a brand is on a Shopify Plus plan with a custom domain contract — the only fix is a code deploy.
    - **Evidence:**
        ```php
        // ProvisionBrandDnsJob.php:52
        $dns->upsertCname($subdomain, 'shops.myshopify.com', false);
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#CFG-3** · P3 — Queue connection `redis_gdpr` hardcoded while queue name uses config
    - **Where:** `app/Jobs/Shopify/Gdpr/RedactShopJob.php:39`
    - **Affects:** GDPR shop-redact job routing. Inconsistent config pattern — connection is hardcoded, queue name is config-driven. A staging environment using a different Redis instance for GDPR jobs cannot redirect without a code change.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `$this->onConnection('redis_gdpr')` with `$this->onConnection(config('partna.gdpr.connection', 'redis_gdpr'))`.
        - Add `GDPR_QUEUE_CONNECTION=redis_gdpr` to `.env.example` if not already present, mapping to a `partna.gdpr.connection` config key.
    - **Technical:** Category 4. The constructor reads `config('partna.gdpr.queue', 'gdpr')` for the queue name but hardcodes `'redis_gdpr'` for the connection. Every other GDPR job (`ExportProfessionalDataJob`, `ExportCustomerDataJob`, `RedactCustomerJob`) uses the same `config('partna.gdpr.queue')` pattern for the name but leaves the connection to the job's default — making `RedactShopJob` the outlier in both directions. The author likely copied the pattern from `DeleteMediaArtifactsJob` (which does use config for both connection and queue), but only applied it to the queue half.
    - **Plain English:** Imagine a shipping department where the destination address is on a configurable label but the shipping carrier is painted on the wall. If you need to switch carriers for a test run, you can change the label but the truck still shows up at the old dock. This job is like that — the queue name moves with the config, but the Redis connection is bolted to the wall.
    - **Evidence:**
        ```php
        // RedactShopJob.php:39
        $this->onConnection('redis_gdpr')->onQueue(config('partna.gdpr.queue', 'gdpr'));
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#CFG-4** · P3 — Staff broadcast batch dispatch hardcodes `'mail'` queue name
    - **Where:** `app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php:86`
    - **Affects:** Staff broadcast email delivery routing. A typo in the hardcoded string (`'mail'` vs `'email'`) would silently route sends to a queue with no workers.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `->onQueue('mail')` with `->onQueue(config('partna.notifications.mail_queue', 'mail'))`.
        - Mirror the pattern already used by `FanOutBrandStatusNotificationJob` which sources its batch chunk size from `config('partna.notifications.batch_chunk_size')`.
    - **Technical:** Category 4. `SendStaffBroadcastEmailsJob` dispatches leaf batches to a hardcoded `'mail'` queue. The parent job itself uses `$this->onQueue('notifications')` (also hardcoded, but conventional for the notifications domain). The `'mail'` string appears nowhere else in config — if a deployment configures a different queue name for email workers (`'emails'`, `'transactional'`), this batch silently dispatches to an unconsumed queue and no subscriber receives the broadcast. The sibling `FanOutBrandStatusNotificationJob` already demonstrates the config-driven pattern for batch dispatch parameters.
    - **Plain English:** This is like a mailroom clerk who always writes "Mail Room" on the inter-office envelope regardless of what the company directory says. If the mailroom gets renamed to "Postal Services," the clerk's envelopes pile up in a dead drop while everyone wonders why the newsletter never arrived.
    - **Evidence:**
        ```php
        // SendStaffBroadcastEmailsJob.php:86
        $batch = Bus::batch($chunk)
            ->onQueue('mail')
            ->name('staff-broadcast:'.$notification->id)
            ->allowFailures()
            ->dispatch();
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CFG-5** · P3 — Image variant job hardcodes `'images'` queue while video counterpart uses config
    - **Where:** `app/Jobs/ProcessImageVariantsJob.php:40`
    - **Affects:** Image processing queue routing. Operator cannot redirect image variant work to a different queue without a code change, unlike video processing.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `$this->onQueue('images')` with `$this->onQueue(config('partna.image_queue.name', 'images'))`.
        - Mirror the connection+queue config pattern from `ProcessVideoVariantsJob` (`config('partna.video_queue.connection')` / `config('partna.video_queue.name')`).
    - **Technical:** Category 4. `ProcessImageVariantsJob` hardcodes `$this->onQueue('images')` while its sibling `ProcessVideoVariantsJob` reads both connection and queue from `config('partna.video_queue.*')`. Image and video processing have the same architectural needs — dedicated workers, separate scaling, independent timeout configuration — but the image job entirely lacks a config-driven escape hatch. A staging environment that routes variant generation to a lower-priority queue for cost savings can do so for video but not for images.
    - **Plain English:** The video processing machine has a dial to choose which conveyor belt it feeds into. The image processing machine has the belt name stamped into the metal. They do the same kind of work (turn uploads into web-ready files), but only one of them can be reconfigured without a factory shutdown.
    - **Evidence:**
        ```php
        // ProcessImageVariantsJob.php:40 — hardcoded
        $this->onQueue('images');
        ```
        ```php
        // ProcessVideoVariantsJob.php:54-55 — config-driven
        $this->onConnection((string) config('partna.video_queue.connection', 'redis_video'));
        $this->onQueue((string) config('partna.video_queue.name', 'videos'));
        ```
    - `[DRAFT, confidence: 0.9]`
