<?php

/**
 * Unit tests for ScreenshotSampler::summarize() — the only public entry
 * point. It takes raw PNG bytes and returns array{bg: ?string, accent:
 * ?string} hex colours or null. Fixtures are built in-process with ext-gd
 * (imagecreatetruecolor + imagefilledrectangle/imagesetpixel, encoded via
 * ob_start/imagepng/ob_get_clean) — the same decode path the class itself
 * uses (imagecreatefromstring), so these bytes are exactly what a real
 * screenshot capture would hand it.
 *
 * Uses TestCase because the class reads its three gate thresholds via
 * config('partna.brand_scan.sampler.*') (Bundle 6) — no DB/HTTP involved,
 * just the container for config().
 */

use App\Services\Design\Scan\ScreenshotSampler;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

/**
 * Render a $w x $h true-colour PNG via a drawing callback and return the
 * raw encoded bytes — no temp file, matches what an HTTP screenshot
 * fetch would hand summarize().
 */
function samplerFixture(callable $draw, int $w = 300, int $h = 300): string
{
    $img = imagecreatetruecolor($w, $h);
    $draw($img, $w, $h);
    ob_start();
    imagepng($img);
    $bytes = ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

it('returns the flat colour as bg when edge samples are uniform (modality gate cleared)', function () {
    // Whole canvas one neutral, non-saturated grey — edge-sample top bucket
    // share is 1.0 (>= bg_modality_min 0.55), so bg resolves; grey has
    // sat=0 so it never qualifies for the accent gate either.
    $png = samplerFixture(function ($img, $w, $h) {
        $grey = imagecolorallocate($img, 200, 200, 200);
        imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, $grey);
    });

    expect((new ScreenshotSampler)->summarize($png))->toBe(['bg' => '#c8c8c8', 'accent' => null]);
});

it('abstains (bg null) on a photo-dominated fold with no dominant modality', function () {
    // Deterministic "noise" gradient (not random — reproducible across
    // runs) scatters edge samples across the 4096 possible colour buckets,
    // so no single bucket can reach the 0.55 modality share. The same
    // dispersion also means the saturated-cluster gate never concentrates,
    // so pixels never nominate an accent either — matching the class
    // docblock ("photos... FAILS the modality gate", "pixels never
    // nominate an accent").
    $png = samplerFixture(function ($img, $w, $h) {
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $r = (13 * $y + 7 * $x) % 256;
                $g = (17 * $x + 3 * $y) % 256;
                $b = (29 * $y + 11 * $x) % 256;
                imagesetpixel($img, $x, $y, imagecolorallocate($img, $r, $g, $b));
            }
        }
    });

    expect((new ScreenshotSampler)->summarize($png))->toBe(['bg' => null, 'accent' => null]);
});

it('returns a saturated accent colour when a clear cluster exists above the fold', function () {
    // Neutral background (keeps bg's edge samples uniform, uninvolved in
    // this assertion) plus a 120x120 saturated-orange block placed away
    // from the edge-sample columns. At a step-6 grid the block alone
    // yields ~400 saturated samples (>= accent_min_samples 200) with a
    // ~1.0 cluster share (>= accent_cluster_min_share 0.30).
    $png = samplerFixture(function ($img, $w, $h) {
        $bg = imagecolorallocate($img, 240, 240, 240);
        imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, $bg);
        $accent = imagecolorallocate($img, 255, 120, 10);
        imagefilledrectangle($img, 90, 20, 210, 140, $accent);
    });

    expect((new ScreenshotSampler)->summarize($png))->toBe(['bg' => '#f0f0f0', 'accent' => '#ff780a']);
});

it('returns nulls for empty bytes without crashing', function () {
    expect((new ScreenshotSampler)->summarize(''))->toBe(['bg' => null, 'accent' => null]);
});

it('returns nulls for bytes that are not a decodable image without crashing', function () {
    expect((new ScreenshotSampler)->summarize('not a png at all'))->toBe(['bg' => null, 'accent' => null]);
});

it('returns bg null for an image below the 40x40 sampling floor regardless of colour', function () {
    // modalEdgeColor() bails out before any bucketing when w<40 or h<40 —
    // even a perfectly uniform fill can't produce a bg on a too-small capture.
    $png = samplerFixture(function ($img, $w, $h) {
        $c = imagecolorallocate($img, 10, 200, 30);
        imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, $c);
    }, 20, 20);

    expect((new ScreenshotSampler)->summarize($png))->toBe(['bg' => null, 'accent' => null]);
});
