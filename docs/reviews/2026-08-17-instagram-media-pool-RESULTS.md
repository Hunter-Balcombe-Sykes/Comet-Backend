# RESULTS — Instagram pre-account builds: does the scraped media ever reach a surface?

**Date:** 2026-08-17 · **Env:** dev Supabase `glncumufgaqcmqhzwrxm` · **Branch:** `investigate/ig-media-pool-2026-08-17`
**Phase 0 only. No code changed. No paid scrape spent.**

---

## 1. The verdict — **C, it is not wired**

**No. An Instagram pre-account build's scraped media can never reach the media pool on its own, and
this is not a latency problem — it is a closed trigger chain.** An `instagram` ingest source is
provisioned `auto_sync = false` by construction, and the only code path that can start an ingest run
filters on `auto_sync = true`. Nothing else in the codebase can start one. The three Instagram runs
that have ever existed in dev were all started by a human.

The verdict is established by **code-path proof**, not by inference from timing. Each link was
verified by grep across `app/` and `routes/`:

| # | Link | Evidence |
|---|------|----------|
| 1 | Instagram is a paid connector | `InstagramConnector.php:69` → `cost: CostClass::Actor` |
| 2 | Paid ⇒ not schedulable | `SourceProvisioner.php:147-150` → `schedulable() { return $manifest->cost === CostClass::Free; }` |
| 3 | Source is inserted switched off | `SourceProvisioner.php:94` → `'auto_sync' => self::schedulable($manifest)` → **`false`** |
| 4 | The scheduler cannot see it | `SourceScheduler.php:85-91` → `scoreDue()` has `->where('auto_sync', true)` |
| 5 | `claimDue()` has exactly **one** caller | `IngestDispatchCommand.php:37` |
| 6 | `RunSourceJob` has exactly **one** dispatcher | `IngestDispatchCommand.php:44,46` |
| 7 | `RunExecutor::execute()` has exactly **one** caller | `RunSourceJob.php:83` |

∴ `ingest:dispatch` → `claimDue()` → `RunSourceJob` → `RunExecutor` is the **whole** trigger surface,
and step 4 excludes Instagram from it permanently. There is no controller, service, job, observer or
command that bypasses it.

### The design intended a manual lane. It was never built.

`InstagramConnector`'s own docblock states the design:

> *"MANUAL-ONLY by construction, not by convention: CostClass::Actor means SourceProvisioner provisions
> the source with auto_sync=false (the scheduler never touches it), so **a run happens only on an
> explicit manual/connect trigger**."*

**That trigger does not exist.** There is no `ingest:run`/`ingest:source` command (the only ingest
commands are `dispatch`, `stranded`, `effects`, `project`, `anomalies`, `backfill-sources`), and no
connect-time dispatch of `RunSourceJob` from any controller, service or observer. So the connector is
correct that it is manual-only, and wrong that a manual trigger is available. **That gap is the
defect** — not the projector, not the pool plumbing, not the pre-account path.

### Row-level evidence

Every `instagram` ingest source in dev, with its runs and resulting media items:

| handle | status | identifier | `auto_sync` | source created | runs | media items |
|---|---|---|---|---|---|---|
| `business1` | unclaimed | `basette_barberia_` | **true** ⚠ | 2026-07-31 03:41 | 2 | **12** |
| `tobiasbalcombe` | unclaimed | `tobiasbalcombe` | false | 2026-08-12 10:48 | 1 | 0 |
| `ollies` | active | `the_046_official` | false | 2026-08-13 13:55 | **0** | 0 (from IG) |
| `ollies` | active | `st_ali` | false | 2026-08-16 22:59 | **0** | 0 (from IG) |
| `broken-oven` | active | `brokenoar` | false | 2026-08-17 12:16 | **0** | 0 (from IG) |

Three sources created in the last five days have **zero** runs. All three Instagram runs that have
ever existed fired inside a **four-minute window** — 15:45:02, 15:47:55, 15:49:15 on 2026-08-12 —
which is an operator session's fingerprint, not a 15-minute scheduler's.

