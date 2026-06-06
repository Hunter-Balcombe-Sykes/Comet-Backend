`★ Insight ─────────────────────────────────────`
The policy-registration gap DeepSeek flagged in TEST-2 is already closed: `Gate::policy(IntegrationConnection::class, IntegrationConnectionPolicy::class)` exists at line 135 of `AppServiceProvider`. The surviving issue is purely that the trait methods never *call* `authorizeForUser` — the policy is wired to the gate but the gate is never knocked. The Apple/podcast refresher paths both include `'latest'` (lines 120 and 142) via the `...$payload` spread + explicit override; YouTube rebuilds its array from scratch and misses the key, making API-1 asymmetric and real.
`─────────────────────────────────────────────────`

# Pre-Merge Audit (MIG / API / CFG / TEST) — 2026-06-06

**Branch:** development
**Lens:** Bundle 'pre-merge' audit across 4 focused themes: migration safety (MIG-*), API contract (API-*), configuration hygiene (CFG-*), and test coverage gaps (TEST-*) — pre-merge sweep for PRs touching schema, public API, or config
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Services/Platforms/PlatformRefresher.php
- app/Http/Controllers/Api/Platforms/YoutubeController.php
- app/Http/Controllers/Api/Platforms/AppleController.php
- app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php
- app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php
- app/Http/Controllers/Api/PublicSite/PublicConfigController.php
- app/Http/Controllers/Api/Platforms/{EventbriteController,FacebookController,FreshaController,InstagramController,ShopifyController,TiktokController}.php
- app/Policies/IntegrationConnectionPolicy.php
- app/Services/Cloudflare/CloudflarePurgeService.php
- app/Jobs/Cloudflare/CloudflareCachePurgeJob.php
- app/Console/Commands/RefreshIntegrationConnectionsCommand.php
- supabase/migrations/20260602150238_create_platform_connections.sql
- tests/Feature/Platforms/RefreshPlatformConnectionsCommandTest.php
- tests/Unit/Jobs/CloudflareCachePurgeJobTest.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 2 complete
- P2 Medium: 0 of 7 complete
- P3 Low: 0 of 4 complete

---

## P1 — Fix before pilot launch

- [ ] **#TEST-1** · P1 — IntegrationConnectionPolicy has zero tests for its four ability methods
    - **Where:** app/Policies/IntegrationConnectionPolicy.php (all 4 public methods)
    - **Affects:** Every platform connect/read/delete endpoint — the `view`, `update`, `delete`, and `create` policy gates govern all `IntegrationConnection` row operations but have never been exercised by a test.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create `tests/Feature/Policies/IntegrationConnectionPolicyTest.php`.
        - For each of the four abilities: assert owner → `true`, non-owner → 404 (not 403), pending-deletion actor → 423.
        - Assert `denyAsNotFound()` produces a 404 response (not a 403) to confirm the existence-privacy contract.
    - **Technical:** `IntegrationConnectionPolicy` is registered (`Gate::policy(IntegrationConnection::class, IntegrationConnectionPolicy::class)` — confirmed at `AppServiceProvider.php:135`) and all four methods branch on `ownerMatches()` plus `denyIfPendingDeletion()`. Zero tests exercise any path. The 404-not-403 contract (`denyAsNotFound()`) is the key invariant for preventing resource-existence enumeration; without a test, a refactor that inadvertently calls `denyAsForbidden()` would be invisible to CI.
    - **Plain English:** The door policy for platform connections is written and installed, but no one has ever tested it. We don't know if it actually stops the wrong people or gives the right error message. The fix is writing a few checks that confirm "owner gets in, stranger gets a 'not found' (not a 'no entry' — those two messages reveal different information), and an account under deletion gets locked out."
    - **Evidence:**
        ```php
        public function view(User $actor, Model $resource): bool|Response
        {
            return $this->ownerMatches($actor, $resource)
                ? true
                : $this->denyAsNotFound();
        }

        public function update(User $actor, Model $resource): bool|Response
        {
            if ($denied = $this->denyIfPendingDeletion($actor)) {
                return $denied;
            }

            return $this->ownerMatches($actor, $resource)
                ? true
                : $this->denyAsNotFound();
        }
        ```

