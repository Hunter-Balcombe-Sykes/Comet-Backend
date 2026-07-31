# Unit 13 Plan — #TEST-5, 6, 7, 9, 10 (ingest concurrency / money / PII proofs)

Produced 2026-07-29 by the audit-fix run. Persisted into the repo because the
session scratchpad does not survive. Not yet implemented.

## STEP 1 — Premise verification (all five read in full)

| # | Premise | Verdict |
|---|---|---|
| TEST-7 | `EffectLedger::once()` charge-once has no concurrent test | **CONFIRMED (concurrency), one sub-item STALE.** No live defect. |
| TEST-6 | `claimDue()` mutual exclusion proven only sequentially | **CONFIRMED.** No live defect. |
| TEST-5 | `RunSourceJob` `finally` + `failed()` untested | **CONFIRMED.** No live defect. The premise's "concurrency" framing is wrong — this is sequencing, not contention. |
| TEST-9 | 40% deletion guard "has no test in EITHER direction" | **LARGELY STALE — factually false in both directions.** A real but much smaller gap remains. |
| TEST-10 | `RunExecutor::isClaimed()` untested | **CONFIRMED**, with two material framing corrections. No defect in `isClaimed()`; one latent fail-open hazard found nearby. |

### TEST-7 — CONFIRMED (one stale sub-item)

`EffectLedger.php` matches the finding: pre-read → `insert` → `catch` → re-read →
`verdictFor()`. The constraint is real (`digest text PRIMARY KEY`,
`20260727130000_ingest_schema.sql`). `once()` is called from `HttpIo::effect()` with **no
enclosing transaction** anywhere in `RunExecutor`/`RunSourceJob` (grepped), so the
duplicate-key catch cannot land inside an aborted transaction (25P02). Nothing to fix.

`tests/Feature/Ingest/EffectLedgerTest.php` (8 tests) has no concurrent test. Gap real.

**STALE sub-item:** the finding's second bullet (test an abandoned `claimed` row past
`ABANDON_AFTER_SECONDS`) is already covered by
`it('marks a long-abandoned claim as abandoned and still refuses to run the closure…')`,
which seeds `claimed_at = now()->subSeconds(901)`. **Do not write it again.**

### TEST-6 — CONFIRMED

`SourceScheduler::claimDue()` is the conditional `UPDATE … WHERE in_flight_since IS NULL`.
Correct under READ COMMITTED. **Note: this is NOT `FOR UPDATE SKIP LOCKED`** — the run
owner's SKIP LOCKED note does not apply to this method.

The existing mutual-exclusion test makes two sequential calls on one scheduler against one
in-memory SQLite connection. Proves in-memory coherence only. Gap real.

### TEST-5 — CONFIRMED (reframe: S not M, and NOT a Postgres test)

Traced both nets; **no defect**. `$released = true` sits after the normal `release()` inside
`try`. The `$row === null` path sets `$released = true` and releases nothing — correct.
`failed()`'s re-claim window is unreachable in practice: `release(…, 'error', …)` sets
`next_attempt_at = now() + min_interval × 2^failures` (≥2h at first failure), so a
dispatcher tick cannot re-claim between `finally` and `failed()`.

Grepped whole `tests/` tree: only two comments mention `RunSourceJob`. **Zero behavioural
coverage.** Gap real.

### TEST-9 — LARGELY STALE (headline outcome)

"No test in **either** direction" is false in **both**. `tests/Feature/Ingest/LanderTest.php`:
- L294 trips the guard (5 of 10 vanish): asserts `guard_tripped === true`, `tombstoned === 0`,
  `streams.guard_tripped_at` set, a `delete_guard` anomaly at `severity = critical`, and that
  the vanished keys' `absent_runs` stayed 0.
- L527 does not trip (30 of 100): asserts `guard_tripped === false` and all 30 accrued
  `absent_runs = 1`.
- L328 frozen-once-tripped; L346 + L363 `clearGuardIfRecovered()` in both directions — so the
  finding's third bullet is stale too.

**Genuine residue:** no test sits at the boundary. Existing cases are 50% and 30%. All four of
these mutations pass the current suite green:
`>= 0.4` → `> 0.4`; `>= 5` → `> 5`; `>= 5` → `>= 4` (or dropped); `>= 0.4` → `>= 0.3`.

Downgrade to **#TEST-9 (residual): boundary/off-by-one coverage**. P1 → P2-ish, S not M, and
it does **not** need Postgres.

### TEST-10 — CONFIRMED, two corrections

