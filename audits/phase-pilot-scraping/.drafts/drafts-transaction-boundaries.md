- [ ] **TXN-1** · P2 — IntegrationConnectionObserver dispatches CloudflareCachePurgeJob via bare `dispatch()`; relies on `$afterCommit` property alone with no explicit `DB::afterCommit` wrapper
    - **Where:** app/Observers/Core/IntegrationConnectionObserver.php:49
    - **Affects:** Sitepage visitors who may see stale content if Redis write fails between DB commit and queue delivery. Non-critical — edge cache TTL provides a safety net.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the dispatch in an explicit `DB::afterCommit(fn() => CloudflareCachePurgeJob::dispatch($subdomain))` inside the `purge()` method so the intent is auditable in code regardless of observer property configuration.
        - Alternatively, confirm the observer's `$afterCommit = true` is project-standard and document it as the sole gating mechanism.
    - **Technical:** The observer declares `public bool $afterCommit = true`, which causes Laravel to defer `saved`/`deleted`/`restored` callbacks until after the database transaction commits. When the model is saved via `updateOrCreate()` (which internally wraps in a transaction), the callback fires post-commit, and the dispatch is safe. However, this relies entirely on a property that is set on the class, not on the call-site. A future refactor that removes `$afterCommit = true` or changes the save path to an auto-committed single-statement update would silently regress to dispatching inside the transaction. The gold standard prefers explicit `DB::afterCommit(fn() => ...)` at each dispatch site so the invariant is locally visible. Current behaviour is correct but fragile.
    - **Plain English:** Imagine you hand a sealed envelope to a courier after the bank processes your payment — that's the correct order. Right now, the system depends on a single "wait until done" switch on the observer class to guarantee that ordering. If someone accidentally turns off that switch during future work, the courier leaves before the payment clears. Wrapping the dispatch in an explicit "only run after commit" call makes the guarantee visible right at the point of dispatch, so nobody can break it by accident.
    - **Evidence:**
        ```php
        // IntegrationConnectionObserver.php
        public bool $afterCommit = true;

        public function saved(IntegrationConnection $connection): void
        {
            $this->purge($connection);
        }

        private function purge(IntegrationConnection $connection): void
        {
            // ...
            if ($subdomain) {
                CloudflareCachePurgeJob::dispatch($subdomain);
            }
        }
        ```
    - `[DRAFT, confidence: 0.7]`

- [ ] **TXN-2** · P3 — InstagramController::guardApifyBudget increments daily rate-limit counter before scrape attempt; failed scrapes consume user quota
    - **Where:** app/Http/Controllers/Api/Platforms/InstagramController.php:194-209 (guardApifyBudget called from connect at line ~87, before scraper call)
    - **Affects:** Instagram users who hit a scraper failure (private profile, Apify error) — they lose a daily quota slot with no saved data, and must wait for the cooldown to retry.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move the daily counter increment (`Cache::put($dayKey, $count + 1, ...)`) to after a successful `$this->writeConnection()` call in `connect()`, so the counter only advances when the scrape actually produced stored data.
        - Keep the cooldown check (`Cache::add`) before the scrape to prevent rapid-fire retries, but only commit the daily increment on success.
    - **Technical:** The `guardApifyBudget` method uses `Cache::add` (atomic) for the per-user cooldown and a read-modify-write `Cache::get` + `Cache::put` for the global daily counter. The daily counter is incremented before `$this->scraper->fetchProfile($username)` executes. If the Apify actor returns null (private profile, API error, timeout), the controller returns a 502 error but the daily counter was already advanced. This is not a transaction-boundary issue (no DB transaction wraps the cache and scraper call), but the cache write and the scrape outcome have a logical dependency: quota should only be consumed when the scrape succeeds. The code's own comment acknowledges this as "good enough for a pilot."
    - **Plain English:** You buy a concert ticket, but the ticket printer jams. You still paid, and the venue counts you toward their daily capacity even though you didn't get a ticket. The fix is to only count the purchase after the ticket prints successfully — move the counter increment to after the scrape succeeds.
    - **Evidence:**
        ```php
        // InstagramController.php — connect() calls guardApifyBudget then scraper
        if ($budgetError = $this->guardApifyBudget($user)) {
            return $budgetError;
        }

        $profile = $this->scraper->fetchProfile($username);
        if (! $profile) {
            return $this->error('Could not fetch that Instagram profile...', 502);
        }
        // ... mirror + writeConnection happen here on success

        // guardApifyBudget increments the daily counter BEFORE the scrape:
        $count = (int) Cache::get($dayKey, 0);
        if ($count >= self::APIFY_DAILY_CAP) {
            return $this->error('Instagram is busy...', 429);
        }
        Cache::put($dayKey, $count + 1, now()->addDay());
        ```
    - `[DRAFT, confidence: 0.8]`
