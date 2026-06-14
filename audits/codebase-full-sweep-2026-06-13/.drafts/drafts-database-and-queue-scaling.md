
<!-- ═══ LENS: database-and-queue-scaling | CHUNK: models-config ═══ -->

- [ ] **SCALE-1** · P1 — Customer::redact() loads all linked enquiries into memory without bounding
    - **Where:** app/Models/Core/User/Customer.php: near end of `redact()` method
    - **Affects:** GDPR data erasure for professionals; a user with a high volume of contact-form submissions could cause memory exhaustion or timeout during redaction, leaving PII not fully erased.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the `Enquiry::where(…)->each(fn ($e) => $e->redact())` pattern with a chunked approach (e.g., `chunkById(200)`) that processes enquiries in small batches.
        - Batch the status updates (e.g., bulk update the redaction fields directly on the matching rows) rather than loading each model.
    - **Technical:** `each()` internally calls `get()` and materialises the entire result set into memory, then issues an `update` per row. At viral scale a professional could have tens of thousands of enquiries; that single query will balloon memory and hold a transaction open while iterating, potentially exceeding PHP’s memory limit or the web process timeout on account deletion. Chunking avoids the memory pressure and keeps the per‑chunk work bounded.
    - **Plain English:** Imagine a folder with every message ever sent to you by your customers. The current code picks up the entire folder, reads every letter into RAM, and then goes through one‑by‑one to erase the personal info. If the folder is huge, the computer runs out of desk space mid‑job and crashes, leaving some letters un‑erased and the process incomplete. The fix is to process the folder a few letters at a time so the desk never overflows.
    - **Evidence:**
        ```php
        Enquiry::where('customer_id', $this->id)
            ->each(fn ($e) => $e->redact());
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **SCALE-2** · P2 — GalleryImageResource relies on lazy-loading of mediaVariants, risking N+1 queries
    - **Where:** app/Http/Resources/GalleryImageResource.php: line calling `$this->variantUrls()` in `toArray()`; and app/Models/Core/Site/SiteMedia.php: `variantUrls()` method that calls `$this->loadMissing('mediaVariants')`
    - **Affects:** List endpoints that use `GalleryImageResource` without eager‑loading the `mediaVariants` relation — every image row in the response will trigger an extra database query.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Require that controllers always eager‑load `mediaVariants` when using this resource.
        - Alternatively, change `variantUrls()` to accept only an already‑loaded relation (e.g., throw if not loaded) to make the mistake impossible, or use `whenLoaded` in the resource so that un‑loaded variants simply exclude the key.
    - **Technical:** The resource’s `toArray` calls `$this->variantUrls()`, which invokes `loadMissing` on the `mediaVariants` HasMany relation. If the controller forgot to `->with('mediaVariants')`, every row in a paginated gallery list will execute a separate `SELECT * FROM site.media_variants WHERE media_id = …`. At scale this is a classic N+1 whose query count multiplies with the page size. The correct Laravel pattern is to eager load the relation on the query and only use it if already present.
    - **Plain English:** Think of a photographer’s gallery: each photo’s thumbnail should be printed on the back of the print. The server currently picks up the stack of prints, then for each one sends a runner to the darkroom to fetch the tiny thumbnail. With 50 prints, that’s 50 separate trips. The fix is to fetch all the thumbnails at once before showing the prints, or to refuse to show a print that doesn’t already have its thumbnail attached.
    - **Evidence:**
        ```php
        // GalleryImageResource::toArray()
        'variants' => $this->variantUrls(),

        // SiteMedia::variantUrls()
        public function variantUrls(): array
        {
            $this->loadMissing('mediaVariants');
            …
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **SCALE-3** · P2 — EnforcePlatformLinkCapCommand loads all distinct user IDs into memory
    - **Where:** app/Console/Commands/EnforcePlatformLinkCapCommand.php: line `$userIds = Block::query() … ->pluck('user_id');`
    - **Affects:** Scheduled or manual run of the platform‑link cap enforcement; at scale with thousands of professionals, the in‑memory collection of all user IDs can exhaust memory.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the single `pluck()` with a chunk‑based iteration, e.g., `User::whereIn(…)` or a cursor over distinct IDs via `chunkById`.
        - Process users in batches rather than loading the entire ID list upfront.
    - **Technical:** `pluck()` materialises every distinct `user_id` from the `site.blocks` table into a PHP array. While the loop itself then fetches blocks per user, the initial array can grow to hundreds of megabytes if the platform has many active professionals, causing an OOM kill during the command’s execution. Laravel’s `chunkById` or database-level pagination avoids this by holding only a page of IDs in memory at any time.
    - **Plain English:** The command is essentially saying “give me a list of every house in the entire city, all on one enormous sheet of paper.” That sheet alone could be too heavy to carry. The fix is to ask for the list a few blocks at a time, process those houses, and then ask for the next few blocks.
    - **Evidence:**
        ```php
        $userIds = Block::query()
            ->where('block_group', 'links')
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('user_id')
            ->filter()
            ->values();
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **SCALE-4** · P2 — BackfillSubdomainKvCommand loads all user IDs into memory when run with --all
    - **Where:** app/Console/Commands/BackfillSubdomainKvCommand.php: line `$ids = $all ? User::query() … ->pluck('id') : collect([$proId]);`
    - **Affects:** Weekly scheduled KV backfill (Sunday 04:00) and manual re‑sync; a large user base will balloon the memory footprint of the scheduler process.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Use `User::query() … ->chunkById(500)` to stream IDs and dispatch jobs in batches, or dispatch a single batch job that internally chunks.
        - Keep the per‑ID dispatch lightweight; a chunked approach avoids the massive upfront array.
    - **Technical:** The command currently calls `pluck('id')` to obtain every user ID into a PHP collection before looping. At scale this array contains one entry per professional — potentially tens of thousands — and the combined memory usage of the collection plus the subsequent foreach can push the PHP process over its memory limit, causing a crash and leaving the KV table incomplete until the next weekly run.
    - **Plain English:** Instead of bringing an entire phonebook into the office at once, the command should call one page of names, make the corresponding Cloudflare updates, then request the next page. The current approach risks the phonebook being too heavy to lift.
    - **Evidence:**
        ```php
        $ids = $all
            ? User::query()
                ->whereNotNull('handle')
                ->where('handle', '!=', '')
                ->pluck('id')
            : collect([$proId]);
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **SCALE-5** · P2 — GcOrphanedVideoArtifactsCommand loads the entire videos/ directory listing into memory
    - **Where:** app/Console/Commands/GcOrphanedVideoArtifactsCommand.php: `foreach ($disk->allFiles('videos') as $file)`
    - **Affects:** Weekly garbage‑collection of orphaned video artefacts; as the number of uploaded videos grows, the in‑memory array of all file paths can cause an OOM kill during the command.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace `allFiles()` with a streaming iterator (if the Flysystem/Laravel disk adapter supports directory listing pagination) or sweep the prefix in smaller batches using `listContents` with limit/pagination.
        - Alternatively, adopt a separate ledger‑based approach (like the existing `SweepPurgedVideoArtifactsCommand`) so a full enumeration is never needed.
    - **Technical:** `allFiles()` internally calls `listContents` with `recursive = true` and returns a plain PHP array of every file path under `videos/`. In a busy platform this could be millions of entries, consuming hundreds of megabytes of PHP memory and causing the weekly cleanup job to fail repeatedly, leaving orphan objects to accumulate and increase storage costs. A streaming or chunked listing keeps the memory footprint constant.
    - **Plain English:** The janitor currently picks up a box listing every single item in the entire warehouse, memorises every line, and then walks around to clean. If the warehouse holds a million boxes, the list alone is too heavy to carry. The fix is to walk down the aisles one shelf at a time, glancing only at the boxes in the current aisle.
    - **Evidence:**
        ```php
        foreach ($disk->allFiles('videos') as $file) {
            $parts = explode('/', $file);
            …
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **SCALE-6** · P2 — PruneExpiredHandleAliases loads all expired alias IDs into memory before deletion
    - **Where:** app/Console/Commands/PruneExpiredHandleAliases.php: lines `$expiredHandleIds = $pgsql->table('core.user_handle_aliases') … ->pluck('id');` and `$expiredSubdomainIds = $pgsql->table('site.site_subdomain_aliases') … ->pluck('id');`
    - **Affects:** Daily alias expiry pruning; a large number of expired aliases will cause a memory spike in the scheduler process.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Delete the expired rows in batches using `limit` inside a loop rather than plucking all IDs first.
        - Capture affected professional IDs during the batched deletion (e.g., by chunking) to avoid loading the whole set at once.
    - **Technical:** `pluck()` materialises every expired alias ID into a PHP collection. Even though the subsequent delete uses those IDs, the intermediate array can be large if many professionals change handles/subdomains. At viral adoption, daily alias churn could generate tens of thousands of expired rows; the pluck could exceed the PHP memory limit and crash the scheduled job, leaving expired aliases in the database and the KV sync incomplete.
    - **Plain English:** The nightly cleanup currently says “give me a list of all expired parking permits” and then one‑by‑one removes them. If thousands of permits expire on the same night, that single list can be too long. The cleaner should instead remove a handful at a time, noting which drivers need a follow‑up, without ever holding the full list in hand.
    - **Evidence:**
        ```php
        $expiredHandleIds = $pgsql->table('core.user_handle_aliases')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->pluck('id');
        $expiredSubdomainIds = $pgsql->table('site.site_subdomain_aliases')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->pluck('id');
        ```
    - `[DRAFT, confidence: 0.9]`

<!-- ═══ LENS: database-and-queue-scaling | CHUNK: jobs-vendors ═══ -->

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
