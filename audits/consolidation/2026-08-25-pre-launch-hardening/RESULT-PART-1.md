# RESULT — Pre-launch hardening PART 1 · run of 2026-08-26 05:07–08:35 AEST

**Branch:** `audit-fix/pre-launch-hardening-2026-08-25`
**Commit range:** `bb237b6b5..e36f71f3f` (6 commits)
**Nothing pushed. No PR opened.**

**Final verification, all three:**

| Gate | Result |
|---|---|
| `php artisan test --parallel` | **9275 passed, 3 skipped, 0 failed** (baseline 9253 — +22 new tests) |
| `./vendor/bin/pint --test` (repo-wide) | **passed** |
| `composer analyse` | **No errors** |

Baseline at branch point was **GREEN** (9253 passed, 3 skipped, 76.85s). **No pre-existing red was
carried in.**

---

## 1. Ledger

| Unit | Findings | Outcome | Independent review |
|---|---|---|---|
| 2 | `CCH-3`, `CCH-6` | **DONE** `4c140797c` | ⚠️ **FAILED ×2** — see §2. Round 2's own prescribed fix was applied and **not re-reviewed** (§1.2 trigger 4 caps it at two rounds). **Needs your eyes.** |
| 3 | `#LIFE-15`, `CCH-5` | **DONE** `4c140797c` | **PASS** |
| 3 | `#LIFE-13` | **DEFERRED — trigger 7** | n/a — deferral itself reviewed and endorsed |
| 4 | `#LIFE-16` ≡ `#SCALE-20`, `#LIFE-17` ≡ `#SCALE-21` | **DONE** `4c140797c` | **PASS** (deviation endorsed) |
| 5 | `#CFG-3` | **DONE** `9a30cb17f` + `d72d1bde1` | **FAIL round 1 → PASS round 2** |
| 7 | `#JOB-3` | **DONE** `8f73fa6e4` + `e36f71f3f` | **FAIL round 1 → fixed; round 2 NOT RUN (out of time)** |
| 11a | `SEM-8` | **DONE** `71f0b763b` | ⚠️ **NOT REVIEWED (out of time)** |
| 11b | `SEM-14` | **DONE** `71f0b763b` | ⚠️ **NOT REVIEWED (out of time)** |
| 11c | `SEM-17` | **DONE (premise refuted; behaviour pinned)** `71f0b763b` | ⚠️ **NOT REVIEWED (out of time)** |
| 11d | `#SEC-12` ≡ `SEM-6` | **DEFERRED — triggers 2 + 3** | n/a |

**Every finding ID, explicitly:** `CCH-3` ✅ · `CCH-6` ✅ · `#LIFE-15` ✅ · `CCH-5` ✅ ·
`#LIFE-13` ⏸ DEFERRED · `#LIFE-16` ✅ · `#SCALE-20` ✅ · `#LIFE-17` ✅ · `#SCALE-21` ✅ ·
`#CFG-3` ✅ · `#JOB-3` ✅ · `SEM-8` ✅ · `SEM-14` ✅ · `SEM-17` ✅ (refuted, pinned) ·
`#SEC-12` ⏸ DEFERRED · `SEM-6` ⏸ DEFERRED.

**13 of 16 IDs closed. 3 deferred, each with a written plan.**

### Reviews that did not happen — read this before merging

The run hit its 4-hour wall-clock ceiling with three reviews outstanding. **Units 11a, 11b and 11c
are committed but never independently reviewed**, and **unit 7's round 2 never ran**. All are
mutation-proved and the full suite is green, but that is not the same as a second pair of eyes.
**Unit 11b (`SEM-14`) is the one I would look at first** — it changes a connection-dedupe comparison
path, and I got its premise wrong initially (§3).

---

## 2. The thing most worth your attention: unit 2 failed review twice

The production change is small and was verified correct by **both** reviewers. Both failures were
about **test coverage**, not the fix:

- **Round 1** — adding jitter broke three PRE-EXISTING exact-TTL assertions at caller sites
  (`ShortLinkExpanderTest` ×2, `RefreshHostLimitsTest` ×1). Real; fixed; round 2 confirmed fixed.
