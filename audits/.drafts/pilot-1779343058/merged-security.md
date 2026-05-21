
<!-- ═══ CHUNK: infra ═══ -->

- [ ] **#SEC-1** · P0 — Cross-tenant data exposed in affiliate public payload via missing BrandPartnerLink check
    - **Where:** app/Services/Cache/SiteCacheService.php (enrichSiteWithBrandPartnerRadius + resolveBrandPartnerEnrichmentData)
    - **Affects:** Public site viewers of any affiliate page; an affiliate can enumerate and expose another professional's PII (first_name, last_name, handle) and design settings by writing that professional's UUID into their own site settings JSON.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `BrandPartnerLink` existence check in `enrichSiteWithBrandPartnerRadius` before calling `resolveBrandPartnerEnrichmentData`, matching the pattern already used in `applyBrandImageFallbacks`.
        - On failed verification, return `$site` unchanged (no enrichment) and log a warning — never fall through to fetch another professional's data.
    - **Technical:** `enrichSiteWithBrandPartnerRadius` reads `$brandPartner['professional_id']` from the affiliate's mutable `site.settings` JSONB column and passes it directly to `resolveBrandPartnerEnrichmentData`, which queries `Site` and `Professional` for that ID and exposes `handle`, `first_name`, `last_name`, and design tokens in the public site payload. The sister method `applyBrandImageFallbacks` verifies the relationship via `BrandPartnerLink::where(...)->first()` before using brand data. `enrichSiteWithBrandPartnerRadius` has no such check — the only guard is `is_string($professionalId) && trim($professionalId) !== ''`, which any valid UUID passes. This is a direct tenant-boundary bypass in the public path.
    - **Plain English:** Imagine an affiliate's public page displays the brand's name and design style. The affiliate tells the system "my brand partner is professional #123" by saving it in their settings. In one code path, the system double-checks "are you actually linked to #123?" before showing that brand's data. In another path, it skips that check entirely. An affiliate could type any professional's ID number into their settings and that person's name and design details would appear on the affiliate's public webpage — even if they have no relationship at all.
    - **Evidence:**
        ```php
        // enrichSiteWithBrandPartnerRadius — NO BrandPartnerLink check before enrichment:
        $professionalId = $brandPartner['professional_id'] ?? $brandPartner['professionalId'] ?? null;
        if (! is_string($professionalId) || trim($professionalId) === '') {
            return $site;
        }

        $enrichment = $this->resolveBrandPartnerEnrichmentData(trim($professionalId));
        ```
        ```php
        // resolveBrandPartnerEnrichmentData — queries another professional's PII with no auth:
        $partnerSite = Site::query()
            ->where('professional_id', $professionalId)
            ->first(['settings']);

        $partnerProfessional = Professional::query()
            ->whereKey($professionalId)
            ->first(['handle', 'first_name', 'last_name']);
        ```
        ```php
        // Contrast: applyBrandImageFallbacks DOES verify the link first:
        $link = BrandPartnerLink::where('affiliate_professional_id', $affiliateId)
            ->where('brand_professional_id', $claimedBrandId)
            ->first();

        if (! $link) {
            Log::warning('Brand-partner enrichment skipped: no verified link in consent table.', [...]);
            return $payload;
        }
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#SEC-2** · P1 — Shopify access token prefix and length logged to console in diagnostic command
    - **Where:** app/Console/Commands/Diagnostics/ShopifyTokenDiagnoseCommand.php:72-75
    - **Affects:** Any operator running `shopify:diagnose`; the console output (and any log aggregator capturing stdout) receives partial credential material.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the `token len` and `token prefix` output lines entirely. Replace with a boolean `token present: yes/no`.
        - Keep the `looks like shpat_?` line but compute it without echoing the prefix — store the boolean result and print only `yes` or `no`.
    - **Technical:** Shopify offline access tokens follow the format `shpat_` + 32 hex characters. Revealing the length (`strlen($token)`) and the first 8 characters (`substr($token, 0, 8)`) materially reduces the entropy an attacker must brute-force if they obtain the console log. In environments where CI/CD or scheduled-task output is captured to log aggregators (Nightwatch, CloudWatch, Datadog), this constitutes persistent credential leakage. The values come from decrypting `ProfessionalIntegration::access_token` — a live, revocable-but-powerful credential that grants full Admin API access to the brand's Shopify store.
    - **Plain English:** This diagnostic tool prints part of the Shopify store key to the screen. It's like reading out the first 8 digits of a credit card number and saying how long the full number is. If those logs are ever stored or viewed by someone who shouldn't have them, it reduces the work needed to guess the rest of the key. The fix is simple: stop printing any part of the key and just say "yes, a token is present" or "no, it's missing."
    - **Evidence:**
        ```php
        $this->line('token len:      '.strlen($token));
        $this->line('token prefix:   '.substr($token, 0, 8).'...');
        $this->line('looks like shpat_? '.(str_starts_with($token, 'shpat_') ? 'yes' : 'NO'));
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#SEC-3** · P2 — Full PII payload cached without audience filtering in ProfessionalCacheService
    - **Where:** app/Services/Cache/ProfessionalCacheService.php:95-127 (toPayload method)
    - **Affects:** Any authenticated code path that calls `getPayloadById` / `getPayloadByHandle` / `getPayloadByAuthId` and returns the cached array without Resource-class filtering; PII (contact email, phone, full street address, postcode) persists in Redis.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Audit every caller of `ProfessionalCacheService::getPayload*` methods and confirm a Resource class strips PII fields before the response leaves the server.
        - Consider splitting `toPayload` into two methods: `toPublicPayload` (handle, display_name, bio, professional_type) and `toPrivatePayload` (adds email, phone, location) — so callers opt into PII explicitly.
    - **Technical:** The `toPayload` method assembles a comprehensive array including `public_contact_number`, `public_contact_email`, `location_street_address`, `location_city`, `location_state`, `location_postcode`, and `location_country`. This array is cached in Redis under keys like `pro:payload:id:{uuid}` and `pro:payload:handle:{handle}`. If any controller bypasses Resource-class filtering (or a new endpoint is added that returns `$cache->getPayloadById()` directly), PII leaks to the caller. The cache keys are predictable with a professional UUID, so a Redis exposure or misconfigured cache introspection would dump PII for every cached professional. The fields are labeled "public_" in the model but the cache makes no distinction between public and private audiences.
    - **Plain English:** The system stores a complete profile card in Redis — including email, phone, and full street address — and any part of the code can grab it with just the professional's ID number. Right now, the "front desk" (Resource classes) is supposed to strip out sensitive fields before handing the card to a website visitor. But if someone adds a new endpoint and forgets to put a front desk in front of it, the full card gets handed over. The safer design is to keep two separate cards in the back room: a public one with just name and bio, and a private one with contact details that's only available to the owner.
    - **Evidence:**
        ```php
        return [
            'professional' => [
                'id' => $pro->id,
                // ...
                'public_contact_number' => $pro->public_contact_number,
                'public_contact_email' => $pro->public_contact_email,
                'location_street_address' => $pro->location_street_address,
                'location_city' => $pro->location_city,
                'location_state' => $pro->location_state,
                'location_postcode' => $pro->location_postcode,
                'location_country' => $pro->location_country,
                // ...
            ],
        ];
        ```
    - `[DRAFT, confidence: 0.75]`

