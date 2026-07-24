# PROMPT — Async platform connect: design & contract (NO CODE)

> Paste this whole file as the opening message of a fresh session in the
> Comet-Backend repo (`/Users/joshuahunter/Herd/Side Street/backend`).
>
> **This session writes documents, not code.** No branch, no worktree, no
> migrations, no test runs. Four markdown deliverables and a decision
> recommendation. It is parallel-safe against every other session in the repo
> because it touches nothing outside `docs/`.

---

## Mission

Roadmap item **#12** of `docs/reviews/2026-07-23-worker-async-layer-review.md` —
"async+poll the heavy platform connects" — cannot be implemented as specified.
Produce the design and contract that make it implementable, then stop.

Deliverables:

| # | Output | Where |
|---|---|---|
| B0 | Re-costed status of the prior plan | edit in place |
| B1 | Architectural fork recommendation | new design doc |
| B2 | Frontend contract | `docs/frontend-contracts/` |
| B3 | Unit breakdown for the future implementation run | in the design doc |

---

## Verified starting facts — do not re-derive these

All confirmed against the tree on 2026-07-23. Verify anything you intend to
contradict, but do not spend the session re-establishing them.

- **`ConnectResolver` is used by `GenericPlatformController` only.** No bespoke
  platform controller references it. The registry-driven connect path and the
  bespoke path are fully disjoint today.
- **`PlatformDescriptor.php:54` is `private bool $deferredConnect = false;` with
  zero call sites.** No descriptor invokes `->deferredConnect()`. Setting
  `PARTNA_CONNECT_DEFERRED` therefore changes nothing at present —
  `ConnectResolver.php:68` gates on `$descriptor->supportsDeferredConnect() && …`,
  and the first conjunct is universally false.
- **The review's platform list is incomplete.** It names Fresha, Shop, Apple,
  Eventbrite and Skool. **Humanitix has the same shape** and belongs in scope:
  six controllers, twelve endpoints.
- **`FetchBudget` shipped.** Phase 1 of the prior plan is done — a 20 s wall-clock
  budget exists and is used by `ConnectResolver`, `HighlightsPicker` and
  `YoutubeThumbnailResolver`. It is **opt-in per call site** and none of the six
  controllers below use it.
- **The frontend is a separate repo** (`github.com/hunterbalcombesykes/partna-frontend`),
  read-only from here. Never clone, pull, commit or push it. B2 is how the change
  gets communicated.

### The surface in scope

| Controller | Endpoints | Review's worst-case inline time |
|---|---|---|
| `ShopController` | `brands():98`, `selection():420` | **~384 s** (Shopify → Woo → Squarespace → generic, sequential) |
| `AppleController` | `connectMusic():63`, `connectPodcast():101` | ~192 s (two sequential iTunes lookups) |
| `FreshaController` | `connect():57`, `team():130`, `employeeServices():205`, `selection():223` | ~96–108 s |
| `EventbriteController` | `connect():59` | ~96 s |
| `HumanitixController` | `connect():58` | ~96 s |
| `SkoolController` | `connect():31`, `selection():52` | ~96 s |

Timings derive from `SafeUrlFetcher`'s **per-hop** timeout model: 8 s × up to 6
hops (`max_redirects` 5), doubled by the one-shot 403 alternate-UA retry at
`SafeUrlFetcher.php:101-114`.

### The exemplar

`InstagramController` is the proven pattern in this repo. Read it closely:

- `connect()` writes a `pending` placeholder (`:86-101`), dispatches (`:111`),
  returns **202** with `['status' => 'pending']` (`:116`).
- `connectStatus()` (`:122`) polls → `pending` / `ready` (with payload) / `failed`;
  **404 when no connection exists**.
- Two structural notes its comments call out explicitly, both of which your design
  must preserve: the cooldown guard runs **in the controller, not the job**
  (`:45`), and the job dispatch **must stay outside the row lock** (`:231-232`).

---

## The constraint that shapes the whole answer

**In these connect paths, the vendor fetch *is* the validation.**

An empty `fetchRecentVideos()` is what produces
`fail('Could not find that YouTube channel', 404)`. Deferring `resolve()`
wholesale accepts any string, writes a row, and tells the user seconds later that
what they pasted was not real — worse than today, and it writes rows for garbage.

Instagram works asynchronously because it does **not** have this property: the
handle is validated *syntactically* inline, and the job does the separable
*content* fetch.

Whatever you recommend must **preserve inline validation and defer only the
separable fetch**. A design that defers validation is wrong regardless of how
clean it looks. State per endpoint where that seam falls — for several of these,
finding the seam is the actual design work.

---

## Method

### Phase 1 — parallel read (fan out)

Dispatch **read-only** subagents concurrently, one per target. They edit nothing,
so they cannot collide. Use a cheap-to-mid model; each returns a structured note,
not prose.