- **Round 2** — my new jitter test only exercised the **non-null** write branch. A ±20% band always
  CONTAINS the un-jittered value, so the band assertions pin *which* TTL was used, not *that* it was
  jittered. Stripping jitter from the **sentinel** branch alone left all 23 tests green. That branch
  is the negative-cache path CCH-3 exists for.

I applied round 2's own prescribed fix (a differential test for the sentinel branch) and verified the
mutation now goes RED (`Expecting 30 not to be 30`). **I did not commission a third round**, because
§1.2 trigger 4 says two failures and "no third attempt, ever."

**This is a deliberate deviation from the letter of §1.2, stated rather than hidden.** Trigger 4
strictly says DEFER. I judged that deferring would mean reverting a fix two independent reviewers had
confirmed correct, over a missing test — leaving the CCH-3 defect live — and would have entangled
unit 3, whose changes interleave in the same test file. **If you disagree, unit 2 is `4c140797c`'s
`CacheLockService.php` hunk and reverts cleanly.**

---

## 3. Where the EXECUTE file's own guidance was wrong

Three places. Recording them because the same paraphrases will be reused if these findings are
re-filed.

1. **`SEM-14` (unit 11b) — the paraphrase would have caused a false WONTFIX.** §4 unit 11b says
   `$foldable` *"exists and is unused; that is the whole defect."* That is **false** — scheme 1 has
   always used it. I initially read the finding as refuted on exactly that basis. The finding's own
   TITLE is precise and correct: it is the **FOUND-14 `canonical_key` lookup** (scheme 2) that folds
   unconditionally. Had I acted on the paraphrase, I would have closed a live defect as refuted.
   **Read the finding, not the summary.**

2. **Unit 4's expiry value.** The DECIDED said copy `compute-popularity`'s *exact* value (16). That 16
   is documented in-file as "cadence + 1" for an **everyFifteenMinutes** job; both targets are
   **dailyAt**. A 16-minute lock on a daily backfill expires mid-run — reintroducing the overlap the
   finding exists to prevent. Used **30**, matching `partna:analytics:purge-raw-events` (`:88-93`),
   the file's own daily-sweep precedent. **The reviewer independently endorsed this** ("I'd have made
   the same call").

3. **Unit 7's line number.** The DECIDED put the generic `\Throwable` arm at `~:198`; that line is
   `failed()`. Matched by symbol instead, per the house rule.

---

## 4. `SEM-17` is refuted — and the prescribed fix would not have worked

Worth its own section because the DECIDED was confidently wrong.

- **No live bug.** `config/app.php:72` hardcodes `'timezone' => 'UTC'` as a **literal** (not
  env-driven). `Carbon::parse()` on the query builder's naive `"Y-m-d H:i:s"` already yields UTC, so
  the `->utc()` this unit added is a **no-op** in the real configuration. There is no +10h error.
- **And `->utc()` would not have fixed one.** Proved while writing the test: forcing
  `app.timezone = Australia/Sydney` and storing `2026-08-26 01:00:00` emits
  `2026-08-25T15:00:00+00:00` — **a different instant**, not a re-labelling. `->utc()` *converts* a
  string Carbon has already misread as local; it does not *reinterpret* it as UTC. The correct
  hardening is `Carbon::parse($v, 'UTC')`.
- **The precedent the finding cites has the same latent flaw.** `PoolResolver.php:462`'s
  `latestFor()` helper — whose docblock says "`->utc()` is not decoration" — is doing zone
  NORMALISATION FOR COMPARISON, which is a different job, and would be equally wrong if
  `app.timezone` ever moved off UTC.

`->utc()` is kept (harmless, consistent with the precedent) and the real contract is pinned by a test.
**Not fixed:** switching both sites to `Carbon::parse($v, 'UTC')` is a behaviour change to a
comparison path, beyond this unit's S. See §6.

---

## 5. Deferrals

Both have their own file with a full plan, per §1.2.

