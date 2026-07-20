# Unit 11 — Platform connect/highlights async-fetch pattern (LIFE-13..LIFE-24)

> **▶ To run this:** paste this whole file as the opening prompt of a fresh session.
> Deferred from the TRIAGE-2-P2 run on 2026-07-20 (Josh's decision). Everything below is
> **already verified against the code** — do not redo premise verification, and do not
> re-derive the design questions. Start from these facts.

---

## Source

- Audit file: `audits/sweeps/2026-07-11-full-work-sweep/TRIAGE-2-P2.md`
- The 12 findings are the only unticked boxes in that file (89/101 ticked as of merge `c9d9eb59`).
- Runbook: `scripts/audit/fix-flow.md`. Execution policy from the audit file:
  **Plan = Opus 4.8 · Implement = Sonnet 4.6 · Review = a SEPARATE Sonnet 4.6 instance.**
- Effort ~L. Under the blocker gate this needs a plan presented for sign-off before
  implementing — but Josh has already chosen to defer rather than split, so treat the
  *whole* unit as one sign-off gate, not twelve.

## The findings

All 12 are **VALID**, verified 2026-07-20. Every strategy is **live** — registered in
`app/Providers/PlatformRegistryServiceProvider.php` and routed through
`GenericPlatformController`. None is dead code. Do not re-check this.

| ID | Strategy | Where the sync vendor call happens |
|---|---|---|
| LIFE-13 | SpotifyConnect | `.../Strategies/Connect/SpotifyConnect.php:26` — `$this->oembed->resolve(...)` |
| LIFE-14 | BandcampConnect | `.../Connect/BandcampConnect.php:30,35` — `fetchProfile()` + `enrichPrices()` |
| LIFE-15 | PinterestConnect | `.../Connect/PinterestConnect.php:23,27` — `fetchProfile()` then `fetchPins()` |
| LIFE-16 | StravaConnect | `.../Connect/StravaConnect.php:23` — `fetchClub()` |
| LIFE-17 | TwitchConnect | `.../Connect/TwitchConnect.php:22` — `fetchChannel()` |
| LIFE-18 | VimeoConnect | `.../Connect/VimeoConnect.php:25-26` — `fetchProfile()` + `fetchVideos()` |
| LIFE-19 | YoutubeConnect | `.../Connect/YoutubeConnect.php:28` — `fetchRecentVideos()` |
| LIFE-20 | YoutubeMusicConnect | `.../Connect/YoutubeMusicConnect.php:22,27` — `channelIdFrom()` + `fetchUploadsFeed()` |
| LIFE-21 | BandcampHighlights | `.../Highlights/BandcampHighlights.php:30` — `fetchProfile()` in `recentItems()` |
| LIFE-22 | VimeoHighlights | `.../Highlights/VimeoHighlights.php:29` — `fetchVideos()` |
| LIFE-23 | YoutubeHighlights | `.../Highlights/YoutubeHighlights.php:30` — `fetchRecentVideos()` |
| LIFE-24 | YoutubeMusicHighlights | `.../Highlights/YoutubeMusicHighlights.php:30` — `fetchUploadsFeed()` |

## Why this is L, not M — there is no shared seam

`ConnectStrategy` (`.../Strategies/Contracts/ConnectStrategy.php:9-12`) is a **bare
one-method interface**. `HighlightsStrategy` is a bare 8-method interface. All 12
implementers declare them **directly** — there is no abstract base class. The only shared
code is the `RefreshesLatestTile` trait, used by 4 of the 12, and it is a pure formatting
helper unrelated to sync/async.

`ConnectResult` (`.../Contracts/ConnectResult.php`) is `final readonly`, constructed only
via `ok()`/`fail()`, with **no pending variant**.

So "lock one shared async contract, then fan out" has no free seam. Any shared contract
lands on the two bare interfaces themselves — touching all 12 files plus `ConnectResult`.
Budget for that; do not plan around a base class that does not exist.

## The two halves have genuinely different risk — plan them separately

### Connect half (LIFE-13..LIFE-20) — BREAKING API change, needs frontend coordination

`GenericPlatformController.php:91` and `:97` echo `$result->selection` — the
synchronously-fetched name/thumbnail/latest-item — **in the same HTTP response**, built
from the in-memory result, not re-read from the DB. Deferring the fetch changes the
`POST .../connect` response shape for all 8 platforms.

There **is** a proven in-house pattern to copy, so this is a done-before change, not a
novel one:

- `InstagramController::connect()` writes the row with `last_refresh_status => 'pending'`
  (line 87), dispatches `InstagramConnectJob` (line 93), returns **HTTP 202** with
  `{'status' => 'pending', ...}` (line 98). The job scrapes, then
  `InstagramConnectionSeeder::seed()` persists and flips status to `'ok'`/`'unavailable'`.
  Frontend polls `InstagramController::connectStatus()`.
- `GoogleBusinessEnrichJob` does the same shape for the enrichment half.
- The `'pending'` value required its own migration
  (`supabase/migrations/20260616000000_allow_pending_refresh_status.sql`) whose comment
  says it exists precisely because the async Instagram connect writes a pending
  placeholder before the scrape job runs. **A new status value on any status column needs
  its CHECK constraint widened — verify before writing one.**
- `GET /platforms/meta` (`IntegrationsMetaController`) **already** surfaces
  `last_refresh_status` / `has_refresh_error` generically for every connected platform on
  the shared `site.platform_connections` table. The polling substrate is not
  Instagram-specific — it is already generic.

**Blocked on:** matching changes in `partna-frontend`
(`github.com/hunterbalcombesykes/partna-frontend`) to add a per-platform polling/loading
state. That repo is **read-only reference** from a backend session — never clone, pull,
commit, or push it from here. Coordinate with Josh before shipping the backend half, or
the dashboard breaks for 8 platforms.

### Highlights half (LIFE-21..LIFE-24) — no poll precedent; prefer a cached snapshot

`GenericPlatformController.php:118` returns `$strategy->recentItems($identity)` in the
same response to `GET /{platform}/recent`. That is the "open picker modal, see items" UX,
and there is **no** pending/poll precedent for it anywhere in the codebase. Deferring it
would mean inventing a whole new picker loading state.

The better direction — and the audit's own suggested alternative — is a cached snapshot:
these platforms already have a working 12h `refreshEvery()` cron pipeline
(`RefreshConnectionJob` / `PlatformRefresher`, wired via `->fetch(...)` +
`->refreshEvery(...)` in `PlatformRegistryServiceProvider`) which could **also** populate a
cached recent-items snapshot instead of live-fetching on every picker open. Non-breaking,
no frontend dependency.

`ShopCatalog`'s cached-catalog fallback is the in-repo pattern to mirror.

## Defect the audit MISSED — cover it

`BandcampHighlights::apply()`
(`app/Services/Platforms/Strategies/Highlights/BandcampHighlights.php:55`) calls
`$this->scraper->enrichPrices(...)` synchronously **inside** `withConnectionLock`'s locked
read-mutate-write (`GenericPlatformController.php:134-157`). That is a sync vendor fetch
**while holding a lock** — strictly worse than what LIFE-21 describes, which only covers
the GET `/recent` picker read.

Also note `highlights()` POST (line 146) calls `recentItems()` again to revalidate chosen
ids against a fresh fetch, under the same lock.

**Whoever works this unit must cover `apply()`, not just `recentItems()`.**

## Hard constraints (this codebase will bite you)

- **Never** type `public bool $afterCommit` on a queueable job — trait conflict causes a
  silent fatal. Use `->afterCommit()` on dispatch.
- Jobs need `$backoff`/`$timeout` per house rules.
- **No Laravel migrations.** Schema changes go in `supabase/migrations/` as raw SQL, and a
  composer guard rejects Laravel ones. Any migration makes this a gated item needing
  separate sign-off.
- Tests run **SQLite in-memory**; prod is **Postgres**, and the schemas diverge. SQLite
  enforces no CHECK constraints and treats unknown quoted identifiers as **string
  literals**, so a query against a nonexistent column silently "works". Assert on
  **returned data**, never that a query ran. Verify constraint-bound writes against the
  real DDL in `supabase/migrations/`.
- If a fix depends on a column absent from the SQLite mirror (`tests/Pest.php` or a
  `*TestCase.php`), **add it** or the new assertion silently passes.
- The deployed env runs `QUEUE_CONNECTION=sync` with 0 Horizon masters, so every job runs
  **inline**. A "deferred" fetch still blocks the request there unless real workers run.
  Factor this into what "async" actually buys today, and probe with `config()` not `env()`.
- Authorization: Policies + `$this->authorizeForUser()`, never `authorize()`, never inline
  `abort_unless(..., 403)` — CI fails on it. 403 for role/type restrictions and policy
  failures; 404 when a resource doesn't exist or isn't the caller's.

## Method

Follow `scripts/audit/fix-flow.md`. Per unit: **plan (Opus) → implement (Sonnet) →
independent review by a SEPARATE Sonnet that did not write the code** → tick the box only
after tests pass AND review returns PASS → commit `fix(audit): <unit> — <ids>`.

Give reviewers the finding and the diff; do **not** hand them a checklist of what to find.
Beyond correctness have them check: would this test pass against unfixed code (if yes it is
vacuous)? Did any pre-existing test get modified, weakened, or deleted (any `-` line
against an existing test body needs explicit justification)? Does the fix hold at the real
boundary, or only where it is easy to assert?

**Do not use `git stash` in any form** — the stash stack is shared across worktrees. To
prove a test fails against pre-fix code, edit by hand and restore by hand, verifying with
`git diff`.

Branch `audit-fix/unit-11-platform-async-2026-XX-XX` off `development`. Work in a worktree
under `backend-wt/` (not `.claude/worktrees/`, which poisons the Composer classmap); each
worktree needs its own `composer install` and `.env`.

## What "done" looks like

All 12 boxes ticked in `TRIAGE-2-P2.md`, which will take it to **101/101** and let
`scripts/audit/archive-done.sh` finally archive the run folder. Run that at the end — it is
automatic, never a question.

Baseline to beat: **4355 passed, 0 failed, 144 skipped, 1 warning, 1 risky** on serial
`php artisan test` (what `composer test` and CI run) as of merge `c9d9eb59`.
