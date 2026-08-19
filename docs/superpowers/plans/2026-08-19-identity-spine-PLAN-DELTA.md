# Plan delta — #LIFE-1 + #SCALE-4 + #CACHE-5 (identity spine)

Branch `audit-fix/identity-spine-2026-08-19`, worktree under the session
scratchpad, based on `origin/development` @ `35ab6b7d8`.

**Status: IMPLEMENTED and signed off (2026-08-19). Kept as the record of what was
decided and why — the sign-off answers and the measurements are in §5.**

Verified against the worktree checkout on 2026-08-19, not against the plan file.

---

## 0. RULE ZERO — the premise, re-checked

**Verdict: the race IS reachable, but NOT by the route the plan leads with.**
One of the plan's two reachability routes is closed by deployment config today.

### Route 1 — "two sources of the same user run concurrently" — CLOSED on the queue

The plan (§2.1) and the CONSOLIDATED finding both rest on `SourceScheduler::claimOne()`
locking a source, not a user. That part is true and I confirmed it
(`app/Ingest/Runtime/SourceScheduler.php:84-93`; `claimDue()` at `:36-63` will happily
claim two sources of one user in one tick; `RunSourceJob` is explicitly *not*
`ShouldBeUnique` and says so in its docblock).

What neither document checked is the **worker lane**. `RunSourceJob` hardcodes
`$this->onQueue('ingest')` (`app/Jobs/Ingest/RunSourceJob.php:67`), and `ingest`
is drained by exactly one supervisor — `supervisor-ingest`, `config/horizon.php:352-360`
— which is pinned to **`maxProcesses => 1` in production, development AND local**
(`:418-438`). The file carries an explicit instruction not to raise it before a box
resize (`:403-409`). So two `RunSourceJob`s for one user cannot execute
simultaneously; they queue behind one worker.

This is a **memory-budget accident, not an invariant**, and it is one config line
from being false. But as of today it is load-bearing, and the finding's headline
scenario ("Spotify + SoundCloud refresh at the same moment") does not happen on
the queue.

### Route 1b — CLI — OPEN

`IngestRunCommand:83` and `IngestDispatchCommand:44` use `dispatchSync`, and
`IngestProjectCommand` calls `projectStream()` directly. Those run in their own CLI
process, entirely outside `supervisor-ingest`'s single worker and (for
`ingest:project`) outside the source claim as well — `ProjectionWriter.php:170-176`
already documents that bypass in a code comment. An operator repair run against a
user whose scheduled source is mid-flight is a genuine two-writer collision.

### Route 2 — the dashboard write — WIDE OPEN, and this is the real one

`writeManualItem()` (`:390`) calls `resolveItems()` at `:414` from **synchronous HTTP
requests**, with no queue lane bounding concurrency:
`PoolItemCreateController::store()`, `UserServiceController`,
`StaffServiceManagementController`, plus `ManualPoolWriter` / `LinkPoolWriter` /
`ManualEventWriter` / `ShopContentWriter` / `MenuScanApplier` behind them. Two tabs,
a double-clicked "Add", or a bulk dashboard action is two concurrent
`resolveItems($user, $kind)` calls in two PHP-FPM workers. #PGR-7 fixed the
**same-coord** half of exactly this collision and its comment names the scenario
verbatim; the **different-coord** half — which is what #LIFE-1 is — is untouched.

Two `platform_connect` jobs of one user can also overlap (`supervisor-1`,
`maxProcesses` 2–3), e.g. `ShopBrandConnectJob` + `ConnectStoreFromProductJob`,
both landing manual items.

### What this changes

Nothing about the fix; something about the justification. The unit proceeds. The
CONSOLIDATED entry's "Affects" line is wrong as written and I will correct it when
ticking the box: the reachable collision today is **a dashboard write racing another
dashboard write or a projection run**, not two scheduled connector runs.

### Line-number drift (plan file is a day old, as warned)

