<?php

use Tests\TestCase;

// tests/Unit is NOT bound to TestCase in Pest.php — config() needs the app.
uses(TestCase::class)->in(__FILE__);

it('enables auto booking connect by default', function () {
    expect(config('partna.connect.auto_booking.enabled'))->toBeTrue();
});

it('caps auto booking fetches globally per day', function () {
    expect(config('partna.connect.auto_booking.global_daily_cap'))->toBe(500);
});

it('caches the salon menu scrape for an hour', function () {
    expect(config('partna.connect.auto_booking.menu_cache_seconds'))->toBe(3600);
});

it('resolves the services listing cap FreshaAutoSelector reads', function () {
    // The obvious-looking partna.limits.services_max is null — the key is nested
    // under `pagination`. Reading the wrong path silently pins the cap to the
    // hardcoded default and ignores PARTNA_LIMITS_SERVICES_MAX entirely.
    expect(config('partna.limits.pagination.services_max'))->not->toBeNull()
        ->and(config('partna.limits.services_max'))->toBeNull();
});
