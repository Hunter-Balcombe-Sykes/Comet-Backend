# Platform Integrations — Collapse & Cutover Implementation Plan (Plan 6, final)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Kill the two remaining centralizers in the platform-integrations subsystem — `PlatformRefresher`'s hard-coded `match()` and `ProviderDetector`'s hard-coded category map — by driving both from the `PlatformRegistry`, then drop the now-redundant `platform` CHECK constraint, leaving the registry as the single source of truth for "what a platform is."

**Architecture:** The spine (registry, typed descriptors, `FetchStrategy`/`RefreshStrategy` seams, `FetchShapeException`/`FetchUnavailableException` buckets) already exists and is partially wired from Plans 1–5. This plan (1) builds the three missing fetch strategies (`strava`, `eventbrite`, `humanitix`), (2) rewrites `PlatformRefresher::refresh()` to resolve each connection's behaviour from `descriptor->refreshStrategy()` while keeping the failure bookkeeping centralized, (3) points the refresh command + `RefreshController` at `registry->refreshable()`, (4) rewrites `ProviderDetector` to read registry categories via a new `Detection` seam, and (5) drops the DB CHECK in one raw-SQL migration. Every step is strangler-safe: the old path stays live until its typed replacement is proven green by parity + golden-master tests.

**Tech Stack:** PHP 8.2, Laravel 12, Pest 4 (SQLite in-memory for tests), Postgres/Supabase (raw SQL migrations only — `supabase/migrations/`), Redis-backed Horizon.

## Global Constraints

- **API contract is FROZEN, byte-for-byte.** No route, response shape, status code, or 422/429 copy may change. The `IntegrationContractGoldenMasterTest` (`tests/Feature/Platforms/GoldenMaster/`) is the proof; it must stay green at every commit.
- **No Laravel migrations.** The composer guard `guard:no-laravel-migrations` rejects them. The one schema change is a raw `.sql` file in `supabase/migrations/`.
- **Behaviour preservation is the whole job.** The refresher rewrite must preserve EXACTLY: the success path (`payload` + `last_refreshed_at` + `last_refresh_status='ok'` + `last_refresh_error=null` + `consecutive_failures=0` via `update()`), the three status buckets `ok`/`unavailable`/`error`, the atomic `consecutive_failures` increment on failure, the `integrations.refresh.bad_shape` warning fired ONLY for `status==='error'`, and the `IntegrationConnectionObserver` cache-purge behaviour (purge on a genuine payload change; no-op on a failed refresh).
- **Catch the two specific exceptions only.** `FetchShapeException` and `FetchUnavailableException` both extend `\RuntimeException`. The refresher must catch each subclass explicitly and MUST NOT catch `\RuntimeException` or `\Throwable` — a generic scraper exception has to bubble past the refresher to the command's loop-level `catch`, which reports it to Nightwatch (`RefreshPlatformConnectionsCommandTest` → "catches scraper exceptions without crashing the command loop").
- **SQLite never enforced the dropped CHECK.** The `platform_connections_platform_check` constraint is a Postgres-only safety net; SQLite (CI) never applied it. So dropping it changes nothing in the test suite — the real guard against a bad `platform` write is `RegistryCoverageTest` (app-level) + `GenericPlatformController`'s existing `abort_if(registry->get(...) === null, 404)`. Verify the DROP against the live dev Postgres, not a green suite (per CLAUDE.md's SQLite-vs-Postgres caveat).
- **Pint clean.** Run `php artisan pint` on changed files before each commit; keep commits surgical (no baseline churn).
- **This is the only plan in the series whose ship step pushes a migration to Supabase dev.** Authoring writes the `.sql` file; the ship section runs `supabase db push` against `glncumufgaqcmqhzwrxm`.

---

## Reality vs. spec — read before starting

The 2026-06-26 design doc (`docs/superpowers/specs/2026-06-26-platform-integrations-registry-redesign-design.md`) §10 step 4 and §14 describe this plan's scope. Two items in that sketch do **not** match the code Plans 1–5 actually shipped. Follow the code, not the sketch:

1. **"Delete the ~17 thin per-platform controllers" — DO NOT.** The real migration kept each platform's `connect()` (and bespoke writes like `recent`/`highlights`) on its thin controller and moved only the read paths (`selection`/`accounts`/`forget`) to `GenericPlatformController`, *to hold the golden-master net-route count at 52* (see `routes/api/integrations.php` comments). `SingleSelectionPlatformController` still backs 14 controllers. **No controller is dead.** Task 8 verifies this and documents it; it deletes no controllers.
2. **"Wire `PlatformInRegistry` into the Form Requests that validate a `platform` value" — there are none for integrations.** The per-platform connect requests are platform-implicit (`ConnectSocialLinkRequest` validates `username`, not `platform`). The only user-influenced integration platform value is `RefreshController`'s `{platform}` route param (handled in Task 4). The link-block/analytics requests that DO carry a `platform` field validate against `config('partna.social_platforms')` — a *different* registry — and must be left alone. Task 6 documents the write-gate coverage instead of adding an unused rule.

Out of scope (do not touch): `ShopProviderDetector` (separate multi-brand shop detection), the dual `/integrations` + `/platforms` route registration, any OAuth/webhook seam implementation.

---

## File Map

**Create:**
- `app/Services/Platforms/Strategies/Fetch/EventbriteFetch.php`
- `app/Services/Platforms/Strategies/Fetch/HumanitixFetch.php`
- `app/Services/Platforms/Strategies/Fetch/StravaFetch.php`
- `app/Services/Platforms/Strategies/Contracts/Detection.php`
- `app/Services/Platforms/Strategies/Detect/HostMatch.php`
- `app/Services/Platforms/Strategies/Detect/ServiceMatch.php`
- `tests/Feature/Platforms/Strategies/EventsFetchParityTest.php`
- `tests/Feature/Platforms/Strategies/StravaFetchParityTest.php`
- `tests/Feature/Platforms/Strategies/RefresherCutoverParityTest.php`
- `supabase/migrations/20260629120000_drop_platform_connections_check.sql`

**Modify:**
- `app/Services/Platforms/Registry/PlatformDescriptor.php` — add `refreshStrategy()`; add `detect()` / `detection()`.
- `app/Services/Platforms/Registry/PlatformRegistry.php` — add `isRefreshable(string $key): bool`.
- `app/Providers/PlatformRegistryServiceProvider.php` — wire the 3 new fetch strategies + 7 detection strategies.
- `app/Services/Platforms/PlatformRefresher.php` — full rewrite (registry-driven; delete the `match()` + all private payload methods + the `REFRESHABLE` constant).
- `app/Services/Platforms/ProviderDetector.php` — full rewrite (registry-driven).
- `app/Console/Commands/RefreshIntegrationConnectionsCommand.php` — query off `registry->refreshable()`.
- `app/Http/Controllers/Api/Platforms/RefreshController.php` — gate off `registry->isRefreshable()`.
- `tests/Feature/Platforms/Registry/RegistryCoverageTest.php` — inline the frozen refreshable-15 literal (replace `PlatformRefresher::REFRESHABLE`).
- `routes/api/integrations.php` (comment), `app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php` (comment) — drop the `PlatformRefresher::REFRESHABLE` references in prose.
- `tests/Feature/Platforms/IntegrationCategoriesTest.php` — add an events-detection assertion (Task 5).

**Delete (code, not files):** `PlatformRefresher::REFRESHABLE`, `PlatformRefresher`'s `match()` + ~15 private `*Payload` methods, `ProviderDetector::CATEGORY_PROVIDERS` + `matches()`.

---

## Task 0: Prerequisite & baseline

**Files:** none (verification only).

- [ ] **Step 1: Confirm Plan 5 is merged and the tree is clean**

```bash
git fetch && git pull
git log --oneline -8
```
Expected: the log shows the Plan 5 commits (`RegistryCoverageTest — google-business payload deferral resolved`, `no untyped payload access remains`, `migrate residual feed-controller re-fetch reads onto FeedPayload`, `migrate custom links onto CardPayload`). If they are absent, STOP — Plan 5 is the prerequisite.

- [ ] **Step 2: Capture a green baseline**

```bash
composer test -- --filter=Platforms
```
Expected: PASS. In particular `RegistryCoverageTest`, `FeedFetchParityTest`, `EmbedFetchParityTest`, `RefreshPlatformConnectionsCommandTest`, `IntegrationContractGoldenMasterTest`, `IntegrationCategoriesTest`, `ReservationProvidersTest`, `GenericStrategiesTest` all green. This is the contract you must not break.

- [ ] **Step 3: Branch**

```bash
git checkout -b plan6/platform-integrations-collapse-cutover origin/development
```

---

## Task 1: Build the three missing fetch strategies

