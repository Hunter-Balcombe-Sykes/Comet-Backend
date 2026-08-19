# Execute prompt — #LIFE-1 + #SCALE-4 + #CACHE-5, the identity spine

**These three are ONE unit.** They are the last open P1s from the 2026-08-18
overnight-run sweep. The other eleven shipped on 2026-08-19
(`audit-fix/p1-overnight-2026-08-19`, merged); #SCALE-1 and #SCALE-3 are closed
with written reasons and are NOT in scope here.

**Read first, in this order:**
1. `docs/superpowers/plans/2026-08-19-LIFE-1-identity-race-plan.md` — the design,
   already written and verified against the code. Failure modes, the proposed
   lock, the test approach, and four things that will bite.
2. `docs/superpowers/plans/2026-08-19-P1-overnight-DECISIONS.md` §9 — why
   `#SCALE-4` was deliberately NOT done alone, which is the reason this prompt
   exists.
3. `audits/sweeps/2026-08-18-overnight-run/CONSOLIDATED.md` for the raw findings.

**This is a BLOCKER-GATE unit** under `scripts/audit/fix-flow.md`: L effort, core
content-identity spine, and `mergeInto()` HARD-DELETES so a wrong merge is not
fully reversible. Do NOT run it unattended. Produce the plan delta, get sign-off,
then implement.

---

