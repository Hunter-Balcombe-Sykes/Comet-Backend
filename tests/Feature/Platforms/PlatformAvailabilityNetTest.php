<?php

use App\Models\Core\FeatureAvailabilityRule;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\FeatureAvailability\FeatureAvailability;
use App\Services\Platforms\BandcampScraper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupSegmentsTables();
    setupFeatureAvailabilityTable();
    DB::connection('pgsql')->statement('DELETE FROM core.feature_availability');
    FeatureAvailability::flush();
});

function netUser(string $h): User
{
    return User::create([
        'handle' => $h, 'handle_lc' => strtolower($h), 'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

// Both cases below borrow a scraped platform purely as a fixture for the
// availability net — the gating is platform-agnostic. Swapped skool → bandcamp
// when skool was demoted to link-only and SkoolScraper was deleted; bandcamp is
// the surviving refreshable, scrape-backed equivalent.
it('blocks persisting a connection for a disabled platform even after a successful scrape', function () {
    $user = netUser('netblock');

    // Scrape "succeeds" — the point is the net still refuses to persist.
    $this->mock(BandcampScraper::class, function ($m) {
        $m->shouldReceive('normalizeOrigin')->andReturn('https://demoband.bandcamp.com');
        $m->shouldReceive('fetchProfile')->andReturn([
            'name' => 'Demo',
            'thumbnail' => 't',
            'items' => [['itemId' => 'a1', 'name' => 'Album', 'thumbnail' => 't', 'link' => 'l']],
        ]);
        $m->shouldReceive('enrichPrices')->andReturnUsing(fn (array $items) => $items);
    });

    FeatureAvailabilityRule::query()->create([
        'feature_key' => 'integration.bandcamp', 'mode' => 'disabled',
    ]);
    FeatureAvailability::flush();

    actingAsUser($user)->postJson('/api/platforms/bandcamp/connect', ['url' => 'https://demoband.bandcamp.com'])
        ->assertStatus(503);

    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'bandcamp')->count())
        ->toBe(0);
});

it('does not resurrect a taken-down connection while the platform stays disabled', function () {
    $user = netUser('netresurrect');

    // Existing connection already taken down (is_active=false).
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'bandcamp', 'resource_id' => 'c-res',
        'payload' => ['url' => 'https://demoband.bandcamp.com', 'name' => 'Demo'], 'is_active' => false,
    ]);

    $this->mock(BandcampScraper::class, function ($m) {
        $m->shouldReceive('normalizeOrigin')->andReturn('https://demoband.bandcamp.com');
        $m->shouldReceive('fetchProfile')->andReturn([
            'name' => 'Demo',
            'thumbnail' => 't',
            'items' => [['itemId' => 'a1', 'name' => 'Album', 'thumbnail' => 't', 'link' => 'l']],
        ]);
        $m->shouldReceive('enrichPrices')->andReturnUsing(fn (array $items) => $items);
    });

    FeatureAvailabilityRule::query()->create([
        'feature_key' => 'integration.bandcamp', 'mode' => 'disabled',
    ]);
    FeatureAvailability::flush();

    // A reconnect attempt while disabled must not flip is_active back to true.
    actingAsUser($user)->postJson('/api/platforms/bandcamp/connect', ['url' => 'https://demoband.bandcamp.com'])
        ->assertStatus(503);

    expect($conn->refresh()->is_active)->toBeFalse();
});
