# Slice 5a — Shop Data Move Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move 51 shop products and 9 stores out of `site.shop_products` /
`site.shop_brands` into `content.*`, and repoint every shop write and read at the
new store, so the legacy tables become inert without any visitor-facing change.

**Architecture:** Products become `content.items` (`kind='product'`) with
`offers`, `item_variants`, `item_media`, `f_link` and `f_catalog`, written
exclusively through the slice-0b manual lane
(`ProjectionWriter::writeManualItem()`). Stores become `content.collections` rows
with a new `content.storefronts` 1:1 sidecar carrying their behaviour. A backfill
seeds the existing data; `ShopCatalog::syncLatest()`, the seeders and all 14
`/platforms/shop/*` endpoints then read and write `content.*` directly.

**Tech Stack:** PHP 8.4, Laravel 12, PostgreSQL (Supabase), Pest 4, Redis/Horizon.

**Spec:** `docs/superpowers/specs/2026-08-12-slice-5a-shop-data-design.md` — read
§1 before starting. Its figures were re-derived from dev on 2026-08-12 and
several contradict the parent spec.

**A note on Tasks 6, 7 and 8.** `ShopController` is ~950 lines across 14
endpoints; transcribing it into this plan would make the plan unreadable and
would go stale the moment anyone touched the file. Those tasks specify the
**exact field-by-field mapping** and the exact behaviour to preserve, with tests
written out in full, rather than finished controller bodies. The tests are the
contract: Task 7's parity test must be green against the *unchanged*
controllers before the repoint begins, and pass unedited afterwards.

## Global Constraints

- **Never create Laravel migration files.** Schema changes are raw SQL under `supabase/migrations/`. A Composer guard rejects Laravel migrations.
- **Migration filename prefix block: `20260813100000`–`20260813109999`.** Do not take from another slice's block. One `CONCURRENTLY` statement per file, max.
- **Never write `content.*` raw.** Every item write goes through `ProjectionWriter::writeManualItem(string $userId, string $coord, array $projection): string`. Collections and storefronts are not items and are written directly by the backfiller.
- **Coord format is `manual:{sha1(canonical_url)}`** — never `manual:{uuid}`. Spec §3.3 explains why.
- **Never hard-delete a `content.items` row, and never write `content.source_items.removed_at` for an owner deletion.** Owner removal sets `content.items.removed_at` only.
- **Business logic lives in `app/Services/`,** not controllers. Responses go through Resource classes. Validation goes through Form Requests.
- **Authorization via Policies only** — `$this->authorizeForUser($user, 'ability', $resource)`. Never an inline `abort_unless(...403)`; CI fails the build on those.
- **Every cache key must carry a TTL.** Never `Cache::forever()`.
- **All three cache lanes fire on every raw-write seam:** `BuildState::bump($siteId)`, touch `site.sites.updated_at`, `CloudflareCachePurgeJob::dispatch($subdomain)`. No CI check enforces this.
- **Tests run SQLite; production is Postgres.** Verify constraint-bound writes against the DDL in `supabase/migrations/`, not against a green suite.
- **Run tests with `./vendor/bin/pest <path>` directly.** `composer test -- --filter` is broken in this repo.
- Comment for WHY, not what. 4-space indent, LF.

---

## File Structure

**Created:**

| File | Responsibility |
|---|---|
| `supabase/migrations/20260813100000_create_content_storefronts.sql` | The `content.storefronts` table |
| `app/Models/Content/Storefront.php` | Eloquent model for `content.storefronts` |
| `app/Models/Content/Collection.php` | Eloquent model for `content.collections` |
| `app/Services/Migration/ShopBackfiller.php` | Reads legacy tables, writes `content.*` through the manual lane |
| `app/Services/Shop/ShopProductProjection.php` | Pure mapper: one `shop_products.data` blob → one projection array. No I/O |
| `app/Services/Shop/ShopContentReader.php` | Reads `content.*` back into the brand-map shape the 14 endpoints already return |
| `app/Services/Shop/ShopContentWriter.php` | The write half: upsert a product set for a store, retire what is gone |
| `app/Console/Commands/BackfillShopContentCommand.php` | Artisan wrapper, `--dry-run`, counts |
| `tests/Feature/Shop/ShopProductProjectionTest.php` | Mapper unit tests |
| `tests/Feature/Shop/ShopBackfillerTest.php` | Backfill + idempotency |
| `tests/Feature/Shop/ShopContentWriterTest.php` | Reconcile semantics, the §8.3 regression |
| `tests/Feature/Shop/ShopEndpointParityTest.php` | All 14 endpoints unchanged |
| `tests/Feature/Ingest/ProjectionWriterVariantsTest.php` | The new variants writer |

**Modified:**

| File | Change |
|---|---|
| `app/Ingest/Projection/ProjectionWriter.php:1010-1112, 1321` | Learn the `variants` projection key |
| `app/Services/Platforms/ShopCatalog.php:81-132` | `syncLatest()` reconciles instead of delete-then-insert |
| `app/Services/Platforms/ShopProductSeeder.php:84-103` | Write through the content lane |
| `app/Http/Controllers/Api/Platforms/ShopController.php` | All 14 endpoints backed by `ShopContentReader` / `ShopContentWriter` |
| `app/Services/Platforms/Strategies/Fetch/ShopFetch.php:46-50` | `products_curated_at` now lives on `content.storefronts` |
| `app/Providers/AppServiceProvider.php` | Register policies for the two new models |

---

## Task 1: The `content.storefronts` table

**Files:**
- Create: `supabase/migrations/20260813100000_create_content_storefronts.sql`
- Create: `app/Models/Content/Storefront.php`, `app/Models/Content/Collection.php`
- Test: `tests/Schema/ContentStorefrontsConstraintsTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: table `content.storefronts` keyed `collection_id`; models `App\Models\Content\Storefront` and `App\Models\Content\Collection`, both extending `App\Models\BaseModel` (connection `pgsql`, UUID keys).

- [ ] **Step 1: Write the migration**

```sql
-- 20260813100000_create_content_storefronts.sql
-- Slice 5a §3.1. A 1:1 sidecar on content.collections carrying storefront
-- behaviour, so content.collections stays generic for the service and menu
-- categories that slices 3 and 4 put in it.
CREATE TABLE IF NOT EXISTS content.storefronts (
    collection_id       uuid PRIMARY KEY REFERENCES content.collections(id) ON DELETE CASCADE,
    provider            text        NOT NULL,
    url                 text,
    source_url          text,
    currency            text,
    discount_code       text,
    referral_query      text        NOT NULL DEFAULT '',
    is_individual       boolean     NOT NULL DEFAULT false,
    fetch_mode          text,
    connect_status      text,
    connect_error       text,
    products_curated_at timestamptz,
    logo_url            text,
    favicon_url         text,
    logo_mark_url       text,
    logo_mark_svg_url   text,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now()
);

COMMENT ON TABLE content.storefronts IS
    'Slice 5a: per-store behaviour for a content.collections row of kind=storefront. referral_query is affiliate revenue — see spec §3.7.';
```

- [ ] **Step 2: Dry-run the migration against dev, then apply**

```bash
supabase link --project-ref glncumufgaqcmqhzwrxm
supabase db push --dry-run
supabase db push
```

Expected: one new table, no other diff. If the dry-run reports unrelated drift, STOP and report it — do not push through it.

- [ ] **Step 3: Write the failing schema test**

```php
<?php
// tests/Schema/ContentStorefrontsConstraintsTest.php
// Applied-schema lane (composer test:schema) — NOT composer test.
it('cascades storefront rows when the collection goes', function () {
    $cols = DB::connection('pgsql')->select(<<<'SQL'
        SELECT column_name, is_nullable, column_default
        FROM information_schema.columns
        WHERE table_schema = 'content' AND table_name = 'storefronts'
    SQL);

    expect(collect($cols)->pluck('column_name'))
        ->toContain('referral_query', 'products_curated_at', 'connect_status');

    $fk = DB::connection('pgsql')->select(<<<'SQL'
        SELECT confdeltype FROM pg_constraint
        WHERE conrelid = 'content.storefronts'::regclass AND contype = 'f'
    SQL);

    // 'c' = ON DELETE CASCADE. A storefront outliving its collection is an
    // orphan nothing would ever clean up.
    expect($fk[0]->confdeltype)->toBe('c');
});
```

- [ ] **Step 4: Run the schema test**

Run: `composer test:schema`
Expected: PASS (the migration is already applied to dev by step 2).

- [ ] **Step 5: Write the two models**

```php
<?php
// app/Models/Content/Storefront.php
namespace App\Models\Content;