The registry marks `strava`, `eventbrite`, `humanitix` `refreshable()` but has no `FetchStrategy` attached to them — `PlatformRefresher` still serves them through its `match()`. Before the `match()` can be deleted (Task 3), each needs a strategy that mirrors its current `*Payload` method byte-for-byte, attached in the provider, and proven equivalent by a parity test. This task is purely additive — the old `match()` still runs.

**Files:**
- Create: `app/Services/Platforms/Strategies/Fetch/StravaFetch.php`
- Create: `app/Services/Platforms/Strategies/Fetch/EventbriteFetch.php`
- Create: `app/Services/Platforms/Strategies/Fetch/HumanitixFetch.php`
- Modify: `app/Providers/PlatformRegistryServiceProvider.php`
- Test: `tests/Feature/Platforms/Strategies/StravaFetchParityTest.php`
- Test: `tests/Feature/Platforms/Strategies/EventsFetchParityTest.php`

**Interfaces:**
- Consumes: `FetchStrategy::fetch(IntegrationConnection): array` (contract, existing); `FetchShapeException` / `FetchUnavailableException` (existing); `EventsPayload::accountPayload(string $url, ?string $organiser, array $events, array $hiddenEventIds = []): array` and `EventsPayload::standalonePayload(array $event): array` (existing static builders, `App\Services\Platforms\EventsPayload`); scrapers `StravaClubScraper::fetchClub(string $url): ?array`, `EventbriteScraper::fetchEvents(string $url): ?array` (`['organiser' => ?string, 'events' => array]`), `EventbriteScraper::fetchSingleEvent(string $link): ?array`, `HumanitixScraper::fetchEvents`, `HumanitixScraper::fetchSingleEvent`.
- Produces: `StravaFetch`, `EventbriteFetch`, `HumanitixFetch` (each a `final readonly class implements FetchStrategy`), wired onto the `strava` / `eventbrite` / `humanitix` descriptors via `->fetch(...)`.

- [ ] **Step 1: Write the Strava parity test (failing)**

Create `tests/Feature/Platforms/Strategies/StravaFetchParityTest.php`. The `gmUser`/`gmSeed` helpers are loaded globally by `tests/Pest.php` (same as `FeedFetchParityTest`).

```php
<?php

use App\Services\Platforms\PlatformRefresher;
use App\Services\Platforms\Strategies\Fetch\FetchShapeException;
use App\Services\Platforms\Strategies\Fetch\FetchUnavailableException;
use App\Services\Platforms\Strategies\Fetch\StravaFetch;
use App\Services\Platforms\StravaClubScraper;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

it('StravaFetch produces the same merged card payload as the refresher (nulls keep stored values)', function () {
    // fetchClub() returns {name, location, image, description, members}. Here the
    // scrape yields a fresh name but a null image — null must fall back to the stored value.
    $card = ['name' => 'Fresh Club', 'image' => null, 'members' => 42];
    $this->mock(StravaClubScraper::class, fn ($m) => $m->shouldReceive('fetchClub')->andReturn($card));

    $stored = ['url' => 'https://www.strava.com/clubs/abc', 'name' => 'Old Club', 'image' => 'stored.jpg'];

    $refresherRow = gmSeed(gmUser('gmst1'), 'strava', $stored);
    app(PlatformRefresher::class)->refresh($refresherRow);

    $strategyRow = gmSeed(gmUser('gmst2'), 'strava', $stored);
    $result = (new StravaFetch(app(StravaClubScraper::class)))->fetch($strategyRow);

    expect($result)->toEqual($refresherRow->fresh()->payload);
    expect($result['name'])->toBe('Fresh Club');
    expect($result['image'])->toBe('stored.jpg'); // null scrape kept the stored value
    expect($result['members'])->toBe(42);
});

it('StravaFetch throws FetchShapeException when url is missing (refresher status=error)', function () {
    $row = gmSeed(gmUser('gmst3'), 'strava', ['name' => 'no url']);
    app(PlatformRefresher::class)->refresh($row);
    expect($row->fresh()->last_refresh_status)->toBe('error');

    $strategyRow = gmSeed(gmUser('gmst4'), 'strava', ['name' => 'no url']);
    expect(fn () => (new StravaFetch(app(StravaClubScraper::class)))->fetch($strategyRow))->toThrow(FetchShapeException::class);
});

it('StravaFetch throws FetchUnavailableException when the scrape is null (refresher status=unavailable)', function () {
    $this->mock(StravaClubScraper::class, fn ($m) => $m->shouldReceive('fetchClub')->andReturn(null));

    $row = gmSeed(gmUser('gmst5'), 'strava', ['url' => 'https://www.strava.com/clubs/abc']);
    app(PlatformRefresher::class)->refresh($row);
    expect($row->fresh()->last_refresh_status)->toBe('unavailable');

    $strategyRow = gmSeed(gmUser('gmst6'), 'strava', ['url' => 'https://www.strava.com/clubs/abc']);
    expect(fn () => (new StravaFetch(app(StravaClubScraper::class)))->fetch($strategyRow))->toThrow(FetchUnavailableException::class);
});
```

- [ ] **Step 2: Run it — expect failure**

Run: `composer test -- --filter=StravaFetchParityTest`
Expected: FAIL with "Class StravaFetch not found".

- [ ] **Step 3: Create `StravaFetch`**

Mirrors `PlatformRefresher::scrapedCardPayload($payload, fn ($url) => $this->strava->fetchClub($url), 'strava')` exactly.

```php
<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;
use App\Services\Platforms\StravaClubScraper;

// Re-scrapes a Strava club card from its stored URL and merges the fresh fields
// over the existing payload (a null scrape value keeps the stored one). Mirrors
// PlatformRefresher::scrapedCardPayload EXACTLY.
final readonly class StravaFetch implements FetchStrategy
{
    public function __construct(private StravaClubScraper $strava) {}

    public function fetch(IntegrationConnection $connection): array
    {
        $payload = $connection->payload ?? [];

        $url = $payload['url'] ?? null;
        if (! $url) {
            throw new FetchShapeException('missing_key: url');
        }
        $card = $this->strava->fetchClub($url);
        if ($card === null) {
            throw new FetchUnavailableException('strava_fetch_failed');
        }

        // Refresh every scraped field; nulls from the scrape keep stored values.
        $merged = $payload;
        foreach ($card as $key => $value) {
            $merged[$key] = $value ?? ($payload[$key] ?? null);
        }

        return $merged;
    }
}
```

- [ ] **Step 4: Wire `strava`'s fetch in the provider**

In `app/Providers/PlatformRegistryServiceProvider.php`, add the `use` import and, immediately after the `strava` descriptor is registered (the `PD::make('strava')...->refreshable()` line), attach the strategy:

```php
use App\Services\Platforms\StravaClubScraper;
use App\Services\Platforms\Strategies\Fetch\StravaFetch;
```
```php
// Attach the live fetch strategy (Plan 6). Consumed by the registry-driven refresher.
$r->get('strava')->fetch(new StravaFetch($this->app->make(StravaClubScraper::class)));
```

- [ ] **Step 5: Run the Strava test — expect pass**

Run: `composer test -- --filter=StravaFetchParityTest`
Expected: PASS (all 3).

- [ ] **Step 6: Write the events parity test (failing)**

Create `tests/Feature/Platforms/Strategies/EventsFetchParityTest.php`. Covers both branches (organiser account + `kind==='event'` standalone) for Eventbrite, plus the Humanitix account branch, plus both failure buckets.

