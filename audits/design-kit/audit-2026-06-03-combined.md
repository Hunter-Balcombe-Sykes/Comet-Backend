`★ Insight ─────────────────────────────────────`
**Core adjudicator discoveries:** (1) TXN-1/TXN-2 are a genuine TOCTOU — `saveQuietly` is called *after* the `lockForUpdate` transaction closes, meaning any Horizon retry or concurrent worker can read `confirmation_sent_at = null` and send a duplicate. This is the most common real-world path: `$tries = 3` guarantees a second send whenever the mail provider hiccups. (2) LIFE-1 and SEC-1 were prematurely checked off in commit `8f992afd` — grep confirms the `getCode() !== '23505'` pattern and the missing `authorizeForUser` calls are both still present in source. (3) The stale-twin bug (CCH-1) is asymmetric: the deleted-user recovery path calls `Cache::forget` on the primary but never touches the `:stale` companion, producing a retry loop for the full stale TTL.
**Pre-merge adjudicator discoveries:** (1) `UpdateSiteAction` contains ~150 lines of branching subdomain/alias/handle/publish logic wrapped in a `DB::transaction`, with zero test coverage — a regression here silently ships broken renames for every professional. (2) The legacy `PublicSiteController` / `buildPayloadFromDb()` path is still routed and still emits a `'theme' => null` key after the skeleton-system cleanup. (3) Three config values (`queue name`, `SUBDOMAIN_COOLDOWN_DAYS`, default `skeleton-1`) are hardcoded where siblings are already config-driven.
**Cross-bundle dedup:** Pre-merge MIG-3 ("several design-kit migrations missing IF [NOT] EXISTS guards") is fully superseded by core SCHEMA-3 + SCHEMA-4, which address the same two unguarded migrations (`20260529044737`, `20260529053028`) with more precision. The two earlier migrations named in MIG-3 (`20260527150000`, `20260527170000`) were covered by the previous audit cycle's MIG-5 (marked complete). MIG-3 is dropped.
`─────────────────────────────────────────────────`

# Design Kit Audit — 2026-06-03

**Branch:** development
**Lens:** Combined bundle — core 8-lens (SEC/LIFE/CCH/CACHE/SCHEMA/TXN) + pre-merge 4-lens (MIG/API/CFG/TEST)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- supabase/migrations/20260527070000_skeleton_system_cleanup.sql
- supabase/migrations/20260527080000_design_kit_initial_vars.sql
- supabase/migrations/20260527090000_design_kit_layout_vars.sql
- supabase/migrations/20260527100000_design_kit_expanded_vars.sql
- supabase/migrations/20260527110000_design_kit_derived_default_vars.sql
- supabase/migrations/20260527120000_design_kit_bg_image_toggle.sql
- supabase/migrations/20260527130000_design_kit_row_motion_vars.sql
- supabase/migrations/20260527140000_design_kit_responsive_vars.sql
- supabase/migrations/20260527150000_design_kit_header_height.sql
- supabase/migrations/20260527170000_design_kit_typography_uppercase.sql
- supabase/migrations/20260528030000_drop_design_kit_bg_image.sql
- supabase/migrations/20260528090000_drop_design_kit_row_height.sql
- supabase/migrations/20260529044737_design_kit_contrasting_colors.sql
- supabase/migrations/20260529053028_design_kit_unified_space_scale.sql
- supabase/migrations/20260530100000_design_kit_heading_scale.sql
- supabase/migrations/20260530110000_design_kit_icon_xl_overlay_opacity.sql
- supabase/migrations/20260530120000_design_kit_icon_xxl.sql
- supabase/migrations/20260530130000_design_kit_icon_stroke_widths.sql
- supabase/migrations/20260602000000_design_kits_rls.sql
- supabase/migrations/20260602010000_design_kit_trigger_on_conflict.sql
- supabase/migrations/20260603000001_drop_orphan_design_kit_typography_cols.sql
- app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php
- app/Http/Controllers/Api/Staff/StaffSite/StaffSiteController.php
- app/Http/Controllers/Api/PublicSite/IndividualProfileController.php
- app/Http/Requests/Api/User/Site/UpdateSiteRequest.php
- app/Http/Requests/Api/Staff/UserSite/StaffUpdateSiteRequest.php
- app/Http/Resources/SiteResource.php
- app/Http/Resources/PublicSite/IndividualProfileResource.php
- app/Services/PublicSite/IndividualProfilePayloadBuilder.php
- app/Services/Cache/CacheKeyGenerator.php
- app/Services/Cache/SiteCacheService.php
- app/Services/Site/UpdateSiteAction.php
- app/Models/Core/Site/Site.php
- app/Observers/Core/SiteObserver.php
- app/Policies/SitePolicy.php
- app/Mail/Branding/EmailBrand.php
- app/Mail/Branding/EmailBrandDefaults.php
- app/Mail/Branding/EmailPalette.php
- app/Mail/Branding/ProEmailBrandResolver.php
- app/Jobs/Notifications/SendEnquiryConfirmationJob.php
- app/Jobs/Notifications/SendSubscriptionConfirmationJob.php
- resources/views/mail/layouts/partna.blade.php
- tests/Feature/Api/PublicSite/IndividualProfileControllerTest.php
- tests/Feature/Api/User/SiteManagement/WriteDesignKitTest.php
- tests/Feature/Cache/DesignKitCacheInvalidationTest.php
- tests/Feature/Database/SkeletonSystemConstraintsTest.php
- tests/Feature/Notifications/DesignKitWriteInvalidatesBrandTest.php
- tests/Feature/Notifications/ProEmailBrandResolverTest.php
- tests/Feature/Notifications/SendEnquiryConfirmationJobBrandTest.php
- tests/Feature/Notifications/SendSubscriptionConfirmationJobBrandTest.php
- tests/Feature/Requests/DesignKitRequestDriftTest.php
- tests/Unit/Requests/DesignKitRequestSyncTest.php
- config/partna.php *(adjudicator read)*
- app/Http/Controllers/Api/PublicSite/PublicSiteController.php *(adjudicator read)*

**Dropped findings (with reason):**
- **MIG-3** (pre-merge draft) — superseded by **SCHEMA-3** + **SCHEMA-4** (core), which cover the same two affected migrations (`20260529044737`, `20260529053028`) with greater precision. The two earlier migrations named in MIG-3 (`20260527150000`, `20260527170000`) were addressed in the previous audit cycle's MIG-5.

---

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 3 of 3 complete
- P2 Medium: 9 of 10 complete (API-2 partial — safe subset only, see note)
- P3 Low: 15 of 15 complete

---

## P1 — Fix before pilot launch