use App\Models\BaseModel;

/**
 * Slice 5a §3.1: per-store behaviour hanging off a content.collections row.
 * Not an item — it has no content.items row and never reaches PoolResolver.
 */
class Storefront extends BaseModel
{
    protected $table = 'content.storefronts';

    protected $primaryKey = 'collection_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'provider', 'url', 'source_url', 'currency', 'discount_code',
        'referral_query', 'is_individual', 'fetch_mode', 'connect_status',
        'connect_error', 'products_curated_at', 'logo_url', 'favicon_url',
        'logo_mark_url', 'logo_mark_svg_url',
    ];

    protected function casts(): array
    {
        return [
            'is_individual' => 'boolean',
            'products_curated_at' => 'datetime',
        ];
    }

    public function collection()
    {
        return $this->belongsTo(Collection::class, 'collection_id');
    }
}
```

```php
<?php
// app/Models/Content/Collection.php
namespace App\Models\Content;

use App\Models\BaseModel;

/** Generic grouping row. Shop uses kind='storefront'; slices 3/4 will add theirs. */
class Collection extends BaseModel
{
    protected $table = 'content.collections';

    // user_id is tenancy — assigned via associate(), never mass-assigned.
    protected $fillable = ['parent_id', 'label', 'kind', 'position', 'is_user_created'];

    protected function casts(): array
    {
        return ['is_user_created' => 'boolean', 'position' => 'integer'];
    }

    public function storefront()
    {
        return $this->hasOne(Storefront::class, 'collection_id');
    }
}
```

- [ ] **Step 6: Register policies so `PolicyCoverageTest` stays green**

In `app/Providers/AppServiceProvider::boot()`, beside the existing `Gate::policy` calls:

```php
Gate::policy(\App\Models\Content\Collection::class, \App\Policies\Content\ContentCollectionPolicy::class);
Gate::policy(\App\Models\Content\Storefront::class, \App\Policies\Content\ContentCollectionPolicy::class);
```

Create `app/Policies/Content/ContentCollectionPolicy.php` extending `BasePolicy`,
authorising on `user_id` for `Collection` and on `collection->user_id` for
`Storefront` — mirror `ContentItemPolicy`, which is the kind-agnostic reference.

- [ ] **Step 7: Run the policy coverage test and commit**

Run: `./vendor/bin/pest tests/Feature/Security/PolicyCoverageTest.php`
Expected: PASS

```bash
git add supabase/migrations/20260813100000_create_content_storefronts.sql \
        app/Models/Content app/Policies/Content app/Providers/AppServiceProvider.php \
        tests/Schema/ContentStorefrontsConstraintsTest.php
git commit -m "feat(shop): content.storefronts — per-store behaviour sidecar

Slice 5a §3.1. A 1:1 table on content.collections rather than 15 columns
on collections itself, which slices 3 and 4 fill with categories that
need none of them."
```

---

## Task 2: `ProjectionWriter` learns `variants`

**Files:**
- Modify: `app/Ingest/Projection/ProjectionWriter.php:1010-1112` and `:1321`
- Test: `tests/Feature/Ingest/ProjectionWriterVariantsTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: a projection array may now carry `'variants' => [['label' => string, 'sku' => ?string], …]`. Order in the array is the written `position`. Every later task depends on this.

**Why this task exists:** spec §3.2a. `content.item_variants` holds 0 rows
because **no writer exists** — `writeFacets()` assembles only `item_media`,
`offers` and `item_tags`. Without this, §3.2's variant mapping cannot be built.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Ingest/ProjectionWriterVariantsTest.php
use App\Ingest\Projection\ProjectionWriter;
use Illuminate\Support\Facades\DB;

it('writes item_variants from a projection, in array order', function () {
    $user = makeUser();   // existing helper in tests/Pest.php
    $writer = app(ProjectionWriter::class);

    $itemId = $writer->writeManualItem((string) $user->id, 'manual:'.sha1('https://x.test/p'), [
        'kind' => 'product',
        'headline' => 'Tee',
        'variants' => [
            ['label' => 'Small', 'sku' => 'TEE-S'],
            ['label' => 'Large', 'sku' => 'TEE-L'],
        ],
    ]);

    $rows = DB::table('content.item_variants')->where('item_id', $itemId)
        ->orderBy('position')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->label)->toBe('Small')
        ->and($rows[0]->position)->toBe(0)
        ->and($rows[1]->sku)->toBe('TEE-L');
});

it('writes no variants when the projection carries none', function () {
    $user = makeUser();
    $itemId = app(ProjectionWriter::class)->writeManualItem(
        (string) $user->id, 'manual:'.sha1('https://x.test/q'),
        ['kind' => 'product', 'headline' => 'Plain'],
    );

    expect(DB::table('content.item_variants')->where('item_id', $itemId)->count())->toBe(0);
});

