- [ ] **CFG-1** · P2 — `config('partna.cache.ttls.public_payload')` accessed without a default, causing infinite cache TTL when the config key is absent
    - **Where:** app/Services/Cache/SiteCacheService.php (writePayloadWithStale method)
    - **Affects:** Public site payload cache — the highest-traffic cache in the system. A missing config key causes payloads to be cached indefinitely, serving stale data forever (no TTL expiry).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a safe default: `config('partna.cache.ttls.public_payload', 900)` (15 minutes).
        - Ensure `PARTNA_CACHE_TTLS_PUBLIC_PAYLOAD` appears in `.env.example` with a comment explaining the unit (seconds).
    - **Technical:** Category 5 — config file correctness. `(int) config('partna.cache.ttls.public_payload')` returns `0` when the key is missing from `config/partna.php`. Laravel's Redis cache driver treats TTL `0` as "no expiry" (store forever), so both the primary and `:stale` copies (`$base * 10 = 0`) become permanent. This is the opposite of the intended 15-minute SWR pattern — stale data is never evicted, and the only way to bust it is a manual `Cache::flush()` or key deletion.
    - **Plain English:** The speed-limit sign for how long public profile pages stay cached has no fallback number. If someone forgets to set it in a new environment, the cache holds onto old versions of every professional's page forever — like a restaurant that never clears menus after a price change. Adding a sensible default (15 minutes) means the system self-corrects even if the config is missing.
    - **Evidence:**
        ```php
        $base = (int) config('partna.cache.ttls.public_payload');

        // Independent jitter draws so primary and stale copies expire at different seconds.
        Cache::put($key, $value, self::applyJitter($base));
        Cache::put($key.':stale', $value, self::applyJitter($base * self::PAYLOAD_STALE_TTL_MULTIPLIER));
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **CFG-2** · P2 — `config('partna.public_profile.analytics_endpoint')` accessed without a default, shipping `null` to the public API when the key is absent
    - **Where:** app/Services/PublicSite/IndividualProfilePayloadBuilder.php (buildPublicConfig method)
    - **Affects:** Every public profile page load — the `publicConfig.analyticsEndpoint` field is rendered into the Astro Worker subrequest payload. `null` means the frontend analytics tracker has no endpoint and silently fails to record page views.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a safe default: `config('partna.public_profile.analytics_endpoint', '/api/analytics')`.
        - Ensure `PARTNA_PUBLIC_PROFILE_ANALYTICS_ENDPOINT` appears in `.env.example` with a comment explaining what it points to.
    - **Technical:** Category 5 — config file correctness. The method returns `['analyticsEndpoint' => config('partna.public_profile.analytics_endpoint')]` with no second argument. When the config tree is missing this leaf, `config()` returns `null`. The resource serializes this as `"analyticsEndpoint":null` in the JSON response. The test only asserts the key exists (`toHaveKey('analyticsEndpoint')`) — it never asserts the value is non-null, so the test passes even in this broken state.
    - **Plain English:** The "where to send visitor counts" setting has no backup address. If a new environment is set up without this line in the config file, every public profile page ships with a blank analytics address — like mailing a letter with no street name. The frontend tries to send data but it goes nowhere, and nobody notices because there's no error.
    - **Evidence:**
        ```php
        private function buildPublicConfig(): array
        {
            return [
                'analyticsEndpoint' => config('partna.public_profile.analytics_endpoint'),
            ];
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **CFG-3** · P2 — `config('partna.media_disk')` accessed without a default, silently disabling logo URL host validation when absent
    - **Where:** app/Mail/Branding/ProEmailBrandResolver.php (isSafeLogoUrl method)
    - **Affects:** White-label email branding for every enquiry/subscription confirmation. A missing config key causes the logo URL host check to be skipped, allowing any HTTPS URL — including external/malicious hosts — to pass validation and be embedded in outbound emails.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a safe default: `config('partna.media_disk', 'media')`.
        - Ensure `PARTNA_MEDIA_DISK` appears in `.env.example`.
    - **Technical:** Category 5 — config file correctness. `isSafeLogoUrl()` calls `$disk = (string) config('partna.media_disk')` with no default. When absent, `$disk = ''`, so `config("filesystems.disks..url", '')` also returns `''`. The guard `if ($base === '') { return true; }` then fires, treating any HTTPS URL as safe. This is a defense-in-depth downgrade — the intent is to ensure logos come only from the configured media host, but a missing config value makes it a no-op.
    - **Plain English:** The email system checks that the professional's logo comes from the company's own media server before embedding it in outbound mail — like verifying a letterhead came from your office printer. But if the "which disk is our media server?" setting is blank, the check shrugs and says "looks fine to me" for any image URL. A missing config line turns a security guard into a greeter who waves everyone through.
    - **Evidence:**
        ```php
        private function isSafeLogoUrl(string $url): bool
        {
            if (! str_starts_with($url, 'https://')) {
                return false;
            }

            $disk = (string) config('partna.media_disk');
            $base = (string) config("filesystems.disks.{$disk}.url", '');
            if ($base === '') {
                return true; // no configured host to assert against
            }

            $expectedHost = parse_url($base, PHP_URL_HOST);
            $actualHost = parse_url($url, PHP_URL_HOST);

            return $expectedHost === null || $expectedHost === $actualHost;
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **CFG-4** · P3 — Queue name `'notifications'` hardcoded as a string literal in two job classes
    - **Where:** app/Jobs/Notifications/SendEnquiryConfirmationJob.php:34 and app/Jobs/Notifications/SendSubscriptionConfirmationJob.php:34
    - **Affects:** All outbound visitor email confirmations. A typo in the queue name or an environment that needs a different queue (e.g., `notifications-staging`) requires a code change and deploy.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract to a config key: `config('partna.queues.notifications', 'notifications')`.
        - Use `$this->onQueue(config('partna.queues.notifications'))` in both constructors.
    - **Technical:** Category 4 — hardcoded values. The lens explicitly calls out queue names as hardcoded string literals: "a typo creates a silent routing failure." Both jobs call `$this->onQueue('notifications')` in their constructors. This bypasses environment-specific queue routing — if a staging deploy uses a different Redis connection for notifications, these jobs will land on the production `notifications` queue and either fail silently or pollute production data. The value is duplicated across two files, doubling the drift risk.
    - **Plain English:** Both notification jobs have the mailbox number "notifications" written on them in permanent marker. If you ever need a different mailbox for testing or a separate environment, you can't just change a setting — you have to rewrite both letters. A typo means the letters go to a mailbox nobody checks, and nobody gets an error because the postal system doesn't complain about undeliverable mail.
    - **Evidence:**
        ```php
        // SendEnquiryConfirmationJob.php
        public function __construct(public readonly string $enquiryId)
        {
            $this->onQueue('notifications');
        }
        ```
        ```php
        // SendSubscriptionConfirmationJob.php
        public function __construct(public readonly string $subscriptionId)
        {
            $this->onQueue('notifications');
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **CFG-5** · P3 — `SUBDOMAIN_COOLDOWN_DAYS = 30` hardcoded as a class constant instead of a config value
    - **Where:** app/Services/Site/UpdateSiteAction.php:30
    - **Affects:** Subdomain rename flow — professionals are locked out of changing their subdomain for 30 days. Staging/test environments can't shorten this to e.g. 1 day without a code change.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the constant reference with `(int) config('partna.handle.subdomain_cooldown_days', 30)`.
        - Add `PARTNA_HANDLE_SUBDOMAIN_COOLDOWN_DAYS=30` to `.env.example` with a comment.
    - **Technical:** Category 4 — hardcoded values. The constant `SUBDOMAIN_COOLDOWN_DAYS` is a business rule that differs by environment (production wants 30 days; staging wants 1 day for testing rename flows). It's defined as a PHP class constant and used directly in the cooldown calculation. This is the same class that already reads `config('partna.handle.reclaim_days', 14)` and `config('partna.handle.redirect_days', 90)` for the other handle lifecycle settings — the cooldown is the only one that didn't get the config treatment.
    - **Plain English:** The "you can only change your web address once a month" rule is carved in stone inside the code. For testing on a staging server, you'd want it to be 1 day so testers can rename freely, but there's no knob to turn — you have to chisel a new stone. The two related settings (how long the old address redirects and how long before it's recycled) already have adjustable knobs; this one is oddly stuck.
    - **Evidence:**
        ```php
        // Days between allowed subdomain changes. Mirrored in UserSelfController::show
        // when computing subdomain_change_available_at for the /me payload.
        public const SUBDOMAIN_COOLDOWN_DAYS = 30;
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **CFG-6** · P3 — Default skeleton ID `'skeleton-1'` hardcoded in two separate files
    - **Where:** app/Services/PublicSite/IndividualProfilePayloadBuilder.php (build method) and app/Http/Resources/PublicSite/IndividualProfileResource.php (toArray method)
    - **Affects:** Public profile rendering — if the platform's default skeleton ever changes (e.g., `skeleton-3` becomes the new default), two files must be updated in lockstep. A miss causes the builder and the resource to disagree on the fallback.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Define a single source of truth: `config('partna.skeletons.default', 'skeleton-1')`.
        - Reference it in both locations. Add `PARTNA_SKELETONS_DEFAULT=skeleton-1` to `.env.example`.
    - **Technical:** Category 4 — hardcoded values duplicated across files. The builder computes `$site?->skeleton_id ?? 'skeleton-1'` and the resource independently computes `$this->sections['skeleton_id'] ?? 'skeleton-1'`. While the resource receives its value from the builder (so they typically agree), the fallback string is duplicated. If a migration changes the DB default or a future skeleton becomes the platform default, both locations must be found and updated — there's no single config key to change.
    - **Plain English:** The "which page template to use if nothing is chosen" answer is written on two separate sticky notes in two different filing cabinets. If the answer ever changes (say, a new template becomes the standard), someone has to find and update both notes. A single whiteboard with the answer would be safer.
    - **Evidence:**
        ```php
        // IndividualProfilePayloadBuilder::build()
        'skeleton_id' => $site?->skeleton_id ?? 'skeleton-1',
        ```
        ```php
        // IndividualProfileResource::toArray()
        'skeletonId' => $this->sections['skeleton_id'] ?? 'skeleton-1',
        ```
    - `[DRAFT, confidence: 0.8]`
