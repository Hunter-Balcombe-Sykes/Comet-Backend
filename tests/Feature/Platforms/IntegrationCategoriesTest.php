<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\LinkCardScraper;
use App\Services\Platforms\ProviderDetector;
use App\Services\SmartLinks\SafeUrlFetcher;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function catUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'account_type' => 'individual',
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
    expect($detector->detectFor('reservations', 'https://resy.com/x'))->toBeNull();
    expect($detector->detectFor('online-ordering', 'https://www.ubereats.com/store/x'))->toBeNull();
});

it('detects events providers by host (eventbrite / humanitix), custom otherwise', function () {
    $detector = app(ProviderDetector::class);
    expect($detector->detectFor('events', 'https://www.eventbrite.com.au/e/show-123'))->toBe('eventbrite');
    expect($detector->detectFor('events', 'https://events.humanitix.com/my-gig'))->toBe('humanitix');
    expect($detector->detectFor('events', 'https://meetup.com/group'))->toBeNull(); // unknown → custom
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
    $user = catUser('cat3');

    fakeScraper([
        'url' => 'https://calendly.com/me',
        'name' => 'Calendly',
        'description' => 'Book a meeting',
        'favicon' => 'https://calendly.com/favicon.ico',
        'logo' => null,
    ]);

    actingAsUser($user)->postJson('/api/platforms/booking/detect', ['url' => 'https://calendly.com/me'])
        ->assertOk()
        ->assertJsonPath('provider', 'custom')
        ->assertJsonPath('next', 'custom-saved')
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
    $user = catUser('cat8');

    fakeScraper([
        'url' => 'https://www.ubereats.com/store/my-cafe',
        'name' => 'Uber Eats',
        'description' => null,
        'favicon' => 'https://www.ubereats.com/favicon.ico',
        'logo' => null,
    ]);

    actingAsUser($user)->postJson('/api/platforms/online-ordering/entries', ['url' => 'https://www.ubereats.com/store/my-cafe'])
        ->assertOk()
        ->assertJsonPath('entries.0.provider', 'custom')
        ->assertJsonPath('entries.0.name', 'Uber Eats')
        ->assertJsonPath('entries.0.source', 'manual');

    $id = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'online-ordering')->firstOrFail()->resource_id;

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
    test()->mock(SafeUrlFetcher::class, fn ($m) => $m->shouldReceive('tryFetch')->andReturn(null));
    $user = catUser('cat9');

    actingAsUser($user)->postJson('/api/platforms/online-ordering/entries', [
        'url' => 'https://www.ubereats.com/au/store/ollies-pizza-parlour/abc',
    ])
        ->assertOk()
        ->assertJsonPath('entries.0.name', 'ubereats.com')
        ->assertJsonPath('entries.0.url', 'https://www.ubereats.com/au/store/ollies-pizza-parlour/abc');
});
