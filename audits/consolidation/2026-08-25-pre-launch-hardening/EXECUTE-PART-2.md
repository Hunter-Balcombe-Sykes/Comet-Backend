# EXECUTE — Pre-launch hardening PART 2 of 3: surfaces & wire · UNATTENDED

**Run this by saying:** `execute audit audits/consolidation/2026-08-25-pre-launch-hardening/EXECUTE-PART-2.md`

**This file is written for an UNATTENDED overnight run. Josh is asleep. Nobody will answer a
question.** Every decision this part needs has been made in advance and is written inline as a
`**DECIDED:**` line. The one authorization gate is discharged in §1.1. §1 is the no-stop policy;
read it before §4.

**Six units, ~16 defects.** These touch staff-facing surfaces, one authorization hardening, and a
long tail of small items. Two findings are **pre-deferred by decision** (§4 units 12 and 14) — read
those before starting them.
Sourced from `audits/consolidation/2026-08-25-pre-pilot-p2-promotion/BACKLOG-TRIAGE.md`, verified
against `development` at `d52a604c5`.

Follow `scripts/audit/fix-flow.md` for the per-unit plan → implement → **independent** review loop.
**Where this file and `fix-flow.md` disagree, THIS FILE WINS** — including on §1a's blocker gate,
which §1.1 explicitly discharges.

> **Unit numbers are the ORIGINAL ones** from the undivided tranche (6, 8, 9, 10, 12, 14), kept so
> cross-references to `BACKLOG-TRIAGE.md` and the source sweeps still resolve. Units 2, 3, 4, 5, 7, 11
> are PART 1; unit 13 is PART 3; unit 1 was deferred out and has since **shipped** on 2026-08-26
> (`3c88c5925`..`c529f16ac`) — nothing in this file touches it.

---

## 0. Run order and git state — READ FIRST

**This part runs FOURTH overall.** Required order:

1. `../2026-08-25-pre-pilot-blockers/EXECUTE.md`
2. `EXECUTE-PART-1.md`
3. **This file** — PART 2
4. `EXECUTE-PART-3.md`

**All three PARTs share ONE branch:** `audit-fix/pre-launch-hardening-2026-08-25`.

- If PART 1 ran, that branch exists — `git checkout` it and continue. **Do not create a second branch
  and do not rebase it.**
- If it does not exist (PART 1 was skipped), create it off freshly-pulled `development` and note the
  skip in §7.
- **Read `RESULT-PART-1.md` if it exists** — its handoff line names the files PART 1 changed, which is
  how you spot a conflict before making one.

**Never run two PARTs concurrently in the same checkout.** A peer session switching the branch
mid-task silently destroys work. Genuine concurrency would need a `git worktree` with a **real
(non-symlinked) `vendor/` and `.env`** — a symlinked vendor makes every Feature test fail to
bootstrap. Out of scope: **run sequentially.**

Before starting: `git worktree list` and `git status`.

## 0b. Execution policy

- **Plan:** Opus 5 · **Implement:** Sonnet 5 · **Review:** Sonnet 5 — a *separate, independent*
  instance, never the implementer.
- **Combine plan+impl:** YES for S units · **NO for units 6 and 12** (M — plan and implement as
  separate instances, but do not wait between them).
- No per-item model escalation in this part.

---

## 1. NO-STOP POLICY — read this before anything else

### 1.1 The one gate in this part is DISCHARGED

`fix-flow.md` §1a would pause for sign-off on unit 9 (`#SEC-10`, authorization). **Josh
pre-authorised it on 2026-08-25, in writing, before this run.** Do not pause for it.

**Why it is safe to pre-authorise:** it is defence-in-depth on five methods that are *already*
structurally user-scoped, the pattern being copied lives in the same file, and the change adds a
second lock rather than relaxing a first. It cannot widen access.

**There is no other gate in this part.** If you find yourself composing a question for Josh, that is
a DEFER (§1.2), not a pause.

### 1.2 The DEFER lane — how a large issue exits without stopping the run

**Defer, never escalate, never grind.** Deferring is a successful outcome, not a failure.

