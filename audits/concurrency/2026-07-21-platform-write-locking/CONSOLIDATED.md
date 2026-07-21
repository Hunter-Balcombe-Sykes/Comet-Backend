# Platform-connection write-path locking — verified findings

**Category:** concurrency (lost-update / missing-lock) · **Date:** 2026-07-21
**Baseline:** branch `audit-fix/platform-write-locking-2026-07-21` off `origin/development` @ `42bc6141`
**Source prompt:** `docs/superpowers/plans/2026-07-21-platform-write-path-locking-PROMPT.md`

## Why this is not an `audit.sh` run

The bug class is the **absence** of a lock, which has no grep signature — a writer that should
lock but doesn't is byte-identical to one that correctly needn't. The discovery instrument was
reasoning about which two writers can hit the same `site.platform_connections` row at the same
instant, then verifying each against current code. Every finding **names its racing partner** — a
writer with no plausible concurrent partner is Tier 4, not a bug.

## The one load-bearing fact

`CacheKeyGenerator::platformConnectionLock($platform, $userId)` → `"platforms:{platform}:lock:{userId}"`
(suffix-free since `dde6aadd`). A lost update is prevented **only when both racing writers build
this same key.** Consequence for triage:

- Where the **job side already locks** (`ConnectFetchJob`, `ScheduledRefresh`,
  `GoogleBusinessEnrichJob::persist`), a **controller-side** lock *completes the pair* and is safe
  to land on its own — these mirror the existing `withConnectionLock()` pattern and **proceed**.
- Where **neither side locks** (Instagram everywhere; the auto-sync seeders; `EnrichLinkCardJob`),
  a controller-only fix is useless — you must also lock the **live job path**, which trips the
  blocker gate → **plan + sign-off**.

Confirmed safe to add controller-side locks: the observer's synchronous fan-out
(`IntegrationConnectionObserver::saved` → `IdentitySync`, `IntegrationConnectionCacheRefresher`,
`ContentSelectionService`, `DeleteMirroredMediaJob`) takes **no** platform-connection lock @ `42bc6141`,
so wrapping a controller write cannot self-deadlock.

## Execution policy

- **Plan = Opus · Implement = Sonnet · Review = SEPARATE Sonnet** (never the implementer). Per the
  source prompt. S/XS controller units may combine plan+implement.
