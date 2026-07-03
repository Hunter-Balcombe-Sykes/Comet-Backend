# Platform Refresh Plan 3 — Async Link-Connect (JOB-1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Status:** Draft — awaiting Josh's sign-off. **P1 + breaking API contract change → BLOCKER GATE.** Must ship in lockstep with the frontend (see "Frontend Contract Change" below).

**Goal:** Move the synchronous outbound page-fetch off the request thread in the four link-card connect endpoints (audit **#JOB-1**) so a burst of link-adds can't exhaust the PHP-FPM worker pool. Uses the **202 + poll** pattern already established by Instagram connect (chosen by Josh 2026-07-02 as the foundational, consistent approach).

**Architecture:** One shared async pattern applied to all four controllers (not four bespoke ones):
1. The connect action does only fast work — normalise the URL, cheap validation, write a **minimal card** (URL-derived, no HTTP) with status `pending` — then dispatches a shared **`EnrichLinkCardJob`** and returns **202 + a poll URL**.
2. `EnrichLinkCardJob` does the slow `LinkCardScraper::snapshot()` fetch on the queue and upgrades the row's display fields (name/description/favicon/logo), flipping status to `ok`.
3. A dedicated per-resource **status endpoint** reports `pending` / `ready` / `failed`, mirroring Instagram's `connectStatus()`.

**Tech Stack:** PHP 8.2, Laravel 12, Horizon (`scraping` queue), Pest 4. Reuses `ManagesIntegrationConnection`, `LinkCardScraper`, and the Instagram 202 template verbatim.

**Source:** Strategy doc `docs/superpowers/plans/2026-07-01-platform-refresh-scaling-strategy.md` §8 (#JOB-1). Plan 1 + Plan 2 are merged to `development`. Scope locked with Josh 2026-07-02: **Option 2 (202 + poll)**; **four link controllers, Fresha EXCLUDED** (booking not being built — `project_booking_dropped`; Fresha path is test-mode).

**⚠️ Contract note:** the audit lists `FreshaController` too — deliberately out of scope. The live P1 risk is the four `LinkCardScraper::snapshotOrMinimal()` callers.

## Global Constraints

- **NO Laravel migration files** — no schema change. `last_refresh_status` (existing column) carries the `pending`→`ok` lifecycle, exactly as Instagram uses it.
- **`payload` is `NOT NULL` in prod** — never write a null payload. The minimal card is always a full array (verified: the Instagram placeholder writes `[]`, not null, for this reason).
- **Preserve the dedup keys.** The enrich job upgrades DISPLAY fields only (name/description/favicon/logo); it must NOT change the stored `url` (the sync-written normalised input), or OnlineOrdering's `storeKey` dedup and every `resource_id` hash would drift.
- **Dispatch enrich jobs with `->afterCommit()`** so the queued job reads the committed `pending` row (never a typed `public bool $afterCommit` property — trait conflict, see `feedback_job_aftercommit_property`).
- **Authorization unchanged** — writes still go through the concern's policy-gated `writeConnection`/`writePendingLinkCard`. 404 (not 403) for missing resources on these authenticated routes.
- **Tests on SQLite + `sync` queue + `array` cache** (`phpunit.xml`). Fake HTTP with `Http::fake()`; assert `Http::assertNothingSent()` in the sync path and enrichment via the job.
- Run `php artisan pint` on changed files; keep commits surgical.

---

## Frontend Contract Change (REQUIRED coordination — ship together)

The four connect endpoints change from **synchronous 200 (fully-enriched)** to **202 (minimal card) + poll**. The frontend MUST implement the poll or added links will look unenriched until a manual refresh. Task 8 writes this up as a standalone note for the frontend team.

| Endpoint | Before | After |
|---|---|---|
| `POST /custom/links` | 200 `{links:[…fully enriched]}` | 202 `{status:'pending', link:{…minimal}, statusUrl}` |
| `POST /online-ordering/entries` | 200 `{entries:[…]}` | 202 `{status:'pending', entries:[…minimal], statusUrl}` |
| `POST /booking/detect` (custom) | 200 `{provider:'custom', selection:{…}}` | 202 `{provider:'custom', next:'custom-saved', selection:{…minimal}, statusUrl}` |
| `POST /reservations/detect` (custom) | 200 `{provider:'custom', selection:{…}}` | 202 `{provider:'custom', next:'custom-saved', selection:{…minimal}, statusUrl}` |

New `GET` status endpoints return `{status:'pending'}` → `{status:'ready', …}` → `{status:'failed'}`. The `fresha`/`square`/`opentable` detect branches are UNCHANGED (no HTTP, still synchronous 200).

---

## File Structure

**New files:**
- `app/Jobs/Platforms/EnrichLinkCardJob.php` — shared slow-fetch enrichment job.
- `tests/Unit/Jobs/EnrichLinkCardJobTest.php`
- `docs/frontend-contracts/2026-07-02-async-link-connect.md` (Task 8)

**Modified files:**
- `app/Services/Platforms/LinkCardScraper.php` — make `minimal()` public as `minimalCard()`.
- `app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php` — `writePendingLinkCard()` + `linkCardStatusResponse()`.
- `app/Http/Controllers/Api/Platforms/CustomLinksController.php` + test
- `app/Http/Controllers/Api/Platforms/OnlineOrderingController.php` + test
- `app/Http/Controllers/Api/Platforms/BookingController.php` + test
- `app/Http/Controllers/Api/Platforms/ReservationsController.php` + test
- `routes/api/integrations.php` — four new status routes.

---

## Task 1: Expose a synchronous minimal card

**Files:**
- Modify: `app/Services/Platforms/LinkCardScraper.php`
- Test: `tests/Unit/Platforms/LinkCardScraperMinimalTest.php`

**Interfaces:**
- Produces: `LinkCardScraper::minimalCard(string $url): array` (public; URL-derived, NO HTTP). `snapshot()` stays the slow fetch used by the job. Consumed by Tasks 4–7.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/Platforms/LinkCardScraperMinimalTest.php

use App\Services\Platforms\LinkCardScraper;
use Illuminate\Support\Facades\Http;

it('builds a minimal card from the URL with no HTTP', function () {
    Http::fake();
    $card = app(LinkCardScraper::class)->minimalCard('https://www.ubereats.com/store/x');

    expect($card['url'])->toBe('https://www.ubereats.com/store/x')
        ->and($card['name'])->toBe('ubereats.com')
        ->and($card['favicon'])->toContain('google.com/s2/favicons');
    Http::assertNothingSent();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Unit/Platforms/LinkCardScraperMinimalTest.php`
Expected: FAIL — `Method …::minimalCard does not exist`.

- [ ] **Step 3: Make `minimal()` public**

In `app/Services/Platforms/LinkCardScraper.php`, rename the `private function minimal(string $url): array` to `public function minimalCard(string $url): array`, and update its one internal caller in `snapshotOrMinimal()`:

```php
    public function snapshotOrMinimal(string $url): array
    {
        return $this->snapshot($url) ?? $this->minimalCard($url);
    }
```

Keep the existing method body (the `$host`/`$domain`/favicon logic) unchanged — only the visibility and name change. Update the docblock to note it's the synchronous placeholder used by the async connect flow.

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test tests/Unit/Platforms/LinkCardScraperMinimalTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
php artisan pint app/Services/Platforms/LinkCardScraper.php tests/Unit/Platforms/LinkCardScraperMinimalTest.php
git add app/Services/Platforms/LinkCardScraper.php tests/Unit/Platforms/LinkCardScraperMinimalTest.php
git commit -m "feat(links): expose LinkCardScraper::minimalCard for async connect (JOB-1)"
```

---

## Task 2: Shared concern helpers (pending write + status response)

**Files:**
- Modify: `app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php`
- Test: covered indirectly by Tasks 4–7 (the helpers have no behaviour independent of a controller's `platform()`).

**Interfaces:**
- Produces:
  - `writePendingLinkCard(User $user, array $payload, ?string $resourceId = null): IntegrationConnection` — same policy-gated upsert as `writeConnection`, but writes `last_refresh_status => 'pending'` (the enrich job flips it to `ok`). Card is `is_active = true` so it shows immediately.
  - `linkCardStatusResponse(User $user, string $resourceId, callable $whenReady): JsonResponse` — reads the row's `last_refresh_status`; `pending` → `{status:'pending'}`, `ok` → `{status:'ready', ...$whenReady($row)}`, missing → 404, else → `{status:'failed'}`. Mirrors Instagram `connectStatus()`.

- [ ] **Step 1: Add the helpers**

In `app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php`, add these methods (after `writeConnection`). Note the imports `IntegrationConnection`, `User`, `JsonResponse` are already present in the file.

```php
    /**
     * Async-connect variant of writeConnection: writes a usable MINIMAL card
     * immediately with status 'pending', so the connect action can return 202
     * before the slow enrichment fetch runs (JOB-1). EnrichLinkCardJob flips the
     * status to 'ok' once it has upgraded the display fields. Policy-gated exactly
     * like writeConnection (create vs update ability resolved before the upsert).
     */
    protected function writePendingLinkCard(User $user, array $payload, ?string $resourceId = null): IntegrationConnection
    {
        $existing = $this->connectionFor($user, $resourceId);
        if ($existing) {
            $this->authorizeForUser($user, 'update', $existing);
        } else {
            $skeleton = new IntegrationConnection([
                'user_id' => $user->id,
                'platform' => $this->platform(),
                'resource_id' => $resourceId ?? $this->defaultResourceId(),
            ]);
            $this->authorizeForUser($user, 'create', $skeleton);
        }

        return IntegrationConnection::updateOrCreate(
            [
                'user_id' => $user->id,
                'platform' => $this->platform(),
                'resource_id' => $resourceId ?? $this->defaultResourceId(),
            ],
            [
                'payload' => $payload,
                'is_active' => true,
                'last_refreshed_at' => null,
                'last_refresh_status' => 'pending',
                'last_refresh_error' => null,
                'consecutive_failures' => 0,
            ],
        );
    }

    /**
     * Poll response for an async link-card enrichment (JOB-1), mirroring the
     * Instagram connectStatus shape: pending → ready(+data) → failed. 404 when the
     * resource doesn't exist for the caller (never 403 — no existence leak).
     */
    protected function linkCardStatusResponse(User $user, string $resourceId, callable $whenReady): JsonResponse
    {
        $connection = $this->connectionFor($user, $resourceId);
        if (! $connection) {
            return $this->error('Link not found.', 404);
        }

        return match ($connection->last_refresh_status) {
            'pending' => $this->success(['status' => 'pending']),
            'ok' => $this->success(['status' => 'ready', ...$whenReady($connection)]),
            default => $this->success(['status' => 'failed']),
        };
    }
```

- [ ] **Step 2: Static check + commit** (behaviour is exercised by Tasks 4–7)

```bash
php artisan pint app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php
php artisan test tests/Feature/Platforms/ --filter=Custom  # smoke: nothing broke in the concern
git add app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php
git commit -m "feat(links): pending-write + status-poll helpers on the connection concern (JOB-1)"
```

---

## Task 3: The shared `EnrichLinkCardJob`

**Files:**
- Create: `app/Jobs/Platforms/EnrichLinkCardJob.php`
- Test: `tests/Unit/Jobs/EnrichLinkCardJobTest.php`

**Interfaces:**
- Consumes: `LinkCardScraper::snapshot()` (existing).
- Produces: `new EnrichLinkCardJob(string $userId, string $platform, string $resourceId, string $url)`; `handle(LinkCardScraper)` upgrades the row's display fields and sets status `ok`. `ShouldBeUnique` on `{platform}:{resourceId}`.

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Unit/Jobs/EnrichLinkCardJobTest.php

use App\Jobs\Platforms\EnrichLinkCardJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\LinkCardScraper;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function enrichUser(): User
{
    return User::create([
        'handle' => 'en', 'handle_lc' => 'en', 'display_name' => 'En',
        'account_type' => 'individual', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => 'en@example.com',
    ]);
}

it('has the required queue-hygiene properties and unique id', function () {
    $job = new EnrichLinkCardJob('u', 'custom', 'link-abc', 'https://x.com');
    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([30, 120, 300])
        ->and($job->timeout)->toBe(60)
        ->and($job->uniqueId())->toBe('custom:link-abc');
});

it('upgrades display fields from the snapshot and marks the row ok', function () {
    $user = enrichUser();
    $row = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'custom', 'resource_id' => 'link-abc',
        'payload' => ['kind' => 'link', 'url' => 'https://x.com', 'name' => 'x.com', 'favicon' => 'g', 'logo' => null, 'description' => null],
        'last_refresh_status' => 'pending',
    ]);

    $this->mock(LinkCardScraper::class, function ($m) {
        $m->shouldReceive('snapshot')->andReturn([
            'url' => 'https://x.com/final', 'name' => 'The Real Title', 'description' => 'desc',
            'favicon' => 'https://x.com/fav.ico', 'logo' => 'https://x.com/og.png',
        ]);
    });

    (new EnrichLinkCardJob($user->id, 'custom', 'link-abc', 'https://x.com'))->handle(app(LinkCardScraper::class));

    $row->refresh();
    expect($row->last_refresh_status)->toBe('ok')
        ->and($row->payload['name'])->toBe('The Real Title')
        ->and($row->payload['logo'])->toBe('https://x.com/og.png')
        ->and($row->payload['url'])->toBe('https://x.com')   // URL preserved — dedup keys intact
        ->and($row->payload['kind'])->toBe('link');           // controller field preserved
});

it('leaves the minimal card and marks ok when the snapshot fails', function () {
    $user = enrichUser();
    $row = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'custom', 'resource_id' => 'link-abc',
        'payload' => ['kind' => 'link', 'url' => 'https://x.com', 'name' => 'x.com', 'favicon' => 'g', 'logo' => null, 'description' => null],
        'last_refresh_status' => 'pending',
    ]);

    $this->mock(LinkCardScraper::class, fn ($m) => $m->shouldReceive('snapshot')->andReturnNull());

    (new EnrichLinkCardJob($user->id, 'custom', 'link-abc', 'https://x.com'))->handle(app(LinkCardScraper::class));

    $row->refresh();
    expect($row->last_refresh_status)->toBe('ok')       // minimal card is an acceptable final state
        ->and($row->payload['name'])->toBe('x.com');
});

it('no-ops when the row is gone', function () {
    $user = enrichUser();
    $this->mock(LinkCardScraper::class, fn ($m) => $m->shouldReceive('snapshot')->never());

    (new EnrichLinkCardJob($user->id, 'custom', 'missing', 'https://x.com'))->handle(app(LinkCardScraper::class));
})->throwsNoExceptions();
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test tests/Unit/Jobs/EnrichLinkCardJobTest.php`
Expected: FAIL — `Class "App\Jobs\Platforms\EnrichLinkCardJob" not found`.

- [ ] **Step 3: Implement the job**

```php
<?php
// app/Jobs/Platforms/EnrichLinkCardJob.php

namespace App\Jobs\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\LinkCardScraper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

// Slow-fetch enrichment for an async link-card connect (JOB-1). The connect action
// writes a usable MINIMAL card synchronously (status 'pending') and returns 202;
// this job runs snapshot() (the outbound HTTP that used to block the request thread)
// on the queue and upgrades ONLY the display fields — name/description/favicon/logo —
// leaving the stored url untouched so resource_id / storeKey dedup stays stable. A
// failed snapshot is fine: the minimal card is an acceptable final state, so the row
// still flips to 'ok'. Shared by custom links, online-ordering, booking, reservations.
class EnrichLinkCardJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public function __construct(
        public string $userId,
        public string $platform,
        public string $resourceId,
        public string $url,
    ) {
        $this->onQueue(config('partna.queues.scraping'));
    }

    public function uniqueId(): string
    {
        return $this->platform.':'.$this->resourceId;
    }

    public function handle(LinkCardScraper $scraper): void
    {
        $row = IntegrationConnection::query()
            ->where('user_id', $this->userId)
            ->where('platform', $this->platform)
            ->where('resource_id', $this->resourceId)
            ->first();

        if ($row === null) {
            return; // removed between dispatch and run
        }

        $snapshot = $scraper->snapshot($this->url); // slow HTTP; null on failure

        $payload = $row->payload;
        if ($snapshot !== null) {
            // Upgrade DISPLAY fields only — never the stored url (keeps dedup keys stable).
            foreach (['name', 'description', 'favicon', 'logo'] as $field) {
                if (($snapshot[$field] ?? null) !== null) {
                    $payload[$field] = $snapshot[$field];
                }
            }
        }

        $row->update([
            'payload' => $payload,
            'last_refreshed_at' => now(),
            'last_refresh_status' => 'ok',
        ]);
    }
}
```

- [ ] **Step 4: Run to verify they pass + hygiene gate**

Run: `php artisan test tests/Unit/Jobs/EnrichLinkCardJobTest.php tests/Feature/Queue/JobHygienePolicyTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
php artisan pint app/Jobs/Platforms/EnrichLinkCardJob.php tests/Unit/Jobs/EnrichLinkCardJobTest.php
git add app/Jobs/Platforms/EnrichLinkCardJob.php tests/Unit/Jobs/EnrichLinkCardJobTest.php
git commit -m "feat(links): shared EnrichLinkCardJob (off-thread snapshot enrichment) (JOB-1)"
```

---

## Task 4: CustomLinks async connect

**Files:**
- Modify: `app/Http/Controllers/Api/Platforms/CustomLinksController.php`
- Modify: `routes/api/integrations.php`
- Test: `tests/Feature/Platforms/CustomLinksControllerTest.php` (add async cases)

**Interfaces:**
- Consumes: `writePendingLinkCard`, `linkCardStatusResponse` (Task 2), `EnrichLinkCardJob`, `minimalCard` (Task 1).
- Produces: `POST /custom/links` → 202; new `GET /custom/links/{id}/status`.

- [ ] **Step 1: Write the failing tests**

```php
use App\Jobs\Platforms\EnrichLinkCardJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('addLink returns 202 with a minimal card and no outbound HTTP', function () {
    Queue::fake();
    Http::fake();
    // ... auth as a user (reuse the file's existing auth helper) ...

    $res = $this->postJson('/api/integrations/custom/links', ['url' => 'https://www.example.com/x']);

    $res->assertStatus(202)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.link.name', 'example.com');
    Http::assertNothingSent();
    Queue::assertPushed(EnrichLinkCardJob::class,
        fn ($j) => $j->platform === 'custom' && str_starts_with($j->resourceId, 'link-'));
});

