# PROMPT — Worker/async layer: LAUNCH-tier fixes

> Paste this whole file as the opening message of a fresh session in the
> Comet-Backend repo (`/Users/joshuahunter/Herd/Side Street/backend`).
> You are the **orchestrator**. You dispatch subagents; you do not write
> production code yourself.
>
> **Prerequisite:** `2026-07-23-worker-async-pilot-PROMPT.md` is merged. In
> particular `RV-4` (worker memory over-commit) must be resolved — Part A adds
> load to the same 1 GiB box.

---

## Read this first — the launch tier does NOT fit in one orchestrated run

You asked whether it does. It does not, and the reason is structural rather than
a matter of size.

**Part A (analytics) fits.** One clean seam, two units, no external dependency.

**Part B (async platform connects, roadmap #12) does not.** Three blockers, all
verified against current code on 2026-07-23:

1. **The heavy endpoints are not on the registry path.** Roadmap #12 targets
   Fresha, Shop, Apple, Eventbrite and Skool — **plus Humanitix**, which the review
   omits but has the identical shape. Each has a **bespoke controller**
   (`app/Http/Controllers/Api/Platforms/{Fresha,Shop,Apple,Eventbrite,Humanitix,Skool}Controller.php`)
   sitting alongside `GenericPlatformController`. The generic
   `connectDeferred()` machinery does not reach them.
2. **The bespoke controllers have no descriptor opt-in — the registry machinery is
   otherwise armed.** `->deferredConnect()` has **eight** call sites
   (`PlatformRegistryServiceProvider.php:150,177,196,211,225,238,251,265` — spotify,
   bandcamp, twitch, pinterest, strava, vimeo, youtube, youtube-music), so
   `PARTNA_CONNECT_DEFERRED=<one of those>` flips that platform to async today.
   `PlatformDescriptor.php:54`'s `private bool $deferredConnect = false;` is the
   *default*, overridden by a fluent builder in the provider — a grep of the
   descriptor class alone misses the setters. What genuinely blocks roadmap #12 is
   that the six bespoke platforms (Shop, Apple, Fresha, Eventbrite, Humanitix, Skool)
   have no opt-in and no `ConnectStrategy`, so `connectDeferred()` never reaches them.
3. **It is a cross-repo contract change.** `200` → `202 + poll` requires frontend
   work in a separate, read-only-from-here repo
   (`github.com/hunterbalcombesykes/partna-frontend`).

There is also an unresolved architectural fork — migrate the six bespoke
controllers onto the registry, or hand-roll async+poll in each — which is Josh's
decision, not an implementer's.

**So this prompt is structured as: Part A implements; Part B produces a design
and a contract, then stops.** Do not let Part B slide into implementation.

---

## Non-negotiable rules

Identical to the pilot prompt — read `§ Non-negotiable rules` in
`docs/superpowers/plans/2026-07-23-worker-async-pilot-PROMPT.md` and obey it
verbatim. The ones that bite hardest here:

- Branch `audit-fix/worker-async-launch-2026-07-23` off `development`, dedicated
  worktree, own `composer install` and `.env`, **no symlinked `vendor`**.
- Units sequential; never two implementers at once.
- Never run `composer test` alongside a running implementer;
  `COMPOSER_PROCESS_TIMEOUT=0` on full-suite runs.
- Every implementer prompt explicitly forbids `git stash`.
- Verify every premise against current code before implementing.
- Tests are SQLite, production is Postgres — check `supabase/migrations/` DDL for
  anything constraint-bound.
- Ledger at `.superpowers/sdd/progress-2026-07-23-worker-async-launch.md`.

**Execution policy:** Plan Opus 4.8 · Implement Sonnet 4.6 · Review a *separate*
Sonnet 4.6 · final whole-branch review on **Opus 4.8**. Specify the model on
every dispatch.

---

# Part A — Analytics ingest

Covers roadmap **#11** and TRIAGE unit 10 (`R3-SCALE-1`). Note the TRIAGE already
flags `R3-SCALE-1` as *"L-effort shared queue infrastructure with 2026-07-22 OOM
history — capacity-planning decision, NOT a routine fix session."* Honour that:
`A2` is gated.

## A0 — Confirm the premise before building anything

**Do this first and report the numbers.** The review is explicit that current load
is effectively zero — every queue depth measured 0, `analytics.site_visits` held
3,944 rows — and that *all* scaling claims are projections from code and config,
not observed throughput.

Re-probe: queue depths, `analytics.site_visits` row count, and
`AggregateCacheMetricsJob`'s recent hit-rate output.

- If load is still effectively zero, **say so and present the case for deferring
  Part A.** The `AnalyticsIngestor` interface (§A1) means adding batching later is
  an afternoon's work; building it now optimises against a wall nobody has hit and
  introduces a new data-loss mode that does not exist today. Josh decides.
- If load has grown materially, proceed.

Do not skip this because the roadmap says the work is warranted. The roadmap was
written against zero load and says so.

## A1 — Batched analytics ingestor
**Effort M · autonomous (if A0 clears)**

- **Where:** `app/Services/Analytics/Ingestors/{QueuedIngestor,SyncIngestor}.php`,
  `app/Services/Analytics/Contracts/AnalyticsIngestor.php`,
  `app/Http/Controllers/PublicSite/AnalyticsController.php` (6 call sites),
  binding in `AppServiceProvider`
- **Technical:** `QueuedIngestor::ingest()` dispatches **one job per HTTP beacon**,
  and `AnalyticsController` calls it once per pageview / click / section_view /
  section_dwell / item_view / session_ping. Every visitor interaction becomes a
  Redis round-trip plus a serialize/deserialize cycle plus a Postgres insert,
  competing for one of two `supervisor-1` processes at **7th priority of 11**. The
  job itself is cheap and correctly idempotent (`insertOrIgnore` on a minted PK),
  so **the fix is batching, not correctness**.
- **Why this is cheaper than its roadmap label:** the seam is already cut. Six
  call sites, all in one controller, all behind `AnalyticsIngestor` with two
  existing implementations. Add a third implementation and swap the binding — do
  **not** touch the six call sites.
- **The risk to design around:** buffering introduces a loss window that
  job-per-event does not have. Be explicit about where the buffer lives, what
  flushes it, and what happens on worker death mid-buffer. A crash that silently
  drops analytics events is a worse outcome than the latency it fixes.
- **Also in scope:** `analytics:compute-popularity` aggregates a site's **entire**
  raw-event history per run (`ComputeContentPopularityScores.php:410-503`), bounded
  only by the 90-day purge, on a 15-minute cadence. Site *selection* is bounded to
  a 60-minute activity window but the per-site aggregation is not. Bound it.
  (Related: `R2-SCHED-1` in the pilot run sets this command's
  `withoutOverlapping` TTL — check what landed there before changing cadence.)
- **Out of scope:** the rollup tables (`site_metrics_daily` / `_hourly`) are
  vestigial and have never been populated; all reads compute from raw. Do not
  revive them as part of this unit.

## A2 — Analytics lane capacity (`R3-SCALE-1`)
**Effort L · 🔒 blocker — capacity decision, not a fix**

- **Technical:** `analytics` shares a single 2-process supervisor with ten other
  queues under strict listed priority, sitting 7th. `supervisor-1` head-of-line
  blocking is the first thing the review projects to break at 10× load.
- **What to do:** Produce options with memory arithmetic — a dedicated supervisor,
  a raised `maxProcesses`, or a resized instance — each costed against the box
  size `RV-4` settled on. Present to Josh and **wait**. The 2026-07-22 OOM was
  caused by the `horizon.defaults` union (every supervisor in `defaults` runs in
  *every* environment); any new supervisor inherits that behaviour, so state
  explicitly what the change does across all three env blocks.
- Do not implement without sign-off.

---

# Part B — Async platform connects (design only)

> **✅ SUPERSEDED — this design ran and is signed off (2026-07-24). Do not
> re-commission B0–B3.** The deliverables exist:
> - Design + fork recommendation + unit breakdown:
>   `docs/superpowers/specs/2026-07-23-platform-connect-async-design.md`
> - Frontend contract:
>   `docs/frontend-contracts/2026-07-23-platform-connect-async.md`
> - B0 status note added in place to the prior plan
>   (`docs/superpowers/plans/2026-07-20-platform-connect-async.md`).
>
> **Recommendation: route (c)** — reuse the shipped deferred-connect *mechanism*
> (`ConnectFetchJob`, the `pending: true` write, `connectFetchErrorMessage()`)
> inside the bespoke controllers, **without** migrating them onto the registry and
> **without** hand-rolling six copies of Instagram. All six of Josh's open
> questions are answered in §6 of the design doc; the merge/activation sequence is
> §7. Implementation is a separate orchestrated run and is **not gated on the
> frontend** — every unit merges dark behind `PARTNA_CONNECT_DEFERRED`.
>
> Two factual corrections this design forced on the brief below, recorded so they
> are not re-inherited: **(1)** `->deferredConnect()` has eight call sites, not zero
> (blocker #2 in "Read this first" is corrected); **(2)** there is no
> `deferredFailureMessage` — the real accessor is `connectFetchError()` /
> `connectFetchErrorMessage()` (`PlatformDescriptor.php:333-343`), which B1 route
> (a) below misnames.
>
> B0–B3 are retained below as the historical brief only.

Covers roadmap **#12**. **Produce documents, not code.** No controller is modified
in this run.

## B0 — Re-cost the existing plan

`docs/superpowers/plans/2026-07-20-platform-connect-async.md` is still marked
*"awaiting sign-off (2026-07-20)"*. Its Phase 1 (bounded fetch, `FetchBudget`) has
since shipped — `FetchBudget` exists at 20 s and is used by `ConnectResolver`,
`HighlightsPicker` and `YoutubeThumbnailResolver`. Its Phase 2 was written against
the registry path and does not account for the five bespoke controllers.

Read it, mark what has shipped, and state what is actually left.

## B1 — Resolve the architectural fork

The five heavy endpoints, with the review's worst-case inline timings:

| Endpoint | Site | Worst case |
|---|---|---|
| `POST /platforms/shop/brands` | `ShopController.php:108-115` — Shopify → Woo → Squarespace → generic, sequential | **~384 s** |
| `POST /platforms/apple/{music,podcast}/connect` | `AppleController.php:62` — two sequential iTunes lookups | ~192 s |
| `POST /platforms/fresha/selection`, `/employee-services` | `FreshaController.php:149-169, 205-216` | ~108 s |
| `POST /platforms/fresha/connect`, `GET /team` | `FreshaController.php:57-76, 130-137` | ~96 s |
| `POST /platforms/{eventbrite,humanitix,skool}/connect` | `EventbriteController.php:59-68` etc. | ~96 s |

Two routes, both viable:

- **(a) Migrate onto the registry.** Give each platform a descriptor calling
  `->deferredConnect()` plus a `deferredFailureMessage`, and route through
  `GenericPlatformController::connectDeferred()`. Higher up-front cost, but the
  `PARTNA_CONNECT_DEFERRED` env lever then works as designed — per-platform,
  per-environment, no deploy, and the same lever is the kill switch.
- **(b) Hand-roll async+poll per controller**, following
  `InstagramController::connect` (`:47`), which writes a `pending` placeholder,
  dispatches, returns **202**, and exposes `connectStatus` (`:122`). Lower risk per
  endpoint, five times the work, no shared kill switch.

Recommend one, with reasoning. Do not implement either.

**The constraint that shapes the answer:** in these connect paths the vendor fetch
*is* the validation — an empty `fetchRecentVideos()` is what produces
`fail('Could not find that YouTube channel', 404)`. Deferring `resolve()` wholesale
accepts any string, writes a row, and tells the user seconds later that what they
pasted was not real. Instagram works asynchronously because it does **not** have
this property: the handle is validated syntactically inline and the job does the
separable *content* fetch. Whatever you recommend must preserve inline validation
and defer only the separable fetch.

## B2 — Write the frontend contract

The frontend is a **separate repo** — read-only from here. Never clone, pull,
commit or push it.

Produce `docs/frontend-contracts/2026-07-23-platform-connect-async.md` covering,
per endpoint: the new `202` response shape, the status-poll endpoint and its
states, how a deferred *failure* surfaces the message that used to be a `422` body,
and the rollout/kill-switch lever. Model it on the existing
`docs/frontend-contracts/instagram-connect-async.md` and
`2026-07-02-async-link-connect.md`.

## B3 — Report and stop

Deliver: the re-costed plan, the fork recommendation, the frontend contract, and a
unit breakdown for a future implementation run. **Then stop.** Implementation is a
separate orchestrated run, gated on Josh's decision and on frontend availability.

---

## Completion

1. `COMPOSER_PROCESS_TIMEOUT=0 composer test` — green. (Part A only; Part B ships
   no code.)
2. `php artisan pint --dirty`.
3. Final whole-branch review on **Opus 4.8**, given the branch diff as a file. One
   fix subagent for the complete findings list, not one per finding.
4. Tick the `R3-SCALE-1` box in
   `audits/workers/2026-07-23-worker-async-review-TRIAGE.md` only if A2 was
   actually implemented — not if it stopped at the decision gate. Then run
   `scripts/audit/archive-done.sh audits/workers/`.
5. Report: units done, units gated with reason, test status, branch name. **Do not
   merge or push to `development`.**

---

## Reference

- Review: `docs/reviews/2026-07-23-worker-async-layer-review.md` §5a, §6, §8
- TRIAGE: `audits/workers/2026-07-23-worker-async-review-TRIAGE.md` (unit 10)
- Prior plan: `docs/superpowers/plans/2026-07-20-platform-connect-async.md`
- Pilot tier: `docs/superpowers/plans/2026-07-23-worker-async-pilot-PROMPT.md`
- Runbook: `scripts/audit/fix-flow.md`