- **Blocker gate (pause for Josh's sign-off before implementing):** any unit that adds a lock to a
  **live job path** (`ScheduledRefresh` / `ConnectFetchJob` / `InstagramConnectJob` /
  `GoogleBusinessEnrichJob` / `EnrichLinkCardJob` / the auto-sync seeders), any **L/XL** unit, and
  every **Standalone** item. Controller-side Tier-1/Tier-2 locks that mirror the existing pattern
  proceed without asking.
- **Sync-driver caveat:** deployed env runs `QUEUE_CONNECTION=sync` (probe with `config()`), so a
  dispatched job runs INLINE and `$job->release()` is a no-op. Job-side lock-timeout handling must
  write a terminal state or `block()` and accept the wait — never rely on release/retry. Match
  `ScheduledRefresh`'s log-and-skip (cron) vs `ConnectFetchJob`'s terminal-write (connect) per case.
- No Laravel migrations (none needed). No inline `abort_unless(...,403)`. Never type
  `public bool $afterCommit` on a queueable job.

## Triage framework

- **Tier 1 — REAL:** a background job races a user action on the same row; both active at once.
- **Tier 2 — LOWER:** two user actions race (double-submit / two tabs). Real but rare, low-stakes.
- **Tier 3 — STRUCTURAL ODDITY:** wrong-key locks — the lock guards a different platform than the
  row being written. A distinct bug from the missing-lock pattern.
- **Tier 4 — NOT WORTH LOCKING:** no plausible concurrent writer. Record so a future sweep doesn't re-flag.

## Progress

- Tier 1: 6/10 · Tier 2: 3/3 · Tier 3: 0/2 · Tier 4: 0/1 (record-only) — **16 findings, 9 done** (PWL-1,2,3,4,5,6,11,12,13)
- ALL NON-BLOCKER work COMPLETE (Session A controller locks + PWL-13 + both discovered). tests/Feature/Platforms 929 green.
- REMAINING = blocker units only (need sign-off): PWL-5 (Fresha), PWL-7 (Instagram), PWL-8 (EnrichLinkCardJob), PWL-9 (auto-sync, L), PWL-10 (CustomLinkSeeder), PWL-14/15 (Tier-3 XOR) + PWL-16 (Tier-4 record-only). Prompt: PROMPT-execute-blockers.md
- Discovered during execution: 2/2 FIXED (PWL-D1, PWL-D2, below)
- Verified against `42bc6141`; line numbers current as of this baseline.

---

## Tier 1 — REAL (job ⇄ user, fix these)

### PWL-1 — GoogleBusinessController connect/applySync/forget vs GoogleBusinessEnrichJob (job locks, controller doesn't) — CONTROLLER-side, non-blocker, S–M
- [x] Fix — wrapped connect/applySync/forget; applySync's applyFinding() kept OUTSIDE the lock (re-read→write inside). Independent review PASS.
**Plain English:** When you connect or edit your Google Business card, a background job is often
still fetching and enriching that same card. The job carefully locks the row before writing; your
click doesn't. So the job's slower write can silently erase what you just saved, or vice-versa.
**Technical:** `GoogleBusinessEnrichJob::persist()` (`app/Jobs/Platforms/GoogleBusinessEnrichJob.php:400`)
locks on `platformConnectionLock('google_business', userId)`. Its own comment (`:232`) documents that
`GoogleBusinessController::connect()` (`:78`, write `:120`+`saveQuietly :124`), `applySync()` (`:224`,
`saveQuietly :249`) and `forget()` (`:161`) take no such lock. **Fix:** wrap each of the three
controller methods' read→mutate→write in `withConnectionLock($user, …)`. Racing partner: the enrich job.

### PWL-2 — GenericPlatformController connect/connectDeferred/removeAccount/forget vs ConnectFetchJob + ScheduledRefresh — CONTROLLER-side, non-blocker, M
- [x] Fix — wrapped connect/connectDeferred/removeAccount/forget, mirroring highlights(); job dispatch stays outside the lock. Independent review PASS.
**Plain English:** Every "generic" platform card (YouTube, Vimeo, Spotify, SoundCloud, Bandcamp,
Twitch, Pinterest, Strava, and the reservation providers) can be refreshed by a 12-hour cron and by
an async connect-fetch job — both of which lock. Connecting, deferring, removing an account, or
disconnecting from the dashboard doesn't lock, so a refresh landing at the same moment overwrites it.
**Technical:** `GenericPlatformController` — only `highlights()` (`:278`, lock `:306`) locks.
`connect()` (`:58`, writes `:108`/`:117`), the deferred/pending path (`:156`/`:161`), `removeAccount()`
(`:362`→`forgetConnection :370`), `forget()` (`:376`) are unlocked. Racing partners:
`ConnectFetchJob` (`:160` locks) and `ScheduledRefresh` (`:44` locks). **Fix:** wrap each write path.
Multi-account: the lock is platform-wide, so it serialises all of a user's accounts on that platform —
correct and intended. Preserve the `pending`/merge semantics inside the closure.

### PWL-3 — AppleController connectFor/removeAccountFor/forgetFor vs highlightsFor + ScheduledRefresh — CONTROLLER-side, non-blocker, S–M
- [x] Fix — wrapped connectFor/removeAccountFor/forgetFor (each covers music+podcast via $activePlatform); forget() takes both sub-platform locks per-iteration (partial-clear is idempotent-on-retry). Independent review PASS.
**Plain English:** Apple Music / Podcasts connect, account-remove, and disconnect don't lock, but
saving highlights and the refresh cron do. Editing while a refresh runs can lose one side's change.
**Technical:** only the highlights path locks (`AppleController.php:305`, write `:324`). `connectFor`
(write `:240` `writeAccountConnection`), `removeMusicAccount`/`removePodcastAccount` (`:273`
`forgetConnection`), `forget`/`forgetMusic`/`forgetPodcast` (`:144`/`:333` `forgetAllConnections`) are
unlocked. Racing partners: `highlightsFor()` + `ScheduledRefresh`. **Fix:** wrap the connect/remove/forget paths.

### PWL-4 — EventsPlatformController addAccount/addStandaloneEvent/removeAccount/forget vs removeEvent + ScheduledRefresh — CONTROLLER-side, non-blocker, S–M
- [x] Fix — wrapped addAccount/addStandaloneEvent/removeAccount/forget (shared base → covers Eventbrite+Humanitix); scrapes stay outside the lock. Independent review PASS.
**Plain English:** Adding an events account or a standalone event, removing an account, or
disconnecting doesn't lock; removing a single event and the refresh cron do. Concurrent edits collide.
**Technical:** `EventsPlatformController` — `removeEvent()` (`:136`, lock `:140`) locks; `addAccount()`
(`:78`), `addStandaloneEvent()` (write `:128`), `removeAccount()` (`:94`→`:100`), `forget()` (`:183`)
don't. Racing partners: `removeEvent()` + `ScheduledRefresh`. **Fix:** wrap the four. (See also PWL-13,
the duplicate `EventsCatalog` write path feeding the same rows.)

