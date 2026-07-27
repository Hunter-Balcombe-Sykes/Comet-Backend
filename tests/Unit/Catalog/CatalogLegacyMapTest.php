<?php

use App\Catalog\CompiledCatalog;
use App\Catalog\LegacyPlatformMap;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// LegacyPlatformMap is consumed in three forms that must never drift: the PHP
// map itself, the 20260727110000 migration's SQL CASEs, and the compiled
// catalog artefact. These tests pin all three.

it('round-trips every legacy platform through surface and back', function () {
    foreach (LegacyPlatformMap::toSurfaceMap() as $legacy => $surface) {
        expect(LegacyPlatformMap::legacyFor($surface))->toBe($legacy, "surface {$surface} does not alias back to {$legacy}");
    }
});

it('gives every mapped surface a routing class', function () {
    foreach (LegacyPlatformMap::toSurfaceMap() as $surface) {
        expect(LegacyPlatformMap::routingClassFor($surface))->not->toBeNull("surface {$surface} has no routing class");
    }
});

it('keeps the special back-map exactly the set of surfaces whose legacy slug is not their brand prefix', function () {
    $expectedSpecials = [];
    foreach (LegacyPlatformMap::toSurfaceMap() as $legacy => $surface) {
        if (explode('.', $surface, 2)[0] !== $legacy) {
            $expectedSpecials[$surface] = $legacy;
        }
    }
    ksort($expectedSpecials);
    $actual = LegacyPlatformMap::specialToLegacyMap();
    ksort($actual);

    expect($actual)->toBe($expectedSpecials);
});

it('retires a platform without pretending the migration never mapped it', function () {
    // A retired platform stays in the historical backfill CASE (that file
    // records what actually ran) but must be gone from every LIVE map, so a
    // retired slug can never be written again.
    foreach (LegacyPlatformMap::retiredMap() as $legacy => $surface) {
        expect(LegacyPlatformMap::toSurfaceMap())->not->toHaveKey($legacy)
            ->and(LegacyPlatformMap::surfaceFor($legacy))->toBeNull()
            ->and(LegacyPlatformMap::routingClassFor($surface))->toBeNull()
            ->and(LegacyPlatformMap::isKnownSurface($surface))->toBeFalse();
    }
});

it('matches the backfill migration CASE pair-for-pair', function () {
    $sql = file_get_contents(base_path('supabase/migrations/20260727110000_connections_surface_key.sql'));

    // The surface backfill: WHEN '<legacy>' THEN '<surface>' pairs.
    preg_match_all("~WHEN '([a-z0-9-]+)' THEN '([a-z0-9_]+\\.[a-z0-9_]+)'~", $sql, $m, PREG_SET_ORDER);
    $sqlPairs = [];
    foreach ($m as [, $legacy, $surface]) {
        $sqlPairs[$legacy] = $surface;
    }

    // Historical: the CASE also carries platforms retired since it ran.
    // Both sides are sorted — a mapping's key ORDER carries no meaning, and
    // asserting on it would fail the day someone alphabetises either list.
    $expected = LegacyPlatformMap::historicalSurfaceMap();
    ksort($sqlPairs);
    ksort($expected);

    expect($sqlPairs)->toBe($expected);
});

it('matches the generated alias CASE pair-for-pair', function () {
    $sql = file_get_contents(base_path('supabase/migrations/20260727110000_connections_surface_key.sql'));

    // The alias half lives after the GENERATED ALWAYS AS marker.
    $generated = substr($sql, strpos($sql, 'GENERATED ALWAYS AS'));
    preg_match_all("~WHEN '([a-z0-9_]+\\.[a-z0-9_]+)' THEN '([a-z0-9-]+)'~", $generated, $m, PREG_SET_ORDER);
    $sqlSpecials = [];
    foreach ($m as [, $surface, $legacy]) {
        $sqlSpecials[$surface] = $legacy;
    }

    expect($sqlSpecials)->toBe(LegacyPlatformMap::specialToLegacyMap());
});

it('matches the SQLite test-schema generated CASE pair-for-pair', function () {
    $pest = file_get_contents(base_path('tests/Pest.php'));
    $generated = substr($pest, strpos($pest, 'platform TEXT GENERATED ALWAYS AS'));
    $generated = substr($generated, 0, strpos($generated, 'END) STORED'));
    preg_match_all("~WHEN \\\\'([a-z0-9_]+\\.[a-z0-9_]+)\\\\' THEN \\\\'([a-z0-9-]+)\\\\'~", $generated, $m, PREG_SET_ORDER);
    $pairs = [];
    foreach ($m as [, $surface, $legacy]) {
        $pairs[$surface] = $legacy;
    }

    expect($pairs)->toBe(LegacyPlatformMap::specialToLegacyMap());
});

it('maps only onto surfaces that exist in the compiled artefact, with agreeing routing classes', function () {
    $surfaces = CompiledCatalog::surfaces();

    foreach (LegacyPlatformMap::toSurfaceMap() as $legacy => $surfaceKey) {
        expect(array_key_exists($surfaceKey, $surfaces))
            ->toBeTrue("legacy '{$legacy}' maps to '{$surfaceKey}' which is not in the compiled catalog");
        expect($surfaces[$surfaceKey]['routing_class'])->toBe(
            LegacyPlatformMap::routingClassFor($surfaceKey),
            "routing class disagreement for {$surfaceKey}",
        );
    }
});
