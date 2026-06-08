<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\YoutubeScraper;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function refreshCronUser(): User
{
    return User::create([
        'handle' => 'cron',
        'handle_lc' => 'cron',
        'display_name' => 'Cron',
        'account_type' => 'individual',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => 'cron@example.com',
    ]);
}

it('refreshes a stale YouTube connection to the new latest video, preserving highlights', function () {
    $user = refreshCronUser();
    $conn = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'youtube',
        'resource_id' => 'youtube',
        'payload' => [
            'handle' => 'chan', 'name' => 'Old Video', 'description' => 'd',
            'link' => 'l', 'thumbnail' => 't', 'highlights' => [['videoId' => 'h1']],
        ],
        'last_refreshed_at' => now()->subWeek(),
    ]);

    $this->mock(YoutubeScraper::class, function ($m) {
        $m->shouldReceive('fetchRecentVideos')->andReturn([
            ['videoId' => 'v9', 'name' => 'New Video', 'description' => 'nd', 'link' => 'nl', 'thumbnail' => 'nt'],
        ]);
    });

    $this->artisan('integrations:refresh', ['--throttle-ms' => 0])->assertSuccessful();

    $conn->refresh();
    expect($conn->payload['name'])->toBe('New Video');
    expect($conn->payload['highlights'])->toHaveCount(1);   // curated picks preserved
    expect($conn->last_refresh_status)->toBe('ok');
    expect($conn->payload['latest'])->toHaveKey('videoId');
    expect($conn->payload['latest']['videoId'])->toBe('v9');   // the new video, not the old flat fields
});

it('does not touch non-refreshable platforms (Instagram is never queried)', function () {
    $user = refreshCronUser();
    $conn = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => ['username' => 'ig', 'images' => []],
        'last_refreshed_at' => now()->subYear(),
    ]);

    // No scraper mock: if the cron touched Instagram it would attempt a live scrape.
    $this->artisan('integrations:refresh', ['--throttle-ms' => 0])->assertSuccessful();

    $conn->refresh();
    expect($conn->payload['username'])->toBe('ig');     // untouched
    expect($conn->last_refresh_status)->toBeNull();
});
