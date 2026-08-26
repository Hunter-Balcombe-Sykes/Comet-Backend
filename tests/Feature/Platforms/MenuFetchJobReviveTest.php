<?php

use App\Jobs\Platforms\MenuFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Menu;
use App\Models\Core\User\User;
use App\Services\Content\ManualMenuItems;
use App\Services\Content\ManualMenuWriter;
use App\Services\Platforms\MenuApifyScraper;
use App\Services\Platforms\MenuMerger;
use App\Services\Platforms\MenuSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// MenuFetchJob::reviveScrapedDishes() — the reconnect half of menu
// reconciliation (plan B-R, 2026-08-26). A disconnect retires every
// scraper-owned dish; before the revive pass, a reconnect re-scrape wrote
// fresh facets onto rows whose items.removed_at stayed set, so the dishes
// never returned to the pool (live failure, ollies 2026-08-26).

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    Queue::fake();
});

function mfjrUser(string $handle): User
{
    $user = User::create([
        'handle' => $handle, 'handle_lc' => strtolower($handle), 'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle),
        'account_type' => 'business', 'sector' => 'restaurant', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $user->id, 'subdomain' => $handle,
        'is_published' => 1, 'settings' => json_encode([]),
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);

    return $user->fresh();
}

function mfjrOrdering(User $user): IntegrationConnection
{
    $url = 'https://www.ubereats.com/store/x';
    $rid = 'order-'.substr(sha1(strtolower($url)), 0, 16);

    return IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'uber_eats.order', 'resource_id' => $rid,
        'payload' => ['id' => $rid, 'provider' => 'custom', 'url' => $url, 'name' => 'Order', 'source' => 'manual'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
}

function mfjrScrape(User $user, array $items): void
{
    test()->mock(MenuApifyScraper::class, function ($m) use ($items) {
        $m->shouldReceive('fetchStores')->once()->andReturn(['uber-eats' => [
            'store' => ['name' => 'Revive Cafe', 'currency' => 'AUD'],
            'categories' => [['name' => 'Drinks', 'items' => $items]],
        ]]);
    });

    (new MenuFetchJob((string) $user->id, true))->handle(
        app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class)
    );
}

it('revives a retired scraper-owned dish when the scrape re-emits it, keeping its id', function () {
    $user = mfjrUser('mfjr1');
    mfjrOrdering($user);

    mfjrScrape($user, [['name' => 'Cola', 'pickupPrice' => 3.0, 'deliveryPrice' => 3.0]]);

    $items = app(ManualMenuItems::class);
    $row = $items->rows((string) $user->id)->firstWhere('headline', 'Cola');
    expect($row)->not->toBeNull();
    $originalId = (string) $row->id;

    // The disconnect half: reconciliation retires the dish (items.removed_at
    // + freed slug) — the exact state the ollies incident left 66 dishes in.
    app(ManualMenuWriter::class)->markRemoved($originalId);
    expect($items->rows((string) $user->id)->firstWhere('headline', 'Cola'))->toBeNull();

    // Reconnect re-scrape, same dish. Before reviveScrapedDishes() this wrote
    // fresh facets but left removed_at set — dish stayed invisible forever.
    mfjrScrape($user, [['name' => 'Cola', 'pickupPrice' => 3.0, 'deliveryPrice' => 3.0]]);

    $revived = app(ManualMenuItems::class)->rows((string) $user->id)->firstWhere('headline', 'Cola');
    expect($revived)->not->toBeNull()
        // Identity stable — the same content.items row came back, not a twin.
        ->and((string) $revived->id)->toBe($originalId);

    // The slug was re-minted, not stranded: exactly one current slug row.
    $slugs = DB::connection('pgsql')->table('content.item_slugs')
        ->where('item_id', $originalId)->where('is_current', true)->count();
    expect($slugs)->toBe(1);
});

it('never revives an owner-deleted dish (suppressed_items stays sticky) while siblings revive', function () {
    $user = mfjrUser('mfjr2');
    mfjrOrdering($user);

    mfjrScrape($user, [
        ['name' => 'Cola', 'pickupPrice' => 3.0, 'deliveryPrice' => 3.0],
        ['name' => 'Lemonade', 'pickupPrice' => 4.0, 'deliveryPrice' => 4.0],
    ]);

    $items = app(ManualMenuItems::class);
    $colaId = (string) $items->rows((string) $user->id)->firstWhere('headline', 'Cola')->id;
    $lemonadeId = (string) $items->rows((string) $user->id)->firstWhere('headline', 'Lemonade')->id;

    // Owner deletes Cola: the delete verb writes menus.suppressed_items AND
    // retires the row. Lemonade is retired by reconciliation only.
    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();
    $menu->forceFill(['suppressed_items' => [['name' => 'Cola']]])->save();
    app(ManualMenuWriter::class)->markRemoved($colaId);
    app(ManualMenuWriter::class)->markRemoved($lemonadeId);

    mfjrScrape($user, [
        ['name' => 'Cola', 'pickupPrice' => 3.0, 'deliveryPrice' => 3.0],
        ['name' => 'Lemonade', 'pickupPrice' => 4.0, 'deliveryPrice' => 4.0],
    ]);

    $headlines = app(ManualMenuItems::class)->rows((string) $user->id)->pluck('headline')->all();
    expect($headlines)->toContain('Lemonade')   // sibling revived
        ->not->toContain('Cola');               // owner delete stays sticky
});

it('revives onto a suffixed slug when the base was taken while retired, without stealing it back', function () {
    $user = mfjrUser('mfjr4');
    mfjrOrdering($user);

    // "Café Latte" and "Cafe Latte" are distinct dishes to normalizeName()
    // but share the slug base `cafe-latte` — the exact pair the persist()
    // FREE-THEN-RE-ASSERT comment names.
    mfjrScrape($user, [['name' => 'Café Latte', 'pickupPrice' => 5.0, 'deliveryPrice' => 5.0]]);

    $items = app(ManualMenuItems::class);
    $originalId = (string) $items->rows((string) $user->id)->firstWhere('headline', 'Café Latte')->id;
    app(ManualMenuWriter::class)->markRemoved($originalId); // frees `cafe-latte`

    // The newcomer claims the freed base while the original sits retired.
    mfjrScrape($user, [['name' => 'Cafe Latte', 'pickupPrice' => 4.0, 'deliveryPrice' => 4.0]]);
    $newcomerId = (string) app(ManualMenuItems::class)->rows((string) $user->id)
        ->firstWhere('headline', 'Cafe Latte')->id;
    expect($newcomerId)->not->toBe($originalId);

    // Vendor re-lists both: the original revives but must NOT steal the base
    // back — it takes the writer's suffix.
    mfjrScrape($user, [
        ['name' => 'Café Latte', 'pickupPrice' => 5.0, 'deliveryPrice' => 5.0],
        ['name' => 'Cafe Latte', 'pickupPrice' => 4.0, 'deliveryPrice' => 4.0],
    ]);

    $slugOf = fn (string $id) => (string) DB::connection('pgsql')->table('content.item_slugs')
        ->where('item_id', $id)->where('is_current', true)->value('slug');

    expect(app(ManualMenuItems::class)->rows((string) $user->id)->pluck('headline')->all())
        ->toContain('Café Latte')
        ->toContain('Cafe Latte')
        ->and($slugOf($newcomerId))->toBe('cafe-latte')
        ->and($slugOf($originalId))->toBe('cafe-latte-2');
});

it('leaves an owner-edited dish (manual_overrides) in its current removed state', function () {
    $user = mfjrUser('mfjr3');
    mfjrOrdering($user);

    mfjrScrape($user, [['name' => 'Cola', 'pickupPrice' => 3.0, 'deliveryPrice' => 3.0]]);

    $items = app(ManualMenuItems::class);
    $colaId = (string) $items->rows((string) $user->id)->firstWhere('headline', 'Cola')->id;

    // Owner edit marker — the whole-dish lock the write loop honours.
    DB::connection('pgsql')->table('content.manual_overrides')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $colaId,
        'facet' => 'items', 'column_name' => 'headline',
        'value' => json_encode('Cola Deluxe'),
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);
    app(ManualMenuWriter::class)->markRemoved($colaId);

    mfjrScrape($user, [['name' => 'Cola', 'pickupPrice' => 3.0, 'deliveryPrice' => 3.0]]);

    // The locked coord is skipped by the write loop, so the revive pass never
    // sees it: current (removed) state is retained.
    expect(app(ManualMenuItems::class)->rows((string) $user->id)->firstWhere('headline', 'Cola'))->toBeNull()
        ->and(DB::connection('pgsql')->table('content.items')
            ->where('id', $colaId)->whereNotNull('removed_at')->exists())->toBeTrue();
});

