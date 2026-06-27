`★ Insight ─────────────────────────────────────`
Reading `ProcessImageVariantsJob` reveals two things DeepSeek missed: (1) the job already has a terminal-state idempotency guard — but it only covers `READY` and `FAILED`, leaving `PROCESSING` unguarded for concurrent redelivery. (2) When `dispatchSync` is used in the inline path and fails, the queue's `failed()` callback is never invoked, so the `markFailed()` helper inside the job never runs. The row ends up stuck in `processing`, not `pending`. The DeepSeek evidence was directionally correct but described the wrong stuck state.
`─────────────────────────────────────────────────`

# Lifecycle Audit — 2026-05-11

**Branch:** development
**Lens:** Lifecycle correctness: race-safety, idempotency, anchor decoupling, reconcile loops, vendor resilience, observability discipline
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Services/Media/BrandDesignMediaService.php
- app/Services/Media/ImageVariantService.php
- app/Services/Media/MediaDiskResolver.php
- app/Services/Media/VideoVariantService.php
- app/Services/Streaming/KickApiClient.php
- app/Services/Streaming/TwitchApiClient.php
- app/Services/Streaming/LiveStatusPoller.php
- app/Services/Streaming/LiveStatusInjector.php
- app/Services/Streaming/StreamingTokenManager.php
- app/Services/Hydrogen/HydrogenDeploymentService.php
- app/Services/Cloudflare/CloudflareDnsService.php
- app/Services/Cloudflare/CloudflareKvService.php
- app/Jobs/Cloudflare/ProvisionBrandDnsJob.php
- app/Jobs/Cloudflare/RetireBrandDnsJob.php
- app/Jobs/Cloudflare/RetireSubdomainFromKvJob.php
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php
- app/Jobs/Streaming/CheckStreamingLiveStatusJob.php
- app/Jobs/ProcessImageVariantsJob.php *(adjudicator-read)*
- app/Jobs/ProcessVideoVariantsJob.php *(adjudicator-read)*

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 11 complete
- P3 Low: 0 of 2 complete

---

## P1 — Fix before pilot launch

- [ ] **#LIFE-1** · P1 — `addPlaceholder` count-then-insert race allows exceeding the 5-placeholder limit
    - **Where:** app/Services/Media/BrandDesignMediaService.php — `addPlaceholder` method
    - **Affects:** Brand users uploading placeholder images; two concurrent uploads can both read `activeCount = 4`, pass the guard, and both insert — producing 6 placeholders for a hard-capped limit of 5.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `lockForUpdate()` to the `$activeCount` query inside the transaction so both reads see a consistent count under the row lock.
        - The existing `deletePlaceholder` method already uses `lockForUpdate()` correctly; apply the same pattern here to match.
    - **Technical:** The transaction wraps a `SELECT COUNT(*)` then an `INSERT`, but without `lockForUpdate` the Postgres transaction isolation level (read committed by default) allows two concurrent transactions to both read `4`, both pass the guard `< 5`, and both `INSERT`. This is the canonical `lockForUpdate + UNIQUE` race shape from commit `5735525`. The project already knows the pattern — `deletePlaceholder` applies it to the same table. At 200 brands × 50 affiliates, concurrent design uploads are a near-certainty and the limit exists for a business reason (UI slot constraints). Category (2).
    - **Plain English:** The system checks "how many placeholder images does this brand have?" and if it's under 5, allows the upload. But if two uploads arrive at almost exactly the same moment, both checks happen before either upload is recorded — both see 4, both get approved, and now the brand has 6 images in a 5-slot gallery. The fix is to make the "check and record" step a single uninterruptible action — like the way a supermarket checkout scanner claims an item before the next customer can scan the same one.
    - **Evidence:**
        ```php
        $media = DB::transaction(function () use ($site, $file) {
            $activeCount = SiteMedia::query()
                ->where('site_id', $site->id)
                ->where('pool', SiteMedia::POOL_DESIGN)
                ->where('purpose', SiteMedia::PURPOSE_PLACEHOLDER)
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->whereNotIn('processing_state', [SiteMedia::PROCESSING_STATE_FAILED])
                ->count();

            if ($activeCount >= self::PLACEHOLDER_MAX) {
                throw new PlaceholderLimitExceededException(self::PLACEHOLDER_MAX);
            }
            // ... INSERT follows without lockForUpdate on counted rows
        ```