### PWL-5 — FreshaController connect/saveSelection/forget vs setServiceVisibility + ScheduledRefresh — CONTROLLER-side, non-blocker, S–M
- [x] Fix — wrapped connect()/saveSelection() read→mutate→write in withConnectionLock('fresha'); scrapes (fetchMenu/fetchLocation/extractTeam/fetchEmployeeServices) stay OUTSIDE the lock. forget()'s cross-platform clear deferred to PWL-14 (booking-XOR). Independent review PASS; lost-update test fails pre-fix (200→423).
**Plain English:** Connecting Fresha, saving your service selection, or disconnecting doesn't lock;
toggling a service's visibility and the refresh cron do. A refresh mid-edit can lose your selection.
**Technical:** `FreshaController` — `setServiceVisibility()` (`:238`, lock `:244`) locks; `connect()`
(writes `:95`/`:117`), `saveSelection()` (write `:189`), `forget()` (`:314`→`:317`, plus
`saveQuietly :326`+`delete :327`) don't. Racing partners: `setServiceVisibility()` + `ScheduledRefresh`.
**Fix:** wrap the three. **Note:** `forget()` participates in the booking-XOR family — coordinate with
PWL-14 so the two fixes don't fight over which lock guards a Fresha delete.

### PWL-6 — ShopController::forget() vs the other (locked) Shop write paths — CONTROLLER-side, non-blocker, XS
- [x] Fix — wrapped forget()'s child-row delete + forgetConnection in withConnectionLock (key 'shop'). Independent review PASS.
**Plain English:** Every Shop edit locks except "disconnect", which deletes brands and products
unlocked — so a disconnect racing a save can leave half-deleted state.
**Technical:** `ShopController` locks `detect`/`update`/`delete`/product ops (`:147`/`:215`/`:297`/`:365`/`:487`/`:542`),
but `forget()` (`:435`) deletes `ShopProduct`/`ShopBrand` (`:446`–`:447`) with no `withConnectionLock`.
**Fix:** wrap `forget()`'s delete block in the lock.

### PWL-7 — Instagram locks NOWHERE: controller + InstagramConnectJob/Seeder — MIXED (controller non-blocker; job/seeder BLOCKER), M–L
- [ ] Fix
**Plain English:** Instagram is the worst case — no code path locks the IG connection row at all.
Two quick connects, or a connect landing while the previous connect's background scrape is still
writing, can clobber each other with no protection anywhere.
**Technical:** `InstagramController::connect()` writes directly via `IntegrationConnection::updateOrCreate`
(`:74`, not through `writeConnection`), `forget()` (`:146`→`:148`), `applySync()` (`saveQuietly :212`) —
all unlocked. `InstagramConnectJob::handle()` (`:98`) → `InstagramConnectionSeeder::seed()` (`:59`,
`connection->update :175`) and `markFailed()` (`saveQuietly :149`) — all unlocked. Because **neither**
side locks, a controller-only fix is inert. **Fix:** wrap the controller writes AND add
`platformConnectionLock('instagram', userId)` to the seeder's write + the job's terminal write.
**Blocker:** the seeder/job half is a live job path → plan + sign-off before implementing.

