# Transaction Boundary Correctness Audit — 2026-06-13

**Branch:** development
**Lens:** Transaction boundary correctness — external I/O, queue dispatches, cache writes, and observer side effects inside `DB::transaction` blocks
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-5`
**Source files audited:**
- `app/Services/User/AccountDeletionService.php`
- `app/Services/User/DataExport/DataExportService.php`
- `app/Services/User/UserBootstrapService.php`
- `app/Services/User/SiteProvisioningService.php`
- `app/Services/User/ConfirmationPreferenceService.php`
- `app/Services/Site/ReclaimHandleAction.php`
- `app/Services/Site/UpdateSiteAction.php`
- `app/Services/Moderation/ModerationDecisionService.php`
- `app/Services/Moderation/ModerationCaseService.php`
- `app/Services/Moderation/ContentReportService.php`
- `app/Services/Media/MediaUploadService.php`
- `app/Observers/Core/BlockObserver.php`
- `app/Observers/Core/CustomerObserver.php`
- `app/Observers/Core/IntegrationConnectionObserver.php`
- `app/Observers/Core/ServiceCategoryObserver.php`
- `app/Observers/Core/ServiceObserver.php`
- `app/Observers/Core/SiteMediaObserver.php`
- `app/Observers/Core/SiteObserver.php`
- `app/Observers/Core/SmartLinkObserver.php`
- `app/Observers/User/UserObserver.php`
- `app/Jobs/Account/SendAccountDeletionRequestMailJob.php`
- `app/Jobs/Gdpr/ExportUserDataJob.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 2 complete

---

## P1 — Fix before pilot launch

- [ ] **#TXN-1** · P1 — `ExportUserDataJob` dispatched inside transaction without `afterCommit` protection
    - **Where:** `app/Services/User/DataExport/DataExportService.php:59`
    - **Affects:** GDPR data export for all users. If the DB commit fails after the Redis write (extremely rare but possible), the job fires against an audit ID that doesn't exist and the export silently disappears — the user's right-of-access request is swallowed with only a `Log::warning`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `$this->afterCommit = true;` to `ExportUserDataJob::__construct()`, mirroring the pattern already used by `SendAccountDeletionRequestMailJob` (line 57 of that file).
        - No change to `DataExportService` is required — the existing code structure is correct. The protection belongs on the job class so every dispatch site is guarded automatically.
    - **Technical:** Every queue connection in `config/queue.php` has `after_commit => false` (confirmed at lines 46, 56, 67, 79, 91, 103). There is no blanket safety net. `ExportUserDataJob` is dispatched inside a `DB::connection('pgsql')->transaction(...)` closure at line 59 of `DataExportService::dispatch`. The Redis write happens immediately on `dispatch()`. If the surrounding transaction commits normally, the audit row exists and the job runs correctly. However, the job has no `$afterCommit = true` guard, so the failure mode — commit failure after Redis write — produces an orphaned job. `ExportUserDataJob::handle()` recovers with `Log::warning('ExportUserDataJob: audit row not found', ...)` and returns, silently failing the export. The gold-standard fix (used correctly in `SendAccountDeletionRequestMailJob`) is `$this->afterCommit = true` in the job constructor, which defers the Redis write until after the outer transaction commits. This eliminates the failure mode entirely. The complementary rollback path is also safe: if Redis throws during `dispatch()`, the exception propagates, the transaction rolls back, and no audit row is committed — correct behaviour. Note the current code structure works on the happy path; this is a narrow but real correctness gap that matches an important GDPR compliance concern.
    - **Plain English:** When a user asks for a copy of their data, the system books a slot for the export job before it's confirmed the booking is saved. In the rare case where the save confirmation gets lost, the job runs anyway — looks for its booking number, can't find it, and quietly gives up. The user never gets their data and nobody gets an alert. The fix is to wait until the booking is confirmed before telling the job it can start — one line in the job's setup code.
    - **Evidence:**
        ```php
        return DB::connection('pgsql')->transaction(function () use ($professional, $triggeredBy, $staffId, $sendTo, $recipient) {
            // ...
            $audit = DataExportAudit::create([
                'user_id' => $professional->id,
                // ...
            ]);

            ExportUserDataJob::dispatch($audit->id);   // ← no afterCommit protection

            return $audit;
        });
        ```
        Compare with the correctly guarded sibling job:
        ```php
        // SendAccountDeletionRequestMailJob::__construct()
        $this->afterCommit = true;
        ```

