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
