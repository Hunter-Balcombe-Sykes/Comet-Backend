<?php

use App\Jobs\Platforms\ThrottledByProvider;
use App\Jobs\PreAccount\ApproveEarlyAccessBuildJob;
use App\Jobs\PreAccount\GeneratePreAccountSiteJob;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// Both pre-account scraping-lane jobs must carry a per-provider burst gate so a
// spike (esp. bulk early-access approval, which fans one job per lead) can't
// stampede an external vendor. The vendor DIFFERS by source_type: 'instagram'
// hits the paid Apify account SHARED with the dashboard-connect IG lane, so it
// rides the SAME 'platform-connect' limiter keyed 'instagram' (one global Apify
// budget); 'google_business' hits the official Google Places API — a different
// vendor — so it gets its own 'preaccount-places' limiter. Mirrors
// ConnectThrottleTest, which pins the connect-lane half of this scheme.

// RateLimited::$limiterName is protected; read it to prove which named limiter a
// job routes to (the name is half of the cache-bucket identity).
function preaccountLimiterName(RateLimited $mw): string
{
    $ref = new ReflectionProperty($mw, 'limiterName');
    $ref->setAccessible(true);

    return (string) $ref->getValue($mw);
}

// [factory, provider actor, limiter name, resolved bucket key] — ordered so each
// test's leading params line up (later values are ignored where unused).
dataset('scrapeJobs', [
    'generate/instagram' => [fn () => new GeneratePreAccountSiteJob('build-1', 'instagram'), 'instagram', 'platform-connect', 'platform-connect:instagram'],
    'generate/google_business' => [fn () => new GeneratePreAccountSiteJob('build-1', 'google_business'), 'google-business', 'preaccount-places', 'preaccount-places:google-business'],
    'approve/instagram' => [fn () => new ApproveEarlyAccessBuildJob('signup-1', 'instagram'), 'instagram', 'platform-connect', 'platform-connect:instagram'],
    'approve/google_business' => [fn () => new ApproveEarlyAccessBuildJob('signup-1', 'google_business'), 'google-business', 'preaccount-places', 'preaccount-places:google-business'],
]);

it('exposes its provider actor as the rate key, per source_type', function (Closure $make, string $actor) {
    $job = $make();

    expect($job)->toBeInstanceOf(ThrottledByProvider::class)
        ->and($job->providerRateKey())->toBe($actor);
})->with('scrapeJobs');

it('carries the vendor-appropriate RateLimited middleware', function (Closure $make, string $actor, string $limiterName) {
    $job = $make();
    $limiters = collect($job->middleware())->filter(fn ($m) => $m instanceof RateLimited);

    expect($limiters)->toHaveCount(1)
        ->and(preaccountLimiterName($limiters->first()))->toBe($limiterName);
})->with('scrapeJobs');

it('resolves each middleware limiter to the expected per-vendor bucket', function (Closure $make, string $actor, string $limiterName, string $bucketKey) {
    $callback = RateLimiter::limiter($limiterName);
    expect($callback)->not->toBeNull();

    $limit = $callback($make());
    $limit = is_array($limit) ? $limit[0] : $limit;

    expect($limit)->toBeInstanceOf(Limit::class)
        ->and($limit->key)->toBe($bucketKey);
})->with('scrapeJobs');

it('shares the paid-Apify platform-connect budget for the instagram source', function () {
    // The pre-account IG bucket key must EQUAL the connect lane's IG key, and read
    // the same connect-rate config, so both lanes draw from ONE global Apify budget.
    $limit = RateLimiter::limiter('platform-connect')(new GeneratePreAccountSiteJob('build-1', 'instagram'));
    $limit = is_array($limit) ? $limit[0] : $limit;

    expect($limit->key)->toBe('platform-connect:instagram')
        ->and($limit->maxAttempts)->toBe((int) config('partna.connect.rate_limits.default'));
});

it('sizes the google_business Places limiter from pre_account config', function () {
    $limit = RateLimiter::limiter('preaccount-places')(new GeneratePreAccountSiteJob('build-1', 'google_business'));
    $limit = is_array($limit) ? $limit[0] : $limit;

    expect($limit->maxAttempts)->toBe((int) config('partna.pre_account.rate_limits.default'));
});

it('uses release-tolerant retry semantics so a throttle release never re-bills a scrape', function (Closure $make) {
    $job = $make();

    // A RateLimited throttle RELEASES the job, and every release counts as an
    // attempt — so tries must be 0 (releases governed by retryUntil, not tries).
    // failOnTimeout + maxExceptions=1 preserve the original tries=1 intent: a real
    // error or a timeout fails fast, never retrying the (already-billed) scrape.
    expect($job->tries)->toBe(0)
        ->and($job->maxExceptions)->toBe(1)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and($job->retryUntil())->toBeInstanceOf(DateTimeInterface::class)
        ->and($job->retryUntil()->getTimestamp())->toBeGreaterThan(now()->getTimestamp())
        // Stays within the 600s ShouldBeUnique window so a rate-parked job can't
        // outlive its unique lock and let a duplicate bill a second scrape.
        ->and($job->retryUntil()->getTimestamp())->toBeLessThanOrEqual(now()->addSeconds($job->uniqueFor)->getTimestamp());
})->with('scrapeJobs');