---

## P2 — Should fix

- [ ] **#TXN-2** · P2 — Cache invalidation inside bootstrap transaction can produce a stale-cache window
    - **Where:** `app/Services/User/UserBootstrapService.php:101`
    - **Affects:** User signup and profile-update paths. A concurrent reader arriving in the millisecond gap between cache invalidation (inside the still-open transaction) and the implicit commit can miss cache, re-warm from the database with the old committed state, and hold that stale value for the full cache TTL — even though the new state was committed moments later.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap `$this->cache->invalidateUser($professional)` in `DB::afterCommit(fn() => $this->cache->invalidateUser($professional))` inside the transaction, OR move the call to immediately after the `DB::connection('pgsql')->transaction(...)` block returns.
        - The return value from the transaction (`['professional' => ..., 'site' => ..., 'created' => ...]`) is already available after the block, so moving the invalidation to after the return is straightforward.
    - **Technical:** `UserBootstrapService::bootstrap` executes all DB writes (user save, subscription upsert, site creation, welcome notification) inside a `DB::connection('pgsql')->transaction(...)` closure, then calls `$this->cache->invalidateUser($professional)` before the transaction has committed. PostgreSQL's READ COMMITTED isolation means a concurrent connection between the cache DEL and the commit sees the old committed row, caches it, and the system serves that stale value for the TTL even after the new row commits. The window is very narrow (sub-millisecond in practice for a local commit), but the pattern is a gold-standard violation. Rollback semantics are also affected: if `createWelcomeNotification` throws after the cache invalidation, the transaction rolls back but the cache was already cleared. For existing users, the next reader re-warms from the old (still-correct) committed state, so data is not corrupted — but the double-invalidation and rollback-then-re-warm is wasteful. The fix is `DB::afterCommit`, which is the same pattern all nine observers in this codebase already use correctly (`public bool $afterCommit = true` is set on every observer class).
    - **Plain English:** A clean-up crew arrives to tidy the room (clear the old info from the cache) before the new furniture has been officially logged in the building register. If someone asks reception for the room layout while the crew is working, they'll see the room is empty, check the register (database), find the old layout (the new one isn't committed yet), and fill the cache with the old version. Then the new layout is registered, but the cache already has the wrong one for the next several minutes.
    - **Evidence:**
        ```php
        return DB::connection('pgsql')->transaction(function () use ($uid, $data, $existing) {
            // ...
            $professional->save();

            $this->ensureSidestUpdatesSubscription($professional->primary_email);

            $site = Site::query()->where('user_id', $professional->id)->first();
            if (! $site) {
                // ...
                $site = $this->siteProvisioning->createSiteWithRetry($professional->id, $base);
            }

            $this->cache->invalidateUser($professional);   // ← inside transaction, before commit

            if ($createdProfessional) {
                $this->createWelcomeNotification($professional);
            }

            return [
                'professional' => $professional->fresh(),
                'site' => $site->fresh(),
                'created' => $createdProfessional,
            ];
        });
        ```

---

## P3 — Nice to have

