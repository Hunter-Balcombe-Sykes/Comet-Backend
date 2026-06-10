Good. I have enough to adjudicate definitively. Here's what I confirmed:

- **LIFE-2 must be dropped**: `ProfessionalAnalyticsController` reads `$summaryVersion` and appends `:v{$summaryVersion}` to the key (line 96-100). `bumpAnalyticsVersion()` correctly invalidates these keys at runtime. DeepSeek only read `CacheKeyGenerator`, not the call site.
- **LIFE-1, 3, 4**: All evidence verbatim and findings correct.
- **New finding**: Three `catch (Throwable) {}` in `AnalyticsController` swallow `invalidateAnalytics` failures silently — Nightwatch-invisible at 3K visits/day.
- **FanOutBrandStatusNotificationJob**: Already uses `Bus::batch()` with `allowFailures()` — no finding needed there.
- **siteImages key**: Not written anywhere with SWR, so no stale twin to bust — not a finding.

`★ Insight ─────────────────────────────────────`
- The DeepSeek draft missed that `CacheKeyGenerator::analyticsSummary` being "unversioned" is actually intentional — the version is concatenated at the controller call site. This is a common pattern where key composition happens outside the generator.
- The confirmed `bust :stale twin` gap (LIFE-1) is higher-frequency than it first appears: `invalidateAnalytics` is called on every visit, click, and cart event — up to 3K times/day — making the leaking stale copies a constant, not occasional, issue.
- The two-level cache pattern (version token for windowed analytics, explicit deletion for rolling per-day keys) is architecturally sound; the bug is simply that the explicit-deletion loop forgets one step.
`─────────────────────────────────────────────────`

---

# Lifecycle Audit — Cache Group C — 2026-05-11

