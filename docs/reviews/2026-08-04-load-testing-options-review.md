# Load-testing options review — distributed testing, k6 Cloud, and what actually needs measuring

- **Date:** 2026-08-04
- **Trigger:** "Phase 1 passed. What else needs load testing, and how do we do a distributed run?"
- **Status:** Research + recommendation. No code changed, no service purchased.
- **Related:** `scripts/launch-check/k6/README.md`, `docs/superpowers/specs/2026-07-18-k6-load-testing-design.md`

---

## Bottom line

**Don't buy anything, and don't build a distributed harness yet.** Two findings drive that:

1. **The design spec's plan for the thundering-herd problem does not work.** §7 defers it to "distributed
   load (k6 Cloud, multi-region)". But k6 Cloud allocates **one load generator per 300 VUs**, and one
   generator is one source IP. At your target you'd get 1–3 distinct IPs. Simulating 1,000 IPs needs
   300,000 VUs ≈ **$3,700 for a five-minute test**. Geographic distribution is not IP distribution.

2. ~~**There is a real, concrete stampede window in the Worker.**~~ **✅ MEASURED 2026-08-04 — and the
   origin is shielded.** Three bursts (`results/2026-08-04-concurrent-miss-probe.md`) put **160 concurrent
   cold-or-stale requests** through the edge and produced **3** profile fetches at Laravel — a fan-out of
   ~1:50, not the 1:1 modelled in §3 below. Both risky paths coalesce, including the stale-shadow path.
   The cost was three purges and three local k6 bursts: **$0**.

The recommended sequence was: **probe the stampede locally (free) → Phases 2a/2b → one Grafana Cloud volume
run before public launch (free tier) → never pay for IP simulation.** Step 1 is now done and came back
clean, which lowers the urgency of everything after it.

---

## 1. What is currently unmeasured

The harness covers the anonymous public read path and one write endpoint. Concretely:

| Surface | Total | Harness covers |
|---|---|---|
| Named rate limiters (`AppServiceProvider`) | 22 | 3 — `public-profile`, `analytics`, `health-check` |
| API endpoints | ~1,450 lines / 5 route files | 4 |
| Requests sending an `Authorization` header | — | **0** |

Uncovered, roughly in descending order of how much it would change a launch decision:

1. **Worker cold/stale path** — Phase 2a measures `cf-cache-status: HIT` ratio, which by definition
   *skips* the KV lookup and origin fetch. The path a first visitor takes is the one Phase 2a is built to
   avoid measuring.
2. **Concurrent-miss behaviour** — see §3. Nothing probes it.
3. **Authenticated dashboard** — `VerifySupabaseJwt` never executes in any phase (`grep Authorization
   scripts/launch-check/k6/*.js` → no matches). Different queries, different N+1 risk.
4. **Media upload / video** — the `redis_video` queue is the most memory-hungry job class and is untouched;
   Phase 3 drives analytics pageviews only.
5. **Cache stampede + Valkey memory pressure** — one 250 MB instance, instance-wide `volatile-lru`, shared
   across queue/cache/sessions/locks.
6. **Enquiries / leads** — a real conversion path with its own limiter, never exercised.
7. **Pre-account builds** — *recommend never load testing this.* Places is the only uncapped paid API; a
   saturation run bills real money for no signal. The control there is a spend ceiling, not a k6 script.

---

## 2. Finding — the k6 Cloud premise does not hold

**What the spec says (§7):** the thundering herd "needs **distributed** load (k6 Cloud, multi-region).
That is a separate, larger, later step before public launch."

**What is actually true:** Grafana Cloud k6 hosts **300 VUs per load generator instance**, and source-IP
count scales with generators, not VUs.

| Configuration | Load generators | Distinct source IPs |
|---|---|---|
| 50 VUs, 1 zone (your named target) | 1 | **1** |
| 200 VUs, 3 zones | 1 per zone | **3** |
| 1,000 VUs, 3 zones | 2 per zone | **~6** |
| 1,000 distinct IPs | 1,000 | **300,000 VUs ≈ $3,700 / 5 min** |

There are roughly 20 public load zones, so even spreading maximally across all of them caps you near
**20 IPs**. Against a 60/min per-IP limiter that is 1,200 req/min reaching origin — better than 60, but
nowhere near modelling thousands of independent visitors.

**Verified by:** Grafana Cloud docs (300 VU/instance ratio; static IPs are Pro-plan-only), and the
`options.cloud.distribution` schema, which distributes by *percentage of load across zones* — it has no
concept of IP count.

