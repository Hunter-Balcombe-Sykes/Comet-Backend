<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\LinkCardScraper;
use App\Services\Platforms\ProviderDetector;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

// Defaults to 'partna' (booking/reservations are unconditional for partna —
// 2026-07-15 sector gating). Online ordering is food-business-only, so the
// two online-ordering tests below override to business + a food sector.
function catUser(string $h, string $accountType = 'partna', ?string $sector = null): User
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

/** Replace LinkCardScraper with a canned snapshot so no live HTTP is made. */
function fakeScraper(array $snapshot): void
{
    test()->mock(LinkCardScraper::class, function ($m) use ($snapshot) {
        $m->shouldReceive('normalizeUrl')->andReturnUsing(fn ($u) => $snapshot['url'] ?? $u);
        $m->shouldReceive('snapshotOrMinimal')->andReturn($snapshot);
        // addEntry now calls minimalCard (async JOB-1) instead of snapshotOrMinimal.
        $m->shouldReceive('minimalCard')->andReturn($snapshot);
    });
}

// ── ProviderDetector (pure, no HTTP) ──────────────────────────────────

it('detects booking providers from a pasted URL', function () {
    $detector = app(ProviderDetector::class);

    expect($detector->detectFor('booking', 'https://www.fresha.com/a/my-salon-abc'))->toBe('fresha');
    expect($detector->detectFor('booking', 'fresha.com/en-GB/a/my-salon'))->toBe('fresha');
    expect($detector->detectFor('booking', 'https://book.squareup.com/appointments/xyz'))->toBe('square');
    expect($detector->detectFor('booking', 'https://my-shop.square.site/'))->toBe('square');
    expect($detector->detectFor('booking', 'https://calendly.com/me'))->toBeNull();   // unknown → custom
});

it('detects opentable for reservations and nothing for ordering', function () {
    $detector = app(ProviderDetector::class);

    expect($detector->detectFor('reservations', 'https://www.opentable.com.au/r/ollies'))->toBe('opentable');
    // resy earned its own key in the 27-provider stopgap — detectable since.
    expect($detector->detectFor('reservations', 'https://resy.com/x'))->toBe('resy');
    expect($detector->detectFor('online-ordering', 'https://www.ubereats.com/store/x'))->toBeNull();
});

it('detects events providers by host (eventbrite / humanitix), custom otherwise', function () {
    $detector = app(ProviderDetector::class);
    expect($detector->detectFor('events', 'https://www.eventbrite.com.au/e/show-123'))->toBe('eventbrite');
    expect($detector->detectFor('events', 'https://events.humanitix.com/my-gig'))->toBe('humanitix');
    expect($detector->detectFor('events', 'https://meetup.com/group'))->toBeNull(); // unknown → custom
});

it('providersFor returns detectable event slugs in registration order, excluding fallbacks', function () {
    $detector = app(ProviderDetector::class);
    // events-custom has no Detection strategy and must be excluded. The five
    // ticket sellers joined in the 27-provider stopgap, in registration order.
    expect($detector->providersFor('events'))->toBe([
        'eventbrite', 'humanitix', 'ticketek', 'oztix', 'trybooking', 'resident-advisor', 'ticketmaster',
    ]);
});

// ── Booking detect routing ────────────────────────────────────────────

it('routes a Fresha URL to the picker step without writing a booking row', function () {
    $user = catUser('cat1');

    actingAsUser($user)->postJson('/api/platforms/booking/detect', [
        'url' => 'https://www.fresha.com/a/my-salon-abc',
    ])
        ->assertOk()
        ->assertJsonPath('provider', 'fresha')
        ->assertJsonPath('next', 'fresha-picker');

    // Known providers store under their own key — no 'booking' row.
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'booking')->exists())->toBeFalse();
});

it('routes a Square URL to square-connect', function () {
    $user = catUser('cat2');

    actingAsUser($user)->postJson('/api/platforms/booking/detect', [
        'url' => 'https://book.squareup.com/appointments/abc',
    ])
        ->assertOk()
        ->assertJsonPath('provider', 'square')
        ->assertJsonPath('next', 'square-connect');
});

it('falls back to a branded custom card for an unknown booking URL', function () {
    Queue::fake();
    $user = catUser('cat3');

    fakeScraper([
        'url' => 'https://calendly.com/me',
        'name' => 'Calendly',
        'description' => 'Book a meeting',
        'favicon' => 'https://calendly.com/favicon.ico',
        'logo' => null,
    ]);

    // Custom fallback is now async (JOB-1): returns 202 + status=pending immediately;
    // EnrichLinkCardJob upgrades the display fields off-thread.
    actingAsUser($user)->postJson('/api/platforms/booking/detect', ['url' => 'https://calendly.com/me'])
        ->assertStatus(202)
        ->assertJsonPath('provider', 'custom')
        ->assertJsonPath('next', 'custom-saved')
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('selection.name', 'Calendly');

    $row = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'booking')->firstOrFail();
    expect($row->payload['provider'])->toBe('custom');
    expect($row->payload['source'])->toBe('manual');
    expect($row->payload['url'])->toBe('https://calendly.com/me');
});

// ── Booking status aggregation ────────────────────────────────────────

