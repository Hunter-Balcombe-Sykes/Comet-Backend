# EXECUTE — Pre-launch hardening PART 1 of 3: quick wins · UNATTENDED

**Run this by saying:** `execute audit audits/consolidation/2026-08-25-pre-launch-hardening/EXECUTE-PART-1.md`

**This file is written for an UNATTENDED overnight run. Josh is asleep. Nobody will answer a
question.** Every decision this part needs has been made in advance and is written inline as a
`**DECIDED:**` line. There are no sign-off gates in this part. §1 is the no-stop policy; read it
before §4.

**Six units, ~13 defects across ~16 IDs.** All S-effort, all high-certainty, none touching auth,
money, a migration or the public wire. This is the safest of the three parts by construction.
Sourced from `audits/consolidation/2026-08-25-pre-pilot-p2-promotion/BACKLOG-TRIAGE.md`, verified
against `development` at `d52a604c5`.

Follow `scripts/audit/fix-flow.md` for the per-unit plan → implement → **independent** review loop.
**Where this file and `fix-flow.md` disagree, THIS FILE WINS.**

> **Unit numbers are the ORIGINAL ones** from the undivided tranche (2, 3, 4, 5, 7, 11) and are kept
> deliberately so cross-references to `BACKLOG-TRIAGE.md` and the source sweeps still resolve. There
> is no unit 1, 6, 8, 9, 10, 12, 13 or 14 in this file — they live in PART 2 and PART 3.

---

## 0. Run order and git state — READ FIRST

**This part runs THIRD overall.** The required order is:

1. `../2026-08-25-pre-pilot-blockers/EXECUTE.md` — own branch, must be finished first.
2. **This file** — PART 1.
3. `EXECUTE-PART-2.md`
4. `EXECUTE-PART-3.md`

**All three PARTs share ONE branch:** `audit-fix/pre-launch-hardening-2026-08-25`.

- If that branch does **not** exist: create it off freshly-pulled `development`.
- If it **does** exist: `git checkout` it and continue on it. **Do not create a second branch and do
  not rebase it.**

**Never run two PARTs, or a PART and the pre-pilot tranche, concurrently in the same checkout.**
A peer session switching the branch mid-task will silently destroy work. If you need genuine
concurrency, each session needs its own `git worktree` with a **real (non-symlinked) `vendor/` and
`.env`** — a symlinked vendor makes every Feature test fail to bootstrap. That setup is out of scope
for this run: **run sequentially.**

Before starting: `git worktree list` and `git status`. If another worktree of this repo holds
uncommitted changes, do not write into it.

## 0b. Execution policy

- **Plan:** Opus 5 · **Implement:** Sonnet 5 · **Review:** Sonnet 5 — a *separate, independent*
  instance, never the implementer.
- **Combine plan+impl:** YES for every unit in this part (all S).
- No per-item model escalation in this part.

> The `Opus 4.8 / Sonnet 4.6` string that `scripts/audit/audit.sh:174` stamps into generated files is
> stale. THIS file's policy is authoritative.

---

## 1. NO-STOP POLICY — read this before anything else

### 1.1 There are no gates in this part

Nothing here touches auth, money, a schema change, or the public wire. `fix-flow.md` §1a does not
fire on any unit in this file. **Proceed through all six without asking.**

If you find yourself composing a question for Josh, that is a DEFER (§1.2), not a pause.

### 1.2 The DEFER lane — how a large issue exits without stopping the run

**Defer, never escalate, never grind.** Deferring is a successful outcome, not a failure.

**DEFER a unit the moment ANY of these is true:**

1. The fix would need a **schema change** (a `supabase/migrations/` file). Nothing in this part should.
2. The fix would **break the public or dashboard wire** in a way a frontend must change to absorb
   (removed field, changed status code on a success path, new required request key).
3. The unit turns out to be **M or larger** once the code is read — this part is all S; a genuine M
   belongs in a later part, not here.
4. The **independent review fails twice.** No third attempt, ever.
5. **Three implementation attempts** have not produced a green targeted test run.
6. The fix requires a **live third-party call, a real credential, or a paid API budget** to verify.
7. It would require editing a file another unit in this same run has already changed in a
   conflicting way, and reconciling them is not obvious.

**What DEFER looks like — do all five, then move on:**

1. Write `DEFERRED-part1-unit-<n>-<slug>.md` beside this file: finding IDs, what you found, the DEFER
   trigger number, the plan you had reached, and the concrete next step for a human. **A deferral
   with no written plan is a dropped ball, not a deferral.**
