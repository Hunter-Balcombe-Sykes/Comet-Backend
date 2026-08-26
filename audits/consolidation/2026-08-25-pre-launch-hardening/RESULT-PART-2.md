# RESULT — Pre-launch hardening PART 2 · run of 2026-08-26

**Branch:** `audit-fix/pre-launch-hardening-2026-08-25` (shared with PART 1, continued — not rebased)
**Commit range:** `d373d4427..47d888e62` (14 commits)
**Nothing pushed. No PR opened.**

| Gate | Result |
|---|---|
| `php artisan test --parallel` | **9307 passed, 3 skipped, 0 failed** (32739 assertions, 77.6s) |
| `./vendor/bin/pint --test` (repo-wide) | **passed** |
| `composer analyse` | **[OK] No errors** (1413 files) |
| `php artisan checkpoint:scan` | **21 passed, 4 warnings, 0 failed** — all 4 pre-existing and unrelated |

**Baseline at start of PART 2: 9275 passed, 3 skipped, 0 failed — GREEN. No pre-existing red was
carried in, and none was introduced.** Net +32 tests.

---

## 1. Ledger

**16 finding IDs. 11 closed with code, 2 closed without code, 3 deferred. Every review that ran,
ran to a verdict — no unit shipped unreviewed.**

| Unit | Findings | Outcome | Independent review |
|---|---|---|---|
| 6 | `CACHE-2` ≡ `SCALE-7` | **DONE** `53c2abcf3` | **PASS** (1 nit, applied) |
| 8 | `#RANK-2` | **DONE** `abb74b0c4` | **PASS**, no defects |
| 9 | `#SEC-10` | **DONE** `d5bc1fbe1` | **PASS**, no defects |
| 10a | `#SEC-6` | **DEFERRED — trigger 2** `e98ed2e83` | **FAIL** — must-fix, work reverted |
| 10b | `#SEC-8` | **DONE** `27b2fc4dd` | **PASS** |
| 10c | `#SEC-13` | **DONE** `a9dc839bc` | **PASS** |
| 10d | `#SEC-11` | **WONTFIX — deliberate** `d373d4427` | n/a (no code) |
| 12 | `#TEST-20` | **DEFERRED — trigger 3** `e1f5b43b0` | n/a — plan itself is the deliverable |
| 14a | `#SEC-4` | **DONE** `e4efcc757` | **PASS** |
| 14b | `#SEC-9` | **DONE** `660be6ec8` | **PASS** |
| 14c | `#CCH-1` | **DONE (effect premise refuted)** `3204cc5aa` | **PASS** (1 must-fix doc, applied) |
| 14d | `CCH-10` | **DONE** `06fc3429e` | **PASS** (2 nits, 1 applied) |
| 14e | `#LIFE-9` | **DONE** `47d888e62` | **FAIL r1 → fixed → PASS r2** |
| 14f | `#LIFE-10` | **DONE** `e4efcc757` | **PASS** |
| 14g | `#PRIV-2` | **DEFERRED — trigger 7** `de68e4b2b` | n/a — pre-deferred by decision |
| 14h | `#SEC-5` | **CHECKLIST — state recorded** `d373d4427` | n/a (no code) |

**Every ID explicitly:** `CACHE-2` ✅ · `SCALE-7` ✅ · `#RANK-2` ✅ · `#SEC-10` ✅ · `#SEC-6` ⏸ ·
`#SEC-8` ✅ · `#SEC-13` ✅ · `#SEC-11` ✅ (WONTFIX) · `#TEST-20` ⏸ · `#SEC-4` ✅ · `#SEC-9` ✅ ·
`#CCH-1` ✅ · `CCH-10` ✅ · `#LIFE-9` ✅ · `#LIFE-10` ✅ · `#PRIV-2` ⏸ · `#SEC-5` ✅ (checklist).

`CACHE-2` ≡ `SCALE-7` was ticked in **both** places, per §6.

