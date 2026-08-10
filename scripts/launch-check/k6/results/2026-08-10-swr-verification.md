# `stale-while-revalidate` verification — Cloudflare does NOT honour it

**Date:** 2026-08-10 · **Env:** development · **Run:** `probe-stall.js`, 20m00.3s, 901 iterations, 4505 requests
**Wire during the run:** `cache-control: max-age=30, public, s-maxage=30, stale-while-revalidate=60`
**Plan:** `docs/superpowers/plans/2026-08-10-public-cache-swr.md` Task 4

## Verdict

**Laravel Cloud's Cloudflare does not act on the `stale-while-revalidate` directive.**
Zero `STALE` responses across 901 profile requests with the directive live on both 200s and 304s.

Per the plan's own gate (Task 4 Step 4 Q1, Task 5 Step 1): **do not enable on prod.** Leave
`PARTNA_CACHE_PUBLIC_SWR` at 0. The code stays — it costs nothing while inert.

## The three questions

### Q1 — Does `cf-cache-status` ever report `STALE`? **NO.**

| `cf-cache-status` | n | share | p50 | p95 |
|---|---:|---:|---:|---:|
| `HIT` | 857 | 95.1% | 48.5 ms | 135.4 ms |
| `REVALIDATED` | 40 | 4.4% | **193.5 ms** | 263.7 ms |
| *(unrecorded)* | 4 | 0.4% | 35.6 ms | 35.6 ms |
| **`STALE`** | **0** | **0%** | — | — |

No `UPDATING` either (Cloudflare's Enterprise serve-stale label).

### Q2 — Did origin reach drop below 4.4%? **No — it is unchanged, as predicted.**

40 origin-reaching requests per 20 minutes = **4.4%**, identical to the 2026-08-10 baseline
(exactly 40 per 20 min, one per 30 s `s-maxage` window). The plan expected this: SWR does not
reduce the *count* of revalidations, only unblocks the clients waiting on them. The count did
not move — and neither did the blocking.

### Q3 — Did the ~200 ms per-32 s blip disappear? **No.**

`REVALIDATED` p50 **193.5 ms** vs `HIT` p50 **48.5 ms** — a ~145 ms penalty still paid, still
once per TTL window, still synchronously. Overall profile p95 171.6 ms against the 2026-08-10
idle-link baseline of 179.2 ms: unchanged within noise.

## Why this is conclusive, not a measurement artefact

The one confound worth ruling out is "those entries were stored before the redeploy, so they
carry the old SWR-free header". Ruled out by the distribution:

```
REVALIDATED per quarter of the run:  [10, 10, 10, 10]
first at iteration 0, last at iteration 897 of 901
```

Every revalidation re-stores the entry with the header the origin just sent, which from the
first revalidation onward included `stale-while-revalidate=60`. By the final quarter every
cached entry had been re-stored with the directive many times over. The behaviour is perfectly
uniform across the run — no drift as the entry population turned over.

The directive was verified on the wire immediately before and immediately after the run, and
confirmed present on a 304 revalidation against the live edge:

```
HTTP/2 304
cache-control: max-age=30, public, s-maxage=30, stale-while-revalidate=60
cf-cache-status: HIT
```

So the origin emitted it correctly on both response types throughout. The edge simply ignores it.

This is consistent with documented Cloudflare behaviour: serve-stale is exposed through
Always Online and Enterprise "serve stale content" settings, not through the origin's
`stale-while-revalidate` directive. And per `reference_cloudflare_free_ratelimit_fields`,
`api.partna.au` is a **dns-only** CNAME — the Cloudflare in front of it is Laravel Cloud's,
not ours, so we have no dashboard access to enable such a setting even if we wanted to.

## Transport noise (unchanged from 2026-08-10)

2 requests ≥ 800 ms, both isolated single requests, neither part of a wave:

| started | ep | client dur | wait | cache | origin log, same window |
|---|---|---:|---:|---|---|
| 23:05:48.194 | social | 1594.1 ms | 1533.0 ms | `HIT age=729` | 23–26 ms |
| 23:13:04.114 | profile | 1482.2 ms | 1481.9 ms | `HIT age=0` | 23–79 ms |

Both were edge `HIT`s — they never touched the origin — and origin duration in the same window
was 23–79 ms. Origin-side for the whole run: p50 34 ms, p95 173 ms, max 541 ms. Same conclusion
as `2026-08-10-stall-probe.md`: the tail is the measurement path, not Partna.

## Endpoint summary (client-observed, 901 each)

| endpoint | p50 | p95 | max |
|---|---:|---:|---:|
| health | 90.4 | 165.0 | 369.5 |
| profile | 50.0 | 171.6 | 1482.2 |
| social | 47.2 | 141.2 | 1594.1 |
| control_cf | 32.1 | 99.0 | 300.3 |
| control_google | 28.5 | 95.7 | 372.0 |

Origin access log: 1257 unique entries (1193 access) over
`2026-08-10T12:48:49Z .. 2026-08-10T13:15:12Z`.

## Corrections to the plan

1. **The env-var command in Task 4 Step 2 does not exist.** The plan prescribes
   `cloud environment:update --variables` and warns it replaces all variables. This CLI version
   has no such flag. The command is `cloud environment:variables`, and it supports a targeted
   single-key write:

   ```bash
   cloud environment:variables development --action=set --key=PARTNA_CACHE_PUBLIC_SWR --value=60 --force
   ```

   Verified non-destructive by diffing all keys and values before/after: 93 → 94 keys,
   **nothing lost, no pre-existing value altered**. The replace-all hazard recorded in the plan
   and in project memory is stale for this CLI version.

2. **Task 1's test passes on first run, not fails.** The middleware does not read the new key
   until Task 2, so nothing can make it red at that point — it is a characterisation test whose
   guard value begins in Task 2. Confirmed non-vacuous by mutation (`if ($swr >= 0)` turns it red).

3. **An env-var change does not auto-redeploy.** `config:cache` runs at build (`php artisan
   optimize`), so the new value needs `cloud deploy partna development` to take effect.

## What to do with this

- **Do not enable on prod.** The value proposition is edge serve-stale; the edge ignores it.
- **Keep the code.** It is inert at 0 and costs nothing. If Partna ever fronts the API with its
  own Cloudflare zone (rather than the current dns-only CNAME to Laravel Cloud), this becomes
  testable again with one env-var flip.
- **`stale-if-error` is not answered by this run** and should not be assumed dead. It is a
  different directive with different platform support, and it was deliberately out of scope.
  Any future attempt should measure it the same way rather than infer from this result.
- **The per-TTL blip is real and unmitigated:** ~145 ms extra on 4.4% of profile requests. If it
  ever matters, the remaining levers are raising `s-maxage` (leaning harder on purge-on-write)
  or origin capacity — not this directive.