### PWL-8 — EnrichLinkCardJob::handle() vs custom/online-ordering/booking/reservations connect-time locked writes — BLOCKER (job path), S–M
- [ ] Fix
**Plain English:** After you add a custom link / online-ordering / booking / reservation card, a
background job upgrades its display fields. That job doesn't lock, so it can overwrite an edit or a
disconnect you make in the seconds while it runs.
**Technical:** `EnrichLinkCardJob::handle()` (`app/Jobs/Platforms/EnrichLinkCardJob.php:48`) does a
bare `$row->update([...])` (`:72`), no lock. Its dispatchers (`BookingController::detect`,
`OnlineOrderingController::addEntry`, `CustomLinksController::addLink`) lock their own writes. **Fix:**
wrap the job's update in `platformConnectionLock(row->platform, row->user_id)`; on lock timeout write a
terminal/skip state (sync-driver: no `release()`). **Blocker:** live job path → sign-off.

### PWL-9 — Auto-sync seeders (BuildsAutoSyncFindings::write + GoogleBusinessAutoSync::seedInstagram) write connection rows unlocked — BLOCKER (job path), L
- [ ] Fix
**Plain English:** When we auto-build or auto-sync a site (pre-account builds, GB/IG enrichment), we
seed platform cards straight into the connection table without locking. These often land on platforms
whose own controllers are also unlocked, so both sides of the race are wide open.
**Technical:** `BuildsAutoSyncFindings::write()` (`app/Services/Platforms/Concerns/BuildsAutoSyncFindings.php:67`,
`updateOrCreate :69`) is called unlocked from `GoogleBusinessAutoSync::seedReservation/seedOrdering/
seedSocials/seedInstagram/dispatchInstagram` and the `InstagramAutoSync` mirror; only `seedBooking()`
takes the separate booking-XOR lock (`:173`). `GoogleBusinessAutoSync::seedInstagram()` also writes a
row directly (`:615`). These run inside `GeneratePreAccountSiteJob` / `InstagramConnectJob` /
`GoogleBusinessEnrichJob`. **Fix:** lock each seed write on the target row's `platformConnectionLock`.
Depends on the controller fixes (PWL-1..5,7) to close the pairs. **Blocker:** live job paths, L → sign-off.

### PWL-10 — CustomLinkSeeder::seed() vs CustomLinksController::addLink (controller locks, seeder doesn't) — BLOCKER (job path), S
- [ ] Fix
**Plain English:** Job-triggered custom-link creation writes the same card row your dashboard
"add link" writes — but the job path doesn't lock while the dashboard path does.
**Technical:** `CustomLinkSeeder` (`app/Services/Platforms/CustomLinkSeeder.php:53` `updateOrCreate`),
unlocked; `CustomLinksController::addLink()` (`:60`) locks. **Fix:** wrap the seeder write in
`platformConnectionLock('custom', userId)` (confirm the platform slug). **Blocker:** job path → sign-off.

---

## Tier 2 — LOWER (user ⇄ user, fix only if cheap alongside a Tier-1 on the same file)

### PWL-11 — CustomLinksController removeLink/forget vs addLink — non-blocker, XS
- [x] Fix — wrapped removeLink/forget in withConnectionLock. Independent review PASS.
**Plain English:** Deleting a custom link in two tabs at once (or delete-while-add) is unguarded.
**Technical:** `addLink()` (`:60`) locks; `removeLink()` (`:88`→`:94`) and `forget()` (`:100`→`:102`)
don't. **Fix:** wrap both — trivial, do alongside any custom-link work.

