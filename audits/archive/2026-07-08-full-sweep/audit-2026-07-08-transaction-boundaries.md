# Transaction Boundary Correctness Audit — 2026-07-08

**Branch:** development
**Lens:** Transaction boundary correctness — every `DB::transaction`/`DB::beginTransaction` site measured against the gold standard: no external I/O, queue dispatch, or cache write inside a transaction; `afterCommit` used correctly; narrow scope; safe retries; intentional nesting; consistent lock ordering.
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- app/Services/User (AccountDeletionService, UserBootstrapService, SiteProvisioningService, ConfirmationPreferenceService, DataExport/DataExportService)
- app/Services/Site (RenameSubdomainAction, ReclaimHandleAction, UpdateSiteAction, ReorderService, InsertWithSortOrder, ContentSelectionService)
- app/Services/Moderation (ModerationDecisionService, ModerationActionDispatcher, ModerationCaseService, ContentReportService, EvidenceSnapshotService, ModerationAuditService)
- app/Services/Accounts, app/Services/Auth, app/Services/Feedback
- app/Observers (Core/SiteObserver, BlockObserver, ServiceObserver, ServiceCategoryObserver, CustomerObserver, WorkplaceObserver, SiteMediaObserver, IntegrationConnectionObserver; User/UserObserver)
- app/Services/Cloudflare, app/Services/Streaming, app/Services/Platforms (incl. ShopCatalog), app/Services/Http
- app/Jobs (Account, Gdpr, Moderation, Notifications, Platforms)
- app/Listeners

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [ ] **#TXN-1** · P2 — Account-deletion token write relies on a rollback guarantee that `afterCommit` job dispatch cannot provide
    - **Where:** app/Services/User/AccountDeletionService.php:77-98
    - **Affects:** Self-service account-deletion request flow (`AccountDeletionService::request()`), triggered on every "delete my account" click. Manifests only during a Redis/queue-infrastructure outage that coincides with a deletion request.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Correct the inline comment's claim — a job dispatched with `$afterCommit = true` does not throw inside the transaction; Laravel defers the actual Redis push to a callback that runs via `DatabaseTransactionsManager::commit()`, which fires *after* `$this->getPdo()->commit()` has already physically committed the transaction (`Illuminate\Database\Concerns\ManagesTransactions::commit()`). A Redis failure at that point cannot roll back the token write — it already landed.
        - Add an explicit compensating action: catch the dispatch failure (it will still surface via the existing outer `catch (\Throwable $e)` in `request()`) and issue a follow-up `$professional->update(['deletion_token_hash' => null, 'deletion_requested_at' => null])` so a Redis outage doesn't leave a live (if practically inert) token row after the user is told the request failed.
        - Alternatively, accept the current self-healing behavior (the token is silently overwritten on retry, or expires after 24h via `confirm()`'s staleness check) but update the comment so a future reader doesn't rely on a rollback guarantee that doesn't exist.
    - **Technical:** `SendAccountDeletionRequestMailJob` sets `$this->afterCommit = true` in its constructor, which is a real Laravel mechanism (`Illuminate\Queue\Queue::shouldDispatchAfterCommit()` / `enqueueUsing()`) — verified against vendor source. When `dispatch()` is called inside a transaction with this flag set, Laravel does **not** attempt the Redis push at dispatch time; it registers the push as a callback on `DatabaseTransactionsManager`, which only runs it once the transaction reaches level 0. Critically, `ManagesTransactions::commit()` calls `$this->getPdo()->commit()` (the real Postgres commit) *before* `$this->transactionsManager->commit(...)` (which executes the deferred push). So if Redis is down, the push throws *after* the DB row is already durably committed — there is nothing left to roll back. The comment at lines 86-89 asserts the opposite ("a dispatch infrastructure failure... throws and rolls back the token write"), which is incorrect and gives future maintainers false confidence in an invariant the code doesn't enforce. Practical blast radius is limited: `confirm()` already clears a stale token after 24h, and a retried `request()` overwrites the token unconditionally, so the state self-heals — no PII exposure or ability to complete a deletion results, since the confirmation URL bearing the raw token was never delivered. That bounded impact is why this is P2, not P0/P1.
    - **Plain English:** There's a comment in the code that says "if the email system is down when someone asks to delete their account, we'll undo the whole request automatically." That's not actually true — by the time the system notices the email system is down, the database has already saved the deletion request. It's a bit like a shop till printing "sale voided" on the receipt after the money has already left the customer's card: the till's confirmation is wrong, even though nothing catastrophic happens (the till clears itself the next day). No customer data is exposed and nobody can accidentally get their account deleted, but the code's own explanation of how it protects itself is inaccurate, which risks someone building on that false assumption later.
    - **Evidence:**
        ```php
        DB::connection('pgsql')->transaction(function () use ($professional, $tokenHash, $confirmationUrl, $request) {
            $professional->update([
                'deletion_token_hash' => $tokenHash,
                'deletion_requested_at' => now(),
                // Reset sent_at so the idempotency guard in the job allows re-delivery —
                // mirrors the subscription reset-on-re-subscribe precedent in PublicEmailSubscriptionController.
                'deletion_mail_sent_at' => null,
            ]);

            // Job dispatch runs inside the transaction so a dispatch infrastructure
            // failure (e.g., Redis down) throws and rolls back the token write —
            // no orphaned token left on the row. afterCommit on the job class then
            // delays the worker pickup until this transaction commits.
            SendAccountDeletionRequestMailJob::dispatch(
                $professional->id,
                $confirmationUrl,
                $tokenHash,
            );

            $this->logAuditEvent($professional, UserDeletionAuditEntry::EVENT_REQUESTED, $request);
        });
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Account-deletion comment/invariant fix:** TXN-1
    - **Why grouped:** single file, single method, self-contained fix (comment correction + optional compensating-clear addition).
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet (per file's Execution policy). No escalation needed — this is a documentation/edge-case fix, not gnarly.

## Standalone — do NOT bundle

None.
