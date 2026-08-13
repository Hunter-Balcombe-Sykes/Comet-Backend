# Slice 5b — Shop Pool and Public Render: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the public Shop page off the bespoke `/integrations` shop branch and onto the shared content-pool system, with the outbound product URL composed in the backend.

**Architecture:** Register `shop` as a pool so `buildPools()` serves it automatically; seed owner ordering as pins in `site.section_items`; extend the pool item payload with the four fields the legacy wire carried that the pool does not; compose the outbound URL in a small pure class read at payload-build time; then delete the legacy shop branch and repoint page-presence at the pool.

**Tech Stack:** PHP 8.4, Laravel 12, Pest 4, PostgreSQL (Supabase), SQLite in tests.

**Spec:** `docs/superpowers/specs/2026-08-12-slice-5b-shop-render-design.md`

## Global Constraints

- **No Laravel migrations, ever.** Schema changes are raw SQL under `supabase/migrations/`. This slice needs **no DDL at all** — if a task seems to need one, stop and re-read the spec.
- **Cache invalidation is three lanes**, none enforced by CI: `BuildState::bump($siteId)`, touch `site.sites.updated_at`, dispatch `CloudflareCachePurgeJob::dispatch($subdomain)`. Assert all three directly in tests.
- **Tests run SQLite, production is Postgres.** Verify constraint-bound writes against `supabase/migrations/` DDL, not a green suite.
- **Business logic in `Services/` or `app/Site/`**, never controllers. Resource classes for API responses. Authorization via Policies.
- **4-space indent, LF.** Comments explain WHY. `php artisan pint` before each commit.
- **Never `git stash`** — this worktree shares its stash stack with three others. Use a WIP commit.
- Run a single test file with `./vendor/bin/pest <path>`. **Do not use `composer test -- --filter`** — that combination is broken in this repo.
- `site.sites` has **no `deleted_at`** column. Filtering on it compiles to a string literal on SQLite (always false, silent) and errors 42703 on Postgres.

---

## Task 1: Register the `shop` pool

**Files:**
- Modify: `app/Site/Pools/PoolRegistry.php:11-16` (docblock), `:28-33` (POOLS), `:43-48` (PAGE_KEYS), `:50-55` (PAGE_LABELS), `:71-87` (SECTION_SHAPE)
- Test: `tests/Feature/Content/ShopPoolTest.php` (create)

**Interfaces:**
- Produces: `PoolRegistry::POOLS['shop'] === ['product']`, `PAGE_KEYS['shop'] === 'shop'`, `PAGE_LABELS['shop'] === 'Shop'`, `sectionShape('shop') === ['rule' => [['op' => 'kind_is', 'values' => ['product']]], 'order_by' => 'recency']`. Every later task depends on these.

**Rebase note:** `feat/slice-3-services` adds a `services` entry to the same four consts and edits the same docblock sentence. If it merged first, **read both hunks** — do not take either side wholesale — then re-run this task's tests plus `PoolRegistryTest` **after** resolving.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Content/ShopPoolTest.php`:

```php
<?php

use App\Site\Pools\PoolRegistry;

it('registers shop as a pool owning the product kind', function () {
    expect(PoolRegistry::POOLS['shop'])->toBe(['product'])
        ->and(PoolRegistry::PAGE_KEYS['shop'])->toBe('shop')
        ->and(PoolRegistry::PAGE_LABELS['shop'])->toBe('Shop')
        ->and(PoolRegistry::sectionKey('shop'))->toBe('pool:shop')
        ->and(PoolRegistry::poolForKind('product'))->toBe('shop');
});

it('shapes the shop section as bare kind_is ordered by recency', function () {
    expect(PoolRegistry::sectionShape('shop'))->toBe([
        'rule' => [['op' => 'kind_is', 'values' => ['product']]],
        'order_by' => 'recency',
    ]);
});

// 5a §7 decided this both ways and excluded shop: hand-ordering fights a
// Latest badge, pool recency is last_seen_at (a sync artefact, not product
// newness), and 16 of 51 dev products are unavailable so the badge can land
// on sold-out stock.
it('excludes shop from the Latest tag', function () {
    expect(PoolRegistry::carriesLatestTag('shop'))->toBeFalse()
        ->and(PoolRegistry::LATEST_TAG_POOLS)->not->toContain('shop');
});
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `./vendor/bin/pest tests/Feature/Content/ShopPoolTest.php`
Expected: FAIL — `Undefined array key "shop"`.

- [ ] **Step 3: Add the registry entries**

In `app/Site/Pools/PoolRegistry.php`, add `'shop' => ['product'],` to `POOLS`, `'shop' => 'shop',` to `PAGE_KEYS`, `'shop' => 'Shop',` to `PAGE_LABELS`, and to `SECTION_SHAPE`:

```php
        // Priced, undated — the same shape slice 3a uses for services,
        // reconciled 2026-08-12 so slice 4 inherits one convention. order_by
        // governs only UNPINNED items; the owner's ordering is carried by pins
        // (spec §3.2), which is why no `position` operator exists and none
        // should be added — the rule DSL spans four registries.
        'shop' => [
            'rule' => [['op' => 'kind_is']],
            'order_by' => 'recency',
        ],
```

Edit the docblock's second paragraph — it currently reads "Sell / Services / Menu are NOT here" (or "Sell / Menu are NOT here" if slice 3a landed first). Replace the Sell clause:

```
 * Menu is NOT here: it keeps its existing live lane, which already implements
 * sources→selection in its own machinery. Sell JOINED 2026-08-13 (slice 5b):
 * products render from the pool and the legacy /integrations shop keys are
 * retired. Watch + listen were the launch
```

- [ ] **Step 4: Run the tests and confirm they pass**

Run: `./vendor/bin/pest tests/Feature/Content/ShopPoolTest.php tests/Feature/Content/PoolRegistryTest.php`
Expected: PASS. `PoolRegistryTest` pins that a kind belongs to at most one pool — `product` was previously poolless, so it must stay green.

- [ ] **Step 5: Commit**

```bash
php artisan pint app/Site/Pools/PoolRegistry.php tests/Feature/Content/ShopPoolTest.php
git add app/Site/Pools/PoolRegistry.php tests/Feature/Content/ShopPoolTest.php
git commit -m "feat(shop): register the shop pool over the product kind"
```

---

## Task 2: A re-added product comes back

**Files:**
- Modify: `app/Services/Shop/ShopContentWriter.php:317-365` (`syncStore()`)
- Test: `tests/Feature/Content/ShopRetirementTest.php` (create)

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `ShopContentWriter::syncStore()` clears `content.items.removed_at` for every item it links. No signature change.

**Why:** `ProjectionWriter::upsertSourceItem()` clears only `source_items.removed_at` (`:422-423`) and `resolveItems()` never consults `items.removed_at` (`:564-571`), so a re-added product binds to the same still-retired row. Invisible until 5b's pool read filters `removed_at`. The narrowing: **an owner-authored write may clear `removed_at`; a connector re-observing never may.**

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Content/ShopRetirementTest.php`:

```php
<?php

use App\Services\Shop\ShopContentWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    Queue::fake();
});

function shopCollection(string $userId): string
{
    $id = (string) Str::uuid();
    DB::table('content.collections')->insert([
        'id' => $id, 'user_id' => $userId, 'kind' => 'storefront',
        'label' => 'Test Store', 'is_user_created' => false, 'position' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function shopBlob(string $url, string $title = 'A product'): array
{
    return [
        'url' => $url, 'title' => $title, 'price' => '10.00',
        'currency' => 'AUD', 'available' => true, 'variants' => [],
        'images' => [], 'productId' => '', 'handle' => '', 'vendor' => '',
        'description' => '', 'image' => null, 'variantId' => '',
    ];
}

it('un-retires a product the owner removes and re-adds', function () {
    $pro = createTenant('shop-readd');
    $collectionId = shopCollection($pro->id);
    $writer = app(ShopContentWriter::class);
    $url = 'https://store.example.com/products/hat';

    $writer->syncStore($pro->id, $collectionId, [shopBlob($url)], 'AUD');
    $itemId = DB::table('content.collection_items')
        ->where('collection_id', $collectionId)->value('item_id');

    // The owner removes it: syncing an empty catalogue retires the item.
    $writer->syncStore($pro->id, $collectionId, [], 'AUD');
    expect(DB::table('content.items')->where('id', $itemId)->value('removed_at'))->not->toBeNull();

    // The owner re-adds the same URL. It must return, on the SAME item row —
    // a new row would orphan analytics.item_views and any pin.
    $writer->syncStore($pro->id, $collectionId, [shopBlob($url)], 'AUD');

    expect(DB::table('content.items')->where('id', $itemId)->value('removed_at'))->toBeNull()
        ->and(DB::table('content.collection_items')
            ->where('collection_id', $collectionId)->value('item_id'))->toBe($itemId);
});

it('does not un-retire an item outside the catalogue being synced', function () {
    $pro = createTenant('shop-scope');
    $a = shopCollection($pro->id);
    $b = shopCollection($pro->id);
    $writer = app(ShopContentWriter::class);

    $writer->syncStore($pro->id, $b, [shopBlob('https://store.example.com/products/other')], 'AUD');
    $otherId = DB::table('content.collection_items')->where('collection_id', $b)->value('item_id');
    DB::table('content.items')->where('id', $otherId)->update(['removed_at' => now()]);

    // Syncing collection A must leave B's retired item alone.
    $writer->syncStore($pro->id, $a, [shopBlob('https://store.example.com/products/hat')], 'AUD');

    expect(DB::table('content.items')->where('id', $otherId)->value('removed_at'))->not->toBeNull();
});
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `./vendor/bin/pest tests/Feature/Content/ShopRetirementTest.php`
Expected: FAIL on the first test — `removed_at` is still non-null after the re-add.

- [ ] **Step 3: Clear `removed_at` for the items just linked**

In `syncStore()`, collect the linked item ids in the loop and clear after it. Add `$linked = [];` beside `$seen = [];`, then after the `collection_items` upsert add `$linked[] = $itemId;`, and insert this immediately before the `retireAbsent()` call:

```php
        // Spec §3.3 — an OWNER-AUTHORED write may clear items.removed_at; a
        // connector re-observing an item never may. The parent programme's
        // one-way rule was written against scrape flapping, which must not undo
        // a deliberate removal; a re-add through addProduct/setProducts is an
        // explicit owner act. Scoped to the items this call just linked, so it
        // can never reach outside this catalogue.
        //
        // Without this, the individual bucket's 20-item cap (which retires the
        // oldest product on EVERY add) makes a re-added product permanently
        // absent from the Shop page while it shows normally in the dashboard.
        if ($linked !== []) {
            DB::table('content.items')
                ->whereIn('id', $linked)
                ->whereNotNull('removed_at')
                ->update(['removed_at' => null, 'updated_at' => now()]);
        }