### PWL-12 — OnlineOrderingController removeEntry/forget vs addEntry — non-blocker, XS
- [x] Fix — wrapped removeEntry/forget; MenuFetchJob::dispatch moved OUTSIDE the lock (gated on actual delete) after review caught it holding the lock across a ~240s inline scrape. Independent review PASS (2 rounds).
**Plain English:** Same shape for online-ordering entries.
**Technical:** `addEntry()` (`:55`, lock `:80`) locks; `removeEntry()` (`:125`→`:145`) and `forget()`
(`:153`→`:156`) don't. **Fix:** wrap both.

### PWL-13 — EventsCatalog::storeAccount duplicate unlocked write to the same events row — non-blocker, S
- [x] Fix — added a service-level withPlatformLock helper (raw Cache::lock on platformConnectionLock($provider,uid), 423 via the fail() array contract); wrapped storeAccount + storeStandalone (read+write inside). storeCustom's fetch stays upstream, its write routes through the now-locked storeStandalone. Independent review PASS. (Note: removeCustom delete stays unlocked — no lost-update window, events-custom never refreshed.)
**Plain English:** Two different code paths write the same Eventbrite/Humanitix account row; one of
them (the catalog service, reached via the Eventbrite/Humanitix connect controllers) doesn't lock.
**Technical:** `EventsCatalog::storeAccount()` (`app/Services/Platforms/EventsCatalog.php:219`) derives
the same `acct-<hash>` id (`:221`) and writes via `updateOrCreate` (`:315`) unlocked — a second entry
point onto the rows `EventsPlatformController` also writes. **Fix:** route the write through a single
locked path, or wrap `storeAccount`'s write. Bundle with PWL-4.

---

## Tier 3 — STRUCTURAL ODDITY (wrong-key) — Standalone, BLOCKER

### PWL-14 — BookingController holds the `booking` lock while deleting fresha/square rows; forget() unlocked — Standalone, M
- [ ] Fix
**Plain English:** Booking is a single-slot card — connecting one provider clears the others. The
code locks the "booking" slot but then deletes the Fresha and Square rows, which live under different
locks, so a simultaneous Fresha connect isn't actually excluded. And "disconnect" clears them with no
lock at all.
**Technical:** `BookingController::detect()` (`:76`) wraps in `withConnectionLock` keyed on `booking`,
then `clearBooking()` (`:163`) deletes `fresha`/`square` rows (`:165`–`:168`) + the custom booking row.
`forget()` (`:109`) calls `clearBooking()` with no lock. This is a **wrong-key** bug, not a missing-key
one. **Fix (design decision):** guard the cross-platform clear with the shared booking-XOR lock
(`BuildsAutoSyncFindings::bookingXorLockKey`, already used by `seedBooking()`), in both `detect()` and
`forget()`. Coordinate with PWL-5 (Fresha) and PWL-9 (auto-sync seedBooking share the same lock).

### PWL-15 — ReservationsController holds the `reservations` lock while deleting opentable/resdiary/nowbookit rows; forget() unlocked — Standalone, M
- [ ] Fix
**Plain English:** Same shape as Booking, for the reservations single-slot family.
**Technical:** `ReservationsController::detect()` (`:76`) holds the `reservations` lock; `clearReservations()`
deletes opentable/resdiary/nowbookit rows (`:172`) + `forgetConnection` (`:175`); `forget()` (`:123`)
clears unlocked. **Fix:** a reservations-family XOR lock mirroring the booking decision in PWL-14. Do the two together.

---

## Tier 4 — NOT WORTH LOCKING (record-only, do NOT fix)

### PWL-16 — Deliberately-not-locking register — doc-only, XS
- [ ] Record in an ADR/comment so a future sweep doesn't re-flag
**Items & reasons:**
- **Link-only socials** (facebook / tiktok / x / linkedin / threads / reddit / skool / square): never
  locked, no refreshable sibling, no second writer — nothing to race.
- **`DisplaySettingsController::update()`** (`:70`): saves only the `display_settings` column via
  Eloquent dirty-tracking (`:140`/`:161`); sharpest risk is two concurrent display-settings PATCHes,
  not a payload race — low, leave.
