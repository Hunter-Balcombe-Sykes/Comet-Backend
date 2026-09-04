<?php

use App\Models\Core\User\User;
use App\Services\Platforms\FreshaAutoSelector;
use App\Services\Platforms\FreshaScraper;
use Mockery\MockInterface;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupServicesTable();
    // Slice 7 D3a: FreshaServiceProjector::compose() reads the Fresha service
    // menu from content.* (FreshaServiceItems) instead of site.services.
    setupContentTables();
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
    $user = User::factory()->create(['first_name' => 'Prahran', 'last_name' => 'Hairdresser', 'status' => 'unclaimed']);

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
    $user = User::factory()->create(['first_name' => 'Simon', 'last_name' => 'Doyle', 'status' => 'unclaimed']);

    $result = app(FreshaAutoSelector::class)->select(
        $user,
        autoMenu([['employeeId' => 'e1', 'displayName' => 'Simon Doyle']]),
        'https://www.fresha.com/book-now/not-canonical/all-offer'
    );

    expect($result['selection']['mode'])->toBe('storewide')
        ->and($result['matchTier'])->toBe('exact'); // matched, but could not act on it
});

it('falls back to storewide when the employee menu comes back empty', function () use ($canonical) {
    $user = User::factory()->create(['first_name' => 'Simon', 'last_name' => 'Doyle', 'status' => 'unclaimed']);

    $this->mock(FreshaScraper::class, function (MockInterface $m) {
        $m->shouldReceive('slugFromUrl')->andReturn('anseo-studio-v0v92jna');
        // The slug has NOT rotated — resolving returns the same one, so the
        // rotation retry correctly declines to spend a second fetch and `once()`
        // still means what it always meant here.
        $m->shouldReceive('resolveCurrentSlug')->andReturn('anseo-studio-v0v92jna');
        $m->shouldReceive('fetchEmployeeServices')->once()->andReturn(null);
    });

    $result = app(FreshaAutoSelector::class)->select(
        $user,
        autoMenu([['employeeId' => 'e1', 'displayName' => 'Simon Doyle']]),
        $canonical
    );

    expect($result['selection']['mode'])->toBe('storewide');
});

it('writes no site.services rows — the pool is the connector\'s to fill', function () use ($canonical) {
    // Slice 7 D3a: the selector used to project the chosen menu into
    // site.services via FreshaServiceProjector::sync(). Those rows are gone;
    // the Fresha service menu lands in content.* through the ingest connector,
    // and sync() only composes. Pinned as a zero rather than deleted so a
    // reintroduced write is caught rather than silently accepted.
    $user = User::factory()->create(['first_name' => 'Prahran', 'last_name' => 'Hairdresser', 'status' => 'unclaimed']);

    app(FreshaAutoSelector::class)->select($user, autoMenu(), $canonical);

    expect(DB::table('services')->where('user_id', $user->id)->count())->toBe(0);
});

it('carries the store name onto the selection', function () use ($canonical) {
    // storeName is what the sitepage renders as the booking card heading; a null
    // here is the difference between "Anseo Studio" and a bare link.
    $user = User::factory()->create(['first_name' => 'Prahran', 'last_name' => 'Hairdresser', 'status' => 'unclaimed']);

    $result = app(FreshaAutoSelector::class)->select($user, autoMenu(), $canonical);

    expect($result['selection']['storeName'])->toBe('Anseo Studio')
        ->and($result['selection']['url'])->toBe($canonical);
});

it('retries the employee menu on the rotated slug instead of degrading to storewide', function () {
    // Measured live on dev 2026-08-19: Fresha rotated anseo-studio-v0v92jna to
    // anseo-studio-melbourne-140a-chapel-street-w8ajp04r. The storewide fetch
    // follows the rotation transparently, but the employee leg was handed
    // slugFromUrl() off the STORED url, hit the dead slug, returned no
    // categories, and silently degraded to the whole salon's "from" prices —
    // for a user the matcher had positively identified.
    //
    // matchTier 'first-exact' + mode 'storewide' is that bug's signature.
    $user = User::factory()->create(['first_name' => 'Simon', 'last_name' => 'Doyle']);

    $this->mock(FreshaScraper::class, function (MockInterface $m) {
        $m->shouldReceive('slugFromUrl')->andReturn('anseo-studio-v0v92jna');
        // The dead slug yields nothing...
        $m->shouldReceive('fetchEmployeeServices')
            ->with('anseo-studio-v0v92jna', 'e1', false)->andReturn(null);
        // ...the current one is resolvable, and serves the employee's real menu.
        $m->shouldReceive('resolveCurrentSlug')
            ->andReturn('anseo-studio-melbourne-140a-chapel-street-w8ajp04r');
        $m->shouldReceive('fetchEmployeeServices')
            ->with('anseo-studio-melbourne-140a-chapel-street-w8ajp04r', 'e1')
            ->andReturn([[
                'serviceId' => 's:9', 'name' => 'Simon Cut', 'duration' => '45min', 'description' => null,
                'price' => 'A$80', 'priceValue' => 80, 'currency' => 'AUD', 'category' => 'Hair', 'hasVariants' => false,
            ]]);
    });

    $result = app(FreshaAutoSelector::class)->select(
        $user,
        autoMenu([['employeeId' => 'e1', 'displayName' => 'Simon Doyle']]),
        'https://www.fresha.com/a/anseo-studio-v0v92jna',
    );

    expect($result['selection']['mode'])->toBe('employee');
    expect($result['matchTier'])->toBe('exact');
    expect($result['selection']['employee']['employeeId'])->toBe('e1');
});