```php
<?php

use App\Services\Platforms\EventbriteScraper;
use App\Services\Platforms\HumanitixScraper;
use App\Services\Platforms\PlatformRefresher;
use App\Services\Platforms\Strategies\Fetch\EventbriteFetch;
use App\Services\Platforms\Strategies\Fetch\FetchShapeException;
use App\Services\Platforms\Strategies\Fetch\FetchUnavailableException;
use App\Services\Platforms\Strategies\Fetch\HumanitixFetch;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

it('EventbriteFetch (organiser account) matches the refresher and re-applies hidden ids', function () {
    $result = ['organiser' => 'Acme Events', 'events' => [
        ['id' => 'e1', 'name' => 'One'], ['id' => 'e2', 'name' => 'Two'],
    ]];
    $this->mock(EventbriteScraper::class, fn ($m) => $m->shouldReceive('fetchEvents')->andReturn($result));

    $stored = ['url' => 'https://eventbrite.com/o/acme', 'hiddenEventIds' => ['e2']];

    $refresherRow = gmSeed(gmUser('gmev1'), 'eventbrite', $stored);
    app(PlatformRefresher::class)->refresh($refresherRow);

    $strategyRow = gmSeed(gmUser('gmev2'), 'eventbrite', $stored);
    $out = (new EventbriteFetch(app(EventbriteScraper::class)))->fetch($strategyRow);

    expect($out)->toEqual($refresherRow->fresh()->payload);
});

it('EventbriteFetch (standalone kind=event) re-scrapes the single event page', function () {
    $event = ['link' => 'https://eventbrite.com/e/show-1', 'name' => 'Show'];
    $this->mock(EventbriteScraper::class, fn ($m) => $m->shouldReceive('fetchSingleEvent')->with('https://eventbrite.com/e/show-1')->andReturn($event));

    $stored = ['kind' => 'event', 'link' => 'https://eventbrite.com/e/show-1', 'name' => 'Old'];

    $refresherRow = gmSeed(gmUser('gmev3'), 'eventbrite', $stored, 'event-show1');
    app(PlatformRefresher::class)->refresh($refresherRow);

    $strategyRow = gmSeed(gmUser('gmev4'), 'eventbrite', $stored, 'event-show1');
    $out = (new EventbriteFetch(app(EventbriteScraper::class)))->fetch($strategyRow);

    expect($out)->toEqual($refresherRow->fresh()->payload);
});

it('EventbriteFetch throws FetchShapeException when the account url is missing (status=error)', function () {
    $row = gmSeed(gmUser('gmev5'), 'eventbrite', ['name' => 'no url']);
    app(PlatformRefresher::class)->refresh($row);
    expect($row->fresh()->last_refresh_status)->toBe('error');

    expect(fn () => (new EventbriteFetch(app(EventbriteScraper::class)))->fetch(gmSeed(gmUser('gmev6'), 'eventbrite', ['name' => 'no url'])))
        ->toThrow(FetchShapeException::class);
});

it('EventbriteFetch throws FetchUnavailableException when fetchEvents is null (status=unavailable)', function () {
    $this->mock(EventbriteScraper::class, fn ($m) => $m->shouldReceive('fetchEvents')->andReturn(null));
    $row = gmSeed(gmUser('gmev7'), 'eventbrite', ['url' => 'https://eventbrite.com/o/acme']);
    app(PlatformRefresher::class)->refresh($row);
    expect($row->fresh()->last_refresh_status)->toBe('unavailable');

    expect(fn () => (new EventbriteFetch(app(EventbriteScraper::class)))->fetch(gmSeed(gmUser('gmev8'), 'eventbrite', ['url' => 'https://eventbrite.com/o/acme'])))
        ->toThrow(FetchUnavailableException::class);
});

it('HumanitixFetch (organiser account) matches the refresher', function () {
    $result = ['organiser' => 'Town Hall', 'events' => [['id' => 'h1', 'name' => 'Gig']]];
    $this->mock(HumanitixScraper::class, fn ($m) => $m->shouldReceive('fetchEvents')->andReturn($result));

    $stored = ['url' => 'https://humanitix.com/host/townhall', 'hiddenEventIds' => []];

    $refresherRow = gmSeed(gmUser('gmhx1'), 'humanitix', $stored);
    app(PlatformRefresher::class)->refresh($refresherRow);

    $strategyRow = gmSeed(gmUser('gmhx2'), 'humanitix', $stored);
    $out = (new HumanitixFetch(app(HumanitixScraper::class)))->fetch($strategyRow);

    expect($out)->toEqual($refresherRow->fresh()->payload);
});
```

- [ ] **Step 7: Run it — expect failure**

Run: `composer test -- --filter=EventsFetchParityTest`
Expected: FAIL with "Class EventbriteFetch not found".

- [ ] **Step 8: Create `EventbriteFetch`**

Mirrors `PlatformRefresher::eventbritePayload` + `standaloneEventPayload` exactly. Note: a missing/empty `link` on a `kind==='event'` row is `missing_key: link` (shape error); a missing `url` on an account row is `missing_key: url`.

```php
<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\EventbriteScraper;
use App\Services\Platforms\EventsPayload;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;

// Re-pulls an Eventbrite organiser account's events, or re-scrapes a single
// standalone event when payload.kind === 'event'. Mirrors
// PlatformRefresher::eventbritePayload + standaloneEventPayload EXACTLY — same
// kind branch, same accountPayload re-application of the user's hiddenEventIds.
final readonly class EventbriteFetch implements FetchStrategy
{
    public function __construct(private EventbriteScraper $eventbrite) {}

    public function fetch(IntegrationConnection $connection): array
    {
        $payload = $connection->payload ?? [];

        // Standalone-event row — re-scrape the single event page; the id is
        // re-derived from the link so it stays stable across refreshes.
        if (($payload['kind'] ?? null) === 'event') {
            $link = $payload['link'] ?? null;
            if (! is_string($link) || $link === '') {
                throw new FetchShapeException('missing_key: link');
            }
            $event = $this->eventbrite->fetchSingleEvent($link);
            if ($event === null) {
                throw new FetchUnavailableException('eventbrite_event_fetch_failed');
            }

            return EventsPayload::standalonePayload($event);
        }

        $url = $payload['url'] ?? null;
        if (! $url) {
            throw new FetchShapeException('missing_key: url');
        }
        $result = $this->eventbrite->fetchEvents($url);
        if ($result === null) {
            throw new FetchUnavailableException('eventbrite_fetch_failed');
        }

        // accountPayload re-applies the user's per-event hides to the fresh list.
        return EventsPayload::accountPayload(
            $url,
            $result['organiser'],
            $result['events'],
            is_array($payload['hiddenEventIds'] ?? null) ? $payload['hiddenEventIds'] : [],
        );
    }
}
```

- [ ] **Step 9: Create `HumanitixFetch`** (identical shape; swap the scraper + the platform error strings)

```php
<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\EventsPayload;
use App\Services\Platforms\HumanitixScraper;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;

// Humanitix twin of EventbriteFetch — organiser account events or a single
// standalone event (payload.kind === 'event'). Mirrors
// PlatformRefresher::humanitixPayload + standaloneEventPayload EXACTLY.
final readonly class HumanitixFetch implements FetchStrategy
{
    public function __construct(private HumanitixScraper $humanitix) {}

    public function fetch(IntegrationConnection $connection): array
    {
        $payload = $connection->payload ?? [];

        if (($payload['kind'] ?? null) === 'event') {
            $link = $payload['link'] ?? null;
            if (! is_string($link) || $link === '') {
                throw new FetchShapeException('missing_key: link');
            }
            $event = $this->humanitix->fetchSingleEvent($link);
            if ($event === null) {
                throw new FetchUnavailableException('humanitix_event_fetch_failed');
            }

            return EventsPayload::standalonePayload($event);
        }

        $url = $payload['url'] ?? null;
        if (! $url) {
            throw new FetchShapeException('missing_key: url');
        }
        $result = $this->humanitix->fetchEvents($url);
        if ($result === null) {
            throw new FetchUnavailableException('humanitix_fetch_failed');
        }

        return EventsPayload::accountPayload(
            $url,
            $result['organiser'],
            $result['events'],
            is_array($payload['hiddenEventIds'] ?? null) ? $payload['hiddenEventIds'] : [],
        );
    }
}
```

- [ ] **Step 10: Wire `eventbrite` + `humanitix` fetch in the provider**

Add the imports and, after the events descriptors are registered (the `PD::make('eventbrite')...` / `PD::make('humanitix')...` lines), attach:

```php
use App\Services\Platforms\EventbriteScraper;
use App\Services\Platforms\HumanitixScraper;
use App\Services\Platforms\Strategies\Fetch\EventbriteFetch;
use App\Services\Platforms\Strategies\Fetch\HumanitixFetch;
```
```php
// Attach the live event fetch strategies (Plan 6). Consumed by the registry-driven refresher.
$r->get('eventbrite')->fetch(new EventbriteFetch($this->app->make(EventbriteScraper::class)));
$r->get('humanitix')->fetch(new HumanitixFetch($this->app->make(HumanitixScraper::class)));
```

- [ ] **Step 11: Run the events test — expect pass**

Run: `composer test -- --filter=EventsFetchParityTest`
Expected: PASS.

- [ ] **Step 12: Extend the coverage test to assert the 3 new strategies are attached**

In `tests/Feature/Platforms/Registry/RegistryCoverageTest.php`, add:

```php
it('attaches the Plan-6 fetch strategies to strava, eventbrite and humanitix', function () {
    $registry = app(\App\Services\Platforms\Registry\PlatformRegistry::class);

    expect($registry->get('strava')->fetchStrategy())->toBeInstanceOf(\App\Services\Platforms\Strategies\Fetch\StravaFetch::class);
    expect($registry->get('eventbrite')->fetchStrategy())->toBeInstanceOf(\App\Services\Platforms\Strategies\Fetch\EventbriteFetch::class);
    expect($registry->get('humanitix')->fetchStrategy())->toBeInstanceOf(\App\Services\Platforms\Strategies\Fetch\HumanitixFetch::class);
});
```

- [ ] **Step 13: Full platforms suite + pint, then commit**

Run: `php artisan pint app/Services/Platforms/Strategies/Fetch/ app/Providers/PlatformRegistryServiceProvider.php tests/Feature/Platforms/Strategies/ && composer test -- --filter=Platforms`
Expected: PASS (old `match()` still live; new strategies proven equivalent).

