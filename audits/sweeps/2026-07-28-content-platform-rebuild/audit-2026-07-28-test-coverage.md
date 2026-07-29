# Test Coverage Audit — 2026-07-29

**Branch:** development
**Lens:** Test coverage: critical paths, idempotency, race-safety, policy abilities, mock-vs-integration discipline
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- `app/Catalog`, `app/Content`, `app/Ingest`, `app/Routing`, `app/Site`
- `app/Http/Controllers/Api/{Catalog,Content,Platforms,PublicSite,Routing,Site,Webhooks}`
- `app/Http/Middleware/Auth`, `app/Http/Requests`, `app/Http/Resources`, `app/Jobs`, `app/Policies`
- `app/Services/Platforms`, `database/factories`, `supabase/migrations`
- `tests/Feature/**`, `tests/Unit/**`, `tests/Postgres/**`, `tests/fixtures/Routing`

## Progress

- P1 High: 17 of 17 complete  (many stale or partly stale — see individual entries)
- P2 Medium: 1 of 20 complete
- P3 Low: 0 of 9 complete

---

## P1 — Fix before pilot launch

- [x] **#TEST-1** · P1 — `AccountCapabilities` gate-rejection path is untested for page creation, section rules, and lifestyle pages
    - **STALE ON ITS HEADLINE CLAIM, AND PARTLY FABRICATED (2026-07-29):** the deny-branch tests it asks for already existed at `tests/Feature/Site/PageAndSectionCurationTest.php:54-61` and `:104-113`, added in the SAME commit (`61636698`) as `PageController`/`PageCapabilities`. Its third bullet prescribes tests for `canUseListen()`/`canUseStrava()` — **neither method exists**; the real flag `can_use_lifestyle_pages` was already asserted both ways. Re-graded P1 → P2. The REAL gap, which the audit never named: the update-path gates (`PageController.php:82-84`, `SectionController.php:104-106`) had zero coverage — a live privilege escalation (create an ungated page, then PATCH a gated capability onto it). Closed and mutation-proven.
    - **Where:** `app/Http/Controllers/Api/Site/PageController.php:122-128`, `app/Http/Controllers/Api/Site/SectionController.php:140-149`
    - **Affects:** Every capability-gated page/section type (menu, listen, events, etc.) — a regression that loosens `PageCapabilities::allows()` lets an account create pages it can't actually serve, producing a broken/blank block on their live sitepage.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `it('rejects page creation when the capability is not granted')` and `it('allows page creation when the capability is granted')` in `tests/Feature/Site/`.
        - Add the parallel pair for `SectionController::assertRuleIsPermitted()`.
        - Extend `tests/Feature/Platforms/SectorCapabilityGatingTest.php` (which already exists and covers some lifestyle-page presence) with a direct assertion on `AccountCapabilities::for($user)->canUseListen()`/`canUseStrava()` rather than only the page-presence resolver output, so the gate itself — not just its downstream effect — is pinned.
    - **Technical:** The comment in `PageController::assertCapability()` states enforcement moved to write-time specifically because the old read-time filter was removed — this makes the write-time check the *only* thing standing between a user and a page type their account tier doesn't support. No test in the provided scope exercises the deny branch directly.
    - **Plain English:** Different account tiers get different page types — not everyone gets a Menu page or a Listen page. The system checks this the moment someone tries to create a page. If that check quietly breaks, someone could end up with a page type their account was never meant to have, and it would show up broken on their public site with no page-builder that knows how to fill it in.
    - **Evidence:**
        ```php
        private function assertCapability(User $user, ?string $capability): void
        {
            if (! PageCapabilities::allows($user, $capability)) {
                throw ValidationException::withMessages([
                    'capability' => 'Your account does not have that page type.',
                ]);
            }
        }
        ```

- [x] **#TEST-2** · P1 — `RoutingController::store()` has 5+ distinct outcome branches with no per-branch test coverage
    - **PARTLY STALE (2026-07-29):** four of the six outcomes named were already covered in `tests/Feature/Routing/RoutingEndpointTest.php` (connected, review/choose, link_cap_reached, note→link). Only `busy` (423) and `unavailable` (503) were missing; both added against REAL objects. The audit's suggestion to mock `CustomLinkSeeder::addManual()` was rejected — it would have deleted the only thing under test.
    - **Where:** `app/Http/Controllers/Api/Routing/RoutingController.php:36-77`
    - **Affects:** The primary link-adding flow for every user pasting a URL into the dashboard — the newest, least-proven write path in the platform.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Add `it()` blocks in `tests/Feature/Routing/RoutingEndpointTest.php` (or a new file) for each outcome: `connected`, `review` (choose/hold), `link_cap_reached`, `busy` (423), `unavailable` (503), and successful `note`→`link` write.
        - Mock `LinkRoutingService::route()` and `CustomLinkSeeder::addManual()` return values to force each branch deterministically.
    - **Technical:** The outcome is computed via nested `match`/`if` on `$result['verdict']` and `$write['status']`, with five sub-branches inside the `note` path alone. `tests/Feature/Routing/RoutingEndpointTest.php` exists and covers idempotency, but no evidence any test forces the `cap_full`/`busy`/`unavailable` sub-branches specifically.
    - **Plain English:** When a user pastes a link, the system can respond about eight different ways — "connected," "needs your confirmation," "you're at your link limit," "busy, try again," etc. Each is a different conversation with the user. Untested branches are untested conversations — a bug in any one of them ships silently.
    - **Evidence:**
        ```php
        if ($result['verdict'] === 'note') {
            $write = $this->links->addManual($user, $result['canonicalUrl'] ?? $url);
            if ($write['status'] === 'cap_full') { /* ... */ }
            if ($write['status'] === 'busy') { /* ... */ }
            if ($write['status'] === 'unavailable') { /* ... */ }
            if ($write['row'] !== null) { $outcome = 'link'; }
        }
        ```

- [x] **#TEST-3** · P1 — `SourceProvisioner::sync()` and its 22-platform `identifierFor()` dispatch have no visible test coverage
    - **LARGELY STALE (2026-07-29):** `tests/Feature/Ingest/SourceProvisionerTest.php` already existed (landed `f6e63942`, pre-dating the audit) covering ALL 21 `identifierFor()` arms and 6 of 7 `sync()` branches. Re-graded P1/L → P2/S. Residue added: the identifier-unchanged update branch, `sync()`'s 7-state return contract, and 8 malformed/spoof negatives. Verifying it also found a production defect the audit never named — `freshaSlug()` was the only UNANCHORED URL extractor in the class, so `https://evil.example/?next=fresha.com/a/rival-salon` matched. Anchored, keeping an optional locale group so legacy `/en-au/a/` rows still provision.
    - **Where:** `app/Ingest/SourceProvisioner.php:45-102` (sync), `:164-221` (identifierFor)
    - **Affects:** Every platform connector's ability to enter the ingest scheduling pipeline — this is the sole seam between `IntegrationConnection` and `ingest.sources`.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Add `tests/Feature/Ingest/SourceProvisionerTest.php` covering: create, skip (resource_kind row), skip (no identifier), retire (trashed), deactivate (inactive), update-identifier-resets-schedule, update-identifier-unchanged-preserves-schedule.
        - Add a data-provider test over `identifierFor()`'s 22 platform arms, at minimum one valid case per platform, with malformed-input cases for youtube/spotify/instagram/twitch.
    - **Technical:** `sync()` has five early-return branches and an explicit design constraint (documented in-line) to never clobber scheduler state on an identifier-unchanged update. `identifierFor()` dispatches through 15+ private regex-based extractors — a single regex typo silently `null`s out one platform's provisioning permanently.
    - **Plain English:** This is the front door every platform connection walks through to start being polled for content. It has a unique "address format" decoder for each of 22 platforms. If any one decoder is subtly wrong, that platform's connections just never get scheduled — no error, no crash, just silence.
    - **Evidence:**
        ```php
        if ($existing === null) {
            DB::table('ingest.sources')->insert([...]);
            return ['status' => 'created', 'source_key' => $sourceKey];
        }
        // Update ONLY identity + activation — scheduling state belongs to the scheduler
        ```

