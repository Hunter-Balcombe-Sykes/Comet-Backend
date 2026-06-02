`★ Insight ─────────────────────────────────────`
**PostgreSQL vs SQLite error model divergence is the most subtle and dangerous test gap here.** PostgreSQL aborts the entire transaction on any SQL error — not just the statement. SQLite and MySQL don't. When `tryCreateSite()` catches a `23505` QueryException at the PHP level, the *PostgreSQL connection* is still in an aborted state; every subsequent SQL call in the outer `DB::transaction()` will return "current transaction is aborted." The test suite's SQLite backend silently masks this, so it has never failed a CI run.

**Pattern consistency is a correctness signal.** Four other notification jobs (`SendEnquiryConfirmationJob`, `SendSubscriptionConfirmationJob`, `ExportUserDataJob`, `SendTransactionalNotificationEmailJob`) all use `lockForUpdate + *_sent_at` to guard against duplicate sends on retry. `SendAccountDeletionRequestMailJob` breaking this established pattern is itself a signal that something was missed — pattern audits surface these.
`─────────────────────────────────────────────────`

# Lifecycle, Race & Idempotency Audit — 2026-05-31

**Branch:** development
**Lens:** Missing transactions, race conditions, soft-delete consistency, FK/unique constraint gaps, N+1 writes, observer side-effects outside transactions, double-dispatch on retried writes
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Services/Moderation/ModerationCaseService.php
- app/Services/Moderation/ModerationDecisionService.php
- app/Services/Moderation/ModerationActionDispatcher.php
- app/Services/User/UserBootstrapService.php
- app/Services/User/SiteProvisioningService.php
- app/Jobs/Account/SendAccountDeletionRequestMailJob.php
- app/Jobs/Moderation/SuspendUserJob.php
- app/Jobs/Notifications/ (idempotency-pattern reference)
- supabase/migrations/20260526000000_baseline_standalone_user.sql (index verification)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 0 complete

---

## P1 — Fix before pilot launch

- [ ] **#PROV-1** · P1 — Subdomain retry loop aborts the outer signup transaction on PostgreSQL
    - **Where:** app/Services/User/SiteProvisioningService.php:84–106, called inside the `DB::transaction` in app/Services/User/UserBootstrapService.php:42
    - **Affects:** Any new user whose first-choice subdomain is already taken — common first names and popular handles. The entire signup transaction rolls back and the user receives a database error instead of a successful account creation. The SQLite-backed test suite does not reproduce this because SQLite does not abort transactions on statement errors.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the body of `tryCreateSite()` in a nested `DB::transaction()` call (i.e., `DB::transaction(function() use (...) { $site->save(); return $site; })`).
        - Laravel translates a nested `DB::transaction()` to a named PostgreSQL `SAVEPOINT` / `ROLLBACK TO SAVEPOINT`, isolating the unique-violation failure from the outer bootstrap transaction.
        - The existing `catch (QueryException $e)` block can remain; it just needs to run outside — or instead of — the nested call, catching the re-thrown exception after the savepoint is rolled back.
        - Add a feature test against the real pgsql connection (or a stub that throws a 23505 QueryException on the first call) to prevent regression.
    - **Technical:** PostgreSQL's error model aborts the entire connection-level transaction on any SQL error. `tryCreateSite()` catches the `QueryException(23505)` at the PHP level, but the underlying PostgreSQL transaction is now in an aborted state. The retry loop then attempts `$site->save()` for the next subdomain candidate; PostgreSQL returns "current transaction is aborted, commands ignored until end of transaction block" — error code `25P02`, not `23505` — so the catch block re-throws it. This exception propagates up through `createSiteWithRetry()` into the `DB::transaction()` closure in `UserBootstrapService::bootstrap()`, which catches it, issues a `ROLLBACK`, and re-throws. The controller receives an unhandled `QueryException` and the signup fails. The test suite uses SQLite in-memory (`phpunit.xml` `DB_CONNECTION=sqlite`) and SQLite does not abort transactions on statement errors, so no existing test exercises this path against PostgreSQL's stricter semantics.
    - **Plain English:** When a new user picks a username that's already taken, the system is supposed to silently try variations — "john", then "john-1", then "john-2", and so on. The code does attempt this. But on the real database (PostgreSQL), the first failed attempt leaves the whole sign-up session in a "frozen" state: no further database operations are allowed until the session is reset. The retry loop never resets it — so every subsequent attempt also fails. The user sees a sign-up error even though a perfectly valid username variation was available. Because automated tests run against a simpler database that doesn't have this freeze-on-error rule, the bug has never been caught by CI.
    - **Evidence:**
        ```php
        // SiteProvisioningService::tryCreateSite (line 84-106)
        // Catches 23505 at PHP level, but the PostgreSQL outer transaction is now ABORTED.
        private function tryCreateSite(string $userId, string $candidate): ?Site
        {
            try {
                $site = new Site([
                    'subdomain' => $candidate,
                    'is_published' => true,
                    'settings' => [],
                ]);

                $site->user_id = $userId;
                $site->save(); // ← throws QueryException(23505) on collision

                return $site;
            } catch (QueryException $e) {
                if ($this->isUniqueViolation($e)) {
                    return null; // ← PHP unwinds cleanly; PostgreSQL tx remains ABORTED
                }
                throw $e;
            }
        }

        // UserBootstrapService::bootstrap — createSiteWithRetry is inside DB::transaction
        return DB::transaction(function () use ($uid, $data, $existing) {
            ...
            $site = $this->siteProvisioning->createSiteWithRetry($professional->id, $base);
            ...
        });
        ```

