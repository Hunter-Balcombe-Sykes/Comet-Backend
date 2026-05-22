- [ ] **#CCG-1** · P2 — Uncached aggregate payout-summary queries on affiliate/brand dashboard
    - **Where:** app/Services/Stripe/CommissionPayoutService.php:472-490
    - **Affects:** Every brand and affiliate loading their payout overview dashboard — two aggregate queries (SUM + COUNT + GROUP BY) hit the database on every page view with no cache layer.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap `getPayoutSummary()` in `CacheLockService::rememberLocked` with a key from `CacheKeyGenerator` (e.g. `payout_summary:{professional_id}`).
        - TTL should be short (60–120s) — payout state changes are low-frequency but the dashboard must reflect new payouts within a reasonable window.
        - Bust the key from `markCompleted()`, `failPayout()`, and `cancelExpiredPayout()` so completed/failed/cancelled payouts invalidate immediately.
    - **Technical:** `getPayoutSummary()` issues two `selectRaw('status, COUNT(*) … SUM(…)')->groupBy('status')` queries against `commerce.commission_payouts` — one scoped to the professional as brand, one as affiliate. For a long-tenured affiliate these aggregate over hundreds or thousands of payout rows on every dashboard load. The method has no `Cache::` call, no `rememberLocked` wrapper, and no docblock delegating caching upward (unlike `StripeTransactionFetcher` which explicitly says "caching is the controller's job"). This is a straight database aggregate on a hot dashboard path.
    - **Plain English:** Every time an affiliate or brand opens their earnings dashboard, we run two expensive "total up all my payouts" calculations against the database — even if nothing changed since the last page load. It's like asking an accountant to re-add every invoice from scratch every time you glance at the summary. The fix is a sticky note on the desk: "here's the total, recalculate only when a new invoice arrives."
    - **Evidence:**
        ```php
        public function getPayoutSummary(Professional $professional): array
        {
            $asBrand = CommissionPayout::query()
                ->where('brand_professional_id', $professional->id)
                ->selectRaw('status, COUNT(*) as count, SUM(gross_commission_cents) as total_cents')
                ->groupBy('status')
                ->get()
                ->keyBy('status');

            $asAffiliate = CommissionPayout::query()
                ->where('affiliate_professional_id', $professional->id)
                ->selectRaw('status, COUNT(*) as count, SUM(net_payout_cents) as total_cents')
                ->groupBy('status')
                ->get()
                ->keyBy('status');
            // …
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CCG-2** · P3 — Duplicate site_media COUNT query within single brand-onboarding checklist request
    - **Where:** app/Services/Professional/Brand/BrandOnboardingReadinessService.php:62-76 and app/Services/Professional/Brand/BrandStatusService.php:248-265
    - **Affects:** Brands loading the onboarding readiness checklist — the same `COUNT` query against `site.site_media` fires twice in one request lifecycle with identical parameters.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Pass the count from `checkSiteImages()` into `syncBrandStatus()` so `hasMinimumImages()` short-circuits (or accept a precomputed `$imageCount` parameter).
        - Alternatively, request-scoped memoisation via `once()` in `BrandStatusService::hasMinimumImages()` so the second call returns the cached result within the same request.
    - **Technical:** `BrandOnboardingReadinessService::getChecklist()` calls `$this->checkSiteImages($site)` which issues a `SiteMedia::…->count()` query, then calls `$this->syncBrandStatus($professional)` which flows into `BrandStatusService::sync()` → `determine()` → `isOnboardingReady()` → `hasMinimumImages()` — and `hasMinimumImages()` issues the identical `SiteMedia::…->count()` query a second time. Same pool, purpose, media_type, and active/deleted filters. This is not an N+1 bug (no loop) but a repeated identical aggregate within one request. Impact is bounded — one extra COUNT per checklist page view — so P3.
    - **Plain English:** When a brand checks their setup checklist, we ask the database "how many images have I uploaded?" twice in a row — once for the checklist item itself and again for the overall status check. It's like asking the same question to the same person twice in the same conversation because the second question-asker didn't hear the first answer. The fix is to write the answer down on a scratchpad for the duration of the request.
    - **Evidence:**
        ```php
        // BrandOnboardingReadinessService::checkSiteImages()
        $count = $site
            ? SiteMedia::query()
                ->where('site_id', $site->id)
                ->where('pool', SiteMedia::POOL_DESIGN)
                ->where('purpose', SiteMedia::PURPOSE_PLACEHOLDER)
                ->where('media_type', SiteMedia::MEDIA_TYPE_IMAGE)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->count()
            : 0;
        ```
        ```php
        // BrandStatusService::hasMinimumImages() — same query, called later in same request
        $count = SiteMedia::query()
            ->where('site_id', $site->id)
            ->where('pool', SiteMedia::POOL_DESIGN)
            ->where('purpose', SiteMedia::PURPOSE_PLACEHOLDER)
            ->where('media_type', SiteMedia::MEDIA_TYPE_IMAGE)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->count();
        ```
    - `[DRAFT, confidence: 0.90]`
