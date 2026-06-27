`★ Insight ─────────────────────────────────────`
Two significant adjudication corrections emerged from tool verification:
1. `CREATE OR REPLACE TRIGGER` is **valid** PostgreSQL 14+ syntax — the entire baseline migration uses it for every trigger (30+ instances). DeepSeek's SCHEMA-1 P0 was fabricated from an older PostgreSQL mental model.
2. The `FeedbackPolicy.php` provided in source shows **no** `unset($denied)` pattern — SEC-3's evidence was hallucinated/stale. The policy just has a plain comment.
`─────────────────────────────────────────────────`

Key decisions from tool checks:
- **SCHEMA-1 (P0 "invalid trigger syntax")** → **Dropped.** Baseline uses `CREATE OR REPLACE TRIGGER` on every single trigger; it's valid on PG 14+ (Supabase runs PG 15+).
- **SEC-3 ("dead code denial check")** → **Dropped.** The `unset($denied)` pattern doesn't exist in the actual source; the policy has a clean comment-only explanation.
- **SEC-2 + LIFE-2** → **Merged** (identical root cause, same file, same line). Re-tiered P1→P2: consequence is silent degradation of abuse-detection, not user data exposure or auth bypass.
- **SCALE-1** → **Dropped.** Confidence 0.5, not a security/data issue.
- **SEC-4 ("dead trashed() check")** → **Confirmed valid** via `routes/api/professional.php`: the route uses `->whereUuid()` with no `->withTrashed()`, so `$feedback->trashed()` in `show()` is unreachable. Kept as P3.
- **SCHEMA-2 (FORCE RLS)** → **Downgraded to P3.** Tool check shows only `core.partna_staff` has `FORCE` among 30+ tables; it's not the codebase norm, making this systemic. Kept but noted as such.
- **LIFE-5** → **Downgraded from P2 to P3.** Internal team recipient list only; LIFE-3's fix largely subsumes the duplication concern.

---

# Feedback System Audit — 2026-05-25

**Branch:** development
**Lens:** Bundle 'core' audit across 8 focused themes: security/policy (SEC-*), lifecycle correctness (LIFE-*), scaling antipatterns (CACHE-*), database/queue scaling — N+1/throughput (SCALE-*), schema/RLS correctness (SCHEMA-*), caching gold-standard adherence (CCH-*), webhook idempotency & delivery (WHK-*), and transaction-boundary correctness (TXN-*)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- supabase/migrations/20260526210001_create_feedback_table.sql
- app/Models/Core/Feedback.php
- app/Policies/FeedbackPolicy.php
- app/Http/Controllers/Api/Professional/Feedback/FeedbackController.php
- app/Http/Requests/Api/Professional/Feedback/SubmitFeedbackRequest.php
- app/Http/Resources/FeedbackResource.php
- app/Services/Feedback/Exceptions/DuplicateFeedbackException.php
- app/Services/Feedback/Exceptions/FeedbackNotAllowedException.php
- app/Services/Feedback/FeedbackService.php
- app/Jobs/Notifications/SendFeedbackEmailJob.php
- app/Mail/FeedbackSubmittedMail.php
- resources/views/emails/feedback-submitted.blade.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 5 complete
- P3 Low: 0 of 4 complete

---

## P2 — Should fix

