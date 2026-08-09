<?php

/**
 * Roster containment guard for every service that WRITES a font slug.
 *
 * The sitepage font roster narrowed to four on 2026-08-06. A writer that can
 * still emit a retired slug would assign users a face the design system no
 * longer ships, so both writers are asserted against the roster here rather
 * than trusted to stay in sync by review.
 */

use App\Services\Design\FontKeywordClassifier;
use App\Services\Design\SectorStylePresets;

const SURVIVING_FONTS = ['nb-architekt', 'helvetica-neue', 'monument-grotesk', 'forma-djr'];

it('classifies website evidence only onto surviving roster fonts', function () {
    $keywords = (new ReflectionClass(FontKeywordClassifier::class))->getConstant('KEYWORDS');

    expect($keywords)->toBeArray()->not->toBe([]);

    $offenders = [];
    foreach ($keywords as $keyword => $slug) {
        if (! in_array($slug, SURVIVING_FONTS, true)) {
            $offenders[] = "{$keyword} => {$slug}";
        }
    }

    expect($offenders)->toBe([], 'keywords classifying to a font outside the roster');
});

it('seeds sector presets only with surviving roster fonts', function () {
    $overlays = array_map(SectorStylePresets::forBucket(...), SectorStylePresets::buckets());
    foreach (SectorStylePresets::refinedSlugs() as $slug) {
        $overlays[] = SectorStylePresets::forSlug($slug);
    }

    $offenders = [];
    foreach ($overlays as $overlay) {
        $font = $overlay['typography_font_family'] ?? null;
        if ($font !== null && ! in_array($font, SURVIVING_FONTS, true)) {
            $offenders[] = $font;
        }
    }

    expect(array_values(array_unique($offenders)))->toBe([], 'presets seeding a font outside the roster');
});

it('seeds no design_kits column that the simplification removed', function () {
    $removed = [
        'theme_mode',
        'theme_contrast',
        'typography_tracking',
        'typography_uppercase',
        'motion_pace',
        'border_style',
        'typography_weight',
    ];

    $overlays = array_map(SectorStylePresets::forBucket(...), SectorStylePresets::buckets());
    foreach (SectorStylePresets::refinedSlugs() as $slug) {
        $overlays[] = SectorStylePresets::forSlug($slug);
    }

    $offenders = [];
    foreach ($overlays as $overlay) {
        foreach (array_keys($overlay) as $column) {
            if (in_array($column, $removed, true)) {
                $offenders[] = $column;
            }
        }
    }

    expect(array_values(array_unique($offenders)))->toBe([], 'presets writing a removed column');
});
