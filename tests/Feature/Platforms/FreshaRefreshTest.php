<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\Service;
use App\Models\Core\User\User;
use App\Services\Platforms\FreshaAutoSelector;
use App\Services\Platforms\FreshaScraper;
use App\Services\Platforms\FreshaServiceProjector;
use App\Services\Platforms\StaffNameMatcher;
use App\Services\Platforms\Strategies\Fetch\FetchNotModifiedException;
use App\Services\Platforms\Strategies\Fetch\FetchUnavailableException;
use App\Services\Platforms\Strategies\Fetch\FreshaFetch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// FreshaFetch — the scheduled service-menu refresh, against a mocked scraper.
// fetch() persists the raw scrape at payload.raw and composes the effective
// selection, so the connection needs a real user.
//
// Slice 7 D3a: the composed selection comes from content.* (the Fresha
// connection lane), NOT from the scrape and no longer from site.services — so
// every case that asserts a non-empty selection must land the matching pool
// rows via frrLand(). A scrape with no pool behind it composes to [], which is
// 3b's documented "no content.* rows yet" behaviour reaching the public blob.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupServicesTable();
    // Slice 7 D3a: FreshaServiceProjector::compose() reads the Fresha service
    // menu from content.* (FreshaServiceItems) instead of site.services.
    setupContentTables();
    shimPgAdvisoryLockForSqlite();
});

function freshaRefreshUser(string $h): User
{
    return createTenant($h);
}

function freshaConn(array $payload, ?User $user = null): IntegrationConnection
{
    $conn = new IntegrationConnection;
    $conn->payload = $payload;
    if ($user !== null) {
        $conn->user_id = $user->id;
    }

    return $conn;
}

function freshaFetchWith(FreshaScraper $scraper): FreshaFetch
{
    // The selector gets the SAME scraper: resolving it from the container would
    // hand the self-heal branch a real scraper and make this test reach the network.
    return new FreshaFetch(
        $scraper,
        app(FreshaServiceProjector::class),
        new FreshaAutoSelector($scraper, app(StaffNameMatcher::class), app(FreshaServiceProjector::class)),
    );
}

/**
 * Land one Fresha service into content.* the way the ingest connector would.
 * `record_key` is the vendor serviceId, which is what FreshaServiceItems reads
 * back out — and the price comes from content.offers, not from the scrape.
 */