it('still degrades to storewide when the rotated slug also has no employee menu', function () {
    // The retry is one extra attempt, not a loop. A venue that genuinely has no
    // per-employee menu must still land a WORKING storewide selection (for an
    // unclaimed build — a claimed partna defers to the picker instead, below).
    $user = User::factory()->create(['first_name' => 'Simon', 'last_name' => 'Doyle', 'status' => 'unclaimed']);

    $this->mock(FreshaScraper::class, function (MockInterface $m) {
        $m->shouldReceive('slugFromUrl')->andReturn('old-slug');
        $m->shouldReceive('resolveCurrentSlug')->andReturn('new-slug');
        $m->shouldReceive('fetchEmployeeServices')->andReturn(null);
    });

    $result = app(FreshaAutoSelector::class)->select(
        $user,
        autoMenu([['employeeId' => 'e1', 'displayName' => 'Simon Doyle']]),
        'https://www.fresha.com/a/old-slug',
    );

    expect($result['selection']['mode'])->toBe('storewide');
});

it('does not re-resolve when the stored slug already serves the employee menu', function () {
    // The retry must cost nothing on the happy path — no second outbound call
    // for the overwhelming majority of venues whose slug never moved.
    $user = User::factory()->create(['first_name' => 'Simon', 'last_name' => 'Doyle']);

    $this->mock(FreshaScraper::class, function (MockInterface $m) {
        $m->shouldReceive('slugFromUrl')->andReturn('live-slug');
        $m->shouldReceive('fetchEmployeeServices')->with('live-slug', 'e1', false)->andReturn([[
            'serviceId' => 's:9', 'name' => 'Simon Cut', 'duration' => '45min', 'description' => null,
            'price' => 'A$80', 'priceValue' => 80, 'currency' => 'AUD', 'category' => 'Hair', 'hasVariants' => false,
        ]]);
        $m->shouldReceive('resolveCurrentSlug')->never();
    });

    $result = app(FreshaAutoSelector::class)->select(
        $user,
        autoMenu([['employeeId' => 'e1', 'displayName' => 'Simon Doyle']]),
        'https://www.fresha.com/a/live-slug',
    );

    expect($result['selection']['mode'])->toBe('employee');
});

it('declines to guess for a claimed partna when nobody matches — no selection, nothing projected', function () use ($canonical) {
    // The picker-preserving degrade (2026-09-04): a whole salon's menu on an
    // individual's page misprices almost everything (22 of 23 prices were the
    // salon's "from" prices in the live case), and a claimed owner HAS a
    // picker. The caller composes the team snapshot from this null.
    $user = User::factory()->create(['first_name' => 'Prahran', 'last_name' => 'Hairdresser']);

    $result = app(FreshaAutoSelector::class)->select(
        $user,
        autoMenu([['employeeId' => 'e1', 'displayName' => 'Simon Doyle']]),
        $canonical
    );

    expect($result['selection'])->toBeNull()
        ->and($result['matchTier'])->toBeNull()
        ->and($result['suggestedEmployeeId'])->toBeNull()
        ->and($result['raw'])->toBe([]);
});

it('still hands the picker its suggestion when the match landed but the employee menu did not', function () use ($canonical) {
    // Matched-but-unfetchable is the half-way state: the person is identified,
    // only their menu is missing. The suggestion rides back so the picker opens
    // pre-highlighted and saveSelection()'s own scrape gets a second chance.
    $user = User::factory()->create(['first_name' => 'Simon', 'last_name' => 'Doyle']);

    $this->mock(FreshaScraper::class, function (MockInterface $m) {
        $m->shouldReceive('slugFromUrl')->andReturn('anseo-studio-v0v92jna');
        $m->shouldReceive('resolveCurrentSlug')->andReturn('anseo-studio-v0v92jna');
        $m->shouldReceive('fetchEmployeeServices')->once()->andReturn(null);
    });

    $result = app(FreshaAutoSelector::class)->select(
        $user,
        autoMenu([['employeeId' => 'e1', 'displayName' => 'Simon Doyle']]),
        $canonical
    );

    expect($result['selection'])->toBeNull()
        ->and($result['matchTier'])->toBe('exact')
        ->and($result['suggestedEmployeeId'])->toBe('e1');
});

it('still writes the employee selection for a claimed partna — declining is only for the storewide degrade', function () use ($canonical) {
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

    expect($result['selection']['mode'])->toBe('employee')
        ->and($result['selection']['employee']['employeeId'])->toBe('e1');
});
