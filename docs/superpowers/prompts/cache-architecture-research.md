# Cache Architecture Research Prompt

Paste this entire message into a fresh Claude session (Sonnet or Opus) to run a
comprehensive cache-architecture research programme. It will spawn a fleet of
research agents, audit the current codebase, and produce a gold-standard
recommendation report.

---

## Task

Research the state of the art in application-level caching and produce a
concrete, prioritized set of recommendations for this Laravel 12 + Redis
(Valkey) backend.

**Scope:** Redis application cache only — NOT Cloudflare CDN, edge cache, or KV.

**Output:** A markdown report saved to
`docs/superpowers/research/cache-gold-standard-2026-07-28.md` with:

1. What this codebase already does at or above industry gold-standard
2. Gaps ranked by impact/effort
3. One recommendation per gap, concrete enough to implement

---

## Phase 0 — Understand the current architecture

Read these files to orient yourself (do NOT audit yet — just understand):

| File | What it tells you |
|---|---|
| `config/cache.php` | Store topology: Redis DB 1 (data), DB 4 (locks), isolated `lock_connection` |
| `config/database.php` §redis | Connection names → DB numbers |
| `config/partna.php` §cache | TTL registry, SLO config, purge follow-up schedule |
| `config/horizon.php` | Queue lanes; note `cache-warm` as its own lane |
| `app/Services/Cache/CacheKeyGenerator.php` | ~60 key methods; note `:stale` convention, multi-site annotations |
| `app/Services/Cache/CacheLockService.php` | Single-flight lock + SWR + jitter engine |
| `app/Services/Cache/SiteCacheService.php` | Public payload caching (95% of traffic); backward-compat healing |
| `app/Services/Cache/UserCacheService.php` | Multi-level professional cache (ID lookup → model), defensive auth-id guard |
| `app/Services/Cache/SiteCacheInvalidator.php` | Observer propagation pattern |
| `app/Services/Cache/Concerns/JitteredTtl.php` | ±20% jitter implementation |
| `app/Observers/Core/SiteObserver.php` | `saved` → `invalidateSite()` → `CloudflareCachePurgeJob` + `WarmPublicSiteCacheJob` |
| `app/Observers/Core/BlockObserver.php` | Block writes → `SiteCacheInvalidator::touchSite()` |
| `app/Jobs/Cache/AggregateCacheMetricsJob.php` | Hourly SLO check (min 90% hit rate) |
| `app/Listeners/RecordCacheMetrics.php` | Per-operation hit/miss counter |

Key architectural facts to internalise:
- **Isolated lock DB:** `Cache::flush()` issues `FLUSHDB` on DB 1; locks on DB 4 survive it
- **SWR everywhere:** Every hot key writes a `:stale` twin at 10× the primary TTL. On primary expiry, stale copy serves immediately while one worker recomputes — no synchronous blocking for 95%+ of reads
- **TTL jitter ±20%:** Independent draws for primary and stale keys so they don't expire in lockstep
- **Timestamp-keyed rotation:** `public.profile:{handle}:{updated_at_ts}` — mutations rotate the key forward; no explicit `Cache::forget` needed
- **Resolve-floor pattern:** A monotonic floor prevents a pre-commit reader from re-putting a stale timestamp after invalidation
- **Negative caching:** `__MISS__` sentinel for 404s, `NULL_SENTINEL` for lookup misses; prevents bot-scan DB storms
- **Cache-warm lane:** `WarmPublicSiteCacheJob` dispatched to a dedicated Horizon lane so warm-up never competes with real traffic
- **No tags:** Laravel's `Cache::tags()` is NOT used — Redis tag support was removed from Laravel circa v10. All invalidation is explicit key enumeration
- **Budget counters:** `ApifyBudget`, `AiSpendBudget`, `PlacesBudget` use Redis INCR with date-suffixed keys for daily cost ceilings
- **Multi-level auth path:** `pro:map:auth:{uid}` (30min) → `pro:model:{id}` (60s SWR) — two-tier so the immutable auth→id mapping survives model-cache expiry

---

## Phase 1 — Fleet research (run in parallel)

Launch these 6 agents simultaneously. Each should produce a 500-1000 word
brief with concrete examples, NOT generic advice. Instruct each to cite
specific systems, versions, or papers.

### Agent 1: High-scale Laravel caching

Research how the most demanding Laravel applications handle Redis application
caching at scale. Look for:

- How Laravel Cloud's own infrastructure caches (it runs on AWS Lambda +
  Cloudflare; what does that mean for cache architecture?)
