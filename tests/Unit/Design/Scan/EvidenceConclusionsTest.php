<?php

/**
 * Unit tests for EvidenceConclusions::conclude() — the brand-scan confidence
 * engine. Pure array-in / array-out: collector evidence + pixel summary in,
 * per-signal tier+confidence (or an ABSENT key when below the floor) out. No
 * DB / HTTP / GD / Mockery — every judgement is exercised through the single
 * public seam, which is exactly what keeps this policy layer browserless.
 *
 * Observability contract (used throughout):
 *  - a SURVIVING signal exposes its exact confidence at
 *    $r['signals'][$name]['confidence']; a below-min_confidence signal is
 *    OMITTED, so its absence (`->not->toHaveKey($name)`) IS the assertion.
 *  - accent is {hex, confidence, evidence} or null; notes live in $r['notes'].
 *
 * Uses TestCase because the class reads config('partna.brand_scan.confidence.*')
 * via its static threshold helpers — no DB, just the container for config().
 * Thresholds are pinned in beforeEach() so every derivation below is
 * deterministic regardless of env.
 */

use App\Services\Design\Scan\EvidenceConclusions;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    config()->set('partna.brand_scan.confidence', [
        'min_confidence' => 0.5,
        'agree_dist' => 40.0,
        'cluster_dist' => 30.0,
    ]);
});

// ── helpers ────────────────────────────────────────────────────────────────

/**
 * Sane light-page evidence with every key conclude() reads. Top-level keys
 * REPLACE on override (array_merge semantics), matching wbsEvidence().
 */
function ecEvidence(array $overrides = []): array
{
    return array_merge([
        'page' => [
            'bodyBg' => 'rgb(255, 255, 255)', 'bodyBgImage' => false, 'deepBg' => null, 'mainBg' => null,
            'bodyColor' => 'rgb(20, 20, 20)', 'bodyFont' => 'Poppins, sans-serif',
            'bodyFontSize' => 17, 'bodyFontWeight' => '300',
        ],
        'copy' => array_fill(0, 3, ['size' => 17, 'weight' => '300', 'family' => 'Poppins, sans-serif', 'color' => 'rgb(20,20,20)']),
        'headings' => [],
        'buttons' => array_fill(0, 3, ['bg' => 'rgba(0, 0, 0, 0)', 'color' => 'rgb(20,20,20)', 'radius' => 14, 'height' => 44, 'transition' => 150, 'cls' => 'btn']),
        'sections' => [],
        'links' => [],
        'rootVars' => [],
        'meta' => ['themeColor' => null, 'manifest' => null, 'icons' => [], 'ogImage' => null, 'twitterImage' => null],
        'logos' => [],
    ], $overrides);
}

/** Thin wrapper: conclude() with a null-pixel summary by default. */
function ecConclude(array $evidence, array $pixels = ['bg' => null, 'accent' => null]): array
{
    return (new EvidenceConclusions)->conclude($evidence, $pixels);
}

/** Build a copy list from bare weights (weight-signal fixtures). */
function ecCopyWeights(array $weights): array
{
    return array_map(fn ($w) => ['weight' => $w, 'family' => 'Poppins', 'size' => 17], $weights);
}

/** Build a copy list from bare sizes (text-signal fixtures). */
function ecCopySizes(array $sizes): array
{
    return array_map(fn ($s) => ['size' => $s, 'weight' => '400', 'family' => 'Poppins'], $sizes);
}

// ── background ─────────────────────────────────────────────────────────────

it('classifies bg tiers by luminance/warmth from a computed colour', function (string $color, string $tier) {
    // computed-only, no bg-image → confidence 0.6 → survives (>= 0.5).
    $r = ecConclude(ecEvidence(['page' => ['bodyBg' => $color, 'bodyBgImage' => false]]));

    expect($r['signals']['bg']['tier'])->toBe($tier)
        ->and($r['signals']['bg']['confidence'])->toBe(0.6);
})->with([
    'light at the lum boundary (rgb 82 = 0.3216 >= 0.32)' => ['rgb(82, 82, 82)', 'light'],
    'dark just under the lum boundary (rgb 81 = 0.3176 < 0.32)' => ['rgb(81, 81, 81)', 'dark'],
    'warm_light at r-b = 4' => ['rgb(104, 100, 100)', 'warm_light'],
    'plain light at r-b = 3 (below the warm cut)' => ['rgb(103, 100, 100)', 'light'],
    'cool_light at b-r = 4' => ['rgb(100, 100, 104)', 'cool_light'],
    'plain light at b-r = 3 (below the cool cut)' => ['rgb(100, 100, 103)', 'light'],
]);

