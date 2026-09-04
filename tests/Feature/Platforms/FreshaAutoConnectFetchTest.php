<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\FreshaScraper;
use App\Services\Platforms\Strategies\Fetch\FetchNotModifiedException;
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
        $m->shouldReceive('lastResolvedSlug')->andReturn(null);
        $m->shouldReceive('resolveCurrentSlug')->andReturn(null);
        $m->shouldReceive('fetchEmployeeServices')->andReturn(null);
    });
}

it('writes a storewide selection when nobody matches on an unclaimed build', function () {
    $user = User::factory()->create(['first_name' => 'Prahran', 'last_name' => 'Hairdresser', 'status' => 'unclaimed']);
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
        $m->shouldReceive('lastResolvedSlug')->andReturn(null);
        $m->shouldReceive('resolveCurrentSlug')->andReturn(null);
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
        $m->shouldReceive('lastResolvedSlug')->andReturn(null);
        $m->shouldReceive('resolveCurrentSlug')->andReturn(null);
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
        $m->shouldReceive('lastResolvedSlug')->andReturn(null);
        $m->shouldReceive('resolveCurrentSlug')->andReturn(null);
        $m->shouldReceive('fetchEmployeeServices')->andReturn(null);
    });

    expect(fn () => app(FreshaConnectFetch::class)->fetch($row))
        ->toThrow(FetchUnavailableException::class);

    expect(DB::table('services')->where('user_id', $user->id)->count())->toBe(0);
});

it('marks an auto-chosen selection so the owner can be asked to confirm it', function () {
    // The marker is what makes "we guessed, and nobody has confirmed it" legible
    // at claim time. matchTier alone cannot carry it: a null tier means "storewide
    // because nothing matched", which is indistinguishable from a storewide the
    // owner deliberately chose in the picker.
    $user = User::factory()->create(['first_name' => 'Prahran', 'last_name' => 'Hairdresser', 'status' => 'unclaimed']);
    stubMenu([['employeeId' => 'e1', 'displayName' => 'Simon Doyle']]);

    $next = app(FreshaConnectFetch::class)->fetch(autoConnectionFor($user));

    expect($next['selection']['mode'])->toBe('storewide')
        ->and($next['autoSelected'])->toBeTrue()
        ->and($next['matchTier'])->toBeNull();
});

it('marks an auto-chosen EMPLOYEE selection too, not just the storewide fallback', function () {
    // A confident match is still a machine's guess — the tier is evidence, not
    // consent. Pinned separately because stamping the marker only on the fallback
    // would look correct in the storewide test above and silently skip every
    // account the matcher actually recognised.
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
        $m->shouldReceive('lastResolvedSlug')->andReturn(null);
        $m->shouldReceive('resolveCurrentSlug')->andReturn(null);
        $m->shouldReceive('fetchEmployeeServices')->andReturn([[
            'serviceId' => 's:9', 'name' => 'Simon Cut', 'duration' => '45min', 'description' => null,
            'price' => 'A$80', 'priceValue' => 80, 'currency' => 'AUD', 'category' => 'Hair', 'hasVariants' => false,
        ]]);
    });

    $next = app(FreshaConnectFetch::class)->fetch(autoConnectionFor($user));

    expect($next['selection']['mode'])->toBe('employee')
        ->and($next['autoSelected'])->toBeTrue()
        ->and($next['matchTier'])->toBe('exact');
});

it('never stamps the marker on a dashboard storewide connect', function () {
    // The negative half. A human who picked storewide in the picker must not be
    // asked to confirm a choice they made themselves.
    $user = User::factory()->create(['first_name' => 'Simon', 'last_name' => 'Doyle']);
    stubMenu();

    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'fresha',
        'resource_id' => 'fresha',
        'payload' => [
            'url' => 'https://www.fresha.com/a/anseo-studio-v0v92jna',
            'selection' => null,
            'connectMode' => 'storewide',
        ],
        'is_active' => true,
    ]);

    $next = app(FreshaConnectFetch::class)->fetch($connection);

    expect($next['selection']['mode'])->toBe('storewide')
        ->and($next)->not->toHaveKey('autoSelected')
        ->and($next)->not->toHaveKey('matchTier');
});

it('persists the rotated slug so the stale one stops being the starting point', function () {
    // Fresha rotates venue slugs. fetchLocation() absorbs that transparently and
    // reports what it landed on via lastResolvedSlug(); the dashboard lane has
    // always written that back, this lane never did — so a rotated venue
    // re-resolved on every refresh and kept feeding the employee leg a dead slug.
    // (Unclaimed: the no-match outcome must still land a storewide SELECTION for
    // this pin to read the rewritten url off it.)
    $user = User::factory()->create(['first_name' => 'Simon', 'last_name' => 'Doyle', 'status' => 'unclaimed']);

    test()->mock(FreshaScraper::class, function (MockInterface $m) {
        $m->shouldReceive('fetchMenu')->once()->andReturn([
            'storeName' => 'Anseo Studio',
            'team' => [],
            'services' => [[
                'serviceId' => 's:1', 'name' => 'Cut', 'duration' => '30min', 'description' => null,
                'price' => 'A$50', 'priceValue' => 50, 'currency' => 'AUD', 'category' => 'Hair', 'hasVariants' => false,
            ]],
        ]);
        $m->shouldReceive('slugFromUrl')->andReturn('anseo-studio-v0v92jna');
        $m->shouldReceive('lastResolvedSlug')->andReturn('anseo-studio-melbourne-140a-chapel-street-w8ajp04r');
        $m->shouldReceive('fetchEmployeeServices')->andReturn(null);
        $m->shouldReceive('resolveCurrentSlug')->andReturn('anseo-studio-melbourne-140a-chapel-street-w8ajp04r');
    });

    $next = app(FreshaConnectFetch::class)->fetch(autoConnectionFor($user));

    expect($next['url'])->toBe('https://www.fresha.com/a/anseo-studio-melbourne-140a-chapel-street-w8ajp04r');
    expect($next['selection']['url'])->toBe('https://www.fresha.com/a/anseo-studio-melbourne-140a-chapel-street-w8ajp04r');
});