**DEFER a unit the moment ANY of these is true:**

1. The fix would need a **schema change** (a `supabase/migrations/` file).
2. The fix would **break** the public, dashboard or staff wire — a **removed** field, a **changed**
   status code on a success path, or a **new required** request key. ⚠️ **Purely ADDITIVE response
   fields are NOT a break** and are explicitly permitted (unit 6 relies on this).
3. The unit turns out to be **L or XL** once the code is read.
4. The **independent review fails twice.** No third attempt, ever.
5. **Three implementation attempts** have not produced a green targeted test run.
6. The fix requires a **live third-party call, a real credential, or a paid API budget** to verify.
7. The fix would **delete or hard-expire user or third-party PII** on a new schedule (see unit 14's
   `#PRIV-2`, which is pre-deferred for exactly this reason).
8. It would require editing a file another unit in this run — **or PART 1** — has already changed in a
   conflicting way, and reconciling them is not obvious.

**What DEFER looks like — do all five, then move on:**

1. Write `DEFERRED-part2-unit-<n>-<slug>.md` beside this file: finding IDs, what you found, the DEFER
   trigger number, the plan you had reached, and the concrete next step for a human. **A deferral with
   no written plan is a dropped ball, not a deferral.**
2. Leave the source audit checkboxes **unticked**.
3. `git checkout -- <paths>` any half-finished edit **for that unit only** — never a tree-wide reset.
4. Record it in §7's ledger as `DEFERRED — <trigger>`.
5. **Go to the next unit immediately.** Do not revisit.

### 1.3 Nothing else halts the run — including a red baseline

- **A red suite at baseline is NOT a stop.** Record the failing tests and the commit that broke them,
  carry on.
- **A refuted premise** → close per §5, tick `WONTFIX — premise refuted`, move on. Expect several.
- **Anything out of scope** → §7 "Surfaced, not worked". Do not chase it.
- Units are independent.

**The ONLY conditions that end the run early** (write `RESULT-PART-2.md` first regardless):
`git fetch`/`pull` unrecoverable · foreign uncommitted changes that cannot be safely stashed · disk full.

### 1.4 Forbidden in an unattended run

- **`cloud env:logs` in any form.** No guaranteed exit path; on 2026-08-19 an 11-minute poll loop left
  70 orphaned PHP processes alive for six hours and drove load average to 45. If truly needed:
  `scripts/env/cloud-logs.sh <env> --minutes 2 --json`, **once**, never looped, never backgrounded.
- **Any interactive command.** Set `GIT_EDITOR=true` if a git command might try to open one.
- **Pushing anything.** No `git push`, no PR.
- **`scripts/audit/audit.sh`** and **`scripts/audit/archive-done.sh`**.
- **Touching another branch or worktree.**
- **Reading, printing, logging or committing any secret.**
- **Spending money.** No unit here may *increase* a paid-API budget, raise a spend cap, or trigger a
  billed call. Unit 14's `#LIFE-9` may only ever *refuse* spend.

### 1.5 Time and cost discipline

- **Full suite: at most twice** — baseline (§2.3) and end (§6). Per-unit verification is **targeted**.
- Per unit: **3 implementation attempts, 2 review rounds.** Either ceiling is a DEFER.
- Work units **in order**. Unit 14 is eight independent items — **one commit each**, so one DEFER does
  not take the other seven with it.

---

## 2. Setup + preconditions