function frrLand(User $user, string $serviceId, string $name, int $amountMinor): void
{
    $sourceId = DB::table('content.sources')
        ->where('user_id', $user->id)->where('kind', 'connection')->value('id');

    if ($sourceId === null) {
        $sourceId = (string) Str::uuid();
        DB::table('content.sources')->insert([
            'id' => $sourceId, 'user_id' => $user->id, 'kind' => 'connection', 'connection_id' => null,
            'label' => 'Fresha', 'priority' => 100, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $itemId = addItem($user->id, 'service', $name);

    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $sourceId,
        'coord' => "fresha:store:{$serviceId}", 'record_key' => $serviceId,
        'item_id' => $itemId, 'kind' => 'service', 'projector_version' => 1,
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    DB::table('content.offers')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $itemId, 'source_id' => $sourceId,
        'channel' => 'fresha', 'qualifier' => 'exact', 'amount_minor' => $amountMinor,
        'currency' => null, 'updated_at' => now(),
    ]);
    DB::table('content.f_duration')->insert([
        'item_id' => $itemId, 'source_id' => $sourceId, 'seconds' => 1800, 'updated_at' => now(),
    ]);
}

function freshaService(string $id, string $name, ?string $price = '$50'): array
{
    return [
        'serviceId' => $id, 'name' => $name, 'duration' => '30min',
        'description' => null, 'price' => $price, 'priceValue' => null,
        'currency' => null, 'category' => 'Cuts', 'hasVariants' => false,
    ];
}

it('304s when the connection has no saved selection', function () {
    $fetch = freshaFetchWith(Mockery::mock(FreshaScraper::class));

    expect(fn () => $fetch->fetch(freshaConn(['url' => 'https://www.fresha.com/a/x', 'selection' => null])))
        ->toThrow(FetchNotModifiedException::class);
});

it('refreshes a storewide selection from the location menu and prunes dead hidden ids', function () {
    $user = freshaRefreshUser('frr1');
    // The pool the composed selection is read from. s:2 is deliberately NOT
    // landed — it is the retired service whose hidden id must be pruned.
    frrLand($user, 's:1', 'Cut', 5500);
    frrLand($user, 's:3', 'New Fade', 5000);
    $scraper = Mockery::mock(FreshaScraper::class);
    $scraper->shouldReceive('fetchLocation')->once()->andReturn(['name' => 'Acme Cuts']);
    $scraper->shouldReceive('extractServices')->once()->andReturn([
        freshaService('s:1', 'Cut', '$55'),
        freshaService('s:3', 'New Fade'),
    ]);
    $scraper->shouldReceive('extractStoreName')->once()->andReturn('Acme Cuts');

    $selection = [
        'url' => 'https://www.fresha.com/a/acme',
        'storeName' => 'Acme',
        'mode' => 'storewide',
        'employee' => null,
        'services' => [freshaService('s:1', 'Cut'), freshaService('s:2', 'Retired')],
        'hiddenServiceIds' => ['s:2'],
    ];

    $out = freshaFetchWith($scraper)->fetch(freshaConn([
        'url' => 'https://www.fresha.com/a/acme',
        'selection' => $selection,
    ], $user));

    expect($out['selection']['services'])->toHaveCount(2)
        ->and($out['selection']['services'][0]['price'])->toBe('$55')
        ->and($out['selection']['services'][1]['serviceId'])->toBe('s:3')
        // s:2 vanished from the menu → its hidden id is pruned.
        ->and($out['selection']['hiddenServiceIds'])->toBe([])
        ->and($out['selection']['storeName'])->toBe('Acme Cuts')
        // Everything else rides through verbatim.
        ->and($out['selection']['mode'])->toBe('storewide')
        ->and($out['url'])->toBe('https://www.fresha.com/a/acme')
        // The deduped raw scrape persists privately alongside the selection.
        ->and(array_column($out['raw']['services'], 'serviceId'))->toBe(['s:1', 's:3']);

    // D3a: the refresh writes NO site.services rows — the pool is the connector's
    // to write and this lane only reads it.
    expect(Service::query()->where('user_id', $user->id)->where('source', 'fresha')->count())->toBe(0);
});

it('refreshes an employee selection via the booking GraphQL path', function () {
    $user = freshaRefreshUser('frr2');
    frrLand($user, 's:1', 'Cut', 6000);
    $scraper = Mockery::mock(FreshaScraper::class);
    $scraper->shouldReceive('slugFromUrl')->once()->andReturn('acme');
    $scraper->shouldReceive('fetchEmployeeServices')->once()->with('acme', 'e1')->andReturn([
        freshaService('s:1', 'Cut', '$60'),
    ]);

    $selection = [
        'url' => 'https://www.fresha.com/a/acme',
        'storeName' => 'Acme',
        'mode' => 'employee',
        'employee' => ['employeeId' => 'e1', 'displayName' => 'Jo'],
        'services' => [freshaService('s:1', 'Cut')],
        'hiddenServiceIds' => [],
    ];

    $out = freshaFetchWith($scraper)->fetch(freshaConn([
        'url' => 'https://www.fresha.com/a/acme',
        'selection' => $selection,
    ], $user));

    expect($out['selection']['services'][0]['price'])->toBe('$60')
        ->and($out['selection']['employee']['employeeId'])->toBe('e1')
        // GraphQL path never fetched the location — storeName preserved.
        ->and($out['selection']['storeName'])->toBe('Acme');
});

it('throws unavailable (never wipes) when the refreshed menu is empty', function () {
    $scraper = Mockery::mock(FreshaScraper::class);
    $scraper->shouldReceive('fetchLocation')->once()->andReturn([]);
    $scraper->shouldReceive('extractServices')->once()->andReturn([]);
    $scraper->shouldReceive('extractStoreName')->once()->andReturn(null);

    $selection = [
        'url' => 'https://www.fresha.com/a/acme', 'storeName' => 'Acme', 'mode' => 'storewide',
        'employee' => null, 'services' => [freshaService('s:1', 'Cut')], 'hiddenServiceIds' => [],
    ];

    expect(fn () => freshaFetchWith($scraper)->fetch(freshaConn([
        'url' => 'https://www.fresha.com/a/acme', 'selection' => $selection,
    ])))->toThrow(FetchUnavailableException::class);
});

it('304s when the refreshed menu is byte-identical on an already-projected connection', function () {
    $user = freshaRefreshUser('frr3');
    $services = [freshaService('s:1', 'Cut')];
    // Steady state: the pool carries the service and payload.raw is stored, so
    // a re-scrape composes byte-identically and 304s. The stored selection has
    // to hold the COMPOSED entries, not the scrape's — that is what the
    // comparison is against.
    frrLand($user, 's:1', 'Cut', 5000);
    $composed = app(FreshaServiceProjector::class)->sync($user, $services);

    $scraper = Mockery::mock(FreshaScraper::class);
    $scraper->shouldReceive('fetchLocation')->once()->andReturn(['name' => 'Acme']);
    $scraper->shouldReceive('extractServices')->once()->andReturn($services);
    $scraper->shouldReceive('extractStoreName')->once()->andReturn('Acme');

    $selection = [
        'url' => 'https://www.fresha.com/a/acme', 'storeName' => 'Acme', 'mode' => 'storewide',
        'employee' => null, 'services' => $composed['services'], 'hiddenServiceIds' => [],
    ];

    expect(fn () => freshaFetchWith($scraper)->fetch(freshaConn([
        'url' => 'https://www.fresha.com/a/acme', 'selection' => $selection,
        'raw' => ['services' => $services],
    ], $user)))->toThrow(FetchNotModifiedException::class);
});

it('migrates a legacy (pre-projection) connection on first refresh even when the menu is unchanged', function () {
    $user = freshaRefreshUser('frr4');
    $services = [freshaService('s:1', 'Cut')];
    $scraper = Mockery::mock(FreshaScraper::class);
    $scraper->shouldReceive('fetchLocation')->once()->andReturn(['name' => 'Acme']);
    $scraper->shouldReceive('extractServices')->once()->andReturn($services);
    $scraper->shouldReceive('extractStoreName')->once()->andReturn('Acme');

    $selection = [
        'url' => 'https://www.fresha.com/a/acme', 'storeName' => 'Acme', 'mode' => 'storewide',
        'employee' => null, 'services' => $services, 'hiddenServiceIds' => [],
    ];

    // No payload.raw yet — a pre-rework row. The refresh must WRITE (not 304)
    // so raw lands, exactly once.
    $out = freshaFetchWith($scraper)->fetch(freshaConn([
        'url' => 'https://www.fresha.com/a/acme', 'selection' => $selection,
    ], $user));

    expect(array_column($out['raw']['services'], 'serviceId'))->toBe(['s:1']);
    expect(Service::query()->where('user_id', $user->id)->where('source', 'fresha')->count())->toBe(0);
});
