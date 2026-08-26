# DEFERRED — PART 1, unit 3, `#LIFE-13` only

**Finding:** `#LIFE-13` (`audits/sweeps/2026-08-18-overnight-run/CONSOLIDATED.md:578`) — generic
`QueryException` catch in `IntegrationConnectionObserver::syncIngestSource()` hides real database
failures at `Log::debug` level.

**DEFER trigger: §1.2 #7** — "It would require editing a file another unit in this same run has
already changed in a conflicting way, and reconciling them is not obvious."

The other two findings in unit 3 (`#LIFE-15` `PoolResolver`, `CCH-5` `ShortLinkExpander`) were
**implemented and shipped**. This defers `#LIFE-13` alone.

---

## What I found

`EXECUTE-PART-1.md` §4 unit 3 anticipated that the pre-pilot tranche's unit 6 would have fixed this
already, and told me to tick `ALREADY FIXED` if the file reported instead of `Log::debug`.

Reality is the awkward middle case:

- The pre-pilot tranche (`step 1` of `scripts/audit/run-overnight-2026-08-25.sh`) **did** write that
  fix — but its `claude -p` was **terminated mid-unit-6 at 05:07:04** by the print-mode
  background-task ceiling, before committing. See `RESULT-PART-1.md` §1.
- So the fix exists **only as uncommitted work**, which this run had to stash to get a clean tree:

  ```
  stash@{0}  On audit-fix/pre-pilot-blockers-2026-08-25: ORPHANED step-1 pre-pilot unit-6 WIP (#LIFE-13/14) …
  ```

- On `development` as it stands, `app/Observers/Core/IntegrationConnectionObserver.php:350-355` still
  reads `Log::debug`. The defect is live.

## Why I did not just re-implement it

**The stashed fix is better than the one I would have written, and re-doing it would make things worse.**

`git diff` of the stash against `development` shows step-1 split the catch in two:

```php
} catch (UniqueConstraintViolationException) {
    // The benign, expected outcome of a concurrent duplicate save, and
    // the ONLY quiet arm here. …
} catch (QueryException $e) {
    report($e);
    Log::warning('IntegrationConnectionObserver ingest-source sync query failure', [...]);
```

That split is **load-bearing and coupled to `#LIFE-14`**, which is in the same stash:
`SourceProvisioner::sync()` was changed to `insertOrIgnore`, so the benign insert race stops reaching
this catch at all.

Without `#LIFE-14`, the benign concurrent-duplicate race **does** still reach this catch. A naive
"report every `QueryException`" fix — which is what unit 3's brief on its own implies — would
therefore fire `report()` on **normal operation**, turning a silent-failure bug into a Nightwatch
noise bug. The two findings have to land together.

Re-implementing here would also have guaranteed a merge conflict on that file against strictly better
work.

## The plan I had reached

If it had to be done standalone, it would be:

1. `use Illuminate\Database\UniqueConstraintViolationException;`
2. Add a quiet `catch (UniqueConstraintViolationException)` arm ABOVE the `QueryException` arm
   (order matters — `UniqueConstraintViolationException extends QueryException`, so the general arm
   would otherwise swallow it first).
3. Change the surviving `QueryException` arm from `Log::debug` to `report($e)` + `Log::warning`.
4. **But do not do this without `#LIFE-14`'s `insertOrIgnore` landing in the same commit**, for the
   reason above.

## Concrete next step for a human

**Finish pre-pilot unit 6 rather than redoing it here.** In order:

```bash
git checkout audit-fix/pre-pilot-blockers-2026-08-25
git stash pop                      # restores SourceProvisioner + observer + 3 test files
```

Then review and commit it as pre-pilot unit 6 (`#LIFE-13` + `#LIFE-14` together), and tick `#LIFE-13`
in **both** places — `audits/sweeps/2026-08-18-overnight-run/CONSOLIDATED.md:578` and the pre-pilot
tranche's own list. The stash also carries `tests/Feature/Ingest/IngestSourceSyncReportingTest.php`,
`tests/Feature/Ingest/SourceProvisionerInsertRaceTest.php` and
`tests/Postgres/SourceProvisionerInsertRacePgTest.php`, so the tests for it are already written.

⚠️ Per CLAUDE.md, `SourceProvisioner`/`ProjectionWriter`-adjacent changes need
`composer test:pg` (`tests/Postgres/`), not just a green SQLite run — and step-1 wrote a Postgres-lane
test for exactly that, which never got to run.

**Status of the box:** left **unticked** in `audits/sweeps/2026-08-18-overnight-run/CONSOLIDATED.md`,
per §1.2 step 2.