- [x] **#TXN-1** · P1 · S — Double-send window: `confirmation_sent_at` flag written outside the idempotency lock in `SendEnquiryConfirmationJob`
    - **Where:** app/Jobs/Notifications/SendEnquiryConfirmationJob.php:56–68 (transaction) and :106 (saveQuietly)
    - **Affects:** Visitors who submit contact forms. Any job retry — or two Horizon workers racing on the same job — can both read `confirmation_sent_at = null` and each send a confirmation email. With `$tries = 3` and `$backoff = [30, 90, 180]`, a mail-send failure on attempt 1 leaves the flag unset and guarantees a second send on attempt 2.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move `$enquiry->forceFill(['confirmation_sent_at' => now()])->saveQuietly()` **inside** the `DB::transaction` closure, immediately after the `confirmation_sent_at !== null` guard, before the `return $e`.
        - Keep `Mail::to(...)->send(...)` **outside** the transaction — mail send must never block the lock.
        - After the move, if `Mail::send` throws on a retry attempt, the idempotency flag is already set, so the job correctly skips re-sending rather than re-sending.
    - **Technical:** The `lockForUpdate` acquires a row lock, reads `confirmation_sent_at`, then returns the model. The transaction ends — lock released — before the column is written. A second worker can acquire the lock during that window, see `null`, and also proceed to send. This is a classic TOCTOU gap. Laravel Horizon runs multiple workers per queue by default, and the job's `$tries = 3` makes the retry path the most common failure scenario. The fix atomically stamps the flag under the lock so any concurrent read sees the committed write before proceeding.
    - **Plain English:** Two staff members check the same notepad simultaneously. Both see the "sent?" box is empty. Both head to the mailbox and send a duplicate letter. The fix: one person ticks the box while still at the desk, so the second person sees "already sent" the moment they look. The mailing happens after the box is ticked — if the mail fails, the retry knows to skip it.
    - **Evidence:**
        ```php
        // Lines 56-68: lock + check inside transaction — lock released on return
        $enquiry = DB::transaction(function () {
            $e = Enquiry::query()->lockForUpdate()->find($this->enquiryId);
            if ($e === null) {
                return null;
            }
            if ($e->confirmation_sent_at !== null) {
                return false;
            }
            return $e;  // ← lock released here, flag still null
        });
        ```
        ```php
        // Line 106: state mutation outside the lock
        $enquiry->forceFill(['confirmation_sent_at' => now()])->saveQuietly();
        ```

- [x] **#TXN-2** · P1 · S — Double-send window: `confirmation_sent_at` flag written outside the idempotency lock in `SendSubscriptionConfirmationJob`
    - **Where:** app/Jobs/Notifications/SendSubscriptionConfirmationJob.php:56–68 (transaction) and :124 (saveQuietly)
    - **Affects:** Visitors who subscribe to newsletters. Identical race path to TXN-1 — any retry or concurrent worker can send two "you're subscribed" confirmations to the same email address.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move `$sub->forceFill(['confirmation_sent_at' => now()])->saveQuietly()` **inside** the `DB::transaction` closure, immediately after the `confirmation_sent_at !== null` guard, before the `return $s`.
        - Keep `Mail::to(...)->send(...)` outside the transaction.
    - **Technical:** Structurally identical to TXN-1. The same `lockForUpdate` → transaction-end → flag-write gap exists. The unsubscribe check (`$sub->status !== 'subscribed'`) runs after the transaction and is also subject to the same race — both workers can pass the status check before either writes the flag. Fix approach is identical: move `saveQuietly` inside the transaction, send mail after.
    - **Plain English:** Same double-send scenario as TXN-1, just for newsletter sign-ups instead of enquiries. Tick the box before leaving the desk.
    - **Evidence:**
        ```php
        // Lines 56-68: lock + check inside transaction
        $sub = DB::transaction(function () {
            $s = EmailSubscription::query()->lockForUpdate()->find($this->subscriptionId);
            if ($s === null) {
                return null;
            }
            if ($s->confirmation_sent_at !== null) {
                return false;
            }
            return $s;  // ← lock released, flag still null
        });
        ```
        ```php
        // Line 124: state mutation outside the lock
        $sub->forceFill(['confirmation_sent_at' => now()])->saveQuietly();
        ```

- [x] **#TEST-1** · P1 · M — `UpdateSiteAction` has no test coverage despite containing the most critical business logic in the codebase
    - **Where:** app/Services/Site/UpdateSiteAction.php (entire class)
    - **Affects:** Every user who changes a subdomain, publishes their site, or renames their handle. A regression silently breaks subdomain assignment, orphans alias rows, skips cooldown enforcement, or allows publication of incomplete profiles — all without a test catching it.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `tests/Feature/Services/UpdateSiteActionTest.php` covering: happy-path subdomain rename with alias row created, cooldown enforcement (re-rename blocked before 30 days), collision with an existing subdomain in `site.sites` and `site.site_subdomain_aliases`, handle sync on rename (professional.handle updated, `UserHandleAlias` row created), rename-back collapse (alias deleted when renaming to a previously held subdomain), publish validation (missing `display_name` rejected), and settings merge preserving unknown keys.
        - Assert database state after each case — alias row created, `HandleChangeLog` row inserted, `subdomain_changed_at` advanced.
        - The cooldown and collision guards each have a second DB query that bypasses the FormRequest layer; the test must drive the action directly, not through HTTP.
    - **Technical:** `UpdateSiteAction::execute()` contains ~150 lines of branching subdomain/alias/handle/publish business logic inside a `DB::transaction`. No existing test touches it. The design-kit tests drive `PATCH /api/site` but sidestep subdomain mutations entirely. A regression in the cooldown branch (e.g., a future refactor drops `$site->subdomain_changed_at = now()`) silently ships — no CI failure.
    - **Plain English:** The code that handles changing your web address (`yourname.partna.au`) has never had an automated test run against it. It's the most complex piece of logic in the whole system — cooldown timers, checking for conflicts, logging every rename, keeping aliases in sync — and it works only because it was written carefully, not because any automated check verifies it. One careless refactor and users start getting 500 errors or end up on someone else's subdomain.
    - **Evidence:**
        ```php
        public const SUBDOMAIN_COOLDOWN_DAYS = 30;

        public function execute(User $professional, array $data, array $options = []): Site
        {
            $professional->loadMissing('site');
            // ... 150 lines of branching subdomain/alias/handle/publish logic
            return DB::transaction(function () use ($professional, $site, $data, ...) {
                if (array_key_exists('subdomain', $data)) {
                    // cooldown check
                    // conflict checks in sites + aliases + user_handle_aliases
                    // SiteSubdomainAlias::create()
                    // UserHandleAlias::create()
                    // HandleChangeLog::create()
                }
                // settings merge, publish guard, $site->save()
            });
        }
        ```

---

## P2 — Should fix

- [x] **#SCHEMA-1** · P2 · S — `writeDesignKit()` silently discards values when no `site.design_kits` row exists
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php:274–284 (`writeDesignKit` private method)
    - **Affects:** Professionals updating design settings for a site whose `design_kits` row is missing (backfill race on sites created before migration `20260527070000`, trigger bypass via `session_replication_role = 'replica'` or `pg_restore`, or manual DB operations). The dashboard returns HTTP 200 with no indication the write was a no-op.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the `->where('site_id', $siteId)->update($valid)` call with `DB::connection('pgsql')->table('site.design_kits')->updateOrInsert(['site_id' => $siteId], $valid)`.
        - Alternatively, check the affected-row count from `->update()` and log a `Log::warning` with `site_id` context when it returns 0, so Nightwatch surfaces the missing-row case rather than silently succeeding.
    - **Technical:** `lockForUpdate()->get()` on an empty result set acquires no row lock and returns an empty collection. The subsequent `->update($valid)` targets zero rows — PostgreSQL returns success for a valid UPDATE that matches nothing. The `trg_create_empty_design_kit` trigger (with `ON CONFLICT DO NOTHING`) guarantees a row for every site created through normal application flow, but the migration comment explicitly notes that pre-cleanup sites relied on a separate backfill step that "may have missed rows." For those sites, every design kit save silently discards the user's data.
    - **Plain English:** The system files new design settings into the right folder, but if the folder was never created for a particular user, the settings go in the bin with no error. New users are fine — the system auto-creates the folder. But users from before the June migration who missed the backfill step would see their colour and font choices silently vanish every time they save.
    - **Evidence:**
        ```php
        DB::connection('pgsql')->transaction(function () use ($siteId, $valid): void {
            DB::connection('pgsql')
                ->table('site.design_kits')
                ->where('site_id', $siteId)
                ->lockForUpdate()
                ->get(); // acquire the lock before writing

            DB::connection('pgsql')
                ->table('site.design_kits')
                ->where('site_id', $siteId)
                ->update($valid);
        });
        ```

