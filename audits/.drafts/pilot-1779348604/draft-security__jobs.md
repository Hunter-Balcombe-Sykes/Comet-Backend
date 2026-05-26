- [ ] **#SEC-1** · P1 — GDPR export temporary files created in world-readable system temp directory
    - **Where:** app/Jobs/Gdpr/ExportProfessionalDataJob.php:97, app/Jobs/Exports/ExportFinalizerJob.php:78
    - **Affects:** Any professional whose data export is processed; full PII (customer names, emails, phone numbers, order history) is written to `/tmp` during the 600s job window.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `tempnam(sys_get_temp_dir(), ...)` with `Storage::disk('local')->path(...)` or a dedicated private temp directory with `0700` permissions.
        - Ensure the temp directory is outside the web root and not shared across tenant processes.
    - **Technical:** `sys_get_temp_dir()` typically resolves to `/tmp` with default 0755/0644 permissions, meaning any process on the same host can read the file while the job runs. `ExportProfessionalDataJob` has a `$timeout = 600` — the zip containing the full professional data payload sits in `/tmp` for up to 10 minutes. Laravel's `Storage::disk('local')` writes to `storage/app/` which is conventionally excluded from web serving and has stricter permission control.
    - **Plain English:** Imagine writing a customer's entire file history onto a sticky note and leaving it on the office kitchen table for 10 minutes while you go make copies. Anyone walking by can read it. The fix is to write that file inside a locked drawer instead.
    - **Evidence:**
        ```php
        // ExportProfessionalDataJob.php:97
        $written = $writer->writeStreaming($builder, $audit->professional_id);
        $tmpPath = $written['path'];
        ```
        ```php
        // ExportFinalizerJob.php:78
        $tmpFinal = tempnam(sys_get_temp_dir(), 'cep_final_') . '.' . $audit->format;
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#SEC-2** · P2 — Customer email address persisted in Redis queue payload
    - **Where:** app/Jobs/Notifications/SyncCustomerMarketingOptInJob.php:41-46
    - **Affects:** Every customer whose marketing opt-in status changes; email address is serialized into the Redis queue payload and persists until the job is consumed.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the `$email` constructor parameter with a `$customerId` lookup inside `handle()`, or pass an opaque reference instead of the raw email.
        - Alternatively, encrypt queue payloads via Laravel's `ShouldBeEncrypted` contract or a custom serialization guard.
    - **Technical:** Laravel serializes job constructor properties into the queue backend (Redis DB 2). Redis persistence (RDB/AOF snapshots) means the email address survives job completion in backup files. Under GDPR/Privacy Act, email is PII and its presence in an operational data store (Redis) that may not have the same retention/deletion controls as the primary database creates a compliance surface.
    - **Plain English:** You write a customer's email on a whiteboard in the office so the next shift knows who to update. The whiteboard gets photographed every hour for backups, but nobody ever erases old entries. The fix is to write a reference number instead of the email, and have the next shift look up the details themselves.
    - **Evidence:**
        ```php
        public function __construct(
            public readonly string $professionalId,
            public readonly string $email,      // PII in queue payload
            public readonly bool $subscribed,
        ) {
            $this->onQueue('notifications');
        }
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#SEC-3** · P2 — Shopify logo download accepts any HTTPS URL, not restricted to Shopify CDN domains
    - **Where:** app/Jobs/Shopify/SyncShopifyBrandDesignJob.php:234-237
    - **Affects:** Any brand undergoing brand-design sync; an attacker who compromises the `BrandDesignImporter` response or Shopify's API could cause the job to fetch from an arbitrary HTTPS server (SSRF).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a domain allow-list check: only permit URLs from `cdn.shopify.com`, `*.myshopify.com`, or Shopify's known asset CDN domains.
        - Consider adding an IP-range restriction at the network/HTTP-client level to block RFC1918 and cloud-metadata IPs.
    - **Technical:** The guard `str_starts_with($sourceUrl, 'https://')` permits any TLS URL. While the source URL is sourced from Shopify's Brand API (trusted in normal operation), defense-in-depth demands that outbound HTTP fetches from queue jobs restrict targets to expected vendor domains. The `allow_redirects => ['protocols' => ['https']]` option prevents HTTP downgrade but does not restrict the target host.
    - **Plain English:** You ask a trusted courier to pick up a package from "any address starting with https." The courier will go anywhere as long as the door has a secure lock. The fix is to give the courier a specific list of approved addresses.
    - **Evidence:**
        ```php
        if (! is_string($sourceUrl) || $sourceUrl === '' || ! str_starts_with($sourceUrl, 'https://')) {
            return;
        }

        $response = Http::timeout(20)
            ->withOptions(['allow_redirects' => ['max' => 3, 'protocols' => ['https']]])
            ->get($sourceUrl);
        ```
    - `[DRAFT, confidence: 0.75]`

