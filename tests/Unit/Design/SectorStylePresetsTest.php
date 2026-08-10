<?php

/**
 * Pure unit tests for SectorStylePresets — the two-tier tunable table mapping
 * a SectorTaxonomy bucket (base) and slug (refinement) to sparse design_kits
 * overlays. No DB, no container.
 */

use App\Services\Design\SectorStylePresets;
use App\Services\Profile\SectorTaxonomy;

it('returns a non-empty overlay for every declared bucket', function () {
    foreach (SectorStylePresets::buckets() as $bucket) {
        expect(SectorStylePresets::forBucket($bucket))->not->toBe([]);
    }
});

it('returns [] for an unknown bucket and an unrefined slug', function () {
    expect(SectorStylePresets::forBucket('astral_projection'))->toBe([])
        ->and(SectorStylePresets::forSlug('restaurant'))->toBe([])
        ->and(SectorStylePresets::forSlug('not-a-slug'))->toBe([]);
});

it('only ever sets snake_case design_kits column keys with string or bool values, in both tiers', function () {
    $overlays = array_map(SectorStylePresets::forBucket(...), SectorStylePresets::buckets());
    foreach (SectorStylePresets::refinedSlugs() as $slug) {
        $overlays[] = SectorStylePresets::forSlug($slug);
    }
    foreach ($overlays as $overlay) {
        foreach ($overlay as $column => $value) {
            expect($column)->toMatch('/^[a-z][a-z0-9_]+$/')
                ->and(is_string($value) || is_bool($value))->toBeTrue("non string/bool value for {$column}");
        }
    }
});

// ('pairs text_desktop_body with every text_body bump' was removed 2026-08-09:
// both columns left the schema with the preset-only migration, so the test
// could only ever pass vacuously. What replaced it is FontRosterTest's
// 'collapsed the sector presets to accent and font' — an allowlist assertion
// that fails if either column comes back.)

it('every refined slug is a real taxonomy slug with a real bucket', function () {
    foreach (SectorStylePresets::refinedSlugs() as $slug) {
        expect(SectorTaxonomy::isValid($slug))->toBeTrue("unknown slug: {$slug}")
            ->and(SectorTaxonomy::bucketFor($slug))->not->toBeNull();
    }
});