it('status endpoint reports ready once the row is ok', function () {
    // seed a 'custom' link-... row with last_refresh_status 'ok', then:
    $res = $this->getJson("/api/integrations/custom/links/{$rid}/status");
    $res->assertOk()->assertJsonPath('data.status', 'ready');
});
```

*(Reuse the existing test file's user-auth setup; if none, follow the auth pattern in `tests/Feature/Platforms/InstagramConnect*Test.php`.)*

- [ ] **Step 2: Run to verify they fail** — `php artisan test tests/Feature/Platforms/CustomLinksControllerTest.php` → FAIL (still 200, still fetches).

- [ ] **Step 3: Rewrite `addLink` + add `linkStatus`**

Replace `addLink` and add `linkStatus` in `CustomLinksController.php` (imports: add `use App\Jobs\Platforms\EnrichLinkCardJob;`):

```php
    // POST /api/platforms/custom/links — attach a URL. Returns 202 immediately with
    // a minimal card; EnrichLinkCardJob upgrades name/logo off-thread (JOB-1).
    public function addLink(AddCustomLinkRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $url = $this->scraper->normalizeUrl($request->validated()['url']);
        if (! $url) {
            return $this->error('Enter a valid link (https://...).', 422);
        }

        $payload = ['kind' => 'link', ...$this->scraper->minimalCard($url)];
        $rid = 'link-'.substr(sha1(strtolower($url)), 0, 16);

        return $this->withConnectionLock($user, function () use ($user, $payload, $rid, $url) {
            $existing = $this->linkRows($user)->firstWhere('resource_id', $rid);
            if (! $existing && $this->linkRows($user)->count() >= self::MAX_LINKS) {
                return $this->error('You can add up to '.self::MAX_LINKS.' links.', 422);
            }

            $this->writePendingLinkCard($user, $payload, $rid);
            EnrichLinkCardJob::dispatch((string) $user->id, $this->platform(), $rid, $url)->afterCommit();

            return $this->success([
                'status' => 'pending',
                'link' => $this->cardData($rid, $payload),
                'statusUrl' => url("/api/integrations/custom/links/{$rid}/status"),
            ], 202);
        });
    }

    // GET /api/platforms/custom/links/{id}/status — poll link-card enrichment.
    public function linkStatus(Request $request, string $id): JsonResponse
    {
        return $this->linkCardStatusResponse($this->currentUser($request), $id, fn () => [
            'links' => $this->linksData($this->currentUser($request)),
        ]);
    }
