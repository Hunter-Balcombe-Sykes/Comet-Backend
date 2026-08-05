<?php

use App\Services\FeatureAvailability\FeatureAvailability;
use App\Services\FeatureAvailability\UserFeatureAvailability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FeatureAvailability::for() under a dead cache store.
 *
 * TWO requirements, and they pull in opposite directions — which is exactly why
 * both are asserted here:
 *
 *   1. It must not THROW. Drill 03 (2026-08-05) found `GET /api/site` returning a
 *      raw 500 during a Redis outage whenever the rate limiter did not preempt
 *      it. Root cause: `for()` wrapped its `rememberLocked` call in a try/catch,
 *      but the two `Cache::get()` calls ABOVE that try were unguarded, so a dead
 *      store threw straight past the fail-open handler.
 *
 *   2. It must not FAIL OPEN either. A dead cache is not a dead database.
 *      `CacheLockService::rememberLocked` absorbs store faults and falls through
 *      to `computeWithoutCache()`, which queries Postgres directly — so the real
 *      rule set is still reachable. Returning "everything available" here would
 *      silently lift every staff-disabled feature for the outage's duration.
 *
 * Requirement 2 is the one an obvious fix gets wrong, so it has its own test.
 * Fail-open remains correct for a genuine DB fault, which `for()` handles in its
 * other catch — there we truly cannot know the answer.
 *
 * In production the limiter usually masks requirement 1 as a 503, which is why it
 * had never been seen as a 500. That masking is not a guarantee: the five
 * fail-open limiters (`public-site`, `public-profile`, `analytics`,
 * `analytics-click`, `health-check`) do NOT throw, and any route whose limiter
 * joins that list, or any request with throttling disabled, reaches this code
 * with a live exception.
 */
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    FeatureAvailability::flush();
});

// bindThrowingCacheStore() lives in tests/Pest.php — shared with StrictRevocationTest.

it('does not throw when the cache store is unreachable', function () {
    $pro = createTenant('feat-avail-outage');

    bindThrowingCacheStore();

    expect(FeatureAvailability::for($pro))->toBeInstanceOf(UserFeatureAvailability::class);
});

it('KEEPS a staff-disabled feature disabled when only the cache is dead', function () {
    // The finding that matters, and the reason this file exists in its current
    // shape. An earlier draft of the fix returned an empty override set the moment
    // the cache threw — which silently re-enables every staff-disabled feature and
    // integration (including kill-switches pulled for legal reasons) for the whole
    // outage.
    //
    // That draft justified itself with "rememberLocked would fault on the same
    // store anyway". It would not: CacheLockService::rememberLocked catches store
    // faults and falls through to computeWithoutCache(), which queries Postgres
    // directly. With a dead cache and a healthy DB the TRUE answer is one query
    // away, so failing open there was a self-inflicted security hole.
    //
    // Absence == enabled, so "disabled" is the only state a lost override set can
    // destroy — which makes it the only state worth asserting.
    setupFeatureAvailabilityTable();
    setupSegmentsTables();
    $pro = createTenant('feat-avail-killswitch');

    DB::connection('pgsql')->table('core.feature_availability')->insert([
        'id' => (string) Str::uuid(),
        'feature_key' => 'integration.fresha',
        'mode' => 'disabled',
        'segment_id' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
    FeatureAvailability::flush();

    // Sanity: the rule bites with a healthy cache. Without this the assertion
    // below could pass because the rule never applied at all.
    expect(FeatureAvailability::for($pro)->allows('integration.fresha'))->toBeFalse();

    FeatureAvailability::flush();
    bindThrowingCacheStore();

    expect(FeatureAvailability::for($pro)->allows('integration.fresha'))->toBeFalse();
});

it('serves GET /api/site rather than 500ing when the cache store is unreachable', function () {
    // The route-level proof. This is the exact request drill 03 saw return 500.
    $pro = createTenant('feat-avail-site');   // creates the site.sites row too

    bindThrowingCacheStore();

    actingAsUser($pro)
        ->getJson('/api/site')
        ->assertOk();
});