---

## 2. Unit 9 is DEFENCE-IN-DEPTH, not a closed live hole — say it that way

Required by §7, and it matters for how you describe this branch.

All five `ShopController` methods were, and remain, **structurally user-scoped** via
`$this->shop->store($user, …)` / `brandMap($user)`. A cross-tenant id **404s before authorization is
ever consulted.** The change adds a second lock; it cannot widen access.

**Was any denial reachable in test? No — and I did not paper over it with an assertion that can never
fail.** The tests instead force the policy to deny via a partial mock and assert the response flips to
403 (proving the call is on the request path), plus a `shouldNotReceive` test on an unknown id proving
404 short-circuits first. That was the accepted outcome for `#SEC-14` and it is the honest one here.

**One honest gap, recorded rather than hidden:** `anchorFor()` returns null for a store whose anchor
never minted, and all four sites then **skip** the authorize call. That is a verbatim copy of the
existing `removeBrand()` precedent, so it is consistent — but the second lock genuinely does not apply
to that class of store.

`tests/Authz/CrossTenantTest.php` — the strongest evidence here — is **genuinely skipped locally**
(needs a reachable Postgres host). Known documented gotcha, not introduced here. **It should run in CI
for this branch.**

---

## 3. The thing most worth your attention: 10a's decided fix was wrong

`#SEC-6` is the one unit where the EXECUTE file's own `DECIDED` block would have shipped a defect.
It was implemented, independently reviewed, **FAILED**, and reverted. Full write-up:
`DEFERRED-part2-unit-10a-sec-6.md`; the rejected work is preserved as
`DEFERRED-part2-unit-10a-sec-6.rejected.patch` (~80% reusable).

**Why it fails.** `SecretParams::isSecret()` matches on **either** a secret key segment **or** a secret
value *shape* (`JWT_PATTERN`, 18 vendor prefixes). `IriCanonicalizer::filterQuery()` **DROPS** whatever
it matches. `redactUrl()` keeps the key and replaces the value with the literal `[redacted]`, which
matches **neither** pattern. So a param with an innocuous key and a secret-shaped value flips from
dropped to **kept**:

```
canonical(RAW):            .../dest?other=1                      ← code dropped, correct
canonical(REDACTED first): .../dest?code=%5Bredacted%5D&other=1   ← code kept, polluted
```

`canonical` is a **live** fallback for `SourceReconciler`'s `identifier` (`LinkProjector` returns
`identifier: null` for every host/path-only detector), and `identifier` backs
`idx_source_intents_live`, `UNIQUE(user_id, surface_key, identifier)`. Worse: every redacted value
collapses to the same literal, so two different destinations differing only in that param **collide on
one fabricated identifier**.

I verified this against `isSecret()` and `filterQuery()` myself rather than taking the reviewer's word.

**Two things the attempt got right that must survive any retry:**
- It found a real trap: `redactUrl()` returns `''` on >8KB input or a PCRE error, and
  `rememberLockedNullable` treats `''` as an ordinary value — so a `''` would have been cached at the
  **24-hour** success TTL, and `expandIfShort()`'s `!== ''` guard would then serve the *un-expanded*
  URL for a day.
- **That guard is code-correct but TEST-UNCOVERED.** Loosening it left all 13 tests green.

Three fix options are written up with a recommendation (teach `isSecret()` to recognise its own
placeholder, so `filterQuery` drops it and `canonical` becomes byte-identical between paths).

---

## 4. Deferrals — all three have a written plan

**`#SEC-6`** → `DEFERRED-part2-unit-10a-sec-6.md` (+ `.rejected.patch`). See §3.

**`#TEST-20`** → `DEFERRED-part2-unit-12-test-20.md` (trigger 3). The two arms are **not** the same
pattern, which is what made the unit look S:
- **Reservations is provably acyclic and shovel-ready** — full plan, `$default` semantics and test
  shape are in the doc as *12a*.