```

Add a small `cardData` shaper (mirrors one row of `linksData`) so the 202 body carries the minimal card:

```php
    /** @return array<string,mixed> */
    private function cardData(string $rid, array $payload): array
    {
        $card = \App\Services\Platforms\Payloads\CardPayload::fromArray($payload);

        return ['id' => $rid, 'url' => $card->url(), 'name' => $card->name(),
            'description' => $card->description(), 'favicon' => $card->favicon(), 'logo' => $card->logo()];
    }
```

- [ ] **Step 4: Add the route**

In `routes/api/integrations.php`, in the `custom` prefix group, add after the `POST /links` route:

```php
            Route::get('/links/{id}/status', [CustomLinksController::class, 'linkStatus'])->where('id', '[A-Za-z0-9._-]+');
```

- [ ] **Step 5: Run to verify they pass** — `php artisan test tests/Feature/Platforms/CustomLinksControllerTest.php` → PASS.

- [ ] **Step 6: Commit**

```bash
php artisan pint app/Http/Controllers/Api/Platforms/CustomLinksController.php routes/api/integrations.php tests/Feature/Platforms/CustomLinksControllerTest.php
git add app/Http/Controllers/Api/Platforms/CustomLinksController.php routes/api/integrations.php tests/Feature/Platforms/CustomLinksControllerTest.php
git commit -m "feat(links): async custom-links connect (202 + poll) (JOB-1)"
```

---

## Task 5: OnlineOrdering async connect

**Files:**
- Modify: `app/Http/Controllers/Api/Platforms/OnlineOrderingController.php`
- Modify: `routes/api/integrations.php`
- Test: `tests/Feature/Platforms/OnlineOrderingControllerTest.php`

**Interfaces:** `POST /online-ordering/entries` → 202; new `GET /online-ordering/entries/{id}/status`.

**Design:** the merge-on-add / MAX_ENTRIES / storeKey / mode logic is all URL-derived — it stays synchronous, driven by `minimalCard($url)` instead of `snapshotOrMinimal($url)`. `MenuFetchJob` still dispatches synchronously (it only needs the URLs, and it's already a queued job). Only the metadata snapshot moves to `EnrichLinkCardJob`.

- [ ] **Step 1: Write the failing tests**

```php
use App\Jobs\Platforms\EnrichLinkCardJob;
use App\Jobs\Platforms\MenuFetchJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('addEntry returns 202, sends no HTTP, and dispatches enrich + menu jobs', function () {
    Queue::fake();
    Http::fake();
    // ... auth ...

    $res = $this->postJson('/api/integrations/online-ordering/entries', ['url' => 'https://www.ubereats.com/store/x']);

    $res->assertStatus(202)->assertJsonPath('data.status', 'pending');
    Http::assertNothingSent();
    Queue::assertPushed(EnrichLinkCardJob::class);
    Queue::assertPushed(MenuFetchJob::class);
});

