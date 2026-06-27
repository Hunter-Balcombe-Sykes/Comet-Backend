Now I have enough verified data. Let me check one final item — the `config/partna.php` confirms CFG-1/2/3 are false alarms (all three have PHP-level defaults in the config file, so `config()` without a second argument still returns the fallback). I'll drop those. Let me write the final audit.

`★ Insight ─────────────────────────────────────`
Key adjudication patterns used here:
1. **Config-default false positives**: DeepSeek flags `config('key')` with no second argument as dangerous, but misses that `config/partna.php` already wraps each key in `env('VAR', hardcoded_fallback)` — the fallback lives in PHP, not in the call-site. Always read the config file before filing a CFG finding.
2. **Legacy path coexistence**: Two public-profile endpoints (`PublicSiteController` and `IndividualProfileController`) serving the same conceptual resource in different shapes is a real ongoing risk, confirmed only by checking routes — DeepSeek found the code smell but couldn't confirm the route was still wired.
3. **Structural vs. functional test coverage**: The `DesignKitRequestDriftTest` tests *shape* (column names match rules), but not *behavior* (the validation engine actually rejects bad values) — these are distinct coverage gaps, and DeepSeek correctly calls out both.
`─────────────────────────────────────────────────`

# Pre-Merge Audit (Migration Safety · API Contract · Config · Test Coverage) — 2026-06-03

**Branch:** development
**Lens:** Bundle 'pre-merge' audit across 4 focused themes: migration safety (MIG-*), API contract (API-*), configuration hygiene (CFG-*), and test coverage gaps (TEST-*) — pre-merge sweep for PRs touching schema, public API, or config.
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- supabase/migrations/20260527070000_skeleton_system_cleanup.sql
- supabase/migrations/20260527150000_design_kit_header_height.sql
- supabase/migrations/20260527170000_design_kit_typography_uppercase.sql
- supabase/migrations/20260529044737_design_kit_contrasting_colors.sql
- supabase/migrations/20260529053028_design_kit_unified_space_scale.sql
- app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php
- app/Http/Controllers/Api/Staff/StaffSite/StaffSiteController.php
- app/Http/Controllers/Api/PublicSite/PublicSiteController.php
- app/Http/Controllers/Api/PublicSite/IndividualProfileController.php
- app/Http/Requests/Api/User/Site/UpdateSiteRequest.php
- app/Http/Requests/Api/Staff/UserSite/StaffUpdateSiteRequest.php
- app/Services/Cache/SiteCacheService.php
- app/Services/Site/UpdateSiteAction.php
- app/Jobs/Notifications/SendEnquiryConfirmationJob.php
- app/Jobs/Notifications/SendSubscriptionConfirmationJob.php
- config/partna.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 5 complete
- P3 Low: 0 of 8 complete

---

## P1 — Fix before pilot launch

- [ ] **#TEST-1** · P1 — UpdateSiteAction has no test coverage despite containing the most critical business logic in the codebase
    - **Where:** app/Services/Site/UpdateSiteAction.php (entire class)
    - **Affects:** Every user who changes a subdomain, publishes their site, or renames their handle. A regression silently breaks subdomain assignment, orphans alias rows, skips cooldown enforcement, or allows publication of incomplete profiles — all without a test catching it.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `tests/Feature/Services/UpdateSiteActionTest.php` covering: happy-path subdomain rename with alias row created, cooldown enforcement (re-rename blocked before 30 days), collision with an existing subdomain in `site.sites` and `site.site_subdomain_aliases`, handle sync on rename (professional.handle updated, `UserHandleAlias` row created), rename-back collapse (alias deleted when renaming to a previously held subdomain), publish validation (missing `display_name` rejected), and settings merge preserving unknown keys.
        - Assert database state after each case — alias row created, `HandleChangeLog` row inserted, `subdomain_changed_at` advanced.
        - The cooldown and collision guards each have a second DB query that bypasses the FormRequest layer (`UpdateSiteAction` validates independently of `UpdateSiteRequest`); the test must drive the action directly, not through HTTP.
    - **Technical:** `UpdateSiteAction::execute()` contains ~150 lines of branching subdomain/alias/handle/publish business logic inside a `DB::transaction`. No existing test touches it. The design-kit tests drive `PATCH /api/site` but sidestep subdomain mutations entirely. A regression in the cooldown branch (e.g., a future refactor drops `$site->subdomain_changed_at = now()`) silently ships — no CI failure. Per P1 criteria, this ships bad behavior in a well-documented scenario (every user rename).
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