---

## P2 — Should fix

- [ ] **#LIFE-2** · P2 — Log context missing `professional_id` in media services — Nightwatch correlation gap
    - **Where:** app/Services/Media/BrandDesignMediaService.php, app/Services/Media/ImageVariantService.php, app/Services/Media/VideoVariantService.php
    - **Affects:** On-call engineers debugging media failures; without the owning brand in every log line, a single failure requires a separate DB lookup to identify the affected customer.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Thread `brand_professional_id` (available on the `Site` model in `BrandDesignMediaService`) into every `Log::*` call in the media pipeline.
        - In `ImageVariantService` and `VideoVariantService`, add `site_id` to log context where only `image_id`/`media_id` is currently present — the callers have `site_id` and can pass it in.
    - **Technical:** The canonical `Log-with-context` pattern from the Stripe audit (`#STRIPE-2`, `35c6f31`) requires `brand_professional_id`, `request_id`, and operation name in every log context for Nightwatch correlation. `BrandDesignMediaService` includes `site_id` in some error logs but no `professional_id`; `ImageVariantService` and `VideoVariantService` log only `image_id`/`media_id` and `base_path`. At the scale target (~40K daily events, 200 brands), a single media-processing error requires a manual DB join to identify the affected brand. Category (10).
    - **Plain English:** When a brand's image upload fails, the error log says "image ABC123 failed" but doesn't say which brand owns it. The support engineer has to open a second tool to look up the brand. It's like a fire alarm that tells you which room is burning but not which building. Adding the brand identifier to every log line lets monitoring tools automatically group problems by customer and surface the right brand in the alert.
    - **Evidence:**
        ```php
        // BrandDesignMediaService — site_id present, professional_id absent:
        Log::error('BrandDesignMediaService: failed to store logo original.', [
            'site_id' => $site->id,
            'purpose' => $purpose,
            'error' => $e->getMessage(),
        ]);

        // ImageVariantService — no site or professional context:
        \Illuminate\Support\Facades\Log::info('Starting image variant processing', [
            'image_id' => $imageId,
            'base_path' => $basePath,
        ]);

        // VideoVariantService — same gap:
        Log::info('VideoVariantService: starting', [
            'media_id' => $mediaId,
            'base_path' => $basePath,
        ]);
        ```

- [ ] **#LIFE-3** · P2 — `ProvisionBrandDnsJob` retries Cloudflare without backoff — zero-delay retry storm
    - **Where:** app/Jobs/Cloudflare/ProvisionBrandDnsJob.php
    - **Affects:** Brand onboarding during a Cloudflare API degradation; 3 retries fire as fast as the queue can re-dispatch them, compounding an already-failing vendor API.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `public array $backoff = [10, 30, 60];` (or `public int $backoff = 30;`) so retries are spaced out.
    - **Technical:** The job declares `public int $tries = 3` with no `$backoff` property. Laravel's default backoff is 0 seconds, producing 3 near-instantaneous retries during an outage. The canonical pattern from the Stripe work is explicit `$tries` and `$backoff` (`9a9b107`). At the scale target (200 brands onboarding over time), a Cloudflare incident during a batch of completions produces up to 600 rapid API calls in milliseconds. Category (6).
    - **Plain English:** When the job that sets up a brand's web address hits a temporary Cloudflare outage, it immediately retries three times in a fraction of a second — like repeatedly slamming a stuck door instead of waiting a moment between tries. This makes the outage worse. A 30-second pause between attempts gives Cloudflare time to recover.
    - **Evidence:**
        ```php
        class ProvisionBrandDnsJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

            public int $tries = 3;

            public function __construct(public readonly string $professionalId) {}
        ```

- [ ] **#LIFE-4** · P2 — `RetireBrandDnsJob` retries Cloudflare without backoff
    - **Where:** app/Jobs/Cloudflare/RetireBrandDnsJob.php
    - **Affects:** Brand subdomain renaming — same retry-storm risk as LIFE-3.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `public array $backoff = [10, 30, 60];`.
    - **Technical:** Structurally identical to LIFE-3: `public int $tries = 3` with no `$backoff`. Zero-delay retries against Cloudflare DNS API during an outage. Canonical replacement: explicit `$tries` and `$backoff` (`9a9b107`). Category (6).
    - **Plain English:** Same as LIFE-3 — when removing a brand's old web address during a rename, failed retries fire instantly instead of waiting between attempts.
    - **Evidence:**
        ```php
        class RetireBrandDnsJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

            public int $tries = 3;

            public function __construct(public readonly string $subdomain) {}
        ```

