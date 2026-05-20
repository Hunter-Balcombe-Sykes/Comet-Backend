<?php

use App\Jobs\Cache\AggregateCacheMetricsJob;
use App\Listeners\RecordCacheMetrics;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

it('does nothing when the previous hour bucket is empty', function () {
    Redis::shouldReceive('hGetAll')->andReturn([]);
    Log::spy();

    (new AggregateCacheMetricsJob)->handle();

    Log::shouldNotHaveReceived('info');
});

it('logs cache.metrics for each prefix in the bucket', function () {
    Redis::shouldReceive('hGetAll')->andReturn([
        'site:hits' => '80',
        'site:misses' => '20',
        'site:writes' => '10',
        'pro:hits' => '50',
        'pro:misses' => '5',
    ]);

    Log::spy();

    (new AggregateCacheMetricsJob)->handle();

    Log::shouldHaveReceived('info')
        ->with('cache.metrics', \Mockery::on(fn ($ctx) => $ctx['prefix'] === 'site'
            && $ctx['hits'] === 80
            && $ctx['misses'] === 20
            && $ctx['writes'] === 10
            && $ctx['hit_rate'] === 0.8))
        ->once();

    Log::shouldHaveReceived('info')
        ->with('cache.metrics', \Mockery::on(fn ($ctx) => $ctx['prefix'] === 'pro'
            && $ctx['hits'] === 50
            && $ctx['hit_rate'] === round(50 / 55, 4)))
        ->once();
});

it('reports an SLO violation when a hot prefix hits below 90%', function () {
    Redis::shouldReceive('hGetAll')->andReturn([
        'site:hits' => '5',
        'site:misses' => '6', // ~45% hit rate
    ]);

    Log::spy();

    $handler = $this->spy(ExceptionHandler::class);

    (new AggregateCacheMetricsJob)->handle();

    $handler->shouldHaveReceived('report')
        ->once()
        ->withArgs(fn (\Throwable $e) => str_contains($e->getMessage(), 'site')
            && str_contains($e->getMessage(), 'SLO violation'));
});

it('does not report an SLO violation when hit rate is at or above 90%', function () {
    Redis::shouldReceive('hGetAll')->andReturn([
        'site:hits' => '90',
        'site:misses' => '10', // exactly 90%
    ]);

    Log::spy();

    $handler = $this->spy(ExceptionHandler::class);

    (new AggregateCacheMetricsJob)->handle();

    $handler->shouldNotHaveReceived('report');
});

it('does not report an SLO violation for non-hot prefixes below 90%', function () {
    Redis::shouldReceive('hGetAll')->andReturn([
        'brand:hits' => '1',
        'brand:misses' => '20', // very low hit rate but not a tracked SLO prefix
    ]);

    Log::spy();

    $handler = $this->spy(ExceptionHandler::class);

    (new AggregateCacheMetricsJob)->handle();

    $handler->shouldNotHaveReceived('report');
});

it('does not report an SLO violation when total requests are below minimum threshold', function () {
    Redis::shouldReceive('hGetAll')->andReturn([
        'site:hits' => '1',
        'site:misses' => '8', // below 90% but only 9 total — below noise floor
    ]);

    Log::spy();

    $handler = $this->spy(ExceptionHandler::class);

    (new AggregateCacheMetricsJob)->handle();

    $handler->shouldNotHaveReceived('report');
});

it('handles buckets with only writes (no reads)', function () {
    Redis::shouldReceive('hGetAll')->andReturn([
        'pro:writes' => '5',
    ]);

    Log::spy();

    (new AggregateCacheMetricsJob)->handle();

    Log::shouldHaveReceived('info')
        ->with('cache.metrics', \Mockery::on(fn ($ctx) => $ctx['prefix'] === 'pro'
            && $ctx['hits'] === 0
            && $ctx['misses'] === 0
            && $ctx['hit_rate'] === null))
        ->once();
});

it('runs on the default queue', function () {
    $job = new AggregateCacheMetricsJob;
    expect($job->queue)->toBe('default');
});

it('confirms slo_prefixes and threshold constants are as documented', function () {
    expect(RecordCacheMetrics::SLO_PREFIXES)->toContain('site', 'pro');
    expect(RecordCacheMetrics::SLO_MIN_HIT_RATE)->toBe(0.9);
});

it('failed() calls report() so terminal failures alert Nightwatch', function () {
    $handler = $this->spy(\Illuminate\Contracts\Debug\ExceptionHandler::class);

    $e = new \RuntimeException('redis connection lost');
    (new AggregateCacheMetricsJob)->failed($e);

    $handler->shouldHaveReceived('report')->once()->withArgs(fn ($reported) => $reported === $e);
});
