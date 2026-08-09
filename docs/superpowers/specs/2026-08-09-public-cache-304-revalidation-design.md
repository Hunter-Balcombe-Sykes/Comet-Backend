# 304 revalidations lose their public cache headers — design

**Date:** 2026-08-09
**Status:** approved, not yet implemented
**Evidence:** `scripts/launch-check/k6/results/2026-08-09-full-rerun.md`

## Problem

`GET /api/public/profiles/{handle}` is edge-cached by Laravel Cloud's Cloudflare
(`AddPublicCacheHeaders` sets `public, max-age=30, s-maxage=30`; `PARTNA_CACHE_PUBLIC_MAX_AGE = 30`
in **both** dev and production). Measured over two independent 5–10 minute runs, only **8.2–8.4%**
of profile requests reach the origin, against **~100%** for `/api/health` — which also proves the
access log is not sampled.

**The revalidation response is wrong.** `AddETagHeaders` converts a matching conditional request to
304, and its post-`$next` code runs *before* `AddPublicCacheHeaders` (it is appended later to the
`api` group, so it unwinds first). `AddPublicCacheHeaders` then tests
`$response->isSuccessful()` — a 304 is not 2xx — and skips entirely. Observed:

```
HTTP/2 304
cache-control: no-cache, private     <- Symfony's default, never overwritten
etag: "43d36369a151756a77cfc712772432b7"
vary: Origin                          <- Accept-Encoding token missing
```

RFC 9111 §4.3.4 lets a 304 update the stored response's headers. So every 30 seconds the edge
revalidates, the origin answers "no-cache, private", and the stored entry is poisoned into being
non-cacheable — forcing a full re-fetch on the next request.

**Corroborating measurement:** the origin's profile status split is **19 × 200 / 18 × 304**. Under
correct revalidation of unchanged content, 304s should dominate. Near-parity is what
poison-then-refetch looks like: each 304 is followed by a full 200.

`AddPublicCacheHeaders` is not wrong when read in isolation — it is wrong only because of what runs
before it. That is the defect's real character, and it drives the choice of fix.

## Non-goals

- `stale-while-revalidate` — deliberately deferred so the re-measurement changes one variable.
- Any change to `PARTNA_CACHE_PUBLIC_MAX_AGE`.
- The duplicated `Accept-Encoding` in `Vary` on cached responses. `mergeVary()` dedupes
  case-insensitively, so this is added by the edge/proxy, not by our code. Cosmetic, external.
- Root-causing the 4–8.5 s stall. See "Honest limits".

## Design

### Change 1 — accept 304 in the cacheable branch

In `AddPublicCacheHeaders::handle`, the allow-listed public-GET branch admits `304` as well as 2xx:

```php
// A 304 carries the same caching contract as the 200 it replaces. RFC 9111
// lets a 304 update the stored entry's headers, so returning Symfony's default
// `no-cache, private` here tells the edge to stop caching a route we mean to
// cache — and the next request pays a full origin fetch.
$revalidated = $response->getStatusCode() === Response::HTTP_NOT_MODIFIED;

if ($request->isMethod('GET') && ($response->isSuccessful() || $revalidated)) {
```

Ordering safety: the `Authorization` branch and `NO_STORE_PATH_PREFIXES` loop both run earlier and
`return` unconditionally, so no authenticated or no-store response can reach this branch whatever
its status. A 304 on `api/public/unsubscribe/` keeps `private, no-store`.

Side effect, accepted: the same branch sets `X-Cache-Status: MISS` when absent, so 304s will now
carry it too. "MISS" is a poor label for a revalidation, but the header is a debug affordance rather
than a contract and nothing reads it. Special-casing it would add a branch for no behavioural gain;
noted here so the change is not mistaken for an oversight.

### Change 2 — correct the false comment

`VARY_BY_PREFIX` currently asserts:

