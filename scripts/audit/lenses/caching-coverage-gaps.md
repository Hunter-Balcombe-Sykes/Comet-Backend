# Caching coverage gaps: hot, expensive reads with no cache at all

Find reads that **should** be cached and currently are not. This is the inverse
of `caching-gold-standard.md`: that lens grades caches that exist; this lens
hunts the *absence* of a cache on a read path hot and expensive enough to
warrant one.

A correct, fast, uncached query is fine. The target here is the read that runs
on every request to a hot endpoint, costs real database (or vendor) work each
time, and has many concurrent callers — the canonical `CacheLockService::rememberLocked`
candidate that nobody wrapped.

## The bar — a finding requires ALL THREE

A read is a coverage gap **only if every one of these holds**. Two out of three
is not a finding — drop it.

1. **Hot path** — the read sits on a named hot path: the public sitepage payload
   resolution path (`SiteCacheService` / `PublicSiteResolver` / `SitepageDataResolverService`),
   handle/profile resolution, `AccountCapabilities` lookups, analytics summary
   endpoints, notification unread-count, streaming live-status (`LiveStatusPoller` /
   `LiveStatusInjector`), dashboard controllers under
   `app/Http/Controllers/Api/{User,Staff,Internal,PublicSite}`, or middleware that
   runs on most requests. A read on an admin-only, rarely-hit, or one-shot path
   is **not** hot.
2. **Expensive** — the read does real work each call: a multi-table join, an
   aggregate (`SUM`/`COUNT`/`GROUP BY`), a JSONB scan, an unindexed `WHERE`, a
   fan-out of N queries, or a synchronous vendor API call (Twitch/Kick live-status
   API, Cloudflare, URL metadata fetch via `app/Services/Http`, platform connector
   outbound read).
   A single indexed primary-key lookup is **not** expensive — do not flag it.
3. **Multi-caller / repeated** — the same logical value is recomputed across many
   requests or many callers within a TTL window, with no per-request reason for
   it to differ. A value that is genuinely unique per request (and so could never
   hit a cache) is **not** a finding.

If you cannot quote evidence for all three, it is not a `CCG` finding.

## Use the lens prefix `CCG` for findings

Number them `CCG-1`, `CCG-2`, … sequentially across the whole audit.
(`CCH` = gold-standard adherence, `CACHE` = scaling-antipatterns, `SCALE` =
database-and-queue-scaling — all reserved. This lens uses `CCG`.)

## Findings categories

### (1) Uncached hot dashboard read

A controller action or service method on a hot path that issues an expensive
query (or query fan-out) directly, with no `Cache::`/`rememberLocked` anywhere
in the path. Quote the query/builder chain and the route or method that reaches it.
Canonical fix: wrap in `CacheLockService::rememberLocked($key, $ttl, fn() => ...)`
with a key from `CacheKeyGenerator`.

### (2) Uncached resolution / capability path

Site, handle, profile, or account-capability resolution that hits the database
on every request because it is not memoised. These run on nearly every request —
the highest-leverage gaps. The public sitepage payload path (`PublicSiteResolver` →
`SiteCacheService::getPublicSitePayload`) and the auth path (handle→user lookup via
`LoadCurrentUser` middleware) are the canonical reference implementations — confirm
a cache layer genuinely exists (in the resolver, in middleware, or in a service it
delegates to) before flagging any adjacent resolution step.

### (3) Uncached synchronous vendor read

A read path that calls a vendor API (Twitch/Kick live-status, Cloudflare KV/DNS
state, URL metadata fetch, platform connector scrape) synchronously on a hot
request, with no cache. Vendor calls are both slow and rate-limited — uncached
vendor reads on a hot path are the most expensive gap class. Canonical fix: cache
the vendor response with a bounded TTL + push-invalidate on the relevant webhook
or job completion event.

### (4) Repeated identical read within one request

The same expensive query issued more than once in a single request lifecycle
(e.g. a controller and a Resource class both resolving the same site payload, or
a loop re-fetching an invariant user/site row). Canonical fix: request-scoped
memoisation (a `once()` / static memo / passed-in value), not necessarily a
Redis cache.

### (5) Config / reference data read per request

Slow-changing reference data (feature-flag matrix, capability map, design kit
column list, the architecture-id value, enum-like lookup tables) re-read from the
database on every request instead of cached behind a version token. Canonical fix:
`rememberLocked` with a long TTL + version-token bump on the (rare) write.

## Per-finding requirements

For every finding:
- Cite the category number (1–5).
- Prove the bar: state explicitly which hot path, why the read is expensive
  (quote the join/aggregate/vendor call), and why it repeats across callers.
- Quote verbatim evidence — the query builder chain or vendor call, and the
  enclosing method/route.
- Name the canonical fix: `CacheLockService::rememberLocked` + `CacheKeyGenerator`
  key, request-scoped memo, or version-token-keyed reference cache.
- Default tier: **P2** for an uncached hot dashboard/resolution read (1, 2) and
  uncached synchronous vendor reads (3). **P3** for repeated-within-request (4)
  and reference-data gaps (5) — bounded impact. Escalate to P2 only with
  evidence the path is genuinely hot at the current scale target (thousands of
  users; a single user's page going viral creates a fan-out of concurrent cache
  misses against one Supabase Postgres primary).

## Out of scope — do NOT flag, defer to the sibling lens

- A cache that **exists but is weak** (no single-flight, no jitter, no SWR, no
  push-invalidate) → `caching-gold-standard.md` (`CCH`) owns it. This lens is
  *absence only*.
- A read whose correct fix is `with()` eager loading or a composite index — the
  query is badly *shaped*, not missing a *cache* → `database-and-queue-scaling.md`
  (`SCALE`) owns N+1 and index hygiene.
- Rebuild-on-write / write amplification / aggregate tables that should be live
  queries → `scaling-antipatterns.md` (`CACHE`).
- Cheap reads: single indexed PK/FK lookups, `find($id)`, already-eager-loaded
  relations. Caching these adds invalidation surface for no gain — explicitly
  NOT findings.
- One-shot paths: signup, bootstrap, migrations, artisan commands, admin-only
  staff tooling that runs rarely. Not hot — not in scope.
- Test code.

## Suggested per-domain scope groups

### Group A — Resolution & capability paths (highest leverage)
```
--scope app/Services/PublicSite
--scope app/Services/Accounts
--scope app/Services/Site
--scope app/Services/Cache
--scope app/Http/Middleware
```

### Group B — Dashboard read paths
```
--scope app/Http/Controllers/Api/User
--scope app/Http/Controllers/Api/Staff
--scope app/Http/Controllers/Api/Internal
--scope app/Http/Controllers/Api/PublicSite
--scope app/Http/Resources
```

### Group C — Synchronous vendor reads
```
--scope app/Services/Streaming
--scope app/Services/Http
--scope app/Services/Platforms
--scope app/Services/Cloudflare
```

## Exhaustiveness directive

Walk every file in scope. For each expensive read, apply the three-part bar
explicitly — do not flag on hotness alone or expense alone. Emit a separate
finding for every distinct read that clears the bar; if one controller has two
uncached hot reads, that is two findings. But hold the line on precision: this
lens's failure mode is the opposite of the others — **over-reporting** every
uncached query drowns the real gaps. A finding you cannot defend on all three
criteria is noise. The adjudicator will re-tier and dedupe; it should not have
to delete half your findings for failing the bar.