it('still enforces MAX_ENTRIES synchronously (422 before any job)', function () {
    Queue::fake();
    // seed 10 entries, then attempt an 11th distinct store
    $res = $this->postJson('/api/integrations/online-ordering/entries', ['url' => 'https://www.doordash.com/store/z']);
    $res->assertStatus(422);
    Queue::assertNotPushed(EnrichLinkCardJob::class);
});
```

- [ ] **Step 2: Run to verify they fail.**

- [ ] **Step 3: Rewrite `addEntry` + add `entryStatus`**

In `OnlineOrderingController.php` change the import (`use App\Jobs\Platforms\EnrichLinkCardJob;`) and replace `addEntry`'s fetch + return. The merge block is unchanged except: `snapshotOrMinimal` → `minimalCard`, `writeConnection` → `writePendingLinkCard`, dispatch `EnrichLinkCardJob` for the written rid, and return 202. Because a merge-on-add can target an existing store row OR create a new one, capture the resource id written and enrich that:

```php
    public function addEntry(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $validated = $request->validate(['url' => ['required', 'string', 'max:1000']]);

        $url = $this->scraper->normalizeUrl($validated['url']);
        if (! $url) {
            return $this->error('Enter a valid link (https://...).', 422);
        }
        // Minimal card only — the slow metadata fetch moves to EnrichLinkCardJob (JOB-1).
        $meta = ['url' => $url, ...$this->scraper->minimalCard($url)];
        $mode = $this->modeOf($meta['url']);
        $storeKey = $this->storeKey($meta['url']);

        return $this->withConnectionLock($user, function () use ($user, $meta, $mode, $storeKey, $url) {
            $existing = $storeKey === null ? null : $this->entryRows($user)
                ->first(fn (IntegrationConnection $row) => $this->storeKey(CardPayload::fromArray($row->payload)->url()) === $storeKey);

            if (! $existing && $this->entryRows($user)->count() >= self::MAX_ENTRIES) {
                return $this->error('You can add up to '.self::MAX_ENTRIES.' ordering links.', 422);
            }

            if ($existing) {
                $rid = $existing->resource_id;
                $this->writePendingLinkCard($user, $this->mergeStorePayload(CardPayload::fromArray($existing->payload)->toArray(), $meta, $mode), $rid);
            } else {
                $rid = $this->entryResourceId($meta['url']);
                $this->writePendingLinkCard($user, $this->mergeStorePayload([
                    'id' => $rid, 'provider' => 'custom', 'source' => 'manual', ...$meta,
                ], $meta, $mode), $rid);
            }

            EnrichLinkCardJob::dispatch((string) $user->id, $this->platform(), $rid, $url)->afterCommit();
            MenuFetchJob::dispatch((string) $user->id);

            return $this->success([
                'status' => 'pending',
                'entries' => $this->entriesData($user),
                'statusUrl' => url("/api/integrations/online-ordering/entries/{$rid}/status"),
            ], 202);
        });
    }

    // GET /api/platforms/online-ordering/entries/{id}/status
    public function entryStatus(Request $request, string $id): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->linkCardStatusResponse($user, $id, fn () => ['entries' => $this->entriesData($user)]);
    }