> ### ⚠️ Premise freshness — refreshed 2026-08-26, re-verify anyway
> These findings were originally verified at `d52a604c5`. `development` has since advanced **41
> commits** to **`c529f16ac`**. This file was refreshed against that head on 2026-08-26; the
> per-unit `DECIDED` blocks below reflect it. What landed in between:
>
> - **The whole `ProjectionWriter` identity-scope cluster shipped** (`3c88c5925`..`c529f16ac`) —
>   see each file's own note; `#CACHE-2`, `#CACHE-4`, `#SCALE-8`, `#SCALE-9` are **fixed** and
>   `#SCALE-12` is **WONTFIX**, all ticked by `ad8922d15`. Do not re-open them.
> - **PR #310 `feat/staff-release-claim` + the ManyChat claim-link work** — `ClaimController.php`,
>   `StaffPreAccountBuildController.php`, `PreAccountBuild.php`, `AppServiceProvider.php`,
>   `routes/api.php`, `config/partna.php`, two migrations.
> - `755fdbdbd` storefront `logoMarkSvg` on the public collections wire (`PoolResolver.php`).
> - Earlier: `9a84b3721` (`#SCALE-3`, `#SCALE-15`), `924008fea` (`#SCALE-1`).
>
> **Still treat every premise as unverified until you check it against the code you pulled.** The
> refresh confirmed the units in this file, but `development` moves — this repo's backlog carries a
> measured ~40% already-fixed rate and this tranche has now been overtaken twice.

1. `git fetch && git pull` on `development`.
2. Branch/checkout `audit-fix/pre-launch-hardening-2026-08-25` per §0.
3. Baseline: `php artisan test --parallel` (NOT `composer test --parallel`). Record counts. **Red is
   recorded, not a stop.**
4. **Landed-work probes.** A failed probe is **not** a stop — re-read the finding against the code and
   note the discrepancy in §7.

   | Probe | Expected | Used by |
   |---|---|---|
   | `grep -n "wasDisconnected" app/Services/Platforms/Concerns/BuildsAutoSyncFindings.php` | present — landed 2026-08-25 | unit 12 |
   | `grep -n "withReservationsXorLock" app/Services/Platforms/Concerns/BuildsAutoSyncFindings.php` | present (~:481) | unit 12 |
   | `grep -n "authorizeForUser" app/Http/Controllers/Api/Platforms/ShopController.php` | present at ~:790 and ~:1016 — the pattern unit 9 copies | unit 9 |
   | `grep -n "unavailable" app/Site/Actions/ActionSlots.php` | present — the channel unit 8 mirrors | unit 8 |

---

## 3. House rules that bite in this part

- **⚠️ IDs COLLIDE ACROSS SWEEPS.** `SCALE-7` in the overnight run and `SCALE-7` in the remainder are
  **different findings**. Key every finding by ID **plus its source file**.
- **Duplicate ID pair in THIS part — fix once, tick BOTH boxes:** `CACHE-2` ≡ `SCALE-7` (remainder).
- **Audit line numbers are STALE.** Match by symbol.
- **Verify the premise first.** ~40% already-fixed rate in this backlog.
- **Every new assertion mutation-proved.** Break the code, watch it go RED, restore via `cp` from
  `<scratchpad>/mutation-backups/` — **never `git checkout`**. `git diff` after every round.
  **A mutation that does not go red is a finding about your test.**
- **Chained `expect()` aborts at the first failure** — separate statements.
- **`pint --test` is the gate, not `pint`.**
- **`checkpoint:scan` runs only in CI's `test` job.** Run it locally if you touch raw SQL — unit 14's
  `#CCH-1` does.
- **Tests run SQLite, prod is Postgres.** Check constraint-bound writes against `supabase/migrations/`.
- **Authorization via Policies**, never inline `abort_unless` or a bare `throw`.
  `authorizeForUser($user, …)`, never `authorize()` — `Auth::user()` is always null under Supabase JWT.
- **No Laravel migrations.** A unit that seems to need one is a DEFER.
- **Prod context:** prod is stopped, `core.users` = 0, and it lacks `content`/`ingest`/`routing`/`catalog`
  entirely. Several findings here **cannot fire on prod today** — say so when ticking rather than
  implying a live prod risk. Unit 14's `#SEC-4` is explicitly dev-only for this reason.

---

## 4. Units — work in order

### Unit 6 — Staff batch onboarding must not time out silently · M
**Findings:** `CACHE-2` ≡ `SCALE-7` (remainder). **One defect, two IDs — tick both.**
**File:** `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffPreAccountBuildController.php::batch()`

`$cap = 500;` then a bare `foreach ($rows …) { $this->builds->requestBuild(…) }` — up to 500
synchronous builds in one HTTP request. **This is the tool the pilot cohort gets onboarded with.** On
timeout staff get no response at all, so they cannot tell which rows landed.