| Claim | Plan says | Actually |
|---|---|---|
| `resolveItems()` | `:593` | `:596` |
| source-item read | `:609-618` | `:612-621` |
| `bindGroup()` call | `:719` | `:722` |
| final `item_id` UPDATE | `:686-707` | `:689-710` |
| anchor read in `bindGroup()` | — | `:723-728` |
| per-coord anchor INSERT (#CACHE-5) | `:721-731` | `:753-758` |
| `mergeInto()` | `:898` | `:901` |

`#SCALE-4`'s cited `:655-660` / `:704-710` are both wrong; the real sites are
`:670-675` (the group loop) and `:723-728` (the per-group anchor read).

### Hazard checks the prompt demanded, answered

- **Is `resolveItems()` already inside a transaction?** No, at either call site.
  `projectStream()`'s transactions are per-record inside the `foreach` (`:202`) and
  close before `:247`. `writeManualItem()`'s closes at `:412`, before `:414`. I
  checked every caller of `writeManualItem()` for a wrapping transaction — there is
  none (`ShopContentWriter`, `ManualEventWriter`, `LinkPoolWriter`, `ManualPoolWriter`,
  the three backfillers, `MenuScanApplier`, `ShopCatalog`, `ShopProductSeeder`,
  `IngestProjectCommand`: zero `DB::transaction` in any of them), and three call sites
  carry comments stating it must not be. `:1379`'s chunked transaction is in
  `replaceCollections()`, downstream, not around this.
- **The 25P02 trap.** No `try/catch` will be added inside the new transaction.
  The only catch is around the whole `DB::transaction()` call, for the lock-timeout
  degrade.
- **SQLite.** `pg_advisory_xact_lock`/`hashtext` exist in the suite only as UDF shims
  registered by `shimPgAdvisoryLockForSqlite()` (`tests/Pest.php:1511`), which is
  called from **one** setup function and six test files. Adding an unguarded lock call
  to `ProjectionWriter` would break every SQLite feature test that projects content.
  → the call is **driver-guarded** (below), so `composer test` proves nothing about the
  lock, exactly as the prompt says.

---

## 1. The change

### 1a. #LIFE-1 — lock + transaction

`resolveItems()` body becomes:

```php
return DB::transaction(function () use ($userId, $kind) {
    if (DB::connection()->getDriverName() === 'pgsql') {
        AdvisoryLock::acquire("identity:{$userId}:{$kind}", self::IDENTITY_LOCK_TIMEOUT_MS);
    }
    // …existing body, unchanged, INCLUDING recordCandidates() and the final
    //   per-target source_items UPDATE loop…
});
```

Reusing `App\Services\Site\AdvisoryLock` rather than an eighth bare
`DB::select('select pg_advisory_xact_lock(...)')`: it already carries
`SET LOCAL lock_timeout` and the typed `AdvisoryLockTimeoutException` the prompt
asks for.

**One wrinkle:** `AdvisoryLock::acquire()` hardcodes `DB::connection('pgsql')` while
`ProjectionWriter` writes on the *default* connection. Those are the same object in
every environment we run (`config/database.php:20` defaults to `pgsql`), but an
advisory **xact** lock taken on a different connection than the transaction is a
silent no-op, so I will add an optional `?string $connection = null` parameter to
`AdvisoryLock::acquire()` (default preserves today's behaviour for the seven
existing callers) and pass the transaction's own connection. Additive, no
behavioural change elsewhere.

The transaction **encloses the whole body** — the reads, the union-find,
`bindGroup()`, `recordCandidates()`, and the final `UPDATE … SET item_id` loop.
That is the reviewer's first question and it is the design, not an accident.

**Degrade path (needs your call — see §4):** `AdvisoryLockTimeoutException` propagates
out of `resolveItems()`. For a queue run that is a failed run with `SourceScheduler`
backoff, which is right. For a **dashboard write it becomes a 500 unless mapped**, and
the repo's precedent for this exception is a **423** (`ManagesIntegrationConnection`,
the two service-management reorder paths). Mapping it means the pool-create / service
endpoints can now return 423 under contention — a wire-visible change to the
dashboard API.

Timeout: proposing `5000`ms to match `AdvisoryLock::SERVICES_LOCK_TIMEOUT_MS`,
**subject to measurement** — the prompt requires timing a large-catalogue resolve
under the lock before accepting that number, and I will report the measurement.

### 1b. #SCALE-4 — hoist the anchor read, with a merge-dirty guard

Prefetch every anchor for the union of all group coords in one query before the loop,
pass the per-group slice into `bindGroup()`.

**The staleness problem is real even under the lock**, and this is the reviewer's
second question. No *other* process can interleave, but `mergeInto()` — called from
inside the loop — runs
`UPDATE content.item_anchors SET superseded_by = kept WHERE item_id = discarded OR superseded_by = discarded`,
which is **not scoped to the current group** and can rewrite anchors belonging to
coords a later group is about to read. Groups are disjoint (union-find partitions
coords) so the *coords* don't overlap, but the *items* they point at do, which is
precisely what that UPDATE keys on.

Fix: a `$mergedSinceSnapshot` flag. Any `mergeInto()` inside the loop sets it; once
set, `bindGroup()` re-reads its own group's anchors exactly as today. Steady state is
all-singleton groups with zero merges, so the N+1 is fully eliminated on the hot path
and the correctness is unconditional.

Rejected: mirroring the SQL UPDATE's semantics onto the in-memory snapshot. It is
cheaper and it is how this goes subtly wrong — reimplementing a `WHERE item_id = ? OR
superseded_by = ?` rewrite in PHP against the identity spine, to save a query in a
case that barely happens.

