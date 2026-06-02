`★ Insight ─────────────────────────────────────`
**Key adjudication patterns in play here:**
1. **Silent catch re-tier:** DeepSeek tiered structurally identical catch-block findings inconsistently (P2 for the service, P3 for the controller). Same root cause → same tier, per the calibration rule. `StaffAnalyticsController` is being bumped to P2.
2. **Fat controller + service duplication:** The staff controller re-implements three query blocks that already exist in `AnalyticsQueryService`. This is both an `OBS-2` logging gap *and* an `OBS-6` service-boundary finding — fixing the refactor (P3) automatically resolves the logging gap without a separate patch.
3. **New finding (`OBS-6`):** DeepSeek missed the service-boundary violation entirely. The lens explicitly covers fat controllers / service-boundary correctness, and verbatim evidence exists in the already-read files.
`─────────────────────────────────────────────────`

# Observability & Architecture Hygiene Audit — 2026-05-31

**Branch:** development
**Lens:** Observability and architecture hygiene and test coverage, missing structured log context, PII in logs, silent catch blocks, exception/slow-job coverage gaps, audit-log integrity, service-boundary correctness, fat controllers, dead code post-strip, test coverage of critical paths
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Services/Analytics/AnalyticsQueryService.php
- app/Services/Cache/UserCacheService.php
- app/Services/Cache/CacheLockService.php
- app/Http/Controllers/Api/Staff/StaffSite/StaffAnalyticsController.php
- app/Http/Controllers/Api/PublicSite/AnalyticsController.php
- tests/Feature/Staff/StaffAnalyticsControllerTest.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 3 complete

---

## P2 — Should fix

- [ ] **OBS-1** · P2 — AnalyticsQueryService silently swallows click-table QueryExceptions across four sites
    - **Where:** app/Services/Analytics/AnalyticsQueryService.php:88, 118, 223, 257
    - **Affects:** Observability — every user's analytics dashboard silently shows zero clicks when `analytics.link_clicks` is missing or a query fails. There is no Nightwatch signal to distinguish "no clicks today" from "click pipeline broken."
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `use Illuminate\Support\Facades\Log;` to the file's imports.
        - In each of the four `catch (QueryException)` blocks, capture the exception: `catch (QueryException $e)`.
        - Add `Log::warning('analytics.click_query_failed', ['method' => __METHOD__, 'user_id' => $userId, 'error' => $e->getMessage()])` before the `return` in each block.
        - Keep returning the zero/empty defaults — the resilience behaviour is intentional and correct.
    - **Technical:** The class-level docblock explicitly documents the intentional fallback for SQLite test environments where not all migrations are applied. This is the right call for test resilience, but it means production failures are indistinguishable from "no data yet." Adding a `warning` with `__METHOD__` context gives Nightwatch four distinct signals to aggregate. If `analytics.link_clicks` goes missing in production, the warning count spikes immediately on the first dashboard load rather than waiting for a user complaint.
    - **Plain English:** Imagine your website's click counter shows zero for every user because the database table holding clicks broke overnight. There is no alarm and no warning — it just silently shows zero. We need our monitoring system to notice this quietly-broken state. Adding one log line per failure (while still showing zero to users, so the page doesn't crash) is all it takes to turn a silent problem into a detectable one.
    - **Evidence:**
        ```php
        } catch (QueryException) {
            return (object) ['total_clicks' => 0, 'unique_clickers' => 0, 'last_click_at' => null];
        }
        ```

- [ ] **OBS-2** · P2 — StaffAnalyticsController silently swallows the same click-table failures as OBS-1 — same root cause, same tier
    - **Where:** app/Http/Controllers/Api/Staff/StaffSite/StaffAnalyticsController.php:106, 134, 151
    - **Affects:** Observability — staff members investigating zero-click analytics for a user cannot tell whether clicks are genuinely zero or whether the `analytics.link_clicks` query itself failed. DeepSeek originally tiered this P3, but it is structurally identical to OBS-1 (same table, same zero-return pattern, same absent logging) and must carry the same tier. Note: OBS-6 (P3) proposes delegating to `AnalyticsQueryService`, which would fix this automatically once OBS-1 is resolved; if OBS-6 is done first, this fix becomes redundant.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Add `use Illuminate\Support\Facades\Log;` to the controller imports.
        - Change each `catch (Throwable)` to `catch (Throwable $e)` and add `Log::warning('staff.analytics.click_query_failed', ['professional_id' => $professional->id, 'error' => $e->getMessage()])` in each block.
        - Keep the existing zero-value defaults — the resilience posture is correct.
    - **Technical:** The controller introduces three inline click-analytics query blocks that duplicate what `AnalyticsQueryService` already provides, but without the service's logging fix once OBS-1 is applied. DeepSeek tiered this P3 while tiering the structurally identical service pattern P2, which is an inconsistency: same missing log context, same schema table, same user-visible consequence. `$professional->id` is in scope at all three catch sites and serves as the Nightwatch correlation key.
    - **Plain English:** Same problem as OBS-1, but on the page a support person uses to look at a user's analytics. They see zero clicks and can't tell if that's real or if the system broke. We want one quiet note in the logs when the system breaks, so the engineering team knows to investigate rather than assuming everything is fine.
    - **Evidence:**
        ```php
        } catch (Throwable) {
            $clicksAgg = (object) [
                'total_clicks' => 0,
                'unique_clickers' => 0,
                'last_click_at' => null,
            ];
        }
        ```

