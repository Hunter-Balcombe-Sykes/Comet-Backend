# Platform Refresh Plan 5 — Conditional Requests (ETag / If-None-Match / 304) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Status:** Draft — awaiting Josh's sign-off. **P2 + DB migration → BLOCKER GATE** (produce the plan, present it, wait for sign-off before implementing). This is the **LAST core-foundation plan** of the platform-refresh scaling initiative (Plans 1–4 have all landed on `development`).

**Goal:** Make poll-only refreshes near-free for *unchanged* resources (strategy doc §4b) by storing each connection's HTTP validator (`ETag` / `Last-Modified`) and sending it back as `If-None-Match` / `If-Modified-Since` on the next poll. A **304 Not Modified** short-circuits: no payload re-download, no payload write, **no Cloudflare cache purge, no design-preset rebuild** — just a quiet `last_refreshed_at` bump so the connection isn't re-checked until its next TTL.

**Architecture:** A **source-agnostic conditional-request spine**: two nullable columns (`refresh_etag`, `refresh_last_modified`) on `site.platform_connections`; a `FetchNotModifiedException` sentinel; a small `ConditionalContext` carrier that threads validators between a fetch strategy and its scraper **without changing the `FetchStrategy::fetch(): array` contract or any scraper's return shape** (it is an optional out-param). On a 304 the strategy raises `FetchNotModifiedException`; `PlatformRefresher::refresh()` catches it and writes the bookkeeping **quietly** (`updateQuietly()` → no `IntegrationConnectionObserver` side-effects). The spine is wired into the three fetch strategies whose upstream is a single controllable GET (`YoutubeMusicFetch`, `DeezerFetch`, `OEmbedFetch`); every other strategy is unchanged and **degrades gracefully** — no stored validator ⇒ a normal full fetch, exactly as today.

**Tech Stack:** PHP 8.2, Laravel 12, Guzzle/Laravel HTTP client (via `SafeUrlFetcher`), Pest 4 (SQLite in-memory + `array` cache + `sync` queue), existing `PlatformRegistry` / `PlatformRefresher` / `ScheduledRefresh` / `FetchStrategy` spine (all shipped in Plans 1–4).

