<?php

// Endpoint-level capability gating (2026-07-15 industry/sector gating). Each
// gated controller already has its own dedicated mechanics test file (MenuTest,
// OnlineOrderingControllerTest, ReservationsControllerTest, BookingControllerTest,
// FreshaPayloadTest, SquareConnectionTest, ReservationProvidersTest) — this file
// is the cross-cutting capability-matrix pass: 403 for a disallowed account, 2xx
// for an allowed one, on every mutating/connect path AccountCapabilities gates.
// Disconnect/delete + read endpoints are proven OPEN here too (spot checks) —
// the full mechanics for each stay in their own dedicated files.

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\FreshaScraper;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // Fresha connect (storewide) projects services into site.services now.
    setupServicesTable();
    shimPgAdvisoryLockForSqlite();
});

function gateUser(string $h, string $accountType, ?string $sector = null): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => $accountType,
        'sector' => $sector,
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

// ── Menu (can_use_menu = business && food) ──────────────────────────────────

it('403s menu refresh for a non-food (barbershop) business', function () {
    $user = gateUser('gate-menu-1', 'business', 'barber');

    actingAsUser($user)->postJson('/api/platforms/menu/refresh')
        ->assertStatus(403)
        ->assertJsonPath('message', 'Menu is not available for your account.');
});

it('403s menu refresh for partna (menu is never available to partna)', function () {
    $user = gateUser('gate-menu-2', 'partna', 'restaurant');

    actingAsUser($user)->postJson('/api/platforms/menu/refresh')->assertStatus(403);
});

it('allows menu refresh for a food (restaurant) business', function () {
    Queue::fake();
    $user = gateUser('gate-menu-3', 'business', 'restaurant');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'online-ordering', 'resource_id' => 'order-1',
        'payload' => ['url' => 'https://www.ubereats.com/store/x', 'provider' => 'custom', 'source' => 'manual'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)->postJson('/api/platforms/menu/refresh')
        ->assertOk()
        ->assertJsonPath('fetchStatus', 'pending');
});

it('403s menu scan/apply for a non-food business', function () {
    $user = gateUser('gate-menu-4', 'business', 'barber');

    actingAsUser($user)->postJson('/api/platforms/menu/scan/apply', [
        'items' => [['name' => 'Test dish', 'description' => null, 'price' => 10, 'category' => null]],
    ])->assertStatus(403);
});

it('allows menu scan/apply for a food business', function () {
    $user = gateUser('gate-menu-5', 'business', 'restaurant');

    actingAsUser($user)->postJson('/api/platforms/menu/scan/apply', [
        'items' => [['name' => 'Test dish', 'description' => null, 'price' => 10, 'category' => null]],
    ])->assertOk();
});

it('leaves menu status()/show() open regardless of capability', function () {
    $user = gateUser('gate-menu-6', 'partna', null);

    actingAsUser($user)->getJson('/api/platforms/menu/status')->assertOk();
    actingAsUser($user)->getJson('/api/platforms/menu')->assertOk();
});

// ── Online ordering (can_use_online_ordering = business && food) ────────────

it('403s online-ordering add for partna (explicit owner override — partna never gets it)', function () {
    $user = gateUser('gate-oo-1', 'partna', 'restaurant');

    actingAsUser($user)
        ->postJson('/api/platforms/online-ordering/entries', ['url' => 'https://www.ubereats.com/store/x'])
        ->assertStatus(403)
        ->assertJsonPath('message', 'Online ordering is not available for your account.');
});

it('403s online-ordering add for a non-food business', function () {
    $user = gateUser('gate-oo-2', 'business', 'barber');

    actingAsUser($user)
        ->postJson('/api/platforms/online-ordering/entries', ['url' => 'https://www.ubereats.com/store/x'])
        ->assertStatus(403);
});

it('allows online-ordering add for a food business', function () {
    Queue::fake();
    $user = gateUser('gate-oo-3', 'business', 'restaurant');

    actingAsUser($user)
        ->postJson('/api/platforms/online-ordering/entries', ['url' => 'https://www.ubereats.com/store/x'])
        ->assertStatus(202);
});

it('leaves online-ordering entries()/forget() open regardless of capability', function () {
    $user = gateUser('gate-oo-4', 'partna', null);

    actingAsUser($user)->getJson('/api/platforms/online-ordering/entries')->assertOk();
    actingAsUser($user)->deleteJson('/api/platforms/online-ordering')->assertOk();
});

// ── Reservations (can_use_reservations = business ? food : true) ────────────

it('403s reservations detect for a non-food (barbershop) business', function () {
    $user = gateUser('gate-res-1', 'business', 'barber');

    actingAsUser($user)->postJson('/api/platforms/reservations/detect', ['url' => 'https://booking.resdiary.com/widget/Standard/Ollies'])
        ->assertStatus(403)
        ->assertJsonPath('message', 'Reservations are not available for your account.');
});