- **`ConnectFetchJob::markTerminal/markOk`** (`:242`/`:256`) and `PlatformRefresher` bookkeeping: touch
  only status columns via a single logical writer — deliberately narrow.
- **`OnDemandRefresh`**: dead code (no constructor references anywhere) — flag, don't fix.
- **Menu* / workplaces / design_kits writers** (`MenuContentController`, `GoogleBusinessAutoSync::seedWorkplace`,
  website-scan appliers): write NON-connection tables — out of scope here, worth their own row-locking audit.

---

## Discovered during execution

### PWL-D1 — AppleController::highlightsFor() holds its lock across a network fetch — Tier-3-adjacent, pre-existing, S
- [x] Fix — fetch moved outside the lock; closure re-reads the fresh account row + writes (mirrors GenericPlatformController::highlights()). Test proves fetch runs under a held lock (fetchCalls===1, pre-fix 0). Independent review PASS.
**Found:** during PWL-3 review (2026-07-21). Pre-existing (commit `2e4018e4`), NOT introduced by this
audit. Same class as the PWL-1 `applySync` defect: `highlightsFor()` (`AppleController.php` ~327-349)
calls `($cfg['fetch'])(...)` INSIDE its `withConnectionLock` closure, so a slow/network fetch is held
under the 10s-TTL lock — the lock can expire mid-fetch and a concurrent writer slips in.
**Fix direction:** move the fetch outside the lock; re-read + write inside (mirror the `applySync`
and `GenericPlatformController::highlights()` re-read-under-lock shape). Bundle with any Apple follow-up.

### PWL-D2 — OnlineOrderingController::addEntry() dispatches MenuFetchJob inside its lock — Tier-1-adjacent, pre-existing, XS
- [x] Fix — EnrichLinkCardJob + MenuFetchJob dispatch moved outside the lock, gated on 202 (mirrors PWL-12). Proof is structural + assertPushed-on-202 (a blocked block(5) never enters the closure, so the 423-path test can't distinguish — reviewer confirmed). Independent review PASS.
**Found:** during PWL-12 review (2026-07-21). Pre-existing, NOT introduced here. `addEntry()`
(`OnlineOrderingController.php` ~80-106) dispatches `MenuFetchJob` (a ~240s inline scrape under the
sync queue, no platform lock of its own) from INSIDE its `withConnectionLock` closure — the same
lock-across-inline-scrape defect PWL-12 fixed for removeEntry/forget. **Fix direction:** move the
dispatch after the lock returns (gated on the write succeeding), identical to the PWL-12 fix.

## Suggested bundled sessions & order

**PROCEED (non-blocker, controller-side, mirror the existing pattern):**
- **Session A — controller locks that complete an already-locked pair:** PWL-1, PWL-2, PWL-3, PWL-4, PWL-6.
  (Run sequentially; each is an independent file.) PWL-13 folds in with PWL-4; PWL-11 + PWL-12 fold in as XS.
- **Session B — Fresha (PWL-5):** proceed on the connect/saveSelection locks, but hold `forget()`'s
  cross-platform clear until PWL-14's XOR-lock decision (they touch the same delete).

**BLOCKER (plan → present → sign-off before implementing):**
- **Standalone S1 — Instagram end-to-end (PWL-7):** controller + seeder + job; only meaningful done whole.
- **Standalone S2 — job-path locks (PWL-8, PWL-10):** `EnrichLinkCardJob`, `CustomLinkSeeder`.
- **Standalone S3 — auto-sync seeders (PWL-9, L):** biggest blast radius; depends on Session A landing.
- **Standalone S4 — Tier-3 XOR redesign (PWL-14 + PWL-15):** shared design decision, do together.

## What "done" looks like

Every Tier-1 racing pair is locked on the shared per-platform key (or has a written safe-unlocked
reason). Tier-2/3 are decided (fix or defer, recorded). Tier-4 is recorded as deliberate. Each fix
ships a concurrency test that **fails against the pre-fix code** (real `Cache::lock()->block()`,
interleave two writers on one row, assert the first writer's change survives — never assert merely
that "a lock exists"; never `git stash`, the stack is shared across worktrees). Full suite green. No migration.