**Verified current state (do not re-derive):** the response already returns
`['built', 'reused', 'failed', 'truncated']`, and `failed[]` already carries `row`/`code`/`message`.
The row loop already catches `PreAccountBuildException` per row. **So the wire shape is not the
problem** — the problem is that a timeout returns none of it, and that a non-`PreAccountBuildException`
`\Throwable` aborts the whole loop.

✅ **Re-verified 2026-08-26: `batch()` is byte-for-byte unchanged** by PR #310 / the ManyChat work,
so the analysis above still holds exactly. The surrounding **file** did change — a new
`POST /api/staff/builds/{build}/claim-token` re-issue method was added — so line numbers moved and
there is now a sibling method to keep consistent with. Match by symbol.

**DECIDED — time-budget the synchronous loop. Do NOT queue it.**
Queueing changes the staff contract to a job id plus polling, which needs frontend work nobody is
awake to coordinate (§1.2 trigger 2). The time-budget fix is additive-only and lands tonight.

Three changes:
1. **Per-row `\Throwable` catch** alongside the existing `PreAccountBuildException` catch, so one bad
   row cannot kill the batch. Record it in `failed[]` with a generic code; `report()` it so Nightwatch
   sees it.
2. **A wall-clock budget.** Stop starting new rows once elapsed exceeds a budget comfortably inside
   the request timeout, and return what completed. Read the budget from config, do not hardcode it.
3. **Additive response fields** naming exactly where it stopped — e.g. `processed` and `remaining` —
   so staff know which rows to re-upload. Additive fields only; **remove nothing, rename nothing.**

- **Mitigating fact that makes this correct:** re-uploading is safe. `requestBuild` dedupes and
  re-serves the live build as `reused`, so completed rows come back `reused` on the second pass.
- ⚠️ `PreAccountBuildService::requestBuild` dedupes **before** the pairing map — `CLAUDE.md` pins that
  order as deliberate (spec §4.1). **Do not reorder it.**
- Check `docs/wire-changes/` for the convention and add an entry for the added fields.
- Test: a batch that exceeds the budget returns a well-formed body with `processed` < total and a
  coherent `remaining`; a row that throws a non-`PreAccountBuildException` lands in `failed[]` and the
  loop continues. Mutation-prove by removing the per-row catch.
- **Plan and implement as separate instances** (§0b) — but do not wait between them.

### Unit 8 — `#RANK-2`: colliding pins are silently discarded · M
**Finding:** `#RANK-2` (correctness/actions-ordering-math)

Owner pins two menu/service items to the **same position in the same category**; validation accepts it
(`poolLockPositionsRule()` early-returns on `ItemFamily::CATEGORY_FAMILIES`) and
`PoolOrdering::applyLocks()` drops the collider via `! isset($placed[$lock['position']])`, returning a
bare item list with **no `unavailable` channel** — unlike `ActionSlots::resolve()`, which has one
(verified at `app/Site/Actions/ActionSlots.php:30,40,44,57`).

**DECIDED — mirror `ActionSlots`' `unavailable` channel. Do NOT add request-layer validation.**
Two reasons: the in-file precedent already exists and matching it is the safe unattended move; and a
new 422 on a pin request is a wire break (§1.2 trigger 2) while an added `unavailable` key is additive.

- Copy the shape from `ActionSlots::resolve()` — same key name, same list-of-ids payload — so the two
  surfaces read alike.
- Test: two pins at the same position produce a **visible** outcome — the survivor placed, the collider
  named in `unavailable` — not a silent drop. Mutation-prove by removing the `unavailable` append.
- ⚠️ If `PoolOrdering::applyLocks()`'s return type is consumed in more than a couple of places, widening
  it may ripple. Grep the callers first; if the ripple is wide, **DEFER** rather than half-changing a
  return contract.

### Unit 9 — `#SEC-10`: defence-in-depth authz on `ShopController` · M · **PRE-AUTHORISED, do not wait**
**Finding:** `#SEC-10` (unified-actions-security)