- [x] **#TEST-4** · P1 — Deferred-connect self-deadlock prevention (lock released before job dispatch) is untested in two controllers
    - **HOLDS — and the audit's prescribed assertion was WRONG (2026-07-29):** it suggests `Queue::fake()` + `assertPushed` to prove the job dispatches outside the lock, but under a faked queue the job never contends for the lock, so it passes identically whether the dispatch is inside or outside the closure. Two such assertions already existed in `DeferredConnectTest` — which is why this looked covered while being unproven. Replaced with a `Queue::before()` probe that itself takes the same lock key; review confirmed the probe genuinely fires and that both controllers go red when the dispatch moves back inside.
    - **Where:** `app/Http/Controllers/Api/Platforms/GenericPlatformController.php` (`connectDeferred`), `app/Http/Controllers/Api/Platforms/InstagramController.php` (`connect`)
    - **Affects:** Every deferred-connect platform (Bandcamp, Spotify, YouTube Music, SoundCloud, Apple, Gumroad, TikTok, Instagram) — a regression that moves the job dispatch back inside the lock closure hangs every concurrent connect request under the test suite's default sync queue driver, and stacks real users behind a ~110s scrape in production.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a test asserting `ConnectFetchJob`/`InstagramConnectJob` is dispatched via `Queue::fake()` and that the dispatch happens only after the lock closure's return value is available (i.e., the row exists and is committed before dispatch).
        - Add a companion 423 (`LockTimeoutException`) test for both controllers.
    - **Technical:** Both controllers carry explicit, matching in-line warnings that dispatching inside the lock self-deadlocks under a sync queue connection — this is exactly `phpunit.xml`'s test-suite default, meaning a regression here would likely hang the test suite itself, not just production.
    - **Plain English:** Imagine handing off a package while still blocking the loading-dock door. Both of these connect flows deliberately step out of the doorway before handing off the background job — but nothing proves that ordering stays correct if someone "cleans up" the code later.
    - **Evidence:**
        ```php
        // ConnectFetchJob::dispatch()->afterCommit() is deliberately called AFTER
        // the lock closure returns (lock released): the job blocks on the
        // identical platform+user lock key, so dispatching it from inside would
        // self-deadlock under a sync queue connection (phpunit.xml's test default).
        ```

- [x] **#TEST-5** · P1 — `RunSourceJob`'s claim-release safety net (`finally` block + `failed()` backstop) is untested
    - **Where:** `app/Jobs/Ingest/RunSourceJob.php:88-101` (finally), `:103-129` (failed)
    - **Affects:** Every scheduled ingest run — a broken release path strands a source's `in_flight_since` claim, blocking it from being re-fetched until the 2-hour backstop.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `it('releases the claim in finally when handle throws')`.
        - Add `it('failed handler releases only when still claimed (finally did not run)')` and `it('failed handler is a no-op when finally already released')` — proving the `$stillClaimed` DB guard prevents double-counting `consecutive_failures`.
    - **Technical:** The job's own in-line comment explains a genuine race: `failed()` runs on a re-serialized copy of the job *after* the worker catches the exception, by which point the original instance's `finally` may already have released the claim. Releasing unconditionally in both places would double-count the failure and corrupt the backoff counter. No test exercises either path.
    - **Plain English:** This job marks a source "in progress" before working on it. Two safety nets are supposed to make sure that mark always gets cleared, even on a crash — but the two nets have to coordinate so they don't both fire and double-punish a source for one real failure. Neither net has ever actually been tested.
    - **Evidence:**
        ```php
        $stillClaimed = DB::table('ingest.sources')
            ->where('id', $this->sourceId)->whereNotNull('in_flight_since')->exists();
        if ($stillClaimed) {
            app(SourceScheduler::class)->release($this->sourceId, 'error', false);
        }
        ```

- [x] **#TEST-6** · P1 — `SourceScheduler::claimDue()` mutual-exclusion is proven only sequentially, never under concurrent claimers
    - **Where:** `app/Ingest/Runtime/SourceScheduler.php:114-128`; existing test `tests/Feature/Ingest/SourceSchedulerTest.php` only calls `claimDue()` twice on the *same* instance
    - **Affects:** Every scheduled ingest run — if two Horizon workers can both claim the same source, it gets fetched (and potentially billed) twice concurrently.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a test that seeds one due source and calls `claimDue()` from two independent `SourceScheduler` instances (or forces DB-level contention), asserting only one wins.
    - **Technical:** The existing sequential test proves in-memory state coherence on one instance, not that the underlying `UPDATE ... WHERE in_flight_since IS NULL` is genuinely atomic against two real, independent processes racing on the same row.
    - **Plain English:** We've proven the same bouncer won't let the same guest in twice. We haven't proven two different bouncers working the same door at the same instant can't both wave the guest through.
    - **Evidence:**
        ```php
        $won = DB::table('ingest.sources')->where('id', $candidate->id)
            ->whereNull('in_flight_since')->update(['in_flight_since' => now(), ...]);
        if ($won === 1) { $claimed[] = ...; }
        ```

