<?php

use App\Models\Core\User\User;
use App\Services\Platforms\FreshaAutoSelector;
use App\Services\Platforms\FreshaScraper;
use Mockery\MockInterface;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupServicesTable();
    setupServiceCategoriesTable();
    shimPgAdvisoryLockForSqlite();
});

function autoMenu(array $team = [], ?string $store = 'Anseo Studio'): array
{
    return [
        'storeName' => $store,
        'team' => $team,
        'services' => [[
            'serviceId' => 's:1', 'name' => 'Cut', 'duration' => '30min', 'description' => null,
            'price' => 'A$50', 'priceValue' => 50, 'currency' => 'AUD', 'category' => 'Hair', 'hasVariants' => false,
        ]],
    ];
}

$canonical = 'https://www.fresha.com/a/anseo-studio-v0v92jna';

it('selects the matched employee menu', function () use ($canonical) {
    $user = User::factory()->create(['first_name' => 'Simon', 'last_name' => 'Doyle']);

    $this->mock(FreshaScraper::class, function (MockInterface $m) {
        $m->shouldReceive('slugFromUrl')->andReturn('anseo-studio-v0v92jna');
        $m->shouldReceive('fetchEmployeeServices')->once()->andReturn([[
            'serviceId' => 's:9', 'name' => 'Simon Cut', 'duration' => '45min', 'description' => null,
            'price' => 'A$80', 'priceValue' => 80, 'currency' => 'AUD', 'category' => 'Hair', 'hasVariants' => false,
        ]]);
    });

    $result = app(FreshaAutoSelector::class)->select(
        $user,
        autoMenu([['employeeId' => 'e1', 'displayName' => 'Simon Doyle']]),
        $canonical
    );

    expect($result['matchTier'])->toBe('exact')
        ->and($result['selection']['mode'])->toBe('employee')
        ->and($result['selection']['employee'])->toBeArray()
        ->and($result['selection']['employee']['employeeId'])->toBe('e1');
});

it('falls back to storewide when nothing matches', function () use ($canonical) {
    $user = User::factory()->create(['first_name' => 'Prahran', 'last_name' => 'Hairdresser']);

    $result = app(FreshaAutoSelector::class)->select(
        $user,
        autoMenu([['employeeId' => 'e1', 'displayName' => 'Simon Doyle']]),
        $canonical
    );

    expect($result['matchTier'])->toBeNull()
        ->and($result['selection']['mode'])->toBe('storewide')
        ->and($result['selection']['employee'])->toBeNull();
});

it('falls back to storewide when the slug cannot be extracted', function () {
    $user = User::factory()->create(['first_name' => 'Simon', 'last_name' => 'Doyle']);

    $result = app(FreshaAutoSelector::class)->select(
        $user,
        autoMenu([['employeeId' => 'e1', 'displayName' => 'Simon Doyle']]),
        'https://www.fresha.com/book-now/not-canonical/all-offer'
    );

    expect($result['selection']['mode'])->toBe('storewide')
        ->and($result['matchTier'])->toBe('exact'); // matched, but could not act on it
});

it('falls back to storewide when the employee menu comes back empty', function () use ($canonical) {
    $user = User::factory()->create(['first_name' => 'Simon', 'last_name' => 'Doyle']);

    $this->mock(FreshaScraper::class, function (MockInterface $m) {
        $m->shouldReceive('slugFromUrl')->andReturn('anseo-studio-v0v92jna');
        $m->shouldReceive('fetchEmployeeServices')->once()->andReturn(null);
    });

    $result = app(FreshaAutoSelector::class)->select(
        $user,
        autoMenu([['employeeId' => 'e1', 'displayName' => 'Simon Doyle']]),
        $canonical
    );

    expect($result['selection']['mode'])->toBe('storewide');
});

it('projects the chosen services into site.services', function () use ($canonical) {
    $user = User::factory()->create(['first_name' => 'Prahran', 'last_name' => 'Hairdresser']);

    app(FreshaAutoSelector::class)->select($user, autoMenu(), $canonical);

    expect(DB::table('services')->where('user_id', $user->id)->count())->toBe(1);
});

it('carries the store name onto the selection', function () use ($canonical) {
    // storeName is what the sitepage renders as the booking card heading; a null
    // here is the difference between "Anseo Studio" and a bare link.
    $user = User::factory()->create(['first_name' => 'Prahran', 'last_name' => 'Hairdresser']);

    $result = app(FreshaAutoSelector::class)->select($user, autoMenu(), $canonical);

    expect($result['selection']['storeName'])->toBe('Anseo Studio')
        ->and($result['selection']['url'])->toBe($canonical);
});
