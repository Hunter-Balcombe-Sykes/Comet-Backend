# Async Analytics Ingest Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move public analytics ingest (pageview / click / section-seen) off the visitor request path behind two swappable seams (transport + storage), so future scaling is additive — never a controller rewrite.

**Architecture:** Controller builds an immutable `AnalyticsEvent` (minting the row PK + stamping request-time fields), does an in-memory + Redis-only dedup, and hands off to an `AnalyticsIngestor`. In prod that's `QueuedIngestor` → `RecordAnalyticsEventJob` → `AnalyticsEventWriter` (`PostgresEventWriter`); in `local`/`testing` it's `SyncIngestor` (inline write, keeps tests synchronous). The minted UUID is the persisted PK and the writer uses `insertOrIgnore`, so at-least-once retries no-op instead of double-counting.

**Tech Stack:** Laravel 12, PHP 8.2, Redis (Horizon `analytics` queue — already provisioned), Postgres (raw analytics tables — unchanged), Pest 4 on SQLite `:memory:`.

---

## Code-grounded deviations from spec rev.2 (READ FIRST — confirm before executing)

Found by reading the actual code, not the spec. Four matter:

1. **Reuse the existing `analytics` queue — do NOT build `redis_analytics` (spec §7).** `config/horizon.php` already has `supervisor-analytics` consuming `['analytics','images']` on the default `redis` connection, plus a `redis:analytics` wait threshold. Nothing dispatches to it yet — our job is the first producer. So: dispatch with `->onQueue('analytics')` on the default connection. **Zero new connection, zero Horizon change.** `retry_after=360` is harmless for tiny PK-idempotent jobs.
2. **Drop metric = breadcrumb `Log::warning`; the Nightwatch rate-alert is a dashboard/ops follow-up, not code (spec §3.4).** This codebase has no generic counter→alert facility (confirmed: `LogLeadRateLimits:71`, `VerifySupabaseJwt`). Nightwatch alerts on exceptions/slow jobs, not log queries. We emit the structured breadcrumb; true rate alerting is configured in the Nightwatch dashboard later.
3. **`ClickRequest` must drop its `Rule::exists` on `block_id` (spec missed this).** It currently does a DB existence check during validation that would 422 a nonexistent block — contradicting "optimistic accept → worker drops." Change to `['required','uuid']`.
4. **HTTP status changes from the full decouple (NEEDS EXPLICIT SIGN-OFF).** Because the controller no longer loads the block, every *synchronous block rejection* becomes a *synchronous 201 accept + asynchronous writer drop*. The behaviour is preserved (no row is ever written for a bad block); only the HTTP status changes. Enumerated:

   | Endpoint | Block condition | Old status | New status |
   |----------|-----------------|-----------|-----------|
   | `/clicks` | block does not exist | 404 | **201** (writer drops) |
   | `/clicks` | block belongs to another site (IDOR) | 404 | **201** (writer drops) |
   | `/clicks` | block type not trackable | 422 | **201** (writer drops) |
   | `/clicks` | block inactive | 404 | **201** (writer drops) |
   | `/section-seen` | optional `block_id` belongs to another site (IDOR) | 404 | **201** (writer drops) |
   | `/section-seen` | optional `block_id` does not exist | 404 | **201** (writer drops) |

   - **Security invariant preserved at the writer:** `appendClickRow` / `appendSectionRow` still enforce `block->site_id === event->siteId` before emitting a row, so **no cross-site row is ever written**. Enumeration surface actually *improves* — a uniform 201 no longer distinguishes "block exists on another site" (old 404 "does not belong") from a normal accept.
   - **Tests this edits:** `SectionSeenIngestTest` (cross-site case 404→201) **and** `TopSectionsExpandedTypesTest` (untrackable case 422→201). Both are updated in Task 8. No existing test asserts the non-existent / foreign / inactive *click* cases (confirmed gap), so those flip silently — surfaced here for sign-off.

5. **`/clicks` success message** collapses to a constant `'Click recorded'` (today it returns `'Section interaction recorded'` for section-type blocks). The controller no longer loads the block, so it can't branch the message. `click_id` + 201 are unchanged. No existing test asserts this message.

6. **Dedup is Redis-only and strictly weaker than today's DB dedup (accepted trade-off, NOT parity).** Today's dedup is a Postgres sliding-window query; the new design is a Redis SETNX guard + fail-open. Two consequences:
   - **No Redis-outage backstop.** A Redis blip means two redundant requests mint two distinct UUIDs, and `insertOrIgnore` inserts *both* — the PK constraint only neutralises *same-UUID job requeues*, not distinct requests that should have collapsed. The old DB query deduped even with Redis down. A duplicated beacon under a Redis outage is acceptable; this is a deliberate degrade, not equivalence.
   - **Single-identifier keying.** The key uses `visitor_id ?? session_id` (strongest only); the old query matched `visitor_id OR session_id`. If the visitor cookie drops between two requests from one user, they key differently and are not deduped.

Everything else follows spec rev.2.

## Ground-truth reference (verified against source — do not re-derive)

- **Live column is `user_id`** on all analytics tables (renamed from `professional_id` by `supabase/migrations/20260527030000_rename_professional_to_user.sql`; the baseline predates it). Models + `AnalyticsQueryService` already use `user_id`.
- **Per-type columns** (from the baseline DDL):
  - `analytics.site_visits`: `id, user_id, site_id, occurred_at, session_id, visitor_id, ip_hash, user_agent, referrer, utm_source, utm_medium, utm_campaign, country_code, device_type, created_at`
  - `analytics.link_clicks`: same **minus** `country_code, device_type`, **plus** `link_block_id`
  - `analytics.section_views`: site_visits set **plus** `block_id, section_key`
  - All `created_at` default `now()` in prod, but the **test stub tables have no default** → writer sets `created_at` explicitly.
- **Models** (`SiteVisit`/`LinkClick`/`SectionView`): extend `BaseModel` (forces `pgsql`), `HasUuids`, `incrementing=false`, `keyType=string`, `timestamps=false`. `HasUuids` mints `Str::orderedUuid()` — so we mint the same way for PK index locality.
- **`ApiController::success($data, int $status)`** — NO message arg. Use `success(['message'=>..., '<x>_id'=>$id], 201)`.
- **`error(string $message, int $status)`** — for 404/422.
- **Controller traits already in use:** `DetectsClientInfo` (`detectCountryCode(Request)`, `isBotUserAgent(?string)`, `detectDeviceType(?string)`), `HashesClientData` (`hashIp(?string)`), `ResolvesSiteFromRequest` (`resolveSiteFromData(array): ?Site` — **does raw `Site::query()` reads, NOT cache-backed**; stays as-is, a pre-existing read).
- **Per-endpoint referrer handling (preserve exactly):** pageview stores raw `referrer` (no URL filter); click + section sanitize via `filter_var($r, FILTER_VALIDATE_URL) ? $r : null`.
- **Dedup pattern:** `Cache::add($key, $value, $ttl)` is atomic SETNX (see `LogLeadRateLimits:57`).
- **Cache version key:** `CacheKeyGenerator::analyticsSummaryVersion($userId)` → `"analytics:summary:ver:{userId}"`; debounce key `"analytics:ingest-debounce:{userId}"` TTL 30.
- **Job conventions** (`ProcessImageVariantsJob`): `implements ShouldQueue; use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;` public `$tries/$backoff/$timeout`; `$this->onQueue(...)` in constructor.
- **Routes** `routes/api.php:89-94` carry `throttle:analytics` (+ `throttle:analytics-click` on clicks). **Untouched.**
- **Test wiring:** SQLite `:memory:`; `TestCase::setUp` redirects the `pgsql` connection to SQLite and makes it default. Schemas via `attachTestSchemas()`. Stub tables via `setupSiteVisitsTable()`, `setupLinkClicksTable()`, `setupSectionViewsTable()`. Tenants via `createBrandTenant($handle): User` (`$t->id` = user id, `$t->site` = Site). Blocks via `createLinkBlockFor(User $pro, array $overrides=[]): Block` (defaults to a trackable active `links/link`). Feature tests auto-bind `Tests\TestCase`; **Unit tests that hit the DB must add `uses(Tests\TestCase::class);` at the top of the file.**

---

## File Structure

