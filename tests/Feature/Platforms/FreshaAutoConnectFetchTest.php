<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\FreshaScraper;
use App\Services\Platforms\Strategies\Fetch\FetchUnavailableException;
use App\Services\Platforms\Strategies\Fetch\FreshaConnectFetch;
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

function autoConnectionFor(User $user): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'fresha',
        'resource_id' => 'fresha',
        'payload' => [
            'url' => 'https://www.fresha.com/a/anseo-studio-v0v92jna',
            'selection' => null,
            'source' => 'instagram',
            'connectMode' => 'auto',
        ],
        'is_active' => true,
    ]);
}

function stubMenu(array $team = []): void
{
    test()->mock(FreshaScraper::class, function (MockInterface $m) use ($team) {
        $m->shouldReceive('fetchMenu')->once()->andReturn([
            'storeName' => 'Anseo Studio',
            'team' => $team,
            'services' => [[
                'serviceId' => 's:1', 'name' => 'Cut', 'duration' => '30min', 'description' => null,
                'price' => 'A$50', 'priceValue' => 50, 'currency' => 'AUD', 'category' => 'Hair', 'hasVariants' => false,
            ]],
        ]);
        $m->shouldReceive('slugFromUrl')->andReturn('anseo-studio-v0v92jna');
        $m->shouldReceive('fetchEmployeeServices')->andReturn(null);
    });
}

it('writes a storewide selection when nobody matches', function () {
    $user = User::factory()->create(['first_name' => 'Prahran', 'last_name' => 'Hairdresser']);
    stubMenu([['employeeId' => 'e1', 'displayName' => 'Simon Doyle']]);

    $next = app(FreshaConnectFetch::class)->fetch(autoConnectionFor($user));

    expect($next['selection']['mode'])->toBe('storewide')
        ->and($next['matchTier'])->toBeNull();
});

it('selects the matched employee menu in auto mode', function () {
    $user = User::factory()->create(['first_name' => 'Simon', 'last_name' => 'Doyle']);

    test()->mock(FreshaScraper::class, function (MockInterface $m) {
        $m->shouldReceive('fetchMenu')->once()->andReturn([
            'storeName' => 'Anseo Studio',
            'team' => [['employeeId' => 'e1', 'displayName' => 'Simon Doyle']],
            'services' => [[
                'serviceId' => 's:1', 'name' => 'Cut', 'duration' => '30min', 'description' => null,
                'price' => 'A$50', 'priceValue' => 50, 'currency' => 'AUD', 'category' => 'Hair', 'hasVariants' => false,
            ]],
        ]);
        $m->shouldReceive('slugFromUrl')->andReturn('anseo-studio-v0v92jna');
        $m->shouldReceive('fetchEmployeeServices')->andReturn([[
            'serviceId' => 's:9', 'name' => 'Simon Cut', 'duration' => '45min', 'description' => null,
            'price' => 'A$80', 'priceValue' => 80, 'currency' => 'AUD', 'category' => 'Hair', 'hasVariants' => false,
        ]]);
    });

    $next = app(FreshaConnectFetch::class)->fetch(autoConnectionFor($user));

    expect($next['selection']['mode'])->toBe('employee')
        ->and($next['selection']['employee']['employeeId'])->toBe('e1')
        ->and($next['matchTier'])->toBe('exact');
});

it('strips connectMode from the persisted payload on success', function () {
    $user = User::factory()->create(['first_name' => 'Prahran', 'last_name' => 'Hairdresser']);
    stubMenu();

    $next = app(FreshaConnectFetch::class)->fetch(autoConnectionFor($user));

    // Leaving it behind strands a pending-window marker forever.
    expect($next)->not->toHaveKey('connectMode');
});

it('still honours the team and storewide modes', function (string $mode) {
    $user = User::factory()->create();
    stubMenu();
    $row = autoConnectionFor($user);
    $row->forceFill(['payload' => [...$row->payload, 'connectMode' => $mode]])->saveQuietly();

    expect(fn () => app(FreshaConnectFetch::class)->fetch($row->fresh()))->not->toThrow(Exception::class);
})->with(['storewide']);