- [ ] **#API-1** · P1 — PlatformRefresher drops YouTube `latest` key on cron refresh, breaking the dashboard "Most recent" tile for all YouTube-connected users
    - **Where:** app/Services/Platforms/PlatformRefresher.php:61-80 (`youtubePayload` method)
    - **Affects:** Every professional with a YouTube connection after the daily `integrations:refresh` cron runs. The dashboard reads `selection.latest` to render the "Most recent" tile; after a cron refresh that key is absent and the tile renders blank. The connection appears functional (the flat `name`/`thumbnail` fields update) but the nested `latest` object the dashboard depends on is gone.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `'latest' => $latest` to the `youtubePayload()` return array, directly after `'thumbnail' => $latest['thumbnail']`.
        - Update the existing happy-path assertion in `RefreshPlatformConnectionsCommandTest` to also assert `$conn->payload['latest']['videoId']` is set and matches `$conn->payload['name']` context — the test currently passes despite the bug because it only checks `payload['name']` and `payload['highlights']`.
    - **Technical:** `appleMusicPayload()` (line 120) and `applePodcastPayload()` (line 142) both include `'latest' => $latest` via their `...$payload` spread pattern. `youtubePayload()` rebuilds its return array from scratch instead of spreading and therefore silently drops the `latest` key every cron cycle. Commit `feat(platforms): YouTube connect stores nested latest; highlights refreshes it (#191)` added the `latest` key to `YoutubeController::connect()` and `highlights()` but did not touch `PlatformRefresher`, creating an asymmetry where the user-triggered paths preserve the key and the automated nightly refresh strips it.
    - **Plain English:** Every night a background job refreshes YouTube content for all users' public pages. When YouTube's `connect` button is used, the "most recent video" is stored in two ways: a detailed card (called `latest`) and some quick labels. The nightly job updates the quick labels but throws away the detailed card. The next morning, the dashboard opens the drawer expecting the card to be there — it isn't, so the "Most Recent" tile goes blank. The Apple Music and Podcast refreshers don't have this problem; only YouTube was missed when the feature was added.
    - **Evidence:**
        ```php
        // PlatformRefresher::youtubePayload() — 'latest' key absent:
        return [
            'handle' => $handle,
            'name' => $latest['name'],
            'description' => $latest['description'],
            'link' => $latest['link'],
            'thumbnail' => $latest['thumbnail'],
            // User-chosen highlights are preserved — the cron only refreshes the
            // auto-latest tile, not the curated picks.
            'highlights' => $payload['highlights'] ?? [],
        ];
        ```
        ```php
        // Compare: appleMusicPayload() at line 120 — 'latest' key present:
        return [
            ...$payload,
            'latest' => $latest,
            'name' => $latest['name'],
            'thumbnail' => $latest['thumbnail'],
            'releaseDate' => $latest['releaseDate'],
            'link' => $latest['link'],
        ];
        ```
        ```php
        // YoutubeController::connect() — the user-triggered path that set the standard:
        $selection = [
            'handle' => $handle,
            'name' => $latest['name'],
            'description' => $latest['description'],
            'link' => $latest['link'],
            'thumbnail' => $latest['thumbnail'],
            'latest' => $latest,          // ← missing from PlatformRefresher
            'highlights' => $highlights,
        ];
        ```

---

## P2 — Should fix