it('scores bg 0.95 when computed and pixel agree within agree_dist', function () {
    $r = ecConclude(ecEvidence(['page' => ['bodyBg' => 'rgb(255,255,255)']]), ['bg' => 'rgb(255,255,255)', 'accent' => null]);

    expect($r['signals']['bg']['confidence'])->toBe(0.95)
        ->and($r['signals']['bg']['tier'])->toBe('light');
});

it('drops bg (0.25) when computed and pixel disagree beyond agree_dist', function () {
    // white computed vs near-black pixel → dist far > 40 → 0.25 → below floor.
    $r = ecConclude(ecEvidence(['page' => ['bodyBg' => 'rgb(255,255,255)']]), ['bg' => 'rgb(12,12,12)', 'accent' => null]);

    expect($r['signals'])->not->toHaveKey('bg');
});

it('scores bg 0.6 for computed-only with no image', function () {
    $r = ecConclude(ecEvidence(['page' => ['bodyBg' => 'rgb(255,255,255)', 'bodyBgImage' => false]]));

    expect($r['signals']['bg']['confidence'])->toBe(0.6);
});

it('drops bg (0.45) for computed-only WITH a background image', function () {
    // A paint-chain background-image means the colour may never be visible →
    // 0.45 < 0.5 → omitted.
    $r = ecConclude(ecEvidence(['page' => ['bodyBg' => 'rgb(255,255,255)', 'bodyBgImage' => true]]));

    expect($r['signals'])->not->toHaveKey('bg');
});

it('scores bg 0.55 for pixels-only (no computed colour)', function () {
    $r = ecConclude(ecEvidence(['page' => ['bodyBg' => null, 'bodyBgImage' => false, 'deepBg' => null, 'mainBg' => null]]), ['bg' => 'rgb(21,21,21)', 'accent' => null]);

    expect($r['signals']['bg']['confidence'])->toBe(0.55)
        ->and($r['signals']['bg']['tier'])->toBe('dark');
});

it('emits no bg when both computed and pixel are absent', function () {
    $r = ecConclude(ecEvidence(['page' => ['bodyBg' => null, 'deepBg' => null, 'mainBg' => null]]), ['bg' => null, 'accent' => null]);

    expect($r['signals'])->not->toHaveKey('bg');
});

it('skips a computed bg colour whose alpha is below 0.5', function () {
    // alpha 0.4 < 0.5 → not eligible as computed; no fallback keys, no pixel → absent.
    $r = ecConclude(ecEvidence(['page' => ['bodyBg' => 'rgba(255,255,255,0.4)', 'deepBg' => null, 'mainBg' => null]]), ['bg' => null, 'accent' => null]);

    expect($r['signals'])->not->toHaveKey('bg');
});

it('accepts a computed bg colour whose alpha is exactly 0.5', function () {
    // 50% == the a >= 0.5 cut → eligible; computed-only → 0.6.
    $r = ecConclude(ecEvidence(['page' => ['bodyBg' => 'rgba(255,255,255,50%)', 'bodyBgImage' => false]]));

    expect($r['signals']['bg']['confidence'])->toBe(0.6)
        ->and($r['signals']['bg']['tier'])->toBe('light');
});

it('falls through to deepBg when bodyBg alpha is too low', function () {
    // bodyBg alpha 0.4 skipped → deepBg (opaque dark) is the computed colour.
    $r = ecConclude(ecEvidence(['page' => ['bodyBg' => 'rgba(255,255,255,0.4)', 'deepBg' => 'rgb(10,10,10)', 'mainBg' => null, 'bodyBgImage' => false]]));

    expect($r['signals']['bg']['tier'])->toBe('dark')
        ->and($r['signals']['bg']['confidence'])->toBe(0.6);
});