- [x] **#TEST-7** · P1 — `EffectLedger::once()` charge-once claim has no concurrent-dispatch test
    - **PARTIALLY STALE (2026-07-29):** the abandoned-claim test the finding asks for already existed at `tests/Feature/Ingest/EffectLedgerTest.php:138-160`. Only the concurrent-dispatch half was genuinely missing; added as a deterministic proof in `tests/Postgres/EffectLedgerConcurrencyTest.php` (a committed rival INSERT is injected via `DB::listen` after the caller's pre-read, so the duplicate-key collision is forced every run rather than hoped for). Mutation evidence: `insert()` → `insertOrIgnore()` yields 6 charges instead of 1.
    - **Where:** `app/Ingest/Runtime/EffectLedger.php` (`once()`)
    - **Affects:** All billed ingest effects (Apify actor runs, Places API calls) — the pilot's uncapped-paid-API surface. A race that double-claims double-charges.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a test dispatching two `once()` calls with the same digest concurrently (or via forced DB contention) and assert exactly one settles `ok` while the other reads back the winner's result via the insert-then-catch path.
        - Add a test for an abandoned `claimed` row past `ABANDON_AFTER_SECONDS`.
    - **Technical:** The insert-then-catch-duplicate-key pattern is correct in Postgres in principle, but is exactly the class of concurrency logic that looks right sequentially and fails under real contention. No test forces the race.
    - **Plain English:** Two workers trying to bill the same paid API call at the same instant should produce exactly one charge. The code is written to guarantee that, but nobody has actually made two workers collide to prove it.
    - **Evidence:**
        ```php
        try {
            DB::table('ingest.effects')->insert(['digest' => $digest, ..., 'status' => 'claimed']);
        } catch (\Throwable) {
            $row = DB::table('ingest.effects')->where('digest', $digest)->first();
            return $row === null ? ['status' => 'refused', ...] : $this->verdictFor($row);
        }
        ```

- [x] **#TEST-8** · P1 — `ProbeBudget::tryClaim()`'s check-then-increment is not atomic despite a docblock claiming it is
    - **PREMISE REFUTED (2026-07-29):** `tryClaim()` is increment-then-check-then-rollback, not check-then-increment. `INCRBY` returns a distinct value per concurrent caller and a claim succeeds only within its own returned value, so over-admission is provably impossible; the audit's suggested `rememberLocked` remedy was rejected as serialising every claim to fix a non-existent bug. A REAL defect in the same lines was fixed instead: `Cache::add()` + `Cache::increment()` are two round trips, and an expiry landing between them leaves a TTL-less key — permanent, inevictable ballast under instance-wide `volatile-lru`, one per user per day. Now a single TTL-asserting EVAL. `ApifyBudget` carries the identical defect and is deliberately deferred to its own unit (paid-spend path).
    - **Where:** `app/Routing/Probes/ProbeBudget.php:47-75`
    - **Affects:** Global and per-user probe budget enforcement — this is a real correctness bug uncovered by reading the code, not just a coverage gap.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a concurrency test dispatching two probes simultaneously and asserting the global counter never exceeds the cap.
        - If it fails (expected), replace the `Cache::add` + `Cache::increment` + separate compare with `CacheLockService::rememberLocked` — the codebase's own gold-standard primitive for exactly this pattern — around the check-and-increment.
    - **Technical:** `Cache::increment()` is atomic on its own, but the subsequent `if ($global > $globalCap)` check is a separate step — two workers can both increment past the cap before either observes the breach. The class docblock's claim of atomicity ("Claims are atomic... never get+put") is incorrect for this exact pattern.
    - **Plain English:** The budget check is like checking your bank balance, then spending, then checking it was actually still positive — but done in that order for two purchases at once, both purchases can go through even though the account can only afford one. The code that's supposed to prevent this has a gap.
    - **Evidence:**
        ```php
        Cache::add($globalKey, 0, $expiry);
        $global = Cache::increment($globalKey);
        if ($global > $globalCap) {
            Cache::decrement($globalKey);
            return false;
        }
        ```

- [x] **#TEST-9** · P1 — `Lander`'s 40% deletion guard (the anti-mass-deletion circuit breaker) has no test in either direction
    - **LARGELY STALE (2026-07-29):** the finding claims no test in EITHER direction; both were already covered at `tests/Feature/Ingest/LanderTest.php:294-380`, along with two `clearGuardIfRecovered()` tests. The genuine residue was BOUNDARY coverage: the pre-existing cases sit at 50% and 30%, so moving the 0.4 threshold, changing `>=` to `>`, or deleting the `count >= 5` clause all stayed green. New cases sit immediately either side of both clauses; independent review confirmed all three mutations now go red while the pre-existing cases stay green under every one of them.
    - **Where:** `app/Ingest/Landing/Lander.php:128-145`
    - **Affects:** Every stream with `mayDelete() === true` — a vendor outage returning zero records for a user with many live items would trip (or fail to trip) this guard with no test proving the threshold math is right.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a test at just-under-threshold (e.g. 39% absent) asserting normal tombstoning proceeds.
        - Add a test at just-over-threshold (≥40% and ≥5 absent) asserting the guard trips, zero tombstones, and an anomaly row is written.
        - Add a `clearGuardIfRecovered()` test.
    - **Technical:** The guard is `count($dominatedAbsent) / $liveCount >= 0.4 && count($dominatedAbsent) >= 5`. An inequality flipped either direction is catastrophic in opposite ways: false-trip permanently freezes legitimate deletion, false-pass bulk-deletes a user's content on a routine vendor hiccup.
    - **Plain English:** This is the circuit breaker that stops a bad vendor response from wiping out a large chunk of someone's content in one pass — it should trip and ask a human before that happens. Nobody has tested that the trip wire is set at the right sensitivity.
    - **Evidence:**
        ```php
        if ($liveCount > 0 && (count($dominatedAbsent) / $liveCount) >= self::GUARD_THRESHOLD && count($dominatedAbsent) >= 5) {
            DB::table('ingest.streams')->where('id', $streamId)->update(['guard_tripped_at' => now(), ...]);
            return ['tombstoned' => 0, 'guard_tripped' => true];
        }
        ```

- [x] **#TEST-10** · P1 — `RunExecutor::isClaimed()` — the PII-redaction gate for unclaimed accounts — has no test
    - **PREMISE CORRECTION (2026-07-29):** the finding's 'null-status users' case is unseedable — `core.users.status` is NOT NULL in the prod DDL (`baseline_pilot.sql:1171`) and in the SQLite mirror, so the reachable fail-closed cases are a missing user row and a missing `user_id`. Also fixed a latent fail-open the finding did not name: `Pull::$isClaimed` defaulted to TRUE on a PII gate. Review found the non-`active` half of the status enum unpinned; `suspended`/`disabled`/`pending_deletion` now covered.
    - **Where:** `app/Ingest/Runtime/RunExecutor.php:340-348`
    - **Affects:** Every Google Business listing ingested for a `status='unclaimed'` account — this gate decides whether reviewer names/photos are stripped before storage, directly implementing the CLAUDE.md hard rule that "capability/notification/deletion gates fail-closed" for unclaimed users.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('treats unclaimed and null-status users as unclaimed')` and `it('treats active users as claimed')`, asserting the correct `redactionsFor()` branch fires.
    - **Technical:** A regression that inverts this boolean (e.g. via a careless De Morgan refactor) would store third-party PII the platform has no consent to hold, for every pre-account Google Business build — a real, current pipeline per the pre-account signup doctrine.
    - **Plain English:** For businesses that haven't claimed their Partna account yet, we're not allowed to store the names and photos of people who left them Google reviews — we don't have anyone's permission. This one function is the gate that strips that data out. It has never been tested.
    - **Evidence:**
        ```php
        private function isClaimed(array $source): bool
        {
            $userId = $source['user_id'] ?? null;
            if ($userId === null) { return false; }
            $status = DB::table('core.users')->where('id', $userId)->value('status');
            return $status !== null && $status !== 'unclaimed';
        }
        ```

- [x] **#TEST-11** · P1 — `field_bindings_manual_priority` CHECK constraint — the single-point-of-failure for "manual always wins" — has no invariant test
    - **HOLDS; two audit instructions were wrong (2026-07-29):** SQLite DOES enforce CHECK constraints (`tests/Pest.php` already mirrors this one), and the migration-text exemplar is `ConstraintVocabularyLockstepTest.php`, not `WriteDesignKitTest.php`. Enforcement proven in `tests/Postgres/FieldBindingsManualPriorityTest.php` against real Postgres — the decisive case (`google_business` at priority 0) is one a grep-based test could never catch. NOTE: the `CheckConstraintsTest` entry added alongside runs in NO lane (see its warning comment) — a pre-existing hazard affecting all 22 entries there, filed separately.
    - **Where:** `supabase/migrations/20260728150000_field_bindings.sql:29-32`
    - **Affects:** Every workplace-identity field resolution for every user — if this constraint silently drops (migration typo, future ALTER), a non-manual source could claim priority 0 and permanently overwrite user-typed fields.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a grep-based invariant test (SQLite can't exercise Postgres CHECK constraints) asserting the exact clause text `(("source_key" = 'manual' AND "priority" = 0) OR ("source_key" <> 'manual' AND "priority" > 0))` exists in the migration file, following the existing `WriteDesignKitTest.php` exemplar pattern.
    - **Technical:** The migration's own comment calls this "the invariant the resolver's 'manual always wins' rests on, held by the schema" — meaning the application code trusts the database to enforce it and does not re-check it independently.
    - **Plain English:** This is the database rule guaranteeing a user's own hand-typed edits can never be silently overwritten by an automated import. If that rule is ever accidentally dropped in a future migration, nothing else in the code would catch it.
    - **Evidence:**
        ```sql
        CONSTRAINT "field_bindings_manual_priority" CHECK (
            ("source_key" = 'manual' AND "priority" = 0)
            OR ("source_key" <> 'manual' AND "priority" > 0)
        )
        ```

- [x] **#TEST-12** · P1 — Host-spoofing TLD allowlists (Eventbrite, OpenTable, Google Business) have no negative test locking the closed suffix set shut
    - **PARTLY STALE, AND IT HID A REAL HOLE (2026-07-29):** OpenTable and Eventbrite were already pinned both directions by `tests/Unit/Security/HostSpoofingHotfixTest.php`. Google had zero coverage AND no closed set — its gate was the OPEN family `com(\.[a-z]{2})?|co\.[a-z]{2}|[a-z]{2}`, admitting ~2,029 registrable suffixes of which Google owns a few hundred (`google.tk`, `google.cm`, `google.com.zz` all passed). Pinning that would have certified the hole, so the gate was narrowed to a verified 48-entry enumeration first. Review caught a fabricated `pe` in the first cut (`google.pe` does not exist; Peru is `google.com.pe`) — the fix both admitted a fake host and rejected the real one.
    - **Where:** `app/Services/Platforms/EventbriteScraper.php:28`, `app/Services/Platforms/OpenTableService.php:21`, `app/Services/Platforms/GoogleBusinessService.php:89-93`
    - **Affects:** Any auto-connect or paste flow accepting one of these URLs — all three files' own in-line comments confirm each was previously vulnerable to host spoofing (`eventbrite.<attacker-domain>`, `opentable.<attacker-domain>`, `google.<attacker-domain>`) and each fix is a closed TLD/host regex with no regression test.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - For each service, add a negative test asserting a spoofed host (e.g. `eventbrite.evil.com`) is rejected, and a positive data-provider test covering every TLD in the closed set.
    - **Technical:** All three fixes are recent and each carries an explicit code comment documenting the exact prior vulnerability, which strongly indicates these were live findings from a previous review — exactly the kind of fix that silently regresses back to an open regex without a locking test.
    - **Plain English:** All three of these had a real bug where a lookalike domain (like `opentable.attacker.com`) would be accepted as the real thing, pointing a "Reserve" or "Book" button on someone's public page at an attacker's site. All three were fixed. None of the fixes have a test proving the fix holds.
    - **Evidence:**
        ```php
        // OpenTableService.php — "a rid/embed sourced from a spoofed host points
        // the reserve button at an attacker-chosen widget, so the suffix set
        // must stay closed."
        private const TLDS = '(?:com|com\.au|com\.mx|co\.uk|co\.th|ca|de|jp|ie|sg|hk|ae|it|es|nl|at)';
        ```

- [x] **#TEST-13** · P1 — `PublicSuffixList` (the registrable-domain algorithm underpinning all routing security) has no unit tests
    - **REFUTED (2026-07-29):** `tests/Unit/Routing/PublicSuffixListTest.php` was added in `710db104`, the SAME commit as the class, and is an ancestor of the audit commit `57be57d1` — the coverage was never absent. Algorithm hand-traced against the publicsuffix.org spec (exception > wildcard > normal precedence, the exception leftmost-label drop, the suffix+1 slice): CORRECT, no production defect. Third instance of this shape after #TEST-16 and #TEST-1 — the coverage lens does not reach `tests/Unit/Routing/`. Added a PSL data-file regression test instead.
    - **Where:** `app/Routing/PublicSuffixList.php` (entire class)
    - **Affects:** Every URL the link canonicaliser processes — this is the exact mechanism deciding that `opentable.evil.com` belongs to `evil.com`, not `opentable.com`.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `tests/Unit/Routing/PublicSuffixListTest.php` covering: normal rule, exception rule (`!www.ck` overriding `*.ck`), wildcard rule, and unknown-TLD fallback.
    - **Technical:** This is a pure function implementing a three-rule-type PSL algorithm (normal/exception/wildcard) with no I/O — the cheapest class in the router to test exhaustively and, per its role feeding every detector's `registrableKey`, one of the highest-impact if wrong.
    - **Plain English:** This is the math that tells the system where one domain ends and a subdomain begins — the foundation every other spoofing check in the routing system is built on. It has zero tests despite being pure, deterministic logic that takes minutes to test properly.
    - **Evidence:**
        ```php
        for ($i = $count - 1; $i >= 0; $i--) {
            $candidate = implode('.', array_slice($labels, $i));
            if (isset($this->exceptions[$candidate])) { return implode('.', array_slice($labels, $i + 1)); }
            if (isset($this->rules[$candidate])) { $best = $candidate; continue; }
        ```

- [x] **#TEST-14** · P1 — The content identity engine (`Resolver` + `DisjointSet`) has zero unit tests despite a documented historical bug in the exact code path
    - **LARGELY STALE, BUT VERIFYING IT FOUND A REAL BUG (2026-07-29):** `tests/Unit/Content/ResolverTest.php` (17 blocks) and `tests/Feature/Content/IdentityQueueTest.php:215` already covered ~85%, including the C8 invariant and the `separate()` both-argument-orders regression. Re-graded P1/L → P2/S. The value here is the DEFECT: `separate()` recorded a pair before its unknown-element guard and `isSeparated()`'s `find()` auto-vivified it, so a decision naming a deleted coord resurrected it as a phantom singleton group — downstream minting an EMPTY content item for a coord that does not exist. Reachable because decisions have no FK to coords. Fixed and pinned. A second defect (order-dependent cut tie-break, contradicting the class docblock) is filed NOT fixed — pinning it would bless an accident.
    - **Where:** `app/Content/Identity/Resolver.php` (entire class), `app/Content/Identity/DisjointSet.php` (entire class)
    - **Affects:** Every identity merge decision platform-wide — wrong groupings cascade into wrong catalog items and wrong sitepage content for every user.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Add `tests/Unit/Content/DisjointSetTest.php` including the exact regression case the in-line comment describes: separating an element that IS the group root must not silently lose the cut.
        - Add `tests/Unit/Content/ResolverTest.php` covering the 5-step ordered pipeline, especially that a user's "different" cut (step 3) survives the corroborating-key union pass (step 4) — the core architectural invariant (per doctrine's C8: user rulings outrank automated matches).
    - **Technical:** `DisjointSet::separate()` is a non-textbook extension to union-find, and its own in-line comment describes a *previous real bug* where always re-rooting one argument silently lost user "different" rulings depending on argument order. `Resolver::resolve()`'s step ordering is the platform's entire "does a user's ruling stick" guarantee. Neither file has any test evidence in the audited scope.
    - **Plain English:** This is the engine that decides which pieces of scraped content are "the same thing" across platforms. It has a documented history of a bug where the system would silently ignore a user's explicit "these are NOT the same" decision, depending on the order things happened to be compared in. That exact bug class has zero regression protection today.
    - **Evidence:**
        ```php
        // Re-root whichever argument is NOT the group root. Re-rooting the
        // root is a no-op, and always re-rooting $b (the old bug) silently
        // lost the cut whenever $b's side had won the union
        $detach = $this->find($b) === $b ? $a : $b;
        $this->parent[$detach] = $detach;
        ```

- [x] **#TEST-15** · P1 — Policy ability coverage: only 1 of 14 Policy classes has a dedicated test file
    - **PREMISE DOES NOT HOLD (2026-07-29):** its evidence was an `ls` of `tests/Feature/Policies/`; policy tests actually live in `tests/Unit/Policies/` (10), `tests/Feature/Security/PolicyEnforcement/` (21) and `TenantIsolation/` (10) — off by ~40 files. All three named priorities are individually stale, and `ContentItemPolicy::curate()`'s 403 branch is unreachable by construction. Re-graded P1 → P3. The one real gap: `SectionPolicy::ownerMatches()`'s `site_id` cross-check and its `DesignKitRestylePolicy` twin — the documented setRelation-spoofing guard — had never been touched by any test. Closed.
    - **Where:** `app/Policies/` (14 classes: BasePolicy, CasePolicy, CustomerPolicy, DecisionPolicy, EnquiryPolicy, FeatureFlagPolicy, FeedbackPolicy, GdprPolicy, IntegrationConnectionPolicy, NotificationPolicy, PartnaStaffPolicy, ServicePolicy, SitePolicy, UserSelfPolicy) plus newer additions (ContentItemPolicy, SectionPolicy, DesignKitRestylePolicy) not listed in the lens but confirmed present in the codebase
    - **Affects:** Every authenticated CRUD endpoint. `Glob` of `tests/Feature/Policies/` confirms only `EnquiryPolicyTest.php` exists — the platform's entire authorization surface, outside of one policy, has no per-method allowed/denied test.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Add one test file per Policy class with at minimum an `allowed` (owner) and `denied` (non-owner → 404, per doctrine) assertion for every public method.
        - Priority order given available evidence: `IntegrationConnectionPolicy` (the `connect` ability takes a two-argument `[Model, PlatformDescriptor]` shape — non-standard and worth pinning), `ContentItemPolicy::curate()` (has a capability-denied 403 branch distinct from the 404 not-owner branch — currently only indirectly covered by an HTTP isolation test), `SectionPolicy`/`DesignKitRestylePolicy` (see #TEST-16 below for a real bug found in these two).
        - `PolicyCoverageTest.php` only proves a policy is *registered* — it does not prove the policy's methods return correct decisions. Both are needed.
    - **Technical:** This is a systemic, confirmed gap (verified via `Glob tests/Feature/Policies/*.php`), not a speculative one. Per the "same root cause, same tier" rule, every untested policy method carries the same risk class and the same tier.
    - **Plain English:** Every resource in the app is supposed to have a lock, and there's an inventory check confirming every lock exists. But almost none of the locks have actually been tested with a real key and a wrong key. We know the locks are installed; we don't know they work.
    - **Evidence:**
        ```
        $ ls tests/Feature/Policies/
        EnquiryPolicyTest.php
        # 13+ other Policy classes with no corresponding test file
        ```

- [x] **#TEST-16** · STALE (2026-07-29, unit-12 review) — The document-build pipeline (`BuildState` CAS protocol + `DocumentBuilder`'s hash-idempotency and 7-operator rule DSL) has no visible unit or feature test

    **Stale — the safety net already exists.** `tests/Feature/Site/DocumentBuilderTest.php` (build-status/version, hash-idempotency, CAS refusal, concurrent-bump counting, nav-is-pages, `on_empty` hide, exclusions, pin ordering, hand-picked-is-exactly-pins, `limit_n`, `removed_at`), `tests/Feature/Site/DocumentBuilderRuleOpsTest.php` (the 7-operator DSL), `tests/Feature/Site/SiteBuildDocumentsCommandTest.php` (all three command modes + the job), and `tests/Feature/Site/PresetInstantiatorTest.php:167` (end-to-end through the builder) all landed in `c721afc8` (2026-07-28), the same day this audit ran against an older tree. No characterisation-test-first step was needed for unit-12's SCALE-9 fix.

    - **Where:** `app/Site/Documents/BuildState.php` (entire class), `app/Site/Documents/DocumentBuilder.php` (entire class, esp. `applyPredicate()`)
    - **Affects:** Every published sitepage document — this is the producer side of the platform's hottest read path (public sitepage resolution feeds directly from `site.site_documents`).
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Add `tests/Feature/Site/BuildStateTest.php`: `read()` creates a zero-row on first access (without which the first build of every new site reports "superseded" forever), `commit()` succeeds/fails correctly under the CAS, `isStale()` correctness.
        - Add `tests/Feature/Site/DocumentBuilderTest.php`: hash-match short-circuits without writing a new version; each of the 7 rule operators (`kind_is`, `has_facet`, `from_source`, `in_collection`, `tagged_with`, `published_within`, `has_action`) both positive and negated; the off-map-facet `whereRaw('1 = 0')` guard, including its behavior when negated (`NOT (1=0)` = matches everything — a correctness-critical interaction the code doesn't visibly guard against).
    - **Technical:** `BuildState::commit()` is a compare-and-set primitive with two documented race conditions in its own comments (concurrent bumps, first-build zero-row). `DocumentBuilder::applyPredicate()` is the sole translator from a user-facing rule DSL into the SQL that populates every section of every sitepage — a single wrong operator silently blanks or misfills a section platform-wide.
    - **Plain English:** This is the factory floor that assembles the actual page every visitor sees. It has a "did anything really change?" check to avoid unnecessary republishing, and a seven-verb mini-language ("show me things tagged X") that turns a user's rule into a database query. Neither has ever been run through a test.
    - **Evidence:**
        ```php
        if ($tables === []) {
            // Every named facet is off-map: an impossible ask matches
            // nothing — an empty OR-group would have matched everything.
            $q->whereRaw('1 = 0');
            return;
        }
        ```

- [x] **#TEST-17** · P1 — `ConnectionPayload::forWrite()` has no test guarding the exact contract whose absence already caused a real production incident
    - **STALE (2026-07-29):** `tests/Unit/Routing/ConnectionPayloadTest.php` already covered url/source/handle/composite/opaque. Exactly one conjunct was unpinned (`$identifier !== ''`); one XS test added. Re-graded P1/S → P3/XS.
    - **Where:** `app/Routing/ConnectionPayload.php` (entire class)
    - **Affects:** Every sitepage render for a handle-identity platform connection — the class's own docblock states a missing `username` key "survives every backend test and then renders an empty page," and that this already happened across 20 showcase accounts.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('includes username for handle identifiers')`, `it('omits username for opaque or composite identifiers')`, and `it('always includes url and source')` — pinning all three branches of the conditional.
    - **Technical:** This class exists specifically to stop a third writer from drifting into the exact bug it was built to fix, yet has zero test coverage itself — the single strongest, most concretely-evidenced finding in this audit: a documented incident with no regression test written against the fix.
    - **Plain English:** Twenty real accounts once showed blank cards on their public page because a piece of data was silently missing. The fix for that bug has never been tested — meaning the same class of bug could ship again, silently, the next time someone touches this code.
    - **Evidence:**
        ```php
        public static function forWrite(string $canonicalUrl, string $identifier, string $identifierKind, string $origin): array
        {
            $payload = ['url' => $canonicalUrl, 'source' => $origin];
            if ($identifierKind === 'handle' && $identifier !== '' && ! str_contains($identifier, '/')) {
                $payload['username'] = $identifier;
            }
            return $payload;
        }
        ```

## P2 — Should fix

- [x] **#TEST-18** · P2 — `SectionPolicy::create()` and `DesignKitRestylePolicy::create()` return bare `false` (→ 403) on owner mismatch instead of `denyAsNotFound()` (→ 404), breaking the anti-enumeration contract — and has no test
    - **Where:** `app/Policies/SectionPolicy.php:28-32`, `app/Policies/DesignKitRestylePolicy.php:26-30`
    - **Affects:** Any actor probing whether a site exists by comparing 403 (exists, not yours) vs 404 (doesn't exist) responses on the page/section/restyle-create endpoints.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Fix: change `return $this->ownerMatches($actor, $skeleton);` to `return $this->ownerMatches($actor, $skeleton) ? true : $this->denyAsNotFound();` in both classes, matching the pattern already used by `update()`/`delete()` in the same files.
        - Add a test asserting the non-owner-create path returns 404.
    - **Technical:** This is a genuine code defect surfaced by test-coverage review, not just a gap — `view()`/`update()`/`delete()` in both classes correctly wrap `ownerMatches()` with `denyAsNotFound()`, but `create()` returns the raw boolean, which Laravel's Gate translates to 403. Per doctrine, 404-on-not-yours is mandatory anti-enumeration policy.
    - **Plain English:** Every door in this part of the house says "pretend this room doesn't exist" if it's not yours — except the "create a new room here" doors, which say "this room exists, you just can't enter." That inconsistency lets someone map out which sites are real by testing the create endpoint.
    - **Evidence:**
        ```php
        // SectionPolicy.php — create() returns bare false, unlike update()/delete():
        return $this->ownerMatches($actor, $skeleton);
        // update() in the same file, for comparison:
        return $this->ownerMatches($actor, $resource) ? true : $this->denyAsNotFound();
        ```

- [ ] **#TEST-19** · P2 — `ContentItemPolicy::curate()`'s capability-denied 403 branch has no direct test
    - **Where:** `app/Policies/ContentItemPolicy.php:46-50`
    - **Affects:** The identity-curation gate — a regression that drops the `AccountCapabilities::for($actor)->can_curate_identity` check would silently grant curation to every owner, and the only visible coverage (`SectionsAndContentIsolationTest.php`) exercises the non-owner 404 path, not the owner-without-capability 403 path.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('denies curation when the account lacks can_curate_identity')` seeding an owned item and a capability-denied user, asserting 403.
    - **Evidence:**
        ```php
        return AccountCapabilities::for($actor)->can_curate_identity
            ? true
            : Response::deny('Identity curation is not available on this account.');
        ```

- [ ] **#TEST-20** · P2 — Advisory-lock code paths have no concurrency test despite a dedicated SQLite shim existing for exactly that purpose
    - **Where:** `tests/Pest.php` (`shimPgAdvisoryLockForSqlite()`); no matching test in `tests/Feature/Concurrency/`
    - **Affects:** All reorder/upsert operations serialized per-site via `pg_advisory_xact_lock`.
    - **Effort:** M (~2–4h)
    - **What to do:** Add a concurrency test asserting two racing writers against the same lock key produce a serialized, correct end-state — not just that the shim exists and no-ops cleanly under SQLite.
    - **Evidence:**
        ```php
        $pdo->sqliteCreateFunction('pg_advisory_xact_lock', fn ($value) => null, 1);
        ```

- [ ] **#TEST-21** · P2 — `PublicIntegrationConnectionResource::filterPayload()` — the canonical PII-safety boundary for the public sitepage wire — has no per-branch test
    - **Where:** `app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php:135-196`
    - **Affects:** Every unauthenticated public sitepage request. Five distinct branches (shop-with-pending-rejection, linkMode override, non-array bailout, unknown-platform fail-closed, known-platform allowlist intersection) sit between stored JSONB and the public internet.
    - **Effort:** M (~2–4h)
    - **What to do:** Add `tests/Feature/Resources/PublicIntegrationConnectionResourceTest.php` covering each branch, especially the shop pending-brand rejection and the unknown-platform fail-closed path.
    - **Evidence:**
        ```php
        return $this->shopBrands->reject(fn ($b) => $b->connect_status === 'pending')
            ->mapWithKeys(function ($b) use ($linkMode, $productRanks) { ... });
        ```

- [ ] **#TEST-22** · P2 — `SectionRuleRules` trait (6 validation branches) and three other Form Requests tying validation to runtime registries have no tests
    - **Where:** `app/Http/Requests/Api/User/Sections/SectionRuleRules.php`; `app/Http/Requests/Api/User/ContentLibrary/UpsertManualOverrideRequest.php:40-47`; `app/Http/Requests/Api/User/Design/ApplyRestyleRequest.php:27`; `app/Http/Requests/Api/User/Sections/{StorePageRequest.php:33,UpdatePageRequest.php:27}`
    - **Affects:** Section/page/design-kit write endpoints — each validation rule is tied to a live registry (`KindRegistry`, `FacetRegistry`, `DesignKitAutopilot::WRITABLE`, `PageCapabilities`); a registry refactor can silently loosen or tighten what's accepted with no test catching it.
    - **Effort:** M (~2–4h)
    - **What to do:** For each, add one valid + one invalid payload test per the lens's Form Request convention, specifically exercising the registry-lookup branch (not just basic required/string rules).
    - **Evidence:**
        ```php
        // SectionRuleRules.php — six distinct validation branches in one closure
        if ($unknown !== []) { … } try { SectionRule::fromArray($rule); } catch (...) { … }
        if (count($predicate->values) > self::MAX_VALUES_PER_PREDICATE) { … }
        ```

- [ ] **#TEST-23** · P2 — `IntegrationConnectionPolicy`'s `connect` ability (non-standard two-argument shape) is only exercised through an inline test mock, never the real policy
    - **Where:** `app/Policies/IntegrationConnectionPolicy.php`; `tests/Feature/Platforms/DisplaySettingsTest.php` binds a fake subclass to the container
    - **Affects:** Every platform connect endpoint routed through `GenericPlatformController`.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Add a direct policy test passing `[new IntegrationConnection(['user_id' => $user->id]), $descriptor]` and asserting both allow and deny, mirroring the 404 contract the test mock currently fakes.
    - **Evidence:**
        ```php
        $this->app->bind(IntegrationConnectionPolicy::class, fn () => new class extends IntegrationConnectionPolicy {
            public function update(User $actor, Model $resource): bool|Response { return Response::denyAsNotFound(); }
        });
        ```

- [ ] **#TEST-24** · P2 — `ManagesIntegrationConnection::upsertConnection()`'s merge-vs-replace payload behavior is untested despite preventing three documented historical bugs
    - **Where:** `app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php`
    - **Affects:** Every deferred-connect reconnect — the merge preserves `refresh_etag` across reconnects; losing it reintroduces the documented Bandcamp/conditional-request 304 traps.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Add tests for `mergePayload: true` (preserves untouched keys) and `false` (replaces wholesale).
    - **Evidence:**
        ```php
        if ($mergePayload && array_key_exists('payload', $values)) {
            $values['payload'] = [...($existing->payload ?? []), ...$values['payload']];
        }
        ```

- [ ] **#TEST-25** · P2 — `withConnectionLock()`'s 423-on-timeout contract (the only user-facing "try again" signal for lock contention) is untested
    - **Where:** `app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php`
    - **Affects:** Every platform controller using the shared lock helper; catches both `LockTimeoutException` and `AdvisoryLockTimeoutException` in one branch.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Add a test pre-acquiring the lock and asserting the 423 response for both exception types.
    - **Evidence:**
        ```php
        } catch (LockTimeoutException|AdvisoryLockTimeoutException) {
            return $this->error('Another change is still saving — please retry in a moment.', 423);
        }
        ```

- [ ] **#TEST-26** · P2 — `ShopController::setProducts()`'s fetch-outside-lock/re-read-inside-lock race pattern has no concurrent-write test
    - **Where:** `app/Http/Controllers/Api/Platforms/ShopController.php` (`setProducts()`)
    - **Affects:** Product selection writes for shop brands — the in-line comment explicitly warns a concurrent `removeBrand`/`forget` can delete the brand mid-fetch, which is exactly why the code re-reads authoritatively inside the lock.
    - **Effort:** M (~2–4h)
    - **What to do:** Add a test simulating brand deletion between the pre-lock read and the lock acquisition, asserting the authoritative re-read catches it and returns 404 rather than writing to a deleted brand.
    - **Evidence:**
        ```php
        // Pre-lock read — deliberately duplicated by the authoritative re-read
        // inside the lock below... a concurrent removeBrand/forget can delete
        // this brand while the fetch (below) is still running.
        ```

- [ ] **#TEST-27** · P2 — `GoogleBusinessService::resolvePhotoUrls()`'s budget-claim loop (skip carry-forward, break-on-denial, Nightwatch paging) is untested
    - **Where:** `app/Services/Platforms/GoogleBusinessService.php:293-356`
    - **Affects:** Places API spend during connect/refresh — a bug here silently over- or under-spends budget on a paid, uncapped API (per project cost notes, Places is the pilot's one uncapped-paid surface).
    - **Effort:** M (~2–4h)
    - **What to do:** Add tests for carry-forward skip (no claim), break-on-`UserCapReached`, and Nightwatch `report()` on platform-level exhaustion.
    - **Evidence:**
        ```php
        $claim = $this->budget->claim('photos', $userId);
        if ($claim !== PlacesClaim::Granted) {
            if ($claim !== PlacesClaim::UserCapReached) { report(new PlacesBudgetExhaustedException(...)); }
            break;
        }
        ```

- [ ] **#TEST-28** · P2 — `IdentityCandidateController::settle()` and `SuggestionsController::dismiss()` dual-write idempotency is untested under concurrent settle/dismiss
    - **Where:** `app/Http/Controllers/Api/Content/IdentityCandidateController.php:100-106`; `app/Http/Controllers/Api/Routing/SuggestionsController.php:105-121`
    - **Affects:** Users resolving duplicate-item suggestions and dismissing routing suggestions — both use raw `whereNull(...)`/`insertOrIgnore` guards specifically to survive a concurrent merge-then-dismiss or double-dismiss.
    - **Effort:** M (~2–4h)
    - **What to do:** Add a test that runs both write paths concurrently (or in immediate succession) for the same row and asserts a single, consistent terminal state with no error.
    - **Evidence:**
        ```php
        // Written raw: the row may already have been dismissed by a merge that
        // touched the same pair, and this must stay idempotent.
        DB::table('content.identity_candidates')->where('id', $candidate->id)->whereNull('dismissed_at')->update([...]);
        ```

- [ ] **#TEST-29** · P2 — `ConnectFetchJob::failed()` is never invoked by any test, despite another test file's comment confirming its existence and fallback message
    - **Where:** `tests/Unit/Jobs/ConnectFetchJobTest.php` (14 tests, none call `handle()` twice or invoke `failed()`)
    - **Affects:** Observability of connect-fetch failures — the terminal-status write and error-message fallback a user sees when a connection permanently fails to load.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Add a test forcing an unhandled exception from `handle()` and asserting `failed()` writes the expected terminal status/message. Add a companion idempotency-under-retry test (`handle()` called twice → identical end-state).
    - **Evidence:**
        ```php
        // CA-SM review fix: ... the same generic, already-established infra string
        // ConnectFetchJob::failed() falls back to.
        expect($fresh->last_refresh_error)->toBe('We could not load that account. Please try again.');
        ```

- [ ] **#TEST-30** · P2 — `SafeUrlFetcher` — Partna's own SSRF-protection boundary — is mocked with `Mockery::mock()` instead of `Http::fake()` in Shop URL validation tests, bypassing the SSRF guard entirely in CI
    - **Where:** `tests/Feature/Platforms/ShopUrlValidationTest.php:81-96`
    - **Affects:** All Shop URL validation tests — SSRF allowlist, DNS resolution, redirect limits are all stripped out under this mock.
    - **Effort:** M (~2–4h)
    - **What to do:** Replace with `Http::fake()` using an RFC 2606 domain (`example.com`), matching the correct pattern already used in `ScanPreviousWebsiteContentJobTest.php`.
    - **Technical:** Per the lens's own mock-vs-integration doctrine, internal services like `SafeUrlFetcher` must never be mocked — only vendor SDKs may be. Mocking the platform's own SSRF boundary means these tests provide zero assurance that boundary works.
    - **Evidence:**
        ```php
        $mock = Mockery::mock(SafeUrlFetcher::class);
        $mock->shouldReceive('tryFetch')->andReturnUsing(...);
        app()->instance(SafeUrlFetcher::class, $mock);
        ```

- [ ] **#TEST-31** · P2 — Several "idempotent" tests (routing link creation, probe cache cooldown, `catalog:sync`) prove only sequential correctness, never concurrent
    - **Where:** `tests/Feature/Routing/RoutingEndpointTest.php` (`is idempotent — the same link twice...`), `tests/Feature/Routing/LinkProbeWorkerTest.php` (`keeps an answer rather than paying for it twice`, `charges one probe for a URL two users paste`), `tests/Postgres/CatalogSyncIdempotenceTest.php` (`is idempotent: same artefact twice changes no rows`)
    - **Affects:** Duplicate connection creation on double-submit, double-charged probes on simultaneous pastes, and duplicate catalog rows on overlapping deploy-time syncs.
    - **Effort:** M (~2–4h)
    - **What to do:** For each, add a genuinely concurrent variant (two requests/calls dispatched before either commits) and assert single-write outcomes, since sequential idempotency does not prove the check-then-write pattern is race-free.
    - **Evidence:**
        ```php
        it('is idempotent — the same link twice yields one connection', function () {
            actingAsUser($pro)->postJson('/api/routing/links', [...])->assertStatus(202);
            actingAsUser($pro)->postJson('/api/routing/links', [...])->assertStatus(202); // sequential, not concurrent
        ```

- [ ] **#TEST-32** · P2 — `DeferredConnectParityTest` proves strategy-level parity for 7 platforms but none has a full-flow HTTP lifecycle test before their deferred-connect flags go live
    - **Where:** `tests/Feature/Platforms/Strategies/DeferredConnectParityTest.php`; contrast with `tests/Feature/Platforms/SkoolAsyncConnectTest.php` and `AppleAsyncConnectTest.php`, which do have full-flow coverage
    - **Affects:** Spotify, Bandcamp, Twitch, Strava, Vimeo, YouTube, YouTube Music — when each flips `partna.connect.deferred` on, there is no test of the 202→poll→ready/failed HTTP lifecycle, only of `identify()`/`resolve()` data-shape agreement.
    - **Effort:** L (~1–2d)
    - **What to do:** As each platform goes deferred, add a full-flow test modeled on `SkoolAsyncConnectTest.php`.
    - **Evidence:** (pattern comparison; `SkoolAsyncConnectTest` exercises `assertStatus(202)` + poll + job completion, `DeferredConnectParityTest` only calls `identify()`/`resolve()` directly)

- [ ] **#TEST-33** · P2 — `SectionItemController::upsert()`'s pin-to-exclude state transition on an existing row is untested
    - **Where:** `app/Http/Controllers/Api/Site/SectionItemController.php:56-81`
    - **Affects:** Users changing a curated item from pinned to excluded — a naive `create()` (instead of the current first-or-new upsert) would throw a unique-constraint error on `(section_id, item_id)`.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Add a test creating a pinned row, then upserting to `excluded`, asserting the existing row updates (not duplicates) and `sort_key` nulls out.
    - **Evidence:**
        ```php
        $row = SectionItem::query()->where('section_id', $section->id)->where('item_id', $item->id)->first() ?? new SectionItem;
        ```

- [ ] **#TEST-34** · P2 — `RestyleController::undo()`'s cross-site 404 (anti-enumeration) has no test despite an explicit house-rule comment
    - **Where:** `app/Http/Controllers/Api/Site/RestyleController.php:49-62`
    - **Affects:** Restyle undo — a scope-clause regression would leak restyle existence across sites via 403-vs-404 status.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Add a test undoing site A's restyle as site B's owner, asserting 404.
    - **Evidence:**
        ```php
        if ($restyle === null) {
            // 404, never 403 — existence must not leak (house rule).
            abort(404, 'Restyle not found.');
        }
        ```

- [ ] **#TEST-35** · P2 — Catalog definition sweep (75+ classes) has no structural test iterating `_manifest.php` and building every surface
    - **Where:** `app/Catalog/Definitions/_manifest.php`; `app/Catalog/SurfaceBuilder.php`
    - **Affects:** Every deploy touching a catalog definition — `SurfaceBuilder`'s builder fields are nullable while `Surface`'s constructor params are not, so a definition that never chained `.routing()`/`.shelf()`/`.identifier()` throws a `TypeError` at build time with no test catching it before deploy.
    - **Effort:** M (~2–4h)
    - **What to do:** Add a structural sweep test (matching the house `PolicyCoverageTest`/`JobHygienePolicyTest` pattern) iterating the manifest and building every surface.
    - **Evidence:**
        ```php
        private ?RoutingClass $routingClass = null; // SurfaceBuilder — nullable
        public function __construct(public RoutingClass $routingClass, ...) // Surface — non-nullable
        ```

- [ ] **#TEST-36** · P2 — `CatalogIntegrityCheck::unservableSurfaces()`/`orphanCapabilities()` — the production graceful-degradation guard — has no test in either direction
    - **Where:** `app/Catalog/CatalogIntegrityCheck.php:20-55`
    - **Affects:** The platform picker — a surface referencing a capability absent from the current build should be hidden, not crash the picker or silently ship broken connect buttons.
    - **Effort:** M (~2–4h)
    - **What to do:** Add tests for a surface with a missing capability (flagged unservable), a capability no surface references (flagged orphan), and the servable happy path.

- [ ] **#TEST-37** · P2 — `CapabilityManifest::resolve()`'s three resolution paths (class+args, closure, unknown) are untested
    - **Where:** `app/Catalog/CapabilityManifest.php:34-47`
    - **Affects:** Any surface routing through capability resolution — a broken constructor-arg mismatch or closure bug throws uncaught on a real request path.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Add tests for a class-based entry, a closure entry, and an unknown-name `InvalidArgumentException`.

- [ ] **#TEST-38** · P2 — `LegacyPlatformMap`'s documented three-way lockstep (PHP map ↔ backfill SQL CASE ↔ generated `platform` column) has no confirmed enforcing test in scope
    - **Where:** `app/Catalog/LegacyPlatformMap.php` (docblock references `CatalogLegacyMapTest`); `supabase/migrations/20260727110000_connections_surface_key.sql:109-124`
    - **Affects:** Every read of the legacy `platform` column on `platform_connections` — a drift between the PHP map and the SQL `GENERATED ALWAYS AS` CASE silently corrupts every connection's legacy-platform read.
    - **Effort:** M (~2–4h)
    - **What to do:** Confirm `CatalogLegacyMapTest.php` exists and asserts all three consumers agree; if absent, create it — the code's own docblock names this exact test as the enforcement mechanism.
    - **Evidence:**
        ```php
        // Three consumers must stay in exact lockstep... CatalogLegacyMapTest
        // asserts 1↔2↔3 agreement and map ⊆ compiled artefact.
        ```

- [ ] **#TEST-39** · P2 — `ValueResolver`'s override precedence and 24-hour recency-dwell threshold are untested
    - **Where:** `app/Content/Values/ValueResolver.php` (entire class)
    - **Affects:** What the public sitepage displays for every contested field — the dwell threshold specifically prevents a noisy source from permanently stealing a field by re-publishing unchanged content.
    - **Effort:** M (~2–4h)
    - **What to do:** Add tests for override-always-wins (including explicit-null-clears-all), `Longest`, `Union` dedup, and the recency dwell boundary (23h no-flip vs 25h flip).
    - **Evidence:**
        ```php
        private const RECENCY_DWELL_HOURS = 24;
        ```

- [ ] **#TEST-40** · P2 — Container binding leak: `app()->instance()` mock replaces a real service and is never restored, silently affecting later tests in the same file
    - **Where:** `tests/Feature/Analytics/ComputePopularityScoresTest.php` (~lines 190-194)
    - **Affects:** Test reliability — any test appended after this one in the same file transparently resolves the mocked `RankedActionsComputer` instead of the real one.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Wrap the mock in `try/finally` or use `afterEach(fn () => app()->forgetInstance(RankedActionsComputer::class))`.
    - **Evidence:**
        ```php
        app()->instance(RankedActionsComputer::class, $brokenRankedActions);
        // no restore anywhere in the file
        ```

- [ ] **#TEST-41** · P2 — `tests/Postgres/*` tests hand-copy migration DDL inline rather than exercising the real migration files, risking silent schema drift
    - **Where:** `tests/Postgres/BrandAssetPipelineTest.php:36-68`, `tests/Postgres/CatalogSyncIdempotenceTest.php:19-83`, `tests/Postgres/ItemTombstoneBackfillTest.php:26-61`
    - **Affects:** CI confidence that Postgres tests validate against production schema — one file (`ItemTombstoneBackfillTest`) already demonstrates the correct pattern (`file_get_contents(base_path(...))`) for the backfill itself but not for its own base-table setup.
    - **Effort:** M (~2–4h)
    - **What to do:** Migrate the remaining inline `CREATE TABLE` blocks to read from the real migration files, or add a drift-check assertion comparing inline DDL to migration DDL.
    - **Evidence:**
        ```php
        $pg->statement('CREATE TABLE catalog.brands (key text PRIMARY KEY, ...)'); // hand-copied
        DB::connection('pgsql')->statement(file_get_contents(base_path(BACKFILL_SQL_PATH))); // correct pattern, same file
        ```

- [ ] **#TEST-42** · P2 — No end-to-end test connects `analytics:compute-popularity`'s writes to `IndividualProfilePayloadBuilder`'s reads
    - **Where:** `tests/Feature/Analytics/RankedActionsComputeTest.php` / `ComputePopularityScoresTest.php` (producer-only); public-profile ranked-actions tests (consumer-only, seed scores manually via `insertActionScore()` bypassing the command entirely)
    - **Affects:** The public sitepage payload's ranked-actions block — a column-name or format drift between the command's writes and the builder's reads would leave both test suites green while the real payload is broken.
    - **Effort:** M (~2–4h)
    - **What to do:** Add one integration test that runs the real command, then reads through the real payload builder on the same site, asserting the ranked actions appear correctly.

- [ ] **#TEST-43** · P2 — `SchemaOrgEvent::lowestOffer()`'s min-across-offers pricing algorithm (Eventbrite/Humanitix) is untested
    - **Where:** `app/Ingest/Support/SchemaOrgEvent.php:112-138`
    - **Affects:** Displayed ticket prices on every event card sourced from these two connectors.
    - **Effort:** M (~2–4h)
    - **What to do:** Add tests for multi-offer minimum selection, `lowPrice`-over-`price` priority within one offer, and currency taken from the first offer that declares it.

- [ ] **#TEST-44** · P2 — `YoutubeFeed::parse()`'s XXE defense (`LIBXML_NONET`, no `LIBXML_NOENT`) has no regression test
    - **Where:** `app/Ingest/Support/YoutubeFeed.php:30-39`
    - **Affects:** Both YouTube RSS connectors — a future "fix" that adds entity-loading flags to solve an unrelated parse issue would silently reopen an XXE vector.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Add a test feeding an XML payload with a `<!ENTITY xxe SYSTEM "...">` declaration and asserting the entity is never resolved.

- [ ] **#TEST-45** · P2 — `SourceProvisioner::schedulable()`'s single-boolean billing gate (the entire on/off switch for paid connectors) is untested; its `sync()` also has a documented but unguarded create-race
    - **Where:** `app/Ingest/SourceProvisioner.php:117-120` (schedulable), `:76-94` (sync race)
    - **Affects:** When paid connector drivers land, this one predicate is what turns billing on platform-wide.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Add a test asserting `Billed` manifests produce `auto_sync=false` and `Free` produce `true`. Separately, add a concurrent-dispatch test for `sync()`'s read-then-insert TOCTOU (two syncs for the same connection racing to create the source row).
    - **Evidence:**
        ```php
        private static function schedulable(Manifest $manifest): bool
        {
            return $manifest->cost === CostClass::Free;
        }
        ```

- [ ] **#TEST-46** · P2 — Onboarding suggestions response has no structural snapshot test; the test helper works around an undocumented `Site::create()` fillable gap
    - **Where:** `tests/Feature/Onboarding/OnboardingSuggestionsTest.php` (entire file)
    - **Affects:** Confidence that new fields added to the onboarding response (the first API call after signup, adjacent to sector/connection PII) don't leak unintended keys; and confidence that `Site::create()` works correctly for any real caller.
    - **Effort:** M (~2–4h)
    - **What to do:** Add `user_id` to `Site::$fillable` (or remove from `$guarded`) and switch the test helper to `Site::create()`; add a full-key-set snapshot assertion on the suggestions response.
    - **Evidence:**
        ```php
        // Raw insert (createTenant's pattern) — Site::create() silently drops
        // non-fillable keys like user_id, leaving the site() relation empty.
        DB::connection('pgsql')->table('site.sites')->insert([...]);
        ```

- [ ] **#TEST-47** · P2 — `SiteBuildDocumentsCommandTest`'s "mid-build content change" test never actually simulates a mid-build change — it's a test that lies about its own coverage
    - **Where:** `tests/Feature/Site/SiteBuildDocumentsCommandTest.php:111-119`
    - **Affects:** Confidence that `BuildState`'s CAS guard correctly handles a genuine race — the test bumps the revision *before* creating the job, never during, so the CAS conflict path is never exercised.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Rewrite to bump `BuildState` from a second thread/call after the job has read its starting revision but before it commits, and assert either a correct rebuild or a consistent final state.
    - **Evidence:**
        ```php
        it('the job builds through a mid-build content change by rebuilding from the new revision', function () {
            BuildState::bump($siteId);
            (new BuildSiteDocumentJob($siteId))->handle(app(DocumentBuilder::class)); // no mutation during handle()
        ```

## P3 — Nice to have

- [ ] **#TEST-48** · P3 — `DetectorBuilder::build()`'s surfaceKey/signalKey XOR validation has no unit test for either error branch
    - **Where:** `app/Catalog/DetectorBuilder.php:100-104`
    - **Effort:** S (~0.5–1h)
    - **Evidence:** `if ($hasSurface === $hasSignal) { throw new InvalidArgumentException(...); }`

- [ ] **#TEST-49** · P3 — `detectors_surface_xor_signal` DB CHECK constraint (defense-in-depth backup to #TEST-48) has no grep-based invariant test
    - **Where:** `supabase/migrations/20260727100000_catalog_schema.sql:57`
    - **Effort:** S (~0.5–1h)
    - **Evidence:** `CONSTRAINT "detectors_surface_xor_signal" CHECK (("surface_key" IS NULL) <> ("signal_key" IS NULL))`

- [ ] **#TEST-50** · P3 — `content.identity_keys` deliberately has NO unique index on `(key_class, key_value)` — a "must not exist" invariant with no guard against a well-meaning future addition
    - **Where:** `supabase/migrations/20260727140000_content_schema.sql:76-82`
    - **Effort:** S (~0.5–1h)
    - **Evidence:** `-- Deliberately NO unique index on (class, value): two sources reporting the same ISRC is the exact signal the resolver consumes.`

- [ ] **#TEST-51** · P3 — `MenuRecords::flatten()`'s stable-key generation (Square/Uber Eats/DoorDash dedup) has no test asserting key stability across runs
    - **Where:** `app/Ingest/Support/MenuRecords.php:61-63`
    - **Effort:** S (~0.5–1h)
    - **Evidence:** `$key = $externalId ?? substr(sha1(mb_strtolower($categoryName.'|'.$name)), 0, 16);`

- [ ] **#TEST-52** · P3 — `BrandAssetPipelineTest` proves SVG rejection but never exercises the sanitized-SVG acceptance path implied by the test's own name
    - **Where:** `tests/Postgres/BrandAssetPipelineTest.php:120-131`
    - **Effort:** S (~0.5–1h)
    - **Evidence:** `it('refuses an unsanitised scraped vector', ...)` — no corresponding test for a clean SVG.

- [ ] **#TEST-53** · P3 — `CatalogSurfacesEndpointTest` asserts only top-level key presence (`assertJsonStructure(['digest','brands','surfaces'])`), not the shape inside `brands`/`surfaces`
    - **Where:** `tests/Feature/Catalog/CatalogSurfacesEndpointTest.php:7-13`
    - **Effort:** S (~0.5–1h)

- [ ] **#TEST-54** · P3 — `setupNotificationsTable()` shared test helper is missing the dedupe unique index; one test compensates locally instead of fixing the shared helper
    - **Where:** `tests/Feature/Notifications/IntegrationConnectedNotifierTest.php:22-27`
    - **Affects:** Every other test using `setupNotificationsTable()` — their dedupe assertions (if any) pass vacuously without the index.
    - **Effort:** S (~0.5–1h)
    - **Evidence:**
        ```php
        // setupNotificationsTable() does NOT create the dedupe index... without
        // this, duplicate publishes would silently insert twice.
        DB::connection('pgsql')->statement('CREATE UNIQUE INDEX IF NOT EXISTS ...');
        ```

- [ ] **#TEST-55** · P3 — `LifestyleConnectionCleanupTest` has no test for the (most common in production) zero-connections boundary case
    - **Where:** `tests/Feature/Accounts/LifestyleConnectionCleanupTest.php`
    - **Affects:** Every business-account switch where no lifestyle connections exist to clean up — the most common real-world case is the only untested one.
    - **Effort:** S (~0.5–1h)

- [ ] **#TEST-56** · P3 — `AccountCapabilities` notification-gate rejection path is untested; only allowed-path and unrelated silent-guards are covered
    - **Where:** `tests/Feature/Notifications/IntegrationConnectedNotifierTest.php`; production: `app/Services/Notifications/Dispatchers/IntegrationNotifier.php`
    - **Effort:** S (~0.5–1h)

## Suggested Bundled Sessions

- **Bundle 1 — Policy 404-vs-403 contract:** #TEST-18, #TEST-19
    - **Why grouped:** Same two files (`SectionPolicy`, `DesignKitRestylePolicy`, `ContentItemPolicy`), same anti-enumeration doctrine, same fix pattern.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Platform connect controllers (lock/deadlock/race hardening):** #TEST-4, #TEST-25, #TEST-26, #TEST-24, #TEST-23
    - **Why grouped:** Same `ManagesIntegrationConnection` trait and its consumers; all concern the lock/dispatch/merge contract already implemented — tests only.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 3 — Catalog structural sweeps:** #TEST-35, #TEST-36, #TEST-37, #TEST-38, #TEST-48, #TEST-49
    - **Why grouped:** All are house-pattern structural sweep tests over `app/Catalog`, same file family, same low-risk mechanical pattern.
    - **Model:** Plan: Sonnet · Implement: Sonnet · Review: Sonnet.

- **Bundle 4 — Routing decision-layer idempotency:** #TEST-28, #TEST-31, #TEST-33, #TEST-34
    - **Why grouped:** All are concurrent-write/idempotency tests over the routing/content controllers landed in the same recent merge wave.
    - **Model:** Plan: Sonnet · Implement: Sonnet · Review: Sonnet.

- **Bundle 5 — Ingest connector/pipeline hardening tests:** #TEST-27, #TEST-43, #TEST-44, #TEST-45, #TEST-51
    - **Why grouped:** All are `app/Services/Platforms` and `app/Ingest/Support` pure-logic tests with no shared state, safe to implement together.
    - **Model:** Plan: Sonnet · Implement: Sonnet · Review: Sonnet.

- **Bundle 6 — Test-suite hygiene:** #TEST-40, #TEST-41, #TEST-47, #TEST-53, #TEST-54, #TEST-55, #TEST-56, #TEST-46, #TEST-52
    - **Why grouped:** All are fixes to existing tests (leaks, drift, lying assertions, missing helper index) rather than new production-code coverage — same review lens.
    - **Model:** Plan: Sonnet · Implement: Sonnet · Review: Sonnet.

- **Bundle 7 — Content/value resolution unit tests:** #TEST-39, #TEST-50
    - **Why grouped:** Both `app/Content/Values` and `app/Content/Identity` pure-logic, no DB writes beyond factory seeding.
    - **Model:** Plan: Sonnet · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#TEST-15 — Policy ability coverage systemic gap** · L effort, touches authorization across 14+ Policy classes — plan and sign-off first.
- **#TEST-16 — Document-build pipeline (BuildState + DocumentBuilder)** · L effort, foundational to every public sitepage render.
- **#TEST-14 — Identity resolution engine (Resolver + DisjointSet)** · L effort, documented historical bug, platform-wide blast radius.
- **#TEST-3 — SourceProvisioner sync()+identifierFor()** · L effort, sole entry seam for the ingest pipeline.
- **#TEST-7 — EffectLedger charge-once** · touches money (billed API effects).
- **#TEST-11 — field_bindings_manual_priority CHECK constraint** · DB schema/migration invariant.
- **#TEST-9 — Lander 40% deletion guard** · data-loss circuit breaker, irreversible-consequence path.
- **#TEST-10 — RunExecutor::isClaimed() PII-redaction gate** · privacy/GDPR-adjacent, unclaimed-account data handling.
- **#TEST-2 — RoutingController::store() branch coverage** · L effort, newest primary write path in the platform.
- **#TEST-17 — ConnectionPayload::forWrite()** · documented prior production incident; fix already shipped, verify against it in isolation before bundling with anything else.
- **#TEST-32 — DeferredConnectParityTest full-flow gaps** · L effort, spans 7 platform integrations.
