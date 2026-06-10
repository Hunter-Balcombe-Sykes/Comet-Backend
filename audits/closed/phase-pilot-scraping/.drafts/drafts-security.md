- [ ] **#SEC-1** · P1 — Google Maps API key exposed at a public, unauthenticated endpoint
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicConfigController.php:60-66
    - **Affects:** Any visitor to the public API — the API key is cacheable for 3600s via CDN. A leaked Maps key enables quota theft and, if not properly referrer-restricted in Google Cloud Console, unauthenticated usage on arbitrary sites.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove `googleMapsApiKey` from this public endpoint. Serve it only from an authenticated dashboard config endpoint, or embed it in the authenticated frontend bundle where the browser's document origin acts as the implicit referrer restriction.
        - If the key must remain here, add a dedicated Resource class or inline filter that strips internal-only fields before return, and reduce the Cache-Control TTL or mark it `private`.
    - **Technical:** The `integrations()` method returns `config('services.google_maps.api_key')` directly with `Cache-Control: public, max-age=3600`. Google Maps API keys are intended to be bound to an HTTP referrer or IP address in the Cloud Console; exposing the raw key at a public, cacheable URL removes the server-side enforcement of that binding. An attacker who obtains the key can exhaust quota from any origin if the Cloud Console restriction is misconfigured or absent. The Partna authorization doctrine requires that secrets never appear in unauthenticated responses; this endpoint is explicitly unauthenticated.
    - **Plain English:** Imagine printing your credit card number on a billboard with a note saying "only valid at one store." If anyone copies the number and the store forgets to check the note, they can spend freely. This endpoint prints the Google Maps key on a publicly accessible URL that anyone can visit and copy.
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
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SEC-2** · P2 — Public integration endpoint returns raw `payload` JSONB without a Resource class allowlist
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php:55-62
    - **Affects:** Every public sitepage visitor — the full platform-connection payload (product lists, discount codes, employee names, service menus, mirrored image URLs, store metadata) is shipped verbatim with no server-side field filtering.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create a `PublicIntegrationConnectionResource` class that whitelists only the fields the Astro sitepage actually renders per platform.
        - Keep the existing `payload` shape for authenticated dashboard endpoints but filter through the Resource on the public path so future fields added to `payload` don't silently become public.
    - **Technical:** `PublicIntegrationController::show()` maps each `IntegrationConnection` row to `['resourceId' => ..., 'payload' => $r->payload, ...]` and returns the result unaltered. The `payload` column is a free-form JSONB blob written by each platform controller; it currently holds public-facing data, but there is no code-level contract preventing a developer from adding a sensitive field (e.g. an internal reference ID, a raw cost price, or a staff member's email) that would then be served to unauthenticated visitors. A Resource class provides a centralized allowlist — the canonical Partna pattern for API response hygiene — and makes future leaks fail the review rather than failing silently in production.
    - **Plain English:** This is like handing a restaurant customer the entire kitchen inventory list instead of just the menu. Right now everything in the kitchen is food-safe, but if someone later stores cleaning supplies on the same shelf, the customer gets those too. A Resource class is a menu — it says "only these specific items leave the kitchen."
    - **Evidence:**
        ```php
        ->map(fn ($rows) => $rows->map(fn (IntegrationConnection $r) => [
            'resourceId' => $r->resource_id,
            'payload' => $r->payload,
            'lastRefreshedAt' => $r->last_refreshed_at?->toIso8601String(),
        ])->values())
        ->toArray();
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#SEC-3** · P2 — Platform controllers bypass the existing `IntegrationConnectionPolicy` — inline query scoping replaces Policy gates
    - **Where:** app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php (entire trait) and all consuming controllers (AppleController, EventbriteController, FacebookController, FreshaController, InstagramController, ShopifyController, TikTokController, YoutubeController)
    - **Affects:** Every platform write/read/delete path — authorization is distributed across controller query scoping rather than centralized in the Policy class.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - In each controller action that reads or mutates an `IntegrationConnection`, call `$this->authorizeForUser($pro, 'verb', $connection)` before the operation. The trait's `connectionFor` already resolves the model; wire it through the Policy gate.
        - Confirm `IntegrationConnectionPolicy` is registered via `Gate::policy()` in `AppServiceProvider::boot()`. Verify each action (view/update/delete/create) maps to the correct Policy method.
    - **Technical:** The Partna authorization doctrine mandates `authorizeForUser` through a `BasePolicy` for all tenant-owned models. `IntegrationConnectionPolicy` exists with correct `ownerMatches` logic, but no platform controller calls it. Instead, the `ManagesIntegrationConnection` trait scopes queries to `$user->integrationConnections()` (a BelongsTo relationship) and hardcodes `user_id` in `updateOrCreate`. While this query-scoping achieves tenant isolation in practice, it violates the single-pattern doctrine: authorization decisions are spread across eight controllers and the trait, making them impossible to audit centrally or test in isolation through the Policy class. A future controller that fetches an `IntegrationConnection` by ID without scoping (e.g. for an admin tool) would have no Policy gate to stop it.
    - **Plain English:** We installed a proper lock on the door (the Policy) but every room has its own separate key hidden under the mat (the query scoping). Right now all the mats are in the right place so nobody walks into the wrong room, but if someone builds a new room and forgets the mat, there's no lock to stop them. Using the Policy means every door gets the same lock, no exceptions.
    - **Evidence:**
        ```php
        // ManagesIntegrationConnection — scopes to user but never authorizes:
        protected function connectionFor(User $user, ?string $resourceId = null): ?IntegrationConnection
        {
            return $user->integrationConnections()
                ->where('platform', $this->platform())
                ->where('resource_id', $resourceId ?? $this->defaultResourceId())
                ->first();
        }

        // IntegrationConnectionPolicy — exists but never called:
        public function view(User $actor, Model $resource): bool|Response
        {
            return $this->ownerMatches($actor, $resource)
                ? true
                : $this->denyAsNotFound();
        }
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#SEC-4** · P2 — Instagram scraper logs the raw Apify API response body, which may contain sensitive data
    - **Where:** app/Services/Platforms/InstagramScraper.php:40-44
    - **Affects:** Log aggregator (Nightwatch) — up to 800 characters of the Apify API response are persisted per non-2xx response. This could include Instagram profile data (full names, bios, post captions) or, in edge cases, token material.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Drop `'body' => mb_substr($response->body(), 0, 800)` from the log context. Log only `status` and `username` for the `not_ok` branch.
        - If response inspection is needed for debugging, log a truncated, sanitised summary (e.g. only the error code/message from the JSON) or gate it behind a `config('app.debug')` check.
    - **Technical:** `InstagramScraper::fetchProfile()` logs the response body on any non-2xx status with `Log::warning('instagram.apify.not_ok', [... 'body' => mb_substr($response->body(), 0, 800)])`. The Apify scraper returns Instagram profile data — full names, bios, post captions — which can contain PII. Log aggregators like Nightwatch persist these messages indefinitely; this conflicts with GDPR data-minimisation principles. The `threw` catch block logs only `$e->getMessage()`, which is safe. The `bad_items` block logs only `type` and `count`, also safe. Only the `not_ok` branch over-logs.
    - **Plain English:** When the Instagram scraper has a bad day and gets an error back from Apify, it writes a snippet of the error response into a permanent log book. That snippet can contain pieces of someone's Instagram profile — like their full name or a post caption. We should only write down what went wrong (the status code), not repeat the whole conversation.
    - **Evidence:**
        ```php
        Log::warning('instagram.apify.not_ok', [
            'username' => $username,
            'status' => $response->status(),
            'body' => mb_substr($response->body(), 0, 800),
        ]);
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SEC-5** · P3 — Platform controllers use inline `$request->validate()` instead of dedicated Form Request classes
    - **Where:** app/Http/Controllers/Api/Platforms/AppleController.php (all actions), EventbriteController.php:40, FacebookController.php:32, FreshaController.php (connect, saveSelection, employeeServices, serviceVisibility), InstagramController.php (connect, saveSelection), ShopifyController.php (addBrand, updateBrand, setProducts), TiktokController.php:30, YoutubeController.php (connect, highlights)
    - **Affects:** Developer velocity and auditability — validation rules are scattered across controllers rather than centralized in Form Request classes. No immediate user impact.
    - **Effort:** M (~2–4h) across all controllers
    - **What to do:**
        - Extract validation rules into dedicated Form Request classes (e.g. `ConnectInstagramRequest`, `SaveShopifyProductsRequest`). Laravel's `authorize()` method in Form Requests is the correct place to wire Policy checks (addressing SEC-3 simultaneously).
        - Apply the Form Request class in the route definition or controller method signature so validation runs before the controller body.
    - **Technical:** The Partna architecture specifies "Form Request classes for all endpoints that accept user input — validation bypass risk." Every platform controller action that accepts user input validates inline via `$request->validate([...])`. While functionally correct, this scatters validation rules and makes it impossible to reuse or test them independently. More critically, Form Request `authorize()` methods are the canonical Laravel location for Policy integration; adding Form Requests now would create a natural home for the `authorizeForUser` calls missing in SEC-3.
    - **Plain English:** Every platform controller has its own handwritten list of "what fields do we accept and what rules apply." If we want to change a rule — say, cap product selections at 200 instead of 250 — we have to find and edit the right line in the right controller. Form Request classes put all the rules for one operation in one place, like a checklist on a clipboard instead of sticky notes on every desk. They also give us a single spot to check "is this person allowed to do this?" which plugs the gap from the previous finding.
    - **Evidence:**
        ```php
        // Representative — same pattern in every platform controller:
        public function connect(Request $request): JsonResponse
        {
            $user = $this->currentUser($request);
            $validated = $request->validate(['artist' => ['required', 'string', 'max:200']]);
            // ...
        }
        ```
    - `[DRAFT, confidence: 0.95]`