`isClaimed()` is at `RunExecutor.php:281-291` (the finding's `:340-348` is line-drifted; body
verbatim). Called at `:80`; result flows to `:144`
`redactions: $manifest->redactionsFor($pull->isClaimed)`, which `Lander::landChunk()` applies
via `Redactor::apply()` **before** hashing and before the `record_versions` insert. The gate
genuinely decides what is persisted. It fails closed on a missing user. **No defect.**

1. **Existing coverage is adjacent.** `tests/Unit/Ingest/ManifestTest.php:65` and
   `tests/Feature/Ingest/GoogleBusinessConnectorTest.php:212` already prove
   `redactionsFor(bool)` and the GBP `when_unclaimed` declaration. Untested is the **middle**:
   `isClaimed()`'s `core.users.status` → bool mapping, and that an unclaimed account's stored
   doc has no reviewer PII.
2. **The finding's "real, current pipeline" claim is not accurate today.**
   `HttpIo::runBilledEffect()` throws unconditionally — no `api`/`places.details` driver is
   wired (deferred to P7). So `GoogleBusinessConnector` cannot complete a run through
   `RunExecutor` at all; the pre-account GBP path runs through `GoogleBusinessService`. **An
   end-to-end GBP test through `RunExecutor` is not writable** — which drives the fake-connector
   design below.

**Latent hazard found while verifying:** `App\Ingest\Runtime\Pull::$isClaimed` defaults to
**`true`** — fail-*open*. `RunExecutor:75` is the only production construction site today
(grepped `new Pull(`), so nothing leaks. But any future second site that forgets the argument
silently disables redaction for unclaimed accounts.

---

## STEP 2 — Plan

### Lane assignment (honest split — two pg files, three normal)

| Finding | Lane | Why |
|---|---|---|
| TEST-7 | `tests/Postgres/EffectLedgerConcurrencyTest.php` | Separate connections against a real unique constraint. The SQLite mirror is `ATTACH ':memory:'` — unshareable across forks by construction. |
| TEST-6 | `tests/Postgres/SourceSchedulerConcurrencyTest.php` | Row-level contention on a conditional UPDATE is meaningless under SQLite's whole-DB serialisation. |
| TEST-5 | `tests/Feature/Ingest/RunSourceJobClaimReleaseTest.php` (new) | Sequencing of two in-process paths. Postgres proves nothing extra. |
| TEST-9 residual | append to `tests/Feature/Ingest/LanderTest.php` | Integer/float arithmetic plus two writes. |
| TEST-10 | `tests/Feature/Ingest/RunExecutorClaimGateTest.php` (new) | `core.users` lookup + `Redactor` + stored-doc assertion; all in the SQLite mirror. |

### #TEST-7 — `tests/Postgres/EffectLedgerConcurrencyTest.php`

**Two mechanisms, deliberately:**

1. **Deterministic race injection (no fork).** A `DB::listen` hook that, the first time it sees
   `once()`'s pre-read `select … from ingest.effects where digest = ?`, inserts that digest **on
   a second, independently resolved Postgres connection**, then unregisters itself. `once()`'s
   INSERT then hits a real 23505 and the catch branch executes. Same technique as
   `ProbeBudgetConcurrencyTest`'s race-injecting Cache decorator. **Zero timing dependence — this
   is the real regression pin.** A second connection is essential; one connection cannot both be
   inside `once()` and insert the conflicting row.
2. **Real N-way fork race.** `pcntl_fork` × 8. Each child calls
   `DB::purge('pgsql'); DB::reconnect('pgsql');` **first thing** — a libpq socket shared with the
   parent corrupts in a way that looks exactly like the bug under test. Children `usleep()` to a
   parent-computed wall-clock start gate (~200 ms out), then all call `once()` on the same digest.

**Assertions** (the closure is not a no-op — it INSERTs into a scratch `ingest.charge_probe`;
that row IS the charge):
- `count(*) FROM ingest.charge_probe` **=== 1** — the money assertion. Not "no exception thrown".
- `count(*) FROM ingest.effects WHERE digest = ?` === 1, `status='ok'`, `settled_at IS NOT NULL`.
- `SUM(cost_units)` === one unit's worth — charged once, not merely written once.
- Every loser's verdict ∈ {`refused`, `ok` with `cached=true`}, **never** `ok` with
  `cached=false` — that combination from a non-winner is the double-charge signature. (Which of
  the two a loser gets depends on whether it lost at the pre-read or the INSERT; both correct.)
- Any loser that got `ok` has a result equal to the winner's.

Children report via a scratch `ingest.effect_probe(child_idx, status, cached, result_json)` table,
not exit codes (which cap at 255 and are confused by a throwing child).

