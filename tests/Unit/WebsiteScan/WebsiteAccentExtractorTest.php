<?php

use App\Services\WebsiteScan\WebsiteAccentExtractor;

/** A tiny solid-colour PNG, generated via GD so the test is self-documenting about the colour it expects back. */
function solidColorPng(int $r, int $g, int $b): string
{
    $image = imagecreatetruecolor(8, 8);
    $color = imagecolorallocate($image, $r, $g, $b);
    imagefill($image, 0, 0, $color);

    ob_start();
    imagepng($image);

    return ob_get_clean();
}

it('extracts a qualifying theme-color meta tag', function () {
    $html = '<meta name="theme-color" content="#ff5500">';
    expect(app(WebsiteAccentExtractor::class)->extract($html, null))->toBe('#ff5500');
});

it('rejects a theme-color that is too close to white', function () {
    $html = '<meta name="theme-color" content="#fefefe">';
    expect(app(WebsiteAccentExtractor::class)->extract($html, null))->toBeNull();
});

it('rejects a theme-color that is too close to black', function () {
    $html = '<meta name="theme-color" content="#0a0a0a">';
    expect(app(WebsiteAccentExtractor::class)->extract($html, null))->toBeNull();
});

it('rejects a low-saturation (grey) theme-color', function () {
    $html = '<meta name="theme-color" content="#808080">';
    expect(app(WebsiteAccentExtractor::class)->extract($html, null))->toBeNull();
});

it('falls back to favicon dominant-colour when no theme-color is present', function () {
    $orangeSquare = solidColorPng(255, 85, 0); // saturated orange — qualifies
    $result = app(WebsiteAccentExtractor::class)->extract('<html></html>', $orangeSquare);

    expect($result)->not->toBeNull();
    // Bucketed to the nearest 0x10 per channel by design (dominant-colour
    // clustering), so assert closeness rather than an exact round-trip.
    [$r, $g, $b] = sscanf($result, '#%02x%02x%02x');
    expect(abs($r - 255))->toBeLessThan(20);
    expect(abs($g - 85))->toBeLessThan(20);
    expect($b)->toBeLessThan(20);
});

it('returns null when neither source yields a qualifying colour', function () {
    expect(app(WebsiteAccentExtractor::class)->extract('<html></html>', null))->toBeNull();
});

it('returns null when the favicon bytes are not a valid image', function () {
    expect(app(WebsiteAccentExtractor::class)->extract('<html></html>', 'not-an-image'))->toBeNull();
});

it('prefers the theme-color when it agrees closely with the favicon colour', function () {
    $html = '<meta name="theme-color" content="#ff5500">';
    $matchingFavicon = solidColorPng(255, 90, 5); // close, within the 60-distance agreement threshold

    $result = app(WebsiteAccentExtractor::class)->extract($html, $matchingFavicon);

    expect($result)->toBe('#ff5500');
});

it('prefers the favicon colour when it strongly disagrees with the theme-color', function () {
    $html = '<meta name="theme-color" content="#ff5500">'; // orange
    $disagreeingFavicon = solidColorPng(0, 100, 200); // blue — far outside the agreement threshold

    $result = app(WebsiteAccentExtractor::class)->extract($html, $disagreeingFavicon);

    expect($result)->not->toBe('#ff5500');
    expect($result)->not->toBeNull();
});

// Regression test for the 2026-07-23 live DivisionByZeroError incident (signup
// testing repairs item 2): a genuinely pure-black pixel — every channel
// exactly 0, unlike #0a0a0a above — makes max($r,$g,$b)/255 evaluate to the
// INTEGER 0 (0/255 divides evenly), which the old `$max === 0.0` strict
// guard failed to catch (0 === 0.0 is false in PHP), falling through to a
// division by zero. Neither existing "close to black" case above (#0a0a0a,
// a non-zero channel) can reproduce this — only true (0,0,0) hits the
// int/float guard gap. Both entry points that reach qualifies() are covered:
// the theme-color meta path and the favicon dominant-colour path.
it('does not crash on a pure-black theme-color (regression: int/float saturation guard)', function () {
    $html = '<meta name="theme-color" content="#000000">';

    expect(fn () => app(WebsiteAccentExtractor::class)->extract($html, null))->not->toThrow(\DivisionByZeroError::class);
});

it('does not crash on a pure-black favicon (regression: int/float saturation guard)', function () {
    $blackSquare = solidColorPng(0, 0, 0);

    expect(fn () => app(WebsiteAccentExtractor::class)->extract('<html></html>', $blackSquare))->not->toThrow(\DivisionByZeroError::class);
    expect(app(WebsiteAccentExtractor::class)->extract('<html></html>', $blackSquare))->toBeNull();
});