---

## P2 — Should fix

- [ ] **#MOD-1** · P2 — Race condition in case take/decide: status guard runs outside the transaction
    - **Where:** app/Services/Moderation/ModerationCaseService.php:63–65 and app/Services/Moderation/ModerationDecisionService.php:47–49
    - **Affects:** Staff moderation workflows — two staff members acting on the same case concurrently may receive a 422 (illegal transition) instead of the intended 409 (conflict), producing an ambiguous error in the staff UI.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `take()`: move the `$case->status === 'under_review'` check inside the `DB::transaction` callback, after re-loading the case with `ModerationCase::query()->lockForUpdate()->findOrFail($case->id)`.
        - In `decide()`: apply the same pattern for the `$case->status === 'resolved'` check.
        - The locked re-fetch ensures the status check and the state-machine transition are atomic; no concurrent request can change the status between the read and the write.
    - **Technical:** Both guards read `$case->status` from the in-memory model loaded by the controller, before entering the transaction. Between that read and `$this->sm->transition(...)` inside the transaction, a concurrent request can complete the same transition. When that happens, the state machine (which also reads from the in-memory object, not a re-fetched row) throws `IllegalCaseTransition` (422) rather than the intended `CaseAlreadyTaken`/`CaseAlreadyResolved` (409). Moving the guard inside the transaction behind a `lockForUpdate` makes the read-check-transition atomic at the database level.
    - **Plain English:** Two staff members can open the same moderation case at the same moment. The system checks whether the case is already claimed *before* entering the "claim" operation — but if both staff members check at the exact same instant, both see it as unclaimed. One successfully claims it; the other gets a confusing generic error ("invalid state transition") instead of a clear "someone else already has this" message. Moving the check into the protected zone ensures only one can succeed, and the second gets the right informative error.
    - **Evidence:**
        ```php
        // ModerationCaseService::take — guard precedes the transaction
        public function take(ModerationCase $case, PartnaStaff $staff): ModerationCase
        {
            // Concurrency guard: already-claimed is a 409 conflict, not a 422.
            if ($case->status === 'under_review') {
                throw new CaseAlreadyTaken($case->id);
            }

            return DB::transaction(function () use ($case, $staff) {
                $this->sm->transition($case, 'under_review');
                $case->save();
                ...
            });
        }

        // ModerationDecisionService::decide — guard also precedes the transaction
        if ($case->status === 'resolved') {
            throw new CaseAlreadyResolved($case->id);
        }

        $decision = DB::transaction(function () use ($case, $staff, $dto) {
            ...
            $this->sm->transition($case, 'resolved');
            ...
        });
        ```

- [ ] **#TRAN-1** · P2 — Check-then-insert race in bootstrap email subscription creation
    - **Where:** app/Services/User/UserBootstrapService.php:130–153 (inside `DB::transaction` at line 42)
    - **Affects:** New user signups — concurrent bootstraps from the same auth user (two devices or tabs submitted at the same instant) can both pass the existence check and collide on the unique index, propagating an unhandled `QueryException` that rolls back the entire signup transaction.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the `first()` existence check and `$sub->save()` with an atomic insert: `DB::table('notifications.email_subscriptions')->insertOrIgnore([...])`.
        - `insertOrIgnore()` generates `INSERT ... ON CONFLICT DO NOTHING` in PostgreSQL — it is atomic and never errors on a duplicate, so the outer transaction is never at risk.
        - If model lifecycle methods on `EmailSubscription` are required (e.g., `markSubscribed`), wrap `$sub->save()` in a nested `DB::transaction()` and catch the re-thrown `23505` QueryException so the savepoint roll-back (not the outer transaction) absorbs the conflict.
    - **Technical:** The method SELECTs for an existing row, and if absent, inserts. Between the `SELECT` and the `INSERT`, a concurrent bootstrap with the same email can insert first. The partial unique index `email_subscriptions_unique_global_list_email_lc ON notifications.email_subscriptions (list_key, email_lc) WHERE (professional_id IS NULL)` then rejects the second `INSERT` with a `23505` error. Because `ensureSidestUpdatesSubscription` has no exception handler, the `QueryException` propagates out of the `DB::transaction()` callback; the transaction manager rolls back the entire signup and re-throws. `insertOrIgnore()` eliminates the race by pushing the duplicate-detection into a single atomic database instruction.
    - **Plain English:** When someone submits the sign-up form twice in quick succession (two tabs, double-tap), both attempts check the newsletter list at almost the same moment, both see the email isn't there yet, and both try to add it. The database allows only one copy, so the second attempt crashes with an error — and since the whole sign-up was happening as one bundled operation, the crash rolls everything back and the user sees a sign-up error. Using a database-level "add this only if it isn't there yet" instruction avoids the collision entirely.
    - **Evidence:**
        ```php
        private function ensureSidestUpdatesSubscription(?string $email): void
        {
            $email = is_string($email) ? strtolower(trim($email)) : '';
            if ($email === '') {
                return;
            }

            $listKey = 'sidest_updates';

            $existing = EmailSubscription::query()
                ->whereNull('user_id')
                ->where('list_key', $listKey)
                ->where('email_lc', $email)
                ->first();

            if ($existing) {
                return;
            }

            $sub = new EmailSubscription([
                'user_id' => null,
                'list_key' => $listKey,
                'email' => $email,
                'email_lc' => $email,
                'full_name' => null,
                'unsubscribe_token' => EmailSubscription::newUnsubscribeToken(),
            ]);

            $sub->markSubscribed(['source' => 'bootstrap']);
            $sub->save();
        }
        ```