**Source:** Strategy doc `docs/superpowers/plans/2026-07-01-platform-refresh-scaling-strategy.md` §4b (conditional requests: stored ETag/Last-Modified → If-None-Match → 304 = free, bump next check only), §5 step 2 ("the first follow-on"), §6 (schema note: `etag`/`last_modified` columns → Supabase migration), §8. Audit `audits/sweeps/2026-07-01-connection-scale-health/CONSOLIDATED.md` (#CACHE-1, #SCALE-3 context). Plans 1–4 all landed on `development`.

---

## ⚠️ Premise corrections (verified against LANDED code + live dev DB, 2026-07-03)

Read these before implementing — they change the shape of the plan and correct three over-optimistic premises in the source docs. (Convention borrowed from Plan 4.)

1. **Plans 1–4 have ALL LANDED — not just "authored."** Verified via `git log` on `development`: Plan 1 (`RefreshConnectionJob`, `dueForRefresh`, `platform-refresh` limiter), Plan 2 (`ApifyBudget`), Plan 3 (`EnrichLinkCardJob`), Plan 4 (`host_limits`, iTunes cache, OBS-1 reports, commit `7e466ef3`) are all merged. `PlatformRefresher::refresh()` already `report()`s `FetchShapeException`; `ScheduledRefresh::run()`, `FetchStrategy::fetch(): array`, and `SafeUrlFetcher::fetch()` are as-shipped. This plan diffs against that landed spine.

2. **"304 = no cache purge" is NOT free with the current observer.** The strategy doc (§4b) says a 304 costs "no payload write, no cache purge." But `IntegrationConnectionObserver::saved()` fires `purge()` → `CloudflareCachePurgeJob::dispatch(...)` on **EVERY** save (and re-resolves design presets on payload/active changes). So a naïve `$connection->update(['last_refreshed_at' => now()])` on a 304 **would still purge the edge cache** — defeating the point. This plan achieves "no purge" by writing the 304 bookkeeping with **`updateQuietly()`** (Eloquent `saveQuietly()` → `withoutEvents()`), which bypasses the observer entirely. Correct, because a 304 means *nothing changed* → there is no content to re-publish, no preset to rebuild, no R2 to reclaim. Verified `updateQuietly()` exists (`vendor/laravel/framework/.../Model.php:1118` → `saveQuietly()`).

3. **Conditional requests do NOT help the menu (#CACHE-1) — its diff-write stays Bundle C.** The strategy doc (§7) claims "conditional requests make the full menu rebuild fire far less often." Verified false: the menu is scraped via **Apify** (`MenuApifyScraper::fetchStores()`, an actor run), not an HTTP GET we control, and it is **not** a `FetchStrategy` at all (it runs through `MenuFetchJob`, outside the refresh-strategy spine). Apify actor runs expose no HTTP `ETag`/`Last-Modified`, so there is no 304 to short-circuit on. **Therefore Plan 5 does not touch the menu**, and the entire #CACHE-1 fix (diff-vs-rebuild write in `MenuFetchJob::persist()`) **stays in Bundle C** (per strategy doc §8's "kept separate" list). What Plan 5 *does* mitigate is the broader *rebuild-on-unchanged-write* class that #CACHE-1 exemplifies: for the wired poll strategies, a 304 skips the payload write **and** its downstream Cloudflare purge + `ResolveDesignPresetsJob` rebuild. Honest scope: real, indirect mitigation of the finding's *class*; the specific menu row-churn is Bundle C.

4. **Per-strategy candidate reality (verified fetch paths).** Conditional requests only correctly short-circuit when a **single** controllable GET of **one** URL fully determines the stored payload (a 304 on one of several calls can't prove the whole payload is unchanged). Verified per strategy:

   | Strategy | Upstream | Calls / fetch | Wired? | Why |
   |---|---|---|---|---|
   | **YoutubeMusicFetch** | YouTube uploads Atom feed via `SafeUrlFetcher::tryFetch` | **1** (RSS GET; channelId stored) | ✅ **YES** | Single stable feed GET; Atom feeds serve `Last-Modified`/`ETag` — highest real payoff |
   | **DeezerFetch** | `api.deezer.com/artist/{id}` via `SafeUrlFetcher::tryFetch` | **1** | ✅ **YES** | Single stable keyless JSON GET |
   | **OEmbedFetch** (spotify, soundcloud) | oEmbed JSON via `SafeUrlFetcher::tryFetch` | **1** | ✅ **YES** | Single stable oEmbed GET; one wiring covers 2 platforms |
   | YoutubeFetch | channel page **+** RSS | 2 (handle→channelId first) | ❌ later | 2 calls; needs channelId caching to become single-GET |
   | PinterestFetch | profile HTML **+** `feed.rss` | 2 | ❌ later | RSS is ideal but paired with a separate profile GET |
   | Bandcamp / Eventbrite / Humanitix (account) | HTML **+** `fetchMany` batch | 1 + N | ❌ later | multi-URL; a 304 on the index page doesn't prove the events unchanged |
   | VimeoFetch | `videos.json` **+** `info.json` | 2 | ❌ later | 2 independent API calls |
   | AppleMusic / ApplePodcast | iTunes | 1–2 | ❌ | **already app-cached** in Plan 4 (`CacheKeyGenerator::itunesResponse`); HTTP-304 would duplicate it |
   | GoogleBusinessFetch | Google Places (New) | many, **billed** | ❌ | raw `Http::`, not `SafeUrlFetcher`; multi-call; already 6-day `detailsFetchedAt` gated |

   The excluded-but-viable ones (YoutubeFetch, Pinterest, standalone Eventbrite/Humanitix, Strava, Twitch, Bandcamp-profile) all route through `SafeUrlFetcher::tryFetch`, so the spine built here makes each a small opt-in later. Task 10 documents that seam. **Every wired scraper method gains an OPTIONAL `?ConditionalContext $cond = null` last param** — all connect-path callers pass no `$cond` and are provably unaffected (verified callers: `YoutubeMusicController`, `DeezerController`, `SpotifyController`, `SoundcloudController`).

---

## Global Constraints

- **NO Laravel migration files** — a composer guard rejects them. The one schema change here is a **raw SQL file in `supabase/migrations/`** (Task 1). It is a **blocker-gate** item: present the plan and wait for sign-off before implementing.
- **New columns are NULLABLE with NO DB-level default and NO CHECK.** Opaque validator strings, echoed back verbatim (no parsing). This sidesteps the SQLite-vs-Postgres NOT NULL/CHECK drift trap (`reference_supabase_migration_drift`, and the `allow_pending_refresh_status` migration's own comment about CHECKs slipping past SQLite CI). `ADD COLUMN` of a nullable, default-less column is metadata-only (no table rewrite) → passes `guard:no-unsafe-migrations`.
- **304 status stays `'ok'`.** Do NOT introduce a new `last_refresh_status` value — the column's CHECK is `('ok','unavailable','error','pending')` and widening it would be a second, avoidable migration + drift risk. A 304 is a healthy hit; record it as `'ok'`.
- **Tests run on SQLite in-memory + `array` cache + `sync` queue** (`phpunit.xml`). Keep queries DB-agnostic (no `NULLS FIRST`/`interval`/`power()`); this plan adds no new queries. Hand-maintained SQLite test schema (`tests/Pest.php`) must gain the two columns (Task 2) — `reference_testing_information_schema_sqlite`.
- **The `FetchStrategy::fetch(): array` contract and every scraper's return shape are UNCHANGED.** Conditional state flows through an optional `ConditionalContext` out-param, so the ~20 non-wired strategies and all connect-path callers compile and behave identically (minimal blast radius).
- **No new `ShouldQueue` jobs** → `JobHygienePolicyTest` is unaffected (no `$tries`/`$backoff`/`$timeout` to add).
- **Do NOT modify `.env`** — add new keys to `.env.example` only; read via `env()` with a safe default inside `config/partna.php`.
- **Cache KEYS stay centralized in `CacheKeyGenerator`** — this plan adds no cache keys (validators live in DB columns, not cache).
- **Run `php artisan pint` on changed files before each commit**; keep commits surgical (don't let Pint churn unrelated lines — `feedback_pint_baseline_not_clean`).
- **Do NOT run `composer test` concurrently with a test-running review subagent** (`feedback_audit_fix_runbook_gotchas`).
- **SEC-1 test-timing:** creating a `youtube`/`youtube-music`/`deezer`/`spotify` connection resolves `PlatformRegistry` in the model's `saving` guard, eagerly wiring scrapers. Any scraper mock MUST be bound **before** `IntegrationConnection::create(...)` (`reference_integrationconnection_guard_test_timing`).
- **Config is the single source** of the kill-switch. Defaults to on; graceful degradation makes the wired strategies safe even if a given upstream never emits validators.

---

## File Structure

**New files:**
- `supabase/migrations/20260703000000_add_platform_connection_conditional_validators.sql` — adds `refresh_etag` + `refresh_last_modified`.
- `app/Services/Platforms/Strategies/Fetch/FetchNotModifiedException.php` — the 304 sentinel (sibling of `FetchShapeException`/`FetchUnavailableException`).
- `app/Services/Platforms/ConditionalContext.php` — the validator carrier (build conditional headers, detect 304, capture new validators, apply to the model).
- `tests/Unit/Platforms/ConditionalContextTest.php`
- `tests/Feature/Platforms/SafeUrlFetcherConditionalTest.php`
- `tests/Feature/Platforms/ConditionalRefreshTest.php` — the PlatformRefresher 304 → quiet-bookkeeping / no-purge spine test.
- `tests/Feature/Platforms/Strategies/ConditionalFetchStrategiesTest.php` — the three wired strategies' 304 behaviour.

**Modified files:**
- `app/Models/Core/Site/IntegrationConnection.php` — add the two columns to `$fillable`.
- `tests/Pest.php` — add the two columns to the `site.platform_connections` test schema (+ defensive ALTERs).
- `config/partna.php` — add `refresh.conditional` block; `.env.example` — document the env key.
- `app/Services/Http/SafeUrlFetcher.php` — surface `etag` + `lastModified` in `fetch()`'s return.
- `app/Services/Platforms/PlatformRefresher.php` — catch `FetchNotModifiedException` → `recordNotModified()` (quiet write).
- `app/Services/Platforms/Strategies/Refresh/ScheduledRefresh.php` — persist captured validators alongside the payload on a 200.
- `app/Services/Platforms/YoutubeScraper.php` — `fetchUploadsFeed()` gains an optional `?ConditionalContext`.
- `app/Services/Platforms/Strategies/Fetch/YoutubeMusicFetch.php` — send/handle validators.
- `app/Services/Platforms/DeezerApi.php` — `fetchArtist()` gains an optional `?ConditionalContext`.
- `app/Services/Platforms/Strategies/Fetch/DeezerFetch.php` — send/handle validators.
- `app/Services/Platforms/OEmbedService.php` — `resolve()` gains an optional `?ConditionalContext`.
- `app/Services/Platforms/Strategies/Fetch/OEmbedFetch.php` — send/handle validators.
- `tests/Feature/Platforms/Strategies/FeedFetchParityTest.php:83` — relax the strict `fetchUploadsFeed` arg matcher.
- `tests/Feature/Platforms/Strategies/EmbedFetchParityTest.php:78` — relax the strict `fetchArtist` arg matcher.
- `docs/frontend-contracts/` is NOT touched (no API contract change — the public payload is unchanged).

---

# Part A — the source-agnostic conditional-request spine

## Task 1: Supabase migration — validator columns  ⛔ BLOCKER GATE

**Files:**
- Create: `supabase/migrations/20260703000000_add_platform_connection_conditional_validators.sql`

**Interfaces:**
- Produces: `site.platform_connections.refresh_etag text NULL` and `.refresh_last_modified text NULL` on the dev DB (ref `glncumufgaqcmqhzwrxm`). Consumed by Tasks 2, 4, 6.

**Verified:** the live dev DB has neither column (17 columns, none for validators — `information_schema` query 2026-07-03). Prod is a paused pre-standalone DB (`reference_prod_on_pre_standalone_schema`) — this migration targets **dev only**.

- [ ] **Step 1: Write the migration**

Create `supabase/migrations/20260703000000_add_platform_connection_conditional_validators.sql`:

```sql
-- Plan 5 (platform-refresh conditional requests). Store the HTTP validators
-- (ETag / Last-Modified) returned by a connection's last successful refresh, so
-- the next poll can send If-None-Match / If-Modified-Since and short-circuit on a
-- 304 Not Modified — no payload re-download, no payload write, no cache purge.
--
-- Both NULLABLE with NO default and NO CHECK: opaque strings echoed back verbatim.
-- A connection that has never made a conditional request (or whose upstream emits
-- no validators) simply stores NULL and refreshes exactly as today (graceful
-- degradation). ADD COLUMN of a nullable, default-less column is metadata-only
-- (no table rewrite) — safe, non-locking (CONVENTIONS §2).
BEGIN;

SET LOCAL lock_timeout = '3s';
SET LOCAL statement_timeout = '30s';

ALTER TABLE site.platform_connections ADD COLUMN IF NOT EXISTS refresh_etag text;
ALTER TABLE site.platform_connections ADD COLUMN IF NOT EXISTS refresh_last_modified text;

COMMIT;

-- ROLLBACK:
-- BEGIN;
-- ALTER TABLE site.platform_connections DROP COLUMN IF EXISTS refresh_last_modified;
-- ALTER TABLE site.platform_connections DROP COLUMN IF EXISTS refresh_etag;
-- COMMIT;
```

- [ ] **Step 2: Verify the guard accepts it**

Run: `composer guard:no-unsafe-migrations`
Expected: PASS (nullable, default-less `ADD COLUMN` is metadata-only — no rewrite/lock warning).

- [ ] **Step 3: Present for sign-off, then apply to dev**

⛔ **This is the blocker gate.** Present the plan; do not apply until Josh signs off. On sign-off, apply to the **dev** ref `glncumufgaqcmqhzwrxm` via `supabase db push` (per CLAUDE.md push semantics: `link` → `db push --dry-run` → `db push`) **or** the Supabase MCP `apply_migration`. Do NOT apply to prod (paused / pre-standalone).

Verify after apply:
```sql
select column_name from information_schema.columns
where table_schema='site' and table_name='platform_connections'
  and column_name in ('refresh_etag','refresh_last_modified');
```
Expected: both rows returned.

- [ ] **Step 4: Commit**

```bash
git add supabase/migrations/20260703000000_add_platform_connection_conditional_validators.sql
git commit -m "feat(refresh): add refresh_etag/refresh_last_modified columns (conditional requests)"
```

---

## Task 2: Model fillable + SQLite test schema

**Files:**
- Modify: `app/Models/Core/Site/IntegrationConnection.php`
- Modify: `tests/Pest.php`
- Test: `tests/Feature/Platforms/ConditionalContextTest.php` (added in Task 4 — asserts the attributes are mass-assignable + persist)

**Interfaces:**
- Produces: `IntegrationConnection` accepts/persists `refresh_etag` and `refresh_last_modified` (string|null). Consumed by Tasks 4, 6.

- [ ] **Step 1: Add the columns to `$fillable`**

In `app/Models/Core/Site/IntegrationConnection.php`, append to the `$fillable` array (after `'place_id',`):

```php
        'refresh_etag',
        'refresh_last_modified',
```

(No cast — both are plain nullable strings.)

- [ ] **Step 2: Add the columns to the SQLite test schema**

In `tests/Pest.php`, inside `setupSitesTable()`, in the `CREATE TABLE IF NOT EXISTS site.platform_connections` statement, add the two columns after `place_id TEXT NULL,`:

```php
        place_id TEXT NULL,
        refresh_etag TEXT NULL,
        refresh_last_modified TEXT NULL,
```

- [ ] **Step 3: Add a defensive ALTER for pre-existing test tables**

Still in `setupSitesTable()`, immediately after the `CREATE TABLE ... site.platform_connections (...)` statement (mirroring the `moderation_state` / custom-domain / promoted-cols defensive-ALTER pattern already in the file), add:

```php
    // Plan 5 conditional-request validators — defensive ALTER for any pre-existing
    // test table (SQLite's CREATE TABLE IF NOT EXISTS won't add columns to an
    // already-created table within a run).
    foreach (['refresh_etag', 'refresh_last_modified'] as $vCol) {
        try {
            DB::connection('pgsql')->statement("ALTER TABLE site.platform_connections ADD COLUMN IF NOT EXISTS {$vCol} TEXT NULL");
        } catch (Throwable $e) {
            // already exists / unsupported — ignore
        }
    }
```

- [ ] **Step 4: Smoke-check the schema loads**

Run: `php artisan test tests/Feature/Platforms/PlatformConnectionModelTest.php`
Expected: PASS (the model + test schema still line up; new columns are additive).

- [ ] **Step 5: Commit**

```bash
php artisan pint app/Models/Core/Site/IntegrationConnection.php tests/Pest.php
git add app/Models/Core/Site/IntegrationConnection.php tests/Pest.php
git commit -m "feat(refresh): fillable + test-schema for conditional validators"
```

---

## Task 3: Config kill-switch

**Files:**
- Modify: `config/partna.php`
- Modify: `.env.example`
- Test: (asserted indirectly by Tasks 4 & 7 — the kill-switch path)

**Interfaces:**
- Produces: `config('partna.refresh.conditional.enabled')` (bool, default true). Consumed by Task 4 (`ConditionalContext::for()`).

- [ ] **Step 1: Add the `conditional` sub-block**

In `config/partna.php`, inside the existing `'refresh' => [ … ]` array, after the `'host_limits' => [ … ]` sub-array (before the `refresh` block's closing `],`):

```php
        // Plan 5: HTTP conditional requests (ETag / If-None-Match / 304) on the
        // single-GET poll strategies. When enabled, a wired fetch strategy sends the
        // connection's stored validator and short-circuits on a 304 (no payload
        // write, no cache purge). Global kill-switch: set false to force full fetches
        // everywhere if an upstream starts mis-answering conditional requests. Off ⇒
        // ConditionalContext::for() returns null and every strategy fetches exactly
        // as before (graceful degradation is per-strategy; this is the master off).
        'conditional' => [
            'enabled' => (bool) env('PARTNA_REFRESH_CONDITIONAL_ENABLED', true),
        ],
```

- [ ] **Step 2: Document the env key**

In `.env.example`, add near the other `PARTNA_REFRESH_*` keys:

```dotenv
PARTNA_REFRESH_CONDITIONAL_ENABLED=true
```

- [ ] **Step 3: Verify config loads**

Run: `php artisan config:clear && php artisan tinker --execute="var_export(config('partna.refresh.conditional.enabled'));"`
Expected: prints `true`.

- [ ] **Step 4: Commit**

```bash
php artisan pint config/partna.php
git add config/partna.php .env.example
git commit -m "feat(refresh): conditional-requests kill-switch config (Plan 5)"
```

---

## Task 4: `FetchNotModifiedException` + `ConditionalContext`

**Files:**
- Create: `app/Services/Platforms/Strategies/Fetch/FetchNotModifiedException.php`
- Create: `app/Services/Platforms/ConditionalContext.php`
- Test: `tests/Unit/Platforms/ConditionalContextTest.php`

**Interfaces:**
- Produces:
  - `FetchNotModifiedException(string $platform)` extends `RuntimeException` — the 304 signal a fetch strategy raises.
  - `ConditionalContext::for(IntegrationConnection $c): ?self` — null when the kill-switch is off (strategy then fetches unconditionally).
  - `ConditionalContext::headers(): array<string,string>` — `If-None-Match` / `If-Modified-Since` for the stored validators.
  - `ConditionalContext::handle(array $res): bool` — true on a 304 (sets `->notModified`); on a 200 captures the fresh `etag`/`lastModified` from the `SafeUrlFetcher` result.
  - `ConditionalContext::applyTo(IntegrationConnection $c): void` — writes the captured validators onto the model (dirty; persisted by `ScheduledRefresh`).
  - public `bool $notModified`.
  Consumed by Tasks 6, 7, 8, 9.

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Unit/Platforms/ConditionalContextTest.php

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\ConditionalContext;

it('returns null when the kill-switch is off', function () {
    config()->set('partna.refresh.conditional.enabled', false);
    $conn = new IntegrationConnection(['refresh_etag' => '"e"']);
    expect(ConditionalContext::for($conn))->toBeNull();
});

it('builds If-None-Match / If-Modified-Since headers from the stored validators', function () {
    config()->set('partna.refresh.conditional.enabled', true);
    $conn = new IntegrationConnection([
        'refresh_etag' => '"abc"',
        'refresh_last_modified' => 'Wed, 21 Oct 2026 07:28:00 GMT',
    ]);

    expect(ConditionalContext::for($conn)->headers())->toBe([
        'If-None-Match' => '"abc"',
        'If-Modified-Since' => 'Wed, 21 Oct 2026 07:28:00 GMT',
    ]);
});

it('sends no conditional headers when the connection has no stored validators', function () {
    config()->set('partna.refresh.conditional.enabled', true);
    expect(ConditionalContext::for(new IntegrationConnection)->headers())->toBe([]);
});

it('flags notModified on a 304 result', function () {
    config()->set('partna.refresh.conditional.enabled', true);
    $cond = ConditionalContext::for(new IntegrationConnection);

    expect($cond->handle(['status' => 304]))->toBeTrue()
        ->and($cond->notModified)->toBeTrue();
});

it('captures fresh validators on a 200 and applies them to the connection', function () {
    config()->set('partna.refresh.conditional.enabled', true);
    $conn = new IntegrationConnection(['refresh_etag' => '"old"']);
    $cond = ConditionalContext::for($conn);

    expect($cond->handle(['status' => 200, 'etag' => '"new"', 'lastModified' => 'D']))->toBeFalse()
        ->and($cond->notModified)->toBeFalse();

    $cond->applyTo($conn);
    expect($conn->refresh_etag)->toBe('"new"')
        ->and($conn->refresh_last_modified)->toBe('D');
});

it('clears validators on a 200 that carries none (self-correcting)', function () {
    config()->set('partna.refresh.conditional.enabled', true);
    $conn = new IntegrationConnection(['refresh_etag' => '"old"']);
    $cond = ConditionalContext::for($conn);

    $cond->handle(['status' => 200]); // no etag/lastModified keys
    $cond->applyTo($conn);

    expect($conn->refresh_etag)->toBeNull()
        ->and($conn->refresh_last_modified)->toBeNull();
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test tests/Unit/Platforms/ConditionalContextTest.php`
Expected: FAIL — `Class "App\Services\Platforms\ConditionalContext" not found`.

- [ ] **Step 3: Create the exception**

```php
<?php
// app/Services/Platforms/Strategies/Fetch/FetchNotModifiedException.php

namespace App\Services\Platforms\Strategies\Fetch;

use RuntimeException;

// Raised by a fetch strategy when the upstream answered 304 Not Modified to a
// conditional request (If-None-Match / If-Modified-Since) — the stored payload is
// still current. PlatformRefresher catches this and does a QUIET last_refreshed_at
// bump: no payload write, no Cloudflare purge, no design-preset rebuild (nothing
// changed). A sibling of FetchShape/FetchUnavailableException; like them it extends
// RuntimeException and is caught EXPLICITLY in PlatformRefresher::refresh(), never as
// the generic parent (a real scraper crash must not masquerade as a quiet 304).
class FetchNotModifiedException extends RuntimeException
{
    public function __construct(public readonly string $platform)
    {
        parent::__construct("not_modified: {$platform}");
    }
}
```

- [ ] **Step 4: Create the carrier**

```php
<?php
// app/Services/Platforms/ConditionalContext.php

namespace App\Services\Platforms;

use App\Models\Core\Site\IntegrationConnection;

// Carries HTTP conditional-request state (ETag / Last-Modified) between a fetch
// strategy and the scraper that performs the GET, WITHOUT changing the
// FetchStrategy::fetch(): array contract or any scraper's return shape — it is an
// OPTIONAL out-param a wired scraper accepts and mutates.
//
// Flow (source-agnostic — any single-GET fetch strategy opts in identically):
//   1. for($connection)   → the stored validators, or null when the kill-switch is
//      off (the strategy then fetches unconditionally, exactly as before).
//   2. headers()          → the If-None-Match / If-Modified-Since to send.
//   3. handle($res)       → true on a 304 (sets ->notModified so the strategy raises
//      FetchNotModifiedException); on a 200 captures the response's fresh validators.
//   4. applyTo($connection) → writes the captured validators onto the model (dirty;
//      ScheduledRefresh::run() persists them with the payload on success).
//
// Graceful degradation: no stored validator ⇒ headers() is empty ⇒ a normal 200 ⇒
// validators captured for next time. An upstream that never emits validators ⇒ we
// keep capturing null ⇒ every poll is a full fetch, exactly as today.
final class ConditionalContext
{
    /** Set true by handle() on a 304; the strategy raises FetchNotModifiedException. */
    public bool $notModified = false;

    private ?string $newEtag = null;

    private ?string $newLastModified = null;

    private function __construct(
        private readonly ?string $etag,
        private readonly ?string $lastModified,
    ) {}

    /** Null when the conditional-request feature is disabled (master kill-switch). */
    public static function for(IntegrationConnection $connection): ?self
    {
        if (! config('partna.refresh.conditional.enabled')) {
            return null;
        }

        return new self($connection->refresh_etag, $connection->refresh_last_modified);
    }

    /**
     * Conditional request headers for the stored validators (empty when none stored).
     *
     * @return array<string,string>
     */
    public function headers(): array
    {
        $headers = [];
        if ($this->etag !== null && $this->etag !== '') {
            $headers['If-None-Match'] = $this->etag;
        }
        if ($this->lastModified !== null && $this->lastModified !== '') {
            $headers['If-Modified-Since'] = $this->lastModified;
        }

        return $headers;
    }

    /**
     * Inspect a SafeUrlFetcher result. Returns true when it was a 304 (the caller
     * stops and keeps the prior payload); on a 200 captures the fresh validators.
     *
     * @param  array{status?:int, etag?:?string, lastModified?:?string}  $res
     */
    public function handle(array $res): bool
    {
        if (($res['status'] ?? null) === 304) {
            $this->notModified = true;

            return true;
        }
        if (($res['status'] ?? null) === 200) {
            $this->newEtag = $res['etag'] ?? null;
            $this->newLastModified = $res['lastModified'] ?? null;
        }

        return false;
    }

    /** Write the freshly-captured validators onto the connection (dirty; saved by ScheduledRefresh). */
    public function applyTo(IntegrationConnection $connection): void
    {
        $connection->refresh_etag = $this->newEtag;
        $connection->refresh_last_modified = $this->newLastModified;
    }
}
```

- [ ] **Step 5: Run to verify they pass**

Run: `php artisan test tests/Unit/Platforms/ConditionalContextTest.php`
Expected: PASS (6 passed).

- [ ] **Step 6: Commit**

```bash
php artisan pint app/Services/Platforms/Strategies/Fetch/FetchNotModifiedException.php app/Services/Platforms/ConditionalContext.php tests/Unit/Platforms/ConditionalContextTest.php
git add app/Services/Platforms/Strategies/Fetch/FetchNotModifiedException.php app/Services/Platforms/ConditionalContext.php tests/Unit/Platforms/ConditionalContextTest.php
git commit -m "feat(refresh): FetchNotModifiedException + ConditionalContext carrier (Plan 5)"
```

---

## Task 5: `SafeUrlFetcher` surfaces `etag` / `lastModified`

**Files:**
- Modify: `app/Services/Http/SafeUrlFetcher.php`
- Test: `tests/Feature/Platforms/SafeUrlFetcherConditionalTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `SafeUrlFetcher::fetch()` (and therefore `tryFetch()`) returns two additional keys — `etag: ?string` and `lastModified: ?string` (from the response's `ETag` / `Last-Modified` headers, null when absent). Purely additive; existing callers ignore them. A **304** already returns cleanly (verified: a 304 has no `Location`, so the manual-redirect branch at line 57 is skipped and it falls through to the terminal `return`). Consumed by Tasks 7–9 (via `ConditionalContext::handle`).

**Vendor-verified:** `http_errors => false` (`PendingRequest.php:268`) means Guzzle does not throw on a 304; `Response::header()` is `getHeaderLine()` (PSR-7, case-insensitive, `''` when absent — so `?: null` yields null); `Response::redirect()` is unused here (SafeUrlFetcher gates on a `Location` header, which a 304 lacks).

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Platforms/SafeUrlFetcherConditionalTest.php
//
// A literal public IP (8.8.8.8) bypasses assertSafe()'s DNS resolution → hermetic
// (matches the Plan 4 SafeUrlFetcher test convention). Http::fake stubs the GET.

use App\Services\Http\SafeUrlFetcher;
use Illuminate\Support\Facades\Http;

it('surfaces ETag and Last-Modified from a 200 response', function () {
    Http::fake(['8.8.8.8/*' => Http::response('ok', 200, [
        'ETag' => '"v1"',
        'Last-Modified' => 'Wed, 21 Oct 2026 07:28:00 GMT',
        'Content-Type' => 'text/html',
    ])]);

    $res = app(SafeUrlFetcher::class)->fetch('https://8.8.8.8/x');

    expect($res['status'])->toBe(200)
        ->and($res['etag'])->toBe('"v1"')
        ->and($res['lastModified'])->toBe('Wed, 21 Oct 2026 07:28:00 GMT');
});

it('returns a 304 cleanly (terminal, not treated as a redirect) with its ETag', function () {
    Http::fake(['8.8.8.8/*' => Http::response('', 304, ['ETag' => '"v1"'])]);

    $res = app(SafeUrlFetcher::class)->fetch('https://8.8.8.8/x', ['If-None-Match' => '"v1"']);

    expect($res['status'])->toBe(304)
        ->and($res['body'])->toBe('')
        ->and($res['etag'])->toBe('"v1"');
});

it('reports null validators when the response carries none', function () {
    Http::fake(['8.8.8.8/*' => Http::response('ok', 200, ['Content-Type' => 'text/plain'])]);

    $res = app(SafeUrlFetcher::class)->fetch('https://8.8.8.8/x');

    expect($res['etag'])->toBeNull()->and($res['lastModified'])->toBeNull();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/Platforms/SafeUrlFetcherConditionalTest.php`
Expected: FAIL — `$res['etag']` is an undefined array key (not surfaced yet).

- [ ] **Step 3: Surface the validators in the terminal return**

In `app/Services/Http/SafeUrlFetcher.php`, in `fetch()`, replace the terminal `return [ … ]` (the one after the redirect-follow `if`, currently lines 64–69):

```php
            return [
                'status' => $status,
                'body' => $response->body(),
                'finalUrl' => $current,
                'contentType' => (string) $response->header('Content-Type'),
                // Conditional-request validators (Plan 5). getHeaderLine() returns ''
                // when absent → null. A 304 lands here (no Location header ⇒ not a
                // followed redirect), carrying its validators back to the caller.
                'etag' => $response->header('ETag') ?: null,
                'lastModified' => $response->header('Last-Modified') ?: null,
            ];
```

Update the two `@return` docblocks in the file to the new shape:

```php
     * @return array{status:int, body:string, finalUrl:string, contentType:string, etag:?string, lastModified:?string}
```

(Change it on both `fetch()` and `tryFetch()`. `fetchMany()`'s per-item shape is left unchanged — its multi-URL callers aren't wired for conditional requests; extending it is unnecessary blast radius.)

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test tests/Feature/Platforms/SafeUrlFetcherConditionalTest.php`
Expected: PASS (3 passed).

- [ ] **Step 5: Regression-check the scraper suites that mock this shape**

Run: `php artisan test tests/Unit/Platforms/MediaApisTest.php tests/Unit/Platforms/BandcampScraperTest.php tests/Unit/Platforms/PinterestScraperTest.php`
Expected: PASS — these fake `tryFetch` with 4-key arrays (no `etag`/`lastModified`); `ConditionalContext::handle()` reads them with `?? null`, and these tests call scrapers with no `$cond`, so the new keys are simply absent and ignored.

- [ ] **Step 6: Commit**

```bash
php artisan pint app/Services/Http/SafeUrlFetcher.php tests/Feature/Platforms/SafeUrlFetcherConditionalTest.php
git add app/Services/Http/SafeUrlFetcher.php tests/Feature/Platforms/SafeUrlFetcherConditionalTest.php
git commit -m "feat(refresh): SafeUrlFetcher surfaces ETag/Last-Modified (Plan 5)"
```

---

## Task 6: `PlatformRefresher` 304 handling + `ScheduledRefresh` validator persistence

**Files:**
- Modify: `app/Services/Platforms/PlatformRefresher.php`
- Modify: `app/Services/Platforms/Strategies/Refresh/ScheduledRefresh.php`
- Test: `tests/Feature/Platforms/ConditionalRefreshTest.php`

**Interfaces:**
- Consumes: `FetchNotModifiedException` (Task 4); the wired strategies (Tasks 7–9) raise it.
- Produces:
  - `PlatformRefresher::refresh()` catches `FetchNotModifiedException` → `recordNotModified()`, which does a **quiet** write (`updateQuietly`): `last_refreshed_at = now()`, `last_refresh_status = 'ok'`, `last_refresh_error = null`, `consecutive_failures = 0`. No observer → **no Cloudflare purge, no design-preset rebuild**.
  - `ScheduledRefresh::run()` persists the connection's `refresh_etag` / `refresh_last_modified` (set by the wired strategy's `applyTo`) alongside the payload on a 200.

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Feature/Platforms/ConditionalRefreshTest.php
//
// Exercises the 304 spine end-to-end through PlatformRefresher → ScheduledRefresh →
// YoutubeMusicFetch → ConditionalContext, WITHOUT real DNS/HTTP: the YoutubeScraper
// mock simulates a 304 by flipping the passed context's notModified flag.

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\ConditionalContext;
use App\Services\Platforms\PlatformRefresher;
use App\Services\Platforms\YoutubeScraper;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    config()->set('partna.refresh.conditional.enabled', true);
});

function condUser(): User
{
    return User::create([
        'handle' => 'cond', 'handle_lc' => 'cond', 'display_name' => 'Cond',
        'account_type' => 'individual', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => 'cond@example.com',
    ]);
}

it('a 304 bumps last_refreshed_at quietly — no payload write, no cache purge', function () {
    Queue::fake();
    $user = condUser();

    // Mock BEFORE create (SEC-1 saving-guard eager-wires the scraper). The mock
    // simulates a 304 by flipping the passed ConditionalContext.
    $this->mock(YoutubeScraper::class, function ($m) {
        $m->shouldReceive('fetchUploadsFeed')->andReturnUsing(function ($channelId, $limit, ?ConditionalContext $cond) {
            $cond->notModified = true; // 304 Not Modified

            return null;
        });
    });

    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'youtube-music', 'resource_id' => 'youtube-music',
        'payload' => ['channelId' => 'UC123', 'name' => 'Cached Artist', 'items' => [['videoId' => 'v1']]],
        'last_refreshed_at' => now()->subWeek(),
        'last_refresh_status' => 'ok',
        'consecutive_failures' => 2,
        'refresh_etag' => '"stored"',
    ]);

    app(PlatformRefresher::class)->refresh($conn);

    $conn->refresh();
    expect($conn->last_refresh_status)->toBe('ok')
        ->and($conn->consecutive_failures)->toBe(0)                 // healthy hit → reset
        ->and($conn->payload['name'])->toBe('Cached Artist')       // payload untouched
        ->and($conn->last_refreshed_at->gt(now()->subMinute()))->toBeTrue(); // bumped

    Queue::assertNotPushed(CloudflareCachePurgeJob::class);         // 304 ⇒ no purge
});

it('a 200 persists freshly-captured validators alongside the payload', function () {
    $user = condUser();

    // The mock captures a fresh ETag onto the context (as the real handle() would on
    // a 200) and returns a normal feed.
    $this->mock(YoutubeScraper::class, function ($m) {
        $m->shouldReceive('fetchUploadsFeed')->andReturnUsing(function ($channelId, $limit, ?ConditionalContext $cond) {
            $cond->handle(['status' => 200, 'etag' => '"fresh"', 'lastModified' => 'D']);

            return ['title' => 'New Name', 'videos' => [
                ['videoId' => 'v9', 'name' => 'Song', 'thumbnail' => 't', 'link' => 'l', 'date' => null],
            ]];
        });
    });

    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'youtube-music', 'resource_id' => 'youtube-music',
        'payload' => ['channelId' => 'UC123', 'name' => 'Old'],
        'last_refreshed_at' => now()->subWeek(),
        'refresh_etag' => '"stored"',
    ]);

    app(PlatformRefresher::class)->refresh($conn);

    $conn->refresh();
    expect($conn->last_refresh_status)->toBe('ok')
        ->and($conn->refresh_etag)->toBe('"fresh"')
        ->and($conn->refresh_last_modified)->toBe('D');
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test tests/Feature/Platforms/ConditionalRefreshTest.php`
Expected: FAIL — the 304 case: `FetchNotModifiedException` is uncaught (bubbles out of `refresh()`); the 200 case: `refresh_etag` not persisted (ScheduledRefresh doesn't write it yet).

- [ ] **Step 3: Catch `FetchNotModifiedException` in `PlatformRefresher`**

In `app/Services/Platforms/PlatformRefresher.php`, add the import at the top (with the other `Strategies\Fetch` imports):

```php
use App\Services\Platforms\Strategies\Fetch\FetchNotModifiedException;
```

In `refresh()`, add the `FetchNotModifiedException` catch **first** in the try/catch (before `FetchShapeException`):

```php
        try {
            return $strategy->run($connection);
        } catch (FetchNotModifiedException $e) {
            return $this->recordNotModified($connection);
        } catch (FetchShapeException $e) {
```

Add the `recordNotModified()` method (next to `recordFailure()`):

```php
    // 304 Not Modified: upstream confirmed the stored payload is still current. Bump
    // last_refreshed_at so the connection isn't re-checked until its next TTL, and
    // clear the failure counter (a 304 IS a healthy hit). Write QUIETLY: nothing
    // changed, so we must NOT fire IntegrationConnectionObserver — its saved() purges
    // the sitepage edge cache and re-resolves design presets on EVERY save.
    // updateQuietly() bypasses the observer, which is exactly right when there is no
    // content change to publish (the whole point of the 304 short-circuit).
    private function recordNotModified(IntegrationConnection $connection): IntegrationConnection
    {
        $connection->updateQuietly([
            'last_refreshed_at' => now(),
            'last_refresh_status' => 'ok',
            'last_refresh_error' => null,
            'consecutive_failures' => 0,
        ]);

        return $connection;
    }
```

- [ ] **Step 4: Persist validators in `ScheduledRefresh::run()`**

In `app/Services/Platforms/Strategies/Refresh/ScheduledRefresh.php`, extend the `update()` in `run()` to persist the validators the wired strategy captured onto the connection (via `ConditionalContext::applyTo`):

```php
    public function run(IntegrationConnection $connection): IntegrationConnection
    {
        $next = $this->fetch->fetch($connection);

        $connection->update([
            'payload' => $next,
            'last_refreshed_at' => now(),
            'last_refresh_status' => 'ok',
            'last_refresh_error' => null,
            'consecutive_failures' => 0,
            // Conditional-request validators (Plan 5). A wired fetch strategy set these
            // via ConditionalContext::applyTo() before returning; a non-wired strategy
            // leaves them at their stored value, so this is a harmless no-op write there.
            'refresh_etag' => $connection->refresh_etag,
            'refresh_last_modified' => $connection->refresh_last_modified,
        ]);

        return $connection;
    }
```

- [ ] **Step 5: Run to verify they pass**

Run: `php artisan test tests/Feature/Platforms/ConditionalRefreshTest.php`
Expected: PASS (2 passed).

- [ ] **Step 6: Regression-check the refresher/strategy suite**

Run: `php artisan test tests/Feature/Platforms/Strategies/`
Expected: PASS — the parity suites still pass (the strict-mock relaxations land in Tasks 7–8; if run before those, expect the two `->with(...)` failures there and nowhere else).

- [ ] **Step 7: Commit**

```bash
php artisan pint app/Services/Platforms/PlatformRefresher.php app/Services/Platforms/Strategies/Refresh/ScheduledRefresh.php tests/Feature/Platforms/ConditionalRefreshTest.php
git add app/Services/Platforms/PlatformRefresher.php app/Services/Platforms/Strategies/Refresh/ScheduledRefresh.php tests/Feature/Platforms/ConditionalRefreshTest.php
git commit -m "feat(refresh): 304 quiet-bookkeeping + validator persistence (Plan 5)"
```

---

# Part B — wire the confirmed single-GET candidates

Each wiring is the same three-line pattern: `$cond = ConditionalContext::for($connection)`; pass it into the scraper's GET; check `$cond?->notModified` (raise `FetchNotModifiedException`) **before** the existing null/empty check; `$cond?->applyTo($connection)` on success. The scraper method gains an optional `?ConditionalContext $cond = null` (default null preserves every connect-path caller).

## Task 7: `YoutubeMusicFetch` (RSS/Atom feed)

**Files:**
- Modify: `app/Services/Platforms/YoutubeScraper.php`
- Modify: `app/Services/Platforms/Strategies/Fetch/YoutubeMusicFetch.php`
- Modify: `tests/Feature/Platforms/Strategies/FeedFetchParityTest.php` (relax one strict mock)
- Test: `tests/Feature/Platforms/Strategies/ConditionalFetchStrategiesTest.php`

**Interfaces:**
- Consumes: `ConditionalContext` (Task 4); `SafeUrlFetcher::fetch()` validators (Task 5).
- Produces: `YoutubeScraper::fetchUploadsFeed(string $channelId, int $limit = 15, ?ConditionalContext $cond = null): ?array` — sends `$cond?->headers()`, returns `null` after flagging `$cond->notModified` on a 304, captures validators on a 200. `YoutubeMusicFetch::fetch()` raises `FetchNotModifiedException('youtube-music')` on a 304.

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Feature/Platforms/Strategies/ConditionalFetchStrategiesTest.php
//
// 304 behaviour for the three wired strategies. Scrapers are mocked (hermetic — no
// DNS/HTTP): the mock flips the passed ConditionalContext to simulate a 304, and the
// strategy must translate that into a FetchNotModifiedException.

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\ConditionalContext;
use App\Services\Platforms\DeezerApi;
use App\Services\Platforms\OEmbedService;
use App\Services\Platforms\Strategies\Fetch\DeezerFetch;
use App\Services\Platforms\Strategies\Fetch\FetchNotModifiedException;
use App\Services\Platforms\Strategies\Fetch\OEmbedFetch;
use App\Services\Platforms\Strategies\Fetch\YoutubeMusicFetch;
use App\Services\Platforms\YoutubeScraper;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    config()->set('partna.refresh.conditional.enabled', true);
});

function condStratUser(string $h): User
{
    return User::create([
        'handle' => $h, 'handle_lc' => $h, 'display_name' => $h,
        'account_type' => 'individual', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => $h.'@example.com',
    ]);
}

it('YoutubeMusicFetch raises FetchNotModifiedException on a 304', function () {
    $this->mock(YoutubeScraper::class, function ($m) {
        $m->shouldReceive('fetchUploadsFeed')->andReturnUsing(function ($channelId, $limit, ?ConditionalContext $cond) {
            $cond->notModified = true;

            return null;
        });
    });

    $conn = IntegrationConnection::create([
        'user_id' => condStratUser('ym304')->id, 'platform' => 'youtube-music', 'resource_id' => 'youtube-music',
        'payload' => ['channelId' => 'UC123'], 'refresh_etag' => '"e"',
    ]);

    expect(fn () => (new YoutubeMusicFetch(app(YoutubeScraper::class)))->fetch($conn))
        ->toThrow(FetchNotModifiedException::class);
});

it('DeezerFetch raises FetchNotModifiedException on a 304', function () {
    $this->mock(DeezerApi::class, function ($m) {
        $m->shouldReceive('fetchArtist')->andReturnUsing(function ($id, ?ConditionalContext $cond) {
            $cond->notModified = true;

            return null;
        });
    });

    $conn = IntegrationConnection::create([
        'user_id' => condStratUser('dz304')->id, 'platform' => 'deezer', 'resource_id' => 'deezer',
        'payload' => ['artistId' => '123'], 'refresh_etag' => '"e"',
    ]);

    expect(fn () => (new DeezerFetch(app(DeezerApi::class)))->fetch($conn))
        ->toThrow(FetchNotModifiedException::class);
});

it('OEmbedFetch raises FetchNotModifiedException on a 304', function () {
    $this->mock(OEmbedService::class, function ($m) {
        $m->shouldReceive('resolve')->andReturnUsing(function ($endpoint, ?ConditionalContext $cond) {
            $cond->notModified = true;

            return null;
        });
    });

    $conn = IntegrationConnection::create([
        'user_id' => condStratUser('oe304')->id, 'platform' => 'spotify', 'resource_id' => 'spotify',
        'payload' => ['link' => 'https://open.spotify.com/artist/x'], 'refresh_etag' => '"e"',
    ]);

    $strategy = new OEmbedFetch(app(OEmbedService::class), fn (string $l) => 'https://open.spotify.com/oembed?url='.$l, 'spotify');

    expect(fn () => $strategy->fetch($conn))->toThrow(FetchNotModifiedException::class);
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test tests/Feature/Platforms/Strategies/ConditionalFetchStrategiesTest.php`
Expected: FAIL — the mocks pass a 3rd/2nd `$cond` arg the current method signatures don't accept (`ArgumentCountError` / Mockery signature mismatch), and the strategies don't raise `FetchNotModifiedException`.

- [ ] **Step 3: Add the conditional param to `fetchUploadsFeed`**

In `app/Services/Platforms/YoutubeScraper.php` (no import needed — `ConditionalContext` is in the same `App\Services\Platforms` namespace), change the `fetchUploadsFeed` signature and its fetch/guard block (lines 76–89):

```php
    public function fetchUploadsFeed(string $channelId, int $limit = 15, ?ConditionalContext $cond = null): ?array
    {
        $headers = array_merge(['User-Agent' => self::USER_AGENT], $cond?->headers() ?? []);

        // Use the channel's uploads-playlist feed (UU…) rather than the channel
        // feed (UC…). On a fresh upload the channel_id feed can lag hours — or
        // never populate at all for new / low-volume channels — whereas the
        // uploads-playlist feed updates within minutes. The uploads playlist id
        // is the channel id with its "UC" prefix swapped to "UU".
        $uploadsPlaylistId = 'UU'.substr($channelId, 2);
        $rss = $this->fetcher->tryFetch('https://www.youtube.com/feeds/videos.xml?playlist_id='.$uploadsPlaylistId, $headers);
        if ($rss === null) {
            return null;
        }
        // 304 Not Modified → let the caller (a fetch strategy) short-circuit. On a
        // 200, capture the fresh ETag/Last-Modified for next time.
        if ($cond !== null && $cond->handle($rss)) {
            return null;
        }
        if ($rss['status'] !== 200) {
            return null;
        }
```

(Everything below — the head/entry parsing — is unchanged.)

- [ ] **Step 4: Wire `YoutubeMusicFetch`**

Replace the body of `app/Services/Platforms/Strategies/Fetch/YoutubeMusicFetch.php`'s `fetch()` (add the import `use App\Services\Platforms\ConditionalContext;`):

```php
    public function fetch(IntegrationConnection $connection): array
    {
        $payload = $connection->payload ?? [];

        $channelId = $payload['channelId'] ?? null;
        if (! $channelId) {
            throw new FetchShapeException('missing_key: channelId');
        }

        $cond = ConditionalContext::for($connection);
        $feed = $this->youtube->fetchUploadsFeed((string) $channelId, 12, $cond);

        // 304 must be checked BEFORE the empty-feed guard: on a 304 the scraper
        // returns null, which would otherwise read as "unavailable" and wrongly
        // increment failures. A 304 is a healthy "nothing changed".
        if ($cond?->notModified) {
            throw new FetchNotModifiedException('youtube-music');
        }
        if ($feed === null || $feed['videos'] === []) {
            throw new FetchUnavailableException('youtube_music_no_releases');
        }
        $cond?->applyTo($connection);

        $items = YoutubeMusicController::musicItems($feed['videos']);

        return [
            ...$payload,
            'name' => $feed['title'] !== null
                ? preg_replace('/\s+-\s+Topic$/', '', $feed['title'])
                : ($payload['name'] ?? null),
            'thumbnail' => $items[0]['thumbnail'] ?? ($payload['thumbnail'] ?? null),
            'latest' => $items[0],
            'items' => array_slice($items, 0, 12),
        ];
    }
```

- [ ] **Step 5: Relax the strict parity mock (required — else it breaks)**

`tests/Feature/Platforms/Strategies/FeedFetchParityTest.php:83` mocks `fetchUploadsFeed` with a strict **2-arg** matcher `->with('UC123', 12)`. `YoutubeMusicFetch` now calls it with a 3rd `$cond` arg, so the matcher no longer matches. Change line 83:

```php
    $this->mock(YoutubeScraper::class, fn ($m) => $m->shouldReceive('fetchUploadsFeed')->with('UC123', 12, \Mockery::any())
        ->andReturn(['title' => 'Artist - Topic', 'videos' => $videos]));
```

(The parity assertion is unaffected: validators live in separate columns, not the payload.)

- [ ] **Step 6: Run to verify they pass**

Run: `php artisan test tests/Feature/Platforms/Strategies/ConditionalFetchStrategiesTest.php tests/Feature/Platforms/Strategies/FeedFetchParityTest.php`
Expected: the YouTube-Music 304 case PASSES; `FeedFetchParityTest` PASSES (the other two 304 cases still fail until Tasks 8–9).

- [ ] **Step 7: Commit**

```bash
php artisan pint app/Services/Platforms/YoutubeScraper.php app/Services/Platforms/Strategies/Fetch/YoutubeMusicFetch.php tests/Feature/Platforms/Strategies/FeedFetchParityTest.php tests/Feature/Platforms/Strategies/ConditionalFetchStrategiesTest.php
git add app/Services/Platforms/YoutubeScraper.php app/Services/Platforms/Strategies/Fetch/YoutubeMusicFetch.php tests/Feature/Platforms/Strategies/FeedFetchParityTest.php tests/Feature/Platforms/Strategies/ConditionalFetchStrategiesTest.php
git commit -m "feat(refresh): conditional requests on YoutubeMusicFetch (RSS/304) (Plan 5)"
```

---

## Task 8: `DeezerFetch` (keyless JSON API)

**Files:**
- Modify: `app/Services/Platforms/DeezerApi.php`
- Modify: `app/Services/Platforms/Strategies/Fetch/DeezerFetch.php`
- Modify: `tests/Feature/Platforms/Strategies/EmbedFetchParityTest.php` (relax one strict mock)
- Test: `tests/Feature/Platforms/Strategies/ConditionalFetchStrategiesTest.php` (the DeezerFetch case, added in Task 7)

**Interfaces:**
- Produces: `DeezerApi::fetchArtist(string $id, ?ConditionalContext $cond = null): ?array`; `DeezerFetch::fetch()` raises `FetchNotModifiedException('deezer')` on a 304.

- [ ] **Step 1: Add the conditional param to `fetchArtist`**

In `app/Services/Platforms/DeezerApi.php` (no import needed — `ConditionalContext` is in the same `App\Services\Platforms` namespace), change `fetchArtist()`'s signature and its fetch/guard block (lines 30–35):

```php
    public function fetchArtist(string $id, ?ConditionalContext $cond = null): ?array
    {
        $res = $this->fetcher->tryFetch("https://api.deezer.com/artist/{$id}", array_merge(
            ['User-Agent' => self::USER_AGENT, 'Accept' => 'application/json'],
            $cond?->headers() ?? [],
        ));
        if ($res === null) {
            return null;
        }
        if ($cond !== null && $cond->handle($res)) {
            return null; // 304 Not Modified — caller short-circuits
        }
        if ($res['status'] !== 200) {
            return null;
        }
```

(The `json_decode` + shaping below is unchanged.)

- [ ] **Step 2: Wire `DeezerFetch`**

Replace `app/Services/Platforms/Strategies/Fetch/DeezerFetch.php`'s `fetch()` body (add `use App\Services\Platforms\ConditionalContext;`):

```php
    public function fetch(IntegrationConnection $connection): array
    {
        $payload = $connection->payload ?? [];

        $id = $payload['artistId'] ?? null;
        if (! $id) {
            throw new FetchShapeException('missing_key: artistId');
        }

        $cond = ConditionalContext::for($connection);
        $artist = $this->deezer->fetchArtist((string) $id, $cond);

        if ($cond?->notModified) {
            throw new FetchNotModifiedException('deezer');
        }
        if ($artist === null) {
            throw new FetchUnavailableException('deezer_fetch_failed');
        }
        $cond?->applyTo($connection);

        return [
            ...$payload,
            'name' => $artist['name'] ?? ($payload['name'] ?? null),
            'thumbnail' => $artist['thumbnail'] ?? ($payload['thumbnail'] ?? null),
            'embedUrl' => DeezerApi::embedUrlForArtist((string) $id),
        ];
    }
```

- [ ] **Step 3: Relax the strict parity mock (required)**

`tests/Feature/Platforms/Strategies/EmbedFetchParityTest.php:78` mocks `fetchArtist` with a strict `->with('123')`. `DeezerFetch` now calls it with a 2nd `$cond` arg. Change line 78:

```php
        $m->shouldReceive('fetchArtist')->with('123', \Mockery::any())->andReturn([
```

- [ ] **Step 4: Run to verify**

Run: `php artisan test tests/Feature/Platforms/Strategies/ConditionalFetchStrategiesTest.php tests/Feature/Platforms/Strategies/EmbedFetchParityTest.php`
Expected: the DeezerFetch 304 case PASSES; `EmbedFetchParityTest` PASSES (OEmbed 304 case still fails until Task 9).

- [ ] **Step 5: Commit**

```bash
php artisan pint app/Services/Platforms/DeezerApi.php app/Services/Platforms/Strategies/Fetch/DeezerFetch.php tests/Feature/Platforms/Strategies/EmbedFetchParityTest.php
git add app/Services/Platforms/DeezerApi.php app/Services/Platforms/Strategies/Fetch/DeezerFetch.php tests/Feature/Platforms/Strategies/EmbedFetchParityTest.php
git commit -m "feat(refresh): conditional requests on DeezerFetch (JSON API/304) (Plan 5)"
```

---

## Task 9: `OEmbedFetch` (Spotify / SoundCloud)

**Files:**
- Modify: `app/Services/Platforms/OEmbedService.php`
- Modify: `app/Services/Platforms/Strategies/Fetch/OEmbedFetch.php`
- Test: `tests/Feature/Platforms/Strategies/ConditionalFetchStrategiesTest.php` (the OEmbedFetch case, added in Task 7)

**Interfaces:**
- Produces: `OEmbedService::resolve(string $oembedEndpoint, ?ConditionalContext $cond = null): ?array`; `OEmbedFetch::fetch()` raises `FetchNotModifiedException($platform)` on a 304. One wiring covers both `spotify` and `soundcloud` (both use `OEmbedFetch`).

**No parity-mock change needed:** every existing `resolve` mock uses `->andReturn(...)` with no `->with()` argument matcher (verified: `EmbedFetchParityTest:29,63`, `IntegrationsV2/V3ConnectionTest`), so a 2nd arg matches. Connect callers (`SpotifyController:50`, `SoundcloudController:47`) pass one arg → the new default keeps them valid.

- [ ] **Step 1: Add the conditional param to `resolve`**

In `app/Services/Platforms/OEmbedService.php` (no import needed — `ConditionalContext` is in the same `App\Services\Platforms` namespace), change `resolve()`'s signature and its fetch/guard block (lines 21–26):

```php
    public function resolve(string $oembedEndpoint, ?ConditionalContext $cond = null): ?array
    {
        $res = $this->fetcher->tryFetch($oembedEndpoint, array_merge(
            ['User-Agent' => self::USER_AGENT, 'Accept' => 'application/json'],
            $cond?->headers() ?? [],
        ));
        if ($res === null) {
            return null;
        }
        if ($cond !== null && $cond->handle($res)) {
            return null; // 304 Not Modified — caller short-circuits
        }
        if ($res['status'] !== 200) {
            return null;
        }
```

(The `json_decode` + embed-url extraction below is unchanged.)

- [ ] **Step 2: Wire `OEmbedFetch`**

Replace `app/Services/Platforms/Strategies/Fetch/OEmbedFetch.php`'s `fetch()` body (add `use App\Services\Platforms\ConditionalContext;`):

```php
    public function fetch(IntegrationConnection $connection): array
    {
        $payload = $connection->payload ?? [];

        $link = $payload['link'] ?? $payload['url'] ?? null;
        if (! $link) {
            throw new FetchShapeException('missing_key: link');
        }

        $cond = ConditionalContext::for($connection);
        $resolved = $this->oembed->resolve(($this->endpointFor)($link), $cond);

        if ($cond?->notModified) {
            throw new FetchNotModifiedException($this->platform);
        }
        if ($resolved === null) {
            throw new FetchUnavailableException("{$this->platform}_oembed_failed");
        }
        $cond?->applyTo($connection);

        return [
            ...$payload,
            'name' => $resolved['name'] ?? ($payload['name'] ?? null),
            'thumbnail' => $resolved['thumbnail'] ?? ($payload['thumbnail'] ?? null),
            'embedUrl' => $resolved['embedUrl'] ?? ($payload['embedUrl'] ?? null),
        ];
    }
```

- [ ] **Step 3: Run to verify the full conditional-strategy suite passes**

Run: `php artisan test tests/Feature/Platforms/Strategies/ConditionalFetchStrategiesTest.php tests/Feature/Platforms/Strategies/EmbedFetchParityTest.php tests/Feature/Platforms/Strategies/FeedFetchParityTest.php`
Expected: PASS (all three 304 cases + both parity suites).

- [ ] **Step 4: Commit**

```bash
php artisan pint app/Services/Platforms/OEmbedService.php app/Services/Platforms/Strategies/Fetch/OEmbedFetch.php
git add app/Services/Platforms/OEmbedService.php app/Services/Platforms/Strategies/Fetch/OEmbedFetch.php
git commit -m "feat(refresh): conditional requests on OEmbedFetch (Spotify/SoundCloud/304) (Plan 5)"
```

---

## Task 10: Opt-in doc for the remaining candidates + full-suite gate

**Files:**
- Modify: `app/Services/Platforms/ConditionalContext.php` (append an opt-in doc comment — no behaviour change)

**Interfaces:**
- Produces: an in-code note telling the next engineer exactly how to opt a further single-GET strategy into conditional requests, and which candidates remain.

- [ ] **Step 1: Document the opt-in seam**

In `app/Services/Platforms/ConditionalContext.php`, append this to the class-level docblock (after the "Graceful degradation" paragraph):

```php
// Opting in a further single-GET strategy (no spine change needed):
//   1. Give the scraper's GET method an optional `?ConditionalContext $cond = null`
//      last param; merge `$cond?->headers() ?? []` into the request headers; after
//      the fetch, `if ($cond !== null && $cond->handle($res)) return null;` before
//      the existing status/null guard.
//   2. In the strategy: `$cond = ConditionalContext::for($connection);` pass it in;
//      `if ($cond?->notModified) throw new FetchNotModifiedException($platform);`
//      BEFORE the empty/null guard; `$cond?->applyTo($connection);` on success.
// Ready candidates (all route through SafeUrlFetcher::tryFetch), deferred only to
// bound this plan's blast radius — NOT because the upstream is unsuitable:
//   • TwitchFetch (single HTML GET)                     — 1 call
//   • StravaFetch (club page; ignore the optional image probe on a 304)
//   • EventbriteFetch / HumanitixFetch — the standalone `kind==='event'` path only
//     (the organiser/account path is multi-URL via fetchMany — NOT a candidate)
//   • YoutubeFetch  — needs channelId cached first (today it resolves handle→id via
//     a prior channel-page GET, making it a 2-call fetch)
//   • PinterestFetch — its feed.rss GET is ideal but is paired with a profile GET
// NOT candidates: iTunes (already app-cached, Plan 4), Google Places (billed, raw
// Http::, 6-day gated), the menu (Apify actor — no HTTP validator; #CACHE-1 stays
// Bundle C), and any strategy whose payload needs >1 upstream call.
```

- [ ] **Step 2: Full suite (namespace/relocation safety net)**

Run: `composer test`
Expected: PASS — full suite green in the **main checkout** (not a filtered subset, not a worktree; `feedback_namespace_relocation_short_refs`, `reference_worktree_feature_tests_broken`). Do NOT run this concurrently with a review subagent (`feedback_audit_fix_runbook_gotchas`).

- [ ] **Step 3: Commit**

```bash
php artisan pint app/Services/Platforms/ConditionalContext.php
git add app/Services/Platforms/ConditionalContext.php
git commit -m "docs(refresh): document the conditional-request opt-in seam (Plan 5)"
```

---

## Self-Review

**1. Spec coverage (strategy doc §4b / §5 step 2 / §6 / §8):**
- stored `ETag`/`Last-Modified` → columns → **Task 1** (migration) + **Task 2** (model/schema) ✓
- send `If-None-Match` / `If-Modified-Since` → **Task 4** (`ConditionalContext::headers`) + **Tasks 7–9** (wired into the GET) ✓
- 304 = free: no payload write, no cache purge, bump next check only → **Task 6** (`recordNotModified` via `updateQuietly`) ✓ (premise-correction #2: `updateQuietly` is what actually makes it purge-free)
- source-agnostic spine, threaded through `PlatformRefresher` / `FetchStrategy` → `ConditionalContext` out-param keeps the `fetch(): array` contract + scraper return shapes unchanged (**Task 4**), 304 raised as an exception caught in `PlatformRefresher` (**Task 6**) ✓
- "no validator ⇒ full fetch, exactly as today" (premise #4) → `for()` returns null when disabled; `headers()` empty when unstored; non-wired strategies untouched ✓
- schema note (§6) → Task 1 raw SQL migration (blocker gate) ✓
- #CACHE-1 → premise-correction #3: the ETag/304 short-circuit reduces the rebuild-on-unchanged-write *class* for wired strategies; the menu-specific diff-write **stays Bundle C** (stated, not silently claimed) ✓

**2. Placeholder scan:** every step carries complete code + exact run/expected lines. No "TBD"/"similar to Task N"/"add error handling". The three Part-B wirings are each written in full (not cross-referenced) despite sharing a pattern. ✓

**3. Type consistency:**
- `ConditionalContext::for(IntegrationConnection): ?self`, `headers(): array`, `handle(array): bool`, `applyTo(IntegrationConnection): void`, public `bool $notModified` — used identically in Tasks 6, 7, 8, 9. ✓
- `FetchNotModifiedException(string $platform)` — constructed with the platform key in all three strategies; caught in `PlatformRefresher::refresh()`. ✓
- `SafeUrlFetcher::fetch()` return keys `etag`/`lastModified` (Task 5) are exactly the keys `ConditionalContext::handle()` reads (Task 4) and the scrapers pass through unmodified. ✓
- scraper signatures `fetchUploadsFeed(..., ?ConditionalContext $cond = null)`, `fetchArtist(string, ?ConditionalContext $cond = null)`, `resolve(string, ?ConditionalContext $cond = null)` — the strategies call them with `$cond`; connect callers pass one/two positional args and rely on the default. ✓
- config key `partna.refresh.conditional.enabled` — defined Task 3, read Task 4. ✓

**4. Adversarial verification (against landed code + Laravel/Guzzle vendor):**
- **304 surfaces cleanly:** `SafeUrlFetcher::fetch()` follows redirects only when a `Location` header is present (line 57); a 304 has none → falls to the terminal return. `http_errors => false` (Guzzle) → no throw on 304. `Response::header()` = `getHeaderLine()` (PSR-7, case-insensitive, `''`→`?:null`). Verified in vendor.
- **`updateQuietly` bypasses the observer:** `updateQuietly()` → `saveQuietly()` → `withoutEvents()` (vendor `Model.php:1118/1204`), so `saved()`/`updated()` don't fire → no `CloudflareCachePurgeJob`, no `ResolveDesignPresetsJob`, no R2 cleanup. It also skips the model's `saving` guard, which is safe: the 304 write touches neither `user_id` nor `platform` (the only things that guard checks).
- **Strict-mock breakages caught & fixed:** `FeedFetchParityTest:83` (`->with('UC123', 12)`) and `EmbedFetchParityTest:78` (`->with('123')`) are the only strict arg-matchers on the three wired methods — both relaxed with `\Mockery::any()` (Tasks 7, 8). All other `resolve`/`fetchArtist`/`fetchUploadsFeed` mocks use no `->with()` → a new arg matches. Scraper unit tests call the methods with no `$cond` (fully `$cond !== null`-guarded) → unaffected.
- **No return-shape assertion breaks:** no test asserts `SafeUrlFetcher::fetch()`'s exact array via `toBe`/`toEqual`; the +2 keys are additive. Scraper tests provide 4-key fakes as *inputs* to a mocked fetcher; `handle()` reads the new keys with `?? null`.
- **200 validator persistence:** the wired strategy's `applyTo()` sets the model dirty; `ScheduledRefresh::run()` writes them explicitly. On a 304 the exception fires inside `fetch()` → `run()`'s `update()` never executes → no payload write (correct).
- **Ordering:** every wired strategy checks `$cond?->notModified` **before** its null/empty guard, so a 304 (scraper returns null) becomes `FetchNotModifiedException`, never a false `FetchUnavailableException` (which would wrongly increment failures).

**Remaining soft spots (honest):**
- **Stale validator after a URL change.** If a user reconnects and the stored payload URL changes, an old `refresh_etag` could still be sent. This is **self-correcting**: ETags are server-scoped opaque tokens, so a different URL returns a 200 with a fresh ETag (captured), never a false 304. Not worth adding connect-path validator-clearing (broad blast radius) for a self-healing edge case; documented, not fixed.
- **Real-world 304 rate is upstream-dependent.** Whether YouTube/Deezer/Spotify/SoundCloud actually emit stable validators isn't guaranteed; graceful degradation means the worst case is "full fetch, exactly as today." The RSS/Atom feed (YouTube Music) is the most likely to pay off; the others are correct-but-maybe-no-savings until observed.
- **Scope is 3 strategies (4 platforms).** Deliberately bounded to keep the migration-carrying plan reviewable; Task 10 documents the one-strategy-at-a-time opt-in for the rest.

---

## Execution Handoff

Plan complete and saved to `docs/superpowers/plans/2026-07-03-platform-refresh-plan-5-conditional-requests.md`.

⛔ **Blocker gate: this plan carries a DB migration (Task 1).** Present it; **wait for Josh's sign-off before implementing.** On sign-off, Task 1 applies to the **dev** Supabase ref `glncumufgaqcmqhzwrxm` only (prod is paused / pre-standalone). Two execution options once approved:

**1. Subagent-Driven (recommended)** — a fresh subagent per task, two-stage review between tasks (implement Sonnet → independent Sonnet review), fast iteration. Matches the audit fix-flow. Tasks are ordered so the migration + spine (Tasks 1–6) land before the three wirings (7–9); Part B tasks are independently reviewable.

**2. Inline Execution** — execute tasks in this session with checkpoints for review.

**Which approach?**

---

## Initiative status — the core foundation is COMPLETE after this plan

**Plan 5 is the LAST core-foundation plan** of the platform-refresh scaling initiative. Plans 1–5 together deliver the full source-agnostic spine (SCALE-1), Apify cost control (SCALE-2/4), async connect (JOB-1), inner-burst limits + observability (SCALE-3/OBS-1), and conditional requests (this plan). No "Plan 6" follows. The remaining work is **optional / gated**, to be picked up via the normal `execute audit` flow when its trigger arrives:

- **Webhooks for push-capable platforms** (YouTube / Instagram / Eventbrite / Strava) — **gated on Meta API access (~a month out)**. Slots into the existing, deliberately-empty `WebhookRefresh` seam (`Strategies/Contracts/WebhookRefresh.php`) with **no spine change** — the "any trigger → one job" design (Plan 1) already accommodates a push trigger.
- **Adaptive polling intervals** — **only if the staleness/backlog alarm** (`integrations:refresh-backlog`, Plan 1) shows the fleet outgrowing capacity. Error-backoff off `consecutive_failures`; check often-changing platforms more, rarely-changing ones less (per-descriptor TTL already exists).
- **Separate hygiene bundles** (independent; `execute audit audits/sweeps/2026-07-01-connection-scale-health/CONSOLIDATED.md`):
  - **Bundle C** — #CCH-1 (lock on `cache_locks`) / #CCH-2 (YouTube thumbnail single-flight + stale-TTL) / **#CACHE-1** (the menu diff-vs-rebuild write — *this* is the part conditional requests do NOT cover).
  - **#JOB-2** — Instagram reconnect R2 orphan cleanup.
  - **P3 cleanups** — #SCALE-5 / #SHOP-1 / #CCH-3 / #CCH-4.
