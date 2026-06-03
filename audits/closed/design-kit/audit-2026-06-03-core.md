`★ Insight ─────────────────────────────────────`
Key verification findings from tool checks:
- **SiteObserver has `$afterCommit = true`** — this completely invalidates TXN-3 (DeepSeek's "cache bust inside transaction" P1). Laravel defers all observer event callbacks until after the surrounding transaction commits when this flag is set. The pattern is already correct.
- **No writer for `CacheKeyGenerator::siteImages()`** was found — LIFE-3's stale-twin concern requires that key to be written via `CacheLockService::rememberLocked`. It isn't. Drop under the precision rule.
- **LIFE-1/SEC-2 "checked off"** in `8f992afd docs: check off LIFE-1, SEC-1, SEC-2 in core audit` but Grep confirms both issues are still present in source — the docs commit was premature.
`─────────────────────────────────────────────────`

# Core Bundle Audit — 2026-06-03

**Branch:** development
**Lens:** Bundle 'core' audit — security/policy (SEC-*), lifecycle correctness (LIFE-*), caching gold-standard (CCH-*), scaling antipatterns (CACHE-*), schema/RLS (SCHEMA-*), transaction-boundaries (TXN-*)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php
- app/Http/Controllers/Api/Staff/StaffSite/StaffSiteController.php
- app/Http/Controllers/Api/PublicSite/IndividualProfileController.php
- app/Http/Requests/Api/User/Site/UpdateSiteRequest.php
- app/Http/Requests/Api/Staff/UserSite/StaffUpdateSiteRequest.php
- app/Services/Site/UpdateSiteAction.php
- app/Services/Cache/SiteCacheService.php
- app/Services/Cache/CacheKeyGenerator.php
- app/Services/PublicSite/IndividualProfilePayloadBuilder.php
- app/Jobs/Notifications/SendEnquiryConfirmationJob.php
- app/Jobs/Notifications/SendSubscriptionConfirmationJob.php
- app/Observers/Core/SiteObserver.php
- app/Policies/SitePolicy.php
- app/Mail/Branding/ProEmailBrandResolver.php
- supabase/migrations/20260527070000_skeleton_system_cleanup.sql
- supabase/migrations/20260529044737_design_kit_contrasting_colors.sql
- supabase/migrations/20260529053028_design_kit_unified_space_scale.sql

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 2 complete
- P2 Medium: 0 of 5 complete
- P3 Low: 0 of 8 complete

---

## P1 — Fix before pilot launch

- [ ] **#TXN-1** · P1 — Double-send window: `confirmation_sent_at` flag written outside the idempotency lock in `SendEnquiryConfirmationJob`
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

- [ ] **#TXN-2** · P1 — Double-send window: `confirmation_sent_at` flag written outside the idempotency lock in `SendSubscriptionConfirmationJob`
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

---

## P2 — Should fix

- [ ] **#SCHEMA-1** · P2 — `writeDesignKit()` silently discards values when no `site.design_kits` row exists
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php:274–284 (`writeDesignKit` private method)
    - **Affects:** Professionals updating design settings for a site whose `design_kits` row is missing (backfill race on sites created before migration `20260527070000`, trigger bypass via `session_replication_role = 'replica'` or `pg_restore`, or manual DB operations). The dashboard returns HTTP 200 with no indication the write was a no-op.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the `->where('site_id', $siteId)->update($valid)` call with `DB::connection('pgsql')->table('site.design_kits')->updateOrInsert(['site_id' => $siteId], $valid)`.
        - Alternatively, check the affected-row count from `->update()` and log a `Log::warning` with `site_id` context when it returns 0, so Nightwatch surfaces the missing-row case rather than silently succeeding.
    - **Technical:** `lockForUpdate()->get()` on an empty result set acquires no row lock and returns an empty collection. The subsequent `->update($valid)` targets zero rows — PostgreSQL returns success for a valid UPDATE that matches nothing. The `trg_create_empty_design_kit` trigger (with `ON CONFLICT DO NOTHING` from `20260602010000`) guarantees a row for every site created through normal application flow, but the migration comment explicitly notes that pre-cleanup sites relied on a separate backfill step that "may have missed rows." For those sites, every design kit save silently discards the user's data.
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

- [ ] **#LIFE-1** · P2 — `UpdateSiteAction` catches generic `QueryException` and string-compares SQLSTATE codes instead of `UniqueConstraintViolationException`
    - **Where:** app/Services/Site/UpdateSiteAction.php:103–105, 136–138, 216–219 (three catch blocks)
    - **Affects:** The subdomain rename flow — alias creation, handle alias creation, and the final site save unique guard. Note: commit `8f992afd docs: check off LIFE-1, SEC-1, SEC-2 in core audit` marked this done, but Grep confirms the `getCode() !== '23505'` pattern is still present on all three lines as of the current source.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace all three `catch (QueryException $e)` blocks with `catch (\Illuminate\Database\UniqueConstraintViolationException $e)` (available since Laravel 10).
        - Remove the `if ($e->getCode() !== '23505') { throw $e; }` guards — the typed catch handles only unique violations by construction.
        - Keep the fallback logic (alias timestamp refresh, ValidationException re-throw) inside the typed catch.
    - **Technical:** `QueryException::getCode()` returns the SQLSTATE as a string. Comparing it to `'23505'` (PostgreSQL's unique-violation code) works today but has two latent risks: (1) a new unique constraint added to a related table that fires unexpectedly inside the transaction would be silently caught rather than surfaced; (2) the check is fragile to any driver-layer change in how SQLSTATE codes are formatted. `UniqueConstraintViolationException` is a typed subclass raised specifically for 23505 violations, making the intent explicit and the handling version-stable. The `8f992afd` commit was a docs-only check-off; no corresponding fix commit is present in the git log.
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

- [ ] **#LIFE-2** · P2 — `design_kits:columns` cache key invalidated only by full `artisan cache:clear`; no version token ties bust to the migration that changes the column set
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

- [ ] **#CCH-1** · P2 — `handle.resolve` stale twin not cleared in the deleted-user recovery path
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
    - **Technical:** `CacheLockService::rememberLocked` writes a primary entry with jittered TTL and a `:stale` companion at `STALE_TTL_MULTIPLIER × primary TTL`. On a primary miss (TTL expired), the SWR path reads the `:stale` copy and re-serves it while one worker refills. If the primary is cleared without clearing the `:stale` twin, the SWR path resurrects the stale resolve entry on the next request, re-triggers the same null-payload path, clears the primary again — and repeats for the full stale TTL. The pattern is symmetrically handled in `invalidateSitePayload` with `bustWithStale()` but was missed in this one controller recovery path.
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

- [ ] **#SEC-1** · P2 — `UserSiteController::update()` and `::visibility()` never call `authorizeForUser`; inline ownership resolution bypasses the Policy layer
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php:33–55 (`update` and `visibility` methods)
    - **Affects:** Site mutation endpoints from the professional dashboard. `SitePolicy` exists (`app/Policies/SitePolicy.php`) and has an `update` method with `denyIfPendingDeletion` and ownership checks, but neither controller method calls it. Note: commit `8f992afd docs: check off LIFE-1, SEC-1, SEC-2 in core audit` marked this done, but Grep confirms no `authorizeForUser` call exists in the controller.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - In `UserSiteController::update()`, resolve the site before calling `action->execute()` and add `$this->authorizeForUser($professional, 'update', $site)`.
        - In `UserSiteController::visibility()`, do the same.
        - The site can be resolved via `$professional->loadMissing('site')->site` so the Policy check receives a concrete model instance and `denyIfPendingDeletion` can fire.
        - Remove the inline ownership check from `UpdateSiteAction::execute()` (the `if (! $site) { throw ValidationException... }` guard remains as a belt-and-suspenders, but ownership enforcement moves to the Policy).
    - **Technical:** The Authorization Doctrine requires `$this->authorizeForUser($pro, 'verb', $resource)` for every mutating endpoint. `UpdateSiteAction::execute()` resolves `$professional->site` via Eloquent relationship traversal — functionally correct for the one-site-per-professional model, but it bypasses `SitePolicy::update()`, which means (a) `denyIfPendingDeletion` never fires for the dashboard update path, (b) there is no central authorization surface that tests can assert against, and (c) any future multi-site refactor silently loses the ownership gate. `SitePolicy` is already registered and has the correct implementation — the controller just needs to call it.
    - **Plain English:** Every door in the building has a lock, and there's a master key card system that logs who opens what. The design settings door happens to be left slightly ajar because the key card system was never wired up — it works because only the right people know where the door is, but there's no log of who enters and the "account being deleted" warning sign is never checked. The fix is to route the door through the key card system like every other door.
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

---

## P3 — Nice to have

- [ ] **#SCHEMA-2** · P3 — `site.design_kits` table has no `created_at` / `updated_at` columns
    - **Where:** supabase/migrations/20260527070000_skeleton_system_cleanup.sql:116–118 (`CREATE TABLE site.design_kits`)
    - **Affects:** Internal debugging and future audit features. Without timestamps, there's no way to answer "when did this professional last change their design kit?" from the database alone. The parent `site.sites.updated_at` is touched by the controller after a kit-only write, but it's imprecise (any site mutation advances it) and lost entirely if a direct DB write occurs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a new migration: `ALTER TABLE site.design_kits ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ NOT NULL DEFAULT now(), ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT now();`
        - Backfill existing rows: `UPDATE site.design_kits dk SET created_at = s.created_at, updated_at = s.updated_at FROM site.sites s WHERE s.id = dk.site_id;`
        - Add an `AFTER UPDATE` trigger to set `updated_at = now()` on every row update, since `writeDesignKit` uses raw `DB::table` queries (no Eloquent timestamps).
    - **Technical:** All other site-schema tables (`site.sites`, `site.blocks`, `site.services`, `site.site_media`) have `created_at` / `updated_at`. The design_kits table is the only one without them. The `writeDesignKit` method uses raw `DB::connection('pgsql')->table(...)` which never sets Eloquent's automatic timestamp columns, so even if the columns were added the trigger approach is needed.
    - **Plain English:** Every other record in the system has a "created on" and "last updated" stamp — like a date on a filing folder. The design settings table is missing this, so you can't tell from the database alone when someone last changed their brand colours. It's a one-migration fix that will pay off during debugging.
    - **Evidence:**
        ```sql
        CREATE TABLE site.design_kits (
          site_id UUID PRIMARY KEY REFERENCES site.sites(id) ON DELETE CASCADE
        );
        ```

- [ ] **#SCHEMA-3** · P3 — Migration `20260529044737` adds columns without `IF NOT EXISTS`, breaking idempotent re-run
    - **Where:** supabase/migrations/20260529044737_design_kit_contrasting_colors.sql:23–25
    - **Affects:** Deployment recovery — re-running this migration (disaster-restore, branch switch, partial-apply rollback) throws `ERROR: column "color_contrasting_bg" of relation "design_kits" already exists` and halts.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `IF NOT EXISTS` to each `ADD COLUMN` clause.
        - Note: this is the only migration in the design-kit sequence that omits the guard — later migrations (`20260530100000`, `20260530110000`, etc.) all use `IF NOT EXISTS` correctly.
    - **Technical:** PostgreSQL `ALTER TABLE … ADD COLUMN` without `IF NOT EXISTS` throws if the column already exists. For a normal forward deploy this is fine; for a disaster-recovery scenario (restore a backup and replay migrations, or re-apply after a failed partial deploy), the migration cannot distinguish "already applied" from "never applied." The sister migration `20260529053028` has the same gap — see SCHEMA-4.
    - **Plain English:** This change file is safe the first time it runs but fails with an error if it ever needs to run again. Adding "if it's not already there" to each column addition makes it safe to replay in an emergency restore.
    - **Evidence:**
        ```sql
        ALTER TABLE site.design_kits
          ADD COLUMN color_contrasting_bg TEXT NULL,
          ADD COLUMN color_contrasting_text TEXT NULL,
          ADD COLUMN color_placeholder TEXT NULL;
        ```

- [ ] **#SCHEMA-4** · P3 — Migration `20260529053028` drops and adds 34 columns without `IF EXISTS` / `IF NOT EXISTS` guards
    - **Where:** supabase/migrations/20260529053028_design_kit_unified_space_scale.sql:52–80
    - **Affects:** Same as SCHEMA-3 — idempotent re-run fails. Higher blast radius: 24 `DROP COLUMN` (without `IF EXISTS`) and 10 `ADD COLUMN` (without `IF NOT EXISTS`) in a single `ALTER TABLE` statement. Any one already-present or already-absent column causes the entire atomic statement to fail.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `IF EXISTS` to every `DROP COLUMN` clause: `DROP COLUMN IF EXISTS padding_extra_small`.
        - Add `IF NOT EXISTS` to every `ADD COLUMN` clause: `ADD COLUMN IF NOT EXISTS space_xs TEXT NULL`.
    - **Technical:** Atomicity of the single `ALTER TABLE` statement is a feature — it prevents partial column states in a normal deploy. But the lack of idempotency guards means a restore-and-replay fails on the first column mismatch. Migration `20260530100000` handles this correctly using `DO $$ BEGIN IF EXISTS(...) THEN ALTER TABLE ... RENAME ...; END IF; END $$;` blocks — the same `IF EXISTS` approach should be applied here for the drop/add operations.
    - **Plain English:** This file makes 34 column changes in one shot. If it's ever replayed and even one column is already in the right state, the entire operation refuses to run. Adding "if it's there" and "if it's not there" checks to each step makes it safe to replay in any state.
    - **Evidence:**
        ```sql
        ALTER TABLE site.design_kits
          -- Drop old padding scale (base + desktop)
          DROP COLUMN padding_extra_small,
          DROP COLUMN padding_small,
          …
          -- Add unified space scale (mobile base)
          ADD COLUMN space_xs TEXT NULL,
          ADD COLUMN space_s TEXT NULL,
          …;
        ```

- [ ] **#LIFE-3** · P3 — `Log::warning` calls in request classes and notification jobs lack tenant correlation context
    - **Where:** app/Http/Requests/Api/User/Site/UpdateSiteRequest.php (alias check warning), app/Http/Requests/Api/Staff/UserSite/StaffUpdateSiteRequest.php (same), app/Jobs/Notifications/SendEnquiryConfirmationJob.php:failed(), app/Jobs/Notifications/SendSubscriptionConfirmationJob.php:failed()
    - **Affects:** Operations visibility. Without `professional_id` and `request_id` in the log context, Nightwatch cannot correlate alias-check failures or permanently-failed notification jobs to a specific tenant or originating request. Finding the root cause of transient failures across 200 professionals becomes a manual grep exercise.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - In `UpdateSiteRequest` and `StaffUpdateSiteRequest`, add `'professional_id' => $this->attributes->get('professional')?->id` and `'operation' => 'subdomain_alias_check'` to the warning context array.
        - In the job `failed()` handlers, add `'job_id' => $this->job?->getJobId()` and `'attempt' => $this->attempts()` alongside the existing `enquiry_id` / `subscription_id`.
    - **Technical:** The Nightwatch correlation model requires `professional_id` (or `brand_professional_id`) and an operation discriminator for every structured warning so the dashboard can group by tenant and operation type. The alias-check warnings carry only `'error' => $e->getMessage()` — at 200 professionals with subdomain renames happening periodically, a single misconfigured alias would produce warnings indistinguishable from any other professional's failures. The job `failed()` handlers carry only `enquiry_id` / `subscription_id` — adding attempt count lets support distinguish "failed once and recovered" from "failed permanently on attempt 3."
    - **Plain English:** The system writes helpful notes when something goes wrong, but the notes don't say who they're about or which request caused them. At 200 users, that's like a support log that says "something broke" without a customer name or ticket number. Adding a few extra fields turns "something broke" into "Josh's subdomain check failed on request abc123."
    - **Evidence:**
        ```php
        // UpdateSiteRequest.php
        Log::warning('Professional alias check failed in UpdateSiteRequest', ['error' => $e->getMessage()]);

        // StaffUpdateSiteRequest.php
        Log::warning('Professional alias check failed in StaffUpdateSiteRequest', ['error' => $e->getMessage()]);

        // SendEnquiryConfirmationJob.php failed()
        Log::error('SendEnquiryConfirmationJob failed permanently', [
            'enquiry_id' => $this->enquiryId,
            'error' => $e->getMessage(),
        ]);
        ```

- [ ] **#LIFE-4** · P3 — `UpdateSiteRequest` and `StaffUpdateSiteRequest` duplicate ~80 `design_kit.*` validation rules
    - **Where:** app/Http/Requests/Api/User/Site/UpdateSiteRequest.php and app/Http/Requests/Api/Staff/UserSite/StaffUpdateSiteRequest.php (both `rules()` methods)
    - **Affects:** Maintenance velocity. Every migration that adds or drops a `site.design_kits` column requires updating both files identically. The `DesignKitRequestSyncTest` catches drift after the fact, but the duplication itself is ongoing toil — two PRs per column addition.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract the `design_kit.*` rules into a `DesignKitValidationRules` trait (or a static method on `UpdateSiteRequest`) and `use` / call it in both classes.
        - Keep the drift test (`DesignKitRequestSyncTest`) as a safety net; the extraction reduces the "add a column" workflow from two file edits to one.
    - **Technical:** Both `rules()` methods define an identical allowlist of ~80 `'design_kit.{column_name}'` entries — colours (hex-regex), typography (string/max), borders, space scale, icons, effects, sizing, responsive companions, motion, and buttons. `StaffUpdateSiteRequest` already references `UpdateSiteRequest::ALLOWED_SKELETONS` to avoid one duplication; the same delegation pattern should apply to the full rules block. The `DesignKitRequestDriftTest` verifies DB alignment; the unit-level sync test ensures the two request classes stay in lock-step — both tests remain useful after extraction.
    - **Plain English:** There are two identical menus — one for customers, one for staff — and every time a new dish is added, both menus need updating. Moving the shared menu items to a shared master list means one update keeps both menus current.
    - **Evidence:**
        ```php
        // UpdateSiteRequest.php rules() — ~80 design_kit entries:
        'design_kit' => ['sometimes', 'array'],
        'design_kit.color_bg' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
        // ... ~78 more ...

        // StaffUpdateSiteRequest.php rules() — identical block:
        'design_kit' => ['sometimes', 'array'],
        'design_kit.color_bg' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
        // ... ~78 more identical entries ...
        ```

- [ ] **#CCH-2** · P3 — `design_kits:columns` cache uses bare `Cache::remember` with no single-flight lock and no TTL jitter
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php:260–266 (`writeDesignKit`)
    - **Affects:** Concurrent design-kit saves immediately after a deploy (when the `design_kits:columns` key is cold). All concurrent `writeDesignKit()` calls race to query `information_schema.columns` independently instead of single-flighting through one worker. The global key (not per-site) means every concurrent save across all 200 professionals collides on the same cold-cache fill.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Cache::remember('design_kits:columns', 3600, fn() => ...)` with `app(CacheLockService::class)->rememberLocked(CacheKeyGenerator::designKitColumns(), 3600, fn() => ...)`.
        - Add a static `CacheKeyGenerator::designKitColumns()` method to centralise the key string so any future invalidation (or the version-keying fix in LIFE-2) touches one place.
    - **Technical:** The caching gold standard requires `CacheLockService::rememberLocked` for any shared key to prevent stampedes. Without it, N concurrent saves after a `cache:clear` each fire an `information_schema` query in parallel. While `information_schema.columns` is fast (metadata, cached by Postgres), the pattern is inconsistent with every other cached value in the codebase and the blast radius grows with the number of active Horizon workers. The literal key string is also hardcoded in the controller rather than going through `CacheKeyGenerator`, making it invisible to any future bust or version-rotation logic.
    - **Plain English:** After a deploy, if ten users all try to save their design settings at the same moment, each one separately asks the database "what columns exist?" instead of one person asking and sharing the answer. Adding a shared lock makes only one person ask; the rest wait a fraction of a second. Centralising the key name means one change updates every place that references it.
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

- [ ] **#CACHE-1** · P3 — Three `invalidateSite()` calls fire per design-kit-only update; the first two precede the `design_kits` write
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php:40–56 (`update` method flow)
    - **Affects:** Every design-kit-only `PATCH /api/site` — three full cache-invalidation sweeps (each touching ~30 Redis keys) for one logical write. Busts 1 and 2 run before the `site.design_kits` row is updated, so any cache rebuild triggered by them would serve the pre-write kit. Only bust 3 (the explicit post-write call) is authoritative. Note: `SiteObserver.$afterCommit = true` means busts 1 and 2 are correctly deferred past their respective transaction commits — they do not fire inside a transaction — but they still precede the `writeDesignKit` raw SQL.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Suppress bust 1 by wrapping `action->execute($professional, [])` (the empty-data call) in `Site::withoutObservers(fn() => $action->execute($professional, []))` when `$data` is empty — the explicit bust 3 already covers the authoritative post-write invalidation.
        - The `WriteDesignKitTest` asserts `->times(3)` — update that expectation to `->times(2)` after removing the first observer-driven bust.
    - **Technical:** The three bust paths: (1) `execute([])` → `$site->save()` (Eloquent fires `saved` even on a non-dirty save via `finishSave()`) → `SiteObserver::saved()` (afterCommit) → `invalidateSite`. (2) `$site->touch()` → `$site->save()` (dirty timestamp) → observer → `invalidateSite`. (3) Explicit `app(SiteCacheService::class)->invalidateSite($site)`. Bust 1 cannot serve stale-kit data (it runs before the kit write), but it forces a cache rebuild from DB that discards whatever was warm from the previous request. Each `invalidateSite` call issues two `Cache::deleteMultiple` calls each deleting ~15 keys. The fix is to short-circuit the first observer call when the action is invoked with no site-column data.
    - **Plain English:** Three separate fire alarm tests ring every time someone changes a paint colour. The first two ring before the painting is done — any inspector who shows up in that window sees the old paint. Only the third ring, after painting is complete, is meaningful. The first ring is easy to silence by telling the fire alarm to take a break for this particular empty-data save.
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

- [ ] **#TXN-3** · P3 — `UpdateSiteAction::execute()` holds a Postgres transaction open across ~140 lines of mixed reads, pure-PHP computation, and multi-model writes
    - **Where:** app/Services/Site/UpdateSiteAction.php:48–191 (full `DB::transaction` closure)
    - **Affects:** Site update latency under concurrent subdomain changes — the transaction holds an open Postgres connection and row locks for the entire duration of the settings merge computation, publish validation, and all five model saves. On a busy deploy day with 200 professionals renaming subdomains, this extends lock contention unnecessarily.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Hoist the `settings` `array_replace_recursive` merge (lines ~135–148) and the `is_published` completeness check (lines ~150–162) out of the transaction closure. Both are pure PHP with no DB interaction.
        - Consider whether `HandleChangeLog::create()` should use `DB::afterCommit()` or a separate connection so an outer rollback doesn't wipe the audit trail.
        - The subdomain conflict reads, alias writes, handle update, and site save must remain inside the transaction — do not move them.
    - **Technical:** The transaction scope currently wraps: subdomain conflict queries, `SiteSubdomainAlias` create, `UserHandleAlias` create, `$professional->save()`, alias collapse delete, `HandleChangeLog::create()`, `settings` PHP array merge, publish completeness validation, `$site->fill($data)`, `$site->save()`, and `$site->fresh()`. The settings merge (`array_replace_recursive`) and publish check (empty string comparison) are pure computation — zero DB calls, zero risk of dirty reads. Moving them before the `DB::transaction(...)` call reduces the lock window from ~140 lines to ~80 lines and makes the atomic unit visually obvious to future maintainers. Note: `SiteObserver.$afterCommit = true` already ensures cache invalidation happens post-commit, so there is no TXN-safety concern with the current observer setup.
    - **Plain English:** The filing-cabinet lock is held while sharpening a pencil, checking a calendar, and making a phone call — all of which could have been done before the cabinet was opened. Other clerks wait for the entire routine. Doing the prep work outside the locked period means the cabinet is free sooner, and other users can get in faster.
    - **Evidence:**
        ```php
        return DB::transaction(function () use (...): Site {
            // Lines 52–108: subdomain conflict reads + alias writes (must be in tx)

            // Lines 135–148: pure PHP array merge (no DB — can move outside)
            if (array_key_exists('settings', $data)) {
                $existing = is_array($site->settings) ? $site->settings : [];
                $incoming = is_array($data['settings']) ? $data['settings'] : [];
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
        }); // Line 191
        ```