**Implication.** k6 Cloud is genuinely good at "does the edge hold at 5,000 concurrent from Sydney?"
It is structurally unable to answer "does a per-IP rate limiter mean anything against a real spike?"
Those are different questions and the spec conflates them. **§7 needs correcting.**

---

## 3. Finding — the Worker has a real, testable stampede window

> **⚠️ SUPERSEDED IN PART, 2026-08-04.** The mechanism described below is real and the code reading is
> correct, but the predicted *consequence* — N concurrent misses producing N origin fetches — **was
> measured and did not occur**. 160 concurrent cold/stale requests yielded 3 profile fetches at Laravel.
> Something between the router and the database coalesces, most likely `partna-pages`. Full method,
> numbers and caveats: `scripts/launch-check/k6/results/2026-08-04-concurrent-miss-probe.md`.
> Read the modelling below as the hypothesis that motivated the probe, not as a live finding.

### The mechanism

Cloudflare draws an explicit distinction between its two caching layers:

> "When many requests for the same cache key arrive simultaneously at a Cloudflare data center and the
> response is not yet cached, Cloudflare runs your Worker **once** and serves the resulting response to
> every waiting request… **This is one of the most significant differences between Workers Caching and the
> Cache API — the Cache API does not collapse concurrent requests, so a burst of traffic to a fresh URL
> invokes your Worker once per request.**"
> — [Cloudflare Workers docs — Cache](https://developers.cloudflare.com/workers/cache/)

**The Worker uses the Cache API.** `cloudflare-worker/src/index.js:730` — `const cache = caches.default;`
with `cache.match` / `cache.put` throughout. So the automatic collapsing does **not** apply.

### The three paths, and which two stampede

From `src/index.js:733-761`:

| # | Path | Origin fetches under N concurrent requests |
|---|---|---|
| 1 | Primary HIT (`cache.match(cacheKey)`) | **0** — safe |
| 2 | Primary MISS + stale shadow HIT → serve stale, `ctx.waitUntil(fetchAndCache(...))` | **N** — each request schedules its own background refresh |
| 3 | Cold miss (no primary, no shadow) → blocking `fetchAndCache` | **N** — each request fetches origin |

Path 2 is the more interesting one. Primary TTL is **24 h**, shadow TTL is **7 days**
(`wrangler.toml:22-23`). So for a six-day window after primary expiry, *every* arriving request is served
stale — good — but *also* schedules its own origin refresh, because nothing coalesces them.

### How bad, honestly

The window closes as soon as the first refresh completes and repopulates primary. So exposure is roughly:

```
concurrent origin fetches ≈ arrival rate × origin fetch duration
```

Using your own measured origin latency (~150–350 ms):

| Traffic to one page | Concurrent origin fetches per TTL rollover, per Cloudflare PoP |
|---|---|
| 5 req/s (pilot) | ~1 — a non-event |
| 500 req/s | ~100 |
| 5,000 req/s (viral) | ~1,000 |

`caches.default` is **per-PoP**, so the multiplier is per data centre. For a mostly-Australian audience
that concentrates in a couple of PoPs rather than spreading — which makes it worse, not better.

**This is correctly a pre-public-launch concern, not a pre-pilot one.** But it is the specific, real
mechanism behind the vague "thundering herd" worry, and unlike the herd it is cheap to measure.

### Why this matters for the testing decision

**You can probe this from one laptop.** You do not need distributed IPs to see whether N concurrent
misses produce N origin fetches — you need N concurrent requests against one freshly-expired key, and a
count of what arrived at origin. That is a ~2-hour k6 script plus `cloud env:logs`, and it answers more
than any amount of paid distributed volume would.

If it turns out one fetch serves all N, the herd is largely a non-event and you can stop worrying. If it's
N, you have a bug worth fixing before launch — and fixing it is worth more than measuring it harder.

---

## 4. Options considered

### A. Grafana Cloud k6 — free tier ✅ *recommended, later*

Managed service; same `k6` binary you already have (v2.1.0 ships `k6 cloud run`). Add a `cloud` block to
options, `k6 cloud login --token …`, then `k6 cloud run spike-edge.js`.

```js
export const options = {
  cloud: {
    name: 'Partna edge spike',
    projectID: 123456,
    distribution: {
      sydney:  { loadZone: 'amazon:au:sydney',  percent: 60 },
      ashburn: { loadZone: 'amazon:us:ashburn', percent: 20 },
      london:  { loadZone: 'amazon:gb:london',  percent: 20 },
    },
  },
  scenarios: { /* unchanged */ },
  thresholds: THRESHOLDS.edge,
};
```

- **Cost:** free tier is **500 VUh/month**; VUh = max VUs × duration. Phase 2a as written = **1.9 VUh**.
  `SPIKE_VUS=200` for 5 min = **16.7 VUh**. You would use ~3% of the allowance. Beyond it, $0.15/VUh.
- **Gives you:** real volume from Sydney, hosted dashboards, trend history across runs.
- **Does not give you:** IP diversity (§2). Static IPs for WAF allowlisting are **Pro-plan-only**.
- **Friction:** distributed traffic at `partna.au` is likely to trip Cloudflare's own DDoS/bot mitigation,
  at which point you measure Cloudflare rather than Partna. Workaround: a temporary WAF skip rule matching
  the existing `X-Load-Test: 1` header. Also generates real CF request billing on the prod zone.

### B. Grafana Cloud k6 — paid ❌

$0.15/VUh buys more volume. It does not buy IP diversity. Nothing here is worth paying for at your scale.

### C. `k6-operator` on Kubernetes ❌

Grafana's official operator; splits VUs across pods, and Grafana lists "should be accessed from multiple IP
addresses" as an explicit reason to use it. Installed via Helm.

- **Blocker:** you have no Kubernetes cluster, and the operator "expects deep Kubernetes knowledge and
  leaves results, history, and UI up to you." Standing up k8s to run a load test is a larger project than
  the thing it tests.
- IP diversity also depends on pod egress NAT — many clusters egress through a small number of NAT gateway
  IPs, so pods ≠ IPs without deliberate config.

### D. Multi-VM DIY distributed ⚠️ *only if §3 shows a real problem*

N cheap VMs in different regions, each running local k6 with `--execution-segment`, results aggregated
manually. 20 × small instances ≈ a few dollars for a run window.

- **Genuinely gives** ~20 distinct IPs — same order as k6 Cloud's maximum, but you control them and can
  allowlist them at Cloudflare for free.
- **Costs** orchestration and result-aggregation effort that k6 Cloud gives you free.
- Verdict: only worth it if you specifically need allowlistable IPs, which you would only need if
  Cloudflare mitigation blocks option A.

### E. Commercial alternatives ❌

| Tool | Entry price | Note |
|---|---|---|
| BlazeMeter | $149/mo Basic (1,000 concurrent, 200 tests/yr) | Cloud execution layer over JMeter/Gatling/k6/Locust |
| Gatling Enterprise | Distributed gated at Team plan | 3,000–5,000 VUs per agent — *fewer* agents, so fewer IPs |
| Azure Load Testing | Consumption | Only compelling if you were on Azure; you are not |
| Loadium | — | Up to 50 locations, dedicated IPs available |

None solve the IP-diversity problem at a price that makes sense pre-revenue, and all of them are strictly
worse than the free Grafana tier for the volume question. **Note Gatling's higher VUs-per-agent makes it
*worse* for IP diversity, not better** — a useful reminder that the two goals pull in opposite directions.

### F. Test the mechanism instead of the scale ✅ *recommended, now*

Covered in §3. A concurrent-miss probe against one key, run locally, counting origin arrivals.
Free, hours not days, and it targets the actual risk rather than a proxy for it.

### G. Design-layer mitigation ✅ *if F shows a problem*

If the probe confirms N-fetches-per-N-requests, the fix is architectural, not a bigger test. Options worth
investigating, in rough order of preference — **none of these are verified, they are leads:**

- **Coalesce in the Worker** — a Durable Object or KV-backed lock per cache key so only the first miss
  fetches and the rest await it. Most direct fix; adds a stateful component.
- **Only refresh probabilistically** on the stale path — instead of every request scheduling
  `fetchAndCache`, refresh with probability p, or gate on a short-TTL KV "refresh in flight" marker.
  Cheapest change; approximate rather than exact.
- **Reconsider Cache API vs Workers Caching** — Workers Caching collapses automatically, but you'd lose the
  stale-shadow SWR pattern the Cache API enables. That is a genuine trade-off, not a free upgrade, and the
  shadow is doing real work for you today.
- **Cloudflare-layer rate limiting rules** — protects the origin regardless of source-IP count, which is
  the one thing a per-IP application limiter fundamentally cannot do.

---

## 5. Recommendation

| When | Action | Cost | Answers |
|---|---|---|---|
| ~~Now~~ **✅ DONE 08-04** | Concurrent-miss probe (§3/F) | $0 | **Answered: no stampede reaches the DB.** 160 concurrent → 3 origin fetches |
| **Now** | Phases 2a + 2b, already written, checkpoint protocol | $0 | Edge cache holds; limiter shields Postgres |
| ~~If probe is bad~~ | ~~Design fix from §G~~ — **not needed**; §G is unnecessary on this evidence | — | — |
| **Pre-public-launch** | One Grafana Cloud run, `amazon:au:sydney`, 500–1,000 VUs, ~3 VUh | $0 (free tier) | Edge holds at genuine volume — and whether coalescing still holds above 60 concurrent |
| **Never** | Paying to simulate thousands of IPs | — | Buy Cloudflare protection instead |

Rationale for the ordering: the probe is cheaper than the cloud run, targets a confirmed mechanism rather
than a hypothesis, and its result determines whether the cloud run is even interesting. Running a paid or
elaborate test before knowing whether the coalescing bug exists would be measuring the wrong thing harder.

---

## 6. Corrections owed to existing docs

1. **`docs/superpowers/specs/2026-07-18-k6-load-testing-design.md` §7** — "needs distributed load (k6
   Cloud, multi-region)" is wrong as a remedy for the herd. Replace with the IP-count reality from §2 and
   point at the coalescing question instead.
