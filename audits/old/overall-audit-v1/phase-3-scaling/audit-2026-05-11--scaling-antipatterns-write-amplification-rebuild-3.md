`★ Insight ─────────────────────────────────────`
The grep results confirm two critical facts: (1) `getVisitStats` and `getClickStats` appear **only** in their definition file — zero callers anywhere in the codebase, meaning the 180-key `deleteMultiple` loop in `invalidateAnalytics` deletes keys that are never written. (2) Commerce/Stripe paths call `bumpAnalyticsVersion` directly (bypassing the dead loop), so only the public site analytics ingest path bears this overhead.
`─────────────────────────────────────────────────`

# Scaling Antipatterns — Write Amplification, Rebuild-on-Write, Weak Caching Audit — 2026-05-11

**Branch:** development
**Lens:** Scaling antipatterns: write amplification, rebuild-on-write, weak caching
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Services/Notifications/CommerceNotificationService.php
- app/Services/Notifications/NotificationPublisher.php
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
- app/Notifications/Affiliate/AffiliatePayoutGraceWarningNotification.php
- app/Notifications/Brand/BrandPayoutFundingFailedNotification.php
- app/Services/Cache/AnalyticsCacheService.php
- app/Services/Cache/CacheKeyGenerator.php
- app/Services/Cache/CacheLockService.php
- app/Http/Controllers/Api/PublicSite/AnalyticsController.php
- app/Http/Controllers/Api/Professional/ProfessionalAnalyticsController.php
- app/Http/Controllers/Api/Professional/Booking/BookingAnalyticsController.php
- app/Http/Controllers/Api/Staff/StaffSite/StaffStatsController.php

## Adjudication notes

**DeepSeek CACHE-2 (per-brand notification insert loop) — dropped.** N is bounded by brands connected to a single affiliate booking (~1–5 at scale target). Falls under the always-drop rule for N+1 on < 50 rows. Not a write-amplification finding at this cardinality.

**DeepSeek CACHE-3 (weekly analytics job per-chunk query) — dropped.** Confidence 0.6, below the 0.7 threshold. Not a security/data issue. The one-query-per-chunk contract is intentional and enforced by `SendWeeklyAnalyticsNotificationJobQueryCountTest`. The proposed "cache the whole result set" fix inverts the correct design.

**No Rebuild*AggregatesJob files exist** — the booking analytics rebuild jobs mentioned in the scope directive have already been removed. `app/Jobs/Analytics/` is empty. No finding required.

**No Observers directory exists** — `app/Observers/` has no files. No per-save dispatch findings possible.

---

## P3 — Nice to have

- [ ] **#CACHE-1** · P3 — Booking milestone totals cache is TTL-only; milestone notifications delayed up to 60s after the triggering event
    - **Where:** app/Services/Notifications/CommerceNotificationService.php:134
    - **Affects:** Booking milestone notifications ("You have reached 10 total bookings", "Bookings revenue reached $500") for affiliate professionals. At pre-beta scale (< 5 bookings/minute), the 60-second delay is imperceptible. At target scale (50 affiliates × booking burst rates), the milestone fires up to one TTL cycle late. No duplicate notifications — the publisher's dedupe key (`booking:count:<threshold>`) remains correct across refreshes. No data loss.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - After `$this->publisher->publish(...)` fires the per-booking notification in `notifyBookingCompleted()`, call `Cache::forget(CacheKeyGenerator::bookingMilestoneTotals($professionalId))` so the next call to `notifyBookingMilestonesForProfessional` always reads the fresh aggregate.
        - Alternatively, accept the intentional 60s window — the existing code comment already documents this trade-off and the dedupe key safety net is correct. If the booking feature remains deprioritised (see `project_booking_dropped.md`), defer this indefinitely.
    - **Technical:** `notifyBookingMilestonesForProfessional` wraps a `COUNT(*)/SUM(amount_paid_cents)` scan of `analytics.booking_events` in `CacheLockService::rememberLocked` with a 60-second TTL. No `Cache::forget` is issued on the write path after a booking is recorded. If bookings #9 and #10 land within the same 60s window, the "10 bookings" threshold check still sees the snapshot from booking #9, and the milestone notification is delayed until natural TTL expiry. The publisher's dedupe key (`booking:count:10`) prevents double-fire once the cache refreshes, so this is a latency issue rather than a correctness issue. Category (3): TTL-only cache, no push-invalidation on the write path.
    - **Plain English:** Think of a loyalty punch-card app that only syncs your punch count once a minute. You hit the 10th punch, but the app still shows 9 for up to 60 seconds, so your "10 punches — free coffee!" message arrives late. The safeguard against getting two messages works correctly, but the delay exists. Since your team has paused the booking feature entirely, this is a tidy-up when bookings are revisited rather than anything urgent.
    - **Evidence:**
        ```php
        // Only ever expires naturally — no Cache::forget after a new booking is recorded.
        $totals = $this->cacheLock->rememberLocked(
            CacheKeyGenerator::bookingMilestoneTotals($professionalId),
            self::MILESTONE_TOTALS_TTL_SECONDS,  // 60
            function () use ($professionalId): array {
                $row = DB::table('analytics.booking_events')
                    ->where('professional_id', $professionalId)
                    ->selectRaw('COUNT(*) as bookings_count')
                    ->selectRaw('COALESCE(SUM(amount_paid_cents), 0) as total_spent_cents')
                    ->first();
        ```

