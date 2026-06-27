Now I have all the evidence I need. Let me produce the final audit.

# Data Integrity & Concurrency Audit — 2026-05-25

**Branch:** development
**Lens:** missing transactions, race conditions, soft-delete consistency, FK/unique constraint gaps, N+1 writes
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `app/Services/Professional/AccountDeletionService.php`
- `app/Services/Professional/ProfessionalBootstrapService.php`
- `app/Services/Auth/TokenRevocationService.php`
- `app/Services/FeatureFlags/FeatureFlagService.php`
- `app/Services/Media/MediaUploadService.php`
- `app/Services/Site/SiteCacheService.php`
- `app/Services/Notifications/NotificationPublisher.php`
- `supabase/migrations/20260526000000_baseline_standalone_user.sql`

## Progress

- P0 Blockers: 0 of 1 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 1 complete

---

## P0 — Must fix before any real user touches the system

- [ ] **#DATA-1** · P0 — R2 artifact cleanup runs after DB cascade has already deleted the source rows
    - **Where:** `app/Services/Professional/AccountDeletionService.php:472–609` (`purge()` + `purgeMediaArtifacts()`)
    - **Affects:** Every account that reaches the end of its 30-day grace period and is purged by the daily `PurgeSoftDeleted` command. All R2-stored files (images, videos, documents) are silently orphaned on every purge run. `purge()` returns `true` and logs success — there is no indication in logs or audit trail that cleanup was skipped.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Move `$this->purgeMediaArtifacts($professional)` to execute **before** `$this->deleteSupabaseAuthUser($authUserId)` in `purge()`. The media rows must be queried and cleanup dispatched while they still exist.
        - Update the Step 1/2 comments to document the correct ordering rationale: the Supabase Admin API deletion fires the `auth.users → core.users → site.sites → site.site_media` cascade chain, so media must be collected before Step 1.
        - Add a regression test asserting that `DeleteMediaArtifactsJob` (and/or `ImageVariantService::deleteVariants`) is called with the correct media IDs during a full `purge()` run.
        - Accept that if `purgeMediaArtifacts()` partially fails and `deleteSupabaseAuthUser()` subsequently fails, the next daily retry will attempt R2 cleanup again and find the rows still present — this is safe (idempotent cleanup) and was already the intent.
    - **Technical:** `deleteSupabaseAuthUser()` calls the Supabase Admin API (`DELETE /auth/v1/admin/users/{id}`), which removes the `auth.users` row. PostgreSQL immediately fires three chained ON DELETE CASCADE constraints: `users_auth_user_id_fkey` (→ `core.users`), `sites_professional_fk` (→ `site.sites`), `site_media_site_fk` (→ `site.site_media`). All three are confirmed in the baseline migration (lines 337, 703, 791). By the time control returns from `deleteSupabaseAuthUser()` and `purgeMediaArtifacts()` is called in Step 2, every `site.sites` and `site.site_media` row for this professional is already gone. `purgeMediaArtifacts()` queries `Site::query()->where('professional_id', ...)` — which returns null — and returns early without touching R2. The developer's inline comment ("forceDelete() cascades to site_media") reflects a mistaken mental model: the cascade the comment refers to is actually triggered by Step 1, not Step 4. `forceDelete()` in Step 4 silently no-ops on an already-deleted row (0 rows affected, no exception), so the audit entry writes and `purge()` returns `true` — the failure is completely invisible.
    - **Plain English:** When a user's account is permanently deleted after their 30-day cancellation window, the app first removes their login access from the authentication system. Unknown to the code, that single action triggers a chain reaction inside the database that automatically wipes their entire profile — including every record of what photos and videos they uploaded. Then, the app tries to go delete those actual files from cloud storage. But the records it needs to find them have already been erased by the chain reaction. The app finds nothing, quietly gives up, and reports success. Every deleted user permanently leaves behind orphaned files that consume storage and can never be recovered. The fix is simple: collect the file list and kick off their deletion *before* touching the authentication system.
    - **Evidence:**
        ```php
        // Step 1: delete Supabase auth user. If this fails, do NOT hard-delete
        // the DB row — we'd end up with an orphaned auth user and no way to retry.
        if ($authUserId !== '' && ! $this->deleteSupabaseAuthUser($authUserId)) {
            $this->logAuditEvent(
                $professional,
                ProfessionalDeletionAuditEntry::EVENT_PURGE_FAILED,
                null,
                ['reason' => 'supabase_deletion_failed'],
                ProfessionalDeletionAuditEntry::ACTOR_TYPE_SYSTEM,
            );

            return false;
        }

        // Step 2: clean up R2 artifacts before the DB cascade deletes the rows.
        // forceDelete() cascades to site_media, but DB cascades do not touch R2 storage.
        $this->purgeMediaArtifacts($professional);
        ```
        ```php
        private function purgeMediaArtifacts(User $professional): void
        {
            $site = Site::query()->where('professional_id', $professional->id)->first();

            if (! $site) {
                return;  // ← always hit; site.sites was cascade-deleted in Step 1
            }

            $mediaItems = SiteMedia::query()
                ->withTrashed()
                ->where('site_id', $site->id)
                ->get();
        ```
        Cascade chain confirmed in migration (lines 337, 703, 791):
        ```sql
        CONSTRAINT users_auth_user_id_fkey FOREIGN KEY (auth_user_id) REFERENCES auth.users(id) ON DELETE CASCADE,
        CONSTRAINT sites_professional_fk FOREIGN KEY (professional_id) REFERENCES core.users(id) ON DELETE CASCADE,
        CONSTRAINT site_media_site_fk FOREIGN KEY (site_id) REFERENCES site.sites(id) ON DELETE CASCADE
        ```

