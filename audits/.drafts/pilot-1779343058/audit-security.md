`★ Insight ─────────────────────────────────────`
Three patterns recur across all 10 chunks: (1) SSRF/tenant-isolation risks where mutable JSONB settings fields are used as trust anchors without a DB relationship check, (2) PII leaking into Laravel queue payloads because constructor-injected strings are serialized by default, and (3) DeepSeek systematically over-tiers "doctrine violation" findings (inline `abort_unless`, `isBrand()` checks) to P1/P2 when they should be P3 — the routes are already protected by `brand.only`/`affiliate.only` middleware.
`─────────────────────────────────────────────────`

# Security Audit — 2026-05-21

**Branch:** development
**Lens:** Whole-backend PILOT audit — security lens
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `app/`
- `supabase/migrations/`

## Progress

- P0 Blockers: 0 of 1 complete
- P1 High: 0 of 4 complete
- P2 Medium: 0 of 9 complete
- P3 Low: 0 of 7 complete

---

## P0 — Must fix before any real user touches the system

- [ ] **#SEC-1** · P0 — Cross-tenant PII exposed in public affiliate payload via missing relationship check
    - **Where:** `app/Services/Cache/SiteCacheService.php` — `enrichSiteWithBrandPartnerRadius()` / `resolveBrandPartnerEnrichmentData()`
    - **Affects:** Every public site visitor of an affiliate page. An affiliate can embed any professional's UUID into their own `settings.brand_partner.professional_id` JSONB field and have that person's `first_name`, `last_name`, `handle`, and design tokens served in their public payload — even if no brand-partner relationship exists.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `BrandPartnerLink::where('affiliate_professional_id', $currentSiteProfessionalId)->where('brand_professional_id', $professionalId)->exists()` check at the top of `enrichSiteWithBrandPartnerRadius()` — or inline before the `resolveBrandPartnerEnrichmentData()` call.
        - On failed verification return `$site` unchanged and emit `Log::warning` with both IDs. Do not fall through to fetch the foreign professional's data.
        - The correct professional ID of the site owner must be threaded in from the calling context (`safeHydrateSitePayload` already has `$professionalId` in scope).
    - **Technical:** `enrichSiteWithBrandPartnerRadius()` reads `$brandPartner['professional_id']` from the affiliate's mutable `site.settings` JSONB and passes it directly to `resolveBrandPartnerEnrichmentData()`, which queries `Site` and `Professional` for that ID and returns `handle`, `first_name`, `last_name`, border radius, and font URL — all merged into the public payload. The only guard is `is_string($professionalId) && trim($professionalId) !== ''`, which any valid UUID passes. Compare with `applyBrandImageFallbacks()` in the same class, which performs an identical enrichment but first calls `BrandPartnerLink::where('affiliate_professional_id', ...)->where('brand_professional_id', ...)->first()` and bails with a warning if the link is absent. The two methods serve symmetric purposes but only one checks relationship consent — a textbook copy-paste divergence that creates a tenant-boundary bypass on the public site path.
    - **Plain English:** An affiliate's public page pulls in design details from their linked brand. In one code path the system checks "are these two actually linked?" before showing the brand's data. In a parallel path it skips that check entirely — it just uses whatever ID the affiliate has saved in their settings. Any affiliate can type any other professional's ID into their settings and that person's name and design will appear publicly on the affiliate's page, with no relationship between them at all.
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
        // resolveBrandPartnerEnrichmentData — queries another professional's PII with no auth check:
        $partnerProfessional = Professional::query()
            ->whereKey($professionalId)
            ->first(['handle', 'first_name', 'last_name']);
        ```
        ```php
        // applyBrandImageFallbacks — DOES verify the link first (correct pattern):
        $link = BrandPartnerLink::where('affiliate_professional_id', $affiliateId)
            ->where('brand_professional_id', $claimedBrandId)
            ->first();
        if (! $link) {
            Log::warning('Brand-partner enrichment skipped: no verified link in consent table.', [...]);
            return $payload;
        }
        ```

---

## P1 — Fix before pilot launch

- [ ] **#SEC-2** · P1 — Unvalidated shop domain enables SSRF and Shopify access token leakage in affiliate catalog queries
    - **Where:** `app/Services/Store/AffiliateProductCatalogService.php` — `queryAdminCatalog()` (~line 399)
    - **Affects:** Every affiliate-triggered product catalog read. A corrupted or attacker-written `shop_domain` in `provider_metadata` would send a valid Shopify Admin access token to an arbitrary host.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Validate `$shopDomain` with `ShopDomain::fromUntrusted($shopDomain)` (the boundary guard used by every other Shopify service in the codebase) before constructing the URL.
        - Replace the raw `Http::post()` call with `$this->client->graphql()` (inject `ShopifyAdminClient`) so rate-limit budget tracking and cost observability are not bypassed on the highest-volume read path in the system.
    - **Technical:** `queryAdminCatalog()` extracts `shop_domain` from `provider_metadata`, checks only for empty string, then interpolates it directly into `"https://{$shopDomain}/admin/api/..."` and sends `X-Shopify-Access-Token: $accessToken`. Every other Shopify service (including `BrandCatalogService` serving the brand-admin catalog) routes through `ShopifyAdminClient::graphql()`, which internally calls `ShopDomain::fromUntrusted()` to validate the `*.myshopify.com` pattern. A stored domain of `evil.com` would receive a live, revocable Shopify Admin API token. Additionally, bypassing `ShopifyAdminClient` removes rate-limit protection and cost reconciliation from the affiliate catalog path — likely the highest-volume Shopify read in the system.
    - **Plain English:** Every door in the building has a guard who checks that you're actually from a Shopify store before handing out the key. This one side entrance just asks "is there an address written here?" and hands the key to whatever address is written — even if it says a completely different company. If someone edits the stored address to point somewhere malicious, the key gets handed over. The fix is to put the same guard at this entrance that every other door already has.
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

- [ ] **#SEC-3** · P1 — Internal env-check endpoint has no rate limiting and exposes all credentials on a single token compromise
    - **Where:** `routes/api.php` (line `Route::get('/internal/env-check', EnvCheckController::class);`) and `config/partna.php:4-9`
    - **Affects:** All third-party service credentials (Stripe, Shopify, Cloudflare, Supabase, Hydrogen, Resend, Twitch, Square, Fresha, Turnstile, Postmark, Google Maps) — every secret accessible via `env()`. The unthrottled route also allows brute-forcing the shared `INTERNAL_ENV_CHECK_TOKEN` header.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `->middleware('throttle:10,1')` to the route immediately. This is the only internal endpoint in the file with zero throttle middleware.
        - Confirm `EnvCheckController` uses `hash_equals` for token comparison (timing-safe). If it uses `===`, replace it.
        - For a pre-pilot hardening pass: replace the HTTP endpoint with an Artisan command (`php artisan env:check`) that outputs to the server terminal, never over HTTP. If the HTTP endpoint is retained, ensure it returns only key names and redacted values — not the actual secrets.
    - **Technical:** Every other internal/admin endpoint in `routes/api.php` carries a named throttle (`hydrogen-internal`, `embedded-by-shop`, `webhooks`, `60,1` on OAuth). The env-check route stands alone. The auth model is a single shared-secret header (`INTERNAL_ENV_CHECK_TOKEN`). Without rate limiting, that token is brute-forceable at wire speed. The blast radius if the token leaks (commit, Slack paste, Nightwatch log line) is the full credential set for every integrated vendor. The fail-closed default (503 when `INTERNAL_ENV_CHECK_TOKEN` is unset) is correct hygiene but doesn't reduce the danger when the token is set.
    - **Plain English:** Imagine a master safe in the server room that, when opened with a single combination, displays every password and API key in the building. The combination is stored in a config file, and the door to the safe has no lock counter — you can try combinations as fast as you can type. Every other door in the building limits you to a handful of attempts per minute. This door needs the same limiter, and ideally the safe itself should be locked away inside a terminal that only the server operator can access.
    - **Evidence:**
        ```php
        // routes/api.php — no middleware on this route:
        Route::get('/internal/env-check', EnvCheckController::class);

        // config/partna.php — single shared-secret token:
        'internal_env_check_token' => env('INTERNAL_ENV_CHECK_TOKEN'),
        ```

- [ ] **#SEC-4** · P1 — Unauthenticated email, phone, and handle enumeration on signup-availability endpoint
    - **Where:** `app/Http/Controllers/Api/PublicSite/PublicSignupAvailabilityController.php:28-60`
    - **Affects:** Privacy of all registered users. Any unauthenticated visitor can probe whether any email address, phone number, or handle is registered — with no rate limiting or anti-automation challenge.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - For **email and phone**: Replace the boolean `exists` field in the response with a uniform "check submitted" or omit the field entirely. If real-time availability feedback is a product requirement, confirm via out-of-band channel (e.g., send a code to the address) rather than exposing registration status directly.
        - For **handles**: Handle availability is legitimately needed for the signup UX. Apply a `captcha` middleware to the route and log probe attempts that check more than 10 distinct handles per IP per minute for security monitoring.
        - Rate-limit the endpoint with a named throttle (e.g., `throttle:availability`) regardless of the above.
    - **Technical:** The endpoint returns `{email: {available: false, exists: true}, phone: {available: false, exists: true}, handle_lc: {available: false, exists: true}}` on every request with no authentication and no throttle beyond standard middleware. An attacker can POST a list of known email addresses and harvest a complete "is registered" map. Under GDPR/Australian Privacy Act, exposing account existence without consent is a PII disclosure. The handle availability check is benign (handles are public by design) and can remain — but the email/phone checks should not directly confirm registration status.
    - **Plain English:** This is like a membership call centre where any stranger can ask "Does Joe Smith have an account here?" and get an immediate yes or no. A script can ask about thousands of email addresses per minute and build a complete list of who uses the service. The fix is to stop confirming whether an email or phone is registered — if someone's trying to create an account, handle the case where it's already taken during the actual signup step.
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

- [ ] **#SEC-5** · P1 — Customer plaintext email serialized into Redis job payload and written to logs on failure
    - **Where:** `app/Jobs/Notifications/SyncCustomerMarketingOptInJob.php:37-41, 88-95`
    - **Affects:** Customer privacy. Every `EmailSubscription` save dispatches this job with the customer's email as a public constructor property, serializing it into the Redis `jobs` payload and writing it to the application log on any failure.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `public string $email` in the constructor with `public string $customerId` and resolve the email inside `handle()` via `Customer::find($this->customerId)->email`.
        - Remove `'email' => $this->email` from the `failed()` log context — `professional_id` and `subscribed` are sufficient for incident response.
    - **Technical:** Laravel serializes every public property on a queued job into the `payload` column (Redis key for Horizon). That value is visible to any operator with Redis access and persists until the job processes. The `failed()` method also writes the email to the application log, where it lives for the log aggregator's retention period. Both paths violate the principle that PII should live only in the primary datastore under access control — not duplicated in operational systems. The pattern used by `SendEnquiryNotificationJob.php` — which stores an `enquiryId` and fetches the email inside `handle()` — is the correct model; its own `failed()` docblock even explicitly comments "log retention exceeds GDPR/Privacy Act expectations."
    - **Plain English:** Think of the queue system like a postal sorting room. Right now, every package that passes through this room has the customer's email address printed on the outside where any mail sorter can read it. It should be inside the envelope, referenced only by a tracking number. The system already knows the right way to do this — another job in the same folder uses a tracking number and only opens the envelope when it's ready to act.
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
                'email' => $this->email,   // ← written to log aggregator on failure
                'subscribed' => $this->subscribed,
                'error' => $e->getMessage(),
            ]);
        }
        ```

