<?php

use App\Jobs\Platforms\GoogleMenuPhotoScanJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Menu;
use App\Models\Core\User\User;
use App\Services\Content\ManualMenuItems;
use App\Services\Content\ManualMenuWriter;
use App\Services\Platforms\GoogleMenuImagesScraper;
use App\Services\Platforms\MenuAiExtractor;
use App\Services\Platforms\MenuScanApplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    Queue::fake();
});

function scanUser(string $h, string $accountType = 'business', string $sector = 'restaurant'): User
{
    $user = User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => $accountType,
        'sector' => $sector,
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $user->id, 'subdomain' => $h,
        'is_published' => 1, 'settings' => json_encode([]),
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);

    return $user;
}

/**
 * A SCRAPED dish, landed the way MenuFetchJob's persist lands one: through the
 * projection mapper, so its category carries a plain `menu:<slug>` ref and is
 * therefore scraper-owned as far as the applier is concerned.
 */
function scanMenuWithItem(User $user, string $itemName, ?string $description, ?float $price, mixed $badges = null): string
{
    $menu = Menu::create(['user_id' => $user->id, 'content_source' => 'uber-eats', 'currency' => 'AUD', 'fetch_status' => 'ok']);
    $writer = app(ManualMenuWriter::class);

    return $writer->write(
        (string) $user->id,
        $writer->coordFor((string) $menu->id, $itemName),
        $writer->projectionFor(
            (object) ['name' => $itemName, 'description' => $description, 'base_price' => $price, 'badges' => $badges],
            [['id' => (string) Str::uuid(), 'name' => 'Mains', 'position' => 0]],
            [],
            $menu,
        ),
    );
}

/** The dish the assertions below read back, folded to the legacy columns. */
function scanRow(User $user, string $name): ?object
{
    return app(ManualMenuItems::class)->rows((string) $user->id)->firstWhere('headline', $name);
}

// ── Extractor parsing ────────────────────────────────────────────────────────

it('parses structured items with canonical dietary labels', function () {
    $raw = json_encode(['items' => [
        ['name' => 'Pizza Margherita', 'description' => 'Tomato, basil', 'price' => 25.5, 'category' => 'Pizza', 'dietary' => ['gluten free', 'Vegetarian', 'nonsense']],
    ]]);

    $items = app(MenuAiExtractor::class)->parseItems($raw);

    expect($items)->toHaveCount(1)
        ->and($items[0]['name'])->toBe('Pizza Margherita')
        ->and($items[0]['price'])->toBe(25.5)
        ->and($items[0]['dietary'])->toBe(['Gluten free', 'Vegetarian']);
});

it('salvages a max_tokens-truncated items array', function () {
    $raw = '{"items":[{"name":"Garlic Bread","description":null,"price":9,"category":"Starters","dietary":null},{"name":"Cut Off Mid';

    $items = app(MenuAiExtractor::class)->parseItems($raw);

    expect($items)->toHaveCount(1)
        ->and($items[0]['name'])->toBe('Garlic Bread');
});

it('truncates a description over DESCRIPTION_MAX rather than emitting it unbounded (#FU-11)', function () {
    // Producer/validator asymmetry: ApplyMenuScanRequest bounds
    // items.*.description at MenuAiExtractor::DESCRIPTION_MAX, so the
    // extractor must never emit a longer string or a legitimately-scanned
    // item gets 422'd on apply.
    $raw = json_encode(['items' => [
        ['name' => 'Long Description Dish', 'description' => str_repeat('a', MenuAiExtractor::DESCRIPTION_MAX + 500), 'price' => 10, 'category' => null, 'dietary' => null],
    ]]);

    $items = app(MenuAiExtractor::class)->parseItems($raw);

    expect($items)->toHaveCount(1)
        ->and(mb_strlen($items[0]['description']))->toBe(MenuAiExtractor::DESCRIPTION_MAX);
});

it('returns no items for non-menu output', function () {
    expect(app(MenuAiExtractor::class)->parseItems('{"items":[]}'))->toBe([])
        ->and(app(MenuAiExtractor::class)->parseItems('total garbage'))->toBe([]);
});

