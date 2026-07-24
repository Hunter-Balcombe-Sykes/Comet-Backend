# Test Coverage Audit — 2026-07-24

**Branch:** HEAD
**Lens:** Test coverage: critical paths, idempotency, race-safety, policy abilities, mock-vs-integration discipline (single lens, scanned across 7 codebase chunks: sweep-conventions, prod-http, prod-platforms-services, prod-schema, feature-domain, feature-platforms, unit-suite)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- tests/Pest.php
- app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php
- app/Http/Controllers/Api/PublicSite/PublicMenuController.php
- app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php
- app/Services/Platforms/EventSlugSync.php
- app/Services/Site/ItemSlugAllocator.php
- app/Services/Platforms/EventsCatalog.php
- app/Http/Controllers/Api/Platforms/EventsController.php
- app/Observers/MenuItemObserver.php
- app/Console/Commands/BackfillItemSlugs.php
- supabase/migrations/20260724120000_create_item_slugs.sql
- tests/Feature/PublicSite/PublicEventSlugTest.php, PublicMenuControllerTest.php
- tests/Feature/Platforms/PublicIntegrationAllowlistTest.php, PublicPlatformEndpointTest.php, ShopRelationalStorageTest.php, EventsCatalogTest.php, PlatformConnectionAuthorizationTest.php, Registry/RegistryCoverageTest.php, DisplaySettingsTest.php
- tests/Feature/Database/ArchitectureSystemConstraintsTest.php, ConstraintVocabularyLockstepTest.php, CheckConstraintsTest.php
- tests/Feature/Resources/IndividualProfileResourceTest.php
- tests/Feature/Account/AccountCapabilitiesTest.php
- tests/Unit/Analytics/RecordAnalyticsEventJobTest.php, tests/Unit/Jobs/SyncSubdomainToKvJobTest.php
- tests/Unit/Services/Site/ItemSlugAllocatorTest.php, tests/Unit/Services/Platforms/EventSlugSyncTest.php, tests/Unit/Console/BackfillItemSlugsTest.php, tests/Unit/Observers/MenuItemObserverTest.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 6 complete
- P3 Low: 0 of 3 complete

**Adjudication note:** the draft scan over-reported heavily on this scope. Most "no visible test" claims turned out false on inspection — this PR's feature (item-url-slugs) and the public sitepage endpoints it touches are unusually well tested (`PublicEventSlugTest.php`, `PublicMenuControllerTest.php`, `PublicIntegrationAllowlistTest.php`, `EventSlugSyncTest.php`, `ItemSlugAllocatorTest.php`, `BackfillItemSlugsTest.php` all directly cover what several draft findings claimed was untested). Two findings (`account_type` should be `'individual'`) were backwards: the `AccountType` enum and `users_account_type_check` constraint (`supabase/migrations/20260612120000_account_type_partna_business.sql`) only permit `'partna'`/`'business'` — `'individual'` is the legacy value explicitly rejected going forward. Those were dropped rather than "fixed," since applying the proposed fix would itself introduce a bug.

---

## P2 — Should fix