```
Rename this session to identity-spine-life1.

Work #LIFE-1, #SCALE-4 and #CACHE-5 as ONE unit, following
scripts/audit/fix-flow.md. Branch audit-fix/identity-spine-<date> off
development, in your OWN git worktree under your scratchpad — NOT in
.worktrees/, which peer sessions delete out from under running processes. Copy
vendor (`cp -a <main>/vendor ./vendor`), do NOT symlink it, or Pest's ->in('Feature')
binding resolves to the main checkout and you get ~1100 fake failures. Symlink .env.

RULE ZERO — VERIFY THE PREMISE BEFORE YOU FIX IT.
The plan file was written against the code and its claims were checked, but it
is a day old and this area moves. Re-verify every line number and every claim
before acting on it. Four of the five findings in the previous run had a wrong
premise somewhere; expect one here.

In particular, PROVE THE RACE IS REACHABLE BEFORE FIXING IT. The plan says two
sources of the SAME user and kind can project concurrently because
SourceScheduler::claimOne() locks a SOURCE, not a user. If that turns out to be
wrong — if something upstream already serialises per user — then #LIFE-1 is not
reachable, the right outcome is a WONTFIX with that evidence, and #SCALE-4
becomes a simple batching change after all. Establishing which world we are in
is the first task, not an afterthought.

SCOPE, and why it is one unit.
  * #LIFE-1 — resolveItems()/bindGroup() run read → compute → write with no lock
    and no transaction.
  * #SCALE-4 — one content.item_anchors read per identity group; the remedy is
    "hoist the read above the loop".
  * #CACHE-5 — one anchor INSERT per coord, same function.

  #SCALE-4 and #CACHE-5 are NOT independently safe. That loop MUTATES the table
  the hoisted read would prefetch: bindGroup() inserts anchors (insertOrIgnore,
  #PGR-7), UPDATEs item_anchors.item_id on a lost race, and calls mergeInto(),
  which rewrites superseded_by on anchors that OTHER groups in the same loop are
  about to read. Prefetching across that is a staleness bug whose failure mode is
  a wrong merge on the identity spine.
  Under #LIFE-1's lock and transaction the prefetch becomes safe almost for free.
  So: LOCK FIRST, BATCH SECOND, in the same unit. Do not reorder.

WHAT IS ALREADY KNOWN — do not re-derive:
  * pg_advisory_xact_lock(hashtext(?)) is the established repo idiom for exactly
    this shape — SEVEN existing call sites, e.g. `services:{user_id}` in
    FreshaFetch/FreshaConnectFetch and `blocks-sections:{site_id}` in
    UserSectionBlockController. Copy how they driver-guard it; SQLite has no such
    function, so the Feature lane cannot exercise the lock AT ALL.
  * #PGR-7 already hardened the SAME-coord insert race (insertOrIgnore + adopt
    the persisted winner + redirect $boundHere). Do not re-solve it. What is left
    is the GROUP-level race.
  * release()/scoreDue() are NOT involved. This is projection, not scheduling.

HAZARDS, each of which has cost a session before:
  * A catch that RECOVERS inside the new transaction poisons it with 25P02. This
    repo has shipped that bug three times (ItemSlugAllocatorSavepointTest). Catch
    OUTSIDE, as Lander::land() does.
  * Check whether resolveItems() is ALREADY inside a transaction. If it is,
    DB::transaction() becomes a SAVEPOINT and the advisory lock's scope silently
    becomes the OUTER transaction. projectStream() opens transactions at :199,
    :396 and a chunked one at :1306 — confirm, do not assume.
  * Transaction length. The lock would be held across a PHP union-find over every
    source_item of the kind. Measure it for a large catalogue before deciding the
    lock is free; SET LOCAL lock_timeout / statement_timeout and degrade cleanly
    when the lock is not obtained.
  * tests/Postgres/ hand-writes its own ingest/content DDL and drifts SILENTLY.
    A new column or table reference breaks seven files at once with 42703 while
    the SQLite suite stays green — that happened on 2026-08-19 with
    needs_eager_run. Run the PG lane on a FRESH database (drop and recreate
    partna_test); a reused one hides exactly this.

TESTS — tests/Postgres/ only. SQLite cannot reach any of it.
  Model on ProjectionWriterManualCoordRaceTest.php and ClaimConcurrencyTest.php,
  which already fork real concurrent writers with a barrier.
  1. Write the FAILING test first, for the lost update on source_items.item_id.
     It must FAIL on today's code. If it passes, stop — the race is not reachable
     the way the plan claims, and the whole unit needs re-scoping (see RULE ZERO).
  2. Then the split-identity case (insert the uniting identity_key between A's
     read and A's write).
  3. Then the mergeInto() hard-delete case (no dangling item_id, no FK error).
  4. Only then add the lock, and assert all three go green AND that a second
     caller genuinely blocks — measure it. A lock test that would pass with no
     lock at all is the usual way this work goes vacuously green.
  5. Only then hoist the #SCALE-4 read and batch the #CACHE-5 inserts, and re-run
     all of the above unchanged. If any of them needs its assertions relaxed to
     accommodate the batching, the batching is wrong.

VERIFICATION — all of it, and report real output:
  * `composer test` (full SQLite suite)
  * `composer test:pg` on a FRESH partna_test database
  * `vendor/bin/pint --test` — the CI gate. NOTE `vendor/bin/pint` FIXES and then
    reports "passed"; that is not the gate and reporting on it is claiming a gate
    is green having never run it.
  * `vendor/bin/phpstan analyse` — compare the error set against untouched
    development, byte for byte. development carries pre-existing errors; count
    alone is not evidence.
  * Independent review by a SEPARATE instance that did not write the code. Every
    review in the previous run but one returned FAIL first and every one of those
    found something real, including two defects in fixes made for earlier review
    catches. Budget for at least two rounds.

WHEN DONE:
  * Tick #LIFE-1, #SCALE-4 and #CACHE-5 in CONSOLIDATED.md and re-derive the
    per-lens `## Progress` counts from the checkboxes rather than editing them by
    hand.
  * A migration, if any, goes to dev Supabase (glncumufgaqcmqhzwrxm) BEFORE the
    merge — code that reads a new column merged first throws 42703 the moment
    development auto-deploys. The Supabase MCP applies DDL but writes NO
    supabase_migrations.schema_migrations row; insert it by hand.
  * Do not merge without sign-off. This is the identity spine.
```

---

## For the reviewer of this unit specifically

The single highest-value question: **does the lock actually cover the write?**
It is easy to take an advisory lock, hold it across the read and the union-find,
and then commit the `source_items.item_id` UPDATE outside it — which looks right,
tests green, and fixes nothing. Check the transaction boundary encloses
`recordCandidates()` and the final per-target UPDATE loop, not just `bindGroup()`.

The second: **is the prefetch invalidated by anything inside the loop?** Under a
lock no *other* process can interleave, but `mergeInto()` can still stale the
snapshot *within* the same call. A correct fix either re-reads after a merge or
proves merges cannot touch another group's anchors.