---

## P2 — Should fix

- [ ] **#SEC-6** · P2 — Shopify access token prefix and length written to console output in diagnostic command
    - **Where:** `app/Console/Commands/Diagnostics/ShopifyTokenDiagnoseCommand.php:50-52`
    - **Affects:** Any operator who captures console output (CI/CD pipelines, log aggregators). The first 8 characters of a `shpat_` token are logged on every diagnostic run.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the `token len` and `token prefix` lines with a single boolean: `token present: yes/no`.
        - The `looks like shpat_?` check can remain but compute it without printing the prefix — store the result as a local boolean and print `yes` or `no`.
    - **Technical:** Shopify offline tokens are `shpat_` + 32 hex characters. The command already prints the first 8 characters (`shpat_XX`) and the full token length. While this alone doesn't allow brute-force, any log aggregator (Nightwatch, CI stdout) that retains this output provides a partial oracle for credential validation. An attacker with access to logs who also has an `shpat_` prefix can narrow their search space. The token comes from decrypting `ProfessionalIntegration::access_token` — a live credential with full Shopify Admin API access for the brand's store.
    - **Plain English:** This diagnostic tool reads the brand's Shopify store key from the database and prints the first several characters plus the total length to the screen. It's like announcing the first few digits of a credit card number during a support call. If those logs ever end up in an aggregation tool that the wrong person can access, they now have a head start guessing the rest of the key.
    - **Evidence:**
        ```php
        $this->line('token len:      '.strlen($token));
        $this->line('token prefix:   '.substr($token, 0, 8).'...');
        $this->line('looks like shpat_? '.(str_starts_with($token, 'shpat_') ? 'yes' : 'NO'));
        ```

