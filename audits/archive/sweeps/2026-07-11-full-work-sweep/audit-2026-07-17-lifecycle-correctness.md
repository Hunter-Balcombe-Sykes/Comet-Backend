# Lifecycle Correctness Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Lifecycle correctness — race-safety, idempotency, anchor decoupling, reconcile loops, vendor resilience, observability discipline (account/site lifecycle, Cloudflare KV subdomain sync, and platform-connector auto-sync scope groups)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Services/PublicSite/SitepageDataResolverService.php
- app/Services/User/AccountDeletionService.php
- app/Services/User/SiteProvisioningService.php
- app/Services/User/UserBootstrapService.php
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php
- app/Services/Cloudflare/CloudflareKvService.php
- routes/console.php
- app/Console/Commands/BackfillSubdomainKvCommand.php
- app/Services/Platforms/IdentitySync.php
- app/Services/Platforms/GoogleBusinessAutoSync.php
- app/Services/Platforms/InstagramAutoSync.php
- app/Observers/Core/IntegrationConnectionObserver.php
- app/Jobs/Platforms/InstagramConnectJob.php
- app/Services/Platforms/InstagramScraper.php
- supabase/migrations/20260526000000_baseline_standalone_user.sql
- supabase/migrations/20260602150238_create_platform_connections.sql

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 9 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **#LIFE-1** · P1 — Signup email-conflict handling discriminates constraints by string-matching the Postgres driver message
    - **Where:** app/Services/User/UserBootstrapService.php:100-116
    - **Affects:** Every new-user and existing-user bootstrap call (`POST` signup/profile-bootstrap) — the hottest identity-creation path in the app.
    - **Effort:** M (~2–4h, needs a real-Postgres regression test since SQLite doesn't abort transactions on constraint violation the same way)
    - **What to do:**
        - Mirror the pattern `SiteProvisioningService::tryCreateSite` already uses in this codebase: wrap `$professional->save()` in a nested `DB::connection('pgsql')->transaction()` so Postgres emits a SAVEPOINT instead of aborting the whole outer bootstrap transaction on a `23505`. That lets the catch block re-query (`User::query()->whereRaw('lower(primary_email) = ?', [$emailLc])->exists()`) to determine the real cause deterministically instead of parsing driver text.
        - If the savepoint restructure is deferred, at minimum stop matching the loose `'primary_email'` substring (which can false-positive on unrelated messages that happen to mention that column name) and anchor purely on the fixed index name `users_email_unique` (confirmed at `supabase/migrations/20260526000000_baseline_standalone_user.sql:363`).
        - Add a Postgres-gated regression test (same gating as `SiteProvisioningSavepointTest`) so a future Postgres minor/major version upgrade that reflows the unique-violation message text fails CI instead of silently turning `EMAIL_ALREADY_REGISTERED` into a raw 500 in production.
    - **Technical:** The code already correctly catches the typed `UniqueConstraintViolationException` (not a bare `QueryException`), but then discriminates *which* constraint fired by `str_contains()` on `$e->getMessage()` — the same version-unstable pattern the house doctrine's `UniqueConstraintViolationException` canonical pattern exists to eliminate, just one layer deeper. The comment above the catch block (`// LIFE-6: guardAgainstEmailReuseByDifferentAuthUser is a TOCTOU-racy pre-check...`) already documents that this exact race is expected to happen in production (two concurrent signups for the same email), meaning this code path executes for real users today, not just in theory. `SiteProvisioningService::tryCreateSite` in the same codebase already demonstrates the correct fix — a nested transaction (SAVEPOINT) that survives the constraint violation and lets the caller re-query cleanly, with no string matching at all.
    - **Plain English:** When someone signs up with an email that's already registered, the system currently reads the raw error text Postgres hands back to decide "was this an email clash, or something else entirely?" That's like sorting mail by skimming the first sentence of each letter — if the postal service (Postgres) ever rewords its form letters during an upgrade, the sorting rule silently breaks and a user trying to sign up with a taken email gets a confusing crash page instead of "this email is already registered." The fix makes the check ask the database directly instead of guessing from wording.
    - **Evidence:**
        ```php
        } catch (UniqueConstraintViolationException $e) {
            // LIFE-6: guardAgainstEmailReuseByDifferentAuthUser is a TOCTOU-racy pre-check;
            // the lower(primary_email) unique index is the real backstop. ...
            if (str_contains($e->getMessage(), 'users_email_unique')
                || str_contains($e->getMessage(), 'primary_email')) {
                throw new RuntimeException('EMAIL_ALREADY_REGISTERED', 0, $e);
            }

            throw $e;
        }
        ```

## P2 — Should fix

- [ ] **#LIFE-2** · P2 — `purgeWaitlistSignup` error log carries no user identifier
    - **Where:** app/Services/User/AccountDeletionService.php:717-733
    - **Affects:** GDPR-erasure audit trail during the daily `purge()` sweep — support can't correlate a failed waitlist-row erasure back to the account that triggered it.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Thread `$professional` (already in scope at the `purge()` call site) into `purgeWaitlistSignup` and add `'user_id' => $professional->id` to the `Log::error` context.
    - **Technical:** The `Log-with-context` canonical pattern requires `user_id` on every lifecycle log so Nightwatch correlation works. `purgeWaitlistSignup` only receives `?string $lookupEmail` — the `$professional` reference available at the call site in `purge()` is discarded before the method boundary, so the catch block has no way to identify which account's erasure step failed.
    - **Plain English:** This step deletes a leftover signup record during account deletion. If that delete fails, the error log right now just says "something broke" with no name attached — like a shredding service reporting a jam with no ticket number. Adding the user ID lets support find and manually clean up the right record.
    - **Evidence:**
        ```php
        private function purgeWaitlistSignup(?string $lookupEmail): void
        {
            if ($lookupEmail === null || trim($lookupEmail) === '') {
                return;
            }

            try {
                DB::connection('pgsql')
                    ->table('core.waitlist_signups')
                    ->where('email_lc', mb_strtolower(trim($lookupEmail)))
                    ->delete();
            } catch (\Throwable $e) {
                Log::error('Waitlist signup erasure failed during account purge', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
        ```

- [ ] **#LIFE-3** · P2 — `purgeGlobalEmailSubscriptions` error log carries no user identifier
    - **Where:** app/Services/User/AccountDeletionService.php:846-863
    - **Affects:** Same GDPR-erasure audit trail as #LIFE-2 — global (platform-marketing) subscription rows keyed only by email have no user_id trail on failure.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Same fix as #LIFE-2: thread `$professional` into the method and add `'user_id' => $professional->id` to the log context.
    - **Technical:** Identical root cause to #LIFE-2 — same file, same `Log-with-context` gap, same missing parameter. Tiered identically per the "same root cause, same tier" rule.
    - **Plain English:** Same shredder-jam problem as #LIFE-2, but for the platform's global marketing mailing list. When this cleanup step fails, the report is anonymous.
    - **Evidence:**
        ```php
        private function purgeGlobalEmailSubscriptions(?string $lookupEmail): void
        {
            if ($lookupEmail === null || trim($lookupEmail) === '') {
                return;
            }

            try {
                DB::connection('pgsql')
                    ->table('notifications.email_subscriptions')
                    ->whereNull('user_id')
                    ->where('email_lc', mb_strtolower(trim($lookupEmail)))
                    ->delete();
            } catch (\Throwable $e) {
                Log::error('Global email subscription erasure failed during account purge', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
        ```

- [ ] **#LIFE-4** · P2 — `safeQuery` presence-probe failures log with no correlation context or per-probe discriminator
    - **Where:** app/Services/PublicSite/SitepageDataResolverService.php:355-364
    - **Affects:** Public sitepage resolution — `presentPageIds()` calls `safeQuery` 8 times per resolve; every failure (transient DB blip, partial-env missing table) logs the identical string with no `user_id`/`site_id` and no indication which of the 8 probes failed.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add an optional `string $probe` label parameter to `safeQuery` and pass a discriminator at each of the 8 call sites (`'integration_platforms'`, `'shop_product'`, `'gb_display_settings'`, `'menu'`, `'services'`, `'links'`, `'gallery'`, `'curated_gallery'`).
        - Thread `$site?->user_id` / `$site?->id` into the log context — every call site already has `$site` or `$userId` in scope.
    - **Technical:** The `Log-with-context` canonical pattern requires enough context for Nightwatch to group and attribute failures. All 8 call sites in `presentPageIds()` (plus `buildCuratedGalleryData`) funnel through this one private method and emit the identical `'sitepage.presence_probe_failed'` string with only `$e->getMessage()` — which varies per exception instance and defeats grouping entirely. A support report of "my Shop page disappeared" currently yields zero forensic signal distinguishing that from a gallery or menu probe failure.
    - **Plain English:** Eight different background checks feed into building a user's public page. Right now, if any one of them has a hiccup, the error log just says "a check failed" — no user, no site, no indication which of the eight. A user reporting a broken page gives support nothing to trace. Tagging each check with a name and the affected user turns a blur into a precise report.
    - **Evidence:**
        ```php
        private function safeQuery(\Closure $query, mixed $default): mixed
        {
            try {
                return $query();
            } catch (QueryException $e) {
                Log::warning('sitepage.presence_probe_failed', ['error' => $e->getMessage()]);

                return $default;
            }
        }
        ```

- [ ] **#LIFE-5** · P2 — `GoogleBusinessAutoSync::seedBooking` XOR invariant (one active booking provider) is check-then-write, not atomic
    - **Where:** app/Services/Platforms/GoogleBusinessAutoSync.php:250-257
    - **Affects:** Business Partna accounts with Google Business connected — two near-simultaneous auto-sync sources (e.g. connecting Google Business and Instagram back-to-back during onboarding, or a scheduled Google Business refresh landing mid-connect) can both observe "no booking connection yet" and each write a different provider (Fresha vs Square vs custom Booking), leaving two live booking cards.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the "any booking platform exists" check + `$this->write(...)` in a short-lived `Cache::lock("booking-seed:{$userId}", 10)->block(5)` (or a Postgres advisory lock keyed on `$userId`) so the check-then-write is serialized per user. A row-level `lockForUpdate` alone doesn't close this gap — the invariant spans three *different* `platform` values (Fresha/Square/Booking), so there's no single row to lock against a concurrent INSERT.
        - Longer-term (separate migration, not bundled here): a Postgres partial unique index — `UNIQUE (user_id) WHERE platform IN ('fresha','square','booking') AND deleted_at IS NULL` on `site.platform_connections` — would enforce the XOR invariant at the DB layer regardless of application-level locking gaps.
    - **Technical:** `site.platform_connections` has `idx_platform_connections_unique_active` — a `UNIQUE (user_id, platform, resource_id) WHERE deleted_at IS NULL` index (confirmed in `supabase/migrations/20260602150238_create_platform_connections.sql:32-34`) — but that index can't prevent two *different* platform values from both landing for the same user, which is exactly the failure mode here: `collect(self::BOOKING_PLATFORMS)->contains(fn ($p) => $this->has($userId, $p))` and the subsequent `write()` are two separate round-trips with no lock between them.
    - **Plain English:** Only one "Book now" button should be live at a time (Fresha, Square, or a custom link — never two). Right now the code checks "is a booking button already set?" and, if not, sets one — but two different automated processes (say, connecting Google Business and Instagram moments apart) can both check at the same instant, both see "not set," and both set a different one. The result: the dashboard shows two competing booking connections instead of one clean choice.
    - **Evidence:**
        ```php
        if (collect(self::BOOKING_PLATFORMS)->contains(fn ($p) => $this->has($userId, $p))) {
            return [$this->conflictFinding($write['platform'], $write['resourceId'], 'booking', is_string($label) ? $label : 'Booking', $this->urlOf($write), [
                'remove' => self::BOOKING_PLATFORMS,
                'write' => $write,
            ])];
        }

        $this->write($userId, $write['platform'], $write['resourceId'], $write['payload']);
        ```

- [ ] **#LIFE-6** · P2 — `InstagramAutoSync` booking XOR check has the same non-atomic race as `GoogleBusinessAutoSync::seedBooking`
    - **Where:** app/Services/Platforms/InstagramAutoSync.php:137-151
    - **Affects:** Same invariant as #LIFE-5 — a Google Business auto-sync racing an Instagram bio-link auto-sync (both can run around the same "connect a platform" moment) can each install a different booking provider.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Apply the identical fix as #LIFE-5 — same lock key (`"booking-seed:{$userId}"`) so a concurrent Google Business seed and Instagram seed serialize against each other, not just against themselves.
    - **Technical:** Same root cause and pattern as #LIFE-5 — `IntegrationConnection::query()->whereIn('platform', self::BOOKING_PLATFORMS)->where('platform', '!=', $platform)->first()` is a plain SELECT with no lock, followed by a separate `write()` call. Tiered identically to #LIFE-5 per "same root cause, same tier" — fixing both together with one shared lock key closes the gap for both sync sources at once.
    - **Plain English:** Same problem as #LIFE-5, but the two competing processes here are the Instagram bio-link scan and the Google Business sync — either can win the race and clobber the other's booking choice.
    - **Evidence:**
        ```php
        $conflictingBooking = IntegrationConnection::query()
            ->where('user_id', $userId)
            ->whereIn('platform', self::BOOKING_PLATFORMS)
            ->where('platform', '!=', $platform)
            ->first();

        if ($conflictingBooking !== null) {
        ```

- [ ] **#LIFE-7** · P2 — `IdentitySync::applySector` reads-then-writes the user's sector with no row lock
    - **Where:** app/Services/Platforms/IdentitySync.php:140-148
    - **Affects:** Business Partna users with Google Business connected — a scheduled Google Business refresh (dispatched hourly by `integrations:refresh`, confirmed in `routes/console.php:93-98`) landing in the same instant as a user manually picking their sector via `SectorController` can silently revert the manual pick.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Re-fetch the user under `User::query()->where('id', $user->id)->lockForUpdate()->first()` inside a transaction immediately before the manual/`sector_source` guard check, so a concurrent manual pick that commits between this read and this write is detected rather than silently overwritten.
    - **Technical:** `IntegrationConnectionObserver::syncIdentityFromGoogle` fires `applyFromGooglePayload` (which calls `applySector`) on **every** Google Business connection save where the payload changed — not just the initial connect — including the recurring `integrations:refresh` → `RefreshConnectionJob` cycle. That makes "a scheduled Google resync lands at the same moment the user is editing their sector" a real, recurring scenario rather than a one-off connect-time race, even though the manual-pick guard (`sector_source === 'manual'`) is intended to make manual picks permanent.
    - **Plain English:** A user's chosen business category is supposed to stick once they pick it themselves — Google's automated sync should never overwrite a manual choice. But because the sync and the save aren't coordinated, if a user saves their pick at the exact moment an hourly Google refresh is also running, whichever one finishes writing last wins — occasionally reverting the user's own choice without any error or warning.
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
        ```

- [ ] **#LIFE-8** · P2 — `IdentitySync::applyFromGooglePayload` reads-then-writes the workplace row with no row lock
    - **Where:** app/Services/Platforms/IdentitySync.php:71-95
    - **Affects:** Same recurring-refresh scenario as #LIFE-7, but for the workplace card fields (name, address, phone, website, category, hours) — a concurrent user edit to the same field a Google refresh is also touching can be silently clobbered.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Load the workplace row under `lockForUpdate()` inside a transaction before computing `$candidates`/`$sources`, so the precedence check (`! $overwrite && ! $this->isBlank($workplace->{$field})`) reads a consistent snapshot relative to the write.
    - **Technical:** Same root cause and trigger path as #LIFE-7 (the observer's `wasChanged('payload')` gate fires on every refresh, not just connect) — `Workplace::firstOrNew()` is a plain SELECT with no lock, and the eventual `$workplace->save()` only writes the fields this pass touched, so the risk is specifically a same-field collision: a user editing e.g. `phone` in the dashboard at the same instant a scheduled sync also resolves a new `phone` value from Google.
    - **Plain English:** The business-info card on a user's page (address, phone, hours) can be edited by the user or auto-filled from Google. If a user edits a field at the same moment an automatic Google refresh is also writing that same field, one of the two silently disappears — with no error shown to either side.
    - **Evidence:**
        ```php
        $workplace = Workplace::firstOrNew(['site_id' => (string) $site->id]);
        $sources = is_array($workplace->field_sources) ? $workplace->field_sources : [];
        $stamp = now()->toIso8601String();
        $changed = false;
        ```

- [ ] **#LIFE-9** · P2 — Alias KV entries with 1–59s of remaining TTL are written without Cloudflare's 60s minimum enforced
    - **Where:** app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:211-251
    - **Affects:** Users with a handle alias expiring in under a minute at the moment any KV resync fires — the whole `bulkPut` batch for that user's aliases can be rejected by Cloudflare, temporarily dropping alias-redirect entries for handles that still have plenty of TTL left, not just the near-expiry one.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - After computing `$ttl`, add a floor: `if ($ttl !== null && $ttl > 0 && $ttl < 60) { $ttl = 60; }` (or skip the entry entirely — it will fall out of the alias query on the very next sync anyway since it's about to expire).
    - **Technical:** `CloudflareKvService::put`/`bulkPut` already document the constraint (`"Cloudflare KV enforces a minimum of 60s; callers should pre-clamp."`, `app/Services/Cloudflare/CloudflareKvService.php:36`), but `writeAliasEntries` only guards `$ttl <= 0` — the `1 ≤ ttl ≤ 59` window passes straight into the batch `bulkPut` call. Cloudflare's bulk KV write validates the whole batch before writing, so one invalid TTL entry can fail the entire request for that user's aliases (retried up to 3 times per `HasCloudflareRetryPolicy`, then permanently until the next trigger). Impact is self-limiting — the offending alias will fall out of the `->active()` window within a minute regardless — but a batch failure temporarily blocks *all* of that user's other, still-valid alias redirects too.
    - **Plain English:** Cloudflare requires "at least 60 seconds of life" on any entry it stores. When a user's old web address is down to its last few seconds before it stops working entirely, this code still tries to hand Cloudflare that almost-expired entry — which can make Cloudflare reject the *whole batch* of that user's old addresses, not just the expiring one, until the next automatic retry.
    - **Evidence:**
        ```php
        $ttl = $alias->expires_at
            ? (int) now()->diffInSeconds(Carbon::parse($alias->expires_at), false)
            : null;

        // P3-31: skip already-expired aliases — Cloudflare KV enforces a 60s
        // minimum TTL, so passing a ≤0 TTL would resurrect an expired alias at
        // the edge for up to 60s past its DB expiry. The DB query above already
        // excludes expires_at < now(), but race conditions between query time and
        // this point mean we must guard here too. Aligns with the resolver
        // ->active() scope which also filters expires_at > now().
        if ($ttl !== null && $ttl <= 0) {
            continue;
        }
        ```

- [ ] **#LIFE-10** · P2 — `SyncSubdomainToKvJob`'s `ShouldBeUnique` window can drop a rapid second handle-routing sync while the first is still in flight
    - **Where:** app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:38-71
    - **Affects:** Any user whose routing state changes twice (e.g. two rapid handle corrections, or a handle change immediately followed by a custom-domain change) while the prior sync job for that same `uniqueId()` is still queued or actively processing (including through its up-to-3 Cloudflare-API retries).
    - **Effort:** M (~2–4h, needs care around Horizon lock semantics + a concurrency test)
    - **What to do:**
        - Note before changing anything: Laravel's `ShouldBeUnique` lock is released as soon as the job **finishes processing** (success or failure), not held for the full 45s regardless — `$uniqueFor` is a safety ceiling for a crashed/lost job, not the normal-case drop window. The drop only happens if a second dispatch lands *while the first job is still actively queued or running* (including its Cloudflare-retry backoff, up to ~100s under `HasCloudflareRetryPolicy`'s `[10, 30, 60]`).
        - Consider switching to `ShouldBeUniqueUntilProcessing` instead of `ShouldBeUnique`, which releases the lock the instant a worker picks the job up rather than after it finishes — this shrinks the drop window to just queue-wait time. This is safe here specifically because `handle()` always re-reads current user/site/alias state fresh at execution time rather than trusting dispatch-time data, so two overlapping executions converge to the same correct result rather than tearing state.
        - Preserve the existing weekly backstop (`partna:backfill-subdomain-kv --all --queue`, scheduled Sunday 04:00 UTC in `routes/console.php:187-192`) regardless of which fix is chosen — it already re-syncs every professional's KV state unconditionally and is the safety net that keeps this at P2 rather than P1.
    - **Technical:** The class doc comment (`"ShouldBeUnique with a 45s window collapses observer storms to a single KV write per 45s"`) documents the coalescing as deliberate, and for the common case (fast queue, job completes in well under a second) the actual drop window is negligible — the lock releases on completion, not after a flat 45s. The real exposure is narrower than DeepSeek's original framing but still real: if the first job is genuinely still processing (backed up queue, or retrying against a slow/erroring Cloudflare API) when a second routing-relevant change commits, that second dispatch is silently skipped and the job that eventually runs may have already read stale state before the change. Given the existing weekly `backfill-subdomain-kv` reconcile sweep, any such drift self-heals within 7 days rather than persisting indefinitely.
    - **Plain English:** When a user's routing info changes (say, they fix a handle typo right after setting it), the system queues an update to Cloudflare's routing table. If an update for that same user is already in progress — usually a fraction of a second, but potentially longer if Cloudflare itself is being slow — the second update can get silently skipped. There's already a weekly safety sweep that re-checks every user's routing and fixes any drift, so this isn't an "invisible forever" problem, but tightening the window means fewer users ever see a stale routing entry in the meantime.
    - **Evidence:**
        ```php
        // `ShouldBeUnique` with a 45s window collapses observer storms to a single KV write per 45s.
        class SyncSubdomainToKvJob implements ShouldBeUnique, ShouldQueue
        {
            use Dispatchable, HasCloudflareRetryPolicy, InteractsWithQueue, Queueable, SerializesModels;

            public int $timeout = 30;

            public int $uniqueFor = 45;
        ```

## P3 — Nice to have

- [ ] **#LIFE-11** · P3 — `InstagramConnectJob::markFailed` increments `consecutive_failures` via read-then-write instead of an atomic increment
    - **Where:** app/Jobs/Platforms/InstagramConnectJob.php:434-441
    - **Affects:** The `consecutive_failures` counter on an Instagram integration connection — cosmetic drift only; the job is already serialized per-connection by its own `ShouldBeUnique`/`uniqueId()` (`"{$this->connectionId}:{$this->username}"`), so a genuine concurrent write to the same connection's counter is already very unlikely in practice.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Replace the manual `(int) $connection->consecutive_failures + 1` computation with `$connection->increment('consecutive_failures')` (paired with a separate `forceFill([...])->saveQuietly()` for the other two fields, or a single `update()` call), which issues an atomic `UPDATE ... SET consecutive_failures = consecutive_failures + 1` at the DB level.
    - **Technical:** This is a textbook read-modify-write gap, but the job's own `ShouldBeUnique` already serializes execution per connection, so the practical exposure is near-zero — the fix is worth doing for correctness hygiene and because it's a one-line change, not because a real incident is likely from this alone.
    - **Plain English:** This counter tracks how many times in a row an Instagram sync has failed for one connection. The way it's incremented today reads the number, adds one, and writes it back — which could theoretically undercount if two updates happened at the exact same instant. In practice the system already prevents that overlap elsewhere, so this is a low-risk cleanup rather than an active bug.
    - **Evidence:**
        ```php
        private function markFailed(IntegrationConnection $connection, string $error): void
        {
            $connection->forceFill([
                'last_refresh_status' => 'unavailable',
                'last_refresh_error' => $error,
                'consecutive_failures' => (int) $connection->consecutive_failures + 1,
            ])->saveQuietly();
        }
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Log-with-context sweep (account/site lifecycle):** #LIFE-2, #LIFE-3, #LIFE-4
    - **Why grouped:** Same root-cause pattern (missing `user_id`/discriminator in a lifecycle log's catch block) across two files; all S-effort, no auth/money/schema involvement.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Platform auto-sync race hardening:** #LIFE-5, #LIFE-6, #LIFE-7, #LIFE-8, #LIFE-11
    - **Why grouped:** All live in `app/Services/Platforms/` + `app/Jobs/Platforms/`, all stem from unlocked read-modify-write races in the Google Business / Instagram auto-sync pipeline, and #LIFE-5/#LIFE-6 share one fix (same lock key) by design.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 3 — Cloudflare KV sync job hardening:** #LIFE-9, #LIFE-10
    - **Why grouped:** Same file (`SyncSubdomainToKvJob.php`), same subsystem (subdomain routing sync), both touch the alias/TTL and uniqueness logic that a single reviewer should reason about together.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#LIFE-1 — Signup email-conflict string-matching:** standalone — sits on the primary signup/account-creation path; a regression here can break new-user onboarding platform-wide, and the recommended fix (nested transaction + Postgres-gated test) touches transaction semantics that warrant isolated review rather than being bundled with unrelated work.
