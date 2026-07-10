# Caching Gold-Standard Adherence Audit — 2026-07-08

**Branch:** audit-fix/middleware-2026-07-06
**Lens:** Caching: gold-standard adherence audit — measuring every cache read/write against `CacheLockService`/`SiteCacheService::getPublicSitePayload` (single-flight lock, TTL jitter, stale-while-revalidate, push-invalidate, version tokens, Redis/`cache_locks` connection hygiene, bounded TTLs, centralized key generation)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Services/Analytics/AnalyticsCacheService.php
- app/Services/Analytics/AnalyticsDedupGuard.php
- app/Services/Streaming/StreamingTokenManager.php
- app/Services/Streaming/LiveStatusPoller.php
- app/Http/Middleware/IdempotencyKey.php
- app/Services/User/AccountDeletionService.php
- app/Http/Middleware/Auth/VerifySupabaseJwt.php
- app/Services/FeatureFlags/FeatureFlagService.php
- app/Services/Notifications/NotificationListingService.php
- app/Http/Middleware/Logging/LogLeadRateLimits.php
- app/Services/Cache/CacheKeyGenerator.php
- app/Services/Cache/CacheLockService.php
- app/Observers/Core/ServiceCategoryObserver.php
- config/cache.php, config/database.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 5 complete
- P3 Low: 0 of 5 complete

---

## P2 — Should fix

- [ ] **#CCH-1** · P2 — `AnalyticsCacheService::bumpVersion` coordinates via the bare `Cache::` facade, which resolves to the `failover` store in production
    - **Where:** app/Services/Analytics/AnalyticsCacheService.php:44-45
    - **Affects:** The analytics summary version-token bump for every professional's ingest event; during a Redis outage — the exact scenario `CACHE_STORE=failover` exists to survive — this debounce/version-bump silently becomes per-worker instead of fleet-wide.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Pin both the debounce `Cache::add` and the `Cache::increment` call to an explicit store, mirroring `NotificationPublisher::store()`/`NotificationListingService::bustIndexCache()`: `app()->environment('testing') ? Cache::store() : Cache::store('redis')`.
        - Fix in the same change as #CCH-10 — same method, same lines.
    - **Technical:** `.env.example` documents `CACHE_STORE=failover` as the intended production value; `config/cache.php` defines `'failover' => ['stores' => ['redis', 'array']]`, a deliberate design (shipped as `#GS-2`, see `audits/archive/audit-2026-05-07-caching-foundation.md`) so a Redis outage degrades the app to "no shared cache" rather than crashing it. `bumpVersion()` calls the bare `Cache::add(...)`/`Cache::increment(...)` facade, which resolves through that `failover` wrapper — during an active outage it transparently falls through to the in-process `array` driver. For *read* caches that fallback is an accepted, intentional degradation. But `bumpVersion` is a *write-path invalidation* primitive — the whole point of the version token is that every worker's next read observes the bump. Falling through to `array` makes the increment worker-local, so during precisely the outage window this pattern exists to survive, cross-fleet invalidation stops propagating. `NotificationListingService`/`NotificationPublisher` already encode the fix for this exact class of primitive: pin to `Cache::store('redis')` outside tests so an outage surfaces as a caught, logged failure instead of a silent partial degrade.
    - **Plain English:** The system has a deliberate safety net so that if the shared cache goes down, the site keeps working — it just quietly serves from each server's own private memory for a while. That's fine for the memory used to speed up reads. But this piece of code is a "your data changed, forget the old copy" note, not a read — and if it gets stuck writing to one server's private memory during that outage, the other servers never hear the note. Two other places in the codebase already learned this lesson and were fixed; this one wasn't.
    - **Evidence:**
        ```php
        public function bumpVersion(string $userId): void
        {
            try {
                if (Cache::add("analytics:ingest-debounce:{$userId}", 1, 30)) {
                    Cache::increment(CacheKeyGenerator::analyticsSummaryVersion($userId));
                }
            } catch (Throwable $e) {
                Log::warning('analytics.cache_bump_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            }
        }
        ```

