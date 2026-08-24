# Test Coverage Audit — 2026-08-24

**Branch:** development
**Lens:** Test coverage: critical paths, idempotency, race-safety, policy abilities, mock-vs-integration discipline
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- tests/Pest.php
- app/Http/Controllers/Api/PublicSite/AnalyticsController.php
- app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php
- app/Http/Requests/Api/PublicSite/Analytics/{ActionSeenRequest,ActionTapRequest,ItemSeenRequest}.php
- app/Http/Requests/Api/User/Site/UpdateSiteRequest.php
- app/Http/Requests/Concerns/SiteOrderingValidationRules.php
- app/Http/Controllers/Api/Platforms/ShopController.php
- app/Services/Platforms/{AppleSearch,GoogleBusinessAutoSync,LinkRouter}.php
- app/Http/Controllers/Api/Content/PoolController.php
- app/Http/Controllers/Api/Routing/{RoutingController,SuggestionsController}.php
- app/Routing/{ConnectionIdentity,IriCanonicalizer,Importers/LinkInBioImporter}.php
- app/Ingest/Connectors/FreshaConnector.php · app/Ingest/Projection/FreshaServiceProjector.php
- app/Site/Actions/ActionCandidates.php · app/Site/Pools/{PoolOrdering,PoolResolver,PoolSectionProvisioner}.php
- app/Jobs/Platforms/{CommerceProbeJob,ShopBrandConnectJob,ShopInitialFillJob}.php
- supabase/migrations/{20260820110000_single_account_social_convergence,20260823100000_unified_actions,20260823120000_item_scores_keyed_by_id}.sql
- tests/Feature/Api/PublicSite/{IndividualProfileControllerTest,PoolDegradedBuildTest}.php
- tests/Feature/PublicSite/DegradedPayloadTtlTest.php
- tests/Feature/Site/PoolOrderModesTest.php · tests/Feature/Cache/DesignKitCacheInvalidationTest.php
- tests/Feature/Content/{PoolLaneTest,PoolCacheLanesTest}.php · tests/Schema/ContentStorefrontsConstraintsTest.php
- tests/Feature/Architecture/{CollectionWriteInvalidationGuardTest,OutboundHttpGuardTest}.php
- tests/Feature/Routing/{SuggestionsInboxTest,SuggestionsInboxFoldTest,ShopPlaceDelegationTest,ConnectionIdentityAliasTest,BookingXorConnectRaceTest}.php
- tests/Feature/Platforms/{AutoSyncSeederLockTest,PublicIntegrationAllowlistTest,ShopSelectionLockTest,ShopAsyncConnectTest,ShopRelationalStorageTest}.php
- tests/Feature/Ingest/{FreshaConnectorTest,FreshaServiceProjectorTest}.php
- tests/Unit/Jobs/ShopBrandConnectJobTest.php · tests/Feature/Database/ConstraintVocabularyLockstepTest.php

## Progress

- P1 High: 0 of 1 complete
- P2 Medium: 0 of 19 complete
- P3 Low: 0 of 5 complete

---

## P1 — Fix before pilot launch

- [ ] **#TEST-1** · P1 — Design-kit cache-rotation test claims it exercises the HTTP controller path but replays the controller's logic by hand instead
    - **Where:** tests/Feature/Cache/DesignKitCacheInvalidationTest.php:26-101
    - **Affects:** Confidence that a design-kit-only save rotates the public sitepage cache key. A regression in `UserSiteController::update()`'s touch block would go undetected by CI.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace the direct `UpdateSiteAction::execute()` call + manual `if (! $returnedSite->wasChanged()) { $returnedSite->touch(); }` replication with a real `patchJson('/api/site', [...design-kit-only payload...])` request.
        - Assert `site.sites.updated_at` advances as a result of the HTTP call, not a line written by the test itself.
        - Rename or remove the header comment claiming HTTP-path coverage until the body actually does that.
    - **Technical:** The test's own header comment says "This test goes through the full HTTP controller path (PATCH /api/site) so that deleting the touch() block in UserSiteController::update() would cause it to fail — unlike a direct $site->touch() call which always passes." The body then calls `UpdateSiteAction::execute($pro, [])` directly and, when `wasChanged()` is false, calls `$returnedSite->touch()` itself — replicating the exact controller logic it claims to be guarding. If the controller's touch block were deleted today, this test would stay green because the touch happens in the test, not in the code under test. The public-profile cache key is derived from `site.sites.updated_at` (CCH-4), so this is the one test standing between a silent regression and a professional's page serving a stale design kit for up to 60 seconds.
    - **Plain English:** This is like a fire-drill report that says "the fire alarm rang and evacuated the building" but was actually written by someone who rang a hand bell themselves and never touched the real alarm. If the real alarm system breaks, the report still reads "all clear" every time. The test needs to actually trigger the real save, not narrate what the real save is supposed to do.
    - **Evidence:**
        ```php
        // This test goes through the full HTTP controller path (PATCH /api/site) so
        // that deleting the touch() block in UserSiteController::update() would cause
        // it to fail — unlike a direct $site->touch() call which always passes.
        ...
        $action = app(UpdateSiteAction::class);
        $returnedSite = $action->execute($pro, []);
        ...
        // Replicate the controller's touch logic exactly. If the controller's
        // touch() block were deleted, this assertion would remain but the
        // HTTP-layer test (which exercises the full controller path) would fail.
        if (! $returnedSite->wasChanged()) {
            $returnedSite->touch();
        }
        ```

## P2 — Should fix