- [ ] **#SEC-7** · P2 — StaffUpdateSiteRequest does not strip HTML from public-facing text fields
    - **Where:** `app/Http/Requests/Api/Staff/ProfessionalSite/StaffUpdateSiteRequest.php` — no `prepareForValidation` sanitization for `hero_title`, `hero_subtitle`, `primary_button_text`, `bio_text`
    - **Affects:** Public site visitors of any brand whose site was edited by a staff account. A compromised staff account could inject script tags into site text that execute when customers visit the store.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `prepareForValidation()` loop over `hero_title`, `hero_subtitle`, `primary_button_text`, `bio_text` that applies `static::cleanString()` — mirroring the existing implementation in the professional-facing `UpdateSiteRequest`.
    - **Technical:** The professional `UpdateSiteRequest::prepareForValidation()` strips HTML tags and control characters from these four fields via `static::cleanString()`. The staff counterpart has a `prepareForValidation()` method for subdomain normalization but never touches the text content fields. Raw HTML is therefore stored verbatim in the site's JSONB settings. Staff accounts are trusted but a compromised credential could inject scripts across any brand's public site. The public site renderer should also escape on output, but defense-in-depth requires sanitization at write time.
    - **Plain English:** The professional settings form has a step that removes any hidden code from the title and bio fields before saving them. The staff version of the same form skips that step entirely. If a staff account gets taken over, an attacker could type script code into a brand's hero title and it would appear on that brand's public page and run in every visitor's browser. The fix is a one-method copy from the professional form.
    - **Evidence:**
        ```php
        // UpdateSiteRequest (professional) — sanitizes these fields:
        foreach (['hero_title', 'hero_subtitle', 'primary_button_text', 'bio_text'] as $field) {
            if (! array_key_exists($field, $settings) || ! is_string($settings[$field])) {
                continue;
            }
            $settings[$field] = static::cleanString($settings[$field]);
        }

        // StaffUpdateSiteRequest — prepareForValidation() handles only subdomain, not text fields:
        'settings.hero_title' => ['sometimes', 'string', 'max:100'],
        'settings.hero_subtitle' => ['sometimes', 'string', 'max:200'],
        // No cleanString() applied before these rules run.
        ```