it('aggregates booking status across fresha / square / custom', function () {
    $user = catUser('cat4');

    actingAsUser($user)->getJson('/api/platforms/booking/status')
        ->assertOk()->assertJsonPath('connected', false)->assertJsonPath('provider', null);

    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/x', 'selection' => ['storeName' => 'My Salon']],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)->getJson('/api/platforms/booking/status')
        ->assertOk()
        ->assertJsonPath('connected', true)
        ->assertJsonPath('provider', 'fresha')
        ->assertJsonPath('name', 'My Salon');
});

it('forgets the connected booking provider', function () {
    $user = catUser('cat5');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'square', 'resource_id' => 'square',
        'payload' => ['url' => 'https://book.squareup.com/x'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)->deleteJson('/api/platforms/booking')
        ->assertOk()->assertJsonPath('connected', false);

    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'square')->exists())->toBeFalse();
});

// ── Reservations ──────────────────────────────────────────────────────

it('routes an OpenTable URL to opentable-connect', function () {
    $user = catUser('cat6');

    actingAsUser($user)->postJson('/api/platforms/reservations/detect', [
        'url' => 'https://www.opentable.com.au/restaurant/profile/266537',
    ])
        ->assertOk()
        ->assertJsonPath('provider', 'opentable')
        ->assertJsonPath('next', 'opentable-connect');
});

it('reports opentable as the connected reservation', function () {
    $user = catUser('cat7');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'opentable', 'resource_id' => 'opentable',
        'payload' => ['url' => 'https://www.opentable.com.au/restaurant/profile/266537', 'rid' => '266537', 'name' => 'Ollies', 'embedUrl' => 'https://www.opentable.com.au/widget/reservation/canvas?rid=266537'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)->getJson('/api/platforms/reservations/status')
        ->assertOk()
        ->assertJsonPath('connected', true)
        ->assertJsonPath('provider', 'opentable')
        ->assertJsonPath('embedUrl', fn ($u) => str_contains((string) $u, 'rid=266537'));
});

// ── Online ordering (multi-entry) ─────────────────────────────────────

it('adds, lists and removes online-ordering entries', function () {
    Queue::fake();
    // Online ordering is food-business-only (2026-07-15 sector gating).
    $user = catUser('cat8', 'business', 'restaurant');

    fakeScraper([
        'url' => 'https://www.ubereats.com/store/my-cafe',
        'name' => 'Uber Eats',
        'description' => null,
        'favicon' => 'https://www.ubereats.com/favicon.ico',
        'logo' => null,
    ]);

    // addEntry returns 202 with a minimal card (async JOB-1); name comes from the
    // canned minimalCard mock which returns the same snapshot as before.
    actingAsUser($user)->postJson('/api/platforms/online-ordering/entries', ['url' => 'https://www.ubereats.com/store/my-cafe'])
        ->assertStatus(202)
        ->assertJsonPath('entries.0.provider', 'custom')
        ->assertJsonPath('entries.0.name', 'Uber Eats')
        ->assertJsonPath('entries.0.source', 'manual');

    // Convergence Phase 6: an Uber Eats link is an uber_eats.order row; the
    // family is addressed by routing_class, and the endpoints are unchanged.
    $row = IntegrationConnection::query()->where('user_id', $user->id)->where('routing_class', 'ordering')->firstOrFail();
    expect($row->surface_key)->toBe('uber_eats.order');
    $id = $row->resource_id;

    actingAsUser($user)->getJson('/api/platforms/online-ordering/entries')
        ->assertOk()->assertJsonCount(1, 'entries');

    actingAsUser($user)->deleteJson("/api/platforms/online-ordering/entries/{$id}")
        ->assertOk()->assertJsonCount(0, 'entries');
});

it('attaches any link even when the page cannot be fetched (graceful fallback)', function () {
    // SafeUrlFetcher returns null for bot-blocked / unreachable pages (Uber Eats).
    test()->mock(SafeUrlFetcher::class, fn ($m) => $m->shouldReceive('tryFetch')->andReturn(null));

    $card = app(LinkCardScraper::class)->snapshotOrMinimal('https://www.ubereats.com/au/store/ollies-pizza-parlour/abc');

    expect($card['url'])->toBe('https://www.ubereats.com/au/store/ollies-pizza-parlour/abc');
    expect($card['name'])->toBe('ubereats.com');                                                       // host-derived name
    expect($card['favicon'])->toBe('https://www.google.com/s2/favicons?domain=ubereats.com&sz=64');    // resolved brand icon
});

it('adds an unfetchable online-ordering link as a branded card (no 422)', function () {
    // addEntry now calls minimalCard (URL-only, no HTTP) rather than snapshotOrMinimal,
    // so SafeUrlFetcher is never invoked. The mock is kept as documentation but unused.
    Queue::fake();
    test()->mock(SafeUrlFetcher::class, fn ($m) => $m->shouldReceive('tryFetch')->andReturn(null));
    // Online ordering is food-business-only (2026-07-15 sector gating).
    $user = catUser('cat9', 'business', 'restaurant');

    actingAsUser($user)->postJson('/api/platforms/online-ordering/entries', [
        'url' => 'https://www.ubereats.com/au/store/ollies-pizza-parlour/abc',
    ])
        ->assertStatus(202)
        ->assertJsonPath('entries.0.name', 'ubereats.com')
        ->assertJsonPath('entries.0.url', 'https://www.ubereats.com/au/store/ollies-pizza-parlour/abc');
});
