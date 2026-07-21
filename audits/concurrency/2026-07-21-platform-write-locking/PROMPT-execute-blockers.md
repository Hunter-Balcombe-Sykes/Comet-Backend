# Platform write-locking — BLOCKER units execution prompt

> **⛔ SUPERSEDED (2026-07-22).** The open questions below are ANSWERED and this combined prompt was
> split in two — run those instead:
> - `PROMPT-execute-rest.md` — PWL-5, 7, 8, 10, 14, 15, 16 (continues the existing branch).
> - `PROMPT-execute-pwl9.md` — the deferred PWL-9 auto-sync seeders (own follow-up branch).
>
> Kept for the reasoning/tiering rationale only. Decisions taken: Q1 = split (rest now, PWL-9 own
> branch) · Q2 = resolved (rate-limit branch already merged) · Q3 = per-platform lock per non-booking
> seed write, booking-xor untouched · Q4 = reuse booking-xor + mirror reservations-xor · Q5 =
> EnrichLinkCardJob log-and-skip, Instagram job terminal-write · Q6 = comment in ManagesIntegrationConnection.

> **▶ (historical) To run this:** first answer the OPEN QUESTIONS below (edit your answers inline).
> Then paste everything from `=== PROMPT START ===` to the end as the opening message of a
> fresh Claude Code session on **Opus**. Read it end to end first.

This continues `audits/concurrency/2026-07-21-platform-write-locking/CONSOLIDATED.md`. The
**non-blocker** half is DONE and committed on branch `audit-fix/platform-write-locking-2026-07-21`
(PWL-1,2,3,4,6,11,12,13 + discovered PWL-D1,D2 — controller-side + service-level locks, all with
independent review + a lost-update test that fails pre-fix). `tests/Feature/Platforms` = 929 green.

This prompt covers the **blocker units** — the ones the fix-flow blocker gate held for Josh's
sign-off because they touch **live job paths**, are **L**, or are a **standalone design decision**.

---

## OPEN QUESTIONS — answer these BEFORE running (they change the scope/approach)

**Q1 — Scope of this branch.** Which blocker units run here vs a later follow-up?
- **Recommendation:** do **PWL-5 (Fresha), PWL-7 (Instagram), PWL-8 (EnrichLinkCardJob), PWL-10
  (CustomLinkSeeder)** on this branch now; **defer PWL-9 (auto-sync, L)** and the **Tier-3 XOR
  redesign (PWL-14/15)** to dedicated follow-ups (Q3/Q4 explain why).
- Your answer: __________

**Q2 — PWL-9 / PWL-10 vs the preaccount-scraping-rate-limit branch (OVERLAP).** PWL-9's auto-sync
seeders and PWL-10's `CustomLinkSeeder` run inside `GeneratePreAccountSiteJob`, which the
`feature/preaccount-scraping-rate-limit` worktree currently has open (along with
`PlatformRegistryServiceProvider` + `config/partna.php`). Editing the same job callees on two
branches risks a merge collision.
- **Recommendation:** land the rate-limit branch first, then do PWL-9/PWL-10 rebased on top. Or, if
  that branch is stalled, do PWL-10 here (it only touches `CustomLinkSeeder`, not the job) and hold
  PWL-9 until the overlap clears.
- Your answer: __________

**Q3 — PWL-9 auto-sync locks interaction with the booking-XOR lock.** `BuildsAutoSyncFindings`
already takes a dedicated `booking-xor` lock in `seedBooking()`. Adding a per-platform
`platformConnectionLock` to the OTHER seeds (`seedReservation`/`seedOrdering`/`seedSocials`/
`seedInstagram`) means two lock schemes coexist on the same trait. Do you want (a) per-platform
locks on each non-booking seed write (nested cleanly, different keys), or (b) a broader rethink of
how the seeder locks? PWL-9 is **L** and its racing partners are only closed once the controller
fixes (done) AND this land.
- **Recommendation:** (a) — a per-platform lock on each seed write, scoped to the write only (fetch
  already happened upstream), leaving the booking-XOR lock as-is. Treat as its own follow-up branch.
- Your answer: __________

**Q4 — PWL-14/15 Tier-3 XOR design.** Booking/Reservations `detect()` currently hold the WRONG
lock (the `booking`/`reservations` per-platform key) while deleting sibling-provider rows
(fresha/square, opentable/resdiary/nowbookit); `forget()` clears them unlocked. The fix is a
**cross-platform XOR lock**. A `booking-xor` lock already exists
(`BuildsAutoSyncFindings::bookingXorLockKey`). Confirm the approach:
- **Recommendation:** reuse the existing `booking-xor` lock in `BookingController::detect/forget`,
  and add a mirror `reservations-xor` lock for `ReservationsController::detect/forget`. This makes
  the single-slot clear atomic against a concurrent sibling connect. Standalone design unit — do
  PWL-14 + PWL-15 together, separate from the job-path locks.
- Your answer: __________