- [ ] **#TEST-2** · P2 — `content.storefronts` FK-cascade schema invariant reads an unscoped, unordered `$fk[0]` instead of the specific constraint it names
    - **Where:** tests/Schema/ContentStorefrontsConstraintsTest.php:19-27
    - **Affects:** Reliability of the schema-invariant lane's guard against an orphaned `content.storefronts` row.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `AND confrelid = 'content.collections'::regclass` to the query so it targets the `collection_id` FK specifically.
        - Assert `count($fk) === 1` before indexing, or otherwise make the row identity explicit.
    - **Technical:** The table carries two `ON DELETE CASCADE` foreign keys (`collection_id → content.collections`, added inline in `20260813100000_create_content_storefronts.sql`, and `user_id → core.users`, added by `20260819000100_content_storefronts_user_id.sql`). Both happen to be `confdeltype = 'c'` today, so the test currently passes for the right reason by coincidence — but the query has no `ORDER BY` and no scoping to the referenced table, so a future FK with different delete behaviour could silently be the one the test reads at index 0, masking a real regression to the constraint the test's comment says it's pinning.
    - **Plain English:** This check is supposed to prove one specific safety rule holds, but instead of asking for that rule by name it just grabs whatever rule happens to be listed first and assumes it's the right one. Right now there are only two rules and they agree, so nobody's noticed. If a third, different rule is ever added, this check could keep passing while quietly watching the wrong thing.
    - **Evidence:**
        ```sql
        SELECT confdeltype FROM pg_constraint
        WHERE conrelid = 'content.storefronts'::regclass AND contype = 'f'
        ```
        ```php
        expect($fk[0]->confdeltype)->toBe('c');
        ```

- [ ] **#TEST-3** · P2 — `PoolController` foreign-item 404 test asserts only the generic `HttpException` parent class, not the 404 status the anti-enumeration doctrine requires
    - **Where:** tests/Feature/Content/PoolLaneTest.php:271-281
    - **Affects:** CI's ability to catch a regression from 404 ("not yours") to 403 on pool selection writes.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `->toThrow(HttpException::class)` with an assertion that pins the status code, e.g. `->toThrow(fn (HttpException $e) => expect($e->getStatusCode())->toBe(404))`.
    - **Technical:** `Symfony\Component\HttpKernel\Exception\HttpException` is the parent of every status-carrying HTTP exception, including the 403 `AccessDeniedHttpException` and the 423 pending-deletion response this codebase also throws from policies. Per CLAUDE.md's authorization doctrine, "denied because not yours" on a public/tenant-scoped endpoint must be 404 (anti-enumeration), never 403. This test's name promises exactly that contract, but its assertion would also pass if the ownership check regressed to a 403 or any other `HttpException` subtype.
    - **Plain English:** The test's title says "make sure a stranger's item looks like it doesn't exist," but the actual check only confirms "some kind of error happened." If a future change accidentally told strangers "you're not allowed" (403) instead of "that doesn't exist" (404) — which leaks the existence of other people's data — this test would still say everything's fine.
    - **Evidence:**
        ```php
        expect(fn () => app(PoolController::class)->select($request, 'watch', $foreign))
            ->toThrow(HttpException::class);
        ```

- [ ] **#TEST-4** · P2 — `OutboundHttpScanner::alternativeTransports()` (Rule 1, the curl/Guzzle/`file_get_contents` ban) has no positive control proving it can detect a violation
    - **Where:** tests/Feature/Architecture/OutboundHttpGuardTest.php:96-105
    - **Affects:** CI's SSRF-adjacent guard that every outbound HTTP call in `app/` goes through the Laravel `Http` facade only.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a test that feeds `OutboundHttpScanner::alternativeTransports()` a stub source string containing `curl_init(...)`, `new \GuzzleHttp\Client()`, and `file_get_contents('http://...')`, and asserts each is reported.
    - **Technical:** Rule 1 asserts only `expect(OutboundHttpScanner::alternativeTransports(base_path('app')))->toBe([])` against the current `app/` tree. Unlike Rule 2 (`httpFacadeCallSites()`), which is implicitly proven against real code by the "no stale allowlist entries" test — a broken scanner there would make dozens of real allowlist entries look stale and fail loudly — Rule 1 has no real curl/Guzzle call anywhere in `app/` to prove positive detection against. A scanner that always returned `[]` would pass this test forever with zero evidence it can catch anything.
    - **Plain English:** This is a smoke detector tested only by checking that it stays quiet in a room with no smoke. Nobody has ever held a match under it to prove it would actually beep. If the sensor died, the "all clear" reading would look identical either way.
    - **Evidence:**
        ```php
        it('has exactly one outbound HTTP door — no curl, no direct Guzzle, no URL file reads (Rule 1)', function () {
            $violations = OutboundHttpScanner::alternativeTransports(base_path('app'));

            expect($violations)->toBe([], ...);
        });
        ```

- [ ] **#TEST-5** · P2 — The single-account social convergence migration's destructive ranking DELETE has no data-driven test
    - **Where:** supabase/migrations/20260820110000_single_account_social_convergence.sql
    - **Affects:** Any install carrying duplicate active social `site.platform_connections` rows; the user's live connection could be soft-deleted if the ranking predicate is ever re-run or reapplied against a different schema state.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a Postgres-lane test (`tests/Postgres/` or `tests/Schema/`) that seeds rows covering active-vs-inactive, primary-vs-non-primary and creation-order ties, and asserts the surviving row is the active, then primary, then oldest one.
        - Run the migration's window-function query a second time on the resulting state and assert no further rows change (idempotency).
    - **Technical:** The migration orders candidates by `is_active desc, is_primary desc, created_at asc, id asc` and soft-deletes every `rn > 1` row per identity group. A flipped `desc`/`asc` would delete the live connection in favour of a stale or inactive one, and the delete is not reversible in the ordinary sense (soft-delete, but nothing currently un-deletes it programmatically). This is exactly the class of one-time destructive DML the "grep the CHECK constraint" convention in `ConstraintVocabularyLockstepTest.php` doesn't cover, because there's no CHECK here — the risk is in the ranking logic itself.
    - **Plain English:** This one-time cleanup keeps the "real" version of a duplicated social link and quietly retires the rest — like deleting duplicate phone contacts by keeping the most-used, primary, oldest one. If the ranking logic is backwards, it deletes the live contact and keeps the dead one, with no built-in undo. A rehearsal on sample data before this runs for real would catch that.
    - **Evidence:**
        ```sql
        order by is_active desc, is_primary desc, created_at asc, id asc
        ...
        update site.platform_connections pc
        set deleted_at = now(), is_active = false, updated_at = now()
        from ranked
        where ranked.id = pc.id
          and ranked.rn > 1;
        ```