it('parses a dense multi-category menu into per-category items, never a bare category header', function () {
    // Representative of a dense multi-column drinks menu (bug repro case:
    // the scan previously returned only the 6 category headers as "items",
    // zero real drinks) — 4 categories, wines with a dual glass/bottle price
    // and a region/appellation line folded into the description.
    $raw = json_encode(['items' => [
        ['name' => 'Negroni', 'description' => 'Gin, Campari, sweet vermouth', 'price' => 18, 'category' => 'COCKTAILS', 'dietary' => null],
        ['name' => 'Aperol Spritz', 'description' => 'Aperol, prosecco, soda', 'price' => 16, 'category' => 'COCKTAILS', 'dietary' => null],
        ['name' => 'Prosecco', 'description' => 'DOC, Veneto, IT. Bottle $58.', 'price' => 14, 'category' => 'SPARKLING', 'dietary' => null],
        ['name' => 'Franciacorta Brut', 'description' => 'DOCG, Lombardy, IT. Bottle $72.', 'price' => 18, 'category' => 'SPARKLING', 'dietary' => null],
        ['name' => 'Chianti Classico', 'description' => 'DOCG, Tuscany, IT. Bottle $60.', 'price' => 15, 'category' => 'RED WINES', 'dietary' => null],
        ['name' => 'Barolo', 'description' => 'DOCG, Piedmont, IT. Bottle $95.', 'price' => 22, 'category' => 'RED WINES', 'dietary' => null],
        ['name' => 'Peroni', 'description' => 'Italian lager', 'price' => 9, 'category' => 'BEERS & CIDER', 'dietary' => null],
        ['name' => 'Guinness', 'description' => 'Irish stout', 'price' => 10, 'category' => 'BEERS & CIDER', 'dietary' => null],
    ]]);

    $items = app(MenuAiExtractor::class)->parseItems($raw);

    expect($items)->toHaveCount(8);

    $byCategory = collect($items)->groupBy('category');
    expect($byCategory->keys()->sort()->values()->all())
        ->toBe(['BEERS & CIDER', 'COCKTAILS', 'RED WINES', 'SPARKLING'])
        ->and($byCategory['COCKTAILS'])->toHaveCount(2)
        ->and($byCategory['SPARKLING'])->toHaveCount(2)
        ->and($byCategory['RED WINES'])->toHaveCount(2)
        ->and($byCategory['BEERS & CIDER'])->toHaveCount(2);

    // The exact bug under test: no item's name is literally one of the
    // category header strings ("6 items found" = the 6 headers, 0 drinks).
    $categoryNames = $byCategory->keys()->all();
    foreach ($items as $item) {
        expect($categoryNames)->not->toContain($item['name']);
    }

    // Dual wine pricing (glass/bottle) resolves to the lower price, with the
    // second price folded into the description rather than silently dropped.
    $chianti = collect($items)->firstWhere('name', 'Chianti Classico');
    expect($chianti['price'])->toBe(15.0)
        ->and($chianti['description'])->toContain('Bottle $60');
});

// ── Applier enrich semantics ─────────────────────────────────────────────────

it('updates a matched item description only when the scanned one is longer', function () {
    $user = scanUser('longerwins');
    scanMenuWithItem($user, 'Pizza Margherita', 'Tomato.', 25.5);

    app(MenuScanApplier::class)->apply($user, [
        ['name' => 'Pizza Margherita', 'description' => 'San Marzano tomato, fior di latte mozzarella & fresh basil.', 'price' => null, 'category' => null, 'dietary' => null],
    ], enrichOnly: true);
    expect(scanRow($user, 'Pizza Margherita')->description)->toContain('San Marzano');

    app(MenuScanApplier::class)->apply($user, [
        ['name' => 'Pizza Margherita', 'description' => 'Short.', 'price' => null, 'category' => null, 'dietary' => null],
    ], enrichOnly: true);
    expect(scanRow($user, 'Pizza Margherita')->description)->toContain('San Marzano');
});

it('fills a missing price but never overwrites a scraped one', function () {
    $user = scanUser('pricefill');
    scanMenuWithItem($user, 'Garlic Bread', null, 12.0);

    app(MenuScanApplier::class)->apply($user, [
        ['name' => 'Garlic Bread', 'description' => null, 'price' => 9.0, 'category' => null, 'dietary' => null],
    ], enrichOnly: true);
    expect(scanRow($user, 'Garlic Bread')->base_price)->toBe(12.0);

    $unpriced = scanUser('pricefillempty');
    scanMenuWithItem($unpriced, 'Garlic Bread', null, null);
    app(MenuScanApplier::class)->apply($unpriced, [
        ['name' => 'Garlic Bread', 'description' => null, 'price' => 9.0, 'category' => null, 'dietary' => null],
    ], enrichOnly: true);
    expect(scanRow($unpriced, 'Garlic Bread')->base_price)->toBe(9.0);
});

