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

    $poller = Mockery::mock(LiveStatusPoller::class);
    // Kick should NOT be dispatched
    $poller->shouldReceive('poll')->with('kick', Mockery::any())->never();
    // Twitch may be dispatched (with empty handles since no DB rows in this test)
    $poller->shouldReceive('poll')->with('twitch', Mockery::any())->zeroOrMoreTimes();

    Log::shouldReceive('warning')->once()->withArgs(fn ($msg) => str_contains((string) $msg, 'rate limited'));

    $job = new CheckStreamingLiveStatusJob;
    $job->handle($poller);
});

it('logs critical and aborts when Redis is unavailable', function () {
    config(['partna.streaming_platforms' => ['twitch', 'kick']]);

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
        ->with('twitch', Mockery::any())
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

it('logs job failure via failed() callback', function () {
    // Fake the exception reporter so report($e) doesn't trigger an additional
    // Log::error via the exception handler on top of our explicit log.
    Exceptions::fake();

    Log::shouldReceive('error')->once()->with('streaming.job_failed', Mockery::any());

    $job = new CheckStreamingLiveStatusJob;
    $job->failed(new RuntimeException('Something broke'));

    Exceptions::assertReported(RuntimeException::class);
});