---

## P2 — Should fix

- [ ] **#DATA-2** · P2 — Customer unique indexes are not soft-delete-aware; constraint violations during 30-day retention window
    - **Where:** `supabase/migrations/20260526000000_baseline_standalone_user.sql:404–405`
    - **Affects:** Any professional who soft-deletes a customer and then re-adds them (same email or phone) within the 30-day retention window — produces an unhandled 23505 unique constraint violation → 500.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In a new Supabase migration, drop and recreate both indexes with `AND deleted_at IS NULL` appended to the `WHERE` clause:
            ```sql
            DROP INDEX core.customers_professional_email_unique;
            CREATE UNIQUE INDEX customers_professional_email_unique
                ON core.customers (professional_id, lower(email))
                WHERE (email IS NOT NULL AND deleted_at IS NULL);

            DROP INDEX core.customers_professional_phone_unique;
            CREATE UNIQUE INDEX customers_professional_phone_unique
                ON core.customers (professional_id, phone)
                WHERE (phone IS NOT NULL AND deleted_at IS NULL);
            ```
        - Add a test asserting that a professional can create a customer, soft-delete them, and immediately create another customer with the same email without an error.
    - **Technical:** PostgreSQL partial indexes respect only the `WHERE` predicate — the existing `WHERE (email IS NOT NULL)` filters null emails but leaves soft-deleted rows fully visible to the index. During the 30-day retention window, a soft-deleted `(professional_id, lower(email))` tuple still occupies a slot, causing a 23505 on re-insert. The pattern `WHERE (col IS NOT NULL AND deleted_at IS NULL)` is the standard idiom for soft-delete-aware unique constraints in this codebase; the `users_email_unique` index on `core.users` (line 347 of the same migration) already uses it correctly. Customer indexes missed it.
    - **Plain English:** The system lets professionals "soft-delete" customers — they disappear from the UI but are kept privately for 30 days in case it was a mistake. But the database's duplicate-checking rules don't know about soft-deletes: they see a hidden customer as still "taking up" their email address. So if a professional deletes a customer and tries to add them back the same week, they get a confusing server crash instead of a smooth re-add. The fix is a one-line update to the database's duplicate-checking rules that says "ignore deleted customers when deciding if an email is already taken."
    - **Evidence:**
        ```sql
        CREATE UNIQUE INDEX customers_professional_email_unique ON core.customers (professional_id, lower(email)) WHERE (email IS NOT NULL);
        CREATE UNIQUE INDEX customers_professional_phone_unique ON core.customers (professional_id, phone) WHERE (phone IS NOT NULL);
        ```
        Compare the correct pattern already used for `core.users`:
        ```sql
        CREATE UNIQUE INDEX users_email_unique ON core.users (primary_email) WHERE (deleted_at IS NULL);
        ```

