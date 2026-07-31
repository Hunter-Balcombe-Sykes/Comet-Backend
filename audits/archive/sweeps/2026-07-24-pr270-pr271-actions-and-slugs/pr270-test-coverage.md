# Test Coverage Audit — 2026-07-24

**Branch:** HEAD
**Lens:** Test coverage: critical paths, idempotency, race-safety, policy abilities, mock-vs-integration discipline
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- tests/Pest.php
- app/Http/Controllers/Api/PublicSite/AnalyticsController.php
- app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php
- app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php
- app/Http/Requests/Api/PublicSite/Analytics/ActionSeenRequest.php, ActionTapRequest.php
- app/Http/Requests/Concerns/SiteOrderingValidationRules.php
- app/Jobs/Platforms/GoogleBusinessEnrichJob.php
- app/Console/Commands/PurgeRawAnalyticsEvents.php
- app/Providers/PlatformRegistryServiceProvider.php
- supabase/migrations/20260723090000_create_action_events.sql
- .github/workflows/ci.yml
- tests/Feature/Analytics/* (ActionBeaconsTest, ClickDedupTest, ClickBotFilterTest, PublicIngestHardeningTest, ItemSeenIngestTest, SectionSeenIngestTest, SectionDwellIngestTest, ClickV2AndSessionPingTest, ComputePopularityScoresTest, RankedActionsComputeTest)
- tests/Feature/PublicSite/* (IndividualProfileControllerTest, PublicSiteControllerShowTest, ActionCatalogTest, ProfileRankedActionsTest, PublicPlatformEndpointTest)
- tests/Feature/Platforms/PublicIntegrationAllowlistTest.php, GoogleBusinessApifyTest.php, GoogleBusinessEnrichConcurrencyTest.php
- tests/Feature/Security/TenantIsolation/PublicAnalyticsIdorTest.php
- tests/Feature/Database/CheckConstraintsTest.php, IndexCoverageTest.php, ConstraintVocabularyLockstepTest.php
- tests/Feature/Console/PurgeRawAnalyticsEventsCommandTest.php
- tests/Feature/Api/User/SiteManagement/UserSiteActionsEndpointTest.php, ActionSettingsValidationTest.php
- tests/Feature/User/DataExport/DataExportTestCase.php
- tests/Feature/Account/AccountDeletionPurgeActionEventsPiiTest.php
- tests/Feature/User/AccountDeletion/*, tests/Feature/Site/HandleReclaimTest.php, HandleAliasLifecycleTest.php
- tests/Feature/Resources/IndividualProfileResourceTest.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 3 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 7 complete

---

## P1 — Fix before pilot launch

- [ ] **#TEST-1** · P1 — Hand-maintained SQLite fixtures for GDPR export/deletion tests can drift from production schema undetected
    - **Where:** tests/Feature/User/DataExport/DataExportTestCase.php:83-100, tests/Feature/User/AccountDeletion/AccountDeletionTestCase.php
    - **Affects:** Every GDPR export / account-deletion feature test. A migration that adds a column referenced by export/purge queries can pass the full suite while the real Postgres query 42703s in production.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Add a schema-parity test that, for each `CREATE TABLE` stub in `DataExportTestCase`/`AccountDeletionTestCase`, diffs its column list against the corresponding table's columns as defined across `supabase/migrations/` (parse or grep the latest `CREATE TABLE`/`ALTER TABLE ... ADD COLUMN` statements per table).
        - Fail the test when a production column referenced by `DataExportPayloadBuilder` or `AccountDeletionService` is absent from the fixture.
        - Note: `tests/Feature/Database/DataExportSchemaParityTest.php` already exists — extend/generalize that mechanism to cover the two TestCase bootstraps rather than building new tooling from scratch.
    - **Technical:** `DataExportTestCase::boot()` hand-writes `CREATE TABLE` DDL that must mirror production. SQLite silently returns an unknown identifier as a string literal on `SELECT` instead of raising an error (Postgres raises 42703), so a fixture missing a column the real export/purge query references passes the test while the real code throws in production. The file's own comment documents this exact trap and references a prior incident where `external_id`/`marketing_opt_in_cached` were missing. This is not hypothetical: project history records the GDPR export streaming query breaking in production for exactly this reason (`streamMedia queried nonexistent cols`, fixed reactively). The root cause — unvalidated hand-duplicated schema — remains for every column added since.
    - **Plain English:** The test suite uses a lightweight practice database that's supposed to be an exact copy of the real one, but a person has to remember to update it by hand every time the real database changes. This has already gone wrong once — a real export broke in production because the practice database was missing a column, and the tests didn't catch it because the practice database quietly treats a missing column as "not found" instead of raising an alarm. Without an automatic check that keeps the two in sync, this exact failure can happen again on any new column.
    - **Evidence:**
        ```php
        // Column list mirrors PRODUCTION (baseline :401-416 + external_id/
        // marketing_opt_in_cached present since baseline, redacted_at added by
        // 20260527160000). external_id/marketing_opt_in_cached were previously
        // missing here — a SELECT naming them would silently return the column
        // NAME as a string literal on SQLite rather than erroring (see file-level
        // trap note in the task prompt), masking a real Postgres 42703.
        $conn->statement('CREATE TABLE IF NOT EXISTS site.customers (
        ```

- [ ] **#TEST-2** · P1 — `CheckConstraintsTest`/`IndexCoverageTest` (20+ constraint/index assertions) never execute in CI — they're inert on every PR
    - **Where:** tests/Feature/Database/CheckConstraintsTest.php (20 tests), tests/Feature/Database/IndexCoverageTest.php, tests/Feature/Database/ArchitectureSystemConstraintsTest.php, tests/Feature/Database/UpdatedAtTriggerCoverageTest.php; .github/workflows/ci.yml
    - **Affects:** Every CHECK constraint, FK-cascade, index, and trigger these files assert exist — including the newly added `action_events_event_check`/`action_events_site_fk`. A migration that drops or weakens any of these constraints passes CI green.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Extend the existing `ConstraintVocabularyLockstepTest.php` grep pattern (already proven, runs for real on SQLite) to cover the remaining CHECK constraints, FK cascades, and purge indexes currently only checked by the Postgres-only files.
        - Alternatively/additionally, add a `pgsql`-backed service container + `pdo_pgsql` extension to a dedicated CI job so these files actually execute somewhere in the pipeline (the current `ci.yml` installs only `pdo_sqlite`).
        - Prioritize the recently-added `action_events` constraints and any constraint tied to a schema change in the last 30 days.
    - **Technical:** `checkConstraintsSuiteIsPostgres()`/`indexCoverageSuiteIsPostgres()` gate every assertion in these files behind `DB::connection()->getDriverName() === 'pgsql'`, calling `markTestSkipped()` otherwise. `.github/workflows/ci.yml`'s `test` job installs only `pdo, pdo_sqlite, sqlite3, redis` — no `pdo_pgsql`, no Postgres service container — so every one of these ~30+ assertions across 4 files silently skips on every PR and push. This is not a theoretical risk: the project's own `ConstraintVocabularyLockstepTest.php` was built specifically because a prior CHECK-constraint vocabulary drift (stale item/content-type lists) would have failed `VALIDATE CONSTRAINT` against real data and broken the nightly `analytics:compute-popularity` job — that gap was only caught by manual review, not CI, precisely because `CheckConstraintsTest` provided no real coverage. The same exposure exists today for every constraint not yet given a lockstep companion, including the two constraints just added for `analytics.action_events`.
    - **Plain English:** There's a large stack of automated checks meant to make sure the database's safety rules (things like "an event must be either 'seen' or 'tap', nothing else") are still in place after every code change. The checks are written, they look thorough — but they're built to run against the "real" type of database, and the automated testing robot only ever uses a lightweight stand-in database that can't run them. So every single one of these checks quietly says "skipped" instead of "passed" or "failed," which looks the same as passing at a glance. This already almost caused a real bug to ship once; without a fix, it can happen again with no one noticing until something breaks in production.
    - **Evidence:**
        ```php
        // Non-vacuous companion to CheckConstraintsTest's Postgres-only introspection
        // (that file's assertions all markTestSkipped on the default SQLite CI
        // driver, so on their own they prove nothing about what CI actually
        // verifies). This file has NO Postgres guard — it runs for real on SQLite in
        // every CI run.
        ```
        ```yaml
        - name: Setup PHP
          uses: shivammathur/setup-php@v2
          with:
            php-version: '8.4'
            extensions: pdo, pdo_sqlite, sqlite3, redis
            coverage: none
        ```

- [ ] **#TEST-3** · P1 — Snapchat/Discord/Telegram/Kick/Medium (commit e1879529) have no `ALLOWLIST` entry — they render empty on every public sitepage today, and no test catches it
    - **Where:** app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php:78-147 (`ALLOWLIST` const), app/Providers/PlatformRegistryServiceProvider.php:124-131; tests/Feature/Platforms/PublicIntegrationAllowlistTest.php
    - **Affects:** Every professional who connects one of these 5 platforms (shipped in commit `e1879529 feat: add Snapchat, Discord, Telegram, Kick, Medium as link-only integrations`). The link they connect never appears on their public sitepage — a live, currently-shipping functional regression, not just a coverage gap.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `'snapchat' => ['username', 'url']`, `'discord' => [...]`, `'telegram' => [...]`, `'kick' => [...]`, `'medium' => [...]` entries to `PublicIntegrationConnectionResource::ALLOWLIST`, mirroring the existing `facebook`/`tiktok`/`x` shape (all 6 older link-only socials already have `['username', 'url']` entries).
        - Add one positive test per platform to `PublicIntegrationAllowlistTest.php`, mirroring the existing `it('allowlists the new v2 platforms on the public endpoint', ...)` pattern — seed a connection, assert the public payload contains the expected keys (not `[]`).
    - **Technical:** `PlatformRegistryServiceProvider::boot()` registers `snapchat`/`discord`/`telegram`/`kick`/`medium` as `PD::linkOnly(...)` platforms (routable, connectable, storing `{username, url}` via their normalizers), but `PublicIntegrationController::show()` always serializes every connection through `PublicIntegrationConnectionResource` regardless of which resource class the registry entry names for the dashboard. Since these 5 platform keys have no `ALLOWLIST` entry, `filterPayload()`'s fail-closed branch fires for every one of them: `report(new MissingPublicAllowlistException(...))` + `Log::warning(...)` + `return []`. The connection is stored correctly and shows on the dashboard, but the public sitepage silently renders nothing for it. `PublicIntegrationAllowlistTest.php` has no test seeding any of these 5 platforms, so this is unguarded.
    - **Plain English:** A recent update let professionals connect Snapchat, Discord, Telegram, Kick, and Medium to their profile. But the part of the code that decides what's safe to show on the public page was never updated to include these five — so right now, if someone connects their Snapchat, it saves fine, shows fine in their own dashboard, but it never actually appears on their public page. Nobody would know unless they specifically checked, because the failure is silent (empty, not an error). A quick test for each of these five would have caught this before it shipped.
    - **Evidence:**
        ```php
        // app/Providers/PlatformRegistryServiceProvider.php
        foreach ([
            'tiktok' => 'TikTok', 'facebook' => 'Facebook', 'x' => 'X',
            'linkedin' => 'LinkedIn', 'threads' => 'Threads', 'reddit' => 'Reddit',
            'snapchat' => 'Snapchat', 'discord' => 'Discord', 'telegram' => 'Telegram',
            'kick' => 'Kick', 'medium' => 'Medium',
        ] as $key => $label) {
            $r->register(PD::linkOnly($key, $label, LinkConnectionResource::class));
        }
        ```
        ```php
        // app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php — ALLOWLIST
        // has entries for the OLDER 6 link-only socials, but no snapchat/discord/
        // telegram/kick/medium entry anywhere in the const:
        'facebook' => ['username', 'url'],
        'tiktok' => ['username', 'url'],
        'x' => ['username', 'url'],
        'linkedin' => ['username', 'url'],
        'threads' => ['username', 'url'],
        'reddit' => ['username', 'url'],
        ```

## P2 — Should fix

- [ ] **#TEST-4** · P2 — `UserSiteActionsEndpointTest` only exercises a single-action pool; empty-state and multi-action shapes are untested
    - **Where:** tests/Feature/Api/User/SiteManagement/UserSiteActionsEndpointTest.php:22-62
    - **Affects:** The dashboard action-picker data source (`GET /api/site/actions`) for professionals with zero eligible actions or several — the two most common real shapes.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `it('returns an empty pool when the user has no live actions')`.
        - Add `it('includes multiple actions when multiple blocks exist')`.
        - Add `it('handles a block with no matching popularity score row')` — assert no 500 and a sane `score` value (`null`/omitted).
    - **Technical:** The file's single happy-path test seeds exactly one block and one popularity-score row, then asserts the pool has exactly one entry. Real accounts have zero, one, or many link blocks, and `analytics.content_popularity_scores` won't have a row for every block (scores are computed on a schedule, not synchronously). A refactor that assumes a non-empty pool or an always-present score would ship with this test suite green.
    - **Plain English:** The test only checked this feature with exactly one item in the list. It never checked what happens with zero items or several — the two situations every real user will actually hit. A bug that only shows up when the list is empty or has multiple entries would sail through this test suite untouched.
    - **Evidence:**
        ```php
        $pro = createTenant('actions-endpoint');
        DB::connection('pgsql')->table('site.blocks')->insert([…]); // one block
        DB::connection('pgsql')->table('analytics.content_popularity_scores')->insert([…]); // one score
        $response = actingAsUser($pro)->getJson('/api/site/actions')->assertOk();
        $poolIds = collect($data['pool'])->pluck('id')->all();
        expect($poolIds)->toBe(['instagram'])
        ```

- [ ] **#TEST-5** · P2 — `PublicIntegrationController::show()`'s Instagram-toggle suppression and popularity-rank threading (incl. single-flight cache) are untested
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php:90-133; tests/Feature/Platforms/PublicIntegrationAllowlistTest.php
    - **Affects:** Every Instagram-connected profile that has turned off auto-sync (gallery card must disappear), and every shop-connected profile's product popularity ranking (a 15-minute single-flight cache around a Postgres read).
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a test: `content_instagram_auto_enabled === false` on the site suppresses `display_settings.gallery` for every Instagram connection row; `null` (never set) and `true` leave it untouched.
        - Add a test: shop-product popularity ranks (`ContentPopularityReader::forSite()`) are threaded into `payload.<brandId>.products[].popularityRank` on the public wire.
        - Add a test asserting `ContentPopularityReader::forSite()` is called once (not once per concurrent request) via a mocked reader + `CacheLockService::rememberLocked`, mirroring the single-flight pattern used elsewhere in the suite.
    - **Technical:** `PublicIntegrationAllowlistTest.php` exercises the `ALLOWLIST` filtering extensively but never seeds `site.sites.content_instagram_auto_enabled = false` nor a `shop_product` popularity-scores row, so neither in-memory override in `show()` is exercised end-to-end. The Instagram override condition is specifically `=== false` (not falsy/`!`) — a refactor that loosens this to treat `null` as "off" would silently hide the gallery for every Instagram-connected user who never explicitly toggled the switch, and nothing would fail.
    - **Plain English:** The public profile endpoint has two special behaviors nobody is checking: (1) if someone turns off their Instagram auto-sync, the photo gallery is supposed to disappear from their public page — but if a future code change is a hair too aggressive about who counts as "turned off," it could hide galleries for people who never touched that setting; (2) shop products are supposed to show a "how popular is this" ranking that's calculated once and shared, not recalculated every time someone visits — if that sharing breaks, a popular page could hammer the database with duplicate work.
    - **Evidence:**
        ```php
        if ($site?->content_instagram_auto_enabled === false) {
            foreach ($connections->get('instagram') as $row) {
                $ds = (array) ($row->display_settings ?? []);
                $ds['gallery'] = false;
                $row->display_settings = $ds;
            }
        }
        ```
        ```php
        $ranks = $siteId !== null
            ? $this->cache->rememberLocked(
                CacheKeyGenerator::sitePopularityRanks($siteId),
                self::POPULARITY_CACHE_TTL_SECONDS,
                fn () => $this->popularity->forSite($siteId),
            )
            : [];
        ```

- [ ] **#TEST-6** · P2 — Fail-closed `MissingPublicAllowlistException` path asserts the empty payload but not that `report()`/Nightwatch actually fires
    - **Where:** app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php:225-242; tests/Feature/Platforms/PublicIntegrationAllowlistTest.php:354-382
    - **Affects:** Observability for the exact scenario #TEST-3 above just demonstrated live — a platform shipped without an `ALLOWLIST` entry. Without the `report()` assertion, this failure mode can go unnoticed by Nightwatch even though the code intends to page it.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In the existing `it('returns an empty payload array (fail-closed)...')` test, add `report()`/`Log::warning` assertions (e.g. via `report()`'s exception-handler fake or `Log::spy()`) confirming `MissingPublicAllowlistException` is actually reported, not just that `payload` is `[]`.
    - **Technical:** The existing test proves the fail-closed *data* contract (`payload === []`) but not the fail-closed *observability* contract (`report()` called so Nightwatch pages). A refactor that accidentally drops the `report()` call (leaving only the `Log::warning`, which is invisible to Nightwatch per this repo's alerting model) would leave a missing-allowlist platform — exactly what #TEST-3 shows currently happens for 5 real platforms — silently unpaged indefinitely.
    - **Plain English:** When a new platform is added to the system but someone forgets to say "this is safe to show publicly," the code correctly hides it rather than risk leaking private data — that part is tested. But the code is also supposed to sound an alarm so an engineer notices and fixes it. Nobody is checking that the alarm actually goes off. This is exactly the situation happening right now with five real platforms (see the finding above) — an assertion here would have caught it immediately instead of it shipping silently.
    - **Evidence:**
        ```php
        // OBS-1: report() so Nightwatch pages — Log::warning alone is invisible to it.
        report(new MissingPublicAllowlistException($platform));
        Log::warning('PublicIntegrationConnectionResource: no allowlist for platform', [
            'platform' => $platform,
        ]);
        ```

## P3 — Nice to have

- [ ] **#TEST-7** · P3 — `smart_actions`/`smart_page_order` boolean settings never tested with a non-boolean value
    - **Where:** tests/Feature/Api/User/SiteManagement/ActionSettingsValidationTest.php
    - **Affects:** `PATCH /api/site` when a client sends `"true"`/`1` instead of a real boolean for `smart_actions`/`smart_page_order`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('rejects a non-boolean smart_actions value')` / equivalent for `smart_page_order`, sending `'smart_actions' => 'maybe'`.
    - **Technical:** The 15-test file thoroughly covers `manual_actions`/`manual_page_order` shape validation but never sends an invalid value for the two boolean toggles — only ever `false` in the happy path. Laravel's `boolean` rule accepts a range of truthy/falsy representations; no test pins down the actual accept/reject boundary.
    - **Plain English:** The two on/off switches in site settings are never tested with a genuinely invalid value like the word "maybe." It's a small gap, but it means nobody would notice if validation here quietly got looser or stricter.
    - **Evidence:**
        ```php
        actingAsUser($pro)
            ->patchJson('/api/site', ['settings' => [
                'smart_page_order' => false,
                'smart_actions' => false,
                …
            ]])
            ->assertOk();
        ```

- [ ] **#TEST-8** · P3 — `GoogleBusinessEnrichJob::failed()`'s straightforward (non-lock-contended) happy path isn't directly asserted
    - **Where:** app/Jobs/Platforms/GoogleBusinessEnrichJob.php:283-308; tests/Feature/Platforms/GoogleBusinessEnrichConcurrencyTest.php
    - **Affects:** Recovery after a genuine job failure when the connection lock is NOT contended (the common case).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a test that calls `failed(new RuntimeException(...))` with no competing lock held, and asserts both `Cache::forget()` calls happened (inflight + result keys cleared) and the connection's `apify_status` became `'unavailable'`.
    - **Technical:** `GoogleBusinessEnrichConcurrencyTest.php` is otherwise exceptionally thorough (real wall-clock lock-contention tests, JOB-2 idempotency, LIFE-10 CAS), but its one `->failed(...)` test is specifically the lock-timeout regression case, which never reaches the `mark()` write. The plain-path cache-clear + status-write behavior of `failed()` has no direct assertion.
    - **Plain English:** When this background job fails outright, it's supposed to clean up its markers and flag the connection as unavailable so a user can retry. That specific, common "it just failed, no lock fight involved" path isn't directly checked — only the rarer case where two processes collide is.
    - **Evidence:**
        ```php
        public function failed(Throwable $e): void
        {
            report($e);
            Log::error('google_business.enrich_job.failed', [...]);
            Cache::forget(CacheKeyGenerator::googleBusinessApifyInflight($this->userId, $this->placeId));
            Cache::forget(CacheKeyGenerator::googleBusinessApifyResult($this->userId, $this->placeId));
            $connection = $this->connection();
            if ($connection) {
                $this->mark($connection, 'unavailable', terminal: true);
            }
        }
        ```

- [ ] **#TEST-9** · P3 — No invariant test guarding against `site.themes` reappearing in a future migration
    - **Where:** supabase/migrations/ (sweep target); tests/Feature/Database/ (missing test)
    - **Affects:** Regression prevention for the architecture-system cleanup — `site.themes` and its trigger were deliberately dropped and must never be reintroduced.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a Pest test alongside the existing `tests/Feature/Database/` invariant tests that greps every file under `supabase/migrations/` for `CREATE TABLE site.themes` (or `site\.themes`) and asserts zero matches.
    - **Technical:** Per this repo's architecture-system doctrine, `site.themes` is permanently dropped and "theme" now only means `theme_mode`. No test currently enforces this at the migration-file level, so nothing stops a future migration from accidentally recreating it (e.g. a well-meaning revert or a copy-pasted CREATE TABLE from an old branch).
    - **Plain English:** An old database table was intentionally deleted and must stay deleted. Right now nothing automatically checks for that — a future change could accidentally bring it back and nobody would notice until it caused confusion.
    - **Evidence:**
        ```
        No test file under tests/Feature/Database/ greps supabase/migrations/
        for `CREATE TABLE site.themes` — confirmed via search across tests/.
        ```

- [ ] **#TEST-10** · P3 — `PurgeRawAnalyticsEventsCommandTest.php` has several small, real coverage gaps
    - **Where:** tests/Feature/Console/PurgeRawAnalyticsEventsCommandTest.php; app/Console/Commands/PurgeRawAnalyticsEvents.php
    - **Affects:** Operators tuning retention via `--days`, and CI's ability to catch a table silently dropped from the purge sweep.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `it('respects a valid --days=60 to shorten the retention cutoff')` — no existing test passes a `--days` value ≥30 and proves the cutoff actually moves.
        - Add `it('succeeds with exit code 0 when no raw events exist')` — every current test seeds at least one row first.
        - Add `it('declares exactly the expected raw analytics tables')` asserting `PurgeRawAnalyticsEvents::TABLES` (reflection or a public accessor) equals the known 7-table list, so a table silently dropped from the sweep fails CI instead of accumulating stale data forever.
        - Register `analytics.lead_submissions` in a shared `setupLeadSubmissionsTable()` Pest helper instead of the inline `CREATE TABLE` in this file's `beforeEach`, matching the `setup*Table()` convention used everywhere else.
    - **Technical:** The command's `retentionDays()` and `TABLES` constant are both meaningfully testable but under-covered: no test exercises a valid `--days` override (only the below-floor rejection path), no test proves the command tolerates an empty dataset, and nothing pins the `TABLES` array's contents so a future refactor that forgets to add a new raw-analytics table doesn't fail CI.
    - **Plain English:** The command that periodically deletes old analytics rows is mostly well tested, but a few realistic scenarios are missing: changing how far back it keeps data, running it when there's nothing to delete, and — most importantly — making sure that if someone adds a new type of analytics event later, they don't forget to add it to the cleanup list. Forgetting that last one means old data would just pile up forever with no warning.
    - **Evidence:**
        ```php
        it('rejects a --days value below the 30-day retention floor and exits with a failure code', function () {
            $this->artisan('partna:analytics:purge-raw-events', ['--days' => 29])
                ->expectsOutputToContain('Retention window must be at least 30 days (got 29)')
                ->assertExitCode(1);
        });
        // no test with --days >= 30 proving the cutoff moves
        ```
        ```php
        // No shared Pest.php helper for this table — mirrors the minimal DDL used by
        // tests/Feature/Middleware/LogLeadRateLimitsTest.php.
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS analytics.lead_submissions');
        ```

- [ ] **#TEST-11** · P3 — No test covers a platform connection with a `null`/`[]` payload (e.g. a scraper that errored before first sync)
    - **Where:** app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php:220-223; tests/Feature/Platforms/PublicIntegrationAllowlistTest.php
    - **Affects:** Public sitepage rendering for a connection stuck mid-scrape or in an error state before its first successful payload.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a test seeding an `IntegrationConnection` with `payload = null`, hit the public endpoint, and assert a graceful response (no 500) with `payload: null` or an equivalent safe shape.
    - **Technical:** `filterPayload()` explicitly guards `if (! is_array($payload)) { return $payload; }`, so the code already defends against this — but nothing proves it end-to-end through the HTTP layer, and a future refactor of that guard (e.g. someone "simplifying" it to always call `array_intersect_key`) would fatal on a null payload with no test catching the regression.
    - **Plain English:** Sometimes a connection to a platform is only half set up — data hasn't arrived yet. The code has a safety check for this that looks correct, but nothing actually proves visiting such a profile page doesn't crash.
    - **Evidence:**
        ```php
        // Null / non-array payloads (e.g. a pending connection) pass through.
        if (! is_array($payload)) {
            return $payload;
        }
        ```

- [ ] **#TEST-12** · P3 — `rum()` beacon's bot-rejection, missing/invalid-handle, and log-failure-swallow paths aren't tested
    - **Where:** app/Http/Controllers/Api/PublicSite/AnalyticsController.php:600-635; tests/Feature/Analytics/PublicIngestHardeningTest.php
    - **Affects:** Real-user-monitoring data quality — malformed or bot RUM traffic and a failing log call are all silently absorbed into a uniform `200 {'message':'ok'}`, and nothing currently proves each path behaves as intended.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add tests: bot UA → 200, no `Log::info` call; missing `handle` → 200, no log; invalid `handle` (regex reject) → 200, no log; `Log::info` throwing → `Log::warning('analytics.rum_logging_failed', ...)` fires and the response is still 200.
    - **Technical:** `PublicIngestHardeningTest.php` covers the RUM beacon's UA-truncation and handle-hashing behavior but not the three early-return/failure branches. Because every outcome returns an identical `200 {'message':'ok'}` by design (to avoid leaking filter state to the caller), these branches are only observable via their log side-effects — exactly the kind of gap that's invisible without a log-spy test.
    - **Plain English:** This beacon quietly drops bad or bot data and always says "ok" no matter what happened, by design — so the only way to know it's working correctly is to check what it logs internally. Right now, several of its "silently do nothing" paths (bad handle, no handle, bot traffic, logging itself failing) aren't checked at all.
    - **Evidence:**
        ```php
        public function rum(Request $request): JsonResponse
        {
            if ($this->isBotUserAgent($request->userAgent())) {
                return $this->success(['message' => 'ok'], 200);
            }
            $payload = $request->json()->all();
            $handle = isset($payload['handle']) ? (string) $payload['handle'] : null;
            if (! $handle || ! preg_match('/^[a-z0-9-]{1,63}$/i', $handle)) {
                return $this->success(['message' => 'ok'], 200);
            }
            try {
                Log::info('rum', [...]);
            } catch (\Throwable $e) {
                Log::warning('analytics.rum_logging_failed', ['error' => $e->getMessage()]);
            }
            return $this->success(['message' => 'ok'], 200);
        }
        ```

- [ ] **#TEST-13** · P3 — `ComputePopularityScoresTest`'s only end-to-end command test mocks away `RankedActionsComputer`, so the real command→computer wiring is never proven
    - **Where:** tests/Feature/Analytics/ComputePopularityScoresTest.php; tests/Feature/Analytics/RankedActionsComputeTest.php
    - **Affects:** The `analytics:compute-popularity` artisan command's action-score output — confidence that the command actually invokes the real computer correctly, not just that its failure path is caught.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add an integration test that runs `analytics:compute-popularity --site=<id>` with the REAL `RankedActionsComputer` (no mock), seeds real `analytics.action_events` rows, and asserts action scores land in `analytics.content_popularity_scores`.
    - **Technical:** The existing OBS-3 test intentionally mocks `RankedActionsComputer` to prove the command's catch-block fails open — a legitimate and necessary test of that specific behavior. `RankedActionsComputeTest.php` separately tests `computeForSite()` in isolation, never through the artisan command. No test currently proves the command correctly wires a REAL computer end-to-end; a DI or method-signature regression between the two would not be caught by either existing file.
    - **Plain English:** One test proves the popularity-scoring command doesn't crash when its action-scoring component breaks — good, that's important. But no test proves that when everything is working normally, the command and the action-scoring component are actually wired together correctly. It's like proving the seatbelt works in a crash, without ever proving the car drives.
    - **Evidence:**
        ```php
        $brokenRankedActions = Mockery::mock(RankedActionsComputer::class);
        $brokenRankedActions->shouldReceive('computeForSite')
            ->andThrow(new RuntimeException('ranked actions exploded'));
        app()->instance(RankedActionsComputer::class, $brokenRankedActions);
        $this->artisan('analytics:compute-popularity', ['--site' => $tenant->site->id])
            ->assertExitCode(0);
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Public integration allowlist coverage:** #TEST-3, #TEST-5, #TEST-6, #TEST-11
    - **Why grouped:** All four touch the same two files (`PublicIntegrationConnectionResource.php` / `PublicIntegrationController.php`) and the same test file (`PublicIntegrationAllowlistTest.php`) — fixing them together avoids repeated context-loading of the ALLOWLIST/filterPayload mechanics.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Site-actions dashboard validation coverage:** #TEST-4, #TEST-7
    - **Why grouped:** Both add test cases to the same dashboard site-actions/settings validation surface (`tests/Feature/Api/User/SiteManagement/`).
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 3 — Schema/command invariant coverage:** #TEST-9, #TEST-10
    - **Why grouped:** Same pattern (CI-safe grep/reflection invariant tests) applied to two different subsystems (migrations, purge command) — same implementer skill set.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 4 — Job/command coverage polish:** #TEST-8, #TEST-13
    - **Why grouped:** Both add a missing "straightforward path" test to an otherwise well-covered job/command, same low-risk shape.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 5 — RUM beacon edge cases:** #TEST-12
    - **Why grouped:** Single-item bundle — narrow, self-contained addition to `PublicIngestHardeningTest.php`.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#TEST-1 — GDPR export/deletion fixture drift guard** · L effort; touches DB-layer test infrastructure shared by every export/deletion test and has a documented prior production incident — needs its own plan + sign-off before touching two large shared TestCase bootstraps.
- **#TEST-2 — CheckConstraintsTest/IndexCoverageTest CI-inert gap** · L effort; the fix path (CI topology change adding a Postgres service, or a broad grep-based rewrite of 20+ constraint assertions) is a foundational CI/testing-infrastructure decision that needs its own plan + sign-off.