- [ ] **TEST-1** · P2 — No invariant test asserting `site.themes` stays dropped
    - **Where:** tests/Feature/Database/ (no matching file); supabase/migrations/20260527070000_skeleton_system_cleanup.sql:56
    - **Affects:** Architecture-system integrity — a future migration authored by copy-pasting an older pattern could accidentally re-introduce `site.themes` with nothing in CI to catch it.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a test in `tests/Feature/Database/` (sibling to `ArchitectureSystemConstraintsTest.php`) that greps all `supabase/migrations/*.sql` files for `CREATE TABLE site.themes` (or `CREATE TABLE IF NOT EXISTS site.themes`) appearing *after* the drop migration's position and asserts zero matches.
        - Follow the `ConstraintVocabularyLockstepTest.php` pattern (regexes the migration file as text) rather than a live-DB assertion, since this must also mean something on the SQLite CI driver.
    - **Technical:** `20260527070000_skeleton_system_cleanup.sql:56` runs `DROP TABLE IF EXISTS site.themes CASCADE;` as part of the single-architecture cleanup (CLAUDE.md: "`site.themes` and `settings.design.*` are removed — code touching them is a finding"). `ArchitectureSystemConstraintsTest.php` verifies three *other* invariants from the same cleanup (the `architecture_id` CHECK, the `design_kits` CASCADE FK, the auto-insert trigger) but has no assertion about `site.themes` itself, and grepping the whole `tests/` tree turns up zero references to `site.themes` anywhere. This is exactly the kind of structural regression the codebase's grep-based invariant convention (`ConstraintVocabularyLockstepTest.php`, `WriteDesignKitTest.php`) exists to catch, and it's currently uncovered for this specific table.
    - **Plain English:** The team deliberately tore down an old "themes" system when they rebuilt the design engine, and the rule going forward is: never bring it back. Right now nothing in the automated tests would notice if someone accidentally rebuilt it — like renovating a kitchen and never checking that the wall you paid to remove doesn't quietly reappear in a later "fix."
    - **Evidence:**
        ```sql
        -- supabase/migrations/20260527070000_skeleton_system_cleanup.sql:56
        DROP TABLE IF EXISTS site.themes CASCADE;
        ```

- [ ] **TEST-2** · P2 — No migration-invariant test for `site.item_slugs`'s CHECK / UNIQUE / FK constraints
    - **Where:** supabase/migrations/20260724120000_create_item_slugs.sql (entire file); no corresponding test in tests/Feature/Database/
    - **Affects:** CI's ability to catch a future migration that relaxes the per-profile slug-uniqueness guarantee or the one-current-slug-per-item guarantee — SQLite can't enforce either at the test layer, so nothing else catches a regression here.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a grep-based invariant test (matching the `WriteDesignKitTest.php` exemplar / `ConstraintVocabularyLockstepTest.php` pattern) asserting the migration file text contains: the CHECK `item_type IN ('event', 'menu_item')`, the unique index `item_slugs_unique_slug ON site.item_slugs (user_id, slug)`, the partial unique index `item_slugs_one_current ... WHERE is_current`, and the FK `item_slugs_user_fk FOREIGN KEY (user_id) REFERENCES core.users(id) ON DELETE CASCADE`.
        - Place it in `tests/Feature/Database/`.
    - **Technical:** This migration (introduced alongside the item-url-slugs feature, commit `3c152ca9`) defines the entire data-integrity contract `ItemSlugAllocator` depends on: per-user slug uniqueness and exactly-one-current-slug-per-item. SQLite's `setupItemSlugsTable()` mirror in `tests/Pest.php` reproduces the same unique indexes so `ItemSlugAllocatorTest.php` can exercise collision/rename behavior, but nothing greps the actual Postgres migration file to confirm those constraints are still declared there — the established convention this codebase uses for exactly this class of gap (SQLite can't enforce Postgres CHECK/UNIQUE/FK).
    - **Plain English:** The database has rules like "no two live items for the same professional can share a web address" and "an item can only have one current address at a time." Because the test database is a simplified stand-in that can't enforce real database rules, the team's safety net is a test that reads the actual rule straight out of the migration file and confirms it's still there. That safety net was never added for this new table.
    - **Evidence:**
        ```sql
        -- supabase/migrations/20260724120000_create_item_slugs.sql
        CONSTRAINT item_slugs_user_fk FOREIGN KEY (user_id) REFERENCES core.users(id) ON DELETE CASCADE,
        CONSTRAINT item_slugs_type_check CHECK (item_type IN ('event', 'menu_item'))
        );
        CREATE UNIQUE INDEX IF NOT EXISTS item_slugs_unique_slug
            ON site.item_slugs (user_id, slug);
        CREATE UNIQUE INDEX IF NOT EXISTS item_slugs_one_current
            ON site.item_slugs (user_id, item_type, item_key)
            WHERE is_current;
        ```

