# Instrumented stall probe — the 4–8.5 s profile stall

- **Date:** 2026-08-10
- **Scripts:** `probe-stall.js`, `poll-origin-logs.sh`, `analyse-stall.py` (all new)
- **Target:** dev (`dev-api.partna.au`), `development` @ `59aec7497`+
- **Question:** what is the intermittent multi-second stall carried forward from
  `2026-08-09-full-rerun.md`, and is it Partna at all?

## Headline

Two 20-minute runs. Idle link: **zero stalls** in 4,505 requests. Tester's WAN link
saturated: **reproduced immediately**, worst request **9,475 ms** — and the origin's own
access log shows it answered that same request in **129 ms**.

The recorded fingerprint ("profile-route only, neighbours unaffected") was also **falsified**
by re-analysing five earlier runs, and the origin capture **verified the 304 fix by
behaviour** rather than by a single header check.

## The fingerprint was wrong, and one subtraction is why

k6 timestamps a request at **completion**. Every previous analysis read those timestamps as
if they were arrivals. Plotting each request at `completion − duration` instead changes the
diagnosis:

| Claim in `2026-08-09-full-rerun.md` | What the raw JSON shows |
|---|---|
| "Profile route only. `/api/health` stayed normal." | **`/api/health` stalls in both captured events**, and in the 08-09 event it is the *only* route that stalls (2,755 ms and 1,504 ms). |
| "Not a whole-route block — a 4,042 ms request completed alongside a 42.5 ms one." | The fast neighbour was **issued after the release**. Reconstructed, it *is* a block. |
| "Individual requests fall into a slow path; their neighbours do not." | Requests issued 1.3–5 s apart complete **within ~110 ms of each other** — a queue draining in waves. |

The 2026-08-03 cold run, in full (8 requests ≥ 800 ms inside one 6.1 s window, two release
waves at ~:56.7 and ~:58.0):

```
started        completed         dur     wait   url
20:30:52.103   20:30:56.666   4562.9   4562.6   /public/profiles/loadtest
20:30:53.436   20:30:56.778   3342.3   3274.4   /public/profiles/loadtest   <- wave 1
20:30:54.770   20:30:58.023   3252.7   3239.0   /public/profiles/loadtest
20:30:56.104   20:30:58.023   1918.6   1904.8   /public/profiles/loadtest
20:30:56.770   20:30:58.118   1347.4   1347.3   /health
20:30:56.831   20:30:58.018   1187.3   1184.2   /health                     <- wave 2
20:30:57.437   20:30:58.239    801.7    801.5   /public/profiles/loadtest
```

`blocked`/`connecting`/`tls` were **0** on every stalled request — no DNS, no handshake. No
deployment overlapped either window (08-09 stall 00:39:56Z, nearest deploy started 00:41:16Z;
08-03 stall 10:30:52Z, deploys at 09:52Z and 11:16Z).

**Why the two events look different, in one mechanism.** `/api/health` always reaches the
origin; `social-platforms` essentially never does; profile reaches it ~4–8% of the time. On
08-09 the block missed a cache expiry, so only health queued and the edge-served profile and
social requests in the *same VU iteration* returned in 40 ms. On 08-03 the block overlapped
an expiry, so a profile revalidation was in flight and Cloudflare collapsed every subsequent
profile request behind it. Same blockage, two presentations.

**Consequence for the planned fix:** `stale-while-revalidate` removes the *amplifier* — the
cache-key collapsing that turns one slow revalidation into a stalled route — and it removes
the ~200 ms blip landing every ~32 s on the profile route. It would have changed **nothing**
in the 08-09 event, because `/api/health` is not cacheable. SWR is worth shipping; it is not
this bug's fix, and retiring the finding on it would close it on a false premise.

## What ran

`probe-stall.js` keeps baseline.js's three requests, order and 45/min pacing verbatim, and
adds what was never measured:

- **Two controls** — `cloudflare.com/cdn-cgi/trace` (CF-fronted, like Laravel Cloud) and
  `google.com/generate_204` (**not** Cloudflare). Nothing in the harness previously separated
  "Partna stalled" from "this laptop's link stalled".
- **`cf-cache-status` / `age` / `cf-ray` per request**, so a stalled request is classifiable
  as edge HIT vs revalidation on sight.
- **20 minutes, not 5** — the event lands about once per affected run, so exposure is the
  only lever.

`poll-origin-logs.sh` works around the ~100-entry log cap (a 5-minute window every 20 s,
deduped on read): **1,094 unique entries across 24m19s**, covering the whole run.

## Results — 901 iterations, 4,505 requests, 20m01s

| endpoint | n | p50 | p95 | max |
|---|---|---|---|---|
| health | 901 | 93.6 | 168.2 | 476.3 |
| profile | 901 | 51.9 | 179.2 | 845.8 |
| social | 901 | 51.5 | 141.6 | 917.6 |
| control_cf | 901 | 32.7 | 109.0 | **1714.1** |
| control_google | 901 | 30.9 | 101.5 | 199.2 |

Three requests ≥ 800 ms, **all inside the first 15 s** with `blocked` of 307–969 ms — TLS
ramp-up, not the stall. `http_req_failed` 0.00%, checks 2,703/2,703.

**The single worst request in the run was a control**, on a host with no relationship to
Partna: `control_cf` at 1,714 ms, TTFB-bound (`waiting` 1,713.9). That does not prove the
historical stalls were transport, but it is the first direct evidence that this measurement
path produces multi-second TTFB events unrelated to Partna — which is exactly what the
controls exist to catch.

Origin, over the same window: **p50 28 ms, p95 49 ms, max 718 ms** — and that 718 ms was
`/api/public/profiles/ra33rty`, live dev traffic, not the harness. Nothing multi-second
happened at the origin either.

