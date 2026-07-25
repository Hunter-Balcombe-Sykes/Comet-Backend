<?php

use App\Jobs\Platforms\ProbeCommerceLinksJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\ShopBrand;
use App\Models\Core\Site\ShopProduct;
use App\Models\Core\User\User;
use App\Services\Platforms\CustomLinkSeeder;
use App\Services\Platforms\EventbriteScraper;
use App\Services\Platforms\EventsSeeder;
use App\Services\Platforms\GenericShopScraper;
use App\Services\Platforms\HumanitixScraper;
use App\Services\Platforms\InstagramAutoSync;
use App\Services\Platforms\IntegrationConnectionCacheRefresher;
use App\Services\Platforms\ShopBrandSeeder;
use App\Services\Platforms\ShopifyScraper;
use App\Services\Platforms\ShopProductSeeder;
use App\Services\Platforms\ShopProviderDetector;
use App\Services\Platforms\SquarespaceScraper;
use App\Services\Platforms\WooCommerceScraper;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIntegrationConnectionsTable();
});

// ── ProbeCommerceLinksJob routing (mock seeders — pure dispatch logic) ───────

function probeJobMocks(): array
{
    return [
        'generic' => Mockery::mock(GenericShopScraper::class),
        'detector' => Mockery::mock(ShopProviderDetector::class),
        'brands' => Mockery::mock(ShopBrandSeeder::class),
        'products' => Mockery::mock(ShopProductSeeder::class),
        'events' => Mockery::mock(EventsSeeder::class),
        'links' => Mockery::mock(CustomLinkSeeder::class),
    ];
}

function runProbeJob(string $userId, string $url, array $m, ?string $category = null, ?string $platform = null): void
{
    (new ProbeCommerceLinksJob($userId, $url, $category, $platform))->handle(
        $m['generic'], $m['detector'], $m['brands'], $m['products'], $m['events'], $m['links'],
    );
}

it('seeds an individual product when the probe reads a product page', function () {
    $user = User::factory()->create();
    $m = probeJobMocks();
    $product = ['productId' => 'p1', 'title' => 'Widget', 'price' => '10'];
    $m['generic']->shouldReceive('readProductPage')->once()->with('https://acme.example/widget')
        ->andReturn(['outcome' => GenericShopScraper::OUTCOME_PRODUCT, 'product' => $product, 'storeUrl' => null]);
    $m['products']->shouldReceive('seed')->once()->withArgs(fn ($u, $p) => $p === $product)->andReturn(true);
    $m['links']->shouldReceive('seed')->never();

    runProbeJob((string) $user->id, 'https://acme.example/widget', $m);
});

it('seeds a brand when the probe finds a storefront homepage', function () {
    $user = User::factory()->create();
    $m = probeJobMocks();
    $m['generic']->shouldReceive('readProductPage')->once()
        ->andReturn(['outcome' => GenericShopScraper::OUTCOME_STORE_PAGE, 'product' => null, 'storeUrl' => 'https://acme.example']);
    $detected = ['provider' => 'shopify', 'origin' => 'https://acme.example', 'sourceUrl' => 'https://acme.example', 'page' => null, 'store' => null];
    $m['detector']->shouldReceive('detect')->once()->with('https://acme.example')->andReturn($detected);
    $m['brands']->shouldReceive('seed')->once()->andReturn(new ShopBrand);
    $m['links']->shouldReceive('seed')->never();

    runProbeJob((string) $user->id, 'https://acme.example/', $m);
});

it('tries the provider detector on a reachable no-product page, then falls back to a custom link', function () {
    $user = User::factory()->create();
    $m = probeJobMocks();
    $m['generic']->shouldReceive('readProductPage')->once()
        ->andReturn(['outcome' => GenericShopScraper::OUTCOME_NO_PRODUCT, 'product' => null, 'storeUrl' => null]);
    // A store LISTING page (live case: Squarespace /-store) carries no product
    // JSON-LD — the detector's own probe chain is the second chance.
    $m['detector']->shouldReceive('detect')->once()->with('https://someblog.example')->andReturnNull();
    $m['links']->shouldReceive('seed')->once()->withArgs(fn ($u, $url) => $url === 'https://someblog.example');

    runProbeJob((string) $user->id, 'https://someblog.example', $m);
});

it('skips the detector entirely for an unreachable page', function () {
    $user = User::factory()->create();
    $m = probeJobMocks();
    $m['generic']->shouldReceive('readProductPage')->once()
        ->andReturn(['outcome' => GenericShopScraper::OUTCOME_UNREACHABLE, 'product' => null, 'storeUrl' => null]);
    $m['detector']->shouldReceive('detect')->never();
    $m['links']->shouldReceive('seed')->once();

    runProbeJob((string) $user->id, 'https://gone.example', $m);
});

