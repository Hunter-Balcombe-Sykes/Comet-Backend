# Lifecycle Correctness Audit — 2026-07-10

**Branch:** audit-fix/analytics-master-2026-07-10
**Lens:** Lifecycle correctness — race-safety, idempotency, anchor decoupling, reconcile loops, vendor resilience, observability discipline
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Http/Middleware/Context/EnforcePendingDeletionReadOnly.php
- app/Http/Middleware/Context/LoadCurrentUser.php
- app/Services/Accounts/AccountCapabilities.php
- app/Services/Accounts/AccountCapabilitySet.php
- app/Services/Profile/SectorTaxonomy.php
- app/Services/PublicSite/IndividualProfilePayloadBuilder.php
- app/Services/PublicSite/SitepageDataResolverService.php
- app/Services/Site/ContentSelectionService.php
- app/Services/Site/UpdateSiteAction.php
- app/Services/User/AccountDeletionService.php
- app/Services/User/DataExport/DataExportPayloadBuilder.php
- app/Services/User/SectionVisibilityService.php
- app/Services/User/SiteProvisioningService.php
- app/Services/User/UserBootstrapService.php
- app/Services/User/Visibility/Rules/ContactVisibility.php
- app/Services/User/Visibility/SectionVisibilityContract.php
- app/Notifications/Moderation/AccountBannedNotification.php
- app/Notifications/Moderation/AccountSuspendedNotification.php
- app/Notifications/Moderation/ReportOutcomeNotification.php
- app/Services/Moderation/EvidenceSnapshotService.php
- app/Jobs/Platforms/GoogleBusinessEnrichJob.php
- app/Jobs/Platforms/InstagramConnectJob.php
- app/Jobs/Platforms/MenuFetchJob.php
- app/Services/Platforms/AppleSearch.php
- app/Services/Platforms/DoorDashMenuDriver.php
- app/Services/Platforms/FreshaScraper.php
- app/Services/Platforms/GoogleBusinessAutoSync.php
- app/Services/Platforms/IdentitySync.php
- app/Services/Platforms/IntegrationConnectionCacheRefresher.php
- app/Services/Platforms/MenuApifyScraper.php
- app/Services/Platforms/MenuMerger.php
- app/Services/Platforms/MenuPlatformDriver.php
- app/Services/Platforms/MenuSource.php
- app/Services/Platforms/NormalizesMenuData.php
- app/Services/Platforms/Payloads/EmbedPayload.php
- app/Services/Platforms/Payloads/FeedPayload.php
- app/Services/Platforms/Payloads/GoogleBusinessPayload.php
- app/Services/Platforms/Payloads/ShopPayload.php
- app/Services/Platforms/Registry/Platform.php
- app/Services/Platforms/Registry/PlatformDescriptor.php
- app/Services/Platforms/ShopCatalog.php
- app/Services/Platforms/ShopifyScraper.php
- app/Services/Platforms/Strategies/Connect/* (Bandcamp, NowBookit, OpenTable, Pinterest, ResDiary, Spotify, Strava, Twitch, Url, Vimeo, Youtube, YoutubeMusic)
- app/Services/Platforms/Strategies/Contracts/* (ConnectResult, ConnectStrategy, HighlightsStrategy)
- app/Services/Platforms/Strategies/Fetch/* (AppleMusic, Fresha, GoogleBusiness, Shop, YoutubeMusic)
- app/Services/Platforms/Strategies/Highlights/* (Bandcamp, Vimeo, Youtube, YoutubeMusic)
- app/Services/Platforms/UberEatsMenuDriver.php
- app/Services/Platforms/WebsiteLinkHarvester.php
- app/Services/Platforms/WooCommerceScraper.php
- app/Services/Platforms/YoutubeMusicItems.php
- app/Services/Platforms/YoutubeScraper.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 3 complete
- P2 Medium: 0 of 8 complete
- P3 Low: 0 of 3 complete

---

## P1 — Fix before pilot launch

- [ ] **LIFE-1** · P1 — `AccountDeletionService::adminInitiate` races on the `pending_deletion` status check
    - **Where:** app/Services/User/AccountDeletionService.php:334-377
    - **Affects:** Any professional undergoing a staff/support-initiated GDPR erasure (Article 17 request). Two near-simultaneous `adminInitiate` calls (double-click on the staff dashboard's "Delete Account" action, or two support agents actioning the same ticket) both read the pre-transition status, both pass the guard, and both run `executeConfirmation` — producing two `EVENT_ADMIN_INITIATED` audit rows and two "your account will be deleted" emails for one erasure request.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Re-fetch the professional with `User::where('id', $professional->id)->lockForUpdate()->first()` inside the same transaction `executeConfirmation` opens, and re-check `status !== 'pending_deletion'` before applying the transition — the canonical **lockForUpdate + UNIQUE** read-modify-write pattern.
        - Alternatively, fold the guard directly into `executeConfirmation()` so every entry point (self-service `confirm`, `adminInitiate`) shares one locked check, rather than duplicating an unlocked pre-check in each caller.
    - **Technical:** The guard `if ($professional->status === 'pending_deletion')` at the top of `adminInitiate()` reads the in-memory model loaded by the controller before any lock is taken; `executeConfirmation()` (called ~20 lines later) opens its own `DB::connection('pgsql')->transaction()` but never re-validates the precondition inside it. Two concurrent staff requests against the same `User` row can both observe `status === 'active'`, both pass, and both execute the full state transition (status flip, site unpublish, audit row, PII pseudonymisation) — this is the same "torn status from an unlocked read-modify-write" shape the house doctrine flags at P1 regardless of frequency. No DB migration is required for the fix; `lockForUpdate` is a query-time concern only.
    - **Plain English:** Imagine two support staff both click "delete this account" for the same person within a split second of each other, because the ticket queue showed it twice. Right now, both clicks go all the way through — the system doesn't notice the account was already being deleted a moment ago — so the customer gets two "your account will be deleted" emails and the internal record shows the deletion happening twice. Making the system "lock" the record for a moment while it checks would mean only the first click actually does anything.
    - **Evidence:**
        ```php
        if ($professional->status === 'pending_deletion') {
            return ['success' => false, 'code' => 409, 'error' => 'Deletion already in progress.'];
        }
        ```
        ```php
        $deletesAt = $this->executeConfirmation(
            $professional,
            UserDeletionAuditEntry::EVENT_ADMIN_INITIATED,
            $request,
            $metadata,
            UserDeletionAuditEntry::ACTOR_TYPE_STAFF_ADMIN,
            $staffActorId,
            $staffActorHandle,
            $reason,
        );
        ```

- [ ] **LIFE-2** · P1 — `AccountDeletionService::confirm` races on token consumption
    - **Where:** app/Services/User/AccountDeletionService.php:120-157
    - **Affects:** Any professional confirming self-service account deletion via the emailed token link. A double-click on the "Confirm Deletion" button, or the same link opened in two tabs, sends two concurrent `confirm()` calls carrying the same valid token — both pass the hash/expiry checks before either commits the transition that nulls `deletion_token_hash`, so both execute `executeConfirmation`.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Consume the token atomically: inside `executeConfirmation`'s transaction, re-verify (under `lockForUpdate`) that `deletion_token_hash` still matches before nulling it and applying the transition — the second concurrent caller then fails the re-check and returns the same 404/410 it would for an already-used token.
        - This is the same **lockForUpdate + UNIQUE** read-modify-write pattern as LIFE-1 — the token hash acts as the idempotency key and must be checked-and-cleared under a lock, not checked once outside any lock and cleared later.
    - **Technical:** `confirm()` validates `deletion_token_hash`/`deletion_requested_at`/`hash_equals()` entirely outside a transaction (lines 122-144), then hands off to `executeConfirmation()`, which is the first point that opens a `pgsql` transaction and nulls the token. Because the invalidating write happens strictly after both requests' checks can pass, two concurrent confirms against the same token both apply the full state transition — duplicate `EVENT_CONFIRMED` audit rows, duplicate `AccountDeletionScheduledMail` sends, and (per the file's own `restoreEmailFromAuditSnapshot()`, which picks the audit row by `orderByDesc('created_at')`) a doubled audit trail that cancel-time email recovery must pick the *right* row out of. Root cause is identical to LIFE-1 — an unlocked read of state that a later step in the same call mutates — so both carry the same tier.
    - **Plain English:** If someone clicks the "Confirm my account deletion" link twice by accident — which people do, especially on a slow connection — the system doesn't notice the first click already started the process. Both clicks go through fully, so the user gets two "we scheduled your deletion" emails and the internal history shows it happening twice. The fix makes the second click recognize "this has already been done" instead of redoing it.
    - **Evidence:**
        ```php
        // No deletion request on file?
        if (! $professional->deletion_token_hash || ! $professional->deletion_requested_at) {
            return ['success' => false, 'code' => 404, 'error' => 'No deletion request found.'];
        }
        ```
        ```php
        // Token mismatch? Timing-safe comparison.
        if (! hash_equals((string) $professional->deletion_token_hash, hash('sha256', $rawToken))) {
            return ['success' => false, 'code' => 404, 'error' => 'Invalid token.'];
        }

        $deletesAt = $this->executeConfirmation(
            $professional,
            UserDeletionAuditEntry::EVENT_CONFIRMED,
            $request,
        );
        ```

- [ ] **LIFE-3** · P1 — Unhandled `UniqueConstraintViolationException` on concurrent same-email sign-up in `UserBootstrapService`
    - **Where:** app/Services/User/UserBootstrapService.php:36-137
    - **Affects:** New users bootstrapping an account. A race on the same email (two sign-up tabs, or a client retry after a timed-out first request) produces a 500 error on the losing request instead of a clean "email already in use" response — on the platform's single highest-traffic user journey.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the `DB::connection('pgsql')->transaction(...)` call in `bootstrap()` in a `try { ... } catch (UniqueConstraintViolationException $e) { throw new RuntimeException('EMAIL_ALREADY_REGISTERED'); }` so a DB-level collision maps to the same `RuntimeException('EMAIL_ALREADY_REGISTERED')` code path the pre-check already throws — the controller/Form Request layer already knows how to turn that into a clean 422.
        - Keep `guardAgainstEmailReuseByDifferentAuthUser()` as the fast-path (avoids a wasted transaction on the common case); treat the unique index as the authoritative guard, per the **`UniqueConstraintViolationException`** house pattern.
    - **Technical:** `core.users` has a real partial unique index — `CREATE UNIQUE INDEX users_email_unique ON core.users (primary_email) WHERE (deleted_at IS NULL);` (`supabase/migrations/20260526000000_baseline_standalone_user.sql:363`) — confirming the constraint DeepSeek assumed actually exists. `guardAgainstEmailReuseByDifferentAuthUser()` performs a plain `SELECT ... exists()` before the insert; two concurrent bootstraps for the same email can both see no row and both proceed to `$professional->save()`. The second `save()` throws `UniqueConstraintViolationException` from the unique index, and nothing in `UserBootstrapService` or its call chain catches that exception type — it propagates as a generic 500 to a brand-new user in the middle of signing up.
    - **Plain English:** Two people (or one person with two open tabs) try to sign up with the exact same email at the exact same moment. Today, the second one crashes with a generic server error instead of a friendly "that email is already registered" message. The database already refuses to store the duplicate — we just need to catch that refusal and turn it into a normal error message instead of a crash.
    - **Evidence:**
        ```php
        if (! $professional) {
            $this->guardAgainstEmailReuseByDifferentAuthUser((string) ($data['primary_email'] ?? ''), $uid);
        ```
        ```php
        private function guardAgainstEmailReuseByDifferentAuthUser(string $email, string $uid): void
        {
            $emailLc = strtolower(trim($email));
            if ($emailLc === '') {
                return;
            }

            $existingByEmail = User::query()
                ->whereRaw('lower(primary_email) = ?', [$emailLc])
                ->where('auth_user_id', '!=', $uid)
                ->exists();

            if ($existingByEmail) {
                throw new RuntimeException('EMAIL_ALREADY_REGISTERED');
            }
        }
        ```

## P2 — Should fix

- [ ] **LIFE-4** · P2 — `GoogleBusinessAutoSync::seedOrdering` races on store-key existence checks
    - **Where:** app/Services/Platforms/GoogleBusinessAutoSync.php:364-382
    - **Affects:** Business-Partna accounts reconnecting or connecting a second Google Business listing before the first `GoogleBusinessEnrichJob` finishes — two enrich jobs (for different `place_id`s, so not deduped by `ShouldBeUnique`'s `uniqueId()`) can both compute `$existingStoreKeys` from the same pre-write snapshot and both attempt to seed the same store.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the existence check + `write()` loop in a transaction with `lockForUpdate()` on the user's `IntegrationConnection` rows for `Platform::OnlineOrdering`, per the **lockForUpdate + UNIQUE** pattern — the existing `idx_platform_connections_unique_active` unique index on `(user_id, platform, resource_id)` already protects exact duplicate rows; the gap is the multi-row "don't exceed `MAX_ORDERING`" and per-store-key dedup logic racing ahead of that index.
    - **Technical:** `$existingOrdering`/`$existingCount`/`$existingStoreKeys` are computed once via a plain (unlocked) query, then mutated in-memory across the `foreach ($stores as ...)` loop while `write()` calls `IntegrationConnection::updateOrCreate()`. Two concurrent job executions for the same user (different Google Business connections) each see the same starting snapshot and can both write for a store the other is also about to write, or both push past `MAX_ORDERING` before either commits. `write()`'s underlying `updateOrCreate` is itself not atomic — it is backed by the real unique index on `(user_id, platform, resource_id)`, so an exact key collision throws (caught by the method's outer `catch (Throwable $e) { report($e); }`), but the cross-provider/cross-store invariants (max count, distinct store keys) have no DB backing at all.
    - **Plain English:** If a business connects two different Google listings close together, the background jobs that pull in ordering links for each one can both check "do we already have this store?" at the same moment, both get "no," and both try to add it — possibly pushing past the intended 10-store limit or double-adding a store. Locking the check while one job is working would stop the other from acting on stale information.
    - **Evidence:**
        ```php
        $existingOrdering = IntegrationConnection::query()
            ->where('user_id', $userId)
            ->where('platform', Platform::OnlineOrdering->value)
            ->get();
        $existingCount = $existingOrdering->count();
        // Key by storeKey for O(1) duplicate detection.
        $existingStoreKeys = $existingOrdering->mapWithKeys(function (IntegrationConnection $row) {
            $key = $this->storeKey(CardPayload::fromArray($row->payload)->url()) ?? '';

            return [$key => true];
        })->all();

        foreach ($stores as $storeKey => $group) {
            if ($existingCount >= self::MAX_ORDERING) {
                break;
            }
            if ($existingStoreKeys[$storeKey] ?? false) {
                continue;   // only-if-empty per store — never clobber an existing one
            }
        ```

- [ ] **LIFE-5** · P2 — `GoogleBusinessAutoSync::seedReservation` races on the single-reservation invariant
    - **Where:** app/Services/Platforms/GoogleBusinessAutoSync.php:119-143 (guard: `hasAnyReservation`, lines 202-211)
    - **Affects:** Business-Partna accounts with two overlapping Google Business enrich jobs — the "only one reservation platform at a time" business rule has no DB backing, so two concurrent jobs resolving to two *different* reservation providers (e.g. OpenTable vs ResDiary) can both pass the check and both write, since each provider's row uses a different `resource_id` and the unique index does not span the whole `RESERVATION_PLATFORMS` group.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Lock the check with `lockForUpdate()` across the `RESERVATION_PLATFORMS` rows before deciding to write, or serialize `seed()` per-user with `CacheLockService` so overlapping enrich jobs for the same user can't interleave this decision — matching **lockForUpdate + UNIQUE**.
    - **Technical:** `hasAnyReservation()` is a plain `exists()` scan across `RESERVATION_PLATFORMS`; between that read and the later `write()` call there is no lock, so two concurrent `GoogleBusinessEnrichJob` runs for the same user (distinct `place_id`s, so not deduped by the job's own uniqueness key) resolving to different providers can both observe "no reservation yet" and both insert. The app-level "one reservation platform" rule is enforced only by this check-then-act, not by any constraint.
    - **Plain English:** The system's rule is "a business should only have one reservation booking link." But if two background jobs are checking that rule at almost the same instant — say, because the business reconnected Google Business twice in quick succession — both can see "no reservation link yet" and both add one, leaving two reservation systems live when only one was intended.
    - **Evidence:**
        ```php
        $label = $write['payload']['name'] ?? 'Reservations';
        if ($this->hasAnyReservation($userId)) {
            return [$this->conflictFinding($write['platform'], $write['resourceId'], 'reservations', is_string($label) ? $label : 'Reservations', $this->urlOf($write), [
                'remove' => self::RESERVATION_PLATFORMS,
                'write' => $write,
            ])];
        }

        $this->write($userId, $write['platform'], $write['resourceId'], $write['payload']);
        ```

- [ ] **LIFE-6** · P2 — `GoogleBusinessAutoSync::seedBooking` races on the single-booking invariant
    - **Where:** app/Services/Platforms/GoogleBusinessAutoSync.php:216-242
    - **Affects:** Business-Partna accounts — same shape as LIFE-5, for `BOOKING_PLATFORMS` (Fresha/Square/Booking): two overlapping enrich jobs can each resolve a different booking provider and both write, leaving two "Book now" links live.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Same fix as LIFE-5: lock the `BOOKING_PLATFORMS` existence check, or serialize `seed()` execution per-user, per **lockForUpdate + UNIQUE**. Consider fixing LIFE-4/5/6 together since all three share the same `has()`-then-`write()` shape inside `GoogleBusinessAutoSync`.
    - **Technical:** `collect(self::BOOKING_PLATFORMS)->contains(fn ($p) => $this->has($userId, $p))` is an unlocked existence check; `write()` runs after with no re-validation. Same root cause as LIFE-4/5.
    - **Plain English:** Same issue as the reservation case: two near-simultaneous background checks can each conclude "no booking link yet" and both add one — the business ends up with two different "Book now" buttons pointing at two different systems.
    - **Evidence:**
        ```php
        $label = match ($write['platform']) {
            Platform::Fresha->value => 'Fresha', Platform::Square->value => 'Square', default => $write['payload']['name'] ?? 'Booking',
        };
        if (collect(self::BOOKING_PLATFORMS)->contains(fn ($p) => $this->has($userId, $p))) {
            return [$this->conflictFinding($write['platform'], $write['resourceId'], 'booking', is_string($label) ? $label : 'Booking', $this->urlOf($write), [
                'remove' => self::BOOKING_PLATFORMS,
                'write' => $write,
            ])];
        }

        $this->write($userId, $write['platform'], $write['resourceId'], $write['payload']);
        ```

- [ ] **LIFE-7** · P2 — `Workplace` row races between `IdentitySync` (connection-save observer) and `GoogleBusinessAutoSync::seedWorkplace` (async enrich job)
    - **Where:** app/Services/Platforms/IdentitySync.php:70-94; app/Services/Platforms/GoogleBusinessAutoSync.php:285-324
    - **Affects:** Every Google Business connect — `IdentitySync::applyFromGooglePayload` runs synchronously from `IntegrationConnectionObserver` when the controller saves the Place Details payload, while `GoogleBusinessAutoSync::seedWorkplace` runs later, asynchronously, from `GoogleBusinessEnrichJob`. Both independently `firstOrNew` the same `site.workplaces` row, conditionally fill blank fields, and `save()` with no lock between them.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Fetch the `Workplace` row with `lockForUpdate()` inside a short transaction in both write paths — **lockForUpdate + UNIQUE** — so the observer's synchronous fold and the job's later fold can't clobber each other's writes on overlapping fields (`category` is written by both).
    - **Technical:** `IdentitySync::applyFromGooglePayload` (fires synchronously on connection save) and `GoogleBusinessAutoSync::seedWorkplace` (fires from the async `GoogleBusinessEnrichJob`, dispatched from the same request but executed later on a worker) both perform `firstOrNew(['site_id' => ...])` → conditional per-field fill → `save()` with no row lock. `category` is a field both write from the same underlying Google payload — in practice usually convergent, but nothing stops a genuinely concurrent third write (e.g. the user manually editing the workplace card between the observer's synchronous write and the job's later write) from being silently overwritten by the job's stale in-memory copy, since the job never re-reads after the observer's fold commits.
    - **Plain English:** Two different parts of the system — one that runs the instant you connect Google Business, and one that runs a bit later in the background — both try to fill in the same business-profile fields from the same Google data. They don't check with each other first, so it's possible (though currently more coincidence-safe than not, since they usually compute the same values) for one to overwrite a field the other — or the user — just set.
    - **Evidence:**
        ```php
        $workplace = Workplace::firstOrNew(['site_id' => (string) $site->id]);
        $sources = is_array($workplace->field_sources) ? $workplace->field_sources : [];
        $stamp = now()->toIso8601String();
        $changed = false;

        foreach ($candidates as $field => $value) {
            if ($value === null) {
                continue; // Google didn't provide this field (or malformed).
            }

            // Per-field precedence: business overwrites; partna fills only
            // when the current column value is blank.
            if (! $overwrite && ! $this->isBlank($workplace->{$field})) {
                continue;
            }

            $workplace->{$field} = $value;
            $sources[$field] = ['source' => self::GOOGLE_SOURCE, 'at' => $stamp];
            $changed = true;
        }
        ```
        ```php
        $workplace = Workplace::query()->firstOrNew(['site_id' => (string) $site->id]);

        $changed = false;
        foreach ($fields as $key => $value) {
            // Only fill if the column is currently blank — never overwrite user data.
            if ($this->blank($workplace->{$key} ?? null)) {
                $workplace->{$key} = $value;
                $changed = true;
            }
        }
        ```

- [ ] **LIFE-8** · P2 — `IdentitySync` races on `User.sector` / `public_contact_number` writes
    - **Where:** app/Services/Platforms/IdentitySync.php:115-159
    - **Affects:** Every Google Business connect — two overlapping calls to `applyFromGooglePayload` for the same user (double-submit connect, or a reconnect racing the tail of a prior connect) can both read the user row's blank/current field, both write, with the later `save()` winning and silently discarding the earlier write.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Fetch the `User` row with `lockForUpdate()` before the blank check and `save()` in both `applySector` and `mirrorPublicContactNumber`, same **lockForUpdate + UNIQUE** pattern as LIFE-7 (same file, same underlying trigger — bundle together).
    - **Technical:** Both methods run a read (`isBlank($user->sector)` / `isBlank($user->public_contact_number)`) then a conditional `$user->save()`, with no lock in between. As with LIFE-7, this only manifests as data loss when two concurrent connect flows for the same user compute genuinely different values — plausible if the user is mid-edit on one tab while a Google reconnect runs on another.
    - **Plain English:** Same underlying gap as the workplace-fields issue above, but for the business's "sector" and public phone number stored on the account itself rather than the workplace card.
    - **Evidence:**
        ```php
        if ($overwrite) {
            if ($user->sector !== $mapped) {
                $user->sector = $mapped;
                $user->sector_source = self::GOOGLE_SOURCE;
                $user->save();
            }

            return;
        }

        // partna: fill only when nothing is set yet.
        if ($this->isBlank($user->sector)) {
            $user->sector = $mapped;
            $user->sector_source = self::GOOGLE_SOURCE;
            $user->save();
        }
        ```
        ```php
        if (! $overwrite && ! $this->isBlank($user->public_contact_number)) {
            return;
        }

        if ($user->public_contact_number !== $phone) {
            $user->public_contact_number = $phone;
            $user->save();
        }
        ```

- [ ] **LIFE-9** · P2 — `ReportOutcomeNotification` silently swallows decision types that are already reachable today
    - **Where:** app/Notifications/Moderation/ReportOutcomeNotification.php:24-30
    - **Affects:** Reporters who submitted a moderation report where staff decided `csam_auto_suspend`, `override_csam_auto_action`, `escalate_law_enforcement`, or `escalate_esafety` — 4 of the 9 `decision_type` values currently allowed by `moderation.decisions`' CHECK constraint (`supabase/migrations/20260528000000_create_moderation_schema.sql` + `20260530000100_add_csam_auto_suspend_decision_type.sql`) fall straight into the vague `default` branch today, not just hypothetically on a future enum addition.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Log a warning in the `default` branch with the unrecognised `decision_type` and `decision_id` before returning the generic fallback — **Log-with-context** — e.g. `Log::warning('report_outcome_notification.unhandled_decision_type', ['decision_type' => ..., 'decision_id' => ...])`.
        - Add explicit `match` arms for the 4 currently-reachable-but-unhandled types so reporters of severe cases (escalated to law enforcement / eSafety) get an accurate message rather than the generic one.
    - **Technical:** `moderation.decisions.decision_type` is CHECK-constrained to 9 values (`dismiss, warn, hide_content, hide_site, suspend_user, ban_user, csam_auto_suspend, override_csam_auction_action, escalate_law_enforcement, escalate_esafety` per the two migrations above), but this notification's `match` only names 6, falling to `default => 'We reviewed your report.'` for the remaining 4 — with zero log emitted. This is the house **distinct logs for distinct failure modes** gap: a 9-outcome function produces at most 6 distinguishable results, and the other 3 silently collapse with no engineering visibility.
    - **Plain English:** When a reporter is told the outcome of their report, the message is supposed to match what actually happened. But for the platform's most serious moderation actions (like escalating to law enforcement), the code falls back to a generic "we reviewed your report" message and doesn't tell anyone on the engineering team that this happened. It's like a form letter going out for a serious case without anyone noticing the specific-case letter never got written.
    - **Evidence:**
        ```php
        $outcome = match ($this->decision->decision_type) {
            'dismiss' => 'We reviewed your report and determined no action was needed.',
            'warn' => 'We reviewed your report and warned the user.',
            'hide_content', 'hide_site' => 'We reviewed your report and removed the content.',
            'suspend_user', 'ban_user' => 'We reviewed your report and took action against the account.',
            default => 'We reviewed your report.',
        };
        ```

- [ ] **LIFE-10** · P2 — Welcome notification bypasses the platform's canonical idempotent/capability-gated notification path
    - **Where:** app/Services/User/UserBootstrapService.php:163-180
    - **Affects:** Every new user's first in-app notification. `createWelcomeNotification()` calls `Notification::query()->firstOrCreate()` directly instead of the established `NotificationPublisher::publish()`, so it gets neither the atomic `dedupe_key`-backed idempotency the rest of the notification system relies on, nor the `AccountCapabilities` gate every other dispatcher goes through.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace the direct `Notification::firstOrCreate()` call with `app(NotificationPublisher::class)->publish($professional->id, 'Info', <category>, 'Welcome to Partna', <body>, dedupeKey: 'welcome_notification', ...)` — this reuses the existing atomic `insertOrIgnore` on `(user_id, dedupe_key)` backed by the real `notifications_dedupe_key_per_pro_uq` unique index, matching **`JSONB dedup`**-equivalent dedup-column idempotency already built for this exact problem.
        - This also closes the **`AccountCapabilities` gate** gap for free — `NotificationPublisher::publish()` already calls `passesCapabilityGate()` before inserting, which the direct `firstOrCreate()` call skips entirely.
    - **Technical:** `NotificationPublisher::publish()` (`app/Services/Notifications/NotificationPublisher.php:32-140`) is the codebase's own gold-standard fix for exactly this shape of problem: `DB::table('notifications.notifications')->insertOrIgnore(...)` on `(user_id, dedupe_key)`, backed by `CREATE UNIQUE INDEX notifications_dedupe_key_per_pro_uq ON notifications.notifications (professional_id, dedupe_key) WHERE dedupe_key IS NOT NULL` (`supabase/migrations/20260526000000_baseline_standalone_user.sql:1031`). `createWelcomeNotification()` predates or simply never adopted this path — it sets no `dedupe_key`, so the partial unique index doesn't apply to it at all, and it never calls `AccountCapabilities::for($user)`. The in-code comment (`DINT-12`) already acknowledges the missing DB-level guard and defers it; since the fix already exists elsewhere in the codebase, "add a bespoke unique constraint" (DeepSeek's original suggestion) would be reinventing a pattern the codebase has already solved once.
    - **Plain English:** Every other "send this notification once" message in the app goes through a shared, battle-tested piece of code that guarantees no duplicates and checks whether the recipient is even allowed to receive it. The very first message a new user gets — "Welcome to Partna" — was written before that shared code existed and never got moved over, so it's the one place that could still (rarely) send a duplicate, and it skips the eligibility check every other notification respects.
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
                [
                    'body' => 'Your account is ready. Complete your profile and start building your professional page from your dashboard.',
                    'cta_url' => null,
                    'severity' => 'info',
                    'starts_at' => now(),
                    'ends_at' => null,
                ]
            );
        }
        ```

- [ ] **LIFE-11** · P2 — `EvidenceSnapshotService::capture()` has no DB-level idempotency guard despite claiming one
    - **Where:** app/Services/Moderation/EvidenceSnapshotService.php:24-51
    - **Affects:** Moderation staff reviewing evidence on any case with multiple concurrent signals (spam/raid waves reporting the same content simultaneously) — duplicate evidence rows waste storage and confuse case review; once a `UNIQUE` constraint is eventually added to close the gap, an unhandled collision would instead 500 the reporting/signal-ingest path.
    - **Effort:** M (~2–4h, includes a `supabase/migrations/` DDL change)
    - **What to do:**
        - Add `CREATE UNIQUE INDEX evidence_case_content_hash_uq ON moderation.evidence (case_id, content_hash) WHERE content_hash IS NOT NULL;` as a new migration (raw SQL per this repo's `supabase/migrations/` convention — never a Laravel migration).
        - Wrap `Evidence::forceCreate([...])` in `try { ... } catch (UniqueConstraintViolationException $e) { return Evidence::where('case_id', $caseId)->where('content_hash', $contentHash)->firstOrFail(); }`, per the **`UniqueConstraintViolationException`** house pattern — never a bare `catch (QueryException $e)` + message-matching.
    - **Technical:** `moderation.evidence` (`supabase/migrations/20260528000000_create_moderation_schema.sql:77-93`) declares `content_hash VARCHAR(64) NULL` with no unique constraint at all — confirmed by reading the table DDL directly. `capture()` computes `content_hash` specifically to make re-snapshotting idempotent (per its own comment) but calls `Evidence::forceCreate()` unconditionally with no catch, so today two concurrent `capture()` calls for the same content produce two rows with identical `content_hash` and no error — silent duplication, not a crash. Since it's a DB/migration change, this is standalone rather than bundlable with other fixes.
    - **Plain English:** When someone reports a page, the system takes a snapshot of what it looked like at that moment, and the code assumes "if the same content gets snapshotted twice, we'll just get the same snapshot back." But nothing in the database actually enforces that — so if two people report the same page at nearly the same instant (common during a coordinated spam wave), the system silently creates two identical evidence records instead of one, which wastes space and can confuse a moderator reviewing the case.
    - **Evidence:**
        ```php
        // Hash excludes captured_at so re-snapshotting unchanged content is idempotent.
        $hashInput = $payload;
        unset($hashInput['captured_at']);
        $contentHash = hash('sha256', json_encode($hashInput, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        // forceCreate() bypasses the model's $guarded = ['id'] so the explicit UUID is stored.
        return Evidence::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $caseId,
            'signal_id' => $signalId,
            'evidence_type' => 'content_snapshot',
            'payload' => $payload,
            'content_hash' => $contentHash,
        ]);
        ```

## P3 — Nice to have

- [ ] **LIFE-12** · P3 — `GoogleBusinessAutoSync` exception reports lack `user_id` context
    - **Where:** app/Services/Platforms/GoogleBusinessAutoSync.php (seedReservation:138-142, seedBooking:237-241, seedWorkplace:321-323, seedOrdering:426-428, seedSocials:530-532 & 541-543)
    - **Affects:** Incident response — Nightwatch exception reports from these 6 catch sites carry a stack trace but no explicit `user_id`, making it slower to correlate a seed failure to the affected professional during triage (though the enclosing `GoogleBusinessEnrichJob`'s job payload, which does carry `$this->userId`, may partially compensate).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `Log::warning('google_business_auto_sync.seed_failed', ['user_id' => $userId, 'method' => __FUNCTION__, 'error' => $e->getMessage()])` alongside each `report($e)` call, per **Log-with-context**.
    - **Technical:** Every catch block in this class is `catch (Throwable $e) { report($e); ... }` with no accompanying structured log carrying `$userId`. `report($e)` does surface to Nightwatch as an exception (unlike a bare `Log::warning`), so this is a correlation-speed gap rather than a total observability blind spot.
    - **Plain English:** When one of these background sync steps fails, the error does get reported to the team's monitoring tool, but it doesn't explicitly say which user's account was affected — like a fire alarm that goes off without saying which room. Adding the user ID to the log line makes tracing the failure faster.
    - **Evidence:**
        ```php
        } catch (Throwable $e) {
            report($e);

            return [];
        }
        ```
        ```php
        } catch (Throwable $e) {
            report($e);
        }
        ```

- [ ] **LIFE-13** · P3 — Account-deletion cancel has no lock/idempotency guard against concurrent cancellation
    - **Where:** app/Http/Controllers/Api/User/Account/UserAccountDeletionController.php:95-109; app/Services/User/AccountDeletionService.php:424-437
    - **Affects:** Users cancelling deletion during the 30-day grace period — two concurrent cancel requests can both pass the controller's status check and both call the service's `cancel()`, sending two "deletion cancelled" emails. No state corruption results: `restoreSiteAndStatus()`'s internal `lockForUpdate()` on the `Site` row (and its `unpublished_at !== null` guard) already makes the actual state restoration idempotent.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a cheap idempotency check inside `AccountDeletionService::cancel()` itself (re-check `status === 'pending_deletion'` under `lockForUpdate` before proceeding) rather than relying solely on the controller's unlocked pre-check, so the service is safe to call from any future caller too.
    - **Technical:** The `pending_deletion` gate lives only in `UserAccountDeletionController::cancel()` (`if ($professional->status !== 'pending_deletion') return $this->error(...)`), not in `AccountDeletionService::cancel()`, which restores state and sends mail unconditionally whenever called. Two concurrent controller requests can both pass the controller-level check before either commits the service-level state change. Unlike LIFE-1/LIFE-2, this doesn't cascade into a corrupted GDPR audit trail — `restoreEmailFromAuditSnapshot()` is idempotent (same snapshot value regardless of how many times it's applied) and the site-republish step is already `lockForUpdate`-guarded — so the only externally visible effect is a duplicate cancellation email.
    - **Plain English:** If someone clicks "cancel my account deletion" twice in a row very quickly, they might get two "your deletion was cancelled" emails. The actual account state doesn't get corrupted — the more important parts of this flow are already protected — so this is just a minor annoyance to clean up, not a real risk.
    - **Evidence:**
        ```php
        if ($professional->status !== 'pending_deletion') {
            return $this->error('No pending deletion to cancel.', 409);
        }

        $this->deletionService->cancel($professional, $request);
        ```
        ```php
        public function cancel(User $professional, Request $request): array
        {
            $previousStatus = $professional->deletion_previous_status;
            if (! is_string($previousStatus) || $previousStatus === '') {
                $previousStatus = 'active';
            }

            $this->restoreSiteAndStatus($professional, $previousStatus);
            $this->sendDeletionCancelledMail($professional);

            $this->logAuditEvent($professional, UserDeletionAuditEntry::EVENT_CANCELLED, $request);

            return ['success' => true, 'code' => 200];
        }
        ```

- [ ] **LIFE-14** · P3 — `LoadCurrentUser::syncEmailFromClaims` has a narrow, self-acknowledged, self-healing lost-update window
    - **Where:** app/Http/Middleware/Context/LoadCurrentUser.php:93-137
    - **Affects:** Users who change their verified email and immediately fire two concurrent requests (e.g. multi-tab re-login) carrying two different currently-valid verified emails from the JWT — a rare race could leave one email update transiently lost, self-healing on the next request against the JWT's current claim.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - If closing this permanently is prioritized, replace the read-compare-`save()` sequence with a conditional `UPDATE core.users SET primary_email = ? WHERE id = ? AND primary_email = ?` (or a `lockForUpdate()` re-read) so the write only applies against the exact value that was compared — per **lockForUpdate + UNIQUE**.
        - Given the code's own `DINT-2` comment already scopes this as an accepted, self-healing edge case with no cross-user exposure (the `UniqueConstraintViolationException` catch already prevents the outcome that actually matters — silently taking over another user's email row), this can stay low priority.
    - **Technical:** `syncEmailFromClaims()` reads `$professional->primary_email`, compares case-insensitively to the JWT's claimed email, then calls `$professional->save()` — a full-row Eloquent save, not a targeted conditional `UPDATE`. Two concurrent requests carrying different verified emails for the same user could both pass the comparison and the later `save()` wins, discarding the earlier one. The existing `catch (UniqueConstraintViolationException $e)` block already handles the more dangerous cross-user collision case (another account already owns the claimed email) by logging (with a properly HMAC-hashed email, never raw PII) and leaving the row untouched — this finding is only about the same-user lost-update window the code's own `DINT-2` comment already scopes and accepts.
    - **Plain English:** If a user changes their email and then reloads the page from two open tabs at almost the same instant, it's theoretically possible for one of the updates to get silently dropped for a moment. The developers already knew about this and decided it was low-risk because it fixes itself automatically the next time the user makes a request — this finding just confirms that assessment is accurate and offers a permanent close if it's ever prioritized.
    - **Evidence:**
        ```php
        // DINT-2: this read (the strcasecmp above) and this write are not
        // wrapped in a transaction or a conditional `UPDATE ... WHERE
        // primary_email = ?`. The only realistic race is the same user firing
        // two concurrent requests carrying two different currently-valid
        // verified emails (e.g. multi-tab re-login) — a narrow window with no
        // cross-user exposure. The UniqueConstraintViolationException catch
        // below already prevents the outcome that actually matters (silently
        // taking over another user's email row); worst case here is a lost
        // update that self-heals on the next request against the JWT's
        // current claim. Not worth a conditional UPDATE for that edge case.
        try {
            $professional->primary_email = $claimedEmail;
            $professional->save();
            $this->userCache->invalidateUser($professional);
        } catch (UniqueConstraintViolationException $e) {
        ```

## Suggested Bundled Sessions

- **Bundle A — Bootstrap & signup notification hardening:** LIFE-3, LIFE-10
    - **Why grouped:** Both live in `UserBootstrapService.php` and touch the same `bootstrap()` call chain (unhandled unique-constraint exception on signup, and the welcome notification it dispatches immediately after).
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle B — Google Business connector race hardening:** LIFE-4, LIFE-5, LIFE-6, LIFE-7, LIFE-8
    - **Why grouped:** All five share the identical root-cause shape (unlocked check-then-write against `IntegrationConnection`/`Workplace`/`User` rows from `GoogleBusinessAutoSync` and `IdentitySync`) and touch only two files — fix in one pass with one set of concurrency tests.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle C — Distinct logs for distinct failure modes:** LIFE-9, LIFE-12
    - **Why grouped:** Both are pure observability gaps (missing discriminating log/warning on a swallowed or under-specified failure path) with no behavioral risk — safe to land together.
    - **Model:** Plan: Opus (combine plan+implement for S effort) · Implement: Sonnet · Review: Sonnet.

- **Bundle D — Lockless read-modify-write cleanup (P3):** LIFE-13, LIFE-14
    - **Why grouped:** Both are low-severity, self-limiting read-modify-write races (duplicate cancellation email; self-healing email lost-update) already partially mitigated by adjacent guards — same pattern, low individual risk, safe to land together.
    - **Model:** Plan: Opus (combine plan+implement for S/M effort) · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **LIFE-1 — `AccountDeletionService::adminInitiate` race** · standalone: P1, modifies the GDPR account-deletion state machine (irreversible-adjacent, audit-critical path) — plan + sign-off before implementing.
- **LIFE-2 — `AccountDeletionService::confirm` race** · standalone: P1, same GDPR deletion state machine as LIFE-1 — plan + sign-off before implementing.
- **LIFE-11 — `EvidenceSnapshotService` missing idempotency guard** · standalone: requires a `supabase/migrations/` DDL change (new `UNIQUE` index on `moderation.evidence`) — DB/migration items always run alone per the fix-flow blocker gate.