- [ ] **#SEC-8** · P2 — BrandAffiliateController exposes affiliate personal email and phone to brand dashboard
    - **Where:** `app/Http/Controllers/Api/Professional/Brand/BrandAffiliateController.php:67-70`
    - **Affects:** Every affiliate connected to any brand. Their `primary_email`, `public_contact_email`, `phone`, and `public_contact_number` are returned in the brand's affiliate listing without a consent gate.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove `email` and `phone` from the response map. The affiliate's `handle` and display name are sufficient for the dashboard listing.
        - If contact details are genuinely required by product spec, add an explicit "share contact with brand" consent toggle on the affiliate side and gate the fields on that toggle.
    - **Technical:** The `index` method maps `BrandPartnerLink` rows to inline response arrays that include `primary_email ?? public_contact_email` and `phone ?? public_contact_number`. Under GDPR and the Australian Privacy Act, sharing a natural person's personal contact details with a third party (the brand) requires a lawful basis — typically consent or legitimate interest with documented justification. No consent check is performed. The fields are labeled "public_" in the model but were designed for the professional's own site display, not for sharing with brand operators as an unlabelled CRM export.
    - **Plain English:** When a brand opens their affiliate dashboard, they can see the personal phone number and email of every affiliate they work with. The affiliate may have set those up as their public website contact info — not expecting them to be handed to every brand they partner with. Before launch, either remove these fields or add a setting that lets affiliates choose to share their contact details with brand partners.
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

- [ ] **#SEC-9** · P2 — Full Supabase Admin API response body logged verbatim on auth-user deletion failure
    - **Where:** `app/Services/Professional/AccountDeletionService.php:335-339`
    - **Affects:** Log aggregator retention. Any system that retains Laravel log entries (Nightwatch, Papertrail, CloudWatch) will store the full GoTrue error response body indefinitely.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `'body' => $response->body()` with `'status' => $response->status()` only, or truncate: `'body' => substr($response->body(), 0, 200)`.
        - Add a code-review convention in CLAUDE.md: HTTP response bodies from auth/admin APIs must never be logged in full.
    - **Technical:** `deleteSupabaseAuthUser()` calls the Supabase GoTrue Admin API and logs `$response->body()` on any non-404 failure. Current GoTrue error responses are JSON error objects (`{"code":500,"msg":"Database error"}`). However, full-body logging from an auth service creates a persistent pattern that can leak tokens, session material, or PII if the upstream schema changes or if the pattern is copied to a more sensitive call site. Log aggregators retain entries beyond any DB retention policy.
    - **Plain English:** When deleting a user's login account fails, we write the entire raw error response from Supabase into the operations log. Today those responses are just error codes. But if Supabase ever changes what they include in an error — or someone copies this pattern to a different API call — customer data or session tokens could end up in log files for months. The fix is to log only the status code number, not the response body.
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

