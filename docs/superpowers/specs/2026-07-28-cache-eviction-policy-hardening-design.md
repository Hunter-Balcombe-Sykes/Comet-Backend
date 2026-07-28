# Cache Eviction-Policy Hardening

**Date:** 2026-07-28
**Status:** Design approved, implementation pending
**Origin:** `docs/superpowers/research/cache-gold-standard-2026-07-28.md` §2.1 (P1), recommendations #1, #3, #5
**Scope:** Valkey keyspace TTL invariant + the platform actions needed to make `maxmemory-policy` a known quantity. Cloudflare CDN, edge cache, and KV are out of scope.

---

## 1. Problem

The cache architecture depends on Valkey's `maxmemory-policy`, and §0 of the research established that the application cannot read it: `INFO` returns `NOPERM`, `CONFIG GET maxmemory-policy` returns `false`. The setting is a permanent unknown — we cannot read it today and cannot detect it changing tomorrow.

The §1.3 timestamp-keyed rotation pattern (`public.profile:{handle}:{updated_at_ts}`) deliberately orphans a key on every site edit and relies on reclamation. If the policy were `noeviction`, that garbage would accumulate until writes began failing with `OOM command not allowed`.

The fix is therefore not "find out the value and set the right one" — that answer has a shelf life measured in however long it takes Laravel Cloud to change a default. The fix is to make the keyspace satisfy the precondition of the policy that is safe here, so the setting stops being load-bearing.

## 2. Corrected premises

Three corrections to §2.1's reasoning, established by reading the code:

### 2.1 The lock-failure counter is not in the cache keyspace

`CacheLockService::recordLockReleaseFailure()` calls `Redis::incr('cache:lock_release_failures')`. The bare `Redis` facade resolves the connection named `default` (`config/database.php:157`), which is `REDIS_DB=0` — the **queue and Horizon** database, not the cache database (`REDIS_CACHE_DB=1`).

Consequence: this key cannot serve as evidence that the *cache* keyspace is non-all-TTL, which is the argument §2.1 makes. It is still worth giving a TTL (§4.2), but for a different reason.

### 2.2 There is a genuine TTL-less key on the cache DB, and the research missed it

`app/Listeners/RecordScheduledTaskHeartbeat.php:21` writes `scheduler:last_run:*` via `Cache::forever()`. That *is* on DB 1. It is read by `GET /api/health/scheduler` to detect a silently-stopped cron runner.

Every other raw-Redis writer audited pairs its write with an explicit expiry: `TokenRevocationService` (`setex`, and `expire` after both `sadd` and `hset`), `LiveStatusPoller` (`expire` after `incr`, `EX` on both `set` calls), `EnquirySpamBlocklist` (`expire` after `zadd`), `StreamingTokenManager` (`EX`), `CircuitBreaker` (`setex`).

So the cache keyspace has exactly one TTL-less key, and it is not the one the research named.

### 2.3 `maxmemory-policy` is per-instance, not per-database — which makes `allkeys-lru` unsafe

All five Redis connections in `config/database.php` share one `REDIS_HOST` and `REDIS_PORT`, differing only by `database` index: 0 (queue + Horizon), 1 (cache), 2 (sessions), 3 (dormant queue-override), 4 (cache locks). Redis and Valkey apply `maxmemory-policy` per **instance**. There is no per-DB eviction policy.

Recommendation #1 of the research — request `allkeys-lru` — would therefore authorise Valkey to evict any key under memory pressure, **including queued Horizon job payloads and held locks**. Silent job loss is a materially worse outcome than a failed cache write.

The property that made `volatile-lru` look wrong in §2.1 ("a keyspace containing any non-expiring key can still OOM because nothing else is eligible") is precisely what makes it right here. On a shared instance, "evict only keys that carry a TTL" is a load-bearing safety property: cache entries all carry TTLs, queue job payloads do not. `volatile-lru` evicts exactly the class of data that is safe to lose and structurally protects the class that is not.

## 3. Decision

**Target policy: `volatile-lru`.** Make the keyspace satisfy its precondition rather than change the policy to suit the cache.

The invariant, stated once so it can be enforced:

> Every key that is safe to lose carries a TTL. Every key that is not safe to lose does not.

Under `volatile-lru` this invariant makes memory pressure resolve by discarding cache entries — which are, by definition, recomputable — while queue jobs and locks are untouchable. It also makes the keyspace self-limiting under `noeviction`, because TTL'd keys expire actively regardless of eviction policy: total cache memory is bounded by write-rate × TTL × entry-size, so the §1.3 rotation garbage clears within its own TTL even if nothing is ever evicted. The design is then correct under `volatile-lru` and survivable under `noeviction`, which is as policy-independent as this platform permits.

## 4. Code changes

### 4.1 Unit A — heartbeat key gains a TTL

`app/Listeners/RecordScheduledTaskHeartbeat.php`

Replace `Cache::forever(self::CACHE_PREFIX.$key, ...)` with `Cache::put(self::CACHE_PREFIX.$key, ..., now()->addDays(30))`.