- [ ] **#TEST-4** · P2 — UpdateSiteRequest and StaffUpdateSiteRequest validation rules are structurally tested but functionally untested (no 422 on bad input)
    - **Where:** app/Http/Requests/Api/User/Site/UpdateSiteRequest.php; app/Http/Requests/Api/Staff/UserSite/StaffUpdateSiteRequest.php
    - **Affects:** Dashboard users and staff submitting invalid design-kit values (non-hex colours, unknown skeleton IDs, reserved subdomains). A silently dropped validation rule lets malformed data pass through; the structural drift test (`DesignKitRequestDriftTest`) would not catch it because both the column and the rule would be absent simultaneously.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a test that hits `PATCH /api/site` with each of these invalid payloads and asserts HTTP 422 with the correct error key: a colour value like `'red'` (not hex), `skeleton_id: 'skeleton-9'`, `subdomain: 'api'` (reserved), `settings.design: {}` (prohibited), and a subdomain with uppercase characters.
        - Duplicate the pattern for the staff endpoint (`PATCH /api/staff/sites/{professional}`).
        - The hex regex rule (`regex:/^#[0-9a-fA-F]{3,8}$/`) and the reserved-subdomain closure are only exercised when the Laravel validation pipeline is actually invoked; the structural drift test never fires the engine.
    - **Technical:** `DesignKitRequestDriftTest` verifies that every `site.design_kits` column has a matching rule key in the request class. It does this by comparing string sets — it does not call the validator. A developer could accidentally replace the `'regex:/^#[0-9a-fA-F]{3,8}$/'` rule with `'string'` and the structural test would still pass (the key is still there) while arbitrary CSS values flow into the database and into inline styles on the public profile page.
    - **Plain English:** We have a system that checks the lock is still on the door, but we've never actually tried opening it with the wrong key to see if it says no. The structural check confirms the lock mechanism exists; a functional test would confirm it actually locks. Without the functional test, someone could swap the lock for a prop without setting off any alarms.
    - **Evidence:**
        ```php
        // app/Http/Requests/Api/User/Site/UpdateSiteRequest.php
        'design_kit.color_accent' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
        'skeleton_id' => ['sometimes', 'string', Rule::in(self::ALLOWED_SKELETONS)],
        // … plus closure-based reserved-word and uniqueness checks
        ```

- [ ] **#TEST-3** · P2 — Rate-limit early-return path untested in both confirmation job classes
    - **Where:** app/Jobs/Notifications/SendEnquiryConfirmationJob.php (`withinRateLimit`); app/Jobs/Notifications/SendSubscriptionConfirmationJob.php (`withinRateLimit`)
    - **Affects:** Visitors submitting multiple enquiries or subscriptions. If the rate limit fires, the job silently returns without sending — no mail, no error, no `confirmation_sent_at` stamp. No test verifies this path, so a misconfigured limit (e.g. `partna.throttle.visitor_confirmation_per_hour` accidentally set to 0) would suppress all outbound confirmations silently.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - For the enquiry confirmation job: add a test that mocks `RateLimiter::tooManyAttempts` to return `true`, dispatches the job, and asserts no email was sent and a `Log::warning` was written.
        - Apply the same pattern to `SendSubscriptionConfirmationJob`.
    - **Technical:** Both jobs call `withinRateLimit($recipient)` before the mail send. If it returns `false`, the method returns early without updating `confirmation_sent_at`. The existing brand tests (`SendEnquiryConfirmationJobBrandTest`) exercise the happy path only — they never set the rate limiter to exhausted. The warning log is the only observable side-effect of the throttled path; without asserting it, a future removal of the log line would also go unnoticed.
    - **Plain English:** The jobs have a built-in safety valve that says "don't send more than N emails per hour to the same address." But the tests always run with the valve wide open. If someone accidentally cranks the limit to zero in the settings, all confirmation emails stop — silently. A one-line test verifying the valve actually shuts off would catch this.
    - **Evidence:**
        ```php
        // app/Jobs/Notifications/SendEnquiryConfirmationJob.php
        if (! $this->withinRateLimit($recipient)) {
            return;
        }
        ```
        ```php
        // SendSubscriptionConfirmationJob.php — identical guard
        if (! $this->withinRateLimit($recipient)) {
            return;
        }
        ```

