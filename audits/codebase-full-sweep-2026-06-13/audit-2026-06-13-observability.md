# Observability Audit — 2026-06-13

**Branch:** development
**Lens:** Observability — logging gaps, silent failures, missing Nightwatch instrumentation
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-5`
**Source files audited:**
- `app/Jobs/Platforms/InstagramConnectJob.php`
- `app/Jobs/Streaming/CheckStreamingLiveStatusJob.php`
- `app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php`
- `app/Jobs/Cache/WarmPublicSiteCacheJob.php`
- `app/Services/Streaming/KickApiClient.php`
- `app/Services/Streaming/TwitchApiClient.php`
- `app/Services/Streaming/StreamingTokenManager.php`
- `app/Services/Platforms/InstagramScraper.php`
- `app/Services/Media/MediaUploadService.php`
- `app/Services/Audit/StaffAuditService.php`
- `app/Console/Commands/RefreshSmartLinksCommand.php`
- `app/Console/Commands/RefreshIntegrationConnectionsCommand.php`
- `app/Console/Commands/GcOrphanedVideoArtifactsCommand.php`
- `app/Console/Commands/PurgeSoftDeleted.php`
- `app/Console/Commands/PurgeRawAnalyticsEvents.php`
- `app/Console/Commands/CleanupStuckMediaProcessingCommand.php`
- `config/horizon.php`
- `routes/console.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 6 complete
- P2 Medium: 0 of 4 complete
- P3 Low: 0 of 4 complete

---

## P1 — Fix before pilot launch