- [ ] **#SEC-4** · P2 — Stale-while-revalidate stale copies persist past invalidation in feature flag caching
    - **Where:** app/Services/FeatureFlags/FeatureFlagService.php (forgetPro + forgetBrand flush only primary keys when invalidating overrides after setOverride/clearOverride)
    - **Affects:** Operators toggling feature flags via admin; stale override state may serve for up to ~72 minutes through the SWR fast path.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `forgetPro()` and `forgetBrand()`, delete both the primary key and its `:stale` companion (the pattern already used by `flushRegistry()` and every observer's cache bust).
        - Alternatively, if the override TTL cap via `jitteredTtl(nearestExpiry)` makes the stale window acceptable by design, document the maximum staleness in the method docblock.
    - **Technical:** `FeatureFlagService::forgetPro()` calls `Cache::forget("ff:pro:{$proId}")` but the preceding `loadProOverrides()` writes via `CacheLockService::rememberLocked()`, which stores a `:stale` twin with 10× the primary TTL. The same pattern applies to `forgetBrand()`. After `setOverride` or `clearOverride` invalidates the primary key, the SWR fast path in `rememberLocked` will serve the stale (pre-change) override map until the stale TTL expires. The docblock on `flush()` acknowledges "SWR stale copies persist up to ~72 min worst case" — but `forgetPro`/`forgetBrand` are the hot-path invalidation methods called on every override write and should clear the stale copy immediately, matching the `flushRegistry()` pattern which already deletes both keys.
    - **Plain English:** When an admin flips a feature flag, the system clears the main "current rules" note. But there's a backup copy (the "in case the main note is missing" note) that doesn't get cleared. For up to 72 minutes, anyone checking that flag reads the old backup note instead of waiting for the new one. The fix is to clear both notes at the same time — a pattern already used everywhere else in the codebase for cache invalidation.
    - **Evidence:**
        ```php
        public function forgetPro(string $proId): void
        {
            Cache::forget("ff:pro:{$proId}");
            Cache::forget("ff:pro:{$proId}:stale");
        }
        ```
        Wait — re-reading the code, `forgetPro` actually DOES delete both keys. Let me re-check... Yes, the current code includes `:stale` bust. This finding is invalid — I was looking at an earlier mental model. Let me re-verify:

        ```php
        public function forgetPro(string $proId): void
        {
            Cache::forget("ff:pro:{$proId}");
            Cache::forget("ff:pro:{$proId}:stale");
        }
        ```

        This is correct. Both keys are deleted. I'll drop this finding.

        But wait — `setOverride` calls `forgetPro`/`forgetBrand`, which bust both primary and stale. So overrides are immediately reflected. The `flush()` method (which flushes the registry only) documents the 72-min stale window, but that's for registry changes (adding/editing FeatureFlag rows), not overrides. This is acceptable. Dropping this finding.

    - `[DRAFT, confidence: 0.0]` — **WITHDRAWN — forgetPro/forgetBrand already delete :stale copies.**

<!-- ═══ CHUNK: svc-prof-stripe ═══ -->

- [ ] **#SEC-1** · P2 — Full Supabase Admin API response body logged verbatim on failure
    - **Where:** app/Services/Professional/AccountDeletionService.php:335-339
    - **Affects:** Log aggregator persistence (Nightwatch / Papertrail / Cloudwatch) — any downstream system that retains Laravel log entries indefinitely.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `'body' => $response->body()` with `'body' => substr($response->body(), 0, 200)` or log only the status code.
        - Add a project-wide convention in CLAUDE.md that HTTP response bodies must never be logged in full from auth or admin APIs.
    - **Technical:** The `deleteSupabaseAuthUser` helper calls the Supabase GoTrue Admin API (`DELETE /auth/v1/admin/users/{id}`) and logs `$response->body()` on any non-404 failure. While GoTrue error responses today are JSON error objects (`{"code":500,"msg":"Database error"}`), logging full response bodies from an auth-service API creates a pattern that can leak tokens or PII if the upstream response schema changes, or if the pattern is copy-pasted to a more sensitive endpoint. Laravel's default log stack (single / daily / stack) writes these entries to disk without automatic redaction; log aggregators retain them indefinitely.
    - **Plain English:** When something goes wrong while deleting a user's login account on Supabase, we write the entire raw response from Supabase into our log files. Right now those responses are just error codes, but if Supabase ever changes what they return in an error — or if someone copies this pattern to a different API call — we could end up with customer emails, phone numbers, or access tokens sitting in log files forever. The fix is to log only the status code and the first few characters of the response, not the whole thing.
    - **Evidence:**
        ```php
        if (! $response->successful()) {
            Log::error('Supabase auth user deletion failed', [
                'auth_user_id' => $authUserId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        }
        ```
    - `[DRAFT, confidence: 0.85]`

<!-- ═══ CHUNK: svc-commerce ═══ -->

- [ ] **#SEC-1** · P1 — Unvalidated shop domain enables SSRF + Shopify access token leakage in affiliate catalog queries
    - **Where:** `app/Services/Store/AffiliateProductCatalogService.php:399-433`
    - **Affects:** Every affiliate-triggered product catalog read. A compromised or malformed `shop_domain` in `provider_metadata` would cause the access token to be sent to an arbitrary host.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap `$shopDomain` with `ShopDomain::fromUntrusted($shopDomain)` before constructing the URL.
        - Replace the raw `Http::post()` call with `$this->client->graphql()` (inject `ShopifyAdminClient`) so throttle budget tracking, cost reconciliation, and metrics are not bypassed on every affiliate catalog pagination.
    - **Technical:** Every other Shopify service in this codebase validates the domain at the boundary via `ShopDomain::fromUntrusted()`. `BrandCatalogService` — which serves the brand-admin catalog — routes through `ShopifyAdminClient::graphql()`, which itself calls `ShopDomain::fromUntrusted()`. `AffiliateProductCatalogService::queryAdminCatalog()` alone extracts `shop_domain` from metadata, checks only for empty string, then builds a URL via string interpolation and sends the access token with `Http::withHeaders(['X-Shopify-Access-Token' => $accessToken])`. A stored domain like `evil.com` would receive a valid Shopify access token. This is category 7 (SSRF). The parallel bypass of `ShopifyAdminClient` also drops rate-limit protection and cost observability on the highest-volume read path in the system — every affiliate browsing a catalog pages through this method.
    - **Plain English:** Imagine every door in the building has a security guard who checks IDs, except one side entrance where the guard just waves people through if their badge isn't blank. This service is that side entrance. Every other Shopify call in the system validates that the store domain looks like `*.myshopify.com` before sending credentials. This one skips that check, so if the stored domain ever gets corrupted or replaced, the brand's access token gets handed to whatever server sits at that address.
    - **Evidence:**
        ```php
        $shopDomain = trim((string) Arr::get($metadata, 'shop_domain', ''));
        $accessToken = trim((string) ($integration->access_token ?? ''));
        // ...
        if ($shopDomain === '' || $accessToken === '') {
            return [];
        }

        $url = "https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json";
        // ...
        $response = Http::timeout(20)
            ->acceptJson()
            ->withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
            ])
            ->post($url, [
                'query' => $query,
                'variables' => $variables,
            ]);
        ```
    - `[DRAFT, confidence: 1.0]`

<!-- ═══ CHUNK: svc-rest-models ═══ -->

- [ ] **SEC-1** · P1 — Commerce models CommissionClawback, CommissionPayoutItem, and OrderEvent missing registered authorization policies
    - **Where:** app/Providers/AppServiceProvider.php (missing Gate::policy registrations)
    - **Affects:** Any API endpoints that resolve these models via route-model binding; potential IDOR if a brand or affiliate can read/write clawback details, payout line items, or order audit events belonging to another tenant.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add policies (extending CommissionPolicy or a new policy) for CommissionClawback, CommissionPayoutItem, and OrderEvent.
        - Register each via Gate::policy() in AppServiceProvider::boot().
        - Audit controllers that resolve these models to use authorizeForUser with the resolved professional.
    - **Technical:** The Partna Authorization Doctrine requires every tenant-owned model to have a registered policy. CommissionClawback links to a payout (brand_professional_id/affiliate_professional_id) and contains sensitive financial data. Without a policy, the Gate defaults to denying access, but any code that avoids Gate (e.g., model binding without authorization) could expose data across tenants. All three models carry tenant identifiers and are plausible REST resources.
    - **Plain English:** Imagine the company safe has three drawers that hold sensitive transaction details, but only the main vault has a lock. An employee could open the other drawers simply by knowing the drawer number, because nobody installed the required locks. We need to install locks on all drawers.
    - **Evidence:**
        ```php
        // AppServiceProvider::boot() registers many policies but omits these:
        // No line for CommissionClawback, CommissionPayoutItem, or OrderEvent.
        Gate::policy(\App\Models\Commerce\CommissionPayout::class, \App\Policies\CommissionPolicy::class);
        Gate::policy(\App\Models\Commerce\Order::class, \App\Policies\CommissionPolicy::class);
        // ...
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **SEC-2** · P2 — Customer model exposes PII (email, phone, full_name) in default serialization
    - **Where:** app/Models/Core/Professional/Customer.php (missing $hidden)
    - **Affects:** Any serialization path (API Resource fallback, queue job payloads, log dumps) that calls toArray() on a Customer instance; potential PII leak to logs, job dashboards, or API consumers.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add email, phone, full_name to the $hidden array to prevent accidental exposure.
        - Ensure existing CustomerResource properly selects only intended fields; add regression test.
    - **Technical:** Laravel's $hidden property controls which attributes are excluded from toArray() and JSON serialization. Customer currently has no $hidden, meaning email, phone, and full_name will appear whenever the model is serialized. Even if a CustomerResource is used, a fallback to toArray() (e.g., in an exception handler or queue payload) would expose these fields. GDPR compliance requires minimising PII exposure.
    - **Plain English:** The customer file folder has a "PRIVATE" stamp on the cover, but every page inside is printed with the customer's phone number and email in the margin. Anyone who picks up the folder sees the sensitive details. We need to remove that info from the public-facing pages.
    - **Evidence:**
        ```php
        // Customer model — note the absence of a $hidden array:
        protected $fillable = [
            'professional_id',
            'email',
            'phone',
            'full_name',
            // ...
        ];
        // No protected $hidden = ['email', 'phone', ...];
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **SEC-3** · P2 — Analytics models (SiteVisit, LinkClick, SectionView, LeadSubmission, CartEvent) expose visitor identifiers in default serialization
    - **Where:** app/Models/Analytics/*.php (each model missing $hidden for ip_hash, user_agent, etc.)
    - **Affects:** Analytics API endpoints that return visit/click/lead data; exposed IP hashes and user-agent strings could be used to fingerprint visitors across sites, violating user expectations and GDPR best practices.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add ip_hash, user_agent, and any other telemetry fields to $hidden on each model.
        - Ensure Analytics Resource classes explicitly select only approved public fields.
    - **Technical:** These models store visitor telemetry (ip_hash, user_agent) without setting them hidden. If a controller serializes the model directly (e.g., return response()->json($visit)), these identifiers leak. Even if current Resource classes filter, any future serialization (queue jobs, debug logs) would expose them. Since ip_hash is a one-way hash of the visitor IP, combined with user-agent it can uniquely re-identify a user across sessions.
    - **Plain English:** The site visit log book records not just that someone visited, but also a scrambled version of their home address and a detailed description of their car. If that book were left open on a desk, anyone walking by could link visits together and build a profile. We should keep those details in a locked drawer.
    - **Evidence:**
        ```php
        // Example from SectionView:
        protected $fillable = [
            'section_key',
            'occurred_at',
            'session_id',
            'visitor_id',
            'ip_hash',       // ← should be hidden
            'user_agent',    // ← should be hidden
            // ...
        ];
        // No protected $hidden declared.
        ```
    - `[DRAFT, confidence: 0.9]`

<!-- ═══ CHUNK: jobs ═══ -->

- [ ] **#SEC-1** · P1 — Customer email exposed in serialized job payload and logged on failure
    - **Where:** app/Jobs/Notifications/SyncCustomerMarketingOptInJob.php:37-41, 88-95
    - **Affects:** Customer privacy — every time an EmailSubscription save triggers this job, the customer's plaintext email address is written to the queue payload (Horizon/Redis) and appears in log aggregator output on failure.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `public string $email` in the constructor with `public string $customerId` and resolve the email inside `handle()` from the `Customer` model.
        - Remove `'email' => $this->email` from the `failed()` log context — `professional_id` + `subscribed` is sufficient for incident response.
    - **Technical:** Laravel serializes every public property on a queued job into the `payload` column of the `jobs` table (or Redis). That column is visible to any operator with database access and persists until the job completes. The `failed()` method further writes the email to the application log, where it lives for the retention period of the log aggregator (Nightwatch or equivalent). Both paths violate the principle that PII should only be stored in the primary datastore under access control, not duplicated into operational systems.
    - **Plain English:** Think of the queue system like a post office sorting room — every package going through has its contents label facing up. Right now, customer email addresses are printed on the outside of the package for any mail sorter to see. They should be inside the envelope, referenced by a tracking number. Plus, we're writing the email on a clipboard in the break room when something goes wrong.
    - **Evidence:**
        ```php
        public function __construct(
            public readonly string $professionalId,
            public readonly string $email,
            public readonly bool $subscribed,
        ) {
            $this->onQueue('notifications');
        }
        ```
        ```php
        public function failed(Throwable $e): void
        {
            report($e);
            Log::error('notifications.sync_customer_marketing_opt_in.failed', [
                'professional_id' => $this->professionalId,
                'email' => $this->email,
                'subscribed' => $this->subscribed,
                'error' => $e->getMessage(),
            ]);
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SEC-2** · P2 — Professional notification email exposed in serialized job payload
    - **Where:** app/Jobs/Notifications/SendEnquiryNotificationJob.php:32-38
    - **Affects:** Professional privacy — the enquiry notification destination email is written into the queue payload for every contact-form submission. While the `failed()` handler correctly avoids logging it, the email still persists in the jobs table/Redis for the job's lifetime.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `public string $notificationEmail` with `public string $enquiryId` and resolve the email from the Site/Professional row inside `handle()`.
        - The `failed()` handler already follows this pattern — extend it to the constructor.
    - **Technical:** Same serialization issue as SEC-1: Laravel's queue system serializes every public property into the job payload. The `failed()` handler commendably excludes the email from log context with the comment "log retention exceeds GDPR/Privacy Act expectations," but the email still sits in the queue payload until the job completes or is purged from the failed-jobs table. Resolving it inside `handle()` from the authoritative datastore keeps it out of the queue entirely.
    - **Plain English:** The good news: we already recognized this is sensitive and stopped writing it on the break-room clipboard. The bad news: we're still printing it on the outside of the package. Fixing that means the email only lives in the database, never on a routing label.
    - **Evidence:**
        ```php
        public function __construct(
            public readonly string $enquiryId,
            public readonly string $notificationEmail,
        ) {
            $this->onQueue('notifications');
        }
        ```
        ```php
        // Don't log the professional's notification_email — log retention exceeds
        // GDPR/Privacy Act expectations; enquiry_id is sufficient to recover the
        // email from the database during incident response.
        Log::error('SendEnquiryNotificationJob failed permanently', [
            'enquiry_id' => $this->enquiryId,
            'error' => $e->getMessage(),
        ]);
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#SEC-3** · P2 — SSRF risk: logo URL from Shopify vendor response used in HTTP fetch without domain validation
    - **Where:** app/Jobs/Shopify/SyncShopifyBrandDesignJob.php:248-262
    - **Affects:** Outbound HTTP requests from the application server — if a compromised or misconfigured Shopify store returns a logo URL pointing to an internal service, the server will fetch it.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a domain allow-list check before the `Http::get()` call — validate that `$sourceUrl` matches a known Shopify CDN pattern (e.g. `cdn.shopify.com`, `shopify.com`).
        - Alternatively, parse the host with `parse_url()` and reject any URL whose host resolves to an RFC1918, loopback, or link-local address.
    - **Technical:** `persistLogoFromShopify()` receives `$sourceUrl` from `BrandDesignImporter::import()`, which pulls it from the Shopify Brand API response. The only validation is `str_starts_with($sourceUrl, 'https://')`. While Shopify is a trusted vendor, a compromised theme or a store with an externally-hosted logo could cause the application server to issue an outbound GET to an arbitrary host. The redirect policy constrains protocol to HTTPS and max 3 hops, which mitigates protocol downgrade but not destination poisoning. Adding a domain suffix check (`shopify.com` or `cdn.shopify.com`) is a low-cost defense-in-depth measure.
    - **Plain English:** We download a brand's logo from a URL that Shopify tells us about. Right now we check that the URL starts with "https" but we don't check *where* it's actually pointing. If a Shopify store got clever and pointed their logo at an internal company server, our servers would happily knock on that door. A quick "does this domain end in shopify.com?" check closes that door.
    - **Evidence:**
        ```php
        private function persistLogoFromShopify(
            BrandDesignMediaService $brandDesign,
            Site $site,
            string $professionalId,
            string $variant,
            ?string $sourceUrl,
        ): void {
            if (! is_string($sourceUrl) || $sourceUrl === '' || ! str_starts_with($sourceUrl, 'https://')) {
                return;
            }

            try {
                $response = Http::timeout(20)
                    ->withOptions(['allow_redirects' => ['max' => 3, 'protocols' => ['https']]])
                    ->get($sourceUrl);
        ```
    - `[DRAFT, confidence: 0.7]`

- [ ] **#SEC-4** · P2 — Webhook job falls back to untrusted payload domain when integration record is missing
    - **Where:** app/Jobs/Shopify/ProcessShopifyOrderUpdatedWebhookJob.php:543-553
    - **Affects:** Order-update webhook processing — if the integration row is absent (disconnected, deleted), the shop domain used to locate the order comes from the webhook payload itself rather than the authoritative database record.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the fallback to `$this->payload['domain']`. If the integration record is missing, return an empty string and let the caller short-circuit.
        - This ensures the shop domain is always sourced from the authoritative `professional_integrations` row, which was written during the OAuth flow, never from a webhook payload that an attacker could forge (if HMAC verification were ever bypassed).
    - **Technical:** `resolveShopDomain()` first queries `professional_integrations` for the `shopify_shop_domain` column. If that returns null (integration deleted, disconnected, or never existed), it falls back to `Arr::get($this->payload, 'domain')` — a field controlled entirely by the Shopify webhook sender. While production HMAC verification should prevent forged payloads from reaching this job, defense-in-depth says the domain should only come from the database. If an attacker ever finds a way to dispatch this job with a forged payload (misconfigured webhook route, middleware bypass, test environment), the fallback gives them full control over which shop domain the order lookup targets.
    - **Plain English:** When we need to figure out which store an order belongs to, we look it up in our own records. But if that record is missing, we shrug and ask the incoming message "so, which store are you from?" — and trust whatever it says. That's like a bouncer who checks the guest list, can't find your name, and then says "just tell me who you are and I'll let you in." The fix: if it's not on the list, stop there.
    - **Evidence:**
        ```php
        private function resolveShopDomain(): string
        {
            $integration = \App\Models\Core\Professional\ProfessionalIntegration::query()
                ->where('professional_id', $this->professionalId)
                ->where('provider', \App\Models\Core\Professional\ProfessionalIntegration::PROVIDER_SHOPIFY)
                ->value('shopify_shop_domain');

            if ($integration) {
                return (string) $integration;
            }

            // Fallback: try to get domain from payload (some topics include it)
            $domain = strtolower(trim((string) Arr::get($this->payload, 'domain', '')));

            return $domain;
        }
        ```
    - `[DRAFT, confidence: 0.75]`

- [ ] **#SEC-5** · P2 — Professional contact email logged in GDPR export job completion message
    - **Where:** app/Jobs/Shopify/Gdpr/ExportCustomerDataJob.php:96-101
    - **Affects:** Professional privacy — the professional's contact email (used as the GDPR export delivery address) is written to the application log on every successful export.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `'recipient' => $recipientEmail` with `'professional_id' => $professionalId` in the log context.
        - The `professional_id` is already present in the log entry and is sufficient to recover the email from the database during incident response.
    - **Technical:** `ExportCustomerDataJob` sends a customer-data export to the professional's contact email for GDPR compliance. On success, it logs `Log::info('ExportCustomerDataJob completed.', ['recipient' => $recipientEmail, ...])`. This writes the professional's business contact email to the application log stream, where it persists for the log retention period. While this is a professional email (not a consumer/customer email), it is still personally identifiable under Australian Privacy Act definitions when the professional is a sole trader or individual. The same job already has `professional_id` in scope — using it instead costs nothing.
    - **Plain English:** When we finish packaging up a GDPR data export, we write a note in the operations log saying "package sent to joe@example.com." That note sticks around in the logs for months. If Joe is a sole trader, that's his personal email sitting in a system that wasn't designed to store personal data. We already write Joe's account ID in the same note — that's enough to find his email in the database if we ever need to.
    - **Evidence:**
        ```php
        Log::info('ExportCustomerDataJob completed.', [
            'gdpr_request_id' => $gdpr->id,
            'professional_id' => $professionalId,
            'recipient' => $recipientEmail,
            'customer_records' => count($exportData['customers'] ?? []),
        ]);
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#SEC-6** · P3 — File extension from database used in temp-file path construction without validation
    - **Where:** app/Jobs/Exports/ExportFinalizerJob.php:83
    - **Affects:** Export subsystem — if the `format` column on `commission_export_audits` were ever corrupted or injected, it could produce an unexpected file extension or path traversal.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Assert `$audit->format` is in an allow-list (`['csv', 'xlsx']`) before using it in the path, or use a validated constant from the model.
        - Apply the same guard to any other path where `$audit->format` is interpolated into a filename.
    - **Technical:** `ExportFinalizerJob::handle()` constructs a temp-file path with `tempnam(sys_get_temp_dir(), 'cep_final_') . '.' . $audit->format`. The `format` value originates from a user request (the export API endpoint) and is stored in the database. In practice, the FormRequest or controller should validate it to `['csv', 'xlsx']`, and the database value is trustworthy at read time. However, defense-in-depth says the job should not trust the database value for filesystem operations — a future bug in the validation layer or a direct database update could inject `../../.env` or similar. An allow-list check inside the job costs one line and eliminates the risk permanently.
    - **Plain English:** We build a temporary filename by gluing the export format (like "csv" or "xlsx") onto the end. If something goes wrong upstream and that field says "../../secrets" instead of "csv," the file ends up somewhere unexpected. A quick "is this one of the two allowed formats?" check inside the job itself makes that impossible, no matter what happened earlier in the pipeline.
    - **Evidence:**
        ```php
        $tmpFinal = tempnam(sys_get_temp_dir(), 'cep_final_') . '.' . $audit->format;
        ```
    - `[DRAFT, confidence: 0.6]`

<!-- ═══ CHUNK: ctrl-prof-a ═══ -->

- [ ] **#SEC-1** · P2 — BrandAffiliateController::index leaks affiliate email and phone to all connected brands
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandAffiliateController.php:67-70
    - **Affects:** Every brand can read the personal email and phone of every affiliate they've connected with, regardless of whether the affiliate expects that information to be shared.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove `email` and `phone` from the response array unless explicitly required by the business model.
        - If contact details are needed, gate them behind a dedicated endpoint with an “expose contact info to brand” consent toggle, so affiliates can opt in.
    - **Technical:** The `index` method maps `BrandPartnerLink` rows to affiliate identities and includes `primary_email`/`public_contact_email` and `phone`/`public_contact_number`. No consent check is performed. Under GDPR and Australian privacy principles, sharing PII with a data controller (the brand) without a clear purpose or consent is a PII exposure.
    - **Plain English:** Imagine every shop you’ve ever partnered with could see your personal phone number and email address in their dashboard, even if you only intended to share a public handle. This finds that until that exposure is removed or made opt-in, all brands can see all affiliates’ private contact details.
    - **Evidence:**
        ```php
        return [
            'id' => $connectedProfessional?->id,
            // ...
            'email' => $connectedProfessional?->primary_email ?? $connectedProfessional?->public_contact_email,
            'phone' => $connectedProfessional?->phone ?? $connectedProfessional?->public_contact_number,
            // ...
        ];
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SEC-2** · P3 — Inline `abort_unless` replaces Policy for custom-link feature gating
    - **Where:** app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalLinkBlockController.php:47-55
    - **Affects:** Any attempt to create a custom link; currently rejected by a hardcoded config check rather than a policy ability.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move the allow/deny logic into a Policy method (e.g., `allowCustomLinks`) on `BlockPolicy`.
        - Replace `abort_unless(…)` with `$this->authorizeForUser($pro, 'allowCustomLinks', $someModel)`.
    - **Technical:** The Partna authorization doctrine requires all gating to go through Policies. The current inline `abort_unless` on a config key deviates from that standard. It also couples the controller to a configuration detail that should be isolated in the policy layer.
    - **Plain English:** The front door has a security guard checking IDs, but this particular gate is being checked by a handwritten sign taped to the wall. Put a proper lock on it that follows the same rules as every other door.
    - **Evidence:**
        ```php
        abort_unless(
            (bool) config("partna.account_type_defaults.{$type}.custom_links_allowed", false),
            403,
            'Custom links are not available on your account type.'
        );
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#SEC-3** · P3 — Delete product selection skips `authorizeForUser`, uses inline ownership check
    - **Where:** app/Http/Controllers/Api/Professional/Store/AffiliateProductController.php:207-225
    - **Affects:** Affiliates removing a product selection. The inline query is currently scoped by `affiliate_professional_id`, but a policy would add defence-in-depth (e.g., pending-deletion 423).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Call `$this->authorizeForUser($pro, 'delete', $selection)` after fetching the selection.
        - Ensure `AffiliateProductSelectionPolicy` has a `delete` method that replicates the ownership check.
    - **Technical:** The controller uses `AffiliateProductSelection::query()->where('affiliate_professional_id', $pro->id)->first()` and then immediately deletes. While this scopes the delete to the authenticated affiliate, it bypasses the Policy layer, meaning any future guard (e.g., soft-delete grace period, pending-deletion lock) would not be enforced.
    - **Plain English:** The door has a working lock, but the security guard isn't logging the entry. If we ever need to add an extra check — like “can’t delete while a payout is pending” — this code wouldn’t apply it.
    - **Evidence:**
        ```php
        $selection = AffiliateProductSelection::query()
            ->where('affiliate_professional_id', $pro->id)
            ->where('shopify_product_gid', $gid)
            ->first();

        if (! $selection) {
            return $this->error('Selection not found.', 404);
        }

        $selection->delete();
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SEC-4** · P3 — Product photo operations rely on inline selection check instead of `authorizeForUser`
    - **Where:** app/Http/Controllers/Api/Professional/Store/AffiliateProductPhotoController.php:48-50, 74-80, 104-106, 130-132
    - **Affects:** Affiliates uploading, viewing, deleting, or reordering custom product photos. The checks are correct but should be routed through Policies for consistency and future-proofing.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Introduce `affiliate_product_selection_id` on the `SiteMedia` row or load the selection and call `$this->authorizeForUser($pro, 'view/update/delete', $selection)`.
        - Centralize the “affiliate owns this selection” check in `AffiliateProductSelectionPolicy`.
    - **Technical:** Each method queries `AffiliateProductSelection::query()->where('affiliate_professional_id', $pro->id)->where('shopify_product_gid', $gid)->exists()` to gate access. This repeats the ownership logic in four places. A Policy would make the check testable, auditable, and respect the project-wide authorization doctrine.
    - **Plain English:** Instead of installing a lock on the door, we’re sending a different security guard to manually verify every person who tries to open it. Standardising the locks means future doors are secured the same way without rewriting the guard’s instructions each time.
    - **Evidence:**
        ```php
        $hasSelection = AffiliateProductSelection::query()
            ->where('affiliate_professional_id', $pro->id)
            ->where('shopify_product_gid', $gid)
            ->exists();

        if (! $hasSelection) {
            return $this->error('You can only upload photos for products you have selected.', 422);
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SEC-5** · P3 — BrandAffiliateController::disconnect does not call `authorizeForUser`
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandAffiliateController.php:93-114
    - **Affects:** Brands trying to disconnect an affiliate. The service layer may already validate the relationship, but the controller does not enforce a Policy on the link itself.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Load the `BrandPartnerLink` record before calling `disconnect` and call `$this->authorizeForUser($pro, 'delete', $link)`.
        - Return 404 if the link doesn't exist (policy will handle).
    - **Technical:** The method fetches the affiliate `Professional` by ID without verifying they are linked to the acting brand. The `DisconnectRequest` is passed to a service that presumably checks, but a defender is missing at the controller layer. A Policy would enforce the same ownership contract used elsewhere.
    - **Plain English:** We’re passing a note to the back office saying “detach this person” without first checking that the person is actually attached. The back office double-checks, but the front desk should too.
    - **Evidence:**
        ```php
        $affiliate = Professional::query()->whereKey($affiliateId)->first();
        if (! $affiliate) {
            return $this->error('Affiliate not found.', 404);
        }

        $result = $lifecycle->disconnect(DisconnectRequest::forBrand(
            brand: $professional,
            affiliate: $affiliate,
            reason: $data['reason'] ?? null,
        ));
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SEC-6** · P3 — Inline `isBrand()` checks duplicate middleware gates
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandAffiliateInviteController.php:28,56,78,110,132,177,197
    - **Affects:** Several invite endpoints; the middleware already restricts access to brand accounts, so the inline checks are redundant and break the single-source-of-truth principle.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove all `if (! $professional->isBrand()) { return error }` blocks, trusting `brand.only` middleware.
        - If any endpoint needs an additional capability check, express it in a Policy.
    - **Technical:** The `brand.only` middleware (EnsureBrandAccount) already gates these routes. The inline `isBrand()` calls create a second, inconsistent authorization path that could drift from middleware logic and are disallowed by the authorization doctrine.
    - **Plain English:** We’re posting a guard at the gate and then stopping each person again inside the building to re-check the same ID. Remove the second check so everyone follows the same entrance rules.
    - **Evidence:**
        ```php
        if (! $professional->isBrand()) {
            return $this->error('Only brand accounts can view affiliate invites.', 403);
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SEC-7** · P3 — BrandStoreSettingsController::update skips Policy authorisation
    - **Where:** app/Http/Controllers/Api/Professional/Store/BrandStoreSettingsController.php:83-175
    - **Affects:** Brands updating store settings (commission rate, payout hold, storefront config). The update is gated only by the authenticated professional; a Policy should confirm the brand can manage its own settings.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - At the top of `update`, call `$this->authorizeForUser($pro, 'update', BrandStoreSettings::where('professional_id', $pro->id)->first())` or the policy on the BrandStoreSettings model.
        - Register `BrandStoreSettingsPolicy` if not yet present.
    - **Technical:** The `update` method creates or updates a `BrandStoreSettings` row scoped to `$pro->id` without any explicit authorisation check. The Partna doctrine requires all write operations on tenant-owned models to pass through a Policy.
    - **Plain English:** The settings panel is behind the login wall but doesn’t actually ask “are you allowed to change this?” Putting a formal permission check on it makes it testable and safe if we later add sub‑roles.
    - **Evidence:**
        ```php
        $pro = $this->currentProfessional($request);
        $validated = $request->validated();
        // ... directly updates BrandStoreSettings ...
        $settings = BrandStoreSettings::updateOrCreate(
            ['professional_id' => $pro->id],
            $dbFields
        );
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SEC-8** · P3 — ProfessionalGoogleBusinessProfileController writes site settings without Policy enforcement
    - **Where:** app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalGoogleBusinessProfileController.php:29-47
    - **Affects:** Professionals saving Google Business Profile data; no explicit ownership check beyond loading the site via `currentSite()`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Insert `$this->authorizeForUser($professional, 'update', $site)` before modifying site settings.
        - Ensure `SitePolicy` has an `update` ability.
    - **Technical:** The method calls `$site = $this->currentSite($professional)` which guarantees the site belongs to the professional, but then writes to `settings` without a Policy gate. The doctrine requires all mutations to be wrapped in `authorizeForUser`.
    - **Plain English:** The person is allowed inside the room, but we haven’t checked whether they have a key to the specific filing cabinet they’re opening.
    - **Evidence:**
        ```php
        $professional = $this->currentProfessional($request);
        $site = $this->currentSite($professional);
        // ...
        $settings = is_array($site->settings) ? $site->settings : [];
        $settings[self::SETTINGS_KEY] = $profile;
        $site->settings = $settings;
        $site->save();
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SEC-9** · P2 — No rate limiting on signup code rotation endpoint
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandSignupCodeController.php:38-44
    - **Affects:** The `POST …/signup-code/rotate` endpoint. A malicious actor (or a misbehaving UI) could rotate the code repeatedly, invalidating any in-flight invites and causing confusion.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Apply `throttle:5,1` (or similar) middleware to the rotate route group, keyed by brand professional id.
        - Consider adding the same protection to the deactivate/reactivate endpoints.
    - **Technical:** The `rotate` method immediately replaces the signup code with no rate limit. Unlike the resync endpoint, which uses `RateLimiter`, this operation is meant to be rare. Without throttling, an attacker who obtains a valid session could rapidly cycle codes, breaking the affiliate onboarding flow.
    - **Plain English:** This is like letting someone change the front-door combination as fast as they can type. Putting a 5‑attempts‑per‑minute cap on it makes accidental or malicious rapid changes impossible.
    - **Evidence:**
        ```php
        public function rotate(Request $request): JsonResponse
        {
            $professional = $this->currentProfessional($request);
            $profile = $this->requireBrandProfile($professional->id);
            $this->codeService->rotate($profile);
            return $this->success($this->buildResponse($profile->refresh()));
        }
        ```
    - `[DRAFT, confidence: 0.9]`

<!-- ═══ CHUNK: ctrl-prof-b-staff ═══ -->

- [ ] **#SEC-1** · P2 — Inline `abort_unless` ownership check instead of Policy in staff link-block controller
    - **Where:** app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffLinkBlockManagementController.php:75-80 and :84-89
    - **Affects:** Staff admin updating or deleting link blocks. Cross-tenant enforcement bypassed if Policy regressions are introduced at the model layer.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `abort_unless($linkBlock->professional_id === $professional->id ...)` with `$this->authorizeForUser($professional, 'update', $linkBlock)` / `'delete'`.
        - Register or confirm a `BlockPolicy` with `update`/`delete` methods that gate on `professional_id` via `BasePolicy`.
    - **Technical:** Per Partna doctrine category (2), authorization MUST go through Policies extending `BasePolicy`, never inline `abort_unless`. The current code adds a `block_group`/`block_type` gauze inside the same `abort_unless`, which duplicates what a Policy's type-gate would do. If a future refactor weakens `scopeBindings`, the inline check becomes the only line of defence — and it's not centrally testable. The Policy pattern ensures one auditable gate per model.
    - **Plain English:** There's a guard at the door checking IDs manually instead of using the building's standard keycard system. If someone changes the keycard rules later, this door won't pick up the change. The fix is to swap the manual check for the same keycard reader every other door uses.
    - **Evidence:**
        ```php
        // StaffLinkBlockManagementController.php:75-80 (update)
        abort_unless(
            $linkBlock->professional_id === $professional->id &&
            $linkBlock->block_group === 'links' &&
            $linkBlock->block_type === 'link',
            404
        );

        // StaffLinkBlockManagementController.php:84-89 (destroy)
        abort_unless(
            $linkBlock->professional_id === $professional->id &&
            $linkBlock->block_group === 'links' &&
            $linkBlock->block_type === 'link',
            404
        );
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#SEC-2** · P2 — No factor-ownership verification before MFA unenrollment (defense-in-depth gap)
    - **Where:** app/Http/Controllers/Api/Professional/Account/MfaController.php:45-48
    - **Affects:** Authenticated professionals unenrolling MFA factors. An attacker who learns a victim's factor ID could attempt cross-user factor deletion if Supabase's admin API validation is ever bypassed or misconfigured.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Before calling `unenrollMfaFactor`, call Supabase's admin `getFactor` (or equivalent) to confirm the factor's `user_id` matches `$uid`.
        - Log and reject if the factor does not belong to the authenticated user.
    - **Technical:** Category (3) tenant isolation. `$uid` is resolved from the Supabase JWT (trusted), but `$factorId` comes directly from the URL segment with no application-level ownership check. The Supabase Admin API's `unenrollMfaFactor` likely validates ownership internally, making this defense-in-depth rather than an exploitable bug today. However, MFA factor manipulation is a high-sensitivity operation — if Supabase's API behavior changes or a configuration error removes that guard, this endpoint becomes an IDOR that lets any authenticated user strip MFA from any other account.
    - **Plain English:** When you ask the system to remove a security key from your account, it takes the key's serial number from the URL and trusts that the backend will verify it's yours. If that backend check ever fails — due to a bug, a misconfiguration, or an API change — someone could remove another person's security key just by guessing the serial number. The fix is to double-check ownership at our end before sending the removal command.
    - **Evidence:**
        ```php
        // MfaController.php:45-48
        $uid = (string) $request->attributes->get('supabase_uid');
        $sessionId = $request->attributes->get('supabase_session_id');

        try {
            $this->admin->unenrollMfaFactor($uid, $factorId);
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#SEC-3** · P3 — Inline `$pro->isBrand()` gate instead of `brand.only` middleware on professional-facing document endpoint
    - **Where:** app/Http/Controllers/Api/Professional/Account/ProfessionalDocumentController.php:66-69
    - **Affects:** Brand-account users hitting the generic document upload endpoint. They get a 403; the rejection is correct but the enforcement mechanism is inconsistent with the rest of the API surface.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the inline `if ($pro->isBrand())` check.
        - Apply the `brand.only` middleware to the document-upload route in `routes/api/professional.php` with an `except: []` or inverse middleware.
    - **Technical:** Per Partna doctrine category (2) item 6, brand-only route gating should live in middleware, not in controllers. The current inline check works correctly (brands are rejected), but it spreads authorization logic across the controller layer instead of keeping it centralized in the route definition where it's visible during route auditing. If three other controllers also have inline `isBrand()` checks, that's three places to miss when the brand/affiliate split evolves.
    - **Plain English:** There's a "brands not allowed" sign taped to one specific room's door instead of being on the hallway entrance where it belongs. The sign is correct, but if the building layout changes, someone has to remember to check every room for taped-up signs instead of just updating the hallway sign once.
    - **Evidence:**
        ```php
        // ProfessionalDocumentController.php:66-69
        // Brand accounts are excluded per product spec — they have Shopify
        // for catalogue assets and don't get the generic document slot.
        if ($pro->isBrand()) {
            return $this->error('Documents section not available for brand accounts.', 403);
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#SEC-4** · P3 — Inline `$professional->isBrand()` gate on affiliate-invite listing instead of middleware guard
    - **Where:** app/Http/Controllers/Api/Professional/Affiliate/AffiliateInviteController.php:27-29
    - **Affects:** Brand-account users accidentally hitting the affiliate-invite inbox endpoint. Returns 403; functionally correct but route-authoring inconsistency.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the inline `if ($professional->isBrand())` check.
        - Route the invite-listing endpoint behind a non-brand middleware guard (inverse of `brand.only`) or scope it to `affiliate.only` middleware.
    - **Technical:** Same category (2) item 6 violation as SEC-3 — inline professional-type gating instead of middleware. The check itself is harmless (returns 403 for brands, which is the desired behavior), but it's an ad-hoc authorization decision embedded in controller code rather than declared at the route layer where the full access-control picture is visible in one file.
    - **Plain English:** Same pattern as the document endpoint — a "brands prohibited" rule enforced inside one room rather than on the hallway entrance. Centralizing it in the route definition means the rule is visible when auditing which doors each account type can walk through.
    - **Evidence:**
        ```php
        // AffiliateInviteController.php:27-29
        if ($professional->isBrand()) {
            return $this->error('Brand accounts cannot view affiliate invites.', 403);
        }
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#SEC-5** · P2 — `return_url` and `refresh_url` passed to Stripe Connect without server-side allow-list validation
    - **Where:** app/Http/Controllers/Api/Professional/Stripe/StripeConnectController.php:101-105 and app/Http/Controllers/Api/Professional/Stripe/AffiliateStripeOnboardingController.php:30-35
    - **Affects:** Professionals initiating Stripe Connect onboarding. A malicious or compromised client could supply arbitrary URLs; Stripe validates against the platform's registered domain, but missing server-side validation removes a defense-in-depth layer.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a domain allow-list check in `OnboardRequest` (the Form Request): validate that `return_url` and `refresh_url` match the Partna frontend origin or a known set of allowed redirect targets.
        - Reject with 422 before the values reach Stripe's API.
    - **Technical:** Category (7) SSRF / open redirect. While Stripe validates that `return_url` and `refresh_url` match the platform's registered Connect redirect domains, relying solely on the vendor's validation means a misconfiguration in the Stripe dashboard (e.g., a wildcard or overly permissive domain entry) could open an open-redirect vector. Server-side validation keeps the allow-list in source control where it's reviewed and versioned alongside the code that uses it.
    - **Plain English:** You're handing Stripe a return address from the customer without checking it first. Stripe has its own address-verification system, so this isn't exploitable today. But if someone fat-fingers the Stripe settings and allows a broader set of addresses, your server would happily forward any address the customer types in. Adding your own address check means two locks have to fail instead of one.
    - **Evidence:**
        ```php
        // StripeConnectController.php:101-105
        $url = $this->connectService->createOnboardingLink(
            $pro,
            $request->input('return_url'),
            $request->input('refresh_url'),
        );

        // AffiliateStripeOnboardingController.php:30-35
        $url = $this->connectService->createOnboardingLink(
            $aff,
            $request->input('return_url'),
            $request->input('refresh_url'),
        );
        ```
    - `[DRAFT, confidence: 0.70]`

<!-- ═══ CHUNK: ctrl-public-internal ═══ -->

- [ ] **SEC-1** · P1 — Public email/phone/handle enumeration via signup-availability endpoint
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicSignupAvailabilityController.php:28-52
    - **Affects:** Any unauthenticated visitor can probe registered emails, phone numbers, and handles at scale — privacy of current users and viability of targeted phishing.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the boolean `exists` fields with a uniform “check submitted” response and an out-of-band confirmation (e.g., “If that email is registered we’ll send a link”).
        - If a real-time availability widget is required, add per-IP rate limiting and consider a client-side proof-of-work or CAPTCHA.
        - Log high-frequency probe attempts so security monitoring detects enumeration sweeps.
    - **Technical:** The endpoint returns `{email: {available: false, exists: true}, phone: …, handle_lc: …}` for every request with no authentication, no throttling, and no anti-automation challenge. An attacker can POST repeatedly with candidate addresses/phones/handles and harvest the complete user list. Under GDPR principles, exposing “account exists” without user consent is a PII leak.
    - **Plain English:** This is like a membership help-desk that, when asked “Does [email] have an account here?”, answers “Yes” or “No” instantly to any stranger who asks. A scanner can try thousands of emails per minute and build a list of all registered users.
    - **Evidence:**
        ```php
        $emailExists = Professional::query()
            ->where(function ($query) use ($email) {
                $query->whereRaw('LOWER(primary_email) = ?', [$email])
                    ->orWhereRaw('LOWER(public_contact_email) = ?', [$email]);
            })
            ->exists();
        // ...
        return $this->success([
            'email' => [
                'available' => ! $emailExists,
                'exists' => $emailExists,
            ],
            // ...
        ]);
        ```
    - `[DRAFT, confidence: 0.9]`

<!-- ═══ CHUNK: http-boundary ═══ -->


<!-- ═══ SUB-CHUNK: s1 (app/Http/Requests) ═══ -->

- [ ] **#SEC-1** · P0 — Bootstrap request allows any new user to self-escalate to brand role, bypassing brand invite requirements
    - **Where:** app/Http/Requests/Api/BootstrapRequest.php:43-49 (requiresInvite logic) and :66 (professional_type rule)
    - **Affects:** Tenant roles: a brand-new user can sign up as a brand without any invitation, gaining full brand dashboard, site creation, and store management capabilities.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a server-side check in the controller that rejects `professional_type=brand` (or `account_type=brand`) for uninvited users, or require explicit brand verification (e.g., an admin approval or invite token).
        - Consider removing `brand` from the list of types a self-signup can claim, or require a separate brand-signup flow with shop verification.
    - **Technical:** The `$requiresInvite` condition in `rules()` explicitly excludes the case where `$declaredType === 'brand'`, so no invite fields are validated. The controller then creates a professional with type 'brand'. Since the authorization system uses `professional_type` for role-based access (e.g., `brand.only` middleware), this allows the user full brand privileges with no gate. This is a direct privilege escalation under the Supabase JWT scheme where the actor is resolved from the token, not from the request body.
    - **Plain English:** Door says “Brands must be invited,” but a new user can just write “brand” on the sign-up form and walk in. They get the keys to the brand-only room without anyone checking credentials.
    - **Evidence:**
        ```php
        $declaredType = mb_strtolower(trim((string) ($this->professional_type ?? '')));
        // ...
        $requiresInvite = $isFirstTimeSignup
            && ! $accountTypeIsSelfOnboard
            && $declaredType !== ''
            && $declaredType !== 'brand';
        ```
    - `[DRAFT, confidence: 1.0]`

- [ ] **#SEC-2** · P2 — StaffUpdateSiteRequest does not sanitize HTML from public-facing text fields, risking stored XSS
    - **Where:** app/Http/Requests/Api/Staff/ProfessionalSite/StaffUpdateSiteRequest.php (entire file, notably lack of prepareForValidation sanitization for hero_title, hero_subtitle, primary_button_text, bio_text)
    - **Affects:** Public site visitors if a staff user inserts malicious scripts into site text settings; affects all brands whose sites can be edited by staff.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `prepareForValidation()` method that loops over `'hero_title'`, `'hero_subtitle'`, `'primary_button_text'`, `'bio_text'` and applies `static::cleanString()` (or a similar strip-tags helper) before validation, matching the professional `UpdateSiteRequest`.
        - Ensure the public site renderer also escapes these values, but defense-in-depth is proper here.
    - **Technical:** The professional `UpdateSiteRequest` sanitizes those four fields via `cleanString()`, which strips HTML tags and control characters. The staff counterpart lacks this step entirely, so any HTML/JS is stored verbatim in the site's JSONB settings. If the Hydrogen storefront or any admin view renders these fields without escaping, it becomes a stored XSS vector. Staff accounts are trusted, but a compromised staff account could inject scripts across all sites and pivot from the internal to the public surface.
    - **Plain English:** A staff member can add a design detail that includes hidden code, like typing a script tag into a hero title. When customers visit the store, that code could run — even though staff are trusted, if their account gets hijacked, every brand’s front page could be poisoned.
    - **Evidence:**
        ```php
        // StaffUpdateSiteRequest has NO prepareForValidation() sanitization.
        // Compare with UpdateSiteRequest which does:
        foreach (['hero_title', 'hero_subtitle', 'primary_button_text', 'bio_text'] as $field) {
            if (! array_key_exists($field, $settings) || ! is_string($settings[$field])) {
                continue;
            }
            $settings[$field] = static::cleanString($settings[$field]);
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SEC-3** · P3 — UploadDocumentRequest lacks magic-byte validation, allowing disguised file uploads
    - **Where:** app/Http/Requests/Api/Professional/Documents/UploadDocumentRequest.php:15-20 (rules only specify `mimes`)
    - **Affects:** Document storage: a malicious user could upload a file with a faked extension (e.g., .php renamed to .pdf) and potentially trick the system into storing or serving an executable.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Use the `SniffsFileMimeType` trait (or similar) to verify the actual file content matches an allowed MIME type (application/pdf, image/jpeg, image/png).
        - Add `mimetypes:application/pdf,image/jpeg,image/png` rule in addition to `mimes` for extra validation, but the trait performs a deeper byte check.
    - **Technical:** Laravel's `mimes` rule trusts the client-provided extension, not the actual file content. The `UploadImageRequest` uses `SniffsFileMimeType` to guard against this, but `UploadDocumentRequest` does not. Since documents are later downloaded by customers, a disguised file could be exploited if the server relies on extension to set Content-Type, causing browsers to execute it rather than open as a document. Risk is low because typical document viewing doesn't execute code, but it's a defense gap against file-type confusion attacks.
    - **Plain English:** Checking a file's type just by its name is like verifying someone’s age by their handwriting — easy to fake. Other upload endpoints actually peek inside the file to confirm it’s really a picture; this one doesn’t, so a clever renamed file could slip through.
    - **Evidence:**
        ```php
        'file' => [
            'required',
            'file',
            'mimes:pdf,jpg,jpeg,png',
            'max:10240',
        ],
        // No withValidator() or SniffsFileMimeType usage.
        ```
    - `[DRAFT, confidence: 0.7]`

- [ ] **#SEC-4** · P3 — allowedRedirectRule permits redirection to localhost/127.0.0.1 in all environments
    - **Where:** app/Http/Requests/BaseFormRequest.php:155-170 (allowedRedirectRule closure)
    - **Affects:** Any endpoint accepting a redirect URL (Stripe onboarding, plan changes, payment method setup) — could be used as a building block in phishing or local-network exploration, though practical impact is low.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove `'localhost'` and `'127.0.0.1'` from the allowed hosts array when in a non-local environment, or conditionally include them only when `app()->environment('local')`.
        - Alternatively, make the allowed redirect domains configurable via a config array.
    - **Technical:** The closure builds an allowlist by parsing configured frontend and app URLs and adding localhost/127.0.0.1. In production, these internal hostnames could be used in a social-engineering scenario where a maliciously crafted link redirects the user to a service running on their own machine after a legitimate Stripe flow. While the attack surface is narrow (requires the user to have a local web server listening), security best practice for open-redirect protection is to exclude loopback addresses in production.
    - **Plain English:** The system’s list of safe redirect destinations includes “localhost” and “127.0.0.1” — addresses that point to the user’s own computer. In a production environment this is like leaving a side gate open; an attacker could use it to redirect someone to a fake page on their own machine after a real login, though it’s hard to pull off.
    - **Evidence:**
        ```php
        $allowed = array_filter([$frontendHost, $appHost, 'localhost', '127.0.0.1']);
        ```
    - `[DRAFT, confidence: 0.6]`

<!-- ═══ SUB-CHUNK: s2 (app/Http/Resources app/Http/Middleware) ═══ -->

- [ ] **SEC-1** · P2 — Access-Control-Allow-Origin: * on every response widens cross-origin attack surface
    - **Where:** app/Http/Middleware/SecureHeaders.php:19-21
    - **Affects:** All API responses, including those that may contain sensitive data (customer details, payouts, professional profiles). Any website can make cross-origin requests and read the responses, which could facilitate token-exfiltration or information leakage if a token is known or mishandled.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the wildcard `*` with an explicit allowlist of trusted origins (e.g., the Shopify admin domain, the Partna dashboard, the Hydrogen storefront).
        - Apply the header only on endpoints that genuinely require cross-origin access, not globally.
    - **Technical:** The middleware unconditionally sets `Access-Control-Allow-Origin: *` to “guarantee the header survives” (comment). Although the API uses Bearer tokens (not cookies), a wildcard CORS policy removes same-origin restrictions, allowing any origin to script requests. An attacker hosting a page visited by an authenticated user could potentially read API responses if the token is forceable into the request (e.g., if the token were exposed in a URL or through another XSS). This violates least-privilege and unnecessarily broadens the attack surface for a production API serving business data.
    - **Plain English:** It’s like putting a sign on every envelope that says “return to sender – anyone can read me”. Even though the envelopes are sealed, you’re inviting any stranger who picks one up to open it. You should only put return addresses on envelopes that actually need to be returned, and only for trusted senders.
    - **Evidence:**
        ```php
        if (! $response->headers->has('Access-Control-Allow-Origin')) {
            $response->headers->set('Access-Control-Allow-Origin', '*');
        }
        ```
    - `[DRAFT, confidence: 0.9]`

<!-- ═══ CHUNK: config ═══ -->


<!-- ═══ SUB-CHUNK: s1 (config) ═══ -->

- [ ] **#SEC-1** · P1 — Env-var dump endpoint gated by a single shared-secret token that, if leaked, exposes every API key in the system
    - **Where:** config/partna.php:4-9
    - **Affects:** All third-party service credentials (Stripe, Shopify, Cloudflare, Supabase, Hydrogen, Twitch, Kick, Square, Fresha, Turnstile, Slack, Resend, Google Maps, Postmark) — every secret consumed via `env()`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the `GET /api/internal/env-check` endpoint entirely. If an env-var report is needed for ops, ship it via an Artisan command (`php artisan env:check`) that runs on the server, never over HTTP.
        - If the endpoint must stay, ensure the controller uses `hash_equals` for token comparison, rate-limit to single-digit requests per minute per IP, and never include values for keys matching `*_KEY`, `*_SECRET`, `*_TOKEN`, `*_PASSWORD` — return only key names + redacted placeholders.
    - **Technical:** An HTTP endpoint that returns `$_ENV` or `config()` values for every env var is a single-point-of-failure for secret management. A single leaked `INTERNAL_ENV_CHECK_TOKEN` (commit to source, Slack paste, log line) hands an attacker every Stripe, Shopify, Cloudflare, and Supabase credential in one request. Even with `hash_equals`, the blast radius of this endpoint dwarfs any other secret-storage concern in the codebase. The fail-closed default (503 when unset) is good hygiene but doesn't reduce the endpoint's danger when enabled.
    - **Plain English:** Imagine a master key that opens every safe in the building — the office safe, the cash drawer, the server room, the filing cabinets. That's this endpoint. It's protected by a single password. If that password ever leaks (someone pastes it in Slack, commits it to a repo, or an attacker guesses it), every other lock in the building becomes irrelevant. The fix is to either remove the master-key door entirely, or make sure it never hands out the actual contents of the safes — just tells you which safes exist.
    - **Evidence:**
        ```php
        // Shared-secret token for GET /api/internal/env-check. Required to enable
        // the endpoint. When unset, the endpoint returns 503 — fail-closed by default
        // so a fresh deploy never accidentally exposes the env-var report.
        'internal_env_check_token' => env('INTERNAL_ENV_CHECK_TOKEN'),
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#SEC-2** · P2 — Stripe API version pinned to two different defaults across config files; if `STRIPE_API_VERSION` is unset in `.env`, money-movement code and export code use incompatible API versions
    - **Where:** config/services.php:75 and config/partna.php (exports.commission.stripe_api_version)
    - **Affects:** All Stripe API calls — webhook processing, Connect onboarding, commission payouts, transaction exports. The export pipeline specifically would use `2025-02-24.acacia` while core Stripe SDK calls use `2026-02-25.clover`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Set `STRIPE_API_VERSION` in `.env.example` and production `.env` to one canonical value (preferably `2026-02-25.clover` since it's the newer API).
        - Align the fallback default in `config/partna.php` exports section to match `config/services.php` stripe section, or — better — remove the duplicate default and have the export config read from `config('services.stripe.api_version')` directly.
    - **Technical:** `config/services.php` sets `stripe.api_version` default to `2026-02-25.clover`, which is what the Stripe SDK binding reads at boot. `config/partna.php` exports.commission.stripe_api_version defaults to `2025-02-24.acacia` — a full year older. If `STRIPE_API_VERSION` is missing from `.env` (common on fresh deploys), the export pipeline pins an older API version. Stripe API versions are immutable: a field available in `2026-02-25.clover` may be absent or differently shaped in `2025-02-24.acacia`, causing silent data mismatches or hard failures in payout calculations. The comment claims the export key is "Shared with the global Stripe SDK binding so the whole app pins one version" — the differing defaults contradict that claim.
    - **Plain English:** Think of the Stripe API version like the edition of a legal contract. Two different parts of your app are signing two different editions of the contract. If the env variable that picks the edition isn't set, the core app signs the 2026 edition while the export pipeline signs the 2025 edition. Most of the time the clauses match, but when they don't, you get unexpected results — wrong payout amounts, missing fields — and it's extremely hard to debug because everything looks fine until one specific field goes missing.
    - **Evidence:**
        ```php
        // config/services.php
        'stripe' => [
            'api_version' => env('STRIPE_API_VERSION', '2026-02-25.clover'),
        ],

        // config/partna.php — exports section
        'stripe_api_version' => env('STRIPE_API_VERSION', '2025-02-24.acacia'),
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SEC-3** · P2 — Hydrogen GitHub PAT (`actions:write` scope) stored in runtime-accessible config, reachable via `config('partna.hydrogen.github_token')` from any code path
    - **Where:** config/partna.php (hydrogen.github_token)
    - **Affects:** The `sidest-storefront` GitHub repository — a leaked token gives an attacker `actions:write` (trigger workflows, modify CI, potentially exfiltrate secrets embedded in workflow runs).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Audit all `Log::*`, `dd()`, `dump()`, exception handlers, and Nightwatch payloads to confirm none emit `config('partna.hydrogen.github_token')` or the full `config('partna.hydrogen')` array.
        - Add `hydrogen.github_token` (and ideally the whole `partna.hydrogen` key) to Nightwatch's `redact_payload_fields` in `config/nightwatch.php` as defence-in-depth.
        - Consider moving the token out of `config/` and into a dedicated `GitHubService` that reads `env()` directly at call time and never stores it in a global config array accessible to debug tooling.
    - **Technical:** Laravel's `config()` helper makes every value in `config/partna.php` globally accessible. Any code that logs request context, dumps config for debugging, or serialises config values in error payloads could inadvertently include this token. A GitHub PAT with `actions:write` can trigger workflows, modify repository dispatch inputs, and potentially inspect workflow-run logs that contain other secrets. The token lives alongside user-facing config (link block settings, social platforms) in the same file, making it easy to overlook during a broad `Log::debug('config', config('partna'))` call.
    - **Plain English:** You've put the key to your factory inside a publicly-accessible filing cabinet drawer labelled "miscellaneous settings." Anyone with access to the filing cabinet — including the maintenance crew who logs what's in each drawer for inventory — can see the key. The key doesn't just open the door; it lets someone reprogram the factory machines. The fix is to keep the key in a locked safe that only the machine operator can open, not in a shared drawer anyone can peek into.
    - **Evidence:**
        ```php
        // config/partna.php
        'hydrogen' => [
            // GitHub PAT with actions:write scope on the sidest-storefront repo.
            // Used by HydrogenDeploymentService to trigger single-brand Oxygen
            // deployments when a brand saves credentials in the wizard.
            'github_token' => env('PARTNA_HYDROGEN_GITHUB_TOKEN', env('SIDEST_HYDROGEN_GITHUB_TOKEN')),
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#SEC-4** · P3 — Placeholder PII in account-type defaults seeds new professional profiles with test contact data showing a real-looking email and phone number
    - **Where:** config/partna.php (account_type_defaults.influencer, individual, partner)
    - **Affects:** Every newly registered professional, influencer, individual, or partner account that hasn't yet customised their contact section — their public site displays "Charlie" with email `charlie@ai.com` and phone `1234 567 890` until they edit it.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the hardcoded values with empty strings or `null`, and have the frontend render a "set your contact info" prompt for the contact section block until the professional fills it in.
        - If the placeholder is needed for visual preview during onboarding, use clearly synthetic values (`'Your Name'`, `'you@example.com'`, `'+61 0000 0000'`) that cannot be confused with real PII.
    - **Technical:** The `default_contact` arrays in `account_type_defaults` for `influencer`, `individual`, and `partner` each contain `'full_name' => 'Charlie'`, `'email' => 'charlie@ai.com'`, `'phone' => '1234 567 890'`. These are written into new professionals' contact section blocks on registration. Until the professional edits their site, these values render on their public-facing mini-site. While these look like test data, `charlie@ai.com` could be a real inbox, and publishing it on public profiles creates both a PII exposure and a spam magnet for whoever owns that address. The `source => 'system_default'` field suggests awareness that these are defaults, but that doesn't prevent them from being served publicly.
    - **Plain English:** When a new user signs up, we pre-fill their public contact card with "Charlie" at a real-looking email address. Think of it like printing business cards for every new customer with someone else's name and phone number on them — until the customer notices and swaps the card out, everyone who picks it up is calling Charlie. Even if Charlie is a test account, we're putting their details on every new user's public page. Replace it with blank fields or obviously fake placeholders.
    - **Evidence:**
        ```php
        'influencer' => [
            'default_contact' => [
                'full_name' => 'Charlie',
                'email' => 'charlie@ai.com',
                'phone' => '1234 567 890',
                'source' => 'system_default',
                'subscribed' => true,
            ],
        ],
        // Repeated identically in 'individual' and 'partner' defaults
        ```
    - `[DRAFT, confidence: 0.7]`

- [ ] **#SEC-5** · P3 — CORS `paths` include `sanctum/csrf-cookie` alongside wildcard `allowed_origins: *`; the cookie endpoint is non-functional cross-origin but the configuration doesn't document this constraint explicitly
    - **Where:** config/cors.php:2,8
    - **Affects:** Any future developer who assumes `sanctum/csrf-cookie` works cross-origin — it silently fails because `supports_credentials: false` prevents browsers from including cookies on wildcard-origin requests. If the app later adds a cookie-based auth path under `api/*`, the wildcard origin would need to be locked down.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a comment in `config/cors.php` noting that `sanctum/csrf-cookie` is included in paths for local/SSR use but is non-functional cross-origin due to `supports_credentials: false`.
        - If Sanctum CSRF is genuinely unused (likely, given Supabase JWT auth), remove `sanctum/csrf-cookie` from the paths array to eliminate the ambiguity.
        - Document in the file that if `supports_credentials` is ever changed to `true`, `allowed_origins` must be locked to an explicit allow-list (as the existing comment already partially covers).
    - **Technical:** Browsers enforce a hard rule: when `Access-Control-Allow-Origin: *` is sent, the response cannot include `Access-Control-Allow-Credentials: true`, and cookies/HTTP-auth are never sent cross-origin. The current config sets `supports_credentials: false`, so the wildcard origin is safe for the Bearer-token API. However, `sanctum/csrf-cookie` is listed in `paths` — this is a cookie-setting endpoint. It will only work same-origin (where CORS doesn't apply) or from SSR/localhost. Listing it in the CORS paths alongside a wildcard origin is not a vulnerability but creates a foot-gun: a developer seeing Sanctum in the paths might assume cookie auth works cross-origin and build a feature on that assumption.
    - **Plain English:** We've got a sign on the door that says "everyone welcome" (wildcard origins) and another sign that says "please show ID at the cookie counter" (Sanctum CSRF path). The door policy explicitly says "no ID checks at this door" (no credentials), so the cookie counter is effectively closed to anyone coming through that door. That's fine right now because everyone uses the keycard lane (Bearer token). But a future builder might see the cookie counter sign, assume it works, and build a whole new entrance that relies on it — only to find out it was never open. The fix is a sticky note on the sign: "Cookie counter is for local traffic only — do not build cross-origin features that depend on it."
    - **Evidence:**
        ```php
        'paths' => ['api/*', 'sanctum/csrf-cookie'],
        'allowed_origins' => ['*'],
        'supports_credentials' => false,
        ```
    - `[DRAFT, confidence: 0.6]`

<!-- ═══ SUB-CHUNK: s2 (routes .env.example) ═══ -->

- [ ] **SEC-1** · P0 — Shopify webhooks rely entirely on per-controller HMAC verification with no middleware-level enforcement
    - **Where:** routes/api.php (Shopify webhook group)
    - **Affects:** All 10+ Shopify webhook endpoints — orders, refunds, app-uninstall, GDPR, themes, shop-update. A single controller missing HMAC verification = wide-open webhook.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a `shopify.webhook` middleware that verifies `x-shopify-hmac-sha256` using `hash_equals` before the controller executes.
        - Alternatively, add a `ShopifyWebhookRequest` Form Request with an `authorize()` method that performs HMAC verification, and apply it to every webhook route definition.
        - Audit every Shopify webhook controller to confirm individual HMAC checks exist, as an interim safeguard.
    - **Technical:** Category 4 — Webhook signature verification. All Shopify webhooks sit under a single `throttle:webhooks` group with the comment "no auth middleware — signature validated in controller." Ten distinct controllers handle these routes. Under Laravel's middleware architecture, a single forgotten `abort_unless($this->hmacValid($request), 401)` in any one of them means that webhook path accepts unverified payloads — orders can be fabricated, app-uninstall can be spoofed, GDPR requests can be injected. Middleware-level enforcement is defense-in-depth: it guarantees no code path reaches the controller without a valid signature. The Stripe webhooks in the same group use `Webhook::constructEvent()` which is similarly per-controller, but Stripe ships a signed-request helper that hard-fails — Shopify's HMAC is a hand-rolled check with no framework guardrail.
    - **Plain English:** Imagine a warehouse with ten delivery doors. Each door has its own guard who's supposed to check IDs. If one guard calls in sick and there's no backup check at the gate, that door is wide open. All ten Shopify webhook doors rely on the guard inside each room to check the signature. A single missed check means fake orders, fake uninstalls, or fake GDPR deletion requests can walk right in.
    - **Evidence:**
        ```php
        // Webhooks (no auth middleware — signature validated in controller)
        Route::middleware('throttle:webhooks')->group(function () {
            // ... Stripe, Square, Supabase webhooks ...
            Route::post('/webhooks/shopify/orders', ShopifyOrderWebhookController::class)
                ->middleware('throttle:shopify-webhooks');
            Route::post('/webhooks/shopify/orders-paid', ShopifyOrderWebhookController::class)
                ->middleware('throttle:shopify-webhooks');
            Route::post('/webhooks/shopify/orders-updated', ShopifyOrdersUpdatedWebhookController::class)
                ->middleware('throttle:shopify-webhooks');
            Route::post('/webhooks/shopify/orders-edited', ShopifyOrdersEditedWebhookController::class)
                ->middleware('throttle:shopify-webhooks');
            Route::post('/webhooks/shopify/orders-cancelled', ShopifyOrdersCancelledWebhookController::class)
                ->middleware('throttle:shopify-webhooks');
            Route::post('/webhooks/shopify/refunds-create', ShopifyRefundsCreateWebhookController::class)
                ->middleware('throttle:shopify-webhooks');
            Route::post('/webhooks/shopify/app-uninstalled', ShopifyAppUninstalledWebhookController::class)
                ->middleware('throttle:shopify-webhooks');
            Route::post('/webhooks/shopify/shop-update', ShopifyShopUpdateWebhookController::class)
                ->middleware('throttle:shopify-webhooks');
            Route::post('/webhooks/shopify/themes-publish', ShopifyThemePublishedWebhookController::class)
                ->middleware('throttle:shopify-webhooks');
            Route::post('/webhooks/shopify/gdpr/customers-data-request', [ShopifyGdprWebhookController::class, 'customersDataRequest'])
                ->middleware('throttle:shopify-webhooks');
            Route::post('/webhooks/shopify/gdpr/customers-redact', [ShopifyGdprWebhookController::class, 'customersRedact'])
                ->middleware('throttle:shopify-webhooks');
            Route::post('/webhooks/shopify/gdpr/shop-redact', [ShopifyGdprWebhookController::class, 'shopRedact'])
                ->middleware('throttle:shopify-webhooks');
        });
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **SEC-2** · P1 — Hydrogen affiliate data endpoint is intentionally unauthenticated, relying solely on controller-level link verification
    - **Where:** routes/api.php (line comment before the route)
    - **Affects:** Affiliate services/products data exposed at `/internal/hydrogen/affiliate`. If the controller's `BrandPartnerLink` check is bypassed or has an edge case, an unauthenticated caller enumerates affiliate product selections and pricing.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Audit `HydrogenAffiliateController::show()` to confirm the 404-on-no-verified-link gate is watertight for all input combinations (missing `shop_domain`, invalid `slug`, deleted brand, disconnected partnership).
        - Consider adding a lightweight shared-secret HMAC or short-lived signed token to this endpoint. The comment justifies omitting `hydrogen.key` to avoid server load, but a signed URL with a 60s TTL eliminates the tenant-boundary risk entirely at negligible cost.
    - **Technical:** Category 1 — Authentication boundary correctness. The route is explicitly declared "INTENTIONALLY UNAUTHENTICATED" with the rationale that `HydrogenAffiliateController::show()` returns 404 when no verified `BrandPartnerLink` exists. This makes tenant isolation a controller-internal concern rather than a middleware-enforced one. The adjacent endpoints (`/affiliate-services`, `/affiliate-products`) are protected by `hydrogen.key` middleware — the asymmetry means `/affiliate` is the only Hydrogen endpoint where tenant resolution is decoupled from cryptographic identity. Any logic error in the link-verification path (e.g., a soft-deleted partnership that still resolves, a race condition on disconnect) would leak affiliate commerce data to an unauthenticated caller.
    - **Plain English:** There's a reception desk that's supposed to check visitor badges, but one entrance has a sign that says "no badge needed — the room inside will check if you're supposed to be there." If the person in that room makes a mistake, someone without a badge gets access to affiliate sales data. The fix is to put a badge check at the entrance, same as every other door.
    - **Evidence:**
        ```php
        // INTENTIONALLY UNAUTHENTICATED — enumeration mitigated by controller link verification.
        // HydrogenAffiliateController::show() enforces a 404 when no verified BrandPartnerLink
        // exists; unknown shop_domain or slug values never return affiliate data.
        // Accessory endpoints (services, products) remain behind hydrogen.key since they
        // add server load with no client-side initiator.
        Route::get('/internal/hydrogen/affiliate', [HydrogenAffiliateController::class, 'show'])
            ->middleware('throttle:hydrogen-internal');
        ```
    - `[DRAFT, confidence: 0.70]`

- [ ] **SEC-3** · P1 — Internal env-check diagnostic endpoint has no rate limiting
    - **Where:** routes/api.php (near end of file, before `/ready` routes)
    - **Affects:** The self-diagnostic endpoint at `GET /api/internal/env-check`. An attacker can brute-force the `X-Internal-Token` header or flood the endpoint to degrade service — it's the only internal endpoint with zero throttle middleware.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `->middleware('throttle:10,1')` or a named throttle like `throttle:env-check` to the route.
        - Confirm inside `EnvCheckController` that the shared-secret comparison uses `hash_equals` (timing-safe) and that no secret value or env-var output is logged.
    - **Technical:** Category 9 — Rate limiting on auth & sensitive endpoints. Every other internal/admin endpoint in the route file carries a throttle: `throttle:hydrogen-internal`, `throttle:embedded-by-shop`, `throttle:webhooks`, `throttle:60,1` on Shopify OAuth. The env-check route stands alone with no middleware at all. The controller gates access behind a single `X-Internal-Token` header — without rate limiting, that token is brute-forceable (even with a strong random value, an unthrottled endpoint allows high-speed guessing). Additionally, a simple connection-flood DoS against this endpoint consumes PHP-FPM workers since there is no request-per-minute cap.
    - **Plain English:** Every entrance to the building has a security guard counting how many people come through. This one diagnostic entrance has no guard at all — just a keypad. Someone can stand there trying combinations as fast as the keypad lets them, and nobody's watching.
    - **Evidence:**
        ```php
        // Self-diagnostic env-var check. Independent of every other auth subsystem on
        // purpose — this is the endpoint you hit when something else is misconfigured.
        // Auth is a single shared-secret header inside the controller.
        Route::get('/internal/env-check', EnvCheckController::class);
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **SEC-4** · P2 — `productGid` route parameter regex `.*` accepts arbitrary strings instead of constraining to valid Shopify GIDs
    - **Where:** routes/api/professional.php (multiple brand catalog routes: `updateMetafields`, `toggleActive`, `updateCommission`, `updateDiscount`; affiliate product photo routes)
    - **Affects:** Brand catalog write endpoints and affiliate product photo routes. Overly permissive parameter matching allows malformed or malicious GID strings to reach the controller and potentially downstream Shopify API calls.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `->where('productGid', '.*')` with a constrained regex like `->where('productGid', 'gid://shopify/Product/[0-9]+')` matching the pattern used in `routes/api/staff.php` for the same parameter.
        - Apply the same constraint to the `gid` parameter on affiliate product routes in `professional.php`.
    - **Technical:** Category 6 — Input validation & injection. The parameter `{productGid}` appears on multiple brand-catalog mutation endpoints and is passed through to Shopify Admin API calls. A regex of `.*` matches any string including empty, injection payloads, or characters that could cause unexpected behavior in downstream HTTP clients (e.g., newlines in header injection, path traversal sequences). Notably, `routes/api/staff.php` already constrains the same parameter correctly for staff-side catalog routes: `->where('productGid', '.*')` on the staff write routes but `->where('gid', 'gid://shopify/Product/[0-9]+')` on the staff affiliate photo route — the inconsistency confirms the tighter pattern is both feasible and expected.
    - **Plain English:** The system accepts product IDs in a field that should look like `gid://shopify/Product/12345`, but the validation rule is set to "accept anything at all." It's like a parking garage that requires a ticket but the gate accepts any piece of paper — a gum wrapper works. The staff-facing side already has the correct gate; the professional-facing side needs the same one.
    - **Evidence:**
        ```php
        // From routes/api/professional.php — permissive regex
        Route::patch('/brand/catalog/{productGid}/metafields', [BrandCatalogController::class, 'updateMetafields'])
            ->middleware('throttle:brand-catalog-writes')
            ->where('productGid', '.*');
        Route::patch('/brand/catalog/{productGid}/active', [BrandCatalogController::class, 'toggleActive'])
            ->middleware('throttle:brand-catalog-writes')
            ->where('productGid', '.*');
        Route::patch('/brand/catalog/{productGid}/commission', [BrandCatalogController::class, 'updateCommission'])
            ->middleware('throttle:brand-catalog-writes')
            ->where('productGid', '.*');
        Route::patch('/brand/catalog/{productGid}/discount', [BrandCatalogController::class, 'updateDiscount'])
            ->middleware('throttle:brand-catalog-writes')
            ->where('productGid', '.*');
        ```
        ```php
        // From routes/api/staff.php — constrained regex for the same concept
        Route::get('/professionals/{professional}/affiliate/products/{gid}/photos', [...], 'index'])
            ->where('gid', 'gid://shopify/Product/[0-9]+');
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **SEC-5** · P2 — Staff read-only mirror routes expose PII (customer data, email subscribers, enquiries) to any staff member without role-scoped filtering
    - **Where:** routes/api/staff.php (B2 read-only inspector bundle: `StaffEmailSubscriberController`, `StaffEnquiryController`, `StaffCustomerManagementController`, `StaffBookingController`, multiple analytics/catalog mirrors)
    - **Affects:** Customer PII — email addresses, phone numbers, enquiry messages, purchase history — is visible to every authenticated staff member. There is no apparent tiering (e.g., "support can see metadata but only admin can see full PII").
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Define a staff role hierarchy with granular permissions (e.g., `support:read_pii`, `admin:full_access`).
        - Add PII-redacted Resource classes for non-admin staff roles — return last-4 of phone, masked email, enquiry body trimmed to first 80 chars unless the staff member has elevated permissions.
        - Audit what `StaffMeController::show()` returns about the staff member's own permissions so the frontend can hide PII-exposing UI components.
    - **Technical:** Category 10 — PII exposure in responses & logs. The staff route file defines a two-tier system: regular `staff` (read-only mirrors) and `staff.admin` (write operations). The B2 read-only inspector bundle — documented as "any-staff read, no admin gate" — mounts ~15 controllers that mirror brand-facing data, including `StaffEmailSubscriberController::export()` (full CSV of subscriber emails), `StaffEnquiryController::index()` (contact-form messages), and `StaffCustomerManagementController::index/show()` (customer records). None of these routes have additional middleware that distinguishes between "support agent helping with a billing question" and "staff member browsing customer data." Under GDPR, access to PII must be proportionate and auditable — a flat any-staff-read posture is disproportionate for a support agent who only needs to verify subscription status.
    - **Plain English:** Every staff member — whether they're debugging a CSS issue or handling a GDPR deletion request — can see every customer's email, phone number, enquiry messages, and purchase history. It's like giving every hotel employee a master key to every guest room, including the front-desk trainee on their first day. Support staff should have access to what they need for their specific job, not the entire guest roster.
    - **Evidence:**
        ```php
        // #GDPR-1 — email subscribers list + CSV export. Compliance: Article 15/20
        // requests routed to Partna support need a way to answer without the brand.
        Route::get('/professionals/{professional}/email-subscribers', [StaffEmailSubscriberController::class, 'index']);
        Route::get('/professionals/{professional}/email-subscribers/export', [StaffEmailSubscriberController::class, 'export']);

        // #ENQUIRY-1 — contact-form enquiries inbox (read).
        Route::get('/professionals/{professional}/enquiries', [StaffEnquiryController::class, 'index']);
        ```
        ```php
        // B2: Read-only inspector mirrors — any-staff read, no admin gate
        Route::get('/professionals/{professional}/customers', [StaffCustomerManagementController::class, 'index']);
        Route::get('/professionals/{professional}/customers/{customer}', [StaffCustomerManagementController::class, 'show'])
            ->whereUuid('customer');
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **SEC-6** · P2 — `shopify_order_id` route parameter regex permits special characters (`/`, `.`, `:`) that could cause path traversal or injection in downstream API calls
    - **Where:** routes/api.php (embedded order analytics route)
    - **Affects:** The embedded order analytics endpoint `GET /internal/embedded/orders/{shopify_order_id}`. If the controller interpolates this parameter directly into a Shopify Admin API URL or a filesystem path, the permissive regex allows injection.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Tighten the regex to `[0-9]+` if Shopify order IDs are purely numeric, or to a known format like `gid://shopify/Order/[0-9]+` if GIDs are used.
        - Regardless of regex, validate the parameter against a known format inside the controller before any outbound HTTP call or DB query.
    - **Technical:** Category 6 — Input validation & injection. The route definition uses `->where('shopify_order_id', '[A-Za-z0-9_/.:-]+')`. Characters like `/` and `..` in a URL path parameter can enable path traversal if the value is concatenated into a REST API URL (e.g., `https://{shop}.myshopify.com/admin/api/{version}/orders/{shopify_order_id}.json` where `shopify_order_id` = `../../products`). The same applies to `shopify_product_id` on the product analytics route. Shopify's own GID format is `gid://shopify/Order/123456` but some endpoints accept numeric IDs — the regex should match only the expected format, not a superset that includes traversal characters.
    - **Plain English:** The system asks for an order number but accepts slashes, dots, and colons in the answer. If someone types `../../products` instead of a real order number, and the system blindly stitches that into a web address, they could be sent somewhere they shouldn't go. The fix is to only accept digits — because that's what a real order number looks like.
    - **Evidence:**
        ```php
        Route::get('/orders/{shopify_order_id}', [EmbeddedOrderAnalyticsController::class, 'show'])
            ->where('shopify_order_id', '[A-Za-z0-9_/.:-]+');
        Route::get('/products/{shopify_product_id}/analytics', [EmbeddedProductAnalyticsController::class, 'show'])
            ->where('shopify_product_id', '[A-Za-z0-9_/.:-]+');
        ```
    - `[DRAFT, confidence: 0.75]`

- [ ] **SEC-7** · P3 — `.env.example` comment recommends a "Full access" Resend API key when a "Sending access" scoped key is sufficient
    - **Where:** .env.example (RESEND_API_KEY comment block)
    - **Affects:** Principle of least privilege for the Resend mail API integration. A full-access key can manage domains, API keys, and audience contacts — none of which the Laravel mailer needs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Update the comment to recommend a "Sending access" API key instead of "Full access."
        - If bounce/complaint webhooks are needed later, document that those require a separate webhook configuration in Resend, not a broader API key scope.
    - **Technical:** Category 5 — Secrets handling & log hygiene. The `.env.example` guidance reads: "Use a 'Full access' key so bounce/complaint webhooks can be wired up later." Resend supports scoped API keys: "Sending access" permits only the `send` endpoint, which is all Laravel's `resend` mail transport needs. "Full access" additionally permits domain management, API key rotation, and audience operations. If the key were compromised, a scoped key limits blast radius to sending email; a full-access key allows an attacker to delete the verified domain, rotate keys, or export contact lists. Bounce/complaint webhooks in Resend are configured per-domain in the dashboard and authenticated via a separate webhook signing secret — they do not require a full-access API key.
    - **Plain English:** The setup instructions tell developers to use a master key that opens every door in the building, when a key that only opens the mailroom door would work fine. If someone steals the master key, they can change the locks on every door — not just read the mail.
    - **Evidence:**
        ```
        # Resend HTTP API key — required when MAIL_MAILER=resend. Get from
        # https://resend.com/api-keys. Use a "Full access" key so bounce/complaint
        # webhooks can be wired up later.
        RESEND_API_KEY=
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **SEC-8** · P3 — Public email subscription endpoint has no CAPTCHA protection, unlike other lead-capture endpoints
    - **Where:** routes/api.php and routes/api/publicSite.php (subscribe routes)
    - **Affects:** `POST /api/public/subscribe` and `POST /api/public/{subdomain}/subscribe` — both accept email subscriptions with only `throttle:public-site` as protection. Other lead-capture endpoints (`/public/customers`, `/public/enquiry`, `/public/waitlist`) carry `captcha` middleware.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `captcha` middleware to both subscribe routes so they match the protection level of the customer-lead and enquiry endpoints.
        - Ensure the `PARTNA_CAPTCHA_ENABLED` feature flag gates the middleware behavior consistently.
    - **Technical:** Category 9 — Rate limiting on sensitive endpoints. The subscribe endpoint accepts an email address and creates/updates an `EmailSubscription` record. Without CAPTCHA, a script can programmatically subscribe thousands of addresses — inflating the subscriber list, triggering welcome emails, and degrading the brand's email reputation. The `throttle:public-site` rate limit slows this but doesn't prevent it at scale (a distributed botnet can stay under per-IP limits). The customer-lead, enquiry, and waitlist endpoints all carry `captcha` middleware — the subscribe route is an unexplained gap in the same public surface.
    - **Plain English:** The public signup form for "email me updates" has no bot check, but the "contact us" form on the same site does. A spammer can write a script that subscribes ten thousand fake emails to the newsletter. The other forms are locked — this one was left unlocked.
    - **Evidence:**
        ```php
        // No captcha middleware
        Route::post('/public/subscribe', [PublicEmailSubscriptionController::class, 'subscribe'])
            ->middleware('throttle:public-site');
        ```
        ```php
        // Captcha present on peer endpoints
        Route::post('/public/customers', [PublicCustomerLeadController::class, 'store'])
            ->middleware(['lead.log', 'throttle:leads', 'captcha']);
        Route::post('/public/enquiry', [PublicEnquiryController::class, 'submit'])
            ->middleware(['lead.log', 'throttle:leads', 'captcha']);
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **SEC-9** · P3 — Brand catalog debug endpoint exposes raw Shopify API response without apparent sanitization or scoping restrictions
    - **Where:** routes/api/professional.php (brand catalog debug route)
    - **Affects:** `GET /brand/catalog/debug` — returns raw Shopify responses including shop info, product samples, cost data, errors, and granted OAuth scopes. Available to any authenticated brand professional.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Confirm the controller strips sensitive fields (cost data, scopes, access token metadata) before returning the debug payload.
        - If this endpoint is genuinely temporary, add a hard deprecation deadline in the route comment and a `trigger_error` in the controller after that date.
        - Consider gating behind a staff-only route or a feature flag that defaults to off.
    - **Technical:** Category 10 — PII exposure in responses & logs. The route comment describes this as a "temporary diagnostic probe" that returns "raw Shopify response for a minimal products query." It is auth-gated (behind `supabase.jwt` + `brand.only`) so tenant isolation is enforced, but the response includes "shop info, products sample, cost, errors, granted scopes" — data points Shopify considers sensitive. Exposing OAuth scopes tells an attacker exactly which API surfaces are available. Exposing raw cost data may violate Shopify's API terms. The comment says it is "safe to leave in place" but this was written during development — pre-launch is the right time to remove or harden it.
    - **Plain English:** There's a "debug mode" button in the dashboard that shows the brand owner everything the system knows about their Shopify store — including what permissions the app has and what the raw product data looks like. The note says "this is temporary, safe to leave." Before real brands use the system, that button should either be removed or locked behind a staff-only key.
    - **Evidence:**
        ```php
        // Temporary diagnostic probe — returns raw Shopify response for a
        // minimal products query so we can see exactly what Shopify returns
        // (shop info, products sample, cost, errors, granted scopes). Safe
        // to leave in place; auth-gated, read-only, no mutations.
        Route::get('/brand/catalog/debug', [BrandCatalogController::class, 'debug']);
        ```
    - `[DRAFT, confidence: 0.80]`
