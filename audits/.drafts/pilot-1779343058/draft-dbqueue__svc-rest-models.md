- [ ] **#SCALE-1** · P2 — LiveStatusInjector issues per-block Redis GET on every site render (N+1 Redis round-trips)
    - **Where:** app/Services/Streaming/LiveStatusInjector.php:64-65
    - **Affects:** Every public site page render that passes through `injectIntoPayload()` — including cache-hit renders where the payload was just unpacked. At 200 brands each averaging 3 streaming link blocks, that's ~600 Redis GETs per polling cycle across the fleet; at ~10K daily site visits it's fine today but the linear coupling between block count and Redis calls means a single brand adding 20 streaming blocks multiplies cost for every visitor to every brand's page.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Bulk-read the live status keys with `Redis::mget()` before iterating blocks — one round-trip for all keys instead of one per block.
        - Pre-compute the `LIVE_KEY_PREFIX` list from the already-loaded block settings array so the `mget` call needs no extra query.
    - **Technical:** The `array_map` callback in `injectIntoBlocks` calls `Redis::get()` for every block whose settings carry `live_check_enabled=true`. This is a text-book N+1 against Redis — sub-millisecond per call, but the cumulative latency grows linearly with the number of streaming blocks on the page. Since `LiveStatusInjector` runs *after* `SiteCacheService::getPublicSitePayload()` (the docblock says "never stored in the cache itself"), this penalty is paid even on cache-hit renders. A single `mget` amortises the cost to one network hop.
    - **Plain English:** Every time someone visits a Partna profile page, the server checks if each "Live on Twitch" link is actually live by asking Redis one link at a time. If a profile has 10 streaming links, that's 10 separate questions to Redis instead of asking all 10 in one call. It's like checking each light bulb in your house by walking to the switchboard 10 times instead of once. Redis answers fast, but the extra trips add up as more creators add streaming links.
    - **Evidence:**
        ```php
        $redisKey = self::LIVE_KEY_PREFIX."{$platform}:{$handle}";
        $block['settings']['is_live'] = Redis::get($redisKey) === '1';
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#SCALE-2** · P2 — LiveStatusPoller::filterStaleHandles issues per-handle Redis TTL call (N+1 Redis round-trips)
    - **Where:** app/Services/Streaming/LiveStatusPoller.php:160-167
    - **Affects:** Every polling cycle for every streaming platform. At 200 brands with an average of 2–3 streaming handles each, plus individual affiliates with streaming links, the handle population reaches ~500+. Each poll cycle calls `Redis::ttl()` 500+ times sequentially.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Use Redis pipelining or a Lua script to batch the TTL checks into one round-trip.
        - Accept the handles array, pipeline all TTL calls, then filter on the returned array.
    - **Technical:** The `array_filter` callback calls `Redis::ttl()` once per handle. At 500 handles and a 2-minute poll interval, this is 500 sequential Redis commands every 120 seconds — well within Redis capacity but a wasteful use of PHP-worker wall-clock time. Pipelining collapses this into one network hop and lets Redis answer all TTLs in a single pass. The cold-handle demotion mechanism (tiered TTLs) is the right architecture; the read path just needs batching.
    - **Plain English:** The live-status poller checks whether each streaming handle's Redis data is "stale" before deciding to call Twitch or Kick. It does this by asking Redis "how much time is left on this key?" — one handle at a time. With 500 streamers using Partna, that's 500 tiny questions instead of one "check all 500 please" request. It's the difference between texting each friend individually versus sending one group message.
    - **Evidence:**
        ```php
        return array_values(array_filter($handles, function (string $handle) use ($platform): bool {
            $key = self::LIVE_KEY_PREFIX."{$platform}:{$handle}";
            $ttl = Redis::ttl($key);
            // -2 = key doesn't exist, -1 = no TTL, any value <= threshold = stale
            return $ttl < self::TTL_SKIP_THRESHOLD;
        }));
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SCALE-3** · P2 — SquareApiClient retry loop sleeps inside PHP worker with `usleep`, blocking queue throughput under rate-limit pressure
    - **Where:** app/Services/Square/SquareApiClient.php:153-161
    - **Affects:** Any background job that calls the Square API during a rate-limit event. A single 429 with `Retry-After: 5` sleeps the Horizon worker for 5 seconds per retry, up to 3 retries = 15 seconds of idle worker time. At 200 brands each with periodic catalog syncs, a Square outage or rate-limit burst could occupy every worker in the `default` queue with sleeping processes.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the in-process sleep with a `release($delay)` back to the queue — let the job re-enter the queue after the `Retry-After` window instead of tying up a worker.
        - Apply `$tries` and `$backoff` on the calling job so repeated 429s decay rather than looping forever.
    - **Technical:** The `while (true)` loop with `usleep($wait * 1000)` holds the PHP-FPM or Horizon worker process in a sleeping state. In Horizon, each worker is a dedicated OS process; a sleeping worker is unavailable to process other jobs. Under rate-limit pressure from Square, multiple sync jobs can all sleep simultaneously — this is a classic connection-pool / worker-exhaustion pattern. The canonical Laravel fix is `$this->release($delay)` inside the job's `handle()`, which returns the job to the queue and frees the worker. The `maxRetries = 3` guard is good but the sleep happens per-retry, not per-job.
    - **Plain English:** When Square says "slow down," the API client puts the worker to sleep right where it stands — like a cashier who stops serving the line to wait for a manager instead of stepping aside. While that worker naps, it can't process anyone else's job. If Square is slow for many brands at once, all the available workers could end up napping simultaneously. The fix is to have the worker put the job back in the queue with a "come back in X seconds" note rather than sleeping on the job.
    - **Evidence:**
        ```php
        while (true) {
            $response = $this->makeRequest($token, $method, $path, $query, $body);

            if ($response->status() === 429 && $attempt < $maxRetries) {
                $wait = max(1000, ((int) ($response->header('Retry-After') ?? 1)) * 1000);
                usleep($wait * 1000);
                $attempt++;
                continue;
            }
            break;
        }
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#SCALE-4** · P2 — FreshaApiClient retry loop has the same in-process `usleep` worker-blocking pattern
    - **Where:** app/Services/Fresha/FreshaApiClient.php:161-169
    - **Affects:** Same worker-exhaustion risk as SCALE-3, applied to the Fresha API path. Under a Fresha rate-limit event, any job calling the Fresha API sleeps the Horizon worker inside the retry loop.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Apply the same `release($delay)` pattern described in SCALE-3.
        - Consider extracting a shared `VendorApiClient` base class so the retry/policy logic is maintained once (applies to both Square and Fresha clients, and any future vendor integration).
    - **Technical:** Identical architecture to SCALE-3 — a `while (true)` loop with in-process `usleep`. The code is a near-copy of `SquareApiClient::request()`. At the 200-brand scale target, even if Fresha adoption is low, the pattern means every Fresha-connected brand's sync job can occupy a worker during rate-limit windows. Extracting the retry logic into a shared trait or base class would prevent this anti-pattern from recurring in future vendor integrations.
    - **Plain English:** Same problem as the Square client — the worker naps on the job instead of putting the task back in the queue. This is the second copy of the same pattern, which means a future third vendor integration (e.g., a booking platform) would likely copy it a third time. Better to fix both and extract the shared logic now.
    - **Evidence:**
        ```php
        while (true) {
            $response = $this->makeRequest($token, $method, $path, $query, $body);

            if ($response->status() === 429 && $attempt < $maxRetries) {
                $wait = max(1000, ((int) ($response->header('Retry-After') ?? 1)) * 1000);
                usleep($wait * 1000);
                $attempt++;
                continue;
            }
            break;
        }
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#SCALE-5** · P3 — CloudflareDnsService::upsertCname makes redundant PATCH call when CNAME content already matches
    - **Where:** app/Services/Cloudflare/CloudflareDnsService.php:82-98
    - **Affects:** Every call to `upsertCname` where the DNS record already exists with the correct target. The method unconditionally issues a PATCH to update the `proxied` flag even when it may already be correct. At 200 brands each going through Hydrogen storefront setup, this doubles Cloudflare API call volume for DNS operations.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Before patching, fetch the current record with a GET to inspect the existing `proxied` state, or include `proxied` in the `findRecord` response so the caller can skip the PATCH when state is already correct.
        - Alternatively, always include `content` in the PATCH alongside `proxied` so the call is idempotent and the redundant-PATCH concern goes away — a PATCH with the same values is a cheap no-op at Cloudflare's edge.
    - **Technical:** `findRecord` returns only `['id', 'type', 'name', 'content']` — it omits `proxied`. The `upsertCname` method therefore can't know whether the existing record's `proxied` flag matches the desired value, so it unconditionally issues a PATCH. Cloudflare's DNS API rate limit is ~30 requests/second per zone; at 200 brands this isn't a limit-breaker today, but it's a wasteful API call that adds latency to every subdomain provisioning operation.
    - **Plain English:** When setting up a brand's storefront subdomain, the service checks if the DNS record already exists. If it does and the target is correct, it still phones Cloudflare to say "make sure the proxy setting is right" — even though it might already be right. It's like calling your internet provider to confirm your plan hasn't changed every time you pay the bill. Unnecessary, adds a bit of lag, and uses up your polite-phone-call budget.
    - **Evidence:**
        ```php
        if ($existing !== null) {
            if ($existing['content'] === $target) {
                // Check proxied state — requires a fresh fetch as findRecord doesn't return it.
                $response = Http::withToken($this->apiToken)
                    ->patch($this->zonesUrl("/dns_records/{$existing['id']}"), [
                        'proxied' => $proxied,
                    ]);
        ```
    - `[DRAFT, confidence: 0.7]`

