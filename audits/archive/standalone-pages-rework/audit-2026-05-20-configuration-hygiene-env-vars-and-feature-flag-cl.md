`★ Insight ─────────────────────────────────────`
`config/services.php` defaults `STRIPE_API_VERSION` to `'2026-02-25.clover'` while `config/partna.php` defaults the same env var to `'2025-02-24.acacia'`. Two separate fallbacks for one env var — whichever config path is read first in code that doesn't have the env var set can silently get a different API version. This inconsistency is a sub-case of CFG-3 worth calling out specifically.
`─────────────────────────────────────────────────`

# Configuration Hygiene — Env Vars & Feature Flag Cleanliness Audit — 2026-05-20

**Branch:** development
**Lens:** configuration hygiene env vars and feature flag cleanliness
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `.env.example`
- `app/Providers/AppServiceProvider.php`
- `config/app.php`
- `config/auth.php`
- `config/cache.php`
- `config/database.php`
- `config/horizon.php`
- `config/mail.php`
- `config/nightwatch.php`
- `config/partna.php`
- `config/queue.php`
- `config/services.php`
- `config/session.php`
- `config/supabase.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 4 complete
- P3 Low: 0 of 3 complete

---

## P1 — Fix before pilot launch

- [ ] **#CFG-1** · P1 — `.env.example` puts Redis cache on DB 0, colliding with Horizon's queue data
    - **Where:** `.env.example:98` (`REDIS_CACHE_DB=0`) vs `config/database.php` cache connection default
    - **Affects:** Any environment bootstrapped from `.env.example`. `Cache::flush()` issues a raw `FLUSHDB` on DB 0, which also holds Horizon's job metadata, pending jobs, and retry state. This wipes the queue silently with no error.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change `.env.example` to `REDIS_CACHE_DB=1` to match the config's safe default.
        - Cascade the other DB assignments upward: `REDIS_SESSION_DB=2`, `REDIS_QUEUE_DB=3`, `REDIS_CACHE_LOCKS_DB=4` to eliminate a secondary session/cache collision created by the shift. (The `queue` named connection in `database.php` is unused by `config/queue.php` in practice — queue traffic runs through the `default` named connection on DB 0 — so DB 0 should remain exclusive to Horizon/queue.)
        - Optionally add a boot guard in `AppServiceProvider::boot()` that asserts `REDIS_CACHE_DB !== REDIS_DB` in production, matching the pattern already used for throttle, public_domain, Shopify API version, and JWKS fail-closed.
    - **Technical:** `config/database.php` defaults `REDIS_CACHE_DB` to `1` (the safe value). `.env.example` overrides this to `0`, the same DB as `REDIS_DB` (the `default` named connection used by both Horizon and `config/queue.php`'s redis connection). Laravel's Redis cache store calls a raw `FLUSHDB` when `Cache::flush()` is invoked — this is not key-prefix-scoped. The collision means cache maintenance (deploys, test cleanup) silently purges Horizon's job and supervisor state. A secondary collision exists in the current example: after fixing cache to DB 1, both cache and sessions share DB 1 (`REDIS_SESSION_DB=1` is already in the example). All four DB assignments need to be renumbered consistently.
    - **Plain English:** Picture four filing cabinets numbered 0–3 for different purposes — one for the job queue, one for the cache, one for sessions, one for locks. The instruction manual accidentally labels both the job-queue cabinet and the cache cabinet "Cabinet 0." When someone empties Cabinet 0 (a routine cache flush), they also throw out all the pending job slips. The app recovers, but queued work is silently lost. The fix is to relabel the instruction manual so each cabinet has a unique number.
    - **Evidence:**
        ```
        # .env.example:98 — collides cache with Horizon's default connection
        REDIS_CACHE_DB=0
        REDIS_SESSION_DB=1
        REDIS_QUEUE_DB=2
        REDIS_CACHE_LOCKS_DB=3
        ```
        ```php
        // config/database.php — cache connection safe default is 1, not 0
        'cache' => [
            'database' => env('REDIS_CACHE_DB', 1),
        ],
        // default connection (used by Horizon + queue.php redis connection)
        'default' => [
            'database' => env('REDIS_DB', 0),
        ],
        ```
        ```php
        // config/horizon.php — Horizon sits on the 'default' named connection = DB 0
        'use' => 'default',
        ```

---

## P2 — Should fix

- [ ] **#CFG-2** · P2 — Stripe SDK singleton has no boot guard for missing `STRIPE_SECRET_KEY`, yielding a silent empty-dashboard failure mode
    - **Where:** `app/Providers/AppServiceProvider.php` register() closure for `\Stripe\StripeClient::class`
    - **Affects:** Stripe dashboard views (balance, transactions, payouts). Missing key causes `AuthenticationException` that callers silently swallow, presenting as zero balances and empty transaction lists — no log entry, no alert.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a production boot guard alongside the existing four in `AppServiceProvider::boot()`: `if (app()->isProduction() && empty(config('services.stripe.secret_key'))) { throw new \RuntimeException('STRIPE_SECRET_KEY must be set in production.'); }`
        - The guard belongs in `boot()` (not the singleton closure in `register()`) so it fires at startup rather than on the first Stripe call.
    - **Technical:** The `StripeClient` singleton uses `array_filter` to strip falsy values. If `STRIPE_SECRET_KEY` is unset, `api_key` is stripped and the client is constructed without credentials. The inline comment on the binding explicitly acknowledges that downstream `try/catch` blocks "silently swallow `AuthenticationException`, surfacing as empty transactions / zero balance on the dashboard." The existing boot-guard pattern already covers `PARTNA_THROTTLE_ENABLED`, `PARTNA_PUBLIC_DOMAIN`, `SHOPIFY_API_VERSION`, and `SUPABASE_JWKS_FAIL_CLOSED`. Stripe's secret key is a notable gap in that pattern: unlike the other four guards, the Stripe failure is invisible to operators until they notice wrong numbers on the dashboard.
    - **Plain English:** If the Stripe password goes missing from the server's settings, the app doesn't crash or send an alert — it just quietly shows empty revenue figures and a blank transaction list. The other critical settings all have a "refuse to start" check so problems are caught immediately on deploy. Stripe is the only major integration that lacks this check. Adding it means a missing Stripe key causes a clear "won't start" error at deploy time instead of silent wrong numbers in production.
    - **Evidence:**
        ```php
        // app/Providers/AppServiceProvider.php — Stripe singleton, no boot guard
        $this->app->singleton(\Stripe\StripeClient::class, function () {
            return new \Stripe\StripeClient(array_filter([
                'api_key' => config('services.stripe.secret_key'),
                'stripe_version' => config('partna.exports.commission.stripe_api_version'),
            ]));
        });
        // Comment above explains the silent-swallow failure mode but no guard prevents it
        ```
        ```php
        // AppServiceProvider::boot() — four guards exist, Stripe is absent
        if (app()->isProduction() && ! (bool) config('partna.throttle.enabled', true)) { ... }
        if (app()->isProduction() && empty(config('partna.public_domain'))) { ... }
        if (! app()->environment('testing') && empty(config('services.shopify.api_version'))) { ... }
        if (app()->isProduction() && ! (bool) config('supabase.jwks_fail_closed', false)) { ... }
        ```

- [ ] **#CFG-3** · P2 — `config/` fallback defaults contradict `.env.example` at several high-impact points, including a two-version split on `STRIPE_API_VERSION`
    - **Where:** `config/database.php:` `DB_CONNECTION` default; `config/queue.php:` `QUEUE_CONNECTION` default; `config/services.php:` `STRIPE_API_VERSION` default vs `config/partna.php:` same env var; `config/session.php:` `SESSION_DRIVER` default vs `.env.example`
    - **Affects:** Any environment where an env var is omitted or accidentally cleared. The driver-level mismatches (`sqlite` vs `pgsql`, `database` vs `redis`) produce obviously broken apps. The `STRIPE_API_VERSION` split is subtler: `config/services.php` defaults to `2026-02-25.clover`; `config/partna.php` defaults to `2025-02-24.acacia`. Code paths reading one config vs the other can resolve different API versions for the same SDK client.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Align `config/database.php` default for `DB_CONNECTION` to `pgsql` (matches `.env.example` and all real deployments).
        - Align `config/queue.php` default for `QUEUE_CONNECTION` to `redis`.
        - Align `config/session.php` default for `SESSION_DRIVER` to `cookie` (matches `.env.example`).
        - Resolve the `STRIPE_API_VERSION` split: pick one default and use it in both `config/services.php` and `config/partna.php`. The `.env.example` pins `2025-02-24.acacia`; the exports sub-key in `partna.php` also defaults to that. Align `services.php` to match rather than silently defaulting to a newer version.
        - Pin `SHOPIFY_API_VERSION` default in `config/services.php` to match `.env.example` (`2025-01`) or update `.env.example` to `2026-04` if that is the intended pinned version.
    - **Technical:** Laravel's `env()` fallback (second argument) only activates when the variable is entirely absent from the environment. `.env.example` establishes what operators expect the defaults to be. When the two diverge, a deployment that omits a variable (or a test runner that doesn't load `.env`) silently picks up a different driver or API version than intended. The two-fallback split on `STRIPE_API_VERSION` is particularly risky: `config/services.php` has `env('STRIPE_API_VERSION', '2026-02-25.clover')` while `config/partna.php` has `env('STRIPE_API_VERSION', '2025-02-24.acacia')`. Both read the same env var, so in any environment that sets the var these agree — but when the var is missing, code paths reading `services.stripe.api_version` vs `partna.exports.commission.stripe_api_version` silently diverge.
    - **Plain English:** The config files have built-in fallback values for when settings are missing, and the example settings file has its own values. They don't always agree. Most of the time this is harmless because operators set the values explicitly. But two situations cause problems: first, a developer spinning up a fresh environment who forgets to copy `.env.example` gets a local SQLite database instead of PostgreSQL and a database-backed queue instead of Redis — both silently work differently. Second, the Stripe API version has two different fallback values in two different places, so a missing env var can cause the app to use different Stripe API behaviours depending on which code path runs first.
    - **Evidence:**
        ```php
        // config/database.php — fallback is sqlite, .env.example says pgsql
        'default' => env('DB_CONNECTION', 'sqlite'),
        ```
        ```php
        // config/queue.php — fallback is database, .env.example says redis
        'default' => env('QUEUE_CONNECTION', 'database'),
        ```
        ```php
        // config/services.php — Stripe version fallback: 2026-02-25.clover
        'api_version' => env('STRIPE_API_VERSION', '2026-02-25.clover'),
        // config/partna.php — same env var, different fallback: 2025-02-24.acacia
        'stripe_api_version' => env('STRIPE_API_VERSION', '2025-02-24.acacia'),
        // .env.example pins: STRIPE_API_VERSION=2025-02-24.acacia
        ```

- [ ] **#CFG-4** · P2 — `NIGHTWATCH_ENABLED` defaults to `true` while every other feature flag defaults to `false`, risking noisy connection errors on a fresh deploy without a token
    - **Where:** `config/nightwatch.php:` `'enabled' => env('NIGHTWATCH_ENABLED', true)`
    - **Affects:** A fresh deploy where `NIGHTWATCH_TOKEN` is not yet set. Nightwatch attempts to connect to its ingest agent on every request and CLI command without authentication, generating connection-error noise and hiding telemetry gaps behind silence.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change the default to `env('NIGHTWATCH_ENABLED', false)` so Nightwatch is opt-in on a fresh deploy, consistent with every other feature flag in `config/partna.php`.
        - Or add a boot guard: if `NIGHTWATCH_ENABLED` is true in production and `NIGHTWATCH_TOKEN` is empty, throw `RuntimeException`. This matches the style of the four existing guards in `AppServiceProvider::boot()`.
        - `.env.example` already documents `NIGHTWATCH_TOKEN` as a required value — the guard or default change makes the enforcement explicit rather than implicit.
    - **Technical:** Every other on/off knob in `config/partna.php` (`smart_booking`, `square_sync`, `fresha_sync`, `captcha`, `video_uploads_enabled`, `individual_waitlist_enabled`) defaults to `false`. `NIGHTWATCH_ENABLED` defaulting `true` is an inconsistency that means a fresh Laravel Cloud deployment (or a local dev box without a `.env` file) attempts to ship telemetry to the ingest agent before the token is configured. The ingest agent connection is fire-and-forget (0.5s timeout) so the app doesn't crash, but the connection attempt generates noise and the telemetry gap is invisible. Changing to `false` means Nightwatch only activates when explicitly configured.
    - **Plain English:** Every optional feature in this app starts turned off — operators turn things on when they're ready. Nightwatch (the monitoring system) is the only exception: it starts turned on. This means a brand-new server deployment tries to connect to the monitoring system before anyone has given it a password, generating background errors that don't surface anywhere obvious. Making Nightwatch off-by-default like everything else means operators consciously enable monitoring once credentials are in place.
    - **Evidence:**
        ```php
        // config/nightwatch.php — only feature flag that defaults true
        'enabled' => env('NIGHTWATCH_ENABLED', true),
        'token' => env('NIGHTWATCH_TOKEN'),   // no default, can be null
        ```
        ```php
        // config/partna.php — every other flag defaults false
        'smart_booking' => (bool) env('PARTNA_SMART_BOOKING_ENABLED', env('SIDEST_SMART_BOOKING_ENABLED', false)),
        'captcha' => (bool) env('PARTNA_CAPTCHA_ENABLED', env('SIDEST_CAPTCHA_ENABLED', false)),
        'video_uploads_enabled' => (bool) env('PARTNA_VIDEO_UPLOADS_ENABLED', env('SIDEST_VIDEO_UPLOADS_ENABLED', false)),
        ```

---

## P3 — Nice to have

- [ ] **#CFG-5** · P3 — ~30 operational tuning knobs in `config/partna.php` have no `.env.example` entry, making them invisible to operators
    - **Where:** `.env.example` (entire file) vs `config/partna.php`
    - **Affects:** Operators responding to incidents (tightening rate limits, adjusting cache TTLs, tuning batch sizes, changing payout hold windows). These knobs have safe defaults so the app behaves correctly — but finding them requires reading PHP source rather than checking the documented env file.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Sweep every `env()` call in `config/partna.php` and add a commented-out entry to `.env.example` with its default value and a one-line description.
        - Prioritise the incident-response knobs: cache TTL tiers, notification batch chunk size, store commission/fee/payout config, image pixel ceiling, and form timing.
        - Follow the existing convention: commented-out entries so they're discoverable without forcing overrides.
    - **Technical:** `config/partna.php` references roughly 50 distinct env vars. `.env.example` documents about 20 of them. The undocumented ones include all cache TTL tiers (`PARTNA_CACHE_TTL_PUBLIC_PAYLOAD`, `PARTNA_CACHE_TTL_WEBHOOK_IDEMPOTENCY`, etc.), store financial config (`PARTNA_STORE_DEFAULT_COMMISSION`, `PARTNA_STORE_PLATFORM_FEE_PERCENT`), image limits, and payout detail page sizing. All have sensible defaults so the app runs correctly — discoverability is the only gap.
    - **Plain English:** The control panel has about 50 knobs. The instruction manual documents 20 of them. The other 30 are set to reasonable factory defaults but aren't written down anywhere obvious. Everything works fine until an operator needs to change one of the hidden knobs urgently — at that point they have to read the source code rather than looking up a documented setting.
    - **Evidence:**
        ```php
        // config/partna.php — examples with no .env.example entry:
        'enquiry_notification_per_hour' => (int) env('PARTNA_ENQUIRY_NOTIFY_PER_HOUR', env('SIDEST_ENQUIRY_NOTIFY_PER_HOUR', 10)),
        'max_pixels' => (int) env('PARTNA_IMAGE_MAX_PIXELS', env('SIDEST_IMAGE_MAX_PIXELS', 24_000_000)),
        'public_payload' => (int) env('PARTNA_CACHE_TTL_PUBLIC_PAYLOAD', env('CACHE_TTL_PUBLIC_PAYLOAD', 900)),
        'platform_fee_percent' => (float) env('PARTNA_STORE_PLATFORM_FEE_PERCENT', env('SIDEST_STORE_PLATFORM_FEE_PERCENT', 20)),
        'detail_orders_limit' => (int) env('PARTNA_PAYOUTS_DETAIL_ORDERS_LIMIT', 500),
        // ~25 more like these
        ```

- [ ] **#CFG-6** · P3 — `config/mail.php` default sender is the old company identity `info@sight.com` / `Sight`, not `hello@partna.au` / `Partna`
    - **Where:** `config/mail.php` `from` stanza
    - **Affects:** Any environment where `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` are not set. Transactional emails (invites, payouts, notifications) would ship with the wrong sender address and name, potentially triggering SPF/DKIM failures or bounce loops against the defunct domain.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change the fallback to `'hello@partna.au'` and `'Partna'` to match `.env.example`.
    - **Technical:** `config/mail.php` hardcodes `'info@sight.com'` and `'Sight'` as the fallback sender. `.env.example` correctly sets `MAIL_FROM_ADDRESS="hello@partna.au"`. An environment that omits this variable (e.g. a staging deploy that partially copies `.env.example`) silently uses the stale origin. Since Partna's email pipeline goes through Resend, SPF/DKIM alignment is domain-bound — mail from `@sight.com` will fail alignment checks for the `partna.au` domain.
    - **Plain English:** Every envelope sent by this app has a return address printed on it. The fallback return address defaults to the old company's name and email — a domain Partna no longer operates. Any server that forgets to set the sender address will have its mail rejected or sent to the wrong reply-to. The fix is a one-line change to the default so it matches the current business identity.
    - **Evidence:**
        ```php
        // config/mail.php — stale identity in fallback values
        'from' => [
            'address' => env('MAIL_FROM_ADDRESS', 'info@sight.com'),
            'name' => env('MAIL_FROM_NAME', 'Sight'),
        ],
        ```
        ```
        # .env.example — correct current identity
        MAIL_FROM_ADDRESS="hello@partna.au"
        MAIL_FROM_NAME="${APP_NAME}"
        ```

- [ ] **#CFG-7** · P3 — `config/auth.php` configures a session guard, Eloquent user model, and password reset flow that are permanently dead code under Supabase JWT auth
    - **Where:** `config/auth.php:` `guards`, `providers`, `passwords` stanzas
    - **Affects:** Developer onboarding. New contributors who follow standard Laravel auth documentation will call `Auth::user()`, get `null`, and spend debugging time in `config/auth.php` before discovering the architecture note that `Auth::user()` is always null in this app.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Strip the `guards`, `providers`, and `passwords` stanzas down to a minimal stub with an explicit comment: `// Auth::user() is always null — authentication resolves via VerifySupabaseJwt middleware; see app/Http/Middleware/Auth/VerifySupabaseJwt.php`.
        - Remove `AUTH_GUARD`, `AUTH_PASSWORD_BROKER`, `AUTH_MODEL`, and `AUTH_PASSWORD_TIMEOUT` from the config to eliminate the temptation to configure these.
    - **Technical:** The Partna architecture canonical states "`Auth::user()` ALWAYS returns null. Resolved actor lives at `$request->attributes->get('professional')`." Yet `config/auth.php` configures a `web` guard (session driver), an Eloquent `users` provider pointing at `App\Models\User`, and a password reset flow — none of which are called by any middleware or controller in this codebase. The config is inert at runtime but actively misleading at read time. Removing the stanzas makes the constraint explicit rather than hidden in a doc file.
    - **Plain English:** The app uses a badge-swipe keycard system for authentication, but the config file still describes a standard key lock in detail — complete with instructions for cutting new keys and resetting forgotten ones. None of it connects to anything. A new developer reading the config will assume the key lock works and spend time debugging it before discovering the keycard system. Replacing the key-lock description with a sign saying "we use keycards — see X file" saves that confusion.
    - **Evidence:**
        ```php
        // config/auth.php — entire section is dead code in a Supabase JWT app
        'guards' => [
            'web' => [
                'driver' => 'session',
                'provider' => 'users',
            ],
        ],
        'providers' => [
            'users' => [
                'driver' => 'eloquent',
                'model' => env('AUTH_MODEL', App\Models\User::class),
            ],
        ],
        'passwords' => [
            'users' => [
                'provider' => 'users',
                'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
                'expire' => 60,
                'throttle' => 60,
            ],
        ],
        ```