- [ ] **#TEST-2** · P2 — writeDesignKit() lockForUpdate concurrency guard is a no-op under SQLite and has no real-Postgres race test
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php (`writeDesignKit`)
    - **Affects:** Users (or staff) editing a site's design kit from two browser tabs simultaneously. Without a verified lock, two writes with disjoint column sets can overwrite each other — one request sets `color_accent`, another sets `typography_font_family`, and the second write could silently wipe the first's colour.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a PostgreSQL-only integration test (tagged `@group pgsql`) that dispatches two concurrent `writeDesignKit` calls with disjoint column payloads and asserts that after both commits, both columns contain their requested values.
        - The test should use Laravel's `DB::transaction` + parallel connections (or two requests in sequence with artificial delay inside a lock) to simulate contention.
        - The existing `WriteDesignKitTest.php` explicitly documents this gap ("the concurrency invariant would need a real-Postgres test to verify") — this finding formalises the gap as a tracked item.
    - **Technical:** `writeDesignKit()` wraps its `SELECT … FOR UPDATE` and `UPDATE` in a `DB::connection('pgsql')->transaction()`. Under SQLite (the test environment), `lockForUpdate()` compiles to a no-op `SELECT` with no locking semantics. The recent commit `3e3cc768 fix(design-kit): serialise concurrent writes with transaction + lockForUpdate` shipped the fix, but no test exercises the invariant the fix is meant to enforce. A future refactor that accidentally removes the lock would not be caught by CI.
    - **Plain English:** Two people editing the same design at the same moment is like two people editing a shared document at the same time. We added a "take turns" system to prevent one person's edits from wiping the other's, but we only tested it on a training dummy that doesn't actually take turns. Until we test it on the real system, we can't be sure the "take turns" rule actually holds when it matters.
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

- [ ] **#API-2** · P2 — Legacy PublicSiteController is still live and bypasses the Resource layer, exposing raw array output including a dead `theme` key
    - **Where:** app/Services/Cache/SiteCacheService.php (`buildPayloadFromDb`); app/Http/Controllers/Api/PublicSite/PublicSiteController.php
    - **Affects:** Any consumer of `GET /api/public/site` (subdomain header path) or `GET /api/public/site-by-slug`. Both routes are registered in `routes/api.php` and `routes/api/publicSite.php` and serve traffic through `buildPayloadFromDb()`, which assembles a raw associative array including a `'theme' => null` key (the skeleton-system cleanup removed theme data from the view but left the key in the builder). Consumers receive a payload that bypasses Resource allowlisting and includes a null `theme` field that no longer has meaning.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Decide whether `PublicSiteController` + `SiteCacheService::getPublicSitePayload()` / `buildPayloadFromDb()` are still needed. If the Cloudflare Worker and Next.js front-end have migrated to `GET /api/public/profiles/{handle}` (IndividualProfileController), decommission the old routes and mark `buildPayloadFromDb()` as `@deprecated`.
        - If the legacy path still serves active consumers: remove the `'theme' => $payload['theme'] ?? null` key from `buildPayloadFromDb()` since `site.public_site_payload` no longer contains theme data, and add a `TODO` tracking migration to the Resource-layer pattern.
        - In either case, add a comment to `SiteCacheService` explaining which controller still consumes it and why, so the next engineer does not assume it is safe to remove.
    - **Technical:** The new `IndividualProfileController` → `IndividualProfilePayloadBuilder` → `IndividualProfileResource` path enforces field allowlisting, audience-appropriate serialisation, and clean camelCase wire shape. `buildPayloadFromDb()` predates this and hand-assembles an array from the `PublicSitePayload` DB view. After the skeleton-system migration, that view no longer exposes theme data — but the builder still emits `'theme' => $payload['theme'] ?? null`, a stale key that `null`-fills on every response. Verified: `PublicSiteController::show()` and `showByHeader()` call `$this->siteCache->getPublicSitePayload()` which calls `buildPayloadFromDb()` on cache miss.
    - **Plain English:** The old "serve this professional's public page" system is still running in parallel with the new one. The new system has a proper filter that inspects every field before it goes out. The old system loads a box of data and ships it raw, including an empty label marked "theme" that hasn't meant anything since the cleanup. Both paths are wired up in the URL routing. Until the old path is either cleaned up or formally kept with a clear owner, there's no guarantee a future DB change won't add a column to the view and leak it through the old path.
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
        ```php
        // routes/api.php — both legacy routes still registered:
        // Route::get('/public/site-by-slug', [PublicSiteController::class, 'showByHeader'])
        // routes/api/publicSite.php: Route::get('/site', [PublicSiteController::class, 'show'])
        ```