### 1c. #CACHE-5 — batch the per-coord anchor inserts (partial; see §4)

The insert half: collect the group's missing coords, issue **one** multi-row
`insertOrIgnore`, and when `inserted < count($missing)` fall into the existing #PGR-7
reconciliation by re-reading the group's persisted anchors. Same semantics, same
`$boundHere`/`$lostTo` outcomes, one statement.

The merge half — "extend `mergeInto()` to accept a list of loser ids" — I propose
**NOT** doing, and ticking the box with that reason recorded. `mergeInto()` is the
hard-delete path; the finding is P3, self-described as "worth doing opportunistically,
not urgently", and losers per group are 0 in steady state and low single digits ever.
Rewriting the irreversible path to save a handful of statements is the wrong trade on
this unit. **Your call — say the word and I do it.**

---

## 2. Tests — `tests/Postgres/` only

New file `tests/Postgres/ProjectionWriterIdentityRaceTest.php`, modelled on
`ProjectionWriterManualCoordRaceTest.php` (fork + barrier + probe table) with a
`pgir_` identifier prefix so the two files' Pest symbol tables cannot collide.

**Its DDL needs three tables the `pmcr_` clone does not create** —
`content.item_links`, `content.item_slugs`, `site.section_items`. `pmcr` never merges,
so it never reaches `mergeInto()`'s `moveLinks()`/`moveSlugs()`/curation check; every
test here does. This is the silent-DDL-drift hazard the prompt flags, found before it
bit rather than after.

**Forcing a deterministic interleave.** `DB::listen` injection (the `pmcr` Test A
technique) cannot prove this one: the interleaving writer has to be *another
`resolveItems()` call*, and once the lock exists that call would block inside the
synchronous listener and self-deadlock the test. So: real forks, with the read→write
window widened deterministically by a **test-side** `DB::listen` hook in child A that
runs `select pg_sleep(1)` once, after A's first `source_items` read. Timing only — no
semantics injected. Child B runs its whole resolve inside that window.

1. **Lost update / dangling `item_id` (plan §3b + §3c) — must FAIL on today's code.**
   Two coords that unite via a joining identity key inserted between A's read and A's
   write. Pre-fix, A writes `item_id` pointing at the item B's `mergeInto()` just
   hard-deleted → `23503` FK violation, or a silently un-done merge. **If this passes
   unfixed, I stop and come back to you — the unit gets re-scoped, per RULE ZERO.**
2. **Split identity (plan §3a).** Assert the final state is the union, whichever
   process commits last.
3. **`mergeInto()` hard delete under a concurrent reader.** No dangling `item_id`, no
   FK error, curation-carrying losers survive.
4. **The lock actually blocks — measured.** Child B records elapsed wall time. Pre-fix
   ≈0ms; post-fix ≥ the ~1s A holds the lock. A lock test that would pass without a
   lock is the stated failure mode of this kind of work, so the assertion is on the
   measured delay, not on the outcome alone.
5. **Then** #SCALE-4 + #CACHE-5, and re-run 1–4 **unchanged**. Any assertion that needs
   relaxing means the batching is wrong.

Plus a query-count assertion for #SCALE-4 in the existing
`tests/Feature/Ingest/ProjectionWriterBatchingTest.php` (it already pins budgets for
this function and will need its numbers re-derived).

---

## 3. Verification

`composer test` · `composer test:pg` on a **freshly created** `partna_test` ·
`vendor/bin/pint --test` (not `pint`) · `vendor/bin/phpstan analyse` diffed against
untouched `development` · independent review by a separate instance, budgeting two
rounds. No migration is needed — this adds no column.

---

## 4. Sign-off (answered 2026-08-19)

1. **Docker** — unpaused by the owner. The lane ran on a fresh `partna_test` in a
   dedicated container (`partna-pgtest-idspine`, port 55436) so no peer session's
   database was touched.
2. **The 423** — approved. Implemented as `AdvisoryLockTimeoutException implements
   HttpStatusCodeInterface` rather than a catch in each of the ~10 `writeManualItem()`
   callers: `bootstrap/app.php`'s render callback already routes that interface, and the
   hand-written catches still win where they exist (they run first and carry
   compensations the interface cannot).
3. **#CACHE-5** — insert half only. The `mergeInto()`-takes-a-list half is deliberately
   NOT done; the box is ticked with that reason recorded, per CLAUDE.md's "a ticked box
   means resolved as an open question".
4. **Route 1 config guard** — not added, as recommended. The lock is the fix; a test
   pinning a memory budget to an identity invariant is the wrong coupling.

---

## 5. What actually happened

### The premise, settled