- [ ] **#OBS-1** · P1 — `RefreshSmartLinksCommand` swallows all per-link exceptions without alerting Nightwatch
    - **Where:** `app/Console/Commands/RefreshSmartLinksCommand.php:51–57`
    - **Affects:** On-call engineers — a systemic smart-link refresh failure (broken scraper, expired API key, schema drift) produces zero Nightwatch exception events. Every 6-hour run logs warnings, exits `SUCCESS`, and the scheduler's `onFailure()` hook never fires because the command always returns `self::SUCCESS`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report($e)` before `Log::warning(...)` in the `catch (\Throwable $e)` block, matching the pattern already used in `RefreshIntegrationConnectionsCommand` (line 54 of that file).
        - Confirm this is sufficient — `report()` routes to the exception handler which fires Nightwatch.
    - **Technical:** `RefreshSmartLinksCommand::handle()` always returns `self::SUCCESS` regardless of failure count, so the scheduler's `->onFailure()` callback in `routes/console.php` is dead for this command. The per-link `catch (\Throwable $e)` block increments `$failed` and calls `Log::warning(...)` — a breadcrumb. `RefreshIntegrationConnectionsCommand` (structurally identical command, same directory) has `report($e)` in its equivalent catch block and a comment explaining why (`// Surface to Nightwatch — the continue-on-error loop otherwise turns a systemic failure into N silent warnings`). The absence in `RefreshSmartLinksCommand` is an inconsistency, not a design choice.
    - **Plain English:** The smart-link refresher runs every six hours to keep product links and event dates up to date. If the underlying data source breaks, every run silently logs "something went wrong" to a diary that nobody reads unless they already know to look. No alarm fires. The exit code is green. The identical command for platform integrations correctly raises an alarm — this one forgot to wire it up. One line added brings it in line.
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

        // RefreshIntegrationConnectionsCommand — correct pattern (line 50–60)
        } catch (\Throwable $e) {
            // Surface to Nightwatch — the continue-on-error loop otherwise turns
            // a systemic failure (broken scraper, schema drift) into N silent
            // warnings + a healthy-looking summary, with zero exception events.
            report($e);
            $failed++;
            Log::warning('integrations:refresh failed for a connection', [...]);
        }
        ```

- [ ] **#OBS-2** · P1 — `InstagramScraper::fetchProfile` swallows transport exceptions; paid Apify failures are silent and the calling job completes as "succeeded"
    - **Where:** `app/Services/Platforms/InstagramScraper.php:43–47`; secondary: `app/Jobs/Platforms/InstagramConnectJob.php:78–82`
    - **Affects:** Instagram connect reliability — a transport exception (Apify token expired, quota exhausted, network timeout) is swallowed as `Log::warning`, the caller receives `null`, `InstagramConnectJob` marks the connection `last_refresh_status='unavailable'` and returns normally. Horizon shows "succeeded." No Nightwatch alert fires. Repeated Apify runs burn quota against a broken credential with zero operator visibility.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `fetchProfile()`, add `report($e)` before `return null` in the `catch (Throwable $e)` block. This promotes transport failures from a `Log::warning` breadcrumb to a Nightwatch exception event without changing the caller's null-handling contract.
        - In `InstagramConnectJob::handle()`, consider whether `markFailed() + return` is appropriate for transport failures vs. Apify returning bad data. Transport failures (caught exception, non-200 status) may be transient — calling `$this->fail(new RuntimeException('Apify scrape failed: ' . $profile_error))` instead of returning would engage the job's backoff schedule (`[30, 120]` seconds, `$tries = 2`). The current `return` skips retries for the same failure class that `markFailed('apify_fetch_failed')` is intended to handle.
    - **Technical:** `fetchProfile()` wraps the Apify HTTP call in `catch (Throwable $e)` → `Log::warning('instagram.apify.threw', ...)` → `return null`. Apify calls are paid per execution; credential failure or quota exhaustion causes every retry to burn budget silently. `InstagramConnectJob::handle()` checks `if (! $profile)` at line 78 and calls `markFailed($connection, 'apify_fetch_failed')` then `return`, completing the job successfully. The `failed()` method (which calls `report($e)`) is only reached if an unhandled exception propagates — which is prevented by `fetchProfile()`'s catch.
    - **Plain English:** When the Instagram scraper breaks — maybe the API key ran out, maybe Apify raised its prices and the quota ran dry — the system silently writes "couldn't fetch" in a log file, marks the user's Instagram as unavailable, and files the job under "done." No alarm fires. Meanwhile, every automated retry keeps spending money on a broken service. The fix is a one-line addition that makes the alarm ring when this happens, so someone can check the Apify dashboard before more money is wasted.
    - **Evidence:**
        ```php
        // InstagramScraper::fetchProfile — transport exception swallowed
        } catch (Throwable $e) {
            Log::warning('instagram.apify.threw', ['username' => $username, 'user_id' => $userId, 'error' => $e->getMessage()]);

            return null;
        }

        // InstagramConnectJob::handle — null → job returns normally, no retry, no alert
        if (! $profile) {
            $this->markFailed($connection, 'apify_fetch_failed');

            return;
        }
        ```

- [ ] **#OBS-3** · P1 — `TwitchApiClient::getLiveHandles` swallows all transport exceptions; a Twitch outage silently marks every streamer offline
    - **Where:** `app/Services/Streaming/TwitchApiClient.php:71–78`
    - **Affects:** Streaming live-status accuracy — a Twitch API outage, TLS error, or DNS failure causes the poll cycle to return `[]` (no live handles), mark every Twitch streamer as offline, and complete the job as "succeeded." No Nightwatch alert fires.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report($e)` in the `catch (\Throwable $e)` block before `return []`. This is sufficient because `CheckStreamingLiveStatusJob` already has a per-platform `catch (\Throwable $e)` that calls `report($e)` — alternatively, re-throw here and let the job's outer catch handle it consistently. Either approach makes the failure Nightwatch-visible.
        - Also add `report()` or throw on the non-200 response path (lines 53–63), which currently only logs `Log::error('streaming.api_error', ['platform' => 'twitch', 'status' => ...])` without reporting.
    - **Technical:** The `catch (\Throwable $e)` block at line 71 logs `streaming.api_error` at `error` level and returns `[]`. `LiveStatusPoller::pollTwitch()` interprets `[]` identically to "all handles offline" and writes that status to every Twitch-connected block. `CheckStreamingLiveStatusJob`'s per-platform catch (which calls `report($e)`) is only reachable if the poller's `pollTwitch()` throws — but `getLiveHandles()` prevents that by swallowing. The non-200 HTTP path (lines 53–63) has the same gap.
    - **Plain English:** When Twitch has an outage, Partna's streaming system quietly assumes everyone is offline and updates every streamer's page accordingly. The monitoring dashboard stays green, no-one is paged, and streamers' visitors see them as "not live" for the entire outage window. The fix is one line — `report($e)` — that makes this failure sound the alarm.
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