**Q5 — Job lock-timeout behaviour (PWL-7, PWL-8).** Deployed env runs `QUEUE_CONNECTION=sync`, so a
job runs inline and `$job->release()` is a no-op. On a lock timeout inside a job, what should happen?
- **Recommendation:** match the nearest precedent per job — `ConnectFetchJob` writes a **terminal
  state** on lock-timeout (because release is a no-op); `ScheduledRefresh` **logs-and-skips** (cron,
  don't burn `consecutive_failures`). For PWL-8's `EnrichLinkCardJob` (best-effort card upgrade),
  log-and-skip is fine (the card stays at its pending/last-good state). For PWL-7's Instagram job,
  write a terminal/failed state like `ConnectFetchJob`. Do NOT rely on release/retry.
- Your answer: __________

**Q6 — PWL-16 Tier-4 record.** Where to record the deliberately-not-locking decisions (link-only
socials, DisplaySettings, ConnectFetchJob bookkeeping, dead `OnDemandRefresh`) so a future sweep
doesn't re-flag them?
- **Recommendation:** a short `## Deliberately not locked` section appended to
  `docs/` (or a comment block in `ManagesIntegrationConnection`), plus leaving PWL-16 ticked in
  CONSOLIDATED with the rationale already written. Lowest-effort, do it inline when closing the audit.
- Your answer: __________

---

```
=== PROMPT START ===

Continue executing audit audits/concurrency/2026-07-21-platform-write-locking/CONSOLIDATED.md,
the BLOCKER units only. The non-blocker half (PWL-1,2,3,4,6,11,12,13 + PWL-D1,D2) is already
committed on branch audit-fix/platform-write-locking-2026-07-21. Check out that branch and continue
on it — do NOT create a new branch, do NOT redo finished units. Follow scripts/audit/fix-flow.md.

Scope for this run is set by the OPEN QUESTIONS answered above — read them; only do the units the
answers say to do here. If an answer defers a unit, skip it (leave its box unticked).

## First: orient + re-establish baseline (the tree moves hourly)
- `git fetch && git checkout audit-fix/platform-write-locking-2026-07-21 && git log --oneline -8`
  — confirm the 6 fix(audit) + docs(audit) commits are present. `git rev-parse --abbrev-ref HEAD`
  must read exactly that branch (a concurrent gate-a/preaccount session shares this repo — see the
  overlap note in Q2).
- Rebase decision: `origin/development` has advanced past the 42bc6141 baseline. If Josh says so,
  `git rebase origin/development` first and re-run `php artisan test tests/Feature/Platforms` green
  before starting. Otherwise reconcile at merge time.
- Work in THIS worktree (`backend-wt/platform-write-locking`), which already has vendor + .env.

## The established pattern (reuse it — do NOT reinvent)
Lost update on a `site.platform_connections` row is prevented ONLY when both racing writers build
the SAME key: `CacheKeyGenerator::platformConnectionLock($platform, $userId)` = suffix-free
`"platforms:{platform}:lock:{userId}"`. Controllers use `ManagesIntegrationConnection::
withConnectionLock($user,$cb)` (10s TTL, block(5), 423). Services/jobs use the raw primitive
`Cache::lock($key,10)->block(5,$cb)` (see `EventsCatalog::withPlatformLock`, `ConnectFetchJob`,
`GoogleBusinessEnrichJob::persist`, `ScheduledRefresh` for precedents).

**THE #1 RULE this branch enforced (2 defects caught in review already): NEVER hold the lock across
vendor I/O or an inline job dispatch.** Under sync queue a dispatched job runs INLINE; a scrape can
run 100s+ while the lock's TTL is 10s → it expires mid-op and the race reopens. Correct shape: do the
fetch/scrape/dispatch OUTSIDE the lock; the lock wraps ONLY the authoritative re-read of the row +
the write. (Mirror `GoogleBusinessController::applySync` and `GenericPlatformController::highlights`.)

Confirmed safe: the observer fan-out (`IntegrationConnectionObserver::saved` → IdentitySync /
IntegrationConnectionCacheRefresher / ContentSelectionService / DeleteMirroredMediaJob) takes NO
platform-connection lock as of this branch — so adding a lock cannot self-deadlock via the observer.
Re-confirm before relying on it (this subsystem changes hourly).

## Per unit — fix-flow: plan (Opus) → implement (Sonnet) → INDEPENDENT review (separate Sonnet) → tick + commit
VERIFY EVERY PREMISE against current code first (line numbers below have drifted). Each fix needs a
lost-update test that FAILS against pre-fix code (pre-acquire the row's one key, assert the writer
genuinely contends ~5s and the first writer's change survives — never assert merely that "a lock
exists"; assert on returned DATA; never `git stash` — the stack is shared across worktrees). Commit
code + ticked CONSOLIDATED together: `fix(audit): platform write-locking — <PWL-id> <desc>`.

### PWL-5 — Fresha connect/saveSelection (forget deferred to PWL-14)
`FreshaController::connect()` (writes ~95/117), `saveSelection()` (write ~189) are unlocked; race
`setServiceVisibility()` (locks) + `ScheduledRefresh` (locks). Wrap connect/saveSelection in
`withConnectionLock` (fetch/scrape stays outside). HOLD `forget()` — its cross-platform clear belongs
to the booking-XOR decision (PWL-14); do it there, or note it deferred.

### PWL-7 — Instagram end-to-end (controller + live job/seeder) — locks NOWHERE today
- Controller: `InstagramController::connect()` writes directly via `IntegrationConnection::
  updateOrCreate` (~74, not through writeConnection), `forget()` (~148), `applySync()` (saveQuietly
  ~212). Wrap each read→mutate→write in `withConnectionLock` (the IG Apify scrape happens in the
  async job, not the controller — but VERIFY connect() doesn't scrape inline; if it does, keep the
  scrape outside the lock).
- Live job/seeder (BLOCKER — needs Q-answers): `InstagramConnectionSeeder::seed()` (`connection->
  update` ~175) runs INSIDE `InstagramConnectJob::handle()` AFTER the scrape. Wrap ONLY the seeder's
  re-read + write in `platformConnectionLock('instagram', userId)` — NOT the scrape. `InstagramConnectJob
  ::markFailed()` (saveQuietly ~149) writes a terminal state; lock it too (short write). On lock
  timeout use the behaviour from Q5. A controller-only fix is INERT (neither side locks today) — this
  unit is only "done" when BOTH controller and seeder/job lock the same key.

### PWL-8 — EnrichLinkCardJob::handle() (live job path)
`EnrichLinkCardJob::handle()` does a bare `$row->update([...])` (~72) unlocked; races the now-locked
custom/online-ordering/booking/reservations connect writes. Wrap the update in
`platformConnectionLock($row->platform, $row->user_id)` — re-read the row inside the lock, apply the
enrichment, write. The scrape (`LinkCardScraper`) runs BEFORE the lock. Lock-timeout behaviour per Q5
(recommend log-and-skip — the card keeps its last-good state).

### PWL-9 — auto-sync seeders (L) — DEFER unless Q1/Q2/Q3 say otherwise
`BuildsAutoSyncFindings::write()` (`updateOrCreate` ~69) called unlocked from `GoogleBusinessAutoSync`
seeds + the `InstagramAutoSync` mirror; `GoogleBusinessAutoSync::seedInstagram()` writes a row
directly (~615). These run inside `GeneratePreAccountSiteJob`/`InstagramConnectJob`/
`GoogleBusinessEnrichJob`. Per Q3(a): add a per-platform lock scoped to EACH seed WRITE (fetch is
upstream), leaving the existing `booking-xor` lock in `seedBooking()` untouched. HEAVY blast radius +
overlaps the preaccount branch (Q2) — treat as its own follow-up branch unless told otherwise.

### PWL-10 — CustomLinkSeeder::seed() (job path)
`CustomLinkSeeder` (`updateOrCreate` ~53) writes the same custom-link row `CustomLinksController::
addLink()` locks; unlocked. Wrap the seed write in `platformConnectionLock('custom', userId)` (confirm
the slug). Small — only touches `CustomLinkSeeder`, so lower overlap risk than PWL-9.

### PWL-14 + PWL-15 — Tier-3 wrong-key XOR redesign (standalone design unit) — per Q4
`BookingController::detect()` holds the `booking` key while `clearBooking()` deletes fresha/square
rows; `forget()` clears them unlocked. `ReservationsController` mirrors this for opentable/resdiary/
nowbookit. Per Q4: guard the cross-platform single-slot clear with a `booking-xor` lock (reuse
`BuildsAutoSyncFindings::bookingXorLockKey`) in Booking detect/forget, and add a mirror
`reservations-xor` lock for Reservations detect/forget. This also resolves PWL-5's deferred Fresha
`forget()`. Do the two together. Test: two writers on the single-slot family (e.g. a Fresha connect
racing a booking detect) genuinely exclude.

### PWL-16 — Tier-4 record-only (per Q6)
Record the deliberately-not-locking set (link-only socials, DisplaySettings, ConnectFetchJob
bookkeeping, dead OnDemandRefresh, non-connection Menu/workplaces/design_kits writers) per Q6, tick
the box, done.

## When the blocker set for this run is worked
1. `php artisan test` (whole suite, not just Platforms) — must be green.
2. `scripts/audit/archive-done.sh audits/concurrency/2026-07-21-platform-write-locking` — auto-archives
   IF every box is ticked; otherwise it stays (deferred units) and reports why. Never ask "should I
   archive?" — just run it.
3. Report: units done, units deferred (with reason), test status, branch name. Josh reviews + merges;
   never push to development/production without his say-so.

## Non-negotiables
- fix-flow models: Plan=Opus, Implement=Sonnet, Review=SEPARATE Sonnet. Never skip independent review.
- NEVER hold a lock across vendor I/O or an inline dispatch (the #1 rule above).
- Every fix ships a lost-update test that fails against pre-fix code. Assert on DATA. Never `git stash`.
- No Laravel migrations. No inline abort_unless(...,403). Never type `public bool $afterCommit` on a job.
- Re-verify the observer no-self-deadlock premise before adding any job-side lock.

=== PROMPT END ===
```