```

Note: `writePendingLinkCard` upserts by `(user, platform, resource_id)` exactly like `writeConnection`, so the merge semantics are preserved.

- [ ] **Step 4: Add the route** — in the `online-ordering` group, after `POST /entries`:

```php
            Route::get('/entries/{id}/status', [OnlineOrderingController::class, 'entryStatus'])->where('id', '[A-Za-z0-9._-]+');
```

- [ ] **Step 5: Run to verify they pass.**

- [ ] **Step 6: Commit**

```bash
php artisan pint app/Http/Controllers/Api/Platforms/OnlineOrderingController.php routes/api/integrations.php tests/Feature/Platforms/OnlineOrderingControllerTest.php
git add app/Http/Controllers/Api/Platforms/OnlineOrderingController.php routes/api/integrations.php tests/Feature/Platforms/OnlineOrderingControllerTest.php
git commit -m "feat(links): async online-ordering connect (202 + poll), merge stays sync (JOB-1)"
```

---

## Task 6: Booking async connect (custom branch only)

**Files:**
- Modify: `app/Http/Controllers/Api/Platforms/BookingController.php`
- Modify: `routes/api/integrations.php`
- Test: `tests/Feature/Platforms/BookingControllerTest.php`

**Interfaces:** the custom branch of `POST /booking/detect` → 202; new `GET /booking/detect/status`. The `fresha`/`square` branches are UNCHANGED (no HTTP).

- [ ] **Step 1: Write the failing tests**

```php
use App\Jobs\Platforms\EnrichLinkCardJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('detect custom returns 202 + minimal card, no HTTP', function () {
    Queue::fake();
    Http::fake();
    // detect a URL the ProviderDetector maps to 'custom' (an arbitrary non-fresha/square link)
    $res = $this->postJson('/api/integrations/booking/detect', ['url' => 'https://www.example.com/book']);

    $res->assertStatus(202)->assertJsonPath('data.provider', 'custom')->assertJsonPath('data.status', 'pending');
    Http::assertNothingSent();
    Queue::assertPushed(EnrichLinkCardJob::class, fn ($j) => $j->platform === 'booking');
});

