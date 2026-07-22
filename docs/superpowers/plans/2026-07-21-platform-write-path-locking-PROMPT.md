# Platform-connection write-path locking — audit + fix

> **▶ To run this:** paste this whole file as the opening prompt of a fresh session.
> Read it end to end before touching code. This is a VERIFY-then-RANK-then-FIX job, not a
> cold pipeline run — see "How to run this" below for why.

---

## Why this exists

Writers to a `site.platform_connections` row take a per-connection lock so two writers can't
clobber each other (a lost update: two writers both read the row, each writes back its whole
copy, the second silently erases the first). The lock only works if both writers acquire the
**same** cache-key string.

A 2026-07-21 investigation of the platform surface found two things:

1. **The suffix mismatch — ALREADY FIXED (`dde6aadd` on `development`), do not redo.** Some
   writers keyed per-account (`resource_id` suffix), others per-platform (no suffix), so for a
   multi-account platform they didn't mutually exclude. Fixed by adopting per-platform locking
   uniformly and **removing the `$suffix` parameter entirely** from
   `CacheKeyGenerator::platformConnectionLock()` and
   `ManagesIntegrationConnection::withConnectionLock()`. Per-platform is now the only key any
   writer can build. **Do not reintroduce a per-account suffix** — that is a settled decision.

2. **The larger, unaddressed gap this prompt is about: most write paths take NO lock at all.**
   ~7 of ~30 write paths lock. The rest — `connect` / `forget` / `removeAccount`, several async
   jobs, all auto-sync seeding — just write. Per-platform keying did nothing for these, because
   one side of the race never locks. Plus two structural oddities (wrong-key locks; a duplicate
   unlocked write path).

**Not urgent — pre-beta, no concurrent users** (concurrency races need concurrent writers, and
there are none yet). This is foundational-durability work to land before pilot, when two devices
or a background-job-plus-a-click genuinely overlap. Treat it with care, not haste.

## Freshness — checked 2026-07-21 against `origin/development` @ `ea9df2ab`