- [ ] **#MIG-1** · P2 — skeleton_id CHECK constraint added without `NOT VALID`, causing a full table scan under ACCESS EXCLUSIVE lock during the production deploy
    - **Where:** supabase/migrations/20260527070000_skeleton_system_cleanup.sql
    - **Affects:** All site writes (dashboard saves, publish toggles, subdomain changes) are blocked for the duration of the table scan during the production migration run. Pre-pilot the table is small and the lock is brief, but the pattern should be corrected before the table grows.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a follow-up migration that validates the constraint using `ALTER TABLE site.sites VALIDATE CONSTRAINT sites_skeleton_id_check`. If the constraint was already added without `NOT VALID`, this is safe to run and validates existing rows under a `SHARE UPDATE EXCLUSIVE` lock instead of `ACCESS EXCLUSIVE`.
        - For future DDL on other tables: adopt the two-step pattern — `ADD CONSTRAINT … NOT VALID` first, then a separate `VALIDATE CONSTRAINT` migration — for any constraint added to a table with existing rows.
    - **Technical:** PostgreSQL validates CHECK constraints inline when they are added to a column with an existing `DEFAULT` value, even though every row trivially satisfies the constraint. The validation runs under `ACCESS EXCLUSIVE`, which blocks all reads and writes. The two-step `NOT VALID` + `VALIDATE CONSTRAINT` pattern avoids the strong lock: the constraint applies to future writes immediately, and validation of existing rows runs under the weaker lock. The `SkeletonSystemConstraintsTest` confirms the constraint exists and `convalidated = true` on the development database, so the concern is specifically the lock duration on the production push.
    - **Plain English:** When this migration runs on the production database, Postgres locks the entire "sites" table while it checks every existing row against the new rule — even though the rule can never fail (every row already has the default value). It's like shutting the front door of a café while a bouncer checks that every existing customer is old enough to be there, even though they were all already checked on entry. A two-step approach lets the check happen quietly in the background without locking anyone out.
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

- [ ] **#MIG-2** · P3 — Destructive DROP TABLE and DROP COLUMN lack a documented rehearsal note
    - **Where:** supabase/migrations/20260527070000_skeleton_system_cleanup.sql
    - **Affects:** Irreversible loss of the entire `site.themes` catalog and `theme_id` column on `site.sites`. Any rollback requires a prior backup and manual restore.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a header comment to the migration confirming it was dry-run against a copy of production data (or development with representative rows) before the production push: `-- Rehearsed against development YYYY-MM-DD. Themes table confirmed empty (0 rows). Rollback: restore from backup.`
        - Document the rollback path (point-in-time restore from Supabase backup) alongside any other irreversible migration of this scope.
    - **Technical:** `DROP TABLE IF EXISTS site.themes CASCADE` and the `DROP COLUMN theme_id` are irreversible SQL. The migration correctly uses `IF EXISTS` guards so it won't error on partial prior application, but there is no written confirmation that the operation was verified against actual data before landing on production. Convention for destructive migrations in this codebase is to include a brief rehearsal note — this one was authored before that convention was established.
    - **Plain English:** This migration permanently shreds an old filing cabinet (the themes table) and removes a column from the main ledger. Both actions are intentional and correct, but the migration file has no sticky note saying "I checked the cabinet was empty first." Future engineers reading the migration have no way to confirm it was tested before the production run.
    - **Evidence:**
        ```sql
        -- 4. Drop the themes catalog table outright.
        DROP FUNCTION IF EXISTS set_default_theme_for_site CASCADE;
        DROP TABLE IF EXISTS site.themes CASCADE;
        ```

