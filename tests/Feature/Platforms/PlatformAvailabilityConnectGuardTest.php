<?php

use App\Models\Core\FeatureAvailabilityRule;
use App\Models\Core\Segments\UserSegment;
use App\Models\Core\Segments\UserSegmentMember;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\FeatureAvailability\FeatureAvailability;
use App\Services\Platforms\SkoolScraper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupSegmentsTables();
    setupFeatureAvailabilityTable();
    DB::connection('pgsql')->statement('DELETE FROM core.feature_availability');
    DB::connection('pgsql')->statement('DELETE FROM core.user_segments');
    FeatureAvailability::flush();
});

function guardUser(string $h): User
{
    return User::create([
        'handle' => $h, 'handle_lc' => strtolower($h), 'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

it('503s a disabled-platform connect WITHOUT invoking the scraper', function () {
    $user = guardUser('guardblock');

    // A bare Mockery mock: any call to the scraper fails the test. Because the
    // middleware 503s before the controller resolves, it is never called.
    $this->mock(SkoolScraper::class);

    FeatureAvailabilityRule::query()->create(['feature_key' => 'integration.skool', 'mode' => 'disabled']);
    FeatureAvailability::flush();

    actingAsUser($user)->postJson('/api/platforms/skool/connect', ['url' => 'https://www.skool.com/demo'])
        ->assertStatus(503);
});

it('allows a connect when no rule exists', function () {
    $user = guardUser('guardallow');

    $this->mock(SkoolScraper::class, function ($m) {
        $m->shouldReceive('normalizeUrl')->andReturn('https://www.skool.com/demo');
        $m->shouldReceive('fetchCommunity')->andReturn(['url' => 'https://www.skool.com/demo', 'name' => 'Demo']);
    });

    actingAsUser($user)->postJson('/api/platforms/skool/connect', ['url' => 'https://www.skool.com/demo'])
        ->assertOk();

    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'skool')->count())
        ->toBe(1);
});

it('blocks a member of a disabled segment but allows an outsider', function () {
    $member = guardUser('guardmember');
    $outsider = guardUser('guardoutsider');

    $segment = UserSegment::query()->create(['name' => 'seg-'.Str::random(4), 'filters' => []]);
    UserSegmentMember::query()->create(['segment_id' => $segment->id, 'user_id' => $member->id]);

    FeatureAvailabilityRule::query()->create([
        'feature_key' => 'integration.skool', 'mode' => 'disabled', 'segment_id' => $segment->id,
    ]);
    FeatureAvailability::flush();

    // Member: blocked before scrape (bare mock = fails if called).
    $this->mock(SkoolScraper::class);
    actingAsUser($member)->postJson('/api/platforms/skool/connect', ['url' => 'https://www.skool.com/demo'])
        ->assertStatus(503);

    // Outsider: allowed (rebind the scraper to return data).
    $this->mock(SkoolScraper::class, function ($m) {
        $m->shouldReceive('normalizeUrl')->andReturn('https://www.skool.com/demo');
        $m->shouldReceive('fetchCommunity')->andReturn(['url' => 'https://www.skool.com/demo', 'name' => 'Demo']);
    });
    actingAsUser($outsider)->postJson('/api/platforms/skool/connect', ['url' => 'https://www.skool.com/demo'])
        ->assertOk();
});