> *SEC-1: no shared cache in front of this route honors Vary today — the router Worker passes /api
> straight through, and this endpoint has no CDN edge cache in the current topology.*

That is false and load-bearing: it underpins a SEC-1 argument about tenant separation under a future
"Cache Everything" rule. It is replaced with what is measured — there **is** a CDN edge cache, it
serves ~92% of profile requests, and it does key on `Vary` — with a pointer to the evidence.

No exposure follows for profiles (they key on the handle in the URL path), and `site-by-slug`'s
`X-Site-Subdomain` Vary token is unchanged. `config/partna.php` already refers to "Laravel Cloud
edge s-maxage", so this makes two parts of the codebase agree rather than asserting anything new.

### Rejected alternative — swap the middleware order

Registering `AddETagHeaders` before `AddPublicCacheHeaders` would let the 200's headers survive into
the 304, in one line. Rejected: it makes correctness depend on non-obvious pipeline ordering, which
is exactly how this defect arose. A future reorder would silently re-break revalidation with no test
failing. The explicit form states the contract where a reader will look for it.

## Testing

**`tests/Feature/Cache/PublicCacheMiddlewareTest.php`** — the ordering guard. A conditional GET on
the profile route returns **304** still carrying `Cache-Control: public, …, s-maxage=…` and a `Vary`
containing `Accept-Encoding`. This is the test that matters: the defect exists only because of
middleware ordering, so a test that constructs the middleware in isolation cannot catch a regression
of it. It fails if anyone reorders the pipeline back. Same spirit as `PolicyCoverageTest` and
`ArchitectureSystemConstraintsTest`.

**`tests/Unit/AddPublicCacheHeadersTest.php`** — scope guards, asserting that widening the *status*
check did not widen the *path* scope: a 304 on a non-allow-listed path gains nothing, and a 304 on a
`NO_STORE_PATH_PREFIXES` path keeps `no-store`.

These two negative cases live at unit level rather than in the feature file because they are not
constructible end-to-end: `AddETagHeaders` only ETags allow-listed paths, so `api/public/unsubscribe`
never becomes a 304 through the real stack. They pass both before and after the change by design —
they pin scope, they are not red-first tests.

Note the suite runs SQLite while production is Postgres, but nothing here is constraint-bound — this
is pure HTTP header behaviour.

## Verification

Local: `composer test` (targeted files first — note `composer test -- --filter` is broken here).

On dev, after deploy: re-run the 10-minute probe with 15s-cadence origin capture
(`scratchpad/stall-probe.js` + poller). **Falsifiable prediction:**

| Metric | Now | Expected after |
|---|---|---|
| Origin profile split | 19 × 200 / 18 × 304 | **304-dominant** |
| Origin reach (profile) | 8.2–8.4% | **lower** |
| `cache-control` on a 304 | `no-cache, private` | `public, max-age=30, s-maxage=30` |

The third row is checkable with a single `curl -H 'If-None-Match: …'` and does not need a load run.

⚠️ **Probe with the client's encoding.** `curl` sends no `Accept-Encoding`; `Vary` includes it, so a
bare `curl` reads a different cache key and reports `BYPASS`. Always pass
`-H 'Accept-Encoding: gzip'`. This trap hid the entire edge-cache topology for two sessions.

## Honest limits

This fixes a defect with a demonstrated mechanism. It is **not proven** to be the cause of the
intermittent 4–8.5 s profile stall, which never reproduced while under capture (0 occurrences in a
dedicated 10-minute, 451-request probe). The poison-then-refetch cycle is a plausible source of
blocking at the revalidation boundary, and the re-measurement above is what would support or refute
that. If the stall recurs after this ships, the next lever is `stale-while-revalidate`.

## Deployment

Both environments carry `PARTNA_CACHE_PUBLIC_MAX_AGE = 30`, so this changes production behaviour in
the same way it changes dev. Deploying to prod is a push to `production` and is the operator's call,
not part of this change.
