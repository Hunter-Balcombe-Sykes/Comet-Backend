<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\FreshaScraper;
use App\Services\Platforms\Strategies\Fetch\FetchNotModifiedException;
use App\Services\Platforms\Strategies\Fetch\FreshaFetch;
use Mockery\MockInterface;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupServicesTable();
    setupServiceCategoriesTable();
    setupIntegrationConnectionsTable();
    shimPgAdvisoryLockForSqlite();
});

function freshaRow(User $user, array $payload): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => $payload, 'is_active' => true,
    ]);
}

function stubBackstopMenu(): void
{
    test()->mock(FreshaScraper::class, function (MockInterface $m) {
        $m->shouldReceive('fetchMenu')->andReturn([
            'storeName' => 'Anseo Studio', 'team' => [],
            'services' => [[
                'serviceId' => 's:1', 'name' => 'Cut', 'duration' => '30min', 'description' => null,
                'price' => 'A$50', 'priceValue' => 50, 'currency' => 'AUD', 'category' => 'Hair', 'hasVariants' => false,
            ]],
        ]);
        $m->shouldReceive('slugFromUrl')->andReturn('anseo-studio-v0v92jna');
        $m->shouldReceive('fetchEmployeeServices')->andReturn(null);
    });
}

it('repairs a failed auto row instead of 304ing it', function () {
    $user = User::factory()->create();
    stubBackstopMenu();

    $next = app(FreshaFetch::class)->fetch(freshaRow($user, [
        'url' => 'https://www.fresha.com/a/anseo-studio-v0v92jna',
        'selection' => null,
        'connectMode' => 'auto',
    ]));

    expect($next['selection']['mode'])->toBe('storewide');
});

it('drops connectMode once the repair succeeds', function () {
    // Left behind, the row would be re-repaired on every single refresh sweep.
    $user = User::factory()->create();
    stubBackstopMenu();

    $next = app(FreshaFetch::class)->fetch(freshaRow($user, [
        'url' => 'https://www.fresha.com/a/anseo-studio-v0v92jna',
        'selection' => null,
        'connectMode' => 'auto',
    ]));

    expect($next)->not->toHaveKey('connectMode');
});

it('still 304s a team-mode row awaiting its picker', function () {
    $user = User::factory()->create();

    expect(fn () => app(FreshaFetch::class)->fetch(freshaRow($user, [
        'url' => 'https://www.fresha.com/a/anseo-studio-v0v92jna',
        'selection' => null,
    ])))->toThrow(FetchNotModifiedException::class);
});

it('still 304s when there is no url at all', function () {
    $user = User::factory()->create();

    expect(fn () => app(FreshaFetch::class)->fetch(freshaRow($user, [
        'url' => null, 'selection' => null, 'connectMode' => 'auto',
    ])))->toThrow(FetchNotModifiedException::class);
});
