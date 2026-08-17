# PLAN — Option B: eager ingest run on connect (Instagram)

**Status:** awaiting sign-off (Phase 1 blocker gate — paid third-party scrape path + public wire)
**Decision taken:** Josh chose **B — eager run on connect**, 2026-08-17, over the recommended "A then B
without the double scrape". Proceeding with B as chosen.
**Prereq reading:** `docs/reviews/2026-08-17-instagram-media-pool-RESULTS.md` (verdict C).
**Branch at implementation:** `audit-fix/ig-media-pool-2026-08-17` off `development`.
(The verdict doc currently sits on `investigate/ig-media-pool-2026-08-17`.)

---

## 1. What is being fixed

An `instagram` ingest source is provisioned `auto_sync = false` (`CostClass::Actor` is not
`CostClass::Free`), and `SourceScheduler::scoreDue()` selects only `auto_sync = true`. Since
`IngestDispatchCommand` is the sole dispatcher of `RunSourceJob`, **an Instagram source can never run**.
The connector's documented "manual/connect trigger" does not exist.

**This plan builds the connect trigger.** It does not widen `schedulable()`, does not touch the
scheduler's selection predicate, and does not make paid sources recurring.

## 2. Design

### 2.1 The seam already exists and is currently discarded

`SourceProvisioner::sync()` returns `['status' => 'created'|'updated'|'unchanged'|'skipped'|…]`.
`IntegrationConnectionObserver::syncIngestSource()` (`app/Observers/Core/IntegrationConnectionObserver.php:226`)
throws that return value away. **That is the hook.** On `status === 'created'` — and only then — dispatch
one eager run.

`created` (not `updated`) is load-bearing: `syncIngestSource()` is called from `saved()`, `restored()`
and the payload-change path. Firing on anything but first creation would buy a paid scrape on **every
payload write**, which for Instagram is every refresh.

The observer already sets `public bool $afterCommit = true`, so the dispatch lands post-commit for free.

### 2.2 Containment: eager is opt-in per connector, default OFF

An unqualified "eager on connect" would silently start spending on all seven paid connectors
(`instagram`, `doordash`, `uber_eats`, `square`, `spotify`, `soundcloud`, `google_business`). It must not.

Add `eagerOnConnect: bool = false` to `App\Ingest\Manifest\Manifest`, and set it `true` **only** in
`InstagramConnector::manifest()`. A future connector opts in with one line, visibly, in review.

> Deliberately a manifest field rather than a config key: cost class already lives on the manifest, so
> the "what does this connector cost / when may it run" decision stays in one place. It is also
> statically greppable, which a config value is not.

### 2.3 The claim — a real hazard, not a formality

`RunSourceJob::handle()` does **not** claim its source. `SourceScheduler::claimDue()` claims *before*
dispatching, so the claim and the job are separate steps today. An eager dispatch that skips the claim
would let a scheduler tick (for a source that is auto-synced) or a second connect event run the same
source concurrently — and for a billed connector that means **paying twice**.

Add `SourceScheduler::claimOne(string $sourceId, string $runId): bool` — the *same* conditional
`UPDATE … WHERE in_flight_since IS NULL` that `claimDue()` already uses, for one known id. Reusing that
one mechanism is required by the class's own docblock ("ONE mechanism, not a second queue-level lock").
Dispatch only when it returns `true`.

`RunSourceJob`'s existing `finally` already releases the claim, so the release path is unchanged.

### 2.4 Budget

No new budget code. `InstagramActorDriver` claims `ApifyBudget` (per-actor + global daily caps,
`config partna.limits.apify`) immediately before the run, and `InstagramConnector::pull()` folds a
refused claim into `Unavailable`. A signup burst is therefore already capped, and the eager path
inherits that protection unchanged. **This is the reason B is safe to ship without a new rate limiter** —
worth stating explicitly so nobody adds a redundant one.

### 2.5 Cache — nothing to do, and nothing to collide with

`ProjectionWriter::projectStream()` already fires **all three lanes** via `invalidateSiteLanes()`
(line 258), and its docblock names Instagram. The lane-2 (`site.sites.updated_at`) defect recorded in
`CLAUDE.md` belongs to the *other* callers — `PoolItemCreateController::pin()`, `ItemController::destroy()`,
`ItemLinkController::upsert()/destroy()` — via `bumpSite()`. **That is a different fix-flow session's
unit. This plan does not touch `ProjectionWriter`, `bumpSite()` or those controllers.**

Consequence: no `tests/Postgres/` obligation from the `ProjectionWriter` rule — but see §4 for a
Postgres test we need anyway, for the claim.

### 2.6 Unclaimed users