2. Leave the source audit checkboxes **unticked**.
3. `git checkout -- <paths>` any half-finished edit **for that unit only** — never a tree-wide reset.
4. Record it in §7's ledger as `DEFERRED — <trigger>`.
5. **Go to the next unit immediately.** Do not revisit.

### 1.3 Nothing else halts the run — including a red baseline

- **A red suite at baseline is NOT a stop.** Record the failing test names and the commit that broke
  them, carry on. Pre-existing red is not yours.
- **A refuted premise** → close per §5, tick `WONTFIX — premise refuted`, move on. **Expect several
  in this part** — units 2 and 5 both already have corrected diagnoses below, and this backlog carries
  a measured ~40% already-fixed rate.
- **Anything out of scope** → §7 "Surfaced, not worked". Do not chase it.
- Units are independent. One failing unit affects no other.

**The ONLY conditions that end the run early** (and you must still write `RESULT-PART-1.md` first):

- `git fetch`/`git pull` fails and cannot be recovered.
- The tree holds uncommitted changes that are not yours and cannot be safely stashed.
- The machine is out of disk.

### 1.4 Forbidden in an unattended run

- **`cloud env:logs` in any form.** No guaranteed exit path; on 2026-08-19 an 11-minute poll loop left
  70 orphaned PHP processes alive for six hours and drove load average to 45. If you truly need one:
  `scripts/env/cloud-logs.sh <env> --minutes 2 --json`, **once**, never looped, never backgrounded.
  You should not need one — this is offline code work.
- **Any interactive command.** No `git rebase -i`, no `git add -i`, nothing opening `$EDITOR`.
  Set `GIT_EDITOR=true` if a git command might try.
- **Pushing anything.** No `git push`, no PR, any remote, any branch.
- **`scripts/audit/audit.sh`** — this is a fix run, not a scan run.
- **`scripts/audit/archive-done.sh`** — these sweeps carry findings outside this tranche; it will
  refuse, and forcing it corrupts the record.
- **Touching another branch or worktree.**
- **Reading, printing, logging or committing any secret.**

### 1.5 Time and cost discipline

- **Full suite: at most twice** — once at baseline (§2.3), once at the end (§6). Per-unit
  verification is a **targeted** test path.
- Per unit: **3 implementation attempts, 2 review rounds.** Hitting either is a DEFER.
- Work units **in order**.

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
3. Baseline: `php artisan test --parallel` (NOT `composer test --parallel` — the flag reaches
   `config:clear` and errors). Record counts. **Red is recorded, not a stop.**
4. **Landed-work probes.** A failed probe is **not** a stop — re-read the finding against the code as
   it stands and note the discrepancy in §7.

   | Probe | Expected | Used by |
   |---|---|---|
   | `grep -n "writeWithJitter\|applyJitter" app/Services/Cache/CacheLockService.php` | both present | unit 2 |
   | `grep -n "EscalatesRepeatedFaults" app/Services/Analytics/ContentPopularityReader.php` | present — the precedent unit 3 copies | unit 3 |
   | `git log --oneline -5 -- app/Observers/Core/IntegrationConnectionObserver.php` | shows the pre-pilot unit 6 commit | unit 3 (`#LIFE-13`) |

---

## 3. House rules that bite in this part

- **⚠️ IDs COLLIDE ACROSS SWEEPS.** Key every finding by ID **plus its source file**. Matching by ID
  alone merges unrelated defects.
- **Duplicate ID pairs in THIS part — fix once, tick BOTH boxes:** `#LIFE-16` ≡ `#SCALE-20` ·
  `#LIFE-17` ≡ `#SCALE-21` · `#SEC-12` ≡ `SEM-6`.
- **Audit line numbers are STALE.** Match by symbol, never by position.
- **Verify the premise before changing anything.** A `//` comment cited as Evidence narrates history,
  not current state.
- **Every new assertion must be mutation-proved.** Break the code it covers, watch it go RED, restore
  via `cp` from `<scratchpad>/mutation-backups/` — **never `git checkout`**. Record the exact mutation
  and the observed failure text. **A mutation that fails to go red is a finding about your TEST.**
  `git diff` after every mutation round to prove the tree is clean again.
- **A chained `expect()` aborts at the first failure** — one run proves one link. Separate statements,
  or mutate once per assertion.
- **`pint --test` is the CI gate, not `pint`** (`pint` silently fixes then reports "passed").
- **`php artisan checkpoint:scan` runs ONLY in CI's `test` job.** If you touch raw SQL
  (`selectRaw`/`groupByRaw`/`whereRaw`), run it locally before finishing.
