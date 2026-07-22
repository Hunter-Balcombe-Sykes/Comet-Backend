# Platform write-locking — PWL-9 only (auto-sync seeders, L)

> **▶ To run this:** paste everything from `=== PROMPT START ===` to the end as the opening message
> of a fresh Claude Code session on **Opus**. Read it end to end first. **There is one open decision
> left inside** (how the per-seed lock nests vs the write() choke-point) — the plan must resolve it
> and PAUSE for Josh's sign-off before implementing, because PWL-9 is **L** with heavy blast radius.

The single deferred blocker from `audits/concurrency/2026-07-21-platform-write-locking/CONSOLIDATED.md`.
Everything else (PWL-5,7,8,10,14,15,16) is in `PROMPT-execute-rest.md`; the non-blocker half is
already MERGED to `development`. PWL-9 was split out because it is the largest single review surface
and touches the most live job paths at once.

**Ordering (recommended, not a hard block):** run this AFTER `PROMPT-execute-rest.md` lands, so the
PWL-5 (Fresha) and PWL-7 (Instagram) controller/seeder locks exist to close the racing pairs. PWL-9
adds the *seeder side* of pairs whose *controller side* is closed by the rest. If run first, some
pairs stay half-open until the rest lands — acceptable pre-beta, but note it.

---