- [ ] **#SEC-10** · P2 — Professional notification email serialized into Redis job payload
    - **Where:** `app/Jobs/Notifications/SendEnquiryNotificationJob.php:32-38`
    - **Affects:** Professional privacy. The enquiry notification delivery address is written into the queue payload (Redis / Horizon) for every contact-form submission, where it persists until the job completes.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove `public string $notificationEmail` from the constructor. The `enquiryId` already present is sufficient to retrieve the email inside `handle()` from the authoritative datastore.
        - The `failed()` handler already correctly avoids logging the email (with an explicit comment) — extend that discipline to the constructor.
    - **Technical:** Same queue-serialization issue as SEC-5. The `failed()` handler exemplifies the correct pattern and even comments "log retention exceeds GDPR/Privacy Act expectations; enquiry_id is sufficient to recover the email from the database during incident response." The constructor contradicts that documented intent by storing the email as a public property that Laravel will serialize to the queue backend.
    - **Plain English:** The team already recognized this email address is sensitive and made sure it's not written to the error log. But it's still printed on the outside of every message in the queue room. The `failed()` handler comment says exactly what to do: use the enquiry ID as a tracking number and only look up the email from the database when actually needed.
    - **Evidence:**
        ```php
        public function __construct(
            public readonly string $enquiryId,
            public readonly string $notificationEmail,  // ← serialized to Redis payload
        ) {
            $this->onQueue('notifications');
        }
        ```
        ```php
        // failed() correctly avoids logging — but constructor undoes this:
        // Don't log the professional's notification_email — log retention exceeds
        // GDPR/Privacy Act expectations; enquiry_id is sufficient to recover the
        // email from the database during incident response.
        Log::error('SendEnquiryNotificationJob failed permanently', [
            'enquiry_id' => $this->enquiryId,
            'error' => $e->getMessage(),
        ]);
        ```

- [ ] **#SEC-11** · P2 — GDPR export job logs professional contact email on successful delivery
    - **Where:** `app/Jobs/Shopify/Gdpr/ExportCustomerDataJob.php:96-101`
    - **Affects:** Professional privacy. The professional's contact email (used as the GDPR export recipient) is written to the application log stream on every successful export run.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove `'recipient' => $recipientEmail` from the `Log::info` context. `gdpr_request_id` + `professional_id` are already present and sufficient for incident response.
    - **Technical:** While this is a business email (not a consumer/customer email), it is personally identifiable under Australian Privacy Act definitions when the professional is a sole trader or individual. `professional_id` in scope already allows the email to be recovered from the database if needed. The same log entry that documents "we handled your GDPR data request" should not permanently record the recipient's personal email in an aggregator with indefinite retention.
    - **Plain English:** When we finish packaging a customer's data export and email it to the store owner, we write a note in the operations log that says "sent to joe@example.com." That note stays in the log system for months. We already write Joe's account ID in the same note — that's enough to find his email if we ever need it. Remove the email address from the note.
    - **Evidence:**
        ```php
        Log::info('ExportCustomerDataJob completed.', [
            'gdpr_request_id' => $gdpr->id,
            'professional_id' => $professionalId,
            'recipient' => $recipientEmail,   // ← PII in persistent log
            'customer_records' => count($exportData['customers'] ?? []),
        ]);
        ```

- [ ] **#SEC-12** · P2 — Stripe API version has divergent hardcoded defaults across two config files
    - **Where:** `config/services.php:95` vs `config/partna.php:1200`
    - **Affects:** All Stripe API calls on fresh deploys where `STRIPE_API_VERSION` is not set in `.env`. Core SDK calls use `2026-02-25.clover`; the commission export pipeline uses `2025-02-24.acacia` — a year-older API version with potentially different field shapes.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Align the default in `config/partna.php` exports section to `'2026-02-25.clover'`, matching `config/services.php`.
        - Better: remove the duplicate default entirely and have the export config read `config('services.stripe.api_version')` directly, making it impossible for the two to diverge again.
        - Set `STRIPE_API_VERSION` explicitly in `.env.example` to ensure fresh deploys don't rely on either default.
    - **Technical:** The comment in `config/partna.php` claims this value is "Shared with the global Stripe SDK binding so the whole app pins one version" — the differing defaults directly contradict that claim. Stripe API versions are immutable: fields available in `2026-02-25.clover` may be absent or differently shaped in `2025-02-24.acacia`. A fresh deploy with no `STRIPE_API_VERSION` set would produce different payout calculation shapes between the SDK and the export pipeline, creating silent data mismatches in commission exports.
    - **Plain English:** Two different parts of the app are each configured with a different edition of the Stripe rulebook as their backup. If the environment setting that picks the edition isn't configured, the main app signs the 2026 edition while the export system signs the 2025 edition. Most clauses match, but if a field was added or changed between editions, the export data and the live payment data could look different from each other — and it would be very hard to diagnose.
    - **Evidence:**
        ```php
        // config/services.php:95
        'api_version' => env('STRIPE_API_VERSION', '2026-02-25.clover'),

        // config/partna.php:1200
        // Shared with the global Stripe SDK binding so the whole app pins one version.
        'stripe_api_version' => env('STRIPE_API_VERSION', '2025-02-24.acacia'),
        ```