- [ ] **#SEC-1** · P2 — `user_agent` from HTTP header bypasses FormRequest validation and lands in the database unvalidated
    - **Where:** app/Services/Feedback/FeedbackService.php:72
    - **Affects:** `core.feedback` rows written when the frontend omits `user_agent`; internal email recipients who may receive rows with null bytes, control characters, or encoding surprises in the user-agent string.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `prepareForValidation()` merge in `SubmitFeedbackRequest` that injects the header fallback before rules run: `$this->merge(['user_agent' => $this->input('user_agent') ?? mb_substr((string) $this->userAgent(), 0, 1024) ?: null]);`
        - Remove the fallback from `FeedbackService::submit()` so all paths go through the validated `$data` array.
    - **Technical:** `SubmitFeedbackRequest` declares `user_agent` as `nullable|string|max:1024`, but `FeedbackService::submit()` bypasses validation entirely when the frontend omits the field — it grabs the raw `User-Agent` header directly and truncates to 1024 chars without running it through the FormRequest rules. The header is never `trimStrings()`-processed, never checked for control characters, and `max:1024` is enforced by `mb_substr` rather than the validator. The validation boundary has a documented hole: the Blade template uses `{{ }}` everywhere so XSS risk is negligible, but the database receives unvalidated input. Moving the fallback into `prepareForValidation()` closes the gap at zero cost.
    - **Plain English:** The app has a guarded front door (a validation form that checks what comes in) and an unguarded side door (a raw HTTP header grabbed directly). When the browser doesn't send the user-agent field through the form, the app skips all the safety checks and pulls it straight from the header. The fix routes everything through the same door.
    - **Evidence:**
        ```php
        // user_agent: validated input wins, then header. Capped to 1024 to
        // match the validation rule even when sourced from a header.
        'user_agent' => $data['user_agent'] ?? mb_substr((string) $request->userAgent(), 0, 1024) ?: null,
        ```

- [ ] **#LIFE-4** · P2 — `SendFeedbackEmailJob` log context omits `user_id`; Nightwatch cannot correlate job failures to the submitting user
    - **Where:** app/Jobs/Notifications/SendFeedbackEmailJob.php — `handle()` and `failed()` log calls
    - **Affects:** On-call debugging. When the email job fails or warns, Nightwatch can only group by `feedback_id`. Tracing a specific user's submission through the notification pipeline requires a manual Supabase lookup.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `public readonly string $userId` constructor parameter and update the dispatch call in `FeedbackService` to pass `(string) $actor->id` alongside the feedback ID.
        - Add `'user_id' => $this->userId` to every `Log::warning` and `Log::error` context array in `handle()` and `failed()`.
    - **Technical:** Nightwatch groups exceptions and log entries by their context fields. Without `user_id`, all `SendFeedbackEmailJob` failures across all users are indistinguishable in the dashboard — you see "feedback row not found" with no actor context. The early-return warnings (no recipients configured, row not found) cannot source `user_id` from the feedback row since the row either hasn't been loaded or is null; it must come from the constructor. The `failed()` method has the same limitation. Storing `user_id` in the constructor alongside `feedbackId` costs one extra string in the Redis payload and fixes all three log sites. This mirrors the established pattern from prior Stripe audit fixes (`#STRIPE-2`).
    - **Plain English:** When a notification email fails, the error log says "job #12345 failed" but doesn't say whose feedback triggered it. Finding out requires a separate database lookup. Adding the user ID to the log at dispatch time means every log line immediately tells you who was affected, the same way a good ticket system always records the customer's name alongside the ticket number.
    - **Evidence:**
        ```php
        Log::warning('SendFeedbackEmailJob: no recipients configured (FEEDBACK_NOTIFY_EMAILS empty)', [
            'feedback_id' => $this->feedbackId,
            // user_id missing
        ]);
        // ...
        Log::warning('SendFeedbackEmailJob: feedback row not found', [
            'feedback_id' => $this->feedbackId,
            // user_id missing
        ]);
        // ...
        Log::error('SendFeedbackEmailJob failed permanently', [
            'feedback_id' => $this->feedbackId,
            'error' => $e->getMessage(),
            // user_id missing
        ]);
        ```

