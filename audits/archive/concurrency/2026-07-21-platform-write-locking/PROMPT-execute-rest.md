# Platform write-locking — REST blocker units (everything except PWL-9)

> **▶ To run this:** paste everything from `=== PROMPT START ===` to the end as the opening
> message of a fresh Claude Code session on **Opus**. Read it end to end first. There are **no open
> questions** — the design decisions (Q3–Q6) are answered inline. Josh has signed off this scope.

Splits the old `PROMPT-execute-blockers.md` in two. This file = **PWL-5, 7, 8, 10, 14, 15, 16**.
The deferred **PWL-9** (auto-sync seeders, L) has its own prompt: `PROMPT-execute-pwl9.md`.

Continues `audits/concurrency/2026-07-21-platform-write-locking/CONSOLIDATED.md`. The **non-blocker
half is DONE and MERGED to `development`** (PWL-1,2,3,4,6,11,12,13 + PWL-D1,D2 — controller-side +
service-level locks, each with independent review + a lost-update test that fails pre-fix). Full
suite was green (4769 passed) at merge.

---

```
=== PROMPT START ===

Continue executing audit audits/concurrency/2026-07-21-platform-write-locking/CONSOLIDATED.md — the
blocker units PWL-5, PWL-7, PWL-8, PWL-10, PWL-14, PWL-15, and the PWL-16 record-only. DO the
deferred PWL-9 in its own session (PROMPT-execute-pwl9.md) — skip it here, leave its box unticked.
Follow scripts/audit/fix-flow.md. Josh has ALREADY signed off this scope, so do NOT re-pause for
scope sign-off — but NEVER skip the independent review step.

## First: orient + re-establish baseline (the tree moves hourly)
- Continue on branch `audit-fix/platform-write-locking-2026-07-21` in worktree
  `backend-wt/platform-write-locking` (already has vendor + .env). Do NOT create a new branch, do
  NOT redo finished units. `git rev-parse --abbrev-ref HEAD` must read exactly that branch.
- At the time this prompt was written the branch == `origin/development` tip (the non-blocker work
  was fast-forward-merged in). `git fetch origin` and, if `origin/development` has advanced,
  `git rebase origin/development` first, then re-run `php artisan test tests/Feature/Platforms`
  green before starting. A concurrent gate-a / phpstan / fresh-db session shares this repo and
  pushes to development hourly — expect the remote to move under you and rebase, don't merge-commit.
- VERIFY EVERY PREMISE against current code first. Line numbers in CONSOLIDATED have drifted.

## The established pattern (reuse it — do NOT reinvent)
A lost update on a `site.platform_connections` row is prevented ONLY when both racing writers build
the SAME key: `CacheKeyGenerator::platformConnectionLock($platform, $userId)` = suffix-free
`"platforms:{platform}:lock:{userId}"`. Controllers use `ManagesIntegrationConnection::
withConnectionLock($user, $cb)` (10s TTL, block(5), 423 on timeout). Services/jobs use the raw
primitive `Cache::lock($key, 10)->block(5, $cb)` (precedents: `EventsCatalog::withPlatformLock`,
`ConnectFetchJob`, `GoogleBusinessEnrichJob::persist`, `ScheduledRefresh`).

**THE #1 RULE this branch enforced (already caught 2 defects in review): NEVER hold the lock across
vendor I/O or an inline job dispatch.** Under `QUEUE_CONNECTION=sync` (probe with config(), not
env()) a dispatched job runs INLINE; a scrape can run 100s+ while the lock TTL is 10s → it expires
mid-op and the race reopens. Correct shape: do the fetch/scrape/dispatch OUTSIDE the lock; the lock
wraps ONLY the authoritative re-read of the row + the write. (Mirror `GoogleBusinessController::
applySync` and `GenericPlatformController::highlights`.)

**Observer no-self-deadlock:** the observer fan-out (`IntegrationConnectionObserver::saved` →
IdentitySync / IntegrationConnectionCacheRefresher / ContentSelectionService / DeleteMirroredMediaJob)
takes NO platform-connection lock as of this branch — so wrapping a write in a lock cannot
self-deadlock via the observer. RE-CONFIRM before relying on it (this subsystem changes hourly), and
remember `saveQuietly()` bypasses the observer entirely.

## Per unit — fix-flow: plan (Opus) → implement (Sonnet) → INDEPENDENT review (a SEPARATE Sonnet that
## did not write the code) → tick + commit. Each fix needs a lost-update test that FAILS against
## pre-fix code (pre-acquire the row's one key, assert the writer genuinely contends ~5s of real
## wall time and the FIRST writer's change survives — never assert merely that "a lock exists";
## assert on returned DATA; verify each fails pre-fix by hand: cp backup → revert → run → restore;
## NEVER `git stash` — the stack is shared across worktrees). Commit code + ticked CONSOLIDATED
## together: `fix(audit): platform write-locking — <PWL-id> <desc>`.

### PWL-5 — Fresha connect / saveSelection (forget() handled by PWL-14)
`FreshaController::connect()` (writes ~95/117) and `saveSelection()` (write ~189) are unlocked; they
race `setServiceVisibility()` (locks) + `ScheduledRefresh` (locks). Wrap connect/saveSelection's
read→mutate→write in `withConnectionLock($user, …)`; keep any fetch/scrape OUTSIDE the lock. HOLD
`forget()` — its cross-platform (fresha/square) clear belongs to the booking-XOR decision, done in
PWL-14 below. Do PWL-5 BEFORE or alongside PWL-14 so the Fresha delete has exactly one owner.

### PWL-14 + PWL-15 — Tier-3 wrong-key XOR redesign (design unit; Q4 answered) — do together
The bug: `BookingController::detect()` (~76) holds the `booking` per-platform lock while
`clearBooking()` (~163) deletes **fresha/square** rows (different keys → not actually excluded);
`forget()` (~109) clears them with NO lock. `ReservationsController` mirrors this for
**opentable/resdiary/nowbookit** (`detect()` ~76 holds `reservations`; `clearReservations()` ~172;
`forget()` ~123 unlocked).
**DECISION (Q4):** guard the cross-platform single-slot clear with a **cross-platform XOR lock**.
- Booking: reuse the EXISTING `booking-xor` lock — `BuildsAutoSyncFindings::bookingXorLockKey()`
  (`app/Services/Platforms/Concerns/BuildsAutoSyncFindings.php:133`, key
  `"platforms:booking-xor:lock:{userId}"`, used by `seedBooking()`). That helper is `private`; its
  own comment says "Follow-up: promote to `CacheKeyGenerator::bookingXorLock()`." **PROMOTE it to
  `CacheKeyGenerator::bookingXorLock($userId)`** (keep the exact key string so the existing
  `seedBooking()` lock and the controller lock share it) and call it from `BookingController::
  detect()` AND `forget()` around the whole clear+write.
- Reservations: add a mirror `CacheKeyGenerator::reservationsXorLock($userId)` →
  `"platforms:reservations-xor:lock:{userId}"`, used in `ReservationsController::detect()` and
  `forget()` around the clear+write.
This makes the single-slot clear atomic against a concurrent sibling connect, and **resolves PWL-5's
deferred Fresha forget()** (a Fresha connect racing a booking detect now genuinely excludes).
Test: two writers on the single-slot family (e.g. a Fresha connect racing a Booking detect) exclude
— assert the surviving row is coherent, not half-cleared. Keep the network fetch outside the lock.

### PWL-7 — Instagram end-to-end (controller + live job/seeder) — locks NOWHERE today
⚠ FRESHNESS: `InstagramAutoSync` was reworked by DISC-7 (already merged) — re-map the Instagram area
from CURRENT development; CONSOLIDATED line numbers predate it. A controller-only fix is INERT
(neither side locks) — this unit is only "done" when BOTH controller AND seeder/job lock the same
`platformConnectionLock('instagram', userId)` key.
- Controller: `InstagramController::connect()` writes directly via `IntegrationConnection::
  updateOrCreate` (~74, NOT through writeConnection), `forget()` (~148), `applySync()` (saveQuietly
  ~212). Wrap each read→mutate→write in `withConnectionLock`. VERIFY connect() does not scrape
  inline — the Apify scrape belongs to the async job; if any fetch is inline, keep it OUTSIDE the lock.
- Live job/seeder (BLOCKER): `InstagramConnectionSeeder::seed()` (`connection->update` ~175) runs
  INSIDE `InstagramConnectJob::handle()` AFTER the scrape. Wrap ONLY the seeder's re-read + write in
  `platformConnectionLock('instagram', userId)` — NOT the scrape. `InstagramConnectJob::markFailed()`
  (saveQuietly ~149) writes a terminal state; lock it too (short write).
- **Lock-timeout behaviour (Q5):** on a lock timeout inside `InstagramConnectJob`, write a
  **terminal/failed state** (mirror `ConnectFetchJob`) — do NOT rely on release()/retry (sync driver,
  release() is a no-op).

### PWL-8 — EnrichLinkCardJob::handle() (live job path)
`EnrichLinkCardJob::handle()` (`app/Jobs/Platforms/EnrichLinkCardJob.php:48`) does a bare
`$row->update([...])` (~72) unlocked; races the now-locked custom / online-ordering / booking /
reservations connect writes. The scrape (`LinkCardScraper`) runs BEFORE the lock. Wrap ONLY the
re-read + enrichment write in `platformConnectionLock($row->platform, $row->user_id)`.
- **Lock-timeout behaviour (Q5):** **log-and-skip** — this is a best-effort card upgrade; on timeout
  the card keeps its pending / last-good state. Do NOT fail the job or burn retries.

### PWL-10 — CustomLinkSeeder::seed() (job path)
`CustomLinkSeeder` (`app/Services/Platforms/CustomLinkSeeder.php:53` `updateOrCreate`) writes the same
custom-link row `CustomLinksController::addLink()` (locks) writes; the seeder is unlocked. Wrap the
seed write in `platformConnectionLock('custom', userId)` — CONFIRM the platform slug against the row
CustomLinksController actually writes (must be byte-identical or the lock doesn't exclude). Small —
only touches `CustomLinkSeeder`.

### PWL-16 — Tier-4 record-only (Q6)
Record the deliberately-not-locking set so a future sweep doesn't re-flag it: link-only socials
(facebook/tiktok/x/linkedin/threads/reddit/skool/square), `DisplaySettingsController::update()`,
`ConnectFetchJob::markTerminal/markOk` + `PlatformRefresher` bookkeeping, dead `OnDemandRefresh`, and
the non-connection Menu/workplaces/design_kits writers. **Where (Q6):** a short
`## Deliberately not locked` comment block in `app/Http/Controllers/Api/Platforms/Concerns/
ManagesIntegrationConnection.php` (discoverable at the locking helper's own site) PLUS the rationale
already in CONSOLIDATED. Tick the box.

## When this run is worked
1. `php artisan test` (whole suite, not just Platforms) — must be green.
2. `scripts/audit/archive-done.sh audits/concurrency/2026-07-21-platform-write-locking` — it
   auto-archives ONLY if every box is ticked; PWL-9 is deliberately deferred here, so it will stay
   and report why. That's expected — do NOT force it. Never ask "should I archive?" — just run it.
3. Report: units done, PWL-9 deferred (its own prompt), test status, branch name. Josh reviews +
   merges; never push to development/production without his say-so.

## Non-negotiables
- fix-flow models: Plan = Opus, Implement = Sonnet, Review = SEPARATE Sonnet. Never skip independent review.
- NEVER hold a lock across vendor I/O or an inline dispatch (the #1 rule above).
- Every fix ships a lost-update test that fails against pre-fix code. Assert on DATA. Never `git stash`.
- No Laravel migrations. No inline abort_unless(...,403) (use policies + authorizeForUser; 404 not 403).
- Never type `public bool $afterCommit` on a queueable job (trait conflict → silent fatal).
- Re-verify the observer no-self-deadlock premise before adding any job-side lock.

=== PROMPT END ===
```
