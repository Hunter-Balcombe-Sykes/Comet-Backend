# Observability / Logging / Metrics / Instrumentation Audit — 2026-05-20

**Branch:** development
**Lens:** observability logging metrics instrumentation
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Jobs/Cache/AggregateCacheMetricsJob.php
- app/Jobs/Cache/InvalidateConnectedAffiliateCachesJob.php
- app/Jobs/Cache/WarmPublicSiteCacheJob.php
- app/Jobs/Cloudflare/CloudflareCachePurgeJob.php
- app/Jobs/Cloudflare/ProvisionBrandDnsJob.php
- app/Jobs/Cloudflare/ProvisionBrandDnsTxtJob.php
- app/Jobs/Cloudflare/RetireBrandDnsJob.php
- app/Jobs/Cloudflare/RetireSubdomainFromKvJob.php
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php
- app/Jobs/Notifications/FanOutBrandStatusNotificationJob.php
- app/Jobs/Notifications/InviteExpirySweepJob.php
- app/Jobs/Notifications/NudgeStuckOnboardingJob.php
- app/Jobs/Notifications/SendBrandStatusNotificationJob.php
- app/Jobs/Notifications/SendEnquiryNotificationJob.php
- app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php
- app/Jobs/Notifications/SendStaffBroadcastEmailToSubscriberJob.php
- app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php
- app/Jobs/Notifications/SendWeeklyAnalyticsNotificationJob.php
- app/Jobs/Notifications/SyncCustomerMarketingOptInJob.php
- app/Jobs/Stripe/ExecuteCommissionPayoutJob.php
- app/Jobs/Stripe/MonitorManualRefundQueueJob.php
- app/Jobs/Stripe/ProcessCommissionPayoutsJob.php
- app/Jobs/Stripe/ReconcileStuckPayoutsJob.php
- app/Jobs/Stripe/SyncBrandPaymentMethodFromCheckoutSessionJob.php
- app/Jobs/Stripe/SyncStripeAccountStatusJob.php
- app/Jobs/Stripe/VoidableCommissionsAndWarningsJob.php
- app/Jobs/Stripe/VoidExpiredPayoutsJob.php
- app/Jobs/Stripe/VoidPendingCommissionsForLinkJob.php
- app/Http/Middleware/AddETagHeaders.php
- app/Http/Middleware/AddPublicCacheHeaders.php
- app/Http/Middleware/Auth/*.php
- app/Http/Middleware/Context/*.php
- app/Http/Middleware/BrandFundingGate.php
- app/Http/Middleware/EnsureAffiliateAccount.php
- app/Http/Middleware/EnsureBrandAccount.php
- app/Http/Middleware/FeatureGate.php
- app/Http/Middleware/Logging/*.php
- app/Http/Middleware/RequirePlan.php
- app/Http/Middleware/SecureHeaders.php
- app/Http/Middleware/VerifyTurnstileCaptcha.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 4 complete
- P3 Low: 0 of 1 complete

---

## P2 — Should fix

- [ ] **#OBS-1** · P2 — `AggregateCacheMetricsJob` has no `failed()` handler — retry exhaustion is invisible
    - **Where:** app/Jobs/Cache/AggregateCacheMetricsJob.php (entire class, lines 1–71)
    - **Affects:** Operators monitoring cache health. Redis degradation that exhausts retries produces no structured failure event — the bucket window is silently lost with no breadcrumb for the hour-slice that failed.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `failed(\Throwable $e)` method that calls `report($e)` and emits `Log::error` with the bucket key and error message.
        - Wrap the `Redis::hGetAll` call in a try/catch so transient Redis errors log the affected bucket and rethrow, preserving retry semantics.
    - **Technical:** Every other queued job that processes structured data (`VoidExpiredPayoutsJob`, `ReconcileStuckPayoutsJob`, `FanOutBrandStatusNotificationJob`, all 25+ Notification jobs) has an explicit `failed()` with `report($e)` + `Log::error`. This job's `$tries = 3` with no `failed()` means retry exhaustion surfaces only through Laravel's generic queue exception handler — a nameless "job failed" event with no bucket key, no prefix breakdown, and no SLO-violation breadcrumb. Ironically, the job whose sole purpose is SLO observability (`report(new \RuntimeException(...))` path) is itself the least observable job in the codebase when it fails.
    - **Plain English:** This is the hourly report card for our cache system. If Redis goes offline, this job tries three times and then disappears without a trace — no alert, no record of which hour-window was missed. Every other background job in the system leaves a note when it gives up. This one doesn't, which means the monitoring job itself is unmonitored.
    - **Evidence:**
        ```php
        public function handle(): void
        {
            $bucket = now('UTC')->subHour()->format('Y-m-d-H');
            $bucketKey = "cache_metrics:{$bucket}";

            $raw = Redis::hGetAll($bucketKey);
            // ... processing and SLO check ...
            // NO try/catch around Redis::hGetAll, NO failed() method defined anywhere in the class
        }
        ```

- [ ] **#OBS-2** · P2 — `SyncStripeAccountStatusJob` has zero observability on successful and early-exit code paths
    - **Where:** app/Jobs/Stripe/SyncStripeAccountStatusJob.php:50–62 (`handle` method)
    - **Affects:** Stripe Connect integration monitoring; operators diagnosing stuck `stripe_connect_status` values; Nightwatch visibility into webhook-driven account syncs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `Log::info` on successful sync with `professional_id` and `duration_ms`.
        - Add `Log::info` on the `not_connected` early-return (benign skip, useful for confirming the guard fired).
        - The "professional not found" early return can remain silent — it is consistent with several other jobs that treat a missing row as a no-op without logging.
    - **Technical:** This job is dispatched from Stripe webhook controllers on every account-state event. All three code paths through `handle()` — professional not found, `not_connected` guard, and successful `syncAccountStatus` call — produce zero log output. The only observable event is retry exhaustion caught by `failed()`. A brand whose Stripe account status silently drifts (e.g. `syncAccountStatus` returns without updating the row due to an API shape change) will not surface in logs until a downstream payout fails. Compare `SyncBrandPaymentMethodFromCheckoutSessionJob`, which logs a warning on professional-not-found in the same pattern.
    - **Plain English:** Every time Stripe tells us a brand's payment account changed, this job updates our records. It does so in complete silence — like a mailroom that processes packages but never marks anything delivered. If something goes wrong (a brand's status silently stops updating), we won't know until a payout fails days later and someone starts digging.
    - **Evidence:**
        ```php
        public function handle(StripeConnectService $service): void
        {
            $professional = Professional::find($this->professionalId);
            if (! $professional) {
                return;  // silent
            }

            if ($professional->stripe_connect_status === 'not_connected') {
                return;  // silent
            }

            $service->syncAccountStatus($professional);  // silent — no success log
        }
        ```

- [ ] **#OBS-3** · P2 — `ProvisionBrandDnsTxtJob::handle()` has no logging — Shopify domain verification failures leave no trace
    - **Where:** app/Jobs/Cloudflare/ProvisionBrandDnsTxtJob.php:55–57
    - **Affects:** Brand onboarding observability; DNS provisioning audit trail; operators troubleshooting "Shopify domain verification failed" support tickets.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `Log::info` after the successful `upsertTxt` call with `professional_id`, `record_name`, and `duration_ms`.
        - Match the pattern of sibling job `ProvisionBrandDnsJob`, which logs skip conditions with `professional_id`.
    - **Technical:** `ProvisionBrandDnsTxtJob` and `ProvisionBrandDnsJob` are a sibling pair — both provision Cloudflare DNS records for Shopify domain verification, dispatched from the same controller flow. `ProvisionBrandDnsJob` logs a structured `Log::info` when it skips a brand with no site row. `ProvisionBrandDnsTxtJob` logs nothing in `handle()` — success, skip, and the `upsertTxt` call are all invisible. A Shopify domain verification that silently fails (e.g. Cloudflare returns a non-throwing error, or the job is dispatched but never arrives) leaves no trace beyond the eventual user complaint. The `failed()` method does log, so retry exhaustion is observable — but the success path is not.
    - **Plain English:** When a brand connects their Shopify store, we create a verification record at Cloudflare. The CNAME half of that process leaves notes in the logs even when it skips work. The TXT record half (its twin) operates in silence — it could succeed, fail quietly, or complete at the wrong time, and the logs would look identical. Support would only find out when the brand complains that Shopify says "domain not verified."
    - **Evidence:**
        ```php
        public function handle(CloudflareDnsService $dns): void
        {
            // upsertTxt is idempotent — patches the existing record when the
            // value differs, so re-dispatch with a fresh Shopify token always wins.
            $dns->upsertTxt($this->recordName, $this->txtValue);
        }
        // No logging. Compare sibling ProvisionBrandDnsJob which logs skip conditions.
        ```

- [ ] **#OBS-4** · P2 — Three daily sweep jobs emit no completion summary — operators cannot confirm scheduler health or throughput
    - **Where:** app/Jobs/Notifications/InviteExpirySweepJob.php:51–92, app/Jobs/Notifications/NudgeStuckOnboardingJob.php:111–166, app/Jobs/Notifications/SendWeeklyAnalyticsNotificationJob.php (handle method)
    - **Affects:** Operations monitoring of daily scheduled jobs; Nightwatch alerting when the scheduler stalls or a sweep processes zero items; audit trail for notification volume.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `Log::info` at the end of `InviteExpirySweepJob::handle()` with `expired_count` and `notification_count`.
        - Add `Log::info` at the end of `NudgeStuckOnboardingJob::handle()` with per-milestone nudge counts and total `duration_ms`.
        - Add `Log::info` at the end of `SendWeeklyAnalyticsNotificationJob::handle()` with `professionals_processed` and `notifications_sent`.
        - Emit `Log::warning` when a sweep completes with zero items processed, so operators can distinguish "nothing to do" from "scheduler didn't run" or "query returned nothing due to a bug."
    - **Technical:** All three are daily scheduled jobs where the only operational question is "did it run today and did it find anything?" Currently none log a final count. The well-instrumented jobs in this codebase set the standard: `VoidExpiredPayoutsJob` always emits a heartbeat log with stats, `ReconcileStuckPayoutsJob` logs `inspected/advanced/errored` on every run. `InviteExpirySweepJob` processes expired invites in chunks of 500 with per-invite error logging but no aggregate. `NudgeStuckOnboardingJob` iterates 3 milestones with chunked DB walks but no milestone-level or total counts. `SendWeeklyAnalyticsNotificationJob` chunks professionals but emits no final count. A scheduler failure that causes all three to stop running produces exactly the same log output as a successful run that found nothing to process.
    - **Plain English:** Three daily housekeeping jobs run on a schedule — cleaning up expired invites, nudging stuck brands, and sending weekly analytics. None of them leave a "done" receipt. If the scheduler breaks and these jobs stop running entirely, the logs look identical to a healthy night where there was nothing to do. A simple "processed 42 invites, sent 38 notifications" line at the end would let the team confirm the system is healthy without logging into the database to check manually.
    - **Evidence:**
        ```php
        // InviteExpirySweepJob::handle() — ends after chunkById loop, no summary:
                    } catch (\Throwable $e) {
                        Log::warning('InviteExpirySweepJob failed for invite', [...]);
                    }
                }
            });
        }  // <-- method ends here, no count logged

        // NudgeStuckOnboardingJob::handle() — loops milestones, no per-milestone or total:
        foreach (self::MILESTONES as $milestone) {
            $this->sweepMilestone($publisher, $milestone);
        }
        }  // <-- method ends, no summary
        ```

---

## P3 — Nice to have

- [ ] **#OBS-5** · P3 — `RetireSubdomainFromKvJob` double-logs every failure — two warnings for one root cause
    - **Where:** app/Jobs/Cloudflare/RetireSubdomainFromKvJob.php:47–64
    - **Affects:** Operators during Cloudflare KV degradation — each failure generates two log entries with overlapping context, doubling alert noise in Nightwatch.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the `Log::warning` from the catch block inside `handle()`. Keep only `throw $e` — let `failed()` be the single log site. This matches the pattern used by every other job in the `Cloudflare/` directory.
        - Alternatively: remove `throw $e` from the catch block and call `$this->fail($e)` instead, deleting the duplicate from `failed()`. Pick whichever reads more clearly, but not both.
    - **Technical:** `handle()` wraps the KV delete in a try/catch that logs `Log::warning('RetireSubdomainFromKvJob: delete failed', ...)` then re-throws. Laravel's queue worker catches the re-thrown exception, increments the retry counter, and on final exhaustion calls `failed()`, which logs another `Log::warning('cloudflare.retire_subdomain_from_kv.failed', ...)` with the same handle and error message. In Nightwatch these appear as two distinct warning events separated by the retry window. No sibling job in the `Cloudflare/` directory uses this pattern — `RetireBrandDnsJob`, `SyncSubdomainToKvJob`, and `CloudflareCachePurgeJob` all log exclusively in `failed()`.
    - **Plain English:** When this job fails to clean up an old subdomain from Cloudflare, it shouts twice — once when it first trips, and again when it finally gives up after retries. Both messages say essentially the same thing. For the engineer on call this looks like two separate problems. Every other similar job in the codebase only shouts once (when it permanently gives up). Removing the first shout halves the noise without losing any information.
    - **Evidence:**
        ```php
        // handle() — first warning:
        } catch (\Throwable $e) {
            Log::warning('RetireSubdomainFromKvJob: delete failed', [
                'handle' => $this->handle,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }

        // failed() — second warning for the same failure:
        public function failed(Throwable $e): void
        {
            report($e);
            Log::warning('cloudflare.retire_subdomain_from_kv.failed', [
                'handle' => $this->handle,
                'error' => $e->getMessage(),
            ]);
        }
        ```