// ── typography (font) ──────────────────────────────────────────────────────

it('maps a recognized body font family to its catalog slug', function (string $family, string $slug) {
    // copy empty → known < 2 → confidence 0.85 (consensus not required).
    $r = ecConclude(ecEvidence(['page' => ['bodyFont' => $family], 'copy' => []]));

    expect($r['signals']['font']['tier'])->toBe($slug)
        ->and($r['signals']['font']['confidence'])->toBe(0.85);
})->with([
    'Poppins → geist' => ['Poppins, sans-serif', 'geist'],
    'Arial → helvetica-neue' => ['Arial', 'helvetica-neue'],
    'Georgia (serif) → general-sans' => ['Georgia, serif', 'general-sans'],
    'Neue Haas Grotesk → helvetica-neue' => ['Neue Haas Grotesk', 'helvetica-neue'],
]);

it('scores font 0.85 when the visible copy agrees with the body font', function () {
    $r = ecConclude(ecEvidence([
        'page' => ['bodyFont' => 'Poppins'],
        'copy' => array_fill(0, 3, ['family' => 'Poppins', 'weight' => '400', 'size' => 16]),
    ]));

    expect($r['signals']['font']['tier'])->toBe('geist')
        ->and($r['signals']['font']['confidence'])->toBe(0.85);
});

it('scores font 0.6 when known copy disagrees with the body font (< 0.7 share, >= 2 known)', function () {
    // body Poppins(geist); copy = Georgia, Georgia, Poppins → general-sans, general-sans, geist → known 3, agree 1 → 1/3 < 0.7.
    // Georgia (serif → general-sans) diverges from the body's geist.
    $r = ecConclude(ecEvidence([
        'page' => ['bodyFont' => 'Poppins'],
        'copy' => [
            ['family' => 'Georgia'], ['family' => 'Georgia'], ['family' => 'Poppins'],
        ],
    ]));

    expect($r['signals']['font']['tier'])->toBe('geist')
        ->and($r['signals']['font']['confidence'])->toBe(0.6);
});

it('scores font 0.85 when fewer than 2 copy samples are recognizable', function () {
    // body Poppins; the single copy family is unknown → known 0 < 2 → 0.85.
    $r = ecConclude(ecEvidence([
        'page' => ['bodyFont' => 'Poppins'],
        'copy' => [['family' => 'ObscureCustomFace']],
    ]));

    expect($r['signals']['font']['confidence'])->toBe(0.85);
});

it('falls back to a recognized heading (0.55) when the body font is unknown', function () {
    $r = ecConclude(ecEvidence([
        'page' => ['bodyFont' => 'ObscureCustomFace'],
        'copy' => [],
        'headings' => [['tag' => 'h1', 'family' => 'Poppins']],
    ]));

    expect($r['signals']['font']['tier'])->toBe('geist')
        ->and($r['signals']['font']['confidence'])->toBe(0.55)
        ->and($r['notes'])->toContain('unknown font family: obscurecustomface');
});

it('emits no font and records a note when nothing is recognizable', function () {
    $r = ecConclude(ecEvidence([
        'page' => ['bodyFont' => 'ObscureCustomFace'],
        'copy' => [],
        'headings' => [['tag' => 'h1', 'family' => 'AnotherMysteryFont']],
    ]));

    expect($r['signals'])->not->toHaveKey('font')
        ->and($r['notes'])->toContain('unknown font family: obscurecustomface');
});

// ── typography (weight) ────────────────────────────────────────────────────

it('classifies weight tiers by the sample mode', function (array $weights, string $tier) {
    $r = ecConclude(ecEvidence([
        'copy' => ecCopyWeights($weights),
        'page' => ['bodyFontWeight' => null], // no body sample → weights are exactly $weights
    ]));

    expect($r['signals']['weight']['tier'])->toBe($tier);
})->with([
    'light at 300' => [[300, 300], 'light'],
    'regular just past 300' => [[301, 301], 'regular'],
    'regular just under 500' => [[499, 499], 'regular'],
    'medium at 500' => [[500, 500], 'medium'],
    'medium at 700' => [[700, 700], 'medium'],
]);