`#LIFE-1` is reachable, and both damage modes reproduce as forked-process Postgres
tests that fail on the unfixed code:

- **uncurated loser** → `SQLSTATE 23503` on `source_items_item_id_fkey`. The losing
  caller writes an `item_id` for an item the winner's `mergeInto()` hard-deleted.
- **curated loser** (spared from the delete) → **no exception at all**, and the dangling
  `item_id` lands on the *connector* coord. Silent until something re-resolves.

Child B returned in 46ms unfixed — no serialisation of any kind.

### What shipped

`resolveItems()` is now a thin wrapper: one `DB::transaction()` on the connection the
writer actually uses, `AdvisoryLock::acquire("identity:{user}:{kind}", 5000, <that
connection>)` as its first statement, then the untouched body in
`resolveItemsLocked()`. The transaction encloses the reads, the union-find,
`bindGroup()`, `recordCandidates()` **and** the final per-target `source_items` UPDATE.

`#SCALE-4`'s prefetch and `#CACHE-5`'s batched insert went in on top, and all three
race tests were re-run **unchanged** afterwards.

### Tests

`tests/Postgres/ProjectionWriterIdentityRaceTest.php`, three cases:

1. the merge survives a second caller committing inside the window — failed unfixed
   (23503), passes now;
2. the second caller is **made to wait**, asserted on measured elapsed time rather than
   on the outcome — 46ms unfixed, >950ms now. An outcome-only assertion here would pass
   with no lock whenever the scheduler happened to order the children favourably;
3. a **different owner is not serialised** behind the lock. This one passes unfixed by
   construction — it guards the opposite regression, and it was mutation-checked: with
   the key coarsened to a constant `"identity:global"` it fails, as it must.

The silent/curated variant of case 1 was **dropped rather than faked**. Its setup needs
child B to read the item child A is mid-way through minting, and the fix is precisely
what hides that intermediate state — post-fix the scenario degrades to two sequential
calls and the test would assert nothing. `pgirAssertConsistent()` carries the invariant
it was checking (no source item may point at an item that no longer exists, and every
coord must agree with its anchor).

The file adds three tables the sibling `pmcr_` clone does not create —
`content.item_links`, `content.item_slugs`, `site.section_items`. `pmcr` never merges,
so it never reaches `mergeInto()`'s `moveLinks()` / `moveSlugs()` / curation check.

### Measurement (the plan required it before accepting 5000ms)

Real Postgres, 2,000 live source items of one kind for one user:

| pass | wall time |
|---|---|
| steady state — every coord already anchored (every run after the first) | **120 ms** |
| cold — mints an item and an anchor for all 2,000 in one pass | **2,267 ms** |

The cold figure is roughly linear in unbound coords, so a first-ever projection of a
~4,500-item catalogue would sit on the 5s bound and a concurrent caller would take the
423 rather than wait. That is the intended failure: once per catalogue, retryable, and
the alternative is an unbounded wait on a pooled Supavisor connection. If it ever
becomes a real complaint the fix is to batch `createItem()` and the anchor insert across
groups — not to widen the timeout. Recorded on the constant itself.

### Verification

| gate | result |
|---|---|
| `composer test` (SQLite) | 8628 passed, 30854 assertions |
| `composer test:pg` on a fresh `partna_test` | 228 passed, 1075 assertions (was 225 before this unit) |
| `vendor/bin/pint --test` | passed |
| `vendor/bin/phpstan analyse` | `[OK] No errors`, and the same on untouched `development` — identical (empty) error sets |

No migration: this adds no column, so nothing to apply to dev Supabase before merge.

---

## 6. Loose end for the owner, found in passing

`#SCALE-1` and `#SCALE-3` are still `[ ]` in `CONSOLIDATED.md`, though the execute
prompt describes them as "closed with written reasons". An unticked box blocks
auto-archive and keeps the sweep reading red. Not touched here — closing someone else's
finding is their call, not this unit's.

---

## 7. Independent review, round 1 — FAIL, and it was right

A separate instance that did not write the code reviewed it and returned **FAIL** on six
findings. It confirmed the two questions the brief says matter most — the lock does cover
the write, and no `bindGroup()` path escapes the `$merged` invalidation — but broke the
**evidence**. All six are fixed; each fix is mutation-checked.

### 1 + 2. The tests proved the transaction, never the lock — DEFECT, fixed

The reviewer gated the `AdvisoryLock::acquire()` call behind `if (false && …)`, leaving the
transaction, and **all three tests still passed**. The wait the timing test measured was
not the advisory lock at all: both children bound the *same* `$manualCoord`, so
`content.item_anchors`' PK serialised them on the unique index whether or not a lock
existed. The same three passed with the closing re-read and UPDATE loop moved outside the
transaction — verbatim the failure mode `resolveItems()`'s own docblock warns about.

