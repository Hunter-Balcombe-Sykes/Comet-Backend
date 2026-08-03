# Assurance coverage-gap review — CONSOLIDATED — 2026-08-03

**25 findings on the assurance surface itself: which lanes gate, which assertions never run, and
which guards have rotted into always-passing.** Not an `audit.sh` run — this reads *assertions and
lanes*, not code defects, so the usual lens machinery does not apply.

Method: `scripts/audit/coverage-gap-prompt.md`, 8 parallel surface-family agents (Sonnet),
synthesised and spot-verified in the main session.

---

## Execution policy  (how `execute audit` runs this file)

Per `scripts/audit/fix-flow.md`, these three values govern every unit worked out of this file:

| Step | Model |
|---|---|
| **Plan** | Opus |
| **Implement** | Sonnet — escalate to Opus for `COV-LANE-1` (18-file move, real Postgres semantics) |
| **Review** | Sonnet — always a **separate** instance from the implementer |

**Blocker gate** (pause for Josh's sign-off before implementing): any P0 unit, or any unit touching
auth/authorization, money, a DB migration, or graded L/XL. Gated units are marked **⛔ GATE** below.

**Two rules specific to this file:**

> **1. Premise verification is already done — do not redo it, do not skip it either.** Every finding
> below carries the command or file:line that proved it, verified in the main session on 2026-08-03,
> not taken on a subagent's word. Two subagent claims were *rejected* during that pass (see
> `## Rejected during verification`). Start from the recorded evidence; re-run the one-line repro
> where one is given before writing code. If a finding no longer reproduces, tick it **DEAD** with a
> one-line note.

> **2. A guard fix is not done until it has a positive control.** `COV-GUARD-*` findings are all
> guards that pass while guarding nothing. Repairing the pattern is half the unit; the other half is
> a test that feeds the guard a known-bad sample and asserts it goes red. `DsarAllowlistCoverageTest`
> and `PublicAllowlistCoverageTest` are the in-repo pattern to copy. **A guard fix landing without a
> positive control should FAIL review.**

---

## Suggested Bundled Sessions

**Seven units, worked sequentially** — never in parallel (`fix-flow.md` §1: a later fix may depend on
an earlier one, and parallel edits to the same files collide). Each unit is ONE
plan → implement → independent review → tick → commit cycle covering all of its findings together.

Order — P0 first, then P1, then P2, with standalone items interleaved at their own tier
(`fix-flow.md` §0.3):

`COV-GATE` (P0) → `COV-GUARD` (P1) → `COV-LANE` (P1) → `COV-PII` (P1) → `COV-JWT` (P1) →
`COV-TIMEOUT` (P1, standalone) → `COV-TAIL` (P2)

`COV-GATE` leads because it re-arms every assertion the other units add; without it they land in a
lane that cannot block a merge. `COV-GUARD` comes before `COV-LANE` because a rotted guard found
during the lane move is easier to reason about once the guard-repair pattern is already established.

| # | Unit | Findings | Effort | Gate |
|---|---|---|---|---|
| 1 | **`COV-GATE`** — branch protection | `COV-GATE-1`, `COV-GATE-2` | XS | ⛔ **Standalone, delegated** — repo settings, not code |
| 2 | **`COV-GUARD`** — repair the vacuous guards | `COV-GUARD-1` … `COV-GUARD-5` | M | — |
| 3 | **`COV-LANE`** — put sleeping assertions in a real lane | `COV-LANE-1`, `COV-LANE-2`, `COV-LANE-3` | L | ⛔ **GATE** (L/XL) |
| 4 | **`COV-PII`** — route-level staff PII proof | `COV-PII-1`, `COV-PII-2` | S | ⛔ **GATE** (auth) |
| 5 | **`COV-JWT`** — token edge cases | `COV-JWT-1` … `COV-JWT-3` | S | ⛔ **GATE** (auth) |
| 6 | **`COV-TIMEOUT`** — unbounded outbound call | `COV-TIMEOUT-1` | XS | ⛔ **Standalone** — only runtime-behaviour change in this file |
| 7 | **`COV-TAIL`** — P2 absorb | `COV-TAIL-1` … `COV-TAIL-5`, `COV-TAIL-7` … `COV-TAIL-10` | M | — |

**If `COV-TAIL` runs long, split it.** `COV-TAIL-5` (a new `phpunit.redis.xml` lane against a CI Redis
container) is the one member that can outgrow the bundle on its own — promote it to its own unit rather
than letting it stall the other eight.

## Standalone — do NOT bundle

- **`COV-GATE-1` / `COV-GATE-2`** — GitHub branch-protection settings. Not a diff; nothing to review.
  Josh applies these (or an admin `gh api -X PUT`). Tick **on handoff**, and say so in the tick —
  precedent: `LC-PROD-ENV` in the 2026-07-30 consolidation was ticked on handoff, not on verified state.
- **`COV-TIMEOUT-1`** — a one-line production code fix on the account-deletion path, reachable from a
  live staff endpoint. Everything else in this file is test/CI work; this one is not, and it is the only
  finding here that changes runtime behaviour. Own branch, own review.

---

## Findings at a glance

### P0 (2)
- [x] `COV-GATE-1` — **DONE 2026-08-03.** `test` added to `development`'s required status checks. Verified green first (all 9 jobs `success` on run `30803229194`) so the change could not deadlock merges. Applied via `PATCH .../protection/required_status_checks` — the narrow sub-resource endpoint, *not* a full protection `PUT`, which replaces the whole object and drops anything omitted. Full re-read confirms `strict: true`, `enforce_admins: false`, force-push/deletion settings all preserved.
- [x] `COV-GATE-2` — **DONE 2026-08-03.** `worker-static` added in the same call. Required contexts now: `schema-tests, postgres-tests, worker-tests, schema-drift, outbound-http-guard, supply-chain, checkpoint-suppressions, test, worker-static`. ⚠️ `enforce_admins` remains **false** — admins still bypass all nine with a printed warning. Left as-is deliberately: turning it on is a separate decision about how you deploy, not part of this finding.

