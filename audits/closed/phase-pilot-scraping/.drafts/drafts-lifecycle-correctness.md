- [ ] **LIFE-1** · P1 — Instagram Apify daily counter has a read-modify-write race, cap can be exceeded at scale
    - **Where:** app/Http/Controllers/Api/Platforms/InstagramController.php (guardApifyBudget method)
    - **Affects:** All brands refreshing Instagram — the daily Apify budget cap of 200 runs can be silently exceeded under concurrent refresh requests, directly increasing per-scrape costs at the scale target (200 brands × daily refresh).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Cache::get` → `Cache::put` with `Cache::increment` (atomic) guarded by `Cache::add` for the initial EXPIRE set.
        - Add a `Log::warning` when the cap is hit so ops can see budget pressure before the invoice arrives.
    - **Technical:** Category (2) — canonical `lockForUpdate + UNIQUE`. The pilot comment itself reads "read-modify-write (good enough for a pilot — backend dev to harden)". Two concurrent requests both read `count=199`, both pass the `if ($count >= 200)` check, both write `count=200`. In Postgres this needs `SELECT ... FOR UPDATE`; in Redis `INCR` is atomic and `INCR` + `EXPIRE` in a Lua script or two-step with `SETNX` for the TTL is the correct low-overhead fix. At 200 brands each doing daily Instagram refresh, the concurrent-refresh window is real.
    - **Plain English:** Imagine a bouncer at a club with a clicker counter. Two people walk up at the exact same moment; the bouncer checks the counter (199), lets both in, and clicks twice — now the counter reads 201 when the fire limit is 200. The fix is a turnstile that clicks as each person passes through, so two people can't slip in on the same reading.
    - **Evidence:**
        ```php
        $dayKey = 'platforms:instagram:apify-daily:'.now()->format('Y-m-d');
        $count = (int) Cache::get($dayKey, 0);
        if ($count >= self::APIFY_DAILY_CAP) {
            return $this->error('Instagram is busy right now — please try again later.', 429);
        }
        Cache::put($dayKey, $count + 1, now()->addDay());
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **LIFE-2** · P1 — Fresha `fetchEmployeeServices` swallows all exceptions silently — hash/version rotation causes invisible failure at scale
    - **Where:** app/Http/Controllers/Api/Platforms/FreshaController.php (fetchEmployeeServices method)
    - **Affects:** Every brand using Fresha with a per-employee service menu. When Fresha redeploys their frontend and rotates the persisted-query hash, per-employee service fetches fail silently for ALL affected brands, with zero observability — callers fall back to the whole-location menu and neither the brand nor ops knows it degraded.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `Log::warning('fresha.employee_services.failed', ['error' => $e->getMessage()])` inside the catch block.
        - Increment `consecutive_failures` on the connection row so the dashboard can surface "Fresha needs reconnection" after N consecutive failures.
    - **Technical:** Category (6) + (10) — canonical `verbatim vendor error capture` + `Log-with-context`. The `catch (Throwable) { return null; }` pattern is a swallowed exception — Nightwatch never sees it, ops never knows the hash rotated, and the fallback masks the degradation silently. The hardcoded `BOOKING_INIT_HASH` and `FRESHA_CLIENT_VERSION` constants make this an inevitability, not an edge case — Fresha redeploys their frontend regularly. The connection row has `last_refresh_error` and `consecutive_failures` columns purpose-built for exactly this signal, but they're never written in this path.
    - **Plain English:** A restaurant menu system has a cheat sheet to find each waiter's section. When the restaurant renovates, the cheat sheet is wrong but the system quietly serves the full menu instead of the waiter's section — without telling anyone the cheat sheet is broken. The fix is to leave a note for the manager each time the cheat sheet fails, so they know to print a new one.
    - **Evidence:**
        ```php
        try {
            $response = Http::withHeaders([
                'content-type' => 'application/json',
                'x-client-platform' => 'web',
                'x-client-version' => self::FRESHA_CLIENT_VERSION,
                'x-graphql-operation-name' => 'mutation BookingFlow_Initialize_Mutation',
                'origin' => 'https://www.fresha.com',
                'User-Agent' => self::SCRAPE_USER_AGENT,
            ])->timeout(12)->post(self::GRAPHQL_URL, $payload);
        } catch (Throwable) {
            return null;
        }
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **LIFE-3** · P1 — Shopify brand cap has a read-modify-write race — concurrent adds can exceed `MAX_BRANDS`
    - **Where:** app/Http/Controllers/Api/Platforms/ShopifyController.php (addBrand method)
    - **Affects:** Users connecting multiple Shopify brands via concurrent dashboard tabs or retried requests. The cap of 5 brands can be exceeded, and the brand map payload stored as a single JSONB blob has no row-level locking.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap the read-check-write sequence in `DB::transaction` with `lockForUpdate` on the user's IntegrationConnection row for platform='shopify'.
        - Or add a database-level constraint: a `CHECK` on the JSONB array cardinality enforced via a trigger or application-level advisory lock keyed to `(user_id, 'shopify')`.
    - **Technical:** Category (2) — canonical `lockForUpdate + UNIQUE`. Two dashboard tabs both POST `/brands` simultaneously. Both read the brand map (4 brands), both pass the `count($map) >= 5` check, both write a map with 6 entries. The `updateOrCreate` in `writeConnection` targets the single row per `(user_id, platform, resource_id)`, but `addBrand` writes the entire multi-brand map as one JSONB payload — the second writer's map overwrites the first, and the cap was never enforced atomically. At 50 affiliates/brand at the scale target, a multi-tab brand management workflow is normal.
    - **Plain English:** A bouncer with a clipboard checks the guest list (4 names), sees there's room for one more, and adds a name. A second bouncer at the side door does the same thing at the same moment — now the list has 6 names when the venue limit is 5. The fix is to have one clipboard that only one bouncer can hold at a time.
    - **Evidence:**
        ```php
        $map = $this->brandMap($user);
        $id = $brand['id'];
        if (! isset($map[$id]) && count($map) >= self::MAX_BRANDS) {
            return $this->error('You can connect up to '.self::MAX_BRANDS.' brands.', 422);
        }
        $map[$id] = [
            'id' => $id,
            'url' => $origin,
            'name' => $brand['name'],
            // ...
        ];
        $this->writeConnection($user, $map);
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **LIFE-4** · P2 — `PlatformRefresher` never populates `last_refresh_error` — verbatim vendor failures are lost
    - **Where:** app/Services/Platforms/PlatformRefresher.php (refresh method, all four private `*Payload` methods)
    - **Affects:** Operators debugging why a brand's YouTube/Eventbrite/Apple content stopped updating. The column `last_refresh_error` exists on `site.platform_connections` but is never written — only `last_refresh_status = 'unavailable'` is set. The actual scraper failure reason (HTTP status, timeout, parse error) evaporates.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Have each scraper method (`fetchRecentVideos`, `fetchEvents`, `fetchAlbums`, `fetchEpisodes`) return a typed result or throw a typed exception with the verbatim error, rather than returning `null`.
        - Store the verbatim error message in `last_refresh_error` inside `refresh()` before setting `last_refresh_status = 'unavailable'`.
    - **Technical:** Category (6) — canonical `verbatim vendor error capture`. The `#STRIPE-3` fix (`bf6e46d`) established that vendor errors must be stored verbatim on the failing record. Here, the scrapers return `null` on any failure, discarding the reason. `PlatformRefresher::refresh()` sets `last_refresh_status = 'unavailable'` and increments `consecutive_failures` but leaves `last_refresh_error` untouched — meaning the column the schema purpose-built for debugging is always NULL. At the scale target (~200 connections refreshed daily), silent intermittent failures accumulate with no forensic trail.
    - **Plain English:** A delivery driver comes back empty-handed every day and marks "failed" on the clipboard without saying why — flat tire? Wrong address? Store closed? The clipboard has a column for "reason" but nobody fills it in. The fix is to write down what went wrong each time so the dispatcher can see patterns.
    - **Evidence:**
        ```php
        if ($next === null) {
            $connection->forceFill([
                'last_refresh_status' => 'unavailable',
                'consecutive_failures' => (int) $connection->consecutive_failures + 1,
            ])->saveQuietly();
            return $connection;
        }
        ```
        ```php
        // Schema column exists but is never written:
        // last_refresh_error    text,
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **LIFE-5** · P2 — Six platform controllers use inline `$request->validate()` instead of Form Request classes
    - **Where:** app/Http/Controllers/Api/Platforms/ — AppleController, EventbriteController, FacebookController, FreshaController, ShopifyController, TiktokController, YoutubeController (7 controllers, 15+ validation sites total)
    - **Affects:** Testability and consistency of validation across the platform integration surface. Validation rules are inlined in controller methods, making them untestable in isolation and harder to audit for consistency. At 200 brands, a validation-rule drift between e.g. two controllers accepting a `url` field would produce inconsistent error shapes for the dashboard.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extract each controller's validation rules into dedicated Form Request classes (e.g., `ConnectAppleMusicRequest`, `SaveFreshaSelectionRequest`).
        - Follow the `a11feb2` refactor pattern — one Form Request per distinct operation.
    - **Technical:** Category (7) — canonical `Policy + Form Request`. The `a11feb2` refactor established Form Request classes as the validation standard. These platform controllers were built in test-mode velocity and never refactored. Inline `$request->validate([...])` calls scatter rules across 7 files, making it impossible to write a unit test for "does the Fresha URL regex reject non-Fresha domains?" without booting the full HTTP stack. At the scale target, the dashboard needs consistent 422 error shapes across all platform endpoints.
    - **Plain English:** Every store in a chain has its own handwritten "please check ID" sign at the door, each worded slightly differently. Some check birth year, some check expiry, some don't check at all. The fix is a standard sign printed by head office and posted at every door — one place to update when the law changes.
    - **Evidence:**
        ```php
        // AppleController.php
        $validated = $request->validate(['artist' => ['required', 'string', 'max:200']]);
        $validated = $request->validate(['show' => ['required', 'string', 'max:200']]);
        $validated = $request->validate(['albumIds' => ['present', 'array', 'max:'.self::MAX_HIGHLIGHTS], ...]);
        
        // FreshaController.php
        $validated = $request->validate(['url' => ['required', 'string', 'max:500', 'regex:'.self::URL_PATTERN]]);
        $validated = $request->validate(['employeeId' => ['required', 'string', 'max:50']]);
        $validated = $request->validate(['serviceId' => ['required', 'string', 'max:50'], 'hidden' => ['required', 'boolean']]);
        
        // ShopifyController.php
        $validated = $request->validate(['url' => ['required', 'string', 'max:500', 'url']]);
        $validated = $request->validate(['discountCode' => ['present', 'nullable', 'string', 'max:100']]);
        $validated = $request->validate(['productIds' => ['present', 'array', 'max:250'], ...]);
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **LIFE-6** · P2 — `RefreshIntegrationConnectionsCommand` silent reconcile — successful updates are invisible, only failures are logged
    - **Where:** app/Console/Commands/RefreshIntegrationConnectionsCommand.php (handle method)
    - **Affects:** Operators monitoring the daily cron — when the cron catches a stale YouTube video that the RSS feed updated hours ago, the "caught up" event is invisible. Only aggregate counts are logged: "300 ok, 0 failed." If 5 of those 300 actually updated content (the other 295 were no-ops), operators can't distinguish a working refresh from a scrape that returned the same content.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a per-connection `Log::info` (or a dedicated `Log::debug` with a count summary) when the payload actually changed.
        - Track and report "refreshed with new content" vs "refreshed but unchanged" as separate counters in the command output.
    - **Technical:** Category (4) — canonical `daily reconcile job`. The `0de1f2f` pattern (`ReconcileStuckTransferringPayoutsJob`) established that reconcile jobs must log when they catch a missed delivery. Here, the refresh cron IS the reconcile loop for scraped content (the scrapers are the "vendor"), but successful reconciles are silent. The `$refreshed->last_refresh_status === 'ok'` only tells us the scrape didn't fail — not whether it actually changed anything. At the scale target with ~200 connections refreshed daily, operators need to know if the cron is doing real work or just burning CPU.
    - **Plain English:** A night-shift security guard walks the building and checks every door. In the morning, the report says "checked 200 doors, all OK." But 5 of those doors were actually unlocked and the guard locked them — the report doesn't say which ones, so the day shift doesn't know which wing had the problem. The fix is to note which doors needed locking, not just that the round was completed.
    - **Evidence:**
        ```php
        $ok = 0;
        $failed = 0;
        foreach ($connections as $connection) {
            try {
                $refreshed = $refresher->refresh($connection);
                $refreshed->last_refresh_status === 'ok' ? $ok++ : $failed++;
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('integrations:refresh failed for a connection', [...]);
            }
        }
        $this->info("Platform connections refreshed: {$ok} ok, {$failed} failed (of {$connections->count()} stale).");
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **LIFE-7** · P2 — Fresha hash/version constants are hardcoded — rotation requires a code deploy
    - **Where:** app/Http/Controllers/Api/Platforms/FreshaController.php (class constants)
    - **Affects:** All brands using Fresha per-employee service menus. When Fresha redeploys (every 1-2 weeks), the `BOOKING_INIT_HASH` and `FRESHA_CLIENT_VERSION` become stale. The only recovery path is a code deploy — there's no config override, feature flag, or runtime fallback that fetches a fresh hash. Combined with LIFE-2 (swallowed exceptions), the failure is completely silent.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move `BOOKING_INIT_HASH` and `FRESHA_CLIENT_VERSION` to `config/services.php` under a `fresha` key.
        - Add a `fresha.hash_stale` alert in Nightwatch: if `fetchEmployeeServices` returns null for >N consecutive runs across >M brands, page ops so the hash can be updated via config deploy or env variable without a full code deploy.
    - **Technical:** Category (6) — canonical `vendor API version pinning`. The `9a9b107` pattern (`STRIPE_API_VERSION` env) established that vendor version pins must be runtime-configurable, not hardcoded. Fresha's GraphQL persisted-query hash is their equivalent of an API version — it rotates when they deploy their frontend. A hardcoded constant requires a full Laravel deploy to update; moving it to config means ops can hotfix via `php artisan config:cache` after a `config/services.php` edit, or even via an env variable in Cloud.
    - **Plain English:** The restaurant's cheat sheet for finding each waiter's section is photocopied and stapled into every server's training manual. When the restaurant renovates, every manual needs a new staple — instead of just posting one updated sheet on the wall that everyone reads from.
    - **Evidence:**
        ```php
        private const BOOKING_INIT_HASH = '4ea9d1b31075d62f789fcec884c45d76aaeb42e56ffb1b78cc1b7f7c557ad7cb';
        private const FRESHA_CLIENT_VERSION = 'd135e4b3a3be51f9dd24f5cc2af6dd6a647f85dd';
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **LIFE-8** · P2 — `IntegrationConnectionObserver` swallows purge errors — edge cache purges can silently fail at scale
    - **Where:** app/Observers/Core/IntegrationConnectionObserver.php (purge method)
    - **Affects:** All public sitepages. When platform connections are created/updated/deleted, the observer dispatches a `CloudflareCachePurgeJob` to bust the edge cache. If the user has no site (null subdomain — e.g., user created but site not yet provisioned, or a cascading deletion race), the purge silently no-ops with zero observability. At 200 brands, this is a common edge case during onboarding.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Log a `Log::debug` (not warning — this is an expected edge case during onboarding) when the user has no site, including `user_id` and `platform_connection_id`.
        - Remove the outer try/catch — the `CloudflareCachePurgeJob::dispatch()` is a local queue push that doesn't throw under normal operation, and the `User::query()` failure is the only real risk. If it must stay, add `brand_professional_id` to the warning context so Nightwatch can correlate.
    - **Technical:** Category (10) — canonical `Log-with-context`. The observer does a `User::query()->with('site')->find($connection->user_id)?->site?->subdomain` and dispatches the purge only when `$subdomain` is truthy. If the user was deleted between the connection write and the observer fire (cascade race), or if the user's site hasn't been provisioned yet (onboarding), the purge silently no-ops. The outer `catch (\Throwable $e)` logs a warning but without `brand_professional_id` in context, Nightwatch can't correlate it to a specific brand. At the scale target, this edge case fires regularly during onboarding flows.
    - **Plain English:** A mailroom clerk is told "deliver this package to the customer's store." The clerk looks up the address, finds it blank, and quietly puts the package back on the shelf without telling anyone. The fix is to leave a sticky note saying "customer #123 has no store address yet" so the onboarding team can follow up.
    - **Evidence:**
        ```php
        private function purge(IntegrationConnection $connection): void
        {
            try {
                $subdomain = User::query()
                    ->with('site')
                    ->find($connection->user_id)
                    ?->site?->subdomain;

                if ($subdomain) {
                    CloudflareCachePurgeJob::dispatch($subdomain);
                }
            } catch (\Throwable $e) {
                Log::warning('IntegrationConnectionObserver purge failed', [
                    'platform_connection_id' => $connection->id,
                    'user_id' => $connection->user_id,
                    'message' => $e->getMessage(),
                ]);
            }
        }
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **LIFE-9** · P2 — `InstagramScraper` logs lack `brand_professional_id` and `request_id` context
    - **Where:** app/Services/Platforms/InstagramScraper.php (fetchProfile method)
    - **Affects:** Nightwatch correlation — when an Apify scrape fails, the log line can't be traced back to a specific brand or request. At 200 brands each refreshing daily, a spike in Apify failures creates 200 log lines that all look identical to Nightwatch and can't be grouped by affected brand.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Pass the `brand_professional_id` and `request_id` (or `platform_connection_id`) into the scraper method and include them in every log context.
        - Follow the `Log-with-context` pattern from the Stripe payout work.
    - **Technical:** Category (10) — canonical `Log-with-context`. The three `Log::warning` calls in `fetchProfile` include `username` and `error`/`status`/`body` but no professional ID or request ID. Nightwatch groups log entries by message signature and top-level context keys — without a stable tenant identifier, every "instagram.apify.not_ok" line across 200 brands looks identical. The Apify response body is truncated to 800 bytes and included in the log payload — at 40K daily notifications this is modest, but the missing correlation ID is the bigger gap.
    - **Plain English:** A call center takes 200 calls a day. Every agent writes "call dropped" on a notepad without noting which customer it was. The manager sees 200 "call dropped" notes and can't tell if one customer was dropped 200 times or if it's spread across everyone. The fix is to write the customer's account number on each note.
    - **Evidence:**
        ```php
        Log::warning('instagram.apify.threw', ['username' => $username, 'error' => $e->getMessage()]);
        Log::warning('instagram.apify.not_ok', [
            'username' => $username,
            'status' => $response->status(),
            'body' => mb_substr($response->body(), 0, 800),
        ]);
        Log::warning('instagram.apify.bad_items', [
            'username' => $username,
            'type' => gettype($items),
            'count' => is_array($items) ? count($items) : 0,
        ]);
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **LIFE-10** · P3 — `AppleController.put()` uses `updateOrCreate` on a JSONB payload — concurrent highlight updates can lose one writer's changes
    - **Where:** app/Http/Controllers/Api/Platforms/AppleController.php (put method)
    - **Affects:** Low impact — a single dashboard user is unlikely to race against themselves. But the shape is identical to the Shopify brand map race (LIFE-3), and if future per-brand delegate access is added, two delegates could race on the same Apple Music highlights.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add an `updated_at` optimistic-lock check on the connection row before writing, or use a database-level advisory lock keyed to `(user_id, platform, resource_id)`.
        - For now, document the race as accepted risk in the pilot phase.
    - **Technical:** Category (2) — same `lockForUpdate + UNIQUE` shape as LIFE-3. The `AppleController` stores both Music and Podcast selections as independent rows via the same `put()` → `updateOrCreate` path. The unique constraint on `(user_id, platform, resource_id) WHERE deleted_at IS NULL` prevents duplicate rows, but `updateOrCreate` on a JSONB payload is a blind overwrite — if two requests both read the current highlights, each appends a different album, and both write back, only the last writer's highlights survive. In practice, a single dashboard user can't race themselves, so this is P3. But the shape must be noted for the delegate-access future.
    - **Plain English:** Two assistants are updating the same artist's showcase at the same time — one picks albums A and B, the other picks albums C and D. They both check the current list, add their picks, and save. The second one's save overwrites the first, and albums A and B disappear. In practice this only happens if two people share one login, which the pilot doesn't allow — but the clipboard should be designed so it can't happen when sharing is added later.
    - **Evidence:**
        ```php
        private function put(User $user, string $platform, array $data): void
        {
            IntegrationConnection::updateOrCreate(
                ['user_id' => $user->id, 'platform' => $platform, 'resource_id' => $platform],
                [
                    'payload' => $data,
                    'is_active' => true,
                    'last_refreshed_at' => now(),
                    'last_refresh_status' => 'ok',
                    'last_refresh_error' => null,
                    'consecutive_failures' => 0,
                ],
            );
        }
        ```
    - `[DRAFT, confidence: 0.75]`
