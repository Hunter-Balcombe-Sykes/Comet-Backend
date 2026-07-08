<?php

/**
 * Unit tests for ImagePaletteExtractor (#76 Part A) — the GD-only dominant-colour
 * + palette reader that feeds IdentityEvidence::mediaPalette() → the
 * ImageryPaletteFactor. Covers the shape contract, the warm/cool + saturation
 * classification on controlled solid-colour fixtures, and the failure-tolerance
 * guarantee (never throws; returns null on bad input).
 */

use App\Services\Media\ImagePaletteExtractor;

// GD images are freed by the garbage collector when they go out of scope; PHP 8+
// makes imagedestroy() a no-op (deprecated in 8.5), so fixtures just fall out of
// scope at the end of each test.

/** A solid-colour GD image of the given RGB. */
function paletteFixture(int $r, int $g, int $b, int $w = 40, int $h = 40): GdImage
{
    $img = imagecreatetruecolor($w, $h);
    $colour = imagecolorallocate($img, $r, $g, $b);
    imagefilledrectangle($img, 0, 0, $w, $h, $colour);

    return $img;
}

it('returns the documented shape for a solid image', function () {
    $palette = (new ImagePaletteExtractor)->fromGd(paletteFixture(255, 150, 100)); // warm orange

    expect($palette)->toBeArray()
        ->and($palette)->toHaveKeys(['dominant', 'colors', 'saturation', 'warm'])
        ->and($palette['dominant'])->toMatch('/^#[0-9a-f]{6}$/')
        ->and($palette['colors'])->toBeArray()->not->toBeEmpty()
        ->and($palette['saturation'])->toBeFloat()
        ->and($palette['warm'])->toBeBool();
});

it('classifies a warm-dominant image as warm with meaningful saturation', function () {
    $palette = (new ImagePaletteExtractor)->fromGd(paletteFixture(230, 120, 60)); // warm orange/brown

    expect($palette['warm'])->toBeTrue()
        ->and($palette['saturation'])->toBeGreaterThan(0.5);
});

it('classifies a cool blue image as not warm', function () {
    $palette = (new ImagePaletteExtractor)->fromGd(paletteFixture(50, 80, 200)); // cool blue

    expect($palette['warm'])->toBeFalse()
        ->and($palette['saturation'])->toBeGreaterThan(0.5);
});

it('reports near-zero saturation for a neutral grey image (muted read)', function () {
    $palette = (new ImagePaletteExtractor)->fromGd(paletteFixture(128, 128, 128));

    // Grey has no colour temperature — saturation ~0, warm false. This is what
    // drives the factor's muted/mono read.
    expect($palette['saturation'])->toBeLessThan(0.1)
        ->and($palette['warm'])->toBeFalse();
});

it('reports a high-saturation read for a vivid image', function () {
    $palette = (new ImagePaletteExtractor)->fromGd(paletteFixture(255, 20, 20)); // vivid red

    // The factor treats saturation >= 0.65 as "let the vibrant imagery speak".
    expect($palette['saturation'])->toBeGreaterThanOrEqual(0.65);
});

it('extracts from a file path (jpeg on disk)', function () {
    $path = tempnam(sys_get_temp_dir(), 'palette_file_');
    imagejpeg(paletteFixture(240, 130, 70), $path, 95);

    $palette = (new ImagePaletteExtractor)->fromPath($path);
    @unlink($path);

    expect($palette)->toBeArray()
        ->and($palette['warm'])->toBeTrue();
});

it('returns null for a non-image file (never throws)', function () {
    $path = tempnam(sys_get_temp_dir(), 'palette_bad_');
    file_put_contents($path, 'not an image');

    $palette = (new ImagePaletteExtractor)->fromPath($path);
    @unlink($path);

    expect($palette)->toBeNull();
});