- [ ] **#OBS-4** · P1 — `KickApiClient::getLiveHandles` swallows all non-rate-limit exceptions; Kick outage silently marks every streamer offline
    - **Where:** `app/Services/Streaming/KickApiClient.php:98–105`; secondary: `app/Services/Streaming/KickApiClient.php:44–47` (auth failure path)
    - **Affects:** Streaming live-status accuracy — identical root cause and impact to OBS-3. Additionally, the auth-failure early-return (null token) uses `Log::critical` but no `report()`, so a missing/expired Kick token triggers no Nightwatch alert.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report($e)` in the `catch (\Throwable $e)` block before `return []`.
        - Add `report()` on the non-200 path (lines 61–71) and on the null-token path (lines 44–47).
        - Same approach as OBS-3: either report-and-return or re-throw to let the job's outer catch handle it.
    - **Technical:** Three distinct failure paths in `getLiveHandles()` all return `[]` without reporting: (1) `$token === null` (line 44) — logs `Log::critical` but no `report()`; (2) non-200 response (line 61) — logs `Log::error` but no `report()`; (3) transport exception (line 98) — logs `Log::error` but no `report()`. The `KickRateLimitException` path (line 96) correctly re-throws to the poller. All other failure modes return `[]` and produce a silent "all Kick streamers offline" poll result. `Log::critical` is not an alert mechanism — it is a breadcrumb.
    - **Plain English:** When Kick's API is unreachable or the access token expires, the system silently assumes every Kick streamer is offline. The word "CRITICAL" appears in the log file but no alarm rings — it's the equivalent of labelling a sticky note "URGENT" and leaving it on a desk in a locked room. The fix is the same one-liner as for Twitch: make these failures ring the alarm.
    - **Evidence:**
        ```php
        // Auth-failure path — Log::critical without report()
        if (! $token) {
            Log::critical('streaming.auth_failure', ['platform' => 'kick']);

            return [];
        }

        // Transport-exception path — Log::error without report()
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