- [ ] **#JOB-1** · P2 — Missing idempotency guard in SendAccountDeletionRequestMailJob
    - **Where:** app/Jobs/Account/SendAccountDeletionRequestMailJob.php:44–60
    - **Affects:** Users requesting account deletion — if the Horizon worker crashes after the MTA accepts the message but before the job completes, the 3-retry backoff (`$backoff = [30, 120, 300]`) will send duplicate confirmation emails.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `deletion_mail_sent_at TIMESTAMPTZ` column to `core.users` via a Supabase migration.
        - In `handle()`, open a `DB::transaction()`, re-fetch the user with `lockForUpdate()`, verify `deletion_token_hash` still equals `hash('sha256', $this->rawToken)` (stale-retry guard), and verify `deletion_mail_sent_at` is `null` (idempotency guard) — return early if either check fails.
        - After a successful `Mail::to()->send()`, stamp `deletion_mail_sent_at = now()` via `forceFill()->saveQuietly()`.
        - This matches the pattern used by `SendEnquiryConfirmationJob` (`confirmation_sent_at`), `SendSubscriptionConfirmationJob` (`confirmation_sent_at`), `SendTransactionalNotificationEmailJob` (`email_sent_at`), and `ExportUserDataJob` (`email_sent_at`).
    - **Technical:** `$tries = 3`, `$backoff = [30, 120, 300]`. The job calls `Mail::to()->send()` with no prior check for whether the mail was already delivered. The existing `failed()` callback correctly clears `deletion_token_hash` on permanent failure, preserving the "user holds a token IFF the confirmation email was sent" invariant — but this only fires after all retries are exhausted. A crash between SMTP acceptance and job completion on an intermediate attempt causes retries with the same `$rawToken`, producing duplicate emails. Every other transactional notification job in the codebase uses `lockForUpdate + *_sent_at` to guard this exact path; this job is the only exception.
    - **Plain English:** When a user requests account deletion, the system sends a confirmation email. If the server crashes at exactly the wrong moment — after the email goes out but before the system marks the task as done — the system assumes the email was never sent and tries again, sending the user a second or third copy of the same email. Every other confirmation email in this codebase has a safeguard against this: it stamps a "sent" flag in the database the moment the email is dispatched, so any retry immediately sees the flag and stops. This job was the one that was missed.
    - **Evidence:**
        ```php
        public function handle(): void
        {
            $professional = User::query()->find($this->userId);
            if ($professional === null) {
                // User was purged/cancelled between dispatch and execution — nothing to send.
                return;
            }

            $confirmationUrl = rtrim((string) config('app.frontend_url'), '/')
                .'/account/deletion/confirm?token='.$this->rawToken;

            Mail::to($professional->primary_email)->send(
                new AccountDeletionRequestedMail(
                    displayName: (string) ($professional->display_name ?? 'there'),
                    confirmationUrl: $confirmationUrl,
                )
            );
        }
        ```

`★ Insight ─────────────────────────────────────`
**The PostgreSQL savepoint pattern is the correct tool whenever you need to "try and recover" within a larger transaction.** Laravel's `DB::transaction()` nesting uses `SAVEPOINT` automatically — you don't need raw SQL. Any place the codebase catches a `QueryException` from within an outer transaction should be using this pattern. `SiteProvisioningService` and `UserBootstrapService` are two sides of the same architectural gap.

**`insertOrIgnore()` vs `updateOrCreate()` for idempotent inserts:** `updateOrCreate()` is a PHP-level check-then-act — it still races. `insertOrIgnore()` generates `INSERT ... ON CONFLICT DO NOTHING` in PostgreSQL, which is a single atomic server-side instruction. Use `insertOrIgnore()` whenever you want "create if absent and never fail on a race."
`─────────────────────────────────────────────────`

The four findings above cover one new P1 discovered during adjudication (PostgreSQL transaction abort on subdomain retry, masked by the SQLite test suite) and three verified P2s from the DeepSeek draft; all `[DRAFT, confidence: X.X]` markers have been stripped and evidence confirmed verbatim against source.