```bash
git add app/Services/Platforms/Strategies/Fetch/StravaFetch.php app/Services/Platforms/Strategies/Fetch/EventbriteFetch.php app/Services/Platforms/Strategies/Fetch/HumanitixFetch.php app/Providers/PlatformRegistryServiceProvider.php tests/Feature/Platforms/Strategies/StravaFetchParityTest.php tests/Feature/Platforms/Strategies/EventsFetchParityTest.php tests/Feature/Platforms/Registry/RegistryCoverageTest.php
git commit -m "feat(integrations): StravaFetch + EventbriteFetch + HumanitixFetch strategies (Plan 6) — parity with the refresher, every refreshable platform now has a FetchStrategy"
```

---

## Task 2: Expose `refreshStrategy()` on the descriptor + `isRefreshable()` on the registry

Additive seams the refresher and `RefreshController` consume in Tasks 3–4. The descriptor *derives* its refresh behaviour from the already-attached `fetchStrategy` + `refreshable` flag — `ScheduledRefresh` (re-pull + persist) when refreshable with a fetch, else `NoRefresh`. This honours the spec's `$p->refresh()->run($p)` shape and reuses the existing, already-tested `ScheduledRefresh`/`NoRefresh` (no dead code). The descriptor already references concrete `Payload` classes in its presets, so referencing concrete refresh strategies here is consistent.

**Files:**
- Modify: `app/Services/Platforms/Registry/PlatformDescriptor.php`
- Modify: `app/Services/Platforms/Registry/PlatformRegistry.php`
- Test: `tests/Feature/Platforms/Registry/RegistryCoverageTest.php`

**Interfaces:**
- Produces: `PlatformDescriptor::refreshStrategy(): RefreshStrategy`; `PlatformRegistry::isRefreshable(string $key): bool`.
- Consumes: `ScheduledRefresh(FetchStrategy)`, `NoRefresh` (existing, `App\Services\Platforms\Strategies\Refresh`).

- [ ] **Step 1: Write failing tests for both accessors**

Append to `tests/Feature/Platforms/Registry/RegistryCoverageTest.php`:

```php
it('derives a ScheduledRefresh for a refreshable platform and NoRefresh otherwise', function () {
    $registry = app(\App\Services\Platforms\Registry\PlatformRegistry::class);

    expect($registry->get('youtube')->refreshStrategy())->toBeInstanceOf(\App\Services\Platforms\Strategies\Refresh\ScheduledRefresh::class);
    expect($registry->get('instagram')->refreshStrategy())->toBeInstanceOf(\App\Services\Platforms\Strategies\Refresh\NoRefresh::class); // not refreshable
    expect($registry->get('tiktok')->refreshStrategy())->toBeInstanceOf(\App\Services\Platforms\Strategies\Refresh\NoRefresh::class);   // link-only
});

it('isRefreshable mirrors the refreshable() set', function () {
    $registry = app(\App\Services\Platforms\Registry\PlatformRegistry::class);

    expect($registry->isRefreshable('youtube'))->toBeTrue();
    expect($registry->isRefreshable('instagram'))->toBeFalse();
    expect($registry->isRefreshable('not-a-platform'))->toBeFalse();
});
```

- [ ] **Step 2: Run — expect failure**

Run: `composer test -- --filter=RegistryCoverageTest`
Expected: FAIL ("Call to undefined method ... refreshStrategy()").

- [ ] **Step 3: Add `refreshStrategy()` to the descriptor**

In `app/Services/Platforms/Registry/PlatformDescriptor.php`, add the imports and method (place the method near `fetchStrategy()`):

```php
use App\Services\Platforms\Strategies\Contracts\RefreshStrategy;
use App\Services\Platforms\Strategies\Refresh\NoRefresh;
use App\Services\Platforms\Strategies\Refresh\ScheduledRefresh;
```
```php
/**
 * The refresh behaviour for this platform, derived from its fetch strategy and
 * refreshable flag: a re-pull-and-persist ScheduledRefresh when refreshable with
 * a fetch, else the no-op NoRefresh. The registry-driven PlatformRefresher calls
 * refreshStrategy()->run() and wraps it to record the failure buckets the
 * strategy intentionally doesn't carry.
 */
public function refreshStrategy(): RefreshStrategy
{
    return $this->refreshable && $this->fetchStrategy !== null
        ? new ScheduledRefresh($this->fetchStrategy)
        : new NoRefresh;
}
```

- [ ] **Step 4: Add `isRefreshable()` to the registry**

In `app/Services/Platforms/Registry/PlatformRegistry.php`, after `refreshable()`:

```php
public function isRefreshable(string $key): bool
{
    return isset($this->descriptors[$key]) && $this->descriptors[$key]->isRefreshable();
}
```

- [ ] **Step 5: Run — expect pass**

Run: `composer test -- --filter=RegistryCoverageTest`
Expected: PASS.

- [ ] **Step 6: Pint + commit**

Run: `php artisan pint app/Services/Platforms/Registry/`
```bash
git add app/Services/Platforms/Registry/PlatformDescriptor.php app/Services/Platforms/Registry/PlatformRegistry.php tests/Feature/Platforms/Registry/RegistryCoverageTest.php
git commit -m "feat(integrations): descriptor.refreshStrategy() + registry.isRefreshable() (Plan 6) — refresh behaviour derived from the registry"
```

---

## Task 3: Rewrite `PlatformRefresher` — registry-driven, delete the `match()`

The cutover. Replace the `match()` + ~15 private `*Payload` methods with a per-connection registry lookup that delegates the success path to `descriptor->refreshStrategy()->run()` and keeps the failure bookkeeping (bucket selection, atomic increment, `bad_shape` log) centralized here. Every platform now resolves through a `FetchStrategy` (Task 1 closed the last three gaps), so nothing is left behind. Guarded by the full parity suite + `RefreshPlatformConnectionsCommandTest` + the golden master + a new cutover-parity test.

**Files:**
- Modify (rewrite): `app/Services/Platforms/PlatformRefresher.php`
- Test: `tests/Feature/Platforms/Strategies/RefresherCutoverParityTest.php`

**Interfaces:**
- Consumes: `PlatformRegistry::get(string): ?PlatformDescriptor`, `PlatformDescriptor::refreshStrategy(): RefreshStrategy`, `RefreshStrategy::isRefreshable()` / `run(IntegrationConnection)`, `FetchShapeException`, `FetchUnavailableException`.
- Produces: `PlatformRefresher::refresh(IntegrationConnection): IntegrationConnection` (signature UNCHANGED — callers `RefreshController` and the command are untouched here). The `REFRESHABLE` constant is REMOVED (its consumers move to the registry in Task 4).

- [ ] **Step 1: Write the cutover-parity test (failing against the current refresher is not required — it characterizes the post-rewrite contract; write it now so it guards the rewrite)**

Create `tests/Feature/Platforms/Strategies/RefresherCutoverParityTest.php`. This asserts the refresher's behaviour per bucket independently of internals, and explicitly pins the "generic exception bubbles, specific exceptions are caught" rule and the observer no-op-on-failure rule.

```php
<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\PlatformRefresher;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;
use App\Services\Platforms\Strategies\Fetch\FetchShapeException;
use App\Services\Platforms\Strategies\Fetch\FetchUnavailableException;
use App\Services\Platforms\YoutubeScraper;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

it('success path persists the new payload and resets failure state (status=ok)', function () {
    $this->mock(YoutubeScraper::class, fn ($m) => $m->shouldReceive('fetchRecentVideos')->andReturn([
        ['videoId' => 'v9', 'name' => 'New', 'description' => 'd', 'link' => 'l', 'thumbnail' => 't'],
    ]));

    $row = gmSeed(gmUser('rc1'), 'youtube', ['handle' => 'chan', 'consecutive' => 0]);
    $row->update(['consecutive_failures' => 3]);

    app(PlatformRefresher::class)->refresh($row->fresh());

    $row->refresh();
    expect($row->last_refresh_status)->toBe('ok');
    expect($row->last_refresh_error)->toBeNull();
    expect($row->consecutive_failures)->toBe(0);
    expect($row->payload['name'])->toBe('New');
    expect($row->last_refreshed_at)->not->toBeNull();
});

it('shape error logs bad_shape, sets status=error, increments consecutive_failures', function () {
    $row = gmSeed(gmUser('rc2'), 'youtube', ['name' => 'no handle']);

    app(PlatformRefresher::class)->refresh($row);

    $row->refresh();
    expect($row->last_refresh_status)->toBe('error');
    expect($row->last_refresh_error)->toBe('missing_key: handle');
    expect($row->consecutive_failures)->toBe(1);
});

it('unavailable miss sets status=unavailable, increments, preserves last-known payload', function () {
    $this->mock(YoutubeScraper::class, fn ($m) => $m->shouldReceive('fetchRecentVideos')->andReturn([]));

    $row = gmSeed(gmUser('rc3'), 'youtube', ['handle' => 'chan', 'name' => 'Kept']);

    app(PlatformRefresher::class)->refresh($row);

    $row->refresh();
    expect($row->last_refresh_status)->toBe('unavailable');
    expect($row->consecutive_failures)->toBe(1);
    expect($row->payload['name'])->toBe('Kept'); // last-known-good preserved
});

it('an unregistered/non-refreshable platform is an unsupported_platform error', function () {
    // 'instagram' is registered but NOT refreshable → mirrors the old default arm.
    $row = gmSeed(gmUser('rc4'), 'instagram', ['username' => 'ig']);

    app(PlatformRefresher::class)->refresh($row);

    $row->refresh();
    expect($row->last_refresh_status)->toBe('error');
    expect($row->last_refresh_error)->toBe('unsupported_platform');
    expect($row->consecutive_failures)->toBe(1);
});

it('a generic (non-Fetch*) exception bubbles out of refresh() — it is NOT swallowed as unavailable', function () {
    $this->mock(YoutubeScraper::class, fn ($m) => $m->shouldReceive('fetchRecentVideos')->andThrow(new RuntimeException('scraper boom')));

    $row = gmSeed(gmUser('rc5'), 'youtube', ['handle' => 'chan']);
    $row->update(['last_refresh_status' => 'ok', 'consecutive_failures' => 0]);

    expect(fn () => app(PlatformRefresher::class)->refresh($row->fresh()))->toThrow(RuntimeException::class, 'scraper boom');

    // Row untouched — refresh() threw before persisting anything.
    $row->refresh();
    expect($row->last_refresh_status)->toBe('ok');
    expect($row->consecutive_failures)->toBe(0);
});
```