- [ ] **#TEST-6** · P2 — `unified_actions` migration's legacy-row DELETE/UPDATE statements aren't covered by the file's own referenced lockstep test
    - **Where:** supabase/migrations/20260823100000_unified_actions.sql
    - **Affects:** `analytics.action_events` rows and `site.sites.settings` keys; a bad regex or JSONB predicate here removes valid rows/keys instead of legacy ones.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extend `tests/Feature/Database/ConstraintVocabularyLockstepTest.php` (or a sibling migration-data test) to seed both legacy-format and new-format `action_events` rows plus `site.sites.settings` carrying `smart_actions`/`manual_actions`/`manual_order_pools`, then assert only the legacy rows/keys are removed.
    - **Technical:** The migration file's own comment states the CHECK it adds is lockstep-tested, and `ConstraintVocabularyLockstepTest.php` does exactly that for the `IN (...)` vocabulary. But the same migration also runs `DELETE FROM analytics.action_events WHERE action_id !~ '^(page|platform|item|category):'` and `UPDATE site.sites SET settings = settings - 'smart_actions' - ...`, neither of which the named test touches. A typo'd regex or a wrong `?|` operand would silently delete or preserve the wrong rows/keys with no test to catch it.
    - **Plain English:** This migration relabels how analytics events are filed and throws out the old labels. The one safety check that exists only verifies the new label list is spelled right — it says nothing about whether the "throw out the old stuff" step is throwing out the right stuff.
    - **Evidence:**
        ```sql
        DELETE FROM analytics.action_events
            WHERE action_id !~ '^(page|platform|item|category):';

        UPDATE site.sites
            SET settings = settings - 'smart_actions' - 'manual_actions' - 'manual_order_pools'
            WHERE settings ?| ARRAY['smart_actions', 'manual_actions', 'manual_order_pools'];
        ```

