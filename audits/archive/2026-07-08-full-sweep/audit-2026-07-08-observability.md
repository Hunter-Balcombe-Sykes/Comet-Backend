# Observability Audit — 2026-07-08

**Branch:** development
**Lens:** Observability: logging gaps, silent failures, missing Nightwatch instrumentation — jobs that swallow exceptions silently, inbound callbacks that 200-but-don't-process, missing Nightwatch coverage, and log calls that obscure rather than illuminate.
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet`
**Source files audited:**
- app/Jobs/Notifications/SendEnquiryConfirmationJob.php
- app/Jobs/Notifications/SendSubscriptionConfirmationJob.php
- app/Jobs/Notifications/SendFeedbackEmailJob.php
- app/Jobs/Platforms/RefreshConnectionJob.php
- app/Services/Platforms/PlatformRefresher.php
- app/Jobs/ProcessImageVariantsJob.php
- app/Jobs/ProcessVideoVariantsJob.php
- app/Jobs/ProcessLogoVariantsJob.php
- app/Jobs/DeleteMediaArtifactsJob.php
- app/Console/Commands/CleanupStuckMediaProcessingCommand.php
- app/Listeners/RecordCacheMetrics.php
- app/Services/Streaming/StreamingTokenManager.php
- app/Services/Streaming/KickApiClient.php
- app/Services/Streaming/TwitchApiClient.php
- app/Services/Streaming/LiveStatusPoller.php
- app/Jobs/Streaming/CheckStreamingLiveStatusJob.php
- app/Services/Cloudflare/CloudflareCustomHostnameService.php
- app/Http/Controllers/Api/User/SiteManagement/CustomDomainController.php
- app/Services/Media/MediaDiskResolver.php
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php
- app/Jobs/Cloudflare/CloudflareCachePurgeJob.php
- app/Jobs/Gdpr/ExportUserDataJob.php
- app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php
- app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php
- app/Http/Controllers/Api/Internal/EnvCheckController.php
- app/Http/Controllers/Api/HealthController.php
- app/Jobs/Moderation/SuspendSiteJob.php, SuspendUserJob.php, QuarantineMediaJob.php
- app/Jobs/Moderation/Concerns/HasActionLogLifecycle.php
- app/Services/Moderation/ModerationAuditService.php
- app/Services/Moderation/ModerationDecisionService.php
- config/horizon.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 4 complete
- P2 Medium: 0 of 5 complete
- P3 Low: 0 of 3 complete

---

## P1 — Fix before pilot launch

- [ ] **#OBS-1** · P1 — `StreamingTokenManager::getToken()` returns null silently when OAuth credentials are missing
    - **Where:** app/Services/Streaming/StreamingTokenManager.php:51-55
    - **Affects:** All streaming live-status polling (Twitch + Kick). A missing/blank `services.twitch.client_id` or `services.kick.client_secret` config value silently disables the whole live-status feature for every professional who has connected a streaming handle.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `Log::error('streaming.missing_credentials', ['platform' => $platform])` **and** `report(new \RuntimeException("Streaming credentials missing for platform: {$platform}"))` before `return null`.
        - Fix together with #OBS-2/#OBS-3/#OBS-4 — same root cause across the streaming token/API-client family.
    - **Technical:** Category 1 — `getToken()` checks `config($cfg['client_id_key'])` / `config($cfg['client_secret_key'])`; if either is falsy the method returns `null` with **no logging and no `report()` at all** — a strictly worse gap than the sibling `refreshToken()` catch block, which at least logs. The caller (`KickApiClient`/`TwitchApiClient`) treats the null identically to a transient auth failure and writes `is_live=false` into Redis for every handle via `LiveStatusPoller::writeStatus()`. Because that write path is indistinguishable from a legitimate "nobody is live" result, this silently ships **wrong data** (every connected streamer shown offline) with the job reporting success in Horizon and zero Nightwatch signal — indefinitely, until someone manually investigates why a professional's live badge never updates.
    - **Plain English:** If the digital keys we use to check "is this person live on Twitch or Kick right now" go missing — say, from a deploy typo or an expired app registration — the checker just quietly says "nobody's live" for everyone, forever, and nobody is told. It looks identical to a normal quiet day, so the fault could sit undiscovered for weeks. The fix makes a missing key set off an alarm instead of a shrug.
    - **Evidence:**
        ```php
        $clientId = config($cfg['client_id_key']);
        $clientSecret = config($cfg['client_secret_key']);
        if (! $clientId || ! $clientSecret) {
            return null;
        }
        ```

- [ ] **#OBS-2** · P1 — `StreamingTokenManager::refreshToken()` silently swallows a non-2xx OAuth response
    - **Where:** app/Services/Streaming/StreamingTokenManager.php:81-88
    - **Affects:** All streaming live-status polling (Twitch + Kick). A 401/403/5xx from the OAuth token endpoint (revoked app, rotated secret, provider outage) silently stops polling.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report(new \RuntimeException("Streaming OAuth refresh failed for {$platform}: HTTP {$response->status()}"))` before the existing `Log::error` + `return null`.
        - Bundle with #OBS-1/#OBS-3/#OBS-4.
    - **Technical:** Category 1 — the non-successful HTTP response path calls only `Log::error('streaming.auth_failure', ...)`, a Cloud-log breadcrumb invisible to Nightwatch (which alerts on exceptions, not log queries). Note the `catch (\Throwable $e)` block a few lines further down in the same method **does** call `report($e)` — network/connection-level failures already page correctly. It is specifically this clean-but-unsuccessful-response branch that has no alert path, which is the more likely failure mode for a credentials problem (Twitch/Kick return a structured 401, not a network error).
    - **Plain English:** When the streaming-status checker asks Twitch or Kick "please renew my access pass" and gets told "no" (bad credentials, service issue), it writes a note in a log nobody's watching and moves on as if nothing happened. The fix makes that rejection page the on-call engineer, the same way a real crash would.
    - **Evidence:**
        ```php
        if (! $response->successful()) {
            Log::error('streaming.auth_failure', [
                'platform' => $platform,
                'status' => $response->status(),
            ]);

            return null;
        }
        ```