**New (production):**
- `app/Services/Analytics/AnalyticsEvent.php` — immutable DTO + `toArray`/`fromArray`
- `app/Services/Analytics/Contracts/AnalyticsIngestor.php` — transport seam
- `app/Services/Analytics/Contracts/AnalyticsEventWriter.php` — storage seam
- `app/Services/Analytics/AnalyticsDedupGuard.php` — Redis SETNX dedup
- `app/Services/Analytics/Writers/PostgresEventWriter.php` — per-type validate + idempotent insert
- `app/Services/Analytics/Ingestors/SyncIngestor.php` — inline (local/testing)
- `app/Services/Analytics/Ingestors/QueuedIngestor.php` — dispatch to `analytics` queue
- `app/Jobs/Analytics/RecordAnalyticsEventJob.php` — write + debounced version bump

**Modified:**
- `app/Http/Requests/Api/PublicSite/Analytics/ClickRequest.php` — drop `Rule::exists` on `block_id`
- `app/Http/Controllers/Api/PublicSite/AnalyticsController.php` — `pageview`/`click`/`sectionSeen` rewritten; `rum` untouched
- `app/Providers/AppServiceProvider.php` — bind both seams in `register()`
- `config/partna.php` — `analytics_queue.name`

**Tests (new):** `tests/Unit/Analytics/{AnalyticsEventTest,AnalyticsDedupGuardTest,PostgresEventWriterTest,RecordAnalyticsEventJobTest,QueuedIngestorTest,SyncIngestorTest}.php`, `tests/Feature/Analytics/AsyncIngestContractTest.php`
**Tests (modified):** `tests/Feature/Analytics/SectionSeenIngestTest.php` (IDOR 404→201), `tests/Feature/Analytics/TopSectionsExpandedTypesTest.php` (untrackable click 422→201)

---

## Task 1: AnalyticsEvent DTO + contracts

**Files:**
- Create: `app/Services/Analytics/AnalyticsEvent.php`
- Create: `app/Services/Analytics/Contracts/AnalyticsIngestor.php`
- Create: `app/Services/Analytics/Contracts/AnalyticsEventWriter.php`
- Test: `tests/Unit/Analytics/AnalyticsEventTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/Analytics/AnalyticsEventTest.php

use App\Services\Analytics\AnalyticsEvent;

it('round-trips through array preserving the minted id and all fields', function () {
    $event = new AnalyticsEvent(
        id: 'abc-id', type: AnalyticsEvent::TYPE_CLICK, occurredAt: '2026-05-30T00:00:00.000000Z',
        userId: 'user-1', siteId: 'site-1', sessionId: 'sess-1', visitorId: 'vis-1',
        ipHash: 'hash', userAgent: 'UA', referrer: 'https://x.test', utmSource: 's',
        utmMedium: 'm', utmCampaign: 'c', countryCode: 'AU', deviceType: 'mobile',
        blockId: 'block-1', sectionKey: null,
    );

    $restored = AnalyticsEvent::fromArray($event->toArray());

    expect($restored->id)->toBe('abc-id')
        ->and($restored->type)->toBe('click')
        ->and($restored->blockId)->toBe('block-1')
        ->and($restored->countryCode)->toBe('AU')
        ->and($restored->toArray())->toBe($event->toArray());
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Analytics/AnalyticsEventTest.php`
Expected: FAIL — class `App\Services\Analytics\AnalyticsEvent` not found.

- [ ] **Step 3: Create the contracts**

```php
<?php
// app/Services/Analytics/Contracts/AnalyticsIngestor.php
namespace App\Services\Analytics\Contracts;

use App\Services\Analytics\AnalyticsEvent;

// Transport seam: gets an event OFF the request path. Swap impls (queued → buffered)
// without touching the controller.
interface AnalyticsIngestor
{
    public function ingest(AnalyticsEvent $event): void;
}
```

```php
<?php
// app/Services/Analytics/Contracts/AnalyticsEventWriter.php
namespace App\Services\Analytics\Contracts;

use App\Services\Analytics\AnalyticsEvent;

// Storage seam: decides WHERE an event lands. Swap impls (Postgres → ClickHouse)
// without touching the job.
interface AnalyticsEventWriter
{
    public function write(AnalyticsEvent $event): void;

    /** @param  AnalyticsEvent[]  $events */
    public function writeMany(array $events): void;
}
```

- [ ] **Step 4: Create the DTO**

```php
<?php
// app/Services/Analytics/AnalyticsEvent.php
namespace App\Services\Analytics;

// Immutable description of one analytics event. Flows through both seams and onto the
// queue payload, so it holds only scalars (no Eloquent model). `id` is minted at the
// controller and BECOMES the row primary key — the linchpin of at-least-once
// idempotency (the writer uses insertOrIgnore on it). `occurredAt` is an ISO-8601
// string captured at request time; `countryCode`/`deviceType`/`ipHash` are likewise
// request-derived and front-loaded here because the worker has no request object.
final class AnalyticsEvent
{
    public const TYPE_PAGEVIEW = 'pageview';
    public const TYPE_CLICK = 'click';
    public const TYPE_SECTION_VIEW = 'section_view';

    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $occurredAt,
        public readonly string $userId,
        public readonly string $siteId,
        public readonly ?string $sessionId,
        public readonly ?string $visitorId,
        public readonly ?string $ipHash,
        public readonly ?string $userAgent,
        public readonly ?string $referrer,
        public readonly ?string $utmSource,
        public readonly ?string $utmMedium,
        public readonly ?string $utmCampaign,
        public readonly ?string $countryCode,
        public readonly ?string $deviceType,
        public readonly ?string $blockId,
        public readonly ?string $sectionKey,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'occurred_at' => $this->occurredAt,
            'user_id' => $this->userId,
            'site_id' => $this->siteId,
            'session_id' => $this->sessionId,
            'visitor_id' => $this->visitorId,
            'ip_hash' => $this->ipHash,
            'user_agent' => $this->userAgent,
            'referrer' => $this->referrer,
            'utm_source' => $this->utmSource,
            'utm_medium' => $this->utmMedium,
            'utm_campaign' => $this->utmCampaign,
            'country_code' => $this->countryCode,
            'device_type' => $this->deviceType,
            'block_id' => $this->blockId,
            'section_key' => $this->sectionKey,
        ];
    }

    /** @param  array<string, mixed>  $d */
    public static function fromArray(array $d): self
    {
        return new self(
            id: $d['id'],
            type: $d['type'],
            occurredAt: $d['occurred_at'],
            userId: $d['user_id'],
            siteId: $d['site_id'],
            sessionId: $d['session_id'] ?? null,
            visitorId: $d['visitor_id'] ?? null,
            ipHash: $d['ip_hash'] ?? null,
            userAgent: $d['user_agent'] ?? null,
            referrer: $d['referrer'] ?? null,
            utmSource: $d['utm_source'] ?? null,
            utmMedium: $d['utm_medium'] ?? null,
            utmCampaign: $d['utm_campaign'] ?? null,
            countryCode: $d['country_code'] ?? null,
            deviceType: $d['device_type'] ?? null,
            blockId: $d['block_id'] ?? null,
            sectionKey: $d['section_key'] ?? null,
        );
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Unit/Analytics/AnalyticsEventTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Analytics/AnalyticsEvent.php app/Services/Analytics/Contracts tests/Unit/Analytics/AnalyticsEventTest.php
git commit -m "feat(analytics): add AnalyticsEvent DTO + ingest/writer contracts"
```

---

## Task 2: AnalyticsDedupGuard