it('merges scanned dietary markers into existing badges without duplicates', function () {
    $user = scanUser('badgemerge');
    scanMenuWithItem($user, 'Green Curry', 'Thai classic', 24.0, [['text' => 'Popular'], ['text' => 'Vegan', 'type' => 'dietary']]);

    app(MenuScanApplier::class)->apply($user, [
        ['name' => 'Green Curry', 'description' => null, 'price' => null, 'category' => null, 'dietary' => ['Vegan', 'Gluten free']],
    ]);

    // Alphabetical, and text-only: content.item_tags carries neither an ordinal
    // column nor the badge's own `type` (MenuProjectionMapper::badges()).
    expect(collect(scanRow($user, 'Green Curry')->badges)->pluck('text')->all())
        ->toBe(['Gluten free', 'Popular', 'Vegan']);
});

it('badges brand-new scan items with their dietary markers', function () {
    $user = scanUser('newbadges');
    scanMenuWithItem($user, 'Existing Dish', null, null);

    app(MenuScanApplier::class)->apply($user, [
        ['name' => 'Buckwheat Pancakes', 'description' => 'House made', 'price' => 18.0, 'category' => 'Breakfast', 'dietary' => ['Gluten free']],
    ]);

    expect(scanRow($user, 'Buckwheat Pancakes'))->not->toBeNull()
        ->and(scanRow($user, 'Buckwheat Pancakes')->badges)->toBe([['text' => 'Gluten free']]);
});

// ── Job guards ───────────────────────────────────────────────────────────────

it('spends nothing for accounts without the menu capability', function () {
    Http::fake();
    config()->set('services.mistral.key', 'k1');
    config()->set('services.deepseek.key', 'k2');

    // can_use_menu is food-derived for EITHER account type since 2026-09-01 —
    // a non-food sector is what withholds it now, not the account_type enum.
    $user = scanUser('nocap', 'partna', 'barber');

    (new GoogleMenuPhotoScanJob((string) $user->id, 'place-x'))->handle(
        app(MenuAiExtractor::class),
        app(GoogleMenuImagesScraper::class),
        app(MenuScanApplier::class),
    );

    Http::assertNothingSent();
});

it('spends nothing when the AI keys are not configured', function () {
    Http::fake();
    config()->set('services.mistral.key', '');
    config()->set('services.deepseek.key', '');

    $user = scanUser('nokeys');

    (new GoogleMenuPhotoScanJob((string) $user->id, 'place-x'))->handle(
        app(MenuAiExtractor::class),
        app(GoogleMenuImagesScraper::class),
        app(MenuScanApplier::class),
    );

    Http::assertNothingSent();
});

it('scans stored google photos, applies items, and persists scan_items', function () {
    config()->set('services.mistral.key', 'k1');
    config()->set('services.deepseek.key', 'k2');
    config()->set('services.apify.token', null); // tier-2 sweep unavailable — free tier must carry it

    $user = scanUser('scanhappy');
    scanMenuWithItem($user, 'Pizza Margherita', 'Tomato.', null);

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'google-business',
        'resource_id' => 'place-1',
        'payload' => [
            'photos' => [
                ['ref' => 'p/1', 'url' => 'https://lh3.example.com/menu-board.jpg'],
                ['ref' => 'p/2', 'url' => 'https://lh3.example.com/latte.jpg'],
            ],
        ],
    ]);

    $menuText = str_repeat('PIZZA MARGHERITA 25.5 san marzano tomato basil GF option ', 8);
    Http::fake([
        'api.mistral.ai/v1/ocr' => Http::sequence()
            ->push(['pages' => [['markdown' => $menuText]]])
            ->push(['pages' => [['markdown' => '']]]),
        'api.deepseek.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode(['items' => [
                ['name' => 'Pizza Margherita', 'description' => 'San Marzano tomato, mozzarella & fresh basil — wood fired.', 'price' => 25.5, 'category' => 'Pizza', 'dietary' => ['Gluten free']],
            ]])]]],
        ]),
    ]);

    (new GoogleMenuPhotoScanJob((string) $user->id, 'place-1'))->handle(
        app(MenuAiExtractor::class),
        app(GoogleMenuImagesScraper::class),
        app(MenuScanApplier::class),
    );

    $row = scanRow($user, 'Pizza Margherita');
    expect($row->description)->toContain('wood fired')
        ->and(collect($row->badges)->pluck('text')->all())->toContain('Gluten free');

    $menu = Menu::query()->where('user_id', $user->id)->first();
    expect($menu->scan_items['source'] ?? null)->toBe('google-photos')
        ->and($menu->scan_items['items'] ?? [])->toHaveCount(1);
});

