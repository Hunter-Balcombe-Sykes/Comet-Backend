<?php

// TEST-3: every REGISTERED platform must have a PublicIntegrationConnectionResource
// allowlist entry (or be provably exempt, like `shop`'s short-circuit before the
// lookup). Missing one means filterPayload()'s fail-closed branch fires — the
// connection saves and shows on the dashboard, but renders EMPTY on the public
// sitepage, and reports MissingPublicAllowlistException to Nightwatch on every
// public request for that profile.
//
// Deliberately NOT a hardcoded exemption list (that's exactly how the original
// gap — 5 platforms shipped in e1879529 with no entry — went unnoticed): this
// runs the REAL resource over every live registry key and lets its own
// behaviour decide. `shop` passes because its short-circuit never reaches the
// allowlist lookup; `booking`/`reservations` pass via their explicit empty
// entries; if the shop short-circuit is ever removed, this starts correctly
// demanding a `shop` entry instead of silently staying green.

use App\Exceptions\Platforms\MissingPublicAllowlistException;
use App\Http\Resources\Platforms\PublicIntegrationConnectionResource;
use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\Registry\PlatformRegistry;
use Illuminate\Support\Facades\Exceptions;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

it('never reports MissingPublicAllowlistException for a currently-registered platform', function () {
    $fake = Exceptions::fake();

    foreach (app(PlatformRegistry::class)->keys() as $key) {
        // Unsaved carrier — no DB round-trip needed, and it sidesteps the SEC-1
        // saving guard (irrelevant here — every key IS registered). The probe
        // payload must be a non-empty ARRAY: filterPayload() returns early on a
        // non-array payload BEFORE the allowlist lookup (SEC-3), so a null/scalar
        // probe would make every platform pass vacuously and this guard useless.
        $carrier = new IntegrationConnection([
            'platform' => $key,
            'resource_id' => $key,
            'payload' => ['__probe' => 'x'],
        ]);

        (new PublicIntegrationConnectionResource($carrier))->toArray(request());
    }

    $missing = collect($fake->reported())
        ->filter(fn ($e) => $e instanceof MissingPublicAllowlistException)
        ->map(fn (MissingPublicAllowlistException $e) => $e->platform)
        ->values()
        ->all();

    expect($missing)->toBe([], 'Registered platform(s) have no PublicIntegrationConnectionResource::ALLOWLIST entry — '
        .'their payload renders EMPTY on every public sitepage and reports '
        .'MissingPublicAllowlistException to Nightwatch on every request. '
        ."Add `'<key>' => [...public payload keys...]` to that const, or `'<key>' => []` if the "
        .'platform is deliberately dashboard-only (as booking/reservations are): '
        .implode(', ', $missing));
});

// Mutation-proof: without this, the test above could pass vacuously forever
// (e.g. if the probe payload shape stopped reaching the allowlist branch).
// Proves the harness CAN detect a missing entry by feeding it a platform key
// that is deliberately never registered, mirroring the technique in
// RefreshObservabilityTest.php's "reports (and fails closed...)" case.
it('proves the guard can fail: an unregistered platform key IS reported', function () {
    $fake = Exceptions::fake();

    $carrier = new IntegrationConnection([
        'platform' => 'totally-unregistered',
        'resource_id' => 'totally-unregistered',
        'payload' => ['__probe' => 'x'],
    ]);

    (new PublicIntegrationConnectionResource($carrier))->toArray(request());

    $reportedPlatforms = collect($fake->reported())
        ->filter(fn ($e) => $e instanceof MissingPublicAllowlistException)
        ->map(fn (MissingPublicAllowlistException $e) => $e->platform)
        ->values()
        ->all();

    expect($reportedPlatforms)->toBe(['totally-unregistered']);
});