- [ ] **#LIFE-3** · P2 — `SendFeedbackEmailJob` has no idempotency guard; queue re-delivery causes duplicate internal notification emails
    - **Where:** app/Jobs/Notifications/SendFeedbackEmailJob.php — `handle()` method
    - **Affects:** Internal team receiving `team@` notification emails. Redis is at-least-once delivery; a worker crash after `Mail::send()` completes but before the job is acknowledged causes the job to be retried, sending the email again.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `mail_sent_at timestamptz NULL` column to `core.feedback` in a new migration.
        - At the top of `handle()`, load the feedback row and return early if `mail_sent_at IS NOT NULL`.
        - Use `lockForUpdate()` when loading the row, then set `$feedback->mail_sent_at = now()` inside a `DB::transaction()` wrapping the `Mail::send()` dispatch — or, simpler, set `mail_sent_at` before sending and unset it on exception, mirroring the `a05b0c2` webhook dedup rollback pattern from `fix(webhooks): roll back dedup marker on Mail::queue failure`.
    - **Technical:** The job has `$tries = 3` with `$backoff = [30, 120, 600]`. This is correct error-handling posture for transient SMTP failures, but it does not prevent duplicate execution: if a worker crashes mid-handle after `Mail::send()` has already delivered, the Redis queue retries the full job. The canonical fix is a DB-level dedup marker on the parent row — a `mail_sent_at` timestamp that is checked before sending and set atomically with the send. The recent commit `a05b0c2` (`fix(webhooks): roll back dedup marker on Mail::queue failure`) established exactly this pattern for webhook jobs; apply it here.
    - **Plain English:** A postal worker who delivers a letter and then trips while marking it "delivered." When they get back up, they pick up the same letter and deliver it again. The fix is to mark the envelope as delivered in the system before handing it over — if the worker trips, the system knows not to send it twice. The codebase already uses this pattern for other notifications.
    - **Evidence:**
        ```php
        // No dedup check anywhere in handle():
        public function handle(): void
        {
            $recipients = (array) config('partna.feedback.notify_emails', []);
            // ...
            $feedback = Feedback::query()->with('user:id,primary_email')->find($this->feedbackId);
            // ...
            foreach ($recipients as $recipient) {
                // ...
                Mail::to($recipient)->send(new FeedbackSubmittedMail($feedback, $userEmail));
            }
        }
        ```

- [ ] **#LIFE-2** · P2 — `hashIp()` silently returns `null` when `FEEDBACK_IP_HASH_PEPPER` is empty; abuse-correlation index degrades with no alert
    - **Where:** app/Services/Feedback/FeedbackService.php — `hashIp()` method
    - **Affects:** Abuse and trust-and-safety operations. A missing or empty `FEEDBACK_IP_HASH_PEPPER` env var on any deploy writes every `ip_hash` column as `NULL`, the `feedback_ip_hash_recent_idx` partial index (`WHERE ip_hash IS NOT NULL`) becomes empty, and all duplicate-window and abuse-pattern queries silently return nothing.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a deploy-time assertion in `AppServiceProvider::boot()`: `if (app()->isProduction() && empty(config('partna.feedback.ip_hash_pepper'))) { throw new \RuntimeException('FEEDBACK_IP_HASH_PEPPER must be set in production'); }`
        - Change the silent `return null` in `hashIp()` when the pepper is missing to `Log::error('FeedbackService: ip_hash_pepper not configured — IP hashing disabled', ['env' => app()->environment()])` before returning null, so Nightwatch surfaces any misconfiguration in non-production environments too.
    - **Technical:** The migration comment promises "IP stored as SHA256(ip || pepper). Pepper lives in env. Raw IP never persists." A single missing env var silently invalidates that promise — no hash is stored, no error surfaces, the partial index `WHERE ip_hash IS NOT NULL` skips every row, and the feature disappears without a trace. The identical silent-degradation pattern was established as a P0 for `VerifyHydrogenApiKey` in a prior audit; the consequence there (route bypass) was more severe, but the structural pattern — a security-sensitive config value that falls back silently — is the same and warrants a loud failure at boot in production. The existing `feedback_ip_hash_recent_idx` becomes a zero-row index if this fires.
    - **Plain English:** The app is supposed to record a scrambled, privacy-safe fingerprint of each submitter's IP address for abuse detection. The scrambling requires a secret key. If that key is missing from the server's configuration, the app quietly stops recording fingerprints — no alarm, no error. You'd only discover it after an abuse incident when you check the records and find them blank. The fix is an alarm that triggers immediately when the server starts without the key configured.
    - **Evidence:**
        ```php
        private function hashIp(?string $ip): ?string
        {
            if ($ip === null || $ip === '') {
                return null;
            }

            $pepper = (string) config('partna.feedback.ip_hash_pepper', '');
            if ($pepper === '') {
                return null;
            }

            return hash('sha256', $ip.'|'.$pepper);
        }
        ```

