# PROMPT — Phase 2: worker prerequisites (RV-4 memory · RV-8 RefreshController)

> Paste this whole file as the opening message of a fresh session in the
> Comet-Backend repo (`/Users/joshuahunter/Herd/Side Street/backend`).

---

## ⚠ Read first — do not double-run

RV-4 and RV-8 are **already units 17 and 18 of the pilot tier**
(`docs/superpowers/plans/2026-07-23-worker-async-pilot-PROMPT.md`, Step 2). This
prompt exists to run **only those two**, as the minimal slice that unblocks the
roadmap-#12 queue work (Phase 3), for the case where you want #12 moving before the
full pilot run.

**If the full pilot prompt is being run, do NOT run this too** — you would
implement RV-4/RV-8 twice on two branches and collide at merge. Pick one path:
either the full pilot (RV-4/RV-8 come along inside it) *or* this focused slice
first, then let the pilot run skip them. Whichever runs second must tick the boxes,
not re-implement.

## Where this sits

| Phase | Prompt | Ships |
|---|---|---|
| 0 | `…phase0-commit…` | design docs |
| 1 | `…phase1-fetchbudget…` | W1 (independent of this phase) |
| **2 — you are here** | this file | **RV-4 + RV-8** |
| 3 | `…phase3-implementation…` | W2–W8 (dark) |
| 4/5 | `…phase4-5-rollout-shop…` | activation + Shop |

**Why this gates Phase 3:** W2–W8 dispatch onto the `platform_connect` queue, adding
worker load to a box that is **already 25% over-committed** and OOM-killed on
2026-07-22. RV-4 restores headroom first. RV-8 is not a hard dependency of the
W-units (different endpoint) but is the review's highest-value fix, S-effort, in the
same subsystem — bundled here so the platform-fetch area is touched once.

## The two units

### RV-4 · Worker memory over-commit — **🔒 cost decision, blocker gate**

- **Where:** `config/horizon.php` supervisor blocks; the Laravel Cloud Worker cluster.
- **Fact:** permitted heap = `2×256 (supervisor-1) + 256 (supervisor-long) +
  512 (supervisor-videos) = 1280 MiB` on a **1024 MiB** `flex-1gb` box — a 25%
  over-commit before the Horizon master, three middlemen, and the scheduler that
  shares the instance. Horizon's `memory` is a restart-after-exceeded threshold
  checked *between* jobs, not a cap. An OOM kill means no `failed()`, orphaned locks,
  orphaned temp files. ffmpeg RSS is outside PHP's `memory_get_usage()` entirely.
- **This is Josh's call, not the implementer's.** Present **both** options with cost:
  (a) raise the Worker instance tier, or (b) lower `supervisor-videos.memory`.
  Produce the plan, the blast radius, and a recommendation, and **wait for explicit
  go-ahead** before any config change.
- **Cross-check:** confirm the arithmetic against the *current* `config/horizon.php`
  before quoting it — supervisor memory values may have moved since 2026-07-23.

### RV-8 · `RefreshController::refresh()` blocks inline — **🔒 contract change, blocker gate**

- **Where:** `app/Http/Controllers/Api/Platforms/RefreshController.php:40,76-82`.
- **Fact:** calls `PlatformRefresher::refresh()` inline, in a `foreach` over every
  connected row → worst case ~108 s **× row count** in one request.
  `RefreshConnectionJob` already exists and wraps this exact call for the cron
  dispatcher, with rate-limiting and queueing. Dispatch it per row instead.
- **Contract change:** the endpoint goes from a synchronous result to *accepted*.
  **Present the new response shape to Josh before implementing** — the frontend is a
  separate, read-only-from-here repo and must not break silently. If the async
  connect contract (`docs/frontend-contracts/2026-07-23-platform-connect-async.md`)
  is a useful precedent for the shape, follow its 202 conventions.
- Verify the premise: confirm `RefreshController::refresh()` still calls the inline
  `foreach` at those lines — it may have moved.

## Non-negotiables

Read `§ Non-negotiable rules` in the pilot prompt and obey it verbatim. Specifically:

- Branch `audit-fix/connect-worker-prereqs-2026-07-24` off `development`, dedicated
  worktree, own `composer install` + `.env`, no symlinked `vendor`.
- **Both units are gated** (RV-4 cost, RV-8 contract). Front-load: author *both*
  plans and present them as **one batched sign-off** before touching code.
- No Laravel migration files. Nothing here needs one.
- `COMPOSER_PROCESS_TIMEOUT=0 composer test`; never alongside a running implementer.
  Forbid `git stash` in every subagent prompt.
- Logs via `cloud env:logs partna development` only.

## Execution policy

Per `fix-flow.md`: **Plan Opus 4.8 · Implement Sonnet 4.6 · Review a separate
Sonnet 4.6 · final whole-branch review Opus 4.8.** Keep plan and implement separate
for both (they are gated). Specify the model on every dispatch.

## Completion

1. `COMPOSER_PROCESS_TIMEOUT=0 composer test` — green (RV-8's job-dispatch change is
   the only code; RV-4 is config + a dashboard action Josh performs).
2. `php artisan pint --dirty`.
3. Independent whole-branch review on **Opus 4.8**.
4. **Tick RV-4 and RV-8 in the pilot TRIAGE**
   (`audits/workers/2026-07-23-worker-async-review-TRIAGE.md`) so the pilot run does
   not redo them — this is the anti-double-run handshake. If the TRIAGE addendum
   from the pilot Step 1 does not yet exist, note that RV-4/RV-8 are done in your
   report and coordinate with whoever runs the pilot.
5. Report: units done or gated-pending-Josh, the RV-4 decision presented, the RV-8
   response shape presented, test status, branch name. **Do not merge or push.**

## Reference

- Pilot prompt (source of truth for RV-4/RV-8): `…2026-07-23-worker-async-pilot-PROMPT.md` §4
- Review: `docs/reviews/2026-07-23-worker-async-layer-review.md` §5a, §8 (#4, #8)
- Runbook: `scripts/audit/fix-flow.md`
