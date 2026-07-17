# Lifecycle Correctness Audit — 2026-07-11

**Branch:** HEAD
**Lens:** Lifecycle correctness: race-safety, idempotency, anchor decoupling, reconcile loops, vendor resilience, observability discipline
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Services/EarlyAccess/EarlyAccessService.php
- app/Services/User/UserBootstrapService.php
- app/Services/Site/UpdateSiteAction.php
- app/Services/User/AccountDeletionService.php
- app/Services/Site/ContentSelectionService.php
- app/Http/Middleware/Context/LoadCurrentUser.php
- app/Jobs/Notifications/DispatchEnquiryNotificationsJob.php
- app/Services/Notifications/Dispatchers/PlatformHealthNotifier.php
- app/Services/Notifications/NotificationPublisher.php
- app/Jobs/Platforms/GoogleBusinessEnrichJob.php
- app/Jobs/Platforms/InstagramConnectJob.php
- app/Services/Platforms/GoogleBusinessAutoSync.php
- app/Services/Platforms/ShopCatalog.php
- app/Services/Platforms/Strategies/Fetch/GoogleBusinessFetch.php
- app/Services/Platforms/Strategies/Refresh/ScheduledRefresh.php
- app/Services/Platforms/PlatformRefresher.php
- app/Http/Controllers/Api/Platforms/GenericPlatformController.php
- app/Services/Platforms/Strategies/Connect/{Spotify,Bandcamp,Pinterest,Strava,Twitch,Vimeo,Youtube,YoutubeMusic}Connect.php
- app/Services/Platforms/Strategies/Highlights/{Bandcamp,Vimeo,Youtube,YoutubeMusic}Highlights.php
- app/Services/Platforms/WooCommerceScraper.php
- app/Services/Platforms/YoutubeScraper.php
- supabase/migrations/20260711000300_early_access_signups.sql

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 3 complete
- P2 Medium: 0 of 25 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **LIFE-1** · P1 — Waitlist signup races on read-then-create; the DB's own UNIQUE constraint 500s instead of degrading gracefully
    - **Where:** app/Services/EarlyAccess/EarlyAccessService.php:32-49
    - **Affects:** Public marketing waitlist form — any double-click, form re-submit, or client retry for the same email crashes the request instead of returning the existing signup.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Catch `Illuminate\Database\UniqueConstraintViolationException` around the `create()` call and re-fetch/return the existing row on conflict.
        - Alternatively use `firstOrCreate` scoped on `email_lc`, or an `insertOrIgnore` + follow-up select, matching the pattern `NotificationPublisher::publish()` already uses for its dedupe key.
    - **Technical:** Category 1 — `UniqueConstraintViolationException`. `supabase/migrations/20260711000300_early_access_signups.sql` correctly backs `email_lc` with `CONSTRAINT early_access_signups_email_lc_unique UNIQUE (email_lc)` — the DB-level guard exists — but `signupFromMarketing()` does an uncaught `where('email_lc', ...)->first()` then `create([...])` with no lock and no `catch`. Two requests that both observe `null` both attempt the INSERT; the loser's `UniqueConstraintViolationException` is never caught and propagates as an unhandled 500. This is a public, unauthenticated form — double-submits are a routine UX pattern (slow network, impatient double-click), so this isn't a rare edge case but a "known scenario" that will recur as signup volume grows.
    - **Plain English:** Someone fills out the waitlist form and, because the page is slow to respond, clicks "submit" twice. The system checks "does this email already exist?", sees no both times, and tries to save two entries. The database itself refuses the second save — but nobody told the code to expect that refusal, so instead of quietly saying "you're already on the list," the site shows the visitor a broken error page.
    - **Evidence:**
        ```php
        $existing = EarlyAccessSignup::query()->where('email_lc', $emailLc)->first();

        if ($existing === null) {
            $signup = EarlyAccessSignup::query()->create([
                'email' => $emailLc,
                'email_lc' => $emailLc,
                'type' => $data['type'],
                ...
        ```