Re-verified after a large concurrent merge (Tobias's gate-a + scraper/website-scan work) to be
sure the premises still hold:

- **The per-platform lock fix (`dde6aadd`) is intact** — `CacheKeyGenerator::platformConnectionLock`
  is still suffix-free and none of its 5 files changed. Do not redo it.
- **The unlocked-writer gap is NOT fixed — it GREW.** The merge added new unlocked writers to
  platform-connection rows: `CustomLinkSeeder` (job-triggered custom-link creation), and the
  reworked `InstagramConnectionSeeder` / `InstagramAutoSync` / `GoogleBusinessAutoSync`
  (`BuildsAutoSyncFindings::write()` path) — verified each still takes NO lock. Re-map the
  Instagram / custom-link / auto-sync area from scratch; the line-level inventory below predates
  this and is materially stale there.
- **`B9 "pre-account lifecycle races"` is a DIFFERENT domain** — `pre_account_builds`, handle
  collisions, subdomain renames (`PreAccountBuildService`, `RenameSubdomainAction`). It does NOT
  cover platform-connection write locks. Don't assume it did.
- **The no-self-deadlock premise still holds** — `IdentitySync` and
  `IntegrationConnectionCacheRefresher` (which fire synchronously inside the locked `update()` via
  the observer) still take no platform-connection lock as of `ea9df2ab`. Re-confirm before you rely
  on it, since this subsystem is actively changing.
- **Adjacent surface, separate audit:** `InstagramIdentitySync` and the new website-scan appliers
  (`WorkplaceContentApplier`, `DesignKitAccentApplier`, gallery-slot fillers) write
  `workplaces` / `users` / `design_kits` with the same concurrent fill-if-empty pattern — NOT
  platform-connection rows. Out of scope here; worth its own row-locking audit on those tables.

## How to run this — and why NOT to just fire `audit.sh`

The house rule is "audit X → run `scripts/audit/audit.sh`, never hand-write findings." **This
class is the exception, and here's the honest reason:** the pipeline finds bugs by grepping for
*signatures* (`DB::transaction`, `Cache::`, `lockForUpdate`, `ShouldQueue`). This bug is the
**absence** of a lock, which has no signature — a writer that should lock but doesn't looks
byte-identical to one that correctly doesn't need to. The pipeline's own applicable-lens guard
concedes "a bug with no grep signature is invisible to it." A `--codebase` sweep would come back
deceptively clean.

So the discovery instrument here is **reasoning about which pairs of writers can run at the same
time for the same row**, not a scan. The map below (from the 2026-07-21 sweep) is your starting
inventory. Your job:

1. **Verify the map against current code** (as of `dde6aadd` or later — the tree has moved). It
   is a starting point, not ground truth; a path may have changed or already been locked. For
   each item confirm the writer still exists, still writes the row, and is still unlocked.
2. **Rank each by real reachability** using the tiering framework below. Not every "unlocked
   writer" is a real bug — some races are genuinely impossible in practice.
3. **Decide, per path, whether it should lock** — and if so, on what. Per-platform is the
   established convention.
4. **Fix the ones that warrant it** via `scripts/audit/fix-flow.md`: plan → implement →
   independent review by a SEPARATE instance → tests green → commit. Blocker gate applies
   (anything touching a live job path, or L/XL, pauses for Josh's sign-off before implementing).
5. **Cross-check with the pipeline, don't lead with it:** you MAY run
   `scripts/audit/audit.sh --bundle concurrency --scope app/Http/Controllers/Api/Platforms
   --scope app/Jobs/Platforms --scope app/Services/Platforms` as a second pass to catch anything
   the map missed (e.g. a genuine transaction-boundary or `Cache::` signature it CAN see). But do
   not treat a clean pipeline result as evidence these paths are safe — it cannot see lock-absence.

Produce your ranked, verified findings in the canonical audit finding format
(`scripts/audit/adjudicate-prompt.md`) so they can be worked with the normal fix-flow — one
folder under `audits/`, a `CONSOLIDATED.md`, checkboxes, Plain-English + Technical per finding.

## The triage framework — which races are real

The severity of an unlocked writer is entirely about **who else could be writing the same row at
the same instant.** Classify each:

- **TIER 1 — REAL (a background job races a user action).** The job read the row a while ago, is
  still fetching for *seconds*, then writes its stale copy over the user's just-made change. Both
  are genuinely active at once. These are the ones to fix. Examples the sweep flagged:
  `ScheduledRefresh` (12h cron, runs unconditionally) vs `connect`/`forget`/`removeAccount` on
  any refreshable platform; `GoogleBusinessEnrichJob` vs the GB controller's `connect`/`applySync`/
  `forget` (the code *already documents this gap in its own comments*); Instagram's async
  `InstagramConnectJob`/`InstagramConnectionSeeder::seed()` vs a second `connect()` — Instagram
  locks *nowhere*; `ConnectFetchJob` vs `connect`/`forget` (only live once `PARTNA_CONNECT_DEFERRED`
  lists a platform, but wire it correctly now).

- **TIER 2 — LOWER (two user actions race).** Needs the same user to fire two writes to the same
  row within the same moment — a double-submit, two open tabs. Real but rare and low-stakes. Most
  `connect` vs `removeAccount` vs `forget` pairs are here when no job is involved. Also the
  `EventsCatalog` vs `EventsPlatformController` duplicate write path (two different unlocked
  controllers write the same eventbrite/humanitix row). Fix only if cheap alongside a Tier-1 fix
  on the same file; otherwise document and defer.

- **TIER 3 — STRUCTURAL ODDITY (distinct bugs, not the missing-lock pattern).**
  `BookingController::detect()` holds the **`booking`** lock while `clearBooking()` deletes
  **fresha/square** rows; `ReservationsController::detect()` holds the **`reservations`** lock
  while `clearReservations()` deletes **opentable/resdiary/nowbookit** rows. The lock guards a
  different platform than the one being written — same root failure (writers don't exclude), but a
  wrong-key bug rather than a no-key one. Assess on its own terms.

- **TIER 4 — NOT WORTH LOCKING.** Link-only socials (facebook/tiktok/x/linkedin/threads/reddit/
  skool/square) — never locked, but no refreshable sibling and no second writer, so nothing to
  race. `ConnectFetchJob::markTerminal/markOk` and `PlatformRefresher` bookkeeping writes touch
  only status columns via a single logical writer — deliberately narrow, leave them.
  `DisplaySettingsController::update()` saves only the `display_settings` column via Eloquent
  dirty-tracking, so the sharpest risk is two concurrent display-settings PATCHes, not a payload
  race — low. `OnDemandRefresh` is dead code (nothing constructs it). Flag for completeness, do
  not fix.

**A missing-lock finding must name its racing partner.** "This writer doesn't lock" is not a bug
on its own — "this writer doesn't lock and can be overwritten by / can overwrite <that specific
concurrent writer>" is. If you can't name a plausible concurrent writer, it's Tier 4.

## Starting inventory (from the 2026-07-21 sweep — VERIFY, don't trust)

Line numbers will have drifted; treat as pointers, re-confirm each.

**Take no lock at all, with a Tier-1 racing partner:**
- `GoogleBusinessController::connect()` / `applySync()` / `forget()` — races
  `GoogleBusinessEnrichJob::persist()`'s locked write. Gap is self-documented in
  `GoogleBusinessEnrichJob`'s comments.
- `GenericPlatformController::connect()` / `connectDeferred()` / `removeAccount()` / `forget()` —
  every generic platform (youtube, vimeo, youtube-music, bandcamp, spotify, soundcloud, twitch,
  pinterest, strava, nowbookit, resdiary, opentable). Races `ConnectFetchJob` / `ScheduledRefresh`.
  Only `highlights()` locks.
- `AppleController::connectFor()` / `removeAccountFor()` / `forgetFor()` — races `highlightsFor()`
  and `ScheduledRefresh`.
- `EventsPlatformController::addAccount()` / `addStandaloneEvent()` / `removeAccount()` — races
  `removeEvent()` and `ScheduledRefresh`. Plus `EventsCatalog::storeAccount()` derives the same
  `acct-<hash>` id and writes the same row from a second unlocked entry point (Tier 2 duplicate).
- `FreshaController::connect()` / `saveSelection()` / `forget()` — races `setServiceVisibility()`
  and `ScheduledRefresh`.
- `InstagramController::connect()` / `forget()` / `applySync()`, `InstagramConnectJob::markFailed()`,
  `InstagramConnectionSeeder::seed()` — **no writer for this platform locks anywhere.** Two
  concurrent `connect()`s, or a `connect()` racing a still-running `seed()` from the prior connect.
- `ShopController::forget()` — races the other Shop methods' locked writes.
- `EnrichLinkCardJob::handle()` — dispatched by custom/online-ordering/booking/reservations to
  upgrade card display fields; no lock, races those controllers' connect-time locked writes.
- `BuildsAutoSyncFindings::write()` — called unlocked from `GoogleBusinessAutoSync`'s `seedReservation`/
  `seedOrdering`/`seedSocials`/`seedInstagram`/`dispatchInstagram` and the `InstagramAutoSync`
  mirror. Only `seedBooking()` takes a lock (the separate, intentional `booking-xor` lock). These
  land on platforms whose controllers are also mostly unlocked, so often BOTH sides are lock-free.

**Take no lock, Tier-2 partner only:**
- `CustomLinksController::removeLink()` / `forget()` vs `addLink()`'s locked write.
- `OnlineOrderingController::removeEntry()` / `forget()` vs `addEntry()`'s locked write.

**Tier 3 wrong-key:** `BookingController::detect()`/`clearBooking()`,
`ReservationsController::detect()`/`clearReservations()`.

## Hard constraints (this codebase will bite you)

- **Per-platform is the established convention; the `$suffix` param is gone.** Lock via
  `CacheKeyGenerator::platformConnectionLock($platform, $userId)` and
  `withConnectionLock($user, $callback)`. Do not add a suffix back.
- **The observer fires SYNCHRONOUSLY inside a locked `update()`.** `IntegrationConnectionObserver::
  saved()` runs during `$connection->update()` (these writes are outside a DB transaction), and it
  fans out to `IntegrationConnectionCacheRefresher`, `IdentitySync`, `ContentSelectionService`,
  `DeleteMirroredMediaJob`. As of the 2026-07-21 fix, none of those takes a platform-connection
  lock — so wrapping a controller write in `withConnectionLock` will NOT self-deadlock today. But
  **if you add a lock, re-verify the closure doesn't re-enter the same lock**, and remember
  `saveQuietly()` bypasses the observer entirely (that's why `GoogleBusinessEnrichJob`'s locked
  writes don't fire it).
- **`ScheduledRefresh` is a LIVE cron path.** Its `catch (LockTimeoutException)` logs-and-skips
  deliberately — throwing there would burn `consecutive_failures` on pure contention and could
  trip the circuit breaker. Do not change that. `ConnectFetchJob`'s lock-timeout handler writes a
  terminal state instead (because `release()` is a no-op under the sync driver — see below); match
  whichever precedent fits when you add locking to a job vs a user request.
- **The deployed env runs `QUEUE_CONNECTION=sync`** (probe with `config()`, not `env()`), so a
  dispatched job runs INLINE in the request. `$job->release()` is a no-op there — do not rely on
  release/retry to resolve a lock timeout in a job; write a terminal state or use `block()` and
  accept the wait.
- **No Laravel migrations** — none of this needs schema. A composer guard rejects them.
- **No inline `abort_unless(..., 403)`** — CI fails on it. Authorization via Policies +
  `$this->authorizeForUser()`. 404 (not 403) for a resource that isn't the caller's.
- Never type `public bool $afterCommit` on a queueable job (trait conflict → silent fatal).

## Tests (the class is subtle — most naive tests are vacuous)

- Tests run **SQLite in-memory**; `CACHE_STORE=array`. ArrayStore locks are **non-re-entrant
  in-process** (confirmed), so the lock-probe technique works: inside a mocked slow writer, try to
  acquire the row's lock key and assert you cannot — proving the writer holds it. See
  `tests/Feature/Platforms/PlatformConnectionLockConvergenceTest.php` and
  `HighlightsLockBoundaryTest.php` for the established pattern.
- The convergence/mutual-exclusion shape is the right proof: pre-acquire the row's one key, then
  assert the writer under test genuinely contends (real `Cache::lock()->block(...)`, ~seconds of
  wall time), and that WITHOUT the fix the row is silently clobbered. A test that passes against
  unfixed code proves nothing — verify each fails pre-fix by hand (`cp` backup, revert, run,
  restore; **never `git stash`** — the stack is shared across worktrees).
- **Assert on returned DATA, never that a query ran.** On SQLite an unknown quoted identifier is a
  string literal, so a query against a nonexistent column silently "succeeds."
- A lost-update test must actually interleave two writers around the same row and assert the first
  writer's change survives — not just that "a lock exists."

## Method

Branch `audit-fix/platform-write-locking-<date>` off `development`. Work in a worktree under
`backend-wt/` (NOT `.claude/worktrees/`, which poisons the Composer classmap); each worktree needs
its own `composer install` and `.env`. Base off `origin/development` and check for file overlap
with any concurrent session before landing (a gate-a session has been active on `development`).

Per finding, `scripts/audit/fix-flow.md`: **plan (Opus) → implement (Sonnet) → independent review
by a SEPARATE Sonnet that did not write the code → tick + commit.** Give reviewers the finding and
the diff; have them verify the test fails against unfixed code, that no pre-existing test was
weakened, and that the added lock cannot self-deadlock via the observer.

**Blocker gate:** adding a lock to `ScheduledRefresh` / `ConnectFetchJob` / `InstagramConnectJob` /
`GoogleBusinessEnrichJob` (live job paths), or any L/XL unit, produces the plan and PAUSES for
Josh's sign-off before implementing. Tier-1 controller-side locks that mirror an existing pattern
proceed.

## What "done" looks like

Every Tier-1 racing pair is either locked on the shared per-platform key or has a written reason it
is safe unlocked. Tier-2/3 items are documented with a decision (fix or defer). Tier-4 is recorded
as deliberately-not-locking so a future sweep doesn't re-flag it. A concurrency test exists for each
fix that fails against the pre-fix code. Full suite green (`php artisan test`). No migration.

Baseline: `development` was @ `dde6aadd` when this was drafted; re-checked @ `ea9df2ab` (see
Freshness). Re-establish the true baseline before starting — the branch has heavy active concurrent
work and moves hourly.