- [ ] **#LIFE-5** · P2 — `RetireSubdomainFromKvJob` retries Cloudflare KV without backoff
    - **Where:** app/Jobs/Cloudflare/RetireSubdomainFromKvJob.php
    - **Affects:** Handle renaming — stale KV entries may persist if all rapid retries fail during an outage.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `public array $backoff = [10, 30, 60];`.
    - **Technical:** Structurally identical to LIFE-3/4. KV deletes use `->throw()` so failures propagate to the retry mechanism correctly, but zero backoff produces the same storm. Canonical: explicit `$tries` and `$backoff` (`9a9b107`). Category (6).
    - **Plain English:** Same pattern — when cleaning up a stale routing entry after a handle rename, the job retries instantly three times rather than pacing itself, hammering a degraded Cloudflare API.
    - **Evidence:**
        ```php
        class RetireSubdomainFromKvJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

            public int $tries = 3;

            public function __construct(public readonly string $handle) {}
        ```

- [ ] **#LIFE-6** · P2 — `SyncSubdomainToKvJob` retries Cloudflare KV without backoff
    - **Where:** app/Jobs/Cloudflare/SyncSubdomainToKvJob.php
    - **Affects:** All professionals — handle changes, brand link changes, and brand URL changes all dispatch this job. Higher dispatch frequency than the other three Cloudflare jobs combined.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `public array $backoff = [10, 30, 60];`.
    - **Technical:** Same pattern as LIFE-3/4/5 but higher-volume: dispatched from multiple observers (handle change, brand_partner_links change, brand URL change). At the scale target (200 brands × 50 affiliates), a Cloudflare KV outage during a burst of profile edits produces a proportionally larger storm of zero-delay retries. Canonical: explicit `$tries` and `$backoff` (`9a9b107`). Category (6).
    - **Plain English:** Every time any user updates their profile handle or brand link, this job syncs the change to Cloudflare's routing table. During a Cloudflare incident, all queued updates retry instantly three times in rapid succession — potentially hundreds of rapid-fire API calls from a normal batch of user activity.
    - **Evidence:**
        ```php
        class SyncSubdomainToKvJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

            public int $tries = 3;

            public function __construct(public readonly string $professionalId) {}
        ```

- [ ] **#LIFE-7** · P2 — `TwitchApiClient::getLiveHandles` returns `[]` for both API errors and "no streams live" — caller cannot distinguish
    - **Where:** app/Services/Streaming/TwitchApiClient.php — `getLiveHandles` catch block and non-2xx branch
    - **Affects:** All Twitch streaming blocks across every brand site; during a Twitch API outage or HTTP error, every Twitch streaming block is incorrectly written to Redis as `is_live = '0'` (offline) until the next successful poll 2+ minutes later.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Throw a typed exception (e.g., `TwitchApiException`) on non-2xx responses and `\Throwable` catches, or return a nullable typed result (`null` = error, `array` = checked result).
        - In `LiveStatusPoller::pollTwitch`, catch the new exception type and skip `writeStatus` for the affected batch — do not write false-negatives to Redis.
    - **Technical:** The catch-all returns `[]`, which `pollTwitch` interprets as "checked these handles, none are live" and writes `is_live = '0'` to Redis for every queried handle. The canonical pattern from the Stripe audit (`#STRIPE-2`, `35c6f31`) requires distinct outcomes to have distinct return paths: "a function with N distinct outcomes needs N distinct log strings, or a typed return so the caller can branch." The non-2xx branch has the same ambiguity. Note: `KickApiClient` has the structurally identical issue (see LIFE-8) — both must be fixed together. Category (6).
    - **Plain English:** When Twitch's servers are having trouble, the system cannot tell the difference between "we checked and no one is live" versus "we couldn't reach Twitch to check." It assumes everyone is offline and hides every "LIVE NOW" badge on every brand page for two minutes. It's like a security camera that shows an empty room not because the room is empty, but because the camera is unplugged — and the security guard can't tell the difference.
    - **Evidence:**
        ```php
        if (! $response->successful()) {
            Log::error('streaming.api_error', [
                'platform' => 'twitch',
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [];
        }
        // ...
        } catch (\Throwable $e) {
            Log::error('streaming.api_error', [
                'platform' => 'twitch',
                'message' => $e->getMessage(),
            ]);

            return [];
        }
        ```