- **Ordering is degenerate.** `GoogleBusinessAutoSync:545` already holds
  `platformConnectionLock('online-ordering')` when it calls `LinkRouter::routeOrdering` at `:602`.
  `Cache::lock` is **not reentrant**, so taking the key that would actually fix the race self-deadlocks
  — every Google ordering seed would silently degrade to the contended path after a 3s block. The
  per-brand alternative avoids the deadlock but excludes nothing. The only real fix is hoisting GB's
  lock scope: a reorder in another class.
It would also break a written rule (`SuggestionsController:327-341`): there is deliberately **no**
`platformConnectionLock → reservationsXorLock` edge in this codebase.
Harm is a **duplicate button, self-healable by disconnecting** — no data loss.

**`#PRIV-2`** → `DEFERRED-part2-unit-14g-priv2.md` (trigger 7, pre-deferred by decision). Key finding:
**`resolved_at` is NULL on every open case**, so the existing predicate cannot simply be widened — a
second pass needs a different time anchor, which is a design decision. Also: `moderation:sla-scan` is
**alert-only** (46 lines, zero writes), so nothing closes a stuck case today. And on an OPEN case
`reason_details`/`signal_data` **are** the report — nulling them empties the case. Recommends 18
months, contact-only erase; also flags that an auto-close would close the finding with **no new
retention policy at all**.

---

## 5. Where the EXECUTE file's own guidance was wrong

Recording these because the same paraphrases will be reused if these findings are re-filed.

1. **§4 unit 12 names the wrong file.** It describes the unlocked seeders as living in
   `BuildsAutoSyncFindings`. They are in **`LinkRouter`**, which *uses* that trait — so the lock helper
   was already in scope. The §2 probe points at where the helper is *defined*, not where a fix goes.
2. **"`LinkRouter` has no `Cache::lock` anywhere" is FALSE.** `LinkRouter.php:304` already takes
   `withBookingXorLock`; a `Cache::lock` grep misses it because it goes through a trait helper under a
   third name. **I repeated this error to the user before the planner caught it.** `LinkRouter` is
   already a participant in the lock graph — which is exactly why its `seedBooking` arm is fine and
   the other two are not.
3. **10a's `DECIDED` block would have shipped a defect** (§3).
4. **`ReservationsController` no longer exists** (deleted 2026-08-19 with the pseudo-platform lane).
   Two in-repo comments still cite it as a lock holder: `BuildsAutoSyncFindings.php:268` and
   `ManagesIntegrationConnection.php:555`.
5. **§4 unit 12's SQLite worry does not apply.** These are `Cache::lock` on `CACHE_STORE=array`, not
   Postgres advisory locks — `AutoSyncSeederLockTest.php:72-92` already proves contention in the
   Feature lane. No `tests/Postgres/` work was owed, and none was done.
6. Line numbers in `#SEC-4` and `#LIFE-10` were stale; matched by symbol per the house rule.

---

## 6. `#CCH-1` is closed but its EFFECT premise is refuted — read before re-filing

The mechanism the finding describes is real and the explicit invalidation now exists. **But it does not
produce a second edge purge, and it did not need to.** `CloudflareCachePurgeJob` is `ShouldBeUnique`
with a ~35s coalesce window whose lock is taken against the **real** cache store (`Queue::fake()` does
not bypass it), so the same-handle, same-request dispatch is swallowed every time. The race was already
covered: that job's `handle()` schedules a follow-up purge 15s after **its own execution**, long after
this synchronous strip has committed, and `purgeHandle()` evicts rather than reading `display_settings`.

The call is kept as the invalidation CLAUDE.md requires of a non-Eloquent write, and the **code comment
now states all of this** so the next reader does not assume it guarantees a purge.

---

## 7. Surfaced, not worked

