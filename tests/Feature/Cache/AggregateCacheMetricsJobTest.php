<?php

use App\Jobs\Cache\AggregateCacheMetricsJob;
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
        ->with('cache.metrics', Mockery::on(fn ($ctx) => $ctx['prefix'] === 'site'
            && $ctx['hits'] === 80
            && $ctx['misses'] === 20
            && $ctx['writes'] === 10
            && $ctx['hit_rate'] === 0.8))
        ->once();

    Log::shouldHaveReceived('info')
        ->with('cache.metrics', Mockery::on(fn ($ctx) => $ctx['prefix'] === 'pro'
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
        ->withArgs(fn (Throwable $e) => str_contains($e->getMessage(), 'site')
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
        ->with('cache.metrics', Mockery::on(fn ($ctx) => $ctx['prefix'] === 'pro'
            && $ctx['hits'] === 0
            && $ctx['misses'] === 0
            && $ctx['hit_rate'] === null))
        ->once();
});

it('runs on the default queue', function () {
    $job = new AggregateCacheMetricsJob;
    expect($job->queue)->toBe('default');
});

it('confirms slo prefixes and threshold defaults are as documented', function () {
    expect(config('partna.cache.slo.prefixes'))->toContain('site', 'pro');
    expect(config('partna.cache.slo.min_hit_rate'))->toBe(0.9);
    expect(config('partna.cache.slo.min_sample'))->toBe(10);
});

// CACHE-3: the >=90% target and the 10-read noise floor are production numbers.
// A hit rate is capped at 1 - 1/(lambda*TTL), so an environment with near-zero
// traffic cannot reach 90% on a 60s-TTL key no matter how healthy the cache is
// (dev observed ~5.7 hits per recompute => an ~87% ceiling). Both knobs are now
// env-tunable so a low-traffic environment can raise the floor instead of
// alerting hourly on statistically meaningless buckets.
describe('SLO calibration is configurable (CACHE-3)', function () {
    it('honours a configured minimum hit rate', function () {
        config()->set('partna.cache.slo.min_hit_rate', 0.5);

        Redis::shouldReceive('hGetAll')->andReturn([
            'site:hits' => '60',
            'site:misses' => '40', // 60% — under the 0.9 default, over the configured 0.5
        ]);

        Log::spy();
        $handler = $this->spy(ExceptionHandler::class);

        (new AggregateCacheMetricsJob)->handle();

        $handler->shouldNotHaveReceived('report');
    });

    it('honours a configured sample floor', function () {
        config()->set('partna.cache.slo.min_sample', 500);

        Redis::shouldReceive('hGetAll')->andReturn([
            'site:hits' => '10',
            'site:misses' => '90', // 10% hit rate, but only 100 reads — below the configured floor
        ]);

        Log::spy();
        $handler = $this->spy(ExceptionHandler::class);

        (new AggregateCacheMetricsJob)->handle();

        $handler->shouldNotHaveReceived('report');
    });

    it('honours a configured prefix list', function () {
        config()->set('partna.cache.slo.prefixes', ['analytics']);

        Redis::shouldReceive('hGetAll')->andReturn([
            'site:hits' => '1',
            'site:misses' => '20', // no longer a tracked prefix
            'analytics:hits' => '1',
            'analytics:misses' => '20', // now tracked
        ]);

        Log::spy();
        $handler = $this->spy(ExceptionHandler::class);

        (new AggregateCacheMetricsJob)->handle();

        $handler->shouldHaveReceived('report')
            ->once()
            ->withArgs(fn (Throwable $e) => str_contains($e->getMessage(), 'analytics'));
    });

    it('still reports when a configured threshold is genuinely breached', function () {
        config()->set('partna.cache.slo.min_hit_rate', 0.5);
        config()->set('partna.cache.slo.min_sample', 20);

        Redis::shouldReceive('hGetAll')->andReturn([
            'pro:hits' => '10',
            'pro:misses' => '40', // 20% over 50 reads — breaches both configured knobs
        ]);

        Log::spy();
        $handler = $this->spy(ExceptionHandler::class);

        (new AggregateCacheMetricsJob)->handle();

        $handler->shouldHaveReceived('report')
            ->once()
            ->withArgs(fn (Throwable $e) => str_contains($e->getMessage(), 'pro')
                && str_contains($e->getMessage(), 'SLO: ≥50%'));
    });
});

it('failed() calls report() so terminal failures alert Nightwatch', function () {
    $handler = $this->spy(ExceptionHandler::class);

    $e = new RuntimeException('redis connection lost');
    (new AggregateCacheMetricsJob)->failed($e);

    $handler->shouldHaveReceived('report')->once()->withArgs(fn ($reported) => $reported === $e);
});