`business1` is the sole exception in every respect, and it is the row that misled the earlier
investigation. Its `auto_sync = true` **cannot be produced by `SourceProvisioner`**: the insert writes
`schedulable()` (false), and the only branch that raises the flag (`SourceProvisioner.php:117-119`)
requires `schedulable()` to be true. Its cost class has been `Actor` since the connector's first
commit (`c03830d3a`, 2026-07-28), so there was never a Free era to inherit. The flag was set outside
the application — almost certainly by hand in the same 2026-08-12 session that ran the three runs.

### Correcting the prompt's fact 6

Fact 6 read `business1`'s 12 media items as proof the media pool "CAN be populated for an unclaimed
account". True, but it silently implied the ingest lane populates media pools generally. It does not.
Checking provenance via `content.source_items → content.sources`:

- `ollies` — 11 media items, all from a **`manual`** source (`projector_version: 0`). Hand-uploaded.
- `broken-oven` — 4 media items, likewise all from a **`manual`** source.
- `business1` — 12 media items, from a `connection`/`instagram` source with a real `stream_id` and
  `projector_version: 1`. A genuine ingest projection.

So `business1` is not one example among several. It is **the only Instagram-derived media in the
entire dev database**, produced on one day, by one hand-triggered run. `InstagramMediaProjector` works
— that part of fact 6 stands, and it matters for the fix — but nothing about it is automatic.

---

## 2. True build → media-on-page latency

**Unbounded. There is no latency to measure, because the event never occurs unattended.**

The only observed Instagram connection→media transition:

- `business1` connection created **2026-07-31 03:41:06**
- its 12 `content.items` created **2026-08-12 15:48:20**
- elapsed: **12 days 12 hours** — and it happened only because a human intervened on day 12.

For the pre-account path specifically the measured figure is: `tobiasbalcombe` built 2026-08-12
10:47:42, **5 days elapsed, 0 items**, and its source's `next_attempt_at` of 2026-08-19 is a **decoy** —
`SourceScheduler::release()` stamps that column on every run, but `scoreDue()` will never read the row
back because of `auto_sync = false`.

**This corrects the prompt's fact 7.** "The ingest run is asynchronous and SLOW to arrive (~5 hours)"
is wrong. The 5-hour gap on `tobiasbalcombe` was not scheduler latency; it was the interval until a
person ran something. Waiting longer would not have helped, which also means **the 08-17 wave's
5-minute measurement window was not the reason it saw nothing.** It would have seen nothing at T+24h,
or ever.

---

## 3. The `auto_sync: false` contradiction — resolved

**There is no contradiction. `auto_sync: false` is correct, deliberate, and permanent for Instagram.**

`auto_sync` does not mean "this source is healthy" or "this source will be scheduled eventually". It
means exactly one thing: **"this connector is free to run"** (`schedulable()` = `cost === Free`). Paid
connectors are deliberately parked off the dispatcher, per that method's docblock: *"enabling paid
auto-sync is a spend decision that belongs to the slice which uses the data, not to the seam that
makes it possible."*

**Does an IG source ever re-run after its first pass? No — and it never ran a first pass either.**
Nothing in the application will ever run it.

The `trigger: 'schedule'` label that made the run look scheduler-driven is a **red herring**: it is the
default parameter value of `RunExecutor::execute(..., string $trigger = 'schedule')`, hardcoded at the
single call site `RunSourceJob.php:83`. It records nothing about who initiated the run. Any run,
however started, is stamped `schedule`.

### This is systemic, not Instagram-specific

`schedulable()` admits only `CostClass::Free`, so **seven connectors are provisioned unreachable**:

| Cost class | Connectors |
|---|---|
| `Actor` | `instagram`, `doordash`, `uber_eats`, `square`, `spotify`, `soundcloud` |
| `Metered` | `google_business` |

The data matches: `square` has 1 source / 0 runs; `google_business` 15 sources / 5 runs (all inside
2026-08-12→14); `doordash` and `uber_eats` 4 runs each, all inside one night window on 2026-08-15/16.
Every paid connector's run history is a handful of clustered, human-initiated bursts. Whether that is
acceptable is a spend decision, but it should be a **stated** one — today it reads as a working
pipeline.

---