- [ ] **Step 2: Run — expect the unsupported-platform + generic-exception cases to characterize current behaviour (sanity baseline)**

Run: `composer test -- --filter=RefresherCutoverParityTest`
Expected: PASS against the *current* refresher too (these behaviours already hold) — this confirms the test characterizes the contract correctly before you change the implementation. If any case fails now, STOP and reconcile the test with current behaviour before rewriting.

- [ ] **Step 3: Rewrite `PlatformRefresher`**

Replace the ENTIRE file. The 12 scraper constructor deps collapse to one (`PlatformRegistry`); they now live in the fetch strategies.

```php
<?php

namespace App\Services\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\Registry\PlatformRegistry;
use App\Services\Platforms\Strategies\Fetch\FetchShapeException;
use App\Services\Platforms\Strategies\Fetch\FetchUnavailableException;
use Illuminate\Support\Facades\Log;

// Daily / on-demand refresh of the auto-content platform connections. The
// registry owns the per-platform refresh behaviour (descriptor->refreshStrategy()
// → ScheduledRefresh re-pulls + persists); this orchestrator adds the
// cross-cutting failure bookkeeping the strategies intentionally don't carry:
//   - status='ok'          → success (the strategy persisted through the model so
//                            IntegrationConnectionObserver purges the sitepage cache
//                            when the payload actually changed).
//   - status='error'       → a data-shape problem (missing required key); logged loudly.
//   - status='unavailable' → a transient upstream miss; recorded quietly, last-known
//                            payload preserved (no purge — nothing changed).
//
// A generic (non-Fetch*) exception is deliberately NOT caught here: it bubbles to
// the command's per-connection catch, which reports it to Nightwatch. FetchShape/
// FetchUnavailable both extend RuntimeException, so we catch each subclass
// explicitly and never the parent — a real scraper crash must not masquerade as a
// quiet 'unavailable'.
class PlatformRefresher
{
    public function __construct(private readonly PlatformRegistry $registry) {}

    public function refresh(IntegrationConnection $connection): IntegrationConnection
    {
        $descriptor = $this->registry->get($connection->platform);
        $strategy = $descriptor?->refreshStrategy();

        // Unknown or non-refreshable platform — mirrors the old match()'s default
        // arm. Unreachable from the cron/controller (both gate on the refreshable
        // set) but kept as a fail-loud guard.
        if ($strategy === null || ! $strategy->isRefreshable()) {
            return $this->recordFailure($connection, 'unsupported_platform', 'error');
        }

        try {
            return $strategy->run($connection);
        } catch (FetchShapeException $e) {
            return $this->recordFailure($connection, $e->getMessage(), 'error');
        } catch (FetchUnavailableException $e) {
            return $this->recordFailure($connection, $e->getMessage(), 'unavailable');
        }
    }

    // Persist a failed refresh: log a shape error loudly, then atomically bump
    // consecutive_failures. increment() avoids the read-modify-write race and fires
    // only updating/updated — a safe no-op in IntegrationConnectionObserver on a
    // failed refresh (it touches no payload).
    private function recordFailure(IntegrationConnection $connection, string $error, string $status): IntegrationConnection
    {
        if ($status === 'error') {
            Log::warning('integrations.refresh.bad_shape', [
                'platform' => $connection->platform,
                'platform_connection_id' => $connection->id,
                'error' => $error,
            ]);
        }

        $connection->increment('consecutive_failures', 1, [
            'last_refresh_status' => $status,
            'last_refresh_error' => $error,
        ]);

        return $connection;
    }
}
```

- [ ] **Step 4: Run the cutover-parity + command + feed/embed/events/strava parity + golden master**

Run: `composer test -- --filter='RefresherCutoverParityTest|RefreshPlatformConnectionsCommandTest|FeedFetchParityTest|EmbedFetchParityTest|EventsFetchParityTest|StravaFetchParityTest|IntegrationContractGoldenMasterTest'`
Expected: PASS. (Note: the per-platform parity tests now compare a strategy against a refresher that *uses that strategy* — they converge to a tautology but still exercise the full status-bucket path, so they remain valid regression guards. The independent guards are the cutover-parity test + the command test + the golden master.)

- [ ] **Step 5: Full suite + pint, then commit**

Run: `php artisan pint app/Services/Platforms/PlatformRefresher.php tests/Feature/Platforms/Strategies/RefresherCutoverParityTest.php && composer test`
Expected: PASS.

```bash
git add app/Services/Platforms/PlatformRefresher.php tests/Feature/Platforms/Strategies/RefresherCutoverParityTest.php
git commit -m "refactor(integrations): registry-driven PlatformRefresher (Plan 6) — delete the per-platform match(); buckets/observer behaviour preserved"
```

---

## Task 4: Point the command + `RefreshController` at the registry; delete `REFRESHABLE`

The refresher no longer owns the refreshable list. Move its two real consumers (the cron query, the manual-refresh gate) onto `registry->refreshable()` / `registry->isRefreshable()`, then delete the `REFRESHABLE` constant and re-home its frozen 15-item expectation into `RegistryCoverageTest`. Update the prose that names the constant.

**Files:**
- Modify: `app/Console/Commands/RefreshIntegrationConnectionsCommand.php`
- Modify: `app/Http/Controllers/Api/Platforms/RefreshController.php`
- Modify: `tests/Feature/Platforms/Registry/RegistryCoverageTest.php`
- Modify (comments only): `routes/api/integrations.php`, `app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php`

**Interfaces:**
- Consumes: `PlatformRegistry::refreshable(): array<string,PlatformDescriptor>`, `PlatformRegistry::isRefreshable(string): bool`.

- [ ] **Step 1: Re-home the refreshable expectation in the coverage test (do this first so the constant can be deleted)**

In `tests/Feature/Platforms/Registry/RegistryCoverageTest.php`, the test `marks exactly the current REFRESHABLE platforms as refreshable` currently reads `$expected = PlatformRefresher::REFRESHABLE;`. Replace that line with the frozen literal and drop the now-unused import:

```php
it('marks exactly the current REFRESHABLE platforms as refreshable', function () {
    $registry = app(PlatformRegistry::class);
    $refreshable = array_keys($registry->refreshable());
    sort($refreshable);

    // Frozen expectation (was PlatformRefresher::REFRESHABLE before Plan 6 deleted it).
    // The 15 auto-content platforms the daily cron + manual refresh button re-pull.
    $expected = [
        'youtube', 'youtube-music', 'eventbrite', 'humanitix', 'apple-music', 'apple-podcast',
        'bandcamp', 'spotify', 'soundcloud', 'deezer', 'vimeo', 'twitch', 'pinterest', 'strava',
        'google-business',
    ];
    sort($expected);

    expect($refreshable)->toBe($expected);
});
```
Remove `use App\Services\Platforms\PlatformRefresher;` from the file's imports (verify no other test in the file still references it — after this edit, none do).

- [ ] **Step 2: Run the coverage test — expect pass (it now uses the literal)**

Run: `composer test -- --filter=RegistryCoverageTest`
Expected: PASS.

- [ ] **Step 3: Point the command at the registry**