- [ ] **#SCALE-6** · P3 — EmailSubscription saved hook dispatches one job per subscription; bulk imports produce N jobs with no batching
    - **Where:** app/Models/Core/Notifications/EmailSubscription.php:98-112
    - **Affects:** Any bulk operation that creates or updates many EmailSubscription rows (CSV import, GDPR data port, brand migration). Each saved row dispatches a `SyncCustomerMarketingOptInJob` — 10K rows = 10K Redis job-push commands.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Gate the dispatch behind a `wasRecentlyCreated` or `isDirty('status')` check so unchanged rows don't fire the job.
        - For bulk paths, provide a `saveQuietly()` or a batch-aware flag that skips the per-row dispatch and lets the bulk importer run one reconciliation job at the end.
    - **Technical:** The `saved` hook fires on every `save()`, including rows where `status` didn't change. A bulk CSV import of 10K rows would push 10K jobs to the `mail` queue — each doing a Customer lookup. The `afterCommit` guard prevents the job from firing on rolled-back transactions, which is good, but the hook fires per-row regardless. Adding `isDirty('status')` would cut the dispatch to only rows that actually changed state.
    - **Plain English:** Every time an email subscription row is saved — even if nothing changed — a small background task is created to sync some cached data. In normal use (one person subscribing at a time) this is fine. But if the team imports a spreadsheet of 10,000 subscribers, the system creates 10,000 tiny tasks all at once. Most of them are unnecessary because the status didn't change. Adding a "only if something actually changed" check stops the flood.
    - **Evidence:**
        ```php
        static::saved(function (self $subscription) {
            if ($subscription->list_key === 'marketing' && $subscription->professional_id && $subscription->email) {
                $professionalId = (string) $subscription->professional_id;
                $email = (string) $subscription->email;
                $isSubscribed = $subscription->status === 'subscribed';

                DB::afterCommit(function () use ($professionalId, $email, $isSubscribed) {
                    \App\Jobs\Notifications\SyncCustomerMarketingOptInJob::dispatch(
                        $professionalId,
                        $email,
                        $isSubscribed,
                    );
                });
            }
        });
        ```
    - `[DRAFT, confidence: 0.75]`

