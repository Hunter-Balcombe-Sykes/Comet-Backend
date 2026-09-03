<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\PreAccount\ConnectedPlatformNames;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIntegrationConnectionsTable();
});

function connectPlatform(User $user, string $surfaceKey): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => $user->id,
        'surface_key' => $surfaceKey,
        'resource_id' => $surfaceKey,
        'is_active' => true,
        'payload' => [],
    ]);
}

it('returns brand labels for a user with connections, alphabetical and unique', function () {
    $user = User::factory()->create();
    connectPlatform($user, 'instagram.profile');
    connectPlatform($user, 'google_business.listing');

    expect(app(ConnectedPlatformNames::class)->for($user))->toBe(['Google Business', 'Instagram']);
});

// The thin-scrape case. A build that connected nothing is real and not rare
// (thin_scrape_at exists for it) and must degrade to the current email.
it('returns an empty list for a user with no connections', function () {
    $user = User::factory()->create();

    expect(app(ConnectedPlatformNames::class)->for($user))->toBe([]);
});

// A surface the compiled catalog cannot label must not produce a blank bullet.
// The model REFUSES to persist an unknown surface_key (LegacyPlatformMap guard),
// so the only way this row exists is the way it exists in production: written
// straight to the table before the catalog knew the surface.
it('skips a surface with no resolvable brand label', function () {
    $user = User::factory()->create();
    connectPlatform($user, 'instagram.profile');
    DB::connection('pgsql')->table('site.platform_connections')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'surface_key' => 'nonsense.surface',
        'routing_class' => 'link',
        'resource_id' => 'nonsense.surface',
        'is_primary' => 0,
        'is_active' => 1,
    ]);

    expect(app(ConnectedPlatformNames::class)->for($user))->toBe(['Instagram']);
});

// A disconnected platform is not "already connected". SoftDeletes makes the
// Eloquent read do this for free -- a raw DB::table() read would announce it.
it('ignores a disconnected connection', function () {
    $user = User::factory()->create();
    connectPlatform($user, 'instagram.profile');
    connectPlatform($user, 'google_business.listing')->delete();

    expect(app(ConnectedPlatformNames::class)->for($user))->toBe(['Instagram']);
});

it('never leaks another user\'s connections', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    connectPlatform($user, 'instagram.profile');
    connectPlatform($other, 'google_business.listing');

    expect(app(ConnectedPlatformNames::class)->for($user))->toBe(['Instagram']);
});