**Files:**
- Create: `app/Services/Analytics/AnalyticsDedupGuard.php`
- Test: `tests/Unit/Analytics/AnalyticsDedupGuardTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Unit/Analytics/AnalyticsDedupGuardTest.php

use App\Services\Analytics\AnalyticsDedupGuard;
use Illuminate\Support\Facades\Cache;

// Uses the Cache facade (real array store + Cache::shouldReceive), so it needs a
// booted app. tests/Pest.php binds only Feature to Tests\TestCase — unit tests must
// opt in explicitly or the facade has no root.
uses(Tests\TestCase::class);

it('reports novel and stores the minted uuid on first claim', function () {
    $guard = new AnalyticsDedupGuard();
    $result = $guard->claim('dedup:test:1', 'uuid-A', 3);

    expect($result)->toBe(['novel' => true, 'id' => 'uuid-A']);
});

it('reports duplicate and echoes the original uuid on a second claim', function () {
    $guard = new AnalyticsDedupGuard();
    $guard->claim('dedup:test:2', 'uuid-A', 30);
    $result = $guard->claim('dedup:test:2', 'uuid-B', 30);

    expect($result)->toBe(['novel' => false, 'id' => 'uuid-A']);
});

it('falls back to the minted uuid when the key expired between setnx and get', function () {
    $guard = new AnalyticsDedupGuard();
    // Simulate a failed SETNX (key present) whose value vanished before get().
    Cache::shouldReceive('add')->once()->andReturnFalse();
    Cache::shouldReceive('get')->once()->andReturnNull();

    expect($guard->claim('dedup:test:3', 'uuid-B', 3))->toBe(['novel' => false, 'id' => 'uuid-B']);
});

it('fails open (novel) when the cache store throws', function () {
    $guard = new AnalyticsDedupGuard();
    Cache::shouldReceive('add')->once()->andThrow(new RuntimeException('redis down'));

    expect($guard->claim('dedup:test:4', 'uuid-B', 3))->toBe(['novel' => true, 'id' => 'uuid-B']);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Unit/Analytics/AnalyticsDedupGuardTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php
// app/Services/Analytics/AnalyticsDedupGuard.php
namespace App\Services\Analytics;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

// Redis-backed fixed-window dedup via atomic SETNX (Cache::add) — same family as
// LogLeadRateLimits. The key stores the minted UUID as its value so a duplicate can
// echo back the ORIGINAL event id (preserving today's "return existing id" contract).
//
// Fixed window (resets every TTL), not the SQL path's sliding window — a genuine
// repeat is re-registered once per TTL rather than suppressed indefinitely.
//
// Fail-open: any cache fault is swallowed and treated as novel, so a Redis blip
// degrades to a possible duplicate rather than a dropped beacon or a 500.
class AnalyticsDedupGuard
{
    /** @return array{novel: bool, id: string} */
    public function claim(string $key, string $mintedUuid, int $ttlSeconds): array
    {
        try {
            if (Cache::add($key, $mintedUuid, $ttlSeconds)) {
                return ['novel' => true, 'id' => $mintedUuid];
            }

            $original = Cache::get($key);

            // TOCTOU: a 3s key can expire between the failed add() and get(). Never
            // echo null into the response body — fall back to the minted uuid.
            return ['novel' => false, 'id' => is_string($original) ? $original : $mintedUuid];
        } catch (Throwable $e) {
            Log::warning('analytics.dedup_fault', ['key' => $key, 'error' => $e->getMessage()]);

            return ['novel' => true, 'id' => $mintedUuid];
        }
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test tests/Unit/Analytics/AnalyticsDedupGuardTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Analytics/AnalyticsDedupGuard.php tests/Unit/Analytics/AnalyticsDedupGuardTest.php
git commit -m "feat(analytics): add Redis SETNX dedup guard with fail-open + id echo"
```

---

## Task 3: PostgresEventWriter

