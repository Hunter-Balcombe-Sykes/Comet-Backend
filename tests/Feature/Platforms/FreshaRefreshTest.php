<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\FreshaScraper;
use App\Services\Platforms\Strategies\Fetch\FreshaFetch;
use App\Services\Platforms\Strategies\Fetch\FetchNotModifiedException;
use App\Services\Platforms\Strategies\Fetch\FetchUnavailableException;

// FreshaFetch — the scheduled service-menu refresh. Pure payload-in/payload-out
// against a mocked scraper (fetch() reads only $connection->payload).

function freshaConn(array $payload): IntegrationConnection
{
    $conn = new IntegrationConnection;
    $conn->payload = $payload;

    return $conn;
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
    $fetch = new FreshaFetch(Mockery::mock(FreshaScraper::class));

    expect(fn () => $fetch->fetch(freshaConn(['url' => 'https://www.fresha.com/a/x', 'selection' => null])))
        ->toThrow(FetchNotModifiedException::class);
});

it('refreshes a storewide selection from the location menu and prunes dead hidden ids', function () {
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

    $out = (new FreshaFetch($scraper))->fetch(freshaConn([
        'url' => 'https://www.fresha.com/a/acme',
        'selection' => $selection,
    ]));

    expect($out['selection']['services'])->toHaveCount(2)
        ->and($out['selection']['services'][0]['price'])->toBe('$55')
        ->and($out['selection']['services'][1]['serviceId'])->toBe('s:3')
        // s:2 vanished from the menu → its hidden id is pruned.
        ->and($out['selection']['hiddenServiceIds'])->toBe([])
        ->and($out['selection']['storeName'])->toBe('Acme Cuts')
        // Everything else rides through verbatim.
        ->and($out['selection']['mode'])->toBe('storewide')
        ->and($out['url'])->toBe('https://www.fresha.com/a/acme');
});

it('refreshes an employee selection via the booking GraphQL path', function () {
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

    $out = (new FreshaFetch($scraper))->fetch(freshaConn([
        'url' => 'https://www.fresha.com/a/acme',
        'selection' => $selection,
    ]));

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

    expect(fn () => (new FreshaFetch($scraper))->fetch(freshaConn([
        'url' => 'https://www.fresha.com/a/acme', 'selection' => $selection,
    ])))->toThrow(FetchUnavailableException::class);
});

it('304s when the refreshed menu is byte-identical', function () {
    $services = [freshaService('s:1', 'Cut')];
    $scraper = Mockery::mock(FreshaScraper::class);
    $scraper->shouldReceive('fetchLocation')->once()->andReturn(['name' => 'Acme']);
    $scraper->shouldReceive('extractServices')->once()->andReturn($services);
    $scraper->shouldReceive('extractStoreName')->once()->andReturn('Acme');

    $selection = [
        'url' => 'https://www.fresha.com/a/acme', 'storeName' => 'Acme', 'mode' => 'storewide',
        'employee' => null, 'services' => $services, 'hiddenServiceIds' => [],
    ];

    expect(fn () => (new FreshaFetch($scraper))->fetch(freshaConn([
        'url' => 'https://www.fresha.com/a/acme', 'selection' => $selection,
    ])))->toThrow(FetchNotModifiedException::class);
});