```

- [ ] **Step 4: Run the tests and confirm they pass**

Run: `./vendor/bin/pest tests/Feature/Content/ShopRetirementTest.php`
Expected: PASS, both tests.

- [ ] **Step 5: Commit**

```bash
php artisan pint app/Services/Shop/ShopContentWriter.php tests/Feature/Content/ShopRetirementTest.php
git add app/Services/Shop/ShopContentWriter.php tests/Feature/Content/ShopRetirementTest.php
git commit -m "fix(shop): an owner-authored re-add clears items.removed_at"
```

---

## Task 3: Fix `retireAbsent()`'s row-wise stale-coord match

**Files:**
- Modify: `app/Services/Shop/ShopContentWriter.php:703-711`
- Test: `tests/Feature/Content/ShopRetirementTest.php` (append)

**Interfaces:**
- Consumes: Task 2's `syncStore()` behaviour.
- Produces: no signature change.

**Why:** `whereNotIn('si.coord', $liveCoords)` executes in SQL while `->unique()` executes in PHP afterwards, so an item carrying **both** a stale and a live coord matches row-wise on the stale one and is treated as absent. Its link is dropped and, if no other store carries it, it is retired while still in the catalogue.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Content/ShopRetirementTest.php`:

```php
it('keeps an item that carries a stale coord alongside a live one', function () {
    $pro = createTenant('shop-stale');
    $collectionId = shopCollection($pro->id);
    $writer = app(ShopContentWriter::class);
    $url = 'https://store.example.com/products/hat';

    $writer->syncStore($pro->id, $collectionId, [shopBlob($url)], 'AUD');
    $itemId = DB::table('content.collection_items')
        ->where('collection_id', $collectionId)->value('item_id');

    // A product that gained a URL upstream carries a second, now-stale coord
    // on the same item — the pid:-derived one it was first written under.
    $sourceId = DB::table('content.source_items')->where('item_id', $itemId)->value('source_id');
    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $sourceId,
        'coord' => 'manual:stale-'.Str::random(8), 'item_id' => $itemId,
        'kind' => 'product', 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);

    // Re-sync the SAME live catalogue. Nothing left it, so nothing may retire.
    $writer->syncStore($pro->id, $collectionId, [shopBlob($url)], 'AUD');

    expect(DB::table('content.items')->where('id', $itemId)->value('removed_at'))->toBeNull()
        ->and(DB::table('content.collection_items')
            ->where('collection_id', $collectionId)->where('item_id', $itemId)->exists())->toBeTrue();
});

// The delete-links-FIRST-then-requery ordering in retireAbsent() is
// load-bearing: reversed, the synced store's own stale link satisfies the
// "still linked to a storefront of this user" test and cross-store retirement
// silently becomes a no-op. This asserts the cross-store case, not merely the
// single-store one, so that inversion fails here.
it('still retires an item dropped from its only store while sparing one held elsewhere', function () {
    $pro = createTenant('shop-cross');
    $a = shopCollection($pro->id);
    $b = shopCollection($pro->id);
    $writer = app(ShopContentWriter::class);
    $shared = 'https://store.example.com/products/shared';
    $only = 'https://store.example.com/products/only';

    $writer->syncStore($pro->id, $a, [shopBlob($shared), shopBlob($only, 'Only')], 'AUD');
    $writer->syncStore($pro->id, $b, [shopBlob($shared)], 'AUD');

    $sharedId = DB::table('content.collection_items')->where('collection_id', $b)->value('item_id');
    $onlyId = DB::table('content.collection_items')
        ->where('collection_id', $a)->where('item_id', '!=', $sharedId)->value('item_id');

    // Store A drops both. The shared one survives (B still lists it); the
    // other retires.
    $writer->syncStore($pro->id, $a, [], 'AUD');

    expect(DB::table('content.items')->where('id', $sharedId)->value('removed_at'))->toBeNull()
        ->and(DB::table('content.items')->where('id', $onlyId)->value('removed_at'))->not->toBeNull();
});
```

- [ ] **Step 2: Run and confirm the stale-coord test fails**

Run: `./vendor/bin/pest tests/Feature/Content/ShopRetirementTest.php`
Expected: the stale-coord test FAILS (the link is dropped); the cross-store test already PASSES and must keep passing.

- [ ] **Step 3: Invert the query**

Replace the `$absent` builder in `retireAbsent()`:

```php
        // Fix 2026-08-13: the question is "does this item have NO live-coord
        // source item?", not "does it have ANY non-live-coord source item?".
        // The old form ran whereNotIn in SQL and ->unique() in PHP afterwards,
        // so an item carrying both a stale and a live coord — a product that
        // gained a URL upstream — matched row-wise on the stale row and was
        // retired while still in the catalogue. One-way, since removed_at is
        // cleared only by an owner-authored re-add (§3.3).
        $absent = DB::table('content.collection_items as ci')
            ->where('ci.collection_id', $collectionId)
            ->when($liveCoords !== [], fn ($q) => $q->whereNotExists(
                fn ($e) => $e->from('content.source_items as si')
                    ->whereColumn('si.item_id', 'ci.item_id')
                    ->whereIn('si.coord', $liveCoords)
            ))
            ->pluck('ci.item_id')
            ->unique()
            ->all();
```

- [ ] **Step 4: Run the tests and confirm they pass**

Run: `./vendor/bin/pest tests/Feature/Content/ShopRetirementTest.php`
Expected: PASS, all four tests.

- [ ] **Step 5: Commit**

```bash
php artisan pint app/Services/Shop/ShopContentWriter.php tests/Feature/Content/ShopRetirementTest.php
git add app/Services/Shop/ShopContentWriter.php tests/Feature/Content/ShopRetirementTest.php
git commit -m "fix(shop): retireAbsent matched a stale coord row-wise and retired live products"
```

---

## Task 4: Provision the Shop page, section and pins

**Files:**
- Create: `app/Console/Commands/ProvisionShopPinsCommand.php`
- Modify: `app/Providers/AppServiceProvider.php` (register the command **only if** this repo registers commands explicitly — check first; Laravel 12 auto-discovers `app/Console/Commands`, in which case skip)
- Test: `tests/Feature/Content/ShopPinProvisioningTest.php` (create)

**Interfaces:**
- Consumes: `PoolRegistry` from Task 1; `PoolSectionProvisioner::ensure(Site $site, string $pool): object`.
- Produces: artisan command `content:provision-shop-pins {--dry-run}`.

**Why:** `ensure()` creates the page and section on demand at first read, but **nothing creates pins**. Without them the Shop page renders in `last_seen_at` order rather than the owner's. `site.section_items.sort_key` is a nullable `double precision`, so the two-level catalogue order flattens to a dense 1-based sequence — byte-identical to how `PoolController::reorder()` writes a drag (`:173`), so a seeded pin and a dragged pin are indistinguishable.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Content/ShopPinProvisioningTest.php`:

```php
<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    setupSectionsTables();
    Queue::fake();
});

function storefront(string $userId, int $position, string $label): string
{
    $id = (string) Str::uuid();
    DB::table('content.collections')->insert([
        'id' => $id, 'user_id' => $userId, 'kind' => 'storefront', 'label' => $label,
        'is_user_created' => false, 'position' => $position,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function productIn(string $userId, string $collectionId, int $position, string $title): string
{
    $sourceId = poolSource($userId, null);
    $itemId = poolItem($userId, $sourceId, 'product', $title, '2026-08-01T00:00:00Z');
    DB::table('content.collection_items')->insert([
        'collection_id' => $collectionId, 'item_id' => $itemId,
        'source_id' => null, 'position' => $position,
    ]);

    return $itemId;
}

it('pins every product in catalogue order across stores', function () {
    [$pro, $siteId] = poolTenant();
    $storeB = storefront($pro->id, 1, 'Second store');
    $storeA = storefront($pro->id, 0, 'First store');

    $a0 = productIn($pro->id, $storeA, 0, 'A first');
    $a1 = productIn($pro->id, $storeA, 1, 'A second');
    $b0 = productIn($pro->id, $storeB, 0, 'B first');

    $this->artisan('content:provision-shop-pins')->assertSuccessful();

    $sectionId = DB::table('site.sections')
        ->where('site_id', $siteId)->where('key', 'pool:shop')->value('id');

    $pins = DB::table('site.section_items')->where('section_id', $sectionId)
        ->where('state', 'pinned')->orderBy('sort_key')->pluck('item_id')->all();

    // Store position first, then catalogue position within the store.
    expect($pins)->toBe([$a0, $a1, $b0]);

    expect(DB::table('site.section_items')->where('section_id', $sectionId)
        ->where('item_id', $a0)->value('sort_key'))->toEqual(1.0);
});

it('is idempotent and never rewrites an existing pin', function () {
    [$pro, $siteId] = poolTenant();
    $store = storefront($pro->id, 0, 'Store');
    $first = productIn($pro->id, $store, 0, 'First');
    productIn($pro->id, $store, 1, 'Second');

    $this->artisan('content:provision-shop-pins')->assertSuccessful();

    $sectionId = DB::table('site.sections')
        ->where('site_id', $siteId)->where('key', 'pool:shop')->value('id');

    // The owner drags the first product to the end.
    DB::table('site.section_items')->where('section_id', $sectionId)
        ->where('item_id', $first)->update(['sort_key' => 99.0]);

    $this->artisan('content:provision-shop-pins')->assertSuccessful();

    expect(DB::table('site.section_items')->where('section_id', $sectionId)
        ->where('item_id', $first)->value('sort_key'))->toEqual(99.0)
        ->and(DB::table('site.section_items')->where('section_id', $sectionId)->count())->toBe(2);
});

it('reports counts under --dry-run and writes nothing', function () {
    [$pro, $siteId] = poolTenant();
    $store = storefront($pro->id, 0, 'Store');
    productIn($pro->id, $store, 0, 'Only');

    $this->artisan('content:provision-shop-pins', ['--dry-run' => true])->assertSuccessful();

    expect(DB::table('site.section_items')->count())->toBe(0);
});

it('fires all three cache-invalidation lanes per site', function () {
    [$pro, $siteId] = poolTenant();
    $store = storefront($pro->id, 0, 'Store');
    productIn($pro->id, $store, 0, 'Only');

    $before = DB::table('site.sites')->where('id', $siteId)->value('updated_at');
    $this->travelTo(now()->addMinute());

    $this->artisan('content:provision-shop-pins')->assertSuccessful();

    // Lane 2: the payload cache key composes from sites.updated_at.
    expect(DB::table('site.sites')->where('id', $siteId)->value('updated_at'))->not->toBe($before);
    // Lane 1: the document build state.
    expect(DB::table('site.site_build_state')->where('site_id', $siteId)->exists())->toBeTrue();
    // Lane 3: the CDN outlives the origin write.
    Queue::assertPushed(CloudflareCachePurgeJob::class);
});
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `./vendor/bin/pest tests/Feature/Content/ShopPinProvisioningTest.php`
Expected: FAIL — `The command "content:provision-shop-pins" does not exist.`

If `setupSectionsTables()` does not exist, grep `tests/Pest.php` for the helper that creates `site.sections` / `site.section_items` and use that name instead.

- [ ] **Step 3: Write the command**

Create `app/Console/Commands/ProvisionShopPinsCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\Site;
use App\Site\Documents\BuildState;
use App\Site\Pools\PoolSectionProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seed the owner's product ordering as pins on each site's pool:shop section
 * (spec §3.2). PoolSectionProvisioner::ensure() creates the page and section on
 * demand at first read, but nothing creates pins — and without them the Shop
 * page renders in last_seen_at order instead of the order the owner chose.
 *
 * The pool's auto half offers only alphabetical / occurrence / recency
 * (SectionCandidates:105-116) and none of them is "the order the owner picked",
 * which is why the ordering is carried by pins rather than by order_by.
 *
 * Idempotent: an existing pin is left alone, never rewritten, so a drag on the
 * pool page survives a re-run. Read-only under --dry-run.
 */
class ProvisionShopPinsCommand extends Command
{
    protected $signature = 'content:provision-shop-pins
        {--dry-run : Report what would be pinned without writing}';

    protected $description = 'Pin every shop product at its catalogue position on the pool:shop section';

    public function __construct(private readonly PoolSectionProvisioner $provisioner)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $pinned = 0;
        $left = 0;
        $sites = 0;

        $userIds = DB::connection('pgsql')->table('content.collections')
            ->where('kind', 'storefront')
            ->distinct()
            ->pluck('user_id');

        foreach ($userIds as $userId) {
            $site = Site::query()->where('user_id', (string) $userId)->first();
            if ($site === null) {
                // Parent §8.2: derive user_id through the site explicitly and
                // fail loudly rather than skipping in silence.
                $this->warn("  ! user {$userId} holds storefronts but has no site — skipped");

                continue;
            }

            // Store position first, then catalogue position within the store.
            // items.id breaks ties deterministically: two collections may share
            // a position, and without a total order the pin sequence would be
            // heap-order dependent. removed_at is filtered because the pool read
            // filters it — pinning a retired item would put a row in
            // section_items that resolves to nothing.
            $itemIds = DB::connection('pgsql')->table('content.collection_items as ci')
                ->join('content.collections as c', 'c.id', '=', 'ci.collection_id')
                ->join('content.items as i', 'i.id', '=', 'ci.item_id')
                ->where('c.user_id', (string) $userId)
                ->where('c.kind', 'storefront')
                ->whereNull('i.removed_at')
                ->orderBy('c.position')->orderBy('ci.position')->orderBy('i.id')
                ->pluck('ci.item_id')
                // An item listed by two of this user's stores is ONE item
                // (5a §3.3 — the coord is URL-derived, not store-scoped), so it
                // pins once, at its earliest position.
                ->unique()
                ->values();

            if ($itemIds->isEmpty()) {
                continue;
            }

            $section = $this->provisioner->ensure($site, 'shop');

            $existing = DB::connection('pgsql')->table('site.section_items')
                ->where('section_id', $section->id)
                ->pluck('item_id')->flip();

            $wrote = 0;
            foreach ($itemIds as $index => $itemId) {
                if ($existing->has($itemId)) {
                    $left++;

                    continue;
                }
                if ($dry) {
                    $pinned++;

                    continue;
                }
                DB::connection('pgsql')->table('site.section_items')->insert([
                    'id' => (string) Str::uuid(),
                    'section_id' => $section->id,
                    'item_id' => $itemId,
                    'state' => 'pinned',
                    // Dense 1-based, exactly as PoolController::reorder() writes
                    // a drag (:173), so a seeded pin and a dragged pin are
                    // indistinguishable.
                    'sort_key' => (float) ($index + 1),
                    'created_at' => now(),
                ]);
                $pinned++;
                $wrote++;
            }

            if (! $dry && $wrote > 0) {
                $this->invalidate($site);
                $sites++;
            }
        }

        $verb = $dry ? 'would pin' : 'pinned';
        $this->info("pool:shop pins: {$verb} {$pinned}, left alone {$left}, across {$sites} site(s).");

        return self::SUCCESS;
    }

    /**
     * Raw-write seam: all three lanes by hand (spec §4). bump() alone is not
     * enough — the payload cache key composes from sites.updated_at, and the
     * CDN outlives the origin write. No CI check enforces this.
     */
    private function invalidate(Site $site): void
    {
        BuildState::bump((string) $site->id);
        DB::connection('pgsql')->table('site.sites')
            ->where('id', $site->id)->update(['updated_at' => now()]);
        if ((string) ($site->subdomain ?? '') !== '') {
            CloudflareCachePurgeJob::dispatch($site->subdomain);
        }
    }
}
```

- [ ] **Step 4: Run the tests and confirm they pass**

Run: `./vendor/bin/pest tests/Feature/Content/ShopPinProvisioningTest.php`
Expected: PASS, all four tests.

- [ ] **Step 5: Commit**

```bash
php artisan pint app/Console/Commands/ProvisionShopPinsCommand.php tests/Feature/Content/ShopPinProvisioningTest.php
git add app/Console/Commands/ProvisionShopPinsCommand.php tests/Feature/Content/ShopPinProvisioningTest.php
git commit -m "feat(shop): content:provision-shop-pins seeds owner ordering as pins"
```

---

## Task 5: The outbound URL composer

**Files:**
- Create: `app/Site/Pools/ShopOutboundUrl.php`
- Test: `tests/Unit/Site/Pools/ShopOutboundUrlTest.php` (create)

**Interfaces:**
- Consumes: nothing.
- Produces: `ShopOutboundUrl::compose(string $bareUrl, string $linkMode, ?object $store, ?string $variantRef): string`, where `$store` is a row object carrying `->provider`, `->url`, `->discount_code`, `->referral_query`.

**Why a separate class:** the composition is pure, revenue-affecting, and has no live coverage on dev (all 32 sites are `checkout`, all 9 stores have `referral_query = ''`, no product URL contains `?`). A pure class is exhaustively testable without database fixtures. Task 6 wires it in.

**Source of the format — recovered from this repo's history, not invented:** `ac462b2d6` (2026-07-07) *"link_mode ('product'|'checkout'): rides the public payload so the sitepage can deep-link carts (Shopify `/cart/{variant}:1?discount=`, Woo `?add-to-cart=`)"*; corroborated by `edf71f545` (2026-07-08).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Site/Pools/ShopOutboundUrlTest.php`:

```php
<?php

use App\Site\Pools\ShopOutboundUrl;

function store(string $provider, string $url = 'https://store.example.com', string $discount = '', string $referral = ''): object
{
    return (object) [
        'provider' => $provider, 'url' => $url,
        'discount_code' => $discount === '' ? null : $discount,
        'referral_query' => $referral,
    ];
}

$bare = 'https://store.example.com/products/hat';

it('returns the bare URL in product mode regardless of provider', function () use ($bare) {
    expect(ShopOutboundUrl::compose($bare, 'product', store('shopify'), '123'))->toBe($bare)
        ->and(ShopOutboundUrl::compose($bare, 'product', store('woocommerce'), '123'))->toBe($bare);
});

it('builds the Shopify cart deep link in checkout mode', function () use ($bare) {
    expect(ShopOutboundUrl::compose($bare, 'checkout', store('shopify'), '44073715368070'))
        ->toBe('https://store.example.com/cart/44073715368070:1');
});

it('trims a trailing slash off the store URL before the cart path', function () use ($bare) {
    expect(ShopOutboundUrl::compose($bare, 'checkout', store('shopify', 'https://store.example.com/'), '123'))
        ->toBe('https://store.example.com/cart/123:1');
});

it('builds the WooCommerce add-to-cart link on the product URL', function () {
    $wooBare = 'https://fearnoevil.com.au/product/bulwark-jacket/';
    expect(ShopOutboundUrl::compose($wooBare, 'checkout', store('woocommerce'), '2595'))
        ->toBe('https://fearnoevil.com.au/product/bulwark-jacket/?add-to-cart=2595');
});

it('falls back to the bare URL when the variant ref is missing', function () use ($bare) {
    expect(ShopOutboundUrl::compose($bare, 'checkout', store('shopify'), null))->toBe($bare)
        ->and(ShopOutboundUrl::compose($bare, 'checkout', store('woocommerce'), ''))->toBe($bare);
});

it('falls back to the bare URL for providers with no known deep link', function () use ($bare) {
    foreach (['squarespace', 'bigcartel', 'generic'] as $provider) {
        expect(ShopOutboundUrl::compose($bare, 'checkout', store($provider), '123'))->toBe($bare);
    }
});

it('falls back to the bare URL when there is no storefront at all', function () use ($bare) {
    expect(ShopOutboundUrl::compose($bare, 'checkout', null, '123'))->toBe($bare);
});

it('appends the discount code with ? on a URL carrying no query', function () use ($bare) {
    expect(ShopOutboundUrl::compose($bare, 'checkout', store('shopify', discount: 'ALEX10'), '123'))
        ->toBe('https://store.example.com/cart/123:1?discount=ALEX10');
});

it('appends the referral query, which is a whole key=value pair', function () use ($bare) {
    expect(ShopOutboundUrl::compose($bare, 'checkout', store('shopify', referral: 'ref=abc'), '123'))
        ->toBe('https://store.example.com/cart/123:1?ref=abc');
});

it('joins discount and referral with & in that order', function () use ($bare) {
    expect(ShopOutboundUrl::compose($bare, 'checkout', store('shopify', discount: 'ALEX10', referral: 'ref=abc'), '123'))
        ->toBe('https://store.example.com/cart/123:1?discount=ALEX10&ref=abc');
});

// No dev product URL carries a query string (0 of 51), so this case exists
// only here.
it('uses & when the base URL already carries a query', function () {
    $withQuery = 'https://store.example.com/products/hat?variant=9';
    expect(ShopOutboundUrl::compose($withQuery, 'product', store('shopify', referral: 'ref=abc'), '123'))
        ->toBe('https://store.example.com/products/hat?variant=9&ref=abc');
});

// referral_query is affiliate attribution, not a checkout artefact. Omitting
// it in product mode would drop revenue on every non-checkout site.
it('appends the referral query in product mode too', function () use ($bare) {
    expect(ShopOutboundUrl::compose($bare, 'product', store('shopify', referral: 'partner=xyz'), '123'))
        ->toBe('https://store.example.com/products/hat?partner=xyz');
});

it('emits no empty params when discount and referral are absent', function () use ($bare) {
    expect(ShopOutboundUrl::compose($bare, 'checkout', store('shopify'), '123'))
        ->not->toContain('?')
        ->and(ShopOutboundUrl::compose($bare, 'checkout', store('shopify'), '123'))
        ->toBe('https://store.example.com/cart/123:1');
});

it('treats an unknown link mode as checkout, the column default', function () use ($bare) {
    expect(ShopOutboundUrl::compose($bare, '', store('shopify'), '123'))
        ->toBe('https://store.example.com/cart/123:1');
});
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `./vendor/bin/pest tests/Unit/Site/Pools/ShopOutboundUrlTest.php`
Expected: FAIL — `Class "App\Site\Pools\ShopOutboundUrl" not found`.

- [ ] **Step 3: Write the composer**

Create `app/Site/Pools/ShopOutboundUrl.php`:

```php
<?php

namespace App\Site\Pools;

/**
 * The outbound product link — what a visitor's click actually opens.
 *
 * Moved backend-side in slice 5b (owner decision 2026-08-12). It used to live in
 * partna-monorepo's productHref(); composing it here makes referral revenue
 * testable in this repo, and a change to the site's link mode takes effect on
 * the next payload build with nothing to re-backfill.
 *
 * The format is RECOVERED from this repository's own history, not reconstructed
 * from convention — ac462b2d6 (2026-07-07), the commit that built the feature:
 * "link_mode ('product'|'checkout'): rides the public payload so the sitepage
 * can deep-link carts (Shopify /cart/{variant}:1?discount=, Woo ?add-to-cart=)".
 * Corroborated by edf71f545 (2026-07-08).
 *
 * Pure on purpose: dev exercises none of this (all 32 sites are 'checkout', all
 * 9 stores carry referral_query = '', no product URL has a query string), so the
 * unit tests are the ONLY place this behaviour is verified.
 */
final class ShopOutboundUrl
{
    /**
     * @param  string  $bareUrl  content.f_link.url — bare and uncomposed
     * @param  string  $linkMode  site.sites.shop_link_mode; anything unrecognised is 'checkout' (the column default)
     * @param  object|null  $store  a content.storefronts row: provider, url, discount_code, referral_query
     * @param  string|null  $variantRef  content.f_catalog.variant_ref — the provider's checkout id
     */
    public static function compose(string $bareUrl, string $linkMode, ?object $store, ?string $variantRef): string
    {
        $url = $bareUrl;
        $ref = trim((string) $variantRef);
        $provider = strtolower(trim((string) ($store->provider ?? '')));

        if ($linkMode !== 'product' && $store !== null && $ref !== '') {
            $storeUrl = rtrim(trim((string) ($store->url ?? '')), '/');

            $url = match ($provider) {
                // Shopify's permalink cart: one unit of one variant.
                'shopify' => $storeUrl !== '' ? "{$storeUrl}/cart/{$ref}:1" : $bareUrl,
                // Woo adds to the cart from the product page itself.
                'woocommerce' => self::append($bareUrl, "add-to-cart={$ref}"),
                // No documented deep-link form for squarespace / bigcartel /
                // generic — the product page is the honest destination.
                default => $bareUrl,
            };
        }

        $discount = trim((string) ($store->discount_code ?? ''));
        if ($discount !== '') {
            $url = self::append($url, 'discount='.rawurlencode($discount));
        }

        // A whole key=value pair (e.g. "ref=abc"), not a bare code — the shape
        // ShopController::referralQueryFrom() and UrlParamExtractor::extract()
        // both store, capped at 500 chars. Appended in BOTH link modes: it is
        // affiliate attribution, not a checkout artefact.
        $referral = trim((string) ($store->referral_query ?? ''));
        if ($referral !== '') {
            $url = self::append($url, $referral);
        }

        return $url;
    }

    /** Join a query fragment with ? or &, whichever the URL still needs. */
    private static function append(string $url, string $fragment): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').$fragment;
    }
}
```

- [ ] **Step 4: Run the tests and confirm they pass**

Run: `./vendor/bin/pest tests/Unit/Site/Pools/ShopOutboundUrlTest.php`
Expected: PASS, all 14 tests.

- [ ] **Step 5: Commit**

```bash
php artisan pint app/Site/Pools/ShopOutboundUrl.php tests/Unit/Site/Pools/ShopOutboundUrlTest.php
git add app/Site/Pools/ShopOutboundUrl.php tests/Unit/Site/Pools/ShopOutboundUrlTest.php
git commit -m "feat(shop): compose the outbound product URL backend-side"
```

---

## Task 6: Extend the pool item payload

**Files:**
- Modify: `app/Site/Pools/PoolResolver.php:187-394` (`itemPayloads()`), `:35-40` (constructor)
- Test: `tests/Feature/Content/ShopPoolPayloadTest.php` (create)

**Interfaces:**
- Consumes: `ShopOutboundUrl::compose()` (Task 5), `PoolRegistry` (Task 1).
- Produces: every pool item gains `description`, `vendor`, `variants`, `collectionIds`; `frames` populates for `kind='product'`; `popularityRank` populates for products; `url` carries the composed href for products; `pools.shop` gains a sibling `collections` map.

**Why:** retiring the legacy wire (Task 8) removes keys the pool does not carry. Without this task that retirement loses 271 gallery images, 50 descriptions, 49 vendors, all variant data and 34 live popularity ranks.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Content/ShopPoolPayloadTest.php`. Reuse `storefront()` / `productIn()` from Task 4's file by requiring it, or redefine them locally — Pest global helpers collide if defined twice, so if `ShopPinProvisioningTest.php` already defines them, do **not** redefine; name these `shopStore()` / `shopProduct()`.

