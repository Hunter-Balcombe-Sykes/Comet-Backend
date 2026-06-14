
<!-- ═══ LENS: observability | CHUNK: jobs-hooks ═══ -->

- [ ] **#OBS-1** · P1 — `InstagramConnectJob` dispatched to `scraping` queue with no Horizon supervisor in any environment
    - **Where:** `app/Jobs/Platforms/InstagramConnectJob.php:79`
    - **Affects:** Every Instagram connect attempt — the job sits unprocessed forever, the connection stays in `pending` status, and the user never sees their Instagram content.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `supervisor-scraping` entry to `config/horizon.php` for all environments (production, development, local) that processes the `scraping` queue.
        - Alternatively, change `$this->onQueue('scraping')` to an existing queue like `default` or add a dedicated `platforms` queue with a supervisor.
    - **Technical:** `InstagramConnectJob` calls `$this->onQueue('scraping')` in its constructor, but no Horizon supervisor in `config/horizon.php` lists `scraping` in its `queue` array — not in production, development, or local. In production the 10 supervisors cover `moderation_high`, `notifications`, `mail`, `default`, `cloudflare`, `cache-warm`, `analytics`, `images`, `streaming`, `gdpr`, and `videos` — none include `scraping`. The job is pushed to Redis but never dequeued. Horizon shows no failed jobs, no exceptions, no alerts — the queue depth silently grows with every Instagram connect.
    - **Plain English:** The Instagram connect job is handed to a delivery driver who doesn't exist. The job sits in the warehouse forever with no-one picking it up. The system shows "all clear" because no job has crashed — it just never started. Every person connecting Instagram sees "connecting…" indefinitely.
    - **Evidence:**
        ```php
        // InstagramConnectJob constructor
        $this->onQueue('scraping');
        ```
        ```
        // config/horizon.php production supervisors — scraping absent from all:
        'supervisor-moderation-high' => ['queue' => ['moderation_high']],
        'supervisor-notifications'  => ['queue' => ['notifications', 'mail']],
        'supervisor-default'        => ['queue' => ['default']],
        'supervisor-streaming'      => ['queue' => ['streaming']],
        'supervisor-analytics'      => ['queue' => ['analytics']],
        'supervisor-cloudflare'     => ['queue' => ['cloudflare']],
        'supervisor-cache-warm'     => ['queue' => ['cache-warm']],
        'supervisor-images'         => ['queue' => ['images']],
        'supervisor-gdpr'           => ['queue' => ['gdpr']],
        'supervisor-videos'         => ['queue' => ['videos']],
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#OBS-2** · P1 — `RefreshSmartLinksCommand` silently swallows all exceptions without reporting to Nightwatch
    - **Where:** `app/Console/Commands/RefreshSmartLinksCommand.php:70-74`
    - **Affects:** On-call engineers — a systemic smart-link refresh failure (broken scraper, schema drift) produces zero Nightwatch alerts. Every link refresh fails silently every 6 hours with only `Log::warning` breadcrumbs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report($e)` before the `Log::warning` call in the catch block, matching the pattern already used in `RefreshIntegrationConnectionsCommand`.
    - **Technical:** The `catch (\Throwable $e)` block in the per-link loop logs a warning and increments `$failed`, but never calls `report($e)` or re-throws. Nightwatch auto-detects exceptions — it does NOT fire on log queries. A `Log::warning` is invisible to on-call unless someone is actively tailing Cloud logs. `RefreshIntegrationConnectionsCommand` (same directory, same pattern) correctly calls `report($e)` in its equivalent catch block. The command always returns `self::SUCCESS` regardless of how many links failed, so the cron exit code is also green.
    - **Plain English:** If the SmartLink refresher breaks, every scheduled run logs "something went wrong" in a diary that nobody reads unless they already know to look. No alarm fires. The command exits saying "all good." The sibling command for integration connections correctly sets off the alarm — this one forgot to wire it up.
    - **Evidence:**
        ```php
        // RefreshSmartLinksCommand — missing report($e)
        } catch (\Throwable $e) {
            $failed++;
            Log::warning('smartlinks:refresh failed for a link', [
                'smart_link_id' => $link->id,
                'message' => $e->getMessage(),
            ]);
        }

        // RefreshIntegrationConnectionsCommand — correct pattern with report($e)
        } catch (\Throwable $e) {
            report($e);
            $failed++;
            Log::warning('integrations:refresh failed for a connection', [...]);
        }
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#OBS-3** · P2 — `InstagramConnectJob` silently drops individual image mirroring failures with no log or report
    - **Where:** `app/Jobs/Platforms/InstagramConnectJob.php:174-177` and `app/Jobs/Platforms/InstagramConnectJob.php:187-189`
    - **Affects:** Users whose Instagram connect partially succeeds — some images appear in their gallery, others silently vanish with no observability. Engineers cannot distinguish "Instagram returned only 5 images" from "10 images were fetched but 5 failed to write to R2."
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `Log::warning(...)` with image index and error message in both empty catch blocks (`mirrorAllParallel` and `mirrorOne`), so partial failures leave a breadcrumb in Cloud logs.
        - Consider calling `report($e)` for R2 write failures so sustained storage outages surface as Nightwatch exception events.
    - **Technical:** In `mirrorAllParallel()`, the per-image loop catches `Throwable` on `Storage::disk('media')->put(...)` with an empty catch block and a `// skip, don't abort the whole batch` comment. In `mirrorOne()`, the same pattern — empty catch, returns null. No log call, no counter increment, no `report()`. The parent `handle()` method sees a partial image list and writes it as the connection payload with only an `imagesDropped` count derived from URL filtering, not from write failures. A persistent R2 write failure affecting 50% of images would produce zero Nightwatch events and zero log entries distinguishing it from normal operation.
    - **Plain English:** When mirroring Instagram photos to storage, if one photo fails to save, the system shrugs and moves on without writing down what happened. The user sees fewer photos than expected. No alarm fires. An engineer investigating can't tell whether Instagram didn't send those photos or whether the storage bucket dropped them on the floor.
    - **Evidence:**
        ```php
        // mirrorAllParallel — empty catch, no log
        try {
            Storage::disk('media')->put($path, $response->body());
            $out[] = Storage::disk('media')->url($path);
        } catch (Throwable) {
            // Single image write failure — skip, don't abort the whole batch.
        }

        // mirrorOne — empty catch, no log
        } catch (Throwable) {
            return null;
        }
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#OBS-4** · P2 — Three long-running artisan commands lack `$timeout` properties, invisible to Nightwatch slow-command detection
    - **Where:** `app/Console/Commands/GcOrphanedVideoArtifactsCommand.php`, `app/Console/Commands/PurgeRawAnalyticsEvents.php`, `app/Console/Commands/PurgeSoftDeleted.php`
    - **Affects:** On-call visibility — a hung S3 LIST operation in the GC command or a multi-million-row analytics purge that takes 45 minutes produces no Nightwatch slow-command alert.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `public int $timeout` properties to each command: `GcOrphanedVideoArtifactsCommand` (~600), `PurgeRawAnalyticsEvents` (~600), `PurgeSoftDeleted` (~900).
    - **Technical:** `GcOrphanedVideoArtifactsCommand` calls `$disk->allFiles('videos')` which performs an unbounded S3 LIST operation. `PurgeRawAnalyticsEvents` loops with `LIMIT 10000` batch deletes that can run many iterations. `PurgeSoftDeleted` chunks through multiple models with forceDelete(). None declare a `$timeout` property. Nightwatch auto-detects slow jobs/routes/commands based on execution time exceeding a baseline, but without a declared timeout it cannot flag a command that hangs or runs abnormally long as anomalous. The command would just sit there until the PHP `max_execution_time` or a SIGKILL from the process manager.
    - **Plain English:** Three scheduled cleanup tasks can take a very long time to run — one scans every video file in storage, another deletes old analytics rows, a third hard-deletes old records across many tables. If any of them gets stuck or takes 10× longer than normal, Nightwatch won't notice because nobody told it what "too long" means for these tasks. They'd just quietly consume resources until something else breaks.
    - **Evidence:**
        ```php
        // GcOrphanedVideoArtifactsCommand — no $timeout property
        foreach ($disk->allFiles('videos') as $file) { ... }

        // PurgeRawAnalyticsEvents — no $timeout property
        do { $count = DB::table($table)->where(...)->limit(self::BATCH_SIZE)->delete(); ... } while (...);

        // PurgeSoftDeleted — no $timeout property
        $modelClass::onlyTrashed()->where(...)->chunk(500, function ($rows) { ... });
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#OBS-5** · P2 — `CheckStreamingLiveStatusJob` returns successfully when Redis is unavailable, masking a total streaming-poll failure
    - **Where:** `app/Jobs/Streaming/CheckStreamingLiveStatusJob.php:47-52`
    - **Affects:** Streaming-status accuracy — when Redis is down, the entire 2-minute poll cycle is silently skipped. Horizon shows a completed job; the `report($e)` call does fire a Nightwatch exception, but the job's "success" counter is misleading.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Call `$this->fail($e)` after `report($e)` instead of `return`, so the job lands in Horizon's failed-jobs counter and the retry mechanism can re-attempt when Redis recovers. Since `$tries=1`, it would still only run once, but the failure would be visible in failed-jobs metrics.
    - **Technical:** The early-return pattern `report($e); return;` after catching a Redis failure means the job completes as "successful" in Horizon. While `report($e)` does generate a Nightwatch exception event (on-call would see it), the Horizon dashboard shows a clean green job. More importantly, the per-platform poll errors in the second catch block use the same pattern — `report($e)` then continue to the next platform. A sustained Twitch API outage produces one exception per cycle (visible in Nightwatch) but the job still shows as completed, and the Kick poll still runs in the same iteration. This is defensible for the per-platform loop (one broken platform shouldn't block others), but the Redis guard at the top should fail the job since NO polling can proceed.
    - **Plain English:** When the streaming-status job can't reach Redis, it quietly goes home early and marks itself "done." An alarm does fire because of the exception report, but anyone checking the job dashboard sees a string of green successes and doesn't realize no streaming checks actually ran that hour.
    - **Evidence:**
        ```php
        try {
            $kickRateLimited = Redis::exists('streaming:kick:rate_limited');
        } catch (\Throwable $e) {
            Log::error('streaming.redis_unavailable', ['message' => $e->getMessage()]);
            report($e);
            return;  // job completes successfully, no poll ran
        }
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#OBS-6** · P3 — `SendTransactionalNotificationEmailJob` emits `Log::debug()` in production paths without environment guard
    - **Where:** `app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php:96-98` and `:105-107`
    - **Affects:** Cloud log volume — debug-level messages for skipped notifications (feature-flag off, no mailable registered) pollute production logs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the `Log::debug()` calls in `if (config('app.debug'))` or `if (app()->isLocal())` guards, or demote the messages further (remove them if they add no production value).
    - **Technical:** Two `Log::debug()` calls fire on normal production control-flow paths: when the notification email feature flag is disabled, and when a category has no registered mailable class. In production these conditions are static configuration — they don't change at runtime — so every invocation of this job for those categories produces a debug log line. Laravel's default production log level is `debug`, so these do land in Cloud logs. While not a correctness issue, they add noise to production log searches and inflate log volume for a frequently-dispatched notification job.
    - **Plain English:** Every time the system decides "no email needed for this notification" (which is normal and expected), it writes a sticky note about it. In production, thousands of these sticky notes pile up in the log storage, making it harder to find the ones that matter.
    - **Evidence:**
        ```php
        if (! config('partna.notifications.email_enabled', false)) {
            Log::debug('Notification email skipped: feature disabled', [
                'category' => $this->category,
            ]);
            return;
        }
        // ...
        if (! is_string($class) || ! class_exists($class)) {
            Log::debug('Notification email skipped: category has no mailable', [
                'category' => $this->category,
            ]);
            return;
        }
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#OBS-7** · P3 — `WarmPublicSiteCacheJob` silently swallows §28.8 warm failures with only a `Log::warning`
    - **Where:** `app/Jobs/Cache/WarmPublicSiteCacheJob.php:82-86`
    - **Affects:** First-visitor latency — if the §28.8 cache warm fails persistently (e.g., DB unreachable for the Professional/Site lookup), every first visitor to a just-published site hits a cold cache with no observability of the sustained degradation.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - The `Log::warning` is appropriate for transient failures (the documented intent), but add a `report($e)` call so a sustained failure — e.g., DB connection pool exhaustion persisting across multiple publish events — surfaces as a Nightwatch exception with rising frequency.
    - **Technical:** The try/catch around the §28.8 warm path catches `\Throwable` and calls only `Log::warning(...)`. The primary `$siteCache->warmSiteCache($subdomain)` call is outside the try and correctly propagates failures. The §28.8 path is explicitly documented as best-effort. However, a sustained failure in this path (e.g., a DB connection issue that affects only the `User::query()->where('handle_lc', ...)` lookup) would produce warnings every 2 minutes per publish but never fire a Nightwatch alert. Since this is a secondary cache-warm path and the primary warm already alerts, this is a P3 hygiene gap rather than a P1 silent failure.
    - **Plain English:** The cache-warmer has two jobs: warm the main cache (if this fails, alarms fire) and warm a secondary cache (if this fails, the system shrugs and writes a note). The secondary failure is harmless once or twice. But if the secondary cache keeps failing all day, nobody gets alerted — the notes pile up unread. This is like a backup backup generator that fails silently because the main generator is still running.
    - **Evidence:**
        ```php
        } catch (\Throwable $e) {
            Log::warning('WarmPublicSiteCacheJob: §28.8 warm failed', [
                'subdomain' => $subdomain,
                'message' => $e->getMessage(),
            ]);
        }
        ```
    - `[DRAFT, confidence: 0.75]`

<!-- ═══ LENS: observability | CHUNK: vendor-services ═══ -->

- [ ] **OBS-1** · P1 — KickApiClient::getLiveHandles swallows all non-rate-limit exceptions silently
    - **Where:** app/Services/Streaming/KickApiClient.php:85-90
    - **Affects:** Streaming live-status pipeline — the poller receives an empty `[]` indistinguishable from "all handles are offline"; no Nightwatch alert fires.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `catch (\Throwable $e)` with specific catch clauses for expected transport errors; re-throw or call a dedicated `report()` + `$this->fail()` pattern.
        - For the fallback case where the job isn't the call site, have `getLiveHandles` throw a domain exception so `LiveStatusPoller::pollKick` can catch it, log structured context, and mark the poll cycle as failed — triggering a Horizon-retried job failure.
    - **Technical:** The `catch (\Throwable $e)` block logs `streaming.api_error` and returns `[]`. Because `LiveStatusPoller::pollKick` only catches `KickRateLimitException`, this silent empty-array return propagates into `writeStatus` calls that mark every handle offline. Horizon shows the job completed successfully; Nightwatch never sees the underlying API failure. A single DNS blip, TLS error, or Kick API outage produces a full poll cycle of "everyone offline" with zero observability.
    - **Plain English:** When Kick's API is unreachable, the system quietly assumes every streamer is offline and updates all their statuses to "not live." Nobody gets paged, and the dashboard shows no errors — it just looks like a very quiet day on Kick. The fix is to make sure a failed API call looks like a loud failure, not a normal empty result.
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
    - `[DRAFT, confidence: 0.95]`

- [ ] **OBS-2** · P1 — TwitchApiClient::getLiveHandles swallows all exceptions silently
    - **Where:** app/Services/Streaming/TwitchApiClient.php:73-80
    - **Affects:** Streaming live-status pipeline (same category as OBS-1).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Same fix as OBS-1: narrow the catch clause to known transport exceptions; let unexpected failures propagate or throw a domain exception.
        - Ensure `LiveStatusPoller::pollTwitch` catches that domain exception and surfaces it as a Horizon-visible job failure.
    - **Technical:** Identical pattern: `catch (\Throwable $e)` → `Log::error(...)` → `return []`. The poller interprets an empty live set identically to "all handles offline." A Twitch API outage manifests as a complete poll cycle with zero live handles and no alert.
    - **Plain English:** If Twitch's servers have a hiccup, Partna silently marks every streamer as offline. The monitoring dashboard stays green, the support team gets no alert, and streamers' sitepages show them as offline for the entire poll window. The fix is the same as for Kick — surface the failure, don't hide it.
    - **Evidence:**
        ```php
        } catch (\Throwable $e) {
            Log::error('streaming.api_error', [
                'platform' => 'twitch',
                'message' => $e->getMessage(),
            ]);

            return [];
        }
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **OBS-3** · P1 — StreamingTokenManager::refreshToken swallows all exceptions; failed token refresh is invisible
    - **Where:** app/Services/Streaming/StreamingTokenManager.php:97-104
    - **Affects:** Every Twitch and Kick API call that depends on a valid bearer token — the entire streaming live-status pipeline.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `refreshToken`, after logging, throw a domain-specific `StreamingAuthFailureException` instead of returning null.
        - In `getToken`, catch that exception and either bubble it up (so callers fail noisily) or implement a circuit-breaker that prevents repeated retries against a broken credential set.
        - Ensure the calling jobs (`CheckStreamingLiveStatusJob` or equivalent) call `$this->fail($e)` so Horizon and Nightwatch surface the auth outage.
    - **Technical:** `refreshToken` wraps the OAuth token-fetch HTTP call in `try { ... } catch (\Throwable $e) { Log::critical(...); return null; }`. The `Log::critical` is a breadcrumb — it writes to Cloud logs but fires no Nightwatch alert. `getToken` sees null and returns null to callers like `KickApiClient::getLiveHandles`, which then short-circuits with another `Log::critical` + `return []`. The entire streaming status pipeline runs "successfully" with every handle marked offline, job completes green, and a `critical`-severity log line sits unread in Cloud logs.
    - **Plain English:** When the credentials that let Partna talk to Twitch or Kick stop working, the system logs a "critical" error to a file nobody watches in real time, then quietly tells every streamer's page "not live." It's like a security guard writing "front door lock is broken" in a notebook in the basement and then telling everyone the building is secure. The fix is to make the broken lock set off the alarm.
    - **Evidence:**
        ```php
        } catch (\Throwable $e) {
            Log::critical('streaming.auth_failure', [
                'platform' => $platform,
                'message' => $e->getMessage(),
            ]);

            return null;
        } finally {
            Redis::del($lockKey);
        }
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **OBS-4** · P1 — InstagramScraper::fetchProfile swallows all Throwable; paid Apify scrape fails silently
    - **Where:** app/Services/Platforms/InstagramScraper.php:38-42
    - **Affects:** The Instagram connect flow and any Instagram profile display — users and support see a generic "couldn't fetch" with no alert.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Convert the `catch (Throwable $e)` block to `Log::error(...)` with full context, then re-throw as a domain exception (`InstagramScrapeFailedException`).
        - Let the caller (controller or refresh job) catch that exception and decide whether to mark the connection as failed or retry — but ensure the failure is surfaced to Horizon/Nightwatch.
    - **Technical:** `fetchProfile` wraps the Apify `run-sync-get-dataset-items` call in a broad `catch (Throwable $e)` → `Log::warning(...)` → `return null`. Apify calls are paid per execution and can fail for credential, quota, or API reasons. A silent null return means the connect flow fails with no operator alert, and repeated cron retries burn quota invisibly. The `Log::warning` is a breadcrumb — nobody is watching Cloud logs for Instagram scrape failures in real time.
    - **Plain English:** Instagram scraping costs money (Apify charges per run). When it fails — maybe the API key expired, maybe the quota ran out — the system writes a note in a log file and moves on. Nobody gets paged, and if nobody happens to read that log, the feature silently breaks and keeps burning paid quota on retries. The fix is to make a failed paid scrape trigger an alert, not a whisper.
    - **Evidence:**
        ```php
        } catch (Throwable $e) {
            Log::warning('instagram.apify.threw', ['username' => $username, 'user_id' => $userId, 'error' => $e->getMessage()]);

            return null;
        }
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **OBS-5** · P2 — dispatchImageJob silently discards failures; image stays PENDING forever with no alert
    - **Where:** app/Services/Media/MediaUploadService.php:280-318
    - **Affects:** Any image upload where Redis is unreachable at dispatch time or the queue worker cannot pick up the job — the image row stays in `processing_state = 'pending'` indefinitely with no Nightwatch alert.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - In the final `catch (Throwable $syncError)` block, update the SiteMedia row to `processing_state = 'failed'` with `processing_error` set, so the user sees the failure and can retry.
        - Emit a structured `Log::error` with full context AND throw a domain exception that a scheduled health-check or dead-letter scanner can detect.
        - Consider a scheduled command that scans for `processing_state = 'pending'` rows older than N minutes and raises an alert.
    - **Technical:** The `dispatchImageJob` method is intentionally best-effort (comment says "NEVER throws"), but the fallback chain catches both the async dispatch failure AND the sync fallback failure with only a `Log::error`. The SiteMedia row remains in PENDING state, the user sees no error (the upload controller returned success because the row was created), and no Nightwatch alert fires. This asymmetry with `dispatchVideoJob` (which throws → rollback → 503 to user) is deliberate per the docstring, but a PENDING row that never transitions is a silent failure that needs automated detection.
    - **Plain English:** When someone uploads an image and the queue system is down, the app tells them "upload successful!" but the image sits in a permanent "processing" state and never actually appears. It's like a restaurant telling you your order is on the way while the kitchen is on fire. The fix is to either tell the user the upload failed, or have an automated watchdog that notices orders stuck in the kitchen and alerts the manager.
    - **Evidence:**
        ```php
        try {
            ProcessImageVariantsJob::dispatchSync(
                originalPath: $originalPath,
                imageId: $imageId,
                basePath: $basePath,
            );
        } catch (Throwable $syncError) {
            Log::error('Synchronous image variant processing also failed.', [
                'image_id' => $imageId, 'error' => $syncError->getMessage(),
            ]);
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **OBS-6** · P2 — StaffAuditService::record swallows all Throwable; sustained audit write failures are invisible
    - **Where:** app/Services/Audit/StaffAuditService.php:39-48
    - **Affects:** The staff audit trail — every staff action (impersonations, user lookups, admin changes) that occurs during a database outage leaves no record and no alert.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Keep the "audit must not block staff actions" contract, but add a side-channel alert: after catching the `Throwable`, increment a Redis counter (`staff_audit:consecutive_failures`) with a TTL, and have a scheduled health-check read that counter and fire a Nightwatch-visible alert when it exceeds a threshold.
        - Alternatively, dispatch a `LogFailedAuditEntryJob` to a separate queue that retries the write with backoff and fails loudly if it cannot persist after N attempts.
    - **Technical:** The method wraps the `StaffAuditEntry::create()` call in `try { ... } catch (Throwable $e) { Log::warning(...); return null; }`. The documented intent — "audit-log unavailability must never block a staff action" — is correct, but the implementation provides no observability when the audit log is persistently unavailable. A database outage affecting the `audit.staff_audit_log` table would cause every staff action to proceed without an audit trail, with only a `Log::warning` breadcrumb that nobody actively monitors. The `request_id` in the log context helps post-hoc correlation but does not trigger real-time alerting.
    - **Plain English:** When the audit log database has a problem, staff members can still do their jobs — which is good, because an audit system shouldn't block real work. But right now, nobody gets told that the audit trail is broken. It's like a security camera that silently stops recording but still has its little red light on. The fix is to make the camera notify someone when it stops recording, without blocking the door.
    - **Evidence:**
        ```php
        } catch (Throwable $e) {
            Log::warning('staff.audit.write_failed', [
                'exception' => $e->getMessage(),
                'route' => $route,
                'http_method' => $httpMethod,
                'request_id' => request()?->header('X-Request-Id'),
            ]);

            return null;
        }
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **OBS-7** · P3 — Log level mismatch: `Log::critical` used for recoverable auth-failure paths in streaming clients
    - **Where:** app/Services/Streaming/KickApiClient.php:53, app/Services/Streaming/TwitchApiClient.php:47, app/Services/Streaming/StreamingTokenManager.php:95,103
    - **Affects:** On-call triage — `critical` severity in Cloud logs is typically reserved for "wake someone up" events, but these paths recover gracefully and continue serving other handles/platforms.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Downgrade `Log::critical` to `Log::error` for the recoverable paths (null token → skip platform, API error → return empty).
        - Reserve `Log::critical` for failures that actually require immediate human intervention (e.g., both Twitch AND Kick token refresh failing simultaneously for >5 minutes), and gate that with a separate detection mechanism.
    - **Technical:** `KickApiClient::getLiveHandles` logs `streaming.auth_failure` at `critical` when `$this->tokens->getToken('kick')` returns null, then returns `[]`. Same pattern in `TwitchApiClient` and `StreamingTokenManager::refreshToken`. These are recoverable degradations — other platforms continue polling, and the poll cycle completes normally. Using `critical` here pollutes the severity taxonomy and trains on-call to ignore `critical`-level log entries, which reduces the signal value of genuinely critical events.
    - **Plain English:** The streaming system labels routine hiccups as "CRITICAL EMERGENCY" in its logs. After seeing a hundred "critical" alerts that turned out to be nothing, the team will start ignoring them — which means when a real emergency happens, it gets lost in the noise. The fix is to use the right label for the right problem: "error" for recoverable issues, "critical" for ones that need a human right now.
    - **Evidence:**
        ```php
        // KickApiClient.php:53
        if (! $token) {
            Log::critical('streaming.auth_failure', ['platform' => 'kick']);
            return [];
        }
        ```
        ```php
        // StreamingTokenManager.php:95
        if (! $response->successful()) {
            Log::critical('streaming.auth_failure', [
                'platform' => $platform,
                'status' => $response->status(),
            ]);
            return null;
        }
        ```
    - `[DRAFT, confidence: 0.90]`
