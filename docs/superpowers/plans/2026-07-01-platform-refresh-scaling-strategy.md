# Platform Refresh — Scaling Strategy & Foundation

**Date:** 2026-07-01
**Status:** Strategy / direction capture. **Plan 1 (Foundation) WRITTEN 2026-07-02** → `docs/superpowers/plans/2026-07-02-platform-refresh-foundation-plan-1.md` (awaiting sign-off; P1 blocker gate). Execution structure in §8; thin follow-ons remain roadmap.
**Origin:** Audit finding **SCALE-1** (P1) — the whole refresh pipeline is one serial daily command (300/run cap, synchronous fetches).
**Related code:** `app/Console/Commands/RefreshIntegrationConnectionsCommand.php`, `app/Services/Platforms/PlatformRefresher.php`, `app/Services/Platforms/Registry/`, `app/Services/Platforms/Strategies/`, `app/Jobs/Platforms/`
**Research backing:** deep-research run 2026-07-01 (18 adversarially-verified claims; sources listed at the bottom).

---

## 1. The problem, in plain English

Keeping everyone's live info fresh (Google reviews/photos, YouTube videos, event listings, Apple Music, etc.) is currently done by **one worker who visits at most 300 accounts a day, one at a time, in a single line.** Fine for a handful of pilot users. At thousands of users the line gets so long that any given account is only refreshed every few weeks — pages silently show stale data — and one slow upstream stalls everyone queued behind it. Nothing alerts us when this starts happening.

At ~20,000 refreshable connections ÷ 300/day ≈ a **67-day refresh cycle.**

## 2. The one decision that matters

> **Don't build a "polling system." Build a "refresh ONE connection" job that any trigger can fire — a schedule, an incoming webhook, or a user click — all landing on the same code path.**

If the unit of work is *one connection = one job*, and "when is it due" is a *per-connection timestamp*, then push, poll, and manual refresh are three doors into the same room. This is the choice that **doesn't get ripped out** as we grow or as we swap data sources.

Every scaled system in the research separates three concerns our current command fuses together:
1. **Deciding who's due** (a scheduler)
2. **Fetching** (a worker pool — Horizon)
3. **Throttling** (a per-provider rate limiter in shared Redis)

## 3. ⭐ Does this survive the Apify → official-API swap? (YES — it's already designed for it)

**Josh's question:** right now many connections/schedules run through **Apify** (and other scrapers). Eventually we swap to real APIs — e.g. applying for **Meta (Instagram) API in ~a month**. Is that an easy swap or a major change?

**Answer: localized change, not a re-architecture — and the codebase already has the seams for it.**

What's already in place today:
- **`FetchStrategy`** (`Strategies/Contracts/FetchStrategy.php`) — a one-method interface `fetch(connection): array`. *How* data is fetched (Apify scrape vs. official API) is fully hidden behind it. Apify lives in concrete scrapers (`MenuApifyScraper`, `GoogleBusinessApifyScraper`, `InstagramApifyBudget`) used *behind* this seam — never in the scheduler or registry spine.
- **`WebhookRefresh`** (`Strategies/Contracts/WebhookRefresh.php`) — already declared as a deliberately-empty seam: *"future webhook-push platforms slot in by implementing this interface, with NO restructuring of the spine."*
- **`OAuthConnect`** and **`ApiKeyConnect`** (`Strategies/Contracts/`) — the credential seams for official APIs already exist.
- Adding a platform is already "one descriptor, zero migration" (PlatformRegistry redesign, complete 2026-06-29).

**So when Meta API access lands, the swap for Instagram is:**
1. Write a new `InstagramApiFetch implements FetchStrategy` that calls the Graph API instead of the Apify scraper.
2. (Optional, better) implement `WebhookRefresh` so Meta *pushes* updates instead of us polling — the seam is already there.
3. Add per-user OAuth token storage + refresh at connect-time (the `OAuthConnect` seam anticipates this).
4. Point the Instagram descriptor at the new strategies. **One descriptor change.**
5. The scheduler, dedup, rate limiter, storage, and cache-purge stay **untouched.**

**The one genuinely new piece:** per-user OAuth credentials (Apify uses one global token; Meta uses a per-user OAuth token that expires and must be refreshed). That's *connect-time* work, isolated behind the `OAuthConnect` seam — the refresh loop just uses whatever credential the connection carries.

**Non-negotiable to keep the swap easy** (call this out in every future PR): the new scheduling / rate-limiting / dedup layer must be **source-agnostic** — it only ever knows *"refresh connection X,"* never *"scrape via Apify."* As long as new work talks to the `FetchStrategy` / `RefreshStrategy` / `WebhookRefresh` seams and not to Apify directly, Apify → official API stays a per-platform swap, not a system rewrite.

## 4. The building blocks (what a scaled refresh looks like)

