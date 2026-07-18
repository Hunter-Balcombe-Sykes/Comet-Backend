# Lifecycle Correctness Audit — 2026-07-08

**Branch:** audit-fix/middleware-2026-07-06
**Lens:** Lifecycle correctness: race-safety, idempotency, anchor decoupling, reconcile loops, vendor resilience, observability discipline
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Services/User/AccountDeletionService.php
- app/Services/User/UserBootstrapService.php
- app/Services/User/ConfirmationPreferenceService.php
- app/Services/User/SectionVisibilityService.php
- app/Jobs/Gdpr/ExportUserDataJob.php
- app/Services/User/DataExport/DataExportService.php
- app/Services/Moderation/ContentReportService.php
- app/Services/Moderation/ModerationCaseService.php
- app/Services/Moderation/ModerationDecisionService.php
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php
- app/Jobs/Notifications/DispatchEnquiryNotificationsJob.php
- app/Services/Platforms/EventsCatalog.php
- app/Services/Platforms/GoogleBusinessAutoSync.php
- app/Services/Platforms/IdentitySync.php
- app/Services/Platforms/GoogleBusinessApifyScraper.php
- app/Services/Platforms/InstagramScraper.php
- app/Jobs/Platforms/GoogleBusinessEnrichJob.php
- app/Jobs/Platforms/InstagramConnectJob.php
- app/Jobs/Platforms/MenuFetchJob.php
- routes/console.php
- supabase/migrations/20260528000000_create_moderation_schema.sql
- supabase/migrations/20260528000001_create_moderation_indexes.sql

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 4 complete
- P2 Medium: 0 of 9 complete
- P3 Low: 0 of 2 complete

---

## P1 — Fix before pilot launch

- [ ] **#LIFE-1** · P1 — Race in `openOrMergeCase` can create duplicate moderation cases for the same target
    - **Where:** app/Services/Moderation/ContentReportService.php:122-149
    - **Affects:** Moderation case queue integrity; a burst of reports on the same site/handle (e.g. a coordinated report pile-on) can split signals across two open cases instead of merging them.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a partial `UNIQUE` index on `moderation.cases (reportable_type, reportable_id)` for rows where `status IN ('open','triaged','under_review')`.
        - Catch `UniqueConstraintViolationException` on the `forceCreate()` and re-fetch the winning row under `lockForUpdate()` instead of assuming the insert always succeeds.
    - **Technical:** `lockForUpdate()` in `openOrMergeCase` only locks rows that already exist — it does nothing to prevent two concurrent transactions from both seeing zero matching rows and both `forceCreate()`-ing a new case. Confirmed against `supabase/migrations/20260528000000_create_moderation_schema.sql` and the sibling indexes migration: `moderation.cases` has only a plain (non-unique) index on `(reportable_type, reportable_id)`, no partial `UNIQUE` constraint. This is the canonical `lockForUpdate + UNIQUE` gap — the lock alone is not sufficient without a constraint backing the check.
    - **Plain English:** Two people can report the same page at nearly the same instant, and the system opens two separate investigation cases instead of merging into one. It's like a call center creating two tickets for the same call — staff waste time working the same issue twice, and evidence gets split across two records instead of one.
    - **Evidence:**
        ```php
        private function openOrMergeCase(string $type, string $id, string $ownerId): ModerationCase
        {
            $existing = ModerationCase::query()
                ->where('reportable_type', $type)
                ->where('reportable_id', $id)
                ->whereIn('status', ['open', 'triaged', 'under_review'])
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            return ModerationCase::forceCreate([
                'id' => (string) Str::uuid(),
                'case_type' => 'content_report',
                'reportable_type' => $type,
                'reportable_id' => $id,
                ...
            ]);
        }
        ```

