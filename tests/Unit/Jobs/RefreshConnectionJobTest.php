<?php

use App\Jobs\Platforms\RefreshConnectionJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\PlatformRefresher;
use App\Services\Platforms\YoutubeScraper;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function jobUser(): User
{
    return User::create([
        'handle' => 'job', 'handle_lc' => 'job', 'display_name' => 'Job',
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => 'job@example.com',
    ]);
}

it('defines the required queue-hygiene properties', function () {
    $job = new RefreshConnectionJob('id', 'youtube');
    // $tries = 0 (unlimited) is deliberate: RateLimited releases count as attempts,
    // so a finite $tries would fail queued jobs during a burst before they ever ran.
    // Bounded instead by retryUntil() (wall clock) + $maxExceptions (real errors).
    expect($job->tries)->toBe(0)
        ->and($job->maxExceptions)->toBe(3)
        ->and($job->backoff)->toBe([30, 120, 300])
        ->and($job->timeout)->toBe(120)
        ->and($job->retryUntil())->toBeInstanceOf(DateTimeInterface::class);
});

it('uses the connection id as its uniqueId', function () {
    expect((new RefreshConnectionJob('abc', 'youtube'))->uniqueId())->toBe('abc');
});

it('refreshes a stale YouTube connection through PlatformRefresher', function () {
    $user = jobUser();

    $this->mock(YoutubeScraper::class, function ($m) {
        $m->shouldReceive('fetchRecentVideos')->andReturn([
            ['videoId' => 'v9', 'name' => 'New Video', 'description' => 'nd', 'link' => 'nl', 'thumbnail' => 'nt'],
        ]);
    });

    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'youtube', 'resource_id' => 'youtube',
        'payload' => ['handle' => 'chan', 'name' => 'Old Video', 'highlights' => [['videoId' => 'h1']]],
        'last_refreshed_at' => now()->subWeek(),
    ]);

    (new RefreshConnectionJob($conn->id, 'youtube'))->handle(app(PlatformRefresher::class));

    $conn->refresh();
    expect($conn->payload['latest']['videoId'])->toBe('v9')
        ->and($conn->last_refresh_status)->toBe('ok')
        ->and($conn->payload['highlights'])->toHaveCount(1); // curated picks preserved
});

it('records unavailable + increments failures when the scraper returns nothing', function () {
    $user = jobUser();
    $this->mock(YoutubeScraper::class, fn ($m) => $m->shouldReceive('fetchRecentVideos')->andReturn([]));

    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'youtube', 'resource_id' => 'youtube',
        'payload' => ['handle' => 'chan'], 'last_refreshed_at' => now()->subWeek(),
    ]);

    (new RefreshConnectionJob($conn->id, 'youtube'))->handle(app(PlatformRefresher::class));

    $conn->refresh();
    expect($conn->last_refresh_status)->toBe('unavailable')
        ->and($conn->consecutive_failures)->toBe(1);
});

it('no-ops when the connection is missing or inactive', function () {
    $user = jobUser();
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'youtube', 'resource_id' => 'youtube',
        'payload' => ['handle' => 'chan'], 'last_refreshed_at' => now()->subWeek(), 'is_active' => false,
    ]);

    // No scraper mock: if handle() tried to refresh an inactive row it would hit the real scraper.
    (new RefreshConnectionJob($conn->id, 'youtube'))->handle(app(PlatformRefresher::class));
    (new RefreshConnectionJob('does-not-exist', 'youtube'))->handle(app(PlatformRefresher::class));

    $conn->refresh();
    expect($conn->last_refresh_status)->toBeNull();
});

it('applies the platform-refresh RateLimited middleware', function () {
    $mw = (new RefreshConnectionJob('id', 'youtube'))->middleware();
    expect($mw)->toHaveCount(1)->and($mw[0])->toBeInstanceOf(RateLimited::class);
});

it('registers a per-provider platform-refresh limiter keyed by platform', function () {
    $callback = RateLimiter::limiter('platform-refresh');
    expect($callback)->not->toBeNull();

    $limit = $callback(new RefreshConnectionJob('id', 'youtube'));
    $limit = is_array($limit) ? $limit[0] : $limit;

    expect($limit)->toBeInstanceOf(Limit::class)
        ->and($limit->key)->toBe('platform-refresh:youtube')
        ->and($limit->maxAttempts)->toBe(60); // config default
});