## 4. Does the ingest lane cost a second Apify scrape? — **Yes. Saying so loudly.**

**One Instagram signup buys two paid scrapes of the same profile**, whenever an ingest run does happen.

`ingest.effects` holds two `kind: 'actor'`, `cost_tag: 'instagram'` rows, **50 cost units each**, both
`status: ok`, both settled — one per source, distinct digests. These are separate billed calls from
the build-time `InstagramConnectionSeeder` scrape, which runs through `InstagramScraper` on a different
path with its own result (`payload._mediaDiagnostics`).

For `tobiasbalcombe` the two calls were **~5 hours apart on the same username**:

| | when | mechanism | posts seen |
|---|---|---|---|
| build-time scrape | 2026-08-12 10:47 | `InstagramConnectionSeeder` → `InstagramScraper` | 0 |
| ingest actor effect | 2026-08-12 15:45 | `InstagramActorDriver`, 50 units, `status: ok` | 0 |

Today the double spend is latent, because ingest runs essentially never fire. **Any fix that makes IG
ingest automatic converts this into a real, recurring, per-signup double charge.** It must be resolved
as part of that change, not after it.

### Two dead columns found in passing

`ingest.runs.effects_count` and `ingest.runs.cost_claimed` are **never written anywhere in `app/`** —
both are permanently 0, which is why `tobiasbalcombe`'s run reports `effects_count: 0` while its
`ingest.effects` row shows 50 units spent.

`cost_claimed` is not merely cosmetic: `SourceScheduler::scoreDue()` (line 78-82, 99) reads it as the
per-user fairness denominator `(1 + $spent / 100)`. Permanently 0 makes that term permanently 1, so the
mechanism its docblock describes — *"what stops one expensive user monopolising the lane"* — **is
inert**. Not triggered today (paid sources never reach the scheduler anyway), but it would matter the
moment any paid connector is switched on. **Reported, not fixed** — out of scope for this task.

---

## 5. What the 08-17 wave got wrong, and why this must not be re-raised a fourth time

The wave's conclusion ("never surfaces its media") was **right by accident**; its reasoning was wrong,
which is why the follow-up investigation was able to talk itself out of a true finding.

| # | The mistake | The correction |
|---|---|---|
| 1 | Read a 5-minute measurement window as the flaw | The window was irrelevant. T+24h or T+∞ gives the same result. |
| 2 | Read the ~5-hour gap on `tobiasbalcombe` as scheduler latency (fact 7) | It was the delay until a **person** acted. `auto_sync=false` bars the scheduler outright. |
| 3 | Read `next_attempt_at = 2026-08-19` as "it will re-run then" | A decoy column. `release()` writes it; `scoreDue()` never reads that row back. |
| 4 | Read `trigger: 'schedule'` as "the scheduler ran it" | A hardcoded default parameter. It records nothing about the initiator. |
| 5 | Read `business1`'s 12 items as "the lane works for unclaimed users" (fact 6) | Only its **projector** works. Its run was hand-started and its `auto_sync=true` is hand-set — unreproducible by any code path. |
| 6 | Assumed `tobiasbalcombe`'s build-time scrape saw 12 posts, so `no_posts` was contamination | **Its build-time scrape recorded `posts: 0` too.** Fact 3's "12 posts" was the 08-17 wave, not this account. |

**Correction 6 matters most for closing the question.** The prompt set `tobiasbalcombe` aside as
"contaminated by `no_posts`" and therefore unable to distinguish broken wiring from an empty scrape.
But its two *independent, separately billed* scrapes, five hours apart, **agree on zero posts**. That
is a consistent reading of an empty, private or unavailable account — not actor flakiness (per
`reference_actor_row_arrived_but_carries_no_payload`, agreement across independent calls is the
signature of a real wall, not a flaky one). So the one long-lived data point is **not** contaminated;
it simply never had media to carry, and it was never the evidence the question turned on. The code path
was.

**The durable statement, so nobody re-derives this:** an `instagram` ingest source is created
`auto_sync = false` because `CostClass::Actor` is not `CostClass::Free`, and `SourceScheduler::scoreDue()`
selects only `auto_sync = true`. `IngestDispatchCommand` is the sole dispatcher. Therefore Instagram
ingest is **unreachable by any automatic path**, and no amount of waiting changes it.