- **`ActionSlots::resolve()` has the SAME collision bug unit 8 just fixed in `PoolOrdering`.** Its
  `$locks[$slot['position']] = $slot['id']` silently overwrites on a duplicate position and does **not**
  report it in `unavailable`. Out of scope for `#RANK-2` (which is pool ordering), but it is the
  identical defect in the file that supplied the fix pattern. Worth a P3 ticket.
- **`#SEC-9`'s new ability is guarded only by its own test.** Making the policy return `true`
  unconditionally is caught by `AcceptGoogleListingAuthorizationTest` alone — neither
  `PolicyCoverageTest` nor `InlineAuthBypassGuardTest` notices, as neither is designed to catch a
  per-ability logic bypass. A general observation about those sweeps, not a defect here.
- **`Response::deny($msg, 403)`'s second argument is `$code`, not `$status`.** It renders 403 via the
  `AccessDeniedHttpException` branch, so the outcome is right but not for the reason the code reads
  like. Pre-existing convention in `IntegrationConnectionPolicy`, not introduced here.
- **`tests/fixtures/Routing/corpus-negatives.php:177`** labels its over-length case
  `'reason' => 'malformed'` where the code returns `'too_long'`. Cosmetic — the corpus test only
  interpolates the label into a message, never asserts it. Pre-existing.
- **14d's per-block `service_ids` bound of 200** has no matching config cap (only `services_max = 500`).
  A professional filing >200 services under one category would 422 on reorder for that block. Narrow,
  but real.
- **14d's `blockSeconds: 45`** can hold a PHP-FPM worker. Acceptable because the path is owner-scoped
  and low-fan-in — unlike `ShortLinkExpander`, which chose `2` because it is public. Worth watching.
- **`#LIFE-9`'s new log line has no test asserting it fires.** Behaviour is mutation-proved; the log is
  code-reviewed on both paths.
- **The orphaned pre-pilot stash (`stash@{0}`) is STILL UNRESOLVED.** PART 1 §7 flagged it and warned
  PART 2 must not start until it was. **I checked and the conflict is not real:** the stash's hunks are
  in `syncIngestSource()` (~:338) and `maybeRunEagerly()` (~:393); unit 14c's hunk is at ~:177-206,
  ~150 lines away, so a later `git stash pop` merges cleanly. Verified again after 14c landed. **It
  still needs recovering** — see PART 1 §7 for the procedure.

---

## 8. A mistake I made and corrected

My `## Progress` re-derivation helper had a regex that required the tier adjacent to the bold ID
(`** · P2`). It therefore skipped `- [x] **#SCALE-12** — WONTFIX · P2 —` and wrote **"6 of 15" over a
correct "7 of 16"** in the overnight-run *Database & Queue Scaling* block — the very block PART 1 had
independently corrected. I caught it because the denominator moved in a section I had not touched,
audited every checkbox line in all five files for parser misses, widened the regex, and re-derived. No
other block was affected. The helper is at `.audit-work/pre-launch-hardening/progress.py`.

PART 1's finding that this arithmetic has drifted repo-wide is confirmed: re-derivation also corrected
a stale "P3 Low: 0 of 8" against 7 real checkboxes in a section unrelated to this work.

---

## 9. Questions for you, with my recommended answers

1. **`#SEC-6` (10a) — which of the three fix options?**
   → **Recommended: Option A** — teach `SecretParams::isSecret()` to recognise its own `[redacted]`
   placeholder, so `filterQuery()` drops it and `canonical` becomes byte-identical between the redacted
   and raw paths. ~2 lines, fixes the collision at its root, and the rejected patch then applies almost
   as-is. Gate it on a full routing-corpus re-run, since `isSecret` is consumed across the whole lane.
   Fallback is Option B (skip the cache for secret-bearing expansions) if a reviewer objects to the
   coupling. Either way, add a test for the `MAX_LENGTH`/PCRE `''` path — it is currently uncovered.