- [ ] **#OBS-3** · P1 — `KickApiClient::getLiveHandles()` returns empty on missing token with only `Log::error`
    - **Where:** app/Services/Streaming/KickApiClient.php:44-49
    - **Affects:** Kick live-status polling — every connected Kick handle gets marked offline whenever the shared token manager can't produce a token.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report(new \RuntimeException('Kick API token unavailable'))` before `return []`.
        - Bundle with #OBS-1/#OBS-2/#OBS-4 — same root cause.
    - **Technical:** Category 1 — `StreamingTokenManager::getToken('kick')` returning null (from either #OBS-1 or #OBS-2's paths) is handled here with `Log::error` + `return []`; `LiveStatusPoller::pollKick()` then writes `is_live=false` for the entire batch. The `catch (\Throwable $e)` further down in the same method already calls `report($e)` for genuine exceptions — this specific "no token" branch is the gap.
    - **Plain English:** The Kick checker asks for its access badge, gets nothing back, quietly marks every Kick streamer as offline, and clocks out looking successful. The fix makes an empty badge trip the alarm instead of being waved through as normal.
    - **Evidence:**
        ```php
        $token = $this->tokens->getToken('kick');
        if (! $token) {
            Log::error('streaming.auth_failure', ['platform' => 'kick']);

            return [];
        }
        ```

- [ ] **#OBS-4** · P1 — `TwitchApiClient::getLiveHandles()` returns empty on missing token with only `Log::error`
    - **Where:** app/Services/Streaming/TwitchApiClient.php:35-40
    - **Affects:** Twitch live-status polling — same silent-failure pattern as #OBS-3 on the Twitch side.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report(new \RuntimeException('Twitch API token unavailable'))` before `return []`.
        - Bundle with #OBS-1/#OBS-2/#OBS-3.
    - **Technical:** Category 1 — structurally identical to #OBS-3. A null token short-circuits to `Log::error` + `return []`, and `LiveStatusPoller::pollTwitch()` writes `is_live=false` for every handle in the batch. The method's own `catch (\Throwable $e)` block already calls `report($e)` for exceptions — only this clean null-token path is silent.
    - **Plain English:** Same story as Kick but on the Twitch side — a missing access badge quietly turns into "everyone's offline" with no one told.
    - **Evidence:**
        ```php
        $token = $this->tokens->getToken('twitch');
        if (! $token) {
            Log::error('streaming.auth_failure', ['platform' => 'twitch']);

            return [];
        }
        ```

## P2 — Should fix

- [ ] **#OBS-5** · P2 — Brand-resolver exception silently swallowed in `SendEnquiryConfirmationJob`
    - **Where:** app/Jobs/Notifications/SendEnquiryConfirmationJob.php:99-107
    - **Affects:** Every visitor confirmation email for contact-form submissions — branding falls back to the generic Partna look with no operator visibility on a sustained failure.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report($e)` inside the catch block alongside the existing `Log::warning`.
        - Fix together with #OBS-6 — identical pattern in the sibling job.
    - **Technical:** Category 1/4 boundary — the `catch (\Throwable $e)` around `$resolver->forSite(...)` logs at `warning` only and falls back to `$resolver->partna()`. The email itself is still sent correctly and the fallback is the intentional, correct UX (the code comment confirms "a branding failure must never drop a transactional email") — this is graceful degradation, not a broken send, which is why it sits at P2 rather than P1. The gap is purely that a *sustained* resolver failure (DB blip, code regression) degrades every confirmation email's branding indefinitely with zero on-call visibility, since `Log::warning` never reaches Nightwatch.
    - **Plain English:** If the system that fetches a professional's custom branding breaks, the email still goes out fine — just wearing the generic Partna look instead of the professional's own. That's an acceptable fallback for one email, but if it stays broken for days nobody would know. The fix adds a "please tell someone" step alongside the existing quiet note.
    - **Evidence:**
        ```php
        } catch (\Throwable $e) {
            Log::warning('email brand resolve failed; falling back to Partna brand', [
                'site_id' => (string) $enquiry->site_id,
                'error' => $e->getMessage(),
            ]);
            $brand = $resolver->partna();
        }
        ```

- [ ] **#OBS-6** · P2 — Brand-resolver exception silently swallowed in `SendSubscriptionConfirmationJob`
    - **Where:** app/Jobs/Notifications/SendSubscriptionConfirmationJob.php:110-118
    - **Affects:** Every newsletter-subscription confirmation email — same silent fallback as #OBS-5.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report($e)` inside the catch block alongside the existing `Log::warning`.
        - Bundle with #OBS-5.
    - **Technical:** Category 1/4 boundary — identical pathology to #OBS-5: the fallback to `$resolver->partna()` is correct UX, but the failure itself needs to page on a sustained outage.
    - **Plain English:** Same as #OBS-5 — the fallback branding is fine for one email, but a broken branding system should still tell someone if it stays broken.
    - **Evidence:**
        ```php
        } catch (\Throwable $e) {
            Log::warning('email brand resolve failed; falling back to Partna brand', [
                'site_id' => $siteId,
                'error' => $e->getMessage(),
            ]);
            $brand = $resolver->partna();
        }
        ```