it('reads keyword weights (normal → 400, bold → 700)', function () {
    $rNormal = ecConclude(ecEvidence(['copy' => ecCopyWeights(['normal', 'normal']), 'page' => ['bodyFontWeight' => null]]));
    $rBold = ecConclude(ecEvidence(['copy' => ecCopyWeights(['bold', 'bold']), 'page' => ['bodyFontWeight' => null]]));

    expect($rNormal['signals']['weight']['tier'])->toBe('regular')  // 400 < 500
        ->and($rBold['signals']['weight']['tier'])->toBe('medium'); // 700 >= 500
});

it('abstains from weight on mixed weights (mode share < 0.6)', function () {
    // 300/400/700 each once → mode share 1/3 < 0.6 → null.
    $r = ecConclude(ecEvidence(['copy' => ecCopyWeights([300, 400, 700]), 'page' => ['bodyFontWeight' => null]]));

    expect($r['signals'])->not->toHaveKey('weight');
});

it('abstains from weight with fewer than 2 samples', function () {
    $r = ecConclude(ecEvidence(['copy' => ecCopyWeights([400]), 'page' => ['bodyFontWeight' => null]]));

    expect($r['signals'])->not->toHaveKey('weight');
});

it('scales weight confidence with sample count (>= 4 → 0.8, else 0.65)', function () {
    $rMany = ecConclude(ecEvidence(['copy' => ecCopyWeights([300, 300, 300, 300]), 'page' => ['bodyFontWeight' => null]]));
    $rFew = ecConclude(ecEvidence(['copy' => ecCopyWeights([300, 300]), 'page' => ['bodyFontWeight' => null]]));

    expect($rMany['signals']['weight']['confidence'])->toBe(0.8)
        ->and($rFew['signals']['weight']['confidence'])->toBe(0.65);
});

// ── typography (text size) ─────────────────────────────────────────────────

it('classifies text-size tiers by the median', function (array $sizes, string $tier) {
    $r = ecConclude(ecEvidence([
        'copy' => ecCopySizes($sizes),
        'page' => ['bodyFontSize' => null], // no body sample → sizes are exactly $sizes
    ]));

    expect($r['signals']['text']['tier'])->toBe($tier);
})->with([
    'small at the 14.5 boundary' => [[14, 15], 'small'],
    'regular just above small' => [[15, 16], 'regular'],
    'regular below the large cut' => [[16, 17], 'regular'],
    'large at the 17.5 boundary (< is exclusive)' => [[17, 18], 'large'],
]);

it('scales text confidence with sample count (>=5 → 0.8, >=3 → 0.6, else 0.55)', function () {
    $r5 = ecConclude(ecEvidence(['copy' => ecCopySizes([16, 16, 16, 16, 16]), 'page' => ['bodyFontSize' => null]]));
    $r3 = ecConclude(ecEvidence(['copy' => ecCopySizes([16, 16, 16]), 'page' => ['bodyFontSize' => null]]));
    $r1 = ecConclude(ecEvidence(['copy' => ecCopySizes([16]), 'page' => ['bodyFontSize' => null]]));

    expect($r5['signals']['text']['confidence'])->toBe(0.8)
        ->and($r3['signals']['text']['confidence'])->toBe(0.6)
        ->and($r1['signals']['text']['confidence'])->toBe(0.55);
});

it('filters text sizes outside 8..28 before taking the median', function () {
    // 30 is dropped → median of [16] is 16 (regular). Unfiltered median would be
    // 30 (large), so a 'regular' tier proves the filter ran.
    $r = ecConclude(ecEvidence(['copy' => ecCopySizes([30, 30, 16]), 'page' => ['bodyFontSize' => null]]));

    expect($r['signals']['text']['tier'])->toBe('regular')
        ->and($r['signals']['text']['confidence'])->toBe(0.55); // only 1 survives
});

// ── shape (radius) ─────────────────────────────────────────────────────────

