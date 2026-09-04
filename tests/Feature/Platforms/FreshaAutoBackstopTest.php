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
    // Slice 7 D3a: FreshaServiceProjector::compose() reads the Fresha service
    // menu from content.* (FreshaServiceItems) instead of site.services.
    setupContentTables();
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
    // Unclaimed: the storewide repair is the unclaimed outcome — a claimed
    // partna's repair persists the team snapshot instead (below).
    $user = User::factory()->create(['status' => 'unclaimed']);
    stubBackstopMenu();

    $next = app(FreshaFetch::class)->fetch(freshaRow($user, [
        'url' => 'https://www.fresha.com/a/anseo-studio-v0v92jna',
        'selection' => null,
        'connectMode' => 'auto',
    ]));

    expect($next['selection']['mode'])->toBe('storewide');
});

it('repairs a claimed partna auto row to the team snapshot — the picker stays theirs', function () {
    // The self-heal mirrors FreshaConnectFetch's picker-preserving degrade
    // (2026-09-04): nobody matched, so no selection is guessed; the persisted
    // teamMenu makes the row a team-mode one awaiting its picker, and
    // connectMode still drops so the sweep stops re-repairing it.
    $user = User::factory()->create();
    stubBackstopMenu();

    $next = app(FreshaFetch::class)->fetch(freshaRow($user, [
        'url' => 'https://www.fresha.com/a/anseo-studio-v0v92jna',
        'selection' => null,
        'connectMode' => 'auto',
    ]));

    expect($next['selection'] ?? null)->toBeNull()
        ->and($next['teamMenu']['storeName'])->toBe('Anseo Studio')
        ->and($next)->not->toHaveKey('connectMode');
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