- [ ] **#OBS-7** · P2 — `RefreshConnectionJob` defines no `failed()` — retry-exhaustion alerts lack `connection_id`/`platform` context
    - **Where:** app/Jobs/Platforms/RefreshConnectionJob.php:77-88
    - **Affects:** On-call triage for platform-refresh failures — Nightwatch still receives the alert, but without the fields needed to identify which connection/platform broke.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `failed(Throwable $e): void` method that calls `report($e)` (or lets the framework's automatic report stand) and additionally emits `Log::error('platform_refresh.failed', ['connection_id' => $this->connectionId, 'platform' => $this->platform, 'error' => $e->getMessage()])`.
        - Mirror the pattern already used by `SyncSubdomainToKvJob::failed()` / `CloudflareCachePurgeJob::failed()` in the same codebase.
    - **Technical:** Category 3 — corrected from the draft's premise: Laravel's `Illuminate\Queue\Worker::runJob()` (vendor/laravel/framework/src/Illuminate/Queue/Worker.php:451-460) calls `$this->exceptions->report($e)` for **every** exception that escapes a job's `process()` call, regardless of whether the job class defines `failed()` — so Nightwatch **does** already fire for `RefreshConnectionJob` failures today; this is not a total silent failure. What's genuinely missing is the structured `connection_id`/`platform` context every sibling vendor-I/O job (`SyncSubdomainToKvJob`, `CloudflareCachePurgeJob`, `ExportUserDataJob`) attaches via its own `failed()` override — without it, an on-call engineer sees a bare exception and has to dig through `IntegrationConnection` rows to find which professional's connection triggered it. `PlatformRefresher::refresh()` already does this correctly for `FetchShapeException` (`report($e)` with `platform`/`platform_connection_id` in `recordFailure()`'s log) — the job itself is the outlier lacking the same context for any other exception type that bubbles past the strategy layer.
    - **Plain English:** When a platform refresh breaks for good, the system already pages someone — that part works. But the alert doesn't say *which* professional's connection or *which* platform broke, so whoever's on call has to go digging. Adding the missing label is like putting a name tag on the circuit breaker instead of just "something in the building broke."
    - **Evidence:**
        ```php
        public function handle(PlatformRefresher $refresher): void
        {
            $connection = IntegrationConnection::query()->find($this->connectionId);

            // Deleted or deactivated between dispatch and execution — nothing to do.
            if ($connection === null || ! $connection->is_active) {
                return;
            }

            $refresher->refresh($connection);
        }
        }
        ```