- **Tests run SQLite, prod is Postgres.** Check constraint-bound writes against `supabase/migrations/`.
- **No Laravel migrations.** A unit that seems to need one is a DEFER (§1.2 trigger 1).
- **Prod context, for honest write-ups:** prod is stopped, `core.users` = 0, and it lacks the
  `content`/`ingest`/`routing`/`catalog` schemas entirely. Several findings **cannot fire on prod
  today**. Say so when ticking rather than implying a live prod risk.

---

## 4. Units — work in order

### Unit 2 — `CacheLockService`: one missing jitter call · S
**Findings:** `CCH-3` + `CCH-6` (remainder). **Re-diagnosed — the finding text is wrong about the cause.**

The findings blame hardcoded TTLs at two call sites. The real bug is one line:
`rememberLockedNullable` calls `writeOrDegrade($key, $value, $ttl)` **directly**, while its sibling
`rememberLocked` routes through `writeWithJitter()` (~:262-277). So every `rememberLockedNullable`
entry expires in fleet-wide lockstep → a synchronised refetch burst at upstream hosts.

**One fix in `CacheLockService` covers every caller.**

- **DECIDED — add the jitter call, keep the nullable semantics.** ⚠️ Do **not** "upgrade"
  `rememberLockedNullable` to `rememberLocked`: its own inline comment forbids it, and `CCH-4`/`CCH-7`
  were both refuted on exactly that point — feeding a null through the SWR path poisons the stale twin.
- **DECIDED — do not touch the call-site TTLs.** `CCH-6`'s stated premise is already stale:
  `AppleSearch::itunes()` now reads `config('partna.refresh.host_limits.itunes.cache_ttl_seconds')`.
  Note that when ticking `CCH-6`; the code change belongs entirely to `CCH-3`.
- Test: assert two entries written in the same tick get **different** TTLs. Mutation-prove by removing
  the jitter call and watching it go red. ⚠️ If jitter is randomised, seed or bound the assertion so
  it cannot flake — a flaky test here is worse than no test.

### Unit 3 — Silent-swallow family · S×3
**Findings:** `#LIFE-15` (overnight, `PoolResolver`), `CCH-5` (remainder, `ShortLinkExpander`),
`#LIFE-13` (overnight, `IntegrationConnectionObserver`).

**⚠️ `#LIFE-13` was almost certainly fixed by the pre-pilot tranche's unit 6, which runs before this
part.** Check `app/Observers/Core/IntegrationConnectionObserver.php` first. **If it already reports
instead of `Log::debug`, tick `#LIFE-13` as `ALREADY FIXED — pre-pilot unit 6, <commit sha>` and do
no work on it.** Do not re-fix it and do not revert it.

Same shape as `CCH-11`, closed 2026-08-25: `catch (QueryException) { $x = []; }` or
`catch (\Throwable) {}` with no log, on paths where the failure is invisible.

- `PoolResolver` ~:812 — ingest badges blank silently; the same query is on the public hot path.
  ⚠️ That file changed on 2026-08-26 (`755fdbdbd`, storefront `logoMarkSvg` on the collections wire) —
  an additive change that does not touch this catch, but **the line number has moved.** Match by symbol.
- `ShortLinkExpander::resolveFinal()` — empty catch body, comment only. A defect or budget exhaustion
  is cached as "not expandable" for 1h with nothing reaching Nightwatch.
- **DECIDED — the fix is the log line, not the TTL.** The negative TTL is deliberate and documented
  ("Do NOT change these TTLs"). Leave it exactly as it is.
- **DECIDED — follow `App\Services\Analytics\Concerns\EscalatesRepeatedFaults`** (verified present),
  the existing precedent. Do not invent a new convention. Do **not** turn any of these fail-closed —
  the page must still render.
- ⚠️ `PoolResolver::resolve()` **provisions a section — it is not a read.** Do not add a test that
  loops it against a checkout with no content lane; that 500s via `LinkPoolReader`.
- Mutation-prove each report assertion with a **multi-argument** matcher (`[$message, Mockery::any()]`).
  A single-arg `shouldNotHaveReceived` is a documented vacuous shape in this repo, and
  `shouldHaveReceived()->never()` throws.

### Unit 4 — Scheduler hygiene · S×2
**Findings:** `#LIFE-16` ≡ `#SCALE-20`; `#LIFE-17` ≡ `#SCALE-21` (overnight). **Two defects, four IDs
— tick all four.**
**File:** `routes/console.php` (~:499-502 `platforms:enrich-pending-cards`; ~:506-509 `content:refresh-item-caches`)

