# PROMPT — Connect follow-ups: booking XOR · advisory-lock bound

> Paste this whole file as the opening message of a fresh session in the
> Comet-Backend repo (`/Users/joshuahunter/Herd/Side Street/backend`).

---

## Where this sits

Phase 3 (`2026-07-24-connect-phase3-implementation-PROMPT.md`) shipped W2–W7 and merged
as `2c88919c`; W9 Shop merged after it. Two items were **deliberately deferred out of
that run**, and neither has an owner:

| Unit | Why it was deferred | Effort | Gate |
|---|---|---|---|
| **U1** — Square side of the booking XOR | Josh's call: "separate unit after this run" | **S/M** | 🔒 **yes** |
| **U2** — bound the services advisory lock | explicitly escalated out of CA-W7, never picked up | **S/M** | no |

They are grouped here because both touch Fresha/booking and share the same test surface.
**They are independent** — U2 needs no sign-off and can ship alone if U1 stalls.

**A third item, `GET /api/platforms/fresha/team`, was considered and deliberately left out.**
It re-scrapes the salon page on every call, which is real but is already bounded to ~20 s by
W1's `FetchBudget` (the design doc's "~96 s" predates that). The intended fix — serve
`payload.teamMenu` and refresh in the background — **cannot work yet**: that snapshot is
written only by the deferred connect path (`FreshaConnectFetch.php:95`), and `fresha` is not
in `PARTNA_CONNECT_DEFERRED`, so no row has one. **Revisit it after Phase 4 activates
`fresha`**, not before. It remains open and unowned; do not treat this omission as a decision
that it is fine.

## Non-negotiables

- Read `CLAUDE.md` first. `scripts/audit/fix-flow.md` is the runbook; where they disagree, fix-flow wins.
- Branch `audit-fix/connect-followups-2026-07-25` off `development`, in a **dedicated
  worktree** with its **own** `composer install` and `.env` — do **not** symlink `vendor`
  or `.env`, that breaks feature tests.
- **Verify every line number below before acting.** They were correct on `development` at
  2026-07-25. Grep the symbol; do not trust the citation.
- **Run tests in the FOREGROUND.** Do not background a test run and wait for a
  notification — four agents did that during Phase 3 and lost over an hour between them.
- Full suite: `COMPOSER_PROCESS_TIMEOUT=0 composer test`. Never beside a running implementer.
- **Forbid `git stash` explicitly in every subagent prompt.** A foreign stash entry from
  another branch lives in this repo, and one Phase 3 agent breached this rule.
- `vendor/bin/pint --dirty` only (`php artisan pint` does not exist here).
- **No Laravel migration files.** None of these three needs a schema change.
- Pest loads every test file into one PHP process, so a bare `function foo()` in a test
  file is a **global** symbol — a helper collision broke the Phase 3 build once. Run
  `git grep -n "^function " -- tests/` before committing.
- **`DarkMergeProofTest` must stay green.** It is the merge-safety guarantee for the whole
  connect programme and it is mutation-tested. If a unit here makes it fail, you changed
  behaviour rather than fixing something.

## ⚠️ Read this before you trust a green suite

**CI on `development` was already RED when this prompt was written** — continuously since
at least 2026-07-24 08:31, across every commit. Two known causes, neither from these units:

1. **SQL-injection scan FAIL** — `app/Services/Analytics/RankedActionsComputer.php:132-133`,
   `selectRaw`/`groupByRaw` interpolating `{$day}` (from the demand-rate actions work).
2. **PHPStan, 18 errors** — `App\Models\Core\Site\ShopBrand::$connect_status`,
   `$connect_error`, `$brand_id`, `$url` and friends: W9's migration added columns and the
   model never got `@property` annotations.

**Establish the baseline before you start** and record it: run `composer test`,
`vendor/bin/phpstan analyse` and the SQL scan on the **unmodified** branch point, and write
down the counts. Otherwise you cannot tell your own regressions from the inherited ones.
**Do not fix either of the above here** — they belong to their own owners. If your work
makes the PHPStan count *worse*, that part is yours.

## Execution policy

Plan **Opus 4.8** · Implement **Sonnet 4.6** · Review a **separate** Sonnet 4.6 · final
whole-branch review **Opus 4.8**. Keep plan and implement separate for U1 (gated); U2 may
combine. **Front-load U1's plan** — author it and present it to Josh for sign-off before
dispatching its implementer; U2 can proceed meanwhile. Hand artifacts over as files, never
pasted diffs.

---

# U1 — close the symmetric Square/Fresha booking-XOR race

**A full unit spec already exists — read it first and treat it as the requirements:**
`docs/superpowers/plans/2026-07-24-square-fresha-xor-symmetric-race-UNIT.md`

Summary of what it establishes, re-verified on `development` 2026-07-25:

- A user may have **at most one** active booking provider (Fresha or Square).
- **`SquareController::connect()` takes no lock at all** — `:30` declares it, `:41` calls
  `hasConflictingConnection($user, Platform::Fresha->value)`, then it writes.
- **Every other booking writer takes `bookingXorLock`**: `BookingController:83,125`,
  `FreshaController::forget()`, `BuildsAutoSyncFindings:138,178`, and CA-W7's
  `FreshaConnectFetch`. Square is the sole writer outside the discipline.