2. **`scripts/launch-check/k6/README.md`** — the "Not measured" list should gain *concurrent-miss / stampede
   behaviour* and *Worker cold path*, which are currently invisible.

---

## 7. Open questions — flagged, not answered

These came up during research and are **not verified**. Each would change some conclusion above:

1. **Does `CF-Connecting-IP` survive the service binding into Laravel?** `env.PARTNA_PAGES.fetch()` is a
   worker-to-worker call. If the visitor IP is not preserved end-to-end, the `public-profile` limiter may be
   bucketing sitepage-driven API traffic under something other than the visitor — which would change what
   Phase 2b actually proves.
2. **How many Cloudflare PoPs serve Australian traffic?** `caches.default` is per-PoP, so this is the
   multiplier on §3's numbers.
3. ~~**Does `partna-pages` have its own cache between the Worker and Laravel?**~~ **Largely answered
   08-04:** something absorbs the stampede — 160 concurrent → 3 origin fetches. Given Cloudflare documents
   that the Cache API does not collapse, `partna-pages` is the leading candidate. **Still open:** *which*
   layer, which decides whether the router's own fan-out (worker invocations, service-binding volume) is
   real cost at scale. Needs `partna-pages`-side counts, not visible from this repo.
5. **Why is serving stale no faster than a cold miss?** Run 3 median 682 ms vs run 1's 729 ms. An SWR
   shadow should make the stale path near-instant. Probably RTT/body-download dominating, but it warrants
   a `http_req_waiting` vs `http_req_receiving` split before anyone concludes the shadow is working.