- [ ] **#OBS-8** · P2 — `ProcessImageVariantsJob`/`ProcessVideoVariantsJob` lock-acquisition skip logs at `info` — no visibility into contention patterns
    - **Where:** app/Jobs/ProcessImageVariantsJob.php:70-77 (mirrored in app/Jobs/ProcessVideoVariantsJob.php:75-81)
    - **Affects:** Media processing pipeline — when the in-flight Redis lock is held (crashed worker, stuck process), the job returns "successfully" in Horizon while the image/video stays in `PROCESSING` until the separate cleanup sweep runs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Upgrade the skip log to `Log::warning` (matching the severity `CleanupStuckMediaProcessingCommand::handle()` already uses for its own sweep log at `media.cleanup.swept_stuck`) so a sustained pattern of lock contention is visible in Cloud logs.
        - Optional follow-up: increment a Redis counter on skip and surface an abnormal skip-rate via a scheduled health check, rather than relying on someone reading logs.
    - **Technical:** Category 3 — both jobs use `GuardsMediaProcessing::acquireProcessingLock()`, which returns `false` when another worker still holds the per-media lock; the job logs at `info` and returns, and Horizon records success. This is a documented, deliberate trade-off (the job's own docblock explains the TTL/backoff reasoning and defers reconciliation to `CleanupStuckMediaProcessingCommand`), so it is not a data-loss bug — but the eventual safety net itself only logs at `warning` without `report()`, and the in-job skip logs at `info`, one level below where an operator scanning for anomalies would typically filter. A worker that dies repeatedly (bad deploy, OOM loop) would produce a sustained pattern of skipped jobs that is invisible until the cleanup command's next scheduled run.
    - **Plain English:** Think of two workers trying to use the same printing press at once — the second one just waits its turn and reports "done" without actually printing anything, quietly. A cleanup crew picks up any truly abandoned jobs later, but there's no dashboard showing how often this "someone else has the press" situation happens. Bumping the log level from a whisper to a normal-volume note makes a repeating pattern visible before it needs the cleanup crew at all.
    - **Evidence:**
        ```php
        $lockKey = "image:processing-lock:{$this->imageId}";
        if (! $this->acquireProcessingLock($lockKey)) {
            Log::info('ProcessImageVariantsJob: another worker is processing this image, skipping.', [
                'image_id' => $this->imageId,
            ]);

            return;
        }
        ```

- [ ] **#OBS-9** · P2 — `CloudflareCustomHostnameService::delete()` has no `->throw()` — API failures are invisible
    - **Where:** app/Services/Cloudflare/CloudflareCustomHostnameService.php:88-95
    - **Affects:** User custom-domain disconnection/replacement (`CustomDomainController::store()` and `::destroy()`). A failed Cloudflare API call silently "succeeds" — the custom hostname and its DV certificate may remain live on the zone.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `->throw()` to the HTTP call chain, matching `create()` and `get()` in the same class.
    - **Technical:** Category 1 — `create()` and `get()` both chain `->throw()`, so a Cloudflare 4xx/5xx raises an exception the caller already catches (`CustomDomainController::store()`/`::destroy()` both wrap `$this->cf->delete(...)` in `try { ... } catch (Throwable $e) { report($e); }`). `delete()` alone omits `->throw()`, so a non-2xx business-logic response (e.g. hostname already gone, permission error) never becomes a thrown exception — the caller's existing `catch` block, which is correctly written, simply never fires because nothing was thrown. The custom hostname can persist on the Cloudflare zone after a user "disconnects" it, which can later cause a confusing `create()` collision (Cloudflare rejecting a duplicate hostname) if the same domain is reconnected. This does not break routing today — `SyncSubdomainToKvJob` still retires the KV entry independently — but it leaves a hidden, unbounded stale-resource trail with no alert.
    - **Plain English:** The other two Cloudflare operations (create, check-status) already raise a flag if Cloudflare says no. Delete doesn't — if Cloudflare has a bad moment and refuses the deletion, we shrug and tell the user "done" anyway. Their old custom domain quietly stays registered on our end, which can cause a confusing error later if they (or someone else) try to reconnect the same domain.
    - **Evidence:**
        ```php
        public function delete(string $id): void
        {
            if (! $this->configured || $id === '') {
                return;
            }

            Http::withToken($this->apiToken)->delete($this->base()."/{$id}");
        }
        ```

## P3 — Nice to have

- [ ] **#OBS-10** · P3 — `RecordCacheMetrics` listener catches Redis failures with `Log::warning` only — no `report()`
    - **Where:** app/Listeners/RecordCacheMetrics.php:57-60
    - **Affects:** Cache hit-rate observability only — a sustained Redis failure on the metrics hash loses hit-rate data silently. The underlying cache operations themselves fail and alert separately, so this is a secondary signal, not user-facing.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - If added, throttle via a static in-process flag (e.g. a `private static bool $reported = false;` set on first report per worker lifetime) rather than a `Cache::add`-based guard — this listener's own failure mode is Redis being unavailable, so a throttle that itself writes to Redis would be circular and silently no-op during exactly the outage it's meant to catch.
        - Given the low value (this is a fire-and-forget metrics counter fired on every single cache hit/miss/write in the app, and real cache-operation failures already alert independently), this is optional hardening rather than a must-fix.
    - **Technical:** Category 4 — the listener's own comment states the intended behavior: "Never let a metrics write fail a cache operation." It correctly never lets a Redis hiccup break the actual cache read/write path. The only gap is that a sustained Redis outage on this specific hash silently loses hit-rate telemetry with no signal beyond a `Log::warning` breadcrumb — a low-severity, secondary-signal gap given this listener fires on every cache operation across the app and any real cache failure surfaces through other paths already.
    - **Plain English:** The odometer that counts cache hits and misses has its own tiny battery. If that battery dies, the odometer goes blank, but the car still drives fine — the actual cache-serving code doesn't depend on this counter at all. It's a nice-to-have dashboard light, not a safety issue.
    - **Evidence:**
        ```php
        } catch (\Throwable $e) {
            // Never let a metrics write fail a cache operation.
            Log::warning('cache.metrics.record_failed', ['error' => $e->getMessage()]);
        }
        ```

- [ ] **#OBS-11** · P3 — `StreamingTokenManager::getToken()` returns null silently for an unrecognized platform key
    - **Where:** app/Services/Streaming/StreamingTokenManager.php:39-42
    - **Affects:** Developer debugging only — this branch is only reachable via a coding error at a call site (the platform list is a static, hardcoded set in production), not a runtime/production condition.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `Log::warning('streaming.unknown_platform', ['platform' => $platform])` before `return null` so a future coding mistake is easier to trace.
    - **Technical:** Category 4 — when `getToken($platform)` is called with a key not in `self::PLATFORM_CONFIG`, the method returns null with no log at all, identical to a missing-credential outcome. This can only happen from a programming error (a typo'd platform string), not a production data condition, which is why this sits at P3 rather than P2 — it costs nothing to add but only pays off during future development, not live operation.
    - **Plain English:** If a developer ever mistypes a platform name in the streaming checker's code, the system just shrugs with no hint of what went wrong. A one-line note would save someone a confusing debugging session later — but it can't happen from anything a real user or attacker does today.
    - **Evidence:**
        ```php
        $cfg = self::PLATFORM_CONFIG[$platform] ?? null;
        if (! $cfg) {
            return null;
        }
        ```

- [ ] **#OBS-12** · P3 — `MediaDiskResolver` logs runtime config-override divergence at `info` instead of `warning`
    - **Where:** app/Services/Media/MediaDiskResolver.php:43-48
    - **Affects:** Ops debugging only — a runtime env var silently overriding the cached media-disk config is a state change worth surfacing consistently with the fallback path a few lines below, which already logs the same class of event at `warning`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change `Log::info` to `Log::warning` for consistency with the `filesystems.default` fallback branch immediately below it (which already uses `Log::warning` for a comparable divergence).
    - **Technical:** Category 4 — this is a log-level consistency nit, not a missing-signal bug: the resolver already logs the divergence (unlike the streaming/brand-resolver findings above, nothing here is silent), it's simply one level below its sibling branch's severity for what the class docblock itself frames as a state worth being "a visible event."
    - **Plain English:** When the storage location for photos/videos gets changed at runtime by an environment setting, the system does leave a note — just a quieter one than a very similar note a few lines later in the same file. Making the volume consistent costs nothing and avoids confusion about which one matters more.
    - **Evidence:**
        ```php
        if ($resolved !== $configured) {
            Log::info('media.disk_runtime_override', [
                'resolved' => $resolved,
                'configured' => $configured,
            ]);
        }
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Streaming OAuth silent-failure alerting:** #OBS-1, #OBS-2, #OBS-3, #OBS-4
    - **Why grouped:** all four live in the Streaming service family (`StreamingTokenManager` + `KickApiClient` + `TwitchApiClient`) and share one root cause — OAuth token acquisition failures never call `report()`. One cohesive PR.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Notification brand-resolver fallback alerting:** #OBS-5, #OBS-6
    - **Why grouped:** identical catch-and-fallback pattern in sibling confirmation jobs; a one-line `report($e)` fix in each.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 3 — Vendor-I/O failure surfacing:** #OBS-7, #OBS-9
    - **Why grouped:** both are "the vendor call's failure result isn't properly surfaced" gaps (missing structured `failed()` context; missing `->throw()`) — same class of fix, different files, one session.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 4 — Logging hygiene sweep:** #OBS-8, #OBS-10, #OBS-11, #OBS-12
    - **Why grouped:** pure log-level / instrumentation tweaks across media processing, the cache-metrics listener, and the streaming token manager — no behavioral change, low risk, safe to batch into one sweep.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

None — no P0s, no auth/authorization/money findings, no DB migration/schema changes, and every item is S-effort.