it('classifies radius tiers by the median (no pill idiom)', function (int $radius, string $tier) {
    $buttons = array_fill(0, 3, ['radius' => $radius]); // no height → no clamp, no pill
    $r = ecConclude(ecEvidence(['buttons' => $buttons]));

    expect($r['signals']['radius']['tier'])->toBe($tier)
        ->and($r['signals']['radius']['confidence'])->toBe(0.8); // 3 samples
})->with([
    'sharp below 4' => [2, 'sharp'],
    'moderate below 12' => [8, 'moderate'],
    'rounded below 24' => [16, 'rounded'],
    'very_rounded at 24+' => [30, 'very_rounded'],
    // Exact cut boundaries — each pins the `<` on its NOT-less-than side, so a
    // `<` → `<=` mutation at any cut would flip the tier and fail here.
    'moderate at the 4 boundary (guards < 4)' => [4, 'moderate'],
    'rounded at the 12 boundary (guards < 12)' => [12, 'rounded'],
    'very_rounded at the 24 boundary (guards < 24)' => [24, 'very_rounded'],
]);

it('treats a pill-dominant button language as very_rounded regardless of median', function () {
    // Two 9999px pills of height 44 → each clamps to radius 22 (median 22 =
    // 'rounded'), but pill-dominance (2 >= max(2, ceil(2/2))) forces very_rounded.
    $buttons = [
        ['radius' => 9999, 'height' => 44],
        ['radius' => 9999, 'height' => 44],
    ];
    $r = ecConclude(ecEvidence(['buttons' => $buttons]));

    expect($r['signals']['radius']['tier'])->toBe('very_rounded');
});

it('clamps effective radius to half the button height', function () {
    // Single 9999px radius, height 44 → effective 22 → median 22 → 'rounded'.
    // Not pill-dominant (1 pill < required 2), so the clamped median decides.
    // Unclamped this would read 9999 → very_rounded, so 'rounded' proves the clamp.
    $r = ecConclude(ecEvidence(['buttons' => [['radius' => 9999, 'height' => 44]]]));

    expect($r['signals']['radius']['tier'])->toBe('rounded')
        ->and($r['signals']['radius']['confidence'])->toBe(0.55); // 1 sample
});

it('skips non-numeric radii', function () {
    // 'abc' skipped → radii [8,8] → moderate. Proves the guard (else it would error/skew).
    $buttons = [
        ['radius' => 'abc', 'height' => 44],
        ['radius' => 8, 'height' => 44],
        ['radius' => 8, 'height' => 44],
    ];
    $r = ecConclude(ecEvidence(['buttons' => $buttons]));

    expect($r['signals']['radius']['tier'])->toBe('moderate')
        ->and($r['signals']['radius']['confidence'])->toBe(0.55); // 2 numeric samples
});

it('emits no radius when no button has a numeric radius', function () {
    $r = ecConclude(ecEvidence(['buttons' => [['radius' => null], ['radius' => 'x']]]));

    expect($r['signals'])->not->toHaveKey('radius');
});

// ── rhythm (space) ─────────────────────────────────────────────────────────

it('classifies spacing tiers by the padding/gap median', function (int $pad, string $tier) {
    // One section contributes 4 in-range values (padT/padB/padL/padR) → meets the >=4 floor.
    $r = ecConclude(ecEvidence(['sections' => [[
        'padT' => $pad, 'padB' => $pad, 'padL' => $pad, 'padR' => $pad,
    ]]]));

    expect($r['signals']['space']['tier'])->toBe($tier)
        ->and($r['signals']['space']['confidence'])->toBe(0.6);
})->with([
    'compact just under 24' => [23, 'compact'],
    'regular at the 24 boundary' => [24, 'regular'],
    'regular at the 56 boundary' => [56, 'regular'],
    'generous just over 56' => [57, 'generous'],
]);

it('abstains from spacing under 4 samples', function () {
    // 3 values only → median is anecdote, not rhythm → null.
    $r = ecConclude(ecEvidence(['sections' => [['padT' => 16, 'padB' => 16, 'padL' => 16]]]));

    expect($r['signals'])->not->toHaveKey('space');
});