- [ ] **#LIFE-8** · P2 — `KickApiClient::getLiveHandles` returns `[]` for both API errors and "no streams live"
    - **Where:** app/Services/Streaming/KickApiClient.php — catch block
    - **Affects:** All Kick streaming blocks — same false-offline degradation as LIFE-7 but for Kick.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Same fix as LIFE-7: throw a typed `KickApiException` or return `null` on error; update `pollKick` to skip `writeStatus` on error rather than writing false-negatives.
    - **Technical:** Structurally identical to LIFE-7. The `catch (\Throwable $e)` after the 429 re-throw returns `[]`, which `pollKick` interprets as "checked, nobody live." This writes `is_live = '0'` to Redis for every handle in the batch. The distinction from the `KickRateLimitException` path is notable: rate-limits are handled correctly (circuit breaker, no writes), but general errors produce false-negatives. Category (6).
    - **Plain English:** Same as LIFE-7 for Kick streamers — a Kick API error makes every Kick streaming badge go dark across all brand pages until the next successful poll.
    - **Evidence:**
        ```php
        } catch (KickRateLimitException $e) {
            throw $e; // poller handles
        } catch (\Throwable $e) {
            Log::error('streaming.api_error', [
                'platform' => 'kick',
                'message' => $e->getMessage(),
            ]);

            return [];
        }
        ```

- [ ] **#LIFE-9** · P2 — `SyncSubdomainToKvJob` silently swallows KV delete failure for unconnected affiliates — no retry, stale routing persists
    - **Where:** app/Jobs/Cloudflare/SyncSubdomainToKvJob.php — the `! $siteUrl` branch
    - **Affects:** Affiliates who remove their brand connection; their old handle subdomain continues routing to the former brand until a future successful sync, with no automatic retry when the delete fails.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Re-throw the caught `\Throwable` in the unconnected-affiliate branch, the same way `RetireSubdomainFromKvJob` does (`throw $e;`). This causes the queue to retry the job according to its `$tries` limit.
    - **Technical:** The `! $siteUrl` branch wraps `$kv->delete()` in a try/catch that logs a warning and returns without re-throwing. The job therefore "succeeds" from the queue's perspective and is not retried. Compare to `RetireSubdomainFromKvJob` which handles the identical operation with `throw $e` after logging, correctly triggering retry. The inconsistency means a KV delete failure for an unconnected affiliate leaves a stale routing entry (pointing the affiliate's subdomain to the old brand) indefinitely — no reconcile job covers this path. At 200 brands × 50 affiliates, brand-connection churns will be routine. Category (4).
    - **Plain English:** When an affiliate removes their brand partnership, the system tries to remove the old routing entry that redirected their profile link. If that deletion fails (Cloudflare outage), the failure is logged quietly but the job declares success and moves on. The result: the affiliate's link keeps redirecting to the old brand indefinitely. The fix is a one-line change that already exists in the sibling job — make the failure visible so the queue retries it.
    - **Evidence:**
        ```php
        if (! $siteUrl) {
            // No brand connection — remove entry so Worker falls back gracefully
            try {
                $kv->delete($pro->handle);
            } catch (\Throwable $e) {
                Log::warning('SyncSubdomainToKvJob: delete failed for unconnected affiliate', [
                    'professional_id' => $pro->id,
                    'handle' => $pro->handle,
                    'message' => $e->getMessage(),
                ]);
            }

            return;
        }
        ```
        ```php
        // RetireSubdomainFromKvJob.php — correct pattern, not followed in SyncSubdomainToKvJob:
        } catch (\Throwable $e) {
            Log::warning('RetireSubdomainFromKvJob: delete failed', [
                'handle' => $this->handle,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
        ```