- [ ] **#SEC-13** · P2 — Hydrogen GitHub PAT globally accessible via `config('partna.hydrogen.github_token')`
    - **Where:** `config/partna.php` — `hydrogen.github_token`
    - **Affects:** The `sidest-storefront` GitHub repository. A leaked token grants `actions:write` — the ability to trigger workflows, modify CI dispatch inputs, and potentially read secrets embedded in workflow runs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Audit all `Log::*`, `dd()`, `dump()`, exception handlers, and Nightwatch context payloads to confirm none emit `config('partna.hydrogen')` or the token key directly.
        - Add `hydrogen.github_token` to Nightwatch's `redact_payload_fields` in `config/nightwatch.php` as defense-in-depth.
        - Consider moving the token out of `config/partna.php` into a dedicated `GitHubService` that reads `env('PARTNA_HYDROGEN_GITHUB_TOKEN')` at call time and never stores it in the globally-accessible config array.
    - **Technical:** Laravel's `config()` helper makes every value in `config/partna.php` accessible from any code path — controllers, jobs, artisan commands, error handlers. A broad `Log::debug('config', config('partna'))` call (a debugging anti-pattern but not uncommon) would dump the PAT to Nightwatch. The token lives in the same config file as link-block settings and social platform lists, making it easy to overlook when adding a new log statement. A GitHub PAT with `actions:write` can trigger workflows, modify repository dispatch inputs, and read workflow-run logs that may contain other secrets.
    - **Plain English:** You've stored the key to your GitHub factory — which can reprogram the production robots — in a shared filing cabinet drawer alongside ordinary settings like "how many links can a page have." Any maintenance person who inventories the drawer can see the key. The fix is to keep the key in a locked box that only the machine operator opens, not a shared settings drawer that any part of the application can reach into.
    - **Evidence:**
        ```php
        'hydrogen' => [
            // GitHub PAT with actions:write scope on the sidest-storefront repo.
            'github_token' => env('PARTNA_HYDROGEN_GITHUB_TOKEN', env('SIDEST_HYDROGEN_GITHUB_TOKEN')),
        ```

- [ ] **#SEC-14** · P2 — Webhook `resolveShopDomain()` falls back to untrusted payload domain when integration is missing
    - **Where:** `app/Jobs/Shopify/ProcessShopifyOrderUpdatedWebhookJob.php` — `resolveShopDomain()` (~line 543)
    - **Affects:** Order-update webhook processing when the integration row is absent. The shop domain — used to locate the order — comes from the webhook payload itself rather than the authoritative database record.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the fallback to `$this->payload['domain']`. Return empty string and let the caller short-circuit with a warning log — same pattern as `HandlesShopifyWebhook::__invoke()` which returns 200 "not our shop" when no integration is found.
    - **Technical:** `resolveShopDomain()` queries `professional_integrations` for `shopify_shop_domain`. If the integration record is absent, it falls back to `Arr::get($this->payload, 'domain')` — a field from the Shopify webhook body. While HMAC verification must pass before this job is dispatched, defense-in-depth requires the domain to come only from the authoritative database record. If the job were ever enqueued by a test harness, a misconfigured retry path, or a future code path that bypasses the webhook controller, the fallback gives the caller control over which shop domain the order lookup targets.
    - **Plain English:** When we need to figure out which store an order belongs to, we check our own records. If our records don't have that store, we ask the incoming message "so, which store are you from?" and trust whatever it says. That's like a bouncer who can't find your name on the guest list saying "just tell me who you are, I'll let you in." If the store isn't in our database, we should stop there — not ask the message to identify itself.
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

---

## P3 — Nice to have