- [ ] **TEST-3** · P2 — No test for deleting a non-existent custom event via `DELETE /api/platforms/events/custom/{id}`
    - **Where:** app/Services/Platforms/EventsCatalog.php:203-218 (`removeCustom`); tests/Feature/Platforms/EventsCatalogTest.php (only the happy-path delete is tested)
    - **Affects:** The `EventsController::removeCustom` endpoint — the 404-not-found contract for a made-up event ID is implemented but has zero regression protection.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Add `it('returns 404 when deleting a non-existent custom event')` to `EventsCatalogTest.php`: `actingAsUser($user)->deleteJson('/api/platforms/events/custom/'.Str::uuid())->assertNotFound()`.
    - **Technical:** `EventsCatalog::removeCustom()` scopes its lookup by `where('user_id', $user->id)` and returns `$this->fail('Event not found.', 404)` when no row matches — this is already correct per the Partna 404-on-not-found doctrine, and `EventsController::removeCustom()` maps `$result['status'] ?? 404` straight onto the HTTP response. The only existing test (`'removes a custom event via the custom delete endpoint'`) exercises solely the happy path (create → delete → 200). Nothing pins the already-correct 404 behavior, so a future refactor that changes the default status or drops the `$row === null` guard would ship silently.
    - **Plain English:** The code already correctly says "not found" if you try to delete an event that doesn't exist — but there's no test proving it. It's a cheap, already-passing test to add that locks in behavior the code already gets right, so nobody accidentally breaks it later.
    - **Evidence:**
        ```php
        // app/Services/Platforms/EventsCatalog.php
        public function removeCustom(User $user, string $id): array
        {
            $row = IntegrationConnection::query()
                ->where('user_id', $user->id)
                ->where('platform', self::CUSTOM_PLATFORM)
                ->where('resource_id', 'event-'.$id)
                ->first();

            if ($row === null) {
                return $this->fail('Event not found.', 404);
            }
        ```

- [ ] **TEST-4** · P2 — `ItemSlugAllocator::lookupCurrent` exception-degrade path is untested in both public controllers
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicMenuController.php:197-207 (`menuItemSlugMap`); app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php:166-174 (event-slug lookup)
    - **Affects:** Every public sitepage visitor loading a menu or an events section — if `ItemSlugAllocator::lookupCurrent` throws (DB hiccup, connection blip), both controllers are designed to degrade every item to `slug: null, aliases: [id]` rather than 500ing, but nothing proves the catch block actually does that.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add one test per controller that binds a mock `ItemSlugAllocator` (`app()->instance(ItemSlugAllocator::class, ...)`) whose `lookupCurrent` throws, then asserts the endpoint still returns 200 with every item/event carrying `slug: null` and `aliases: [rawId]`.
        - Host in `tests/Feature/PublicSite/PublicMenuControllerTest.php` and `PublicEventSlugTest.php` respectively (both already have the "no item_slugs table at all" degrade test as a template — this adds the "table exists but the lookup call itself throws" branch).
    - **Technical:** Both controllers wrap the lookup identically: `try { $slugMap = $this->slugs->lookupCurrent(...); } catch (\Throwable $e) { report($e); Log::warning(...); }` (PublicIntegrationController) and an equivalent try/catch returning `[]` in `PublicMenuController::menuItemSlugMap()`. The existing tests (`'degrades to a null slug ... when item_slugs is unavailable'` in `PublicEventSlugTest.php`, `'degrades to a null slug ... when the table exists but this item has no row yet'` in `PublicMenuControllerTest.php`) prove the *empty-map* degrade path, but neither exercises the catch block itself — they never make `lookupCurrent` actually throw. A refactor that changes what the catch block returns (or removes it) would go undetected until a real `item_slugs` outage.
    - **Plain English:** Slug lookups for menus and events are a "nice to have" — if that lookup fails, the page should still load, just with plainer links instead of pretty ones. The code is written to fall back gracefully, but no test actually triggers the failure to prove the fallback works. Right now the tests only cover "the feature isn't installed yet," not "the feature broke."
    - **Evidence:**
        ```php
        // app/Http/Controllers/Api/PublicSite/PublicMenuController.php
        private function menuItemSlugMap(string $userId, array $itemIds): array
        {
            try {
                return $this->slugs->lookupCurrent($userId, ItemSlugAllocator::TYPE_MENU_ITEM, $itemIds);
            } catch (\Throwable $e) {
                report($e);
                Log::warning('PublicMenuController item-slug lookup failed', ['user_id' => $userId, 'message' => $e->getMessage()]);
                return [];
            }
        }
        ```