Five methods (`updateBrand`, `catalog`, `setProducts`, `addProduct`, `removeProduct`) lack the
`authorizeForUser` second lock.

**Verified: all five resolve via `$this->shop->store($user, …)` or `brandMap($user)` — structurally
user-scoped, no cross-tenant reach. This is the same class as `#SEC-14`, fixed 2026-08-25, and it is
defence-in-depth, not a live hole. Do not overclaim it in the commit message or `RESULT-PART-2.md`.**

- **DECIDED:** copy the pattern already applied in the same file at ~:790 and ~:1016. Do not invent a
  new policy method if an existing one fits.
- `authorizeForUser($user, …)`, never `authorize()`.
- **DECIDED — be honest in the tests.** If no denial is reachable because the resolver already scopes,
  **say so in the test's own comment and in `RESULT-PART-2.md`** rather than writing an assertion that
  can never fail. That was the correct, accepted outcome for `#SEC-14`. A test that cannot go red is a
  vacuous test, and this repo counts those as defects.
- If adding the call requires a **new** policy method, register it via `Gate::policy(...)` in
  `AppServiceProvider::boot()` and confirm `PolicyCoverageTest` still passes.

### Unit 10 — Small security hardening · S×4
Four independent items. **One commit each.**

**10a — `#SEC-6`** (`ShortLinkExpander`): the expanded URL is cached 24h with no `SecretParams` pass,
so a short link resolving to a token-bearing URL persists that token.
**DECIDED:** run the expanded URL through `App\Routing\SecretParams::redactUrl()` (verified at
`app/Routing/SecretParams.php:176`) before caching. **Do not write a second redactor.** ⚠️ Confirm the
redacted URL is still usable by whatever reads the cache — if redaction breaks the consumer, cache the
redacted value for display and note the residual in §7 rather than reverting.

**10b — `#SEC-8`** (`IriCanonicalizer`): an unanchored regex runs *before* the 2048 cap.
**Only scraper callers are exposed** — the user path is already bounded by `RouteLinkRequest max:2048`,
so do not overclaim. **DECIDED:** move the length cap **above** the regex. That is the whole fix; do
not rewrite the regex.

**10c — `#SEC-13`**: the reorder Form Requests have no `max:` on `categories`/`service_ids`.
**DECIDED:** copy `PoolController::reorder`'s existing `max:200` — same number, same shape. Do not pick
a different bound.

**10d — `#SEC-11`** (`AnalyticsController::pageview`, no bot filter or dedup):
**DECIDED — WONTFIX, intent confirmed in code. Change nothing.**
Evidence to paste when ticking, from `app/Http/Controllers/Api/PublicSite/AnalyticsController.php:58`:

```
// NOTE: pageview intentionally has NO bot filter and NO dedup (preserved). A bot
// UA still records a pageview today; changing that is a separate metrics decision.
```

That is a deliberate, documented carry-forward and a metrics-policy call, not a defect. Tick it
`WONTFIX — deliberate, see AnalyticsController.php:58` and move on. **Do not add a bot filter.**

### Unit 12 — `#TEST-20`: the single-slot family has no DB backstop · M
**Finding:** `#TEST-20` (delta). **Promoted off the deferred pile — the reasoning changed.**

`seedReservation()`/`seedOnlineOrdering()` read the whole family, then write, with no lock. Two workers
auto-routing the same user concurrently (IG harvest racing GBP enrich) both see an empty
`routing_class='reservations'` family and both write.

**Why this is not the same as `#TEST-19`** (closed as DB-backstopped):
`idx_platform_connections_unique_active` keys on `(user_id, surface_key, resource_id)`, so two
*different brands* (OpenTable + Resy) both insert cleanly into a family meant to hold one. The index
saves `#TEST-19`; it does **not** save this.

**DECIDED — this unit has a low bar for DEFER, and taking it is the right call.**
Harm today is a duplicate button, self-healable by disconnecting — **hardening, not data loss. Do not
overclaim it.** Against that, `withReservationsXorLock()` (~:481 in `BuildsAutoSyncFindings`) is
**also taken by `applyFinding()`**, so wrapping these seeders in it is a real concurrency change, and
`LinkRouter`'s comment about keeping the lock ordering acyclic is load-bearing.

