<?php

use App\Models\Core\FeatureAvailabilityRule;
use App\Models\Core\Segments\UserSegment;
use App\Models\Core\Segments\UserSegmentMember;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\FeatureAvailability\FeatureAvailability;
use App\Services\Platforms\BandcampScraper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Skool was this file's fixture until Phase 1.2 demoted it to link-only: its
// connect is now a pure normalizer with no vendor call, so it can no longer
// demonstrate that the 503 lands BEFORE anything is scraped. Bandcamp is the
// surviving equivalent — same GenericPlatformController connect path, still
// backed by a scraper the middleware must never let the controller reach.

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

/** Binds a BandcampScraper that resolves a one-release artist page. */
function guardScraperReturning(): Closure
{
    return function ($m) {
        $m->shouldReceive('normalizeOrigin')->andReturn('https://demo.bandcamp.com');
        $m->shouldReceive('fetchProfile')->andReturn([
            'name' => 'Demo',
            'thumbnail' => null,
            'items' => [['name' => 'Track', 'thumbnail' => null, 'link' => 'https://demo.bandcamp.com/track/x']],
        ]);
        $m->shouldReceive('enrichPrices')->andReturnUsing(fn (array $items) => $items);
    };
}

it('503s a disabled-platform connect WITHOUT invoking the scraper', function () {
    $user = guardUser('guardblock');

    // A bare Mockery mock: any call to the scraper fails the test. Because the
    // middleware 503s before the controller resolves, it is never called.
    $this->mock(BandcampScraper::class);

    FeatureAvailabilityRule::query()->create(['feature_key' => 'integration.bandcamp', 'mode' => 'disabled']);
    FeatureAvailability::flush();

    actingAsUser($user)->postJson('/api/platforms/bandcamp/connect', ['url' => 'https://demo.bandcamp.com'])
        ->assertStatus(503);
});

it('allows a connect when no rule exists', function () {
    $user = guardUser('guardallow');

    $this->mock(BandcampScraper::class, guardScraperReturning());

    actingAsUser($user)->postJson('/api/platforms/bandcamp/connect', ['url' => 'https://demo.bandcamp.com'])
        ->assertOk();

    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'bandcamp')->count())
        ->toBe(1);
});

it('blocks a member of a disabled segment but allows an outsider', function () {
    $member = guardUser('guardmember');
    $outsider = guardUser('guardoutsider');

    $segment = UserSegment::query()->create(['name' => 'seg-'.Str::random(4), 'filters' => []]);
    UserSegmentMember::query()->create(['segment_id' => $segment->id, 'user_id' => $member->id]);

    FeatureAvailabilityRule::query()->create([
        'feature_key' => 'integration.bandcamp', 'mode' => 'disabled', 'segment_id' => $segment->id,
    ]);
    FeatureAvailability::flush();

    // Member: blocked before scrape (bare mock = fails if called).
    $this->mock(BandcampScraper::class);
    actingAsUser($member)->postJson('/api/platforms/bandcamp/connect', ['url' => 'https://demo.bandcamp.com'])
        ->assertStatus(503);

    // Outsider: allowed (rebind the scraper to return data).
    $this->mock(BandcampScraper::class, guardScraperReturning());
    actingAsUser($outsider)->postJson('/api/platforms/bandcamp/connect', ['url' => 'https://demo.bandcamp.com'])
        ->assertOk();
});