- [ ] **#LIFE-10** · P2 — `ProcessVideoVariantsJob` idempotency guard skips terminal states only — concurrent redelivery runs FFmpeg twice
    - **Where:** app/Jobs/ProcessVideoVariantsJob.php — `handle` method, lines 78–95
    - **Affects:** Video-uploading brands; at-least-once queue delivery with a job still in `processing` state causes a second job instance to pass the guard and start a full duplicate FFmpeg encode + R2 upload.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a Redis `SET NX` lock keyed on `media_id` (TTL = `$encodingTimeout + 60s`) at the start of `handle()`, before the state is set to `processing`. Return early if the lock cannot be acquired (another job is actively processing this media).
        - The lock key should be distinct from the job ID so that a retried job after a crash can still acquire it (crash releases the key via TTL expiry).
    - **Technical:** The guard checks for terminal states `READY` and `FAILED` — correct to skip re-processing after completion. But if a job is mid-encode (`processing_state = processing`) and a second delivery arrives (at-least-once queue semantics guarantee this during worker restarts), the second job passes the guard and begins its own FFmpeg pipeline. `MediaVariant::updateOrCreate` provides DB-level idempotency for the resulting rows, so no data corruption occurs — but each full video encode costs multiple minutes of CPU and several R2 uploads. The canonical pattern is a Redis `SET NX` lock (`lockForUpdate + UNIQUE` applied to the job scope, commit `5735525`). At the scale target with video as a premium feature, concurrent encode waste is a real operating cost at 200 brands. `ProcessImageVariantsJob` has the same gap but the CPU cost is orders of magnitude smaller. Category (1).
    - **Plain English:** Video encoding is expensive — minutes of CPU and large file uploads. When a video job gets accidentally delivered twice (which happens in every distributed queue system), both copies check "is this already done?" They both see "processing" and both think "not done yet, let me encode." Two full video encodes run simultaneously for no reason. It's like two builders constructing the same wall at the same time — the result is fine but the work is doubled. A simple "one-at-a-time" lock on the media item before starting would prevent this.
    - **Evidence:**
        ```php
        // Guard skips READY and FAILED — but not PROCESSING:
        if (in_array($siteMedia->processing_state, [SiteMedia::PROCESSING_STATE_READY, SiteMedia::PROCESSING_STATE_FAILED], true)) {
            Log::info('ProcessVideoVariantsJob: already in terminal state, skipping.', [
                'media_id' => $this->mediaId,
                'processing_state' => $siteMedia->processing_state,
            ]);

            return;
        }

        SiteMedia::query()
            ->where('id', $this->mediaId)
            ->whereNull('deleted_at')
            ->update([
                'processing_state' => SiteMedia::PROCESSING_STATE_PROCESSING,
                'processing_error' => null,
            ]);
        // A second job delivery arriving here while first is mid-encode passes the guard above
        // and begins a duplicate encode
        ```

- [ ] **#LIFE-11** · P2 — Logo upload race between concurrent uploads: Row A soft-deleted mid-flight orphans R2 file and leaves Row B stuck in `pending`
    - **Where:** app/Services/Media/BrandDesignMediaService.php — `upsertLogoFromUploadedFile`, `upsertLogoFromBytes`, `createDesignRow`
    - **Affects:** Brand users with multiple team members uploading logos simultaneously; one upload's file is stored in R2 but its DB row is deleted by the concurrent upload, leaving the brand's logo dashboard stuck on "processing" indefinitely and an orphaned R2 object.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Store the original file to R2 **first** (before calling `createDesignRow`), using a deterministic path derived from the content hash. Then call `createDesignRow` and do the singleton-replace in the transaction — which commits the row with the path already known.
        - Alternatively, move the `storeOriginal` call inside the `createDesignRow` transaction. Since transactions cannot span external I/O idiomatically, the hash-first approach is cleaner.
    - **Technical:** `createDesignRow` commits a transaction that soft-deletes all prior rows for the given purpose and inserts a new row (Row A). File upload and path update happen outside any lock. Request B's `createDesignRow` can then run — it sees Row A (path still empty), soft-deletes it, creates Row B. Request A then calls `$media->update(['path' => $originalPath])` on the now-soft-deleted Row A. Eloquent's `performUpdate` applies global scopes including `SoftDeletes`, generating `WHERE id = ? AND deleted_at IS NULL` — this matches 0 rows and silently no-ops. `dispatchVariantJob` is then called for Row A's ID. `ProcessImageVariantsJob` (verified by adjudicator read) checks `if ($siteMedia->trashed()) { return; }` — the job bails out. Row B stays in `processing_state = 'pending'` forever; the R2 file at Row A's path is orphaned. This is the `lockForUpdate + UNIQUE` pattern from commit `5735525` applied to a split read–commit–write across external I/O. Category (2).
    - **Plain English:** Two team members upload a new logo at almost exactly the same moment. The first upload creates a "reserved" database slot, then starts uploading the image file. Before the file finishes, the second upload sees the first slot, deletes it, and creates its own slot. When the first upload finishes, it tries to fill its slot — but the slot has been deleted. The file ends up stored in your cloud storage with no database entry pointing to it, and the dashboard shows "processing…" forever because the second slot never got its file. The fix is to upload the file first, then claim the database slot — so the slot is always created with the file location already known.
    - **Evidence:**
        ```php
        // upsertLogoFromUploadedFile — file upload and path update outside the transaction:
        $media = $this->createDesignRow($site, $purpose, $file->getMimeType(), $file->getSize(), 0);
        // Transaction has committed here; Row $media->id is visible to concurrent requests

        $basePath = "images/{$proId}/{$media->id}";

        try {
            $originalPath = $this->images->storeOriginal($file, $basePath);
        } catch (Throwable $e) { ... }

        $media->update(['path' => $originalPath]); // silently no-ops if row was soft-deleted by concurrent request

        // createDesignRow — soft-deletes ALL active rows for this purpose then creates new:
        SiteMedia::query()
            ->where('site_id', $site->id)
            ->where('pool', SiteMedia::POOL_DESIGN)
            ->where('purpose', $purpose)
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->get()
            ->each(fn (SiteMedia $row) => $row->delete()); // deletes Row A if concurrent
        ```

