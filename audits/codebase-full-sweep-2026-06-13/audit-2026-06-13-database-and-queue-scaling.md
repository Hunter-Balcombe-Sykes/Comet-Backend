# Database & Queue Scaling Audit — 2026-06-13

**Branch:** development
**Lens:** Database & queue scaling: N+1, unbounded reads, connection scoping, queue shape, vendor budgets, migration safety, backpressure
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-5`
**Source files audited:**
- `app/Models/Core/User/Customer.php`
- `app/Models/Core/Site/SiteMedia.php`
- `app/Http/Resources/GalleryImageResource.php`
- `app/Console/Commands/EnforcePlatformLinkCapCommand.php`
- `app/Console/Commands/BackfillSubdomainKvCommand.php`
- `app/Console/Commands/GcOrphanedVideoArtifactsCommand.php`
- `app/Console/Commands/PruneExpiredHandleAliases.php`
- `app/Jobs/Platforms/DeleteMirroredMediaJob.php`
- `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php`
- `app/Jobs/Streaming/CheckStreamingLiveStatusJob.php`
- `app/Jobs/ProcessImageVariantsJob.php`
- `app/Jobs/ProcessVideoVariantsJob.php`
- `app/Services/Streaming/TwitchApiClient.php`
- `app/Services/Streaming/KickApiClient.php`
- `app/Services/Streaming/LiveStatusPoller.php`
- `routes/console.php`

## Adjudication notes

**Dropped (GalleryImageResource N+1):** The only caller of `GalleryImageResource` is `UserGalleryController`, which Grep confirmed already eager-loads `->with('mediaVariants')` at line 40. The `loadMissing()` in `variantUrls()` is a documented safety net, not an active N+1. Per-user gallery data is well under 50 rows. Dropped per Always-Drop Category §8.

**Mechanism-corrected (Customer::redact()):** DeepSeek stated that `each()` "calls `get()` and materialises the entire result set into memory." This is incorrect for `Illuminate\Database\Eloquent\Builder::each()`, which delegates to `chunk(1000)`. The real finding is N individual `UPDATE` round-trips (one per enquiry, plus a conditional notification `UPDATE`). Re-tiered P1 → P2; technical description rewritten.

**Re-tiered (DeleteMirroredMediaJob):** DeepSeek filed P1 for queue-routing mismatch. The jobs still run and still delete the folder — only on the wrong queue. Queue isolation is a hardening concern, not a correctness failure. Re-tiered P1 → P2.

**Re-tiered (PruneExpiredHandleAliases):** At "thousands of users" scale, daily alias churn plucks a bounded UUID list (thousands of rows at most = a few MB). Not a realistic OOM risk within the lens's stated traffic model. Re-tiered P2 → P3.

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 8 complete
- P3 Low: 0 of 1 complete

---

## P2 — Should fix

- [ ] **SCALE-1** · P2 — `EnforcePlatformLinkCapCommand` plucks all user IDs then issues one Eloquent query per user
    - **Where:** `app/Console/Commands/EnforcePlatformLinkCapCommand.php:64–70`, `app/Console/Commands/EnforcePlatformLinkCapCommand.php:80–88`
    - **Affects:** Anyone who runs this remediation command on a large dataset — the command itself OOMs before finishing, leaving excess link blocks untouched.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the upfront `pluck('user_id')` + `foreach` + per-user `Block::query()->get()` with a single `chunkById` on the blocks table grouped by `user_id`, processing each user's blocks without materialising all user IDs first.
        - Alternatively, express the deletion as a raw SQL query since the "keep oldest N" logic is deterministic: a `ROW_NUMBER()` window function can identify excess rows without PHP iteration.
    - **Technical:** The command loads all distinct `user_id` values from `site.blocks` into a PHP array via `pluck()`, then inside `foreach ($userIds as $proId)` issues a second `Block::query()->...->get()` per user. This is an N+1 query pattern at the command level: 1 `DISTINCT SELECT` + N per-user `SELECT`s. At 10,000 professionals with link blocks that is 10,001 queries. The upfront `pluck()` also holds all distinct UUIDs in memory simultaneously. While the command is not scheduled (one-shot remediation), the team may run it again after a bug window that creates new excess data — fixing the query shape now prevents a painful runtime surprise at larger scale.
    - **Plain English:** The command first asks "give me a list of every professional who has link blocks" — loading that entire list into memory — and then for each professional asks "now give me all their link blocks." If there are thousands of professionals, that's thousands of separate database trips. Instead, the command should walk the blocks table in pages, handling each page's worth of professionals in one pass without ever holding the full list in memory.
    - **Evidence:**
        ```php
        $userIds = Block::query()
            ->where('block_group', 'links')
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('user_id')
            ->filter()
            ->values();
        // ...
        foreach ($userIds as $proId) {
            $cappedBlocks = Block::query()
                ->where('user_id', $proId)
                ->where('block_group', 'links')
                ->whereNull('deleted_at')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->filter(fn (Block $b) => in_array($b->settings['category'] ?? null, $cappedCategories, true))
                ->values();
        ```

- [ ] **SCALE-2** · P2 — `BackfillSubdomainKvCommand --all` plucks every user ID into memory before dispatching jobs
    - **Where:** `app/Console/Commands/BackfillSubdomainKvCommand.php:40–45`
    - **Affects:** The weekly scheduled Sunday 04:00 KV backfill and any manual re-sync. With tens of thousands of professionals the `pluck('id')` allocation spikes the scheduler process memory and, if it OOMs, leaves the KV table partially stale for a week.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `->pluck('id')` + `foreach` with `->chunkById(500, fn ($chunk) => $chunk->each(fn ($user) => SyncSubdomainToKvJob::dispatch($user->id)))`. This caps in-memory state to 500 UUIDs at a time and dispatches jobs in the same pass.
        - Keep the single-user path unchanged.
    - **Technical:** `User::query()->whereNotNull('handle')->where('handle', '!=', '')->pluck('id')` returns one `Collection` containing every professional's UUID. Each UUID is ~36 bytes; 50,000 professionals = ~1.8 MB just for the collection, plus Laravel's collection overhead. More importantly, the subsequent `foreach ($ids as $id)` dispatches `SyncSubdomainToKvJob::dispatch()` per ID in a tight loop — at 50,000 users with `dispatchSync()`, that is 50,000 sequential Cloudflare KV HTTP calls inside one scheduler tick (or, with `--queue`, 50,000 Redis pushes before the loop exits). `chunkById` bounds memory and naturally batches the dispatch loop.
    - **Plain English:** The weekly backfill currently says "hand me a list of every professional in the system" and then processes them one by one. At scale that list alone becomes heavy, and if the process runs out of memory mid-way the KV routing table is only half-refreshed — some professionals' pages stop routing correctly until the next Sunday. Processing users a few hundred at a time means the list never has to be held all at once.
    - **Evidence:**
        ```php
        $ids = $all
            ? User::query()
                ->whereNotNull('handle')
                ->where('handle', '!=', '')
                ->pluck('id')
            : collect([$proId]);
        ```

- [ ] **SCALE-3** · P2 — `GcOrphanedVideoArtifactsCommand` loads the entire `videos/` R2 directory listing into a PHP array
    - **Where:** `app/Console/Commands/GcOrphanedVideoArtifactsCommand.php:43–51`
    - **Affects:** The weekly Sunday 04:20 garbage-collection job. As the video library grows, `allFiles('videos')` returns every stored path into a single PHP array. At millions of video objects this array alone can exhaust the worker's memory limit, causing the GC job to crash and orphaned objects to accumulate indefinitely.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace `$disk->allFiles('videos')` with a streaming walk using `Storage::disk(...)->listContents('videos', true)` (Flysystem's `DirectoryListing` is a lazy generator), iterating and grouping by media ID without holding all paths at once.
        - Alternatively, adopt the ledger-based approach already used by `SweepPurgedVideoArtifactsCommand` — maintain a record of orphaned prefixes and walk only those, rather than scanning the full prefix every week.
    - **Technical:** Flysystem's `allFiles()` calls `listContents($path, true)` and immediately iterates the result into a plain PHP `array` via `array_map`. At a platform with 100,000 users each having uploaded several videos, the `videos/` prefix can contain millions of object paths. The resulting PHP array and the `$groups` dictionary (which duplicates those paths) can consume hundreds of megabytes of heap, OOM-killing the scheduler process. Flysystem 3's `listContents()` returns a `DirectoryListing` backed by a PHP `Generator` — iterating it directly via `foreach` never materialises the full listing.
    - **Plain English:** The weekly cleanup job starts by asking the cloud storage "list every file in the video area" and keeps the entire list in memory before doing any work. If the video area has millions of files, that list alone can exhaust the server's available memory and crash the cleanup, leaving orphaned files accumulating and increasing storage costs. The fix is to walk the list as a stream — looking at one file at a time — so the server never has to hold the whole inventory at once.
    - **Evidence:**
        ```php
        foreach ($disk->allFiles('videos') as $file) {
            $parts = explode('/', $file);
            if (count($parts) < 4) {
                continue;
            }
            $mediaId = $parts[2];
            $groups[$mediaId]['prefix'] = "{$parts[0]}/{$parts[1]}/{$parts[2]}";
            $groups[$mediaId]['files'][] = $file;
        }
        ```

- [ ] **SCALE-4** · P2 — `Customer::redact()` issues N individual UPDATE calls per enquiry instead of a bulk erasure
    - **Where:** `app/Models/Core/User/Customer.php:113–114`; `app/Models/Core/Site/Enquiry.php:135–151`
    - **Affects:** GDPR data-erasure flow. A professional who has received thousands of contact-form submissions triggers thousands of round-trips to Postgres during account deletion; the erasure job may time out, leaving enquiry PII un-redacted.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the per-row `each(fn ($e) => $e->redact())` with a single bulk `UPDATE` on the enquiries table (setting `name`, `email`, `phone`, `message`, `ip_hash`, `user_agent`, `redacted_at` in one statement).
        - For the notification body/title reset, collect the affected `notification_id` values in the same query and batch-update the notifications in one additional statement.
        - Use `chunkById` only if the bulk UPDATE query planner struggles at scale (unlikely for a bounded per-customer set).
    - **Technical:** `Eloquent\Builder::each()` delegates to `chunk(1000)` — it does not load the full result set into memory. The memory concern DeepSeek raised is incorrect. The real issue is that `Enquiry::redact()` calls `$this->update([...])` (one `UPDATE` statement) plus a conditional `Notification::where(...)->update(...)`, meaning a customer with 5,000 enquiries generates up to 10,000 Postgres round-trips inside the GDPR deletion path. A single bulk `UPDATE enquiries SET name=NULL, email=NULL, ... WHERE customer_id = ?` + a separate `UPDATE notifications SET title='[redacted]', body='[redacted]' WHERE id IN (SELECT notification_id FROM enquiries WHERE customer_id = ?)` completes the same erasure in 2 round-trips regardless of enquiry count, and eliminates the timeout risk entirely.
    - **Plain English:** When a professional's account is deleted and their customers' data must be erased, the current code erases each contact-form submission one at a time — potentially thousands of individual database operations. It's like shredding every page of a document separately instead of putting the whole document through the shredder at once. The fix rewrites the erasure to a single database operation that clears all submissions simultaneously, making the GDPR deletion instant and timeout-proof.
    - **Evidence:**
        ```php
        Enquiry::where('customer_id', $this->id)
            ->each(fn ($e) => $e->redact());
        ```
        And in `Enquiry::redact()`:
        ```php
        $this->update([
            'name' => null,
            'email' => null,
            'phone' => null,
            'message' => null,
            'ip_hash' => null,
            'user_agent' => null,
            'redacted_at' => now(),
        ]);

        if ($this->notification_id) {
            Notification::where('id', $this->notification_id)
                ->update(['title' => '[redacted]', 'body' => '[redacted]']);
        }
        ```

- [ ] **SCALE-5** · P2 — `ProcessImageVariantsJob` loads the full original file into PHP memory instead of streaming
    - **Where:** `app/Jobs/ProcessImageVariantsJob.php:148–150`
    - **Affects:** Queue worker memory pressure on the `images` queue — large originals (up to the 24MP ceiling) hold their full compressed bytes in a PHP string during download, increasing heap usage and GC pressure per job.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `$disk->get($this->originalPath)` + `file_put_contents($localTmp, $content)` with `$disk->readStream($this->originalPath)` + `stream_copy_to_stream($stream, $dest)`, matching the pattern already used in `ProcessVideoVariantsJob` on the same disk.
    - **Technical:** `Storage::disk()->get()` calls `file_get_contents()` on the S3/R2 response stream body, loading the entire compressed JPEG into a PHP string. A 24MP JPEG at 92% quality can be 8–20 MB. In a long-running Horizon worker processing multiple images sequentially, repeated full-file allocations cause memory fragmentation and increase GC pressure under burst load. `ProcessVideoVariantsJob` — operating on the same R2 disk — already uses `$disk->readStream()` + `stream_copy_to_stream()`, writing the original directly to a temp file without the intermediate PHP string allocation. The streaming approach has been validated in the same codebase; this is a straightforward parity fix.
    - **Plain English:** When the image processing job starts, it downloads the entire original file into the server's working memory all at once — like filling a bucket before pouring it into a drain. The video processing jobs already use a hose instead, streaming the file directly to a temp location without holding it all in memory. Applying the same hose approach to images means the worker never has to hold large files in memory simultaneously, keeping it lean under burst loads.
    - **Evidence:**
        ```php
        // ProcessImageVariantsJob — full file load:
        $content = $disk->get($this->originalPath);
        if (! file_put_contents($localTmp, $content)) {
            throw new \RuntimeException('Failed to write original to temp file.');
        }
        ```
        Compare to `ProcessVideoVariantsJob` — streaming (lines 150–161):
        ```php
        $stream = $disk->readStream($this->originalPath);
        // ...
        stream_copy_to_stream($stream, $dest);
        ```

- [ ] **SCALE-6** · P2 — `SyncSubdomainToKvJob` issues one Cloudflare KV HTTP call per alias instead of batching
    - **Where:** `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:167–188` (`writeAliasEntries`)
    - **Affects:** Cloudflare KV write budget — a platform-wide KV resync (e.g. the weekly `backfill-subdomain-kv` run dispatching thousands of `SyncSubdomainToKvJob`s) issues N × (alias_count + 1) individual HTTP calls to Cloudflare, risking per-account write-rate exhaustion and write failures that leave alias routing broken.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Collect all alias KV payloads for the user into an array and write them via `Http::pool` in a single concurrent batch, or use the Cloudflare Workers KV REST API's `PUT /bulk` endpoint (up to 10,000 key-value pairs per request) to collapse N alias writes into one HTTP call.
        - Add a per-user alias cap (e.g. `max_alias_kv_writes` in `config/partna.php`) so a user with an unusually large alias history can't dominate the write budget in a single job invocation.
    - **Technical:** `writeAliasEntries` queries all non-expired aliases for the user via `->get()` and iterates them in a `foreach`, calling `$kv->put()` for each one. Each `put()` is one HTTP request to the Cloudflare KV REST API. The `ShouldBeUnique(45s)` window collapses per-user observer storms, but does not prevent a backfill-triggered storm across all users simultaneously. The Cloudflare Workers KV REST API's `PUT /bulk` endpoint accepts up to 10,000 `{key, value, expiration_ttl}` objects in a single request — using it reduces N alias writes to 1 HTTP call per user, dramatically lowering write-rate exposure.
    - **Plain English:** Every time a user's old handles need to be updated in Cloudflare's routing table, the job makes one separate network call per old handle. One user with 10 old handles = 10 calls. When a weekly sync refreshes routing for every professional on the platform, those calls multiply across all users at once — like every department in a building trying to call the reception desk at the same time. Bundling all of a user's alias updates into a single call means far fewer calls in total, even during a platform-wide refresh.
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

- [ ] **SCALE-7** · P2 — `DeleteMirroredMediaJob` has no `onQueue()` call despite its docblock promising the `scraping` queue
    - **Where:** `app/Jobs/Platforms/DeleteMirroredMediaJob.php:47`
    - **Affects:** Queue isolation — platform-media cleanup jobs route to `default` rather than `scraping`, competing with user-facing work during bulk Instagram disconnect events. All other platform-scoped jobs (`InstagramConnectJob:64`) explicitly call `$this->onQueue('scraping')`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `$this->onQueue('scraping');` to the constructor body, matching the class docblock's stated contract and `InstagramConnectJob`'s routing.
    - **Technical:** The class docblock reads "Queued on `scraping` (the existing platform queue — no Horizon supervisor change) since cleanup is not latency-sensitive." The `Queueable` trait exposes `onQueue()` for exactly this purpose. Without it, `ShouldQueue` dispatches to whatever the default queue connection's queue name is (`default`). A burst disconnect of multiple Instagram connections dispatches one `DeleteMirroredMediaJob` per orphaned folder; these contend directly with user-facing jobs (cache-warm, notifications) on `default`. The `ShouldBeUnique` key prevents duplicate prefix deletes but does nothing for cross-queue contention.
    - **Plain English:** The label on the job's door says "use the platform cleanup line," but the actual routing code never stamps the ticket — so the job joins the main customer-facing line instead. During a burst of Instagram disconnects, those cleanup tasks can slow down work that directly affects user experience, like sending notifications or warming the site cache. A one-line fix routes these jobs to the correct, lower-priority lane.
    - **Evidence:**
        ```php
        /**
         * Queued on `scraping` (the existing platform queue — no Horizon supervisor
         * change) since cleanup is not latency-sensitive.
         */
        class DeleteMirroredMediaJob implements ShouldBeUnique, ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
            // ...
            public function __construct(public readonly string $folder) {}
            // ↑ No $this->onQueue('scraping') call — job routes to 'default'
        ```
        Compare to `InstagramConnectJob`:
        ```php
        $this->onQueue('scraping');
        ```

- [ ] **SCALE-8** · P2 — `TwitchApiClient` has no 429 detection; a rate-limit response silently marks every polled handle as offline
    - **Where:** `app/Services/Streaming/TwitchApiClient.php:53–63`
    - **Affects:** Streaming live-status accuracy — a Twitch 429 causes `pollTwitch` to return an empty live set, writing `is_live=false` into Redis for every handle in the current batch. Combined with the cold-handle demotion TTL tiers in `LiveStatusPoller`, demoted handles remain "offline" in Redis for up to 30 minutes after the rate limit clears.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `KickRateLimitException`-parity exception `TwitchRateLimitException` and throw it from `getLiveHandles` when `$response->status() === 429`, reading `Retry-After` from the response header.
        - In `LiveStatusPoller::pollTwitch`, catch the new exception and set a Redis circuit-breaker flag (e.g. `streaming:twitch:rate_limited` with a TTL from `Retry-After`), skipping Twitch for the remainder of the cycle — the same pattern `pollKick` already uses for Kick.
    - **Technical:** `KickApiClient` explicitly checks `$response->status() === 429` and throws `KickRateLimitException`, which `pollKick` catches to flip a Redis circuit-breaker (`streaming:kick:rate_limited`, 300s TTL). `TwitchApiClient` has no equivalent — any non-2xx status, including 429, falls through to a generic `Log::error` and `return []`. The poller then calls `writeStatus('twitch', $handle, false)` for every handle in the batch, incrementing their offline counters and potentially pushing them into `COOL_OFFLINE_TTL` (600s) or `COLD_OFFLINE_TTL` (1800s) tiers. A 3-minute Twitch incident that triggers a single 429 can silence streamer live-status badges on their Partna pages for up to 30 minutes beyond the incident end.
    - **Plain English:** When Twitch tells the server "too many requests, slow down," the Kick integration politely steps aside and waits before trying again. The Twitch integration doesn't recognise that signal — it treats the "slow down" response the same as if every streamer were offline and updates their Partna pages accordingly. At scale, one Twitch rate-limit event during a traffic spike could make every streamer on the platform appear offline for half an hour after Twitch has already recovered. A two-minute fix mirrors the Kick pattern.
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
        Compare to `KickApiClient`:
        ```php
        if ($response->status() === 429) {
            $retryAfter = (int) ($response->header('Retry-After') ?? 60);
            throw new KickRateLimitException($retryAfter);
        }
        ```

## P3 — Nice to have

- [ ] **SCALE-9** · P3 — `PruneExpiredHandleAliases` plucks expired alias IDs before deletion rather than deleting in-place
    - **Where:** `app/Console/Commands/PruneExpiredHandleAliases.php:23–31`
    - **Affects:** Daily scheduled alias pruning at 03:15. At the "thousands of users" scale this lens targets, the in-memory UUID list is small (a few MB at most); this becomes relevant only at hundreds of thousands of users with high handle-churn.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Snapshot the affected `user_id` values for KV re-sync in the same pluck query (or as a sub-select), then replace the two `pluck('id')` + `->whereIn('id', ...)` deletions with direct `->delete()` calls on the scoped queries. This eliminates the intermediate ID arrays.
        - If the alias tables grow large, move to a `LIMIT`-batched deletion loop (e.g. `while ($pgsql->table(...)->where(...)->limit(1000)->delete() > 0)`).
    - **Technical:** The command snapshots `$expiredHandleIds` and `$expiredSubdomainIds` via `pluck('id')` so that a TOCTOU rename between the count query and the delete doesn't skew the affected-pro list (the comment explains this correctly). At current scale this is correct and safe. The improvement is to capture the affected `user_id` set in the same pluck call (or a sub-select inside the delete), remove the intermediate ID collection entirely, and adopt a batched loop for future-proofing. The TOCTOU concern is addressed because the user_id list is still snapshot before the transaction.
    - **Plain English:** The nightly cleanup takes a snapshot of all expired parking permits before it tears any of them up, so it knows which car owners to notify afterward. This snapshot approach is sound, but at large scale it means holding a big list in memory before anything gets deleted. The cleaner approach — snapshot just the car owner information and delete in place — achieves the same result without the intermediate list.
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