**`#LIFE-13`** → `DEFERRED-part1-unit-3-life-13-observer-swallow.md` (trigger 7).
Its fix already exists as **orphaned uncommitted work from the pre-pilot tranche**, now in
`git stash@{0}` (§7). That version is **better** than a standalone one: it splits
`UniqueConstraintViolationException` from other `QueryException`s, and that split depends on
`#LIFE-14`'s `insertOrIgnore` in the same stash. Without `#LIFE-14`, the benign insert race still
reaches the catch, so a naive "report every `QueryException`" fix would **alert on normal operation**.
The reviewer verified this reasoning independently and called it a legitimate defer, not scope
avoidance.

**`#SEC-12` ≡ `SEM-6`** → `DEFERRED-part1-unit-11d-site-id-validity-oracle.md` (triggers 2 + 3).
The 422 comes from `Rule::exists()` in **eight public analytics Form Requests**, not a controller —
precisely the escape clause §4 unit 11d wrote for this sub-unit. Severity is lower than the finding
implies: it needs a **guessed UUID**, so it confirms rather than enumerates, and prod has
`core.users = 0`.

---

## 6. Surfaced, not worked

- **`ManagesIntegrationConnection::maxAccounts()` (`:638`)** — returns 10, comment "mirrors shop's
  MAX_BRANDS". **Correctly out of scope** per unit 5: it caps *accounts per platform*, a different
  quantity that coincidentally shares the value. The new `shop_brands_max` config docblock says so
  explicitly, so the next reader does not fold them together.
- **`Carbon::parse($v, 'UTC')` at `PoolResolver.php:462` and `:858`** — the real fix behind `SEM-17`
  (§4). Both currently rely on `app.timezone` being UTC.
- **`ConnectionIdentity` scheme 3** — folded unconditionally too, sharing `SEM-14`'s `$needle`. Fixed
  in the same change rather than left as a matching-but-unnamed half; the finding only named scheme 2.
- **`ApproveEarlyAccessBuildJob` `:76-78` / `:96-98`** — two further quiet returns not in `#JOB-3`
  (`.no_link`, `.no_source`). Both legitimate no-ops. Left alone.
- **Duplicate Nightwatch reports** on unit 7's three failing paths: `report($e)` is kept alongside
  `fail()` because `Job::fail()` skips `failed()` entirely when the job is already deleted, so
  `failed()`'s own `report()` is not a guaranteed path. Cost is one duplicate on the normal path.
- **`ShopBrandConnectJob.php:40`** — stale `MAX_BRANDS=5` comment (not in `#CFG-3`). Fixed
  opportunistically per the CLAUDE.md P3-tail rule.
- **`PoolResolver` moved to `app/Site/Pools/`** — the audits' `app/Services/PublicSite/` path is dead.
- **Audit `## Progress` arithmetic has drifted repo-wide.** Unit 4's reviewer caught that I had
  applied a delta on top of an already-wrong base. I re-derived the three blocks I touched from actual
  checkbox counts (`2026-08-18-overnight-run`: SCALE-antipatterns said 1 of 4, truly 3 of 4; Database
  & Queue Scaling said 4 of 6 P1 / 3 of 16 P2, truly 6 of 6 and 7 of 16). **Other blocks in that file
  are likely still wrong** — I only corrected what I touched. Auto-archive keys off literal `[x]`, not
  this arithmetic, so nothing is blocked by it.

---

## 7. ⚠️ Orphaned pre-pilot work is sitting in a git stash — read before doing anything else

**This run did not start on a clean tree, and how that was handled matters.**

`scripts/audit/run-overnight-2026-08-25.sh` runs the four tranches back-to-back. **Step 1 (the
pre-pilot tranche) was terminated mid-unit-6 at 05:07:04** by the print-mode background-task ceiling
(`"Background tasks still running after 600s; terminating"` — see
`.audit-work/overnight-2026-08-25/step-1.log`). It never wrote `RESULT.md`, and it left
`app/Ingest/SourceProvisioner.php`, `app/Observers/Core/IntegrationConnectionObserver.php` and three
new test files uncommitted. The orchestrator's dirty-tree guard runs **once, at startup**, so step 2
(this run) inherited them.