- So the XOR rests on two unsynchronised `exists()` checks: A checks (no Square), B checks
  (no Fresha), both write, **both providers end up active** — the exact state the check
  exists to prevent. Binary invariant, no reconciliation path once violated.

**Two things the unit spec is emphatic about, because Phase 3 proved them:**

- This race is **pre-existing and NOT caused by the async work.** Deferral *narrows* the
  window (the pending row, which is what makes `hasConflictingConnection` fire, is written
  *before* the 202 instead of after a ~20 s scrape).
- CA-W7's lock exists for a **different** reason — the projector resurrecting rows
  tombstoned as `deleted_origin='sync'`. Do not conflate the two.

**Why this is gated:** `SquareController::connect()` would gain a **423** it has never
returned (frontend-visible); `FreshaConnectLockTest` pins the per-platform key and will
fail deliberately; and the PWL-16 register at `ManagesIntegrationConnection.php:~309-317`
names `square` among writers "deliberately left unlocked … nothing to race" — that entry
becomes false and must be removed with a note, exactly as `skool`'s was during CA-W4.

**Acceptance:** the unit spec's §7 criteria, verbatim. In particular criterion 1 — two
concurrent connects cannot both succeed, **proved by a test that interleaves them**, not by
inspection.

---

# U2 — bound the services advisory lock

**The problem.** `FreshaServiceProjector::sync()` takes an **unbounded** Postgres advisory
lock:

```
FreshaServiceProjector.php:129
DB::connection('pgsql')->select('select pg_advisory_xact_lock(hashtext(?))', ["services:{$user->id}"]);
```

`pg_advisory_xact_lock` waits **forever** for the lock. Before CA-W7 that ran inside a web
request; it now also runs **inside `ConnectFetchJob`**, held while the user may be editing
services in the dashboard.

**Five other callers contend on the identical key** — verify each before designing:

| Caller | Line |
|---|---|
| `StaffServiceManagementController` | `:113`, `:212` |
| `UserServiceController` | `:143`, `:377` |
| `Services/Site/InsertWithSortOrder` | takes the key as a parameter (manual-service create) |
| `FreshaServiceProjector::sync()` | `:129` — the projector itself |

CA-W7 deliberately left this unbounded and **escalated it rather than folding it in**: the
projector transaction is scrape-free, so realistic contention is a sub-second dashboard
stall, and the job's `timeout: 45` → `failed()` is a backstop. That reasoning is sound but
it leaves a job able to block a user's service edit for as long as Postgres will wait.

**The work.** Give the wait a bound — `SET LOCAL lock_timeout` before the advisory
acquisition is the approach CA-W7 named — and decide, with reasoning, what happens on
timeout **for each of the six call sites**. They are not alike: a user's interactive edit
should probably surface a retry, whereas the job must reach a **terminal** state (never
`release()`; under `SyncQueue` it is a silent no-op that strands the row `pending` forever).

**Three traps:**

1. **`SET LOCAL` only lasts the transaction.** If any call site acquires outside an explicit
   transaction, the setting does nothing. Check each.
2. **Tests run SQLite; this is Postgres-only.** `DB::connection('pgsql')->getDriverName()`
   returns `sqlite` under test — that is the documented seam for gating Postgres-only code.
   A green suite will **not** prove this works. State plainly how you gained confidence, and
   verify the statement against the real DDL/engine rather than the suite alone.
3. **Do not convert this to a Redis/cache lock.** The advisory lock's whole point is that it
   is transaction-scoped and released by Postgres on rollback or connection loss.

**Acceptance:** no call site can wait unboundedly; every timeout path is deliberate and, in
the job's case, terminal; `FreshaServiceProjectionTest`, `FreshaForgetXorLockTest` and the
service-management suites stay green.

---

## Completion

1. `COMPOSER_PROCESS_TIMEOUT=0 composer test` — green, and **compared against the baseline
   you recorded at the start**, not against an assumption of zero.
2. `vendor/bin/pint --dirty`; `vendor/bin/phpstan analyse` — **no worse** than the recorded
   baseline (it was already failing with 18 inherited errors).
3. Independent whole-branch review on **Opus 4.8**, diff handed over as a file; **one** fix
   subagent for the complete findings list, not one per finding.
4. `DarkMergeProofTest` still green.
5. Report: units done / gated / blocked with reason, the before-and-after baseline numbers,
   the branch name, and any contract decision Josh made. **Do not merge or push without his
   say-so.**

## Reference

- U1 spec: `docs/superpowers/plans/2026-07-24-square-fresha-xor-symmetric-race-UNIT.md`
- Residual list: `docs/superpowers/plans/2026-07-25-connect-residual-cleanup-PROMPT.md`
  **Note for whoever runs its R3** (strip `connectMode`/`teamMenu` from completed rows): those
  keys are the groundwork a future `team()` fix needs. Stripping them is defensible, but do it
  knowingly and say so — do not delete them as dead weight.
- Design + its seven implementation-proved corrections: `docs/superpowers/specs/2026-07-23-platform-connect-async-design.md`
- Contract: `docs/frontend-contracts/2026-07-23-platform-connect-async.md`
- Runbook: `scripts/audit/fix-flow.md`