- [ ] **#LIFE-2** · P1 — `ModerationCaseService::triage` and `release` skip the row lock that `take` already uses
    - **Where:** app/Services/Moderation/ModerationCaseService.php:27-50, 87-99
    - **Affects:** Staff-facing case actions — a staffer triaging a case while another claims it (`take`) can produce a torn status (e.g. `triaged` silently overwriting an already-claimed `under_review`), leading to duplicate enforcement actions on the same case.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Mirror `take()`'s pattern in both `triage()` and `release()`: `$case = ModerationCase::lockForUpdate()->findOrFail($case->id);` as the first line inside the transaction, before calling `$this->sm->transition(...)`.
    - **Technical:** `take()` already does `ModerationCase::lockForUpdate()->findOrFail($case->id)` before transitioning — this is the established pattern in the same file. `triage()` and `release()` call `$this->sm->transition($case, ...)` directly on the passed-in (unlocked, possibly stale) model. Two concurrent staff actions on the same case can both pass the state machine's transition check against stale in-memory state and both `save()`, with the second write silently clobbering the first's status/fields. Since this is the exact same root-cause pattern as LIFE-1 (missing lock on a moderation write path) it carries the same tier.
    - **Plain English:** If two staff members act on the same case within a split second — one triaging it, another claiming it — the system can end up recording the wrong final state, as if the claim never happened. Locking the row (already done for one of the three actions) makes the second action wait its turn and see the correct, up-to-date state.
    - **Evidence:**
        ```php
        // triage — no lockForUpdate, unlike take()
        return DB::transaction(function () use ($case, $staff, $dto) {
            $this->sm->transition($case, 'triaged');
            $case->triaged_by_staff_id = $staff->id;
            $case->triaged_at = now();
            ...
            $case->save();
        });
        ```
        ```php
        // take() — the pattern the other two methods should mirror
        $case = ModerationCase::lockForUpdate()->findOrFail($case->id);
        ```