---

## 6. What I deliberately did not do

- **Did not spend the Phase 0.2 experiment.** It is gated on 0.1 leaving the question open; 0.1 closed
  it by code-path proof. A new build would have cost a paid scrape (probably two, per §4) to
  re-demonstrate what four grep results already establish, and — because the trigger chain is closed —
  it would have produced 0 media items at T+24h regardless of how many posts the account had. **No cap
  slot consumed, no `core.pre_account_builds` row created, nothing to clean up.**
- **Did not implement a fix.** Phase 1's blocker gate applies on three counts: paid third-party scrape
  path, the public wire, and — decisively — the prompt's own instruction that changing *when* runs fire
  is *"a product decision — put it to Josh in the plan, do not just make it."* That is exactly the
  decision this verdict lands on. Options in §7; awaiting sign-off.
- **Did not fix the `cost_claimed` / `effects_count` dead columns** (§4). Real, but a different defect
  in a different subsystem.
- **Did not touch `designMedia`, `profile.gallery`, `profile.curatedGallery` or `siteImages`.** Nothing
  in this investigation went near them.
- **Left `tobiasbalcombe` live and unmodified.** No rows written to dev in the course of this work —
  every query was read-only.

---

## 7. The decision for Josh — how should a paid IG source ever run?

The fix is not a bug fix; it is choosing a trigger policy. Each option is a different spend profile.
**No work proceeds until one is picked.**

| | Option | What it costs | Notes |
|---|---|---|---|
| **A** | **Manual trigger only** — build the missing `ingest:run --source=<id>` command | Nothing automatic; a scrape per deliberate invocation | Smallest change. Makes the connector's documented design *true* rather than aspirational. Leaves pre-account builds with no media unless someone runs it — i.e. this exact question recurs, just answered honestly. |
| **B** | **Eager first run on connect, once** | Exactly one extra scrape per IG signup — **this is the §4 double charge made real and recurring** | Gives pre-account builds their media within minutes. Should be paired with reusing the build-time scrape (see below) or it pays twice for the same data. |
| **C** | **Let the scheduler run paid sources under a budget cap** | Recurring, ~50 units per source per interval (currently 7 days) | Widest blast radius: flips all seven paid connectors at once unless gated per-connector. Requires `cost_claimed` to actually be written first (§4), or the fairness limiter stays inert. |

**My recommendation: A now, then B — but B only together with removing the double scrape.** The
build-time seeder already fetches the full profile (12 posts for a healthy account). The cheapest
correct shape is for the ingest lane to *reuse that result* rather than re-buy it, which turns B's cost
from "a second scrape per signup" into "no additional spend". That is a real piece of design work
(the two paths have separate ledgers and separate result shapes), and it belongs in the Phase 1 plan
rather than being assumed here.

**C should not be chosen as a side effect of fixing Instagram** — it changes spend for six other
connectors, and its own fairness guard is currently inert.

---

## Appendix — verification method

- All figures read from dev `glncumufgaqcmqhzwrxm` on 2026-08-17, read-only (`execute_sql`), never prod
  (prod lacks `content`/`ingest`/`routing`/`catalog` entirely).
- Joins respected the documented traps: `ingest.runs.source_id → ingest.sources`;
  `content.source_items.source_id → content.sources`. Item provenance was resolved through
  `content.source_items`, since `content.items` carries no `source_id` column.
- Trigger-chain claims were established by exhaustive grep for `claimDue`, `RunSourceJob`, `RunExecutor`
  and `->execute(` across `app/` and `routes/`, and by enumerating every `ingest:*` command signature —
  not by reading any single file's docblock. Where a docblock and the code disagreed
  (`InstagramConnector`'s "manual/connect trigger"), **the code was taken as truth and the docblock
  recorded as wrong.**
- `cost: CostClass::` was enumerated across all 16 connectors rather than assumed from Instagram's.
- Cost-class history checked with `git log -L` on the manifest line: `Actor` since first commit, so no
  Free-era inheritance could explain `business1`'s `auto_sync = true`.
