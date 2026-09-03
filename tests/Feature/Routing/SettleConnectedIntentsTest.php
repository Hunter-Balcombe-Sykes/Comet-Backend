<?php

// routing:settle-connected — close the ledger row for an account the user
// connected some OTHER way (the connect sheet, an OAuth return).
//
// SuggestionsController::index() already HIDES these cards, but hiding is not
// closing: the row stays 'proposed' forever, and CheckStuckSourceIntentsCommand
// counts exactly that state pair. So the LIFE-19 backlog alarm drifted upward
// with no way back down — nobody can answer a card they cannot see. This is the
// write half, given a home where a write belongs.

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
});

function settleIntent(User $user, array $overrides = []): string
{
    $id = (string) Str::uuid();
    DB::table('routing.source_intents')->insert(array_merge([
        'id' => $id,
        'user_id' => $user->id,
        'surface_key' => 'shopify.store',
        'routing_class' => 'shop',
        'identifier' => '11461296187',
        'canonical_url' => 'https://natalieanne.com/',
        'state' => 'proposed',
        'block_reason' => 'needs_confirmation',
        'origin' => 'commerce_probe',
        'first_seen_at' => now()->subDays(3),
        'created_at' => now()->subDays(3),
        'updated_at' => now()->subDays(3),
    ], $overrides));

    return $id;
}

function connect(User $user, string $surface, string $resourceId, string $platform = 'shopify'): IntegrationConnection
{
    return IntegrationConnection::query()->create([
        'user_id' => $user->id, 'platform' => $platform, 'surface_key' => $surface,
        'routing_class' => 'shop', 'resource_id' => $resourceId, 'payload' => [],
    ]);
}

it('settles a proposed intent whose account is already connected', function () {
    $pro = createTenant('settle-basic');
    // Connection first, intent after: a live connect now settles its own
    // intent at the observer (A.9), so the command's population is rows that
    // arrived AFTER the connect — the backfill this sweep exists for.
    $conn = connect($pro, 'shopify.store', '11461296187');
    $intentId = settleIntent($pro);

    $this->artisan('routing:settle-connected')->assertSuccessful();

    $row = DB::table('routing.source_intents')->where('id', $intentId)->first();
    expect($row->state)->toBe('superseded')
        ->and($row->connection_id)->toBe($conn->id)
        ->and($row->resolved_at)->not->toBeNull();
});

it('leaves an intent alone when the account is not connected', function () {
    $pro = createTenant('settle-untouched');
    $intentId = settleIntent($pro);
    connect($pro, 'shopify.store', 'a-different-store');

    $this->artisan('routing:settle-connected')->assertSuccessful();

    expect(DB::table('routing.source_intents')->where('id', $intentId)->value('state'))->toBe('proposed');
});

it('settles a blocked intent too, not only a proposed one', function () {
    // A cap_reached row naming an account the user has since connected is the
    // same stale question wearing a different reason.
    $pro = createTenant('settle-blocked');
    connect($pro, 'shopify.store', '11461296187');
    $intentId = settleIntent($pro, ['state' => 'blocked', 'block_reason' => 'cap_reached']);

    $this->artisan('routing:settle-connected')->assertSuccessful();

    expect(DB::table('routing.source_intents')->where('id', $intentId)->value('state'))->toBe('superseded');
});

it('folds case on the surfaces whose platform does (M-7)', function () {
    $pro = createTenant('settle-case');
    connect($pro, 'tiktok.profile', 'stali', 'tiktok');
    $intentId = settleIntent($pro, [
        'surface_key' => 'tiktok.profile', 'routing_class' => 'social',
        'identifier' => 'STALi', 'canonical_url' => 'https://www.tiktok.com/@STALi',
    ]);

    $this->artisan('routing:settle-connected')->assertSuccessful();

    expect(DB::table('routing.source_intents')->where('id', $intentId)->value('state'))->toBe('superseded');
});

it('never touches a settled row', function () {
    $pro = createTenant('settle-already');
    $applied = settleIntent($pro, ['state' => 'applied', 'block_reason' => null]);
    $dismissed = settleIntent($pro, ['identifier' => 'other', 'state' => 'dismissed']);
    connect($pro, 'shopify.store', '11461296187');
    connect($pro, 'shopify.store', 'other');

    $this->artisan('routing:settle-connected')->assertSuccessful();

    expect(DB::table('routing.source_intents')->where('id', $applied)->value('state'))->toBe('applied')
        ->and(DB::table('routing.source_intents')->where('id', $dismissed)->value('state'))->toBe('dismissed');
});

it('writes nothing under --dry-run, and still reports what it would close', function () {
    $pro = createTenant('settle-dry');
    connect($pro, 'shopify.store', '11461296187');
    $intentId = settleIntent($pro);

    $this->artisan('routing:settle-connected', ['--dry-run' => true])
        ->expectsOutputToContain('1')
        ->assertSuccessful();

    expect(DB::table('routing.source_intents')->where('id', $intentId)->value('state'))->toBe('proposed');
});

it('does not settle another tenant\'s intent from this tenant\'s connection', function () {
    $mine = createTenant('settle-mine');
    $theirs = createTenant('settle-theirs');
    $intentId = settleIntent($theirs);
    connect($mine, 'shopify.store', '11461296187');

    $this->artisan('routing:settle-connected')->assertSuccessful();

    expect(DB::table('routing.source_intents')->where('id', $intentId)->value('state'))->toBe('proposed');
});
