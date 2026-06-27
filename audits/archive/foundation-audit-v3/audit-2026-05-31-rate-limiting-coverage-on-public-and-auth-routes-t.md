`★ Insight ─────────────────────────────────────`
Three patterns worth noting in this adjudication:
1. **RFC compliance vs security tension** — RATE-2's unsubscribe finding conflicts with the code's explicit RFC 8058 compliance comment. Mailbox providers (Gmail/Yahoo) POST list-unsubscribe endpoints directly; any CAPTCHA gate breaks that. The code already documents this tradeoff.
2. **Silent no-op infrastructure** — The biggest miss in the DeepSeek scan is that `BOT_PROTECTION_MODE=off` is the hardcoded default AND the example value in `.env.example`, with no production boot guard. All `bot.token:*` middleware on enquiry/subscribe/waitlist/leads silently passes with zero verification unless an operator explicitly sets the env var.
3. **Dead CORS surface after a strip** — Shopify patterns in `config/cors.php` are a direct artifact of the 2026-05-22 standalone strip. They're verifiably dead (grep of `app/` shows only one non-controller Shopify reference), yet they expand the allowlist to every Shopify admin and store.
`─────────────────────────────────────────────────`

# Rate-Limiting, Bot-Protection & CORS Audit — 2026-05-31

