# Pre-pilot blockers — RESULT

**Branch:** `audit-fix/pre-pilot-blockers-2026-08-25` · **9 commits**, `41868c54d..ef38c8b5d`
(9 ahead of `origin/development`). Nothing pushed, no PR.

**Run history.** Units 1–5 ran unattended as the overnight orchestrator's step 1 on 2026-08-26 and were
terminated **mid-unit-6 at 05:07:04** — not a code failure and not the 4h timeout (the step had run
2h43m). The agent ended its turn while unit 6's verification was still running in the background;
`claude -p` waits `CLAUDE_CODE_PRINT_BG_WAIT_CEILING_MS` (600s) for background work to finish and then
terminates, because print mode has no way to resume. It never wrote this file and left unit 6's work
uncommitted; step 2 (PART 1 of the hardening tranche) inherited the dirty tree and stashed it.
**Units 6–9 were completed attended on 2026-08-26** after recovering that stash.

---

## 1. Ledger — all 11 findings

| Unit | Findings | Disposition |
|---|---|---|
| 1 | `#SEC-7` ×2 | **DONE** `41868c54d` — one half's premise **refuted** (`ImportRun::start()` already redacts internally) |
| 2 | `#TEST-10`, `SEM-16` | **DONE** `308c472dd` — 2b was a hand-copy bug, not ambiguous semantics |
| 3 | `SEM-7` | **DONE** `840831e03` — review FAILED round 1 (upsert could resurrect an unpinned row), fixed, passed round 2 |
| 4 | `#CCH-2` | **DONE** `430a42678` — found and gated a **second** stale-serve path the finding never named |
| 5 | `#LIFE-11` | **DONE** `9a1881523` — **diverged** from the file's `Cache::lock` DECIDED; see §5 |
| 6 | `#LIFE-13`, `#LIFE-14` | **DONE** `0db07c882` — recovered from the orphaned stash; three verification defects found on recovery, §3 |
| 7 | `#SEC-3` (overnight-run) | **DONE** `2a941daef` — owner ruling required; see §4 |
| 8 | `#SEM-3` | **DONE** `1af9775da` — `CLAIM_EMAIL_MISMATCH` deliberately left open, §6 Q1 |
| 9 | `#SEC-16` ≡ `#SEC-4` | **DONE** `ef38c8b5d` — one defect, two IDs, both ticked |

**11 of 11 closed. 0 deferred.** All 12 source checkboxes ticked (6 findings × lens file + `CONSOLIDATED.md`).

---

## 2. Suite status

| Lane | Result |
|---|---|
| `php artisan test --parallel` | **9280 passed, 3 skipped, 0 failed** (32,666 assertions, 78s) |
| Postgres lane (`tests/Postgres`) | **247 passed, 3 skipped, 2 failed** |
| `./vendor/bin/pint --test` | passed |
| `composer analyse` | `[OK] No errors` (1413 files) |
| `php artisan checkpoint:scan` | 21 passed, 4 warnings, **0 failed** |

**The 2 Postgres failures are pre-existing and unrelated** — both `LanderFoldAtomicityTest`,
`SQLSTATE[42P01] relation "ingest.record_state" does not exist` on
`DROP TRIGGER IF EXISTS … ON ingest.record_state`. Identical to the pair PART 3 of the hardening
tranche documented and proved pre-existing by restoring its changed files from `HEAD`. It is the known
first-creator-wins DDL-ordering fragility in that lane, not a regression from this work.

`composer test` (serial) was not run — it hits composer's 300s process timeout on this machine.

---

## 3. Unit 6: what the interrupted verification had not yet found

The implementation in the stash was complete and correct. Its **verification was not**, and it carried
three defects that would each have gone red in CI:

1. **A cross-file test helper.** `SourceProvisionerInsertRaceTest` called `provisionerUser()` and
   `makeConnection()` defined in a *sibling* file. That passes serially and **fails under
   `php artisan test --parallel`**, which is what CI runs — the files land in different processes.
   Now file-local `raceUser()`/`raceConnection()`, deliberately renamed because both files DO share a
   process on a serial run, where a redeclare is fatal.
2. **The plan's own TOP RISK, realised.** Reporting real DB faults instead of swallowing them means
   every test that creates a connection through Eloquent **without** the ingest mirror now reports a
   `42P01`. Four files asserted on that silence and went red: `RefreshObservabilityTest`,
   `EnrichLinkCardJobLockTest`, `InstagramThinRefreshTest`, `InstagramJobSeederLockTest`. All four now
   provision the mirror, paired with `Bus::fake()` per the plan's SECOND RISK (provisioning switches
   the eager-run dispatch on). Safe because all four drive jobs via `->handle()` directly, so faking
   the bus cannot affect their assertions.
3. **The PG lane scratch DB had been dropped** after PART 3 used it; recreated via PDO (`psql` is not
   installed on this machine).

The `#LIFE-13` quiet arm uses Laravel's typed `UniqueConstraintViolationException` rather than
SQLSTATE string-matching, which is stronger than the file's DECIDED and satisfies its intent.

---

## 4. Unit 7 needed an owner ruling, and got one

The audit asked for `order.attacker.example` rejected **and** every genuine Square ordering host still
accepted. **Those are incompatible.** The pattern's third arm,
`^order\.(?!online$|toasttab\.com$|ubereats\.com$|doordash\.com$|menulog\.com\.au$)`, is an
allowlist-by-exclusion: the lookahead is zero-width with no terminal anchor, so every host starting
`order.` matched except five named competitors. A Square Online **custom domain**
(`order.<merchant>.com`) is structurally identical to an attacker's, and Square publishes no list to
verify against — so the audit's fallback rule ("keep the host accepted, widen the anchor not the
allowlist") is unsatisfiable too.

`app/Catalog/Definitions/Square.php` reached this conclusion independently and gives `square.order`
**no detector at all** — *"detector intentionally absent: square.site hosting is ambiguous"*.

**Owner ruling 2026-08-26: drop the arm.** Square is detected from `square.site` and `square.com` only,
matching the catalog and matching every other entry in this registry, all of which are exact-domain.
**Accepted cost:** a Square Online store on its own domain stops being auto-detected and must be
connected explicitly. Currently hypothetical — production carries no users, and the only `order.*`
merchant anywhere in the repo was a test fixture (`order.fat-tuna.com`), now repurposed to pin the new
behaviour.

`SquareMenuHostPatternTest` runs the repo's differential convention over a host corpus, asserting the
**only** hosts that lose their match are unanchored `order.*` ones and that nothing new is accepted —
so a future edit that silently drops a real Square host fails there rather than in production.

---

## 5. Unit 5's divergence from a DECIDED line (carried forward from the interrupted run)