- [x] **#LIFE-1** · P2 · S — `UpdateSiteAction` catches generic `QueryException` and string-compares SQLSTATE codes instead of `UniqueConstraintViolationException`
    - **Where:** app/Services/Site/UpdateSiteAction.php:103–105, 136–138, 216–219 (three catch blocks)
    - **Affects:** The subdomain rename flow — alias creation, handle alias creation, and the final site save unique guard. Note: commit `8f992afd docs: check off LIFE-1, SEC-1, SEC-2 in core audit` marked this done, but grep confirms the `getCode() !== '23505'` pattern is still present on all three lines as of the current source.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace all three `catch (QueryException $e)` blocks with `catch (\Illuminate\Database\UniqueConstraintViolationException $e)` (available since Laravel 10).
        - Remove the `if ($e->getCode() !== '23505') { throw $e; }` guards — the typed catch handles only unique violations by construction.
        - Keep the fallback logic (alias timestamp refresh, ValidationException re-throw) inside the typed catch.
    - **Technical:** `QueryException::getCode()` returns the SQLSTATE as a string. Comparing it to `'23505'` works today but has two latent risks: (1) a new unique constraint added to a related table that fires unexpectedly inside the transaction would be silently caught rather than surfaced; (2) the check is fragile to any driver-layer change in SQLSTATE formatting. `UniqueConstraintViolationException` is a typed subclass raised specifically for 23505 violations. The `8f992afd` commit was a docs-only check-off; no fix commit is present in git log.
    - **Plain English:** There are three catch-alls in the rename flow that answer every database error with "is this a duplicate name?" and re-throw anything else. This works, but it's like using a Swiss Army knife for surgery — it does the job while being exactly the wrong tool. Swapping in the purpose-built exception type takes one line per block and documents the intent clearly.
    - **Evidence:**
        ```php
        // SiteSubdomainAlias create (line 103–105)
        } catch (QueryException $e) {
            if ($e->getCode() !== '23505') {
                throw $e;
            }

        // UserHandleAlias create (line 136–138)
        } catch (QueryException $e) {
            if ($e->getCode() !== '23505') {
                throw $e;
            }

        // Final site save (line 216–219)
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
        ```

- [x] **#LIFE-2** · P2 · S — `design_kits:columns` cache key invalidated only by full `artisan cache:clear`; no version token ties bust to the migration that changes the column set
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php:260–266 (`writeDesignKit` private method)
    - **Affects:** Professionals attempting to save a newly shipped design kit variable in the first hour after a deploy. If the deploy script's `artisan cache:clear` fails, is skipped, or runs before the migration lands, `array_intersect_key` silently drops all keys for the new column. The dashboard returns 200 but nothing is written. Also: `cache:clear` is a blunt instrument — it cold-starts all site payload, email-brand, and block caches unnecessarily to bust a single metadata key.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `design_kit_columns_version` integer to `config/partna.php` (or an env var). Suffix the cache key: `'design_kits:columns:v'.config('partna.design_kit_columns_version')`.
        - Bump the version in the same PR as any migration that adds or drops a `site.design_kits` column. The old key orphans and TTLs out; no `cache:clear` needed.
        - Alternatively, replace the runtime `information_schema` query with a static config array generated at deploy time — eliminates the cache key entirely.
    - **Technical:** The cache key `'design_kits:columns'` has a 1-hour TTL. It is invalidated only by `artisan cache:clear` in the deploy script — a side-effect decoupled from the migration. The column list is deploy-time stable: it changes only when a `supabase/migrations/*design_kit*.sql` migration runs. Using a version token (the `analyticsSummaryVersion` pattern already in `CacheKeyGenerator`) lets a migration PR bump the suffix and bust only this one key, leaving hot public-profile payloads, email-brand bundles, and block caches warm through the deploy.
    - **Plain English:** Every time the design system adds a new colour option, the app has a sticky note listing available colours that expires in an hour. The only way to update the sticky note is to wipe every whiteboard in the building. Putting a version number on the sticky note means only that note gets replaced when the design system changes — all other whiteboards stay as they are.
    - **Evidence:**
        ```php
        // Column list is deploy-time stable; cache for 1 h so each save doesn't
        // pay an extra metadata round-trip. Busted by `artisan cache:clear`
        // in the deploy script whenever a design_kit migration adds/drops columns.
        $columns = Cache::remember('design_kits:columns', 3600, fn () => DB::connection('pgsql')
            ->table('information_schema.columns')
            ->where('table_schema', 'site')
            ->where('table_name', 'design_kits')
            ->pluck('column_name')
            ->all()
        );
        ```

- [x] **#CCH-1** · P2 · S — `handle.resolve` stale twin not cleared in the deleted-user recovery path
    - **Where:** app/Http/Controllers/Api/PublicSite/IndividualProfileController.php:106 (`$payload === null` branch)
    - **Affects:** Public profile requests after a professional account is deleted. `CacheLockService::rememberLocked` writes both a primary key (`handle.resolve:{handle}`) and a `:stale` twin at 10× the primary TTL (~5 minutes for a 30s primary). The recovery path clears only the primary. The stale twin continues serving the deleted-user's `pro_id` to subsequent requests until its TTL expires, causing repeated cache misses → builder calls → `null` payloads → 404s on every request during that window.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change the single `Cache::forget` to a `Cache::deleteMultiple`:
          ```php
          Cache::deleteMultiple([
              CacheKeyGenerator::handleResolve($handleLc),
              CacheKeyGenerator::handleResolve($handleLc) . ':stale',
          ]);
          ```
        - The same pattern is already used in `SiteCacheService::invalidateSitePayload` for other resolve keys.
    - **Technical:** `CacheLockService::rememberLocked` writes a primary entry with jittered TTL and a `:stale` companion at `STALE_TTL_MULTIPLIER × primary TTL`. If the primary is cleared without clearing the `:stale` twin, the SWR path resurrects the stale resolve entry on the next request, re-triggers the same null-payload path, clears the primary again — and repeats for the full stale TTL. The pattern is symmetrically handled in `invalidateSitePayload` with `bustWithStale()` but was missed in this one controller recovery path.
    - **Plain English:** When the system detects a cached entry points to a deleted account, it clears the main copy but leaves the backup copy intact. The next request fetches from the backup, sees the deleted account, throws away the main copy again — and this loop continues for up to five minutes. Deleting both copies at once stops the loop immediately.
    - **Evidence:**
        ```php
        if ($payload === null) {
            // Resolve cache pointed at a now-deleted row. Forget the resolve
            // entry so the next request rebuilds from scratch.
            Cache::forget(CacheKeyGenerator::handleResolve($handleLc));
            $this->logIfSlow($handleLc, '404-deleted-race', $startedAt);
            return $this->error('Not found.', 404);
        }
        ```

