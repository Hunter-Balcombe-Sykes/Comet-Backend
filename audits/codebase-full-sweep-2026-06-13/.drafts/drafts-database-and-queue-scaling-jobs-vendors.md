- [ ] **#SCALE-1** · P1 — DeleteMirroredMediaJob lands on `default` queue despite documented intent
    - **Where:** app/Jobs/Platforms/DeleteMirroredMediaJob.php:68-70
    - **Affects:** Queue isolation — platform-media cleanup competes with user-facing jobs on the shared `default` queue during cleanup bursts (e.g. bulk Instagram disconnect).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `$this->onQueue('scraping');` in the constructor (or a config-driven equivalent), matching the class-level docblock claim.
    - **Technical:** The job's own docblock states "Queued on `scraping` (the existing platform queue — no Horizon supervisor change)" but the constructor body is empty — no `onQueue()` call. Laravel's default queue routing sends the job to `default`. All other platform-scoped jobs (`InstagramConnectJob`) explicitly route to `scraping`. A batch disconnect of multiple Instagram connections dispatches one `DeleteMirroredMediaJob` per orphaned folder; without domain-queue isolation, those deletes contend with user-facing `default`-queue jobs (cache-warm, metrics aggregation, any future `default` job). The `ShouldBeUnique` key on folder prevents duplicate deletes of the same prefix, but does not prevent cross-queue resource contention.
    - **Plain English:** The job's label says it runs on the "scraping" line so it doesn't get in the way of customer-facing work. But the actual code never sets that lane — the job goes to the general-purpose line instead. If a user disconnects several Instagram accounts at once, the cleanup work competes directly with other jobs that affect the customer experience.
    - **Evidence:**
        ```php
        /**
         * Queued on `scraping` (the existing platform queue — no Horizon supervisor
         * change) since cleanup is not latency-sensitive.
         */
        class DeleteMirroredMediaJob implements ShouldBeUnique, ShouldQueue
        {
            // ...
            public function __construct(public readonly string $folder) {}
            // ↑ No $this->onQueue() call — job lands on 'default'
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#SCALE-2** · P2 — SyncSubdomainToKvJob issues sequential Cloudflare KV writes per alias without batching
    - **Where:** app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:170-191 (writeAliasEntries method)
    - **Affects:** Cloudflare KV write budget — one user with many historical handle aliases can consume N+2 KV writes per sync cycle.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Batch all alias entries into a single Cloudflare KV bulk-write call (Cloudflare KV REST API supports `PUT /bulk` with an array of `{key, value, expiration_ttl}` objects).
        - Apply a per-user throttle / max-aliases cap so one user with 50+ historical aliases doesn't dominate the KV write budget.
    - **Technical:** `writeAliasEntries` queries all non-expired aliases for the user via `->get()` and loops over them calling `$kv->put()` for each. Each `put()` is one HTTP request to the Cloudflare KV REST API. At pre-beta scale this is harmless, but at viral scale — one user with 20 historical handle aliases, or a deploy triggering `UserObserver` for thousands of users — the sequential writes can exhaust the per-account Cloudflare KV write rate limit (typically 1,000–5,000 writes/min depending on plan). The `ShouldBeUnique` 45s coalescing window helps per-user, but a platform-wide KV sync storm (e.g. after a migration touching `core.users.handle`) would still spike writes. Cloudflare KV does not accept a bulk endpoint as of writing, but per-key writes should be parallelised via `Http::pool` or capped per-invocation.
    - **Plain English:** Every time a user's handle-aliases need to be updated in Cloudflare's routing table, the job makes one separate phone call per alias. One user with 10 old handles = 10 calls. At small scale this is fine, but if the platform ever needs to refresh everyone's routing at once (say after a system-wide change), those calls could overwhelm Cloudflare's per-minute limit — like too many people trying to get through the same door at once. Batching those calls or spreading them out prevents the bottleneck.
    - **Evidence:**
        ```php
        private function writeAliasEntries(CloudflareKvService $kv, string $proId, string $current, string $canonical): void
        {
            $aliases = DB::table('core.user_handle_aliases')
                ->where('user_id', $proId)
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->get();

            foreach ($aliases as $alias) {
                // ...
                $kv->put($handle, ['type' => 'alias', 'redirect' => $canonical], $ttl);
            }
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#SCALE-3** · P2 — TwitchApiClient has no 429 rate-limit handling; Twitch is exempt from the circuit-breaker pattern Kick uses
    - **Where:** app/Services/Streaming/TwitchApiClient.php:48-59
    - **Affects:** Streaming live-status accuracy — a Twitch rate limit silently marks all polled handles as offline for one cycle, with no backoff signal to the poller.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add explicit 429 detection in `TwitchApiClient::getLiveHandles`, mirroring the `KickRateLimitException` pattern used by `KickApiClient`.
        - In `LiveStatusPoller::pollTwitch`, catch the new exception and apply a circuit breaker (Redis key with TTL), skipping Twitch for the remainder of the cycle — same pattern as Kick's `streaming:kick:rate_limited` key.
    - **Technical:** `KickApiClient` checks `$response->status() === 429` and throws `KickRateLimitException`, which `LiveStatusPoller::pollKick` catches to set a 300s Redis circuit-breaker flag (`streaming:kick:rate_limited`). `TwitchApiClient` has no equivalent — all non-success responses fall through to a generic `Log::error` and return `[]`. At scale with thousands of streaming handles, a Twitch 429 (common during platform incidents or traffic spikes) causes `pollTwitch` to write `is_live=false` into Redis for every handle in the batch, silently degrading live-status badges across all profiles. The poller's cold-handle demotion then pushes those handles into longer TTL tiers, extending the stale-offline window beyond one cycle. Adding a 429 exception + circuit breaker keeps the Kick/Twitch resilience parity.
    - **Plain English:** When Twitch tells us "too many requests, slow down," the Kick integration politely steps aside and waits before trying again. The Twitch integration doesn't recognize that signal — it treats the "slow down" the same as a total failure and tells every streamer's page "not live right now." At viral scale, one Twitch rate limit could make hundreds of streamers appear offline on their Partna pages until the next poll cycle corrects it.
    - **Evidence:**
        ```php
        // TwitchApiClient — no 429 branch:
        if (! $response->successful()) {
            Log::error('streaming.api_error', [
                'platform' => 'twitch',
                'status' => $response->status(),
            ]);
            return [];
        }
        ```
        Compare to KickApiClient:
        ```php
        // KickApiClient — explicit 429 handling:
        if ($response->status() === 429) {
            $retryAfter = (int) ($response->header('Retry-After') ?? 60);
            throw new KickRateLimitException($retryAfter);
        }
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#SCALE-4** · P2 — ProcessImageVariantsJob loads the full original file into PHP memory instead of streaming
    - **Where:** app/Jobs/ProcessImageVariantsJob.php:145-149
    - **Affects:** Queue-worker memory pressure — large originals (near the 24MP pixel ceiling) consume full file bytes in PHP heap.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `$disk->get()` + `file_put_contents()` with `$disk->readStream()` + `stream_copy_to_stream()`, matching the pattern already used in `ProcessVideoVariantsJob` and `VideoVariantService`.
    - **Technical:** The job downloads the original image from R2 via `$content = $disk->get($this->originalPath)` — Flysystem's `get()` calls `file_get_contents()` on the S3 stream body, loading the entire compressed file into a PHP string. A 24MP JPEG at 92% quality can be 8–20 MB. In a long-running Horizon worker processing multiple images sequentially, repeated 20 MB allocations cause memory fragmentation and increase GC pressure. The video pipeline (`ProcessVideoVariantsJob`) already uses `$disk->readStream()` + `stream_copy_to_stream()` for the same R2 download, proving the streaming pattern works on the same disk. The `loadImage()` call already has a pixel-count guard, but that guard evaluates dimensions from the file header — the full compressed bytes are still loaded before GD decoding.
    - **Plain English:** When an image processing job starts, it downloads the entire original file into the worker's memory at once — like filling a bucket before pouring it into the sink. The video processing jobs already use a hose instead (streaming directly to disk without holding the whole thing in memory). For large images near the upload limit, the bucket approach wastes memory that could cause the worker to slow down or crash under heavy load. Switching to the hose pattern is a small, proven change.
    - **Evidence:**
        ```php
        // ProcessImageVariantsJob — full file load:
        $content = $disk->get($this->originalPath);
        if (! file_put_contents($localTmp, $content)) {
            throw new \RuntimeException('Failed to write original to temp file.');
        }
        ```
        Compare to ProcessVideoVariantsJob — streaming:
        ```php
        // ProcessVideoVariantsJob — streaming:
        $stream = $disk->readStream($this->originalPath);
        // ...
        stream_copy_to_stream($stream, $dest);
        ```
    - `[DRAFT, confidence: 0.85]`