it('filters spacing values outside 4..160 before counting the sample floor', function () {
    // padT 2 and padB 200 dropped → only 3 survive → under the floor → null.
    // (If they were counted, 5 samples would clear the floor and produce a signal.)
    $r = ecConclude(ecEvidence(['sections' => [[
        'padT' => 2, 'padB' => 200, 'padL' => 16, 'padR' => 16, 'gap' => 16,
    ]]]));

    expect($r['signals'])->not->toHaveKey('space');
});

// ── motion ─────────────────────────────────────────────────────────────────

it('classifies motion tiers by the transition median', function (int $ms, string $tier) {
    $buttons = array_fill(0, 3, ['transition' => $ms]);
    $r = ecConclude(ecEvidence(['buttons' => $buttons]));

    expect($r['signals']['motion']['tier'])->toBe($tier)
        ->and($r['signals']['motion']['confidence'])->toBe(0.7);
})->with([
    'fast just under 200' => [199, 'fast'],
    'normal at the 200 boundary' => [200, 'normal'],
    'normal at the 400 boundary' => [400, 'normal'],
    'slow just over 400' => [401, 'slow'],
]);

it('abstains from motion (absent, not "fast") under 3 samples', function () {
    $r = ecConclude(ecEvidence(['buttons' => [['transition' => 150], ['transition' => 150]], 'sections' => []]));

    expect($r['signals'])->not->toHaveKey('motion');
});

it('combines button and section transitions for motion', function () {
    // 2 button + 1 section = 3 durations → clears the floor.
    $r = ecConclude(ecEvidence([
        'buttons' => [['transition' => 150], ['transition' => 150]],
        'sections' => [['transition' => 150]],
    ]));

    expect($r['signals']['motion']['tier'])->toBe('fast');
});

it('filters transitions outside 40..5000 before the sample floor', function () {
    // 30 and 6000 dropped → only 1 survives → under 3 → null. (Unfiltered, 3
    // durations would produce a 'fast' signal, so absence proves the filter.)
    $r = ecConclude(ecEvidence([
        'buttons' => [['transition' => 30], ['transition' => 6000], ['transition' => 150]],
        'sections' => [],
    ]));

    expect($r['signals'])->not->toHaveKey('motion');
});

// ── accent (quorum + clustering) ───────────────────────────────────────────

it('scores accent 0.9 from two distinct intentional sources', function () {
    // brand-var + cta on the same colour → one cluster, 2 distinct sources.
    $r = ecConclude(ecEvidence([
        'rootVars' => ['--color-accent' => 'rgb(200, 50, 50)'],
        'buttons' => [['bg' => 'rgb(200, 50, 50)']],
    ]));

    expect($r['accent']['hex'])->toBe('#c83232')
        ->and($r['accent']['confidence'])->toBe(0.9);
});

it('scores accent 0.7 for one intentional source with >= 2 votes', function () {
    $r = ecConclude(ecEvidence([
        'buttons' => [['bg' => 'rgb(200, 50, 50)'], ['bg' => 'rgb(200, 50, 50)']],
    ]));

    expect($r['accent']['hex'])->toBe('#c83232')
        ->and($r['accent']['confidence'])->toBe(0.7);
});

it('scores accent 0.55 for one intentional source with a single vote', function () {
    $r = ecConclude(ecEvidence([
        'buttons' => [['bg' => 'rgb(200, 50, 50)']],
    ]));

    expect($r['accent']['confidence'])->toBe(0.55);
});

it('drops a theme-color-only accent (0.35 < floor)', function () {
    $r = ecConclude(ecEvidence([
        'meta' => ['themeColor' => 'rgb(200, 50, 50)', 'manifest' => null, 'icons' => [], 'ogImage' => null, 'twitterImage' => null],
    ]));

    expect($r['accent'])->toBeNull();
});

it('corroborates an accent with a matching fold pixel (+0.05, adds "pixels")', function () {
    // single cta 0.55 + pixel within agree_dist → 0.60, and 'pixels' joins the evidence.
    $r = ecConclude(
        ecEvidence(['buttons' => [['bg' => 'rgb(200, 50, 50)']]]),
        ['bg' => null, 'accent' => 'rgb(200, 50, 50)'],
    );

    // 0.55 + 0.05 lands on 0.6000000000000001 in IEEE-754 — assert with a delta.
    expect($r['accent']['confidence'])->toEqualWithDelta(0.6, 1e-9)
        ->and($r['accent']['evidence'][0])->toContain('pixels');
});

