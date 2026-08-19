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

it('never hardcodes auto-connect on in the shared instagram machinery', function (string $file) {
    // These three are shared by EVERY Instagram origin — staff build, public
    // site-first signup, dashboard connect, refresh. Hardcoding true in any of
    // them would auto-connect all four and pre-empt the picker the frontend
    // shows in the three that have a human present. Each must thread the
    // caller's flag through instead.
    $source = file_get_contents(base_path($file));

    $this->assertStringNotContainsString(
        'autoConnectBooking: true',
        $source,
        "{$file} is shared by origins that must show a picker; it must pass the caller's flag through, not hardcode it."
    );
    $this->assertStringContainsString(
        'autoConnectBooking',
        $source,
        "{$file} must thread the auto-connect origin flag through, or a staff build silently loses auto-matching."
    );
})->with([
    'app/Services/Platforms/InstagramAutoSync.php',
    'app/Jobs/Platforms/LinkInBioScanJob.php',
    'app/Services/Platforms/InstagramConnectionSeeder.php',
    'app/Jobs/Platforms/InstagramConnectJob.php',
    'app/Jobs/Platforms/GoogleBusinessEnrichJob.php',
    'app/Services/Platforms/GoogleBusinessAutoSync.php',
]);

it('decides auto-connect from built_by_staff_id at the one place that knows', function () {
    // The staff-build condition is read off persisted state, not the publish
    // flag: publish is a presentation choice, "a staff member built this for
    // someone who is not here" is what actually makes a picker impossible.
    $this->assertStringContainsString(
        'built_by_staff_id !== null',
        file_get_contents(base_path('app/Jobs/PreAccount/GeneratePreAccountSiteJob.php')),
        'GeneratePreAccountSiteJob is the only place that can tell a staff build from a public signup.'
    );
});

it('leaves the dashboard paste path unmarked', function () {
    // RoutingController::addManual is the dashboard paste path now — the
    // custom-links controller it replaced was retired 2026-08-19.
    $this->assertStringNotContainsString(
        'autoConnectBooking',
        file_get_contents(base_path('app/Http/Controllers/Api/Routing/RoutingController.php')),
        'A dashboard paste has a human in the loop and must never auto-connect a booking platform on their behalf.'
    );
});

it('leaves the dashboard and refresh dispatch sites unmarked', function (string $file) {
    // These dispatch InstagramConnectJob / GoogleBusinessEnrichJob for a human
    // who is present, so they must omit the argument and take the false default.
    $this->assertStringNotContainsString(
        'autoConnectBooking',
        file_get_contents(base_path($file)),
        "{$file} serves a human with a picker and must leave the menu choice to them."
    );
})->with([
    'app/Http/Controllers/Api/Platforms/InstagramController.php',
    'app/Http/Controllers/Api/Platforms/RefreshController.php',
    'app/Http/Controllers/Api/Platforms/GoogleBusinessController.php',
]);

it('shares one auto-connect dispatcher between both discovery routes', function () {
    // LinkRouter (Instagram bio links) and GoogleBusinessAutoSync (a booking
    // link on a Google listing) reach a seeded Fresha row by different paths.
    // The dispatcher and its daily ceiling live in the trait they share, so the
    // cap is install-wide rather than one budget per route.
    $trait = file_get_contents(base_path('app/Services/Platforms/Concerns/BuildsAutoSyncFindings.php'));

    $this->assertStringContainsString('protected function dispatchAutoBookingConnect(', $trait);
    $this->assertStringContainsString('protected function claimAutoBookingBudget(', $trait);

    foreach (['app/Services/Platforms/LinkRouter.php', 'app/Services/Platforms/GoogleBusinessAutoSync.php'] as $file) {
        $this->assertStringContainsString(
            '$this->dispatchAutoBookingConnect(',
            file_get_contents(base_path($file)),
            "{$file} produces seeded Fresha rows and must dispatch through the shared trait method."
        );
    }
});

it('canonicalises the fresha url on BOTH write paths', function () {
    // resolveWrite (Instagram) and resolveBookingWrite (Google Business) are
    // separate methods. slugFromUrl only understands /a/<slug>, so a raw
    // book-now URL on either path makes the employee leg impossible and every
    // match silently degrades to storewide.
    $this->assertStringContainsString(
        'canonicalUrl($url)',
        file_get_contents(base_path('app/Services/Platforms/Concerns/BuildsAutoSyncFindings.php')),
        'resolveWrite must canonicalise the Instagram-side Fresha URL.'
    );
    $this->assertStringContainsString(
        'canonicalUrl($url)',
        file_get_contents(base_path('app/Services/Platforms/GoogleBusinessAutoSync.php')),
        'resolveBookingWrite must canonicalise the Google-Business-side Fresha URL.'
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
