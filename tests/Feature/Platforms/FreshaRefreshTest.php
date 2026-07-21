<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\Service;
use App\Models\Core\User\User;
use App\Services\Platforms\FreshaScraper;
use App\Services\Platforms\FreshaServiceProjector;
use App\Services\Platforms\Strategies\Fetch\FetchNotModifiedException;
use App\Services\Platforms\Strategies\Fetch\FetchUnavailableException;
use App\Services\Platforms\Strategies\Fetch\FreshaFetch;

// FreshaFetch — the scheduled service-menu refresh, against a mocked scraper.
// Since the projection rework (2026-07-21) fetch() also upserts site.services
// rows and persists the raw scrape at payload.raw, so the connection needs a
// real user + the services tables.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupServicesTable();
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
    return new FreshaFetch($scraper, app(FreshaServiceProjector::class));
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

    // Both scraped services projected into site.services rows.
    expect(Service::query()->where('user_id', $user->id)->where('source', 'fresha')->count())->toBe(2);
});

it('refreshes an employee selection via the booking GraphQL path', function () {
    $user = freshaRefreshUser('frr2');
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
    // Pre-projected state: rows exist + payload.raw stored (the steady state
    // every connection reaches after its first post-rework refresh).
    app(FreshaServiceProjector::class)->sync($user, $services);

    $scraper = Mockery::mock(FreshaScraper::class);
    $scraper->shouldReceive('fetchLocation')->once()->andReturn(['name' => 'Acme']);
    $scraper->shouldReceive('extractServices')->once()->andReturn($services);
    $scraper->shouldReceive('extractStoreName')->once()->andReturn('Acme');

    $selection = [
        'url' => 'https://www.fresha.com/a/acme', 'storeName' => 'Acme', 'mode' => 'storewide',
        'employee' => null, 'services' => $services, 'hiddenServiceIds' => [],
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
    // so projections + raw land, exactly once.
    $out = freshaFetchWith($scraper)->fetch(freshaConn([
        'url' => 'https://www.fresha.com/a/acme', 'selection' => $selection,
    ], $user));

    expect(array_column($out['raw']['services'], 'serviceId'))->toBe(['s:1']);
    expect(Service::query()->where('user_id', $user->id)->where('source', 'fresha')->count())->toBe(1);
});