- [ ] **#LIFE-3** · P1 — Concurrent deletion-confirmation requests can double-fire the scheduled-deletion mail and duplicate audit entries
    - **Where:** app/Services/User/AccountDeletionService.php:120-157, 171-257
    - **Affects:** Users whose confirmation link is opened twice (double-click, or an email client's link-prefetch) — they receive two "your account will be deleted" emails and the audit trail records two `CONFIRMED` events for one deletion.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `executeConfirmation()`, re-fetch and lock the professional row (`User::query()->lockForUpdate()->find($professional->id)`) as the first statement inside the transaction, and re-check `deletion_token_hash !== null` after acquiring the lock — no-op if it's already been cleared by a concurrent request.
    - **Technical:** `confirm()` validates the token (existence, expiry, `hash_equals`) entirely outside any lock, then calls `executeConfirmation()`, whose transaction updates the row (including clearing `deletion_token_hash`) without ever re-reading it under `lockForUpdate()`. Two concurrent requests can both pass the token check before either transaction commits, so both proceed to write the `pending_deletion` status, both write an audit row, and both queue `AccountDeletionScheduledMail` (queued outside the transaction, so there is no de-dup barrier there either).
    - **Plain English:** This is like a "confirm account deletion" button that can be clicked twice before the first click finishes registering. Both clicks look valid because the first hasn't been recorded yet, so the user gets two confirmation emails and the compliance log shows the event happening twice. A lock makes the second click wait, see the confirmation already happened, and stop there.
    - **Evidence:**
        ```php
        // Token check — outside any transaction or row lock
        if (! hash_equals((string) $professional->deletion_token_hash, hash('sha256', $rawToken))) {
            return ['success' => false, 'code' => 404, 'error' => 'Invalid token.'];
        }
        $deletesAt = $this->executeConfirmation(
            $professional,
            UserDeletionAuditEntry::EVENT_CONFIRMED,
            $request,
        );
        ```
        ```php
        // executeConfirmation's transaction — writes the row, but never re-reads it
        // under lockForUpdate() before doing so.
        DB::connection('pgsql')->transaction(function () use ($professional, ...) {
            $professional->update([
                'deletion_previous_status' => $previousStatus,
                'status' => 'pending_deletion',
                'deletion_confirmed_at' => now(),
                'deletion_token_hash' => null,
            ]);
            ...
        });
        ```

- [ ] **#LIFE-4** · P1 — GDPR erasure steps in `AccountDeletionService::purge()` swallow failures with `Log::error`, never surfacing to Nightwatch
    - **Where:** app/Services/User/AccountDeletionService.php:626-661 (purgeExportZips), 668-684 (purgeWaitlistSignup), 692-705 (purgeFeedbackRows), 717-735 (purgeCaseSignalPii), 754-788 (purgeReportedUserEvidencePii), 797-814 (purgeGlobalEmailSubscriptions), 833-852 (purgeCrossTenantSubscriptions)
    - **Affects:** GDPR data subjects whose PII in secondary tables (export ZIPs, waitlist signups, feedback, moderation case-signal PII, evidence-payload PII, global/cross-tenant email subscriptions) survives account hard-deletion because a DB hiccup or storage outage during one of these best-effort erasure steps is never surfaced to on-call.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the `Log::error('... failed', [...])` calls in each `purge*` catch body with `report($e)` (matching the pattern already used elsewhere in the same file — `deleteSupabaseAuthUser` failure and `forceDelete` failure both correctly call `report()`).
        - Leave `Log::warning` in the inner per-item loops (e.g. individual export ZIP path deletion, `purgeMediaArtifacts`'s inner R2 cleanup) as breadcrumbs — those already have a catch-all sweep (`deleteDirectory`) or their own daily reconcile command, so an alert per-item would be noisy.
    - **Technical:** Nightwatch alerts fire on exceptions and auto-detected slow jobs/routes — not on log queries. Every one of these seven `purge*` helpers catches `\Throwable`, logs at `error` level, and returns — no exception ever reaches Nightwatch. This is inconsistent with the rest of the same method: `purge()`'s own `deleteSupabaseAuthUser` failure and `forceDelete` failure both call `report()` in addition to logging. The `purge*` helpers are the erasure steps that satisfy the actual GDPR obligation (Article 17) and their silent failure is exactly the "have to catch it ourselves later" gap the doctrine's Log-with-context pattern exists to prevent.
    - **Plain English:** Imagine a shredding service that silently dumps sensitive documents into a "failed" bin without telling anyone. If the shredder jams, nobody knows — the documents just sit there. When the cleanup steps for erasing a deleted user's personal data hit an error, they write a note in a log nobody actively monitors instead of raising an alarm. The data survives and the team never finds out unless someone thinks to go looking.
    - **Evidence:**
        ```php
        // purgeExportZips — outer catch
        } catch (\Throwable $e) {
            Log::error('Export ZIP erasure step failed during account purge', [
                'user_id' => $professional->id,
                'error' => $e->getMessage(),
            ]);
        }

        // purgeCaseSignalPii
        } catch (\Throwable $e) {
            Log::error('Case signal PII erasure failed during account purge', [
                'user_id' => $professional->id,
                'error' => $e->getMessage(),
            ]);
        }
        ```
        (Same pattern repeats verbatim in purgeWaitlistSignup, purgeFeedbackRows, purgeReportedUserEvidencePii, purgeGlobalEmailSubscriptions, purgeCrossTenantSubscriptions.)

## P2 — Should fix

- [ ] **#LIFE-5** · P2 — Race in `EventsCatalog` account/event cap enforcement
    - **Where:** app/Services/Platforms/EventsCatalog.php:219-259
    - **Affects:** Users adding Eventbrite/Humanitix organisers or standalone events in rapid succession — the per-platform caps (`MAX_ACCOUNTS=5`, `MAX_EVENTS=10`) can be transiently exceeded.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Serialize the count-and-write with an advisory lock keyed on the user (`pg_try_advisory_xact_lock`), or issue the count under `lockForUpdate()` against a stable parent row (the user's site).
    - **Technical:** `storeAccount`/`storeStandalone` both run a plain `count()` query, compare against the constant, and only afterwards call `writeRow()` (an `updateOrCreate`). The table's `(user_id, platform, resource_id)` uniqueness prevents duplicate rows for the same resource but does nothing to cap cardinality — two near-simultaneous adds (e.g. rapid double-submit) can both read a count under the limit and both write, exceeding it. Impact is a soft, self-imposed limit on the user's own data, not a security or cross-tenant issue.
    - **Plain English:** Two clicks in quick succession both check "how many organisers do I have?" and both see room for one more, so both go through — the user briefly ends up with six organisers when five was the limit. It's cosmetic, not dangerous, but the count check and the write should happen as one atomic step.
    - **Evidence:**
        ```php
        if ($existing === null && $this->accountRows($user, $platform)->count() >= self::MAX_ACCOUNTS) {
            return $this->fail('You can connect up to '.self::MAX_ACCOUNTS.' organisers per platform.', 422);
        }
        // ... later calls $this->writeRow($user, $platform, $rid, $payload);
        ```
        ```php
        if (! $exists && $this->eventRows($user, $platform)->count() >= self::MAX_EVENTS) {
            return $this->fail('You can add up to '.self::MAX_EVENTS.' individual events per platform.', 422);
        }
        ```

- [ ] **#LIFE-6** · P2 — Race in `GoogleBusinessAutoSync` presence checks can seed duplicate reservation/booking/ordering rows
    - **Where:** app/Services/Platforms/GoogleBusinessAutoSync.php:118-143 (seedReservation/hasAnyReservation), 216-242 (seedBooking), 340-431 (seedOrdering)
    - **Affects:** Business Partna accounts. `GoogleBusinessEnrichJob` is keyed `ShouldBeUnique` on `userId:placeId` (documented as intentionally allowing "reconnecting a DIFFERENT place" to run concurrently with an in-flight enrichment) — so two enrichment runs for the same user against two different Google Place IDs can genuinely overlap, both see "no existing reservation/booking" and both write, seeding two conflicting integrations.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap the per-slot check-then-write in `seedReservation`/`seedBooking`/`seedOrdering` in a transaction guarded by `lockForUpdate()` on the site row, or an advisory lock keyed on the user ID, so two overlapping enrichment runs for the same user serialize on the slot check.
    - **Technical:** `hasAnyReservation()`, the `BOOKING_PLATFORMS` contains-check, and `hasStoreKey()` are all plain `SELECT`s issued before the corresponding `write()` (an `updateOrCreate`). This is not a purely theoretical race — the job's own `uniqueId()` docstring confirms a second enrichment for a different place_id is expected to run concurrently with an in-flight one for the same user, which is exactly the scenario that can double-seed a reservation/booking slot the first run already claimed.
    - **Plain English:** Two automatic-import runs for the same business (triggered by connecting two different Google listings) can both check "does this business already have a reservation link?" at nearly the same moment, both see "no," and both add one — leaving two reservation platforms connected when only one was intended. It's confusing on the dashboard but self-correctable through the existing "Change to" flow.
    - **Evidence:**
        ```php
        if ($this->hasAnyReservation($userId)) {
            return [$this->conflictFinding($write['platform'], $write['resourceId'], 'reservations', ...)];
        }
        $this->write($userId, $write['platform'], $write['resourceId'], $write['payload']);
        ```

- [ ] **#LIFE-7** · P2 — `IdentitySync::applyFromGooglePayload` performs a read-modify-write on the workplace row without a lock
    - **Where:** app/Services/Platforms/IdentitySync.php:45-108
    - **Affects:** Business Partna users; this runs from `IntegrationConnectionObserver` on every google-business connection save, so an overlapping initial-connect save and a manual refresh save can race on the same workplace row.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Workplace::firstOrNew(['site_id' => (string) $site->id])` with a locked read (`Workplace::query()->where('site_id', $site->id)->lockForUpdate()->first() ?? new Workplace([...])`) inside a transaction wrapping the read-merge-save sequence.
    - **Technical:** The method reads the current workplace row (or an unsaved skeleton), computes per-field merges based on the account-type precedence rule, then saves. Two overlapping observer fires for the same connection (e.g. the synchronous Place-Details write on connect overlapping with a later payload update) can both read the same starting state and the second `save()` silently drops fields the first call intended to set — self-correcting on the next sync, but a real gap given the docblock explicitly calls this "best-effort... must never break the connect/refresh it rides on."
    - **Plain English:** Two updates to a business's profile information (address, phone, category) can happen back-to-back so closely that the second one overwrites the first's changes without knowing about them. The business's listing briefly shows stale info until the next Google sync corrects it.
    - **Evidence:**
        ```php
        $workplace = Workplace::firstOrNew(['site_id' => (string) $site->id]);
        $sources = is_array($workplace->field_sources) ? $workplace->field_sources : [];
        ...
        if ($changed) {
            $workplace->field_sources = $sources;
            $workplace->save();
        }
        ```

- [ ] **#LIFE-8** · P2 — `MenuFetchJob` discards per-platform scrape failure detail when every connected platform fails
    - **Where:** app/Jobs/Platforms/MenuFetchJob.php:182-188
    - **Affects:** Users whose menu can't be scraped (blocked actor, store URL changed, transient timeout) — the record shows `fetch_status = 'unavailable'` with no discriminating reason, so triaging a "menu vanished" support case requires digging through logs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Track a short reason per platform during `fetchStores()` (timeout / bot-blocked / empty result) and thread it into `MenuPlatformLink.status` (currently a bare 'ok'/'unavailable' enum) or a new nullable text column, rather than collapsing every failure mode to the same string.
    - **Technical:** `MenuPlatformLink` already records per-platform `status` ('ok'/'unavailable') at lines 175-180, so some granularity exists, but no failure *reason* is captured anywhere — a WAF block, an empty scrape, and a timeout are all indistinguishable. Note the existing `menu:retry-unavailable` scheduled command (`routes/console.php`) already self-heals transient failures every 15 minutes, which softens the operational urgency here — this finding is about debuggability, not about the failure going unretried.
    - **Plain English:** When a restaurant's menu can't be pulled in, the dashboard just says "menu unavailable." It doesn't say whether the delivery platform was down, blocked the request, or returned nothing — so there's no way to tell a one-off glitch from a broken integration without checking the server logs.
    - **Evidence:**
        ```php
        if (array_filter($menus) === []) {
            $menu->forceFill(['fetch_status' => 'unavailable', 'last_fetched_at' => $now])->save();

            return;
        }
        ```

- [ ] **#LIFE-9** · P2 — `GoogleBusinessEnrichJob` drops the verbatim Apify failure reason before it reaches the connection record
    - **Where:** app/Jobs/Platforms/GoogleBusinessEnrichJob.php:88-104, 164-173
    - **Affects:** Support/debugging when the Google Business enrichment scrape fails — the connection record shows `apify_status = 'unavailable'` with no error text, even though `GoogleBusinessApifyScraper::fetch()` already logs a specific, discriminating reason (budget exhausted / threw / non-2xx / bad items) via `Log::warning`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change `GoogleBusinessApifyScraper::fetch()` to return a structured failure reason alongside `null` (e.g. a small value object or `['error' => string]`) instead of collapsing every failure mode to `null`.
        - Thread that reason into `mark($connection, 'unavailable', $reason)` and store it in `last_refresh_error` on the connection row.
    - **Technical:** `fetch()` already distinguishes four failure modes internally (`budget_exhausted`, an HTTP exception, a non-2xx response, malformed dataset items) and logs each with a distinct message via `Log::warning`/`report()` — but all four collapse to a bare `null` return. The job's `handle()` then calls `$this->mark($connection, 'unavailable')`, which only ever writes `payload.apifyFetchedAt` and `apify_status` — never `last_refresh_error`. The information needed to satisfy the verbatim-vendor-error-capture doctrine already exists in the scraper; it's dropped at the `null` boundary.
    - **Plain English:** When a delivery fails, the tracking page should say why — wrong address, package too heavy, driver couldn't find the door. Right now the system already knows the specific reason internally (it's in the server logs) but throws that detail away before writing anything to the record a support agent or the dashboard can see, leaving only a generic "unavailable."
    - **Evidence:**
        ```php
        $enrichment = $scraper->fetch($this->placeId, $this->userId);

        if ($enrichment === null) {
            // Soft failure: keep the Place Details payload, just mark the Apify
            // layer 'unavailable' so the dashboard stops polling. No hard fail —
            // the core card is unaffected and a re-connect can retry.
            $this->mark($connection, 'unavailable');

            return;
        }
        ```
        ```php
        private function mark(IntegrationConnection $connection, string $status): void
        {
            $connection->forceFill([
                'payload' => [
                    ...$this->payloadOf($connection),
                    'apifyFetchedAt' => now()->toIso8601String(),
                ],
                'apify_status' => $status,
            ])->saveQuietly();
        }
        ```

- [ ] **#LIFE-10** · P2 — `InstagramConnectJob::failed()` writes a hardcoded `'job_failed'` instead of the real exception message
    - **Where:** app/Jobs/Platforms/InstagramConnectJob.php:134-144, 240-254
    - **Affects:** Users whose Instagram auto-connect fails — the connection row's `last_refresh_error` always reads `'job_failed'` regardless of the actual cause (Apify returned no profile, budget exhausted, etc.), even though the real message is captured in the `Log::error` call right next to it.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `failed(Throwable $e)`, pass `$e->getMessage()` to `markFailed()` instead of the literal string `'job_failed'`.
    - **Technical:** `handle()` throws a specific, informative `RuntimeException` message ("Instagram scrape returned no profile for @{username} (user {userId})") via `$this->fail(...)`. `failed()` correctly logs `$e->getMessage()` to `Log::error`, but then calls `$this->markFailed($connection, 'job_failed')` with a hardcoded literal instead of `$e->getMessage()` — the specific reason that was just logged never reaches the DB row the dashboard reads.
    - **Plain English:** Your home internet router shows a red light labeled just "error" — it doesn't say whether the cable's unplugged, the modem's rebooting, or the provider's down, even though that information exists somewhere in a technician's log. The fix is to show the actual reason on the light, not a generic "error."
    - **Evidence:**
        ```php
        public function failed(Throwable $e): void
        {
            report($e);
            Log::error('instagram.connect_job.failed', [
                'user_id' => $this->userId,
                'username' => $this->username,
                'connection_id' => $this->connectionId,
                'error' => $e->getMessage(),
            ]);

            $connection = IntegrationConnection::find($this->connectionId);
            if ($connection) {
                $this->markFailed($connection, 'job_failed');
            }
        }
        ```

- [ ] **#LIFE-11** · P2 — Enquiry notification idempotency relies solely on a cache key with no durable fallback
    - **Where:** app/Jobs/Notifications/DispatchEnquiryNotificationsJob.php:62-69
    - **Affects:** Professionals receiving duplicate enquiry notifications if the Redis cache is flushed/evicted between the job's `Cache::has` check and any later retry — e.g. a deploy-time `cache:clear` or a Redis maintenance event.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a durable `notified_at` timestamp column on `site.enquiries` (or a unique-constrained notification-receipt row) set atomically with the dispatch, and check that instead of (or in addition to) the Redis flag.
    - **Technical:** The job is already `ShouldBeUnique` for 300s at the queue level, which handles same-window duplicate dispatches, but the application-level dedup guard (`Cache::has('enquiry:notified:'.$id)` / `Cache::put(..., now()->addDay())`) has no durable backstop — a cache flush at any point in the 24h window removes the only record that this enquiry was already notified, and a subsequent re-dispatch (e.g. a retriggered observer) would re-send.
    - **Plain English:** To avoid sending the same enquiry alert twice, the job leaves itself a sticky note in a scratchpad that gets erased periodically. If that scratchpad is wiped clean at the wrong moment, the job won't remember it already sent the alert and could send a duplicate. Writing that note on the permanent record instead of the scratchpad would make it durable.
    - **Evidence:**
        ```php
        // Idempotency guard — a retry after partial success must not re-send the notification.
        if (Cache::has('enquiry:notified:'.$this->enquiryId)) {
            return;
        }

        $dispatcher->dispatch($enquiry, $block);

        Cache::put('enquiry:notified:'.$this->enquiryId, true, now()->addDay());
        ```

- [ ] **#LIFE-12** · P2 — `UserBootstrapService::bootstrap()` loads the existing-user row before the transaction, allowing a last-write-wins race on concurrent updates
    - **Where:** app/Services/User/UserBootstrapService.php:36-120
    - **Affects:** Users who submit the same bootstrap/profile-update request from two tabs or a double-submit — the second write can silently overwrite fields the first write just set.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move `$existing = User::query()->where('auth_user_id', $uid)->first();` inside the `DB::connection('pgsql')->transaction()` closure and add `->lockForUpdate()`, so the second concurrent call blocks until the first commits and then operates on the committed row.
    - **Technical:** `$existing` is read before the transaction opens, so two concurrent requests for the same `uid` both operate on the same stale snapshot; inside their respective transactions, both `fill()` + `save()` without a row lock, so Postgres allows both UPDATEs to proceed and the later one wins. Note this is distinct from the brand-new-user path, which is protected by the partial `UNIQUE INDEX users_auth_user_id_unique ON core.users (auth_user_id) WHERE (deleted_at IS NULL)` in the baseline migration — a genuine double-create for a new user fails loudly on that constraint rather than silently duplicating. The gap is specifically the existing-user update branch, which has no such backstop.
    - **Plain English:** Two browser tabs updating the same profile at the same time both read the current version, both make their own edits, and both save — whichever saves last wins, silently discarding the other tab's changes. A lock would make the second tab wait for the first to finish and work from the up-to-date version.
    - **Evidence:**
        ```php
        // $existing loaded BEFORE the transaction — stale snapshot for concurrent requests
        $existing = User::query()->where('auth_user_id', $uid)->first();
        ...
        $result = DB::connection('pgsql')->transaction(function () use ($uid, $data, $existing) {
            $professional = $existing;
            ...
            } else {
                $professional->fill([
                    'handle' => $data['handle'],
                    'display_name' => $data['display_name'],
                    'primary_email' => $data['primary_email'],
                    ...
                ]);
            }
            $professional->save();
            ...
        });
        ```

- [ ] **#LIFE-13** · P2 — Apify actors invoked without version pinning across Instagram, Google Business, and menu scrapers
    - **Where:** app/Services/Platforms/InstagramScraper.php:17, app/Services/Platforms/GoogleBusinessApifyScraper.php:28, app/Services/Platforms/MenuApifyScraper.php (actor id resolved via `config('partna.menu.platforms.<platform>.actor')`)
    - **Affects:** Every platform integration that depends on an Apify actor — an unpinned publisher upgrade to any of these actors can silently change the output shape or field names, breaking the corresponding `map()`/`mapItems()` logic without any deploy on our side.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Pin each actor to a specific build tag (e.g. `apify~instagram-profile-scraper@<build>`) rather than the bare `owner~name` form, which always runs the actor's latest published build.
        - Document the process for bumping the pinned build after verifying a new one against real data.
    - **Technical:** `POST /acts/{actor}/run-sync-get-dataset-items` without a build tag always resolves to the most recently published build. `InstagramScraper::ACTOR` and `GoogleBusinessApifyScraper::ACTOR` are both hardcoded `owner~name` constants with no tag, and `MenuApifyScraper::actorFor()` resolves the same unpinned form from config. A publisher-side schema change (new/renamed output field) would silently degrade or break scraping for all three integrations simultaneously with no code change on our end to bisect against.
    - **Plain English:** It's like ordering "the chef's special" every week — if the chef changes the recipe without telling you, the dish you depend on suddenly tastes different and you have no way to know why. Asking for a specific, versioned dish means you always get exactly what you tested, and any change is a deliberate choice, not a surprise.
    - **Evidence:**
        ```php
        // InstagramScraper.php
        private const ACTOR = 'apify~instagram-profile-scraper';
        ```
        ```php
        // GoogleBusinessApifyScraper.php
        private const ACTOR = 'compass~crawler-google-places';
        ```

## P3 — Nice to have

- [ ] **#LIFE-14** · P3 — `ConfirmationPreferenceService::updateForProfessional` races on concurrent preference updates
    - **Where:** app/Services/User/ConfirmationPreferenceService.php:51-73
    - **Affects:** Users toggling "skip confirmation" preferences from two tabs simultaneously — the later `updateOrCreate` silently overwrites the earlier one.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - If ever revisited, guard with a conditional `UPDATE ... WHERE` or a locked pre-read; low priority given the trivial blast radius (a single boolean preference, self-correcting on the next toggle).
    - **Technical:** `updateOrCreate` is a SELECT-then-write; two concurrent calls for the same `(user_id, action_key)` can both read stale state and the second write wins. Impact is a single boolean UI preference with no security or data-integrity consequence.
    - **Plain English:** Two browser tabs both trying to save your "don't ask again" preference at the same time — whichever finishes last silently wins. For a simple checkbox, this isn't harmful, but it's the same underlying pattern that causes bigger problems elsewhere in the system.
    - **Evidence:**
        ```php
        DB::transaction(function () use ($userId, $normalizedUpdates): void {
            foreach ($normalizedUpdates as $actionKey => $skipConfirmation) {
                UserConfirmationPreference::query()->updateOrCreate(
                    ['user_id' => $userId, 'action_key' => $actionKey],
                    ['skip_confirmation' => $skipConfirmation]
                );
            }
        });
        ```

- [ ] **#LIFE-15** · P3 — `SectionVisibilityService::reevaluateEnabled` has a self-healing read-modify-write race on `is_enabled`
    - **Where:** app/Services/User/SectionVisibilityService.php:100-136
    - **Affects:** Section block `is_enabled` state — a rare concurrent observer double-fire can leave a section's visibility flag transiently wrong until the next re-evaluation corrects it.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - If ever revisited, load the block with `lockForUpdate()` at the top of the method; low priority given the self-correcting nature of every subsequent write.
    - **Technical:** The method reads `$block->is_enabled`, computes a new value from freshly-built context, and conditionally writes it back with no lock. A concurrent resource deletion racing a concurrent creation could theoretically leave a stale value in place, but the very next observer fire re-evaluates and corrects it — the window is transient by construction.
    - **Plain English:** Think of two motion sensors controlling the same light, one saying "occupied" and the other checking a split second later and saying "empty." The light can flicker briefly, but it corrects itself the next time someone walks by.
    - **Evidence:**
        ```php
        if ((bool) $block->is_enabled !== $canBeEnabled) {
            $block->is_enabled = $canBeEnabled;
            $block->save();
        }
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Moderation case-lifecycle locking:** #LIFE-1, #LIFE-2
    - **Why grouped:** Same file family (`app/Services/Moderation/`), same root cause (missing `lockForUpdate`/`UNIQUE` on moderation case writes), naturally reviewed and tested together.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Google Business connector error capture:** #LIFE-9, #LIFE-10
    - **Why grouped:** Identical root cause (vendor error swallowed before reaching the DB record) across two sibling connector jobs; same small, mechanical fix shape.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 3 — Platform-connector concurrency hardening:** #LIFE-5, #LIFE-6, #LIFE-7, #LIFE-12
    - **Why grouped:** Same pattern (unlocked count/existence check before a write) across `app/Services/Platforms/` and `UserBootstrapService`; reviewable as one "add lockForUpdate to read-modify-write" sweep.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 4 — Low-priority self-healing races:** #LIFE-14, #LIFE-15
    - **Why grouped:** Both P3, both trivial preference/state races that self-correct; safe to batch as a single small session.
    - **Model:** Combine plan+implement (Sonnet) given XS/S effort.

## Standalone — do NOT bundle

- **#LIFE-3 — Account-deletion confirm double-fire race:** touches the GDPR deletion state machine; run alone with its own plan + sign-off.
- **#LIFE-4 — GDPR erasure silent-failure logging:** directly affects GDPR Article 17 compliance evidence; standalone per the money/DB/compliance-sensitivity rule.
- **#LIFE-8 — MenuFetchJob per-platform error detail:** touches the `site.menus`/`site.menu_platform_links` write path; keep isolated from the connector-error-capture bundle to avoid conflating a schema-adjacent change with pure logic fixes.
- **#LIFE-11 — Enquiry notification durable dedup:** likely requires a `supabase/migrations/` schema change (new column on `site.enquiries`) — DB migrations are always standalone.
- **#LIFE-13 — Apify actor version pinning:** cross-cutting change touching three separate integrations (Instagram, Google Business, Menu) simultaneously; run alone so a bad pin doesn't get masked inside a larger bundle.
