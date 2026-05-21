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
