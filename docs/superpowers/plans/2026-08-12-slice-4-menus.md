# Slice 4 — Menus → `content.*` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move all 318 live dishes, their 402 category memberships, their per-platform pricing and their 318 public URL slugs onto `content.*`, add the menus pool, and prove the `menu_item` lane end to end on dev — without dropping a single legacy table.

**Architecture:** A pure mapper turns a legacy `site.menu_items` row into the projection shape `ProjectionWriter` already understands; a backfiller drives it through `writeManualItem()` (the slice-0b manual lane), then migrates slug rows and seeds pool pins. The pool itself is registry config. `content.item_slugs`' existing allocator is widened by one const to take over slug minting from `MenuItemObserver`. `PoolResolver`'s collections map — storefront-only today — is widened so menu categories and ordering platforms both surface.

**Tech Stack:** PHP 8.4, Laravel 12, Pest 4, Postgres (Supabase, dev ref `glncumufgaqcmqhzwrxm`), SQLite in-memory for the default test lane.

**Spec:** `docs/superpowers/specs/2026-08-12-slice-4-menus-design.md`

## Global Constraints

- **No Laravel migrations, ever.** Schema changes are raw SQL under `supabase/migrations/`. **This slice needs none** (spec §2.3) — `menu_item` is already in both kind CHECKs, `content.offers` already has `channel`/`url`, `content.collections.kind` has no CHECK.
- **Production is out of scope.** No tool call may name `edplucmvkcnokyygxqsb`, `api.partna.au`, or the `production` branch.
- **Backfill is production code** under `app/Services/Migration/`, artisan-driven, `--dry-run`, idempotent, counts reported.
- **Pool and migration tests live in `tests/Feature/Content/`.** A new child directory under `tests/Feature/` fails `AuditPipelineIntegrityTest` until wired into `codebase_chunks()`.
- **Cache invalidation is three lanes**: `BuildState::bump($siteId)`, touch `site.sites.updated_at`, dispatch `CloudflareCachePurgeJob`. No CI check enforces this. `SiteCacheLanes::bust([$siteId])` does all three.
- **Tests run SQLite, production is Postgres.** Constraint-bound writes get checked against `supabase/migrations/` DDL. `offers_qualifier_check` allows exactly `exact|from|upto|range|free|variable|on_request`.
- **Touching `ProjectionWriter` means running `composer test:pg`.** A green SQLite run says nothing about that file.
- **PHPStan in a worktree:** `php -d memory_limit=1G ./vendor/bin/phpstan analyse <path> --no-progress --debug`. The default invocation dies with "Child process error (exit code 255)" and OOMs at 128M; neither failure looks like what it is.
- **Never `git stash`.** Never touch the main checkout or a sibling worktree.
- **Apify spend cap: US$18 total** for Task 11. Re-check remaining credit before spending. Exceeding the cap is a STOP, not a spend.

---

## File Structure

| File | Responsibility |
|---|---|
| `app/Services/Platforms/MenuProjectionMapper.php` | **Create.** Pure: one legacy dish row (+ its categories, platform rows, menu) → the projection array. No DB, no side effects. |
| `app/Services/Migration/MenuBackfiller.php` | **Create.** Drives the mapper through `ProjectionWriter::writeManualItem()`, migrates slug rows, seeds pins, invalidates. |
| `app/Console/Commands/BackfillMenus.php` | **Create.** `content:backfill-menus --dry-run --user=`. |
| `app/Site/Pools/PoolRegistry.php` | **Modify.** Four const arrays gain a `menus` entry. |
| `app/Services/Content/ContentItemSlugAllocator.php` | **Modify.** `SLUGGED_KINDS` gains `menu_item`; docblock updated. |
| `app/Ingest/Projection/MenuItemProjector.php` | **Modify.** Emit `collections`, `f_rated`, badge tags, the full image set. |
| `app/Site/Pools/PoolResolver.php` | **Modify.** Collections map widened past storefronts; `diningModes` added to the resolve contract. |
| `app/Services/PublicSite/IndividualProfilePayloadBuilder.php` | **Modify.** Spread `diningModes` when non-null. |
| `app/Services/Cloudflare/CloudflarePurgeService.php` | **Modify.** Dish-page purge targets read `content.*`; catch wrapped in `EscalatesRepeatedFaults`. |
| `app/Ingest/Projection/ProjectionWriter.php` | **Modify.** `forget()` a removed item's slug. |
| `tests/Feature/Content/MenusPoolTest.php` | **Create.** Registry + section shape + wire. |
| `tests/Feature/Content/MenuBackfillerTest.php` | **Create.** Coverage, idempotency, slug migration, `is_manual` survival. |
| `tests/Unit/Platforms/MenuProjectionMapperTest.php` | **Create.** The mapping table, per field. |
| `tests/Feature/Content/MenuSlugLaneTest.php` | **Create.** Mint, rename→301, remove→freed. |

---

## Task 1: The menus pool exists

**Files:**
- Modify: `app/Site/Pools/PoolRegistry.php` (`POOLS`, `PAGE_KEYS`, `PAGE_LABELS`, `SECTION_SHAPE`, class docblock)
- Test: `tests/Feature/Content/MenusPoolTest.php` (create)

**Interfaces:**
- Consumes: nothing.
- Produces: pool key `'menus'` owning kind `'menu_item'`; `PoolRegistry::sectionKey('menus') === 'pool:menus'`; `PoolRegistry::sectionShape('menus')` returns `['rule' => [['op' => 'kind_is', 'values' => ['menu_item']]], 'order_by' => 'recency']`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Site\Pools\PoolRegistry;

it('owns the menu_item kind and nothing else', function () {
    expect(PoolRegistry::kinds('menus'))->toBe(['menu_item'])
        ->and(PoolRegistry::poolForKind('menu_item'))->toBe('menus');
});

it('provisions the settled priced-undated shape, not latest_per_auto_source', function () {
    // latest_per_auto_source emits ONE item per connection source, which for a
    // 156-dish menu means one dish visible and 155 hidden — the pathology
    // events hit in slice 2 and media in slice 1a.
    expect(PoolRegistry::sectionShape('menus'))->toBe([
        'rule' => [['op' => 'kind_is', 'values' => ['menu_item']]],
        'order_by' => 'recency',
    ]);
});

it('is a full-curation pool — a dish is the owner\'s own content', function () {
    expect(PoolRegistry::allowsPin('menus'))->toBeTrue()
        ->and(PoolRegistry::allowsManualAdd('menus'))->toBeTrue()
        ->and(PoolRegistry::carriesSourceStats('menus'))->toBeFalse()
        ->and(PoolRegistry::carriesLatestTag('menus'))->toBeFalse();
});

