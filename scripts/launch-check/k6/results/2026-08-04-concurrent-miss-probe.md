# Concurrent-miss (coalescing) probe — Phase 2c

- **Date:** 2026-08-04
- **Script:** `scripts/launch-check/k6/probe-coalesce.js`
- **Target:** `https://loadtest.partna.au/` — prod Worker → prod `partna-pages` → **dev** Laravel
- **Question:** do N concurrent requests for the same cold key produce N origin fetches, or one?

## Result: origin is shielded. The stampede does NOT reach Laravel.

| Run | Edge path | Concurrent | `/profiles/loadtest` arrivals | `/profiles/loadtest/integrations` arrivals |
|---|---|---|---|---|
| 1 | cold miss (`X-Partna-Cache: origin`) | 50 | **1** | 1 |
| 2 | cold miss (`origin`) | 60 | **1** | 3 |
| 3 | **stale shadow** (`stale`) | 50 | **1** | 2 |
| | | **160 total** | **3** | **6** |

160 concurrent cold-or-stale edge requests produced **3 profile fetches** at the origin — a fan-out of
roughly **1:50**, not the 1:1 that `docs/reviews/2026-08-04-load-testing-options-review.md` §3 modelled.

Both risky paths were exercised and both coalesce:
- **Path 3, cold miss** (`src/index.js:755`) — runs 1 and 2.
- **Path 2, stale shadow** (`src/index.js:746`, one `ctx.waitUntil(fetchAndCache)` scheduled *per request*)
  — run 3. This was the path flagged as most insidious, because it recurs at every 24 h TTL rollover
  rather than only on first-ever view. It coalesces too.

## Method

1. Make the key cold via the app's own purge service (it purges primary **and** `/_swr-shadow/`):
   ```
   cloud tinker development --code='app(\App\Services\Cloudflare\CloudflarePurgeService::class)->purgeHandle("loadtest");'
   ```
   For run 3, `purgeUrls(["https://loadtest.partna.au/","https://loadtest.partna.au"])` instead — purging
   *only* the primary leaves the 7-day shadow intact, which is what forces the stale path.
2. `k6 run probe-coalesce.js` — `per-vu-iterations`, 1 iteration per VU, so the burst is simultaneous and
   no VU loops back to re-warm the key mid-measurement.
3. Count arrivals immediately: `cloud env:logs partna development --minutes 3`, filtered to the loadtest
   paths. The buffer caps at ~100 entries; every run above was confirmed to sit inside its log window
   (checked against first/last `loggedAt`) so none of these counts is a truncation artefact.

`X-Partna-Cache` confirms which path the burst actually hit — run 1/2 were 50/50 and 60/60 `origin`,
run 3 was 50/50 `stale`. A run reporting `hit` was warm and must be discarded, not interpreted.

## What this does NOT establish

**Where the coalescing happens is unknown.** Two mechanisms are consistent with the data:

1. **`partna-pages` absorbs it** — the router Worker fans out 1:1 into 50 service-binding calls, and the
   pages Worker's own caching collapses them into one Laravel call. Cheap fan-out at the worker layer,
   expensive layer protected.
2. **Something collapses earlier**, before `PARTNA_PAGES.fetch()` is reached 50 times.

Cloudflare documents that the Cache API — which the router uses (`caches.default`, `src/index.js:730`) —
does **not** collapse concurrent requests. That makes **(1) the leading hypothesis**: the router probably
does stampede, and `partna-pages` is what saves the database. Distinguishing them needs
`partna-pages`-side request counts, which are not visible from this repo.

This matters for one reason: if the shield is `partna-pages` rather than the router, then the router's
fan-out is real and would show up as worker-invocation cost and service-binding volume at scale, even
though the DB stays flat.

## Other caveats

- **Single PoP.** `caches.default` is per-datacentre; this measured one (Sydney) from one laptop.
- **Small burst.** Coalescing that holds at 50–60 concurrent may not hold at 5,000.
- **Dev backend, unloaded.** Origin served the profile in 131–138 ms throughout. Behaviour could differ
  when the origin is slow and the coalescing window is wider.
- **`integrations` coalesces less reliably than `profiles`** (1 / 3 / 2 vs 1 / 1 / 1). Small numbers, but
  consistent with best-effort coalescing — requests arriving in the gap before the cache is populated each
  do their own fetch — rather than a hard per-key lock.
- Dev carried unrelated live dashboard traffic throughout; it used different paths and does not affect
  these counts.

## Follow-up worth a look

**Serving stale was not meaningfully faster than a cold miss** — run 3 median 682 ms vs run 1 median
729 ms, on a 20 KB body from one laptop. An SWR shadow exists to make the stale path near-instant, so
these being within ~7% of each other is not what the pattern predicts. Could easily be RTT and body
download dominating a small difference; worth splitting `http_req_waiting` from `http_req_receiving`
before drawing any conclusion (see `reference_k6_phase_split_and_log_cap`).

## Bearing on the load-testing plan

The premise behind "we must do distributed testing before launch" was that a herd of cold requests would
fan out to the database. On both cache paths, at this scale, it does not. That materially lowers the
priority of any distributed run — and it was established with three local bursts and a purge, at zero cost,
rather than a cloud service.