**Files:**
- Create: `app/Services/Analytics/Writers/PostgresEventWriter.php`
- Test: `tests/Unit/Analytics/PostgresEventWriterTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Unit/Analytics/PostgresEventWriterTest.php

use App\Services\Analytics\AnalyticsEvent;
use App\Services\Analytics\Writers\PostgresEventWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(Tests\TestCase::class); // Unit test hits the (sqlite-backed) pgsql connection.

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupBlocksTable();
    setupSiteVisitsTable();
    setupLinkClicksTable();
    setupSectionViewsTable();
});

function pgWriter(): PostgresEventWriter
{
    return new PostgresEventWriter();
}

function baseEvent(array $o = []): AnalyticsEvent
{
    return AnalyticsEvent::fromArray(array_merge([
        'id' => (string) Str::orderedUuid(),
        'type' => AnalyticsEvent::TYPE_PAGEVIEW,
        'occurred_at' => now()->toISOString(),
        'user_id' => 'u', 'site_id' => 's',
        'session_id' => null, 'visitor_id' => null, 'ip_hash' => null,
        'user_agent' => null, 'referrer' => null,
        'utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null,
        'country_code' => null, 'device_type' => null,
        'block_id' => null, 'section_key' => null,
    ], $o));
}

it('persists a pageview to site_visits', function () {
    $t = createBrandTenant('writer-pv');
    pgWriter()->write(baseEvent(['user_id' => $t->id, 'site_id' => $t->site->id, 'country_code' => 'AU']));

    expect(DB::connection('pgsql')->table('analytics.site_visits')->count())->toBe(1);
});

it('is idempotent — the same minted id inserts exactly one row', function () {
    $t = createBrandTenant('writer-idem');
    $e = baseEvent(['user_id' => $t->id, 'site_id' => $t->site->id]);
    pgWriter()->write($e);
    pgWriter()->write($e); // at-least-once retry

    expect(DB::connection('pgsql')->table('analytics.site_visits')->count())->toBe(1);
});

it('persists a click for a trackable active block, mapping link_block_id', function () {
    $t = createBrandTenant('writer-click');
    $block = createLinkBlockFor($t);
    pgWriter()->write(baseEvent([
        'type' => AnalyticsEvent::TYPE_CLICK, 'user_id' => $t->id, 'site_id' => $t->site->id, 'block_id' => $block->id,
    ]));

    $row = DB::connection('pgsql')->table('analytics.link_clicks')->first();
    expect($row->link_block_id)->toBe($block->id);
});

it('drops a click whose block does not exist', function () {
    $t = createBrandTenant('writer-missing');
    pgWriter()->write(baseEvent([
        'type' => AnalyticsEvent::TYPE_CLICK, 'user_id' => $t->id, 'site_id' => $t->site->id, 'block_id' => (string) Str::uuid(),
    ]));

    expect(DB::connection('pgsql')->table('analytics.link_clicks')->count())->toBe(0);
});

it('drops a click whose block belongs to another site (IDOR defence at the writer)', function () {
    $t = createBrandTenant('writer-foreign');
    $other = createBrandTenant('writer-foreign-other');
    $foreign = createLinkBlockFor($other);
    pgWriter()->write(baseEvent([
        'type' => AnalyticsEvent::TYPE_CLICK, 'user_id' => $t->id, 'site_id' => $t->site->id, 'block_id' => $foreign->id,
    ]));

    expect(DB::connection('pgsql')->table('analytics.link_clicks')->count())->toBe(0);
});

it('drops a click whose block is inactive', function () {
    $t = createBrandTenant('writer-inactive');
    $block = createLinkBlockFor($t, ['is_active' => 0]);
    pgWriter()->write(baseEvent([
        'type' => AnalyticsEvent::TYPE_CLICK, 'user_id' => $t->id, 'site_id' => $t->site->id, 'block_id' => $block->id,
    ]));

    expect(DB::connection('pgsql')->table('analytics.link_clicks')->count())->toBe(0);
});

it('drops a click whose block is not trackable', function () {
    $t = createBrandTenant('writer-untrackable');
    $block = createLinkBlockFor($t, ['block_group' => 'sections', 'block_type' => 'custom_html']);
    pgWriter()->write(baseEvent([
        'type' => AnalyticsEvent::TYPE_CLICK, 'user_id' => $t->id, 'site_id' => $t->site->id, 'block_id' => $block->id,
    ]));

    expect(DB::connection('pgsql')->table('analytics.link_clicks')->count())->toBe(0);
});

it('persists a section view with a null block_id', function () {
    $t = createBrandTenant('writer-section-null');
    pgWriter()->write(baseEvent([
        'type' => AnalyticsEvent::TYPE_SECTION_VIEW, 'user_id' => $t->id, 'site_id' => $t->site->id,
        'section_key' => 'hero', 'block_id' => null,
    ]));

    expect(DB::connection('pgsql')->table('analytics.section_views')->count())->toBe(1);
});

it('drops a section view whose block belongs to another site', function () {
    $t = createBrandTenant('writer-section-foreign');
    $other = createBrandTenant('writer-section-foreign-other');
    $foreign = createLinkBlockFor($other);
    pgWriter()->write(baseEvent([
        'type' => AnalyticsEvent::TYPE_SECTION_VIEW, 'user_id' => $t->id, 'site_id' => $t->site->id,
        'section_key' => 'products', 'block_id' => $foreign->id,
    ]));

    expect(DB::connection('pgsql')->table('analytics.section_views')->count())->toBe(0);
});

it('writeMany persists all valid events across types', function () {
    $t = createBrandTenant('writer-many');
    $block = createLinkBlockFor($t);
    pgWriter()->writeMany([
        baseEvent(['user_id' => $t->id, 'site_id' => $t->site->id]),
        baseEvent(['type' => AnalyticsEvent::TYPE_CLICK, 'user_id' => $t->id, 'site_id' => $t->site->id, 'block_id' => $block->id]),
        baseEvent(['type' => AnalyticsEvent::TYPE_SECTION_VIEW, 'user_id' => $t->id, 'site_id' => $t->site->id, 'section_key' => 'hero']),
    ]);

    expect(DB::connection('pgsql')->table('analytics.site_visits')->count())->toBe(1)
        ->and(DB::connection('pgsql')->table('analytics.link_clicks')->count())->toBe(1)
        ->and(DB::connection('pgsql')->table('analytics.section_views')->count())->toBe(1);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Unit/Analytics/PostgresEventWriterTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php
// app/Services/Analytics/Writers/PostgresEventWriter.php
namespace App\Services\Analytics\Writers;

use App\Models\Analytics\LinkClick;
use App\Models\Analytics\SectionView;
use App\Models\Analytics\SiteVisit;
use App\Models\Core\Site\Block;
use App\Services\Analytics\AnalyticsEvent;
use App\Services\Analytics\Contracts\AnalyticsEventWriter;
use Illuminate\Support\Facades\Log;

// Persists analytics events to the raw Postgres tables. Owns authoritative block
// validation (moved off the request hot path — full decouple). Idempotent: the minted
// UUID is the explicit PK and inserts use insertOrIgnore (ON CONFLICT (id) DO NOTHING),
// so an at-least-once retry no-ops instead of double-counting.
class PostgresEventWriter implements AnalyticsEventWriter
{
    public function write(AnalyticsEvent $event): void
    {
        $this->writeMany([$event]);
    }

    /** @param  AnalyticsEvent[]  $events */
    public function writeMany(array $events): void
    {
        if ($events === []) {
            return;
        }

        // Batch-load every referenced block in ONE query so validation never
        // degrades to a SELECT per event (matters for the future BufferedIngestor).
        $blocks = $this->loadBlocks($events);

        $visitRows = [];
        $clickRows = [];
        $sectionRows = [];

        foreach ($events as $event) {
            match ($event->type) {
                AnalyticsEvent::TYPE_PAGEVIEW => $visitRows[] = $this->visitRow($event),
                AnalyticsEvent::TYPE_CLICK => $this->appendClickRow($event, $blocks, $clickRows),
                AnalyticsEvent::TYPE_SECTION_VIEW => $this->appendSectionRow($event, $blocks, $sectionRows),
                default => $this->drop($event, 'unknown_type'),
            };
        }

        if ($visitRows !== []) {
            SiteVisit::query()->insertOrIgnore($visitRows);
        }
        if ($clickRows !== []) {
            LinkClick::query()->insertOrIgnore($clickRows);
        }
        if ($sectionRows !== []) {
            SectionView::query()->insertOrIgnore($sectionRows);
        }
    }

    /**
     * @param  AnalyticsEvent[]  $events
     * @return array<string, Block>
     */
    private function loadBlocks(array $events): array
    {
        $ids = [];
        foreach ($events as $e) {
            if ($e->blockId !== null) {
                $ids[$e->blockId] = true;
            }
        }
        if ($ids === []) {
            return [];
        }

        return Block::query()->whereIn('id', array_keys($ids))->get()->keyBy('id')->all();
    }

    /** @return array<string, mixed> */
    private function visitRow(AnalyticsEvent $e): array
    {
        return [
            'id' => $e->id,
            'user_id' => $e->userId,
            'site_id' => $e->siteId,
            'occurred_at' => $e->occurredAt,
            'created_at' => now()->toISOString(),
            'session_id' => $e->sessionId,
            'visitor_id' => $e->visitorId,
            'ip_hash' => $e->ipHash,
            'user_agent' => $e->userAgent,
            'referrer' => $e->referrer,
            'utm_source' => $e->utmSource,
            'utm_medium' => $e->utmMedium,
            'utm_campaign' => $e->utmCampaign,
            'country_code' => $e->countryCode,
            'device_type' => $e->deviceType,
        ];
    }

    /** @param  array<string, Block>  $blocks */
    private function appendClickRow(AnalyticsEvent $e, array $blocks, array &$rows): void
    {
        $block = $e->blockId !== null ? ($blocks[$e->blockId] ?? null) : null;

        if (! $block) {
            $this->drop($e, 'block_missing');

            return;
        }
        if ($block->site_id !== $e->siteId) {
            $this->drop($e, 'block_foreign_site');

            return;
        }
        if (! $this->isTrackableClickBlock($block)) {
            $this->drop($e, 'block_not_trackable');

            return;
        }
        if (! $block->is_active) {
            $this->drop($e, 'block_inactive');

            return;
        }

        $rows[] = [
            'id' => $e->id,
            'user_id' => $e->userId,
            'site_id' => $e->siteId,
            'link_block_id' => $e->blockId,
            'occurred_at' => $e->occurredAt,
            'created_at' => now()->toISOString(),
            'session_id' => $e->sessionId,
            'visitor_id' => $e->visitorId,
            'ip_hash' => $e->ipHash,
            'user_agent' => $e->userAgent,
            'referrer' => $e->referrer,
            'utm_source' => $e->utmSource,
            'utm_medium' => $e->utmMedium,
            'utm_campaign' => $e->utmCampaign,
        ];
    }

    /** @param  array<string, Block>  $blocks */
    private function appendSectionRow(AnalyticsEvent $e, array $blocks, array &$rows): void
    {
        // block_id is OPTIONAL for sections; null is valid (header/footer/bio). When
        // present it must belong to the site — cross-site IDOR defence, preserved from
        // the controller and relocated here.
        if ($e->blockId !== null) {
            $block = $blocks[$e->blockId] ?? null;
            if (! $block) {
                $this->drop($e, 'block_missing');

                return;
            }
            if ($block->site_id !== $e->siteId) {
                $this->drop($e, 'block_foreign_site');

                return;
            }
        }

        $rows[] = [
            'id' => $e->id,
            'user_id' => $e->userId,
            'site_id' => $e->siteId,
            'block_id' => $e->blockId,
            'section_key' => $e->sectionKey,
            'occurred_at' => $e->occurredAt,
            'created_at' => now()->toISOString(),
            'session_id' => $e->sessionId,
            'visitor_id' => $e->visitorId,
            'ip_hash' => $e->ipHash,
            'user_agent' => $e->userAgent,
            'referrer' => $e->referrer,
            'utm_source' => $e->utmSource,
            'utm_medium' => $e->utmMedium,
            'utm_campaign' => $e->utmCampaign,
            'country_code' => $e->countryCode,
            'device_type' => $e->deviceType,
        ];
    }

    // Mirrors the ingest-side allowlist in config('partna.section_block_types').
    private function isTrackableClickBlock(Block $block): bool
    {
        $group = strtolower((string) $block->block_group);
        $type = strtolower((string) $block->block_type);

        $trackableSectionTypes = collect(config('partna.section_block_types', ['gallery', 'services', 'booking']))
            ->filter(fn ($t) => is_string($t) && trim($t) !== '')
            ->map(fn (string $t) => strtolower(trim($t)))
            ->all();

        return ($group === 'links' && $type === 'link')
            || ($group === 'sections' && in_array($type, $trackableSectionTypes, true));
    }

    // Breadcrumb only — Nightwatch surfaces sustained spikes via log-channel
    // aggregation; a single drop does not page. A true rate alert is a Nightwatch
    // dashboard config (ops follow-up), not code.
    private function drop(AnalyticsEvent $e, string $reason): void
    {
        Log::warning('analytics.ingest.dropped', [
            'reason' => $reason,
            'type' => $e->type,
            'site_id' => $e->siteId,
            'block_id' => $e->blockId,
        ]);
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test tests/Unit/Analytics/PostgresEventWriterTest.php`
Expected: PASS (11 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Analytics/Writers/PostgresEventWriter.php tests/Unit/Analytics/PostgresEventWriterTest.php
git commit -m "feat(analytics): add PostgresEventWriter with per-type validation + idempotent insert"
```

---

## Task 4: RecordAnalyticsEventJob

**Files:**
- Create: `app/Jobs/Analytics/RecordAnalyticsEventJob.php`
- Modify: `config/partna.php` (add `analytics_queue.name`)
- Test: `tests/Unit/Analytics/RecordAnalyticsEventJobTest.php`

- [ ] **Step 1: Add config key**

In `config/partna.php`, directly after the existing `'video_queue' => [ ... ],` block, add:

```php
    // Analytics ingest queue. Reuses the default 'redis' connection — Horizon's
    // supervisor-analytics already consumes the 'analytics' queue (config/horizon.php).
    // No dedicated connection: jobs are tiny + PK-idempotent, so the default
    // retry_after is harmless.
    'analytics_queue' => [
        'name' => env('PARTNA_ANALYTICS_QUEUE', 'analytics'),
    ],
```

- [ ] **Step 2: Write the failing tests**

```php
<?php
// tests/Unit/Analytics/RecordAnalyticsEventJobTest.php

use App\Jobs\Analytics\RecordAnalyticsEventJob;
use App\Services\Analytics\AnalyticsEvent;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(Tests\TestCase::class);

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupBlocksTable();
    setupSiteVisitsTable();
});