**Schema:** `ingest.effects` mirroring the migration + two scratch probe tables. **No canonical
DDL, no `core.users`.** `beforeEach` DROP…CASCADE + recreate; `afterAll` DROP.

**Flakiness:** invariants are one-sided — a run without collision still passes; no interleaving
can make it fail red. Minimise under-proving with the start gate, 8 children, 5 sequential rounds
(fresh digest each). Skip cleanly when `pcntl_fork` is unavailable.

### #TEST-6 — `tests/Postgres/SourceSchedulerConcurrencyTest.php`

**Fork only.** The race window is *inside a single UPDATE* — no application seam to inject into,
so genuine concurrent transactions on separate connections are the only proof. Children
`DB::purge`/`DB::reconnect` before any query, then wait on the same start gate.

**Design for guaranteed contention:** seed **25** due sources; fork **8** children each calling
`claimDue(25, "run-{$i}")`; each writes one row per claimed source into
`ingest.claim_probe(child_idx, source_id)`.

**Assertions:**
- `count(DISTINCT source_id) === count(*)` in the probe table — **no source claimed twice.** The
  mutual-exclusion proof.
- Total claims === 25 — nothing lost either. **This pairing is what makes it load-bearing:** a
  conditional UPDATE that always lost would pass the no-double-claim assertion alone.