## The 304 fix, verified by behaviour

The origin's own view of harness traffic inside the k6 window:

| path | origin arrivals | of 901 sent | status split |
|---|---|---|---|
| `/api/health` | 901 | **100%** | 901 × 200 |
| `/api/public/profiles/loadtest` | 40 | **4.4%** | **39 × 304, 1 × 200** |
| `/api/public/config/social-platforms` | 1 | 0.1% | 1 × 200 |

Before `59aec7497` the profile split was **19 × 200 / 18 × 304** at **8.2–8.4%** reach —
near-parity, which the 08-09 write-up read as poison-then-refetch. It now reads **97.5%
304 at 4.4% reach**, exactly halved. The arithmetic closes: 20 minutes at a 30 s TTL is 40
expiry windows, and the origin saw **exactly 40** profile requests. One revalidation per
window instead of two round trips.

Health at 100% reach also re-confirms the access log is not sampled.

## Arm 2 — same probe, tester's WAN link saturated. REPRODUCED.

Second 20-minute run, 16:04–16:24, identical probe, with five parallel bulk downloads from
GitHub (Fastly — deliberately not Cloudflare, so neither control shares the load path)
saturating the ~20 Mbit link.

**Characterise the load precisely — it is not what it looks like.** Under saturation the
gateway RTT stayed clean at **1.8 / 2.7 / 3.9 ms** while the internet path inflated from a
~35 ms baseline to **35 / 123 / 247 ms**. So the Wi-Fi radio was never the bottleneck; the
WAN was. This arm tests **WAN queue buildup**, not airtime contention.

| endpoint | p50 | p95 | max | (unloaded max) |
|---|---|---|---|---|
| health | 107.6 | 197.5 | 613.7 | 476.3 |
| profile | 86.9 | 312.2 | **9475.2** | 845.8 |
| social | 70.5 | 228.2 | 1362.3 | 917.6 |
| control_cf | 40.6 | 119.3 | 334.1 | 1714.1 |
| control_google | 37.1 | 122.7 | 295.0 | 199.2 |

**16 requests ≥ 800 ms**, against 3 unloaded (and those 3 were all TLS ramp-up).

### The measurement the investigation was missing

```
started       completed     ep         dur      wait     recv   cache
16:14:36.785  16:14:46.260  profile   9475.2   9210.4   264.6   REVALIDATED
  -- origin access log, same request:
     06:14:37Z   129ms  304  /api/public/profiles/loadtest
```

**The origin answered in 129 ms. The client saw 9,475 ms.** Both views of one request,
9.3 seconds unaccounted for outside Partna. Every other origin entry in that window is
`/api/health` at 23–42 ms. This is the client-vs-origin pairing that the ~100-entry log cap
prevented four times before.

### And a wave that excludes the origin by construction

```
=== wave 7: 4 requests — EDGE/TRANSPORT — every request was a cache HIT
    starts spread 4000ms, completions spread 338ms  <-- RELEASED TOGETHER
    16:14:38.118  16:14:43.452  profile  5334.8  wait 3957.7  HIT age=5
    16:14:39.452  16:14:43.115  profile  3663.1  wait 2702.2  HIT age=5
    16:14:40.785  16:14:43.399  profile  2614.1  wait 1382.7  HIT age=5
    16:14:42.118  16:14:43.406  profile  1288.5  wait 1117.8  HIT age=5
```

Four requests issued across 4 seconds, released within 338 ms — the historical signature
exactly. All four `HIT age=5`: **served entirely from the edge, never reaching PHP.** The
origin cannot be implicated in a request that never arrived.

### ⚠️ A flaw in this script's own verdict, now fixed

`analyse-stall.py` originally labelled wave 7 **"PARTNA ORIGIN — controls unaffected"**,
because it keyed the verdict on which endpoints appeared and ignored cache status. It now
checks cache status first: all-HIT ⇒ edge/transport, full stop.

The controls also **under-detect congestion**, so "controls clean" was never as strong as it
read. On the wire: profile **4,440 bytes**, health 31, `control_cf` 206, `control_google` 0.
A one-packet response survives a congested link that a four-packet response does not. The
controls are still worth having — they cleanly exclude "everything on this laptop stalled" —
but size-matched controls would be better, and the origin's own `duration_ms` outranks both.

## Verdict

**The stall reproduces on demand by congesting the tester's WAN link, and in the reproduced
instance the origin is exonerated by its own access log.** Two independent proofs in one run:
a 9,475 ms client request the origin logged at 129 ms, and a released-together wave of four
requests that were pure edge hits.

**What is still not proven** is that the *historical* events were this mechanism. The 08-09
event stalled `/api/health` — a **31-byte** response — TTFB-bound with `receiving` 0.1 ms,
whereas today's loaded stalls concentrate on the largest payload and carry 265–1,659 ms of
`receiving`. Structurally identical, not identical in payload dependence. What can be said:
congestion on the measurement path produces an indistinguishable signature, the origin has
never once been caught slow during one, and every historical episode falls on a day with a
parallel session building on the same laptop.

Unloaded control, same day: 20 minutes, 4,505 requests, **zero** stalls.

**Actions:**

1. **Treat multi-second k6 tails as measurement artefacts unless the origin's own
   `duration_ms` corroborates them.** That check is now one command
   (`poll-origin-logs.sh slow`) and it has never yet corroborated one.
2. **Ship `stale-while-revalidate`** on `AddPublicCacheHeaders` — for the ~200 ms per-30 s
   revalidation blip and the key-collapsing amplifier, on its own merits. It is not a cure
   for this and must not be recorded as one.
3. Do **not** add a `max` or `p(99)` threshold. Both runs' worst requests were transport.
4. Re-run arm 2 if the fingerprint needs re-checking — it is now a reliable reproduction.