- [ ] **#TEST-4** · P2 — `CloudflareCachePurgeJob::failed()` is untested despite containing a `report()` + `Log::error` side-effect
    - **Where:** app/Jobs/Cloudflare/CloudflareCachePurgeJob.php:76-81; tests/Unit/Jobs/CloudflareCachePurgeJobTest.php
    - **Affects:** Nightwatch observability — if a purge fails after all 3 retries, the `report()` call is the mechanism that surfaces the incident. The test file covers `handle()`, `uniqueId()`, `uniqueFor`, and queue assignment, but never invokes `failed()`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('reports exception on terminal failure')` to the existing `CloudflareCachePurgeJobTest.php`.
        - Call `$job->failed(new \RuntimeException('zone error'))`, spy the exception handler, assert `report()` was called once with the exception.
        - Assert `Log::error` was called with channel key `cloudflare.cache_purge.failed`, `handle`, and `error` keys (no PII).
    - **Technical:** Per-job `failed()` handlers are the contract between queue infrastructure and Nightwatch. The handler calls both `report($e)` (creates a Nightwatch exception event) and `Log::error(...)` (breadcrumb for correlation). Neither path is asserted. An accidental deletion of the `report()` line would silently stop terminal failures from appearing in Nightwatch.
    - **Plain English:** When a cache purge fails three times and gives up, it's supposed to trigger an alert and log the failure. We've built the alert trigger, but we've never pressed the test button to confirm it actually fires. Adding one small test makes sure the alarm still rings after any future code change to this job.
    - **Evidence:**
        ```php
        public function failed(Throwable $e): void
        {
            report($e);
            Log::error('cloudflare.cache_purge.failed', [
                'handle' => $this->handle,
                'error' => $e->getMessage(),
            ]);
        }
        ```

- [ ] **#TEST-3** · P2 — Migration CHECK constraint and UNIQUE partial index have no DB-level rejection tests
    - **Where:** supabase/migrations/20260602150238_create_platform_connections.sql:17-20 (CHECK), :36-38 (UNIQUE index)
    - **Affects:** Data integrity — an invalid `platform` value or a duplicate active `(user_id, platform, resource_id)` triple is blocked at the DB layer, but that guard has never been verified by a test.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('rejects an invalid platform value')` — attempt to insert `platform = 'invalid'` and assert a DB exception is thrown.
        - Add `it('rejects a duplicate active (user_id, platform, resource_id) row')` — insert two rows with identical `(user_id, platform, resource_id)` where `deleted_at IS NULL` and assert the second fails with a unique-constraint violation.
        - Place both in the existing `tests/Feature/Platforms/PlatformConnectionModelTest.php`.
    - **Technical:** The CHECK enumerates 9 valid platform values; the partial UNIQUE index prevents two active connections for the same resource. A future migration refactor that inadvertently drops the CHECK constraint would silently accept garbage `platform` strings. The UNIQUE violation would surface as an unhandled 500 in production on a race condition during concurrent connects.
    - **Plain English:** The database has a guard that says "only these 9 platform names are allowed" and "no duplicate active connections." Those guards have never been tested to confirm they actually block bad data — like a smoke detector that's never had its batteries checked. Two small tests would confirm the guards work before anything reaches production.
    - **Evidence:**
        ```sql
        platform text NOT NULL CHECK (platform IN (
            'shopify', 'eventbrite', 'apple-music', 'apple-podcast',
            'youtube', 'instagram', 'fresha', 'tiktok', 'facebook'
        )),
        ```
        ```sql
        CREATE UNIQUE INDEX IF NOT EXISTS idx_platform_connections_unique_active
            ON site.platform_connections (user_id, platform, resource_id)
            WHERE deleted_at IS NULL;
        ```