4. **Does Cloudflare's bot/DDoS mitigation trigger on `X-Load-Test: 1` traffic at volume?** Determines
   whether option A is usable without a WAF rule.

---

## Sources

- [Cloudflare Workers docs — Cache](https://developers.cloudflare.com/workers/cache/) — request collapsing; Cache API exclusion
- [Grafana Cloud k6 — Use load zones](https://grafana.com/docs/grafana-cloud/testing/k6/author-run/use-load-zones/)
- [Grafana Cloud k6 — Cloud options](https://grafana.com/docs/grafana-cloud/testing/k6/author-run/cloud-scripting-extras/cloud-options/)
- [Grafana Cloud k6 — Manage static IP addresses](https://grafana.com/docs/grafana-cloud/testing/k6/projects-and-users/manage-static-ips/)
- [k6 — Running large tests](https://grafana.com/docs/k6/latest/testing-guides/running-large-tests/) — 300 VUs per load generator
- [grafana/k6-operator](https://github.com/grafana/k6-operator)
- [k6 — Set up distributed k6](https://grafana.com/docs/k6/latest/set-up/set-up-distributed-k6/)
- [Grafana Cloud pricing 2026](https://monitoringcost.com/grafana-cloud-pricing) — 500 VUh free tier, $0.15/VUh
- [Best Load Testing Tools 2026](https://pflb.us/blog/best-load-testing-tools/) — commercial comparison