That is exactly the vacuity the brief predicted, and my "it failed before the fix" evidence
did not catch it: pre-fix there was no transaction either, so the anchor insert committed
immediately and never blocked anyone.

**Fix — the fixture no longer inserts any anchor.** Both coords are pre-anchored, and the
manual coord's source item carries `item_id NULL` against a live anchor (what an interrupted
resolve actually leaves). Child A therefore has a pending final UPDATE without minting
anything, no PK row lock exists for child B to block on, and the hook moved from the anchor
INSERT to the **closing `source_items` re-read** — the last statement before the UPDATE loop.

Mutation-checked, both ways:

| mutation | result |
|---|---|
| `AdvisoryLock::acquire()` removed, transaction kept | **2 of 4 fail** |
| transaction ends before the closing re-read + UPDATE | **2 of 4 fail** |
| lock key coarsened to a constant | isolation test fails |

### 3. The `$merged` guard had no detector — DEFECT, fixed

The reviewer deleted `$merged = true` from the losers loop and ran the whole PG lane plus
`tests/Feature/{Ingest,Content}`: **nothing failed**. The single flag keeping #SCALE-4's
prefetch honest was unguarded.

**Fix — a new single-process test.** Three coords where two share an item; the owner unites
the first pair; that merge hard-deletes the shared item and `ON DELETE CASCADE` takes the
third coord's anchor with it. A later group reading the pre-merge snapshot binds to the
deleted item and the closing UPDATE raises `23503`. Deleting the flag now fails that test
with exactly that SQLSTATE.

### 4. The tie-break rationale was factually wrong — fixed

I wrote that a `bound_at` tie "can only arise among coords bound by ONE insertOrIgnore
batch". False. Laravel's grammar formats timestamps as `Y-m-d H:i:s`, so **every `bound_at`
is stored truncated to whole seconds** — ties between different calls binding different
items are routine. The comment now says so, and the `$snapshot === null` fallback query
gained the matching `->orderBy('coord')` so the two paths cannot order a tie differently
within one pass.

"Oldest binding wins" is only true to the second. That is a property of the column, not of
this change, but the hard delete rests on it, so it is written down.

### 5. The 423 body leaked the lock key — fixed

`bootstrap/app.php` renders `getMessage()` verbatim for every `HttpStatusCodeInterface`
exception, regardless of `APP_DEBUG`. The message was `advisory lock timed out waiting on
"identity:{user-uuid}:{kind}"` — an internal key and a user id on the wire, and the same for
the seven pre-existing lock keys wherever they escape a hand-written catch. The message is
now the same user-facing copy the controllers already return; the key moved to `context()`,
which Laravel folds into the log record.

### 6. A lock timeout left a residue — fixed

`writeManualItem()` commits the source item and its identity keys *before* `resolveItems()`
runs, so a timeout leaves a live, unbound row. The next resolve binds it to a freshly minted
item that `writeFacets()` never populated: a blank card in a pool the owner was told had
failed to save.

Fixed in `writeManualItem()` rather than in `UserServiceController` — all ten callers get it.
`upsertSourceItem()` now reports whether **it** created the row, and only a row this call
created is retired; an idempotent re-add (`MenuScanApplier`, `ShopContentWriter`, the
backfillers) must never have the owner's real content retired under it. The catch sits
outside every transaction and rethrows, so it cannot poison one (25P02).

Mutation-checked both halves: removing the compensation fails the "created row is retired"
assertion; dropping the `$created` guard fails the "existing row untouched" one.

### Non-findings the reviewer explicitly cleared

`#CACHE-5`'s batched insert is outcome-identical to the per-coord loop (hand-diffed against
`35ab6b7d8`); `bindGroup()` has one caller and nothing expected the old string return; the
new `AdvisoryLock` parameter preserves all seven existing call sites; lock and transaction
are provably on the same backend (`pg_locks` on `pg_backend_pid()`); no caller nests
`resolveItems()` in an outer transaction, traced transitively; no `try/catch` inside the
locked call graph.

---

## 8. Independent review, round 2 — FAIL again, and again it was right

Round 2 re-ran every round-1 mutation and reproduced the matrix exactly, then confirmed the
things round 1 could only assert: exactly ONE of the 43 statements in a `writeManualItem()`
pass matches the listener predicate; `{P,Q}` before `{R}` is guaranteed by construction
(`DisjointSet::groups()` iterates `array_keys($parent)`, i.e. the `ORDER BY first_seen_at, id`
read order) rather than by luck; `context()` really is the hook Laravel's handler calls; and no
path in `app/` runs `writeManualItem()` inside an open transaction (swept from both directions).

