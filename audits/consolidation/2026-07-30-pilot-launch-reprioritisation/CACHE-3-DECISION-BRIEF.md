# `#CACHE-3` — decision brief: decouple projection from landing?

**Written 2026-07-30 on `audit-fix/tier1-2026-07-30`. No code was written for this item — it is
Tier 1 item 5, "decision brief only".**

> ## ✅ RULED 2026-07-31 — Josh accepted the recommendation below.
>
> **Projection stays inside the ingest job. The `Bus::chain` fix is REJECTED outright, not
> postponed.** `#CACHE-3` is ticked in both `CONSOLIDATED.md` and its source
> (`audits/sweeps/2026-07-28-content-platform-rebuild/`) as **WONTFIX-as-prescribed** — the box closes
> the *question*, not the code. Reopen only on one of the named triggers at the foot of this file, and
> if triggered, design **bounding projection** via the existing `projection_*_chunk` levers first.
>
> **The two "worth doing regardless" items were implemented on `audit-fix/cache3-2026-07-31`** — see
> the Recommendation section. Neither needed this finding; both were incidental discoveries made while
> verifying it. That is the actual value this brief produced.

**The ask.** `#CACHE-3` proposes moving projection out of the ingest job into a chained job, so the
ingest job returns sooner. Two shipped invariants were believed to block it. Both do — and the
throughput premise underneath the finding does not hold.

---

## Verified state

**Claim 1 — the `degraded` downgrade is real, and it is bigger than one row.**
`app/Ingest/Runtime/RunExecutor.php:168-193` wraps `projectStream()` in a try/catch that does
`$worstOutcome = $this->worse($worstOutcome, 'degraded')` (`:180`), appends a `projection_error`
note, and files an `ingest.anomalies` row with `kind => 'projection'` (`:182-191`).
`ingest.runs.outcome` is written **once**, at `:196-203`, after every stream has both landed *and*
projected. Chaining finalises it as `ok` before projection runs. Pinned by
`tests/Feature/Ingest/RunExecutorProjectionTest.php:102,107`.

**The part the finding missed:** the signal does not stop at the run row. `RunSourceJob.php:77-82`
feeds `$result['outcome']` straight into `SourceScheduler::release()`, and
`SourceScheduler.php:145,154` treats `'degraded'` as a qualifying outcome that stamps
`sources.health = 'degraded'` while leaving `consecutive_failures` at 0. Any decoupling must
reproduce **two** writes from a code path that has already returned, not one.

**Claim 2 — the `finally` release is real, and there is no lease.**
`RunSourceJob.php:89-99` releases in `finally`; the normal release is at `:77`. `claimDue()`
(`SourceScheduler.php:36-63`) is a conditional `UPDATE … WHERE in_flight_since IS NULL`, one row at
a time — **no `FOR UPDATE SKIP LOCKED`, no lease TTL, no `claimed_until`**. The only expiry is
`releaseStranded()`, `STRANDED_AFTER_SECONDS = 7200` (`:29`), swept hourly by `ingest:stranded`
(`routes/console.php:467-471`). Schema confirms: `ingest.sources` carries `in_flight_since` /
`in_flight_run_id` only (`supabase/migrations/20260727130000_ingest_schema.sql:47-48`).

*The re-claim window is narrower than the finding implies.* `release()` sets
`next_attempt_at = now() + intervalFor(...)`, floored at `min_interval_secs` (DDL default **3600**),
so a released source is normally not re-claimable for an hour. The real race is
`SourceProvisioner.php:89,105`, which sets `next_attempt_at = now()` on create and on identifier
change — a reconnect makes the source claimable on the next dispatcher tick.

**Claim 3 — `ProjectionWriter` is not self-concurrent-safe, but the worst site is not the one named.**
`replaceCollections()`'s delete-then-insert (`ProjectionWriter.php:755-766`) **is** wrapped in
`DB::transaction` per chunk, so two concurrent runs on the same `(item_id, source_id)` produce
*duplicated* rows, not lost ones. The genuinely unguarded window is `writeIdentityKeys()`:
`DELETE … WHERE source_item_id = ?` at `:266`, matching `INSERT` at `:287`, with a projector call and
an upsert in between and **no transaction at all**. A concurrent `resolveItems()` (`:367-402`)
reading identity keys inside that gap computes a union-find over a source item that appears to have
zero keys — a silently wrong merge decision. `bindGroup()`/`mergeInto()` (`:454-537`) are
read-modify-write with no locking either.

