# Caching Gold-Standard Adherence Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Caching gold-standard adherence — single-flight locks, TTL jitter, stale-while-revalidate, push-invalidation, version tokens, lock/connection hygiene, bounded TTLs, and key-generation drift (categories 1–10), measured against the `CacheLockService` / `SiteCacheService` / `UserCacheService` reference pattern documented in `docs/caching-gold-standard.md`.
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Services/Accounts/AccountCapabilities.php
- app/Services/Accounts/AccountCapabilitySet.php
- app/Services/Accounts/LifestyleConnectionCleanup.php
- app/Services/PublicSite/IndividualProfilePayloadBuilder.php
- app/Services/PublicSite/SiteActionsService.php
- app/Services/PublicSite/SitepageDataResolverService.php
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php
- app/Observers/User/UserObserver.php
- app/Services/Analytics/ContentFreshness.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [ ] **#CCH-1** · P2 — Cache-invalidation failures on all UserObserver lifecycle hooks are swallowed into an invisible `Log::warning`
    - **Where:** app/Observers/User/UserObserver.php:59-66 (`updated`), 160-167 (`deleted`), 190-197 (`restored`)
    - **Affects:** Every profile edit, soft-delete, and restore. If `UserCacheService::invalidateUser()` throws (e.g. a transient Redis blip), the DB mutation still commits but the ~10-key push-invalidation fan-out (primary + `:stale` SWR companions for the professional payload, services, dashboard services, customer count, plus the id/handle/auth-id lookup keys) silently doesn't happen — no alert fires, no compensating action runs, and the stale set survives until natural TTL/SWR expiry.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Log::warning(...)` with `report($e)` in all three catch blocks so Nightwatch surfaces the failure as an exception (per the architecture doctrine: "a failure that needs attention must throw or `$this->fail($e)`; bare `Log::warning` is invisible").
        - Add a small compensating job (`app/Jobs/Cache/InvalidateUserCacheJob.php`, modeled on the existing `ShouldBeUnique`/`ShouldQueue`/`$backoff` pattern in `WarmPublicSiteCacheJob`) dispatched from the catch block so a transient Redis outage is retried instead of silently accepted as final.
    - **Technical:** `UserCacheService::invalidateUser()` (app/Services/Cache/UserCacheService.php:273-303+) is the correct push-invalidation call and correctly forgets both primary keys and their `:stale` SWR companions — the write-side implementation itself meets the gold standard. The problem is purely in the observer's error posture: all three call sites (`updated`, `deleted`, `restored`) wrap the invalidation in `try { ... } catch (\Throwable $e) { Log::warning(...); }` with no `report()` call and no compensating action anywhere in the file (confirmed via grep — zero `report()` calls in `UserObserver.php`). Per `reference_nightwatch_alerts.md`, Nightwatch alerts trigger on exceptions and auto-detected slow jobs/routes, never on log queries, so this failure mode is invisible to on-call until a user reports stale data. The observer's `public bool $afterCommit = true` class property is correct (Laravel's documented Observer-level after-commit deferral, no interface needed) — invalidation timing relative to the DB transaction is right; only the failure-handling posture is wrong. This is a genuine, if narrow, deviation from category 4 (push-invalidation on every write path) because the invalidation is present in code but not *guaranteed* to execute — a de facto TTL-only window gated by an unlikely-but-real transient dependency failure, not a deliberate design choice.
    - **Plain English:** Picture a coat-check attendant who updates your ticket whenever you swap coats — but if the ticket printer jams, they just shrug and say "it'll sort itself out eventually." The next person who looks up your coat gets outdated information, and nobody finds out the printer jammed unless someone happens to read the logbook by hand — the automatic fire alarm (our monitoring system) only listens for actual fires, not logbook entries. The fix makes a jam trigger the fire alarm, and adds a backup runner who quietly retries the ticket update a little later.
    - **Evidence:**
        ```php
        // updated() — lines 59-66
        try {
            $this->userCache->invalidateUser($professional, bustSite: ! $publicFieldChanged);
        } catch (\Throwable $e) {
            Log::warning('Professional cache invalidation failed on update', $this->logContext(__METHOD__, [
                'user_id' => $professional->id,
                'message' => $e->getMessage(),
            ]));
        }

        // deleted() — lines 160-167
        try {
            $this->userCache->invalidateUser($professional);
        } catch (\Throwable $e) {
            Log::warning('Professional cache invalidation failed on delete', $this->logContext(__METHOD__, [
                'user_id' => $professional->id,
                'message' => $e->getMessage(),
            ]));
        }

        // restored() — lines 190-197
        try {
            $this->userCache->invalidateUser($professional);
        } catch (\Throwable $e) {
            Log::warning('Professional cache invalidation failed on restore', $this->logContext(__METHOD__, [
                'user_id' => $professional->id,
                'message' => $e->getMessage(),
            ]));
        }
        ```

## Suggested Bundled Sessions

- **Bundle 1 — UserObserver cache-invalidation observability:** #CCH-1
    - **Why grouped:** Single file, single root cause (three identical silent-catch occurrences of the same pattern) — one fix session touches `updated()`, `deleted()`, and `restored()` together.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet (S-effort, no escalation needed).

## Standalone — do NOT bundle

None.
