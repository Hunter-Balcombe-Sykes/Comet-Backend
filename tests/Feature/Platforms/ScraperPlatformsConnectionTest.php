<?php

use App\Models\Core\Site\PlatformConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\EventbriteScraper;
use App\Services\Platforms\YoutubeScraper;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function scraperUser(string $h): User
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

it('requires auth on the eventbrite + youtube dashboard routes', function () {
    $this->getJson('/api/platforms/eventbrite/selection')->assertUnauthorized();
    $this->getJson('/api/platforms/youtube/selection')->assertUnauthorized();
});

it('connects an Eventbrite organiser scoped to the authenticated user', function () {
    $user = scraperUser('eborg');

    $this->mock(EventbriteScraper::class, function ($m) {
        $m->shouldReceive('normalizeOrgUrl')->andReturn('https://www.eventbrite.com/o/acme-1');
        $m->shouldReceive('fetchEvents')->andReturn([
            'organiser' => 'Acme',
            'events' => [['name' => 'Gig', 'startDate' => '2099-01-01T00:00:00+00:00']],
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/eventbrite/connect', ['url' => 'https://www.eventbrite.com/o/acme-1'])
        ->assertOk()
        ->assertJsonPath('organiser', 'Acme');

    expect(PlatformConnection::where('user_id', $user->id)->where('platform', 'eventbrite')->exists())->toBeTrue();
});

it('connects a YouTube channel scoped to the authenticated user', function () {
    $user = scraperUser('ytuser');

    $this->mock(YoutubeScraper::class, function ($m) {
        $m->shouldReceive('normalizeHandle')->andReturn('mychannel');
        $m->shouldReceive('fetchRecentVideos')->andReturn([
            ['videoId' => 'v1', 'name' => 'Vid', 'description' => 'd', 'link' => 'l', 'thumbnail' => 't'],
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/youtube/connect', ['channel' => '@mychannel'])
        ->assertOk()
        ->assertJsonPath('handle', 'mychannel');

    expect(PlatformConnection::where('user_id', $user->id)->where('platform', 'youtube')->exists())->toBeTrue();
});
