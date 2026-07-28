# Cache Architecture: Gold-Standard Assessment

**Date:** 2026-07-28
**Scope:** Redis (Valkey) application cache only. Cloudflare CDN, edge cache, and KV are explicitly out of scope.
**Method:** six parallel external-research agents (Laravel-at-scale, non-Laravel architectures, invalidation SOTA, Redis topology/memory, TTL science, observability), then a direct read of every cache implementation file in `app/Services/Cache/`, `app/Jobs/Cache/`, `app/Listeners/RecordCacheMetrics.php`, `app/Observers/Core/`, `config/cache.php`, `config/database.php`, `config/partna.php`.
**Framework version at audit time:** Laravel 12.62.0.

---

## How to read this document

Every finding, recommendation, and rejection below carries a **> Plain English** block underneath it. The paragraph above the block is the technical statement with citations; the block explains the same thing assuming no prior cache knowledge — what the problem actually is, why it matters, and what breaks if it's ignored. If you're new to the codebase, read the Plain English blocks first and treat the technical paragraphs as the supporting evidence.

**Vocabulary used throughout:**

| Term | What it means here |
|---|---|
| **Cache** | A copy of an answer kept somewhere fast (Redis, in memory) so we don't have to recompute it from the database every time. |
| **Key** | The name a cached answer is filed under, e.g. `site:payload:jane`. Everything in Redis is looked up by key. |
| **TTL** (time-to-live) | How long a cached answer is allowed to live before it's considered out of date. Also the maximum time a user can see stale data. |
| **Hit / miss** | A "hit" is when the answer was already cached. A "miss" is when it wasn't and we had to rebuild it from the database. |
| **Invalidation** | Deliberately getting rid of a cached answer because the underlying data changed. |
| **Stampede** (thundering herd) | Many requests all miss the same key at the same instant and all hammer the database to rebuild the same thing. |
| **Single-flight / lock** | Letting exactly one request rebuild a value while the others wait or get served something else. |
| **SWR** (stale-while-revalidate) | Keeping an older copy around and serving it instantly while one worker rebuilds the fresh one in the background. |
| **Jitter** | Randomising expiry times slightly so a batch of entries created together doesn't all expire together. |
| **Eviction** | What Redis does when memory runs out — deciding which entries to throw away to make room. Distinct from expiry. |
| **Negative caching** | Caching the fact that something *doesn't exist*, so repeated lookups for a missing thing don't hit the database. |
| **Sentinel** | A special placeholder value meaning "we checked, and the answer really is nothing" — as opposed to "we haven't checked". |
| **Post-commit** | Doing something only after the database has confirmed a write is permanently saved, rather than during the save. |

---

## 0. Live-environment facts established during the audit

These were verified against the running dev environment, not inferred. They constrain several recommendations below.

| Probe | Result |
|---|---|
| `Redis::connection('cache')->info()` | `NOPERM User application has no permissions to run the 'info' command` |
| `Redis::connection('cache')->config('GET', 'maxmemory-policy')` | returns `false` (denied/disabled) |
| `Redis::connection('cache')->dbsize()` | `163` keys on dev cache DB |

**Consequence:** `maxmemory`, `maxmemory-policy`, `evicted_keys`, `keyspace_hits/misses`, and `mem_fragmentation_ratio` are all unreadable from the application on Laravel Cloud's managed Valkey. Every Redis-side monitoring recommendation the research surfaced (§2.9) is currently unimplementable in code and must go through the Laravel Cloud console or support. This is not a code defect — it is a platform constraint that changes what "observability" can mean here.

> **Plain English:** Redis normally lets you ask it "how are you doing?" — how much memory am I using, how many keys did you throw away, what's your hit rate. Laravel Cloud runs Redis (well, Valkey, a fork of it) as a managed service, and the login our app uses has been stripped of permission to ask those questions. So from inside our code, the cache is a black box: we can put things in and take things out, but we cannot see how full it is or what it's doing under pressure. Anything in this report about watching Redis itself has to be done through the Laravel Cloud web console instead of code.

---

## 1. What's already gold-standard

