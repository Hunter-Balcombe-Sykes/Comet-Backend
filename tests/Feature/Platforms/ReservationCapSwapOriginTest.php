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
