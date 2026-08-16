<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Routing\RoutingCapabilityGate;
use App\Services\Accounts\AccountCapabilities;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Convergence Phase 6 unit 6. Two invariants, both of which the phase rests on
// and neither of which any other test states.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function retiredGuardUser(string $h, string $accountType = 'business', ?string $sector = 'restaurant'): User
{
    return User::create([
        'handle' => $h, 'handle_lc' => strtolower($h), 'display_name' => ucfirst($h),
        'first_name' => ucfirst($h), 'account_type' => $accountType, 'sector' => $sector,
        'auth_user_id' => (string) Str::uuid(), 'primary_email' => "{$h}@example.com",
    ]);
}

// ── The retirement guard ─────────────────────────────────────────────────────

it('refuses to CREATE a connection on any retired pseudo-platform surface', function (string $surface) {
    $user = retiredGuardUser('guard'.substr(sha1($surface), 0, 6));

    expect(fn () => IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => $surface,
        'resource_id' => 'x',
        'payload' => ['url' => 'https://example.com'],
    ]))->toThrow(ValidationException::class);

    expect(IntegrationConnection::withTrashed()->where('user_id', $user->id)->exists())->toBeFalse();
})->with(IntegrationConnection::RETIRED_SURFACES);

it('refuses the LEGACY SLUG too, not just the surface key', function () {
    // The mutator resolves 'online-ordering' to partna.order_link, so a caller
    // that never learned the new vocabulary is refused on the same grounds.
    $user = retiredGuardUser('guardslug');

    expect(fn () => IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'online-ordering',
        'resource_id' => 'order-x', 'payload' => ['url' => 'https://example.com'],
    ]))->toThrow(ValidationException::class);
});

it('still allows UPDATES to a pre-migration row — the retirement command needs them', function () {
    $user = retiredGuardUser('guardupd');

    // Raw insert: a row that predates the guard is the only way one exists.
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('site.platform_connections')->insert([
        'id' => $id, 'user_id' => $user->id, 'surface_key' => 'partna.order_link',
        'routing_class' => 'ordering', 'resource_id' => 'order-x',
        'payload' => json_encode(['url' => 'https://example.com']), 'is_active' => 1,
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);

    $row = IntegrationConnection::findOrFail($id);

    // The two writes the migration actually makes: repoint, and retire.
    $row->surface_key = 'uber_eats.order';
    $row->save();
    expect($row->fresh()->surface_key)->toBe('uber_eats.order');

    $row->delete();
    expect(IntegrationConnection::withTrashed()->find($id)->trashed())->toBeTrue();
});

it('leaves partna.manual_product connectable — hidden and dormant is not retired', function () {
    // §16 reserves it as the manual product add-path, and convergence Phase 6
    // anchors the shop's individual-products bucket there. Conflating "hidden"
    // with "retired" would close a lane nobody decided to close.
    $user = retiredGuardUser('guardmp');

    expect(in_array('partna.manual_product', IntegrationConnection::RETIRED_SURFACES, true))->toBeFalse();

    $row = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'partna.manual_product',
        'resource_id' => 'individual', 'payload' => ['storage' => 'relational'],
    ]);

    expect($row->surface_key)->toBe('partna.manual_product');
});

// ── RoutingCapabilityGate keys on routing_class ──────────────────────────────

it('RoutingCapabilityGate keys on routing_class, so gating cannot weaken when a brand splits off', function () {
    // The phase moved every booking/reservation/ordering link off one shared
    // pseudo-platform onto its own brand surface. That is only safe because the
    // gate never saw the platform in the first place — it answers on the CLASS,
    // which travels with surface_key on every row by construction. A gate that
    // keyed on platform would have silently stopped denying the moment the first
    // brand split off, and nothing else in the suite says so.
    $partna = retiredGuardUser('gaterp', 'partna', null);

    $capabilities = AccountCapabilities::for($partna);
    // Establishes the fixture really is denied something — without this the
    // assertions below could pass against an account that is allowed everything.
    expect($capabilities->can_use_online_ordering)->toBeFalse();

    foreach (['ordering', 'uber_eats.order', 'doordash.order', 'bopple.order'] as $key) {
        $denial = RoutingCapabilityGate::denialFor($partna, $key);

        // Only the CLASS produces a denial. A surface key falls to the default
        // arm and answers null — which is the proof: every caller must hand it
        // routing_class, and every row carries one.
        $key === 'ordering'
            ? expect($denial)->toBe('online ordering is not available for this account')
            : expect($denial)->toBeNull();
    }
});

it('RoutingCapabilityGate answers identically for every brand in a class', function () {
    // The load-bearing consequence: two rows in the same class get the same
    // answer regardless of which brand they carry, so splitting a shared key
    // into N brand surfaces cannot change who is allowed what.
    $foodBusiness = retiredGuardUser('gatefood', 'business', 'restaurant');
    $nonFood = retiredGuardUser('gatenonfood', 'business', 'barber');

    foreach (['booking', 'reservations', 'ordering'] as $class) {
        expect(RoutingCapabilityGate::denialFor($foodBusiness, $class))
            ->toBe(RoutingCapabilityGate::denialFor($foodBusiness, $class));
    }

    // A food business is denied booking and allowed reservations; a non-food
    // business is the mirror. Pinned so the gate's answers are real, not vacuous.
    expect(RoutingCapabilityGate::denialFor($foodBusiness, 'booking'))->not->toBeNull();
    expect(RoutingCapabilityGate::denialFor($foodBusiness, 'reservations'))->toBeNull();
    expect(RoutingCapabilityGate::denialFor($nonFood, 'booking'))->toBeNull();
    expect(RoutingCapabilityGate::denialFor($nonFood, 'reservations'))->not->toBeNull();
});