- [ ] **#LIFE-1** · P2 — Read-then-write race in duplicate-feedback window; non-atomic `SELECT`-then-`INSERT`
    - **Where:** app/Services/Feedback/FeedbackService.php — `submit()` method, duplicate check block
    - **Affects:** Users who double-tap the submit button or hit a stuck-client retry loop. Two simultaneous requests from the same user can both pass the `exists()` check and insert duplicate rows.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a partial unique index to the migration (or a new migration): `CREATE UNIQUE INDEX feedback_user_message_window_uniq ON core.feedback (user_id, md5(message)) WHERE deleted_at IS NULL;` — or a simpler idempotency key column derived from the message hash.
        - Catch `Illuminate\Database\UniqueConstraintViolationException` on the `Feedback::create()` call and throw `DuplicateFeedbackException`, replacing the pure-SELECT guard with a write-path constraint.
    - **Technical:** The code correctly self-documents the gap: `// NOT atomic — two genuinely simultaneous requests can both pass this check and both insert.` The named throttle (`feedback-submit`) is rate-limiting but not serializing — two requests arriving within the same millisecond on different workers both pass the `exists()` call before either has written. The canonical fix is a DB UNIQUE constraint on the write path: `INSERT ... ON CONFLICT DO NOTHING` / catching `UniqueConstraintViolationException`. A `lockForUpdate()` is not sufficient here because there is no existing row to lock on — the correct serialisation point is a UNIQUE constraint that the INSERT itself enforces. `md5(message)` in the index avoids storing the full message content in the index while still providing the uniqueness guarantee within the active (non-deleted) window.
    - **Plain English:** Two forms submitted at the exact same moment both check "has this message been sent before?" — they both see "no" and both save a duplicate. The fix is a database rule that says "only one record with this exact message can exist per user at a time" — if both requests try to save simultaneously, the database itself rejects the second one, the same way a barcode scanner rejects a duplicate ticket even if two scanners are running in parallel.
    - **Evidence:**
        ```php
        // Best-effort duplicate suppression for double-tap / stuck-client floods.
        // NOT atomic — two genuinely simultaneous requests can both pass this
        // check and both insert. The named throttle (`feedback-submit`) is the
        // real abuse backstop; this saves one extra row in the common case.
        $duplicate = Feedback::query()
            ->where('user_id', $actor->id)
            ->where('message', $message)
            ->where('created_at', '>=', now()->subSeconds(
                (int) config('partna.feedback.duplicate_window_seconds', 60)
            ))
            ->exists();

        if ($duplicate) {
            throw new DuplicateFeedbackException;
        }

        $feedback = Feedback::create([...]);
        ```

---

## P3 — Nice to have

- [ ] **#SCHEMA-2** · P3 — Missing composite index for the primary user-facing query (`WHERE user_id = ? ORDER BY created_at DESC`)
    - **Where:** supabase/migrations/20260526210001_create_feedback_table.sql — index creation block
    - **Affects:** The `GET /me/feedback` paginated endpoint — PostgreSQL must sort all matching rows after filtering, rather than using an index scan that delivers them pre-sorted.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a composite index: `CREATE INDEX feedback_user_id_created_idx ON core.feedback (user_id, created_at DESC) WHERE deleted_at IS NULL;`
        - Drop the now-redundant `feedback_user_id_idx` on `(user_id)` alone, since the composite index covers its use case.
    - **Technical:** The controller runs `Feedback::query()->where('user_id', $pro->id)->orderByDesc('created_at')->paginate($perPage)`. With `SoftDeletes`, the effective query is `WHERE user_id = ? AND deleted_at IS NULL ORDER BY created_at DESC LIMIT ?`. The existing `feedback_user_id_idx` covers the filter but not the sort — PostgreSQL uses it to find matching rows then performs a separate sort pass before applying `LIMIT`. A composite index on `(user_id, created_at DESC) WHERE deleted_at IS NULL` serves both filter and sort in a single index scan. At pre-beta scale this is negligible, but the schema should match the query shape before data accumulates.
    - **Plain English:** The current filing system groups feedback by user (fast to find) but keeps each user's records in random order, so fetching the most recent page requires shuffling them into date order first. A better-organised system would keep each user's records pre-sorted by date, so you just grab the top few and hand them over without any extra work.
    - **Evidence:**
        ```sql
        -- Existing index covers filter but not sort:
        CREATE INDEX feedback_user_id_idx
            ON core.feedback (user_id)
            WHERE deleted_at IS NULL;
        ```
        ```php
        // Controller forces a sort step after the index scan:
        $paginator = Feedback::query()
            ->where('user_id', $pro->id)
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->appends($request->query());
        ```

