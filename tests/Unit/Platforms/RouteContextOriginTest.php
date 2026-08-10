<?php

use App\Services\Platforms\RouteContext;
use Tests\TestCase;

// tests/Unit is NOT bound to TestCase in Pest.php — base_path() and the PHPUnit
// string assertions below both need it, so opt in per-file.
uses(TestCase::class)->in(__FILE__);

it('defaults auto-connect to off so unmarked callers never auto-connect', function () {
    expect((new RouteContext)->autoConnectBooking)->toBeFalse();
});

it('can be constructed as an instagram-origin run', function () {
    expect((new RouteContext(autoConnectBooking: true))->autoConnectBooking)->toBeTrue();
});

it('keeps the probe budget independent of the origin flag', function () {
    $ctx = new RouteContext(maxProbes: 2, autoConnectBooking: true);
    expect($ctx->maxProbes)->toBe(2)->and($ctx->autoConnectBooking)->toBeTrue();
});

it('leaves the probe dedupe untouched by the origin flag', function () {
    // RouteContext is shared with the link-probe host-dedupe work; the origin
    // flag must not perturb the budget accounting that lives alongside it.
    $ctx = new RouteContext(autoConnectBooking: true);

    expect($ctx->consumeProbeFor('https://example.com/'))->toBeTrue()
        ->and($ctx->consumeProbeFor('https://example.com/about'))->toBeFalse()
        ->and($ctx->sitesDeduped())->toBe(1)
        ->and($ctx->probesDenied())->toBe(0);
});

it('marks every instagram-origin construction site', function (string $file) {
    $this->assertStringContainsString(
        'autoConnectBooking: true',
        file_get_contents(base_path($file)),
        "{$file} builds a RouteContext from an Instagram-origin scrape and must opt into auto-connect; unmarked, it silently defaults to false and the Fresha menu is never fetched."
    );
})->with([
    'app/Services/Platforms/InstagramAutoSync.php',
    'app/Jobs/Platforms/LinkInBioScanJob.php',
    'app/Services/Platforms/InstagramConnectionSeeder.php',
]);

it('leaves the dashboard paste path unmarked', function () {
    $this->assertStringNotContainsString(
        'autoConnectBooking',
        file_get_contents(base_path('app/Http/Controllers/Api/Platforms/CustomLinksController.php')),
        'A dashboard paste has a human in the loop and must never auto-connect a booking platform on their behalf.'
    );
});

it('leaves the generic seeder fallback unmarked', function () {
    // CustomLinkSeeder::seed() builds a fallback context when a caller passes
    // none. That path serves the dashboard too, so it must default to false —
    // the Instagram loops all pass their own marked context in.
    $this->assertStringNotContainsString(
        'autoConnectBooking',
        file_get_contents(base_path('app/Services/Platforms/CustomLinkSeeder.php')),
        'CustomLinkSeeder is origin-agnostic; marking its fallback context would auto-connect dashboard pastes.'
    );
});