### a. Let them tell us, instead of us checking (push over poll)
Two ways to stay fresh: **us checking repeatedly** (polling) or **them notifying us** (webhooks/push). The big platforms prefer push and say so in their own docs:
- **YouTube** pushes new-video notifications via PubSubHubbub (WebSub); Google calls it *"much more efficient than polling."*
- **GitHub**: *"subscribe to webhook events instead of polling… to stay within the rate limit."*
- **Instagram/Meta** pushes real-time webhooks for comments/@mentions/story-expiry/messages. (Caveat: failed deliveries retry for 36h then drop — our endpoint must be reliable.)

*Cost of push:* WebSub subscriptions expire and need periodic renewal — a small scheduled "renew leases" task.

Our platforms split into two tiers: **push-capable** (YouTube, Instagram, Eventbrite, Strava) and **poll-only** (Google reviews, Apple Music, Bandcamp, Pinterest, Vimeo, Humanitix). The design must serve both — which is exactly why the "any trigger → one job" spine matters.

### b. Make the checking cheap (conditional requests)
For poll-only platforms, don't re-download everything — ask *"anything new since last time?"* using a stored `ETag`/`Last-Modified` and `If-None-Match`. An unchanged resource returns **304 Not Modified**, which (per GitHub's verified rule) *doesn't count against the rate limit.* On a 304 we just bump `next_refresh_at` — no payload write, no cache purge.

### c. Per-connection "due" scheduling (kill the 300-cap)
Give each connection its own appointment card: a **`next_refresh_at`** column, with the interval derived from a **per-provider TTL declared on the platform descriptor**. "Due" = `next_refresh_at <= now()`. This is how Nango (a company doing exactly our job) does it — a scheduled-run-time column, filtered at dequeue. Delete `--limit=300` entirely.

### d. Don't overwhelm any one provider (distributed rate limiting)
Fan-out without throttling hammers Google and gets us blocked. Use a **per-provider limit in shared Redis** (`Redis::throttle("platform:google")->allow(N)->every(1)`); when a job would exceed it, `release()` it back with a delay — it waits its turn instead of failing. This is the Sidekiq-Enterprise pattern (OverLimit → auto-reschedule), and the limit must be **global across all workers**, not per-process. One provider's limit can't starve the others.

### e. An alarm before data rots (staleness gauge)
A scheduled metric counting connections where `next_refresh_at` is overdue. When it climbs, we've outgrown capacity — it warns us *before* pages go stale. (Temporal ships this as `ApproximateBacklogCount`; we compute the equivalent.)

### f. Do NOT adopt Temporal/Cadence yet
Durable workflow engines earn their keep at massive scale (Vantage adopted Temporal at ~25M jobs/day) — but Nango, doing our exact job, **ripped Temporal out** for a Postgres queue. The two strongest pro-Temporal claims were *refuted* in verification. For a single-step refresh (fetch → persist), Horizon + a Postgres due-table is the right tool. The trigger/executor split means we *could* swap to Temporal years later without touching the rest — free optionality, don't prepay for it.

## 5. Build order (incremental, not big-bang)

1. **Cron → dispatcher + per-connection job + per-provider throttle.** Replaces the serial loop and the 300-cap. **This is the SCALE-1 fix and stands alone.** New job needs `$backoff` + `$tries` (JobHygienePolicyTest).
2. **Conditional requests (ETag / 304)** — makes the poll tier cheap.
3. **Webhooks for push-capable platforms** (YouTube/Instagram/Eventbrite/Strava) via the existing `WebhookRefresh` seam — biggest load reduction; dovetails with the Meta API swap.
4. **Unify connect-time jobs** (`InstagramConnectJob`, `MenuFetchJob`, `GoogleBusinessEnrichJob`) onto the shared execution core + one throttle, so connect and refresh share a single provider rate budget (prevents signup-spike thundering herd).
5. **Adaptive intervals** — check often-changing things more, rarely-changing things less; error-backoff off `consecutive_failures`. Only if the staleness gauge says we need it.

Step 1 solves the audit finding. Steps 2–5 are the foundation that makes it durable — cheap to add *because* Step 1's spine is source-agnostic.

## 6. Open questions / decisions to make when we plan Step 1 properly

- **Dispatcher vs. self-pulling workers?** A scheduled command that dispatches due jobs (idiomatic Horizon) vs. Nango-style workers polling a due-table with `SELECT … FOR UPDATE SKIP LOCKED`. Leaning dispatcher — Horizon *is* the worker pool.
- **Schema:** new columns on the connections table (`next_refresh_at`, `etag`/`last_modified`) → Supabase migration (raw SQL, `supabase/migrations/`). Note the SQLite-vs-Postgres NOT NULL/CHECK drift when adding columns.
- **Per-provider TTLs:** where on the descriptor, and starting values per platform.
- **Queue topology:** one `platform_refresh` queue with per-provider throttle keys, vs. a queue per provider class.

## 7. Cross-finding impact — what else in this audit the foundation resolves

Mapping this foundation against the other 13 findings in the same CONSOLIDATED file. The point: building the spine first **shrinks** the rest of the audit — several findings collapse from a custom one-off fix into "add one config/claim on the shared plumbing."