it('hangs its curation off the menu page', function () {
    expect(PoolRegistry::PAGE_KEYS['menus'])->toBe('menu')
        ->and(PoolRegistry::PAGE_LABELS['menus'])->toBe('Menu')
        ->and(PoolRegistry::sectionKey('menus'))->toBe('pool:menus')
        ->and(PoolRegistry::poolForSectionKey('pool:menus'))->toBe('menus');
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `./vendor/bin/pest tests/Feature/Content/MenusPoolTest.php`
Expected: FAIL — `PoolRegistry::kinds('menus')` returns `[]`.

- [ ] **Step 3: Add the four entries**

In `POOLS`, after `'reviews' => ['review'],`:

```php
        'menus' => ['menu_item'],
```

In `PAGE_KEYS`:

```php
        'menus' => 'menu',
```

In `PAGE_LABELS`:

```php
        'menus' => 'Menu',
```

In `SECTION_SHAPE`, beside the services/shop pair:

```php
        // Priced, undated — the same shape services (3a) and shop (5a)
        // reconciled on, deliberately identical to both. A 156-dish menu under
        // latest_per_auto_source would publish ONE dish. order_by governs the
        // unpinned tail only; the owner's arrangement rides on pins, seeded
        // once by content:provision-menu-pins.
        'menus' => [
            'rule' => [['op' => 'kind_is']],
            'order_by' => 'recency',
        ],
```

Update the class docblock's opening line — it currently says "Menu is NOT here: it keeps its existing live lane". Replace that sentence with:

```
 * Menus JOINED 2026-08-15 (slice 4): dishes render from the pool, and the
 * legacy site.menu_items lane runs beside it until slice 7 drops the table.
```

- [ ] **Step 4: Run the tests**

Run: `./vendor/bin/pest tests/Feature/Content/MenusPoolTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Re-run the registry's own suite**

Run: `./vendor/bin/pest tests/Feature/Content/PoolRegistryTest.php tests/Feature/Content/PoolWireShapeTest.php`
Expected: PASS. `PoolRegistryTest` pins "a kind belongs to at most ONE pool" and enumerates the poolless kinds — if it lists `menu_item` as deliberately poolless, that assertion is now wrong and gets updated with a one-line reason, not deleted.

- [ ] **Step 6: Commit**

```bash
git add app/Site/Pools/PoolRegistry.php tests/Feature/Content/MenusPoolTest.php
git commit -m "feat(content): the menu_item kind gets a pool"
```

---

## Task 2: `MenuProjectionMapper` — the legacy row → projection mapping

**Files:**
- Create: `app/Services/Platforms/MenuProjectionMapper.php`
- Test: `tests/Unit/Platforms/MenuProjectionMapperTest.php`

**Interfaces:**
- Consumes: `NormalizesMenuItemNames` (existing trait, `normalizeName(string): string`).
- Produces:
  - `MenuProjectionMapper::coordFor(string $menuId, string $name): string` → `manual:menu:{menuId}:{sha1(normalised name)}`
  - `MenuProjectionMapper::project(object $dish, array $categories, array $platformRows, object $menu): array` → the projection array `ProjectionWriter::writeManualItem()` accepts.

`$categories` is a list of `['id' => uuid, 'name' => string, 'position' => int]`. `$platformRows` is a list of `site.menu_item_platforms` rows. `$menu` carries at least `id` and `currency`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\Platforms\MenuProjectionMapper;

function dish(array $overrides = []): object
{
    return (object) array_merge([
        'id' => '33008fc0-84f6-4e74-bf75-e6b63cc8ca63',
        'name' => 'Iced Latte',
        'description' => 'Cold and strong',
        'image_url' => 'https://cdn.example/latte.jpg',
        'images' => json_encode(['https://cdn.example/latte.jpg', 'https://cdn.example/latte-2.jpg']),
        'rating' => 4.7,
        'rating_count' => 31,
        'badges' => json_encode(['Popular', 'Vegetarian']),
        'base_price' => 5.50,
        'pickup_price' => 5.00,
        'pickup_source' => 'uber_eats',
        'delivery_price' => 6.50,
        'delivery_source' => 'doordash',
        'currency' => 'AUD',
        'dd_external_id' => 'dd-9911',
        'is_manual' => false,
    ], $overrides);
}

$menu = (object) ['id' => '019f649c-32aa-714e-9ad7-1dbb1a620cb5', 'currency' => 'AUD'];

it('derives the coord from the menu and the normalised name, not the row uuid', function () use ($menu) {
    // MenuFetchJob deletes and re-inserts every dish, reusing the uuid via
    // takeReusedId() keyed on the NORMALISED NAME. So the uuid churns on a
    // vendor rename and manual:{uuid} would mint a second item; the name is
    // what the legacy writer itself treats as identity.
    $a = MenuProjectionMapper::coordFor($menu->id, 'Iced Latte');
    $b = MenuProjectionMapper::coordFor($menu->id, '  ICED   LATTE!  ');

    expect($a)->toBe($b)
        ->and($a)->toStartWith("manual:menu:{$menu->id}:")
        ->and($a)->toBe("manual:menu:{$menu->id}:".sha1('iced latte'));
});

it('scopes the coord to the menu so two vendors keep distinct dishes', function () {
    expect(MenuProjectionMapper::coordFor('menu-a', 'Garlic Bread'))
        ->not->toBe(MenuProjectionMapper::coordFor('menu-b', 'Garlic Bread'));
});

it('maps the aggregate prices to base/pickup/delivery offers', function () use ($menu) {
    $p = (new MenuProjectionMapper)->project(dish(), [], [], $menu);

    expect(collect($p['offers'])->firstWhere('channel', 'base'))
        ->toMatchArray(['amount_minor' => 550, 'currency' => 'AUD', 'qualifier' => 'exact'])
        ->and(collect($p['offers'])->firstWhere('channel', 'pickup'))
        ->toMatchArray(['amount_minor' => 500, 'qualifier' => 'exact'])
        ->and(collect($p['offers'])->firstWhere('channel', 'delivery'))
        ->toMatchArray(['amount_minor' => 650, 'qualifier' => 'exact']);
});

it('falls back to the menu currency, and leaves it null when the menu has none', function () {
    // 93 of 318 live rows carry a NULL currency. A menu is one vendor in one
    // country, so the menu's own currency is the honest default — not a
    // hardcoded AUD.
    $withMenu = (new MenuProjectionMapper)->project(
        dish(['currency' => null]), [], [], (object) ['id' => 'm', 'currency' => 'AUD'],
    );
    expect(collect($withMenu['offers'])->firstWhere('channel', 'base')['currency'])->toBe('AUD');

    $without = (new MenuProjectionMapper)->project(
        dish(['currency' => null]), [], [], (object) ['id' => 'm', 'currency' => null],
    );
    expect(collect($without['offers'])->firstWhere('channel', 'base')['currency'])->toBeNull();
});

it('prices a zero-cost dish as free, staying inside offers_qualifier_check', function () use ($menu) {
    $p = (new MenuProjectionMapper)->project(dish(['base_price' => 0]), [], [], $menu);

    expect(collect($p['offers'])->firstWhere('channel', 'base')['qualifier'])->toBe('free');
});

it('emits one offer per platform per priced mode, carrying the order url', function () use ($menu) {
    $platforms = [
        (object) ['platform' => 'uber_eats', 'pickup_price' => 5.00, 'pickup_url' => 'https://ue/x', 'delivery_price' => 6.00, 'delivery_url' => 'https://ue/y'],
        (object) ['platform' => 'doordash', 'pickup_price' => null, 'pickup_url' => null, 'delivery_price' => 6.50, 'delivery_url' => 'https://dd/z'],
    ];

    $p = (new MenuProjectionMapper)->project(dish(), [], $platforms, $menu);
    $perPlatform = collect($p['offers'])->whereNotNull('url');

    // Two platforms selling the same dish at different prices are BOTH true —
    // content.offers is a set and is never resolved to a winner.
    expect($perPlatform)->toHaveCount(3)
        ->and($perPlatform->where('channel', 'delivery')->pluck('amount_minor')->sort()->values()->all())
        ->toBe([600, 650]);
});

it('emits collections from the categories, because the identity key reads them', function () use ($menu) {
    // offering_name_in_category is norm(category)|norm(dish), minLength 5 —
    // it is what makes "Fries" and "Cola" mergeable at all, and it is derived
    // from THIS key. An empty label silently stops short dishes merging.
    $p = (new MenuProjectionMapper)->project(dish(), [
        ['id' => 'cat-1', 'name' => 'Drinks', 'position' => 2],
        ['id' => 'cat-2', 'name' => 'Coffee', 'position' => 5],
    ], [], $menu);

    expect($p['collections'])->toBe([
        ['kind' => 'menu_category', 'external_ref' => 'menu:cat-1', 'label' => 'Drinks', 'position' => 2],
        ['kind' => 'menu_category', 'external_ref' => 'menu:cat-2', 'label' => 'Coffee', 'position' => 5],
    ]);
});

it('drops a category with a blank label rather than keying identity off nothing', function () use ($menu) {
    $p = (new MenuProjectionMapper)->project(dish(), [
        ['id' => 'cat-1', 'name' => '   ', 'position' => 0],
    ], [], $menu);

    expect($p['collections'])->toBe([]);
});

it('maps description, badges, rating and the hero-first image set', function () use ($menu) {
    $p = (new MenuProjectionMapper)->project(dish(), [], [], $menu);

    expect($p['kind'])->toBe('menu_item')
        ->and($p['headline'])->toBe('Iced Latte')
        ->and($p['facets']['f_text']['body'])->toBe('Cold and strong')
        ->and($p['facets']['f_rated'])->toMatchArray(['rating' => 4.7, 'ratings_count' => 31])
        ->and($p['tags'])->toBe([
            ['tag' => 'Popular', 'tag_type' => 'badge'],
            ['tag' => 'Vegetarian', 'tag_type' => 'badge'],
        ])
        ->and($p['media'][0])->toMatchArray(['role' => 'cover', 'url' => 'https://cdn.example/latte.jpg'])
        ->and($p['media'])->toHaveCount(2);
});

it('does not duplicate image_url when it is already the hero of images', function () use ($menu) {
    $p = (new MenuProjectionMapper)->project(dish(), [], [], $menu);

    expect(collect($p['media'])->pluck('url')->all())
        ->toBe(['https://cdn.example/latte.jpg', 'https://cdn.example/latte-2.jpg']);
});

it('omits absent facets rather than writing nulls', function () use ($menu) {
    $bare = dish([
        'description' => null, 'rating' => null, 'rating_count' => null,
        'badges' => null, 'images' => null, 'image_url' => null,
    ]);

    $p = (new MenuProjectionMapper)->project($bare, [], [], $menu);

    expect($p['facets'])->toBe([])
        ->and($p['tags'])->toBe([])
        ->and($p['media'])->toBe([]);
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `./vendor/bin/pest tests/Unit/Platforms/MenuProjectionMapperTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the mapper**

```php
<?php

namespace App\Services\Platforms;

/**
 * One legacy site.menu_items row → the projection shape ProjectionWriter
 * accepts. Pure: no database, no side effects, so the whole mapping table in
 * slice 4's spec §4/§5 is unit-testable without a schema.
 *
 * The coord is NOT manual:{legacy_uuid}, and that is deliberate. Parent §8.1
 * prescribes the uuid only "where the legacy id is stable"; MenuFetchJob
 * deletes and re-inserts every dish each scrape, reusing the old uuid through
 * takeReusedId() keyed on the NORMALISED NAME. So the uuid survives an
 * unchanged dish and churns when the vendor renames one — a uuid coord would
 * mint a second content item on every rename and strand the first. The
 * normalised name IS the legacy writer's own identity key, and it matches
 * MenuRecords::flatten()'s sha1(category|name) fallback that the real
 * connectors emit, so the backfilled row and the scraped row describe identity
 * the same way.
 */
class MenuProjectionMapper
{
    use NormalizesMenuItemNames;

    /** Menu-scoped so two vendors' "Garlic Bread" stay distinct items. */
    public static function coordFor(string $menuId, string $name): string
    {
        return 'manual:menu:'.$menuId.':'.sha1((new self)->normalizeName($name));
    }

    /**
     * @param  list<array{id: string, name: string, position: int}>  $categories
     * @param  list<object>  $platformRows  site.menu_item_platforms rows
     * @return array<string, mixed>
     */
    public function project(object $dish, array $categories, array $platformRows, object $menu): array
    {
        // A menu is one vendor in one country, so its currency is the honest
        // default for the 93 dishes that carry none. Null stays null — the
        // column permits it and inventing AUD would be a guess.
        $currency = $this->text($dish->currency ?? null) ?? $this->text($menu->currency ?? null);

        return [
            'kind' => 'menu_item',
            'headline' => (string) $dish->name,
            'facets' => array_filter([
                'f_text' => $this->text($dish->description ?? null) === null
                    ? null
                    : ['body' => $this->text($dish->description)],
                'f_rated' => $dish->rating === null && $dish->rating_count === null ? null : array_filter([
                    'rating' => $dish->rating === null ? null : (float) $dish->rating,
                    'ratings_count' => $dish->rating_count === null ? null : (int) $dish->rating_count,
                ], static fn ($v) => $v !== null),
            ], static fn ($v) => $v !== null),
            'offers' => $this->offers($dish, $platformRows, $currency),
            'tags' => $this->badges($dish),
            'media' => $this->media($dish),
            'collections' => $this->collections($categories),
        ];
    }

    /**
     * The aggregate three (base / pickup / delivery from the merged row) plus
     * one per platform per priced mode. content.offers is a SET and is never
     * resolved to a winner — two platforms at different prices are both true,
     * and hiding one would be a lie about where the visitor can buy.
     *
     * @param  list<object>  $platformRows
     * @return list<array<string, mixed>>
     */
    private function offers(object $dish, array $platformRows, ?string $currency): array
    {
        $offers = [];

        foreach (['base' => 'base_price', 'pickup' => 'pickup_price', 'delivery' => 'delivery_price'] as $channel => $column) {
            $amount = $dish->$column ?? null;
            if ($amount === null) {
                continue;
            }
            $offers[] = $this->offer($channel, (float) $amount, $currency, null);
        }

        foreach ($platformRows as $row) {
            foreach (['pickup' => ['pickup_price', 'pickup_url'], 'delivery' => ['delivery_price', 'delivery_url']] as $channel => [$priceCol, $urlCol]) {
                $amount = $row->$priceCol ?? null;
                if ($amount === null) {
                    continue;
                }
                $offers[] = $this->offer($channel, (float) $amount, $currency, $this->text($row->$urlCol ?? null));
            }
        }

        return $offers;
    }

    /** @return array<string, mixed> */
    private function offer(string $channel, float $amount, ?string $currency, ?string $url): array
    {
        return [
            'channel' => $channel,
            'amount_minor' => (int) round($amount * 100),
            'currency' => $currency,
            // offers_qualifier_check: exact|from|upto|range|free|variable|on_request.
            'qualifier' => $amount === 0.0 ? 'free' : 'exact',
            'url' => $url,
            // Menus carry no stock signal, so availability stays NULL rather
            // than minting a second spelling beside slice 5a's in_stock pair.
            'availability' => null,
        ];
    }

    /**
     * external_ref is the natural key (collections_user_kind_external_ref_uq);
     * a label is never a key, because vendors rename. A blank label is dropped
     * rather than written: offering_name_in_category is derived from these
     * entries, and an empty one silently stops short dish names merging.
     *
     * @param  list<array{id: string, name: string, position: int}>  $categories
     * @return list<array<string, mixed>>
     */
    private function collections(array $categories): array
    {
        $out = [];
        foreach ($categories as $category) {
            $label = trim((string) ($category['name'] ?? ''));
            if ($label === '') {
                continue;
            }
            $out[] = [
                'kind' => 'menu_category',
                'external_ref' => 'menu:'.$category['id'],
                'label' => $label,
                'position' => (int) ($category['position'] ?? 0),
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function badges(object $dish): array
    {
        $out = [];
        foreach ($this->jsonList($dish->badges ?? null) as $badge) {
            $tag = trim((string) $badge);
            if ($tag !== '') {
                $out[] = ['tag' => $tag, 'tag_type' => 'badge'];
            }
        }

        return $out;
    }

    /** Hero first, then the rest — image_url is the hero and is never duplicated. @return list<array<string, mixed>> */
    private function media(object $dish): array
    {
        $urls = [];
        $hero = $this->text($dish->image_url ?? null);
        if ($hero !== null) {
            $urls[] = $hero;
        }
        foreach ($this->jsonList($dish->images ?? null) as $url) {
            $url = $this->text($url);
            if ($url !== null && ! in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        $out = [];
        foreach (array_values($urls) as $position => $url) {
            $out[] = ['role' => $position === 0 ? 'cover' : 'gallery', 'url' => $url, 'position' => $position];
        }

        return $out;
    }

    /** @return list<mixed> */
    private function jsonList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return trim($value) === '' ? null : trim($value);
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `./vendor/bin/pest tests/Unit/Platforms/MenuProjectionMapperTest.php`
Expected: PASS (11 tests).

- [ ] **Step 5: Check the trait's visibility**

`NormalizesMenuItemNames::normalizeName()` is `private`. Using it from a `static` method needs `(new self)->normalizeName(...)` — that is what `coordFor()` does. Run:

Run: `php -d memory_limit=1G ./vendor/bin/phpstan analyse app/Services/Platforms/MenuProjectionMapper.php --no-progress --debug`
Expected: no errors. If it reports the trait method is not visible, promote it to `protected` in the trait (it has three consumers: `MenuMerger`, `MenuFetchJob`, this) and re-run every one of their test files.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Platforms/MenuProjectionMapper.php tests/Unit/Platforms/MenuProjectionMapperTest.php
git commit -m "feat(menus): map a legacy dish onto the projection shape"
```

---

## Task 3: `MenuBackfiller` — the items, through the manual lane

**Files:**
- Create: `app/Services/Migration/MenuBackfiller.php`
- Create: `app/Console/Commands/BackfillMenus.php`
- Test: `tests/Feature/Content/MenuBackfillerTest.php`

**Interfaces:**
- Consumes: `MenuProjectionMapper::project()/coordFor()`; `ProjectionWriter::writeManualItem(string $userId, string $coord, array $projection): string`; `SiteCacheLanes::bust(array $siteIds): void`.
- Produces: `MenuBackfiller::run(bool $dryRun = false, ?string $userId = null): array` returning `['backfilled' => int, 'skipped_no_site' => int, 'skipped_no_name' => int, 'already_curated' => int, 'failed' => int]`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\Migration\MenuBackfiller;
use App\Services\Platforms\MenuProjectionMapper;
use Illuminate\Support\Facades\DB;

it('lands one content item per dish, keyed on the name-derived coord', function () {
    [$user, $site, $menu] = seedMenuWithDishes(['Iced Latte', 'Flat White']);

    $result = app(MenuBackfiller::class)->run();

    expect($result['backfilled'])->toBe(2)
        ->and($result['failed'])->toBe(0);

    $coords = DB::connection('pgsql')->table('content.source_items')
        ->where('kind', 'menu_item')->pluck('coord')->all();

    expect($coords)->toContain(MenuProjectionMapper::coordFor($menu->id, 'Iced Latte'))
        ->and($coords)->toContain(MenuProjectionMapper::coordFor($menu->id, 'Flat White'));
});

it('is idempotent — a second run updates and mints nothing new', function () {
    seedMenuWithDishes(['Iced Latte']);

    app(MenuBackfiller::class)->run();
    $after = DB::connection('pgsql')->table('content.items')->where('kind', 'menu_item')->count();

    app(MenuBackfiller::class)->run();

    expect(DB::connection('pgsql')->table('content.items')->where('kind', 'menu_item')->count())
        ->toBe($after);
});

it('reports without writing under --dry-run', function () {
    seedMenuWithDishes(['Iced Latte']);

    $result = app(MenuBackfiller::class)->run(dryRun: true);

    expect($result['backfilled'])->toBe(1)
        ->and(DB::connection('pgsql')->table('content.items')->where('kind', 'menu_item')->count())->toBe(0);
});

it('counts a dish whose owner has no site rather than dropping it silently', function () {
    // §8.2: each backfiller derives user_id through the site explicitly and
    // must fail loudly on a site with no owner. A silently skipped dish is one
    // that vanishes from the coverage count without anyone deciding to drop it.
    [$user, $site, $menu] = seedMenuWithDishes(['Iced Latte']);
    DB::connection('pgsql')->table('site.sites')->where('id', $site->id)->delete();

    expect(app(MenuBackfiller::class)->run()['skipped_no_site'])->toBe(1);
});

it('folds two dishes with the same normalised name onto one coord', function () {
    // The legacy table permits "Iced Latte" and "ICED  LATTE" as two rows;
    // both collapse to one coord, so the run must not write the same coord
    // twice and count it as two, or the coverage gate is unreconcilable.
    [$user, $site, $menu] = seedMenuWithDishes(['Iced Latte', 'ICED  LATTE']);

    $result = app(MenuBackfiller::class)->run();

    expect($result['backfilled'] + $result['duplicate_name'])->toBe(2)
        ->and($result['duplicate_name'])->toBe(1)
        ->and(DB::connection('pgsql')->table('content.items')->where('kind', 'menu_item')->count())->toBe(1);
});

it('writes the category memberships as collections', function () {
    [$user, $site, $menu] = seedMenuWithDishes(['Iced Latte'], categories: ['Drinks', 'Coffee']);

    app(MenuBackfiller::class)->run();

    expect(DB::connection('pgsql')->table('content.collections')->where('kind', 'menu_category')->count())->toBe(2)
        ->and(DB::connection('pgsql')->table('content.collection_items')->count())->toBe(2);
});
```

Add the fixture helper to `tests/Pest.php` — **not** to this file. A helper defined in one test file and used by another is a fatal under `--parallel` (guarded by `tests/Feature/Architecture/CrossFileTestHelperTest.php`):

```php
/**
 * A user + site + menu + categories + dishes, wired the way MenuFetchJob
 * writes them: every dish holds a membership in every category, and a
 * menu_item_platforms row per platform.
 *
 * @param  list<string>  $names
 * @param  list<string>  $categories
 * @return array{0: object, 1: object, 2: object}
 */
function seedMenuWithDishes(array $names, array $categories = ['Menu'], array $platforms = ['uber_eats']): array
{
    $user = seedUserWithSite();
    $site = DB::connection('pgsql')->table('site.sites')->where('user_id', $user->id)->first();

    $menuId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.menus')->insert([
        'id' => $menuId, 'user_id' => $user->id, 'content_source' => 'uber-eats',
        'currency' => 'AUD', 'fetch_status' => 'ok',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $categoryIds = [];
    foreach ($categories as $position => $name) {
        $categoryIds[$name] = (string) Str::uuid();
        DB::connection('pgsql')->table('site.menu_categories')->insert([
            'id' => $categoryIds[$name], 'menu_id' => $menuId, 'name' => $name,
            'position' => $position, 'source_platform' => 'uber-eats',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    foreach ($names as $name) {
        $dishId = (string) Str::uuid();
        DB::connection('pgsql')->table('site.menu_items')->insert([
            'id' => $dishId, 'menu_id' => $menuId, 'name' => $name,
            'base_price' => 5.5, 'currency' => 'AUD', 'is_manual' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($categoryIds as $categoryId) {
            DB::connection('pgsql')->table('site.menu_item_categories')->insert([
                'menu_item_id' => $dishId, 'menu_category_id' => $categoryId,
                'position' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach ($platforms as $platform) {
            DB::connection('pgsql')->table('site.menu_item_platforms')->insert([
                'id' => (string) Str::uuid(), 'menu_item_id' => $dishId, 'platform' => $platform,
                'delivery_price' => 6.5, 'delivery_url' => 'https://example/'.$platform,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    return [$user, $site, (object) ['id' => $menuId, 'currency' => 'AUD']];
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `./vendor/bin/pest tests/Feature/Content/MenuBackfillerTest.php`
Expected: FAIL — `MenuBackfiller` not found.

- [ ] **Step 3: Write the backfiller**

```php
<?php

namespace App\Services\Migration;

use App\Ingest\Projection\ProjectionWriter;
use App\Services\Platforms\MenuProjectionMapper;
use App\Site\Documents\SiteCacheLanes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Slice 4: land every live site.menu_items row as a menu_item content item,
 * through the slice-0b manual lane — never a raw write into content.*.
 *
 * Idempotent on the coord (spec §9.1), which is menu-scoped and derived from
 * the normalised dish name rather than the row uuid: MenuFetchJob deletes and
 * re-inserts dishes each scrape and reuses uuids by name, so a uuid coord
 * would mint a second item on every vendor rename.
 *
 * The duplicate-name counter is live defence, not dead code. The legacy table
 * has no uniqueness on (menu_id, name) — "Iced Latte" and "ICED  LATTE" are
 * two permitted rows that collapse to ONE coord. Without the dedupe the run
 * would write that coord twice and count two, making the coverage gate
 * unreconcilable.
 */
class MenuBackfiller
{
    public function __construct(
        private readonly ProjectionWriter $writer,
        private readonly MenuProjectionMapper $mapper,
    ) {}

    /** @return array{backfilled:int, duplicate_name:int, skipped_no_site:int, skipped_no_name:int, failed:int} */
    public function run(bool $dryRun = false, ?string $userId = null): array
    {
        $result = ['backfilled' => 0, 'duplicate_name' => 0, 'skipped_no_site' => 0, 'skipped_no_name' => 0, 'failed' => 0];
        $touchedSites = [];

        $menus = DB::connection('pgsql')->table('site.menus')
            ->whereNull('deleted_at')
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->orderBy('id')
            ->get();

        foreach ($menus as $menu) {
            $owner = (string) $menu->user_id;

            // §8.2: derive user_id through the site explicitly and fail loudly
            // on a site with no owner. site.sites has no deleted_at — sites die
            // by cascade, not soft delete.
            $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $owner)->value('id');

            $categoriesByDish = $this->categoriesByDish((string) $menu->id);
            $platformsByDish = $this->platformsByDish((string) $menu->id);

            $seen = [];

            $dishes = DB::connection('pgsql')->table('site.menu_items')
                ->where('menu_id', $menu->id)
                ->orderBy('created_at')->orderBy('id')
                ->get();

            foreach ($dishes as $dish) {
                try {
                    $name = trim((string) $dish->name);
                    if ($name === '') {
                        $result['skipped_no_name']++;

                        continue;
                    }

                    $coord = MenuProjectionMapper::coordFor((string) $menu->id, $name);
                    if (isset($seen[$coord])) {
                        $result['duplicate_name']++;

                        continue;
                    }
                    $seen[$coord] = true;

                    if ($siteId === null) {
                        $result['skipped_no_site']++;

                        continue;
                    }

                    if ($dryRun) {
                        $result['backfilled']++;

                        continue;
                    }

                    $this->writer->writeManualItem($owner, $coord, $this->mapper->project(
                        $dish,
                        $categoriesByDish[(string) $dish->id] ?? [],
                        $platformsByDish[(string) $dish->id] ?? [],
                        $menu,
                    ));

                    $touchedSites[(string) $siteId] = true;
                    $result['backfilled']++;
                } catch (\Throwable $e) {
                    report($e);
                    Log::warning('Menu backfill failed for one dish.', [
                        'menu_item' => $dish->id, 'error' => $e->getMessage(),
                    ]);
                    $result['failed']++;
                }
            }
        }

        if (! $dryRun && $touchedSites !== []) {
            // All three lanes (§9.2): writeManualItem() bumps build state per
            // item, but the 60s payload cache keys off site.sites.updated_at
            // and the edge outlives the origin write.
            SiteCacheLanes::bust(array_keys($touchedSites));
        }

        return $result;
    }

    /** @return array<string, list<array{id: string, name: string, position: int}>> */
    private function categoriesByDish(string $menuId): array
    {
        $rows = DB::connection('pgsql')->table('site.menu_item_categories as mic')
            ->join('site.menu_categories as mc', 'mc.id', '=', 'mic.menu_category_id')
            ->where('mc.menu_id', $menuId)
            ->orderBy('mc.position')
            ->get(['mic.menu_item_id', 'mc.id', 'mc.name', 'mc.position']);

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->menu_item_id][] = [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'position' => (int) $row->position,
            ];
        }

        return $out;
    }

    /** @return array<string, list<object>> */
    private function platformsByDish(string $menuId): array
    {
        $rows = DB::connection('pgsql')->table('site.menu_item_platforms as mip')
            ->join('site.menu_items as mi', 'mi.id', '=', 'mip.menu_item_id')
            ->where('mi.menu_id', $menuId)
            ->get(['mip.menu_item_id', 'mip.platform', 'mip.pickup_price', 'mip.pickup_url', 'mip.delivery_price', 'mip.delivery_url']);

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->menu_item_id][] = $row;
        }

        return $out;
    }
}
```

- [ ] **Step 4: Write the command**

```php
<?php

namespace App\Console\Commands;

use App\Services\Migration\MenuBackfiller;
use Illuminate\Console\Command;

class BackfillMenus extends Command
{
    protected $signature = 'content:backfill-menus
        {--dry-run : Report what would be written without writing}
        {--user= : Only this user id}';

    protected $description = 'Backfill site.menu_items into content.* as menu_item items';

    public function handle(MenuBackfiller $backfiller): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $backfiller->run($dryRun, $this->option('user'));

        // duplicate_name is the re-run signal alongside backfilled: on a second
        // pass every dish is written again (the coord is stable) but nothing
        // new is minted, so identical output across two runs is what healthy
        // looks like. Printing every counter is what makes that legible.
        $this->line(($dryRun ? '[dry-run] would backfill ' : 'backfilled ').$result['backfilled']
            .', duplicate name '.$result['duplicate_name']
            .', skipped (no site) '.$result['skipped_no_site']
            .', skipped (no name) '.$result['skipped_no_name']
            .', failed '.$result['failed']);

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
```

- [ ] **Step 5: Run the tests**

Run: `./vendor/bin/pest tests/Feature/Content/MenuBackfillerTest.php`
Expected: PASS (6 tests).

- [ ] **Step 6: Run the Postgres lane**

`writeManualItem()` is `ProjectionWriter`, so SQLite proves nothing about it.

Run: `composer test:pg`
Expected: PASS. If the lane will not start locally, use the `postgres:16` container recipe in `reference_pg_lane_local_invocation`; a silent skip reads green and tests nothing.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Migration/MenuBackfiller.php app/Console/Commands/BackfillMenus.php tests/Feature/Content/MenuBackfillerTest.php tests/Pest.php
git commit -m "feat(migration): MenuBackfiller — dishes through the manual lane"
```

---

## Task 4: The slug lane

**Files:**
- Modify: `app/Services/Content/ContentItemSlugAllocator.php` (`SLUGGED_KINDS` + docblock)
- Modify: `app/Services/Migration/MenuBackfiller.php` (a second phase)
- Test: `tests/Feature/Content/MenuSlugLaneTest.php`

**Interfaces:**
- Consumes: `ContentItemSlugAllocator::ensureCurrent(string $userId, string $itemId, string $name): string`.
- Produces: `MenuBackfiller::migrateSlugs(bool $dryRun, ?string $userId): array` returning `['migrated' => int, 'collided' => int, 'unmapped' => int]`, called from `run()` after the item phase.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\Content\ContentItemSlugAllocator;
use App\Services\Migration\MenuBackfiller;
use App\Services\Platforms\MenuProjectionMapper;
use Illuminate\Support\Facades\DB;

it('mints a slug for a landed dish without anyone calling the allocator', function () {
    // ProjectionWriter::refreshItemCaches() mints for every kind in
    // SLUGGED_KINDS. Widening the const IS the re-homing off MenuItemObserver.
    [$user, $site, $menu] = seedMenuWithDishes(['Iced Latte']);

    app(MenuBackfiller::class)->run();

    $itemId = DB::connection('pgsql')->table('content.items')->where('kind', 'menu_item')->value('id');

    expect(DB::connection('pgsql')->table('content.item_slugs')
        ->where('item_id', $itemId)->where('is_current', true)->value('slug'))
        ->toBe('iced-latte');
});

it('carries the legacy slug, its retired history and its created_at', function () {
    [$user, $site, $menu] = seedMenuWithDishes(['Iced Latte']);
    $dishId = DB::connection('pgsql')->table('site.menu_items')->value('id');

    // The live slug plus a retired one the owner renamed away from.
    DB::connection('pgsql')->table('site.item_slugs')->insert([
        ['id' => (string) Str::uuid(), 'user_id' => $user->id, 'item_type' => 'menu_item',
         'item_key' => $dishId, 'slug' => 'iced-latte', 'is_current' => true,
         'created_at' => now()->subDays(30), 'retired_at' => null],
        ['id' => (string) Str::uuid(), 'user_id' => $user->id, 'item_type' => 'menu_item',
         'item_key' => $dishId, 'slug' => 'cold-latte', 'is_current' => false,
         'created_at' => now()->subDays(90), 'retired_at' => now()->subDays(30)],
    ]);

    app(MenuBackfiller::class)->run();

    $itemId = DB::connection('pgsql')->table('content.items')->where('kind', 'menu_item')->value('id');
    $rows = DB::connection('pgsql')->table('content.item_slugs')->where('item_id', $itemId)->get()->keyBy('slug');

    expect($rows)->toHaveCount(2)
        ->and((bool) $rows['iced-latte']->is_current)->toBeTrue()
        ->and((bool) $rows['cold-latte']->is_current)->toBeFalse()
        ->and($rows['cold-latte']->retired_at)->not->toBeNull();
});

it('301s a renamed dish through the retired slug', function () {
    [$user, $site, $menu] = seedMenuWithDishes(['Iced Latte']);
    app(MenuBackfiller::class)->run();

    $itemId = DB::connection('pgsql')->table('content.items')->where('kind', 'menu_item')->value('id');
    app(ContentItemSlugAllocator::class)->ensureCurrent($user->id, $itemId, 'Cold Brew');

    $lookup = app(ContentItemSlugAllocator::class)->lookupCurrent($user->id, [$itemId]);

    expect($lookup[$itemId]['slug'])->toBe('cold-brew')
        ->and($lookup[$itemId]['aliases'])->toContain('iced-latte');
});

it('refuses to hand one user\'s slug to a different item', function () {
    // Both tables enforce UNIQUE (user_id, slug), non-partial. A collision is
    // a hard failure that aborts the run, never a silently skipped permalink.
    [$user, $site, $menu] = seedMenuWithDishes(['Iced Latte']);
    $dishId = DB::connection('pgsql')->table('site.menu_items')->value('id');

    $otherItem = (string) Str::uuid();
    DB::connection('pgsql')->table('content.items')->insert([
        'id' => $otherItem, 'user_id' => $user->id, 'kind' => 'event',
        'created_at' => now(), 'updated_at' => now(), 'last_seen_at' => now(),
    ]);
    DB::connection('pgsql')->table('content.item_slugs')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $user->id, 'item_id' => $otherItem,
        'slug' => 'iced-latte', 'is_current' => true, 'created_at' => now(),
    ]);
    DB::connection('pgsql')->table('site.item_slugs')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $user->id, 'item_type' => 'menu_item',
        'item_key' => $dishId, 'slug' => 'iced-latte', 'is_current' => true, 'created_at' => now(),
    ]);

    expect(app(MenuBackfiller::class)->run()['collided'])->toBe(1);
});

it('frees the slug when a dish is genuinely removed, not merely retired', function () {
    // items.removed_at is the owner-delete marker. source_items.removed_at is
    // cleared on reappearance and would resurrect a deleted dish.
    [$user, $site, $menu] = seedMenuWithDishes(['Iced Latte']);
    app(MenuBackfiller::class)->run();

    $itemId = DB::connection('pgsql')->table('content.items')->where('kind', 'menu_item')->value('id');
    app(ContentItemSlugAllocator::class)->forget($user->id, $itemId);

    expect(DB::connection('pgsql')->table('content.item_slugs')->where('item_id', $itemId)->count())->toBe(0);
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `./vendor/bin/pest tests/Feature/Content/MenuSlugLaneTest.php`
Expected: FAIL — the first test finds no slug row, because `SLUGGED_KINDS` is `['event']`.

- [ ] **Step 3: Widen the const**

```php
    public const SLUGGED_KINDS = ['event', 'menu_item'];
```

and replace the docblock's second paragraph:

```
     * Events and menu items — exactly the pair site.item_slugs covered. Menus
     * joined 2026-08-15 (slice 4), which is what re-homes slug allocation off
     * MenuItemObserver: ProjectionWriter::refreshItemCaches() mints for every
     * kind in this list, so a landed dish gets its permalink with no observer
     * and no second call site. Every pool item's payload carries the `slug`
     * key regardless, null off this list — the wire shape does not vary by
     * kind.
```

Also correct `ItemSlugAllocator`'s stale docblock line ("same table shape, no allocator today") to point at `ContentItemSlugAllocator`.

- [ ] **Step 4: Add the slug phase to the backfiller**

```php
    /**
     * Phase 2: carry site.item_slugs' menu_item rows onto the content items
     * their dishes became. Runs AFTER the item phase in the same command,
     * because the mapping is legacy uuid → coord → content item id and the
     * middle term does not exist until the items land.
     *
     * is_current, retired_at and created_at are preserved verbatim: created_at
     * is what lookupCurrent() orders stranded rows by, and retired_at is the
     * 301. A (user_id, slug) already held by a DIFFERENT item is a hard
     * failure — the unique index is non-partial, so the alternative is an
     * exception mid-run with half the permalinks moved.
     *
     * @return array{migrated:int, collided:int, unmapped:int}
     */
    private function migrateSlugs(bool $dryRun, ?string $userId): array
    {
        $out = ['migrated' => 0, 'collided' => 0, 'unmapped' => 0];

        $legacy = DB::connection('pgsql')->table('site.item_slugs')
            ->where('item_type', 'menu_item')
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->orderBy('created_at')
            ->get();

        foreach ($legacy as $row) {
            $itemId = $this->contentItemForLegacyDish((string) $row->item_key);
            if ($itemId === null) {
                $out['unmapped']++;

                continue;
            }

            $holder = DB::connection('pgsql')->table('content.item_slugs')
                ->where('user_id', $row->user_id)->where('slug', $row->slug)
                ->value('item_id');

            if ($holder !== null && (string) $holder !== $itemId) {
                $out['collided']++;

                continue;
            }
            if ($holder !== null) {
                $out['migrated']++;

                continue;
            }

            if ($dryRun) {
                $out['migrated']++;

                continue;
            }

            DB::connection('pgsql')->table('content.item_slugs')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'user_id' => $row->user_id,
                'item_id' => $itemId,
                'slug' => $row->slug,
                'is_current' => (bool) $row->is_current,
                'created_at' => $row->created_at,
                'retired_at' => $row->retired_at,
            ]);
            $out['migrated']++;
        }

        return $out;
    }

    /** legacy dish uuid → the content item its coord resolved to, or null. */
    private function contentItemForLegacyDish(string $dishId): ?string
    {
        $dish = DB::connection('pgsql')->table('site.menu_items')
            ->where('id', $dishId)->first(['menu_id', 'name']);

        if ($dish === null) {
            return null;
        }

        $coord = MenuProjectionMapper::coordFor((string) $dish->menu_id, (string) $dish->name);

        $id = DB::connection('pgsql')->table('content.source_items as si')
            ->join('content.item_sources as isrc', 'isrc.source_item_id', '=', 'si.id')
            ->where('si.coord', $coord)
            ->value('isrc.item_id');

        return $id === null ? null : (string) $id;
    }
```

Wire it into `run()` immediately before the `SiteCacheLanes::bust()` call, merging its counters into `$result`.

**Verify the join table's real name first.** `content.item_sources` is the assumed link between a source item and its item; confirm with:

```bash
grep -n "item_sources\|source_item_id" app/Ingest/Projection/ProjectionWriter.php | head
```

If the writer resolves items differently (e.g. a column on `content.source_items`), use whatever `resolveItems()` reads and correct this step in place.

- [ ] **Step 5: Run the tests**

Run: `./vendor/bin/pest tests/Feature/Content/MenuSlugLaneTest.php tests/Feature/Content/MenuBackfillerTest.php`
Expected: PASS.

- [ ] **Step 6: Run the whole slug surface**

Run: `./vendor/bin/pest --filter=Slug`
Expected: PASS. `ItemSlugAllocator`'s own suite must stay green — the legacy lane still runs until slice 7.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Content/ContentItemSlugAllocator.php app/Services/Site/ItemSlugAllocator.php app/Services/Migration/MenuBackfiller.php tests/Feature/Content/MenuSlugLaneTest.php
git commit -m "feat(menus): the 301 lane moves to content.item_slugs"
```

---

## Task 5: Removal frees the slug

**Files:**
- Modify: `app/Ingest/Projection/ProjectionWriter.php` (the `removed_at` write path)
- Test: `tests/Feature/Content/MenuSlugLaneTest.php` (extend)

**Interfaces:**
- Consumes: `ContentItemSlugAllocator::forget(string $userId, string $itemId): void` (exists, currently uncalled).
- Produces: nothing new.

- [ ] **Step 1: Find the seam**

```bash
grep -n "removed_at" app/Ingest/Projection/ProjectionWriter.php app/Services/Content/ManualServiceWriter.php
```

`ManualServiceWriter::markRemoved()` is the owner-delete path. The slug must be freed there, not on `source_items.removed_at`, which is cleared on reappearance.

- [ ] **Step 2: Write the failing test**

```php
it('frees the permalink when the owner deletes a dish', function () {
    [$user, $site, $menu] = seedMenuWithDishes(['Iced Latte']);
    app(MenuBackfiller::class)->run();
    $itemId = DB::connection('pgsql')->table('content.items')->where('kind', 'menu_item')->value('id');

    app(App\Services\Content\ManualServiceWriter::class)->markRemoved($itemId);

    expect(DB::connection('pgsql')->table('content.item_slugs')->where('item_id', $itemId)->count())->toBe(0);
});

it('keeps the permalink when a scrape merely stops seeing the dish', function () {
    // source_items.removed_at is projection-level absence and is cleared on
    // reappearance. Freeing the slug there would hand the name to another dish
    // and break the URL the moment the vendor re-lists it.
    [$user, $site, $menu] = seedMenuWithDishes(['Iced Latte']);
    app(MenuBackfiller::class)->run();
    $itemId = DB::connection('pgsql')->table('content.items')->where('kind', 'menu_item')->value('id');

    DB::connection('pgsql')->table('content.source_items')->where('kind', 'menu_item')
        ->update(['removed_at' => now()]);

    expect(DB::connection('pgsql')->table('content.item_slugs')->where('item_id', $itemId)->count())->toBe(1);
});
```

- [ ] **Step 3: Run it and watch it fail**

Run: `./vendor/bin/pest tests/Feature/Content/MenuSlugLaneTest.php`
Expected: FAIL on the first — the slug survives `markRemoved()`.

- [ ] **Step 4: Free the slug on removal**

In `ManualServiceWriter::markRemoved()`, after the `removed_at` update:

```php
        // A removed item's slug must be freed or the name is squatted forever:
        // idx_item_slugs_unique (user_id, slug) is NON-partial, so even a
        // retired row blocks reuse. Only items.removed_at (the owner's delete)
        // reaches here — source_items.removed_at is projection-level absence,
        // cleared on reappearance, and freeing on it would break the URL the
        // moment a vendor re-lists the dish.
        $userId = DB::table('content.items')->where('id', $itemId)->value('user_id');
        if ($userId !== null) {
            $this->slugs->forget((string) $userId, $itemId);
        }
```

injecting `ContentItemSlugAllocator $slugs` into the constructor.

- [ ] **Step 5: Run the tests**

Run: `./vendor/bin/pest tests/Feature/Content/MenuSlugLaneTest.php && composer test:pg`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Content/ManualServiceWriter.php tests/Feature/Content/MenuSlugLaneTest.php
git commit -m "fix(content): a removed item frees its permalink"
```

---

## Task 6: `MenuItemProjector` emits collections

**Files:**
- Modify: `app/Ingest/Projection/MenuItemProjector.php`
- Test: `tests/Feature/Ingest/MenuItemProjectorTest.php` (create if absent)

**Interfaces:**
- Consumes: `RecordView` (`string()`, `float()`).
- Produces: a projection carrying `collections` so `offering_name_in_category` can be derived.

- [ ] **Step 1: Write the failing test**

```php
it('emits the category as a collection, not only as a tag', function () {
    // offering_name_in_category is norm(category)|norm(dish) at minLength 5,
    // derived from the projection's collections entries. Without them, "Fries"
    // and "Cola" fall to bare offering_name (minLength 8) and stop merging
    // across platforms — silently, with no failing test anywhere.
    $view = recordView(['name' => 'Fries', 'category' => 'Sides', 'price' => 4.5, 'currency' => 'AUD']);

    $projection = (new MenuItemProjector)->project($view);

    expect($projection['collections'])->toBe([
        ['kind' => 'menu_category', 'external_ref' => 'menu:sides', 'label' => 'Sides', 'position' => 0],
    ]);
});

it('still emits the category tag the legacy grouping reads', function () {
    $view = recordView(['name' => 'Fries', 'category' => 'Sides']);

    expect((new MenuItemProjector)->project($view)['tags'])
        ->toContain(['tag' => 'Sides', 'tag_type' => 'category']);
});

it('bumps its version, because the projection shape changed', function () {
    expect(MenuItemProjector::version())->toBe(2);
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `./vendor/bin/pest tests/Feature/Ingest/MenuItemProjectorTest.php`
Expected: FAIL — `collections` key absent, version is 1.

- [ ] **Step 3: Emit collections and bump the version**

```php
    public static function version(): int
    {
        // 2: the projection gained `collections` (slice 4). The category was a
        // tag only, and offering_name_in_category derives from collections —
        // so cross-platform merging of short dish names never fired.
        return 2;
    }
```

and in `project()`, alongside `tags`:

```php
            // The category is BOTH a tag (the legacy grouping reads it) and a
            // collection (the identity key and the pool's collections map read
            // it). external_ref is slugified, not the raw label: a vendor
            // rename must follow the label, not mint a second collection.
            'collections' => $view->string('category') === null ? [] : [[
                'kind' => 'menu_category',
                'external_ref' => 'menu:'.Str::slug($view->string('category')),
                'label' => $view->string('category'),
                'position' => (int) ($view->int('position') ?? 0),
            ]],
```

Also add `f_rated` and badge tags if the landed doc carries them — check `MenuRecords::flatten()`'s emitted keys first (`external_id`, `name`, `description`, `price`, `currency`, `image`, `category`, `position`, `store_name`). It carries **neither rating nor badges today**, so do not invent facets for fields the doc has no source for; note it in the wire manifest as a gap the legacy merger filled and the connector does not.

- [ ] **Step 4: Run the tests**

Run: `./vendor/bin/pest tests/Feature/Ingest/MenuItemProjectorTest.php && composer test:pg`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Ingest/Projection/MenuItemProjector.php tests/Feature/Ingest/MenuItemProjectorTest.php
git commit -m "feat(ingest): the menu projector emits categories as collections"
```

---

## Task 7: Ordering platforms become collections with storefront sidecars

**Files:**
- Modify: `app/Services/Migration/MenuBackfiller.php` (a third phase)
- Test: `tests/Feature/Content/MenuBackfillerTest.php` (extend)

**Interfaces:**
- Consumes: `content.collections` (kind `order_platform`), `content.storefronts`.
- Produces: one collection + sidecar per `site.menu_platform_links` row; every dish sold on that platform is a `collection_items` member.

- [ ] **Step 1: Write the failing test**

```php
it('turns each menu platform link into an order_platform collection with a storefront', function () {
    [$user, $site, $menu] = seedMenuWithDishes(['Iced Latte']);
    DB::connection('pgsql')->table('site.menu_platform_links')->insert([
        'id' => (string) Str::uuid(), 'menu_id' => $menu->id, 'platform' => 'uber_eats',
        'store_url' => 'https://ubereats.com/store/x', 'status' => 'ok',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    app(MenuBackfiller::class)->run();

    $collection = DB::connection('pgsql')->table('content.collections')
        ->where('kind', 'order_platform')->first();

    expect($collection)->not->toBeNull()
        ->and($collection->external_ref)->toBe('order:uber_eats')
        ->and(DB::connection('pgsql')->table('content.storefronts')
            ->where('collection_id', $collection->id)->value('url'))
        ->toBe('https://ubereats.com/store/x');
});

it('makes every dish sold on that platform a member of its collection', function () {
    [$user, $site, $menu] = seedMenuWithDishes(['Iced Latte'], platforms: ['uber_eats']);
    DB::connection('pgsql')->table('site.menu_platform_links')->insert([
        'id' => (string) Str::uuid(), 'menu_id' => $menu->id, 'platform' => 'uber_eats',
        'store_url' => 'https://ubereats.com/store/x', 'status' => 'ok',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    app(MenuBackfiller::class)->run();

    $collection = DB::connection('pgsql')->table('content.collections')->where('kind', 'order_platform')->first();

    expect(DB::connection('pgsql')->table('content.collection_items')
        ->where('collection_id', $collection->id)->count())->toBe(1);
});

it('does not overwrite a storefront position the owner has reordered', function () {
    // position is INSERT-ONLY on collections (ProjectionWriter's upsert list
    // omits it deliberately) — a scheduled run must not snap an owner's
    // reorder back. The same rule applies to a re-run of this backfill.
    [$user, $site, $menu] = seedMenuWithDishes(['Iced Latte']);
    DB::connection('pgsql')->table('site.menu_platform_links')->insert([
        'id' => (string) Str::uuid(), 'menu_id' => $menu->id, 'platform' => 'uber_eats',
        'store_url' => 'https://ubereats.com/store/x', 'status' => 'ok',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    app(MenuBackfiller::class)->run();

    DB::connection('pgsql')->table('content.collections')->where('kind', 'order_platform')->update(['position' => 7]);
    app(MenuBackfiller::class)->run();

    expect(DB::connection('pgsql')->table('content.collections')->where('kind', 'order_platform')->value('position'))
        ->toBe(7);
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `./vendor/bin/pest tests/Feature/Content/MenuBackfillerTest.php`
Expected: FAIL — no `order_platform` collection exists.

- [ ] **Step 3: Implement the phase**

Add `migrateOrderPlatforms()` to `MenuBackfiller`, run after the item phase and before the slug phase. Reuse `ProjectionWriter`'s `collections` key by adding an `order_platform` entry to each dish's projection for every platform it carries a `menu_item_platforms` row for — that gets both the collection upsert and the membership for free, and inherits the insert-only `position` rule.

The storefront sidecar has no projection seam, so write it directly, upserting on `collection_id`:

```php
    /**
     * The store-level ordering links (site.menu_platform_links, 5 rows on dev)
     * become content.storefronts sidecars on the order_platform collections
     * the dish projections already created. Owner ruling 2026-08-15: reuse 5b's
     * store-card shape rather than inventing a second grouping mechanism.
     *
     * `status` (pending|ok|unavailable) does NOT migrate — it is scrape health,
     * connect_status means something else, and no public surface reads it.
     * Named as deliberately dropped in the wire manifest.
     */
    private function migrateOrderPlatforms(bool $dryRun, ?string $userId): array
```

- [ ] **Step 4: Run the tests**

Run: `./vendor/bin/pest tests/Feature/Content/MenuBackfillerTest.php && composer test:pg`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Migration/MenuBackfiller.php tests/Feature/Content/MenuBackfillerTest.php
git commit -m "feat(menus): ordering platforms become order_platform storefronts"
```

---

## Task 8: The pool wire — collections map and dining modes

**Files:**
- Modify: `app/Site/Pools/PoolResolver.php`
- Modify: `app/Services/PublicSite/IndividualProfilePayloadBuilder.php:309-353`
- Test: `tests/Feature/Content/MenusPoolTest.php` (extend), `tests/Feature/Content/PoolWireShapeTest.php`

**Interfaces:**
- Consumes: `PoolRegistry::kinds()`.
- Produces: `resolve()` returns `diningModes: list<string>|null`; `collections` now includes sidecar-less collections.

- [ ] **Step 1: Write the failing test**

```php
it('publishes menu categories in the collections map, not as dangling ids', function () {
    // itemPayloads() joins collections through content.storefronts and gates on
    // where(kind='storefront'). A menu category has no sidecar, so without this
    // change every dish would publish collectionIds pointing at collections
    // ABSENT from the map.
    [$user, $site, $menu] = seedMenuWithDishes(['Iced Latte'], categories: ['Drinks']);
    app(MenuBackfiller::class)->run();
    pinEveryItem($site, 'menus');

    $resolved = app(PoolResolver::class)->resolve($site, 'menus');
    $referenced = collect($resolved['selection'])->flatMap(fn ($i) => $i['collectionIds'])->unique();

    expect($referenced)->not->toBeEmpty()
        ->and(array_keys($resolved['collections']))->toEqualCanonicalizing($referenced->all());
});

it('carries the same key set on every collection entry regardless of kind', function () {
    [$user, $site, $menu] = seedMenuWithDishes(['Iced Latte'], categories: ['Drinks']);
    app(MenuBackfiller::class)->run();
    pinEveryItem($site, 'menus');

    $entry = collect(app(PoolResolver::class)->resolve($site, 'menus')['collections'])->first();

    expect(array_keys($entry))->toEqualCanonicalizing(PoolResolver::STORE_KEYS);
});

it('serves the menu dining modes on the pool envelope and null everywhere else', function () {
    [$user, $site, $menu] = seedMenuWithDishes(['Iced Latte']);
    DB::connection('pgsql')->table('site.menus')->where('id', $menu->id)
        ->update(['dining_modes' => json_encode(['DELIVERY', 'PICKUP'])]);
    app(MenuBackfiller::class)->run();
    pinEveryItem($site, 'menus');

    expect(app(PoolResolver::class)->resolve($site, 'menus')['diningModes'])->toBe(['DELIVERY', 'PICKUP'])
        ->and(app(PoolResolver::class)->resolve($site, 'services')['diningModes'])->toBeNull();
});

it('adds no queries to a pool with neither products nor dishes', function () {
    [$user, $site] = [seedUserWithSite(), null];
    DB::enableQueryLog();
    app(PoolResolver::class)->resolve(siteFor($user), 'watch');
    $withoutMenus = count(DB::getQueryLog());
    DB::flushQueryLog();

    expect($withoutMenus)->toBeLessThan(12);
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `./vendor/bin/pest tests/Feature/Content/MenusPoolTest.php`
Expected: FAIL — `collections` is empty and `diningModes` is an undefined key.

- [ ] **Step 3: Widen the collections read**

In `itemPayloads()`, replace the `$hasProduct` gate with:

```php
        // Shop AND menu reads. Gated on the resolved set containing a kind that
        // groups into collections, so watch / listen / media / events still add
        // no queries — this sits behind the 60s payload cache on the public path.
        $hasProduct = $items->contains(fn (object $i): bool => $i->kind === 'product');
        $hasMenuItem = $items->contains(fn (object $i): bool => $i->kind === 'menu_item');
        $groupsIntoCollections = $hasProduct || $hasMenuItem;
```

and drop `->where('c.kind', 'storefront')` from the join — a LEFT JOIN onto `content.storefronts` instead, so a collection without a sidecar still reaches the map with null store fields:

```php
            $links = DB::connection('pgsql')->table('content.collection_items as ci')
                ->join('content.collections as c', 'c.id', '=', 'ci.collection_id')
                ->leftJoin('content.storefronts as s', 's.collection_id', '=', 'c.id')
                ->whereIn('ci.item_id', $ids)
                ->whereNull('c.removed_at')
                ->orderBy('c.position')->orderBy('c.external_ref')
                ->get([...]);
```

In `collectionsFor()`, keep the full `STORE_KEYS` set and null the store-only fields when there is no sidecar — the "wire shape does not change with kind" contract:

```php
            // A menu category has no storefront sidecar, so provider/url/logo
            // are null. The KEY SET never varies — PoolWireShapeTest fails on
            // additions as well as removals, and a frontend that destructures
            // this map must not have to branch on which kind it got.
            'provider' => $row->provider === null ? null : (string) $row->provider,
```

- [ ] **Step 4: Add `diningModes` to the contract**

In `resolve()`'s return, beside `stats`:

```php
            // Store-level metadata for the menus pool: which service modes the
            // vendor offers. Not per-item content, so it rides the envelope the
            // same way `stats` does — null for every other pool, and
            // buildPools() spreads it only when non-null.
            'diningModes' => $this->diningModesFor($pool, $site),
```

with:

```php
    /** @return list<string>|null */
    private function diningModesFor(string $pool, Site $site): ?array
    {
        if ($pool !== 'menus') {
            return null;
        }

        $raw = DB::connection('pgsql')->table('site.menus')
            ->where('user_id', $site->user_id)->whereNull('deleted_at')
            ->value('dining_modes');

        // menus_dining_modes_is_array permits NULL or a jsonb array, and the
        // Postgres driver hands jsonb back as a string.
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_array($decoded) && $decoded !== [] ? array_values($decoded) : null;
    }
```

and update the `resolve()` docblock's return shape.

- [ ] **Step 5: Spread it on the public wire**

In `IndividualProfilePayloadBuilder::buildPools()`, after the `stats` spread:

```php
                // Menus carries its vendor's service modes. Absent when null,
                // the same additive contract `collections` and `stats` keep.
                ...($resolved['diningModes'] === null ? [] : ['diningModes' => $resolved['diningModes']]),
```

- [ ] **Step 6: Run the tests**

Run: `./vendor/bin/pest tests/Feature/Content/ tests/Feature/Api/PublicSite/`
Expected: PASS. `PoolWireShapeTest` fails on key additions — if it does, the new key is added to its expected set with a one-line reason.

- [ ] **Step 7: Commit**

```bash
git add app/Site/Pools/PoolResolver.php app/Services/PublicSite/IndividualProfilePayloadBuilder.php tests/Feature/Content/
git commit -m "feat(pools): the collections map carries sidecar-less collections, and menus carry dining modes"
```

---

## Task 9: Curation migration and pin seeding

**Files:**
- Create: `app/Console/Commands/ProvisionMenuPinsCommand.php`
- Test: `tests/Feature/Content/MenuBackfillerTest.php` (extend)

**Interfaces:**
- Consumes: `PoolSectionProvisioner::ensure(Site $site, string $pool): object`.
- Produces: `content:provision-menu-pins --dry-run`, idempotent, one dense 1-based `sort_key` per dish in the legacy menu's display order.

- [ ] **Step 1: Write the failing test**

```php
it('seeds a dense 1-based pin order from the legacy menu order', function () {
    [$user, $site, $menu] = seedMenuWithDishes(['Iced Latte', 'Flat White']);
    app(MenuBackfiller::class)->run();

    $this->artisan('content:provision-menu-pins')->assertSuccessful();

    $section = app(PoolSectionProvisioner::class)->ensure(siteFor($user), 'menus');
    $keys = DB::connection('pgsql')->table('site.section_items')
        ->where('section_id', $section->id)->where('state', 'pinned')
        ->orderBy('sort_key')->pluck('sort_key')->all();

    expect($keys)->toBe([1.0, 2.0]);
});

it('leaves an existing pin alone rather than rewriting it', function () {
    // 5b's provision-shop-pins is the precedent: an existing pin is never
    // rewritten, so a re-run cannot undo a drag the owner made afterwards.
    [$user, $site, $menu] = seedMenuWithDishes(['Iced Latte', 'Flat White']);
    app(MenuBackfiller::class)->run();
    $this->artisan('content:provision-menu-pins');

    $section = app(PoolSectionProvisioner::class)->ensure(siteFor($user), 'menus');
    DB::connection('pgsql')->table('site.section_items')
        ->where('section_id', $section->id)->limit(1)->update(['sort_key' => 99]);

    $this->artisan('content:provision-menu-pins');

    expect(DB::connection('pgsql')->table('site.section_items')
        ->where('section_id', $section->id)->max('sort_key'))->toBe(99.0);
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `./vendor/bin/pest tests/Feature/Content/MenuBackfillerTest.php`
Expected: FAIL — command not found.

- [ ] **Step 3: Write the command**

Model it on `ProvisionShopPinsCommand` verbatim, including its **no per-site try/catch** — one failing site aborts the run for every site after it in iteration order. That is 5b's deliberate constraint (a partial pin seed is worse than none: the owner sees a half-ordered menu and cannot tell which half is theirs). Copy it deliberately rather than diverging.

Ordering source: `site.menu_item_categories.position` within `site.menu_categories.position`, flattened — the same two-level flatten 5b applies to a catalogue.

- [ ] **Step 4: Run the tests**

Run: `./vendor/bin/pest tests/Feature/Content/MenuBackfillerTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/ProvisionMenuPinsCommand.php tests/Feature/Content/MenuBackfillerTest.php
git commit -m "feat(menus): seed the pool pins from the legacy menu order"
```

---

## Task 10: The edge purge follows the dishes

**Files:**
- Modify: `app/Services/Cloudflare/CloudflarePurgeService.php:266-282`
- Test: `tests/Feature/Cloudflare/CloudflarePurgeServiceTest.php`

**Interfaces:**
- Consumes: `content.items`, `content.item_slugs`.
- Produces: `/menu/<id>` purge targets derived from `content.*`.

- [ ] **Step 1: Write the failing test**

```php
it('builds dish purge targets from content items, not the legacy table', function () {
    [$user, $site, $menu] = seedMenuWithDishes(['Iced Latte']);
    app(MenuBackfiller::class)->run();
    DB::connection('pgsql')->table('site.menu_items')->delete();

    $targets = app(CloudflarePurgeService::class)->purgeTargetsForHandle($user->handle);

    expect(collect($targets)->filter(fn ($t) => str_contains($t, '/menu/')))->not->toBeEmpty();
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `./vendor/bin/pest tests/Feature/Cloudflare/CloudflarePurgeServiceTest.php`
Expected: FAIL — the lookup returns nothing once `site.menu_items` is empty.

- [ ] **Step 3: Repoint the lookup and escalate the catch**

Replace the `site.menu_items` → `site.menus` → `core.users` join with a `content.items` → `core.users` read filtered to `kind = 'menu_item'` and `removed_at IS NULL`, keeping the `menu_items_limit` cap. Wrap the catch in `App\Services\Analytics\Concerns\EscalatesRepeatedFaults` — the raw `report($e)` is un-deduped and `CloudflareCachePurgeJob` self-dispatches three delayed follow-ups, so one site save reports the same fault four times.

- [ ] **Step 4: Run the tests**

Run: `./vendor/bin/pest tests/Feature/Cloudflare/`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Cloudflare/CloudflarePurgeService.php tests/Feature/Cloudflare/
git commit -m "fix(cloudflare): dish purge targets read content.*, and the fault dedupes"
```

---

## Task 11: Phase 5 — the live menu proof

**No new files.** This is an operations task with a hard spend cap.

- [ ] **Step 1: Check the Apify balance BEFORE anything else**

The cap is **US$18 total**. If remaining credit is below what the three actors need, **STOP and report** — exceeding the cap is a STOP, not a spend.

- [ ] **Step 2: Dry-run the source provisioning, scoped**

```bash
php artisan ingest:backfill-sources --dry-run --connector=uber_eats
```

Unqualified, this command showed it would process **80 connections across every connector**, bumping `next_attempt_at` on unrelated sources including other sessions' in-flight work. If no `--connector`/`--connection` scoping flag exists, add one before running — do not run it unscoped.

- [ ] **Step 3: Sync `selection_ref` for the affected connections**

Nothing lands until `SourceProvisioner::sync()` has run per connection: `Pull.config.selection_ref` ships NULL, and a connector must treat NULL as land-nothing. `'storewide'` is the reserved token for the store-wide menu.

- [ ] **Step 4: Provision, dispatch, project**

```bash
php artisan ingest:project --dry-run
php artisan ingest:project
```

- [ ] **Step 5: Assert the gate**

```sql
SELECT count(*) FROM content.source_items WHERE kind = 'menu_item';   -- must be > 0
```

plus the menus pool returning those items off `dev-api.partna.au`.

- [ ] **Step 6: Inspect every merge row individually**

```sql
SELECT * FROM content.item_merges ORDER BY created_at DESC;
SELECT * FROM content.identity_candidates ORDER BY created_at DESC LIMIT 50;
```

`mergeInto()` HARD-DELETES uncurated merged-away items. This slice produces the programme's first real merges. **Any merge that cannot be defended row by row is a HALT**, not a note. The 7 `is_manual` dishes must survive.

---

## Task 12: Verify, document, merge

- [ ] **Step 1: Re-check the k6 harness**

`scripts/launch-check/k6/seed.sql` and `jobs.js` hard-code menu invariants and have silently broken on exactly this before. Read both against the new shape.

- [ ] **Step 2: Full gates**

```bash
composer test
composer test:pg
composer test:schema
php -d memory_limit=1G ./vendor/bin/phpstan analyse app --no-progress --debug
php artisan pint
```

- [ ] **Step 3: The coverage gate on dev**

Derive expected coords twice — once in PHP with the app's own `sha1`, once in SQL with `pgcrypto` — and assert they agree before comparing to what landed. A shared bug confirmed by one derivation is not a gate.

- [ ] **Step 4: Prove a 301 end to end**

Rename a dish on dev through the dashboard, then request the old permalink and follow the redirect. The 301 history is empty at entry (spec §2.2), so the proof is a rename you create, not a row you migrate.

- [ ] **Step 5: Wire manifest**

`docs/wire-changes/2026-08-12-slice-4-menus.md` — endpoint, before shape, after shape, consuming repo. Describe the NEW wire on its own terms. Name the three deliberate drops: `menu_platform_links.status`, connector-side rating/badges, historical menu analytics.

- [ ] **Step 6: Downstream prompt edits — BEFORE merging**

Every row of spec §13. A checkpoint is not a communication channel.

- [ ] **Step 7: Checkpoint into the parent spec**

Live SQL pasted, merges reconciled, three cache lanes named, `cloud env:logs partna development --minutes 10` plus a Nightwatch scan.

- [ ] **Step 8: Merge**

`ExitWorktree` first — a worktree-isolated session cannot merge from inside. Then fetch, fast-forward `development`, `git merge --no-ff feat/slice-4-menus`, re-run the suite **on the merge**, push. Expect 1–3 fetch+rebase cycles; sibling sessions push between your fetch and your push. **Never push to `production`.**

---

## Self-Review

**Spec coverage.** §3 slug lane → Tasks 4, 5. §4 offers → Task 2. §5 collections → Tasks 2, 6. §6.1 identity → Tasks 6, 11. §6.2 pool → Tasks 1, 8, 9. §7 Unit 6 → Tasks 7, 8. §8 payload → Task 2. §9 backfill + coord → Tasks 2, 3. §10 cache/purge/k6/analytics → Tasks 3, 10, 12. §11 Phase 5 → Task 11. §12 verification → Task 12. §13 downstream edits → Task 12 step 6. No gaps.

**Known unknowns, flagged rather than papered over.** Task 4 step 4 assumes `content.item_sources` is the source-item→item join and says so, with the grep that settles it. Task 6 assumes `RecordView::int()` exists; if not, cast `string('position')`. Task 8's query-count ceiling (12) is a placeholder bound to be tightened once measured — it is there to catch a regression of an order of magnitude, not to pin an exact number.

**Type consistency.** `MenuProjectionMapper::coordFor(string, string): string` and `project(object, array, array, object): array` are used with those signatures in Tasks 3 and 4. `MenuBackfiller::run(bool, ?string): array` matches `ServiceBackfiller` and `CustomLinkBackfiller`. `ContentItemSlugAllocator::forget(string, string): void` matches the existing signature.