- [ ] **#SEC-15** · P3 — Brand catalog debug endpoint should be removed or staff-gated before pilot
    - **Where:** `routes/api/professional.php` — `GET /brand/catalog/debug`
    - **Affects:** Any authenticated brand professional can see raw Shopify API responses, OAuth scopes granted to the app, and cost data for their store.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the route before pilot launch, or move it to `routes/api/staff.php` behind `staff.admin` middleware.
        - If retained for debugging, redact `scopes` and `cost` fields before returning the response.
    - **Technical:** The route comment says "safe to leave in place; auth-gated, read-only, no mutations." Tenant isolation is enforced (brand-only). The concern is that exposing OAuth scopes tells any brand exactly which Shopify API surfaces the app has access to — useful intelligence for anyone probing the platform. The "temporary diagnostic probe" comment suggests awareness that this is development scaffolding; pre-pilot is the right time to remove it.
    - **Plain English:** There's a debug button in the brand dashboard that shows the raw data coming back from Shopify — including what permissions our app has on their store. It's locked to the brand's own data, so they can't see anyone else's. But before real brands start using the system, we should remove this button or lock it to staff only — it was put there for development and was never meant to be a permanent feature.
    - **Evidence:**
        ```php
        // Temporary diagnostic probe — returns raw Shopify response for a
        // minimal products query so we can see exactly what Shopify returns
        // (shop info, products sample, cost, errors, granted scopes). Safe
        // to leave in place; auth-gated, read-only, no mutations.
        Route::get('/brand/catalog/debug', [BrandCatalogController::class, 'debug']);
        ```

- [ ] **#SEC-16** · P3 — `.env.example` recommends a full-access Resend API key when sending-only scope is sufficient
    - **Where:** `.env.example` — `RESEND_API_KEY` comment block
    - **Affects:** Principle of least privilege. A full-access key can manage domains, rotate API keys, and export contact lists — none of which the Laravel mailer needs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Update the comment to recommend a "Sending access" scoped key instead of "Full access."
        - Note that bounce/complaint webhook configuration is done in the Resend dashboard (domain settings) and does not require a broader API key.
    - **Technical:** Resend scoped API keys let you issue a key that only allows the `send` endpoint — everything the `resend` mail transport needs. "Full access" additionally allows domain management, API key creation/deletion, and audience operations. If the key leaks, a "Sending access" key limits the blast radius to email delivery; a "Full access" key lets an attacker delete verified domains or rotate all keys. The comment's rationale ("so bounce/complaint webhooks can be wired up later") conflates API key scope with webhook configuration, which are separate concerns in Resend.
    - **Plain English:** The setup guide tells developers to use a master key that can do everything in Resend — delete email domains, create new keys, export subscriber lists. A "send mail only" key is all that's needed. If someone ever steals the key, limiting it to "send mail only" means the worst they can do is send emails from your account — not delete your entire email setup.
    - **Evidence:**
        ```
        # Resend HTTP API key — required when MAIL_MAILER=resend. Get from
        # https://resend.com/api-keys. Use a "Full access" key so bounce/complaint
        # webhooks can be wired up later.
        RESEND_API_KEY=
        ```

- [ ] **#SEC-17** · P3 — Public email-subscription endpoint missing captcha protection present on peer lead-capture routes
    - **Where:** `routes/api.php` — `POST /public/subscribe` and `routes/api/publicSite.php` — `POST /public/{subdomain}/subscribe`
    - **Affects:** Brand email subscriber lists. Without a bot challenge, a script can subscribe thousands of fabricated email addresses, inflating lists and degrading email sender reputation.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `captcha` middleware to both subscribe routes, matching the protection level of the adjacent `customers`, `enquiry`, and `waitlist` endpoints.
        - Ensure `PARTNA_CAPTCHA_ENABLED` gates this middleware consistently with the other public endpoints.
    - **Technical:** The customer-lead, enquiry, and waitlist endpoints all carry `->middleware(['lead.log', 'throttle:leads', 'captcha'])`. The subscribe routes carry only `->middleware('throttle:public-site')`. A distributed botnet can stay under per-IP rate limits while subscribing at scale. The `captcha` middleware already exists and is used consistently elsewhere — the subscribe route is an unexplained gap in an otherwise uniform public surface.
    - **Plain English:** The "contact us" and "join waitlist" forms on every brand's site have a bot check. The "subscribe to emails" form on the same site doesn't. A script can sign up thousands of fake email addresses to any brand's newsletter. Adding the same bot check that's already on the other forms closes this gap.
    - **Evidence:**
        ```php
        // No captcha — subscribe route:
        Route::post('/public/subscribe', [PublicEmailSubscriptionController::class, 'subscribe'])
            ->middleware('throttle:public-site');

        // Captcha present on peer endpoints:
        Route::post('/public/customers', [PublicCustomerLeadController::class, 'store'])
            ->middleware(['lead.log', 'throttle:leads', 'captcha']);
        Route::post('/public/enquiry', [PublicEnquiryController::class, 'submit'])
            ->middleware(['lead.log', 'throttle:leads', 'captcha']);
        ```