- [ ] **#MIG-3** · P3 — Several design-kit migrations missing `IF [NOT] EXISTS` guards on `ADD COLUMN` and `DROP COLUMN`, making partial re-runs fatal
    - **Where:** supabase/migrations/20260527150000_design_kit_header_height.sql; supabase/migrations/20260527170000_design_kit_typography_uppercase.sql; supabase/migrations/20260529044737_design_kit_contrasting_colors.sql; supabase/migrations/20260529053028_design_kit_unified_space_scale.sql
    - **Affects:** Developer experience — if a migration partially applies (network drop after the first `ALTER TABLE` statement succeeds), re-running the file errors immediately on "column already exists" or "column does not exist."
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `IF NOT EXISTS` to all `ADD COLUMN` statements in the affected files (follow the pattern already used in `20260527080000`, `20260527090000`, `20260527100000`).
        - Add `IF EXISTS` to all `DROP COLUMN` statements in `20260529053028` and `20260529044737`.
        - Note: the bulk `DROP COLUMN` block in `20260529053028` replaces the entire padding/spacing/tablet scale with the unified `space_*` columns. Adding `IF EXISTS` makes the migration safe to retry without needing to confirm which columns were already dropped.
    - **Technical:** `supabase db push` is not idempotent. The earlier migrations (`20260527080000`–`20260527130000`) consistently use `IF NOT EXISTS` on `ADD COLUMN`. The four later migrations omit these guards, inconsistently. `20260529053028` drops 28 columns in a single `ALTER TABLE` — if the migration fails mid-statement (which Postgres prevents inside a transaction, but the file itself has no explicit `BEGIN`/`COMMIT`), re-running is safe only if `IF EXISTS` is present.
    - **Plain English:** Some of the later "add a new design setting" scripts were written without a simple "skip this if it already ran" note. If anything goes wrong halfway through and someone tries to re-run the script, it fails immediately because Postgres complains the column already exists (or was already dropped). Earlier scripts in the same folder already include this note — the later ones just need the same treatment.
    - **Evidence:**
        ```sql
        -- 20260527150000_design_kit_header_height.sql — no IF NOT EXISTS
        ALTER TABLE site.design_kits
          ADD COLUMN sizing_header_height TEXT NULL,
          ADD COLUMN sizing_tablet_header_height TEXT NULL,
          ADD COLUMN sizing_desktop_header_height TEXT NULL;
        ```
        ```sql
        -- 20260529053028_design_kit_unified_space_scale.sql — no IF EXISTS
        ALTER TABLE site.design_kits
          DROP COLUMN padding_extra_small,
          DROP COLUMN padding_small,
          ...
        ```

- [ ] **#API-1** · P3 — `updateBookingSettings` returns a raw associative array instead of a Resource, inconsistent with every other action in the controller
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php (`updateBookingSettings`)
    - **Affects:** Professional dashboard clients consuming booking-settings update responses. Every other action (`show`, `update`, `visibility`) returns `['site' => new SiteResource($site)]`; this action returns a flat array bypassing the Resource layer.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Return the booking settings folded into `SiteResource` by adding `booking_mode` and `manual_booking_url` to `SiteResource::toArray()` behind `$this->when(...)` so the controller can use `return $this->success(['site' => new SiteResource($site)])`.
        - Alternatively, wrap the raw array in a dedicated `BookingSettingsResource` if the fields should not appear in the general `SiteResource`.
    - **Technical:** `SiteResource` already reads from `$this->settings` (cast to array). Adding `'booking_mode' => $this->settings['booking_mode'] ?? 'manual'` fields to the resource would unify the response shape without breaking existing consumers of the `show` / `update` endpoints (the fields are additive). The current raw return also means any future computed field added to `SiteResource` is not available to the booking-settings response path.
    - **Plain English:** Every checkout counter in this shop gives you a receipt in the same envelope — except the booking counter, which hands you a handwritten note. The information is the same, but the person receiving it needs to handle two different layouts. Standardising the envelope saves future frontend confusion, especially when new fields are added to the standard receipt.
    - **Evidence:**
        ```php
        return $this->success([
            'booking_mode' => $settings['booking_mode'] ?? 'manual',
            'manual_booking_url' => $settings['manual_booking_url'] ?? null,
        ]);
        ```