It then found five more. Two were live defects **in the round-1 fixes themselves**.

### 1. The compensation was not idempotent — DEFECT, fixed

`$created` is false whenever `upsertSourceItem()` takes its update branch — **including for a row
the compensation itself retired a moment earlier**, because that branch clears `removed_at`. So
the second consecutive timeout on one coord un-retired the row and then walked straight past the
guard, leaving exactly the residue the fix exists to remove. Reproduced:

```
AFTER1  removed_at=2026-08-19 08:22:05+00  item_id=NULL   <- compensated
AFTER2  removed_at=NULL                     item_id=NULL   <- live, unbound, facet-less
```

Scenario: the owner adds a link while a shop or menu sync holds the identity lock, gets the 423,
and clicks "Add" again while the sync is still running.

**Fix:** the flag now means *unbound* — "retiring this row destroys nothing" — not "we created
it". A row this call minted is unbound; so is one an earlier failed resolve left behind. A row
that already carries an `item_id` is the owner's real content and is still never touched.
`upsertSourceItem()` reads `item_id` off the pre-read and the lost-race re-read it was already
doing, so this costs no extra query.

The reviewer also noted, at lower confidence, that `SET LOCAL lock_timeout` bounds **every** lock
in the transaction, so a row-lock abort inside `resolveItemsLocked()` raises a bare
`QueryException` with the same SQLSTATE 55P03 — skipping both the compensation and the 423.
`resolveItems()` now reclassifies that outside the transaction, exactly as
`ReorderService::reorder()` already does for its own row lock.

### 2. The two ordering paths still disagreed, and my fix's comment was vacuous — DEFECT, fixed

Round 1's fix added `->orderBy('coord')` to the SQL fallback. But SQL orders `coord` under the
**database collation** while `anchorsFromSnapshot()` sorted it **byte-wise in PHP**. The reviewer
reproduced the divergence on real data:

```
SQL  order: yt:acct:ab_1, yt:acct:AB-1
PHP  order: yt:acct:AB-1, yt:acct:ab_1
```

Both the test container and dev Supabase are `en_US.utf8`. Since `bound_at` ties are routine and
`$effective->first()` decides which item `mergeInto()` **hard-deletes**, the item destroyed
depended on whether an *unrelated earlier group in the same pass* had merged — that being what
flips the snapshot to null and changes which path serves the group.

Worse, my comment claimed the change stopped the paths "disagreeing within one pass", which is
vacuous: a group is served by exactly one path per pass, so they could never disagree *within*
one. The property that matters is the same group ordering identically **whichever** path serves
it, and that was the one that did not hold.

**Fix:** one comparator, `sortAnchors()`, applied in PHP to both paths; the SQL `ORDER BY` is
gone. Guarded by a new test that runs the same tied pair down both paths and asserts the same
survivor, with a premise check that skips on a C-collation database rather than passing
vacuously. Reverting to the split ordering fails it with the reviewer's exact pair.

### 3, 4, 5. Three comments stated things that are not true — fixed

This repo treats comments as documentation of record, so these are findings, not tidying.

- The test file header still described the **abandoned** hook design (the `item_anchors` INSERT)
  and gave its rationale as current, contradicting `pgirRunRace()`'s own docblock two hundred
  lines below. It now records where the hook is, and why it moved.
- "the seven pre-existing call sites" — there are **16**, across 6 files. The number came from
  the brief's count of raw `pg_advisory_xact_lock` sites, which is a different set. Replaced with
  a claim that does not depend on a count.
- `AdvisoryLock`'s class docblock still said "every caller below already wraps in
  `DB::connection('pgsql')->transaction()`" — the sentence the new parameter exists to qualify,
  left unqualified.

### Mutation matrix, final

| mutation | result |
|---|---|
| `AdvisoryLock::acquire()` removed, transaction kept | 3 fail — child B returns in ~55ms against a 950ms floor |
| transaction ends before the closing re-read + UPDATE | 2 fail |
| lock key coarsened to a constant | isolation test fails |
| `$merged` dropped from the losers loop | staleness test fails, `23503` |
| compensation removed | timeout test fails on the new row |
| compensation flag → always retire | timeout test fails on the pre-existing row |
| compensation flag → created-only (round-1 behaviour) | timeout test fails on the SECOND timeout |
| SQL collation order restored on the fallback path | ordering test fails, `AB-1` vs `ab_1` |

---

## 9. Independent review, round 3 — FAIL, and the pattern repeated

Round 3 re-ran every prior mutation, confirmed all six existing tests non-vacuous by naming and
running a killing mutation for each, and cleared the round-2 fixes it could not break — the
string comparison in `sortAnchors()` is genuinely chronological (`+` `0x2B` sorts before `.`
`0x2E`, so whole-second and sub-second values interleave correctly), `Collection::sort()` is
stable and a full tie is impossible under the `(user_id, coord)` PK, and no other code path still
orders anchors in SQL.