- [ ] **#CCH-2** · P2 — `AnalyticsDedupGuard::claim` coordinates visitor-beacon dedup via the bare `Cache::` facade — same failover fallthrough gap as #CCH-1
    - **Where:** app/Services/Analytics/AnalyticsDedupGuard.php:24-28
    - **Affects:** Visitor/click-beacon deduplication for the whole analytics ingest pipeline; during a Redis outage the dedup key space becomes per-worker, so the same beacon load-balanced across two workers within the TTL window can each independently report "novel."
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Pin `Cache::add`/`Cache::get` in `claim()` to an explicit store, same pattern as #CCH-1.
        - Fix alongside #CCH-1/#CCH-3 — same root-cause pattern across three files.
    - **Technical:** Same mechanism as #CCH-1: `claim()` uses `Cache::add`/`Cache::get` as an atomic SETNX-style coordination primitive, and the bare facade resolves through the production `failover` store. The class's docblock says "Fail-open: any cache fault is swallowed and treated as novel" — correct behavior for a genuine Redis *exception*. But `failover`'s fall-through to `array` on an outage doesn't throw at all — it succeeds against a per-worker store, so `claim()` never reaches its fail-open branch; it just silently coordinates within one worker instead of across the fleet. Blast radius is bounded (duplicate analytics rows during an outage, not data loss), but it's the same fixable gap as #CCH-1.
    - **Plain English:** This is the doorperson checking who's already been let in, so the same visit isn't counted twice — normally off one shared guest list. If the shared list system hiccups, this doorperson falls back to a private notepad, but a doorperson at a different entrance has their own separate notepad — so the same guest can walk in twice without either one noticing. Fixing it means both doorpeople always try the one shared list first.
    - **Evidence:**
        ```php
        if (Cache::add($key, $mintedUuid, $ttlSeconds)) {
            return ['novel' => true, 'id' => $mintedUuid];
        }

        $original = Cache::get($key);
        ```