Pre-account users are `status = 'unclaimed'` with **no email**. The eager run touches no notification
path — but `PlatformHealthNotifier` must be confirmed not to fire on a failed eager run for a user with
`routeNotificationForMail() === null`. **Verification step, not a code change** (§4.6). If it does fire,
that becomes a blocking sub-finding and comes back for sign-off before I work around it.

## 3. The cost, stated plainly

**Every Instagram signup will buy two paid Apify scrapes of the same profile:**

| | when | mechanism | ~cost |
|---|---|---|---|
| 1 | build time | `InstagramConnectionSeeder` → `InstagramScraper` | 1 scrape |
| 2 | ~immediately after | eager `RunSourceJob` → `InstagramActorDriver` | 50 units |

This is inherent to B as chosen and is **not** a defect in the implementation. Capped by `ApifyBudget`.
The cheaper shape (reusing the build-time result) was option "A then B" and was not selected.

⚠️ **The second charge will not be visible in `ingest.runs`.** `cost_claimed` and `effects_count` are
never written anywhere in `app/` (see RESULTS §4), so run rows will keep reporting `0` while
`ingest.effects` carries the real 50 units. **Optional unit U4 below fixes that.** I am not folding it in
silently — it is a separate defect in a different subsystem — but shipping B without it means the new
recurring spend is invisible in the most obvious place to look for it.

## 4. Work units

Sequential. U1–U3 are the fix; U4 is optional and needs its own yes/no.

| Unit | Change | Files |
|---|---|---|
| **U1** | `eagerOnConnect` on `Manifest` (default `false`); `true` in `InstagramConnector` | `app/Ingest/Manifest/Manifest.php`, `app/Ingest/Connectors/InstagramConnector.php` |
| **U2** | `SourceScheduler::claimOne()` | `app/Ingest/Runtime/SourceScheduler.php` |
| **U3** | Observer fires eager dispatch on `status === 'created'` + `eagerOnConnect` + `claimOne()` | `app/Observers/Core/IntegrationConnectionObserver.php` |
| **U4** *(optional)* | Write `effects_count` + `cost_claimed` on run rows | `app/Ingest/Runtime/RunExecutor.php` |

### Tests — all mutation-verified (fail before, pass after; not merely green)

1. **Pre-account IG build dispatches exactly one `RunSourceJob`** (`Bus::fake`), and the dispatched id
   is the newly created `ingest.sources` row. — `tests/Feature/PreAccount/`
2. **A Free connector does NOT get an eager dispatch** (the scheduler owns it) — guards §2.2 containment.
3. **A payload update on an existing connection dispatches nothing** — guards the `created`-only gate,
   i.e. the every-refresh double-charge regression.
4. **A paid connector without `eagerOnConnect` dispatches nothing** — guards the default-off.
5. **`claimOne()` is exclusive under concurrency** — `tests/Postgres/`, **not** SQLite. SQLite's
   concurrency semantics do not exercise the conditional-UPDATE race
   (`reference_sqlite_passes_what_postgres_rejects`, `project_claim_concurrency_pg_lane_shipped`).
   Must pin the *refusal reason*, not just a count
   (`reference_concurrency_test_must_pin_refusal_reason`).
6. **Verification (not a test):** confirm `PlatformHealthNotifier` does not fire for an `unclaimed`,
   email-less user on a failed eager run.

Run: `composer test` (targeted first), plus `composer test:pg` **because of test 5** — not because of
`ProjectionWriter`.

## 5. Explicitly out of scope

- Widening `schedulable()` / letting the scheduler run paid sources (that was option C).
- The missing `ingest:run --source=` manual command (that was option A).
- `bumpSite()`'s lane-2 defect and its controllers — **another session owns it**.
- Reusing the build-time scrape to remove the double charge — not selected.
- `designMedia`, `profile.gallery`, `profile.curatedGallery`, `siteImages` — deleted by slice 7 unit E;
  reintroducing any is an automatic reject.
- Prod. Prod lacks the `content`, `ingest`, `routing` and `catalog` schemas entirely.

## 6. Risks

| Risk | Mitigation |
|---|---|
| Eager dispatch fires on every payload write → scrape per refresh | `status === 'created'` gate; test 3 |
| Spend switched on for the other six paid connectors | `eagerOnConnect` default `false`; test 4 |
| Concurrent run double-charges Apify | `claimOne()` conditional UPDATE; Postgres test 5 |
| Signup burst drains Apify budget | Pre-existing `ApifyBudget` per-actor + daily caps |
| A failed eager run breaks the build | `syncIngestSource()` is already best-effort try/catch; dispatch goes inside it |
| New spend invisible in `ingest.runs` | Optional U4 — **needs your call** |

## 7. Sign-off needed

1. **Approve U1–U3 as specified?**
2. **Include optional U4** (write `effects_count`/`cost_claimed`) so the new per-signup spend is visible
   in run rows — or leave it to a separate unit?
