- [ ] **#CFG-1** · P3 — Hardcoded Square API version string  
    - **Where:** app/Services/Square/SquareApiClient.php (makeRequest method)  
    - **Affects:** Square integration — API version upgrades require a code change instead of a one-line config update; risk of shipping stale version in a deploy.  
    - **Effort:** S (~0.5–1h)  
    - **What to do:**  
        - Add a config key like `services.square.api_version` to `config/services.php`, sourced from `SQUARE_API_VERSION` in `.env.example`.  
        - Replace the hardcoded string `'2025-10-16'` with `config('services.square.api_version')`.  
    - **Technical:** The `makeRequest` method sets the `Square-Version` header to a literal string. When Square releases a new API version, every service class that builds requests must be touched. Extracting to config centralises the version and allows environment-specific pinning (e.g., testing against a pre-release version in sandbox without a full deploy).  
    - **Plain English:** Imagine every office door has the opening hours painted on it instead of on a sign outside. When the hours change, you need to repaint every single door. Moving the version to a configuration file is like updating one sign — everyone reads from the same place, and changes happen once.  
    - **Evidence:**  
        ```php
        // SquareApiClient::makeRequest
        ->withHeaders([
            'Square-Version' => '2025-10-16',
        ])
        ```  
    - `[DRAFT, confidence: 0.9]`

- [ ] **#CFG-2** · P3 — Hardcoded retry count in Square and Fresha API clients  
    - **Where:** app/Services/Square/SquareApiClient.php (request method, `$maxRetries = 3`) and app/Services/Fresha/FreshaApiClient.php (request method, `$maxRetries = 3`)  
    - **Affects:** System resilience — unable to tune retry behaviour per environment (e.g., fewer retries in dev for faster feedback).  
    - **Effort:** S (~0.5–1h)  
    - **What to do:**  
        - Add a config key like `services.square.max_retries` (and `services.fresha.max_retries`) to `config/services.php`.  
        - Use `config('services.square.max_retries', 3)` in the respective `request()` methods, keeping the current value as the default.  
    - **Technical:** The retry limit for 429 responses is buried inside a service class. In staging, a lower retry count may be desirable to surface rate-limit issues sooner; in production, a higher limit may be warranted. Config extraction makes this tunable per environment via `php artisan config:cache` without modifying the class.  
    - **Plain English:** Think of it like the number of times you let a vending machine retry a stuck coin before walking away. Right now that number is written on a sticky note inside the machine — only a mechanic can change it. Putting it in a configuration file lets the owner adjust it without opening the machine.  
    - **Evidence:**  
        ```php
        // SquareApiClient::request
        $maxRetries = 3;

        // FreshaApiClient::request
        $maxRetries = 3;
        ```  
    - `[DRAFT, confidence: 0.9]`

- [ ] **#CFG-3** · P3 — Hardcoded HTTP timeouts in Square/Fresha API clients and token services  
    - **Where:** app/Services/Square/SquareApiClient.php (`->timeout(30)`), app/Services/Fresha/FreshaApiClient.php (`->timeout(30)`), app/Services/Square/SquareTokenService.php (`->timeout(20)`), app/Services/Fresha/FreshaTokenService.php (`->timeout(20)`)  
    - **Affects:** Reliability under network degradation — timeouts cannot be adjusted per environment or service without a code change.  
    - **Effort:** S (~0.5–1h)  
    - **What to do:**  
        - Add config keys `services.square.http_timeout`, `services.fresha.http_timeout`, and similar for token services, defaulting to the current values.  
        - Replace all hardcoded `->timeout(N)` with `config('services.square.http_timeout', 30)` etc.  
    - **Technical:** Four service classes embed timeout seconds as integer literals inside `makeRequest()` and token refresh calls. If Square’s latency spikes, operators cannot raise the timeout quickly without a code push. Config-ifying allows runtime tuning via env variable, and keeps the pattern consistent with other configurable limits (rate limits, retry counts).  
    - **Plain English:** These numbers are like dials on a factory machine that control how long to wait before giving up. Right now the dials are glued in place — only an engineer with a screwdriver (a code deploy) can turn them. Moving them to a control panel (config) lets the operator adjust on the fly.  
    - **Evidence:**  
        ```php
        // SquareApiClient::makeRequest
        ->timeout(30)

        // FreshaApiClient::makeRequest
        ->timeout(30)

        // SquareTokenService::refreshAccessToken
        ->timeout(20)

        // FreshaTokenService::refreshAccessToken
        ->timeout(20)
        ```  
    - `[DRAFT, confidence: 0.9]`