In `app/Console/Commands/RefreshIntegrationConnectionsCommand.php`:
- Add `use App\Services\Platforms\Registry\PlatformRegistry;` and remove `use App\Services\Platforms\PlatformRefresher;` if it becomes unused (it is still referenced by the `handle()` param type — keep it).
- Change the signature to inject the registry and swap the `whereIn`:

```php
public function handle(PlatformRefresher $refresher, PlatformRegistry $registry): int
{
    $limit = (int) $this->option('limit');
    $throttleMs = (int) $this->option('throttle-ms');

    $connections = IntegrationConnection::query()
        ->active()
        ->whereIn('platform', array_keys($registry->refreshable()))
        ->orderByRaw('last_refreshed_at ASC NULLS FIRST')
        ->limit($limit)
        ->get();
    // ... rest unchanged ...
}
```

- [ ] **Step 4: Point `RefreshController` at the registry**

In `app/Http/Controllers/Api/Platforms/RefreshController.php`:
- Add `use App\Services\Platforms\Registry\PlatformRegistry;`.
- Inject it and swap the gate (the 422 copy is part of the frozen contract — keep it verbatim):

```php
public function __construct(
    private readonly PlatformRefresher $refresher,
    private readonly PlatformRegistry $registry,
) {}

// POST /integrations/{platform}/refresh
public function refresh(Request $request, string $platform): JsonResponse
{
    if (! $this->registry->isRefreshable($platform)) {
        return $this->error('This connection refreshes on its own — there’s nothing to pull manually.', 422);
    }
    // ... rest unchanged ...
}
```
- Update the class docblock line that says "Only the auto-content platforms in `PlatformRefresher::REFRESHABLE` can be refreshed" → "Only the registry's refreshable platforms can be refreshed".

- [ ] **Step 5: Delete the `REFRESHABLE` constant**

In `app/Services/Platforms/PlatformRefresher.php`, delete the `public const REFRESHABLE = [...]` block entirely (the class now only has the constructor + `refresh()` + `recordFailure()` from Task 3, so the constant is the only leftover — remove it).

- [ ] **Step 6: Update the two prose references**

- `routes/api/integrations.php`: the comment near the `{platform}/refresh` route ("validated against `PlatformRefresher::REFRESHABLE` inside the controller") → "validated against the registry's refreshable set inside the controller".
- `app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php`: the comment "absent from `PlatformRefresher::REFRESHABLE`" → "absent from the registry's refreshable set".

- [ ] **Step 7: Verify no `REFRESHABLE` references remain**

Run: `grep -rn "REFRESHABLE" app/ tests/ routes/`
Expected: no output.

- [ ] **Step 8: Run the command test + full suite + pint, then commit**

Run: `php artisan pint app/Console/Commands/RefreshIntegrationConnectionsCommand.php app/Http/Controllers/Api/Platforms/RefreshController.php app/Services/Platforms/PlatformRefresher.php && composer test`
Expected: PASS.

```bash
git add app/Console/Commands/RefreshIntegrationConnectionsCommand.php app/Http/Controllers/Api/Platforms/RefreshController.php app/Services/Platforms/PlatformRefresher.php tests/Feature/Platforms/Registry/RegistryCoverageTest.php routes/api/integrations.php app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php
git commit -m "refactor(integrations): cron + RefreshController gate off the registry (Plan 6) — delete PlatformRefresher::REFRESHABLE"
```

---

## Task 5: Rewrite `ProviderDetector` — registry-driven via a `Detection` seam (BLOCKER — get sign-off before implementing)

`ProviderDetector` is the second centralizer: a hard-coded `CATEGORY_PROVIDERS` map + a `match()` of host regexes / service delegations. Make it registry-driven by adding a `Detection` capability to descriptors (mirroring the `Connect`/`Fetch` seam style) and having `detectFor()` iterate the registered descriptors of a category, in registration order (= priority). Adding a future provider becomes a descriptor + one `->detect(...)` line — no edit to `ProviderDetector`. The public contract `detectFor(string $category, string $url): ?string` is unchanged.

**This is a BLOCKER per CLAUDE.md's fix-flow gate** (it rewrites smart-detect logic feeding booking/reservations/events). Produce this task's diff, present it, and wait for Josh's sign-off before implementing. Out of scope: `ShopProviderDetector` (separate multi-brand shop detection — untouched).

**Files:**
- Create: `app/Services/Platforms/Strategies/Contracts/Detection.php`
- Create: `app/Services/Platforms/Strategies/Detect/HostMatch.php`
- Create: `app/Services/Platforms/Strategies/Detect/ServiceMatch.php`
- Modify: `app/Services/Platforms/Registry/PlatformDescriptor.php` — add `detect()` / `detection()`.
- Modify: `app/Providers/PlatformRegistryServiceProvider.php` — wire 7 detection strategies.
- Modify (rewrite): `app/Services/Platforms/ProviderDetector.php`
- Modify: `tests/Feature/Platforms/IntegrationCategoriesTest.php` — add an events-detection case.

**Interfaces:**
- Produces: `Detection::matches(string $url): bool`; `HostMatch(string $pattern)`; `ServiceMatch(Closure(string):bool $matcher)`; `PlatformDescriptor::detect(Detection): self` / `detection(): ?Detection`; `ProviderDetector::__construct(PlatformRegistry)`.
- Consumes: `PlatformRegistry::all()`, `PlatformCategory::tryFrom()`, `PlatformDescriptor::getCategory()` / `key()` / `detection()`, `PlatformInput::urlish()`, the existing `OpenTableService::isOpenTableUrl`, `ResDiaryService::isResDiaryUrl`, `NowBookitService::isNowBookitUrl`.

- [ ] **Step 1: Verify the existing detector tests are the parity guard, and add the events gap**

`IntegrationCategoriesTest` + `ReservationProvidersTest` already pin `detectFor` for booking/reservations/online-ordering/unknown. `detectFor('events', ...)` is currently untested. In `tests/Feature/Platforms/IntegrationCategoriesTest.php`, add (alongside the existing `$detector = app(ProviderDetector::class);` blocks):

```php
it('detects events providers by host (eventbrite / humanitix), custom otherwise', function () {
    $detector = app(ProviderDetector::class);
    expect($detector->detectFor('events', 'https://www.eventbrite.com.au/e/show-123'))->toBe('eventbrite');
    expect($detector->detectFor('events', 'https://events.humanitix.com/my-gig'))->toBe('humanitix');
    expect($detector->detectFor('events', 'https://meetup.com/group'))->toBeNull(); // unknown → custom
});
```

- [ ] **Step 2: Run the detector tests — expect the new events case to PASS against the current detector (baseline) and everything else green**

Run: `composer test -- --filter='IntegrationCategoriesTest|ReservationProvidersTest'`
Expected: PASS (the current `CATEGORY_PROVIDERS` already maps `events` → `['eventbrite','humanitix']`, so the new assertion characterizes existing behaviour). This is the contract the rewrite must keep.

- [ ] **Step 3: Create the `Detection` contract**

```php
<?php

namespace App\Services\Platforms\Strategies\Contracts;

// Whether a pasted URL belongs to this platform, for the smart-detect categories
// (booking / reservations / events). Host-level only — the provider's own connect
// endpoint does the strict path/rid validation.
interface Detection
{
    public function matches(string $url): bool;
}
```

- [ ] **Step 4: Create `HostMatch`**

```php
<?php

namespace App\Services\Platforms\Strategies\Detect;

use App\Services\Platforms\Strategies\Contracts\Detection;

// Host-regex URL detection. Mirrors ProviderDetector::matches()'s host arms
// (fresha, square, eventbrite, humanitix) EXACTLY — same parse_url + strtolower +
// pattern.
final readonly class HostMatch implements Detection
{
    public function __construct(private string $pattern) {}

    public function matches(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return (bool) preg_match($this->pattern, $host);
    }
}
```

- [ ] **Step 5: Create `ServiceMatch`**

```php
<?php

namespace App\Services\Platforms\Strategies\Detect;

use App\Services\Platforms\Strategies\Contracts\Detection;
use Closure;

// Delegates URL detection to a platform service's own matcher (OpenTable /
// ResDiary / NowBookit isXUrl). Mirrors ProviderDetector::matches()'s
// service-delegating arms — the full (urlish'd) URL is passed through unchanged.
final readonly class ServiceMatch implements Detection
{
    /** @param Closure(string):bool $matcher */
    public function __construct(private Closure $matcher) {}

    public function matches(string $url): bool
    {
        return ($this->matcher)($url);
    }
}
```

- [ ] **Step 6: Add `detect()` / `detection()` to the descriptor**

In `app/Services/Platforms/Registry/PlatformDescriptor.php`:

```php
use App\Services\Platforms\Strategies\Contracts\Detection;
```
```php
private ?Detection $detection = null;
```
```php
/** Attach the smart-detect URL matcher (booking/reservations/events providers). */
public function detect(Detection $detection): self
{
    $this->detection = $detection;

    return $this;
}

public function detection(): ?Detection
{
    return $this->detection;
}
```

