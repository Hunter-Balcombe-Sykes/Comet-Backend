<?php

use App\Services\Platforms\Registry\PlatformRegistry;
use App\Services\Platforms\Registry\PlatformRouteShape;

// FOUND-24 regression guard. There is exactly ONE way to add a platform:
// a PlatformDescriptor in PlatformRegistryServiceProvider (connectInput +
// connect strategy + routes(...) — plus highlights(...) for picker platforms).
// Hand-written per-platform controllers are reserved for the bespoke set below;
// adding a controller file = add a descriptor instead, or justify a new
// allowlist entry in this file.

const BESPOKE_CONTROLLER_ALLOWLIST = [
    'AppleController.php',            // dual music+podcast platforms in one controller
    'BookingController.php',          // smart-detect category facade
    'CustomLinksController.php',      // arbitrary link cards, not a platform
    'EventbriteController.php',       // events archetype (organiser accounts + standalone events)
    'EventsController.php',           // events smart-detect facade
    'EventsPlatformController.php',   // shared events base
    'FreshaController.php',           // picker flow (team/services selection)
    'GenericPlatformController.php',  // THE registry-driven controller
    'GoogleBusinessController.php',   // Places picker + cross-platform sync
    'HumanitixController.php',        // events archetype
    'InstagramController.php',        // async connect (job + poll)
    'IntegrationsMetaController.php', // cross-platform sync metadata
    'MenuController.php',             // menu view, no connect
    'OnlineOrderingController.php',   // ordering-links category
    'OpenTableController.php',        // suggestion() only — connect is registry-driven
    'RefreshController.php',          // manual refresh, cross-platform
    'ReservationsController.php',     // smart-detect category facade
    'ShopController.php',             // multi-brand picker
    'SkoolController.php',            // single-selection; needs a payload DTO before migrating
    'SquareController.php',           // XOR-with-fresha guard on connect
];

it('allows no per-platform controllers beyond the bespoke allowlist', function () {
    $files = collect(glob(app_path('Http/Controllers/Api/Platforms/*.php')))
        ->map(fn (string $path) => basename($path))
        ->sort()
        ->values();

    $unexpected = $files->diff(BESPOKE_CONTROLLER_ALLOWLIST)->values()->all();

    expect($unexpected)->toBe([], 'New platform controllers are forbidden — register a PlatformDescriptor '
        .'with a ConnectStrategy in PlatformRegistryServiceProvider instead (see FOUND-24). Unexpected: '
        .implode(', ', $unexpected));
});

it('keeps every allowlisted bespoke controller in existence (no stale entries)', function () {
    foreach (BESPOKE_CONTROLLER_ALLOWLIST as $file) {
        expect(file_exists(app_path('Http/Controllers/Api/Platforms/'.$file)))
            ->toBeTrue("Stale allowlist entry: {$file} no longer exists — remove it.");
    }
});

it('requires every registry-routed platform without a bespoke controller to be fully descriptor-driven', function () {
    $registry = app(PlatformRegistry::class);

    foreach ($registry->all() as $key => $descriptor) {
        if ($descriptor->routeShape() === PlatformRouteShape::Bespoke) {
            continue;
        }
        if ($descriptor->connectController() !== null) {
            continue; // SingleSelection bespoke connect (skool, google-business)
        }

        expect($descriptor->connectStrategy())->not->toBeNull("{$key}: registry-routed with no ConnectStrategy");
        expect($descriptor->connectField())->not->toBeNull("{$key}: registry-routed with no connectInput()");
        expect($descriptor->resourceClass())->not->toBeNull("{$key}: registry-routed with no resource()");
    }
});

it('has fully deleted the single-selection controller base', function () {
    expect(class_exists('App\Http\Controllers\Api\Platforms\SingleSelectionPlatformController'))->toBeFalse();
});
