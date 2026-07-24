# Platform connect/highlights — bounded fetch + async connect

**Source:** Unit 11 of `audits/sweeps/2026-07-11-full-work-sweep/TRIAGE-2-P2.md` (LIFE-13..LIFE-24).
**Status:** ✅ **SHIPPED — both phases (re-costed 2026-07-23).** Phase 2 is merged but *dark*: the
rollout flag defaults empty, so every `connect()` response is byte-identical until a slug is named.
See the status note below before using this document to plan anything.
**Execution policy** (from the audit file): Plan = Opus 4.8 · Implement = Sonnet 4.6 · Review = a SEPARATE Sonnet 4.6.
**Branch:** `audit-fix/unit-11-platform-async-2026-07-20` off `development`, worktree under `backend-wt/`.

---

## Status note — re-cost 2026-07-23

Added when roadmap item **#12** of `docs/reviews/2026-07-23-worker-async-layer-review.md`
("async+poll the heavy platform connects") was found to be un-implementable as written. Nothing
below this note has been deleted or rewritten — the reasoning in §2b–§2h is still correct and is
carried forward. What follows corrects only the plan's **status** and its **scope**.

### Both phases shipped, not one

| Phase | State | Evidence |
|---|---|---|
| **Phase 1** — bounded fetch + highlights snapshot (LIFE-21..24) | **Shipped, live, unconditional** | `6e6a0aeb` *"bound request-cycle vendor fetches with a shared FetchBudget"*. `FetchBudget` is bound `scoped` in `AppServiceProvider.php:112` and consumed by `ConnectResolver.php:45`, `HighlightsPicker.php:39`, `YoutubeThumbnailResolver.php:39`. Budget config at `config/partna.php:1222`. |
| **Phase 2** — async connect (LIFE-13..20) | **Shipped, merged, and DARK** | `088be7f0` *"add the DeferredConnect seam and identify() on 8 strategies"*, `a9066440` *"add ConnectFetchJob and the merge-safe pending write"*, `dde6aadd` *"lock platform connections per-platform, not per-account"*. |

"Dark" is precise, not hedging. Every piece is merged and tested:

- `DeferredConnect` seam interface + `identify()` on all 8 connect strategies
  (`Strategies/Connect/{Spotify,Bandcamp,Twitch,Pinterest,Strava,Vimeo,Youtube,YoutubeMusic}Connect.php`).
- `PlatformDescriptor::deferredConnect()` / `connectFetchError()` — **wired for all 8**, at
  `PlatformRegistryServiceProvider.php:150,177,196,211,225,238,251,265`.
- `GenericPlatformController::connectDeferred()` (`:157-215`) and `connectStatus()` (`:227-269`).
- `ConnectFetchJob` with `uniqueFor = 120` (`:65,76-79`) and `markTerminal()` (`:242-249`).
- `GET /{slug}/connect/status` emitted for all 8 at `routes/api/platforms.php:298-300` — gated on the
  *capability* flag, deliberately **not** on the runtime flag, so the route never appears/disappears
  with an env var.
- Tests: `tests/Feature/Platforms/DeferredConnectTest.php`, `RegistryConnectCoverageTest.php:50-83`
  (pins flag ⇔ `instanceof DeferredConnect` **and** non-null `connectFetchErrorMessage()`), and the
  golden-master route inventory (58 → 66 routes).

The only thing not done is **flipping the lever**: `config/partna.php:1405` parses
`PARTNA_CONNECT_DEFERRED` as a comma-separated slug list defaulting to `''` → `[]`, and
`ConnectResolver.php:68-73` requires that third conjunct. §2f's rollout sequence is therefore still
the live, unexecuted to-do list for this plan.

> ⚠️ **Correction for anyone auditing this file.** `PlatformDescriptor.php:54` reads
> `private bool $deferredConnect = false;`, which looks like a flag nobody sets. It is not — the
> eight setter calls live in `PlatformRegistryServiceProvider`, because this registry builds
> descriptors with a fluent builder at boot. Grepping the descriptor class alone will wrongly
> conclude the seam is dead code. It is armed; only the env list is empty.

### The scope was narrower than roadmap #12 assumes