- [x] **#SEC-1** · P2 · M — `UserSiteController::update()` and `::visibility()` never call `authorizeForUser`; inline ownership resolution bypasses the Policy layer
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php:33–55 (`update` and `visibility` methods)
    - **Affects:** Site mutation endpoints from the professional dashboard. `SitePolicy` exists (`app/Policies/SitePolicy.php`) and has an `update` method with `denyIfPendingDeletion` and ownership checks, but neither controller method calls it. Note: commit `8f992afd docs: check off LIFE-1, SEC-1, SEC-2 in core audit` marked this done, but grep confirms no `authorizeForUser` call exists in the controller.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - In `UserSiteController::update()`, resolve the site before calling `action->execute()` and add `$this->authorizeForUser($professional, 'update', $site)`.
        - In `UserSiteController::visibility()`, do the same.
        - The site can be resolved via `$professional->loadMissing('site')->site` so the Policy check receives a concrete model instance and `denyIfPendingDeletion` can fire.
        - Remove the inline ownership check from `UpdateSiteAction::execute()` (the `if (! $site) { throw ValidationException... }` guard remains as belt-and-suspenders, but ownership enforcement moves to the Policy).
    - **Technical:** The Authorization Doctrine requires `$this->authorizeForUser($pro, 'verb', $resource)` for every mutating endpoint. `UpdateSiteAction::execute()` resolves `$professional->site` via Eloquent relationship traversal — functionally correct for the one-site-per-professional model, but it bypasses `SitePolicy::update()`, which means `denyIfPendingDeletion` never fires for the dashboard update path. `SitePolicy` is already registered and has the correct implementation — the controller just needs to call it.
    - **Plain English:** Every door in the building has a lock, and there's a master key card system that logs who opens what. The design settings door happens to be left slightly ajar because the key card system was never wired up — it works because only the right people know where the door is, but there's no log of who enters and the "account being deleted" warning sign is never checked.
    - **Evidence:**
        ```php
        // UserSiteController.php:33–38 — no authorizeForUser before mutation
        public function update(UpdateSiteRequest $request, UpdateSiteAction $action)
        {
            $professional = $this->currentUser($request);
            $data = $request->validated();
            // design_kit extraction ...
            $site = $action->execute($professional, $data);
        ```
        ```php
        // SitePolicy.php exists and has the correct update() method:
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

- [x] **#TEST-4** · P2 · M — `UpdateSiteRequest` and `StaffUpdateSiteRequest` validation rules are structurally tested but functionally untested (no 422 on bad input)
    - **Where:** app/Http/Requests/Api/User/Site/UpdateSiteRequest.php; app/Http/Requests/Api/Staff/UserSite/StaffUpdateSiteRequest.php
    - **Affects:** Dashboard users and staff submitting invalid design-kit values (non-hex colours, unknown skeleton IDs, reserved subdomains). A silently dropped validation rule lets malformed data pass through; the structural drift test (`DesignKitRequestDriftTest`) would not catch it because both the column and the rule would be absent simultaneously.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a test that hits `PATCH /api/site` with each of these invalid payloads and asserts HTTP 422 with the correct error key: a colour value like `'red'` (not hex), `skeleton_id: 'skeleton-9'`, `subdomain: 'api'` (reserved), and a subdomain with uppercase characters.
        - Duplicate the pattern for the staff endpoint (`PATCH /api/staff/sites/{professional}`).
        - The hex regex rule and the reserved-subdomain closure are only exercised when the Laravel validation pipeline is actually invoked; the structural drift test never fires the engine.
    - **Technical:** `DesignKitRequestDriftTest` verifies that every `site.design_kits` column has a matching rule key in the request class. It does this by comparing string sets — it does not call the validator. A developer could accidentally replace the `'regex:/^#[0-9a-fA-F]{3,8}$/'` rule with `'string'` and the structural test would still pass (the key is still there) while arbitrary CSS values flow into the database and into inline styles on the public profile page.
    - **Plain English:** We have a system that checks the lock is still on the door, but we've never actually tried opening it with the wrong key to see if it says no. The structural check confirms the lock mechanism exists; a functional test would confirm it actually locks.
    - **Evidence:**
        ```php
        // app/Http/Requests/Api/User/Site/UpdateSiteRequest.php
        'design_kit.color_accent' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
        'skeleton_id' => ['sometimes', 'string', Rule::in(self::ALLOWED_SKELETONS)],
        // … plus closure-based reserved-word and uniqueness checks
        ```

- [x] **#TEST-3** · P2 · S — Rate-limit early-return path untested in both confirmation job classes
    - **Where:** app/Jobs/Notifications/SendEnquiryConfirmationJob.php (`withinRateLimit`); app/Jobs/Notifications/SendSubscriptionConfirmationJob.php (`withinRateLimit`)
    - **Affects:** Visitors submitting multiple enquiries or subscriptions. If the rate limit fires, the job silently returns without sending — no mail, no error, no `confirmation_sent_at` stamp. No test verifies this path, so a misconfigured limit (e.g. `partna.throttle.visitor_confirmation_per_hour` accidentally set to 0) would suppress all outbound confirmations silently.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - For the enquiry confirmation job: add a test that mocks `RateLimiter::tooManyAttempts` to return `true`, dispatches the job, and asserts no email was sent and a `Log::warning` was written.
        - Apply the same pattern to `SendSubscriptionConfirmationJob`.
    - **Technical:** Both jobs call `withinRateLimit($recipient)` before the mail send. If it returns `false`, the method returns early without updating `confirmation_sent_at`. The existing brand tests exercise the happy path only — they never set the rate limiter to exhausted.
    - **Plain English:** The jobs have a built-in safety valve that says "don't send more than N emails per hour to the same address." But the tests always run with the valve wide open. If someone accidentally cranks the limit to zero in the settings, all confirmation emails stop — silently.
    - **Evidence:**
        ```php
        // app/Jobs/Notifications/SendEnquiryConfirmationJob.php
        if (! $this->withinRateLimit($recipient)) {
            return;
        }
        ```

- [x] **#TEST-2** · P2 · M — `writeDesignKit()` `lockForUpdate` concurrency guard is a no-op under SQLite and has no real-Postgres race test
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php (`writeDesignKit`)
    - **Affects:** Users editing a site's design kit from two browser tabs simultaneously. Without a verified lock, two writes with disjoint column sets can overwrite each other — one request sets `color_accent`, another sets `typography_font_family`, and the second write could silently wipe the first's colour.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a PostgreSQL-only integration test (tagged `@group pgsql`) that dispatches two concurrent `writeDesignKit` calls with disjoint column payloads and asserts that after both commits, both columns contain their requested values.
        - The existing `WriteDesignKitTest.php` explicitly documents this gap ("the concurrency invariant would need a real-Postgres test to verify") — this finding formalises the gap as a tracked item.
    - **Technical:** `writeDesignKit()` wraps its `SELECT … FOR UPDATE` and `UPDATE` in a `DB::connection('pgsql')->transaction()`. Under SQLite (the test environment), `lockForUpdate()` compiles to a no-op `SELECT` with no locking semantics. The fix landed in commit `3e3cc768` but no test exercises the invariant the fix is meant to enforce.
    - **Plain English:** We added a "take turns" system to prevent one person's edits from wiping the other's, but we only tested it on a training dummy that doesn't actually take turns. Until we test it on the real system, we can't be sure the "take turns" rule actually holds when it matters.
    - **Evidence:**
        ```php
        DB::connection('pgsql')->transaction(function () use ($siteId, $valid): void {
            DB::connection('pgsql')
                ->table('site.design_kits')
                ->where('site_id', $siteId)
                ->lockForUpdate()
                ->get(); // acquire the lock before writing

            DB::connection('pgsql')
                ->table('site.design_kits')
                ->where('site_id', $siteId)
                ->update($valid);
        });
        ```

- [ ] **#API-2** · P2 · M — Legacy `PublicSiteController` is still live and bypasses the Resource layer, exposing a dead `theme` key
    - **Status (2026-06-03 — partial, safe subset done):** Dead `'theme'` key removed from `buildPayloadFromDb()` + a LEGACY PATH NOTE comment added (commit `fix(audit-api2)`). Routes left registered — full decommission of `GET /api/public/site[-by-slug]` is DEFERRED pending confirmation from the external Astro front-end (`partna-pages`) / Worker and any mobile clients that they no longer consume these routes. Re-open this item when that coordination lands.
    - **Where:** app/Services/Cache/SiteCacheService.php (`buildPayloadFromDb`); app/Http/Controllers/Api/PublicSite/PublicSiteController.php
    - **Affects:** Any consumer of `GET /api/public/site` (subdomain header path) or `GET /api/public/site-by-slug`. Both routes are still registered and serve traffic through `buildPayloadFromDb()`, which assembles a raw associative array including a `'theme' => null` key (the skeleton-system cleanup removed theme data from the view but left the key in the builder). Consumers receive a payload that bypasses Resource allowlisting.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Determine whether `PublicSiteController` + `SiteCacheService::getPublicSitePayload()` / `buildPayloadFromDb()` are still needed. If the Cloudflare Worker and Astro front-end have migrated to `GET /api/public/profiles/{handle}`, decommission the old routes and mark `buildPayloadFromDb()` as `@deprecated`.
        - If the legacy path still serves active consumers: remove the `'theme' => $payload['theme'] ?? null` key from `buildPayloadFromDb()` since `site.public_site_payload` no longer contains theme data.
        - In either case, add a comment explaining which controller still consumes it and why, so the next engineer does not assume it is safe to remove.
    - **Technical:** The new `IndividualProfileController` → `IndividualProfilePayloadBuilder` → `IndividualProfileResource` path enforces field allowlisting and clean camelCase wire shape. `buildPayloadFromDb()` predates this and hand-assembles an array from the `PublicSitePayload` DB view. After the skeleton-system migration, that view no longer exposes theme data — but the builder still emits `'theme' => $payload['theme'] ?? null`, a stale key that null-fills on every response.
    - **Plain English:** The old "serve this professional's public page" system is still running in parallel with the new one. The new system has a proper filter that inspects every field before it goes out. The old system loads a box of data and ships it raw, including an empty label marked "theme" that hasn't meant anything since the cleanup.
    - **Evidence:**
        ```php
        // SiteCacheService::buildPayloadFromDb()
        $data = [
            'published' => true,
            'site' => $site,
            'professional' => $payload['professional'] ?? null,
            'theme' => $payload['theme'] ?? null,   // dead: view no longer has theme data
            'services' => $services,
            'links' => $links,
            'sections' => $sections,
            'blocks' => $this->buildCombinedBlocksPayload($links, $sections, $existingBlocks),
            'legal' => $payload['legal'] ?? null,
        ];
        ```

- [x] **#MIG-1** · P2 · S — `skeleton_id` CHECK constraint added without `NOT VALID`, causing a full table scan under ACCESS EXCLUSIVE lock during the production deploy
    - **Where:** supabase/migrations/20260527070000_skeleton_system_cleanup.sql
    - **Affects:** All site writes (dashboard saves, publish toggles, subdomain changes) are blocked for the duration of the table scan during the production migration run. Pre-pilot the table is small and the lock is brief, but the pattern should be corrected before the table grows.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a follow-up migration that validates the constraint using `ALTER TABLE site.sites VALIDATE CONSTRAINT sites_skeleton_id_check`. This is safe to run and validates existing rows under a `SHARE UPDATE EXCLUSIVE` lock instead of `ACCESS EXCLUSIVE`.
        - For future DDL: adopt the two-step pattern — `ADD CONSTRAINT … NOT VALID` first, then a separate `VALIDATE CONSTRAINT` migration — for any constraint added to a table with existing rows.
    - **Technical:** PostgreSQL validates CHECK constraints inline when added to a column with an existing DEFAULT value. The validation runs under `ACCESS EXCLUSIVE`, blocking all reads and writes. The two-step `NOT VALID` + `VALIDATE CONSTRAINT` pattern avoids the strong lock: the constraint applies to future writes immediately, and validation of existing rows runs under the weaker `SHARE UPDATE EXCLUSIVE` lock. The `SkeletonSystemConstraintsTest` confirms the constraint exists and `convalidated = true` on development; the concern is specifically the lock duration on the production push.
    - **Plain English:** When this migration runs on the production database, Postgres locks the entire "sites" table while it checks every existing row against the new rule — even though the rule can never fail (every row already has the default value). A two-step approach lets the check happen quietly in the background without locking anyone out.
    - **Evidence:**
        ```sql
        -- supabase/migrations/20260527070000_skeleton_system_cleanup.sql
        ALTER TABLE site.sites
          DROP COLUMN theme_id,
          ADD COLUMN skeleton_id TEXT NOT NULL
            DEFAULT 'skeleton-1'
            CHECK (skeleton_id IN ('skeleton-1','skeleton-2','skeleton-3','skeleton-4'));
        ```

---

## P3 — Nice to have

- [x] **#SCHEMA-2** · P3 · S — `site.design_kits` table has no `created_at` / `updated_at` columns
    - **Where:** supabase/migrations/20260527070000_skeleton_system_cleanup.sql:116–118 (`CREATE TABLE site.design_kits`)
    - **Affects:** Internal debugging and future audit features. Without timestamps, there's no way to answer "when did this professional last change their design kit?" from the database alone. The parent `site.sites.updated_at` is touched by the controller after a kit-only write, but it's imprecise and lost entirely if a direct DB write occurs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a new migration: `ALTER TABLE site.design_kits ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ NOT NULL DEFAULT now(), ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT now();`
        - Backfill: `UPDATE site.design_kits dk SET created_at = s.created_at, updated_at = s.updated_at FROM site.sites s WHERE s.id = dk.site_id;`
        - Add an `AFTER UPDATE` trigger to set `updated_at = now()` on every row update, since `writeDesignKit` uses raw `DB::table` queries (no Eloquent timestamps).
    - **Technical:** All other site-schema tables (`site.sites`, `site.blocks`, `site.services`, `site.site_media`) have `created_at` / `updated_at`. `site.design_kits` is the only one without them. The `writeDesignKit` method uses raw `DB::connection('pgsql')->table(...)` which never sets Eloquent's automatic timestamp columns, so a trigger is the correct approach.
    - **Plain English:** Every other record in the system has a "created on" and "last updated" stamp. The design settings table is missing this, so you can't tell from the database alone when someone last changed their brand colours.
    - **Evidence:**
        ```sql
        CREATE TABLE site.design_kits (
          site_id UUID PRIMARY KEY REFERENCES site.sites(id) ON DELETE CASCADE
        );
        ```

- [x] **#SCHEMA-3** · P3 · S — Migration `20260529044737` adds columns without `IF NOT EXISTS`, breaking idempotent re-run
    - **Where:** supabase/migrations/20260529044737_design_kit_contrasting_colors.sql:23–25
    - **Affects:** Deployment recovery — re-running this migration (disaster-restore, branch switch, partial-apply rollback) throws `ERROR: column "color_contrasting_bg" of relation "design_kits" already exists` and halts.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `IF NOT EXISTS` to each `ADD COLUMN` clause.
        - Note: later migrations (`20260530100000`, `20260530110000`, etc.) all use `IF NOT EXISTS` correctly — this one is the outlier.
    - **Technical:** `ALTER TABLE … ADD COLUMN` without `IF NOT EXISTS` throws if the column already exists. For a disaster-recovery scenario (restore a backup and replay migrations), the migration cannot distinguish "already applied" from "never applied."
    - **Plain English:** This change file is safe the first time it runs but fails with an error if it ever needs to run again. Adding "if it's not already there" to each column addition makes it safe to replay in an emergency restore.
    - **Evidence:**
        ```sql
        ALTER TABLE site.design_kits
          ADD COLUMN color_contrasting_bg TEXT NULL,
          ADD COLUMN color_contrasting_text TEXT NULL,
          ADD COLUMN color_placeholder TEXT NULL;
        ```

- [x] **#SCHEMA-4** · P3 · S — Migration `20260529053028` drops and adds 34 columns without `IF EXISTS` / `IF NOT EXISTS` guards
    - **Where:** supabase/migrations/20260529053028_design_kit_unified_space_scale.sql:52–80
    - **Affects:** Same as SCHEMA-3 — idempotent re-run fails. Higher blast radius: 24 `DROP COLUMN` (without `IF EXISTS`) and 10 `ADD COLUMN` (without `IF NOT EXISTS`) in a single `ALTER TABLE` statement. Any one already-present or already-absent column causes the entire atomic statement to fail.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `IF EXISTS` to every `DROP COLUMN` clause.
        - Add `IF NOT EXISTS` to every `ADD COLUMN` clause.
        - Migration `20260530100000` handles similar operations with `DO $$ BEGIN IF EXISTS(...) THEN ... END IF; END $$;` blocks — apply the same approach.
    - **Technical:** Atomicity of the single `ALTER TABLE` statement is a feature — it prevents partial column states in a normal deploy. But the lack of idempotency guards means a restore-and-replay fails on the first column mismatch.
    - **Plain English:** This file makes 34 column changes in one shot. If it's ever replayed and even one column is already in the right state, the entire operation refuses to run. Adding "if it's there" and "if it's not there" checks to each step makes it safe to replay in any state.
    - **Evidence:**
        ```sql
        ALTER TABLE site.design_kits
          DROP COLUMN padding_extra_small,
          DROP COLUMN padding_small,
          …
          ADD COLUMN space_xs TEXT NULL,
          ADD COLUMN space_s TEXT NULL,
          …;
        ```

- [x] **#LIFE-3** · P3 · M — `Log::warning` calls in request classes and notification jobs lack tenant correlation context
    - **Where:** app/Http/Requests/Api/User/Site/UpdateSiteRequest.php (alias check warning), app/Http/Requests/Api/Staff/UserSite/StaffUpdateSiteRequest.php (same), app/Jobs/Notifications/SendEnquiryConfirmationJob.php (`failed()`), app/Jobs/Notifications/SendSubscriptionConfirmationJob.php (`failed()`)
    - **Affects:** Operations visibility. Without `professional_id` and `request_id` in the log context, Nightwatch cannot correlate alias-check failures or permanently-failed notification jobs to a specific tenant or originating request.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - In both request classes, add `'professional_id' => $this->attributes->get('professional')?->id` and `'operation' => 'subdomain_alias_check'` to the warning context array.
        - In the job `failed()` handlers, add `'job_id' => $this->job?->getJobId()` and `'attempt' => $this->attempts()` alongside the existing `enquiry_id` / `subscription_id`.
    - **Technical:** The Nightwatch correlation model requires `professional_id` (or equivalent) and an operation discriminator for every structured warning so the dashboard can group by tenant. The alias-check warnings carry only `'error' => $e->getMessage()` — at 200 professionals with subdomain renames happening periodically, warnings are indistinguishable across tenants.
    - **Plain English:** The system writes helpful notes when something goes wrong, but the notes don't say who they're about or which request caused them. Adding a few extra fields turns "something broke" into "Josh's subdomain check failed on request abc123."
    - **Evidence:**
        ```php
        // UpdateSiteRequest.php
        Log::warning('Professional alias check failed in UpdateSiteRequest', ['error' => $e->getMessage()]);

        // SendEnquiryConfirmationJob.php failed()
        Log::error('SendEnquiryConfirmationJob failed permanently', [
            'enquiry_id' => $this->enquiryId,
            'error' => $e->getMessage(),
        ]);
        ```

- [x] **#LIFE-4** · P3 · S — `UpdateSiteRequest` and `StaffUpdateSiteRequest` duplicate ~80 `design_kit.*` validation rules
    - **Where:** app/Http/Requests/Api/User/Site/UpdateSiteRequest.php and app/Http/Requests/Api/Staff/UserSite/StaffUpdateSiteRequest.php (both `rules()` methods)
    - **Affects:** Maintenance velocity. Every migration that adds or drops a `site.design_kits` column requires updating both files identically. The `DesignKitRequestSyncTest` catches drift after the fact, but the duplication itself is ongoing toil — two file edits per column addition.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract the `design_kit.*` rules into a `DesignKitValidationRules` trait (or a static method on `UpdateSiteRequest`) and `use` / call it in both classes.
        - Keep the drift test (`DesignKitRequestSyncTest`) as a safety net after extraction.
    - **Technical:** Both `rules()` methods define an identical allowlist of ~80 `'design_kit.{column_name}'` entries. `StaffUpdateSiteRequest` already references `UpdateSiteRequest::ALLOWED_SKELETONS` to avoid one duplication; the same delegation pattern should apply to the full rules block.
    - **Plain English:** There are two identical menus — one for customers, one for staff — and every time a new dish is added, both menus need updating. Moving the shared menu items to a shared master list means one update keeps both menus current.
    - **Evidence:**
        ```php
        // UpdateSiteRequest.php rules() — ~80 design_kit entries:
        'design_kit.color_bg' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
        // ... ~78 more ...

        // StaffUpdateSiteRequest.php rules() — identical block:
        'design_kit.color_bg' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
        // ... ~78 more identical entries ...
        ```

- [x] **#CCH-2** · P3 · S — `design_kits:columns` cache uses bare `Cache::remember` with no single-flight lock and no TTL jitter
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php:260–266 (`writeDesignKit`)
    - **Affects:** Concurrent design-kit saves immediately after a deploy (when the `design_kits:columns` key is cold). All concurrent `writeDesignKit()` calls race to query `information_schema.columns` independently. The global key means every concurrent save across all professionals collides on the same cold-cache fill.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Cache::remember('design_kits:columns', 3600, fn() => ...)` with `app(CacheLockService::class)->rememberLocked(CacheKeyGenerator::designKitColumns(), 3600, fn() => ...)`.
        - Add a static `CacheKeyGenerator::designKitColumns()` method to centralise the key string so any future invalidation or version-rotation (see LIFE-2) touches one place.
    - **Technical:** The caching gold standard requires `CacheLockService::rememberLocked` for any shared key to prevent stampedes. The literal key string is also hardcoded in the controller rather than going through `CacheKeyGenerator`, making it invisible to any future bust or version-rotation logic.
    - **Evidence:**
        ```php
        $columns = Cache::remember('design_kits:columns', 3600, fn () => DB::connection('pgsql')
            ->table('information_schema.columns')
            ->where('table_schema', 'site')
            ->where('table_name', 'design_kits')
            ->pluck('column_name')
            ->all()
        );
        ```