### P1 (13)
- [x] `COV-LANE-1` — **DONE 2026-08-03.** All 18 files migrated and un-skipped; **194 passing in the `schema-tests` lane**, stable across back-to-back runs. ⚠️ **The audit's prescribed destination was WRONG**: `tests/Postgres/` runs against a **bare `postgres:16` container with no migrations applied** (`ci.yml:353-365` never calls `apply-migrations.sh`), so moving them there would have reddened a required check for 14+ files. Correct destination is `tests/Schema/` + `SchemaTestCase`, run by `schema-tests` *after* migrations — **zero CI changes needed**. ⚠️ **Count corrected: 41 test cases skipped in every lane, not 37**, and those files also held **26 tests that passed under SQLite**, so a naive whole-file move would have LOST coverage. Zero lost in the end. Every file was proven green against a locally migrated container *before* being moved. Four fixture bugs surfaced that SQLite structurally cannot catch — see the drift note below
- [x] `COV-LANE-2` — **DONE 2026-08-03.** Deleted, per sign-off. Fully redundant: `TurnstileProviderTest` already covers the provider end-to-end with `Http::fake()` (request shape, success/failure/expired parse, 503, `ConnectionException`, timeout, `driverName()`), while the integration test asserted only `$result->success === true` — using Cloudflare's documented **always-pass** test key, so it would stay green even if `TurnstileProvider` broke. It also carried a live-network `markTestSkipped`, and wiring that into a gating lane would have produced an intermittently-vacuous required check that fires on Cloudflare incidents rather than Partna regressions. `CI_RUN_INTEGRATION` now has zero references repo-wide. Removing the directory broke `AuditPipelineIntegrityTest` (a dead chunk path in `scripts/audit/audit.sh`) — caught and fixed in the same unit
- [x] `COV-GUARD-1` — **DONE 2026-08-03.** Both vacuous sites converted to `assertStringNotContainsString`. The suite-wide sweep the finding asked for turned up **a third vacuous site the audit missed** — `StrandedPendingWindowLockstepTest.php:41` — fixed the same way. Seven further *weak* (not vacuous) multi-needle negations split to one needle per call. Positive controls added to both lockstep tests. `grep -rn "not->toContain([^)]*," tests/` now returns only the two documented false positives in `PiiLogHygieneSweepTest.php:136-137` (comma inside a string literal — correct as written)
- [x] `COV-GUARD-2` — **DONE 2026-08-03.** Both line-oriented CI greps replaced with token-based scanners (`ControllerAbortScanner`, `RawCacheCallScanner`), which have no concept of a line and so cannot be evaded by wrapping. Positive controls are `.stub` fixtures named **individually**, not counted — a count assertion would pass on 4 single-line hits and 0 wrapped hits, i.e. the bug itself. All 30 GS-1 allowlist pathspecs migrated with every justification comment verbatim (reviewer diffed them); 6 entries marked `// STALE`, none deleted
- [x] `COV-GUARD-3` — **CLOSED NARROWED-DEAD 2026-08-03.** No threshold number changed; the decision and its arithmetic recorded as a comment in `config.js`. A `p(99)` gate is either flaky (below 890 → fails run A on a one-in-2100 TTFB event) or dead (above 890 → cannot fire before `p(95)<500` does, given server p95 of 91ms). No guard added, so **no positive control applies** — this is not a missing control. Baseline comparison stays with `COV-TAIL-10` per the audit's own cross-reference
- [x] `COV-GUARD-4` — **DONE 2026-08-03.** All three sweeps now assert the denominator via `SweepGuard::assertDenominator()` before asserting the numerator; model discovery unified into `ModelSweep`, which removes `SoftDeletePurgeCoverageTest`'s hardcoded `'App\Models\'`. Behaviour-preserving (SoftDeletes set stays at exactly 11). Floors 50 / 50 / 10 / 8, each below the measured value with stated headroom
- [x] `COV-GUARD-5` — **DONE 2026-08-03.** Both sweeps floored at 50, each with a denominator control *and* an offender control built on an **unregistered** `Illuminate\Routing\Route` (a registered probe would persist in the collection and redden the real sweep). ⚠️ **Premise corrected:** "5 routes currently match" is wrong — measured live, **106** routes match the jwt filter and **109** carry a `platform` default. The 5 was a count of declaration sites in `routes/`, not of matched routes. The finding stands; only the number was wrong
- [x] `COV-PII-1` — **DONE 2026-08-03.** ⚠️ **The audit's prescribed assertion was VACUOUS and was NOT written.** It says assert `primary_email` *absent*, but `UserStaffResource` emits redacted PII as **present-and-null**, and `assertJsonPath($path, null)` resolves via `data_get()`, **which returns null for a missing key** — so that assertion passes against `{}`, against a renamed key, and against an empty 200. Replaced with a **four-leg control**: key-exists (`assertJsonStructure`), value-redacted, non-PII-survives (incl. `public_contact_email`, the near-twin of gated `primary_email`), and an **admin positive control** asserting the real seeded values, plus a raw-body high-entropy-secret check using `assertStringNotContainsString`. ⚠️ **Second correction:** `actingAsStaff()` **stubs out `EnsurePartnaStaff`** — the very middleware the finding says is bypassed — so the prescribed fix would not have closed the gap it names. Added a real-middleware pair via `actingAsUser()` + a seeded `core.partna_staff` row, exercising role resolution by `auth_user_id` for the first time. **All 10 gated fields asserted, not the 3 the finding named.** Falsification: flipping `$showPii = true` reddened 3 of 9 cases. **No leak found** — the real middleware resolved the same role as the stub
- [x] `COV-PII-2` — **DONE 2026-08-03.** New `StaffEmailSubscriberExportAuthTest`: support→403, admin→200 asserting real CSV content (the seeded email + exact header row), nonexistent UUID→404 (route-model binding proof), and the sibling `index()` pinned as any-staff in both directions. The admin leg is the control — without it a 403 could be a route typo, a `whereUuid` miss, or a 404 misread. All four existing direct-controller files KEPT per sign-off, each given a one-line pointer docblock
- [x] `COV-JWT-1` — **DONE 2026-08-03.** ⚠️ **The audit's prescribed value was WRONG.** `firebase/php-jwt` checks expiry as `($now - $leeway) >= $exp`, so with the 60s leeway `exp => time() - 10` returns **200, not 401** — verified through the real kernel. Uses `time() - 120`. Driven route-level on an ad-hoc `supabase.jwt`-only route so a 401 cannot be confused with one from `RequireAal2`
- [x] `COV-JWT-2` — **DONE 2026-08-03.** Both directions, on **both claims**: `nbf +30` → 200, `nbf +300` → 401, and — the half the finding didn't name — `exp -10` → **200**, since the leeway applies to `exp` too. Anti-vacuity: every 401 case differs from the asserted-body baseline by exactly one claim and carries a log-spy `reason` assertion plus a `shouldNotReceive` expectation pinning where in the pipeline the rejection happened. Falsification: removing `JWT::$leeway` reddens the in-leeway cases; disabling the `kid` throw reddens the missing-`kid` case
- [ ] `COV-TIMEOUT-1` — `AccountDeletionService.php:1400` has no `->timeout()` and is reachable synchronously from a staff endpoint
- [x] `COV-LANE-3` — **DONE 2026-08-03.** ⚠️ **The audit's prescribed fix would NOT have worked.** `DatabaseServiceProvider::boot()` returns early on `runningUnitTests()`, which is true in `phpunit.pg.xml` too (it sets `APP_ENV=testing` and runs under the console SAPI) — so a naive `SHOW statement_timeout` test would have read Postgres's own default of `0` and asserted nothing. Fixed by narrowing the gate with an opt-in `DB_APPLY_TIMEOUTS_IN_TESTS=1`, set only in the `postgres-tests` job; production behaviour provably unchanged (the new clause is ANDed with `runningUnitTests()`, false at real app boot). New `tests/Postgres/DatabaseTimeoutsTest.php` with a **mandatory positive control** — re-boots the provider with the timeout overridden to a distinct value and asserts `SHOW` reflects it, so the test cannot pass against a hardcoded constant or a coincidental server default