- [ ] **TEST-5** · P2 — Instagram gallery-suppression branch (`content_instagram_auto_enabled === false`) has no test
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php:106-115
    - **Affects:** Every user with a connected Instagram account who toggles off auto-sync — a comparison-operator regression here either hides a gallery that should show, or (privacy-relevant) shows a gallery the owner explicitly turned off.
    - **Edwort:** S (~0.5–1h)
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('suppresses the Instagram gallery when auto-sync is explicitly off')` — create a user with `content_instagram_auto_enabled = false` and an active instagram connection; assert `data.platforms.instagram.0.payload` doesn't include `images` (suppressed via `DisplaySettingsFilter`).
        - Add `it('does not suppress the gallery when the toggle is null/never set or true')` — the same setup with `content_instagram_auto_enabled = true` or omitted; assert the gallery content survives.
        - Host in `tests/Feature/Platforms/PublicIntegrationAllowlistTest.php` (the existing home for platform-payload-shape assertions on this endpoint).
    - **Technical:** The strict `=== false` check is load-bearing: `null` (never toggled) and `true` must both leave the gallery visible, and only an explicit `false` suppresses it via an in-memory `display_settings['gallery'] = false` override that `DisplaySettingsFilter::suppress()` reads downstream. Grepping the whole `tests/` tree for this exact branch (as opposed to the dashboard-side toggle-persistence tests in `ContentSelectionTest.php`/`DisplaySettingsTest.php`, which test a different code path) turns up nothing — this specific public-wire suppression is unexercised.
    - **Plain English:** Users can flip a switch to hide their Instagram photos from their public page. The code checks that switch with a strict "is it exactly off" comparison so that "never touched" and "explicitly on" both keep photos visible — only "explicitly off" hides them. A one-character typo in that comparison (`==` instead of `===`, or flipping the boolean) could silently hide every gallery that was never touched. No test currently protects against that.
    - **Evidence:**
        ```php
        // app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php
        if ($connections->has('instagram')) {
            $site = Site::query()->where('user_id', $userId)->first(['content_instagram_auto_enabled']);
            if ($site?->content_instagram_auto_enabled === false) {
                foreach ($connections->get('instagram') as $row) {
                    $ds = (array) ($row->display_settings ?? []);
                    $ds['gallery'] = false;
                    $row->display_settings = $ds;
                }
            }
        }
        ```

- [ ] **TEST-6** · P2 — No cross-tenant isolation test for the `EventsCatalog` facade endpoints (`/api/platforms/events/add`, `/selection`, `/custom/{id}`)
    - **Where:** tests/Feature/Platforms/EventsCatalogTest.php (entire file — every test uses a single user); app/Services/Platforms/EventsCatalog.php
    - **Affects:** Any user attempting to enumerate or delete another user's events through the newer "Tickets & Events" smart-detect facade.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a second user via the existing `eventsUser()` helper and, for `selection()` and `removeCustom()`, assert a non-owner sees their own empty selection / gets a scoped no-op or 404 — mirroring the pattern already established in `tests/Feature/Platforms/PlatformConnectionAuthorizationTest.php`'s "cross-user isolation — scoped query hides other users' rows" section (which covers the sibling `eventbrite`/`youtube` connect endpoints but not this facade).
    - **Technical:** `EventsCatalog::selection()` reads via `$user->integrationConnections()->whereIn('platform', $allPlatforms)...` and `removeCustom()` via `IntegrationConnection::query()->where('user_id', $user->id)...` — both are scoped-by-construction, matching the exact pattern `PlatformConnectionAuthorizationTest.php` documents as safe for the legacy per-platform connect endpoints ("Every endpoint resolves connections through $user->integrationConnections()... the policy's denyAsNotFound() is therefore dormant on these routes"). That precedent, plus `IntegrationConnectionPolicy`'s existing unit coverage, means this is very unlikely to be a live vulnerability — but the `EventsController`/`EventsCatalog` facade is new code (this PR) and has never had its own cross-tenant assertion written, unlike its siblings.
    - **Plain English:** Every test for this new "paste a URL to add an event" feature acts as if there's only one user in the system — none of them check "can User B see or delete User A's events?" The underlying code appears to already guard against this correctly (it always filters by the logged-in user's own ID), the same safe pattern already proven for the older, similar endpoints — but this newer feature has never had that guarantee locked in with its own test.
    - **Evidence:**
        ```php
        // tests/Feature/Platforms/EventsCatalogTest.php — every test uses one user:
        $user = eventsUser('ev1');
        actingAsUser($user)->postJson('/api/platforms/events/add', ['url' => $url])->assertOk();
        // No test creates a second eventsUser() and asserts cross-tenant isolation.
        ```

## P3 — Nice to have

- [ ] **TEST-7** · P3 — `EventSlugSync::PLATFORMS` is a third, uncross-checked source of truth for "which platforms carry events"
    - **Where:** app/Services/Platforms/EventSlugSync.php:31
    - **Affects:** A hypothetical future events platform added to the registry but forgotten in this constant — that platform's events would silently never get slugs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a test (alongside `tests/Feature/Platforms/Registry/RegistryCoverageTest.php`'s "frozen list" pattern) asserting `EventSlugSync::PLATFORMS` matches the set of registry-declared events-type platforms, so widening one without the other fails CI.
    - **Technical:** The class comment states this constant "mirrors `CloudflarePurgeService`'s own string list. Single source of truth for both the sync hook... and the backfill command" — which is itself a third list, not a single source. `RegistryCoverageTest.php` already frozen-lists the full registered-platform set and would need updating if a new events platform were added, giving a natural (but not automatic) forcing function today. All three current entries (`eventbrite`, `humanitix`, `events-custom`) are consistent right now, and there's no active plan to add a fourth events platform, so this is speculative hardening rather than a live gap.
    - **Plain English:** Three different lists in the codebase all need to agree on "which platforms have events" — a shared code comment, a registry, and this constant. Today they agree. If someone adds a new ticketing platform later and updates two of the three lists but not this one, that platform's events would quietly never get pretty URLs, with nothing to flag the mismatch.
    - **Evidence:**
        ```php
        // app/Services/Platforms/EventSlugSync.php
        // The events-platform set. Platform enum has no cases for these (by
        // design — see Platform.php); mirrors CloudflarePurgeService's own
        // string list. Single source of truth for both the sync hook
        // (IntegrationConnectionObserver) and the backfill command.
        public const PLATFORMS = ['eventbrite', 'humanitix', 'events-custom'];
        ```

- [ ] **TEST-8** · P3 — `MenuItemObserver`'s best-effort failure-tolerance is untested
    - **Where:** app/Observers/MenuItemObserver.php:48-59 (`sync`); tests/Unit/Observers/MenuItemObserverTest.php (happy paths only)
    - **Affects:** Any menu-item create/rename if `ItemSlugAllocator` transiently throws — the design promises the item save still succeeds, but nothing proves it.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a test that binds a mock `ItemSlugAllocator` whose `ensureCurrent` throws, then creates/updates a `MenuItem` and asserts the save still succeeds (no exception propagates) and a warning is logged.
    - **Technical:** `MenuItemObserver`'s own docblock states "Slug bookkeeping is best-effort: it must never break a dish write... every hook swallows + logs rather than propagating," and `sync()`/`deleted()` both wrap their allocator calls in `try { ... } catch (Throwable $e) { Log::warning(...); }`. `MenuItemObserverTest.php` covers create/rename/delete happy paths only — no test injects a failure to confirm the swallow-and-log guarantee actually holds, so a future refactor that drops the try/catch would only surface as a production incident (a dish save failing) rather than a CI failure.
    - **Plain English:** Menu-item pretty URLs are a bonus feature — if generating one fails, saving the dish itself should never fail. The code is written to catch and log any such failure rather than blow up the save. No test currently proves that safety net exists, so a future code change could accidentally remove it without anyone noticing until a real outage breaks menu editing.
    - **Evidence:**
        ```php
        // app/Observers/MenuItemObserver.php
        private function sync(MenuItem $item): void
        {
            $owner = $this->ownerId($item);
            if ($owner === '') {
                return;
            }
            try {
                $this->slugs->ensureCurrent($owner, ItemSlugAllocator::TYPE_MENU_ITEM, $item->id, (string) $item->name);
            } catch (Throwable $e) {
                Log::warning('item-slug mint failed', ['menu_item' => $item->id, 'error' => $e->getMessage()]);
            }
        }
        ```

- [ ] **TEST-9** · P3 — No regression test locking in `EventsCatalog::addByUrl`'s idempotency on duplicate URL submission
    - **Where:** app/Services/Platforms/EventsCatalog.php:228-277 (`storeAccount`, `storeStandalone`)
    - **Affects:** A user pasting the same event/organiser link twice — the underlying mechanism is already idempotent, but the guarantee has no regression test.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Add `it('does not create a duplicate connection when the same event URL is pasted twice')` to `EventsCatalogTest.php` — POST the same URL twice and assert exactly one `IntegrationConnection` row exists for that user+platform+resource_id.
    - **Technical:** Both `storeAccount()` and `storeStandalone()` derive a deterministic `resource_id` from the URL/event id (`$rid = 'acct-'.substr(sha1(strtolower(trim($url))), 0, 16)`, or `'event-'.$payload['id']`) and write via `IntegrationConnection::updateOrCreate(['user_id' => ..., 'platform' => ..., 'resource_id' => $rid], ...)` — a second submission of the same URL updates the existing row rather than inserting a new one. The behavior is already correct by construction; this finding is about locking it in, not fixing a bug.
    - **Plain English:** If someone pastes the same event link twice, the code is built so the second paste updates the existing entry instead of creating a duplicate — good design, but nothing currently tests it, so a future change could break that guarantee without anyone noticing.
    - **Evidence:**
        ```php
        // app/Services/Platforms/EventsCatalog.php
        private function writeRow(User $user, string $platform, string $resourceId, array $payload, ?string $resourceKind = null): void
        {
            // ...
            IntegrationConnection::updateOrCreate(
                ['user_id' => $user->id, 'platform' => $platform, 'resource_id' => $resourceId],
                $values,
            );
        }
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Item-slug system structural regression tests:** TEST-1, TEST-2, TEST-7
    - **Why grouped:** all three are additive, low-risk structural/grep-style tests for the recently-shipped item-url-slugs feature and its adjacent architecture-invariant convention — same skillset (Pest test authorship against migration/registry files), no production code changes.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Public controller branch coverage:** TEST-4, TEST-5
    - **Why grouped:** both add missing-branch tests to `PublicIntegrationController`/`PublicMenuController` (Instagram gallery suppression, slug-lookup exception degrade) in the same test files (`PublicIntegrationAllowlistTest.php`, `PublicMenuControllerTest.php`, `PublicEventSlugTest.php`).
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 3 — EventsCatalog edge-case tests:** TEST-3, TEST-9
    - **Why grouped:** same test file (`EventsCatalogTest.php`), same service (`EventsCatalog`), both are additive edge-case tests confirming already-correct behavior (404-on-missing, idempotent-on-duplicate).
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 4 — Observer failure-path test:** TEST-8
    - **Why grouped:** standalone-sized but low-risk; can ride along with Bundle 2 in the same session if capacity allows.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **TEST-6 — EventsCatalog cross-tenant isolation test** · touches authorization/cross-tenant access; per policy, auth-adjacent test-coverage work runs with its own plan + sign-off even though the underlying mechanism already appears safe by construction.