- [ ] **#CACHE-2** · P3 — `invalidateAnalytics` runs a 180-key deletion loop on every public analytics event for cache keys that are never written
    - **Where:** app/Services/Cache/AnalyticsCacheService.php:96–107
    - **Affects:** Every call to `PublicSite/AnalyticsController::pageview`, `click`, and `cartEvent` runs `invalidateAnalytics`, which builds a 180-entry key array (`analytics:visits:<id>:Ymd:Ymd` × 90 days + `analytics:clicks:<id>:Ymd:Ymd` × 90 days) and issues a single `Cache::deleteMultiple` Redis call. The target keys are written only by `AnalyticsCacheService::getVisitStats` and `AnalyticsCacheService::getClickStats` — neither of which has any call site in the codebase (confirmed by grep: definitions only, zero callers). Every deletion pipeline is a no-op at the data layer but still costs a Redis round-trip with a 180-key payload. At pre-beta scale (< 100 events/day) this is negligible; at 1,000 events/day it adds 1,000 wasted round-trips. It also runs in-band on the public HTTP request thread, not a queued job. Category (3): `Cache::forget` on a set of keys where no write path populates them.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the 90-day visit/click key deletion loop (lines 96–107) from `invalidateAnalytics`. The version token increment (`bumpAnalyticsVersion`) already busts all analytics summary keys atomically; the explicit projection-key deletes are also retained separately and are valid.
        - If `getVisitStats` / `getClickStats` are intended for a future feature, document why they are unused and leave the invalidation loop commented out with a cross-reference, or delete the methods and the loop together.
        - For the public analytics ingest path, consider whether `invalidateAnalytics` should be dispatched as a short-lived queued job rather than run synchronously per request, to keep the ingest endpoint's P99 latency independent of Redis round-trip count.
    - **Technical:** `AnalyticsCacheService::invalidateAnalytics` has three sections: (1) `bumpAnalyticsVersion` — correct and needed; (2) explicit `Cache::forget` on projection key variants — correct and needed; (3) a `for ($i = 0; $i < 90; $i++)` loop generating per-day `analyticsVisits` and `analyticsClicks` keys fed into `Cache::deleteMultiple`. Section (3) was meaningful when `getVisitStats`/`getClickStats` were active, but both methods are now dead code (confirmed by exhaustive grep: zero callers in `app/`). The keys they would populate — `analytics:visits:{id}:{Ymd}:{Ymd}` and `analytics:clicks:{id}:{Ymd}:{Ymd}` — are never written, so every `deleteMultiple` call silently returns 0 for all 180 keys. Notably, the Stripe and commerce webhook paths already call `bumpAnalyticsVersion` directly, bypassing this dead loop; only the public site ingest path hits `invalidateAnalytics` and therefore pays this cost on every event.
    - **Plain English:** Imagine a cleaning crew that vacuums 90 rooms every time a new visitor signs the guest book — but those 90 rooms are always empty because no one ever uses them. The rooms that matter (the ones guests actually sit in) get cleaned by a different, correct process. The 90-room sweep is pure wasted effort. The fix is to stop sending the crew to empty rooms.
    - **Evidence:**
        ```php
        // Delete the rolling 90-day window of visit and click stat keys.
        // Each entry covers a single day (start === end === that day's date).
        $keys = [];

        for ($i = 0; $i < 90; $i++) {
            $date = Carbon::now()->subDays($i)->format('Ymd');

            $keys[] = CacheKeyGenerator::analyticsVisits($professionalId, $date, $date);
            $keys[] = CacheKeyGenerator::analyticsClicks($professionalId, $date, $date);
        }

        Cache::deleteMultiple(array_values(array_unique($keys)));
        ```
        No call sites exist for the methods that write these keys — confirmed by codebase-wide grep:
        ```
        // Only result for getVisitStats|getClickStats across all of app/:
        app/Services/Cache/AnalyticsCacheService.php:15  (definition)
        app/Services/Cache/AnalyticsCacheService.php:44  (definition)
        ```

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 2 complete