**1.1 Three-layer stampede prevention, exceeding the framework's own primitive.**
`CacheLockService::rememberLocked()` combines single-flight locking, stale-while-revalidate via a `:stale` twin key, and ±20% TTL jitter with *independent draws* for primary and stale. The research surveyed four canonical stampede strategies — mutex/lock, probabilistic early recompute (XFetch, [VLDB 2015](http://www.vldb.org/pvldb/vol8/p886-vattani.pdf)), memcached leases ([Scaling Memcache at Facebook, NSDI 2013](https://www.usenix.org/conference/nsdi13/technical-sessions/presentation/nishtala)), and request coalescing ([Go singleflight](https://rednafi.com/go/request-coalescing/)). This codebase implements the first and third-in-effect, plus the avalanche fix (jitter) that most implementations omit. Laravel's own `Cache::flexible()` ([12.x docs](https://laravel.com/docs/12.x/cache)) provides SWR + lock but **no jitter at all** — this implementation is ahead of the framework on that axis.

> **Plain English:** A cache entry has an expiry time. The classic disaster is that when it expires, 500 visitors arrive at once, all find nothing in the cache, and all 500 hammer the database to rebuild the same thing. That's a "stampede." We defend against it three ways at once. **(1) Lock:** only one request is allowed to rebuild; the rest wait. **(2) Stale-while-revalidate:** we keep a second, older copy of the answer around for 10× longer, so while one request rebuilds, everyone else instantly gets the slightly-old copy instead of waiting. **(3) Jitter:** we randomise each expiry time by ±20%, so a thousand cache entries created in the same second don't all expire in the same later second and cause a thousand simultaneous rebuilds. Laravel added its own version of this in v11, but it skips step 3.

**1.2 The resolve-floor is an independently-invented stale-set rejection.**
`SiteCacheService::raiseResolveFloor()` maintains a monotonic timestamp floor that a reader must `max()` against, so a reader who queried the DB pre-commit cannot re-publish a stale `updated_at_ts` after invalidation deleted the resolve key. This is functionally the *second* half of the Facebook memcache lease design — the part that rejects a late `set` carrying a token issued before the invalidation ([NSDI 2013](https://www.usenix.org/conference/nsdi13/technical-sessions/presentation/nishtala)). Most application caches never solve this; they accept the stale-set race and rely on TTL. Finding it hand-built here, with the post-commit-only invariant documented in the docblock, is genuinely above industry norm.

> **Plain English:** Imagine two things happening at the same moment: someone edits their site, and someone else loads that site's page. The reader started reading the database *before* the edit was saved, so it's holding old data. The writer saves, then clears the cache. Then the reader — still holding its stale copy, unaware anything changed — finishes up and writes that old data *into* the freshly-cleared cache. The cache is now stale again, and the write that was supposed to fix it already happened. Nothing will correct it until the entry expires. The fix here is a "floor": a number that only ever goes up. The writer bumps it to the new timestamp. The reader is forced to compare its own timestamp against the floor and take whichever is higher — so a reader carrying old data physically cannot publish it. Facebook solved this same race in their memcached layer with a token system; this is the same idea, built independently.

**1.3 Timestamp-keyed rotation is the DHH key-based-expiration pattern.**
`public.profile:{handle}:{updated_at_ts}` never needs `Cache::forget` — mutations roll the key forward. This is exactly [key-based cache expiration](https://signalvnoise.com/posts/3113-how-key-based-cache-expiration-works), the pattern that survived 15 years in the Rails ecosystem and became Rails cache digests. Caveat in §2.4 — it has a memory-reclamation dependency this deployment cannot currently verify.

> **Plain English:** There are two ways to handle "this cached thing is now out of date." You can go and delete it (fiddly — you have to remember every place it might be stored), or you can bake the version into the name so the old one simply stops being asked for. We do the second. The cache key includes the moment the site was last edited: `public.profile:jane:1753660800`. Edit the site and the timestamp changes, so the next request asks for `public.profile:jane:1753664400` — a name that doesn't exist yet, so it gets built fresh. Nobody ever asks for the old name again. No deletion needed. The catch: the old entry is still sitting in memory taking up space, and we're trusting Redis to clean it up on its own — see §2.1, where it turns out we can't confirm Redis is configured to do that.

**1.4 Invalidation is post-commit, which is the correct answer to the dual-write problem.**
`SiteObserver::$afterCommit = true`, `CloudflareCachePurgeJob::dispatch(...)->afterCommit()`, and `ClaimSiteService` invalidating outside its transaction closure all implement the standard advice: *delete the cache key after the DB transaction commits*, never inside it ([Confluent on the dual-write problem](https://www.confluent.io/blog/dual-write-problem/), [AWS transactional outbox](https://docs.aws.amazon.com/prescriptive-guidance/latest/cloud-design-patterns/transactional-outbox.html)). Invalidation is also always DELETE, never SET — the idempotent choice the research names as best practice.

> **Plain English:** When you save something, two separate systems need updating: the database and the cache. Get the order wrong and you create bugs. Clear the cache *before* the database write commits, and a reader can slip in during the gap, find the cache empty, read the old database value, and cache that — you've just re-cached the stale data you were trying to remove. Clear it inside the transaction, and if the transaction rolls back you've thrown away a perfectly good cache entry for a change that never happened. The right answer is to clear the cache strictly *after* the database confirms the save. That's what `$afterCommit = true` on our observers does. Second rule: when invalidating, **delete** the cache entry rather than overwriting it with the new value. Deleting is safe to do twice by accident; writing the wrong value spreads bad data to everyone until it expires.

**1.5 Serve-stale-on-recompute-failure (CCH-3) is `stale-if-error` at the application layer.**
Both `CacheLockService::rememberLocked()` and `SiteCacheService::getPublicSitePayload()` catch a throwing recompute, `report()` it, and serve the known-good stale copy rather than failing the unlucky lock-winner's request. That is [RFC 5861](https://datatracker.ietf.org/doc/html/rfc5861)'s `stale-if-error` semantics, which Varnish and nginx provide at the edge and which `Cache::flexible()` does **not** provide at all.

> **Plain English:** One request gets picked to rebuild the cache entry. What if that rebuild crashes — the database hiccups, a query times out? The naive behaviour is that this one unlucky visitor gets a 500 error page, even though we still have a perfectly usable older copy sitting right there. Instead we log the error for the team and hand that visitor the older copy. Everyone else was already getting the older copy anyway; this just extends the same courtesy to the one who volunteered to do the work. Web caches like Varnish have had this for years under the name `stale-if-error`. Laravel's built-in version doesn't do it.

**1.6 The metrics fold in `RecordCacheMetrics` is more careful than most production instrumentation.**
One logical read fires up to three Redis probes (primary → `:stale` → post-lock re-check). The listener tracks one logical read at a time and folds all three into a single hit/miss, so the metric measures "we served without recomputing" rather than "this exact key was warm." Hit-rate instrumentation that naively counts every probe — which is the common case — would report a 2-3× inflated miss rate on exactly this architecture.

> **Plain English:** "Cache hit rate" means: out of all the times we needed something, how often was it already in the cache? Sounds simple to count. But because of the three-layer design in §1.1, one single logical lookup actually asks Redis up to three separate questions — "is the main copy there?", "is the backup copy there?", "did another request fill it while I waited?". If you count each question separately, one lookup that ends perfectly happily can be recorded as two or three misses, and your dashboard shows a disaster that isn't happening. Our listener is smart enough to recognise those three questions as one event and score it once. Most instrumentation isn't, which is why most teams with this architecture have a hit-rate number that's quietly wrong.

**1.7 Isolated lock DB (DB 4).**
Locks live on a separate Redis DB from cache data specifically so `Cache::flush()`'s raw `FLUSHDB` cannot release locks held by in-flight workers. The research confirms `FLUSHDB` blast radius is a real production hazard and that DB-index separation is the standard single-node containment. Caveat in §2.10.

> **Plain English:** One Redis server can hold several numbered compartments, and we use them to keep unrelated things apart: compartment 0 is the job queue, 1 is the cache, 2 is user sessions, 4 is locks. Why does this matter? Because "clear the cache" in Laravel doesn't politely delete cache entries — it issues a command that wipes the *entire current compartment*. If locks lived in the same compartment as cached data, clearing the cache would rip the locks out from under jobs that were mid-way through using them, and two workers could then do the same job at once. Putting locks in their own compartment means a cache clear can't touch them. (§2.10 covers the trade-off this creates later on.)

---

## 2. Findings by dimension

| Dimension | Current approach | Gold standard (research) | Gap? | Severity |
|---|---|---|---|---|
| Key design | Central generator, ~60 methods, namespaced, multi-site annotations | Central naming + versioned prefixes | **No** | — |
| Stampede prevention | Single-flight lock + SWR + ±20% jitter | Lock / XFetch / leases / coalescing | **No** (ahead of `Cache::flexible()`) | — |
| Invalidation | Observer-driven, post-commit, explicit key enumeration | Post-commit delete; CDC at multi-writer scale | **Minor** | P3 |
| Lock isolation | Separate Redis DB (DB 4) | Separate instance | **Minor / by design** | P3 |
| TTL strategy | Fixed tiers 60s / 15m / 30m / 60m / 24h + jitter | Tiered + jitter, TTL as freshness contract | **No** | — |
| Negative caching | 30s primary + 5m stale sentinel | DNS RFC 2308; CDN ~2m for 404s | **No** | — |
| Multi-level cache | auth-id → id → model, two-tier | Standard | **No** | — |
| SWR refresh cost | Lock-winner recomputes **synchronously in-request** | `Cache::flexible()` defers refresh post-response | **Yes** | **P2** |
| Serialisation | PHP native `serialize()` of Eloquent models, uncompressed | Compression on the wire (LZ4) — a serializer swap is unreachable, see §2.5 correction 1 | **Yes** | **P2** |
| Monitoring | Per-op hit/miss + hourly SLO, `min_sample` gate | Golden signals + burn-rate + Redis-side | **Partial** | **P2** |
| SLO threshold | Single 90% target across `site` and `pro` prefixes | Per-workload targets | **Yes** | **P1** |
| Eviction | **Unknown and unreadable** | `allkeys-lru` / `allkeys-lfu` for a cache | **Yes** | **P1** |
| Memory management | No `maxmemory` policy visibility; one no-TTL key | All-TTL keyspace or `allkeys-*` policy | **Yes** | **P1** |

### 2.1 Eviction policy is unknown, and the architecture depends on it — **P1**

**Current:** Not set by this codebase and not readable from it (§0). Laravel Cloud provisions managed ElastiCache Valkey ([Laravel Cloud docs](https://cloud.laravel.com/docs/private-cloud/elasticache)).

**Gold standard:** Redis's default is `noeviction`, under which writes fail with `OOM command not allowed` once `maxmemory` is reached while reads keep succeeding ([Netdata](https://www.netdata.cloud/guides/redis/redis-oom-command-not-allowed/)). For a pure cache this is the wrong policy — it converts memory pressure into application-visible write failures. AWS ElastiCache defaults to **`volatile-lru`**, which evicts only keys carrying a TTL; a keyspace containing any non-expiring key can still OOM because nothing else is eligible ([AWS re:Post](https://repost.aws/knowledge-center/oom-command-not-allowed-redis)). For a cache mixing 60s and 24h TTLs, `allkeys-lru` is the safe default and `allkeys-lfu` is better under skewed access.

**Why it bites here specifically:** the `public.profile:{handle}:{ts}` rotation design (§1.3) *never deletes old keys* — every site edit orphans one, and reclamation is entirely the eviction policy's job. The research is explicit that key-based expiration "implicitly relies on the cache backend having a sane eviction policy and enough headroom." So the codebase's most elegant invalidation pattern rests on a setting nobody on the team can currently read.

**Correction (2026-07-28, design phase).** Three premises in the paragraphs above are wrong, established by reading the code:

1. `cache:lock_release_failures` is **not on the cache DB**. The bare `Redis` facade resolves the connection named `default` (`config/database.php:157`) = `REDIS_DB=0`, the queue and Horizon database. It cannot be evidence about the cache keyspace.
2. There *was* a genuine TTL-less key on the cache DB, and this research missed it: `scheduler:last_run:*`, written by `RecordScheduledTaskHeartbeat` via `Cache::forever()`. Every other raw-Redis writer audited pairs its write with an explicit expiry.
3. **`maxmemory-policy` is per-instance, not per-database.** All five connections in `config/database.php` share one `REDIS_HOST`/`REDIS_PORT`. Requesting `allkeys-lru` would authorise Valkey to evict queued Horizon job payloads and held locks — silent job loss, materially worse than a failed cache write.

Both keys now carry TTLs, and `tests/Feature/Cache/CacheKeyspaceConstraintsTest.php` fails CI on any new `forever(` in `app/`. See `docs/superpowers/specs/2026-07-28-cache-eviction-policy-hardening-design.md`.

**Recommendation (as corrected):** Read `maxmemory-policy` and `maxmemory` from the Laravel Cloud console (or ask Cloud support) for both dev and prod. If it is anything other than **`volatile-lru`**, request `volatile-lru` — **not** `allkeys-lru`, per correction 3 above. The keyspace-side precondition is done: both TTL-less keys now expire. Effort: 1h investigation, code complete.

> **Plain English:** Redis holds everything in memory, and memory runs out. What happens when it's full is a setting called the eviction policy, and there are three flavours that matter here. **`noeviction`** (Redis's own default) means: refuse all new writes and return errors, but keep serving reads. For a cache that's a bad deal — you'd rather lose old cached data than start erroring. **`volatile-lru`** (Amazon's default) means: throw away the least-recently-used entry, *but only consider entries that have an expiry time set*. **`allkeys-lru`** means: throw away the least-recently-used entry, full stop. For a pure cache, `allkeys-lru` is the one you want.
>
> Two things make this urgent rather than academic. First, we can't read which one we're on (§0), so this is an unknown, not a choice we made. Second, the clever key-rotation trick from §1.3 leaves old entries lying around on purpose and trusts eviction to sweep them up — so if the policy is `noeviction`, that trick is quietly filling memory with garbage until writes start failing. There's also one key we create, `cache:lock_release_failures`, that deliberately has no expiry time. On its own it's a single harmless counter. What it proves is that our cache is *not* an all-expiry keyspace, which is exactly the assumption `volatile-lru` needs in order to have anything to evict.
>
> **Plain English, corrected:** two of the sentences above are wrong. `allkeys-lru` is **not** the one we want, because the cache doesn't get its own Redis — it shares one machine with the job queue, and the eviction setting applies to the whole machine. Under `allkeys-lru`, Redis would be free to throw away a queued job when memory got tight, and the job would simply never run. `volatile-lru` is the right answer here: it only ever discards things that have an expiry time, and queued jobs don't have one. That is why every cache key now has to have an expiry — that expiry is the label saying "this one is safe to lose". The `cache:lock_release_failures` counter also turned out not to be on the cache at all; it lives on the queue's database. The key we'd actually missed was the scheduler's heartbeat. Both now expire.

### 2.2 The cache-hit SLO is structurally unmeetable on the `pro` prefix — **P1**

**Current:** one `min_hit_rate` (default 0.9) applied to both `site` and `pro`, gated by `min_sample` (default 10). Both are env-tunable and are already set in the dev Cloud environment.

**Gold standard:** the TTL-cache hit-rate ceiling is `h = 1 − e^(−λT)` for Poisson arrivals ([Jung/Berger/Balakrishnan, INFOCOM 2003](https://infocom2003.ieee-infocom.org/papers/11_01.PDF)), approximated as `1 − 1/(λT)` when `λT ≫ 1`. `config/partna.php` already documents this and records the observed dev ceiling of ~87% on `pro`.

**The gap:** `min_sample` fixes the *low-volume noise* problem — the Grafana/SRE "minimum-volume gating" pattern ([Grafana Cloud SLO best practices](https://grafana.com/docs/grafana-cloud/alerting-and-irm/slo/best-practices/)) — and that is the right fix, already implemented. It does **not** fix the *ceiling* problem. `pro` is dominated by `professional_model` at a 60s TTL; `site` is dominated by `public_payload` at 900s. A 60s-TTL prefix and a 900s-TTL prefix cannot share one hit-rate threshold, because their ceilings differ by an order of magnitude in required traffic. A 90% target against an ~87% ceiling fires forever regardless of cache health — the exact "alert measures traffic, not health" anti-pattern the SRE Workbook warns about ([Alerting on SLOs](https://sre.google/workbook/alerting-on-slos/)).

**Recommendation:** make `min_hit_rate` a per-prefix map rather than a scalar — `['site' => 0.90, 'pro' => 0.80]` — derived from each prefix's dominant TTL. This is a small change to `config/partna.php` and the loop in `AggregateCacheMetricsJob::handle()`. Do **not** raise `professional_model`'s 60s TTL to chase the number: that TTL is a freshness contract on the authenticated user's own profile, and trading correctness for a green dashboard is backwards. Effort: 2h including a test.

> **Plain English:** We have an alert that fires when the cache hit rate drops below 90%. The problem is that 90% is mathematically impossible for half of what it's measuring, so the alert is going off forever and nobody can learn anything from it.
>
> Here's why. A cache entry that expires every 60 seconds gets rebuilt at least once a minute — that rebuild is a guaranteed miss. If only 5 people ask for that entry in a minute, you get 1 miss and 4 hits: 80%, and no amount of good engineering changes that. To hit 90% on a 60-second entry you'd need roughly 10 requests per minute *for that specific entry*. Formally the ceiling is `1 − 1/(rate × lifetime)`, and it's a fact about your traffic, not your code.
>
> Now the mismatch: our alert lumps two very different things under one threshold. The `site` group holds page payloads that live 15 minutes — easily clears 90%. The `pro` group holds user profiles that live 60 seconds — measured at about an 87% ceiling on dev. Same alert, same 90% bar, one of them can never pass. The fix is one threshold per group instead of one for both. The fix is *not* to make profiles live longer — that 60 seconds is a promise that when you edit your own profile you see the change within a minute, and we're not weakening a real promise to make a graph go green.

### 2.3 SWR makes the lock-winner pay the recompute in-request — **P2**

**Current:** in the SWR fast path, the caller that wins the non-blocking lock runs `$callback()` synchronously and only then returns. Every *other* concurrent caller gets the stale value immediately. So one unlucky request per refresh window absorbs full recompute latency.

**Gold standard:** `Cache::flexible()` registers the refresh as a **deferred function that runs after the response has been sent**, so no user ever pays for the refresh ([Laravel 12.x docs](https://laravel.com/docs/12.x/cache), [Laravel News](https://laravel-news.com/boosting-app-speed-with-flexible-caching-laravel-in-practice-ep8)). Varnish grace mode and nginx `proxy_cache_background_update` do the same thing at the edge ([Varnish grace](https://varnish-cache.org/docs/trunk/users-guide/vcl-grace.html), [nginx proxy module](https://nginx.org/en/docs/http/ngx_http_proxy_module.html)).

**Recommendation:** keep the hand-rolled `CacheLockService` — see §4 for why a wholesale migration to `flexible()` would be a regression — but adopt its one genuinely better idea. In the SWR branch of `rememberLocked()`, wrap the recompute in `defer()` and return `$stale` immediately. The lock is still held across the deferred closure, so single-flight is preserved. Note that `defer()` only fires in an HTTP request context; the queue/console path must keep the synchronous behaviour, and `WarmPublicSiteCacheJob` depends on it. Effort: 4h including tests for both contexts.

**Shipped 2026-07-28.** Implemented in both SWR sites, not just `rememberLocked`:
`SiteCacheService::getPublicSitePayload` hand-rolls its own SWR against the
`site:fill:{subdomain}` lock and never routes through `CacheLockService`, so a
`rememberLocked`-only fix would have missed the hottest and most expensive path
in the product. Two premises in the original finding were wrong and are corrected
here: `defer()` is **not** HTTP-only — `FoundationServiceProvider` also flushes it
after queued jobs (`JobAttempted`) and artisan commands (`CommandFinished`), so
the console gate is mandatory rather than incidental; and the lock is only "still
held across the deferred closure" if the release moves *inside* that closure, which
the original one-line `defer($callback)` sketch would not have done. Ops kill-switch:
`PARTNA_CACHE_SWR_DEFER_RECOMPUTE=false`.

> **Plain English:** When a cache entry goes stale, one request gets nominated to rebuild it. Right now that request has to sit and wait for the rebuild to finish before it gets a reply — so one visitor per refresh cycle pays the full cost (maybe several hundred milliseconds of database work) while everyone else is served instantly from the older copy. It's not broken, it's just unfair: one person in the queue does the washing up for everybody.
>
> Laravel has a neat trick for this. It sends the response to the visitor *first*, then does the rebuild afterwards, in the same PHP process, once nobody's waiting. The visitor gets the fast old copy like everyone else and never knows they were volunteered. We should copy that behaviour into our own helper. One wrinkle: that "after the response" hook only exists during a web request — there's no response to send in a queued job or a console command — so the current wait-for-it behaviour has to stay for those, and `WarmPublicSiteCacheJob` in particular relies on it.

### 2.4 Orphaned rotation keys are unbounded in count, bounded in lifetime — **P3**

**Current:** each site mutation mints a new `public.profile:{handle}:{ts}` and abandons the old one, which lives out `public_profile.cache_ttl` (60s default). At 163 keys on dev this is a non-issue.

**Gold standard:** Redis carries roughly 70–90+ bytes of fixed overhead per key independent of value size ([OneUptime](https://oneuptime.com/blog/post/2026-03-31-redis-memory-overhead-per-key/view)), and active expiration costs CPU proportional to the volume of TTL'd keys sampled per `hz` cycle.

**Assessment:** with a 60s TTL the orphan population is bounded by *edit rate × 60s*, not by total sites, so this scales fine. **No behaviour change.** It is listed only because it is the mechanism by which §2.1 becomes dangerous if the TTL is ever lengthened or the policy is `noeviction`.

**Guarded (2026-07-28).** The conclusion above depends entirely on the TTL staying short, and `SIDEST_PUBLIC_PROFILE_CACHE_TTL` is tunable in the Laravel Cloud console with no deploy — so nothing in code or CI would have noticed it move. `config('partna.public_profile.cache_ttl_ceiling_seconds')` now pins a 300s ceiling **without** an `env()` fallback, and `AggregateCacheMetricsJob` reports a breach to Nightwatch hourly, above its empty-bucket early return. Raising the TTL stays a console edit; raising the ceiling is a reviewed commit. Note this closes only the half that lives in code — the `noeviction` half still depends on reading the live `maxmemory-policy`, which remains open (§2.1 recommendation).

> **Plain English:** Every time someone edits their site, the key-rotation trick from §1.3 mints a brand-new cache entry and abandons the old one. Those abandoned entries are litter — nobody will ever read them again, but they sit in memory until their expiry runs out. The question is whether the litter piles up faster than it decays. It doesn't: these entries only live 60 seconds, so the amount of litter at any moment depends on how many edits happen *in the last minute*, not on how many sites exist in total. A thousand sites doesn't mean a thousand orphans. So this is fine as-is and needs no work. It's written down because it's the exact mechanism that turns §2.1 from a theoretical concern into a real one — if that 60 seconds ever gets lengthened, or if eviction turns out to be switched off, this is the tap that fills the bucket.

### 2.5 Serialisation is PHP-native `serialize()` — **P2**

> **Correction, 2026-07-28.** Three of this section's claims were wrong and are corrected below by direct measurement on `development` (phpredis 6.3.0). The recommendation stands in outcome — enable it on dev first — but for a different mechanism, with a different flag, and with the safety note pointing the other way. Design: `docs/superpowers/specs/2026-07-28-redis-cache-compression-design.md`.

**Current:** `config/database.php` had igbinary + LZ4 wired behind a single `REDIS_IGBINARY` flag, defaulting `false`, set in neither Cloud environment. Cached values are stored as raw PHP `serialize()` output.

**Gold standard:** Shopify wrote a three-part engineering series specifically about the cost of Ruby's `Marshal` in their Memcached layer ([Caching Without Marshal](https://shopify.engineering/caching-without-marshal-part-one)) — the analogous problem. The analogy does not carry all the way, for the reason in correction 1.

**Correction 1 — igbinary is a no-op here; the entire win is LZ4.** `Illuminate\Cache\RedisStore::serialize()` already returns `serialize($value)`, so phpredis is handed a **string** and has no object graph left to encode. Measured on a real `pro:model:{id}` payload: no encoding 8,256 B; `SERIALIZER_IGBINARY` alone 8,248 B (0.1%); `COMPRESSION_LZ4` alone 2,616 B (**3.16×**); both together 2,624 B — 8 B *worse* than LZ4 alone. The `~3×` figure was real but belonged entirely to the compression half. Unlike Shopify, we cannot reach the serialisation format without replacing the cache store.

**Correction 2 — the flush prerequisite guards the wrong direction.** A legacy uncompressed value read *with* compression on returns intact and unserialises (phpredis passes through payloads that don't look compressed), so **rolling forward needs no flush**. A compressed value read *with* compression off returns raw bytes that `unserialize()` to `false` — a cached falsy hit. **Rolling back** is the unsafe direction, and it wants a `CACHE_PREFIX` bump rather than a flush, because a flush races the draining old instances.

**Correction 3 — `pro:model:{id}` is not the largest payload in the keyspace.** A scan of all 151 string keys on the dev cache DB puts it third, behind `platforms:itunes:*` (6 keys, 81,792 B, one 42,158 B value) and `pro:{uuid}:*` (3 keys, 41,344 B). It *is* the most write-heavy large payload — the 60 s TTL claim is correct — but sizing the change on it alone understates the benefit. Keyspace-wide: 157,968 B → 45,560 B under LZ4 (**3.47×**), → 35,752 B under ZSTD (4.42×). Nothing grew, down to 1-byte values.

**Recommendation:** enable `REDIS_CACHE_COMPRESSION=true` (LZ4, compression only, no serializer) on **development first**, verify every consumer round-trips, then promote. The promote gate is correctness, not a memory measurement — the sizing evidence is above, and prod holds 0 users so a prod control carries no signal. Effort: 1h.

> **Plain English:** Redis stores text and bytes, not PHP objects. So before we can cache a User object we have to flatten it into a string, and PHP's built-in way of doing that (`serialize()`) is verbose — it spells out every property name in full. There are two separate ideas for improving that, and the config had both wired to one switch: a more compact flattening format (igbinary), and squashing the result with a compression algorithm (LZ4).
>
> When measured, only one of them does anything. Laravel flattens the object into a string *before* handing it to Redis, so by the time igbinary gets a look there's no object left — just a string it can't improve on. It saved 8 bytes out of 8,256. The compression, on the other hand, took the same entry from 8,256 bytes down to 2,616, and took the entire cache from about 158 KB to about 46 KB. So the "roughly 3×" the old comment promised is real, but it comes from the half nobody expected.
>
> The other thing that turned out backwards: the old note said switching this on would make existing cache entries unreadable, so it had to ship with a cache wipe. It doesn't — Redis's client notices an entry isn't compressed and hands it back unchanged. It's switching it back *off* that's dangerous, because a compressed entry read by code that isn't expecting compression comes back as garbage that quietly looks like a real (empty) answer. So the care belongs on the undo, not the do.
>
> Finally, this isn't really about one entry. The old note called the cached User object the biggest thing we store; it's actually third, behind some cached iTunes data. Compression applies to everything in the cache at once, which is the better way to think about the win.

### 2.6 `Cache::memo()` is unused — **P3**

**Current:** zero occurrences of `Cache::memo()` or `Cache::flexible()` in `app/`.

**Gold standard:** `Cache::memo()` (Laravel 12) serves repeat reads of the same key from process memory for the remainder of the request, auto-invalidating on any mutating call routed through the same memo instance ([12.x docs](https://laravel.com/docs/12.x/cache)).

**Assessment:** the auth path already avoids most of this — `getByAuthId()` reads two distinct keys once each. The redundant-read pressure that `memo()` targets is largely absent here. **Defer**, and revisit if a request path is ever found reading the same key more than twice. Listing it so the option is on record rather than rediscovered later.

> **Plain English:** Even a cache hit isn't free — it's still a network round-trip to Redis. If one web request asks for the *same* cache key five times (easy to do accidentally when several bits of code each independently ask "who's the current user?"), that's five round-trips for one answer. Laravel 12 added `Cache::memo()`, which remembers the answer in local memory for the rest of that request so calls 2-5 cost nothing. We don't use it. But when I traced our authentication path, it turns out we don't need it — the code already fetches each key exactly once. So there's nothing to win right now. Noted here so that if someone later spots the same key being fetched repeatedly, the tool is already known rather than rediscovered from scratch.

### 2.7 Observer-driven invalidation misses non-ORM writes — **P3**

**Current:** invalidation is driven by Eloquent observers (`SiteObserver`, `BlockObserver`, `UserObserver`, `CustomerObserver`, `ServiceObserver`, `ServiceCategoryObserver`) plus `SiteCacheInvalidator::touchSite()` for models that feed the payload indirectly.

**Gold standard:** at multi-writer scale, change-data-capture off the Postgres WAL (Debezium) invalidates from the source of truth and catches every writer regardless of path ([Debezium](https://debezium.io/blog/2018/12/05/automating-cache-invalidation-with-change-data-capture/), [Percona on Valkey + Debezium](https://www.percona.com/blog/tackling-the-cache-invalidation-and-cache-stampede-problem-in-valkey-with-debezium-platform/)). Shopify's IdentityCache uses the same ORM-`after_commit` approach this codebase does ([Shopify Engineering](https://shopify.engineering/identitycache-improving-performance-one-cached-model-at-a-time)).

**Assessment:** CDC wins once multiple services write the same tables. This is one monolith with one ORM. **Reject CDC** (§4). The residual risk — a raw `DB::table()->update()` or a manual SQL fix bypassing observers — is real but is correctly backstopped by TTL, which the research names as the standard safety net. **No change**, but any future raw-SQL write path must invalidate explicitly.

> **Plain English:** How does the cache find out that something changed? We use "observers" — small classes that Laravel automatically runs whenever a model is saved or deleted, which then go and clear the relevant cache keys. It works well and it's the same approach Shopify uses.
>
> Its one blind spot: observers only fire for writes that go through Laravel's model layer. If someone writes to the database with raw SQL — a hand-run `UPDATE` to fix a support issue, say — no observer fires and the cache never hears about it. The heavyweight industry answer is Change Data Capture: a separate system that tails the database's own write-ahead log and invalidates from there, so it catches *every* write no matter who made it. That's genuinely bulletproof and genuinely expensive (Kafka, Debezium, per-table plumbing). It earns its keep when several different services write the same tables. We have one application, one ORM, one write path — so it's not worth it, and expiry times already act as the safety net that limits how long any missed invalidation can hurt. The rule to carry forward: if we ever add a raw-SQL write path, it has to clear the cache by hand.

### 2.8 Multi-level auth cache — **no gap**

`pro:map:auth:{uid}` (30m, immutable mapping) → `pro:model:{id}` (60s, SWR + jitter), with a defensive `auth_user_id` mismatch guard that forgets both keys and re-resolves through the same locked-nullable helper rather than a bare `Cache::put`. Correctly tiered: the immutable mapping outlives the volatile model. No industry pattern found that improves on this for the shape of data involved.

> **Plain English:** Every logged-in request arrives with an ID from Supabase (the auth system), and we need to turn that into our own user record. That's two lookups, and the clever bit is that they're cached for very different lengths of time. The first — "which of our users is this Supabase ID?" — is an answer that can *never* change, because that link is set once at signup and never edited. So it's cached for 30 minutes. The second — "give me that user's full record" — changes every time they edit their profile, so it's only cached for 60 seconds. Splitting them means a profile edit expires the cheap 60-second half without throwing away the permanent mapping, so we're not re-asking the database a question we already know the answer to. There's also a safety check: if the cached record somehow comes back belonging to a *different* Supabase ID, we throw both entries away and start over rather than risk showing one person another person's profile.

### 2.9 Monitoring covers hit rate only — **P2**

**Current:** per-prefix hits/misses/writes into an hourly Redis hash; hourly SLO check that `report()`s to Nightwatch on breach; a lock-release-failure counter.

**Gold standard:** the four golden signals — latency, traffic, errors, saturation ([SRE Book](https://sre.google/sre-book/monitoring-distributed-systems/)) — plus Redis-side `evicted_keys`, `used_memory` vs `maxmemory` (alert ~80% for a cache), and `mem_fragmentation_ratio` (concerning above ~1.5) ([ScaleGrid](https://scalegrid.io/blog/redis-monitoring-metrics/), [SigNoz](https://signoz.io/blog/redis-monitoring/)).

**Gap and its hard limit:** traffic and errors are covered; **latency and saturation are not**. Saturation is *unimplementable in code* (§0 — `INFO` is `NOPERM`). Latency is implementable: Nightwatch already tracks slow routes/jobs, but there is no cache-operation latency percentile anywhere.

Note also that OpenTelemetry has **no finalised cache-span semantic conventions** — the proposal ([semantic-conventions#1747](https://github.com/open-telemetry/semantic-conventions/issues/1747)) is still open — so a "trace L1→L2→DB" build here would be inventing attribute names, not adopting a standard. Not worth doing yet.

**Recommendation:** two parts. (a) Escalate the `INFO`/`CONFIG` permission gap to Laravel Cloud — without it, memory saturation on the cache is invisible until it causes an incident. (b) Defer latency percentiles until there is production traffic to measure; with zero customers the numbers would be meaningless. Effort: (a) 1h, (b) deferred.

> **Plain English:** Google's SRE book says to watch four things on any system: how fast it is (latency), how busy it is (traffic), how often it breaks (errors), and how full it is (saturation). We measure traffic and errors well. We measure **speed** not at all — we know how *often* the cache has the answer, but not how *long* it takes to hand it over. And we can't measure **fullness** at all, because Redis won't tell us (§0) — so we'd find out the cache is out of memory when something breaks, not before.
>
> Two conclusions. The fullness gap is worth chasing with Laravel Cloud, because it's the one that can bite without warning. The speed gap is real but not worth building yet: we have no customers, so any latency numbers we collected right now would describe an empty system. There's also a tempting third idea — tracing a request as it passes through cache layer 1, misses layer 2, and lands on the database — which I'd skip, because the industry standard for how to label those traces (OpenTelemetry) hasn't been finalised for caches yet. Building it now means inventing our own names and rewriting them later.

### 2.10 DB-index isolation blocks a future Cluster migration — **P3, documentation only**

**Current:** DB 0 queue+Horizon, 1 cache, 2 sessions, 3 dormant queue-override, 4 cache locks.

**The constraint:** Redis Cluster supports **only DB 0** — `SELECT` with any other index errors ([Redis cluster spec](https://redis.io/docs/latest/operate/oss_and_stack/reference/cluster-spec/), [SELECT docs](https://redis.io/docs/latest/commands/select/)). The entire DB-index isolation scheme, including the `Cache::flush()` protection that makes DB 4 valuable, is single-node-only. Migrating to Cluster would require separate *instances* per concern, not separate indices.

Separately, `Cache::lock()` cluster-safety could not be verified. Laravel 13.5.0 hash-tagged queue and `ConcurrencyLimiter` keys to fix `CROSSSLOT` errors ([Laravel News](https://laravel-news.com/laravel-13-5-0)) but the release notes do not mention `RedisLock`. Treat `Cache::lock()` as unverified on Cluster.

**Recommendation:** no change — single-node is correct at this scale and Cluster is firmly rejected (§4). Add one paragraph to `CLAUDE.md` recording that DB-index isolation is a deliberate single-node choice with a known Cluster incompatibility, so a future scaling decision starts from fact instead of rediscovering it. Effort: 0.5h.

> **Plain English:** The numbered-compartment trick from §1.7 — queue in 0, cache in 1, locks in 4 — is a good design with one catch worth writing down before someone trips over it. When a Redis deployment eventually gets too big for one machine, you split it across several, which Redis calls Cluster mode. Cluster mode **only has compartment 0**. Ask for any other number and it errors. So the whole separation scheme we rely on today would have to be rebuilt as separate Redis *servers* rather than separate compartments on one server.
>
> Nothing needs doing now — we're nowhere near needing Cluster (dev holds 163 cache keys) and §4 rejects it outright. The action is purely to write this down in `CLAUDE.md`, so that whoever eventually evaluates Cluster starts from "we know this breaks, here's the migration shape" instead of discovering it halfway through. Related unknown: I could not confirm whether Laravel's cache locks even work correctly in Cluster mode — Laravel fixed this class of bug for queues in v13.5.0 but the release notes never mention locks.

---

## 3. Prioritised recommendations

Ranked by impact × feasibility. Every row is one change with a decision, not a "consider."

| # | Change | Decision | Impact | Effort |
|---|---|---|---|---|
| 1 | Read `maxmemory-policy` + `maxmemory` from the Laravel Cloud console for dev and prod; request **`volatile-lru`** if it is anything else (NOT `allkeys-lru` — the instance is shared with the queue, see §2.1 correction) | **Implement** | Prevents OOM write-failures and guarantees rotation-key reclamation | 1h |
| 2 | Per-prefix `min_hit_rate` map in `config/partna.php` + `AggregateCacheMetricsJob` | **Implement** | Stops a permanently-firing Nightwatch alert; restores signal | 2h |
| 3 | Give `cache:lock_release_failures` and `scheduler:last_run:*` TTLs — **done**, see §2.1 correction | **Implement** | Makes the keyspace all-TTL, which `volatile-*` policies assume | 0.5h |
| 4 | Enable `REDIS_CACHE_COMPRESSION=true` (LZ4, no serializer) on **dev only**, verify consumers, then promote | **Implement** | 3.47× measured across the whole cache keyspace — see §2.5 corrections | 1h |
| 5 | Escalate the `INFO`/`CONFIG` `NOPERM` gap to Laravel Cloud | **Implement** | Cache saturation is currently invisible until it causes an incident | 1h |
| 6 | ~~Defer the SWR recompute via `defer()` in the HTTP path, keeping sync behaviour for queue/console~~ — **done 2026-07-28** | **Implement** | Removes full recompute latency from one request per refresh window | 4h |
| 7 | Document the DB-index / Cluster incompatibility in `CLAUDE.md` | **Implement** | Prevents a future scaling decision being made on a wrong premise | 0.5h |
| 8 | Cache-operation latency percentiles | **Defer** | Meaningless at zero production traffic; revisit post-pilot | — |
| 9 | `Cache::memo()` for intra-request memoisation | **Defer** | The redundant-read pressure it targets is largely absent | — |
| 10 | OpenTelemetry cache-span tracing | **Defer** | No finalised semantic convention exists yet to adopt | — |

**Total for items 1–7: ~10 hours.**

> **Plain English — what each item actually means:**
>
> 1. **Find out what happens when the cache fills up.** Log into the Laravel Cloud console and read one setting. If it's the wrong one, the cache either starts erroring on writes or stops cleaning up after itself. We currently don't know which we're on. Nothing to code — just go and look.
> 2. **Stop the alert that cries wolf.** Give page-payloads and user-profiles their own hit-rate targets instead of one shared 90%, because 90% is arithmetically out of reach for profiles. Best value for the hours on this list.
> 3. **Put an expiry on one counter that doesn't have one.** A single line. It matters because "every key expires eventually" is an assumption the cleanup policy in item 1 quietly depends on.
> 4. **Turn on the compression that's already built.** The switch exists in config and is set to off. Flip it on dev, wipe the dev cache, see how much memory we save, then decide about prod.
> 5. **Ask Laravel Cloud to let us see Redis's own stats.** Right now we're flying blind on memory. Support ticket, not code.
> 6. **Stop making one unlucky visitor do the rebuild while they wait.** Send them their page first, rebuild afterwards. Everyone gets the fast path.
> 7. **Write down the Redis-Cluster gotcha in `CLAUDE.md`.** One paragraph, so a future scaling decision doesn't start by rediscovering it the hard way.
> 8-10. **Deliberately not doing these yet**, and each for a specific reason rather than lack of time: no traffic to measure, no problem to solve, no finished standard to adopt.

---

## 4. What we're NOT changing (and why)

**Migrating `CacheLockService` to `Cache::flexible()` — rejected.**
It looks like the obvious modernisation and it is a net regression. `flexible()` has **no TTL jitter** (avalanche exposure across the fleet), **no negative-cache sentinel** (`rememberLockedNullable`'s `NULL_SENTINEL` and the 30s null-TTL have no equivalent), and **no serve-stale-on-error** (§1.5). It also stores a companion `illuminate:cache:flexible:created:{key}` key per entry, which would double the key count *and* silently break every `bustWithStale()` invalidation site, since those enumerate `key` and `key:stale` — not a third companion. Take the one good idea (deferred refresh, item 6) and leave the rest.

> **Plain English:** Laravel now ships a built-in feature that does roughly what our hand-written `CacheLockService` does, so the obvious instinct is "delete our code, use theirs." That would make things worse. Ours does four things theirs doesn't: randomised expiry times, remembering that an answer was "not found" (so bot scans don't hammer the database), serving the old copy when a rebuild crashes, and — critically — theirs secretly writes a *second* bookkeeping key alongside every entry. All our invalidation code deletes entries in pairs (`key` and `key:stale`); it knows nothing about a third key, so every cache-clear would silently leave that bookkeeping behind and things would go stale in ways that are very hard to debug. We take their one genuinely better idea (item 6) and keep our own for everything else.

**Redis Cluster — rejected.**
Cluster solves dataset size beyond one node and write throughput beyond one primary. Dev holds 163 cache keys. It would also break the DB-index isolation this architecture depends on (§2.10), break multi-key operations, and put `Cache::lock()` into unverified territory. Revisit only if the cache working set approaches the instance memory ceiling.

> **Plain English:** Cluster mode spreads Redis across several machines. You need it when one machine can't hold your data or can't keep up with your writes. We have 163 keys on dev. It would also break the compartment design (§2.10), break commands that touch several keys at once, and put our locking into territory nobody could confirm works. This is the textbook case of adopting a scaling solution years before having the problem it solves.

**Change-data-capture invalidation (Debezium) — rejected.**
CDC earns its operational cost — Kafka, WAL retention, topic-per-table plumbing — when multiple services write the same tables through different paths. This is one Laravel monolith where every write goes through Eloquent, and TTL already backstops the residual raw-SQL risk. Revisit if a second service ever writes `site.*` or `core.*` directly.

> **Plain English:** CDC means running a separate system that watches the database's own internal change log and clears cache entries from there — so it catches every change no matter which bit of code made it. It's the gold-plated answer, and it costs you a Kafka cluster and a pile of ongoing plumbing. It pays off when several different applications write to the same tables and you can't trust them all to remember to clear the cache. We have one application. Not worth it until that changes.

**`Cache::tags()` — remains rejected.**
Laravel 12 docs no longer single out Redis as unsupported ([12.x](https://laravel.com/docs/12.x/cache)), but the historical objection stands on its own merits: Redis has no native tag primitive, Laravel emulates it with per-tag key sets, and tag-flush rotates a reference rather than deleting the underlying keys, which then decay only via eviction ([Laracasts](https://laracasts.com/discuss/channels/laravel/redis-cache-tags)). Explicit key enumeration is more code but is verifiable and has no memory-leak mode. Keep it.

> **Plain English:** Tags would let you label cache entries ("this belongs to site X") and then wipe everything with that label in one call — much less code than our approach, which is to write out by hand every single key that needs deleting. The catch is that Redis has no real concept of tags; Laravel fakes them by keeping side-lists, and "wipe the tag" doesn't actually delete anything — it just abandons the list and lets the real entries rot in memory until they expire on their own. Our way is more typing, but you can read the code and see exactly what gets deleted, and nothing leaks.

**XFetch / probabilistic early recompute — rejected.**
XFetch is provably optimal ([VLDB 2015](http://www.vldb.org/pvldb/vol8/p886-vattani.pdf)) for systems that must avoid coordination. This system already coordinates — it holds a distributed lock and it already serves stale during refresh, so the herd never forms. XFetch would add a per-key recompute-delta measurement and a random-draw branch to buy an optimisation that SWR has already captured. Reject.

> **Plain English:** XFetch is a genuinely elegant published algorithm: instead of waiting for an entry to expire and then having a scramble, each reader rolls a dice as expiry approaches, with the odds rising the closer it gets — so *someone* rebuilds it slightly early and the crowd never forms. It's mathematically proven optimal. It's proven optimal *for systems that can't coordinate*. We can coordinate — we take a lock, and we hand out the old copy while rebuilding, so the crowd already never forms. Adding XFetch would mean tracking how long every rebuild takes and adding a dice roll to every read, to solve a problem we've already solved another way.

**Adaptive / frequency-extended TTL — rejected.**
The research explicitly flagged that no rigorously documented large-scale system dynamically *lengthens* a freshness TTL because a key got hot; the credible frequency-aware work (TinyLFU/W-TinyLFU, [arXiv](https://arxiv.org/abs/1512.00727)) is an *admission and eviction* policy, not a TTL policy, and lives inside the cache engine rather than the application. Sliding-expiration-on-access is real but belongs to session-style keys, not payload freshness. Extending a TTL because a key is popular would silently degrade the freshness contract on exactly the sites that matter most.

> **Plain English:** The idea sounds clever: popular pages get cached for longer, since they're being asked for constantly anyway. Two problems. First, when I went looking for anyone actually running this in production, I couldn't find them — the serious academic work in this area (TinyLFU) is about deciding *what to keep when memory runs out*, not about stretching expiry times, and it lives inside the cache engine rather than in application code. Second and worse: "cached for longer" means "shows stale data for longer." So this would make our most popular sites — the ones where being out of date is most visible — the slowest to reflect their owner's edits. Exactly backwards.

**Raising `professional_model`'s 60s TTL to hit the SLO — rejected.**
It would work, and it is the wrong trade. That TTL bounds how long an authenticated user sees their own stale profile after an edit. Fix the threshold (item 2), not the contract.

> **Plain English:** There's a shortcut available for the failing alert in §2.2: just cache user profiles for longer, and the hit rate goes up on its own. It genuinely works. It's also the wrong instinct, and worth naming so nobody reaches for it later. That 60 seconds isn't an arbitrary number — it's the promise that when you edit your own profile, you see the change within a minute. Stretching it to 10 minutes to make a graph turn green means real users staring at their old details wondering if the save worked. Change the measurement, not the product behaviour.

**Separate Redis instances for cache vs queue — rejected for now.**
Genuinely stronger isolation than DB indices, and the only form that survives a Cluster migration. But the specific hazard it defends against — `Cache::flush()` wiping Horizon state — is *already* contained by the DB split, and no code path calls `Cache::flush()` (verified: zero occurrences in `app/`, `routes/`, `config/`; the only mentions are explanatory comments). Cost without a live threat. Revisit alongside any Cluster decision.

> **Plain English:** Rather than keeping cache and job-queue data in separate compartments of one Redis server, you could run two entirely separate Redis servers. That's stronger — a compartment is a convention, a separate server is a wall — and it's the only version that survives the Cluster migration in §2.10. But the specific accident it prevents is "someone clears the cache and destroys the job queue," and two things already stop that: the compartments, and the fact that nothing in our codebase calls the cache-clearing command at all (I checked — zero occurrences outside explanatory comments). So it's real money and complexity for a threat that isn't currently live. Bundle it with the Cluster decision if that ever comes up.

**Changing the negative-cache TTLs — rejected.**
30s primary + 5m stale sits comfortably inside the range the prior art supports: DNS negative caching typically runs 5 minutes or less ([RFC 2308](https://www.rfc-editor.org/rfc/rfc2308.html)), CDNs default to roughly 2 minutes for cached 404s ([Google Cloud CDN](https://docs.cloud.google.com/cdn/docs/using-negative-caching)). The asymmetric risk the research names — a long-lived cached 404 masking a resource that now exists — is well handled at 30s. No change.

> **Plain English:** When someone requests a site that doesn't exist, we cache the *absence* — we write down "nope, nothing here" for 30 seconds. That sounds odd but it's important: bots scan for random subdomains constantly, and without it every one of those scans becomes a database query. Caching a "no" is riskier than caching a "yes", though, because if the thing later starts existing, you're now actively hiding it. So the duration wants to be short. DNS has done this since the 1990s (usually 5 minutes or less) and CDNs typically cache a 404 for about 2 minutes. Our 30 seconds is comfortably on the safe side of both. Nothing to change.

---

## 5. Quick wins (< 4 hours total)

1. ~~**TTL on `cache:lock_release_failures`**~~ (0.5h) — **done 2026-07-28**, together with the key this research missed (`scheduler:last_run:*`, the genuine cache-DB offender) and a CI guard. Makes the whole shared instance all-TTL apart from the queue, which is the precondition `volatile-lru` assumes. See the §2.1 correction.
2. **Per-prefix `min_hit_rate`** (2h) — `config/partna.php` gains a map, `AggregateCacheMetricsJob::handle()` looks up per prefix with the scalar as fallback. Turns a permanently-firing alert back into a signal. Highest value-per-hour item on the list.
3. **`REDIS_CACHE_COMPRESSION=true` on dev** (1h) — flip the env var and verify each consumer round-trips. No flush (see §2.5 correction 2), and no measurement window: the sizing is already established at 3.47× keyspace-wide. Needs the small config change in the §2.5 design doc first — the old `REDIS_IGBINARY` wiring paired the working half with a measured no-op.
4. **`CLAUDE.md` note on DB-index vs Cluster** (0.5h) — one paragraph recording that DB-index isolation is deliberate, single-node-only, and incompatible with Redis Cluster.

Item 1 in §3 (read the eviction policy from the Cloud console) is higher priority than any of these but is not a code change and cannot be done from this repo.

> **Plain English:** These four are the "could be done this afternoon" set — small, low-risk, and each one closes a real gap rather than being busywork. If you only do one, do **#2**: right now there's an alert firing constantly for a reason that has nothing to do with anything being broken, and a permanently-red alert is worse than no alert, because the team learns to ignore it. #1 and #3 are both one-liners. #4 is a config flag with the code already written behind it. The single most valuable action overall isn't on this list because it isn't code at all — it's logging into the Laravel Cloud console and reading what happens when the cache runs out of memory.

---

## Sources

Every link below was returned by a research agent with a live URL. Claims that could not be sourced are marked as unverified in-line above rather than cited here.

**Laravel**
- [Cache — Laravel 12.x docs](https://laravel.com/docs/12.x/cache) — `flexible()`, `memo()`, `lock()`, `withoutOverlapping()`, `funnel()`, tag driver support, `failover` driver
- [Laravel Octane — 12.x docs](https://laravel.com/docs/12.x/octane) — Octane cache driver, `Octane::table()`, singleton/static-state pitfalls
- [Boosting App Speed with Flexible Caching — Laravel News](https://laravel-news.com/boosting-app-speed-with-flexible-caching-laravel-in-practice-ep8) — production framing of `Cache::flexible()`
- [Laravel Response Cache v8 — Freek Van der Herten](https://freek.dev/3012-laravel-response-cache-v8-is-here-now-offers-flexible-caching)
- [Laravel 13.5.0 — Redis Cluster queue hash-tagging](https://laravel-news.com/laravel-13-5-0)
- [laravel/framework discussion #43242 — CROSSSLOT with AWS Redis](https://github.com/laravel/framework/discussions/43242)
- [laravel/horizon#913 — redis cluster queue error](https://github.com/laravel/horizon/issues/913)
- [Redis cache tags — Laracasts](https://laracasts.com/discuss/channels/laravel/redis-cache-tags)
- [spatie/laravel-multitenancy — prefixing cache](https://spatie.be/docs/laravel-multitenancy/v4/using-tasks-to-prepare-the-environment/prefixing-cache) — versioned-prefix namespace pattern
- [alternative-laravel-cache](https://github.com/swayok/alternative-laravel-cache) — third-party Redis tag support
- [lada-cache](https://github.com/spiritix/lada-cache) — Redis-backed normalised Eloquent cache

**Laravel Cloud infrastructure**
- [How We Built Laravel Cloud's Architecture](https://laravel.com/blog/one-minute-or-less-how-we-built-laravel-clouds-architecture) — Kubernetes/EKS not Lambda; ElastiCache; Cloudflare Tunnels
- [ElastiCache — Laravel Cloud docs](https://cloud.laravel.com/docs/private-cloud/elasticache) — managed Valkey/Redis OSS
- [Laravel Cloud Network](https://laravel.com/cloud/network)
- [Laravel June Product Updates](https://laravel.com/blog/laravel-june-product-updates) — Valkey in Forge

**Industry architectures**
- [Scaling Memcache at Facebook — USENIX NSDI 2013](https://www.usenix.org/conference/nsdi13/technical-sessions/presentation/nishtala) — leases: stampede prevention *and* stale-set rejection
- [Shopify — IdentityCache](https://shopify.engineering/identitycache-improving-performance-one-cached-model-at-a-time) — `after_commit` explicit invalidation
- [Shopify/identity_cache](https://github.com/Shopify/identity_cache)
- [Shopify — Caching Without Marshal](https://shopify.engineering/caching-without-marshal-part-one) — serialisation cost
- [Shopify — Shop app custom caching](https://shopify.engineering/shop-app-custom-caching-solution)
- [Shopify multi-tenant architecture (pods)](https://www.shopify.com/blog/multi-tenant-architecture)
- [How key-based cache expiration works — Signal v. Noise (DHH)](https://signalvnoise.com/posts/3113-how-key-based-cache-expiration-works)
- [rails/cache_digests](https://github.com/rails/cache_digests)
- [Discord — How Discord Stores Trillions of Messages](https://discord.com/blog/how-discord-stores-trillions-of-messages) — request coalescing in the Data Services layer
- [Slack's Incident on 2-22-22](https://slack.engineering/slacks-incident-on-2-22-22/) — Mcrouter/Mcrib cache-ring churn
- [Meta — Introducing mcrouter](https://engineering.fb.com/2014/09/15/web/introducing-mcrouter-a-memcached-protocol-router-for-scaling-memcached-deployments/)
- [GitHub — sharded Redis rate limiter](https://github.blog/engineering/infrastructure/how-we-scaled-github-api-sharded-replicated-rate-limiter-redis/)

**Invalidation and consistency**
- [Optimal Probabilistic Cache Stampede Prevention — VLDB 2015 (PDF)](http://www.vldb.org/pvldb/vol8/p886-vattani.pdf) / [ACM DL](https://dl.acm.org/doi/10.14778/2757807.2757813)
- [Automating Cache Invalidation With Change Data Capture — Debezium](https://debezium.io/blog/2018/12/05/automating-cache-invalidation-with-change-data-capture/)
- [Cache invalidation and stampede in Valkey with Debezium — Percona](https://www.percona.com/blog/tackling-the-cache-invalidation-and-cache-stampede-problem-in-valkey-with-debezium-platform/)
- [The Dual-Write Problem — Confluent](https://www.confluent.io/blog/dual-write-problem/)
- [Transactional outbox pattern — AWS Prescriptive Guidance](https://docs.aws.amazon.com/prescriptive-guidance/latest/cloud-design-patterns/transactional-outbox.html)
- [Request coalescing with Go singleflight](https://rednafi.com/go/request-coalescing/)
- [Cache stampede prevention — antirez](https://redis.antirez.com/fundamental/cache-stampede-prevention.html)
- [Two Hard Things — Martin Fowler](https://martinfowler.com/bliki/TwoHardThings.html)

**TTL science**
- [Modeling TTL-based Internet Caches — INFOCOM 2003 (PDF)](https://infocom2003.ieee-infocom.org/papers/11_01.PDF) — `h = 1 − e^(−λT)`
- [Adaptive TTL-based Caching for Content Delivery — Basu et al. (PDF)](https://groups.cs.umass.edu/wp-content/uploads/sites/3/2019/12/Adaptive-TTL-based-caching-for-content-delivery.pdf)
- [TinyLFU: A Highly Efficient Cache Admission Policy — arXiv](https://arxiv.org/abs/1512.00727) / [ACM ToS](https://dl.acm.org/doi/10.1145/3149371)
- [RFC 5861 — stale-while-revalidate / stale-if-error](https://datatracker.ietf.org/doc/html/rfc5861)
- [Varnish grace mode](https://varnish-cache.org/docs/trunk/users-guide/vcl-grace.html) / [Varnish 4 SWR semantics](https://www.varnish-software.com/blog/grace-varnish-4-stale-while-revalidate-semantics-varnish)
- [nginx ngx_http_proxy_module](https://nginx.org/en/docs/http/ngx_http_proxy_module.html)
- [RFC 2308 — Negative Caching of DNS Queries](https://www.rfc-editor.org/rfc/rfc2308.html)
- [Google Cloud CDN — negative caching](https://docs.cloud.google.com/cdn/docs/using-negative-caching)

**Redis / Valkey topology and memory**
- [Redis cluster specification](https://redis.io/docs/latest/operate/oss_and_stack/reference/cluster-spec/) / [SELECT](https://redis.io/docs/latest/commands/select/) — Cluster has DB 0 only
- [Redis persistence](https://redis.io/docs/latest/operate/oss_and_stack/management/persistence/)
- [Redis — faster KEYS and SCAN](https://redis.io/blog/faster-keys-and-scan-optimized/)
- [AWS re:Post — OOM error for ElastiCache Redis](https://repost.aws/knowledge-center/oom-command-not-allowed-redis) — `volatile-lru` default
- [Netdata — Redis OOM command not allowed](https://www.netdata.cloud/guides/redis/redis-oom-command-not-allowed/)
- [OneUptime — Redis memory overhead per key](https://oneuptime.com/blog/post/2026-03-31-redis-memory-overhead-per-key/view)
- [OneUptime — lazy vs active expiration](https://oneuptime.com/blog/post/2026-03-31-redis-how-redis-key-expiration-algorithm-works-lazy-active/view)
- [Valkey 8.0 release blog](https://valkey.io/blog/valkey-8-0-0-rc1/) / [release notes](https://github.com/valkey-io/valkey/blob/8.0.0/00-RELEASENOTES) / [Linux Foundation](https://www.linuxfoundation.org/press/valkey-8-0)

**Observability**
- [SRE Book — Monitoring Distributed Systems](https://sre.google/sre-book/monitoring-distributed-systems/) — four golden signals
- [SRE Workbook — Alerting on SLOs](https://sre.google/workbook/alerting-on-slos/) — multiwindow multi-burn-rate
- [Grafana Cloud — SLO best practices](https://grafana.com/docs/grafana-cloud/alerting-and-irm/slo/best-practices/) — minimum-volume gating
- [ScaleGrid — Redis monitoring metrics](https://scalegrid.io/blog/redis-monitoring-metrics/)
- [SigNoz — Redis Monitoring 101](https://signoz.io/blog/redis-monitoring/)
- [Redis — Observability at scale](https://redis.io/tutorials/operate/redis-at-scale/observability/)
- [OpenTelemetry semantic-conventions #1747 — cache operations (open, unmerged)](https://github.com/open-telemetry/semantic-conventions/issues/1747)
- [OpenTelemetry — database client spans](https://opentelemetry.io/docs/specs/semconv/db/database-spans/)
- [Last9 — high-cardinality metrics in Prometheus](https://last9.io/blog/how-to-manage-high-cardinality-metrics-in-prometheus/)