it('keeps a dish\'s per-platform identity when a later scrape fails to re-supply it (sticky identity)', function () {
    $user = mfjrUser('mfjr5');
    mfjrOrdering($user);

    // Run 1: the actor returned identity for the dish.
    mfjrScrape($user, [[
        'name' => 'Cola', 'pickupPrice' => 3.0, 'deliveryPrice' => 3.0,
        'itemUrl' => 'https://ue/store/x/sec/sub/uuid-cola', 'externalId' => 'uuid-cola',
    ]]);

    $offer = fn () => DB::connection('pgsql')->table('content.offers as o')
        ->join('content.items as i', 'i.id', '=', 'o.item_id')
        ->where('i.user_id', $user->id)->where('o.platform', 'uber-eats')
        ->whereNotNull('o.item_url')->first(['o.item_url', 'o.external_ref']);
    expect($offer()->item_url)->toBe('https://ue/store/x/sec/sub/uuid-cola');

    // Run 2: same dish, but the actor's flaky identity output came back empty.
    mfjrScrape($user, [['name' => 'Cola', 'pickupPrice' => 3.0, 'deliveryPrice' => 3.0]]);

    // The stable identity survives the wholesale rebuild.
    expect($offer())->not->toBeNull()
        ->and($offer()->item_url)->toBe('https://ue/store/x/sec/sub/uuid-cola')
        ->and($offer()->external_ref)->toBe('uuid-cola');

    // Run 3: fresh identity wins over the carried one.
    mfjrScrape($user, [[
        'name' => 'Cola', 'pickupPrice' => 3.0, 'deliveryPrice' => 3.0,
        'itemUrl' => 'https://ue/store/x/sec/sub/uuid-cola-v2', 'externalId' => 'uuid-cola-v2',
    ]]);
    expect($offer()->item_url)->toBe('https://ue/store/x/sec/sub/uuid-cola-v2');
});
