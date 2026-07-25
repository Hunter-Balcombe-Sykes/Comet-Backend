<?php

use Illuminate\Support\Facades\Route;
use Tests\Feature\FeatureFlags\FeatureFlagTestCase;

use function Pest\Laravel\get;

beforeEach(fn () => FeatureFlagTestCase::boot());

it('returns 503 when the named feature flag is off', function () {
    // Ad-hoc key — real launch flags no longer live in config; this proves
    // the middleware's own gating logic, not any specific flag.
    config()->set('partna.features.__test_gate', false);

    Route::middleware('feature:__test_gate')
        ->get('/__test/feature-gate', fn () => response()->json(['ok' => true]));

    get('/__test/feature-gate')
        ->assertStatus(503)
        ->assertJson(['message' => 'Feature not available']);
});

it('passes through when the named feature flag is on', function () {
    config()->set('partna.features.__test_gate', true);

    Route::middleware('feature:__test_gate')
        ->get('/__test/feature-gate-on', fn () => response()->json(['ok' => true]));

    get('/__test/feature-gate-on')
        ->assertOk()
        ->assertJson(['ok' => true]);
});

it('returns 503 for unknown flag keys (fail closed)', function () {
    Route::middleware('feature:nonexistent_flag')
        ->get('/__test/feature-gate-unknown', fn () => response()->json(['ok' => true]));

    get('/__test/feature-gate-unknown')->assertStatus(503);
});
