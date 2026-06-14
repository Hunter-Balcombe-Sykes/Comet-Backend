- [ ] **#TXN-1** · P1 — Job dispatch inside transaction without `afterCommit` in `AccountDeletionService::request`
    - **Where:** app/Services/User/AccountDeletionService.php inside `request()` method
    - **Affects:** Account deletion initiation; a rolled-back token write can leave a dispatched `SendAccountDeletionRequestMailJob` that never finds its confirmation token
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `SendAccountDeletionRequestMailJob::dispatch(...)` with `DB::afterCommit(fn() => SendAccountDeletionRequestMailJob::dispatch(...))` inside the transaction.
        - Alternatively move the dispatch after the `DB::transaction` block returns.
    - **Technical:** Queue dispatches write to Redis immediately. If the surrounding transaction rolls back (e.g. DB constraint violation), the token write is reverted but the job already exists in Redis. The job will execute against a user row where `deletion_token_hash` is null, so the confirmation mail never sends and the state invariant breaks. Every queue connection in `config/queue.php` has `after_commit => false` — there is no blanket protection. The fix is explicit `DB::afterCommit` wrapping or moving the dispatch outside the transaction.
    - **Plain English:** It's like mailing a letter before you've signed the contract. If the contract falls through, the letter still arrives and the recipient acts on a deal that never happened — except in this case the mail is the account deletion confirmation, and if it fails to send because the token is missing, the user is stuck.
    - **Evidence:**
        ```php
        DB::connection('pgsql')->transaction(function () use ($professional, $tokenHash, $confirmationUrl, $request) {
            $professional->update([
                'deletion_token_hash' => $tokenHash,
                'deletion_requested_at' => now(),
                'deletion_mail_sent_at' => null,
            ]);

            SendAccountDeletionRequestMailJob::dispatch(
                $professional->id,
                $confirmationUrl,
                $tokenHash,
            );

            $this->logAuditEvent($professional, UserDeletionAuditEntry::EVENT_REQUESTED, $request);
        });
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#TXN-2** · P1 — Job dispatch inside transaction without `afterCommit` in `DataExportService::dispatch`
    - **Where:** app/Services/User/DataExport/DataExportService.php inside `dispatch()` method
    - **Affects:** GDPR data export initiation; a rolled-back audit row can orphan a queued `ExportUserDataJob` with a nonexistent audit ID
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `ExportUserDataJob::dispatch($audit->id)` with `DB::afterCommit(fn() => ExportUserDataJob::dispatch($audit->id))` inside the transaction.
        - Or move the dispatch after the `DB::transaction` block returns.
    - **Technical:** Same failure mode as TXN-1: Redis write happens inside the transaction. If the `DataExportAudit` row fails to commit (e.g. duplicate detection killed by the pessimistic lock), the job still picks up an audit ID that doesn't exist in the database, leading to a 5xx / permanent failure. No `after_commit => true` on any queue connection — explicit protection is required.
    - **Plain English:** Imagine writing an order number on a sticky note and handing it to the warehouse before the order is saved in the system. If the system rejects the order, the warehouse still tries to pack a box for an order that never existed.
    - **Evidence:**
        ```php
        return DB::connection('pgsql')->transaction(function () use ($professional, $triggeredBy, $staffId, $sendTo, $recipient) {
            // ...
            $audit = DataExportAudit::create([
                'user_id' => $professional->id,
                // ...
            ]);

            ExportUserDataJob::dispatch($audit->id);

            return $audit;
        });
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#TXN-3** · P1 — Cache write inside transaction in `UserBootstrapService::bootstrap`
    - **Where:** app/Services/User/UserBootstrapService.php inside `bootstrap()` method
    - **Affects:** New user signup or profile update; a rolled-back transaction can leave stale cache that shows a user record that doesn't exist in the database
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move `$this->cache->invalidateUser($professional)` after the `DB::transaction` block, optionally wrapped in a try/catch.
        - Alternatively use `DB::afterCommit(fn() => $this->cache->invalidateUser($professional))` inside the transaction.
    - **Technical:** `UserCacheService::invalidateUser` deletes Redis keys. If the DB transaction rolls back after the cache deletion has already occurred, the Redis cache no longer contains the pre-existing stale data but the DB still does — a subsequent reader will cache a fresh copy of the (still-valid) row immediately, so the risk is mostly double-work rather than data corruption. However, if the cache was the only source of truth for a negative case (e.g. a check if user exists), a rollback could produce a “user exists” signal in cache while the DB row was never committed. Consistency demands that cache invalidation happens only after commit.
    - **Plain English:** You painted over a whiteboard before the final numbers were approved. If the boss changes the numbers back, you've already wiped the old ones and will have to repaint from scratch, and anyone who walked by in the meantime saw the wrong figures.
    - **Evidence:**
        ```php
        return DB::connection('pgsql')->transaction(function () use ($uid, $data, $existing) {
            // ... save professional, create site ...
            $this->cache->invalidateUser($professional);
            // ...
        });
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#TXN-4** · P2 — Nested transaction without explicit intent in `ReclaimHandleAction`
    - **Where:** app/Services/Site/ReclaimHandleAction.php:execute()
    - **Affects:** Handle reclaim logic; a failure deep inside `UpdateSiteAction` may roll back a SAVEPOINT but leave the outer transaction’s lock and alias read stale
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Confirm whether the outer transaction is necessary. The alias check and the call to `UpdateSiteAction::execute` could be re-sequenced to avoid nesting (e.g. lock-then-release, then call UpdateSiteAction outside a transaction).
        - If nesting is intentional, document it and verify that the inner transaction’s SAVEPOINT rollback never leaves the outer transaction in an inconsistent state (the alias lock is still held).
    - **Technical:** `ReclaimHandleAction::execute` opens a `DB::transaction` and inside calls `$this->updateSiteAction->execute(...)`, which itself opens a `DB::transaction`. Laravel converts the inner call into a SAVEPOINT. If the inner savepoint rolls back, the outer transaction continues with a state that may no longer be valid (e.g., the alias was acted upon but the site rename failed). Additionally, the outer lock on `UserHandleAlias` is held across the inner transaction’s work, making the lock window wider than necessary.
    - **Plain English:** It’s like opening a safe within a safe. The inner door can jam and lock independently; the outer door is still open, but the contents are now in a confusing half‑locked state. The simpler approach is to grab what you need from the first safe, then close it and open the second one in a separate visit.
    - **Evidence:**
        ```php
        DB::transaction(function () use ($professional, $handle, $context) {
            $alias = UserHandleAlias::query()->...->lockForUpdate()->first();
            // ...
            $this->updateSiteAction->execute(
                $professional->fresh(),
                ['subdomain' => $handle],
                array_merge($context, [...])
            );
        });
        ```
        and inside `UpdateSiteAction::execute`:
        ```php
        return DB::transaction(function () use (...) { ... });
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#TXN-5** · P3 — Inconsistent transaction connection in `AccountDeletionService` cancel methods
    - **Where:** app/Services/User/AccountDeletionService.php in `cancel()` and `adminCancel()`
    - **Affects:** Transactional safety in tests; could mask rollback failures
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change `DB::transaction(...)` to `DB::connection('pgsql')->transaction(...)` in both methods to match the explicit pattern used by `request()` and `executeConfirmation()` in the same class.
    - **Technical:** `request()` uses `DB::connection('pgsql')->transaction()` because the models enforce the `pgsql` connection and Laravel’s default connection may be `sqlite` in tests. The `cancel`/`adminCancel` methods use a bare `DB::transaction()`, which targets the default connection. In production both resolve to `pgsql`, but the inconsistency means a future change of the default connection could break rollback guarantees, and in feature tests the wrapper may be a no‑op for the Eloquent writes inside, hiding bugs.
    - **Plain English:** In the same filing cabinet, some drawers have a label saying “lock with key A” and others have no label. They all currently use key A, but if someone changes the master key, the unlabelled drawers won't lock properly and nobody would notice until a break‑in.
    - **Evidence:**
        ```php
        // cancel() – bare transaction
        DB::transaction(function () use ($professional, $previousStatus) {
            $professional->update([...]);
            // ...
        });
        ```
        Compare with:
        ```php
        // request() – explicit connection
        DB::connection('pgsql')->transaction(function () use (...) { ... });
        ```
    - `[DRAFT, confidence: 0.85]`