- [ ] **#CFG-1** · P3 — Queue name `'notifications'` hardcoded as a string literal in both confirmation job classes
    - **Where:** app/Jobs/Notifications/SendEnquiryConfirmationJob.php:34; app/Jobs/Notifications/SendSubscriptionConfirmationJob.php:34
    - **Affects:** All outbound visitor email confirmations. A staging or test environment that uses a different queue name requires a code change and redeploy; the two files must both be updated or they drift.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract to a config key: `config('partna.queues.notifications', 'notifications')`.
        - Use `$this->onQueue(config('partna.queues.notifications', 'notifications'))` in both constructors.
        - Add `PARTNA_QUEUE_NOTIFICATIONS=notifications` to `.env.example`.
    - **Technical:** Both jobs call `$this->onQueue('notifications')` directly. This is the only queue name in the notifications subsystem without a config key. The `config/partna.php` file already follows the pattern of wrapping environment-sensitive values in `env(...)` — the queue name is the odd one out. A typo or environment-specific override currently requires code modification.
    - **Plain English:** Both email confirmation jobs have the mailbox name "notifications" written in permanent marker. If you need a different mailbox for a test environment, you have to rewrite both letters. Making it a configuration setting means you can just change one line in the environment file instead.
    - **Evidence:**
        ```php
        // SendEnquiryConfirmationJob.php
        public function __construct(public readonly string $enquiryId)
        {
            $this->onQueue('notifications');
        }
        ```
        ```php
        // SendSubscriptionConfirmationJob.php
        public function __construct(public readonly string $subscriptionId)
        {
            $this->onQueue('notifications');
        }
        ```

- [ ] **#CFG-2** · P3 — `SUBDOMAIN_COOLDOWN_DAYS` hardcoded as a class constant while sibling handle lifecycle settings are config-driven
    - **Where:** app/Services/Site/UpdateSiteAction.php:30
    - **Affects:** Subdomain rename flow — professionals are locked out of changing their subdomain for 30 days. Staging and test environments cannot shorten this for testing rename flows without a code change.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `self::SUBDOMAIN_COOLDOWN_DAYS` with `(int) config('partna.handle.subdomain_cooldown_days', 30)`.
        - Mirror the value in `UserSelfController::show()` where `subdomain_change_available_at` is computed for the `/me` payload (the existing comment at line 30 already flags this mirror).
        - Add `SIDEST_HANDLE_SUBDOMAIN_COOLDOWN_DAYS=30` to `.env.example`.
    - **Technical:** `UpdateSiteAction` already reads `config('partna.handle.reclaim_days', 14)` and `config('partna.handle.redirect_days', 90)` for the other handle lifecycle values. `SUBDOMAIN_COOLDOWN_DAYS` is the only lifecycle constant not given the config treatment. The comment on the constant itself flags the `UserSelfController` mirror dependency — if the constant changes, the mirrored calculation in `UserSelfController` must also change, which is exactly the kind of dual-update risk config centralisation prevents.
    - **Plain English:** The "you can only change your web address once a month" rule is carved in stone in the code, while the two related rules ("how long the old address redirects" and "when it's recycled") are adjustable knobs in the settings file. For testing, having an adjustable cooldown (e.g. 1 day) would let testers freely exercise the rename flow without waiting a month. Adding the knob costs nothing.
    - **Evidence:**
        ```php
        // app/Services/Site/UpdateSiteAction.php
        // Days between allowed subdomain changes. Mirrored in UserSelfController::show
        // when computing subdomain_change_available_at for the /me payload.
        public const SUBDOMAIN_COOLDOWN_DAYS = 30;
        ```

- [ ] **#API-3** · P3 — Response envelope shape differs across Professional, Staff, and PublicSite controller surfaces
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php; app/Http/Controllers/Api/Staff/StaffSite/StaffSiteController.php; app/Http/Controllers/Api/PublicSite/IndividualProfileController.php
    - **Affects:** Any universal API client consuming Professional, Staff, and PublicSite endpoints. Each surface wraps successful responses in a different key, requiring three deserialization paths for what is conceptually the same platform entity.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Adopt a single convention. The simplest path: `StaffSiteController` currently passes the Resource directly to `$this->success()`; wrap it in `['site' => new StaffSiteResource($row)]` to match the Professional surface. Document the chosen convention (resource-named key) in a code comment on `ApiController::success()`.
        - The PublicSite `['data' => ...]` envelope is intentional (the Astro Worker expects it); document the exception clearly.
    - **Technical:** `UserSiteController` uses `['site' => new SiteResource($site)]`. `StaffSiteController` passes `new StaffSiteResource($row)` directly — the response root is the resource's `toArray()` output with no wrapping key. `IndividualProfileController` uses `['data' => $payload]` because the Astro Worker subrequest expects that envelope (documented in the controller docblock). The Staff surface deviates without documentation. A frontend consuming the Staff endpoint must access `response.subdomain` while consuming the Professional endpoint via `response.site.subdomain`.
    - **Plain English:** Three different service counters at the same venue hand you the same paperwork in three different formats: one inside an envelope labelled "SITE," one loose, and one inside an envelope labelled "DATA." Each is correct for its purpose, but a universal scanner needs three different settings. The loose one (Staff) should be standardised to match the Professional envelope; the DATA envelope is intentional and just needs a comment explaining why.
    - **Evidence:**
        ```php
        // UserSiteController — wrapped in 'site' key
        return $this->success(['site' => new SiteResource($site)]);

        // StaffSiteController — Resource passed directly, no wrapping key
        return $this->success(new StaffSiteResource($row));

        // IndividualProfileController — wrapped in 'data' key (Astro Worker contract)
        return $this->success(['data' => $payload]);
        ```