- [ ] **#CCH-3** · P2 — `StreamingTokenManager`'s OAuth-refresh lock uses a raw Redis facade on the `default` connection instead of `Cache::lock`
    - **Where:** app/Services/Streaming/StreamingTokenManager.php:64-72
    - **Affects:** Twitch/Kick OAuth client-credentials token refresh — the single-flight guard for this refresh bypasses every gold-standard primitive (`CacheLockService`, jitter, `cache_locks` connection).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the ad-hoc `Redis::set($lockKey, '1', 'EX', 30, 'NX')` / `usleep` / `Redis::del` sequence with `Cache::store('cache_locks')->lock($lockKey, 30)` and its `->block($seconds)` / `->release()` API.
        - Move the token value itself off the raw `Redis::` default connection onto an explicit store too.
    - **Technical:** `config/database.php` defines four distinct Redis connections: `default` (`REDIS_DB`, DB 0 — used by any bare `Redis::` facade call), `cache` (`REDIS_CACHE_DB`, DB 1 — backs `Cache::store('redis')`), `session` (DB 2), and `cache_locks` (`REDIS_CACHE_LOCKS_DB`, DB 4 — where every sanctioned lock in this codebase is supposed to live, per `config/cache.php`'s `lock_connection`). `StreamingTokenManager::refreshToken` calls the bare `Redis::set(...)` facade for both the token value and the `NX` lock, landing on the `default` connection (DB 0) — a connection none of the Cache stores use. This isn't merely "on the wrong side of a `Cache::flush()`" (a flush against `Cache::store('redis')` only touches DB 1 either way) — it's an entirely separate, ad-hoc coordination mechanism with no jitter, no `CacheKeyGenerator`-derived key, and no `block()`/timeout semantics (just a fixed `usleep(500_000)` retry-once). Every other distributed lock in the codebase (`IdempotencyKey`, `VerifySupabaseJwt::jwksOutage`, `LogLeadRateLimits`) goes through `Cache::lock(...)` and inherits the `cache_locks` connection automatically; this one doesn't.
    - **Plain English:** Every other "only one person at a time" lock in this system uses the same well-tested rope-and-door mechanism, kept on its own dedicated wall so nothing else can knock it down by accident. This one piece of code built its own rope from scratch, tied it to a wall nobody else uses, and only checks once (with a fixed half-second pause) before giving up. It works today, but skips every safety net — jitter, shared observability, a proper wait queue — that the rest of the system's locks get for free.
    - **Evidence:**
        ```php
        $lockKey = self::REFRESH_LOCK_PREFIX.$platform;
        $locked = Redis::set($lockKey, '1', 'EX', 30, 'NX');

        if (! $locked) {
            // Another process is refreshing — wait briefly and read what they wrote.
            usleep(500_000);

            return Redis::get(self::TOKEN_KEY_PREFIX.$platform) ?: null;
        }
        ```

- [ ] **#CCH-4** · P2 — Idempotency user-index key is hardcoded independently in two files, with no shared source of truth
    - **Where:** app/Http/Middleware/IdempotencyKey.php:224-227 (write + read side) and app/Services/User/AccountDeletionService.php:296-304 (GDPR purge read side)
    - **Affects:** Account-deletion GDPR purge (`AccountDeletionService::purgeIdempotencyCache`, shipped in `69e49a6f` for PRIV-1) — the only consumer of the per-user idempotency index outside the middleware itself.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `CacheKeyGenerator::idempotencyUserIndex(string $userId): string` returning `"idempotency:index:{$userId}"`.
        - Replace `IdempotencyKey::userIndexKey()`'s body and `AccountDeletionService::purgeIdempotencyCache()`'s inline literal with calls to that one generator method.
        - Add a test asserting both call sites resolve to the same string, so a future rename in one file can't silently drift from the other.
    - **Technical:** `IdempotencyKey::userIndexKey()` returns `"idempotency:index:{$userId}"`, and `AccountDeletionService::purgeIdempotencyCache()` independently hardcodes the identical literal `"idempotency:index:{$authUserId}"` — the values match today, but nothing enforces that. A future change to the middleware's key format (e.g. adding a version segment, the way `IdempotencyKey::cacheKey()` already carries a `{$version}` segment this index key deliberately omits) would silently orphan `AccountDeletionService`'s copy, leaving PII-bearing cached responses live past account deletion for the rest of the 24h TTL — a data-retention regression in a control that exists specifically to prevent that. This is the gold-standard category-8 drift risk made concrete rather than hypothetical, since the string is *already* duplicated verbatim today.
    - **Plain English:** When someone deletes their account, one service is supposed to find every leftover cached copy of their data and shred it, using an index card that says where all the copies are filed. Right now, two different parts of the codebase have independently written out the exact same instructions for finding that index card instead of one part looking it up from a single master copy. They agree today — but if anyone ever updates one copy of the instructions without knowing about the other, the shredding service would look in the wrong place, and a deleted user's cached data would quietly survive.
    - **Evidence:**
        ```php
        // app/Http/Middleware/IdempotencyKey.php
        private function userIndexKey(string $userId): string
        {
            return "idempotency:index:{$userId}";
        }
        ```
        ```php
        // app/Services/User/AccountDeletionService.php
        private function purgeIdempotencyCache(string $authUserId): void
        {
            ...
            $connection = Redis::connection('cache');
            $indexKey = "idempotency:index:{$authUserId}";
        ```

- [ ] **#CCH-5** · P2 — `LiveStatusPoller` writes streaming live/offline TTLs unjittered, and its staleness check depends on that predictability
    - **Where:** app/Services/Streaming/LiveStatusPoller.php:122 (live), 139 (offline), 151-159 (`filterStaleHandles`)
    - **Affects:** Public sitepage streaming live-status badge (read via `LiveStatusInjector`) for every professional with a connected Twitch/Kick handle.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Apply `JitteredTtl::applyJitter($ttl)` to the `EX` value in both `writeStatus()` calls.
        - Because `filterStaleHandles()` compares `Redis::ttl($key)` against a fixed threshold, jittering the write TTL alone makes that comparison unreliable — replace the TTL-remaining check with an explicit `written_at` timestamp stored alongside the status and compare `now() - written_at` against the threshold instead.
        - Cover both changes with one test asserting the warm/cool/cold demotion tiers still behave correctly once TTLs are jittered.
    - **Technical:** `writeStatus()` calls the bare `Redis::set($liveKey, ..., 'EX', $ttl)` facade directly (bypassing `Cache::`/`CacheLockService` entirely, same pattern as #CCH-3) with a literal config-driven int TTL — `live_ttl_seconds` (180s default), or one of three tiered offline TTLs (180/600/1800s). None pass through `JitteredTtl::applyJitter`. Because the scheduled poller writes many handles' status in the same invocation, a batch polled together gets the *same* TTL written at nearly the same moment — a Redis restart or scheduler resync resynchronises their expiry. `filterStaleHandles()` then reads `Redis::ttl($key)` and compares it to `partna.streaming.ttl_skip_threshold` (60s default) to decide which handles are due for re-poll — this check is only meaningful if the TTL degrades predictably from a known starting point, so naively jittering the write would desynchronize otherwise-identical handles from the demotion tiers in ways the current threshold logic doesn't account for. Both halves need to move together.
    - **Plain English:** Every streaming-status sticker on the wall currently says "replace me in exactly 180 seconds" (or 600, or 1800, depending how long the professional's been offline), and a batch of stickers goes up at the same moment during each check-in cycle — meaning they also all come due at the same moment. The "is this sticker old enough to need checking?" logic and the "how long until it expires?" logic are the same number today, so you can't add a random wobble to reduce that synchronization without also teaching the checking logic to tell time a different way (by how long ago it was written, not how long is left).
    - **Evidence:**
        ```php
        // Live
        Redis::set($liveKey, '1', 'EX', (int) config('partna.streaming.live_ttl_seconds', self::LIVE_TTL_DEFAULT));
        Redis::del($countKey);
        ```
        ```php
        // Offline, tiered TTL
        Redis::set($liveKey, '0', 'EX', $ttl);
        ```
        ```php
        // Staleness check depends on the raw remaining TTL
        $ttl = Redis::ttl($key);

        return $ttl < (int) config('partna.streaming.ttl_skip_threshold', self::TTL_SKIP_DEFAULT);
        ```

## P3 — Nice to have

- [ ] **#CCH-6** · P3 — Supabase JWKS cache key hardcoded inline instead of routed through `CacheKeyGenerator`
    - **Where:** app/Http/Middleware/Auth/VerifySupabaseJwt.php:462
    - **Affects:** JWT verification path only — a single call site; no other reader/writer of this key exists in the repo.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `CacheKeyGenerator::supabaseJwks(): string` returning `'supabase:jwks'`.
        - Replace the literal in `fetchJwksOrThrow()`.
    - **Technical:** `'supabase:jwks'` is passed as a literal string to the sole `rememberLocked` call in the file; a repo-wide search confirms no other file reads or writes this key. The gold standard's category-8 rule is a blanket consistency rule, but the drift risk here is effectively zero today — this is a hygiene item, not a live bug.
    - **Plain English:** This is a filing-cabinet drawer with its label written directly on it in marker instead of printed from the shared label-maker everyone else uses. Only one person ever opens this drawer, so a mislabel is unlikely — but the office policy says every drawer gets the same kind of label, and this one is the odd one out.
    - **Evidence:**
        ```php
        $jwks = $this->cacheLock->rememberLocked('supabase:jwks', config('supabase.jwks_cache_seconds', 300), function () use ($jwksUrl) {
        ```

- [ ] **#CCH-7** · P3 — `FeatureFlagService`'s registry/pro-override keys are hardcoded inline instead of routed through `CacheKeyGenerator`
    - **Where:** app/Services/FeatureFlags/FeatureFlagService.php:38 (`REGISTRY_KEY` const), 190-194 (`forgetPro`), 307 (`loadProOverrides`)
    - **Affects:** Feature-flag evaluation for every request that resolves flags for a professional; confirmed self-contained — no other file in the repo references `ff:pro:` or `ff:registry`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `CacheKeyGenerator::featureFlagRegistry(): string` and `CacheKeyGenerator::featureFlagProOverrides(string $proId): string`.
        - Replace the class constant and the two inline `"ff:pro:{$proId}"` sites with calls to the generator.
    - **Technical:** `FeatureFlagService` builds `'ff:registry'` (a class constant) and `"ff:pro:{$proId}"` (independently in `forgetPro()` and `loadProOverrides()`) without going through `CacheKeyGenerator`. A repo-wide grep confirms these prefixes appear nowhere else, so there's no live drift today — the same bounded-impact pattern as #CCH-6, just with two call sites, worth centralizing before a third caller (e.g. a staff tool to bulk-clear flag overrides) has to guess the format.
    - **Plain English:** This service keeps its own private label-maker for two kinds of storage boxes. It's consistent today because only this file uses it — but if anyone outside this file ever needs to find the same box (say, a future admin tool that resets a professional's flags), they'd have to guess the label format instead of reading it from the shared supply closet.
    - **Evidence:**
        ```php
        private const REGISTRY_KEY = 'ff:registry';
        ...
        public function forgetPro(string $proId): void
        {
            Cache::forget("ff:pro:{$proId}");
            Cache::forget("ff:pro:{$proId}:stale");
        }
        ```

- [ ] **#CCH-8** · P3 — `NotificationListingService`'s index cache key is built via inline string concatenation instead of `CacheKeyGenerator`
    - **Where:** app/Services/Notifications/NotificationListingService.php:171-174
    - **Affects:** Notification bell/list cache for professionals; self-contained today — only `NotificationListingService` reads and writes it.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `CacheKeyGenerator::notificationsList(string $userId, int $limit, bool $includeDismissed): string`.
        - Replace the private `cacheKey()` body with a call to it.
    - **Technical:** `cacheKey()` builds `"pro:{$userId}:notifications:".$limit.':'.($includeDismissed ? 'dismissed' : 'live')` inline; both the read (`index()`) and the bust (`bustIndexCache()`) call the same private method, so there is no live drift. The risk is a future external invalidator (a staff-side "clear this user's notification cache" tool, or a global broadcast bust) that would need to reproduce this exact format from scratch.
    - **Plain English:** The notification service's cache label is written once and reused everywhere inside that file, so it's consistent today. But it lives in a private notebook rather than the shared reference book everyone else uses — if another feature ever needs to clear a user's notification cache from outside this file, someone would have to reverse-engineer the label format by reading this file's source.
    - **Evidence:**
        ```php
        private function cacheKey(string $userId, int $limit, bool $includeDismissed): string
        {
            return "pro:{$userId}:notifications:".$limit.':'.($includeDismissed ? 'dismissed' : 'live');
        }
        ```

- [ ] **#CCH-9** · P3 — `LogLeadRateLimits`' rate-limit dedup key uses an unjittered config-driven TTL
    - **Where:** app/Http/Middleware/Logging/LogLeadRateLimits.php:54-57
    - **Affects:** Lead-submission rate-limit analytics logging; bounded to a per-(ip_hash, subdomain) dedup key with a 10s default window.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Route the write through `JitteredTtl::applyJitter($dedupSeconds)` for consistency with the gold standard.
    - **Technical:** `Cache::add($dedupKey, 1, $dedupSeconds)` passes a raw config-driven int (`partna.analytics.lead_rate_limit_dedup_seconds`, default 10) with no jitter. Because the key is partitioned by `ip_hash` + subdomain, synchronized expiry across *different* IPs is harmless; the only exposure is two workers processing the same IP's retry burst within the same second seeing simultaneous expiry, producing at most one duplicate `LeadSubmission` row. Low severity, but a one-line fix.
    - **Plain English:** This is a "please don't log this again yet" sticky note that always falls off the wall at exactly 10 seconds. If two people look at the exact same sticky at the exact same instant, they might both log the same event once more than needed. Adding a small random wobble to the countdown makes that coincidence far less likely.
    - **Evidence:**
        ```php
        $dedupSeconds = (int) config('partna.analytics.lead_rate_limit_dedup_seconds', 10);
        $dedupKey = "partna:rate-limit-logged:{$ipHash}:".($subdomain ?? 'unknown');
        if (! Cache::add($dedupKey, 1, $dedupSeconds)) {
            return;
        }
        ```

- [ ] **#CCH-10** · P3 — `AnalyticsCacheService`'s ingest-debounce gate uses an unjittered literal TTL
    - **Where:** app/Services/Analytics/AnalyticsCacheService.php:44
    - **Affects:** Analytics summary version-token bump after ingest; same file/method as #CCH-1 — fix together.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the literal `30` with `JitteredTtl::applyJitter(30)` (pull the trait into `AnalyticsCacheService`, mirroring `IdempotencyKey`'s use of the same trait).
    - **Technical:** `Cache::add("analytics:ingest-debounce:{$userId}", 1, 30)` hardcodes `30` with no jitter. This is a debounce gate (at most one version bump per window per user), not a data cache, so synchronized expiry across the fleet only means every worker's debounce gate for a given user opens at the same instant — bounded to one redundant `Cache::increment` call, not a stampede. Jittering to ~24-36s preserves the debounce's intent while closing the gold-standard gap; land it in the same change as #CCH-1 since both touch this exact method.
    - **Plain English:** The analytics system puts up a brief "already handled, don't recount" sign for 30 seconds after processing new data. Every copy of that sign currently counts down from the exact same number, so in rare cases they could all come down at once and trigger one extra bit of recounting. A small random wobble on the countdown avoids that at basically zero cost.
    - **Evidence:**
        ```php
        if (Cache::add("analytics:ingest-debounce:{$userId}", 1, 30)) {
            Cache::increment(CacheKeyGenerator::analyticsSummaryVersion($userId));
        }
        ```

## Suggested Bundled Sessions

- **Bundle 1 — `AnalyticsCacheService::bumpVersion` hygiene:** #CCH-1, #CCH-10
    - **Why grouped:** Same file, same two-line method — one session pins the store and jitters the TTL in one pass.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Distributed-coordination Redis-store pinning:** #CCH-2, #CCH-3
    - **Why grouped:** Same root-cause pattern across two files — a distributed-coordination primitive (dedup / lock) riding either the bare failover-wrapped `Cache::` facade or a raw `Redis::` default-connection call instead of an explicit sanctioned store.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 3 — Cache-key centralization + dedup jitter mop-up:** #CCH-4, #CCH-6, #CCH-7, #CCH-8, #CCH-9
    - **Why grouped:** All are single-file (or, for #CCH-4, one shared-string) mechanical fixes — add a `CacheKeyGenerator` method or apply `JitteredTtl::applyJitter` — independent of each other but cheap to land together in one review pass.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 4 — Live-status TTL jitter + staleness-check refactor:** #CCH-5
    - **Why grouped:** Single finding, but kept in its own session because the fix isn't just "add jitter" — it requires rewriting `filterStaleHandles()`'s staleness detection to use a written-at timestamp instead of raw remaining TTL, and the write/read sides must land together to avoid breaking the warm/cool/cold demotion tiers.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet (consider escalating review → Opus given the demotion-tier interaction).

## Standalone — do NOT bundle

None — no P0, auth/authorization, money, or DB-migration items surfaced, and no finding here exceeds M effort.
