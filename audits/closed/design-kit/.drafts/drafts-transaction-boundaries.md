- [ ] **#TXN-1** · P1 — Double-send window: idempotency lock released before state mutation in SendEnquiryConfirmationJob
    - **Where:** app/Jobs/Notifications/SendEnquiryConfirmationJob.php:56-68 (transaction) and :106 (saveQuietly)
    - **Affects:** Visitors who submit contact forms — may receive duplicate confirmation emails when a job retries or two workers race.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move `$enquiry->forceFill(['confirmation_sent_at' => now()])->saveQuietly()` INSIDE the `DB::transaction` closure, immediately after the `confirmation_sent_at !== null` check, before the `return $e`.
        - Keep `Mail::to(...)->send(...)` OUTSIDE the transaction (it already is — just reorder the write to precede it).
    - **Technical:** The `DB::transaction` acquires `lockForUpdate`, reads `confirmation_sent_at`, and releases the lock before the column is written. Between lock release and the subsequent `saveQuietly()`, a concurrent worker can acquire the lock, read `confirmation_sent_at = null` (the first worker hasn't written it yet), and also proceed to send mail. This is a classic TOCTOU gap — the lock protects the check but not the mutation that proves idempotency. The fix is to set the flag atomically inside the lock, then send mail afterward. If mail fails, the job won't retry (flag is already set), which is the correct idempotent behaviour.
    - **Plain English:** Imagine two clerks checking a shared "sent?" checkbox. Clerk A looks, sees it's empty, walks away from the desk to mail the letter, then comes back to tick the box. Clerk B walks up while A is at the post office, sees the box is still empty, and mails a second copy. The fix: tick the box BEFORE leaving the desk, then mail the letter.
    - **Evidence:**
        ```php
        // Lines 56-68: lock + read inside transaction
        $enquiry = DB::transaction(function () {
            $e = Enquiry::query()->lockForUpdate()->find($this->enquiryId);
            if ($e === null) {
                return null;
            }
            if ($e->confirmation_sent_at !== null) {
                return false;
            }

            return $e;  // ← lock released here, column still null
        });
        ```
        ```php
        // Line 106: state mutation outside the lock
        $enquiry->forceFill(['confirmation_sent_at' => now()])->saveQuietly();
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#TXN-2** · P1 — Double-send window: idempotency lock released before state mutation in SendSubscriptionConfirmationJob
    - **Where:** app/Jobs/Notifications/SendSubscriptionConfirmationJob.php:56-68 (transaction) and :124 (saveQuietly)
    - **Affects:** Visitors who subscribe to newsletters — may receive duplicate confirmation emails under concurrent job execution or retries.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move `$sub->forceFill(['confirmation_sent_at' => now()])->saveQuietly()` INSIDE the `DB::transaction` closure, immediately after the `confirmation_sent_at !== null` idempotency check, before the `return $s`.
        - Keep `Mail::to(...)->send(...)` OUTSIDE the transaction.
    - **Technical:** Identical TOCTOU pattern as TXN-1. The `lockForUpdate` + `DB::transaction` guards the read of `confirmation_sent_at` but the column is written outside the lock after `Mail::send()` completes. Between lock release and the trailing `saveQuietly()`, a second worker can acquire the lock, see the still-null flag, and also send a confirmation. Fix: atomically set the flag inside the lock, then attempt the external send.
    - **Plain English:** Same office, different form. Clerk checks the "confirmation sent?" box — it's empty — then walks to the mailroom. While they're gone, a second clerk checks the same box, still empty, and also heads to the mailroom. The recipient gets two copies. The fix: stamp the box before leaving your chair.
    - **Evidence:**
        ```php
        // Lines 56-68: lock + read inside transaction
        $sub = DB::transaction(function () {
            $s = EmailSubscription::query()->lockForUpdate()->find($this->subscriptionId);
            if ($s === null) {
                return null;
            }
            if ($s->confirmation_sent_at !== null) {
                return false;
            }

            return $s;  // ← lock released, flag still null
        });
        ```
        ```php
        // Line 124: state mutation outside the lock
        $sub->forceFill(['confirmation_sent_at' => now()])->saveQuietly();
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#TXN-3** · P1 — Cache invalidation runs inside DB::transaction via SiteObserver::saved()
    - **Where:** app/Services/Site/UpdateSiteAction.php:48-191 (DB::transaction wrapping $site->save()) → app/Observers/SiteObserver.php (inferred from UserSiteController.php:69-74 commentary) → app/Services/Cache/SiteCacheService.php:invalidateSite() calling Cache::deleteMultiple()
    - **Affects:** Any site mutation via `UpdateSiteAction::execute()` — subdomain change, publish toggle, settings edit. If the transaction rolls back after `SiteObserver::saved()` has already flushed the cache, readers see cached emptiness (or stale last-good data) for up to the TTL even though the DB holds the correct pre-rollback state.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - In `SiteObserver::saved()`, wrap the `invalidateSite()` / `invalidateSitePayload()` call in `DB::afterCommit(fn() => $this->cacheService->invalidateSite($site))`.
        - Alternatively, remove the observer-driven invalidation and call `invalidateSite()` explicitly in `UpdateSiteAction::execute()` AFTER the `DB::transaction` returns (the controller already does a post-execute bust for design-kit flows — extend that pattern).
    - **Technical:** `UpdateSiteAction::execute()` wraps `$site->save()` inside `DB::transaction(...)`. Eloquent fires the `saved` event at commit time (`finishSave()`), which triggers `SiteObserver::saved()`. The observer calls `SiteCacheService::invalidateSite()` which issues `Cache::deleteMultiple()` — a Redis write. If the transaction subsequently fails (e.g. a unique-constraint violation after the save, or an exception from `$professional->save()`), the cache has already been purged but the DB reverted. The `saved` event in Laravel fires unconditionally regardless of transaction outcome when inside a transaction — only the outer commit/rollback determines DB persistence, but the observer's side effects already escaped. `DB::afterCommit` defers the callback until the transaction actually commits.
    - **Plain English:** When updating a site, the system clears the cache at "save" time, then finishes all its other DB work, then decides whether to keep or undo everything. If it undoes, the cache is already empty — like erasing a whiteboard before you're sure you want to. The next person who looks sees a blank board and assumes no info exists, even though the database still holds the original info. The fix: only erase the whiteboard after you've confirmed the changes are final.
    - **Evidence:**
        ```php
        // UpdateSiteAction.php:48-51 — the transaction that wraps $site->save()
        return DB::transaction(function () use ($professional, $site, $data, $options, $allowForcePublish, $forcePublish, $allowSubdomainOverride): Site {
            // ... ~140 lines of subdomain logic, settings merge, publish checks ...
            $site->fill($data);
            try {
                $site->save();  // ← fires SiteObserver::saved() INSIDE transaction
            } catch (QueryException $e) { ... }
            return $site->fresh();
        });
        ```
        ```php
        // UserSiteController.php:69-74 — confirms SiteObserver calls invalidateSite
        // execute() already fired invalidateSite via $site->save(), but that
        // ran BEFORE the raw design_kits write above — bust again so the new
        // kit (and the email-brand bundle that reads it) is reflected.
        app(SiteCacheService::class)->invalidateSite($site);
        ```
        ```php
        // SiteCacheService.php — invalidateSite writes to Redis
        public function invalidateSite(Site $site): void
        {
            $this->invalidateSitePayload($site);
            $this->invalidateSiteImages($site);
        }
        // invalidateSitePayload() calls:
        Cache::deleteMultiple(array_values(array_unique($keys)));
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#TXN-4** · P2 — Oversized transaction in UpdateSiteAction::execute() mixes reads, computation, and multi-model writes
    - **Where:** app/Services/Site/UpdateSiteAction.php:48-191
    - **Affects:** Site update latency under concurrent subdomain changes; holds a Postgres connection open for the duration of settings-merge computation + multiple model saves.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Hoist the `settings` array merge (`array_replace_recursive`) and the `is_published` + display-name validation out of the transaction — both are pure computation on in-memory values.
        - Consider whether `HandleChangeLog::create()` (the audit log) should persist outside the transaction or use a separate connection so a transaction rollback on the site row doesn't also wipe the audit trail.
    - **Technical:** The transaction wraps ~140 lines including subdomain conflict reads, alias creation (2 models), handle alias creation, professional handle update, alias cleanup, audit log creation, settings computation, publish validation, the site fill, and the site save. The settings merge and publish check are pure PHP — no DB interaction — and could run before `DB::transaction(...)` without affecting correctness. The closure also triggers `SiteObserver` and `UserObserver` (see TXN-3), each of which may have their own side effects that extend the lock-and-connection window. Narrower scope reduces lock contention and makes the atomic unit visually obvious.
    - **Plain English:** This is like locking the entire filing cabinet while you rearrange papers, check your calendar, sharpen a pencil, and then finally change one form. Other clerks wait for the whole routine even though the pencil-sharpening step doesn't need the cabinet locked. Moving the non-filing work outside the locked period means the cabinet is free sooner and everyone moves faster.
    - **Evidence:**
        ```php
        // Line 48: transaction opens
        return DB::transaction(function () use ($professional, $site, $data, ...): Site {

            // Lines 52-108: subdomain conflict reads + alias/handle manipulation
            if (array_key_exists('subdomain', $data)) { ... }

            // Lines 135-148: pure PHP array merge (no DB)
            if (array_key_exists('settings', $data)) {
                $existing = is_array($site->settings) ? $site->settings : [];
                $incoming = is_array($data['settings']) ? $data['settings'] : [];
                // ...
                $merged = array_replace_recursive($existing, $incoming);
                $data['settings'] = $merged;
            }

            // Lines 150-162: pure PHP validation (no DB)
            if (($data['is_published'] ?? null) === true) {
                if (! $canBypass) {
                    if (empty($professional->display_name)) { ... }
                }
            }

            // Line 185-190: site save + fresh
            $site->fill($data);
            $site->save();
            return $site->fresh();

        }); // Line 191: transaction closes
        ```
    - `[DRAFT, confidence: 0.7]`