- [ ] **#OBS-5** · P1 — `StreamingTokenManager::refreshToken` swallows auth-credential failures and returns null; the entire streaming pipeline fails silently
    - **Where:** `app/Services/Streaming/StreamingTokenManager.php:81–103`
    - **Affects:** The entire Twitch and Kick streaming pipeline — a null token causes both `KickApiClient` and `TwitchApiClient` to log-and-return-`[]`, marking every streamer offline across both platforms with no Nightwatch alert. This is the upstream root cause that amplifies OBS-3 and OBS-4.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `refreshToken()`, after the non-200 response check (line 81), throw a domain exception (`StreamingAuthException` or similar) instead of returning null.
        - In the `catch (\Throwable $e)` block (line 97), throw after logging (or call `report($e)` before returning null) so the caller chain (`getLiveHandles()` → `pollKick()/pollTwitch()` → job catch) can surface the failure via the job's existing `report($e)` call.
        - If throwing from the service is undesirable, add `report($e)` here directly — it is the correct escalation point since it has full context (`$platform`, `$e->getMessage()`).
    - **Technical:** `refreshToken()` has two silent-failure paths: (a) non-200 HTTP response → `Log::critical(...)` → `return null`; (b) `catch (\Throwable $e)` → `Log::critical(...)` → `return null`. `getToken()` propagates null to callers. `KickApiClient::getLiveHandles()` and `TwitchApiClient::getLiveHandles()` treat null token as a `Log::critical` + `return []` — also no `report()`. The chain of four null-returns means a broken OAuth endpoint or expired client secret produces `Log::critical` entries in Cloud logs but zero Nightwatch exception events and zero Horizon failed-job entries. `Log::critical` is a log-level label; it does not fire an alert.
    - **Plain English:** The streaming system uses a shared "key card" (an OAuth token) to talk to Twitch and Kick. If that key card stops working — the secret expired, the OAuth server is down — the system writes "CRITICAL: key card broken" in a logbook in the basement, then quietly tells every streamer's page "not live." Nobody upstairs is notified. The fix is to make a broken key card trigger the building alarm, not just a basement note.
    - **Evidence:**
        ```php
        if (! $response->successful()) {
            Log::critical('streaming.auth_failure', [
                'platform' => $platform,
                'status' => $response->status(),
            ]);

            return null;
        }
        // ...
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

- [ ] **#OBS-6** · P1 — `InstagramConnectJob` dispatches to a `scraping` queue that has no Horizon supervisor in any environment — jobs are silently enqueued and never processed
    - **Where:** `app/Jobs/Platforms/InstagramConnectJob.php:64`; `config/horizon.php:244–313`
    - **Affects:** Every Instagram connect attempt — the connection stays in `last_refresh_status = 'pending'` permanently, users never see their Instagram content, the Horizon dashboard shows no failures (queue depth grows silently, no job ever dequeues).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `'scraping'` to the `queue` array of an appropriate supervisor in `config/horizon.php`. The job's 150s timeout makes it a poor fit for `default` (60s supervisor timeout); add a dedicated `supervisor-platforms` in `defaults` with `timeout: 180, maxProcesses: 2` and include it in the `production` and `development` environment overrides. The `development` `supervisor-1` array should also get `'scraping'` appended.
        - Alternatively, rename the queue in the job from `'scraping'` to `'default'` or `'streaming'` if isolation is no longer needed, and remove the planned-but-unimplemented scraping supervisor.
    - **Technical:** `InstagramConnectJob::__construct()` calls `$this->onQueue('scraping')`. Horizon `config/horizon.php` defines 10 supervisors in `defaults` covering: `moderation_high`, `notifications`, `mail`, `default`, `cloudflare`, `cache-warm`, `analytics`, `images`, `streaming`, `gdpr`, `videos`. The word `scraping` does not appear anywhere in `config/horizon.php`. In `development`, the single `supervisor-1` covers `['moderation_high', 'notifications', 'mail', 'default', 'cloudflare', 'cache-warm', 'analytics', 'images', 'streaming']` — no `scraping`. Jobs pushed to an unsupervised queue remain in Redis indefinitely. Horizon's success/failure counters don't increment because the job never starts. No Nightwatch alert fires.
    - **Plain English:** The Instagram connect task is handed to a courier service that doesn't exist. The package sits in the warehouse forever — no delivery attempted, no error logged, no alarm raised. The system dashboard shows "all clear" because nothing has crashed; the package just hasn't moved. Every person who tries to connect their Instagram account sees a permanent "connecting…" spinner. Adding one queue name to the Horizon config file is all that's needed to assign an actual courier.
    - **Evidence:**
        ```php
        // InstagramConnectJob — dispatches to unsupervised queue
        public function __construct(
            public readonly string $userId,
            public readonly string $username,
            public readonly string $connectionId,
        ) {
            $this->onQueue('scraping');
        }
        ```
        ```
        // config/horizon.php production environment — 'scraping' absent from all supervisors:
        'production' => [
            'supervisor-moderation-high' => [...],
            'supervisor-notifications'   => [...],
            'supervisor-default'         => [...],
            'supervisor-cloudflare'      => [...],
            'supervisor-cache-warm'      => [...],
            'supervisor-analytics'       => [...],
            'supervisor-streaming'       => [...],
            'supervisor-images'          => [...],
            'supervisor-gdpr'            => [...],
            'supervisor-videos'          => [...],
            // No supervisor-scraping or supervisor-platforms
        ],
        ```

---

## P2 — Should fix

- [ ] **#OBS-7** · P2 — `InstagramConnectJob` silently drops per-image R2 write failures with empty catch blocks; partial gallery failures are invisible
    - **Where:** `app/Jobs/Platforms/InstagramConnectJob.php:178–180` (mirrorAllParallel); `:212–214` (mirrorOne)
    - **Affects:** Instagram gallery completeness — if R2 write fails for individual images (storage outage, disk quota, network error), those images are silently dropped and the connection payload records fewer images than Instagram returned. Engineers cannot distinguish "Instagram sent fewer images" from "R2 rejected the writes."
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In the `mirrorAllParallel` empty catch, add `Log::warning('instagram.mirror.write_failed', ['index' => $i, 'error' => $e->getMessage()])` to create a searchable breadcrumb. Replace the `catch (Throwable)` with `catch (Throwable $e)`.
        - In `mirrorOne`, same — add a structured `Log::warning` with `path` and error context.
        - For persistent R2 outages (more than N images dropped in one job run), add `report($e)` to escalate to Nightwatch. A counter pattern (`if (++$failures > 3) report($e)`) limits alert noise while surfacing sustained storage degradation.
    - **Technical:** Both catch blocks are explicitly commented as intentional (`// Single image write failure — skip, don't abort the whole batch.`), which is correct — one R2 write failure should not abort an 8-image parallel fetch. The problem is `catch (Throwable)` (no variable) which makes it impossible to even log the error. A sustained R2 outage would silently produce 0-image payloads with `imagesDropped` counting all images as "URL-filtered" drops rather than write failures, giving the wrong diagnosis signal.
    - **Plain English:** When saving Instagram photos to storage, if a photo fails to save, the system moves on — which is fine, one bad photo shouldn't cancel the rest. But right now, when a photo does fail, absolutely nothing is written down about why or which photo. If the storage system breaks during the middle of a connection, the user ends up with fewer photos than Instagram sent, and an engineer investigating can't tell whether Instagram just didn't send those photos or whether the storage bucket rejected them.
    - **Evidence:**
        ```php
        // mirrorAllParallel — empty catch, no log, variable dropped
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

- [ ] **#OBS-8** · P2 — All 18 `onFailure()` scheduler callbacks use `Log::error` only; scheduled command crashes are invisible to Nightwatch
    - **Where:** `routes/console.php:36–41` (representative); pattern repeated across all 18 `->onFailure(...)` registrations in the file
    - **Affects:** Every scheduled maintenance task — `purge-soft-deletes`, `analytics:purge-raw-events`, `smartlinks:refresh`, `integrations:refresh`, `handles:prune-expired-aliases`, `gdpr:sweep-stale-exports`, `media:gc-orphaned-video-artifacts`, `moderation:sla-scan`, and 10 others. A command that crashes (unhandled exception, fatal error, OOM kill) produces only a `Log::error` breadcrumb. No Nightwatch exception event fires. The file's own convention comment (`→ surfaces silent maintenance failures to Nightwatch`) is incorrect.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - For each `onFailure()` callback, add `if ($e !== null) { report($e); }` before the `Log::error` call. When `$e` is null (background-process commands where the exception is unavailable), create a synthetic exception: `report($e ?? new \RuntimeException('Scheduled task failed: <task-name>'))`.
        - Update the convention comment at the top of the file to document this pattern accurately.
        - Note: commands that always return `self::SUCCESS` (e.g. `RefreshSmartLinksCommand`) will never trigger `onFailure()`. Those need `report($e)` inside their own catch loops (see OBS-1). The `onFailure()` fix addresses commands that actually crash.
    - **Technical:** Laravel's `->onFailure(function (?Throwable $e = null))` fires when a scheduled command exits non-zero. For commands run via `->runInBackground()`, the command executes in a subprocess and `$e` is null (the exception occurred in a separate process). For in-process commands, `$e` may be populated. In all 18 registrations the callback body is only `Log::error(...)`. `Log::error` writes to Cloud logs (viewable via `cloud env:logs`) but does NOT fire a Nightwatch exception event — Nightwatch alerts on exceptions routed through `report()` or the exception handler, not on log-level labels. The misleading convention comment may cause future developers to believe the alerting is already wired when it is not.
    - **Plain English:** Every scheduled maintenance task has a "what to do if something goes wrong" callback. The comment above it says these callbacks "surface failures to Nightwatch" — but they don't. All 18 of them write a note in a log file that nobody reads unless they already know something broke. It's like labelling an emergency button "CALL AMBULANCE" and having it print a sticky note instead of dialing 911. The fix is four extra characters — `report($e)` — added to each callback, which makes failures actually trigger the alarm system.
    - **Evidence:**
        ```php
        // routes/console.php — representative pattern (repeated 18 times)
        // * ->onFailure(...)          — surfaces silent maintenance failures to Nightwatch.
        // (comment is incorrect — Log::error does not surface to Nightwatch)

        Schedule::command('partna:purge-soft-deletes')
            ->dailyAt('03:20')
            // ...
            ->onFailure(function (?Throwable $e = null): void {
                Log::error('Scheduled task failed: purge-soft-deletes', [
                    'exception' => $e ? get_class($e) : null,
                    'message' => $e?->getMessage(),
                ]);
                // Missing: if ($e !== null) { report($e); }
                // Or: report($e ?? new \RuntimeException('Scheduled task failed: purge-soft-deletes'));
            });
        ```

- [ ] **#OBS-9** · P2 — `MediaUploadService::dispatchImageJob` swallows the final sync-fallback failure; a SiteMedia row stays `PENDING` indefinitely with no Nightwatch alert
    - **Where:** `app/Services/Media/MediaUploadService.php:413–417`
    - **Affects:** Image upload reliability during Redis or worker outages — if both async dispatch and the sync fallback fail, the SiteMedia row stays in `processing_state = 'pending'` forever. The user sees the upload as permanently "processing." The existing `CleanupStuckMediaProcessingCommand` watchdog (scheduled hourly) only sweeps `PROCESSING` rows, not `PENDING` rows, so there is no automated recovery path.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In the final `catch (Throwable $syncError)` block, add `report($syncError)` so a sustained dispatch failure (Redis down, worker misconfiguration) surfaces as a Nightwatch exception event with rising frequency — visible as a pattern, not a one-off.
        - Separately, consider extending `CleanupStuckMediaProcessingCommand` to also sweep `PENDING` rows older than a threshold (e.g., 30 minutes), transitioning them to `FAILED` state so the user sees a clear error rather than an eternal spinner.
    - **Technical:** `dispatchImageJob()` is intentionally best-effort and "NEVER throws" (documented in the method docblock). The design is correct for normal transient queue failures. The gap is that the final fallback failure only logs `Log::error(...)` — a breadcrumb. A Redis outage that persists through multiple upload events produces multiple silent `Log::error` lines and no Nightwatch alert. The user sees "processing" until someone manually inspects Cloud logs. The `CleanupStuckMediaProcessingCommand` sweeps `PROCESSING_STATE_PROCESSING` (line 50 of that command) but the row never leaves `PENDING` in this path, so the watchdog cannot recover it.
    - **Plain English:** When someone uploads an image and both the normal queue system AND the emergency fallback fail, the app tells the user "upload successful!" but the image never processes. It sits in a "processing" limbo forever — the user sees a spinner that never resolves. The automated cleanup that finds stuck images looks for ones that started processing, not ones that never started. Adding one line makes the alarm sound when this happens so an engineer can investigate. Adding PENDING rows to the cleanup watchdog gives a safety net to automatically resolve stuck cases.
    - **Evidence:**
        ```php
        // Final sync fallback — failure only logged, never reported
        } catch (Throwable $syncError) {
            Log::error('Synchronous image variant processing also failed.', [
                'image_id' => $imageId, 'error' => $syncError->getMessage(),
            ]);
            // Missing: report($syncError)
            // Missing: SiteMedia row updated to 'failed' state
        }
        ```

- [ ] **#OBS-10** · P2 — `StaffAuditService::record` swallows all Throwable; sustained audit-write failures are invisible and produce no side-channel alert
    - **Where:** `app/Services/Audit/StaffAuditService.php:46–57`
    - **Affects:** Staff audit trail completeness during database outages — every staff action (impersonation, user lookup, admin change) during a `audit.staff_audit_log` table outage proceeds without an audit record. Only a `Log::warning` breadcrumb is written. No Nightwatch alert fires. The monitoring dashboard shows no anomaly.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Keep the "audit must not block staff actions" contract — do not throw. Instead, add a side-channel alert: after catching the `Throwable`, call `report($e)` once per failure. This triggers a Nightwatch exception event while allowing the staff action to proceed.
        - To avoid a Nightwatch flood during a sustained DB outage, gate the `report()` with an incremental Redis counter (`staff_audit:consecutive_failures`) with a short TTL; only `report()` when the counter crosses a threshold (e.g. 3 consecutive failures).
        - The `request_id` in the existing log context is a good forensics hook but not an alerting mechanism.
    - **Technical:** The method wraps `StaffAuditEntry::query()->create(...)` in `try { ... } catch (Throwable $e) { Log::warning('staff.audit.write_failed', ...); return null; }`. The documented intent (audit unavailability must never block staff actions) is correct. The gap: a persistent DB connectivity issue affecting only the `audit` schema would cause every staff action to proceed without an audit trail, with only `Log::warning` breadcrumbs accumulating in Cloud logs. No on-call notification fires. The `request_id` field aids post-hoc correlation but requires someone actively scanning logs to discover the outage. Staff actions during an un-noticed audit outage are functionally unlogged.
    - **Plain English:** If the audit log database has a problem, staff members can keep doing their jobs — which is by design, because an audit system should never block real work. The problem is that nobody gets told the audit trail is broken. It's like a security camera that quietly stops recording but keeps its red "recording" light on. Staff actions continue as if everything is normal, but the record of what they did silently disappears. The fix keeps the "staff can always proceed" contract but also makes the broken camera trigger a notification so someone can fix it.
    - **Evidence:**
        ```php
        } catch (Throwable $e) {
            // B3/P2-12: request_id correlates the warning to the NGINX/Cloudflare
            // access log entry — same pattern as FeatureFlagService / NotificationPublisher.
            Log::warning('staff.audit.write_failed', [
                'exception' => $e->getMessage(),
                'route' => $route,
                'http_method' => $httpMethod,
                'request_id' => request()?->header('X-Request-Id'),
            ]);

            return null;
        }
        ```

---

## P3 — Nice to have

- [ ] **#OBS-11** · P3 — `CheckStreamingLiveStatusJob` completes as "succeeded" in Horizon when Redis is unavailable, even though zero polling occurred
    - **Where:** `app/Jobs/Streaming/CheckStreamingLiveStatusJob.php:39–43`
    - **Affects:** Horizon dashboard fidelity — a Redis unavailability event that prevents all streaming polls is recorded as a successful job run. `report($e)` does fire (Nightwatch alerts correctly), but Horizon's succeeded counter increments instead of failed, masking the pattern in queue throughput metrics.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `report($e); return;` with `$this->fail($e)`, which both reports to Nightwatch AND marks the job as failed in Horizon. Since `$tries = 1`, there is no retry; the net Nightwatch signal is the same, but Horizon's failure counter increments correctly for trend visibility.
    - **Technical:** The `try/catch` around the Redis existence check calls `report($e)` (correct for Nightwatch) then `return` (job completes as "succeeded" in Horizon). With `$tries = 1`, `$this->fail($e)` has the same effect on retry behaviour but routes the job to Horizon's `failed_jobs` and increments the failed counter. During a Redis outage, multiple successive job completions that are actually no-ops would be visible as a spike in failure rate rather than an unchanged success rate — a more accurate signal for incident triage.
    - **Plain English:** When the streaming polling job can't reach its data store, it correctly sounds the alarm — but then files itself under "completed successfully" in the job history dashboard. So the alarm rings, but the dashboard shows a sea of green. This makes it harder during an incident to see how many polls were missed. A one-line change makes the dashboard reflect reality: "these jobs failed" rather than "these jobs succeeded (but did nothing)."
    - **Evidence:**
        ```php
        try {
            $kickRateLimited = Redis::exists('streaming:kick:rate_limited');
        } catch (\Throwable $e) {
            Log::error('streaming.redis_unavailable', ['message' => $e->getMessage()]);
            report($e);

            return;  // job recorded as "succeeded" in Horizon; zero polls ran
        }
        ```

- [ ] **#OBS-12** · P3 — `SendTransactionalNotificationEmailJob` emits `Log::debug` on production control-flow paths without an environment guard
    - **Where:** `app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php:76–79`, `:87–92`, `:106–115`, `:128–133`, `:137–143`
    - **Affects:** Cloud log signal-to-noise — five `Log::debug` calls fire on normal production control-flow (feature flag off, no mailable registered, capability gate failed, account not active, user preference disabled). For a frequently-dispatched notification job these produce log volume that dilutes the signal value of Cloud logs in production.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap `Log::debug` calls in `if (app()->isLocal() || config('app.debug'))` guards, or downgrade them from `debug` to nothing (remove them) for the purely static-configuration paths (`email_enabled = false`, no mailable registered) where the condition never changes at runtime.
        - Keep `Log::debug` for the dynamic paths (account status, user preferences) if the data is useful for local debugging.
    - **Technical:** Laravel's default log level in production is `debug` (from `config/logging.php`'s `daily` channel unless `LOG_LEVEL` is overridden in `.env`). All five `Log::debug` calls land in Cloud logs. The static-configuration paths (`email_enabled = false`, no mailable class) are particularly wasteful — the condition is determined at config load time and emits on every invocation for affected categories. The dynamic paths (account status, preferences) are more defensible as debugging breadcrumbs.
    - **Plain English:** Every time the email system decides "no email needed here" — which is normal and expected many times a day — it writes a "skipped" note in the logs. In production, thousands of these notes pile up and make it harder to find the entries that actually matter, like real errors. This is a log hygiene issue, not a functional one.
    - **Evidence:**
        ```php
        if (! config('partna.notifications.email_enabled', false)) {
            Log::debug('Notification email skipped: feature disabled', [
                'category' => $this->category,
            ]);

            return;
        }

        if (! is_string($class) || ! class_exists($class)) {
            Log::debug('Notification email skipped: category has no mailable', [
                'category' => $this->category,
            ]);

            return;
        }
        ```

- [ ] **#OBS-13** · P3 — `WarmPublicSiteCacheJob` §28.8 warm swallows all failures with only `Log::warning`; sustained degradation is invisible
    - **Where:** `app/Jobs/Cache/WarmPublicSiteCacheJob.php:80–85`
    - **Affects:** First-visitor latency observability — the §28.8 cache-warm failure is explicitly designed as best-effort (the code comment says so). A single transient failure is harmless. A sustained failure (e.g. DB connection issue affecting `User::query()->where('handle_lc', ...)`) produces `Log::warning` on every publish event with no Nightwatch alert, making the degradation invisible until someone notices elevated latency in production traffic.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report($e)` to the catch block. Given the best-effort intent, this is appropriate because `report()` generates an exception event in Nightwatch without affecting job retry logic. The primary `$siteCache->warmSiteCache($subdomain)` call (which is outside the try and does propagate failures) already handles the critical warm path.
    - **Technical:** The docblock explicitly marks this as best-effort: "swallow errors so a transient Professional/Site lookup failure doesn't trip job retries." This is correct design. The `failed()` method does `report($e)` for failures from the primary warm path. The §28.8 catch only calls `Log::warning(...)`. A sustained failure in the secondary warm path (§28.8 key) would cause every first visitor to a just-published site to miss the warmed cache for that key, hit the full payload assembly, and log a warning on every publish — all with no alerting signal distinguishable from background noise.
    - **Plain English:** The site cache warmer has two jobs: warm the main cache (if this fails, alarms fire) and pre-fill a secondary cache (if this fails, the system notes it quietly and moves on). One quiet failure is fine. But if the secondary cache fails repeatedly for hours because a database table is unhappy, the notes pile up in the log file with no-one getting alerted. Adding one line (`report($e)`) means a sustained secondary failure raises its hand after a while, without breaking the "don't fail the job" contract.
    - **Evidence:**
        ```php
        } catch (\Throwable $e) {
            Log::warning('WarmPublicSiteCacheJob: §28.8 warm failed', [
                'subdomain' => $subdomain,
                'message' => $e->getMessage(),
            ]);
            // Missing: report($e)
        }
        ```