```php
<?php

use App\Models\Core\Site\Site;
use App\Site\Pools\PoolResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    setupSectionsTables();
    Queue::fake();
});

function shopStore(string $userId, array $overrides = []): string
{
    $collectionId = (string) Str::uuid();
    DB::table('content.collections')->insert([
        'id' => $collectionId, 'user_id' => $userId, 'kind' => 'storefront',
        'label' => $overrides['label'] ?? 'Test Store', 'is_user_created' => false,
        'position' => $overrides['position'] ?? 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.storefronts')->insert(array_merge([
        'collection_id' => $collectionId, 'external_ref' => 'ext-'.Str::random(6),
        'provider' => 'shopify', 'url' => 'https://store.example.com',
        'currency' => 'AUD', 'discount_code' => null, 'referral_query' => '',
        'is_individual' => false, 'logo_url' => 'https://cdn.example.com/logo.png',
        'favicon_url' => 'https://cdn.example.com/fav.ico',
        'created_at' => now(), 'updated_at' => now(),
    ], array_diff_key($overrides, ['label' => 1, 'position' => 1])));

    return $collectionId;
}

function shopProduct(string $userId, string $collectionId, string $title, int $position = 0): string
{
    $sourceId = poolSource($userId, null);
    $itemId = poolItem($userId, $sourceId, 'product', $title, '2026-08-01T00:00:00Z');
    DB::table('content.collection_items')->insert([
        'collection_id' => $collectionId, 'item_id' => $itemId,
        'source_id' => null, 'position' => $position,
    ]);
    DB::table('content.f_link')->insert([
        'item_id' => $itemId, 'source_id' => $sourceId,
        'url' => 'https://store.example.com/products/'.Str::slug($title), 'updated_at' => now(),
    ]);
    DB::table('content.f_catalog')->insert([
        'item_id' => $itemId, 'source_id' => $sourceId,
        'handle' => Str::slug($title), 'vendor' => 'A Vendor',
        'variant_ref' => '44073715368070', 'updated_at' => now(),
    ]);
    DB::table('content.f_text')->insert([
        'item_id' => $itemId, 'source_id' => $sourceId,
        'headline' => $title, 'body' => 'A description.', 'updated_at' => now(),
    ]);

    return $itemId;
}

it('carries description, vendor and collectionIds on a product', function () {
    [$pro, $siteId] = poolTenant();
    $store = shopStore($pro->id);
    $itemId = shopProduct($pro->id, $store, 'Bulwark Jacket');

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'shop');
    $item = collect($out['library'])->firstWhere('id', $itemId);

    expect($item['description'])->toBe('A description.')
        ->and($item['vendor'])->toBe('A Vendor')
        ->and($item['collectionIds'])->toBe([$store]);
});

it('leaves the new keys null or empty on a non-shop kind', function () {
    [$pro, $siteId] = poolTenant();
    $sourceId = poolSource($pro->id, null);
    $itemId = poolItem($pro->id, $sourceId, 'video', 'A video', '2026-08-01T00:00:00Z');

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'watch');
    $item = collect($out['library'])->firstWhere('id', $itemId);

    expect($item['description'])->toBeNull()
        ->and($item['vendor'])->toBeNull()
        ->and($item['variants'])->toBe([])
        ->and($item['collectionIds'])->toBe([])
        ->and($item['popularityRank'])->toBeNull();
});

it('ships variants with their own price and availability', function () {
    [$pro, $siteId] = poolTenant();
    $store = shopStore($pro->id);
    $itemId = shopProduct($pro->id, $store, 'Tee');
    $sourceId = DB::table('content.source_items')->where('item_id', $itemId)->value('source_id');

    DB::table('content.item_variants')->insert([
        ['id' => (string) Str::uuid(), 'item_id' => $itemId, 'source_id' => $sourceId,
         'label' => 'Small', 'sku' => 'sku-s', 'position' => 0, 'image_url' => null, 'updated_at' => now()],
        ['id' => (string) Str::uuid(), 'item_id' => $itemId, 'source_id' => $sourceId,
         'label' => 'Large', 'sku' => 'sku-l', 'position' => 1, 'image_url' => null, 'updated_at' => now()],
    ]);
    DB::table('content.offers')->insert([
        ['id' => (string) Str::uuid(), 'item_id' => $itemId, 'source_id' => $sourceId,
         'amount_minor' => 3000, 'currency' => 'AUD', 'qualifier' => 'exact',
         'availability' => 'in_stock', 'variant_label' => 'Small', 'updated_at' => now()],
        ['id' => (string) Str::uuid(), 'item_id' => $itemId, 'source_id' => $sourceId,
         'amount_minor' => 3500, 'currency' => 'AUD', 'qualifier' => 'exact',
         'availability' => 'out_of_stock', 'variant_label' => 'Large', 'updated_at' => now()],
    ]);

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'shop');
    $item = collect($out['library'])->firstWhere('id', $itemId);

    expect($item['variants'])->toBe([
        ['label' => 'Small', 'sku' => 'sku-s', 'imageUrl' => null, 'availability' => 'in_stock',
         'price' => ['amountMinor' => 3000, 'amountMaxMinor' => null, 'currency' => 'AUD', 'qualifier' => 'exact']],
        ['label' => 'Large', 'sku' => 'sku-l', 'imageUrl' => null, 'availability' => 'out_of_stock',
         'price' => ['amountMinor' => 3500, 'amountMaxMinor' => null, 'currency' => 'AUD', 'qualifier' => 'exact']],
    ]);

    // The item-level price stays the CHEAPEST offer — unchanged behaviour.
    expect($item['price']['amountMinor'])->toBe(3000);
});

it('composes the outbound URL into url and leaves links bare', function () {
    [$pro, $siteId] = poolTenant();
    $store = shopStore($pro->id, ['discount_code' => 'ALEX10', 'referral_query' => 'ref=abc']);
    $itemId = shopProduct($pro->id, $store, 'Hat');

    DB::table('site.sites')->where('id', $siteId)->update(['shop_link_mode' => 'checkout']);

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'shop');
    $item = collect($out['library'])->firstWhere('id', $itemId);

    expect($item['url'])->toBe('https://store.example.com/cart/44073715368070:1?discount=ALEX10&ref=abc')
        // The referral suffix must appear in exactly ONE place on the wire.
        ->and($item['links'][0]['url'])->toBe('https://store.example.com/products/hat')
        ->and(json_encode($item['links']))->not->toContain('ref=abc');
});

it('publishes the collections map beside the items', function () {
    [$pro, $siteId] = poolTenant();
    $store = shopStore($pro->id, ['label' => 'Above the Ground', 'external_ref' => '75102060779', 'discount_code' => 'ALEX10']);
    shopProduct($pro->id, $store, 'Hat');

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'shop');

    expect($out['collections'])->toHaveKey($store)
        ->and($out['collections'][$store])->toBe([
            'externalRef' => '75102060779',
            'provider' => 'shopify',
            'url' => 'https://store.example.com',
            'name' => 'Above the Ground',
            'currency' => 'AUD',
            'favicon' => 'https://cdn.example.com/fav.ico',
            'logo' => 'https://cdn.example.com/logo.png',
            'discountCode' => 'ALEX10',
            'position' => 0,
        ]);
});

it('populates frames for a product from its item_media', function () {
    [$pro, $siteId] = poolTenant();
    $store = shopStore($pro->id);
    $itemId = shopProduct($pro->id, $store, 'Hat');
    $sourceId = DB::table('content.source_items')->where('item_id', $itemId)->value('source_id');

    $cover = frameAsset($pro->id, ['source_url' => 'https://cdn.example.com/cover.jpg', 'width' => 800, 'height' => 600]);
    $gallery = frameAsset($pro->id, ['source_url' => 'https://cdn.example.com/g1.jpg', 'width' => 640, 'height' => 480]);
    frameRow($itemId, $sourceId, $cover, 'cover', 0);
    frameRow($itemId, $sourceId, $gallery, 'gallery', 1);

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'shop');
    $item = collect($out['library'])->firstWhere('id', $itemId);

    expect($item['frames'])->toHaveCount(2)
        ->and($item['frames'][0]['url'])->toBe('https://cdn.example.com/cover.jpg')
        ->and($item['thumbnail'])->toBe('https://cdn.example.com/cover.jpg');
});
```

`frameAsset()` / `frameRow()` are already global helpers from `MediaPoolFramesTest.php`; call `setupMediaTables()` in `beforeEach` for this file too.

- [ ] **Step 2: Run it and confirm it fails**

Run: `./vendor/bin/pest tests/Feature/Content/ShopPoolPayloadTest.php`
Expected: FAIL — `Undefined array key "description"`.

- [ ] **Step 3: Extend `itemPayloads()`**

In `app/Site/Pools/PoolResolver.php`:

**3a.** Change the `offers` fetch from `keyBy` to `groupBy`, and add `variant_label` to the select. `keyBy` kept the LAST row, and the ordering puts the cheapest last, so `->map(fn ($rows) => $rows->last())` is exactly equivalent:

```php
        $offerRows = DB::connection('pgsql')->table('content.offers')
            ->whereIn('item_id', $ids)
            ->orderByRaw('amount_minor IS NULL DESC, amount_minor DESC')
            ->get(['item_id', 'amount_minor', 'amount_max_minor', 'currency', 'qualifier', 'availability', 'variant_label'])
            ->groupBy('item_id');

        // ONE fetch serves both readings: the cheapest offer per item (the
        // ordering writes it last, which is what keyBy used to keep) and the
        // per-variant offers Task 6 needs.
        $offers = $offerRows->map(fn ($rows) => $rows->last());
```

**3b.** After `$items` is fetched and `$ids` recomputed, add the shop block. Everything is gated on the item set containing a product, so no other pool pays for it:

```php
        // Shop-only reads. Gated so watch / listen / media / events add no
        // queries — this sits behind the 60s payload cache on the public path.
        $hasProduct = $items->contains(fn (object $i): bool => $i->kind === 'product');

        $storesByItem = collect();
        $stores = collect();
        $catalog = collect();
        $variantsByItem = collect();
        $ranks = [];
        $linkMode = (string) ($site->shop_link_mode ?? 'checkout');

        if ($hasProduct) {
            $links = DB::connection('pgsql')->table('content.collection_items as ci')
                ->join('content.collections as c', 'c.id', '=', 'ci.collection_id')
                ->join('content.storefronts as s', 's.collection_id', '=', 'c.id')
                ->whereIn('ci.item_id', $ids)
                ->where('c.kind', 'storefront')
                // Lowest store position composes when an item sits in two;
                // external_ref breaks the tie, matching brandMap()'s ordering
                // so the dashboard and the wire agree.
                ->orderBy('c.position')->orderBy('s.external_ref')
                ->get([
                    'ci.item_id', 'c.id as collection_id', 'c.label', 'c.position',
                    's.external_ref', 's.provider', 's.url', 's.currency',
                    's.discount_code', 's.referral_query', 's.logo_url', 's.favicon_url',
                ]);

            $storesByItem = $links->groupBy('item_id');
            $stores = $links->unique('collection_id')->keyBy('collection_id');

            $catalog = DB::connection('pgsql')->table('content.f_catalog')
                ->whereIn('item_id', $ids)
                ->get(['item_id', 'vendor', 'handle', 'variant_ref'])
                ->keyBy('item_id');

            $variantsByItem = DB::connection('pgsql')->table('content.item_variants')
                ->whereIn('item_id', $ids)
                ->orderBy('position')
                ->get(['item_id', 'label', 'sku', 'image_url'])
                ->groupBy('item_id');

            // Spec §3.6: the legacy shop wire carried a real popularityRank
            // (content_popularity_scores, content_type='shop_product', keyed by
            // product HANDLE). Retiring it without this drops live computed data
            // to null. Same single-flight cache PublicIntegrationController uses
            // — CCG-102, that read used to hit Postgres on every request.
            $ranks = $this->popularityRanks($site);
        }
```

**3c.** Add the private helper and constructor dependency. Inject `ContentPopularityReader` and `CacheLockService` (or whatever `PublicIntegrationController` injects as `$this->cache` — check its constructor and mirror it exactly):