it('replaces a source\'s variants on re-projection rather than appending', function () {
    $user = makeUser();
    $writer = app(ProjectionWriter::class);
    $coord = 'manual:'.sha1('https://x.test/r');

    $writer->writeManualItem((string) $user->id, $coord, [
        'kind' => 'product', 'headline' => 'Mug',
        'variants' => [['label' => 'Red'], ['label' => 'Blue']],
    ]);
    $itemId = $writer->writeManualItem((string) $user->id, $coord, [
        'kind' => 'product', 'headline' => 'Mug',
        'variants' => [['label' => 'Green']],
    ]);

    $labels = DB::table('content.item_variants')->where('item_id', $itemId)->pluck('label');
    expect($labels)->toHaveCount(1)->and($labels[0])->toBe('Green');
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Ingest/ProjectionWriterVariantsTest.php`
Expected: FAIL — 0 rows found, because nothing writes the table.

- [ ] **Step 3: Add the accumulator**

In `writeFacets()`, beside `$mediaByItem` / `$offersByItem` / `$tagsByItem`
(around `:1011-1026`):

```php
        $variantsByItem = [];
        foreach ($byItem as $itemId => $projections) {
            $variants = [];
            foreach ($projections as $projection) {
                $variants = array_merge($variants, array_values((array) ($projection['variants'] ?? [])));
            }
            $variantsByItem[(string) $itemId] = $variants;
        }
```

Fold this into the existing `foreach ($byItem as ...)` loop rather than adding a
second pass over the same array.

- [ ] **Step 4: Build the rows**

After the `$tagRows` block (around `:1085`):

```php
        // content.item_variants: label is NOT NULL, so a nameless entry is
        // dropped rather than written with an empty label — the table exists
        // to name a choice.
        $variantRows = [];
        foreach ($variantsByItem as $itemId => $entries) {
            foreach ($entries as $position => $entry) {
                $entry = (array) $entry;
                $label = trim((string) ($entry['label'] ?? ''));
                if ($label === '') {
                    continue;
                }
                $variantRows[$itemId][] = [
                    'id' => (string) Str::uuid(),
                    'item_id' => $itemId,
                    'source_id' => $contentSourceId,
                    'label' => $label,
                    'sku' => $entry['sku'] ?? null,
                    'position' => $position,
                ];
            }
        }
```

- [ ] **Step 5: Add it to the write map**

In the `$tables` array (around `:1088-1092`), append **after** `item_tags`:

```php
                'item_tags' => $this->rowsFor($tagRows, $itemIds),
                'item_variants' => $this->rowsFor($variantRows, $itemIds),
```

It inherits the existing delete-by-`(item_id, source_id)`-then-insert inside the
per-chunk transaction, which is what makes step 1's third test pass.

- [ ] **Step 6: Add it to the eligibility scan — at the END of the list**

At `:1321`:

```php
            // item_variants appended LAST on purpose: the comment above states
            // declaration order is part of the cached eligible_cache value
            // (I9). Inserting it among the existing entries would reshape the
            // cached value for every item in the database.
            foreach (['item_media', 'offers', 'item_tags', 'f_action', 'item_variants'] as $collection) {
```

- [ ] **Step 7: Run the tests**

Run: `./vendor/bin/pest tests/Feature/Ingest/ProjectionWriterVariantsTest.php`
Expected: PASS, all three.

- [ ] **Step 8: Run the whole ingest suite — this is shared hot-path code**

Run: `./vendor/bin/pest tests/Feature/Ingest tests/Unit/Ingest`
Expected: PASS with no new failures. Any change in an `eligible_cache`
assertion means step 6 was done wrong.

- [ ] **Step 9: Commit**

```bash
git add app/Ingest/Projection/ProjectionWriter.php tests/Feature/Ingest/ProjectionWriterVariantsTest.php
git commit -m "feat(ingest): ProjectionWriter writes content.item_variants

Spec 5a §3.2a. The table held 0 rows because nothing could write it —
writeFacets() assembled item_media, offers and item_tags only. Additive:
a projection with no variants key writes nothing. item_variants is
appended LAST to the eligibility scan because declaration order is part
of the cached eligible_cache value (I9)."
```

---

## Task 3: `ShopProductProjection` — the pure mapper

**Files:**
- Create: `app/Services/Shop/ShopProductProjection.php`
- Test: `tests/Feature/Shop/ShopProductProjectionTest.php`

**Interfaces:**
- Consumes: Task 2's `variants` projection key.
- Produces: `ShopProductProjection::fromBlob(array $data, ?string $storeCurrency): array` returning a projection accepted by `writeManualItem()`, and `ShopProductProjection::coordFor(string $url): string`.

Keeping the mapping pure — no database, no models — is what makes the 51 real
dev blobs testable as fixtures.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Shop/ShopProductProjectionTest.php
use App\Services\Shop\ShopProductProjection;

$blob = fn (array $over = []) => array_merge([
    'productId' => '8961996521650',
    'title' => 'The Slick & Smooth Edit',
    'url' => 'https://natalieanne.com/products/slick-smooth',
    'price' => '200.00',
    'currency' => 'AUD',
    'available' => true,
    'image' => 'https://cdn.test/a.jpg',
    'images' => ['https://cdn.test/a.jpg', 'https://cdn.test/b.jpg'],
    'variants' => [['id' => '478113', 'title' => 'Default Title', 'price' => '200.00', 'available' => true]],
    'handle' => 'slick-smooth',
    'vendor' => 'Natalie Anne',
    'description' => 'Six pieces.',
    'createdAt' => '2026-08-04T13:16:08+10:00',
], $over);

it('parses price to integer minor units without touching a float', function () use ($blob) {
    $p = ShopProductProjection::fromBlob($blob(), 'AUD');
    expect($p['offers'][0]['amount_minor'])->toBe(20000)
        ->and($p['offers'][0]['qualifier'])->toBe('exact')
        ->and($p['offers'][0]['currency'])->toBe('AUD')
        ->and($p['offers'][0]['availability'])->toBe('in_stock');
});

it('maps a zero price to qualifier free, not exact-with-zero', function () use ($blob) {
    $p = ShopProductProjection::fromBlob($blob(['price' => '0']), 'AUD');
    expect($p['offers'][0]['qualifier'])->toBe('free')
        ->and($p['offers'][0]['amount_minor'])->toBe(0);
});

it('marks an unavailable product out_of_stock', function () use ($blob) {
    $p = ShopProductProjection::fromBlob($blob(['available' => false]), 'AUD');
    expect($p['offers'][0]['availability'])->toBe('out_of_stock');
});

it('drops the Default Title placeholder variant entirely', function () use ($blob) {
    // 17 of the 51 dev rows are exactly this shape. A variant row labelled
    // "Default Title" names no choice.
    expect(ShopProductProjection::fromBlob($blob(), 'AUD')['variants'])->toBe([]);
});

it('keeps a real single variant', function () use ($blob) {
    $p = ShopProductProjection::fromBlob($blob([
        'variants' => [['id' => 'v1', 'title' => '250ml', 'price' => '35.50', 'available' => true]],
    ]), 'AUD');
    expect($p['variants'])->toHaveCount(1)
        ->and($p['variants'][0]['label'])->toBe('250ml')
        ->and($p['variants'][0]['sku'])->toBe('v1');
});

it('emits one offer per real variant, keyed by variant_label', function () use ($blob) {
    $p = ShopProductProjection::fromBlob($blob([
        'variants' => [
            ['id' => 'v1', 'title' => 'Small', 'price' => '10.00', 'available' => true],
            ['id' => 'v2', 'title' => 'Large', 'price' => '12.50', 'available' => false],
        ],
    ]), 'AUD');

    $variantOffers = array_values(array_filter($p['offers'], fn ($o) => $o['variant_label'] !== null));
    expect($variantOffers)->toHaveCount(2)
        ->and($variantOffers[1]['amount_minor'])->toBe(1250)
        ->and($variantOffers[1]['availability'])->toBe('out_of_stock');
});

it('maps image to cover and images to gallery, cover first', function () use ($blob) {
    $media = ShopProductProjection::fromBlob($blob(), 'AUD')['media'];
    expect($media[0]['role'])->toBe('cover')
        ->and($media[0]['url'])->toBe('https://cdn.test/a.jpg')
        ->and($media[1]['role'])->toBe('gallery')
        ->and($media[1]['url'])->toBe('https://cdn.test/b.jpg');
});

it('does not duplicate the cover image into the gallery', function () use ($blob) {
    // images[] on every dev row begins with the same URL as image.
    $urls = array_column(ShopProductProjection::fromBlob($blob(), 'AUD')['media'], 'url');
    expect($urls)->toBe(['https://cdn.test/a.jpg', 'https://cdn.test/b.jpg']);
});

it('stores the bare product url in f_link, uncomposed', function () use ($blob) {
    // link_mode + referral_query composition is 5b's, at read time.
    $p = ShopProductProjection::fromBlob($blob(), 'AUD');
    expect($p['facets']['f_link']['url'])->toBe('https://natalieanne.com/products/slick-smooth');
});

it('falls back to the store currency when the blob has none', function () use ($blob) {
    $p = ShopProductProjection::fromBlob($blob(['currency' => null]), 'AUD');
    expect($p['offers'][0]['currency'])->toBe('AUD');
});

it('derives a coord from the url and is stable across calls', function () {
    expect(ShopProductProjection::coordFor('https://x.test/p'))
        ->toBe('manual:'.sha1('https://x.test/p'))
        ->and(ShopProductProjection::coordFor('https://x.test/p'))
        ->toBe(ShopProductProjection::coordFor('https://x.test/p'));
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Shop/ShopProductProjectionTest.php`
Expected: FAIL — class does not exist.

- [ ] **Step 3: Write the mapper**

```php
<?php

namespace App\Services\Shop;

/**
 * Slice 5a §3.2: one site.shop_products.data blob → one projection for
 * ProjectionWriter::writeManualItem(). Pure — no I/O, no models — so the 51
 * real dev blobs can be fixtures.
 */
final class ShopProductProjection
{
    /** Shopify's placeholder for a product with no options. Names no choice. */
    private const PLACEHOLDER_VARIANT = 'Default Title';

    /** @return array<string, mixed> */
    public static function fromBlob(array $data, ?string $storeCurrency): array
    {
        $currency = self::str($data['currency']) ?? $storeCurrency;
        $url = (string) ($data['url'] ?? '');

        $variants = self::variants($data['variants'] ?? []);

        return array_filter([
            'kind' => 'product',
            'headline' => self::str($data['title']),
            'facets' => array_filter([
                'f_link' => $url === '' ? null : ['url' => $url],
                'f_catalog' => ($sku = self::str($data['productId'])) === null ? null : ['sku' => $sku],
            ]),
            'offers' => self::offers($data, $variants, $currency, $url),
            'variants' => array_map(
                fn (array $v) => ['label' => $v['label'], 'sku' => $v['sku']],
                $variants,
            ),
            'media' => self::media($data),
        ], static fn ($v) => $v !== null && $v !== []);
    }

    public static function coordFor(string $url): string
    {
        return 'manual:'.sha1($url);
    }

    /**
     * "200.00" → 20000. String arithmetic, never a float: every one of the 51
     * dev rows matches ^[0-9]+(\.[0-9]{1,2})?$ and a float round-trip is how a
     * cent goes missing.
     */
    public static function minorUnits(?string $price): ?int
    {
        if ($price === null || ! preg_match('/^([0-9]+)(?:\.([0-9]{1,2}))?$/', trim($price), $m)) {
            return null;
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }

    /** @return list<array{label: string, sku: ?string, price: ?string, available: bool}> */
    private static function variants(mixed $raw): array
    {
        $out = [];
        foreach ((array) $raw as $variant) {
            $variant = (array) $variant;
            $label = trim((string) ($variant['title'] ?? ''));
            if ($label === '' || $label === self::PLACEHOLDER_VARIANT) {
                continue;
            }
            $out[] = [
                'label' => $label,
                'sku' => self::str($variant['id'] ?? null),
                'price' => self::str($variant['price'] ?? null),
                'available' => ($variant['available'] ?? true) !== false,
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function offers(array $data, array $variants, ?string $currency, string $url): array
    {
        $offers = [];
        $productAmount = self::minorUnits(self::str($data['price']));
        if ($productAmount !== null) {
            $offers[] = [
                'variant_label' => null,
                'amount_minor' => $productAmount,
                'currency' => $currency,
                'qualifier' => $productAmount === 0 ? 'free' : 'exact',
                'availability' => ($data['available'] ?? true) !== false ? 'in_stock' : 'out_of_stock',
                'url' => $url === '' ? null : $url,
            ];
        }

        foreach ($variants as $variant) {
            $amount = self::minorUnits($variant['price']);
            if ($amount === null) {
                continue;
            }
            $offers[] = [
                'variant_label' => $variant['label'],
                'amount_minor' => $amount,
                'currency' => $currency,
                'qualifier' => $amount === 0 ? 'free' : 'exact',
                'availability' => $variant['available'] ? 'in_stock' : 'out_of_stock',
                'url' => $url === '' ? null : $url,
            ];
        }

        return $offers;
    }

    /** @return list<array{role: string, url: string}> */
    private static function media(array $data): array
    {
        $cover = self::str($data['image']);
        $out = $cover === null ? [] : [['role' => 'cover', 'url' => $cover]];

        foreach ((array) ($data['images'] ?? []) as $image) {
            $image = self::str($image);
            // images[] repeats the cover on every dev row; one asset, one row.
            if ($image !== null && $image !== $cover) {
                $out[] = ['role' => 'gallery', 'url' => $image];
            }
        }

        return $out;
    }

    private static function str(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return ($value === null || $value === '') ? null : $value;
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `./vendor/bin/pest tests/Feature/Shop/ShopProductProjectionTest.php`
Expected: PASS, all eleven.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Shop/ShopProductProjection.php tests/Feature/Shop/ShopProductProjectionTest.php
git commit -m "feat(shop): pure blob → projection mapper

Slice 5a §3.2. Prices parse as integer minor units by regex, never
through a float. The 17 'Default Title' placeholder variants are dropped;
availability uses the in_stock/out_of_stock vocabulary 5a establishes."
```

---

## Task 4: `ShopBackfiller` and its command

**Files:**
- Create: `app/Services/Migration/ShopBackfiller.php`
- Create: `app/Console/Commands/BackfillShopContentCommand.php`
- Test: `tests/Feature/Shop/ShopBackfillerTest.php`

**Interfaces:**
- Consumes: `ShopProductProjection::fromBlob()` / `::coordFor()` (Task 3); `ProjectionWriter::writeManualItem()`.
- Produces: `ShopBackfiller::run(bool $dryRun = false, ?string $userId = null): array` returning `['stores' => int, 'products' => int, 'skipped_no_url' => int, 'failed' => int]`. Artisan: `content:backfill-shop`.
- Also produces, on `app/Services/Shop/ShopContentWriter.php`: `upsertStore(ShopBrand $brand, string $ownerId): string`, returning the collection id. **It lives there, not on the backfiller**, because Task 6's `syncLatest()` needs the same upsert and a sync path must not depend on a `Services/Migration/` class. Task 5 adds `syncStore()` to the same file.

Follow `app/Services/Migration/MediaUploadBackfiller.php` — it is the merged
reference for structure, dry-run handling, loud failure on an ownerless site, and
the three-lane `invalidate()`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Shop/ShopBackfillerTest.php
use App\Services\Migration\ShopBackfiller;
use Illuminate\Support\Facades\DB;

it('lands a store as a collection with a storefront sidecar', function () {
    [$user, $brand] = makeShopBrand(['name' => 'Allbirds', 'referral_query' => 'ref=partna',
        'discount_code' => 'TEN', 'provider' => 'shopify']);
    makeShopProduct($brand, ['url' => 'https://allbirds.test/a', 'price' => '95.00']);

    app(ShopBackfiller::class)->run();

    $collection = DB::table('content.collections')->where('user_id', $user->id)->first();
    expect($collection->label)->toBe('Allbirds')
        ->and($collection->kind)->toBe('storefront')
        ->and($collection->is_user_created)->toBeFalse();

    $store = DB::table('content.storefronts')->where('collection_id', $collection->id)->first();
    expect($store->referral_query)->toBe('ref=partna')
        ->and($store->discount_code)->toBe('TEN');
});

it('lands products as items joined to their store collection', function () {
    [$user, $brand] = makeShopBrand();
    makeShopProduct($brand, ['url' => 'https://s.test/a', 'position' => 0]);
    makeShopProduct($brand, ['url' => 'https://s.test/b', 'position' => 1]);

    $result = app(ShopBackfiller::class)->run();

    expect($result['products'])->toBe(2)
        ->and(DB::table('content.items')->where('kind', 'product')->count())->toBe(2)
        ->and(DB::table('content.collection_items')->count())->toBe(2);
});

it('preserves the owner ordering on collection_items', function () {
    [$user, $brand] = makeShopBrand();
    makeShopProduct($brand, ['url' => 'https://s.test/a', 'position' => 1]);
    makeShopProduct($brand, ['url' => 'https://s.test/b', 'position' => 0]);

    app(ShopBackfiller::class)->run();

    $positions = DB::table('content.collection_items')->orderBy('position')->pluck('position');
    expect($positions->all())->toBe([0, 1]);
});

it('is idempotent — a second run mints nothing new and keeps item ids', function () {
    [$user, $brand] = makeShopBrand();
    makeShopProduct($brand, ['url' => 'https://s.test/a']);

    app(ShopBackfiller::class)->run();
    $before = DB::table('content.items')->pluck('id')->sort()->values();

    app(ShopBackfiller::class)->run();
    $after = DB::table('content.items')->pluck('id')->sort()->values();

    expect($after->all())->toBe($before->all())
        ->and(DB::table('content.collections')->count())->toBe(1);
});

it('writes nothing on a dry run but still counts', function () {
    [$user, $brand] = makeShopBrand();
    makeShopProduct($brand, ['url' => 'https://s.test/a']);

    $result = app(ShopBackfiller::class)->run(dryRun: true);

    expect($result['products'])->toBe(1)
        ->and(DB::table('content.items')->count())->toBe(0);
});

it('skips and counts a product with no url rather than minting a coord for empty string', function () {
    [$user, $brand] = makeShopBrand();
    makeShopProduct($brand, ['url' => '']);

    expect(app(ShopBackfiller::class)->run()['skipped_no_url'])->toBe(1);
});

it('fires all three cache lanes for a touched site', function () {
    Queue::fake();
    [$user, $brand, $site] = makeShopBrand(withSite: true);
    makeShopProduct($brand, ['url' => 'https://s.test/a']);
    $before = $site->fresh()->updated_at;

    app(ShopBackfiller::class)->run();

    Queue::assertPushed(\App\Jobs\Cloudflare\CloudflareCachePurgeJob::class);
    expect($site->fresh()->updated_at->gt($before))->toBeTrue();
    expect(DB::table('site.site_build_state')->where('site_id', $site->id)
        ->value('content_revision'))->toBeGreaterThan(0);
});
```

**Test helpers — add these to `tests/Pest.php`** beside the existing factory
helpers, with these exact return contracts, because Tasks 5, 6 and 8 destructure
them:

| Helper | Returns |
|---|---|
| `makeShopBrand(array $attrs = [], bool $withSite = false)` | `[User $user, ShopBrand $brand]`, or `[User, ShopBrand, Site]` when `$withSite` |
| `makeShopProduct(ShopBrand $brand, array $data = [])` | `ShopProduct` — `$data` merges into the `data` jsonb |
| `makeStoreCollection(int $withProducts = 0)` | `[User $user, string $collectionId, string $brandId]` — a store already in `content.*`, plus N products. **Returns the brand id third** because Task 8's endpoint URLs need it |
| `exerciseAllShopWrites(TestCase $t, User $u, ShopBrand $b): void` | Calls each of the 9 write endpoints once with valid payloads. Used only by Task 8 step 5's inertness proof |

Seed columns directly on the factory rather than relying on model mutators — a
mutator-derived column is not what a raw backfill reads.

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Shop/ShopBackfillerTest.php`
Expected: FAIL — class does not exist.

- [ ] **Step 3: Write the backfiller**

```php
<?php

namespace App\Services\Migration;

use App\Ingest\Projection\ProjectionWriter;
use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\ShopBrand;
use App\Services\Shop\ShopProductProjection;
use App\Site\Documents\BuildState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Slice 5a §3.4: land site.shop_brands / site.shop_products into content.*
 * through the slice-0b manual lane — never raw writes into content.items.
 *
 * Coord is manual:{sha1(url)}, NOT manual:{uuid} (§3.3): syncLatest() deletes
 * and re-inserts every product row each cycle, so the legacy uuid is a fresh
 * value per sync and would mint a new item every run.
 */
class ShopBackfiller
{
    public function __construct(
        private readonly ProjectionWriter $writer,
        // upsertStore() lives on ShopContentWriter, not here: syncLatest()
        // needs the same upsert in Task 6, and a sync path must not depend on
        // a Services/Migration/ class.
        private readonly \App\Services\Shop\ShopContentWriter $stores,
    ) {}

    /** @return array{stores: int, products: int, skipped_no_url: int, failed: int} */
    public function run(bool $dryRun = false, ?string $userId = null): array
    {
        $result = ['stores' => 0, 'products' => 0, 'skipped_no_url' => 0, 'failed' => 0];
        $touchedSites = [];

        $brands = ShopBrand::query()
            ->with(['products', 'connection'])
            ->orderBy('position')
            ->get()
            ->when($userId !== null, fn ($c) => $c->filter(
                fn (ShopBrand $b) => (string) $b->connection?->user_id === $userId));

        foreach ($brands as $brand) {
            try {
                // Fail LOUDLY on an ownerless connection (parent §8.2) — a
                // silent skip is a store that vanishes from the count.
                $ownerId = $brand->connection?->user_id;
                if ($ownerId === null) {
                    throw new \RuntimeException("shop_brand {$brand->id}: connection or owner missing.");
                }

                if ($dryRun) {
                    $result['stores']++;
                    $result['products'] += $brand->products->count();

                    continue;
                }

                $collectionId = $this->stores->upsertStore($brand, (string) $ownerId);
                $result['stores']++;

                foreach ($brand->products as $index => $product) {
                    $url = trim((string) (($product->data['url'] ?? '')));
                    if ($url === '') {
                        $result['skipped_no_url']++;

                        continue;
                    }

                    $itemId = $this->writer->writeManualItem(
                        (string) $ownerId,
                        ShopProductProjection::coordFor($url),
                        ShopProductProjection::fromBlob($product->data, $brand->currency),
                    );

                    $this->linkToCollection($collectionId, $itemId, (int) $product->position);
                    $result['products']++;
                }

                if (($siteId = $this->siteIdFor((string) $ownerId)) !== null) {
                    $touchedSites[$siteId] = true;
                }
            } catch (\Throwable $e) {
                report($e);
                Log::warning('Shop backfill failed for one brand.', [
                    'shop_brand_id' => $brand->id, 'error' => $e->getMessage(),
                ]);
                $result['failed']++;
            }
        }

        if (! $dryRun) {
            $this->invalidate(array_keys($touchedSites));
        }

        return $result;
    }

    /**
     * WRITE THIS METHOD ON app/Services/Shop/ShopContentWriter.php, PUBLIC —
     * it is shown here because it is what this task exercises. Keyed so a
     * re-run updates rather than duplicates.
     */
    public function upsertStore(ShopBrand $brand, string $ownerId): string
    {
        $existing = DB::table('content.collections')
            ->where('user_id', $ownerId)
            ->where('kind', 'storefront')
            ->where('label', (string) ($brand->name ?? $brand->brand_id))
            ->value('id');

        $collectionId = (string) ($existing ?? Str::uuid());

        DB::table('content.collections')->upsert([[
            'id' => $collectionId,
            'user_id' => $ownerId,
            'parent_id' => null,
            'label' => (string) ($brand->name ?? $brand->brand_id),
            'kind' => 'storefront',
            'position' => (int) $brand->position,
            'is_user_created' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['id'], ['label', 'position', 'updated_at']);

        DB::table('content.storefronts')->upsert([[
            'collection_id' => $collectionId,
            'provider' => (string) $brand->provider,
            'url' => $brand->url,
            'source_url' => $brand->source_url,
            'currency' => $brand->currency,
            'discount_code' => $brand->discount_code,
            'referral_query' => (string) ($brand->referral_query ?? ''),
            'is_individual' => (bool) $brand->is_individual,
            'fetch_mode' => $brand->fetch_mode,
            'connect_status' => $brand->connect_status,
            'connect_error' => $brand->connect_error,
            'products_curated_at' => $brand->products_curated_at,
            'logo_url' => $brand->logo,
            'favicon_url' => $brand->favicon,
            'logo_mark_url' => $brand->logo_mark_url,
            'logo_mark_svg_url' => $brand->logo_mark_svg_url,
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['collection_id'], [
            'provider', 'url', 'source_url', 'currency', 'discount_code',
            'referral_query', 'is_individual', 'fetch_mode', 'connect_status',
            'connect_error', 'products_curated_at', 'logo_url', 'favicon_url',
            'logo_mark_url', 'logo_mark_svg_url', 'updated_at',
        ]);

        return $collectionId;
    }

    private function linkToCollection(string $collectionId, string $itemId, int $position): void
    {
        DB::table('content.collection_items')->upsert([[
            'collection_id' => $collectionId,
            'item_id' => $itemId,
            'source_id' => null,
            'position' => $position,
        ]], ['collection_id', 'item_id'], ['position']);
    }

    private function siteIdFor(string $userId): ?string
    {
        $id = DB::connection('pgsql')->table('site.sites')->where('user_id', $userId)->value('id');

        return $id === null ? null : (string) $id;
    }

    /**
     * Raw-write seam — all three lanes per touched site (spec §4).
     * writeManualItem() bumped build state per item already; updated_at and the
     * edge purge are the two lanes it deliberately does not own.
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
}
```

- [ ] **Step 4: Run the tests**

Run: `./vendor/bin/pest tests/Feature/Shop/ShopBackfillerTest.php`
Expected: PASS, all seven.

- [ ] **Step 5: Write the artisan command**

```php
<?php

namespace App\Console\Commands;

use App\Services\Migration\ShopBackfiller;
use Illuminate\Console\Command;

/** Artisan wrapper for the slice-5a shop backfill (spec §3.4). Idempotent. */
class BackfillShopContentCommand extends Command
{
    protected $signature = 'content:backfill-shop
        {--dry-run : Report counts without writing}
        {--user= : Only this user id}';

    protected $description = 'Backfill site.shop_brands / shop_products into content.*';

    public function handle(ShopBackfiller $backfiller): int
    {
        $dry = (bool) $this->option('dry-run');
        $r = $backfiller->run($dry, $this->option('user') ?: null);

        $verb = $dry ? 'would backfill' : 'backfilled';
        $this->info("Shop: {$verb} {$r['stores']} stores, {$r['products']} products"
            .($r['skipped_no_url'] > 0 ? ", skipped {$r['skipped_no_url']} without a url" : '')
            .($r['failed'] > 0 ? ", {$r['failed']} FAILED" : '.'));

        if ($r['failed'] > 0) {
            $this->warn('Failures are reported to Nightwatch and logged with brand ids. Fix and re-run — the lane is idempotent.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
```

- [ ] **Step 6: Verify the command is registered and dry-runs**

Run: `php artisan content:backfill-shop --dry-run`
Expected: a count line, and `content.items` unchanged.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Migration/ShopBackfiller.php \
        app/Console/Commands/BackfillShopContentCommand.php \
        tests/Feature/Shop/ShopBackfillerTest.php tests/Pest.php
git commit -m "feat(shop): ShopBackfiller — stores to collections, products to items

Slice 5a §3.4. Writes through the manual lane only. Coord is sha1(url)
because syncLatest() rebuilds rows and the legacy uuid is not an
identifier. All three cache lanes fire per touched site."
```

---

## Task 5: `ShopContentWriter` — reconcile, never rebuild

**Files:**
- Create: `app/Services/Shop/ShopContentWriter.php`
- Test: `tests/Feature/Shop/ShopContentWriterTest.php`

**Interfaces:**
- Consumes: `ShopProductProjection`, `ProjectionWriter::writeManualItem()`.
- Produces: `ShopContentWriter::syncStore(string $userId, string $collectionId, array $products, ?string $currency): int` — upserts the given product set and retires anything else in that collection. Returns the count written. Used by Tasks 6, 7 and 8.

**The point of this task:** `ShopCatalog::syncLatest()` today deletes every row
for a brand and re-inserts. Ported literally that destroys and re-mints
`content.items` ids on every sync, breaking `analytics.item_views` references and
any pin. It must reconcile by coord instead.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Shop/ShopContentWriterTest.php
use App\Services\Shop\ShopContentWriter;
use Illuminate\Support\Facades\DB;

it('keeps the same item id when a product is re-synced', function () {
    [$user, $collectionId] = makeStoreCollection();
    $blob = ['url' => 'https://s.test/a', 'title' => 'Tee', 'price' => '10.00', 'available' => true];

    app(ShopContentWriter::class)->syncStore((string) $user->id, $collectionId, [$blob], 'AUD');
    $first = DB::table('content.items')->where('kind', 'product')->value('id');

    app(ShopContentWriter::class)->syncStore((string) $user->id, $collectionId,
        [array_merge($blob, ['price' => '12.00'])], 'AUD');

    expect(DB::table('content.items')->where('kind', 'product')->value('id'))->toBe($first)
        ->and(DB::table('content.offers')->where('item_id', $first)
            ->whereNull('variant_label')->value('amount_minor'))->toBe(1200);
});

it('retires a product dropped from the catalogue via items.removed_at', function () {
    [$user, $collectionId] = makeStoreCollection();
    $a = ['url' => 'https://s.test/a', 'title' => 'A', 'price' => '1.00'];
    $b = ['url' => 'https://s.test/b', 'title' => 'B', 'price' => '2.00'];

    app(ShopContentWriter::class)->syncStore((string) $user->id, $collectionId, [$a, $b], 'AUD');
    app(ShopContentWriter::class)->syncStore((string) $user->id, $collectionId, [$a], 'AUD');

    $gone = DB::table('content.items as i')
        ->join('content.f_link as l', 'l.item_id', '=', 'i.id')
        ->where('l.url', 'https://s.test/b')->first(['i.id', 'i.removed_at']);

    expect($gone->removed_at)->not->toBeNull();
});

it('never writes source_items.removed_at — that would resurrect on reappearance', function () {
    [$user, $collectionId] = makeStoreCollection();
    $a = ['url' => 'https://s.test/a', 'title' => 'A', 'price' => '1.00'];

    app(ShopContentWriter::class)->syncStore((string) $user->id, $collectionId, [$a], 'AUD');
    app(ShopContentWriter::class)->syncStore((string) $user->id, $collectionId, [], 'AUD');

    expect(DB::table('content.source_items')->whereNotNull('removed_at')->count())->toBe(0);
});

it('hard-deletes nothing', function () {
    [$user, $collectionId] = makeStoreCollection();
    app(ShopContentWriter::class)->syncStore((string) $user->id, $collectionId,
        [['url' => 'https://s.test/a', 'title' => 'A', 'price' => '1.00']], 'AUD');

    app(ShopContentWriter::class)->syncStore((string) $user->id, $collectionId, [], 'AUD');

    expect(DB::table('content.items')->count())->toBe(1);
});

it('survives a sync run immediately after the backfill — parent 8.3', function () {
    // The kickoff asks for "a real connector run after backfill". No connector
    // emits kind=product (there is no gumroad ingest source), so the honest
    // equivalent is the thing that actually rewrites this data: a sync.
    [$user, $brand] = makeShopBrand();
    makeShopProduct($brand, ['url' => 'https://s.test/a', 'price' => '9.00']);

    app(\App\Services\Migration\ShopBackfiller::class)->run();
    $before = DB::table('content.items')->where('kind', 'product')->pluck('id')->sort()->values();

    $collectionId = DB::table('content.collections')->value('id');
    app(ShopContentWriter::class)->syncStore((string) $user->id, $collectionId,
        [['url' => 'https://s.test/a', 'title' => 'A', 'price' => '9.00']], 'AUD');

    $after = DB::table('content.items')->where('kind', 'product')
        ->whereNull('removed_at')->pluck('id')->sort()->values();

    expect($after->all())->toBe($before->all());
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Shop/ShopContentWriterTest.php`
Expected: FAIL — class does not exist.

- [ ] **Step 3: Write the writer**

```php
<?php

namespace App\Services\Shop;

use App\Ingest\Projection\ProjectionWriter;
use Illuminate\Support\Facades\DB;

/**
 * Slice 5a §3.5. Replaces ShopCatalog::syncLatest()'s delete-then-insert with a
 * reconcile: upsert by coord, retire what is gone. A literal port of the old
 * transaction would re-mint content.items ids every sync, breaking
 * analytics.item_views references and any pin.
 */
final class ShopContentWriter
{
    public function __construct(private readonly ProjectionWriter $writer) {}

    /**
     * @param  list<array<string,mixed>>  $products  raw scraper blobs, catalogue order
     */
    public function syncStore(string $userId, string $collectionId, array $products, ?string $currency): int
    {
        $seen = [];
        $written = 0;

        foreach ($products as $position => $blob) {
            $url = trim((string) ($blob['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $coord = ShopProductProjection::coordFor($url);
            // §1.7: one coord per canonical URL per user. Two catalogue entries
            // sharing a URL would poison that key for the whole resolution run,
            // so the dedupe happens BEFORE writing, not after.
            if (isset($seen[$coord])) {
                continue;
            }
            $seen[$coord] = true;

            $itemId = $this->writer->writeManualItem(
                $userId, $coord, ShopProductProjection::fromBlob($blob, $currency));

            DB::table('content.collection_items')->upsert([[
                'collection_id' => $collectionId,
                'item_id' => $itemId,
                'source_id' => null,
                'position' => $position,
            ]], ['collection_id', 'item_id'], ['position']);

            $written++;
        }

        $this->retireAbsent($collectionId, array_keys($seen));

        return $written;
    }

    /**
     * Items still linked to this store but absent from the fetched catalogue.
     * items.removed_at ONLY — source_items.removed_at is cleared on
     * reappearance (ProjectionWriter:272-275), so writing it there would
     * resurrect a product the owner removed.
     *
     * @param  list<string>  $liveCoords
     */
    private function retireAbsent(string $collectionId, array $liveCoords): void
    {
        $absent = DB::table('content.collection_items as ci')
            ->join('content.source_items as si', 'si.item_id', '=', 'ci.item_id')
            ->where('ci.collection_id', $collectionId)
            ->when($liveCoords !== [], fn ($q) => $q->whereNotIn('si.coord', $liveCoords))
            ->pluck('ci.item_id')
            ->unique()
            ->all();

        if ($absent === []) {
            return;
        }

        DB::table('content.items')->whereIn('id', $absent)->whereNull('removed_at')
            ->update(['removed_at' => now(), 'updated_at' => now()]);
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `./vendor/bin/pest tests/Feature/Shop/ShopContentWriterTest.php`
Expected: PASS, all five.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Shop/ShopContentWriter.php tests/Feature/Shop/ShopContentWriterTest.php
git commit -m "feat(shop): reconcile-by-coord writer, replacing delete-then-insert

Slice 5a §3.5. Keeps item ids stable across syncs so analytics and pins
survive. Retirement is items.removed_at only — never
source_items.removed_at, which is cleared on reappearance. Includes the
adapted parent-8.3 regression: a sync after the backfill destroys nothing."
```

---

## Task 6: Repoint `syncLatest()` and the seeders

**Files:**
- Modify: `app/Services/Platforms/ShopCatalog.php:81-132`
- Modify: `app/Services/Platforms/ShopProductSeeder.php:84-103`
- Modify: `app/Services/Platforms/Strategies/Fetch/ShopFetch.php:46-50`
- Test: extend `tests/Feature/Shop/ShopContentWriterTest.php`

**Interfaces:**
- Consumes: `ShopContentWriter::syncStore()` (Task 5) and `::upsertStore()` (Task 4).
- Produces: `ShopCatalog::syncLatest(ShopBrand $brand): ?int` — unchanged signature and unchanged null semantics (null = store reachable but empty; `HttpException` re-thrown = unreachable). Only its storage target changes. Plus `ShopContentWriter::isCurated(ShopBrand $brand): bool`, added in step 5 and consumed by `ShopFetch`.

The signature and its two distinct failure signals must not change:
`ShopFetch` depends on telling "empty" from "broken" to decide between a quiet
304 and tripping the circuit breaker.

- [ ] **Step 1: Write the failing test** (append to `ShopContentWriterTest.php`)

```php
it('syncLatest writes content.* and leaves shop_products untouched', function () {
    [$user, $brand] = makeShopBrand();
    makeShopProduct($brand, ['url' => 'https://s.test/old', 'price' => '1.00']);
    app(\App\Services\Migration\ShopBackfiller::class)->run();

    fakeProviderCatalog($brand, [
        ['url' => 'https://s.test/old', 'title' => 'Old', 'price' => '1.00'],
        ['url' => 'https://s.test/new', 'title' => 'New', 'price' => '2.00'],
    ]);
    $legacyBefore = DB::table('site.shop_products')->count();

    expect(app(\App\Services\Platforms\ShopCatalog::class)->syncLatest($brand))->toBe(2)
        ->and(DB::table('site.shop_products')->count())->toBe($legacyBefore)
        ->and(DB::table('content.items')->where('kind', 'product')
            ->whereNull('removed_at')->count())->toBe(2);
});

it('syncLatest still returns null for a reachable but empty store', function () {
    [$user, $brand] = makeShopBrand();
    fakeProviderCatalog($brand, []);

    expect(app(\App\Services\Platforms\ShopCatalog::class)->syncLatest($brand))->toBeNull();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Shop/ShopContentWriterTest.php`
Expected: FAIL — the first test finds 0 content items, because `syncLatest()`
still writes the legacy table.

- [ ] **Step 3: Rewrite `syncLatest()`'s storage half**

Replace the `DB::connection('pgsql')->transaction(...)` block (`:119-129`) with:

```php
        // 5a §3.5: reconcile into content.* instead of rebuilding
        // site.shop_products. The count-preserving `take($count)` selection
        // above is unchanged — only where the result is stored changes.
        $collectionId = $this->storeCollectionId($brand);

        return $this->content->syncStore(
            (string) $brand->connection->user_id,
            $collectionId,
            $latest->all(),
            $brand->currency,
        );
```

Inject `ShopContentWriter $content` into the constructor. Add a private
`storeCollectionId(ShopBrand $brand): string` that finds the brand's
`content.collections` row (by `user_id` + `kind='storefront'` + label) and
creates it with its storefront sidecar if absent, by calling
`ShopContentWriter::upsertStore()` — the same method Task 4's backfiller uses.
One implementation, two callers.

Keep `$count = max(1, $brand->products()->count() ?: self::DEFAULT_LATEST_COUNT)`
working by counting live `collection_items` for the store instead of
`$brand->products()`.

- [ ] **Step 4: Repoint `ShopProductSeeder`**

Replace the `DB::connection('pgsql')->transaction(...)` block (`:93-103`) with a
`ShopContentWriter::syncStore()` call on the individual store's collection,
preserving the existing newest-first, dedupe-by-`productId`,
`MAX_INDIVIDUAL_PRODUCTS = 20` ordering contract exactly — build `$ordered` as
today, then hand the whole array to `syncStore()`.

- [ ] **Step 5: Move `ShopFetch`'s curation gate**

`ShopFetch:46-50` filters `->whereNull('products_curated_at')` on `ShopBrand`.
That column now lives on `content.storefronts`. Replace with a join through the
brand's collection:

```php
        $latestBrands = $connection->shopBrands()
            ->where('is_individual', false)
            ->get()
            ->reject(fn ($b) => $this->content->isCurated($b));
```

Add `ShopContentWriter::isCurated(ShopBrand $brand): bool` reading
`content.storefronts.products_curated_at`. **Do not** reintroduce
`selection_mode` — spec §1.2 records it as dead (#SEM-1).

- [ ] **Step 6: Run the shop and platform suites**

Run: `./vendor/bin/pest tests/Feature/Shop tests/Feature/Platforms`
Expected: PASS. `ShopFetch`'s existing 304 / unavailable tests must still pass
unchanged — they are the proof the two failure signals survived.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Platforms/ShopCatalog.php \
        app/Services/Platforms/ShopProductSeeder.php \
        app/Services/Platforms/Strategies/Fetch/ShopFetch.php \
        app/Services/Shop/ShopContentWriter.php \
        tests/Feature/Shop/ShopContentWriterTest.php
git commit -m "refactor(shop): sync and seeders write content.*, not shop_products

Slice 5a §3.5. syncLatest() keeps its signature and both failure signals
(null = reachable-but-empty, HttpException = unreachable) so ShopFetch's
304-vs-circuit-breaker split is unchanged. products_curated_at follows
the column to content.storefronts; selection_mode stays dead."
```

---

## Task 7: `ShopContentReader` and the read endpoints

**Files:**
- Create: `app/Services/Shop/ShopContentReader.php`
- Modify: `app/Http/Controllers/Api/Platforms/ShopController.php` (`brands`, `brandProducts`, `selection`, `settings`, `connectStatus`)
- Test: `tests/Feature/Shop/ShopEndpointParityTest.php`

**Interfaces:**
- Consumes: `content.collections`, `content.storefronts`, `content.items` + `offers` + `item_media` + `f_link`.
- Produces: `ShopContentReader::brandMap(User $user): array` — the brand-keyed map `ShopBrand::toBrandArray()` produces today, `{ "<brandId>": {id, provider, url, name, currency, favicon, logo, discountCode, linkMode, referralQuery, selectionMode, sourceUrl, fetchMode, products: [...]}, … }`, each product carrying the 14 `SHOP_PRODUCT_ALLOWLIST` keys.

**The acceptance criterion is a response diff, not a shape review.** Partna-App
must need no change.

- [ ] **Step 1: Capture the current responses as fixtures**

Write `tests/Feature/Shop/ShopEndpointParityTest.php` that, for a seeded user
with two stores and five products, asserts the exact JSON structure of all 14
endpoints. Generate the expectations by running the endpoints on
`development` **before** any controller change and pasting the arrays in. Do not
hand-write them from the docblocks — the docblocks are what this slice is
checking.

- [ ] **Step 2: Run the parity test against unchanged controllers**

Run: `./vendor/bin/pest tests/Feature/Shop/ShopEndpointParityTest.php`
Expected: PASS — it must be green *before* the repoint, or it is not a baseline.

- [ ] **Step 3: Write the reader**

`ShopContentReader::brandMap()` selects every `content.collections` row of
`kind='storefront'` for the user with its `storefronts` sidecar, then its items
via `collection_items` ordered by `position`, hydrating each into the legacy
product shape:

- `productId` ← `f_catalog.sku`
- `title` ← `items.headline_cache`
- `url` ← `f_link.url`
- `price` ← the null-`variant_label` offer's `amount_minor`, formatted back to a
  decimal string with two places
- `currency` ← that offer's `currency`
- `available` ← that offer's `availability === 'in_stock'`
- `image` ← the `role='cover'` `item_media` row's resolved URL
- `images` ← cover then every `role='gallery'` row, in `position` order
- `variants` ← `item_variants` joined to their variant offers, as
  `{id, title, price, available, image: null}`
- `createdAt` ← `items.first_seen_at`, ISO-8601
- `handle`, `vendor`, `description` ← `items.facets_cache`

Batch every collection read — one query per table across the whole map, never
per item. `brands` is a dashboard endpoint but `selection` feeds the public
payload path.

- [ ] **Step 4: Repoint the five read endpoints**

Replace each controller method's `ShopBrand`/`ShopProduct` queries with
`ShopContentReader`. Leave the Resource classes, Form Requests and policy calls
untouched — only the data source changes.

- [ ] **Step 5: Run the parity test against the repointed controllers**

Run: `./vendor/bin/pest tests/Feature/Shop/ShopEndpointParityTest.php`
Expected: PASS with **zero** expectation edits. If an expectation needs
changing, the repoint is wrong — do not update the fixture to match the code.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Shop/ShopContentReader.php \
        app/Http/Controllers/Api/Platforms/ShopController.php \
        tests/Feature/Shop/ShopEndpointParityTest.php
git commit -m "refactor(shop): read endpoints served from content.*

Slice 5a §3.6. Response shapes byte-identical — the parity test was
green against the old controllers first, and passes unedited against the
new ones."
```

---

## Task 8: The write endpoints

**Files:**
- Modify: `app/Http/Controllers/Api/Platforms/ShopController.php` (`addBrand`, `updateBrand`, `removeBrand`, `catalog`, `setProducts`, `addProduct`, `removeProduct`, `updateSettings`, `forget`)
- Test: extend `tests/Feature/Shop/ShopEndpointParityTest.php`

**Interfaces:**
- Consumes: `ShopContentWriter` (Task 5), `ShopContentReader` (Task 7).
- Produces: no new public interface. `site.shop_brands` / `site.shop_products` are written by nothing after this task.

- [ ] **Step 1: Write the failing tests**

```php
it('setProducts writes the selection to content.* in the given order', function () {
    [$user, $collectionId, $brandId] = makeStoreCollection(withProducts: 2);
    $this->actingAsUser($user)->putJson("/api/platforms/shop/brands/{$brandId}/selection", [
        'productIds' => ['p2', 'p1'],
    ])->assertOk();

    $positions = DB::table('content.collection_items')->where('collection_id', $collectionId)
        ->orderBy('position')->pluck('item_id');
    expect($positions)->toHaveCount(2);
    expect(DB::table('site.shop_products')->count())->toBe(0);
});

it('setProducts stamps products_curated_at on the storefront', function () {
    // #SEM-1: this is the flag ShopFetch actually reads. Not selection_mode.
    [$user, $collectionId, $brandId] = makeStoreCollection(withProducts: 1);
    $this->actingAsUser($user)->putJson("/api/platforms/shop/brands/{$brandId}/selection",
        ['productIds' => ['p1']])->assertOk();

    expect(DB::table('content.storefronts')->where('collection_id', $collectionId)
        ->value('products_curated_at'))->not->toBeNull();
});

it('removeBrand retires items rather than deleting them', function () {
    [$user, $collectionId, $brandId] = makeStoreCollection(withProducts: 2);
    $this->actingAsUser($user)->deleteJson("/api/platforms/shop/brands/{$brandId}")->assertOk();

    expect(DB::table('content.items')->where('kind', 'product')->count())->toBe(2)
        ->and(DB::table('content.items')->whereNull('removed_at')->count())->toBe(0)
        ->and(DB::table('content.collections')->count())->toBe(0);
});

it('removeProduct retires one item and leaves its siblings alone', function () {
    [$user, $collectionId, $brandId] = makeStoreCollection(withProducts: 3);
    $this->actingAsUser($user)->deleteJson('/api/platforms/shop/products/p2')->assertOk();

    expect(DB::table('content.items')->whereNull('removed_at')->count())->toBe(2);
});

it('forget removes every store for the user and retires their items', function () {
    [$user, $collectionId, $brandId] = makeStoreCollection(withProducts: 2);
    $this->actingAsUser($user)->deleteJson('/api/platforms/shop')->assertOk();

    expect(DB::table('content.collections')->count())->toBe(0)
        ->and(DB::table('content.items')->whereNull('removed_at')->count())->toBe(0);
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Shop/ShopEndpointParityTest.php`
Expected: FAIL — writes still land in `site.shop_products`.

- [ ] **Step 3: Repoint the write endpoints**

- `addBrand` / `updateBrand` → upsert `collections` + `storefronts` via
  `ShopContentWriter`. Keep `connect_status = 'pending'` handling exactly as it
  is: `PublicIntegrationConnectionResource:360` rejects `'pending'` brands from
  the public wire and that gate must keep working.
- `setProducts` → `syncStore()` with the chosen order, then stamp
  `content.storefronts.products_curated_at = now()`.
- `addProduct` / `removeProduct` → `syncStore()` on the `is_individual` store,
  preserving the `MAX_INDIVIDUAL_PRODUCTS = 20` cap and newest-first ordering.
- `removeBrand` / `forget` → set `items.removed_at` for the store's items, then
  delete the `collections` row (the FK cascades `storefronts`).
- `catalog` → **unchanged.** It reads the live store through
  `ShopCatalog::providerProducts()` and the picker cache, neither of which this
  slice touches.

Every one of these is a raw-write seam: fire all three cache lanes.

- [ ] **Step 4: Run the tests**

Run: `./vendor/bin/pest tests/Feature/Shop/ShopEndpointParityTest.php`
Expected: PASS, including the Task 7 parity expectations still unedited.

- [ ] **Step 5: Prove the legacy tables are inert**

```php
it('no shop endpoint writes the legacy tables', function () {
    [$user, $brand] = makeShopBrand();
    $before = [DB::table('site.shop_brands')->max('updated_at'),
               DB::table('site.shop_products')->max('updated_at')];

    // exercise every write endpoint in sequence
    exerciseAllShopWrites($this, $user, $brand);

    expect([DB::table('site.shop_brands')->max('updated_at'),
            DB::table('site.shop_products')->max('updated_at')])->toBe($before);
});
```

Run: `./vendor/bin/pest tests/Feature/Shop`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/Platforms/ShopController.php tests/Feature/Shop
git commit -m "refactor(shop): write endpoints served from content.*

Slice 5a §3.6. Legacy tables now written by nothing — asserted. Removal
sets items.removed_at and deletes the collection; the FK cascades the
storefront. catalog() is deliberately unchanged: it reads the live store,
not these tables."
```

---

## Task 9: Full-suite green and the static gates

**Files:** none created; fixes only.

- [ ] **Step 1: Run the full suite**

Run: `composer test`
Expected: PASS. Investigate every failure against the pre-branch baseline before
assuming it is yours — this repo has a known phantom-failure mode when a sibling
worktree edits concurrently.

- [ ] **Step 2: Run the applied-schema lane**

Run: `composer test:schema`
Expected: PASS. This lane is the only one that runs
`ContentStorefrontsConstraintsTest`; a green `composer test` says nothing about it.

- [ ] **Step 3: PHPStan**

Run: `./vendor/bin/phpstan analyse`
Expected: no new errors. Annotate the **model**, never the call site.

- [ ] **Step 4: Pint**

Run: `php artisan pint`
Expected: only your files touched. The baseline is not clean — do not commit
unrelated reformatting.

- [ ] **Step 5: Commit any fixes**

```bash
git add -A && git commit -m "chore(shop): suite, schema lane, phpstan and pint green"
```

---

## Task 10: Dev verification, checkpoint and wire manifest

**Files:**
- Create: `docs/wire-changes/2026-08-12-slice-5a-shop-data.md`
- Modify: `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md` (append the checkpoint)

- [ ] **Step 1: Dry-run the backfill against dev**

```bash
php artisan content:backfill-shop --dry-run
```
Expected: `9 stores, 51 products`. A different figure means dev moved — record
the new one and say so; do not adjust the plan silently.

- [ ] **Step 2: Run it for real, then run it again**

```bash
php artisan content:backfill-shop
php artisan content:backfill-shop
```
Expected: identical counts both times, and step 3's item ids unchanged between
runs. That is the idempotency proof.

- [ ] **Step 3: Run the §5.1 assertions and paste the output**

Run each query from spec §5.1 against `glncumufgaqcmqhzwrxm` and paste the real
output into the checkpoint. The coverage-gate query must return **0**.

- [ ] **Step 4: Scan the logs**

```bash
cloud env:logs partna development --minutes 10
```
Expected: clean. Also check Nightwatch — slice 0's checkpoint recorded a log scan
performed and a Nightwatch scan skipped; do not repeat that gap.

- [ ] **Step 5: Write the wire manifest**

`docs/wire-changes/2026-08-12-slice-5a-shop-data.md`, per parent §10: endpoint,
before shape, after shape, consuming repo. For this slice every entry reads
"unchanged" — **that is the point**, and stating it explicitly is what lets 5b
and slice 7 tell what has already moved. Name `profile.pools.shop` and the
`/integrations` retirement as **5b's**, not landed here.

- [ ] **Step 6: Append the checkpoint to the parent spec**

New section `## 15. Slice 5a checkpoint — the shop data move`, following §14's
shape: build state verified complete, pre-implementation baseline, the commands
run with output pasted, what shipped, Pest case names, and anything outstanding.

- [ ] **Step 7: Commit**

```bash
git add docs/wire-changes/2026-08-12-slice-5a-shop-data.md \
        docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md
git commit -m "docs(wire): slice 5a manifest and checkpoint — verified on dev"
```

---

## Merge

Rebase onto `development` (never merge it in — conflicts surface once and history
stays linear), re-run `composer test`, `composer test:schema`,
`./vendor/bin/phpstan analyse` and `php artisan pint`, then **STOP for explicit
sign-off** before merging. Never push to `production`.

Before merging, confirm the §7 propagation edits committed in `503e97ba7` are
still accurate against whatever landed on `development` in the meantime — slices
1b and 3 are in flight and may have moved the same files.
