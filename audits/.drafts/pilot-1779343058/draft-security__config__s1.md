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