2. **`#TEST-20` (12) — ship *12a* (reservations) alone?**
   → **Recommended: yes, but as its own reviewed unit, not tacked on.** It is proven acyclic and S. I
   deliberately did **not** ship it here: §4 set an explicit low bar for DEFER on this unit, and
   shipping half a concurrency change unattended against that instruction was not my call. For *12b*
   (ordering), recommend **WONTFIX before launch** with the analysis attached, and the lock-scope hoist
   after.

3. **`#PRIV-2` (14g) — window and shape?**
   → **Recommended: 18 months, contact-only erase (`reporter_email`), its own config key.** But first
   consider the alternative in the doc: an auto-close on `moderation:sla-scan` would close this finding
   with **no new retention policy at all**, which is strictly less machinery to defend.

4. **`#SEC-5` (14h) — anything to do now?** → **No.** TOTP enrolment is a future phase, so `false` is
   correct today. Flipping it now would lock every owner out of their own profile edits, since no
   end-user can reach `aal2` at all.

5. **Should `tests/Authz/CrossTenantTest.php` be made loud when it skips?** It is the strongest
   evidence for unit 9 and it silently no-ops locally. → **Recommended: yes** — a skip that looks like
   a pass is how `#SEC-10`-class findings survive a green run.

---

## 10. Handoff for PART 3

**Files this part changed** — check for conflicts before starting:

```
app/Console/Commands/BackfillPreviousWebsiteContentScanCommand.php
app/Http/Controllers/Api/Platforms/ShopController.php
app/Http/Controllers/Api/Routing/SuggestionsController.php
app/Http/Controllers/Api/Staff/UserSiteManagement/StaffPreAccountBuildController.php
app/Http/Requests/Api/{User,Staff/UserSite}/Services/Reorder*Request.php   (4 files)
app/Ingest/Connectors/FreshaConnector.php      app/Ingest/Projection/FreshaServiceProjector.php
app/Jobs/Platforms/ScanPreviousWebsiteContentJob.php
app/Observers/Core/IntegrationConnectionObserver.php
app/Policies/IntegrationConnectionPolicy.php   app/Routing/IriCanonicalizer.php
app/Services/Cache/CacheKeyGenerator.php
app/Site/Pools/PoolOrdering.php                app/Site/Pools/PoolResolver.php
config/partna.php   .env.example   docs/api.md   docs/wire-changes/2026-08-26-…md
tests/Support/Architecture/RawCacheCallScanner.php
+ 11 test files, 5 sweep CONSOLIDATED.md files, 3 DEFERRED docs + 1 .patch
```

**Four carry real conflict risk for PART 3:**

- **`app/Site/Pools/PoolOrdering.php` / `PoolResolver.php`** — `applyLocks()` and
  `applyLocksPerCollection()` now return **`{items, unavailable}`**, not a bare list. Any PART 3 code or
  test calling them expecting a list **will break**. `PoolResolver` gained `unavailablePoolLocks`.
- **`app/Http/Controllers/Api/Platforms/ShopController.php`** — touched by PART 1 **and** by units 9
  and 14d. Match by symbol; every line number in the audits is stale.
- **`app/Ingest/Connectors/FreshaConnector.php`** — two units' hunks in one file, plus a new
  `MAX_TEXT_LENGTH` const now referenced from `FreshaServiceProjector`.
- **`app/Observers/Core/IntegrationConnectionObserver.php`** — still contested with the unresolved
  pre-pilot stash. My hunk is at ~:177-206 only; **do not touch `syncIngestSource()` or
  `maybeRunEagerly()`** or the stash will conflict.

**Also:** `config/partna.php` gained `pre_account.batch_time_budget_seconds`; `.env.example` gained
`PARTNA_PRE_ACCOUNT_BATCH_TIME_BUDGET_SECONDS`. `CacheKeyGenerator` gained
`websiteScanSubJobDispatched()`. `RawCacheCallScanner::ALLOWLIST` gained one entry.

**PART 3 must not be started in this checkout while any other PART is running** (§0).