- [ ] **LIFE-2** · P1 — Enquiry-notification idempotency guard is set AFTER the side-effect, not before — a job retry can double-send
    - **Where:** app/Jobs/Notifications/DispatchEnquiryNotificationsJob.php:64-77
    - **Affects:** Site owners receiving enquiry notifications — a retry after a mid-flight crash (worker OOM-kill, deploy restart) re-sends the enquiry email/in-app notification.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the `Cache::has()` / `Cache::put()` pair with a single atomic `Cache::add('enquiry:notified:'.$this->enquiryId, true, now()->addDay())` called BEFORE `$dispatcher->dispatch(...)`. `add()` returns `false` if the key already exists — if so, return early without dispatching.
    - **Technical:** Category 1 — the job's own idempotency guard is a check-then-act race: `Cache::has()` is read, `$dispatcher->dispatch($enquiry, $block)` fires the side-effect, and only afterward does `Cache::put()` record that it happened. `ShouldBeUnique` (`uniqueFor=300`) only prevents two copies of the *same job attempt* from running concurrently — it does not survive a crash between `dispatch()` and `Cache::put()`. Laravel releases the unique lock when the job attempt completes (success or exception), so `$tries=3` with `$backoff=[30,90,180]` will retry after exactly this kind of mid-flight failure, re-check `Cache::has()` (still false), and re-send. `Cache::add()` is a single atomic SETNX and closes the window regardless of where the crash lands — this is the same "guard before side-effect" principle `NotificationPublisher::publish()` already applies correctly via its `insertOrIgnore` on `(user_id, dedupe_key)`.
    - **Plain English:** After someone submits an enquiry form, the system sends the site owner an email. To avoid sending it twice, it writes a "sent" note to its scratchpad — but only AFTER the email goes out. If the worker crashes in that gap and the job retries, it checks the scratchpad, sees no note, and sends a second email. Writing the note first (and skipping the send if the note's already there) closes that gap completely.
    - **Evidence:**
        ```php
        // Idempotency guard — a retry after partial success must not re-send the notification.
        if (Cache::has('enquiry:notified:'.$this->enquiryId)) {
            return;
        }

        $dispatcher->dispatch($enquiry, $block);
        ...
        Cache::put('enquiry:notified:'.$this->enquiryId, true, now()->addDay());
        ```

- [ ] **LIFE-3** · P1 — Site-settings PATCH merges the JSONB blob outside any lock — two concurrent saves silently drop one set of changes
    - **Where:** app/Services/Site/UpdateSiteAction.php:51-102, 121-141
    - **Affects:** Every authenticated user editing their site settings — two concurrent dashboard saves (multi-tab editing, a slow save race against a second field change) can lose one write entirely, with no error surfaced to either request.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Re-read `$site` with `lockForUpdate()` inside the transaction, re-derive `$existing`/`$merged` from that locked read (not the pre-transaction snapshot), then `fill()`/`save()` — this narrows the window doctrine calls for without moving the whole PATCH-merge computation back into the lock.
        - Alternatively, adopt a conditional `UPDATE ... WHERE updated_at = ?` compare-and-swap and surface a 409 to the losing request so the client can refresh and retry instead of silently losing data.
    - **Technical:** Category 2 — race-safe read-modify-write. The settings JSONB merge (`array_replace_recursive($existing, $incoming)`) reads `$site->settings` from a model instance loaded before the transaction opens (`$professional->loadMissing('site')` at the top of `execute()`), then — well outside any lock — computes `$data['settings']` in pure PHP. Only afterward does the method open `DB::connection('pgsql')->transaction(...)` and call `$site->fill($data); $site->save();` with no `lockForUpdate()` on the row. Two concurrent PATCHes (e.g. toggling a section live in one tab while updating a booking URL in another) both read the same starting `settings`, each merges its own change, and whichever transaction commits last silently overwrites the other's write — there is no conflict signal to either client. The `UniqueConstraintViolationException` catch on `$site->save()` only guards the subdomain-rename path, not this merge. This is the platform's single highest-traffic authenticated write path (every dashboard save), so the "two tabs" scenario is a documented, expected usage pattern, not a rare edge case.
    - **Plain English:** A professional has their dashboard open in two browser tabs — maybe one on their phone and one on their laptop. In one they turn on their photo gallery; in the other they update their booking link. Both tabs read the current settings, add their own change, and save. Whichever save reaches the database second completely overwrites the first — there's no merge, no warning, nothing. The gallery toggle just silently reverts, and the professional has no idea why. The fix makes the database hold a lock while reading-and-writing so the second save can't blindly stomp on the first.
    - **Evidence:**
        ```php
        // Hoist pure-PHP work out of the transaction to keep the lock window narrow.
        if (array_key_exists('settings', $data)) {
            $existing = is_array($site->settings) ? $site->settings : [];
            $incoming = is_array($data['settings']) ? $data['settings'] : [];
            ...
            $merged = array_replace_recursive($existing, $incoming);
            ...
            $data['settings'] = $merged;
        }
        ...
        return DB::connection('pgsql')->transaction(function () use ($professional, $site, $data, $options): Site {
            ...
            $site->fill($data);
            try {
                $site->save();
            } catch (UniqueConstraintViolationException $e) {
                // Final safety net for the unique index on subdomain
                ...
        ```

## P2 — Should fix

- [ ] **LIFE-4** · P2 — Early-access invite mint/save races without a row lock — a fast re-invite can dead-link the first email
    - **Where:** app/Services/EarlyAccess/EarlyAccessService.php:69-91
    - **Affects:** Staff-initiated invite flow — two staff members (or one staff member double-clicking) inviting the same waitlist row concurrently mint two tokens; only the last one persists.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Re-query the row with `lockForUpdate()` inside a transaction, re-check `status !== STATUS_SIGNED_UP` under that lock, then mint/save.
    - **Technical:** Category 2 — race-safe read-modify-write. `invite()` reads `$signup->status` off a pre-loaded instance, then unconditionally mints `Str::random(48)` and saves — no lock, no re-check against the DB's current row. Two concurrent invokes both pass the `signed_up` guard and both write; the second `invite_token_hash` wins, silently invalidating the first email's link before it's even opened. Staff-only, low-concurrency operation, so the practical blast radius is small, but the fix is a single `lockForUpdate()`.
    - **Plain English:** Two support staff both click "invite" on the same waitlist entry within a second of each other. Both generate a fresh sign-up link and save it — but only one link actually works, because the second save overwrites the first. Whoever's email arrives first in the recipient's inbox will click a dead link. Locking the row while reading-and-writing means only one of the two invites can proceed at a time.
    - **Evidence:**
        ```php
        public function invite(EarlyAccessSignup $signup, ?string $invitedBy = null): ?string
        {
            if ($signup->status === EarlyAccessSignup::STATUS_SIGNED_UP) {
                return null;
            }
            $token = Str::random(48);
            $signup->fill([
                'status' => EarlyAccessSignup::STATUS_INVITED,
                'invited_at' => now(),
                'invite_token_hash' => hash('sha256', $token),
            ]);
            $signup->invited_by = $invitedBy;
            $signup->save();
        ```

- [ ] **LIFE-5** · P2 — Deletion-confirm reads token/status off a pre-loaded model with no lock — a double-click writes a wrong audit snapshot and duplicate email
    - **Where:** app/Services/User/AccountDeletionService.php:120-157, 171-227
    - **Affects:** Self-service deletion confirmation — two near-simultaneous clicks on the same confirmation link both pass validation and both run `executeConfirmation`, producing a duplicate `EVENT_CONFIRMED` audit row (with an incorrect `deletion_previous_status` on the second) and a duplicate "deletion scheduled" email.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a `WHERE status != 'pending_deletion'` guard (or a `lockForUpdate()` re-read) at the top of `executeConfirmation`'s transaction so the second concurrent call is a no-op once the first has flipped status.
    - **Technical:** Category 2 — race-safe read-modify-write. `confirm()` validates the token against `$professional->deletion_token_hash`/`deletion_requested_at` on an instance loaded once before either concurrent request began; `executeConfirmation()` never re-queries or locks the row before writing `deletion_previous_status` / `status`. Both requests pass validation, both enter the transaction, both write — the second captures a stale `$previousStatus` ('active', not the 'pending_deletion' the first request just set) and both queue `AccountDeletionScheduledMail`. The account still ends up correctly `pending_deletion` (idempotent end state), but the audit trail gets a spurious duplicate `EVENT_CONFIRMED` row with wrong metadata, and the user receives the scheduled-deletion email twice. Narrow window (same token, millisecond overlap), so downgraded from the draft's P1 — this is audit-trail/UX noise, not data loss or a security bypass.
    - **Plain English:** Someone clicks the "confirm account deletion" link in their email, and — because the page is slow — clicks it again. Both clicks are valid, so both get processed. The account still ends up correctly scheduled for deletion, but the permanent record of *why* now has a duplicate, slightly wrong entry, and the person gets the "your account will be deleted" email twice instead of once.
    - **Evidence:**
        ```php
        public function confirm(User $professional, string $rawToken, Request $request): array
        {
            if (! $professional->deletion_token_hash || ! $professional->deletion_requested_at) {
                return ['success' => false, 'code' => 404, 'error' => 'No deletion request found.'];
            }
            ...
            $deletesAt = $this->executeConfirmation(
                $professional,
                UserDeletionAuditEntry::EVENT_CONFIRMED,
                $request,
            );
        ```

- [ ] **LIFE-6** · P2 — Instagram-auto flag commits outside the content-selection transaction — a mid-operation DB failure leaves the flag on with no reserved slots
    - **Where:** app/Services/Site/ContentSelectionService.php:222-284
    - **Affects:** Dashboard "auto-fill from Instagram" toggle — if `persist()` throws after `$site->save()` already committed, the site advertises auto-fill as enabled with no ig-reel/ig-post rows behind it.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap `$site->save()` and the `persist()` call in one `DB::connection('pgsql')->transaction()` so the flag and the content-selection rows commit or roll back together.
    - **Technical:** This doesn't map cleanly onto a single named canonical pattern but is the same "two co-dependent writes must be one atomic unit" discipline `AccountDeletionService::executeConfirmation()` and `restoreSiteAndStatus()` apply correctly elsewhere in this codebase. `setInstagramAuto()` calls `$site->save()` first (commits immediately), then computes and calls `persist()`, which wraps its own delete+insert in a separate transaction. If `persist()` throws (DB blip, connection drop), the flag is durably `true` with zero ig-* rows behind it — a self-inconsistent state that only self-heals on a subsequent manual toggle. Low-frequency trigger (a DB failure mid-request), so P2 not P1.
    - **Plain English:** When a professional turns on "auto-fill with my latest Instagram post," the system flips a switch and then tries to reserve two content slots for that Instagram content. If reserving the slots fails partway through (a brief database hiccup), the switch is already stuck in the "on" position even though no Instagram content was actually reserved — a confusing half-done state that won't fix itself until the user manually toggles it off and back on.
    - **Evidence:**
        ```php
        public function setInstagramAuto(Site $site, bool $enabled): void
        {
            $site->content_instagram_auto_enabled = $enabled;
            $site->save();

            $existing = ContentSelection::query()
                ->where('site_id', $site->id)
                ->orderBy('position')
                ->get();
        ```

- [ ] **LIFE-7** · P2 — Cache invalidation sits inside a catch clause scoped to a different exception type — a Redis hiccup 500s an otherwise-successful email sync
    - **Where:** app/Http/Middleware/Context/LoadCurrentUser.php:93-137
    - **Affects:** Every authenticated request where the JWT's verified email differs from the stored `primary_email` (rare per-user, but hits every affected request during any Redis blip on that path).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move `$this->userCache->invalidateUser($professional)` to its own `try { ... } catch (\Throwable $e) { report($e); }` block after the `save()`/`catch (UniqueConstraintViolationException)` block, so a cache failure never propagates past a successful DB write.
    - **Technical:** Category 8 — cache operations must degrade gracefully, never become a single point of failure. `syncEmailFromClaims()` wraps `$professional->save()` AND `$this->userCache->invalidateUser($professional)` in one `try` block that only `catch`es `UniqueConstraintViolationException`. If `invalidateUser()` throws anything else (Redis connection refused/timeout), it propagates uncaught out of middleware `handle()`, turning an already-successful DB write into a 500 for the whole request. The code's own comment argues the DB race here is "not worth" a conditional UPDATE — but the cache-invalidation exception-safety gap is a separate, unrelated issue the comment doesn't address.
    - **Plain English:** When someone logs in with a newly-verified email, the system updates their stored email and then clears the old cached copy so it doesn't linger. The "clear the cache" step lives inside a safety net that's only built to catch one specific kind of error (a duplicate-email conflict). If the cache server itself is briefly down, that different kind of failure isn't caught — it crashes the whole request, even though the important part (saving the new email) already succeeded.
    - **Evidence:**
        ```php
        try {
            $professional->primary_email = $claimedEmail;
            $professional->save();
            $this->userCache->invalidateUser($professional);
        } catch (UniqueConstraintViolationException $e) {
            Log::warning('LoadCurrentUser email sync collision', [
        ```

- [ ] **LIFE-8** · P2 — `markSignedUp` swallows every failure as a bare `Log::warning` — a persistent write failure is invisible to Nightwatch
    - **Where:** app/Services/EarlyAccess/EarlyAccessService.php:97-114
    - **Affects:** Early-access bookkeeping — if the UPDATE fails on every invocation (bad migration state, permission error), no waitlist row ever flips to `signed_up`, and nothing alerts anyone.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report($e)` alongside the existing `Log::warning`, so a systemic failure surfaces to Nightwatch while the signup flow itself still proceeds unaffected (the catch-and-continue behavior is correct and should stay).
    - **Technical:** Category 10 — `Log-with-context`. The comment correctly identifies this as "bookkeeping only" that must never fail the signup — that part of the design is right. But `catch (\Throwable $e) { Log::warning(...); }` with no `report($e)` means Nightwatch (which alerts on exceptions, not log queries) never sees a systemic failure of this update. A discriminating log key (`early_access.mark_signed_up_failed`) already exists; it just needs to also reach the alerting path.
    - **Plain English:** After someone finishes signing up, the system quietly tries to mark their waitlist entry as "done." If that step keeps failing — say, because of a database misconfiguration — nobody finds out, because the failure is only written to a log file nobody watches. Sending it to the monitoring system too means the team gets paged instead of discovering the problem weeks later by accident.
    - **Evidence:**
        ```php
        } catch (\Throwable $e) {
            // Bookkeeping only — a failed status flip must never fail the signup
            // itself. (Also covers SQLite test mirrors without the table.)
            Log::warning('early_access.mark_signed_up_failed', ['error' => $e->getMessage()]);
        }
        ```

- [ ] **LIFE-9** · P2 — Platform-health critical-notification dedup key is permanent — a user who reconnects and later fails again is never warned twice
    - **Where:** app/Services/Notifications/Dispatchers/PlatformHealthNotifier.php:26-49
    - **Affects:** Users whose platform connection trips the failure breaker, reconnects it, and later has it fail again — the second failure episode produces no notification at all.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Scope the dedupe key to a failure episode, e.g. `"platform_connection_failed:{$connection->id}:{$connection->updated_at->timestamp}"` captured at the moment the breaker trips, or explicitly delete the prior critical notification row when `consecutive_failures` resets to 0 on a successful refresh.
    - **Technical:** Category 3/9 — periodic-notification dedup shape. `NotificationPublisher::publish()` correctly dedupes via `insertOrIgnore` on `(user_id, dedupe_key)` (atomic, race-free — no finding there). The gap is upstream: `connectionRefreshFailing()` uses a dedupe key scoped to the connection's entire lifetime (`"platform_connection_failed:{$connection->id}"`), and the notification is `critical: true` → `ends_at = null` (no auto-expiry, no prune — confirmed in `NotificationPublisher::publish()`'s `ends_at` branch). Once that row exists, `insertOrIgnore` permanently blocks any future notification for that connection, even after the user reconnects and `PlatformRefresher::recordNotModified()`/a successful refresh resets `consecutive_failures` to 0. Contrast `menuScrapeFailed()`, which sets `retentionConfigKey: 'content_scrape'` so the row auto-expires and the dedupe naturally clears — that's the correct reference pattern already in the same file. Confirmed against the recently-shipped notification infra (`48d5f9fb feat(notifications): automatic dispatchers + critical email path + expiry prune (OV-H)`).
    - **Plain English:** Think of a smoke detector that can only ever go off once. A connection to a platform breaks, the user gets warned, they fix it — but if it breaks again six months later, the detector stays silent because it already "used up" its one alert for that connection. The permanent alert flag needs to reset whenever the problem is actually fixed, not stay tripped forever.
    - **Evidence:**
        ```php
        $this->safePublish(
            userId: (string) $connection->user_id,
            frontendType: 'Warning',
            category: 'platform_connection',
            title: "Reconnect your {$label}",
            body: "We couldn't refresh your {$label} connection after several attempts...",
            dedupeKey: "platform_connection_failed:{$connection->id}",
            ctaUrl: '/account/integrations',
            critical: true,
            retentionConfigKey: null,
        );
        ```

- [ ] **LIFE-10** · P2 — GoogleBusinessEnrichJob writes `payload` without a lock; a same-window scheduled refresh can lose the enrichment
    - **Where:** app/Jobs/Platforms/GoogleBusinessEnrichJob.php:90-167 (vs. app/Services/Platforms/Strategies/Refresh/ScheduledRefresh.php:22-40)
    - **Affects:** Google Business connections — a connect-time Apify enrichment racing a due scheduled refresh for the same connection can have either write clobber the other's `payload`.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap the final `forceFill(['payload' => ...])->saveQuietly()` in a `lockForUpdate()`-guarded re-read-and-merge, or compare-and-swap on `apifyFetchedAt`/`updated_at` so a stale writer's update is rejected rather than silently applied.
    - **Technical:** Category 2 — race-safe read-modify-write. `GoogleBusinessEnrichJob::handle()` reads the connection's `payload` early, does multi-second external work (website harvest, optional Apify run), then writes back `forceFill(['payload' => [...], 'apify_status' => 'ok'])->saveQuietly()` with no lock. `PlatformRefresher::refresh()` → `ScheduledRefresh::run()` → `GoogleBusinessFetch::fetch()` is a completely separate write path to the same `payload` column, also with no lock, triggered by the daily cron's `dueForRefresh` scope. `GoogleBusinessEnrichJob`'s `uniqueFor=900` only dedupes against *itself* (same job class, same `userId:placeId`), not against `PlatformRefresher`. The window is narrow — `GoogleBusinessFetch` has its own 40-hour freshness short-circuit and the cron cadence is daily — so a fresh connect colliding with a due scheduled refresh for the same connection is uncommon, not a routine occurrence; downgraded from the draft's P1 accordingly.
    - **Plain English:** Two different background processes can both update a Google Business connection's saved details at almost the same time — one right after the user connects it, one from the routine daily refresh. Neither checks whether the other is mid-update, so whichever finishes last wins, and the other's work quietly vanishes. It's an unlikely timing coincidence today, but worth closing before it becomes a real support ticket.
    - **Evidence:**
        ```php
        $connection->forceFill([
            'payload' => [
                ...$businessInfo,
                'apifyFetchedAt' => now()->toIso8601String(),
                'syncFindings' => $findings,
            ],
            'apify_status' => 'ok',
        ])->saveQuietly();
        ```

- [ ] **LIFE-11** · P2 — InstagramConnectJob writes `payload`/status without a lock; a same-window scheduled refresh can lose the scrape result
    - **Where:** app/Jobs/Platforms/InstagramConnectJob.php:123-238
    - **Affects:** Instagram connections — same root cause as LIFE-10, applied to the Instagram connect pipeline.
    - **Effort:** M (~2–4h)
    - **What to do:** Same remediation as LIFE-10 — lock-guarded read-modify-write or a version/timestamp compare-and-swap on the connection row.
    - **Technical:** Category 2 — race-safe read-modify-write. `InstagramConnectJob::handle()` reads via `IntegrationConnection::find($this->connectionId)`, does the full Apify scrape + media mirror (up to 150s), then `$connection->update(['payload' => $selection, ...])` with no lock. Any scheduled refresh strategy for the `instagram` platform writing through the same model in that window would race identically to LIFE-10. `uniqueFor=900` is keyed on `connectionId:username` and only dedupes duplicate connect attempts, not a concurrent refresh. Same low-but-nonzero likelihood as LIFE-10 — P2, not P1.
    - **Plain English:** Same issue as the Google Business one above, but for Instagram: the "just connected" scrape and a routine background refresh can, in rare timing, both try to save the same connection's data, and one silently overwrites the other.
    - **Evidence:**
        ```php
        $connection->update([
            'payload' => $selection,
            'is_active' => true,
            'last_refreshed_at' => now(),
            'last_refresh_status' => 'ok',
            'last_refresh_error' => null,
            'consecutive_failures' => 0,
        ]);
        ```

- [ ] **LIFE-12** · P2 — `seedOrdering`'s in-memory slot counter isn't re-checked against the DB — two concurrent Google Business enrichments can exceed the ordering cap
    - **Where:** app/Services/Platforms/GoogleBusinessAutoSync.php:340-431
    - **Affects:** A user who connects two different Google Business places in quick succession — the two resulting enrichment jobs can each seed ordering rows without seeing each other's writes, exceeding `MAX_ORDERING` (10) by a small margin or double-adding the same store.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Re-check `IntegrationConnection::where(...)->count() >= MAX_ORDERING` (or `lockForUpdate()` the user's ordering rows) immediately before each `write()` call inside the loop, rather than trusting the in-memory `$existingCount`/`$existingStoreKeys` snapshot taken once at the top.
    - **Technical:** Category 2 — race-safe read-modify-write. `$existingOrdering`/`$existingCount`/`$existingStoreKeys` are all snapshotted once via `->get()` before the loop; both the cap check and the per-store dedup check operate against that stale in-memory copy while `write()` (an `updateOrCreate`) commits directly to the DB. `GoogleBusinessEnrichJob`'s `uniqueFor` is scoped per `placeId`, so two different places connected close together for the same user can run this method concurrently. Impact is soft-cap overshoot / a possible duplicate store row, not data corruption — genuinely low-probability and low-severity, consistent with P2.
    - **Plain English:** When Google Business finds online-ordering links, the system counts how many ordering entries the user already has before adding more, so it never adds more than 10. But it counts once at the very start and doesn't re-check as it goes — so if two separate Google Business connections are being processed for the same person at almost the same moment, the count each one relies on can be slightly out of date, letting them add one or two more than intended.
    - **Evidence:**
        ```php
        $existingOrdering = IntegrationConnection::query()
            ->where('user_id', $userId)
            ->where('platform', Platform::OnlineOrdering->value)
            ->get();
        $existingCount = $existingOrdering->count();
        ...
        foreach ($stores as $storeKey => $group) {
            if ($existingCount >= self::MAX_ORDERING) {
                break;
            }
        ```

- [ ] **LIFE-13** · P2 — SpotifyConnect fetches the vendor oEmbed endpoint synchronously in the request cycle
    - **Where:** app/Services/Platforms/Strategies/Connect/SpotifyConnect.php:17-40 (invoked from app/Http/Controllers/Api/Platforms/GenericPlatformController.php:63)
    - **Affects:** Users connecting a Spotify link — Spotify oEmbed latency/downtime directly extends or fails the `POST /api/platforms/{platform}/connect` response.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Mirror the pattern already established for Google Business / Instagram (`GoogleBusinessEnrichJob`, `InstagramConnectJob`): persist a minimal pending row synchronously, dispatch a queued job for the oEmbed fetch, and have the dashboard poll for completion.
    - **Technical:** Category 6 — vendor-integration hygiene: synchronous vendor calls in the request cycle. `GenericPlatformController::connect()` calls `$strategy->resolve(...)` directly and synchronously (confirmed — no queue dispatch anywhere in that method); `SpotifyConnect::resolve()` performs a live `Http` call to `open.spotify.com/oembed` inline. Any latency or outage on Spotify's side is felt directly by the user as request latency/failure. This is a one-time, user-initiated action (not on the public sitepage hot path), so the blast radius is limited to the connecting user's own request — hardening, not a today-hurts-a-real-user issue, hence P2 rather than P1.
    - **Plain English:** When someone pastes a Spotify link into their dashboard, the site calls out to Spotify's servers live and makes the person wait for the reply before showing anything. If Spotify is slow or down, the user just sees a spinner or an error, even though nothing is actually wrong on our end.
    - **Evidence:**
        ```php
        $resolved = $this->oembed->resolve('https://open.spotify.com/oembed?url='.rawurlencode($link));
        if ($resolved === null) {
            return ConnectResult::fail('Could not load that Spotify link.');
        }
        ```

- [ ] **LIFE-14** · P2 — BandcampConnect fetches the artist page + price enrichment synchronously in the request cycle
    - **Where:** app/Services/Platforms/Strategies/Connect/BandcampConnect.php:23-48
    - **Affects:** Users connecting a Bandcamp page — same request-latency exposure as LIFE-13.
    - **Effort:** M (~2–4h)
    - **What to do:** Same remediation direction as LIFE-13 — async job + poll.
    - **Technical:** Category 6, same shape as LIFE-13. `resolve()` calls `$this->scraper->fetchProfile($origin)` (full page scrape) then `enrichPrices(...)` (a second fetch), both synchronously inside `GenericPlatformController::connect()`.
    - **Plain English:** Same issue as the Spotify one, for Bandcamp: connecting a page makes the user wait on a live fetch of the artist's whole page plus a price lookup.
    - **Evidence:**
        ```php
        $profile = $this->scraper->fetchProfile($origin);
        if ($profile === null || $profile['items'] === []) {
            return ConnectResult::fail('Could not find releases on that Bandcamp page.', 404);
        }
        $latest = $this->scraper->enrichPrices([$profile['items'][0]])[0];
        ```

- [ ] **LIFE-15** · P2 — PinterestConnect makes two sequential synchronous vendor fetches in the request cycle
    - **Where:** app/Services/Platforms/Strategies/Connect/PinterestConnect.php:16-38
    - **Affects:** Users connecting a Pinterest profile — two sequential external fetches (state JSON + RSS) both block the response.
    - **Effort:** M (~2–4h)
    - **What to do:** Same remediation direction as LIFE-13.
    - **Technical:** Category 6, same shape as LIFE-13. `resolve()` calls `fetchProfile()` then `fetchPins()`, sequentially, synchronously.
    - **Plain English:** Connecting a Pinterest profile makes the user wait through two separate live lookups to Pinterest, one after the other, before anything shows up.
    - **Evidence:**
        ```php
        $profile = $this->scraper->fetchProfile($username);
        if ($profile === null) {
            return ConnectResult::fail('Could not find that Pinterest profile.', 404);
        }
        $pins = $this->scraper->fetchPins($username);
        ```

- [ ] **LIFE-16** · P2 — StravaConnect fetches the club page synchronously in the request cycle
    - **Where:** app/Services/Platforms/Strategies/Connect/StravaConnect.php:16-30
    - **Affects:** Users connecting a Strava club — same exposure as LIFE-13.
    - **Effort:** M (~2–4h)
    - **What to do:** Same remediation direction as LIFE-13.
    - **Technical:** Category 6, same shape.
    - **Plain English:** Same pattern as the others: pasting a Strava club link makes the user wait on a live page fetch.
    - **Evidence:**
        ```php
        $club = $this->scraper->fetchClub($url);
        if ($club === null) {
            return ConnectResult::fail('Could not read that Strava club page.', 404);
        }
        ```

- [ ] **LIFE-17** · P2 — TwitchConnect fetches the channel page synchronously in the request cycle
    - **Where:** app/Services/Platforms/Strategies/Connect/TwitchConnect.php:15-35
    - **Affects:** Users connecting a Twitch channel — same exposure as LIFE-13.
    - **Effort:** M (~2–4h)
    - **What to do:** Same remediation direction as LIFE-13.
    - **Technical:** Category 6, same shape.
    - **Plain English:** Same pattern: connecting a Twitch channel blocks on a live page fetch.
    - **Evidence:**
        ```php
        $channel = $this->scraper->fetchChannel($login);
        if ($channel === null) {
            return ConnectResult::fail('Could not find that Twitch channel.', 404);
        }
        ```

- [ ] **LIFE-18** · P2 — VimeoConnect makes two sequential synchronous vendor API calls in the request cycle
    - **Where:** app/Services/Platforms/Strategies/Connect/VimeoConnect.php:18-40
    - **Affects:** Users connecting a Vimeo profile — two sequential keyless-API calls block the response.
    - **Effort:** M (~2–4h)
    - **What to do:** Same remediation direction as LIFE-13.
    - **Technical:** Category 6, same shape. `resolve()` calls `fetchProfile()` then `fetchVideos()` sequentially.
    - **Plain English:** Connecting Vimeo makes the user wait through two separate live API calls before the profile shows up.
    - **Evidence:**
        ```php
        $profile = $this->vimeo->fetchProfile($source['apiPath']);
        $videos = $this->vimeo->fetchVideos($source['apiPath']);
        if ($profile === null && $videos === []) {
            return ConnectResult::fail('Could not find that Vimeo profile.', 404);
        }
        ```

- [ ] **LIFE-19** · P2 — YoutubeConnect fetches the channel's recent videos synchronously in the request cycle
    - **Where:** app/Services/Platforms/Strategies/Connect/YoutubeConnect.php:21-42
    - **Affects:** Users connecting a YouTube channel — same exposure as LIFE-13.
    - **Effort:** M (~2–4h)
    - **What to do:** Same remediation direction as LIFE-13.
    - **Technical:** Category 6, same shape. `fetchRecentVideos()` itself does two synchronous fetches internally (channel-id resolution + RSS feed — see `YoutubeScraper`).
    - **Plain English:** Same pattern: connecting a YouTube channel blocks on live fetches to YouTube.
    - **Evidence:**
        ```php
        $videos = $this->scraper->fetchRecentVideos($handle);
        if (empty($videos)) {
            return ConnectResult::fail('Could not find that YouTube channel or its latest video.', 404);
        }
        ```

- [ ] **LIFE-20** · P2 — YoutubeMusicConnect fetches channel-id resolution + uploads feed synchronously in the request cycle
    - **Where:** app/Services/Platforms/Strategies/Connect/YoutubeMusicConnect.php:20-31
    - **Affects:** Users connecting a YouTube Music artist — two sequential external fetches block the response.
    - **Effort:** M (~2–4h)
    - **What to do:** Same remediation direction as LIFE-13.
    - **Technical:** Category 6, same shape.
    - **Plain English:** Same pattern for YouTube Music: two live round-trips before the connect request completes.
    - **Evidence:**
        ```php
        $channelId = $this->scraper->channelIdFrom($input);
        if (! $channelId) {
            return ConnectResult::fail();
        }
        $feed = $this->scraper->fetchUploadsFeed($channelId, self::MAX_ITEMS);
        ```

- [ ] **LIFE-21** · P2 — BandcampHighlights fetches the artist page synchronously from the picker endpoint
    - **Where:** app/Services/Platforms/Strategies/Highlights/BandcampHighlights.php:28-36 (invoked from `GenericPlatformController::recent()`)
    - **Affects:** Users opening the "choose highlights" picker for an already-connected Bandcamp page — the modal blocks on a live fetch every time it opens.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Serve from the periodically-refreshed cached catalog (mirroring `ShopCatalog`'s cached-catalog fallback pattern) rather than a live fetch on every picker open, or queue+poll as in LIFE-13.
    - **Technical:** Category 6, same shape as LIFE-13–20 but on the `GET /api/platforms/{platform}/recent` picker endpoint rather than `connect`. `recentItems()` calls `$this->scraper->fetchProfile($identity)` synchronously; `GenericPlatformController::recent()` calls the highlights strategy directly with no queue involved.
    - **Plain English:** When a user opens the popup to pick which Bandcamp releases to feature, the site fetches the artist's entire page live, every single time the popup opens — if Bandcamp is slow, the popup just hangs.
    - **Evidence:**
        ```php
        public function recentItems(string $identity): ?array
        {
            $profile = $this->scraper->fetchProfile($identity);
            if ($profile === null) {
                return null;
            }
            return array_slice($profile['items'], 0, 15);
        }
        ```

- [ ] **LIFE-22** · P2 — VimeoHighlights fetches recent videos synchronously from the picker endpoint
    - **Where:** app/Services/Platforms/Strategies/Highlights/VimeoHighlights.php:27-33
    - **Affects:** Users opening the Vimeo highlights picker — same exposure as LIFE-21.
    - **Effort:** M (~2–4h)
    - **What to do:** Same remediation direction as LIFE-21.
    - **Technical:** Category 6, same shape.
    - **Plain English:** Same as the Bandcamp picker issue, for Vimeo.
    - **Evidence:**
        ```php
        public function recentItems(string $identity): ?array
        {
            $videos = $this->vimeo->fetchVideos($identity);
            return $videos === [] ? null : $videos;
        }
        ```

- [ ] **LIFE-23** · P2 — YoutubeHighlights fetches recent videos synchronously from the picker endpoint
    - **Where:** app/Services/Platforms/Strategies/Highlights/YoutubeHighlights.php:28-31
    - **Affects:** Users opening the YouTube highlights picker — same exposure as LIFE-21.
    - **Effort:** M (~2–4h)
    - **What to do:** Same remediation direction as LIFE-21.
    - **Technical:** Category 6, same shape.
    - **Plain English:** Same picker-latency issue, for YouTube.
    - **Evidence:**
        ```php
        public function recentItems(string $identity): ?array
        {
            return $this->scraper->fetchRecentVideos($identity);
        }
        ```

- [ ] **LIFE-24** · P2 — YoutubeMusicHighlights fetches the uploads feed synchronously from the picker endpoint
    - **Where:** app/Services/Platforms/Strategies/Highlights/YoutubeMusicHighlights.php:28-36
    - **Affects:** Users opening the YouTube Music highlights picker — same exposure as LIFE-21.
    - **Effort:** M (~2–4h)
    - **What to do:** Same remediation direction as LIFE-21.
    - **Technical:** Category 6, same shape.
    - **Plain English:** Same picker-latency issue, for YouTube Music.
    - **Evidence:**
        ```php
        public function recentItems(string $identity): ?array
        {
            $feed = $this->scraper->fetchUploadsFeed($identity);
            if ($feed === null || $feed['videos'] === []) {
                return null;
            }
            return YoutubeMusicItems::map($feed['videos']);
        }
        ```

- [ ] **LIFE-25** · P2 — `YoutubeScraper::resolveChannelId()` returns null on every failure path with zero logging
    - **Where:** app/Services/Platforms/YoutubeScraper.php:158-172
    - **Affects:** Every YouTube connect/refresh that depends on handle-to-channel-id resolution — a sustained YouTube-side block or layout change silently degrades every affected user's YouTube integration with no operational signal.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `Log::warning('youtube.channel_resolve_failed', ['handle' => $handle, 'reason' => ...])` on both null-return paths (fetch failure vs. pattern-match failure), with distinct `reason` values so Nightwatch search can tell them apart.
    - **Technical:** Category 10 — distinct logs for distinct failure modes. Both `$page === null || $page['status'] !== 200` and the triple `preg_match` miss return `null` silently. A sustained YouTube block on the platform's egress IP would degrade every user's YouTube connect/refresh with zero breadcrumb in logs — the only symptom is "my YouTube stopped updating" support tickets with no way to correlate them to a systemic cause.
    - **Plain English:** When the system tries to look up a YouTube channel's ID and can't — because YouTube is blocking us or changed their page layout — it just gives up quietly. No record is kept of the failure, so if this starts happening to everyone at once, the team has no way to notice except waiting for user complaints to pile up.
    - **Evidence:**
        ```php
        private function resolveChannelId(string $handle, array $headers): ?string
        {
            $page = $this->fetcher->tryFetch('https://www.youtube.com/@'.rawurlencode($handle), $headers);
            if ($page === null || $page['status'] !== 200) {
                return null;
            }
            if (! preg_match('/"externalId":"(UC[A-Za-z0-9_-]{22})"/', $page['body'], $m)
                && ! preg_match('~/channel/(UC[A-Za-z0-9_-]{22})~', $page['body'], $m)
                && ! preg_match('/"channelId":"(UC[A-Za-z0-9_-]{22})"/', $page['body'], $m)) {
                return null;
            }
        ```

- [ ] **LIFE-26** · P2 — `YoutubeScraper::fetchUploadsFeed()` returns null on three distinct failure paths with zero logging
    - **Where:** app/Services/Platforms/YoutubeScraper.php:76-97
    - **Affects:** Periodic refresh keeping a user's YouTube/YouTube Music highlights current — a silent feed failure leaves the sitepage showing stale videos indefinitely with no operational signal.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Log a warning with `channelId` and a discriminating reason (`fetch_null`, `non_200:{status}`) for the RSS-fetch-failed and non-200 paths (the 304-via-`$cond` path is a legitimate success case and should stay quiet).
    - **Technical:** Category 10, same shape as LIFE-25. This is the primary data source feeding both YouTube and YouTube Music highlights; a systemic RSS-fetch failure would silently stall every affected user's video feed with nothing in the logs to distinguish "feed down" from "channel not found."
    - **Plain English:** This is the step that actually fetches a channel's latest videos. If that fetch fails, the code just quietly hands back nothing — no note is made of why. If YouTube starts blocking these requests broadly, dozens of users' pages would show stale video lists and nobody would know until someone investigated by hand.
    - **Evidence:**
        ```php
        $rss = $this->fetcher->tryFetch('https://www.youtube.com/feeds/videos.xml?playlist_id='.$uploadsPlaylistId, $headers);
        if ($rss === null) {
            return null;
        }
        if ($cond !== null && $cond->handle($rss)) {
            return null;
        }
        if ($rss['status'] !== 200) {
            return null;
        }
        ```

- [ ] **LIFE-27** · P2 — `WooCommerceScraper::probe()` returns `false` on every attempt with zero logging
    - **Where:** app/Services/Platforms/WooCommerceScraper.php:28-42
    - **Affects:** The "is this store WooCommerce?" check during store connect — a false negative caused by our egress being blocked is indistinguishable from "not actually WooCommerce," with no way to triage support tickets.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Log a warning with `origin` and the last response status/`'no response'` before returning `false`, so a systemic block is distinguishable from a genuine non-WooCommerce store.
    - **Technical:** Category 10, same shape as LIFE-25/26. `probe()` loops both Store API URL forms and returns `false` silently on exhaustion — indistinguishable in logs from "this genuinely isn't a WooCommerce store."
    - **Plain English:** When checking whether a pasted link is a WooCommerce store, the system knocks on two doors. If nobody answers at either, it just says "not WooCommerce" — without recording whether the store's site was actually down, blocking us, or genuinely isn't WooCommerce. That makes a real bug (our requests being blocked) look identical to a normal "wrong platform" rejection.
    - **Evidence:**
        ```php
        public function probe(string $origin): bool
        {
            foreach ($this->storeApiUrls($origin, 'per_page=1') as $url) {
                $res = $this->fetcher->tryFetch($url, ['User-Agent' => self::USER_AGENT]);
                if ($res === null || $res['status'] !== 200) {
                    continue;
                }
                ...
            }
            return false;
        }
        ```

- [ ] **LIFE-28** · P2 — `WooCommerceScraper::fetchBrand()` silently returns a nameless brand when the WP REST root fetch fails
    - **Where:** app/Services/Platforms/WooCommerceScraper.php:64-84
    - **Affects:** The store's displayed brand name on the sitepage — a WP REST root failure leaves `name` null with no operational trace.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Log a warning with `origin` when `json($origin.'/wp-json')` returns null, distinct from the `probe()` log key.
    - **Technical:** Category 10, same shape. `$root = $this->json($origin.'/wp-json')` returns `null` silently on any transport failure; `$name` stays null and the sitepage shows a blank store name with no way to know why.
    - **Plain English:** The scraper grabs the store's display name from a separate page. If that page fails to load, the store's name on the professional's sitepage just stays blank — like a shop sign left empty — and nothing records that the fetch failed, so nobody notices until a customer or the professional points it out.
    - **Evidence:**
        ```php
        $root = $this->json($origin.'/wp-json');
        $name = data_get($root, 'name');
        $name = is_string($name) && trim($name) !== '' ? trim($name) : null;
        ```

## P3 — Nice to have

- [ ] **LIFE-29** · P3 — Welcome notification's app-level dedup has no backing DB constraint (documented, deferred gap)
    - **Where:** app/Services/User/UserBootstrapService.php:163-180
    - **Affects:** New-user bootstrap — an extremely narrow concurrent double-bootstrap for the same `auth_user_id` could produce a duplicate "Welcome to Partna" notification.
    - **Effort:** S (~0.5–1h, DB migration)
    - **What to do:**
        - Add `CONSTRAINT notifications_user_type_title_unique UNIQUE (user_id, type, title)` (or a narrower partial index) to `notifications.notifications` in a `supabase/migrations/` file, and wrap the `firstOrCreate` in a `catch (UniqueConstraintViolationException)` no-op.
    - **Technical:** Category 1 — `UniqueConstraintViolationException`, but genuinely low-severity: the code's own comment already documents this as a known, accepted gap (`DINT-12`, "Acceptable for a one-time bootstrap; the DB-level constraint is deferred to the schema standalone (S8)"). `firstOrCreate` dedups at the app level only; there is no DB-level backing constraint, so a true concurrent double-bootstrap for the same `auth_user_id` (already guarded upstream by `UserBootstrapService::bootstrap()`'s single-row lookup) could theoretically slip through. Kept as a tracked finding rather than dropped since it's a real, if narrow, gap — but tiered down from the draft's P2 given it's a one-time, already-documented, near-impossible-in-practice race.
    - **Plain English:** When a new account finishes setup, the system drops a welcome message into the dashboard, and it checks first to make sure it hasn't already done this. But that check happens in application code, not as a hard database rule — so in an extremely unlikely case of two signups running at the exact same instant for the same person, two welcome messages could sneak through. The team already knows about this and has flagged it as low-priority; a small database rule would close it completely.
    - **Evidence:**
        ```php
        private function createWelcomeNotification(User $professional): void
        {
            // firstOrCreate dedups the welcome notification at the app level — there is NO DB unique constraint on (user_id, type, title), so a rare concurrent double-bootstrap could still race. Acceptable for a one-time bootstrap; the DB-level constraint is deferred to the schema standalone (S8). (DINT-12)
            Notification::query()->firstOrCreate(
                [
                    'user_id' => $professional->id,
                    'type' => 'Info',
                    'title' => 'Welcome to Partna',
                ],
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Early-access lifecycle hardening:** LIFE-1, LIFE-4, LIFE-8
    - **Why grouped:** Same file (`EarlyAccessService.php`), same three lifecycle methods (`signupFromMarketing`, `invite`, `markSignedUp`) — one focused session touching one file.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Notification dedup hygiene:** LIFE-2, LIFE-9
    - **Why grouped:** Both are idempotency/dedup gaps in the notification-dispatch path (job-level cache guard vs. dispatcher-level dedupe key), reviewable together against `NotificationPublisher`'s existing correct dedup pattern.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 3 — Account/session concurrency hardening:** LIFE-5, LIFE-6, LIFE-7
    - **Why grouped:** All three are narrow-window race/degradation fixes on account-adjacent write paths (deletion confirm, content-selection toggle, auth-middleware cache invalidation); similar size and risk profile, reviewable as one session.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 4 — Google Business / Instagram connect-vs-refresh races:** LIFE-10, LIFE-11, LIFE-12
    - **Why grouped:** All three are lock-safety gaps in the same connect/enrich/auto-sync subsystem (`GoogleBusinessEnrichJob`, `InstagramConnectJob`, `GoogleBusinessAutoSync`) writing to the same `IntegrationConnection` model family.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 5 — Connect-strategy async conversion (profile/link connectors):** LIFE-13, LIFE-14, LIFE-15, LIFE-16, LIFE-17, LIFE-18, LIFE-19, LIFE-20
    - **Why grouped:** Identical root cause (synchronous vendor fetch inside `GenericPlatformController::connect()`) and identical fix shape across 8 `ConnectStrategy` implementations — best executed as one mechanical pass establishing the async pattern once, then applied per-file.
    - **Model:** Plan: Opus (design the one shared async pattern) · Implement: Sonnet (apply per-file) · Review: Sonnet.

- **Bundle 6 — Highlights-strategy async conversion (picker endpoints):** LIFE-21, LIFE-22, LIFE-23, LIFE-24
    - **Why grouped:** Same root cause and fix shape as Bundle 5, but on the `recent()` picker endpoint's `HighlightsStrategy` implementations — natural follow-on once Bundle 5's pattern exists.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 7 — Scraper silent-failure observability:** LIFE-25, LIFE-26, LIFE-27, LIFE-28
    - **Why grouped:** Same fix shape (add discriminating `Log::warning` on existing silent-null paths) across `YoutubeScraper` and `WooCommerceScraper` — cheap, mechanical, low-risk session.
    - **Model:** Plan+Implement combined (Sonnet, XS-per-item) · Review: Sonnet.

## Standalone — do NOT bundle

- **LIFE-3 — Site-settings PATCH concurrency:** standalone — the platform's single highest-traffic authenticated write path; M-effort correctness fix to the core `Site` update flow warrants its own plan + sign-off rather than folding into an unrelated bundle.
- **LIFE-29 — Welcome-notification UNIQUE constraint:** standalone — requires a `supabase/migrations/` schema change (DB migration), which is always standalone per policy regardless of severity.
