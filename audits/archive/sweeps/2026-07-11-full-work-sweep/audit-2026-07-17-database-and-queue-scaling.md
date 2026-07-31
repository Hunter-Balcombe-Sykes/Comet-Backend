# Database & Queue Scaling Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Database & queue scaling — N+1, unbounded reads, connection scoping, queue shape, vendor budgets, migration safety, backpressure
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `app/Models/Core/Site/Site.php`
- `app/Http/Resources/UserDashboardResource.php`
- `app/Http/Resources/PublicSite/IndividualProfileResource.php`
- `app/Services/Cloudflare/CloudflarePurgeService.php`
- `app/Services/Cloudflare/CloudflareCustomHostnameService.php`
- `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php`
- `app/Jobs/Platforms/InstagramConnectJob.php`
- `app/Jobs/Platforms/MenuFetchJob.php`
- `app/Models/Core/Site/Menu.php`, `MenuItem.php`
- `app/Services/Analytics/ContentFreshness.php`
- `app/Console/Commands/CleanupOrphanedLifestyleConnections.php`
- `config/horizon.php`
- `app/Services/Http/SafeUrlFetcher.php` (cross-check only)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 2 complete

---

## P2 — Should fix

- [ ] **#SCALE-1** · P2 — `CloudflarePurgeService::purgeUrls` fires unbounded sequential Cloudflare API calls with no inter-chunk delay
    - **Where:** app/Services/Cloudflare/CloudflarePurgeService.php:66-73
    - **Affects:** Every profile-edit / image-upload / design-kit-change cache purge (`CloudflareCachePurgeJob` → `purgeHandle`). The method's own docblock documents that a full sitepage purge (root + 15 deep-link sub-pages + their SWR shadows + API subrequest, per host, plus up to 100 shop product handles) routinely exceeds the 30-URL-per-request limit, so most real purges already fire multiple sequential POSTs — this isn't a rare bulk-admin edge case, it's the common path.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a small delay (e.g. 100–200ms) between chunk POSTs in `purgeUrls`, or switch to `Http::pool` with a bounded concurrency so chunks aren't fired back-to-back with zero pacing.
        - No change needed to worker concurrency — `config/horizon.php`'s `supervisor-cloudflare` already caps this queue at `maxProcesses: 2`, which bounds cross-job concurrency; this fix only addresses intra-job chunk pacing.
    - **Technical:** Cloudflare's `purge_cache` endpoint accepts at most 30 URLs per request on non-Enterprise plans. `purgeUrls` correctly chunks into batches of 30 but the `foreach` fires each chunk's HTTP POST immediately with no pacing. Since a single `purgeHandle()` call for one profile can already produce 10+ chunks (per the method's own docblock), and up to 2 Horizon workers can run this concurrently (`supervisor-cloudflare` `maxProcesses: 2`), a handle with many shop products can produce a burst of near-simultaneous requests against the same zone. This is defense-in-depth today (pre-beta traffic is low) but will become a real 429/retry-amplification risk as product-catalog size and edit frequency grow.
    - **Plain English:** Every time someone edits their page, we tell Cloudflare "forget these cached copies" — but if there are a lot of cached copies (which is now the normal case, not rare), we fire off all those "forget" requests back-to-back with zero pause between them. It's like a courier making 15 trips to the same loading dock without ever slowing down — eventually the dock says "too many, come back later" and the requests start failing and retrying, which makes the pile-up worse.
    - **Evidence:**
        ```php
        // Cloudflare's purge_cache `files` accepts at most 30 URLs per request on
        // non-Enterprise plans. A full sitepage purge (root + 15 deep-link
        // sub-paths + each one's SWR shadow + the API subrequest) exceeds that, so
        // chunk into <=30-URL batches — one POST each.
        foreach (array_chunk(array_values($urls), 30) as $chunk) {
            Http::withToken($this->apiToken)
                ->asJson()
                ->acceptJson()
                ->post($this->url(), ['files' => $chunk])
                ->throw();
        }
        ```

- [ ] **#SCALE-2** · P2 — `InstagramConnectJob::mirrorOne` buffers the full image body in memory instead of streaming, unlike the sibling `mirrorVideo` path in the same file
    - **Where:** app/Jobs/Platforms/InstagramConnectJob.php:330-338
    - **Affects:** The `scraping` Horizon queue worker (`supervisor-scraping`, `memory: 256`, `maxProcesses: 2`) during Instagram auto-connect. Each connect can mirror up to three images (photo, reel poster, profile pic) at up to 15MB each.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Mirror `mirrorVideo`'s pattern: fetch with `Http::sink($tmp)` to a temp file, size-check via `filesize()`, then stream the temp file to R2 via `fopen()`, instead of reading `$response->body()` into a PHP string.
        - Keep the existing `Content-Length` fast-rejection and the post-fetch hard cap — only the transport mechanism changes.
    - **Technical:** `mirrorOne` fetches with `$response->body()`, buffering the entire response into a PHP string before the size check and before the `Storage::put()` call — up to `MAX_IMAGE_BYTES` (15MB) held in memory per call, and `mirrorOne` can be invoked up to 3 times per job. The sibling `mirrorVideo` in the same file already solved this correctly (`Http::sink($tmp)` → stream to R2), so this is an inconsistency within one file rather than a systemic gap. Given `supervisor-scraping` caps at `maxProcesses: 2` with a 256MB Horizon memory limit per worker, actual OOM risk today is bounded, not imminent — but it's a real asymmetry against an established in-file pattern and worth fixing opportunistically rather than as urgent hardening.
    - **Plain English:** When we mirror someone's Instagram photo, we read the entire picture into the computer's short-term memory before saving it — even though we already know how to stream video straight to storage without doing that (we do it correctly two functions below in the same file). It's an inconsistency: one path is careful, the other isn't. Fixing the photo path to match the video path closes a real (if currently modest) risk of the server running low on memory during a busy signup period.
    - **Evidence:**
        ```php
            $body = $response->body();

            // Hard cap enforced after buffering — covers absent or inaccurate
            // Content-Length headers. Nothing over the limit reaches R2.
            if (strlen($body) > self::MAX_IMAGE_BYTES) {
                return null;
            }

            Storage::disk('media')->put($path, $body);
        ```

## P3 — Nice to have

- [ ] **#SCALE-3** · P3 — `Site::designKitVars()` issues a raw per-instance query with no batch-loading alternative (latent N+1)
    - **Where:** app/Models/Core/Site/Site.php:179-201
    - **Affects:** `SiteResource::toArray()` and `StaffUserController`'s diagnostic endpoint, both of which are single-`Site` responses today (`SiteResource` has zero `::collection()` call sites in the codebase). Purely a latent risk, not a current one.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - No urgent action needed. If a future staff/dashboard multi-site list endpoint is introduced, eager-load `site.design_kits` in bulk (`WHERE site_id IN (...)`) before mapping to `SiteResource` rather than calling `designKitVars()` per row.
        - Cheaper guard in the meantime: add a test asserting `SiteResource` is never constructed via `::collection()`, so the trap can't be introduced silently.
    - **Technical:** `designKitVars()` runs `DB::connection('pgsql')->table('site.design_kits')->where('site_id', $this->id)->first()` unconditionally, bypassing Eloquent relations entirely (by design — writes go through a matching raw builder in `UserSiteController::writeDesignKit`). This is one query per call, and today every call site (`SiteResource`, `StaffUserController`) operates on a single site, so the cost is exactly one query per request. This same finding was already identified and tiered P3 in the 2026-07-10 sweep (`audits/sweeps/2026-07-10-new-work-sweep`) with the same "no live fan-out endpoint" conclusion, which still holds — re-confirmed via `Grep` for `SiteResource::collection` (no matches).
    - **Plain English:** Each site's visual-style settings live in a separate little box from the main site record. Right now we only ever open one box per request because we only ever show one site at a time. Nothing stops a future "show me all my sites" feature from accidentally opening a separate box for every site in the list — worth a lightweight guardrail so that mistake can't happen silently later, but it isn't hurting anyone today.
    - **Evidence:**
        ```php
        public function designKitVars(): array
        {
            try {
                $row = DB::connection('pgsql')
                    ->table('site.design_kits')
                    ->where('site_id', $this->id)
                    ->first();
            } catch (\Throwable) {
                // Fail-closed to "no stored vars": the editor falls back to package
                // defaults exactly as before this field existed. Also covers the
                // SQLite test mirror, which doesn't create site.design_kits.
                return [];
            }

            if ($row === null) {
                return [];
            }
        ```

- [ ] **#SCALE-4** · P3 — `SyncSubdomainToKvJob` has no explicit rate-limit middleware on Cloudflare KV writes, relying only on implicit Horizon worker-count throttling
    - **Where:** app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:56-64, :122
    - **Affects:** Every profile mutation that triggers a KV sync (handle changes, moderation actions, connect/disconnect). A mass moderation event or bulk handle-update could queue many jobs at once.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add an explicit `RateLimited` middleware (mirroring the `platform-connect` pattern already used by `InstagramConnectJob`/`MenuFetchJob`) as defense-in-depth, so KV write throughput stays bounded even if `supervisor-cloudflare`'s `maxProcesses` is ever raised for unrelated reasons.
        - Not urgent: `config/horizon.php`'s `supervisor-cloudflare` already caps this queue at `maxProcesses: 2` (explicitly commented as "Cloudflare's API is rate-limited, not CPU-bound"), and each job issues at most 3 Cloudflare HTTP calls (1 handle `put`, 1 optional custom-domain `put`, 1 batched `bulkPut` for aliases — the alias fan-out was already fixed to a single bulk call, per the file's own "SCALE-6" comment). Two concurrent workers × 3 requests is far below Cloudflare KV's write-rate budget.
    - **Technical:** `SyncSubdomainToKvJob` has no `middleware()` method, so nothing throttles KV write throughput independent of worker count. In practice, `config/horizon.php`'s `supervisor-cloudflare` (`maxProcesses: 2`, `nice: 5`) already provides substantial implicit backpressure — this was designed in deliberately ("Low process count: Cloudflare's API is rate-limited, not CPU-bound"), and the alias-write fan-out is already batched into one `bulkPut`. The remaining gap is that this protection is *implicit* (tied to a supervisor config value someone could change without realizing it removes the rate-limit) rather than *explicit* (a `RateLimited` middleware tied to Cloudflare's actual documented budget, as `InstagramConnectJob`/`MenuFetchJob` already do for the `platform-connect` budget). Worth closing for consistency, not urgent given current bounds.
    - **Plain English:** The system that syncs a user's web address to Cloudflare currently stays safe only because we've limited how many workers can run this job at once — like a shop that only lets two clerks work the till, so the line never gets too long even without a formal "one customer at a time" sign. It works today, but it's an accident of a different setting rather than a deliberate rule, so if someone later increases that worker count for an unrelated reason, this safety net disappears without anyone noticing.
    - **Evidence:**
        ```php
            public function __construct(
                public readonly string $userId,
                public readonly ?string $capturedHandle = null,
                public readonly ?string $retireCustomDomain = null,
            ) {
                // Isolated from user-facing work so a burst of platform-connection writes
                // can't delay notifications or mail delivery.
                $this->onQueue(config('partna.queues.cloudflare', 'cloudflare'));
            }
        ```
        ```php
        $kv->put($current, ['type' => 'individual'], null);
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Cloudflare API rate-limit hardening:** #SCALE-1, #SCALE-4
    - **Why grouped:** Same vendor (Cloudflare), same root theme (outbound write-rate defense-in-depth), touch adjacent files in `app/Services/Cloudflare` and `app/Jobs/Cloudflare`.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).

## Standalone — do NOT bundle

- **#SCALE-2 — InstagramConnectJob::mirrorOne memory streaming** · different subsystem (media pipeline, not Cloudflare) and file (`app/Jobs/Platforms/`) from the bundled pair; no shared dependency worth coordinating.
- **#SCALE-3 — Site::designKitVars latent N+1 guard** · different subsystem (`app/Models/Core/Site/`), low urgency (purely latent, no live fan-out path), stands alone.