Both use `->withoutOverlapping()` with **no expiry argument**, no `runInBackground()`, no `onFailure()`.
After a crash the lock persists for the default 24h and the command silently stops running.

- **DECIDED — copy `compute-popularity`'s shape verbatim** (~:154-159 in the same file), including its
  **exact expiry value**. Do not pick a new number: matching an in-file precedent is what makes this a
  safe unattended change, and a bespoke expiry is a judgement call nobody is awake to make.
- **DECIDED — only add `runInBackground()` if `compute-popularity` has it.** Do not add it
  independently; it changes process isolation and is not what these findings are about.
- Test: assert the schedule definitions carry an expiry and a failure handler. **This is a
  configuration assertion — mutation-prove it by removing the expiry argument and confirming the test
  actually goes red.** A config assertion that passes either way is the single most common vacuous
  test shape in this repo.

### Unit 5 — `#CFG-3`: `MAX_BRANDS` disagrees with itself · S
**Finding:** `#CFG-3` (delta)

`StoreBrandSeeder.php:53` says `MAX_BRANDS = 5`. `ShopController.php:105` and
`ConnectStoreFromProductJob.php:56` both say `10`, and seven `app/Catalog/Definitions/*.php` comments
say "MAX_BRANDS (10, T9)". A user with 5 brands pastes a 6th store link: the connection is placed but
the brand row is capped (`outcome: capped`), so the store **half-exists and never renders**.

**DECIDED — the intended number is 10. `StoreBrandSeeder`'s 5 is the defect.**
Evidence, so you do not need to re-derive it: `docs/plans/2026-08-20-overnight-item-routing-run.md:316`,
under the heading *"T9 — Every cap and budget raised 2–3x (EXPLICIT OWNER PERMISSION, 2026-08-20)"*,
records `ShopController::MAX_BRANDS + ConnectStoreFromProductJob::MAX_BRANDS 5 → 10 (keep the two in
lockstep — same number, same commit)`. That sweep listed only two copies; `StoreBrandSeeder` is a
**third** copy it missed, and its own docblock says *"Mirrors ShopController::MAX_BRANDS … keep in
lockstep"*. So this is a missed lockstep update, not a competing decision. **Do not lower the other
two to 5.**

**DECIDED — put the number in ONE place and have all three read it.** Add a single config key under
`config/partna.php` (match the grouping the neighbouring caps already use — `platform_links_max` at
~:275 and the `limits` block at ~:301 are the two local conventions; pick whichever the shop caps sit
nearest). The three constants become reads of that key.

⚠️ **A fourth mirror exists and is OUT of scope:**
`ManagesIntegrationConnection::maxAccounts()` (~:638) also returns 10 with a comment *"mirrors shop's
MAX_BRANDS"* — but it caps **connected accounts per platform**, which is a different quantity that
merely happens to share a value. **Leave it alone** and note it in §7. Folding it into the same key
would couple two unrelated limits.

- Test: a user at the cap paste-connecting one more store gets a clean refusal, never a half-connected
  store. Assert the seeder and the controller agree by reading the same key.

### Unit 7 — `#JOB-3`: a failed approval must fail the job · S
**Finding:** `#JOB-3` (remainder) — `ApproveEarlyAccessBuildJob`

Four `return` paths after `report($e)`/`Log::warning` with no `$this->fail()`. Staff approve an
early-access signup, the job hits a failure, and **Horizon shows it processed** — the invitee is never
invited. Partly mitigated: `build_state` → `FAILED` and `report()` reaches Nightwatch, so a signal
exists — the *Horizon* one does not.

**DECIDED — per path, so you do not have to judge this unattended:**

| Path (~line) | Disposition |
|---|---|
| `early_access.approve.build_failed` (~:121-126) | **`$this->fail($e)`** — the build did not happen; this is a real failure. |
| `early_access.approve.build_collision` (~:133-138) | **Leave as a quiet `return`.** A collision means a live build already exists — a legitimate no-op, not a failure. Tick it as deliberate with this reason. |
| `early_access.approve.scrape_failed` (~:173-176) | **`$this->fail($e)`** — the invitee gets an empty site; staff must see it. |
| generic `\Throwable` (~:198) | **`$this->fail($e)`** — an unclassified fault is the clearest case of all. |