- [ ] **#LIFE-12** · P2 — `dispatchVariantJob` swallows sync-path exceptions — SiteMedia row left permanently in `processing` state
    - **Where:** app/Services/Media/BrandDesignMediaService.php — `dispatchVariantJob` inline and sync-fallback catch blocks
    - **Affects:** In local/testing environments (sync queue): any image processing failure leaves the SiteMedia row stuck in `processing_state = 'processing'` indefinitely. In production: same outcome when queue dispatch fails AND the sync fallback also fails (e.g., during Redis degradation).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In both catch blocks, update the SiteMedia row to `processing_state = 'failed'` and `processing_error = $e->getMessage()` before returning — mirroring the `markFailed()` helper already implemented inside `ProcessImageVariantsJob` (adjudicator-verified).
        - Alternatively, extract the failure-marking logic into a shared `MediaStateService::markFailed(string $imageId, string $reason)` so both the job and the service use the same code path.
    - **Technical:** When `ProcessImageVariantsJob::dispatchSync()` fails, the exception propagates from `handle()` through `dispatchSync()` to `dispatchVariantJob`'s catch block. The queue framework's `failed()` callback is **not** invoked in sync/inline mode — it is only triggered by the queue worker when a job exhausts its `$tries`. Therefore `ProcessImageVariantsJob::markFailed()` (which sets `processing_state = 'failed'`) never runs. The job sets `processing_state = 'processing'` before doing work (adjudicator-verified at line 84–90 of `ProcessImageVariantsJob`), so a sync failure leaves the row in `processing`, not `pending`. The dashboard `listDesignMedia` renders pending/processing rows with an empty URL and a spinner — the brand sees a permanently spinning image with no way to clear it. The canonical pattern is the same as `#STRIPE-2` — distinct outcomes need distinct code paths with distinct log strings. Category (5). Also affects the production fallback sync path (outer `catch Throwable $sync` block).
    - **Plain English:** When an image fails to process on a local machine or test environment, the system notes the error in the logs but never updates the database to say "this image is broken." The brand's dashboard shows an image with a "loading" spinner that will never resolve — and there's no red X or retry button because the system thinks the image is still working on it. It's like a delivery tracker that's stuck on "out for delivery" because the delivery truck broke down but no one updated the status. The fix is a two-line addition to the catch block that marks the image as failed so the dashboard can show a clear error state.
    - **Evidence:**
        ```php
        // Inline path — exception swallowed, row stays in 'processing':
        if ($processInline) {
            try {
                ProcessImageVariantsJob::dispatchSync(
                    originalPath: $originalPath,
                    imageId: $imageId,
                    basePath: $basePath,
                );
            } catch (Throwable $e) {
                Log::error('BrandDesignMediaService: inline variant processing failed.', [
                    'image_id' => $imageId,
                    'error' => $e->getMessage(),
                ]);
                // failed() callback not invoked in sync mode — markFailed() never runs
            }

            return;
        }

        // Production fallback path — same gap:
        try {
            ProcessImageVariantsJob::dispatchSync(...);
        } catch (Throwable $sync) {
            Log::error('BrandDesignMediaService: sync fallback also failed.', [
                'image_id' => $imageId,
                'error' => $sync->getMessage(),
            ]);
        }
        ```