### P2 (10)
- [x] `COV-JWT-3` — **DONE 2026-08-03.** Covered as case 4 of the new claim-timing test. Its control is unusually clean: `$cacheLock->shouldNotReceive('rememberLocked')`, because the throw at `:359` precedes `resolveSigningKey`, so a 401 from any other cause would have consulted the JWKS first
- [ ] `COV-TAIL-1` — 7 files carry an undocumented Redis-signature PHPStan suppression that one file documents
- [ ] `COV-TAIL-2` — PHPStan level 5 cannot see null dereferences; the codebase is nullable-heavy
- [ ] `COV-TAIL-3` — the TTL guard matches call syntax, not arity: 2-arg `Cache::put` forwards to `forever()` unseen
- [ ] `COV-TAIL-4` — `ServiceReorderPurgeTest` proves the compensating touch, never that the key vanished
- [ ] `COV-TAIL-5` — no lane exercises real Redis; `Cache::lock()` distributed correctness unverified full stop
- [ ] `COV-TAIL-7` — ~15 platform routes probe a nonexistent id, never a real second owner's row
- [ ] `COV-TAIL-8` — the authenticated DAST lane has no cadence ("Never CI, never cron")
- [ ] `COV-TAIL-9` — Postgres and R2 have no drill; Supavisor pool exhaustion has a runbook and no signal
- [ ] `COV-TAIL-10` — k6 covers 3 of ~28 public routes, 0 of ~380 authenticated; 3 of 4 scripts never run

## Progress

| Unit | Done |
|---|---|
| `COV-GATE` | **2 / 2** ✅ |
| `COV-GUARD` | **5 / 5** ✅ |
| `COV-LANE` | **3 / 3** ✅ |
| `COV-PII` | **2 / 2** ✅ |
| `COV-JWT` | **3 / 3** ✅ |
| `COV-TIMEOUT` | 0 / 1 |
| `COV-TAIL` | 0 / 9 |
| **Total** | **15 / 25** |

---

## Lane inventory (context for every finding below)

| Lane | Defined at | Runs | Gates `development`? |
|---|---|---|---|
| Default suite (SQLite) | `phpunit.xml` → `tests/Unit`, `tests/Feature` | CI job `test` | **NO** |
| Postgres lane | `phpunit.pg.xml` → `tests/Postgres` only | `postgres-tests` | YES |
| Applied schema | `phpunit.schema.xml` → `tests/Schema` only | `schema-tests` | YES |
| Authz matrix | `phpunit.authz.xml` → `tests/Authz` only | 2nd step of `schema-tests` | YES |
| Schema drift / SSRF / supply chain / checkpoint | own jobs | 4 jobs | YES |
| Worker vitest | `cloudflare-worker/` | `worker-tests` | YES |
| Worker static | tsc + types-diff + eslint + biome | `worker-static` | **NO** |
| PHPStan / Pint / guards / Vigil / Checkpoint scan | inside `composer test` | `test` | **NO** |
| DAST edge (unauth) | `scripts/dast/edge/` | weekly cron | no |
| DAST active (authenticated) | `scripts/dast/active/` | **manual only** | no |
| k6 load | `scripts/launch-check/k6/` | **manual only**, in no CI | no |
| Drills 01–04 | `docs/runbooks/drills/` | manual | no |

Required contexts on `development`, verbatim from `gh api`:
`schema-tests, postgres-tests, worker-tests, schema-drift, outbound-http-guard, supply-chain,
checkpoint-suppressions`. `production` → `404 Branch not protected` (deliberate, push-to-deploy).
`enforce_admins: false`.

---

## Findings

### `COV-GATE-1` · **P0** · the `test` CI job gates nothing
**Plain English.** The tests that catch a broken permission check can go red and the merge button
stays green.

**Technical.** `test` is absent from `required_status_checks.contexts` (verified live). It hosts
`composer test` (full Unit+Feature), PHPStan, Pint, all three `guard:*` scripts, the GS-1 raw-`Cache::`
grep, the inline-403 grep, Vigil, and Checkpoint's new-finding scan. `development` fast-forwards to
`production`, which has no protection at all.

