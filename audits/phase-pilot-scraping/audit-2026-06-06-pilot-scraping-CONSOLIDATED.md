# Pilot-Readiness Consolidated Audit — Platform Integrations / Scraping / Caching
## 2026-06-06 · phase-pilot-scraping

**Branch:** development  
**Scope:** Platform integrations subsystem (Platforms controllers, PlatformRefresher, scrapers, SafeUrlFetcher, IntegrationConnectionPolicy/Observer, caching layer, Cloudflare jobs, tests)  
**Pipeline:** 7 DeepSeek V4 Pro scans (18 lens passes) + 7 Claude Sonnet adjudications → this consolidation  
**Source bundles:** core (SEC/LIFE/CACHE/SCALE/SCHEMA/CCH/WHK/TXN) · pre-merge (MIG/API/CFG/TEST) · code-quality (SLOP/SEM) · caching-coverage-gaps · data-integrity · job-queue-correctness · observability

---

### Dedup log (findings dropped as duplicates before this consolidation)

| Dropped | Absorbed into | Reason |
|---------|---------------|--------|
| SEM-1 (code-quality) | CONS-1 | Identical: YouTube `latest` key missing from cron refresh |
| LIFE-3 (core) | CONS-2 | Subset: Shopify addBrand race; DINT-1 covers all four mutation methods |
| DINT-5 (data-integrity) | CONS-11 | Identical: public endpoint raw payload; SEC-2 + API-2 same finding |
| API-2 (pre-merge) | CONS-11 | Identical: same raw-payload public endpoint finding |
| OBS-3 (observability) | CONS-15 | Identical: `last_refresh_error` never written; LIFE-4 same finding |
| OBS-4 (observability) | CONS-17 | Identical: Apify budget counter race; LIFE-1 same finding |
| SEC-3 (core) | CONS-10 | Identical: policy gate never called; TEST-2 more actionable framing |
| CFG-3 (pre-merge) | CONS-35 | Identical: Fresha hash hardcoded; LIFE-7 same finding |

Adjudicators also dropped 3 DeepSeek false positives: `integrations:refresh` already has `->withoutOverlapping(60)` in `routes/console.php`; `staffAnalyticsSummary` already appends `:v{$version}` at call-site; `PurgeSoftDeleted::PURGE_HANDLED` already includes `IntegrationConnection::class`.

**Caching coverage gaps lens: no findings** — the caching layer (edge → Redis → DB) is architecturally sound for this scope. `AccountCapabilities` uses in-process WeakMap (no DB round-trip). Public integrations endpoint correctly relies on Cloudflare edge cache + push-purge via Observer. No Redis redundancy needed.

---

## Progress

