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
