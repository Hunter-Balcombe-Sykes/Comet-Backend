<?php

/** @phpstan-ignore-all */

use App\Jobs\Streaming\CheckStreamingLiveStatusJob;
use App\Services\Streaming\LiveStatusPoller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(fn () => Redis::flushdb());

it('skips Kick entirely when rate_limited key is set in Redis', function () {
    setupBlocksTable();
    config(['partna.streaming_platforms' => ['twitch', 'kick']]);
    Redis::set('streaming:kick:rate_limited', '1', 'EX', 300);

    // Real blocks on both platforms — the short-circuit exits before the breaker
    // logic when there is nothing to poll, so this test must supply actual work.
    foreach ([['twitch', 'twitchuser'], ['kick', 'kickuser']] as [$platform, $handle]) {
        DB::connection('pgsql')->table('site.blocks')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'site_id' => (string) Str::uuid(),
            'block_group' => 'links',
            'is_active' => 1,
            'live_check_enabled' => 1,
            'platform' => $platform,
            'settings' => json_encode(['handle' => $handle]),
            'deleted_at' => null,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    $poller = Mockery::mock(LiveStatusPoller::class);
    // Kick should NOT be dispatched (circuit-breaker set)
    $poller->shouldReceive('poll')->with('kick', Mockery::any(), Mockery::any())->never();
    // Twitch is still dispatched
    $poller->shouldReceive('poll')->with('twitch', Mockery::any(), Mockery::any())->once();

    Log::shouldReceive('warning')->once()->withArgs(fn ($msg) => str_contains((string) $msg, 'rate limited'));

    $job = new CheckStreamingLiveStatusJob;
    $job->handle($poller);
});

it('logs critical and aborts when Redis is unavailable', function () {
    setupBlocksTable();
    config(['partna.streaming_platforms' => ['twitch', 'kick']]);

    // A pollable block is required so the job reaches the Kick circuit-breaker read;
    // with none, the short-circuit returns before Redis is ever consulted.
    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => (string) Str::uuid(),
        'site_id' => (string) Str::uuid(),
        'block_group' => 'links',
        'is_active' => 1,
        'live_check_enabled' => 1,
        'platform' => 'twitch',
        'settings' => json_encode(['handle' => 'testuser']),
        'deleted_at' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    Redis::shouldReceive('exists')
        ->once()
        ->andThrow(new RedisException('Connection refused'));

    // The job calls report($e) after the explicit log; intercept it so the
    // exception handler doesn't double-fire Log::error.
    Exceptions::fake();

    Log::shouldReceive('error')->once()->with('streaming.redis_unavailable', Mockery::any());

    $poller = Mockery::mock(LiveStatusPoller::class);
    $poller->shouldNotReceive('poll');

    $job = new CheckStreamingLiveStatusJob;
    $job->handle($poller);

    Exceptions::assertReported(RedisException::class);
});

it('catches poller exceptions and logs per-platform error without crashing the job', function () {
    setupBlocksTable();
    config(['partna.streaming_platforms' => ['twitch']]);

    // Insert a live-check-enabled block so the job finds handles and calls poll.
    // Phase 1: live_check_enabled + platform are promoted columns; handle stays in settings.
    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => (string) Str::uuid(),
        'site_id' => (string) Str::uuid(),
        'block_group' => 'links',
        'is_active' => 1,
        'live_check_enabled' => 1,
        'platform' => 'twitch',
        'settings' => json_encode(['handle' => 'testuser']),
        'deleted_at' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $poller = Mockery::mock(LiveStatusPoller::class);
    $poller->shouldReceive('poll')
        ->with('twitch', Mockery::any(), Mockery::any())
        ->andThrow(new RuntimeException('Network error'));

    // Fake the exception reporter so report($e) inside the per-platform catch
    // doesn't trigger an additional Log::error via the exception handler.
    Exceptions::fake();

    Log::shouldReceive('error')->once()->with('streaming.poll_error', Mockery::any());

    $job = new CheckStreamingLiveStatusJob;
    // Should not throw
    $job->handle($poller);

    Exceptions::assertReported(RuntimeException::class);
});

it('short-circuits before Redis and the poller when no block has live-checking enabled', function () {
    setupBlocksTable();
    config(['partna.streaming_platforms' => ['twitch', 'kick']]);

    // A streaming block exists but live-checking is OFF — no pollable work.
    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => (string) Str::uuid(),
        'site_id' => (string) Str::uuid(),
        'block_group' => 'links',
        'is_active' => 1,
        'live_check_enabled' => 0,
        'platform' => 'twitch',
        'settings' => json_encode(['handle' => 'idlestreamer']),
        'deleted_at' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    // With nothing to poll, the job must not consult the Kick circuit-breaker...
    Redis::shouldReceive('exists')->never();

    // ...nor dispatch any polling.
    $poller = Mockery::mock(LiveStatusPoller::class);
    $poller->shouldNotReceive('poll');

    $job = new CheckStreamingLiveStatusJob;
    $job->handle($poller);
});

it('bounds redelivery without ever retrying a tick that threw', function () {
    // The July MaxAttemptsExceeded fix (Nightwatch #339): tries must exceed 1
    // so a redelivered tick RUNS instead of exploding pre-run, while
    // maxExceptions=1 pins "the next scheduled tick is the retry" for real
    // failures. A drift back to tries=1 reintroduces the noise class.
    $job = new CheckStreamingLiveStatusJob;

    expect($job->tries)->toBeGreaterThan(1)
        ->and($job->maxExceptions)->toBe(1)
        ->and($job->timeout)->toBe(90);
});

it('logs job failure via failed() callback', function () {
    // Fake the exception reporter so report($e) doesn't trigger an additional
    // Log::error via the exception handler on top of our explicit log.
    Exceptions::fake();

    Log::shouldReceive('error')->once()->with('streaming.job_failed', Mockery::any());

    $job = new CheckStreamingLiveStatusJob;
    $job->failed(new RuntimeException('Something broke'));

    Exceptions::assertReported(RuntimeException::class);
});
