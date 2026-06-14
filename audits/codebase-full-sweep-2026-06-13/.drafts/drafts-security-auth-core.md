- [ ] **SEC-1** · P2 — CORS `allowed_origins` resolves to `[]` on deploy when config load order puts `cors.php` before `partna.php`
    - **Where:** config/cors.php:19
    - **Affects:** All browser-origin CORS preflight requests; main frontend origins relying on explicit allowlist entries (apex domain, alternative envs) silently denied.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the inline `config('partna.frontend_origins', [])` in `config/cors.php` with a string env reference and move origin assembly into a deferred `AppServiceProvider::boot()` call that sets `config(['cors.allowed_origins' => ...])` after all config is guaranteed loaded.
        - Alternatively, duplicate the `allowed_origins` list into a dedicated `CORS_FRONTEND_ORIGINS` env var read directly by `config/cors.php` so there is zero cross-config dependency at load time.
    - **Technical:** `config/cors.php` calls `config('partna.frontend_origins', [])` during its own evaluation. The `LoadConfiguration` bootstrap loads config files in filesystem (alphabetical) order — `cors.php` evaluates before `partna.php`. When `partna` config hasn't been loaded yet, the `config()` helper returns the default `[]`. This means `HandleCors` (`fruitcake/laravel-cors` or Laravel's bundled CORS middleware) sees `allowed_origins: []` and rejects every explicit-origin request that isn't matched by the subdomain regex pattern. The `SecureHeaders::applyCors()` fallback path is NOT affected because it calls `config('partna.frontend_origins')` at request time (post-boot).
    - **Plain English:** Imagine the front door key is stored in two safes. Safe A (the CORS config) needs to read Safe B (the Partna config) to know which keys are valid. But Safe A gets opened before Safe B is filled, so it sees an empty list and locks everyone out — except people using the side entrance (the subdomain pattern match). The fix is to put the key list somewhere both safes can read after everything is loaded, or give each safe its own copy.
    - **Evidence:**
        ```php
        // config/cors.php — evaluated during bootstrap, before partna.php is loaded
        'allowed_origins' => config('partna.frontend_origins', []),
        ```
        ```php
        // config/partna.php — loaded later; the value below is never seen by config/cors.php
        'frontend_origins' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('PARTNA_FRONTEND_ORIGINS', ''))
        ))),
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **SEC-2** · P2 — Bot protection mode `off` in production only logs a warning; does not refuse boot
    - **Where:** app/Providers/BotProtectionServiceProvider.php:85-91
    - **Affects:** Every public mutation endpoint gated by `bot.token` middleware (enquiry, lead, waitlist, subscribe, report) — silently accepts unlimited unverified submissions in production.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Upgrade Guard 4 from `Log::warning` to a hard exception (like Guards 1–3) so deploys refuse to boot with `BOT_PROTECTION_MODE=off` in production.
        - Document the intentional override path: `BOT_PROTECTION_MODE=off` must only be set with an explicit deploy-time acknowledgement (e.g., `BOT_PROTECTION_MODE=off BOT_PROTECTION_OVERRIDE_ACK=true`).
    - **Technical:** `BotProtectionServiceProvider::runBootGuards()` checks four misconfiguration conditions. Guards 1–3 throw `CaptchaConfigurationException` (hard boot failure). Guard 4 (`mode=off` in production) only calls `Log::warning` — the application boots normally and `VerifyBotToken::handle()` hits the `if ($mode === 'off') { return $next($request); }` early-return on every protected route, bypassing all CAPTCHA verification. A `.env.example` copy-paste deploy leaves every form endpoint unprotected.
    - **Plain English:** The alarm system has four self-tests on startup. Three of them refuse to arm if something's wrong. The fourth just writes a sticky note saying "the alarm is off" and lets the building open anyway. If someone copies the example config file to production, every public form accepts submissions with no bot check.
    - **Evidence:**
        ```php
        // Guard 4: mode=off in production. Soft warn — log only, do not refuse boot.
        if ($env === 'production' && $mode === 'off') {
            Log::warning('bot_protection.mode_off_in_production', [
                'note' => 'BOT_PROTECTION_MODE=off disables all bot verification on every protected endpoint; set MODE=shadow or MODE=enforce in production.',
            ]);
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **SEC-3** · P2 — CORS subdomain regex pattern excludes apex domain (`partna.au` without `www`)
    - **Where:** config/cors.php:25
    - **Affects:** Browser requests originating from `https://partna.au` (apex domain) — CORS preflight fails unless the origin is explicitly listed in `PARTNA_FRONTEND_ORIGINS`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a second regex pattern `#^https://partna\.au$#i` to `allowed_origins_patterns` so the apex domain is matched independently of the wildcard subdomain pattern.
        - Alternatively, ensure `https://partna.au` and `https://www.partna.au` are always in the `PARTNA_FRONTEND_ORIGINS` explicit list as a belt-and-suspenders measure.
    - **Technical:** The pattern `#^https://[a-z0-9-]+\.partna\.au$#i` requires at least one character (a subdomain label) before `.partna.au`. The regex quantifier `+` means "one or more," so `https://partna.au` (zero labels before the public suffix) does not match. The apex-domain frontend — which is the primary marketing and logged-out landing surface — would be CORS-denied on API calls unless `PARTNA_FRONTEND_ORIGINS` explicitly includes `https://partna.au`. Note: `https://www.partna.au` DOES match the pattern since `www` satisfies the `[a-z0-9-]+` requirement.
    - **Plain English:** The building has a bouncer with a guest list that says "anyone from a subdomain of partna.au is allowed in." The main entrance at `partna.au` itself isn't a subdomain — it's the front door — so the bouncer turns it away unless it's on a separate VIP list. If that VIP list is empty (see SEC-1), the front door can't get into its own API.
    - **Evidence:**
        ```php
        // config/cors.php — requires at least one subdomain label
        'allowed_origins_patterns' => [
            '#^https://[a-z0-9-]+\.partna\.au$#i',
        ],
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **SEC-4** · P3 — `PerTargetReportThrottle` uses non-HMAC IP hashing, inconsistent with the codebase standard in `HashesClientData`
    - **Where:** app/Http/Middleware/Moderation/PerTargetReportThrottle.php:37
    - **Affects:** IP hash values in report-throttle Redis keys; prevents cross-correlation with lead/analytics IP hashes stored elsewhere.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the inline `hash('sha256', $ip.'|'.$key)` with `hash_hmac('sha256', $ip, $key)` or delegate to `HashesClientData::hashIp()` so all IP hashing in the app uses the same scheme.
        - Audit other call sites for the concatenation pattern and standardise them.
    - **Technical:** `PerTargetReportThrottle` computes IP hashes via `hash('sha256', $request->ip().'|'.config('app.key'))` — a plain SHA-256 of concatenated values. The codebase standard (used by `LogLeadRateLimits`, `VerifyBotToken`, and other analytics paths) is `HashesClientData::hashIp()` which calls `hash_hmac('sha256', $ip, config('app.key'))`. The concatenation approach lacks the double-hash and padding structure of HMAC; while not practically exploitable for IP hashing (SHA-256 is resistant to length-extension in this key-at-end arrangement), the different hash values mean the same IP produces different identifiers across the two systems, making abuse-pattern correlation across the rate-limit and analytics pipelines impossible.
    - **Plain English:** Two security cameras in the same building use different methods to blur faces. Camera A blurs one way, Camera B another. When security reviews footage, they can't tell if the person at the front door is the same person who triggered the alarm at the back — the blurred images don't match. Using the same blur method everywhere lets you track patterns across the whole building.
    - **Evidence:**
        ```php
        // PerTargetReportThrottle.php — non-HMAC concatenation hash
        $ipHash = hash('sha256', $request->ip().'|'.config('app.key'));
        $key = "moderation:report:ip:{$ipHash}:target:{$type}:{$handle}";
        ```
        ```php
        // HashesClientData.php — the codebase standard (HMAC)
        protected function hashIp(?string $ip): ?string
        {
            if (! $ip) {
                return null;
            }
            return hash_hmac('sha256', $ip, config('app.key'));
        }
        ```
    - `[DRAFT, confidence: 0.8]`
