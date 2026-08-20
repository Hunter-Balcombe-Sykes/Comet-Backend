<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\LinkRouter;
use App\Services\Platforms\RouteContext;
use Illuminate\Support\Facades\DB;

// M-4 (matrix run 2, chinchin live): the reservations cap arm passed origin
// 'auto' to recordCapBlock — a value routing.source_intents' origin CHECK
// has never accepted. On real Postgres the insert threw 23514, route()'s
// catch-all answered custom(), and the extra OpenTable links on the
// business's own website CARDED instead of filing the promised Swap. SQLite
// does not enforce the PG CHECK, so this pins the ORIGIN VALUE itself
// against the allowed set (mirrored from the migration) — a regression to
// any unlisted origin fails here even though SQLite would accept the row.

const SOURCE_INTENT_ORIGINS = [
    'paste', 'website_import', 'link_in_bio', 'bio_harvest',
    'google_business', 'staff', 'reproject', 'commerce_probe',
];

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
});

it('files a Swap intent with a constraint-valid origin when a second reservation link hits the family cap', function () {
    $pro = User::create([
        'handle' => 'm4-resv', 'handle_lc' => 'm4-resv', 'display_name' => 'M4',
        'first_name' => 'M4', 'account_type' => 'business', 'sector' => 'restaurant',
        'status' => 'active',
    ]);

    $incumbent = new IntegrationConnection([
        'surface_key' => 'opentable.reserve', 'routing_class' => 'reservations',
        'resource_id' => 'opentable',
        'payload' => ['url' => 'https://www.opentable.com.au/booking/restref/availability?restRef=49820', 'source' => 'google-business'],
        'is_active' => true,
    ]);
    $incumbent->user_id = $pro->id;
    $incumbent->platform = 'opentable';
    $incumbent->save();

    $result = app(LinkRouter::class)->route(
        $pro,
        'https://www.opentable.com.au/booking/restref/availability?correlationId=x&restRef=25964',
        new RouteContext,
    );

    expect($result->handled)->toBeTrue();

    $intent = DB::table('routing.source_intents')->where('user_id', $pro->id)->first();
    expect($intent)->not->toBeNull()
        ->and($intent->state)->toBe('blocked')
        ->and($intent->block_reason)->toBe('cap_reached')
        ->and($intent->conflicting_connection_id)->toBe((string) $incumbent->id)
        ->and(in_array($intent->origin, SOURCE_INTENT_ORIGINS, true))->toBeTrue();
});

it('caps the SECOND reservation link in one run instead of carding it (slot self-managed)', function () {
    // M-5: the seenPlatforms short-circuit used to card the second OpenTable
    // link before seedReservation could file its Swap — reservations manage
    // their own family slot, so both links must answer handled.
    $pro = User::create([
        'handle' => 'm5-resv', 'handle_lc' => 'm5-resv', 'display_name' => 'M5',
        'first_name' => 'M5', 'account_type' => 'business', 'sector' => 'restaurant',
        'status' => 'active',
    ]);

    $incumbent = new IntegrationConnection([
        'surface_key' => 'opentable.reserve', 'routing_class' => 'reservations',
        'resource_id' => 'opentable',
        'payload' => ['url' => 'https://www.opentable.com.au/r/chin-chin-melbourne', 'source' => 'google-business'],
        'is_active' => true,
    ]);
    $incumbent->user_id = $pro->id;
    $incumbent->platform = 'opentable';
    $incumbent->save();

    $ctx = new RouteContext;
    $first = app(LinkRouter::class)->route($pro, 'https://www.opentable.com.au/booking/restref/availability?restRef=49820', $ctx);
    $second = app(LinkRouter::class)->route($pro, 'https://www.opentable.com.au/booking/restref/availability?restRef=25964', $ctx);

    expect($first->handled)->toBeTrue()
        ->and($second->handled)->toBeTrue();

    // Both venues cap-block against the incumbent; the brand-keyed identifier
    // coalesces them into ONE Swap offer, and neither becomes a card.
    expect(DB::table('routing.source_intents')->where('user_id', $pro->id)->where('state', 'blocked')->count())
        ->toBeGreaterThanOrEqual(1);
});

it('coalesces query-variant ordering links of one store into ONE Swap, keeps distinct stores distinct', function () {
    // M-6 (critic on M-5): the ordering cap identifier was hashed from the
    // full URL, so ?pickup/?delivery variants of one store minted duplicate
    // Swap rows once M-5 let every ordering link reach the seeder.
    $pro = User::create([
        'handle' => 'm6-order', 'handle_lc' => 'm6-order', 'display_name' => 'M6',
        'first_name' => 'M6', 'account_type' => 'business', 'sector' => 'restaurant',
        'status' => 'active',
    ]);

    $incumbent = new IntegrationConnection([
        'surface_key' => 'uber_eats.order', 'routing_class' => 'ordering',
        'resource_id' => 'uber_eats',
        'payload' => ['url' => 'https://www.ubereats.com/au/store/incumbent-cafe/abc123', 'source' => 'google-business'],
        'is_active' => true,
    ]);
    $incumbent->user_id = $pro->id;
    // No ->platform assignment: 'uber_eats' is not a legacy platform key, and
    // the legacy mutator would overwrite the surface_key with the raw value.
    $incumbent->save();

    $ctx = new RouteContext;
    $router = app(LinkRouter::class);
    $router->route($pro, 'https://www.ubereats.com/au/store/other-cafe/xyz789?diningMode=PICKUP', $ctx);
    $router->route($pro, 'https://www.ubereats.com/au/store/other-cafe/xyz789?diningMode=DELIVERY', $ctx);
    $router->route($pro, 'https://www.ubereats.com/au/store/third-cafe/qqq111', $ctx);

    $identifiers = DB::table('routing.source_intents')
        ->where('user_id', $pro->id)->where('state', 'blocked')
        ->pluck('identifier');

    // 2 distinct stores → exactly 2 Swap rows; the two variants of
    // other-cafe share one identifier.
    expect($identifiers->count())->toBe(2)
        ->and($identifiers->unique()->count())->toBe(2);
});
