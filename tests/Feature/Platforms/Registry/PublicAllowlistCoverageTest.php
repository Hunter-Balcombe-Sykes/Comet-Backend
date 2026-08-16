<?php

// TEST-3: every REGISTERED platform must have a PublicIntegrationConnectionResource
// allowlist entry. Missing one means filterPayload()'s fail-closed branch fires —
// the connection saves and shows on the dashboard, but renders EMPTY on the public
// sitepage, and reports MissingPublicAllowlistException to Nightwatch on every
// public request for that profile.
//
// Deliberately NOT a hardcoded exemption list (that's exactly how the original
// gap — 5 platforms shipped in e1879529 with no entry — went unnoticed): this
// runs the REAL resource over every live registry key and lets its own
// behaviour decide. `booking`/`reservations` pass via their explicit empty
// entries, and so do the retired events platforms and — since slice 5b Task 8
// removed the short-circuit this comment used to describe — `shop`. That
// removal is precisely the case this guard was written to catch, and it caught
// it: an empty `'shop' => []` entry is what keeps it green.

use App\Catalog\CompiledCatalog;
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

// Convergence Phase 6. The test above iterates PlatformRegistry::keys(), and
// that set is FROZEN: CatalogLegacyMapTest pins LegacyPlatformMap to the
// 20260727110001 backfill CASE pair-for-pair, and RegistryCoverageTest chains
// the registry to the same 78 slugs. So every brand added after P1 —
// uber_eats, doordash, menulog, shopify, deliveroo, … — is CATALOG-ONLY and was
// structurally outside the guard's reach, while still being perfectly writable
// (IntegrationConnection's saving guard defers to CompiledCatalog).
//
// The gap was live, not hypothetical: showcase-eats published doordash, menulog,
// uber_eats and shopify as EMPTY payloads on dev-api.partna.au and reported
// MissingPublicAllowlistException on every public request (Nightwatch #436).
//
// `platform` is deliberately set to the SURFACE key here rather than the brand:
// that is the production write path (setPlatformAttribute accepts a surface key
// verbatim), so the accessor derives the legacy slug exactly as a real row does.
// Deriving the expected slug in the test instead would let the two drift.
it('never reports MissingPublicAllowlistException for a catalog surface', function () {
    $fake = Exceptions::fake();

    foreach (array_keys(CompiledCatalog::surfaces()) as $surfaceKey) {
        $carrier = new IntegrationConnection([
            'platform' => $surfaceKey,
            'resource_id' => $surfaceKey,
            'payload' => ['__probe' => 'x'],
        ]);

        (new PublicIntegrationConnectionResource($carrier))->toArray(request());
    }

    $missing = collect($fake->reported())
        ->filter(fn ($e) => $e instanceof MissingPublicAllowlistException)
        ->map(fn (MissingPublicAllowlistException $e) => $e->platform)
        ->unique()
        ->values()
        ->all();

    expect($missing)->toBe([], 'Catalog brand(s) have no PublicIntegrationConnectionResource::ALLOWLIST entry — '
        .'a connection on their surface renders EMPTY on every public sitepage and reports '
        .'MissingPublicAllowlistException to Nightwatch on every request. Add an entry keyed by the '
        .'BRAND prefix (not the surface key), or `=> []` if the brand is deliberately dashboard-only: '
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
