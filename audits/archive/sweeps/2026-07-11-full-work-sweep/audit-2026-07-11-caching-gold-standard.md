# Caching Gold-Standard Audit — 2026-07-11

**Branch:** HEAD
**Lens:** Caching: gold-standard adherence — deviations from `CacheLockService::rememberLocked` / `SiteCacheService::getPublicSitePayload` gold standard (single-flight locks, TTL jitter, stale-while-revalidate, push-invalidation, version tokens, lock hygiene, bounded TTLs, centralised key generation)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Http/Middleware/AddPublicCacheHeaders.php
- app/Http/Middleware/Auth/EnsurePartnaAdmin.php
- app/Http/Middleware/Auth/EnsurePartnaStaff.php
- app/Http/Middleware/Auth/RequireAal2.php
- app/Http/Middleware/Auth/VerifySupabaseJwt.php
- app/Http/Middleware/Context/EnforcePendingDeletionReadOnly.php
- app/Http/Middleware/Context/LoadCurrentUser.php
- app/Http/Middleware/IdempotencyKey.php
- app/Http/Middleware/Logging/LogLeadRateLimits.php
- app/Http/Middleware/Logging/RecordStaffAuditEntry.php
- app/Http/Middleware/Moderation/PerTargetReportThrottle.php
- app/Http/Middleware/VerifyBotToken.php
- app/Services/Accounts/AccountCapabilities.php
- app/Services/Accounts/AccountCapabilitySet.php
- app/Services/Cache/CacheKeyGenerator.php
- app/Services/Cache/SiteCacheService.php
- app/Services/Cache/UserCacheService.php
- app/Services/FeatureAvailability/FeatureAvailability.php
- app/Services/FeatureAvailability/UserFeatureAvailability.php
- app/Services/PublicSite/IndividualProfilePayloadBuilder.php
- app/Services/PublicSite/SiteActionsService.php
- app/Services/PublicSite/SitepageDataResolverService.php
- app/Services/Site/ContentSelectionService.php
- app/Services/Site/UpdateSiteAction.php
- app/Jobs/Cloudflare/CloudflareCachePurgeJob.php
- app/Observers/Core/IntegrationConnectionObserver.php
- app/Observers/User/UserObserver.php
- app/Services/Analytics/AnalyticsCacheService.php
- app/Services/Analytics/AnalyticsDedupGuard.php
- app/Services/Analytics/AnalyticsEvent.php
- app/Services/Analytics/AnalyticsQueryService.php
- app/Services/Analytics/Concerns/EscalatesRepeatedFaults.php
- app/Services/Analytics/ContentFreshness.php
- app/Services/Analytics/ContentPopularityReader.php
- app/Services/Analytics/Ingestors/QueuedIngestor.php
- app/Services/Analytics/InsightEngine.php
- app/Services/Analytics/RankedActionsComputer.php
- app/Services/Analytics/Writers/PostgresEventWriter.php
- app/Services/Notifications/Dispatchers/AchievementNotifier.php
- app/Services/Notifications/Dispatchers/PlatformHealthNotifier.php
- app/Services/Notifications/NotificationPublisher.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 4 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **#CCH-1** · P1 — `FeatureAvailability::for()` uses bare `Cache::remember` without single-flight lock
    - **Where:** app/Services/FeatureAvailability/FeatureAvailability.php:41-45
    - **Affects:** Every professional whose dashboard hits `GET /platforms/meta` (`IntegrationsMetaController`), which resolves availability for every registry platform on load. After a staff member edits a feature-availability rule (`flush()` bumps the version token), every connected user's next dashboard load is a simultaneous cold miss that independently re-queries `feature_availability_rules` + resolves segment membership.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Inject `CacheLockService` into `FeatureAvailability` (or resolve it via the container) and replace `Cache::remember(...)` with `$cacheLock->rememberLocked($key, self::CACHE_TTL_SECONDS, fn () => self::resolveOverrides($user))`.
        - This single change also closes CCH-2 (jitter) and CCH-3 (SWR) below — `rememberLocked` applies both automatically.
    - **Technical:** `FeatureAvailability::for()` is the only read path for `core.feature_availability_rules` and is invoked from `IntegrationsMetaController::__invoke` on every dashboard-index load. After `flush()` increments the version token (staff CRUD), every per-user cache key becomes a fresh miss simultaneously; with bare `Cache::remember`, concurrent requests (a user's own polling/tabs, or the fleet-wide effect of a staff-managed global rule change) each independently execute the query + `SegmentResolver` resolution instead of blocking on a single regenerator. `CacheLockService::rememberLocked` wraps this in `Cache::lock` (on the `cache_locks` connection) so exactly one caller regenerates while the rest block briefly and read the fresh fill.
    - **Plain English:** When staff flip a feature flag, every user's saved answer to "can I use this?" goes stale at the same instant. Right now, if several people load their dashboard in that moment, each one independently re-asks the database the same question at the same time — like a hundred people all ringing the same doorbell simultaneously instead of one person ringing it and everyone else waiting for the door to open.
    - **Evidence:**
        ```php
        $overrides = Cache::remember(
            "feature-availability:user:{$user->id}:v{$version}",
            self::CACHE_TTL_SECONDS,
            fn () => self::resolveOverrides($user),
        );
        ```

## P2 — Should fix

- [ ] **#CCH-2** · P2 — `FeatureAvailability::for()` writes with a hardcoded, unjittered 60s TTL
    - **Where:** app/Services/FeatureAvailability/FeatureAvailability.php:33, 43
    - **Affects:** Every user whose feature-availability entry was written in the same second (post-flush stampede, deploy restart) — all expire on the same tick.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Route the write through `CacheLockService::rememberLocked` (jitters automatically) — same fix as CCH-1.
    - **Technical:** `CACHE_TTL_SECONDS` is a literal `60` written straight into `Cache::remember`. Every entry written within the same second shares the same expiry second, synchronising a re-fetch stampede across users. `JitteredTtl::applyJitter(60)` (applied automatically by `rememberLocked`) spreads expiry across ~48–72s.
    - **Plain English:** All the cached feature-flag answers are set to expire at exactly the same second, like every parking meter in a city running out at 3:00:00 PM sharp — everyone refills at once. A small random offset spreads the expirations out so the rush never forms.
    - **Evidence:**
        ```php
        private const CACHE_TTL_SECONDS = 60;
        // ...
        $overrides = Cache::remember(
            "feature-availability:user:{$user->id}:v{$version}",
            self::CACHE_TTL_SECONDS,
            fn () => self::resolveOverrides($user),
        );
        ```

- [ ] **#CCH-3** · P2 — `FeatureAvailability::for()` has no stale-while-revalidate companion
    - **Where:** app/Services/FeatureAvailability/FeatureAvailability.php:41-45
    - **Affects:** Any caller whose per-user entry expired — blocks on the DB query + segment resolution instead of getting last-good immediately.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Same fix as CCH-1/CCH-2 — `rememberLocked` pairs every write with a `:stale` copy at 10× TTL automatically.
    - **Technical:** `Cache::remember` writes only the primary key; on expiry every concurrent caller blocks on `resolveOverrides()`. Impact is bounded (60s TTL, per-user key) but is still a deviation from the gold standard `rememberLocked` provides for free.
    - **Plain English:** When a user's cached feature-flag answer expires, the next request has to wait for the full lookup to finish. With a stale-while-revalidate pattern they'd get the previous answer instantly while a worker quietly refreshes it in the background — like a shop that keeps selling from the shelf while a stocker restocks out back.
    - **Evidence:**
        ```php
        $overrides = Cache::remember(
            "feature-availability:user:{$user->id}:v{$version}",
            self::CACHE_TTL_SECONDS,
            fn () => self::resolveOverrides($user),
        );
        // No :stale companion written anywhere.
        ```

- [ ] **#CCH-4** · P2 — `AnalyticsCacheService::computeInsights` swallows exceptions and caches an empty result for the full 1h TTL
    - **Where:** app/Services/Analytics/AnalyticsCacheService.php:131, 142-209 (`insights()` / `computeInsights()`)
    - **Affects:** Every professional viewing the analytics dashboard "Insights" card — a transient DB blip produces an empty insights panel that `rememberLocked` then caches fleet-wide for up to an hour, self-healing only on TTL expiry, with no Nightwatch signal.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the `try/catch` inside `computeInsights` and let exceptions bubble out of the `rememberLocked` closure so Nightwatch surfaces the fault and `rememberLocked` never persists it.
        - If a fail-open empty panel is genuinely wanted, cache it via a short, dedicated negative-cache path (e.g. `rememberLockedNullable` with a short null-TTL) rather than the same 3600s key `summary()`'s siblings use.
    - **Technical:** `insights()` calls `$this->cacheLock->rememberLocked($cacheKey, 3600, fn (): array => $this->computeInsights($professional))`. Inside `computeInsights`, `catch (Throwable $e)` returns `[]`, which `rememberLocked` then writes to the primary AND `:stale` Redis keys for a full hour — a textbook category-10 violation (read-through hides errors). Notably, `summary()` in the same class does NOT wrap its closure in try/catch — exceptions there bubble and Nightwatch fires — so this is an inconsistency within one service, not a deliberate house style.
    - **Plain English:** Imagine a specials board that gets locked behind glass for an hour once written. If the chef has a bad morning and can't cook, the board gets locked with a blank sheet for the full hour — every customer sees "no specials" even after the kitchen recovers ten minutes later. The fix is to not lock up a blank board — let the manager (monitoring) know something went wrong instead of hiding it behind a plausible-looking empty result.
    - **Evidence:**
        ```php
        return $this->cacheLock->rememberLocked($cacheKey, 3600, fn (): array => $this->computeInsights($professional));
        ```
        ```php
        private function computeInsights(User $professional): array
        {
            try {
                $proId = $professional->id;
                // ... computes insights from queries ...
                return $insights;
            } catch (Throwable $e) {
                Log::warning('analytics.insights_failed', ['user_id' => $professional->id, 'error' => $e->getMessage()]);

                return [];
            }
        }
        ```

- [ ] **#CCH-5** · P2 — `FeatureAvailability::resolveOverrides()` swallows DB exceptions and caches the empty ("all features available") sentinel for the full TTL
    - **Where:** app/Services/FeatureAvailability/FeatureAvailability.php:62-70
    - **Affects:** All users for up to 60 seconds after any transient DB error — the fail-open empty map ("all features available," including gated integrations) gets cached fleet-wide via the enclosing `Cache::remember`, even after the DB recovers seconds later.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Let the `\Throwable` bubble out of the closure so it is never cached; Nightwatch will surface the fault and the next request retries fresh.
        - If fail-open is non-negotiable for the SQLite-test-mirror case the comment describes, guard that specific case explicitly (e.g. a config flag) rather than swallowing all `\Throwable`s, and if a sentinel is still wanted, cache it with a short, dedicated TTL via `rememberLockedNullable` rather than the shared 60s key.
    - **Technical:** The closure passed to `Cache::remember` in `for()` calls `resolveOverrides()`, which catches `\Throwable` and returns `[]` on any DB failure — this empty map is then written into Redis for 60s by the enclosing `Cache::remember`/`rememberLocked` call. A 2-second DB blip becomes a 60-second fleet-wide fail-open window where every staff-managed restriction (e.g. `integration.<platform>` availability) reads as "available" regardless of intent, even after the DB is healthy again.
    - **Plain English:** If the database hiccups for two seconds, the system stores "no restrictions apply" for a full minute — even after the database recovers 58 seconds early. It's like a restaurant that runs out of a dish for two minutes and then leaves a "temporarily unavailable" sign up for an hour by mistake.
    - **Evidence:**
        ```php
        try {
            $rules = FeatureAvailabilityRule::query()->get(['feature_key', 'mode', 'segment_id']);
        } catch (\Throwable) {
            // Fail-open to "everything available" — matches the absence-=-enabled
            // contract. Also covers SQLite test mirrors without the table.
            return [];
        }
        ```

## P3 — Nice to have

- [ ] **#CCH-6** · P3 — `FeatureAvailability` builds cache keys with ad-hoc string concatenation instead of `CacheKeyGenerator`
    - **Where:** app/Services/FeatureAvailability/FeatureAvailability.php:33, 35, 42
    - **Affects:** Maintainability only today (single reader/writer, same class) — future readers (e.g. a staff preview endpoint) risk a silent typo-miss without a shared helper.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `featureAvailability(string $userId, int $version): string` and `featureAvailabilityVersion(): string` to `CacheKeyGenerator` and call them from `FeatureAvailability::for()` / `flush()`.
    - **Technical:** Every other cache-service in `app/Services/Cache/` sources its keys from `CacheKeyGenerator` (per the gold standard's category 8). `FeatureAvailability` defines `'feature-availability:version'` and `"feature-availability:user:{$user->id}:v{$version}"` inline. No drift exists yet because reader and writer are colocated in one class, but it's the one cache-touching class in scope that doesn't follow the central-key-registry convention.
    - **Plain English:** The cache keys for feature flags are handwritten strings inside one file instead of coming from the app's central "key directory." It works today because nothing else reads them, but if a second feature (like a staff preview tool) needs the same keys later, someone could mistype the string and silently miss the cache — like keeping one spare house key in a drawer instead of the labeled key cabinet everyone else uses.
    - **Evidence:**
        ```php
        private const CACHE_VERSION_KEY = 'feature-availability:version';

        $version = (int) Cache::get(self::CACHE_VERSION_KEY, 0);

        $overrides = Cache::remember(
            "feature-availability:user:{$user->id}:v{$version}",
        ```

## Suggested Bundled Sessions

- **Bundle 1 — FeatureAvailability caching hardening:** #CCH-1, #CCH-2, #CCH-3, #CCH-5, #CCH-6
    - **Why grouped:** Same file (`app/Services/FeatureAvailability/FeatureAvailability.php`), same root fix — routing `for()` through `CacheLockService::rememberLocked` closes the single-flight, jitter, and SWR gaps in one edit; the exception-swallowing (#CCH-5) and key-centralisation (#CCH-6) cleanups are trivial adjacent changes to the same ~10 lines.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet (all S-effort; no escalation needed).

- **Bundle 2 — Analytics insights fail-open caching:** #CCH-4
    - **Why grouped:** Single finding, isolated to `AnalyticsCacheService::computeInsights` — distinct file/service from Bundle 1, no shared fix.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

None.