- [ ] **Step 7: Wire the 7 detection strategies in the provider**

In `app/Providers/PlatformRegistryServiceProvider.php`, after the picker/booking/reservations + events descriptors are registered. Registration order encodes priority (fresha before square; opentable, resdiary, nowbookit; eventbrite, humanitix) — matching the current `CATEGORY_PROVIDERS` order.

```php
use App\Services\Platforms\NowBookitService;
use App\Services\Platforms\OpenTableService;
use App\Services\Platforms\ResDiaryService;
use App\Services\Platforms\Strategies\Detect\HostMatch;
use App\Services\Platforms\Strategies\Detect\ServiceMatch;
```
```php
// ── Smart-detect matchers (Plan 6). Registration order = detection priority. ──
// Booking: fresha host (mirrors ConnectFreshaRequest), then Square (squareup.com / *.square.site).
$r->get('fresha')->detect(new HostMatch('~(^|\.)fresha\.com$~'));
$r->get('square')->detect(new HostMatch('~(^|\.)(squareup\.com|square\.site)$~'));
// Reservations: keyless widgets delegate to their service's isXUrl matcher.
$openTable = $this->app->make(OpenTableService::class);
$resDiary = $this->app->make(ResDiaryService::class);
$nowBookit = $this->app->make(NowBookitService::class);
$r->get('opentable')->detect(new ServiceMatch(fn (string $u) => $openTable->isOpenTableUrl($u)));
$r->get('resdiary')->detect(new ServiceMatch(fn (string $u) => $resDiary->isResDiaryUrl($u)));
$r->get('nowbookit')->detect(new ServiceMatch(fn (string $u) => $nowBookit->isNowBookitUrl($u)));
// Events: Eventbrite has regional TLDs; Humanitix is single-domain.
$r->get('eventbrite')->detect(new HostMatch('~(^|\.)eventbrite\.[a-z.]+$~'));
$r->get('humanitix')->detect(new HostMatch('~(^|\.)humanitix\.com$~'));
```

- [ ] **Step 8: Rewrite `ProviderDetector`**

Replace the entire file. Note: `PlatformInput::urlish()` is applied once up front (as today); `tryFrom()` returns null for an unknown category (the old code returned null via the empty-array lookup — behaviourally identical). The pseudo-platform descriptors (`booking`/`reservations`/`online-ordering`/`events-custom`) share these categories but carry NO detection, so they are naturally skipped.

```php
<?php

namespace App\Services\Platforms;

use App\Services\Platforms\Registry\PlatformCategory;
use App\Services\Platforms\Registry\PlatformRegistry;

// Maps a pasted URL to the known provider for a smart-detect category, or null
// when nothing matches (→ the custom-link fallback). Registry-driven: a category's
// candidate providers are the registered descriptors in that category that carry a
// Detection strategy, tried in registration order (= priority). Adding a provider
// is a descriptor + a ->detect(...) line in PlatformRegistryServiceProvider — no
// edit here. Detection is host-level only; the provider's connect endpoint does
// the strict path/rid validation.
class ProviderDetector
{
    public function __construct(private readonly PlatformRegistry $registry) {}

    /** The known provider for a URL within a category, or null (custom fallback). */
    public function detectFor(string $category, string $url): ?string
    {
        $cat = PlatformCategory::tryFrom($category);
        if ($cat === null) {
            return null;
        }

        $url = PlatformInput::urlish($url);

        foreach ($this->registry->all() as $descriptor) {
            $detection = $descriptor->detection();
            if ($detection !== null
                && $descriptor->getCategory() === $cat
                && $detection->matches($url)) {
                return $descriptor->key();
            }
        }

        return null;
    }
}
```

- [ ] **Step 9: Run the detector tests + the consumers' tests + golden master**

Run: `composer test -- --filter='IntegrationCategoriesTest|ReservationProvidersTest|IntegrationContractGoldenMasterTest'`
Expected: PASS. If a reservation case regresses, the likely cause is registration order — confirm `opentable` is registered before `resdiary` before `nowbookit` in the provider.

- [ ] **Step 10: Full suite + pint, then commit**

Run: `php artisan pint app/Services/Platforms/Strategies/Contracts/Detection.php app/Services/Platforms/Strategies/Detect/ app/Services/Platforms/ProviderDetector.php app/Services/Platforms/Registry/PlatformDescriptor.php app/Providers/PlatformRegistryServiceProvider.php && composer test`
Expected: PASS.

```bash
git add app/Services/Platforms/Strategies/Contracts/Detection.php app/Services/Platforms/Strategies/Detect/ app/Services/Platforms/ProviderDetector.php app/Services/Platforms/Registry/PlatformDescriptor.php app/Providers/PlatformRegistryServiceProvider.php tests/Feature/Platforms/IntegrationCategoriesTest.php
git commit -m "refactor(integrations): registry-driven ProviderDetector via a Detection seam (Plan 6) — delete CATEGORY_PROVIDERS + match()"
```

---

## Task 6: Verify the write-gate coverage (PlatformInRegistry / dropped-CHECK safety)

The DROP CONSTRAINT (Task 7) removes the DB-level guard against a bad `platform` write. This task *proves* the app-level gate fully covers it — and documents that no Form Request wiring is needed, because no integration Form Request accepts a user-supplied `platform` value. This is verification + documentation, not new wiring (per "Reality vs. spec" item 2). No production behaviour changes.

**Files:** none (verification) — optionally a short note in the migration's header comment (Task 7).

- [ ] **Step 1: Confirm there is no user-supplied integration `platform` Form Request**

Run:
```bash
grep -rn "PlatformInRegistry" app/ tests/
grep -rln "'platform'" app/Http/Requests/Platforms/
grep -rn "route('platform')\|input('platform')\|request('platform')" app/Http/Controllers/Api/Platforms/
```
Expected: `PlatformInRegistry` is referenced only by `app/Rules/PlatformInRegistry.php` + its own test; no `Http/Requests/Platforms/*` validates a `platform` field; the only `route('platform')` read is `GenericPlatformController` (a route *default*, app-controlled). This confirms the write surface.

- [ ] **Step 2: Confirm the two real write gates exist and are tested**

- `GenericPlatformController::descriptor()` does `abort_if($this->registry->get($this->platform()) === null, 404)` — a bad platform default cannot write. (Read `app/Http/Controllers/Api/Platforms/GenericPlatformController.php`.)
- `RefreshController` now gates on `registry->isRefreshable()` (Task 4) — the one user-influenced `{platform}` param.
- `RegistryCoverageTest` asserts the registry's key set equals the 36 platforms the app stores (the set the dropped CHECK used to enforce).

Run: `composer test -- --filter='RegistryCoverageTest|PlatformInRegistryRuleTest'`
Expected: PASS.

- [ ] **Step 3: Record the finding (no code change)**

No edit/commit for this task on its own. The conclusion — "the registry coverage test + `GenericPlatformController`'s 404 gate + `RefreshController`'s registry check fully replace the dropped CHECK; `PlatformInRegistry` remains the ready-made rule for any *future* endpoint that accepts a user-supplied platform key, of which there are none today" — is carried into the Task 7 migration header comment. Proceed to Task 7.

---

## Task 7: DROP the `platform` CHECK constraint (raw SQL migration — BLOCKER, DB change)

The registry is now the gate (Task 6). Drop the per-platform CHECK so adding platform #37 needs zero migration. **Authoring writes the `.sql` file only; the push to Supabase dev happens in the Ship section.** This is a DB/migration change → BLOCKER: present the file + dry-run plan and wait for Josh's sign-off before the push.

**Files:**
- Create: `supabase/migrations/20260629120000_drop_platform_connections_check.sql`

- [ ] **Step 1: Confirm the constraint name + current definition**

Run:
```bash
grep -rh "platform_connections_platform_check" supabase/migrations/ | tail -3
```
Expected: the latest definer is `supabase/migrations/20260622120000_allow_events_custom_platform.sql`, constraint name `platform_connections_platform_check`. Also confirm the chosen filename timestamp sorts after every existing migration: `ls supabase/migrations/*.sql | tail -3` (latest at authoring is `20260625000000_create_supabase_email_events.sql`; `20260629120000` is safely later). If the dev DB has drift (per the migration-drift note in memory), run `supabase migration list` during Ship and adjust the timestamp if needed so it applies cleanly.

- [ ] **Step 2: Write the migration**