- [ ] **#OBS-14** · P3 — `Log::critical` used for recoverable streaming auth-failure paths; severity inflation reduces the signal value of genuinely critical log entries
    - **Where:** `app/Services/Streaming/KickApiClient.php:45`; `app/Services/Streaming/TwitchApiClient.php:35`; `app/Services/Streaming/StreamingTokenManager.php:82`, `:98`
    - **Affects:** On-call triage quality — four paths that recover gracefully (null token → return `[]`, HTTP failure → return null) log at `critical` severity. After seeing repeated `critical` log entries that resolve themselves or are diagnosed as transient, engineers are trained to treat `critical` as routine, reducing the signal value of genuinely critical events.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Downgrade `Log::critical` to `Log::error` for all four paths. These are degradations (poll cycle affected, streaming status stale) not crashes or data-loss events.
        - Reserve `Log::critical` for events that require immediate human action — e.g., a sustained auth failure persisting across multiple refresh cycles, detected via a Redis failure counter after the fix in OBS-5 is in place.
    - **Technical:** `KickApiClient::getLiveHandles()` logs `streaming.auth_failure` at `critical` when `getToken()` returns null, then returns `[]` — the poller continues, other platforms still poll, the job completes. Same pattern in `TwitchApiClient`. `StreamingTokenManager::refreshToken()` logs `critical` for non-200 OAuth responses and for transport exceptions. All four paths are recoverable degradations. `error` severity is the correct label: the operation failed, it will be retried, no immediate human action is required. Using `critical` for "recoverable API failure" devalues `critical` as a severity tier.
    - **Plain English:** The streaming system labels routine hiccups — an API token refresh that failed, a network blip — as "CRITICAL." After seeing a dozen "CRITICAL: token refresh failed" entries that turned out to be transient, the team learns to treat "CRITICAL" as "probably fine, check tomorrow." That means when something actually critical happens, the label carries no weight. The fix is to use the right label for the right problem: "error" for recoverable issues, "critical" for ones that need a human immediately.
    - **Evidence:**
        ```php
        // KickApiClient.php:45 — recoverable: returns [], poll continues
        if (! $token) {
            Log::critical('streaming.auth_failure', ['platform' => 'kick']);

            return [];
        }

        // StreamingTokenManager.php:82 — recoverable: null returned, callers degrade gracefully
        if (! $response->successful()) {
            Log::critical('streaming.auth_failure', [
                'platform' => $platform,
                'status' => $response->status(),
            ]);

            return null;
        }
        ```