it('rejects an accent within 50 of the background colour', function () {
    // Dark bg (30,30,40); cta (30,30,80) qualifies but sits 40 < 50 from bg → mis-read → null.
    $r = ecConclude(ecEvidence([
        'page' => ['bodyBg' => 'rgb(30,30,40)', 'bodyBgImage' => false, 'bodyColor' => 'rgb(230,230,230)', 'bodyFont' => 'Arial', 'bodyFontSize' => 16, 'bodyFontWeight' => '400'],
        'buttons' => [['bg' => 'rgb(30, 30, 80)']],
    ]));

    expect($r['signals']['bg']['tier'])->toBe('dark') // bg survived → bgHex fed to accent
        ->and($r['accent'])->toBeNull();
});

it('rejects an accent within 40 of the body text colour', function () {
    // body (60,60,160); cta (60,60,180) qualifies but sits 20 < 40 from body text → null.
    $r = ecConclude(ecEvidence([
        'page' => ['bodyBg' => 'rgb(255,255,255)', 'bodyBgImage' => false, 'bodyColor' => 'rgb(60,60,160)', 'bodyFont' => 'Arial', 'bodyFontSize' => 16, 'bodyFontWeight' => '400'],
        'buttons' => [['bg' => 'rgb(60, 60, 180)']],
    ]));

    expect($r['accent'])->toBeNull();
});

it('rejects a grayscale accent candidate (saturation < 0.3)', function () {
    $r = ecConclude(ecEvidence(['rootVars' => ['--accent' => 'rgb(120, 120, 120)']]));

    expect($r['accent'])->toBeNull();
});

it('rejects a too-dark accent candidate (luminance <= 0.08)', function () {
    $r = ecConclude(ecEvidence(['rootVars' => ['--accent' => 'rgb(10, 0, 0)']]));

    expect($r['accent'])->toBeNull();
});

it('excludes a root var whose name matches the background/foreground filter', function () {
    // '--accent-background' matches /accent/ but also /background/ → skipped.
    $r = ecConclude(ecEvidence(['rootVars' => ['--accent-background' => 'rgb(200, 50, 50)']]));

    expect($r['accent'])->toBeNull();
});

it('excludes a link colour within 60 of the body text colour', function () {
    // link (40,20,20) qualifies but sits 20 <= 60 from body (20,20,20) → not a candidate.
    $r = ecConclude(ecEvidence([
        'page' => ['bodyBg' => 'rgb(255,255,255)', 'bodyBgImage' => false, 'bodyColor' => 'rgb(20,20,20)', 'bodyFont' => 'Arial', 'bodyFontSize' => 16, 'bodyFontWeight' => '400'],
        'links' => [['color' => 'rgb(40, 20, 20)']],
    ]));

    expect($r['accent'])->toBeNull();
});

it('accepts a link colour well clear of the body text as a single-source accent', function () {
    // Blue link far from body (dist ~206 > 60) → intentional source 'link', 1 vote → 0.55.
    $r = ecConclude(ecEvidence([
        'page' => ['bodyBg' => 'rgb(255,255,255)', 'bodyBgImage' => false, 'bodyColor' => 'rgb(20,20,20)', 'bodyFont' => 'Arial', 'bodyFontSize' => 16, 'bodyFontWeight' => '400'],
        'links' => [['color' => 'rgb(30, 120, 200)']],
    ]));

    expect($r['accent']['hex'])->toBe('#1e78c8')
        ->and($r['accent']['confidence'])->toBe(0.55);
});

// ── parseColor (exercised via inputs, asserted via tier/hex) ───────────────

