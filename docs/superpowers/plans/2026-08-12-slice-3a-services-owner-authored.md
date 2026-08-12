# Slice 3a — Owner-Authored Services onto `content.*` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the 21 owner-authored `site.services` rows onto `content.*`, give the `service` kind a pool, and switch the public services section and 8 dashboard endpoints to read and write it.

**Architecture:** A backfiller writes through the slice-0b manual lane (`ProjectionWriter::writeManualItem()`) — never raw SQL into `content.*`. Owner-chosen ordering is carried as `site.section_items` pins (`state='pinned'`, float `sort_key`), not as a new rule-DSL ordering operator. The public read switches to `PoolResolver`, then the write endpoints follow, so data is proven before anything depends on it.

**Tech Stack:** PHP 8.4, Laravel 12, Pest 4, PostgreSQL (Supabase). Tests run SQLite in-memory; production is Postgres.

**Spec:** `docs/superpowers/specs/2026-08-12-slice-3a-services-owner-authored-design.md`

## Global Constraints

- **Never create Laravel migration files.** Schema changes are raw SQL under `supabase/migrations/`. **3a is expected to need none** — every destination table and constraint already exists. The pre-assigned prefix block `20260813090000`–`20260813099999` should be left unconsumed; if it stays unused, say so in the checkpoint so 3b knows it is free.
- **All `content.*` writes go through `ProjectionWriter`.** No `DB::table('content.…')->insert()` in new code.
- **Every raw-write seam invalidates three lanes** (spec §4): `BuildState::bump($siteId)`, `UPDATE site.sites SET updated_at`, and `CloudflareCachePurgeJob::dispatch($subdomain)`. There is **no CI check** enforcing this despite `BuildState`'s docblock claiming one — assert it in tests directly.
- **Never write `content.source_items.removed_at` for a user deletion.** It is cleared on reappearance; `content.items.removed_at` is the one-way column and the correct home.
- Authorization via policies only — `$this->authorizeForUser($user, …)`, never `abort_unless(...403)`. `Auth::user()` is always null under Supabase JWT.
- Resource classes for all API responses; Form Requests for validation. Wire shapes must not change.
- 4-space indent, LF. Comments explain WHY. Run `php artisan pint` before each commit.
- Tests: `./vendor/bin/pest --filter=<Name>`. Note `composer test --filter` is broken in this repo — call the binary directly.

---

### Task 1: `PoolRegistry` gains the `services` pool

**Files:**
- Modify: `app/Site/Pools/PoolRegistry.php` (class docblock, `POOLS`, `PAGE_KEYS`, `PAGE_LABELS`, `SECTION_SHAPE`)
- Test: `tests/Feature/Content/ServicesPoolTest.php` (create)

**Interfaces:**
- Consumes: nothing.
- Produces: pool key `'services'` owning kind `'service'`; `PoolRegistry::sectionKey('services') === 'pool:services'`; section shape `['rule' => [['op' => 'kind_is']], 'order_by' => 'recency']`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Content/ServicesPoolTest.php`:

```php
<?php

use App\Site\Pools\PoolRegistry;

// Slice 3a §3.2: the service kind gets a pool. Not in LATEST_TAG_POOLS —
// a "latest service" is meaningless.

it('owns the service kind and nothing else', function () {
    expect(PoolRegistry::POOLS['services'])->toBe(['service']);
    expect(PoolRegistry::isPool('services'))->toBeTrue();
});

it('is not a Latest-tag pool', function () {
    expect(PoolRegistry::LATEST_TAG_POOLS)->not->toContain('services');
});

it('carries the reconciled shape for priced, undated items', function () {
    // Reconciled with slice 5a 2026-08-12: same rule, same ordering, so slice 4
    // inherits one convention rather than two. Hand-ordering is expressed by
    // PINNING, never by a new order_by operator.
    expect(PoolRegistry::SECTION_SHAPE['services'])->toBe([
        'rule' => [['op' => 'kind_is']],
        'order_by' => 'recency',
    ]);
});