function pageviewPayload(string $userId, string $siteId): array
{
    return [
        'id' => (string) Str::orderedUuid(),
        'type' => AnalyticsEvent::TYPE_PAGEVIEW,
        'occurred_at' => now()->toISOString(),
        'user_id' => $userId, 'site_id' => $siteId,
    ];
}

it('persists the event and bumps the analytics summary version', function () {
    $t = createBrandTenant('job-happy');
    $payload = pageviewPayload($t->id, $t->site->id);

    (new RecordAnalyticsEventJob($payload))->handle(app(\App\Services\Analytics\Contracts\AnalyticsEventWriter::class));

    expect(DB::connection('pgsql')->table('analytics.site_visits')->count())->toBe(1)
        ->and(Cache::get(CacheKeyGenerator::analyticsSummaryVersion($t->id)))->not->toBeNull();
});

it('is idempotent across an at-least-once retry (handle twice → one row)', function () {
    $t = createBrandTenant('job-retry');
    $payload = pageviewPayload($t->id, $t->site->id);
    $writer = app(\App\Services\Analytics\Contracts\AnalyticsEventWriter::class);

    (new RecordAnalyticsEventJob($payload))->handle($writer);
    (new RecordAnalyticsEventJob($payload))->handle($writer);

    expect(DB::connection('pgsql')->table('analytics.site_visits')->count())->toBe(1);
});

it('targets the analytics queue', function () {
    expect((new RecordAnalyticsEventJob(['id' => 'x']))->queue)->toBe('analytics');
});
```

- [ ] **Step 3: Run to verify it fails**

Run: `php artisan test tests/Unit/Analytics/RecordAnalyticsEventJobTest.php`
Expected: FAIL — class not found.

- [ ] **Step 4: Implement**

```php
<?php
// app/Jobs/Analytics/RecordAnalyticsEventJob.php
namespace App\Jobs\Analytics;

use App\Services\Analytics\AnalyticsEvent;
use App\Services\Analytics\Contracts\AnalyticsEventWriter;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