- P1 Launch blockers: 5 of 5 complete
- P2 Scale risks: 4 of 4 complete
- P2 Security & privacy: 2 of 5 complete (CONS-10, CONS-11 parked — standalone; CONS-13 parked — DB migration)
- P2 Correctness/data integrity: 7 of 8 complete (CONS-21 parked — standalone)
- P2 Observability: 3 of 3 complete
- P2 Test coverage: 2 of 3 complete (CONS-27 parked — SQLite harness lacks the CHECK + partial unique index the finding asserts; needs a shared-schema decision)
- P3 Nice to have: 9 of 14 complete (CONS-29, CONS-34 parked — DB migrations; CONS-38 parked — L effort; CONS-35 parked — really L, bundle with CONS-10's PR per the finding)

---

## Launch blockers (P1)

- [x] **#CONS-1** · P1 · Effort: S — YouTube cron strips `latest` key — "Most Recent" tile blanks after first nightly refresh
    - **Where:** `app/Services/Platforms/PlatformRefresher.php` — `youtubePayload()` return array (lines 71–82)
    - **Affects:** Every professional with a YouTube connection. The first nightly `integrations:refresh` cron after they connect strips the `payload['latest']` key, blanking the dashboard "Most Recent" tile. The flat back-compat fields (`name`, `description`, `link`, `thumbnail`) survive; the canonical nested key does not. Apple Music and Apple Podcast refreshers are unaffected — both use `...$payload` spread which preserves `latest` implicitly.
    - **What to do:**
        - Align `youtubePayload()` with the Apple spread pattern:
          ```php
          return [
              ...$payload,
              'latest' => $latest,
              'name' => $latest['name'],
              'description' => $latest['description'],
              'link' => $latest['link'],
              'thumbnail' => $latest['thumbnail'],
              // highlights preserved by spread
          ];
          ```
        - Add assertion to `tests/Feature/Platforms/RefreshPlatformConnectionsCommandTest.php`: `expect($conn->payload['latest'])->toHaveKey('videoId')`.
    - **Technical:** `YoutubeController::connect()` documents the invariant: `"The nested 'latest' is the canonical shape … what the dashboard reads to render the 'Most recent' tile."` Both `connect()` and `highlights()` write `latest` correctly. `youtubePayload()` reconstructs the array from scratch (unlike Apple's spread-then-override) and silently omits `latest`. Root cause: commit #191 updated `connect()` and `highlights()` but did not touch `PlatformRefresher`. Existing test asserts `payload['name']` and `payload['highlights']` but not `payload['latest']`, making the regression invisible to CI.
    - **Plain English:** The overnight auto-refresh updates a user's YouTube flat fields ("current video name, thumbnail URL") but throws away the structured card the dashboard's "Most Recent" tile actually reads. The next morning the tile goes blank. Apple Music/Podcast don't have this problem because they copy the whole payload first and only update what changed.
    - **Evidence:**
        ```php
        // PlatformRefresher::youtubePayload() — 'latest' absent:
        return [
            'handle' => $handle,
            'name' => $latest['name'],
            'description' => $latest['description'],
            'link' => $latest['link'],
            'thumbnail' => $latest['thumbnail'],
            'highlights' => $payload['highlights'] ?? [],
            // 'latest' => $latest   ← missing
        ];

        // appleMusicPayload() line 120 — 'latest' preserved by spread:
        return [ ...$payload, 'latest' => $latest, ... ];
        ```

- [x] **#CONS-2** · P1 · Effort: M — Shopify brand-map mutations have no concurrency guard — concurrent saves silently overwrite each other
    - **Where:** `app/Http/Controllers/Api/Platforms/ShopifyController.php` — `addBrand()`, `setProducts()`, `removeBrand()`, `updateBrand()`
    - **Affects:** Professionals managing multiple Shopify brands. Two concurrent requests (two browser tabs, a retry) each read a stale `brandMap()`, apply their mutation, and write back — the last write wins and the first is silently discarded. At the cap boundary (`MAX_BRANDS = 5`), two requests at count-4 can both slip the check and produce a 6-brand payload.
    - **What to do:**
        - Wrap every brand-mutation method with a per-user Redis lock: `Cache::lock("platforms:shopify:lock:{$user->id}", 10)->block(5, fn() => ...)`, released in `finally`.
        - Lock scope must span the full `brandMap()` read through the `writeConnection()` write.
        - Return 423 if the lock times out so the dashboard can retry.
    - **Technical:** All four methods follow the same pattern: `$map = $this->brandMap($user)` (reads JSONB payload), mutate in-memory PHP array, `$this->writeConnection($user, $map)` → `updateOrCreate`. The `updateOrCreate` is atomic at the Postgres row level, but both callers are mutating the same JSON blob with no visibility into each other's in-flight changes. `CacheLockService` (already in scope) provides the single-flight primitive.
    - **Plain English:** Adding or editing brands works like editing a shared document without collaborative locking — if two sessions save concurrently, one change is silently lost. The fix is a per-user "do not disturb" lock while any brand mutation is in progress.
    - **Evidence:**
        ```php
        $map = $this->brandMap($user);
        // ... check cap, build brand entry ...
        $map[$id] = [...];
        $this->writeConnection($user, $map);  // ← blind JSONB overwrite, no lock
        ```

- [x] **#CONS-3** · P1 · Effort: M — Fresha service-visibility toggle has no concurrency guard — concurrent show/hide of different services silently loses one toggle
    - **Where:** `app/Http/Controllers/Api/Platforms/FreshaController.php` — `setServiceVisibility()`
    - **Affects:** Professionals managing Fresha booking service visibility. Toggling two services from separate dashboard tabs can result in one toggle being silently lost.
    - **What to do:**
        - Wrap `setServiceVisibility()` with a per-user Redis lock: `Cache::lock("platforms:fresha:lock:{$user->id}", 10)->block(5, ...)`, released in `finally`.
        - Alternatively, replace the full-payload rewrite with a targeted `jsonb_set` on `payload->'selection'->'hiddenServiceIds'` for an atomic DB-layer fix.
    - **Technical:** The method reads the full connection payload, mutates `selection['hiddenServiceIds']`, and writes the entire payload back. Between read and write a concurrent request can read the same stale array and write its version — the second write discards the first. The partial unique index on `(user_id, platform, resource_id)` protects row uniqueness but not intra-row JSONB correctness.
    - **Plain English:** If a professional hides "haircut" in one tab and shows "colour" in another, whichever tab saves last wins and the other tab's change is lost with no error.
    - **Evidence:**
        ```php
        $payload = $this->readConnection($user);
        $selection = data_get($payload, 'selection');
        // ... toggle $selection['hiddenServiceIds'] ...
        $this->writeConnection($user, ['url' => ..., 'selection' => $selection]);
        // ← no lock around the read→mutate→write cycle
        ```

- [x] **#CONS-4** · P1 · Effort: M — Apple Music / Podcast + YouTube highlight saves have no concurrency guard — concurrent saves silently lose one set of selections
    - **Where:** `app/Http/Controllers/Api/Platforms/AppleController.php` — `musicHighlights()`, `podcastHighlights()`; `app/Http/Controllers/Api/Platforms/YoutubeController.php` — `highlights()`
    - **Affects:** Professionals curating highlights from multiple sessions (two dashboard tabs, rapid repeated saves). A second save overwrites the first silently.
    - **What to do:**
        - Wrap each highlights method with a per-user, per-platform Redis lock: `Cache::lock("platforms:{$platform}:lock:{$user->id}", 10)->block(5, ...)`, released in `finally`.
        - The `put()` / `writeConnection()` calls can remain unchanged; only the controller-level read→mutate→write cycle needs guarding.
    - **Technical:** All three methods share the same pattern: `read()` selection, mutate `highlights` and `latest` in-memory, `put()` / `writeConnection()` back. A concurrent request on the same user+platform pair can race the same window. The `updateOrCreate` inside `writeConnection()` is atomic at the row level but JSONB mutations are not serialized.
    - **Plain English:** Two browser tabs saving different YouTube video picks at the same time will result in one pick set being silently lost. A per-platform lock prevents the two tabs from stepping on each other.
    - **Evidence:**
        ```php
        // AppleController::musicHighlights
        $selection = $this->read($user, self::MUSIC);
        // ... modify $selection['highlights'], refresh $selection['latest'] ...
        $this->put($user, self::MUSIC, $selection);  // ← no lock
        ```

- [x] **#CONS-5** · P1 · Effort: M — `IntegrationConnectionPolicy` has zero tests — the 404-not-403 invariant is unverified by CI
    - **Where:** `app/Policies/IntegrationConnectionPolicy.php` — all 4 public methods (`view`, `update`, `delete`, `create`)
    - **Affects:** Every platform connect/read/delete endpoint — the policy governs all `IntegrationConnection` row operations but has never been exercised by a test. The critical `denyAsNotFound()` contract (404 not 403 for non-owner access) is asserted nowhere; a refactor that accidentally called `denyAsForbidden()` would pass CI.
    - **What to do:**
        - Create `tests/Feature/Policies/IntegrationConnectionPolicyTest.php`.
        - For each of the four abilities: assert owner → true; non-owner → 404 (not 403); pending-deletion actor → 423.
        - Assert `denyAsNotFound()` produces HTTP 404 to confirm the existence-privacy contract.
    - **Technical:** Policy is confirmed registered at `AppServiceProvider:135` (`Gate::policy(IntegrationConnection::class, IntegrationConnectionPolicy::class)`). All four methods branch on `ownerMatches()` plus `denyIfPendingDeletion()`. Zero tests. The 404-not-403 shape is the key security invariant for preventing resource-existence enumeration on public-facing platform data.
    - **Plain English:** The door policy for platform connections is written and wired — but nobody has ever tested it. We can't confirm it gives a "not found" error (not a "forbidden" error) when the wrong person tries to access a connection. Those two messages reveal different information: "forbidden" tells an attacker the resource exists.
    - **Evidence:**
        ```php
        public function view(User $actor, Model $resource): bool|Response
        {
            return $this->ownerMatches($actor, $resource)
                ? true
                : $this->denyAsNotFound();
        }
        // grep confirms: zero test files reference IntegrationConnectionPolicy
        ```

---

## Scale risks

- [x] **#CONS-6** · P2 · Effort: M — `InstagramController::connect` blocks a PHP-FPM worker for up to 150 seconds
    - **Where:** `app/Services/Platforms/InstagramScraper.php:31` (110s Apify timeout); `app/Http/Controllers/Api/Platforms/InstagramController.php:68–73` (`mirrorAll` serial loop)
    - **Affects:** All API traffic — connecting Instagram synchronously blocks one worker thread for the full Apify runtime (5–110s) plus serial image mirroring (~2–5s × 8 images). Three concurrent Instagram connects can saturate a small worker pool.
    - **What to do:**
        - Move the Apify scrape + image mirroring to a queued job dispatched by `connect()`. Return `202 Accepted` with a status-poll URL immediately.
        - Keep the per-user cooldown `Cache::add` in the controller so rapid re-connects are throttled before job dispatch.
        - Parallelise image mirrors with `Http::pool` in the job.
    - **Technical:** `Http::withToken($token)->timeout(110)->post(...)` blocks the PHP-FPM worker thread for the full Apify actor runtime. After that, `mirrorAll()` iterates up to 8 CDN images serially via `SafeUrlFetcher::fetch()` → `Storage::disk('media')->put()`. Total worst-case: 150s per connect. Under PHP-FPM's process-per-request model, 3-4 concurrent connects starve all other API traffic.
    - **Plain English:** When a user links their Instagram, the server waits up to two minutes for an external scraping service, then downloads and re-uploads up to eight photos one at a time. During all of that time one server worker is completely frozen. Moving this to a background job frees the worker immediately.
    - **Evidence:**
        ```php
        $response = Http::withToken($token)->timeout(110)->post(
            'https://api.apify.com/v2/acts/'.self::ACTOR.'/run-sync-get-dataset-items', ...
        );
        // then: serial mirrorAll loop (~5s × 8 images)
        ```

- [x] **#CONS-7** · P2 · Effort: M — `EventbriteScraper::fetchEvents` fetches event detail pages serially (up to 11 sequential HTTP round-trips)
    - **Where:** `app/Services/Platforms/EventbriteScraper.php:55–59`
    - **Affects:** Daily `integrations:refresh` cron and every Eventbrite connect. At 8 serial events × 1–3s RTT each, one organiser refresh takes 8–24s. At 100 Eventbrite users, serial fetching extends cron runtime by up to 40 minutes.
    - **What to do:**
        - Replace the `foreach` with `Http::pool(fn (Pool $pool) => ...)` to fetch all event detail pages concurrently. Each URL is independent.
        - Parse JSON-LD extraction in a second pass (CPU-bound, not I/O-bound).
    - **Technical:** Each `fetchEvent()` call invokes `SafeUrlFetcher::fetch()` — full DNS + TLS + HTTP round-trip per event. `Http::pool` issues all requests concurrently, completing in the time of the single slowest response.
    - **Plain English:** The scraper visits each event page one at a time, like loading 8 web pages in sequence before closing the browser. Opening all 8 tabs simultaneously and reading them as they load cuts the time from 24s to under 3s.
    - **Evidence:**
        ```php
        foreach (array_slice($eventUrls, 0, $limit + 3) as $url) {
            $event = $this->fetchEvent($url, $headers);  // ← serial HTTP, no pool
            if ($event) { $events[] = $event; }
        }
        ```

- [x] **#CONS-8** · P2 · Effort: M — All Cloudflare and cache-warm jobs share the `default` queue — no isolation from user-facing work
    - **Where:** `app/Jobs/Cache/WarmPublicSiteCacheJob.php:44`, `app/Jobs/Cloudflare/CloudflareCachePurgeJob.php:40`, `app/Jobs/Cloudflare/RetireSubdomainFromKvJob.php:20`, `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:30`
    - **Affects:** Queue throughput at scale — a burst of platform-connection writes dispatches multiple `CloudflareCachePurgeJob`s onto `default`, competing for worker slots with notification dispatch.
    - **What to do:**
        - Route `CloudflareCachePurgeJob`, `SyncSubdomainToKvJob`, `RetireSubdomainFromKvJob` to a dedicated `cloudflare` queue with 1–2 Horizon workers.
        - Route `WarmPublicSiteCacheJob` to a `cache-warm` queue.
        - Add corresponding supervisors to `config/horizon.php` before deploying (the previous `cache` queue was undone because workers weren't configured).
    - **Technical:** Domain queues (`mail`, `notifications`, `webhooks`) already exist — Cloudflare/cache-warm jobs simply weren't separated. One platform-connection edit dispatches at minimum one `CloudflareCachePurgeJob`. A user connecting all five Shopify brands dispatches five.
    - **Plain English:** All background jobs — emails, cache purges, routing table syncs — wait in the same single lane. When someone connects five Shopify stores, five cache-purge tasks jump the queue and slow everyone else's notifications.
    - **Evidence:**
        ```php
        public function __construct(public readonly string $handle)
        {
            $this->onQueue('default');  // CloudflareCachePurgeJob, SyncSubdomainToKvJob, etc.
        }
        ```

- [x] **#CONS-9** · P2 · Effort: S — Cloudflare jobs retry on permanent 4xx without `$maxExceptions` short-circuit
    - **Where:** `app/Jobs/Cloudflare/CloudflareCachePurgeJob.php`; `app/Jobs/Concerns/HasCloudflareRetryPolicy.php` (consumed by `SyncSubdomainToKvJob` + `RetireSubdomainFromKvJob`)
    - **Affects:** Alert latency. A revoked or misconfigured Cloudflare API token burns all retry slots (~80–100s) before `failed()` fires and Nightwatch alerts. During that window the edge cache or KV routing table stays stale.
    - **What to do:**
        - Add `public int $maxExceptions = 2;` to `CloudflareCachePurgeJob`.
        - Add `public int $maxExceptions = 2;` to `HasCloudflareRetryPolicy` — propagates to `SyncSubdomainToKvJob` and `RetireSubdomainFromKvJob`.
        - Optionally, inspect HTTP status before re-throwing: call `$this->fail($e)` immediately on 4xx to skip the retry machinery entirely.
    - **Technical:** All three jobs declare `$tries = 3` with backoff arrays but no `$maxExceptions`. A 401/403 response throws on every attempt. Without `$maxExceptions`, Horizon burns through all retries before marking failed. `$maxExceptions = 2` cuts alert delay from ~100s to ~40s. The `failed()` handlers already call `report($e)` correctly — this is purely a faster-failure fix.
    - **Plain English:** A wrong API key causes these jobs to try three times, waiting between each, before triggering an alert. Two tries is enough to confirm it's not a fluke — cutting to two saves a minute of stale-cache time.
    - **Evidence:**
        ```php
        public int $tries = 3;
        public array $backoff = [5, 15, 60];
        // No $maxExceptions = 2;
        ```

---

## Security & privacy

- [x] **#CONS-10** · P2 · Effort: L — Platform controllers never invoke `IntegrationConnectionPolicy` via `authorizeForUser` — `denyIfPendingDeletion` never runs
    - **Where:** `app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php` — `connectionFor()`, `writeConnection()`, `forgetConnection()`; all 8 platform controllers that use the trait; `AppleController` private helpers that bypass the trait entirely
    - **Affects:** Authorization auditability. The `denyIfPendingDeletion()` policy method never executes — a professional whose account is pending deletion can still write platform connections. The 404-not-403 contract is also never enforced via the policy gate on write paths.
    - **What to do:**
        - Policy is already registered at `AppServiceProvider:135` — no change needed there.
        - In `connectionFor()`, after resolving a non-null connection, add `$this->authorizeForUser($user, 'view', $connection)`.
        - In `writeConnection()`, add `authorizeForUser($user, 'update', $connection)` for existing rows, `authorizeForUser($user, 'create', $skeleton)` for new rows.
        - In `forgetConnection()`, add `authorizeForUser($user, 'delete', $connection)` before `->delete()`.
        - Migrate `AppleController`'s private `read()/put()/forgetOne()` helpers to use the trait.
    - **Technical:** `authorizeForUser` confirmed absent by grep across all 8 platform controllers and the trait. Ownership is currently enforced solely by scoping queries through `$user->integrationConnections()->where(...)`. This works for cross-user isolation but silently skips all policy logic. A future controller that fetches an `IntegrationConnection` by UUID directly would bypass both scoping and the policy gate.
    - **Plain English:** You've installed a formal lock (the Policy class) but every room uses a separate informal keypad. Both currently work for keeping strangers out, but the formal lock's "is this account being deleted?" rule is never checked. Routing all access through the formal lock means one rule covers everything.
    - **Evidence:**
        ```php
        protected function connectionFor(User $user, ?string $resourceId = null): ?IntegrationConnection
        {
            return $user->integrationConnections()
                ->where('platform', $this->platform())
                ->where('resource_id', $resourceId ?? $this->defaultResourceId())
                ->first();
            // No authorizeForUser() follows — confirmed by grep
        }
        ```

- [ ] **#CONS-11** · P2 · Effort: M — `PublicIntegrationController` returns raw `payload` JSONB without a Resource allowlist — future internal keys become public automatically
    - **Where:** `app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php:52–61`
    - **Affects:** All sitepage visitors (unauthenticated). The full `payload` JSONB blob is served verbatim with CDN caching. Any field added by a developer (internal reference IDs, scraper metadata, the `_folder` key from CONS-21's Instagram R2 fix) silently becomes part of the public API contract. Violates Partna's Resource-class architecture mandate.
    - **What to do:**
        - Create `app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php` with an explicit `toArray()` allowlisting `resourceId`, `payload`, and `lastRefreshedAt`.
        - For the pilot this is a pass-through, but once the allowlist exists, future payload additions require a deliberate `$this->when(...)` decision before becoming public.
        - Replace the inline `->map()` in `show()` with the Resource.
    - **Technical:** Currently maps `['resourceId' => ..., 'payload' => $r->payload, ...]` — no Resource sits between the model and the wire. Scraped upstream content (YouTube descriptions, Eventbrite organiser details, Instagram captions) is served verbatim via a CDN-cached endpoint. This converged from 3 independent lenses (SEC-2, API-2, DINT-5).
    - **Plain English:** Whatever gets written into the database goes straight out the public door. Right now all data is meant to be public. But if a developer ever stores an internal field in a platform's data, it appears on every public profile page with no warning.
    - **Evidence:**
        ```php
        ->map(fn ($rows) => $rows->map(fn (IntegrationConnection $r) => [
            'resourceId' => $r->resource_id,
            'payload' => $r->payload,   // ← full JSONB, no allowlist
            'lastRefreshedAt' => $r->last_refreshed_at?->toIso8601String(),
        ])->values())
        ```

- [x] **#CONS-12** · P2 · Effort: S — `InstagramScraper` logs the raw Apify response body (up to 800 bytes of potential Instagram PII)
    - **Where:** `app/Services/Platforms/InstagramScraper.php:40–44` — `not_ok` log branch
    - **Affects:** Nightwatch log aggregator. Apify error responses can echo back scraped Instagram profile data (full name, bio excerpt, post captions). GDPR Article 5(1)(c) data-minimisation principle applies to log storage.
    - **What to do:**
        - Remove `'body' => mb_substr($response->body(), 0, 800)` from the `not_ok` log context entirely.
        - Log only `'status' => $response->status()`. If structured error detail is needed, extract only `$response->json('error')` and gate behind `config('app.debug')`.
    - **Technical:** The `not_ok` branch fires on any non-2xx Apify response. The 800-byte truncation includes enough of a profile payload to capture a full name, bio excerpt, or post caption. The `threw` and `bad_items` branches are safe — they log only exception messages and type metadata. Only `not_ok` over-logs.
    - **Plain English:** When the Instagram scraper gets an error, it copies part of the error message — which can include bits of a user's Instagram profile — into the permanent error log. Removing one line fixes this.
    - **Evidence:**
        ```php
        Log::warning('instagram.apify.not_ok', [
            'username' => $username,
            'status' => $response->status(),
            'body' => mb_substr($response->body(), 0, 800),  // ← potential PII
        ]);
        ```

- [ ] **#CONS-13** · P2 · Effort: S — Missing RLS on `site.platform_connections`
    - **Where:** `supabase/migrations/20260602150238_create_platform_connections.sql`
    - **Affects:** Defense-in-depth. If any Supabase Studio query, admin tool, or future route bypasses the application's policy layer, all rows are visible without tenant scoping. The application's `IntegrationConnectionPolicy` handles authenticated paths; RLS closes the Supabase Studio / raw-query gap.
    - **What to do:**
        - Add a new migration: `ALTER TABLE site.platform_connections ENABLE ROW LEVEL SECURITY;`
        - Add a policy permitting `app_backend` to access rows where `user_id = current_setting('app.actor_id')::uuid`, mirroring the pattern on other `site.*` tenant tables.
    - **Technical:** All `site.*` tables containing user-scoped data carry RLS as a second lock behind the Policy layer. This migration was reconstructed from the dev DB and lacks `ENABLE ROW LEVEL SECURITY`. Current risk is low (Policy enforces auth on every route), but RLS is the deadbolt behind the keypad.
    - **Plain English:** Your app's permission checks are the keypad. RLS is the deadbolt behind it. The platform integrations table has a keypad but no deadbolt. One SQL statement installs the deadbolt.
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS site.platform_connections (
            id uuid PRIMARY KEY,
            user_id uuid NOT NULL REFERENCES core.users(id) ON DELETE CASCADE,
            ...
        );
        -- No ALTER TABLE ... ENABLE ROW LEVEL SECURITY follows
        ```

- [x] **#CONS-14** · P2 · Effort: S — Google Maps API key served at public CDN-cached endpoint with no enforcement of GCP referrer restrictions
    - **Where:** `app/Http/Controllers/Api/PublicSite/PublicConfigController.php:60–66`
    - **Affects:** Key security posture. The key is returned at `GET /api/public/config/integrations` with `Cache-Control: public, max-age=3600`. The code's own docblock makes HTTP-referrer restrictions in GCP a contractual requirement — but nothing enforces this contract in code, CI, or `.env.example`.
    - **What to do:**
        - Add a comment to `.env.example`: `# GOOGLE_MAPS_API_KEY — must have HTTP referrer restriction to *.partna.au/* configured in Google Cloud Console.`
        - Add a CI check or Nightwatch alert that verifies the key has referrer restrictions (or add a dev runbook note in `docs/`).
        - Consider serving only on authenticated dashboard config endpoints to remove the CDN caching surface.
    - **Technical:** The design (public key + referrer restriction) is intentional and can be safe. The risk is the GCP-side restriction being dropped on key rotation with no code-level indication. Fresh environment deploys are the highest risk point.
    - **Plain English:** The Maps key is intentionally public with a note saying "it's safe because Google only accepts it from our website." That GCP setting isn't enforced or documented anywhere developers can't miss it. The fix is adding that reminder.
    - **Evidence:**
        ```php
        return response()->json([
            'googleMapsApiKey' => config('services.google_maps.api_key'),
        ])->header('Cache-Control', 'public, max-age=3600');
        ```

---

## Correctness / data integrity

- [x] **#CONS-15** · P2 · Effort: M — `PlatformRefresher` never writes `last_refresh_error` — failure reason silently discarded
    - **Where:** `app/Services/Platforms/PlatformRefresher.php` — all four private `*Payload` methods and `refresh()`
    - **Affects:** Operators debugging stale YouTube/Eventbrite/Apple tiles. `last_refresh_status = 'unavailable'` is written but `last_refresh_error` is always NULL, making every failure look identical regardless of cause (network error, empty response, missing payload key, upstream outage).
    - **What to do:**
        - Have each `*Payload` method return `['payload' => array|null, 'error' => string|null]` instead of bare `?array`.
        - In `refresh()`, write `last_refresh_error` when `$next === null`.
        - The success path already sets `last_refresh_error => null`; this only touches the failure branch.
    - **Technical:** The `last_refresh_error` column exists in the migration (`20260602150238`) for forensic debugging. Currently, `if ($next === null)` fills `last_refresh_status` and `consecutive_failures` but never touches `last_refresh_error`. At 200+ daily refreshes, debugging stale content is a log-trawl. Confirmed by both LIFE-4 (core) and OBS-3 (observability) lenses independently.
    - **Plain English:** When the daily refresh fails for a user's YouTube, the database records "failed" but not why — out of date playlist? YouTube throttle? Network error? The "why" column exists but nobody fills it in.
    - **Evidence:**
        ```php
        if ($next === null) {
            $connection->forceFill([
                'last_refresh_status' => 'unavailable',
                'consecutive_failures' => (int) $connection->consecutive_failures + 1,
                // last_refresh_error never written
            ])->saveQuietly();
        }
        ```

- [x] **#CONS-16** · P2 · Effort: S — `FreshaController::fetchEmployeeServices` swallows all failures silently — hash rotation is invisible to ops
    - **Where:** `app/Http/Controllers/Api/Platforms/FreshaController.php` — `fetchEmployeeServices()` (all three null-return paths)
    - **Affects:** All users with Fresha per-employee service menus. When Fresha redeploys and rotates `BOOKING_INIT_HASH`, every per-employee service fetch silently returns null and the dashboard falls back to the whole-location menu — no log, no alert.
    - **What to do:**
        - Add `Log::warning('fresha.employee_services.failed', ['slug' => $slug, 'employee_id' => $employeeId, 'error' => $e->getMessage()])` in the `catch (Throwable)` block.
        - Add a similar log on the `! $response->ok()` branch with `'status' => $response->status()`.
    - **Technical:** Three silent null-return paths: network exception (`catch (Throwable) { return null }`), non-2xx response, and missing/malformed categories field. The class comment acknowledges hash rotation as a documented inevitability. The connection row has unused `last_refresh_error` and `consecutive_failures` columns that could surface this to the dashboard.
    - **Plain English:** When Fresha updates their app (which happens every few weeks), the per-employee service fetch breaks silently. Nobody gets an error — the dashboard just shows less accurate information. One log line makes this visible in Nightwatch so ops can push a config update.
    - **Evidence:**
        ```php
        } catch (Throwable) {
            return null;  // ← no log, no error recorded
        }
        if (! $response->ok()) {
            return null;  // ← no log
        }
        ```

- [x] **#CONS-17** · P2 · Effort: S — Instagram Apify daily budget counter has a read-modify-write race
    - **Where:** `app/Http/Controllers/Api/Platforms/InstagramController.php` — `guardApifyBudget()`
    - **Affects:** Cost control. The `APIFY_DAILY_CAP = 200` limit can be exceeded by the number of concurrent connect requests at the cap boundary. The code comment acknowledges this as "good enough for a pilot — backend dev to harden."
    - **What to do:**
        - Replace `Cache::get` + `Cache::put` with atomic `Cache::add($dayKey, 0, now()->addDay())` (initialise once) then `Cache::increment($dayKey)` and compare the returned value against `APIFY_DAILY_CAP`.
    - **Technical:** Classic check-then-act race: two concurrent requests both read 199, both pass the `>= 200` guard, both write 200 — one Apify call beyond the cap slips through. Redis `INCR` (Laravel's `Cache::increment`) is atomic and closes this with a two-line change. Confirmed by LIFE-1 (core) and OBS-4 (observability) independently.
    - **Plain English:** The daily Instagram budget uses a "read, check, then write" counter — like two cashiers both deciding there's room for one more customer before either updates the total. An atomic Redis increment is like a turnstile that counts each person as they walk through.
    - **Evidence:**
        ```php
        $count = (int) Cache::get($dayKey, 0);
        if ($count >= self::APIFY_DAILY_CAP) { return $this->error(..., 429); }
        Cache::put($dayKey, $count + 1, now()->addDay());
        // Code comment: "good enough for a pilot — backend dev to harden"
        ```

- [x] **#CONS-18** · P2 · Effort: S — `ShopifyController::brandProducts` writes catalog cache with unjittered `DateTimeInterface` TTL
    - **Where:** `app/Http/Controllers/Api/Platforms/ShopifyController.php:269`
    - **Affects:** Dashboard users opening the Shopify product picker. Concurrent cold misses at the 10-minute boundary each independently scrape `/products.json` from the same store.
    - **What to do:**
        - Replace `now()->addMinutes(self::CATALOG_TTL_MINUTES)` with `JitteredTtl::applyJitter(self::CATALOG_TTL_MINUTES * 60)`.
        - Optionally wrap the scrape in `CacheLockService::rememberLocked` to coalesce concurrent cold misses to one scrape per brand.
    - **Technical:** `JitteredTtl::applyJitter()` is available via the `JitteredTtl` concern and resolves this with a one-line change. `DateTimeInterface` TTLs bypass jitter entirely — integer seconds are required.
    - **Plain English:** The product catalog cache expires at the exact same second for everyone viewing the same store. Adding a small random wobble (9–11 minutes instead of exactly 10) means concurrent users almost never collide.
    - **Evidence:**
        ```php
        private const CATALOG_TTL_MINUTES = 10;
        Cache::put($this->catalogKey($id), $products, now()->addMinutes(self::CATALOG_TTL_MINUTES));
        // DateTimeInterface TTL — bypasses JitteredTtl
        ```

- [x] **#CONS-19** · P2 · Effort: S — Apify cost-control limits hardcoded class constants — tuning requires a code deploy
    - **Where:** `app/Http/Controllers/Api/Platforms/InstagramController.php:30–31`
    - **Affects:** Operations. Adjusting the paid-scraper cooldown or daily budget cap requires a full pipeline deploy. The class comment explicitly flags these as pilot controls to "tune/extend."
    - **What to do:**
        - Move `APIFY_COOLDOWN_SECONDS` and `APIFY_DAILY_CAP` to `config/partna.php` under `limits.platforms.instagram`.
        - Add entries to `.env.example` with comments on their purpose.
        - Reference via `config('partna.limits.platforms.instagram.apify_cooldown_seconds', 600)`.
    - **Technical:** The controller already calls `config('services.apify.token')`, confirming familiarity with the pattern. Moving these to config means: update env var → restart. Currently: edit code → PR → review → deploy.
    - **Plain English:** The Instagram scraping speed limit and daily budget are baked into the code with a note saying "someone should tune these." Changing them currently means shipping a deployment. Moving to a settings file means changing a dial.
    - **Evidence:**
        ```php
        // Pilot cost controls for the paid Apify scraper. backend dev to tune/extend.
        private const APIFY_COOLDOWN_SECONDS = 600;
        private const APIFY_DAILY_CAP = 200;
        ```

- [x] **#CONS-20** · P2 · Effort: S — `CloudflarePurgeService::purgeHandle()` hardcodes `partna.au` base domain
    - **Where:** `app/Services/Cloudflare/CloudflarePurgeService.php:90–96`
    - **Affects:** Cache purging in non-production environments. The method reads `config('app.url')` for one URL form but hardcodes `partna.au` for three others — half-config, half-hardcoded. In a staging environment with a different TLD, three out of four purge targets silently miss the correct zone.
    - **What to do:**
        - Extract `config('services.cloudflare.base_domain', parse_url(config('app.url'), PHP_URL_HOST))`.
        - Replace the three hardcoded `https://{$h}.partna.au/...` strings.
        - Add `CLOUDFLARE_BASE_DOMAIN=partna.au` to `.env.example`.
    - **Technical:** `config('app.url')` is already used on line 98 for one URL form — the inconsistency is a copy-paste gap when the method was extended.
    - **Plain English:** The cache purge tool reads the address from a settings file for one destination, but has "partna.au" typed directly for three others. If the domain ever changes, three of four purge calls hit the wrong place.
    - **Evidence:**
        ```php
        $urls = [
            "https://{$h}.partna.au/",         // ← hardcoded
            "https://{$h}.partna.au",           // ← hardcoded
            "https://{$h}.partna.au/_swr-shadow/",  // ← hardcoded
        ];
        $apiBase = rtrim((string) config('app.url', ''), '/');  // ← config-driven
        ```

- [ ] **#CONS-21** · P2 · Effort: M — Instagram mirrored images are never deleted when a connection is removed or purged (R2 storage leak)
    - **Where:** `app/Http/Controllers/Api/Platforms/InstagramController.php` — `forget()`; `app/Observers/Core/IntegrationConnectionObserver.php` — `deleted()`
    - **Affects:** R2 storage costs. Every connect/disconnect cycle leaves a `platforms/instagram/{timestamp}/` folder of orphaned image and profile-picture files in object storage indefinitely.
    - **What to do:**
        - Store the R2 folder path in the payload at write time: add `'_folder' => $folder` inside `buildSelection()`.
        - In `IntegrationConnectionObserver`, listen to `forceDeleted()` and dispatch a queued job that reads `payload['_folder']` and deletes all objects under that prefix from `Storage::disk('media')`.
        - Add the same cleanup to the soft-delete path so objects are removed at disconnect time rather than waiting for the 30-day purge.
    - **Technical:** `Observer::deleted()` already fires on soft-delete and dispatches `CloudflareCachePurgeJob` — extending it to also queue R2 cleanup (gated on `platform === 'instagram'`) is the natural hook. Note: once `_folder` is added to payload, update CONS-11's Resource allowlist to exclude it from the public endpoint.
    - **Plain English:** Every time someone connects their Instagram, photo copies are stored in our image storage. When they disconnect, the database row is marked removed — but the photo copies stay forever. Each connect/disconnect cycle adds another unclaimed folder.
    - **Evidence:**
        ```php
        $folder = 'platforms/instagram/'.now()->timestamp;
        $images = $this->mirrorAll($coverUrls, $folder);
        $this->writeConnection($user, $selection);  // ← $folder not stored separately

        // Observer::deleted() — only purges Cloudflare edge cache, not R2:
        public function deleted(IntegrationConnection $connection): void
        {
            $this->purge($connection);  // → CloudflareCachePurgeJob only
        }
        ```

- [x] **#CONS-22** · P2 · Effort: S — `InstagramScraper` log entries lack user/connection correlation context
    - **Where:** `app/Services/Platforms/InstagramScraper.php` — all three `Log::warning` calls in `fetchProfile()`
    - **Affects:** Nightwatch incident correlation. Every Apify failure looks identical across all users — a spike cannot be attributed to a specific user or connection.
    - **What to do:**
        - Add `user_id` and `platform_connection_id` parameters to `fetchProfile()`.
        - Include both in all three `Log::warning` calls.
        - Remove `'body'` from the `not_ok` log (see CONS-12 — addresses both concerns simultaneously).
    - **Technical:** `InstagramController::connect()` already holds `$user->id`, so threading the ID down is a one-line caller change plus a parameter addition. Without `user_id`, every `instagram.apify.not_ok` across 200 users is indistinguishable in Nightwatch.
    - **Plain English:** When the Instagram scraper fails, the error log records "failed for @username" but not which of 200 users that is. Adding the user's account ID makes the error immediately actionable without cross-referencing the user database.
    - **Evidence:**
        ```php
        Log::warning('instagram.apify.threw', ['username' => $username, 'error' => $e->getMessage()]);
        // No user_id or platform_connection_id in any of the three warning calls
        ```

---

## Observability

- [x] **#CONS-23** · P2 · Effort: S — `IntegrationConnectionObserver` swallows Throwable without Nightwatch visibility — stale edge cache is silent
    - **Where:** `app/Observers/Core/IntegrationConnectionObserver.php:51–55`
    - **Affects:** Every platform-connection write. If the user→site lookup or `CloudflareCachePurgeJob::dispatch()` fails persistently, the sitepage edge cache is never purged and the public page serves stale content indefinitely — with zero Nightwatch alert.
    - **What to do:**
        - Add `report($e)` as the first statement inside the `catch (\Throwable $e)` block.
        - Keep the existing `Log::warning` — it provides structured context for correlation.
        - Do not re-throw — observers must not crash the parent write.
    - **Technical:** `report()` forwards the exception to Nightwatch's exception tracker without re-throwing. Currently only `Log::warning` fires — a Nightwatch exception event is never generated. A Redis outage or broken `core.users → site.sites` join produces a steady stream of suppressed warnings while the sitepage silently goes stale.
    - **Plain English:** A smoke alarm wired to write a sticky note instead of sounding a siren. One line makes it ring.
    - **Evidence:**
        ```php
        } catch (\Throwable $e) {
            Log::warning('IntegrationConnectionObserver purge failed', [...]);
            // No report($e)
        }
        ```

- [x] **#CONS-24** · P2 · Effort: S — `RefreshIntegrationConnectionsCommand` per-connection catch swallows Throwable without Nightwatch visibility
    - **Where:** `app/Console/Commands/RefreshIntegrationConnectionsCommand.php:39–45`
    - **Affects:** The daily `integrations:refresh` cron. A systemic failure (broken scraper, schema mismatch) increments `$failed` and writes a warning log but produces no Nightwatch exception event — the cron appears healthy while 300 connections silently error.
    - **What to do:**
        - Add `report($e)` inside the catch block before `$failed++`.
        - Keep the `Log::warning` with existing structured context.
        - The continue-on-error strategy is correct — only `report()` is missing.
    - **Technical:** Structurally identical to CONS-23. Because the catch wraps individual iterations inside a `foreach`, systemic failure (every connection throwing) produces N suppressed warnings and a final artisan summary — zero Nightwatch exception events.
    - **Plain English:** The daily refresh job quietly counts failures but never rings an alarm. If the whole refresh pipeline breaks, the dashboard shows "completed successfully" and errors are buried in log files.
    - **Evidence:**
        ```php
        } catch (\Throwable $e) {
            $failed++;
            Log::warning('integrations:refresh failed for a connection', [...]);
            // No report($e)
        }
        ```

- [x] **#CONS-25** · P2 · Effort: S — `RefreshIntegrationConnectionsCommand` reports "N ok, M failed" but cannot distinguish genuine updates from no-ops
    - **Where:** `app/Console/Commands/RefreshIntegrationConnectionsCommand.php` — `handle()` method
    - **Affects:** Operators monitoring the daily cron. "300 ok" could mean 300 genuine content updates or 300 no-ops — there's no way to tell.
    - **What to do:**
        - After `$refresher->refresh($connection)`, check `$refreshed->wasChanged('payload')` and track a separate `$updated` counter.
        - Output: `"Platform connections refreshed: {$ok} ok ({$updated} with new content), {$failed} failed."`
    - **Technical:** `PlatformRefresher::refresh()` only calls `$connection->update(['payload' => $next, ...])` when content changes, so `wasChanged('payload')` is a reliable signal. At 300 connections daily, ops need to know whether the cron is doing useful work.
    - **Plain English:** The nightly refresh reports "all OK" but doesn't say whether any account actually got new content. Like a security guard reporting "completed rounds" without noting which doors were found unlocked.
    - **Evidence:**
        ```php
        $ok = 0; $failed = 0;
        // No $updated counter anywhere in handle()
        $this->info("Platform connections refreshed: {$ok} ok, {$failed} failed ...");
        ```

---

## Test coverage

- [x] **#CONS-26** · P2 · Effort: S — `CloudflareCachePurgeJob::failed()` path is untested — terminal failure Nightwatch alerting is unverified
    - **Where:** `app/Jobs/Cloudflare/CloudflareCachePurgeJob.php:76–81`; `tests/Unit/Jobs/CloudflareCachePurgeJobTest.php`
    - **Affects:** Nightwatch observability. The test file covers `handle()`, `uniqueId()`, `uniqueFor`, and queue assignment but never invokes `failed()`. An accidental deletion of the `report()` call would silently stop terminal failures from appearing in Nightwatch.
    - **What to do:**
        - Add `it('reports exception on terminal failure')` — call `$job->failed(new \RuntimeException('zone error'))`, spy the exception handler, assert `report()` called once and `Log::error` fired with `cloudflare.cache_purge.failed`, `handle`, and `error` keys.
    - **Evidence:**
        ```php
        public function failed(Throwable $e): void
        {
            report($e);
            Log::error('cloudflare.cache_purge.failed', ['handle' => $this->handle, 'error' => $e->getMessage()]);
        }
        // No test exercises this path
        ```

- [ ] **#CONS-27** · P2 · Effort: S — Migration CHECK constraint and UNIQUE partial index have no DB-level rejection tests
    - **Where:** `supabase/migrations/20260602150238_create_platform_connections.sql:17–20` (CHECK), `:36–38` (UNIQUE index)
    - **Affects:** Data integrity. An invalid `platform` value or a duplicate active `(user_id, platform, resource_id)` triple is blocked at the DB layer, but that guard has never been verified by a test. A future migration refactor that inadvertently drops the CHECK would pass CI.
    - **What to do:**
        - Add `it('rejects an invalid platform value')` — insert `platform = 'invalid'` and assert a DB exception.
        - Add `it('rejects a duplicate active (user_id, platform, resource_id) row')` — assert the second insert fails with a unique-constraint violation.
        - Place in `tests/Feature/Platforms/PlatformConnectionModelTest.php`.
    - **Evidence:**
        ```sql
        platform text NOT NULL CHECK (platform IN ('shopify', 'eventbrite', 'apple-music', ...)),
        CREATE UNIQUE INDEX ... ON site.platform_connections (user_id, platform, resource_id)
            WHERE deleted_at IS NULL;
        ```

- [x] **#CONS-28** · P2 · Effort: S — `RefreshIntegrationConnectionsCommand` failure path is untested — `consecutive_failures` increment logic never exercised
    - **Where:** `app/Services/Platforms/PlatformRefresher.php:43–49`; `app/Console/Commands/RefreshIntegrationConnectionsCommand.php:50–57`; `tests/Feature/Platforms/RefreshPlatformConnectionsCommandTest.php` (happy path only)
    - **Affects:** Cron observability — the `consecutive_failures` counter exists to detect persistent scrape failures, but its increment logic has never been exercised. A silent bug preventing the counter from advancing would be invisible.
    - **What to do:**
        - Add `it('records unavailable status when scraper returns no videos')` — mock `fetchRecentVideos` to return `[]`, run the command, assert `last_refresh_status = 'unavailable'` and `consecutive_failures = 1`.
        - Add `it('catches scraper exceptions without crashing the command loop')` — mock to throw, assert command exits `SUCCESS` and the connection status remains intact.
    - **Evidence:**
        ```php
        if ($next === null) {
            $connection->forceFill([
                'last_refresh_status' => 'unavailable',
                'consecutive_failures' => (int) $connection->consecutive_failures + 1,
            ])->saveQuietly();
        }
        // Existing test only exercises the happy path (new video returned)
        ```

---

## P3 — Nice to have

- [ ] **#CONS-29** · P3 · Effort: S — UUID primary key on `site.platform_connections` has no DB-side default
    - **Where:** `supabase/migrations/20260602150238_create_platform_connections.sql:1`
    - **Affects:** Raw SQL inserts and admin scripts that bypass Eloquent.
    - **What to do:** Add migration: `ALTER TABLE site.platform_connections ALTER COLUMN id SET DEFAULT gen_random_uuid();`
    - **Evidence:** `CREATE TABLE IF NOT EXISTS site.platform_connections (id uuid PRIMARY KEY,` — no `DEFAULT gen_random_uuid()`

- [x] **#CONS-30** · P3 · Effort: S — `YoutubeThumbnailResolver` writes verdict cache with unjittered `DateTimeInterface` TTL
    - **Where:** `app/Services/Platforms/YoutubeThumbnailResolver.php:115`
    - **What to do:** Replace `now()->addDays(self::CACHE_DAYS)` with `JitteredTtl::applyJitter(self::CACHE_DAYS * 86400)`.
    - **Evidence:** `Cache::put($this->cacheKey($id), ..., now()->addDays(self::CACHE_DAYS));`

- [x] **#CONS-31** · P3 · Effort: S — Instagram cooldown and daily counter TTLs not jittered
    - **Where:** `app/Http/Controllers/Api/Platforms/InstagramController.php:297, 304`
    - **What to do:** Apply `JitteredTtl::applyJitter()` to cooldown and daily counter TTLs (integer seconds only — see note about `DateTimeInterface` in CONS-18). Combines naturally with the CONS-17 atomic increment fix.
    - **Evidence:** `Cache::add($cooldownKey, 1, self::APIFY_COOLDOWN_SECONDS)` and `now()->addDay()` — neither jittered.

- [x] **#CONS-32** · P3 · Effort: S — Ad-hoc cache key construction in three files bypasses `CacheKeyGenerator`
    - **Where:** `ShopifyController.php:279–281` (`catalogKey()`), `YoutubeThumbnailResolver.php:120–122` (`cacheKey()`), `InstagramController.php:296, 299` (inline key strings)
    - **What to do:** Add `shopifyBrandCatalog()`, `youtubeThumbnailVerdict()`, `instagramCooldown()`, `instagramDailyLimit()` to `CacheKeyGenerator`. Replace inline constructions.
    - **Evidence:** `"yt_thumb:{$videoId}"`, `"platforms:instagram:cooldown:{$user->id}"` etc. — not registered in `CacheKeyGenerator`.

- [x] **#CONS-33** · P3 · Effort: S — Fresha persisted-query hash and client version hardcoded — rotation requires a full code deploy
    - **Where:** `app/Http/Controllers/Api/Platforms/FreshaController.php:32–33`
    - **What to do:** Move `BOOKING_INIT_HASH` and `FRESHA_CLIENT_VERSION` to `config/services.php` under `fresha`. Add `FRESHA_BOOKING_INIT_HASH` / `FRESHA_CLIENT_VERSION` to `.env.example` with rotation cadence note.
    - **Evidence:** Code comment: "rotate when they redeploy" — yet values are `private const` class constants.

- [ ] **#CONS-34** · P3 · Effort: S — `created_at`/`updated_at` have no `DEFAULT now()` on `site.platform_connections`
    - **Where:** `supabase/migrations/20260602150238_create_platform_connections.sql`
    - **What to do:** `ALTER TABLE site.platform_connections ALTER COLUMN created_at SET DEFAULT now(), ALTER COLUMN updated_at SET DEFAULT now();`
    - **Evidence:** `created_at timestamptz, updated_at timestamptz,` — no DEFAULT clause.

- [x] **#CONS-35** · P3 · Effort: S — Platform controllers use inline `$request->validate()` instead of Form Request classes
    - **Where:** `AppleController`, `EventbriteController`, `FreshaController`, `InstagramController`, `ShopifyController`, `TiktokController`, `YoutubeController` — all action methods
    - **What to do:** Extract validation rules into Form Request classes. The Form Request `authorize()` method is the canonical home for `authorizeForUser` calls (CONS-10), so creating these classes bundles both fixes.
    - **Evidence:** `$validated = $request->validate(['artist' => ['required', 'string', 'max:200']]);` — representative of pattern across all controllers.

- [x] **#CONS-36** · P3 · Effort: S — `EventbriteController::filterPastEvents` null-both-dates path untested
    - **Where:** `app/Http/Controllers/Api/Platforms/EventbriteController.php:83–88`
    - **What to do:** Add `it('keeps an event with no dates at all')` — seed payload with both dates null alongside a past event; assert dateless event survives while past event is dropped.
    - **Evidence:** `$end = $e['endDate'] ?? $e['startDate'] ?? null; return $end === null || $end >= $now;` — null-both path is intentional but unasserted.

- [x] **#CONS-37** · P3 · Effort: S — `PublicConfigController` bypasses `ApiController::success()` envelope — inconsistent response shape for frontend
    - **Where:** `app/Http/Controllers/Api/PublicSite/PublicConfigController.php:44–49, 63–68`
    - **What to do:** Add `successCached(array $data, int $maxAge = 3600): JsonResponse` helper on `ApiController`. Replace two `response()->json()->header()` calls.
    - **Evidence:** `return response()->json([...])->header('Cache-Control', 'public, max-age=3600');` — raw response bypasses `success()` envelope used everywhere else.

- [ ] **#CONS-38** · P3 · Effort: L — Platform controllers return hand-assembled arrays without Resource classes — no canonical field allowlist per platform
    - **Where:** All 8 platform controllers — connect/highlights/saveSelection response arrays
    - **What to do:** Create per-platform Resource classes under `app/Http/Resources/Platforms/`. Each Resource's `toArray()` becomes the canonical allowlist. Reuse from the public endpoint (CONS-11) via `$this->when(...)` guards.
    - **Evidence:** Eight controllers each define their own response shape inline with no shared contract. Same structural gap that let `latest` drift (CONS-1).

- [x] **#CONS-39** · P3 · Effort: M — Five near-duplicate Apple Music/Podcast method pairs create five independent drift surfaces
    - **Where:** `app/Http/Controllers/Api/Platforms/AppleController.php` — pairs `connectMusic`/`connectPodcast`, `musicRecent`/`podcastRecent`, `musicHighlights`/`podcastHighlights`, `musicSelection`/`podcastSelection`, `forgetMusic`/`forgetPodcast`
    - **What to do:** Extract a private generic method per operation accepting the platform constant, scraper callable, and platform-specific field names. Keep public methods as one-line adapters.
    - **Technical:** The `latest` key drift (CONS-1) illustrates the failure mode: a fix applied to one pair member that misses the sibling. Five 8–20-line pairs is past the "three similar lines" threshold.

- [x] **#CONS-40** · P3 · Effort: S — "Refresh most-recent tile" block copy-pasted across three controllers — the same structural gap that produced CONS-1
    - **Where:** `AppleController.php` — `musicHighlights()`, `podcastHighlights()`; `YoutubeController.php` — `highlights()`; comments in each explicitly acknowledge the mirroring
    - **What to do:** Extract a private `refreshLatestTile(array &$selection, array $items, string $backCompatField): void` method, or a trait method on `ManagesIntegrationConnection`.
    - **Technical:** `PlatformRefresher::youtubePayload()` reimplements this same pattern in a fourth location without a shared reference — the absence of a canonical helper was the root cause of CONS-1.

- [x] **#CONS-41** · P3 · Effort: S — `PlatformRefresher` returns `null` for both "bad payload shape" and "network failure" — failure types are indistinguishable
    - **Where:** `app/Services/Platforms/PlatformRefresher.php` — all four private `*Payload` methods
    - **What to do:** Distinguish the "missing required key" early-return (use `last_refresh_status = 'error'` + `last_refresh_error = "missing_key: handle"`) from the "scraper returned empty" return (keep `'unavailable'`). Log a `Log::warning` at the shape-failure branch.
    - **Evidence:** `$handle = $payload['handle'] ?? null; if (! $handle) { return null; }` — same `null` path as a live network failure.

---

## Suggested Bundled Sessions

### Bundle A — P1 Data correctness (2–3h) · CONS-1, CONS-4, CONS-5
Fix the YouTube `latest` key (30 min), add per-user Redis locks to Apple/YouTube highlights (1–2h), write the Policy tests (1h). These are all codebase-contained, zero-DB-migration changes.

### Bundle B — P1 Concurrency + Storage races (2–4h) · CONS-2, CONS-3, CONS-17, CONS-18
Shopify brand-map lock, Fresha service-visibility lock, Apify counter atomic increment, ShopifyController jittered TTL. All Redis-primitive changes in platform controllers.

### Bundle C — Observability sweep (1h) · CONS-23, CONS-24, CONS-12, CONS-22, CONS-16, CONS-15
`report($e)` in observer + command, remove PII from InstagramScraper log, add `user_id` context to logs, add Fresha warning logs, write `last_refresh_error`. All one-line or near-one-line changes.

### Bundle D — Config hygiene (1h) · CONS-19, CONS-20, CONS-33, CONS-37
Move Apify limits to config, fix CloudflarePurge domain hardcode, move Fresha hash to config, wrap PublicConfigController in `successCached`. All config/constant changes, no schema.

### Bundle E — Test coverage (2–3h) · CONS-5, CONS-26, CONS-27, CONS-28, CONS-36
Policy tests, failed() handler test, migration constraint tests, refresh failure-path tests, Eventbrite null-dates test. No code changes; pure test additions.

### Bundle F — Schema/security hardening (1h) · CONS-13, CONS-14, CONS-29, CONS-34
RLS on platform_connections, `.env.example` Maps key note, UUID default, timestamp defaults. Two Supabase migrations + two doc changes.

### Standalone — do NOT bundle

- **CONS-6** (Instagram async connect) — L complexity, requires 202/status-poll contract change with frontend. Own PR.
- **CONS-7** (Eventbrite parallel HTTP pool) — test-harness changes needed for async responses. Own PR.
- **CONS-8** (Queue isolation) — requires Horizon supervisor changes + deploy coordination. Own PR.
- **CONS-10** (Authorise via Policy gate, L effort) — high blast radius across all 8 controllers + AppleController private helpers. Own PR with dedicated review.
- **CONS-11** (Public endpoint Resource class, M) — requires per-platform payload field inventory. Coordinate with CONS-38.
- **CONS-21** (Instagram R2 cleanup, M) — requires Observer extension + new cleanup job. Coordinate with CONS-11 (`_folder` exclusion from public Resource).