- [x] **#CACHE-1** · P3 · S — Three `invalidateSite()` calls fire per design-kit-only update; the first two precede the `design_kits` write
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php:40–56 (`update` method flow)
    - **Affects:** Every design-kit-only `PATCH /api/site` — three full cache-invalidation sweeps (each touching ~30 Redis keys) for one logical write. Busts 1 and 2 run before the `site.design_kits` row is updated, so any cache rebuild triggered by them serves the pre-write kit.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Suppress bust 1 by wrapping `action->execute($professional, [])` (the empty-data call) in `Site::withoutObservers(fn() => $action->execute($professional, []))` when `$data` is empty — the explicit bust 3 already covers the authoritative post-write invalidation.
        - Update the `WriteDesignKitTest` expectation from `->times(3)` to `->times(2)` after removing the first observer-driven bust.
    - **Technical:** The three bust paths: (1) `execute([])` → `$site->save()` → `SiteObserver::saved()` (afterCommit) → `invalidateSite`. (2) `$site->touch()` → observer → `invalidateSite`. (3) Explicit `app(SiteCacheService::class)->invalidateSite($site)`. Bust 1 forces a cache rebuild from DB that discards whatever was warm from the previous request before the kit write has landed. Each `invalidateSite` call issues two `Cache::deleteMultiple` calls each deleting ~15 keys.
    - **Plain English:** Three separate fire alarm tests ring every time someone changes a paint colour. The first two ring before the painting is done — any inspector who shows up in that window sees the old paint. Only the third ring, after painting is complete, is meaningful.
    - **Evidence:**
        ```php
        $site = $action->execute($professional, $data); // bust 1 fires via observer (afterCommit)

        if (is_array($designKit)) {
            $this->writeDesignKit($site->id, $designKit); // raw design_kits write
            if (!$site->wasChanged()) {
                $site->touch(); // bust 2 fires via observer (afterCommit)
            }
            // bust 3 — authoritative post-write invalidation
            app(SiteCacheService::class)->invalidateSite($site);
        }
        ```