it('leaves the url alone when nothing rotated', function () {
    // The happy path must not rewrite anything: lastResolvedSlug() is null both
    // when the slug is current AND on a menu-cache hit where fetchMenu never ran.
    $user = User::factory()->create(['first_name' => 'Simon', 'last_name' => 'Doyle']);
    test()->mock(FreshaScraper::class, function (MockInterface $m) {
        $m->shouldReceive('fetchMenu')->andReturn([
            'storeName' => 'Anseo Studio',
            'team' => [],
            'services' => [[
                'serviceId' => 's:1', 'name' => 'Cut', 'duration' => '30min', 'description' => null,
                'price' => 'A$50', 'priceValue' => 50, 'currency' => 'AUD', 'category' => 'Hair', 'hasVariants' => false,
            ]],
        ]);
        $m->shouldReceive('slugFromUrl')->andReturn('anseo-studio-v0v92jna');
        $m->shouldReceive('lastResolvedSlug')->andReturn(null);
        $m->shouldReceive('fetchEmployeeServices')->andReturn(null);
        $m->shouldReceive('resolveCurrentSlug')->andReturn(null);
    });

    $next = app(FreshaConnectFetch::class)->fetch(autoConnectionFor($user));

    expect($next['url'])->toBe('https://www.fresha.com/a/anseo-studio-v0v92jna');
});

it('falls back to the team snapshot for a claimed partna nobody matched — the picker stays theirs', function () {
    // 2026-09-04: the accept lane now runs auto mode for claimed in-setup
    // users. When FreshaAutoSelector declines to guess, this branch persists
    // exactly what team mode would have: the menu snapshot the picker reads,
    // no selection, no autoSelected marker — so the Get Started walk renders
    // "Which one is you?" instead of a whole salon's understated prices.
    $user = User::factory()->create(['first_name' => 'Prahran', 'last_name' => 'Hairdresser']);
    stubMenu([['employeeId' => 'e1', 'displayName' => 'Simon Doyle']]);

    $next = app(FreshaConnectFetch::class)->fetch(autoConnectionFor($user));

    expect($next['selection'] ?? null)->toBeNull()
        ->and($next['teamMenu']['team'][0]['employeeId'])->toBe('e1')
        ->and($next['teamMenu'])->not->toHaveKey('suggestedEmployeeId')
        ->and($next)->not->toHaveKey('autoSelected')
        ->and($next)->not->toHaveKey('connectMode');
});

it('pre-highlights the picker when the match landed but the employee menu did not', function () {
    $user = User::factory()->create(['first_name' => 'Simon', 'last_name' => 'Doyle']);
    stubMenu([['employeeId' => 'e1', 'displayName' => 'Simon Doyle']]);

    $next = app(FreshaConnectFetch::class)->fetch(autoConnectionFor($user));

    expect($next['selection'] ?? null)->toBeNull()
        ->and($next['teamMenu']['suggestedEmployeeId'])->toBe('e1');
});

it('stands down when a human pick landed while the auto scrape was in flight', function () {
    // The accept-lane dispatch races the walk's own picker: saveSelection()
    // replaces the payload under the booking-XOR lock, and a machine guess
    // arriving second must lose. NotModified, not a write — the row is healthy
    // and it is the person's.
    $user = User::factory()->create(['first_name' => 'Simon', 'last_name' => 'Doyle']);
    $row = autoConnectionFor($user);

    test()->mock(FreshaScraper::class, function (MockInterface $m) use ($row) {
        $m->shouldReceive('fetchMenu')->once()->andReturnUsing(function () use ($row) {
            $row->forceFill(['payload' => [...$row->payload, 'selection' => [
                'url' => 'https://www.fresha.com/a/anseo-studio-v0v92jna',
                'storeName' => 'Anseo Studio', 'mode' => 'employee',
                'employee' => ['employeeId' => 'e2', 'displayName' => 'Someone Else'],
                'services' => [], 'hiddenServiceIds' => [],
            ]]])->saveQuietly();

            return [
                'storeName' => 'Anseo Studio',
                'team' => [['employeeId' => 'e1', 'displayName' => 'Simon Doyle']],
                'services' => [[
                    'serviceId' => 's:1', 'name' => 'Cut', 'duration' => '30min', 'description' => null,
                    'price' => 'A$50', 'priceValue' => 50, 'currency' => 'AUD', 'category' => 'Hair', 'hasVariants' => false,
                ]],
            ];
        });
        $m->shouldReceive('slugFromUrl')->andReturn('anseo-studio-v0v92jna');
        $m->shouldReceive('lastResolvedSlug')->andReturn(null);
        $m->shouldReceive('resolveCurrentSlug')->andReturn(null);
        $m->shouldReceive('fetchEmployeeServices')->andReturn(null);
    });

    expect(fn () => app(FreshaConnectFetch::class)->fetch($row))
        ->toThrow(FetchNotModifiedException::class);

    expect($row->fresh()->payload['selection']['employee']['employeeId'])->toBe('e2');
});