- [ ] **#TEST-5** · P2 — `RefreshIntegrationConnectionsCommand` failure path (scrape fails → `last_refresh_status = 'unavailable'`, `consecutive_failures` incremented) is untested
    - **Where:** app/Services/Platforms/PlatformRefresher.php:43-49 (null-payload → force-fill); app/Console/Commands/RefreshIntegrationConnectionsCommand.php:50-57 (catch Throwable); tests/Feature/Platforms/RefreshPlatformConnectionsCommandTest.php (happy path only)
    - **Affects:** Cron observability — the `consecutive_failures` counter exists precisely to detect persistent scrape failures, but its increment logic has never been exercised by a test. A silent bug that prevented the counter from advancing would be invisible until production monitoring noticed stale content.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('records unavailable status when the scraper returns no videos')` — mock `fetchRecentVideos` to return `[]`, run the command, assert `last_refresh_status` is `'unavailable'` and `consecutive_failures` is `1`.
        - Add `it('catches scraper exceptions without crashing the command loop')` — mock `fetchRecentVideos` to throw a `\RuntimeException`, assert the command exits `SUCCESS` and the connection's status remains intact (no crash).
    - **Technical:** `PlatformRefresher::refresh()` returns early with `last_refresh_status = 'unavailable'` and increments `consecutive_failures` when a platform's payload resolver returns null (empty videos, failed scrape). The command wraps individual refreshes in a try/catch and increments `$failed`. Both paths run entirely blind — the only test exercises a happy-path scrape that returns a new video.
    - **Plain English:** The daily refresh job has a counter for "how many accounts couldn't be updated." We test that the job works correctly when everything succeeds. We've never tested what happens when YouTube returns nothing or throws an error — which is exactly the scenario the counter is built for. One test with a deliberately broken scraper would confirm the failure path actually increments the counter instead of silently no-oping.
    - **Evidence:**
        ```php
        // PlatformRefresher — failure path:
        if ($next === null) {
            $connection->forceFill([
                'last_refresh_status' => 'unavailable',
                'consecutive_failures' => (int) $connection->consecutive_failures + 1,
            ])->saveQuietly();
            return $connection;
        }
        ```
        ```php
        // Command — error counter:
        } catch (\Throwable $e) {
            $failed++;
            Log::warning('integrations:refresh failed for a connection', [
                'platform_connection_id' => $connection->id,
                'platform' => $connection->platform,
                'message' => $e->getMessage(),
            ]);
        }
        ```

- [ ] **#API-2** · P2 — Public integrations endpoint returns raw `payload` JSONB without a Resource transform; no guard against future field leakage
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php:54-60
    - **Affects:** All unauthenticated consumers of `GET /api/public/profiles/{handle}/platforms`. Any field added to an `IntegrationConnection` payload in the future automatically becomes part of the public API contract with no review gate.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create `app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php` with an explicit `toArray()` allowlisting `resourceId`, `payload`, and `lastRefreshedAt`.
        - For the pilot, this is a pass-through — but once the allowlist exists, future payload additions require a deliberate `$this->when(...)` decision before becoming public.
        - Replace the inline `->map()` in `show()` with the Resource.
    - **Technical:** Partna architecture mandates Resource classes for all API responses so the public contract is defined in one auditable place. The `PublicIntegrationController::show()` method constructs its response via an inline `->map()` returning `['resourceId' => ..., 'payload' => $r->payload, ...]` — no Resource sits between the model and the wire. All current payload fields are intentionally public-facing, but there is no mechanical barrier preventing a future platform controller from storing an internal field (e.g. a scrape token, a rate-limit timestamp, an internal error detail) that would be silently promoted to the public API on the next write.
    - **Plain English:** Every other endpoint in the app puts API responses through a "filter layer" that explicitly lists what gets sent to the outside world. The public platforms endpoint bypasses this filter entirely — whatever gets written into the database goes straight out the door. Right now that's fine because all the data is meant to be public. But if a developer ever stores an internal note or a key in a platform's data, it would automatically appear on every public profile page with no warning. Installing the filter now, even as a pass-through, means future additions have to be deliberate.
    - **Evidence:**
        ```php
        $platforms = IntegrationConnection::query()
            ->where('user_id', $userId)
            ->active()
            ->orderBy('platform')
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get(['platform', 'resource_id', 'payload', 'last_refreshed_at'])
            ->groupBy('platform')
            ->map(fn ($rows) => $rows->map(fn (IntegrationConnection $r) => [
                'resourceId' => $r->resource_id,
                'payload' => $r->payload,               // ← raw JSONB, no transform
                'lastRefreshedAt' => $r->last_refreshed_at?->toIso8601String(),
            ])->values())
            ->toArray();
        ```

- [ ] **#CFG-2** · P2 — `InstagramController` hardcodes Apify cost-control limits with an explicit "tune/extend" comment instead of reading from config
    - **Where:** app/Http/Controllers/Api/Platforms/InstagramController.php:30-31
    - **Affects:** Operations — adjusting the paid-scraper cooldown or daily budget cap requires a code change and full pipeline deploy rather than an env-var update.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move `APIFY_COOLDOWN_SECONDS` and `APIFY_DAILY_CAP` into `config/partna.php` under a `limits.platforms.instagram` key with current values as defaults.
        - Reference them via `config('partna.limits.platforms.instagram.apify_cooldown_seconds', 600)` and `config('partna.limits.platforms.instagram.apify_daily_cap', 200)` in `guardApifyBudget()`.
        - Add corresponding entries to `.env.example` with comments noting their purpose.
    - **Technical:** The comment on lines 28–30 explicitly flags these as pilot controls that "backend dev" should tune — yet they are private class constants, not config values. When Apify costs spike or the cap needs temporary adjustment during a marketing push, the current path is: edit code → PR → review → deploy. Moving to config means: update env var → restart. The controller already calls `config('services.apify.token')` on line 240, confirming it knows the config pattern; the cost controls just weren't moved over.
    - **Plain English:** The Instagram integration uses a paid scraping service. There's a speed limit (how often one user can re-scrape) and a daily budget (how many total scrapes are allowed). Both are baked into the code with a developer note saying "someone should tune these later." Right now, tuning them means editing code and shipping a deployment. Moving them to a settings file means adjusting a dial without touching the codebase — like changing a thermostat instead of rewiring the heating system.
    - **Evidence:**
        ```php
        // Pilot cost controls for the paid Apify scraper. Minimal — backend dev to
        // tune/extend (cover posts/saveSelection, telemetry). A re-connect is
        // rate-limited per user; total daily runs are globally capped.
        private const APIFY_COOLDOWN_SECONDS = 600;
        private const APIFY_DAILY_CAP = 200;
        ```

- [ ] **#CFG-1** · P2 — `CloudflarePurgeService::purgeHandle()` hardcodes the base domain `partna.au` instead of deriving it from config
    - **Where:** app/Services/Cloudflare/CloudflarePurgeService.php:90-96
    - **Affects:** Cache purging in staging/non-production environments; a domain change requires a code deployment instead of an env-var update. In a staging environment with a different TLD, purges silently no-op against the wrong zone.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract the subdomain base from a new `config('services.cloudflare.base_domain')` key, with a fallback to `parse_url(config('app.url'), PHP_URL_HOST)`.
        - Replace the three hardcoded `https://{$h}.partna.au/...` strings with `"https://{$h}.{$baseDomain}/..."`.
        - Add `CLOUDFLARE_BASE_DOMAIN=partna.au` to `.env.example`.
    - **Technical:** The same `purgeHandle()` method reads `config('app.url')` on line 98 to construct the API subrequest URL — so the config-driven pattern is already present for one of four URL forms. Lines 90–96 construct the page URL, bare URL, and SWR shadow URL with a literal `partna.au`, splitting the method into half-config, half-hardcoded. On any non-prod environment where the subdomain zone differs, those three purge targets miss the correct zone.
    - **Plain English:** The cache purge tool knows the right address for one thing (reads it from a settings file), but for three other things it has `partna.au` typed directly into the code. If the domain ever changes or this code runs in a test environment with a different address, three out of four purge calls silently hit the wrong place — like a postal worker who reads the label for the return address but writes "Main Street" on the destination for three of the four packages.
    - **Evidence:**
        ```php
        $urls = [
            "https://{$h}.partna.au/",
            "https://{$h}.partna.au",
            "https://{$h}.partna.au/_swr-shadow/",
        ];

        $apiBase = rtrim((string) config('app.url', ''), '/');
        if ($apiBase !== '') {
            $urls[] = "{$apiBase}/api/public/profiles/{$h}";
        }
        ```