**Branch:** development
**Lens:** Lifecycle correctness: race-safety, idempotency, anchor decoupling, reconcile loops, vendor resilience, observability discipline — Group C (Cache invalidation & write-path discipline)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Services/Cache/AnalyticsCacheService.php
- app/Services/Cache/CacheKeyGenerator.php
- app/Services/Cache/CacheLockService.php
- app/Services/Cache/ProfessionalCacheService.php
- app/Services/Cache/SiteCacheService.php
- app/Jobs/Cache/AggregateCacheMetricsJob.php
- app/Jobs/Cache/InvalidateConnectedAffiliateCachesJob.php
- app/Jobs/Cache/WarmPublicSiteCacheJob.php
- app/Observers/Core/BlockObserver.php
- app/Observers/Core/BrandAffiliateInviteObserver.php
- app/Observers/Core/BrandPartnerLinkObserver.php
- app/Observers/Core/BrandProfileObserver.php
- app/Observers/Core/CommissionMovementObserver.php
- app/Observers/Core/CommissionPayoutObserver.php
- app/Observers/Core/CustomerObserver.php
- app/Observers/Core/ProfessionalIntegrationObserver.php
- app/Observers/Core/ServiceObserver.php
- app/Observers/Core/SiteMediaObserver.php
- app/Observers/Core/SiteObserver.php
- app/Observers/Professional/ProfessionalObserver.php
- app/Observers/Retail/BrandStoreSettingsObserver.php
- app/Jobs/Notifications/FanOutBrandStatusNotificationJob.php
- app/Services/Analytics/AffiliateProjectionsService.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **#LIFE-1** · P1 — `invalidateAnalytics` does not bust `:stale` twins for visit/click cache keys
    - **Where:** app/Services/Cache/AnalyticsCacheService.php — the 90-day `$keys` loop and `Cache::deleteMultiple` call
    - **Affects:** Any dashboard or API consumer that reads `analyticsVisits`/`analyticsClicks` after an invalidation event (every visit, click, or cart event via `AnalyticsController`, plus every Shopify `order/paid`, `order/edited`, `order/cancelled`, `refunds/create` webhook via `ProcessShopifyOrderWebhookJob` and `ProcessShopifyOrderUpdatedWebhookJob`). The primary key is deleted but the `:stale` SWR copy written by `CacheLockService::writeWithJitter` survives for `analytics_short × 10` seconds, so the SWR fast-path returns pre-invalidation visit and click counts for the full stale window. At 3K visits/day and ~1M orders/year, `invalidateAnalytics` fires continuously — making the leak persistent rather than occasional.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In the 90-day loop in `invalidateAnalytics`, append each key's `:stale` twin to `$keys` before calling `Cache::deleteMultiple`:
            ```php
            for ($i = 0; $i < 90; $i++) {
                $date = Carbon::now()->subDays($i)->format('Ymd');
                $visitKey  = CacheKeyGenerator::analyticsVisits($professionalId, $date, $date);
                $clickKey  = CacheKeyGenerator::analyticsClicks($professionalId, $date, $date);
                $keys[] = $visitKey;
                $keys[] = $visitKey . ':stale';
                $keys[] = $clickKey;
                $keys[] = $clickKey . ':stale';
            }
            ```
        - Add a test asserting that after `invalidateAnalytics` runs, both the primary and `:stale` keys for a sample day are absent from the cache.
    - **Technical:** `getVisitStats` and `getClickStats` both use `CacheLockService::rememberLocked`, which calls `writeWithJitter` and writes two Redis entries for every cache fill: the primary key (jittered TTL) and `$key . ':stale'` at `STALE_TTL_MULTIPLIER (10)×` the base TTL. The `invalidateAnalytics` method correctly handles this for `affiliateProjections` (it already calls `Cache::forget($key . ':stale')` explicitly in the projections loop). But the 90-day visit/click loop collects only primary keys and calls `Cache::deleteMultiple(array_values(array_unique($keys)))` — the stale copies are never touched. The SWR fast-path in `rememberLocked` reads `:stale` before acquiring a lock, so any reader that arrives after invalidation but before the stale TTL expires gets served the old value silently. This is the canonical `f5450d8` shape: `bust :stale twin` must be paired on every write-path invalidation. The analogous fix is already in place for the `affiliateProjections` keys in the same method.
    - **Plain English:** Think of each analytics number in the cache as having two stored copies — a "current" copy that expires quickly and a "safety net" copy that lasts ten times longer. The safety net exists so the system doesn't go to the database for every concurrent dashboard load. When a new visit is recorded, the system deletes the "current" copy to force a refresh — but it forgets to also shred the safety net copy. So for up to ten times the normal cache window, everyone reading analytics still sees the old visit count, even though the system just tried to flush it. Because this happens on every single visit ingestion (3,000 times a day at scale), the analytics dashboard is in practice always showing slightly outdated numbers despite the invalidation code running constantly.
    - **Evidence:**
        ```php
        // AnalyticsCacheService.php — 90-day loop collects primary keys only; no :stale twins
        for ($i = 0; $i < 90; $i++) {
            $date = Carbon::now()->subDays($i)->format('Ymd');

            $keys[] = CacheKeyGenerator::analyticsVisits($professionalId, $date, $date);
            $keys[] = CacheKeyGenerator::analyticsClicks($professionalId, $date, $date);
        }

        Cache::deleteMultiple(array_values(array_unique($keys)));
        ```
        ```php
        // Compare: affiliateProjections in the same method correctly busts :stale
        foreach ($projectionVariants as $w) {
            $w = $w === null ? null : (int) $w;
            Cache::forget(CacheKeyGenerator::affiliateProjections($professionalId, $w));
            Cache::forget(CacheKeyGenerator::affiliateProjections($professionalId, $w).':stale');
        }
        ```

---

## P2 — Should fix