**🟢 Directly fixed (same architecture):**
- **#JOB-1** (P1) — its prescribed fix ("dispatch a job, return 202 + poll URL") *is* our spine; **Step 4 (connect-time unification)** converts exactly these link/Fresha controllers.
- **#SCALE-3** (P2) — our **per-provider limiter** replaces the global throttle; **conditional requests** kill daily re-resolve of unchanged Google Places/iTunes (billed).
- **#SCALE-4** (P2) — our **distributed per-provider concurrency limiter + backpressure** paces the 200-job burst automatically.

**🟡 Foundation builds the mechanism; small per-item wire-up remains:**
- **#SCALE-2** (P1) — a shared Apify budget is a **per-provider spend cap on the same shared-Redis limiter** the foundation adds; Step 4 puts connect+refresh under one budget. *Left:* the `tryClaim()` calls in the two scrapers.
- **#OBS-1** (P2) — new code built with `report()`/`fail()` + the **fleet staleness gauge** add visibility. *Left:* the ~5 existing legacy sites still need `report()`.
- **#CCH-2** (P2) — per-provider (ytimg) concurrency cap blunts the probe storm. *Left:* single-flight lock + shorter TTL recheck.
- **#CACHE-1** (P2) — conditional requests make the full menu rebuild fire far less often. *Left:* the diff-vs-rebuild write itself.

**⚪ Unrelated — separate fixes regardless** (some in files we touch anyway, cheap to sweep up): #CCH-1, #JOB-2, #SCALE-5, #SHOP-1, #CCH-3, #CCH-4.

**Net:** the foundation is the engine for the whole scaling + Apify-cost + third-party-politeness cluster — **both remaining P1s and 3–4 of the P2s.** Sequence it first.

## 8. Execution structure (agreed 2026-07-01) — one foundation + thin follow-ons

**Decision:** NOT one large plan, and NOT fully separate. Build the shared primitives once (Plan 1), then let each finding be a thin task that *consumes* them — mirroring the codebase's own "one spine, per-platform adapters" philosophy. This preserves small-PR reviewability and the audit's per-unit sign-off gates, and doesn't block the shippable SCALE-1 fix behind Meta-API-gated webhook work.

### Plan 1 — Foundation (standalone; ship first)
The reusable primitives, = the standalone **#SCALE-1** fix:
- source-agnostic "refresh ONE connection" job (any trigger: schedule / webhook / manual)
- per-connection due-scheduler (`next_refresh_at` + per-provider TTL on the descriptor; kills the 300-cap)
- **distributed per-provider limiter** in shared Redis (the plumbing SCALE-2/3/4 consume)
- conditional-request support (ETag / 304)
- staleness/backlog gauge → Nightwatch
- P1 + DB migration ⇒ **blocker gate**: plan via `writing-plans`, present, wait for sign-off before implementing.

### Thin follow-ons (each a small PR that consumes Plan 1's primitives)
Recorded here as roadmap; write each as its own small plan/PR *after* Plan 1 lands:
- **Bundle A — #SCALE-2 + #SCALE-4:** claim the shared Apify budget/limiter in `MenuApifyScraper` + `GoogleBusinessApifyScraper`; pace `menu:retry` via the same limiter.
- **#JOB-1:** async-ify the 4 link controllers (202 + poll) — pairs with connect-time unification (§5 Step 4).
- **#SCALE-3:** mostly absorbed by the limiter + conditional requests; residual = tiny per-host config.
- **Bundle B — #OBS-1:** add `report()`/`fail()` at the ~5 legacy sites, matching the pattern the new code sets.

### Kept SEPARATE — do NOT fold into the above (independent hygiene; stay where the audit put them)
- **Bundle C — #CCH-1 / #CCH-2 / #CACHE-1** (lock & cache hygiene)
- **#JOB-2** (Instagram R2 orphan cleanup)
- **P3 cleanups — #SCALE-5 / #SHOP-1 / #CCH-3 / #CCH-4**

### When work resumes
Start with Plan 1 via `writing-plans` → sign-off → implement. Then pick up the thin follow-ons in the order above. The separate hygiene bundles can be executed independently at any time via the normal `execute audit` flow.

---

## Research sources (verified 2026-07-01)

- YouTube PubSubHubbub push — https://developers.google.com/youtube/v3/guides/push_notifications
- GitHub REST best practices (webhooks-over-polling, 304-is-free) — https://docs.github.com/en/rest/using-the-rest-api/best-practices-for-using-the-rest-api
- Instagram/Meta webhooks (36h retry-then-drop) — https://developers.facebook.com/docs/instagram-platform/webhooks
- Nango: migrating off Temporal to a Postgres task orchestrator (our exact problem shape) — https://nango.dev/blog/migrating-from-temporal-to-a-postgres-based-task-orchestrator/
- Sidekiq Enterprise rate limiting (5 limiter types, distributed, OverLimit→reschedule) — https://github.com/sidekiq/sidekiq/wiki/Ent-Rate-Limiting
- Vantage: Sidekiq → Temporal at ~25M jobs/day — https://www.vantage.sh/blog/sidekiq-vs-temporal
- Temporal worker performance (rate limits, ApproximateBacklogCount) — https://docs.temporal.io/develop/worker-performance
