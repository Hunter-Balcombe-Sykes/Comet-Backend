<?php

use App\Jobs\Cache\AggregateCacheMetricsJob;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

// Nightwatch #371 raised the shipped min_sample from 10 to 100. Nearly every
// case below seeds a two-digit bucket, so left on the default they would have
// stopped exercising the SLO comparison entirely — the sample gate would
// short-circuit first and each `shouldNotHaveReceived('report')` would pass for
// the wrong reason, while the positive cases (11 and 21 reads) would have
// failed outright. A test that passes because the code under test no longer
// runs is worse than a failing one.
//
// So the floor is pinned small here on purpose: these cases are about the
// hit-rate comparison, not about the shipped noise floor. The shipped default
// is captured BEFORE the override and asserted on its own, further down.
beforeEach(function () {
    $this->shippedMinSample = (int) config('partna.cache.slo.min_sample');
    config()->set('partna.cache.slo.min_sample', 10);
});

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
    // The value as SHIPPED, captured in beforeEach before the pin above
    // overrode it. Reading config() here would only read the pin back.
    expect($this->shippedMinSample)->toBe(100);
    // Per-prefix targets are derived from each prefix's dominant TTL, not picked:
    // site is public_payload at 900s, pro is professional_model at 60s.
    expect(config('partna.cache.slo.min_hit_rate_by_prefix'))->toBe([
        'site' => 0.90,
        'pro' => 0.80,
    ]);
});

// A hit rate is capped at 1 - 1/(lambda*TTL), so a 60s-TTL prefix and a 900s-TTL
// prefix cannot share one target. These pin that each prefix is judged against
// its own threshold rather than a single scalar.
describe('per-prefix SLO thresholds', function () {
    it('judges each prefix against its own threshold in the same bucket', function () {
        Redis::shouldReceive('hGetAll')->andReturn([
            // Identical 85% hit rates: under site's 0.90, over pro's 0.80. Only a
            // per-prefix threshold can tell these two apart.
            'site:hits' => '85',
            'site:misses' => '15',
            'pro:hits' => '85',
            'pro:misses' => '15',
        ]);

        Log::spy();
        $handler = $this->spy(ExceptionHandler::class);

        (new AggregateCacheMetricsJob)->handle();

        $handler->shouldHaveReceived('report')->once();
        $handler->shouldHaveReceived('report')
            ->withArgs(fn (Throwable $e) => str_contains($e->getMessage(), 'prefix=site')
                && str_contains($e->getMessage(), 'SLO: ≥90%'))
            ->once();
    });

    it('reports pro only once it falls under its own lower threshold', function () {
        Redis::shouldReceive('hGetAll')->andReturn([
            'pro:hits' => '75',
            'pro:misses' => '25', // 75% — under pro's 0.80
        ]);

        Log::spy();
        $handler = $this->spy(ExceptionHandler::class);

        (new AggregateCacheMetricsJob)->handle();

        $handler->shouldHaveReceived('report')
            ->once()
            ->withArgs(fn (Throwable $e) => str_contains($e->getMessage(), 'prefix=pro')
                && str_contains($e->getMessage(), 'SLO: ≥80%'));
    });

    // Prefixes are open-ended — RecordCacheMetrics::extractPrefix() derives one
    // from any cache key's first segment — so a tracked prefix with no map entry
    // must fall back to the scalar rather than to zero. Guards the fallback
    // through future refactors; it holds before and after this change.
    it('falls back to the scalar for a tracked prefix absent from the map', function () {
        config()->set('partna.cache.slo.prefixes', ['analytics']);
        config()->set('partna.cache.slo.min_hit_rate', 0.5);

        Redis::shouldReceive('hGetAll')->andReturn([
            'analytics:hits' => '40',
            'analytics:misses' => '60', // 40% — under the 0.5 scalar
        ]);

        Log::spy();
        $handler = $this->spy(ExceptionHandler::class);

        (new AggregateCacheMetricsJob)->handle();

        $handler->shouldHaveReceived('report')
            ->once()
            ->withArgs(fn (Throwable $e) => str_contains($e->getMessage(), 'prefix=analytics')
                && str_contains($e->getMessage(), 'SLO: ≥50%'));
    });
});

// CACHE-3: the >=90% target and the 10-read noise floor are production numbers.
// A hit rate is capped at 1 - 1/(lambda*TTL), so an environment with near-zero
// traffic cannot reach 90% on a 60s-TTL key no matter how healthy the cache is
// (dev observed ~5.7 hits per recompute => an ~87% ceiling). Both knobs are now
// env-tunable so a low-traffic environment can raise the floor instead of
// alerting hourly on statistically meaningless buckets. The target is tuned per
// prefix (min_hit_rate_by_prefix); the scalar covers unmapped prefixes only.
describe('SLO calibration is configurable (CACHE-3)', function () {
    it('honours a configured minimum hit rate', function () {
        // site is in min_hit_rate_by_prefix, so the map entry is the knob that
        // governs it — the scalar only covers prefixes with no entry.
        config()->set('partna.cache.slo.min_hit_rate_by_prefix.site', 0.5);

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
        config()->set('partna.cache.slo.min_hit_rate_by_prefix.pro', 0.5);
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

it('reports when the public-profile cache TTL exceeds its orphan-key ceiling', function () {
    config()->set('partna.public_profile.cache_ttl_seconds', 301);
    config()->set('partna.public_profile.cache_ttl_ceiling_seconds', 300);

    // Empty bucket on purpose: the ceiling is a property of configuration, not
    // of traffic, so the check has to run above handle()'s empty-bucket return.
    // A quiet hour is exactly when someone would be adjusting TTLs.
    Redis::shouldReceive('hGetAll')->andReturn([]);
    Log::spy();

    $handler = $this->spy(ExceptionHandler::class);

    (new AggregateCacheMetricsJob)->handle();

    $handler->shouldHaveReceived('report')
        ->once()
        ->withArgs(fn (Throwable $e) => str_contains($e->getMessage(), 'orphan-key ceiling')
            && str_contains($e->getMessage(), 'ttl=301s')
            && str_contains($e->getMessage(), 'ceiling: 300s'));
});

it('does not report when the public-profile cache TTL sits exactly at its ceiling', function () {
    config()->set('partna.public_profile.cache_ttl_seconds', 300);
    config()->set('partna.public_profile.cache_ttl_ceiling_seconds', 300);

    Redis::shouldReceive('hGetAll')->andReturn([]);
    Log::spy();

    $handler = $this->spy(ExceptionHandler::class);

    (new AggregateCacheMetricsJob)->handle();

    $handler->shouldNotHaveReceived('report');
});

it('ships a default public-profile TTL that is within its own ceiling', function () {
    // Guards the other direction: lowering the ceiling below the shipped default
    // would make every environment breach on the hour it deployed.
    expect((int) config('partna.public_profile.cache_ttl_seconds'))
        ->toBeLessThanOrEqual((int) config('partna.public_profile.cache_ttl_ceiling_seconds'));
});

it('failed() calls report() so terminal failures alert Nightwatch', function () {
    $handler = $this->spy(ExceptionHandler::class);

    $e = new RuntimeException('redis connection lost');
    (new AggregateCacheMetricsJob)->failed($e);

    $handler->shouldHaveReceived('report')->once()->withArgs(fn ($reported) => $reported === $e);
});