- [ ] **OBS-3** · P2 — UserCacheService::getByAuthId silently repairs a stale auth mapping without logging the event
    - **Where:** app/Services/Cache/UserCacheService.php:166
    - **Affects:** Observability (security-adjacent) — a mismatch between the cached `auth_user_id` and the JWT `sub` claim indicates a stale or corrupted cache entry. The code correctly self-heals but emits no signal. If mismatches accumulate — for example due to a cache invalidation bug or a key collision — there is no Nightwatch breadcrumb to trigger an investigation.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Add `use Illuminate\Support\Facades\Log;` to the file's imports.
        - Add `Log::warning('cache.auth_id_mismatch', ['cached_user_id' => $id, 'auth_user_id' => $authUserId])` immediately before the `Cache::forget($authIdKey)` call.
        - Do not log the full model object — `$id` and `$authUserId` are UUIDs with no PII exposure.
    - **Technical:** The code comments document `auth_user_id` as immutable (set at creation, never updated), so any mismatch here is abnormal by construction — either the `pro:map:auth:{uid}` key returned a stale value pointing at a different user's ID, or the hydrated model stored in Redis has a corrupted `auth_user_id` field. The repair logic is correct and the no-cross-user-data guarantee holds. What is missing is the observability half: without a log, a recurrent invalidation bug causing this branch to fire on every request would go undetected until someone manually compares cache state against the database.
    - **Plain English:** Imagine a hotel receptionist who gives you the wrong room key, realizes the mistake immediately, and silently swaps it for the right one — but never tells the manager it happened. If this keeps happening for different guests, nobody notices the key-card system has a bug. Logging the correction means the engineering team gets a breadcrumb: if this warning starts appearing regularly, something deeper needs investigating.
    - **Evidence:**
        ```php
        // Defensive guard: if cache is stale/corrupt, never return another user's profile.
        if ((string) $professional->auth_user_id !== $authUserId) {
            $authIdKey = CacheKeyGenerator::userIdByAuthId($authUserId);
            $modelKey = CacheKeyGenerator::professionalModel($id);
            Cache::forget($authIdKey);
            Cache::forget($modelKey);
            Cache::forget($modelKey.':stale');
        ```

---

## P3 — Nice to have

- [ ] **OBS-4** · P3 — CacheLockService::recordLockReleaseFailure inner catch is completely silent when Redis is unreachable
    - **Where:** app/Services/Cache/CacheLockService.php:278
    - **Affects:** Observability — if the `Redis::incr` call that counts lock-release failures itself throws (Redis unreachable), the failure is entirely swallowed. The counter silently stops incrementing with no operator signal that lock-release failures are occurring at all.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Change `catch (\Throwable)` to `catch (\Throwable $e)`.
        - Add `Log::warning('cache.lock_release_failure_counter_failed', ['error' => $e->getMessage()])`. Laravel's file/stack log driver is independent of Redis and will succeed even when Redis is down — the no-cascade contract is preserved.
        - Add `use Illuminate\Support\Facades\Log;` to the file's imports if not already present.
    - **Technical:** The Redis counter exists specifically to expose lock-release failures to ops. If the counter itself fails (most likely because Redis is down — the exact condition that causes lock-release failures to spike), the counter stops working and the warning is silently lost. The outer catch was correctly designed to avoid cascading. However, Laravel's log channel stack is typically file-backed and unaffected by Redis availability, so logging inside the catch is safe and gives ops at least one signal on the first broken request.
    - **Plain English:** We keep a tally of how often a key fails to be released, so we can spot if something is wrong. If the tally-book catches fire too, we just close the door and pretend nothing happened. We should at least scribble a note in the regular logs — which don't depend on Redis — saying "the tally book burned."
    - **Evidence:**
        ```php
        private function recordLockReleaseFailure(): void
        {
            try {
                Redis::incr('cache:lock_release_failures');
            } catch (\Throwable) {
                // Swallow — a failure to count a failure must not cascade.
            }
        }
        ```