- [ ] **#SEC-4** · P2 — Exception messages logged without sanitization may capture CDN tokens or API response fragments
    - **Where:** app/Jobs/Shopify/SyncShopifyBrandDesignJob.php:248-252 (and similar patterns in 15+ `failed()` / catch blocks across the job files)
    - **Affects:** Log aggregator retention; if a Shopify CDN URL contains a signed access token and the HTTP fetch throws, the token is persisted in structured logs.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Audit all `Log::*` calls in job catch blocks and strip URL query strings or known token patterns from `$e->getMessage()` before logging.
        - Add a log-safety helper (`Log::safeError(...)`) that redacts `sig=`, `token=`, `key=` query parameters from exception messages.
    - **Technical:** Shopify CDN URLs frequently carry short-lived signed parameters (`expires`, `sig`, `token`). If the HTTP client throws (timeout, DNS failure, TLS error), the exception message often includes the full URL. Logs are retained in Nightwatch and cloud log aggregators, creating a long-lived record of access credentials that should be ephemeral.
    - **Plain English:** The delivery driver's clipboard has a temporary door code to get into the building. If the driver gets lost and writes down what went wrong, they copy the door code onto a permanent record that gets filed away. The fix is to teach everyone to redact door codes from their notes.
    - **Evidence:**
        ```php
        } catch (\Throwable $e) {
            Log::warning('Failed to persist Shopify-mirrored brand logo.', [
                'integration_id' => $this->integrationId,
                'variant' => $variant,
                'error' => $e->getMessage(),   // may include signed CDN URL
            ]);
        }
        ```
    - `[DRAFT, confidence: 0.70]`

- [ ] **#SEC-5** · P2 — Unsubscribe token exposed in GET URL without visible rate-limiting or token-entropy guarantees
    - **Where:** app/Jobs/Notifications/SendStaffBroadcastEmailToSubscriberJob.php:103
    - **Affects:** All marketing-email subscribers; if tokens are low-entropy or the unsubscribe endpoint lacks rate limiting, an attacker could enumerate or brute-force tokens to unsubscribe arbitrary users.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Verify `EmailSubscription::unsubscribe_token` uses a cryptographically random 64+ character string (e.g., `Str::random(64)` on creation).
        - Add `throttle:6,1` middleware to the unsubscribe route (`public.unsubscribe`) to prevent brute-force token guessing.
        - Return identical response for valid and invalid tokens to prevent enumeration.
    - **Technical:** The token is embedded in a plain GET URL sent via email. Email is transmitted in cleartext (unless DANE/MTA-STS enforced), so the token travels over potentially unencrypted hops. If the token is short or predictable, an attacker who intercepts one email could pattern-match to guess others. Laravel's `throttle` middleware on the unsubscribe route would limit guessing attempts. Without seeing the `EmailSubscription` model or the route definition, the entropy and rate-limiting posture cannot be confirmed from these files alone.
    - **Plain English:** You mail someone a key to their mailbox with the key code printed on the outside of the envelope. Anyone handling the mail can see it. If all the keys follow a simple pattern, someone could try guessing other people's codes. The fix is to make the codes long and random, and to limit how many wrong guesses someone can make.
    - **Evidence:**
        ```php
        $unsubscribeUrl = route('public.unsubscribe', ['token' => $sub->unsubscribe_token]);

        Mail::to($sub->email)->send(
            new StaffBroadcastMail($notification, $unsubscribeUrl)
        );
        ```
    - `[DRAFT, confidence: 0.65]`