- Per source, `in_flight_run_id` equals the recording child's run id and `in_flight_since IS NOT
  NULL` — the row's lock agrees with who thinks they won.
- More than one distinct `child_idx` appears — a soft witness of real contention, asserted as a
  warning-grade expectation, **not** pass/fail, so it cannot flake red.

**Schema:** `ingest.sources` (all columns `claimDue`/`scoreDue` read) + `ingest.runs` (joined by
`scoreDue()` for `cost_claimed`) + the probe table. **`user_id` must be a bare `uuid` with NO FK
to `core.users`** — that keeps `core.users` out of the file and avoids the
`NoLocalCanonicalTableDdlTest` baseline entirely.

**Determinism:** seed every source with `last_run_at = NULL` (score 10.0, the "never run" branch)
and identical `visibility`/`cost_units`, so candidate selection has no clock sensitivity.

### #TEST-5 — `tests/Feature/Ingest/RunSourceJobClaimReleaseTest.php` (normal lane)

Sequential, in-process, `setupIngestTables()`. Deliberately **not** `tests/Postgres/`.

1. `it('releases the claim in finally when handle throws')` — seed a claimed source with
   `source_key = 'no_such_connector'`; `ConnectorRegistry::for()` throws for an unregistered key
   (verified), the cleanest deterministic way to throw *after* the claim and *before* the normal
   release. Assert the exception propagates **and** `in_flight_since IS NULL`,
   `consecutive_failures === 1`, `next_attempt_at` pushed out. Both holding together is the point.
2. `it('failed handler releases the claim when finally never ran')` — construct
   `new RunSourceJob($id)` without calling `handle()`, call `failed(new RuntimeException(...))`.
3. `it('failed handler is a no-op when finally already released')` — **the one the finding exists
   for.** Seed the exact state `finally` leaves (`in_flight_since = NULL`,
   `consecutive_failures = 1`, recorded `next_attempt_at`), call `failed()`, assert
   `consecutive_failures` is **still 1, not 2** and the timestamps are byte-identical. Asserting
   the *unchanged* counter is what proves the `$stillClaimed` guard; "no exception" would pass
   against an unconditional double release that silently corrupts backoff.

Use `Log::spy()`/`Exceptions::fake()` so deliberate exceptions don't pollute output. Freeze the
clock with `Carbon::setTestNow()`.

### #TEST-9 residual — append to `tests/Feature/Ingest/LanderTest.php`

Record the stale finding in the file header next to the existing guard block so the next reader
doesn't re-file it. Four boundary tests, each killing one specific mutation, all under
`Coverage::exhaustive()` with `orderField: 'seq'`:

| Case | Live / absent | Ratio | Expected | Mutation killed |
|---|---|---|---|---|
| 1 | 20 / 8 | exactly 0.4 | **trips** | `>= 0.4` → `> 0.4` |
| 2 | 20 / 7 | 0.35 | **no trip**, all 7 accrue | `>= 0.4` → `>= 0.3` |
| 3 | 10 / 4 | 0.4 but count 4 | **no trip**, all 4 accrue | the `&& count >= 5` clause exists at all |
| 4 | 12 / 5 | 0.4167, count exactly 5 | **trips** | `>= 5` → `> 5` |

8/20 for case 1 because `8.0/20.0` is the correctly-rounded double nearest 0.4, bit-identical to
the `0.4` literal — no float-equality flake.

Trip cases mirror the existing exemplar's assertions. No-trip cases assert `guard_tripped ===
false`, `guard_tripped_at` still NULL, **zero** `delete_guard` anomaly rows, and every absent key
at `absent_runs = 1` — the positive assertion that normal tombstoning proceeded, which is what
"does NOT trip" must mean (a permanently-tripped breaker cannot pass this).

### #TEST-10 — `tests/Feature/Ingest/RunExecutorClaimGateTest.php` (normal lane)

**What leaks if the gate is wrong:** for GBP, `author`, `author_uri`, `author_photo` — reviewer
display name, profile URL, profile photo — persisted durably for an unclaimed account. So assert
on **the stored document**, not the branch.

**Design:** because no billed-effect driver is wired, use a **test-local fake connector** — an
anonymous class implementing `App\Ingest\Runtime\Connector` whose `manifest()` declares
`hosts: []`, one Mirror stream, and redactions `['reviewer_name', 'nested.reviewer_photo',
'reviews.*.author']` with `reviewer_name` and `reviews.*.author` scoped `when_unclaimed` and
`nested.reviewer_photo` scoped `always`. `pull()` yields fixed `Record`s. Pass it straight to
`$executor->execute($source, $connector, $manifest, 'manual')`, as `RunExecutorProjectionTest`
already does with `BandcampConnector`. Pick a `source_key` absent from `ProjectorRegistry::MAP`
so the projection branch is skipped.

Five tests:
1. Unclaimed → `when_unclaimed` paths absent from the persisted `record_versions.doc`.
2. Active → the same fields present with exact values. **A permanently-redacting gate is also a
   bug.** (1) and (2) together are what a De Morgan inversion cannot survive.
3. `user_id` points at a UUID with no `core.users` row → redaction applied (`$status === null`
   fail-closed branch).
4. `user_id` null → redaction applied (early return).
5. `always`-scoped path stripped under **both** states — guards a refactor making all redaction
   claim-conditional.

**Recommended code change (small, in scope):** flip `Pull::$isClaimed`'s default from `true` to
`false`. It is a PII gate and should fail closed; `RunExecutor:75` passes it explicitly. Grep the
~18 connector unit tests that construct `Pull` without it and confirm none declare
`when_unclaimed` scopes before flipping. Add an assertion pinning the default. If the reviewer
prefers zero source change in a test unit, spawn it as a follow-up — but do not leave it unremarked.

## Risks / up-front flags

1. **`NoLocalCanonicalTableDdlTest` is avoidable here.** With this design no new file trips it —
   on one condition: the TEST-6 pg file must declare `ingest.sources.user_id` as bare `uuid` with
   **no** `REFERENCES core.users`. `IngestCascadeDeletionTest` is the tempting copy-paste source
   and it *does* create `core.users` (it's baselined); **`LanderBatchLandingTest` is the correct
   exemplar** (FK-free, baseline-free).
2. **The pg lane shares one database and must not run in parallel.** Existing pg files each
   `DROP TABLE … CASCADE` and recreate `ingest.*` with different shapes in `beforeEach`; the lane
   works only because Pest runs files sequentially. Do not add `--parallel` to `composer test:pg`.
3. **`pcntl_fork` inside PHPUnit:** children must `exit(0)` directly (never fall through to
   PHPUnit's shutdown handlers) and wrap their body in try/catch so an exception becomes a probe
   row rather than a stray fatal. Parent asserts on DB state, which is authoritative.
4. **Forked children and the pgsql connection:** `DB::purge('pgsql')` then `DB::reconnect('pgsql')`
   as the *first* statement in each child. Skipping this produces corruption that mimics the bug
   under test — the test would appear to "find" the race it was written to disprove.
5. **Hand-written `ingest.*` DDL must be transcribed from
   `supabase/migrations/20260727130000_ingest_schema.sql`**, not from memory, or the tests drift
   from prod schema silently.
6. **Under-proving, stated plainly:** the fork tests assert one-sided invariants — they can pass
   without contention occurring, but no interleaving makes them fail. That is the correct trade
   against "flaky tests get disabled". TEST-7's injection test is the deterministic backstop;
   TEST-6 has no such backstop available, which is why it gets 25 contested rows across 8 children.

## Suggested audit-file dispositions

- **#TEST-7** — done; note the abandoned-claim sub-item was already covered.
- **#TEST-6** — done.
- **#TEST-5** — done; note the finding's concurrency framing was wrong and both nets are correct.
- **#TEST-9** — **PREMISE LARGELY STALE**, quote the four existing test names, record that the
  residual boundary coverage was added.
- **#TEST-10** — done; record both framing corrections and the `Pull::$isClaimed` fail-open default.