- [ ] **#LIFE-2** · P2 — `SiteObserver::cascadeAffiliateKvSync` dispatches 1→N Cloudflare KV jobs without jitter
    - **Where:** app/Observers/Core/SiteObserver.php — `cascadeAffiliateKvSync` private method
    - **Affects:** Any brand with connected affiliates that changes its subdomain. Each connected affiliate's KV routing entry must be updated. At 50 affiliates per brand the jobs land simultaneously; at the scale target of 200 brands × 50 affiliates, a single subdomain change produces a burst of 50 near-simultaneous Cloudflare KV Write API calls — no backpressure, no rate-limit protection.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Mirror the pattern already used by `SiteCacheService::invalidateSite` for `InvalidateConnectedAffiliateCachesJob`: add `->delay(now()->addSeconds(random_int(0, 30)))` to each `SyncSubdomainToKvJob::dispatch($affiliateId)` call inside `cascadeAffiliateKvSync`.
        - No schema change required; jitter is purely at the dispatch call site.
    - **Technical:** `SiteCacheService::invalidateSite` already implements the `38ff4fb` jittered per-tenant invalidation pattern — each `InvalidateConnectedAffiliateCachesJob` dispatch includes `->delay(now()->addSeconds(random_int(0, 30)))` so cache cold-misses are spread across a 30-second window. But the sibling Cloudflare KV fan-out in `cascadeAffiliateKvSync` dispatches all `SyncSubdomainToKvJob` instances with no delay at all, so all N jobs land on the queue at the same instant. Each job presumably makes a synchronous Cloudflare Workers KV Write call; N simultaneous calls share the same rate-limit envelope and may produce HTTP 429 responses that silently lose KV entries. The fix is one line per dispatch: add `->delay(now()->addSeconds(random_int(0, 30)))` matching the invalidation pattern in the same class hierarchy.
    - **Plain English:** When a brand renames their website, the system needs to update a routing directory for every affiliate connected to that brand — like updating 50 address book entries at once. One part of the system already staggers these updates across 30 seconds to avoid overwhelming the external directory service. But a second part — the one that specifically updates Cloudflare's routing table — fires all 50 updates at exactly the same moment. At the 50-affiliates-per-brand scale the product is targeting, this is 50 near-simultaneous calls to the same external service, which could trigger a rate limit and silently fail some routing updates. The fix is to add the same random 0–30 second stagger that the other path already uses.
    - **Evidence:**
        ```php
        // SiteObserver.php — cascadeAffiliateKvSync dispatches with no delay
        private function cascadeAffiliateKvSync(string $brandProfessionalId): void
        {
            if ($brandProfessionalId === '') {
                return;
            }

            BrandPartnerLink::query()
                ->where('brand_professional_id', $brandProfessionalId)
                ->pluck('affiliate_professional_id')
                ->each(function (string $affiliateId): void {
                    SyncSubdomainToKvJob::dispatch($affiliateId);
                });
        }
        ```
        ```php
        // Compare: SiteCacheService::invalidateSite already uses the canonical jitter pattern
        foreach ($connectedSubdomains as $connectedSubdomain) {
            InvalidateConnectedAffiliateCachesJob::dispatch($connectedSubdomain)
                ->delay(now()->addSeconds(random_int(0, 30)));
        }
        ```

