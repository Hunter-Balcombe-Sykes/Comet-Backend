- [ ] **LIFE-1** · P1 — `upsertCatalogObject` uses non-deterministic idempotency key
    - **Where:** app/Services/Square/SquareApiClient.php:253
    - **Affects:** Square service push from Partna — every `pushServiceToSquare` call that retries creates a duplicate catalog object on Square rather than recognising the prior attempt.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Derive the key from `$service->id . ':' . ($catalogObject['version'] ?? 'new')` instead of `(string) Str::uuid()` so retries produce the same key.
        - Add a comment referencing **race-safe wallet credit** ('5735525') — idempotency key must be deterministic.
    - **Technical:** `Str::uuid()` generates a fresh value on every invocation. Square uses the `idempotency_key` to dedup retried mutations for 72 hours; a fresh UUID on retry means Square sees two independent create/update calls and applies both, leaving a stale catalog object that the original request already overwrote. At the scale target (200 brands × 50 services typical = 10K services), even a 0.1% retry rate produces 10 duplicate catalog objects per full-sync cycle, which then re-sync back into Partna as phantom rows.
    - **Plain English:** Every time Partna pushes a service change to Square, it includes a "receipt number" so Square knows "I've already handled this one." Right now that number is a random UUID that changes every time — so if the push is retried (network hiccup, Square busy), Square treats it as a brand-new request and creates a duplicate. The fix is to stamp the receipt number from the service's own ID instead of rolling dice.
    - **Evidence:**
        ```php
        public function upsertCatalogObject(Professional $professional, array $catalogObject): array
        {
            $response = $this->request($professional, 'POST', '/v2/catalog/object', [], [
                'idempotency_key' => (string) Str::uuid(),
                'object' => $catalogObject,
            ]);
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **LIFE-2** · P1 — Fresha API client missing vendor version pin
    - **Where:** app/Services/Fresha/FreshaApiClient.php (makeRequest method)
    - **Affects:** All Fresha API calls — every service sync, push, and token operation. A Fresha API version upgrade silently changes response shapes, breaking field mapping in `fetchServices` and `pushServiceToFresha`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `Fresha-Version` header (or equivalent) to `makeRequest()` pinned from `config('services.fresha.api_version')`.
        - Add the config key to `EnvCheckService::RECOMMENDED` so a missing version surfaces at deploy time.
        - Reference **Vendor API version pinning** (`9a9b107`) — every vendor SDK call must pin its API version.
    - **Technical:** SquareApiClient correctly pins `Square-Version: 2025-10-16` in its `makeRequest`. FreshaApiClient has no equivalent header. Without an explicit version pin, Fresha's API auto-upgrades apply new field names and response shapes; the field-mapping code in `fetchServices` (which already carries `// NOTE: Map these fields based on actual Fresha API response structure` comments) would silently break. At 200 brands, a single Fresha API version bump could corrupt sync for every connected brand simultaneously before anyone notices.
    - **Plain English:** Square gets told "use the API from October 2025" so we always see the same response shape. Fresha doesn't — it just takes whatever the latest version is. If Fresha changes how they name fields tomorrow, every brand connected to Fresha starts getting corrupted service data. The fix is one header line, same as Square already has.
    - **Evidence:**
        ```php
        // SquareApiClient::makeRequest — has version pin:
        ->withHeaders([
            'Square-Version' => '2025-10-16',
        ]);

        // FreshaApiClient::makeRequest — no version pin:
        $request = Http::acceptJson()
            ->asJson()
            ->timeout(30)
            ->withToken($accessToken);
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **LIFE-3** · P1 — Fresha create/update service calls lack idempotency key
    - **Where:** app/Services/Fresha/FreshaApiClient.php (createService, updateService methods)
    - **Affects:** `FreshaServiceSyncService::pushServiceToFresha` — retried pushes create duplicate services on Fresha.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add an idempotency key derived from the Partna `$service->id` to both `createService` and `updateService` request bodies.
        - Follow the **race-safe wallet credit** pattern (`5735525`) — deterministic key on every external write.
    - **Technical:** `pushServiceToFresha` does a version-conflict retry (fetches latest, re-upserts). If the second attempt succeeds but the first one also landed on Fresha after a network partition, Fresha has no idempotency key to recognise it as the same mutation. Without the key, Fresha may create a duplicate service row that syncs back into Partna as a phantom. Square's `upsertCatalogObject` includes an idempotency key (even if non-deterministic); Fresha's `createService`/`updateService` pass none at all.
    - **Plain English:** When Partna pushes a service update to Fresha and the network hiccups, it retries. Without a unique receipt number, Fresha can't tell "this retry is the same request I already processed" and may create a second copy. Every service gets doubled.
    - **Evidence:**
        ```php
        public function createService(Professional $professional, array $serviceData): array
        {
            return $this->request($professional, 'POST', '/v1/businesses/'.$this->businessId($professional).'/services', [], $serviceData);
        }

        public function updateService(Professional $professional, string $serviceId, array $serviceData): array
        {
            return $this->request($professional, 'PUT', '/v1/businesses/'.$this->businessId($professional).'/services/'.$serviceId, [], $serviceData);
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **LIFE-4** · P1 — Square sync transaction lacks row lock on integration cursor
    - **Where:** app/Services/Square/SquareServiceSyncService.php (applySquareSnapshot, DB::transaction)
    - **Affects:** Concurrent sync invocations (manual trigger + cron overlap, or two workers picking up the same job) race on `catalog_latest_time`, producing duplicate services or lost cursor updates.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `lockForUpdate()` on the integration row at the top of `syncFromSquare` before entering the transaction, following the **race-safe wallet credit** pattern (`5735525`).
        - Alternatively, use an advisory lock keyed on `'square_sync:' . $professional->id` so only one sync per professional runs at a time.
    - **Technical:** `syncFromSquare` reads `$integration->catalog_latest_time` outside the transaction, then enters `applySquareSnapshot` inside `DB::transaction` but never locks the integration row. Two concurrent syncs for the same professional both read the same `catalog_latest_time`, both fetch the same delta from Square, and both upsert the same services. The second upsert's `$syncedVariationIds` list and full-sync deletion logic produce torn state — services the first upsert deleted may be re-created, or the cursor may be set to an older `latest_time` depending on commit order.
    - **Plain English:** If two sync processes run at the same time for the same professional (say a manual "sync now" while the hourly cron is also running), they both read the same "last time I checked" bookmark, both pull the same changes from Square, and both try to write them at the same time. Like two people editing the same spreadsheet — whoever saves last wins, and the other's changes get scrambled.
    - **Evidence:**
        ```php
        // cursor read outside transaction:
        $beginTime = $beginTimeOverride;
        if ($beginTime === null && ! $fullSync && $integration->catalog_latest_time) {
            $beginTime = CarbonImmutable::parse($integration->catalog_latest_time)->toIso8601String();
        }

        try {
            $fetched = $this->squareApiClient->fetchAppointmentServiceVariations($professional, $beginTime);
            $stats = $this->applySquareSnapshot($professional, $fetched['services'] ?? [], $fullSync);

            // cursor write:
            $integration->catalog_latest_time = ...;
            $integration->save();
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **LIFE-5** · P1 — Cloudflare DNS ensureCname / upsertCname has TOCTOU race
    - **Where:** app/Services/Cloudflare/CloudflareDnsService.php (ensureCname, upsertCname, upsertTxt)
    - **Affects:** Subdomain provisioning during brand storefront setup — two concurrent deployments or a retry storm both call `ensureCname` and produce duplicate DNS records or skipped records on conflict.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap `ensureCname` in a Redis lock scoped to the zone+name (`'cf_dns:' . $name`) so only one caller creates the record. Follow the **single-flight lock** pattern from `SquareTokenService::refreshAccessToken`.
        - For `upsertCname` / `upsertTxt`, use the Cloudflare API's built-in idempotency by passing a deterministic `id` or using `PATCH` only (which is naturally idempotent), rather than `findRecord` then decide create-vs-patch.
    - **Technical:** `ensureCname` does `findRecord` then `post` — two callers racing both call `findRecord`, both see `null`, both `post`. Cloudflare creates the first CNAME and returns success; the second `post` either fails with a duplicate error (silently swallowed, returns `null`) or creates a second record with a suffixed name depending on Cloudflare's duplicate-handling behaviour. The caller receives `null` and assumes DNS provisioning failed, when the record actually exists. At 200 brands, brand storefront setup is the primary path that hits this — every concurrent deploy is a race.
    - **Plain English:** When a brand's storefront is being set up, the code checks "does this DNS entry already exist?" and if not, creates it. If two setup processes run at the same time, both check, both see "nope, doesn't exist," and both try to create it. One succeeds, the other either fails silently or creates a duplicate — and the setup thinks DNS failed when it actually worked.
    - **Evidence:**
        ```php
        public function ensureCname(string $name, string $target, bool $proxied = true): ?string
        {
            if (! $this->hasCredentials()) {
                return null;
            }

            $existing = $this->findRecord('CNAME', $name);
            if ($existing !== null) {
                return $existing['id'];
            }

            $response = Http::withToken($this->apiToken)
                ->post($this->zonesUrl('/dns_records'), [...]);
            // ...
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **LIFE-6** · P2 — Fresha syncFromFresha lacks row lock on cursor, same race class as Square
    - **Where:** app/Services/Fresha/FreshaServiceSyncService.php (syncFromFresha)
    - **Affects:** Same concurrent-sync race as LIFE-4 but for Fresha integrations. Lower blast radius today (fewer Fresha brands) but same code shape.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Apply the same `lockForUpdate` or advisory-lock fix from LIFE-4 to `syncFromFresha`.
    - **Technical:** Identical TOCTOU shape: reads `catalog_latest_time` outside the transaction, fetches delta from Fresha, enters `DB::transaction` without locking the integration row. Two concurrent syncs produce torn state. Impact at scale: as Fresha adoption grows toward the 200-brand target, this becomes P1.
    - **Plain English:** Same spreadsheet-editing problem as the Square sync — if two Fresha syncs run at once for the same professional, the catalog cursor gets scrambled.
    - **Evidence:**
        ```php
        $beginTime = $beginTimeOverride;
        if ($beginTime === null && ! $fullSync && $integration->catalog_latest_time) {
            $beginTime = $integration->catalog_latest_time->toIso8601String();
        }

        try {
            $result = $this->freshaApiClient->fetchServices($professional, $fullSync ? null : $beginTime);
            // ...
            DB::transaction(function () use ($professional, $rows, &$syncedCount, &$deletedCount) {
                // upserts without locking integration row
            });
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **LIFE-7** · P2 — KickApiClient swallows auth failure; callers can't distinguish "no handles live" from "auth broken"
    - **Where:** app/Services/Streaming/KickApiClient.php:getLiveHandles
    - **Affects:** Live status display on public profiles — a revoked/expired Kick OAuth token silently shows every streamer as offline with no alerting.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Throw a typed exception (e.g. `StreamingAuthException`) on auth failure instead of returning `[]`. Let the poller catch it and trigger Nightwatch alerting.
        - Follow the **distinct logs for distinct failure modes** pattern (`#STRIPE-2`, `35c6f31`) — an empty array currently means both "no one is live" and "auth is broken."
    - **Technical:** `getLiveHandles` returns `[]` when `$this->tokens->getToken('kick')` is null and logs `Log::critical('streaming.auth_failure')`. The caller (`LiveStatusPoller::pollKick`) writes `isLive = false` for every handle in the batch — the public profile shows every Kick streamer as offline. At the scale target (~40K daily notifications and public profile loads), a Kick auth failure during a peak streaming window silently blanks the live-status feature for every handle on the platform. `Log::critical` is a breadcrumb; Nightwatch alerts on exceptions, not log queries. The caller needs a distinguishable return so it can raise an exception.
    - **Plain English:** When Kick's login token expires, instead of alerting us that something's broken, the system just tells everyone "all your streamers are offline." Every fan sees empty live indicators. The fix is to throw a specific error when auth fails so Nightwatch pages us, instead of pretending nothing's wrong.
    - **Evidence:**
        ```php
        $token = $this->tokens->getToken('kick');
        if (! $token) {
            Log::critical('streaming.auth_failure', ['platform' => 'kick']);
            return [];
        }
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **LIFE-8** · P2 — TwitchApiClient has same swallowed auth failure as Kick
    - **Where:** app/Services/Streaming/TwitchApiClient.php:getLiveHandles
    - **Affects:** Same as LIFE-7 but for Twitch — larger blast radius (more Twitch streamers than Kick).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Apply identical fix as LIFE-7: throw `StreamingAuthException` instead of returning `[]`.
    - **Technical:** Same shape — `getToken('twitch')` returns null → `Log::critical` → return `[]`. At the scale target, Twitch is the dominant platform; a silent auth failure there blanks live indicators for the majority of streaming handles.
    - **Plain English:** Same as Kick — Twitch auth failure silently shows everyone as offline instead of alerting us.
    - **Evidence:**
        ```php
        $token = $this->tokens->getToken('twitch');
        if (! $token) {
            Log::critical('streaming.auth_failure', ['platform' => 'twitch']);
            return [];
        }
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **LIFE-9** · P2 — Square and Fresha API retry loops use linear backoff, no exponential
    - **Where:** app/Services/Square/SquareApiClient.php:request (line ~172); app/Services/Fresha/FreshaApiClient.php:request
    - **Affects:** 429 throttling during peak sync windows — at 200 brands with hourly syncs, a burst of brand signups triggers a retry storm against Square/Fresha APIs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the fixed 1s wait with exponential backoff: `$wait = max(1000, ((int) ($response->header('Retry-After') ?? 1)) * 1000) * pow(2, $attempt)`.
        - Cap max wait at 30s so a single 429 doesn't stall the job for minutes.
    - **Technical:** Both clients retry 429 with `max(1000, Retry-After * 1000)` microseconds — the same wait on every attempt. If Square/Fresha rate-limit by IP or account-wide, three retries at the same interval all hit the same throttled window, and the request fails permanently after `$maxRetries`. At the scale target, hourly sync for 200 brands produces a dense burst window; linear backoff guarantees every throttled brand exhausts its retries simultaneously. Exponential backoff spreads the retry load.
    - **Plain English:** When Square says "too fast, wait a second," the code waits exactly one second and tries again. If Square is still busy, it waits one more second — same as before. The third attempt also fails for the same reason. Spreading those wait times out (1s, then 2s, then 4s) gives Square more breathing room and more retries succeed.
    - **Evidence:**
        ```php
        // SquareApiClient::request:
        if ($response->status() === 429 && $attempt < $maxRetries) {
            $wait = max(1000, ((int) ($response->header('Retry-After') ?? 1)) * 1000);
            usleep($wait * 1000);
            $attempt++;
            continue;
        }

        // FreshaApiClient::request — identical:
        if ($response->status() === 429 && $attempt < $maxRetries) {
            $wait = max(1000, ((int) ($response->header('Retry-After') ?? 1)) * 1000);
            usleep($wait * 1000);
            $attempt++;
            continue;
        }
        ```
    - `[DRAFT, confidence: 0.75]`

- [ ] **LIFE-10** · P2 — Cloudflare DNS service returns null on failure; callers can't distinguish "provisioned elsewhere" from "API down"
    - **Where:** app/Services/Cloudflare/CloudflareDnsService.php (ensureCname, upsertCname, upsertTxt — all return `?string`)
    - **Affects:** Hydrogen storefront subdomain provisioning — a Cloudflare API 5xx during brand deploy silently returns null, the deploy continues without DNS, and the storefront is unreachable with no alert.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Throw a typed `CloudflareDnsException` on non-404 API failures. Only return null for the expected "credentials not configured" dev path.
        - The calling job should catch the exception and either retry with backoff or fail the deployment with a clear error.
        - Reference **distinct logs for distinct failure modes** (`#STRIPE-2`) — `null` currently conflates "dev mode, no credentials", "findRecord failed", "create failed", and "patch failed."
    - **Technical:** Every public method in `CloudflareDnsService` returns `null` on failure, and every caller treats null as "DNS provisioning didn't happen — skip it." The `hasCredentials()` guard is the only legitimate null path (local dev). Cloudflare 5xx, network timeout, and permission errors all return null with only a `Log::error` breadcrumb. At 200 brands, a single Cloudflare outage during a deploy wave silently leaves storefront subdomains unresolvable with no Nightwatch alert.
    - **Plain English:** When the DNS service fails to create a subdomain entry (Cloudflare is down, network error, bad permissions), it quietly returns "nothing" and the deployment continues as if nothing's wrong. The brand's new storefront goes live but nobody can reach it. The code treats "we're in dev mode with no credentials" the same as "Cloudflare is on fire" — they should be very different outcomes.
    - **Evidence:**
        ```php
        if (! $response->successful()) {
            Log::error('CloudflareDnsService: failed to create CNAME record.', [
                'name' => $name,
                'target' => $target,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return null;
        }
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **LIFE-11** · P3 — CloudflareDnsService logs full API response bodies — log index pressure at scale
    - **Where:** app/Services/Cloudflare/CloudflareDnsService.php (all error log calls)
    - **Affects:** Nightwatch log ingestion volume — every failed DNS call logs the full Cloudflare response body.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Truncate the logged body to first 500 chars. Full body is needed for debugging — store it on the failing job's DB record instead (if one exists).
        - Follow the **heavy log payloads** observation from category 10 — fan-out jobs should never log full vendor responses.
    - **Technical:** Every `Log::error` in `CloudflareDnsService` includes `'body' => $response->body()`. Cloudflare error responses can include verbose HTML or JSON payloads. At 200 brands with periodic subdomain provisioning, DNS record updates, and TXT verification, failed calls produce unbounded log payloads. Not an issue at 10 brands but worth trimming before the scale target.
    - **Plain English:** When a DNS call fails, the system writes the entire error response (which can be kilobytes of HTML) into the log. At small scale it's fine; at 200 brands with hundreds of DNS operations, the log storage fills up with debug noise that nobody reads.
    - **Evidence:**
        ```php
        Log::error('CloudflareDnsService: failed to create CNAME record.', [
            'name' => $name,
            'target' => $target,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);
        ```
    - `[DRAFT, confidence: 0.70]`