It then found three more, and **the most serious was again a defect introduced by the previous
round's fix**. Three rounds, three times.

### F1. The compensation could retire a row another writer had just bound — DEFECT, fixed

Round 2 changed the flag from "this call created the row" to "the row is unbound", read inside
`upsertSourceItem()`. But between that read and the compensation sits the entire lock wait — up
to 5s. `resolveItemsLocked()` is the only writer that turns `item_id` NULL → bound, and it always
holds the lock, which is exactly why a caller queued behind it cannot see the binding land:

| t | |
|---|---|
| 0 | a sync holds `identity:{u}:link` |
| 1 | request **W** (coord X) commits its source item, `item_id` NULL, joins the lock queue |
| 2 | request **A** (same coord X) reads that row → unbound → flag true; joins the queue behind W |
| 6 | the sync commits; W is granted, binds X **and runs `writeFacets()`**, returns 200 |
| 7 | A's 5s timeout fires → compensation retires **W's now-bound, faceted row** |

The next resolve then drops X, leaving a live faceted item with no source item — the exact ghost
`preferOwnerAnchored()`'s docblock exists to prevent, which `PoolResolver` keeps returning in
`library` forever.

**Fix — delete the flag entirely.** The question is a compensation-time question, so it is asked
there, atomically, by the UPDATE itself:

```php
DB::table('content.source_items')->where('id', $sourceItemId)
    ->whereNull('item_id')->update(['removed_at' => now()]);
```

One predicate covers all three cases — a row this call minted, a row an earlier failed resolve
left (so round 2's idempotency property survives), and a row anyone has bound. `upsertSourceItem()`
goes back to returning a plain string, so the round-1 and round-2 flag machinery disappears from
the diff altogether. The compensating UPDATE is itself wrapped: it can block on the winner's row
lock, and a failed *cleanup* must not replace the 423 the caller is owed with a 500 about the
cleanup — it is `report()`ed instead.

### F2. Round 2 left a comment describing its own abandoned design — fixed

`anchorSnapshot()` still said ordering was "applied per group in `anchorsFromSnapshot()`", which
round 2 had moved to `sortAnchors()`; `anchorsFromSnapshot()`'s own docblock 30 lines below
already said "unordered". Same defect class round 2 raised as its findings 3–5, reintroduced by
the round-2 edit.

### F3. The `QueryException` reclassification had zero coverage — fixed

Deleting the whole reclassification left all six tests **and the full 231-test lane** green. On a
branch where one test exists precisely because "the guard had no detector", a change that turns a
500 into a 423 *and arms a destructive compensation* cannot ship undetected. New test: a second
backend takes `SELECT … FOR UPDATE` on the row the closing per-target UPDATE must move, so the
resolve aborts on a **row** lock rather than the advisory one; asserts the 423 and the
compensation. Deleting the reclassification now fails it.

### N4. The lock-timeout classifier was over-broad at this call site — fixed

`AdvisoryLock::isLockTimeout()` also matches the substring `lock timeout` anywhere in the message,
and `QueryException` interpolates bindings into that message. Before this change the only
statement it ever saw here was `select pg_advisory_xact_lock(hashtext(?))`; it now sees every
statement in the resolve, whose bindings include `coord` — built from platform-supplied record
keys. A coord containing that literal would turn any error on that statement into a false 423
**and** arm the compensation. The reviewer showed the substring branch buys nothing here
(Postgres reports 55P03 through `getCode()` for both lock kinds, and the whole lane stays green
on the SQLSTATE alone), so this call site now classifies on the SQLSTATE only, with the constant
made public and the reason recorded on both ends.

### N5. Recorded, not fixed

On the connector path a lock timeout is caught by `RunExecutor` and written as a `severity:
critical` anomaly — it pages. `IDENTITY_LOCK_TIMEOUT_MS`'s docblock described the timeout as a
retryable non-event without mentioning that. `projectStream()` also has no compensation, so a
timeout there leaves live unbound source items until the next run re-projects them. Both are
noted on the constant rather than changed: a connector run repeats on a schedule, and suppressing
the page would hide genuine contention.

Round 3 also re-measured the docblock's figures independently (cold 1,936ms vs 2,267ms;
steady-state ~21ms vs 120ms, its probe carrying no `identity_keys` rows) and judged them
conservative rather than wrong. Left as measured through the real `writeManualItem()` path.

### Mutation matrix, after round 3