---

## Throughput evidence

`RunSourceJob`: `$timeout = 120` (`:36`), `$tries = 1` (`:38`), `$backoff = 0` (`:48`), no
`$retryUntil`, no `$maxExceptions`, `onQueue('ingest')` (`:52`). Lane is `supervisor-ingest`
(`config/horizon.php:316-333`) — timeout 120, memory 192, and **`maxProcesses => 1` in production,
development and local** (`:377-398`), with an explicit instruction not to raise it before a box
resize (`:367`).

**Measured on dev (`glncumufgaqcmqhzwrxm`, live `ingest.runs`): 37 runs, mean 3.19s, max 27.0s**
wall clock for the full land+project including the network fetch. The 27s run was `apple_music` with
141 records changed — the largest `records_changed` ever recorded. Against a 120s timeout that is
**4.4× headroom at the observed worst case**, and **zero runs have ever ended `degraded`**.

Production: `core.users = 0`, and the `ingest` and `content` schemas **do not exist there at all** —
prod's ledger tops out at `20260726101212`; the ingest schema is `20260727130000`. The subsystem is
dev-only today.

**The decisive number.** The lane runs one worker. Chaining does not create parallelism — it splits
one 27s job into a ~20s job plus a ~7s job *on the same single worker*. Lane throughput is
unchanged. The prescribed fix buys nothing until `maxProcesses` rises, which `horizon.php:367`
forbids pending an infra spend.

**Not measured, stated as such:** whether a slow-job alert is actually armed in the Nightwatch
dashboard for the `ingest` queue (dashboard-side, not in the repo), and projection time in isolation
— the 27s figure is land+project+fetch combined, because nothing instruments `projectStream()`
separately. Note also that `ingest:anomalies` pages only on unresolved **critical** rows
(`routes/console.php:497-501`), and `RunExecutor.php:188` files projection anomalies at
`severity => 'warning'` — so today they do **not** page.

---

## The two open questions

### A. What replaces the degraded-outcome signal?

| Option | Cost | What breaks |
|---|---|---|
| Chained job re-`UPDATE`s `ingest.runs.outcome` | Needs a `worse()` merge in SQL — the row is already finalised | Never reaches `sources.health`: `release()` has already run and consumed the outcome (`RunSourceJob.php:77`). Half a fix. |
| Terminal "finalise run" job | Three jobs per source | A third place to lose the signal; still needs the merge above. Worst option. |
| Keep the run open (`finished_at` NULL until projection reports) | One column write moves | An open run becomes indistinguishable from a crashed one. `scoreDue()` reads `sources.last_run_at`, not runs, so it survives — but observability gets worse, not better. |
| Reuse the existing anomalies row as the projection signal | ~zero code: the `kind => 'projection'` row is **already written** (`RunExecutor.php:182-191`) | Only needs `severity` raised to `critical` so `ingest:anomalies` pages. Loses `runs.outcome` and `sources.health = 'degraded'` unless the chained job writes them itself. **Cheapest, and the only one reusing a shipped alarm.** |

### B. What holds the source claim across a chained job?

| Option | Cost | Failure mode if the chained job never runs |
|---|---|---|
| Release in the chained job's `finally` | Small; mirrors `RunSourceJob.php:89-99`, and `failed()`'s `whereNotNull('in_flight_since')` guard (`:119-126`) is a reusable template | Claim held until `ingest:stranded` clears it — up to **3h** (2h threshold + hourly sweep), with an anomaly filed. Already instrumented; the honest option. |
| Lease with TTL renewed by the chained job | New column + renewal path | Directly contradicts `SourceScheduler`'s "ONE mechanism, not a second lock layered on top" docblock (`:9-16`). Reintroduces the bug class that docblock exists to prevent. |
| `Bus::chain` with release only at the end | Same as option 1 in practice | Identical: a dropped chain link holds the claim for the same 3h. |
| Postgres advisory lock spanning both jobs | — | **Dead on arrival.** Session-scoped locks cannot span two jobs on two worker processes, and Supavisor pooling makes them unusable regardless. |