- [x] **#TXN-3** · P3 · M — `UpdateSiteAction::execute()` holds a Postgres transaction open across ~140 lines of mixed reads, pure-PHP computation, and multi-model writes
    - **Where:** app/Services/Site/UpdateSiteAction.php:48–191 (full `DB::transaction` closure)
    - **Affects:** Site update latency under concurrent subdomain changes — the transaction holds an open Postgres connection and row locks for the entire duration of the settings merge computation, publish validation, and all five model saves.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Hoist the `settings` `array_replace_recursive` merge (lines ~135–148) and the `is_published` completeness check (lines ~150–162) out of the transaction closure. Both are pure PHP with no DB interaction.
        - The subdomain conflict reads, alias writes, handle update, and site save must remain inside the transaction — do not move them.
    - **Technical:** The settings merge (`array_replace_recursive`) and publish check (empty string comparison) are pure computation — zero DB calls, zero risk of dirty reads. Moving them before the `DB::transaction(...)` call reduces the lock window from ~140 lines to ~80 lines. Note: `SiteObserver.$afterCommit = true` already ensures cache invalidation happens post-commit.
    - **Plain English:** The filing-cabinet lock is held while sharpening a pencil, checking a calendar, and making a phone call — all of which could have been done before the cabinet was opened. Doing the prep work outside the locked period means the cabinet is free sooner.
    - **Evidence:**
        ```php
        return DB::transaction(function () use (...): Site {
            // Lines 52–108: subdomain conflict reads + alias writes (must be in tx)

            // Lines 135–148: pure PHP array merge (no DB — can move outside)
            if (array_key_exists('settings', $data)) {
                $merged = array_replace_recursive($existing, $incoming);
                $data['settings'] = $merged;
            }

            // Lines 150–162: pure PHP validation (no DB — can move outside)
            if (($data['is_published'] ?? null) === true) {
                if (empty($professional->display_name)) { ... }
            }

            $site->fill($data);
            $site->save();
            return $site->fresh();
        });
        ```