```sql
-- Drop the per-platform CHECK on site.platform_connections.
--
-- The CHECK was application-level config masquerading as schema — six migrations
-- of appended platform strings (the last: 20260622120000_allow_events_custom_platform).
-- The PlatformRegistry is now the single source of truth for what a platform is,
-- so the registry (app-level) is the gate and the CHECK is redundant churn. Adding
-- platform #37 is now one descriptor in PlatformRegistryServiceProvider — zero
-- migration.
--
-- Why this is safe without a DB-level replacement (pre-customer blast radius):
--   * Writes use app-controlled platform constants / route defaults; the only
--     user-influenced value (RefreshController's {platform}) is gated on the
--     registry's refreshable set.
--   * GenericPlatformController::descriptor() 404s on any platform not in the registry.
--   * tests/Feature/Platforms/Registry/RegistryCoverageTest.php asserts the registry
--     key set == the platforms the app stores — the job the CHECK used to do.
--   * SQLite (CI) never enforced this CHECK, so the suite is unaffected; verify the
--     drop against the live dev Postgres (CLAUDE.md SQLite-vs-Postgres caveat).
--
-- guard:no-unsafe-migrations:disable-file
-- Exempt: DROP CONSTRAINT takes a brief ACCESS EXCLUSIVE lock on a table holding a
-- handful of pre-beta rows — harmless. No data is read or rewritten.

ALTER TABLE site.platform_connections
    DROP CONSTRAINT IF EXISTS platform_connections_platform_check;
```

- [ ] **Step 3: Confirm the Laravel-migration guard does not flag it (raw SQL, correct directory)**

Run: `composer test -- --filter=guard` (or the project's `guard:no-laravel-migrations` check, e.g. `php artisan guard:no-laravel-migrations` if exposed). Expected: PASS — this is a `supabase/migrations/*.sql` file, not a Laravel migration.

- [ ] **Step 4: Commit the migration (file only — NOT applied yet)**

```bash
git add supabase/migrations/20260629120000_drop_platform_connections_check.sql
git commit -m "feat(db): drop platform_connections_platform_check (Plan 6) — registry is the gate; applied to dev at ship"
```

---

## Task 8: Dead-code sweep — verify, document, delete only what is truly dead

The spec's §14 imagined deleting ~17 thin controllers. That collapse never happened (see "Reality vs. spec" item 1). This task *verifies* the current state and documents it, so a future reader doesn't re-attempt the deletion. The only genuinely dead production code is already removed by Tasks 3–4 (`PlatformRefresher`'s `match()` + private methods + `REFRESHABLE`; `ProviderDetector`'s `CATEGORY_PROVIDERS` + `match()`). **Delete no controllers.**

**Files:** none (verification); the documented finding lands in the plan's completion notes / commit message only.

- [ ] **Step 1: Prove every per-platform controller is still routed**

Run:
```bash
for c in Apple Bandcamp Deezer Pinterest Soundcloud Spotify Twitch Vimeo Youtube YoutubeMusic Skool Strava OpenTable ResDiary NowBookit Eventbrite Humanitix CustomLinks Booking Reservations OnlineOrdering Events Menu GoogleBusiness Fresha Square Shop Instagram; do
  printf '%s: ' "$c"; grep -c "${c}Controller::class" routes/api/integrations.php;
done
```
Expected: every controller has ≥1 route reference. (`connect()`/`recent`/`highlights` stayed on thin controllers; only read paths moved to `GenericPlatformController`.) → **no controller is dead.**

- [ ] **Step 2: Prove `SingleSelectionPlatformController` is still a live base class**

Run: `grep -rln "extends SingleSelectionPlatformController" app/`
Expected: 14 controllers. → base class stays.

- [ ] **Step 3: Confirm no orphaned trait path**

Run: `grep -rn "PlatformRefresher\|REFRESHABLE" app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php`
Expected: only a prose comment at the multi-account section (line ~172) referencing the per-row model keeping "PlatformRefresher + the public integrations endpoint working" — a description, not a dead code path. Leave it (optionally reword to "the registry-driven refresher"). No method is dead.

- [ ] **Step 4: Confirm the deleted-code is gone and nothing dangles**

Run:
```bash
grep -rn "CATEGORY_PROVIDERS\|private function .*Payload\|public const REFRESHABLE" app/Services/Platforms/
grep -rn "REFRESHABLE" app/ tests/ routes/
```
Expected: no output (Tasks 3–4 removed all of it; no references survive).

- [ ] **Step 5: Document the finding (no commit needed)**

Record in the plan's completion note: "No per-platform controllers or trait paths were dead; the spec's §14 'delete ~17 controllers' was superseded by the Plans 3–5 split that kept `connect()` on thin controllers to freeze the 52-route contract. Dead code removed this plan: `PlatformRefresher` match()/private methods/REFRESHABLE and `ProviderDetector` CATEGORY_PROVIDERS/match()." No further deletions.

---

## Ship

The final cutover deploy — the ONLY plan in the registry-redesign series that pushes a migration to Supabase dev. Run from the **main checkout** (not a worktree — feature tests are flaky in worktrees; see the worktree note in memory) on branch `plan6/...`.

- [ ] **Step 1: Whole suite green + pint clean**

```bash
composer test
php artisan pint --test
```
Expected: PASS / no style diffs. Pay special attention to `IntegrationContractGoldenMasterTest` (the frozen API contract), `RefreshPlatformConnectionsCommandTest`, all `*FetchParityTest`, `RefresherCutoverParityTest`, `RegistryCoverageTest`, `IntegrationCategoriesTest`, `ReservationProvidersTest`.

- [ ] **Step 2: Open the PR into `development`, get review, merge**

Follow the normal flow (CLAUDE.md): PR → review → merge into `development`. Do NOT promote to `production` (prod env is stopped / on the pre-standalone schema). The two BLOCKER tasks (5, 7) need Josh's explicit sign-off before merge.

- [ ] **Step 3: Apply the DROP CONSTRAINT migration to Supabase dev**

The env's deploy command has `migrate --force` commented out, so the migration does NOT auto-run on deploy — apply it manually against the dev ref. Josh runs the interactive link with the `!` prefix:

```
! supabase link --project-ref glncumufgaqcmqhzwrxm
```
Then:
```bash
supabase migration list                 # reconcile against drift first (see memory: dev has applied-but-unrepo'd migrations)
supabase db push --dry-run              # show exactly what will run — expect ONLY 20260629120000_drop_platform_connections_check.sql
supabase db push                        # apply the DROP
```
Expected dry-run: a single statement, the `ALTER TABLE ... DROP CONSTRAINT IF EXISTS platform_connections_platform_check`. If the dry-run lists *other* pending migrations, STOP and reconcile drift (`supabase migration repair` per the drift runbook) before pushing — do not ship unrelated migrations.

- [ ] **Step 4: Verify on the live dev DB**

```bash
supabase db push --dry-run              # now clean (nothing pending)
```
And confirm the constraint is gone (Supabase SQL editor / MCP):
```sql
SELECT conname FROM pg_constraint
WHERE conrelid = 'site.platform_connections'::regclass AND contype = 'c';
```
Expected: `platform_connections_platform_check` is absent. Optionally smoke-test the daily cron on the env: `cloud command:run development "php artisan integrations:refresh --limit=5"` and tail `cloud env:logs partna development --minutes 5` for any `integrations.refresh.bad_shape` surprises.

- [ ] **Step 5: Branch cleanup**

After merge, delete the branch (local + remote) and any worktree in one action (per memory: post-merge cleanup is atomic, no asking).

---

## Self-Review (run before handing off)

**1. Spec coverage (design §10 step 4 + §14):**
- "PlatformRefresher match() → registry iteration" → Tasks 1–4 (strategies built, refresher rewritten, command/controller re-pointed). ✓
- "ProviderDetector → registry-driven" → Task 5 (Detection seam). ✓
- "DROP CONSTRAINT migration" → Task 7 (raw SQL) + Ship step 3 (apply). ✓
- "remove now-dead trait paths / delete controllers" → Task 8 (verified: none dead; documented the divergence). ✓ (Honest deviation from the §14 sketch, called out up front.)
- Validation/coverage test guards the gate → Task 6 + `RegistryCoverageTest`. ✓

**2. Placeholder scan:** No "TBD"/"add error handling"/"similar to Task N". Every code step shows complete code; every test step shows the assertions. ✓

**3. Type consistency:** `refreshStrategy(): RefreshStrategy` (Task 2) consumed in Task 3; `isRefreshable(string): bool` (Task 2) consumed in Task 4; `Detection::matches(string): bool` + `detect()/detection()` (Task 5) consistent across descriptor + provider + detector; `FetchShapeException`/`FetchUnavailableException` caught as the two specific subclasses everywhere. The 3 new fetch strategies return `array` and throw the two exceptions, matching the existing `FetchStrategy` contract and the `RefresherCutoverParityTest` expectations. ✓

**4. Parity-before-deletion:** The 3 new strategies (Task 1) + `RefresherCutoverParityTest` (Task 3 step 1, characterized green against the OLD refresher first) prove identical per-bucket outcomes BEFORE Task 3 deletes the `match()`. The detector rewrite (Task 5) is guarded by the existing `IntegrationCategoriesTest`/`ReservationProvidersTest` + the added events case, characterized green before the rewrite. ✓
