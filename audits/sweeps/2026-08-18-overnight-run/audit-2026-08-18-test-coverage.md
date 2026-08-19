# Test Coverage Audit — 2026-08-18

**Branch:** HEAD
**Lens:** Test coverage: critical paths, idempotency, race-safety, policy abilities, mock-vs-integration discipline (bundle sweep across sweep-conventions, prod-requests, prod-platforms-controllers, prod-catalog-routing-controllers, prod-ingest, content, prod-schema, feature-site-staff, feature-media-jobs, feature-platforms, feature-ingest, feature-content, unit-suite chunks)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- `tests/Pest.php`
- `app/Http/Requests/Platforms/PlatformConnectRequest.php`
- `app/Http/Controllers/Api/Platforms/{DisplaySettingsController,FreshaController,GenericPlatformController,RefreshController}.php`
- `app/Http/Controllers/Api/Routing/RoutingController.php`
- `app/Ingest/Connectors/{AppleMusicConnector,FreshaConnector,GoogleBusinessConnector,YoutubeRssConnector,SoundcloudTracksConnector,SpotifyTracksConnector}.php`
- `app/Ingest/{Landing/Lander,Projection/*,SourceProvisioner}.php`
- `app/Site/Pools/{BorrowedMedia,ItemLinkRules,LiveSourceScope,PoolRegistry,PoolResolver}.php`
- `app/Jobs/Media/MirrorMediaAssetJob.php`, `app/Jobs/Platforms/*.php`
- `supabase/migrations/20260819001000_link_observations_allow_commerce_probe.sql`, `20260819001100_item_media_role_video.sql`
- `tests/Feature/Site/{DocumentBuilderRuleOpsTest,DocumentBuilderTest,DocumentBuilderQueryCountTest,DocumentBuilderTenantScopingTest,PruneSiteDocumentVersionsTest}.php`
- `tests/Feature/Content/{PoolLaneTest,PoolBorrowedMediaPinTest,MediaSectionReshapeTest,MediaPoolFramesTest}.php`
- `tests/Feature/Platforms/{ReservationProvidersTest,BrandConnectGuardTest,...}.php`
- `tests/Feature/Ingest/{LanderTest,GoogleBusinessConnectorTest,InstagramMediaMirrorTest,AppleMusicConnectorTest,YoutubeRssConnectorTest,FreshaConnectorTest,EagerRunOnConnectTest}.php`
- `tests/Feature/Media/MediaMirrorTest.php`
- `tests/Unit/Ingest/ProjectionTest.php`, `tests/Unit/Platforms/Normalizers/LinkNormalizersTest.php`, `tests/Unit/Site/Pools/PoolRegistryTest.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 12 complete
- P3 Low: 0 of 7 complete

---

## P2 — Should fix

- [ ] **#TEST-1** · P2 — Product variant `imageUrl` has never round-tripped against real data
    - **Where:** `app/Site/Pools/PoolResolver.php` (`variants()`)
    - **Affects:** Public product payloads for the shop pool.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a fixture/factory seeded with a non-null `image_url` so the cast branch is exercised against a realistic value.
        - If no vendor currently populates it, note that explicitly rather than shipping an unverified key on the public wire.
    - **Technical:** The code's own comment admits `image_url` is populated on 0 of 268 dev rows, so the cast (`$row->image_url === null ? null : (string) $row->image_url`) only ever round-trips a synthetic test value. A wrong cast or unexpected shape from a future vendor would ship to the public, CDN-cached wire with nothing catching it first.
    - **Plain English:** This is like testing a vending machine with plastic coins instead of real money — the test passes, but nobody has proven the machine works with actual currency. The product photo field has never held a real value, so the test is a dress rehearsal, not proof.
    - **Evidence:**
        ```php
        // Unverified against real data: image_url is populated on 0 of
        // 268 dev rows, so this round-trips in tests only.
        'imageUrl' => $row->image_url === null ? null : (string) $row->image_url,
        ```

- [ ] **#TEST-2** · P2 — `latest_n_per_auto_source` negation is untested despite the rule-DSL file's own "complete coverage" claim
    - **Where:** `tests/Feature/Site/DocumentBuilderRuleOpsTest.php` (file-wide; operator declared in `tests/Unit/Site/Pools/PoolRegistryTest.php`)
    - **Affects:** Media-pool section rendering.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `it('latest_n_per_auto_source negates correctly — the non-newest items surface elsewhere/are excluded as expected', ...)` to `DocumentBuilderRuleOpsTest.php`.
    - **Technical:** `DocumentBuilderRuleOpsTest.php`'s own preamble states "every op must select, reject, and negate against the TYPED tables," but `latest_n_per_auto_source` never appears in that file at all. Its *selection* semantics ARE proven with real seeded data via `tests/Feature/Content/MediaSectionReshapeTest.php` ("publishes only the N newest per source... (R5)"), so this is narrower than a total coverage gap — but negation for this specific operator has no test anywhere, contradicting the file's stated completeness bar.
    - **Plain English:** A recipe file promises "every technique is tested both ways — include and exclude." One technique (take the N newest per source) has been tested for "include," but nobody has tested what happens when you tell it to exclude instead.
    - **Evidence:**
        ```php
        // tests/Feature/Site/DocumentBuilderRuleOpsTest.php
        // The complete rule DSL, one operator at a time (plan §7/§8): every op must
        // select, reject, and negate against the TYPED tables — no cache columns, no
        // silent widening.
        ```

- [ ] **#TEST-3** · P2 — `DocumentBuilder::build()`'s CAS-failure ("superseded") consumer path is untested
    - **Where:** `app/Site/Documents/DocumentBuilder.php` (`build()`), tested only at the primitive level in `tests/Feature/Site/DocumentBuilderTest.php`
    - **Affects:** Concurrent site-document rebuilds during live traffic.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `it('returns status=superseded when content moves under a build, and the caller can detect and retry', ...)` that seeds a revision bump between `build()`'s read and its `BuildState::commit()` call, asserting the returned status.
    - **Technical:** `DocumentBuilderTest.php`'s "refuses to commit a build whose revision has already moved on" test exercises `BuildState::commit()` directly, but never `DocumentBuilder::build()`, which is the actual consumer of that boolean (`if (! BuildState::commit(...)) { return ['status' => 'superseded', ...]; }`). No test proves a caller correctly reacts to `superseded` rather than treating it as success.
    - **Plain English:** This is like testing that a filing cabinet's lock works, but never checking that the clerk actually obeys it when it refuses. If a caller ignores "superseded" and moves on, stale content could quietly stick around.
    - **Evidence:**
        ```php
        if (! BuildState::commit($siteId, $buildingFrom)) {
            // Content moved underneath us. The version we just wrote is not
            // wrong, it is merely superseded — the caller rebuilds.
            return ['status' => 'superseded', 'version' => $version, 'hash' => $hash];
        }
        ```

- [ ] **#TEST-4** · P2 — `LanderTest`'s RuntimeException test never triggers or asserts the exception it claims to guard
    - **Where:** `tests/Feature/Ingest/LanderTest.php` (`raises a descriptive RuntimeException rather than a null-property fatal...`)
    - **Affects:** Error-handling confidence for `App\Ingest\Landing\Lander::landChunk()`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Rewrite the test to actually trigger the winner-resolution failure and assert `->toThrow(RuntimeException::class, ...)` with the message shape.
        - If the failure path is structurally unreachable via the fixture used, say so explicitly and rename the test to describe what it actually proves (successful `landChunk()` wiring), adding a genuine failure-path test alongside it.
    - **Technical:** The test uses reflection to call the protected `landChunk()` with a valid record and only asserts `$changed === 1`. The production `RuntimeException` this test is named for is never thrown or caught — the test is a false positive for coverage of the throw it claims to pin.
    - **Plain English:** This is a fire-drill test that checks the alarm button clicks but never actually sets off the siren. If someone later disconnects the siren, this test would still pass.
    - **Evidence:**
        ```php
        $changed = $reflection->invoke($lander, 's1', 'r1', $spec, [new Record('releases', 'k1', ['title' => 'v1'])], []);
        expect($changed)->toBe(1);
        ```

- [ ] **#TEST-5** · P2 — Cache-purge test claims "all three cache lanes" but only proves at least one job was pushed
    - **Where:** `tests/Feature/Content/MediaSectionReshapeTest.php` (`reshapes a section still carrying latest_per_auto_source and fires all three cache lanes`)
    - **Affects:** Cloudflare cache invalidation strength for the section-reshape command; a regression dropping two of three lanes would pass.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Assert `content_revision` bump (already done), `site.sites.updated_at` bump (already done), and `Queue::assertPushed(CloudflareCachePurgeJob::class, ...)` with an exact expected count/payload, per `SiteCacheLanes::bust()`'s three-lane contract.
    - **Technical:** The test title promises all three cache lanes fire, but `Queue::assertPushed(CloudflareCachePurgeJob::class)` alone is satisfied whether one, two, or three purge jobs were pushed — it does not distinguish a full three-lane bust from a partial one.
    - **Plain English:** The test promises "all three doors closed" but only checks "at least one door might have moved." A two-door failure would still show green.
    - **Evidence:**
        ```php
        Queue::assertPushed(CloudflareCachePurgeJob::class);
        ```

- [ ] **#TEST-6** · P2 — `PoolLaneTest` exercises authorization-sensitive pool actions via direct controller calls, not the HTTP route
    - **Where:** `tests/Feature/Content/PoolLaneTest.php` (`PoolController::select/deselect/reorder`, `ItemController::destroy`, `ItemLinkController::upsert/destroy`)
    - **Affects:** Regression protection for routing, middleware, and policy resolution on pool select/deselect/reorder/delete/link endpoints.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Convert the `Request::create()` + `$request->attributes->set('professional', $pro)` calls to route-level `actingAsUser($pro)->postJson(...)`/`putJson(...)`/`deleteJson(...)`, matching the pattern `tests/Feature/Content/PoolBorrowedMediaPinTest.php` already documents and uses ("Route-level, never a direct controller call: an authorization check reached through a bare Request skips routing, middleware and policy resolution, and green would mean almost nothing").
    - **Technical:** Under Supabase JWT the actor is resolved through the real middleware chain; a direct controller call with a manually-set request attribute proves the method's internal logic but not that the route stack actually authenticates/authorizes the request. `PoolItemCreateController`'s newer tests in the same file already use the route-level pattern — this is inconsistent within one file, not a from-scratch gap.
    - **Plain English:** These tests walk in through the back door instead of the front door. They can check the room is tidy, but they never prove the locks and alarm system between the street and the room actually work.
    - **Evidence:**
        ```php
        $request = Request::create("/api/content/pools/watch/selection/{$vid}", 'POST');
        $request->attributes->set('professional', $pro);
        app(PoolController::class)->select($request, 'watch', $vid);
        ```

- [ ] **#TEST-7** · P2 — `RoutingController::preview()`'s link-in-bio early-return branch has no test
    - **Where:** `app/Http/Controllers/Api/Routing/RoutingController.php` (`preview()`)
    - **Affects:** URL-paste preview UX for Linktree/Beacons/msha.ke/stan.store pages.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('previews a link-in-bio url with the fixed note payload, without calling the router', ...)` posting a `linktr.ee`/`beacons.ai` URL to `/api/routing/preview` and asserting the hardcoded `verdict:'note'` shape.
    - **Technical:** `tests/Feature/Routing/RoutingEndpointTest.php` thoroughly covers `preview()`'s classifier-driven paths (place/note/choose) but never posts a link-in-bio host to `/api/routing/preview` — the `LinkInBioDetector::matches()` early-return branch (a separate code path from the classifier) is unexercised at the route level.
    - **Plain English:** The preview box reacts one way for most links and a special way for link-collection pages. Nobody has actually shown it a link-collection page — a bug there wouldn't be caught until a user saw the wrong preview.
    - **Evidence:**
        ```php
        if (app(LinkInBioDetector::class)->matches($url)) {
            return $this->success([
                'verdict' => 'note',
                'canonicalUrl' => trim($url),
                ...
            ]);
        }
        ```