- [x] **#MIG-2** · P3 · S — Destructive `DROP TABLE` and `DROP COLUMN` lack a documented rehearsal note
    - **Where:** supabase/migrations/20260527070000_skeleton_system_cleanup.sql
    - **Affects:** Irreversible loss of the entire `site.themes` catalog and `theme_id` column on `site.sites`. Any rollback requires a prior backup and manual restore.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a header comment to the migration confirming it was dry-run against development data before the production push: `-- Rehearsed against development YYYY-MM-DD. Themes table confirmed empty (0 rows). Rollback: restore from backup.`
    - **Technical:** `DROP TABLE IF EXISTS site.themes CASCADE` and the `DROP COLUMN theme_id` are irreversible SQL. The migration correctly uses `IF EXISTS` guards so it won't error on partial prior application, but there is no written confirmation the operation was verified against actual data before landing on production.
    - **Evidence:**
        ```sql
        DROP FUNCTION IF EXISTS set_default_theme_for_site CASCADE;
        DROP TABLE IF EXISTS site.themes CASCADE;
        ```

- [x] **#API-1** · P3 · S — `updateBookingSettings` returns a raw associative array instead of a Resource, inconsistent with every other action in the controller
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php (`updateBookingSettings`)
    - **Affects:** Professional dashboard clients consuming booking-settings update responses. Every other action returns `['site' => new SiteResource($site)]`; this action returns a flat array bypassing the Resource layer.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Return the booking settings folded into `SiteResource` by adding `booking_mode` and `manual_booking_url` to `SiteResource::toArray()` behind `$this->when(...)`, then return `$this->success(['site' => new SiteResource($site)])`.
    - **Evidence:**
        ```php
        return $this->success([
            'booking_mode' => $settings['booking_mode'] ?? 'manual',
            'manual_booking_url' => $settings['manual_booking_url'] ?? null,
        ]);
        ```

- [x] **#CFG-1** · P3 · S — Queue name `'notifications'` hardcoded as a string literal in both confirmation job classes
    - **Where:** app/Jobs/Notifications/SendEnquiryConfirmationJob.php:34; app/Jobs/Notifications/SendSubscriptionConfirmationJob.php:34
    - **Affects:** All outbound visitor email confirmations. A staging or test environment that uses a different queue name requires a code change and redeploy; the two files must both be updated or they drift.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract to a config key: `config('partna.queues.notifications', 'notifications')`.
        - Add `PARTNA_QUEUE_NOTIFICATIONS=notifications` to `.env.example`.
    - **Evidence:**
        ```php
        // SendEnquiryConfirmationJob.php
        $this->onQueue('notifications');
        // SendSubscriptionConfirmationJob.php
        $this->onQueue('notifications');
        ```

- [x] **#CFG-2** · P3 · S — `SUBDOMAIN_COOLDOWN_DAYS` hardcoded as a class constant while sibling handle lifecycle settings are config-driven
    - **Where:** app/Services/Site/UpdateSiteAction.php:30
    - **Affects:** Staging and test environments cannot shorten the 30-day cooldown for testing rename flows without a code change.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `self::SUBDOMAIN_COOLDOWN_DAYS` with `(int) config('partna.handle.subdomain_cooldown_days', 30)`.
        - Mirror the value in `UserSelfController::show()` where `subdomain_change_available_at` is computed (the existing comment at line 30 flags this mirror).
        - Add `SIDEST_HANDLE_SUBDOMAIN_COOLDOWN_DAYS=30` to `.env.example`.
    - **Technical:** `UpdateSiteAction` already reads `config('partna.handle.reclaim_days', 14)` and `config('partna.handle.redirect_days', 90)` for the other handle lifecycle values. `SUBDOMAIN_COOLDOWN_DAYS` is the only lifecycle constant not config-driven. The comment on the constant itself flags the `UserSelfController` mirror dependency — if the constant changes, two files must be updated in sync.
    - **Evidence:**
        ```php
        // Days between allowed subdomain changes. Mirrored in UserSelfController::show
        public const SUBDOMAIN_COOLDOWN_DAYS = 30;
        ```