```php
    /** @return array<string, int> product handle => rank */
    private function popularityRanks(Site $site): array
    {
        $ranks = $this->cache->rememberLocked(
            CacheKeyGenerator::sitePopularityRanks((string) $site->id),
            self::POPULARITY_CACHE_TTL_SECONDS,
            fn () => $this->popularity->forSite((string) $site->id),
        );

        return $ranks['shop_product'] ?? [];
    }
```

Copy `POPULARITY_CACHE_TTL_SECONDS` from `PublicIntegrationController` so the two cannot drift.

**3d.** In the `foreach ($items as $itemId => $item)` loop, build the shop fields and add them to `$out[$itemId]`:

```php
            $itemStores = $storesByItem->get($itemId, collect());
            $primaryStore = $itemStores->first();
            $isProduct = $item->kind === 'product';
            $handle = (string) ($catalog[$itemId]->handle ?? '');

            $outboundUrl = $isProduct && $primary !== null
                ? ShopOutboundUrl::compose(
                    (string) $primary['url'],
                    $linkMode,
                    $primaryStore,
                    $catalog[$itemId]->variant_ref ?? null,
                )
                : ($primary['url'] ?? null);
```

Then change `'url' => $primary['url'] ?? null,` to `'url' => $outboundUrl,`, change `'popularityRank' => null,` to `'popularityRank' => $handle !== '' ? ($ranks[$handle] ?? null) : null,`, change the `frames` line to include products, and add the four new keys:

```php
                'frames' => in_array($item->kind, ['media', 'product'], true)
                    ? $this->frames($covers->get($itemId, collect()), $resolvedUrls)
                    : [],
                // Additive and nullable on EVERY item, never a kind-shaped
                // sub-object — the same contract startsAt / venue / price
                // already follow, so the wire shape does not vary with kind.
                'description' => $texts[$itemId]->body ?? null,
                'vendor' => $catalog[$itemId]->vendor ?? null,
                'variants' => $this->variants(
                    $variantsByItem->get($itemId, collect()),
                    $offerRows->get($itemId, collect()),
                ),
                // Plural because it must be: a URL-derived coord is not
                // store-scoped (5a §3.3), so one product URL listed by two of a
                // user's stores is ONE item in TWO collections.
                'collectionIds' => $itemStores->pluck('collection_id')
                    ->unique()->map(fn ($id) => (string) $id)->values()->all(),
```

Add a `$texts` fetch beside the other facet queries (unconditional — `f_text.body` is generic, not shop-specific):

```php
        $texts = DB::connection('pgsql')->table('content.f_text')
            ->whereIn('item_id', $ids)
            ->whereNotNull('body')
            ->get(['item_id', 'body'])
            ->keyBy('item_id');
```

**3e.** Add the `variants()` builder — explicit key assignment, never a spread:

```php
    /**
     * Variant objects for a product. Built key-by-key on purpose (spec §3.7):
     * this is the first nested collection the pool payload carries, and a spread
     * of the DB row is exactly how an unvetted column would reach a CDN-cached
     * public wire.
     *
     * @return list<array<string, mixed>>
     */
    private function variants(Collection $rows, Collection $offerRows): array
    {
        $byLabel = $offerRows->filter(fn (object $o): bool => (string) ($o->variant_label ?? '') !== '')
            ->keyBy('variant_label');

        $out = [];
        foreach ($rows as $row) {
            $offer = $byLabel->get((string) $row->label);
            $out[] = [
                'label' => (string) $row->label,
                'sku' => $row->sku === null ? null : (string) $row->sku,
                // Unverified against real data: image_url is populated on 0 of
                // 268 dev rows, so this round-trips in tests only.
                'imageUrl' => $row->image_url === null ? null : (string) $row->image_url,
                'availability' => $offer->availability ?? null,
                'price' => $offer === null ? null : [
                    'amountMinor' => $offer->amount_minor === null ? null : (int) $offer->amount_minor,
                    'amountMaxMinor' => $offer->amount_max_minor === null ? null : (int) $offer->amount_max_minor,
                    'currency' => $offer->currency,
                    'qualifier' => $offer->qualifier,
                ],
            ];
        }

        return $out;
    }
```

**3f.** Return the collections map from `resolve()`. Extend the return array and its docblock:

```php
        return [
            'selection' => $selection,
            'library' => $library,
            'latestItemId' => PoolRegistry::carriesLatestTag($pool)
                ? $this->latestItemId($selection)
                : null,
            'collections' => $this->collectionsFor($selection, $payloads),
        ];
```

`itemPayloads()` must therefore also hand back the store rows. Simplest shape that keeps the method signature honest: store `$stores` on a private property set inside `itemPayloads()` and read by `collectionsFor()`, or return a tuple. Prefer the tuple — a private property makes `resolve()` order-dependent:

```php
    /**
     * The store cards the sitepage rebuilds its shop layout from. Only
     * collections a SELECTED item references — an unreferenced store card would
     * render as an empty group.
     *
     * @param  list<array<string, mixed>>  $selection
     * @return array<string, array<string, mixed>>
     */
    private function collectionsFor(array $selection, Collection $stores): array
    {
        $referenced = collect($selection)->flatMap(fn (array $i) => $i['collectionIds'] ?? [])->unique();

        $out = [];
        foreach ($referenced as $collectionId) {
            $row = $stores->get($collectionId);
            if ($row === null) {
                continue;
            }
            // Explicit keys only (spec §3.7). referralQuery and linkMode are
            // DELIBERATELY absent: composition is backend-side now, so the
            // affiliate suffix stops being publicly readable. sourceUrl
            // (re-scrape input) and connectStatus (dashboard-only) stay private.
            $out[(string) $collectionId] = [
                'externalRef' => (string) $row->external_ref,
                'provider' => (string) $row->provider,
                'url' => $row->url === null ? null : (string) $row->url,
                'name' => (string) $row->label,
                'currency' => $row->currency === null ? null : (string) $row->currency,
                'favicon' => $row->favicon_url === null ? null : (string) $row->favicon_url,
                'logo' => $row->logo_url === null ? null : (string) $row->logo_url,
                'discountCode' => $row->discount_code === null ? null : (string) $row->discount_code,
                'position' => (int) $row->position,
            ];
        }

        return $out;
    }
```

**3g.** `buildPools()` in `IndividualProfilePayloadBuilder:308-342` must forward the map. Add to the `$out[$pool]` array:

```php
                'latestItemId' => $resolved['latestItemId'],
                // Shop groups its items into store cards; every other pool
                // returns [] and the key is simply absent from its payload.
                ...($resolved['collections'] === [] ? [] : ['collections' => $resolved['collections']]),
```

- [ ] **Step 4: Run the tests and confirm they pass**

Run: `./vendor/bin/pest tests/Feature/Content/ShopPoolPayloadTest.php tests/Feature/Content/MediaPoolFramesTest.php tests/Feature/Content/PoolLaneTest.php tests/Feature/Content/EventsPoolTest.php`
Expected: PASS. The three existing pool suites are the regression guard — the payload gained keys but no existing key changed.

- [ ] **Step 5: Commit**

```bash
php artisan pint app/Site/Pools/PoolResolver.php app/Services/PublicSite/IndividualProfilePayloadBuilder.php tests/Feature/Content/ShopPoolPayloadTest.php
git add app/Site/Pools/PoolResolver.php app/Services/PublicSite/IndividualProfilePayloadBuilder.php tests/Feature/Content/ShopPoolPayloadTest.php
git commit -m "feat(shop): pool items carry description, vendor, variants, frames and the collections map"
```

---

## Task 7: Pin the public payload key sets

**Files:**
- Create: `tests/Feature/Content/PoolWireShapeTest.php`
- Modify: `app/Site/Pools/PoolResolver.php` (add the three key-set consts)

**Interfaces:**
- Consumes: Task 6's payload.
- Produces: `PoolResolver::ITEM_KEYS`, `::STORE_KEYS`, `::VARIANT_KEYS` — public consts, the enforcement point.

