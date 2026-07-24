# Unit — close the symmetric Square/Fresha booking-XOR race

**Status:** written 2026-07-24, **awaiting sign-off**. No code written.
**Origin:** surfaced by the CA-W7 planning pass during the async-connect programme
(`docs/superpowers/specs/2026-07-23-platform-connect-async-design.md`). Josh's call on
2026-07-24 was **"separate unit after this run"** — deliberately excluded from
`audit-fix/connect-async-impl-2026-07-24`.
**Effort:** S/M · **Gate:** yes — booking-adjacent, capability-adjacent, and it adds a
status code an endpoint has never returned.

---

## 1. The invariant

A user may have **at most one** active booking provider. Today that is Fresha or Square,
enforced by a check-then-write in each controller.

## 2. What is actually true today

| Writer | Takes `bookingXorLock`? | Where |
|---|---|---|
| `BookingController` (2 paths) | **yes** | `BookingController.php:83,125` |
| `FreshaController::forget()` | **yes** | `FreshaController.php:496` |
| `BuildsAutoSyncFindings` | **yes** | `BuildsAutoSyncFindings.php:138,178` |
| `FreshaConnectFetch` (added by CA-W7) | **yes** | inside the deferred storewide job |
| **`SquareController::connect()`** | **NO LOCK AT ALL** | `SquareController.php:30-41` |
| `FreshaController::connect()` | per-platform lock only — **not** the XOR key | — |

So the XOR is enforced by two unsynchronised `exists()` checks. `SquareController::connect()`
calls `hasConflictingConnection($user, 'fresha')` at `:41` and then writes, with nothing
serialising it against a concurrent Fresha connect.

**Square is the only booking writer outside the lock discipline every other writer follows.**

## 3. The race

1. Request A (Fresha connect) checks for a Square row → none.
2. Request B (Square connect) checks for a Fresha row → none.
3. Both write.
4. The user now has **two active booking providers** — precisely the state the check exists
   to prevent.

The window is small (two near-simultaneous requests from one user), but the invariant is
binary: once violated, both providers render and there is no reconciliation path.

## 4. What this is NOT

**This race is pre-existing and is NOT caused by the async-connect work.** Two points that
were verified during that programme and should not be re-litigated:

- The design doc claimed deferral "stretches that gap to queue latency". That is **false**
  for the shape implemented: the row-creating write never moved into the job, only the
  content fill did. Deferral **narrows** the window, because the pending row — which is what
  makes `hasConflictingConnection('fresha')` return true — is written *before* the 202
  instead of after a ~20 s scrape.
- CA-W7 already wraps its XOR re-assert **and** `FreshaServiceProjector::sync()` in
  `withCrossPlatformLock(bookingXorLock(...))`, for a different and real reason: the
  projector resurrects rows tombstoned as `deleted_origin='sync'`, so a `forget()` landing
  mid-flight would have its service teardown silently undone.

What remains is only the **symmetric controller-level check-then-write**, unchanged by that
work.

## 5. Proposed fix

Bring the two connect paths under the same cross-platform lock every other booking writer
already uses:

1. `SquareController::connect()` — wrap the conflict check **and** the write in
   `withCrossPlatformLock(CacheKeyGenerator::bookingXorLock((string) $user->id), ...)`.
2. `FreshaController::connect()` — take the **booking-XOR** key rather than (or in addition
   to) the per-platform key, so the two genuinely exclude one another. The per-platform
   `fresha` lock cannot exclude `SquareController::connect()`.

## 6. Blast radius — the reason this is gated

- **`SquareController::connect()` gains a `423`** (`"Another change is still saving — please
  retry in a moment."`) that it has **never returned**. That is a frontend-visible contract
  change and needs the dashboard to handle it, exactly as the other booking endpoints already do.
- **`FreshaConnectLockTest` pins the per-platform key.** Changing which lock
  `FreshaController::connect()` takes will make that test fail; it must be updated
  deliberately, not silently loosened.
- **The PWL-16 register becomes false.** `ManagesIntegrationConnection.php:309-317` names
  `square` among writers "deliberately left unlocked … nothing to race". If Square starts
  locking, that entry must be removed with a note, the same way `skool` was removed during
  CA-W4.
- Roughly **four test files** touched: `SquareConnectionTest`, `FreshaConnectLockTest`,
  `BookingXorLockTest`, `BookingXorControllerLockTest`.

## 7. Acceptance criteria

1. Two concurrent connects (one Fresha, one Square) for the same user cannot both succeed —
   proved by a test that interleaves them, not by inspection.
2. The loser receives the existing `409` (conflict) or `423` (lock contention); it never
   silently wins.
3. No change to the single-request happy path for either platform.
4. The PWL-16 register no longer claims `square` has nothing to race.
5. Existing booking-XOR tests stay green, with any pinned-key change made deliberately and
   explained in the commit.

## 8. Explicitly out of scope

- `SET LOCAL lock_timeout` for `FreshaServiceProjector`'s
  `pg_advisory_xact_lock(hashtext('services:{user_id}'))` — separately escalated, still open.
- Any change to the async-connect flag, the poll contract, or activation order.