- [ ] **#LIFE-3** · P2 — Observer `Log::warning` calls missing `request_id` context across all observer classes
    - **Where:** app/Observers/Core/BlockObserver.php, BrandAffiliateInviteObserver.php, CommissionMovementObserver.php, CommissionPayoutObserver.php, BrandProfileObserver.php, ProfessionalIntegrationObserver.php, SiteMediaObserver.php, SiteObserver.php, BrandPartnerLinkObserver.php, and app/Observers/Professional/ProfessionalObserver.php
    - **Affects:** Operational visibility when any observer-side-effect fails (cache bust, notification dispatch, Cloudflare KV sync). At ~40K daily notifications and ~3K orders/day, side-effect failures will occur in production. Without `request_id`, Nightwatch cannot trace a log entry to a specific HTTP request — all failures across all tenants look identical in the Nightwatch log view.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `'request_id' => request()->header('X-Request-Id', '')` to every `Log::warning` and `Log::error` context array in all observer classes.
        - Add the relevant tenant ID where it can be inferred: `professional_id`, `brand_professional_id`, or `site_id` (most observers already have this — the gap is only `request_id`).
        - Consider extracting a protected helper `withRequestContext(array $extra): array` in a shared `ObserverLogging` trait to prevent future regressions; new observer skeletons would inherit it automatically.
    - **Technical:** Nightwatch correlates logs to request traces via `request_id`. Observers run inside the request lifecycle (they fire after commit but still within the same PHP process as the originating request, so `request()` is available). The `ServiceObserver::runHooks` catch-all already includes `professional_id` as context — that's the closest approximation — but even there `request_id` is absent. The canonical pattern established by `#STRIPE-2` / `35c6f31` is distinct logs for distinct failure modes WITH correlation context; this finding extends that requirement to observer-layer log calls. At 200 brands and ~40K daily notifications, debugging a silent notification failure without `request_id` means manually correlating timestamps across Nightwatch events, which is not feasible at scale.
    - **Plain English:** When something goes wrong in the background after a user action (a notification fails to send, a cache doesn't clear, a routing update fails), the system writes a note to the operations log. But right now every one of those notes is like a sticky note with no return address — it says "something failed" but doesn't include the specific request ID that caused it. At 200 brands with tens of thousands of background events per day, you can't trace a problem in the log to the exact user action that triggered it. The fix is to staple the request ID onto every log note, which takes about two minutes per observer file.
    - **Evidence:**
        ```php
        // BlockObserver.php — missing request_id
        Log::warning('Site cache invalidation failed on block create', [
            'block_id' => $block->id,
            'site_id' => $block->site->id,
            'message' => $e->getMessage(),
        ]);

        // CommissionMovementObserver.php — missing request_id
        Log::warning('CommissionMovement created notification failed', [
            'entry_id' => $entry->id,
            'message' => $e->getMessage(),
        ]);

        // CommissionPayoutObserver.php — missing request_id
        Log::warning('CommissionPayout updated notification failed', [
            'payout_id' => $payout->id,
            'message' => $e->getMessage(),
        ]);

        // ProfessionalIntegrationObserver.php — missing request_id
        Log::warning('ProfessionalIntegration created notification failed', [
            'integration_id' => $integration->id,
            'message' => $e->getMessage(),
        ]);

        // BrandProfileObserver.php — missing request_id
        Log::warning('BrandProfile updated notification dispatch failed', [
            'brand_profile_id' => $brandProfile->id,
            'message' => $e->getMessage(),
        ]);
        ```

---

## P3 — Nice to have

- [ ] **#LIFE-4** · P3 — `AnalyticsController` swallows `invalidateAnalytics` failures silently in three places
    - **Where:** app/Http/Controllers/Api/PublicSite/AnalyticsController.php — `pageview`, `click`, and `cartEvent` methods (each has an identical `try { $this->analyticsCache->invalidateAnalytics(...); } catch (Throwable) {}` block)
    - **Affects:** Operational visibility when Redis connectivity degrades. At 3K visits/day, Redis failures during visit/click ingestion produce thousands of swallowed exceptions per day with zero Nightwatch signal. Cache invalidation failures mean analytics dashboards stay stale longer than intended, but there is no alert and no way to quantify how often it happens in production.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the empty catch body with a `Log::warning` that includes `professional_id`, `site_id`, and the exception message — matching the observer-layer logging standard.
        - Do not rethrow; cache invalidation failure must not fail visit/click ingestion. The wrapping try/catch is intentional and correct; only the empty body is the problem.
    - **Technical:** Category 10 (observability): `catch (Throwable) {}` without a log emit — Nightwatch never sees it. The intent is clear and correct (best-effort invalidation should not break the success response to the visitor's browser), but silent swallowing means Redis degradation is invisible. A `Log::warning('analytics cache invalidation failed', ['professional_id' => ..., 'site_id' => ..., 'error' => $e->getMessage()])` costs nothing on the success path and surfaces Redis connectivity issues through Nightwatch's error aggregation.
    - **Plain English:** When someone visits a site, the system tries to refresh the analytics cache and quietly ignores any errors from that refresh step — which is the right call (a caching glitch shouldn't break the visit recording). But right now "quietly ignoring" means writing absolutely nothing to the log, so if Redis is having trouble, you'd have thousands of these silent failures happening every day with no dashboard, no alert, and no way to know. The fix is to keep the silent-ignore behavior but add a one-line log note so the operations dashboard can at least tell you it's happening.
    - **Evidence:**
        ```php
        // AnalyticsController.php — identical pattern in pageview(), click(), and cartEvent()
        try {
            $this->analyticsCache->invalidateAnalytics($site->professional_id);
        } catch (Throwable) {
        }
        ```
