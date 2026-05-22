- [ ] **#SEC-1** · P1 — `HydrogenDeploymentController::targets()` returns deployment tokens for ALL brands without per-tenant scoping
    - **Where:** app/Http/Controllers/Api/Internal/HydrogenDeploymentController.php:28-47
    - **Affects:** Every brand's Oxygen/Hydrogen deployment pipeline. A compromise of the `VerifyHydrogenApiKey` secret exposes every brand's deployment token, giving the attacker the ability to deploy arbitrary code to all brand storefronts.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Require a `professional_id` filter on un-scoped calls so "deploy everyone" is an explicit opt-in via a separate internal-only endpoint or a dedicated CI secret.
        - At minimum, log a high-severity alert when the endpoint is called without a `professional_id` filter so ops can detect anomalous bulk-fetch.
    - **Technical:** The endpoint is gated by `VerifyHydrogenApiKey` (internal middleware), but once past that gate, it returns every row in `brand_store_settings` that has an `oxygen_deployment_token` — the token is decrypted in-flight by the model's encrypted cast. No tenant-resolution occurs; the controller does not read `embedded_professional_id` or apply any per-tenant policy. The blast radius of a single API-key compromise is every brand's Hydrogen storefront, not one.
    - **Plain English:** Think of this as a building where one key opens a utility closet — but that closet contains the master keys to every apartment. If someone gets the utility key, they can redecorate (or vandalize) every unit. The fix is to require the apartment number to be specified when fetching the keys, and to sound an alarm if someone tries to grab all of them at once.
    - **Evidence:**
        ```php
        $query = BrandStoreSettings::query()
            ->whereNotNull('oxygen_deployment_token');

        // Single-brand deploy: the workflow_dispatch trigger passes a
        // professional_id to filter to just the brand that saved credentials.
        if ($professionalId = $request->query('professional_id')) {
            $query->where('professional_id', $professionalId);
        }

        $settings = $query->get(['professional_id', 'oxygen_deployment_token', 'oxygen_storefront_id']);
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SEC-2** · P2 — `PublicSignupAvailabilityController::check()` enables email/phone/handle enumeration without rate limiting
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicSignupAvailabilityController.php:17-60
    - **Affects:** Every prospective and current user's privacy — an attacker can iterate through candidate emails, phones, or handles to map Partna's user base.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `throttle:10,1` (10 attempts per minute per IP) middleware to the route, or wrap the DB queries in `RateLimiter::attempt()`.
        - Consider returning a generic "check complete" response that does not distinguish availability per field when the caller exceeds the rate limit, so the enumeration signal is suppressed under load.
    - **Technical:** The endpoint performs three independent `exists()` queries (email, phone, handle) and returns per-field boolean `available`/`exists` flags. With no rate limiting, a single IP can issue thousands of requests per minute to probe registered emails. The email lookup uses `whereRaw('LOWER(primary_email) = ?', [$email])` — a case-insensitive exact match with no hashing delay, making it fast enough for high-throughput enumeration.
    - **Plain English:** This is like a nightclub where the bouncer tells anyone who asks whether a specific person is inside. Without a limit on how often someone can ask, a patient attacker can work through a list of names and build a complete guest list. Adding a "slow down after too many questions" rule closes the leak.
    - **Evidence:**
        ```php
        $emailExists = false;
        if ($email) {
            $emailExists = Professional::query()
                ->where(function ($query) use ($email) {
                    $query->whereRaw('LOWER(primary_email) = ?', [$email])
                        ->orWhereRaw('LOWER(public_contact_email) = ?', [$email]);
                })
                ->exists();
        }

        return $this->success([
            'email' => [
                'available' => ! $emailExists,
                'exists' => $emailExists,
            ],
            // ... phone and handle_lc returned the same shape
        ]);
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#SEC-3** · P2 — `EmbeddedConnectController::connect()` accepts connection-code brute-force without rate limiting
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedConnectController.php:27-33
    - **Affects:** Brands performing the Shopify connect step. An attacker flooding connection-code attempts can exhaust valid codes from Redis (`Cache::pull` deletes the key on ANY lookup, valid or not), causing a denial-of-service on the embedded setup flow.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the connection-code lookup with `RateLimiter::attempt()` keyed by shop domain or IP, returning 429 when the threshold is exceeded.
        - Consider switching from `Cache::pull` to a two-step verify-then-consume pattern so invalid guesses do not destroy a valid pending code.
    - **Technical:** `Cache::pull("shopify:embed:connect:{$code}")` atomically reads and deletes the key regardless of whether the returned `$professionalId` is null. An attacker who sprays random 6-character codes can invalidate brand-generated connection codes before the legitimate brand completes the flow. The connection codes have a 30-minute TTL; without rate limiting, the code space can be exhausted well within that window.
    - **Plain English:** The system uses disposable one-time tickets for connecting a Shopify store. The problem is that ANY attempt to use a ticket tears it up — even a wrong guess. Without a limit on how many guesses someone can make, a bad actor can rip up all the valid tickets before the real brand gets to use theirs.
    - **Evidence:**
        ```php
        $code = trim((string) $request->input('code', ''));
        if ($code === '') {
            return $this->error('Connection code is required.', 422);
        }

        // Look up the professional_id stored against this code in Redis (30 min TTL).
        $professionalId = Cache::pull("shopify:embed:connect:{$code}");
        if (! $professionalId) {
            return $this->error('Invalid or expired connection code. Please generate a new one from your Partna dashboard.', 422);
        }
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#SEC-4** · P2 — `PublicConfigController::integrations()` exposes Google Maps API key on an unauthenticated, cacheable endpoint
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicConfigController.php:46-53
    - **Affects:** The Google Cloud billing account — if the API key lacks HTTP-referrer restrictions, any third party can consume the quota. Even with referrer restrictions, the key is visible in CDN caches and browser dev tools.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the Google Maps key from the unauthenticated config endpoint; serve it only from the Hydrogen brand-config endpoint (already authenticated via API key) or via a server-side environment variable injected at deploy time.
        - Document a hard requirement in the provisioning runbook: the Google Maps API key MUST have HTTP-referrer restrictions locked to `*.partna.au` and the Shopify admin.
    - **Technical:** The endpoint responds with `Cache-Control: public, max-age=3600` and no authentication. The Google Maps API key is a `config()` read — if the key lacks referrer restrictions in the Google Cloud Console, it can be extracted from the response and used from any origin to consume Maps JavaScript API quota. Even with referrer restrictions, CDN-cached responses retain the key.
    - **Plain English:** A public bulletin board that anyone can read has a spare key to the company car taped to it. The note says "only use this key if you're standing in the company parking lot" — but anyone who copies the key can try it from anywhere. If the lock doesn't actually check where you're standing, the car gets driven away.
    - **Evidence:**
        ```php
        public function integrations(): JsonResponse
        {
            return response()
                ->json([
                    'googleMapsApiKey' => config('services.google_maps.api_key'),
                ])
                ->header('Cache-Control', 'public, max-age=3600');
        }
        ```
    - `[DRAFT, confidence: 0.75]`

- [ ] **#SEC-5** · P2 — `PublicDocumentDownloadController` skips subdomain isolation when `X-Site-Subdomain` header is absent
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicDocumentDownloadController.php:26-33
    - **Affects:** Document security — a leaked document UUID allows download from any context, bypassing the site-published check's subdomain enforcement.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the "absent header = unenforced" fallback in production. Require `X-Site-Subdomain` on all document requests, or resolve the site from the request host directly.
        - If internal/test callers need to bypass the header check, gate it explicitly on `app()->environment('testing', 'local')` rather than silently skipping.
    - **Technical:** The controller checks `$request->header('X-Site-Subdomain')` and cross-validates against the document's site subdomain — but ONLY when the header is present. The comment states: "Absent header = unenforced (used only by internal/test callers that bypass routing)." In production, any caller omitting the header can access any document by UUID, regardless of which site owns it. Document UUIDs are unguessable (UUIDv4), but a leaked UUID from a share, screenshot, or log remains valid across all sites.
    - **Plain English:** Each document lives in a specific apartment, and the front door checks your apartment number when you try to download. But if you don't state your apartment number, the door just lets you in to whichever document you name. A document ID is hard to guess, but if one leaks (someone forwards a link), the "which apartment" check evaporates.
    - **Evidence:**
        ```php
        // Enforce subdomain isolation when the caller provides an X-Site-Subdomain
        // header (all public-site frontend requests do). Absent header = unenforced
        // (used only by internal/test callers that bypass routing).
        $requestedSubdomain = trim((string) $request->header('X-Site-Subdomain', ''));
        if ($requestedSubdomain !== '') {
            abort_unless(
                strtolower($site->subdomain) === strtolower($requestedSubdomain),
                404
            );
        }
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#SEC-6** · P2 — `BootstrapController` email-uniqueness check races against concurrent signups, surfaces a 500 instead of a clean 409
    - **Where:** app/Http/Controllers/Api/PublicSite/BootstrapController.php:113-122 (inside transaction), with catch at line ~180
    - **Affects:** User experience during concurrent signup with the same email address. Under race conditions, the second signup receives a 500 error instead of a helpful "email already registered" 409.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Catch `UniqueConstraintViolationException` inside the transaction and re-throw as `RuntimeException('EMAIL_ALREADY_REGISTERED')` so the outer catch-block maps it to a clean 409.
        - Alternatively, move the email-exists check to use `lockForUpdate()` on a dedicated row or rely solely on the DB unique index with proper exception translation.
    - **Technical:** The `$existingByEmail` check runs inside `DB::transaction()` at default READ COMMITTED isolation. A concurrent transaction can insert the same email between the SELECT and the INSERT. PostgreSQL's UNIQUE index on `primary_email` catches this and throws `UniqueConstraintViolationException`. The outer catch block only handles `RuntimeException` with the exact message `'EMAIL_ALREADY_REGISTERED'`. `UniqueConstraintViolationException` extends `RuntimeException` but carries a SQL error message, not the expected string, so it falls through to `Log::error(...)` and re-throws → 500.
    - **Plain English:** Two people try to sign up with the same email at the exact same moment. The system checks "is this email taken?" and both get a "no" before either finishes saving. The database catches the double-booking at the last instant, but instead of saying "that email is already taken" nicely, it throws a confusing technical error. The fix is to translate that last-instant database rejection into the same friendly message the normal path uses.
    - **Evidence:**
        ```php
        // Inside DB::transaction():
        $existingByEmail = Professional::query()
            ->whereRaw('lower(primary_email) = ?', [$emailLc])
            ->where('auth_user_id', '!=', $uid)
            ->exists();

        if ($existingByEmail) {
            throw new RuntimeException('EMAIL_ALREADY_REGISTERED');
        }

        // ... $professional->save(); — can throw UniqueConstraintViolationException

        // Outside transaction:
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'EMAIL_ALREADY_REGISTERED') {
                // returns clean 409
            }
            throw $e; // UniqueConstraintViolationException → 500
        }
        ```
    - `[DRAFT, confidence: 0.7]`