it('detect fresha stays synchronous 200 (no job)', function () {
    Queue::fake();
    $res = $this->postJson('/api/integrations/booking/detect', ['url' => 'https://www.fresha.com/a/some-salon']);
    $res->assertOk()->assertJsonPath('data.provider', 'fresha');
    Queue::assertNotPushed(EnrichLinkCardJob::class);
});
```

*(Confirm the fresha URL shape the real `ProviderDetector::detectFor('booking', …)` recognises when writing the test.)*

- [ ] **Step 2: Run to verify they fail.**

- [ ] **Step 3: Rewrite the custom branch + add `detectStatus`**

In `BookingController.php` (import `use App\Jobs\Platforms\EnrichLinkCardJob;`), replace the custom-fallback portion of `detect()` (the part after the fresha/square returns):

```php
        // Unknown → custom fallback. Minimal card now; enrich off-thread (JOB-1).
        $url = $this->scraper->normalizeUrl($validated['url']);
        if (! $url) {
            return $this->error('Enter a valid link (https://...).', 422);
        }
        $meta = $this->scraper->minimalCard($url);

        return $this->withConnectionLock($user, function () use ($user, $meta, $url) {
            $this->clearBooking($user);   // single-slot
            $payload = ['provider' => 'custom', 'source' => 'manual', ...$meta];
            $this->writePendingLinkCard($user, $payload);
            EnrichLinkCardJob::dispatch((string) $user->id, $this->platform(), $this->defaultResourceId(), $url)->afterCommit();

            return $this->success([
                'provider' => 'custom',
                'next' => 'custom-saved',
                'status' => 'pending',
                'selection' => $this->shapeCustom($payload),
                'statusUrl' => url('/api/integrations/booking/detect/status'),
            ], 202);
        });
    }

    // GET /api/platforms/booking/detect/status — poll the custom-card enrichment.
    public function detectStatus(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->linkCardStatusResponse($user, $this->defaultResourceId(), fn () => [
            'selection' => $this->shapeCustom($this->readConnection($user) ?? []),
        ]);
    }