---

## Recommendation

**Defer — and reject the `Bus::chain` prescription outright, not merely for now.**

The finding's premise is throughput, and the throughput gain is **zero** at the current lane
configuration: one worker means chaining reorders work within a process rather than freeing
capacity. Measured worst case is 27s against a 120s timeout, mean 3.19s, on a subsystem that has
never produced a degraded run and does not exist in production. Against that, the change costs a
lifecycle redesign that must reconstruct two writes from a returned code path, and must accept a 3h
stuck-source window on a lost chain link — trading a bounded, alerting failure (job timeout) for an
unbounded, quieter one (held claim).

**Two things worth doing regardless — neither requires this finding. ✅ BOTH IMPLEMENTED 2026-07-31
on `audit-fix/cache3-2026-07-31`:**

1. ✅ Raise the projection anomaly in `RunExecutor` from `warning` to `critical`, so a total
   projection failure actually pages via the already-scheduled `ingest:anomalies`. It was writing to
   a table nobody is woken by: `IngestAnomaliesCommand` filters `->where('severity', 'critical')`.
   `RunExecutorProjectionTest` counted the anomaly row but never asserted its severity — which is
   exactly how this stayed invisible; that assertion now exists.
2. ✅ Make `writeIdentityKeys()`'s delete-then-insert atomic. It is the one genuinely unguarded
   delete-then-insert, and a real hazard for `ingest:project --rebuild` running alongside a live run
   **today** with no chaining involved.

*(Correction to this brief's own earlier wording: the DELETE and INSERT in `writeIdentityKeys()` have
only in-memory array building between them — not "a projector call and an upsert", which sit before
the method is entered. The unguarded window is real but narrower than first described.)*

**⚠️ And a correction that changed the fix.** This brief said "wrap `writeIdentityKeys()` in a
transaction". That would have been **insufficient**, and in the wrong direction: `upsertSourceItem()`
commits the `content.source_items` row *before* `writeIdentityKeys()` is entered, so a reader can see
a committed, live, **keyless** source item through a second window — and that one is the *damaging*
one. A keyless first-sight item resolves as an unrelated singleton, so `createItem()` mints a
spurious `content.items` row and anchors the coord to it; the next pass merges it away, but the user
sees a duplicate meanwhile, and if they curate the loser `mergeInto()`'s curation check keeps it
permanently. The shipped fix therefore spans **both** calls in one per-record transaction. Pinned by
a granularity test that fails if anyone re-narrows it.

**Still open after this pass:** `IngestProjectCommand::dropDerivedRows()` drops every identity key for
a stream up front, unwrapped — a far wider window that `ingest:project --rebuild` still carries.
Raised 2026-07-31 and deliberately kept out of that commit; see follow-up 5 in `CONSOLIDATED.md`.

**Reopen `#CACHE-3` when any one of these fires:**

- `supervisor-ingest`'s `maxProcesses` rises above 1 (i.e. after the box resize `horizon.php:356-367`
  names) — chaining only pays once the lane can run work in parallel;
- any `RunSourceJob` timeout appears in Nightwatch, or `ingest.runs` shows a run with `finished_at`
  NULL and `started_at` older than 120s;
- `max(records_changed)` on a single run exceeds **~400** (observed peak is 141 at 27s; linear
  extrapolation puts the 120s wall near 600, so 400 is a two-thirds warning line).

If a trigger fires, the remedy to design first is **bounding projection** — the finding's own title
says "no chunking for large streams", and `projection_write_chunk` / `projection_source_chunk`
(`config/partna.php:966,972`) already exist as the levers — **not** decoupling it from landing.
Chunking preserves both invariants; chaining destroys both to buy parallelism the lane cannot
currently use.