The file said to use `Cache::lock(platformConnectionLock(...))`. That lock is **advisory** — the
competing writer (`UserWorkplaceController`'s unlocked `firstOrNew`→`save()`) never acquires it, so the
prescribed fix would have looked right and changed nothing. `lockForUpdate()` was used instead, which
the database enforces against every writer, matching `IdentitySync.php:240` on the same table.

---

## 6. Questions for Josh, with recommended answers

1. **`CLAIM_EMAIL_MISMATCH` still leaks site existence, exactly as `CLAIM_NOT_INVITED` did. Leave it?**
   *Recommended: yes, for now.* `CLAUDE.md` pins it as load-bearing for the ManyChat claim-link design
   (`docs/superpowers/specs/2026-08-25-manychat-claim-link-design.md`) — the narrow token *"satisfies
   the invite-gate only and does NOT override `CLAIM_EMAIL_MISMATCH`"*. Collapsing it would silently
   weaken a guard that shipped days ago **and** strand an invited user who signed up under the wrong
   address with a bare 404 and no way to understand why. The enumeration value of this branch is also
   lower: it only answers for a build that already has a `contact_email`, i.e. one already invited.
   If you do want it closed, the clean version is a uniform 404 plus an out-of-band email to the
   *invited* address, not a distinguishable status.

2. **Unit 8 adds no timing defence. Accept?** *Recommended: yes.* A constant-time claim path was
   explicitly out of scope and would have been a DEFER. The residual: the not-invited branch does
   strictly less work than a genuine claim, so a determined attacker could still distinguish them by
   latency. Closing that means padding every claim response to a fixed floor — worth doing only if
   you decide enumeration is a real threat rather than a hygiene issue.

3. **Should the still-open P1 `#SEC-3` (claim-gate) block pilot?** *Recommended: yes — it is the one
   that matters.* Unit 8 narrows *discovery* but does **not** close first-come claiming with nothing
   tying the claimer to the builder. `CLAUDE.md` records that the "Dark Until Claimed" gate was
   reverted on owner decision and that this must be closed **at the claim step (ownership proof), not
   the read step**. Nothing in this tranche changes that.

---

## 7. Runbook steps for you — unit 9's environment decisions

Deliberately **not** done here; both are env changes with real user impact.

```bash
# 1. Check whether the frontend actually mints bot tokens, BEFORE enforcing anything.
#    Enforcing first locks out every real user. Production is currently stopped, so
#    there are no such logs yet — this is a post-launch gate, not a pre-launch one.
scripts/env/cloud-logs.sh production --minutes 60 --json | grep bot_protection.shadow_reject

# 2. Only once (1) shows real traffic minting valid tokens:
cloud environment:get production --json --fields=environmentVariables   # confirm current state
#    then set BOT_PROTECTION_MODE=enforce (leave driver=turnstile, keys already present)

# 3. Development is wired to nothing (driver=null, mode=off). Wiring it at all is a
#    separate decision — it needs its own Turnstile site key/secret pair.
```

The new boot guard refuses a **production** boot on `mode=enforce` + `driver=null` and warns elsewhere.
It is **inert on both environments as configured today** (production `turnstile`/`shadow`, development
`null`/`off`), and `BotProtectionEnforceBootGuardTest` asserts that — so it cannot begin refusing a
production boot without failing CI first. `EnvCheckService` now reports an `effective` verdict, so
`shadow` can no longer read as "protection is on"; the driver secret is never included, and a test
asserts that.

---

## 8. Surfaced, not worked

1. **Six other `seedCustom()` call sites in `LinkInBioImporter`** pass raw URLs down the same path
   unit 1 fixed for two of them. Same class of defect, not in any unit's scope. *(Carried from the
   interrupted run's own notes.)*
2. **`PoolWire::poolLaneAbsent()` returns `[]` with zero logging on a Postgres `42P01`.** This is the
   actual mechanism by which production's missing `content` schema will be invisible — more directly
   than the `ActionCandidates` line `#TEST-10` named. Worth a finding of its own.
3. **Route coverage for unit 9 was not duplicated.** `tests/Feature/Security/BotProtectionCoverageTest.php`
   already asserts every public mutation endpoint is bot-protected or explicitly exempted, which is
   strictly stronger than the finding's "cover the ten wired routes". Noted rather than re-tested.
4. **`## Progress` blocks across three sweeps were stale, in both directions.** Ticking this tranche's
   findings required touching them, so every Progress block in the affected files was recomputed from
   actual checkbox state rather than incremented. That repaired the divergence PART 3 flagged
   (`P1 High: 4 of 6` → `6 of 6` in the overnight sweep) and one wrong **denominator**
   (`P3 Low: 0 of 8` → `0 of 7` in unified-actions-security, which has 7 P3 findings). A wrong
   denominator is the more dangerous of the two: it makes `archive-done.sh` either fire early or never.