```
=== PROMPT START ===

Execute PWL-9 only from audits/concurrency/2026-07-21-platform-write-locking/CONSOLIDATED.md — the
auto-sync seeders that write site.platform_connections rows unlocked (L, live job paths). Follow
scripts/audit/fix-flow.md. PWL-9 is a BLOCKER unit: PLAN FIRST (Opus), PRESENT the plan to Josh, and
WAIT for sign-off before implementing. Do not touch any other PWL unit here.

## First: fresh branch + baseline
- New branch off current development: `git fetch origin && git checkout -b
  audit-fix/platform-write-locking-autosync-2026-07-22 origin/development`.
- New worktree under `backend-wt/` (NOT `.claude/worktrees/`, which poisons the Composer classmap);
  it needs its own `composer install` + `.env`. Base off `origin/development`.
- `php artisan test tests/Feature/Platforms` green before starting.
- VERIFY EVERY PREMISE against current code — line numbers below have drifted, and see ⚠ FRESHNESS.

## ⚠ FRESHNESS — this exact area changed after the sweep
- **DISC-7 (already merged) reworked `InstagramAutoSync.php`** (gates scraped-bio sibling connections
  on claim consent). RE-MAP `InstagramAutoSync` / `GoogleBusinessAutoSync::seedInstagram` /
  `BuildsAutoSyncFindings` from CURRENT development — the CONSOLIDATED line numbers predate DISC-7.
- **The preaccount-scraping-rate-limit branch is MERGED** (per-vendor throttles, `dd609078`). There
  is NO open worktree to collide with — the old overlap concern is resolved. But these seeders run
  inside `GeneratePreAccountSiteJob`, which the rate-limit work also touched; re-read it fresh.

## What PWL-9 is
`BuildsAutoSyncFindings::write()` (`app/Services/Platforms/Concerns/BuildsAutoSyncFindings.php:67`,
`updateOrCreate ~69`) is the seed CHOKE-POINT, called UNLOCKED from `GoogleBusinessAutoSync::
seedReservation / seedOrdering / seedSocials / seedInstagram / dispatchInstagram` and the
`InstagramAutoSync` mirror. Only `seedBooking()` takes the separate `booking-xor` lock
(`bookingXorLockKey()` :133, key `"platforms:booking-xor:lock:{userId}"`, block() at :173).
`GoogleBusinessAutoSync::seedInstagram()` ALSO writes a row DIRECTLY (~615), NOT via write() — a
second write shape you must handle. These all run inside `GeneratePreAccountSiteJob` /
`InstagramConnectJob` / `GoogleBusinessEnrichJob`.

## Design decision (Q3 answered = per-platform lock on each NON-booking seed write; booking-xor
## stays). The ONE thing the plan must still resolve and get signed off:
Where does the per-platform lock go — (a) inside `write()` at the choke-point, guarded so
`seedBooking()` (already under `booking-xor`) does NOT double-lock/deadlock (e.g. write() takes
`platformConnectionLock($platform,$uid)` but seedBooking calls a lower-level unlocked write, or
passes a "already-locked" flag); or (b) at each non-booking seed CALL SITE around write(), plus the
direct `seedInstagram` write. (a) is DRY but must prove no nested-lock hazard with booking-xor; (b)
is more call sites but keeps seedBooking untouched. **Recommendation: (a) — lock the write()
choke-point on the target row's `platformConnectionLock($platform, $userId)`, and route seedBooking's
write so it is NOT wrapped a second time (it already holds booking-xor).** Plan it, present it,
confirm with Josh, THEN implement.

## Hard constraints (this subsystem will bite you)
- **THE #1 RULE: NEVER hold a lock across vendor I/O or an inline dispatch.** Under `QUEUE_CONNECTION=
  sync` (probe with config(), not env()) the enclosing job runs INLINE and a scrape can run 100s+ vs
  a 10s lock TTL. The fetch/scrape already happened UPSTREAM of the seed — the lock wraps ONLY the
  re-read + write of THAT seed. Do not wrap a seed lock around another dispatched scrape.
- **Observer no-self-deadlock:** the observer fan-out (`IntegrationConnectionObserver::saved` →
  IdentitySync / IntegrationConnectionCacheRefresher / ContentSelectionService / DeleteMirroredMediaJob)
  takes NO platform-connection lock as of the base commit — so a seed lock can't self-deadlock via
  the observer. RE-CONFIRM on your branch (this area changes hourly); `saveQuietly()` bypasses it.
- **Lock-timeout behaviour:** on a contended seed, **log-and-skip that single seed** — do NOT abort
  the whole auto-build over one contended row, and do NOT rely on release()/retry (sync driver, no-op).
- **Two lock schemes coexist** on `BuildsAutoSyncFindings` after this: per-platform on the general
  seeds, booking-xor on booking. That is intended — different keys, no ordering hazard between them.
- No Laravel migrations. No inline abort_unless(...,403). Never type `public bool $afterCommit` on a job.

## Fix-flow: plan (Opus) → PRESENT + sign-off → implement (Sonnet) → INDEPENDENT review (separate
## Sonnet) → tick + commit. Each write path needs a lost-update test that FAILS against pre-fix code
## (pre-acquire the target row's one key, assert the seed genuinely contends ~5s and the concurrent
## writer's change survives; assert on returned DATA; verify it fails pre-fix by hand — cp backup,
## revert, run, restore; NEVER `git stash`, the stack is shared across worktrees). Cover BOTH write
## shapes (write() choke-point AND the direct seedInstagram write). Commit code + ticked CONSOLIDATED
## together: `fix(audit): platform write-locking — PWL-9 auto-sync seeder locks`.

## When done
1. `php artisan test` (whole suite) — must be green.
2. `scripts/audit/archive-done.sh audits/concurrency/2026-07-21-platform-write-locking` — with PWL-9
   ticked AND the rest already done, this closes the audit (auto-archives). If the rest hasn't landed
   yet, it stays and reports why — expected; don't force it.
3. Report: what landed, test status, branch name. Josh reviews + merges; never push without his say-so.

## Non-negotiables
- fix-flow models: Plan = Opus, Implement = Sonnet, Review = SEPARATE Sonnet. Never skip independent review.
- PWL-9 is L + live job path → plan first, PAUSE for Josh's sign-off before implementing.
- NEVER hold a lock across vendor I/O or an inline dispatch. Log-and-skip a contended seed.
- Every fix ships a lost-update test that fails against pre-fix code. Assert on DATA. Never `git stash`.
- Re-verify the observer no-self-deadlock premise, and the booking-xor non-double-lock, before implementing.

=== PROMPT END ===
```