- [ ] **#DATA-3** · P2 — Non-atomic Redis three-step in `trackForUser` creates permanent no-TTL hash entries on process crash
    - **Where:** `app/Services/Auth/TokenRevocationService.php:83–97`
    - **Affects:** Active Sessions UI — a process crash (OOM kill, PHP-FPM timeout, `SIGKILL`) between `hsetnx` and `expire` leaves a Redis hash key with no TTL. Subsequent calls for the same session find `_init=1` and skip the write, permanently locking the hash in an incomplete state. `listSessionsForUser()` returns the session as "active" with `created_at=0` and empty metadata fields, and since there is no corresponding `auth:revoked-session:*` key the phantom entry cannot be cleared by normal logout. Auth enforcement is unaffected — the revocation blocklist uses a completely separate key family.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the three sequential commands with a single Lua script to make the entire write atomic:
            ```php
            $flatArgs = array_merge(
                [(string) self::MAX_LIFETIME_SECONDS],
                array_values([
                    'user_id'        => $userId,
                    'created_at'     => (string) time(),
                    'ip_prefix'      => $this->truncateIp((string) ($metadata['ip'] ?? '')),
                    'browser_family' => $this->parseUaBrowserFamily((string) ($metadata['user_agent'] ?? '')),
                    'platform'       => $this->parseUaPlatform((string) ($metadata['user_agent'] ?? '')),
                ])
            );

            $script = <<<'LUA'
            if redis.call('HSETNX', KEYS[1], '_init', '1') == 1 then
                redis.call('HMSET', KEYS[1],
                    'user_id', ARGV[2], 'created_at', ARGV[3],
                    'ip_prefix', ARGV[4], 'browser_family', ARGV[5], 'platform', ARGV[6])
                redis.call('EXPIRE', KEYS[1], ARGV[1])
                return 1
            end
            return 0
            LUA;

            Redis::eval($script, 1, $metaKey, ...$flatArgs);
            ```
        - The Lua script runs as a single atomic unit in Redis — if `HSETNX` returns 0, the rest of the script is a no-op; if it returns 1, `HMSET` and `EXPIRE` are guaranteed to complete together.
    - **Technical:** Three sequential Redis commands — `HSETNX`, `HMSET`, `EXPIRE` — execute as separate round-trips. If the PHP process is killed between any two, the hash is left in a partially-written or TTL-less state. The most damaging case is a crash between `HMSET` and `EXPIRE`: all metadata fields are written correctly but the hash has no expiry, so it persists in Redis indefinitely. The `_init=1` sentinel prevents any future request from correcting it. `listSessionsForUser()` reads via `hgetall` and returns any non-revoked session in the tracking set, so this phantom session appears in the UI for the account's lifetime. Redis Lua scripts run atomically (Redis is single-threaded for script execution), eliminating all three gaps.
    - **Plain English:** When you log in, the server writes a small record in its fast cache to remember which device you used. It does this in three separate steps. If the server process is forcefully killed — which happens on deploy restarts, memory pressure, or timeouts — right between any two of those steps, the record gets stuck with no expiry date. The stuck record makes your login look permanently active in the "Active Sessions" screen and can't be cleared by logging out. There's no way for anyone else to exploit this (your actual login security uses a separate system), but your session list becomes cluttered with ghost entries. Using a single atomic instruction instead of three separate ones closes the gap.
    - **Evidence:**
        ```php
        $metaKey = self::SESSION_META_PREFIX.$sessionId;
        $won = (bool) Redis::hsetnx($metaKey, '_init', '1');

        if ($won) {
            // SEC-3: store transformed values instead of raw IP/UA to limit
            // PII exposure in Redis logs and monitoring tools. Truncation is
            // intentional — preserves "same network neighbourhood" signal
            // without pinpointing the exact device address.
            Redis::hmset($metaKey, [
                'user_id'      => $userId,
                'created_at'   => (string) time(),
                'ip_prefix'    => $this->truncateIp((string) ($metadata['ip'] ?? '')),
                'browser_family' => $this->parseUaBrowserFamily((string) ($metadata['user_agent'] ?? '')),
                'platform'     => $this->parseUaPlatform((string) ($metadata['user_agent'] ?? '')),
            ]);
            Redis::expire($metaKey, self::MAX_LIFETIME_SECONDS);
        }
        ```