- [ ] **#TEST-2** · P2 — Platform controllers enforce ownership via query scoping instead of invoking `IntegrationConnectionPolicy` through `authorizeForUser`
    - **Where:** app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php:37-44 (`connectionFor`), :47-65 (`writeConnection`), :73-76 (`forgetConnection`); `AppleController` private `read()/put()/forgetOne()` helpers bypass the trait entirely
    - **Affects:** All 8 platform controllers — `denyIfPendingDeletion()` in the policy never runs, so a professional whose account is pending deletion can still write to their platform connections. The `denyAsNotFound()` contract (404 not 403) is also never exercised on the write path.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - `IntegrationConnectionPolicy` is already registered at `AppServiceProvider:135` — no change needed there.
        - In `ManagesIntegrationConnection`, after `connectionFor()` resolves a non-null connection, add `$this->authorizeForUser($user, 'view', $connection)` before returning it.
        - Before `writeConnection()` calls `updateOrCreate`, add `$this->authorizeForUser($user, 'update', $connection)` when updating an existing row (pass the found connection), or `$this->authorizeForUser($user, 'create', $skeleton)` for new rows.
        - In `forgetConnection()`, add `$this->authorizeForUser($user, 'delete', $connection)` before calling `->delete()`.
        - Migrate `AppleController`'s private `read()/put()/forgetOne()` helpers to use the trait so they share the same authorization path.
    - **Technical:** Partna's authorization doctrine forbids inline authorization in controllers — the query-scoping approach (`$user->integrationConnections()->where(...)`) achieves the same ownership check as inline `abort_unless`, just via a different mechanism. The policy is registered and wired to the gate, but the gate is never knocked: `authorizeForUser` is called zero times across all 8 platform controllers (confirmed by grep). The concrete consequence is `denyIfPendingDeletion()` never runs — a pending-deletion actor can freely write platform connections. Secondary consequence: `AppleController`'s private helpers are a completely separate code path that duplicates ownership logic with no shared test surface.
    - **Plain English:** The app has a formal set of rules about who can read and edit platform connections (a policy document). Those rules include "if the account is being deleted, reject writes with a 'locked' error." But none of the platform controllers ever check those formal rules — they have their own informal check ("does this connection belong to you?") baked into the database query. The informal check works for preventing cross-user access, but it skips the "is this account being deleted?" rule entirely. The fix is routing all reads and writes through the formal rules document so the same rule covers everything.
    - **Evidence:**
        ```php
        // ManagesIntegrationConnection::connectionFor — ownership enforced via scope only:
        protected function connectionFor(User $user, ?string $resourceId = null): ?IntegrationConnection
        {
            return $user->integrationConnections()
                ->where('platform', $this->platform())
                ->where('resource_id', $resourceId ?? $this->defaultResourceId())
                ->first();
            // No $this->authorizeForUser($user, 'view', $connection) follows.
        }
        ```