**Fix.** Add `test` to required contexts. If its ~15-minute runtime is why it was omitted, split the
sub-4-second synchronous gates (PHPStan, Pint, guards, GS-1, inline-403 — timings from the pre-push
hook's own header) into their own required job. **That pattern already exists**: `ci.yml:585-661`
pulls `schema-drift` and `outbound-http-guard` into dedicated jobs with a comment explaining why
sharing a job with the slow suite is unsafe. The reasoning was applied twice and never generalised.

**Repro.** `gh api repos/:owner/:repo/branches/development/protection --jq '.required_status_checks.contexts'`

---

### `COV-GATE-2` · **P0** · `worker-static` is not required
Added 2026-08-03 (`ci.yml:378`), never added to branch protection — CI side shipped, settings side
didn't. `worker-tests` **is** required; this is the same call. The Worker is the public wire for 100%
of `<handle>.partna.au`.

---

### `COV-LANE-1` · **P1** · 37 assertions that have never executed  ⛔ GATE (L)
**Plain English.** Eighteen test files try to skip themselves on SQLite and run on Postgres. The check
they use can only ever say "not Postgres", so they skip every time, in every lane.

**Technical.** `tests/TestCase.php:21-24` merges the **sqlite** config into the `pgsql` connection slot,
overwriting only `name` — `driver` stays `sqlite`:
```php
config(['database.connections.pgsql' => array_merge(
    config('database.connections.sqlite'), ['name' => 'pgsql']
)]);
```
So `DB::connection('pgsql')->getDriverName()` returns `'sqlite'` in every test extending this base
class. The redirect itself is correct and load-bearing (`BaseModel` forces all models onto `pgsql`;
without it the suite DNS-resolves real Supabase). The bug is 18 files using a driver check as a
Postgres-detection gate. They live in `tests/Feature`/`tests/Unit`, which only `phpunit.xml` includes;
the three real-Postgres configs point exclusively at `tests/Postgres`, `tests/Schema`, `tests/Authz`.

**Files** (37 `markTestSkipped` sites):
`Analytics/QueryPlanTest`, `Api/User/SiteManagement/WriteDesignKitConcurrencyTest`,
`Bootstrap/SiteProvisioningSavepointTest`, `Console/PruneAuditPiiSnapshotsTest`,
`Console/PruneHandleAuditLogsTest`, `Database/DataExportSchemaParityTest`,
`Media/DesignSingletonMediaConcurrencyTest`, `Moderation/ContentReportServiceTest`,
`Moderation/ModerationStateColumnTest`, `Moderation/PublicReportAntiAbuseTest`,
`Moderation/QueueIndexPlanTest`, `Moderation/SchemaSmokeTest`, `Moderation/SiteMediaScanStateTest`,
`PreAccount/PreAccountBuildHandleRaceTest`, `PublicSite/BootstrapEmailRaceTest`,
`Requests/DesignKitRequestDriftTest`, `Staff/StaffUserSearchFiltersTest`,
`Unit/Analytics/AnalyticsQueryServiceBreakdownsTest`.

**Priority within the unit.** Start with the five race/concurrency suites — they exist *specifically*
because SQLite cannot express row locking or savepoints — and `DataExportSchemaParityTest`, which was
written after the 2026-07-19 production DSAR `42703` outage to stop it recurring.

**Fix.** Move to `tests/Postgres/` (base `PostgresTestCase`, config `phpunit.pg.xml`, CI job
`postgres-tests` — already required) and **delete the self-skip guard**, since that lane guarantees a
real connection. Expect real work: assertions that never ran may not pass first time, and that is the
point of the unit. **Do not "fix" a newly-failing assertion by re-adding a skip.**

**Precedent.** `tests/SchemaTestCase.php:23-30` documents this exact fault in `CheckConstraintsTest` —
"all 22 of its assertions skipped silently, in CI and locally, for as long as they existed." That file
was moved. These 18 were not: the fix was applied to the instance, not the class.

**Watch for.** 5 files in `tests/Postgres/*ConcurrencyTest.php` gate on `pcntl_fork`, and the
`postgres-tests` runner's PHP setup (`ci.yml:466`) doesn't list `pcntl`. **Unverified** whether it's
present by default — worth confirming during this unit, since it is the same bug shape one layer up.

> **RESOLVED 2026-08-03 — REFUTED, tick DEAD.** All 5 fork-based concurrency tests **run and pass** in
> CI. Evidence: `postgres-tests` job log from run `30809317492` (job `91672008154`, `development`)
> shows `✓` with real durations for `EffectLedgerConcurrencyTest`, `SourceSchedulerConcurrencyTest`
> and `StreamFailureCounterConcurrencyTest`; `Tests: 189 passed (903 assertions)`; no
> `markTestSkipped` output anywhere. Mechanism: `shivammathur/setup-php`'s `extensions:` input is
> **additive**, and `pcntl` is compiled into the CLI SAPI on the Ubuntu PHP builds it installs, so
> listing it would be a no-op. (Citation fix: `ci.yml:466` is `schema-tests`' "Copy env" step;
> `postgres-tests`' `setup-php` is at `ci.yml:369-374`.)
>
> **But the real version of this bug was found one layer up, and fixed.** `tests/PostgresTestCase.php`
> **skipped** rather than failed when the driver wasn't pgsql, when `getPdo()` threw, or when the
> database wasn't named `partna_test` — and `postgres-tests` set no required flag. PHPUnit exits 0 on
> an all-skipped run, so **if the `postgres:16` service container failed to come up, a REQUIRED status
> check reported green having executed zero assertions.** `SchemaTestCase` already solved this
> (`SCHEMA_LANE_REQUIRED=1`, `ci.yml:419`) and it had never been generalised — the same "fixed the
> instance, not the class" pattern as `COV-LANE-1` itself. Now fixed: `PG_LANE_REQUIRED=1` on the job
> plus the `unavailable()` treatment on the base class. Verified by pointing `DB_PORT` at a dead port
> — the lane now fails hard instead of skipping.

---

### `COV-LANE-2` · **P1** · `tests/Integration/` is dead twice over
`TurnstileIntegrationTest.php` is in no `<testsuite>` in any of the four configs, **and** self-gates on
`env('CI_RUN_INTEGRATION', false)` — a variable set in no workflow, script or `.env` in the repo
(`grep -rn "CI_RUN_INTEGRATION"` returns only its own two lines). Decide: wire it into a lane and set
the var, or delete it. Either is fine; leaving it is not.

---

### `COV-GUARD-1` · **P1** · `not->toContain($needle, $prose)` passes unconditionally
**Plain English.** A test meant to reject hand-copied error strings can never fail.

**Technical.** `tests/Unit/Platforms/ConnectErrorSentenceLockstepTest.php:96-97`. `toContain` is
variadic; `not->` invokes the **positive** matcher, which throws on the first needle it cannot find,
and the negation catches that throw and reports PASS. So it means **"not BOTH present"**. The second
argument is prose that never appears in source, so one needle is always missing, so it always passes.

**Repro (run in the main session 2026-08-03, throwaway test since deleted):**
```php
$source = 'THE FORBIDDEN LITERAL IS RIGHT HERE';
expect($source)->not->toContain('THE FORBIDDEN LITERAL', 'this prose is meant as a message');
// → PASS (2 assertions)
```

**Why it survived.** Unnegated, this trap produces a false *failure*, which announces itself — that is
how it was caught before. Negated it produces a false *pass*, which announces nothing.

**Fix.** `assertStringNotContainsString($needle, $source, $message)` — third param really is a message.
`CollectionLeakageTest` and `DataExportCoverageTest:139-144` already do this. **Then grep the whole
suite for `not->toContain` separately** — this is a distinct bug class from the positive form.

**Also check** `PageViewsMetricTest.php:71` — same mechanism, currently backed by an independent
`toHaveCount(3)` in the same test, so it is weak rather than vacuous. Fix it anyway while open.

---

### `COV-GUARD-2` · **P1** · the inline-403 guard is defeated by line wrapping
**Plain English.** The check stopping raw `abort(403)` calls is beaten by pressing Enter.

**Technical.** `.github/workflows/ci.yml:125` pipes `grep -rE "abort\(403|abort_unless|abort_if"` into
`grep "403"`. Both stages are line-oriented, so `403` must be on the same physical line as the function
name.

**Repro (run 2026-08-03):** single-line `abort_unless($a === $b, 403, "no")` → caught. The identical
call wrapped across four lines → **not caught**. Any real authorization message long enough to wrap
evades it; no adversarial intent needed.

Controllers currently have zero inline 403s, so nothing slips through today — but CLAUDE.md's claim
"CI enforces: inline 403 aborts fail build" does not hold for normally-formatted code.

**Fix.** Use a token-based scan (the repo already has one: `tests/Support/OutboundHttpScanner.php`
survives exactly this class of rot) or `grep -Pzo` for multiline. **Same blind spot exists in the GS-1
raw-`Cache::` grep at `ci.yml:161`** — fix both in this unit; Pint does not currently produce that
wrapping for `Cache::`, so it is latent there rather than live.

---

### `COV-GUARD-3` · ~~P1~~ → **MOSTLY DEAD** · k6 latency thresholds
> ⚠️ **REVISED 2026-08-03, after the finding was written.** A parallel session ran controls and
> refuted most of this. **Do not implement the original prescription — adding a `max` threshold
> would be actively wrong.** Read this whole entry before touching `config.js`.

**What I originally claimed.** `config.js:35-38` sets only `p(95)<500`, and the 3 Aug run showed
p99 1185ms / max 4563ms against a 31 Jul reference of 376 / 805 — a 3× tail regression that exited 0.

**Why that was wrong: n=1, no control.** Three instrumented runs the same day:

| Run | Condition | p50 | p95 | p99 | max |
|---|---|---|---|---|---|
| A (`-cold.json`) | cold, no warm-up | 140 | 242 | **1185** | **4563** |
| B (`-warm.json`) | 45s warm-up | 133 | 225 | **324** | 675 |
| C (`-cold2.json`) | cold, no warm-up | 134 | 219 | **294** | 550 |
| — | 31 Jul reference | — | — | 376 | 805 |

**Runs B and C have p99 *better* than the reference.** Only run A is elevated, and its 4.5s outlier
was a single TTFB-bound event mid-run (165–167s) that stalled `/api/health` at the same instant —
container-or-path, not application code. One event in ~2,100 requests, did not recur. I read run A
alone and called it a trend.

**Three sub-findings now DEAD:**
- ~~"3× p99 regression, unexamined"~~ — diagnosed; not a regression.
- ~~"the 3 Aug run was never transcribed"~~ — written up at
  `scripts/launch-check/k6/results/2026-08-03-baseline-warmup-comparison.md`.
- ~~"the uncommitted WARMUP scenario"~~ — committed at `cc5b2404`, opt-in via `-e WARMUP=45s`,
  default off so the recorded reference stays valid.

**What actually survives, narrowed:** `config.js` has no p99 threshold, and there is no automated
comparison of a run against the recorded baseline (overlaps `COV-TAIL-10`). That is real but small.

> **Figure correction, 2026-08-03 (execution).** The table above lists run A's p99 as **1185ms**. The
> committed writeup (`results/2026-08-03-baseline-warmup-comparison.md`) says **889.8ms**. Use 889.8 —
> the writeup is the primary source; the raw JSON is gitignored. The four observed p99s are therefore
> **293.9 / 320.7 / 376.0 / 889.8ms**, which is what the declined-threshold arithmetic below rests on.
>
> **Closed NARROWED-DEAD, no number changed.** A `p(99)` gate is flaky below 890 (it fails run A on a
> one-in-2100 TTFB event that hit the DB-free `/api/health` at the same instant) and dead above 890
> (the origin's own access log puts server p95 at 91ms and its worst request at 351ms over 970
> requests — a regression big enough to move p99 past 1000ms moves p50/p95 first, and `p(95)<500`
> already gates those). Tightening `p(95)` is defensible on the data — worst observed is 241.4 — but
> it is a policy change with **no possible positive control**: k6 runs by hand and nothing in CI
> executes `config.js`. Landing an unverifiable number into a file nothing runs automatically is the
> exact category of assurance this audit exists to remove. Deferred to `COV-TAIL-10`, which will
> actually run these scripts and can set it from more points. The reasoning is recorded in `config.js`
> so the next reader does not re-derive it from one run.

**Fix — read the constraints first:**
- **Never add a `max` threshold.** Identical conditions (A vs C) gave 4563ms and 550ms — an 8×
  spread. `max` samples a rare transport event and would produce a permanently flaky gate. Never
  quote k6 `max` as a Partna latency figure either.
- A `p(99)` threshold is defensible — but it would have failed run A on a non-reproducible transport
  event. If added, set it from observed spread across ≥3 runs, not from one.
- **p50/p95 held within ~8% across four runs and ARE trustworthy.** Prefer tightening those.
- The origin access log recorded **970 requests, none over 500ms**, while k6 reported 675/550 maxima
  in the same runs — the gap is transport, not the server. Split `waiting` (TTFB) from `receiving`
  before attributing any future tail finding to Partna.

**Genuinely open, inherited from that session, not mine to close here:**
`/api/public/config/social-platforms` never reaches the origin — 485 requests sent, 0 arrived
(`cf-cache-status: HIT`), so phase 1 was always a blended origin+edge measurement, contradicting the
"origin; no edge" claim in run 1's header. The profile route also shows an exact 50/50 200/304 split
at origin while k6 always receives a body; its `Vary` header duplicates `Accept-Encoding`, which is a
cache key. Mechanism not established.

---

### `COV-GUARD-4` · **P1** · three model sweeps have no denominator guard
`PolicyCoverageTest.php:117-145`, `DataExportCoverageTest.php:79-125`,
`SoftDeletePurgeCoverageTest.php:23-66` each `Finder`-sweep `app/Models`, `continue` past anything
`class_exists()` rejects, and assert only that the missing-list is `[]`. None asserts the input set is
non-empty — so a PSR-4/namespace move zeroes all three at once and they pass having examined nothing.
`SoftDeletePurgeCoverageTest.php:33` hardcodes `'App\Models\'` while the other two derive it from
`app_path()`, so a refactor can break one and not the others.

Dormant today (63 models discovered). `Aal2RouteCoverageTest.php:24` and
`MailableCategoryCoverageTest.php:37` get this right — the discipline exists in-suite, just unevenly.

**Fix.** `expect($modelFiles)->not->toBeEmpty(...)` plus a floor count, mirroring the sibling pattern.

---

### `COV-GUARD-5` · **P1** · two route sweeps, same missing guard
`EmailVerifiedRouteCoverageTest.php:38-75` and
`Platforms/Registry/RouteDefaultsCoverageTest.php:6-21` filter `Route::getRoutes()` and assert
`$offenders === []` with no non-empty check. Lower severity: `Route::gatherMiddleware()` is **not**
subject to the `gatherRouteMiddleware()`-before-first-request hazard (that one is about group
expansion; `supabase.jwt` is a plain alias), and 5 routes currently match. Same one-line fix.

---

### `COV-PII-1` · **P1** · staff PII redaction asserted only off-route  ⛔ GATE (auth)
**Plain English.** Nobody has proven a real request from a support-tier staff member actually gets
redacted data — only that the redaction logic is correct when called directly.

**Technical.** `StaffUserShowPiiTest.php:167,183`, `StaffUserControllerIndexShowTest.php`,
`StaffEmailSubscriberControllerTest.php:97,118,128`, `EmailSubscriberExportCapTest.php` all hand-build a
`Request`, attach `partna_staff` manually, and call `$controller->show(...)` / `->export(...)` directly
— bypassing `EnsurePartnaStaff`, the middleware that populates the very attribute the gate reads
(`StaffUserController.php:83-110`).

**Failure scenario.** `EnsurePartnaStaff` regresses (attribute key renamed, `isAdmin()` returns true for
support) → all four files stay green while a support-tier request returns another professional's
`primary_email`/`phone`/`admin_notes`.

**Note.** `StaffUnclaimedVisibilityTest.php:78,94` *does* hit `GET /api/staff/professionals/{id}` over
real HTTP, but asserts `pre_account_build` fields, not role-based redaction — so the endpoint has
route-level coverage of a different question. `tests/Authz/StaffBoundaryTest.php` fires all 104 staff
routes but asserts status codes only, never body content, so it cannot catch a support-tier staffer
receiving admin-tier PII in a 200.

**Fix.** `actingAsStaff($supportStaff)->getJson("/api/staff/professionals/{$pro->id}")` asserting
`primary_email` absent. This is the anti-pattern with a documented three-live-bug record in this repo.

---

### `COV-PII-2` · **P1** · the customer-email export has no route-level test at all  ⛔ GATE (auth)
`grep -rn "email-subscribers" tests/` returns **nothing** — the admin-only
`GET /api/staff/professionals/{id}/email-subscribers/export` (tagged `#PRIV-2`, emits a professional's
customers' email addresses as CSV) is exercised only via `$controller->export(...)`. No test drives the
route, so `staff.admin` role enforcement and route-model binding are unproven on this endpoint.

**Fix.** `actingAsStaff($supportStaff)->getJson(".../email-subscribers/export")->assertForbidden()`.

---

### `COV-JWT-1` · **P1** · expired-token rejection unasserted  ⛔ GATE (auth)
No test in `tests/Unit/Auth` or `tests/Feature/Auth` exercises `exp`. Rejection relies entirely on
`firebase/php-jwt`'s internal check, untested by this repo.
**Fix.** One case: RS256 JWT with `exp => time() - 10`, expect 401.

### `COV-JWT-2` · **P1** · `nbf` clock-skew leeway unasserted  ⛔ GATE (auth)
`VerifySupabaseJwt` sets a 60s leeway for clock skew; nothing proves it works, in either direction.
**Fix.** `nbf => time() + 30` (inside leeway) expect 200; `nbf => time() + 300` expect 401.

### `COV-JWT-3` · **P2** · missing-`kid` path untested
`VerifySupabaseJwt.php:358-360` throws on a missing `kid`; no test case reaches it.

---

### `COV-TIMEOUT-1` · **P1** · unbounded outbound call on the deletion path  ⛔ **Standalone**
**Plain English.** Deleting a user calls Supabase with no time limit. If Supabase hangs rather than
erroring, the staff request hangs with it.

**Technical.** `app/Services/User/AccountDeletionService.php:1400`:
```php
$response = Http::withHeaders([...])->delete("{$baseUrl}/auth/v1/admin/users/{$authUserId}");
```
No `->timeout()`. Reachable synchronously — `StaffUserController::forceDestroy()`
(`:340-347`) → `adminPurgeNow()` (`:558-603`) → `purge()` → this call, **no queue boundary**. The sibling
`SupabaseAdminService.php:166` uses `Http::timeout(config('supabase.http_timeout_seconds', 5))`.

**Why no test caught it.** The failure-*response* path is tested via `Http::fake()`
(`PurgePendingDeletionTest.php:67,84`), but `Http::fake()` cannot simulate a hang. This is a code fix,
not a test gap.

**Fix.** Add `->timeout(...)` matching the sibling service's config key.

---

### `COV-TAIL-1` · **P2** · 7 undocumented PHPStan suppressions of a documented exception class
`phpstan.neon:14-40` carries a reasoned `ignoreErrors` block explaining that `RedisStore::connection()`
returns `PhpRedisConnection`, so phpredis's extended `set($k, $v, 'EX', $ttl, 'NX')` signature looks
wrong to Larastan — scoped to `DailyCounterClaim.php`. The identical mismatch is silently baselined with
no comment in `PerTargetReportThrottle`, `VerifyBotToken`, `TokenRevocationService`, `CircuitBreaker`,
`EnquirySpamBlocklist`, `LiveStatusPoller`, `StreamingTokenManager`. A genuinely swapped `$ttl`/`'NX'`
in any of the seven would be camouflaged by accepted noise.
**Fix.** Move those entries out of `phpstan-baseline.neon` into `phpstan.neon`'s `ignoreErrors` with the
same one-line rationale. No code change.

### `COV-TAIL-2` · **P2** · PHPStan level 5 cannot see null dereferences
Proved with a synthetic file: a method returning `?Thing` called with `->real()` and no null check —
level 5 `[OK] No errors`, level 8 `Cannot call method real() on App\Thing|null`. The codebase is
nullable-heavy in exactly this shape (`routeNotificationForMail()` nullable by design for provisional
users, all design-kit columns nullable).
**Fix.** Not a repo-wide bump. Evaluate `level: 8` scoped via `paths:` to the highest-risk nullable
surfaces, or record the accepted gap as a comment in `phpstan.neon` the way the Redis exception is.
Baseline is currently clean: 467 entries, `composer analyse` green, no unmatched.

### `COV-TAIL-3` · **P2** · the TTL guard matches syntax, not arity
`CacheKeyspaceConstraintsTest.php:56` regexes `(?:->|::)\s*forever\s*\(` with an empty allowlist — it
**does** catch `forever()` correctly. What it cannot see: `Cache::put($key, $value)` with two arguments
and `Cache::remember($key, null, ...)`, both of which forward to `forever()` inside Laravel's
`Repository` without the word appearing. Zero live instances (every call site hand-checked), so latent.
**Fix.** Extend the same file to flag `Cache::put(` with <3 args and a literal `null` TTL.

### `COV-TAIL-4` · **P2** · `ServiceReorderPurgeTest` never checks the key vanished
It proves the compensating `$site->touch()` fires and `CloudflareCachePurgeJob` dispatches for all three
raw-`DB::table()` write paths, but trusts `SiteObserver → invalidateSite()` transitively.
**Fix.** `expect(Cache::get(CacheKeyGenerator::publicSitePayload(...)))->toBeNull()` after each reorder.

### `COV-TAIL-5` · **P2** · no lane exercises real Redis
All four configs force `CACHE_STORE=array` (verified). `ArrayStore` honours `expiresAt`, so TTL logic is
exercised — but `ArrayLock` is single-process, so `Cache::lock()` under genuine cross-worker contention
is asserted nowhere, and `volatile-lru` eviction is untestable by construction. **This is broader than
CLAUDE.md states**: distributed lock correctness is unverified full stop, not just under Cluster.
**Fix.** A scoped `phpunit.redis.xml` against a CI Redis service container, running the lock-contention
subset only. Sizeable — split out if it grows.

### `COV-LANE-3` · **P1** · `statement_timeout` / `lock_timeout` never applied in tests
*(Filed under `COV-LANE`, not `COV-TAIL`: the fix is a new `tests/Postgres/` test, which is the same
lane, same base class and same expertise as the 18-file move — one unit, not two.)*
`DatabaseServiceProvider::boot()` returns early under `runningUnitTests()`, true in all three PHPUnit
configs — so the 30s/10s settings from `config/database.php:100-102` are never exercised and could
regress to a no-op silently. Grep for `statement_timeout` across all four test dirs: zero hits.
**Fix.** A `tests/Postgres/` test asserting `SHOW statement_timeout` / `SHOW lock_timeout` post-boot.

### `COV-TAIL-7` · **P2** · ~15 platform routes prove less than they read
`tests/Authz/expectations.yaml` marks twitch/eventbrite/humanitix/shop account, event, brand and product
routes `fixture: unknown` — probing an id matching **no row**, never a real second owner's row. Disclosed
in `tests/Authz/README.md` ("weaker, and declared explicitly so the weakness is visible in review"), so
this is honest, not hidden. But dropping `authorizeForUser()` from
`ManagesIntegrationConnection::forgetConnection()` would pass every existing test.
**Fix.** One `tests/Feature/Security/TenantIsolation/PlatformConnectionIsolationTest.php` seeding a real
connection owned by user B and asserting user A gets 404.

### `COV-TAIL-8` · **P2** · the authenticated DAST lane has no cadence
`scripts/dast/active/` is the only lane with seeded identities and cross-identity checks — the only one
that can see an IDOR, a policy gap or a real crypto rejection. Its own header: "Never CI, never cron."
The weekly `edge-scan` is fully unauthenticated (spiders `robots.txt`, `sitemap.xml`, root), so it is
structurally incapable of finding an authorization bug.
**Fix.** Give it an explicit cadence the way `dast-edge.yml` has one — or wire it into the `fix-flow.md`
blocker gate for any PR touching auth/policy code. Decision, not code.

### `COV-TAIL-9` · **P2** · two dependencies have no drill
Drills 01-04 cover worker-kill, vendor-outage, redis-down, backup-restore. **Postgres** (connection
failure, pool exhaustion) and **R2** have none. Supavisor `EMAXCONNSESSION` is a previously-observed
condition with a measured 2026-07-30 baseline and a full operator runbook
(`docs/runbooks/db-pool-exhausted.md`) — and no automated signal at all. R2 has no failure-injection
test either: the one test referencing `OriginalStoreFailedException`
(`LogoBackgroundRemovalTest.php:139-164`) throws it via a *format-validation rejection*, not a storage
failure.
**Fix.** Cheapest first: a `tests/Feature/Resilience/` test mocking `Storage::disk()` to throw (same
shape as `DeadCacheStoreTest`'s `ThrowingStore`), and a `pg_stat_activity` threshold probe in
`launch-check`. A full Postgres drill is a separate decision.

### `COV-TAIL-10` · **P2** · k6 coverage is narrow and mostly unrun
Exercises 3 of ~28 public routes and 0 of ~380 authenticated ones. `jobs.js` (the only write path and
only queue-driving script), `spike-origin.js` and `spike-edge.js` have **never been run** — the README
says so. Not in any CI workflow or launch-check group, so there is no automated baseline comparison.
Also: `baseline.js`'s body check proves the `{"data":...}` success *shape*, not content — `{"data":{}}`
passes identically, so a degraded-build regression (`IndividualProfileController.php:145-149`) reports
100% pass.
**Fix.** Assert parsed array lengths against seed expectations (`gallery.length === 6` etc.), then run
`jobs.js` once and record it. Scope decision on the broader route coverage — flag, don't silently expand.

---

## Discovered during execution — needs a disposition, not a checkbox

Found while working the units above. Deliberately **not** filed as checkboxes: an open box blocks
auto-archive, and per `BACKLOG-TRIAGE.md` the right first move on a new P2/P3 is disposition
(WONTFIX / OPPORTUNISTIC / PROMOTE), not execution. Josh calls each.

1. **`not->toHaveKeys([...])` is the same vacuity, different matcher — 9 sites.** `toHaveKeys(array
   $keys, string $message = '')` loops `toHaveKey` internally, so negated it means **"not ALL
   present"** — a leak of one key out of four passes. Sites: `Platforms/DisplaySettingsTest.php:188,
   215, 239, 266`, `Platforms/GoogleBusinessApifyTest.php:556`,
   `Platforms/ShopBrandLogoProcessingTest.php:161`, `PreAccount/InstagramSourceGeneratorTest.php:93`,
   `Documents/DocumentUploadTest.php:30`, `Media/DesignSingletonMediaTest.php:75`. Several are
   **PII-adjacent** — `DisplaySettingsTest` asserts `reviews`/`reviewSummary` do not leak, the same
   third-party-key surface `DsarAllowlistCoverageTest` guards. Mechanical fix: `->not->toHaveKey($k)`
   per key in a `foreach`. **Recommend PROMOTE** — this is a P1-shaped leak check, not tail work.

2. **`EmailVerifiedRouteCoverageTest` is blind to ~372 routes.** `gatherMiddleware()` does not expand
   middleware **groups**, so every route on the `user.api` group (`bootstrap/app.php:123-127`) never
   enters the filter at all. This is *not* the `gatherRouteMiddleware()`-before-first-request hazard
   the audit correctly ruled out for `COV-GUARD-5` — it is a plain scope gap, and it is why that
   test's floor is 50 rather than ~106. **Left unfixed deliberately:** the fix changes what the sweep
   *does*, which is a behaviour change, not the guard repair `COV-GUARD-5` scoped. Without this note
   the floor of 50 reads to a future maintainer as "106 routes are covered", which is false.

3. **Five GS-1 allowlist entries were already dead before the token migration.** Re-running the
   *original* ci.yml grep against today's tree found ordinary code drift, unrelated to this unit:
   `CustomerObserver.php` (refactored onto `UserCacheService`), `InstagramController.php` (only
   `Cache::lock()` left, not a gated method), `ProbeBudget.php` (only `Cache::get()`, a read),
   and `OwnMediaAccentFactor.php` / `StorePricePointFactor.php` — **both files no longer exist**.
   All marked `// STALE` with a reason and pinned by `RawCacheCallGuardTest`'s expected-stale set, so
   *new* staleness still fails loud. Retiring them is a follow-up, not this unit.

4. **`HealthController.php` sits in the GS-1 allowlist with no justification comment at all.** Every
   other entry carries one. Transcribed as-is rather than inventing a rationale. Someone should
   either write the reason or remove the entry.

5. **Four SQLite-only fixture bugs, invisible until the assertions finally ran** (COV-LANE). All
   fixed in-unit; recorded because the *class* recurs: (a) `CREATE UNIQUE INDEX site.name ON …` —
   SQLite reads a schema-qualified **index** name as an attach-database alias and accepts it,
   Postgres qualifies the *table* and rejects it outright; (b) non-UUID fixture ids (`fresh-uid`,
   `rival-uid`, …) that a TEXT stand-in swallows and a real `uuid` column refuses; (c) an **unsaved**
   `PartnaStaff` built on the comment "associate() only reads the key" — true without FK enforcement,
   false against a real FK; (d) `DB::table('core.users')->count()` asserted against a **whole table**,
   only ever true because SQLite starts each test with an empty in-memory DB. Any future
   SQLite→Postgres migration should grep for these four shapes first.

6. **`SiteProvisioningSavepointTest`'s seed-failure skip does not fire for the reason its comment
   claims.** The message says *"likely no INSERT on auth.users"*, implying a role-permission gap.
   Running as `postgres` (superuser, as CI does), the `auth.users` insert succeeds — the skip
   actually fires because the fixture passes `$authId.'-a'` as `auth_user_id`, which is not a valid
   UUID and is not the id inserted into `auth.users`. A pre-existing fixture typo that happens to
   land on the same catch block as the intended permission check. Left untouched (it is one of the
   three legitimate skips), but the comment is misleading and should be corrected.

7. **`AnalyticsQueryServiceBreakdownsTest` asserted `toHaveCount(15)` where the real count is 14.**
   Surfaced by running the never-executed `referrers()` test for the first time. 14 = 12
   `REFERRER_SOURCES` + 2 structural labels, independently corroborated by the existing `#FOUND-3`
   reflection guard in `AnalyticsQueryServiceConfigDrivenTest`, which had been silently proving 14
   all along. Fixed in-unit.

8. **`VerifySupabaseJwtJwksLeewayTest` does not test the leeway.** Despite the name, it only asserts
   `JWT::$leeway` is *restored* to its prior value after decode (process-global-state hygiene). It
   never asserts the leeway *does* anything — itself an assertion that passes while proving nothing
   about what its name implies, and probably why `COV-JWT-2` was easy to miss. Consider renaming.

9. **The 60s JWT leeway grants a 60-second post-expiry grace on every access token.** `JWT::$leeway`
   in `firebase/php-jwt` applies to `nbf`, `iat` **and** `exp` — it is not skew-only. The comment at
   `VerifySupabaseJwt.php:368` says only *"Supabase tokens can arrive with up to ~60s clock skew"*,
   which describes the intent, not this consequence. **Probably not a defect** — 60s on a ~1h token is
   ~1.7%, the fail-closed revocation gate (SEC-2) is the real kill-session control and is unaffected,
   and lowering the leeway would reintroduce the skew rejections it was added to stop. **Recommend P3:
   amend the comment.** It is recorded because it is what invalidated the audit's prescribed test value.

10. **Forged-`kid` path untested** — a `kid` that is present but absent from the JWKS hits
    `RuntimeException('No matching JWKS key for kid')` (`VerifySupabaseJwt.php:427`) → 401, with zero
    coverage. Adjacent to `COV-JWT-3` and cheap. Explicitly deferred by decision, not overlooked.

11. **`OutboundHttpGuardTest` has no concept of a timeout at all.** It enforces SSRF *category* for
    every outbound call but never checks that a call is time-bounded (`grep timeout` over both the
    scanner and the test → zero hits). **That is why no guard caught `COV-TIMEOUT-1`.** A sweep during
    that unit found 32 of 33 `Http::` sites in `app/` bounded and exactly one not — so the durable fix
    is extending `OutboundHttpScanner` to assert boundedness. Deliberately not bundled into a
    blocker-gated runtime change: it is a new guard, needs its own positive control, and touches a
    required CI job. **Recommend PROMOTE.**

12. **62 direct-controller call sites across 18 staff test files** (`grep -rn "app(Staff.*Controller::class)\|new Staff.*Controller" tests/`).
    `COV-PII` converted none of them — it added route-level companions for the two PII surfaces the
    finding named. The wider anti-pattern, which has a documented three-live-bug record in this repo,
    remains. Also: `setupUsersTable()` omits the real `admin_notes` column, so
    `StaffUserShowPiiTest` has to `ALTER` it in — a small fixture-parity gap.

---

## Rejected during verification

Two subagent claims did not survive the main-session check. Recorded so they are not re-raised:

1. **"The 2026-08-03 k6 JSON is untracked/forgotten."** It is *deliberately gitignored*
   (`k6/.gitignore:2` → `results/*.json`); the tracked record is meant to be a markdown summary. The
   real finding is that no summary was written, which is `COV-GUARD-3`.
2. **"No test anywhere issues `getJson('/api/staff/professionals/{id}')`."** False —
   `StaffUnclaimedVisibilityTest.php:78,94` does. The conclusion survives because those cases assert
   `pre_account_build` fields, not PII redaction, but the stated evidence was wrong.

## Refuted — do NOT re-audit

Verified sound this pass. Re-checking these is wasted effort unless the underlying code changes:

- **k6's three hard-coded seed invariants all still hold.** `enforce_site_gallery_max6` trigger exists
  (`baseline_pilot.sql:3460`) and `seed.sql` inserts exactly 6; every gallery row has a matching webp
  `media_variants`; `jobs.js` sends an `Origin` matching the seeded subdomain against
  `AnalyticsController::originAllowed()`.
- **DAST baselines are provably machine-generated.** `lib/diff-baseline.sh:56-70` extracts keys with
  `jq` from raw scanner output — hand-authoring is structurally impossible. Real ZAP plugin IDs, real
  dev-host URLs, commit provenance checked.
- **All four drill logs grade themselves honestly.** `03-redis-down` caught two of its *own* false
  PASSes mid-run and recorded them as findings; `01-worker-kill` grades itself PARTIAL because shared
  prod/dev KV made its central question unobservable; `04-backup-restore` marks RPO **FAIL** and caught
  the dump silently missing the entire `moderation` schema. Freshness gate green on all four.
- **No cache key in `app/` lacks a TTL**; zero `forever()` call sites.
- **Both Eloquent-bypassing write paths are compensated** — raw `DB::table()` writes in
  `UserServiceController::reorderLayout` are followed by `$site->touch()` (tagged `EDGE-1`); the
  `ON DELETE CASCADE` is pre-empted by an explicit `invalidateSite()` at `AccountDeletionService.php:779`.
- **The SWR `runningInConsole()` gate** is the most thoroughly tested line in the cache surface —
  `SwrDeferredRecomputeTest.php` covers both branches with a real array-store lock.
- **No N+1 on the public read path**; every collection query eager-loads.
- **Checkpoint suppression design is correct** — `CheckpointSuppressionStalenessTest` re-runs the scan
  with suppressions blanked and diffs live hashes rather than trusting the file, which is the right
  answer for content-addressed hashes, and it *is* a required check. All 58 entries carry dated reasons.
- **`expect(true)->toBeTrue()` ×20** is the legitimate "reaching this line is the assertion" idiom.
- **All 104 staff routes carry `require.aal2`**, asserted non-vacuously by `Aal2RouteCoverageTest`
  (which *does* have its non-empty guard) and fired over real HTTP by `tests/Authz/StaffBoundaryTest`.

## Explicitly not covered

- Systematic sweep of `shouldReceive()` without `->once()`/`->with()`, and assertions nested in
  never-entered conditionals — named in the brief, not completed.
- Per-Resource PII field audit across all 69 Resource classes (2 read in full).
- Per-Mailable null-email tolerance across 8 notifications + 28 mailables (model contract only).
- R2 read path; Resend transport internals (framework-owned).
- Whether `pcntl` exists in the `postgres-tests` runner — see `COV-LANE-1`.
- **Whether the Laravel Cloud origin is reachable directly, bypassing Cloudflare.** If it is, every
  `CF-Connecting-IP`-keyed rate limiter is forgeable. That would be a P0 *infra* finding, but it is not
  answerable from this repo — needs the Cloud network config.
- Nightwatch as a detection layer; Worker edge internals; `launch-check` groups B/C/G/H script internals.