- [ ] **#CFG-4** · P2 — CloudflarePurgeService hardcodes the public domain `partna.au`  
    - **Where:** app/Services/Cloudflare/CloudflarePurgeService.php (purgeHandle method)  
    - **Affects:** Cache invalidation — if the public domain changes (e.g., another TLD), all handle-based purges would target the wrong URLs and edge cache would remain stale.  
    - **Effort:** S (~0.5–1h)  
    - **What to do:**  
        - Read the domain from an existing config key (`partna.public_domain`) rather than the string literal `partna.au`.  
        - Update the URL construction to `"https://{$h}." . config('partna.public_domain')`.  
    - **Technical:** The `purgeHandle()` method constructs purge URLs with a hardcoded `.partna.au` suffix. The app already has a `PARTNA_PUBLIC_DOMAIN` env var and its corresponding `config('partna.public_domain')` value — AppServiceProvider even refuses to boot in production if it’s empty. Using that value here would automatically keep purge URLs in sync with any future domain migration.  
    - **Plain English:** This is like having a forwarding address written on a single whiteboard in the mailroom. If the company moves, the mailroom keeps sending packages to the wrong building until someone remembers to update that one whiteboard. Using the official company-wide address book (the config value) would fix it automatically.  
    - **Evidence:**  
        ```php
        // CloudflarePurgeService::purgeHandle
        $this->purgeUrls([
            "https://{$h}.partna.au/",
            "https://{$h}.partna.au",
        ]);
        ```  
    - `[DRAFT, confidence: 0.8]`

- [ ] **#CFG-5** · P3 — Hardcoded batch sizes and TTL tiers in LiveStatusPoller  
    - **Where:** app/Services/Streaming/LiveStatusPoller.php (constants `TWITCH_BATCH_SIZE`, `KICK_BATCH_SIZE`, `LIVE_TTL_SECONDS`, `WARM_OFFLINE_TTL`, etc.)  
    - **Affects:** Streaming live-status polling — tuning poll frequency or batch size requires a code deploy.  
    - **Effort:** S (~0.5–1h)  
    - **What to do:**  
        - Move the operational constants to `config/partna.php` under a `streaming` key (e.g., `partna.streaming.poll_batch_sizes.twitch`, `partna.streaming.ttl_tiers.live`).  
        - Replace constant references with `config()` calls, keeping the current values as defaults.  
    - **Technical:** The poller uses class constants to drive tiered TTLs and API batch sizes — directly impacting API call rate and Redis key freshness. In production, you may want to raise `KICK_BATCH_SIZE` when Kick relaxes limits, or shorten `LIVE_TTL_SECONDS` for a high-priority event. Hardcoding these numbers makes experimentation risky and slow. Moving them to config allows hot-restart tuning via `php artisan config:cache`.  
    - **Plain English:** Think of it like the thermostat schedule in a building — it decides how often to check the temperature. Right now that schedule is etched into metal. Moving it to a programmable thermostat (config) lets facilities adjust it for special events without a construction crew.  
    - **Evidence:**  
        ```php
        private const TWITCH_BATCH_SIZE = 100;
        private const KICK_BATCH_SIZE = 50;
        private const LIVE_TTL_SECONDS = 180;
        private const WARM_OFFLINE_TTL = 180;
        private const COOL_OFFLINE_TTL = 600;
        private const COLD_OFFLINE_TTL = 1800;
        ```  
    - `[DRAFT, confidence: 0.9]`