- [ ] **#SEC-18** · P3 — Test placeholder PII (charlie@ai.com) seeded into public profiles via account_type_defaults
    - **Where:** `config/partna.php` — `account_type_defaults.influencer`, `account_type_defaults.individual`, `account_type_defaults.partner` (each `default_contact` block)
    - **Affects:** Every newly registered professional, influencer, individual, or partner account that hasn't yet customized their contact section — their public profile displays `charlie@ai.com` and `1234 567 890` until edited.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the hardcoded placeholder values with empty strings or `null`, and render a "set your contact info" prompt in the frontend contact block when values are absent.
        - If placeholders are needed for visual preview during onboarding, use clearly synthetic values (`you@example.com`, `+61 0000 0000`) that cannot be confused with real inboxes.
    - **Technical:** `default_contact` arrays for three account types each contain `'full_name' => 'Charlie'`, `'email' => 'charlie@ai.com'`, `'phone' => '1234 567 890'`. These are written into new professionals' contact section blocks on registration. Until the professional edits their site, these values are publicly visible. `charlie@ai.com` could be a real inbox; publishing it on every new user's public page without consent creates a PII exposure for whoever owns that address and a spam vector.
    - **Plain English:** When a new user signs up, we pre-fill their public contact card with a placeholder name and what looks like a real email address. Until the user notices and changes it, every visitor to their page sees that email. Even if "Charlie" is an internal test account, we're putting their details on thousands of public pages. Replace it with blank fields or an obviously fake placeholder like "your-email@example.com."
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

- [ ] **#SEC-19** · P3 — Multiple controllers use inline `isBrand()` / `abort_unless` instead of Policy or middleware gates
    - **Where:** `app/Http/Controllers/Api/Professional/Account/ProfessionalDocumentController.php:66-69`, `app/Http/Controllers/Api/Professional/Affiliate/AffiliateInviteController.php:27-29`, `app/Http/Controllers/Api/Professional/Brand/BrandAffiliateInviteController.php` (7 call sites), `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffLinkBlockManagementController.php:75-89`
    - **Affects:** Authorization doctrine consistency. Inline checks cannot be tested centrally, don't enforce `denyAsNotFound()` / `denyIfPendingDeletion()` semantics, and drift silently when the `brand.only` / `affiliate.only` middleware evolves.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - For `isBrand()` / `isAffiliate()` type-gate checks: remove inline checks and rely on `brand.only` / `affiliate.only` middleware declared at the route level. Confirm the affected routes are already behind the correct middleware group.
        - For `StaffLinkBlockManagementController` `abort_unless($linkBlock->professional_id === $professional->id ...)`: replace with `$this->authorizeForUser($professional, 'update'/'delete', $linkBlock)` via `BlockPolicy`.
        - For `BrandStoreSettingsController::update` and `ProfessionalGoogleBusinessProfileController` (which write tenant-owned models without a `authorizeForUser` call): add the policy gate.
    - **Technical:** Per Partna Authorization Doctrine points 2–4: all resource-level authorization goes through `authorizeForUser($pro, 'verb', $resource)` against a Policy extending `BasePolicy`. Inline `abort_unless` and inline `isBrand()` checks spread authorization decisions across controller files instead of centralizing them in testable, auditable Policy classes. The CI guard already rejects `BrandAccessService` capability calls — the inline `abort_unless` and `isBrand()` patterns are the manual equivalent. The routes are currently protected by middleware, making these checks redundant rather than insecure, but they represent technical debt that will cause authorization drift as the platform evolves.
    - **Plain English:** Some doors have a proper keycard reader (the middleware and policy system), and some have a handwritten sign saying "brands only" taped to the wall. The sign works today because the keycard reader is also there. But if someone updates the keycard rules later, they'll update the reader — not hunt for every handwritten sign. Replacing signs with readers keeps all the rules in one place.
    - **Evidence:**
        ```php
        // ProfessionalDocumentController.php:66-69
        if ($pro->isBrand()) {
            return $this->error('Documents section not available for brand accounts.', 403);
        }
        ```
        ```php
        // StaffLinkBlockManagementController.php:75-80
        abort_unless(
            $linkBlock->professional_id === $professional->id &&
            $linkBlock->block_group === 'links' &&
            $linkBlock->block_type === 'link',
            404
        );
        ```
        ```php
        // BrandAffiliateInviteController.php (repeated 7×)
        if (! $professional->isBrand()) {
            return $this->error('Only brand accounts can view affiliate invites.', 403);
        }
        ```