- [ ] **#SEC-7** · P3 — `PublicCustomerLeadController` and `PublicEnquiryController` client-supplied `form_started_at_ms` timing check is trivially bypassable
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicCustomerLeadController.php:31-39, app/Http/Controllers/Api/PublicSite/PublicEnquiryController.php:29-38
    - **Affects:** Spam/bot detection fidelity. The timing check is a defense-in-depth measure that relies on the client to honestly report when the form was opened — a scripted bot can set this to any value that passes the window.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a server-side nonce embedded in the form that expires quickly (server tracks when it was issued), replacing the client-supplied timestamp.
        - Or accept that the client-supplied timing is a weak signal and supplement it with server-side rate limiting (already present for signup codes); document the limitation clearly.
    - **Technical:** The `$data['form_started_at_ms']` field is set by client-side JavaScript and validated server-side against `config('partna.form_timing.min_ms')` / `max_ms`. A scripted bot can compute `Date.now() - 4000` and submit it, always landing inside the valid window. The check catches only naive bots that ignore the field entirely; it does not protect against purpose-built spam tooling.
    - **Plain English:** The form has a hidden stopwatch that asks "how long did the human spend filling this out?" But the stopwatch trusts the browser to report the start time honestly. A bot can simply lie — "oh, I definitely spent 4 seconds on this" — and walk right past. It catches only the laziest bots that forget to lie.
    - **Evidence:**
        ```php
        $startedMs = $data['form_started_at_ms'] ?? null;
        if (is_int($startedMs)) {
            $nowMs = (int) floor(microtime(true) * 1000);
            $delta = $nowMs - $startedMs;

            $minMs = (int) config('partna.form_timing.min_ms', 2500);
            $maxMs = (int) config('partna.form_timing.max_ms', 12 * 60 * 60 * 1000);

            if ($delta < $minMs || $delta > $maxMs) {
                // ... reject as spam
            }
        }
        ```
    - `[DRAFT, confidence: 0.9]`