**Branch:** development
**Lens:** Rate-limiting coverage on public and auth routes, throttle bypass, CORS misconfig, bot-protection coverage on enquiry/feedback/signup endpoints, abuse surface at 10k-visitor traffic
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `routes/api.php`
- `routes/api/publicSite.php`
- `routes/api/user.php`
- `routes/api/staff.php`
- `app/Http/Middleware/VerifyBotToken.php`
- `app/Http/Middleware/SecureHeaders.php`
- `app/Services/BotProtection/CircuitBreaker.php`
- `app/Services/BotProtection/CaptchaManager.php`
- `app/Providers/AppServiceProvider.php`
- `app/Providers/BotProtectionServiceProvider.php`
- `config/cors.php`
- `config/partna.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 2 complete
- P2 Medium: 0 of 3 complete

---

## P1 — Fix before pilot launch

- [ ] **#RATE-1** · P1 — Handle-availability and login-identifier endpoints open to automated enumeration
    - **Where:** `routes/api.php` (lines for `POST /public/signup/availability` and `POST /public/auth/resolve-identifier`)
    - **Affects:** Unauthenticated visitors; an automated script can map which handles are taken and which email addresses have registered accounts. At 60 req/min per IP, that's 3,600 probes per hour from a single source.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `bot.token:signup` to the middleware array on `POST /public/signup/availability`.
        - Add `bot.token:login-identifier` to the middleware array on `POST /public/auth/resolve-identifier`.
        - Note: this fix only takes real effect once `BOT_PROTECTION_MODE` is set to `shadow` or `enforce` in production (see RATE-2).
    - **Technical:** Every other public mutation endpoint — enquiry, subscribe, waitlist, leads — already carries `bot.token:*` middleware. These two POST routes sit behind only `throttle:public-site` (60 req/min per IP with no per-action keying), which is generous enough for an attacker to enumerate thousands of handles or email addresses per hour without triggering any bot gate. The login-identifier resolver in particular leaks account-existence information that can drive spear-phishing targeting.
    - **Plain English:** Think of these as two "is this seat taken?" windows at the front door. Right now anyone — human or robot — can walk up and ask, thousands of times a minute. Neighbors who submit enquiries or newsletter subscriptions already have to pass a robot-check; these two windows should too.
    - **Evidence:**
        ```php
        Route::post('/public/signup/availability', [PublicSignupAvailabilityController::class, 'check'])
            ->middleware('throttle:public-site');
        Route::post('/public/auth/resolve-identifier', [PublicLoginIdentifierController::class, 'resolve'])
            ->middleware('throttle:public-site');
        ```

- [ ] **#RATE-2** · P1 — `BOT_PROTECTION_MODE` defaults to `off` with no production boot guard — all bot protection is silently disabled
    - **Where:** `config/partna.php:1144`, `app/Http/Middleware/VerifyBotToken.php` (early-return on `$mode === 'off'`), `app/Providers/BotProtectionServiceProvider.php` (boot guards)
    - **Affects:** Every public endpoint carrying `bot.token:*` middleware — enquiry, subscribe, waitlist, leads, customer leads, moderation reports. At 10k-visitor traffic, all of them accept unlimited bot submissions unless the operator explicitly opts in.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a boot guard in `BotProtectionServiceProvider::runBootGuards()` that `Log::warning`s (or throws, if preferred) when `$env === 'production'` and `$mode === 'off'`. Match the tone of the existing `enforce + null` guard.
        - Update `.env.example` so `BOT_PROTECTION_MODE` shows `shadow` as the recommended deployed value, with a comment explaining the three modes. Currently the example file shows `off` with a trailing comment "set enforce in deployed envs" — the comment is not enforced.
        - Coordinate with the frontend team to confirm a valid CAPTCHA widget is wired up before switching from `shadow` to `enforce`.
    - **Technical:** `VerifyBotToken::handle()` short-circuits immediately when `config('partna.bot_protection.mode') === 'off'` — zero verification, zero Redis, zero provider call. The existing boot guard in `BotProtectionServiceProvider` only catches the combination `enforce + null driver`; it never checks for `mode=off` in production. The default value in `config/partna.php` is `env('BOT_PROTECTION_MODE', 'off')`, and `.env.example` ships `BOT_PROTECTION_MODE=off`. A deploy that copies `.env.example` verbatim, or omits the variable, silently bypasses the entire CAPTCHA system despite `bot.token:*` being present on the routes. The `shadow` mode is the safe intermediate — it logs would-be rejections without blocking users, letting you validate CAPTCHA accuracy before hard-enforcing.
    - **Plain English:** You built a CAPTCHA checkpoint at every important door — contact forms, signup, newsletter — but the checkpoint's power switch defaults to off. Anyone who sets up the server without explicitly flipping the switch to `shadow` or `enforce` gets zero protection, even though the middleware is visibly there in the code. Adding a warning when the server starts in production with the switch off catches this before traffic arrives.
    - **Evidence:**
        ```php
        // config/partna.php:1144
        'mode' => env('BOT_PROTECTION_MODE', 'off'),  // off | shadow | enforce
        ```
        ```php
        // app/Http/Middleware/VerifyBotToken.php
        $mode = (string) config('partna.bot_protection.mode', 'off');
        if ($mode === 'off') {
            return $next($request);
        }
        ```
        ```php
        // app/Providers/BotProtectionServiceProvider.php — only guard present
        if ($env === 'production' && $driver === 'null' && $mode === 'enforce') {
            throw new CaptchaConfigurationException(
                'BOT_PROTECTION_DRIVER=null + BOT_PROTECTION_MODE=enforce in production is a silent no-op; ...'
            );
        }
        ```

---

## P2 — Should fix

- [ ] **#RATE-3** · P2 — Public document download throttled only by global IP bucket — no per-document rate control
    - **Where:** `routes/api.php` (`GET /public/documents/{document}/download`)
    - **Affects:** Any party that obtains a document UUID — once known, the URL can be called in a tight loop from a single IP within the 60 req/min `public-site` budget, or across many IPs concurrently, driving unnecessary R2 redirect cost.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a named rate limiter (e.g. `document-download`) in `AppServiceProvider::configureRateLimiting()` keyed on `$request->ip().':doc:'.$request->route('document')`, limiting to something like 10 per UUID per IP per hour.
        - Apply `->middleware('throttle:document-download')` alongside the existing `throttle:public-site`.
        - Do not add `bot.token` — this is a GET endpoint that serves direct links from email, so CAPTCHA would break UX.
    - **Technical:** The route uses `whereUuid('document')` which prevents random enumeration (no valid UUIDs are guessable), but a UUID that has been legitimately shared or leaked provides unlimited download throughput within the 60/min IP window. The controller 302-redirects to a short-TTL R2 presigned URL, so cost is in R2 egress rather than Laravel CPU. A per-`(ip, document)` limiter adds a targeted cost to repeated fetches without touching the general IP bucket or requiring interactive bot checks.
    - **Plain English:** Your document download links use a random, unguessable code — so random bots can't find them. But once someone has the link, there's nothing stopping them from downloading it hundreds of times. Adding a small per-link cap (say, 10 times per hour from the same address) keeps accidental or deliberate loops cheap, without making normal users jump through any hoops.
    - **Evidence:**
        ```php
        Route::get('/public/documents/{document}/download', PublicDocumentDownloadController::class)
            ->whereUuid('document')
            ->middleware('throttle:public-site');
        ```

- [ ] **#RATE-4** · P2 — Dead Shopify CORS patterns remain after the standalone strip — unnecessary allowlist expansion
    - **Where:** `config/cors.php` (`allowed_origins_patterns` array)
    - **Affects:** CORS allowlist: any Shopify Admin origin (`https://admin.shopify.com`) and any Shopify merchant storefront (`https://*.myshopify.com`) can currently make cross-origin requests to the Partna API from a browser context, despite Shopify integration being removed in the 2026-05-22 standalone strip.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Remove the two Shopify pattern entries from `allowed_origins_patterns` in `config/cors.php`.
        - Verify `SecureHeaders::originAllowed()` (which mirrors this array) passes CI after removal — it reads from `config('cors.allowed_origins_patterns', [])` directly.
    - **Technical:** After the 2026-05-22 standalone strip, there are no Shopify webhook handlers, embedded-app controllers, or API surfaces that require Shopify browser origins. A grep of `app/` confirms only `GdprRequest.php` references Shopify (a data-request type enum, not a request handler). `supports_credentials: false` prevents credential forwarding, but the allowlist still permits Shopify-embedded scripts to read unauthenticated API responses and trigger preflight-free simple requests. Dead CORS patterns should be removed rather than left as implicit permissions for a removed integration.
    - **Plain English:** When Shopify was removed from the platform, the door policy was updated everywhere except the guest list. Shopify storefronts and the Shopify admin still appear as "approved callers" in the API's configuration, even though there's no longer anything for them to call. Removing those two entries closes a door that no longer needs to be open.
    - **Evidence:**
        ```php
        // config/cors.php
        'allowed_origins_patterns' => [
            '#^https://[a-z0-9-]+\.partna\.au$#i',
            '#^https://admin\.shopify\.com$#i',          // dead — Shopify stripped 2026-05-22
            '#^https://[a-z0-9-]+\.myshopify\.com$#i',  // dead — Shopify stripped 2026-05-22
        ],
        ```