**So: plan the lock ordering explicitly and write it down FIRST. If you cannot demonstrate on paper
that the resulting order is acyclic — or if the plan needs a second lock, a lock reorder, or a change
to `applyFinding()` — DEFER it (trigger 3) with that plan attached.** A deadlock introduced overnight
in the auto-routing path costs far more than a duplicate button.

- **Plan and implement as separate instances** (§0b) — but do not wait between them.
- If you do implement: test that two concurrent seeds produce exactly one member of the family.
  Mutation-prove by removing the lock. ⚠️ SQLite cannot exercise a Postgres advisory lock at all — if
  the lock is advisory, say plainly in `RESULT-PART-2.md` that the Feature lane does not prove it, and
  put the real proof in `tests/Postgres/` (§8) or state that it is unproven.

### Unit 14 — Remainder · S each
Eight independent items. **One commit each** — a DEFER on one must not take the other seven.

**14a — `#SEC-4`** (unified): Fresha vendor `name`/`description` copied unbounded into `content.f_text`
— DB bloat. **Dev-only: prod has no `content` schema.**
**DECIDED:** bound it to an existing limit — the column's own DDL bound in `supabase/migrations/`, or a
truncation convention already used by the same writer. **If neither exists, DEFER rather than inventing
a number** — an invented truncation silently loses merchant copy, and nobody is awake to pick the
length.

**14b — `#SEC-9`**: an inline `throw new AuthorizationException` outside the Policy framework. It fails
**closed** — doctrine drift only, no live hole. **DECIDED:** route it through the Policy per
`CLAUDE.md`, using `authorizeForUser`. If no policy method fits and adding one widens the blast radius
beyond this call site, tick it `WONTFIX — fails closed, doctrine only` with that reasoning.

**14c — `#CCH-1`**: a raw `DB::table` update with no following refresh, so the edge purge reflects
pre-strip settings. **DECIDED:** re-read the model after the raw update so the purge sees current
state. ⚠️ `CLAUDE.md`: a write path that bypasses Eloquent **will not** be caught by an observer and
must invalidate affected cache keys explicitly. If this is an owner-initiated pool mutation, it owes
all three lanes via `App\Site\Documents\SiteCacheLanes::bust()` — **never `BuildState::bump()` alone.**
This touches raw SQL: run `php artisan checkpoint:scan` before finishing.

**14d — `CCH-10`**: `brandProducts` has jitter but **no single-flight**. Owner-scoped, one store — low
stakes. **DECIDED:** use `CacheLockService`'s existing single-flight seam rather than a bespoke lock.
⚠️ If the value can legitimately be null, use `rememberLockedNullable`, **not** `rememberLocked` —
PART 1 unit 2 documents why feeding a null through the SWR path poisons the stale twin.

**14e — `#LIFE-9`**: Horizon "Retry" re-bills Mistral OCR. Auto-retry is already closed by `$tries = 1`;
**manual** retry is not.
**DECIDED — the guard may only ever REFUSE spend, never enable it (§1.4).** Make a manual retry
recognise that the OCR result already exists and skip the billed call, or refuse outright. **Do not
touch any budget, cap, or `config('partna.limits.*')` value**, and do not make a real billed call to
test — fake the upstream from `tests/fixtures/recorded/` (`Tests\Support\Fixtures\Recorded`).

**14f — `#LIFE-10`**: a rotated persisted-query hash is indistinguishable from any other GraphQL
rejection because `errors` is discarded at ~:426. **DECIDED:** log the discarded `errors` payload so
the rotation is diagnosable. ⚠️ A GraphQL `errors` array can carry request context — run it through the
same redaction the file already uses for URLs if it may contain one, and never log a credential.