- Production patterns from Laravel communities: Laracasts, Laravel News,
  conference talks from Laracon (2024-2026)
- How teams using Laravel Octane (Swoole/FrankenPHP) adapt their cache
  strategy for long-lived processes
- Real-world cache architectures from Laravel shops that have written about
  them publicly (Flare/Spork, Mailcoach, etc.)
- The `Cache::tags()` deprecation story — what replaced it in the ecosystem?

**Search:** Web + GitHub for "Laravel Redis cache architecture production",
"Laravel Octane cache strategy", "Laravel cache stampede prevention", "Laravel
Cache::tags alternative"

### Agent 2: Non-Laravel app-cache architectures

Study how major non-Laravel systems architect their application cache layer.
Focus on systems with publicly-documented architecture:

- **Shopify** — Rails + Redis + Memcached; their cache invalidation is
  famously sophisticated. How do they handle multi-tenant cache isolation?
- **Discord** — Elixir + Redis; real-time at enormous scale. How does their
  cache strategy differ from request/response patterns?
- **GitHub** — Ruby + Redis + Memcached; one of the longest-running Rails
  monoliths. What cache patterns survived 15 years of scaling?
- **Slack** — PHP/Hack + Redis; the largest PHP deployment in the world.
  What do they cache, and how?
- **Stripe** — Ruby + Redis; API-first with strict consistency requirements.
  How does caching coexist with financial data integrity?

**Search:** Web for each: "{company} cache architecture", "{company}
engineering blog Redis caching", plus "how {company} handles cache
invalidation" for Shopify specifically (their "caching is hard" blog post is
canonical)

### Agent 3: Cache invalidation — academic + industry state of the art

This is the hard problem. Research:

- Phil Karlton's axiom ("two hard things: naming things, cache invalidation,
  and off-by-one errors") — what have we learned since?
- **Write-through vs write-behind vs write-around** — when does each win?
- **Cache-Aside** (the pattern this codebase uses: check cache, miss → DB →
  populate cache) — what are its failure modes at scale?
- **Read-through / Write-through** — when is it worth the abstraction layer?
- **Event-driven invalidation** — is the Observer pattern (what this codebase
  uses) the best approach, or do event buses / change-data-capture (CDC) win
  at scale?
- **Time-to-live vs explicit invalidation** — what does the research say about
  the optimal mix?
- **Cache stampede / thundering herd** — survey of prevention strategies:
  locking (this codebase), probabilistic early recompute (XFetch), external
  recompute, no-lock lease. Which wins under what conditions?
- **The "dual write" problem** — DB + cache in the same transaction? Outside?
  What do the distributed-systems papers say?

**Search:** Web + academic: "cache invalidation strategies survey", "thundering
herd prevention Redis", "XFetch algorithm cache stampede", "probabilistic
cache recompute", "cache-aside pattern failure modes"

### Agent 4: Redis topology + memory management for caching

Research Redis-specific cache architecture decisions:

- **Single-node vs Cluster** — at what scale does Redis Cluster become
  necessary for caching? What breaks? (Laravel's Redis cache driver does not
  support Cluster for locks — is that still true?)
- **Isolating cache from queue** — this codebase uses separate Redis DBs (DB 1
  for cache, DB 0 for queue). Is that sufficient isolation, or should they be
  separate instances? What does `FLUSHDB` risk really look like in production?
- **Memory management:** `volatile-lru` vs `allkeys-lru` vs `volatile-ttl` —
  which eviction policy is optimal for an app cache that mixes short-TTL (60s
  model cache) and long-TTL (24h email brand) keys?
- **Key count vs memory:** When does a large number of small keys (analytics
  dedup keys, handle-resolve entries) become a problem?
- **Valkey vs Redis OSS:** Laravel Cloud uses Valkey. What differences matter
  for caching? (Valkey 8 has some improvements over Redis 7.)
- **Persistence for cache?** RDB/AOF on a cache instance — waste of I/O or
  useful for surviving restarts with a warm cache?

**Search:** Web: "Redis cache topology best practices", "Redis eviction policy
caching", "Valkey vs Redis caching", "Redis cluster Laravel cache", "Redis
FLUSHDB production risk"

### Agent 5: TTL strategy — the science of expiry

Research how the industry thinks about TTL design:

- **The 1−1/(λ·TTL) ceiling** — this codebase already documents that a 60s TTL
  needs ~10 reads/min to reach 90% hit rate. What TTL strategies do high-scale
  systems use to push past this?
- **Adaptive TTL** — systems that vary TTL based on access frequency (hot keys
  live longer). Who does this? Does it work in practice?