- [ ] **OBS-5** · P3 — RUM beacon catch block is completely empty — a broken log driver goes undetected
    - **Where:** app/Http/Controllers/Api/PublicSite/AnalyticsController.php:241
    - **Affects:** Observability — if `Log::info('rum', ...)` throws (e.g., a misconfigured log channel, a write-permission issue on the log file), every RUM beacon request silently fails. Performance timing data stops flowing with no operator signal.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - The exception variable is already captured (`catch (\Throwable $e)`). Add `Log::warning('analytics.rum_logging_failed', ['error' => $e->getMessage()])` inside the catch.
        - The comment intent ("never bubble logging errors back to the visitor") is fully preserved — the endpoint still returns 200.
    - **Technical:** The handler is deliberately fire-and-forget: a transient log failure on one request is acceptable. However, a persistent log-driver misconfiguration would silently kill all RUM data collection. Since `Log::warning` uses the same driver as `Log::info`, if the driver is broken both may fail — but a best-effort warning is still consistent with the existing design and would surface at least once on the first broken request.
    - **Plain English:** We record page-load performance for every visitor. If the recording system breaks, we're happy to lose one measurement — but we still want to know the recording system itself is sick. Right now if it breaks, nobody finds out until a developer manually checks the performance charts and notices the gap.
    - **Evidence:**
        ```php
        } catch (\Throwable $e) {
            // RUM is best-effort; never bubble logging errors back to the visitor.
        }
        ```

- [ ] **OBS-6** · P3 — StaffAnalyticsController inlines three click-analytics query blocks that already exist in AnalyticsQueryService
    - **Where:** app/Http/Controllers/Api/Staff/StaffSite/StaffAnalyticsController.php:98–153
    - **Affects:** Service-boundary correctness — click-analytics logic is maintained in two places. Any change to `AnalyticsQueryService` (including the logging fix in OBS-1) does not propagate to the staff view. The two query surfaces can diverge silently on schema changes, new column additions, or query optimisations. Resolving this also makes OBS-2 redundant.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Inject `AnalyticsQueryService` into `StaffAnalyticsController::__construct()` alongside the existing `CacheLockService`.
        - Replace the three inline try/catch blocks (clicks aggregate, clicks by day, top links) with calls to `$this->analyticsQuery->clicksAggregate()`, `->clicksByBucket()`, and `->topLinks()`.
        - The staff `topLinks` query is simpler (omits `platform`/`category` columns) — accept the additional fields from the service method rather than maintaining a lighter variant in the controller.
        - After this refactor, OBS-2's logging gap is resolved automatically once OBS-1 is applied to the service.
    - **Technical:** `StaffAnalyticsController` was written without injecting the analytics service, leading to three schema-aware SQL blocks that duplicate `AnalyticsQueryService`. The controller imports `Illuminate\Support\Facades\DB` directly and re-implements the same `COUNT(DISTINCT COALESCE(visitor_id::text, ip_hash))` patterns. Any evolution to the click-query shape — partitioning, schema renames, new dedup logic — must now be applied in two places. Fat controllers with duplicated service logic are explicitly in scope for this audit lens.
    - **Plain English:** We have two managers who each keep their own handwritten sales ledgers instead of reading from the shared accounting system. When the accounting system gets updated with better reporting, both managers are still using their own old ledgers and see different numbers. Consolidating to one source means both always see the same picture, and any improvements only need to be made once.
    - **Evidence:**
        ```php
        // Inline in StaffAnalyticsController, duplicating AnalyticsQueryService::clicksAggregate()
        try {
            $clicksAgg = DB::table('analytics.link_clicks')
                ->where('user_id', $professional->id)
                ->whereBetween('occurred_at', [$from, $to])
                ->selectRaw('COUNT(*) as total_clicks')
                ->selectRaw('COUNT(DISTINCT COALESCE(visitor_id::text, ip_hash)) as unique_clickers')
                ->selectRaw('MAX(occurred_at) as last_click_at')
                ->first();
        } catch (Throwable) {
            $clicksAgg = (object) [
                'total_clicks' => 0,
                'unique_clickers' => 0,
                'last_click_at' => null,
            ];
        }
        ```

`★ Insight ─────────────────────────────────────`
**What just happened in adjudication:**
1. **OBS-4 (StaffAnalyticsController) was re-tiered P3 → P2** because the calibration rule "same root cause, same tier" applies: the staff controller's silent catch pattern is structurally identical to the service's silent catch pattern (OBS-1). DeepSeek inconsistently tiered these.
2. **OBS-6 is a new finding** that DeepSeek missed entirely — the fat-controller duplication pattern is the *root cause* of why OBS-2 needs its own fix rather than inheriting OBS-1's fix. Structural findings like this often cascade into multiple surface-level symptoms.
3. **Silent-catch findings on best-effort endpoints (OBS-5) stay P3** — the failure mode (log driver breaking) is rare, the consequence is limited to one telemetry stream, and the endpoint's design contract already accepts loss.
`─────────────────────────────────────────────────`

The final audit contains 6 verified findings with no hallucinated evidence — all code excerpts were confirmed against source before inclusion.
