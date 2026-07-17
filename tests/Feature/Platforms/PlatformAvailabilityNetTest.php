<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\FeatureAvailability\FeatureAvailability;
use App\Services\Platforms\SkoolScraper;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupSegmentsTables();
    setupFeatureAvailabilityTable();
    \Illuminate\Support\Facades\DB::connection('pgsql')->statement('DELETE FROM core.feature_availability');
    FeatureAvailability::flush();
});

function netUser(string $h): User
{
    return User::create([
        'handle' => $h, 'handle_lc' => strtolower($h), 'display_name' => ucfirst($h),
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

it('blocks persisting a connection for a disabled platform even after a successful scrape', function () {
    $user = netUser('netblock');

    // Scrape "succeeds" — the point is the net still refuses to persist.
    $this->mock(SkoolScraper::class, function ($m) {
        $m->shouldReceive('normalizeUrl')->andReturn('https://www.skool.com/demo');
        $m->shouldReceive('fetchCommunity')->andReturn(['url' => 'https://www.skool.com/demo', 'name' => 'Demo']);
    });

    \App\Models\Core\FeatureAvailabilityRule::query()->create([
        'feature_key' => 'integration.skool', 'mode' => 'disabled',
    ]);
    FeatureAvailability::flush();

    actingAsUser($user)->postJson('/api/platforms/skool/connect', ['url' => 'https://www.skool.com/demo'])
        ->assertStatus(503);

    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'skool')->count())
        ->toBe(0);
});

it('does not resurrect a taken-down connection while the platform stays disabled', function () {
    $user = netUser('netresurrect');

    // Existing connection already taken down (is_active=false).
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'skool', 'resource_id' => 'c-res',
        'payload' => ['url' => 'https://www.skool.com/demo', 'name' => 'Demo'], 'is_active' => false,
    ]);

    $this->mock(SkoolScraper::class, function ($m) {
        $m->shouldReceive('normalizeUrl')->andReturn('https://www.skool.com/demo');
        $m->shouldReceive('fetchCommunity')->andReturn(['url' => 'https://www.skool.com/demo', 'name' => 'Demo']);
    });

    \App\Models\Core\FeatureAvailabilityRule::query()->create([
        'feature_key' => 'integration.skool', 'mode' => 'disabled',
    ]);
    FeatureAvailability::flush();

    // A reconnect attempt while disabled must not flip is_active back to true.
    actingAsUser($user)->postJson('/api/platforms/skool/connect', ['url' => 'https://www.skool.com/demo'])
        ->assertStatus(503);

    expect($conn->refresh()->is_active)->toBeFalse();
});
