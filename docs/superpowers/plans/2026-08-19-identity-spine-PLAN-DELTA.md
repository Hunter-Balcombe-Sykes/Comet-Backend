# Plan delta — #LIFE-1 + #SCALE-4 + #CACHE-5 (identity spine)

Branch `audit-fix/identity-spine-2026-08-19`, worktree under the session
scratchpad, based on `origin/development` @ `35ab6b7d8`.

**Status: awaiting sign-off. No code written yet.**

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

## 4. What I need from you before writing code

1. **Docker is paused.** `docker desktop status` → `paused`, and the CLI cannot
   unpause it (`docker desktop start` reports "already running"). The Postgres lane is
   the *only* place any of this can be tested. Please unpause from the whale menu — or
   tell me to `docker desktop restart`, which should clear it.
2. **The 423.** Do you want `AdvisoryLockTimeoutException` mapped to a 423 on the
   dashboard write paths (my recommendation, matching existing precedent), or left to
   propagate as a 500? It is a public-wire change either way, which is why I am asking
   rather than picking.
3. **#CACHE-5's merge half** — accept my "batch the inserts, leave `mergeInto()`
   alone, tick with the reason recorded", or do the full thing?
4. **Route 1's config dependency.** Do you want a guard test pinning
   `supervisor-ingest`'s `maxProcesses => 1`, given the lock now makes raising it safe
   anyway? My inclination is no — the lock is the fix, and a test pinning a memory
   budget to an identity invariant is the wrong coupling. Recorded here so the next
   session doesn't rediscover it.