- **Stale-while-revalidate** — this codebase uses a home-grown SWR with
  `:stale` keys. How do other systems implement SWR? Redis' `--stale` flag?
  Application-level?
- **Negative caching** — how long should a "not found" be cached? This
  codebase uses 30s for misses, paired with a 5min stale copy. Is that optimal?
- **Grace periods on shutdown** — when Redis restarts (deploy, crash), should
  the cache warm-up strategy differ? What about cache stampede on cold start?
- **The "TTL as SLA" pattern** — treating TTL as a data-freshness contract
  rather than a memory-management knob. Who does this?

**Search:** Web: "adaptive TTL caching strategy", "stale-while-revalidate
Redis pattern", "cache hit rate formula", "negative caching TTL best
practices", "cache stampede on cold start"

### Agent 6: Cache monitoring + observability

Research how mature systems observe their cache:

- **Hit rate monitoring** — this codebase tracks hit/miss per-operation and
  checks a 90% SLO hourly. Is that the right metric? What about latency
  percentiles?
- **Cache efficiency metrics** — recompute ratio (misses → DB hits), fill
  ratio (writes → reads), key churn rate. Which matter most?
- **Cache-alarm anti-patterns** — when does a hit-rate alert fire because
  traffic is low, not because the cache is broken? (This codebase already has
  this problem with the 90% SLO — is there a better approach?)
- **Redis-side monitoring** — keyspace hits/misses from `INFO stats`, memory
  fragmentation, eviction count. What should trigger an alert?
- **Distributed tracing through cache** — how do systems trace a request that
  hits cache at layer 1, misses at layer 2, and hits DB at layer 3?

**Search:** Web: "Redis cache monitoring best practices", "cache hit rate SLO",
"cache observability patterns", "Redis INFO stats alerting"

---

## Phase 2 — Codebase audit

Now read the cache implementation files in detail and score each against the
research findings. Produce a table:

| Dimension | Current approach | Gold standard (from research) | Gap? | Severity |
|---|---|---|---|---|
| Key design | Central generator, namespaced, documented | | | |
| Stampede prevention | Single-flight lock + SWR + TTL jitter | | | |
| Invalidation | Observer-driven, explicit key enumeration | | | |
| Lock isolation | Separate Redis DB (DB 4) | | | |
| TTL strategy | Fixed tiers (60s, 15m, 30m, 60m, 24h) | | | |
| Negative caching | 30s sentinel + 5min stale | | | |
| Multi-level cache | Auth-id → ID → model (two-tier) | | | |
| Serialisation | PHP native (Eloquent models through Redis) | | | |
| Monitoring | Per-op hit/miss + hourly SLO check | | | |
| Eviction | Default (likely noeviction?) | | | |
| Memory management | No explicit maxmemory policy | | | |

For each row, fill in the gold-standard column from what the research agents
found, then assess the gap honestly.

---

## Phase 3 — Synthesis

Write a single report to
`docs/superpowers/research/cache-gold-standard-2026-07-28.md` with this
structure:

```markdown
# Cache Architecture: Gold-Standard Assessment
**Date:** 2026-07-28
**Scope:** Redis application cache only

## 1. What's already gold-standard

(3-5 things this codebase does at or above industry best practice, with
specific citations from the research)

## 2. Findings by dimension

(One subsection per row from Phase 2 — current state, gold standard, gap, and
concrete recommendation)

## 3. Prioritised recommendations

(A table ranked by impact×feasibility. Each row is ONE change, with an
estimated effort in hours. No "consider" or "explore" — either implement,
defer, or reject.)

## 4. What we're NOT changing (and why)

(Research may have surfaced ideas that look good on paper but don't fit this
system — e.g. Redis Cluster, cache tags, external recompute service. Explicitly
reject them with reasoning.)

## 5. Quick wins (< 4 hours total)

(2-4 changes that can be made immediately with high confidence)

## Sources

(Link every article, paper, talk, or documentation page the research agents
found. No link → don't cite it.)
```

---

## Rules for the session

1. **Phase 1 agents MUST run in parallel.** Do not sequence them.
2. **Cite specific sources.** No recommendation without a link or paper title.
3. **Be honest about gaps.** Some things this codebase does will already be
   best-in-class — say so. Some will need work — say so plainly.
4. **No theoretical recommendations.** Every suggestion must reference a
   system that actually runs it in production.
5. **Respect the scope.** Redis app-cache only. Do not audit CDN/edge/KV.
6. **Read before judging.** Phase 2 MUST read the actual source files, not
   just the summaries above.