it('routes classified event and organiser links to the events seeder, with custom-link fallback', function () {
    $user = User::factory()->create();

    $m = probeJobMocks();
    $m['events']->shouldReceive('seedStandalone')->once()
        ->with(Mockery::type(User::class), 'eventbrite', 'https://www.eventbrite.com/e/thing-1')->andReturn(true);
    $m['links']->shouldReceive('seed')->never();
    runProbeJob((string) $user->id, 'https://www.eventbrite.com/e/thing-1', $m, 'event', 'eventbrite');

    $m2 = probeJobMocks();
    $m2['events']->shouldReceive('seedAccount')->once()->andReturn(false); // cap hit / fetch fail
    $m2['links']->shouldReceive('seed')->once(); // nothing vanishes
    runProbeJob((string) $user->id, 'https://events.humanitix.com/host/x', $m2, 'event-organiser', 'humanitix');
});

it('downgrades to a custom link for an unclaimed (non-consenting) subject without probing', function () {
    $user = User::factory()->create(['status' => 'unclaimed']);
    $m = probeJobMocks();
    $m['generic']->shouldReceive('readProductPage')->never();
    $m['links']->shouldReceive('seed')->once();

    runProbeJob((string) $user->id, 'https://acme.example/widget', $m);
});

it('does nothing when the user no longer exists', function () {
    $m = probeJobMocks();
    $m['generic']->shouldReceive('readProductPage')->never();
    $m['links']->shouldReceive('seed')->never();

    runProbeJob((string) Str::uuid(), 'https://acme.example', $m);
});

// ── EventsSeeder (real DB writes) ────────────────────────────────────────────

function eventsSeederWith(?array $fetchEventsResult = null, ?array $singleEvent = null): EventsSeeder
{
    $eb = Mockery::mock(EventbriteScraper::class);
    $eb->shouldReceive('normalizeOrgUrl')->andReturnUsing(
        fn (string $u) => preg_match('~eventbrite\.[a-z.]+/o/[a-z0-9-]+~i', $u, $mm) ? 'https://www.eventbrite.com.au/o/'.basename($u) : null
    );
    $eb->shouldReceive('normalizeEventUrl')->andReturnUsing(
        fn (string $u) => str_contains($u, '/e/') ? $u : null
    );
    $eb->shouldReceive('fetchEvents')->andReturn($fetchEventsResult);
    $eb->shouldReceive('fetchSingleEvent')->andReturn($singleEvent);
    $hx = Mockery::mock(HumanitixScraper::class);
    $hx->shouldReceive('resolveHostUrl')->andReturnNull();
    $hx->shouldReceive('normalizeEventUrl')->andReturnNull();

    return new EventsSeeder($eb, $hx);
}

it('seeds an organiser account row and enforces the 5-account cap', function () {
    $user = User::factory()->create();
    $seeder = eventsSeederWith(fetchEventsResult: ['organiser' => 'Org', 'events' => []]);

    // 5 distinct organisers fill the cap.
    for ($i = 0; $i < 5; $i++) {
        expect($seeder->seedAccount($user, 'eventbrite', "https://www.eventbrite.com.au/o/org-{$i}"))->toBeTrue();
    }
    expect($seeder->seedAccount($user, 'eventbrite', 'https://www.eventbrite.com.au/o/org-6'))->toBeFalse();

    // Re-seeding an existing one is fine (updates in place, no cap hit).
    expect($seeder->seedAccount($user, 'eventbrite', 'https://www.eventbrite.com.au/o/org-0'))->toBeTrue();
    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'eventbrite')->count())->toBe(5);
});

it('seeds standalone events with the 10-event cap and never resurrects a tombstoned row', function () {
    $user = User::factory()->create();
    $seeder = eventsSeederWith(singleEvent: ['url' => 'https://www.eventbrite.com/e/x', 'title' => 'X']);

    expect($seeder->seedStandalone($user, 'eventbrite', 'https://www.eventbrite.com/e/x'))->toBeTrue();
    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'eventbrite')->first();
    expect($row->resource_kind)->toBe('event');

    // Disconnect (soft-delete) → a rescan must NOT bring it back.
    $row->delete();
    expect($seeder->seedStandalone($user, 'eventbrite', 'https://www.eventbrite.com/e/x'))->toBeFalse();
    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'eventbrite')->exists())->toBeFalse();
});

// ── ShopBrandSeeder / ShopProductSeeder (real DB writes) ─────────────────────