- [ ] **#SEC-2** · P3 — Dead `$feedback->trashed()` check in `FeedbackController::show` after default route-model binding
    - **Where:** app/Http/Controllers/Api/Professional/Feedback/FeedbackController.php:54–57
    - **Affects:** Code clarity — the branch is unreachable and misleads reviewers into thinking soft-deleted rows can be fetched via this endpoint.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the `if ($feedback->trashed()) { abort(404); }` block from `show()`.
        - If soft-deleted feedback ever needs to be staff-viewable, add `->withTrashed()` explicitly on the route binding and document it.
    - **Technical:** Confirmed via `routes/api/professional.php`: the `show` route is `Route::get('/me/feedback/{feedback}', ...)->whereUuid('feedback')` with no `->withTrashed()`. Laravel's implicit route-model binding for a `SoftDeletes` model adds `->whereNull('deleted_at')` to the binding query, so a trashed `Feedback` row results in a 404 from the binding itself — `$feedback->trashed()` can never return `true` in `show()`. The dead branch is not harmful but signals a missing `withTrashed()` call to future readers and may cause `->withTrashed()` to be added later without removing the now-live dead check, producing silent double-404 logic.
    - **Plain English:** There's a safety check that says "if this record is deleted, return a 404." But the record-fetching step already skips deleted records before the controller runs. The check can never fire — it's a smoke detector in a room that can't catch fire. Removing it makes the code tell the truth about what it does.
    - **Evidence:**
        ```php
        // Route (routes/api/professional.php:224-225) — no withTrashed():
        Route::get('/me/feedback/{feedback}', [FeedbackController::class, 'show'])
            ->whereUuid('feedback');

        // Controller — trashed() check is unreachable:
        public function show(Request $request, Feedback $feedback)
        {
            $pro = $this->currentProfessional($request);
            $this->authorizeForUser($pro, 'view', $feedback);

            if ($feedback->trashed()) {
                abort(404);
            }

            return $this->success(['feedback' => new FeedbackResource($feedback)]);
        }
        ```

- [ ] **#LIFE-5** · P3 — `foreach` `Mail::send` loop exits on first recipient SMTP failure; retry re-sends to already-delivered recipients
    - **Where:** app/Jobs/Notifications/SendFeedbackEmailJob.php — `handle()` foreach over `$recipients`
    - **Affects:** Internal team notification list (typically 5–10 addresses). If one address produces an SMTP hard-bounce or timeout, later addresses don't receive the notification on that attempt; on retry, earlier addresses receive a duplicate.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap each `Mail::to($recipient)->send()` call in its own `try/catch(\Throwable)` so one failure doesn't abort the loop; log per-recipient failures with `'recipient' => $recipient`.
        - Combined with the LIFE-3 idempotency fix, the retry-duplication concern is also resolved: the dedup marker prevents re-entry to already-delivered recipients even if the loop is re-entered.
    - **Technical:** The `foreach` treats all recipients as a single unit of work. One `Mail::send()` exception unwinds the entire `handle()`, and the queue retry re-executes the full loop from the beginning. At a small fixed recipient list (5–10), the blast radius is contained, but the failure mode is internally observable (some team members miss the notification, others get duplicates). The fix is independent per-recipient `try/catch` blocks — each address is its own delivery unit. The LIFE-3 dedup fix subsumes the duplication concern for full re-deliveries, but this fix also handles the partial-delivery case where no dedup marker has been set yet.
    - **Plain English:** If sending to the third address on the team list fails, addresses four through ten don't get the email that attempt. When the job retries, the first three get the email a second time. Wrapping each delivery in its own error-handler means one bad address is skipped and logged without affecting everyone else, like a courier skipping one undeliverable address and continuing down the street rather than returning the whole bag to the depot.
    - **Evidence:**
        ```php
        foreach ($recipients as $recipient) {
            $recipient = trim((string) $recipient);
            if ($recipient === '') {
                continue;
            }
            // No try/catch — any Mail::send() exception aborts the entire foreach
            Mail::to($recipient)->send(new FeedbackSubmittedMail($feedback, $userEmail));
        }
        ```