---

## P3 — Nice to have

- [ ] **#LIFE-13** · P3 — `upsertCname` always PATCHes `proxied` when content matches — unnecessary Cloudflare API round-trip
    - **Where:** app/Services/Cloudflare/CloudflareDnsService.php — `upsertCname` method, `$existing['content'] === $target` branch
    - **Affects:** Every brand onboarding and subdomain rename that calls `upsertCname`; makes an unconditional PATCH even when `proxied` is already in the desired state.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extend `findRecord` to include `proxied` in its return array (the Cloudflare list API response includes this field); then skip the PATCH when both `content` and `proxied` already match the desired state.
    - **Technical:** The code comment acknowledges the problem ("requires a fresh fetch as findRecord doesn't return it") but resolves it by always PATCHing rather than extending `findRecord`. At the scale target this causes harmless extra API calls during brand onboarding, but unconditional mutating vendor calls are an idempotency anti-pattern — they add latency and consume Cloudflare rate-limit budget unnecessarily. Category (6).
    - **Plain English:** Every time the system checks a brand's DNS record and finds it's already pointing to the right place, it still sends an unnecessary "update" request to Cloudflare just to set a toggle that's already where it needs to be — like adjusting a light switch that's already on. It doesn't break anything, but it's wasted work and a small extra charge on every brand setup.
    - **Evidence:**
        ```php
        if ($existing['content'] === $target) {
            // Check proxied state — requires a fresh fetch as findRecord doesn't return it.
            $response = Http::withToken($this->apiToken)
                ->patch($this->zonesUrl("/dns_records/{$existing['id']}"), [
                    'proxied' => $proxied,
                ]);
        ```
        ```php
        // findRecord return — 'proxied' absent despite being in Cloudflare's API response:
        return [
            'id' => (string) ($record['id'] ?? ''),
            'type' => (string) ($record['type'] ?? ''),
            'name' => (string) ($record['name'] ?? ''),
            'content' => (string) ($record['content'] ?? ''),
        ];
        ```

- [ ] **#LIFE-14** · P3 — `VideoVariantService::processVariants` clears `processing_error` on success — forensic error history lost on retry
    - **Where:** app/Services/Media/VideoVariantService.php — final `SiteMedia::update` call
    - **Affects:** Operations visibility; if a video fails on first attempt (e.g. OOM during FFmpeg encode) and succeeds on retry, the original error is overwritten with `null` and lost.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Only clear `processing_error` if it was previously non-null by checking `$siteMedia->processing_error !== null` before including it in the update, or preserve errors in a `processing_error_history` JSONB column.
    - **Technical:** The verbatim vendor error capture pattern (`bf6e46d`) establishes that error messages are hand-debuggable evidence — rephrasing or erasing destroys signal. A transient OOM during FFmpeg would appear in the log, succeed on retry, and then be silently erased from the DB record. At the scale target with video as a premium feature, transient OOM events would be invisible in the DB, hiding capacity trends from operators. `ProcessImageVariantsJob` has the same pattern but image jobs are far less resource-intensive. Category (10).
    - **Plain English:** If a video fails to encode the first time because the server ran out of memory, the system records "out of memory" on the file. When the retry succeeds, it erases that note — so no one ever knows there was a near-miss capacity problem. It's like a doctor curing a patient but shredding the chart entry that showed what went wrong the first time. Keeping the error history lets the team spot patterns before they become outages.
    - **Evidence:**
        ```php
        SiteMedia::query()
            ->where('id', $mediaId)
            ->whereNull('deleted_at')
            ->update([
                'processing_state' => SiteMedia::PROCESSING_STATE_READY,
                'processing_error' => null,
                'duration_ms' => $durationMs,
                'poster_path' => $posterRemotePath,
            ]);
        ```