/** Stubs the scraper and returns a counter of how many times the page was fetched. */
function countingMenuStub(): stdClass
{
    $calls = new stdClass;
    $calls->fetchMenu = 0;

    test()->mock(FreshaScraper::class, function (MockInterface $m) use ($calls) {
        $m->shouldReceive('fetchMenu')->andReturnUsing(function () use ($calls) {
            $calls->fetchMenu++;

            return [
                'storeName' => 'Anseo Studio', 'team' => [],
                'services' => [[
                    'serviceId' => 's:1', 'name' => 'Cut', 'duration' => '30min', 'description' => null,
                    'price' => 'A$50', 'priceValue' => 50, 'currency' => 'AUD', 'category' => 'Hair', 'hasVariants' => false,
                ]],
            ];
        });
        $m->shouldReceive('slugFromUrl')->andReturn('anseo-studio-v0v92jna');
        $m->shouldReceive('fetchEmployeeServices')->andReturn(null);
    });

    return $calls;
}

it('scrapes the salon page once for two auto connects at the same salon', function () {
    // A bulk Instagram build can bring several people from one salon through
    // here in the same hour. The cache is keyed by URL, not user, so they share
    // one scrape.
    $one = User::factory()->create(['first_name' => 'Ana', 'last_name' => 'Ruiz']);
    $two = User::factory()->create(['first_name' => 'Bo', 'last_name' => 'Nguyen']);
    $calls = countingMenuStub();

    app(FreshaConnectFetch::class)->fetch(autoConnectionFor($one));
    app(FreshaConnectFetch::class)->fetch(autoConnectionFor($two));

    expect($calls->fetchMenu)->toBe(1);
});

it('always re-scrapes for a dashboard storewide connect', function () {
    // The human path must never be served a cached menu: someone who just fixed
    // a price on their Fresha page and reconnected would otherwise see the
    // pre-fix menu for the rest of the TTL, with no way to force a refresh.
    $user = User::factory()->create();
    $calls = countingMenuStub();

    $row = autoConnectionFor($user);
    $row->forceFill(['payload' => [...$row->payload, 'connectMode' => 'storewide']])->saveQuietly();

    app(FreshaConnectFetch::class)->fetch($row->fresh());
    app(FreshaConnectFetch::class)->fetch($row->fresh());

    expect($calls->fetchMenu)->toBe(2);
});

it('keeps the auto projection under the booking-XOR lock guards', function () {
    // The disconnect guard lives INSIDE the locked closure. If auto mode
    // projected outside that lock, a row soft-deleted while the scrape was in
    // flight would be resurrected instead of losing to the teardown. Deleting
    // during fetchMenu() puts the row in exactly that state by lock time.
    $user = User::factory()->create(['first_name' => 'Prahran', 'last_name' => 'Hairdresser']);
    $row = autoConnectionFor($user);

    test()->mock(FreshaScraper::class, function (MockInterface $m) use ($row) {
        $m->shouldReceive('fetchMenu')->once()->andReturnUsing(function () use ($row) {
            $row->delete();

            return [
                'storeName' => 'Anseo Studio',
                'team' => [],
                'services' => [[
                    'serviceId' => 's:1', 'name' => 'Cut', 'duration' => '30min', 'description' => null,
                    'price' => 'A$50', 'priceValue' => 50, 'currency' => 'AUD', 'category' => 'Hair', 'hasVariants' => false,
                ]],
            ];
        });
        $m->shouldReceive('slugFromUrl')->andReturn('anseo-studio-v0v92jna');
        $m->shouldReceive('fetchEmployeeServices')->andReturn(null);
    });

    expect(fn () => app(FreshaConnectFetch::class)->fetch($row))
        ->toThrow(FetchUnavailableException::class);

    expect(DB::table('services')->where('user_id', $user->id)->count())->toBe(0);
});