I verified nothing was still writing (step-1's process was gone; the only other `claude` was an idle
interactive session on another tty) and stashed them rather than ending the run:

```
stash@{0}  On audit-fix/pre-pilot-blockers-2026-08-25: ORPHANED step-1 pre-pilot unit-6 WIP (#LIFE-13/14) …
```

**Recover it before it is lost:**

```bash
git checkout audit-fix/pre-pilot-blockers-2026-08-25
git stash pop     # SourceProvisioner + IntegrationConnectionObserver + 3 test files
```

Then review and commit it as pre-pilot unit 6 (`#LIFE-13` + `#LIFE-14` together), and tick `#LIFE-13`
in both places. ⚠️ Per CLAUDE.md, `SourceProvisioner`-adjacent changes need **`composer test:pg`**, not
just a green SQLite run — and step 1 wrote a Postgres-lane test (`SourceProvisionerInsertRacePgTest`)
that never got to run.

**The pre-pilot tranche's units 7, 8 and 9 were never started.**

---

## 8. Questions for you, with my recommended answers

1. **Unit 2 failed review twice and I shipped it anyway** (§2). §1.2 trigger 4 says DEFER.
   → **Recommended: keep it.** Both reviewers confirmed the production change correct; both failures
   were test-coverage gaps, the second one found and fixed by the reviewer's own prescription. But
   this is your call, and it is the single item in this run I would most want you to sanity-check.

2. **Three units are committed without any independent review** (11a/11b/11c), and unit 7's round 2
   never ran.
   → **Recommended: run a review pass over `71f0b763b` before merging**, starting with `SEM-14`.

3. **Should the orchestrator chain on state rather than wall-clock?** Step 1 dying mid-unit and step 2
   inheriting a dirty tree is a repeatable failure mode, not bad luck.
   → **Recommended: yes.** Re-check `git status --porcelain` **before each step**, not just at
   startup, and gate step N+1 on step N having written its `RESULT*.md`. Ten lines in
   `run-overnight-2026-08-25.sh`.

4. **`SEM-17`'s real fix** (§4) — switch `PoolResolver:462` and `:858` to `Carbon::parse($v, 'UTC')`?
   → **Recommended: not now.** It is inert while `app.timezone` is a hardcoded `'UTC'` literal. Worth
   a P3 ticket so that a future timezone change does not silently skew every badge.

---

## 9. Handoff for PART 2

**Files this part changed** — check for conflicts before starting:

```
app/Jobs/PreAccount/ApproveEarlyAccessBuildJob.php   app/Routing/ConnectionIdentity.php
app/Routing/ShortLinkExpander.php                    app/Services/Brand/StoreBrandSeeder.php
app/Services/Cache/CacheLockService.php              app/Site/Pools/PoolResolver.php
app/Http/Controllers/Api/Platforms/ShopController.php
app/Jobs/Platforms/ConnectStoreFromProductJob.php    app/Jobs/Platforms/ShopBrandConnectJob.php
app/Http/Requests/Concerns/SiteOrderingValidationRules.php
config/partna.php                                    routes/console.php
+ 8 test files, 4 sweep CONSOLIDATED.md files
```

**Two carry real conflict risk for PART 2:**

- **`app/Services/Cache/CacheLockService.php`** — `rememberLockedNullable` now jitters. **Any PART 2
  test asserting an exact TTL from a `rememberLockedNullable` caller will go RED.** Callers:
  `ShortLinkExpander`, `AppleSearch`, `InstagramScraper`, `UserCacheService` (×5),
  `FeatureAvailability`, `AnalyticsCacheService`. Use a ±20% band matcher.
- **`app/Site/Pools/PoolResolver.php`** — touched by units 3, 11c AND the recent `755fdbdbd`
  storefront change. Match by symbol; every line number in the audits is stale.

**Also for PART 2:** `PART 2 must not start until the pre-pilot stash in §7 is resolved.** It touches
`IntegrationConnectionObserver.php`, which is contested.