**14g — `#PRIV-2`**: a moderation case that never resolves keeps a non-account reporter's PII forever;
only resolved cases prune. Won't bite for ~12 months at pilot volume.
**PRE-DEFERRED BY DECISION — do not implement. Write the plan and move on.**
This is a **retention-policy change that hard-deletes third-party PII on a new schedule** (§1.2
trigger 7). Getting the window wrong destroys evidence in an open moderation case, and the window is a
legal/product call, not an engineering one. **Write `DEFERRED-part2-unit-14g-priv2.md`** containing:
the current prune predicate, exactly which rows would newly become eligible, the candidate retention
windows with their trade-offs, and your recommendation. Leave the box unticked. Cross-reference
`project_priv2_syncfindings_remediation_open`.

**14h — `#SEC-5`** (claim-gate): fresh-AAL2 off pending TOTP enrolment.
**DECIDED — this is a staged-rollout checklist item, not a defect. Change no code.**
Tick it with the current rollout state written inline (is TOTP enrolment shipped? is it enforced?),
sourced from `docs/auth/mfa-foundation.md` and `config/partna.php`. If the rollout state is not
determinable from the repo, say that instead of guessing, and list it in §7 as a question for Josh.

---

## 5. Protocol for a refuted premise

1. Write the disproof down — the config line, the passing test path + its output, the framework source.
2. Tick the box as `WONTFIX — premise refuted` with that evidence inline. A ticked box means *resolved
   as an open question*, not *the code changed*.
3. If a real residual survives, land a test pinning the behaviour so the next sweep does not re-file it.
4. Move on. Do not escalate.

## 6. Per-unit close-out

1. Independent reviewer PASS (fresh instance, never the implementer). **2 rounds max — a second FAIL
   is a DEFER.**
2. **Targeted tests only** per unit. Full `php artisan test --parallel` **once at the end of this part**.
3. `./vendor/bin/pint --test` clean; `composer analyse` clean; `php artisan checkpoint:scan` clean if
   you touched raw SQL (14c does).
4. Tick boxes in the **source** audit file(s) — **including both IDs of `CACHE-2` ≡ `SCALE-7`** — and
   bump each file's `## Progress`. A Progress block can sit ~80 lines below its section header; find it
   by content.
5. Commit code + ticked audit files together: `fix(audit): <unit> — <ids>`. Mutation proof in the commit
   body. **Commit after every unit, and after each item of units 10 and 14.**

## 7. Final report — write `RESULT-PART-2.md` beside this file

**Write it even if the run ends early or badly.**

- Ledger, one row per unit **and per item of units 10 and 14**: `DONE` / `DEFERRED — <trigger>` /
  `BLOCKED — <reviewer defects>` / `WONTFIX — <reason>`. State the disposition of every finding ID.
- **Be explicit that unit 9 is defence-in-depth**, not a closed live hole, and say whether any denial
  was reachable in test.
- **14g must appear as DEFERRED with its plan file named.**
- **Surfaced, not worked.**
- Suite counts, and any pre-existing red carried in (with the commit that broke it).
- Branch name and commit range. **Do not push or open a PR without Josh's say-so.**
- Anything you would have asked Josh, written as a question with your recommended answer.
- **A one-line handoff for PART 3:** which files this part changed.

## 8. Notes carried in

- **The Postgres lane runs locally** against a scratch DB on the existing Supabase container:
  `CREATE DATABASE partna_pg_lane_scratch` on `127.0.0.1:54322`, then
  `PG_LANE_DISPOSABLE=1 DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=54322 DB_DATABASE=partna_pg_lane_scratch DB_USERNAME=postgres DB_PASSWORD=postgres DB_SSLMODE=disable ./vendor/bin/pest -c phpunit.pg.xml <path>`,
  then drop it. **Never** override `PG_LANE_DISPOSABLE` against the `postgres` database itself.
  Only unit 12 might need it.
- **Back up before mutating** — a mutation script hitting a tool timeout can leave a production file
  dirty. `git diff` after every mutation round.
- **`archive-done.sh` will not archive these sweeps.** Do not run it.
- **Prod facts** (verified 2026-08-25): env stopped; 0 of `content`/`ingest`/`routing`/`catalog`;
  ledger 4 rows; `core.users` = 0.