- [x] **#API-3** · P3 · M — Response envelope shape differs across Professional, Staff, and PublicSite controller surfaces
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php; app/Http/Controllers/Api/Staff/StaffSite/StaffSiteController.php; app/Http/Controllers/Api/PublicSite/IndividualProfileController.php
    - **Affects:** Any universal API client — each surface wraps successful responses in a different key, requiring three deserialization paths for the same platform entity.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - `StaffSiteController` currently passes the Resource directly to `$this->success()`; wrap it in `['site' => new StaffSiteResource($row)]` to match the Professional surface.
        - The PublicSite `['data' => ...]` envelope is intentional (the Astro Worker expects it); document the exception clearly in a comment on the controller.
    - **Evidence:**
        ```php
        // UserSiteController — wrapped in 'site' key
        return $this->success(['site' => new SiteResource($site)]);
        // StaffSiteController — Resource passed directly, no wrapping key
        return $this->success(new StaffSiteResource($row));
        // IndividualProfileController — wrapped in 'data' key (Astro Worker contract)
        return $this->success(['data' => $payload]);
        ```

- [x] **#CFG-3** · P3 · S — Default skeleton ID `'skeleton-1'` duplicated in two separate files with no shared source of truth
    - **Where:** app/Services/PublicSite/IndividualProfilePayloadBuilder.php (`build`); app/Http/Resources/PublicSite/IndividualProfileResource.php (`toArray`)
    - **Affects:** If the platform default skeleton ever changes, both files must be updated in lockstep; a missed update causes the builder and the resource to disagree on the fallback.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Define `App\Models\Core\Site\Site::DEFAULT_SKELETON_ID = 'skeleton-1'` (or `config('partna.skeletons.default', 'skeleton-1')`) and reference it in both files.
    - **Evidence:**
        ```php
        // IndividualProfilePayloadBuilder::build()
        'skeleton_id' => $site?->skeleton_id ?? 'skeleton-1',
        // IndividualProfileResource::toArray()
        'skeletonId' => $this->sections['skeleton_id'] ?? 'skeleton-1',
        ```

- [x] **#TEST-5** · P3 · S — `StaffSiteController` has zero test coverage
    - **Where:** app/Http/Controllers/Api/Staff/StaffSite/StaffSiteController.php
    - **Affects:** Staff dashboard functionality — if either `show` or `showByProfessional` breaks, staff cannot view a professional's site details.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `tests/Feature/Api/Staff/StaffSiteControllerTest.php` with: `it returns site data for a given subdomain` (200, correct resource fields), `it returns 404 when subdomain not found`, and `it returns site data by professional model` for `showByProfessional`.
    - **Evidence:**
        ```php
        public function show(string $subdomain): JsonResponse
        {
            $row = AllSiteData::query()
                ->whereRaw('lower(subdomain) = lower(?)', [$subdomain])
                ->first();
            if (! $row) {
                return $this->error('Site not found.', 404);
            }
            return $this->success(new StaffSiteResource($row));
        }
        ```

---

## Suggested Bundled Sessions

### Bundle A — Transaction idempotency (2 × P1-S) — ship-blocking
Fixes the confirmed double-send TOCTOU in both confirmation jobs. Safe to bundle: both classes share identical structure and the fix is mechanical (move one line inside the transaction in each file). Run the existing brand tests after to confirm no regression.
- **#TXN-1** · P1 · S — Move `confirmation_sent_at` stamp inside lock in `SendEnquiryConfirmationJob`
- **#TXN-2** · P1 · S — Same fix for `SendSubscriptionConfirmationJob`

### Bundle B — Authorization hardening (P1-M + P2-M + P3-S)
Wires the existing `SitePolicy` into `UserSiteController`, adds tests for both the action and the staff controller. Safe to bundle: all three are additive — no behaviour removed. The drift test (`DesignKitRequestSyncTest`) and the policy coverage test both guard against regression.
- **#TEST-1** · P1 · M — Add `UpdateSiteActionTest` covering subdomain rename, cooldown, publish guard
- **#SEC-1** · P2 · M — Wire `authorizeForUser` into `UserSiteController::update()` and `::visibility()`
- **#TEST-5** · P3 · S — Add `StaffSiteControllerTest` (show + showByProfessional)

### Bundle C — Cache correctness (P2-S × 2 + P3-S × 2)
Four cache correctness fixes that touch the same cache layer. Bundle together: CCH-1 and CCH-2 both touch `IndividualProfileController` and `CacheLockService`; LIFE-2 and CACHE-1 touch the write path in `UserSiteController`.
- **#CCH-1** · P2 · S — Clear both primary + stale twin on deleted-user recovery path
- **#LIFE-2** · P2 · S — Add version suffix to `design_kits:columns` cache key
- **#CCH-2** · P3 · S — Route `design_kits:columns` through `CacheLockService::rememberLocked`
- **#CACHE-1** · P3 · S — Suppress bust 1 (empty-data observer call) in design-kit-only updates

### Bundle D — Write-path correctness (P2-S + P2-S + P2-M)
Three correctness gaps in the design-kit write flow. Bundle: all touch `UserSiteController::writeDesignKit()` or `UpdateSiteAction`; fixing them together avoids multiple PRs on the same methods.
- **#SCHEMA-1** · P2 · S — Use `updateOrInsert` to handle missing `design_kits` row
- **#LIFE-1** · P2 · S — Replace `QueryException`/`getCode()` with `UniqueConstraintViolationException`
- **#TEST-4** · P2 · M — Add functional validation tests (422 on bad hex / bad skeleton / reserved subdomain)

### Bundle E — Notification test coverage (P2-S + P2-M)
Two test gaps for the confirmation job classes. Bundle: both jobs share structure; the rate-limit test and concurrency test are additive to the existing brand test suite.
- **#TEST-3** · P2 · S — Test rate-limit early-return path in both confirmation jobs
- **#TEST-2** · P2 · M — Add PostgreSQL-only concurrency test for `writeDesignKit` lock guard

### Bundle F — Migration safety (P2-S + P3-S × 3)
Five migration hygiene fixes. Bundle: all are additive SQL-only or comment-only changes with no PHP impact; safe to batch in one PR.
- **#MIG-1** · P2 · S — Add `VALIDATE CONSTRAINT` migration for `skeleton_id` CHECK
- **#SCHEMA-3** · P3 · S — Add `IF NOT EXISTS` to `20260529044737` contrasting-colors ADD COLUMNs
- **#SCHEMA-4** · P3 · S — Add `IF EXISTS` / `IF NOT EXISTS` to `20260529053028` space-scale ALTER
- **#MIG-2** · P3 · S — Add rehearsal note to skeleton-system cleanup migration
- **#SCHEMA-2** · P3 · S — Add `created_at` / `updated_at` + update trigger to `site.design_kits`

### Bundle G — Config and API hygiene (P3 × 7)
Low-risk polish. Bundle: all are 1-line-per-file changes; none touch business logic.
- **#LIFE-4** · P3 · S — Extract `design_kit.*` rules to shared trait
- **#CFG-1** · P3 · S — Extract queue name `'notifications'` to config key
- **#CFG-2** · P3 · S — Make `SUBDOMAIN_COOLDOWN_DAYS` config-driven
- **#CFG-3** · P3 · S — Consolidate `'skeleton-1'` default to a single constant
- **#API-1** · P3 · S — Return `SiteResource` from `updateBookingSettings`
- **#API-3** · P3 · M — Wrap `StaffSiteController` response in `['site' => ...]` key; comment PublicSite exception
- **#LIFE-3** · P3 · M — Add tenant context (`professional_id`, `operation`) to `Log::warning` calls

### Standalone — do NOT bundle

- **#API-2** · P2 · M — Legacy `PublicSiteController` decommission — requires frontend/Worker coordination to confirm the `GET /api/public/site*` routes are no longer consumed before deregistering them. Verify with the Astro Worker and any mobile clients before removing routes.
- **#TXN-3** · P3 · M — Narrow `UpdateSiteAction` transaction scope — higher blast radius than the rest of Bundle D; keep separate to allow focused review of the transaction boundary change.