it('allows reservations detect for a food (restaurant) business', function () {
    $user = gateUser('gate-res-2', 'business', 'restaurant');

    actingAsUser($user)->postJson('/api/platforms/reservations/detect', ['url' => 'https://booking.resdiary.com/widget/Standard/Ollies'])
        ->assertOk()
        ->assertJsonPath('provider', 'resdiary');
});

it('allows reservations detect for partna unconditionally', function () {
    $user = gateUser('gate-res-3', 'partna', null);

    actingAsUser($user)->postJson('/api/platforms/reservations/detect', ['url' => 'https://booking.resdiary.com/widget/Standard/Ollies'])
        ->assertOk();
});

it('403s the keyless reservation connect endpoints directly for a non-food business (bypassing /detect)', function () {
    $user = gateUser('gate-res-4', 'business', 'barber');

    actingAsUser($user)->postJson('/api/platforms/opentable/connect', ['url' => 'https://www.opentable.com.au/restaurant/profile/266537'])->assertStatus(403);
    actingAsUser($user)->postJson('/api/platforms/resdiary/connect', ['url' => 'https://booking.resdiary.com/widget/Standard/Ollies'])->assertStatus(403);
    actingAsUser($user)->postJson('/api/platforms/nowbookit/connect', ['url' => 'https://booking.nowbookit.com/steps/sitting-details?accountid=12&venueid=34'])->assertStatus(403);
});

it('leaves reservations status()/suggestion()/forget() open regardless of capability', function () {
    $user = gateUser('gate-res-5', 'business', 'barber');

    actingAsUser($user)->getJson('/api/platforms/reservations/status')->assertOk();
    actingAsUser($user)->getJson('/api/platforms/reservations/suggestion')->assertOk();
    actingAsUser($user)->deleteJson('/api/platforms/reservations')->assertOk();
});

// ── Booking (can_use_booking = business ? !food : true) ──────────────────────

it('403s booking detect for a food (restaurant) business', function () {
    $user = gateUser('gate-bk-1', 'business', 'restaurant');

    actingAsUser($user)->postJson('/api/platforms/booking/detect', ['url' => 'https://www.fresha.com/a/ollies-salon'])
        ->assertStatus(403)
        ->assertJsonPath('message', 'Booking is not available for your account.');
});

it('allows booking detect for a non-food (barbershop) business', function () {
    $user = gateUser('gate-bk-2', 'business', 'barber');

    actingAsUser($user)->postJson('/api/platforms/booking/detect', ['url' => 'https://www.fresha.com/a/ollies-salon'])
        ->assertOk()
        ->assertJsonPath('provider', 'fresha');
});

it('allows booking detect for partna unconditionally', function () {
    $user = gateUser('gate-bk-3', 'partna', null);

    actingAsUser($user)->postJson('/api/platforms/booking/detect', ['url' => 'https://www.fresha.com/a/ollies-salon'])
        ->assertOk();
});

it('403s the bespoke Fresha connect for a food (restaurant) business', function () {
    $user = gateUser('gate-bk-4', 'business', 'restaurant');

    actingAsUser($user)->postJson('/api/platforms/fresha/connect', ['url' => 'https://www.fresha.com/a/ollies-salon'])
        ->assertStatus(403)
        ->assertJsonPath('message', 'Booking is not available for your account.');
});

it('allows the bespoke Fresha connect for a non-food business', function () {
    $this->mock(FreshaScraper::class, function ($m) {
        $m->shouldReceive('stripLocale')->andReturnUsing(fn ($u) => $u);
        $m->shouldReceive('fetchMenu')->andReturn(['storeName' => 'Ollies', 'team' => [], 'services' => []]);
    });
    $user = gateUser('gate-bk-5', 'business', 'barber');

    actingAsUser($user)->postJson('/api/platforms/fresha/connect', ['url' => 'https://www.fresha.com/a/ollies-salon'])
        ->assertOk();
});

it('403s the bespoke Square connect for a food (restaurant) business', function () {
    $user = gateUser('gate-bk-6', 'business', 'restaurant');

    actingAsUser($user)->postJson('/api/platforms/square/connect', ['url' => 'https://book.squareup.com/appointments/x'])
        ->assertStatus(403)
        ->assertJsonPath('message', 'Booking is not available for your account.');
});

it('allows the bespoke Square connect for partna unconditionally', function () {
    $user = gateUser('gate-bk-7', 'partna', null);

    actingAsUser($user)->postJson('/api/platforms/square/connect', ['url' => 'https://book.squareup.com/appointments/x'])
        ->assertOk();
});

it('leaves booking status()/forget() open regardless of capability', function () {
    $user = gateUser('gate-bk-8', 'business', 'restaurant');

    actingAsUser($user)->getJson('/api/platforms/booking/status')->assertOk();
    actingAsUser($user)->deleteJson('/api/platforms/booking')->assertOk();
});
