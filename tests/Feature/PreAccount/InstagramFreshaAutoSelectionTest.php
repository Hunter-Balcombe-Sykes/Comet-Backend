<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\FreshaScraper;
use App\Services\Platforms\LinkRouter;
use App\Services\Platforms\RouteContext;
use App\Services\Profile\PersonNameParser;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupServicesTable();
    setupServiceCategoriesTable();
    setupIntegrationConnectionsTable();
    // Slice 7 D3a: FreshaAutoSelector composes the selection through
    // FreshaServiceProjector, which reads the Fresha menu from content.*.
    setupContentTables();
    shimPgAdvisoryLockForSqlite();
    Http::fake();
});

it('matches the account holder to their salon profile end to end', function () {
    // The name arrives exactly as Instagram supplies it — tagline and all.
    $parsed = PersonNameParser::parse('SIMON DOYLE | Barber & Educator');
    $user = User::factory()->create([
        'account_type' => 'partna',
        'display_name' => $parsed['displayName'],
        'first_name' => $parsed['firstName'],
        'last_name' => $parsed['lastName'],
    ]);

    $this->mock(FreshaScraper::class, function (MockInterface $m) {
        $m->shouldReceive('canonicalUrl')->andReturnUsing(fn (string $u): string => 'https://www.fresha.com/a/anseo-studio-v0v92jna');
        $m->shouldReceive('fetchMenu')->andReturn([
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

    app(LinkRouter::class)->route(
        $user,
        'https://www.fresha.com/book-now/anseo-studio-v0v92jna/all-offer?share=true&pId=2835260',
        new RouteContext(autoConnectBooking: true)
    );

    $payload = IntegrationConnection::where('user_id', $user->id)->where('platform', 'fresha')->firstOrFail()->fresh()->payload;

    // Proves the whole chain: canonicalise -> seed -> dispatch -> auto fetch ->
    // match -> employee menu -> projected selection on the row.
    //
    // NOTE this does NOT guard the Task 2 fold ORDERING — it sets the name
    // columns directly rather than running InstagramSourceGenerator. The
    // ordering is pinned structurally by InstagramIdentityFoldTest; what THIS
    // pins is that populated name columns actually reach the matcher, which the
    // companion case below makes non-vacuous.
    expect($payload['selection']['mode'])->toBe('employee')
        ->and($payload['matchTier'])->toBe('exact');
});

it('degrades to storewide when the surname never made it onto the user', function () {
    // The exact state a fold-after-seed() regression produces at routing time.
    // Without this case the test above could pass for reasons unrelated to the
    // name ever being read.
    $user = User::factory()->create([
        'account_type' => 'partna',
        'display_name' => 'SIMON DOYLE | Barber & Educator',
        'first_name' => 'SIMON DOYLE | Barber & Educator',
        'last_name' => null,
    ]);

    $this->mock(FreshaScraper::class, function (MockInterface $m) {
        $m->shouldReceive('canonicalUrl')->andReturn('https://www.fresha.com/a/anseo-studio-v0v92jna');
        $m->shouldReceive('fetchMenu')->andReturn([
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
        $m->shouldReceive('fetchEmployeeServices')->andReturn(null);
    });

    app(LinkRouter::class)->route(
        $user,
        'https://www.fresha.com/book-now/anseo-studio-v0v92jna/all-offer',
        new RouteContext(autoConnectBooking: true)
    );

    $payload = IntegrationConnection::where('user_id', $user->id)->where('platform', 'fresha')->firstOrFail()->fresh()->payload;

    expect($payload['selection']['mode'])->toBe('storewide')
        ->and($payload['matchTier'])->toBeNull();
});

it('makes no outbound fresha calls anywhere in the suite path', function () {
    $user = User::factory()->create(['account_type' => 'partna']);

    $this->mock(FreshaScraper::class, function (MockInterface $m) {
        $m->shouldReceive('canonicalUrl')->andReturn('https://www.fresha.com/a/x');
        $m->shouldReceive('fetchMenu')->andReturn(['storeName' => null, 'team' => [], 'services' => []]);
    });

    app(LinkRouter::class)->route($user, 'https://www.fresha.com/a/x', new RouteContext(autoConnectBooking: true));

    Http::assertNothingSent();
});
