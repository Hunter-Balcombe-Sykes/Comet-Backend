<?php

use App\Jobs\Platforms\GoogleBusinessEnrichJob;
use App\Jobs\Platforms\InstagramConnectJob;
use App\Jobs\Platforms\MenuFetchJob;
use App\Jobs\Platforms\ThrottledByProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// Seam 5 / strategy §5 step 4: the three paid Apify CONNECT jobs must carry an
// explicit per-provider (per-actor) burst gate — the same shape RefreshConnectionJob
// already has — so a signup spike can't stampede a single Apify actor. ApifyBudget
// caps daily SPEND; the 'platform-connect' RateLimiter caps per-minute BURST RATE.
// Refresh (official APIs) and connect (Apify) never share a vendor, so this is a
// SEPARATE limiter keyed by Apify actor, not the refresh limiter.

dataset('connectJobs', [
    'instagram' => [fn () => new InstagramConnectJob('u', 'name', 'conn'), 'instagram'],
    'menu' => [fn () => new MenuFetchJob('u'), 'menu'],
    'google-business' => [fn () => new GoogleBusinessEnrichJob('u', 'place'), 'google-business'],
]);

it('exposes its apify actor as the provider rate key', function (Closure $make, string $actor) {
    $job = $make();

    expect($job)->toBeInstanceOf(ThrottledByProvider::class)
        ->and($job->providerRateKey())->toBe($actor);
})->with('connectJobs');

it('applies the platform-connect RateLimited middleware', function (Closure $make) {
    $job = $make();

    expect(method_exists($job, 'middleware'))->toBeTrue()
        ->and(collect($job->middleware())->contains(fn ($m) => $m instanceof RateLimited))->toBeTrue();
})->with('connectJobs');

it('registers a per-provider platform-connect limiter keyed by actor', function (Closure $make, string $actor) {
    $callback = RateLimiter::limiter('platform-connect');
    expect($callback)->not->toBeNull();

    $limit = $callback($make());
    $limit = is_array($limit) ? $limit[0] : $limit;

    expect($limit)->toBeInstanceOf(Limit::class)
        ->and($limit->key)->toBe('platform-connect:'.$actor)
        ->and($limit->maxAttempts)->toBe((int) config('partna.connect.rate_limits.default'));
})->with('connectJobs');

it('uses tries=0 + retryUntil so a rate-limit release never fails a connect', function (Closure $make) {
    $job = $make();

    // A RateLimited release counts as an attempt; a finite $tries would mass-fail
    // connects during the exact spike this gate exists to absorb. Genuine errors
    // stay capped by $maxExceptions.
    expect($job->tries)->toBe(0)
        ->and($job->maxExceptions)->toBe(2)
        ->and($job->retryUntil())->toBeInstanceOf(DateTimeInterface::class)
        ->and($job->retryUntil()->getTimestamp())->toBeGreaterThan(now()->getTimestamp());
})->with('connectJobs');