30 days is chosen flat rather than computed. `HealthController::scheduler()` scores each task against `max(2 × cron interval, 3600s)`, and the longest-interval task currently scheduled is daily — a 2-day window. 30 days clears every current task by an order of magnitude.

A future weekly or monthly task would exceed a 30-day window only in the monthly case, and the failure mode there is benign: the second pass in `HealthController` forgives a null heartbeat whenever any *other* task proves the runner alive, so an expired key degrades one row's `last_run_at` to `null` in the response body rather than producing a false 503. The only scenario that turns into a real false alarm is a monthly task that is the *sole* scheduled task, which cannot occur.

A comment on the line records why the TTL exists — that this key must remain evictable — so it is not reverted to `forever` by someone reasoning only about the health check.

### 4.2 Unit B — lock-failure counter gains a TTL

`app/Services/Cache/CacheLockService.php`

After `Redis::incr('cache:lock_release_failures')`, add `Redis::expire('cache:lock_release_failures', 7 * 86400)`. Both calls stay inside the existing `try`/`catch (Throwable)` that already swallows driver errors, so a failed `expire` cannot cascade.

The docblock currently documents "no TTL" as deliberate. It is rewritten to describe a 7-day rolling window and to state that the counter exists for ops to spot driver issues, not for lifetime accounting — nothing is lost by the window.

**The key stays on the `default` connection (DB 0).** Moving it to the cache connection was considered and rejected: it would change where ops look for the key, add the cache prefix to a name documented in the docblock, and bring it into reach of `Cache::flush()`'s raw `FLUSHDB`. A TTL is sufficient; relocation is churn.

### 4.3 Unit C — constraints test

`tests/Feature/Cache/CacheKeyspaceConstraintsTest.php`

In the established house style (`tests/Feature/Database/ArchitectureSystemConstraintsTest.php`, `PolicyCoverageTest`): scan `app/` for `forever(` and fail with an explicit allowlist array that starts empty.

The guard is deliberately narrow. `forever(` is a zero-false-positive grep. The broader rule — "a raw `Redis` write with no paired expiry" — is not statically checkable without flow analysis and would flag every legitimate two-call write in `TokenRevocationService`, `LiveStatusPoller`, and `EnquirySpamBlocklist`. Per the audit-pipeline lesson, a noisy guard gets suppressed and then protects nothing.

The test's docblock states the invariant from §3 and its reasoning — shared instance, `volatile-lru`, queue-job protection — so a developer who trips it understands the constraint rather than allowlisting past it. Raw-`Redis` writers are covered by a CLAUDE.md convention note and code review instead.

## 5. Platform actions

Two items require console or support access and cannot be done in code. Both write their result back into `docs/superpowers/research/cache-gold-standard-2026-07-28.md` §0 so the table stops recording an unknown.

1. **Read `maxmemory-policy` and `maxmemory`** in the Laravel Cloud console for **both** dev and prod. If either is `noeviction` or `allkeys-*`, request `volatile-lru`. If already `volatile-lru`, record that; no request needed. Record both values per environment regardless of outcome — `maxmemory` is needed to reason about headroom.

2. **Escalate the `INFO` / `CONFIG` `NOPERM` gap** to Laravel Cloud support (research item #5). This is the durable fix. Without it, `evicted_keys`, `used_memory`, and `keyspace_hits/misses` remain unreadable, so even a correct policy is unverifiable and cache saturation stays invisible until it causes an incident.

Item 1 gates nothing in §4 — the code changes are correct under any policy and should not wait on console access.

## 6. Documentation updates

- `docs/superpowers/research/cache-gold-standard-2026-07-28.md`: correct §2.1 with the three premises from §2 above, and amend recommendation #1 from `allkeys-lru` to `volatile-lru` with the shared-instance reasoning. Update the §0 table when the console values are read.
- `CLAUDE.md`: one line under the Cache/Queue row recording the all-TTL invariant and that `maxmemory-policy` is instance-wide across DB 0/1/2/4.

## 7. Testing

- Unit A: extend the existing heartbeat/health-scheduler coverage with an assertion that the written key has a positive TTL.
- Unit B: assert `recordLockReleaseFailure()` issues both `incr` and `expire`, and that a throwing `expire` is swallowed rather than propagated.
- Unit C: is itself the test. Verify it fails before Unit A lands and passes after — a constraints test that has never been seen red is not known to work.

Tests run on SQLite while production is Postgres, but nothing here touches the database. Unit C is a static file scan and so is driver- and store-independent; Units A and B assert against the cache store the existing `tests/Feature/Cache/` suite already uses.

## 8. Out of scope

Deliberately excluded, each a separate change with separate risk:

- Research items 2 (per-prefix `min_hit_rate` map), 4 (`REDIS_IGBINARY`), 6 (deferred SWR recompute), 7 (CLAUDE.md Cluster note).
- **Splitting cache onto its own Valkey instance.** This is the structurally correct long-term answer: it decouples the cache's eviction needs from the queue's durability needs and would make `allkeys-lru` both safe and optimal for the cache instance. It is a platform and cost decision, not a code fix. Recording it here as the change that retires the constraint in §3.