- [ ] **#DATA-4** · P2 — Concurrent bootstrap with same email bypasses the guard and surfaces an unhandled 23505 as a 500
    - **Where:** `app/Services/Professional/ProfessionalBootstrapService.php:106–121` (`guardAgainstEmailReuseByDifferentAuthUser()`)
    - **Affects:** New user signup — two simultaneous requests for the same email both pass the guard's `exists()` check, one succeeds, the other hits the `users_email_unique` partial index and receives a 500 (unhandled `UniqueConstraintViolationException`) instead of a 422 with `EMAIL_ALREADY_REGISTERED`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the `DB::transaction()` call in the caller with a catch for `Illuminate\Database\UniqueConstraintViolationException` and re-throw as the expected sentinel:
            ```php
            try {
                return DB::transaction(function () use ($uid, $data, $existing) {
                    // ... existing body unchanged
                });
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                throw new \RuntimeException('EMAIL_ALREADY_REGISTERED');
            }
            ```
        - Verify the controller / caller that catches `RuntimeException('EMAIL_ALREADY_REGISTERED')` already maps it to a 422 — confirm this path is exercised in the existing feature tests.
        - The guard (`exists()` check) remains valuable for the sequential case and provides a friendlier early-exit path; the catch is the safety net for the concurrent race.
    - **Technical:** `guardAgainstEmailReuseByDifferentAuthUser()` issues a non-locking `exists()` read inside a `READ COMMITTED` transaction. Two concurrent requests for the same email both snapshot the email as "not taken," both pass the guard, and both proceed to insert. The second insert collides with the `users_email_unique` partial index (`WHERE deleted_at IS NULL`, line 347 of the migration) and throws a 23505 PostgreSQL error. Laravel 10+ wraps this as `Illuminate\Database\UniqueConstraintViolationException`, a subclass of `QueryException`. Nothing in the current call stack catches it, so it propagates to the global exception handler as a 500. The unique index is the correct safety net; the fix only translates its error into the expected application-level response.
    - **Plain English:** When someone signs up, the app checks whether their email is already taken before writing anything to the database. But if two sign-up attempts arrive at exactly the same millisecond — both from the same email — both checks happen before either one writes, so both say "email is available." One account gets created; the other crashes with an unhelpful server error (500) instead of a clear "this email is already registered" message. The database's built-in duplicate check catches the collision correctly, but the code doesn't translate that database crash into a friendly response. The fix is a three-line catch block that converts the database error into the right message.
    - **Evidence:**
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
        Safety-net index (migration line 347):
        ```sql
        CREATE UNIQUE INDEX users_email_unique ON core.users (primary_email) WHERE (deleted_at IS NULL);
        ```

---

## P3 — Nice to have

- [ ] **#DATA-5** · P3 — Feature flag cache is busted after the DB write; a request in the millisecond window reads a stale value
    - **Where:** `app/Services/FeatureFlags/FeatureFlagService.php:115–125` (`setOverride()`)
    - **Affects:** Feature flag reads — a request arriving in the window between `FeatureFlagOverride::updateOrCreate()` committing and `forgetPro()` evicting the Redis key sees the old cached flag value. Window is bounded by two Redis round-trips (~1ms). Staff-controlled path only.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Invert the ordering: flush the cache key first, then write to DB. Any reader that lands in the gap between flush and write forces a cache miss and rebuilds from DB, where it either reads the old value (if the write hasn't committed yet) or the new value (if it has) — both are coherent states. With the current ordering, the only incoherent state is "DB says new, cache says old":
            ```php
            // Flush first — any cache miss during the write window rebuilds from DB.
            $this->requestCache = [];
            $this->forgetPro($scope->professionalId);

            FeatureFlagOverride::updateOrCreate(
                ['flag_key' => $key, 'professional_id' => $scope->professionalId],
                $attrs + ['professional_id' => $scope->professionalId],
            );
            ```
        - Note: the existing ~72-minute worst-case SWR stale window on the registry key (`ff:registry`) is documented and accepted — this change does not address registry staleness.
    - **Technical:** Cache-invalidate-after-write is a classic pattern where the brief window between the DB commit and the cache eviction creates a stale-read opportunity. Pre-invalidation (flush before write) converts the window to "readers may see the old DB value" — which is the same value they would have seen from cache anyway — eliminating the incoherent state. Neither ordering eliminates the race without a distributed lock; pre-invalidation is the conventional preference because it minimises the time a stale value is served.
    - **Plain English:** When a staff member updates a feature flag, the system writes the change to the database and *then* clears the old cached copy. For a couple of milliseconds in between, another user's request could read the old value from cache. This is a very minor timing edge case on a staff-only admin action. Clearing the cache first — before writing to the database — removes the window entirely.
    - **Evidence:**
        ```php
        FeatureFlagOverride::updateOrCreate(
            ['flag_key' => $key, 'professional_id' => $scope->professionalId],
            $attrs + ['professional_id' => $scope->professionalId],
        );

        $this->requestCache = [];

        try {
            $this->forgetPro($scope->professionalId);
        ```