- [ ] **#SCHEMA-1** · P3 — `FORCE ROW LEVEL SECURITY` missing on `core.feedback`; table owner bypasses RLS on direct connections
    - **Where:** supabase/migrations/20260526210001_create_feedback_table.sql — RLS enablement block
    - **Affects:** Defence-in-depth for tenant isolation. The table owner (`postgres`) bypasses RLS on direct connections — admin scripts, migration jobs, and ad-hoc SQL editor queries run with owner privileges skip the tenant-isolation policy silently.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `ALTER TABLE core.feedback FORCE ROW LEVEL SECURITY;` immediately after the existing `ENABLE ROW LEVEL SECURITY` statement.
        - Note: this is a systemic gap — only `core.partna_staff` has `FORCE` among 30+ tables in the baseline. A follow-up migration applying `FORCE` to all tenant-data tables would harden the full schema consistently.
    - **Technical:** `ALTER TABLE ... ENABLE ROW LEVEL SECURITY` activates RLS for non-owner roles but the table owner (here `postgres`, set by `ALTER TABLE core.feedback OWNER TO postgres`) still bypasses it unless `FORCE ROW LEVEL SECURITY` is also applied. Confirmed via tool check: the baseline migration applies `FORCE` only to `core.partna_staff` and nowhere else. For the app's normal path (`app_backend` role), this gap is irrelevant — `app_backend` is not the table owner and already goes through RLS. The risk is an accidental admin query or a future migration script running as `postgres` that reads or mutates cross-user feedback rows without the RLS policies evaluating. Adding `FORCE` closes this path with one line.
    - **Plain English:** The access control rules apply to everyone who uses the normal app login, but the database's built-in administrator account is exempt by default. Any admin script or manual SQL command that runs with full database privileges ignores all the "you can only see your own feedback" rules. The fix locks that backdoor so even admin-level commands have to follow the same rules. This same gap exists across most tables in the codebase — the feedback table just happens to be the one under review.
    - **Evidence:**
        ```sql
        ALTER TABLE core.feedback ENABLE ROW LEVEL SECURITY;
        -- Missing: ALTER TABLE core.feedback FORCE ROW LEVEL SECURITY;

        CREATE POLICY feedback_all_authenticated ON core.feedback TO authenticated
            USING (
                (EXISTS (
                    SELECT 1 FROM core.users p
                    WHERE p.id = feedback.user_id
                      AND p.auth_user_id = auth.uid()
                      AND p.deleted_at IS NULL
                ))
                OR (EXISTS (
                    SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid()
                ))
            )
        ...
        ```

`★ Insight ─────────────────────────────────────`
The feedback system has strong structural bones — `afterCommit()` dispatch, service-layer separation, no raw Eloquent in responses, FormRequest validation, Policy coverage — but the P2 cluster (LIFE-1 through LIFE-3) shows a common pattern: **the service was written correctly for the happy path but the at-least-once delivery contract of Redis queues wasn't systematically designed against.** The three P2 lifecycle findings (race condition, no idempotency, missing context) would all be caught by a "what happens if this runs twice?" review pass, which is a useful habit to build into PR review for any job dispatch.
`─────────────────────────────────────────────────`