- [ ] **#RATE-5** · P2 — Shared `public-site` rate limiter creates noisy-neighbour degradation across unrelated endpoints
    - **Where:** `app/Providers/AppServiceProvider.php` (`configureRateLimiting()` → `RateLimiter::for('public-site', ...)`) and all `->middleware('throttle:public-site')` registrations in `routes/api.php`, `routes/api/publicSite.php`, and `routes/web.php`
    - **Affects:** Legitimate visitors to any public-site endpoint — profile viewing, document download, config fetches, QR code rendering — when a different endpoint sharing the same limiter is being abused from the same IP.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create a dedicated `public-signup` limiter (e.g. 10/min per IP) for `POST /public/signup/availability` and `POST /public/auth/resolve-identifier`.
        - Create a dedicated `public-auth` limiter for the identifier resolver — the handle availability check and login-identifier check have different risk profiles.
        - Keep the shared `public-site` limiter for true read-only GETs (site display, config, QR codes, marketing preferences) where 60/min is appropriate.
        - The mutation endpoints (enquiry, subscribe, waitlist, leads) already have dedicated limiters and are not affected by this change.
    - **Technical:** A single 60 req/min IP bucket shared by `POST /public/signup/availability`, `POST /public/auth/resolve-identifier`, `GET /public/site`, `GET /public/documents/.../download`, `GET /public/config/social-platforms`, `GET /public/config/integrations`, and the QR code endpoint means that abusive traffic on any one of those consumes rate-limit budget for all the others. The result is that a bot hammering the availability endpoint can force 429s on a visitor trying to view a public profile from the same corporate NAT or shared-egress IP. Separating high-write POST endpoints into their own limiters prevents this cross-contamination and allows the POST-specific limits to be tighter without penalising benign GET traffic.
    - **Plain English:** Right now all your public pages and "is this name taken?" checks share one ticket counter per visitor IP. A bot hammering the name-check door burns through the counter for everyone behind that IP — including someone who just wants to view a profile page. Giving each type of request its own counter means a bot attacking one door can't accidentally lock out visitors using a different door.
    - **Evidence:**
        ```php
        // AppServiceProvider::configureRateLimiting()
        RateLimiter::for('public-site', function (Request $request) use ($throttleEnabled) {
            if (! $throttleEnabled) {
                return Limit::none();
            }
            return Limit::perMinute(60)
                ->by($request->ip())
                ->response(function () {
                    return response()->json(['message' => 'Too many requests. Please try again later.'], 429);
                });
        });
        ```
        Applied to heterogeneous endpoints including `POST /public/signup/availability`, `POST /public/auth/resolve-identifier`, `GET /public/documents/{document}/download`, `GET /public/config/social-platforms`, `GET /public/config/integrations`, and `GET /p/{professionalId}.svg`.