// Writes one analytics event to its raw table, then bumps the per-user analytics
// summary cache version (debounced). Dispatched onto the 'analytics' queue (default
// redis connection — already consumed by Horizon's supervisor-analytics).
//
// At-least-once: the writer's insertOrIgnore on the minted PK neutralises a retry that
// lands after a partial success, so a worker restart never double-counts.
class RecordAnalyticsEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public int $timeout = 30;

    /** @param  array<string, mixed>  $payload  AnalyticsEvent::toArray() */
    public function __construct(public readonly array $payload)
    {
        $this->onQueue((string) config('partna.analytics_queue.name', 'analytics'));
    }

    public function handle(AnalyticsEventWriter $writer): void
    {
        $event = AnalyticsEvent::fromArray($this->payload);

        $writer->write($event);

        $this->bumpAnalyticsVersion($event->userId);
    }

    // Moved off the request path (was the controller's debounceInvalidateAnalytics).
    // Wrapped so a cache fault never fails a job whose write already committed —
    // re-running handle() would be harmless anyway (PK-idempotent), but acking avoids
    // a needless retry.
    private function bumpAnalyticsVersion(string $userId): void
    {
        try {
            if (Cache::add("analytics:ingest-debounce:{$userId}", 1, 30)) {
                Cache::increment(CacheKeyGenerator::analyticsSummaryVersion($userId));
            }
        } catch (Throwable $e) {
            Log::warning('analytics.cache_bump_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }
}
```

- [ ] **Step 5: Run to verify it passes**

Run: `php artisan test tests/Unit/Analytics/RecordAnalyticsEventJobTest.php`
Expected: PASS (3 tests). (Resolves `AnalyticsEventWriter` via the default container binding to a concrete `PostgresEventWriter`; Laravel auto-resolves the concrete class even before Task 6 wires the interface, because the test asks for the concrete writer in Task 3 — here it asks for the interface, so **Task 6 must run before this passes if the interface is unbound**. If running strictly in order, bind in Task 6 first OR change `app(AnalyticsEventWriter::class)` to `new PostgresEventWriter()` in the test.)

> **Execution note:** to keep tasks independently runnable, this test news-up the writer directly is simplest. If you prefer the container, reorder so Task 6's binding lands first. Either is fine; pick one and stay consistent.

- [ ] **Step 6: Commit**

```bash
git add app/Jobs/Analytics/RecordAnalyticsEventJob.php config/partna.php tests/Unit/Analytics/RecordAnalyticsEventJobTest.php
git commit -m "feat(analytics): add RecordAnalyticsEventJob (idempotent write + debounced version bump)"
```

---

## Task 5: Ingestors (Sync + Queued)

**Files:**
- Create: `app/Services/Analytics/Ingestors/SyncIngestor.php`
- Create: `app/Services/Analytics/Ingestors/QueuedIngestor.php`
- Test: `tests/Unit/Analytics/SyncIngestorTest.php`, `tests/Unit/Analytics/QueuedIngestorTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Unit/Analytics/SyncIngestorTest.php

use App\Services\Analytics\AnalyticsEvent;
use App\Services\Analytics\Contracts\AnalyticsEventWriter;
use App\Services\Analytics\Ingestors\SyncIngestor;

it('writes inline via the writer', function () {
    $event = AnalyticsEvent::fromArray([
        'id' => 'i', 'type' => AnalyticsEvent::TYPE_PAGEVIEW, 'occurred_at' => 'now',
        'user_id' => 'u', 'site_id' => 's',
    ]);

    $writer = Mockery::mock(AnalyticsEventWriter::class);
    $writer->shouldReceive('write')->once()->with($event);

    (new SyncIngestor($writer))->ingest($event);
});
```

```php
<?php
// tests/Unit/Analytics/QueuedIngestorTest.php

use App\Jobs\Analytics\RecordAnalyticsEventJob;
use App\Services\Analytics\AnalyticsEvent;
use App\Services\Analytics\Ingestors\QueuedIngestor;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Queue;

// Uses Queue::fake(), app(), config() (the job constructor reads
// partna.analytics_queue.name), and Log::warning in the fail-open path — all need a
// booted app. tests/Pest.php binds only Feature to Tests\TestCase, so opt in here.
uses(Tests\TestCase::class);

function ingestorEvent(): AnalyticsEvent
{
    return AnalyticsEvent::fromArray([
        'id' => 'i', 'type' => AnalyticsEvent::TYPE_CLICK, 'occurred_at' => 'now',
        'user_id' => 'u', 'site_id' => 's', 'block_id' => 'b',
    ]);
}

it('dispatches a RecordAnalyticsEventJob onto the analytics queue', function () {
    Queue::fake();

    app(QueuedIngestor::class)->ingest(ingestorEvent());

    Queue::assertPushed(RecordAnalyticsEventJob::class, function ($job) {
        return $job->payload['id'] === 'i' && $job->queue === 'analytics';
    });
});

it('fails open — a dispatch exception is swallowed, never thrown', function () {
    $bus = Mockery::mock(Dispatcher::class);
    $bus->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('redis down'));

    $ingestor = new QueuedIngestor($bus);

    expect(fn () => $ingestor->ingest(ingestorEvent()))->not->toThrow(Throwable::class);
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test tests/Unit/Analytics/SyncIngestorTest.php tests/Unit/Analytics/QueuedIngestorTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement SyncIngestor**

```php
<?php
// app/Services/Analytics/Ingestors/SyncIngestor.php
namespace App\Services\Analytics\Ingestors;

use App\Services\Analytics\AnalyticsEvent;
use App\Services\Analytics\Contracts\AnalyticsEventWriter;
use App\Services\Analytics\Contracts\AnalyticsIngestor;

// Inline ingest for local/testing — writes straight through the writer so dev and the
// test suite observe rows immediately (no queue worker required).
class SyncIngestor implements AnalyticsIngestor
{
    public function __construct(private readonly AnalyticsEventWriter $writer) {}

    public function ingest(AnalyticsEvent $event): void
    {
        $this->writer->write($event);
    }
}
```

- [ ] **Step 4: Implement QueuedIngestor**

```php
<?php
// app/Services/Analytics/Ingestors/QueuedIngestor.php
namespace App\Services\Analytics\Ingestors;

use App\Jobs\Analytics\RecordAnalyticsEventJob;
use App\Services\Analytics\AnalyticsEvent;
use App\Services\Analytics\Contracts\AnalyticsIngestor;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

// Production ingest: hand the event to the queue and return. Lossy fail-open — if the
// dispatch throws (Redis down) we log a breadcrumb and return normally. We deliberately
// do NOT fall back to an inline write (unlike the image pipeline): that would reintroduce
// the request-path DB coupling this whole design removes, and a single lost beacon is
// acceptable. The visitor beacon is fire-and-forget; never throw at it.
class QueuedIngestor implements AnalyticsIngestor
{
    public function __construct(private readonly Dispatcher $bus) {}

    public function ingest(AnalyticsEvent $event): void
    {
        try {
            $this->bus->dispatch(new RecordAnalyticsEventJob($event->toArray()));
        } catch (Throwable $e) {
            Log::warning('analytics.ingest.dispatch_failed', [
                'type' => $event->type,
                'site_id' => $event->siteId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
```

- [ ] **Step 5: Run to verify they pass**

Run: `php artisan test tests/Unit/Analytics/SyncIngestorTest.php tests/Unit/Analytics/QueuedIngestorTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Analytics/Ingestors tests/Unit/Analytics/SyncIngestorTest.php tests/Unit/Analytics/QueuedIngestorTest.php
git commit -m "feat(analytics): add Sync + Queued ingestors (queued is lossy fail-open)"
```

---

## Task 6: Bind both seams in AppServiceProvider

**Files:**
- Modify: `app/Providers/AppServiceProvider.php` (in `register()`, near the existing `$this->app->singleton(...)` calls)

- [ ] **Step 1: Add the imports** at the top of `AppServiceProvider.php` with the other `use` statements:

```php
use App\Services\Analytics\Contracts\AnalyticsEventWriter;
use App\Services\Analytics\Contracts\AnalyticsIngestor;
use App\Services\Analytics\Ingestors\QueuedIngestor;
use App\Services\Analytics\Ingestors\SyncIngestor;
use App\Services\Analytics\Writers\PostgresEventWriter;
```

- [ ] **Step 2: Add the bindings** inside `register()`, alongside the existing `singleton` calls:

```php
        // Analytics ingest seams. Writer is fixed (Postgres today); ingestor switches
        // on env/queue connection — inline in local/testing or when queue is sync,
        // queued otherwise. Mirrors MediaUploadService::dispatchImageJob's gate.
        $this->app->singleton(AnalyticsEventWriter::class, PostgresEventWriter::class);
        $this->app->singleton(AnalyticsIngestor::class, function ($app) {
            $inline = in_array($app->environment(), ['local', 'testing'], true)
                || (string) config('queue.default', 'sync') === 'sync';

            return $inline ? $app->make(SyncIngestor::class) : $app->make(QueuedIngestor::class);
        });
```

- [ ] **Step 3: Verify the container resolves both**

Run: `php artisan tinker --execute="echo get_class(app(App\Services\Analytics\Contracts\AnalyticsIngestor::class)); echo PHP_EOL; echo get_class(app(App\Services\Analytics\Contracts\AnalyticsEventWriter::class));"`
Expected: `App\Services\Analytics\Ingestors\SyncIngestor` (local env) and `App\Services\Analytics\Writers\PostgresEventWriter`.

- [ ] **Step 4: Commit**

```bash
git add app/Providers/AppServiceProvider.php
git commit -m "feat(analytics): bind ingestor (env-switched) + writer seams"
```

---

## Task 7: Drop ClickRequest exists-rule + rewrite the controller

**Files:**
- Modify: `app/Http/Requests/Api/PublicSite/Analytics/ClickRequest.php`
- Modify: `app/Http/Controllers/Api/PublicSite/AnalyticsController.php`

- [ ] **Step 1: Relax ClickRequest block_id**

In `ClickRequest::rules()`, change the `block_id` line from:

```php
            'block_id' => ['required', 'uuid', Rule::exists('pgsql.site.blocks', 'id')],
```
to:
```php
            // Existence/ownership/trackability now validated in PostgresEventWriter
            // (worker side) so the beacon never blocks on a DB read. Optimistic accept.
            'block_id' => ['required', 'uuid'],
```

(The `use Illuminate\Validation\Rule;` import is still used by `site_id` — leave it.)

- [ ] **Step 2: Rewrite the controller**

Replace the entire body of `app/Http/Controllers/Api/PublicSite/AnalyticsController.php` with:

```php
<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\DetectsClientInfo;
use App\Http\Controllers\Concerns\HashesClientData;
use App\Http\Controllers\Concerns\ResolvesSiteFromRequest;
use App\Http\Requests\Api\PublicSite\Analytics\ClickRequest;
use App\Http\Requests\Api\PublicSite\Analytics\PageviewRequest;
use App\Http\Requests\Api\PublicSite\Analytics\SectionSeenRequest;
use App\Models\Core\Site\Site;
use App\Services\Analytics\AnalyticsDedupGuard;
use App\Services\Analytics\AnalyticsEvent;
use App\Services\Analytics\Contracts\AnalyticsIngestor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

// Records pageview / click / section-seen analytics events from public mini-sites.
//
// Write path is fully decoupled: the controller validates (in-memory), resolves the
// site, mints the row PK + stamps request-time fields, does a Redis-only dedup, and
// hands the event to the AnalyticsIngestor. No Postgres WRITE and no read-for-write on
// the hot path; authoritative block validation lives in the writer (worker side).
class AnalyticsController extends ApiController
{
    use DetectsClientInfo;
    use HashesClientData;
    use ResolvesSiteFromRequest;

    public function __construct(
        private readonly AnalyticsIngestor $ingestor,
        private readonly AnalyticsDedupGuard $dedup,
    ) {}

    public function pageview(PageviewRequest $request): JsonResponse
    {
        $data = $request->validated();

        $site = $this->resolvePublishedSite($data, $error);
        if (! $site) {
            return $error;
        }

        // NOTE: pageview intentionally has NO bot filter and NO dedup (preserved). A bot
        // UA still records a pageview today; changing that is a separate metrics decision.
        $event = $this->buildEvent(
            type: AnalyticsEvent::TYPE_PAGEVIEW,
            request: $request,
            site: $site,
            data: $data,
            // pageview stores the RAW referrer (no URL sanitisation — preserved).
            referrer: $data['referrer'] ?? $request->headers->get('referer'),
        );

        $this->ingestor->ingest($event);

        return $this->success(['message' => 'Pageview recorded', 'visit_id' => $event->id], 201);
    }

    public function click(ClickRequest $request): JsonResponse
    {
        $data = $request->validated();

        $site = $this->resolvePublishedSite($data, $error);
        if (! $site) {
            return $error;
        }

        if ($this->isBotUserAgent($request->userAgent())) {
            // Bot path: 200 with a message and NO id (preserved). Fake-success avoids
            // fingerprinting the filter.
            return $this->success(['message' => 'Click recorded'], 200);
        }

        $id = (string) Str::orderedUuid();

        // Dedup on (block, strongest identifier) for 3s. Returns the original id on a
        // duplicate so the response is byte-identical to today's "return existing id".
        $identifier = $data['visitor_id'] ?? $data['session_id'] ?? null;
        if ($identifier !== null) {
            $claim = $this->dedup->claim("analytics:dedup:click:{$data['block_id']}:{$identifier}", $id, 3);
            if (! $claim['novel']) {
                return $this->success(['message' => 'Click recorded', 'click_id' => $claim['id']], 201);
            }
        }

        $event = $this->buildEvent(
            type: AnalyticsEvent::TYPE_CLICK,
            request: $request,
            site: $site,
            data: $data,
            // click sanitises the referrer (preserved SEC behaviour).
            referrer: $this->sanitizeReferrer($data['referrer'] ?? $request->headers->get('referer')),
            id: $id,
            blockId: $data['block_id'],
        );

        $this->ingestor->ingest($event);

        return $this->success(['message' => 'Click recorded', 'click_id' => $event->id], 201);
    }

    public function sectionSeen(SectionSeenRequest $request): JsonResponse
    {
        $data = $request->validated();

        $site = $this->resolvePublishedSite($data, $error);
        if (! $site) {
            return $error;
        }

        if ($this->isBotUserAgent($request->userAgent())) {
            return $this->success(['message' => 'Section view recorded'], 200);
        }

        $id = (string) Str::orderedUuid();

        // Dedup on (site, section_key, strongest identifier) for 5min.
        $identifier = $data['visitor_id'] ?? $data['session_id'] ?? null;
        if ($identifier !== null) {
            $key = "analytics:dedup:section:{$site->id}:{$data['section_key']}:{$identifier}";
            $claim = $this->dedup->claim($key, $id, 300);
            if (! $claim['novel']) {
                return $this->success(['message' => 'Section view recorded', 'view_id' => $claim['id']], 201);
            }
        }

        $event = $this->buildEvent(
            type: AnalyticsEvent::TYPE_SECTION_VIEW,
            request: $request,
            site: $site,
            data: $data,
            referrer: $this->sanitizeReferrer($data['referrer'] ?? $request->headers->get('referer')),
            id: $id,
            blockId: $data['block_id'] ?? null,
            sectionKey: $data['section_key'],
        );

        $this->ingestor->ingest($event);

        return $this->success(['message' => 'Section view recorded', 'view_id' => $event->id], 201);
    }

    /**
     * Resolve + publication-gate the site. On failure, sets $error to the right JSON
     * response (422 IDOR when site_id was supplied but cross-check failed; otherwise
     * 404 — never 403, no existence leak) and returns null.
     */
    private function resolvePublishedSite(array $data, ?JsonResponse &$error): ?Site
    {
        $site = $this->resolveSiteFromData($data);

        if (! $site) {
            $status = ! empty($data['site_id']) ? 422 : 404;
            $error = $this->error('Site not found', $status);

            return null;
        }

        if (! $site->is_published) {
            $error = $this->error('Site not found', 404);

            return null;
        }

        $error = null;

        return $site;
    }

    // Front-loads every request-derived field into the DTO (occurred_at, geo, device,
    // ip hash, UA). The worker has no request object, so anything not captured here is
    // lost. occurred_at is request-time, ISO-8601.
    private function buildEvent(
        string $type,
        Request $request,
        Site $site,
        array $data,
        ?string $referrer,
        ?string $id = null,
        ?string $blockId = null,
        ?string $sectionKey = null,
    ): AnalyticsEvent {
        return new AnalyticsEvent(
            id: $id ?? (string) Str::orderedUuid(),
            type: $type,
            occurredAt: now()->toISOString(),
            userId: $site->user_id,
            siteId: $site->id,
            sessionId: $data['session_id'] ?? null,
            visitorId: $data['visitor_id'] ?? null,
            ipHash: $this->hashIp($request->ip()),
            userAgent: $request->userAgent(),
            referrer: $referrer,
            utmSource: $data['utm_source'] ?? null,
            utmMedium: $data['utm_medium'] ?? null,
            utmCampaign: $data['utm_campaign'] ?? null,
            countryCode: $this->detectCountryCode($request),
            deviceType: $this->detectDeviceType($request->userAgent()),
            blockId: $blockId,
            sectionKey: $sectionKey,
        );
    }

    // Mirrors the old inline rule: keep only values that are valid URLs.
    private function sanitizeReferrer(?string $raw): ?string
    {
        return ($raw !== null && filter_var($raw, FILTER_VALIDATE_URL)) ? $raw : null;
    }

    /**
     * Real-user monitoring beacon — unchanged. Logs first-paint / load timings to a
     * structured channel for offline percentile analysis. No DB writes.
     */
    public function rum(Request $request): JsonResponse
    {
        if ($this->isBotUserAgent($request->userAgent())) {
            return $this->success(['message' => 'ok'], 200);
        }

        $payload = $request->json()->all();
        $handle = isset($payload['handle']) ? (string) $payload['handle'] : null;
        if (! $handle || ! preg_match('/^[a-z0-9-]{1,63}$/i', $handle)) {
            return $this->success(['message' => 'ok'], 200);
        }

        try {
            \Illuminate\Support\Facades\Log::info('rum', [
                'handle' => strtolower($handle),
                'ttfb_ms' => isset($payload['ttfb']) ? (int) $payload['ttfb'] : null,
                'dom_ms' => isset($payload['dom']) ? (int) $payload['dom'] : null,
                'load_ms' => isset($payload['load']) ? (int) $payload['load'] : null,
                'fcp_ms' => isset($payload['fcp']) ? (int) $payload['fcp'] : null,
                'lkg' => isset($payload['lkg']) ? (bool) $payload['lkg'] : false,
                'ua' => substr((string) $request->userAgent(), 0, 256),
                'country' => $request->header('cf-ipcountry'),
            ]);
        } catch (\Throwable $e) {
            // RUM is best-effort; never bubble logging errors back to the visitor.
        }

        return $this->success(['message' => 'ok'], 200);
    }
}
```

- [ ] **Step 3: Style + static check**

Run: `vendor/bin/pint app/Http/Controllers/Api/PublicSite/AnalyticsController.php app/Http/Requests/Api/PublicSite/Analytics/ClickRequest.php`
Expected: clean (only your changed files — do not run repo-wide pint).

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Api/PublicSite/AnalyticsController.php app/Http/Requests/Api/PublicSite/Analytics/ClickRequest.php
git commit -m "refactor(analytics): decouple ingest controller (DTO + Redis dedup + async handoff)"
```

---

## Task 8: Update existing feature tests + add the contract test

**Files:**
- Modify: `tests/Feature/Analytics/SectionSeenIngestTest.php` (IDOR case 404 → 201)
- Create: `tests/Feature/Analytics/AsyncIngestContractTest.php`

- [ ] **Step 1: Update the section-seen IDOR test**

In `tests/Feature/Analytics/SectionSeenIngestTest.php`, replace the `it('validates an optional block_id belongs to the site ...')` test with:

```php
it('drops an optional block_id that belongs to another site (cross-site IDOR defence)', function () {
    // Full-decouple: the controller no longer cross-checks the block synchronously, so
    // a foreign block_id is accepted (201) and dropped by the writer. The security
    // invariant — NO cross-site row is ever written — is preserved at the writer.
    $tenant = createBrandTenant('section-seen-block-valid');
    $otherTenant = createBrandTenant('section-seen-other-tenant');
    $foreignBlock = createLinkBlockFor($otherTenant);

    $response = $this->postJson('/api/public/analytics/section-seen', [
        'site_id' => $tenant->site->id,
        'section_key' => 'products',
        'block_id' => $foreignBlock->id,
        'session_id' => (string) Str::uuid(),
    ]);

    $response->assertStatus(201);
    expect(DB::connection('pgsql')->table('analytics.section_views')->count())->toBe(0);
});
```

- [ ] **Step 1b: Update the untrackable-click test in `TopSectionsExpandedTypesTest`**

The full decouple means the controller no longer loads the block to reject an untrackable type — it accepts (201) and the writer drops it. So the existing case that asserts **422** now sees **201 + 0 rows**. In `tests/Feature/Analytics/TopSectionsExpandedTypesTest.php`, replace the `it('rejects a click on a section type that is not in the allowlist', ...)` test with:

```php
it('accepts a click on a non-allowlisted section type (201) but writes no row — writer drops', function () {
    // Full-decouple: trackability is no longer checked synchronously. A click to a
    // section block whose type is not in config('partna.section_block_types') is
    // accepted (201) and dropped by PostgresEventWriter. The invariant — no row for
    // an untrackable block — is preserved at the writer.
    $tenant = createBrandTenant('top-sections-untrackable');
    $block = createLinkBlockFor($tenant, ['block_group' => 'sections', 'block_type' => 'custom_html']);

    $response = $this->postJson('/api/public/analytics/clicks', [
        'site_id' => $tenant->site->id,
        'block_id' => $block->id,
        'visitor_id' => (string) Str::uuid(),
    ]);

    $response->assertStatus(201);
    expect(DB::connection('pgsql')->table('analytics.link_clicks')->count())->toBe(0);
});
```

(The two passing cases in this file — `records a click on a bio section block` and `records a click on a documents section block` — stay green: `bio`/`documents` are in `section_block_types`, so the writer persists them. Confirm the file imports `Str` + `DB`; add `use` lines if the new assertion needs them.)

- [ ] **Step 2: Write the contract test**

```php
<?php
// tests/Feature/Analytics/AsyncIngestContractTest.php

use App\Jobs\Analytics\RecordAnalyticsEventJob;
use App\Services\Analytics\Contracts\AnalyticsIngestor;
use App\Services\Analytics\Ingestors\QueuedIngestor;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// These assert the ASYNC contract, so they force the QueuedIngestor (the default
// testing binding is SyncIngestor, which writes inline). With the queue faked, the job
// is captured but never run — so we assert dispatch behaviour, not rows.
beforeEach(function () {
    tenantHelpersEnsureTables();
    setupBlocksTable();
    app()->bind(AnalyticsIngestor::class, QueuedIngestor::class);
    Queue::fake();
});

it('accepts a pageview, echoes a visit_id, and enqueues a job', function () {
    $t = createBrandTenant('contract-pv');

    $res = $this->postJson('/api/public/analytics/pageviews', ['site_id' => $t->site->id]);

    $res->assertStatus(201)->assertJsonStructure(['message', 'visit_id']);
    Queue::assertPushed(RecordAnalyticsEventJob::class, fn ($j) => $j->queue === 'analytics');
});

it('records a pageview even for a bot UA (no bot filter on pageview — preserved)', function () {
    $t = createBrandTenant('contract-pv-bot');

    $this->withHeader('User-Agent', 'Googlebot/2.1')
        ->postJson('/api/public/analytics/pageviews', ['site_id' => $t->site->id])
        ->assertStatus(201);

    Queue::assertPushed(RecordAnalyticsEventJob::class);
});

it('accepts a click to a non-existent block (201, not 422) and enqueues — worker drops', function () {
    $t = createBrandTenant('contract-click-missing');

    $res = $this->postJson('/api/public/analytics/clicks', [
        'site_id' => $t->site->id,
        'block_id' => (string) Str::uuid(), // valid uuid, no row
        'visitor_id' => (string) Str::uuid(),
    ]);

    $res->assertStatus(201)->assertJsonStructure(['message', 'click_id']);
    Queue::assertPushed(RecordAnalyticsEventJob::class);
});

it('bot click returns 200 with no click_id and enqueues nothing', function () {
    $t = createBrandTenant('contract-click-bot');
    $block = createLinkBlockFor($t);

    $res = $this->withHeader('User-Agent', 'Googlebot/2.1')
        ->postJson('/api/public/analytics/clicks', [
            'site_id' => $t->site->id, 'block_id' => $block->id, 'visitor_id' => (string) Str::uuid(),
        ]);

    $res->assertStatus(200)->assertJsonMissing(['click_id' => true]);
    Queue::assertNothingPushed();
});

it('dedups a repeat click, echoing the original id and enqueuing once', function () {
    $t = createBrandTenant('contract-click-dedup');
    $block = createLinkBlockFor($t);
    $payload = ['site_id' => $t->site->id, 'block_id' => $block->id, 'visitor_id' => (string) Str::uuid()];

    $first = $this->postJson('/api/public/analytics/clicks', $payload);
    $second = $this->postJson('/api/public/analytics/clicks', $payload);

    $first->assertStatus(201);
    $second->assertStatus(201);
    expect($second->json('click_id'))->toBe($first->json('click_id'));
    Queue::assertPushed(RecordAnalyticsEventJob::class, 1);
});

it('preserves the 422 IDOR signal when site_id is supplied with a mismatched subdomain', function () {
    $t = createBrandTenant('contract-idor');

    $res = $this->postJson('/api/public/analytics/pageviews', [
        'site_id' => $t->site->id,
        'subdomain' => 'someone-elses-handle',
    ]);

    $res->assertStatus(422);
    Queue::assertNothingPushed();
});
```

- [ ] **Step 3: Run the analytics suite**

Run: `php artisan test tests/Feature/Analytics tests/Unit/Analytics`
Expected: PASS — including the unchanged `ClickDedupTest` (Redis dedup + SyncIngestor still yields 1 row), `ClickBotFilterTest`, `QueryPlanTest`, the rewritten section IDOR case, and the rewritten `TopSectionsExpandedTypesTest` untrackable case (now 201 + 0 rows).

> If `ClickDedupTest`'s within-window cases regress, the cause is the array cache store not persisting across the two `postJson` calls in one test. It does within a single test (same app instance). If a future change breaks that, switch those tests' driver to `redis` via `config(['cache.default' => 'array'])` is already in effect — investigate before weakening the assertion.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Analytics/SectionSeenIngestTest.php tests/Feature/Analytics/TopSectionsExpandedTypesTest.php tests/Feature/Analytics/AsyncIngestContractTest.php
git commit -m "test(analytics): async contract coverage + section IDOR & untrackable click now 201 (writer drops)"
```

---

## Task 9: Full-suite verification + handoff notes

- [ ] **Step 1: Run the whole suite**

Run: `composer test`
Expected: green. Pay attention to `tests/Feature/Security/TenantIsolation/PublicAnalyticsIdorTest.php` (422 IDOR) and `tests/Feature/Staff/StaffAnalyticsControllerTest.php` (read path) — both must stay green.

- [ ] **Step 2: Confirm no stray writes remain in the controller**

Run: `grep -nE "->save\(\)|::create\(|->insert" app/Http/Controllers/Api/PublicSite/AnalyticsController.php`
Expected: no matches (all persistence is now via the ingestor/writer).

- [ ] **Step 3: Operational note (no code) — Horizon**

No Horizon change is required: `supervisor-analytics` already consumes the `analytics` queue on the default `redis` connection. Confirm in `config/horizon.php` that `supervisor-analytics`'s `queue` array still includes `'analytics'` (it does today). On deploy, the existing worker drains the new jobs. If the worker is down, jobs buffer in Redis and drain on recovery — PK-idempotent, so the drain cannot double-count.

- [ ] **Step 4: Operational note (no code) — drop-rate observability**

The writer emits `Log::warning('analytics.ingest.dropped', ...)`. To get paged on a sustained drop spike (frontend sending stale block_ids, or enumeration), add a Nightwatch dashboard alert on that log event's rate — this is a dashboard/ops task, not code. Until configured, drops are visible in logs but do not page.

---

## Self-Review (completed by plan author)

**Spec coverage:** §3.1 DTO → Task 1. §3.2 ingestors + fail-open → Task 5. §3.3 writer + idempotency + per-type mapping + writeMany → Task 3. §3.4 job + retries + drop breadcrumb → Tasks 4 + 3. §4 request flow + full decouple → Task 7. §4.1 per-endpoint contract (422 IDOR, bot 200-no-id, pageview no-filter/no-dedup) → Tasks 7 + 8. §5 dedup → Task 2 + Task 7 wiring. §6 cache bump moved to job → Task 4. §7 queue → Task 4 config + Task 9 note (deviation: reuse existing queue). §9 tests → Tasks 1-8. §10 file inventory → matches.

**Deviations flagged** (top of plan): reuse existing `analytics` queue (not `redis_analytics`); drop metric = breadcrumb not Nightwatch-code; remove `ClickRequest` exists-rule; full enumerated status changes (`/clicks` missing/foreign/untrackable/inactive 404·422→201 + section IDOR/missing 404→201) with the no-cross-site-row invariant preserved at the writer; `/clicks` message constant; Redis-only dedup is a deliberate degrade vs the old DB dedup (no Redis-outage backstop, single-identifier keying), not parity.

**Tests touched** (beyond new files): `SectionSeenIngestTest` (cross-site 404→201, Task 8 Step 1) and `TopSectionsExpandedTypesTest` (untrackable click 422→201, Task 8 Step 1b). Facade-using unit tests (`AnalyticsDedupGuardTest`, `QueuedIngestorTest`) carry `uses(Tests\TestCase::class)` — `tests/Pest.php` binds only `Feature` automatically.

**Type consistency:** `AnalyticsEvent` constructor/`fromArray`/`toArray` keys aligned across Tasks 1/3/4/5/7. `AnalyticsDedupGuard::claim(): array{novel,id}` consumed identically in Task 7. `AnalyticsEventWriter::write/writeMany` defined in Task 1, implemented in Task 3, consumed in Tasks 4/5. Queue name `'analytics'` consistent (config + job + assertions).

**No placeholders:** every code step is complete; every run step has an expected result.