This plan's Phase 2 covers **exactly the 8 registry platforms that already have a `FetchStrategy`**
(§2b's table). That is what makes its "L not XL" argument work — *"the seam is not something to
invent, it is already cut, and `Strategies/Fetch/` is the far side of it"* (§ above, still true).

Roadmap #12 names a **different and disjoint** set: `ShopController`, `AppleController`,
`FreshaController`, `EventbriteController`, `SkoolController` — plus `HumanitixController`, which the
review omits but which has the same shape. Six bespoke controllers, twelve endpoints. **None of them
has a `ConnectStrategy` at all** — `PlatformRegistryServiceProvider` attaches only `->fetch()`,
`->refreshEvery()`, `->detect()` and `->connectInput()` for those slugs, never `->connect()`.

So the "L not XL" reasoning **does not transfer** to them: for these six there *is* per-platform
connect code to write, because the seam was never cut. Worse, the review's heaviest offenders live
entirely in that disjoint set — Shop at ~384 s and Apple at ~192 s are not touched by anything in
this plan.

**This plan does not discharge roadmap #12, and was never scoped to.** It is not superseded and it
is not wrong; it is *complete for its own scope and awaiting a rollout decision*. The six bespoke
controllers are designed separately in:

**→ `docs/superpowers/specs/2026-07-23-platform-connect-async-design.md`**

The single most valuable idea in this document — **the vendor fetch *is* the validation, so defer
only the separable content fetch, never the validation** — carries forward into that design intact
and unmodified. It is the constraint the new design is built around.

---

## Why this plan diverges from the audit's stated remedy

The audit says: defer `resolve()` to a queued job, across 8 Connect strategies.

**Applied literally that is wrong.** In all 8, the vendor fetch *is* the validation — it is
`fetchRecentVideos()` coming back empty that produces `fail('Could not find that YouTube channel',
404)`. Deferring `resolve()` wholesale accepts any string, writes a row, and tells the user seconds
later that what they pasted was not a real link. Worse than today, and it writes rows for garbage.

`InstagramController::connect()` works asynchronously because it does *not* have this property: the
handle is validated syntactically inline, and the job does the separable **content** fetch. That is
the shape to copy.

**The structural discovery that makes this tractable:** all 8 platforms already have a
`FetchStrategy` that takes an identity key out of `payload` and produces the complete content
payload (`YoutubeFetch` → `handle`, `VimeoFetch` → `apiPath`, `OEmbedFetch` → `link ?? url`, …).
The seam is not something to invent — it is already cut, and `Strategies/Fetch/` is the far side of
it. This is why the work is L and not XL: there is no per-platform content code to write.

Two phases. Phase 2 has a hard dependency on Phase 1.

---

# Phase 1 — Bound the fetch, cache the picker

Independently valuable, ships first, no frontend dependency, no contract change.

## 1a. The acute risk: `SafeUrlFetcher`'s timeout is per-hop

All seven vendor clients (`OEmbedService`, `BandcampScraper`, `PinterestScraper`,
`StravaClubScraper`, `TwitchScraper`, `VimeoApi`, `YoutubeScraper`) route through
`app/Services/Http/SafeUrlFetcher.php`. It **does** set `->timeout(8s)` (lines 116, 335) — but:

- 8s is **per hop**, and `max_redirects = 5` → 6 hops → 48s per `fetch()`.
- The 403 honest-UA retry (lines 80–93) re-runs the whole chain → **96s per `fetch()` call**.
- **No `->connectTimeout()` anywhere in `app/`** — a SYN-blackholed host adds Guzzle's default
  connect budget on top.
- DNS in `assertSafe()` is unbounded (`gethostbynamel()` / `dns_get_record()`, lines 439/443), once
  per hop, no timeout knob.

`YoutubeConnect` makes ~5 such calls (channel page, RSS, up to 3 pooled-HEAD thumbnail rounds via
`YoutubeThumbnailResolver::bestForMany`). Theoretical worst case for
`POST /api/platforms/youtube/connect`: **~8 minutes of held FPM worker**, before DNS.

### The fix — a per-connect wall-clock budget

1. `config/partna.php`, under `http_fetch`:
   - `'connect_timeout_seconds' => (int) env('PARTNA_HTTP_CONNECT_TIMEOUT_SECONDS', 3)`
   - `'connect_budget_seconds'  => (int) env('PARTNA_CONNECT_BUDGET_SECONDS', 20)`
2. `SafeUrlFetcher`: add `->connectTimeout($this->connectTimeoutSeconds)` to both the
   `Http::withHeaders(...)` call at line 114 and the `$pool->as(...)` at line 335.
3. `SafeUrlFetcher`: add a deadline.
   - private `?float $deadlineAt`
   - `public function withinBudget(float $seconds, callable $work): mixed` — sets
     `$this->deadlineAt = hrtime(true)/1e9 + $seconds`, runs `$work()` in a `try/finally` that nulls it.
   - private `remainingBudget(): ?float`. In `fetchFollowingRedirects()` **before** `assertSafe()`,
     and in `pooledGet()` before each chunk: if a deadline is set and remaining ≤ 0, throw
     `SafeUrlException`; else pass `->timeout((int) ceil(min($this->timeoutSeconds, $remaining)))`.
   - `tryFetch()` already swallows `SafeUrlException` → `null`, which every strategy already turns
     into its existing `ConnectResult::fail(...)`. **Zero contract change.**
4. Bind `SafeUrlFetcher` as `$this->app->scoped(...)` so the instance the scraper holds is the one
   the budget was opened on. **Confirm no existing `bind`/`singleton` first.**
5. New `App\Services\Platforms\ConnectResolver` — see the shape note in Phase 2 §7 below. It must be
   built to return an **outcome object**, not a bare `ConnectResult`, or Phase 2 has to reopen it.
6. `GenericPlatformController::connect()` line 63 delegates to it. Nothing else changes.

**Honest limitation to comment in the code:** a DNS stall inside `assertSafe()` can overshoot the
deadline by the system resolver's own timeout. Fixing that needs a resolver with a timeout — out of
scope.

## 1b. Highlights snapshot (LIFE-21..24) + the lock defect

### Design decisions

**Snapshot lives in the payload, not a cache store.** The `ShopCatalog` `Cache::remember` pattern
works there because the shop picker is reopened repeatedly inside a 10-min window. The highlights
picker is **cold by nature** — opened once, minutes after connect or weeks later. A TTL cache would
miss on nearly every real open and the blocking fetch would survive. The payload snapshot is
refreshed by the already-wired 12h `ScheduledRefresh`, so it is warm essentially always.

**New private key `recent`, not the existing public keys.** Reusing `items` would couple picker
width to render width and shrink pickers (Vimeo `recentItems` returns up to 20; `VimeoHighlights::apply`
stores only 12). `recent` is absent from `PublicIntegrationConnectionResource::ALLOWLIST` for all
four platforms (youtube :80, vimeo :117, youtube-music :119, bandcamp :116), absent from all four
dashboard resources, and absent from `FeedPayload` → dropped by `GenericPlatformController::shape()`.
**Never on any wire.**

**Freshness gate = `$row->last_refreshed_at`, no timestamp key in the payload.**
`IntegrationConnectionObserver::saved()` purges the sitepage edge cache on `wasChanged('payload')`
(line 48), so a monotonic timestamp inside the payload would fire an edge purge on *every* 12h
refresh of every picker connection, including no-op ones.

Known consequence, accepted deliberately: a Bandcamp owner with `auto_sync_latest` **off** gets
`FetchNotModifiedException` → `recordNotModified()` bumps `last_refreshed_at` → their `recent` looks
fresh forever while never updating. Consistent with the toggle's intent; reconnecting refreshes it.
Document in the `HighlightsPicker` docblock.

**Frozen 404-vs-422 semantics preserved exactly.** `identity()` null → 404 (untouched). Snapshot
absent/empty/stale → fall through to today's live `recentItems()` → null → 422. An **empty**
snapshot counts as absent — required, because `VimeoHighlights::apply()` line 39 does an unguarded
`$items[0]`.

### Files

**NEW `app/Services/Platforms/Strategies/Contracts/PreparesHighlightItems.php`**
```php
interface PreparesHighlightItems
{
    /** Enrich picker items the save will draw from. Vendor I/O is allowed here —
     *  this runs OUTSIDE the per-user connection lock, unlike apply(). */
    public function prepare(array $items, array $chosenIds): array;
}
```

**NEW `app/Services/Platforms/HighlightsPicker.php`**
- `public const SNAPSHOT_KEY = 'recent';`
- `items(HighlightsStrategy $s, IntegrationConnection $row, string $identity): ?array` — stored
  snapshot if `is_array && !== [] && fresh()`, else `$s->recentItems($identity)`.
- `fresh(IntegrationConnection $row): bool` — `last_refreshed_at?->gt(now()->subSeconds(
  config('partna.platforms.highlights_snapshot_ttl', 24*3600)))`
- `prepared(HighlightsStrategy $s, array $items, array $chosenIds): array` —
  `$s instanceof PreparesHighlightItems ? $s->prepare(...) : $items`
- **`warmInto(array $payload, IntegrationConnection $row): array`** — returns the payload with
  `recent` filled, **without writing to the DB**. Required by Phase 2's `ConnectFetchJob`; see §7.
- **Trap:** `NoUntypedPayloadAccessTest` (line 68) greps `app/Services/Platforms/` for
  `/data_get\([^;]*payload/` and this file is **not** exempt. Use array access
  `$row->payload[...] ?? null`, never `data_get()`.

**`config/partna.php`** — `'platforms' => ['highlights_snapshot_ttl' => (int) env('PARTNA_HIGHLIGHTS_SNAPSHOT_TTL', 24*3600)]`.
Setting it to `0` restores today's always-live behaviour — instant rollback lever, no deploy.

**`GenericPlatformController`** — inject `HighlightsPicker`.
- `recent()` line 113: `$strategy->recentItems($identity)` → `$this->picker->items($strategy, $row, $identity)`.
  Guard `$row === null` explicitly first, keeping the 404 message identical.
- `highlights()` lines 134–157 — restructure so **nothing vendor-facing runs inside the lock**:
```
$row = $this->requestedAccountRow($user, $accountId);
if (! $row || ! $row->payload) return $this->error($strategy->notConnectedMessage(), 404);
$identity = $strategy->identity($row->payload);
if ($identity === null)        return $this->error($strategy->notConnectedMessage(), 404);
$items = $this->picker->items($strategy, $row, $identity);
if ($items === null)           return $this->error($strategy->loadErrorMessage(), 422);
$items = $this->picker->prepared($strategy, $items, $validated[$strategy->requestField()]);

return $this->withConnectionLock($user, function () use (...) {
    $fresh = $this->requestedAccountRow($user, $accountId);       // authoritative re-read
    if (! $fresh || ! $fresh->payload) return $this->error($strategy->notConnectedMessage(), 404);
    $selection = $strategy->apply($fresh->payload, $items, $validated[$strategy->requestField()]);
    $this->writeConnection($user, $selection, $fresh->resource_id);
    return $this->success(['id' => $fresh->resource_id, ...(new $resourceClass($selection))->resolve()]);
});
```
This is exactly `ScheduledRefresh`'s documented model (line 28: *"The fetch is multi-second and
touches nothing shared — left unlocked"*; line 35: *"Only the write is guarded, not the fetch above"*).
**The re-read inside the lock is load-bearing** — without it, read-outside/write-inside reintroduces
the lost update the lock exists to prevent. This is the single most review-worthy line in the diff.

Observable delta: a 404/422 no longer acquires the lock, so 404 now wins over a 423 lock timeout.
Strictly better; no test asserts the old ordering (checked `PlatformConnectionAuthorizationTest`
:100/:408 — policy assertions only).

**`YoutubeHighlights::apply()`** — after the `refreshLatestTile` block: `$selection['recent'] = array_slice($items, 0, 15);`
**`VimeoHighlights::apply()`** — `$selection['recent'] = $items;`
**`YoutubeMusicHighlights::apply()`** — `$selection['recent'] = $items;`

**`BandcampHighlights`** — the lock defect. Three changes:
- `implements HighlightsStrategy, PreparesHighlightItems`
- extract `private function chosenIndices(array $items, array $chosenIds): array` with **exactly**
  `apply()`'s current semantics — filter unresolvable ids first, *then* take `MAX_HIGHLIGHTS`.
  **Correctness trap:** a naive `array_slice($chosenIds, 0, 5)` in `prepare()` diverges whenever the
  payload contains unresolvable ids (validation allows `max:24`), silently leaving the 5th real pick
  unpriced. One shared helper, used by both.
- `prepare()`:
```php
$indices = $this->chosenIndices($items, $chosenIds);
if (isset($items[0])) { $indices[0] = true; }        // the "Most recent" tile
$subset = array_intersect_key($items, $indices);      // ORIGINAL keys preserved
if ($subset === []) { return $items; }
foreach ($this->scraper->enrichPrices($subset, count($subset)) as $i => $enriched) {
    $items[$i] = $enriched;
}
return $items;
```
  `enrichPrices` does `array_slice($items, 0, $cap, true)` (key-preserving) and writes back via
  `$items[$i]`, so a sparse-keyed subset with `$cap = count($subset)` merges onto the right indices.
  Max 6 URLs = one `fetchMany` pool round.
- `apply()` loses **both** `enrichPrices` calls (lines 44, 55). Line 44 → `refreshLatestTile($selection, $items[0], self::FLAT_FIELDS)`
  (already priced by `prepare()`). Line 55 → plain `->values()->all()`. Add `$selection['recent'] = array_slice($items, 0, 15);`

**Net: two sequential fetch rounds inside the lock → one 6-URL pool round outside it.**

**Fetch strategies** — persist the snapshot on every 12h refresh:
- `YoutubeFetch` — add `'recent' => array_slice($videos, 0, 15),` (the only one needing real widening)
- `VimeoFetch` — add `'recent' => $videos,` (already stores a 12-item `items`)
- `YoutubeMusicFetch` — add `'recent' => $items,` **and raise `fetchUploadsFeed($channelId, 12)` to 15**
  so a save after a refresh sees the same set the picker showed
- `BandcampFetch` — add `'recent' => array_slice($profile['items'], 0, 15),`

**Blast radius:** `tests/Feature/Platforms/Strategies/FeedFetchParityTest.php` and
`RefresherCutoverParityTest.php` byte-compare fetch output and **will** fail until their expected
arrays are widened. Update them in the same commit — **do not weaken them to ignore unknown keys.**

**Connect strategies** — warm the snapshot so the picker is fast on the first open. Four one-line
payload additions (`YoutubeConnect`, `VimeoConnect`, `YoutubeMusicConnect`, `BandcampConnect`).
**This is not the async conversion.**

**One-time deploy effect:** the first scheduled refresh after ship dirties `payload` on every
youtube/vimeo/youtube-music/bandcamp connection (new key) → one edge-cache purge wave. Steady state
unchanged.

## 1c. Phase 1 tests

`phpunit.xml` sets `CACHE_STORE=array`. **Verify empirically before writing P1-3** that `ArrayStore`
locks are not re-entrant in-process (a second `Cache::lock($key)->get()` on a held key must return
`false`). If they are, switch that test's store to `database` or probe via `Cache::getStore()`.

| # | Behaviour | Why it fails before |
|---|---|---|
| P1-1 | Fresh snapshot serves the vimeo picker with no vendor call. Connect (mock 8 videos), re-mock `fetchVideos` `->never()`, `GET /recent` → `assertOk`, `assertJsonCount(8,'videos')`, `assertJsonPath('videos.0.itemId','101')` | Unfixed `recent()` unconditionally calls `recentItems()` → `->never()` fails at teardown |
| P1-2 | YouTube snapshot round-trip. `fetchRecentVideos` `->once()` on connect, `->never()` on the GET → `assertJsonCount(15,'videos')` | YouTube stores no item list today, so the GET necessarily re-scrapes |
| P1-3 | **The lock defect.** Probe inside the `enrichPrices` mock: `$held = ! Cache::lock($key,1)->get();` → `expect($held)->toBeFalse()` | Unfixed `apply()` runs `enrichPrices` at :44 and :55 inside `withConnectionLock` → probe cannot acquire → `true`. Add the same probe to `fetchProfile` to cover LIFE-21. Resolve the lock suffix from the created row (`ScheduledRefresh` :36 shows the rule) — do not hardcode |
| P1-4 | Bandcamp saves in one round trip. `enrichPrices` `->once()`; assert `assertJsonCount(3,'highlights')` + `assertJsonPath('latest.itemId', …)` | Unfixed calls it twice. Data assertions pin that collapsing didn't drop latest-tile pricing |
| P1-5 | Prices land correctly when the payload has unresolvable ids (`['nope','album-1'..'album-5']`) | **Vacuous before — label it.** Targets the `chosenIndices` trap in the new code |
| P1-6 | `HighlightsPickerTest` (unit): fresh+non-empty → stored, strategy `->never()`; 48h old → `->once()`; `recent => []` → `->once()` | `HighlightsPicker` doesn't exist. **Caveat:** the stale branch reproduces today's behaviour and has no before/after power — comment it as such |
| P1-7 | Leak guard: seed `recent` in a payload, assert absent from the public payload for all four platforms | **Deliberately vacuous — label it.** Cheap guard against a future allowlist edit |

**Regression surface (must keep passing):** `IntegrationsV3ConnectionTest:104-148`,
`IntegrationsV4AdditionsTest:153-176`, `IntegrationsV2ConnectionTest:164-193`,
`AccountCanonicalKeyDedupeTest:79`, `PlatformFixesTest:225`, `PlatformConnectionAuthorizationTest:100,408`.

**SQLite mirror:** `tests/Pest.php:561-584` already has every column. No mirror edit.

## 1d. Phase 1 risks

1. **Lost update from read-outside/write-inside** — mitigated by the in-lock re-read. Reference:
   `GoogleBusinessEnrichConcurrencyTest`, `IdentitySyncConcurrencyTest`.
2. **Stale picker** — ≤12h old until the cron catches up. `PARTNA_HIGHLIGHTS_SNAPSHOT_TTL=0` is the
   rollback lever.
3. **Payload growth** — `recent` adds ~4KB per picker row; YouTube grows ~1KB → ~5KB. Negligible.
4. **One-time edge-purge wave** on first refresh after ship.
5. **Connect budget false positives** — a legitimately slow vendor now returns the platform's
   existing "couldn't load" error instead of eventually succeeding at 40s. `PARTNA_CONNECT_BUDGET_SECONDS`
   is the lever. `connectTimeout(3)` is **global** across every scraper (menu, shop, events, link
   cards) — safe for public hosts; the *deadline* is opt-in per call site so only connect is affected.
6. **`SafeUrlFetcher` `scoped` binding** changes its lifecycle app-wide. Stateless today apart from
   constructor config reads — but confirm no existing binding.

**No migrations. No new jobs. No policy changes.**

---

# Phase 2 — Async connect (LIFE-13..20, done properly)

## 2a. Verified facts

1. **`payload jsonb NOT NULL DEFAULT '{}'`** (`20260602150238_create_platform_connections.sql:19`).
   `InstagramController::connect()` carries the scar tissue at :81-83. **This design is structurally
   immune to that incident class** — every pending row carries a non-empty identity payload
   (`{handle: …}`, `{url:…, link:…, embedUrl:…}`), never `[]`, never null.
2. **`last_refresh_status` already permits `'pending'` table-wide**
   (`20260616000000_allow_pending_refresh_status.sql`), and no later migration narrows it (the
   `20260701220000` reference is a comment only — verified). The design uses only
   `pending`/`ok`/`unavailable`/`error`. **Zero SQL migrations → not a separately gated item.**
   *Caveat:* SQLite doesn't enforce CHECK, so **no test can prove `'pending'` is writable.** The
   migration file is the proof. Do not let a reviewer accept a passing test as evidence here.
3. **SQLite mirror complete** — no changes.
4. **`GET /platforms/meta` is NOT sufficient as the poll endpoint.** `IntegrationsMetaController`
   aggregates *per platform*, collapsing multi-account rows, and returns **no payload**. Seven of the
   eight are multi-account, so connecting a second YouTube channel behind a healthy first would poll
   `ok` immediately — a false ready. A per-platform `connectStatus` keyed on `resource_id` is required.
5. **Frontend fact (load-bearing for rollout).** `partna-frontend` `origin/main`
   `app/(app)/account/(dashboard)/integrations/connect-modals.tsx` ~:253 is the sole caller for all 8:
   `await withMinDuration(authedJsonRequest(config.endpoint, {method:"POST", body:{...}})); onConnected()`.
   It **discards the response body** and only distinguishes 2xx from throw. **A 202 will not break the
   current dashboard.** *(Read-only observation — this repo never clones/pulls/commits that repo.)*

## 2b. Per-platform seam table

| Platform | Inline (free, no network) | Identity written | Job runs | Deferred fetches | Verdict |
|---|---|---|---|---|---|
| **spotify** | `parseEntity()` — pure regex, `open.spotify.com/(artist\|album\|playlist\|track\|show\|episode\|user)/[A-Za-z0-9]+` | `url`,`link`,`embedUrl` | `OEmbedFetch` | 1 | SPLITS WITH CAVEAT |
| **bandcamp** | `normalizeOrigin()` — pure | `url` | `BandcampFetch` | 2 | SPLITS CLEANLY |
| **twitch** | `parseLogin()` — pure, host-anchored + reserved list | `login` | `TwitchFetch` | 1 | SPLITS CLEANLY |
| **pinterest** | `parseUsername()` — pure, multi-TLD + reserved list | `username` | `PinterestFetch` | 2 | SPLITS CLEANLY |
| **strava** | `normalizeUrl()` — pure | `url` | `StravaFetch` | 1 | SPLITS CLEANLY |
| **vimeo** | `parseSource()` — pure `parse_url` + host allowlist + reserved list | `apiPath`,`url`,`link` | `VimeoFetch` | 2 | SPLITS CLEANLY (nuance below) |
| **youtube-music** | `channelIdFrom()` — pure only for `/channel/UC…`; `@handle` costs 1 fetch | `channelId` | `YoutubeMusicFetch` | 1 of 2 | SPLITS WITH CAVEAT |
| **youtube** | `normalizeHandle()` — pure, but falls back to `PlatformInput::token()` = almost any string | `handle` | `YoutubeFetch` | 2 | **SPLITS WITH CAVEAT — the weak one** |

### The honest cases

**Spotify — irreducibly both, and it doesn't matter.** `OEmbedService::resolve()` is simultaneously
the existence check and the content fetch; there is no cheaper probe. But: (1) the syntactic gate is
the *strongest* of the eight — you cannot produce a pending row without a real `open.spotify.com` URL
with an enumerated entity type and a base62 id; (2) **a pending Spotify row is already a working
product** — `embedUrl` is derived deterministically from `{type}/{id}`, and `SpotifyConnect` only
uses oEmbed's `iframe_url` as a *preference* over the constructed form. The row renders a functioning
player the instant it's written; the job upgrades `name` and `thumbnail` only. A dead id yields a dead
embed whether checked now or in three seconds. **Spotify splits best, not worst.**

**YouTube — the one that risks being worse than today.** `YoutubeScraper::normalizeHandle()` (:22)
ends with `PlatformInput::token($s)` = `ltrim(trim($input),'@')`; `YoutubeConnect` only rejects `''`;
the descriptor validates `['required','string','max:200']` with **no regex**
(`PlatformRegistryServiceProvider:346`). So today `"hello world"` reaches the fetch, fails, and
returns a clean 422. Under a naive split it would write a row. **This is the exact concern, real for
exactly one platform.**
- **(a) RECOMMENDED — needs sign-off.** Post-normalize assertion in `YoutubeConnect::identify()`:
  reject unless `preg_match('~^[A-Za-z0-9._-]{3,100}$~', $handle)` — the actual YouTube handle
  charset, matching the regex already in `normalizeHandle`'s URL branch. Failure returns the
  **existing** message `'Enter your YouTube channel.'` — no new wording, no 422 body change. It can
  reject an input that today would have succeeded, but only inputs with spaces/slashes/non-handle
  characters, which cannot be real handles.
- (b) Leave YouTube synchronous — defensible, but YouTube is the worst latency offender (5 fetches),
  so this forfeits most of the win.
- (c) Accept garbage rows — **rejected.**

**YouTube Music — the seam is in the input form.** Keep `channelIdFrom()` inline for both forms: it
preserves the contract exactly (a handle that fails to resolve returns the descriptor's existing 422,
already the right wording), still halves the cost (2 fetches → 1 inline), and avoids a pending row
with no `channelId` (which would make `YoutubeMusicFetch` throw `FetchShapeException`). The residual
inline call is bounded by Phase 1's budget — one of the reasons Phase 1 survives.

**Vimeo nuance — must not be glossed.** `VimeoConnect::resolve()` succeeds when
`$profile !== null || $videos !== []`. `VimeoFetch::fetch()` throws `FetchUnavailableException('vimeo_no_videos')`
when `$videos === []`. So **a Vimeo profile that exists but has zero uploads connects today and would
land in `unavailable` under the job.** Options: (i) accept it (such a profile renders an empty card
anyway) plus an explicit test documenting the change, or (ii) add a `VimeoFetch` tolerance for the
connect path. **Recommend (i) — needs sign-off.**

## 2c. The contract

### Decision: a new optional seam interface, `DeferredConnect extends ConnectStrategy`

**Rejected — `ConnectResult::pending()`.** `ConnectResult::failed()` is `$this->selection === null`.
A pending result *has* a selection, so it reports `failed() === false`, and
`GenericPlatformController::connect()` — which branches on nothing else — would write it through
with `last_refresh_status => 'ok'` and return 200. **A forgotten call site fails silently and looks
correct.**

**Rejected — widening `ConnectStrategy`.** Forces all 13 implementers (including the 5 that must not
break) to grow a method they don't want, and gives the boot-time route loop no way to ask "is this
deferred?" without resolving the strategy factory.

**Chosen — seam interface.** Matches the established `ApiKeyConnect`/`OAuthConnect`/`WebhookRefresh`
convention in the same directory; `instanceof` is explicit and greppable; the 5 non-async
implementers (`UrlConnect`, `SoundcloudConnect`, `OpenTableConnect`, `NowBookitConnect`,
`ResDiaryConnect`) have **zero diff**.

### Critical sub-decision: `resolve()` is NOT refactored to call `identify()`

`identify()` is **additive** and duplicates 3–5 lines of parse logic per platform. `resolve()` is the
frozen synchronous contract, golden-master-tested
(`tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php`), and it is the
fallback path when the flag is off. Refactoring it to `identify() + fetch()` would silently alter
behaviour in at least one place already found (Vimeo's `profile || videos` vs `VimeoFetch`'s
`videos`), and probably in ways not yet found. Five lines of duplicated regex is far cheaper than
eight quietly-changed frozen contracts. A parity test pins the duplication.

**`ConnectResult.php` and `ConnectStrategy.php` are NOT edited.** That is a feature of the design.

### `app/Services/Platforms/Strategies/Contracts/DeferredConnect.php` (new)

```php
<?php

namespace App\Services\Platforms\Strategies\Contracts;

// SEAM INTERFACE — a connect strategy whose vendor CONTENT fetch can be split off
// from its input VALIDATION, so the controller can write a row and return 202 while
// a queued job fills the payload.
//
// The split point is NOT arbitrary. identify() must return the exact identity key(s)
// the platform's registered FetchStrategy reads out of the stored payload
// (YoutubeFetch → 'handle', VimeoFetch → 'apiPath', OEmbedFetch → 'link' ?? 'url',
// …), because THAT strategy — not new code — is what the connect job runs. A
// selection returned here is a partial payload, valid to store, incomplete to render.
//
// identify() MUST be cheap: no network, no vendor call, no blocking I/O. The one
// deliberate exception is YoutubeMusicConnect, whose @handle→channelId resolution is
// a single fetch retained inline so its frozen 422 contract is preserved; it is
// bounded by ConnectResolver's wall-clock budget (Phase 1). Any new implementer that
// wants to make a network call in identify() must justify it the same way.
//
// resolve() is INTENTIONALLY still required and is NOT reimplemented in terms of
// identify(): it remains the byte-identical synchronous path used when the platform's
// async flag is off, and it is what the golden-master contract tests pin. The small
// duplication of parse logic between the two is deliberate and guarded by
// tests/Feature/Platforms/Strategies/DeferredConnectParityTest.php.
interface DeferredConnect extends ConnectStrategy
{
    /**
     * Cheap syntactic validation only. On success returns ConnectResult::ok() whose
     * selection is the PARTIAL payload — the FetchStrategy's identity key plus any
     * value derivable with zero network (Spotify's deterministic embedUrl). On
     * failure returns ConnectResult::fail() with the SAME message and status the
     * platform's resolve() would have returned for the same input, so the inline
     * 422 contract is unchanged.
     */
    public function identify(string $input): ConnectResult;
}
```

### `PlatformDescriptor` — three boot-safe accessors

```php
private bool $deferredConnect = false;
private ?string $connectFetchErrorMessage = null;

/**
 * Declare that this platform's connect strategy implements DeferredConnect and may
 * run its content fetch on the queue. BOOT-SAFE BY CONSTRUCTION: the route loop
 * iterates the registry at boot to decide whether to emit /connect/status, and it
 * CANNOT call connectStrategy() — that resolves the lazy factory and bakes a real
 * scraper into the descriptor before any test can mock it (the same reason
 * hasHighlights() is a flag rather than a null-check on the resolved strategy).
 * This is a declared flag, never an instanceof.
 *
 * RegistryConnectCoverageTest asserts flag ⇔ instanceof for every descriptor.
 */
public function deferredConnect(bool $enabled = true): self { … }
public function supportsDeferredConnect(): bool { … }

/**
 * The user-facing message shown when the DEFERRED content fetch fails — the exact
 * string this platform's resolve() returns today for the same failure. Under async
 * connect that message is no longer deliverable as a 422 body, so it is stored on
 * last_refresh_error and surfaced verbatim by connect/status. Contract-preserving by
 * construction: same words, different transport.
 */
public function connectFetchError(string $message): self { … }
public function connectFetchErrorMessage(): ?string { … }
```

Registry wiring — eight pairs, each message copied **verbatim** from the corresponding `resolve()`:
```php
$r->get('youtube')->deferredConnect()
    ->connectFetchError('Could not find that YouTube channel or its latest video.');
```

### Storage — `ManagesIntegrationConnection`

`writeConnection()` hardcodes `'last_refresh_status' => 'ok'`. `writePendingLinkCard()` exists but is
link-card-specific (no `canonical_key`, no cap/dedupe). **Do not add a third near-copy.**

- Extract the shared upsert body of `writeConnection`/`writePendingLinkCard` into one private
  `upsertConnection(User, array $values, ?string $resourceId)` owning create-vs-update policy
  resolution and `assertPlatformAvailable`.
- Add `writeAccountConnection(User $user, string $canonicalKey, array $payload, bool $pending = false)`
  — one new defaulted parameter, keeping the cap check, derived-hash-then-`canonical_key` dedupe, and
  `null`-on-cap return where they live today.
- **Pending writes MERGE, never replace.** When `$pending === true` and a row exists:
  `$payload = [...($existing->payload ?? []), ...$payload];`
  Load-bearing for three distinct bugs:
  1. **Reconnect UX** — the card stays rendered instead of blanking to an identity stub.
  2. **The Bandcamp 304 trap** — `BandcampFetch` throws `FetchNotModifiedException` when
     `display_settings.auto_sync_latest === false`; on reconnect the job 304s, correct *only* if the
     old payload survived. With a replace, the card is permanently empty.
  3. **The conditional-request trap** — `OEmbedFetch`/`YoutubeMusicFetch` send `If-None-Match` from
     `refresh_etag`; a 304 on reconnect is likewise only safe if the payload survived.

## 2d. The job — `app/Jobs/Platforms/ConnectFetchJob.php`

**One generic registry-driven job.** Eight per-platform jobs would be eight copies of
`$descriptor->fetchStrategy()->fetch($connection)`; the variance lives entirely inside the already-
registry-resolved `FetchStrategy`.

**Why not reuse `RefreshConnectionJob`** (it is nearly this job already) — four disqualifying differences:
1. `PlatformRefresher::recordFailure()` calls `PlatformHealthNotifier::connectionRefreshFailing()`.
   Emailing "your connection is failing" four seconds after a user clicks Connect is wrong.
   **A failed connect is not a failing connection.**
2. `uniqueFor = 7200` — a user who mistypes, sees the failure, and retries within two hours has the
   retry silently swallowed.
3. `retryUntil = now()->addHours(2)` with `tries = 0` — a connect a human is watching must fail fast.
4. It early-returns on `! $connection->is_active`, coupling connect correctness to a flag it doesn't own.

```
implements ShouldBeUnique, ShouldQueue
uses Dispatchable, InteractsWithQueue, Queueable, SerializesModels

__construct(public readonly string $connectionId, public readonly string $platform)
    → $this->onQueue(config('partna.queues.platform_connect'))

public int $tries         = 3;
public array $backoff     = [5, 20];   // a human is watching — not RefreshConnectionJob's 30/120/300
public int $timeout       = 45;        // must exceed Phase 1's connect budget with headroom
public int $uniqueFor     = 120;       // short: a deliberate retry after 2 min must actually run
public int $maxExceptions = 2;

uniqueId(): string => $this->platform.':'.$this->connectionId;
middleware(): []    // deliberately NO RateLimited('platform-connect')
```

**No rate-limit middleware, deliberately.** `partna.connect.rate_limits` is keyed by *Apify actor* and
exists to cap paid-scrape burst. All eight of these are keyless public scrapes. Attaching that
limiter would couple free connects to the paid budget and mass-release them during exactly the signup
spike we want to serve.

**New queue lane.** `config/partna.php`:
`'platform_connect' => env('PARTNA_QUEUE_PLATFORM_CONNECT', 'platform_connect')`.
Connects are interactive; `scraping` carries 110-second Apify Instagram jobs on two workers, so
sharing it puts a user-visible spinner behind an Apify backlog. **Requires a Horizon supervisor entry
— flag for the Horizon deployment work.**

**`afterCommit`.** `ConnectFetchJob::dispatch($row->id, $slug)->afterCommit();` at the `ConnectResolver`
call site. **Never** a typed `public bool $afterCommit` property — trait conflict, silent fatal.
(`EnrichLinkCardJob` and `InstagramConnectJob` both correctly avoid it; keep the streak.)

### `handle()`

```
1. $connection = IntegrationConnection::find($this->connectionId);
   null (soft-delete scope covers "user disconnected while queued") → return.

2. $descriptor = registry->get($this->platform); $fetch = $descriptor?->fetchStrategy();
   null → markTerminal('error', 'unsupported_platform'); return.

3. FETCH OUTSIDE THE LOCK:
     try { $next = $fetch->fetch($connection); }
     catch (FetchNotModifiedException) → markOk($connection); return;   // payload intact (merge rule)
     catch (FetchShapeException $e)    → report($e);
                                         markTerminal('error', $descriptor->connectFetchErrorMessage()); return;
     catch (FetchUnavailableException) → markTerminal('unavailable', $descriptor->connectFetchErrorMessage()); return;

4. WARM THE HIGHLIGHTS SNAPSHOT, ALSO OUTSIDE THE LOCK (Phase 1 coupling — §2g):
     if ($descriptor->hasHighlights()) { $next = $picker->warmInto($next, $connection); }

5. SINGLE LOCKED WRITE (same key + suffix rule as ScheduledRefresh::run()):
     $suffix = $connection->resource_id === $connection->platform ? null : $connection->resource_id;
     Cache::lock(CacheKeyGenerator::platformConnectionLock($platform, $user_id, $suffix), 10)->block(5, fn () =>
         $connection->update([
             'payload' => $next, 'last_refreshed_at' => now(),
             'last_refresh_status' => 'ok', 'last_refresh_error' => null,
             'consecutive_failures' => 0,
             'refresh_etag' => $connection->refresh_etag,
             'refresh_last_modified' => $connection->refresh_last_modified,
         ]));
```

- **Same lock key/suffix as `ScheduledRefresh`**, so a connect job cannot race a dashboard highlights
  save or a scheduled refresh. Phase 1's restructured `highlights()` composes correctly.
- **One lock acquisition, one write.** Content + `recent` go in together; taking the lock twice opens
  a window for a highlights save to land between them.
- **`LockTimeoutException` must NOT be swallowed.** `ScheduledRefresh` logs and skips — right for an
  hourly cron, catastrophically wrong here (row stays `pending` forever, user polls forever). On
  timeout: `$this->release(3)`. On the final attempt `failed()` fires and writes a terminal status.

**`handle()` must not throw on expected upstream failure.** Every `Fetch*Exception` is caught and
converted to a terminal row state. This is what makes sync-driver behaviour identical to queued
behaviour. Only genuinely unexpected throwables propagate.

### `failed(Throwable $e)`

`report($e)` → `Log::error('platform.connect_job.failed', …)` → find the row and
`forceFill(['last_refresh_status' => 'unavailable', 'last_refresh_error' => $descriptor?->connectFetchErrorMessage()
?? 'We could not load that account. Please try again.', 'consecutive_failures' => +1])->saveQuietly();`
`saveQuietly()` matches `InstagramConnectJob::markFailed` and avoids an edge-cache purge for an
unchanged payload.

### Stuck-pending escape hatch

If the worker dies between dispatch and `failed()`, the row sits `pending` and the client polls
forever. **`connectStatus` treats `last_refresh_status === 'pending' && updated_at < now()->subMinutes(5)`
as `failed`.** No new column, no migration, no reaper cron.

### What the user sees

| Failure | Row status | `last_refresh_error` | Poll |
|---|---|---|---|
| Parse fail | *no row written* | — | inline **422** — unchanged from today |
| Upstream miss (`FetchUnavailable`) | `unavailable` | descriptor's message | `{status:'failed', error:'<exact message>'}` |
| Missing identity key (`FetchShape`) | `error` | same | `{status:'failed'}` + `report()` — should be unreachable; a canary |
| 304 (reconnect) | `ok` | null | `{status:'ready', …}` with preserved payload |
| Job died / lock starved | `unavailable` | generic | `{status:'failed'}` |
| Worker vanished | `pending`, stale | — | `{status:'failed'}` after 5 min |

## 2e. API contract — spec for the frontend team

> **Scope.** `spotify`, `bandcamp`, `pinterest`, `strava`, `twitch`, `vimeo`, `youtube`,
> `youtube-music`. `soundcloud`, `opentable`, `resdiary`, `nowbookit` and every link-only social
> remain fully synchronous with byte-identical responses.
>
> **Activation.** Per-platform, server-side flag. Until a platform is flipped on, its endpoints behave
> exactly as "Before". Frontend may ship the new handling ahead of the flag — the new path is a
> superset of the old.
>
> ### `POST /api/platforms/{slug}/connect`
>
> Request — **unchanged**.
>
> **Before — 200:** `{ "id": "acct-9f2c…", "url": "…", "name": "Artist", "thumbnail": "…", "embedUrl": "…" }`
> (`id` on multi-account platforms — all but `pinterest`/`strava`.)
> **Before — failure:** 422 (or 404 for some upstream misses) `{ "message": "Could not load that Spotify link." }`
>
> **After — 202 accepted:**
> ```json
> { "status": "pending", "id": "acct-9f2c…",
>   "statusUrl": "/api/platforms/spotify/connect/status?account=acct-9f2c…",
>   "url": "https://open.spotify.com/artist/abc",
>   "embedUrl": "https://open.spotify.com/embed/artist/abc" }
> ```
> Selection keys present are whatever was derivable without a vendor call — always at least the
> identity field, at the **same key names** as the 200 shape. `name`/`thumbnail` typically absent
> until `ready`. On a **reconnect** the previous payload is preserved and returned in full, so the
> card never blanks.
>
> **After — 422 rejected:** `{ "message": "Enter a Spotify link (open.spotify.com/artist/...)." }` —
> unchanged wording and status. This is now the *only* inline failure.
>
> **Removed:** connect no longer returns **404** — that case becomes a poll `failed`.
> **Unchanged:** 423 (concurrent-mutation lock), 503 (staff-disabled platform), 422 account cap.
>
> ### `GET /api/platforms/{slug}/connect/status?account={resourceId}` — NEW
>
> `account` is the `id` from the 202; omit for `pinterest`/`strava`.
> ```json
> { "status": "pending" }
> { "status": "ready", "id": "acct-9f2c…", "connection": { "url": "…", "name": "…", "thumbnail": "…", "embedUrl": "…" } }
> { "status": "failed", "error": "Could not load that Spotify link." }
> ```
> `connection` is the **identical shape** `GET /selection` and `/accounts` return — same Resource
> class, no new client-side type.
> `404 { "message": "Account not found." }` when the resource doesn't exist **or isn't the caller's**
> — deliberately not 403, no existence leak.
> `error` is the same user-facing sentence the endpoint returned as a 422/404 body before. Safe to
> display verbatim; never contains internal scraper detail.
>
> ### Recommended client behaviour
> ```
> 422 → show message inline, keep modal open        (unchanged)
> 200 → platform still synchronous — existing path  (unchanged)
> 202 → render the partial selection immediately, then poll statusUrl every 1.5s,
>       giving up after 30s → treat as failed
>         pending → keep spinner
>         ready   → replace card with `connection`, close modal
>         failed  → show `error`, offer retry
> ```
> An existing client that ignores the body and treats any 2xx as success continues to work: the modal
> closes, the card appears thin, and fills in on the next `/selection` fetch. **Degraded, not broken.**
>
> `GET /platforms/meta` is **not** a poll endpoint — it aggregates across a platform's connections and
> returns no payload.

## 2f. Rollout

**Per-platform config flag + dual-shape 202 body. Both, not either.**

```php
// config/partna.php
'connect' => [
    'deferred' => array_filter(explode(',', (string) env('PARTNA_CONNECT_DEFERRED', ''))),
    // …existing rate_limits…
],
```
`ConnectResolver` requires **both** `$descriptor->supportsDeferredConnect()` and
`in_array($slug, config('partna.connect.deferred'), true)`. Default `''` — **async is off everywhere
on merge.** Per-platform, per-environment via env, no deploy. Same lever is the kill switch.

**Why not big-bang.** Eight platforms × a frontend that discards bodies × two code paths. One bad
deploy turns every connect into a silent thin card with no way to tell which platform broke it.

**Why the dual shape too.** `connect-modals.tsx` cannot distinguish 200 from 202. If the 202 body did
not carry the selection at today's key names, flipping a flag would blank the card on an unmodified
client. Because it does, the flag can be flipped ahead of the frontend release with a coherent
result. **The flag controls blast radius; the dual shape controls what happens inside it.**

Honest caveat: for Spotify the pending card is *fully functional* (working embed, missing only
name/artwork). For the other seven it renders thin for a few seconds. **So flip only Spotify ahead of
the frontend; hold the other seven until polling ships.**

**Sequence.** (1) Merge with `PARTNA_CONNECT_DEFERRED=''` — zero behaviour change everywhere.
(2) Hand §2e to the frontend team. (3) Staging: flip all 8, exercise by hand. (4) Production:
`spotify` → observe → `bandcamp,twitch,pinterest,strava,vimeo` → observe → `youtube,youtube-music`
last (weakest gate, most fetches). (5) Once frontend polling ships, `youtube` gains the most.

### The `sync` driver — what it means concretely

Until Horizon runs in an environment, `dispatch(...)->afterCommit()` **runs inline in the request,
after commit.**

1. **A throwing `handle()` becomes a 500, not a `failed()` callback.** No queue to catch it, no
   `failed()` invocation. This is why §2d mandates catching every `Fetch*Exception`. Get it wrong and
   dev turns every upstream miss into a 500 where production shows a clean `failed`.
   **Highest-value correctness constraint in the plan.**
2. **Dev gets no latency win** — the fetch just happens after the row write instead of before.
   Phase 1's budget is the only thing making dev connects tolerable until Horizon lands. Say this
   plainly to anyone testing on dev.
3. **A poll immediately after a dev connect returns `ready`, never `pending`** — the row is already
   `ok` before the 202 is serialised. **Dev cannot exercise the pending state at all.** "I connected
   on dev and it worked" is not evidence the async path works.
4. **Tests must never rely on the sync driver to prove async behaviour.** `Queue::fake()` to assert
   dispatch; instantiate the job and call `handle()` directly to assert behaviour.
5. `->afterCommit()` is respected on the sync driver, so ordering semantics match — only isolation differs.

## 2g. Phase 2 tests

New file `tests/Feature/Platforms/DeferredConnectTest.php` unless noted.

| # | Behaviour | Why it fails before |
|---|---|---|
| P2-1 | 202 without fetching. `Queue::fake()`, `Http::fake()` with **no** routes; POST spotify connect → `assertStatus(202)`, `assertJsonPath('status','pending')`, `assertJsonPath('embedUrl','https://open.spotify.com/embed/artist/abc')` | Today `resolve()` calls oEmbed synchronously; with no fake registered it fails → 422, not 202. Asserts on returned **data** |
| P2-2 | Pending payload satisfies the real NOT NULL: `expect($row->payload)->toBeArray()->not->toBeEmpty()`; `payload['link']` correct | No pending row exists today. Partly a guard for the SQLSTATE 23502 class — SQLite won't enforce NOT NULL, so this asserts payload *content* as the enforceable proxy. **The constraint itself is unprovable in this suite** |
| P2-3 | `Queue::assertPushed(ConnectFetchJob::class, fn ($j) => $j->connectionId === $row->id && $j->platform === 'spotify')` | Job doesn't exist |
| P2-4 | Job fills payload via the existing FetchStrategy. Seed pending `{link:…}`, mock `OEmbedService::resolve`, call `handle()` directly, assert `payload['name']` + status `ok` | Job doesn't exist. Direct `handle()` — driver-independent |
| P2-5 | **Pending write MERGES.** Seed an `ok` bandcamp row with `latest`; POST reconnect; assert `payload['latest']` unchanged and `payload['url']` present | A naive implementation overwrites with the stub, blanking the card |
| P2-6 | 304 on reconnect keeps payload and marks `ok`. Mock `FetchNotModifiedException`; assert `payload['name']` unchanged + status `ok` | Naive handling treats 304 as failure → `unavailable` |
| P2-7 | Upstream miss surfaces the frozen message. Mock `FetchUnavailableException`, GET status → `assertJsonPath('error','Could not find that YouTube channel or its latest video.')` | Endpoint doesn't exist. Pins the contract-preservation claim |
| P2-8 | Health notifier NOT called on failed connect: `shouldNotReceive('connectionRefreshFailing')` | Guards the specific reason `RefreshConnectionJob` was rejected; fails if the implementer reuses `PlatformRefresher` |
| P2-9 | Poll 404s (not 403) for another user's resource → `assertStatus(404)`, `assertJsonPath('message','Account not found.')` | Endpoint doesn't exist. Also the CI house rule |
| P2-10 | Stale pending reports failed. Seed `pending` with `updated_at = now()->subMinutes(10)` → `failed` | Naive implementation reports `pending` forever |
| P2-11 | Flag off = byte-identical sync response. `config(['partna.connect.deferred' => []])` → `assertOk`, `assertJsonPath('name','Artist')`, `Queue::assertNothingPushed()` | **Deliberately vacuous — labelled.** Rollout safety guard proving the flag restores the old path |
| P2-12 | `identify()`/`resolve()` parity — `tests/Feature/Platforms/Strategies/DeferredConnectParityTest.php`. Dataset `[slug, input, identityKey]` × 8: `identify($in)->selection[$key] === resolve($in)->selection[$key]` | Guards the §2c decision to duplicate parse logic — the only real risk it carries |
| P2-13 | Parse-fail parity — same file, × 8: same `error` and `status` from both | Guards the "inline 422 contract unchanged" claim |
| P2-14 | YouTube garbage still rejected inline: `{"channel":"hello world"}` → 422 and `IntegrationConnection::where('platform','youtube')->count() === 0` | **The test the whole YouTube analysis exists for.** Fails loudly against a naive split. Passes vacuously today, so must be paired with P2-1 to have teeth. Depends on remedy (a) |
| P2-15 | Registry flag ⇔ interface — extend `RegistryConnectCoverageTest`: `supportsDeferredConnect() === (connectStrategy() instanceof DeferredConnect)`, and every deferred descriptor has a non-null `connectFetchErrorMessage()` | Catches the boot-safety trap — a descriptor declaring the flag without the interface emits a `/connect/status` route that 500s |
| P2-16 | The 5 non-deferred implementers untouched — extend the golden master | **Deliberately vacuous — labelled.** Blast-radius guard |
| P2-17 | Public render tolerates a pending row. Seed pending youtube `{handle:'x'}`, hit the public endpoint, assert 200 and either omitted or rendered without error | **Resolves the open `is_active` question.** May reveal `is_active => false` is required — resolve at implementation time, not by assumption. Cross-ref `PublicPlatformEndpointTest`, `PublicIntegrationAllowlistTest` |

## 2h. Sizing, risks, sequencing

**Size: L.** Touches 8 strategies, the contract dir, the descriptor, the storage trait, the
controller, the route loop, config, a new job, a new queue lane, ~17 tests. **Not XL for one reason:
the `FetchStrategy` layer already does exactly the deferred half of every one of the eight connects.**
The job is ~90 lines and there is no per-platform content code to write.

**Risks, most severe first:**
1. **`handle()` throwing on the sync driver → 500.** Mitigated by the catch-everything-expected rule
   and by P2-4/P2-7 calling `handle()` directly.
2. **Boot-time factory resolution.** If the route loop calls `connectStrategy()` instead of
   `supportsDeferredConnect()`, every scraper is baked into the registry at boot and test mocks stop
   working — fails *silently and confusingly*. Mitigated by the declared flag + P2-15.
3. **Replace-instead-of-merge.** Blanks reconnected cards; permanently empties any Bandcamp account
   with `auto_sync_latest = false` or any account with a live `refresh_etag`. Mitigated by P2-5/P2-6.
4. **YouTube's weak inline gate** — needs sign-off on remedy (a), else YouTube stays synchronous.
5. **Vimeo zero-video profiles** — connects today, `failed` under async. Needs sign-off.
6. **Pending rows on the public render path** — genuinely unresolved; see P2-17. Do not let the
   implementer guess.
7. **`SafeUrlFetcher` `scoped` in a queue worker.** Laravel's worker calls `forgetScopedInstances()`
   per job, so per-job budget state should isolate — **confirm, don't assume**, especially if Octane
   or long-lived workers are ever in play. A leaked deadline would fail the second job instantly.

**Blast radius: contained.** `ConnectResult` and `ConnectStrategy` are **not edited**. The 5
non-async implementers, the ~20 non-deferred descriptors, `PlatformRefresher`, `ScheduledRefresh`,
and `RefreshConnectionJob` all have **zero diff**. `writeConnection()` keeps its signature. Only
shared-surface edits: `writeAccountConnection()` (one defaulted param) and
`GenericPlatformController::connect()` (one branch, delegated to `ConnectResolver`).
**No migration, no SQLite mirror change.**

### Hard sequencing dependency on Phase 1

Phase 2 moves an unbudgeted multi-fetch operation onto a worker with `$timeout = 45`. Today a
`YoutubeConnect` fetch chain can consume ~96s *per hop*. **Without Phase 1's `withinBudget()`,
`ConnectFetchJob` would be killed by its own timeout mid-fetch on every slow upstream, burn all 3
attempts, and land every such connect in `unavailable`.** Phase 1's budget is what makes the job's
timeout a meaningful number rather than a coin flip.

### Two cross-phase couplings Phase 1 must be built to accommodate

**1. `ConnectResolver` survives and grows — build it with room for a second outcome type.**
It is not subsumed, for three independent reasons: (a) the budget's job changes from "protect the
FPM thread" to "bound the queue worker" — a 45s job timeout is only defensible if the work inside has
a deadline; (b) YouTube Music deliberately retains one inline network call; (c) **five connect
strategies never become async at all** — and `SoundcloudConnect` makes a real oEmbed call on the
request thread, with the budget as its only protection.

```
ConnectResolver::connect(PlatformDescriptor $d, string $input, User $u): ConnectOutcome
   deferred?  → withinBudget(fn () => $strategy->identify($input))
                → write pending row (merged) → dispatch ConnectFetchJob->afterCommit()
                → ConnectOutcome::pending($row, $partialSelection)
   otherwise  → withinBudget(fn () => $strategy->resolve($input))
                → ConnectOutcome::complete($result)     // today's path, unchanged
```
The controller's `connect()` becomes a thin translation of `ConnectOutcome` into 202/200/422.
**If Phase 1 writes `ConnectResolver` as a bare `withinBudget(resolve(...))` wrapper with no room for
a second outcome type, Phase 2 has to reopen it.**

**2. `HighlightsPicker` must expose `warmInto()` and must NOT write the row itself.**
Phase 1 specifies `recent` is warmed "at connect" — but under Phase 2 that is no longer in the
request, it is in the job. If `ConnectFetchJob` doesn't warm `recent`, the first `/recent` call after
every async connect does a live fetch, defeating Phase 1's optimisation at precisely the moment it
matters most (the user just connected and is opening the picker), until the next 12h refresh.
So `warmInto(array $payload, IntegrationConnection $row): array` returns the shaped payload without
touching the DB, and the job writes content + `recent` in its **single locked write**. If the picker
writes the row itself, the job either takes the lock twice (opening a window for a highlights save to
land between) or reaches around the picker's API. **Small interface decision now, awkward refactor later.**

*Benign interaction, no special-casing needed:* a pending row has `last_refreshed_at = null`, so the
freshness gate fails and `HighlightsPicker` falls back to live — correct. And because the pending
write merges, a reconnect keeps its existing `recent` and serves it until the job overwrites. No
stale-snapshot window.

No other conflict found. Phase 1's `PreparesHighlightItems` and the restructured `highlights()`
compose correctly with `ConnectFetchJob`'s identical lock key, suffix rule, and
fetch-outside/write-inside discipline.

---

## Sign-offs required before implementation

1. **YouTube inline handle regex** (§2b remedy (a)) — narrows what reaches the fetch, same 422
   wording. Without it, YouTube stays synchronous.
2. **Vimeo zero-video profiles** — connects today, `failed` under async.
3. **New `platform_connect` queue lane** — needs a Horizon supervisor entry.
4. **`is_active` on a pending row** — resolve via P2-17, not by assumption.

## Adjacent defect, deliberately out of scope

`AppleController::highlightsFor()` (:305-327) has the **identical** lock defect — `($cfg['fetch'])(...)`
is a live `AppleSearch` lookup inside `withConnectionLock`, and `musicRecent`/`podcastRecent`
live-fetch on GET. Not in LIFE-21..24 and it doesn't share the `HighlightsStrategy` seam (bespoke
`$cfg` array, `input` payload key, no strategy object), so folding it in would roughly double the
diff for a different shape. **Recommend a follow-up unit — logged here so it isn't lost.**

## Definition of done

All 12 boxes ticked in `TRIAGE-2-P2.md` → **101/101** → `scripts/audit/archive-done.sh` archives the
run folder (automatic, never a question).

Baseline to beat: **4355 passed, 0 failed, 144 skipped, 1 warning, 1 risky** on serial
`php artisan test`, as of merge `c9d9eb59`.
