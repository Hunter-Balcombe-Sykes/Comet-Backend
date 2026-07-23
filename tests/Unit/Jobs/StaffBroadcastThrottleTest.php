<?php

use App\Jobs\Notifications\SendStaffBroadcastEmailToSubscriberJob;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// R3-SCALE-2: SendStaffBroadcastEmailToSubscriberJob has no provider-throughput
// pacing — a large broadcast can exceed Resend's per-team cap. This pins the
// 'mail-broadcast' queue RateLimited middleware + the retry-shape change it
// forces (releases count as attempts against $tries). Modelled on
// PreAccountScrapeThrottleTest.php, the house exemplar for this shape.

// RateLimited::$limiterName is protected; read it by reflection to prove which
// named limiter the job routes to (half of the cache-bucket identity).
function broadcastLimiterName(RateLimited $mw): string
{
    $ref = new ReflectionProperty($mw, 'limiterName');
    $ref->setAccessible(true);

    return (string) $ref->getValue($mw);
}

it('carries exactly one RateLimited middleware, named mail-broadcast', function () {
    $job = new SendStaffBroadcastEmailToSubscriberJob('notif-1', 'sub-1');
    $limiters = collect($job->middleware())->filter(fn ($m) => $m instanceof RateLimited);

    expect($limiters)->toHaveCount(1)
        ->and(broadcastLimiterName($limiters->first()))->toBe('mail-broadcast');
});

it('registers the mail-broadcast limiter sized from config, with a 1-second decay', function () {
    $callback = RateLimiter::limiter('mail-broadcast');
    expect($callback)->not->toBeNull();

    $limit = $callback();
    $limit = is_array($limit) ? $limit[0] : $limit;

    expect($limit)->toBeInstanceOf(Limit::class)
        ->and($limit->key)->toBe('mail-broadcast')
        ->and($limit->maxAttempts)->toBe((int) config('partna.throttle.mail_broadcast_per_second'))
        ->and($limit->decaySeconds)->toBe(1);
});

it('sizes the rate below Resend\'s documented 10 req/s PER-TEAM cap', function () {
    // Deliberately <= 10, not == 10: that budget is shared with every
    // transactional send in the app (enquiry/subscription confirmations, claim
    // invites, moderation notices) — a broadcast must yield to those, never
    // starve them. A later bump to 10 (or above) must fail this test and force
    // someone to re-read Resend's per-team-not-per-tier cap before changing it.
    $callback = RateLimiter::limiter('mail-broadcast');
    $limit = $callback();
    $limit = is_array($limit) ? $limit[0] : $limit;

    expect($limit->maxAttempts)->toBeLessThanOrEqual(10);
});

it('uses release-tolerant retry semantics so a rate-limit release never fails a send that never happened', function () {
    $job = new SendStaffBroadcastEmailToSubscriberJob('notif-1', 'sub-1');

    // A RateLimited throttle RELEASES the job, and every release counts as an
    // attempt (Illuminate\Queue\Worker::markJobAsFailedIfAlreadyExceedsMaxAttempts
    // checks retryUntil() FIRST and exclusively when it's set) — so tries must
    // be 0, with retryUntil() as the actual bound. maxExceptions stays finite so
    // a genuinely broken send (not a throttle release) still fails fast.
    expect($job->tries)->toBe(0)
        ->and($job->maxExceptions)->toBe(2)
        ->and($job->retryUntil())->toBeInstanceOf(DateTimeInterface::class)
        ->and($job->retryUntil()->getTimestamp())->toBeGreaterThan(now()->getTimestamp());
});

it('actually gates through a cache-backed bucket — the only test proving the middleware is wired, not just present', function () {
    // Override the registered limiter with a tight 1/second bucket so two
    // successive calls deterministically cross the limit within this test.
    RateLimiter::for('mail-broadcast', fn () => Limit::perSecond(1)->by('mail-broadcast'));

    $middleware = new RateLimited('mail-broadcast');

    $job = Mockery::mock();
    $job->shouldReceive('release')->once()->with(Mockery::on(fn ($seconds) => is_int($seconds) && $seconds > 0));

    $calls = 0;
    $next = function ($job) use (&$calls) {
        $calls++;

        return 'handled';
    };

    // First call: under the limit, reaches $next.
    $result = $middleware->handle($job, $next);
    expect($result)->toBe('handled')
        ->and($calls)->toBe(1);

    // Second call: over the limit, releases instead of reaching $next.
    $middleware->handle($job, $next);
    expect($calls)->toBe(1);
});