```

- [ ] **Step 4: Add the route** — in the `booking` group, after `POST /detect`:

```php
            Route::get('/detect/status', [BookingController::class, 'detectStatus']);
```

- [ ] **Step 5: Run to verify they pass.**

- [ ] **Step 6: Commit**

```bash
php artisan pint app/Http/Controllers/Api/Platforms/BookingController.php routes/api/integrations.php tests/Feature/Platforms/BookingControllerTest.php
git add app/Http/Controllers/Api/Platforms/BookingController.php routes/api/integrations.php tests/Feature/Platforms/BookingControllerTest.php
git commit -m "feat(links): async booking custom-card connect (202 + poll) (JOB-1)"
```

---

## Task 7: Reservations async connect (custom branch only)

**Files:**
- Modify: `app/Http/Controllers/Api/Platforms/ReservationsController.php`
- Modify: `routes/api/integrations.php`
- Test: `tests/Feature/Platforms/ReservationsControllerTest.php`

**Interfaces:** the custom branch of `POST /reservations/detect` → 202; new `GET /reservations/detect/status`. The `opentable`/`resdiary`/`nowbookit` (KEYLESS_PROVIDERS) branch is UNCHANGED.

- [ ] **Step 1: Write the failing tests** — same shape as Task 6, but assert an `opentable` URL stays synchronous 200 and a custom URL returns 202 pushing `EnrichLinkCardJob` with `platform === 'reservations'`.

- [ ] **Step 2: Run to verify they fail.**

- [ ] **Step 3: Rewrite the custom branch + add `detectStatus`**

In `ReservationsController.php` (import `EnrichLinkCardJob`), replace the custom-fallback portion of `detect()` (after the KEYLESS_PROVIDERS return):

```php
        // Unknown → custom fallback. Minimal card now; enrich off-thread (JOB-1).
        $url = $this->scraper->normalizeUrl($validated['url']);
        if (! $url) {
            return $this->error('Enter a valid link (https://...).', 422);
        }
        $meta = $this->scraper->minimalCard($url);

        return $this->withConnectionLock($user, function () use ($user, $meta, $url) {
            $this->clearReservations($user);   // single-slot
            $payload = ['provider' => 'custom', 'source' => 'manual', ...$meta];
            $this->writePendingLinkCard($user, $payload);
            EnrichLinkCardJob::dispatch((string) $user->id, $this->platform(), $this->defaultResourceId(), $url)->afterCommit();

            return $this->success([
                'provider' => 'custom',
                'next' => 'custom-saved',
                'status' => 'pending',
                'selection' => $this->shapeCustom($payload),
                'statusUrl' => url('/api/integrations/reservations/detect/status'),
            ], 202);
        });
    }

    // GET /api/platforms/reservations/detect/status — poll the custom-card enrichment.
    public function detectStatus(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->linkCardStatusResponse($user, $this->defaultResourceId(), fn () => [
            'selection' => $this->shapeCustom($this->readConnection($user) ?? []),
        ]);
    }
