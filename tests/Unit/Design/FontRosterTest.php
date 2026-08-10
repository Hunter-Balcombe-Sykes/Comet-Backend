<?php

/**
 * Roster containment guard for every service that WRITES a font slug.
 *
 * The sitepage font roster narrowed to four on 2026-08-06. A writer that can
 * still emit a retired slug would assign users a face the design system no
 * longer ships, so both writers are asserted against the roster here rather
 * than trusted to stay in sync by review.
 */

use App\Http\Requests\Concerns\DesignKitValidationRules;
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

it('seeds no design_kits column that is not in the live allowlist', function () {
    // Inverted from a blocklist to an ALLOWLIST on 2026-08-09. The old version
    // named seven removed columns by hand and had to be extended by whoever
    // dropped the next one — which is exactly the drift it existed to catch.
    // Anchoring on DesignKitValidationRules instead cannot go stale: that
    // trait's stated contract is one rule per live site.design_kits column,
    // and tests/Schema/DesignKitRequestDriftTest.php pins it to the real
    // Postgres catalog in both directions.
    //
    // A preset that writes anything outside it is a write to a column that
    // does not exist. Reaching for it via an anonymous class keeps this a pure
    // unit test — no DB, no HTTP, no FormRequest lifecycle.
    $rules = (new class
    {
        use DesignKitValidationRules;

        /** @return array<string, list<string>> */
        public function expose(): array
        {
            return $this->designKitRules();
        }
    })->expose();

    $allowed = [];
    foreach (array_keys($rules) as $key) {
        if (str_starts_with($key, 'design_kit.')) {
            $allowed[] = substr($key, strlen('design_kit.'));
        }
    }

    $overlays = array_map(SectorStylePresets::forBucket(...), SectorStylePresets::buckets());
    foreach (SectorStylePresets::refinedSlugs() as $slug) {
        $overlays[] = SectorStylePresets::forSlug($slug);
    }

    $offenders = [];
    foreach ($overlays as $overlay) {
        foreach (array_keys($overlay) as $column) {
            if (! in_array($column, $allowed, true)) {
                $offenders[] = $column;
            }
        }
    }

    expect(array_values(array_unique($offenders)))->toBe([], 'presets writing a column outside the live allowlist');
});

it('collapsed the sector presets to accent and font, and nothing else', function () {
    // OWNER DECISION 2026-08-09 (go-live brief §9 / plan 6.3): let the gutted
    // sectors collapse rather than invent new differentiation. Of the columns
    // these presets used to set, weight/text/space/radius/line-height went with
    // the preset-only migration and the three effect_* axes were deleted
    // outright; border_thickness survives but as a two-value selection that
    // cannot express the chunky 2px rules three presets wanted.
    //
    // This pins the RESULT of that decision, so a well-meant re-enrichment is
    // a conversation rather than a silent revert.
    $overlays = array_map(SectorStylePresets::forBucket(...), SectorStylePresets::buckets());
    foreach (SectorStylePresets::refinedSlugs() as $slug) {
        $overlays[] = SectorStylePresets::forSlug($slug);
    }

    $columns = [];
    foreach ($overlays as $overlay) {
        $columns = [...$columns, ...array_keys($overlay)];
    }
    $columns = array_values(array_unique($columns));
    sort($columns);

    expect($columns)->toBe(['color_accent', 'typography_font_family']);
});

it('carries no empty refinement — a slug with nothing left to say is removed', function () {
    // food-truck, personal-chef and personal-trainer each lost every key they
    // had on 2026-08-09 and were deleted rather than left as `[] `. An empty
    // refinement reads as "this slug is tuned" while doing nothing.
    foreach (SectorStylePresets::refinedSlugs() as $slug) {
        expect(SectorStylePresets::forSlug($slug))->not->toBe([], "empty refinement for {$slug}");
    }
});