it('gives the pool a page and a label', function () {
    expect(PoolRegistry::PAGE_KEYS['services'])->toBe('services');
    expect(PoolRegistry::PAGE_LABELS['services'])->toBe('Services');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest --filter=ServicesPoolTest`
Expected: FAIL — `Undefined array key "services"`.

- [ ] **Step 3: Add the registry entries**

In `app/Site/Pools/PoolRegistry.php`, add to each constant:

```php
    public const POOLS = [
        'watch' => ['video'],
        'listen' => ['track', 'release', 'episode'],
        'media' => ['media'],
        'events' => ['event'],
        'services' => ['service'],
    ];
```

```php
    public const PAGE_KEYS = [
        'watch' => 'watch',
        'listen' => 'listen',
        'media' => 'gallery',
        'events' => 'events',
        'services' => 'services',
    ];

    public const PAGE_LABELS = [
        'watch' => 'Watch',
        'listen' => 'Listen',
        'media' => 'Gallery',
        'events' => 'Events',
        'services' => 'Services',
    ];
```

Add to `SECTION_SHAPE`:

```php
        // Priced, undated. Same shape slice 5a uses for products, reconciled
        // 2026-08-12 so slice 4 inherits one convention. order_by governs only
        // UNPINNED items; owner ordering is carried by pins (§3.3), which is
        // why no `position` operator exists and none should be added — the
        // rule DSL spans four registries and missing one is a 500, not a red
        // test.
        'services' => [
            'rule' => [['op' => 'kind_is']],
            'order_by' => 'recency',
        ],
```

- [ ] **Step 4: Correct the class docblock**

The docblock currently asserts the opposite of what now ships. Replace this sentence:

```
 * Sell / Services / Menu are NOT here: they keep their existing live lanes
 * (shop selections, hiddenServiceIds), which already implement
 * sources→selection in their own machinery.
```

with:

```
 * Sell / Menu are NOT here: they keep their existing live lanes (shop
 * selections), which already implement sources→selection in their own
 * machinery. Services JOINED 2026-08-12 (slice 3a) for the owner-authored
 * half; the Fresha half and its hiddenServiceIds lane follow in 3b, so both
 * run side by side until then.
```

A docblock asserting a design that no longer holds is how the phantom `BuildState` CI check propagated (parent §9.1).

- [ ] **Step 5: Run tests to verify they pass**

Run: `./vendor/bin/pest --filter=ServicesPoolTest`
Expected: PASS, 4 tests.

- [ ] **Step 6: Run the registry's own guard**

Run: `./vendor/bin/pest --filter=PoolRegistryTest`
Expected: PASS. It pins that a kind belongs to at most one pool; `service` belongs to no other, so this should be green without changes. **If it fails, stop and report** — it means another pool already claims the kind.

- [ ] **Step 7: Commit**

```bash
php artisan pint --dirty
git add app/Site/Pools/PoolRegistry.php tests/Feature/Content/ServicesPoolTest.php
git commit -m "feat(content): the service kind gets a pool

Shape reconciled with slice 5a: kind_is + recency for priced, undated items,
so slice 4 inherits one convention. Ordering is carried by pins, not a new
order_by operator — the rule DSL spans four registries and a miss is a 500.

The class docblock said Services were deliberately excluded. Corrected in the
same change rather than left to rot."
```

---

### Task 2: `ServiceBackfiller` — the mapping

**Files:**
- Create: `app/Services/Migration/ServiceBackfiller.php`
- Test: `tests/Feature/Content/ServiceBackfillerTest.php`

**Interfaces:**
- Consumes: `ProjectionWriter::writeManualItem(string $userId, string $coord, array $projection): string` from slice 0b. Projection shape: `['kind' => string, 'headline' => ?string, 'facets' => ['f_text' => ['headline'=>,'body'=>,'summary'=>], 'f_duration' => ['seconds'=>int]], 'offers' => [[...]]]`.
- Produces: `ServiceBackfiller::run(bool $dryRun = false, ?string $userId = null): array` returning `['backfilled' => int, 'retired' => int, 'skipped_no_user' => int, 'failed' => int]`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Content/ServiceBackfillerTest.php`:

```php
<?php

use App\Services\Migration\ServiceBackfiller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Slice 3a §3.1: the 21 owner-authored services become service-kind content
// items through the slice-0b manual lane. Production code, idempotent,
// re-runnable (convergence invariant #4).

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    Queue::fake();
});

function ownerService(string $userId, array $overrides = []): string
{
    $id = (string) Str::uuid();
    DB::table('site.services')->insert(array_merge([
        'id' => $id,
        'user_id' => $userId,
        'title' => 'Consultation',
        'description' => 'A chat about your hair.',
        'price_cents' => 6500,
        'currency_code' => 'AUD',
        'duration_minutes' => 45,
        'is_active' => true,
        'sort_order' => 0,
        'source' => null,
        'is_manual' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));

    return $id;
}

it('lands an owner-authored service as a content item on the manual source', function () {
    [$userId] = seedUserWithSite();
    $serviceId = ownerService($userId);

    $result = app(ServiceBackfiller::class)->run();

    expect($result['backfilled'])->toBe(1);

    $row = DB::table('content.source_items as si')
        ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
        ->join('content.items as i', 'i.id', '=', 'si.item_id')
        ->where('si.coord', 'manual:'.$serviceId)
        ->first(['cs.kind as source_kind', 'si.kind', 'i.headline_cache', 'i.removed_at', 'i.id as item_id']);

    expect($row->source_kind)->toBe('manual');
    expect($row->kind)->toBe('service');
    expect($row->headline_cache)->toBe('Consultation');
    expect($row->removed_at)->toBeNull();
});

it('writes price as an exact offer and duration in seconds', function () {
    [$userId] = seedUserWithSite();
    ownerService($userId, ['price_cents' => 12500, 'duration_minutes' => 90]);

    app(ServiceBackfiller::class)->run();

    $offer = DB::table('content.offers')->first();
    expect((int) $offer->amount_minor)->toBe(12500);
    expect($offer->currency)->toBe('AUD');
    expect($offer->qualifier)->toBe('exact');

    expect((int) DB::table('content.f_duration')->value('seconds'))->toBe(5400);
});

it('maps a hand-entered zero price to free, not exact-zero', function () {
    // §1.2: a TYPED zero means free. A SCRAPED zero means unparsed — that is
    // 3b's problem and must not be solved with this mapper.
    [$userId] = seedUserWithSite();
    ownerService($userId, ['price_cents' => 0]);

    app(ServiceBackfiller::class)->run();

    expect(DB::table('content.offers')->value('qualifier'))->toBe('free');
});

it('omits duration and body rows when the legacy columns are null', function () {
    [$userId] = seedUserWithSite();
    ownerService($userId, ['duration_minutes' => null, 'description' => null]);

    app(ServiceBackfiller::class)->run();

    expect(DB::table('content.f_duration')->count())->toBe(0);
    expect(DB::table('content.f_text')->value('body'))->toBeNull();
});

it('carries a soft delete to items.removed_at and NEVER to source_items', function () {
    // The whole point: source_items.removed_at is cleared on reappearance, so
    // writing it there would resurrect a service its owner deleted.
    [$userId] = seedUserWithSite();
    ownerService($userId, ['deleted_at' => now()]);

    $result = app(ServiceBackfiller::class)->run();

    expect($result['retired'])->toBe(1);
    expect(DB::table('content.items')->value('removed_at'))->not->toBeNull();
    expect(DB::table('content.source_items')->whereNotNull('removed_at')->count())->toBe(0);
});

it('ignores fresha-sourced rows entirely', function () {
    // §2: the 61 Fresha rows are NOT backfilled — the connector lands them.
    [$userId] = seedUserWithSite();
    ownerService($userId, ['source' => 'fresha', 'external_id' => 's:123']);

    $result = app(ServiceBackfiller::class)->run();

    expect($result['backfilled'])->toBe(0);
    expect(DB::table('content.items')->count())->toBe(0);
});

it('is idempotent across two runs', function () {
    [$userId] = seedUserWithSite();
    ownerService($userId);

    app(ServiceBackfiller::class)->run();
    app(ServiceBackfiller::class)->run();

    expect(DB::table('content.source_items')->count())->toBe(1);
    expect(DB::table('content.items')->count())->toBe(1);
    expect(DB::table('content.offers')->count())->toBe(1);
});

it('writes nothing under dry run but still counts', function () {
    [$userId] = seedUserWithSite();
    ownerService($userId);

    $result = app(ServiceBackfiller::class)->run(dryRun: true);

    expect($result['backfilled'])->toBe(1);
    expect(DB::table('content.items')->count())->toBe(0);
});
```

Add the shared helper at the top of the file, after `beforeEach`:

```php
function seedUserWithSite(): array
{
    $userId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    DB::table('core.users')->insert([
        'id' => $userId, 'email' => $userId.'@example.test',
        'account_type' => 'partna', 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('site.sites')->insert([
        'id' => $siteId, 'user_id' => $userId, 'subdomain' => 'site-'.substr($userId, 0, 8),
        'architecture_id' => 'staple', 'is_published' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$userId, $siteId];
}
```

**If `setupUsersTable()` / `setupSitesTable()` already provide a seeding helper**, use theirs instead — check `tests/Pest.php` first and do not duplicate one.

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest --filter=ServiceBackfillerTest`
Expected: FAIL — `Class "App\Services\Migration\ServiceBackfiller" not found`.

If instead it fails on a missing `site.services` table, add it to the SQLite stand-in in `tests/Pest.php` mirroring the real DDL: `id, user_id, title, description, price_cents, currency_code, duration_minutes, is_active, sort_order, created_at, updated_at, deleted_at, deleted_origin, source, is_manual, external_id`.

- [ ] **Step 3: Write the backfiller**

Create `app/Services/Migration/ServiceBackfiller.php`:

```php
<?php

namespace App\Services\Migration;

use App\Ingest\Projection\ProjectionWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Slice 3a §3.1: land the owner-authored services as service-kind content
 * items, through the slice-0b manual lane — NEVER raw writes into content.*.
 *
 * Coord manual:{service_uuid}: stable, so a re-run UPDATES (idempotent), and
 * the legacy identifier survives site.services' drop in slice 7. Stable
 * because the owner-authored write path updates rows in place — unlike
 * ShopCatalog::syncLatest(), which deletes and re-creates so shop uuids churn
 * every sync (slice 5a).
 *
 * Fresha-sourced rows are deliberately OUT of scope (§2): once 3b fixes the
 * connector they land natively under the Fresha source with real prices, and
 * backfilling them here would stamp owner-authorship on scraped data —
 * destroying the discriminator the two public surfaces key on.
 */
class ServiceBackfiller
{
    public function __construct(private readonly ProjectionWriter $writer) {}

    /** @return array{backfilled: int, retired: int, skipped_no_user: int, failed: int} */
    public function run(bool $dryRun = false, ?string $userId = null): array
    {
        $result = ['backfilled' => 0, 'retired' => 0, 'skipped_no_user' => 0, 'failed' => 0];

        $rows = DB::connection('pgsql')->table('site.services')
            ->whereNull('source')
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->orderBy('user_id')
            ->orderBy('sort_order')
            ->get();

        foreach ($rows as $service) {
            try {
                $owner = $service->user_id === null ? null : (string) $service->user_id;
                if ($owner === null) {
                    // Loud rather than silent: a skipped row is a service that
                    // vanishes from the count without anyone deciding to drop it.
                    $result['skipped_no_user']++;

                    continue;
                }

                $isDeleted = $service->deleted_at !== null;

                if ($dryRun) {
                    $isDeleted ? $result['retired']++ : $result['backfilled']++;

                    continue;
                }

                $this->writer->writeManualItem($owner, 'manual:'.$service->id, $this->projectionFor($service));

                if ($isDeleted) {
                    // items.removed_at ONLY. source_items.removed_at is cleared
                    // on reappearance, so a later run would resurrect a service
                    // its owner deleted.
                    $this->retire($owner, 'manual:'.$service->id, $service->deleted_at);
                    $result['retired']++;

                    continue;
                }

                $result['backfilled']++;
            } catch (\Throwable $e) {
                report($e);
                Log::warning('Service backfill failed for one row.', [
                    'service_id' => $service->id, 'error' => $e->getMessage(),
                ]);
                $result['failed']++;
            }
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function projectionFor(object $service): array
    {
        $title = trim((string) ($service->title ?? ''));
        $description = trim((string) ($service->description ?? ''));

        $projection = [
            'kind' => 'service',
            'headline' => $title,
            'facets' => ['f_text' => array_filter([
                'headline' => $title !== '' ? $title : null,
                'body' => $description !== '' ? $description : null,
            ], static fn ($v) => $v !== null)],
        ];

        if ($service->duration_minutes !== null) {
            $projection['facets']['f_duration'] = ['seconds' => ((int) $service->duration_minutes) * 60];
        }

        if ($service->price_cents !== null) {
            $cents = (int) $service->price_cents;
            $projection['offers'] = [[
                // §1.2: a HAND-ENTERED zero means free. Scraped zeros are 3b's
                // problem and must not be routed through this mapper.
                'qualifier' => $cents === 0 ? 'free' : 'exact',
                'amount_minor' => $cents === 0 ? null : $cents,
                'currency' => $cents === 0 ? null : (string) $service->currency_code,
            ]];
        }

        return $projection;
    }

    /** Retire the item behind a coord — items.removed_at only, never source_items. */
    private function retire(string $userId, string $coord, mixed $deletedAt): void
    {
        $itemId = DB::connection('pgsql')->table('content.source_items as si')
            ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
            ->where('cs.user_id', $userId)
            ->where('si.coord', $coord)
            ->value('si.item_id');

        if ($itemId === null) {
            return;
        }

        DB::connection('pgsql')->table('content.items')
            ->where('id', $itemId)
            ->whereNull('removed_at')
            ->update(['removed_at' => $deletedAt, 'updated_at' => now()]);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest --filter=ServiceBackfillerTest`
Expected: PASS, 8 tests.

If the `offers` write does not land, check `ProjectionWriter`'s offer key vocabulary at the `'offers' => $this->rowsFor(...)` site (`ProjectionWriter.php:1020, 1090`) and match its expected keys exactly rather than guessing.

- [ ] **Step 5: Commit**

```bash
php artisan pint --dirty
git add app/Services/Migration/ServiceBackfiller.php tests/Feature/Content/ServiceBackfillerTest.php
git commit -m "feat(migration): ServiceBackfiller — owner-authored services through the manual lane

Fresha rows are deliberately out of scope: 3b's connector lands them natively
with real prices, and backfilling them here would stamp owner-authorship on
scraped data.

A hand-entered zero price maps to qualifier=free. A scraped zero does NOT mean
free — all 61 Fresha rows carry price_cents 0 and none is free — which is why
that mapping lives here and not in a shared helper 3b could reach for."
```

---

### Task 3: Pins, the artisan command, and the three invalidation lanes

**Files:**
- Modify: `app/Services/Migration/ServiceBackfiller.php`
- Create: `app/Console/Commands/BackfillOwnerServices.php`
- Modify: `tests/Feature/Content/ServiceBackfillerTest.php`

**Interfaces:**
- Consumes: `PoolSectionProvisioner::ensure(Site $site, string $pool): object`; `SectionItem::STATE_PINNED`; `BuildState::bump(string $siteId): void`; `CloudflareCachePurgeJob::dispatch(string $subdomain)`.
- Produces: artisan command `content:backfill-owner-services {--dry-run} {--user=}`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Content/ServiceBackfillerTest.php`:

```php
it('pins each service at its sort_order so the owner ordering survives', function () {
    [$userId, $siteId] = seedUserWithSite();
    $second = ownerService($userId, ['title' => 'Second', 'sort_order' => 1]);
    $first = ownerService($userId, ['title' => 'First', 'sort_order' => 0]);

    app(ServiceBackfiller::class)->run();

    $pins = DB::table('site.section_items as si')
        ->join('content.source_items as csi', 'csi.item_id', '=', 'si.item_id')
        ->whereIn('csi.coord', ['manual:'.$first, 'manual:'.$second])
        ->orderBy('si.sort_key')
        ->pluck('csi.coord')
        ->all();

    expect($pins)->toBe(['manual:'.$first, 'manual:'.$second]);
    expect(DB::table('site.section_items')->value('state'))->toBe('pinned');
});

it('does not pin a soft-deleted service', function () {
    [$userId] = seedUserWithSite();
    ownerService($userId, ['deleted_at' => now()]);

    app(ServiceBackfiller::class)->run();

    expect(DB::table('site.section_items')->count())->toBe(0);
});

it('invalidates all three cache lanes for each touched site', function () {
    // No CI check enforces this despite BuildState's docblock claiming one
    // (parent §9.1), so it is asserted directly.
    [$userId, $siteId] = seedUserWithSite();
    ownerService($userId);
    $before = DB::table('site.sites')->where('id', $siteId)->value('updated_at');

    app(ServiceBackfiller::class)->run();

    expect(DB::table('site.site_build_state')->where('site_id', $siteId)->value('content_revision'))
        ->toBeGreaterThan(0);
    expect(DB::table('site.sites')->where('id', $siteId)->value('updated_at'))->not->toBe($before);
    Queue::assertPushed(\App\Jobs\Cloudflare\CloudflareCachePurgeJob::class);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest --filter=ServiceBackfillerTest`
Expected: FAIL — the three new tests fail; the eight from Task 2 still pass.

- [ ] **Step 3: Add pinning and invalidation to the backfiller**

Add imports and two methods to `ServiceBackfiller`:

```php
use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\SectionItem;
use App\Models\Core\Site\Site;
use App\Site\Documents\BuildState;
use App\Site\Pools\PoolSectionProvisioner;
```

Constructor:

```php
    public function __construct(
        private readonly ProjectionWriter $writer,
        private readonly PoolSectionProvisioner $sections,
    ) {}
```

In `run()`, collect touched sites and pin live rows. Replace the success branch:

```php
                $itemId = $this->writer->writeManualItem($owner, 'manual:'.$service->id, $this->projectionFor($service));

                if ($isDeleted) {
                    $this->retire($owner, 'manual:'.$service->id, $service->deleted_at);
                    $result['retired']++;

                    continue;
                }

                $site = Site::query()->where('user_id', $owner)->first();
                if ($site !== null) {
                    $this->pin($site, $itemId, (int) ($service->sort_order ?? 0));
                    $touchedSites[(string) $site->id] = true;
                }

                $result['backfilled']++;
```

with `$touchedSites = [];` declared beside `$result`, and after the loop:

```php
        if (! $dryRun) {
            $this->invalidate(array_keys($touchedSites));
        }
```

Add the two methods:

```php
    /**
     * Owner ordering lives in the CURATION half. The auto half offers only
     * recency/alphabetical/occurrence (SectionCandidates:105), none of which
     * preserve a hand-chosen sort_order — and SectionCandidates:119 excludes
     * pinned ids from the auto half, so there is no duplication.
     *
     * A pin also protects the row: mergeInto()'s hasCuration check reads
     * site.section_items, so a pinned item cannot be hard-deleted by a merge
     * (parent §8.3).
     */
    private function pin(Site $site, string $itemId, int $sortOrder): void
    {
        $section = $this->sections->ensure($site, 'services');

        $row = SectionItem::query()
            ->where('section_id', $section->id)
            ->where('item_id', $itemId)
            ->first() ?? new SectionItem;

        $row->section_id = (string) $section->id;
        $row->item_id = $itemId;
        $row->state = SectionItem::STATE_PINNED;
        $row->sort_key = (float) $sortOrder;
        if (! $row->exists) {
            $row->created_at = now();
        }
        $row->save();
    }

    /**
     * Raw-write seam — all three lanes per touched site (spec §4).
     * writeManualItem() bumped build state per item already; updated_at and
     * the edge purge are the two lanes it deliberately does not own.
     *
     * @param  list<string>  $siteIds
     */
    private function invalidate(array $siteIds): void
    {
        foreach ($siteIds as $siteId) {
            BuildState::bump($siteId);
            DB::connection('pgsql')->table('site.sites')
                ->where('id', $siteId)->update(['updated_at' => now()]);
            $subdomain = (string) (DB::connection('pgsql')->table('site.sites')
                ->where('id', $siteId)->value('subdomain') ?? '');
            if ($subdomain !== '') {
                CloudflareCachePurgeJob::dispatch($subdomain);
            }
        }
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest --filter=ServiceBackfillerTest`
Expected: PASS, 11 tests.

- [ ] **Step 5: Write the artisan command**

Create `app/Console/Commands/BackfillOwnerServices.php`:

```php
<?php

namespace App\Console\Commands;

use App\Services\Migration\ServiceBackfiller;
use Illuminate\Console\Command;

/**
 * Slice 3a §3.1: carry the owner-authored site.services rows onto content.*.
 * Idempotent on the coord, so re-running is safe. Read-only under --dry-run.
 */
class BackfillOwnerServices extends Command
{
    protected $signature = 'content:backfill-owner-services {--dry-run} {--user= : limit to one user id}';

    protected $description = 'Backfill owner-authored services into content.* through the manual lane';

    public function handle(ServiceBackfiller $backfiller): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $backfiller->run($dryRun, $this->option('user'));

        $this->line(($dryRun ? '[dry-run] would backfill ' : 'backfilled ').$result['backfilled']
            .', retired '.$result['retired']
            .', skipped (no user) '.$result['skipped_no_user']
            .', failed '.$result['failed']);

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
```

- [ ] **Step 6: Verify the command registers and dry-runs clean**

Run: `php artisan content:backfill-owner-services --dry-run`
Expected: exit 0, a line reporting counts. Against a local empty DB the counts are zeros; that is a pass.

- [ ] **Step 7: Commit**

```bash
php artisan pint --dirty
git add app/Services/Migration/ServiceBackfiller.php app/Console/Commands/BackfillOwnerServices.php tests/Feature/Content/ServiceBackfillerTest.php
git commit -m "feat(migration): pin backfilled services at sort_order, invalidate all three lanes

Ordering rides in the curation half because the auto half cannot express a
hand-chosen order, and SectionCandidates excludes pinned ids so nothing
duplicates. A pin also makes the row uncollapsible by mergeInto()'s hard delete.

All three invalidation lanes asserted directly — no CI check enforces them
despite BuildState's docblock claiming one."
```

---

### Task 4: Switch the public read to the pool

**Files:**
- Modify: `app/Services/PublicSite/SitepageDataResolverService.php:289` and `:930`
- Modify: `app/Services/User/Visibility/Rules/ServicesVisibility.php:27`
- Modify: `app/Services/User/Visibility/Rules/BookingVisibility.php:30`
- Test: `tests/Feature/Content/ServicesPublicReadTest.php` (create)

**Interfaces:**
- Consumes: `ServiceBackfiller` output; `PoolRegistry::POOLS['services']`.
- Produces: `buildServicesData()` returns the same wire shape, sourced from `content.*`.

**Do NOT touch `PurgeSoftDeleted.php:107`** — it purges `site.services` rows, which still exist and are still the Fresha half's store until slice 7. It is the one `whereNull('source')` call site that is correct as-is. Note this in the wire manifest so 3b does not "finish the job" by mistake.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Content/ServicesPublicReadTest.php`:

```php
<?php

use App\Services\Migration\ServiceBackfiller;
use App\Services\PublicSite\SitepageDataResolverService;
use App\Models\Core\Site\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// Slice 3a §3.4: the services section renders from content.*, and a
// Fresha-sourced service must never appear in it (the kickoff's two-surface
// rule — the trap this slice exists to avoid).

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    Queue::fake();
});

it('renders backfilled owner services in the owner ordering', function () {
    [$userId, $siteId] = seedUserWithSite();
    ownerService($userId, ['title' => 'Second', 'sort_order' => 1, 'price_cents' => 9000]);
    ownerService($userId, ['title' => 'First', 'sort_order' => 0, 'price_cents' => 5000]);
    app(ServiceBackfiller::class)->run();

    $site = Site::query()->find($siteId);
    $data = app(SitepageDataResolverService::class)->buildServicesData($site, $userId);

    expect(array_column($data['services'], 'title'))->toBe(['First', 'Second']);
});

it('never renders a fresha-sourced service in the services section', function () {
    [$userId, $siteId] = seedUserWithSite();
    ownerService($userId, ['title' => 'Mine']);
    app(ServiceBackfiller::class)->run();

    // A Fresha service item, landed as a connection source would land it.
    freshaServiceItem($userId, 'Fresha Cut');

    $site = Site::query()->find($siteId);
    $data = app(SitepageDataResolverService::class)->buildServicesData($site, $userId);

    expect(array_column($data['services'], 'title'))->toBe(['Mine']);
});

it('hides the services section when the owner has no live services', function () {
    [$userId, $siteId] = seedUserWithSite();
    ownerService($userId, ['deleted_at' => now()]);
    app(ServiceBackfiller::class)->run();

    $site = Site::query()->find($siteId);
    $data = app(SitepageDataResolverService::class)->buildServicesData($site, $userId);

    expect($data['services'])->toBe([]);
});
```

Add the Fresha-item helper to the same file:

```php
// A service item landed by a CONNECTION source — what 3b's connector will
// produce. Written directly because 3a has no connector to run.
function freshaServiceItem(string $userId, string $title): string
{
    $sourceId = (string) \Illuminate\Support\Str::uuid();
    $itemId = (string) \Illuminate\Support\Str::uuid();
    DB::table('content.sources')->insert([
        'id' => $sourceId, 'user_id' => $userId, 'kind' => 'connection',
        'priority' => 100, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.items')->insert([
        'id' => $itemId, 'user_id' => $userId, 'kind' => 'service',
        'headline_cache' => $title, 'facets_cache' => '{}', 'eligible_cache' => '{}',
        'first_seen_at' => now(), 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.source_items')->insert([
        'id' => (string) \Illuminate\Support\Str::uuid(), 'source_id' => $sourceId,
        'coord' => 'fresha:acct:s:999', 'item_id' => $itemId, 'kind' => 'service',
        'projector_version' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);

    return $itemId;
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest --filter=ServicesPublicReadTest`
Expected: FAIL — the first test returns `[]` because `buildServicesData()` still reads `site.services`, where the backfill wrote nothing new.

- [ ] **Step 3: Switch `buildServicesData()` to `content.*`**

In `SitepageDataResolverService::buildServicesData()` (`:916`), replace the `Service::query()` block at `:925-935`. Read live service-kind items on the user's MANUAL source, ordered by their pin:

```php
        // Slice 3a §3.4: owner-authored services live in content.* now. The
        // manual-source filter replaces the old whereNull('source') — same
        // split, different mechanism: Fresha projections belong to the booking
        // surface, never the services section.
        $rows = DB::connection('pgsql')->table('content.items as i')
            ->join('content.source_items as si', 'si.item_id', '=', 'i.id')
            ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
            ->leftJoin('site.section_items as sec', 'sec.item_id', '=', 'i.id')
            ->where('i.user_id', $proId)
            ->where('i.kind', 'service')
            ->whereNull('i.removed_at')
            ->whereNull('si.removed_at')
            ->where('cs.kind', 'manual')
            ->where(fn ($q) => $q->whereNull('sec.state')->orWhere('sec.state', '!=', 'excluded'))
            ->orderByRaw('sec.sort_key ASC NULLS LAST')
            ->orderBy('i.headline_cache')
            ->distinct()
            ->get(['i.id', 'i.headline_cache']);
```

then map each row to the existing wire shape, reading `f_text.body`, `offers` and `f_duration` per item. **Preserve every key the current `->map()` emits** — open it and copy the key list exactly; a dropped key is a silent wire break.

- [ ] **Step 4: Switch the two visibility rules**

`ServicesVisibility:27` and `BookingVisibility:30` each build a `has_active_service` subquery against `site.services`. Replace both with the equivalent against `content.items` + the manual source, keeping the semantics identical:

```php
            'has_active_service' => DB::connection('pgsql')->table('content.items as i')
                ->join('content.source_items as si', 'si.item_id', '=', 'i.id')
                ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
                ->select(DB::raw('1'))
                ->where('i.user_id', $userId)
                ->where('i.kind', 'service')
                ->whereNull('i.removed_at')
                ->where('cs.kind', 'manual')
                ->getQuery(),
```

`BookingVisibility`'s comment says the gate is "at least one active MANUAL service (Fresha projections don't change this gate)". That stays true — update the comment to name the new mechanism, not the old column.

- [ ] **Step 5: Switch the page-presence check**

`SitepageDataResolverService:289` uses the same `whereNull('source')` existence check for `active_services_exists`. Replace it with the same manual-source existence query. Keep it inside `safeQuery()` — the resilience posture must not change.

- [ ] **Step 6: Run tests to verify they pass**

Run: `./vendor/bin/pest --filter=ServicesPublicReadTest`
Expected: PASS, 3 tests.

- [ ] **Step 7: Run the surrounding suites for regressions**

Run: `./vendor/bin/pest tests/Feature/Api/PublicSite tests/Feature/Content tests/Unit/Site`
Expected: PASS. **If a public-profile snapshot test fails on key order or a missing key, stop and report** — that is a wire break, not a test to update.

- [ ] **Step 8: Commit**

```bash
php artisan pint --dirty
git add app/Services/PublicSite/SitepageDataResolverService.php app/Services/User/Visibility/Rules/ServicesVisibility.php app/Services/User/Visibility/Rules/BookingVisibility.php tests/Feature/Content/ServicesPublicReadTest.php
git commit -m "feat(publicsite): the services section renders from content.*

Four of the five whereNull('source') call sites move to a manual-source filter
— same split, different mechanism. PurgeSoftDeleted:107 deliberately stays: it
purges site.services rows, which remain the Fresha half's store until slice 7.

A test fails if a Fresha-sourced service reaches the services section — the
two-surface rule the kickoff calls the trap in this slice."
```

---

### Task 5: Cut the 8 owner-authored endpoints over

**Files:**
- Modify: `app/Http/Controllers/Api/User/SiteManagement/UserServiceController.php`
- Test: `tests/Feature/Api/User/ServiceEndpointCutoverTest.php` (create)

**Interfaces:**
- Consumes: `ProjectionWriter::writeManualItem()`; `ServiceBackfiller`'s coord convention `manual:{uuid}`.
- Produces: the same 8 wire contracts, reading and writing `content.*`.

**Scope:** `index` (309), `store` (310), `show` (311), `update` (313), `destroy` (315), `reorder` (317), `reorderLayout` (341), `restore` (323). **Leave `resync`, `resyncBulk`, `updateCategory` and every `/service-categories/*` route untouched** — they are 3b's.

- [ ] **Step 1: Read the controller before changing it**

Open `app/Http/Controllers/Api/User/SiteManagement/UserServiceController.php` in full and write down, for each of the 8 methods, the exact response shape its Resource emits. **The wire must not change.** If a method returns `ServiceResource`, the new `content.*`-backed path must produce an object with the identical keys.

- [ ] **Step 2: Write the failing tests**

Create `tests/Feature/Api/User/ServiceEndpointCutoverTest.php` covering, at minimum:

```php
it('creates a service that appears in the public payload immediately', function () {
    // The bug slice 2 shipped: dashboard wrote a lane the pool does not read,
    // so every write silently failed to reach the site.
    [$userId, $siteId] = seedUserWithSite();

    $response = $this->withUser($userId)->postJson('/api/professional/services', [
        'title' => 'New Service', 'price_cents' => 8000, 'currency_code' => 'AUD',
        'duration_minutes' => 30,
    ]);

    $response->assertCreated();

    $site = \App\Models\Core\Site\Site::query()->find($siteId);
    $data = app(\App\Services\PublicSite\SitepageDataResolverService::class)
        ->buildServicesData($site, $userId);

    expect(array_column($data['services'], 'title'))->toContain('New Service');
});

it('keeps the response shape unchanged', function () { /* assertJsonStructure with the keys from Step 1 */ });

it('reorders by moving the pin, and the public order follows', function () { /* … */ });

it('deletes to items.removed_at and never to source_items.removed_at', function () { /* … */ });

it('restores by clearing items.removed_at', function () { /* … */ });

it('a projection run after a restore does not re-clear or re-set removed_at', function () {
    // The one-way rule is a property of the SYNC path and stays intact; only
    // the explicit endpoint may clear it.
});
```

Replace `withUser()` with whatever this repo's authenticated-request helper is — check an existing `tests/Feature/Api/User/` test and copy its auth setup exactly rather than inventing one.

- [ ] **Step 3: Run tests to verify they fail**

Run: `./vendor/bin/pest --filter=ServiceEndpointCutoverTest`
Expected: FAIL — the created service does not reach the public payload, because `store()` still writes `site.services`.

- [ ] **Step 4: Cut the read methods over**

`index` and `show` read the same manual-source query Task 4 established. Extract that query into a single private method or a small query object used by both the controller and `SitepageDataResolverService` — **do not copy it twice.** If it is duplicated, the two will drift, which is the class of bug this slice exists to fix.

- [ ] **Step 5: Cut the write methods over**

- `store`: mint a uuid, `writeManualItem($userId, 'manual:'.$uuid, $projection)`, then pin at the next free `sort_key`.
- `update`: same coord, re-write the projection. Idempotent by construction.
- `destroy`: set `content.items.removed_at`. **Never** `source_items.removed_at`.
- `restore`: clear `content.items.removed_at` (spec §3.5 — legitimate here, and only here).
- `reorder` / `reorderLayout`: rewrite `section_items.sort_key`.

Every one of these is a raw-write seam: all three invalidation lanes, per the Global Constraints. Reuse `ServiceBackfiller`'s `invalidate()` by extracting it to a shared helper rather than duplicating it a third time.

- [ ] **Step 6: Run tests to verify they pass**

Run: `./vendor/bin/pest --filter=ServiceEndpointCutoverTest`
Expected: PASS.

- [ ] **Step 7: Run the full API and authorization suites**

Run: `./vendor/bin/pest tests/Feature/Api/User tests/Feature/Authorization`
Expected: PASS. `ServicePolicy` still governs; if a policy test fails, the authorization seam moved and that needs reporting, not patching.

- [ ] **Step 8: Commit**

```bash
php artisan pint --dirty
git add app/Http/Controllers/Api/User/SiteManagement/UserServiceController.php tests/Feature/Api/User/ServiceEndpointCutoverTest.php
git commit -m "feat(api): cut the 8 owner-authored service endpoints over to content.*

Wire shapes unchanged. resync, updateCategory and the six category routes stay
on site.services — they are Fresha-shaped and belong to 3b.

restore clears items.removed_at from the explicit endpoint only; the sync
path's one-way rule, which slices 4-7 depend on, is untouched."
```

---

### Task 6: DSAR export re-sources from `content.*`

**Files:**
- Modify: `app/Services/.../DataExportPayloadBuilder.php` (locate with `grep -rn "class DataExportPayloadBuilder" app/`)
- Test: modify the existing DSAR export test (locate with `grep -rln "DataExportPayloadBuilder" tests/`)

**Interfaces:**
- Consumes: the manual-source query from Task 4.
- Produces: unchanged export section keys `services` and `service_categories`.

- [ ] **Step 1: Write the failing test**

Extend the existing DSAR test: after backfilling and then cutting over, a service created through the new endpoint must appear in the export. Assert the section key is still `services` — **not** a renamed one. The 2026-08-05 precedent is that DSAR allowlists retain legacy keys so previously-stored payloads stay disclosable.

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest --filter=DataExport`
Expected: FAIL — the newly created service is absent, because the builder still streams `site.services`.

- [ ] **Step 3: Re-source the services section**

Stream the manual-source `content.*` query instead of `site.services`. Keep `service_categories` reading `site.service_categories` for now — those rows are Fresha's and 3b owns them. Keep both keys in the declared return shape.

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest --filter=DataExport`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
php artisan pint --dirty
git add app/Services app/Http tests
git commit -m "fix(dsar): export services from content.* so the cutover does not stale the export

Section keys unchanged — the 2026-08-05 precedent keeps legacy DSAR keys so
previously-stored payloads stay disclosable. service_categories still reads the
legacy table; those rows are Fresha's and belong to 3b."
```

---

### Task 7: Full verification and the dev run

**Files:**
- Create: `docs/wire-changes/2026-08-12-slice-3a-services.md`
- Modify: the spec's checkpoint section

- [ ] **Step 1: Run the full suite**

Run: `composer test`
Expected: PASS. Compare any failure against `development` before assuming you caused it — this repo has known parallel flakes in `RunExecutorProjectionTest` that pass serially.

- [ ] **Step 2: Run PHPStan and Pint**

```bash
./vendor/bin/phpstan analyse --memory-limit=1G
php artisan pint --test
```
Expected: both green. Annotate the MODEL, never the call site, if PHPStan complains about a magic property.

- [ ] **Step 3: Run the schema lane**

Run: `composer test:schema`
Expected: PASS. A green `composer test` says nothing about applied-schema constraints.

- [ ] **Step 4: Write the wire manifest**

Create `docs/wire-changes/2026-08-12-slice-3a-services.md`: endpoint, before shape, after shape, consuming repo — for each of the 8 endpoints plus the public profile payload. State explicitly that shapes are **unchanged** and only the backing store moved. Name `PurgeSoftDeleted:107` as a deliberate non-change.

- [ ] **Step 5: Deploy to dev and run the backfill**

```bash
cloud command:run partna development "content:backfill-owner-services --dry-run"
cloud command:run partna development "content:backfill-owner-services"
```
Expected dry-run: `would backfill 18, retired 3, skipped (no user) 0, failed 0`.

**If the numbers differ from 18/3, stop and report** rather than proceeding — the spec's §1 figures were measured 2026-08-12 and a divergence means the data moved.

- [ ] **Step 6: Run the live assertions**

Run every query in spec §5.1 against dev and paste the output into the checkpoint. Gates: `source_items.removed_at` count = **0**; orphan items = **0**.

- [ ] **Step 7: Scan logs and Nightwatch**

```bash
cloud env:logs partna development --minutes 10
```
Expected: clean. Then check Nightwatch — slice 0's checkpoint records a log scan done and Nightwatch skipped; do not repeat that gap.

- [ ] **Step 8: Write the checkpoint and commit**

Append a checkpoint section to the spec with the SQL output, the Pest test names, and the log scan result. State whether the migration prefix block was consumed.

```bash
git add docs/
git commit -m "docs(checkpoint): slice 3a verified on dev"
```

---

## Known merge conflict — read both hunks, do not "take theirs"

`docs/superpowers/plans/2026-08-12-slice-4-menus-KICKOFF-PROMPT.md` is edited on
**both** this branch and `feat/slice-5a-shop-data`, in the same region. Whoever
rebases second gets a conflict.

The content is **compatible, not contradictory** — the reconciled `SECTION_SHAPE`
and the pinning rule are identical in both, and the remainder is additive on each
side:

| This branch adds | slice 5a adds |
|---|---|
| The reconciled shape + the pinning rule + why no `position` operator | The same shape and pinning rule |
| That 3a populates **no** collections, so slice 4 must not expect prior art from it | An `offers.availability` vocabulary line |
| | A coord-rule caveat (legacy uuids churn where the writer deletes and re-inserts) |

**Resolve as a union.** A fast `--theirs` or `--ours` silently drops half the
guidance from a prompt whose entire purpose is to stop slice 4 rediscovering
this.

Also inherited from that branch once it merges: `ProjectionWriter` gains a
`variants` projection key (additive — a projection without the key writes
nothing, so nothing in this plan changes). It is appended LAST to the `:1321`
eligibility scan because declaration order is part of the cached
`eligible_cache` value.

## Self-Review Notes

Checked against the spec:

- §3.1 backfiller → Task 2. §3.2 registry → Task 1. §3.3 pins → Task 3. §3.4 read switch → Task 4. §3.5 write cutover → Task 5. §3.6 DSAR → Task 6. §4 invalidation → Task 3 Step 3 + Task 5 Step 5. §5 verification → Task 7.
- §3.7 (`deleted_origin` not carried) and §3.8 (`availability` stays NULL) need no task — they are deliberate omissions, and Task 2's mapper implements them by not writing those columns.
- **Known softness, flagged rather than hidden:** Tasks 5 and 6 carry less literal code than Tasks 1–4, because both depend on file contents that must be read first (the controller's exact Resource keys, the DSAR builder's declared shape). Both tasks open with a "read it before changing it" step for that reason. An implementer who skips that step will break a wire contract.