- [ ] **#TEST-7** · P2 — `UpdateSiteRequest`'s publish-readiness guard (no `display_name` → can't publish) has no test
    - **Where:** app/Http/Requests/Api/User/Site/UpdateSiteRequest.php:124-126
    - **Affects:** The site publish flow; a professional with an empty `display_name` could otherwise publish a malformed public sitepage.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('rejects is_published=true when display_name is empty', ...)` asserting a 422 on `settings.is_published` (or the relevant field) with the "Cannot publish" message.
        - Add `it('accepts is_published=true when display_name is present', ...)`.
    - **Technical:** `withValidator()` adds a post-validation error when `is_published` is true and `$professional->display_name` is empty. `core.users.display_name` is `NOT NULL` with no default, so this can only be reached via an empty string, not a true NULL — a narrower but real edge case (e.g. a pre-account or partially-onboarded user). No test in `tests/Feature/Api/User/SiteManagement/` or `tests/Feature/Api/Staff/UserSiteManagement/` exercises this branch (repo-wide grep for "Cannot publish" / "must have a display name" returned nothing).
    - **Plain English:** There's a rule that says "you can't flip your page to public until you've told us your name." Nobody has a test proving that rule actually blocks the save with a clear error — if it silently stopped working, a professional could go live with a blank-name page.
    - **Evidence:**
        ```php
        if (empty($professional->display_name)) {
            $validator->errors()->add('is_published', 'Cannot publish: professional must have a display name.');
        }
        ```

- [ ] **#TEST-8** · P2 — The "single-flights concurrent requests" cache test is two sequential requests, not a concurrency test
    - **Where:** tests/Feature/Api/PublicSite/IndividualProfileControllerTest.php:866-896
    - **Affects:** Confidence that the public-profile payload cache actually collapses simultaneous cold-cache requests into one build (`CacheLockService::rememberLocked`), rather than just serving a warm cache on the second call.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a genuine contention test: acquire the lock the resolver would take before calling the endpoint, then assert a concurrent caller either waits or reads the winner's result, and the builder mock is invoked exactly once across both.
        - Keep the current test but rename it to describe what it actually proves (cache-hit-on-second-call), and don't call it "single-flight."
    - **Technical:** The test makes `getJson()` twice, one after the other. The first call warms the cache; the second call hits that now-warm cache. This proves nothing about lock contention — a naive, non-locking `Cache::remember()` implementation with no single-flight protection at all would pass this exact test just as easily, since there's never a moment where two requests are cold at the same time.
    - **Plain English:** This is like testing whether a door has a working lock by turning the handle twice, one after the other, by the same person. The second try obviously opens — the door was never contested. It proves nothing about what happens if two people grab the handle at the same instant, which is the actual scenario the lock exists for.
    - **Evidence:**
        ```php
        it('single-flights concurrent requests so only one payload is built', function () {
            ...
            // First request — resolve cache miss → DB lookup; payload cache miss → builder called once.
            $res1 = $this->getJson('/api/public/profiles/singleflight-pro')->assertOk();

            // Second request — resolve cache hit (30s TTL); payload cache hit → builder NOT called again.
            $res2 = $this->getJson('/api/public/profiles/singleflight-pro')->assertOk();
        ```

- [ ] **#TEST-9** · P2 — `PoolResolver::popularityRanks()`'s `rememberLocked` single-flight path has no concurrent-collapse test
    - **Where:** app/Site/Pools/PoolResolver.php:780-785
    - **Affects:** Public pool payloads (`ActionCandidates`, `PoolWire`, `resolve()`) — a hot public-sitepage read path.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a test that forces a cold cache and invokes the code path twice concurrently (or via a mock side-effect that simulates the interleave, as `ShopSelectionLockTest.php`'s F2 case does), asserting the underlying `ContentPopularityReader::forSite()` call happens once.
    - **Technical:** This is the one `rememberLocked` cache-lock path found in scope with genuinely no test proving the lock collapses concurrent misses (repo-wide grep for `popularityRanks`/`sitePopularityRanks` in `tests/` returned nothing). Without it, a misconfigured lock driver or a refactor that bypasses `rememberLocked` would silently regress this hot path back to one Postgres read per concurrent request under load.
    - **Plain English:** This code is supposed to make sure that if 100 visitors hit a popular page at once, only one of them actually triggers a database lookup for popularity scores, and the other 99 share that answer. Nothing currently proves that sharing mechanism works — it's possible all 100 quietly go to the database today and nobody would notice until traffic spikes.
    - **Evidence:**
        ```php
        $ranks = $this->cache->rememberLocked(
            CacheKeyGenerator::sitePopularityRanks((string) $site->id),
            self::POPULARITY_CACHE_TTL_SECONDS,
            fn () => $this->popularity->forSite((string) $site->id),
        );
        ```

- [ ] **#TEST-10** · P2 — `ActionCandidates::forSite()` silently swallows a content-lane `QueryException` into `$pools = []` with no failure-path test
    - **Where:** app/Site/Actions/ActionCandidates.php:158-161
    - **Affects:** Public sitepage action candidates — a real content-lane outage silently drops item/category candidates while page/platform candidates keep rendering, with no log or Nightwatch signal.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a test where `PoolWire::forSite()` throws `QueryException` (e.g. via the missing-table fault-injection idiom used in `PresenceProbeEscalationTest.php`/`DegradedPayloadTtlTest.php`) and assert `forSite()` still returns page/platform candidates with no item/category entries and no exception escaping.
    - **Technical:** Unlike its sibling degradation patterns elsewhere in the codebase (e.g. `SitepageDataResolverService::hasDegraded()`, which is observable and test-pinned in `DegradedPayloadTtlTest.php`), this catch block has no logging and no test. Repo-wide search confirms no test targets `ActionCandidates::forSite()`'s own catch — `PresenceProbeEscalationTest.php` exercises `PoolWire`/`PoolResolver` directly, not this wrapper.
    - **Plain English:** Picture a menu app that, when its "today's specials" database hiccups, quietly removes the specials from the page but leaves everything else looking normal — and never tells anyone something broke. A test should prove the app still shows what it can, and ideally that the outage becomes visible to whoever's on call.
    - **Evidence:**
        ```php
        if ($pools === null) {
            try {
                $pools = $this->poolWire->forSite($site, $this->resolver);
            } catch (QueryException) {
                $pools = [];
            }
        }
        ```

- [ ] **#TEST-11** · P2 — `PoolSectionProvisioner::ensure()`/`ensurePage()`'s "lost the first-read race" catch blocks have no concurrency test
    - **Where:** app/Site/Pools/PoolSectionProvisioner.php:93-108, 115-128
    - **Affects:** First-ever read of a pool/page for a site (dashboard load or a visitor hitting a brand-new site) — a race between two first-reads relies on this catch to hand both callers the same winning row.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a test simulating two concurrent `ensure()` calls for the same `(site, pool)` (e.g. force the first insert to throw via a mock/hook) and assert exactly one `site.sections` row exists and both callers resolve it.
        - Add the parallel case for `ensurePage()`.
    - **Technical:** The comment documents the intended idempotent fallback under a unique index, but repo-wide search (including `tests/Postgres/`, the applied-schema lane where a real unique-constraint violation could be exercised) turned up no test for this specific contention. If the catch block's re-read query ever drifted from the actual unique index columns, the fallback could return `null` or the wrong row instead of the winner's.
    - **Plain English:** Two visitors land on a brand-new page at the exact same moment, and both trigger "set this page up for the first time." The code is supposed to let one of them win and quietly hand the second one the same result instead of an error. Nothing currently proves that handoff actually works — if it broke, one of those visitors could see a crash instead of a normal page.
    - **Evidence:**
        ```php
        } catch (QueryException) {
            // Lost the first-read race — the winner's row is the section.
        }
        ```

- [ ] **#TEST-12** · P2 — `ConnectionIdentity::identityOf()`'s no-`url` legacy marker branch — a documented past production near-miss — has no regression test
    - **Where:** app/Routing/ConnectionIdentity.php:177-194
    - **Affects:** Link-routing dedupe for legacy singleton connections (Instagram and similar handle-identity surfaces); a regression here reintroduces duplicate connections for the same account.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `it('resolves a legacy marker row whose payload has username but no url', ...)` seeding a connection shaped exactly as `InstagramConnectionSeeder` writes it (`username`/`fullName`/`images`, no `url` key) and asserting `matchExisting()` finds it.
        - Add a negative case with an unrelated username to pin the positive-identifier rule.
    - **Technical:** The code comment states plainly: "this is the ACTUAL R4 shape and the reason a url-only resolver would have passed its test and done nothing in production." Repo-wide search of `tests/Feature/Routing/ConnectionIdentityAliasTest.php` (the closest existing test file) shows every fixture seeds `payload: ['url' => ...]` — none reproduces the no-`url` shape the fix specifically targets. The exact bug class this fix closed can recur unnoticed.
    - **Plain English:** A past bug shipped because the test used a convenient, simplified version of the data instead of the real shape the system actually produces — and the test passed while the fix did nothing. The fix is now in place, but without a test using the *real* data shape, a future change could silently reintroduce the same bug the same way.
    - **Evidence:**
        ```php
        // No url. This is the ACTUAL R4 shape and the reason a url-only
        // resolver would have passed its test and done nothing in production:
        // InstagramConnectionSeeder writes username/fullName/images and never a
        // `url` key at all.
        ```

- [ ] **#TEST-13** · P2 — `RoutingController::store()`'s item/event seeder failure fallthrough is untested
    - **Where:** app/Http/Controllers/Api/Routing/RoutingController.php (content-item/event branch, try/catch around `seedItem`/`seedStandalone`)
    - **Affects:** Users pasting a video/track/episode/event link; an upstream outage or malformed response during item recognition.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a test where `MediaSeeder::seedItem()` throws and assert the request falls through to ordinary link routing (202, a normal link/review/null outcome) instead of a 500.
        - Add a parallel test for `EventSeeder::seedStandalone()` throwing.
    - **Technical:** The code wraps the item/event recognition call in a try/catch that `report()`s the exception and sets `$written = null`, then continues into the ordinary routing path so the link still lands as a card. No test in the provided scope or a repo-wide grep for "falls through to ordinary routing" / a forced seeder exception exercises this specific guarantee — a regression that let the exception propagate would crash the whole paste instead of degrading gracefully.
    - **Plain English:** When the system tries to recognise a pasted link as a specific video or event and that recognition fails, it's supposed to quietly fall back to saving it as a normal link instead. Nothing currently proves that fallback works — if it broke, a single failed recognition could crash the entire "add a link" action instead of just saving a plain link.
    - **Evidence:**
        ```php
        } catch (\Throwable $e) {
            report($e);
            $written = null;
        }

        if ($written !== null) {
            // ... early return 202 item
        }
        ```

- [ ] **#TEST-14** · P2 — `SuggestionsController::acceptPayloadFinding()`'s `settlePayloadFinding` lock-timeout branch has no test
    - **Where:** app/Http/Controllers/Api/Routing/SuggestionsController.php:319-349
    - **Affects:** Users accepting a swapped connection from the legacy Instagram/Google-Business synced-findings modal; a settle that times out on lock contention must not mark the finding done for a change that never landed.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a test where `Cache::lock(...)->block(5, ...)` throws `LockTimeoutException` during `settlePayloadFinding()` and assert the endpoint returns 423 with no finding marked `'seeded'`.
    - **Technical:** This method has two distinct failure exits. The first — `applyFinding()` returning false on a contended booking/reservations XOR lock — is already well covered: `tests/Feature/Platforms/BookingXorConnectRaceTest.php`'s "a held booking-XOR lock makes google-business 'Change to' fail honestly" cases hit this exact endpoint format (`sync:google-business:fresha/accept`) under a held lock and assert 423. The second exit — the `platformConnectionLock` around `settlePayloadFinding()` itself timing out — has no equivalent test anywhere in scope. A regression there would settle a finding as done while the write it claims happened never landed, silently losing a connection, which is exactly the failure mode the code comment warns against.
    - **Plain English:** When a user accepts a swap, the system does the swap first, then writes down "done." Two different things can currently go wrong on the "writing it down" step, and only one of them is proven to fail safely — the other could theoretically mark a swap as complete when it actually never finished saving.
    - **Evidence:**
        ```php
        try {
            Cache::lock(CacheKeyGenerator::platformConnectionLock($located['holder'], (string) $user->id), 10)
                ->block(5, fn () => $this->bridge->settlePayloadFinding($located['connection'], $located['index'], 'seeded'));
        } catch (LockTimeoutException) {
            return $this->error('Another change is still saving — please retry in a moment.', 423);
        }
        ```

- [ ] **#TEST-15** · P2 — `SuggestionsController::googleListingSuggestion()`'s four suppression branches are untested
    - **Where:** app/Http/Controllers/Api/Routing/SuggestionsController.php:141-165
    - **Affects:** Owners with a Google Business connection; a suppression regression re-nags a reservation-link suggestion the owner already resolved or already has.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add tests for: no Google Business connection → null; no/empty OpenTable-style URL → null; an existing `routing_class = 'reservations'` connection → null; a dismissed tombstone (`LISTING_REF`) → null; and the positive case (all conditions clear) returning the suggestion.
    - **Technical:** Repo-wide search for `LISTING_REF`, `googleListingSuggestion`, and "google-listing" in `tests/` found no test exercising this method at all. It has four independent early-return gates; any one regressing means the inbox repeatedly offers a suggestion the owner already has or already dismissed.
    - **Plain English:** This suggestion is only supposed to appear when four separate conditions are all true. There are four separate "off switches," and none of them currently has a test proving it actually turns the suggestion off. If one silently broke, an owner could see the same nag over and over.
    - **Evidence:**
        ```php
        if ($gb === null) {
            return null;
        }
        ...
        if ($hasReservation) {
            return null;
        }
        ...
        if ($dismissed) {
            return null;
        }
        ```

- [ ] **#TEST-16** · P2 — `CommerceProbeJob::failed()`'s zero-loss fallback (`suggestOnly`/pending-deletion gates) has no test
    - **Where:** app/Jobs/Platforms/CommerceProbeJob.php (`failed()`)
    - **Affects:** Scanned and pasted links; a regression could duplicate a link card or card a user already pending deletion.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `it('failed() cards the link when suggestOnly is false')`, `it('failed() does not card when suggestOnly is true')`, and `it('failed() no-ops for a missing or pending-deletion user')`.
    - **Technical:** Repo-wide search found no test calling `(new CommerceProbeJob(...))->failed(...)` anywhere, despite the method implementing a deliberate zero-loss fallback (seed a custom link unless `suggestOnly`, and never for a missing/pending-deletion user). This is a distinct code path from the `handle()`-level tests in `CommerceProbeObservationTest.php`, which exercise normal completion, not a worker crash.
    - **Plain English:** If the background worker crashes while looking at a pasted link, there's a backup plan to still save the link as a plain card so nothing is lost — but only when it should (not for a link that's only being previewed, and not for a user who's deleting their account). None of that backup logic has a test.
    - **Evidence:**
        ```php
        public function failed(Throwable $e): void
        {
            ...
            if ($this->suggestOnly) {
                return;
            }

            try {
                $user = User::find($this->userId);
                if ($user !== null && ! $user->isPendingDeletion()) {
                    app(CustomLinkSeeder::class)->seedCustom($user, $this->url);
                }
            } catch (Throwable $cardError) {
                report($cardError);
            }
        }
        ```

- [ ] **#TEST-17** · P2 — `CommerceProbeJob`'s `ShouldBeUnique` dedup contract has no dispatch-twice or idempotency test
    - **Where:** app/Jobs/Platforms/CommerceProbeJob.php:56, 86-89 (`$uniqueFor`, `uniqueId()`)
    - **Affects:** Scanned links; duplicate dispatches for the same user+URL within 300s could run duplicate probes or write duplicate cards.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a test dispatching two identical `CommerceProbeJob`s and asserting only one executes (`Bus::fake()` + assert-pushed count, or a real-queue run asserting `handle()` fires once).
        - Add an idempotency test running `handle()` twice directly and asserting identical end-state.
    - **Technical:** `uniqueId()` keys on `userId:sha1(url)` with `uniqueFor = 300`, which is load-bearing for the scrape/import pipeline, but no test in scope proves either the dedup key actually collapses a duplicate dispatch or that a genuine re-delivery (queue redelivery, not a fresh dispatch) leaves the end-state unchanged.
    - **Plain English:** A "do this only once" label is attached to this job, but nothing proves the label actually stops the same link from being processed twice in a five-minute window.
    - **Evidence:**
        ```php
        class CommerceProbeJob implements ShouldBeUnique, ShouldQueue
        {
            public int $uniqueFor = 300;

            public function uniqueId(): string
            {
                return $this->userId.':'.sha1($this->url);
            }
        }
        ```

- [ ] **#TEST-18** · P2 — `ShopInitialFillJob`'s independent try/catch continuation and idempotency are untested
    - **Where:** app/Jobs/Platforms/ShopInitialFillJob.php (`handle()`)
    - **Affects:** Newly connected or scan-suggested shop stores; the one-shot catalogue fill and first-connect auto-select can be silently skipped or double-run.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a failure-path test where `ShopCatalog::syncLatest()` throws and assert `ShopAutoSelector::selectInitial()` still runs.
        - Add a failure-path test where `selectInitial()` throws and assert the job still completes without throwing.
        - Add an idempotency test running `handle()` twice and asserting identical catalogue/pin end-state.
    - **Technical:** No dedicated `ShopInitialFillJobTest.php` exists, and the closest matching coverage (`StoreBrandSeederTest.php`'s "dispatches the initial fill... on a first connect only") only tests the *dispatch* condition, not the job's own internal `handle()` failure-isolation and idempotency behaviour. The two steps are wrapped in independent try/catch blocks specifically so one failing doesn't block the other — that design intent has no test proving it holds.
    - **Plain English:** This job stocks a new store's shelves and then picks a few best-sellers to feature. If stocking the shelves hits a snag, picking the best-sellers should still happen — and running the whole job twice by accident shouldn't double anything up. Neither guarantee currently has a test.
    - **Evidence:**
        ```php
        try {
            $catalog->syncLatest($store, (string) $store->userId);
        } catch (Throwable $e) {
            Log::warning('shop.initial_fill_job.fill_failed', [...]);
        }

        try {
            $selector->selectInitial($this->collectionId);
        } catch (Throwable $e) {
            Log::warning('shop.initial_fill_job.auto_select_failed', [...]);
        }
        ```

- [ ] **#TEST-19** · P2 — `GoogleBusinessAutoSync::seedSocials()`'s facebook/tiktok/x/linkedin check-then-write loop is unlocked and untested for concurrency
    - **Where:** app/Services/Platforms/GoogleBusinessAutoSync.php:739-796
    - **Affects:** Users whose Google Business enrichment runs concurrently with itself via queue retries — a duplicate social connection can be written instead of reported as a conflict.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap each social's `has()` + `write()` pair in a per-platform seed lock, matching the pattern `dispatchInstagram()`'s placeholder write already uses (`withPlatformSeedLock`).
        - Add a concurrent-dispatch test: two simultaneous `seed()` calls for the same user/platform produce one row and one conflict finding, not two rows.
    - **Technical:** `seedSocials()` calls `$this->has($userId, $platform)`, and only if false, `$this->write(...)`, with no lock spanning the check and the write. The sibling `seedReservation()`/`seedOrdering()`/`dispatchInstagram()` paths in the same class are all explicitly locked for this exact race (verified via `tests/Feature/Platforms/AutoSyncSeederLockTest.php`, PWL-9), but this loop — for facebook/tiktok/x/linkedin specifically — is not, and no test covers it.
    - **Plain English:** Three sibling desks in this same office already have a "one person at a time" rule for updating a shared clipboard, and each rule is proven with a drill. The social-media desk has no such rule: two staff can both check "is this connected?", both see no, and both connect it — and nothing catches that.
    - **Evidence:**
        ```php
        if ($this->has($userId, $platform)) {
            $findings[] = $this->conflictFinding($platform, $platform, 'social', $label, $url, [
                'remove' => [$platform], 'write' => $write,
            ]);

            continue;
        }
        $this->write($userId, $platform, $platform, $payload);
        ```

- [ ] **#TEST-20** · P2 — `LinkRouter::seedReservation()`/`seedOnlineOrdering()`'s check-then-write spans have no lock and no concurrency test
    - **Where:** app/Services/Platforms/LinkRouter.php:425-460, 483-520
    - **Affects:** Users whose Instagram bio / link-in-bio / Google Business auto-routes race across two queue workers — duplicate reservation or ordering connections can be written for the same identity.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap the incumbent query + write in the same reservations-XOR / per-platform seed lock `GoogleBusinessAutoSync::seedReservation()`/`seedOrdering()` already use for the identical pattern.
        - Add a concurrent-dispatch test routing the same URL twice from separate contexts and asserting exactly one `IntegrationConnection` survives for the family/slot.
        - Do not rely on `LinkRouter::$routing` as the concurrency guard — confirmed to be a static, in-process reentrancy marker only, not cross-request/cross-job mutual exclusion.
    - **Technical:** Verified directly against `app/Services/Platforms/LinkRouter.php`: both methods do an incumbent query, a gap, then a `write()` call with no `Cache::lock`/`CacheLockService` anywhere in the file. `GoogleBusinessAutoSync`'s structurally identical `seedReservation()`/`seedOrdering()` methods ARE locked and ARE tested for contention (`AutoSyncSeederLockTest.php`), and `LinkRouter` is invoked from the same class of background paths (`InstagramAutoSync`, `GoogleBusinessAutoSync`, `CustomLinkSeeder`) — this is an inconsistency, not a deliberate no-lock decision. `OrderingSlotSwapTest.php`/`ReservationCapSwapOriginTest.php` prove the swap-vs-pool logic is functionally correct sequentially, but none exercise two calls landing inside each other's read-then-write window.
    - **Plain English:** Two automated processes can both check an empty diary slot at the same instant, then both write the same appointment into it — the diary never stood a chance. The other half of this exact feature already locks the diary while one process writes; this half does not, and nothing has ever tested it under a race.
    - **Evidence:**
        ```php
        $incumbent = IntegrationConnection::query()
            ->where('user_id', (string) $user->id)
            ->where('routing_class', 'reservations')
            ->orderBy('created_at')
            ->get()
            ->first(fn (IntegrationConnection $row) => ! $this->sameUrl(
                (string) (CardPayload::fromArray($row->payload)->url() ?? ''), $url,
            ));
        ```
        followed later by:
        ```php
        $this->write((string) $user->id, $platform, $resourceId, $payload);
        ```

## P3 — Nice to have

- [ ] **#TEST-21** · P3 — `item_scores_keyed_by_id` migration's legacy-score DELETE has no test
    - **Where:** supabase/migrations/20260823120000_item_scores_keyed_by_id.sql
    - **Affects:** `analytics.content_popularity_scores` rows for `shop_product`/`link_item`; a temporary ranking artefact.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a small data test asserting the `DELETE` only removes `content_type IN ('shop_product','link_item')` rows and leaves every other taxonomy family untouched.
    - **Technical:** Same class of gap as #TEST-5/#TEST-6, but lower risk: the migration's own comment notes these rows recompute from raw events within roughly 15 minutes, so a wrong predicate here is self-healing rather than a permanent data loss.
    - **Plain English:** This deletes old shelf tags that were printed in the wrong format. New tags print automatically soon after, so getting this wrong is a brief inconvenience, not a lasting loss — a small test would still confirm only the outdated tags are thrown out.
    - **Evidence:**
        ```sql
        DELETE FROM analytics.content_popularity_scores
            WHERE content_type IN ('shop_product', 'link_item');
        ```

- [ ] **#TEST-22** · P3 — `OutboundHttpGuardTest`'s Pattern D check only proves a `preg_match(self::CONST, ...)` call exists in the file, not that the validated value is what reaches the URL
    - **Where:** tests/Feature/Architecture/OutboundHttpGuardTest.php:151-173
    - **Affects:** Fixed-host, variable-path outbound fetchers (`YoutubeThumbnailResolver`, `GoogleBusinessService`, `LinkInBioApiUnroller`) — the guard against path-syntax injection into an otherwise-safe hardcoded host.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Strengthen the check so it confirms the validated variable (or a checked `preg_match` return) is the one actually interpolated into the URL builder, not merely that some `preg_match` call using the named constant exists anywhere in the file.
    - **Technical:** As the code's own comment notes, this is a syntax-injection concern (not SSRF — the host is already fixed), which lowers its severity, but the check itself is textual: a file could call `preg_match(self::PATTERN, $raw)`, ignore the boolean result, and interpolate an entirely different unvalidated variable into the URL, and this test would still pass.
    - **Plain English:** The inspector confirms a guardrail post exists somewhere on the bridge, but never checks whether it's actually bolted between the road and the drop. A file can mention the safety check without the check doing anything, and still pass inspection.
    - **Evidence:**
        ```php
        $declaresPattern = str_contains($source, "const {$constant}");
        $appliesPattern = preg_match('/preg_match\s*\(\s*self::'.preg_quote($constant, '/').'/', $source) === 1;
        ```

- [ ] **#TEST-23** · P3 — Action beacon dedup tests only exercise the session-id path, never the visitor-id-only path the file's own comment promises
    - **Where:** tests/Feature/Analytics/ActionBeaconsTest.php:7-11, 85-100
    - **Affects:** Precision of this file's coverage claim for `action-seen`/`action-tap` dedup.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a dedup case posting the same payload twice with `visitor_id` set and no `session_id`, asserting one row.
    - **Technical:** The file's header comment claims dedup "per (session|visitor)+action," but the only dedup test (`'deduplicates a repeat event for the same action by the same session...'`) always sets `session_id`. The underlying `dedupIdentifier()` helper (`visitor_id ?? session_id ?? ip fallback`) is already proven to prefer `visitor_id` correctly for click/section/item beacons in `ClickDedupTest.php`/`PublicIngestHardeningTest.php`, so the functional risk here is low — this is a precision/documentation gap in one file's claimed coverage, not an unverified mechanism.
    - **Plain English:** This test file's own notes say it checks duplicate clicks whether the visitor is identified by a session or just a persistent visitor id. All the actual duplicate-click tests use the session id only — the visitor-id path is never directly exercised in this file, even though the underlying mechanism is proven elsewhere.
    - **Evidence:**
        ```php
        // ...dedups within a window per (session|visitor)+action.
        it('deduplicates a repeat event for the same action by the same session within the dedup window', function (string $path, string $event) {
        ```

- [ ] **#TEST-24** · P3 — Public-profile shape test doesn't assert the recently-published `publicConfig.displayGalleryPage`/`shopLinkMode` fields
    - **Where:** tests/Feature/Api/PublicSite/IndividualProfileControllerTest.php (skeleton-envelope shape test)
    - **Affects:** Visibility of a regression to two fields recently added to the public profile wire contract.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add explicit `toHaveKey('displayGalleryPage')` / `toHaveKey('shopLinkMode')` assertions on `publicConfig` in the route-level shape test.
    - **Technical:** Recent commits (`1836fae0f`, `c46db360d`) publish `displayGalleryPage` and `shopLinkMode` on `publicConfig`, but the existing shape assertion is deliberately partial, checking only `analyticsEndpoint` — by design, so future additions don't break the test, but that same design means a regression to either new field is also invisible.
    - **Plain English:** This is the check that the public profile hands visitors the right paperwork. It currently only checks for one long-standing piece of paper and ignores everything newer. Two recently-added pieces of paper could go missing and nothing would notice.
    - **Evidence:**
        ```php
        // publicConfig is always emitted (object on the wire). analyticsEndpoint
        // is the only field for now; tested with a partial-shape check so future
        // additions don't break this test.
        expect($data['publicConfig'])->toBeArray();
        expect($data['publicConfig'])->toHaveKey('analyticsEndpoint');
        ```

- [ ] **#TEST-25** · P3 — `IriCanonicalizer`'s deliberately-left-unfixed non-tenanted multi-label subdomain quirk (#SEM-4) has no regression pin
    - **Where:** app/Routing/IriCanonicalizer.php (non-tenanted branch)
    - **Affects:** Any future detector/subdomain-matching change for non-tenanted multi-label hosts (e.g. `www.shop.example.com`).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a test recording the current (buggy but deliberately unfixed) `www.shop.example.com` → subdomain `www.shop` output, so a future change to this branch is a deliberate, reviewed decision rather than an accidental side effect.
    - **Technical:** The code comment explicitly documents this as the same bug class already fixed on the tenant-scoped branch (#SEM-4), left as-is here because fixing it changes detector behaviour more broadly and was deferred as a follow-up. That makes it exactly the kind of known, deliberately-parked quirk that should be pinned by a characterisation test so a future "cleanup" doesn't change platform-detection behaviour by accident.
    - **Plain English:** There's a known, minor mistake in how the system reads certain web addresses, currently left alone on purpose because fixing it would ripple into a lot of other behaviour. Right now that decision lives only in a code comment. A small test that records today's behaviour means nobody can undo that decision by accident.
    - **Evidence:**
        ```php
        // NOTE: this branch has the same class of bug the tenant-scoped
        // branch above was fixed for (#SEM-4) — `www.shop.example.com`
        // yields subdomain 'www.shop', not 'shop'. Left as-is: ...
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Pool subsystem race/fail-open coverage:** #TEST-9, #TEST-10, #TEST-11
    - **Why grouped:** all three live in `app/Site/Pools`/`app/Site/Actions` and are variations of the same "no test for the fail-open or first-read-race branch" pattern; one session can add all three with shared fixtures.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Platform auto-sync locking parity:** #TEST-19, #TEST-20
    - **Why grouped:** same root cause (an unlocked check-then-write copied from a pattern that's locked everywhere else in `app/Services/Platforms`) and the same fix shape (borrow the existing `withPlatformSeedLock`/XOR-lock convention).
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet — escalate implement → Opus (concurrency-sensitive production code change alongside the test).

- **Bundle 3 — Shop/Commerce job hygiene tests:** #TEST-16, #TEST-17, #TEST-18
    - **Why grouped:** all three are `app/Jobs/Platforms` failure-path/idempotency test gaps with no production code change required.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 4 — Suggestions/Routing controller failure-path tests:** #TEST-13, #TEST-14, #TEST-15
    - **Why grouped:** same two controllers (`RoutingController`, `SuggestionsController`), same theme (untested failure/suppression branches), no production code changes needed.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 5 — Migration DML coverage tests:** #TEST-5, #TEST-6, #TEST-21
    - **Why grouped:** same pattern (destructive one-time migration DML with no data-driven test) and likely share one new test file in the applied-schema/Postgres lane.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 6 — Architecture guard precision (OutboundHttpGuardTest):** #TEST-4, #TEST-22
    - **Why grouped:** same file, same class of "the guard's own test doesn't prove the guard detects a violation" gap.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 7 — Test assertion precision fixes:** #TEST-2, #TEST-3
    - **Why grouped:** both are small, mechanical tightenings of an existing assertion (unscoped FK query; generic exception class) with no behavioural change.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 8 — Public site wire & validation test additions:** #TEST-7, #TEST-8, #TEST-24
    - **Why grouped:** all touch the public-profile/site-update surface with small, independent test additions.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 9 — Regression-pin additions for documented edge cases:** #TEST-12, #TEST-23, #TEST-25
    - **Why grouped:** all three pin a known, already-documented behaviour (a past near-miss, a claimed-but-unexercised path, a deliberately-parked quirk) with a small, self-contained test each.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#TEST-1 — DesignKitCacheInvalidationTest lies about HTTP-path coverage** · P1, and the fix touches the cache-invalidation safety net for the public sitepage — run and reviewed on its own.