---

## P3 — Nice to have

- [ ] **#TEST-6** · P3 — `EventbriteController::filterPastEvents` never tested with null `endDate` and null `startDate`
    - **Where:** app/Http/Controllers/Api/Platforms/EventbriteController.php:83-88 (`filterPastEvents` null-coalesce chain)
    - **Affects:** Edge case — an Eventbrite event with no dates at all is kept in the selection (correct behavior), but this branch is not explicitly asserted.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('keeps an event with no dates at all in the upcoming list')` to `tests/Feature/Platforms/PlatformFixesTest.php`.
        - Seed a payload with `['name' => 'No dates', 'startDate' => null, 'endDate' => null]` alongside a past event; assert the dateless event survives while the past event is dropped.
    - **Technical:** The filter evaluates `$end = $e['endDate'] ?? $e['startDate'] ?? null; return $end === null || $end >= $now;`. When both are null, `$end === null` short-circuits to `true` and the event is kept. The current tests cover the past-event drop and the in-progress-event keep paths, but the null-both-dates path is implicit. A future refactor that swaps the null check order (e.g., to default missing dates to the epoch) would silently drop dateless events without any test failing.
    - **Plain English:** Events without any date are shown in the upcoming list — that's the right call (better to show an undated event than hide it). But no test confirms this choice. A future developer editing the filter logic wouldn't know this is intentional and could accidentally flip it.
    - **Evidence:**
        ```php
        function (array $e) use ($now) {
            $end = $e['endDate'] ?? $e['startDate'] ?? null;
            return $end === null || $end >= $now;
        },
        ```

- [ ] **#CFG-3** · P3 — `FreshaController` hardcodes a GraphQL persisted-query hash and client version that rotate independently of application code
    - **Where:** app/Http/Controllers/Api/Platforms/FreshaController.php:32-33
    - **Affects:** Per-employee service menus — when Fresha redeploys their frontend, these values go stale and the feature silently degrades to the whole-location menu fallback until a developer re-captures and deploys.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move `BOOKING_INIT_HASH` and `FRESHA_CLIENT_VERSION` into `config/services.php` under a `fresha` key.
        - Add `FRESHA_BOOKING_INIT_HASH` and `FRESHA_CLIENT_VERSION` to `.env.example` with a comment explaining they must be re-captured from Fresha's frontend when it redeploys, and that a null value triggers the documented full-location fallback.
    - **Technical:** The comment on lines 28–30 explicitly acknowledges that these values "rotate when they redeploy" and that the fallback is intentional. Moving them to config means ops can update the values via env vars and restart without a pipeline deploy. Null/empty values in config already trigger the fallback (`fetchEmployeeServices` returns null), so the missing-key case is safely handled.
    - **Plain English:** Fresha's website uses a "shortcut key" to load per-employee service menus. That key changes whenever Fresha updates their website, which happens independently of Partna. Right now the key is written directly into the code, so when Fresha changes it, a developer has to find the new key, edit the code, and ship a deployment. Moving it to a settings file means swapping the key is a configuration change instead of a code change — like updating a bookmark instead of rewriting the browser.
    - **Evidence:**
        ```php
        // Persisted-query hash + client version are pinned to a Fresha frontend
        // build and rotate when they redeploy. When they do, fetchEmployeeServices
        // returns null and callers fall back to the whole-location menu until these
        // are re-captured. (Test-mode tradeoff; the real version uses Fresha's
        // partner API.)
        private const BOOKING_INIT_HASH = '4ea9d1b31075d62f789fcec884c45d76aaeb42e56ffb1b78cc1b7f7c557ad7cb';
        private const FRESHA_CLIENT_VERSION = 'd135e4b3a3be51f9dd24f5cc2af6dd6a647f85dd';
        ```

- [ ] **#API-3** · P3 — `PublicConfigController` bypasses `ApiController::success()` envelope, creating a response shape inconsistency for frontend consumers
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicConfigController.php:44-49, 63-68
    - **Affects:** Frontend clients consuming `GET /api/public/config/social-platforms` and `GET /api/public/config/integrations` — these two endpoints return a raw JSON object while every other API endpoint returns the standard `success()` envelope.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - If `ApiController::success()` can accept optional extra response headers, add a `$headers = []` parameter and pass `['Cache-Control' => 'public, max-age=3600']` from these controllers.
        - Otherwise, add a `successCached(array $data, int $maxAge = 3600): JsonResponse` helper on `ApiController` that wraps `success()` and appends the cache header.
        - Replace the two `response()->json([...])->header(...)` calls with the new helper.
    - **Technical:** `ApiController::success()` provides a consistent envelope, status code, and JSON encoding contract across all endpoints. `PublicConfigController::socialPlatforms()` and `integrations()` bypass it with raw `response()->json()` to append `Cache-Control` headers, but this could be solved with a thin helper on the base controller. The frontend must special-case these two endpoints; any future envelope change to `success()` would need to be manually applied here as well.
    - **Plain English:** Every response from this API comes in a standard wrapper, like every item from a restaurant coming on a tray with a receipt. These two config endpoints hand items to customers directly, without a tray. The kitchen (frontend) has to remember these two items are different. Wrapping them in the same tray — even a small one — means one fewer exception rule to maintain.
    - **Evidence:**
        ```php
        public function socialPlatforms(): JsonResponse
        {
            return response()
                ->json([
                    'platforms' => $this->normalizer->getPublicRegistry(),
                    'categories' => config('partna.link_categories', []),
                ])
                ->header('Cache-Control', 'public, max-age=3600');
        }
        ```

- [ ] **#API-4** · P3 — All 8 platform controllers return hand-assembled arrays without Resource classes; API contract is defined implicitly across every controller
    - **Where:** app/Http/Controllers/Api/Platforms/{AppleController,EventbriteController,FacebookController,FreshaController,InstagramController,ShopifyController,TiktokController,YoutubeController}.php
    - **Affects:** The professional dashboard frontend — eight controllers each define their own response shape inline, with no single authoritative place that describes what each platform's selection payload looks like.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Create per-platform Resource classes under `app/Http/Resources/Platforms/` (e.g., `YoutubeSelectionResource`, `ShopifyBrandResource`, `AppleMusicSelectionResource`).
        - Each Resource's `toArray()` becomes the canonical field allowlist for that platform.
        - Wire them into each controller's return: `return $this->success(new YoutubeSelectionResource($selection))`.
        - The same Resource can serve API-2's public endpoint concern via `$this->when(...)` guards for fields that should only appear on the authenticated dashboard path.
    - **Technical:** Partna architecture mandates Resource classes for all API responses. The platform controllers predate this standard and have not been retrofitted. A `YoutubeSelectionResource` would also directly address API-2 — the public endpoint at `PublicIntegrationController` could reuse or extend the same Resource class, eliminating the raw-payload pass-through in a single change rather than as two separate tasks.
    - **Plain English:** Eight different sets of hands are each assembling their own plate presentation, each slightly differently. There's no shared recipe card that says "here are exactly the fields that go on a YouTube plate." When a new developer joins and adds something to a platform's data, they copy whatever was there before and hope it matches what the frontend expects. A recipe card per platform means one place to look, one place to change, and a mechanical guarantee that the public window never shows something that wasn't explicitly put there.
    - **Evidence:**
        ```php
        // YoutubeController::connect() — hand-rolled shape:
        $selection = [
            'handle' => $handle,
            'name' => $latest['name'],
            'description' => $latest['description'],
            'link' => $latest['link'],
            'thumbnail' => $latest['thumbnail'],
            'latest' => $latest,
            'highlights' => $highlights,
        ];
        return $this->success($selection);

        // ShopifyController::addBrand() — different hand-rolled shape:
        $map[$id] = [
            'id' => $id,
            'url' => $origin,
            'name' => $brand['name'],
            'currency' => $brand['currency'] ?? null,
            'favicon' => $brand['favicon'],
            'logo' => $brand['logo'],
            'discountCode' => $discount,
            'products' => $map[$id]['products'] ?? [],
        ];
        return $this->success($map[$id]);
        ```

`★ Insight ─────────────────────────────────────`
The policy-registration step in TEST-2's original fix was already done — `AppServiceProvider:135` proves it. The remaining gap is purely invocation: the gate is wired, but the controllers never knock on it. This is a common pattern when policies are added after controllers: the registration is done correctly, but the call sites require a second pass. API-1 is asymmetric specifically because commit #191 updated `connect()` and `highlights()` but `PlatformRefresher` is in a different file and wasn't caught by the PR review — a strong argument for the TEST-5 failure-path test that would have caught the `latest` key being absent from the refreshed payload.
`─────────────────────────────────────────────────`