- [ ] **#TEST-8** · P2 — `RoutingController::store()`'s link-in-bio early-return branch (cap/lock/feature-gate + dispatch ordering) has no test
    - **Where:** `app/Http/Controllers/Api/Routing/RoutingController.php` (`store()`, lines 66–94)
    - **Affects:** Users pasting Linktree/Beacons/msha.ke/stan.store URLs.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add route-level tests posting a link-in-bio URL to `/api/routing/links` for: happy path (`unrolled: true`, `LinkInBioScanJob` dispatched), `cap_full` (422, job NOT dispatched), `busy` (423, job NOT dispatched), `unavailable` (503, job NOT dispatched).
    - **Technical:** `RoutingEndpointTest.php` fully covers the *later* `note`-verdict branch (lines 114-140, reached only for non-link-in-bio unrecognized URLs) including the same cap/busy/unavailable outcomes and lock contention — but never exercises the structurally separate, earlier link-in-bio detector branch that a real Linktree paste actually takes. The code comment on the dispatch line ("must not still fan out (review W3)") shows this exact ordering was a real bug once; a regression here would not be caught by the well-tested later branch, since it is different code.
    - **Plain English:** This endpoint has a "reject" path with three exits and one "accept" path that kicks off a background job. A past bug let a rejected paste still start that job — this exact branch (not the similar-looking one that's well tested) has no test proving that bug hasn't come back.
    - **Evidence:**
        ```php
        if (app(LinkInBioDetector::class)->matches($url)) {
            $write = $this->links->addManual($user, trim($url));
            if ($write['status'] === 'cap_full') { ... }
            if ($write['status'] === 'busy') { ... }
            if ($write['status'] === 'unavailable') { ... }
            // Dispatched AFTER the card write settled: a cap-full page must not
            // still fan out (review W3).
            LinkInBioScanJob::dispatch((string) $user->id, trim($url), AccountCapabilities::for($user)->can_use_booking);
        ```

- [ ] **#TEST-9** · P2 — `DocumentBuilderRuleOpsTest` raw-inserts into `site.pages`/`site.sections` without calling `setupSectionsTables()`
    - **Where:** `tests/Feature/Site/DocumentBuilderRuleOpsTest.php` (`beforeEach()`, then `ruleOpsSite()`/`ruleOpsSection()`)
    - **Affects:** Test-suite reliability under `pest --parallel` (shipped, per repo convention) or any single-file run of this test.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `setupSectionsTables()` to `beforeEach()`, matching `tests/Feature/Site/PruneSiteDocumentVersionsTest.php` and `DocumentBuilderQueryCountTest.php`, which both call it before touching `site.pages`/`site.sections`.
    - **Technical:** `site.pages` and `site.sections` are created ONLY by `setupSectionsTables()` (confirmed: it is the sole `CREATE TABLE` site for both). `DocumentBuilderRuleOpsTest.php`'s `beforeEach()` calls only `setupUsersTable()`, `setupSitesTable()`, `setupContentTables()`, yet its own fixture helpers raw-insert into both tables on line 1. In a shared non-parallel process this is masked by whichever table state an earlier-loaded file already created; under `--parallel` (one process per file) or a solo run, it fails on the very first fixture call with "no such table: site.pages."
    - **Plain English:** This test kitchen assumes another cook already laid out its ingredients. In a shared kitchen running many cooks back to back, it gets away with that — but the moment it cooks alone, it reaches for ingredients that were never set out and the recipe fails immediately.
    - **Evidence:**
        ```php
        beforeEach(function () {
            setupUsersTable();
            setupSitesTable();
            setupContentTables();
        });
        ```
        Compare `PruneSiteDocumentVersionsTest.php`:
        ```php
        beforeEach(function () {
            setupUsersTable();
            setupSitesTable();
            setupSectionsTables();
            setupContentTables();
        });
        ```

- [ ] **#TEST-10** · P2 — `link_observations_source_check` widening lacks a schema-invariant regression test
    - **Where:** `supabase/migrations/20260819001000_link_observations_allow_commerce_probe.sql`
    - **Affects:** `CommerceProbeJob` observation writes.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a grep-based test under `tests/Schema/` asserting `link_observations_source_check`'s CHECK array includes `'commerce_probe'` alongside the rest of the vocabulary.
    - **Technical:** SQLite cannot exercise a Postgres CHECK constraint, so this class of invariant must be pinned by grepping migration SQL — the house pattern this repo already uses elsewhere (`ArchitectureSystemConstraintsTest`, etc.). The migration's own comment records that this exact failure already happened once: `commerce_probe` was admitted on `routing.source_intents` but not on `routing.link_observations.source`, so writes silently failed the CHECK and were logged as `routing.observation.write_failed` — the observation was simply lost, with no test catching the drift. No such test exists anywhere in the repo for this constraint.
    - **Plain English:** This is a guest list at a door. A new courier was just added to the list. If someone reprints the list later and leaves the courier off, their packages get quietly thrown away with no alarm — this already happened once. There's no framed copy of the list to make a future change deliberate.
    - **Evidence:**
        ```sql
        ALTER TABLE routing.link_observations
            ADD CONSTRAINT link_observations_source_check
            CHECK (source = ANY (ARRAY['paste', 'website_import', 'link_in_bio',
                'bio_harvest', 'google_business', 'staff', 'reproject', 'commerce_probe']));
        ```

- [ ] **#TEST-11** · P2 — `item_media_role_check` widening lacks a schema-invariant regression test
    - **Where:** `supabase/migrations/20260819001100_item_media_role_video.sql`
    - **Affects:** `content.item_media` rows with role `video`; reel playback on sitepages.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a grep-based test under `tests/Schema/` asserting `item_media_role_check`'s allowed set includes `'video'`.
    - **Technical:** Same SQLite-cannot-enforce-CHECK limitation as TEST-10, applied to the new `video` media role this migration admits (feeding `InstagramMediaProjector`/`MediaMirror`/`PoolResolver::frames()`). No grep-based invariant test exists for this constraint anywhere in the repo.
    - **Plain English:** The media shelf just got a new label: "video." If someone rewrites the list of approved labels later and forgets "video," every reel that should play on a sitepage gets rejected at the door with no alert.
    - **Evidence:**
        ```sql
        ALTER TABLE content.item_media
            ADD CONSTRAINT item_media_role_check
            CHECK (role IN ('cover', 'gallery', 'poster', 'avatar', 'logo', 'video'));
        ```

- [ ] **#TEST-12** · P2 — Foreign-item ownership test only asserts `HttpException`, not the mandatory 404
    - **Where:** `tests/Feature/Content/PoolLaneTest.php` (`404s selection writes naming a foreign item`)
    - **Affects:** Anti-enumeration guarantee on pool selection writes.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Convert to a route-level HTTP call and assert `->assertStatus(404)` (also folds into TEST-6's route-level conversion).
    - **Technical:** Per Partna's authorization doctrine, denied-because-not-yours must be 404, never 403 (anti-enumeration, mandatory on public/owned-resource endpoints). `HttpException` is the parent class of both 403 and 404 — this test's `->toThrow(HttpException::class)` would still pass if a regression changed the response to 403.
    - **Plain English:** The test says "this should come back as not-found," but it only checks "some kind of error happened." A response that instead says "forbidden" — which leaks that the item exists — would still pass.
    - **Evidence:**
        ```php
        expect(fn () => app(PoolController::class)->select($request, 'watch', $foreign))
            ->toThrow(HttpException::class);
        ```

## P3 — Nice to have

- [ ] **#TEST-13** · P3 — Fresha `connectStatus()` retains a production branch that a test comment admits only matches a hand-seeded row
    - **Where:** `app/Http/Controllers/Api/Platforms/FreshaController.php` (`connectStatus()`)
    - **Affects:** Test-suite fidelity for the Fresha async connect-status discriminator; no production impact (the branch is documented as dead for real rows).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - The production comment already documents that `($payload['connectMode'] ?? null) === 'team'` is unreachable post-completion (`FreshaConnectFetch`'s success paths always drop `connectMode`); consider trimming the dead disjunct from the discriminator, or leave it as defensive redundancy — either way, note in `FreshaAsyncConnectTest.php`'s "ready row" test that hand-seeding `connectMode` on an `ok` row exercises a state real code never produces.
    - **Technical:** `tests/Feature/Platforms/FreshaAsyncConnectTest.php`'s `poll: ready row...` test hand-seeds `connectMode => 'team'` on a `last_refresh_status: 'ok'` row alongside a populated `teamMenu` array — so it can never prove `is_array($payload['teamMenu'])` alone is the real discriminator, only that the two together produce the right result. This is a test-clarity issue, not a coverage gap: `teamMenu`'s array-ness is otherwise well covered end-to-end via the job's success-path tests.
    - **Plain English:** A safety check in the code has a note saying "only a hand-built test still needs this branch — real cases never do." The test in question is exactly that hand-built case, so it's testing an artificial state rather than anything a real user produces.
    - **Evidence:**
        ```php
        // The
        // `$payload['connectMode'] ?? null` read below only still matches a
        // hand-seeded test row.
        $isTeam = ($payload['connectMode'] ?? null) === 'team' || is_array($payload['teamMenu'] ?? null);
        ```

- [ ] **#TEST-14** · P3 — Hand-edited-rule test never asserts the "reports it" half of its own title
    - **Where:** `tests/Feature/Content/MediaSectionReshapeTest.php` (`leaves a hand-edited rule alone, and reports it`)
    - **Affects:** Operator-visible command output for skipped sections.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `->expectsOutputToContain(...)` for whatever skip message the command actually emits.
    - **Technical:** The test asserts the rule is unchanged and the exit code is 0, but never checks that the command actually reported the skip — the second half of the test's own name is unverified.
    - **Plain English:** The test says a warning gets shown to the operator, but it never actually looks for that warning — only that nothing crashed.
    - **Evidence:**
        ```php
        $this->artisan('content:reshape-pool-sections', ['pool' => 'media'])->assertExitCode(0);

        expect((string) DB::table('site.sections')->where('id', $sectionId)->value('rule'))->toBe($custom);
        ```

- [ ] **#TEST-15** · P3 — Instagram pin test asserts only HTTP 200, not that the pin persisted
    - **Where:** `tests/Feature/Content/PoolBorrowedMediaPinTest.php` (`allows a pin on an instagram media item — owned bytes, stable ref`)
    - **Affects:** Consistency of pin-write assertions within the same test file.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add the same `DB::table('site.section_items')->where('item_id', $itemId)->where('state', 'pinned')->exists()` assertion the neighboring upload-backed and Google-sourced tests in this file already use.
    - **Technical:** The upload-backed and Google-sourced pin tests in this same file assert the DB row actually reaches `state: pinned`; the Instagram case only checks `->assertOk()`, so a no-op endpoint would still pass it.
    - **Plain English:** The test claims Instagram photos can be pinned, but it only checks that the system didn't say "no" — it never checks the pin actually landed.
    - **Evidence:**
        ```php
        actingAsUser($user)
            ->postJson("/api/content/pools/media/selection/{$itemId}")
            ->assertOk();
        ```

- [ ] **#TEST-16** · P3 — `BorrowedMedia`'s refusal branch is untestable while its allowlist is deliberately empty
    - **Where:** `app/Site/Pools/BorrowedMedia.php`; asserted-empty in `tests/Feature/Content/PoolBorrowedMediaPinTest.php` (`still refuses a pin for a source registered as borrowed`)
    - **Affects:** Future borrowed-media sources with churning identity — no current impact, by design.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - When a future source is added to `BORROWED_SOURCE_KEYS`, pair it with a test that seeds a matching `content.source_items`/`ingest.sources` row and asserts `isBorrowed()` (and the controller's refusal) actually fires.
    - **Technical:** `BORROWED_SOURCE_KEYS` is intentionally `[]` since owner ruling R6 (2026-08-18): Google Business photos moved to a stable per-photo key, so nothing needs the refusal today. The existing test only asserts the constant is empty and `isBorrowed()` returns `false` for a Google item — it never exercises the actual query/refusal path, so a future registration could ship a broken `whereIn`/join with no red test to catch it. This is a documented, deliberate seam, not a live gap.
    - **Plain English:** A safety latch exists for a "borrowed photo" problem that doesn't currently apply to anything. The latch itself has never been test-fired because there's nothing to fire it on — when a future case needs it, its first real test will be in production unless a test is added at the same time.
    - **Evidence:**
        ```php
        public const BORROWED_SOURCE_KEYS = [];
        ```
        ```php
        expect(BorrowedMedia::BORROWED_SOURCE_KEYS)->toBe([]);
        expect(BorrowedMedia::isBorrowed(Item::query()->findOrFail($itemId)))->toBeFalse();
        ```

- [ ] **#TEST-17** · P3 — Projector registry sweep checks `kind()` parity but not `version()` for every registered projector
    - **Where:** `tests/Unit/Ingest/ProjectionTest.php` (`maps a projector for every registered connector stream that targets an item kind`)
    - **Affects:** Cache-rebuild invalidation if a future projector ships a missing/invalid `version()`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extend the sweep to assert `$projector::version()` is an int for every `ProjectorRegistry::for()` result, not just the single hand-picked `BandcampReleaseProjector` case.
    - **Technical:** The only `version()` assertion in the file is a single-instance test for one projector; the structural sweep — which is supposed to guarantee every stream is correctly wired — checks `kind()` but not `version()`. A future projector with a bad version would pass this sweep and could silently break the rebuild-invalidation contract.
    - **Plain English:** A gatekeeper test walks every registered "recipe" and checks it's the right type, but not that it carries a valid version stamp. A recipe with a broken stamp would slip through the gate.
    - **Evidence:**
        ```php
        $projector = ProjectorRegistry::for($sourceKey, $streamName);
        expect($projector)->not->toBeNull(...)
            ->and($projector::kind())->toBe($spec->target, ...);
        ```
        ```php
        it('is versioned so a changed projector can trigger a rebuild', function () {
            expect(BandcampReleaseProjector::version())->toBeInt()
                ->and(BandcampReleaseProjector::kind())->toBe('release');
        });
        ```

- [ ] **#TEST-18** · P3 — `content.storefronts` SQLite stand-in leaves `user_id` nullable behind a comment that's factually wrong about SQLite
    - **Where:** `tests/Pest.php` (`setupContentTables()`, `content.storefronts`)
    - **Affects:** Default (SQLite) lane's ability to catch a null-owner bug in shop-store identity; the real constraint is already proven separately.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Correct the comment (SQLite does support partial unique indexes — this same file creates two of them: `site.idx_platform_connections_canonical`, `content.idx_content_item_slugs_one_current`).
        - Tighten `user_id TEXT NOT NULL` in the stand-in — production has enforced this since migration `20260819000100`, and doing so in the fast default lane catches a null-owner bug earlier than waiting for the dedicated Postgres lane.
    - **Technical:** Production denormalised and NOT-NULL'd `content.storefronts.user_id` (migration `20260819000100`) and added a partial unique index on `(user_id, provider, external_ref)` (migration `20260819000110`). The SQLite stand-in leaves `user_id` nullable, and its comment claims SQLite "cannot express the partial unique index that makes it meaningful" — but the same file demonstrably creates partial unique indexes elsewhere. The real constraint is not unprotected: `tests/Postgres/ShopStorefrontUpsertConflictTest.php` exists and covers it in the dedicated Postgres lane, so this is a hardening/consistency fix, not a coverage hole.
    - **Plain English:** A note on a test drawer says "this kind of lock can't be installed here," but the same cabinet has that exact lock on two other drawers. A separate, slower test elsewhere does check the real lock, but the fast everyday test drawer stays unlocked for no real reason.
    - **Evidence:**
        ```php
        -- NULLABLE in this stand-in where production is NOT NULL —
        -- SQLite cannot express the partial unique index that makes it
        -- meaningful either, so the real constraint is pinned in the PG lane
        -- (tests/Postgres/ShopStorefrontUpsertConflictTest.php).
        user_id TEXT NULL
        ```

- [ ] **#TEST-19** · P3 — Five `tests/Feature/Site/*` files declare 13 un-namespaced global helper functions with no collision guard
    - **Where:** `tests/Feature/Site/DocumentBuilderRuleOpsTest.php`, `DocumentBuilderTenantScopingTest.php`, `DocumentBuilderTest.php`, and siblings
    - **Affects:** Suite stability if two files ever declare the same top-level helper name — currently harmless (names are unique today).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a lightweight CI grep check for duplicate top-level `function` names across `tests/`, since this is already a known, hand-documented hazard in the codebase (see `DocumentBuilderTest.php`'s own header comment).
    - **Technical:** Pest loads all test files into one PHP process; a second declaration of the same top-level function name is a hard fatal that kills the whole run before any test executes. `DocumentBuilderTest.php` already carries a hand-written warning about this exact hazard for its own shared helpers, and this repo's engineering memory independently tracks it as a known cross-file gotcha — this finding doesn't surface a new defect, just that nothing enforces the convention beyond a code comment.
    - **Plain English:** All test helpers share one whiteboard with no alarm for someone reusing a name. There's already a sticky note asking people not to — a small automated check would make that unnecessary to remember.
    - **Evidence:**
        ```php
        // buildTestSite()/addPage()/addSection()/addItem() live in tests/Pest.php —
        // shared with DocumentBuilderQueryCountTest.php, which must not redeclare
        // them regardless of which file PHPUnit loads first.
        ```

## Suggested Bundled Sessions

- **Bundle 1 — RoutingController link-in-bio branch coverage:** #TEST-7, #TEST-8
    - **Why grouped:** Same file, same detector-branch pattern, same missing route-level test shape.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Content pool test hardening:** #TEST-6, #TEST-12, #TEST-15, #TEST-16
    - **Why grouped:** Same subsystem (`tests/Feature/Content/Pool*`), same theme (route-level rigor and assertion strength on pool endpoints).
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 3 — Schema-invariant migration tests:** #TEST-10, #TEST-11
    - **Why grouped:** Identical grep-based invariant pattern, both new `tests/Schema/` files guarding recent CHECK widenings.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 4 — DocumentBuilder / rule-DSL coverage:** #TEST-2, #TEST-3, #TEST-9
    - **Why grouped:** Same subsystem (`DocumentBuilder` + rule-ops test files); #TEST-9 must land first since it's a prerequisite fix to the same test file #TEST-2 extends.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 5 — Misc weak-assertion cleanup:** #TEST-4, #TEST-5, #TEST-14, #TEST-17
    - **Why grouped:** Same root pattern — a test's name/comment promises more than its assertions check.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 6 — Low-risk stand-in/comment fixes:** #TEST-1, #TEST-13, #TEST-18, #TEST-19
    - **Why grouped:** Independent, low-effort, no-shared-file items that can be absorbed opportunistically per CLAUDE.md's P3-tail policy.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

None — no P0, auth/money, migration/schema-write, or L/XL-effort items in this audit.