function shopBrandSeederWith(array $brand): ShopBrandSeeder
{
    $shopify = Mockery::mock(ShopifyScraper::class);
    $shopify->shouldReceive('fetchBrand')->andReturn($brand);
    $refresher = Mockery::mock(IntegrationConnectionCacheRefresher::class);
    $refresher->shouldReceive('refresh');

    return new ShopBrandSeeder(
        $shopify,
        Mockery::mock(WooCommerceScraper::class),
        Mockery::mock(SquarespaceScraper::class),
        $refresher,
    );
}

const PROBE_TEST_DETECTED = [
    'provider' => 'shopify',
    'origin' => 'https://acme.example',
    'sourceUrl' => 'https://acme.example',
    'page' => null,
    'store' => null,
];

it('seeds a brand with its marker connection and preserves an existing discount code', function () {
    $user = User::factory()->create();
    $seeder = shopBrandSeederWith(['id' => 'acme', 'name' => 'Acme', 'currency' => 'AUD', 'favicon' => null, 'logo' => null]);

    $row = $seeder->seed($user, PROBE_TEST_DETECTED);
    expect($row)->not->toBeNull();
    expect($row->name)->toBe('Acme');
    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'shop')->exists())->toBeTrue();

    // A user-typed discount must survive a re-seed.
    $row->update(['discount_code' => 'VIP10']);
    $again = $seeder->seed($user, PROBE_TEST_DETECTED);
    expect($again->discount_code)->toBe('VIP10');
    expect(ShopBrand::where('brand_id', 'acme')->count())->toBe(1);
});

it('never resurrects a tombstoned shop connection from a scan', function () {
    $user = User::factory()->create();
    $seeder = shopBrandSeederWith(['id' => 'acme', 'name' => 'Acme']);

    $first = $seeder->seed($user, PROBE_TEST_DETECTED);
    expect($first)->not->toBeNull();

    IntegrationConnection::where('user_id', $user->id)->where('platform', 'shop')->first()->delete();

    expect($seeder->seed($user, PROBE_TEST_DETECTED))->toBeNull();
});

it('adds an individual product newest-first with dedup by productId', function () {
    $user = User::factory()->create();
    $refresher = Mockery::mock(IntegrationConnectionCacheRefresher::class);
    $refresher->shouldReceive('refresh');
    $seeder = new ShopProductSeeder($refresher);

    expect($seeder->seed($user, ['productId' => 'a', 'title' => 'A']))->toBeTrue();
    expect($seeder->seed($user, ['productId' => 'b', 'title' => 'B']))->toBeTrue();
    // Re-adding A moves it back to the front, no duplicate.
    expect($seeder->seed($user, ['productId' => 'a', 'title' => 'A2']))->toBeTrue();

    $bucket = ShopBrand::where('brand_id', 'individual')->first();
    $products = ShopProduct::where('brand_id', $bucket->id)->orderBy('position')->get();
    expect($products)->toHaveCount(2);
    expect($products[0]->product_id)->toBe('a');
    expect($products[0]->data['title'])->toBe('A2');
    expect($products[1]->product_id)->toBe('b');
});

// ── InstagramAutoSync commerce routing (signup-v2 C3) ────────────────────────

it('dispatches a probe for a classified shop link and consumes the platform slot', function () {
    Queue::fake();
    $user = User::factory()->create();
    $seen = [];
    $findings = [];
    $unmatched = [];

    app(InstagramAutoSync::class)->handleClassifiedLink(
        $user,
        ['platform' => 'shop', 'category' => 'shop', 'label' => 'Shopify'],
        'https://acme.myshopify.com/',
        $seen, $findings, $unmatched,
    );

    Queue::assertPushed(
        ProbeCommerceLinksJob::class,
        fn ($job) => $job->category === 'shop' && $job->platform === 'shop',
    );
    expect($seen)->toHaveKey('shop');
    expect($unmatched)->toBe([]);
});

it('routes a commerce link to unmatched for a non-consenting (unclaimed) subject', function () {
    Queue::fake();
    $user = User::factory()->create(['status' => 'unclaimed']);
    $seen = [];
    $findings = [];
    $unmatched = [];

    app(InstagramAutoSync::class)->handleClassifiedLink(
        $user,
        ['platform' => 'eventbrite', 'category' => 'event-organiser', 'label' => 'Eventbrite'],
        'https://www.eventbrite.com.au/o/org-1',
        $seen, $findings, $unmatched,
    );

    Queue::assertNotPushed(ProbeCommerceLinksJob::class);
    expect($unmatched)->toBe([['url' => 'https://www.eventbrite.com.au/o/org-1', 'label' => 'Eventbrite']]);
});