- [ ] **#TXN-3** · P3 — Nested `DB::transaction` in `ReclaimHandleAction` is correct but undocumented
    - **Where:** `app/Services/Site/ReclaimHandleAction.php:25` and `app/Services/Site/UpdateSiteAction.php:85`
    - **Affects:** Handle reclaim operations. No current data integrity bug — exceptions from the inner SAVEPOINT propagate and roll back the outer transaction. The undocumented nesting is a future-developer trap: the wide alias lock and the SAVEPOINT semantics are non-obvious and a future edit inside either closure could introduce a real bug.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a comment above the `$this->updateSiteAction->execute(...)` call in `ReclaimHandleAction::execute` noting that `UpdateSiteAction` opens its own `DB::transaction`, which becomes a SAVEPOINT inside this outer transaction — and that all failures propagate and roll back both.
        - No code change required; the current behaviour is correct.
    - **Technical:** `ReclaimHandleAction::execute` opens a bare `DB::transaction` and takes a `lockForUpdate` on the `UserHandleAlias` row. Inside, it calls `$this->updateSiteAction->execute(...)`, which opens its own `DB::transaction`. Laravel automatically converts the inner call to a `SAVEPOINT / RELEASE SAVEPOINT` pair. If `UpdateSiteAction` throws (e.g. `ValidationException` on a subdomain conflict), the SAVEPOINT is rolled back and the exception propagates through `ReclaimHandleAction`'s closure, causing the outer transaction to roll back too — so no stale alias-lock state is possible. The pattern is safe, but the nesting is invisible from `ReclaimHandleAction`'s call site. This is the same SAVEPOINT strategy used intentionally and with thorough comments in `SiteProvisioningService::tryCreateSite`, which is the codebase's reference implementation.
    - **Plain English:** Two nested safes — the outer holds a key (a lock), the inner does the actual work. If the inner fails, both get reset. There's no bug, but the "both get reset" behaviour isn't written on either safe door. A future developer editing either method might not realise the other is affected.
    - **Evidence:**
        ```php
        // ReclaimHandleAction::execute
        DB::transaction(function () use ($professional, $handle, $context) {
            $alias = UserHandleAlias::query()
                ->where('user_id', $professional->id)
                ->whereRaw('lower(handle) = ?', [$handle])
                ->lockForUpdate()
                ->first();
            // ...
            $this->updateSiteAction->execute(  // ← opens its own DB::transaction (→ SAVEPOINT)
                $professional->fresh(),
                ['subdomain' => $handle],
                array_merge($context, [...])
            );
        });
        ```
        ```php
        // UpdateSiteAction::execute — the nested transaction
        return DB::transaction(function () use ($professional, $site, $data, $options, $allowSubdomainOverride): Site {
            // ...
        });
        ```

- [ ] **#TXN-4** · P3 — `cancel()` and `adminCancel()` use bare `DB::transaction()` while the rest of the class pins to `pgsql`
    - **Where:** `app/Services/User/AccountDeletionService.php:357` (`cancel`) and `app/Services/User/AccountDeletionService.php:430` (`adminCancel`)
    - **Affects:** Feature tests only — production behaviour is identical. In tests, the bare `DB::transaction()` targets the default `sqlite` connection, making the wrapper a no-op for the Eloquent writes inside (which go to `pgsql` via `BaseModel`). A constraint violation inside either cancel flow would not roll back in tests, masking correctness bugs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change `DB::transaction(function () ...` to `DB::connection('pgsql')->transaction(function () ...` in both `cancel()` and `adminCancel()`, matching the pattern already used by `request()` and `executeConfirmation()` in the same class.
    - **Technical:** The same class contains a comment in `request()` explaining the rationale for pinning to `'pgsql'`: "Bare `DB::transaction()` targets the default connection — `sqlite` in feature tests — making the wrapper a no-op and breaking rollback." The `cancel()` and `adminCancel()` methods were written without applying that same reasoning, leaving test coverage for their rollback paths structurally broken. In production, the default connection is `pgsql`, so the behaviour is correct. The inconsistency is a test-reliability hazard, not a runtime bug.
    - **Plain English:** In the same filing system, some folders say "use the blue lock" and others say "use any lock". In the office (production) there is only one lock, so it always works. In the practice room (tests) there are two locks, and "any lock" picks the wrong one — so drills to practice recovering from mistakes don't actually test what they're supposed to.
    - **Evidence:**
        ```php
        // cancel() — bare transaction
        DB::transaction(function () use ($professional, $previousStatus) {
            $professional->update([...]);
            // ...
            $site = Site::query()
                ->where('user_id', $professional->id)
                ->lockForUpdate()
                ->first();
            // ...
        });
        ```
        ```php
        // request() — explicit connection (correct pattern in same class)
        DB::connection('pgsql')->transaction(function () use ($professional, $tokenHash, $confirmationUrl, $request) {
            // ...
        });
        ```