- [ ] **#SCALE-7** · P3 — SendTransactionalNotificationEmailJob dispatched without visible `$tries`/`$backoff` exposure at the call site
    - **Where:** app/Services/Notifications/NotificationPublisher.php:141-146
    - **Affects:** Email delivery reliability under Resend API outage. Without `$tries` and `$backoff` visible in the dispatch path (or on the job class itself), a Resend 5xx could retry immediately rather than with exponential backoff, creating a retry storm on the `mail` queue during an outage.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Verify that `SendTransactionalNotificationEmailJob` has `$tries = 3` and `$backoff = [30, 120, 300]` (or similar) on the job class itself. If not, add them.
        - Add a `failed()` handler that logs the failure via `report()` so Nightwatch surfaces persistent email delivery failures.
    - **Technical:** The job dispatch call sites in `publish()` and `publishMany()` use `->onQueue('mail')` but don't chain `->tries()` or `->backoff()`. If the job class doesn't define these as properties, Laravel's default is 1 try with no backoff — a single Resend 500 would permanently drop the notification email. The job class file (`app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php`) is not in the audit scope, so this finding is a flag to verify, not a confirmed defect. At ~40K daily notifications, even a 1% email failure rate drops 400 emails/day silently.
    - **Plain English:** When the system sends notification emails, each one is a background task. If the email provider has a hiccup, the task should retry a few times with pauses in between. I can't see from this code whether the retry settings are configured on the task itself — they're not set at the point where the task is created. This is a "please check" flag: if the task doesn't have retry settings, a brief email-provider outage would permanently lose every notification email that was attempted during that window.
    - **Evidence:**
        ```php
        if ($inserted > 0 && config('partna.notifications.email_enabled', false)) {
            SendTransactionalNotificationEmailJob::dispatch(
                $notificationId,
                $category,
                $professionalId,
            )->onQueue('mail');
        }
        ```
    - `[DRAFT, confidence: 0.6]`
