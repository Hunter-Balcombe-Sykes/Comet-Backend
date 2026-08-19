# #LIFE-1 — identity resolution has no lock and no transaction

**PLAN ONLY. Nothing here is implemented.** Produced unattended on 2026-08-19 by
the P1 overnight run, which was explicitly forbidden from implementing it: this is
an L-effort concurrency-correctness change to the core content-identity spine, and
an unattended session is the wrong place for it. Sign off before anyone writes code.

Verified against `origin/development` @ `60e142011`.

---

## 1. What is actually unprotected

`ProjectionWriter::resolveItems($userId, $kind)` (`:593`) is a read → compute →
write cycle with **no transaction and no lock** around the whole:

1. **Read** every live `content.source_items` of `(user, kind)` (`:609-618`).
2. **Read** their `content.identity_keys` (`:632-645`) and the user's
   `content.identity_decisions` (`:660-664`).
3. **Compute** — `Resolver::resolve()` is a pure global union-find in PHP.
4. **Write** — `bindGroup()` per group (`:719`), then `recordCandidates()`, then a
   re-read of `content.source_items` and one `UPDATE … SET item_id` per distinct
   target (`:686-707`).

Between (1) and (4) another process can change every input.

### What is ALREADY protected — do not re-solve these

- **The same-coord anchor insert.** `#PGR-7` hardened it: `insertOrIgnore` on
  `content.item_anchors` (PK `(user_id, coord)`), plus adopt-the-persisted-winner
  and a `$boundHere` redirect for coords already bound under the losing item
  (`:745-800`). Covered by `tests/Postgres/ProjectionWriterManualCoordRaceTest.php`.
- **Per-record landing atomicity** — each record's projection has its own
  `DB::transaction()` (`:199`, `:396`).

`#LIFE-1` is what is left: the **group-level** race, not the row-level one.

## 2. Who actually runs this concurrently

This matters, because "add a lock" is only worth it if two writers really collide.
They do, by two distinct routes:

1. **Two sources of the SAME user, same kind, in parallel.** `SourceScheduler::claimOne()`
   claims a **source** row, not a user. A user with Instagram *and* Apple Music has
   two `ingest.sources`; both `RunSourceJob`s can be in flight at once, and both
   call `projectStream()` → `resolveItems($sameUserId, 'release')`. Nothing
   serialises them. This is the common case and it is not exotic.
2. **A dashboard write racing a projection run.** `writeManualItem()` /
   `PoolItemCreateController` bind coords for the same user while a scheduled run
   is mid-resolve. This is the route `#PGR-7` found — the same-coord half is fixed,
   the different-coord half is not.

## 3. The failure modes, concretely

**(a) Split identity (lost merge).** Run A reads the source-items before run B
inserts the `identity_key` that would have united two groups. A resolves them as
two groups and binds two items. B then resolves them as ONE group, and
`bindGroup()` picks a winner and calls `mergeInto()`. Outcome is *eventually*
right — but only because B happened to run second. If B commits its
`source_items.item_id` UPDATE before A's, A's stale `$itemByCoord` overwrites it
and the merge is silently undone. The user sees one song as two cards until some
later run happens to re-resolve in a luckier order.

**(b) Lost update on `source_items.item_id`.** `:686-707` is read-then-write
across two statements with no fencing. Both runs compute `$idsByTarget` from their
own snapshot; last writer wins wholesale. There is no version column, no
`WHERE item_id = <expected>`, so nothing detects it.

**(c) `mergeInto()`'s hard delete under a concurrent reader.** `bindGroup()` calls
`mergeInto($userId, keptItemId, discardedItemId)` (`:898`), which deletes the
discarded `content.items` row. A concurrent `resolveItems()` holding that id in its
in-memory `$itemByCoord` will then write a **dangling `item_id`**, or hit the FK.
The `$mintedOwnItem` shortcut at `:790` is safe (nothing else can reference an item
minted inside this call); the `mergeInto()` branch is not.

**(d) Candidate churn.** `recordCandidates()` writes from the same stale snapshot.
Cosmetic next to (a)–(c), but it is the same root cause.

**Blast radius if it goes wrong in the fix:** every pool on every sitepage. Identity
IS the content spine — `content.items` is what `PoolResolver` selects, what pins
point at, what slugs hang off. A bad merge is user-visible and, because
`mergeInto()` hard-deletes, **not always reversible**.

## 4. Recommended fix — advisory lock, not row locks

**Use `pg_advisory_xact_lock`, keyed on `identity:{user_id}:{kind}`.**

This is already the repo's idiom for "serialise a compute-then-write over a
user-scoped set" — seven existing call sites, including `services:{user_id}` in
`FreshaFetch`/`FreshaConnectFetch` and `blocks-sections:{site_id}` in
`UserSectionBlockController`:

```php
DB::select('select pg_advisory_xact_lock(hashtext(?))', ["identity:{$userId}:{$kind}"]);
```

Wrap `resolveItems()`'s body — reads, resolve, `bindGroup()` loop,
`recordCandidates()`, and the final `item_id` UPDATE — in one
`DB::transaction()`, taking the advisory lock as its **first** statement.

**Why advisory rather than `lockForUpdate()` on the rows:**

- The set being protected is not a fixed row set — it is "every live source_item of
  this (user, kind)", which the resolver may *grow* mid-computation. You cannot
  `SELECT … FOR UPDATE` rows that do not exist yet, so row locks cannot prevent (a).
- `bindGroup()` writes `content.item_anchors`, `content.items` and
  `content.source_items` — three tables. Lock ordering across them is a deadlock
  source; one advisory key is not.
- `hashtext()` collisions are harmless here: a collision costs unnecessary
  serialisation between two unrelated users, never incorrectness.

**Why `_xact_` and not the session variant:** it releases on COMMIT/ROLLBACK
automatically. A session lock leaked by a killed worker would wedge that user's
identity resolution until the connection is reaped — on a pooled Supavisor
connection that is a genuinely bad failure mode.

### Things that will bite

1. **Transaction length.** `resolveItems()` now holds a write transaction across a
   PHP union-find over every source-item of the kind. Measure first — if the
   resolve is slow for a large catalogue this becomes a long-held lock, and
   `SET LOCAL lock_timeout`/`statement_timeout` are mandatory, with a clean
   degrade path when the lock is not obtained.
2. **Nested transactions.** `projectStream()` already opens transactions per record
   (`:199`, `:396`) and a chunked one at `:1306`. Confirm `resolveItems()` is not
   already inside one — if it is, `DB::transaction()` becomes a SAVEPOINT and the
   advisory lock's scope silently becomes the OUTER transaction. That changes the
   analysis and may be fine, but it must be checked, not assumed.
3. **The catch-and-recover trap.** Any `try/catch` that recovers *inside* this new
   transaction poisons it with 25P02. This repo has shipped that bug three times
   (`ItemSlugAllocatorSavepointTest`). Catch outside, as `Lander::land()` does.
4. **SQLite has no `pg_advisory_xact_lock`.** The Feature lane runs SQLite, so the
   lock call must be driver-guarded (`DB::connection()->getDriverName() === 'pgsql'`)
   exactly as the seven existing call sites are — check how they do it and copy.
   **Consequence: a green `composer test` proves nothing about this change.**

## 5. Test approach

`tests/Postgres/` only — this is not reproducible on SQLite under any arrangement.
Model on `tests/Postgres/ProjectionWriterManualCoordRaceTest.php` and
`ClaimConcurrencyTest.php`, both of which already fork real concurrent claimers.

1. **Prove (b) first, before fixing it.** Two forked processes calling
   `resolveItems()` for the same `(user, kind)`, with a barrier so their reads
   interleave; assert the final `source_items.item_id` matches the *union* result.
   It must FAIL on today's code — if it passes, the race is not reachable the way
   this plan claims and the whole unit should be re-scoped.
2. **Prove (a)**: insert the uniting `identity_key` between A's read and A's write.
3. **Prove (c)**: force `mergeInto()`'s delete to land between another process's
   resolve and its `item_id` UPDATE; assert no dangling `item_id` and no FK error.
4. **Then** add the lock and assert all three go green, plus that a second caller
   genuinely blocks (measure it — a test that would pass with no lock at all is the
   usual way this kind of work goes vacuously green).
5. Re-run the whole `tests/Postgres/` lane: touching `ProjectionWriter` means
   running `composer test:pg`, whose stand-in DDL is hand-written and drifts
   silently (slice 5a turned it red for 7 tests and two reviews missed it).

## 6. Rejected alternatives

- **Serialise at the job level** (one `RunSourceJob` per user at a time, via a
  `ShouldBeUnique` key on `user_id`). Simpler, but it throttles unrelated sources
  of the same user for a race that only touches identity, and it does nothing about
  route 2 (the dashboard write).
- **Optimistic concurrency** (version column on `content.items`, retry on
  conflict). More moving parts, and the retry would have to re-run the whole
  union-find; the advisory lock gets the same correctness for a fraction of it.
- **Do nothing.** Defensible *today*: production carries no `content` schema at all
  and zero customers, so nothing is live. It is not defensible at pilot, when one
  user with two music connections is the ordinary case rather than the exotic one.

## 7. Effort

L. Roughly: half a day to build the three failing Postgres tests (that is the real
work — forking interleaved writers with a barrier is fiddly), an hour for the lock
itself, then a full `test:pg` + `composer test` cycle and an independent review.
The tests are worth having even if the lock design changes.