it('applies a dense multi-category scan as per-category items, not bare category headers', function () {
    // End-to-end regression for the reported bug: a dense multi-column
    // drinks menu (categories + many priced items each) came back from the
    // scan as "6 items found" — the 6 category headers, zero real drinks.
    // The Mistral OCR response here mirrors a multi-column menu flattened
    // to text (4 categories, wines with a region/DOC line + dual glass/
    // bottle price); the DeepSeek response mirrors what the current
    // MenuAiExtractor system prompt actually returns for that input
    // (verified against the live API — see MenuAiExtractor prompt comment).
    config()->set('services.mistral.key', 'k1');
    config()->set('services.deepseek.key', 'k2');
    config()->set('services.apify.token', null); // tier-2 sweep unavailable — free tier must carry it

    $user = scanUser('densemenu');

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'google-business',
        'resource_id' => 'place-dense',
        'payload' => [
            'photos' => [
                ['ref' => 'p/1', 'url' => 'https://lh3.example.com/drinks-menu.jpg'],
            ],
        ],
    ]);

    $ocrText = <<<'TXT'
COCKTAILS
Negroni $18
Gin, Campari, sweet vermouth

Aperol Spritz $16
Aperol, prosecco, soda

SPARKLING
Prosecco DOC
Veneto, IT
Glass $14 / Bottle $58

Franciacorta Brut DOCG
Lombardy, IT
Glass $18 / Bottle $72

RED WINES
Chianti Classico DOCG
Tuscany, IT
Glass $15 / Bottle $60

Barolo DOCG
Piedmont, IT
Glass $22 / Bottle $95

BEERS & CIDER
Peroni $9
Italian lager

Guinness $10
Irish stout
TXT;

    Http::fake([
        'api.mistral.ai/v1/ocr' => Http::response(['pages' => [['markdown' => $ocrText]]]),
        'api.deepseek.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode(['items' => [
            ['name' => 'Negroni', 'description' => 'Gin, Campari, sweet vermouth', 'price' => 18, 'category' => 'COCKTAILS', 'dietary' => null],
            ['name' => 'Aperol Spritz', 'description' => 'Aperol, prosecco, soda', 'price' => 16, 'category' => 'COCKTAILS', 'dietary' => null],
            ['name' => 'Prosecco', 'description' => 'DOC, Veneto, IT. Bottle $58.', 'price' => 14, 'category' => 'SPARKLING', 'dietary' => null],
            ['name' => 'Franciacorta Brut', 'description' => 'DOCG, Lombardy, IT. Bottle $72.', 'price' => 18, 'category' => 'SPARKLING', 'dietary' => null],
            ['name' => 'Chianti Classico', 'description' => 'DOCG, Tuscany, IT. Bottle $60.', 'price' => 15, 'category' => 'RED WINES', 'dietary' => null],
            ['name' => 'Barolo', 'description' => 'DOCG, Piedmont, IT. Bottle $95.', 'price' => 22, 'category' => 'RED WINES', 'dietary' => null],
            ['name' => 'Peroni', 'description' => 'Italian lager', 'price' => 9, 'category' => 'BEERS & CIDER', 'dietary' => null],
            ['name' => 'Guinness', 'description' => 'Irish stout', 'price' => 10, 'category' => 'BEERS & CIDER', 'dietary' => null],
        ]])]]]]),
    ]);

    (new GoogleMenuPhotoScanJob((string) $user->id, 'place-dense'))->handle(
        app(MenuAiExtractor::class),
        app(GoogleMenuImagesScraper::class),
        app(MenuScanApplier::class),
    );

    // Every category the scan created is scan-OWNED: its external_ref sits in
    // the `menu:scan:*` namespace, unreachable from a scraped label.
    $categories = app(ManualMenuItems::class)->categories((string) $user->id)
        ->filter(fn ($c) => MenuScanApplier::isOwnerCategoryRef((string) $c->external_ref))
        ->values();

    expect($categories)->toHaveCount(4)
        ->and($categories->pluck('label')->sort()->values()->all())
        ->toBe(['Beers & Cider', 'Cocktails', 'Red Wines', 'Sparkling']); // B5/3a: uniform-case scan labels re-cased at write

    $rows = app(ManualMenuItems::class)->rows((string) $user->id);
    foreach ($categories as $category) {
        $members = $rows->filter(fn ($r) => in_array((string) $category->id, $r->category_ids, true));
        expect($members)->toHaveCount(2);
        // The bug under test: a category header must never itself land as
        // an item row (e.g. a bare "COCKTAILS" item with no price).
        expect($members->pluck('headline')->all())->not->toContain($category->label);
    }

    $chianti = scanRow($user, 'Chianti Classico');
    expect($chianti->base_price)->toBe(15.0)
        ->and($chianti->description)->toContain('Bottle $60');
});