- [ ] **#CFG-3** · P3 — Default skeleton ID `'skeleton-1'` duplicated in two separate files with no shared source of truth
    - **Where:** app/Services/PublicSite/IndividualProfilePayloadBuilder.php (`build`); app/Http/Resources/PublicSite/IndividualProfileResource.php (`toArray`)
    - **Affects:** Public profile rendering. If the platform default skeleton ever changes, both files must be updated in lockstep; a missed update causes the builder and the resource to disagree on the fallback value.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Define a single constant: `App\Models\Core\Site\Site::DEFAULT_SKELETON_ID = 'skeleton-1'` (or a key in `config('partna.skeletons.default', 'skeleton-1')`).
        - Reference it in both `IndividualProfilePayloadBuilder::build()` and `IndividualProfileResource::toArray()`.
    - **Technical:** The builder computes `$site?->skeleton_id ?? 'skeleton-1'` and the resource independently computes `$this->sections['skeleton_id'] ?? 'skeleton-1'`. The resource receives its value from the builder (so they agree at runtime), but the fallback string is duplicated. The `Site` model's `skeleton_id` column has a DB-level `DEFAULT 'skeleton-1'` from the migration, making a true null unlikely — but the PHP fallbacks exist for the edge case of a user without a site row, which the builder handles by passing `null` to the resource. A single constant eliminates the coordination requirement.
    - **Plain English:** The answer to "which page template do we use if nothing is chosen?" is written on two separate sticky notes in two different rooms. If the answer ever changes (a new template becomes the standard), someone has to find and update both notes without a reminder that the second one exists. A single source of truth — one sticky note everyone reads — removes the dual-update risk.
    - **Evidence:**
        ```php
        // IndividualProfilePayloadBuilder::build()
        'skeleton_id' => $site?->skeleton_id ?? 'skeleton-1',
        ```
        ```php
        // IndividualProfileResource::toArray()
        'skeletonId' => $this->sections['skeleton_id'] ?? 'skeleton-1',
        ```

- [ ] **#TEST-5** · P3 — StaffSiteController has zero test coverage
    - **Where:** app/Http/Controllers/Api/Staff/StaffSite/StaffSiteController.php
    - **Affects:** Staff dashboard functionality — if either `show` or `showByProfessional` breaks, staff cannot view a professional's site details, which is a support-ops dependency.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `tests/Feature/Api/Staff/StaffSiteControllerTest.php` with: `it returns site data for a given subdomain` (200, correct `StaffSiteResource` fields), `it returns 404 when subdomain not found`, and optionally `it returns site data by professional model` for `showByProfessional`.
    - **Technical:** `StaffSiteController` reads from the `AllSiteData` DB view and returns `StaffSiteResource`. No test file exists. The controller's 404 handling (explicit `return $this->error('Site not found.', 404)`) and the case-insensitive `whereRaw('lower(subdomain) = lower(?)')` lookup are both untested. The cost to add two tests is minimal; the benefit is a regression signal for any future schema change to `site.all_site_data`.
    - **Plain English:** The staff back-office tool that shows them a professional's site has never had an automated check to confirm it actually works. Adding a quick automated test — "load a known site, verify it comes back" — would be the equivalent of testing a smoke alarm by pressing the test button: low effort, catches catastrophic failures early.
    - **Evidence:**
        ```php
        // app/Http/Controllers/Api/Staff/StaffSite/StaffSiteController.php
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