```

- [ ] **Step 4: Add the route** — in the `reservations` group, after `POST /detect`:

```php
            Route::get('/detect/status', [ReservationsController::class, 'detectStatus']);
```

- [ ] **Step 5: Run to verify they pass.**

- [ ] **Step 6: Commit**

```bash
php artisan pint app/Http/Controllers/Api/Platforms/ReservationsController.php routes/api/integrations.php tests/Feature/Platforms/ReservationsControllerTest.php
git add app/Http/Controllers/Api/Platforms/ReservationsController.php routes/api/integrations.php tests/Feature/Platforms/ReservationsControllerTest.php
git commit -m "feat(links): async reservations custom-card connect (202 + poll) (JOB-1)"
```

---

## Task 8: Frontend contract note + full-suite gate

**Files:**
- Create: `docs/frontend-contracts/2026-07-02-async-link-connect.md`

- [ ] **Step 1: Write the frontend note**

Create `docs/frontend-contracts/2026-07-02-async-link-connect.md` documenting, for each of the four endpoints: the new 202 response shape, the `statusUrl` to poll, the `{status: pending|ready|failed}` contract, a suggested poll cadence (e.g. every 1.5s, cap ~20s), and that the minimal card is safe to render immediately (it upgrades on `ready`). Include the unchanged branches (`fresha`/`square`/`opentable` stay 200). Use the table from this plan's "Frontend Contract Change" section as the summary.

- [ ] **Step 2: Full suite**

Run: `composer test`
Expected: PASS — full suite green in the main checkout (per `feedback_namespace_relocation_short_refs`).

- [ ] **Step 3: Route sanity**

Run: `php artisan route:list --path=integrations | grep status`
Expected: the four new `.../status` routes are registered.

- [ ] **Step 4: Commit**

```bash
git add docs/frontend-contracts/2026-07-02-async-link-connect.md
git commit -m "docs(links): async link-connect frontend contract note (JOB-1)"
```

---

## Self-Review

**1. Spec coverage:** JOB-1 "move outbound HTTP off the request thread via a queued job + 202 + poll (Instagram pattern)" → the four `snapshotOrMinimal` callers now do `minimalCard` (sync) + `EnrichLinkCardJob` (async) + 202 + status endpoint (Tasks 4–7). Fresha explicitly out of scope (documented). URL normalisation stays synchronous (per the finding). ✓

**2. Placeholder scan:** shared code (Tasks 1–3) is fully specified; per-controller tasks give complete method bodies. The two soft spots — the test files' existing auth helper and the exact `ProviderDetector` URL shapes — are explicitly flagged to confirm at implementation time, with the behavioural assertions pinned. ✓

**3. Type consistency:** `EnrichLinkCardJob(userId, platform, resourceId, url)` constructed identically in Tasks 4–7. `writePendingLinkCard(user, payload, ?resourceId)` and `linkCardStatusResponse(user, resourceId, callable)` (Task 2) used identically across all four controllers. `minimalCard(url)` (Task 1) used in all four. Status contract `pending|ready|failed` uniform. Enrich preserves `url` (dedup-safe) — asserted in Task 3. ✓

**Deferred / not in this plan:** FreshaController (shelved feature); a shared base controller (the concern already carries the shared behaviour — a base class would be over-abstraction for four thin call sites).

---

## Execution Handoff

Plan complete and saved to `docs/superpowers/plans/2026-07-02-platform-refresh-plan-3-async-connect.md`. **Blocker gate: P1 + breaking frontend contract — do not implement until Josh signs off AND the frontend change is coordinated.** Two execution modes once approved:

**1. Subagent-Driven (recommended)** — fresh subagent per task, independent review between tasks.

**2. Inline Execution** — task-by-task in this session with checkpoints.