**Why:** `SHOP_PRODUCT_ALLOWLIST` (#API-1) is what keeps unvetted scraper keys off a CDN-cached public wire. Task 8 deletes it, so the pool needs an equivalent. `array_intersect_key` cannot be copied — it filters a blob, and pool items are built from typed columns. The equivalent is explicit construction (done in Task 6) plus a test that fails on **additions as well as removals**.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Content/PoolWireShapeTest.php`:

```php
<?php

use App\Models\Core\Site\Site;
use App\Site\Pools\PoolResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    setupSectionsTables();
    setupMediaTables();
    Queue::fake();
});

// The #API-1 equivalent for the pool wire. This fails on ADDITIONS as well as
// removals, which the legacy allowlist could not do — it only ever caught keys
// someone remembered to leave out.
it('emits exactly the declared item keys, no more and no fewer', function () {
    [$pro, $siteId] = poolTenant();
    $store = shopStore($pro->id);
    shopProduct($pro->id, $store, 'Hat');

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'shop');

    expect(array_keys($out['library'][0]))->toEqualCanonicalizing(PoolResolver::ITEM_KEYS);
});

it('emits exactly the declared store keys', function () {
    [$pro, $siteId] = poolTenant();
    $store = shopStore($pro->id);
    shopProduct($pro->id, $store, 'Hat');

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'shop');
    $card = $out['collections'][$store] ?? null;

    expect($card)->not->toBeNull()
        ->and(array_keys($card))->toEqualCanonicalizing(PoolResolver::STORE_KEYS);
});

it('emits exactly the declared variant keys', function () {
    [$pro, $siteId] = poolTenant();
    $store = shopStore($pro->id);
    $itemId = shopProduct($pro->id, $store, 'Tee');
    $sourceId = DB::table('content.source_items')->where('item_id', $itemId)->value('source_id');
    DB::table('content.item_variants')->insert([
        'id' => (string) \Illuminate\Support\Str::uuid(), 'item_id' => $itemId,
        'source_id' => $sourceId, 'label' => 'Small', 'sku' => 'sku-s',
        'position' => 0, 'image_url' => null, 'updated_at' => now(),
    ]);

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'shop');
    $item = collect($out['library'])->firstWhere('id', $itemId);

    expect(array_keys($item['variants'][0]))->toEqualCanonicalizing(PoolResolver::VARIANT_KEYS);
});

// The affiliate suffix must never be publicly readable again — composition is
// backend-side now, so referralQuery and linkMode have no reason to ship.
it('never publishes referralQuery or linkMode on a store card', function () {
    [$pro, $siteId] = poolTenant();
    $store = shopStore($pro->id, ['referral_query' => 'ref=secret']);
    shopProduct($pro->id, $store, 'Hat');

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'shop');

    expect(json_encode($out['collections']))->not->toContain('ref=secret')
        ->and($out['collections'][$store])->not->toHaveKey('referralQuery')
        ->and($out['collections'][$store])->not->toHaveKey('linkMode');
});
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `./vendor/bin/pest tests/Feature/Content/PoolWireShapeTest.php`
Expected: FAIL — `Undefined constant App\Site\Pools\PoolResolver::ITEM_KEYS`.

- [ ] **Step 3: Declare the key sets**

Add to `PoolResolver`, above `LIBRARY_LIMIT`:

```php
    /**
     * THE public wire contract for a pool item — the #API-1 enforcement point
     * for this lane (spec §3.7).
     *
     * The legacy SHOP_PRODUCT_ALLOWLIST filtered a raw scraper blob with
     * array_intersect_key. That mechanism does not transfer: pool payloads are
     * built key-by-key from typed columns, so there is no blob to subtract from.
     * The equivalent guarantee comes from two halves — explicit construction in
     * itemPayloads(), and PoolWireShapeTest asserting this list is exactly what
     * ships. That fails on ADDITIONS too, which the legacy list never could.
     *
     * `selected` is stripped by buildPools() before the public wire; it is
     * listed here because this const describes the resolver's output, which the
     * dashboard also reads.
     *
     * @var list<string>
     */
    public const ITEM_KEYS = [
        'id', 'kind', 'slug', 'aliases', 'headline', 'headlineEdited', 'url',
        'platform', 'creator', 'publishedAt', 'firstSeenAt', 'durationSeconds',
        'thumbnail', 'frames', 'startsAt', 'startsAtLocal', 'endsAtLocal',
        'timezone', 'venue', 'locality', 'price', 'availability', 'links',
        'popularityRank', 'description', 'vendor', 'variants', 'collectionIds',
        'selected', 'origin',
    ];

    /** Public fields of one store card in a pool's `collections` map. */
    public const STORE_KEYS = [
        'externalRef', 'provider', 'url', 'name', 'currency',
        'favicon', 'logo', 'discountCode', 'position',
    ];

    /** Public fields of one product variant. */
    public const VARIANT_KEYS = ['label', 'sku', 'imageUrl', 'availability', 'price'];
```

- [ ] **Step 4: Run the tests and confirm they pass**

Run: `./vendor/bin/pest tests/Feature/Content/PoolWireShapeTest.php`
Expected: PASS. If the item test fails on a key mismatch, reconcile the const with what Task 6 actually emits — **the const follows the code, but only after you have checked the code is right.**

- [ ] **Step 5: Commit**

```bash
php artisan pint app/Site/Pools/PoolResolver.php tests/Feature/Content/PoolWireShapeTest.php
git add app/Site/Pools/PoolResolver.php tests/Feature/Content/PoolWireShapeTest.php
git commit -m "feat(shop): pin the pool wire key sets as the #API-1 equivalent"
```

---

## Task 8: Retire the legacy shop wire and repoint page presence

**Files:**
- Modify: `app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php:27-80` (properties + setters), `:258-310` (both allowlists), `:336-395` (`filterPayload()`'s shop branch)
- Modify: `app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php:108-161`
- Modify: `app/Providers/PlatformRegistryServiceProvider.php:492-499` (the shop `complete()` closure)
- Test: `tests/Feature/Content/ShopWireRetirementTest.php` (create); update any existing shop public-wire test that asserts the old shape

**Interfaces:**
- Consumes: Tasks 1–7. **This task must be last of the code tasks** — the pool has to carry everything before the legacy keys go.
- Produces: `platforms.shop[*].payload === []`; Shop page presence derived from `PoolResolver::hasSelection()`.

**Why:** slice 2's precedent — *"Backend-only execution; the frontends are told, not designed around"*. The envelope survives so a consumer iterating `platforms` sees no shape change, only an empty payload.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Content/ShopWireRetirementTest.php`:

```php
<?php

use App\Models\Core\Site\Site;
use App\Site\Pools\PoolResolver;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    setupSectionsTables();
    Queue::fake();
});

// setupSitesTable() already provisions site.platform_connections — it is the
// documented alias for exactly that (tests/Pest.php:598). There is no separate
// platform-connections helper; do not add one.

it('publishes an empty payload for a shop connection but keeps the envelope', function () {
    [$pro, $siteId] = poolTenant();
    $store = shopStore($pro->id);
    shopProduct($pro->id, $store, 'Hat');
    $connectionId = poolConnection($pro->id, 'shop.store');

    $response = $this->getJson("/api/public/profiles/{$pro->handle}/integrations");

    $response->assertOk();
    $shop = $response->json('data.platforms.shop.0');

    expect($shop)->toHaveKeys(['resourceId', 'payload', 'lastRefreshedAt'])
        ->and($shop['payload'])->toBe([]);
});

it('derives Shop page presence from the pool selection', function () {
    [$pro, $siteId] = poolTenant();
    $site = Site::query()->findOrFail($siteId);
    $store = shopStore($pro->id);
    shopProduct($pro->id, $store, 'Hat');

    $this->artisan('content:provision-shop-pins')->assertSuccessful();

    expect(app(PoolResolver::class)->hasSelection($site, 'shop'))->toBeTrue();
});

it('reports no Shop selection for a user with no products', function () {
    [$pro, $siteId] = poolTenant();

    expect(app(PoolResolver::class)->hasSelection(Site::query()->findOrFail($siteId), 'shop'))->toBeFalse();
});
```

The route is `/api/public/profiles/{handle}/integrations` — confirmed against `tests/Feature/Platforms/LinkPlatformsConnectionTest.php:94` and four sibling tests.

`poolConnection()` (from `PoolLaneTest.php`) inserts a `surface_key` and no `platform` column, so it may not produce a row the shop branch recognises. **Before writing this test, open one of those five existing tests and copy how it creates a connection whose `platform` resolves to `shop`** — do not guess a surface key.

- [ ] **Step 2: Run it and confirm it fails**

Run: `./vendor/bin/pest tests/Feature/Content/ShopWireRetirementTest.php`
Expected: FAIL — the payload still carries the brand map.

- [ ] **Step 3: Delete the legacy shop branch**

In `PublicIntegrationConnectionResource`:
- Delete the `$shopLinkModeOverride` and `$shopBrandMap` properties and the `withShopLinkMode()` / `withShopBrands()` setters.
- Delete `SHOP_BRAND_ALLOWLIST` and `SHOP_PRODUCT_ALLOWLIST`.
- Replace the whole `if ($platform === 'shop') { … }` block in `filterPayload()` with an `ALLOWLIST` entry, matching how slice 2 retired the event platforms:

```php
        // RETIRED 2026-08-13 (slice 5b). Shop is DASHBOARD-ONLY on this wire
        // now: products reach the public payload through `profile.pools.shop`,
        // which serves them from content.items with variants, store cards and a
        // backend-composed outbound URL the legacy brand shape could not carry.
        //
        // `=> []` rather than deletion, deliberately — same reason the event
        // platforms keep an entry. The platform is still REGISTERED (the
        // dashboard connect/refresh lane uses it) and
        // PublicAllowlistCoverageTest requires every registered platform to
        // carry one; deleting it would report a MissingPublicAllowlistException
        // to Nightwatch on every public request.
        'shop' => [],
```

In `PublicIntegrationController`, delete the whole `if ($connections->has('shop')) { … }` block, the `$shopLinkMode` / `$productRanks` / `$shopBrands` locals, and collapse the `$platforms` map back to the uniform form:

```php
        $platforms = $connections
            ->map(fn ($rows) => PublicIntegrationConnectionResource::collection($rows->values())->resolve())
            ->toArray();
```

Remove the now-unused `ShopContentReader` and `ContentPopularityReader` constructor dependencies **only if nothing else in the class uses them** — check first.

In `PlatformRegistryServiceProvider`, replace the shop `complete()` closure:

```php
            // Slice 5b: page presence is POOL-derived, exactly as events became
            // in slice 2. The previous closure counted content.collection_items
            // and deliberately did NOT filter items.removed_at, to stay in
            // lockstep with a payload that did not filter it either. The pool
            // read DOES filter it, so asking the pool the question directly is
            // what keeps presence and payload from disagreeing — lockstep by
            // construction rather than by two queries agreeing to be wrong in
            // the same way.
            $r->get('shop')->complete(function (IntegrationConnection $c): bool {
                $site = Site::query()->where('user_id', (string) $c->user_id)->first();

                return $site !== null && app(PoolResolver::class)->hasSelection($site, 'shop');
            });
```

Add the `Site` and `PoolResolver` imports.

- [ ] **Step 4: Run the tests and confirm they pass**

Run: `./vendor/bin/pest tests/Feature/Content/ShopWireRetirementTest.php`
Expected: PASS.

Then run every suite that touches the shop wire:

Run: `./vendor/bin/pest tests/Feature/Platforms tests/Feature/Api/PublicSite tests/Feature/Content`
Expected: PASS. Several existing tests assert the old brand-keyed payload — **update them to assert `payload === []`, do not delete them.** A deleted test is not a passing test.

- [ ] **Step 5: Commit**

```bash
php artisan pint app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php \
    app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php \
    app/Providers/PlatformRegistryServiceProvider.php \
    tests/Feature/Content/ShopWireRetirementTest.php
# Explicit paths, plus whichever existing tests you updated — list them.
# Never `git add -A`: concurrent subagents share THIS worktree's index, so a
# blanket add stages a peer task's half-written file into your commit. (The
# slice-3 worktree has its own index and is not the hazard here.)
git add app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php \
    app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php \
    app/Providers/PlatformRegistryServiceProvider.php \
    tests/Feature/Content/ShopWireRetirementTest.php
git commit -m "feat(shop)!: retire the legacy /integrations shop keys, presence is pool-derived"
```

---

## Task 9: Wire manifest, downstream prompts, checkpoint

**Files:**
- Create: `docs/wire-changes/2026-08-12-slice-5b-shop-render.md`
- Modify: `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md` (§9.8, §16.9, and a new §17 checkpoint)
- Modify: `docs/superpowers/plans/2026-08-12-slice-4-menus-KICKOFF-PROMPT.md`, `2026-08-12-slice-6-reviews-KICKOFF-PROMPT.md`, `2026-08-12-slice-7-teardown-KICKOFF-PROMPT.md`

**Interfaces:** documentation only.

- [ ] **Step 1: Write the wire manifest**

Create `docs/wire-changes/2026-08-12-slice-5b-shop-render.md` following `2026-08-11-slice2-events-pool.md`'s structure exactly: endpoint, before shape, after shape, consuming repo. It must state:

- **New:** `profile.pools.shop` — `{items, latestItemId: null, collections}`, with the full `collections` map shape and the four new item keys.
- **`url` carries the composed outbound href for products.** `productHref()` retires; the sitepage clicks `item.url`. `links[]` stays bare.
- **Changed on every pool item, additive and nullable:** `description`, `vendor`, `variants`, `collectionIds`; `frames` now populates for products.
- **Removed:** `platforms.shop[*].payload` is `{}`. Envelope preserved. **This is breaking and partna-monorepo must land its side.**
- **Removed from the wire entirely:** `referralQuery` and `linkMode` — a privacy improvement, since the affiliate suffix is no longer publicly readable.
- **Page presence:** a shop connection no longer grants the Shop page on its own; a non-empty pool selection does.
- **Required deploy step:** `php artisan content:provision-shop-pins` (dry-run first), **before** the code deploy, or every Shop page renders in `last_seen_at` order instead of the owner's.

- [ ] **Step 2: Correct the two false parent-spec claims in place**

In §16.9, replace the line reading "`site.shop_brands` / `site.shop_products` are **not dropped** — inert, as designed" with:

```markdown
- `site.shop_products` is **inert** — nothing writes it; the remaining
  `ShopProduct::delete()` calls only clear pre-deploy rows.
  **`site.shop_brands` is NOT inert** (corrected 2026-08-13 by slice 5b's entry
  gate). `ShopController` still writes it — `updateOrCreate` at `:317`,
  `firstOrCreate` at `:929`, `delete` at `:869` — and
  `ShopContentWriter::upsertStore()` takes the `ShopBrand` model as its identity
  anchor. Its `max(updated_at)` is frozen only because no brand has been added
  on dev since 2026-08-12 10:54. Slice 7 must re-home the writer, not merely
  drop the table.
```

In §9.8, append the narrowing:

```markdown
**Narrowed 2026-08-13 (slice 5b §3.3).** An **owner-authored** write may clear
`content.items.removed_at`; a **connector** re-observing an item never may. The
one-way rule was written against scrape flapping, which must not undo a
deliberate removal — an owner re-adding a product through `addProduct` /
`setProducts` is not that. Slices 3, 4 and 6 inherit the narrowed form.
```

- [ ] **Step 3: Update the three downstream prompts**

Edit in place, stating the fact rather than the story:

- **`slice-4-menus`** — under its non-negotiables: the pool item payload gained `description` / `vendor` / `variants` / `collectionIds`; groups ride an additive `collections` map keyed by collection **uuid** carrying `externalRef`, not by a legacy id; the enforcement point is `PoolResolver::ITEM_KEYS` / `STORE_KEYS` / `VARIANT_KEYS` plus `PoolWireShapeTest`, which fails on additions. Menu categories are the same problem and should reuse the map.
- **`slice-6-reviews`** — the same payload and enforcement-point facts, plus the §9.8 narrowing.
- **`slice-7-teardown`** — `site.shop_brands` is still written (§1.3); re-homing `ShopContentWriter` off the `ShopBrand` model is part of the teardown, not a follow-up. The `CloudflarePurgeService` note is already there from `b1cc2b738`.

- [ ] **Step 4: Write the checkpoint**

Append §17 to the parent spec: the pre-implementation baseline, what shipped, the live dev assertions with **output pasted**, the real `PoolResolver::resolve()` output, the three cache lanes, the gates at merge, and what remains outstanding. Follow §16's structure.

State plainly whether partna-monorepo has landed its side. **If it has not, say the Shop page renders empty on dev and name it — do not tick the retirement criterion.**

- [ ] **Step 5: Commit**

```bash
git add docs/
git commit -m "docs(shop): wire manifest, parent-spec corrections, downstream prompt updates"
```

---

## Task 10: Verify on dev and merge

**Files:** none — verification and merge only.

- [ ] **Step 1: Full local gate**

```bash
composer test
composer test:pg
./vendor/bin/phpstan analyse
php artisan pint --test
```

Expected: all green. `composer test:pg` is **not** mandated by CLAUDE.md for this slice (nothing under `app/Ingest/` changed) but is run anyway — 5a was bitten twice by SQLite/Postgres divergence in this code, and Task 3 rewrote a subquery into `NOT EXISTS`.

- [ ] **Step 2: Rebase onto `development` and resolve the `PoolRegistry` collision**

```bash
git fetch origin
git rebase origin/development
```

If `feat/slice-3-services` landed first, `PoolRegistry.php` conflicts. **Read both hunks; take neither side wholesale.** The result must carry `watch`, `listen`, `media`, `events`, `services` **and** `shop` in all four consts, and a docblock naming only Menu as absent. Then:

```bash
./vendor/bin/pest tests/Feature/Content/ShopPoolTest.php tests/Feature/Content/PoolRegistryTest.php tests/Feature/Content/ShopPinProvisioningTest.php
```

Expected: PASS. Run these **after** resolving, not before — a union merge that drops half a const array still passes every test written by the other branch.

- [ ] **Step 3: Run the provisioning command against dev**

```bash
cloud command:run development "php artisan content:provision-shop-pins --dry-run"
cloud command:run development "php artisan content:provision-shop-pins"
```

Paste both outputs into the checkpoint. Expect 51 pins across 5 sites.

- [ ] **Step 4: Live dev assertions**

Run the §5.1 SQL from the spec against `glncumufgaqcmqhzwrxm` and paste the output into the checkpoint. Then invoke the resolver for real — SQL cannot prove the composition, which happens in PHP:

```bash
cloud command:run development "php artisan tinker --execute=\"
\\\$site = App\\Models\\Core\\Site\\Site::whereHas('user', fn(\\\$q) => \\\$q->where('handle_lc','<a-handle-with-products>'))->firstOrFail();
\\\$out = app(App\\Site\\Pools\\PoolResolver::class)->resolve(\\\$site, 'shop');
echo json_encode(['items' => count(\\\$out['selection']), 'collections' => array_keys(\\\$out['collections']), 'firstUrl' => \\\$out['selection'][0]['url'] ?? null], JSON_PRETTY_PRINT);
\""
```

Paste the output. At least one `url` must show a composed checkout deep link.

- [ ] **Step 5: Post-deploy scans, then merge**

```bash
cloud env:logs partna development --minutes 10
```

Check Nightwatch too — §13's checkpoint recorded a Nightwatch scan skipped; do not repeat that gap.

**STOP — explicit sign-off before merging.** Then merge to `development` and push. **Never push to `production`** — that deploy is gated on partna-monorepo landing its side.

---

## Self-Review

**Spec coverage:** §3.1 → Task 1. §3.2 → Task 4. §3.3 → Task 2. §3.4 → Task 3. §3.5 → Tasks 5, 6. §3.6 → Task 6. §3.7 → Task 7. §3.8 → Task 8. §4 → Task 4 (command lanes) and Task 2 (un-retire path — **note:** the un-retire happens inside `syncStore()`, whose callers already invalidate; Task 2's test asserts the write, and the caller-side lanes are already covered by 5a's tests). §5 → Task 10. §9 → Task 9.

**Type consistency:** `ShopOutboundUrl::compose(string, string, ?object, ?string): string` — same signature in Tasks 5 and 6. `PoolResolver::resolve()` returns `selection`/`library`/`latestItemId`/`collections` in Task 6 and is read that way in Tasks 7 and 8. `ITEM_KEYS`/`STORE_KEYS`/`VARIANT_KEYS` declared in Task 7, referenced in Task 9's prompt edits.

**Helper names — verified against `tests/Pest.php`, not assumed:** `setupMediaTables()` (`:1411`), `createTenant()` (`:1583`), `setupSectionsTables()` (`:2416`) all exist. `setupSitesTable()` is the documented alias that also provisions `site.platform_connections` (`:598`) — there is no separate helper for it. `poolTenant()` / `poolSource()` / `poolItem()` / `poolConnection()` are global helpers defined in `tests/Feature/Content/PoolLaneTest.php:29-79`; `frameAsset()` / `frameRow()` in `tests/Feature/Content/MediaPoolFramesTest.php:23-42`. **Pest global helpers collide if defined twice** — reuse these rather than redeclaring, and name any new helper distinctly (`shopStore`, `shopProduct`, `shopCollection`, `shopBlob`, `storefront`, `productIn` are the ones this plan introduces; check none already exists before adding).

**Two soft spots the implementer must resolve rather than guess:**
- `PublicIntegrationController`'s cache property and TTL constant are copied by reference in Task 6 step 3c — read that constructor and mirror it exactly, so the pool and the controller cannot drift onto different cache keys.
- How a test creates a connection whose `platform` resolves to `shop` (Task 8, step 1) — copy it from one of the five existing public-integrations tests.