| Agent | Reads | Returns |
|---|---|---|
| 1 | `ShopController` (+ `ShopProviderDetector`) | connect surface, where validation ends and fetch begins, what state a `pending` row would need |
| 2 | `AppleController` | same |
| 3 | `FreshaController` (4 endpoints — the widest) | same, plus how `selection`/`employeeServices` chain off `connect` |
| 4 | `EventbriteController` + `HumanitixController` + `SkoolController` | same, plus whether the three genuinely share one shape |
| 5 | `GenericPlatformController::connectDeferred()` + `ConnectResolver` + `PlatformDescriptor` | exactly what a descriptor must declare to use the deferred path, and what `connectDeferred()` already handles vs. what a caller still supplies |
| 6 | `InstagramController` + `ConnectFetchJob` + the two existing frontend contracts | the reference shape, verbatim: response bodies, status states, error surfacing |

Each agent must return **file:line citations**. A claim without one is not usable
in a design doc.

### Phase 2 — synthesis (do this yourself)

Do **not** delegate the recommendation. Six partial views need one mind holding
all of them; a subagent asked to "combine these" will average them instead of
deciding. Read the six notes, then write B0–B3.

---

## B0 — Re-cost the prior plan

`docs/superpowers/plans/2026-07-20-platform-connect-async.md` is still marked
*"Status: awaiting sign-off (2026-07-20)"*.

Its Phase 1 has shipped. Its Phase 2 was written against the registry path and
assumes the eight `FetchStrategy` platforms — it does **not** account for the six
bespoke controllers, which is why its "L not XL" reasoning ("the seam is already
cut… `Strategies/Fetch/` is the far side of it") does not transfer.

Edit that file in place: mark Phase 1 shipped with evidence, and add a status note
stating plainly that Phase 2's scope was mis-set and is superseded by the new
design doc. Do not delete its reasoning — the "validation *is* the fetch" argument
is the most valuable thing in it and carries forward intact.

---

## B1 — Resolve the architectural fork

Two routes. Recommend **one**, with reasoning, and be decisive — a survey is not
a deliverable.

**(a) Migrate the six onto the registry.** Each platform gets a descriptor calling
`->deferredConnect()` plus a `deferredFailureMessage`, routed through
`GenericPlatformController::connectDeferred()`. Higher up-front cost; in exchange
the `PARTNA_CONNECT_DEFERRED` lever works as designed — per-platform,
per-environment, no deploy, and the same lever is the kill switch.

**(b) Hand-roll async+poll per controller**, following `InstagramController`.
Lower risk per endpoint, roughly six times the work, no shared kill switch, and
six status endpoints to maintain instead of one.

Judge against, at minimum: whether the twelve endpoints actually share one shape
(Phase 1 agent 4 answers this); what `connectDeferred()` already handles that (b)
would reimplement six times; the rollback story if a platform misbehaves in
production; and whether `Fresha`'s four chained endpoints fit either model without
distortion.

Write it to `docs/superpowers/specs/2026-07-23-platform-connect-async-design.md`.

---

## B2 — Frontend contract

`docs/frontend-contracts/2026-07-23-platform-connect-async.md`. Model it on the two
existing contracts — `instagram-connect-async.md` and
`2026-07-02-async-link-connect.md` — and match their structure so the frontend
reads a familiar document.

Per endpoint, specify:

- The new **202** response body, exactly.
- The **status-poll endpoint** and its complete state set.
- **How a deferred failure surfaces the message that used to be a `422` body.**
  This is the part most likely to be got wrong: today the user gets
  "Could not find that YouTube channel" as a synchronous error body; after the
  change the request has already returned 202 before the fetch runs.
  `PlatformDescriptor`'s `deferredFailureMessage` exists for exactly this — see
  its docblock, which describes storing the verbatim string for the status
  endpoint to surface.
- The **rollout and kill-switch lever**, and what the frontend should do while a
  platform is *not* yet deferred (the contract must describe a mixed state, since
  rollout is per-platform).
- Any endpoint whose contract does **not** change, stated explicitly, so the
  frontend knows the blast radius exactly.

---

## B3 — Unit breakdown

Close the design doc with the work units a future orchestrated run would execute:
each unit's scope, files, effort, blocker-gate status per `scripts/audit/fix-flow.md`,
and dependencies between them. Enough that the implementation prompt is a short
document pointing at this one.

Flag explicitly which units are backend-only and shippable before the frontend is
ready — under route (a) the descriptor work is inert until
`PARTNA_CONNECT_DEFERRED` names the platform, which likely means a meaningful
first slice can land with zero contract change.

---

## Completion

Report: the recommendation in one paragraph, the four document paths, and the
open questions only Josh can settle (rollout order, whether Fresha's chained
endpoints are in the first slice, frontend timing).

Then **stop**. Implementation is a separate orchestrated run, gated on Josh's
decision here and on frontend availability. Do not begin it, and do not create a
branch in anticipation of it.

---

## Reference

- Review: `docs/reviews/2026-07-23-worker-async-layer-review.md` §5a
- Prior plan: `docs/superpowers/plans/2026-07-20-platform-connect-async.md`
- Exemplars: `docs/frontend-contracts/instagram-connect-async.md`,
  `2026-07-02-async-link-connect.md`
- Runbook (for B3's gate classification): `scripts/audit/fix-flow.md`
