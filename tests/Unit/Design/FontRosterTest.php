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

it('nb-architekt is genuinely REACHABLE: a sector look and a classifier register both emit it (plan 02 step 4)', function () {
    // Reversed 2026-08-27 (the taste map routes it): tattoo-artist and
    // it-services wear the technical grotesk, and scanned-website evidence
    // reaches it through the technical/mono keyword register. If both
    // paths dry up again, that is a deliberate decision to make loudly —
    // not silent drift back to "deliberately unreachable".
    $presetFonts = [];
    foreach (SectorStylePresets::refinedSlugs() as $slug) {
        $presetFonts[] = SectorStylePresets::forSlug($slug)['typography_font_family'] ?? null;
    }
    expect(in_array('nb-architekt', $presetFonts, true))->toBeTrue('no sector look emits nb-architekt');

    $keywords = (new ReflectionClass(FontKeywordClassifier::class))->getConstant('KEYWORDS');
    expect(in_array('nb-architekt', $keywords, true))->toBeTrue('no classifier keyword routes to nb-architekt');
});

it('classify() routes by the LONGEST keyword match — Roboto Mono is technical, Roboto is UI-modern', function () {
    // The critic's find: first-match-wins let 'roboto' swallow "Roboto
    // Mono", so the technical register could never fire for it. This is a
    // REAL routing test (the reachability test above only reflects on the
    // constant).
    $c = new FontKeywordClassifier;

    expect($c->classify('<style>body{font-family:"Roboto Mono", monospace}</style>'))
        ->toBe('nb-architekt')
        ->and($c->classify('<style>body{font-family:"Roboto", sans-serif}</style>'))
        ->toBe('helvetica-neue');
});

it('never authors small text WITH uppercase (taste map §1.4: quiet shouting is incoherent)', function () {
    $overlays = [];
    foreach (SectorStylePresets::buckets() as $bucket) {
        $overlays[$bucket] = SectorStylePresets::forBucket($bucket);
    }
    foreach (SectorStylePresets::refinedSlugs() as $slug) {
        // The rendered look is bucket + refinement merged; the invariant
        // holds on the RESULT, not each sparse layer.
        $bucket = \App\Services\Profile\SectorTaxonomy::bucketFor($slug);
        $overlays['slug:'.$slug] = array_merge(
            $bucket !== null ? SectorStylePresets::forBucket($bucket) : [],
            SectorStylePresets::forSlug($slug),
        );
    }
    foreach ($overlays as $name => $look) {
        $small = ($look['text_size'] ?? null) === 'small';
        $caps = ($look['typography_uppercase'] ?? true) === true;
        expect($small && $caps)->toBeFalse("look {$name} authors small text in caps");
    }
});

it('an nb-architekt look always authors uppercase true (composition rule: it is an all-caps face)', function () {
    $overlays = array_map(SectorStylePresets::forBucket(...), SectorStylePresets::buckets());
    foreach (SectorStylePresets::refinedSlugs() as $slug) {
        $overlays[] = SectorStylePresets::forSlug($slug);
    }
    foreach ($overlays as $overlay) {
        if (($overlay['typography_font_family'] ?? null) === 'nb-architekt') {
            expect($overlay['typography_uppercase'] ?? null)->toBeTrue();
        }
    }
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

it('presets speak the FULL-LOOK vocabulary and nothing outside it (plan 02 step 4)', function () {
    // The 2026-08-09 "collapse to accent+font" pin is deliberately
    // REVERSED here (owner decision B, 2026-08-27): every bucket is a
    // complete authored look now. The pin's job is unchanged — presets may
    // only write these six live columns; anything else is a write to a
    // column that does not exist or an axis nobody resolved.
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

    expect($columns)->toBe([
        'color_accent', 'corners', 'spacing', 'text_size',
        'typography_font_family', 'typography_uppercase',
    ]);
});

it('carries no empty refinement — a slug with nothing left to say is removed', function () {
    // food-truck, personal-chef and personal-trainer each lost every key they
    // had on 2026-08-09 and were deleted rather than left as `[] `. An empty
    // refinement reads as "this slug is tuned" while doing nothing.
    foreach (SectorStylePresets::refinedSlugs() as $slug) {
        expect(SectorStylePresets::forSlug($slug))->not->toBe([], "empty refinement for {$slug}");
    }
});