it('parses assorted colour syntaxes into the right bg tier', function (string $color, string $tier) {
    $r = ecConclude(ecEvidence(['page' => ['bodyBg' => $color, 'bodyBgImage' => false, 'deepBg' => null, 'mainBg' => null]]));

    expect($r['signals']['bg']['tier'])->toBe($tier);
})->with([
    '6-hex white' => ['#ffffff', 'light'],
    '3-hex dark (#111)' => ['#111', 'dark'],
    'rgb slash-alpha "255 255 255 / 1.0"' => ['rgb(255 255 255 / 1.0)', 'light'],
    'bare triplet "18, 18, 18"' => ['18, 18, 18', 'dark'],
]);

it('treats non-colour tokens as null (no bg signal)', function (string $token) {
    $r = ecConclude(ecEvidence(['page' => ['bodyBg' => $token, 'deepBg' => null, 'mainBg' => null]]), ['bg' => null, 'accent' => null]);

    expect($r['signals'])->not->toHaveKey('bg');
})->with([
    'transparent' => ['transparent'],
    'none' => ['none'],
    'empty string' => [''],
    'garbage' => ['not-a-colour'],
]);

// ── thresholds (config-driven flips) ───────────────────────────────────────

it('flips a computed-only bg (0.6) around the min_confidence threshold', function () {
    $evidence = ecEvidence(['page' => ['bodyBg' => 'rgb(255,255,255)', 'bodyBgImage' => false]]);

    config()->set('partna.brand_scan.confidence.min_confidence', 0.60);
    expect(ecConclude($evidence)['signals'])->toHaveKey('bg'); // 0.6 >= 0.60 survives

    config()->set('partna.brand_scan.confidence.min_confidence', 0.61);
    expect(ecConclude($evidence)['signals'])->not->toHaveKey('bg'); // 0.6 < 0.61 drops
});

it('flips bg agreement at exactly agree_dist (40 → 0.95, 41 → 0.25)', function () {
    // computed (0,0,0) vs pixel offset; agree_dist pinned at 40.
    $rAt = ecConclude(ecEvidence(['page' => ['bodyBg' => '#000000']]), ['bg' => '#280000', 'accent' => null]);   // dist 40
    $rOver = ecConclude(ecEvidence(['page' => ['bodyBg' => '#000000']]), ['bg' => '#290000', 'accent' => null]); // dist 41

    expect($rAt['signals']['bg']['confidence'])->toBe(0.95)
        ->and($rOver['signals'])->not->toHaveKey('bg'); // 0.25 dropped
});

it('flips accent clustering at exactly cluster_dist (30 merges → 0.9, 31 splits → 0.55)', function () {
    // brand-var (200,50,50) + cta at increasing distance; cluster_dist pinned at 30.
    $merge = ecConclude(ecEvidence([
        'rootVars' => ['--accent' => 'rgb(200, 50, 50)'],
        'buttons' => [['bg' => 'rgb(200, 50, 80)']], // dist 30 → same cluster, 2 sources
    ]));
    $split = ecConclude(ecEvidence([
        'rootVars' => ['--accent' => 'rgb(200, 50, 50)'],
        'buttons' => [['bg' => 'rgb(200, 50, 81)']], // dist 31 → separate clusters
    ]));

    expect($merge['accent']['confidence'])->toBe(0.9)
        ->and($merge['accent']['hex'])->toBe('#c83232')
        ->and($split['accent']['confidence'])->toBe(0.55); // top cluster is single-source, single-vote
});

// ── smoke: a full light-page evidence set ──────────────────────────────────

it('concludes the expected signal set for the default light-page fixture', function () {
    $r = ecConclude(ecEvidence(), ['bg' => 'rgb(255,255,255)', 'accent' => null]);

    expect($r['signals']['bg']['tier'])->toBe('light')
        ->and($r['signals']['bg']['confidence'])->toBe(0.95)    // computed + pixel agree
        ->and($r['signals']['font']['tier'])->toBe('geist') // Poppins
        ->and($r['signals']['weight']['tier'])->toBe('light')   // all 300
        ->and($r['signals']['text']['tier'])->toBe('regular')   // 17px median
        ->and($r['signals']['radius']['tier'])->toBe('rounded') // radius 14
        ->and($r['signals']['motion']['tier'])->toBe('fast')    // 150ms
        ->and($r['accent'])->toBeNull()                         // transparent CTAs, no vars
        ->and($r['signals'])->not->toHaveKey('space');          // no sections
});