- ⚠️ Check `$tries` / retry config before adding `fail()`: if the job retries, `fail()` on a transient
  fault turns one retryable blip into a hard failure. If `$tries > 1` and the path is plausibly
  transient, say so in `RESULT-PART-1.md` rather than changing the retry policy — **retry-policy
  changes are out of scope**.
- Test: each of the three failing paths marks the job failed; the collision path does **not**.
  Mutation-prove by removing one `fail()` call at a time.

### Unit 11 — Small correctness · S×4
Four independent one-file defects. **Work them as four separate commits** so one DEFER does not take
the other three with it.

**11a — `SEM-8`:** an `is_int()` gate lets the string `"1"` skip the contiguity check entirely, while
the sibling rule uses Laravel's non-strict `integer`.
**DECIDED:** match the sibling rule — use the same non-strict form the sibling uses. Consistency with
the in-file precedent is the fix; do not tighten the sibling to strict instead.

**11b — `SEM-14`:** `ConnectionIdentity::matchExisting` folds case for **every** surface, ignoring the
`$foldable` allowlist computed two lines above — collapsing two distinct Discord invite codes.
**Within one tenant, no cross-tenant reach** — do not overclaim it as a tenancy bug.
**DECIDED:** honour the `$foldable` allowlist that is already computed. The variable exists and is
unused; that is the whole defect.

**11c — `SEM-17`:** `PoolResolver`'s sources panel emits timezone-naive timestamps → a +10h error for
an AEST reader. **DECIDED:** use `->utc()` — the correct call is already in the same file; copy it.

**11d — `#SEC-12` ≡ `SEM-6`** (**tick both IDs**): a nonexistent `site_id` returns 422 while a
real-but-unpublished one returns 404 — a validity oracle, though it needs a guessed UUID.
**DECIDED — collapse both to 404.** `CLAUDE.md` states the house rule outright: *"Public endpoints:
always 404 (403 enables enumeration)"*, and 404 is the shape that leaks nothing. Assert on the **full
body**, not just the status, and mutation-prove by restoring the 422.
⚠️ If the 422 comes from a Form Request validation rule rather than the controller, moving it may
change the response shape for other keys in the same request. If that is the case and it is not
cleanly separable, **DEFER 11d only** and keep 11a–11c.

---

## 5. Protocol for a refuted premise

1. Write the disproof down — the config line, the passing test path + its output, the framework
   source, whatever settles it.
2. Tick the box as `WONTFIX — premise refuted` with that evidence inline. A ticked box means
   *resolved as an open question*, not *the code changed*.
3. If a real residual survives, land a test pinning the behaviour so the next sweep does not re-file it.
4. Move on. Do not escalate.

## 6. Per-unit close-out

1. Independent reviewer PASS (fresh instance, never the implementer). **2 rounds max — a second FAIL
   is a DEFER.**
2. **Targeted tests only** per unit. Full `php artisan test --parallel` **once at the end of this
   part**.
3. `./vendor/bin/pint --test` clean; `composer analyse` clean; `checkpoint:scan` clean if you touched
   raw SQL.
4. Tick boxes in the **source** audit file(s) — **including both IDs of every duplicate pair (§3)** —
   and bump each file's `## Progress`. A Progress block can sit ~80 lines below its section header;
   find it by content, not offset.
5. Commit code + ticked audit files together: `fix(audit): <unit> — <ids>`. Mutation proof in the
   commit body. **Commit after every unit.**

## 7. Final report — write `RESULT-PART-1.md` beside this file

**Write it even if the run ends early or badly.** A run that produces no report produced nothing.

- Ledger, one row per unit: `DONE` / `DEFERRED — <trigger>` / `BLOCKED — <reviewer defects>` /
  `WONTFIX — <reason>` / `ALREADY FIXED — <sha>`. State the disposition of every finding ID.
- **Surfaced, not worked** — at minimum `ManagesIntegrationConnection::maxAccounts()` from unit 5.
- Suite counts, and any pre-existing red carried in (with the commit that broke it).
- Branch name and commit range. **Do not push or open a PR without Josh's say-so.**
- Anything you would have asked Josh, written as a question with your recommended answer.
- **A one-line handoff for PART 2:** which files this part changed, so PART 2 can spot a conflict.

## 8. Notes carried in

- **Back up before mutating** — a mutation script hitting a tool timeout can leave a production file
  dirty. `git diff` after every mutation round.
- **`archive-done.sh` will not archive these sweeps.** Do not run it.
- **Prod facts** (verified 2026-08-25): env stopped; 0 of `content`/`ingest`/`routing`/`catalog`;
  ledger 4 rows; `core.users` = 0.