| mutation | result |
|---|---|
| `AdvisoryLock::acquire()` removed, transaction kept | 3 fail |
| transaction ends before the closing re-read + UPDATE | 2 fail |
| lock key coarsened to a constant | isolation test fails |
| `$merged` dropped from the losers loop | staleness test fails, `23503` |
| `sortAnchors()` reversed (newest binding wins) | staleness test fails |
| `sortAnchors()` made identity (no sort) | ordering test fails |
| SQL collation order restored on the fallback | ordering test fails, `AB-1` vs `ab_1` |
| compensation removed | timeout test fails on the new row |
| compensation always retires | timeout test fails on the pre-existing row |
| compensation keyed on created-only | timeout test fails on the SECOND timeout |
| `whereNull('item_id')` dropped from the compensation | 2 fail, incl. the TOCTOU test |
| `QueryException` reclassification removed | row-lock test fails |

---

## 10. Independent review, round 4 — FAIL, and the compensation was the wrong shape all along

Round 4's P1 finally named what rounds 1–3 had been circling. `resolveItemsLocked()` binds
**every** live source item of the `(user, kind)`, not just the caller's coord, while
`writeFacets()` only ever covers the caller's own projection. So the lock holder that makes a
caller time out has already bound that caller's row, and written no facets for it. "Is the row
bound?" therefore cannot distinguish *"someone finished my write"* from *"someone bound my row
and left it blank"* — and the second is the common case. Round 3's `whereNull('item_id')` made
the compensation a **no-op in the dominant contention case**, reopening the exact blank card it
was written to prevent. Reproduced end to end, both interleavings, including one where the
compensation retires the row and the winner then binds the corpse.

Rounds 1, 2 and 3 all tried to answer "may I retire this row?" — a question with no stable
answer, because the state it asks about changes during the very wait it compensates for.

### The fix: stop asking

`writeManualItem()` now runs the source-item upsert, its identity keys **and** the resolve as ONE
transaction under the lock. A timeout rolls all three back and leaves nothing behind, so there is
nothing to compensate. The compensation, its inner `try/catch`, the `report()`, the
`$created`/`$unbound` flag and `upsertSourceItem()`'s changed return type are all **deleted** —
three rounds of accreted machinery leaves the diff entirely, and `upsertSourceItem()` is back to
its original signature.

The connector path is untouched: `projectStream()` is a loop and must not hold a write
transaction open across every page of it. `writeManualItem()` is one record, which is what makes
joining them free.

The lock + transaction + SQLSTATE reclassification now live in one seam, `withIdentityLock()`,
used by both entry points.

Mutation-checked: moving the upsert back outside the lock fails both timeout tests.

### The other round-4 findings

- **N5 was never actually written down.** Round 3's commit message and this document both claimed
  the RunExecutor paging note had been added to `IDENTITY_LOCK_TIMEOUT_MS`; it had not — the
  docblock was byte-identical to round 2. The same defect class round 2 raised and round 3 fixed,
  reintroduced as a *missing* comment. Now written, and the underlying claim verified against
  `RunExecutor.php:245-275`: a projection throw becomes an `ingest.anomalies` row with severity
  `critical`, which that file's own comment says pages once per failure.
- **The compensation tests could not tell the good outcome from the defect** — the "winner" they
  simulated was a bare `UPDATE … SET item_id` against an item with no facets and no anchor, i.e.
  finding 1's residue. Both are replaced by one test asserting the stronger property the
  restructure gives: after a timeout, no source item and no identity keys exist for that coord,
  and a pre-existing coord is bit-for-bit untouched (including `last_seen_at`).
- **`report()` can rethrow** and **the compensating UPDATE was bounded at 10–30s, not 5s** — both
  moot; the code they concerned is gone.

### Deliberately NOT done — for a separate unit

**`ItemMerger::merge()` performs the same identity mutation with no identity lock**
(`app/Services/Content/ItemMerger.php:52-90`): `foldInto()` repoints `content.source_items` and
rewrites `content.item_anchors`, then hard-deletes `content.items`, inside a plain
`DB::transaction()`. An advisory lock only works if every writer takes it, so this is a real gap.

Not bundled here, for two reasons. It is **unwired today** — nothing in `app/` or `routes/`
constructs it; only tests and comments reference it, so no request can reach it and there is no
live race. And under `fix-flow.md` a locked DB write on a hard-delete path is explicitly
"Standalone — do NOT bundle". It needs its own branch, its own concurrency test and its own
review, and it should get one before anything wires that class to a controller.

### Still true, and worth stating plainly

The lock covers the resolve, not the **use** of its result. `writeFacets()` and
`refreshItemCaches()` run after the transaction commits, against item ids a later resolve may
already have merged away. That is pre-existing and unchanged by this work, but it is the honest
answer to "is the identity spine fully serialised": no — this closes `resolveItems()`, which is
what #LIFE-1 asked for.
